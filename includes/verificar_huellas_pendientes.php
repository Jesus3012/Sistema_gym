<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

// Este archivo simula la lectura de huellas
// En producción, aquí se comunicaría con el hardware real

$last_check = $_POST['last_check'] ?? 0;

// Simular detección de huellas (para pruebas)
// En producción, esto vendría del lector de huellas real
$huellas_simuladas = [];

// Para pruebas, puedes descomentar esto para simular una huella cada 10 segundos
/*
if (rand(1, 20) == 1) {
    $huellas_simuladas[] = 'FP_' . time() . '_test';
}
*/

echo json_encode([
    'success' => true,
    'huellas' => $huellas_simuladas,
    'current_time' => time()
]);
?>