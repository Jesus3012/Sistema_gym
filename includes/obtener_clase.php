<?php
// includes/obtener_clase.php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $database = new Database();
    $conn = $database->getConnection();
    
    $id = $_POST['id'];
    
    $stmt = $conn->prepare("SELECT * FROM clases WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode([
            'success' => true,
            'id' => $row['id'],
            'nombre' => $row['nombre'],
            'descripcion' => $row['descripcion'],
            'horario' => $row['horario'],
            'instructor' => $row['instructor'],
            'cupo_maximo' => $row['cupo_maximo'],
            'cupo_actual' => $row['cupo_actual'],
            'duracion_minutos' => $row['duracion_minutos'],
            'estado' => $row['estado']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Clase no encontrada']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Solicitud inválida']);
}
?>