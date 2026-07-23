<?php
// Archivo: socios.php
// Directorio visual de socios con edición de datos personales.

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';

if (!isset($connPermisos) || !$connPermisos instanceof mysqli) {
    http_response_code(500);
    exit('No fue posible conectar con la base de datos.');
}

$conn = $connPermisos;
$conn->set_charset('utf8mb4');

function socios_h(?string $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}


function socios_strlen(string $valor): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($valor, 'UTF-8')
        : strlen($valor);
}

function socios_json(array $respuesta, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $respuesta,
        JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_APOS
        | JSON_HEX_AMP
        | JSON_HEX_QUOT
    );
    exit;
}

function socios_bind_params(mysqli_stmt $stmt, string $tipos, array &$parametros): void
{
    if ($tipos === '' || $parametros === []) {
        return;
    }

    $argumentos = [$tipos];

    foreach ($parametros as $indice => &$parametro) {
        $argumentos[] = &$parametro;
    }
    unset($parametro);

    call_user_func_array([$stmt, 'bind_param'], $argumentos);
}

function socios_iniciales(string $nombre, string $apellido): string
{
    $iniciales = '';

    foreach ([$nombre, $apellido] as $parte) {
        $parte = trim($parte);

        if ($parte === '') {
            continue;
        }

        $iniciales .= function_exists('mb_substr')
            ? mb_substr($parte, 0, 1, 'UTF-8')
            : substr($parte, 0, 1);
    }

    return function_exists('mb_strtoupper')
        ? mb_strtoupper($iniciales !== '' ? $iniciales : 'S', 'UTF-8')
        : strtoupper($iniciales !== '' ? $iniciales : 'S');
}

function socios_fecha_corta(?string $fecha): string
{
    $fecha = trim((string) $fecha);

    if ($fecha === '') {
        return 'Sin registro';
    }

    $timestamp = strtotime($fecha);

    return $timestamp !== false
        ? date('d/m/Y', $timestamp)
        : 'Sin registro';
}

function socios_fecha_hora(?string $fecha): string
{
    $fecha = trim((string) $fecha);

    if ($fecha === '') {
        return 'Sin asistencias';
    }

    $timestamp = strtotime($fecha);

    return $timestamp !== false
        ? date('d/m/Y · H:i', $timestamp)
        : 'Sin asistencias';
}

if (empty($_SESSION['socios_csrf'])) {
    $_SESSION['socios_csrf'] = bin2hex(random_bytes(32));
}

$csrfSocios = (string) $_SESSION['socios_csrf'];
$rolSocios = strtolower(trim((string) ($_SESSION['user_rol'] ?? '')));
$esAdministradorSocios = in_array(
    $rolSocios,
    ['admin', 'administrador'],
    true
);
$sucursalSociosId = (int) ($sucursal_id ?? ($_SESSION['sucursal_id'] ?? 0));
$sucursalSociosNombre = trim((string) (
    $sucursal_nombre ?? ($_SESSION['sucursal_nombre'] ?? 'Sucursal')
));

$vistaSolicitada = strtolower(trim((string) ($_GET['vista'] ?? 'sucursal')));
$vistaGlobalSocios = $esAdministradorSocios && $vistaSolicitada === 'global';

$alcanceSql = '';
$alcanceTipos = '';
$alcanceParametros = [];

if (!$vistaGlobalSocios) {
    $alcanceSql = "(
        c.sucursal_registro_id = ?
        OR EXISTS (
            SELECT 1
            FROM inscripciones i_scope
            INNER JOIN inscripciones_sucursales iss_scope
                ON iss_scope.inscripcion_id = i_scope.id
            WHERE i_scope.cliente_id = c.id
              AND iss_scope.sucursal_id = ?
        )
    )";
    $alcanceTipos = 'ii';
    $alcanceParametros = [$sucursalSociosId, $sucursalSociosId];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = trim((string) ($_POST['accion'] ?? ''));

    if ($accion !== 'actualizar_socio') {
        socios_json([
            'ok' => false,
            'mensaje' => 'La operación solicitada no es válida.',
        ], 400);
    }

    $csrfRecibido = (string) ($_POST['csrf'] ?? '');

    if ($csrfRecibido === '' || !hash_equals($csrfSocios, $csrfRecibido)) {
        socios_json([
            'ok' => false,
            'mensaje' => 'La sesión del formulario venció. Recarga la página.',
        ], 419);
    }

    $clienteId = (int) ($_POST['cliente_id'] ?? 0);
    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    $apellido = trim((string) ($_POST['apellido'] ?? ''));
    $telefono = trim((string) ($_POST['telefono'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $emergenciaNombre = trim((string) ($_POST['contacto_emergencia_nombre'] ?? ''));
    $emergenciaTelefono = trim((string) ($_POST['contacto_emergencia_telefono'] ?? ''));
    $estadoCliente = strtolower(trim((string) ($_POST['estado'] ?? 'activo')));

    if ($clienteId <= 0) {
        socios_json([
            'ok' => false,
            'mensaje' => 'No fue posible identificar al socio.',
        ], 422);
    }

    if ($nombre === '' || $apellido === '') {
        socios_json([
            'ok' => false,
            'mensaje' => 'El nombre y el apellido son obligatorios.',
        ], 422);
    }

    if (socios_strlen($nombre) > 100 || socios_strlen($apellido) > 100) {
        socios_json([
            'ok' => false,
            'mensaje' => 'El nombre o el apellido exceden la longitud permitida.',
        ], 422);
    }

    if ($telefono !== '' && !preg_match('/^[0-9+()\-\s]{7,20}$/', $telefono)) {
        socios_json([
            'ok' => false,
            'mensaje' => 'El teléfono del socio no tiene un formato válido.',
        ], 422);
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        socios_json([
            'ok' => false,
            'mensaje' => 'El correo electrónico no tiene un formato válido.',
        ], 422);
    }

    if ($emergenciaNombre !== '' && socios_strlen($emergenciaNombre) > 150) {
        socios_json([
            'ok' => false,
            'mensaje' => 'El nombre del contacto de emergencia es demasiado largo.',
        ], 422);
    }

    if (
        $emergenciaTelefono !== ''
        && !preg_match('/^[0-9+()\-\s]{7,25}$/', $emergenciaTelefono)
    ) {
        socios_json([
            'ok' => false,
            'mensaje' => 'El teléfono de emergencia no tiene un formato válido.',
        ], 422);
    }

    if (!in_array($estadoCliente, ['activo', 'inactivo'], true)) {
        socios_json([
            'ok' => false,
            'mensaje' => 'El estado seleccionado no es válido.',
        ], 422);
    }

    $sqlAcceso = 'SELECT c.id FROM clientes c WHERE c.id = ?';
    $tiposAcceso = 'i';
    $parametrosAcceso = [$clienteId];

    if (!$esAdministradorSocios && $alcanceSql !== '') {
        $sqlAcceso .= ' AND ' . $alcanceSql;
        $tiposAcceso .= $alcanceTipos;
        $parametrosAcceso = array_merge(
            $parametrosAcceso,
            $alcanceParametros
        );
    }

    $sqlAcceso .= ' LIMIT 1';
    $stmtAcceso = $conn->prepare($sqlAcceso);

    if (!$stmtAcceso) {
        socios_json([
            'ok' => false,
            'mensaje' => 'No fue posible validar el acceso al socio.',
        ], 500);
    }

    socios_bind_params($stmtAcceso, $tiposAcceso, $parametrosAcceso);
    $stmtAcceso->execute();
    $resultadoAcceso = $stmtAcceso->get_result();
    $tieneAcceso = $resultadoAcceso && $resultadoAcceso->num_rows > 0;
    $stmtAcceso->close();

    if (!$tieneAcceso) {
        socios_json([
            'ok' => false,
            'mensaje' => 'No tienes acceso para modificar a este socio.',
        ], 403);
    }

    $stmtActualizar = $conn->prepare(
        "UPDATE clientes
         SET nombre = ?,
             apellido = ?,
             telefono = NULLIF(?, ''),
             email = NULLIF(?, ''),
             contacto_emergencia_nombre = ?,
             contacto_emergencia_telefono = ?,
             estado = ?
         WHERE id = ?
         LIMIT 1"
    );

    if (!$stmtActualizar) {
        error_log('[Socios actualizar] ' . $conn->error);

        socios_json([
            'ok' => false,
            'mensaje' => 'No fue posible preparar la actualización.',
        ], 500);
    }

    $stmtActualizar->bind_param(
        'sssssssi',
        $nombre,
        $apellido,
        $telefono,
        $email,
        $emergenciaNombre,
        $emergenciaTelefono,
        $estadoCliente,
        $clienteId
    );

    if (!$stmtActualizar->execute()) {
        error_log('[Socios actualizar] ' . $stmtActualizar->error);
        $stmtActualizar->close();

        socios_json([
            'ok' => false,
            'mensaje' => 'No fue posible guardar los cambios del socio.',
        ], 500);
    }

    $stmtActualizar->close();

    socios_json([
        'ok' => true,
        'mensaje' => 'La información del socio se actualizó correctamente.',
        'socio' => [
            'id' => $clienteId,
            'nombre' => $nombre,
            'apellido' => $apellido,
            'telefono' => $telefono,
            'email' => $email,
            'contacto_emergencia_nombre' => $emergenciaNombre,
            'contacto_emergencia_telefono' => $emergenciaTelefono,
            'estado' => $estadoCliente,
        ],
    ]);
}

$busqueda = trim((string) ($_GET['q'] ?? ''));
$filtroEstado = strtolower(trim((string) ($_GET['estado'] ?? 'todos')));
$filtroMembresia = strtolower(trim((string) ($_GET['membresia'] ?? 'todas')));
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$porPagina = (int) ($_GET['por_pagina'] ?? 12);

if (!in_array($porPagina, [6, 9, 12, 18, 24], true)) {
    $porPagina = 12;
}

if (!in_array($filtroEstado, ['todos', 'activo', 'inactivo'], true)) {
    $filtroEstado = 'todos';
}

if (!in_array(
    $filtroMembresia,
    ['todas', 'vigente', 'por_vencer', 'vencida', 'sin_membresia', 'cancelada'],
    true
)) {
    $filtroMembresia = 'todas';
}

$estadoMembresiaSql = "CASE
    WHEN i.id IS NULL THEN 'sin_membresia'
    WHEN i.estado = 'cancelada' THEN 'cancelada'
    WHEN i.estado = 'vencida' OR i.fecha_fin < CURDATE() THEN 'vencida'
    WHEN i.estado = 'activa'
         AND i.fecha_inicio <= CURDATE()
         AND i.fecha_fin >= CURDATE() THEN 'vigente'
    WHEN i.estado = 'activa' AND i.fecha_inicio > CURDATE() THEN 'proxima'
    ELSE 'sin_membresia'
END";

$fromSociosSql = "
    FROM clientes c
    LEFT JOIN sucursales sr
        ON sr.id = c.sucursal_registro_id
    LEFT JOIN inscripciones i
        ON i.id = (
            SELECT i2.id
            FROM inscripciones i2
            WHERE i2.cliente_id = c.id
            ORDER BY i2.fecha_fin DESC, i2.id DESC
            LIMIT 1
        )
    LEFT JOIN planes p
        ON p.id = i.plan_id
";

$condiciones = [];
$tipos = '';
$parametros = [];

if ($alcanceSql !== '') {
    $condiciones[] = $alcanceSql;
    $tipos .= $alcanceTipos;
    $parametros = array_merge($parametros, $alcanceParametros);
}

if ($busqueda !== '') {
    $condiciones[] = "(
        CONCAT_WS(' ', c.nombre, c.apellido) LIKE ?
        OR c.telefono LIKE ?
        OR c.email LIKE ?
        OR c.codigo_qr LIKE ?
        OR c.contacto_emergencia_nombre LIKE ?
        OR c.contacto_emergencia_telefono LIKE ?
    )";

    $termino = '%' . $busqueda . '%';
    $tipos .= 'ssssss';

    for ($indice = 0; $indice < 6; $indice++) {
        $parametros[] = $termino;
    }
}

if ($filtroEstado !== 'todos') {
    $condiciones[] = 'c.estado = ?';
    $tipos .= 's';
    $parametros[] = $filtroEstado;
}

if ($filtroMembresia === 'por_vencer') {
    $condiciones[] = "(
        {$estadoMembresiaSql} = 'vigente'
        AND DATEDIFF(i.fecha_fin, CURDATE()) BETWEEN 0 AND 7
    )";
} elseif ($filtroMembresia !== 'todas') {
    $condiciones[] = "{$estadoMembresiaSql} = ?";
    $tipos .= 's';
    $parametros[] = $filtroMembresia;
}

$whereSql = $condiciones !== []
    ? ' WHERE ' . implode(' AND ', $condiciones)
    : '';

$sqlConteo = 'SELECT COUNT(*) AS total ' . $fromSociosSql . $whereSql;
$stmtConteo = $conn->prepare($sqlConteo);

if (!$stmtConteo) {
    throw new RuntimeException('No fue posible preparar el conteo de socios: ' . $conn->error);
}

$parametrosConteo = $parametros;
socios_bind_params($stmtConteo, $tipos, $parametrosConteo);
$stmtConteo->execute();
$resultadoConteo = $stmtConteo->get_result();
$totalSocios = (int) (($resultadoConteo->fetch_assoc()['total'] ?? 0));
$stmtConteo->close();

$totalPaginas = max(1, (int) ceil($totalSocios / $porPagina));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * $porPagina;

$sqlSocios = "
    SELECT
        c.id,
        c.sucursal_registro_id,
        c.nombre,
        c.apellido,
        c.telefono,
        c.email,
        c.codigo_qr,
        c.fecha_registro,
        c.estado,
        c.contacto_emergencia_nombre,
        c.contacto_emergencia_telefono,
        COALESCE(sr.nombre, 'Sucursal sin identificar') AS sucursal_registro_nombre,
        i.id AS inscripcion_id,
        i.fecha_inicio,
        i.fecha_fin,
        i.estado AS inscripcion_estado,
        COALESCE(p.nombre, 'Sin membresía') AS plan_nombre,
        {$estadoMembresiaSql} AS estado_membresia,
        CASE
            WHEN {$estadoMembresiaSql} = 'vigente'
                THEN GREATEST(DATEDIFF(i.fecha_fin, CURDATE()), 0)
            ELSE NULL
        END AS dias_restantes,
        (
            SELECT MAX(TIMESTAMP(a.fecha, a.hora_entrada))
            FROM asistencias a
            WHERE a.cliente_id = c.id
        ) AS ultima_asistencia,
        (
            SELECT COUNT(*)
            FROM asistencias a_total
            WHERE a_total.cliente_id = c.id
        ) AS total_asistencias,
        (
            SELECT COUNT(*)
            FROM asistencias a_30
            WHERE a_30.cliente_id = c.id
              AND a_30.fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ) AS asistencias_30_dias
    {$fromSociosSql}
    {$whereSql}
    ORDER BY
        CASE {$estadoMembresiaSql}
            WHEN 'vigente' THEN 1
            WHEN 'proxima' THEN 2
            WHEN 'vencida' THEN 3
            WHEN 'sin_membresia' THEN 4
            ELSE 5
        END,
        c.estado DESC,
        c.nombre ASC,
        c.apellido ASC
    LIMIT {$porPagina} OFFSET {$offset}
";

$stmtSocios = $conn->prepare($sqlSocios);

if (!$stmtSocios) {
    throw new RuntimeException('No fue posible preparar el listado de socios: ' . $conn->error);
}

$parametrosListado = $parametros;
socios_bind_params($stmtSocios, $tipos, $parametrosListado);
$stmtSocios->execute();
$resultadoSocios = $stmtSocios->get_result();
$socios = $resultadoSocios ? $resultadoSocios->fetch_all(MYSQLI_ASSOC) : [];
$stmtSocios->close();

$condicionesResumen = [];
$tiposResumen = '';
$parametrosResumen = [];

if ($alcanceSql !== '') {
    $condicionesResumen[] = $alcanceSql;
    $tiposResumen = $alcanceTipos;
    $parametrosResumen = $alcanceParametros;
}

$whereResumen = $condicionesResumen !== []
    ? ' WHERE ' . implode(' AND ', $condicionesResumen)
    : '';

$sqlResumen = "
    SELECT
        COUNT(*) AS total,
        SUM(c.estado = 'activo') AS activos,
        SUM({$estadoMembresiaSql} = 'vigente') AS membresias_vigentes,
        SUM(
            {$estadoMembresiaSql} = 'vigente'
            AND DATEDIFF(i.fecha_fin, CURDATE()) BETWEEN 0 AND 7
        ) AS por_vencer
    {$fromSociosSql}
    {$whereResumen}
";

$stmtResumen = $conn->prepare($sqlResumen);

if (!$stmtResumen) {
    throw new RuntimeException('No fue posible preparar el resumen de socios: ' . $conn->error);
}

socios_bind_params($stmtResumen, $tiposResumen, $parametrosResumen);
$stmtResumen->execute();
$resultadoResumen = $stmtResumen->get_result();
$resumen = $resultadoResumen
    ? ($resultadoResumen->fetch_assoc() ?: [])
    : [];
$stmtResumen->close();

$resumenTotal = (int) ($resumen['total'] ?? 0);
$resumenActivos = (int) ($resumen['activos'] ?? 0);
$resumenVigentes = (int) ($resumen['membresias_vigentes'] ?? 0);
$resumenPorVencer = (int) ($resumen['por_vencer'] ?? 0);

function socios_url_pagina(int $numero, array $base): string
{
    $base['pagina'] = $numero;
    return 'socios.php?' . http_build_query($base);
}

$parametrosUrl = [
    'vista' => $vistaGlobalSocios ? 'global' : 'sucursal',
    'q' => $busqueda,
    'estado' => $filtroEstado,
    'membresia' => $filtroMembresia,
    'por_pagina' => $porPagina,
];

$desde = $totalSocios > 0 ? $offset + 1 : 0;
$hasta = min($offset + $porPagina, $totalSocios);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Socios</title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >
    <link rel="stylesheet" href="css/socios.css?v=<?php echo (int) @filemtime(__DIR__ . '/css/socios.css'); ?>">
</head>
<body>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<main class="main-content socios-main">
    <div class="socios-shell">
        <header class="socios-header">
            <div class="socios-heading">
                <h1>Socios</h1>
                <p>
                    Consulta la membresía, actividad reciente y datos de contacto de cada persona registrada.
                </p>
            </div>

            <div class="socios-context-badge">
                <span class="socios-context-icon">
                    <i class="fas <?php echo $vistaGlobalSocios ? 'fa-earth-americas' : 'fa-building'; ?>"></i>
                </span>
                <span>
                    <small><?php echo $vistaGlobalSocios ? 'Vista general' : 'Sucursal activa'; ?></small>
                    <strong><?php echo socios_h($vistaGlobalSocios ? 'Todas las sucursales' : $sucursalSociosNombre); ?></strong>
                </span>
            </div>
        </header>

        <section class="socios-stats" aria-label="Resumen de socios">
            <article class="socios-stat-card">
                <span class="socios-stat-icon"><i class="fas fa-users"></i></span>
                <span>
                    <small>Registrados</small>
                    <strong><?php echo number_format($resumenTotal); ?></strong>
                </span>
            </article>

            <article class="socios-stat-card">
                <span class="socios-stat-icon positive"><i class="fas fa-user-check"></i></span>
                <span>
                    <small>Socios activos</small>
                    <strong><?php echo number_format($resumenActivos); ?></strong>
                </span>
            </article>

            <article class="socios-stat-card">
                <span class="socios-stat-icon membership"><i class="fas fa-id-card"></i></span>
                <span>
                    <small>Membresía vigente</small>
                    <strong><?php echo number_format($resumenVigentes); ?></strong>
                </span>
            </article>

            <article class="socios-stat-card">
                <span class="socios-stat-icon warning"><i class="fas fa-clock"></i></span>
                <span>
                    <small>Vencen en 7 días</small>
                    <strong><?php echo number_format($resumenPorVencer); ?></strong>
                </span>
            </article>
        </section>

        <section class="socios-toolbar">
            <form method="GET" action="socios.php" class="socios-filters" id="sociosFilters">
                <input type="hidden" name="vista" value="<?php echo $vistaGlobalSocios ? 'global' : 'sucursal'; ?>">

                <label class="socios-search">
                    <i class="fas fa-magnifying-glass"></i>
                    <input
                        type="search"
                        name="q"
                        value="<?php echo socios_h($busqueda); ?>"
                        placeholder="Buscar por nombre, teléfono, correo o QR"
                        autocomplete="off"
                        aria-label="Buscar socios"
                    >
                    <span class="socios-search-state" id="sociosSearchState" aria-hidden="true">
                        <i class="fas fa-spinner fa-spin"></i>
                    </span>
                </label>

                <label class="socios-select-wrap">
                    <i class="fas fa-user-shield"></i>
                    <select name="estado" aria-label="Filtrar por estado del socio">
                        <option value="todos" <?php echo $filtroEstado === 'todos' ? 'selected' : ''; ?>>Todos los estados</option>
                        <option value="activo" <?php echo $filtroEstado === 'activo' ? 'selected' : ''; ?>>Socios activos</option>
                        <option value="inactivo" <?php echo $filtroEstado === 'inactivo' ? 'selected' : ''; ?>>Socios inactivos</option>
                    </select>
                </label>

                <label class="socios-select-wrap">
                    <i class="fas fa-id-card-clip"></i>
                    <select name="membresia" aria-label="Filtrar por membresía">
                        <option value="todas" <?php echo $filtroMembresia === 'todas' ? 'selected' : ''; ?>>Todas las membresías</option>
                        <option value="vigente" <?php echo $filtroMembresia === 'vigente' ? 'selected' : ''; ?>>Vigentes</option>
                        <option value="por_vencer" <?php echo $filtroMembresia === 'por_vencer' ? 'selected' : ''; ?>>Por vencer</option>
                        <option value="vencida" <?php echo $filtroMembresia === 'vencida' ? 'selected' : ''; ?>>Vencidas</option>
                        <option value="sin_membresia" <?php echo $filtroMembresia === 'sin_membresia' ? 'selected' : ''; ?>>Sin membresía</option>
                        <option value="cancelada" <?php echo $filtroMembresia === 'cancelada' ? 'selected' : ''; ?>>Canceladas</option>
                    </select>
                </label>

                <div class="socios-filter-feedback" id="sociosFilterFeedback" aria-live="polite">
                    <i class="fas fa-wand-magic-sparkles"></i>
                    <span>Se actualiza cuando terminas de escribir</span>
                </div>

                <?php if ($busqueda !== '' || $filtroEstado !== 'todos' || $filtroMembresia !== 'todas'): ?>
                    <a
                        href="socios.php?vista=<?php echo $vistaGlobalSocios ? 'global' : 'sucursal'; ?>"
                        class="socios-clear-button"
                        id="sociosClearFilters"
                    >
                        <i class="fas fa-rotate-left"></i>
                        Restablecer
                    </a>
                <?php endif; ?>
            </form>
        </section>

        <div class="socios-result-meta">
            <span>
                <strong><?php echo number_format($totalSocios); ?></strong>
                <?php echo $totalSocios === 1 ? 'socio encontrado' : 'socios encontrados'; ?>
            </span>

            <form method="GET" action="socios.php" class="socios-page-size">
                <input type="hidden" name="vista" value="<?php echo $vistaGlobalSocios ? 'global' : 'sucursal'; ?>">
                <input type="hidden" name="q" value="<?php echo socios_h($busqueda); ?>">
                <input type="hidden" name="estado" value="<?php echo socios_h($filtroEstado); ?>">
                <input type="hidden" name="membresia" value="<?php echo socios_h($filtroMembresia); ?>">
                <span>Mostrar</span>
                <select name="por_pagina" onchange="this.form.submit()" aria-label="Cantidad de socios por página">
                    <?php foreach ([6, 9, 12, 18, 24] as $opcion): ?>
                        <option value="<?php echo $opcion; ?>" <?php echo $porPagina === $opcion ? 'selected' : ''; ?>>
                            <?php echo $opcion; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if ($socios === []): ?>
            <section class="socios-empty">
                <span><i class="fas fa-user-group"></i></span>
                <h2>No se encontraron socios</h2>
                <p>Prueba con otro nombre o cambia los filtros del directorio.</p>
            </section>
        <?php else: ?>
            <section class="socios-grid" aria-label="Tarjetas de socios">
                <?php foreach ($socios as $socio): ?>
                    <?php
                    $estadoMembresia = (string) ($socio['estado_membresia'] ?? 'sin_membresia');
                    $estadoMembresiaTexto = [
                        'vigente' => 'Vigente',
                        'proxima' => 'Próxima',
                        'vencida' => 'Vencida',
                        'cancelada' => 'Cancelada',
                        'sin_membresia' => 'Sin membresía',
                    ][$estadoMembresia] ?? 'Sin membresía';

                    $datosEdicion = [
                        'id' => (int) $socio['id'],
                        'nombre' => (string) $socio['nombre'],
                        'apellido' => (string) $socio['apellido'],
                        'telefono' => (string) ($socio['telefono'] ?? ''),
                        'email' => (string) ($socio['email'] ?? ''),
                        'contacto_emergencia_nombre' => (string) ($socio['contacto_emergencia_nombre'] ?? ''),
                        'contacto_emergencia_telefono' => (string) ($socio['contacto_emergencia_telefono'] ?? ''),
                        'estado' => (string) $socio['estado'],
                    ];

                    $codigoQr = trim((string) ($socio['codigo_qr'] ?? ''));
                    $diasRestantes = $socio['dias_restantes'] !== null
                        ? (int) $socio['dias_restantes']
                        : null;

                    $porcentajeMembresia = 0;
                    $fechaInicioTimestamp = !empty($socio['fecha_inicio'])
                        ? strtotime((string) $socio['fecha_inicio'])
                        : false;
                    $fechaFinTimestamp = !empty($socio['fecha_fin'])
                        ? strtotime((string) $socio['fecha_fin'])
                        : false;
                    $hoyTimestamp = strtotime(date('Y-m-d'));

                    if (
                        $fechaInicioTimestamp !== false
                        && $fechaFinTimestamp !== false
                        && $fechaFinTimestamp > $fechaInicioTimestamp
                    ) {
                        $duracionSegundos = $fechaFinTimestamp - $fechaInicioTimestamp;
                        $avanceSegundos = $hoyTimestamp - $fechaInicioTimestamp;
                        $porcentajeMembresia = (int) round(
                            max(0, min(1, $avanceSegundos / $duracionSegundos)) * 100
                        );
                    }

                    if (in_array($estadoMembresia, ['vencida', 'cancelada'], true)) {
                        $porcentajeMembresia = 100;
                    } elseif ($estadoMembresia === 'proxima') {
                        $porcentajeMembresia = 0;
                    }

                    $estadoSocioTexto = $socio['estado'] === 'activo'
                        ? 'Activo'
                        : 'Inactivo';
                    ?>
                    <article class="socio-card socio-card-<?php echo socios_h($estadoMembresia); ?> <?php echo $socio['estado'] === 'inactivo' ? 'is-inactive' : ''; ?>">
                        <header class="socio-card-header">
                            <div class="socio-identity">
                                <span class="socio-avatar" aria-hidden="true">
                                    <?php echo socios_h(socios_iniciales((string) $socio['nombre'], (string) $socio['apellido'])); ?>
                                </span>

                                <div class="socio-name-wrap">
                                    <h2 title="<?php echo socios_h(trim($socio['nombre'] . ' ' . $socio['apellido'])); ?>">
                                        <?php echo socios_h(trim($socio['nombre'] . ' ' . $socio['apellido'])); ?>
                                    </h2>

                                    <div class="socio-meta-line">
                                        <span>
                                            <i class="fas fa-hashtag"></i>
                                            <?php echo str_pad((string) $socio['id'], 5, '0', STR_PAD_LEFT); ?>
                                        </span>
                                        <span title="<?php echo socios_h((string) $socio['sucursal_registro_nombre']); ?>">
                                            <i class="fas fa-building"></i>
                                            <?php echo socios_h((string) $socio['sucursal_registro_nombre']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="socio-card-statuses">
                                <span class="socio-account-badge <?php echo socios_h((string) $socio['estado']); ?>">
                                    <i class="fas <?php echo $socio['estado'] === 'activo' ? 'fa-circle-check' : 'fa-circle-xmark'; ?>"></i>
                                    <?php echo socios_h($estadoSocioTexto); ?>
                                </span>
                                <span class="membership-badge membership-<?php echo socios_h($estadoMembresia); ?>">
                                    <?php echo socios_h($estadoMembresiaTexto); ?>
                                </span>
                            </div>
                        </header>

                        <section class="socio-membership-panel">
                            <div class="socio-membership-summary">
                                <span class="socio-membership-icon">
                                    <i class="fas fa-id-card"></i>
                                </span>

                                <div>
                                    <small>Membresía actual</small>
                                    <strong><?php echo socios_h((string) $socio['plan_nombre']); ?></strong>
                                    <?php if (!empty($socio['fecha_inicio']) && !empty($socio['fecha_fin'])): ?>
                                        <span>
                                            <?php echo socios_h(socios_fecha_corta((string) $socio['fecha_inicio'])); ?>
                                            –
                                            <?php echo socios_h(socios_fecha_corta((string) $socio['fecha_fin'])); ?>
                                        </span>
                                    <?php else: ?>
                                        <span>Sin periodo registrado</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="socio-membership-time">
                                <?php if ($estadoMembresia === 'vigente' && $diasRestantes !== null): ?>
                                    <strong><?php echo $diasRestantes; ?></strong>
                                    <small><?php echo $diasRestantes === 1 ? 'día restante' : 'días restantes'; ?></small>
                                <?php elseif (!empty($socio['fecha_fin'])): ?>
                                    <strong><?php echo socios_h(socios_fecha_corta((string) $socio['fecha_fin'])); ?></strong>
                                    <small>fecha de término</small>
                                <?php else: ?>
                                    <strong>—</strong>
                                    <small>sin periodo</small>
                                <?php endif; ?>
                            </div>

                            <?php if ($estadoMembresia !== 'sin_membresia'): ?>
                                <div
                                    class="socio-membership-progress"
                                    style="--membership-progress: <?php echo $porcentajeMembresia; ?>%;"
                                    aria-label="Avance del periodo <?php echo $porcentajeMembresia; ?> por ciento"
                                >
                                    <span></span>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="socio-details-grid" aria-label="Información del socio">
                            <div class="socio-detail-item">
                                <span class="socio-detail-icon"><i class="fas fa-phone"></i></span>
                                <div>
                                    <small>Teléfono</small>
                                    <strong><?php echo socios_h(trim((string) ($socio['telefono'] ?? '')) !== '' ? (string) $socio['telefono'] : 'No registrado'); ?></strong>
                                </div>
                            </div>

                            <div class="socio-detail-item">
                                <span class="socio-detail-icon"><i class="fas fa-envelope"></i></span>
                                <div>
                                    <small>Correo</small>
                                    <strong title="<?php echo socios_h((string) ($socio['email'] ?? '')); ?>">
                                        <?php echo socios_h(trim((string) ($socio['email'] ?? '')) !== '' ? (string) $socio['email'] : 'No registrado'); ?>
                                    </strong>
                                </div>
                            </div>

                            <div class="socio-detail-item">
                                <span class="socio-detail-icon"><i class="fas fa-door-open"></i></span>
                                <div>
                                    <small>Último acceso</small>
                                    <strong><?php echo socios_h(socios_fecha_hora((string) ($socio['ultima_asistencia'] ?? ''))); ?></strong>
                                </div>
                            </div>

                            <div class="socio-detail-item">
                                <span class="socio-detail-icon"><i class="fas fa-chart-line"></i></span>
                                <div>
                                    <small>Actividad · 30 días</small>
                                    <strong><?php echo (int) ($socio['asistencias_30_dias'] ?? 0); ?> asistencias</strong>
                                </div>
                            </div>
                        </section>

                        <?php if (
                            trim((string) ($socio['contacto_emergencia_nombre'] ?? '')) !== ''
                            || trim((string) ($socio['contacto_emergencia_telefono'] ?? '')) !== ''
                        ): ?>
                            <div class="socio-emergency-note">
                                <span class="socio-emergency-icon"><i class="fas fa-kit-medical"></i></span>
                                <span>
                                    <small>Contacto de emergencia</small>
                                    <strong>
                                        <?php echo socios_h(trim((string) ($socio['contacto_emergencia_nombre'] ?? '')) !== '' ? (string) $socio['contacto_emergencia_nombre'] : 'Sin nombre'); ?>
                                        <?php if (trim((string) ($socio['contacto_emergencia_telefono'] ?? '')) !== ''): ?>
                                            · <?php echo socios_h((string) $socio['contacto_emergencia_telefono']); ?>
                                        <?php endif; ?>
                                    </strong>
                                </span>
                            </div>
                        <?php endif; ?>

                        <footer class="socio-card-footer">
                            <div class="socio-card-secondary-actions">
                                <button
                                    type="button"
                                    class="socio-qr-button js-open-qr"
                                    data-qr="<?php echo socios_h($codigoQr); ?>"
                                    data-name="<?php echo socios_h(trim($socio['nombre'] . ' ' . $socio['apellido'])); ?>"
                                    <?php echo $codigoQr === '' ? 'disabled' : ''; ?>
                                    title="Ver código QR"
                                >
                                    <i class="fas fa-qrcode"></i>
                                    <span>QR</span>
                                </button>

                                <span class="socio-total-attendance" title="Asistencias históricas">
                                    <i class="fas fa-person-walking"></i>
                                    <span>
                                        <strong><?php echo (int) ($socio['total_asistencias'] ?? 0); ?></strong>
                                        <small>históricas</small>
                                    </span>
                                </span>
                            </div>

                            <button
                                type="button"
                                class="socio-edit-button js-edit-socio"
                                data-socio="<?php echo socios_h(json_encode($datosEdicion, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)); ?>"
                            >
                                <i class="fas fa-pen"></i>
                                Editar
                            </button>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <?php if ($totalSocios > 0): ?>
            <footer class="socios-pagination-wrap">
                <p>Mostrando <?php echo $desde; ?>–<?php echo $hasta; ?> de <?php echo number_format($totalSocios); ?></p>

                <?php if ($totalPaginas > 1): ?>
                    <nav class="socios-pagination" aria-label="Paginación de socios">
                        <a
                            href="<?php echo socios_h(socios_url_pagina(max(1, $pagina - 1), $parametrosUrl)); ?>"
                            class="<?php echo $pagina <= 1 ? 'disabled' : ''; ?>"
                            aria-label="Página anterior"
                        >
                            <i class="fas fa-chevron-left"></i>
                        </a>

                        <?php
                        $inicioPagina = max(1, $pagina - 2);
                        $finPagina = min($totalPaginas, $pagina + 2);
                        ?>

                        <?php for ($numeroPagina = $inicioPagina; $numeroPagina <= $finPagina; $numeroPagina++): ?>
                            <a
                                href="<?php echo socios_h(socios_url_pagina($numeroPagina, $parametrosUrl)); ?>"
                                class="<?php echo $numeroPagina === $pagina ? 'active' : ''; ?>"
                                <?php echo $numeroPagina === $pagina ? 'aria-current="page"' : ''; ?>
                            >
                                <?php echo $numeroPagina; ?>
                            </a>
                        <?php endfor; ?>

                        <a
                            href="<?php echo socios_h(socios_url_pagina(min($totalPaginas, $pagina + 1), $parametrosUrl)); ?>"
                            class="<?php echo $pagina >= $totalPaginas ? 'disabled' : ''; ?>"
                            aria-label="Página siguiente"
                        >
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </nav>
                <?php endif; ?>
            </footer>
        <?php endif; ?>
    </div>
</main>

<div class="socios-modal" id="editSocioModal" aria-hidden="true">
    <div class="socios-modal-backdrop" data-close-modal="editSocioModal"></div>

    <section class="socios-modal-card" role="dialog" aria-modal="true" aria-labelledby="editSocioTitle">
        <header class="socios-modal-header">
            <div>
                <span class="socios-modal-kicker">Expediente del socio</span>
                <h2 id="editSocioTitle">Editar información</h2>
                <p>Actualiza los datos personales sin modificar su historial ni su código QR.</p>
            </div>

            <button type="button" class="socios-modal-close" data-close-modal="editSocioModal" aria-label="Cerrar">
                <i class="fas fa-xmark"></i>
            </button>
        </header>

        <form id="editSocioForm" class="socios-edit-form">
            <input type="hidden" name="accion" value="actualizar_socio">
            <input type="hidden" name="csrf" value="<?php echo socios_h($csrfSocios); ?>">
            <input type="hidden" name="cliente_id" id="editClienteId">

            <div class="socios-form-section-title">
                <span><i class="fas fa-user"></i></span>
                <div>
                    <strong>Datos personales</strong>
                    <small>Nombre y medios de contacto del socio.</small>
                </div>
            </div>

            <div class="socios-form-grid">
                <label class="socios-field">
                    <span>Nombre <b>*</b></span>
                    <input type="text" name="nombre" id="editNombre" maxlength="100" required>
                </label>

                <label class="socios-field">
                    <span>Apellido <b>*</b></span>
                    <input type="text" name="apellido" id="editApellido" maxlength="100" required>
                </label>

                <label class="socios-field">
                    <span>Teléfono</span>
                    <input type="tel" name="telefono" id="editTelefono" maxlength="20" inputmode="tel">
                </label>

                <label class="socios-field">
                    <span>Correo electrónico</span>
                    <input type="email" name="email" id="editEmail" maxlength="100">
                </label>
            </div>

            <div class="socios-form-section-title emergency">
                <span><i class="fas fa-kit-medical"></i></span>
                <div>
                    <strong>Contacto de emergencia</strong>
                    <small>Estos dos campos son opcionales.</small>
                </div>
            </div>

            <div class="socios-form-grid">
                <label class="socios-field">
                    <span>Nombre del contacto</span>
                    <input type="text" name="contacto_emergencia_nombre" id="editEmergenciaNombre" maxlength="150">
                </label>

                <label class="socios-field">
                    <span>Teléfono de emergencia</span>
                    <input type="tel" name="contacto_emergencia_telefono" id="editEmergenciaTelefono" maxlength="25" inputmode="tel">
                </label>

                <label class="socios-field socios-field-full">
                    <span>Estado del socio</span>
                    <select name="estado" id="editEstado">
                        <option value="activo">Activo</option>
                        <option value="inactivo">Inactivo</option>
                    </select>
                    <small class="socios-field-help">
                        Un socio inactivo no podrá renovar ni registrar acceso mientras conserve ese estado.
                    </small>
                </label>
            </div>

            <footer class="socios-modal-footer">
                <button type="button" class="socios-cancel-button" data-close-modal="editSocioModal">
                    Cancelar
                </button>
                <button type="submit" class="socios-save-button" id="saveSocioButton">
                    <i class="fas fa-floppy-disk"></i>
                    Guardar cambios
                </button>
            </footer>
        </form>
    </section>
</div>

<div class="socios-modal socios-qr-modal" id="qrSocioModal" aria-hidden="true">
    <div class="socios-modal-backdrop" data-close-modal="qrSocioModal"></div>

    <section class="socios-modal-card qr-card" role="dialog" aria-modal="true" aria-labelledby="qrSocioTitle">
        <header class="socios-modal-header">
            <div>
                <span class="socios-modal-kicker">Acceso del socio</span>
                <h2 id="qrSocioTitle">Código QR</h2>
                <p id="qrSocioName">Socio</p>
            </div>

            <button type="button" class="socios-modal-close" data-close-modal="qrSocioModal" aria-label="Cerrar">
                <i class="fas fa-xmark"></i>
            </button>
        </header>

        <div class="socios-qr-content">
            <div class="socios-qr-box" id="sociosQrBox"></div>
            <code id="sociosQrCode"></code>
            <button type="button" class="socios-copy-button" id="copySocioQr">
                <i class="fas fa-copy"></i>
                Copiar código
            </button>
        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
(function () {
    const editModal = document.getElementById('editSocioModal');
    const qrModal = document.getElementById('qrSocioModal');
    const editForm = document.getElementById('editSocioForm');
    const saveButton = document.getElementById('saveSocioButton');
    const qrBox = document.getElementById('sociosQrBox');
    const qrCodeText = document.getElementById('sociosQrCode');
    const qrName = document.getElementById('qrSocioName');
    const copyQrButton = document.getElementById('copySocioQr');
    const filtersForm = document.getElementById('sociosFilters');
    const searchInput = filtersForm
        ? filtersForm.querySelector('input[name="q"]')
        : null;
    const filterSelects = filtersForm
        ? Array.from(filtersForm.querySelectorAll('select'))
        : [];
    const filterFeedback = document.getElementById('sociosFilterFeedback');
    const searchState = document.getElementById('sociosSearchState');
    const clearFilters = document.getElementById('sociosClearFilters');

    let currentQr = '';
    let filterTimer = null;
    let filtersSubmitting = false;
    const initialFilterSignature = filtersForm
        ? new URLSearchParams(new FormData(filtersForm)).toString()
        : '';

    function saveDirectoryPosition(focusField) {
        sessionStorage.setItem('sociosScrollY', String(window.scrollY));

        if (focusField) {
            sessionStorage.setItem('sociosFocusField', focusField);
        } else {
            sessionStorage.removeItem('sociosFocusField');
        }
    }

    function setFilteringState(active) {
        if (filterFeedback) {
            filterFeedback.classList.toggle('is-working', active);

            const text = filterFeedback.querySelector('span');
            if (text) {
                text.textContent = active
                    ? 'Actualizando resultados…'
                    : 'Se actualiza cuando terminas de escribir';
            }
        }

        if (searchState) {
            searchState.classList.toggle('is-visible', active);
        }
    }

    function submitAutomaticFilters(focusField) {
        if (!filtersForm || filtersSubmitting) {
            return;
        }

        const signature = new URLSearchParams(
            new FormData(filtersForm)
        ).toString();

        if (signature === initialFilterSignature) {
            setFilteringState(false);
            return;
        }

        filtersSubmitting = true;
        setFilteringState(true);
        saveDirectoryPosition(focusField);
        filtersForm.requestSubmit();
    }

    function scheduleAutomaticFilters(delay, focusField) {
        window.clearTimeout(filterTimer);
        setFilteringState(true);

        filterTimer = window.setTimeout(function () {
            submitAutomaticFilters(focusField);
        }, delay);
    }

    if (filtersForm) {
        filtersForm.addEventListener('submit', function () {
            if (!filtersSubmitting) {
                saveDirectoryPosition(
                    document.activeElement === searchInput ? 'q' : ''
                );
            }
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            scheduleAutomaticFilters(750, 'q');
        });

        searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                window.clearTimeout(filterTimer);
                filtersSubmitting = true;
                setFilteringState(true);
                saveDirectoryPosition('q');
            }
        });
    }

    filterSelects.forEach(function (select) {
        select.addEventListener('change', function () {
            scheduleAutomaticFilters(180, '');
        });
    });

    if (clearFilters) {
        clearFilters.addEventListener('click', function () {
            saveDirectoryPosition('q');
            setFilteringState(true);
        });
    }

    function openModal(modal) {
        if (!modal) return;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('socios-modal-open');
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');

        if (!document.querySelector('.socios-modal.is-open')) {
            document.body.classList.remove('socios-modal-open');
        }
    }

    document.querySelectorAll('[data-close-modal]').forEach(function (button) {
        button.addEventListener('click', function () {
            closeModal(document.getElementById(button.dataset.closeModal));
        });
    });

    document.querySelectorAll('.js-edit-socio').forEach(function (button) {
        button.addEventListener('click', function () {
            let socio;

            try {
                socio = JSON.parse(button.dataset.socio || '{}');
            } catch (error) {
                return;
            }

            document.getElementById('editClienteId').value = socio.id || '';
            document.getElementById('editNombre').value = socio.nombre || '';
            document.getElementById('editApellido').value = socio.apellido || '';
            document.getElementById('editTelefono').value = socio.telefono || '';
            document.getElementById('editEmail').value = socio.email || '';
            document.getElementById('editEmergenciaNombre').value = socio.contacto_emergencia_nombre || '';
            document.getElementById('editEmergenciaTelefono').value = socio.contacto_emergencia_telefono || '';
            document.getElementById('editEstado').value = socio.estado || 'activo';

            openModal(editModal);
            window.setTimeout(function () {
                document.getElementById('editNombre').focus();
            }, 80);
        });
    });

    document.querySelectorAll('.js-open-qr').forEach(function (button) {
        button.addEventListener('click', function () {
            if (button.disabled) return;

            currentQr = button.dataset.qr || '';
            const name = button.dataset.name || 'Socio';

            qrName.textContent = name;
            qrCodeText.textContent = currentQr;
            qrBox.innerHTML = '';

            if (window.QRCode && currentQr !== '') {
                new QRCode(qrBox, {
                    text: currentQr,
                    width: 190,
                    height: 190,
                    correctLevel: QRCode.CorrectLevel.H
                });
            } else {
                qrBox.innerHTML = '<i class="fas fa-qrcode"></i>';
            }

            openModal(qrModal);
        });
    });

    if (copyQrButton) {
        copyQrButton.addEventListener('click', async function () {
            if (currentQr === '') return;

            try {
                await navigator.clipboard.writeText(currentQr);
                copyQrButton.innerHTML = '<i class="fas fa-check"></i> Código copiado';
                window.setTimeout(function () {
                    copyQrButton.innerHTML = '<i class="fas fa-copy"></i> Copiar código';
                }, 1600);
            } catch (error) {
                window.prompt('Copia el código QR:', currentQr);
            }
        });
    }

    if (editForm) {
        editForm.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (!editForm.reportValidity()) {
                return;
            }

            const originalHtml = saveButton.innerHTML;
            saveButton.disabled = true;
            saveButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin',
                    body: new FormData(editForm)
                });

                const data = await response.json();

                if (!response.ok || !data.ok) {
                    throw new Error(data.mensaje || 'No fue posible guardar los cambios.');
                }

                closeModal(editModal);
                sessionStorage.setItem('sociosScrollY', String(window.scrollY));

                if (window.Swal) {
                    await Swal.fire({
                        icon: 'success',
                        title: 'Socio actualizado',
                        text: data.mensaje,
                        timer: 2200,
                        showConfirmButton: false,
                        allowOutsideClick: false
                    });
                }

                window.location.reload();
            } catch (error) {
                const message = error instanceof Error
                    ? error.message
                    : 'No fue posible guardar los cambios.';

                if (window.Swal) {
                    Swal.fire({
                        icon: 'error',
                        title: 'No se guardaron los cambios',
                        text: message,
                        confirmButtonText: 'Entendido',
                        confirmButtonColor: '#1e3a8a'
                    });
                } else {
                    alert(message);
                }
            } finally {
                saveButton.disabled = false;
                saveButton.innerHTML = originalHtml;
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        closeModal(editModal);
        closeModal(qrModal);
    });

    const savedScroll = Number(sessionStorage.getItem('sociosScrollY') || 0);
    const savedFocusField = sessionStorage.getItem('sociosFocusField') || '';

    sessionStorage.removeItem('sociosScrollY');
    sessionStorage.removeItem('sociosFocusField');

    window.requestAnimationFrame(function () {
        if (savedScroll > 0) {
            window.scrollTo({ top: savedScroll, behavior: 'auto' });
        }

        if (savedFocusField === 'q' && searchInput) {
            searchInput.focus({ preventScroll: true });
            const end = searchInput.value.length;
            searchInput.setSelectionRange(end, end);
        }
    });
})();
</script>
</body>
</html>