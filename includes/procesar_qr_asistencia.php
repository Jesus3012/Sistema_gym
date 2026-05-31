<?php
// includes/procesar_qr_asistencia.php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sesión no iniciada']);
    exit;
}

$codigo_qr = $_POST['codigo_qr'] ?? '';

if (empty($codigo_qr)) {
    echo json_encode(['success' => false, 'message' => 'Código QR no proporcionado']);
    exit;
}

$database = new Database();
$conn = $database->getConnection();

// Buscar cliente por código QR
$stmt = $conn->prepare("
    SELECT c.id, c.nombre, c.apellido, 
           i.id as inscripcion_id, i.fecha_inicio, i.fecha_fin, i.estado as inscripcion_estado,
           p.nombre as plan_nombre, p.duracion_dias
    FROM clientes c
    LEFT JOIN inscripciones i ON c.id = i.cliente_id AND i.estado = 'activa'
    LEFT JOIN planes p ON i.plan_id = p.id
    WHERE c.codigo_qr = ? AND c.estado = 'activo'
    ORDER BY i.fecha_inicio DESC
    LIMIT 1
");
$stmt->bind_param("s", $codigo_qr);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Registrar intento denegado
    $stmt_den = $conn->prepare("INSERT INTO asistencias_denegadas (cliente_id, fecha, hora, motivo, metodo) VALUES (NULL, CURDATE(), CURTIME(), 'Código QR no válido', 'qr')");
    $stmt_den->execute();
    echo json_encode(['success' => false, 'message' => 'Código QR no válido']);
    exit;
}

$cliente = $result->fetch_assoc();
$acceso_permitido = false;
$mensaje = "Acceso denegado.";

// Verificar si el cliente tiene inscripción activa
if ($cliente['inscripcion_id']) {
    $hoy = new DateTime();
    $hoy->setTime(0, 0, 0);
    $fecha_fin = new DateTime($cliente['fecha_fin']);
    $fecha_fin->setTime(0, 0, 0);
    
    if ($cliente['inscripcion_estado'] == 'activa') {
        if ($cliente['duracion_dias'] == 1) {
            if ($hoy <= $fecha_fin) {
                $acceso_permitido = true;
                $mensaje = "Acceso permitido. Plan de visita válido por hoy.";
            } else {
                $mensaje = "Plan de visita vencido.";
            }
        } else {
            if ($hoy <= $fecha_fin) {
                $acceso_permitido = true;
                $dias_restantes = $hoy->diff($fecha_fin)->days;
                $mensaje = "Acceso permitido. Días restantes: {$dias_restantes}.";
            } else {
                $mensaje = "Membresía vencida. Por favor, renueve.";
            }
        }
    } else {
        $mensaje = "Inscripción no activa.";
    }
} else {
    $mensaje = "No tiene una membresía activa.";
}

if ($acceso_permitido) {
    $fecha_actual = date('Y-m-d');
    $hora_actual = date('H:i:s');
    
    // Verificar si ya registró entrada hoy
    $check = $conn->prepare("SELECT id, hora_entrada, hora_salida FROM asistencias WHERE cliente_id = ? AND fecha = ?");
    $check->bind_param("is", $cliente['id'], $fecha_actual);
    $check->execute();
    $asistencia_hoy = $check->get_result()->fetch_assoc();
    
    $dias_restantes_calc = (new DateTime())->diff(new DateTime($cliente['fecha_fin']))->days;
    $tipo = '';
    $hora_registro = '';
    
    if (!$asistencia_hoy) {
        // Registrar entrada
        $stmt_asistencia = $conn->prepare("
            INSERT INTO asistencias (cliente_id, fecha, hora_entrada, metodo_registro, inscripcion_id, dias_restantes, plan_nombre) 
            VALUES (?, ?, ?, 'qr', ?, ?, ?)
        ");
        $stmt_asistencia->bind_param("issiis", $cliente['id'], $fecha_actual, $hora_actual, $cliente['inscripcion_id'], $dias_restantes_calc, $cliente['plan_nombre']);
        $stmt_asistencia->execute();
        $tipo = 'entrada';
        $hora_registro = $hora_actual;
    } elseif ($asistencia_hoy && is_null($asistencia_hoy['hora_salida'])) {
        // Registrar salida
        $stmt_asistencia = $conn->prepare("UPDATE asistencias SET hora_salida = ? WHERE id = ?");
        $stmt_asistencia->bind_param("si", $hora_actual, $asistencia_hoy['id']);
        $stmt_asistencia->execute();
        $tipo = 'salida';
        $hora_registro = $hora_actual;
    } else {
        echo json_encode(['success' => false, 'message' => 'Ya registró entrada y salida hoy']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'tipo' => $tipo,
        'cliente_nombre' => $cliente['nombre'] . ' ' . $cliente['apellido'],
        'hora_entrada' => $tipo == 'entrada' ? $hora_registro : null,
        'hora_salida' => $tipo == 'salida' ? $hora_registro : null,
        'mensaje' => $mensaje
    ]);
} else {
    // Registrar intento denegado
    $stmt_den = $conn->prepare("INSERT INTO asistencias_denegadas (cliente_id, fecha, hora, motivo, metodo) VALUES (?, CURDATE(), CURTIME(), ?, 'qr')");
    $stmt_den->bind_param("is", $cliente['id'], $mensaje);
    $stmt_den->execute();
    
    echo json_encode(['success' => false, 'message' => $mensaje]);
}
?>