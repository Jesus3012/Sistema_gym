<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'config/database.php';
require_once 'includes/stock_functions.php';

$database = new Database();
$conn = $database->getConnection();

if (!$conn) {
    die(json_encode(['success' => false, 'error' => 'Error de conexión a la base de datos']));
}

$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

if ($action == 'list') {
    // Configuración de paginación con límite personalizable
    $registros_por_pagina = isset($_GET['limite']) ? (int)$_GET['limite'] : 10;
    $pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
    $offset = ($pagina - 1) * $registros_por_pagina;
    $busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
    $categoria = isset($_GET['categoria']) ? (int)$_GET['categoria'] : 0;
    $estado = isset($_GET['estado']) ? $_GET['estado'] : 'todos';
    
    // Construir consulta
    $where = [];
    $params = [];
    $types = "";
    
    if (!empty($busqueda)) {
        $where[] = "(p.nombre LIKE ? OR p.descripcion LIKE ?)";
        $params[] = "%$busqueda%";
        $params[] = "%$busqueda%";
        $types .= "ss";
    }
    
    if ($categoria > 0) {
        $where[] = "p.categoria_id = ?";
        $params[] = $categoria;
        $types .= "i";
    }
    
    if ($estado != 'todos') {
        $where[] = "p.estado = ?";
        $params[] = $estado;
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
    
    // Pasar variables para el template
    $pagina_actual = $pagina;
    $busqueda = $busqueda;
    $categoria_filtro = $categoria;
    $estado_filtro = $estado;
    $total_registros = $total_registros;
    $total_paginas = $total_paginas;
    
    // Incluir el template de tabla
    include 'productos_table.php';
}
elseif ($action == 'get') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($id > 0) {
        // Solo devolver campos editables, NO stock ni stock_minimo
        $stmt = $conn->prepare("SELECT id, nombre, descripcion, categoria_id, proveedor_id, precio_compra, precio_venta, foto FROM productos WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $producto = $result->fetch_assoc();
        
        if ($producto) {
            echo json_encode([
                'success' => true, 
                'producto' => [
                    'id' => $producto['id'],
                    'nombre' => $producto['nombre'],
                    'descripcion' => $producto['descripcion'],
                    'categoria_id' => $producto['categoria_id'],
                    'proveedor_id' => $producto['proveedor_id'],
                    'precio_compra' => $producto['precio_compra'],
                    'precio_venta' => $producto['precio_venta'],
                    'foto' => $producto['foto']
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Producto no encontrado']);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => 'ID inválido']);
    }
}
elseif ($action == 'update') {
    // Procesar actualización de producto - SIN modificar stock
    $id = isset($_POST['producto_id']) ? (int)$_POST['producto_id'] : 0;
    $nombre = trim($_POST['nombre']);
    $categoria_id = $_POST['categoria_id'];
    $proveedor_id = $_POST['proveedor_id'] ?: null;
    $precio_compra = floatval($_POST['precio_compra']);
    $precio_venta = floatval($_POST['precio_venta']);
    $descripcion = isset($_POST['descripcion']) ? trim($_POST['descripcion']) : '';
    
    if (empty($nombre)) {
        echo json_encode(['success' => false, 'error' => 'El nombre del producto es obligatorio']);
        exit();
    }
    
    if ($categoria_id == 0 || empty($categoria_id)) {
        echo json_encode(['success' => false, 'error' => 'Debe seleccionar una categoría']);
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
    
    // Actualizar SOLO los campos editables (NO stock ni stock_minimo)
    if ($foto_ruta) {
        $sql = "UPDATE productos SET nombre=?, categoria_id=?, proveedor_id=?, precio_compra=?, precio_venta=?, descripcion=?, foto=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("siiddssi", $nombre, $categoria_id, $proveedor_id, $precio_compra, $precio_venta, $descripcion, $foto_ruta, $id);
    } else {
        $sql = "UPDATE productos SET nombre=?, categoria_id=?, proveedor_id=?, precio_compra=?, precio_venta=?, descripcion=? WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("siiddsi", $nombre, $categoria_id, $proveedor_id, $precio_compra, $precio_venta, $descripcion, $id);
    }
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Producto actualizado exitosamente']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Error al actualizar producto: ' . $conn->error]);
    }
    $stmt->close();
}
elseif ($action == 'add_stock') {
    $id = intval($_POST['producto_id']);
    $cantidad = intval($_POST['cantidad']);
    $observaciones = isset($_POST['observaciones']) ? trim($_POST['observaciones']) : 'Agregado manualmente';
    
    if ($cantidad > 0) {
        // Obtener stock actual
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
            $resultado_mov = registrarMovimientoStock(
                $conn,
                $id,
                'entrada',
                $cantidad,
                'Entrada de stock',
                $_SESSION['user_id'],
                null,
                null,
                $observaciones . ' | Stock anterior: ' . $stock_anterior . ', nuevo: ' . $stock_nuevo
            );
            
            if ($resultado_mov['success']) {
                echo json_encode(['success' => true, 'message' => "Stock agregado exitosamente. Se añadieron $cantidad unidades."]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Stock actualizado pero error al registrar movimiento: ' . $resultado_mov['error']]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Error al agregar stock: ' . $conn->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => 'La cantidad debe ser mayor a 0']);
    }
    exit();
}
elseif ($action == 'ajuste_stock') {
    $id = intval($_POST['producto_id']);
    $tipo_ajuste = $_POST['tipo_ajuste'];
    $motivo = $_POST['motivo_ajuste'] ?? 'Ajuste manual';
    $observaciones = isset($_POST['observaciones']) ? trim($_POST['observaciones']) : '';
    
    if ($tipo_ajuste == 'stock_correccion') {
        $stock_fisico = intval($_POST['stock_fisico']);
        
        // Obtener stock actual
        $stmt = $conn->prepare("SELECT stock FROM productos WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $producto = $result->fetch_assoc();
        $stock_anterior = $producto['stock'];
        $diferencia = $stock_fisico - $stock_anterior;
        $stmt->close();
        
        // Actualizar stock
        $sql = "UPDATE productos SET stock = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $stock_fisico, $id);
        
        if ($stmt->execute()) {
            // Registrar movimiento de corrección
            $resultado_mov = registrarMovimientoStock(
                $conn,
                $id,
                'correccion',
                $diferencia,
                $motivo,
                $_SESSION['user_id'],
                null,
                null,
                $observaciones . ' | Corrección de inventario: Stock anterior ' . $stock_anterior . ', nuevo ' . $stock_fisico
            );
            
            if ($resultado_mov['success']) {
                echo json_encode(['success' => true, 'message' => "Stock corregido a $stock_fisico unidades"]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Error al registrar movimiento: ' . $resultado_mov['error']]);
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
        $stmt->close();
        
        // Actualizar stock mínimo
        $sql = "UPDATE productos SET stock_minimo = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $nuevo_stock_minimo, $id);
        
        if ($stmt->execute()) {
            // Registrar movimiento de ajuste de mínimo
            $resultado_mov = registrarMovimientoStock(
                $conn,
                $id,
                'ajuste_minimo',
                $nuevo_stock_minimo - $stock_minimo_anterior,
                $motivo,
                $_SESSION['user_id'],
                null,
                null,
                $observaciones . ' | Cambio de stock mínimo: de ' . $stock_minimo_anterior . ' a ' . $nuevo_stock_minimo
            );
            
            if ($resultado_mov['success']) {
                echo json_encode(['success' => true, 'message' => "Stock mínimo actualizado a $nuevo_stock_minimo unidades"]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Error al registrar movimiento: ' . $resultado_mov['error']]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Error al actualizar stock mínimo: ' . $conn->error]);
        }
        $stmt->close();
    }
    exit();
}
?>