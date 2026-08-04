<?php
// Archivo: includes/two_factor_helper.php
// Funciones de verificación en dos pasos (TOTP), códigos de recuperación
// y dispositivos confiables. Compatible con PHP 7.4+.

declare(strict_types=1);

function two_factor_config_file(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $ruta = dirname(__DIR__) . '/config/seguridad_2fa.php';
    if (!is_file($ruta)) {
        throw new RuntimeException(
            'Falta config/seguridad_2fa.php. Instala primero el paquete de verificación en dos pasos.'
        );
    }

    $loaded = require $ruta;
    if (!is_array($loaded)) {
        throw new RuntimeException('La configuración de 2FA no es válida.');
    }

    $config = $loaded;
    return $config;
}

function two_factor_table_exists(mysqli $db, string $table): bool
{
    static $cache = [];

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $safe = $db->real_escape_string($table);
    $result = $db->query("SHOW TABLES LIKE '{$safe}'");
    $exists = $result instanceof mysqli_result && $result->num_rows > 0;
    $cache[$table] = $exists;

    return $exists;
}

function two_factor_schema_ready(mysqli $db): bool
{
    return two_factor_table_exists($db, 'configuracion_2fa')
        && two_factor_table_exists($db, 'usuarios_2fa')
        && two_factor_table_exists($db, 'usuarios_2fa_dispositivos')
        && two_factor_table_exists($db, 'usuarios_2fa_eventos');
}

function two_factor_get_config(mysqli $db): array
{
    if (!two_factor_schema_ready($db)) {
        throw new RuntimeException(
            'La verificación en dos pasos todavía no está instalada en la base de datos.'
        );
    }

    $result = $db->query(
        "SELECT * FROM configuracion_2fa WHERE id = 1 LIMIT 1"
    );
    $row = $result instanceof mysqli_result
        ? $result->fetch_assoc()
        : null;

    if (!$row) {
        throw new RuntimeException('No existe la configuración general de 2FA.');
    }

    return $row;
}

function two_factor_normalize_role(string $role): string
{
    $role = strtolower(trim($role));
    if ($role === 'administrador') {
        return 'admin';
    }
    return $role;
}

function two_factor_role_required(array $config, string $role): bool
{
    if ((int) ($config['activo'] ?? 0) !== 1) {
        return false;
    }

    $role = two_factor_normalize_role($role);
    $map = [
        'super_administrador' => 'requerir_super_administrador',
        'admin' => 'requerir_admin',
        'recepcionista' => 'requerir_recepcionista',
        'entrenador' => 'requerir_entrenador',
    ];

    return isset($map[$role])
        && (int) ($config[$map[$role]] ?? 0) === 1;
}

function two_factor_get_user(mysqli $db, int $userId): ?array
{
    $stmt = $db->prepare(
        "SELECT
            u.id,
            u.nombre,
            u.email,
            u.password,
            u.rol,
            u.estado,
            u.password_change_required,
            COALESCE(u.auth_version, 1) AS auth_version,
            u2.enabled AS two_factor_enabled,
            u2.secret_encrypted,
            u2.last_counter,
            u2.failed_attempts,
            u2.locked_until,
            CASE
                WHEN u2.locked_until IS NOT NULL
                 AND u2.locked_until > NOW()
                THEN 1 ELSE 0
            END AS is_locked,
            u2.confirmed_at
         FROM usuarios u
         LEFT JOIN usuarios_2fa u2 ON u2.usuario_id = u.id
         WHERE u.id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        throw new RuntimeException('No fue posible consultar la seguridad del usuario.');
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function two_factor_user_enabled(array $user): bool
{
    return (int) ($user['two_factor_enabled'] ?? 0) === 1
        && trim((string) ($user['secret_encrypted'] ?? '')) !== '';
}

function two_factor_base32_encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $length = strlen($data);

    for ($i = 0; $i < $length; $i++) {
        $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }

    $output = '';
    for ($i = 0, $bitLength = strlen($bits); $i < $bitLength; $i += 5) {
        $chunk = substr($bits, $i, 5);
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        }
        $output .= $alphabet[bindec($chunk)];
    }

    return $output;
}

function two_factor_base32_decode(string $encoded): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $encoded = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $encoded) ?? '');
    $bits = '';

    for ($i = 0, $length = strlen($encoded); $i < $length; $i++) {
        $position = strpos($alphabet, $encoded[$i]);
        if ($position === false) {
            throw new InvalidArgumentException('El secreto 2FA no es válido.');
        }
        $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
    }

    $output = '';
    for ($i = 0, $bitLength = strlen($bits); $i + 8 <= $bitLength; $i += 8) {
        $output .= chr(bindec(substr($bits, $i, 8)));
    }

    return $output;
}

function two_factor_generate_secret(): string
{
    return two_factor_base32_encode(random_bytes(20));
}

function two_factor_encrypt_secret(string $secret): string
{
    $config = two_factor_config_file();
    $keyRaw = base64_decode((string) ($config['encryption_key_base64'] ?? ''), true);

    if (!is_string($keyRaw) || strlen($keyRaw) !== 32) {
        throw new RuntimeException('La clave de cifrado de 2FA no es válida.');
    }

    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $secret,
        'aes-256-gcm',
        $keyRaw,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'gym-system-2fa',
        16
    );

    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('No fue posible cifrar el secreto 2FA.');
    }

    return base64_encode($iv . $tag . $ciphertext);
}

function two_factor_decrypt_secret(string $encrypted): string
{
    $config = two_factor_config_file();
    $keyRaw = base64_decode((string) ($config['encryption_key_base64'] ?? ''), true);
    $packed = base64_decode($encrypted, true);

    if (!is_string($keyRaw) || strlen($keyRaw) !== 32 || !is_string($packed) || strlen($packed) < 29) {
        throw new RuntimeException('No fue posible leer el secreto 2FA.');
    }

    $iv = substr($packed, 0, 12);
    $tag = substr($packed, 12, 16);
    $ciphertext = substr($packed, 28);

    $plain = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $keyRaw,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'gym-system-2fa'
    );

    if (!is_string($plain) || $plain === '') {
        throw new RuntimeException('El secreto 2FA no pudo descifrarse.');
    }

    return $plain;
}

function two_factor_hotp(string $secretBase32, int $counter, int $digits = 6): string
{
    $secret = two_factor_base32_decode($secretBase32);
    $high = intdiv($counter, 0x100000000);
    $low = $counter % 0x100000000;
    $binaryCounter = pack('N2', $high, $low);
    $hash = hash_hmac('sha1', $binaryCounter, $secret, true);
    $offset = ord($hash[19]) & 0x0f;
    $binary = (
        ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff)
    );

    $otp = $binary % (10 ** $digits);
    return str_pad((string) $otp, $digits, '0', STR_PAD_LEFT);
}

function two_factor_verify_totp(
    string $secret,
    string $code,
    int $lastCounter = -1,
    int $window = 1,
    ?int $timestamp = null
): ?int {
    $code = preg_replace('/\D+/', '', $code) ?? '';
    if (strlen($code) !== 6) {
        return null;
    }

    $timestamp = $timestamp ?? time();
    $counter = intdiv($timestamp, 30);

    for ($offset = -$window; $offset <= $window; $offset++) {
        $candidateCounter = $counter + $offset;
        if ($candidateCounter <= $lastCounter) {
            continue;
        }

        $expected = two_factor_hotp($secret, $candidateCounter, 6);
        if (hash_equals($expected, $code)) {
            return $candidateCounter;
        }
    }

    return null;
}

function two_factor_build_otpauth_uri(
    string $issuer,
    string $account,
    string $secret
): string {
    $issuer = trim($issuer) !== '' ? trim($issuer) : 'Gym System';
    $label = rawurlencode($issuer . ':' . $account);

    return 'otpauth://totp/' . $label
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

function two_factor_generate_recovery_codes(int $count = 10): array
{
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $raw = strtoupper(bin2hex(random_bytes(5)));
        $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
    }
    return $codes;
}

function two_factor_hash_recovery_codes(array $codes): string
{
    $hashes = [];
    foreach ($codes as $code) {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $code) ?? '');
        $hashes[] = password_hash($normalized, PASSWORD_DEFAULT);
    }

    return json_encode($hashes, JSON_UNESCAPED_UNICODE) ?: '[]';
}

function two_factor_consume_recovery_code(mysqli $db, int $userId, string $code): bool
{
    $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code) ?? '');
    if (strlen($normalized) < 8) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT recovery_codes_json FROM usuarios_2fa WHERE usuario_id = ? AND enabled = 1 LIMIT 1"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $hashes = json_decode((string) ($row['recovery_codes_json'] ?? '[]'), true);
    if (!is_array($hashes)) {
        return false;
    }

    foreach ($hashes as $index => $hash) {
        if (is_string($hash) && password_verify($normalized, $hash)) {
            unset($hashes[$index]);
            $json = json_encode(array_values($hashes), JSON_UNESCAPED_UNICODE) ?: '[]';
            $update = $db->prepare(
                "UPDATE usuarios_2fa SET recovery_codes_json = ?, updated_at = CURRENT_TIMESTAMP WHERE usuario_id = ?"
            );
            $update->bind_param('si', $json, $userId);
            $update->execute();
            $update->close();
            return true;
        }
    }

    return false;
}

function two_factor_request_ip(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return substr($ip, 0, 45);
}

function two_factor_user_agent_hash(): string
{
    return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function two_factor_log_event(
    mysqli $db,
    int $userId,
    string $event,
    string $detail = ''
): void {
    if (!two_factor_table_exists($db, 'usuarios_2fa_eventos')) {
        return;
    }

    $event = substr(trim($event), 0, 60);
    $detail = substr(trim($detail), 0, 500);
    $ip = two_factor_request_ip();
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $stmt = $db->prepare(
        "INSERT INTO usuarios_2fa_eventos
            (usuario_id, evento, detalle, ip, user_agent)
         VALUES (?, ?, ?, ?, ?)"
    );
    if ($stmt) {
        $stmt->bind_param('issss', $userId, $event, $detail, $ip, $userAgent);
        $stmt->execute();
        $stmt->close();
    }
}

function two_factor_clear_pending(): void
{
    unset(
        $_SESSION['2fa_pending_user_id'],
        $_SESSION['2fa_pending_until'],
        $_SESSION['2fa_pending_attempts'],
        $_SESSION['2fa_setup_secret'],
        $_SESSION['2fa_setup_created_at']
    );
}

function two_factor_start_pending(array $user): void
{
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['2fa_pending_user_id'] = (int) $user['id'];
    $_SESSION['2fa_pending_until'] = time() + 600;
    $_SESSION['2fa_pending_attempts'] = 0;
}

function two_factor_pending_user_id(): int
{
    $userId = (int) ($_SESSION['2fa_pending_user_id'] ?? 0);
    $until = (int) ($_SESSION['2fa_pending_until'] ?? 0);

    if ($userId <= 0 || $until < time()) {
        two_factor_clear_pending();
        return 0;
    }

    return $userId;
}

function two_factor_cookie_path(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
    $directory = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    foreach (['/includes', '/api'] as $suffix) {
        if (substr($directory, -strlen($suffix)) === $suffix) {
            $directory = substr($directory, 0, -strlen($suffix));
        }
    }

    return $directory === '' || $directory === '.' ? '/' : $directory . '/';
}

function two_factor_cookie_name(): string
{
    $config = two_factor_config_file();
    return (string) ($config['cookie_name'] ?? 'gym_2fa_trusted');
}

function two_factor_set_cookie(string $value, int $expires): void
{
    $secure = (
        !empty($_SERVER['HTTPS'])
        && strtolower((string) $_SERVER['HTTPS']) !== 'off'
    ) || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    setcookie(two_factor_cookie_name(), $value, [
        'expires' => $expires,
        'path' => two_factor_cookie_path(),
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function two_factor_forget_trusted_cookie(): void
{
    two_factor_set_cookie('', time() - 3600);
    unset($_COOKIE[two_factor_cookie_name()]);
}

function two_factor_issue_trusted_device(
    mysqli $db,
    int $userId,
    int $days
): void {
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $validatorHash = hash('sha256', $validator);
    $userAgentHash = two_factor_user_agent_hash();
    $expiresTimestamp = time() + max(1, $days) * 86400;
    $expires = date('Y-m-d H:i:s', $expiresTimestamp);

    $stmt = $db->prepare(
        "INSERT INTO usuarios_2fa_dispositivos
            (usuario_id, selector, validator_hash, user_agent_hash, expires_at)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('issss', $userId, $selector, $validatorHash, $userAgentHash, $expires);
    $stmt->execute();
    $stmt->close();

    two_factor_set_cookie($selector . ':' . $validator, $expiresTimestamp);
}

function two_factor_trusted_device_valid(mysqli $db, int $userId): bool
{
    $cookie = (string) ($_COOKIE[two_factor_cookie_name()] ?? '');
    if ($cookie === '' || strpos($cookie, ':') === false) {
        return false;
    }

    [$selector, $validator] = explode(':', $cookie, 2);
    if (!preg_match('/^[a-f0-9]{18}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
        two_factor_forget_trusted_cookie();
        return false;
    }

    $stmt = $db->prepare(
        "SELECT id, validator_hash, user_agent_hash, expires_at
         FROM usuarios_2fa_dispositivos
         WHERE usuario_id = ?
           AND selector = ?
           AND revoked_at IS NULL
           AND expires_at > NOW()
         LIMIT 1"
    );
    $stmt->bind_param('is', $userId, $selector);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        two_factor_forget_trusted_cookie();
        return false;
    }

    $valid = hash_equals((string) $row['validator_hash'], hash('sha256', $validator))
        && hash_equals((string) $row['user_agent_hash'], two_factor_user_agent_hash());

    if (!$valid) {
        two_factor_forget_trusted_cookie();
        return false;
    }

    $deviceId = (int) $row['id'];
    $newValidator = bin2hex(random_bytes(32));
    $newHash = hash('sha256', $newValidator);
    $update = $db->prepare(
        "UPDATE usuarios_2fa_dispositivos
         SET validator_hash = ?, last_used_at = NOW()
         WHERE id = ?"
    );
    $update->bind_param('si', $newHash, $deviceId);
    $update->execute();
    $update->close();

    $expiresTimestamp = strtotime((string) $row['expires_at']);
    if ($expiresTimestamp > time()) {
        two_factor_set_cookie(
            $selector . ':' . $newValidator,
            $expiresTimestamp
        );
    }

    return true;
}

function two_factor_revoke_devices(mysqli $db, int $userId): void
{
    $stmt = $db->prepare(
        "UPDATE usuarios_2fa_dispositivos SET revoked_at = NOW() WHERE usuario_id = ? AND revoked_at IS NULL"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

function two_factor_complete_login(mysqli $db, array $user): string
{
    require_once __DIR__ . '/super_admin_helper.php';
    require_once __DIR__ . '/sucursal_context.php';

    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = (string) $user['nombre'];
    $_SESSION['user_email'] = (string) $user['email'];
    $_SESSION['user_rol_base'] = (string) $user['rol'];
    $_SESSION['user_rol'] = (string) $user['rol'];
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['two_factor_verified'] = 1;
    $_SESSION['two_factor_user_id'] = (int) $user['id'];
    $_SESSION['auth_version'] = (int) ($user['auth_version'] ?? 1);
    $_SESSION['session_user_agent_hash'] = two_factor_user_agent_hash();

    sucursal_inicializar_sesion($db);

    $effectiveRole = rol_normalizar_sistema((string) ($_SESSION['user_rol'] ?? ''));
    return $effectiveRole === 'entrenador'
        ? 'panel_entrenador.php'
        : 'dashboard.php';
}

function two_factor_session_is_verified(mysqli $db): bool
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0
        || (int) ($_SESSION['two_factor_verified'] ?? 0) !== 1
        || (int) ($_SESSION['two_factor_user_id'] ?? 0) !== $userId
        || empty($_SESSION['session_user_agent_hash'])
        || !hash_equals(
            (string) $_SESSION['session_user_agent_hash'],
            two_factor_user_agent_hash()
        )
    ) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT estado, COALESCE(auth_version, 1) AS auth_version
         FROM usuarios WHERE id = ? LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row
        && (string) $row['estado'] === 'activo'
        && (int) $row['auth_version'] === (int) ($_SESSION['auth_version'] ?? 0);
}

function two_factor_mark_failed_attempt(mysqli $db, int $userId, array $config): array
{
    $maxAttempts = max(3, (int) ($config['max_intentos'] ?? 5));
    $lockMinutes = max(1, (int) ($config['minutos_bloqueo'] ?? 15));

    $stmt = $db->prepare(
        "UPDATE usuarios_2fa
         SET failed_attempts = failed_attempts + 1,
             updated_at = CURRENT_TIMESTAMP
         WHERE usuario_id = ?"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    $user = two_factor_get_user($db, $userId) ?: [];
    $attempts = (int) ($user['failed_attempts'] ?? 0);

    if ($attempts >= $maxAttempts) {
        $lockTimeResult = $db->query(
            "SELECT DATE_FORMAT(
                DATE_ADD(NOW(), INTERVAL {$lockMinutes} MINUTE),
                '%Y-%m-%d %H:%i:%s'
             ) AS locked_until"
        );
        $lockTimeRow = $lockTimeResult instanceof mysqli_result
            ? $lockTimeResult->fetch_assoc()
            : null;
        $lockedUntil = (string) ($lockTimeRow['locked_until'] ?? '');

        if ($lockedUntil === '') {
            $lockedUntil = date('Y-m-d H:i:s', time() + ($lockMinutes * 60));
        }

        $lockStmt = $db->prepare(
            "UPDATE usuarios_2fa
             SET locked_until = ?, updated_at = CURRENT_TIMESTAMP
             WHERE usuario_id = ?"
        );
        $lockStmt->bind_param('si', $lockedUntil, $userId);
        $lockStmt->execute();
        $lockStmt->close();
        $user['locked_until'] = $lockedUntil;
    }

    return [
        'attempts' => $attempts,
        'locked_until' => (string) ($user['locked_until'] ?? ''),
    ];
}

function two_factor_reset_failed_attempts(mysqli $db, int $userId): void
{
    $stmt = $db->prepare(
        "UPDATE usuarios_2fa
         SET failed_attempts = 0, locked_until = NULL, updated_at = CURRENT_TIMESTAMP
         WHERE usuario_id = ?"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}
