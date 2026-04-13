<?php
// Archivo: includes/procesar_cancelacion.php
// Cancelar venta y devolver stock

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

// Permitir tanto admin como recepcionista
if ($_SESSION['user_rol'] !== 'admin' && $_SESSION['user_rol'] !== 'recepcionista') {
    echo json_encode(['success' => false, 'message' => 'No tienes permiso para cancelar ventas']);
    exit();
}

require_once '../config/database.php';

$database = new Database();
$conn = $database->getConnection();

$data = json_decode(file_get_contents('php://input'), true);
$venta_id = isset($data['venta_id']) ? (int)$data['venta_id'] : 0;

if (!$venta_id) {
    echo json_encode(['success' => false, 'message' => 'ID de venta inválido']);
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
    
    if ($venta['estado'] === 'cancelada') {
        throw new Exception("La venta ya está cancelada");
    }
    
    // Obtener detalles de la venta
    $query_detalles = "SELECT producto_id, cantidad FROM detalle_ventas WHERE venta_id = ?";
    $stmt = $conn->prepare($query_detalles);
    $stmt->bind_param("i", $venta_id);
    $stmt->execute();
    $detalles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    if (empty($detalles)) {
        throw new Exception("No se encontraron productos en esta venta");
    }
    
    // Devolver stock
    foreach ($detalles as $detalle) {
        $query_update = "UPDATE productos SET stock = stock + ? WHERE id = ?";
        $stmt_update = $conn->prepare($query_update);
        $stmt_update->bind_param("ii", $detalle['cantidad'], $detalle['producto_id']);
        if (!$stmt_update->execute()) {
            throw new Exception("Error al devolver stock del producto ID: " . $detalle['producto_id']);
        }
    }
    
    // Actualizar estado de la venta
    $query_update_venta = "UPDATE ventas SET estado = 'cancelada' WHERE id = ?";
    $stmt_update = $conn->prepare($query_update_venta);
    $stmt_update->bind_param("i", $venta_id);
    if (!$stmt_update->execute()) {
        throw new Exception("Error al actualizar el estado de la venta");
    }
    
    // Verificar si existe la tabla ventas_modificaciones
    $check_table = $conn->query("SHOW TABLES LIKE 'ventas_modificaciones'");
    if ($check_table->num_rows > 0) {
        // Registrar modificación
        $query_modificacion = "INSERT INTO ventas_modificaciones 
                               (venta_id, usuario_id, tipo_modificacion, descripcion, fecha_modificacion) 
                               VALUES (?, ?, 'cancelacion', 'Venta cancelada', NOW())";
        $stmt_mod = $conn->prepare($query_modificacion);
        $stmt_mod->bind_param("ii", $venta_id, $_SESSION['user_id']);
        $stmt_mod->execute();
    }
    
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Venta cancelada correctamente']);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>