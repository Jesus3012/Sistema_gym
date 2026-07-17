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
     ORDER BY nombre ASC"
);

if ($resultadoCategorias) {
    while ($fila = $resultadoCategorias->fetch_assoc()) {
        $categorias[] = $fila;
    }
}

$where = [];
$parametros = [];
$tipos = '';

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

if ($estado !== 'todos') {
    $where[] = 'p.estado = ?';
    $parametros[] = $estado;
    $tipos .= 's';
}

if ($existencia === 'disponible') {
    $where[] = "p.estado = 'activo' AND p.stock > p.stock_minimo";
} elseif ($existencia === 'bajo') {
    $where[] = "p.estado = 'activo' AND p.stock > 0 AND p.stock <= p.stock_minimo";
} elseif ($existencia === 'agotado') {
    $where[] = "p.estado = 'activo' AND p.stock <= 0";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sqlConteo = "SELECT COUNT(*) AS total
              FROM productos p
              LEFT JOIN categorias_productos c ON c.id = p.categoria_id
              LEFT JOIN proveedores prov ON prov.id = p.proveedor_id
              $whereSql";

$stmtConteo = $conn->prepare($sqlConteo);

if (!$stmtConteo) {
    die('No fue posible preparar el conteo de productos.');
}

$parametrosConteo = $parametros;
inventario_bind($stmtConteo, $tipos, $parametrosConteo);
$stmtConteo->execute();
$totalProductos = (int) (($stmtConteo->get_result()->fetch_assoc()['total'] ?? 0));
$stmtConteo->close();

$totalPaginas = max(1, (int) ceil($totalProductos / $porPagina));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * $porPagina;

$sqlProductos = "SELECT
                    p.id,
                    p.nombre,
                    p.descripcion,
                    p.precio_compra,
                    p.precio_venta,
                    p.stock,
                    p.stock_minimo,
                    p.foto,
                    p.estado,
                    p.fecha_registro,
                    c.nombre AS categoria_nombre,
                    prov.nombre AS proveedor_nombre
                 FROM productos p
                 LEFT JOIN categorias_productos c ON c.id = p.categoria_id
                 LEFT JOIN proveedores prov ON prov.id = p.proveedor_id
                 $whereSql
                 ORDER BY p.nombre ASC, p.id DESC
                 LIMIT ? OFFSET ?";

$stmtProductos = $conn->prepare($sqlProductos);

if (!$stmtProductos) {
    die('No fue posible consultar los productos: ' . inventario_h($conn->error));
}

$parametrosProductos = $parametros;
$parametrosProductos[] = $porPagina;
$parametrosProductos[] = $offset;
$tiposProductos = $tipos . 'ii';
inventario_bind($stmtProductos, $tiposProductos, $parametrosProductos);
$stmtProductos->execute();
$resultadoProductos = $stmtProductos->get_result();
$productos = [];

while ($fila = $resultadoProductos->fetch_assoc()) {
    $productos[] = $fila;
}

$stmtProductos->close();

$queryBase = [
    'busqueda' => $busqueda,
    'categoria' => $categoriaId,
    'estado' => $estado,
    'existencia' => $existencia,
    'por_pagina' => $porPagina,
];

$desde = $totalProductos > 0 ? $offset + 1 : 0;
$hasta = min($offset + count($productos), $totalProductos);

$paginasVisibles = [];
$inicioPaginas = max(1, $pagina - 2);
$finPaginas = min($totalPaginas, $pagina + 2);

for ($numero = $inicioPaginas; $numero <= $finPaginas; $numero++) {
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
    <link rel="stylesheet" href="css/inventario.css?v=6.1.0">
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content inventory-catalog-main">
        <div class="inventory-catalog-page">
            <header class="inventory-catalog-header">
                <div class="inventory-catalog-title">
                    <span class="inventory-catalog-title-icon" aria-hidden="true">
                        <i class="fas fa-boxes-stacked"></i>
                    </span>
                    <div>
                        <h1>Inventario</h1>
                        <p>Consulta visual de productos con filtros automáticos y paginación.</p>
                    </div>
                </div>

                <span class="inventory-catalog-total">
                    <strong><?php echo number_format($totalProductos); ?></strong>
                    <?php echo $totalProductos === 1 ? 'producto' : 'productos'; ?>
                </span>
            </header>

            <section class="inventory-catalog-panel" aria-label="Productos del inventario">
                <form method="GET" class="inventory-catalog-filters" id="inventoryFilterForm">
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
                    <a href="inventario.php" class="inventory-catalog-clear" aria-label="Borrar filtros" title="Borrar filtros">
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
                            <article class="inventory-card">
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
                                            <strong>$<?php echo number_format((float) $producto['precio_compra'], 2); ?></strong>
                                        </div>
                                        <div>
                                            <span>Venta</span>
                                            <strong>$<?php echo number_format((float) $producto['precio_venta'], 2); ?></strong>
                                        </div>
                                    </div>

                                    <div class="inventory-card-stockline">
                                        <div>
                                            <span>Stock</span>
                                            <strong><?php echo number_format((int) $producto['stock']); ?></strong>
                                        </div>
                                        <div>
                                            <span>Mínimo</span>
                                            <strong><?php echo number_format((int) $producto['stock_minimo']); ?></strong>
                                        </div>
                                    </div>

                                    <div class="inventory-card-provider" title="<?php echo inventario_h($proveedor !== '' ? $proveedor : 'Sin proveedor'); ?>">
                                        <i class="fas fa-truck-field"></i>
                                        <span><?php echo inventario_h($proveedor !== '' ? $proveedor : 'Sin proveedor'); ?></span>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <footer class="inventory-catalog-footer">
                        <div class="inventory-catalog-range">
                            <strong>Página <?php echo $pagina; ?> de <?php echo $totalPaginas; ?></strong>
                            <span>
                                Mostrando <?php echo number_format($desde); ?>–<?php echo number_format($hasta); ?>
                                de <?php echo number_format($totalProductos); ?> productos
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
                        <a href="inventario.php">Limpiar filtros</a>
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
