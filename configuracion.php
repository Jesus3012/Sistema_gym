<?php
session_start();
require_once 'config/database.php';
require_once 'PHPMailer/PHPMailer.php';
require_once 'PHPMailer/SMTP.php';
require_once 'PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$database = new Database();
$conn = $database->getConnection();

if (!$conn) {
    die("Error: No se pudo establecer la conexión a la base de datos");
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Verificar que el usuario sea admin
if ($_SESSION['user_rol'] !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

$usuario_id = $_SESSION['user_id'];
$usuario_nombre = $_SESSION['user_name'];
$usuario_rol = $_SESSION['user_rol'];


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

// Obtener configuración del gimnasio
$config_result = $conn->query("SELECT * FROM configuracion_gimnasio WHERE id = 1");
$config_gimnasio = $config_result->fetch_assoc();

// Obtener configuración SMTP si la tabla ya existe
$config_correo = obtenerConfiguracionCorreo($conn);

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_config') {
        $nombre = $conn->real_escape_string($_POST['nombre_gimnasio']);
        $telefono = $conn->real_escape_string($_POST['telefono']);
        $email = $conn->real_escape_string($_POST['email']);
        $direccion = $conn->real_escape_string($_POST['direccion']);
        $horario = $conn->real_escape_string($_POST['horario']);

        // Manejo del logo
        $logo_path = null;

        // Verificar si se subió un nuevo logo
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $archivo = $_FILES['logo'];
            $nombre_original = $archivo['name'];
            $tipo = $archivo['type'];
            $tamano = $archivo['size'];
            $temp = $archivo['tmp_name'];

            // Validar tipo de archivo
            $tipos_permitidos = ['image/jpeg', 'image/jpg', 'image/png'];
            if (!in_array($tipo, $tipos_permitidos)) {
                echo json_encode(['success' => false, 'message' => 'Solo se permiten archivos JPG, JPEG y PNG']);
                exit;
            }

            // Validar tamaño (máximo 2MB)
            if ($tamano > 2 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'El archivo no puede superar los 2MB']);
                exit;
            }

            // Crear directorio img si no existe
            $directorio = 'img/';
            if (!file_exists($directorio)) {
                mkdir($directorio, 0777, true);
            }

            // Generar nombre único para el logo
            $extension = pathinfo($nombre_original, PATHINFO_EXTENSION);
            $nombre_logo = 'logo-gym.' . $extension;
            $ruta_completa = $directorio . $nombre_logo;

            // Obtener logo anterior
            $query_old = "SELECT logo FROM configuracion_gimnasio WHERE id = 1";
            $result_old = $conn->query($query_old);
            $old_logo = $result_old->fetch_assoc();

            if ($old_logo && !empty($old_logo['logo']) && file_exists($old_logo['logo'])) {
                unlink($old_logo['logo']);
            }

            // Subir nuevo archivo
            if (move_uploaded_file($temp, $ruta_completa)) {
                $logo_path = 'img/' . $nombre_logo;
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al subir el archivo']);
                exit;
            }
        }

        // Construir la consulta SQL
        if ($logo_path) {
            $query = "UPDATE configuracion_gimnasio SET nombre='$nombre', telefono='$telefono', email='$email', direccion='$direccion', horario='$horario', logo='$logo_path' WHERE id=1";
        } else {
            $query = "UPDATE configuracion_gimnasio SET nombre='$nombre', telefono='$telefono', email='$email', direccion='$direccion', horario='$horario' WHERE id=1";
        }

        if ($conn->query($query)) {
            echo json_encode(['success' => true, 'message' => 'Configuración guardada correctamente']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error al guardar la configuración: ' . $conn->error]);
        }
        exit;
    }

    // Guardar configuración de correo
    if ($action === 'save_email_config') {
        if (!existeConfiguracionCorreo($conn)) {
            echo json_encode(array(
                'success' => false,
                'message' =>
                    'Ejecuta primero configuracion_correo.sql.'
            ));
            exit;
        }

        $host = trim($_POST['host']);
        $puerto = (int) $_POST['puerto'];
        $usuarioSmtp = trim($_POST['usuario']);
        $passwordSmtp = isset($_POST['password_smtp'])
            ? trim($_POST['password_smtp'])
            : '';

        $cifrado = strtolower(trim($_POST['cifrado']));
        $smtpAuth = isset($_POST['smtp_auth']) ? 1 : 0;
        $remitenteEmail = trim($_POST['remitente_email']);
        $remitenteNombre = trim((string) ($config_gimnasio['nombre'] ?? 'EGO'));

        if ($remitenteNombre === '') {
            $remitenteNombre = 'EGO';
        }
        $verificarSsl =
            isset($_POST['verificar_ssl']) ? 1 : 0;
        $activo = isset($_POST['activo']) ? 1 : 0;

        if (
            $host === '' ||
            $usuarioSmtp === '' ||
            $remitenteEmail === ''
        ) {
            echo json_encode(array(
                'success' => false,
                'message' =>
                    'Host, usuario y remitente son obligatorios.'
            ));
            exit;
        }

        if (
            $passwordSmtp === '' &&
            $config_correo
        ) {
            $passwordSmtp =
                $config_correo['password_smtp'];
        }

        $stmt = $conn->prepare(
            "INSERT INTO configuracion_correo
                (
                    id,
                    host,
                    puerto,
                    usuario,
                    password_smtp,
                    cifrado,
                    smtp_auth,
                    remitente_email,
                    remitente_nombre,
                    verificar_ssl,
                    activo
                )
             VALUES
                (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                activo = VALUES(activo)"
        );

        $stmt->bind_param(
            'sisssissii',
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
        );

        $ok = $stmt->execute();
        $error = $stmt->error;
        $stmt->close();

        echo json_encode(array(
            'success' => $ok,
            'message' => $ok
                ? 'Configuración de correo guardada.'
                : $error
        ));
        exit;
    }

    // Gestionar Clientes
    if ($action === 'save_cliente') {
        $id = isset($_POST['id'])
            ? (int) $_POST['id']
            : 0;

        $nombre = $conn->real_escape_string(
            trim($_POST['nombre'])
        );

        $apellido = $conn->real_escape_string(
            trim($_POST['apellido'])
        );

        $telefono = $conn->real_escape_string(
            trim($_POST['telefono'])
        );

        $email = $conn->real_escape_string(
            trim($_POST['email'])
        );

        $estado = $_POST['estado'];

        if ($id > 0) {
            $query = "UPDATE clientes
                      SET nombre='$nombre',
                          apellido='$apellido',
                          telefono='$telefono',
                          email='$email',
                          estado='$estado'
                      WHERE id=$id";

            $ok = $conn->query($query);
        } else {
            $ok = $conn->query(
                "INSERT INTO clientes
                    (
                        nombre,
                        apellido,
                        telefono,
                        email,
                        estado
                    )
                 VALUES
                    (
                        '$nombre',
                        '$apellido',
                        '$telefono',
                        '$email',
                        '$estado'
                    )"
            );
        }

        echo json_encode(array(
            'success' => (bool) $ok,
            'message' => $ok
                ? 'Socio guardado correctamente.'
                : $conn->error
        ));
        exit;
    }

    if ($action === 'delete_cliente') {
        $id = intval($_POST['id']);
        $check = $conn->query("SELECT COUNT(*) as total FROM inscripciones WHERE cliente_id=$id AND estado='activa'")->fetch_assoc();
        if ($check['total'] > 0) {
            echo json_encode(['success' => false, 'error' => 'No se puede eliminar un cliente con inscripciones activas']);
        } else {
            $conn->query("DELETE FROM clientes WHERE id=$id");
            echo json_encode(['success' => true]);
        }
        exit;
    }

    // Gestionar Planes
    if ($action === 'save_plan') {
        $id = $_POST['id'] ?? null;
        $nombre = $conn->real_escape_string($_POST['nombre']);
        $duracion_dias = intval($_POST['duracion_dias']);
        $precio = floatval($_POST['precio']);
        $descripcion = $conn->real_escape_string($_POST['descripcion']);
        $estado = $_POST['estado'];

        if ($id) {
            $conn->query("UPDATE planes SET nombre='$nombre', duracion_dias=$duracion_dias, precio=$precio, descripcion='$descripcion', estado='$estado' WHERE id=$id");
        } else {
            $conn->query("INSERT INTO planes (nombre, duracion_dias, precio, descripcion, estado) VALUES ('$nombre', $duracion_dias, $precio, '$descripcion', '$estado')");
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_plan') {
        $id = intval($_POST['id']);
        $conn->query("DELETE FROM planes WHERE id=$id");
        echo json_encode(['success' => true]);
        exit;
    }

    // Gestionar Categorías
    if ($action === 'save_categoria') {
        $id = $_POST['id'] ?? null;
        $nombre = $conn->real_escape_string($_POST['nombre']);
        $descripcion = $conn->real_escape_string($_POST['descripcion']);
        $estado = $_POST['estado'];

        if ($id) {
            $conn->query("UPDATE categorias_productos SET nombre='$nombre', descripcion='$descripcion', estado='$estado' WHERE id=$id");
        } else {
            $conn->query("INSERT INTO categorias_productos (nombre, descripcion, estado) VALUES ('$nombre', '$descripcion', '$estado')");
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_categoria') {
        $id = intval($_POST['id']);
        $conn->query("DELETE FROM categorias_productos WHERE id=$id");
        echo json_encode(['success' => true]);
        exit;
    }

    // Gestionar Proveedores
    if ($action === 'save_proveedor') {
        $id = $_POST['id'] ?? null;
        $nombre = $conn->real_escape_string($_POST['nombre']);
        $contacto = $conn->real_escape_string($_POST['contacto']);
        $telefono = $conn->real_escape_string($_POST['telefono']);
        $email = $conn->real_escape_string($_POST['email']);
        $direccion = $conn->real_escape_string($_POST['direccion']);
        $estado = $_POST['estado'];

        if ($id) {
            $conn->query("UPDATE proveedores SET nombre='$nombre', contacto='$contacto', telefono='$telefono', email='$email', direccion='$direccion', estado='$estado' WHERE id=$id");
        } else {
            $conn->query("INSERT INTO proveedores (nombre, contacto, telefono, email, direccion, estado) VALUES ('$nombre', '$contacto', '$telefono', '$email', '$direccion', '$estado')");
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_proveedor') {
        $id = intval($_POST['id']);
        $conn->query("DELETE FROM proveedores WHERE id=$id");
        echo json_encode(['success' => true]);
        exit;
    }

    // Gestionar Productos
    if ($action === 'save_producto') {
        $id = $_POST['id'] ?? null;
        $nombre = $conn->real_escape_string($_POST['nombre']);
        $descripcion = $conn->real_escape_string($_POST['descripcion']);
        $categoria_id = intval($_POST['categoria_id']);
        $proveedor_id = intval($_POST['proveedor_id']) ?: 'NULL';
        $precio_compra = floatval($_POST['precio_compra']);
        $precio_venta = floatval($_POST['precio_venta']);
        $stock = intval($_POST['stock']);
        $stock_minimo = intval($_POST['stock_minimo']);
        $estado = $_POST['estado'];

        if ($id) {
            $conn->query("UPDATE productos SET nombre='$nombre', descripcion='$descripcion', categoria_id=$categoria_id, proveedor_id=$proveedor_id, precio_compra=$precio_compra, precio_venta=$precio_venta, stock=$stock, stock_minimo=$stock_minimo, estado='$estado' WHERE id=$id");
        } else {
            $conn->query("INSERT INTO productos (nombre, descripcion, categoria_id, proveedor_id, precio_compra, precio_venta, stock, stock_minimo, estado) VALUES ('$nombre', '$descripcion', $categoria_id, $proveedor_id, $precio_compra, $precio_venta, $stock, $stock_minimo, '$estado')");
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_producto') {
        $id = intval($_POST['id']);
        $conn->query("DELETE FROM productos WHERE id=$id");
        echo json_encode(['success' => true]);
        exit;
    }

    // Gestionar Clases
    if ($action === 'save_clase') {
        $id = $_POST['id'] ?? null;
        $nombre = $conn->real_escape_string($_POST['nombre']);
        $descripcion = $conn->real_escape_string($_POST['descripcion']);
        $horario = $conn->real_escape_string($_POST['horario']);
        $instructor = $conn->real_escape_string($_POST['instructor']);
        $cupo_maximo = intval($_POST['cupo_maximo']);
        $duracion_minutos = intval($_POST['duracion_minutos']);
        $estado = $_POST['estado'];

        if ($id) {
            $conn->query("UPDATE clases SET nombre='$nombre', descripcion='$descripcion', horario='$horario', instructor='$instructor', cupo_maximo=$cupo_maximo, duracion_minutos=$duracion_minutos, estado='$estado' WHERE id=$id");
        } else {
            $conn->query("INSERT INTO clases (nombre, descripcion, horario, instructor, cupo_maximo, duracion_minutos, estado) VALUES ('$nombre', '$descripcion', '$horario', '$instructor', $cupo_maximo, $duracion_minutos, '$estado')");
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_clase') {
        $id = intval($_POST['id']);
        $conn->query("DELETE FROM clases WHERE id=$id");
        echo json_encode(['success' => true]);
        exit;
    }

    // Gestionar Usuarios del Sistema
    if ($action === 'save_usuario') {
        $id = isset($_POST['id'])
            ? (int) $_POST['id']
            : 0;

        $nombreLimpio = trim($_POST['nombre']);
        $emailLimpio = strtolower(trim($_POST['email']));
        $nombre = $conn->real_escape_string($nombreLimpio);
        $email = $conn->real_escape_string($emailLimpio);
        $rol = $_POST['rol'];
        $estado = $_POST['estado'];

        if (!filter_var($emailLimpio, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(array(
                'success' => false,
                'message' => 'El correo del usuario no es válido.'
            ));
            exit;
        }

        $duplicado = $conn->query(
            "SELECT id
             FROM usuarios
             WHERE email='$email'
               AND id<>$id
             LIMIT 1"
        );

        if ($duplicado && $duplicado->num_rows > 0) {
            echo json_encode(array(
                'success' => false,
                'message' =>
                    'Ya existe un usuario con ese correo.'
            ));
            exit;
        }

        if ($id > 0) {
            $ok = $conn->query(
                "UPDATE usuarios
                 SET nombre='$nombre',
                     email='$email',
                     rol='$rol',
                     estado='$estado'
                 WHERE id=$id"
            );

            echo json_encode(array(
                'success' => (bool) $ok,
                'usuario_nuevo' => false,
                'message' => $ok
                    ? 'Usuario actualizado.'
                    : $conn->error
            ));
            exit;
        }

        $passwordTemporal =
            generarPasswordTemporal();

        $password = password_hash(
            $passwordTemporal,
            PASSWORD_DEFAULT
        );

        $passwordSeguro =
            $conn->real_escape_string($password);

        $ok = $conn->query(
            "INSERT INTO usuarios
                (
                    nombre,
                    email,
                    password,
                    rol,
                    estado,
                    password_change_required
                )
             VALUES
                (
                    '$nombre',
                    '$email',
                    '$passwordSeguro',
                    '$rol',
                    '$estado',
                    1
                )"
        );

        if (!$ok) {
            echo json_encode(array(
                'success' => false,
                'message' => $conn->error
            ));
            exit;
        }

        $correo = enviarCredencialesAcceso(
            $conn,
            $nombreLimpio,
            $emailLimpio,
            $passwordTemporal,
            $rol
        );

        echo json_encode(array(
            'success' => true,
            'usuario_nuevo' => true,
            'correo_enviado' => $correo['enviado'],
            'correo_error' => $correo['error'],
            'password_temporal' =>
                $correo['enviado']
                    ? ''
                    : $passwordTemporal,
            'message' => $correo['enviado']
                ? 'Usuario creado y credenciales enviadas.'
                : 'Usuario creado, pero el correo no pudo enviarse.'
        ));
        exit;
    }

    if ($action === 'delete_usuario') {
        $id = intval($_POST['id']);

        if ($id <= 0) {
            echo json_encode(array(
                'success' => false,
                'message' => 'El usuario no es válido.'
            ));
            exit;
        }

        if ($id === 1) {
            echo json_encode(array(
                'success' => false,
                'message' => 'El administrador principal no se puede eliminar.'
            ));
            exit;
        }

        // Eliminación lógica: conserva ventas, movimientos y trazabilidad.
        $ok = $conn->query(
            "UPDATE usuarios
             SET estado='inactivo'
             WHERE id=$id"
        );

        echo json_encode(array(
            'success' => (bool) $ok,
            'message' => $ok
                ? 'Usuario eliminado correctamente.'
                : $conn->error
        ));
        exit;
    }

    if ($action === 'cambiar_password') {
        $id = intval($_POST['id']);

        $usuarioResultado = $conn->query(
            "SELECT nombre, email, rol
             FROM usuarios
             WHERE id=$id
             LIMIT 1"
        );

        $usuarioReset = $usuarioResultado
            ? $usuarioResultado->fetch_assoc()
            : null;

        if (!$usuarioReset) {
            echo json_encode(array(
                'success' => false,
                'message' => 'No se encontró el usuario.'
            ));
            exit;
        }

        $passwordTemporal =
            generarPasswordTemporal();

        $password = password_hash(
            $passwordTemporal,
            PASSWORD_DEFAULT
        );

        $passwordSeguro =
            $conn->real_escape_string($password);

        $ok = $conn->query(
            "UPDATE usuarios
             SET password='$passwordSeguro',
                 password_change_required=1,
                 ultimo_cambio_password=NOW()
             WHERE id=$id"
        );

        if (!$ok) {
            echo json_encode(array(
                'success' => false,
                'message' => $conn->error
            ));
            exit;
        }

        $correo = enviarCredencialesAcceso(
            $conn,
            $usuarioReset['nombre'],
            $usuarioReset['email'],
            $passwordTemporal,
            $usuarioReset['rol']
        );

        echo json_encode(array(
            'success' => true,
            'correo_enviado' => $correo['enviado'],
            'correo_error' => $correo['error'],
            'password_temporal' =>
                $correo['enviado']
                    ? ''
                    : $passwordTemporal
        ));
        exit;
    }

    if ($action === 'get_registro') {
        $tabla = $_POST['tabla'];
        $id = intval($_POST['id']);

        $tablas_permitidas = ['planes', 'categorias_productos', 'proveedores', 'productos', 'clases', 'usuarios', 'clientes'];
        if (in_array($tabla, $tablas_permitidas)) {
            $result = $conn->query("SELECT * FROM $tabla WHERE id=$id");
            echo json_encode($result->fetch_assoc());
        } else {
            echo json_encode([]);
        }
        exit;
    }
}

$seccion = isset($_GET['section']) ? $_GET['section'] : 'general';

// Obtener datos para estadísticas
$total_planes = $conn->query("SELECT COUNT(*) as total FROM planes WHERE estado='activo'")->fetch_assoc();
$total_productos = $conn->query("SELECT COUNT(*) as total FROM productos WHERE estado='activo'")->fetch_assoc();
$total_usuarios = $conn->query("SELECT COUNT(*) as total FROM usuarios WHERE estado='activo'")->fetch_assoc();
$total_proveedores = $conn->query("SELECT COUNT(*) as total FROM proveedores WHERE estado='activo'")->fetch_assoc();
$total_categorias = $conn->query("SELECT COUNT(*) as total FROM categorias_productos WHERE estado='activo'")->fetch_assoc();
$total_clases = $conn->query("SELECT COUNT(*) as total FROM clases WHERE estado='activa'")->fetch_assoc();
$total_clientes = $conn->query("SELECT COUNT(*) as total FROM clientes WHERE estado='activo'")->fetch_assoc();

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
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración - Sistema Gimnasio</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="css/configuracion.css?v=4.0.0">

</head>
<body class="hold-transition sidebar-mini">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0"> Configuración del Sistema</h1>
                    </div>
                </div>
            </div>
        </div>

        <div class="config-nav">
            <ul>
                <li><a href="?section=general" class="<?php echo $seccion == 'general' ? 'active' : ''; ?>"><i class="fas fa-sliders-h"></i> General</a></li>
                <li><a href="?section=correo" class="<?php echo $seccion == 'correo' ? 'active' : ''; ?>"><i class="fas fa-envelope-open-text"></i> Correo</a></li>
                <li><a href="?section=clientes" class="<?php echo $seccion == 'clientes' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Socios</a></li>
                <li><a href="?section=planes" class="<?php echo $seccion == 'planes' ? 'active' : ''; ?>"><i class="fas fa-tags"></i> Planes</a></li>
                <li><a href="?section=productos" class="<?php echo $seccion == 'productos' ? 'active' : ''; ?>"><i class="fas fa-box"></i> Productos</a></li>
                <li><a href="?section=categorias" class="<?php echo $seccion == 'categorias' ? 'active' : ''; ?>"><i class="fas fa-folder"></i> Categorías</a></li>
                <li><a href="?section=proveedores" class="<?php echo $seccion == 'proveedores' ? 'active' : ''; ?>"><i class="fas fa-truck"></i> Proveedores</a></li>
                <li><a href="?section=clases" class="<?php echo $seccion == 'clases' ? 'active' : ''; ?>"><i class="fas fa-chalkboard-user"></i> Clases</a></li>
                <li><a href="?section=usuarios" class="<?php echo $seccion == 'usuarios' ? 'active' : ''; ?>"><i class="fas fa-user-shield"></i> Usuarios</a></li>
            </ul>
        </div>

        <?php if ($seccion == 'general'): ?>
        <div class="row">
            <div class="col-lg-3 col-6">
                <div class="small-box bg-info">
                    <div class="inner">
                        <h3><?php echo $total_clientes['total']; ?></h3>
                        <p>Clientes Activos</p>
                    </div>
                    <div class="icon"><i class="fas fa-users"></i></div>
                </div>
            </div>
            <div class="col-lg-3 col-6">
                <div class="small-box bg-success">
                    <div class="inner">
                        <h3><?php echo $total_planes['total']; ?></h3>
                        <p>Planes Activos</p>
                    </div>
                    <div class="icon"><i class="fas fa-tags"></i></div>
                </div>
            </div>
            <div class="col-lg-3 col-6">
                <div class="small-box bg-warning">
                    <div class="inner">
                        <h3><?php echo $total_productos['total']; ?></h3>
                        <p>Productos</p>
                    </div>
                    <div class="icon"><i class="fas fa-box"></i></div>
                </div>
            </div>
            <div class="col-lg-3 col-6">
                <div class="small-box bg-danger">
                    <div class="inner">
                        <h3><?php echo $total_usuarios['total']; ?></h3>
                        <p>Usuarios</p>
                    </div>
                    <div class="icon"><i class="fas fa-user-shield"></i></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-building"></i> Información del Gimnasio</h3>
            </div>
            <div class="card-body">
                <form id="formInfoGimnasio" enctype="multipart/form-data">
                    <div class="row">
                        <!-- Sección Logo - Versión mejorada y más bonita -->
                        <div class="col-md-12">
                            <div class="form-group">
                                <label><i class="fas fa-image"></i> Logo del Gimnasio</label>
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <div class="row align-items-center">
                                            <div class="col-md-3 text-center">
                                                <?php
                                                $logo_path = 'img/logo-gym.png';
                                                if(!empty($config_gimnasio['logo']) && file_exists($config_gimnasio['logo'])) {
                                                    $logo_path = $config_gimnasio['logo'];
                                                }
                                                ?>
                                                <div class="text-center mb-3">
                                                    <img id="preview_logo" src="<?php echo htmlspecialchars($logo_path); ?>"
                                                        alt="Logo del gimnasio"
                                                        style="max-width: 150px; max-height: 150px; border: 1px solid #ddd; padding: 5px; border-radius: 5px; object-fit: contain;">
                                                </div>
                                            </div>
                                            <div class="col-md-9">
                                                <div class="custom-file">
                                                    <input type="file" class="custom-file-input" id="logo" name="logo" accept="image/*">
                                                    <label class="custom-file-label" for="logo">Seleccionar logo (PNG, JPG, JPEG, GIF, WEBP, BMP)</label>
                                                </div>

                                                <!-- Alerta ocultable con todas las recomendaciones -->
                                                <div class="alert alert-info alert-ocultable mt-3" data-alerta-id="logo_info" style="position: relative;">
                                                    <i class="fas fa-info-circle"></i>
                                                    <strong>Recomendaciones para el logo:</strong>
                                                    <ul class="mb-0 mt-1">
                                                        <li>Formatos permitidos: PNG, JPG, JPEG, GIF, WEBP, BMP</li>
                                                        <li>Tamaño máximo: 2MB</li>
                                                        <li>Dimensión recomendada: 200x200px</li>
                                                        <li>Fondo transparente para mejor integración</li>
                                                        <li>El logo se mostrará en facturas, reportes y en la interfaz del sistema</li>
                                                    </ul>
                                                    <button type="button" class="btn-ocultar" onclick="event.preventDefault(); event.stopPropagation(); ocultarAlerta('logo_info')" title="Ocultar recomendaciones">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>

                                                <?php if(!empty($config_gimnasio['logo'])): ?>
                                                    <button type="button" class="btn btn-danger btn-sm mt-2" onclick="eliminarLogo()">
                                                        <i class="fas fa-trash"></i> Eliminar logo actual
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-building"></i> Nombre del Gimnasio</label>
                                <input type="text" class="form-control" name="nombre_gimnasio" value="<?php echo htmlspecialchars($config_gimnasio['nombre'] ?? 'EGO'); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-phone"></i> Teléfono</label>
                                <input type="text" class="form-control" name="telefono" value="<?php echo htmlspecialchars($config_gimnasio['telefono'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-envelope"></i> Email</label>
                                <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($config_gimnasio['email'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-map-marker-alt"></i> Dirección</label>
                                <input type="text" class="form-control" name="direccion" value="<?php echo htmlspecialchars($config_gimnasio['direccion'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-group">
                                <label><i class="fas fa-clock"></i> Horario de Atención</label>
                                <input type="text" class="form-control" name="horario" value="<?php echo htmlspecialchars($config_gimnasio['horario'] ?? ''); ?>">
                                <small class="text-muted">Ejemplo: Lun-Vie 6am-10pm, Sáb 8am-6pm</small>
                            </div>
                        </div>
                    </div>
                    <div class="text-right">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-info-circle"></i> Acerca del Sistema</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <ul class="list-unstyled">
                            <li><strong>Desarrollado por:</strong> Jesus Martinez</li>
                            <li><strong>Última actualización:</strong> <?php echo $ultima_actualizacion; ?></li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <div class="alert alert-info alert-ocultable" data-alerta-id="info_gimnasio">
                            <i class="fas fa-lightbulb"></i> <strong>Consejo:</strong> La información del gimnasio se utiliza en reportes, facturas y en la interfaz del sistema.
                            <button type="button" class="btn-ocultar" onclick="event.preventDefault(); event.stopPropagation(); ocultarAlerta('info_gimnasio')" title="Ocultar alerta">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion == 'correo'): ?>
        <div class="card config-mail-card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-envelope-open-text"></i>
                    Configuración de Correo
                </h3>

                <span class="mail-config-status <?php
                echo $config_correo ? 'ready' : 'missing';
                ?>">
                    <i class="fas <?php
                    echo $config_correo
                        ? 'fa-circle-check'
                        : 'fa-triangle-exclamation';
                    ?>"></i>
                    <?php
                    echo $config_correo
                        ? 'Configurado'
                        : 'Falta ejecutar SQL';
                    ?>
                </span>
            </div>

            <div class="card-body">
                <?php if (!$config_correo): ?>
                    <div class="config-inline-notice warning">
                        <i class="fas fa-database"></i>
                        <div>
                            <strong>Configuración pendiente</strong>
                            <span>
                                Ejecuta primero
                                <b>configuracion_correo.sql</b>
                                para crear la tabla y cargar los
                                datos SMTP iniciales.
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <form id="formCorreo">
                    <div class="config-form-modern">
                        <div class="form-group">
                            <label>Servidor SMTP</label>
                            <input
                                type="text"
                                class="form-control"
                                name="host"
                                required
                                value="<?php
                                echo htmlspecialchars(
                                    $config_correo
                                        ? $config_correo['host']
                                        : 'smtp.gmail.com'
                                );
                                ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label>Puerto</label>
                            <input
                                type="number"
                                class="form-control"
                                name="puerto"
                                required
                                value="<?php
                                echo htmlspecialchars(
                                    $config_correo
                                        ? $config_correo['puerto']
                                        : '587'
                                );
                                ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label>Usuario SMTP</label>
                            <input
                                type="email"
                                class="form-control"
                                name="usuario"
                                required
                                value="<?php
                                echo htmlspecialchars(
                                    $config_correo
                                        ? $config_correo['usuario']
                                        : ''
                                );
                                ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label>Contraseña o App Password</label>
                            <input
                                type="password"
                                class="form-control"
                                name="password_smtp"
                                placeholder="<?php
                                echo $config_correo
                                    ? 'Dejar en blanco para conservar'
                                    : 'Contraseña SMTP';
                                ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label>Cifrado</label>
                            <select
                                class="form-control"
                                name="cifrado"
                            >
                                <?php
                                $cifrado_actual =
                                    $config_correo
                                        ? $config_correo['cifrado']
                                        : 'tls';
                                ?>
                                <option
                                    value="tls"
                                    <?php
                                    echo $cifrado_actual === 'tls'
                                        ? 'selected'
                                        : '';
                                    ?>
                                >
                                    TLS
                                </option>
                                <option
                                    value="ssl"
                                    <?php
                                    echo $cifrado_actual === 'ssl'
                                        ? 'selected'
                                        : '';
                                    ?>
                                >
                                    SSL
                                </option>
                                <option
                                    value="ninguno"
                                    <?php
                                    echo $cifrado_actual === 'ninguno'
                                        ? 'selected'
                                        : '';
                                    ?>
                                >
                                    Sin cifrado
                                </option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Correo remitente</label>
                            <input
                                type="email"
                                class="form-control"
                                name="remitente_email"
                                required
                                value="<?php
                                echo htmlspecialchars(
                                    $config_correo
                                        ? $config_correo[
                                            'remitente_email'
                                        ]
                                        : ''
                                );
                                ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label>Nombre remitente</label>
                            <input
                                type="text"
                                class="form-control"
                                name="remitente_nombre"
                                readonly
                                value="<?php
                                echo htmlspecialchars(
                                    $config_gimnasio['nombre']
                                    ?? 'EGO'
                                );
                                ?>"
                            >
                        </div>

                        <div class="form-group full-width">
                            <div class="config-checks">
                                <label>
                                    <input
                                        type="checkbox"
                                        name="smtp_auth"
                                        <?php
                                        echo !$config_correo ||
                                            (int) $config_correo[
                                                'smtp_auth'
                                            ] === 1
                                                ? 'checked'
                                                : '';
                                        ?>
                                    >
                                    Autenticación SMTP
                                </label>

                                <label>
                                    <input
                                        type="checkbox"
                                        name="verificar_ssl"
                                        <?php
                                        echo $config_correo &&
                                            (int) $config_correo[
                                                'verificar_ssl'
                                            ] === 1
                                                ? 'checked'
                                                : '';
                                        ?>
                                    >
                                    Verificar certificado SSL
                                </label>

                                <label>
                                    <input
                                        type="checkbox"
                                        name="activo"
                                        <?php
                                        echo !$config_correo ||
                                            (int) $config_correo[
                                                'activo'
                                            ] === 1
                                                ? 'checked'
                                                : '';
                                        ?>
                                    >
                                    Envío de correo activo
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="config-inline-notice">
                        <i class="fas fa-shield-halved"></i>
                        <div>
                            <strong>Contraseña de aplicación</strong>
                            <span>
                                Para Gmail utiliza una App Password.
                                No uses la contraseña normal de la cuenta.
                            </span>
                        </div>
                    </div>

                    <div class="config-form-actions-modern">

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            <i class="fas fa-save"></i>
                            Guardar configuración
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion == 'clientes'): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-users"></i> Gestión de Socios</h3>
                <div class="card-tools">
                    <button class="btn btn-primary btn-sm" onclick="abrirModal('modalCliente')">
                        <i class="fas fa-plus"></i> Nuevo Socio
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th style="display: none;">ID</th>
                                <th>Nombre</th>
                                <th>Teléfono</th>
                                <th>Email</th>
                                <th>Código QR</th>
                                <th>Estado</th>
                                <th>Fecha Registro</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // El QR válido del socio se obtiene exclusivamente de clientes.codigo_qr.
                            $clientes = $conn->query("SELECT * FROM clientes ORDER BY id DESC");
                            while($cliente = $clientes->fetch_assoc()):
                            ?>
                            <tr>
                                <td style="display: none;"><?php echo $cliente['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apellido']); ?></strong></td>
                                <td><?php echo htmlspecialchars($cliente['telefono'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($cliente['email'] ?? '-'); ?></td>
                                <td>
                                    <div class="qr-list-cell">
                                        <div
                                            class="qr-mini"
                                            data-qr="<?php echo htmlspecialchars($cliente['codigo_qr'] ?? ''); ?>"
                                        ></div>
                                        <span>
                                            <?php echo !empty($cliente['codigo_qr']) ? 'QR asignado' : 'Sin QR'; ?>
                                        </span>
                                    </div>
                                </td>
                                <td><span class="badge <?php echo $cliente['estado'] == 'activo' ? 'badge-success' : 'badge-danger'; ?>"><?php echo $cliente['estado'] == 'activo' ? 'Activo' : 'Inactivo'; ?></span></td>
                                <td><?php echo date('d/m/Y', strtotime($cliente['fecha_registro'])); ?></td>
                                <td class="acciones-cliente">
                                    <button class="btn btn-warning btn-sm" onclick="editarCliente(<?php echo $cliente['id']; ?>)" title="Editar cliente"><i class="fas fa-edit"></i> Editar</button>
                                    <button
                                        class="btn btn-info btn-sm"
                                        onclick='verQrSocio(
                                            <?php echo (int) $cliente["id"]; ?>,
                                            <?php echo htmlspecialchars(json_encode($cliente["nombre"] . " " . $cliente["apellido"]), ENT_QUOTES, "UTF-8"); ?>,
                                            <?php echo htmlspecialchars(json_encode($cliente["codigo_qr"] ?? ""), ENT_QUOTES, "UTF-8"); ?>
                                        )'
                                        title="Ver código QR"
                                    >
                                        <i class="fas fa-qrcode"></i>
                                        Ver QR
                                    </button>
                                    <button class="btn btn-danger btn-sm" onclick="eliminarCliente(<?php echo $cliente['id']; ?>)" title="Eliminar cliente"><i class="fas fa-trash"></i> Eliminar</button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="modalCliente" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h4><i class="fas fa-user-plus"></i> Nuevo Cliente</h4>
                    <button class="modal-close">&times;</button>
                </div>
                <form id="formCliente">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="clienteId">
                        <div class="row">
                            <div class="col-md-6"><div class="form-group"><label>Nombre</label><input type="text" class="form-control" name="nombre" id="clienteNombre" required></div></div>
                            <div class="col-md-6"><div class="form-group"><label>Apellido</label><input type="text" class="form-control" name="apellido" id="clienteApellido" required></div></div>
                            <div class="col-md-6"><div class="form-group"><label>Teléfono</label><input type="text" class="form-control" name="telefono" id="clienteTelefono"></div></div>
                            <div class="col-md-6"><div class="form-group"><label>Email</label><input type="email" class="form-control" name="email" id="clienteEmail"></div></div>
                            <div class="col-md-12"><div class="form-group"><label>Estado</label><select class="form-control" name="estado" id="clienteEstado"><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select></div></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalCliente')">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar Cliente</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="modalQr" class="modal">
            <div class="modal-content qr-modal-content">
                <div class="modal-header">
                    <h4>
                        <i class="fas fa-qrcode"></i>
                        Código QR del Socio
                    </h4>
                    <button class="modal-close">&times;</button>
                </div>

                <div class="modal-body">
                    <input type="hidden" id="qrClienteId">

                    <div class="qr-modal-layout">
                        <div id="qrGrande" class="qr-grande"></div>

                        <div class="qr-modal-info">
                            <h3 id="qrClienteNombre"></h3>

                            <p>
                                Este código identifica al socio en
                                los módulos de acceso y asistencia.
                            </p>

                            <code id="qrCodigoTexto"></code>

                            <div class="qr-modal-actions">
                                <button
                                    type="button"
                                    class="btn btn-light"
                                    onclick="copiarQr()"
                                >
                                    <i class="fas fa-copy"></i>
                                    Copiar
                                </button>

                                <button
                                    type="button"
                                    class="btn btn-dark"
                                    onclick="imprimirQr()"
                                >
                                    <i class="fas fa-print"></i>
                                    Imprimir
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-secondary"
                        onclick="cerrarModal('modalQr')"
                    >
                        Cerrar
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion == 'planes'): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-tags"></i> Planes de Membresía</h3>
                <div class="card-tools">
                    <button class="btn btn-primary btn-sm" onclick="abrirModal('modalPlan')"><i class="fas fa-plus"></i> Nuevo Plan</button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th style="display: none;">ID</th>
                                <th>Nombre</th>
                                <th>Duración</th>
                                <th>Precio</th>
                                <th>Descripción</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $planes = $conn->query("SELECT * FROM planes ORDER BY id"); while($plan = $planes->fetch_assoc()): ?>
                            <tr>
                                <td style="display: none;"><?php echo $plan['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($plan['nombre']); ?></strong></td>
                                <td><?php echo $plan['duracion_dias']; ?> días</td>
                                <td>$<?php echo number_format($plan['precio']); ?></td>
                                <td><?php echo htmlspecialchars($plan['descripcion'] ?? '-'); ?></td>
                                <td><span class="badge <?php echo $plan['estado'] == 'activo' ? 'badge-success' : 'badge-danger'; ?>"><?php echo $plan['estado'] == 'activo' ? 'Activo' : 'Inactivo'; ?></span></td>
                                <td>
                                    <button class="btn btn-warning btn-sm" onclick="editarRegistro('planes', <?php echo $plan['id']; ?>, 'modalPlan')" title="Editar plan"><i class="fas fa-edit"></i> Editar</button>
                                    <button class="btn btn-danger btn-sm" onclick="eliminarRegistro('planes', <?php echo $plan['id']; ?>, 'plan')" title="Eliminar plan"><i class="fas fa-trash"></i> Eliminar</button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="modalPlan" class="modal">
            <div class="modal-content">
                <div class="modal-header"><h4><i class="fas fa-plus"></i> Nuevo Plan</h4><button class="modal-close">&times;</button></div>
                <form id="formPlan">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="planId">
                        <div class="form-group"><label>Nombre</label><input type="text" class="form-control" name="nombre" id="planNombre" required></div>
                        <div class="form-group"><label>Duración (días)</label><input type="number" class="form-control" name="duracion_dias" id="planDuracion" required></div>
                        <div class="form-group"><label>Precio</label><input type="number" step="1" class="form-control" name="precio" id="planPrecio" required></div>
                        <div class="form-group"><label>Descripción</label><textarea class="form-control" name="descripcion" id="planDescripcion"></textarea></div>
                        <div class="form-group"><label>Estado</label><select class="form-control" name="estado" id="planEstado"><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="cerrarModal('modalPlan')">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion == 'productos'): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-box"></i> Productos</h3>
                <div class="card-tools">
                    <button class="btn btn-primary btn-sm" onclick="abrirModal('modalProducto')"><i class="fas fa-plus"></i> Nuevo Producto</button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th style="display: none;">ID</th>
                                <th>Nombre</th>
                                <th>Categoría</th>
                                <th>Proveedor</th>
                                <th>Precio Venta</th>
                                <th>Stock</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $productos = $conn->query("SELECT p.*, c.nombre as categoria_nombre, pr.nombre as proveedor_nombre FROM productos p LEFT JOIN categorias_productos c ON p.categoria_id = c.id LEFT JOIN proveedores pr ON p.proveedor_id = pr.id ORDER BY p.id");
                            while($prod = $productos->fetch_assoc()): ?>
                            <tr>
                                <td style="display: none;"><?php echo $prod['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($prod['nombre']); ?></strong></td>
                                <td><?php echo htmlspecialchars($prod['categoria_nombre'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($prod['proveedor_nombre'] ?? '-'); ?></td>
                                <td>$<?php echo number_format($prod['precio_venta'], 2); ?></td>
                                <td><?php echo $prod['stock']; ?> unidades</td>
                                <td><span class="badge <?php echo $prod['estado'] == 'activo' ? 'badge-success' : 'badge-danger'; ?>"><?php echo $prod['estado'] == 'activo' ? 'Activo' : 'Inactivo'; ?></span></td>
                                <td>
                                    <button class="btn btn-warning btn-sm" onclick="editarProducto(<?php echo $prod['id']; ?>)" title="Editar producto"><i class="fas fa-edit"></i> Editar</button>
                                    <button class="btn btn-danger btn-sm" onclick="eliminarRegistro('productos', <?php echo $prod['id']; ?>, 'producto')" title="Eliminar producto"><i class="fas fa-trash"></i> Eliminar</button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="modalProducto" class="modal">
            <div class="modal-content" style="max-width: 700px;">
                <div class="modal-header"><h4><i class="fas fa-plus"></i> Nuevo Producto</h4><button class="modal-close">&times;</button></div>
                <form id="formProducto">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="productoId">
                        <div class="row">
                            <div class="col-md-6"><div class="form-group"><label>Nombre</label><input type="text" class="form-control" name="nombre" id="productoNombre" required></div></div>
                            <div class="col-md-6"><div class="form-group"><label>Categoría</label><select class="form-control" name="categoria_id" id="productoCategoria" required><?php $cats = $conn->query("SELECT id, nombre FROM categorias_productos WHERE estado='activo'"); while($cat = $cats->fetch_assoc()): ?><option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['nombre']); ?></option><?php endwhile; ?></select></div></div>
                            <div class="col-md-6"><div class="form-group"><label>Proveedor</label><select class="form-control" name="proveedor_id" id="productoProveedor"><option value="">Seleccionar</option><?php $provs = $conn->query("SELECT id, nombre FROM proveedores WHERE estado='activo'"); while($prov = $provs->fetch_assoc()): ?><option value="<?php echo $prov['id']; ?>"><?php echo htmlspecialchars($prov['nombre']); ?></option><?php endwhile; ?></select></div></div>
                            <div class="col-md-6"><div class="form-group"><label>Precio Compra</label><input type="number" step="0.01" class="form-control" name="precio_compra" id="productoPrecioCompra" required></div></div>
                            <div class="col-md-6"><div class="form-group"><label>Precio Venta</label><input type="number" step="0.01" class="form-control" name="precio_venta" id="productoPrecioVenta" required></div></div>
                            <div class="col-md-6"><div class="form-group"><label>Stock</label><input type="number" class="form-control" name="stock" id="productoStock" required></div></div>
                            <div class="col-md-6"><div class="form-group"><label>Stock Mínimo</label><input type="number" class="form-control" name="stock_minimo" id="productoStockMinimo" value="10"></div></div>
                            <div class="col-12"><div class="form-group"><label>Descripción</label><textarea class="form-control" name="descripcion" id="productoDescripcion"></textarea></div></div>
                            <div class="col-12"><div class="form-group"><label>Estado</label><select class="form-control" name="estado" id="productoEstado"><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select></div></div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="cerrarModal('modalProducto')">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion == 'categorias'): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-folder"></i> Categorías de Productos</h3>
                <div class="card-tools">
                    <button class="btn btn-primary btn-sm" onclick="abrirModal('modalCategoria')"><i class="fas fa-plus"></i> Nueva Categoría</button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th style="display: none;">ID</th>
                                <th>Nombre</th>
                                <th>Descripción</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $categorias = $conn->query("SELECT * FROM categorias_productos ORDER BY id"); while($cat = $categorias->fetch_assoc()): ?>
                            <tr>
                                <td style="display: none;"><?php echo $cat['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($cat['nombre']); ?></strong></td>
                                <td><?php echo htmlspecialchars($cat['descripcion'] ?? '-'); ?></td>
                                <td><span class="badge <?php echo $cat['estado'] == 'activo' ? 'badge-success' : 'badge-danger'; ?>"><?php echo $cat['estado'] == 'activo' ? 'Activo' : 'Inactivo'; ?></span></td>
                                <td>
                                    <button class="btn btn-warning btn-sm" onclick="editarRegistro('categorias_productos', <?php echo $cat['id']; ?>, 'modalCategoria')" title="Editar categoría"><i class="fas fa-edit"></i> Editar</button>
                                    <button class="btn btn-danger btn-sm" onclick="eliminarRegistro('categorias_productos', <?php echo $cat['id']; ?>, 'categoria')" title="Eliminar categoría"><i class="fas fa-trash"></i> Eliminar</button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="modalCategoria" class="modal">
            <div class="modal-content">
                <div class="modal-header"><h4><i class="fas fa-plus"></i> Nueva Categoría</h4><button class="modal-close">&times;</button></div>
                <form id="formCategoria">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="categoriaId">
                        <div class="form-group"><label>Nombre</label><input type="text" class="form-control" name="nombre" id="categoriaNombre" required></div>
                        <div class="form-group"><label>Descripción</label><textarea class="form-control" name="descripcion" id="categoriaDescripcion"></textarea></div>
                        <div class="form-group"><label>Estado</label><select class="form-control" name="estado" id="categoriaEstado"><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="cerrarModal('modalCategoria')">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion == 'proveedores'): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-truck"></i> Proveedores</h3>
                <div class="card-tools">
                    <button class="btn btn-primary btn-sm" onclick="abrirModal('modalProveedor')"><i class="fas fa-plus"></i> Nuevo Proveedor</button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th style="display: none;">ID</th>
                                <th>Nombre</th>
                                <th>Contacto</th>
                                <th>Teléfono</th>
                                <th>Email</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $proveedores = $conn->query("SELECT * FROM proveedores ORDER BY id"); while($prov = $proveedores->fetch_assoc()): ?>
                            <tr>
                                <td style="display: none;"><?php echo $prov['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($prov['nombre']); ?></strong></td>
                                <td><?php echo htmlspecialchars($prov['contacto'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($prov['telefono'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($prov['email'] ?? '-'); ?></td>
                                <td><span class="badge <?php echo $prov['estado'] == 'activo' ? 'badge-success' : 'badge-danger'; ?>"><?php echo $prov['estado'] == 'activo' ? 'Activo' : 'Inactivo'; ?></span></td>
                                <td>
                                    <button class="btn btn-warning btn-sm" onclick="editarRegistro('proveedores', <?php echo $prov['id']; ?>, 'modalProveedor')" title="Editar proveedor"><i class="fas fa-edit"></i> Editar</button>
                                    <button class="btn btn-danger btn-sm" onclick="eliminarRegistro('proveedores', <?php echo $prov['id']; ?>, 'proveedor')" title="Eliminar proveedor"><i class="fas fa-trash"></i> Eliminar</button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="modalProveedor" class="modal">
            <div class="modal-content">
                <div class="modal-header"><h4><i class="fas fa-plus"></i> Nuevo Proveedor</h4><button class="modal-close">&times;</button></div>
                <form id="formProveedor">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="proveedorId">
                        <div class="form-group"><label>Nombre</label><input type="text" class="form-control" name="nombre" id="proveedorNombre" required></div>
                        <div class="form-group"><label>Contacto</label><input type="text" class="form-control" name="contacto" id="proveedorContacto"></div>
                        <div class="form-group"><label>Teléfono</label><input type="text" class="form-control" name="telefono" id="proveedorTelefono"></div>
                        <div class="form-group"><label>Email</label><input type="email" class="form-control" name="email" id="proveedorEmail"></div>
                        <div class="form-group"><label>Dirección</label><textarea class="form-control" name="direccion" id="proveedorDireccion"></textarea></div>
                        <div class="form-group"><label>Estado</label><select class="form-control" name="estado" id="proveedorEstado"><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="cerrarModal('modalProveedor')">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion == 'clases'): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chalkboard-user"></i> Clases del Gimnasio</h3>
                <div class="card-tools">
                    <button class="btn btn-primary btn-sm" onclick="abrirModal('modalClase')"><i class="fas fa-plus"></i> Nueva Clase</button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th style="display: none;">ID</th>
                                <th>Nombre</th>
                                <th>Horario</th>
                                <th>Instructor</th>
                                <th>Cupo</th>
                                <th>Duración</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $clases = $conn->query("SELECT * FROM clases ORDER BY id"); while($clase = $clases->fetch_assoc()): ?>
                            <tr>
                                <td style="display: none;"><?php echo $clase['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($clase['nombre']); ?></strong></td>
                                <td><?php echo htmlspecialchars($clase['horario']); ?></td>
                                <td><?php echo htmlspecialchars($clase['instructor']); ?></td>
                                <td><?php echo $clase['cupo_actual']; ?>/<?php echo $clase['cupo_maximo']; ?></td>
                                <td><?php echo $clase['duracion_minutos']; ?> min</td>
                                <td><span class="badge <?php echo $clase['estado'] == 'activa' ? 'badge-success' : 'badge-danger'; ?>"><?php echo $clase['estado'] == 'activa' ? 'Activa' : 'Inactiva'; ?></span></td>
                                <td>
                                    <button class="btn btn-warning btn-sm" onclick="editarRegistro('clases', <?php echo $clase['id']; ?>, 'modalClase')" title="Editar clase"><i class="fas fa-edit"></i> Editar</button>
                                    <button class="btn btn-danger btn-sm" onclick="eliminarRegistro('clases', <?php echo $clase['id']; ?>, 'clase')" title="Eliminar clase"><i class="fas fa-trash"></i> Eliminar</button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="modalClase" class="modal">
            <div class="modal-content">
                <div class="modal-header"><h4><i class="fas fa-plus"></i> Nueva Clase</h4><button class="modal-close">&times;</button></div>
                <form id="formClase">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="claseId">
                        <div class="form-group"><label>Nombre</label><input type="text" class="form-control" name="nombre" id="claseNombre" required></div>
                        <div class="form-group"><label>Descripción</label><textarea class="form-control" name="descripcion" id="claseDescripcion"></textarea></div>
                        <div class="form-group"><label>Horario</label><input type="text" class="form-control" name="horario" id="claseHorario" placeholder="Ej: Lunes y Miércoles 7pm-8pm" required></div>
                        <div class="form-group"><label>Instructor</label><input type="text" class="form-control" name="instructor" id="claseInstructor" required></div>
                        <div class="form-group"><label>Cupo Máximo</label><input type="number" class="form-control" name="cupo_maximo" id="claseCupo" required></div>
                        <div class="form-group"><label>Duración (minutos)</label><input type="number" class="form-control" name="duracion_minutos" id="claseDuracion" value="60"></div>
                        <div class="form-group"><label>Estado</label><select class="form-control" name="estado" id="claseEstado"><option value="activa">Activa</option><option value="inactiva">Inactiva</option></select></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="cerrarModal('modalClase')">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion == 'usuarios'): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-user-shield"></i> Usuarios del Sistema</h3>
                <div class="card-tools">
                    <button class="btn btn-primary btn-sm" onclick="abrirModal('modalUsuario')">
                        <i class="fas fa-plus"></i> Nuevo Usuario
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th style="display: none;">ID</th>
                                <th>Nombre</th>
                                <th>Email</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Acceso</th>
                                <th>Fecha Registro</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $usuarios = $conn->query("SELECT * FROM usuarios ORDER BY id");
                            $roles_map = ['admin' => 'Administrador', 'recepcionista' => 'Recepcionista', 'entrenador' => 'Entrenador'];
                            while($user = $usuarios->fetch_assoc()):
                            ?>
                            <tr>
                                <td style="display: none;"><?php echo $user['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($user['nombre']); ?></strong></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><span class="badge badge-info"><?php echo $roles_map[$user['rol']] ?? $user['rol']; ?></span></td>
                                <td>
                                    <span class="badge <?php echo $user['estado'] === 'activo' ? 'badge-success' : 'badge-danger'; ?>">
                                        <?php echo $user['estado'] === 'activo' ? 'Activo' : 'Eliminado'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($user['estado'] !== 'activo'): ?>
                                        <span class="badge badge-secondary">
                                            <i class="fas fa-ban"></i> Sin acceso
                                        </span>
                                    <?php elseif ((int) $user['password_change_required'] === 1): ?>
                                        <span class="badge badge-warning">
                                            <i class="fas fa-clock"></i> Debe cambiar contraseña
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-success">
                                            <i class="fas fa-check-circle"></i> Normal
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d/m/Y', strtotime($user['fecha_registro'])); ?></td>
                                <td class="text-nowrap">
                                    <button class="btn btn-warning btn-sm" onclick="editarUsuario(<?php echo $user['id']; ?>)" title="Editar información del usuario"><i class="fas fa-edit"></i> Editar</button>
                                    <?php if($user['id'] != 1 && $user['estado'] === 'activo'): ?>
                                    <button class="btn btn-danger btn-sm" onclick="eliminarRegistro('usuarios', <?php echo $user['id']; ?>, 'usuario')" title="Eliminar usuario del sistema"><i class="fas fa-trash"></i> Eliminar</button>
                                    <?php endif; ?>

                                    <?php
                                    $restablecimientoPendiente =
                                        (int) $user['password_change_required'] === 1;
                                    $usuarioSinAcceso =
                                        $user['estado'] !== 'activo';
                                    $bloquearRestablecer =
                                        $restablecimientoPendiente ||
                                        $usuarioSinAcceso;
                                    ?>

                                    <button
                                        class="btn btn-secondary btn-sm"
                                        <?php if (!$bloquearRestablecer): ?>
                                            onclick='restablecerPassword(
                                                <?php echo (int) $user["id"]; ?>,
                                                <?php echo htmlspecialchars(
                                                    json_encode($user["nombre"]),
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ); ?>
                                            )'
                                        <?php else: ?>
                                            disabled
                                        <?php endif; ?>
                                        title="<?php
                                        echo $usuarioSinAcceso
                                            ? 'El usuario no tiene acceso al sistema'
                                            : ($restablecimientoPendiente
                                                ? 'El usuario ya tiene un cambio de contraseña pendiente'
                                                : 'Restablecer a la contraseña predeterminada ego1');
                                        ?>"
                                    >
                                        <i class="fas <?php echo $restablecimientoPendiente ? 'fa-clock' : 'fa-key'; ?>"></i>
                                        <?php echo $restablecimientoPendiente ? 'Pendiente' : 'Restablecer'; ?>
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="modalUsuario" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h4><i class="fas fa-user-plus"></i> Nuevo Usuario</h4>
                    <button class="modal-close">&times;</button>
                </div>
                <form id="formUsuario">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="usuarioId">
                        <div class="form-group"><label><i class="fas fa-user"></i> Nombre Completo</label><input type="text" class="form-control" name="nombre" id="usuarioNombre" required></div>
                        <div class="form-group"><label><i class="fas fa-envelope"></i> Email</label><input type="email" class="form-control" name="email" id="usuarioEmail" required></div>
                        <div class="form-group"><label><i class="fas fa-user-tag"></i> Rol</label><select class="form-control" name="rol" id="usuarioRol" required><option value="recepcionista">Recepcionista</option><option value="entrenador">Entrenador</option><option value="admin">Administrador</option></select></div>
                        <div class="form-group"><label><i class="fas fa-circle"></i> Estado</label><select class="form-control" name="estado" id="usuarioEstado"><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select></div>
                        <div class="alert alert-info alert-ocultable" data-alerta-id="usuario_info">
                            <i class="fas fa-info-circle"></i> <strong>Información:</strong> Los nuevos usuarios recibirán por correo la contraseña temporal <strong>ego1</strong>. Deberán cambiarla durante el primer inicio de sesión.
                            <button type="button" class="btn-ocultar" onclick="event.preventDefault(); event.stopPropagation(); ocultarAlerta('usuario_info')" title="Ocultar alerta">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalUsuario')">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar Usuario</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="modalRestablecer" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h4><i class="fas fa-key"></i> Restablecer Contraseña</h4>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="resetUsuarioId">
                    <input type="hidden" id="resetUsuarioNombre">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>¿Está seguro?</strong>
                        <p class="mt-2 mb-0">
                            La contraseña de <strong id="resetNombreMostrar"></strong>
                            se restablecerá a la contraseña predeterminada
                            <strong>ego1</strong> y se enviará por correo.
                        </p>
                        <p class="mt-2 mb-0 text-muted small">
                            El usuario deberá cambiarla durante su próximo inicio de sesión.
                        </p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalRestablecer')">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="confirmarRestablecer()">Sí, restablecer</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <script>
        // Funciones de Modal
        function abrirModal(modalId) {
            $('#' + modalId).addClass('active');
        }

        function cerrarModal(modalId) {
            $('#' + modalId).removeClass('active');
            $('#' + modalId + ' form')[0]?.reset();
            $('#' + modalId + ' input[name="id"]').val('');
            // Restaurar título del modal a su estado original
            let tituloOriginal = '';
            switch(modalId) {
                case 'modalPlan': tituloOriginal = 'Nuevo Plan'; break;
                case 'modalCategoria': tituloOriginal = 'Nueva Categoría'; break;
                case 'modalProveedor': tituloOriginal = 'Nuevo Proveedor'; break;
                case 'modalProducto': tituloOriginal = 'Nuevo Producto'; break;
                case 'modalClase': tituloOriginal = 'Nueva Clase'; break;
                case 'modalUsuario': tituloOriginal = 'Nuevo Usuario'; break;
                case 'modalCliente': tituloOriginal = 'Nuevo Cliente'; break;
                default: tituloOriginal = 'Nuevo Registro';
            }
            $('#' + modalId + ' .modal-header h4').html('<i class="fas fa-plus"></i> ' + tituloOriginal);
        }

        $(document).ready(function() {
            $('.modal-close').on('click', function() {
                $(this).closest('.modal').removeClass('active');
            });

            $('.modal').on('click', function(e) {
                if (e.target === this) {
                    $(this).removeClass('active');
                }
            });
            cargarEstadoAlertas();
        });

        // Funciones genéricas para editar y eliminar
        function editarRegistro(tabla, id, modalId) {
            console.log('Editando registro - Tabla:', tabla, 'ID:', id, 'Modal:', modalId);

            $.ajax({
                url: 'configuracion.php',
                method: 'POST',
                data: { action: 'get_registro', tabla: tabla, id: id },
                dataType: 'json',
                success: function(data) {
                    console.log('Datos recibidos:', data);

                    let form = $('#' + modalId + ' form');
                    let modal = $('#' + modalId);

                    // Limpiar el formulario primero
                    if (form[0]) form[0].reset();

                    // Asignar el ID
                    form.find('input[name="id"]').val(data.id);

                    // Llenar los demás campos
                    for(let key in data) {
                        let input = form.find('[name="' + key + '"]');
                        if(input.length) {
                            input.val(data[key]);
                            console.log('Campo ' + key + ' asignado con valor:', data[key]);
                        }
                    }

                    // Cambiar el título del modal
                    let titulo = '';
                    switch(tabla) {
                        case 'planes': titulo = 'Editar Plan'; break;
                        case 'categorias_productos': titulo = 'Editar Categoría'; break;
                        case 'proveedores': titulo = 'Editar Proveedor'; break;
                        case 'productos': titulo = 'Editar Producto'; break;
                        case 'clases': titulo = 'Editar Clase'; break;
                        case 'usuarios': titulo = 'Editar Usuario'; break;
                        default: titulo = 'Editar Registro';
                    }
                    modal.find('.modal-header h4').html('<i class="fas fa-edit"></i> ' + titulo);

                    // Abrir el modal
                    abrirModal(modalId);
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'No se pudo cargar el registro. Detalles: ' + error,
                        target: document.body
                    });
                }
            });
        }

        function eliminarRegistro(tabla, id, tipo) {
            const esUsuario = tipo === 'usuario';

            Swal.fire({
                title: esUsuario
                    ? '¿Eliminar usuario?'
                    : '¿Eliminar registro?',
                html: esUsuario
                    ? '<p>El usuario dejará de tener acceso al sistema.</p>' +
                      '<p style="margin-bottom:0;font-size:13px;color:#667085;">' +
                      'Su historial de ventas, movimientos y registros se ' +
                      'conservará para mantener la integridad de la información.' +
                      '</p>'
                    : 'Esta acción no se puede deshacer',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar',
                target: document.body
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }

                let action = '';
                if (tipo === 'plan') action = 'delete_plan';
                else if (tipo === 'categoria') action = 'delete_categoria';
                else if (tipo === 'proveedor') action = 'delete_proveedor';
                else if (tipo === 'producto') action = 'delete_producto';
                else if (tipo === 'clase') action = 'delete_clase';
                else if (tipo === 'usuario') action = 'delete_usuario';

                $.ajax({
                    url: 'configuracion.php',
                    method: 'POST',
                    dataType: 'json',
                    data: { action: action, id: id },
                    success: function(response) {
                        if (!response.success) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message || 'No se pudo eliminar.',
                                target: document.body
                            });
                            return;
                        }

                        Swal.fire({
                            icon: 'success',
                            title: 'Eliminado',
                            text: response.message || 'Registro eliminado correctamente.',
                            target: document.body,
                            timer: 1500,
                            showConfirmButton: false
                        });

                        setTimeout(() => location.reload(), 1500);
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'No se pudo eliminar el registro.',
                            target: document.body
                        });
                    }
                });
            });
        }

        // Funciones para Clientes
        function editarCliente(id) {
            $.ajax({
                url: 'configuracion.php',
                method: 'POST',
                data: { action: 'get_registro', tabla: 'clientes', id: id },
                dataType: 'json',
                success: function(data) {
                    $('#clienteId').val(data.id);
                    $('#clienteNombre').val(data.nombre);
                    $('#clienteApellido').val(data.apellido);
                    $('#clienteTelefono').val(data.telefono);
                    $('#clienteEmail').val(data.email);
                    $('#clienteEstado').val(data.estado);
                    $('#modalCliente .modal-header h4').html('<i class="fas fa-edit"></i> Editar Cliente');
                    abrirModal('modalCliente');
                }
            });
        }

        function eliminarCliente(id) {
            Swal.fire({
                title: '¿Eliminar cliente?',
                text: 'Esta acción no se puede deshacer',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar',
                target: document.body
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'configuracion.php',
                        method: 'POST',
                        data: { action: 'delete_cliente', id: id },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({ icon: 'success', title: 'Eliminado', text: 'Cliente eliminado correctamente', target: document.body, timer: 1500, showConfirmButton: false });
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                Swal.fire({ icon: 'error', title: 'Error', text: response.error || 'No se pudo eliminar', target: document.body });
                            }
                        }
                    });
                }
            });
        }

        let qrActual = '';
        let qrNombreActual = '';

        function renderQr(elemento, valor, tamano) {
            const contenedor =
                typeof elemento === 'string'
                    ? document.getElementById(elemento)
                    : elemento;

            if (!contenedor) return;

            contenedor.innerHTML = '';

            if (!valor) {
                contenedor.innerHTML =
                    '<i class="fas fa-qrcode qr-empty-icon"></i>';
                return;
            }

            new QRCode(contenedor, {
                text: valor,
                width: tamano,
                height: tamano,
                colorDark: '#15263a',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        }

        function renderQrMiniaturas() {
            document
                .querySelectorAll('.qr-mini')
                .forEach(function(elemento) {
                    renderQr(
                        elemento,
                        elemento.dataset.qr || '',
                        64
                    );
                });
        }

        function verQrSocio(id, nombre, codigo) {
            qrActual = codigo || '';
            qrNombreActual = nombre || '';

            $('#qrClienteId').val(id);
            $('#qrClienteNombre').text(nombre);
            $('#qrCodigoTexto').text(
                qrActual || 'Sin código QR'
            );

            renderQr('qrGrande', qrActual, 210);
            abrirModal('modalQr');
        }

        async function copiarQr() {
            if (!qrActual) return;

            try {
                await navigator.clipboard.writeText(qrActual);

                Swal.fire({
                    icon: 'success',
                    title: 'Código copiado',
                    timer: 1200,
                    showConfirmButton: false
                });
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'No se pudo copiar'
                });
            }
        }

        function imprimirQr() {
            if (!qrActual) return;

            const contenedor =
                document.getElementById('qrGrande');

            const canvas =
                contenedor.querySelector('canvas');

            const imagen =
                contenedor.querySelector('img');

            let dataUrl = '';

            if (canvas) {
                dataUrl = canvas.toDataURL('image/png');
            } else if (imagen) {
                dataUrl = imagen.src;
            }

            if (!dataUrl) return;

            const ventana = window.open(
                '',
                '_blank',
                'width=520,height=650'
            );

            ventana.document.write(
                '<!DOCTYPE html>' +
                '<html lang="es">' +
                '<head>' +
                '<meta charset="UTF-8">' +
                '<title>QR del socio</title>' +
                '<style>' +
                'body{font-family:Arial,sans-serif;' +
                'margin:0;padding:30px;text-align:center;' +
                'color:#15263a;}' +
                '.qr-ticket{width:320px;margin:auto;' +
                'padding:24px;border:1px solid #dce3eb;' +
                'border-radius:12px;}' +
                'img{width:240px;height:240px;}' +
                'h2{margin:16px 0 6px;}' +
                'p{font-family:monospace;font-size:11px;' +
                'color:#667085;word-break:break-all;}' +
                '@media print{body{padding:0;}' +
                '.qr-ticket{border:0;}}' +
                '</style>' +
                '</head>' +
                '<body>' +
                '<div class="qr-ticket">' +
                '<img src="' + dataUrl + '" alt="QR">' +
                '<h2>' + $('<div>').text(qrNombreActual).html() +
                '</h2>' +
                '<p>' + $('<div>').text(qrActual).html() +
                '</p>' +
                '</div>' +
                '<script>window.onload=function(){window.print();};' +
                '<\/script>' +
                '</body>' +
                '</html>'
            );

            ventana.document.close();
        }

        // Funciones para Productos
        function editarProducto(id) {
            $.ajax({
                url: 'configuracion.php',
                method: 'POST',
                data: { action: 'get_registro', tabla: 'productos', id: id },
                dataType: 'json',
                success: function(data) {
                    $('#productoId').val(data.id);
                    $('#productoNombre').val(data.nombre);
                    $('#productoDescripcion').val(data.descripcion);
                    $('#productoCategoria').val(data.categoria_id);
                    $('#productoProveedor').val(data.proveedor_id);
                    $('#productoPrecioCompra').val(data.precio_compra);
                    $('#productoPrecioVenta').val(data.precio_venta);
                    $('#productoStock').val(data.stock);
                    $('#productoStockMinimo').val(data.stock_minimo);
                    $('#productoEstado').val(data.estado);
                    $('#modalProducto .modal-header h4').html('<i class="fas fa-edit"></i> Editar Producto');
                    abrirModal('modalProducto');
                }
            });
        }

        // Funciones para Usuarios
        function editarUsuario(id) {
            $.ajax({
                url: 'configuracion.php',
                method: 'POST',
                data: { action: 'get_registro', tabla: 'usuarios', id: id },
                dataType: 'json',
                success: function(data) {
                    $('#usuarioId').val(data.id);
                    $('#usuarioNombre').val(data.nombre);
                    $('#usuarioEmail').val(data.email);
                    $('#usuarioRol').val(data.rol);
                    $('#usuarioEstado').val(data.estado);
                    $('#modalUsuario .modal-header h4').html('<i class="fas fa-edit"></i> Editar Usuario');
                    abrirModal('modalUsuario');
                }
            });
        }

        function restablecerPassword(id, nombre) {
            $('#resetUsuarioId').val(id);
            $('#resetUsuarioNombre').val(nombre);
            $('#resetNombreMostrar').text(nombre);
            abrirModal('modalRestablecer');
        }

        function confirmarRestablecer() {
            let id = $('#resetUsuarioId').val();
            let nombre = $('#resetUsuarioNombre').val();

            Swal.fire({
                title: 'Restablecer contraseña',
                html:
                    'La contraseña de <strong>' +
                    $('<div>').text(nombre).html() +
                    '</strong> se restablecerá a la contraseña ' +
                    'predeterminada <strong>ego1</strong> y se enviará ' +
                    'por correo. El usuario deberá cambiarla en su ' +
                    'próximo inicio de sesión.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí, restablecer',
                cancelButtonText: 'Cancelar',
                target: document.body
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }

                Swal.fire({
                    title: 'Actualizando contraseña...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading(),
                    target: document.body
                });

                $.ajax({
                    url: 'configuracion.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'cambiar_password',
                        id: id
                    },
                    success: function(response) {
                        if (!response.success) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text:
                                    response.message ||
                                    'No se pudo restablecer.',
                                target: document.body
                            });
                            return;
                        }

                        cerrarModal('modalRestablecer');

                        if (response.correo_enviado) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Contraseña restablecida',
                                text:
                                    'La contraseña se restableció a ego1. ' +
                                    'Las credenciales fueron enviadas por correo.',
                                target: document.body
                            });
                        } else {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Contraseña restablecida',
                                html:
                                    '<p>El correo no pudo enviarse.</p>' +
                                    '<p><strong>Contraseña temporal:' +
                                    '</strong></p>' +
                                    '<code style="font-size:16px;">' +
                                    $('<div>')
                                        .text(
                                            response.password_temporal ||
                                            ''
                                        )
                                        .html() +
                                    '</code>' +
                                    '<p style="' +
                                    'margin-top:12px;font-size:12px;' +
                                    'color:#667085;">' +
                                    $('<div>')
                                        .text(
                                            response.correo_error || ''
                                        )
                                        .html() +
                                    '</p>',
                                target: document.body
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text:
                                'No se pudo restablecer la contraseña.',
                            target: document.body
                        });
                    }
                });
            });
        }

        // ==================== FUNCIONES PARA ALERTAS OCULTABLES ====================
        function ocultarAlerta(alertaId) {
            localStorage.setItem('alerta_oculta_' + alertaId, 'true');
            let $alerta = $('[data-alerta-id="' + alertaId + '"]');
            $alerta.addClass('oculto');

            let textoBoton = '';
            let iconoBoton = '';
            switch(alertaId) {
                case 'info_gimnasio': textoBoton = 'Ver consejo'; iconoBoton = 'fa-lightbulb'; break;
                case 'huella_info': textoBoton = 'Ver instrucciones'; iconoBoton = 'fa-fingerprint'; break;
                case 'usuario_info': textoBoton = 'Más información'; iconoBoton = 'fa-info-circle'; break;
                case 'logo_info': textoBoton = 'Mostrar recomendaciones'; iconoBoton = 'fa-image'; break;
                default: textoBoton = 'Mostrar alerta'; iconoBoton = 'fa-eye';
            }

            if ($alerta.next('.alert-boton-container').length === 0) {
                let $contenedor = $('<div class="alert-boton-container"></div>');
                let $botonMostrar = $('<button class="btn-mostrar-alerta" onclick="mostrarAlertaEspecifica(\'' + alertaId + '\')" title="Mostrar esta alerta nuevamente"><i class="fas ' + iconoBoton + '"></i> ' + textoBoton + '</button>');
                $contenedor.append($botonMostrar);
                $alerta.after($contenedor);
            }
        }

        function mostrarAlertaEspecifica(alertaId) {
            let $alerta = $('[data-alerta-id="' + alertaId + '"]');
            $alerta.removeClass('oculto');
            $alerta.next('.alert-boton-container').remove();
            localStorage.removeItem('alerta_oculta_' + alertaId);
        }

        function cargarEstadoAlertas() {
            $('.alert-ocultable').each(function() {
                let alertaId = $(this).data('alerta-id');
                if (alertaId) {
                    let estaOculta = localStorage.getItem('alerta_oculta_' + alertaId) === 'true';
                    if (estaOculta) {
                        $(this).addClass('oculto');

                        let textoBoton = '';
                        let iconoBoton = '';
                        switch(alertaId) {
                            case 'info_gimnasio': textoBoton = 'Ver consejo'; iconoBoton = 'fa-lightbulb'; break;
                            case 'huella_info': textoBoton = 'Ver instrucciones'; iconoBoton = 'fa-fingerprint'; break;
                            case 'usuario_info': textoBoton = 'Más información'; iconoBoton = 'fa-info-circle'; break;
                            case 'logo_info': textoBoton = 'Mostrar recomendaciones'; iconoBoton = 'fa-image'; break;
                            default: textoBoton = 'Mostrar alerta'; iconoBoton = 'fa-eye';
                        }

                        if ($(this).next('.alert-boton-container').length === 0) {
                            let $contenedor = $('<div class="alert-boton-container"></div>');
                            let $botonMostrar = $('<button class="btn-mostrar-alerta" onclick="mostrarAlertaEspecifica(\'' + alertaId + '\')" title="Mostrar esta alerta nuevamente"><i class="fas ' + iconoBoton + '"></i> ' + textoBoton + '</button>');
                            $contenedor.append($botonMostrar);
                            $(this).after($contenedor);
                        }
                    } else {
                        $(this).removeClass('oculto');
                        $(this).next('.alert-boton-container').remove();
                    }
                }
            });
        }

        // ==================== ENVÍO DE FORMULARIOS (UN SOLO MANEJADOR) ====================
        $(document).ready(function() {
            // Vista previa del logo
            $('#logo').on('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const tiposPermitidos = ['image/jpeg', 'image/jpg', 'image/png'];
                    if (!tiposPermitidos.includes(file.type)) {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Solo se permiten archivos JPG, JPEG y PNG', target: document.body });
                        $(this).val('');
                        return;
                    }
                    if (file.size > 2 * 1024 * 1024) {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'El archivo no puede superar los 2MB', target: document.body });
                        $(this).val('');
                        return;
                    }
                    const reader = new FileReader();
                    reader.onload = function(e) { $('#preview_logo').attr('src', e.target.result); }
                    reader.readAsDataURL(file);
                    $(this).next('.custom-file-label').html(file.name);
                } else {
                    $(this).next('.custom-file-label').html('Seleccionar logo (PNG, JPG, JPEG)');
                }
            });

            // Formulario de Información del Gimnasio (con logo)
            $('#formInfoGimnasio').on('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                formData.append('action', 'save_config');

                $.ajax({
                    url: 'configuracion.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({ icon: 'success', title: '¡Éxito!', text: response.message, target: document.body, showConfirmButton: false, timer: 2000 })
                                .then(() => { location.reload(); });
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: response.message, target: document.body });
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Ocurrió un error al guardar la configuración: ' + error, target: document.body });
                    }
                });
            });

            // UN SOLO MANEJADOR PARA TODOS LOS DEMÁS FORMULARIOS
            $('#formPlan, #formCategoria, #formProveedor, #formProducto, #formClase, #formUsuario, #formCliente').on('submit', function(e) {
                e.preventDefault();

                let action = '';
                const formId = $(this).attr('id');

                if (formId === 'formPlan') action = 'save_plan';
                else if (formId === 'formCategoria') action = 'save_categoria';
                else if (formId === 'formProveedor') action = 'save_proveedor';
                else if (formId === 'formProducto') action = 'save_producto';
                else if (formId === 'formClase') action = 'save_clase';
                else if (formId === 'formUsuario') action = 'save_usuario';
                else if (formId === 'formCliente') action = 'save_cliente';

                let data =
                    $(this).serialize() +
                    '&action=' +
                    action;

                Swal.fire({
                    title: 'Guardando...',
                    text: 'Por favor espere',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading(),
                    target: document.body
                });

                $.ajax({
                    url: 'configuracion.php',
                    method: 'POST',
                    data: data,
                    dataType: 'json',
                    success: function(res) {
                        if (!res.success) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text:
                                    res.message ||
                                    res.error ||
                                    'No se pudo guardar.',
                                target: document.body
                            });
                            return;
                        }

                        if (
                            formId === 'formUsuario' &&
                            res.usuario_nuevo
                        ) {
                            if (res.correo_enviado) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Usuario creado',
                                    text:
                                        'Las credenciales fueron enviadas ' +
                                        'al correo registrado.',
                                    target: document.body,
                                    confirmButtonText: 'Aceptar'
                                }).then(() => location.reload());
                            } else {
                                Swal.fire({
                                    icon: 'warning',
                                    title: 'Usuario creado sin correo',
                                    html:
                                        '<p>No fue posible enviar las ' +
                                        'credenciales.</p>' +
                                        '<p><strong>Contraseña temporal:' +
                                        '</strong></p>' +
                                        '<code style="font-size:16px;">' +
                                        $('<div>')
                                            .text(
                                                res.password_temporal || ''
                                            )
                                            .html() +
                                        '</code>' +
                                        '<p style="' +
                                        'margin-top:12px;font-size:12px;' +
                                        'color:#667085;">' +
                                        $('<div>')
                                            .text(
                                                res.correo_error || ''
                                            )
                                            .html() +
                                        '</p>',
                                    target: document.body,
                                    confirmButtonText: 'Aceptar'
                                }).then(() => location.reload());
                            }

                            return;
                        }

                        Swal.fire({
                            icon: 'success',
                            title: 'Guardado',
                            text:
                                res.message ||
                                'Registro guardado correctamente.',
                            target: document.body,
                            timer: 1500,
                            showConfirmButton: false
                        });

                        setTimeout(
                            () => location.reload(),
                            1500
                        );
                    },
                    error: function(xhr, status, error) {
                        let message = 'Ocurrió un error: ' + error;

                        if (
                            xhr.responseJSON &&
                            xhr.responseJSON.message
                        ) {
                            message = xhr.responseJSON.message;
                        }

                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: message,
                            target: document.body
                        });
                    }
                });
            });

            renderQrMiniaturas();
            inicializarListadosPaginados();
            inicializarCorreo();
        });

        function inicializarListadosPaginados() {
            const tablas = document.querySelectorAll(
                '.card .table-responsive > table.table'
            );

            tablas.forEach(function(tabla, indiceTabla) {
                if (
                    tabla.dataset.managedList === 'true'
                ) {
                    return;
                }

                tabla.dataset.managedList = 'true';
                tabla.classList.add(
                    'responsive-card-table'
                );

                const headers = Array.from(
                    tabla.querySelectorAll('thead th')
                ).map(function(th) {
                    return th.textContent.trim();
                });

                const filas = Array.from(
                    tabla.querySelectorAll('tbody tr')
                );

                filas.forEach(function(fila) {
                    Array.from(fila.children)
                        .forEach(function(celda, indice) {
                            celda.setAttribute(
                                'data-label',
                                headers[indice] || ''
                            );
                        });
                });

                const wrapper =
                    tabla.closest('.table-responsive');

                const body = wrapper.parentElement;
                const toolbar =
                    document.createElement('div');

                toolbar.className =
                    'managed-list-toolbar';

                toolbar.innerHTML =
                    '<div class="managed-list-search">' +
                        '<i class="fas fa-magnifying-glass">' +
                        '</i>' +
                        '<input type="search" ' +
                        'placeholder="Buscar en esta sección...">' +
                    '</div>' +
                    '<span class="managed-list-count"></span>';

                body.insertBefore(toolbar, wrapper);

                const paginacion =
                    document.createElement('div');

                paginacion.className =
                    'managed-pagination';

                body.appendChild(paginacion);

                const input =
                    toolbar.querySelector('input');

                const count =
                    toolbar.querySelector(
                        '.managed-list-count'
                    );

                const porPagina = 9;
                let paginaActual = 1;
                let filtradas = filas.slice();

                function renderLista() {
                    const totalPaginas = Math.max(
                        1,
                        Math.ceil(
                            filtradas.length /
                            porPagina
                        )
                    );

                    paginaActual = Math.min(
                        paginaActual,
                        totalPaginas
                    );

                    filas.forEach(function(fila) {
                        fila.classList.add(
                            'list-hidden'
                        );
                    });

                    const inicio =
                        (paginaActual - 1) *
                        porPagina;

                    filtradas
                        .slice(
                            inicio,
                            inicio + porPagina
                        )
                        .forEach(function(fila) {
                            fila.classList.remove(
                                'list-hidden'
                            );
                        });

                    count.textContent =
                        filtradas.length +
                        (
                            filtradas.length === 1
                                ? ' registro'
                                : ' registros'
                        );

                    renderPaginacion(totalPaginas);

                    let empty =
                        body.querySelector(
                            '.managed-list-empty'
                        );

                    if (filtradas.length === 0) {
                        if (!empty) {
                            empty =
                                document.createElement(
                                    'div'
                                );

                            empty.className =
                                'managed-list-empty';

                            empty.innerHTML =
                                '<i class="fas ' +
                                'fa-folder-open fa-2x ' +
                                'mb-2"></i>' +
                                '<p>No hay resultados ' +
                                'para la búsqueda.</p>';

                            body.insertBefore(
                                empty,
                                paginacion
                            );
                        }

                        wrapper.style.display = 'none';
                        paginacion.style.display = 'none';
                    } else {
                        if (empty) {
                            empty.remove();
                        }

                        wrapper.style.display = '';
                        paginacion.style.display =
                            totalPaginas > 1
                                ? 'flex'
                                : 'none';
                    }
                }

                function renderPaginacion(
                    totalPaginas
                ) {
                    paginacion.innerHTML = '';

                    function agregarBoton(
                        contenido,
                        pagina,
                        activo,
                        deshabilitado
                    ) {
                        const boton =
                            document.createElement(
                                'button'
                            );

                        boton.type = 'button';
                        boton.className =
                            'managed-page-btn' +
                            (activo ? ' active' : '');

                        boton.innerHTML = contenido;
                        boton.disabled =
                            Boolean(deshabilitado);

                        boton.addEventListener(
                            'click',
                            function() {
                                paginaActual = pagina;
                                renderLista();

                                tabla.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'start'
                                });
                            }
                        );

                        paginacion.appendChild(boton);
                    }

                    agregarBoton(
                        '<i class="fas ' +
                        'fa-chevron-left"></i>',
                        Math.max(
                            1,
                            paginaActual - 1
                        ),
                        false,
                        paginaActual === 1
                    );

                    const inicio = Math.max(
                        1,
                        paginaActual - 2
                    );

                    const fin = Math.min(
                        totalPaginas,
                        paginaActual + 2
                    );

                    for (
                        let pagina = inicio;
                        pagina <= fin;
                        pagina++
                    ) {
                        agregarBoton(
                            String(pagina),
                            pagina,
                            pagina === paginaActual,
                            false
                        );
                    }

                    agregarBoton(
                        '<i class="fas ' +
                        'fa-chevron-right"></i>',
                        Math.min(
                            totalPaginas,
                            paginaActual + 1
                        ),
                        false,
                        paginaActual === totalPaginas
                    );
                }

                let timeoutBusqueda;

                input.addEventListener(
                    'input',
                    function() {
                        clearTimeout(
                            timeoutBusqueda
                        );

                        timeoutBusqueda =
                            setTimeout(function() {
                                const termino =
                                    input.value
                                        .trim()
                                        .toLowerCase();

                                filtradas =
                                    filas.filter(
                                        function(fila) {
                                            return fila
                                                .textContent
                                                .toLowerCase()
                                                .includes(
                                                    termino
                                                );
                                        }
                                    );

                                paginaActual = 1;
                                renderLista();
                            }, 220);
                    }
                );

                renderLista();
            });
        }

        function inicializarCorreo() {
            const $form = $('#formCorreo');

            if ($form.length) {
                $form.on('submit', function(e) {
                    e.preventDefault();

                    const data =
                        $form.serialize() +
                        '&action=save_email_config';

                    Swal.fire({
                        title:
                            'Guardando configuración...',
                        allowOutsideClick: false,
                        didOpen: () =>
                            Swal.showLoading()
                    });

                    $.ajax({
                        url: 'configuracion.php',
                        method: 'POST',
                        data: data,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title:
                                        'Configuración guardada',
                                    text:
                                        response.message,
                                    timer: 1600,
                                    showConfirmButton: false
                                }).then(
                                    () =>
                                        location.reload()
                                );
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text:
                                        response.message ||
                                        'No se pudo guardar.'
                                });
                            }
                        },
                        error: function() {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text:
                                    'No se pudo guardar la ' +
                                    'configuración.'
                            });
                        }
                    });
                });
            }
        }

        // Función para eliminar logo
        function eliminarLogo() {
            Swal.fire({
                title: '¿Eliminar logo?',
                text: "Esta acción eliminará el logo actual del gimnasio",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar',
                target: document.body
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'includes/eliminar_logo.php',
                        type: 'POST',
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({ icon: 'success', title: 'Eliminado', text: response.message, target: document.body, showConfirmButton: false, timer: 1500 })
                                    .then(() => { location.reload(); });
                            } else {
                                Swal.fire({ icon: 'error', title: 'Error', text: response.message, target: document.body });
                            }
                        },
                        error: function() {
                            Swal.fire({ icon: 'error', title: 'Error', text: 'Ocurrió un error al eliminar el logo', target: document.body });
                        }
                    });
                }
            });
        }
    </script>
</body>
</html>