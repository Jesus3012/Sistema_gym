<?php
// includes/obtener_cliente.php
session_start();
require_once '../config/database.php';

$database = new Database();
$conn = $database->getConnection();

if (!isset($_SESSION['user_id']) || !isset($_POST['id'])) {
    echo json_encode(['success' => false]);
    exit;
}

$id = $_POST['id'];

$stmt = $conn->prepare("SELECT CONCAT(nombre, ' ', apellido) as nombre FROM clientes WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$cliente = $result->fetch_assoc();

if ($cliente) {
    echo json_encode(['success' => true, 'nombre' => $cliente['nombre']]);
} else {
    echo json_encode(['success' => false]);
}
?>