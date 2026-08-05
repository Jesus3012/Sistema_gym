<?php

declare(strict_types=1);

/**
 * Contraseña temporal predeterminada del sistema.
 *
 * La base de datos conserva únicamente el valor cifrado. La clave de
 * cifrado se genera por instalación y se guarda en:
 * config/password_temporal_key.php
 */

if (!defined('PASSWORD_TEMPORAL_LEGACY_DEFAULT')) {
    define('PASSWORD_TEMPORAL_LEGACY_DEFAULT', 'ego1');
}

function password_temporal_table_exists(mysqli $db): bool
{
    $result = $db->query(
        "SHOW TABLES LIKE 'configuracion_acceso'"
    );

    return $result instanceof mysqli_result
        && $result->num_rows > 0;
}

/**
 * @return array<string, mixed>
 */
function password_temporal_get_metadata(mysqli $db): array
{
    if (!password_temporal_table_exists($db)) {
        return array(
            'table_exists' => false,
            'configured' => false,
            'updated_at' => null,
            'updated_by_name' => null,
            'uses_legacy_default' => true,
        );
    }

    $result = $db->query(
        "SELECT
            ca.password_temporal_cifrada,
            ca.updated_at,
            u.nombre AS updated_by_name
         FROM configuracion_acceso ca
         LEFT JOIN usuarios u ON u.id = ca.actualizado_por
         WHERE ca.id = 1
         LIMIT 1"
    );

    $row = $result instanceof mysqli_result
        ? $result->fetch_assoc()
        : null;

    $configured = is_array($row)
        && trim((string) ($row['password_temporal_cifrada'] ?? '')) !== '';

    return array(
        'table_exists' => true,
        'configured' => $configured,
        'updated_at' => $row['updated_at'] ?? null,
        'updated_by_name' => $row['updated_by_name'] ?? null,
        'uses_legacy_default' => !$configured,
    );
}

function password_temporal_validate(
    string $password,
    string $confirmation
): string {
    $password = trim($password);
    $confirmation = trim($confirmation);

    if ($password === '' || $confirmation === '') {
        throw new RuntimeException(
            'Escribe y confirma la contraseña temporal del sistema.'
        );
    }

    $length = strlen($password);

    if ($length < 4 || $length > 72) {
        throw new RuntimeException(
            'La contraseña temporal debe contener entre 4 y 72 caracteres.'
        );
    }

    if (!hash_equals($password, $confirmation)) {
        throw new RuntimeException(
            'La contraseña temporal y su confirmación no coinciden.'
        );
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $password)) {
        throw new RuntimeException(
            'La contraseña temporal contiene caracteres no permitidos.'
        );
    }

    return $password;
}

function password_temporal_key_path(): string
{
    return dirname(__DIR__)
        . '/config/password_temporal_key.php';
}

function password_temporal_get_encryption_key(): string
{
    $environmentKey = getenv('GYM_PASSWORD_TEMPORAL_KEY');

    if (is_string($environmentKey) && trim($environmentKey) !== '') {
        $decoded = base64_decode(trim($environmentKey), true);

        if (is_string($decoded) && strlen($decoded) === 32) {
            return $decoded;
        }

        throw new RuntimeException(
            'GYM_PASSWORD_TEMPORAL_KEY debe contener una clave base64 de 32 bytes.'
        );
    }

    $path = password_temporal_key_path();

    if (is_file($path)) {
        $stored = require $path;
        $decoded = is_string($stored)
            ? base64_decode(trim($stored), true)
            : false;

        if (is_string($decoded) && strlen($decoded) === 32) {
            return $decoded;
        }

        throw new RuntimeException(
            'La clave de cifrado de la contraseña temporal no es válida.'
        );
    }

    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException(
            'La extensión OpenSSL es necesaria para proteger la contraseña temporal.'
        );
    }

    $directory = dirname($path);

    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException(
            'La carpeta config debe permitir crear password_temporal_key.php una sola vez.'
        );
    }

    $rawKey = random_bytes(32);
    $encoded = base64_encode($rawKey);
    $contents = "<?php\n\ndeclare(strict_types=1);\n\n"
        . "// Clave única de esta instalación. No la publiques ni la reemplaces.\n"
        . "return '"
        . $encoded
        . "';\n";

    $written = file_put_contents(
        $path,
        $contents,
        LOCK_EX
    );

    if ($written === false) {
        throw new RuntimeException(
            'No fue posible crear la clave de cifrado de esta instalación.'
        );
    }

    @chmod($path, 0600);

    return $rawKey;
}

function password_temporal_encrypt(string $plainText): string
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException(
            'La extensión OpenSSL es necesaria para cifrar la contraseña temporal.'
        );
    }

    $key = password_temporal_get_encryption_key();
    $iv = random_bytes(12);
    $tag = '';

    $cipherText = openssl_encrypt(
        $plainText,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'gym-password-temporal-v1',
        16
    );

    if (!is_string($cipherText) || $tag === '') {
        throw new RuntimeException(
            'No fue posible cifrar la contraseña temporal.'
        );
    }

    $payload = json_encode(
        array(
            'v' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($cipherText),
        ),
        JSON_UNESCAPED_SLASHES
    );

    if (!is_string($payload)) {
        throw new RuntimeException(
            'No fue posible preparar la contraseña temporal cifrada.'
        );
    }

    return $payload;
}

function password_temporal_decrypt(string $payload): string
{
    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException(
            'La extensión OpenSSL es necesaria para descifrar la contraseña temporal.'
        );
    }

    $decodedPayload = json_decode($payload, true);

    if (!is_array($decodedPayload)) {
        throw new RuntimeException(
            'La contraseña temporal guardada no tiene un formato válido.'
        );
    }

    $iv = base64_decode(
        (string) ($decodedPayload['iv'] ?? ''),
        true
    );
    $tag = base64_decode(
        (string) ($decodedPayload['tag'] ?? ''),
        true
    );
    $cipherText = base64_decode(
        (string) ($decodedPayload['data'] ?? ''),
        true
    );

    if (
        !is_string($iv)
        || strlen($iv) !== 12
        || !is_string($tag)
        || strlen($tag) !== 16
        || !is_string($cipherText)
    ) {
        throw new RuntimeException(
            'La contraseña temporal cifrada está incompleta.'
        );
    }

    $plainText = openssl_decrypt(
        $cipherText,
        'aes-256-gcm',
        password_temporal_get_encryption_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'gym-password-temporal-v1'
    );

    if (!is_string($plainText) || $plainText === '') {
        throw new RuntimeException(
            'No fue posible descifrar la contraseña temporal del sistema.'
        );
    }

    return $plainText;
}

function password_temporal_get(mysqli $db): string
{
    /*
     * Compatibilidad: si la migración aún no se ejecutó o la instalación
     * todavía no ha personalizado el valor, se conserva ego1.
     */
    if (!password_temporal_table_exists($db)) {
        return PASSWORD_TEMPORAL_LEGACY_DEFAULT;
    }

    $result = $db->query(
        "SELECT password_temporal_cifrada
         FROM configuracion_acceso
         WHERE id = 1
         LIMIT 1"
    );

    $row = $result instanceof mysqli_result
        ? $result->fetch_assoc()
        : null;
    $encrypted = trim((string) (
        $row['password_temporal_cifrada'] ?? ''
    ));

    if ($encrypted === '') {
        return PASSWORD_TEMPORAL_LEGACY_DEFAULT;
    }

    return password_temporal_decrypt($encrypted);
}

function password_temporal_save(
    mysqli $db,
    string $password,
    int $updatedBy
): void {
    if (!password_temporal_table_exists($db)) {
        throw new RuntimeException(
            'Ejecuta primero database/instalar_password_temporal_sistema.sql.'
        );
    }

    $encrypted = password_temporal_encrypt($password);
    $stmt = $db->prepare(
        "INSERT INTO configuracion_acceso
            (
                id,
                password_temporal_cifrada,
                actualizado_por
            )
         VALUES (1, ?, ?)
         ON DUPLICATE KEY UPDATE
            password_temporal_cifrada = VALUES(password_temporal_cifrada),
            actualizado_por = VALUES(actualizado_por),
            updated_at = CURRENT_TIMESTAMP"
    );

    if (!$stmt instanceof mysqli_stmt) {
        throw new RuntimeException(
            'No fue posible preparar la configuración de acceso.'
        );
    }

    $stmt->bind_param('si', $encrypted, $updatedBy);
    $stmt->execute();
    $stmt->close();
}
