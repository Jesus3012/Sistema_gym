<?php
// Archivo: inventario.php
// Inventario visual en tarjetas con filtros y paginación.

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/permisos_helper.php';
require_once __DIR__ . '/includes/legal_guard.php';
require_once __DIR__ . '/includes/sucursal_context.php';

$rolActual = strtolower(trim((string) ($_SESSION['user_rol'] ?? '')));

if (!permisos_es_admin($rolActual)) {
    $_SESSION['alerta_acceso_denegado'] = [
        'titulo' => 'Acceso restringido',
        'mensaje' => 'El inventario general está disponible únicamente para administradores.',
        'rol' => ucfirst($rolActual ?: 'Usuario'),
        'modulo' => 'Inventario',
    ];

    header('Location: dashboard.php?error=acceso_denegado');
    exit();
}

legal_require_acceptance();

$database = new Database();
$conn = $database->getConnection();

if (!$conn) {
    die('No fue posible conectar con la base de datos.');
}

$conn->set_charset('utf8mb4');

$usuarioId = (int) $_SESSION['user_id'];
$vistaSolicitada = strtolower(trim((string) ($_GET['vista'] ?? '')));

if ($vistaSolicitada === 'global') {
    sucursal_activar_vista_global($conn, $usuarioId);
} elseif ($vistaSolicitada === 'sucursal') {
    sucursal_desactivar_vista_global();
}

$vistaGlobal = function_exists('sucursal_dashboard_vista_global')
    && sucursal_dashboard_vista_global();

$sucursalId = (int) ($_SESSION['sucursal_id'] ?? 0);
$sucursalesAsignadas = sucursal_obtener_asignadas($conn, $usuarioId);

/*
 * Como el módulo es exclusivo de administradores, si por alguna migración
 * antigua el usuario aún no tiene filas en usuarios_sucursales, se usan
 * las sucursales activas para no dejar el inventario sin contexto.
 */
if ($sucursalesAsignadas === []) {
    $resultadoSucursales = $conn->query(
        "SELECT id, clave, nombre, es_matriz
         FROM sucursales
         WHERE estado = 'activa'
         ORDER BY es_matriz DESC, nombre ASC"
    );

    if ($resultadoSucursales) {
        while ($filaSucursal = $resultadoSucursales->fetch_assoc()) {
            $sucursalesAsignadas[] = $filaSucursal;
        }
    }
}

$sucursalActual = null;
$sucursalesIds = [];

foreach ($sucursalesAsignadas as $sucursalAsignada) {
    $idAsignado = (int) ($sucursalAsignada['id'] ?? 0);

    if ($idAsignado <= 0) {
        continue;
    }

    $sucursalesIds[] = $idAsignado;

    if ($idAsignado === $sucursalId) {
        $sucursalActual = $sucursalAsignada;
    }
}

$sucursalesIds = array_values(array_unique($sucursalesIds));

if ($sucursalesIds === []) {
    die('No existen sucursales activas disponibles para consultar el inventario.');
}

if (!$vistaGlobal && !$sucursalActual) {
    die('No hay una sucursal operativa seleccionada para consultar el inventario.');
}

$sucursalNombre = $sucursalActual
    ? trim((string) ($sucursalActual['nombre'] ?? 'Sucursal'))
    : 'Todas las sucursales';

$sucursalClave = $sucursalActual
    ? trim((string) ($sucursalActual['clave'] ?? ''))
    : '';

$totalSedes = count($sucursalesIds);

function inventario_h($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function inventario_bind(mysqli_stmt $stmt, string $tipos, array &$parametros): void
{
    if ($tipos === '' || $parametros === []) {
        return;
    }

    $referencias = [$tipos];

    foreach ($parametros as $indice => $_valor) {
        $referencias[] = &$parametros[$indice];
    }

    call_user_func_array([$stmt, 'bind_param'], $referencias);
}

function inventario_imagen(string $ruta): string
{
    $ruta = ltrim(trim($ruta), '/\\');

    if ($ruta !== '' && is_file(__DIR__ . DIRECTORY_SEPARATOR . $ruta)) {
        return $ruta;
    }

    return '';
}

function inventario_estado_stock(array $producto): array
{
    if (($producto['estado'] ?? '') !== 'activo') {
        return [
            'texto' => 'Inactivo',
            'clase' => 'inactive',
            'icono' => 'fa-circle-pause',
        ];
    }

    $stock = (int) ($producto['stock'] ?? 0);
    $minimo = (int) ($producto['stock_minimo'] ?? 0);

    if ($stock <= 0) {
        return [
            'texto' => 'Agotado',
            'clase' => 'out',
            'icono' => 'fa-circle-xmark',
        ];
    }

    if ($stock <= $minimo) {
        return [
            'texto' => 'Stock bajo',
            'clase' => 'low',
            'icono' => 'fa-triangle-exclamation',
        ];
    }

    return [
        'texto' => 'Disponible',
        'clase' => 'available',
        'icono' => 'fa-circle-check',
    ];
}

function inventario_url(array $base, array $cambios = []): string
{
    $parametros = array_merge($base, $cambios);

    foreach ($parametros as $clave => $valor) {
        if ($valor === '' || $valor === null || $valor === 'todos' || $valor === 0 || $valor === '0') {
            unset($parametros[$clave]);
        }
    }

    $query = http_build_query($parametros);

    return 'inventario.php' . ($query !== '' ? '?' . $query : '');
}

function inventario_rango_precio($minimo, $maximo): string
{
    if ($minimo === null || $maximo === null) {
        return 'Sin precio';
    }

    $minimo = round((float) $minimo, 2);
    $maximo = round((float) $maximo, 2);

    if (abs($maximo - $minimo) <= 0.009) {
        return '$' . number_format($minimo, 2);
    }

    return '$'
        . number_format($minimo, 2)
        . ' – $'
        . number_format($maximo, 2);
}

$busqueda = trim((string) ($_GET['busqueda'] ?? ''));
$categoriaId = max(0, (int) ($_GET['categoria'] ?? 0));
$estado = (string) ($_GET['estado'] ?? 'todos');
$existencia = (string) ($_GET['existencia'] ?? 'todos');
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$porPaginaPermitidos = [8, 12, 16, 24];
$porPagina = (int) ($_GET['por_pagina'] ?? 12);

if (!in_array($porPagina, $porPaginaPermitidos, true)) {
    $porPagina = 12;
}

if (!in_array($estado, ['todos', 'activo', 'inactivo'], true)) {
    $estado = 'todos';
}

if (!in_array($existencia, ['todos', 'disponible', 'bajo', 'agotado'], true)) {
    $existencia = 'todos';
}

$categorias = [];
$resultadoCategorias = $conn->query(
    "SELECT id, nombre
     FROM categorias_productos
     WHERE estado = 'activo'
     ORDER BY nombre ASC"
);

if ($resultadoCategorias) {
    while ($fila = $resultadoCategorias->fetch_assoc()) {
        $categorias[] = $fila;
    }
}

$productos = [];
$totalProductos = 0;

if ($vistaGlobal) {
    /*
     * La vista global devuelve una tarjeta por producto y consolida
     * únicamente las sucursales disponibles para el usuario.
     */
    $marcadoresSucursales = implode(
        ',',
        array_fill(0, count($sucursalesIds), '?')
    );

    $where = [
        "inv.sucursal_id IN ($marcadoresSucursales)",
    ];
    $parametros = $sucursalesIds;
    $tipos = str_repeat('i', count($sucursalesIds));
    $having = [];

    if ($busqueda !== '') {
        $where[] = '(p.nombre LIKE ? OR p.descripcion LIKE ? OR c.nombre LIKE ? OR prov.nombre LIKE ?)';
        $termino = '%' . $busqueda . '%';

        for ($i = 0; $i < 4; $i++) {
            $parametros[] = $termino;
        }

        $tipos .= 'ssss';
    }

    if ($categoriaId > 0) {
        $where[] = 'p.categoria_id = ?';
        $parametros[] = $categoriaId;
        $tipos .= 'i';
    }

    $conteoActivasSql =
        "SUM(CASE WHEN inv.estado = 'activo' THEN 1 ELSE 0 END)";

    if ($estado === 'activo') {
        $having[] =
            "p.estado = 'activo' AND $conteoActivasSql > 0";
    } elseif ($estado === 'inactivo') {
        $having[] =
            "(p.estado <> 'activo' OR $conteoActivasSql = 0)";
    }

    if ($existencia === 'disponible') {
        $having[] =
            "p.estado = 'activo'
             AND $conteoActivasSql > 0
             AND SUM(inv.stock) > SUM(inv.stock_minimo)";
    } elseif ($existencia === 'bajo') {
        $having[] =
            "p.estado = 'activo'
             AND $conteoActivasSql > 0
             AND SUM(inv.stock) > 0
             AND SUM(inv.stock) <= SUM(inv.stock_minimo)";
    } elseif ($existencia === 'agotado') {
        $having[] =
            "p.estado = 'activo'
             AND $conteoActivasSql > 0
             AND SUM(inv.stock) <= 0";
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $havingSql = $having
        ? 'HAVING ' . implode(' AND ', $having)
        : '';

    $sqlBaseGlobal = "SELECT
                        p.id,
                        p.nombre,
                        p.descripcion,
                        p.foto,
                        p.fecha_registro,
                        p.estado AS catalogo_estado,
                        c.nombre AS categoria_nombre,
                        prov.nombre AS proveedor_nombre,
                        COUNT(inv.id) AS sucursales_count,
                        $conteoActivasSql AS sucursales_activas,
                        COALESCE(SUM(inv.stock), 0) AS stock,
                        COALESCE(SUM(inv.stock_minimo), 0) AS stock_minimo,
                        MIN(inv.precio_compra) AS precio_compra_min,
                        MAX(inv.precio_compra) AS precio_compra_max,
                        MIN(inv.precio_venta) AS precio_venta_min,
                        MAX(inv.precio_venta) AS precio_venta_max,
                        CASE
                            WHEN p.estado = 'activo'
                             AND $conteoActivasSql > 0
                            THEN 'activo'
                            ELSE 'inactivo'
                        END AS estado
                     FROM inventario_sucursales inv
                     INNER JOIN productos p
                        ON p.id = inv.producto_id
                     LEFT JOIN categorias_productos c
                        ON c.id = p.categoria_id
                     LEFT JOIN proveedores prov
                        ON prov.id = p.proveedor_id
                     $whereSql
                     GROUP BY
                        p.id,
                        p.nombre,
                        p.descripcion,
                        p.foto,
                        p.fecha_registro,
                        p.estado,
                        c.nombre,
                        prov.nombre";

    $sqlConteo = "SELECT COUNT(*) AS total
                  FROM (
                      $sqlBaseGlobal
                      $havingSql
                  ) inventario_consolidado";

    $stmtConteo = $conn->prepare($sqlConteo);

    if (!$stmtConteo) {
        die(
            'No fue posible preparar el conteo consolidado: '
            . inventario_h($conn->error)
        );
    }

    $parametrosConteo = $parametros;
    inventario_bind(
        $stmtConteo,
        $tipos,
        $parametrosConteo
    );
    $stmtConteo->execute();
    $totalProductos = (int) (
        $stmtConteo
            ->get_result()
            ->fetch_assoc()['total']
        ?? 0
    );
    $stmtConteo->close();

    $totalPaginas = max(
        1,
        (int) ceil($totalProductos / $porPagina)
    );
    $pagina = min($pagina, $totalPaginas);
    $offset = ($pagina - 1) * $porPagina;

    $sqlProductos = "$sqlBaseGlobal
                     $havingSql
                     ORDER BY p.nombre ASC, p.id DESC
                     LIMIT ? OFFSET ?";

    $parametrosProductos = $parametros;
    $parametrosProductos[] = $porPagina;
    $parametrosProductos[] = $offset;
    $tiposProductos = $tipos . 'ii';
} else {
    /*
     * La vista de sucursal obtiene precios y existencias únicamente
     * desde inventario_sucursales.
     */
    $where = ['inv.sucursal_id = ?'];
    $parametros = [$sucursalId];
    $tipos = 'i';

    if ($busqueda !== '') {
        $where[] = '(p.nombre LIKE ? OR p.descripcion LIKE ? OR c.nombre LIKE ? OR prov.nombre LIKE ?)';
        $termino = '%' . $busqueda . '%';

        for ($i = 0; $i < 4; $i++) {
            $parametros[] = $termino;
        }

        $tipos .= 'ssss';
    }

    if ($categoriaId > 0) {
        $where[] = 'p.categoria_id = ?';
        $parametros[] = $categoriaId;
        $tipos .= 'i';
    }

    if ($estado === 'activo') {
        $where[] =
            "p.estado = 'activo'
             AND inv.estado = 'activo'";
    } elseif ($estado === 'inactivo') {
        $where[] =
            "(p.estado <> 'activo'
              OR inv.estado <> 'activo')";
    }

    if ($existencia === 'disponible') {
        $where[] =
            "p.estado = 'activo'
             AND inv.estado = 'activo'
             AND inv.stock > inv.stock_minimo";
    } elseif ($existencia === 'bajo') {
        $where[] =
            "p.estado = 'activo'
             AND inv.estado = 'activo'
             AND inv.stock > 0
             AND inv.stock <= inv.stock_minimo";
    } elseif ($existencia === 'agotado') {
        $where[] =
            "p.estado = 'activo'
             AND inv.estado = 'activo'
             AND inv.stock <= 0";
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $sqlConteo = "SELECT COUNT(*) AS total
                  FROM inventario_sucursales inv
                  INNER JOIN productos p
                    ON p.id = inv.producto_id
                  LEFT JOIN categorias_productos c
                    ON c.id = p.categoria_id
                  LEFT JOIN proveedores prov
                    ON prov.id = p.proveedor_id
                  $whereSql";

    $stmtConteo = $conn->prepare($sqlConteo);

    if (!$stmtConteo) {
        die(
            'No fue posible preparar el conteo de la sucursal: '
            . inventario_h($conn->error)
        );
    }

    $parametrosConteo = $parametros;
    inventario_bind(
        $stmtConteo,
        $tipos,
        $parametrosConteo
    );
    $stmtConteo->execute();
    $totalProductos = (int) (
        $stmtConteo
            ->get_result()
            ->fetch_assoc()['total']
        ?? 0
    );
    $stmtConteo->close();

    $totalPaginas = max(
        1,
        (int) ceil($totalProductos / $porPagina)
    );
    $pagina = min($pagina, $totalPaginas);
    $offset = ($pagina - 1) * $porPagina;

    $sqlProductos = "SELECT
                        p.id,
                        p.nombre,
                        p.descripcion,
                        p.foto,
                        p.fecha_registro,
                        c.nombre AS categoria_nombre,
                        prov.nombre AS proveedor_nombre,
                        inv.precio_compra,
                        inv.precio_venta,
                        inv.stock,
                        inv.stock_minimo,
                        CASE
                            WHEN p.estado = 'activo'
                             AND inv.estado = 'activo'
                            THEN 'activo'
                            ELSE 'inactivo'
                        END AS estado
                     FROM inventario_sucursales inv
                     INNER JOIN productos p
                        ON p.id = inv.producto_id
                     LEFT JOIN categorias_productos c
                        ON c.id = p.categoria_id
                     LEFT JOIN proveedores prov
                        ON prov.id = p.proveedor_id
                     $whereSql
                     ORDER BY p.nombre ASC, p.id DESC
                     LIMIT ? OFFSET ?";

    $parametrosProductos = $parametros;
    $parametrosProductos[] = $porPagina;
    $parametrosProductos[] = $offset;
    $tiposProductos = $tipos . 'ii';
}

$stmtProductos = $conn->prepare($sqlProductos);

if (!$stmtProductos) {
    die(
        'No fue posible consultar los productos: '
        . inventario_h($conn->error)
    );
}

inventario_bind(
    $stmtProductos,
    $tiposProductos,
    $parametrosProductos
);
$stmtProductos->execute();
$resultadoProductos = $stmtProductos->get_result();

while ($fila = $resultadoProductos->fetch_assoc()) {
    $productos[] = $fila;
}

$stmtProductos->close();

$queryBase = [
    'vista' => $vistaGlobal ? 'global' : 'sucursal',
    'busqueda' => $busqueda,
    'categoria' => $categoriaId,
    'estado' => $estado,
    'existencia' => $existencia,
    'por_pagina' => $porPagina,
];

$desde = $totalProductos > 0
    ? $offset + 1
    : 0;

$hasta = min(
    $offset + count($productos),
    $totalProductos
);

$paginasVisibles = [];
$inicioPaginas = max(1, $pagina - 2);
$finPaginas = min($totalPaginas, $pagina + 2);

for (
    $numero = $inicioPaginas;
    $numero <= $finPaginas;
    $numero++
) {
    $paginasVisibles[] = $numero;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario - Sistema Gimnasio</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <?php
    $inventarioCss = __DIR__ . '/css/inventario.css';
    $inventarioMultisucursalCss =
        __DIR__ . '/css/inventario_multisucursal.css';
    ?>
    <link
        rel="stylesheet"
        href="css/inventario.css?v=<?php echo is_file($inventarioCss) ? (int) filemtime($inventarioCss) : time(); ?>"
    >
    <link
        rel="stylesheet"
        href="css/inventario_multisucursal.css?v=<?php echo is_file($inventarioMultisucursalCss) ? (int) filemtime($inventarioMultisucursalCss) : time(); ?>"
    >
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content inventory-catalog-main">
        <div class="inventory-catalog-page">
            <header class="inventory-catalog-header inventory-context-header">
                <div class="inventory-catalog-title">
                    <span class="inventory-catalog-title-icon" aria-hidden="true">
                        <i class="fas fa-boxes-stacked"></i>
                    </span>
                    <div>
                        <h1>Inventario</h1>
                        <p>
                            <?php echo $vistaGlobal
                                ? 'Consulta consolidada de precios y existencias de todas las sucursales.'
                                : 'Consulta visual del inventario operativo de ' . inventario_h($sucursalNombre) . '.';
                            ?>
                        </p>
                    </div>
                </div>

                <div class="inventory-header-actions">
                    <span class="inventory-context-badge <?php echo $vistaGlobal ? 'global' : 'branch'; ?>">
                        <i class="fas <?php echo $vistaGlobal ? 'fa-chart-pie' : 'fa-building'; ?>"></i>
                        <span>
                            <strong>
                                <?php echo inventario_h(
                                    $vistaGlobal
                                        ? 'Todas las sucursales'
                                        : $sucursalNombre
                                ); ?>
                            </strong>
                            <small>
                                <?php echo inventario_h(
                                    $vistaGlobal
                                        ? $totalSedes . ($totalSedes === 1 ? ' sede consolidada' : ' sedes consolidadas')
                                        : ($sucursalClave !== '' ? $sucursalClave : 'Sucursal activa')
                                ); ?>
                            </small>
                        </span>
                    </span>

                    <span class="inventory-catalog-total">
                        <strong><?php echo number_format($totalProductos); ?></strong>
                        <?php echo $totalProductos === 1 ? 'producto' : 'productos'; ?>
                    </span>
                </div>
            </header>

            <section class="inventory-catalog-panel" aria-label="Productos del inventario">
                <form method="GET" class="inventory-catalog-filters" id="inventoryFilterForm">
                    <input
                        type="hidden"
                        name="vista"
                        value="<?php echo $vistaGlobal ? 'global' : 'sucursal'; ?>"
                    >
                    <label class="inventory-catalog-search">
                        <span class="sr-only">Buscar producto</span>
                        <i class="fas fa-magnifying-glass"></i>
                        <input
                            type="search"
                            name="busqueda"
                            value="<?php echo inventario_h($busqueda); ?>"
                            placeholder="Buscar producto, descripción o proveedor"
                            autocomplete="off"
                        >
                    </label>

                    <label>
                        <span class="sr-only">Filtrar por categoría</span>
                        <select name="categoria">
                            <option value="0">Todas las categorías</option>
                            <?php foreach ($categorias as $categoria): ?>
                                <option
                                    value="<?php echo (int) $categoria['id']; ?>"
                                    <?php echo $categoriaId === (int) $categoria['id'] ? 'selected' : ''; ?>
                                >
                                    <?php echo inventario_h($categoria['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <span class="sr-only">Filtrar por existencia</span>
                        <select name="existencia">
                            <option value="todos" <?php echo $existencia === 'todos' ? 'selected' : ''; ?>>Toda existencia</option>
                            <option value="disponible" <?php echo $existencia === 'disponible' ? 'selected' : ''; ?>>Disponible</option>
                            <option value="bajo" <?php echo $existencia === 'bajo' ? 'selected' : ''; ?>>Stock bajo</option>
                            <option value="agotado" <?php echo $existencia === 'agotado' ? 'selected' : ''; ?>>Agotado</option>
                        </select>
                    </label>

                    <label>
                        <span class="sr-only">Filtrar por estado</span>
                        <select name="estado">
                            <option value="todos" <?php echo $estado === 'todos' ? 'selected' : ''; ?>>Todos los estados</option>
                            <option value="activo" <?php echo $estado === 'activo' ? 'selected' : ''; ?>>Activos</option>
                            <option value="inactivo" <?php echo $estado === 'inactivo' ? 'selected' : ''; ?>>Inactivos</option>
                        </select>
                    </label>

                    <label>
                        <span class="sr-only">Productos por página</span>
                        <select name="por_pagina">
                            <?php foreach ($porPaginaPermitidos as $cantidad): ?>
                                <option value="<?php echo $cantidad; ?>" <?php echo $porPagina === $cantidad ? 'selected' : ''; ?>>
                                    <?php echo $cantidad; ?> por página
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <a href="<?php echo inventario_h(inventario_url(['vista' => $vistaGlobal ? 'global' : 'sucursal'])); ?>" class="inventory-catalog-clear" aria-label="Borrar filtros" title="Borrar filtros">
                        <i class="fas fa-rotate-left"></i>
                    </a>
                </form>

                <?php if ($productos): ?>
                    <div class="inventory-grid">
                        <?php foreach ($productos as $producto): ?>
                            <?php
                            $imagen = inventario_imagen((string) ($producto['foto'] ?? ''));
                            $stockEstado = inventario_estado_stock($producto);
                            $proveedor = trim((string) ($producto['proveedor_nombre'] ?? ''));
                            $categoria = trim((string) ($producto['categoria_nombre'] ?? ''));
                            ?>
                            <article class="inventory-card <?php echo $vistaGlobal ? 'is-global' : ''; ?>">
                                <div class="inventory-card-media">
                                    <?php if ($imagen !== ''): ?>
                                        <img
                                            src="<?php echo inventario_h($imagen); ?>"
                                            alt="<?php echo inventario_h($producto['nombre']); ?>"
                                            loading="lazy"
                                        >
                                    <?php else: ?>
                                        <span class="inventory-card-placeholder">
                                            <i class="fas fa-box-open" aria-hidden="true"></i>
                                        </span>
                                    <?php endif; ?>

                                    <span class="inventory-product-stock-status <?php echo inventario_h($stockEstado['clase']); ?>">
                                        <i class="fas <?php echo inventario_h($stockEstado['icono']); ?>"></i>
                                        <?php echo inventario_h($stockEstado['texto']); ?>
                                    </span>
                                </div>
                                <div class="inventory-card-body">
                                    <div class="inventory-card-topline">
                                        <span class="inventory-card-category">
                                            <?php echo inventario_h($categoria !== '' ? $categoria : 'Sin categoría'); ?>
                                        </span>
                                        <span class="inventory-card-id">#<?php echo (int) $producto['id']; ?></span>
                                    </div>

                                    <h2><?php echo inventario_h($producto['nombre']); ?></h2>

                                    <div class="inventory-card-prices">
                                        <div>
                                            <span>Compra</span>
                                            <strong>
                                                <?php if ($vistaGlobal): ?>
                                                    <?php echo inventario_h(
                                                        inventario_rango_precio(
                                                            $producto['precio_compra_min'] ?? null,
                                                            $producto['precio_compra_max'] ?? null
                                                        )
                                                    ); ?>
                                                <?php else: ?>
                                                    $<?php echo number_format((float) $producto['precio_compra'], 2); ?>
                                                <?php endif; ?>
                                            </strong>
                                        </div>
                                        <div>
                                            <span>Venta</span>
                                            <strong>
                                                <?php if ($vistaGlobal): ?>
                                                    <?php echo inventario_h(
                                                        inventario_rango_precio(
                                                            $producto['precio_venta_min'] ?? null,
                                                            $producto['precio_venta_max'] ?? null
                                                        )
                                                    ); ?>
                                                <?php else: ?>
                                                    $<?php echo number_format((float) $producto['precio_venta'], 2); ?>
                                                <?php endif; ?>
                                            </strong>
                                        </div>
                                    </div>

                                    <div class="inventory-card-stockline">
                                        <div>
                                            <span>
                                                <?php echo $vistaGlobal ? 'Stock total' : 'Stock'; ?>
                                            </span>
                                            <strong><?php echo number_format((int) $producto['stock']); ?></strong>
                                        </div>
                                        <div>
                                            <span>
                                                <?php echo $vistaGlobal ? 'Sucursales' : 'Mínimo'; ?>
                                            </span>
                                            <strong>
                                                <?php echo $vistaGlobal
                                                    ? number_format((int) ($producto['sucursales_count'] ?? 0))
                                                    : number_format((int) $producto['stock_minimo']);
                                                ?>
                                            </strong>
                                        </div>
                                    </div>

                                    <div class="inventory-card-provider" title="<?php echo inventario_h($proveedor !== '' ? $proveedor : 'Sin proveedor'); ?>">
                                        <i class="fas fa-truck-field"></i>
                                        <span><?php echo inventario_h($proveedor !== '' ? $proveedor : 'Sin proveedor'); ?></span>
                                    </div>

                                    <?php if ($vistaGlobal): ?>
                                        <div class="inventory-card-coverage">
                                            <i class="fas fa-building-circle-check"></i>
                                            <span>
                                                <?php echo number_format((int) ($producto['sucursales_activas'] ?? 0)); ?>
                                                <?php echo (int) ($producto['sucursales_activas'] ?? 0) === 1
                                                    ? 'sede activa'
                                                    : 'sedes activas';
                                                ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <footer class="inventory-catalog-footer">
                        <div class="inventory-catalog-range">
                            <strong>Página <?php echo $pagina; ?> de <?php echo $totalPaginas; ?></strong>
                            <span>
                                Mostrando <?php echo number_format($desde); ?>–<?php echo number_format($hasta); ?>
                                de <?php echo number_format($totalProductos); ?> productos <?php echo $vistaGlobal ? 'consolidados' : ''; ?>
                            </span>
                        </div>

                        <nav class="inventory-catalog-pagination" aria-label="Paginación del inventario">
                            <a
                                href="<?php echo inventario_h(inventario_url($queryBase, ['pagina' => 1])); ?>"
                                class="<?php echo $pagina <= 1 ? 'disabled' : ''; ?>"
                                aria-label="Primera página"
                                title="Primera página"
                            >
                                <i class="fas fa-angles-left"></i>
                            </a>

                            <a
                                href="<?php echo inventario_h(inventario_url($queryBase, ['pagina' => max(1, $pagina - 1)])); ?>"
                                class="<?php echo $pagina <= 1 ? 'disabled' : ''; ?>"
                                aria-label="Página anterior"
                                title="Página anterior"
                            >
                                <i class="fas fa-chevron-left"></i>
                            </a>

                            <?php if ($inicioPaginas > 1): ?>
                                <span class="inventory-pagination-ellipsis" aria-hidden="true">…</span>
                            <?php endif; ?>

                            <?php foreach ($paginasVisibles as $numero): ?>
                                <a
                                    href="<?php echo inventario_h(inventario_url($queryBase, ['pagina' => $numero])); ?>"
                                    class="<?php echo $numero === $pagina ? 'active' : ''; ?>"
                                    <?php echo $numero === $pagina ? 'aria-current="page"' : ''; ?>
                                >
                                    <?php echo $numero; ?>
                                </a>
                            <?php endforeach; ?>

                            <?php if ($finPaginas < $totalPaginas): ?>
                                <span class="inventory-pagination-ellipsis" aria-hidden="true">…</span>
                            <?php endif; ?>

                            <a
                                href="<?php echo inventario_h(inventario_url($queryBase, ['pagina' => min($totalPaginas, $pagina + 1)])); ?>"
                                class="<?php echo $pagina >= $totalPaginas ? 'disabled' : ''; ?>"
                                aria-label="Página siguiente"
                                title="Página siguiente"
                            >
                                <i class="fas fa-chevron-right"></i>
                            </a>

                            <a
                                href="<?php echo inventario_h(inventario_url($queryBase, ['pagina' => $totalPaginas])); ?>"
                                class="<?php echo $pagina >= $totalPaginas ? 'disabled' : ''; ?>"
                                aria-label="Última página"
                                title="Última página"
                            >
                                <i class="fas fa-angles-right"></i>
                            </a>
                        </nav>
                    </footer>
                <?php else: ?>
                    <div class="inventory-catalog-empty">
                        <span><i class="fas fa-box-open"></i></span>
                        <h2>No se encontraron productos</h2>
                        <p>No hay productos que coincidan con los filtros seleccionados.</p>
                        <a href="<?php echo inventario_h(inventario_url(['vista' => $vistaGlobal ? 'global' : 'sucursal'])); ?>">Limpiar filtros</a>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
    <script>
        (() => {
            const form = document.getElementById('inventoryFilterForm');
            if (!form) return;

            const search = form.querySelector('input[name="busqueda"]');
            const selects = form.querySelectorAll('select');
            let timer = null;

            const submitFilters = () => {
                window.clearTimeout(timer);
                form.requestSubmit();
            };

            selects.forEach((select) => {
                select.addEventListener('change', submitFilters);
            });

            if (search) {
                search.addEventListener('input', () => {
                    window.clearTimeout(timer);
                    timer = window.setTimeout(() => form.requestSubmit(), 380);
                });

                search.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        submitFilters();
                    }
                });
            }
        })();
    </script>
</body>
</html>