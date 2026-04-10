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

$termino = $_POST['termino'] ?? '';

if (strlen($termino) < 2) {
    echo json_encode(['success' => true, 'clientes' => []]);
    exit;
}

$termino = "%$termino%";

$query = "
    SELECT c.id, c.nombre, c.apellido, c.telefono,
           i.id as inscripcion_id, i.fecha_fin, i.estado as inscripcion_estado,
           p.nombre as plan_nombre, p.duracion_dias,
           DATEDIFF(i.fecha_fin, CURDATE()) as dias_restantes
    FROM clientes c
    LEFT JOIN inscripciones i ON c.id = i.cliente_id AND i.estado = 'activa'
    LEFT JOIN planes p ON i.plan_id = p.id
    WHERE c.estado = 'activo' 
    AND (c.nombre LIKE ? OR c.apellido LIKE ? OR c.telefono LIKE ?)
    GROUP BY c.id
    LIMIT 10
";

$stmt = $conn->prepare($query);
$stmt->bind_param("sss", $termino, $termino, $termino);
$stmt->execute();
$result = $stmt->get_result();

$clientes = [];
while ($row = $result->fetch_assoc()) {
    $clientes[] = [
        'id' => $row['id'],
        'nombre' => $row['nombre'],
        'apellido' => $row['apellido'],
        'telefono' => $row['telefono'],
        'plan_nombre' => $row['plan_nombre'],
        'dias_restantes' => $row['dias_restantes'],
        'tiene_plan' => $row['inscripcion_id'] && $row['dias_restantes'] >= 0
    ];
}

echo json_encode(['success' => true, 'clientes' => $clientes]);
?>