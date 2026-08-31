<?php
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/sucursal_context.php';
require_once __DIR__ . '/includes/super_admin_helper.php';
require_once __DIR__ . '/includes/configuracion_context.php';
require_once __DIR__ . '/includes/two_factor_helper.php';
require_once __DIR__ . '/includes/password_temporal_helper.php';
require_once __DIR__ . '/includes/tema_sistema.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
require_once __DIR__ . '/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$database = new Database();
$conn = $database->getConnection();

if (!$conn instanceof mysqli) {
    die('Error: No se pudo establecer la conexión a la base de datos.');
}

$conn->set_charset('utf8mb4');

$usuario_id = (int) ($_SESSION['user_id'] ?? 0);
$usuario_nombre = (string) ($_SESSION['user_name'] ?? 'Usuario');

if (empty($_SESSION['config_security_csrf'])) {
    $_SESSION['config_security_csrf'] = bin2hex(random_bytes(32));
}
$configSecurityCsrf = (string) $_SESSION['config_security_csrf'];

/*
 * Se conserva el rol real para las acciones exclusivas del
 * superadministrador. El rol operativo continúa siendo "admin" para
 * mantener compatibilidad con los módulos anteriores.
 */
$usuario_rol_real = rol_base_real_sesion();
$esSuperAdministradorActual = rol_es_super_administrador(
    $usuario_rol_real
);
$usuario_rol = rol_operativo_desde_base($usuario_rol_real);

if ($usuario_id <= 0) {
    header('Location: login.php');
    exit;
}

if (!rol_es_administrativo($usuario_rol_real)) {
    header('Location: dashboard.php');
    exit;
}

try {
    $configContexto = configuracion_contexto(
        $conn,
        $usuario_id
    );
} catch (Throwable $errorContexto) {
    die(configuracion_h($errorContexto->getMessage()));
}

$vistaGlobalConfiguracion = !empty(
    $configContexto['vista_global']
);
$sucursalIdConfiguracion = (int) (
    $configContexto['sucursal_id'] ?? 0
);
$sucursalNombreConfiguracion = (string) (
    $configContexto['sucursal_nombre'] ?? 'Sucursal'
);

/*
 * Los catálogos por sucursal ya no se rellenan automáticamente.
 * En particular, un plan solo existe en planes_sucursales cuando
 * un administrador lo asigna expresamente a esa sede.
 */

date_default_timezone_set((string) (
    $configContexto['zona_horaria']
    ?? 'America/Mexico_City'
));

/**
 * Comprueba si la tabla de configuración SMTP ya existe.
 */
function existeConfiguracionCorreo($conn)
{
    $resultado = $conn->query(
        "SHOW TABLES LIKE 'configuracion_correo'"
    );

    return $resultado && $resultado->num_rows > 0;
}

/**
 * Obtiene la configuración SMTP activa.
 */
function obtenerConfiguracionCorreo($conn)
{
    if (!existeConfiguracionCorreo($conn)) {
        return null;
    }

    $resultado = $conn->query(
        "SELECT *
         FROM configuracion_correo
         WHERE id = 1
         LIMIT 1"
    );

    return $resultado ? $resultado->fetch_assoc() : null;
}

/**
 * Obtiene la contraseña temporal predeterminada de esta instalación.
 *
 * La base de datos conserva el valor cifrado. Si la migración todavía no
 * se ha ejecutado o aún no fue personalizada, se mantiene ego1 para no
 * interrumpir el alta y restablecimiento de usuarios existentes.
 */
function generarPasswordTemporal(mysqli $conn): string
{
    return password_temporal_get($conn);
}

/**
 * Construye automáticamente la URL del login.
 */
function obtenerUrlLoginSistema()
{
    $https =
        !empty($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off';

    $protocolo = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST'])
        ? $_SERVER['HTTP_HOST']
        : 'localhost';

    $directorio = str_replace(
        '\\',
        '/',
        dirname($_SERVER['SCRIPT_NAME'])
    );

    return $protocolo .
        '://' .
        $host .
        rtrim($directorio, '/') .
        '/login.php';
}

/**
 * Envía las credenciales de un usuario con los datos guardados
 * en configuracion_correo.
 */
function enviarCredencialesAcceso(
    $conn,
    $nombre,
    $email,
    $passwordTemporal,
    $rol
) {
    $config = obtenerConfiguracionCorreo($conn);

    if (!$config) {
        return array(
            'enviado' => false,
            'error' =>
                'La configuración de correo todavía no existe.'
        );
    }

    if ((int) $config['activo'] !== 1) {
        return array(
            'enviado' => false,
            'error' => 'El envío de correo está desactivado.'
        );
    }

    $gimnasio = 'EGO';
    $resultadoGym = $conn->query(
        "SELECT nombre
         FROM configuracion_gimnasio
         WHERE id = 1
         LIMIT 1"
    );

    if ($resultadoGym && $filaGym = $resultadoGym->fetch_assoc()) {
        if (!empty($filaGym['nombre'])) {
            $gimnasio = $filaGym['nombre'];
        }
    }

    $roles = array(
        'super_administrador' => 'Superadministrador',
        'admin' => 'Administrador',
        'recepcionista' => 'Recepcionista',
        'entrenador' => 'Entrenador'
    );

    $rolTexto = isset($roles[$rol])
        ? $roles[$rol]
        : ucfirst($rol);

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->Port = (int) $config['puerto'];
        $mail->SMTPAuth =
            (int) $config['smtp_auth'] === 1;

        if ($mail->SMTPAuth) {
            $mail->Username = $config['usuario'];
            $mail->Password = $config['password_smtp'];
        }

        $cifrado = strtolower(
            trim((string) $config['cifrado'])
        );

        if ($cifrado === 'ssl') {
            $mail->SMTPSecure =
                PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($cifrado === 'tls') {
            $mail->SMTPSecure =
                PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $verificarSsl =
            (int) $config['verificar_ssl'] === 1;

        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => $verificarSsl,
                'verify_peer_name' => $verificarSsl,
                'allow_self_signed' => !$verificarSsl
            )
        );

        $remitenteEmail =
            !empty($config['remitente_email'])
                ? $config['remitente_email']
                : $config['usuario'];

        // El nombre visible del remitente siempre sale de
        // configuracion_gimnasio para mantener la marca consistente.
        $remitenteNombre = $gimnasio;

        $mail->setFrom(
            $remitenteEmail,
            $remitenteNombre
        );

        $mail->addAddress($email, $nombre);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';

        $urlLogin = obtenerUrlLoginSistema();

        $mail->Subject =
            'Credenciales de acceso - ' .
            $gimnasio;

        $mail->Body = '
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
        </head>
        <body style="
            margin:0;
            padding:24px;
            background:#f3f5f8;
            font-family:Arial,sans-serif;
            color:#243244;
        ">
            <div style="
                max-width:620px;
                margin:0 auto;
                overflow:hidden;
                border:1px solid #dce3eb;
                border-radius:14px;
                background:#ffffff;
            ">
                <div style="
                    padding:24px 28px;
                    background:#15263a;
                    color:#ffffff;
                ">
                    <h2 style="margin:0;font-size:21px;">' .
                        htmlspecialchars(
                            $gimnasio,
                            ENT_QUOTES,
                            'UTF-8'
                        ) .
                    '</h2>
                    <p style="
                        margin:7px 0 0;
                        color:#d8e2ec;
                        font-size:13px;
                    ">
                        Tu cuenta de acceso fue creada
                    </p>
                </div>

                <div style="padding:28px;">
                    <p style="margin-top:0;">
                        Hola <strong>' .
                            htmlspecialchars(
                                $nombre,
                                ENT_QUOTES,
                                'UTF-8'
                            ) .
                        '</strong>,
                    </p>

                    <p style="line-height:1.6;">
                        Utiliza estas credenciales para
                        ingresar al sistema:
                    </p>

                    <div style="
                        margin:20px 0;
                        padding:18px;
                        border:1px solid #dce3eb;
                        border-radius:10px;
                        background:#f8fafc;
                    ">
                        <p style="margin:0 0 10px;">
                            <strong>Usuario:</strong>
                            ' .
                            htmlspecialchars(
                                $email,
                                ENT_QUOTES,
                                'UTF-8'
                            ) .
                        '</p>

                        <p style="margin:0 0 10px;">
                            <strong>Contraseña temporal:</strong>
                            <span style="
                                font-family:monospace;
                                font-size:15px;
                            ">' .
                                htmlspecialchars(
                                    $passwordTemporal,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) .
                            '</span>
                        </p>

                        <p style="margin:0;">
                            <strong>Rol:</strong>
                            ' .
                            htmlspecialchars(
                                $rolTexto,
                                ENT_QUOTES,
                                'UTF-8'
                            ) .
                        '</p>
                    </div>

                    <p style="text-align:center;">
                        <a href="' .
                            htmlspecialchars(
                                $urlLogin,
                                ENT_QUOTES,
                                'UTF-8'
                            ) .
                        '" style="
                            display:inline-block;
                            padding:12px 22px;
                            border-radius:8px;
                            background:#2f66b3;
                            color:#ffffff;
                            text-decoration:none;
                            font-weight:bold;
                        ">
                            Ingresar al sistema
                        </a>
                    </p>

                    <p style="
                        margin-bottom:0;
                        color:#667085;
                        font-size:12px;
                        line-height:1.5;
                    ">
                        El sistema te pedirá cambiar la contraseña
                        durante el primer inicio de sesión.
                    </p>
                </div>
            </div>
        </body>
        </html>';

        $mail->AltBody =
            "Hola " .
            $nombre .
            "\n\nUsuario: " .
            $email .
            "\nContraseña temporal: " .
            $passwordTemporal .
            "\nRol: " .
            $rolTexto .
            "\nAcceso: " .
            $urlLogin;

        $mail->send();

        return array(
            'enviado' => true,
            'error' => ''
        );
    } catch (Exception $error) {
        error_log(
            'Error al enviar credenciales a ' .
            $email .
            ': ' .
            $mail->ErrorInfo
        );

        return array(
            'enviado' => false,
            'error' => $mail->ErrorInfo
        );
    }
}


// Tema corporativo del sistema.
try {
    tema_sistema_asegurar_tabla($conn);
} catch (Throwable $temaSchemaError) {
    error_log('[Configuración apariencia] ' . $temaSchemaError->getMessage());
}
$config_apariencia = tema_sistema_obtener($conn, false);

// Resolver secciones disponibles según el contexto.
$seccionesGlobales = array(
    'general',
    'apariencia',
    'correo',
    'clientes',
    'planes',
    'productos',
    'categorias',
    'proveedores',
    'clases',
    'usuarios'
);

if ($esSuperAdministradorActual) {
    array_splice($seccionesGlobales, 2, 0, array('seguridad'));
}

$seccionesSucursal = array(
    'clientes',
    'planes',
    'productos',
    'clases',
    'usuarios'
);

$seccionesDisponibles = $vistaGlobalConfiguracion
    ? $seccionesGlobales
    : $seccionesSucursal;

$seccionPredeterminada = $vistaGlobalConfiguracion
    ? 'general'
    : 'clientes';

$seccion = trim((string) (
    $_GET['section'] ?? $seccionPredeterminada
));

if (!in_array($seccion, $seccionesDisponibles, true)) {
    $seccion = $seccionPredeterminada;
}

// Configuración corporativa o datos propios de la sucursal activa.
if ($vistaGlobalConfiguracion) {
    $config_gimnasio = configuracion_fila(
        $conn,
        "SELECT *
         FROM configuracion_gimnasio
         WHERE id = 1
         LIMIT 1"
    );
} else {
    $config_gimnasio = configuracion_fila(
        $conn,
        "SELECT
            id,
            nombre,
            telefono,
            email,
            direccion,
            horario,
            logo,
            zona_horaria,
            clave,
            es_matriz,
            estado
         FROM sucursales
         WHERE id = ?
         LIMIT 1",
        'i',
        array($sucursalIdConfiguracion)
    );
}

if (!$config_gimnasio) {
    $config_gimnasio = array(
        'nombre' => $vistaGlobalConfiguracion
            ? 'EGO'
            : $sucursalNombreConfiguracion,
        'telefono' => '',
        'email' => '',
        'direccion' => '',
        'horario' => '',
        'logo' => ''
    );
}

$config_correo = obtenerConfiguracionCorreo($conn);
$config_2fa = two_factor_get_config($conn);
$config_acceso = password_temporal_get_metadata($conn);

// Datos corporativos usados como respaldo visual del logo de una sede.
$config_corporativa = configuracion_fila(
    $conn,
    "SELECT nombre, logo
     FROM configuracion_gimnasio
     WHERE id = 1
     LIMIT 1"
) ?: array('nombre' => 'EGO', 'logo' => '');

$logo_path = trim((string) ($config_gimnasio['logo'] ?? ''));
$logo_es_propio = $logo_path !== '';

if (!$logo_es_propio && !$vistaGlobalConfiguracion) {
    $logo_path = trim((string) ($config_corporativa['logo'] ?? ''));
}

if ($logo_path === '' || !is_file(__DIR__ . '/' . $logo_path)) {
    $logo_path = 'img/logo-gym.png';
}

// Procesar acciones POST.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'save_appearance') {
            if (!$vistaGlobalConfiguracion) {
                throw new RuntimeException(
                    'La apariencia es corporativa. Cambia a Todas las sucursales para modificarla.'
                );
            }

            $csrfTema = (string) ($_POST['security_csrf'] ?? '');
            if (
                $csrfTema === ''
                || !hash_equals($configSecurityCsrf, $csrfTema)
            ) {
                throw new RuntimeException('Token de seguridad inválido. Actualiza la página e intenta nuevamente.');
            }

            $config_apariencia = tema_sistema_guardar(
                $conn,
                array(
                    'tema' => (string) ($_POST['tema'] ?? 'personalizado'),
                    'color_primario' => (string) ($_POST['color_primario'] ?? ''),
                    'color_acento' => (string) ($_POST['color_acento'] ?? ''),
                    'color_sidebar' => (string) ($_POST['color_sidebar'] ?? ''),
                    'color_fondo' => (string) ($_POST['color_fondo'] ?? ''),
                    'color_superficie' => (string) ($_POST['color_superficie'] ?? ''),
                    'color_texto' => (string) ($_POST['color_texto'] ?? ''),
                    'radio_componentes' => (int) ($_POST['radio_componentes'] ?? 12),
                ),
                $usuario_id
            );

            configuracion_json(array(
                'success' => true,
                'message' => 'La apariencia del sistema fue actualizada.',
                'config' => $config_apariencia
            ));
        }

        if ($action === 'reset_appearance') {
            if (!$vistaGlobalConfiguracion) {
                throw new RuntimeException(
                    'La apariencia es corporativa. Cambia a Todas las sucursales para modificarla.'
                );
            }

            $csrfTema = (string) ($_POST['security_csrf'] ?? '');
            if (
                $csrfTema === ''
                || !hash_equals($configSecurityCsrf, $csrfTema)
            ) {
                throw new RuntimeException('Token de seguridad inválido. Actualiza la página e intenta nuevamente.');
            }

            $config_apariencia = tema_sistema_restaurar($conn, $usuario_id);

            configuracion_json(array(
                'success' => true,
                'message' => 'Se restauró el tema predeterminado.',
                'config' => $config_apariencia
            ));
        }

        if ($action === 'save_config') {
            if (!$vistaGlobalConfiguracion) {
                throw new RuntimeException(
                    'La configuración general solo puede modificarse '
                    . 'desde Todas las sucursales.'
                );
            }

            $nombre = trim((string) ($_POST['nombre_gimnasio'] ?? ''));
            $telefono = trim((string) ($_POST['telefono'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $direccion = trim((string) ($_POST['direccion'] ?? ''));
            $horario = trim((string) ($_POST['horario'] ?? ''));

            if ($nombre === '') {
                throw new RuntimeException('El nombre es obligatorio.');
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('El correo no es válido.');
            }

            $logoNuevo = null;
            if (isset($_FILES['logo'])) {
                $logoNuevo = configuracion_guardar_logo(
                    $_FILES['logo'],
                    $vistaGlobalConfiguracion
                        ? 0
                        : $sucursalIdConfiguracion
                );
            }

            if ($vistaGlobalConfiguracion) {
                $anterior = configuracion_fila(
                    $conn,
                    "SELECT logo
                     FROM configuracion_gimnasio
                     WHERE id = 1
                     LIMIT 1"
                );

                if ($logoNuevo !== null) {
                    configuracion_ejecutar(
                        $conn,
                        "UPDATE configuracion_gimnasio
                         SET nombre = ?, telefono = ?, email = ?,
                             direccion = ?, horario = ?, logo = ?
                         WHERE id = 1",
                        'ssssss',
                        array(
                            $nombre,
                            $telefono,
                            $email,
                            $direccion,
                            $horario,
                            $logoNuevo
                        )
                    );

                    configuracion_eliminar_archivo_logo(
                        $anterior['logo'] ?? ''
                    );
                } else {
                    configuracion_ejecutar(
                        $conn,
                        "UPDATE configuracion_gimnasio
                         SET nombre = ?, telefono = ?, email = ?,
                             direccion = ?, horario = ?
                         WHERE id = 1",
                        'sssss',
                        array(
                            $nombre,
                            $telefono,
                            $email,
                            $direccion,
                            $horario
                        )
                    );
                }

                configuracion_json(array(
                    'success' => true,
                    'message' => 'La información corporativa fue actualizada.'
                ));
            }

            $anterior = configuracion_fila(
                $conn,
                "SELECT logo
                 FROM sucursales
                 WHERE id = ?
                 LIMIT 1",
                'i',
                array($sucursalIdConfiguracion)
            );

            if ($logoNuevo !== null) {
                configuracion_ejecutar(
                    $conn,
                    "UPDATE sucursales
                     SET nombre = ?, telefono = ?, email = ?,
                         direccion = ?, horario = ?, logo = ?
                     WHERE id = ?",
                    'ssssssi',
                    array(
                        $nombre,
                        $telefono,
                        $email,
                        $direccion,
                        $horario,
                        $logoNuevo,
                        $sucursalIdConfiguracion
                    )
                );

                configuracion_eliminar_archivo_logo(
                    $anterior['logo'] ?? ''
                );
            } else {
                configuracion_ejecutar(
                    $conn,
                    "UPDATE sucursales
                     SET nombre = ?, telefono = ?, email = ?,
                         direccion = ?, horario = ?
                     WHERE id = ?",
                    'sssssi',
                    array(
                        $nombre,
                        $telefono,
                        $email,
                        $direccion,
                        $horario,
                        $sucursalIdConfiguracion
                    )
                );
            }

            $_SESSION['sucursal_nombre'] = $nombre;

            configuracion_json(array(
                'success' => true,
                'message' => 'Los datos de la sucursal fueron actualizados.'
            ));
        }

        if ($action === 'delete_logo') {
            if (!$vistaGlobalConfiguracion) {
                throw new RuntimeException(
                    'El logo corporativo solo puede modificarse '
                    . 'desde Todas las sucursales.'
                );
            }

            if ($vistaGlobalConfiguracion) {
                $actual = configuracion_fila(
                    $conn,
                    "SELECT logo FROM configuracion_gimnasio WHERE id = 1"
                );
                configuracion_ejecutar(
                    $conn,
                    "UPDATE configuracion_gimnasio SET logo = NULL WHERE id = 1"
                );
            } else {
                $actual = configuracion_fila(
                    $conn,
                    "SELECT logo FROM sucursales WHERE id = ?",
                    'i',
                    array($sucursalIdConfiguracion)
                );
                configuracion_ejecutar(
                    $conn,
                    "UPDATE sucursales SET logo = NULL WHERE id = ?",
                    'i',
                    array($sucursalIdConfiguracion)
                );
            }

            configuracion_eliminar_archivo_logo($actual['logo'] ?? '');

            configuracion_json(array(
                'success' => true,
                'message' => $vistaGlobalConfiguracion
                    ? 'Logo corporativo eliminado.'
                    : 'Logo propio eliminado. La sede usará el logo corporativo.'
            ));
        }

        if ($action === 'save_email_config') {
            if (!$vistaGlobalConfiguracion) {
                throw new RuntimeException(
                    'La configuración SMTP es corporativa. Cambia a Todas las sucursales para editarla.'
                );
            }

            if (!existeConfiguracionCorreo($conn)) {
                throw new RuntimeException(
                    'Ejecuta primero configuracion_correo.sql.'
                );
            }

            $host = trim((string) ($_POST['host'] ?? ''));
            $puerto = (int) ($_POST['puerto'] ?? 587);
            $usuarioSmtp = trim((string) ($_POST['usuario'] ?? ''));
            $passwordSmtp = trim((string) ($_POST['password_smtp'] ?? ''));
            $cifrado = strtolower(trim((string) ($_POST['cifrado'] ?? 'tls')));
            $smtpAuth = isset($_POST['smtp_auth']) ? 1 : 0;
            $remitenteEmail = trim((string) ($_POST['remitente_email'] ?? ''));
            $remitenteNombre = trim((string) ($config_corporativa['nombre'] ?? 'EGO'));
            $verificarSsl = isset($_POST['verificar_ssl']) ? 1 : 0;
            $activo = isset($_POST['activo']) ? 1 : 0;

            if ($host === '' || $usuarioSmtp === '' || $remitenteEmail === '') {
                throw new RuntimeException(
                    'Host, usuario y correo remitente son obligatorios.'
                );
            }

            if (!filter_var($remitenteEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('El correo remitente no es válido.');
            }

            if ($passwordSmtp === '' && $config_correo) {
                $passwordSmtp = (string) $config_correo['password_smtp'];
            }

            configuracion_ejecutar(
                $conn,
                "INSERT INTO configuracion_correo
                    (
                        id, host, puerto, usuario, password_smtp,
                        cifrado, smtp_auth, remitente_email,
                        remitente_nombre, verificar_ssl, activo
                    )
                 VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    host = VALUES(host),
                    puerto = VALUES(puerto),
                    usuario = VALUES(usuario),
                    password_smtp = VALUES(password_smtp),
                    cifrado = VALUES(cifrado),
                    smtp_auth = VALUES(smtp_auth),
                    remitente_email = VALUES(remitente_email),
                    remitente_nombre = VALUES(remitente_nombre),
                    verificar_ssl = VALUES(verificar_ssl),
                    activo = VALUES(activo)",
                'sisssissii',
                array(
                    $host,
                    $puerto,
                    $usuarioSmtp,
                    $passwordSmtp,
                    $cifrado,
                    $smtpAuth,
                    $remitenteEmail,
                    $remitenteNombre,
                    $verificarSsl,
                    $activo
                )
            );

            configuracion_json(array(
                'success' => true,
                'message' => 'Configuración de correo guardada.'
            ));
        }

        if ($action === 'save_2fa_config') {
            $csrfSeguridad = (string) ($_POST['security_csrf'] ?? '');
            if ($csrfSeguridad === '' || !hash_equals($configSecurityCsrf, $csrfSeguridad)) {
                throw new RuntimeException('La sesión de seguridad cambió. Recarga la página.');
            }

            if (!$vistaGlobalConfiguracion || !$esSuperAdministradorActual) {
                throw new RuntimeException(
                    'Solo el superadministrador puede modificar la política de verificación en dos pasos.'
                );
            }

            $activo2fa = isset($_POST['activo']) ? 1 : 0;
            $requerirSuper = isset($_POST['requerir_super_administrador']) ? 1 : 0;
            $requerirAdmin = isset($_POST['requerir_admin']) ? 1 : 0;
            $requerirRecepcion = isset($_POST['requerir_recepcionista']) ? 1 : 0;
            $requerirEntrenador = isset($_POST['requerir_entrenador']) ? 1 : 0;
            $permitirConfiable = isset($_POST['permitir_dispositivo_confiable']) ? 1 : 0;
            $diasConfiable = max(1, min(90, (int) ($_POST['dias_dispositivo_confiable'] ?? 30)));
            $maxIntentos = max(3, min(10, (int) ($_POST['max_intentos'] ?? 5)));
            $minutosBloqueo = max(1, min(120, (int) ($_POST['minutos_bloqueo'] ?? 15)));
            $emisor = trim((string) ($_POST['emisor'] ?? ''));

            if ($emisor === '') {
                $emisor = trim((string) ($config_corporativa['nombre'] ?? 'Gym System'));
            }

            configuracion_ejecutar(
                $conn,
                "UPDATE configuracion_2fa
                 SET activo = ?,
                     requerir_super_administrador = ?,
                     requerir_admin = ?,
                     requerir_recepcionista = ?,
                     requerir_entrenador = ?,
                     permitir_dispositivo_confiable = ?,
                     dias_dispositivo_confiable = ?,
                     max_intentos = ?,
                     minutos_bloqueo = ?,
                     emisor = ?
                 WHERE id = 1",
                'iiiiiiiiis',
                array(
                    $activo2fa,
                    $requerirSuper,
                    $requerirAdmin,
                    $requerirRecepcion,
                    $requerirEntrenador,
                    $permitirConfiable,
                    $diasConfiable,
                    $maxIntentos,
                    $minutosBloqueo,
                    $emisor
                )
            );

            configuracion_json(array(
                'success' => true,
                'message' => 'La política de verificación en dos pasos fue actualizada.'
            ));
        }

        if ($action === 'get_password_temporal_actual') {
            $csrfSeguridad = (string) (
                $_POST['security_csrf'] ?? ''
            );

            if (
                $csrfSeguridad === ''
                || !hash_equals(
                    $configSecurityCsrf,
                    $csrfSeguridad
                )
            ) {
                throw new RuntimeException(
                    'La sesión de seguridad cambió. Recarga la página.'
                );
            }

            if (!rol_es_administrativo($usuario_rol_real)) {
                throw new RuntimeException(
                    'No tienes permiso para consultar la contraseña temporal del sistema.'
                );
            }

            /*
             * La contraseña temporal del sistema se conserva cifrada y puede
             * recuperarse mediante password_temporal_get(). Se entrega solo
             * bajo petición autenticada y nunca se incrusta en el HTML inicial.
             */
            $passwordTemporalActual = generarPasswordTemporal($conn);

            header(
                'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
            );
            header('Pragma: no-cache');

            configuracion_json(array(
                'success' => true,
                'password_temporal' => $passwordTemporalActual,
                'configurada' => !empty($config_acceso['configured']),
                'message' => 'Contraseña temporal consultada.'
            ));
        }

        if ($action === 'save_password_temporal_config') {
            $csrfSeguridad = (string) (
                $_POST['security_csrf'] ?? ''
            );

            if (
                $csrfSeguridad === ''
                || !hash_equals(
                    $configSecurityCsrf,
                    $csrfSeguridad
                )
            ) {
                throw new RuntimeException(
                    'La sesión de seguridad cambió. Recarga la página.'
                );
            }

            if (!rol_es_administrativo($usuario_rol_real)) {
                throw new RuntimeException(
                    'No tienes permiso para modificar la contraseña temporal del sistema.'
                );
            }

            $passwordTemporal = password_temporal_validate(
                (string) ($_POST['password_temporal'] ?? ''),
                (string) (
                    $_POST['password_temporal_confirmacion'] ?? ''
                )
            );

            password_temporal_save(
                $conn,
                $passwordTemporal,
                $usuario_id
            );

            configuracion_json(array(
                'success' => true,
                'message' =>
                    'La contraseña temporal predeterminada del sistema fue actualizada. '
                    . 'Se aplicará a usuarios nuevos y a futuros restablecimientos.'
            ));
        }

        if ($action === 'save_cliente') {
            $id = (int) ($_POST['id'] ?? 0);
            $nombre = trim((string) ($_POST['nombre'] ?? ''));
            $apellido = trim((string) ($_POST['apellido'] ?? ''));
            $telefono = trim((string) ($_POST['telefono'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $estadoSolicitado = (string) ($_POST['estado'] ?? 'activo');

            if ($nombre === '' || $apellido === '') {
                throw new RuntimeException('Nombre y apellido son obligatorios.');
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('El correo del socio no es válido.');
            }

            if (!in_array($estadoSolicitado, array('activo', 'inactivo'), true)) {
                $estadoSolicitado = 'activo';
            }

            if ($id > 0) {
                if (
                    !$vistaGlobalConfiguracion
                    && !configuracion_cliente_en_sucursal(
                        $conn,
                        $id,
                        $sucursalIdConfiguracion
                    )
                ) {
                    throw new RuntimeException(
                        'El socio no pertenece a la sucursal activa.'
                    );
                }

                if ($vistaGlobalConfiguracion) {
                    configuracion_ejecutar(
                        $conn,
                        "UPDATE clientes
                         SET nombre = ?, apellido = ?, telefono = ?,
                             email = ?, estado = ?
                         WHERE id = ?",
                        'sssssi',
                        array(
                            $nombre,
                            $apellido,
                            $telefono,
                            $email,
                            $estadoSolicitado,
                            $id
                        )
                    );
                } else {
                    /*
                     * clientes.estado es general para toda la cuenta del socio.
                     * Desde una sede solo se actualizan datos personales para no
                     * desactivarlo accidentalmente en otras sucursales.
                     */
                    configuracion_ejecutar(
                        $conn,
                        "UPDATE clientes
                         SET nombre = ?, apellido = ?, telefono = ?, email = ?
                         WHERE id = ?",
                        'ssssi',
                        array($nombre, $apellido, $telefono, $email, $id)
                    );
                }
            } else {
                if ($vistaGlobalConfiguracion) {
                    throw new RuntimeException(
                        'Selecciona una sucursal antes de registrar al socio.'
                    );
                }

                configuracion_ejecutar(
                    $conn,
                    "INSERT INTO clientes
                        (
                            sucursal_registro_id,
                            nombre,
                            apellido,
                            telefono,
                            email,
                            estado
                        )
                     VALUES (?, ?, ?, ?, ?, ?)",
                    'isssss',
                    array(
                        $sucursalIdConfiguracion,
                        $nombre,
                        $apellido,
                        $telefono,
                        $email,
                        'activo'
                    )
                );
            }

            configuracion_json(array(
                'success' => true,
                'message' => 'Socio guardado correctamente.'
            ));
        }

        if ($action === 'delete_cliente') {
            if (!$vistaGlobalConfiguracion) {
                throw new RuntimeException(
                    'El estado del socio es general. Cambia a Todas las sucursales para desactivarlo.'
                );
            }

            $id = (int) ($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('El socio seleccionado no es válido.');
            }

            $activas = configuracion_contar(
                $conn,
                "SELECT COUNT(*) AS total
                 FROM inscripciones
                 WHERE cliente_id = ?
                   AND estado = 'activa'",
                'i',
                array($id)
            );

            if ($activas > 0) {
                throw new RuntimeException(
                    'No se puede desactivar un socio con membresías activas.'
                );
            }

            configuracion_ejecutar(
                $conn,
                "UPDATE clientes SET estado = 'inactivo' WHERE id = ?",
                'i',
                array($id)
            );

            configuracion_json(array(
                'success' => true,
                'message' => 'Socio desactivado correctamente.'
            ));
        }

        if ($action === 'save_plan') {
            $id = (int) ($_POST['id'] ?? 0);
            $precio = (float) ($_POST['precio'] ?? 0);
            $estado = (string) ($_POST['estado'] ?? 'activo');

            if (!in_array($estado, array('activo', 'inactivo'), true)) {
                $estado = 'activo';
            }

            if (!$vistaGlobalConfiguracion) {
                if ($id <= 0) {
                    throw new RuntimeException(
                        'Los planes nuevos se crean desde Todas las sucursales.'
                    );
                }

                configuracion_ejecutar(
                    $conn,
                    "INSERT INTO planes_sucursales
                        (sucursal_id, plan_id, precio, estado)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        precio = VALUES(precio),
                        estado = VALUES(estado)",
                    'iids',
                    array(
                        $sucursalIdConfiguracion,
                        $id,
                        $precio,
                        $estado
                    )
                );

                configuracion_json(array(
                    'success' => true,
                    'message' => 'Precio y disponibilidad actualizados para la sucursal.'
                ));
            }

            $nombre = trim((string) ($_POST['nombre'] ?? ''));
            $duracion = (int) ($_POST['duracion_dias'] ?? 0);
            $descripcion = trim((string) ($_POST['descripcion'] ?? ''));

            if ($nombre === '' || $duracion <= 0 || $precio < 0) {
                throw new RuntimeException(
                    'Nombre, duración y precio del plan son obligatorios.'
                );
            }

            $conn->begin_transaction();

            try {
                if ($id > 0) {
                    configuracion_ejecutar(
                        $conn,
                        "UPDATE planes
                         SET nombre = ?, duracion_dias = ?, precio = ?,
                             descripcion = ?, estado = ?
                         WHERE id = ?",
                        'sidssi',
                        array(
                            $nombre,
                            $duracion,
                            $precio,
                            $descripcion,
                            $estado,
                            $id
                        )
                    );

                    if ($estado === 'inactivo') {
                        configuracion_ejecutar(
                            $conn,
                            "UPDATE planes_sucursales
                             SET estado = 'inactivo'
                             WHERE plan_id = ?",
                            'i',
                            array($id)
                        );
                    }
                } else {
                    configuracion_ejecutar(
                        $conn,
                        "INSERT INTO planes
                            (nombre, duracion_dias, precio, descripcion, estado)
                         VALUES (?, ?, ?, ?, ?)",
                        'sidss',
                        array($nombre, $duracion, $precio, $descripcion, $estado)
                    );
                    $id = (int) $conn->insert_id;
                }

                $conn->commit();
            } catch (Throwable $errorPlan) {
                $conn->rollback();
                throw $errorPlan;
            }

            configuracion_json(array(
                'success' => true,
                'message' => 'Plan guardado en el catálogo general. La asignación a sucursales se administra de forma independiente.'
            ));
        }

        if ($action === 'delete_plan') {
            $id = (int) ($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('El plan seleccionado no es válido.');
            }

            if ($vistaGlobalConfiguracion) {
                configuracion_ejecutar(
                    $conn,
                    "UPDATE planes SET estado = 'inactivo' WHERE id = ?",
                    'i',
                    array($id)
                );
                configuracion_ejecutar(
                    $conn,
                    "UPDATE planes_sucursales
                     SET estado = 'inactivo'
                     WHERE plan_id = ?",
                    'i',
                    array($id)
                );
                $mensaje = 'Plan desactivado en todas las sucursales.';
            } else {
                configuracion_ejecutar(
                    $conn,
                    "UPDATE planes_sucursales
                     SET estado = 'inactivo'
                     WHERE sucursal_id = ? AND plan_id = ?",
                    'ii',
                    array($sucursalIdConfiguracion, $id)
                );
                $mensaje = 'Plan desactivado en la sucursal actual.';
            }

            configuracion_json(array('success' => true, 'message' => $mensaje));
        }

        if ($action === 'save_categoria' || $action === 'save_proveedor') {
            if (!$vistaGlobalConfiguracion) {
                throw new RuntimeException(
                    'Este catálogo es corporativo. Cambia a Todas las sucursales.'
                );
            }

            $id = (int) ($_POST['id'] ?? 0);
            $estado = (string) ($_POST['estado'] ?? 'activo');
            if (!in_array($estado, array('activo', 'inactivo'), true)) {
                $estado = 'activo';
            }

            if ($action === 'save_categoria') {
                $nombre = trim((string) ($_POST['nombre'] ?? ''));
                $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
                if ($nombre === '') {
                    throw new RuntimeException('El nombre de la categoría es obligatorio.');
                }

                if ($id > 0) {
                    configuracion_ejecutar(
                        $conn,
                        "UPDATE categorias_productos
                         SET nombre = ?, descripcion = ?, estado = ?
                         WHERE id = ?",
                        'sssi',
                        array($nombre, $descripcion, $estado, $id)
                    );
                } else {
                    configuracion_ejecutar(
                        $conn,
                        "INSERT INTO categorias_productos
                            (nombre, descripcion, estado)
                         VALUES (?, ?, ?)",
                        'sss',
                        array($nombre, $descripcion, $estado)
                    );
                }
                $mensaje = 'Categoría guardada.';
            } else {
                $nombre = trim((string) ($_POST['nombre'] ?? ''));
                $contacto = trim((string) ($_POST['contacto'] ?? ''));
                $telefono = trim((string) ($_POST['telefono'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));
                $direccion = trim((string) ($_POST['direccion'] ?? ''));

                if ($nombre === '') {
                    throw new RuntimeException('El nombre del proveedor es obligatorio.');
                }

                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('El correo del proveedor no es válido.');
                }

                if ($id > 0) {
                    configuracion_ejecutar(
                        $conn,
                        "UPDATE proveedores
                         SET nombre = ?, contacto = ?, telefono = ?,
                             email = ?, direccion = ?, estado = ?
                         WHERE id = ?",
                        'ssssssi',
                        array(
                            $nombre,
                            $contacto,
                            $telefono,
                            $email,
                            $direccion,
                            $estado,
                            $id
                        )
                    );
                } else {
                    configuracion_ejecutar(
                        $conn,
                        "INSERT INTO proveedores
                            (nombre, contacto, telefono, email, direccion, estado)
                         VALUES (?, ?, ?, ?, ?, ?)",
                        'ssssss',
                        array(
                            $nombre,
                            $contacto,
                            $telefono,
                            $email,
                            $direccion,
                            $estado
                        )
                    );
                }
                $mensaje = 'Proveedor guardado.';
            }

            configuracion_json(array('success' => true, 'message' => $mensaje));
        }

        if ($action === 'delete_categoria' || $action === 'delete_proveedor') {
            if (!$vistaGlobalConfiguracion) {
                throw new RuntimeException('La acción solo está disponible en la vista global.');
            }

            $id = (int) ($_POST['id'] ?? 0);
            $tabla = $action === 'delete_categoria'
                ? 'categorias_productos'
                : 'proveedores';

            if ($id <= 0) {
                throw new RuntimeException('El registro seleccionado no es válido.');
            }

            configuracion_ejecutar(
                $conn,
                "UPDATE {$tabla} SET estado = 'inactivo' WHERE id = ?",
                'i',
                array($id)
            );

            configuracion_json(array(
                'success' => true,
                'message' => 'Registro desactivado correctamente.'
            ));
        }

        if ($action === 'save_producto') {
            $id = (int) ($_POST['id'] ?? 0);
            $precioCompra = (float) ($_POST['precio_compra'] ?? 0);
            $precioVenta = (float) ($_POST['precio_venta'] ?? 0);
            $stock = (int) ($_POST['stock'] ?? 0);
            $stockMinimo = (int) ($_POST['stock_minimo'] ?? 0);
            $estado = (string) ($_POST['estado'] ?? 'activo');

            if (!in_array($estado, array('activo', 'inactivo'), true)) {
                $estado = 'activo';
            }

            if (!$vistaGlobalConfiguracion) {
                if ($id <= 0) {
                    throw new RuntimeException(
                        'Los productos nuevos se crean desde Todas las sucursales.'
                    );
                }

                configuracion_ejecutar(
                    $conn,
                    "INSERT INTO inventario_sucursales
                        (
                            sucursal_id,
                            producto_id,
                            precio_compra,
                            precio_venta,
                            stock,
                            stock_minimo,
                            estado
                        )
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        precio_compra = VALUES(precio_compra),
                        precio_venta = VALUES(precio_venta),
                        stock = VALUES(stock),
                        stock_minimo = VALUES(stock_minimo),
                        estado = VALUES(estado)",
                    'iiddiis',
                    array(
                        $sucursalIdConfiguracion,
                        $id,
                        $precioCompra,
                        $precioVenta,
                        $stock,
                        $stockMinimo,
                        $estado
                    )
                );

                configuracion_json(array(
                    'success' => true,
                    'message' => 'Inventario del producto actualizado en la sucursal.'
                ));
            }

            $nombre = trim((string) ($_POST['nombre'] ?? ''));
            $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
            $categoriaId = (int) ($_POST['categoria_id'] ?? 0);
            $proveedorId = (int) ($_POST['proveedor_id'] ?? 0);

            if ($nombre === '' || $categoriaId <= 0) {
                throw new RuntimeException('Nombre y categoría son obligatorios.');
            }

            $proveedorSql = $proveedorId > 0 ? $proveedorId : null;
            $conn->begin_transaction();

            try {
                if ($id > 0) {
                    configuracion_ejecutar(
                        $conn,
                        "UPDATE productos
                         SET nombre = ?, descripcion = ?, categoria_id = ?,
                             proveedor_id = ?, precio_compra = ?,
                             precio_venta = ?, stock_minimo = ?, estado = ?
                         WHERE id = ?",
                        'ssiiddisi',
                        array(
                            $nombre,
                            $descripcion,
                            $categoriaId,
                            $proveedorSql,
                            $precioCompra,
                            $precioVenta,
                            $stockMinimo,
                            $estado,
                            $id
                        )
                    );

                    if ($estado === 'inactivo') {
                        configuracion_ejecutar(
                            $conn,
                            "UPDATE inventario_sucursales
                             SET estado = 'inactivo'
                             WHERE producto_id = ?",
                            'i',
                            array($id)
                        );
                    }
                } else {
                    configuracion_ejecutar(
                        $conn,
                        "INSERT INTO productos
                            (
                                nombre, descripcion, categoria_id,
                                proveedor_id, precio_compra, precio_venta,
                                stock, stock_minimo, estado
                            )
                         VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)",
                        'ssiiddis',
                        array(
                            $nombre,
                            $descripcion,
                            $categoriaId,
                            $proveedorSql,
                            $precioCompra,
                            $precioVenta,
                            $stockMinimo,
                            $estado
                        )
                    );
                    $id = (int) $conn->insert_id;
                }

                $conn->commit();
            } catch (Throwable $errorProducto) {
                $conn->rollback();
                throw $errorProducto;
            }

            configuracion_json(array(
                'success' => true,
                'message' => 'Producto guardado en el catálogo corporativo.'
            ));
        }

        if ($action === 'delete_producto') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('El producto seleccionado no es válido.');
            }

            if ($vistaGlobalConfiguracion) {
                configuracion_ejecutar(
                    $conn,
                    "UPDATE productos SET estado = 'inactivo' WHERE id = ?",
                    'i',
                    array($id)
                );
                configuracion_ejecutar(
                    $conn,
                    "UPDATE inventario_sucursales
                     SET estado = 'inactivo'
                     WHERE producto_id = ?",
                    'i',
                    array($id)
                );
                $mensaje = 'Producto desactivado en todas las sucursales.';
            } else {
                configuracion_ejecutar(
                    $conn,
                    "UPDATE inventario_sucursales
                     SET estado = 'inactivo'
                     WHERE sucursal_id = ? AND producto_id = ?",
                    'ii',
                    array($sucursalIdConfiguracion, $id)
                );
                $mensaje = 'Producto desactivado en la sucursal actual.';
            }

            configuracion_json(array('success' => true, 'message' => $mensaje));
        }

        if ($action === 'save_clase') {
            if ($vistaGlobalConfiguracion) {
                throw new RuntimeException(
                    'Selecciona una sucursal antes de crear o editar una clase.'
                );
            }

            $id = (int) ($_POST['id'] ?? 0);
            $nombre = trim((string) ($_POST['nombre'] ?? ''));
            $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
            $horario = trim((string) ($_POST['horario'] ?? ''));
            $instructor = trim((string) ($_POST['instructor'] ?? ''));
            $cupo = (int) ($_POST['cupo_maximo'] ?? 20);
            $duracion = (int) ($_POST['duracion_minutos'] ?? 60);
            $estado = (string) ($_POST['estado'] ?? 'activa');

            if ($nombre === '' || $horario === '' || $instructor === '') {
                throw new RuntimeException(
                    'Nombre, horario e instructor son obligatorios.'
                );
            }

            if (!configuracion_instructor_valido(
                $conn,
                $instructor,
                $sucursalIdConfiguracion
            )) {
                throw new RuntimeException(
                    'El instructor debe ser un entrenador activo asignado a esta sucursal.'
                );
            }

            if (!in_array($estado, array('activa', 'inactiva'), true)) {
                $estado = 'activa';
            }

            if ($id > 0) {
                if (!configuracion_clase_en_sucursal(
                    $conn,
                    $id,
                    $sucursalIdConfiguracion
                )) {
                    throw new RuntimeException('La clase no pertenece a la sucursal activa.');
                }

                configuracion_ejecutar(
                    $conn,
                    "UPDATE clases
                     SET nombre = ?, descripcion = ?, horario = ?,
                         instructor = ?, cupo_maximo = ?,
                         duracion_minutos = ?, estado = ?
                     WHERE id = ? AND sucursal_id = ?",
                    'ssssiisii',
                    array(
                        $nombre,
                        $descripcion,
                        $horario,
                        $instructor,
                        $cupo,
                        $duracion,
                        $estado,
                        $id,
                        $sucursalIdConfiguracion
                    )
                );
            } else {
                configuracion_ejecutar(
                    $conn,
                    "INSERT INTO clases
                        (
                            sucursal_id, nombre, descripcion, horario,
                            instructor, cupo_maximo, duracion_minutos, estado
                        )
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    'issssiis',
                    array(
                        $sucursalIdConfiguracion,
                        $nombre,
                        $descripcion,
                        $horario,
                        $instructor,
                        $cupo,
                        $duracion,
                        $estado
                    )
                );
            }

            configuracion_json(array(
                'success' => true,
                'message' => 'Clase guardada en la sucursal activa.'
            ));
        }

        if ($action === 'delete_clase') {
            if ($vistaGlobalConfiguracion) {
                throw new RuntimeException('Selecciona una sucursal para modificar la clase.');
            }

            $id = (int) ($_POST['id'] ?? 0);
            if (!configuracion_clase_en_sucursal($conn, $id, $sucursalIdConfiguracion)) {
                throw new RuntimeException('La clase no pertenece a la sucursal activa.');
            }

            configuracion_ejecutar(
                $conn,
                "UPDATE clases
                 SET estado = 'inactiva'
                 WHERE id = ? AND sucursal_id = ?",
                'ii',
                array($id, $sucursalIdConfiguracion)
            );

            configuracion_json(array(
                'success' => true,
                'message' => 'Clase desactivada en la sucursal.'
            ));
        }

        if ($action === 'save_usuario') {
            $id = (int) ($_POST['id'] ?? 0);
            $nombre = trim((string) ($_POST['nombre'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $rol = rol_normalizar_sistema((string) (
                $_POST['rol'] ?? 'recepcionista'
            ));
            $estado = (string) ($_POST['estado'] ?? 'activo');
            $sucursalDestinoId = (int) (
                $_POST['sucursal_destino_id'] ?? 0
            );

            /*
             * Las cuentas superadministradoras permanecen protegidas.
             * Desde este catálogo únicamente se crean administradores,
             * recepcionistas y entrenadores.
             */
            if ($id > 0) {
                $cuentaProtegida = configuracion_fila(
                    $conn,
                    "SELECT rol
                     FROM usuarios
                     WHERE id = ?
                     LIMIT 1",
                    'i',
                    array($id)
                );

                if (
                    rol_normalizar_sistema((string) (
                        $cuentaProtegida['rol'] ?? ''
                    )) === 'super_administrador'
                ) {
                    throw new RuntimeException(
                        'Las cuentas superadministradoras están protegidas '
                        . 'y no se administran desde este catálogo.'
                    );
                }
            }

            if (
                $nombre === ''
                || !filter_var($email, FILTER_VALIDATE_EMAIL)
            ) {
                throw new RuntimeException(
                    'Nombre y correo válido son obligatorios.'
                );
            }

            $rolesPermitidos = array(
                'admin',
                'recepcionista',
                'entrenador'
            );

            if (!in_array($rol, $rolesPermitidos, true)) {
                throw new RuntimeException(
                    'El rol seleccionado no es válido.'
                );
            }

            if (!in_array(
                $estado,
                array('activo', 'inactivo'),
                true
            )) {
                $estado = 'activo';
            }

            /*
             * Alta desde Todas las sucursales:
             * - Administrador: acceso global a todas las sedes activas.
             * - Recepcionista/Entrenador: acceso únicamente a la sede elegida.
             */
            if ($vistaGlobalConfiguracion && $id === 0) {
                if (!$esSuperAdministradorActual) {
                    throw new RuntimeException(
                        'Solo el superadministrador puede crear cuentas '
                        . 'desde la vista de Todas las sucursales.'
                    );
                }

                $estado = 'activo';

                if ($rol !== 'admin') {
                    if ($sucursalDestinoId <= 0) {
                        throw new RuntimeException(
                            'Selecciona la sucursal donde trabajará el usuario.'
                        );
                    }

                    $sucursalDestino = configuracion_fila(
                        $conn,
                        "SELECT id, nombre
                         FROM sucursales
                         WHERE id = ?
                           AND estado = 'activa'
                         LIMIT 1",
                        'i',
                        array($sucursalDestinoId)
                    );

                    if (!$sucursalDestino) {
                        throw new RuntimeException(
                            'La sucursal seleccionada no existe o está inactiva.'
                        );
                    }
                }
            }

            $duplicado = configuracion_fila(
                $conn,
                "SELECT id
                 FROM usuarios
                 WHERE email = ?
                   AND id <> ?
                 LIMIT 1",
                'si',
                array($email, $id)
            );

            if ($duplicado) {
                throw new RuntimeException(
                    'Ya existe un usuario con ese correo.'
                );
            }

            if ($id > 0) {
                if (
                    $vistaGlobalConfiguracion
                    && $id === $usuario_id
                ) {
                    if ($rol !== 'admin' || $estado !== 'activo') {
                        throw new RuntimeException(
                            'No puedes desactivar ni retirar el rol '
                            . 'administrador de tu propia cuenta.'
                        );
                    }
                }

                if (
                    !$vistaGlobalConfiguracion
                    && $id === $usuario_id
                ) {
                    $asignacionPropia = configuracion_fila(
                        $conn,
                        "SELECT
                            COALESCE(rol_sucursal, 'admin')
                                AS rol_sucursal,
                            estado
                         FROM usuarios_sucursales
                         WHERE usuario_id = ?
                           AND sucursal_id = ?
                         LIMIT 1",
                        'ii',
                        array($id, $sucursalIdConfiguracion)
                    );

                    if (
                        !$asignacionPropia
                        || $estado !== 'activo'
                        || $rol !== (string) (
                            $asignacionPropia['rol_sucursal']
                            ?? ''
                        )
                    ) {
                        throw new RuntimeException(
                            'No puedes cambiar tu propio rol ni '
                            . 'suspender tu acceso a la sucursal activa.'
                        );
                    }
                }

                if (
                    !$vistaGlobalConfiguracion
                    && !configuracion_usuario_en_sucursal(
                        $conn,
                        $id,
                        $sucursalIdConfiguracion
                    )
                ) {
                    throw new RuntimeException(
                        'El usuario no está asignado a la sucursal activa.'
                    );
                }

                if ($vistaGlobalConfiguracion) {
                    $conn->begin_transaction();

                    try {
                        configuracion_ejecutar(
                            $conn,
                            "UPDATE usuarios
                             SET nombre = ?,
                                 email = ?,
                                 rol = ?,
                                 estado = ?
                             WHERE id = ?",
                            'ssssi',
                            array(
                                $nombre,
                                $email,
                                $rol,
                                $estado,
                                $id
                            )
                        );

                        configuracion_ejecutar(
                            $conn,
                            "UPDATE usuarios_sucursales
                             SET rol_sucursal = ?,
                                 estado = ?,
                                 puede_operar_caja = ?,
                                 updated_at = CURRENT_TIMESTAMP
                             WHERE usuario_id = ?",
                            'ssii',
                            array(
                                $rol,
                                $estado,
                                $rol === 'entrenador' ? 0 : 1,
                                $id
                            )
                        );

                        $conn->commit();
                    } catch (Throwable $errorUsuarioGlobal) {
                        $conn->rollback();
                        throw $errorUsuarioGlobal;
                    }
                } else {
                    $cuentaGlobal = configuracion_fila(
                        $conn,
                        "SELECT estado
                         FROM usuarios
                         WHERE id = ?
                         LIMIT 1",
                        'i',
                        array($id)
                    );

                    if (
                        $estado === 'activo'
                        && (
                            !$cuentaGlobal
                            || $cuentaGlobal['estado'] !== 'activo'
                        )
                    ) {
                        throw new RuntimeException(
                            'La cuenta está inactiva globalmente. '
                            . 'Actívala desde Todas las sucursales.'
                        );
                    }

                    $conn->begin_transaction();

                    try {
                        configuracion_ejecutar(
                            $conn,
                            "UPDATE usuarios
                             SET nombre = ?,
                                 email = ?
                             WHERE id = ?",
                            'ssi',
                            array($nombre, $email, $id)
                        );

                        configuracion_ejecutar(
                            $conn,
                            "UPDATE usuarios_sucursales
                             SET rol_sucursal = ?,
                                 estado = ?,
                                 puede_operar_caja = ?
                             WHERE usuario_id = ?
                               AND sucursal_id = ?",
                            'ssiii',
                            array(
                                $rol,
                                $estado,
                                $rol === 'entrenador' ? 0 : 1,
                                $id,
                                $sucursalIdConfiguracion
                            )
                        );

                        $conn->commit();
                    } catch (Throwable $errorUsuario) {
                        $conn->rollback();
                        throw $errorUsuario;
                    }
                }

                configuracion_json(array(
                    'success' => true,
                    'usuario_nuevo' => false,
                    'message' => $vistaGlobalConfiguracion
                        ? 'Usuario y rol actualizados en todas sus sucursales.'
                        : 'Usuario y acceso de sucursal actualizados.'
                ));
            }

            $passwordTemporal = generarPasswordTemporal($conn);
            $password = password_hash(
                $passwordTemporal,
                PASSWORD_DEFAULT
            );

            /*
             * Alta desde la vista global.
             * Un Administrador se asigna a todas las sucursales activas.
             * Recepcionista y Entrenador se asignan solo a la sede elegida.
             */
            if ($vistaGlobalConfiguracion) {
                if ($rol === 'admin') {
                    $sucursalPrincipal = configuracion_fila(
                        $conn,
                        "SELECT id
                         FROM sucursales
                         WHERE estado = 'activa'
                         ORDER BY es_matriz DESC, id ASC
                         LIMIT 1"
                    );

                    $sucursalPrincipalId = (int) (
                        $sucursalPrincipal['id'] ?? 0
                    );

                    if ($sucursalPrincipalId <= 0) {
                        throw new RuntimeException(
                            'No existe una sucursal activa para asignar '
                            . 'al nuevo administrador.'
                        );
                    }

                    $conn->begin_transaction();

                    try {
                        configuracion_ejecutar(
                            $conn,
                            "INSERT INTO usuarios
                                (
                                    nombre,
                                    email,
                                    password,
                                    rol,
                                    estado,
                                    password_change_required
                                )
                             VALUES (?, ?, ?, 'admin', 'activo', 1)",
                            'sss',
                            array($nombre, $email, $password)
                        );

                        $id = (int) $conn->insert_id;

                        configuracion_ejecutar(
                            $conn,
                            "INSERT INTO usuarios_sucursales
                                (
                                    usuario_id,
                                    sucursal_id,
                                    rol_sucursal,
                                    es_principal,
                                    puede_operar_caja,
                                    estado
                                )
                             SELECT
                                ?,
                                s.id,
                                'admin',
                                CASE WHEN s.id = ? THEN 1 ELSE 0 END,
                                1,
                                'activo'
                             FROM sucursales s
                             WHERE s.estado = 'activa'",
                            'ii',
                            array($id, $sucursalPrincipalId)
                        );

                        $sedesEsperadas = configuracion_contar(
                            $conn,
                            "SELECT COUNT(*) AS total
                             FROM sucursales
                             WHERE estado = 'activa'"
                        );

                        $sedesAsignadas = configuracion_contar(
                            $conn,
                            "SELECT COUNT(*) AS total
                             FROM usuarios_sucursales
                             WHERE usuario_id = ?
                               AND rol_sucursal = 'admin'
                               AND estado = 'activo'",
                            'i',
                            array($id)
                        );

                        if (
                            $sedesEsperadas <= 0
                            || $sedesAsignadas !== $sedesEsperadas
                        ) {
                            throw new RuntimeException(
                                'No fue posible completar la asignación global. '
                                . 'Se esperaban '
                                . $sedesEsperadas
                                . ' sucursal(es) y se registraron '
                                . $sedesAsignadas
                                . '.'
                            );
                        }

                        $conn->commit();
                    } catch (Throwable $errorNuevoAdministrador) {
                        $conn->rollback();
                        throw $errorNuevoAdministrador;
                    }

                    $correo = enviarCredencialesAcceso(
                        $conn,
                        $nombre,
                        $email,
                        $passwordTemporal,
                        'admin'
                    );

                    configuracion_json(array(
                        'success' => true,
                        'usuario_nuevo' => true,
                        'alcance_global' => true,
                        'rol_creado' => 'admin',
                        'sedes_asignadas' => $sedesAsignadas,
                        'correo_enviado' => $correo['enviado'],
                        'correo_error' => $correo['error'],
                        'password_temporal' => $correo['enviado']
                            ? ''
                            : $passwordTemporal,
                        'message' => $correo['enviado']
                            ? 'Administrador creado y asignado a '
                                . $sedesAsignadas
                                . ' sucursal(es). Las credenciales fueron enviadas.'
                            : 'Administrador creado y asignado a '
                                . $sedesAsignadas
                                . ' sucursal(es), pero el correo no pudo enviarse.'
                    ));
                }

                $sucursalDestino = configuracion_fila(
                    $conn,
                    "SELECT id, nombre
                     FROM sucursales
                     WHERE id = ?
                       AND estado = 'activa'
                     LIMIT 1",
                    'i',
                    array($sucursalDestinoId)
                );

                if (!$sucursalDestino) {
                    throw new RuntimeException(
                        'La sucursal seleccionada no existe o está inactiva.'
                    );
                }

                $puedeOperarCaja = $rol === 'entrenador' ? 0 : 1;

                $conn->begin_transaction();

                try {
                    configuracion_ejecutar(
                        $conn,
                        "INSERT INTO usuarios
                            (
                                nombre,
                                email,
                                password,
                                rol,
                                estado,
                                password_change_required
                            )
                         VALUES (?, ?, ?, ?, 'activo', 1)",
                        'ssss',
                        array($nombre, $email, $password, $rol)
                    );

                    $id = (int) $conn->insert_id;

                    configuracion_ejecutar(
                        $conn,
                        "INSERT INTO usuarios_sucursales
                            (
                                usuario_id,
                                sucursal_id,
                                rol_sucursal,
                                es_principal,
                                puede_operar_caja,
                                estado
                            )
                         VALUES (?, ?, ?, 1, ?, 'activo')",
                        'iisi',
                        array(
                            $id,
                            $sucursalDestinoId,
                            $rol,
                            $puedeOperarCaja
                        )
                    );

                    $conn->commit();
                } catch (Throwable $errorNuevoUsuarioGlobal) {
                    $conn->rollback();
                    throw $errorNuevoUsuarioGlobal;
                }

                $correo = enviarCredencialesAcceso(
                    $conn,
                    $nombre,
                    $email,
                    $passwordTemporal,
                    $rol
                );

                $rolTextoCreado = $rol === 'recepcionista'
                    ? 'Recepcionista'
                    : 'Entrenador';

                configuracion_json(array(
                    'success' => true,
                    'usuario_nuevo' => true,
                    'alcance_global' => false,
                    'rol_creado' => $rol,
                    'sucursal_asignada' => (string) $sucursalDestino['nombre'],
                    'sedes_asignadas' => 1,
                    'correo_enviado' => $correo['enviado'],
                    'correo_error' => $correo['error'],
                    'password_temporal' => $correo['enviado']
                        ? ''
                        : $passwordTemporal,
                    'message' => $correo['enviado']
                        ? $rolTextoCreado
                            . ' creado y asignado a '
                            . (string) $sucursalDestino['nombre']
                            . '. Las credenciales fueron enviadas.'
                        : $rolTextoCreado
                            . ' creado y asignado a '
                            . (string) $sucursalDestino['nombre']
                            . ', pero el correo no pudo enviarse.'
                ));
            }

            /* Alta local para recepcionistas, entrenadores o administradores. */
            $conn->begin_transaction();

            try {
                configuracion_ejecutar(
                    $conn,
                    "INSERT INTO usuarios
                        (
                            nombre,
                            email,
                            password,
                            rol,
                            estado,
                            password_change_required
                        )
                     VALUES (?, ?, ?, ?, 'activo', 1)",
                    'ssss',
                    array($nombre, $email, $password, $rol)
                );

                $id = (int) $conn->insert_id;

                configuracion_ejecutar(
                    $conn,
                    "INSERT INTO usuarios_sucursales
                        (
                            usuario_id,
                            sucursal_id,
                            rol_sucursal,
                            es_principal,
                            puede_operar_caja,
                            estado
                        )
                     VALUES (?, ?, ?, 1, ?, ?)",
                    'iisis',
                    array(
                        $id,
                        $sucursalIdConfiguracion,
                        $rol,
                        $rol === 'entrenador' ? 0 : 1,
                        $estado
                    )
                );

                $conn->commit();
            } catch (Throwable $errorNuevoUsuario) {
                $conn->rollback();
                throw $errorNuevoUsuario;
            }

            $correo = enviarCredencialesAcceso(
                $conn,
                $nombre,
                $email,
                $passwordTemporal,
                $rol
            );

            configuracion_json(array(
                'success' => true,
                'usuario_nuevo' => true,
                'correo_enviado' => $correo['enviado'],
                'correo_error' => $correo['error'],
                'password_temporal' => $correo['enviado']
                    ? ''
                    : $passwordTemporal,
                'message' => $correo['enviado']
                    ? 'Usuario creado, asignado a la sucursal y notificado.'
                    : 'Usuario creado y asignado, pero el correo no pudo enviarse.'
            ));
        }

        if ($action === 'delete_usuario') {
            $id = (int) ($_POST['id'] ?? 0);

            if ($id <= 0 || $id === $usuario_id) {
                throw new RuntimeException(
                    'No puedes retirar tu propio acceso desde esta pantalla.'
                );
            }

            $cuentaProtegida = configuracion_fila(
                $conn,
                "SELECT rol FROM usuarios WHERE id = ? LIMIT 1",
                'i',
                array($id)
            );

            if (
                rol_normalizar_sistema((string) ($cuentaProtegida['rol'] ?? ''))
                === 'super_administrador'
            ) {
                throw new RuntimeException(
                    'La cuenta principal está protegida.'
                );
            }

            if ($vistaGlobalConfiguracion) {
                if ($id === 1) {
                    throw new RuntimeException(
                        'El administrador principal no se puede desactivar.'
                    );
                }

                $conn->begin_transaction();
                try {
                    configuracion_ejecutar(
                        $conn,
                        "UPDATE usuarios SET estado = 'inactivo' WHERE id = ?",
                        'i',
                        array($id)
                    );
                    configuracion_ejecutar(
                        $conn,
                        "UPDATE usuarios_sucursales
                         SET estado = 'inactivo'
                         WHERE usuario_id = ?",
                        'i',
                        array($id)
                    );
                    $conn->commit();
                } catch (Throwable $errorEliminarUsuario) {
                    $conn->rollback();
                    throw $errorEliminarUsuario;
                }

                $mensaje = 'Usuario desactivado en todo el sistema.';
            } else {
                if (!configuracion_usuario_en_sucursal(
                    $conn,
                    $id,
                    $sucursalIdConfiguracion
                )) {
                    throw new RuntimeException('El usuario no pertenece a esta sucursal.');
                }

                configuracion_ejecutar(
                    $conn,
                    "UPDATE usuarios_sucursales
                     SET estado = 'inactivo'
                     WHERE usuario_id = ? AND sucursal_id = ?",
                    'ii',
                    array($id, $sucursalIdConfiguracion)
                );
                $mensaje = 'Acceso retirado únicamente de la sucursal actual.';
            }

            configuracion_json(array('success' => true, 'message' => $mensaje));
        }

        if ($action === 'reset_2fa') {
            $csrfSeguridad = (string) ($_POST['security_csrf'] ?? '');
            if ($csrfSeguridad === '' || !hash_equals($configSecurityCsrf, $csrfSeguridad)) {
                throw new RuntimeException('La sesión de seguridad cambió. Recarga la página.');
            }

            $id = (int) ($_POST['id'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('El usuario seleccionado no es válido.');
            }

            if ($id === $usuario_id) {
                throw new RuntimeException(
                    'Para cambiar tu propio autenticador utiliza Mi perfil > Seguridad.'
                );
            }

            if (
                !$vistaGlobalConfiguracion
                && !configuracion_usuario_en_sucursal(
                    $conn,
                    $id,
                    $sucursalIdConfiguracion
                )
            ) {
                throw new RuntimeException(
                    'El usuario no pertenece a la sucursal activa.'
                );
            }

            $usuario2faReset = configuracion_fila(
                $conn,
                "SELECT id, nombre, email, rol, estado
                 FROM usuarios
                 WHERE id = ?
                 LIMIT 1",
                'i',
                array($id)
            );

            if (!$usuario2faReset) {
                throw new RuntimeException('No se encontró el usuario.');
            }

            if (
                rol_normalizar_sistema((string) $usuario2faReset['rol'])
                    === 'super_administrador'
                && !$esSuperAdministradorActual
            ) {
                throw new RuntimeException(
                    'Solo el superadministrador puede restablecer la seguridad de esa cuenta.'
                );
            }

            $conn->begin_transaction();
            try {
                two_factor_revoke_devices($conn, $id);

                configuracion_ejecutar(
                    $conn,
                    "DELETE FROM usuarios_2fa WHERE usuario_id = ?",
                    'i',
                    array($id)
                );

                configuracion_ejecutar(
                    $conn,
                    "UPDATE usuarios
                     SET auth_version = auth_version + 1
                     WHERE id = ?",
                    'i',
                    array($id)
                );

                $conn->commit();
            } catch (Throwable $errorReset2fa) {
                $conn->rollback();
                throw $errorReset2fa;
            }

            two_factor_log_event(
                $conn,
                $id,
                '2fa_restaurado_admin',
                'La configuración 2FA fue restablecida por ' . $usuario_nombre . '.'
            );

            configuracion_json(array(
                'success' => true,
                'message' => 'La verificación en dos pasos fue restablecida. El usuario deberá configurarla en su próximo acceso.'
            ));
        }

        if ($action === 'cambiar_password') {
            $csrfSeguridad = (string) ($_POST['security_csrf'] ?? '');
            if ($csrfSeguridad === '' || !hash_equals($configSecurityCsrf, $csrfSeguridad)) {
                throw new RuntimeException('La sesión de seguridad cambió. Recarga la página.');
            }

            $id = (int) ($_POST['id'] ?? 0);

            if (
                !$vistaGlobalConfiguracion
                && !configuracion_usuario_en_sucursal(
                    $conn,
                    $id,
                    $sucursalIdConfiguracion
                )
            ) {
                throw new RuntimeException('El usuario no pertenece a la sucursal activa.');
            }

            $usuarioReset = configuracion_fila(
                $conn,
                "SELECT nombre, email, rol
                 FROM usuarios
                 WHERE id = ?
                   AND rol <> 'super_administrador'
                 LIMIT 1",
                'i',
                array($id)
            );

            if (!$usuarioReset) {
                throw new RuntimeException('No se encontró el usuario.');
            }

            $passwordTemporal = generarPasswordTemporal($conn);
            $password = password_hash($passwordTemporal, PASSWORD_DEFAULT);

            configuracion_ejecutar(
                $conn,
                "UPDATE usuarios
                 SET password = ?,
                     password_change_required = 1,
                     ultimo_cambio_password = NOW(),
                     auth_version = auth_version + 1
                 WHERE id = ?",
                'si',
                array($password, $id)
            );

            two_factor_revoke_devices($conn, $id);
            two_factor_log_event(
                $conn,
                $id,
                'password_restaurado_admin',
                'Un administrador restableció la contraseña y revocó los dispositivos confiables.'
            );

            $correo = enviarCredencialesAcceso(
                $conn,
                $usuarioReset['nombre'],
                $usuarioReset['email'],
                $passwordTemporal,
                $usuarioReset['rol']
            );

            configuracion_json(array(
                'success' => true,
                'correo_enviado' => $correo['enviado'],
                'correo_error' => $correo['error'],
                'password_temporal' => $correo['enviado']
                    ? ''
                    : $passwordTemporal
            ));
        }

        if ($action === 'get_registro') {
            $tabla = trim((string) ($_POST['tabla'] ?? ''));
            $id = (int) ($_POST['id'] ?? 0);
            $fila = null;

            if ($tabla === 'planes') {
                $fila = $vistaGlobalConfiguracion
                    ? configuracion_fila(
                        $conn,
                        "SELECT * FROM planes WHERE id = ? LIMIT 1",
                        'i',
                        array($id)
                    )
                    : configuracion_fila(
                        $conn,
                        "SELECT
                            p.id,
                            p.nombre,
                            p.duracion_dias,
                            COALESCE(ps.precio, p.precio) AS precio,
                            p.descripcion,
                            COALESCE(ps.estado, 'inactivo') AS estado,
                            CASE
                                WHEN ps.plan_id IS NULL THEN 0
                                ELSE 1
                            END AS asignado_sucursal
                         FROM planes p
                         LEFT JOIN planes_sucursales ps
                            ON ps.plan_id = p.id
                           AND ps.sucursal_id = ?
                         WHERE p.id = ?
                         LIMIT 1",
                        'ii',
                        array($sucursalIdConfiguracion, $id)
                    );
            } elseif ($tabla === 'productos') {
                $fila = $vistaGlobalConfiguracion
                    ? configuracion_fila(
                        $conn,
                        "SELECT
                            p.*,
                            COALESCE((
                                SELECT SUM(inv.stock)
                                FROM inventario_sucursales inv
                                INNER JOIN sucursales s_inv
                                    ON s_inv.id = inv.sucursal_id
                                   AND s_inv.estado = 'activa'
                                WHERE inv.producto_id = p.id
                            ), 0) AS stock
                         FROM productos p
                         WHERE p.id = ?
                         LIMIT 1",
                        'i',
                        array($id)
                    )
                    : configuracion_fila(
                        $conn,
                        "SELECT
                            p.id, p.nombre, p.descripcion,
                            p.categoria_id, p.proveedor_id,
                            inv.precio_compra, inv.precio_venta,
                            inv.stock, inv.stock_minimo, inv.estado
                         FROM productos p
                         INNER JOIN inventario_sucursales inv
                            ON inv.producto_id = p.id
                         WHERE p.id = ? AND inv.sucursal_id = ?
                         LIMIT 1",
                        'ii',
                        array($id, $sucursalIdConfiguracion)
                    );
            } elseif ($tabla === 'clases') {
                $fila = $vistaGlobalConfiguracion
                    ? configuracion_fila(
                        $conn,
                        "SELECT * FROM clases WHERE id = ? LIMIT 1",
                        'i',
                        array($id)
                    )
                    : configuracion_fila(
                        $conn,
                        "SELECT * FROM clases
                         WHERE id = ? AND sucursal_id = ?
                         LIMIT 1",
                        'ii',
                        array($id, $sucursalIdConfiguracion)
                    );
            } elseif ($tabla === 'usuarios') {
                $fila = $vistaGlobalConfiguracion
                    ? configuracion_fila(
                        $conn,
                        "SELECT *
                         FROM usuarios
                         WHERE id = ?
                           AND rol <> 'super_administrador'
                         LIMIT 1",
                        'i',
                        array($id)
                    )
                    : configuracion_fila(
                        $conn,
                        "SELECT
                            u.id, u.nombre, u.email,
                            COALESCE(us.rol_sucursal, u.rol) AS rol,
                            us.estado
                         FROM usuarios u
                         INNER JOIN usuarios_sucursales us
                            ON us.usuario_id = u.id
                         WHERE u.id = ?
                           AND us.sucursal_id = ?
                           AND u.rol <> 'super_administrador'
                         LIMIT 1",
                        'ii',
                        array($id, $sucursalIdConfiguracion)
                    );
            } elseif ($tabla === 'clientes') {
                if (
                    $vistaGlobalConfiguracion
                    || configuracion_cliente_en_sucursal(
                        $conn,
                        $id,
                        $sucursalIdConfiguracion
                    )
                ) {
                    $fila = configuracion_fila(
                        $conn,
                        "SELECT * FROM clientes WHERE id = ? LIMIT 1",
                        'i',
                        array($id)
                    );
                }
            } elseif (
                $vistaGlobalConfiguracion
                && in_array(
                    $tabla,
                    array('categorias_productos', 'proveedores'),
                    true
                )
            ) {
                $fila = configuracion_fila(
                    $conn,
                    "SELECT * FROM {$tabla} WHERE id = ? LIMIT 1",
                    'i',
                    array($id)
                );
            }

            configuracion_json($fila ?: array(), $fila ? 200 : 404);
        }

        throw new RuntimeException('La acción solicitada no es válida.');
    } catch (Throwable $errorAccion) {
        configuracion_json(array(
            'success' => false,
            'message' => $errorAccion->getMessage()
        ), 400);
    }
}

// Estadísticas y listados con el alcance actual.
/*
 * El administrador normal no puede descubrir cuentas
 * superadministradoras. Un superadministrador sí puede verlas en la
 * vista global, pero continúan protegidas contra edición accidental.
 */
$filtroSuperAdministradoresGlobal = $esSuperAdministradorActual
    ? ''
    : " AND rol <> 'super_administrador'";

$filtroSuperAdministradoresGlobalAlias = $esSuperAdministradorActual
    ? ''
    : " WHERE u.rol <> 'super_administrador'";

if ($vistaGlobalConfiguracion) {
    $total_clientes = array('total' => configuracion_contar(
        $conn,
        "SELECT COUNT(*) AS total FROM clientes WHERE estado = 'activo'"
    ));
    $total_planes = array('total' => configuracion_contar(
        $conn,
        "SELECT COUNT(*) AS total FROM planes WHERE estado = 'activo'"
    ));
    $total_productos = array('total' => configuracion_contar(
        $conn,
        "SELECT COUNT(*) AS total FROM productos WHERE estado = 'activo'"
    ));
    $total_usuarios = array('total' => configuracion_contar(
        $conn,
        "SELECT COUNT(*) AS total
         FROM usuarios
         WHERE estado = 'activo'"
         . $filtroSuperAdministradoresGlobal
    ));
    $total_clases = array('total' => configuracion_contar(
        $conn,
        "SELECT COUNT(*) AS total FROM clases WHERE estado = 'activa'"
    ));

    $clientes = $conn->query(
        "SELECT c.*, s.nombre AS sucursal_nombre, s.clave AS sucursal_clave
         FROM clientes c
         LEFT JOIN sucursales s ON s.id = c.sucursal_registro_id
         ORDER BY c.id DESC"
    );
    $planes = $conn->query(
        "SELECT
            p.*,
            (
                SELECT COUNT(*)
                FROM planes_sucursales ps
                WHERE ps.plan_id = p.id
                  AND ps.estado = 'activo'
            ) AS sedes_activas
         FROM planes p
         ORDER BY p.id"
    );
    $productos = $conn->query(
        "SELECT
            p.id, p.nombre, p.descripcion, p.categoria_id,
            p.proveedor_id, p.precio_compra, p.precio_venta,
            COALESCE((
                SELECT SUM(inv_stock.stock)
                FROM inventario_sucursales inv_stock
                INNER JOIN sucursales s_stock
                    ON s_stock.id = inv_stock.sucursal_id
                   AND s_stock.estado = 'activa'
                WHERE inv_stock.producto_id = p.id
            ), 0) AS stock,
            p.stock_minimo, p.estado, p.fecha_registro,
            c.nombre AS categoria_nombre,
            pr.nombre AS proveedor_nombre,
            (
                SELECT COUNT(*)
                FROM inventario_sucursales inv_estado
                WHERE inv_estado.producto_id = p.id
                  AND inv_estado.estado = 'activo'
            ) AS sedes_activas
         FROM productos p
         LEFT JOIN categorias_productos c ON c.id = p.categoria_id
         LEFT JOIN proveedores pr ON pr.id = p.proveedor_id
         ORDER BY p.id"
    );
    $clases = $conn->query(
        "SELECT c.*, s.nombre AS sucursal_nombre, s.clave AS sucursal_clave
         FROM clases c
         INNER JOIN sucursales s ON s.id = c.sucursal_id
         ORDER BY s.nombre, c.id"
    );
    $usuarios = $conn->query(
        "SELECT
            u.*,
            COALESCE(u2.enabled, 0) AS two_factor_enabled,
            u2.confirmed_at AS two_factor_confirmed_at,
            (
                SELECT COUNT(*)
                FROM usuarios_sucursales us
                WHERE us.usuario_id = u.id
                  AND us.estado = 'activo'
            ) AS sedes_activas
         FROM usuarios u
         LEFT JOIN usuarios_2fa u2 ON u2.usuario_id = u.id"
         . $filtroSuperAdministradoresGlobalAlias
         . " ORDER BY
                FIELD(
                    u.rol,
                    'super_administrador',
                    'admin',
                    'recepcionista',
                    'entrenador'
                ),
                u.nombre ASC"
    );
} else {
    $total_clientes = array('total' => configuracion_contar(
        $conn,
        "SELECT COUNT(DISTINCT c.id) AS total
         FROM clientes c
         WHERE c.estado = 'activo'
           AND (
                c.sucursal_registro_id = ?
                OR EXISTS (
                    SELECT 1
                    FROM inscripciones i
                    WHERE i.cliente_id = c.id
                      AND (
                          i.sucursal_id = ?
                          OR EXISTS (
                              SELECT 1
                              FROM inscripciones_sucursales isc
                              WHERE isc.inscripcion_id = i.id
                                AND isc.sucursal_id = ?
                          )
                      )
                )
           )",
        'iii',
        array(
            $sucursalIdConfiguracion,
            $sucursalIdConfiguracion,
            $sucursalIdConfiguracion
        )
    ));
    $total_planes = array('total' => configuracion_contar(
        $conn,
        "SELECT COUNT(*) AS total
         FROM planes_sucursales
         WHERE sucursal_id = ? AND estado = 'activo'",
        'i',
        array($sucursalIdConfiguracion)
    ));
    $total_productos = array('total' => configuracion_contar(
        $conn,
        "SELECT COUNT(*) AS total
         FROM inventario_sucursales
         WHERE sucursal_id = ? AND estado = 'activo'",
        'i',
        array($sucursalIdConfiguracion)
    ));
    $total_usuarios = array('total' => configuracion_contar(
        $conn,
        "SELECT COUNT(*) AS total
         FROM usuarios_sucursales us
         INNER JOIN usuarios u ON u.id = us.usuario_id
         WHERE us.sucursal_id = ?
           AND us.estado = 'activo'
           AND u.estado = 'activo'
           AND u.rol <> 'super_administrador'",
        'i',
        array($sucursalIdConfiguracion)
    ));
    $total_clases = array('total' => configuracion_contar(
        $conn,
        "SELECT COUNT(*) AS total
         FROM clases
         WHERE sucursal_id = ? AND estado = 'activa'",
        'i',
        array($sucursalIdConfiguracion)
    ));

    $clientesStmt = configuracion_preparar(
        $conn,
        "SELECT DISTINCT c.*, s.nombre AS sucursal_nombre,
                s.clave AS sucursal_clave
         FROM clientes c
         LEFT JOIN sucursales s ON s.id = c.sucursal_registro_id
         WHERE c.sucursal_registro_id = ?
            OR EXISTS (
                SELECT 1
                FROM inscripciones i
                WHERE i.cliente_id = c.id
                  AND (
                      i.sucursal_id = ?
                      OR EXISTS (
                          SELECT 1
                          FROM inscripciones_sucursales isc
                          WHERE isc.inscripcion_id = i.id
                            AND isc.sucursal_id = ?
                      )
                  )
            )
         ORDER BY c.id DESC",
        'iii',
        array(
            $sucursalIdConfiguracion,
            $sucursalIdConfiguracion,
            $sucursalIdConfiguracion
        )
    );
    $clientesStmt->execute();
    $clientes = $clientesStmt->get_result();

    $planesStmt = configuracion_preparar(
        $conn,
        "SELECT
            p.id,
            p.nombre,
            p.duracion_dias,
            COALESCE(ps.precio, p.precio) AS precio,
            p.descripcion,
            COALESCE(ps.estado, 'inactivo') AS estado,
            p.precio AS precio_base,
            p.estado AS estado_base,
            CASE
                WHEN ps.plan_id IS NULL THEN 0
                ELSE 1
            END AS asignado_sucursal
         FROM planes p
         LEFT JOIN planes_sucursales ps
            ON ps.plan_id = p.id
           AND ps.sucursal_id = ?
         ORDER BY
            CASE WHEN ps.plan_id IS NULL THEN 1 ELSE 0 END,
            p.id",
        'i',
        array($sucursalIdConfiguracion)
    );
    $planesStmt->execute();
    $planes = $planesStmt->get_result();

    $productosStmt = configuracion_preparar(
        $conn,
        "SELECT
            p.id, p.nombre, p.descripcion, p.categoria_id,
            p.proveedor_id, p.foto, p.fecha_registro,
            inv.precio_compra, inv.precio_venta,
            inv.stock, inv.stock_minimo, inv.estado,
            c.nombre AS categoria_nombre,
            pr.nombre AS proveedor_nombre
         FROM productos p
         INNER JOIN inventario_sucursales inv
            ON inv.producto_id = p.id
         LEFT JOIN categorias_productos c ON c.id = p.categoria_id
         LEFT JOIN proveedores pr ON pr.id = p.proveedor_id
         WHERE inv.sucursal_id = ?
         ORDER BY p.id",
        'i',
        array($sucursalIdConfiguracion)
    );
    $productosStmt->execute();
    $productos = $productosStmt->get_result();

    $clasesStmt = configuracion_preparar(
        $conn,
        "SELECT c.*, s.nombre AS sucursal_nombre, s.clave AS sucursal_clave
         FROM clases c
         INNER JOIN sucursales s ON s.id = c.sucursal_id
         WHERE c.sucursal_id = ?
         ORDER BY c.id",
        'i',
        array($sucursalIdConfiguracion)
    );
    $clasesStmt->execute();
    $clases = $clasesStmt->get_result();

    $usuariosStmt = configuracion_preparar(
        $conn,
        "SELECT
            u.id, u.nombre, u.email,
            COALESCE(us.rol_sucursal, u.rol) AS rol,
            CASE
                WHEN us.estado = 'activo' AND u.estado = 'activo'
                    THEN 'activo'
                ELSE 'inactivo'
            END AS estado,
            us.estado AS estado_sucursal,
            u.estado AS estado_global,
            u.password_change_required,
            u.fecha_registro,
            COALESCE(u2.enabled, 0) AS two_factor_enabled,
            u2.confirmed_at AS two_factor_confirmed_at,
            us.es_principal,
            us.puede_operar_caja,
            1 AS sedes_activas
         FROM usuarios_sucursales us
         INNER JOIN usuarios u ON u.id = us.usuario_id
         LEFT JOIN usuarios_2fa u2 ON u2.usuario_id = u.id
         WHERE us.sucursal_id = ?
           AND u.rol <> 'super_administrador'
         ORDER BY u.nombre",
        'i',
        array($sucursalIdConfiguracion)
    );
    $usuariosStmt->execute();
    $usuarios = $usuariosStmt->get_result();
}

$total_proveedores = array('total' => configuracion_contar(
    $conn,
    "SELECT COUNT(*) AS total FROM proveedores WHERE estado = 'activo'"
));
$total_categorias = array('total' => configuracion_contar(
    $conn,
    "SELECT COUNT(*) AS total FROM categorias_productos WHERE estado = 'activo'"
));

$categorias = $vistaGlobalConfiguracion
    ? $conn->query("SELECT * FROM categorias_productos ORDER BY id")
    : null;
$proveedores = $vistaGlobalConfiguracion
    ? $conn->query("SELECT * FROM proveedores ORDER BY id")
    : null;

$entrenadoresSucursal = array();
if (!$vistaGlobalConfiguracion) {
    $stmtEntrenadores = configuracion_preparar(
        $conn,
        "SELECT DISTINCT u.nombre
         FROM usuarios u
         INNER JOIN usuarios_sucursales us ON us.usuario_id = u.id
         WHERE us.sucursal_id = ?
           AND us.estado = 'activo'
           AND u.estado = 'activo'
           AND COALESCE(us.rol_sucursal, u.rol) = 'entrenador'
         ORDER BY u.nombre",
        'i',
        array($sucursalIdConfiguracion)
    );
    $stmtEntrenadores->execute();
    $resultadoEntrenadores = $stmtEntrenadores->get_result();
    while ($filaEntrenador = $resultadoEntrenadores->fetch_assoc()) {
        $entrenadoresSucursal[] = (string) $filaEntrenador['nombre'];
    }
    $stmtEntrenadores->close();
}

function getLastGitHubDateTime() {
    $cache_file = __DIR__ . '/cache/github_datetime.json';

    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 3600) {
        $cached = json_decode(file_get_contents($cache_file), true);
        if ($cached) return $cached['datetime'];
    }

    $url = "https://api.github.com/repos/Jesus3012/Sistema_gym/commits?per_page=1";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $data = json_decode($response, true);
        if (isset($data[0]['commit']['committer']['date'])) {
            $date = new DateTime($data[0]['commit']['committer']['date']);
            $date->setTimezone(new DateTimeZone('America/Mexico_City'));
            $formatted = $date->format('d/m/Y H:i:s');

            if (!file_exists(__DIR__ . '/cache')) mkdir(__DIR__ . '/cache', 0777, true);
            file_put_contents($cache_file, json_encode(['datetime' => $formatted]));
            return $formatted;
        }
    }

    return date('d/m/Y H:i:s');
}

$ultima_actualizacion = getLastGitHubDateTime();

/*
 * Contexto explícito de la vista.
 *
 * Aunque PHP comparte el alcance de las variables al ejecutar require,
 * los analizadores estáticos como Intelephense no siempre pueden
 * determinarlo. Al entregar los datos en un arreglo único, la vista puede
 * inicializar sus variables localmente y mantener autocompletado y tipos.
 */
$configuracionVista = array(
    'conn' => $conn,
    'usuario_id' => $usuario_id,
    'es_super_administrador' => $esSuperAdministradorActual,
    'vista_global' => $vistaGlobalConfiguracion,
    'sucursal_id' => $sucursalIdConfiguracion,
    'sucursal_nombre' => $sucursalNombreConfiguracion,
    'seccion' => $seccion,
    'config_gimnasio' => $config_gimnasio,
    'config_apariencia' => $config_apariencia,
    'config_correo' => $config_correo,
    'config_2fa' => $config_2fa,
    'config_acceso' => $config_acceso,
    'es_super_administrador' => $esSuperAdministradorActual,
    'security_csrf' => $configSecurityCsrf,
    'logo_path' => $logo_path,
    'logo_es_propio' => $logo_es_propio,
    'ultima_actualizacion' => $ultima_actualizacion,
    'total_clientes' => $total_clientes,
    'total_planes' => $total_planes,
    'total_productos' => $total_productos,
    'total_usuarios' => $total_usuarios,
    'total_clases' => $total_clases,
    'total_proveedores' => $total_proveedores,
    'total_categorias' => $total_categorias,
    'clientes' => $clientes,
    'planes' => $planes,
    'productos' => $productos,
    'categorias' => $categorias,
    'proveedores' => $proveedores,
    'clases' => $clases,
    'usuarios' => $usuarios,
    'entrenadores_sucursal' => $entrenadoresSucursal,
);

/*
 * La lógica del módulo termina aquí.
 * La interfaz se mantiene separada para facilitar mantenimiento.
 */
define('CONFIGURACION_MODULO_CARGADO', true);

/*
 * La interfaz continúa separada en configuracion_vista.php. Este módulo
 * únicamente ajusta el botón de alta cuando el superadministrador está
 * dentro de Todas las sucursales > Usuarios.
 */
ob_start();
require __DIR__ . '/includes/configuracion_vista.php';
$contenidoVistaConfiguracion = (string) ob_get_clean();

if (
    $vistaGlobalConfiguracion
    && $esSuperAdministradorActual
    && $seccion === 'usuarios'
) {
    $botonAdministradorGlobal = <<<'HTML'
<button
    type="button"
    class="btn btn-primary btn-sm"
    id="btnNuevoUsuarioGlobal"
    onclick="abrirAltaUsuarioGlobal()"
>
    <i class="fas fa-user-shield"></i>
    Nuevo usuario
</button>
HTML;

    $patronAvisoAlta = '#<span\s+class="config-action-hint">\s*'
        . '<i\s+class="fas\s+fa-location-dot"></i>\s*'
        . 'Elige una sucursal para crear y asignar\s*'
        . '</span>#u';

    $contenidoVistaConfiguracion = (string) preg_replace(
        $patronAvisoAlta,
        $botonAdministradorGlobal,
        $contenidoVistaConfiguracion,
        1
    );

    $sucursalesAltaGlobal = array();
    $resultadoSucursalesAlta = $conn->query(
        "SELECT id, nombre, clave, es_matriz
         FROM sucursales
         WHERE estado = 'activa'
         ORDER BY es_matriz DESC, nombre ASC, id ASC"
    );

    if ($resultadoSucursalesAlta instanceof mysqli_result) {
        while ($filaSucursalAlta = $resultadoSucursalesAlta->fetch_assoc()) {
            $sucursalesAltaGlobal[] = array(
                'id' => (int) $filaSucursalAlta['id'],
                'nombre' => (string) $filaSucursalAlta['nombre'],
                'clave' => (string) ($filaSucursalAlta['clave'] ?? ''),
                'es_matriz' => (int) ($filaSucursalAlta['es_matriz'] ?? 0)
            );
        }
    }

    $scriptAdministradorGlobal = '<script>window.CONFIG_SUCURSALES_ALTA_GLOBAL = '
        . json_encode(
            $sucursalesAltaGlobal,
            JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_APOS
            | JSON_HEX_AMP
            | JSON_HEX_QUOT
        )
        . ';</script>'
        . <<<'HTML'
<script>
(function () {
    'use strict';

    function obtenerModal(modalId) {
        if (!modalId) {
            return null;
        }

        return document.getElementById(String(modalId));
    }

    function existenModalesAbiertos() {
        return document.querySelector('.modal.active') !== null;
    }

    function actualizarBloqueoPagina() {
        document.body.classList.toggle(
            'modal-open',
            existenModalesAbiertos()
        );
    }

    function limpiarFormularioModal(modal) {
        if (!modal) {
            return;
        }

        const formulario = modal.querySelector('form');

        if (formulario && typeof formulario.reset === 'function') {
            formulario.reset();
        }

        const campoId = modal.querySelector('input[name="id"]');

        if (campoId) {
            campoId.value = '';
        }
    }

    function obtenerAlertasPorId(alertaId) {
        return Array.prototype.filter.call(
            document.querySelectorAll('[data-alerta-id]'),
            function (alerta) {
                return String(alerta.getAttribute('data-alerta-id'))
                    === String(alertaId);
            }
        );
    }

    function textoBotonAlerta(alertaId) {
        switch (String(alertaId)) {
            case 'info_gimnasio':
                return {
                    texto: 'Ver consejo',
                    icono: 'fa-lightbulb'
                };
            case 'usuario_info':
                return {
                    texto: 'Más información',
                    icono: 'fa-circle-info'
                };
            case 'admin_global':
                return {
                    texto: 'Ver alcance global',
                    icono: 'fa-building'
                };
            case 'logo_info':
                return {
                    texto: 'Mostrar recomendaciones',
                    icono: 'fa-image'
                };
            default:
                return {
                    texto: 'Mostrar información',
                    icono: 'fa-eye'
                };
        }
    }

    function crearBotonMostrarAlerta(alerta, alertaId) {
        if (!alerta || !alerta.parentNode) {
            return;
        }

        const siguiente = alerta.nextElementSibling;

        if (
            siguiente
            && siguiente.classList.contains('alert-boton-container')
        ) {
            return;
        }

        const datos = textoBotonAlerta(alertaId);
        const contenedor = document.createElement('div');
        const boton = document.createElement('button');

        contenedor.className = 'alert-boton-container';
        contenedor.setAttribute('data-alerta-boton', String(alertaId));

        boton.type = 'button';
        boton.className = 'btn-mostrar-alerta';
        boton.innerHTML =
            '<i class="fas ' + datos.icono + '"></i> ' +
            datos.texto;

        boton.addEventListener('click', function () {
            window.mostrarAlertaEspecifica(alertaId);
        });

        contenedor.appendChild(boton);
        alerta.parentNode.insertBefore(
            contenedor,
            alerta.nextSibling
        );
    }

    function guardarEstadoAlerta(alertaId, oculto) {
        try {
            if (oculto) {
                window.localStorage.setItem(
                    'alerta_oculta_' + alertaId,
                    'true'
                );
            } else {
                window.localStorage.removeItem(
                    'alerta_oculta_' + alertaId
                );
            }
        } catch (error) {
            // El módulo sigue funcionando aunque el navegador bloquee storage.
        }
    }

    function alertaEstaGuardadaComoOculta(alertaId) {
        try {
            return window.localStorage.getItem(
                'alerta_oculta_' + alertaId
            ) === 'true';
        } catch (error) {
            return false;
        }
    }

    window.ocultarAlerta = function (alertaId) {
        obtenerAlertasPorId(alertaId).forEach(function (alerta) {
            alerta.classList.add('oculto');
            crearBotonMostrarAlerta(alerta, alertaId);
        });

        guardarEstadoAlerta(alertaId, true);
    };

    window.mostrarAlertaEspecifica = function (alertaId) {
        obtenerAlertasPorId(alertaId).forEach(function (alerta) {
            alerta.classList.remove('oculto');

            const siguiente = alerta.nextElementSibling;

            if (
                siguiente
                && siguiente.classList.contains('alert-boton-container')
            ) {
                siguiente.remove();
            }
        });

        guardarEstadoAlerta(alertaId, false);
    };

    function aplicarEstadoGuardadoAlerta(alerta) {
        if (!alerta) {
            return;
        }

        const alertaId = alerta.getAttribute('data-alerta-id');

        if (alertaId && alertaEstaGuardadaComoOculta(alertaId)) {
            alerta.classList.add('oculto');
            crearBotonMostrarAlerta(alerta, alertaId);
        }
    }

    /*
     * Se definen controles propios sin depender de jQuery ni del script
     * anterior de la vista. De esta forma los botones Cerrar y Cancelar
     * siguen funcionando incluso si otro bloque JavaScript presenta un
     * error antes de inicializar sus eventos.
     */
    window.abrirModal = function (modalId) {
        const modal = obtenerModal(modalId);

        if (!modal) {
            return;
        }

        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        actualizarBloqueoPagina();
    };

    window.cerrarModal = function (modalId) {
        const modal = obtenerModal(modalId);

        if (!modal) {
            return;
        }

        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        limpiarFormularioModal(modal);
        actualizarBloqueoPagina();
    };

    function cerrarModalDesdeElemento(elemento) {
        const modal = elemento
            ? elemento.closest('.modal')
            : null;

        if (modal && modal.id) {
            window.cerrarModal(modal.id);
        }
    }

    document.addEventListener('click', function (evento) {
        const botonCerrar = evento.target.closest(
            '.modal-close, [data-modal-close]'
        );

        if (botonCerrar) {
            evento.preventDefault();
            evento.stopPropagation();
            cerrarModalDesdeElemento(botonCerrar);
            return;
        }

        const modalFondo = evento.target;

        if (
            modalFondo
            && modalFondo.classList
            && modalFondo.classList.contains('modal')
            && modalFondo.classList.contains('active')
        ) {
            window.cerrarModal(modalFondo.id);
        }
    });

    document.addEventListener('keydown', function (evento) {
        if (evento.key !== 'Escape') {
            return;
        }

        const abiertos = document.querySelectorAll('.modal.active');
        const ultimo = abiertos.length > 0
            ? abiertos[abiertos.length - 1]
            : null;

        if (ultimo && ultimo.id) {
            window.cerrarModal(ultimo.id);
        }
    });

    function encontrarTarjetaUsuarios() {
        const titulos = document.querySelectorAll('.card-title');

        for (const titulo of titulos) {
            const texto = titulo.textContent
                .trim()
                .toLowerCase();

            if (texto.includes('usuarios del sistema')) {
                return titulo.closest('.card');
            }
        }

        return null;
    }

    function asegurarBotonUsuarioGlobal() {
        if (document.getElementById('btnNuevoUsuarioGlobal')) {
            return;
        }

        const tarjeta = encontrarTarjetaUsuarios();

        if (!tarjeta) {
            return;
        }

        const herramientas = tarjeta.querySelector('.card-tools');

        if (!herramientas) {
            return;
        }

        const boton = document.createElement('button');
        boton.type = 'button';
        boton.id = 'btnNuevoUsuarioGlobal';
        boton.className = 'btn btn-primary btn-sm';
        boton.innerHTML =
            '<i class="fas fa-user-shield"></i> ' +
            'Nuevo usuario';
        boton.addEventListener('click', function () {
            window.abrirAltaUsuarioGlobal();
        });

        herramientas.innerHTML = '';
        herramientas.appendChild(boton);
    }

    function obtenerSucursalesAltaGlobal() {
        return Array.isArray(window.CONFIG_SUCURSALES_ALTA_GLOBAL)
            ? window.CONFIG_SUCURSALES_ALTA_GLOBAL
            : [];
    }

    function crearSelectorSucursalGlobal(formulario) {
        let grupo = document.getElementById('grupoSucursalDestinoGlobal');

        if (grupo) {
            return grupo;
        }

        const campoRol = formulario.querySelector('[name="rol"]');
        const grupoRol = campoRol ? campoRol.closest('.form-group') : null;

        grupo = document.createElement('div');
        grupo.id = 'grupoSucursalDestinoGlobal';
        grupo.className = 'form-group config-user-branch-group';
        grupo.hidden = true;

        const opciones = obtenerSucursalesAltaGlobal().map(function (sucursal) {
            const etiqueta = String(sucursal.nombre || 'Sucursal')
                + (Number(sucursal.es_matriz) === 1 ? ' · Matriz' : '')
                + (sucursal.clave ? ' (' + String(sucursal.clave) + ')' : '');

            return '<option value="' + Number(sucursal.id || 0) + '">' +
                escaparHtml(etiqueta) +
                '</option>';
        }).join('');

        grupo.innerHTML =
            '<label for="usuarioSucursalDestino">' +
                '<i class="fas fa-building"></i> Sucursal asignada' +
            '</label>' +
            '<select class="form-control" name="sucursal_destino_id" ' +
                'id="usuarioSucursalDestino">' +
                '<option value="">Selecciona una sucursal</option>' +
                opciones +
            '</select>' +
            '<small class="config-user-branch-help">' +
                'El usuario tendrá acceso únicamente a esta sucursal.' +
            '</small>';

        if (grupoRol && grupoRol.parentNode) {
            grupoRol.parentNode.insertBefore(grupo, grupoRol.nextSibling);
        } else {
            formulario.querySelector('.modal-body').appendChild(grupo);
        }

        return grupo;
    }

    function crearResumenAlcanceGlobal(formulario) {
        let resumen = document.getElementById('resumenAltaUsuarioGlobal');

        if (resumen) {
            return resumen;
        }

        resumen = document.createElement('div');
        resumen.id = 'resumenAltaUsuarioGlobal';
        resumen.className = 'admin-global-summary config-user-scope-summary';

        const campoEstado = formulario.querySelector('[name="estado"]');
        const grupoEstado = campoEstado
            ? campoEstado.closest('.form-group')
            : null;

        if (grupoEstado && grupoEstado.parentNode) {
            grupoEstado.parentNode.insertBefore(
                resumen,
                grupoEstado.nextSibling
            );
        } else {
            formulario.querySelector('.modal-body').appendChild(resumen);
        }

        return resumen;
    }

    function actualizarAlcanceUsuarioGlobal() {
        const formulario = document.getElementById('formUsuario');

        if (!formulario) {
            return;
        }

        const rol = formulario.querySelector('[name="rol"]');
        const grupoSucursal = crearSelectorSucursalGlobal(formulario);
        const selectorSucursal = grupoSucursal.querySelector('select');
        const resumen = crearResumenAlcanceGlobal(formulario);
        const rolActual = rol ? String(rol.value || 'admin') : 'admin';

        grupoSucursal.hidden = rolActual === 'admin';

        if (selectorSucursal) {
            selectorSucursal.required = rolActual !== 'admin';

            if (rolActual === 'admin') {
                selectorSucursal.value = '';
            }
        }

        let icono = 'fa-user-shield';
        let titulo = 'Cuenta administrativa global';
        let descripcion =
            'Se asignará automáticamente a todas las sucursales activas ' +
            'con permisos de Administrador.';
        let detalle =
            '<span><i class="fas fa-layer-group"></i> ' +
            'Acceso a todas las sucursales activas</span>';
        let claseRol = 'is-admin';

        if (rolActual === 'recepcionista') {
            icono = 'fa-user-check';
            titulo = 'Cuenta de recepción';
            descripcion =
                'Se asignará únicamente a la sucursal seleccionada ' +
                'con permisos de Recepcionista.';
            detalle =
                '<span><i class="fas fa-cash-register"></i> ' +
                'Puede operar caja en la sede asignada</span>';
            claseRol = 'is-reception';
        } else if (rolActual === 'entrenador') {
            icono = 'fa-dumbbell';
            titulo = 'Cuenta de entrenador';
            descripcion =
                'Se asignará únicamente a la sucursal seleccionada ' +
                'con permisos de Entrenador.';
            detalle =
                '<span><i class="fas fa-ban"></i> ' +
                'No tendrá permisos para operar caja</span>';
            claseRol = 'is-trainer';
        }

        resumen.className =
            'admin-global-summary config-user-scope-summary ' + claseRol;
        resumen.innerHTML =
            '<div class="admin-global-summary__icon">' +
                '<i class="fas ' + icono + '"></i>' +
            '</div>' +
            '<div class="admin-global-summary__content">' +
                '<strong>' + titulo + '</strong>' +
                '<p>' + descripcion + '</p>' +
                '<div class="admin-global-summary__details">' +
                    detalle +
                    '<span><i class="fas fa-key"></i> ' +
                    'Usará la contraseña temporal configurada para este sistema</span>' +
                    '<span><i class="fas fa-envelope"></i> ' +
                    'Las credenciales se enviarán al correo indicado</span>' +
                '</div>' +
            '</div>';
    }

    window.abrirAltaUsuarioGlobal = function () {
        const modal = document.getElementById('modalUsuario');
        const formulario = document.getElementById('formUsuario');

        if (!modal || !formulario) {
            return;
        }

        formulario.reset();

        const id = formulario.querySelector('[name="id"]');
        const rol = formulario.querySelector('[name="rol"]');
        const estado = formulario.querySelector('[name="estado"]');
        const titulo = modal.querySelector('.modal-header h4');
        const cuerpo = modal.querySelector('.modal-body');

        if (id) {
            id.value = '';
        }

        if (rol) {
            rol.value = 'admin';
        }

        if (estado) {
            estado.value = 'activo';
        }

        if (titulo) {
            titulo.innerHTML =
                '<i class="fas fa-user-plus"></i> Nuevo usuario';
        }

        if (cuerpo) {
            cuerpo.querySelectorAll('[data-alerta-id="usuario_info"]')
                .forEach(function (alerta) {
                    const siguiente = alerta.nextElementSibling;

                    if (
                        siguiente
                        && siguiente.classList.contains('alert-boton-container')
                    ) {
                        siguiente.remove();
                    }

                    alerta.remove();
                });
        }

        const grupoSucursal = crearSelectorSucursalGlobal(formulario);
        const selectorSucursal = grupoSucursal.querySelector('select');

        if (selectorSucursal) {
            selectorSucursal.value = '';
        }

        crearResumenAlcanceGlobal(formulario);
        actualizarAlcanceUsuarioGlobal();

        if (rol && rol.dataset.alcanceListener !== 'true') {
            rol.dataset.alcanceListener = 'true';
            rol.addEventListener('change', actualizarAlcanceUsuarioGlobal);
        }

        if (typeof window.abrirModal === 'function') {
            window.abrirModal('modalUsuario');
        } else {
            modal.classList.add('active');
        }
    };

    const formulario = document.getElementById('formUsuario');

    function escaparHtml(valor) {
        const elemento = document.createElement('div');
        elemento.textContent = String(valor || '');
        return elemento.innerHTML;
    }

    function textoRolRespuesta(respuesta) {
        const rol = String(respuesta.rol_creado || 'usuario');

        if (rol === 'admin') {
            return 'Administrador';
        }

        if (rol === 'recepcionista') {
            return 'Recepcionista';
        }

        if (rol === 'entrenador') {
            return 'Entrenador';
        }

        return 'Usuario';
    }

    function mostrarResultadoAltaUsuario(respuesta) {
        window.cerrarModal('modalUsuario');

        const rolTexto = textoRolRespuesta(respuesta);
        const esGlobal = Boolean(respuesta.alcance_global);
        const sucursal = String(respuesta.sucursal_asignada || '');
        const alcance = esGlobal
            ? 'Se asignó a ' + String(respuesta.sedes_asignadas || 0) +
                ' sucursal(es).'
            : 'Se asignó a la sucursal ' + sucursal + '.';

        if (typeof window.Swal === 'undefined') {
            let mensaje = rolTexto + ' creado. ' + alcance;

            if (!respuesta.correo_enviado) {
                mensaje += ' Contraseña temporal: ' +
                    String(respuesta.password_temporal || 'No disponible') +
                    '. Error de correo: ' +
                    String(respuesta.correo_error || 'No especificado.');
            }

            window.alert(mensaje);
            window.location.reload();
            return;
        }

        if (respuesta.correo_enviado) {
            window.Swal.fire({
                icon: 'success',
                title: rolTexto + ' creado',
                text: alcance +
                    ' Las credenciales fueron enviadas al correo registrado.',
                confirmButtonText: 'Aceptar',
                target: document.body
            }).then(function () {
                window.location.reload();
            });

            return;
        }

        window.Swal.fire({
            icon: 'warning',
            title: rolTexto + ' creado sin correo',
            html:
                '<p>La cuenta sí fue creada. ' + escaparHtml(alcance) + '</p>' +
                '<p><strong>Contraseña temporal:</strong> ' +
                '<code>' +
                escaparHtml(respuesta.password_temporal || 'No disponible') +
                '</code></p>' +
                '<p><strong>Error al enviar:</strong><br>' +
                escaparHtml(
                    respuesta.correo_error ||
                    'El servidor SMTP no devolvió un detalle.'
                ) +
                '</p>',
            confirmButtonText: 'Aceptar',
            target: document.body
        }).then(function () {
            window.location.reload();
        });
    }

    function enviarAltaUsuarioGlobal(evento) {
        if (!formulario) {
            return;
        }

        const campoId = formulario.querySelector('[name="id"]');

        if (campoId && campoId.value.trim() !== '') {
            return;
        }

        evento.preventDefault();
        evento.stopImmediatePropagation();

        const rol = formulario.querySelector('[name="rol"]');
        const estado = formulario.querySelector('[name="estado"]');
        const sucursal = formulario.querySelector(
            '[name="sucursal_destino_id"]'
        );
        const botonGuardar = formulario.querySelector(
            'button[type="submit"], input[type="submit"]'
        );
        const rolActual = rol ? String(rol.value || '') : '';

        if (!['admin', 'recepcionista', 'entrenador'].includes(rolActual)) {
            window.Swal.fire({
                icon: 'error',
                title: 'Rol no válido',
                text: 'Selecciona un rol válido para continuar.',
                target: document.body
            });
            return;
        }

        if (
            rolActual !== 'admin'
            && (!sucursal || String(sucursal.value || '') === '')
        ) {
            window.Swal.fire({
                icon: 'warning',
                title: 'Selecciona una sucursal',
                text:
                    'Recepcionistas y entrenadores deben asignarse a una ' +
                    'sucursal específica.',
                target: document.body
            });
            return;
        }

        if (estado) {
            estado.value = 'activo';
        }

        if (botonGuardar) {
            botonGuardar.disabled = true;
        }

        const datos = new FormData(formulario);
        datos.set('action', 'save_usuario');
        datos.set('rol', rolActual);
        datos.set('estado', 'activo');

        if (rolActual === 'admin') {
            datos.delete('sucursal_destino_id');
        }

        const rolTexto = rolActual === 'admin'
            ? 'administrador'
            : (rolActual === 'recepcionista'
                ? 'recepcionista'
                : 'entrenador');

        if (typeof window.Swal !== 'undefined') {
            window.Swal.fire({
                title: 'Creando ' + rolTexto + '...',
                text: rolActual === 'admin'
                    ? 'Se asignará a todas las sucursales activas.'
                    : 'Se asignará únicamente a la sucursal seleccionada.',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: function () {
                    window.Swal.showLoading();
                },
                target: document.body
            });
        }

        window.fetch(
            'configuracion.php?vista=global&section=usuarios',
            {
                method: 'POST',
                body: datos,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }
        )
            .then(function (respuestaHttp) {
                return respuestaHttp.text().then(function (texto) {
                    let respuestaJson;

                    try {
                        respuestaJson = JSON.parse(texto);
                    } catch (errorJson) {
                        throw new Error(
                            'El servidor devolvió una respuesta no válida. ' +
                            texto.slice(0, 300)
                        );
                    }

                    if (!respuestaHttp.ok || !respuestaJson.success) {
                        throw new Error(
                            respuestaJson.message ||
                            'No fue posible crear el usuario.'
                        );
                    }

                    return respuestaJson;
                });
            })
            .then(function (respuestaJson) {
                mostrarResultadoAltaUsuario(respuestaJson);
            })
            .catch(function (error) {
                if (typeof window.Swal !== 'undefined') {
                    window.Swal.fire({
                        icon: 'error',
                        title: 'No se completó el alta',
                        text: error.message || 'Ocurrió un error inesperado.',
                        confirmButtonText: 'Aceptar',
                        target: document.body
                    });
                } else {
                    window.alert(
                        error.message || 'Ocurrió un error inesperado.'
                    );
                }
            })
            .finally(function () {
                if (botonGuardar) {
                    botonGuardar.disabled = false;
                }
            });
    }

    if (formulario) {
        formulario.addEventListener(
            'submit',
            enviarAltaUsuarioGlobal,
            true
        );
    }

    function inicializarControlesConfiguracion() {
        asegurarBotonUsuarioGlobal();

        document.querySelectorAll('.alert-ocultable')
            .forEach(function (alerta) {
                aplicarEstadoGuardadoAlerta(alerta);
            });

        document.querySelectorAll('.modal')
            .forEach(function (modal) {
                if (!modal.classList.contains('active')) {
                    modal.setAttribute('aria-hidden', 'true');
                }
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            inicializarControlesConfiguracion
        );
    } else {
        inicializarControlesConfiguracion();
    }
})();
</script>
HTML;

    $posicionCierreBody = strrpos(
        $contenidoVistaConfiguracion,
        '</body>'
    );

    if ($posicionCierreBody !== false) {
        $contenidoVistaConfiguracion = substr_replace(
            $contenidoVistaConfiguracion,
            $scriptAdministradorGlobal . "\n",
            $posicionCierreBody,
            0
        );
    } else {
        $contenidoVistaConfiguracion .= $scriptAdministradorGlobal;
    }
}

echo $contenidoVistaConfiguracion;
