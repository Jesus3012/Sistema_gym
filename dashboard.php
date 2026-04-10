<?php
date_default_timezone_set('America/Mexico_City');
// dashboard.php
require_once 'includes/auth_check.php';
require_once 'config/database.php';

// Verificar si el usuario necesita cambiar la contraseña
$require_password_change = false;
$user_id = $_SESSION['user_id'];

$database = new Database();
$db = $database->getConnection();

// Verificar conexión
if (!$db) {
    die("Error de conexión a la base de datos");
}

// Consultar si el usuario requiere cambio de contraseña
$query = "SELECT password_change_required, estado FROM usuarios WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user_data = $result->fetch_assoc();
    $require_password_change = ($user_data['password_change_required'] == 1);
    
    // Verificar si el usuario está activo
    if ($user_data['estado'] == 'inactivo') {
        session_destroy();
        header("Location: login.php?error=usuario_inactivo");
        exit();
    }
}
$stmt->close();

// Mostrar mensajes de cambio de contraseña
if (isset($_SESSION['password_change_success'])) {
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: 'success',
                title: '¡Contraseña actualizada!',
                text: 'Tu contraseña ha sido cambiada exitosamente',
                confirmButtonColor: '#003366'
            });
        });
    </script>";
    unset($_SESSION['password_change_success']);
}

if (isset($_SESSION['password_change_error'])) {
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: '" . $_SESSION['password_change_error'] . "',
                confirmButtonColor: '#003366'
            });
        });
    </script>";
    unset($_SESSION['password_change_error']);
}

// Obtener estadísticas de la base de datos
// Total de clientes activos
$query = "SELECT COUNT(*) as total FROM clientes WHERE estado = 'activo'";
$result = $db->query($query);
if ($result && $result->num_rows > 0) {
    $total_clientes = $result->fetch_assoc()['total'];
} else {
    $total_clientes = 0;
}

// Total de productos activos
$query = "SELECT COUNT(*) as total FROM productos WHERE estado = 'activo'";
$result = $db->query($query);
if ($result && $result->num_rows > 0) {
    $total_productos = $result->fetch_assoc()['total'];
} else {
    $total_productos = 0;
}

// Total de inscripciones activas
$query = "SELECT COUNT(*) as total FROM inscripciones WHERE estado = 'activa'";
$result = $db->query($query);
if ($result && $result->num_rows > 0) {
    $total_inscripciones = $result->fetch_assoc()['total'];
} else {
    $total_inscripciones = 0;
}

// Total de clases activas
$query = "SELECT COUNT(*) as total FROM clases WHERE estado = 'activa'";
$result = $db->query($query);
if ($result && $result->num_rows > 0) {
    $total_clases = $result->fetch_assoc()['total'];
} else {
    $total_clases = 0;
}

// Ingresos del mes actual
$query = "SELECT SUM(monto) as total FROM pagos WHERE MONTH(fecha_pago) = MONTH(CURDATE()) AND YEAR(fecha_pago) = YEAR(CURDATE()) AND estado = 'completado'";
$result = $db->query($query);
if ($result && $result->num_rows > 0) {
    $ingresos_mes = $result->fetch_assoc()['total'] ?? 0;
} else {
    $ingresos_mes = 0;
}

// Asistencias del día de hoy
$query = "SELECT COUNT(*) as total FROM asistencias WHERE fecha = CURDATE()";
$result = $db->query($query);
if ($result && $result->num_rows > 0) {
    $asistencias_hoy = $result->fetch_assoc()['total'] ?? 0;
} else {
    $asistencias_hoy = 0;
}

// Obtener TODOS los clientes para el modal
$query = "SELECT id, nombre, apellido, telefono, email, fecha_registro FROM clientes ORDER BY fecha_registro DESC";
$result = $db->query($query);
$todos_clientes = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $todos_clientes[] = $row;
    }
}

// Últimos 5 clientes registrados
$ultimos_clientes = array_slice($todos_clientes, 0, 5);

// Obtener TODOS los productos para el modal
$query = "SELECT p.id, p.nombre, p.descripcion, p.stock, p.stock_minimo, p.precio_venta, 
          c.nombre as categoria 
          FROM productos p 
          LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
          WHERE p.estado = 'activo' 
          ORDER BY p.nombre ASC";
$result = $db->query($query);
$todos_productos = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $todos_productos[] = $row;
    }
}

// Obtener TODAS las inscripciones para el modal
$query = "SELECT i.id, c.nombre as cliente_nombre, c.apellido as cliente_apellido, 
          p.nombre as plan_nombre, i.fecha_inicio, i.fecha_fin, i.precio_pagado, i.estado
          FROM inscripciones i 
          JOIN clientes c ON i.cliente_id = c.id 
          JOIN planes p ON i.plan_id = p.id 
          WHERE i.estado = 'activa' 
          ORDER BY i.fecha_fin ASC";
$result = $db->query($query);
$todas_inscripciones = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $todas_inscripciones[] = $row;
    }
}

// Obtener TODAS las clases para el modal
$query = "SELECT c.id, c.nombre, c.descripcion, c.horario, c.instructor, 
          c.cupo_maximo, c.cupo_actual, c.duracion_minutos, c.estado
          FROM clases c 
          WHERE c.estado = 'activa' 
          ORDER BY c.horario ASC";
$result = $db->query($query);
$todas_clases = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $todas_clases[] = $row;
    }
}

// Próximas clases (primeras 5)
$proximas_clases = array_slice($todas_clases, 0, 5);

// Productos con bajo stock
$query = "SELECT p.id, p.nombre, p.stock, p.stock_minimo, c.nombre as categoria 
          FROM productos p 
          LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
          WHERE p.stock <= p.stock_minimo AND p.estado = 'activo' 
          ORDER BY p.stock ASC LIMIT 5";
$result = $db->query($query);
$productos_bajo_stock = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $productos_bajo_stock[] = $row;
    }
}

// Inscripciones que vencen en los próximos 7 días
$query = "SELECT COUNT(*) as total FROM inscripciones i 
          JOIN clientes c ON i.cliente_id = c.id 
          WHERE i.fecha_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) 
          AND i.estado = 'activa'";
$result = $db->query($query);
if ($result && $result->num_rows > 0) {
    $vencimientos_proximos = $result->fetch_assoc()['total'] ?? 0;
} else {
    $vencimientos_proximos = 0;
}

// Ingresos por mes para el gráfico (últimos 6 meses)
$query = "SELECT 
            DATE_FORMAT(fecha_pago, '%Y-%m') as mes,
            SUM(monto) as total 
          FROM pagos 
          WHERE fecha_pago >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
          AND estado = 'completado'
          GROUP BY DATE_FORMAT(fecha_pago, '%Y-%m')
          ORDER BY mes ASC";
$result = $db->query($query);
$ingresos_por_mes = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $ingresos_por_mes[$row['mes']] = $row['total'];
    }
}

// Preparar datos para el gráfico (últimos 6 meses)
$labels = [];
$datos = [];
for ($i = 5; $i >= 0; $i--) {
    $fecha = date('Y-m', strtotime("-$i months"));
    $labels[] = date('M Y', strtotime("-$i months"));
    $datos[] = isset($ingresos_por_mes[$fecha]) ? (float)$ingresos_por_mes[$fecha] : 0;
}

// Incluir el sidebar
include 'includes/sidebar.php';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Gym System</title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        /* ============================================
           ESTILOS ORIGINALES DEL DASHBOARD (NO MODIFICAR)
           ============================================ */
        .small-box {
            border-radius: 12px;
            transition: transform 0.2s;
        }
        .small-box:hover {
            transform: translateY(-5px);
        }
        .card {
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .card-header {
            background: transparent;
            border-bottom: 1px solid #e9ecef;
            font-weight: 600;
        }
        .table th {
            border-top: none;
        }
        .badge-warning {
            background-color: #ffc107;
            color: #212529;
        }
        .progress {
            border-radius: 10px;
            height: 8px;
        }
        .welcome-banner {
            background: linear-gradient(135deg, #0a2540 0%, #1e3a5f 100%);
            border-radius: 12px;
            padding: 20px;
            color: white;
            margin-bottom: 25px;
        }
        .welcome-banner h3 {
            margin-bottom: 5px;
            font-weight: 600;
        }
        .welcome-banner p {
            margin-bottom: 0;
            opacity: 0.9;
        }
        .btn-app {
            background: #f4f4f4;
            border-radius: 12px;
            padding: 15px 10px;
            display: inline-block;
            text-align: center;
            min-width: 100px;
            transition: all 0.2s;
        }
        .btn-app:hover {
            background: #e9ecef;
            transform: translateY(-2px);
        }
        .access-time {
            font-size: 12px;
            opacity: 0.8;
            margin-top: 8px;
        }
        .session-timer {
            background: rgba(255,255,255,0.2);
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-block;
        }
        
        /* ============================================
           ESTILOS EXCLUSIVOS PARA LOS MODALES (CARDS)
           ============================================ */
        .modal-xl {
            max-width: 1200px;
        }
        
        .modal-content {
            border-radius: 12px;
            border: none;
            overflow: hidden;
        }
        
        .modal-header {
            background: #003366;
            border: none;
            padding: 20px 25px;
        }
        
        .modal-header .modal-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: white;
        }
        
        .modal-header .modal-title i {
            margin-right: 10px;
        }
        
        .modal-header .close {
            color: white;
            opacity: 0.8;
            font-size: 1.5rem;
        }
        
        .modal-header .close:hover {
            opacity: 1;
        }
        
        .modal-body {
            padding: 0;
            background: #f8f9fc;
        }
        
        .modal-footer {
            border-top: 1px solid #e9ecef;
            padding: 15px 25px;
            background: white;
        }
        
        .btn-close-modal {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 25px;
            border-radius: 8px;
            transition: all 0.2s;
        }
        
        .btn-close-modal:hover {
            background: #5a6268;
        }
        
        /* Stats bar dentro de modales */
        .stats-bar {
            display: flex;
            gap: 20px;
            padding: 20px 25px;
            background: white;
            border-bottom: 1px solid #e9ecef;
            flex-wrap: wrap;
        }
        
        .stat-box {
            flex: 1;
            text-align: center;
            padding: 10px;
            background: #f8f9fc;
            border-radius: 8px;
        }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #003366;
        }
        
        .stat-label {
            font-size: 0.7rem;
            color: #6c757d;
            margin-top: 5px;
        }
        
        /* Search input */
        .modal-search {
            padding: 15px 25px;
            background: white;
            border-bottom: 1px solid #e9ecef;
        }
        
        .modal-search input {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            font-size: 0.9rem;
        }
        
        .modal-search input:focus {
            outline: none;
            border-color: #003366;
        }
        
        /* Grid de cards dentro del modal */
        .modal-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            padding: 25px;
        }
        
        /* Card de cliente */
        .client-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            border-left: 4px solid #17a2b8;
        }
        
        .client-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .client-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .client-avatar {
            width: 50px;
            height: 50px;
            background: #17a2b8;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 20px;
            margin-right: 15px;
        }
        
        .client-name {
            font-size: 1rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .client-date {
            font-size: 0.75rem;
            color: #6c757d;
        }
        
        .client-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #e9ecef;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            font-size: 0.85rem;
            color: #495057;
        }
        
        .info-item i {
            width: 25px;
            color: #17a2b8;
        }
        
        /* Card de producto */
        .product-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            border-left: 4px solid #ffc107;
        }
        
        .product-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .product-header {
            margin-bottom: 15px;
        }
        
        .product-name {
            font-size: 1rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .product-category {
            font-size: 0.75rem;
            color: #ffc107;
        }
        
        .product-stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        
        .stock-info {
            text-align: center;
            flex: 1;
        }
        
        .stock-number {
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .stock-number.critical {
            color: #dc3545;
        }
        
        .stock-number.low {
            color: #ffc107;
        }
        
        .stock-number.normal {
            color: #28a745;
        }
        
        .stock-label {
            font-size: 0.7rem;
            color: #6c757d;
        }
        
        .price-info {
            text-align: center;
            flex: 1;
        }
        
        .price-number {
            font-size: 1.3rem;
            font-weight: 700;
            color: #28a745;
        }
        
        .stock-bar {
            background: #e9ecef;
            border-radius: 10px;
            height: 6px;
            overflow: hidden;
            margin-top: 10px;
        }
        
        .stock-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        
        .stock-fill.critical { background: #dc3545; }
        .stock-fill.low { background: #ffc107; }
        .stock-fill.normal { background: #28a745; }
        
        /* Card de inscripción */
        .inscripcion-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            border-left: 4px solid #28a745;
        }
        
        .inscripcion-card.urgent {
            border-left-color: #ffc107;
            background: #fffef7;
        }
        
        .inscripcion-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .inscripcion-header {
            margin-bottom: 15px;
        }
        
        .cliente-name {
            font-size: 1rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .plan-name {
            font-size: 0.75rem;
            color: #28a745;
        }
        
        .fechas {
            display: flex;
            justify-content: space-between;
            margin: 15px 0;
            padding: 10px 0;
            border-top: 1px solid #e9ecef;
            border-bottom: 1px solid #e9ecef;
        }
        
        .fecha-item {
            text-align: center;
            flex: 1;
        }
        
        .fecha-label {
            font-size: 0.7rem;
            color: #6c757d;
            display: block;
        }
        
        .fecha-value {
            font-size: 0.85rem;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .dias-restantes {
            text-align: center;
            margin-top: 10px;
            padding: 8px;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .dias-restantes.urgent {
            background: #fff3cd;
            color: #856404;
        }
        
        .dias-restantes.normal {
            background: #d4edda;
            color: #155724;
        }
        
        /* Card de clase */
        .clase-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            border-left: 4px solid #dc3545;
        }
        
        .clase-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .clase-header {
            margin-bottom: 15px;
        }
        
        .clase-name {
            font-size: 1rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .instructor-name {
            font-size: 0.75rem;
            color: #dc3545;
        }
        
        .horario {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin: 15px 0;
            padding: 10px;
            background: #f8f9fc;
            border-radius: 8px;
        }
        
        .horario i {
            color: #dc3545;
        }
        
        .horario-text {
            font-size: 0.9rem;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .cupo-info {
            margin-top: 10px;
        }
        
        .cupo-numbers {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            margin-bottom: 8px;
        }
        
        .cupo-bar {
            background: #e9ecef;
            border-radius: 10px;
            height: 6px;
            overflow: hidden;
        }
        
        .cupo-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        
        .cupo-fill.danger { background: #dc3545; }
        .cupo-fill.warning { background: #ffc107; }
        .cupo-fill.success { background: #28a745; }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        /* Estilos para el modal de cambio de contraseña */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 9999;
            display: flex;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px);
        }
        
        .change-password-modal {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 450px;
            padding: 30px;
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .change-password-modal h2 {
            color: #003366;
            font-size: 24px;
            margin-bottom: 10px;
            text-align: center;
        }
        
        .change-password-modal p {
            color: #666;
            text-align: center;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .change-password-modal .form-group {
            margin-bottom: 20px;
        }
        
        .change-password-modal label {
            display: block;
            margin-bottom: 8px;
            color: #003366;
            font-weight: 500;
            font-size: 13px;
        }
        
        .change-password-modal input {
            width: 100%;
            padding: 12px;
            border: 1.5px solid #dde7f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .change-password-modal input:focus {
            outline: none;
            border-color: #003366;
            box-shadow: 0 0 0 3px rgba(0,51,102,0.1);
        }
        
        .change-password-modal .btn-change {
            width: 100%;
            padding: 12px;
            background: #003366;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .change-password-modal .btn-change:hover {
            background: #004080;
        }
        
        .password-requirements {
            font-size: 11px;
            color: #888;
            margin-top: 5px;
        }
        
        .error-message {
            color: #dc2626;
            font-size: 12px;
            margin-top: 5px;
            display: none;
        }
        
        /* Responsive para modales */
        @media (max-width: 768px) {
            .modal-grid {
                grid-template-columns: 1fr;
                padding: 15px;
            }
            
            .stats-bar {
                flex-direction: column;
                gap: 10px;
            }
            
            .stat-box {
                flex: none;
            }
        }

        /* Para igualar la altura de las cards en la misma fila */
.row.equal-height-cards {
    display: flex;
    flex-wrap: wrap;
}

.row.equal-height-cards > [class*='col-'] {
    display: flex;
    flex-direction: column;
}

.row.equal-height-cards .card {
    flex: 1;
    display: flex;
    flex-direction: column;
}

.row.equal-height-cards .card .card-body {
    flex: 1;
}

/* Para la card de Ingresos Mensuales que tiene canvas */
.row.equal-height-cards .card .card-body canvas {
    min-height: 250px;
    height: 100%;
    max-height: 100%;
}
    </style>
    
    <!-- AdminLTE CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
</head>
<body>
    <div class="main-content">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div class="row">
                <div class="col-md-8">
                    <h3>
                        <i class="fas fa-hand-wave"></i> ¡Bienvenido, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!
                    </h3>
                    <p>
                        <i class="fas fa-sign-in-alt"></i> Accediendo al sistema como 
                        <strong><?php echo htmlspecialchars($_SESSION['user_rol']); ?></strong>
                    </p>
                    <p class="access-time">
                        <i class="fas fa-clock"></i> 
                        Último acceso: <?php echo date('d/m/Y H:i:s', $_SESSION['login_time']); ?>
                        <span class="session-timer ml-3">
                            <i class="fas fa-hourglass-half"></i> 
                            Sesión expira en: <span id="session-timer">calculando...</span>
                        </span>
                    </p>
                </div>
                <div class="col-md-4 text-right">
                    <i class="fas fa-chart-line" style="font-size: 60px; opacity: 0.3;"></i>
                    <br>
                    <span class="badge badge-light mt-2">
                        <i class="fas fa-fingerprint"></i> <?php echo $asistencias_hoy; ?> asistencias hoy
                    </span>
                </div>
            </div>
        </div>

        <!-- Small boxes (Stat box) -->
        <div class="row">
            <div class="col-lg-3 col-6">
                <div class="small-box bg-info">
                    <div class="inner">
                        <h3><?php echo $total_clientes; ?></h3>
                        <p>Clientes Registrados</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <a href="javascript:void(0)" onclick="verTodosClientes()" class="small-box-footer">
                        Ver más <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
            
            <div class="col-lg-3 col-6">
                <div class="small-box bg-success">
                    <div class="inner">
                        <h3><?php echo $total_inscripciones; ?></h3>
                        <p>Inscripciones Activas</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-id-card"></i>
                    </div>
                    <a href="javascript:void(0)" onclick="verTodasInscripciones()" class="small-box-footer">
                        Ver más <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
            
            <div class="col-lg-3 col-6">
                <div class="small-box bg-warning">
                    <div class="inner">
                        <h3><?php echo $total_productos; ?></h3>
                        <p>Productos en Stock</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <a href="javascript:void(0)" onclick="verTodosProductos()" class="small-box-footer">
                        Ver más <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
            
            <div class="col-lg-3 col-6">
                <div class="small-box bg-danger">
                    <div class="inner">
                        <h3><?php echo $total_clases; ?></h3>
                        <p>Clases Activas</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <a href="javascript:void(0)" onclick="verTodasClases()" class="small-box-footer">
                        Ver más <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- Charts and Tables Row -->
        <div class="row equal-height-cards">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-chart-line mr-2"></i>
                            Ingresos Mensuales
                        </h3>
                        <div class="card-tools">
                            <span class="badge badge-info">
                                <i class="fas fa-dollar-sign"></i> Total mes: $<?php echo number_format($ingresos_mes, 2); ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <canvas id="incomeChart" style="min-height: 250px; width: 100%; flex: 1;"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-user-plus mr-2"></i>
                            Últimos Clientes Registrados
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0 d-flex flex-column">
                        <div class="table-responsive flex-grow-1">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Nombre</th>
                                        <th>Teléfono</th>
                                        <th>Fecha</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ultimos_clientes as $cliente): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apellido']); ?></td>
                                        <td><?php echo htmlspecialchars($cliente['telefono'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($cliente['fecha_registro'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($ultimos_clientes)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center">No hay clientes registrados</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer text-center">
                        <a href="javascript:void(0)" onclick="verTodosClientes()" class="btn btn-sm btn-primary">Ver todos los clientes</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Second Row -->
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-calendar-check mr-2"></i>
                            Próximas Clases
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Clase</th>
                                        <th>Horario</th>
                                        <th>Instructor</th>
                                        <th>Cupo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($proximas_clases as $clase): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($clase['nombre']); ?></td>
                                        <td><?php echo htmlspecialchars($clase['horario']); ?></td>
                                        <td><?php echo htmlspecialchars($clase['instructor'] ?? 'Por asignar'); ?></td>
                                        <td>
                                            <span class="badge badge-info"><?php echo $clase['cupo_actual']; ?>/<?php echo $clase['cupo_maximo']; ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($proximas_clases)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center">No hay clases programadas</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer text-center">
                        <a href="javascript:void(0)" onclick="verTodasClases()" class="btn btn-sm btn-primary">Ver todas las clases</a>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            Productos con Bajo Stock
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th>Stock Actual</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($productos_bajo_stock as $producto): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($producto['nombre']); ?></td>
                                        <td><?php echo $producto['stock']; ?> unidades</td>
                                        <td>
                                            <?php if ($producto['stock'] <= 5): ?>
                                                <span class="badge badge-danger">Stock Crítico</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning">Stock Bajo</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($productos_bajo_stock)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-success">
                                            <i class="fas fa-check-circle"></i> Todos los productos tienen stock suficiente
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer text-center">
                        <a href="javascript:void(0)" onclick="verTodosProductos()" class="btn btn-sm btn-primary">Ver inventario completo</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alertas de Vencimientos -->
        <?php if ($vencimientos_proximos > 0): ?>
        <div class="row">
            <div class="col-12">
                <div class="card" style="border-left: 4px solid #ffc107;">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-bell mr-2"></i>
                            Inscripciones por Vencer
                        </h3>
                    </div>
                    <div class="card-body">
                        <p class="mb-0">
                            <i class="fas fa-calendar-times"></i> 
                            <strong><?php echo $vencimientos_proximos; ?> inscripciones</strong> están por vencer en los próximos 7 días.
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Actions Row -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-bolt mr-2"></i>
                            Acciones Rápidas
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-2 col-6 mb-3">
                                <a href="inscripciones.php?action=nuevo_cliente" class="btn-app">
                                    <i class="fas fa-user-plus"></i> Nuevo Cliente
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="inscripciones.php?action=create" class="btn-app">
                                    <i class="fas fa-id-card"></i> Nueva Inscripción
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="asistencias.php" class="btn-app">
                                    <i class="fas fa-fingerprint"></i> Registrar Asistencia
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="clases.php?action=create" class="btn-app">
                                    <i class="fas fa-plus-circle"></i> Nueva Clase
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="productos.php?action=create" class="btn-app">
                                    <i class="fas fa-box"></i> Agregar Producto
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="reportes.php" class="btn-app">
                                    <i class="fas fa-chart-bar"></i> Generar Reporte
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Clientes -->
    <div class="modal fade" id="modalClientes" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-users"></i> Clientes Registrados
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="stats-bar">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo count($todos_clientes); ?></div>
                        <div class="stat-label">Total Clientes</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number">
                            <?php 
                                $con_telefono = 0;
                                foreach($todos_clientes as $c) if($c['telefono']) $con_telefono++;
                                echo $con_telefono;
                            ?>
                        </div>
                        <div class="stat-label">Con Teléfono</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number">
                            <?php 
                                $con_email = 0;
                                foreach($todos_clientes as $c) if($c['email']) $con_email++;
                                echo $con_email;
                            ?>
                        </div>
                        <div class="stat-label">Con Email</div>
                    </div>
                </div>
                <div class="modal-search">
                    <input type="text" id="searchClientes" placeholder="🔍 Buscar cliente por nombre, teléfono o email...">
                </div>
                <div class="modal-body">
                    <div class="modal-grid" id="clientesGrid">
                        <?php foreach ($todos_clientes as $cliente): 
                            $inicial = strtoupper(substr($cliente['nombre'], 0, 1) . substr($cliente['apellido'], 0, 1));
                        ?>
                        <div class="client-card" data-name="<?php echo strtolower($cliente['nombre'] . ' ' . $cliente['apellido']); ?>" data-phone="<?php echo $cliente['telefono']; ?>" data-email="<?php echo $cliente['email']; ?>">
                            <div class="client-header">
                                <div class="client-avatar"><?php echo $inicial; ?></div>
                                <div>
                                    <div class="client-name"><?php echo htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apellido']); ?></div>
                                    <div class="client-date"><i class="far fa-calendar-alt"></i> <?php echo date('d/m/Y', strtotime($cliente['fecha_registro'])); ?></div>
                                </div>
                            </div>
                            <div class="client-info">
                                <div class="info-item">
                                    <i class="fas fa-phone"></i>
                                    <span><?php echo htmlspecialchars($cliente['telefono'] ?? 'No registrado'); ?></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-envelope"></i>
                                    <span><?php echo htmlspecialchars($cliente['email'] ?? 'No registrado'); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($todos_clientes)): ?>
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <p>No hay clientes registrados</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-close-modal" data-dismiss="modal">
                        <i class="fas fa-times"></i> Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Productos -->
    <div class="modal fade" id="modalProductos" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-boxes"></i> Inventario de Productos
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="stats-bar">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo count($todos_productos); ?></div>
                        <div class="stat-label">Total Productos</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number">
                            <?php 
                                $stock_bajo = 0;
                                foreach($todos_productos as $p) if($p['stock'] <= $p['stock_minimo']) $stock_bajo++;
                                echo $stock_bajo;
                            ?>
                        </div>
                        <div class="stat-label">Stock Bajo</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number">
                            <?php 
                                $stock_total = 0;
                                foreach($todos_productos as $p) $stock_total += $p['stock'];
                                echo $stock_total;
                            ?>
                        </div>
                        <div class="stat-label">Unidades Totales</div>
                    </div>
                </div>
                <div class="modal-search">
                    <input type="text" id="searchProductos" placeholder="🔍 Buscar producto por nombre o categoría...">
                </div>
                <div class="modal-body">
                    <div class="modal-grid" id="productosGrid">
                        <?php foreach ($todos_productos as $producto): 
                            $stock = $producto['stock'];
                            $minimo = $producto['stock_minimo'];
                            $porcentaje = ($minimo > 0) ? ($stock / $minimo) * 100 : 100;
                            $stock_class = $stock <= 5 ? 'critical' : ($stock <= $minimo ? 'low' : 'normal');
                        ?>
                        <div class="product-card" data-name="<?php echo strtolower($producto['nombre']); ?>" data-category="<?php echo strtolower($producto['categoria'] ?? ''); ?>">
                            <div class="product-header">
                                <div class="product-name"><?php echo htmlspecialchars($producto['nombre']); ?></div>
                                <div class="product-category"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($producto['categoria'] ?? 'Sin categoría'); ?></div>
                            </div>
                            <div class="product-stats">
                                <div class="stock-info">
                                    <div class="stock-number <?php echo $stock_class; ?>"><?php echo $stock; ?></div>
                                    <div class="stock-label">Unidades</div>
                                </div>
                                <div class="price-info">
                                    <div class="price-number">$<?php echo number_format($producto['precio_venta'], 2); ?></div>
                                    <div class="stock-label">Precio Venta</div>
                                </div>
                            </div>
                            <div class="stock-bar">
                                <div class="stock-fill <?php echo $stock_class; ?>" style="width: <?php echo min(100, $porcentaje); ?>%"></div>
                            </div>
                            <div class="info-item" style="margin-top: 10px; font-size: 0.7rem; color: #6c757d;">
                                <i class="fas fa-chart-line"></i> Stock mínimo: <?php echo $minimo; ?> unidades
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($todos_productos)): ?>
                        <div class="empty-state">
                            <i class="fas fa-boxes"></i>
                            <p>No hay productos registrados</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-close-modal" data-dismiss="modal">
                        <i class="fas fa-times"></i> Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Inscripciones -->
    <div class="modal fade" id="modalInscripciones" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-id-card"></i> Inscripciones Activas
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="stats-bar">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo count($todas_inscripciones); ?></div>
                        <div class="stat-label">Inscripciones Activas</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number">
                            <?php 
                                $vencen_7dias = 0;
                                foreach($todas_inscripciones as $ins) {
                                    $fecha_fin = new DateTime($ins['fecha_fin']);
                                    $hoy = new DateTime();
                                    $diff = $hoy->diff($fecha_fin)->days;
                                    if($fecha_fin >= $hoy && $diff <= 7) $vencen_7dias++;
                                }
                                echo $vencen_7dias;
                            ?>
                        </div>
                        <div class="stat-label">Vencen pronto</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number">
                            <?php 
                                $total_ingresos = 0;
                                foreach($todas_inscripciones as $ins) $total_ingresos += $ins['precio_pagado'];
                                echo '$' . number_format($total_ingresos, 0);
                            ?>
                        </div>
                        <div class="stat-label">Ingresos Totales</div>
                    </div>
                </div>
                <div class="modal-search">
                    <input type="text" id="searchInscripciones" placeholder="🔍 Buscar por cliente o plan...">
                </div>
                <div class="modal-body">
                    <div class="modal-grid" id="inscripcionesGrid">
                        <?php foreach ($todas_inscripciones as $inscripcion): 
                            $fecha_fin = new DateTime($inscripcion['fecha_fin']);
                            $hoy = new DateTime();
                            $dias_restantes = $hoy->diff($fecha_fin)->days;
                            if ($fecha_fin < $hoy) $dias_restantes = 0;
                            $es_urgente = ($dias_restantes <= 7 && $dias_restantes > 0);
                        ?>
                        <div class="inscripcion-card <?php echo $es_urgente ? 'urgent' : ''; ?>" data-name="<?php echo strtolower($inscripcion['cliente_nombre'] . ' ' . $inscripcion['cliente_apellido']); ?>" data-plan="<?php echo strtolower($inscripcion['plan_nombre']); ?>">
                            <div class="inscripcion-header">
                                <div class="cliente-name"><?php echo htmlspecialchars($inscripcion['cliente_nombre'] . ' ' . $inscripcion['cliente_apellido']); ?></div>
                                <div class="plan-name"><i class="fas fa-dumbbell"></i> <?php echo htmlspecialchars($inscripcion['plan_nombre']); ?></div>
                            </div>
                            <div class="fechas">
                                <div class="fecha-item">
                                    <span class="fecha-label">Inicio</span>
                                    <span class="fecha-value"><?php echo date('d/m/Y', strtotime($inscripcion['fecha_inicio'])); ?></span>
                                </div>
                                <div class="fecha-item">
                                    <span class="fecha-label">Fin</span>
                                    <span class="fecha-value"><?php echo date('d/m/Y', strtotime($inscripcion['fecha_fin'])); ?></span>
                                </div>
                            </div>
                            <div class="dias-restantes <?php echo $es_urgente ? 'urgent' : 'normal'; ?>">
                                <?php if ($dias_restantes <= 0): ?>
                                    <i class="fas fa-exclamation-circle"></i> Vencida
                                <?php elseif ($dias_restantes <= 7): ?>
                                    <i class="fas fa-clock"></i> Vence en <?php echo $dias_restantes; ?> días
                                <?php else: ?>
                                    <i class="fas fa-calendar-check"></i> Vence en <?php echo $dias_restantes; ?> días
                                <?php endif; ?>
                            </div>
                            <div class="info-item" style="margin-top: 10px; justify-content: center;">
                                <i class="fas fa-dollar-sign" style="color: #28a745;"></i>
                                <span>Pagado: $<?php echo number_format($inscripcion['precio_pagado'], 2); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($todas_inscripciones)): ?>
                        <div class="empty-state">
                            <i class="fas fa-id-card"></i>
                            <p>No hay inscripciones activas</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-close-modal" data-dismiss="modal">
                        <i class="fas fa-times"></i> Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Clases -->
    <div class="modal fade" id="modalClases" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-alt"></i> Clases Disponibles
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="stats-bar">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo count($todas_clases); ?></div>
                        <div class="stat-label">Total Clases</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number">
                            <?php 
                                $cupo_total = 0;
                                $inscritos_total = 0;
                                foreach($todas_clases as $c) {
                                    $cupo_total += $c['cupo_maximo'];
                                    $inscritos_total += $c['cupo_actual'];
                                }
                                $porcentaje_global = $cupo_total > 0 ? round(($inscritos_total / $cupo_total) * 100) : 0;
                                echo $porcentaje_global . '%';
                            ?>
                        </div>
                        <div class="stat-label">Ocupación Global</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number">
                            <?php 
                                $instructores_unicos = [];
                                foreach($todas_clases as $c) if($c['instructor']) $instructores_unicos[$c['instructor']] = true;
                                echo count($instructores_unicos);
                            ?>
                        </div>
                        <div class="stat-label">Instructores</div>
                    </div>
                </div>
                <div class="modal-search">
                    <input type="text" id="searchClases" placeholder="🔍 Buscar clase, instructor o horario...">
                </div>
                <div class="modal-body">
                    <div class="modal-grid" id="clasesGrid">
                        <?php foreach ($todas_clases as $clase): 
                            $porcentaje = ($clase['cupo_maximo'] > 0) ? ($clase['cupo_actual'] / $clase['cupo_maximo']) * 100 : 0;
                            $cupo_class = $porcentaje >= 90 ? 'danger' : ($porcentaje >= 70 ? 'warning' : 'success');
                            $espacios = $clase['cupo_maximo'] - $clase['cupo_actual'];
                        ?>
                        <div class="clase-card" data-name="<?php echo strtolower($clase['nombre']); ?>" data-instructor="<?php echo strtolower($clase['instructor'] ?? ''); ?>" data-horario="<?php echo strtolower($clase['horario']); ?>">
                            <div class="clase-header">
                                <div class="clase-name"><?php echo htmlspecialchars($clase['nombre']); ?></div>
                                <div class="instructor-name"><i class="fas fa-chalkboard-teacher"></i> <?php echo htmlspecialchars($clase['instructor'] ?? 'Por asignar'); ?></div>
                            </div>
                            <div class="horario">
                                <i class="far fa-clock"></i>
                                <span class="horario-text"><?php echo htmlspecialchars($clase['horario']); ?></span>
                                <i class="fas fa-hourglass-half"></i>
                                <span><?php echo $clase['duracion_minutos']; ?> min</span>
                            </div>
                            <div class="cupo-info">
                                <div class="cupo-numbers">
                                    <span>Cupo: <?php echo $clase['cupo_actual']; ?>/<?php echo $clase['cupo_maximo']; ?></span>
                                    <span><?php echo $espacios > 0 ? $espacios . ' lugares libres' : 'Completo'; ?></span>
                                </div>
                                <div class="cupo-bar">
                                    <div class="cupo-fill <?php echo $cupo_class; ?>" style="width: <?php echo $porcentaje; ?>%"></div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($todas_clases)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-alt"></i>
                            <p>No hay clases disponibles</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-close-modal" data-dismiss="modal">
                        <i class="fas fa-times"></i> Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal para cambio de contraseña -->
    <div id="passwordModal" class="modal-overlay" style="display: none;">
        <div class="change-password-modal">
            <h2><i class="fas fa-key"></i> Cambiar Contraseña</h2>
            <p>Por seguridad, debes cambiar tu contraseña de acceso.</p>
            
            <form id="changePasswordForm" method="POST" action="cambiar_password.php">
                <div class="form-group">
                    <label for="new_password">Nueva Contraseña</label>
                    <input type="password" id="new_password" name="new_password" required>
                    <div class="password-requirements">
                        <i class="fas fa-info-circle"></i> Mínimo 6 caracteres
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirmar Contraseña</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                    <div id="passwordError" class="error-message">
                        <i class="fas fa-exclamation-circle"></i> Las contraseñas no coinciden
                    </div>
                </div>
                
                <button type="submit" class="btn-change">
                    <i class="fas fa-save"></i> Cambiar Contraseña
                </button>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>

    <script>
    let alertaMostrada = false;
    let tiempoRestanteInterval;
    
    // Funciones para abrir modales
    function verTodosClientes() {
        $('#modalClientes').modal('show');
        setTimeout(() => {
            document.getElementById('searchClientes').value = '';
            filtrarClientes();
        }, 100);
    }
    
    function verTodosProductos() {
        $('#modalProductos').modal('show');
        setTimeout(() => {
            document.getElementById('searchProductos').value = '';
            filtrarProductos();
        }, 100);
    }
    
    function verTodasInscripciones() {
        $('#modalInscripciones').modal('show');
        setTimeout(() => {
            document.getElementById('searchInscripciones').value = '';
            filtrarInscripciones();
        }, 100);
    }
    
    function verTodasClases() {
        $('#modalClases').modal('show');
        setTimeout(() => {
            document.getElementById('searchClases').value = '';
            filtrarClases();
        }, 100);
    }
    
    // Filtros de búsqueda
    function filtrarClientes() {
        const searchTerm = document.getElementById('searchClientes').value.toLowerCase();
        const cards = document.querySelectorAll('#clientesGrid .client-card');
        
        cards.forEach(card => {
            const name = card.getAttribute('data-name') || '';
            const phone = card.getAttribute('data-phone') || '';
            const email = card.getAttribute('data-email') || '';
            
            if (name.includes(searchTerm) || phone.includes(searchTerm) || email.includes(searchTerm)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    }
    
    function filtrarProductos() {
        const searchTerm = document.getElementById('searchProductos').value.toLowerCase();
        const cards = document.querySelectorAll('#productosGrid .product-card');
        
        cards.forEach(card => {
            const name = card.getAttribute('data-name') || '';
            const category = card.getAttribute('data-category') || '';
            
            if (name.includes(searchTerm) || category.includes(searchTerm)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    }
    
    function filtrarInscripciones() {
        const searchTerm = document.getElementById('searchInscripciones').value.toLowerCase();
        const cards = document.querySelectorAll('#inscripcionesGrid .inscripcion-card');
        
        cards.forEach(card => {
            const name = card.getAttribute('data-name') || '';
            const plan = card.getAttribute('data-plan') || '';
            
            if (name.includes(searchTerm) || plan.includes(searchTerm)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    }
    
    function filtrarClases() {
        const searchTerm = document.getElementById('searchClases').value.toLowerCase();
        const cards = document.querySelectorAll('#clasesGrid .clase-card');
        
        cards.forEach(card => {
            const name = card.getAttribute('data-name') || '';
            const instructor = card.getAttribute('data-instructor') || '';
            const horario = card.getAttribute('data-horario') || '';
            
            if (name.includes(searchTerm) || instructor.includes(searchTerm) || horario.includes(searchTerm)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    }
    
    // Event listeners para búsqueda
    document.addEventListener('DOMContentLoaded', function() {
        const searchClientes = document.getElementById('searchClientes');
        if (searchClientes) searchClientes.addEventListener('keyup', filtrarClientes);
        
        const searchProductos = document.getElementById('searchProductos');
        if (searchProductos) searchProductos.addEventListener('keyup', filtrarProductos);
        
        const searchInscripciones = document.getElementById('searchInscripciones');
        if (searchInscripciones) searchInscripciones.addEventListener('keyup', filtrarInscripciones);
        
        const searchClases = document.getElementById('searchClases');
        if (searchClases) searchClases.addEventListener('keyup', filtrarClases);
    });
    
    // Función para actualizar el temporizador de sesión
    function actualizarTemporizador() {
        const loginTime = <?php echo $_SESSION['login_time']; ?> * 1000;
        const maxSessionTime = 12 * 3600 * 1000;
        const now = new Date().getTime();
        const elapsed = now - loginTime;
        const remaining = maxSessionTime - elapsed;
        
        if (remaining > 0) {
            const hours = Math.floor(remaining / (3600 * 1000));
            const minutes = Math.floor((remaining % (3600 * 1000)) / (60 * 1000));
            const seconds = Math.floor((remaining % (60 * 1000)) / 1000);
            
            const timerElement = document.getElementById('session-timer');
            if (timerElement) {
                timerElement.textContent = `${hours}h ${minutes}m ${seconds}s`;
                
                if (remaining <= 30 * 60 * 1000 && !alertaMostrada) {
                    alertaMostrada = true;
                    Swal.fire({
                        icon: 'warning',
                        title: 'Sesión próxima a expirar',
                        html: `Tu sesión expirará en <strong>${hours}h ${minutes}m ${seconds}s</strong><br><br>Por seguridad, la sesión se cerrará automáticamente después de 12 horas.`,
                        confirmButtonColor: '#ff6b6b',
                        confirmButtonText: 'Continuar',
                        timer: 10000,
                        timerProgressBar: true
                    });
                }
            }
        } else {
            clearInterval(tiempoRestanteInterval);
            Swal.fire({
                icon: 'info',
                title: 'Sesión expirada',
                text: 'Tu sesión ha expirado después de 12 horas. Serás redirigido al inicio de sesión.',
                confirmButtonColor: '#ff6b6b',
                confirmButtonText: 'Aceptar',
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then(() => {
                window.location.href = 'logout.php';
            });
        }
    }
    
    // Detectar inactividad del usuario
    let inactivityTimer;
    const maxInactivityTime = 12 * 3600 * 1000;
    
    function resetInactivityTimer() {
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(() => {
            Swal.fire({
                icon: 'warning',
                title: 'Sesión expirada por inactividad',
                text: 'Has estado inactivo por 12 horas. Tu sesión se cerrará por seguridad.',
                confirmButtonColor: '#ff6b6b',
                confirmButtonText: 'Aceptar',
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then(() => {
                window.location.href = 'logout.php';
            });
        }, maxInactivityTime);
    }
    
    // Función para mostrar el modal de cambio de contraseña
    function showPasswordModal() {
        const modal = document.getElementById('passwordModal');
        if (modal) {
            modal.style.display = 'flex';
        }
    }
    
    // Gráfico de ingresos mensuales
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('incomeChart').getContext('2d');
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [{
                    label: 'Ingresos ($)',
                    data: <?php echo json_encode($datos); ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#3b82f6'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return '$' + context.raw.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        
        // Mostrar alerta de bienvenida
        const alertaMostradaStorage = sessionStorage.getItem('welcomeAlertShown_' + <?php echo $_SESSION['user_id']; ?>);
        
        if (!alertaMostradaStorage) {
            <?php if ($require_password_change): ?>
            Swal.fire({
                icon: 'info',
                title: '¡Bienvenido al Sistema!',
                html: `
                    <div style="text-align: center; padding: 10px;">
                        <h3 style="color: #003366; margin-bottom: 15px;">¡Hola, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</h3>
                        <p style="font-size: 16px; margin-bottom: 10px;">
                            <i class="fas fa-shield-alt"></i> <strong>Por seguridad, necesitas cambiar tu contraseña</strong>
                        </p>
                        <p style="font-size: 14px; color: #666;">
                            La contraseña actual es temporal y debe ser actualizada.
                        </p>
                    </div>
                `,
                confirmButtonColor: '#003366',
                confirmButtonText: 'Continuar',
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then(() => {
                sessionStorage.setItem('welcomeAlertShown_' + <?php echo $_SESSION['user_id']; ?>, 'true');
                showPasswordModal();
            });
            <?php else: ?>
            Swal.fire({
                icon: 'success',
                title: '¡Bienvenido al Sistema!',
                html: `
                    <div style="text-align: center; padding: 10px;">
                        <h3 style="color: #28a745; margin-bottom: 15px;">¡Hola, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</h3>
                        <p style="font-size: 16px; margin-bottom: 10px;">
                            <i class="fas fa-sign-in-alt"></i> <strong>Accediendo al sistema como</strong><br>
                            <span style="color: #ff6b6b; font-size: 18px;"><?php echo htmlspecialchars($_SESSION['user_rol']); ?></span>
                        </p>
                    </div>
                `,
                showConfirmButton: false,
                timer: 2000,
                timerProgressBar: true,
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then(() => {
                sessionStorage.setItem('welcomeAlertShown_' + <?php echo $_SESSION['user_id']; ?>, 'true');
            });
            <?php endif; ?>
        } else if (<?php echo $require_password_change ? 'true' : 'false'; ?>) {
            showPasswordModal();
        }
        
        // Iniciar temporizadores
        <?php if (!$require_password_change): ?>
        actualizarTemporizador();
        tiempoRestanteInterval = setInterval(actualizarTemporizador, 1000);
        
        const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
        events.forEach(event => {
            document.addEventListener(event, resetInactivityTimer);
        });
        resetInactivityTimer();
        <?php endif; ?>
    });
    
    // Validación del formulario de cambio de contraseña
    document.getElementById('changePasswordForm')?.addEventListener('submit', function(e) {
        const newPassword = document.getElementById('new_password').value;
        const confirmPassword = document.getElementById('confirm_password').value;
        const errorDiv = document.getElementById('passwordError');
        
        if (newPassword.length < 6) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Contraseña muy corta',
                text: 'La contraseña debe tener al menos 6 caracteres',
                confirmButtonColor: '#003366'
            });
            return false;
        }
        
        if (newPassword !== confirmPassword) {
            e.preventDefault();
            errorDiv.style.display = 'block';
            return false;
        }
        
        errorDiv.style.display = 'none';
    });
    </script>
</body>
</html>