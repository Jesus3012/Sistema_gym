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

$query = "SELECT a.*, c.nombre, c.apellido, c.telefono 
          FROM asistencias a 
          JOIN clientes c ON a.cliente_id = c.id 
          WHERE a.fecha = ? 
          ORDER BY a.hora_entrada DESC 
          LIMIT 50";

$stmt = $conn->prepare($query);
$stmt->bind_param("s", $fecha_hoy);
$stmt->execute();
$result = $stmt->get_result();

$asistencias = [];
while ($row = $result->fetch_assoc()) {
    $asistencias[] = [
        'id' => $row['id'],
        'cliente_id' => $row['cliente_id'],
        'nombre' => $row['nombre'],
        'apellido' => $row['apellido'],
        'telefono' => $row['telefono'],
        'hora_entrada' => date('H:i:s', strtotime($row['hora_entrada'])),
        'hora_salida' => $row['hora_salida'] ? date('H:i:s', strtotime($row['hora_salida'])) : null,
        'metodo_registro' => $row['metodo_registro']
    ];
}

echo json_encode(['success' => true, 'data' => $asistencias]);
?>