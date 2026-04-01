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
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$fecha_desde = isset($_GET['fecha_desde']) ? $_GET['fecha_desde'] : '';
$fecha_hasta = isset($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : '';

// Construir consulta WHERE para las consultas que necesitan JOIN con productos
$where_con_join = [];
// Construir consulta WHERE para las consultas que NO necesitan JOIN
$where_sin_join = [];

$params = [];
$types = "";

// Filtro de búsqueda (solo se aplica en consultas con JOIN)
if (!empty($busqueda)) {
    $where_con_join[] = "(p.nombre LIKE ? OR m.motivo LIKE ?)";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
    $types .= "ss";
}

// Filtro de tipo (aplica para todas las consultas)
if ($tipo_filtro != 'todos') {
    $where_con_join[] = "m.tipo_movimiento = ?";
    $where_sin_join[] = "tipo_movimiento = ?";
    $params[] = $tipo_filtro;
    $types .= "s";
}

// Filtros de fecha - SOLO se aplican si AMBAS fechas están presentes o si solo una está presente
if (!empty($fecha_desde) && !empty($fecha_hasta)) {
    // Ambas fechas presentes: rango completo
    $where_con_join[] = "DATE(m.fecha_movimiento) BETWEEN ? AND ?";
    $where_sin_join[] = "DATE(fecha_movimiento) BETWEEN ? AND ?";
    $params[] = $fecha_desde;
    $params[] = $fecha_hasta;
    $types .= "ss";
} elseif (!empty($fecha_desde) && empty($fecha_hasta)) {
    // Solo fecha desde
    $where_con_join[] = "DATE(m.fecha_movimiento) >= ?";
    $where_sin_join[] = "DATE(fecha_movimiento) >= ?";
    $params[] = $fecha_desde;
    $types .= "s";
} elseif (empty($fecha_desde) && !empty($fecha_hasta)) {
    // Solo fecha hasta
    $where_con_join[] = "DATE(m.fecha_movimiento) <= ?";
    $where_sin_join[] = "DATE(fecha_movimiento) <= ?";
    $params[] = $fecha_hasta;
    $types .= "s";
}

$where_sql_con_join = !empty($where_con_join) ? "WHERE " . implode(" AND ", $where_con_join) : "";
$where_sql_sin_join = !empty($where_sin_join) ? "WHERE " . implode(" AND ", $where_sin_join) : "";

// Contar total de registros (con JOIN)
$count_sql = "SELECT COUNT(*) as total FROM movimientos_stock m 
              JOIN productos p ON m.producto_id = p.id 
              $where_sql_con_join";
$count_stmt = $conn->prepare($count_sql);
if ($count_stmt === false) {
    die("Error en la consulta COUNT: " . $conn->error);
}
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
        $where_sql_con_join 
        ORDER BY m.fecha_movimiento DESC 
        LIMIT ? OFFSET ?";

$params_paginacion = $params;
$types_paginacion = $types;
$params_paginacion[] = $registros_por_pagina;
$params_paginacion[] = $offset;
$types_paginacion .= "ii";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die("Error en la consulta principal: " . $conn->error);
}
if (!empty($params_paginacion)) {
    $stmt->bind_param($types_paginacion, ...$params_paginacion);
}
$stmt->execute();
$result = $stmt->get_result();
$movimientos = [];
while ($row = $result->fetch_assoc()) {
    $movimientos[] = $row;
}
$stmt->close();

// Obtener resumen de movimientos para estadísticas (SIN JOIN)
$resumen_sql = "SELECT 
                    tipo_movimiento,
                    COUNT(*) as total,
                    SUM(ABS(cantidad)) as suma_cantidad
                FROM movimientos_stock m
                $where_sql_sin_join
                GROUP BY tipo_movimiento";
$resumen_stmt = $conn->prepare($resumen_sql);
if ($resumen_stmt === false) {
    die("Error en la consulta de resumen: " . $conn->error);
}
if (!empty($params)) {
    $resumen_stmt->bind_param($types, ...$params);
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
        
        .filter-input {
            transition: all 0.3s ease;
        }
        
        .filter-input:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
        }
        
        .limit-selector {
            display: inline-block;
            margin-left: 10px;
        }
        
        .limit-selector select {
            width: auto;
            display: inline-block;
            width: 70px;
            margin: 0 5px;
        }
        
        .date-range-info {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
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
                                <h3><?php echo number_format($resumen['entrada']['total'] ?? 0); ?></h3>
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
                                <h3><?php echo number_format($resumen['correccion']['total'] ?? 0); ?></h3>
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
                                <h3><?php echo number_format($resumen['inicial']['total'] ?? 0); ?></h3>
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
                                <h3><?php echo number_format($total_registros); ?></h3>
                                <p>Total Movimientos</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtros en tiempo real -->
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
                        <form method="GET" id="filtrosForm" autocomplete="off">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label><i class="fas fa-search"></i> Buscar producto o motivo</label>
                                        <input type="text" name="busqueda" class="form-control filter-input" 
                                            placeholder="Escribe para buscar..." 
                                            value="<?php echo htmlspecialchars($busqueda); ?>"
                                            id="busquedaInput">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label><i class="fas fa-tag"></i> Tipo de movimiento</label>
                                        <select name="tipo" class="form-control filter-input" id="tipoSelect">
                                            <option value="todos" <?php echo $tipo_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                                            <option value="inicial" <?php echo $tipo_filtro == 'inicial' ? 'selected' : ''; ?>>Stock Inicial</option>
                                            <option value="entrada" <?php echo $tipo_filtro == 'entrada' ? 'selected' : ''; ?>>Entradas</option>
                                            <option value="correccion" <?php echo $tipo_filtro == 'correccion' ? 'selected' : ''; ?>>Correcciones</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label><i class="fas fa-calendar"></i> Desde</label>
                                        <input type="date" name="fecha_desde" class="form-control filter-input" 
                                            value="<?php echo $fecha_desde; ?>"
                                            id="fechaDesde">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label><i class="fas fa-calendar"></i> Hasta</label>
                                        <input type="date" name="fecha_hasta" class="form-control filter-input" 
                                            value="<?php echo $fecha_hasta; ?>"
                                            id="fechaHasta">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label>&nbsp;</label>
                                        <button type="button" class="btn btn-danger btn-block" id="borrarFiltrosBtn">
                                            <i class="fas fa-trash-alt"></i> Borrar filtros
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-12">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle"></i> Los filtros de fecha solo se aplican cuando ambas fechas están seleccionadas
                                    </small>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tabla de movimientos -->
                <div class="card" id="tablaContainer">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h3 class="card-title">
                                <i class="fas fa-list"></i> Movimientos Registrados
                            </h3>
                            <div class="limit-selector">
                                <label class="mb-0 mr-2">Mostrar:</label>
                                <select name="limite" class="form-control form-control-sm" id="limiteSelect" style="width: auto; display: inline-block;">
                                    <option value="10" <?php echo $registros_por_pagina == 10 ? 'selected' : ''; ?>>10</option>
                                    <option value="20" <?php echo $registros_por_pagina == 20 ? 'selected' : ''; ?>>20</option>
                                    <option value="50" <?php echo $registros_por_pagina == 50 ? 'selected' : ''; ?>>50</option>
                                    <option value="100" <?php echo $registros_por_pagina == 100 ? 'selected' : ''; ?>>100</option>
                                </select>
                                <span class="ml-2">registros</span>
                            </div>
                        </div>
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
                                <ul class="pagination pagination-sm m-0 float-right" id="pagination">
                                    <?php if ($pagina_actual > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="#" data-page="1">
                                                <i class="fas fa-angle-double-left"></i>
                                            </a>
                                        </li>
                                        <li class="page-item">
                                            <a class="page-link" href="#" data-page="<?php echo $pagina_actual - 1; ?>">
                                                <i class="fas fa-angle-left"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    $start_page = max(1, $pagina_actual - 2);
                                    $end_page = min($total_paginas, $pagina_actual + 2);
                                    for ($i = $start_page; $i <= $end_page; $i++): 
                                    ?>
                                        <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                            <a class="page-link" href="#" data-page="<?php echo $i; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($pagina_actual < $total_paginas): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="#" data-page="<?php echo $pagina_actual + 1; ?>">
                                                <i class="fas fa-angle-right"></i>
                                            </a>
                                        </li>
                                        <li class="page-item">
                                            <a class="page-link" href="#" data-page="<?php echo $total_paginas; ?>">
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
    
    <script>
        $(document).ready(function() {
            let timeoutId = null;
            
            // Función para obtener los parámetros actuales de los filtros
            function getCurrentFilters() {
                const busqueda = $('#busquedaInput').val();
                const tipo = $('#tipoSelect').val();
                const fechaDesde = $('#fechaDesde').val();
                const fechaHasta = $('#fechaHasta').val();
                const limite = $('#limiteSelect').val();
                
                return { busqueda, tipo, fechaDesde, fechaHasta, limite };
            }
            
            // Función para recargar la página con los filtros actuales
            function aplicarFiltros(pagina = 1) {
                const filters = getCurrentFilters();
                
                // Construir URL
                let params = [];
                if (filters.busqueda) params.push(`busqueda=${encodeURIComponent(filters.busqueda)}`);
                if (filters.tipo !== 'todos') params.push(`tipo=${encodeURIComponent(filters.tipo)}`);
                if (filters.fechaDesde) params.push(`fecha_desde=${encodeURIComponent(filters.fechaDesde)}`);
                if (filters.fechaHasta) params.push(`fecha_hasta=${encodeURIComponent(filters.fechaHasta)}`);
                if (filters.limite) params.push(`limite=${encodeURIComponent(filters.limite)}`);
                params.push(`pagina=${pagina}`);
                
                const url = '?' + params.join('&');
                window.location.href = url;
            }
            
            // Función para borrar todos los filtros
            function borrarFiltros() {
                // Limpiar los valores de los inputs
                $('#busquedaInput').val('');
                $('#tipoSelect').val('todos');
                $('#fechaDesde').val('');
                $('#fechaHasta').val('');
                $('#limiteSelect').val('20');
                
                // Redirigir sin filtros
                window.location.href = '?pagina=1&limite=20';
            }
            
            // Variable para controlar si ambas fechas están completas
            let ambasFechasCompletas = false;
            
            // Función para verificar si ambas fechas están completas
            function verificarFechas() {
                const fechaDesde = $('#fechaDesde').val();
                const fechaHasta = $('#fechaHasta').val();
                
                if (fechaDesde && fechaHasta) {
                    ambasFechasCompletas = true;
                } else {
                    ambasFechasCompletas = false;
                }
            }
            
            // Aplicar filtros en tiempo real con debounce
            $('.filter-input').on('input change', function() {
                clearTimeout(timeoutId);
                
                // Para campos de fecha, verificar si ambas están completas
                if ($(this).attr('id') === 'fechaDesde' || $(this).attr('id') === 'fechaHasta') {
                    verificarFechas();
                    // Solo aplicar si ambas fechas están completas O si es un cambio individual pero ya había ambas
                    if (ambasFechasCompletas || (!$('#fechaDesde').val() && !$('#fechaHasta').val())) {
                        timeoutId = setTimeout(function() {
                            aplicarFiltros(1);
                        }, 500);
                    }
                } else {
                    timeoutId = setTimeout(function() {
                        aplicarFiltros(1);
                    }, 500);
                }
            });
            
            // Manejar cambios en el select de límite
            $('#limiteSelect').on('change', function() {
                aplicarFiltros(1);
            });
            
            // Botón borrar filtros
            $('#borrarFiltrosBtn').on('click', function() {
                borrarFiltros();
            });
            
            // Paginación
            $('#pagination').on('click', 'a.page-link', function(e) {
                e.preventDefault();
                const pagina = $(this).data('page');
                if (pagina) {
                    aplicarFiltros(pagina);
                }
            });
            
            // Inicializar verificación de fechas
            verificarFechas();
        });
    </script>
</body>
</html>