<?php
session_start();

require_once __DIR__ . '/config/database.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    http_response_code(500);
    exit('No fue posible conectar con la base de datos.');
}

if (empty($_SESSION['restablecer_password_csrf'])) {
    $_SESSION['restablecer_password_csrf'] = bin2hex(random_bytes(32));
}

function eReset(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function obtenerMarcaReset(mysqli $db): array
{
    $marca = ['nombre' => 'Gym System', 'logo' => ''];

    $result = $db->query(
        "SELECT nombre, logo
         FROM configuracion_gimnasio
         WHERE id = 1
         LIMIT 1"
    );

    if ($result && $fila = $result->fetch_assoc()) {
        if (trim((string) ($fila['nombre'] ?? '')) !== '') {
            $marca['nombre'] = (string) $fila['nombre'];
        }

        $logoGuardado = trim((string) ($fila['logo'] ?? ''));

        if ($logoGuardado !== '' && is_file(__DIR__ . '/' . ltrim($logoGuardado, '/'))) {
            $marca['logo'] = $logoGuardado;
        }
    }

    if ($marca['logo'] === '') {
        foreach (['png', 'jpg', 'jpeg', 'webp', 'svg'] as $extension) {
            $ruta = 'img/logo-gym.' . $extension;

            if (is_file(__DIR__ . '/' . $ruta)) {
                $marca['logo'] = $ruta;
                break;
            }
        }
    }

    return $marca;
}

function buscarTokenValido(mysqli $db, string $tokenHash, bool $bloquear = false): ?array
{
    $sql = "
        SELECT
            prt.id AS token_id,
            prt.usuario_id,
            u.nombre,
            u.email
        FROM password_reset_tokens prt
        INNER JOIN usuarios u ON u.id = prt.usuario_id
        WHERE prt.token_hash = ?
          AND prt.used_at IS NULL
          AND prt.expires_at > NOW()
          AND u.estado = 'activo'
        LIMIT 1
    ";

    if ($bloquear) {
        $sql .= " FOR UPDATE";
    }

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $result = $stmt->get_result();
    $fila = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $fila ?: null;
}

$marca = obtenerMarcaReset($db);
$nombre_gimnasio = $marca['nombre'];
$logo_gimnasio = $marca['logo'];

$token = trim((string) ($_POST['token'] ?? $_GET['token'] ?? ''));
$token_formato_valido = preg_match('/^[a-f0-9]{64}$/i', $token) === 1;
$token_hash = $token_formato_valido ? hash('sha256', strtolower($token)) : '';
$token_data = $token_formato_valido ? buscarTokenValido($db, $token_hash) : null;

$mensaje = '';
$tipo_mensaje = '';
$restablecido = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirmacion = (string) ($_POST['confirm_password'] ?? '');

    if (
        $csrf === ''
        || !hash_equals((string) $_SESSION['restablecer_password_csrf'], $csrf)
    ) {
        $mensaje = 'La solicitud no es válida. Actualiza la página e inténtalo nuevamente.';
        $tipo_mensaje = 'error';
    } elseif (!$token_formato_valido || !$token_data) {
        $mensaje = 'El enlace de recuperación venció, ya fue utilizado o no es válido.';
        $tipo_mensaje = 'error';
    } elseif (strlen($password) < 8) {
        $mensaje = 'La nueva contraseña debe tener al menos 8 caracteres.';
        $tipo_mensaje = 'warning';
    } elseif ($password !== $confirmacion) {
        $mensaje = 'Las contraseñas no coinciden.';
        $tipo_mensaje = 'warning';
    } else {
        try {
            $db->begin_transaction();

            $tokenBloqueado = buscarTokenValido($db, $token_hash, true);

            if (!$tokenBloqueado) {
                throw new RuntimeException('El enlace dejó de estar disponible.');
            }

            $usuario_id = (int) $tokenBloqueado['usuario_id'];
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            if ($password_hash === false) {
                throw new RuntimeException('No fue posible proteger la nueva contraseña.');
            }

            $stmtUsuario = $db->prepare(
                "UPDATE usuarios
                 SET password = ?,
                     password_change_required = 0,
                     ultimo_cambio_password = NOW()
                 WHERE id = ?
                   AND estado = 'activo'"
            );

            if (!$stmtUsuario) {
                throw new RuntimeException('No fue posible actualizar la contraseña.');
            }

            $stmtUsuario->bind_param('si', $password_hash, $usuario_id);
            $stmtUsuario->execute();

            if ($stmtUsuario->affected_rows !== 1) {
                $stmtUsuario->close();
                throw new RuntimeException('La cuenta no está disponible.');
            }

            $stmtUsuario->close();

            $stmtTokens = $db->prepare(
                "UPDATE password_reset_tokens
                 SET used_at = NOW()
                 WHERE usuario_id = ?
                   AND used_at IS NULL"
            );

            if (!$stmtTokens) {
                throw new RuntimeException('No fue posible cerrar los enlaces de recuperación.');
            }

            $stmtTokens->bind_param('i', $usuario_id);
            $stmtTokens->execute();
            $stmtTokens->close();

            $db->commit();

            $_SESSION['restablecer_password_csrf'] = bin2hex(random_bytes(32));
            $restablecido = true;
            $token_data = null;
            $mensaje = 'Tu contraseña fue actualizada correctamente. Ya puedes iniciar sesión.';
            $tipo_mensaje = 'success';
        } catch (Throwable $e) {
            try {
                $db->rollback();
            } catch (Throwable $ignored) {
            }

            error_log('Restablecer contraseña: ' . $e->getMessage());
            $mensaje = 'No fue posible actualizar la contraseña. Solicita un enlace nuevo e inténtalo nuevamente.';
            $tipo_mensaje = 'error';
        }
    }
}

$enlace_disponible = $token_formato_valido && $token_data !== null && !$restablecido;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1e3a8a">
    <title>Nueva contraseña - <?php echo eReset($nombre_gimnasio); ?></title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        :root {
            --azul: #1e3a8a;
            --azul-claro: #3154a5;
            --azul-oscuro: #152c6b;
            --fondo: #eef2f8;
            --blanco: #ffffff;
            --texto: #1f2937;
            --suave: #64748b;
            --borde: #dbe3ef;
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
            padding: clamp(16px, 4vw, 44px);
            color: var(--texto);
            background:
                radial-gradient(circle at top left, rgba(30, 58, 138, .10), transparent 34rem),
                var(--fondo);
            font-family: "Segoe UI", Roboto, Arial, sans-serif;
        }

        button, input, a { font: inherit; }

        .auth-shell {
            display: grid;
            grid-template-columns: minmax(310px, .84fr) minmax(430px, 1.16fr);
            width: min(1040px, 100%);
            min-height: 590px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,.7);
            border-radius: 28px;
            background: var(--blanco);
            box-shadow: 0 24px 70px rgba(15,23,42,.16);
        }

        .brand-panel {
            position: relative;
            display: grid;
            place-items: center;
            min-width: 0;
            padding: 42px 32px 76px;
            overflow: hidden;
            color: #fff;
            background:
                radial-gradient(circle at 95% 4%, rgba(255,255,255,.10) 0 112px, transparent 113px),
                radial-gradient(circle at 0 100%, rgba(255,255,255,.08) 0 130px, transparent 131px),
                linear-gradient(145deg, #173374, #3154a5);
        }

        .brand-content { width:100%; text-align:center; }

        .brand-logo {
            display:grid;
            width:min(190px,68%);
            min-height:125px;
            margin:0 auto 22px;
            place-items:center;
        }

        .brand-logo img {
            display:block;
            max-width:100%;
            max-height:150px;
            object-fit:contain;
            filter:drop-shadow(0 10px 22px rgba(0,0,0,.18));
        }

        .brand-logo i { font-size:4rem; color:#dbeafe; }

        .brand-content h1 {
            margin:0;
            overflow-wrap:anywhere;
            font-size:clamp(1.6rem,3vw,2.15rem);
            line-height:1.15;
        }

        .secure-note {
            position:absolute;
            right:22px;
            bottom:22px;
            left:22px;
            display:flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            color:#dbeafe;
            font-size:.78rem;
            text-align:center;
        }

        .form-panel {
            display:flex;
            align-items:center;
            padding:clamp(34px,6vw,68px);
        }

        .form-content {
            width:min(440px,100%);
            margin:0 auto;
        }

        .form-icon {
            display:grid;
            width:52px;
            height:52px;
            margin-bottom:20px;
            place-items:center;
            border-radius:15px;
            color:var(--azul);
            background:#eef4ff;
            font-size:1.2rem;
        }

        .form-header { margin-bottom:24px; }

        .form-header h2 {
            margin:0 0 8px;
            color:var(--azul-oscuro);
            font-size:clamp(1.7rem,4vw,2.2rem);
            line-height:1.15;
            letter-spacing:-.035em;
        }

        .form-header p {
            margin:0;
            color:var(--suave);
            font-size:.9rem;
            line-height:1.55;
        }

        .alert {
            display:flex;
            align-items:flex-start;
            gap:10px;
            margin-bottom:19px;
            padding:13px 14px;
            border:1px solid;
            border-radius:12px;
            font-size:.81rem;
            line-height:1.5;
        }

        .alert i { margin-top:2px; }
        .alert-success { color:#065f46; border-color:#a7f3d0; background:#ecfdf5; }
        .alert-error { color:#991b1b; border-color:#fecaca; background:#fef2f2; }
        .alert-warning { color:#92400e; border-color:#fde68a; background:#fffbeb; }

        .field { margin-bottom:16px; }

        .field label {
            display:block;
            margin-bottom:8px;
            color:var(--texto);
            font-size:.8rem;
            font-weight:750;
        }

        .input-wrap { position:relative; }

        .input-wrap > i {
            position:absolute;
            top:50%;
            left:15px;
            color:#94a3b8;
            transform:translateY(-50%);
            pointer-events:none;
        }

        .input-wrap input {
            width:100%;
            min-height:50px;
            padding:0 47px 0 45px;
            border:1px solid var(--borde);
            border-radius:12px;
            outline:0;
            color:var(--texto);
            background:#f8fafc;
            font-size:.88rem;
            transition:border-color .18s ease,box-shadow .18s ease,background .18s ease;
        }

        .input-wrap input:focus {
            border-color:#7aa2e8;
            background:#fff;
            box-shadow:0 0 0 4px rgba(30,58,138,.10);
        }

        .toggle-password {
            position:absolute;
            top:50%;
            right:12px;
            display:grid;
            width:34px;
            height:34px;
            place-items:center;
            border:0;
            border-radius:8px;
            color:#64748b;
            background:transparent;
            cursor:pointer;
            transform:translateY(-50%);
        }

        .toggle-password:hover { color:var(--azul); background:#edf2fa; }

        .password-help {
            margin:-7px 0 17px;
            color:var(--suave);
            font-size:.72rem;
        }

        .submit-button {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:9px;
            width:100%;
            min-height:49px;
            border:0;
            border-radius:12px;
            color:#fff;
            background:var(--azul);
            cursor:pointer;
            font-size:.88rem;
            font-weight:800;
            box-shadow:0 9px 20px rgba(30,58,138,.20);
            transition:background .18s ease,transform .18s ease;
        }

        .submit-button:hover { background:var(--azul-claro); transform:translateY(-1px); }
        .submit-button:disabled { cursor:wait; opacity:.72; transform:none; }

        .back-link {
            display:inline-flex;
            align-items:center;
            gap:7px;
            margin-top:20px;
            color:var(--azul);
            text-decoration:none;
            font-size:.81rem;
            font-weight:750;
        }

        .back-link:hover { text-decoration:underline; }

        .invalid-link {
            padding:20px;
            border:1px solid #fecaca;
            border-radius:14px;
            background:#fef2f2;
            color:#991b1b;
            text-align:center;
        }

        .invalid-link i {
            display:block;
            margin-bottom:12px;
            font-size:2rem;
        }

        .invalid-link strong {
            display:block;
            margin-bottom:7px;
            font-size:.95rem;
        }

        .invalid-link p {
            margin:0;
            font-size:.8rem;
            line-height:1.5;
        }

        @media (max-width:820px) {
            body { padding:16px; }

            .auth-shell {
                grid-template-columns:1fr;
                width:min(560px,100%);
                min-height:auto;
            }

            .brand-panel {
                min-height:235px;
                padding:28px 24px 56px;
            }

            .brand-logo {
                width:min(145px,48%);
                min-height:88px;
                margin-bottom:12px;
            }

            .brand-logo img { max-height:105px; }
            .brand-content h1 { font-size:1.45rem; }
            .secure-note { bottom:16px; }
            .form-panel { padding:34px 25px 38px; }
        }

        @media (max-width:420px) {
            body { padding:10px; align-items:start; }
            .auth-shell { border-radius:20px; }

            .brand-panel {
                min-height:190px;
                padding:22px 20px 49px;
            }

            .brand-logo {
                min-height:72px;
                margin-bottom:8px;
            }

            .brand-logo img { max-height:84px; }
            .brand-content h1 { font-size:1.25rem; }

            .secure-note {
                right:12px;
                bottom:13px;
                left:12px;
                font-size:.68rem;
            }

            .form-panel { padding:27px 20px 30px; }
        }

        @media (prefers-reduced-motion:reduce) {
            .input-wrap input,.submit-button { transition:none; }
        }
    </style>
</head>
<body>
    <main class="auth-shell">
        <section class="brand-panel" aria-label="Identidad del gimnasio">
            <div class="brand-content">
                <div class="brand-logo">
                    <?php if ($logo_gimnasio !== ''): ?>
                        <img
                            src="<?php echo eReset($logo_gimnasio); ?>"
                            alt="Logo de <?php echo eReset($nombre_gimnasio); ?>"
                        >
                    <?php else: ?>
                        <i class="fas fa-dumbbell" aria-hidden="true"></i>
                    <?php endif; ?>
                </div>

                <h1><?php echo eReset($nombre_gimnasio); ?></h1>
            </div>

            <div class="secure-note">
                <i class="fas fa-shield-halved" aria-hidden="true"></i>
                Enlace temporal de un solo uso
            </div>
        </section>

        <section class="form-panel">
            <div class="form-content">
                <div class="form-icon">
                    <i class="fas fa-lock" aria-hidden="true"></i>
                </div>

                <header class="form-header">
                    <h2>Nueva contraseña</h2>
                    <p>Crea una contraseña nueva para recuperar el acceso a tu cuenta.</p>
                </header>

                <?php if ($mensaje !== ''): ?>
                    <div class="alert alert-<?php echo eReset($tipo_mensaje); ?>" role="alert">
                        <i class="fas <?php
                            echo $tipo_mensaje === 'success'
                                ? 'fa-circle-check'
                                : ($tipo_mensaje === 'warning'
                                    ? 'fa-triangle-exclamation'
                                    : 'fa-circle-xmark');
                        ?>" aria-hidden="true"></i>
                        <span><?php echo eReset($mensaje); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($enlace_disponible): ?>
                    <form method="POST" id="resetForm" novalidate>
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php echo eReset((string) $_SESSION['restablecer_password_csrf']); ?>"
                        >
                        <input type="hidden" name="token" value="<?php echo eReset($token); ?>">

                        <div class="field">
                            <label for="password">Nueva contraseña</label>
                            <div class="input-wrap">
                                <i class="fas fa-lock" aria-hidden="true"></i>
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    minlength="8"
                                    maxlength="255"
                                    autocomplete="new-password"
                                    placeholder="Mínimo 8 caracteres"
                                    required
                                >
                                <button
                                    type="button"
                                    class="toggle-password"
                                    data-target="password"
                                    aria-label="Mostrar contraseña"
                                >
                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <div class="field">
                            <label for="confirm_password">Confirmar contraseña</label>
                            <div class="input-wrap">
                                <i class="fas fa-shield-halved" aria-hidden="true"></i>
                                <input
                                    type="password"
                                    id="confirm_password"
                                    name="confirm_password"
                                    minlength="8"
                                    maxlength="255"
                                    autocomplete="new-password"
                                    placeholder="Repite la contraseña"
                                    required
                                >
                                <button
                                    type="button"
                                    class="toggle-password"
                                    data-target="confirm_password"
                                    aria-label="Mostrar contraseña"
                                >
                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <p class="password-help">
                            Usa al menos 8 caracteres y evita reutilizar contraseñas de otros servicios.
                        </p>

                        <button type="submit" class="submit-button" id="submitButton">
                            <i class="fas fa-check" aria-hidden="true"></i>
                            <span>Guardar nueva contraseña</span>
                        </button>
                    </form>
                <?php elseif (!$restablecido): ?>
                    <div class="invalid-link">
                        <i class="fas fa-link-slash" aria-hidden="true"></i>
                        <strong>Enlace no disponible</strong>
                        <p>
                            Este enlace venció, ya fue utilizado o no es válido.
                            Solicita uno nuevo para continuar.
                        </p>
                    </div>
                <?php endif; ?>

                <a
                    href="<?php echo $restablecido ? 'login.php' : 'recuperar-password.php'; ?>"
                    class="back-link"
                >
                    <i class="fas fa-arrow-left" aria-hidden="true"></i>
                    <?php echo $restablecido
                        ? 'Ir al inicio de sesión'
                        : 'Solicitar un enlace nuevo'; ?>
                </a>
            </div>
        </section>
    </main>

    <script>
    document.querySelectorAll('.toggle-password').forEach(function (button) {
        button.addEventListener('click', function () {
            const input = document.getElementById(button.dataset.target);
            const icon = button.querySelector('i');
            const showing = input.type === 'text';

            input.type = showing ? 'password' : 'text';
            icon.classList.toggle('fa-eye', showing);
            icon.classList.toggle('fa-eye-slash', !showing);
            button.setAttribute(
                'aria-label',
                showing ? 'Mostrar contraseña' : 'Ocultar contraseña'
            );
        });
    });

    const resetForm = document.getElementById('resetForm');

    if (resetForm) {
        resetForm.addEventListener('submit', function (event) {
            const password = document.getElementById('password');
            const confirmPassword = document.getElementById('confirm_password');
            const submitButton = document.getElementById('submitButton');

            if (!password.checkValidity() || !confirmPassword.checkValidity()) {
                event.preventDefault();
                resetForm.reportValidity();
                return;
            }

            if (password.value !== confirmPassword.value) {
                event.preventDefault();
                confirmPassword.setCustomValidity('Las contraseñas no coinciden.');
                confirmPassword.reportValidity();
                return;
            }

            confirmPassword.setCustomValidity('');
            submitButton.disabled = true;
            submitButton.querySelector('span').textContent = 'Guardando contraseña...';
        });

        document.getElementById('confirm_password').addEventListener('input', function () {
            this.setCustomValidity('');
        });
    }
    </script>
</body>
</html>
