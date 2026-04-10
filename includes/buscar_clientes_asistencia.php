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

$termino = $_POST['termino'] ?? '';

if (strlen($termino) < 2) {
    echo json_encode(['success' => true, 'clientes' => []]);
    exit;
}

$termino = "%$termino%";

$query = "
    SELECT 
        c.id, 
        c.nombre, 
        c.apellido, 
        c.telefono,
        i.id as inscripcion_id,
        i.fecha_fin,
        p.nombre as plan_nombre,
        DATEDIFF(i.fecha_fin, CURDATE()) as dias_restantes,
        CASE 
            WHEN i.id IS NULL THEN 0
            WHEN DATEDIFF(i.fecha_fin, CURDATE()) < 0 THEN 0
            ELSE 1
        END as tiene_plan
    FROM clientes c
    LEFT JOIN inscripciones i ON c.id = i.cliente_id AND i.estado = 'activa'
    LEFT JOIN planes p ON i.plan_id = p.id
    WHERE c.estado = 'activo' 
    AND (c.nombre LIKE ? OR c.apellido LIKE ? OR c.telefono LIKE ?)
    GROUP BY c.id
    ORDER BY c.nombre ASC
    LIMIT 15
";

$stmt = $conn->prepare($query);
$stmt->bind_param("sss", $termino, $termino, $termino);
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