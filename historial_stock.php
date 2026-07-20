<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once 'config/database.php';
require_once __DIR__ . '/includes/sucursal_context.php';

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

$usuario_id = (int) $_SESSION['user_id'];
$rol_usuario = strtolower(trim((string) (
    $_SESSION['user_rol_base']
    ?? $_SESSION['user_rol']
    ?? 'recepcionista'
)));

if ($rol_usuario === 'administrador') {
    $rol_usuario = 'admin';
}

$puede_vista_global = $rol_usuario === 'admin';
$vista_solicitada = strtolower(trim((string) (
    $_GET['vista'] ?? ''
)));

if ($vista_solicitada === 'global' && $puede_vista_global) {
    sucursal_activar_vista_global(
        $conn,
        $usuario_id
    );
} elseif ($vista_solicitada === 'sucursal') {
    sucursal_desactivar_vista_global();
}

$vista_global =
    $puede_vista_global
    && function_exists('sucursal_dashboard_vista_global')
    && sucursal_dashboard_vista_global();

$sucursal_id = (int) (
    $_SESSION['sucursal_id'] ?? 0
);

$sucursales_asignadas = sucursal_obtener_asignadas(
    $conn,
    $usuario_id
);

/*
 * El administrador debe poder consultar el consolidado. Si una instalación
 * antigua todavía no tiene sus asignaciones, se utilizan las sedes activas.
 */
if ($sucursales_asignadas === [] && $puede_vista_global) {
    $resultado_sucursales = $conn->query(
        "SELECT id, clave, nombre, es_matriz
         FROM sucursales
         WHERE estado = 'activa'
         ORDER BY es_matriz DESC, nombre ASC"
    );

    if ($resultado_sucursales) {
        while (
            $fila_sucursal =
                $resultado_sucursales->fetch_assoc()
        ) {
            $sucursales_asignadas[] = $fila_sucursal;
        }
    }
}

$sucursales_ids = [];
$sucursal_actual = null;

foreach ($sucursales_asignadas as $sucursal_asignada) {
    $id_asignado = (int) (
        $sucursal_asignada['id'] ?? 0
    );

    if ($id_asignado <= 0) {
        continue;
    }

    $sucursales_ids[] = $id_asignado;

    if ($id_asignado === $sucursal_id) {
        $sucursal_actual = $sucursal_asignada;
    }
}

$sucursales_ids = array_values(
    array_unique($sucursales_ids)
);

if ($sucursales_ids === []) {
    die(
        'No tienes sucursales activas disponibles '
        . 'para consultar el historial de stock.'
    );
}

if (!$vista_global && !$sucursal_actual) {
    die(
        'Selecciona una sucursal válida antes de '
        . 'consultar los movimientos de stock.'
    );
}

$sucursal_nombre = $vista_global
    ? 'Todas las sucursales'
    : trim((string) (
        $sucursal_actual['nombre'] ?? 'Sucursal'
    ));

$sucursal_clave = $vista_global
    ? 'GLOBAL'
    : trim((string) (
        $sucursal_actual['clave'] ?? ''
    ));

$total_sedes = count($sucursales_ids);

$limites_permitidos = [10, 20, 50, 100];
$registros_por_pagina = isset($_GET['limite'])
    ? (int) $_GET['limite']
    : 20;

if (
    !in_array(
        $registros_por_pagina,
        $limites_permitidos,
        true
    )
) {
    $registros_por_pagina = 20;
}

$pagina_actual = max(
    1,
    isset($_GET['pagina'])
        ? (int) $_GET['pagina']
        : 1
);

$busqueda = trim((string) (
    $_GET['busqueda'] ?? ''
));

$tipo_filtro = trim((string) (
    $_GET['tipo'] ?? 'todos'
));

$fecha_desde = trim((string) (
    $_GET['fecha_desde'] ?? ''
));

$fecha_hasta = trim((string) (
    $_GET['fecha_hasta'] ?? ''
));

$tipos_permitidos = [
    'todos',
    'inicial',
    'entrada',
    'salida',
    'correccion',
    'ajuste_minimo',
];

if (
    !in_array(
        $tipo_filtro,
        $tipos_permitidos,
        true
    )
) {
    $tipo_filtro = 'todos';
}

/*
 * Todas las consultas —tabla, conteo y tarjetas— utilizan exactamente
 * las mismas condiciones para que sus cifras siempre coincidan.
 */
$where = [];
$params = [];
$types = '';

if ($vista_global) {
    $marcadores = implode(
        ',',
        array_fill(
            0,
            count($sucursales_ids),
            '?'
        )
    );

    $where[] = "m.sucursal_id IN ($marcadores)";

    foreach ($sucursales_ids as $id_sede) {
        $params[] = $id_sede;
        $types .= 'i';
    }
} else {
    $where[] = 'm.sucursal_id = ?';
    $params[] = $sucursal_id;
    $types .= 'i';
}

if ($busqueda !== '') {
    $where[] = "(
        p.nombre LIKE ?
        OR m.motivo LIKE ?
        OR m.observaciones LIKE ?
        OR s.nombre LIKE ?
        OR s.clave LIKE ?
    )";

    $termino = '%' . $busqueda . '%';

    for ($i = 0; $i < 5; $i++) {
        $params[] = $termino;
        $types .= 's';
    }
}

if ($tipo_filtro !== 'todos') {
    $where[] = 'm.tipo_movimiento = ?';
    $params[] = $tipo_filtro;
    $types .= 's';
}

if ($fecha_desde !== '' && $fecha_hasta !== '') {
    $where[] =
        'DATE(m.fecha_movimiento) BETWEEN ? AND ?';

    $params[] = $fecha_desde;
    $params[] = $fecha_hasta;
    $types .= 'ss';
} elseif ($fecha_desde !== '') {
    $where[] =
        'DATE(m.fecha_movimiento) >= ?';

    $params[] = $fecha_desde;
    $types .= 's';
} elseif ($fecha_hasta !== '') {
    $where[] =
        'DATE(m.fecha_movimiento) <= ?';

    $params[] = $fecha_hasta;
    $types .= 's';
}

$where_sql = 'WHERE ' . implode(
    ' AND ',
    $where
);

$joins_sql = "
    FROM movimientos_stock m
    INNER JOIN productos p
        ON p.id = m.producto_id
    INNER JOIN usuarios u
        ON u.id = m.usuario_id
    LEFT JOIN sucursales s
        ON s.id = m.sucursal_id
";

$count_sql = "
    SELECT COUNT(*) AS total
    $joins_sql
    $where_sql
";

$count_stmt = $conn->prepare($count_sql);

if (!$count_stmt) {
    die(
        'Error en la consulta COUNT: '
        . $conn->error
    );
}

$params_count = $params;

bindParams(
    $count_stmt,
    $types,
    $params_count
);

$count_stmt->execute();

$total_registros = (int) (
    $count_stmt
        ->get_result()
        ->fetch_assoc()['total']
    ?? 0
);

$count_stmt->close();

$total_paginas = max(
    1,
    (int) ceil(
        $total_registros
        / $registros_por_pagina
    )
);

if ($pagina_actual > $total_paginas) {
    $pagina_actual = $total_paginas;
}

$offset =
    ($pagina_actual - 1)
    * $registros_por_pagina;

$sql = "
    SELECT
        m.*,
        p.nombre AS producto_nombre,
        u.nombre AS usuario_nombre,
        s.nombre AS sucursal_nombre,
        s.clave AS sucursal_clave,
        s.es_matriz AS sucursal_es_matriz
    $joins_sql
    $where_sql
    ORDER BY
        m.fecha_movimiento DESC,
        m.id DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die(
        'Error en la consulta principal: '
        . $conn->error
    );
}

$params_paginacion = $params;
$params_paginacion[] =
    $registros_por_pagina;
$params_paginacion[] = $offset;

$types_paginacion = $types . 'ii';

bindParams(
    $stmt,
    $types_paginacion,
    $params_paginacion
);

$stmt->execute();

$result = $stmt->get_result();
$movimientos = [];

while ($row = $result->fetch_assoc()) {
    $movimientos[] = $row;
}

$stmt->close();

$resumen_sql = "
    SELECT
        m.tipo_movimiento,
        COUNT(*) AS total,
        COALESCE(
            SUM(ABS(m.cantidad)),
            0
        ) AS suma_cantidad
    $joins_sql
    $where_sql
    GROUP BY m.tipo_movimiento
";

$resumen_stmt = $conn->prepare(
    $resumen_sql
);

if (!$resumen_stmt) {
    die(
        'Error en la consulta de resumen: '
        . $conn->error
    );
}

$params_resumen = $params;

bindParams(
    $resumen_stmt,
    $types,
    $params_resumen
);

$resumen_stmt->execute();

$resumen_result =
    $resumen_stmt->get_result();

$resumen = [];

while (
    $row =
        $resumen_result->fetch_assoc()
) {
    $resumen[$row['tipo_movimiento']] =
        $row;
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
    <?php
    $historialStockCss =
        __DIR__ . '/css/historial_stock.css';
    ?>
    <link
        rel="stylesheet"
        href="css/historial_stock.css?v=<?php echo is_file($historialStockCss) ? (int) filemtime($historialStockCss) : time(); ?>"
    >
</head>
<body>
<div class="dashboard-layout">
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="stock-page">
            <header class="page-header stock-context-header">
                <div class="page-title-wrap">
                    <span class="page-title-icon">
                        <i class="fas fa-boxes-stacked"></i>
                    </span>

                    <div>
                        <h1 class="page-title">
                            Historial de movimientos de stock
                        </h1>

                        <p class="page-subtitle">
                            <?php echo $vista_global
                                ? 'Consulta consolidada de entradas, salidas y ajustes de todas las sucursales.'
                                : 'Consulta los movimientos realizados en el inventario de ' . htmlspecialchars($sucursal_nombre, ENT_QUOTES, 'UTF-8') . '.';
                            ?>
                        </p>
                    </div>
                </div>

                <div class="stock-header-actions">
                    <span class="stock-context-badge <?php echo $vista_global ? 'global' : 'branch'; ?>">
                        <i class="fas <?php echo $vista_global ? 'fa-chart-pie' : 'fa-building'; ?>"></i>

                        <span>
                            <strong>
                                <?php echo htmlspecialchars(
                                    $sucursal_nombre,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </strong>

                            <small>
                                <?php echo htmlspecialchars(
                                    $vista_global
                                        ? $total_sedes . ($total_sedes === 1 ? ' sede consolidada' : ' sedes consolidadas')
                                        : ($sucursal_clave !== '' ? $sucursal_clave : 'Sucursal activa'),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </small>
                        </span>
                    </span>

                    <span class="results-chip">
                        <i class="fas fa-list-check"></i>
                        <strong>
                            <?php echo number_format($total_registros); ?>
                        </strong>
                        <span>movimientos</span>
                    </span>
                </div>
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
                        <input
                            type="hidden"
                            name="vista"
                            id="vistaStock"
                            value="<?php echo $vista_global ? 'global' : 'sucursal'; ?>"
                        >
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
                                        placeholder="<?php echo $vista_global ? 'Producto, sede, motivo u observación...' : 'Nombre, motivo u observación...'; ?>"
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
                        <table class="stock-table <?php echo $vista_global ? 'is-global' : ''; ?>" aria-label="Historial de movimientos de stock">
                            <thead>
                                <tr>
                                    <th class="col-date">Fecha y hora</th>
                                    <?php if ($vista_global): ?>
                                        <th class="col-branch">Sucursal</th>
                                    <?php endif; ?>
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

                                    <?php if ($vista_global): ?>
                                        <td data-label="Sucursal" class="col-branch">
                                            <span class="stock-branch-badge">
                                                <i class="fas fa-building"></i>

                                                <span>
                                                    <strong>
                                                        <?php echo htmlspecialchars(
                                                            (string) (
                                                                $mov['sucursal_nombre']
                                                                ?? 'Sin sucursal'
                                                            ),
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ); ?>
                                                    </strong>

                                                    <small>
                                                        <?php echo htmlspecialchars(
                                                            (string) (
                                                                $mov['sucursal_clave']
                                                                ?? ''
                                                            ),
                                                            ENT_QUOTES,
                                                            'UTF-8'
                                                        ); ?>

                                                        <?php if ((int) ($mov['sucursal_es_matriz'] ?? 0) === 1): ?>
                                                            · Matriz
                                                        <?php endif; ?>
                                                    </small>
                                                </span>
                                            </span>
                                        </td>
                                    <?php endif; ?>
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
            vista: $('#vistaStock').val() || 'sucursal',
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

        params.set('vista', filters.vista);
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
        const vista = $('#vistaStock').val() || 'sucursal';
        window.location.href = 'historial_stock.php?vista=' + encodeURIComponent(vista) + '&pagina=1&limite=20';
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