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

$query = "
    SELECT 
        a.id,
        a.cliente_id,
        a.hora_entrada,
        a.hora_salida,
        a.metodo_registro,
        a.dias_restantes,
        a.plan_nombre,
        c.nombre,
        c.apellido,
        c.telefono,
        i.id as inscripcion_id,
        p.nombre as plan_actual
    FROM asistencias a
    INNER JOIN clientes c ON a.cliente_id = c.id
    LEFT JOIN inscripciones i ON c.id = i.cliente_id AND i.estado = 'activa'
    LEFT JOIN planes p ON i.plan_id = p.id
    WHERE a.fecha = ?
    ORDER BY a.hora_entrada DESC
    LIMIT 50
";

$stmt = $conn->prepare($query);
$stmt->bind_param("s", $fecha_hoy);
$stmt->execute();
$result = $stmt->get_result();

$asistencias = [];
while ($row = $result->fetch_assoc()) {
    // Calcular días restantes si no está en la tabla
    $dias_restantes = $row['dias_restantes'];
    if ($dias_restantes === null && $row['inscripcion_id']) {
        // Obtener días restantes de la inscripción actual
        $stmt2 = $conn->prepare("SELECT DATEDIFF(fecha_fin, CURDATE()) as dias FROM inscripciones WHERE id = ?");
        $stmt2->bind_param("i", $row['inscripcion_id']);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        if ($row2 = $res2->fetch_assoc()) {
            $dias_restantes = (int)$row2['dias'];
        }
    }
    
    $asistencias[] = [
        'id' => $row['id'],
        'cliente_id' => $row['cliente_id'],
        'nombre' => $row['nombre'],
        'apellido' => $row['apellido'],
        'telefono' => $row['telefono'] ?? 'N/A',
        'hora_entrada' => date('H:i:s', strtotime($row['hora_entrada'])),
        'hora_salida' => $row['hora_salida'] ? date('H:i:s', strtotime($row['hora_salida'])) : null,
        'metodo_registro' => $row['metodo_registro'],
        'plan_nombre' => $row['plan_nombre'] ?? $row['plan_actual'] ?? 'Sin plan',
        'dias_restantes' => $dias_restantes !== null ? (int)$dias_restantes : null
    ];
}

echo json_encode(['success' => true, 'data' => $asistencias]);
?>