<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/session_security.php';
secure_session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/two_factor_helper.php';

$database = new Database();
$db = $database->getConnection();

if (!$db instanceof mysqli) {
    http_response_code(500);
    exit('No fue posible conectar con la base de datos.');
}
$db->set_charset('utf8mb4');

$error = '';
$recoveryCodes = [];
$destination = 'dashboard.php';
$userId = two_factor_pending_user_id();

if ($userId <= 0) {
    header('Location: login.php?error=sesion_2fa_expirada');
    exit;
}

try {
    $config = two_factor_get_config($db);
    $user = two_factor_get_user($db, $userId);

    if (!$user || (string) $user['estado'] !== 'activo') {
        throw new RuntimeException('La cuenta ya no está disponible.');
    }

    if (two_factor_user_enabled($user)) {
        header('Location: verificar_2fa.php');
        exit;
    }

    if (empty($_SESSION['2fa_csrf'])) {
        $_SESSION['2fa_csrf'] = bin2hex(random_bytes(32));
    }

    $secretCreatedAt = (int) ($_SESSION['2fa_setup_created_at'] ?? 0);
    if (
        empty($_SESSION['2fa_setup_secret'])
        || $secretCreatedAt <= 0
        || time() - $secretCreatedAt > 600
    ) {
        $_SESSION['2fa_setup_secret'] = two_factor_generate_secret();
        $_SESSION['2fa_setup_created_at'] = time();
    }

    $secret = (string) $_SESSION['2fa_setup_secret'];
    $issuer = trim((string) ($config['emisor'] ?? '')) ?: 'Gym System';
    $otpauthUri = two_factor_build_otpauth_uri(
        $issuer,
        (string) $user['email'],
        $secret
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = (string) ($_POST['csrf'] ?? '');
        $expectedCsrf = (string) ($_SESSION['2fa_csrf'] ?? '');

        if ($expectedCsrf === '' || !hash_equals($expectedCsrf, $csrf)) {
            throw new RuntimeException('La sesión de configuración cambió. Recarga la página.');
        }

        $code = (string) ($_POST['codigo'] ?? '');
        $counter = two_factor_verify_totp($secret, $code, -1, 1);

        if ($counter === null) {
            $_SESSION['2fa_pending_attempts'] =
                (int) ($_SESSION['2fa_pending_attempts'] ?? 0) + 1;

            if ((int) $_SESSION['2fa_pending_attempts'] >= 5) {
                two_factor_log_event(
                    $db,
                    $userId,
                    '2fa_configuracion_fallida',
                    'Se agotaron los intentos de confirmación durante la configuración.'
                );
                two_factor_clear_pending();
                header('Location: login.php?error=sesion_2fa_expirada');
                exit;
            }

            $remainingSetup = 5 - (int) $_SESSION['2fa_pending_attempts'];
            $error = 'El código no coincide. Revisa la hora de tu teléfono. Te quedan '
                . $remainingSetup . ' intento(s).';
        } else {
            $recoveryCodes = two_factor_generate_recovery_codes(10);
            $encrypted = two_factor_encrypt_secret($secret);
            $recoveryJson = two_factor_hash_recovery_codes($recoveryCodes);

            $stmt = $db->prepare(
                "INSERT INTO usuarios_2fa
                    (usuario_id, secret_encrypted, enabled, recovery_codes_json,
                     last_counter, failed_attempts, locked_until, confirmed_at,
                     last_verified_at)
                 VALUES (?, ?, 1, ?, ?, 0, NULL, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    secret_encrypted = VALUES(secret_encrypted),
                    enabled = 1,
                    recovery_codes_json = VALUES(recovery_codes_json),
                    last_counter = VALUES(last_counter),
                    failed_attempts = 0,
                    locked_until = NULL,
                    confirmed_at = NOW(),
                    last_verified_at = NOW(),
                    updated_at = CURRENT_TIMESTAMP"
            );

            if (!$stmt) {
                throw new RuntimeException('No fue posible guardar la configuración de seguridad.');
            }

            $stmt->bind_param('issi', $userId, $encrypted, $recoveryJson, $counter);
            $stmt->execute();
            $stmt->close();

            two_factor_log_event($db, $userId, '2fa_activado', 'Aplicación autenticadora configurada.');

            $confiarDispositivo =
                (int) ($config['permitir_dispositivo_confiable'] ?? 0) === 1
                && isset($_POST['confiar_dispositivo']);

            try {
                $destination = two_factor_complete_login($db, $user);
            } catch (Throwable $loginError) {
                $_SESSION = [];
                session_regenerate_id(true);
                throw $loginError;
            }

            if ($confiarDispositivo) {
                two_factor_issue_trusted_device(
                    $db,
                    $userId,
                    (int) ($config['dias_dispositivo_confiable'] ?? 30)
                );
            }

        }
    }
} catch (Throwable $exception) {
    error_log('[Configurar 2FA] ' . $exception->getMessage());
    $error = $exception->getMessage();
}

$showRecovery = $recoveryCodes !== [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1e3a8a">
    <title>Configurar verificación en dos pasos</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="css/two_factor.css?v=1.0.0">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>
<section class="tf-shell">
    <header class="tf-header">
        <span class="tf-header-icon"><i class="fa-solid fa-shield-halved"></i></span>
        <div>
            <h1><?php echo $showRecovery ? 'Guarda tus códigos de recuperación' : 'Protege tu cuenta'; ?></h1>
            <p><?php echo $showRecovery
                ? 'Son la única alternativa de acceso cuando no tengas tu teléfono.'
                : 'Configura una aplicación autenticadora antes de ingresar al sistema.'; ?></p>
        </div>
    </header>

    <div class="tf-body">
        <?php if ($showRecovery): ?>
            <div class="tf-content">
                <p class="tf-kicker">Configuración completada</p>
                <h2>Verificación en dos pasos activada</h2>
                <p>Guarda estos códigos en un lugar seguro. Cada código funciona una sola vez y no volverán a mostrarse completos.</p>

                <div class="tf-recovery" id="recoveryCodes">
                    <?php foreach ($recoveryCodes as $recoveryCode): ?>
                        <code><?php echo htmlspecialchars($recoveryCode, ENT_QUOTES, 'UTF-8'); ?></code>
                    <?php endforeach; ?>
                </div>

                <div class="tf-actions">
                    <button type="button" class="tf-button secondary" id="copyCodes">
                        <i class="fa-regular fa-copy"></i> Copiar códigos
                    </button>
                    <a class="tf-button" href="<?php echo htmlspecialchars($destination, ENT_QUOTES, 'UTF-8'); ?>">
                        Continuar al sistema <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        <?php else: ?>
            <?php if ($error !== ''): ?>
                <div class="tf-alert"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <div class="tf-grid">
                <div class="tf-qr" id="authQr" aria-label="Código QR para la aplicación autenticadora"></div>

                <div class="tf-content">
                    <p class="tf-kicker">Configuración inicial</p>
                    <h2>Escanea el código QR</h2>
                    <p>Usa Google Authenticator, Microsoft Authenticator, Authy u otra aplicación compatible con códigos TOTP.</p>

                    <div class="tf-steps">
                        <div class="tf-step"><span class="tf-step-number">1</span><div><strong>Abre tu aplicación</strong><span>Selecciona agregar cuenta o escanear código QR.</span></div></div>
                        <div class="tf-step"><span class="tf-step-number">2</span><div><strong>Escanea el QR</strong><span>La cuenta aparecerá con tu correo y el nombre del gimnasio.</span></div></div>
                        <div class="tf-step"><span class="tf-step-number">3</span><div><strong>Confirma el código</strong><span>Escribe el código actual de seis dígitos.</span></div></div>
                    </div>

                    <div class="tf-secret">
                        <small>Clave manual, por si no puedes escanear:</small>
                        <code><?php echo htmlspecialchars($secret ?? '', ENT_QUOTES, 'UTF-8'); ?></code>
                    </div>

                    <form method="POST" class="tf-form" autocomplete="off">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string) ($_SESSION['2fa_csrf'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <label class="tf-label" for="codigo">Código de seis dígitos</label>
                        <input class="tf-code-input" type="text" id="codigo" name="codigo" inputmode="numeric" autocomplete="one-time-code" maxlength="8" pattern="[0-9 ]{6,8}" required autofocus>

                        <?php if ((int) ($config['permitir_dispositivo_confiable'] ?? 0) === 1): ?>
                            <label class="tf-check">
                                <input type="checkbox" name="confiar_dispositivo" value="1">
                                <span>Confiar en este dispositivo durante <?php echo (int) ($config['dias_dispositivo_confiable'] ?? 30); ?> días. No lo marques en una computadora compartida.</span>
                            </label>
                        <?php endif; ?>

                        <button type="submit" class="tf-button">
                            Activar y continuar <i class="fa-solid fa-arrow-right"></i>
                        </button>
                    </form>
                </div>
            </div>

            <div class="tf-note"><i class="fa-solid fa-lock"></i><span>El secreto se guarda cifrado. Los códigos cambian cada 30 segundos y la sesión completa no se crea hasta validar uno correctamente.</span></div>
        <?php endif; ?>
    </div>
</section>

<script>
<?php if (!$showRecovery): ?>
const authQr = document.getElementById('authQr');
if (window.QRCode) {
    new QRCode(authQr, {
        text: <?php echo json_encode($otpauthUri ?? '', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>,
        width: 244,
        height: 244,
        colorDark: '#152c6b',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
    });
} else if (authQr) {
    authQr.innerHTML = '<div style="padding:20px;text-align:center;color:#667085;font-size:13px;line-height:1.5;"><i class="fa-solid fa-triangle-exclamation" style="font-size:24px;color:#d97706;margin-bottom:10px;"></i><br>No se pudo cargar el generador QR. Usa la clave manual mostrada a la derecha.</div>';
}

const codeInput = document.getElementById('codigo');
codeInput?.addEventListener('input', () => {
    codeInput.value = codeInput.value.replace(/\D/g, '').slice(0, 6);
});
<?php else: ?>
document.getElementById('copyCodes')?.addEventListener('click', async () => {
    const codes = Array.from(document.querySelectorAll('#recoveryCodes code'))
        .map(item => item.textContent.trim())
        .join('\n');
    await navigator.clipboard.writeText(codes);
    const button = document.getElementById('copyCodes');
    button.innerHTML = '<i class="fa-solid fa-check"></i> Códigos copiados';
});
<?php endif; ?>
</script>
</body>
</html>
