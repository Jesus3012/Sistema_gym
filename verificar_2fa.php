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

    if (!two_factor_user_enabled($user)) {
        header('Location: configurar_2fa.php');
        exit;
    }

    if (empty($_SESSION['2fa_csrf'])) {
        $_SESSION['2fa_csrf'] = bin2hex(random_bytes(32));
    }

    $lockedUntil = trim((string) ($user['locked_until'] ?? ''));
    $isLocked = (int) ($user['is_locked'] ?? 0) === 1;

    if ($lockedUntil !== '' && !$isLocked) {
        two_factor_reset_failed_attempts($db, $userId);
        $user['failed_attempts'] = 0;
        $user['locked_until'] = null;
        $lockedUntil = '';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = (string) ($_POST['csrf'] ?? '');
        $expectedCsrf = (string) ($_SESSION['2fa_csrf'] ?? '');

        if ($expectedCsrf === '' || !hash_equals($expectedCsrf, $csrf)) {
            throw new RuntimeException('La sesión de verificación cambió. Recarga la página.');
        }

        if ($isLocked) {
            $error = 'La verificación está bloqueada temporalmente. Espera unos minutos e inténtalo nuevamente.';
        } else {
            $verification = trim((string) ($_POST['codigo'] ?? ''));
            $normalizedDigits = preg_replace('/\D+/', '', $verification) ?? '';
            $verified = false;
            $usedRecovery = false;
            $counter = null;

            if (strlen($normalizedDigits) === 6) {
                $secret = two_factor_decrypt_secret((string) $user['secret_encrypted']);
                $counter = two_factor_verify_totp(
                    $secret,
                    $normalizedDigits,
                    (int) ($user['last_counter'] ?? -1),
                    1
                );
                $verified = $counter !== null;
            } else {
                $verified = two_factor_consume_recovery_code($db, $userId, $verification);
                $usedRecovery = $verified;
            }

            if (!$verified) {
                $failure = two_factor_mark_failed_attempt($db, $userId, $config);
                two_factor_log_event($db, $userId, '2fa_fallido', 'Código de verificación incorrecto.');

                $remaining = max(
                    0,
                    (int) ($config['max_intentos'] ?? 5) - (int) ($failure['attempts'] ?? 0)
                );

                $error = $remaining > 0
                    ? 'El código no es correcto. Te quedan ' . $remaining . ' intento(s) antes del bloqueo temporal.'
                    : 'Demasiados intentos. La verificación quedó bloqueada temporalmente.';
            } else {
                if (!$usedRecovery && $counter !== null) {
                    $stmt = $db->prepare(
                        "UPDATE usuarios_2fa
                         SET last_counter = ?, last_verified_at = NOW(),
                             failed_attempts = 0, locked_until = NULL,
                             updated_at = CURRENT_TIMESTAMP
                         WHERE usuario_id = ?"
                    );
                    $stmt->bind_param('ii', $counter, $userId);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    two_factor_reset_failed_attempts($db, $userId);
                    $stmt = $db->prepare(
                        "UPDATE usuarios_2fa SET last_verified_at = NOW() WHERE usuario_id = ?"
                    );
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $stmt->close();
                }

                $confiarDispositivo =
                    (int) ($config['permitir_dispositivo_confiable'] ?? 0) === 1
                    && isset($_POST['confiar_dispositivo']);

                two_factor_log_event(
                    $db,
                    $userId,
                    $usedRecovery ? '2fa_recuperacion_usada' : '2fa_verificado',
                    $usedRecovery ? 'Se utilizó un código de recuperación.' : 'Código TOTP correcto.'
                );

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

                header('Location: ' . $destination);
                exit;
            }
        }
    }
} catch (Throwable $exception) {
    error_log('[Verificar 2FA] ' . $exception->getMessage());
    $error = $exception->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1e3a8a">
    <title>Verificación en dos pasos</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="css/two_factor.css?v=1.0.0">
    <link rel="stylesheet" href="tema.css.php?v=1" data-system-theme="true">
</head>
<body>
<section class="tf-shell" style="max-width:560px;">
    <header class="tf-header">
        <span class="tf-header-icon"><i class="fa-solid fa-mobile-screen-button"></i></span>
        <div>
            <h1>Confirma que eres tú</h1>
            <p>La contraseña fue correcta. Falta validar el segundo factor.</p>
        </div>
    </header>

    <div class="tf-body">
        <?php if ($error !== ''): ?>
            <div class="tf-alert"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="tf-content">
            <p class="tf-kicker">Verificación de acceso</p>
            <h2>Escribe el código actual</h2>
            <p>Abre tu aplicación autenticadora y captura los seis dígitos. También puedes utilizar uno de tus códigos de recuperación.</p>

            <form method="POST" class="tf-form" autocomplete="off">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string) ($_SESSION['2fa_csrf'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                <label class="tf-label" for="codigo">Código de autenticación o recuperación</label>
                <input class="tf-code-input" type="text" id="codigo" name="codigo" inputmode="text" autocomplete="one-time-code" maxlength="14" required autofocus <?php echo !empty($isLocked) ? 'disabled' : ''; ?>>

                <?php if ((int) ($config['permitir_dispositivo_confiable'] ?? 0) === 1 && empty($isLocked)): ?>
                    <label class="tf-check">
                        <input type="checkbox" name="confiar_dispositivo" value="1">
                        <span>Confiar en este dispositivo durante <?php echo (int) ($config['dias_dispositivo_confiable'] ?? 30); ?> días. No lo marques en equipos compartidos.</span>
                    </label>
                <?php endif; ?>

                <button type="submit" class="tf-button" <?php echo !empty($isLocked) ? 'disabled' : ''; ?>>
                    Verificar e ingresar <i class="fa-solid fa-arrow-right"></i>
                </button>
            </form>

            <a class="tf-link" href="login.php?reiniciar=1">Volver al inicio de sesión</a>
            <div class="tf-note"><i class="fa-solid fa-circle-info"></i><span>Los códigos de la aplicación cambian cada 30 segundos. Si uno está por vencer, espera al siguiente.</span></div>
        </div>
    </div>
</section>
<script>
const codeInput = document.getElementById('codigo');
codeInput?.addEventListener('input', () => {
    let value = codeInput.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
    if (/^\d+$/.test(value.replace(/-/g, ''))) {
        value = value.replace(/\D/g, '').slice(0, 6);
    }
    codeInput.value = value.slice(0, 11);
});
</script>
</body>
</html>
