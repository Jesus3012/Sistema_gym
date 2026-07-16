<?php
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/password_reset_mailer.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

set_time_limit(60);

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    http_response_code(500);
    exit('No fue posible conectar con la base de datos.');
}

if (empty($_SESSION['recuperar_password_csrf'])) {
    $_SESSION['recuperar_password_csrf'] = bin2hex(random_bytes(32));
}

$mensaje = '';
$tipo_mensaje = '';
$email_form = '';

function eRecuperar(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function obtenerMarcaRecuperacion(mysqli $db): array
{
    $marca = [
        'nombre' => 'Gym System',
        'logo' => '',
    ];

    $result = $db->query(
        "SELECT nombre, logo
         FROM configuracion_gimnasio
         WHERE id = 1
         LIMIT 1"
    );

    if ($result && $fila = $result->fetch_assoc()) {
        $nombre = trim((string) ($fila['nombre'] ?? ''));
        if ($nombre !== '') {
            $marca['nombre'] = $nombre;
        }

        $logoGuardado = trim((string) ($fila['logo'] ?? ''));
        if ($logoGuardado !== '') {
            $rutaDisco = __DIR__ . '/' . ltrim($logoGuardado, '/');
            if (is_file($rutaDisco)) {
                $marca['logo'] = $logoGuardado;
            }
        }
    }

    if ($marca['logo'] === '') {
        foreach (['png', 'jpg', 'jpeg', 'webp', 'svg'] as $extension) {
            $rutaRelativa = 'img/logo-gym.' . $extension;
            if (is_file(__DIR__ . '/' . $rutaRelativa)) {
                $marca['logo'] = $rutaRelativa;
                break;
            }
        }
    }

    return $marca;
}

function obtenerBaseUrlRecuperacion(): string
{
    $appUrl = trim((string) getenv('APP_URL'));

    if ($appUrl !== '' && filter_var($appUrl, FILTER_VALIDATE_URL)) {
        return rtrim($appUrl, '/');
    }

    $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $esquema = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    if (!preg_match('/^[a-z0-9.-]+(?::[0-9]{1,5})?$/i', $host)) {
        $host = 'localhost';
    }

    $directorio = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    $directorio = $directorio === '/' ? '' : rtrim($directorio, '/');

    return $esquema . '://' . $host . $directorio;
}

$marca = obtenerMarcaRecuperacion($db);
$nombre_gimnasio = $marca['nombre'];
$logo_gimnasio = $marca['logo'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $email_form = strtolower(trim((string) ($_POST['email'] ?? '')));

    if ($csrf === '' || !hash_equals((string) $_SESSION['recuperar_password_csrf'], $csrf)) {
        $mensaje = 'La solicitud no es válida. Actualiza la página e inténtalo nuevamente.';
        $tipo_mensaje = 'error';
    } elseif (!filter_var($email_form, FILTER_VALIDATE_EMAIL) || strlen($email_form) > 100) {
        $mensaje = 'Ingresa un correo electrónico válido.';
        $tipo_mensaje = 'warning';
    } else {
        $stmt = $db->prepare(
            "SELECT id, nombre, email
             FROM usuarios
             WHERE email = ?
               AND estado = 'activo'
             LIMIT 1"
        );

        if (!$stmt) {
            $mensaje = 'No fue posible consultar la cuenta. Inténtalo nuevamente.';
            $tipo_mensaje = 'error';
        } else {
            $stmt->bind_param('s', $email_form);
            $stmt->execute();
            $result = $stmt->get_result();
            $usuario = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if (!$usuario) {
                $mensaje = 'No fue posible enviar el correo. Verifica que la dirección pertenezca a una cuenta activa.';
                $tipo_mensaje = 'warning';
            } else {
                $usuario_id = (int) $usuario['id'];
                $totalSolicitudes = 0;

                $stmtLimite = $db->prepare(
                    "SELECT COUNT(*) AS total
                     FROM password_reset_tokens
                     WHERE usuario_id = ?
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
                );

                if ($stmtLimite) {
                    $stmtLimite->bind_param('i', $usuario_id);
                    $stmtLimite->execute();
                    $resultLimite = $stmtLimite->get_result();

                    if ($resultLimite && $filaLimite = $resultLimite->fetch_assoc()) {
                        $totalSolicitudes = (int) ($filaLimite['total'] ?? 0);
                    }

                    $stmtLimite->close();
                }

                if ($totalSolicitudes >= 3) {
                    $mensaje = 'Alcanzaste el límite de solicitudes. Espera una hora antes de intentarlo nuevamente.';
                    $tipo_mensaje = 'warning';
                } else {
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);
                    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
                    $tokenId = 0;

                    try {
                        $stmtToken = $db->prepare(
                            "INSERT INTO password_reset_tokens
                                (usuario_id, token_hash, expires_at, requested_ip)
                             VALUES
                                (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), ?)"
                        );

                        if (!$stmtToken) {
                            throw new RuntimeException('No fue posible crear el enlace de recuperación.');
                        }

                        $stmtToken->bind_param('iss', $usuario_id, $tokenHash, $ip);
                        $stmtToken->execute();
                        $tokenId = (int) $stmtToken->insert_id;
                        $stmtToken->close();

                        $urlRecuperacion =
                            obtenerBaseUrlRecuperacion()
                            . '/restablecer-password.php?token='
                            . rawurlencode($token);

                        // Esta llamada es síncrona: la página seguirá mostrando
                        // "Enviando correo" hasta que SMTP confirme o falle.
                        enviarCorreoRecuperacion(
                            $db,
                            (string) $usuario['email'],
                            (string) $usuario['nombre'],
                            $urlRecuperacion,
                            $nombre_gimnasio,
                            30
                        );

                        // El correo ya fue aceptado: ahora invalidamos enlaces anteriores.
                        $stmtInvalidar = $db->prepare(
                            "UPDATE password_reset_tokens
                             SET used_at = NOW()
                             WHERE usuario_id = ?
                               AND id <> ?
                               AND used_at IS NULL"
                        );

                        if ($stmtInvalidar) {
                            $stmtInvalidar->bind_param('ii', $usuario_id, $tokenId);
                            $stmtInvalidar->execute();
                            $stmtInvalidar->close();
                        }

                        $mensaje = 'Correo enviado correctamente. Revisa tu bandeja de entrada y la carpeta de spam.';
                        $tipo_mensaje = 'success';
                        $email_form = '';
                        $_SESSION['recuperar_password_csrf'] = bin2hex(random_bytes(32));
                    } catch (Throwable $e) {
                        if ($tokenId > 0) {
                            $stmtEliminar = $db->prepare(
                                "DELETE FROM password_reset_tokens WHERE id = ?"
                            );

                            if ($stmtEliminar) {
                                $stmtEliminar->bind_param('i', $tokenId);
                                $stmtEliminar->execute();
                                $stmtEliminar->close();
                            }
                        }

                        error_log('Recuperación de contraseña: ' . $e->getMessage());
                        $mensaje = 'No fue posible enviar el correo. Revisa que PHPMailer y la configuración SMTP estén funcionando.';
                        $tipo_mensaje = 'error';
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1e3a8a">
    <title>Recuperar contraseña - <?php echo eRecuperar($nombre_gimnasio); ?></title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        :root {
            --azul: #1e3a8a;
            --azul-hover: #25489e;
            --azul-suave: #eef4ff;
            --fondo: #f3f6fa;
            --blanco: #ffffff;
            --texto: #1f2937;
            --suave: #64748b;
            --borde: #dbe3ee;
        }

        * { box-sizing: border-box; }

        html, body {
            width: 100%;
            min-height: 100%;
            margin: 0;
            overflow-x: hidden;
        }

        body {
            display: grid;
            min-height: 100dvh;
            place-items: center;
            padding: 18px;
            color: var(--texto);
            background:
                radial-gradient(circle at top left, rgba(30, 58, 138, .07), transparent 27rem),
                var(--fondo);
            font-family: "Segoe UI", Roboto, Arial, sans-serif;
        }

        button, input, a { font: inherit; }

        .recovery-card {
            width: min(455px, 100%);
            padding: 30px;
            border: 1px solid var(--borde);
            border-radius: 20px;
            background: var(--blanco);
            box-shadow: 0 14px 36px rgba(15, 23, 42, .09);
        }

        .brand-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 76px;
            margin-bottom: 22px;
        }

        .brand-logo img {
            display: block;
            width: auto;
            max-width: min(210px, 72%);
            height: auto;
            max-height: 76px;
            object-fit: contain;
        }

        .brand-logo i {
            color: var(--azul);
            font-size: 2.5rem;
        }

        .header {
            margin-bottom: 23px;
            text-align: center;
        }

        .header h1 {
            margin: 0 0 8px;
            color: var(--azul);
            font-size: clamp(1.55rem, 5vw, 1.95rem);
            line-height: 1.15;
            letter-spacing: -.03em;
        }

        .header p {
            max-width: 360px;
            margin: 0 auto;
            color: var(--suave);
            font-size: .86rem;
            line-height: 1.55;
        }

        .alert {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            margin-bottom: 18px;
            padding: 12px 13px;
            border: 1px solid;
            border-radius: 11px;
            font-size: .79rem;
            line-height: 1.5;
        }

        .alert i { margin-top: 2px; flex: 0 0 auto; }
        .alert-success { color: #065f46; border-color: #a7f3d0; background: #ecfdf5; }
        .alert-error { color: #991b1b; border-color: #fecaca; background: #fef2f2; }
        .alert-warning { color: #92400e; border-color: #fde68a; background: #fffbeb; }

        .field { margin-bottom: 17px; }

        .field label {
            display: block;
            margin-bottom: 7px;
            color: var(--texto);
            font-size: .78rem;
            font-weight: 750;
        }

        .input-wrap { position: relative; }

        .input-wrap > i {
            position: absolute;
            top: 50%;
            left: 14px;
            color: #94a3b8;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .input-wrap input {
            width: 100%;
            min-height: 50px;
            padding: 0 14px 0 43px;
            border: 1px solid var(--borde);
            border-radius: 11px;
            outline: none;
            color: var(--texto);
            background: #f8fafc;
            font-size: .87rem;
            transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
        }

        .input-wrap input:focus {
            border-color: #82a4df;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(30, 58, 138, .10);
        }

        .submit-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            min-height: 49px;
            border: 0;
            border-radius: 11px;
            color: #ffffff;
            background: var(--azul);
            cursor: pointer;
            font-size: .86rem;
            font-weight: 800;
            transition: background .18s ease, transform .18s ease;
        }

        .submit-button:hover { background: var(--azul-hover); transform: translateY(-1px); }
        .submit-button:disabled { cursor: wait; opacity: .72; transform: none; }

        .footer-row {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 19px;
            padding-top: 17px;
            border-top: 1px solid #edf1f6;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: var(--azul);
            text-decoration: none;
            font-size: .78rem;
            font-weight: 750;
        }

        .back-link:hover { text-decoration: underline; }

        .sending-overlay {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(15, 23, 42, .44);
            backdrop-filter: blur(4px);
        }

        .sending-overlay.active { display: flex; }

        .sending-box {
            width: min(340px, 100%);
            padding: 26px 22px;
            border-radius: 18px;
            background: #ffffff;
            box-shadow: 0 22px 55px rgba(15, 23, 42, .24);
            text-align: center;
        }

        .spinner {
            width: 44px;
            height: 44px;
            margin: 0 auto 16px;
            border: 4px solid #dbeafe;
            border-top-color: var(--azul);
            border-radius: 50%;
            animation: spin .8s linear infinite;
        }

        .sending-box strong {
            display: block;
            margin-bottom: 6px;
            color: var(--azul);
            font-size: 1rem;
        }

        .sending-box span {
            color: var(--suave);
            font-size: .8rem;
            line-height: 1.5;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        @media (max-width: 480px) {
            body { padding: 10px; align-items: start; }
            .recovery-card { margin-top: 12px; padding: 24px 18px; border-radius: 17px; }
            .brand-logo { min-height: 64px; margin-bottom: 18px; }
            .brand-logo img { max-height: 64px; max-width: min(190px, 76%); }
        }

        @media (prefers-reduced-motion: reduce) {
            .input-wrap input, .submit-button { transition: none; }
            .spinner { animation-duration: 1.6s; }
        }
    </style>
</head>
<body>
    <main class="recovery-card">
        <div class="brand-logo">
            <?php if ($logo_gimnasio !== ''): ?>
                <img
                    src="<?php echo eRecuperar($logo_gimnasio); ?>"
                    alt="Logo de <?php echo eRecuperar($nombre_gimnasio); ?>"
                >
            <?php else: ?>
                <i class="fas fa-dumbbell" aria-hidden="true"></i>
            <?php endif; ?>
        </div>

        <header class="header">
            <h1>Recuperar contraseña</h1>
            <p>Ingresa el correo de tu cuenta y te enviaremos un enlace temporal para crear una contraseña nueva.</p>
        </header>

        <?php if ($mensaje !== ''): ?>
            <div class="alert alert-<?php echo eRecuperar($tipo_mensaje); ?>" role="alert">
                <i class="fas <?php
                    echo $tipo_mensaje === 'success'
                        ? 'fa-circle-check'
                        : ($tipo_mensaje === 'warning'
                            ? 'fa-triangle-exclamation'
                            : 'fa-circle-xmark');
                ?>" aria-hidden="true"></i>
                <span><?php echo eRecuperar($mensaje); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" id="recoveryForm" novalidate>
            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo eRecuperar((string) $_SESSION['recuperar_password_csrf']); ?>"
            >

            <div class="field">
                <label for="email">Correo electrónico</label>
                <div class="input-wrap">
                    <i class="fas fa-envelope" aria-hidden="true"></i>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        maxlength="100"
                        autocomplete="email"
                        placeholder="ejemplo@correo.com"
                        value="<?php echo eRecuperar($email_form); ?>"
                        required
                    >
                </div>
            </div>

            <button type="submit" class="submit-button" id="submitButton">
                <i class="fas fa-paper-plane" aria-hidden="true"></i>
                <span>Enviar enlace</span>
            </button>
        </form>

        <div class="footer-row">
            <a href="login.php" class="back-link">
                <i class="fas fa-arrow-left" aria-hidden="true"></i>
                Volver al inicio de sesión
            </a>
        </div>
    </main>

    <div class="sending-overlay" id="sendingOverlay" role="status" aria-live="polite" aria-hidden="true">
        <div class="sending-box">
            <div class="spinner" aria-hidden="true"></div>
            <strong>Enviando correo</strong>
            <span>Espera mientras el servidor confirma el envío del enlace de recuperación.</span>
        </div>
    </div>

    <script>
    const recoveryForm = document.getElementById('recoveryForm');
    const submitButton = document.getElementById('submitButton');
    const sendingOverlay = document.getElementById('sendingOverlay');

    recoveryForm.addEventListener('submit', function (event) {
        const email = document.getElementById('email');

        if (!email.checkValidity()) {
            event.preventDefault();
            email.reportValidity();
            return;
        }

        submitButton.disabled = true;
        submitButton.querySelector('span').textContent = 'Enviando...';
        sendingOverlay.classList.add('active');
        sendingOverlay.setAttribute('aria-hidden', 'false');
    });

    window.addEventListener('pageshow', function () {
        submitButton.disabled = false;
        submitButton.querySelector('span').textContent = 'Enviar enlace';
        sendingOverlay.classList.remove('active');
        sendingOverlay.setAttribute('aria-hidden', 'true');
    });
    </script>
</body>
</html>>