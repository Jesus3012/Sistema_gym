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
        
        $stmt = $conn->prepare("SELECT duracion_dias, precio FROM planes WHERE id = ? AND estado = 'activo'");
        $stmt->bind_param("i", $plan_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $plan = $result->fetch_assoc();
        
        if (!$plan) {
            throw new Exception('Plan no válido');
        }
        
        if ($plan['duracion_dias'] > 0) {
            $fecha_fin = date('Y-m-d', strtotime($fecha_inicio . ' + ' . $plan['duracion_dias'] . ' days'));
        } else {
            $fecha_fin = null;
        }
        
        $conn->begin_transaction();
        
        $stmt = $conn->prepare("INSERT INTO clientes (nombre, apellido, telefono, email, huella_digital, estado) VALUES (?, ?, ?, ?, ?, 'activo')");
        $stmt->bind_param("sssss", $nombre, $apellido, $telefono, $email, $huella_digital);
        $stmt->execute();
        $cliente_id = $conn->insert_id;
        
        $stmt = $conn->prepare("INSERT INTO inscripciones (cliente_id, plan_id, fecha_inicio, fecha_fin, precio_pagado, estado) VALUES (?, ?, ?, ?, ?, 'activa')");
        $stmt->bind_param("iisss", $cliente_id, $plan_id, $fecha_inicio, $fecha_fin, $precio_pagado);
        $stmt->execute();
        $inscripcion_id = $conn->insert_id;
        
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
        
        $stmt = $conn->prepare("SELECT duracion_dias FROM planes WHERE id = ? AND estado = 'activo'");
        $stmt->bind_param("i", $plan_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $plan = $result->fetch_assoc();
        
        if (!$plan) {
            throw new Exception('Plan no válido');
        }
        
        $fecha_fin = date('Y-m-d', strtotime($fecha_inicio . ' + ' . $plan['duracion_dias'] . ' days'));
        
        $conn->begin_transaction();
        
        // Actualizar la inscripción existente en lugar de crear una nueva
        $stmt = $conn->prepare("UPDATE inscripciones SET plan_id = ?, fecha_inicio = ?, fecha_fin = ?, precio_pagado = ?, estado = 'activa' WHERE id = ?");
        $stmt->bind_param("isssi", $plan_id, $fecha_inicio, $fecha_fin, $precio_pagado, $inscripcion_id);
        $stmt->execute();
        
        // Registrar el pago en el historial
        $stmt = $conn->prepare("INSERT INTO pagos (inscripcion_id, cliente_id, monto, fecha_pago, metodo_pago, referencia, estado) VALUES (?, ?, ?, ?, ?, ?, 'completado')");
        $stmt->bind_param("iidsss", $inscripcion_id, $cliente_id, $precio_pagado, date('Y-m-d'), $metodo_pago, $referencia);
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
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$inscripciones = $result->fetch_all(MYSQLI_ASSOC);

// Obtener total de registros para paginación
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
        
        .btn-detalle {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
        }
        
        .btn-renovar {
            background: #10b981;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
        }
        
        .btn-cancelar {
            background: #dc2626;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
        }
        
        .btn-cancelar:hover {
            background: #b91c1c;
        }
        
        .btn-ver-pagos {
            background: #8b5cf6;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
        }
        
        .btn-renovar:disabled {
            background: #9ca3af;
            cursor: not-allowed;
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
        
        /* Estilos para la tabla de pagos dentro del modal */
        .tabla-pagos {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .tabla-pagos th,
        .tabla-pagos td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .tabla-pagos th {
            background: #f8f9fa;
            font-weight: 600;
            cursor: pointer;
        }
        
        .tabla-pagos th:hover {
            background: #e9ecef;
        }
        
        .tabla-pagos tr:hover {
            background: #f8f9fa;
        }
        
        .pagos-pagination {
            margin-top: 20px;
            justify-content: center;
        }
        
        .search-pagos {
            margin-bottom: 15px;
        }
        
        .search-pagos input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
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
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <!-- Título -->
        <div class="mb-4">
            <h2><i class="fas fa-clipboard-list"></i> Gestión de Inscripciones</h2>
            <p class="text-muted mb-0">Administra las inscripciones de tus clientes</p>
        </div>
        
        <!-- Alertas -->
        <div id="alertas"></div>
        
        <!-- Botón Nuevo Cliente -->
        <div class="mb-3">
            <button class="btn-custom-primary" data-bs-toggle="modal" data-bs-target="#modalNuevoCliente">
                <i class="fas fa-user-plus"></i> Nuevo Cliente + Inscripción
            </button>
        </div>
        
        <!-- Filtros -->
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
        
        <!-- Tabla de Inscripciones -->
        <div class="card-custom">
            <div class="card-header-custom">
                <i class="fas fa-list"></i> Listado de Inscripciones
            </div>
            <div class="card-body-custom" style="padding: 0;">
                <div style="overflow-x: auto;">
                    <table class="tabla-simple" id="tablaInscripciones">
                        <thead>
                            <tr>
                                <th data-sort="cliente">Cliente <i class="fas fa-sort"></i></th>
                                <th data-sort="telefono">Teléfono <i class="fas fa-sort"></i></th>
                                <th data-sort="plan">Plan <i class="fas fa-sort"></i></th>
                                <th data-sort="fecha_inicio">Fecha Inicio <i class="fas fa-sort"></i></th>
                                <th data-sort="fecha_fin">Fecha Fin <i class="fas fa-sort"></i></th>
                                <th data-sort="precio">$ Precio <i class="fas fa-sort"></i></th>
                                <th data-sort="estado">Estado <i class="fas fa-sort"></i></th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="tablaBody">
                            <tr>
                                <td colspan="8" class="loading">
                                    <div class="spinner"></div>
                                    <p>Cargando datos...</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Paginación -->
                <div id="paginationContainer" class="pagination"></div>
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
                <form id="formNuevoCliente">
                    <input type="hidden" name="action" value="crear_cliente_inscripcion">
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
                <form id="formRenovar">
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
    
    <!-- Modal Detalle con Historial de Pagos -->
    <div class="modal fade" id="modalDetalle" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #1e3a8a; color: white;">
                    <h5 class="modal-title"><i class="fas fa-info-circle"></i> Detalle de Inscripción</h5>
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
        let currentPage = <?php echo $page; ?>;
        let currentSort = '<?php echo $sort; ?>';
        let currentOrder = '<?php echo $order; ?>';
        let currentSearch = '<?php echo $search; ?>';
        let currentEstado = '<?php echo $estado; ?>';
        let timeoutBusqueda;
        
        $(document).ready(function() {
            cargarDatos();
            
            // Búsqueda en tiempo real
            $('#searchInput').on('input', function() {
                clearTimeout(timeoutBusqueda);
                timeoutBusqueda = setTimeout(function() {
                    currentSearch = $('#searchInput').val();
                    currentPage = 1;
                    cargarDatos();
                }, 500);
            });
            
            // Filtro por estado
            $('#estadoSelect').on('change', function() {
                currentEstado = $(this).val();
                currentPage = 1;
                cargarDatos();
            });
            
            // Limpiar filtros
            $('#limpiarFiltros').on('click', function() {
                $('#searchInput').val('');
                $('#estadoSelect').val('');
                currentSearch = '';
                currentEstado = '';
                currentPage = 1;
                cargarDatos();
            });
            
            // Ordenamiento
            $('th[data-sort]').on('click', function() {
                const sort = $(this).data('sort');
                if (currentSort === sort) {
                    currentOrder = currentOrder === 'ASC' ? 'DESC' : 'ASC';
                } else {
                    currentSort = sort;
                    currentOrder = 'ASC';
                }
                currentPage = 1;
                cargarDatos();
            });
            
            // Formulario nuevo cliente
            $('#formNuevoCliente').on('submit', function(e) {
                e.preventDefault();
                $.ajax({
                    url: window.location.pathname,
                    method: 'POST',
                    data: $(this).serialize(),
                    success: function(response) {
                        Swal.fire('Éxito', 'Cliente e inscripción creados exitosamente', 'success');
                        $('#modalNuevoCliente').modal('hide');
                        cargarDatos();
                    },
                    error: function() {
                        Swal.fire('Error', 'Error al crear el cliente', 'error');
                    }
                });
            });
            
            // Formulario renovar
            $('#formRenovar').on('submit', function(e) {
                e.preventDefault();
                $.ajax({
                    url: window.location.pathname,
                    method: 'POST',
                    data: $(this).serialize(),
                    success: function(response) {
                        Swal.fire('Éxito', 'Inscripción renovada exitosamente', 'success');
                        $('#modalRenovar').modal('hide');
                        cargarDatos();
                    },
                    error: function() {
                        Swal.fire('Error', 'Error al renovar la inscripción', 'error');
                    }
                });
            });
        });
        
        function cargarDatos() {
            $('#tablaBody').html('<tr><td colspan="8" class="loading"><div class="spinner"></div><p>Cargando datos...</p></td></tr>');
            
            $.ajax({
                url: 'ajax_inscripciones.php',
                method: 'GET',
                data: {
                    search: currentSearch,
                    estado: currentEstado,
                    page: currentPage,
                    sort: currentSort,
                    order: currentOrder
                },
                dataType: 'json',
                success: function(data) {
                    mostrarTabla(data.inscripciones);
                    mostrarPaginacion(data.total_pages, data.current_page);
                },
                error: function() {
                    $('#tablaBody').html('<tr><td colspan="8" class="loading"><p class="text-danger">Error al cargar los datos</p></td></tr>');
                }
            });
        }
        
        function mostrarTabla(inscripciones) {
            if (!inscripciones || inscripciones.length === 0) {
                $('#tablaBody').html('<tr><td colspan="8" style="text-align: center; padding: 40px;"><i class="fas fa-inbox" style="font-size: 48px; color: #ccc;"></i><p class="mt-2">No hay inscripciones registradas</p></td></tr>');
                return;
            }
            
            let html = '';
            for (let i = 0; i < inscripciones.length; i++) {
                const ins = inscripciones[i];
                let badgeClass = '';
                let statusText = '';
                
                if (ins.estado === 'activa') {
                    badgeClass = 'badge-activa';
                    statusText = 'Activa';
                } else if (ins.estado === 'vencida') {
                    badgeClass = 'badge-vencida';
                    statusText = 'Vencida';
                } else {
                    badgeClass = 'badge-cancelada';
                    statusText = 'Cancelada';
                }
                
                html += '<tr>';
                html += '<td><strong>' + ins.cliente_nombre + ' ' + ins.cliente_apellido + '</strong></td>';
                html += '<td>' + ins.cliente_telefono + '</td>';
                html += '<td>' + ins.plan_nombre + '</td>';
                html += '<td>' + ins.fecha_inicio_formateada + '</td>';
                html += '<td>' + (ins.duracion_dias > 0 ? ins.fecha_fin_formateada : 'Sin vencimiento') + '</td>';
                html += '<td>$' + parseFloat(ins.precio_pagado).toFixed(2) + '</td>';
                html += '<td><span class="' + badgeClass + '">' + statusText + '</span></td>';
                html += '<td>';
                html += '<button class="btn-detalle" onclick="verDetalle(' + ins.id + ')"><i class="fas fa-eye"></i> Ver</button>';
                
                if (ins.estado === 'vencida') {
                    html += '<button class="btn-renovar" onclick="abrirRenovar(' + ins.id + ', ' + ins.cliente_id + ')"><i class="fas fa-sync-alt"></i> Renovar</button>';
                } else if (ins.estado === 'activa') {
                    html += '<button class="btn-renovar" disabled><i class="fas fa-sync-alt"></i> Renovar</button>';
                    html += '<button class="btn-cancelar" onclick="cancelarInscripcion(' + ins.id + ')"><i class="fas fa-times-circle"></i> Cancelar</button>';
                }
                
                html += '</div>';
                html += '</tr>';
            }
            $('#tablaBody').html(html);
        }
        
        function mostrarPaginacion(totalPages, currentPage) {
            if (totalPages <= 1) {
                $('#paginationContainer').html('');
                return;
            }
            
            let html = '<ul class="pagination">';
            html += '<li class="page-item ' + (currentPage <= 1 ? 'disabled' : '') + '">';
            html += '<a class="page-link" onclick="cambiarPagina(' + (currentPage - 1) + ')">Anterior</a></li>';
            
            let startPage = Math.max(1, currentPage - 2);
            let endPage = Math.min(totalPages, currentPage + 2);
            
            if (startPage > 1) {
                html += '<li class="page-item"><a class="page-link" onclick="cambiarPagina(1)">1</a></li>';
                if (startPage > 2) html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
            
            for (let i = startPage; i <= endPage; i++) {
                html += '<li class="page-item ' + (i === currentPage ? 'active' : '') + '">';
                html += '<a class="page-link" onclick="cambiarPagina(' + i + ')">' + i + '</a></li>';
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) html += '<li class="page-item disabled"><span class="page-link">...</span></li>';
                html += '<li class="page-item"><a class="page-link" onclick="cambiarPagina(' + totalPages + ')">' + totalPages + '</a></li>';
            }
            
            html += '<li class="page-item ' + (currentPage >= totalPages ? 'disabled' : '') + '">';
            html += '<a class="page-link" onclick="cambiarPagina(' + (currentPage + 1) + ')">Siguiente</a></li>';
            html += '</ul>';
            
            $('#paginationContainer').html(html);
        }
        
        function cambiarPagina(page) {
            currentPage = page;
            cargarDatos();
        }
        
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
                const huellaData = 'FP_' + Date.now();
                document.getElementById('huella_digital').value = huellaData;
                document.getElementById('huellaStatus').innerHTML = '<i class="fas fa-check-circle"></i> Huella registrada';
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
                    }
                }
            });
        }
        
        function verDetalle(id) {
            $('#modalDetalle').modal('show');
            $('#detalleContenido').html('<div class="text-center"><div class="spinner-border text-primary"></div><p>Cargando...</p></div>');
            
            $.ajax({
                url: 'includes/inscripcion_detalle.php',
                method: 'POST',
                data: { id: id },
                success: function(response) {
                    $('#detalleContenido').html(response);
                },
                error: function() {
                    $('#detalleContenido').html('<div class="alert alert-danger">Error al cargar los detalles</div>');
                }
            });
        }
        
        function verHistorialPagos(inscripcionId, clienteNombre) {
            Swal.fire({
                title: 'Historial de Pagos - ' + clienteNombre,
                html: '<div id="historialPagosContenido"><div class="text-center"><div class="spinner-border text-primary"></div><p>Cargando historial...</p></div></div>',
                width: '900px',
                showConfirmButton: false,
                showCloseButton: true,
                didOpen: () => {
                    cargarHistorialPagos(inscripcionId);
                }
            });
        }
        
        function cargarHistorialPagos(inscripcionId) {
            $.ajax({
                url: 'includes/historial_pagos.php',
                method: 'POST',
                data: { inscripcion_id: inscripcionId },
                success: function(response) {
                    $('#historialPagosContenido').html(response);
                },
                error: function() {
                    $('#historialPagosContenido').html('<div class="alert alert-danger">Error al cargar el historial de pagos</div>');
                }
            });
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
    </script>
</body>
</html>