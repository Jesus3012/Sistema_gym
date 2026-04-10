<?php
session_start();
require_once '../config/database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$database = new Database();
$conn = $database->getConnection();

$cliente_id = $_POST['cliente_id'] ?? 0;
$tipo = $_POST['tipo'] ?? 'entrada';

if (!$cliente_id) {
    echo json_encode(['success' => false, 'message' => 'Cliente no válido']);
    exit;
}

// Verificar inscripción activa
$stmt = $conn->prepare("
    SELECT i.id, i.fecha_inicio, i.fecha_fin, p.nombre as plan_nombre,
           DATEDIFF(i.fecha_fin, CURDATE()) as dias_restantes
    FROM inscripciones i
    JOIN planes p ON i.plan_id = p.id
    WHERE i.cliente_id = ? AND i.estado = 'activa'
    ORDER BY i.fecha_fin ASC LIMIT 1
");
$stmt->bind_param("i", $cliente_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'El cliente no tiene una inscripción activa']);
    exit;
}

$inscripcion = $result->fetch_assoc();
$dias_restantes = $inscripcion['dias_restantes'];

if ($dias_restantes < 0) {
    echo json_encode(['success' => false, 'message' => 'La inscripción del cliente ha expirado']);
    exit;
}

$fecha_hoy = date('Y-m-d');
$hora_actual = date('H:i:s');
$usuario_id = $_SESSION['user_id'];

if ($tipo == 'salida') {
    // Registrar salida
    $stmt = $conn->prepare("SELECT id, hora_entrada FROM asistencias WHERE cliente_id = ? AND fecha = ? AND hora_salida IS NULL");
    $stmt->bind_param("is", $cliente_id, $fecha_hoy);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'No hay una entrada registrada para hoy']);
        exit;
    }
    
    $asistencia = $result->fetch_assoc();
    $stmt = $conn->prepare("UPDATE asistencias SET hora_salida = ? WHERE id = ?");
    $stmt->bind_param("si", $hora_actual, $asistencia['id']);
    $stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'Salida registrada correctamente']);
} else {
    // Registrar entrada
    $stmt = $conn->prepare("SELECT id FROM asistencias WHERE cliente_id = ? AND fecha = ?");
    $stmt->bind_param("is", $cliente_id, $fecha_hoy);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $asistencia = $result->fetch_assoc();
        $stmt = $conn->prepare("SELECT hora_salida FROM asistencias WHERE id = ?");
        $stmt->bind_param("i", $asistencia['id']);
        $stmt->execute();
        $check = $stmt->get_result()->fetch_assoc();
        
        if ($check['hora_salida']) {
            echo json_encode(['success' => false, 'message' => 'Ya registró entrada y salida hoy']);
            exit;
        } else {
            echo json_encode(['success' => false, 'message' => 'Ya tiene una entrada activa. Registre salida primero']);
            exit;
        }
    }
    
    $stmt = $conn->prepare("INSERT INTO asistencias (cliente_id, fecha, hora_entrada, metodo_registro, verificado_por, inscripcion_id, dias_restantes, plan_nombre) VALUES (?, ?, ?, 'manual', ?, ?, ?, ?)");
    $stmt->bind_param("issiiis", $cliente_id, $fecha_hoy, $hora_actual, $usuario_id, $inscripcion['id'], $dias_restantes, $inscripcion['plan_nombre']);
    $stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'Entrada registrada correctamente']);
}
?>