<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Incluir la conexión a la base de datos y funciones de stock
require_once 'config/database.php';
require_once 'includes/stock_functions.php';

// Crear instancia de la base de datos y obtener conexión
$database = new Database();
$conn = $database->getConnection();

// Verificar conexión
if (!$conn) {
    die("Error: No se pudo establecer la conexión a la base de datos");
}

// Obtener categorías y proveedores
$categorias = [];
$result = $conn->query("SELECT * FROM categorias_productos WHERE estado = 'activo' ORDER BY nombre");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $categorias[] = $row;
    }
}

$proveedores = [];
$result = $conn->query("SELECT * FROM proveedores WHERE estado = 'activo' ORDER BY nombre");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $proveedores[] = $row;
    }
}

// Variables para mensajes
$error = '';
$success = '';

// Procesar acciones POST del formulario principal
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // Registrar nuevo producto
    if ($action == 'create') {
        $nombre = trim($_POST['nombre']);
        $categoria_id = $_POST['categoria_id'];
        $proveedor_id = $_POST['proveedor_id'] ?: null;
        $precio_compra = floatval($_POST['precio_compra']);
        $precio_venta = floatval($_POST['precio_venta']);
        $stock = intval($_POST['stock']);
        $stock_minimo = intval($_POST['stock_minimo']);
        $descripcion = isset($_POST['descripcion']) ? trim($_POST['descripcion']) : '';
        
        if (empty($nombre)) {
            $error = "El nombre del producto es obligatorio";
        } elseif ($categoria_id == 0) {
            $error = "Debe seleccionar una categoría";
        } elseif ($precio_compra <= 0) {
            $error = "El precio de compra debe ser mayor a 0";
        } elseif ($precio_venta <= 0) {
            $error = "El precio de venta debe ser mayor a 0";
        } else {
            // Procesar imagen
            $foto_ruta = null;
            if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
                $max_size = 2 * 1024 * 1024;
                
                if (in_array($_FILES['foto']['type'], $allowed_types) && $_FILES['foto']['size'] <= $max_size) {
                    $upload_dir = 'uploads/productos/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $extension = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
                    $nombre_archivo = time() . '_' . uniqid() . '.' . $extension;
                    $foto_ruta = $upload_dir . $nombre_archivo;
                    move_uploaded_file($_FILES['foto']['tmp_name'], $foto_ruta);
                }
            }
            
            $sql = "INSERT INTO productos (nombre, descripcion, categoria_id, proveedor_id, precio_compra, precio_venta, stock, stock_minimo, foto, estado) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo')";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssiiddiis", $nombre, $descripcion, $categoria_id, $proveedor_id, $precio_compra, $precio_venta, $stock, $stock_minimo, $foto_ruta);
            
            if ($stmt->execute()) {
                $producto_id = $stmt->insert_id;
                
                // Registrar movimiento inicial de stock (cantidad = stock inicial)
                $resultado_movimiento = registrarMovimientoStock(
                    $conn,
                    $producto_id,
                    'inicial',
                    $stock,  // Cantidad inicial
                    'Stock inicial al crear producto',
                    $_SESSION['user_id'],
                    null,
                    null,
                    'Producto registrado en el sistema con stock inicial: ' . $stock . ' unidades'
                );
                
                if (!$resultado_movimiento['success']) {
                    error_log("Error al registrar movimiento inicial: " . $resultado_movimiento['error']);
                    $success = "Producto registrado exitosamente, pero hubo un error al registrar el movimiento de stock inicial.";
                } else {
                    $success = "Producto registrado exitosamente con stock inicial de " . $stock . " unidades";
                }
                
                $_POST = array();
            } else {
                $error = "Error al registrar producto: " . $conn->error;
            }
            $stmt->close();
        }
    }
    
    // Agregar stock
    elseif ($action == 'add_stock') {
        $id = intval($_POST['producto_id']);
        $cantidad = intval($_POST['cantidad']);
        $motivo = trim($_POST['motivo']) ?: 'Entrada de stock';
        $observaciones = trim($_POST['observaciones']) ?: 'Agregado manualmente desde panel de productos';
        
        if ($cantidad > 0) {
            // Primero obtener stock actual
            $stmt = $conn->prepare("SELECT stock FROM productos WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $producto = $result->fetch_assoc();
            $stock_anterior = $producto['stock'];
            $stock_nuevo = $stock_anterior + $cantidad;
            $stmt->close();
            
            // Actualizar stock
            $sql = "UPDATE productos SET stock = stock + ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $cantidad, $id);
            
            if ($stmt->execute()) {
                // Registrar movimiento
                $resultado = registrarMovimientoStock(
                    $conn,
                    $id,
                    'entrada',
                    $cantidad,  // Cantidad agregada
                    $motivo,
                    $_SESSION['user_id'],
                    null,
                    null,
                    $observaciones . ' | Stock anterior: ' . $stock_anterior . ', nuevo: ' . $stock_nuevo
                );
                
                if ($resultado['success']) {
                    $success = "Stock agregado exitosamente. Se añadieron $cantidad unidades. Nuevo stock: $stock_nuevo";
                } else {
                    $error = "Stock actualizado pero error al registrar movimiento: " . $resultado['error'];
                }
            } else {
                $error = "Error al agregar stock: " . $conn->error;
            }
            $stmt->close();
        } else {
            $error = "La cantidad debe ser mayor a 0";
        }
    }
    
    // Ajuste de stock (AJAX)
    elseif ($action == 'ajuste_stock') {
        $id = intval($_POST['producto_id']);
        $tipo_ajuste = $_POST['tipo_ajuste'];
        $motivo = $_POST['motivo_ajuste'] ?? 'Ajuste manual';
        $observaciones = $_POST['observaciones'] ?? '';
        
        if ($tipo_ajuste == 'stock_correccion') {
            $nuevo_stock = intval($_POST['stock_fisico']); // Este es el NUEVO STOCK
            
            // Obtener stock actual
            $stmt = $conn->prepare("SELECT stock FROM productos WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $producto = $result->fetch_assoc();
            $stock_anterior = $producto['stock'];
            $diferencia = $nuevo_stock - $stock_anterior;
            $stmt->close();
            
            // Actualizar stock con el NUEVO valor
            $sql = "UPDATE productos SET stock = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $nuevo_stock, $id);
            
            if ($stmt->execute()) {
                // Registrar movimiento de corrección - PASAMOS EL NUEVO STOCK
                $resultado = registrarMovimientoStock(
                    $conn,
                    $id,
                    'correccion',
                    $nuevo_stock,  // Pasamos el NUEVO STOCK, no la diferencia
                    $motivo,
                    $_SESSION['user_id'],
                    null,
                    null,
                    $observaciones . ' | Corrección de inventario: Stock anterior ' . $stock_anterior . ', nuevo ' . $nuevo_stock . ' | Variación: ' . ($diferencia > 0 ? '+' : '') . $diferencia
                );
                
                if ($resultado['success']) {
                    echo json_encode([
                        'success' => true, 
                        'message' => "Stock corregido de $stock_anterior a $nuevo_stock unidades",
                        'stock_anterior' => $stock_anterior,
                        'stock_nuevo' => $nuevo_stock,
                        'diferencia' => $diferencia
                    ]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Error al registrar movimiento: ' . $resultado['error']]);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Error al corregir stock: ' . $conn->error]);
            }
            $stmt->close();
        } 
        elseif ($tipo_ajuste == 'stock_minimo') {
            $nuevo_stock_minimo = intval($_POST['nuevo_stock_minimo']);
            
            // Obtener stock mínimo actual
            $stmt = $conn->prepare("SELECT stock_minimo FROM productos WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $producto = $result->fetch_assoc();
            $stock_minimo_anterior = $producto['stock_minimo'];
            $diferencia_minimo = $nuevo_stock_minimo - $stock_minimo_anterior;
            $stmt->close();
            
            // Actualizar stock mínimo
            $sql = "UPDATE productos SET stock_minimo = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $nuevo_stock_minimo, $id);
            
            if ($stmt->execute()) {
                // Registrar movimiento de ajuste de mínimo - PASAMOS LA DIFERENCIA
                $resultado = registrarMovimientoStock(
                    $conn,
                    $id,
                    'ajuste_minimo',
                    $diferencia_minimo,  // Pasamos la diferencia
                    $motivo,
                    $_SESSION['user_id'],
                    null,
                    null,
                    $observaciones . ' | Cambio de stock mínimo: de ' . $stock_minimo_anterior . ' a ' . $nuevo_stock_minimo
                );
                
                if ($resultado['success']) {
                    echo json_encode([
                        'success' => true, 
                        'message' => "Stock mínimo actualizado de $stock_minimo_anterior a $nuevo_stock_minimo unidades"
                    ]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Error al registrar movimiento: ' . $resultado['error']]);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Error al actualizar stock mínimo: ' . $conn->error]);
            }
            $stmt->close();
        }
        exit();
    }
    
    // Cambiar estado
    elseif ($action == 'toggle_status') {
        $id = intval($_POST['producto_id']);
        $nuevo_estado = $_POST['nuevo_estado'];
        
        $sql = "UPDATE productos SET estado = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $nuevo_estado, $id);
        
        if ($stmt->execute()) {
            $mensaje = $nuevo_estado == 'activo' ? 'activado' : 'desactivado';
            $success = "Producto $mensaje exitosamente";
        } else {
            $error = "Error al cambiar estado: " . $conn->error;
        }
        $stmt->close();
    }
    
    // Agregar nueva categoría (AJAX)
    elseif ($action == 'add_categoria') {
        $nombre = trim($_POST['nombre_categoria']);
        $descripcion = trim($_POST['descripcion_categoria']);
        
        if (!empty($nombre)) {
            $sql = "INSERT INTO categorias_productos (nombre, descripcion, estado) VALUES (?, ?, 'activo')";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $nombre, $descripcion);
            
            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                echo json_encode(['success' => true, 'id' => $new_id, 'nombre' => $nombre]);
                exit();
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
                exit();
            }
        }
    }
    
    // Agregar nuevo proveedor (AJAX)
    elseif ($action == 'add_proveedor') {
        $nombre = trim($_POST['nombre_proveedor']);
        $contacto = trim($_POST['contacto_proveedor']);
        $telefono = trim($_POST['telefono_proveedor']);
        $email = trim($_POST['email_proveedor']);
        
        if (!empty($nombre)) {
            $sql = "INSERT INTO proveedores (nombre, contacto, telefono, email, estado) VALUES (?, ?, ?, ?, 'activo')";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssss", $nombre, $contacto, $telefono, $email);
            
            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                echo json_encode(['success' => true, 'id' => $new_id, 'nombre' => $nombre]);
                exit();
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
                exit();
            }
        }
    }
    
    // Actualizar producto (AJAX)
    elseif ($action == 'update') {
        $id = intval($_POST['producto_id']);
        $nombre = trim($_POST['nombre']);
        $categoria_id = $_POST['categoria_id'];
        $proveedor_id = $_POST['proveedor_id'] ?: null;
        $precio_compra = floatval($_POST['precio_compra']);
        $precio_venta = floatval($_POST['precio_venta']);
        $stock = intval($_POST['stock']);
        $stock_minimo = intval($_POST['stock_minimo']);
        $descripcion = trim($_POST['descripcion']);
        
        if (empty($nombre)) {
            echo json_encode(['success' => false, 'error' => 'El nombre del producto es obligatorio']);
            exit();
        }
        
        // Procesar imagen
        $foto_ruta = null;
        if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
            $max_size = 2 * 1024 * 1024;
            
            if (in_array($_FILES['foto']['type'], $allowed_types) && $_FILES['foto']['size'] <= $max_size) {
                $upload_dir = 'uploads/productos/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $extension = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
                $nombre_archivo = time() . '_' . uniqid() . '.' . $extension;
                $foto_ruta = $upload_dir . $nombre_archivo;
                move_uploaded_file($_FILES['foto']['tmp_name'], $foto_ruta);
            }
        }
        
        if ($foto_ruta) {
            $sql = "UPDATE productos SET nombre=?, descripcion=?, categoria_id=?, proveedor_id=?, precio_compra=?, precio_venta=?, stock=?, stock_minimo=?, foto=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssiiddiisi", $nombre, $descripcion, $categoria_id, $proveedor_id, $precio_compra, $precio_venta, $stock, $stock_minimo, $foto_ruta, $id);
        } else {
            $sql = "UPDATE productos SET nombre=?, descripcion=?, categoria_id=?, proveedor_id=?, precio_compra=?, precio_venta=?, stock=?, stock_minimo=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssiiddiii", $nombre, $descripcion, $categoria_id, $proveedor_id, $precio_compra, $precio_venta, $stock, $stock_minimo, $id);
        }
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Producto actualizado exitosamente']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Error al actualizar producto: ' . $conn->error]);
        }
        $stmt->close();
        exit();
    }
}

// Configuración de paginación y búsqueda
$registros_por_pagina = isset($_GET['limite']) ? (int)$_GET['limite'] : 10;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;
$busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
$categoria_filtro = isset($_GET['categoria']) ? (int)$_GET['categoria'] : 0;
$estado_filtro = isset($_GET['estado']) ? $_GET['estado'] : 'todos';

// Construir consulta de productos
$where = [];
$params = [];
$types = "";

if (!empty($busqueda)) {
    $where[] = "(p.nombre LIKE ? OR p.descripcion LIKE ?)";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
    $types .= "ss";
}

if ($categoria_filtro > 0) {
    $where[] = "p.categoria_id = ?";
    $params[] = $categoria_filtro;
    $types .= "i";
}

if ($estado_filtro !== 'todos') {
    $where[] = "p.estado = ?";
    $params[] = $estado_filtro;
    $types .= "s";
}

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Contar total
$count_sql = "SELECT COUNT(*) as total FROM productos p $where_sql";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_registros = $count_result->fetch_assoc()['total'];
$total_paginas = ceil($total_registros / $registros_por_pagina);
$count_stmt->close();

// Obtener productos
$sql = "SELECT p.*, c.nombre as categoria_nombre, prov.nombre as proveedor_nombre 
        FROM productos p 
        LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
        LEFT JOIN proveedores prov ON p.proveedor_id = prov.id 
        $where_sql 
        ORDER BY p.fecha_registro DESC 
        LIMIT ? OFFSET ?";

$params[] = $registros_por_pagina;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$productos = [];
while ($row = $result->fetch_assoc()) {
    $productos[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Productos - Gym System</title>
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Bootstrap 4 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/css/bootstrap.min.css">
    <!-- AdminLTE CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* Tus estilos existentes... */
        .main-content {
            margin-left: 280px;
            padding: 20px 30px;
            transition: margin-left 0.3s ease;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
        }
        
        .card {
            box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2);
            margin-bottom: 1rem;
        }
        
        .card-header {
            background-color: transparent;
            border-bottom: 1px solid rgba(0,0,0,.125);
            padding: 0.75rem 1.25rem;
        }
        
        .card-title {
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .select-with-add {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .select-with-add select {
            flex: 1;
        }
        
        .btn-add {
            padding: 6px 12px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 0.25rem;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-add:hover {
            background: #218838;
            transform: translateY(-1px);
        }
        
        .producto-imagen {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 0.25rem;
        }
        
        .preview-image {
            max-width: 150px;
            max-height: 150px;
            border-radius: 0.25rem;
            margin-top: 10px;
        }
        
        .filter-card {
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .action-buttons {
                flex-direction: column;
            }
            
            .action-btn {
                width: 100%;
                text-align: center;
            }
            
            .select-with-add {
                flex-direction: column;
            }
            
            .btn-add {
                width: 100%;
            }
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            margin-bottom: 0;
        }
        
        .alert {
            animation: fadeInDown 0.5s ease;
        }
        
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .page-title h2 {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .text-success {
            color: #28a745 !important;
        }
        
        .text-danger {
            color: #dc3545 !important;
        }
        
        .text-warning {
            color: #ffc107 !important;
        }
        
        .text-info {
            color: #17a2b8 !important;
        }
        
        .text-muted {
            color: #6c757d !important;
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="container-fluid p-0">
                <!-- Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title">
                            <h2>Gestión de Productos</h2>
                        </div>
                    </div>
                </div>

                <!-- Alertas -->
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>

                <!-- Formulario de Nuevo Producto -->
                <div class="card card-primary card-outline">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-plus-circle"></i> Nuevo Producto
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" id="nuevoProductoForm">
                            <input type="hidden" name="action" value="create">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><i class="fas fa-tag"></i> Nombre del Producto *</label>
                                        <input type="text" name="nombre" class="form-control" placeholder="Ej: Proteína Whey Protein" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><i class="fas fa-list"></i> Categoría *</label>
                                        <div class="select-with-add">
                                            <select name="categoria_id" class="form-control" required>
                                                <option value="">Seleccionar categoría</option>
                                                <?php foreach ($categorias as $cat): ?>
                                                    <option value="<?php echo $cat['id']; ?>">
                                                        <?php echo htmlspecialchars($cat['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn-add" onclick="openCategoriaModal()" title="Agregar nueva categoría">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><i class="fas fa-truck"></i> Proveedor</label>
                                        <div class="select-with-add">
                                            <select name="proveedor_id" class="form-control">
                                                <option value="">Seleccionar proveedor</option>
                                                <?php foreach ($proveedores as $prov): ?>
                                                    <option value="<?php echo $prov['id']; ?>">
                                                        <?php echo htmlspecialchars($prov['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn-add" onclick="openProveedorModal()" title="Agregar nuevo proveedor">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><i class="fas fa-image"></i> Foto del Producto</label>
                                        <input type="file" name="foto" class="form-control" accept="image/*" onchange="previewImage(this, 'previewNuevo')">
                                        <div id="previewNuevo" style="margin-top: 10px; display: none;"></div>
                                        <small class="text-muted">Formatos: JPG, PNG, WEBP (Max 2MB)</small>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label><i class="fas fa-dollar-sign"></i> Precio de Compra *</label>
                                        <input type="number" name="precio_compra" class="form-control" step="0.01" min="0" placeholder="0.00" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label><i class="fas fa-money-bill-wave"></i> Precio de Venta *</label>
                                        <input type="number" name="precio_venta" class="form-control" step="0.01" min="0" placeholder="0.00" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label><i class="fas fa-box"></i> Stock Actual</label>
                                        <input type="number" name="stock" class="form-control" min="0" value="0" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label><i class="fas fa-exclamation-triangle"></i> Stock Mínimo</label>
                                        <input type="number" name="stock_minimo" class="form-control" min="0" value="5" required>
                                        <small class="text-muted">Alertar cuando el stock esté por debajo</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group text-right">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Guardar Producto
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class="fas fa-undo"></i> Limpiar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Filtros de búsqueda -->
                <div class="card filter-card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-filter"></i> Filtros de búsqueda
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label><i class="fas fa-search"></i> Buscar producto</label>
                                    <input type="text" id="searchInput" class="form-control" 
                                           placeholder="Nombre o descripción..." 
                                           value="<?php echo htmlspecialchars($busqueda); ?>"
                                           onkeyup="buscarProductos()">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label><i class="fas fa-list"></i> Categoría</label>
                                    <select id="categoriaFilter" class="form-control" onchange="buscarProductos()">
                                        <option value="0">Todas las categorías</option>
                                        <?php foreach ($categorias as $cat): ?>
                                            <option value="<?php echo $cat['id']; ?>" 
                                                <?php echo $categoria_filtro == $cat['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cat['nombre']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label><i class="fas fa-circle"></i> Estado</label>
                                    <select id="estadoFilter" class="form-control" onchange="buscarProductos()">
                                        <option value="todos" <?php echo $estado_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                                        <option value="activo" <?php echo $estado_filtro == 'activo' ? 'selected' : ''; ?>>Activos</option>
                                        <option value="inactivo" <?php echo $estado_filtro == 'inactivo' ? 'selected' : ''; ?>>Inactivos</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <button class="btn btn-secondary btn-block" onclick="limpiarFiltros()">
                                        <i class="fas fa-eraser"></i> Limpiar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabla de Productos -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-boxes"></i> Lista de Productos
                            <span class="badge badge-info ml-2">Total: <?php echo $total_registros; ?></span>
                        </h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <div id="tablaProductos">
                                <?php include 'productos_table.php'; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modales (mantener los mismos que tenías) -->
    <div class="modal fade" id="editProductoModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Producto</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="editProductoForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="producto_id" id="edit_producto_id">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><i class="fas fa-tag"></i> Nombre del Producto *</label>
                                    <input type="text" name="nombre" id="edit_nombre" class="form-control" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><i class="fas fa-list"></i> Categoría *</label>
                                    <div class="select-with-add">
                                        <select name="categoria_id" id="edit_categoria_id" class="form-control" required>
                                            <option value="">Seleccionar categoría</option>
                                            <?php foreach ($categorias as $cat): ?>
                                                <option value="<?php echo $cat['id']; ?>">
                                                    <?php echo htmlspecialchars($cat['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn-add" onclick="openCategoriaModalEdit()" title="Agregar nueva categoría">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><i class="fas fa-truck"></i> Proveedor</label>
                                    <div class="select-with-add">
                                        <select name="proveedor_id" id="edit_proveedor_id" class="form-control">
                                            <option value="">Seleccionar proveedor</option>
                                            <?php foreach ($proveedores as $prov): ?>
                                                <option value="<?php echo $prov['id']; ?>">
                                                    <?php echo htmlspecialchars($prov['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn-add" onclick="openProveedorModalEdit()" title="Agregar nuevo proveedor">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><i class="fas fa-image"></i> Foto del Producto</label>
                                    <input type="file" name="foto" id="edit_foto" class="form-control" accept="image/*" onchange="previewImageEdit(this)">
                                    <div id="edit_preview" style="margin-top: 10px; display: none;"></div>
                                    <div id="edit_current_image" style="margin-top: 10px; display: none;"></div>
                                    <small class="text-muted">Formatos: JPG, PNG, WEBP (Max 2MB)</small>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><i class="fas fa-dollar-sign"></i> Precio de Compra *</label>
                                    <input type="number" name="precio_compra" id="edit_precio_compra" class="form-control" step="0.01" min="0" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><i class="fas fa-money-bill-wave"></i> Precio de Venta *</label>
                                    <input type="number" name="precio_venta" id="edit_precio_venta" class="form-control" step="0.01" min="0" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Actualizar Producto</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para Agregar Stock -->
    <div class="modal fade" id="stockModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Agregar Stock</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="POST" action="productos.php">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_stock">
                        <input type="hidden" name="producto_id" id="stock_producto_id">
                        <input type="hidden" name="motivo" value="Entrada de stock">
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Esta acción INCREMENTARÁ el stock del producto.
                        </div>
                        
                        <div class="form-group">
                            <label>Producto:</label>
                            <p><strong id="stock_producto_nombre" class="text-primary"></strong></p>
                        </div>
                        
                        <div class="form-group">
                            <label>Stock Actual:</label>
                            <p><span id="stock_actual" class="badge badge-info" style="font-size: 14px; padding: 8px 12px;"></span></p>
                        </div>
                        
                        <div class="form-group">
                            <label>Cantidad a agregar *</label>
                            <input type="number" name="cantidad" id="cantidad_stock" class="form-control" min="1" required>
                            <small class="text-muted">Nuevo stock: <span id="nuevo_stock_preview">0</span> unidades</small>
                        </div>
                        
                        <div class="form-group">
                            <label>Observaciones (opcional)</label>
                            <textarea name="observaciones" class="form-control" rows="2" placeholder="Motivo de la entrada de stock..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Agregar Stock</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para Ajuste de Stock -->
    <div class="modal fade" id="ajusteModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-balance-scale"></i> Corrección de Inventario</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="POST" action="productos.php" id="ajusteForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="ajuste_stock">
                        <input type="hidden" name="producto_id" id="ajuste_producto_id">
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> <strong>Importante:</strong> Esta acción CORREGIRÁ el stock. Úsala con precaución.
                        </div>
                        
                        <div class="form-group">
                            <label>Producto:</label>
                            <p><strong id="ajuste_producto_nombre" class="text-primary"></strong></p>
                        </div>
                        
                        <div class="form-group">
                            <label>Tipo de Ajuste *</label>
                            <select name="tipo_ajuste" id="tipo_ajuste" class="form-control" onchange="mostrarCampoAjuste()" required>
                                <option value="">Seleccionar tipo de ajuste</option>
                                <option value="stock_correccion">Corregir Stock</option>
                                <option value="stock_minimo">Cambiar Stock Mínimo</option>
                            </select>
                        </div>
                        
                        <!-- Campos dinámicos para corrección de stock -->
                        <div id="campo_correccion_stock" style="display: none;">
                            <div class="form-group">
                                <label>Stock Actual en Sistema:</label>
                                <p><span id="ajuste_stock_actual" class="badge badge-info" style="font-size: 14px; padding: 8px 12px;"></span></p>
                            </div>
                            
                            <div class="form-group">
                                <label>Stock Físico (real) *</label>
                                <input type="number" name="stock_fisico" id="stock_fisico" class="form-control" min="0" step="1">
                                <small id="diferencia_stock" class="text-muted"></small>
                            </div>
                        </div>
                        
                        <!-- Campos dinámicos para cambio de stock mínimo -->
                        <div id="campo_stock_minimo" style="display: none;">
                            <div class="form-group">
                                <label>Stock Mínimo Actual:</label>
                                <p><span id="ajuste_stock_minimo_actual" class="badge badge-warning" style="font-size: 14px; padding: 8px 12px;"></span></p>
                            </div>
                            
                            <div class="form-group">
                                <label>Nuevo Stock Mínimo *</label>
                                <input type="number" name="nuevo_stock_minimo" id="nuevo_stock_minimo" class="form-control" min="0" step="1">
                                <small id="preview_stock_minimo" class="text-muted"></small>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Motivo del ajuste *</label>
                            <select name="motivo_ajuste" class="form-control" required>
                                <option value="">Seleccionar motivo</option>
                                <option value="inventario_fisico">Inventario físico</option>
                                <option value="merma">Merma / Pérdida</option>
                                <option value="sobrante">Sobrante encontrado</option>
                                <option value="error_sistema">Error de sistema</option>
                                <option value="reajuste_minimo">Reajuste de stock mínimo</option>
                                <option value="otros">Otros</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Observaciones</label>
                            <textarea name="observaciones" class="form-control" rows="2" placeholder="Detalles de la corrección..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-warning">Aplicar Corrección</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modales para Categoría y Proveedor -->
    <div class="modal fade" id="categoriaModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Agregar Nueva Categoría</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nombre de la categoría *</label>
                        <input type="text" id="nombre_categoria" class="form-control" placeholder="Ej: Suplementos">
                    </div>
                    <div class="form-group">
                        <label>Descripción</label>
                        <textarea id="descripcion_categoria" class="form-control" rows="2" placeholder="Descripción opcional"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="guardarCategoria()">Guardar Categoría</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="proveedorModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Agregar Nuevo Proveedor</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nombre del proveedor *</label>
                        <input type="text" id="nombre_proveedor" class="form-control" placeholder="Ej: Suplementos Pro">
                    </div>
                    <div class="form-group">
                        <label>Contacto</label>
                        <input type="text" id="contacto_proveedor" class="form-control" placeholder="Nombre del contacto">
                    </div>
                    <div class="form-group">
                        <label>Teléfono</label>
                        <input type="text" id="telefono_proveedor" class="form-control" placeholder="Ej: 555-1234">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" id="email_proveedor" class="form-control" placeholder="correo@ejemplo.com">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="guardarProveedor()">Guardar Proveedor</button>
                </div>
            </div>
        </div>
    </div>

    <form id="toggleStatusForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="toggle_status">
        <input type="hidden" name="producto_id" id="toggle_producto_id">
        <input type="hidden" name="nuevo_estado" id="toggle_nuevo_estado">
    </form>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        let ajusteData = {};
                
        function buscarProductos() {
            clearTimeout(window.searchTimeout);
            window.searchTimeout = setTimeout(() => {
                const busqueda = document.getElementById('searchInput').value;
                const categoria = document.getElementById('categoriaFilter').value;
                const estado = document.getElementById('estadoFilter').value;
                const limite = document.getElementById('registrosPorPagina') ? document.getElementById('registrosPorPagina').value : 10;
                const pagina = 1;
                
                fetch(`productos_ajax.php?action=list&busqueda=${encodeURIComponent(busqueda)}&categoria=${categoria}&estado=${estado}&pagina=${pagina}&limite=${limite}`)
                    .then(response => response.text())
                    .then(data => {
                        document.getElementById('tablaProductos').innerHTML = data;
                    })
                    .catch(error => {
                        Swal.fire('Error', 'Error al cargar los productos', 'error');
                    });
            }, 300);
        }
        
        function limpiarFiltros() {
            document.getElementById('searchInput').value = '';
            document.getElementById('categoriaFilter').value = '0';
            document.getElementById('estadoFilter').value = 'todos';
            buscarProductos();
        }
        
        function previewImage(input, previewId) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    var preview = document.getElementById(previewId);
                    preview.innerHTML = '<img src="' + e.target.result + '" class="preview-image"><small class="text-muted d-block">Vista previa</small>';
                    preview.style.display = 'block';
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        function previewImageEdit(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    var preview = document.getElementById('edit_preview');
                    preview.innerHTML = '<img src="' + e.target.result + '" class="preview-image"><small class="text-muted d-block">Nueva imagen</small>';
                    preview.style.display = 'block';
                    document.getElementById('edit_current_image').style.display = 'none';
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        function editProducto(id) {
            fetch(`productos_ajax.php?action=get&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        $('#edit_producto_id').val(data.producto.id);
                        $('#edit_nombre').val(data.producto.nombre);
                        $('#edit_categoria_id').val(data.producto.categoria_id);
                        $('#edit_proveedor_id').val(data.producto.proveedor_id || '');
                        $('#edit_precio_compra').val(data.producto.precio_compra);
                        $('#edit_precio_venta').val(data.producto.precio_venta);
                        
                        if (data.producto.foto && data.producto.foto !== 'null' && data.producto.foto !== '') {
                            $('#edit_current_image').html(`
                                <img src="${data.producto.foto}" class="preview-image">
                                <small class="text-muted d-block">Imagen actual</small>
                            `).show();
                        } else {
                            $('#edit_current_image').hide();
                        }
                        
                        $('#edit_preview').hide().html('');
                        $('#edit_foto').val('');
                        
                        $('#editProductoModal').modal('show');
                    } else {
                        Swal.fire('Error', 'No se pudo cargar el producto', 'error');
                    }
                })
                .catch(error => {
                    Swal.fire('Error', 'Error al cargar los datos del producto', 'error');
                });
        }

        function openStockModal(id, nombre, stockActual) {
            $('#stock_producto_id').val(id);
            $('#stock_producto_nombre').text(nombre);
            $('#stock_actual').text(stockActual + ' unidades');
            $('#cantidad_stock').val('');
            $('#nuevo_stock_preview').text('0 unidades');
            
            $('#cantidad_stock').off('keyup').on('keyup', function() {
                const cantidad = parseInt($(this).val()) || 0;
                const nuevoStock = parseInt(stockActual) + cantidad;
                $('#nuevo_stock_preview').text(nuevoStock + ' unidades');
            });
            
            $('#stockModal').modal('show');
        }

        function openAjusteModal(id, nombre, stockActual, stockMinimo) {
            $('#ajuste_producto_id').val(id);
            $('#ajuste_producto_nombre').text(nombre);
            $('#ajuste_stock_actual').text(stockActual + ' unidades');
            $('#ajuste_stock_minimo_actual').text(stockMinimo + ' unidades');
            
            ajusteData = {
                stock: stockActual,
                stockMinimo: stockMinimo
            };
            
            $('#tipo_ajuste').val('');
            $('#campo_correccion_stock').hide();
            $('#campo_stock_minimo').hide();
            $('#stock_fisico').val('');
            $('#nuevo_stock_minimo').val('');
            $('#diferencia_stock').text('');
            $('#preview_stock_minimo').text('');
            
            $('#ajusteModal').modal('show');
        }

        function mostrarCampoAjuste() {
            var tipo = document.getElementById('tipo_ajuste').value;
            
            $('#campo_correccion_stock').hide();
            $('#campo_stock_minimo').hide();
            
            if (tipo === 'stock_correccion') {
                $('#campo_correccion_stock').show();
                
                $('#stock_fisico').off('keyup').on('keyup', function() {
                    const stockFisico = parseInt($(this).val()) || 0;
                    const diferencia = stockFisico - ajusteData.stock;
                    
                    if (diferencia > 0) {
                        $('#diferencia_stock').html('<span class="text-success">Aumentará en ' + diferencia + ' unidades. Nuevo stock: ' + stockFisico + '</span>');
                    } else if (diferencia < 0) {
                        $('#diferencia_stock').html('<span class="text-danger">Disminuirá en ' + Math.abs(diferencia) + ' unidades. Nuevo stock: ' + stockFisico + '</span>');
                    } else {
                        $('#diferencia_stock').html('<span class="text-muted">✓ Sin cambios. Stock actual: ' + ajusteData.stock + ' unidades</span>');
                    }
                });
                
                $('#nuevo_stock_minimo').removeAttr('required');
                $('#stock_fisico').attr('required', true);
                
            } else if (tipo === 'stock_minimo') {
                $('#campo_stock_minimo').show();
                
                $('#nuevo_stock_minimo').off('keyup').on('keyup', function() {
                    const nuevoMinimo = parseInt($(this).val()) || 0;
                    if (nuevoMinimo !== ajusteData.stockMinimo) {
                        if (nuevoMinimo > ajusteData.stockMinimo) {
                            $('#preview_stock_minimo').html('<span class="text-warning">⚠️ Stock mínimo aumentará de ' + ajusteData.stockMinimo + ' a ' + nuevoMinimo + ' unidades</span>');
                        } else {
                            $('#preview_stock_minimo').html('<span class="text-info">📉 Stock mínimo disminuirá de ' + ajusteData.stockMinimo + ' a ' + nuevoMinimo + ' unidades</span>');
                        }
                    } else {
                        $('#preview_stock_minimo').html('<span class="text-muted">✓ Stock mínimo actual: ' + ajusteData.stockMinimo + ' unidades (sin cambios)</span>');
                    }
                });
                
                $('#stock_fisico').removeAttr('required');
                $('#nuevo_stock_minimo').attr('required', true);
            }
        }

        // Manejar envío del formulario de ajustes con SweetAlert
        $('#ajusteForm').on('submit', function(e) {
            e.preventDefault();
            
            const tipoAjuste = $('#tipo_ajuste').val();
            let mensajeConfirmacion = '';
            let confirmText = '';
            
            if (tipoAjuste === 'stock_correccion') {
                const stockFisico = $('#stock_fisico').val();
                const diferencia = stockFisico - ajusteData.stock;
                if (diferencia > 0) {
                    mensajeConfirmacion = `
                        <div style="text-align: left;">
                            <p><strong>Producto:</strong> ${$('#ajuste_producto_nombre').text()}</p>
                            <p><strong>Stock actual:</strong> ${ajusteData.stock} unidades</p>
                            <p><strong>Stock físico:</strong> ${stockFisico} unidades</p>
                            <p class="text-success"><strong>Cambio:</strong> Aumentará en ${diferencia} unidades</p>
                            <p><strong>Nuevo stock:</strong> ${stockFisico} unidades</p>
                        </div>
                    `;
                    confirmText = 'Sí, aumentar stock';
                } else if (diferencia < 0) {
                    mensajeConfirmacion = `
                        <div style="text-align: left;">
                            <p><strong>Producto:</strong> ${$('#ajuste_producto_nombre').text()}</p>
                            <p><strong>Stock actual:</strong> ${ajusteData.stock} unidades</p>
                            <p><strong>Stock físico:</strong> ${stockFisico} unidades</p>
                            <p class="text-danger"><strong>Cambio:</strong> Disminuirá en ${Math.abs(diferencia)} unidades</p>
                            <p><strong>Nuevo stock:</strong> ${stockFisico} unidades</p>
                        </div>
                    `;
                    confirmText = 'Sí, disminuir stock';
                } else {
                    mensajeConfirmacion = `
                        <div style="text-align: left;">
                            <p><strong>Producto:</strong> ${$('#ajuste_producto_nombre').text()}</p>
                            <p><strong>Stock actual:</strong> ${ajusteData.stock} unidades</p>
                            <p><strong>Stock físico:</strong> ${stockFisico} unidades</p>
                            <p class="text-muted"><strong>⚠️ Sin cambios</strong></p>
                        </div>
                    `;
                    confirmText = 'Sí, continuar';
                }
            } else if (tipoAjuste === 'stock_minimo') {
                const nuevoMinimo = $('#nuevo_stock_minimo').val();
                if (nuevoMinimo != ajusteData.stockMinimo) {
                    mensajeConfirmacion = `
                        <div style="text-align: left;">
                            <p><strong>Producto:</strong> ${$('#ajuste_producto_nombre').text()}</p>
                            <p><strong>Stock mínimo actual:</strong> ${ajusteData.stockMinimo} unidades</p>
                            <p><strong>Nuevo stock mínimo:</strong> ${nuevoMinimo} unidades</p>
                            <p class="text-warning"><strong>Cambio:</strong> ${nuevoMinimo > ajusteData.stockMinimo ? 'Aumentará' : 'Disminuirá'} en ${Math.abs(nuevoMinimo - ajusteData.stockMinimo)} unidades</p>
                        </div>
                    `;
                } else {
                    mensajeConfirmacion = `
                        <div style="text-align: left;">
                            <p><strong>Producto:</strong> ${$('#ajuste_producto_nombre').text()}</p>
                            <p><strong>Stock mínimo actual:</strong> ${ajusteData.stockMinimo} unidades</p>
                            <p><strong>Nuevo stock mínimo:</strong> ${nuevoMinimo} unidades</p>
                            <p class="text-muted"><strong>Sin cambios</strong></p>
                        </div>
                    `;
                }
                confirmText = 'Sí, actualizar';
            }
            
            Swal.fire({
                title: '¿Confirmar ajuste?',
                html: mensajeConfirmacion,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ffc107',
                cancelButtonColor: '#d33',
                confirmButtonText: confirmText,
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Mostrar loading
                    Swal.fire({
                        title: 'Procesando...',
                        text: 'Guardando los cambios',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    // Enviar formulario via AJAX
                    const formData = new FormData(this);
                    
                    fetch('productos.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                title: '¡Éxito!',
                                text: data.message,
                                icon: 'success',
                                confirmButtonText: 'OK'
                            }).then(() => {
                                $('#ajusteModal').modal('hide');
                                buscarProductos(); // Recargar la tabla
                            });
                        } else {
                            Swal.fire({
                                title: 'Error',
                                text: data.error || 'Error al aplicar el ajuste',
                                icon: 'error',
                                confirmButtonText: 'OK'
                            });
                        }
                    })
                    .catch(error => {
                        Swal.fire({
                            title: 'Error',
                            text: 'Error de conexión: ' + error,
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    });
                }
            });
        });
        
        function toggleStatus(id, estado) {
            const accion = estado === 'activo' ? 'activar' : 'desactivar';
            Swal.fire({
                title: '¿Estás seguro?',
                text: `¿Deseas ${accion} este producto?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Sí, ' + accion,
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('toggle_producto_id').value = id;
                    document.getElementById('toggle_nuevo_estado').value = estado;
                    document.getElementById('toggleStatusForm').submit();
                }
            });
        }
        
        let categoriaOrigen = '';
        let proveedorOrigen = '';
        
        function openCategoriaModal() {
            categoriaOrigen = 'nuevo';
            $('#categoriaModal').modal('show');
        }
        
        function openCategoriaModalEdit() {
            categoriaOrigen = 'edit';
            $('#categoriaModal').modal('show');
        }
        
        function guardarCategoria() {
            const nombre = document.getElementById('nombre_categoria').value;
            const descripcion = document.getElementById('descripcion_categoria').value;
            
            if (!nombre) {
                Swal.fire('Advertencia', 'Por favor ingrese el nombre de la categoría', 'warning');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'add_categoria');
            formData.append('nombre_categoria', nombre);
            formData.append('descripcion_categoria', descripcion);
            
            fetch('productos.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (categoriaOrigen === 'nuevo') {
                        const select = document.querySelector('#nuevoProductoForm select[name="categoria_id"]');
                        const option = document.createElement('option');
                        option.value = data.id;
                        option.text = data.nombre;
                        select.appendChild(option);
                        select.value = data.id;
                    } else if (categoriaOrigen === 'edit') {
                        const select = document.getElementById('edit_categoria_id');
                        const option = document.createElement('option');
                        option.value = data.id;
                        option.text = data.nombre;
                        select.appendChild(option);
                        select.value = data.id;
                    }
                    
                    const filterSelect = document.getElementById('categoriaFilter');
                    const filterOption = document.createElement('option');
                    filterOption.value = data.id;
                    filterOption.text = data.nombre;
                    filterSelect.appendChild(filterOption);
                    
                    $('#categoriaModal').modal('hide');
                    document.getElementById('nombre_categoria').value = '';
                    document.getElementById('descripcion_categoria').value = '';
                    
                    Swal.fire('Éxito', 'Categoría agregada correctamente', 'success');
                } else {
                    Swal.fire('Error', 'Error al guardar categoría: ' + data.error, 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Error de conexión', 'error');
            });
        }
        
        function openProveedorModal() {
            proveedorOrigen = 'nuevo';
            $('#proveedorModal').modal('show');
        }
        
        function openProveedorModalEdit() {
            proveedorOrigen = 'edit';
            $('#proveedorModal').modal('show');
        }
        
        function guardarProveedor() {
            const nombre = document.getElementById('nombre_proveedor').value;
            const contacto = document.getElementById('contacto_proveedor').value;
            const telefono = document.getElementById('telefono_proveedor').value;
            const email = document.getElementById('email_proveedor').value;
            
            if (!nombre) {
                Swal.fire('Advertencia', 'Por favor ingrese el nombre del proveedor', 'warning');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'add_proveedor');
            formData.append('nombre_proveedor', nombre);
            formData.append('contacto_proveedor', contacto);
            formData.append('telefono_proveedor', telefono);
            formData.append('email_proveedor', email);
            
            fetch('productos.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (proveedorOrigen === 'nuevo') {
                        const select = document.querySelector('#nuevoProductoForm select[name="proveedor_id"]');
                        const option = document.createElement('option');
                        option.value = data.id;
                        option.text = data.nombre;
                        select.appendChild(option);
                        select.value = data.id;
                    } else if (proveedorOrigen === 'edit') {
                        const select = document.getElementById('edit_proveedor_id');
                        const option = document.createElement('option');
                        option.value = data.id;
                        option.text = data.nombre;
                        select.appendChild(option);
                        select.value = data.id;
                    }
                    
                    $('#proveedorModal').modal('hide');
                    document.getElementById('nombre_proveedor').value = '';
                    document.getElementById('contacto_proveedor').value = '';
                    document.getElementById('telefono_proveedor').value = '';
                    document.getElementById('email_proveedor').value = '';
                    
                    Swal.fire('Éxito', 'Proveedor agregado correctamente', 'success');
                } else {
                    Swal.fire('Error', 'Error al guardar proveedor: ' + data.error, 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Error de conexión', 'error');
            });
        }
        
        $('#editProductoForm').on('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('productos.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Éxito', data.message, 'success').then(() => {
                        $('#editProductoModal').modal('hide');
                        buscarProductos();
                    });
                } else {
                    Swal.fire('Error', data.error, 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Error al actualizar el producto', 'error');
            });
        });
        
        $(document).ready(function() {
            $('[title]').tooltip();
        });
    </script>
</body>
</html>