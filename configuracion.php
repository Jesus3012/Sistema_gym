<?php
session_start();
require_once 'config/database.php';

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

// Obtener configuración del gimnasio
$config_result = $conn->query("SELECT * FROM configuracion_gimnasio WHERE id = 1");
$config_gimnasio = $config_result->fetch_assoc();

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
    
    // Gestionar Clientes
    if ($action === 'save_cliente') {
        $id = $_POST['id'] ?? null;
        $nombre = $conn->real_escape_string($_POST['nombre']);
        $apellido = $conn->real_escape_string($_POST['apellido']);
        $telefono = $conn->real_escape_string($_POST['telefono']);
        $email = $conn->real_escape_string($_POST['email']);
        $estado = $_POST['estado'];
        $huella_digital = $_POST['huella_digital'] ?? null;
        
        if ($id) {
            $query = "UPDATE clientes SET nombre='$nombre', apellido='$apellido', telefono='$telefono', email='$email', estado='$estado'";
            if ($huella_digital) {
                $query .= ", huella_digital='$huella_digital'";
            }
            $query .= " WHERE id=$id";
            $conn->query($query);
        } else {
            $conn->query("INSERT INTO clientes (nombre, apellido, telefono, email, estado) VALUES ('$nombre', '$apellido', '$telefono', '$email', '$estado')");
        }
        echo json_encode(['success' => true]);
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
    
    if ($action === 'update_huella') {
        $id = intval($_POST['id']);
        $huella = $conn->real_escape_string($_POST['huella']);
        $conn->query("UPDATE clientes SET huella_digital='$huella' WHERE id=$id");
        echo json_encode(['success' => true]);
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
        $id = $_POST['id'] ?? null;
        $nombre = $conn->real_escape_string($_POST['nombre']);
        $email = $conn->real_escape_string($_POST['email']);
        $rol = $_POST['rol'];
        $estado = $_POST['estado'];
        
        if ($id) {
            $query = "UPDATE usuarios SET nombre='$nombre', email='$email', rol='$rol', estado='$estado'";
            $query .= " WHERE id=$id";
            $conn->query($query);
        } else {
            $password_default = 'ego1';
            $password = password_hash($password_default, PASSWORD_DEFAULT);
            $conn->query("INSERT INTO usuarios (nombre, email, password, rol, estado, password_change_required) VALUES ('$nombre', '$email', '$password', '$rol', '$estado', 1)");
        }
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'delete_usuario') {
        $id = intval($_POST['id']);
        if ($id != 1) {
            $conn->query("DELETE FROM usuarios WHERE id=$id");
        }
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'cambiar_password') {
        $id = intval($_POST['id']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $conn->query("UPDATE usuarios SET password='$password', password_change_required=1, ultimo_cambio_password=NOW() WHERE id=$id");
        echo json_encode(['success' => true]);
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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f5f7fa;
            font-family: 'Source Sans Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }

        .main-content {
            margin-left: 280px;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            min-height: 100vh;
            padding: 20px;
            background: #f4f6f9;
        }

        body.sidebar-collapsed .main-content {
            margin-left: 70px;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0 !important;
                padding: 80px 15px 15px 15px;
            }
        }

        .content-header {
            padding: 15px 0;
        }

        .content-header h1 {
            font-size: 1.8rem;
            font-weight: 600;
            color: #1e293b;
        }

        .config-nav {
            background: white;
            border-radius: 8px;
            margin-bottom: 25px;
            overflow-x: auto;
            white-space: nowrap;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .config-nav ul {
            display: flex;
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .config-nav li {
            display: inline-block;
        }

        .config-nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px 20px;
            color: #64748b;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s;
            border-bottom: 3px solid transparent;
        }

        .config-nav a:hover {
            color: #1e3a8a;
            background: #f8fafc;
        }

        .config-nav a.active {
            color: #1e3a8a;
            border-bottom-color: #1e3a8a;
        }

        .config-nav a i {
            font-size: 1.1rem;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1050;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h4 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6c757d;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .text-right {
            text-align: right;
        }

        .text-muted {
            color: #6c757d;
        }

        .huella-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            background: #e8f0fe;
            color: #1e3a8a;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .swal2-container {
            z-index: 10000 !important;
        }

        .swal2-popup {
            z-index: 10001 !important;
        }

        .modal-backdrop {
            z-index: 1040 !important;
        }

        /* Estilos mejorados para alertas ocultables - diseño elegante */
        .alert-ocultable {
            position: relative;
            transition: all 0.3s ease;
            padding-right: 50px !important;
            background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
            border: none;
            border-left: 5px solid #0284c7;
            color: #0c4a6e;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .alert-ocultable .btn-ocultar {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            background: rgba(2, 132, 199, 0.2);
            border: none;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            cursor: pointer;
            font-size: 12px;
            color: #0284c7;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        .alert-ocultable .btn-ocultar:hover {
            background: rgba(2, 132, 199, 0.4);
            transform: translateY(-50%) scale(1.05);
            color: #0c4a6e;
        }

        .alert-ocultable .btn-ocultar i {
            font-size: 12px;
        }

        .alert-ocultable.oculto {
            display: none;
        }

        /* Contenedor para el botón mostrar alerta - centrado */
        .alert-boton-container {
            margin-top: 15px;
            margin-bottom: 10px;
            text-align: center;
        }

        /* Botones para mostrar alertas ocultas - azul suave con borde fuerte */
        .btn-mostrar-alerta {
            background: #eff6ff;  /* Azul muy suave de fondo */
            border: 2px solid #3b82f6;  /* Borde azul fuerte */
            border-radius: 50px;
            padding: 10px 28px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            color: #2563eb;  /* Texto azul */
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.15);
        }

        .btn-mostrar-alerta:hover {
            background: #dbeafe;  /* Azul un poco más intenso al hover */
            border-color: #2563eb;  /* Borde más fuerte */
            transform: translateY(-2px);
            color: #1e40af;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25);
        }

        .btn-mostrar-alerta:active {
            transform: translateY(0px);
        }

        .btn-mostrar-alerta i {
            font-size: 14px;
        }

        /* Estilos para acciones de clientes en una línea */
        .acciones-cliente {
            white-space: nowrap;
        }
        
        .acciones-cliente .btn {
            margin: 0 2px;
            display: inline-block;
        }
        
        .table td.acciones-cliente {
            vertical-align: middle;
        }

        /* Ocultar columna ID en todas las tablas */
        .table th:first-child,
        .table td:first-child {
            display: none;
        }

        /* Estilos para el input de archivo */
        .custom-file {
            position: relative;
            display: inline-block;
            width: 100%;
            height: calc(1.5em + 0.75rem + 2px);
            margin-bottom: 0;
        }

        .custom-file-input {
            position: relative;
            z-index: 2;
            width: 100%;
            height: calc(1.5em + 0.75rem + 2px);
            margin: 0;
            opacity: 0;
        }

        .custom-file-label {
            position: absolute;
            top: 0;
            right: 0;
            left: 0;
            z-index: 1;
            height: calc(1.5em + 0.75rem + 2px);
            padding: 0.375rem 0.75rem;
            font-weight: 400;
            line-height: 1.5;
            color: #495057;
            background-color: #fff;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .custom-file-label::after {
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            z-index: 3;
            display: block;
            height: calc(1.5em + 0.75rem);
            padding: 0.375rem 0.75rem;
            line-height: 1.5;
            color: #495057;
            content: "Examinar";
            background-color: #e9ecef;
            border-left: inherit;
            border-radius: 0 0.25rem 0.25rem 0;
        }
    </style>
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
                <li><a href="?section=clientes" class="<?php echo $seccion == 'clientes' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Clientes</a></li>
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
                                                    <button class="btn-ocultar" onclick="event.preventDefault(); event.stopPropagation(); ocultarAlerta('logo_info')" title="Ocultar recomendaciones">
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
                                <input type="text" class="form-control" name="nombre_gimnasio" value="<?php echo htmlspecialchars($config_gimnasio['nombre'] ?? 'Gym System'); ?>">
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
                            <button class="btn-ocultar" onclick="event.preventDefault(); event.stopPropagation(); ocultarAlerta('info_gimnasio')" title="Ocultar alerta">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion == 'clientes'): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-users"></i> Gestión de Clientes</h3>
                <div class="card-tools">
                    <button class="btn btn-primary btn-sm" onclick="abrirModal('modalCliente')">
                        <i class="fas fa-plus"></i> Nuevo Cliente
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
                                <th>Huella Digital</th>
                                <th>Estado</th>
                                <th>Fecha Registro</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $clientes = $conn->query("SELECT * FROM clientes ORDER BY id DESC");
                            while($cliente = $clientes->fetch_assoc()): 
                            ?>
                            <tr>
                                <td style="display: none;"><?php echo $cliente['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apellido']); ?></strong></td>
                                <td><?php echo htmlspecialchars($cliente['telefono'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($cliente['email'] ?? '-'); ?></td>
                                <td><?php if($cliente['huella_digital']): ?><span class="huella-badge"><i class="fas fa-fingerprint"></i> Registrada</span><?php else: ?><span class="badge badge-secondary"><i class="fas fa-fingerprint"></i> No registrada</span><?php endif; ?></td>
                                <td><span class="badge <?php echo $cliente['estado'] == 'activo' ? 'badge-success' : 'badge-danger'; ?>"><?php echo $cliente['estado'] == 'activo' ? 'Activo' : 'Inactivo'; ?></span></td>
                                <td><?php echo date('d/m/Y', strtotime($cliente['fecha_registro'])); ?></td>
                                <td class="acciones-cliente">
                                    <button class="btn btn-warning btn-sm" onclick="editarCliente(<?php echo $cliente['id']; ?>)" title="Editar cliente"><i class="fas fa-edit"></i> Editar</button>
                                    <button class="btn btn-info btn-sm" onclick="editarHuella(<?php echo $cliente['id']; ?>, '<?php echo htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apellido']); ?>')" title="Registrar huella digital"><i class="fas fa-fingerprint"></i> Huella</button>
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

        <div id="modalHuella" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h4><i class="fas fa-fingerprint"></i> Registrar Huella Digital</h4>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="huellaClienteId">
                    <p>Cliente: <strong id="huellaClienteNombre"></strong></p>
                    <div class="alert alert-info alert-ocultable" data-alerta-id="huella_info">
                        <i class="fas fa-info-circle"></i> Para registrar la huella digital, conecte el lector biométrico y coloque el dedo del cliente.
                        <button class="btn-ocultar" onclick="event.preventDefault(); event.stopPropagation(); ocultarAlerta('huella_info')" title="Ocultar alerta">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="form-group">
                        <label>ID de Huella (simulación)</label>
                        <input type="text" class="form-control" id="huellaValor" placeholder="Ej: FP_123456789_abcde">
                        <small class="text-muted">En un entorno real, esto se capturaría automáticamente desde el lector biométrico</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalHuella')">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="guardarHuella()">Guardar Huella</button>
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
                                <td><span class="badge <?php echo $user['estado'] == 'activo' ? 'badge-success' : 'badge-danger'; ?>"><?php echo $user['estado'] == 'activo' ? 'Activo' : 'Inactivo'; ?></span></td>
                                <td><?php echo date('d/m/Y', strtotime($user['fecha_registro'])); ?></td>
                                <td class="text-nowrap">
                                    <button class="btn btn-warning btn-sm" onclick="editarUsuario(<?php echo $user['id']; ?>)" title="Editar información del usuario"><i class="fas fa-edit"></i> Editar</button>
                                    <?php if($user['id'] != 1): ?>
                                    <button class="btn btn-danger btn-sm" onclick="eliminarRegistro('usuarios', <?php echo $user['id']; ?>, 'usuario')" title="Eliminar usuario del sistema"><i class="fas fa-trash"></i> Eliminar</button>
                                    <?php endif; ?>
                                    <button class="btn btn-secondary btn-sm" onclick="restablecerPassword(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['nombre']); ?>')" title="Restablecer contraseña a valor predeterminado (ego1)"><i class="fas fa-key"></i> Restablecer</button>
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
                            <i class="fas fa-info-circle"></i> <strong>Información:</strong> Los nuevos usuarios se crearán con la contraseña predeterminada <strong>ego1</strong>. El usuario deberá cambiarla en su primer inicio de sesión.
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
                        <p class="mt-2 mb-0">Va a restablecer la contraseña del usuario <strong id="resetNombreMostrar"></strong> a su valor predeterminado: <strong>ego1</strong>.</p>
                        <p class="mt-2 mb-0 text-muted small">El usuario deberá cambiar su contraseña en el próximo inicio de sesión.</p>
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

    
    <script>
        // Funciones de Modal
        function abrirModal(modalId) {
            $('#' + modalId).addClass('active');
        }

        function cerrarModal(modalId) {
            $('#' + modalId).removeClass('active');
            $('#' + modalId + ' form')[0]?.reset();
            $('#' + modalId + ' input[name="id"]').val('');
        }

        $(document).ready(function() {
            $('.modal-close, .modal').click(function(e) {
                if ($(e.target).hasClass('modal') || $(e.target).hasClass('modal-close')) {
                    $(this).removeClass('active');
                }
            });
            cargarEstadoAlertas();
        });

        // Funciones genéricas para editar y eliminar
        function editarRegistro(tabla, id, modalId) {
            $.ajax({
                url: 'configuracion.php',
                method: 'POST',
                data: { action: 'get_registro', tabla: tabla, id: id },
                dataType: 'json',
                success: function(data) {
                    let form = $('#' + modalId + ' form');
                    form.find('input[name="id"]').val(data.id);
                    for(let key in data) {
                        let input = form.find('[name="' + key + '"]');
                        if(input.length) input.val(data[key]);
                    }
                    $('#' + modalId + ' .modal-header h4').html('<i class="fas fa-edit"></i> Editar');
                    abrirModal(modalId);
                },
                error: function() {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'No se pudo cargar el registro', target: document.body });
                }
            });
        }

        function eliminarRegistro(tabla, id, tipo) {
            Swal.fire({
                title: '¿Eliminar registro?',
                text: 'Esta acción no se puede deshacer',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar',
                target: document.body
            }).then((result) => {
                if (result.isConfirmed) {
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
                        data: { action: action, id: id },
                        success: function(response) {
                            Swal.fire({ icon: 'success', title: 'Eliminado', text: 'Registro eliminado correctamente', target: document.body, timer: 1500, showConfirmButton: false });
                            setTimeout(() => location.reload(), 1500);
                        },
                        error: function() {
                            Swal.fire({ icon: 'error', title: 'Error', text: 'No se pudo eliminar el registro', target: document.body });
                        }
                    });
                }
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

        function editarHuella(id, nombre) {
            $('#huellaClienteId').val(id);
            $('#huellaClienteNombre').text(nombre);
            $('#huellaValor').val('');
            $('#modalHuella .modal-header h4').html('<i class="fas fa-fingerprint"></i> Registrar Huella Digital - ' + nombre);
            abrirModal('modalHuella');
        }

        function guardarHuella() {
            let id = $('#huellaClienteId').val();
            let huella = $('#huellaValor').val();
            
            if (!huella) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Debe ingresar un identificador de huella', target: document.body });
                return;
            }
            
            $.ajax({
                url: 'configuracion.php',
                method: 'POST',
                data: { action: 'update_huella', id: id, huella: huella },
                success: function() {
                    Swal.fire({ icon: 'success', title: 'Éxito', text: 'Huella digital registrada correctamente', target: document.body, timer: 1500, showConfirmButton: false });
                    cerrarModal('modalHuella');
                    setTimeout(() => location.reload(), 1500);
                }
            });
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
                text: `¿Está seguro de restablecer la contraseña de ${nombre} a "ego1"?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí, restablecer',
                cancelButtonText: 'Cancelar',
                target: document.body
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'configuracion.php',
                        method: 'POST',
                        data: { action: 'cambiar_password', id: id, password: 'ego1' },
                        success: function(response) {
                            Swal.fire({ icon: 'success', title: 'Contraseña restablecida', html: `La contraseña del usuario <strong>${nombre}</strong> ha sido restablecida a <strong>ego1</strong>.<br>El usuario deberá cambiarla en su próximo inicio de sesión.`, target: document.body, timer: 3000, showConfirmButton: true });
                            cerrarModal('modalRestablecer');
                        },
                        error: function() {
                            Swal.fire({ icon: 'error', title: 'Error', text: 'No se pudo restablecer la contraseña', target: document.body });
                        }
                    });
                }
            });
        }

        // ==================== FUNCIONES PARA ALERTAS OCULTABLES (Mejoradas) ====================
        function ocultarAlerta(alertaId) {
            // Guardar estado en localStorage
            localStorage.setItem('alerta_oculta_' + alertaId, 'true');
            let $alerta = $('[data-alerta-id="' + alertaId + '"]');
            $alerta.addClass('oculto');
            
            // Determinar el texto del botón según el ID de la alerta
            let textoBoton = '';
            let iconoBoton = '';
            switch(alertaId) {
                case 'info_gimnasio':
                    textoBoton = 'Ver consejo';
                    iconoBoton = 'fa-lightbulb';
                    break;
                case 'huella_info':
                    textoBoton = 'Ver instrucciones';
                    iconoBoton = 'fa-fingerprint';
                    break;
                case 'usuario_info':
                    textoBoton = 'Más información';
                    iconoBoton = 'fa-info-circle';
                    break;
                case 'logo_info':
                    textoBoton = 'Mostrar recomendaciones';
                    iconoBoton = 'fa-image';
                    break;
                default:
                    textoBoton = 'Mostrar alerta';
                    iconoBoton = 'fa-eye';
            }
            
            // Crear contenedor para el botón si no existe
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
                        
                        // Determinar el texto del botón según el ID de la alerta
                        let textoBoton = '';
                        let iconoBoton = '';
                        switch(alertaId) {
                            case 'info_gimnasio':
                                textoBoton = 'Ver consejo';
                                iconoBoton = 'fa-lightbulb';
                                break;
                            case 'huella_info':
                                textoBoton = 'Ver instrucciones';
                                iconoBoton = 'fa-fingerprint';
                                break;
                            case 'usuario_info':
                                textoBoton = 'Más información';
                                iconoBoton = 'fa-info-circle';
                                break;
                            case 'logo_info':
                                textoBoton = 'Mostrar recomendaciones';
                                iconoBoton = 'fa-image';
                                break;
                            default:
                                textoBoton = 'Mostrar alerta';
                                iconoBoton = 'fa-eye';
                        }
                        
                        // Crear contenedor para el botón si no existe
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

        // Envío de formularios
        $('#formPlan, #formCategoria, #formProveedor, #formProducto, #formClase, #formUsuario, #formCliente, #formInfoGimnasio').on('submit', function(e) {
            e.preventDefault();
            let action = '';
            if ($(this).attr('id') === 'formPlan') action = 'save_plan';
            else if ($(this).attr('id') === 'formCategoria') action = 'save_categoria';
            else if ($(this).attr('id') === 'formProveedor') action = 'save_proveedor';
            else if ($(this).attr('id') === 'formProducto') action = 'save_producto';
            else if ($(this).attr('id') === 'formClase') action = 'save_clase';
            else if ($(this).attr('id') === 'formUsuario') action = 'save_usuario';
            else if ($(this).attr('id') === 'formCliente') action = 'save_cliente';
            else if ($(this).attr('id') === 'formInfoGimnasio') action = 'save_config';
            
            let data = $(this).serialize() + '&action=' + action;
            $.ajax({
                url: 'configuracion.php',
                method: 'POST',
                data: data,
                success: function(response) {
                    let res;
                    try { res = typeof response === 'string' ? JSON.parse(response) : response; } catch(e) { res = { success: true }; }
                    if (res.success) {
                        Swal.fire({ icon: 'success', title: 'Guardado', text: 'Registro guardado correctamente', target: document.body, timer: 1500, showConfirmButton: false });
                        setTimeout(() => location.reload(), 1500);
                    } else if (res.error) {
                        Swal.fire({ icon: 'error', title: 'Error', text: res.error, target: document.body });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Ocurrió un error: ' + error, target: document.body });
                }
            });
        });

        // Agrega esta función para eliminar el logo
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
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Eliminado',
                                    text: response.message,
                                    target: document.body,
                                    showConfirmButton: false,
                                    timer: 1500
                                }).then(() => {
                                    location.reload();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: response.message,
                                    target: document.body
                                });
                            }
                        },
                        error: function() {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Ocurrió un error al eliminar el logo',
                                target: document.body
                            });
                        }
                    });
                }
            });
        }

        // Modifica el envío del formulario de información del gimnasio
        $(document).ready(function() {
            // Vista previa del logo
            $('#logo').on('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    // Validar tipo de archivo
                    const tiposPermitidos = ['image/jpeg', 'image/jpg', 'image/png'];
                    if (!tiposPermitidos.includes(file.type)) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Solo se permiten archivos JPG, JPEG y PNG',
                            target: document.body
                        });
                        $(this).val('');
                        return;
                    }
                    
                    // Validar tamaño (máximo 2MB)
                    if (file.size > 2 * 1024 * 1024) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'El archivo no puede superar los 2MB',
                            target: document.body
                        });
                        $(this).val('');
                        return;
                    }
                    
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        $('#preview_logo').attr('src', e.target.result);
                    }
                    reader.readAsDataURL(file);
                    
                    // Mostrar nombre del archivo
                    $(this).next('.custom-file-label').html(file.name);
                } else {
                    $(this).next('.custom-file-label').html('Seleccionar logo (PNG, JPG, JPEG)');
                }
            });
            
            // Los demás eventos de formularios...
            $('#formPlan, #formCategoria, #formProveedor, #formProducto, #formClase, #formUsuario, #formCliente').on('submit', function(e) {
                e.preventDefault();
                let action = '';
                if ($(this).attr('id') === 'formPlan') action = 'save_plan';
                else if ($(this).attr('id') === 'formCategoria') action = 'save_categoria';
                else if ($(this).attr('id') === 'formProveedor') action = 'save_proveedor';
                else if ($(this).attr('id') === 'formProducto') action = 'save_producto';
                else if ($(this).attr('id') === 'formClase') action = 'save_clase';
                else if ($(this).attr('id') === 'formUsuario') action = 'save_usuario';
                else if ($(this).attr('id') === 'formCliente') action = 'save_cliente';
                
                let data = $(this).serialize() + '&action=' + action;
                $.ajax({
                    url: 'configuracion.php',
                    method: 'POST',
                    data: data,
                    success: function(response) {
                        let res;
                        try { res = typeof response === 'string' ? JSON.parse(response) : response; } catch(e) { res = { success: true }; }
                        if (res.success) {
                            Swal.fire({ icon: 'success', title: 'Guardado', text: 'Registro guardado correctamente', target: document.body, timer: 1500, showConfirmButton: false });
                            setTimeout(() => location.reload(), 1500);
                        } else if (res.error) {
                            Swal.fire({ icon: 'error', title: 'Error', text: res.error, target: document.body });
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Ocurrió un error: ' + error, target: document.body });
                    }
                });
            });
            
            // Formulario específico de configuración del gimnasio (con logo)
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
                            Swal.fire({
                                icon: 'success',
                                title: '¡Éxito!',
                                text: response.message,
                                target: document.body,
                                showConfirmButton: false,
                                timer: 2000
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message,
                                target: document.body
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Ocurrió un error al guardar la configuración: ' + error,
                            target: document.body
                        });
                    }
                });
            });
        });
    </script>
</body>
</html>