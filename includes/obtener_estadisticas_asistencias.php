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

$fecha_hoy = date('Y-m-d');

// Total asistencias hoy
$query = "SELECT COUNT(*) as total FROM asistencias WHERE fecha = '$fecha_hoy'";
$result = $conn->query($query);
$total_asistencias = $result->fetch_assoc()['total'];

// Clientes activos hoy
$query = "SELECT COUNT(DISTINCT cliente_id) as total FROM asistencias WHERE fecha = '$fecha_hoy'";
$result = $conn->query($query);
$clientes_activos = $result->fetch_assoc()['total'];

// Asistencias denegadas (intentos fallidos por plan vencido)
$query = "SELECT COUNT(*) as total FROM asistencias_denegadas WHERE fecha = '$fecha_hoy'";
$result = $conn->query($query);
$denegadas = $result ? $result->fetch_assoc()['total'] : 0;

echo json_encode([
    'success' => true,
    'total_asistencias' => $total_asistencias,
    'clientes_activos' => $clientes_activos,
    'asistencias_denegadas' => $denegadas
]);
?>