<?php
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/sucursal_context.php';
require_once __DIR__ . '/includes/super_admin_helper.php';
require_once __DIR__ . '/includes/configuracion_context.php';
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

if (!$vistaGlobalConfiguracion) {
    try {
        configuracion_sincronizar_catalogos(
            $conn,
            $sucursalIdConfiguracion
        );
    } catch (Throwable $errorSincronizacion) {
        error_log(
            '[Configuración sincronización] '
            . $errorSincronizacion->getMessage()
        );
    }
}

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
 * Contraseña temporal inicial utilizada por el sistema.
 * El indicador password_change_required obliga al usuario a cambiarla.
 */
function generarPasswordTemporal()
{
    return 'ego1';
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


// Resolver secciones disponibles según el contexto.
$seccionesGlobales = array(
    'general',
    'correo',
    'clientes',
    'planes',
    'productos',
    'categorias',
    'proveedores',
    'clases',
    'usuarios'
);
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

                configuracion_sincronizar_todas($conn);
                $conn->commit();
            } catch (Throwable $errorPlan) {
                $conn->rollback();
                throw $errorPlan;
            }

            configuracion_json(array(
                'success' => true,
                'message' => 'Plan guardado en el catálogo corporativo.'
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

                configuracion_sincronizar_todas($conn);
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
             * En la vista Todas las sucursales el alta nueva es
             * exclusivamente para un Administrador global. Solo una cuenta
             * superadministradora puede realizar esta operación.
             */
            if ($vistaGlobalConfiguracion && $id === 0) {
                if (!$esSuperAdministradorActual) {
                    throw new RuntimeException(
                        'Solo un superadministrador puede crear un '
                        . 'administrador para todas las sucursales.'
                    );
                }

                if ($rol !== 'admin') {
                    throw new RuntimeException(
                        'Desde Todas las sucursales únicamente se puede '
                        . 'crear un Administrador.'
                    );
                }

                $estado = 'activo';
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

            $passwordTemporal = generarPasswordTemporal();
            $password = password_hash(
                $passwordTemporal,
                PASSWORD_DEFAULT
            );

            /*
             * Alta de Administrador global:
             * - usuarios.rol = admin
             * - rol_sucursal = admin
             * - acceso a todas las sucursales activas
             * - la matriz queda como sede principal
             */
            if ($vistaGlobalConfiguracion) {
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
                         VALUES (
                            ?,
                            ?,
                            ?,
                            'admin',
                            'activo',
                            1
                         )",
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

                    /*
                     * configuracion_ejecutar() cierra el statement antes de
                     * regresar. Por eso $conn->affected_rows puede volver a
                     * cero aunque el INSERT ... SELECT sí haya funcionado.
                     * La verificación correcta se hace consultando las
                     * relaciones guardadas dentro de la misma transacción.
                     */
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
                    'administrador_global' => true,
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

        if ($action === 'cambiar_password') {
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

            $passwordTemporal = generarPasswordTemporal();
            $password = password_hash($passwordTemporal, PASSWORD_DEFAULT);

            configuracion_ejecutar(
                $conn,
                "UPDATE usuarios
                 SET password = ?, password_change_required = 1,
                     ultimo_cambio_password = NOW()
                 WHERE id = ?",
                'si',
                array($password, $id)
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
                            p.id, p.nombre, p.duracion_dias,
                            ps.precio, p.descripcion, ps.estado
                         FROM planes p
                         INNER JOIN planes_sucursales ps
                            ON ps.plan_id = p.id
                         WHERE p.id = ? AND ps.sucursal_id = ?
                         LIMIT 1",
                        'ii',
                        array($id, $sucursalIdConfiguracion)
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
            (
                SELECT COUNT(*)
                FROM usuarios_sucursales us
                WHERE us.usuario_id = u.id
                  AND us.estado = 'activo'
            ) AS sedes_activas
         FROM usuarios u"
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
            p.id, p.nombre, p.duracion_dias,
            ps.precio, p.descripcion, ps.estado,
            p.precio AS precio_base, p.estado AS estado_base
         FROM planes p
         INNER JOIN planes_sucursales ps ON ps.plan_id = p.id
         WHERE ps.sucursal_id = ?
         ORDER BY p.id",
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
            us.es_principal,
            us.puede_operar_caja,
            1 AS sedes_activas
         FROM usuarios_sucursales us
         INNER JOIN usuarios u ON u.id = us.usuario_id
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
    'config_correo' => $config_correo,
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
    id="btnNuevoAdministradorGlobal"
    onclick="abrirAltaAdministradorGlobal()"
>
    <i class="fas fa-user-shield"></i>
    Nuevo administrador
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

    $scriptAdministradorGlobal = <<<'HTML'
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

    function asegurarBotonAdministrador() {
        if (document.getElementById('btnNuevoAdministradorGlobal')) {
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
        boton.id = 'btnNuevoAdministradorGlobal';
        boton.className = 'btn btn-primary btn-sm';
        boton.innerHTML =
            '<i class="fas fa-user-shield"></i> ' +
            'Nuevo administrador';
        boton.addEventListener('click', function () {
            window.abrirAltaAdministradorGlobal();
        });

        herramientas.innerHTML = '';
        herramientas.appendChild(boton);
    }

    window.abrirAltaAdministradorGlobal = function () {
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
                '<i class="fas fa-user-shield"></i> ' +
                'Nuevo administrador';
        }

        if (cuerpo) {
            /*
             * El modal original trae un aviso ocultable sobre la contraseña.
             * En el alta global se sustituye junto con el aviso anterior por
             * un único resumen compacto y permanente.
             */
            ['usuario_info', 'admin_global'].forEach(function (alertaId) {
                cuerpo.querySelectorAll(
                    '[data-alerta-id="' + alertaId + '"]'
                ).forEach(function (alerta) {
                    const siguiente = alerta.nextElementSibling;

                    if (
                        siguiente
                        && siguiente.classList.contains(
                            'alert-boton-container'
                        )
                    ) {
                        siguiente.remove();
                    }

                    alerta.remove();
                });

                cuerpo.querySelectorAll(
                    '[data-alerta-boton="' + alertaId + '"]'
                ).forEach(function (botonAviso) {
                    botonAviso.remove();
                });
            });

            let resumen = document.getElementById(
                'resumenAltaAdministradorGlobal'
            );

            if (!resumen) {
                resumen = document.createElement('div');
                resumen.id = 'resumenAltaAdministradorGlobal';
                resumen.className = 'admin-global-summary';
                resumen.innerHTML =
                    '<div class="admin-global-summary__icon">' +
                        '<i class="fas fa-user-shield"></i>' +
                    '</div>' +
                    '<div class="admin-global-summary__content">' +
                        '<strong>Cuenta administrativa global</strong>' +
                        '<p>Se asignará automáticamente a todas las ' +
                        'sucursales activas con permisos de Administrador.</p>' +
                        '<div class="admin-global-summary__details">' +
                            '<span><i class="fas fa-key"></i> ' +
                            'Contraseña temporal: <b>ego1</b></span>' +
                            '<span><i class="fas fa-envelope"></i> ' +
                            'Las credenciales se enviarán al correo indicado</span>' +
                        '</div>' +
                    '</div>';

                const campoEstado = formulario.querySelector(
                    '[name="estado"]'
                );
                const grupoEstado = campoEstado
                    ? campoEstado.closest('.form-group')
                    : null;

                if (grupoEstado && grupoEstado.parentNode) {
                    grupoEstado.parentNode.insertBefore(
                        resumen,
                        grupoEstado.nextSibling
                    );
                } else {
                    cuerpo.appendChild(resumen);
                }
            }
        }

        if (typeof window.abrirModal === 'function') {
            window.abrirModal('modalUsuario');
        } else {
            modal.classList.add('active');
        }

        window.setTimeout(function () {
            if (rol) {
                rol.value = 'admin';
            }

            if (estado) {
                estado.value = 'activo';
            }
        }, 0);
    };

    const formulario = document.getElementById('formUsuario');

    function escaparHtml(valor) {
        const elemento = document.createElement('div');
        elemento.textContent = String(valor || '');
        return elemento.innerHTML;
    }

    function mostrarResultadoAltaAdministrador(respuesta) {
        window.cerrarModal('modalUsuario');

        if (typeof window.Swal === 'undefined') {
            const mensaje = respuesta.correo_enviado
                ? 'Administrador creado. Las credenciales fueron enviadas.'
                : 'Administrador creado. Contraseña temporal: ' +
                    String(respuesta.password_temporal || 'ego1') +
                    '. Error de correo: ' +
                    String(respuesta.correo_error || 'No especificado.');

            window.alert(mensaje);
            window.location.reload();
            return;
        }

        if (respuesta.correo_enviado) {
            window.Swal.fire({
                icon: 'success',
                title: 'Administrador creado',
                text:
                    'La cuenta fue asignada a ' +
                    String(respuesta.sedes_asignadas || 0) +
                    ' sucursal(es) y las credenciales fueron enviadas al correo registrado.',
                confirmButtonText: 'Aceptar',
                target: document.body
            }).then(function () {
                window.location.reload();
            });

            return;
        }

        window.Swal.fire({
            icon: 'warning',
            title: 'Administrador creado sin correo',
            html:
                '<p>La cuenta sí fue creada y asignada a todas las sucursales activas.</p>' +
                '<p><strong>Contraseña temporal:</strong> ' +
                '<code>' +
                escaparHtml(respuesta.password_temporal || 'ego1') +
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

    function enviarAltaAdministradorGlobal(evento) {
        if (!formulario) {
            return;
        }

        const campoId = formulario.querySelector('[name="id"]');

        /*
         * Las ediciones existentes continúan usando el manejador original.
         * Este flujo propio se usa únicamente para el alta global nueva.
         */
        if (campoId && campoId.value.trim() !== '') {
            return;
        }

        evento.preventDefault();
        evento.stopImmediatePropagation();

        const rol = formulario.querySelector('[name="rol"]');
        const estado = formulario.querySelector('[name="estado"]');
        const botonGuardar = formulario.querySelector(
            'button[type="submit"], input[type="submit"]'
        );

        if (rol) {
            rol.value = 'admin';
        }

        if (estado) {
            estado.value = 'activo';
        }

        if (botonGuardar) {
            botonGuardar.disabled = true;
        }

        const datos = new FormData(formulario);
        datos.set('action', 'save_usuario');
        datos.set('rol', 'admin');
        datos.set('estado', 'activo');

        if (typeof window.Swal !== 'undefined') {
            window.Swal.fire({
                title: 'Creando administrador...',
                text: 'Se está asignando la cuenta a todas las sucursales.',
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
                            'No fue posible crear el administrador.'
                        );
                    }

                    return respuestaJson;
                });
            })
            .then(function (respuestaJson) {
                mostrarResultadoAltaAdministrador(respuestaJson);
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
            enviarAltaAdministradorGlobal,
            true
        );
    }

    function inicializarControlesConfiguracion() {
        asegurarBotonAdministrador();

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
