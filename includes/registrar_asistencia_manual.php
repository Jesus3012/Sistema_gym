<?php
date_default_timezone_set('America/Mexico_City');
session_start();
require_once '../config/database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$database = new Database();
$conn = $database->getConnection();

// Forzar zona horaria en MySQL antes de cualquier operación
$conn->query("SET time_zone = '-06:00'");

// Obtener fecha y hora desde PHP
$fecha_hoy = date('Y-m-d');
$hora_actual = date('H:i:s');
$usuario_id = $_SESSION['user_id'];

// Verificar la hora en MySQL después de configurar
$check = $conn->query("SELECT CURTIME() as hora_mysql")->fetch_assoc();
error_log("=== REGISTRO MANUAL ===");
error_log("Hora PHP: " . $hora_actual);
error_log("Hora MySQL después de SET: " . $check['hora_mysql']);

$cliente_id = (int)$_POST['cliente_id'] ?? 0;
$tipo = $_POST['tipo'] ?? 'entrada';

if (!$cliente_id) {
    echo json_encode(['success' => false, 'message' => 'Cliente no válido']);
    exit;
}

// Verificar inscripción activa
$stmt = $conn->prepare("
    SELECT i.id, p.nombre as plan_nombre,
           DATEDIFF(i.fecha_fin, CURDATE()) as dias_restantes
    FROM inscripciones i
    INNER JOIN planes p ON i.plan_id = p.id
    WHERE i.cliente_id = ? AND i.estado = 'activa'
    ORDER BY i.fecha_fin ASC
    LIMIT 1
");
$stmt->bind_param("i", $cliente_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'El cliente no tiene una inscripción activa']);
    exit;
}

$inscripcion = $result->fetch_assoc();
$dias_restantes = (int)$inscripcion['dias_restantes'];

if ($dias_restantes < 0) {
    echo json_encode(['success' => false, 'message' => 'La inscripción del cliente ha expirado']);
    exit;
}

if ($tipo == 'salida') {
    // Registrar salida
    $stmt = $conn->prepare("SELECT id FROM asistencias WHERE cliente_id = ? AND fecha = ? AND hora_salida IS NULL");
    $stmt->bind_param("is", $cliente_id, $fecha_hoy);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'No hay una entrada registrada para hoy']);
        exit;
    }
    
    $asistencia = $result->fetch_assoc();
    
    // Usar la hora de PHP directamente (ya está en la zona correcta)
    $stmt = $conn->prepare("UPDATE asistencias SET hora_salida = ? WHERE id = ?");
    $stmt->bind_param("si", $hora_actual, $asistencia['id']);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Salida registrada correctamente a las ' . $hora_actual]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error al registrar salida']);
    }
} else {
    // Registrar entrada
    $stmt = $conn->prepare("SELECT id FROM asistencias WHERE cliente_id = ? AND fecha = ? AND hora_salida IS NULL");
    $stmt->bind_param("is", $cliente_id, $fecha_hoy);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Ya tiene una entrada activa. Registre salida primero']);
        exit;
    }
    
    $stmt = $conn->prepare("SELECT id FROM asistencias WHERE cliente_id = ? AND fecha = ?");
    $stmt->bind_param("is", $cliente_id, $fecha_hoy);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Ya registró entrada y salida hoy']);
        exit;
    }
    
    // Insertar usando hora de PHP (ya está en zona horaria correcta)
    $stmt = $conn->prepare("INSERT INTO asistencias (cliente_id, fecha, hora_entrada, metodo_registro, verificado_por, inscripcion_id, dias_restantes, plan_nombre) VALUES (?, ?, ?, 'manual', ?, ?, ?, ?)");
    $stmt->bind_param("issiiis", $cliente_id, $fecha_hoy, $hora_actual, $usuario_id, $inscripcion['id'], $dias_restantes, $inscripcion['plan_nombre']);
    
    if ($stmt->execute()) {
        // Verificar qué hora se guardó
        $last_id = $conn->insert_id;
        $verify = $conn->query("SELECT hora_entrada FROM asistencias WHERE id = $last_id")->fetch_assoc();
        error_log("Hora guardada en BD: " . $verify['hora_entrada']);
        
        echo json_encode(['success' => true, 'message' => 'Entrada registrada correctamente a las ' . $hora_actual]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error al registrar entrada: ' . $conn->error]);
    }
}
?>