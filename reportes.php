<?php
// reportes.php
session_start();
require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

if (!$conn) {
    die("Error: No se pudo establecer la conexión a la base de datos");
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$usuario_id = $_SESSION['user_id'];
$usuario_nombre = $_SESSION['user_name'];
$usuario_rol = $_SESSION['user_rol'];

// Obtener estadísticas generales
$stats_query = "SELECT 
    COUNT(DISTINCT i.id) as total_inscripciones,
    COALESCE(SUM(i.precio_pagado), 0) as total_ingresos,
    COUNT(DISTINCT c.id) as total_clientes_activos
    FROM inscripciones i
    INNER JOIN clientes c ON i.cliente_id = c.id
    WHERE i.estado = 'activa'";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

// Inscripciones por plan
$planes_query = "SELECT p.nombre, COUNT(i.id) as total, COALESCE(SUM(i.precio_pagado), 0) as ingresos
    FROM planes p
    LEFT JOIN inscripciones i ON p.id = i.plan_id AND i.estado = 'activa'
    WHERE p.estado = 'activo'
    GROUP BY p.id";
$planes_result = $conn->query($planes_query);

// Obtener planes para el filtro
$planes_list = $conn->query("SELECT id, nombre FROM planes WHERE estado = 'activo'");

// Obtener estadísticas adicionales
$vencidas = $conn->query("SELECT COUNT(*) as total FROM inscripciones WHERE estado = 'vencida'")->fetch_assoc();
$canceladas = $conn->query("SELECT COUNT(*) as total FROM inscripciones WHERE estado = 'cancelada'")->fetch_assoc();
$promedio = $conn->query("SELECT AVG(precio_pagado) as promedio FROM inscripciones WHERE estado = 'activa'")->fetch_assoc();
$total_clientes = $conn->query("SELECT COUNT(*) as total FROM clientes WHERE estado = 'activo'")->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes - Sistema Gimnasio</title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- DateRangePicker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">
    <style>
        /* Reset básico */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #f5f7fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }
        
        /* Contenido principal - Se adapta al sidebar */
        .main-content {
            margin-left: 280px;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            min-height: 100vh;
            padding: 20px;
            background: #f5f7fa;
        }
        
        /* Cuando el sidebar está colapsado */
        body.sidebar-collapsed .main-content {
            margin-left: 70px;
        }
        
        /* Responsive para móvil */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0 !important;
                padding: 80px 15px 15px 15px;
            }
        }
        
        /* Estilos para las tarjetas de estadísticas */
        .small-box {
            border-radius: 10px;
            transition: transform 0.2s;
            margin-bottom: 20px;
            cursor: default;
            position: relative;
            display: block;
            padding: 20px;
            color: white;
        }
        
        .small-box:hover {
            transform: translateY(-3px);
        }
        
        .small-box .inner {
            position: relative;
            z-index: 1;
        }
        
        .small-box h3 {
            font-size: 2rem;
            font-weight: bold;
            margin: 0 0 10px 0;
            white-space: nowrap;
            padding: 0;
        }
        
        .small-box p {
            font-size: 1rem;
            margin: 0;
        }
        
        .small-box .icon {
            position: absolute;
            right: 15px;
            top: 15px;
            font-size: 3.5rem;
            opacity: 0.3;
            z-index: 0;
        }
        
        .bg-primary { background-color: #007bff !important; }
        .bg-success { background-color: #28a745 !important; }
        .bg-warning { background-color: #ffc107 !important; }
        .bg-info { background-color: #17a2b8 !important; }
        
        /* Tarjetas de resumen general - CORREGIDO */
        .resumen-card {
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            margin-bottom: 15px;
            background: white;
            transition: transform 0.2s;
            border: 1px solid #e9ecef;
            overflow: hidden;
        }
        
        .resumen-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.12);
        }
        
        .resumen-card .card-body {
            padding: 15px;
        }
        
        .resumen-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .resumen-number {
            font-size: 24px;
            font-weight: bold;
            color: #333;
            line-height: 1.2;
        }
        
        .resumen-label {
            color: #666;
            font-size: 12px;
            margin-top: 5px;
        }
        
        .resumen-icon {
            font-size: 40px;
            opacity: 0.3;
        }
        
        /* Filtros */
        .filter-card {
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            background: white;
        }
        
        .filter-card .card-header {
            background: white;
            border-bottom: 1px solid #e9ecef;
            padding: 15px 20px;
        }
        
        .filter-card .card-body {
            padding: 20px;
        }
        
        .filter-card .form-group {
            margin-bottom: 0;
        }
        
        .filter-card label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #495057;
            font-size: 13px;
        }
        
        .form-control {
            border-radius: 8px;
            border: 1px solid #e0e0e0;
            padding: 8px 12px;
            font-size: 14px;
            width: 100%;
        }
        
        .form-control:focus {
            border-color: #1e3a8a;
            box-shadow: 0 0 0 0.2rem rgba(30,58,138,0.25);
            outline: none;
        }
        
        /* Tabla */
        .card {
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            background: white;
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #e9ecef;
            padding: 15px 20px;
        }
        
        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0;
        }
        
        .table-responsive {
            border-radius: 10px;
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            margin-bottom: 0;
            background-color: transparent;
        }
        
        .table thead th {
            background: #1e3a8a;
            color: white;
            font-weight: 500;
            border: none;
            font-size: 13px;
            padding: 12px;
        }
        
        .table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .table td {
            padding: 10px 12px;
            vertical-align: middle;
            font-size: 13px;
            border-top: 1px solid #dee2e6;
        }
        
        .badge-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .bg-success { background-color: #28a745 !important; }
        .bg-danger { background-color: #dc3545 !important; }
        .bg-secondary { background-color: #6c757d !important; }
        .bg-info { background-color: #17a2b8 !important; }
        .bg-warning { background-color: #ffc107 !important; }
        .text-white { color: white !important; }
        .text-success { color: #28a745 !important; }
        .text-muted { color: #6c757d !important; }
        
        .btn-export {
            border-radius: 8px;
            padding: 8px 18px;
            transition: all 0.2s;
            margin-right: 10px;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-export:hover {
            transform: translateY(-2px);
        }
        
        .btn-success {
            background-color: #28a745;
            color: white;
        }
        
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
        }
        
        .btn-secondary:hover {
            background-color: #5a6268;
        }
        
        .btn-tool {
            background: transparent;
            border: none;
            cursor: pointer;
            color: #6c757d;
        }
        
        .loading-spinner {
            text-align: center;
            padding: 40px;
        }
        
        .fa-spinner {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .text-center {
            text-align: center;
        }
        
        .py-5 {
            padding-top: 3rem;
            padding-bottom: 3rem;
        }
        
        .mb-0 {
            margin-bottom: 0;
        }
        
        .mb-2 {
            margin-bottom: 0.5rem;
        }
        
        .mb-3 {
            margin-bottom: 1rem;
        }
        
        .mt-2 {
            margin-top: 0.5rem;
        }
        
        .mt-3 {
            margin-top: 1rem;
        }
        
        .mr-2 {
            margin-right: 0.5rem;
        }
        
        .float-right {
            float: right;
        }
        
        .row {
            display: flex;
            flex-wrap: wrap;
            margin-right: -10px;
            margin-left: -10px;
        }
        
        .col-lg-3, .col-md-6, .col-sm-6, .col-md-4, .col-md-3, .col-12, .col-6 {
            position: relative;
            width: 100%;
            padding-right: 10px;
            padding-left: 10px;
        }
        
        .col-6 { flex: 0 0 50%; max-width: 50%; }
        .col-12 { flex: 0 0 100%; max-width: 100%; }
        
        @media (min-width: 768px) {
            .col-md-3 { flex: 0 0 25%; max-width: 25%; }
            .col-md-4 { flex: 0 0 33.333333%; max-width: 33.333333%; }
            .col-md-6 { flex: 0 0 50%; max-width: 50%; }
        }
        
        @media (min-width: 992px) {
            .col-lg-3 { flex: 0 0 25%; max-width: 25%; }
        }
        
        .h-100 {
            height: 100%;
        }
        
        .p-0 {
            padding: 0;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-danger {
            color: #dc3545;
        }
        
        .font-weight-bold {
            font-weight: bold;
        }
        
        /* Grid para resumen general */
        .grid-resumen {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        @media (min-width: 768px) {
            .grid-resumen {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        .full-width {
            grid-column: span 2;
        }

        /* Estilos para los botones del DateRangePicker */
        .daterangepicker .drp-buttons .btn-default {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            color: white;
            border: none;
            border-radius: 6px;
            padding: 6px 15px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .daterangepicker .drp-buttons .btn-default:hover {
            background: linear-gradient(135deg, #5a6268 0%, #343a40 100%);
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .daterangepicker .drp-buttons .btn-primary {
            background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
            color: white;
            border: none;
            border-radius: 6px;
            padding: 6px 20px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .daterangepicker .drp-buttons .btn-primary:hover {
            background: linear-gradient(135deg, #218838 0%, #166b2a 100%);
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }

        .daterangepicker .drp-buttons {
            border-top: 1px solid #e9ecef;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 0 0 8px 8px;
        }

        .daterangepicker .drp-buttons .btn {
            margin-left: 10px;
        }
        
        /* Estilo para tabla más ancha */
        .table th, .table td {
            white-space: nowrap;
        }

        /* Estilos para paginacion */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            background: white;
            border-top: 1px solid #e9ecef;
            flex-wrap: wrap;
            gap: 10px;
        }

        .pagination {
            display: flex;
            gap: 5px;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .pagination li {
            display: inline-block;
        }

        .pagination button {
            padding: 6px 12px;
            border: 1px solid #dee2e6;
            background: white;
            color: #1e3a8a;
            cursor: pointer;
            border-radius: 6px;
            transition: all 0.2s;
            font-size: 13px;
        }

        .pagination button:hover:not(:disabled) {
            background: #1e3a8a;
            color: white;
            border-color: #1e3a8a;
        }

        .pagination button.active {
            background: #1e3a8a;
            color: white;
            border-color: #1e3a8a;
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-info {
            color: #6c757d;
            font-size: 13px;
        }

        .records-per-page {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .records-per-page select {
            padding: 5px 8px;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            font-size: 13px;
        }

        .sort-icon {
            margin-left: 5px;
            font-size: 11px;
            opacity: 0.6;
        }

        th.sortable {
            cursor: pointer;
            user-select: none;
            transition: background 0.2s;
        }

        th.sortable:hover {
            background: #2d4a9e !important;
        }

        th.sortable i {
            margin-left: 5px;
            font-size: 11px;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Content Header -->
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1 class="m-0">
                    Reportes del Sistema
                </h1>
            </div>
        </div>

        <!-- Alertas -->
        <?php if(isset($_SESSION['mensaje_exito'])): ?>
        <div class="alert alert-success" style="background-color: #d4edda; color: #155724; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
            <?php echo $_SESSION['mensaje_exito']; unset($_SESSION['mensaje_exito']); ?>
            <button type="button" style="float: right; background: transparent; border: none; font-size: 20px; cursor: pointer;" onclick="this.parentElement.style.display='none';">×</button>
        </div>
        <?php endif; ?>

        <!-- Stats Cards Principales -->
        <div class="row">
            <div class="col-md-4 col-sm-6 col-12">
                <div class="small-box bg-primary">
                    <div class="inner">
                        <h3><?php echo number_format($stats['total_clientes_activos'] ?? 0); ?></h3>
                        <p>Clientes Activos</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 col-12">
                <div class="small-box bg-success">
                    <div class="inner">
                        <h3><?php echo number_format($stats['total_inscripciones'] ?? 0); ?></h3>
                        <p>Inscripciones Activas</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-file-signature"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6 col-12">
                <div class="small-box bg-warning">
                    <div class="inner">
                        <h3>$<?php echo number_format($stats['total_ingresos'] ?? 0, 2); ?></h3>
                        <p>Ingresos Totales</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Inscripciones por Plan y Resumen General -->
        <div class="row">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-chart-pie mr-2"></i> Inscripciones por Plan
                        </h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Plan</th>
                                        <th class="text-center">Inscripciones</th>
                                        <th class="text-right">Ingresos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $planes_result->data_seek(0);
                                    while($plan = $planes_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($plan['nombre']); ?></strong></td>
                                        <td class="text-center">
                                            <span class="badge-status bg-info text-white"><?php echo number_format($plan['total'] ?? 0); ?></span>
                                        </td>
                                        <td class="text-right text-success">
                                            <strong>$<?php echo number_format($plan['ingresos'] ?? 0, 2); ?></strong>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-chart-simple mr-2"></i> Resumen General
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="grid-resumen">
                            <div class="resumen-card">
                                <div class="card-body">
                                    <div class="resumen-stats">
                                        <div>
                                            <div class="resumen-number"><?php echo $planes_list->num_rows; ?></div>
                                            <div class="resumen-label">Planes Activos</div>
                                        </div>
                                        <div class="resumen-icon">
                                            <i class="fas fa-tags text-primary"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="resumen-card">
                                <div class="card-body">
                                    <div class="resumen-stats">
                                        <div>
                                            <div class="resumen-number"><?php echo number_format($total_clientes['total']); ?></div>
                                            <div class="resumen-label">Total Clientes</div>
                                        </div>
                                        <div class="resumen-icon">
                                            <i class="fas fa-user-friends text-success"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="resumen-card">
                                <div class="card-body">
                                    <div class="resumen-stats">
                                        <div>
                                            <div class="resumen-number"><?php echo number_format($vencidas['total']); ?></div>
                                            <div class="resumen-label">Inscripciones Vencidas</div>
                                        </div>
                                        <div class="resumen-icon">
                                            <i class="fas fa-clock text-warning"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="resumen-card">
                                <div class="card-body">
                                    <div class="resumen-stats">
                                        <div>
                                            <div class="resumen-number"><?php echo number_format($canceladas['total']); ?></div>
                                            <div class="resumen-label">Inscripciones Canceladas</div>
                                        </div>
                                        <div class="resumen-icon">
                                            <i class="fas fa-times-circle text-danger"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <br>
        <!-- Filtros en Tiempo Real -->
        <div class="card filter-card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-filter text-primary mr-2"></i> Filtros de Búsqueda
                </h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label><i class="fas fa-search"></i> Buscador Rápido</label>
                            <input type="text" class="form-control" id="searchInput" 
                                   placeholder="Nombre, teléfono, email...">
                            <small style="font-size: 11px; color: #6c757d; margin-top: 5px; display: block;">Búsqueda en tiempo real</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Plan</label>
                            <select class="form-control" id="planSelect">
                                <option value="">Todos los planes</option>
                                <?php 
                                $planes_list->data_seek(0);
                                while($plan = $planes_list->fetch_assoc()): ?>
                                <option value="<?php echo $plan['id']; ?>">
                                    <?php echo htmlspecialchars($plan['nombre']); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label><i class="fas fa-circle-info"></i> Estado</label>
                            <select class="form-control" id="estadoSelect">
                                <option value="">Todos los estados</option>
                                <option value="activa">Activa</option>
                                <option value="vencida">Vencida</option>
                                <option value="cancelada">Cancelada</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt"></i> Rango de Fechas</label>
                            <input type="text" class="form-control" id="fechaRango" placeholder="Seleccionar rango">
                            <small style="font-size: 11px; color: #6c757d; margin-top: 5px; display: block;">Filtra por fecha de inicio</small>
                        </div>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12 text-right">
                        <button class="btn-secondary" id="limpiarFiltros" style="padding: 6px 12px; border-radius: 6px; border: none; cursor: pointer;">
                            <i class="fas fa-eraser"></i> Limpiar filtros
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Botones de Exportación -->
        <div class="row mb-3">
            <div class="col-12">
                <button class="btn-success btn-export" onclick="exportarExcel()">
                    <i class="fas fa-file-excel"></i> Exportar a Excel
                </button>
                <button class="btn-danger btn-export" onclick="exportarPDF()">
                    <i class="fas fa-file-pdf"></i> Exportar a PDF
                </button>
            </div>
        </div>

        <!-- Tabla de Inscripciones -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-list mr-2"></i> Listado de Inscripciones
                </h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover" id="tablaReporte">
                        <thead>
                            <tr>
                                <th class="sortable" data-column="cliente">Cliente <i class="fas fa-sort sort-icon"></i></th>
                                <th class="sortable" data-column="contacto">Contacto <i class="fas fa-sort sort-icon"></i></th>
                                <th class="sortable" data-column="plan">Plan <i class="fas fa-sort sort-icon"></i></th>
                                <th class="sortable" data-column="fecha_inicio">Fecha Inicio <i class="fas fa-sort sort-icon"></i></th>
                                <th class="sortable" data-column="fecha_fin">Fecha Fin <i class="fas fa-sort sort-icon"></i></th>
                                <th class="sortable" data-column="dias">Días Restantes <i class="fas fa-sort sort-icon"></i></th>
                                <th class="sortable" data-column="precio">Precio <i class="fas fa-sort sort-icon"></i></th>
                                <th class="sortable" data-column="estado">Estado <i class="fas fa-sort sort-icon"></i></th>
                            </tr>
                        </thead>
                        <tbody id="tablaBody">
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <div class="loading-spinner">
                                        <i class="fas fa-spinner fa-3x text-primary"></i>
                                        <p class="mt-2">Cargando datos...</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="pagination-container" id="paginationContainer" style="display: none;">
                    <div class="records-per-page">
                        <span>Mostrar:</span>
                        <select id="recordsPerPage">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        <span>registros</span>
                    </div>
                    <div class="pagination-info" id="paginationInfo"></div>
                    <ul class="pagination" id="pagination"></ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/moment@2.29.4/moment.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>

    <script>
        let timeoutBusqueda;
        let fechaInicio = '';
        let fechaFin = '';
        
        // Variables para paginacion y ordenamiento
        let datosCompletos = [];
        let currentPage = 1;
        let recordsPerPage = 25;
        let sortColumn = 'cliente';
        let sortOrder = 'asc';
        
        // Inicializar DateRangePicker
        $(document).ready(function() {
            $('#fechaRango').daterangepicker({
                autoUpdateInput: false,
                locale: {
                    cancelLabel: 'Limpiar',
                    applyLabel: 'Aplicar',
                    format: 'YYYY-MM-DD',
                    daysOfWeek: ['Do', 'Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sa'],
                    monthNames: ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre']
                },
                opens: 'center'
            });
            
            $('#fechaRango').on('apply.daterangepicker', function(ev, picker) {
                $(this).val(picker.startDate.format('YYYY-MM-DD') + ' - ' + picker.endDate.format('YYYY-MM-DD'));
                fechaInicio = picker.startDate.format('YYYY-MM-DD');
                fechaFin = picker.endDate.format('YYYY-MM-DD');
                cargarDatos();
            });
            
            $('#fechaRango').on('cancel.daterangepicker', function(ev, picker) {
                $(this).val('');
                fechaInicio = '';
                fechaFin = '';
                cargarDatos();
            });
            
            // Eventos de ordenamiento
            $('.sortable').on('click', function() {
                let column = $(this).data('column');
                if (sortColumn === column) {
                    sortOrder = sortOrder === 'asc' ? 'desc' : 'asc';
                } else {
                    sortColumn = column;
                    sortOrder = 'asc';
                }
                
                // Actualizar iconos
                $('.sortable i').removeClass('fa-sort-up fa-sort-down').addClass('fa-sort');
                let icon = $(this).find('i');
                icon.removeClass('fa-sort');
                icon.addClass(sortOrder === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
                
                currentPage = 1;
                renderTabla();
            });
            
            // Cambiar registros por pagina
            $('#recordsPerPage').on('change', function() {
                recordsPerPage = parseInt($(this).val());
                currentPage = 1;
                renderTabla();
            });
            
            // Cargar datos iniciales
            cargarDatos();
            
            // Filtros en tiempo real
            $('#searchInput, #planSelect, #estadoSelect').on('input change', function() {
                clearTimeout(timeoutBusqueda);
                timeoutBusqueda = setTimeout(function() {
                    cargarDatos();
                }, 500);
            });
        });
        
        function cargarDatos() {
            const search = $('#searchInput').val();
            const plan = $('#planSelect').val();
            const estado = $('#estadoSelect').val();
            
            $.ajax({
                url: 'ajax_reporte_inscripciones.php',
                method: 'GET',
                data: { 
                    search: search, 
                    plan: plan, 
                    estado: estado,
                    fecha_inicio: fechaInicio,
                    fecha_fin: fechaFin
                },
                dataType: 'json',
                success: function(response) {
                    if(response.success) {
                        datosCompletos = response.datos;
                        currentPage = 1;
                        renderTabla();
                        $('#paginationContainer').show();
                    } else {
                        console.error('Error:', response.message);
                        mostrarError('Error al cargar los datos');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error AJAX:', error);
                    mostrarError('Error de conexión al servidor');
                }
            });
        }
        
        function ordenarDatos(datos) {
            return datos.sort((a, b) => {
                let valA, valB;
                
                switch(sortColumn) {
                    case 'cliente':
                        valA = (a.cliente_nombre + ' ' + a.cliente_apellido).toLowerCase();
                        valB = (b.cliente_nombre + ' ' + b.cliente_apellido).toLowerCase();
                        break;
                    case 'contacto':
                        valA = (a.email || '').toLowerCase();
                        valB = (b.email || '').toLowerCase();
                        break;
                    case 'plan':
                        valA = (a.plan_nombre || '').toLowerCase();
                        valB = (b.plan_nombre || '').toLowerCase();
                        break;
                    case 'fecha_inicio':
                        valA = a.fecha_inicio || '';
                        valB = b.fecha_inicio || '';
                        break;
                    case 'fecha_fin':
                        valA = a.fecha_fin || '';
                        valB = b.fecha_fin || '';
                        break;
                    case 'dias':
                        valA = a.dias_restantes !== null ? parseInt(a.dias_restantes) : -1;
                        valB = b.dias_restantes !== null ? parseInt(b.dias_restantes) : -1;
                        break;
                    case 'precio':
                        valA = parseFloat(a.precio_pagado) || 0;
                        valB = parseFloat(b.precio_pagado) || 0;
                        break;
                    case 'estado':
                        valA = (a.estado || '').toLowerCase();
                        valB = (b.estado || '').toLowerCase();
                        break;
                    default:
                        valA = (a.cliente_nombre + ' ' + a.cliente_apellido).toLowerCase();
                        valB = (b.cliente_nombre + ' ' + b.cliente_apellido).toLowerCase();
                }
                
                if (valA < valB) return sortOrder === 'asc' ? -1 : 1;
                if (valA > valB) return sortOrder === 'asc' ? 1 : -1;
                return 0;
            });
        }
        
        function renderTabla() {
            if(datosCompletos.length === 0) {
                let tbody = '<tr><td colspan="8" class="text-center py-5">' +
                            '<i class="fas fa-inbox fa-3x text-muted mb-3"></i>' +
                            '<p class="mb-0">No hay inscripciones que coincidan con los filtros</p>' +
                            '</td></tr>';
                $('#tablaBody').html(tbody);
                $('#paginationContainer').hide();
                return;
            }
            
            // Ordenar datos
            let datosOrdenados = ordenarDatos([...datosCompletos]);
            
            // Calcular paginacion
            let totalRecords = datosOrdenados.length;
            let totalPages = Math.ceil(totalRecords / recordsPerPage);
            let startIndex = (currentPage - 1) * recordsPerPage;
            let endIndex = startIndex + recordsPerPage;
            let datosPagina = datosOrdenados.slice(startIndex, endIndex);
            
            // Renderizar tabla
            let tbody = '';
            datosPagina.forEach(function(row) {
                let fechaInicioMostrar = row.fecha_inicio ? formatFecha(row.fecha_inicio) : '-';
                let fechaFinMostrar = row.fecha_fin && row.fecha_fin !== '0000-00-00' ? formatFecha(row.fecha_fin) : '<span class="text-muted">Sin vencimiento</span>';
                
                let badgeDias = '';
                
                // VERIFICAR SI VIENE texto_dias DESDE EL SERVIDOR (para plan Visita)
                if(row.texto_dias) {
                    if(row.texto_dias === 'Vencido') {
                        badgeDias = '<span class="badge-status bg-danger text-white">Vencido</span>';
                    } else if(row.texto_dias === 'Vence hoy') {
                        badgeDias = '<span class="badge-status bg-warning">Vence hoy</span>';
                    } else if(row.texto_dias === 'Hoy (Válido)') {
                        badgeDias = '<span class="badge-status bg-success text-white">Válido hoy</span>';
                    } else if(row.texto_dias === 'Por vencer') {
                        badgeDias = '<span class="badge-status bg-warning">Por vencer</span>';
                    } else if(typeof row.texto_dias === 'number' || !isNaN(parseInt(row.texto_dias))) {
                        badgeDias = '<span class="badge-status bg-info text-white">' + row.texto_dias + ' días</span>';
                    } else if(row.texto_dias === '-') {
                        badgeDias = '<span class="badge-status bg-secondary text-white">-</span>';
                    } else {
                        badgeDias = '<span class="badge-status bg-info text-white">' + row.texto_dias + '</span>';
                    }
                } 
                // Si no viene texto_dias, usar el cálculo tradicional
                else if(row.dias_restantes > 0) {
                    badgeDias = '<span class="badge-status bg-info text-white">' + row.dias_restantes + ' días</span>';
                } else if(row.estado === 'activa') {
                    // Verificar si es plan Visita para mostrar mensaje especial
                    if(row.plan_nombre === 'Visita') {
                        badgeDias = '<span class="badge-status bg-warning">Válido hoy</span>';
                    } else {
                        badgeDias = '<span class="badge-status bg-warning">Por vencer</span>';
                    }
                } else {
                    badgeDias = '<span class="badge-status bg-secondary text-white">-</span>';
                }
                
                let badgeEstado = '';
                if(row.estado === 'activa') {
                    if(row.plan_nombre === 'Visita') {
                        badgeEstado = '<span class="badge-status bg-info text-white">Visita Activa</span>';
                    } else {
                        badgeEstado = '<span class="badge-status bg-success text-white">Activa</span>';
                    }
                } else if(row.estado === 'vencida') {
                    badgeEstado = '<span class="badge-status bg-danger text-white">Vencida</span>';
                } else {
                    badgeEstado = '<span class="badge-status bg-secondary text-white">Cancelada</span>';
                }
                
                tbody += '<tr>' +
                    '<td><strong>' + escapeHtml(row.cliente_nombre) + ' ' + escapeHtml(row.cliente_apellido) + '</strong><br><small class="text-muted">' + escapeHtml(row.telefono) + '</small></td>' +
                    '<td><i class="fas fa-envelope text-muted"></i> ' + escapeHtml(row.email || 'No registrado') + '</td>' +
                    '<td><span class="badge-status bg-secondary text-white">' + escapeHtml(row.plan_nombre) + '</span></td>' +
                    '<td>' + fechaInicioMostrar + '</td>' +
                    '<td>' + fechaFinMostrar + '</td>' +
                    '<td>' + badgeDias + '</td>' +
                    '<td class="text-success font-weight-bold">$' + parseFloat(row.precio_pagado).toFixed(2) + '</td>' +
                    '<td>' + badgeEstado + '</td>' +
                    '</tr>';
            });
            $('#tablaBody').html(tbody);
            
            // Renderizar paginacion
            renderPagination(currentPage, totalPages, totalRecords);
        }
        
        function renderPagination(currentPage, totalPages, totalRecords) {
            let startRecord = (currentPage - 1) * recordsPerPage + 1;
            let endRecord = Math.min(currentPage * recordsPerPage, totalRecords);
            
            $('#paginationInfo').html(`Mostrando ${startRecord} - ${endRecord} de ${totalRecords} registros`);
            
            let paginationHtml = '';
            
            // Boton Anterior
            paginationHtml += `<li><button onclick="goToPage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>&laquo;</button></li>`;
            
            // Numeros de pagina
            let startPage = Math.max(1, currentPage - 2);
            let endPage = Math.min(totalPages, currentPage + 2);
            
            if (startPage > 1) {
                paginationHtml += `<li><button onclick="goToPage(1)">1</button></li>`;
                if (startPage > 2) paginationHtml += `<li><button disabled>...</button></li>`;
            }
            
            for (let i = startPage; i <= endPage; i++) {
                paginationHtml += `<li><button onclick="goToPage(${i})" class="${i === currentPage ? 'active' : ''}">${i}</button></li>`;
            }
            
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) paginationHtml += `<li><button disabled>...</button></li>`;
                paginationHtml += `<li><button onclick="goToPage(${totalPages})">${totalPages}</button></li>`;
            }
            
            // Boton Siguiente
            paginationHtml += `<li><button onclick="goToPage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>&raquo;</button></li>`;
            
            $('#pagination').html(paginationHtml);
        }
        
        function goToPage(page) {
            let totalPages = Math.ceil(datosCompletos.length / recordsPerPage);
            if (page < 1 || page > totalPages) return;
            currentPage = page;
            renderTabla();
        }
        
        function formatFecha(fecha) {
            if (!fecha || fecha === '0000-00-00') return '-';
            const partes = fecha.split('-');
            if (partes.length === 3) {
                return partes[2] + '/' + partes[1] + '/' + partes[0];
            }
            return fecha;
        }
        
        function limpiarFiltros() {
            $('#searchInput').val('');
            $('#planSelect').val('');
            $('#estadoSelect').val('');
            $('#fechaRango').val('');
            fechaInicio = '';
            fechaFin = '';
            sortColumn = 'cliente';
            sortOrder = 'asc';
            $('.sortable i').removeClass('fa-sort-up fa-sort-down').addClass('fa-sort');
            cargarDatos();
        }
        
        $('#limpiarFiltros').on('click', limpiarFiltros);
        
        function escapeHtml(text) {
            if(!text) return '';
            return text.toString()
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
        
        function mostrarError(mensaje) {
            Swal.fire('Error', mensaje, 'error');
            $('#tablaBody').html('<tr><td colspan="8" class="text-center py-5 text-danger">' +
                '<i class="fas fa-exclamation-triangle fa-3x mb-3"></i>' +
                '<p>' + mensaje + '</p>' +
                '</td></tr>');
        }

        function exportarExcel() {
            Swal.fire({
                title: 'Generando reporte Excel...',
                text: 'Por favor espere',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            setTimeout(() => {
                try {
                    // Obtener datos de la tabla actual (SOLO FILAS VISIBLES)
                    let datosTabla = [];
                    let totalVencidas = 0;
                    let totalCanceladas = 0;
                    let totalActivas = 0;
                    
                    $('#tablaReporte tbody tr').each(function() {
                        // Verificar si la fila está visible
                        let esVisible = $(this).css('display') !== 'none' && !$(this).hasClass('hidden');
                        if (!esVisible) return;
                        
                        let fila = [];
                        let $tds = $(this).find('td');
                        if ($tds.length === 0) return;
                        
                        // Columna 0: Cliente y Teléfono
                        let clienteTelefono = $tds.eq(0).text().trim();
                        clienteTelefono = clienteTelefono.replace(/<[^>]*>/g, '');
                        clienteTelefono = clienteTelefono.replace(/\n/g, ' ');
                        clienteTelefono = clienteTelefono.replace(/\s+/g, ' ');
                        
                        let nombreCliente = clienteTelefono;
                        let telefono = '';
                        let matchTelefono = clienteTelefono.match(/(.+?)\s*(\d{6,})$/);
                        if (matchTelefono) {
                            nombreCliente = matchTelefono[1].trim();
                            telefono = matchTelefono[2];
                        }
                        nombreCliente = nombreCliente.replace(/^\d+\.\s*/, '').replace(/^\d+\s+/, '');
                        
                        // Columna 1: Email
                        let email = $tds.eq(1).text().trim();
                        email = email.replace(/<[^>]*>/g, '').replace(/\n/g, ' ').trim();
                        email = email.replace(/<i[^>]*>.*?<\/i>/g, '').trim();
                        if (email === 'No registrado') email = '';
                        
                        // Columna 2: Plan
                        let plan = $tds.eq(2).text().trim();
                        plan = plan.replace(/<[^>]*>/g, '').replace(/\n/g, ' ').trim();
                        plan = plan.replace(/<span[^>]*>.*?<\/span>/g, '').trim();
                        
                        // Columna 3: Fecha Inicio
                        let fechaInicio = $tds.eq(3).text().trim();
                        fechaInicio = fechaInicio.replace(/<[^>]*>/g, '').trim();
                        
                        // Columna 4: Fecha Fin
                        let fechaFin = $tds.eq(4).text().trim();
                        fechaFin = fechaFin.replace(/<[^>]*>/g, '').trim();
                        
                        // Columna 5: Días Restantes
                        let dias = $tds.eq(5).text().trim();
                        dias = dias.replace(/<[^>]*>/g, '').trim();
                        let diasTexto = '';
                        let yaVencido = false;
                        
                        // Parsear fecha fin para verificar vencimiento
                        let fechaFinObj = null;
                        if (fechaFin) {
                            let partes = fechaFin.split('/');
                            if (partes.length === 3) {
                                fechaFinObj = new Date(partes[2], partes[1] - 1, partes[0]);
                            }
                        }
                        let fechaActual = new Date();
                        fechaActual.setHours(0, 0, 0, 0);
                        
                        let diasNum = parseInt(dias);
                        if (fechaFinObj && fechaFinObj < fechaActual) {
                            diasTexto = 'Vencido';
                            yaVencido = true;
                        } else if (!isNaN(diasNum)) {
                            diasTexto = diasNum;
                        } else if (dias.includes('Por vencer')) {
                            diasTexto = 'Por vencer';
                        } else {
                            diasTexto = '-';
                        }
                        
                        // Columna 6: Precio
                        let precio = $tds.eq(6).text().trim();
                        precio = precio.replace(/<[^>]*>/g, '').replace('$', '').replace(/\s/g, '');
                        let precioNum = parseFloat(precio);
                        let precioFormateado = !isNaN(precioNum) ? Math.round(precioNum) : 0;
                        
                        // Columna 7: Estado
                        let estado = $tds.eq(7).text().trim();
                        estado = estado.replace(/<[^>]*>/g, '').trim();
                        
                        // Contar estados
                        if (estado === 'Cancelada') {
                            totalCanceladas++;
                        } else if (yaVencido) {
                            estado = 'Vencido';
                            totalVencidas++;
                        } else if (estado === 'Activa') {
                            totalActivas++;
                        }
                        
                        if (nombreCliente && nombreCliente !== 'Cargando datos...') {
                            fila.push(nombreCliente, email || 'No registrado', telefono || 'No registrado', plan, fechaInicio, fechaFin, diasTexto, precioFormateado, estado);
                            datosTabla.push(fila);
                        }
                    });
                    
                    if (datosTabla.length === 0) {
                        Swal.fire('Sin datos', 'No hay datos para exportar', 'warning');
                        return;
                    }
                    
                    // Crear libro de trabajo
                    let wb = XLSX.utils.book_new();
                    const fechaHora = new Date();
                    const fechaStr = fechaHora.toLocaleString('es-ES');
                    
                    // Calcular totales y KPIs
                    let totalIngresos = datosTabla.reduce((sum, fila) => sum + (typeof fila[7] === 'number' ? fila[7] : 0), 0);
                    let totalRegistros = datosTabla.length;
                    let ingresoPromedio = totalRegistros > 0 ? Math.round(totalIngresos / totalRegistros) : 0;
                    
                    // ==================== HOJA 1: DASHBOARD CON KPIS ====================
                    let dashboardData = [
                        ['REPORTE DE INSCRIPCIONES - DASHBOARD'],
                        [''],
                        ['INFORMACION DEL REPORTE'],
                        ['Fecha de Generacion:', fechaStr],
                        ['Usuario:', '<?php echo htmlspecialchars($usuario_nombre); ?>'],
                        ['Rol:', '<?php echo htmlspecialchars($usuario_rol); ?>'],
                        [''],
                        ['INDICADORES CLAVE (KPIS)'],
                        [''],
                        ['KPI', 'VALOR'],
                        ['Total de Inscripciones', totalRegistros],
                        ['Ingresos Totales', '$' + totalIngresos.toLocaleString('es-MX')],
                        ['Ingreso Promedio por Inscripcion', '$' + ingresoPromedio.toLocaleString('es-MX')],
                        ['Inscripciones Activas', totalActivas],
                        ['Inscripciones por Vencer / Vencidas', totalVencidas],
                        ['Inscripciones Canceladas', totalCanceladas],
                        [''],
                        ['RESUMEN POR ESTADO', 'CANTIDAD'],
                        ['Activas', totalActivas],
                        ['Por Vencer / Vencidas', totalVencidas],
                        ['Canceladas', totalCanceladas],
                        [''],
                        ['FILTROS APLICADOS'],
                        ['Buscador:', $('#searchInput').val() || 'Ninguno'],
                        ['Plan:', $('#planSelect option:selected').text() || 'Todos'],
                        ['Estado:', $('#estadoSelect option:selected').text() || 'Todos'],
                        ['Rango de Fechas:', $('#fechaRango').val() || 'Sin filtro']
                    ];
                    
                    let wsDashboard = XLSX.utils.aoa_to_sheet(dashboardData);
                    wsDashboard['!cols'] = [{wch: 32}, {wch: 25}];
                    wsDashboard['!merges'] = [
                        {s: {r: 0, c: 0}, e: {r: 0, c: 1}},
                        {s: {r: 7, c: 0}, e: {r: 7, c: 1}},
                        {s: {r: 17, c: 0}, e: {r: 17, c: 1}}
                    ];
                    XLSX.utils.book_append_sheet(wb, wsDashboard, 'Dashboard KPIs');
                    
                    // ==================== HOJA 2: DETALLE DE INSCRIPCIONES ====================
                    let headers = ['Cliente', 'Email', 'Telefono', 'Plan', 'Fecha Inicio', 'Fecha Fin', 'Dias', 'Precio (MXN)', 'Estado'];
                    let datosCompletos = [headers, ...datosTabla];
                    let wsDetalle = XLSX.utils.aoa_to_sheet(datosCompletos);
                    
                    // Configurar anchos de columna
                    wsDetalle['!cols'] = [
                        {wch: 32},
                        {wch: 32},
                        {wch: 15},
                        {wch: 20},
                        {wch: 14},
                        {wch: 14},
                        {wch: 12},
                        {wch: 16},
                        {wch: 14}
                    ];
                    
                    XLSX.utils.book_append_sheet(wb, wsDetalle, 'Detalle Inscripciones');
                    
                    // ==================== HOJA 3: ANALISIS POR PLAN ====================
                    let planesMap = new Map();
                    datosTabla.forEach(fila => {
                        let plan = fila[3];
                        let precio = typeof fila[7] === 'number' ? fila[7] : 0;
                        let estado = fila[8];
                        
                        if (!planesMap.has(plan)) {
                            planesMap.set(plan, { total: 0, ingresos: 0, activas: 0, vencidas: 0, canceladas: 0 });
                        }
                        let planData = planesMap.get(plan);
                        planData.total++;
                        planData.ingresos += precio;
                        if (estado === 'Activa') planData.activas++;
                        else if (estado === 'Vencido' || estado === 'Por vencer') planData.vencidas++;
                        else if (estado === 'Cancelada') planData.canceladas++;
                    });
                    
                    let analisisData = [
                        ['ANALISIS POR PLAN DE INSCRIPCION'],
                        [''],
                        ['Plan', 'Total', 'Activas', 'Por Vencer', 'Canceladas', 'Ingresos Totales']
                    ];
                    
                    planesMap.forEach((data, plan) => {
                        analisisData.push([
                            plan,
                            data.total,
                            data.activas,
                            data.vencidas,
                            data.canceladas,
                            '$' + data.ingresos.toLocaleString('es-MX')
                        ]);
                    });
                    
                    // Agregar fila de totales
                    analisisData.push(
                        [''],
                        ['TOTAL GENERAL', totalRegistros, totalActivas, totalVencidas, totalCanceladas, '$' + totalIngresos.toLocaleString('es-MX')]
                    );
                    
                    let wsAnalisis = XLSX.utils.aoa_to_sheet(analisisData);
                    wsAnalisis['!cols'] = [{wch: 28}, {wch: 12}, {wch: 12}, {wch: 12}, {wch: 12}, {wch: 22}];
                    wsAnalisis['!merges'] = [
                        {s: {r: 0, c: 0}, e: {r: 0, c: 5}}
                    ];
                    XLSX.utils.book_append_sheet(wb, wsAnalisis, 'Analisis por Plan');
                    
                    // Generar archivo
                    const anio = fechaHora.getFullYear();
                    const mes = (fechaHora.getMonth()+1).toString().padStart(2,'0');
                    const dia = fechaHora.getDate().toString().padStart(2,'0');
                    const hora = fechaHora.getHours().toString().padStart(2,'0');
                    const minuto = fechaHora.getMinutes().toString().padStart(2,'0');
                    const nombreArchivo = `Reporte_Inscripciones_${anio}-${mes}-${dia}_${hora}${minuto}.xlsx`;
                    
                    XLSX.writeFile(wb, nombreArchivo);
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Exportacion Exitosa',
                        html: `Reporte generado correctamente.<br><small>${nombreArchivo}</small><br><small>${datosTabla.length} registros</small>`,
                        timer: 3000,
                        showConfirmButton: false
                    });
                } catch (error) {
                    console.error('Error en Excel:', error);
                    Swal.fire('Error', 'Ocurrio un error: ' + error.message, 'error');
                }
            }, 100);
        }

        function exportarPDF() {
            Swal.fire({
                title: 'Generando PDF...',
                text: 'Por favor espere',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            setTimeout(() => {
                try {
                    const { jsPDF } = window.jspdf;
                    const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
                    const pageWidth = doc.internal.pageSize.getWidth();
                    const pageHeight = doc.internal.pageSize.getHeight();
                    const primaryColor = [31, 58, 147];
                    
                    // Obtener datos de la tabla actual (SOLO FILAS VISIBLES)
                    let datosTabla = [];
                    let totalVencidas = 0;
                    let totalCanceladas = 0;
                    
                    // IMPORTANTE: Seleccionar SOLO las filas visibles (no ocultas por filtro)
                    $('#tablaReporte tbody tr').each(function() {
                        // Verificar si la fila está visible (no tiene la clase 'hidden' o display:none)
                        let esVisible = $(this).css('display') !== 'none' && !$(this).hasClass('hidden');
                        
                        if (!esVisible) return;
                        
                        let $tds = $(this).find('td');
                        if ($tds.length === 0) return;
                        
                        // Columna 0: Cliente y Teléfono juntos - Separar
                        let clienteTelefono = $tds.eq(0).text().trim();
                        clienteTelefono = clienteTelefono.replace(/<[^>]*>/g, '');
                        clienteTelefono = clienteTelefono.replace(/\n/g, ' ');
                        clienteTelefono = clienteTelefono.replace(/\s+/g, ' ');
                        
                        // Separar nombre y teléfono
                        let nombreCliente = clienteTelefono;
                        let telefono = '';
                        let matchTelefono = clienteTelefono.match(/(.+?)\s*(\d{6,})$/);
                        if (matchTelefono) {
                            nombreCliente = matchTelefono[1].trim();
                            telefono = matchTelefono[2];
                        }
                        nombreCliente = nombreCliente.replace(/^\d+\.\s*/, '').replace(/^\d+\s+/, '');
                        
                        // Columna 1: Email
                        let email = $tds.eq(1).text().trim();
                        email = email.replace(/<[^>]*>/g, '').replace(/\n/g, ' ').trim();
                        email = email.replace(/<i[^>]*>.*?<\/i>/g, '').trim();
                        if (email.includes('No registrado')) email = '';
                        
                        // Columna 2: Plan
                        let plan = $tds.eq(2).text().trim();
                        plan = plan.replace(/<[^>]*>/g, '').trim();
                        plan = plan.replace(/<span[^>]*>.*?<\/span>/g, '').trim();
                        
                        // Columna 3: Fecha Inicio
                        let fechaInicio = $tds.eq(3).text().trim();
                        fechaInicio = fechaInicio.replace(/<[^>]*>/g, '').trim();
                        
                        // Columna 4: Fecha Fin
                        let fechaFin = $tds.eq(4).text().trim();
                        fechaFin = fechaFin.replace(/<[^>]*>/g, '').trim();
                        
                        // Columna 5: Días Restantes (texto original de la tabla)
                        let diasOriginal = $tds.eq(5).text().trim();
                        diasOriginal = diasOriginal.replace(/<[^>]*>/g, '').trim();
                        
                        // Columna 6: Precio
                        let precio = $tds.eq(6).text().trim();
                        precio = precio.replace(/<[^>]*>/g, '').replace('$', '').replace(/\s/g, '');
                        let precioNum = parseFloat(precio);
                        let precioFormateado = '';
                        if (!isNaN(precioNum)) {
                            precioFormateado = '$' + Math.round(precioNum).toLocaleString('es-MX');
                        }
                        
                        // Columna 7: Estado
                        let estado = $tds.eq(7).text().trim();
                        estado = estado.replace(/<[^>]*>/g, '').trim();
                        if (estado === 'Cancelada') totalCanceladas++;
                        
                        // Determinar el texto para la columna Días
                        let diasTexto = '';
                        
                        // Primero, si el estado es Cancelada, no se considera vencida
                        if (estado === 'Cancelada') {
                            diasTexto = '-';
                        } 
                        // Si el estado es Activa, verificar si está vencida o por vencer
                        else if (estado === 'Activa') {
                            // Parsear fecha fin correctamente (formato: DD/MM/YYYY)
                            let fechaFinObj = null;
                            if (fechaFin) {
                                let partes = fechaFin.split('/');
                                if (partes.length === 3) {
                                    // Formato: DD/MM/YYYY
                                    fechaFinObj = new Date(partes[2], partes[1] - 1, partes[0]);
                                } else {
                                    fechaFinObj = new Date(fechaFin);
                                }
                            }
                            
                            let fechaActual = new Date();
                            // Resetear horas para comparar solo fechas
                            fechaActual.setHours(0, 0, 0, 0);
                            
                            if (fechaFinObj && fechaFinObj < fechaActual) {
                                diasTexto = 'Vencido';
                                totalVencidas++;
                            } else if (diasOriginal.includes('Por vencer')) {
                                diasTexto = 'Por vencer';
                                totalVencidas++;
                            } else {
                                // Extraer número de días del texto original
                                let diasNum = parseInt(diasOriginal);
                                if (!isNaN(diasNum)) {
                                    diasTexto = diasNum + ' dias';
                                } else {
                                    diasTexto = '-';
                                }
                            }
                        } 
                        // Otros estados
                        else {
                            diasTexto = diasOriginal;
                        }
                        
                        if (nombreCliente && nombreCliente !== 'Cargando datos...') {
                            datosTabla.push([nombreCliente, email || 'No registrado', telefono || 'No registrado', plan, fechaInicio, fechaFin, diasTexto, precioFormateado, estado]);
                        }
                    });
                    
                    if (datosTabla.length === 0) {
                        Swal.fire('Sin datos', 'No hay datos para exportar', 'warning');
                        return;
                    }
                    
                    let headers = ['Cliente', 'Email', 'Telefono', 'Plan', 'Fecha Inicio', 'Fecha Fin', 'Dias', 'Precio', 'Estado'];
                    
                    // Encabezado del reporte
                    doc.setFillColor(primaryColor[0], primaryColor[1], primaryColor[2]);
                    doc.rect(0, 0, pageWidth, 35, 'F');
                    doc.setTextColor(255, 255, 255);
                    doc.setFontSize(16);
                    doc.setFont('helvetica', 'bold');
                    doc.text('SISTEMA GIMNASIO', 20, 14);
                    doc.setFontSize(11);
                    doc.setFont('helvetica', 'normal');
                    doc.text('Reporte de Inscripciones', 20, 24);
                    doc.setFontSize(7);
                    doc.setTextColor(200, 200, 200);
                    doc.text(`Generado: ${new Date().toLocaleString('es-ES')}`, 20, 32);
                    doc.setTextColor(255, 255, 255);
                    doc.text(`Usuario: <?php echo htmlspecialchars($usuario_nombre); ?>`, pageWidth - 50, 14);
                    doc.text(`Rol: <?php echo htmlspecialchars($usuario_rol); ?>`, pageWidth - 50, 22);
                    
                    // Tarjetas de estadisticas principales (solo 4)
                    let yPos = 48;
                    const stats = [
                        { label: 'Clientes Activos', value: '<?php echo number_format($stats['total_clientes_activos'] ?? 0); ?>' },
                        { label: 'Inscripciones Activas', value: '<?php echo number_format($stats['total_inscripciones'] ?? 0); ?>' },
                        { label: 'Ingresos Totales', value: '$<?php echo number_format(round($stats['total_ingresos'] ?? 0), 0); ?>' },
                        { label: 'Total Clientes', value: '<?php echo number_format($total_clientes['total']); ?>' }
                    ];
                    
                    const cardWidth = (pageWidth - 40) / 4;
                    stats.forEach((stat, index) => {
                        const x = 15 + (index * (cardWidth + 5));
                        doc.setFillColor(248, 250, 252);
                        doc.roundedRect(x, yPos, cardWidth, 26, 3, 3, 'F');
                        doc.setDrawColor(200, 200, 200);
                        doc.roundedRect(x, yPos, cardWidth, 26, 3, 3, 'S');
                        doc.setFontSize(8);
                        doc.setTextColor(100, 100, 100);
                        doc.text(stat.label, x + 4, yPos + 9);
                        doc.setFontSize(12);
                        doc.setFont('helvetica', 'bold');
                        doc.setTextColor(primaryColor[0], primaryColor[1], primaryColor[2]);
                        doc.text(stat.value, x + 4, yPos + 20);
                    });
                    
                    // Linea de Totales: Vencidas y Canceladas
                    yPos = 84;
                    doc.setFontSize(9);
                    doc.setFont('helvetica', 'bold');
                    doc.setTextColor(primaryColor[0], primaryColor[1], primaryColor[2]);
                    doc.text('Totales:', 15, yPos);
                    doc.setFontSize(9);
                    doc.setFont('helvetica', 'normal');
                    doc.setTextColor(80, 80, 80);
                    doc.text(`Incripciones vencidas: ${totalVencidas}`, 45, yPos);
                    doc.text(`Inscripciones canceladas: ${totalCanceladas}`, 85, yPos);
                    
                    // Filtros aplicados (tomando los valores actuales de los filtros)
                    yPos = 98;
                    doc.setFontSize(9);
                    doc.setFont('helvetica', 'bold');
                    doc.setTextColor(primaryColor[0], primaryColor[1], primaryColor[2]);
                    doc.text('Filtros aplicados:', 15, yPos);
                    doc.setFontSize(8);
                    doc.setFont('helvetica', 'normal');
                    doc.setTextColor(80, 80, 80);
                    
                    const searchValue = $('#searchInput').val();
                    const planValue = $('#planSelect option:selected').text();
                    const estadoValue = $('#estadoSelect option:selected').text();
                    const fechaValue = $('#fechaRango').val();
                    
                    let filtrosTexto = [];
                    if (searchValue && searchValue !== '') filtrosTexto.push(`Buscador: ${searchValue}`);
                    if (planValue && planValue !== 'Todos' && planValue !== 'Todos los planes') filtrosTexto.push(`Plan: ${planValue}`);
                    if (estadoValue && estadoValue !== 'Todos' && estadoValue !== 'Todos los estados') filtrosTexto.push(`Estado: ${estadoValue}`);
                    if (fechaValue && fechaValue !== '') filtrosTexto.push(`Fechas: ${fechaValue}`);
                    if (filtrosTexto.length === 0) filtrosTexto = ['Ningun filtro aplicado'];
                    
                    let filtrosY = yPos + 5;
                    let filtrosX = 70;
                    filtrosTexto.forEach((filtro) => {
                        const filtroWidth = doc.getStringUnitWidth(filtro) * 8 / doc.internal.scaleFactor;
                        if (filtrosX + filtroWidth > pageWidth - 20) {
                            filtrosY += 5;
                            filtrosX = 70;
                        }
                        doc.text(filtro, filtrosX, filtrosY);
                        filtrosX += filtroWidth + 8;
                    });
                    
                    // Tabla principal - ocupando todo el espacio disponible
                    let startY = filtrosY + 10;
                    
                    // Calcular anchos de columna para ocupar todo el ancho
                    const availableWidth = pageWidth - 24;
                    const columnWidths = {
                        0: availableWidth * 0.18,
                        1: availableWidth * 0.18,
                        2: availableWidth * 0.10,
                        3: availableWidth * 0.12,
                        4: availableWidth * 0.10,
                        5: availableWidth * 0.10,
                        6: availableWidth * 0.08,
                        7: availableWidth * 0.07,
                        8: availableWidth * 0.07
                    };
                    
                    doc.autoTable({
                        head: [headers],
                        body: datosTabla,
                        startY: startY,
                        theme: 'striped',
                        headStyles: {
                            fillColor: primaryColor,
                            textColor: [255, 255, 255],
                            fontStyle: 'bold',
                            fontSize: 8,
                            halign: 'center',
                            valign: 'middle',
                            cellPadding: 5
                        },
                        bodyStyles: { 
                            fontSize: 7.5, 
                            cellPadding: 4,
                            valign: 'middle'
                        },
                        alternateRowStyles: { fillColor: [245, 248, 250] },
                        styles: {
                            lineColor: [180, 180, 180],
                            lineWidth: 0.1,
                            font: 'helvetica'
                        },
                        columnStyles: {
                            0: { cellWidth: columnWidths[0], halign: 'left' },
                            1: { cellWidth: columnWidths[1], halign: 'left' },
                            2: { cellWidth: columnWidths[2], halign: 'center' },
                            3: { cellWidth: columnWidths[3], halign: 'center' },
                            4: { cellWidth: columnWidths[4], halign: 'center' },
                            5: { cellWidth: columnWidths[5], halign: 'center' },
                            6: { cellWidth: columnWidths[6], halign: 'center' },
                            7: { cellWidth: columnWidths[7], halign: 'right' },
                            8: { cellWidth: columnWidths[8], halign: 'center' }
                        },
                        margin: { left: 12, right: 12 },
                        tableWidth: 'auto',
                        didDrawCell: function(data) {
                            // Resaltar celdas de la columna Dias (índice 6)
                            if (data.column.index === 6) {
                                const valorDias = data.cell.raw;
                                if (valorDias === 'Vencido') {
                                    doc.setTextColor(239, 68, Hakutatsu);
                                    doc.setFont('helvetica', 'bold');
                                } else if (valorDias === 'Por vencer') {
                                    doc.setTextColor(245, 158, 11);
                                    doc.setFont('helvetica', 'bold');
                                }
                            }
                            
                            // Resaltar celdas de estado
                            if (data.column.index === 8) {
                                const estado = data.cell.raw;
                                if (estado === 'Vencido') {
                                    doc.setTextColor(239, 68, 68);
                                    doc.setFont('helvetica', 'bold');
                                } else if (estado === 'Cancelada') {
                                    doc.setTextColor(239, 68, 68);
                                    doc.setFont('helvetica', 'bold');
                                } else if (estado === 'Activa') {
                                    doc.setTextColor(34, 197, 94);
                                    doc.setFont('helvetica', 'bold');
                                }
                            }
                        },
                        didParseCell: function(data) {
                            // Restaurar color después de cada celda
                            if (data.column.index === 6 || data.column.index === 8) {
                                if (data.cell.raw !== 'Vencido' && data.cell.raw !== 'Por vencer' && data.cell.raw !== 'Cancelada') {
                                    data.cell.styles.textColor = [80, 80, 80];
                                    data.cell.styles.fontStyle = 'normal';
                                }
                            }
                        },
                        didDrawPage: function(data) {
                            const pageCount = doc.internal.getNumberOfPages();
                            const currentPage = doc.internal.getCurrentPageInfo().pageNumber;
                            
                            doc.setFontSize(7);
                            doc.setTextColor(150, 150, 150);
                            doc.setFont('helvetica', 'italic');
                            doc.text(`Pagina ${currentPage} de ${pageCount} | Total registros: ${datosTabla.length}`,
                                    pageWidth / 2, pageHeight - 6, { align: 'center' });
                            
                            doc.setDrawColor(200, 200, 200);
                            doc.setLineWidth(0.2);
                            doc.line(12, pageHeight - 10, pageWidth - 12, pageHeight - 10);
                        }
                    });
                    
                    const fecha = new Date();
                    const anio = fecha.getFullYear();
                    const mes = (fecha.getMonth()+1).toString().padStart(2,'0');
                    const dia = fecha.getDate().toString().padStart(2,'0');
                    const hora = fecha.getHours().toString().padStart(2,'0');
                    const minuto = fecha.getMinutes().toString().padStart(2,'0');
                    const nombreArchivo = `Reporte_Inscripciones_${anio}-${mes}-${dia}_${hora}${minuto}.pdf`;
                    doc.save(nombreArchivo);
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'PDF Generado',
                        html: `Reporte generado correctamente.<br><small>${nombreArchivo}</small><br><small>${datosTabla.length} registros</small>`,
                        timer: 2500,
                        showConfirmButton: false
                    });
                } catch (error) {
                    console.error('Error en PDF:', error);
                    Swal.fire('Error', 'Ocurrio un error: ' + error.message, 'error');
                }
            }, 100);
        }
        
        function escapeHtml(text) {
            if(!text) return '';
            return text.toString()
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
        
        function mostrarError(mensaje) {
            Swal.fire('Error', mensaje, 'error');
            $('#tablaBody').html('<tr><td colspan="8" class="text-center py-5 text-danger">' +
                '<i class="fas fa-exclamation-triangle fa-3x mb-3"></i>' +
                '<p>' + mensaje + '</p>' +
                '</td></tr>');
        }
    </script>
</body>
</html>