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
    // INTEGRACIÓN REAL CON LECTOR USB
    // SIMULACIÓN (para pruebas)
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
        
        // Validaciones
        if (empty($nombre) || empty($apellido) || empty($telefono) || empty($plan_id)) {
            throw new Exception('Por favor complete todos los campos requeridos');
        }
        
        // Verificar si ya existe cliente con ese teléfono o email
        $stmt = $conn->prepare("SELECT id FROM clientes WHERE telefono = ? OR (email = ? AND email != '')");
        $stmt->bind_param("ss", $telefono, $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            throw new Exception('Ya existe un cliente con ese teléfono o email');
        }
        
        // Obtener duración del plan
        $stmt = $conn->prepare("SELECT duracion_dias, precio FROM planes WHERE id = ? AND estado = 'activo'");
        $stmt->bind_param("i", $plan_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $plan = $result->fetch_assoc();
        
        if (!$plan) {
            throw new Exception('Plan no válido');
        }
        
        // Calcular fecha fin
        if ($plan['duracion_dias'] > 0) {
            $fecha_fin = date('Y-m-d', strtotime($fecha_inicio . ' + ' . $plan['duracion_dias'] . ' days'));
        } else {
            $fecha_fin = null;
        }
        
        // Iniciar transacción
        $conn->begin_transaction();
        
        // Insertar cliente
        $stmt = $conn->prepare("INSERT INTO clientes (nombre, apellido, telefono, email, huella_digital, estado) VALUES (?, ?, ?, ?, ?, 'activo')");
        $stmt->bind_param("sssss", $nombre, $apellido, $telefono, $email, $huella_digital);
        $stmt->execute();
        $cliente_id = $conn->insert_id;
        
        // Crear inscripción
        $stmt = $conn->prepare("INSERT INTO inscripciones (cliente_id, plan_id, fecha_inicio, fecha_fin, precio_pagado, estado) VALUES (?, ?, ?, ?, ?, 'activa')");
        $stmt->bind_param("iisss", $cliente_id, $plan_id, $fecha_inicio, $fecha_fin, $precio_pagado);
        $stmt->execute();
        $inscripcion_id = $conn->insert_id;
        
        // Registrar pago
        $stmt = $conn->prepare("INSERT INTO pagos (inscripcion_id, cliente_id, monto, fecha_pago, metodo_pago, referencia, estado) VALUES (?, ?, ?, ?, ?, ?, 'completado')");
        $stmt->bind_param("iidsss", $inscripcion_id, $cliente_id, $precio_pagado, date('Y-m-d'), $metodo_pago, $referencia);
        $stmt->execute();
        
        $conn->commit();
        
        $mensaje = 'Cliente e inscripción creados exitosamente';
    } catch (Exception $e) {
        if (isset($conn)) $conn->rollback();
        $error = $e->getMessage();
    }
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
        
        // Obtener duración del plan
        $stmt = $conn->prepare("SELECT duracion_dias FROM planes WHERE id = ? AND estado = 'activo'");
        $stmt->bind_param("i", $plan_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $plan = $result->fetch_assoc();
        
        if (!$plan) {
            throw new Exception('Plan no válido');
        }
        
        // Calcular fecha fin
        $fecha_fin = date('Y-m-d', strtotime($fecha_inicio . ' + ' . $plan['duracion_dias'] . ' days'));
        
        // Iniciar transacción
        $conn->begin_transaction();
        
        // Crear nueva inscripción (renovación)
        $stmt = $conn->prepare("INSERT INTO inscripciones (cliente_id, plan_id, fecha_inicio, fecha_fin, precio_pagado, estado) VALUES (?, ?, ?, ?, ?, 'activa')");
        $stmt->bind_param("iisss", $cliente_id, $plan_id, $fecha_inicio, $fecha_fin, $precio_pagado);
        $stmt->execute();
        $nueva_inscripcion_id = $conn->insert_id;
        
        // Registrar pago
        $stmt = $conn->prepare("INSERT INTO pagos (inscripcion_id, cliente_id, monto, fecha_pago, metodo_pago, referencia, estado) VALUES (?, ?, ?, ?, ?, ?, 'completado')");
        $stmt->bind_param("iidsss", $nueva_inscripcion_id, $cliente_id, $precio_pagado, date('Y-m-d'), $metodo_pago, $referencia);
        $stmt->execute();
        
        $conn->commit();
        
        $mensaje = 'Inscripción renovada exitosamente';
    } catch (Exception $e) {
        if (isset($conn)) $conn->rollback();
        $error = $e->getMessage();
    }
}

// Cancelar inscripción
if (isset($_GET['cancelar']) && is_numeric($_GET['cancelar'])) {
    $id = $_GET['cancelar'];
    try {
        $stmt = $conn->prepare("UPDATE inscripciones SET estado = 'cancelada' WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $mensaje = 'Inscripción cancelada exitosamente';
    } catch (Exception $e) {
        $error = 'Error al cancelar la inscripción';
    }
}

// Actualizar estados de inscripciones vencidas
$update_vencidas = "UPDATE inscripciones SET estado = 'vencida' WHERE fecha_fin IS NOT NULL AND fecha_fin < CURDATE() AND estado = 'activa'";
$conn->query($update_vencidas);

// Obtener listado de inscripciones (con filtros para la carga inicial)
$search = isset($_GET['search']) ? $_GET['search'] : '';
$estado = isset($_GET['estado']) ? $_GET['estado'] : '';

$query = "SELECT i.*, c.nombre as cliente_nombre, c.apellido as cliente_apellido, c.telefono as cliente_telefono,
          p.nombre as plan_nombre, p.duracion_dias,
          CASE 
              WHEN p.duracion_dias = 0 THEN 'Visitas'
              WHEN p.duracion_dias = 1 THEN 'Diario'
              WHEN p.duracion_dias = 7 THEN 'Semanal'
              WHEN p.duracion_dias = 15 THEN 'Quincenal'
              WHEN p.duracion_dias = 30 THEN 'Mensual'
              ELSE CONCAT(p.duracion_dias, ' días')
          END as tipo_plan
          FROM inscripciones i 
          INNER JOIN clientes c ON i.cliente_id = c.id 
          INNER JOIN planes p ON i.plan_id = p.id 
          WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (c.nombre LIKE ? OR c.apellido LIKE ? OR c.telefono LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if (!empty($estado)) {
    $query .= " AND i.estado = ?";
    $params[] = $estado;
    $types .= "s";
}

$query .= " ORDER BY i.fecha_registro DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$inscripciones = $result->fetch_all(MYSQLI_ASSOC);

// Obtener planes activos para el formulario
$result = $conn->query("SELECT * FROM planes WHERE estado = 'activo' ORDER BY 
                        CASE 
                            WHEN duracion_dias = 0 THEN 1
                            WHEN duracion_dias = 1 THEN 2
                            WHEN duracion_dias = 7 THEN 3
                            WHEN duracion_dias = 15 THEN 4
                            WHEN duracion_dias = 30 THEN 5
                            ELSE 6
                        END");
$planes = $result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Inscripciones - Sistema Gimnasio</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        * {
            box-sizing: border-box;
        }
        
        :root {
            --primary-blue: #1e3a8a;
            --primary-dark: #1e2a3a;
            --primary-light: #3b82f6;
            --bg-light: #f8fafc;
        }
        
        body {
            background: var(--bg-light);
            overflow-x: hidden;
        }
        
        .content-wrapper {
            padding: 20px;
            min-height: 100vh;
            width: 100%;
        }
        
        .card {
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            border: none;
        }
        
        .card-header {
            background: var(--primary-blue);
            color: white;
            border-radius: 10px 10px 0 0;
            padding: 15px 20px;
            border: none;
        }
        
        .card-header h5 {
            margin: 0;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .btn-primary {
            background: var(--primary-blue);
            border: none;
            padding: 8px 20px;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }
        
        .btn-info {
            background: var(--primary-light);
            border: none;
            color: white;
        }
        
        .btn-info:hover {
            background: var(--primary-blue);
            color: white;
        }
        
        .btn-success {
            background: #10b981;
            border: none;
        }
        
        .btn-success:hover {
            background: #059669;
        }
        
        .btn-warning {
            background: #f59e0b;
            border: none;
            color: white;
        }
        
        .btn-warning:hover {
            background: #d97706;
            color: white;
        }
        
        .badge-activa {
            background: #10b981;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.85rem;
            display: inline-block;
        }
        
        .badge-vencida {
            background: #ef4444;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.85rem;
            display: inline-block;
        }
        
        .badge-cancelada {
            background: #6b7280;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.85rem;
            display: inline-block;
        }
        
        /* Tabla responsiva */
        .table-responsive-wrapper {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            position: relative;
        }
        
        .table {
            width: 100%;
            margin-bottom: 0;
            min-width: 800px;
        }
        
        .table thead th {
            background-color: #f8f9fa;
            font-weight: 600;
            white-space: nowrap;
            padding: 12px;
        }
        
        .table tbody td {
            padding: 12px;
            vertical-align: middle;
        }
        
        .btn-action {
            margin: 2px;
            white-space: nowrap;
        }
        
        .btn-cancelar, .btn-renovar {
            padding: 5px 12px;
            font-size: 0.875rem;
            white-space: nowrap;
        }
        
        .btn-renovar:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .filtros-card {
            background: white;
        }
        
        .modal-header {
            background: var(--primary-blue);
            color: white;
        }
        
        .modal-header .btn-close {
            color: white;
            filter: brightness(0) invert(1);
        }
        
        .main-content {
            margin-left: 0;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            width: 100%;
        }
        
        @media (min-width: 769px) {
            .main-content {
                margin-left: 280px;
            }
            body.sidebar-collapsed .main-content {
                margin-left: 70px;
            }
        }
        
        /* Estilos para captura de huella */
        .fingerprint-card {
            background: #f1f5f9;
            border: 2px dashed var(--primary-light);
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .fingerprint-card:hover {
            border-color: var(--primary-blue);
            background: #e2e8f0;
        }
        
        .fingerprint-card i {
            font-size: 48px;
            color: var(--primary-blue);
            margin-bottom: 10px;
        }
        
        .fingerprint-card .status {
            font-size: 12px;
            color: #64748b;
            margin-top: 5px;
        }
        
        .fingerprint-captured {
            border-color: #10b981;
            background: #d1fae5;
        }
        
        .fingerprint-captured i {
            color: #10b981;
        }
        
        /* Empty state mejorado */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: #f9fafb;
            border-radius: 10px;
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            color: #cbd5e1;
        }
        
        .empty-state p {
            font-size: 18px;
            color: #6c757d;
            margin-bottom: 10px;
        }
        
        .empty-state .small-text {
            font-size: 14px;
            color: #9ca3af;
        }
        
        /* Input group para búsqueda */
        .input-group .input-group-text {
            background-color: #e9ecef;
        }
        
        /* Responsive para móviles */
        @media (max-width: 768px) {
            .content-wrapper {
                padding: 15px;
            }
            
            .table thead th,
            .table tbody td {
                padding: 8px;
                font-size: 13px;
            }
            
            .btn-action {
                font-size: 12px;
                padding: 4px 8px;
            }
            
            .empty-state {
                padding: 40px 15px;
            }
            
            .empty-state i {
                font-size: 48px;
            }
            
            .empty-state p {
                font-size: 16px;
            }
        }
        
        /* Loading spinner */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="content-wrapper">
            <!-- Título -->
            <div class="row mb-4">
                <div class="col-12">
                    <h2><i class="fas fa-clipboard-list"></i> Gestión de Inscripciones</h2>
                    <hr>
                </div>
            </div>

            <!-- Alertas -->
            <?php if($mensaje): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $mensaje; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Botón Nuevo Cliente -->
            <div class="row">
                <div class="col-12 mb-3">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevoCliente">
                        <i class="fas fa-user-plus"></i> Nuevo Cliente + Inscripción
                    </button>
                </div>
            </div>

            <!-- Filtros -->
            <div class="card filtros-card">
                <div class="card-header">
                    <h5><i class="fas fa-filter"></i> Filtros de Búsqueda</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><i class="fas fa-search"></i> Buscar por nombre, apellido o teléfono</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" class="form-control" id="searchInput" placeholder="Escriba para buscar..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><i class="fas fa-tag"></i> Estado de inscripción</label>
                            <select class="form-select" id="estadoSelect">
                                <option value="">Todos los estados</option>
                                <option value="activa" <?php echo $estado == 'activa' ? 'selected' : ''; ?>>Activa</option>
                                <option value="vencida" <?php echo $estado == 'vencida' ? 'selected' : ''; ?>>Vencida</option>
                                <option value="cancelada" <?php echo $estado == 'cancelada' ? 'selected' : ''; ?>>Cancelada</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="button" class="btn btn-secondary w-100" id="limpiarFiltros">
                                <i class="fas fa-eraser"></i> Limpiar filtros
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabla de Inscripciones -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-list"></i> Listado de Inscripciones</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive-wrapper">
                        <table class="table table-hover" id="tablaInscripciones">
                            <thead>
                                <tr>
                                    <th style="min-width: 60px;"><i class="fas fa-hashtag"></i> ID</th>
                                    <th style="min-width: 180px;"><i class="fas fa-user"></i> Cliente</th>
                                    <th style="min-width: 130px;"><i class="fas fa-phone"></i> Teléfono</th>
                                    <th style="min-width: 150px;"><i class="fas fa-calendar-alt"></i> Plan</th>
                                    <th style="min-width: 110px;"><i class="fas fa-calendar-check"></i> Fecha Inicio</th>
                                    <th style="min-width: 110px;"><i class="fas fa-calendar-times"></i> Fecha Fin</th>
                                    <th style="min-width: 90px;"><i class="fas fa-dollar-sign"></i> Precio</th>
                                    <th style="min-width: 100px;"><i class="fas fa-info-circle"></i> Estado</th>
                                    <th style="min-width: 220px;"><i class="fas fa-cogs"></i> Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tablaBody">
                                <?php if(empty($inscripciones)): ?>
                                <tr>
                                    <td colspan="9">
                                        <div class="empty-state">
                                            <i class="fas fa-inbox"></i>
                                            <p><strong>No hay inscripciones registradas</strong></p>
                                            <p class="small-text">Utilice el botón "Nuevo Cliente + Inscripción" para comenzar</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach($inscripciones as $inscripcion): 
                                    // Determinar si la inscripción está vencida
                                    $estaVencida = ($inscripcion['estado'] == 'activa' && $inscripcion['fecha_fin'] && strtotime($inscripcion['fecha_fin']) < time());
                                    if ($estaVencida) {
                                        $updateStmt = $conn->prepare("UPDATE inscripciones SET estado = 'vencida' WHERE id = ?");
                                        $updateStmt->bind_param("i", $inscripcion['id']);
                                        $updateStmt->execute();
                                        $inscripcion['estado'] = 'vencida';
                                    }
                                ?>
                                <tr>
                                    <td><?php echo $inscripcion['id']; ?></td>
                                    <td><?php echo htmlspecialchars($inscripcion['cliente_nombre'] . ' ' . $inscripcion['cliente_apellido']); ?></td>
                                    <td><?php echo htmlspecialchars($inscripcion['cliente_telefono']); ?></td>
                                    <td><?php echo htmlspecialchars($inscripcion['plan_nombre']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($inscripcion['fecha_inicio'])); ?></td>
                                    <td><?php echo $inscripcion['duracion_dias'] > 0 ? date('d/m/Y', strtotime($inscripcion['fecha_fin'])) : 'Sin vencimiento'; ?></td>
                                    <td>$<?php echo number_format($inscripcion['precio_pagado'], 2); ?></td>
                                    <td>
                                        <?php
                                        $badgeClass = '';
                                        $statusText = '';
                                        if($inscripcion['estado'] == 'activa') {
                                            $badgeClass = 'badge-activa';
                                            $statusText = 'Activa';
                                        } elseif($inscripcion['estado'] == 'vencida') {
                                            $badgeClass = 'badge-vencida';
                                            $statusText = 'Vencida';
                                        } else {
                                            $badgeClass = 'badge-cancelada';
                                            $statusText = 'Cancelada';
                                        }
                                        ?>
                                        <span class="<?php echo $badgeClass; ?>"><?php echo $statusText; ?></span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-info btn-action" onclick="verDetalle(<?php echo $inscripcion['id']; ?>)" title="Ver detalles">
                                            <i class="fas fa-eye"></i> Detalle
                                        </button>
                                        <?php if($inscripcion['estado'] == 'vencida'): ?>
                                        <button class="btn btn-sm btn-success btn-renovar" onclick="abrirRenovar(<?php echo $inscripcion['id']; ?>, <?php echo $inscripcion['cliente_id']; ?>)" title="Renovar inscripción vencida">
                                            <i class="fas fa-sync-alt"></i> Renovar
                                        </button>
                                        <?php elseif($inscripcion['estado'] == 'activa'): ?>
                                        <button class="btn btn-sm btn-success btn-renovar" disabled title="La inscripción aún está activa, no requiere renovación">
                                            <i class="fas fa-sync-alt"></i> Renovar
                                        </button>
                                        <?php endif; ?>
                                        <?php if($inscripcion['estado'] == 'activa'): ?>
                                        <button class="btn btn-sm btn-warning btn-cancelar" onclick="cancelarInscripcion(<?php echo $inscripcion['id']; ?>)" title="Cancelar inscripción">
                                            <i class="fas fa-times-circle"></i> Cancelar
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Nuevo Cliente + Inscripción -->
    <div class="modal fade" id="modalNuevoCliente" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus"></i> Nuevo Cliente e Inscripción</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" id="formNuevoCliente">
                    <input type="hidden" name="action" value="crear_cliente_inscripcion">
                    <input type="hidden" name="huella_digital" id="huella_digital" value="">
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><i class="fas fa-user"></i> Nombre *</label>
                                <input type="text" class="form-control" name="nombre" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><i class="fas fa-user"></i> Apellido *</label>
                                <input type="text" class="form-control" name="apellido" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><i class="fas fa-phone"></i> Teléfono *</label>
                                <input type="tel" class="form-control" name="telefono" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><i class="fas fa-envelope"></i> Email</label>
                                <input type="email" class="form-control" name="email">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><i class="fas fa-dumbbell"></i> Plan *</label>
                                <select class="form-select" name="plan_id" id="plan_id_nuevo" required onchange="actualizarPrecioNuevo()">
                                    <option value="">Seleccionar plan</option>
                                    <?php foreach($planes as $plan): ?>
                                    <option value="<?php echo $plan['id']; ?>" data-precio="<?php echo $plan['precio']; ?>" data-duracion="<?php echo $plan['duracion_dias']; ?>">
                                        <?php 
                                        $tipo = '';
                                        if($plan['duracion_dias'] == 0) $tipo = 'Paquete de Visitas';
                                        elseif($plan['duracion_dias'] == 1) $tipo = 'Por Día';
                                        elseif($plan['duracion_dias'] == 7) $tipo = 'Semanal';
                                        elseif($plan['duracion_dias'] == 15) $tipo = 'Quincenal';
                                        elseif($plan['duracion_dias'] == 30) $tipo = 'Mensual';
                                        else $tipo = $plan['duracion_dias'] . ' días';
                                        echo htmlspecialchars($plan['nombre'] . ' - ' . $tipo . ' - $' . number_format($plan['precio'], 2)); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><i class="fas fa-calendar-alt"></i> Fecha de Inicio *</label>
                                <input type="date" class="form-control" name="fecha_inicio" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><i class="fas fa-dollar-sign"></i> Precio Pagado *</label>
                                <input type="number" class="form-control" name="precio_pagado" id="precio_pagado_nuevo" step="0.01" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><i class="fas fa-credit-card"></i> Método de Pago *</label>
                                <select class="form-select" name="metodo_pago" required>
                                    <option value="efectivo">Efectivo</option>
                                    <option value="tarjeta">Tarjeta de crédito/débito</option>
                                    <option value="transferencia">Transferencia bancaria</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-12 mb-3">
                                <label class="form-label"><i class="fas fa-hashtag"></i> Referencia</label>
                                <input type="text" class="form-control" name="referencia" placeholder="Número de referencia (opcional)">
                            </div>
                        </div>
                        
                        <!-- Captura de Huella Digital -->
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-fingerprint"></i> Registro de Huella Digital</label>
                            <div class="fingerprint-card" id="fingerprintCard" onclick="capturarHuella()">
                                <i class="fas fa-fingerprint"></i>
                                <div>Capturar Huella Digital</div>
                                <div class="status" id="huellaStatus">No registrada</div>
                            </div>
                            <small class="text-muted"><i class="fas fa-info-circle"></i> Coloque su dedo en el lector USB y presione para capturar</small>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Cliente e Inscribir</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Renovar Inscripción -->
    <div class="modal fade" id="modalRenovar" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-sync-alt"></i> Renovar Inscripción</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" id="formRenovar">
                    <input type="hidden" name="action" value="renovar_inscripcion">
                    <input type="hidden" name="inscripcion_id" id="renovar_inscripcion_id">
                    <input type="hidden" name="cliente_id" id="renovar_cliente_id">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-user"></i> Cliente</label>
                            <input type="text" class="form-control" id="renovar_cliente_nombre" readonly disabled>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-dumbbell"></i> Plan *</label>
                            <select class="form-select" name="plan_id" id="renovar_plan_id" required onchange="actualizarPrecioRenovar()">
                                <option value="">Seleccionar plan</option>
                                <?php foreach($planes as $plan): ?>
                                <option value="<?php echo $plan['id']; ?>" data-precio="<?php echo $plan['precio']; ?>" data-duracion="<?php echo $plan['duracion_dias']; ?>">
                                    <?php 
                                    $tipo = '';
                                    if($plan['duracion_dias'] == 0) $tipo = 'Paquete de Visitas';
                                    elseif($plan['duracion_dias'] == 1) $tipo = 'Por Día';
                                    elseif($plan['duracion_dias'] == 7) $tipo = 'Semanal';
                                    elseif($plan['duracion_dias'] == 15) $tipo = 'Quincenal';
                                    elseif($plan['duracion_dias'] == 30) $tipo = 'Mensual';
                                    else $tipo = $plan['duracion_dias'] . ' días';
                                    echo htmlspecialchars($plan['nombre'] . ' - ' . $tipo . ' - $' . number_format($plan['precio'], 2)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-calendar-alt"></i> Fecha de Inicio *</label>
                            <input type="date" class="form-control" name="fecha_inicio" id="renovar_fecha_inicio" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-dollar-sign"></i> Precio Pagado *</label>
                            <input type="number" class="form-control" name="precio_pagado" id="renovar_precio_pagado" step="0.01" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-credit-card"></i> Método de Pago *</label>
                            <select class="form-select" name="metodo_pago" required>
                                <option value="efectivo">Efectivo</option>
                                <option value="tarjeta">Tarjeta de crédito/débito</option>
                                <option value="transferencia">Transferencia bancaria</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-hashtag"></i> Referencia</label>
                            <input type="text" class="form-control" name="referencia" placeholder="Número de referencia (opcional)">
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Cancelar</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-sync-alt"></i> Renovar Inscripción</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Detalle -->
    <div class="modal fade" id="modalDetalle" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-info-circle"></i> Detalle de Inscripción</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalleContenido">
                    <div class="text-center">
                        <i class="fas fa-spinner fa-spin"></i> Cargando...
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Variables
        let huellaCapturada = '';
        
        $(document).ready(function() {
            // Filtros en tiempo real - Recargar página con los filtros
            $('#searchInput').on('input', function() {
                aplicarFiltros();
            });
            
            $('#estadoSelect').on('change', function() {
                aplicarFiltros();
            });
            
            // Botón limpiar filtros
            $('#limpiarFiltros').on('click', function() {
                $('#searchInput').val('');
                $('#estadoSelect').val('');
                aplicarFiltros();
            });
        });
        
        function aplicarFiltros() {
            const search = $('#searchInput').val();
            const estado = $('#estadoSelect').val();
            
            // Construir URL con los filtros
            let url = window.location.pathname + '?';
            if (search) url += 'search=' + encodeURIComponent(search) + '&';
            if (estado) url += 'estado=' + encodeURIComponent(estado);
            
            // Redirigir a la página con los filtros aplicados
            window.location.href = url;
        }

        // Actualizar precio nuevo cliente
        function actualizarPrecioNuevo() {
            const planSelect = document.getElementById('plan_id_nuevo');
            const selectedOption = planSelect.options[planSelect.selectedIndex];
            const precio = selectedOption.getAttribute('data-precio');
            if (precio) {
                document.getElementById('precio_pagado_nuevo').value = precio;
            }
        }

        // Actualizar precio renovación
        function actualizarPrecioRenovar() {
            const planSelect = document.getElementById('renovar_plan_id');
            const selectedOption = planSelect.options[planSelect.selectedIndex];
            const precio = selectedOption.getAttribute('data-precio');
            if (precio) {
                document.getElementById('renovar_precio_pagado').value = precio;
            }
        }

        // Captura de huella digital
        function capturarHuella() {
            const fingerprintCard = document.getElementById('fingerprintCard');
            const huellaStatus = document.getElementById('huellaStatus');
            
            Swal.fire({
                title: 'Capturando huella digital',
                text: 'Por favor, coloque su dedo en el lector USB...',
                icon: 'info',
                showConfirmButton: false,
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // SIMULACIÓN (para pruebas)
            setTimeout(() => {
                Swal.close();
                
                const huellaData = 'FP_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                huellaCapturada = huellaData;
                document.getElementById('huella_digital').value = huellaData;
                
                fingerprintCard.classList.add('fingerprint-captured');
                huellaStatus.innerHTML = '<i class="fas fa-check-circle"></i> Huella registrada correctamente';
                huellaStatus.style.color = '#10b981';
                
                Swal.fire({
                    title: '¡Huella capturada!',
                    text: 'La huella digital ha sido registrada exitosamente.',
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false
                });
            }, 2000);
        }

        // Abrir modal de renovación
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
                    }
                }
            });
        }

        // Ver detalle de inscripción
        function verDetalle(id) {
            $('#modalDetalle').modal('show');
            $('#detalleContenido').html('<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>');
            
            $.ajax({
                url: 'includes/inscripcion_detalle.php',
                method: 'POST',
                data: { id: id },
                success: function(response) {
                    $('#detalleContenido').html(response);
                },
                error: function() {
                    $('#detalleContenido').html('<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> Error al cargar los detalles</div>');
                }
            });
        }

        // Cancelar inscripción
        function cancelarInscripcion(id) {
            Swal.fire({
                title: '¿Estás seguro?',
                text: "Esta acción cancelará la inscripción del cliente",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Sí, cancelar',
                cancelButtonText: 'No, mantener'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '?cancelar=' + id;
                }
            });
        }

        // Auto cerrar alertas
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                setTimeout(() => bsAlert.close(), 3000);
            });
        }, 3000);
    </script>
</body>
</html>