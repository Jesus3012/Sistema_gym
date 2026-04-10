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

$tipo = $_POST['tipo'] ?? 'todos';
$filtro = $_POST['filtro'] ?? '';

$fecha_hoy = date('Y-m-d');

if ($tipo == 'recientes') {
    // Clientes que asistieron hoy
    $query = "
        SELECT DISTINCT c.id, c.nombre, c.apellido, c.telefono,
               i.id as inscripcion_id,
               p.nombre as plan_nombre,
               DATEDIFF(i.fecha_fin, CURDATE()) as dias_restantes,
               CASE WHEN i.id IS NOT NULL AND DATEDIFF(i.fecha_fin, CURDATE()) >= 0 THEN 1 ELSE 0 END as tiene_plan
        FROM asistencias a
        INNER JOIN clientes c ON a.cliente_id = c.id
        LEFT JOIN inscripciones i ON c.id = i.cliente_id AND i.estado = 'activa'
        LEFT JOIN planes p ON i.plan_id = p.id
        WHERE a.fecha = ?
        GROUP BY c.id
        ORDER BY c.nombre ASC
        LIMIT 50
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $fecha_hoy);
    
} elseif ($tipo == 'vencer') {
    // Clientes con planes a vencer en 7 días
    $query = "
        SELECT c.id, c.nombre, c.apellido, c.telefono,
               i.id as inscripcion_id,
               p.nombre as plan_nombre,
               DATEDIFF(i.fecha_fin, CURDATE()) as dias_restantes,
               1 as tiene_plan
        FROM clientes c
        INNER JOIN inscripciones i ON c.id = i.cliente_id AND i.estado = 'activa'
        INNER JOIN planes p ON i.plan_id = p.id
        WHERE DATEDIFF(i.fecha_fin, CURDATE()) BETWEEN 0 AND 7
        ORDER BY dias_restantes ASC
        LIMIT 50
    ";
    $stmt = $conn->prepare($query);
    
} elseif ($tipo == 'buscar' && !empty($filtro)) {
    // Búsqueda por nombre o teléfono
    $termino = "%$filtro%";
    $query = "
        SELECT c.id, c.nombre, c.apellido, c.telefono,
               i.id as inscripcion_id,
               p.nombre as plan_nombre,
               DATEDIFF(i.fecha_fin, CURDATE()) as dias_restantes,
               CASE WHEN i.id IS NOT NULL AND DATEDIFF(i.fecha_fin, CURDATE()) >= 0 THEN 1 ELSE 0 END as tiene_plan
        FROM clientes c
        LEFT JOIN inscripciones i ON c.id = i.cliente_id AND i.estado = 'activa'
        LEFT JOIN planes p ON i.plan_id = p.id
        WHERE c.estado = 'activo' 
        AND (c.nombre LIKE ? OR c.apellido LIKE ? OR c.telefono LIKE ?)
        ORDER BY c.nombre ASC
        LIMIT 30
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sss", $termino, $termino, $termino);
    
} else {
    // Todos los clientes activos
    $query = "
        SELECT c.id, c.nombre, c.apellido, c.telefono,
               i.id as inscripcion_id,
               p.nombre as plan_nombre,
               DATEDIFF(i.fecha_fin, CURDATE()) as dias_restantes,
               CASE WHEN i.id IS NOT NULL AND DATEDIFF(i.fecha_fin, CURDATE()) >= 0 THEN 1 ELSE 0 END as tiene_plan
        FROM clientes c
        LEFT JOIN inscripciones i ON c.id = i.cliente_id AND i.estado = 'activa'
        LEFT JOIN planes p ON i.plan_id = p.id
        WHERE c.estado = 'activo'
        ORDER BY c.nombre ASC
        LIMIT 100
    ";
    $stmt = $conn->prepare($query);
}

$stmt->execute();
$result = $stmt->get_result();

$clientes = [];
while ($row = $result->fetch_assoc()) {
    $clientes[] = [
        'id' => (int)$row['id'],
        'nombre' => $row['nombre'],
        'apellido' => $row['apellido'],
        'telefono' => $row['telefono'],
        'plan_nombre' => $row['plan_nombre'],
        'dias_restantes' => (int)$row['dias_restantes'],
        'tiene_plan' => (bool)$row['tiene_plan']
    ];
}

echo json_encode(['success' => true, 'clientes' => $clientes]);
?>