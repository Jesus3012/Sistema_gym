<?php
// ajax_inscripciones.php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$database = new Database();
$conn = $database->getConnection();

$search = isset($_GET['search']) ? $_GET['search'] : '';
$estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'cliente';
$order = isset($_GET['order']) ? $_GET['order'] : 'ASC';
$limit = 10;
$offset = ($page - 1) * $limit;

$sort_columns = [
    'cliente' => 'c.nombre',
    'telefono' => 'c.telefono',
    'plan' => 'p.nombre',
    'fecha_inicio' => 'i.fecha_inicio',
    'fecha_fin' => 'i.fecha_fin',
    'precio' => 'i.precio_pagado',
    'estado' => 'i.estado'
];

$order_by = isset($sort_columns[$sort]) ? $sort_columns[$sort] : 'c.nombre';
$order_dir = ($order == 'ASC') ? 'ASC' : 'DESC';

$query = "SELECT i.*, c.id as cliente_id, c.nombre as cliente_nombre, c.apellido as cliente_apellido, c.telefono as cliente_telefono,
          p.nombre as plan_nombre, p.duracion_dias
          FROM inscripciones i 
          INNER JOIN clientes c ON i.cliente_id = c.id 
          INNER JOIN planes p ON i.plan_id = p.id 
          WHERE 1=1";
$count_query = "SELECT COUNT(*) as total FROM inscripciones i 
                INNER JOIN clientes c ON i.cliente_id = c.id 
                INNER JOIN planes p ON i.plan_id = p.id 
                WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (c.nombre LIKE ? OR c.apellido LIKE ? OR c.telefono LIKE ?)";
    $count_query .= " AND (c.nombre LIKE ? OR c.apellido LIKE ? OR c.telefono LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if (!empty($estado)) {
    $query .= " AND i.estado = ?";
    $count_query .= " AND i.estado = ?";
    $params[] = $estado;
    $types .= "s";
}

$query .= " ORDER BY $order_by $order_dir LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$inscripciones = $result->fetch_all(MYSQLI_ASSOC);

// Formatear fechas
foreach ($inscripciones as &$ins) {
    $ins['fecha_inicio_formateada'] = date('d/m/Y', strtotime($ins['fecha_inicio']));
    $ins['fecha_fin_formateada'] = $ins['fecha_fin'] ? date('d/m/Y', strtotime($ins['fecha_fin'])) : null;
}

// Obtener total
$count_params = array_slice($params, 0, count($params) - 2);
$count_types = substr($types, 0, -2);
$stmt_count = $conn->prepare($count_query);
if (!empty($count_params)) {
    $stmt_count->bind_param($count_types, ...$count_params);
}
$stmt_count->execute();
$total_result = $stmt_count->get_result();
$total_rows = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

echo json_encode([
    'inscripciones' => $inscripciones,
    'total_pages' => $total_pages,
    'current_page' => $page,
    'total_rows' => $total_rows
]);
?>