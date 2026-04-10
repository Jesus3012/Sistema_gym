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
$conn->query("SET time_zone = '-06:00'");

$fecha_hoy = date('Y-m-d');

// Total asistencias hoy
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM asistencias WHERE fecha = ?");
$stmt->bind_param("s", $fecha_hoy);
$stmt->execute();
$total_asistencias = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

// Clientes activos hoy (con asistencia)
$stmt = $conn->prepare("SELECT COUNT(DISTINCT cliente_id) as total FROM asistencias WHERE fecha = ?");
$stmt->bind_param("s", $fecha_hoy);
$stmt->execute();
$clientes_activos = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

// Asistencias denegadas (intentos fallidos)
$denegadas = 0;
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM asistencias_denegadas WHERE fecha = ?");
if ($stmt) {
    $stmt->bind_param("s", $fecha_hoy);
    $stmt->execute();
    $denegadas = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
}

echo json_encode([
    'success' => true,
    'total_asistencias' => (int)$total_asistencias,
    'clientes_activos' => (int)$clientes_activos,
    'asistencias_denegadas' => (int)$denegadas,
    'fecha' => $fecha_hoy,
    'hora_servidor' => date('H:i:s')
]);
?>