<?php
session_start();
require_once 'config/database.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$error = '';
$success = false;

$nombre_value = '';
$email_value = '';
$rol_value = '';

$roles_permitidos = [
    'recepcionista' => 'Recepcionista',
    'entrenador' => 'Entrenador',
];

function getGymLogo($conn): string
{
    $query = 'SELECT logo FROM configuracion_gimnasio WHERE id = 1';
    $result = $conn->query($query);

    if ($result && ($row = $result->fetch_assoc())) {
        $logo = trim((string) ($row['logo'] ?? ''));

        if ($logo !== '' && file_exists($logo)) {
            return $logo . '?v=' . filemtime($logo);
        }
    }

    foreach (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico'] as $extension) {
        $ruta = 'img/logo-gym.' . $extension;

        if (file_exists($ruta)) {
            return $ruta . '?v=' . filemtime($ruta);
        }
    }

    return '';
}

function getGymName($conn): string
{
    $query = 'SELECT nombre FROM configuracion_gimnasio WHERE id = 1';
    $result = $conn->query($query);

    if ($result && ($row = $result->fetch_assoc())) {
        $nombre = trim((string) ($row['nombre'] ?? ''));

        if ($nombre !== '') {
            return $nombre;
        }
    }

    return 'Gym System';
}

$gym_logo = getGymLogo($db);
$gym_name = getGymName($db);
$gym_name_safe = htmlspecialchars($gym_name, ENT_QUOTES, 'UTF-8');

if (empty($_SESSION['registro_csrf'])) {
    $_SESSION['registro_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirm_password = (string) ($_POST['confirm_password'] ?? '');
    $rol = strtolower(trim((string) ($_POST['rol'] ?? '')));
    $csrf = (string) ($_POST['csrf_token'] ?? '');

    $nombre_value = $nombre;
    $email_value = $email;
    $rol_value = $rol;

    if (!hash_equals($_SESSION['registro_csrf'], $csrf)) {
        $error = 'solicitud_invalida';
    } elseif ($nombre === '' || $email === '' || $password === '' || $confirm_password === '' || $rol === '') {
        $error = 'campos_vacios';
    } elseif (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 100) {
        $error = 'nombre_invalido';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'email_invalido';
    } elseif (!array_key_exists($rol, $roles_permitidos)) {
        // La validación del servidor impide enviar manualmente otros roles.
        $error = 'rol_invalido';
    } elseif (strlen($password) < 6) {
        $error = 'password_corta';
    } elseif ($password !== $confirm_password) {
        $error = 'password_no_coincide';
    } else {
        $check = $db->prepare('SELECT id FROM usuarios WHERE email = ? LIMIT 1');

        if (!$check) {
            $error = 'error_sistema';
        } else {
            $check->bind_param('s', $email);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $error = 'email_existente';
            }

            $check->close();
        }

        if ($error === '') {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $query = "INSERT INTO usuarios
                        (nombre, email, password, rol, password_change_required, estado)
                      VALUES (?, ?, ?, ?, 0, 'pendiente')";
            $stmt = $db->prepare($query);

            if (!$stmt) {
                $error = 'error_sistema';
            } else {
                $stmt->bind_param('ssss', $nombre, $email, $hashed_password, $rol);

                if ($stmt->execute()) {
                    $success = true;
                    $nombre_value = '';
                    $email_value = '';
                    $rol_value = '';

                    // Renovar el token después de registrar correctamente.
                    $_SESSION['registro_csrf'] = bin2hex(random_bytes(32));
                } elseif ((int) $stmt->errno === 1062) {
                    $error = 'email_existente';
                } else {
                    $error = 'error_sistema';
                }

                $stmt->close();
            }
        }
    }
}

$errores = [
    'solicitud_invalida' => [
        'title' => 'Solicitud no válida',
        'message' => 'Actualiza la página e intenta registrar la cuenta nuevamente.',
        'icon' => 'error',
    ],
    'campos_vacios' => [
        'title' => 'Campos incompletos',
        'message' => 'Completa todos los campos del formulario.',
        'icon' => 'warning',
    ],
    'nombre_invalido' => [
        'title' => 'Nombre no válido',
        'message' => 'Ingresa un nombre de entre 3 y 100 caracteres.',
        'icon' => 'warning',
    ],
    'email_invalido' => [
        'title' => 'Correo no válido',
        'message' => 'Revisa el formato del correo electrónico.',
        'icon' => 'warning',
    ],
    'email_existente' => [
        'title' => 'Correo registrado',
        'message' => 'Ya existe una cuenta asociada con ese correo electrónico.',
        'icon' => 'info',
    ],
    'rol_invalido' => [
        'title' => 'Rol no permitido',
        'message' => 'Solo se permite registrar recepcionistas o entrenadores.',
        'icon' => 'error',
    ],
    'password_corta' => [
        'title' => 'Contraseña muy corta',
        'message' => 'La contraseña debe contener al menos 6 caracteres.',
        'icon' => 'warning',
    ],
    'password_no_coincide' => [
        'title' => 'Las contraseñas no coinciden',
        'message' => 'Escribe la misma contraseña en ambos campos.',
        'icon' => 'warning',
    ],
    'error_sistema' => [
        'title' => 'No fue posible crear la cuenta',
        'message' => 'Ocurrió un problema al guardar la información. Inténtalo nuevamente.',
        'icon' => 'error',
    ],
];

$alerta = $errores[$error] ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1e3a8a">
    <title><?php echo $gym_name_safe; ?> | Crear cuenta</title>

    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="icon" href="favicon.php">

    <style>
        :root {
            --azul: #1e3a8a;
            --azul-oscuro: #152c6b;
            --azul-claro: #eef3ff;
            --fondo: #f3f5f9;
            --blanco: #ffffff;
            --texto: #1f2937;
            --texto-suave: #64748b;
            --borde: #dfe5ee;
            --radio-grande: 28px;
            --radio: 14px;
            --sombra: 0 24px 70px rgba(30, 58, 138, 0.14);
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        html {
            width: 100%;
            min-height: 100%;
            background: var(--fondo);
            -webkit-text-size-adjust: 100%;
        }

        body {
            width: 100%;
            min-height: 100vh;
            min-height: 100dvh;
            margin: 0;
            padding: clamp(14px, 2.4vw, 30px);
            overflow-x: hidden;
            display: grid;
            place-items: center;
            color: var(--texto);
            font-family: "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background:
                radial-gradient(circle at 8% 12%, rgba(30, 58, 138, 0.08), transparent 28rem),
                radial-gradient(circle at 92% 88%, rgba(30, 58, 138, 0.05), transparent 24rem),
                var(--fondo);
        }

        button,
        input,
        select {
            font: inherit;
        }

        a {
            color: inherit;
        }

        .page-shell {
            position: relative;
            width: min(1000px, 100%);
            min-height: min(720px, calc(100dvh - clamp(28px, 5vw, 60px)));
            display: grid;
            grid-template-columns: minmax(340px, 0.82fr) minmax(520px, 1.18fr);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.9);
            border-radius: var(--radio-grande);
            background: var(--blanco);
            box-shadow: var(--sombra);
        }

        .brand-panel {
            position: relative;
            isolation: isolate;
            min-width: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: clamp(40px, 5vw, 66px);
            overflow: hidden;
            color: var(--blanco);
            text-align: center;
            background: linear-gradient(145deg, #162d6f 0%, var(--azul) 56%, #3155ad 100%);
        }

        .brand-panel::before,
        .brand-panel::after {
            content: "";
            position: absolute;
            z-index: -1;
            border-radius: 50%;
            pointer-events: none;
        }

        .brand-panel::before {
            width: 420px;
            height: 420px;
            top: -235px;
            right: -205px;
            border: 72px solid rgba(255, 255, 255, 0.055);
        }

        .brand-panel::after {
            width: 330px;
            height: 330px;
            bottom: -205px;
            left: -175px;
            border: 54px solid rgba(255, 255, 255, 0.04);
        }

        .brand-hero {
            position: relative;
            z-index: 1;
            width: min(100%, 340px);
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .brand-logo-box {
            width: clamp(185px, 17vw, 225px);
            aspect-ratio: 1 / 1;
            display: grid;
            place-items: center;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: 38px;
            background: #ffffff;
            box-shadow:
                0 26px 60px rgba(4, 15, 48, 0.3),
                0 0 0 8px rgba(255, 255, 255, 0.075);
        }

        .brand-logo-box img {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: contain;
        }

        .logo-fallback {
            width: 100%;
            height: 100%;
            display: grid;
            place-items: center;
            color: var(--azul);
            font-size: 58px;
        }

        .brand-name {
            max-width: 100%;
            margin: 24px 0 0;
            overflow-wrap: anywhere;
            color: #ffffff;
            font-size: clamp(27px, 3vw, 36px);
            font-weight: 800;
            line-height: 1.12;
            letter-spacing: -0.03em;
            text-shadow: 0 5px 18px rgba(5, 17, 52, 0.18);
        }

        .brand-access {
            position: absolute;
            z-index: 2;
            left: 50%;
            bottom: clamp(26px, 3.2vw, 38px);
            width: calc(100% - 48px);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            color: rgba(255, 255, 255, 0.76);
            font-size: 12px;
            font-weight: 500;
            line-height: 1.35;
            text-align: center;
            transform: translateX(-50%);
        }

        .brand-access i {
            flex: 0 0 auto;
            color: #b9caef;
            font-size: 14px;
        }

        .auth-panel {
            min-width: 0;
            display: grid;
            place-items: center;
            padding: clamp(32px, 4vw, 54px);
            background: var(--blanco);
        }

        .register-card {
            width: min(470px, 100%);
            min-width: 0;
        }

        .register-header {
            margin-bottom: 24px;
        }

        .register-header h1 {
            margin: 0 0 8px;
            color: var(--azul-oscuro);
            font-size: clamp(27px, 3vw, 34px);
            font-weight: 780;
            line-height: 1.1;
            letter-spacing: -0.035em;
        }

        .register-header p {
            margin: 0;
            color: var(--texto-suave);
            font-size: 14px;
            line-height: 1.55;
        }


        .approval-note {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin: -4px 0 22px;
            padding: 13px 14px;
            border: 1px solid #d8e2f5;
            border-radius: 13px;
            background: #f5f8ff;
            color: var(--texto);
        }

        .approval-note > i {
            flex: 0 0 auto;
            margin-top: 2px;
            color: var(--azul);
            font-size: 16px;
        }

        .approval-note div {
            min-width: 0;
            display: grid;
            gap: 3px;
        }

        .approval-note strong {
            color: var(--azul-oscuro);
            font-size: 13px;
        }

        .approval-note span {
            color: var(--texto-suave);
            font-size: 12px;
            line-height: 1.45;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 17px 15px;
        }

        .form-group {
            min-width: 0;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            margin-bottom: 7px;
            color: #334155;
            font-size: 13px;
            font-weight: 700;
        }

        .input-wrapper {
            position: relative;
            min-width: 0;
        }

        .input-icon {
            position: absolute;
            z-index: 2;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 15px;
            pointer-events: none;
            transition: color 0.2s ease;
        }

        .form-control {
            width: 100%;
            min-width: 0;
            min-height: 51px;
            padding: 12px 45px;
            border: 1px solid var(--borde);
            border-radius: var(--radio);
            outline: none;
            color: var(--texto);
            background: #f8fafc;
            font-size: 15px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        select.form-control {
            appearance: none;
            cursor: pointer;
            padding-right: 46px;
        }

        .select-arrow {
            position: absolute;
            z-index: 2;
            right: 17px;
            top: 50%;
            color: #64748b;
            font-size: 13px;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .form-control::placeholder {
            color: #a0aec0;
        }

        .form-control:hover {
            border-color: #c4cfdd;
        }

        .form-control:focus {
            border-color: var(--azul);
            background: var(--blanco);
            box-shadow: 0 0 0 4px rgba(30, 58, 138, 0.1);
        }

        .input-wrapper:focus-within .input-icon,
        .input-wrapper:focus-within .select-arrow {
            color: var(--azul);
        }

        .toggle-password {
            position: absolute;
            z-index: 3;
            right: 7px;
            top: 50%;
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            padding: 0;
            border: 0;
            border-radius: 10px;
            color: #64748b;
            background: transparent;
            cursor: pointer;
            transform: translateY(-50%);
            transition: color 0.2s ease, background 0.2s ease;
        }

        .toggle-password:hover {
            color: var(--azul);
            background: var(--azul-claro);
        }

        .password-help {
            margin: 7px 2px 0;
            color: #94a3b8;
            font-size: 11px;
            line-height: 1.4;
        }

        .btn-register {
            position: relative;
            width: 100%;
            min-height: 52px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 22px;
            padding: 13px 18px;
            border: 0;
            border-radius: var(--radio);
            color: var(--blanco);
            background: var(--azul);
            box-shadow: 0 12px 25px rgba(30, 58, 138, 0.2);
            font-size: 15px;
            font-weight: 750;
            cursor: pointer;
            transition: transform 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
        }

        .btn-register:hover:not(:disabled) {
            transform: translateY(-1px);
            background: var(--azul-oscuro);
            box-shadow: 0 15px 30px rgba(30, 58, 138, 0.25);
        }

        .btn-register:disabled {
            cursor: wait;
            opacity: 0.84;
        }

        .btn-spinner {
            display: none;
            width: 17px;
            height: 17px;
            border: 2px solid rgba(255, 255, 255, 0.45);
            border-top-color: var(--blanco);
            border-radius: 50%;
            animation: spin 0.75s linear infinite;
        }

        .btn-register.is-loading .btn-spinner {
            display: inline-block;
        }

        .login-link {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e8edf3;
            color: var(--texto-suave);
            font-size: 13px;
            line-height: 1.55;
            text-align: center;
        }

        .login-link a {
            color: var(--azul);
            font-weight: 750;
            text-decoration: none;
        }

        .login-link a:hover {
            color: var(--azul-oscuro);
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        .register-footer {
            margin-top: 19px;
            color: #94a3b8;
            font-size: 11px;
            line-height: 1.5;
            text-align: center;
        }

        .toggle-password:focus-visible,
        .btn-register:focus-visible,
        .login-link a:focus-visible {
            outline: 3px solid rgba(30, 58, 138, 0.22);
            outline-offset: 3px;
        }

        .swal2-popup.register-alert {
            width: min(450px, calc(100vw - 28px));
            border-radius: 20px;
            padding: 1.5rem;
        }


        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 900px) {
            body {
                padding: 18px;
            }

            .page-shell {
                width: min(620px, 100%);
                min-height: auto;
                grid-template-columns: 1fr;
                border-radius: 26px;
            }

            .brand-panel {
                min-height: 220px;
                padding: 27px 28px 24px;
            }

            .brand-panel::before {
                width: 260px;
                height: 260px;
                top: -155px;
                right: -130px;
                border-width: 44px;
            }

            .brand-panel::after {
                width: 220px;
                height: 220px;
                bottom: -150px;
                left: -125px;
                border-width: 38px;
            }

            .brand-logo-box {
                width: 130px;
                height: 130px;
                aspect-ratio: auto;
                border-radius: 28px;
                box-shadow:
                    0 18px 38px rgba(4, 15, 48, 0.25),
                    0 0 0 6px rgba(255, 255, 255, 0.065);
            }

            .brand-name {
                margin-top: 13px;
                font-size: 22px;
            }

            .brand-access {
                position: static;
                width: auto;
                margin-top: 11px;
                font-size: 10px;
                transform: none;
            }

            .auth-panel {
                display: block;
                padding: clamp(30px, 7vw, 46px);
            }

            .register-card {
                width: 100%;
            }

            .register-header {
                text-align: center;
            }
        }

        @media (max-width: 560px) {
            body {
                place-items: start center;
                padding: 12px;
                background:
                    linear-gradient(180deg, rgba(30, 58, 138, 0.08), transparent 230px),
                    var(--fondo);
            }

            .page-shell {
                width: 100%;
                min-height: calc(100dvh - 24px);
                border: 1px solid rgba(30, 58, 138, 0.08);
                border-radius: 23px;
                box-shadow: 0 18px 45px rgba(30, 58, 138, 0.12);
            }

            .brand-panel {
                min-height: 182px;
                padding: 20px 20px 18px;
            }

            .brand-logo-box {
                width: 108px;
                height: 108px;
                border-radius: 24px;
            }

            .brand-name {
                margin-top: 9px;
                font-size: 19px;
            }

            .brand-access {
                margin-top: 9px;
                font-size: 9px;
            }

            .auth-panel {
                min-height: 0;
                padding: 27px 20px 22px;
            }

            .register-header {
                margin-bottom: 23px;
            }

            .register-header h1 {
                font-size: 27px;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .form-group.full {
                grid-column: auto;
            }

            .form-control {
                min-height: 50px;
                font-size: 16px;
            }

            .btn-register {
                min-height: 50px;
            }
        }

        @media (max-width: 380px) {
            body {
                padding: 8px;
            }

            .page-shell {
                min-height: calc(100dvh - 16px);
                border-radius: 20px;
            }

            .brand-panel {
                min-height: 166px;
                padding: 17px 16px 15px;
            }

            .brand-logo-box {
                width: 98px;
                height: 98px;
                border-radius: 22px;
            }

            .brand-name {
                font-size: 18px;
            }

            .auth-panel {
                padding: 24px 16px 18px;
            }
        }

        @media (max-height: 760px) and (min-width: 901px) {
            body {
                align-items: start;
            }

            .page-shell {
                min-height: 680px;
            }

            .brand-panel,
            .auth-panel {
                padding-top: 28px;
                padding-bottom: 28px;
            }

            .brand-logo-box {
                width: 180px;
                height: 180px;
                aspect-ratio: auto;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>
    <div class="page-shell">
        <aside class="brand-panel" aria-label="Identidad del gimnasio">
            <div class="brand-hero">
                <div class="brand-logo-box">
                    <?php if ($gym_logo !== ''): ?>
                        <img
                            src="<?php echo htmlspecialchars($gym_logo, ENT_QUOTES, 'UTF-8'); ?>"
                            alt="Logo de <?php echo $gym_name_safe; ?>"
                            onerror="this.hidden=true; this.nextElementSibling.hidden=false;"
                        >
                    <?php endif; ?>

                    <span class="logo-fallback" <?php echo $gym_logo !== '' ? 'hidden' : ''; ?> aria-hidden="true">
                        <i class="fa-solid fa-dumbbell"></i>
                    </span>
                </div>

                <p class="brand-name"><?php echo $gym_name_safe; ?></p>
            </div>

            <div class="brand-access" aria-label="Registro restringido">
                <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                <span>El acceso requiere aprobación del administrador</span>
            </div>
        </aside>

        <main class="auth-panel">
            <section class="register-card" aria-labelledby="register-title">
                <header class="register-header">
                    <h1 id="register-title">Crear cuenta</h1>
                </header>

                <div class="approval-note" role="note">
                    <i class="fa-solid fa-clock" aria-hidden="true"></i>
                    <div>
                        <strong>Aprobación requerida</strong>
                        <span>Después de registrarte, un administrador deberá autorizar tu cuenta antes de que puedas iniciar sesión.</span>
                    </div>
                </div>

                <form method="POST" action="" id="registerForm" novalidate>
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION['registro_csrf'], ENT_QUOTES, 'UTF-8'); ?>"
                    >

                    <div class="form-grid">
                        <div class="form-group full">
                            <label class="form-label" for="nombre">Nombre completo</label>
                            <div class="input-wrapper">
                                <i class="fa-regular fa-user input-icon" aria-hidden="true"></i>
                                <input
                                    class="form-control"
                                    type="text"
                                    id="nombre"
                                    name="nombre"
                                    placeholder="Nombre del colaborador"
                                    value="<?php echo htmlspecialchars($nombre_value, ENT_QUOTES, 'UTF-8'); ?>"
                                    autocomplete="name"
                                    maxlength="100"
                                    required
                                >
                            </div>
                        </div>

                        <div class="form-group full">
                            <label class="form-label" for="email">Correo electrónico</label>
                            <div class="input-wrapper">
                                <i class="fa-regular fa-envelope input-icon" aria-hidden="true"></i>
                                <input
                                    class="form-control"
                                    type="email"
                                    id="email"
                                    name="email"
                                    placeholder="ejemplo@correo.com"
                                    value="<?php echo htmlspecialchars($email_value, ENT_QUOTES, 'UTF-8'); ?>"
                                    autocomplete="email"
                                    inputmode="email"
                                    autocapitalize="none"
                                    spellcheck="false"
                                    maxlength="190"
                                    required
                                >
                            </div>
                        </div>

                        <div class="form-group full">
                            <label class="form-label" for="rol">Rol de acceso</label>
                            <div class="input-wrapper">
                                <i class="fa-solid fa-id-badge input-icon" aria-hidden="true"></i>
                                <select class="form-control" id="rol" name="rol" required>
                                    <option value="" disabled <?php echo $rol_value === '' ? 'selected' : ''; ?>>Selecciona un rol</option>

                                    <?php foreach ($roles_permitidos as $valor_rol => $etiqueta_rol): ?>
                                        <option
                                            value="<?php echo htmlspecialchars($valor_rol, ENT_QUOTES, 'UTF-8'); ?>"
                                            <?php echo $rol_value === $valor_rol ? 'selected' : ''; ?>
                                        >
                                            <?php echo htmlspecialchars($etiqueta_rol, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="fa-solid fa-chevron-down select-arrow" aria-hidden="true"></i>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="password">Contraseña</label>
                            <div class="input-wrapper">
                                <i class="fa-solid fa-lock input-icon" aria-hidden="true"></i>
                                <input
                                    class="form-control"
                                    type="password"
                                    id="password"
                                    name="password"
                                    placeholder="Mínimo 6 caracteres"
                                    autocomplete="new-password"
                                    minlength="6"
                                    required
                                >
                                <button
                                    type="button"
                                    class="toggle-password"
                                    data-target="password"
                                    aria-label="Mostrar contraseña"
                                    aria-pressed="false"
                                >
                                    <i class="fa-regular fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="confirm_password">Confirmar contraseña</label>
                            <div class="input-wrapper">
                                <i class="fa-solid fa-shield-keyhole input-icon" aria-hidden="true"></i>
                                <input
                                    class="form-control"
                                    type="password"
                                    id="confirm_password"
                                    name="confirm_password"
                                    placeholder="Repite la contraseña"
                                    autocomplete="new-password"
                                    minlength="6"
                                    required
                                >
                                <button
                                    type="button"
                                    class="toggle-password"
                                    data-target="confirm_password"
                                    aria-label="Mostrar confirmación de contraseña"
                                    aria-pressed="false"
                                >
                                    <i class="fa-regular fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <p class="password-help">La contraseña se guardará cifrada y debe contener al menos 6 caracteres.</p>

                    <button type="submit" class="btn-register" id="submitButton">
                        <span class="btn-spinner" aria-hidden="true"></span>
                        <span class="button-text">Enviar solicitud</span>
                        <i class="fa-solid fa-user-plus button-icon" aria-hidden="true"></i>
                    </button>
                </form>

                <div class="login-link">
                    ¿Ya tienes una cuenta?
                    <a href="login.php">Inicia sesión</a>
                </div>

                <footer class="register-footer">
                    &copy; <?php echo date('Y'); ?> <?php echo $gym_name_safe; ?>. Todos los derechos reservados.
                </footer>
            </section>
        </main>
    </div>

    <script>
        const registerForm = document.getElementById('registerForm');
        const submitButton = document.getElementById('submitButton');

        document.querySelectorAll('.toggle-password').forEach((button) => {
            button.addEventListener('click', () => {
                const targetId = button.dataset.target;
                const input = document.getElementById(targetId);
                const icon = button.querySelector('i');
                const showing = input.type === 'text';

                input.type = showing ? 'password' : 'text';
                button.setAttribute('aria-pressed', showing ? 'false' : 'true');
                button.setAttribute(
                    'aria-label',
                    showing ? 'Mostrar contraseña' : 'Ocultar contraseña'
                );

                icon.classList.toggle('fa-eye', showing);
                icon.classList.toggle('fa-eye-slash', !showing);
            });
        });

        registerForm.addEventListener('submit', (event) => {
            const nombre = document.getElementById('nombre').value.trim();
            const email = document.getElementById('email').value.trim();
            const rol = document.getElementById('rol').value;
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (!nombre || !email || !rol || !password || !confirmPassword) {
                event.preventDefault();
                showAlert('warning', 'Campos incompletos', 'Completa todos los campos del formulario.');
                return;
            }

            if (!['recepcionista', 'entrenador'].includes(rol)) {
                event.preventDefault();
                showAlert('error', 'Rol no permitido', 'Selecciona recepcionista o entrenador.');
                return;
            }

            if (password.length < 6) {
                event.preventDefault();
                showAlert('warning', 'Contraseña muy corta', 'La contraseña debe contener al menos 6 caracteres.');
                return;
            }

            if (password !== confirmPassword) {
                event.preventDefault();
                showAlert('warning', 'Las contraseñas no coinciden', 'Escribe la misma contraseña en ambos campos.');
                return;
            }

            submitButton.disabled = true;
            submitButton.classList.add('is-loading');
            submitButton.querySelector('.button-text').textContent = 'Enviando solicitud...';
            submitButton.querySelector('.button-icon').hidden = true;
        });

        function showAlert(icon, title, text) {
            Swal.fire({
                icon,
                title,
                text,
                confirmButtonText: 'Entendido',
                confirmButtonColor: '#1e3a8a',
                customClass: {
                    popup: 'register-alert'
                }
            });
        }

        <?php if ($alerta): ?>
            showAlert(
                <?php echo json_encode($alerta['icon'], JSON_UNESCAPED_UNICODE); ?>,
                <?php echo json_encode($alerta['title'], JSON_UNESCAPED_UNICODE); ?>,
                <?php echo json_encode($alerta['message'], JSON_UNESCAPED_UNICODE); ?>
            );
        <?php endif; ?>

        <?php if ($success): ?>
            Swal.fire({
                icon: 'success',
                title: 'Solicitud enviada',
                text: 'Tu cuenta quedó pendiente de aprobación. Podrás iniciar sesión cuando un administrador la autorice.',
                confirmButtonText: 'Entendido',
                allowOutsideClick: false,
                confirmButtonColor: '#1e3a8a',
                customClass: {
                    popup: 'register-alert'
                }
            }).then(() => {
                window.location.href = 'login.php';
            });
        <?php endif; ?>
    </script>
</body>
</html>