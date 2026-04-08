<?php
// inscripciones.php
session_start();
require_once 'config/database.php';

// Crear instancia de la base de datos y obtener la conexión
$database = new Database();
$conn = $database->getConnection();

// Verificar que la conexión existe
if (!$conn) {
    die("Error: No se pudo establecer la conexión a la base de datos");
}

// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Obtener datos del usuario actual
$usuario_id = $_SESSION['user_id'];
$usuario_nombre = $_SESSION['user_name'];
$usuario_rol = $_SESSION['user_rol'];

// Procesar acciones
$mensaje = '';
$error = '';

// ==================== FUNCIONES PARA LECTOR DE HUELLAS ====================
function capturarHuellaDigital() {
    $huella_simulada = 'FP_' . date('YmdHis') . '_' . uniqid();
    
    return [
        'success' => true,
        'huella_data' => $huella_simulada,
        'template' => base64_encode('simulated_fingerprint_template_' . $huella_simulada)
    ];
}

function verificarHuellaDigital($huella_data) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT id, nombre, apellido FROM clientes WHERE huella_digital = ? AND estado = 'activo'");
    $stmt->bind_param("s", $huella_data);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $cliente = $result->fetch_assoc();
        return [
            'success' => true,
            'cliente_id' => $cliente['id'],
            'nombre' => $cliente['nombre'] . ' ' . $cliente['apellido']
        ];
    }
    
    return ['success' => false, 'message' => 'Huella no registrada'];
}
// ==================== FIN FUNCIONES LECTOR HUELLAS ====================

// Crear nuevo cliente e inscripción
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'crear_cliente_inscripcion') {
    // Verificar token CSRF para evitar doble envío
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = 'Token de seguridad inválido. Por favor, intente nuevamente.';
        header('Location: inscripciones.php');
        exit;
    } else {
        try {
            $nombre = trim($_POST['nombre']);
            $apellido = trim($_POST['apellido']);
            $telefono = trim($_POST['telefono']);
            $email = trim($_POST['email']);
            $plan_id = $_POST['plan_id'];
            $fecha_inicio = $_POST['fecha_inicio'];
            $precio_pagado = $_POST['precio_pagado'];
            $metodo_pago = $_POST['metodo_pago'];
            $referencia = $_POST['referencia'] ?? null;
            $huella_digital = $_POST['huella_digital'] ?? null;
            
            if (empty($nombre) || empty($apellido) || empty($telefono) || empty($plan_id)) {
                throw new Exception('Por favor complete todos los campos requeridos');
            }
            
            $stmt = $conn->prepare("SELECT id FROM clientes WHERE telefono = ? OR (email = ? AND email != '')");
            $stmt->bind_param("ss", $telefono, $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                throw new Exception('Ya existe un cliente con ese teléfono o email');
            }
            
            $stmt = $conn->prepare("SELECT duracion_dias, precio, nombre as plan_nombre FROM planes WHERE id = ? AND estado = 'activo'");
            $stmt->bind_param("i", $plan_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $plan = $result->fetch_assoc();
            
            if (!$plan) {
                throw new Exception('Plan no válido');
            }
            
            // Calcular fecha fin según el plan
            if ($plan['duracion_dias'] > 0) {
                // Para plan Visita (duración 1 día), la fecha fin es la misma fecha de inicio (solo dura el día actual)
                if ($plan['plan_nombre'] == 'Visita' || $plan['duracion_dias'] == 1) {
                    $fecha_fin = $fecha_inicio; // Misma fecha de inicio
                } else {
                    $fecha_fin = date('Y-m-d', strtotime($fecha_inicio . ' + ' . $plan['duracion_dias'] . ' days'));
                }
            } else {
                $fecha_fin = null;
            }
            
            $conn->begin_transaction();
            
            // Insertar cliente
            $stmt = $conn->prepare("INSERT INTO clientes (nombre, apellido, telefono, email, huella_digital, estado) VALUES (?, ?, ?, ?, ?, 'activo')");
            $stmt->bind_param("sssss", $nombre, $apellido, $telefono, $email, $huella_digital);
            $stmt->execute();
            $cliente_id = $conn->insert_id;
            
            // Insertar inscripción
            $fecha_actual_db = date('Y-m-d');
            $stmt = $conn->prepare("INSERT INTO inscripciones (cliente_id, plan_id, fecha_inicio, fecha_fin, precio_pagado, estado) VALUES (?, ?, ?, ?, ?, 'activa')");
            $stmt->bind_param("iisss", $cliente_id, $plan_id, $fecha_inicio, $fecha_fin, $precio_pagado);
            $stmt->execute();
            $inscripcion_id = $conn->insert_id;
            
            // Insertar pago
            $stmt = $conn->prepare("INSERT INTO pagos (inscripcion_id, cliente_id, monto, fecha_pago, metodo_pago, referencia, estado) VALUES (?, ?, ?, ?, ?, ?, 'completado')");
            $stmt->bind_param("iidsss", $inscripcion_id, $cliente_id, $precio_pagado, $fecha_actual_db, $metodo_pago, $referencia);
            $stmt->execute();
            
            // Registrar en historial_pagos
            $stmt = $conn->prepare("INSERT INTO historial_pagos (inscripcion_id, cliente_id, monto, fecha_pago, metodo_pago, referencia, periodo_inicio, periodo_fin, plan_nombre, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iidssssssi", $inscripcion_id, $cliente_id, $precio_pagado, $fecha_actual_db, $metodo_pago, $referencia, $fecha_inicio, $fecha_fin, $plan['plan_nombre'], $usuario_id);
            $stmt->execute();
            
            $conn->commit();

            // Obtener el email del cliente recién creado
            $email_cliente = $email;

            // Enviar correo solo si el cliente proporcionó un email
            if (!empty($email_cliente)) {
                require_once 'includes/enviar_correo_phpmailer.php';
                $nombre_completo = $nombre . ' ' . $apellido;
                $envio_correo = enviarTicketInscripcion(
                    $email_cliente,
                    $nombre_completo,
                    $plan['plan_nombre'],
                    $fecha_inicio,
                    $fecha_fin,
                    $precio_pagado,
                    $metodo_pago,
                    $referencia
                );
                
                if (!$envio_correo) {
                    error_log("Error al enviar correo a: " . $email_cliente);
                }
            }

            // Limpiar token después de uso exitoso
            unset($_SESSION['csrf_token']);

            // Guardar mensaje en sesión
            if (!empty($email_cliente) && $envio_correo) {
                $_SESSION['mensaje_exito'] = 'Cliente e inscripción creados exitosamente. Se ha enviado un ticket a su correo electrónico.';
            } elseif (!empty($email_cliente) && !$envio_correo) {
                $_SESSION['mensaje_exito'] = 'Cliente e inscripción creados exitosamente. No se pudo enviar el correo electrónico.';
            } else {
                $_SESSION['mensaje_exito'] = 'Cliente e inscripción creados exitosamente. No se envió correo porque no proporcionó email.';
            }

            header('Location: inscripciones.php');
            exit;
            
        } catch (Exception $e) {
            if (isset($conn)) $conn->rollback();
            $_SESSION['error'] = $e->getMessage();
            header('Location: inscripciones.php');
            exit;
        }
    }
}

// Generar token CSRF para el formulario
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Renovar inscripción
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'renovar_inscripcion') {
    try {
        $inscripcion_id = $_POST['inscripcion_id'];
        $cliente_id = $_POST['cliente_id'];
        $plan_id = $_POST['plan_id'];
        $fecha_inicio = $_POST['fecha_inicio'];
        $precio_pagado = $_POST['precio_pagado'];
        $metodo_pago = $_POST['metodo_pago'];
        $referencia = $_POST['referencia'] ?? null;
        
        // Verificar si ya se procesó esta renovación
        if (isset($_SESSION['last_renewal_' . $inscripcion_id]) && 
            $_SESSION['last_renewal_' . $inscripcion_id] > time() - 5) {
            throw new Exception('Ya se está procesando una renovación para esta inscripción');
        }
        $_SESSION['last_renewal_' . $inscripcion_id] = time();
        
        // Validar que la fecha de inicio no sea anterior a hoy
        if (strtotime($fecha_inicio) < strtotime(date('Y-m-d'))) {
            throw new Exception('La fecha de inicio no puede ser anterior a hoy');
        }
        
        // Obtener datos del plan seleccionado
        $stmt = $conn->prepare("SELECT duracion_dias, precio, nombre as plan_nombre FROM planes WHERE id = ? AND estado = 'activo'");
        $stmt->bind_param("i", $plan_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $plan = $result->fetch_assoc();
        
        if (!$plan) {
            throw new Exception('Plan no válido');
        }
        
        // Calcular fecha fin según el plan
        if ($plan['duracion_dias'] > 0) {
            // Para plan Visita (duración 1 día), la fecha fin es la misma fecha de inicio
            if ($plan['plan_nombre'] == 'Visita' || $plan['duracion_dias'] == 1) {
                $fecha_fin = $fecha_inicio; // Misma fecha de inicio (solo dura el día actual)
            } else {
                $fecha_fin = date('Y-m-d', strtotime($fecha_inicio . ' + ' . $plan['duracion_dias'] . ' days'));
            }
        } else {
            $fecha_fin = null;
        }
        
        $conn->begin_transaction();
        
        // ACTUALIZAR la inscripción existente con el NUEVO PLAN
        $stmt = $conn->prepare("UPDATE inscripciones SET plan_id = ?, fecha_inicio = ?, fecha_fin = ?, precio_pagado = ?, estado = 'activa' WHERE id = ?");
        $stmt->bind_param("isssi", $plan_id, $fecha_inicio, $fecha_fin, $precio_pagado, $inscripcion_id);
        $stmt->execute();
        
        // Registrar el pago en la tabla pagos
        $fecha_actual = date('Y-m-d');
        $stmt = $conn->prepare("INSERT INTO pagos (inscripcion_id, cliente_id, monto, fecha_pago, metodo_pago, referencia, estado) VALUES (?, ?, ?, ?, ?, ?, 'completado')");
        $stmt->bind_param("iidsss", $inscripcion_id, $cliente_id, $precio_pagado, $fecha_actual, $metodo_pago, $referencia);
        $stmt->execute();

        // Registrar en historial_pagos
        $stmt = $conn->prepare("INSERT INTO historial_pagos (inscripcion_id, cliente_id, monto, fecha_pago, metodo_pago, referencia, periodo_inicio, periodo_fin, plan_nombre, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iidssssssi", $inscripcion_id, $cliente_id, $precio_pagado, $fecha_actual, $metodo_pago, $referencia, $fecha_inicio, $fecha_fin, $plan['plan_nombre'], $usuario_id);
        $stmt->execute();
        
        $conn->commit();

        // Obtener el email del cliente
        $stmt_email = $conn->prepare("SELECT email, nombre, apellido FROM clientes WHERE id = ?");
        $stmt_email->bind_param("i", $cliente_id);
        $stmt_email->execute();
        $result_email = $stmt_email->get_result();
        $cliente_data = $result_email->fetch_assoc();
        $email_cliente = $cliente_data['email'];
        $nombre_completo = $cliente_data['nombre'] . ' ' . $cliente_data['apellido'];

        // Enviar correo solo si el cliente tiene email
        $envio_correo = false;
        if (!empty($email_cliente)) {
            require_once 'includes/enviar_correo_phpmailer.php';
            $envio_correo = enviarTicketRenovacion(
                $email_cliente,
                $nombre_completo,
                $plan['plan_nombre'],
                $fecha_inicio,
                $fecha_fin,
                $precio_pagado,
                $metodo_pago,
                $referencia
            );
            
            if (!$envio_correo) {
                error_log("Error al enviar correo de renovación a: " . $email_cliente);
            }
        }

        // Limpiar la marca de tiempo después de procesar
        unset($_SESSION['last_renewal_' . $inscripcion_id]);

        // Guardar mensaje en sesión
        if (!empty($email_cliente) && $envio_correo) {
            $_SESSION['mensaje_exito'] = 'Inscripción renovada exitosamente con el plan ' . $plan['plan_nombre'] . '. Se ha enviado un ticket a su correo electrónico.';
        } elseif (!empty($email_cliente) && !$envio_correo) {
            $_SESSION['mensaje_exito'] = 'Inscripción renovada exitosamente con el plan ' . $plan['plan_nombre'] . '. No se pudo enviar el correo electrónico.';
        } else {
            $_SESSION['mensaje_exito'] = 'Inscripción renovada exitosamente con el plan ' . $plan['plan_nombre'] . '. No se envió correo porque el cliente no tiene email registrado.';
        }

        header('Location: inscripciones.php');
        exit;
        
    } catch (Exception $e) {
        if (isset($conn)) $conn->rollback();
        if (isset($inscripcion_id)) {
            unset($_SESSION['last_renewal_' . $inscripcion_id]);
        }
        $_SESSION['error'] = $e->getMessage();
        header('Location: inscripciones.php');
        exit;
    }
}

// Cancelar inscripción
if (isset($_GET['cancelar']) && is_numeric($_GET['cancelar'])) {
    $id = $_GET['cancelar'];
    
    // Verificar si ya se procesó esta cancelación
    if (isset($_SESSION['last_cancel_' . $id]) && $_SESSION['last_cancel_' . $id] > time() - 5) {
        $_SESSION['error'] = 'Ya se está procesando esta cancelación';
        header('Location: inscripciones.php');
        exit;
    }
    $_SESSION['last_cancel_' . $id] = time();
    
    try {
        // Obtener el cliente_id antes de cancelar
        $stmt = $conn->prepare("SELECT cliente_id FROM inscripciones WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $inscripcion = $result->fetch_assoc();
        $cliente_id = $inscripcion['cliente_id'];
        
        $stmt = $conn->prepare("UPDATE inscripciones SET estado = 'cancelada' WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        // Registrar cancelación en historial_pagos
        $stmt = $conn->prepare("INSERT INTO historial_pagos (inscripcion_id, cliente_id, monto, fecha_pago, metodo_pago, referencia, periodo_inicio, periodo_fin, plan_nombre, usuario_id) VALUES (?, ?, 0, NOW(), NULL, NULL, NULL, NULL, 'CANCELACION', ?)");
        $stmt->bind_param("iii", $id, $cliente_id, $usuario_id);
        $stmt->execute();
        
        $_SESSION['mensaje_exito'] = 'Inscripción cancelada exitosamente';
        
        unset($_SESSION['last_cancel_' . $id]);
        
        header('Location: inscripciones.php');
        exit;
        
    } catch (Exception $e) {
        unset($_SESSION['last_cancel_' . $id]);
        $_SESSION['error'] = 'Error al cancelar la inscripción: ' . $e->getMessage();
        header('Location: inscripciones.php');
        exit;
    }
}

// Actualizar estados de inscripciones vencidas
$update_vencidas = "UPDATE inscripciones i 
                    INNER JOIN planes p ON i.plan_id = p.id 
                    SET i.estado = 'vencida' 
                    WHERE i.estado = 'activa' 
                    AND i.fecha_fin IS NOT NULL 
                    AND i.fecha_fin < CURDATE()";
$conn->query($update_vencidas);

// Obtener listado de inscripciones
$search = isset($_GET['search']) ? $_GET['search'] : '';
$estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'id';
$order = isset($_GET['order']) ? $_GET['order'] : 'DESC';
$limit = 10;
$offset = ($page - 1) * $limit;

// Mapeo de columnas para ordenamiento
$sort_columns = [
    'cliente' => 'c.nombre',
    'telefono' => 'c.telefono',
    'plan' => 'p.nombre',
    'fecha_inicio' => 'i.fecha_inicio',
    'fecha_fin' => 'i.fecha_fin',
    'precio' => 'i.precio_pagado',
    'estado' => 'i.estado'
];

$order_by = isset($sort_columns[$sort]) ? $sort_columns[$sort] : 'i.id';
$order_dir = ($order == 'ASC') ? 'ASC' : 'DESC';

$query = "SELECT i.*, c.nombre as cliente_nombre, c.apellido as cliente_apellido, c.telefono as cliente_telefono,
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
    $bind_params = array_values($params);
    $stmt->bind_param($types, ...$bind_params);
}
$stmt->execute();
$result = $stmt->get_result();
$inscripciones = $result->fetch_all(MYSQLI_ASSOC);

// Obtener total de registros para paginación
$count_params = array_slice($params, 0, count($params) - 2);
$count_types = substr($types, 0, -2);
$stmt_count = $conn->prepare($count_query);
if (!empty($count_params)) {
    $bind_count_params = array_values($count_params);
    $stmt_count->bind_param($count_types, ...$bind_count_params);
}
$stmt_count->execute();
$total_result = $stmt_count->get_result();
$total_rows = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// Obtener planes activos
$result = $conn->query("SELECT * FROM planes WHERE estado = 'activo' ORDER BY duracion_dias ASC");
$planes = $result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscripciones - Sistema Gimnasio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #f4f6f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100vh;
            background: #1e3a8a;
            color: white;
            z-index: 1000;
            overflow-y: auto;
        }
        
        .main-content {
            margin-left: 260px;
            padding: 20px;
            min-height: 100vh;
        }
        
        .card-custom {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .card-header-custom {
            background: #1e3a8a;
            color: white;
            padding: 12px 20px;
            border-radius: 8px 8px 0 0;
            font-weight: 600;
        }
        
        .card-body-custom {
            padding: 20px;
        }
        
        .tabla-simple {
            width: 100%;
            background: white;
            border-collapse: collapse;
        }
        
        .tabla-simple th,
        .tabla-simple td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
            vertical-align: middle;
        }
        
        .tabla-simple th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
            font-size: 14px;
            cursor: pointer;
            user-select: none;
        }
        
        .tabla-simple th:hover {
            background: #e9ecef;
        }
        
        .tabla-simple th i {
            margin-left: 5px;
            font-size: 12px;
        }
        
        .tabla-simple tr:hover {
            background: #f8f9fa;
        }
        
        .acciones-container {
            display: flex;
            gap: 8px;
            flex-wrap: nowrap;
            align-items: center;
        }
        
        .btn-accion {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        
        .btn-accion i {
            font-size: 12px;
        }
        
        .btn-accion:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .btn-detalle {
            background: #3b82f6;
            color: white;
        }
        
        .btn-detalle:hover {
            background: #2563eb;
        }
        
        .btn-renovar {
            background: #10b981;
            color: white;
        }
        
        .btn-renovar:hover {
            background: #059669;
        }
        
        .btn-renovar:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-cancelar {
            background: #dc2626;
            color: white;
        }
        
        .btn-cancelar:hover {
            background: #b91c1c;
        }
        
        .badge-activa {
            background: #10b981;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .badge-vencida {
            background: #ef4444;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .badge-cancelada {
            background: #6b7280;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .badge-visita {
            background: #f59e0b;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .btn-custom-primary {
            background: #1e3a8a;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
        }
        
        .btn-custom-primary:hover {
            background: #152c6b;
        }
        
        .fingerprint-area {
            border: 2px dashed #3b82f6;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            background: #f8f9fa;
        }
        
        .fingerprint-area:hover {
            background: #e8f0fe;
        }
        
        .pagination {
            margin-top: 20px;
            justify-content: center;
        }
        
        .page-link {
            color: #1e3a8a;
            cursor: pointer;
        }
        
        .page-item.active .page-link {
            background-color: #1e3a8a;
            border-color: #1e3a8a;
        }
        
        .precio-disabled {
            background-color: #e9ecef;
            cursor: not-allowed;
        }
        
        .loading {
            text-align: center;
            padding: 20px;
        }
        
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #1e3a8a;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .info-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            padding: 20px;
            color: white;
            margin-bottom: 20px;
        }
        
        .info-box h6 {
            margin-bottom: 15px;
            font-weight: 600;
            border-bottom: 2px solid rgba(255,255,255,0.3);
            padding-bottom: 8px;
        }
        
        .info-box p {
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .info-box .badge {
            font-size: 12px;
            padding: 5px 10px;
        }
        
        .historial-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .historial-card h6 {
            color: #333;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .table-historial {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-historial th,
        .table-historial td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .table-historial th {
            background: #f8f9fa;
            font-weight: 600;
            color: #555;
            cursor: pointer;
        }
        
        .table-historial th:hover {
            background: #e9ecef;
        }
        
        .table-historial tr:hover {
            background: #f8f9fa;
        }
        
        .search-box {
            margin-bottom: 20px;
        }
        
        .total-paid {
            background: #10b981;
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            text-align: right;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .tabla-simple th,
            .tabla-simple td {
                padding: 8px;
                font-size: 12px;
            }
            
            .btn-accion {
                padding: 4px 8px;
                font-size: 10px;
            }
            
            .btn-accion span {
                display: none;
            }
            
            .btn-accion i {
                font-size: 14px;
                margin: 0;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="mb-4">
            <h2>Gestión de Inscripciones</h2>
        </div>
        
        <?php if(isset($_SESSION['mensaje_exito'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php 
            echo $_SESSION['mensaje_exito'];
            unset($_SESSION['mensaje_exito']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php 
            echo $_SESSION['error'];
            unset($_SESSION['error']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
                
        <div class="mb-3">
            <button class="btn-custom-primary" data-bs-toggle="modal" data-bs-target="#modalNuevoCliente">
                <i class="fas fa-user-plus"></i> Nuevo Cliente + Inscripción
            </button>
        </div>
        
        <div class="card-custom">
            <div class="card-header-custom">
                <i class="fas fa-filter"></i> Filtros de Búsqueda
            </div>
            <div class="card-body-custom">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Buscar</label>
                        <input type="text" class="form-control" id="searchInput" placeholder="Nombre, apellido o teléfono..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Estado</label>
                        <select class="form-select" id="estadoSelect">
                            <option value="">Todos</option>
                            <option value="activa" <?php echo $estado == 'activa' ? 'selected' : ''; ?>>Activa</option>
                            <option value="vencida" <?php echo $estado == 'vencida' ? 'selected' : ''; ?>>Vencida</option>
                            <option value="cancelada" <?php echo $estado == 'cancelada' ? 'selected' : ''; ?>>Cancelada</option>
                        </select>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label">&nbsp;</label>
                        <button class="btn btn-secondary w-100" id="limpiarFiltros">
                            <i class="fas fa-eraser"></i> Limpiar
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card-custom">
            <div class="card-header-custom">
                <i class="fas fa-list"></i> Listado de Inscripciones
            </div>
            <div class="card-body-custom" style="padding: 0;">
                <div style="overflow-x: auto;">
                    <table class="tabla-simple">
                        <thead>
                            <tr>
                                <th><a href="?sort=cliente&order=<?php echo ($sort == 'cliente' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Cliente <i class="fas fa-sort"></i></a></th>
                                <th><a href="?sort=telefono&order=<?php echo ($sort == 'telefono' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Teléfono <i class="fas fa-sort"></i></a></th>
                                <th><a href="?sort=plan&order=<?php echo ($sort == 'plan' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Plan <i class="fas fa-sort"></i></a></th>
                                <th><a href="?sort=fecha_inicio&order=<?php echo ($sort == 'fecha_inicio' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Fecha Inicio <i class="fas fa-sort"></i></a></th>
                                <th><a href="?sort=fecha_fin&order=<?php echo ($sort == 'fecha_fin' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Fecha Fin <i class="fas fa-sort"></i></a></th>
                                <th><a href="?sort=precio&order=<?php echo ($sort == 'precio' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">$ Precio <i class="fas fa-sort"></i></a></th>
                                <th><a href="?sort=estado&order=<?php echo ($sort == 'estado' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Estado <i class="fas fa-sort"></i></a></th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($inscripciones as $ins): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($ins['cliente_nombre'] . ' ' . $ins['cliente_apellido']); ?></strong></td>
                                <td><?php echo htmlspecialchars($ins['cliente_telefono']); ?></td>
                                <td>
                                    <?php if($ins['plan_nombre'] == 'Visita'): ?>
                                        <span class="badge-visita"><?php echo htmlspecialchars($ins['plan_nombre']); ?></span>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($ins['plan_nombre']); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d/m/Y', strtotime($ins['fecha_inicio'])); ?></td>
                                <td>
                                    <?php 
                                    if($ins['plan_nombre'] == 'Visita') {
                                        echo '<span class="text-warning">' . date('d/m/Y', strtotime($ins['fecha_fin'])) . ' (Solo hoy)</span>';
                                    } else {
                                        echo $ins['duracion_dias'] > 0 ? date('d/m/Y', strtotime($ins['fecha_fin'])) : 'Sin vencimiento';
                                    }
                                    ?>
                                </td>
                                <td>$<?php echo number_format($ins['precio_pagado'], 2); ?></td>
                                <td>
                                    <?php if($ins['estado'] == 'activa'): ?>
                                        <span class="badge-activa">Activa</span>
                                    <?php elseif($ins['estado'] == 'vencida'): ?>
                                        <span class="badge-vencida">Vencida</span>
                                    <?php else: ?>
                                        <span class="badge-cancelada">Cancelada</span>
                                    <?php endif; ?>
                                </td>
                                <td class="acciones-cell">
                                    <div class="acciones-container">
                                        <button class="btn-accion btn-detalle" onclick="verDetalle(<?php echo $ins['id']; ?>)" title="Ver detalles completos">
                                            <i class="fas fa-eye"></i> <span>Ver</span>
                                        </button>
                                        
                                        <?php if($ins['estado'] == 'activa'): ?>
                                            <button class="btn-accion btn-renovar" onclick="abrirRenovar(<?php echo $ins['id']; ?>, <?php echo $ins['cliente_id']; ?>)" title="Renovar inscripción">
                                                <i class="fas fa-sync-alt"></i> <span>Renovar</span>
                                            </button>
                                            <button class="btn-accion btn-cancelar" onclick="cancelarInscripcion(<?php echo $ins['id']; ?>)" title="Cancelar inscripción">
                                                <i class="fas fa-times-circle"></i> <span>Cancelar</span>
                                            </button>
                                        <?php elseif($ins['estado'] == 'vencida'): ?>
                                            <button class="btn-accion btn-renovar" onclick="abrirRenovar(<?php echo $ins['id']; ?>, <?php echo $ins['cliente_id']; ?>)" title="Renovar inscripción vencida">
                                                <i class="fas fa-sync-alt"></i> <span>Renovar</span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($inscripciones)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px;">
                                    <i class="fas fa-inbox" style="font-size: 48px; color: #ccc;"></i>
                                    <p class="mt-2">No hay inscripciones registradas</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if($total_pages > 1): ?>
                <div class="pagination">
                    <ul class="pagination">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>&sort=<?php echo $sort; ?>&order=<?php echo $order; ?>">Anterior</a>
                        </li>
                        <?php for($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>&sort=<?php echo $sort; ?>&order=<?php echo $order; ?>"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>&sort=<?php echo $sort; ?>&order=<?php echo $order; ?>">Siguiente</a>
                        </li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Nuevo Cliente -->
    <div class="modal fade" id="modalNuevoCliente" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #1e3a8a; color: white;">
                    <h5 class="modal-title"><i class="fas fa-user-plus"></i> Nuevo Cliente e Inscripción</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="formNuevoCliente" method="POST">
                    <input type="hidden" name="action" value="crear_cliente_inscripcion">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="huella_digital" id="huella_digital" value="">
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nombre *</label>
                                <input type="text" class="form-control" name="nombre" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Apellido *</label>
                                <input type="text" class="form-control" name="apellido" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Teléfono *</label>
                                <input type="tel" class="form-control" name="telefono" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Plan *</label>
                                <select class="form-select" name="plan_id" id="plan_id_nuevo" required onchange="actualizarPrecioNuevo()">
                                    <option value="">Seleccionar plan</option>
                                    <?php foreach($planes as $plan): ?>
                                    <option value="<?php echo $plan['id']; ?>" data-precio="<?php echo $plan['precio']; ?>">
                                        <?php echo htmlspecialchars($plan['nombre'] . ' - $' . number_format($plan['precio'], 2)); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fecha Inicio *</label>
                                <input type="date" class="form-control" name="fecha_inicio" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Precio Pagado *</label>
                                <input type="number" class="form-control precio-disabled" name="precio_pagado" id="precio_pagado_nuevo" step="0.01" readonly required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Método Pago *</label>
                                <select class="form-select" name="metodo_pago" required>
                                    <option value="efectivo">Efectivo</option>
                                    <option value="tarjeta">Tarjeta</option>
                                    <option value="transferencia">Transferencia</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Referencia</label>
                            <input type="text" class="form-control" name="referencia" placeholder="Número de referencia (opcional)">
                        </div>
                        
                        <div class="fingerprint-area" onclick="capturarHuella()">
                            <i class="fas fa-fingerprint" style="font-size: 48px; color: #1e3a8a;"></i>
                            <div class="mt-2">Capturar Huella Digital</div>
                            <div class="small text-muted" id="huellaStatus">No registrada</div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Renovar -->
    <div class="modal fade" id="modalRenovar" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: #1e3a8a; color: white;">
                    <h5 class="modal-title"><i class="fas fa-sync-alt"></i> Renovar Inscripción</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="formRenovar" method="POST">
                    <input type="hidden" name="action" value="renovar_inscripcion">
                    <input type="hidden" name="inscripcion_id" id="renovar_inscripcion_id">
                    <input type="hidden" name="cliente_id" id="renovar_cliente_id">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Cliente</label>
                            <input type="text" class="form-control" id="renovar_cliente_nombre" readonly disabled>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Plan *</label>
                            <select class="form-select" name="plan_id" id="renovar_plan_id" required onchange="actualizarPrecioRenovar()">
                                <option value="">Seleccionar plan</option>
                                <?php foreach($planes as $plan): ?>
                                <option value="<?php echo $plan['id']; ?>" data-precio="<?php echo $plan['precio']; ?>">
                                    <?php echo htmlspecialchars($plan['nombre'] . ' - $' . number_format($plan['precio'], 2)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Fecha Inicio *</label>
                            <input type="date" class="form-control" name="fecha_inicio" id="renovar_fecha_inicio" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Precio Pagado *</label>
                            <input type="number" class="form-control" name="precio_pagado" id="renovar_precio_pagado" step="0.01" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Método Pago *</label>
                            <select class="form-select" name="metodo_pago" required>
                                <option value="efectivo">Efectivo</option>
                                <option value="tarjeta">Tarjeta</option>
                                <option value="transferencia">Transferencia</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Referencia</label>
                            <input type="text" class="form-control" name="referencia" placeholder="Número de referencia (opcional)">
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Renovar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Detalle -->
    <div class="modal fade" id="modalDetalle" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: #1e3a8a; color: white;">
                    <h5 class="modal-title"><i class="fas fa-info-circle"></i> Detalle de Inscripción e Historial de Pagos</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalleContenido">
                    <div class="text-center">
                        <div class="spinner-border text-primary"></div>
                        <p>Cargando...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        function actualizarPrecioNuevo() {
            const planSelect = document.getElementById('plan_id_nuevo');
            const selectedOption = planSelect.options[planSelect.selectedIndex];
            const precio = selectedOption.getAttribute('data-precio');
            if (precio) {
                document.getElementById('precio_pagado_nuevo').value = precio;
            }
        }
        
        function actualizarPrecioRenovar() {
            const planSelect = document.getElementById('renovar_plan_id');
            const selectedOption = planSelect.options[planSelect.selectedIndex];
            const precio = selectedOption.getAttribute('data-precio');
            if (precio) {
                document.getElementById('renovar_precio_pagado').value = precio;
            }
        }
        
        function capturarHuella() {
            Swal.fire({
                title: 'Capturando huella',
                text: 'Coloque su dedo en el lector...',
                icon: 'info',
                showConfirmButton: false,
                didOpen: () => Swal.showLoading()
            });
            
            setTimeout(() => {
                Swal.close();
                const huellaData = 'FP_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                document.getElementById('huella_digital').value = huellaData;
                document.getElementById('huellaStatus').innerHTML = '<i class="fas fa-check-circle text-success"></i> Huella registrada';
                Swal.fire('Éxito', 'Huella registrada correctamente', 'success');
            }, 2000);
        }
        
        function abrirRenovar(inscripcionId, clienteId) {
            $.ajax({
                url: 'includes/obtener_cliente.php',
                method: 'POST',
                data: { id: clienteId },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        document.getElementById('renovar_inscripcion_id').value = inscripcionId;
                        document.getElementById('renovar_cliente_id').value = clienteId;
                        document.getElementById('renovar_cliente_nombre').value = data.nombre;
                        document.getElementById('renovar_fecha_inicio').value = new Date().toISOString().split('T')[0];
                        $('#modalRenovar').modal('show');
                    } else {
                        Swal.fire('Error', 'No se pudo obtener los datos del cliente', 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Error al cargar los datos del cliente', 'error');
                }
            });
        }
        
        let currentPageHistorial = 1;
        let currentSortHistorial = 'fecha_pago';
        let currentOrderHistorial = 'DESC';
        let currentSearchHistorial = '';
        
        function verDetalle(id) {
            currentPageHistorial = 1;
            currentSortHistorial = 'fecha_pago';
            currentOrderHistorial = 'DESC';
            currentSearchHistorial = '';
            
            $('#modalDetalle').modal('show');
            $('#detalleContenido').html('<div class="text-center"><div class="spinner-border text-primary"></div><p>Cargando...</p></div>');
            
            $.ajax({
                url: 'includes/inscripcion_detalle.php',
                method: 'POST',
                data: { id: id },
                success: function(response) {
                    $('#detalleContenido').html(response);
                    inicializarEventosHistorial(id);
                },
                error: function() {
                    $('#detalleContenido').html('<div class="alert alert-danger">Error al cargar los detalles</div>');
                }
            });
        }

        function inicializarEventosHistorial(id) {
            window.currentPageHistorial = 1;
            window.currentSortHistorial = 'fecha_pago';
            window.currentOrderHistorial = 'DESC';
            window.currentSearchHistorial = '';
            window.inscripcionIdActual = id;
            window.timeoutHistorial = null;
            
            window.cargarHistorialPagos = function() {
                $('#tablaHistorialBody').html('<tr><td colspan="6" class="text-center"><div class="spinner-border text-primary"></div><p class="mt-2">Cargando...</p></td></tr>');
                
                $.ajax({
                    url: 'includes/inscripcion_detalle_historial.php',
                    method: 'POST',
                    data: {
                        id: window.inscripcionIdActual,
                        page: window.currentPageHistorial,
                        sort: window.currentSortHistorial,
                        order: window.currentOrderHistorial,
                        search: window.currentSearchHistorial
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.error) {
                            $('#tablaHistorialBody').html('<tr><td colspan="6" class="text-center text-danger">' + response.error + '</td></tr>');
                            return;
                        }
                        $('#tablaHistorialBody').html(response.tbody);
                        $('#paginacionHistorial').html(response.pagination);
                        $('#totalPagadoSpan').html('$' + response.total_pagado);
                    },
                    error: function() {
                        $('#tablaHistorialBody').html('<tr><td colspan="6" class="text-center text-danger">Error al cargar los datos</td></tr>');
                    }
                });
            };
            
            window.buscarHistorial = function() {
                window.currentSearchHistorial = $('#searchHistorialInput').val();
                window.currentPageHistorial = 1;
                window.cargarHistorialPagos();
            };
            
            window.ordenarHistorial = function(columna) {
                if (window.currentSortHistorial === columna) {
                    window.currentOrderHistorial = window.currentOrderHistorial === 'ASC' ? 'DESC' : 'ASC';
                } else {
                    window.currentSortHistorial = columna;
                    window.currentOrderHistorial = 'ASC';
                }
                window.currentPageHistorial = 1;
                window.cargarHistorialPagos();
            };
            
            window.cambiarPaginaHistorial = function(page) {
                window.currentPageHistorial = page;
                window.cargarHistorialPagos();
            };
            
            const searchInput = document.getElementById('searchHistorialInput');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    clearTimeout(window.timeoutHistorial);
                    window.timeoutHistorial = setTimeout(function() {
                        window.buscarHistorial();
                    }, 500);
                });
            }
            
            window.cargarHistorialPagos();
        }
        
        function cancelarInscripcion(id) {
            Swal.fire({
                title: '¿Cancelar inscripción?',
                text: "Esta acción no se puede deshacer",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Sí, cancelar',
                cancelButtonText: 'No'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '?cancelar=' + id;
                }
            });
        }
        
        let timeoutBusqueda;
        $('#searchInput').on('input', function() {
            clearTimeout(timeoutBusqueda);
            timeoutBusqueda = setTimeout(function() {
                const search = $('#searchInput').val();
                const estado = $('#estadoSelect').val();
                window.location.href = '?search=' + encodeURIComponent(search) + '&estado=' + encodeURIComponent(estado);
            }, 500);
        });
        
        $('#estadoSelect').on('change', function() {
            const search = $('#searchInput').val();
            const estado = $(this).val();
            window.location.href = '?search=' + encodeURIComponent(search) + '&estado=' + encodeURIComponent(estado);
        });
        
        $('#limpiarFiltros').on('click', function() {
            window.location.href = '?';
        });
        
        $('#formNuevoCliente').on('submit', function() {
            const $btn = $(this).find('button[type="submit"]');
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Guardando...');
        });
    </script>
</body>
</html>