<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Incluir la conexión a la base de datos
require_once 'config/database.php';

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
        $descripcion = trim($_POST['descripcion']);
        $categoria_id = $_POST['categoria_id'];
        $proveedor_id = $_POST['proveedor_id'] ?: null;
        $precio_compra = floatval($_POST['precio_compra']);
        $precio_venta = floatval($_POST['precio_venta']);
        $stock = intval($_POST['stock']);
        $stock_minimo = intval($_POST['stock_minimo']);
        
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
                $success = "Producto registrado exitosamente";
                // Limpiar el formulario después de guardar
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
        $motivo = trim($_POST['motivo']);
        
        if ($cantidad > 0) {
            $sql = "UPDATE productos SET stock = stock + ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $cantidad, $id);
            
            if ($stmt->execute()) {
                $success = "Stock agregado exitosamente. Se añadieron $cantidad unidades.";
            } else {
                $error = "Error al agregar stock: " . $conn->error;
            }
            $stmt->close();
        }
    }
    
    // Ajuste de producto
    elseif ($action == 'ajuste') {
        $id = intval($_POST['producto_id']);
        $tipo_ajuste = $_POST['tipo_ajuste'];
        $valor = floatval($_POST['valor']);
        
        if ($tipo_ajuste == 'precio_venta') {
            $sql = "UPDATE productos SET precio_venta = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("di", $valor, $id);
            if ($stmt->execute()) {
                $success = "Precio de venta actualizado a $" . number_format($valor, 2);
            }
        } 
        elseif ($tipo_ajuste == 'precio_compra') {
            $sql = "UPDATE productos SET precio_compra = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("di", $valor, $id);
            if ($stmt->execute()) {
                $success = "Precio de compra actualizado a $" . number_format($valor, 2);
            }
        }
        elseif ($tipo_ajuste == 'stock_correccion') {
            $valor_int = intval($valor);
            $sql = "UPDATE productos SET stock = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $valor_int, $id);
            if ($stmt->execute()) {
                $success = "Stock corregido a $valor_int unidades";
            }
        }
        elseif ($tipo_ajuste == 'stock_minimo') {
            $valor_int = intval($valor);
            $sql = "UPDATE productos SET stock_minimo = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $valor_int, $id);
            if ($stmt->execute()) {
                $success = "Stock mínimo actualizado a $valor_int unidades";
            }
        }
        
        if (isset($stmt)) {
            if (!$success) {
                $error = "Error al realizar ajuste: " . $conn->error;
            }
            $stmt->close();
        }
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
}

// Configuración de paginación y búsqueda
$registros_por_pagina = 10;
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productos - Gym System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f0f2f5;
        }

        .dashboard-layout {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 20px 30px;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }

        .page-title h2 {
            font-size: 28px;
            font-weight: 700;
            color: #1e3c72;
            margin-bottom: 5px;
        }

        .breadcrumb {
            color: #6c757d;
            font-size: 14px;
        }

        .breadcrumb a {
            color: #1e3c72;
            text-decoration: none;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            overflow: hidden;
        }

        .card-header {
            padding: 18px 24px;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
        }

        .card-header h3 {
            color: white;
            font-size: 18px;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-body {
            padding: 24px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #2c3e50;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: #1e3c72;
            box-shadow: 0 0 0 3px rgba(30,60,114,0.1);
        }

        .full-width {
            grid-column: span 2;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 14px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(30,60,114,0.3);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table thead tr {
            background: #f8f9fc;
            border-bottom: 2px solid #e3e6f0;
        }

        .table th {
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #4e73df;
            font-size: 13px;
        }

        .table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e3e6f0;
        }

        .producto-imagen {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
        }

        .stock-bajo {
            color: #dc3545;
            font-weight: 600;
        }

        .stock-normal {
            color: #28a745;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
        }

        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border: none;
            cursor: pointer;
        }

        .filters-bar {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 10px 40px 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
        }

        .search-box i {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
        }

        .filters-grid {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .filter-item {
            flex: 1;
            min-width: 150px;
        }

        .alert {
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 20px;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 18px;
        }

        .modal-header button {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 20px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
        }

        .page-link {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            text-decoration: none;
            color: #1e3c72;
            cursor: pointer;
        }

        .page-link.active {
            background: #1e3c72;
            color: white;
            border-color: #1e3c72;
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
            padding: 10px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            width: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-add:hover {
            background: #218838;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
            .full-width {
                grid-column: span 1;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="top-bar">
                <div class="page-title">
                    <h2><i class="fas fa-boxes"></i> Gestión de Productos</h2>
                    <div class="breadcrumb">
                        <a href="dashboard.php">Inicio</a> / Productos
                    </div>
                </div>
            </div>

            <!-- Alertas -->
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <!-- Formulario de Nuevo Producto (siempre visible) -->
            <div class="card">
                <div class="card-header">
                    <h3>
                        <i class="fas fa-plus-circle"></i> 
                        Nuevo Producto
                    </h3>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" id="nuevoProductoForm">
                        <input type="hidden" name="action" value="create">
                        
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-tag"></i> Nombre del Producto *</label>
                                <input type="text" name="nombre" class="form-control" placeholder="Ej: Proteína Whey Protein" required>
                            </div>
                            
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
                            
                            <div class="form-group">
                                <label><i class="fas fa-image"></i> Foto del Producto</label>
                                <input type="file" name="foto" class="form-control" accept="image/*" onchange="previewImage(this, 'previewNuevo')">
                                <div id="previewNuevo" style="margin-top: 10px; display: none;"></div>
                                <small style="color: #6c757d;">Formatos: JPG, PNG, WEBP (Max 2MB)</small>
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-dollar-sign"></i> Precio de Compra *</label>
                                <input type="number" name="precio_compra" class="form-control" step="0.01" min="0" placeholder="0.00" required>
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-money-bill-wave"></i> Precio de Venta *</label>
                                <input type="number" name="precio_venta" class="form-control" step="0.01" min="0" placeholder="0.00" required>
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-box"></i> Stock Actual</label>
                                <input type="number" name="stock" class="form-control" min="0" value="0" required>
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-exclamation-triangle"></i> Stock Mínimo</label>
                                <input type="number" name="stock_minimo" class="form-control" min="0" value="10" required>
                                <small style="color: #6c757d;">Alertar cuando el stock esté por debajo</small>
                            </div>
                            
                            <div class="form-group full-width">
                                <label><i class="fas fa-align-left"></i> Descripción</label>
                                <textarea name="descripcion" class="form-control" rows="3" placeholder="Descripción detallada del producto..."></textarea>
                            </div>
                        </div>
                        
                        <div style="margin-top: 25px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Guardar Producto
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Filtros de búsqueda en tiempo real -->
            <div class="filters-bar">
                <div class="filters-grid">
                    <div class="filter-item">
                        <label><i class="fas fa-search"></i> Buscar producto</label>
                        <div class="search-box">
                            <input type="text" id="searchInput" class="form-control" 
                                   placeholder="Nombre o descripción..." 
                                   value="<?php echo htmlspecialchars($busqueda); ?>"
                                   onkeyup="buscarProductos()">
                            <i class="fas fa-search"></i>
                        </div>
                    </div>
                    <div class="filter-item">
                        <label><i class="fas fa-list"></i> Categoría</label>
                        <select id="categoriaFilter" class="form-control" onchange="buscarProductos()">
                            <option value="0">Todas</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" 
                                    <?php echo $categoria_filtro == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label><i class="fas fa-circle"></i> Estado</label>
                        <select id="estadoFilter" class="form-control" onchange="buscarProductos()">
                            <option value="todos" <?php echo $estado_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                            <option value="activo" <?php echo $estado_filtro == 'activo' ? 'selected' : ''; ?>>Activos</option>
                            <option value="inactivo" <?php echo $estado_filtro == 'inactivo' ? 'selected' : ''; ?>>Inactivos</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Tabla de Productos -->
            <div class="card">
                <div class="card-header">
                    <h3>
                        <i class="fas fa-boxes"></i> Lista de Productos
                        <span style="font-size: 14px; margin-left: auto;">Total: <?php echo $total_registros; ?> productos</span>
                    </h3>
                </div>
                <div class="card-body">
                    <div id="tablaProductos">
                        <?php include 'productos_table.php'; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal para Editar Producto -->
    <div id="editProductoModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Editar Producto</h3>
                <button onclick="closeModal('editProductoModal')">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="editProductoForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="producto_id" id="edit_producto_id">
                    
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Nombre del Producto *</label>
                        <input type="text" name="nombre" id="edit_nombre" class="form-control" required>
                    </div>
                    
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
                    
                    <div class="form-group">
                        <label><i class="fas fa-image"></i> Foto del Producto</label>
                        <input type="file" name="foto" id="edit_foto" class="form-control" accept="image/*" onchange="previewImageEdit(this)">
                        <div id="edit_preview" style="margin-top: 10px; display: none;"></div>
                        <div id="edit_current_image" style="margin-top: 10px; display: none;"></div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-dollar-sign"></i> Precio de Compra *</label>
                        <input type="number" name="precio_compra" id="edit_precio_compra" class="form-control" step="0.01" min="0" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-money-bill-wave"></i> Precio de Venta *</label>
                        <input type="number" name="precio_venta" id="edit_precio_venta" class="form-control" step="0.01" min="0" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-box"></i> Stock Actual</label>
                        <input type="number" name="stock" id="edit_stock" class="form-control" min="0" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-exclamation-triangle"></i> Stock Mínimo</label>
                        <input type="number" name="stock_minimo" id="edit_stock_minimo" class="form-control" min="0" required>
                    </div>
                    
                    <div class="form-group full-width">
                        <label><i class="fas fa-align-left"></i> Descripción</label>
                        <textarea name="descripcion" id="edit_descripcion" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editProductoModal')">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Actualizar Producto</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal para Agregar Stock -->
    <div id="stockModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Agregar Stock</h3>
                <button onclick="closeModal('stockModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_stock">
                    <input type="hidden" name="producto_id" id="stock_producto_id">
                    <div class="form-group">
                        <label>Producto:</label>
                        <p><strong id="stock_producto_nombre"></strong></p>
                    </div>
                    <div class="form-group">
                        <label>Cantidad a agregar *</label>
                        <input type="number" name="cantidad" class="form-control" min="1" required>
                    </div>
                    <div class="form-group">
                        <label>Motivo</label>
                        <input type="text" name="motivo" class="form-control" placeholder="Ej: Compra a proveedor">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('stockModal')">Cancelar</button>
                    <button type="submit" class="btn btn-success">Agregar Stock</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal para Ajustes -->
    <div id="ajusteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Ajuste de Producto</h3>
                <button onclick="closeModal('ajusteModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="ajuste">
                    <input type="hidden" name="producto_id" id="ajuste_producto_id">
                    <div class="form-group">
                        <label>Producto:</label>
                        <p><strong id="ajuste_producto_nombre"></strong></p>
                    </div>
                    <div class="form-group">
                        <label>Tipo de Ajuste *</label>
                        <select name="tipo_ajuste" class="form-control" id="tipo_ajuste" onchange="mostrarCampoAjuste()" required>
                            <option value="">Seleccionar</option>
                            <option value="precio_venta">Precio de Venta</option>
                            <option value="precio_compra">Precio de Compra</option>
                            <option value="stock_correccion">Corregir Stock</option>
                            <option value="stock_minimo">Stock Mínimo</option>
                        </select>
                    </div>
                    <div class="form-group" id="campo_valor_ajuste">
                        <label id="label_ajuste">Valor *</label>
                        <input type="number" name="valor" id="valor_ajuste" class="form-control" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Motivo</label>
                        <textarea name="motivo_ajuste" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('ajusteModal')">Cancelar</button>
                    <button type="submit" class="btn btn-warning">Aplicar Ajuste</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal para Agregar Categoría (desde nuevo producto) -->
    <div id="categoriaModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Agregar Nueva Categoría</h3>
                <button onclick="closeModal('categoriaModal')">&times;</button>
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
                <button type="button" class="btn btn-secondary" onclick="closeModal('categoriaModal')">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="guardarCategoria()">Guardar Categoría</button>
            </div>
        </div>
    </div>

    <!-- Modal para Agregar Proveedor (desde nuevo producto) -->
    <div id="proveedorModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Agregar Nuevo Proveedor</h3>
                <button onclick="closeModal('proveedorModal')">&times;</button>
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
                <button type="button" class="btn btn-secondary" onclick="closeModal('proveedorModal')">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="guardarProveedor()">Guardar Proveedor</button>
            </div>
        </div>
    </div>

    <form id="toggleStatusForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="toggle_status">
        <input type="hidden" name="producto_id" id="toggle_producto_id">
        <input type="hidden" name="nuevo_estado" id="toggle_nuevo_estado">
    </form>

    <script>
        let ajusteData = {};
        
        function buscarProductos() {
            clearTimeout(window.searchTimeout);
            window.searchTimeout = setTimeout(() => {
                const busqueda = document.getElementById('searchInput').value;
                const categoria = document.getElementById('categoriaFilter').value;
                const estado = document.getElementById('estadoFilter').value;
                
                fetch(`productos_ajax.php?action=list&busqueda=${encodeURIComponent(busqueda)}&categoria=${categoria}&estado=${estado}`)
                    .then(response => response.text())
                    .then(data => {
                        document.getElementById('tablaProductos').innerHTML = data;
                    });
            }, 300);
        }
        
        function previewImage(input, previewId) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    var preview = document.getElementById(previewId);
                    preview.innerHTML = '<img src="' + e.target.result + '" style="max-width: 150px; border-radius: 8px;"><small style="display: block;">Vista previa</small>';
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
                    preview.innerHTML = '<img src="' + e.target.result + '" style="max-width: 150px; border-radius: 8px;"><small style="display: block;">Nueva imagen</small>';
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
                        document.getElementById('edit_producto_id').value = data.producto.id;
                        document.getElementById('edit_nombre').value = data.producto.nombre;
                        document.getElementById('edit_categoria_id').value = data.producto.categoria_id;
                        document.getElementById('edit_proveedor_id').value = data.producto.proveedor_id || '';
                        document.getElementById('edit_precio_compra').value = data.producto.precio_compra;
                        document.getElementById('edit_precio_venta').value = data.producto.precio_venta;
                        document.getElementById('edit_stock').value = data.producto.stock;
                        document.getElementById('edit_stock_minimo').value = data.producto.stock_minimo;
                        document.getElementById('edit_descripcion').value = data.producto.descripcion || '';
                        
                        if (data.producto.foto && data.producto.foto !== 'null' && data.producto.foto !== '') {
                            document.getElementById('edit_current_image').innerHTML = `
                                <img src="${data.producto.foto}" style="max-width: 150px; border-radius: 8px;">
                                <small style="display: block;">Imagen actual</small>
                            `;
                            document.getElementById('edit_current_image').style.display = 'block';
                        } else {
                            document.getElementById('edit_current_image').style.display = 'none';
                        }
                        
                        document.getElementById('edit_preview').style.display = 'none';
                        document.getElementById('edit_preview').innerHTML = '';
                        document.getElementById('edit_foto').value = '';
                        
                        document.getElementById('editProductoModal').classList.add('active');
                    }
                });
        }
        
        function openStockModal(id, nombre) {
            document.getElementById('stock_producto_id').value = id;
            document.getElementById('stock_producto_nombre').innerText = nombre;
            document.getElementById('stockModal').classList.add('active');
        }
        
        function openAjusteModal(id, nombre, pVenta, pCompra, stock, stockMin) {
            document.getElementById('ajuste_producto_id').value = id;
            document.getElementById('ajuste_producto_nombre').innerText = nombre;
            ajusteData = {precioVenta: pVenta, precioCompra: pCompra, stock: stock, stockMinimo: stockMin};
            document.getElementById('ajusteModal').classList.add('active');
        }
        
        function mostrarCampoAjuste() {
            var tipo = document.getElementById('tipo_ajuste').value;
            var label = document.getElementById('label_ajuste');
            var campo = document.getElementById('valor_ajuste');
            
            if (tipo === 'precio_venta') {
                label.innerText = 'Nuevo Precio de Venta *';
                campo.value = ajusteData.precioVenta || '';
            } else if (tipo === 'precio_compra') {
                label.innerText = 'Nuevo Precio de Compra *';
                campo.value = ajusteData.precioCompra || '';
            } else if (tipo === 'stock_correccion') {
                label.innerText = 'Stock Correcto *';
                campo.value = ajusteData.stock || '';
            } else if (tipo === 'stock_minimo') {
                label.innerText = 'Nuevo Stock Mínimo *';
                campo.value = ajusteData.stockMinimo || '';
            }
        }
        
        function toggleStatus(id, estado) {
            if (confirm('¿Estás seguro de ' + (estado === 'activo' ? 'activar' : 'desactivar') + ' este producto?')) {
                document.getElementById('toggle_producto_id').value = id;
                document.getElementById('toggle_nuevo_estado').value = estado;
                document.getElementById('toggleStatusForm').submit();
            }
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        
        function openCategoriaModal() {
            document.getElementById('categoriaModal').classList.add('active');
            window.categoriaOrigen = 'nuevo';
        }
        
        function openCategoriaModalEdit() {
            document.getElementById('categoriaModal').classList.add('active');
            window.categoriaOrigen = 'edit';
        }
        
        function guardarCategoria() {
            const nombre = document.getElementById('nombre_categoria').value;
            const descripcion = document.getElementById('descripcion_categoria').value;
            
            if (!nombre) {
                alert('Por favor ingrese el nombre de la categoría');
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
                    if (window.categoriaOrigen === 'nuevo') {
                        const select = document.querySelector('#nuevoProductoForm select[name="categoria_id"]');
                        const option = document.createElement('option');
                        option.value = data.id;
                        option.text = data.nombre;
                        select.appendChild(option);
                        select.value = data.id;
                    } else if (window.categoriaOrigen === 'edit') {
                        const select = document.getElementById('edit_categoria_id');
                        const option = document.createElement('option');
                        option.value = data.id;
                        option.text = data.nombre;
                        select.appendChild(option);
                        select.value = data.id;
                    }
                    
                    closeModal('categoriaModal');
                    document.getElementById('nombre_categoria').value = '';
                    document.getElementById('descripcion_categoria').value = '';
                    
                    // También actualizar los filtros
                    const filterSelect = document.getElementById('categoriaFilter');
                    const filterOption = document.createElement('option');
                    filterOption.value = data.id;
                    filterOption.text = data.nombre;
                    filterSelect.appendChild(filterOption);
                } else {
                    alert('Error al guardar categoría: ' + data.error);
                }
            });
        }
        
        function openProveedorModal() {
            document.getElementById('proveedorModal').classList.add('active');
            window.proveedorOrigen = 'nuevo';
        }
        
        function openProveedorModalEdit() {
            document.getElementById('proveedorModal').classList.add('active');
            window.proveedorOrigen = 'edit';
        }
        
        function guardarProveedor() {
            const nombre = document.getElementById('nombre_proveedor').value;
            const contacto = document.getElementById('contacto_proveedor').value;
            const telefono = document.getElementById('telefono_proveedor').value;
            const email = document.getElementById('email_proveedor').value;
            
            if (!nombre) {
                alert('Por favor ingrese el nombre del proveedor');
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
                    if (window.proveedorOrigen === 'nuevo') {
                        const select = document.querySelector('#nuevoProductoForm select[name="proveedor_id"]');
                        const option = document.createElement('option');
                        option.value = data.id;
                        option.text = data.nombre;
                        select.appendChild(option);
                        select.value = data.id;
                    } else if (window.proveedorOrigen === 'edit') {
                        const select = document.getElementById('edit_proveedor_id');
                        const option = document.createElement('option');
                        option.value = data.id;
                        option.text = data.nombre;
                        select.appendChild(option);
                        select.value = data.id;
                    }
                    
                    closeModal('proveedorModal');
                    document.getElementById('nombre_proveedor').value = '';
                    document.getElementById('contacto_proveedor').value = '';
                    document.getElementById('telefono_proveedor').value = '';
                    document.getElementById('email_proveedor').value = '';
                } else {
                    alert('Error al guardar proveedor: ' + data.error);
                }
            });
        }
        
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</body>
</html>