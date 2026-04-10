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

// Forzar zona horaria
$conn->query("SET time_zone = '-06:00'");

// Obtener hora de PHP
$fecha_hoy = date('Y-m-d');
$hora_actual = date('H:i:s');
$usuario_id = $_SESSION['user_id'];

$huella = $_POST['huella'] ?? '';

if (empty($huella)) {
    echo json_encode(['success' => false, 'message' => 'No se recibió la huella']);
    exit;
}

// Buscar cliente por huella
$stmt = $conn->prepare("SELECT id, nombre, apellido, telefono FROM clientes WHERE huella_digital = ? AND estado = 'activo'");
$stmt->bind_param("s", $huella);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Huella no registrada']);
    exit;
}

$cliente = $result->fetch_assoc();
$cliente_id = $cliente['id'];

// Buscar inscripción activa
$stmt = $conn->prepare("
    SELECT i.id, i.fecha_inicio, i.fecha_fin, i.plan_id, 
           p.nombre as plan_nombre, p.duracion_dias,
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
    echo json_encode(['success' => false, 'message' => 'No tiene una inscripción activa']);
    exit;
}

$inscripcion = $result->fetch_assoc();
$dias_restantes = (int)$inscripcion['dias_restantes'];

if ($dias_restantes < 0) {
    $stmt = $conn->prepare("UPDATE inscripciones SET estado = 'vencida' WHERE id = ?");
    $stmt->bind_param("i", $inscripcion['id']);
    $stmt->execute();
    echo json_encode(['success' => false, 'message' => 'Su plan ha expirado']);
    exit;
}

// Verificar si ya tiene asistencia hoy SIN SALIDA
$stmt = $conn->prepare("SELECT id, hora_entrada FROM asistencias WHERE cliente_id = ? AND fecha = ? AND hora_salida IS NULL");
$stmt->bind_param("is", $cliente_id, $fecha_hoy);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // REGISTRAR SALIDA
    $asistencia = $result->fetch_assoc();
    $stmt = $conn->prepare("UPDATE asistencias SET hora_salida = ? WHERE id = ?");
    $stmt->bind_param("si", $hora_actual, $asistencia['id']);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'cliente_nombre' => $cliente['nombre'] . ' ' . $cliente['apellido'],
            'hora_salida' => $hora_actual,
            'tipo' => 'salida',
            'dias_restantes' => $dias_restantes,
            'plan' => $inscripcion['plan_nombre']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error al registrar salida']);
    }
} else {
    // Verificar si ya registró entrada y salida hoy
    $stmt = $conn->prepare("SELECT id FROM asistencias WHERE cliente_id = ? AND fecha = ?");
    $stmt->bind_param("is", $cliente_id, $fecha_hoy);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Ya registró entrada y salida hoy']);
        exit;
    }
    
    // REGISTRAR ENTRADA
    $stmt = $conn->prepare("INSERT INTO asistencias (cliente_id, fecha, hora_entrada, metodo_registro, verificado_por, inscripcion_id, dias_restantes, plan_nombre) VALUES (?, ?, ?, 'huella', ?, ?, ?, ?)");
    $stmt->bind_param("issiiis", $cliente_id, $fecha_hoy, $hora_actual, $usuario_id, $inscripcion['id'], $dias_restantes, $inscripcion['plan_nombre']);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'cliente_nombre' => $cliente['nombre'] . ' ' . $cliente['apellido'],
            'hora_entrada' => $hora_actual,
            'tipo' => 'entrada',
            'dias_restantes' => $dias_restantes,
            'plan' => $inscripcion['plan_nombre']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error al registrar entrada']);
    }
}
?>