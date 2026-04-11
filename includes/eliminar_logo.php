<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Obtener logo actual
    $query = "SELECT logo FROM configuracion_gimnasio WHERE id = 1";
    $result = $conn->query($query);
    $row = $result->fetch_assoc();
    
    if ($row && !empty($row['logo'])) {
        // Eliminar archivo físico
        $ruta_logo = '../' . $row['logo'];
        if (file_exists($ruta_logo)) {
            unlink($ruta_logo);
        }
        
        // Actualizar base de datos
        $query_update = "UPDATE configuracion_gimnasio SET logo = NULL WHERE id = 1";
        if ($conn->query($query_update)) {
            echo json_encode(['success' => true, 'message' => 'Logo eliminado correctamente']);
        } else {
            throw new Exception('Error al actualizar la base de datos');
        }
    } else {
        echo json_encode(['success' => true, 'message' => 'No hay logo para eliminar']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>