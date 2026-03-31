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

// Últimos 5 clientes registrados
$query = "SELECT id, nombre, apellido, telefono, email, fecha_registro FROM clientes ORDER BY fecha_registro DESC LIMIT 5";
$result = $db->query($query);
$ultimos_clientes = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $ultimos_clientes[] = $row;
    }
}

// Próximas clases
$query = "SELECT c.id, c.nombre, c.horario, u.nombre as instructor, c.capacidad_maxima, 
          (SELECT COUNT(*) FROM clase_inscripciones WHERE clase_id = c.id AND estado = 'activa') as inscritos 
          FROM clases c 
          LEFT JOIN usuarios u ON c.instructor_id = u.id 
          WHERE c.estado = 'activa' 
          ORDER BY c.horario ASC 
          LIMIT 5";
$result = $db->query($query);
$proximas_clases = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $proximas_clases[] = $row;
    }
}

// Productos con bajo stock
$query = "SELECT id, nombre, stock, stock_minimo FROM productos WHERE stock <= stock_minimo AND estado = 'activo' ORDER BY stock ASC LIMIT 5";
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
        /* Estilos del dashboard */
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
            border-radius: 16px;
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
            border-radius: 10px;
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
            background: linear-gradient(135deg, #003366, #0047b3);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .change-password-modal .btn-change:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,51,102,0.3);
        }
        
        .change-password-modal .btn-change:active {
            transform: translateY(0);
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
        
        .error-message i {
            margin-right: 5px;
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
                    <a href="clientes.php" class="small-box-footer">
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
                    <a href="inscripciones.php" class="small-box-footer">
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
                    <a href="productos.php" class="small-box-footer">
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
                    <a href="clases.php" class="small-box-footer">
                        Ver más <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- Charts and Tables Row -->
        <div class="row">
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
                    <div class="card-body">
                        <canvas id="incomeChart" style="min-height: 250px; height: 250px; max-height: 250px; max-width: 100%;"></canvas>
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
                    <div class="card-body p-0">
                        <table class="table table-striped">
                            <thead>
                                葩
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
                    <div class="card-footer text-center">
                        <a href="clientes.php" class="btn btn-sm btn-primary">Ver todos los clientes</a>
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
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Clase</th>
                                    <th>Horario</th>
                                    <th>Instructor</th>
                                    <th>Inscritos</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($proximas_clases as $clase): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($clase['nombre']); ?></td>
                                    <td><?php echo date('H:i', strtotime($clase['horario'])); ?></td>
                                    <td><?php echo htmlspecialchars($clase['instructor'] ?? 'Por asignar'); ?></td>
                                    <td>
                                        <span class="badge badge-info"><?php echo $clase['inscritos']; ?>/<?php echo $clase['capacidad_maxima']; ?></span>
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
                    <div class="card-footer text-center">
                        <a href="clases.php" class="btn btn-sm btn-primary">Ver todas las clases</a>
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
                    <div class="card-footer text-center">
                        <a href="productos.php" class="btn btn-sm btn-primary">Ver inventario completo</a>
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
                            <a href="inscripciones.php?renovaciones=1" class="btn btn-sm btn-warning ml-3">Renovar ahora</a>
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
                                <a href="clientes.php?action=create" class="btn btn-app">
                                    <i class="fas fa-user-plus"></i> Nuevo Cliente
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="inscripciones.php?action=create" class="btn btn-app">
                                    <i class="fas fa-id-card"></i> Nueva Inscripción
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="asistencias.php" class="btn btn-app">
                                    <i class="fas fa-fingerprint"></i> Registrar Asistencia
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="clases.php?action=create" class="btn btn-app">
                                    <i class="fas fa-plus-circle"></i> Nueva Clase
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="productos.php?action=create" class="btn btn-app">
                                    <i class="fas fa-box"></i> Agregar Producto
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="reportes.php" class="btn btn-app">
                                    <i class="fas fa-chart-bar"></i> Generar Reporte
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal para cambio de contraseña (se crea pero se muestra después del Swal) -->
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
        
        // Mostrar alerta de bienvenida SIEMPRE al iniciar sesión
        const alertaMostradaStorage = sessionStorage.getItem('welcomeAlertShown_' + <?php echo $_SESSION['user_id']; ?>);
        
        if (!alertaMostradaStorage) {
            // Si requiere cambio de contraseña, mostrar mensaje especial y después el modal
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
                // Después de cerrar el Swal, mostrar el modal de cambio de contraseña
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
            // Si ya se mostró la alerta pero requiere cambio de contraseña, mostrar modal
            showPasswordModal();
        }
        
        // Iniciar temporizadores (si no requiere cambio de contraseña o después del modal)
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