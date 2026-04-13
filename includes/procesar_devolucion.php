<?php
// Archivo: includes/procesar_devolucion.php
// Procesar devolución de artículos

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

// Permitir tanto admin como recepcionista
if ($_SESSION['user_rol'] !== 'admin' && $_SESSION['user_rol'] !== 'recepcionista') {
    echo json_encode(['success' => false, 'message' => 'No tienes permiso para procesar devoluciones']);
    exit();
}

require_once '../config/database.php';

$database = new Database();
$conn = $database->getConnection();

$data = json_decode(file_get_contents('php://input'), true);
$venta_id = isset($data['venta_id']) ? (int)$data['venta_id'] : 0;
$producto_id = isset($data['producto_id']) ? (int)$data['producto_id'] : 0;
$cantidad = isset($data['cantidad']) ? (int)$data['cantidad'] : 0;
$motivo = isset($data['motivo']) ? trim($data['motivo']) : '';

if (!$venta_id || !$producto_id || !$cantidad) {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
    exit();
}

if (empty($motivo)) {
    echo json_encode(['success' => false, 'message' => 'Debe ingresar un motivo para la devolución']);
    exit();
}

try {
    $conn->begin_transaction();
    
    // Verificar que la venta existe y está completada
    $query_check = "SELECT estado FROM ventas WHERE id = ?";
    $stmt_check = $conn->prepare($query_check);
    $stmt_check->bind_param("i", $venta_id);
    $stmt_check->execute();
    $venta = $stmt_check->get_result()->fetch_assoc();
    
    if (!$venta) {
        throw new Exception("Venta no encontrada");
    }
    
    if ($venta['estado'] !== 'completada') {
        throw new Exception("Solo se pueden devolver productos de ventas completadas");
    }
    
    // Verificar que la cantidad a devolver no exceda la vendida
    $query_check = "SELECT cantidad, precio_unitario FROM detalle_ventas 
                    WHERE venta_id = ? AND producto_id = ?";
    $stmt = $conn->prepare($query_check);
    $stmt->bind_param("ii", $venta_id, $producto_id);
    $stmt->execute();
    $detalle = $stmt->get_result()->fetch_assoc();
    
    if (!$detalle) {
        throw new Exception("Producto no encontrado en esta venta");
    }
    
    if ($detalle['cantidad'] < $cantidad) {
        throw new Exception("Cantidad a devolver excede la cantidad vendida (Máximo: " . $detalle['cantidad'] . ")");
    }
    
    // Calcular monto a devolver
    $monto_devuelto = $detalle['precio_unitario'] * $cantidad;
    
    // Actualizar o eliminar el detalle
    if ($detalle['cantidad'] == $cantidad) {
        $query_delete = "DELETE FROM detalle_ventas WHERE venta_id = ? AND producto_id = ?";
        $stmt_del = $conn->prepare($query_delete);
        $stmt_del->bind_param("ii", $venta_id, $producto_id);
        if (!$stmt_del->execute()) {
            throw new Exception("Error al eliminar el detalle de la venta");
        }
    } else {
        $query_update = "UPDATE detalle_ventas SET cantidad = cantidad - ? 
                         WHERE venta_id = ? AND producto_id = ?";
        $stmt_upd = $conn->prepare($query_update);
        $stmt_upd->bind_param("iii", $cantidad, $venta_id, $producto_id);
        if (!$stmt_upd->execute()) {
            throw new Exception("Error al actualizar la cantidad del producto");
        }
    }
    
    // Actualizar el total de la venta
    $query_update_total = "UPDATE ventas SET total = total - ? WHERE id = ?";
    $stmt_total = $conn->prepare($query_update_total);
    $stmt_total->bind_param("di", $monto_devuelto, $venta_id);
    if (!$stmt_total->execute()) {
        throw new Exception("Error al actualizar el total de la venta");
    }
    
    // Devolver stock
    $query_stock = "UPDATE productos SET stock = stock + ? WHERE id = ?";
    $stmt_stock = $conn->prepare($query_stock);
    $stmt_stock->bind_param("ii", $cantidad, $producto_id);
    if (!$stmt_stock->execute()) {
        throw new Exception("Error al devolver el stock del producto");
    }
    
    // Verificar si existe la tabla ventas_modificaciones
    $check_table = $conn->query("SHOW TABLES LIKE 'ventas_modificaciones'");
    if ($check_table->num_rows > 0) {
        // Registrar modificación
        $query_mod = "INSERT INTO ventas_modificaciones 
                      (venta_id, usuario_id, tipo_modificacion, descripcion, monto_devuelto, productos_devueltos, fecha_modificacion) 
                      VALUES (?, ?, 'devolucion_parcial', ?, ?, ?, NOW())";
        $stmt_mod = $conn->prepare($query_mod);
        $descripcion = "Devolución de $cantidad unidades - Motivo: $motivo";
        $productos_devueltos = json_encode([['producto_id' => $producto_id, 'cantidad' => $cantidad]]);
        $stmt_mod->bind_param("iisds", $venta_id, $_SESSION['user_id'], $descripcion, $monto_devuelto, $productos_devueltos);
        $stmt_mod->execute();
    }
    
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Devolución procesada correctamente. Monto devuelto: $' . number_format($monto_devuelto, 2)]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>