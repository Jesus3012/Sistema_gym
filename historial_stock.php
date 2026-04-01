<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

if (!$conn) {
    die("Error de conexión a la base de datos");
}

// Configuración de paginación
$registros_por_pagina = isset($_GET['limite']) ? (int)$_GET['limite'] : 20;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;
$busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$fecha_desde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : '';
$fecha_hasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : '';

// Construir consulta WHERE
$where = [];
$params = [];
$types = "";

if (!empty($busqueda)) {
    $where[] = "(p.nombre LIKE ? OR m.motivo LIKE ?)";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
    $types .= "ss";
}

if ($tipo_filtro != 'todos') {
    $where[] = "m.tipo_movimiento = ?";
    $params[] = $tipo_filtro;
    $types .= "s";
}

if (!empty($fecha_desde)) {
    $where[] = "DATE(m.fecha_movimiento) >= ?";
    $params[] = $fecha_desde;
    $types .= "s";
}

if (!empty($fecha_hasta)) {
    $where[] = "DATE(m.fecha_movimiento) <= ?";
    $params[] = $fecha_hasta;
    $types .= "s";
}

$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Contar total de registros
$count_sql = "SELECT COUNT(*) as total FROM movimientos_stock m 
              JOIN productos p ON m.producto_id = p.id 
              $where_sql";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_registros = $count_result->fetch_assoc()['total'];
$total_paginas = ceil($total_registros / $registros_por_pagina);
$count_stmt->close();

// Obtener movimientos
$sql = "SELECT m.*, p.nombre as producto_nombre, u.nombre as usuario_nombre 
        FROM movimientos_stock m 
        JOIN productos p ON m.producto_id = p.id 
        JOIN usuarios u ON m.usuario_id = u.id 
        $where_sql 
        ORDER BY m.fecha_movimiento DESC 
        LIMIT ? OFFSET ?";

$params[] = $registros_por_pagina;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$movimientos = [];
while ($row = $result->fetch_assoc()) {
    $movimientos[] = $row;
}
$stmt->close();

// Obtener resumen de movimientos para estadísticas
$resumen_sql = "SELECT 
                    tipo_movimiento,
                    COUNT(*) as total,
                    SUM(cantidad) as suma
                FROM movimientos_stock m
                $where_sql
                GROUP BY tipo_movimiento";
$resumen_stmt = $conn->prepare($resumen_sql);
if (!empty($params_original)) {
    // Reconstruir parámetros sin los de paginación
    $params_resumen = array_slice($params, 0, -2);
    $types_resumen = substr($types, 0, -2);
    if (!empty($params_resumen)) {
        $resumen_stmt->bind_param($types_resumen, ...$params_resumen);
    }
}
$resumen_stmt->execute();
$resumen_result = $resumen_stmt->get_result();
$resumen = [];
while ($row = $resumen_result->fetch_assoc()) {
    $resumen[$row['tipo_movimiento']] = $row;
}
$resumen_stmt->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Stock - Gym System</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        .main-content {
            margin-left: 280px;
            padding: 20px 30px;
            transition: margin-left 0.3s ease;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
        }
        
        .badge-entrada {
            background-color: #28a745;
            color: white;
        }
        
        .badge-correccion {
            background-color: #ffc107;
            color: #212529;
        }
        
        .badge-ajuste_minimo {
            background-color: #17a2b8;
            color: white;
        }
        
        .badge-inicial {
            background-color: #6c757d;
            color: white;
        }
        
        .cantidad-positiva {
            color: #28a745;
            font-weight: bold;
        }
        
        .cantidad-negativa {
            color: #dc3545;
            font-weight: bold;
        }
        
        .filter-card {
            margin-bottom: 20px;
        }
        
        .stats-card {
            margin-bottom: 20px;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state i {
            font-size: 64px;
            color: #cbd5e0;
            margin-bottom: 20px;
            display: block;
        }
        
        .empty-state p {
            color: #6c757d;
            font-size: 16px;
            margin: 0;
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="container-fluid p-0">
                <!-- Header -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="page-title">
                            <h2>Historial de Movimientos de Stock</h2>
                        </div>
                    </div>
                </div>

                <!-- Tarjetas de estadísticas -->
                <div class="row stats-card">
                    <div class="col-md-3">
                        <div class="small-box bg-info">
                            <div class="inner">
                                <h3><?php echo $resumen['entrada']['total'] ?? 0; ?></h3>
                                <p>Entradas de Stock</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-plus-circle"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="small-box bg-warning">
                            <div class="inner">
                                <h3><?php echo $resumen['correccion']['total'] ?? 0; ?></h3>
                                <p>Correcciones</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-sliders-h"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="small-box bg-secondary">
                            <div class="inner">
                                <h3><?php echo $resumen['inicial']['total'] ?? 0; ?></h3>
                                <p>Stock Inicial</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-box"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="small-box bg-primary">
                            <div class="inner">
                                <h3><?php echo $total_registros; ?></h3>
                                <p>Total Movimientos</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="card filter-card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-filter"></i> Filtros de búsqueda
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="GET" id="filtrosForm">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label><i class="fas fa-search"></i> Buscar</label>
                                        <input type="text" name="busqueda" class="form-control" 
                                               placeholder="Producto o motivo..." 
                                               value="<?php echo htmlspecialchars($busqueda); ?>">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label><i class="fas fa-tag"></i> Tipo</label>
                                        <select name="tipo" class="form-control">
                                            <option value="todos" <?php echo $tipo_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                                            <option value="inicial" <?php echo $tipo_filtro == 'inicial' ? 'selected' : ''; ?>>Stock Inicial</option>
                                            <option value="entrada" <?php echo $tipo_filtro == 'entrada' ? 'selected' : ''; ?>>Entradas</option>
                                            <option value="correccion" <?php echo $tipo_filtro == 'correccion' ? 'selected' : ''; ?>>Correcciones</option>
                                            <option value="ajuste_minimo" <?php echo $tipo_filtro == 'ajuste_minimo' ? 'selected' : ''; ?>>Ajustes Mínimo</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label><i class="fas fa-calendar"></i> Desde</label>
                                        <input type="date" name="fecha_desde" class="form-control" 
                                               value="<?php echo $fecha_desde; ?>">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label><i class="fas fa-calendar"></i> Hasta</label>
                                        <input type="date" name="fecha_hasta" class="form-control" 
                                               value="<?php echo $fecha_hasta; ?>">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label><i class="fas fa-list"></i> Mostrar</label>
                                        <select name="limite" class="form-control">
                                            <option value="10" <?php echo $registros_por_pagina == 10 ? 'selected' : ''; ?>>10</option>
                                            <option value="20" <?php echo $registros_por_pagina == 20 ? 'selected' : ''; ?>>20</option>
                                            <option value="50" <?php echo $registros_por_pagina == 50 ? 'selected' : ''; ?>>50</option>
                                            <option value="100" <?php echo $registros_por_pagina == 100 ? 'selected' : ''; ?>>100</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-1">
                                    <div class="form-group">
                                        <label>&nbsp;</label>
                                        <button type="submit" class="btn btn-primary btn-block">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tabla de movimientos -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-list"></i> Movimientos Registrados
                        </h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-striped">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Fecha y Hora</th>
                                        <th>Producto</th>
                                        <th>Tipo</th>
                                        <th>Cantidad</th>
                                        <th>Stock Anterior</th>
                                        <th>Stock Nuevo</th>
                                        <th>Motivo</th>
                                        <th>Usuario</th>
                                        <th>Observaciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($movimientos) > 0): ?>
                                        <?php foreach ($movimientos as $mov): ?>
                                        <tr>
                                            <td class="align-middle">
                                                <?php echo date('d/m/Y H:i:s', strtotime($mov['fecha_movimiento'])); ?>
                                            </td>
                                            <td class="align-middle">
                                                <strong><?php echo htmlspecialchars($mov['producto_nombre']); ?></strong>
                                            </td>
                                            <td class="align-middle">
                                                <?php
                                                $badge_class = '';
                                                $tipo_texto = '';
                                                switch($mov['tipo_movimiento']) {
                                                    case 'inicial':
                                                        $badge_class = 'badge-secondary';
                                                        $tipo_texto = 'Stock Inicial';
                                                        break;
                                                    case 'entrada':
                                                        $badge_class = 'badge-success';
                                                        $tipo_texto = 'Entrada';
                                                        break;
                                                    case 'correccion':
                                                        $badge_class = 'badge-warning';
                                                        $tipo_texto = 'Corrección';
                                                        break;
                                                    case 'ajuste_minimo':
                                                        $badge_class = 'badge-info';
                                                        $tipo_texto = 'Ajuste Mínimo';
                                                        break;
                                                }
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>" style="padding: 6px 12px;">
                                                    <?php echo $tipo_texto; ?>
                                                </span>
                                            </td>
                                            <td class="align-middle <?php echo $mov['cantidad'] >= 0 ? 'cantidad-positiva' : 'cantidad-negativa'; ?>">
                                                <?php echo ($mov['cantidad'] >= 0 ? '+' : '') . $mov['cantidad']; ?>
                                            </td>
                                            <td class="align-middle"><?php echo $mov['stock_anterior']; ?></td>
                                            <td class="align-middle">
                                                <strong><?php echo $mov['stock_nuevo']; ?></strong>
                                            </td>
                                            <td class="align-middle">
                                                <?php echo htmlspecialchars($mov['motivo'] ?: 'N/A'); ?>
                                            </td>
                                            <td class="align-middle">
                                                <?php echo htmlspecialchars($mov['usuario_nombre']); ?>
                                            </td>
                                            <td class="align-middle">
                                                <small><?php echo htmlspecialchars($mov['observaciones'] ?: '—'); ?></small>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" style="padding: 0;">
                                                <div class="empty-state">
                                                    <i class="fas fa-box-open"></i>
                                                    <p>No se encontraron movimientos de stock</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <?php if ($total_paginas > 1 && count($movimientos) > 0): ?>
                    <div class="card-footer clearfix">
                        <div class="row">
                            <div class="col-sm-12 col-md-6">
                                <div class="dataTables_info" style="margin-top: 8px;">
                                    Mostrando <?php echo count($movimientos); ?> de <?php echo $total_registros; ?> movimientos
                                    (Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?>)
                                </div>
                            </div>
                            <div class="col-sm-12 col-md-6">
                                <ul class="pagination pagination-sm m-0 float-right">
                                    <?php if ($pagina_actual > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?pagina=1&busqueda=<?php echo urlencode($busqueda); ?>&tipo=<?php echo $tipo_filtro; ?>&fecha_desde=<?php echo $fecha_desde; ?>&fecha_hasta=<?php echo $fecha_hasta; ?>&limite=<?php echo $registros_por_pagina; ?>">
                                                <i class="fas fa-angle-double-left"></i>
                                            </a>
                                        </li>
                                        <li class="page-item">
                                            <a class="page-link" href="?pagina=<?php echo $pagina_actual - 1; ?>&busqueda=<?php echo urlencode($busqueda); ?>&tipo=<?php echo $tipo_filtro; ?>&fecha_desde=<?php echo $fecha_desde; ?>&fecha_hasta=<?php echo $fecha_hasta; ?>&limite=<?php echo $registros_por_pagina; ?>">
                                                <i class="fas fa-angle-left"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = max(1, $pagina_actual - 2); $i <= min($total_paginas, $pagina_actual + 2); $i++): ?>
                                        <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                            <a class="page-link" href="?pagina=<?php echo $i; ?>&busqueda=<?php echo urlencode($busqueda); ?>&tipo=<?php echo $tipo_filtro; ?>&fecha_desde=<?php echo $fecha_desde; ?>&fecha_hasta=<?php echo $fecha_hasta; ?>&limite=<?php echo $registros_por_pagina; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($pagina_actual < $total_paginas): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?pagina=<?php echo $pagina_actual + 1; ?>&busqueda=<?php echo urlencode($busqueda); ?>&tipo=<?php echo $tipo_filtro; ?>&fecha_desde=<?php echo $fecha_desde; ?>&fecha_hasta=<?php echo $fecha_hasta; ?>&limite=<?php echo $registros_por_pagina; ?>">
                                                <i class="fas fa-angle-right"></i>
                                            </a>
                                        </li>
                                        <li class="page-item">
                                            <a class="page-link" href="?pagina=<?php echo $total_paginas; ?>&busqueda=<?php echo urlencode($busqueda); ?>&tipo=<?php echo $tipo_filtro; ?>&fecha_desde=<?php echo $fecha_desde; ?>&fecha_hasta=<?php echo $fecha_hasta; ?>&limite=<?php echo $registros_por_pagina; ?>">
                                                <i class="fas fa-angle-double-right"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        $(document).ready(function() {
            // Auto-submit al cambiar el límite
            $('select[name="limite"]').on('change', function() {
                $('#filtrosForm').submit();
            });
        });
    </script>
</body>
</html>