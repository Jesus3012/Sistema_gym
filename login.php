<?php
require_once __DIR__ . '/includes/session_security.php';
secure_session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once 'config/database.php';
require_once 'includes/super_admin_helper.php';
require_once 'includes/sucursal_context.php';
require_once 'includes/two_factor_helper.php';
require_once __DIR__ . '/includes/tema_sistema.php';

$error = trim((string) ($_GET['error'] ?? ''));
$email_value = '';
$tiempo_maximo = 12 * 3600; // 12 horas

$database = new Database();
$db = $database->getConnection();

if ($db instanceof mysqli) {
    $db->set_charset('utf8mb4');
}

// Tema corporativo también disponible antes de renderizar el login.
$loginTema = tema_sistema_defaults();
if ($db instanceof mysqli) {
    $loginTema = tema_sistema_obtener($db, false);
}
$loginThemeColor = (string) ($loginTema['color_primario'] ?? '#1e3a8a');

$twoFactorReady = $db instanceof mysqli
    && two_factor_schema_ready($db);

function login_limpiar_sesion(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }

    session_destroy();
    session_start();
}

if (isset($_GET['reiniciar']) && (string) $_GET['reiniciar'] === '1') {
    login_limpiar_sesion();
}

function login_destino_segun_rol(): string
{
    $rolEfectivo = rol_normalizar_sistema((string) (
        $_SESSION['user_rol'] ?? ''
    ));

    return $rolEfectivo === 'entrenador'
        ? 'panel_entrenador.php'
        : 'dashboard.php';
}

/*
 * Una sesión existente se revalida contra la base de datos antes de
 * redirigir. Esto evita ciclos login → dashboard cuando el rol cambió.
 */
if (isset($_SESSION['user_id'], $_SESSION['login_time'])) {
    $tiempoSesion = time() - (int) $_SESSION['login_time'];
    $tiempoInactividad = isset($_SESSION['last_activity'])
        ? time() - (int) $_SESSION['last_activity']
        : $tiempo_maximo;

    if (
        $tiempoSesion >= $tiempo_maximo
        || $tiempoInactividad >= $tiempo_maximo
    ) {
        login_limpiar_sesion();
        $error = 'sesion_expirada';
    } elseif ($db instanceof mysqli) {
        try {
            if (!$twoFactorReady || !two_factor_session_is_verified($db)) {
                throw new RuntimeException(
                    'La sesión no tiene una verificación en dos pasos válida.'
                );
            }

            sucursal_inicializar_sesion($db);
            $_SESSION['last_activity'] = time();

            header('Location: ' . login_destino_segun_rol());
            exit();
        } catch (Throwable $sesionException) {
            error_log(
                '[Login revalidación] '
                . $sesionException->getMessage()
            );

            login_limpiar_sesion();
            $error = 'sesion_revalidacion';
        }
    } else {
        login_limpiar_sesion();
        $error = 'error_sistema';
    }
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $email_value = $email;

    if (!$twoFactorReady) {
        $error = '2fa_no_instalado';
    } elseif ($email === '' || $password === '') {
        $error = 'campos_vacios';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'email_invalido';
    } else {
        $query = 'SELECT id, nombre, email, password, rol, estado, password_change_required, COALESCE(auth_version, 1) AS auth_version FROM usuarios WHERE email = ? LIMIT 1';
        $stmt = $db->prepare($query);

        if (!$stmt) {
            $error = 'error_sistema';
        } else {
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows === 1) {
                $user = $result->fetch_assoc();

                $estado_usuario = strtolower(trim((string) ($user['estado'] ?? '')));

                if ($estado_usuario === 'pendiente') {
                    $error = 'usuario_pendiente';
                } elseif ($estado_usuario === 'rechazado') {
                    $error = 'usuario_rechazado';
                } elseif ($estado_usuario !== 'activo') {
                    // Por seguridad, solo las cuentas expresamente activas pueden entrar.
                    $error = 'usuario_inactivo';
                } elseif (password_verify($password, (string) $user['password'])) {
                    try {
                        $config2fa = two_factor_get_config($db);
                        $usuario2fa = two_factor_get_user(
                            $db,
                            (int) $user['id']
                        );

                        if (!$usuario2fa) {
                            throw new RuntimeException(
                                'No fue posible cargar la seguridad de la cuenta.'
                            );
                        }

                        two_factor_start_pending($usuario2fa);

                        $proteccion2faActiva =
                            (int) ($config2fa['activo'] ?? 0) === 1;
                        $requiere2fa = two_factor_role_required(
                            $config2fa,
                            (string) $usuario2fa['rol']
                        );
                        $tiene2fa = two_factor_user_enabled($usuario2fa);

                        if (
                            $proteccion2faActiva
                            && $tiene2fa
                            && (int) ($config2fa['permitir_dispositivo_confiable'] ?? 0) === 1
                            && two_factor_trusted_device_valid(
                                $db,
                                (int) $usuario2fa['id']
                            )
                        ) {
                            two_factor_log_event(
                                $db,
                                (int) $usuario2fa['id'],
                                '2fa_dispositivo_confiable',
                                'Acceso autorizado mediante dispositivo confiable.'
                            );

                            $destino = two_factor_complete_login(
                                $db,
                                $usuario2fa
                            );
                            header('Location: ' . $destino);
                            exit();
                        }

                        if ($requiere2fa && !$tiene2fa) {
                            header('Location: configurar_2fa.php');
                            exit();
                        }

                        if ($proteccion2faActiva && $tiene2fa) {
                            header('Location: verificar_2fa.php');
                            exit();
                        }

                        $destino = two_factor_complete_login(
                            $db,
                            $usuario2fa
                        );
                        header('Location: ' . $destino);
                        exit();
                    } catch (Throwable $sucursalException) {
                        error_log(
                            '[Login 2FA/sucursal] '
                            . $sucursalException->getMessage()
                        );

                        two_factor_clear_pending();
                        $_SESSION = [];
                        session_regenerate_id(true);

                        $mensajeSucursal = strtolower(
                            $sucursalException->getMessage()
                        );

                        if (strpos($mensajeSucursal, 'no tiene una sucursal') !== false) {
                            $error = 'sin_sucursal_asignada';
                        } elseif (strpos($mensajeSucursal, 'dos pasos') !== false) {
                            $error = '2fa_no_instalado';
                        } else {
                            $error = 'error_sucursal';
                        }
                    }
                } else {
                    $error = 'password_incorrecta';
                }
            } else {
                $error = 'usuario_no_encontrado';
            }

            $stmt->close();
        }
    }
}

$errores = [
    'sesion_requerida' => [
        'title' => 'Inicia sesión',
        'message' => 'Debes iniciar sesión para acceder al sistema.',
        'icon' => 'info',
        'timer' => 4200,
    ],
    'rol_invalido' => [
        'title' => 'Perfil no válido',
        'message' => 'El perfil de la cuenta no tiene un rol permitido. Contacta al administrador.',
        'icon' => 'error',
        'timer' => 5500,
    ],
    'campos_vacios' => [
        'title' => 'Campos incompletos',
        'message' => 'Por favor completa tu correo electrónico y contraseña.',
        'icon' => 'warning',
        'timer' => 3500,
    ],
    'email_invalido' => [
        'title' => 'Correo no válido',
        'message' => 'Revisa el formato del correo electrónico e inténtalo nuevamente.',
        'icon' => 'warning',
        'timer' => 3500,
    ],
    'password_incorrecta' => [
        'title' => 'Acceso no autorizado',
        'message' => 'La contraseña ingresada no es correcta.',
        'icon' => 'error',
        'timer' => 3500,
    ],
    'usuario_no_encontrado' => [
        'title' => 'Usuario no encontrado',
        'message' => 'No encontramos una cuenta asociada con ese correo.',
        'icon' => 'error',
        'timer' => 3500,
    ],
    'usuario_pendiente' => [
        'title' => 'Cuenta pendiente de aprobación',
        'message' => 'Tu solicitud fue recibida, pero un administrador todavía debe autorizarla.',
        'icon' => 'info',
        'timer' => 6000,
    ],
    'usuario_rechazado' => [
        'title' => 'Solicitud no autorizada',
        'message' => 'Tu solicitud de acceso fue rechazada. Contacta al administrador para obtener más información.',
        'icon' => 'error',
        'timer' => 6000,
    ],
    'usuario_inactivo' => [
        'title' => 'Cuenta desactivada',
        'message' => 'Tu cuenta está desactivada. Contacta al administrador del sistema.',
        'icon' => 'error',
        'timer' => 5000,
    ],
    'sin_sucursal_asignada' => [
        'title' => 'Sucursal no asignada',
        'message' => 'Tu cuenta está activa, pero todavía no tiene una sucursal disponible. Solicita al administrador que te asigne una sede.',
        'icon' => 'warning',
        'timer' => 6500,
    ],
    'sin_sucursal' => [
        'title' => 'Sucursal no disponible',
        'message' => 'La sucursal de tu sesión dejó de estar disponible. Inicia sesión nuevamente o contacta al administrador.',
        'icon' => 'warning',
        'timer' => 6500,
    ],
    'conexion_sucursal' => [
        'title' => 'No fue posible validar la sucursal',
        'message' => 'No se pudo comprobar la sede asignada. Inténtalo nuevamente.',
        'icon' => 'error',
        'timer' => 5500,
    ],
    'error_sucursal' => [
        'title' => 'Configuración de sucursales no disponible',
        'message' => 'No fue posible cargar la sucursal asignada a tu cuenta. Verifica que la migración multisucursal esté instalada.',
        'icon' => 'error',
        'timer' => 6500,
    ],
    'sesion_revalidacion' => [
        'title' => 'Sesión actualizada',
        'message' => 'El rol o la sucursal de tu cuenta cambió. Inicia sesión nuevamente.',
        'icon' => 'info',
        'timer' => 5500,
    ],
    'sesion_expirada' => [
        'title' => 'Sesión finalizada',
        'message' => 'Tu sesión expiró después de 12 horas. Inicia sesión nuevamente.',
        'icon' => 'info',
        'timer' => 5000,
    ],
    'sesion_seguridad' => [
        'title' => 'Verifica nuevamente tu identidad',
        'message' => 'La sesión de seguridad cambió o fue revocada. Inicia sesión otra vez.',
        'icon' => 'info',
        'timer' => 6000,
    ],
    'sesion_2fa_expirada' => [
        'title' => 'Verificación expirada',
        'message' => 'La verificación en dos pasos tardó demasiado. Ingresa nuevamente tu correo y contraseña.',
        'icon' => 'info',
        'timer' => 6000,
    ],
    '2fa_no_instalado' => [
        'title' => 'Seguridad pendiente de instalar',
        'message' => 'Ejecuta database/instalar_verificacion_dos_pasos.sql antes de iniciar sesión.',
        'icon' => 'error',
        'timer' => 7000,
    ],
    'error_sistema' => [
        'title' => 'No fue posible iniciar sesión',
        'message' => 'Ocurrió un problema al procesar la solicitud. Inténtalo nuevamente.',
        'icon' => 'error',
        'timer' => 5000,
    ],
];

$alerta = $errores[$error] ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?php echo htmlspecialchars($loginThemeColor, ENT_QUOTES, 'UTF-8'); ?>">
    <title><?php echo $gym_name_safe; ?> | Iniciar sesión</title>

    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="icon" href="favicon.php">

    <style>
        :root {
            /* Fallbacks. tema.css.php redefine --sys-* según la BD. */
            --azul: var(--sys-primary, #1e3a8a);
            --azul-oscuro: var(--sys-primary-dark, #152c6b);
            --azul-claro: var(--sys-primary-soft, #eef3ff);
            --login-accent: var(--sys-accent, #2563eb);
            --login-sidebar: var(--sys-sidebar, #0a2540);
            --fondo: var(--sys-bg, #f3f5f9);
            --blanco: var(--sys-surface, #ffffff);
            --texto: var(--sys-text, #1f2937);
            --texto-suave: var(--sys-muted, #64748b);
            --borde: var(--sys-border, #dfe5ee);
            --radio-grande: calc(var(--sys-radius, 14px) + 14px);
            --radio: var(--sys-radius, 14px);
            --sombra: 0 24px 70px color-mix(in srgb, var(--azul) 15%, transparent);
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
            padding: clamp(16px, 3vw, 36px);
            overflow-x: hidden;
            display: grid;
            place-items: center;
            color: var(--texto);
            font-family: "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background:
                radial-gradient(circle at 8% 12%, color-mix(in srgb, var(--azul) 8%, transparent), transparent 28rem),
                radial-gradient(circle at 92% 88%, color-mix(in srgb, var(--login-accent) 6%, transparent), transparent 24rem),
                var(--fondo);
        }

        button,
        input {
            font: inherit;
        }

        a {
            color: inherit;
        }

        .page-shell {
            position: relative;
            width: min(950px, 100%);
            min-height: min(650px, calc(100dvh - clamp(32px, 6vw, 72px)));
            display: grid;
            grid-template-columns: minmax(350px, 0.84fr) minmax(470px, 1.16fr);
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
            padding: clamp(42px, 5vw, 68px);
            overflow: hidden;
            color: var(--blanco);
            text-align: center;
            background:
                linear-gradient(145deg, var(--login-sidebar) 0%, var(--azul) 56%, var(--login-accent) 100%);
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
            position: relative;
            flex: 0 0 auto;
            width: clamp(190px, 18vw, 232px);
            aspect-ratio: 1 / 1;
            display: grid;
            place-items: center;
            overflow: hidden;
            padding: 0;
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: 38px;
            background: #ffffff;
            box-shadow:
                0 26px 60px rgba(4, 15, 48, 0.3),
                0 0 0 8px rgba(255, 255, 255, 0.075);
        }

        .brand-logo-box.light {
            width: 110px;
            height: 88px;
            aspect-ratio: auto;
            padding: 8px;
            border-color: color-mix(in srgb, var(--azul) 12%, transparent);
            border-radius: 22px;
            background: var(--blanco);
            box-shadow: 0 15px 35px color-mix(in srgb, var(--azul) 16%, transparent);
        }

        .brand-logo-box img {
            width: 100%;
            height: 100%;
            display: block;
            padding: 0;
            object-fit: contain;
        }

        .brand-logo-box.light img {
            padding: 0;
        }

        .logo-fallback {
            width: 100%;
            height: 100%;
            display: grid;
            place-items: center;
            color: var(--azul);
            font-size: 58px;
        }

        .brand-logo-box.light .logo-fallback {
            font-size: 34px;
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
            text-shadow: 0 5px 18px rgba(0, 0, 0, 0.18);
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
            color: color-mix(in srgb, var(--login-accent) 35%, #ffffff 65%);
            font-size: 14px;
        }

        .auth-panel {
            min-width: 0;
            display: grid;
            place-items: center;
            padding: clamp(34px, 5vw, 70px);
            background: var(--blanco);
        }

        .login-card {
            width: min(420px, 100%);
            min-width: 0;
        }

        .login-brand {
            display: none;
            flex-direction: column;
            align-items: center;
            margin-bottom: 26px;
            text-align: center;
        }

        .login-brand .brand-name {
            margin-top: 14px;
            color: var(--azul-oscuro);
            font-size: 21px;
        }

        .login-brand .brand-subtitle {
            margin-top: 5px;
            color: var(--texto-suave);
            font-size: 11px;
        }

        .login-header {
            margin-bottom: 30px;
        }

        .login-header h2 {
            margin: 0 0 9px;
            color: var(--azul-oscuro);
            font-size: clamp(28px, 3vw, 35px);
            font-weight: 760;
            line-height: 1.1;
            letter-spacing: -0.035em;
        }

        .login-header p {
            margin: 0;
            color: var(--texto-suave);
            font-size: 14px;
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 19px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
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
            z-index: 1;
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
            min-height: 52px;
            padding: 13px 46px;
            border: 1px solid var(--borde);
            border-radius: var(--radio);
            outline: none;
            color: var(--texto);
            background: #f8fafc;
            font-size: 15px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
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
            box-shadow: 0 0 0 4px color-mix(in srgb, var(--azul) 10%, transparent);
        }

        .input-wrapper:focus-within .input-icon {
            color: var(--azul);
        }

        .toggle-password {
            position: absolute;
            z-index: 2;
            right: 8px;
            top: 50%;
            width: 38px;
            height: 38px;
            transform: translateY(-50%);
            display: grid;
            place-items: center;
            padding: 0;
            border: 0;
            border-radius: 10px;
            color: #64748b;
            background: transparent;
            cursor: pointer;
            transition: color 0.2s ease, background 0.2s ease;
        }

        .toggle-password:hover {
            color: var(--azul);
            background: var(--azul-claro);
        }

        .toggle-password:focus-visible,
        .forgot-link:focus-visible,
        .register-link a:focus-visible,
        .btn-login:focus-visible {
            outline: 3px solid color-mix(in srgb, var(--azul) 22%, transparent);
            outline-offset: 3px;
        }

        .form-options {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin: 4px 0 26px;
        }

        .remember-option {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            min-width: 0;
            color: var(--texto-suave);
            font-size: 13px;
            cursor: pointer;
            user-select: none;
        }

        .remember-option input {
            width: 17px;
            height: 17px;
            flex: 0 0 auto;
            margin: 0;
            accent-color: var(--azul);
            cursor: pointer;
        }

        .forgot-link {
            flex: 0 0 auto;
            color: var(--azul);
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        .forgot-link:hover,
        .register-link a:hover {
            color: var(--azul-oscuro);
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        .btn-login {
            position: relative;
            width: 100%;
            min-height: 52px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 13px 18px;
            border: 0;
            border-radius: var(--radio);
            color: var(--blanco);
            background: var(--azul);
            box-shadow: 0 12px 25px color-mix(in srgb, var(--azul) 20%, transparent);
            font-size: 15px;
            font-weight: 750;
            cursor: pointer;
            transition: transform 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
        }

        .btn-login:hover:not(:disabled) {
            transform: translateY(-1px);
            background: var(--azul-oscuro);
            box-shadow: 0 15px 30px color-mix(in srgb, var(--azul) 25%, transparent);
        }

        .btn-login:active:not(:disabled) {
            transform: translateY(0);
        }

        .btn-login:disabled {
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

        .btn-login.is-loading .btn-spinner {
            display: inline-block;
        }

        .register-link {
            margin-top: 24px;
            padding-top: 23px;
            border-top: 1px solid #e8edf3;
            text-align: center;
        }

        .register-link p {
            margin: 0;
            color: var(--texto-suave);
            font-size: 13px;
            line-height: 1.6;
        }

        .register-link a {
            color: var(--azul);
            font-weight: 750;
            text-decoration: none;
        }

        .login-footer {
            margin-top: 24px;
            color: #94a3b8;
            font-size: 11px;
            line-height: 1.5;
            text-align: center;
        }

        .swal2-popup.login-alert {
            width: min(450px, calc(100vw - 28px));
            border-radius: 20px;
            padding: 1.5rem;
        }

        .swal2-confirm.login-alert-button {
            min-width: 112px;
            border-radius: 10px !important;
            background: var(--azul) !important;
            box-shadow: none !important;
        }


        /* El panel de identidad debe seguir completamente el tema corporativo. */
        .brand-panel {
            background:
                linear-gradient(
                    145deg,
                    var(--login-sidebar) 0%,
                    color-mix(in srgb, var(--azul) 82%, var(--login-sidebar) 18%) 48%,
                    var(--login-accent) 100%
                );
        }

        .brand-access i {
            color: color-mix(in srgb, var(--login-accent) 42%, #ffffff 58%);
        }

        .login-header h2,
        .login-brand .brand-name,
        .forgot-link,
        .register-link a,
        .input-wrapper:focus-within .input-icon,
        .toggle-password:hover,
        .logo-fallback {
            color: var(--azul);
        }

        .btn-login,
        .swal2-confirm.login-alert-button {
            background: linear-gradient(135deg, var(--azul), var(--login-accent)) !important;
            color: var(--sys-primary-text, #ffffff) !important;
        }

        .btn-login:hover:not(:disabled) {
            background: linear-gradient(135deg, var(--azul-oscuro), var(--azul)) !important;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 840px) {
            body {
                padding: 18px;
            }

            .page-shell {
                width: min(560px, 100%);
                min-height: auto;
                grid-template-columns: 1fr;
                border-color: color-mix(in srgb, var(--azul) 8%, transparent);
                border-radius: 26px;
            }

            .brand-panel {
                min-height: 220px;
                padding: 28px 28px 25px;
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

            .brand-hero {
                width: min(100%, 250px);
            }

            .brand-logo-box {
                width: 130px;
                height: 130px;
                aspect-ratio: auto;
                padding: 0;
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
                margin-top: 12px;
                font-size: 10px;
                transform: none;
            }

            .brand-access i {
                font-size: 12px;
            }

            .auth-panel {
                display: block;
                padding: clamp(32px, 7vw, 48px);
            }

            .login-card {
                width: 100%;
            }

            .login-brand {
                display: none;
            }

            .login-header {
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            body {
                place-items: start center;
                padding: 12px;
                background:
                    linear-gradient(180deg, color-mix(in srgb, var(--azul) 8%, transparent), transparent 230px),
                    var(--fondo);
            }

            .page-shell {
                width: 100%;
                min-height: calc(100dvh - 24px);
                border: 1px solid color-mix(in srgb, var(--azul) 8%, transparent);
                border-radius: 23px;
                box-shadow: 0 18px 45px color-mix(in srgb, var(--azul) 12%, transparent);
            }

            .brand-panel {
                min-height: 182px;
                padding: 21px 20px 19px;
            }

            .brand-logo-box {
                width: 112px;
                height: 112px;
                padding: 0;
                border-radius: 25px;
            }

            .brand-name {
                margin-top: 10px;
                font-size: 19px;
            }

            .brand-access {
                margin-top: 10px;
                font-size: 9px;
            }

            .auth-panel {
                min-height: 0;
                display: block;
                padding: 27px 20px 22px;
            }

            .login-header {
                margin-bottom: 25px;
            }

            .login-header h2 {
                font-size: 27px;
            }

            .form-control {
                min-height: 50px;
                font-size: 16px;
            }

            .btn-login {
                min-height: 50px;
            }

            .login-footer {
                margin-top: 20px;
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
                padding: 18px 16px 16px;
            }

            .brand-logo-box {
                width: 102px;
                height: 102px;
                border-radius: 23px;
            }

            .brand-name {
                margin-top: 9px;
                font-size: 18px;
            }

            .auth-panel {
                padding: 24px 16px 18px;
            }

            .form-options {
                gap: 10px;
            }

            .remember-option,
            .forgot-link {
                font-size: 12px;
            }
        }

        @media (max-height: 720px) and (min-width: 841px) {
            body {
                align-items: start;
            }

            .page-shell {
                min-height: 600px;
            }

            .brand-panel,
            .auth-panel {
                padding-top: 32px;
                padding-bottom: 32px;
            }

            .brand-logo-box {
                width: 190px;
                height: 190px;
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
    <link rel="stylesheet" href="tema.css.php?v=1" data-system-theme="true">
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

            <div class="brand-access" aria-label="Acceso restringido">
                <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                <span>Acceso exclusivo para personal autorizado</span>
            </div>
        </aside>

        <main class="auth-panel">
            <section class="login-card" aria-labelledby="login-title">
                <div class="login-brand">
                    <div class="brand-logo-box light">
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

                <header class="login-header">
                    <h2 id="login-title">Bienvenido de nuevo</h2>
                    <p>Ingresa tus datos para acceder al sistema.</p>
                </header>

                <form method="POST" action="" id="loginForm" novalidate>
                    <div class="form-group">
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
                                autocomplete="username"
                                inputmode="email"
                                autocapitalize="none"
                                spellcheck="false"
                                required
                            >
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
                                placeholder="Ingresa tu contraseña"
                                autocomplete="current-password"
                                required
                            >
                            <button
                                type="button"
                                class="toggle-password"
                                id="togglePassword"
                                aria-label="Mostrar contraseña"
                                aria-pressed="false"
                            >
                                <i class="fa-regular fa-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-options">
                        <label class="remember-option" for="remember">
                            <input type="checkbox" id="remember">
                            <span>Recordar correo</span>
                        </label>
                        <a href="recuperar-password.php" class="forgot-link">¿Olvidaste tu contraseña?</a>
                    </div>

                    <button type="submit" class="btn-login" id="loginButton">
                        <span class="btn-spinner" aria-hidden="true"></span>
                        <span class="btn-text">Iniciar sesión</span>
                        <i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i>
                    </button>
                </form>

                <div class="register-link">
                    <p>¿No tienes una cuenta? <a href="registro.php">Crear cuenta</a></p>
                </div>

                <footer class="login-footer">
                    &copy; <?php echo date('Y'); ?> <?php echo $gym_name_safe; ?>. Todos los derechos reservados.
                </footer>
            </section>
        </main>
    </div>

    <script>
        (() => {
            'use strict';

            const form = document.getElementById('loginForm');
            const emailInput = document.getElementById('email');
            const passwordInput = document.getElementById('password');
            const rememberInput = document.getElementById('remember');
            const toggleButton = document.getElementById('togglePassword');
            const toggleIcon = toggleButton.querySelector('i');
            const loginButton = document.getElementById('loginButton');

            const savedEmail = localStorage.getItem('savedEmail');
            if (savedEmail && emailInput.value.trim() === '') {
                emailInput.value = savedEmail;
                rememberInput.checked = true;
            }

            toggleButton.addEventListener('click', () => {
                const showPassword = passwordInput.type === 'password';
                passwordInput.type = showPassword ? 'text' : 'password';
                toggleButton.setAttribute('aria-pressed', String(showPassword));
                toggleButton.setAttribute('aria-label', showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña');
                toggleIcon.classList.toggle('fa-eye', !showPassword);
                toggleIcon.classList.toggle('fa-eye-slash', showPassword);
                passwordInput.focus({ preventScroll: true });
            });

            form.addEventListener('submit', (event) => {
                const email = emailInput.value.trim();
                const password = passwordInput.value;

                emailInput.value = email;

                if (!email || !password || !emailInput.validity.valid) {
                    event.preventDefault();

                    const invalidEmail = email !== '' && !emailInput.validity.valid;
                    Swal.fire({
                        icon: 'warning',
                        title: invalidEmail ? 'Correo no válido' : 'Campos incompletos',
                        text: invalidEmail
                            ? 'Ingresa un correo electrónico con un formato válido.'
                            : 'Completa tu correo electrónico y contraseña.',
                        confirmButtonText: 'Entendido',
                        customClass: {
                            popup: 'login-alert',
                            confirmButton: 'login-alert-button'
                        }
                    }).then(() => {
                        (invalidEmail || !email ? emailInput : passwordInput).focus();
                    });
                    return;
                }

                if (rememberInput.checked) {
                    localStorage.setItem('savedEmail', email);
                } else {
                    localStorage.removeItem('savedEmail');
                }

                loginButton.disabled = true;
                loginButton.classList.add('is-loading');
                loginButton.setAttribute('aria-busy', 'true');
                loginButton.querySelector('.btn-text').textContent = 'Ingresando...';
            });

            <?php if ($alerta): ?>
            Swal.fire({
                icon: <?php echo json_encode($alerta['icon'], JSON_UNESCAPED_UNICODE); ?>,
                title: <?php echo json_encode($alerta['title'], JSON_UNESCAPED_UNICODE); ?>,
                text: <?php echo json_encode($alerta['message'], JSON_UNESCAPED_UNICODE); ?>,
                confirmButtonText: 'Entendido',
                timer: <?php echo (int) $alerta['timer']; ?>,
                timerProgressBar: true,
                customClass: {
                    popup: 'login-alert',
                    confirmButton: 'login-alert-button'
                }
            });
            <?php endif; ?>
        })();
    </script>
</body>
</html>