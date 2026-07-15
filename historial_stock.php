<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

if (!$conn) {
    die('Error de conexión a la base de datos');
}

function bindParams(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types !== '' && $params !== []) {
        $stmt->bind_param($types, ...$params);
    }
}

$limites_permitidos = [10, 20, 50, 100];
$registros_por_pagina = isset($_GET['limite']) ? (int) $_GET['limite'] : 20;
if (!in_array($registros_por_pagina, $limites_permitidos, true)) {
    $registros_por_pagina = 20;
}

$pagina_actual = max(1, isset($_GET['pagina']) ? (int) $_GET['pagina'] : 1);
$busqueda = trim((string) ($_GET['busqueda'] ?? ''));
$tipo_filtro = trim((string) ($_GET['tipo'] ?? 'todos'));
$fecha_desde = trim((string) ($_GET['fecha_desde'] ?? ''));
$fecha_hasta = trim((string) ($_GET['fecha_hasta'] ?? ''));

$tipos_permitidos = ['todos', 'inicial', 'entrada', 'salida', 'correccion', 'ajuste_minimo'];
if (!in_array($tipo_filtro, $tipos_permitidos, true)) {
    $tipo_filtro = 'todos';
}

$where_con_join = [];
$params_con_join = [];
$types_con_join = '';

$where_sin_join = [];
$params_sin_join = [];
$types_sin_join = '';

if ($busqueda !== '') {
    $where_con_join[] = '(p.nombre LIKE ? OR m.motivo LIKE ? OR m.observaciones LIKE ?)';
    $termino = '%' . $busqueda . '%';
    $params_con_join[] = $termino;
    $params_con_join[] = $termino;
    $params_con_join[] = $termino;
    $types_con_join .= 'sss';
}

if ($tipo_filtro !== 'todos') {
    $where_con_join[] = 'm.tipo_movimiento = ?';
    $params_con_join[] = $tipo_filtro;
    $types_con_join .= 's';

    $where_sin_join[] = 'tipo_movimiento = ?';
    $params_sin_join[] = $tipo_filtro;
    $types_sin_join .= 's';
}

if ($fecha_desde !== '' && $fecha_hasta !== '') {
    $where_con_join[] = 'DATE(m.fecha_movimiento) BETWEEN ? AND ?';
    $params_con_join[] = $fecha_desde;
    $params_con_join[] = $fecha_hasta;
    $types_con_join .= 'ss';

    $where_sin_join[] = 'DATE(fecha_movimiento) BETWEEN ? AND ?';
    $params_sin_join[] = $fecha_desde;
    $params_sin_join[] = $fecha_hasta;
    $types_sin_join .= 'ss';
} elseif ($fecha_desde !== '') {
    $where_con_join[] = 'DATE(m.fecha_movimiento) >= ?';
    $params_con_join[] = $fecha_desde;
    $types_con_join .= 's';

    $where_sin_join[] = 'DATE(fecha_movimiento) >= ?';
    $params_sin_join[] = $fecha_desde;
    $types_sin_join .= 's';
} elseif ($fecha_hasta !== '') {
    $where_con_join[] = 'DATE(m.fecha_movimiento) <= ?';
    $params_con_join[] = $fecha_hasta;
    $types_con_join .= 's';

    $where_sin_join[] = 'DATE(fecha_movimiento) <= ?';
    $params_sin_join[] = $fecha_hasta;
    $types_sin_join .= 's';
}

$where_sql_con_join = $where_con_join ? 'WHERE ' . implode(' AND ', $where_con_join) : '';
$where_sql_sin_join = $where_sin_join ? 'WHERE ' . implode(' AND ', $where_sin_join) : '';

$count_sql = "SELECT COUNT(*) AS total
              FROM movimientos_stock m
              INNER JOIN productos p ON p.id = m.producto_id
              $where_sql_con_join";
$count_stmt = $conn->prepare($count_sql);
if (!$count_stmt) {
    die('Error en la consulta COUNT: ' . $conn->error);
}
bindParams($count_stmt, $types_con_join, $params_con_join);
$count_stmt->execute();
$total_registros = (int) ($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$count_stmt->close();

$total_paginas = max(1, (int) ceil($total_registros / $registros_por_pagina));
if ($pagina_actual > $total_paginas) {
    $pagina_actual = $total_paginas;
}
$offset = ($pagina_actual - 1) * $registros_por_pagina;

$sql = "SELECT
            m.*,
            p.nombre AS producto_nombre,
            u.nombre AS usuario_nombre
        FROM movimientos_stock m
        INNER JOIN productos p ON p.id = m.producto_id
        INNER JOIN usuarios u ON u.id = m.usuario_id
        $where_sql_con_join
        ORDER BY m.fecha_movimiento DESC
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Error en la consulta principal: ' . $conn->error);
}
$params_paginacion = $params_con_join;
$params_paginacion[] = $registros_por_pagina;
$params_paginacion[] = $offset;
$types_paginacion = $types_con_join . 'ii';
bindParams($stmt, $types_paginacion, $params_paginacion);
$stmt->execute();
$result = $stmt->get_result();
$movimientos = [];
while ($row = $result->fetch_assoc()) {
    $movimientos[] = $row;
}
$stmt->close();

$resumen_sql = "SELECT
                    tipo_movimiento,
                    COUNT(*) AS total,
                    COALESCE(SUM(ABS(cantidad)), 0) AS suma_cantidad
                FROM movimientos_stock
                $where_sql_sin_join
                GROUP BY tipo_movimiento";
$resumen_stmt = $conn->prepare($resumen_sql);
if (!$resumen_stmt) {
    die('Error en la consulta de resumen: ' . $conn->error);
}
bindParams($resumen_stmt, $types_sin_join, $params_sin_join);
$resumen_stmt->execute();
$resumen_result = $resumen_stmt->get_result();
$resumen = [];
while ($row = $resumen_result->fetch_assoc()) {
    $resumen[$row['tipo_movimiento']] = $row;
}
$resumen_stmt->close();

$filtros_activos = 0;
$filtros_activos += $busqueda !== '' ? 1 : 0;
$filtros_activos += $tipo_filtro !== 'todos' ? 1 : 0;
$filtros_activos += $fecha_desde !== '' ? 1 : 0;
$filtros_activos += $fecha_hasta !== '' ? 1 : 0;

function tipoMovimientoMeta(string $tipo): array
{
    switch ($tipo) {
        case 'inicial':
            return ['Stock inicial', 'initial', 'fa-box'];

        case 'entrada':
            return ['Entrada', 'entry', 'fa-arrow-down'];

        case 'salida':
            return ['Salida', 'exit', 'fa-arrow-up'];

        case 'correccion':
            return ['Corrección', 'correction', 'fa-pen-to-square'];

        case 'ajuste_minimo':
            return ['Ajuste mínimo', 'adjustment', 'fa-sliders'];

        default:
            return [
                ucfirst(str_replace('_', ' ', $tipo)),
                'neutral',
                'fa-circle'
            ];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de stock - Gym System</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="css/historial_stock.css">
</head>
<body>
<div class="dashboard-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="stock-page">
            <header class="page-header">
                <div class="page-title-wrap">
                    <span class="page-title-icon"><i class="fas fa-boxes-stacked"></i></span>
                    <div>
                        <h1 class="page-title">Historial de movimientos de stock</h1>
                        <p class="page-subtitle">Consulta entradas, salidas y ajustes realizados en el inventario.</p>
                    </div>
                </div>
                <span class="results-chip">
                    <i class="fas fa-list-check"></i>
                    <strong><?php echo number_format($total_registros); ?></strong>
                    <span>movimientos</span>
                </span>
            </header>

            <section class="stats-grid" aria-label="Resumen de movimientos">
                <article class="stat-card info">
                    <div class="stat-copy">
                        <strong class="stat-value"><?php echo number_format((int) ($resumen['entrada']['total'] ?? 0)); ?></strong>
                        <span class="stat-label">Entradas de stock</span>
                    </div>
                    <span class="stat-icon"><i class="fas fa-arrow-down"></i></span>
                </article>

                <article class="stat-card success">
                    <div class="stat-copy">
                        <strong class="stat-value"><?php echo number_format((int) ($resumen['salida']['total'] ?? 0)); ?></strong>
                        <span class="stat-label">Salidas de stock</span>
                    </div>
                    <span class="stat-icon"><i class="fas fa-arrow-up"></i></span>
                </article>

                <article class="stat-card warning">
                    <div class="stat-copy">
                        <strong class="stat-value"><?php echo number_format((int) ($resumen['correccion']['total'] ?? 0)); ?></strong>
                        <span class="stat-label">Correcciones</span>
                    </div>
                    <span class="stat-icon"><i class="fas fa-sliders-h"></i></span>
                </article>

                <article class="stat-card danger">
                    <div class="stat-copy">
                        <strong class="stat-value"><?php echo number_format($total_registros); ?></strong>
                        <span class="stat-label">Total de movimientos</span>
                    </div>
                    <span class="stat-icon"><i class="fas fa-chart-line"></i></span>
                </article>
            </section>

            <section class="module-card filter-card">
                <div class="module-header">
                    <h2 class="module-title">
                        <i class="fas fa-filter"></i>
                        Filtros de búsqueda
                        <?php if ($filtros_activos > 0): ?>
                            <span class="filter-count"><?php echo $filtros_activos; ?></span>
                        <?php endif; ?>
                    </h2>
                </div>

                <div class="filter-body">
                    <form method="GET" id="filtrosForm" autocomplete="off">
                        <div class="filters-grid">
                            <div class="field-group search-field">
                                <label class="field-label" for="busquedaInput">Buscar producto o motivo</label>
                                <div class="field-control-wrap">
                                    <i class="fas fa-search"></i>
                                    <input
                                        type="search"
                                        name="busqueda"
                                        id="busquedaInput"
                                        class="filter-control has-icon filter-input"
                                        placeholder="Nombre, motivo u observación..."
                                        value="<?php echo htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                </div>
                            </div>

                            <div class="field-group">
                                <label class="field-label" for="tipoSelect">Tipo de movimiento</label>
                                <select name="tipo" id="tipoSelect" class="filter-control filter-input">
                                    <option value="todos" <?php echo $tipo_filtro === 'todos' ? 'selected' : ''; ?>>Todos</option>
                                    <option value="inicial" <?php echo $tipo_filtro === 'inicial' ? 'selected' : ''; ?>>Stock inicial</option>
                                    <option value="entrada" <?php echo $tipo_filtro === 'entrada' ? 'selected' : ''; ?>>Entradas</option>
                                    <option value="salida" <?php echo $tipo_filtro === 'salida' ? 'selected' : ''; ?>>Salidas</option>
                                    <option value="correccion" <?php echo $tipo_filtro === 'correccion' ? 'selected' : ''; ?>>Correcciones</option>
                                    <option value="ajuste_minimo" <?php echo $tipo_filtro === 'ajuste_minimo' ? 'selected' : ''; ?>>Ajuste mínimo</option>
                                </select>
                            </div>

                            <div class="field-group">
                                <label class="field-label" for="fechaDesde">Desde</label>
                                <input
                                    type="date"
                                    name="fecha_desde"
                                    id="fechaDesde"
                                    class="filter-control filter-input"
                                    value="<?php echo htmlspecialchars($fecha_desde, ENT_QUOTES, 'UTF-8'); ?>"
                                >
                            </div>

                            <div class="field-group">
                                <label class="field-label" for="fechaHasta">Hasta</label>
                                <input
                                    type="date"
                                    name="fecha_hasta"
                                    id="fechaHasta"
                                    class="filter-control filter-input"
                                    value="<?php echo htmlspecialchars($fecha_hasta, ENT_QUOTES, 'UTF-8'); ?>"
                                >
                            </div>

                            <div class="field-group clear-field">
                                <button type="button" class="btn-clear" id="borrarFiltrosBtn">
                                    <i class="fas fa-rotate-left"></i>
                                    Limpiar
                                </button>
                            </div>
                        </div>

                        <p class="filter-help">
                            <i class="fas fa-circle-info"></i>
                            Puedes usar una sola fecha o seleccionar un rango completo.
                        </p>
                    </form>
                </div>
            </section>

            <section class="module-card" id="tablaContainer">
                <div class="module-header">
                    <div class="table-toolbar">
                        <h2 class="module-title"><i class="fas fa-list"></i> Movimientos registrados</h2>
                    </div>
                </div>

                <?php if ($movimientos): ?>
                    <div class="stock-table-wrap">
                        <table class="stock-table" aria-label="Historial de movimientos de stock">
                            <thead>
                                <tr>
                                    <th class="col-date">Fecha y hora</th>
                                    <th class="col-product">Producto</th>
                                    <th class="col-type">Tipo</th>
                                    <th class="col-qty">Cantidad</th>
                                    <th class="col-stock">Anterior</th>
                                    <th class="col-stock">Nuevo</th>
                                    <th class="col-reason">Motivo</th>
                                    <th class="col-user">Usuario</th>
                                    <th class="col-notes">Observaciones</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($movimientos as $mov): ?>
                                <?php
                                [$tipo_texto, $tipo_clase, $tipo_icono] = tipoMovimientoMeta((string) $mov['tipo_movimiento']);
                                $cantidad = (int) $mov['cantidad'];
                                $fechaTimestamp = strtotime((string) $mov['fecha_movimiento']);
                                ?>
                                <tr>
                                    <td data-label="Fecha y hora" class="col-date">
                                        <span class="date-cell">
                                            <strong><?php echo date('d/m/Y', $fechaTimestamp); ?></strong>
                                            <span><?php echo date('H:i:s', $fechaTimestamp); ?></span>
                                        </span>
                                    </td>
                                    <td data-label="Producto" class="col-product">
                                        <span class="product-name"><?php echo htmlspecialchars((string) $mov['producto_nombre'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </td>
                                    <td data-label="Tipo" class="col-type">
                                        <span class="type-badge <?php echo $tipo_clase; ?>">
                                            <i class="fas <?php echo $tipo_icono; ?>"></i>
                                            <?php echo htmlspecialchars($tipo_texto, ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                    <td data-label="Cantidad" class="col-qty">
                                        <span class="quantity <?php echo $cantidad >= 0 ? 'positive' : 'negative'; ?>">
                                            <?php echo $cantidad >= 0 ? '+' : ''; ?><?php echo $cantidad; ?>
                                        </span>
                                    </td>
                                    <td data-label="Stock anterior" class="col-stock">
                                        <?php echo (int) $mov['stock_anterior']; ?>
                                    </td>
                                    <td data-label="Stock nuevo" class="col-stock">
                                        <span class="stock-value"><?php echo (int) $mov['stock_nuevo']; ?></span>
                                    </td>
                                    <td data-label="Motivo" class="col-reason">
                                        <?php echo htmlspecialchars((string) ($mov['motivo'] ?: 'Sin motivo'), ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td data-label="Usuario" class="col-user">
                                        <?php echo htmlspecialchars((string) $mov['usuario_nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td data-label="Observaciones" class="col-notes">
                                        <span class="notes-text" title="<?php echo htmlspecialchars((string) ($mov['observaciones'] ?: 'Sin observaciones'), ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars((string) ($mov['observaciones'] ?: 'Sin observaciones'), ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <span class="empty-state-icon"><i class="fas fa-box-open"></i></span>
                        <h4>No se encontraron movimientos</h4>
                        <p>Prueba cambiando o limpiando los filtros seleccionados.</p>
                    </div>
                <?php endif; ?>

                <?php if ($movimientos): ?>
                    <footer class="table-footer">
                        <div class="table-footer-meta">
                            <label class="limit-control" for="limiteSelect">
                                <span>Mostrar</span>
                                <select id="limiteSelect" aria-label="Registros por página">
                                    <?php foreach ($limites_permitidos as $limite): ?>
                                        <option value="<?php echo $limite; ?>" <?php echo $registros_por_pagina === $limite ? 'selected' : ''; ?>>
                                            <?php echo $limite; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span>registros</span>
                            </label>

                            <div class="table-info">
                                Mostrando <strong><?php echo count($movimientos); ?></strong> de
                                <strong><?php echo number_format($total_registros); ?></strong> movimientos ·
                                Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?>
                            </div>
                        </div>

                        <?php if ($total_paginas > 1): ?>
                            <ul class="pagination pagination-sm" id="pagination" aria-label="Paginación">
                                <?php if ($pagina_actual > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="#" data-page="1" aria-label="Primera página"><i class="fas fa-angles-left"></i></a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="#" data-page="<?php echo $pagina_actual - 1; ?>" aria-label="Página anterior"><i class="fas fa-angle-left"></i></a>
                                    </li>
                                <?php endif; ?>

                                <?php
                                $start_page = max(1, $pagina_actual - 2);
                                $end_page = min($total_paginas, $pagina_actual + 2);
                                for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                    <li class="page-item <?php echo $i === $pagina_actual ? 'active' : ''; ?>">
                                        <a class="page-link" href="#" data-page="<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($pagina_actual < $total_paginas): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="#" data-page="<?php echo $pagina_actual + 1; ?>" aria-label="Página siguiente"><i class="fas fa-angle-right"></i></a>
                                    </li>
                                    <li class="page-item">
                                        <a class="page-link" href="#" data-page="<?php echo $total_paginas; ?>" aria-label="Última página"><i class="fas fa-angles-right"></i></a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        <?php endif; ?>
                    </footer>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(function () {
    let debounceId = null;

    function getCurrentFilters() {
        return {
            busqueda: $('#busquedaInput').val().trim(),
            tipo: $('#tipoSelect').val(),
            fechaDesde: $('#fechaDesde').val(),
            fechaHasta: $('#fechaHasta').val(),
            limite: $('#limiteSelect').val()
        };
    }

    function aplicarFiltros(pagina = 1) {
        const filters = getCurrentFilters();
        const params = new URLSearchParams();

        if (filters.busqueda) params.set('busqueda', filters.busqueda);
        if (filters.tipo && filters.tipo !== 'todos') params.set('tipo', filters.tipo);
        if (filters.fechaDesde) params.set('fecha_desde', filters.fechaDesde);
        if (filters.fechaHasta) params.set('fecha_hasta', filters.fechaHasta);
        params.set('limite', filters.limite || '20');
        params.set('pagina', String(pagina));

        window.location.href = '?' + params.toString();
    }

    $('#busquedaInput').on('input', function () {
        clearTimeout(debounceId);
        debounceId = setTimeout(() => aplicarFiltros(1), 550);
    });

    $('#tipoSelect, #fechaDesde, #fechaHasta').on('change', function () {
        clearTimeout(debounceId);
        debounceId = setTimeout(() => aplicarFiltros(1), 250);
    });

    $('#limiteSelect').on('change', function () {
        aplicarFiltros(1);
    });

    $('#borrarFiltrosBtn').on('click', function () {
        window.location.href = '?pagina=1&limite=20';
    });

    $('#pagination').on('click', 'a.page-link', function (event) {
        event.preventDefault();
        const page = Number($(this).data('page'));
        if (Number.isInteger(page) && page > 0) {
            aplicarFiltros(page);
        }
    });
});
</script>
</body>
</html>