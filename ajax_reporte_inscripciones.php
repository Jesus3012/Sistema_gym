<?php
// ajax_reporte_inscripciones.php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$database = new Database();
$conn = $database->getConnection();

$search = isset($_GET['search']) ? $_GET['search'] : '';
$filtro_plan = isset($_GET['plan']) ? $_GET['plan'] : '';
$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';

// Construir query
$query = "SELECT i.id, i.fecha_inicio, i.fecha_fin, i.precio_pagado, i.estado,
          c.nombre as cliente_nombre, c.apellido as cliente_apellido, c.telefono, c.email,
          p.nombre as plan_nombre, p.duracion_dias
          FROM inscripciones i 
          INNER JOIN clientes c ON i.cliente_id = c.id 
          INNER JOIN planes p ON i.plan_id = p.id 
          WHERE 1=1";

$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (c.nombre LIKE ? OR c.apellido LIKE ? OR c.telefono LIKE ? OR c.email LIKE ? OR p.nombre LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
    $types .= "sssss";
}
if (!empty($filtro_plan)) {
    $query .= " AND p.id = ?";
    $params[] = $filtro_plan;
    $types .= "i";
}
if (!empty($filtro_estado)) {
    $query .= " AND i.estado = ?";
    $params[] = $filtro_estado;
    $types .= "s";
}
if (!empty($fecha_inicio) && !empty($fecha_fin)) {
    $query .= " AND DATE(i.fecha_inicio) BETWEEN ? AND ?";
    $params[] = $fecha_inicio;
    $params[] = $fecha_fin;
    $types .= "ss";
}

$query .= " ORDER BY i.fecha_inicio DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$inscripciones = $result->fetch_all(MYSQLI_ASSOC);

// Calcular días restantes para cada inscripción
foreach ($inscripciones as &$inscripcion) {
    $dias_restantes = 0;
    $texto_dias = '';
    
    // Para planes inactivos o cancelados
    if($inscripcion['estado'] != 'activa') {
        $texto_dias = '-';
        $dias_restantes = -1;
    } 
    // Para plan Visita
    else if($inscripcion['plan_nombre'] == 'Visita') {
        $fecha_fin_obj = new DateTime($inscripcion['fecha_fin']);
        $hoy = new DateTime();
        $hoy->setTime(0, 0, 0);
        $fecha_fin_obj->setTime(0, 0, 0);
        
        if($fecha_fin_obj < $hoy) {
            // Ya expiró (día diferente)
            $texto_dias = 'Vencido';
            $dias_restantes = -1;
        } else if($fecha_fin_obj == $hoy) {
            // Es hoy, aún puede entrar
            $texto_dias = 'Hoy (Válido)';
            $dias_restantes = 1;
        } else {
            $texto_dias = 'Válido';
            $dias_restantes = 1;
        }
    }
    // Para otros planes activos
    else if($inscripcion['estado'] == 'activa' && $inscripcion['fecha_fin'] && $inscripcion['fecha_fin'] != '0000-00-00') {
        $fecha_fin_obj = new DateTime($inscripcion['fecha_fin']);
        $hoy = new DateTime();
        $hoy->setTime(0, 0, 0);
        $fecha_fin_obj->setTime(0, 0, 0);
        
        if($fecha_fin_obj > $hoy) {
            $dias_restantes = $hoy->diff($fecha_fin_obj)->days;
            $texto_dias = $dias_restantes;
        } else if($fecha_fin_obj == $hoy) {
            $texto_dias = 'Vence hoy';
            $dias_restantes = 0;
        } else {
            $texto_dias = 'Vencido';
            $dias_restantes = -1;
        }
    } else {
        $texto_dias = '-';
        $dias_restantes = -1;
    }
    
    // Asignar valores
    $inscripcion['dias_restantes'] = $dias_restantes;
    $inscripcion['texto_dias'] = $texto_dias;
}

echo json_encode([
    'success' => true,
    'datos' => $inscripciones,
    'total' => count($inscripciones)
]);
?>