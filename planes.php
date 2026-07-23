<?php
// Archivo: planes.php
// Administración del catálogo corporativo y disponibilidad por sucursal.

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';

if (!isset($connPermisos) || !$connPermisos instanceof mysqli) {
    http_response_code(500);
    exit('No fue posible conectar con la base de datos.');
}

$conn = $connPermisos;
$conn->set_charset('utf8mb4');

function planes_h(?string $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function planes_strlen(string $valor): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($valor, 'UTF-8')
        : strlen($valor);
}

function planes_json(array $respuesta, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

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

function planes_formato_duracion(int $dias): string
{
    if ($dias === 1) {
        return '1 día';
    }

    if ($dias > 0 && $dias % 365 === 0) {
        $anios = (int) ($dias / 365);
        return $anios === 1 ? '1 año' : $anios . ' años';
    }

    if ($dias > 0 && $dias % 30 === 0) {
        $meses = (int) ($dias / 30);
        return $meses === 1 ? '1 mes' : $meses . ' meses';
    }

    if ($dias > 0 && $dias % 7 === 0) {
        $semanas = (int) ($dias / 7);
        return $semanas === 1 ? '1 semana' : $semanas . ' semanas';
    }

    return number_format($dias) . ' días';
}

function planes_sincronizar_sucursales(mysqli $db): void
{
    $sql = "INSERT IGNORE INTO planes_sucursales
                (sucursal_id, plan_id, precio, estado)
            SELECT
                s.id,
                p.id,
                CAST(p.precio AS DECIMAL(10,2)),
                CASE
                    WHEN p.estado = 'activo' THEN 'activo'
                    ELSE 'inactivo'
                END
            FROM sucursales s
            CROSS JOIN planes p
            WHERE s.estado = 'activa'";

    if (!$db->query($sql)) {
        throw new RuntimeException(
            'No fue posible sincronizar los planes con las sucursales: '
            . $db->error
        );
    }
}

function planes_nombre_duplicado(
    mysqli $db,
    string $nombre,
    int $excluirId = 0
): bool {
    $sql = "SELECT id
            FROM planes
            WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?))";

    if ($excluirId > 0) {
        $sql .= ' AND id <> ?';
    }

    $sql .= ' LIMIT 1';
    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'No fue posible validar el nombre del plan.'
        );
    }

    if ($excluirId > 0) {
        $stmt->bind_param('si', $nombre, $excluirId);
    } else {
        $stmt->bind_param('s', $nombre);
    }

    $stmt->execute();
    $duplicado = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $duplicado;
}

if (empty($_SESSION['planes_csrf'])) {
    $_SESSION['planes_csrf'] = bin2hex(random_bytes(32));
}

$csrfPlanes = (string) $_SESSION['planes_csrf'];
$rolPlanes = strtolower(trim((string) ($_SESSION['user_rol'] ?? '')));
$esAdministradorPlanes = in_array(
    $rolPlanes,
    ['admin', 'administrador'],
    true
);

if (!$esAdministradorPlanes) {
    http_response_code(403);
    exit('Este módulo está reservado para administradores.');
}

$sucursalPlanesId = (int) (
    $sucursal_id ?? ($_SESSION['sucursal_id'] ?? 0)
);
$sucursalPlanesNombre = trim((string) (
    $sucursal_nombre ?? ($_SESSION['sucursal_nombre'] ?? 'Sucursal')
));

$vistaSolicitada = strtolower(trim((string) ($_GET['vista'] ?? 'sucursal')));
$vistaGlobalPlanes = $vistaSolicitada === 'global';

try {
    planes_sincronizar_sucursales($conn);
} catch (Throwable $sincronizacionError) {
    error_log('[Planes sincronización] ' . $sincronizacionError->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = trim((string) ($_POST['accion'] ?? ''));
    $csrfRecibido = (string) ($_POST['csrf'] ?? '');

    if ($csrfRecibido === '' || !hash_equals($csrfPlanes, $csrfRecibido)) {
        planes_json([
            'ok' => false,
            'mensaje' => 'La sesión del formulario venció. Recarga la página.',
        ], 419);
    }

    if ($accion !== 'guardar_plan') {
        planes_json([
            'ok' => false,
            'mensaje' => 'La operación solicitada no es válida.',
        ], 400);
    }

    $planId = (int) ($_POST['plan_id'] ?? 0);
    $estado = strtolower(trim((string) ($_POST['estado'] ?? 'activo')));

    if (!in_array($estado, ['activo', 'inactivo'], true)) {
        planes_json([
            'ok' => false,
            'mensaje' => 'El estado seleccionado no es válido.',
        ], 422);
    }

    if ($vistaGlobalPlanes) {
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $duracionDias = (int) ($_POST['duracion_dias'] ?? 0);
        $precioTexto = trim((string) ($_POST['precio'] ?? ''));
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
        $aplicarPrecioTodas = isset($_POST['aplicar_precio_todas'])
            && (string) $_POST['aplicar_precio_todas'] === '1';

        if ($nombre === '' || $duracionDias <= 0 || $precioTexto === '') {
            planes_json([
                'ok' => false,
                'mensaje' => 'Nombre, duración y precio base son obligatorios.',
            ], 422);
        }

        if (planes_strlen($nombre) > 50) {
            planes_json([
                'ok' => false,
                'mensaje' => 'El nombre no puede superar 50 caracteres.',
            ], 422);
        }

        if ($descripcion !== '' && planes_strlen($descripcion) > 1000) {
            planes_json([
                'ok' => false,
                'mensaje' => 'La descripción no puede superar 1000 caracteres.',
            ], 422);
        }

        if ($duracionDias > 3650) {
            planes_json([
                'ok' => false,
                'mensaje' => 'La duración no puede superar 3650 días.',
            ], 422);
        }

        if (!preg_match('/^\d+$/', $precioTexto)) {
            planes_json([
                'ok' => false,
                'mensaje' => 'El precio base debe capturarse en pesos enteros.',
            ], 422);
        }

        $precioBase = (int) $precioTexto;

        if ($precioBase < 0 || $precioBase > 99999999) {
            planes_json([
                'ok' => false,
                'mensaje' => 'El precio base no es válido.',
            ], 422);
        }

        try {
            $esNuevo = $planId <= 0;

            if (planes_nombre_duplicado($conn, $nombre, $planId)) {
                planes_json([
                    'ok' => false,
                    'mensaje' => 'Ya existe otro plan con ese nombre.',
                ], 422);
            }

            $conn->begin_transaction();

            if ($planId > 0) {
                $stmtPlan = $conn->prepare(
                    "UPDATE planes
                     SET nombre = ?,
                         duracion_dias = ?,
                         precio = ?,
                         descripcion = ?,
                         estado = ?
                     WHERE id = ?"
                );

                if (!$stmtPlan) {
                    throw new RuntimeException(
                        'No fue posible preparar la actualización del plan.'
                    );
                }

                $stmtPlan->bind_param(
                    'siissi',
                    $nombre,
                    $duracionDias,
                    $precioBase,
                    $descripcion,
                    $estado,
                    $planId
                );
                $stmtPlan->execute();

                if ($stmtPlan->affected_rows < 0) {
                    throw new RuntimeException(
                        'No fue posible actualizar el plan.'
                    );
                }

                $stmtPlan->close();
            } else {
                $stmtPlan = $conn->prepare(
                    "INSERT INTO planes
                        (nombre, duracion_dias, precio, descripcion, estado)
                     VALUES (?, ?, ?, ?, ?)"
                );

                if (!$stmtPlan) {
                    throw new RuntimeException(
                        'No fue posible preparar el registro del plan.'
                    );
                }

                $stmtPlan->bind_param(
                    'siiss',
                    $nombre,
                    $duracionDias,
                    $precioBase,
                    $descripcion,
                    $estado
                );
                $stmtPlan->execute();
                $planId = (int) $conn->insert_id;
                $stmtPlan->close();
            }

            $stmtSincronizar = $conn->prepare(
                "INSERT IGNORE INTO planes_sucursales
                    (sucursal_id, plan_id, precio, estado)
                 SELECT id, ?, ?, ?
                 FROM sucursales
                 WHERE estado = 'activa'"
            );

            if (!$stmtSincronizar) {
                throw new RuntimeException(
                    'No fue posible preparar la disponibilidad por sucursal.'
                );
            }

            $precioSucursal = (float) $precioBase;
            $stmtSincronizar->bind_param(
                'ids',
                $planId,
                $precioSucursal,
                $estado
            );
            $stmtSincronizar->execute();
            $stmtSincronizar->close();

            if ($estado === 'inactivo') {
                $stmtEstado = $conn->prepare(
                    "UPDATE planes_sucursales
                     SET estado = 'inactivo'
                     WHERE plan_id = ?"
                );

                if (!$stmtEstado) {
                    throw new RuntimeException(
                        'No fue posible desactivar el plan en las sucursales.'
                    );
                }

                $stmtEstado->bind_param('i', $planId);
                $stmtEstado->execute();
                $stmtEstado->close();
            }

            if ($aplicarPrecioTodas) {
                $stmtPrecio = $conn->prepare(
                    "UPDATE planes_sucursales
                     SET precio = ?
                     WHERE plan_id = ?"
                );

                if (!$stmtPrecio) {
                    throw new RuntimeException(
                        'No fue posible aplicar el precio a las sucursales.'
                    );
                }

                $stmtPrecio->bind_param(
                    'di',
                    $precioSucursal,
                    $planId
                );
                $stmtPrecio->execute();
                $stmtPrecio->close();
            }

            $conn->commit();

            planes_json([
                'ok' => true,
                'mensaje' => $esNuevo
                    ? 'El plan se creó correctamente.'
                    : 'El plan se guardó correctamente.',
            ]);
        } catch (Throwable $error) {
            if ($conn->errno === 0) {
                // mysqli puede no reportar una transacción activa; rollback es seguro.
            }

            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                error_log('[Planes rollback] ' . $rollbackError->getMessage());
            }

            error_log('[Planes guardar global] ' . $error->getMessage());

            planes_json([
                'ok' => false,
                'mensaje' => 'No fue posible guardar el plan. Revisa los datos e intenta nuevamente.',
            ], 500);
        }
    }

    if ($planId <= 0 || $sucursalPlanesId <= 0) {
        planes_json([
            'ok' => false,
            'mensaje' => 'No fue posible identificar el plan o la sucursal.',
        ], 422);
    }

    $precioTexto = trim((string) ($_POST['precio'] ?? ''));

    if (
        $precioTexto === ''
        || !preg_match('/^\d+(?:\.\d{1,2})?$/', $precioTexto)
    ) {
        planes_json([
            'ok' => false,
            'mensaje' => 'Captura un precio válido con máximo dos decimales.',
        ], 422);
    }

    $precioSucursal = (float) $precioTexto;

    if ($precioSucursal < 0 || $precioSucursal > 99999999.99) {
        planes_json([
            'ok' => false,
            'mensaje' => 'El precio capturado no es válido.',
        ], 422);
    }

    $stmtPlanGlobal = $conn->prepare(
        'SELECT id FROM planes WHERE id = ? LIMIT 1'
    );

    if (!$stmtPlanGlobal) {
        planes_json([
            'ok' => false,
            'mensaje' => 'No fue posible validar el plan.',
        ], 500);
    }

    $stmtPlanGlobal->bind_param('i', $planId);
    $stmtPlanGlobal->execute();
    $planGlobal = $stmtPlanGlobal->get_result()->fetch_assoc();
    $stmtPlanGlobal->close();

    if (!$planGlobal) {
        planes_json([
            'ok' => false,
            'mensaje' => 'El plan seleccionado ya no existe.',
        ], 404);
    }

    $stmtSucursal = $conn->prepare(
        "INSERT INTO planes_sucursales
            (sucursal_id, plan_id, precio, estado)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            precio = VALUES(precio),
            estado = VALUES(estado)"
    );

    if (!$stmtSucursal) {
        planes_json([
            'ok' => false,
            'mensaje' => 'No fue posible preparar la actualización de la sucursal.',
        ], 500);
    }

    $stmtSucursal->bind_param(
        'iids',
        $sucursalPlanesId,
        $planId,
        $precioSucursal,
        $estado
    );

    if (!$stmtSucursal->execute()) {
        $stmtSucursal->close();

        planes_json([
            'ok' => false,
            'mensaje' => 'No fue posible actualizar el precio y la disponibilidad.',
        ], 500);
    }

    $stmtSucursal->close();

    planes_json([
        'ok' => true,
        'mensaje' => 'Precio y disponibilidad actualizados para la sucursal.',
    ]);
}

if ($vistaGlobalPlanes) {
    $sqlPlanes = "
        SELECT
            p.id,
            p.nombre,
            p.duracion_dias,
            p.precio AS precio_base,
            p.descripcion,
            p.estado AS estado_global,
            p.precio AS precio_mostrado,
            p.estado AS estado_mostrado,
            COALESCE(pa.sucursales_activas, 0) AS sucursales_activas,
            pa.precio_min,
            pa.precio_max,
            COALESCE(ia.membresias_activas, 0) AS membresias_activas,
            COALESCE(ia.usos_historicos, 0) AS usos_historicos
        FROM planes p
        LEFT JOIN (
            SELECT
                ps.plan_id,
                COUNT(DISTINCT CASE
                    WHEN ps.estado = 'activo' AND s.estado = 'activa'
                    THEN ps.sucursal_id
                END) AS sucursales_activas,
                MIN(CASE WHEN s.estado = 'activa' THEN ps.precio END) AS precio_min,
                MAX(CASE WHEN s.estado = 'activa' THEN ps.precio END) AS precio_max
            FROM planes_sucursales ps
            INNER JOIN sucursales s
                ON s.id = ps.sucursal_id
            GROUP BY ps.plan_id
        ) pa ON pa.plan_id = p.id
        LEFT JOIN (
            SELECT
                i.plan_id,
                SUM(
                    i.estado = 'activa'
                    AND CURDATE() BETWEEN i.fecha_inicio AND i.fecha_fin
                ) AS membresias_activas,
                COUNT(*) AS usos_historicos
            FROM inscripciones i
            GROUP BY i.plan_id
        ) ia ON ia.plan_id = p.id
        ORDER BY p.duracion_dias ASC, p.nombre ASC
    ";

    $resultadoPlanes = $conn->query($sqlPlanes);
} else {
    $sqlPlanes = "
        SELECT
            p.id,
            p.nombre,
            p.duracion_dias,
            p.precio AS precio_base,
            p.descripcion,
            p.estado AS estado_global,
            COALESCE(ps.precio, p.precio) AS precio_mostrado,
            COALESCE(ps.estado, p.estado) AS estado_mostrado,
            0 AS sucursales_activas,
            NULL AS precio_min,
            NULL AS precio_max,
            COALESCE(ia.membresias_activas, 0) AS membresias_activas,
            COALESCE(ia.usos_historicos, 0) AS usos_historicos
        FROM planes p
        LEFT JOIN planes_sucursales ps
          ON ps.plan_id = p.id
         AND ps.sucursal_id = ?
        LEFT JOIN (
            SELECT
                i.plan_id,
                SUM(
                    i.estado = 'activa'
                    AND CURDATE() BETWEEN i.fecha_inicio AND i.fecha_fin
                ) AS membresias_activas,
                COUNT(*) AS usos_historicos
            FROM inscripciones i
            INNER JOIN inscripciones_sucursales iss
              ON iss.inscripcion_id = i.id
             AND iss.sucursal_id = ?
            GROUP BY i.plan_id
        ) ia ON ia.plan_id = p.id
        ORDER BY p.duracion_dias ASC, p.nombre ASC
    ";

    $stmtPlanes = $conn->prepare($sqlPlanes);

    if (!$stmtPlanes) {
        http_response_code(500);
        exit('No fue posible consultar los planes.');
    }

    $stmtPlanes->bind_param(
        'ii',
        $sucursalPlanesId,
        $sucursalPlanesId
    );
    $stmtPlanes->execute();
    $resultadoPlanes = $stmtPlanes->get_result();
}

$planes = [];

while ($resultadoPlanes && $filaPlan = $resultadoPlanes->fetch_assoc()) {
    $planes[] = $filaPlan;
}

if (isset($stmtPlanes) && $stmtPlanes instanceof mysqli_stmt) {
    $stmtPlanes->close();
}

$totalPlanes = count($planes);
$planesDisponibles = 0;
$membresiasActivas = 0;
$planesUtilizados = 0;

foreach ($planes as $planResumen) {
    if ((string) $planResumen['estado_mostrado'] === 'activo') {
        $planesDisponibles++;
    }

    $membresiasActivas += (int) $planResumen['membresias_activas'];

    if ((int) $planResumen['usos_historicos'] > 0) {
        $planesUtilizados++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planes de membresía</title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >
    <link
        rel="stylesheet"
        href="css/planes.css?v=<?php echo (int) @filemtime(__DIR__ . '/css/planes.css'); ?>"
    >
</head>
<body>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<main class="main-content plans-main">
    <div class="plans-shell">
        <header class="plans-header">
            <div class="plans-heading">
                <h1>Planes</h1>
                <p>
                    Administra duración, precios y disponibilidad sin eliminar
                    el historial de las membresías existentes.
                </p>
            </div>

            <div class="plans-header-actions">
                <div class="plans-context-badge">
                    <span class="plans-context-icon">
                        <i class="fas <?php echo $vistaGlobalPlanes ? 'fa-earth-americas' : 'fa-building'; ?>"></i>
                    </span>
                    <span>
                        <small><?php echo $vistaGlobalPlanes ? 'Catálogo general' : 'Sucursal activa'; ?></small>
                        <strong><?php echo planes_h($vistaGlobalPlanes ? 'Todas las sucursales' : $sucursalPlanesNombre); ?></strong>
                    </span>
                </div>

                <?php if ($vistaGlobalPlanes): ?>
                    <button
                        type="button"
                        class="plans-primary-button"
                        id="newPlanButton"
                    >
                        <i class="fas fa-plus"></i>
                        Nuevo plan
                    </button>
                <?php else: ?>
                    <a
                        href="planes.php?vista=global"
                        class="plans-secondary-button"
                    >
                        <i class="fas fa-layer-group"></i>
                        Catálogo general
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <section class="plans-stats" aria-label="Resumen de planes">
            <article class="plans-stat-card stat-blue">
                <span class="plans-stat-icon"><i class="fas fa-layer-group"></i></span>
                <small class="plans-stat-label">Resumen</small>
                <strong id="statTotalPlanes"><?php echo number_format($totalPlanes); ?></strong>
                <span>Planes registrados</span>
            </article>

            <article class="plans-stat-card stat-green">
                <span class="plans-stat-icon"><i class="fas fa-circle-check"></i></span>
                <small class="plans-stat-label">Disponibilidad</small>
                <strong id="statAvailablePlanes"><?php echo number_format($planesDisponibles); ?></strong>
                <span>Disponibles</span>
            </article>

            <article class="plans-stat-card stat-purple">
                <span class="plans-stat-icon"><i class="fas fa-id-card"></i></span>
                <small class="plans-stat-label">Actividad</small>
                <strong id="statActiveMemberships"><?php echo number_format($membresiasActivas); ?></strong>
                <span>Membresías vigentes</span>
            </article>

            <article class="plans-stat-card stat-orange">
                <span class="plans-stat-icon"><i class="fas fa-chart-line"></i></span>
                <small class="plans-stat-label">Historial</small>
                <strong id="statPlanHistory"><?php echo number_format($planesUtilizados); ?></strong>
                <span>Planes con historial</span>
            </article>
        </section>

        <section class="plans-toolbar" aria-label="Filtros de planes">
            <label class="plans-search">
                <i class="fas fa-magnifying-glass"></i>
                <input
                    type="search"
                    id="plansSearch"
                    placeholder="Buscar por nombre o descripción"
                    autocomplete="off"
                >
            </label>

            <label class="plans-select-wrap">
                <i class="fas fa-toggle-on"></i>
                <select id="plansStatusFilter">
                    <option value="all">Todos los estados</option>
                    <option value="activo">Activos</option>
                    <option value="inactivo">Inactivos</option>
                </select>
            </label>

            <button
                type="button"
                class="plans-clear-button"
                id="plansClearFilters"
                hidden
            >
                <i class="fas fa-eraser"></i>
                Limpiar
            </button>

            <span class="plans-result-count" id="plansResultCount">
                <?php echo number_format($totalPlanes); ?> planes
            </span>
        </section>

        <section class="plans-grid" id="plansGrid">
            <?php foreach ($planes as $plan): ?>
                <?php
                $planId = (int) $plan['id'];
                $estadoMostrado = (string) $plan['estado_mostrado'];
                $estadoGlobal = (string) $plan['estado_global'];
                $precioMostrado = (float) $plan['precio_mostrado'];
                $precioMin = $plan['precio_min'] !== null
                    ? (float) $plan['precio_min']
                    : null;
                $precioMax = $plan['precio_max'] !== null
                    ? (float) $plan['precio_max']
                    : null;
                $descripcion = trim((string) ($plan['descripcion'] ?? ''));
                $busquedaPlan = strtolower(trim(
                    (string) $plan['nombre'] . ' ' . $descripcion
                ));
                $membresiasPlan = (int) $plan['membresias_activas'];
                $usosHistoricosPlan = (int) $plan['usos_historicos'];
                $datosPlan = [
                    'id' => $planId,
                    'nombre' => (string) $plan['nombre'],
                    'duracion_dias' => (int) $plan['duracion_dias'],
                    'precio' => $precioMostrado,
                    'precio_base' => (int) $plan['precio_base'],
                    'descripcion' => $descripcion,
                    'estado' => $estadoMostrado,
                    'estado_global' => $estadoGlobal,
                ];
                ?>

                <article
                    class="plan-card <?php echo $estadoMostrado === 'activo' ? 'is-active' : 'is-inactive'; ?>"
                    data-plan-card
                    data-search="<?php echo planes_h($busquedaPlan); ?>"
                    data-status="<?php echo planes_h($estadoMostrado); ?>"
                    data-memberships="<?php echo $membresiasPlan; ?>"
                    data-history="<?php echo $usosHistoricosPlan; ?>"
                >
                    <div class="plan-card-top">
                        <div class="plan-card-title-wrap">
                            <span class="plan-card-kicker">Plan de membresía</span>
                            <h2><?php echo planes_h((string) $plan['nombre']); ?></h2>
                        </div>

                        <span class="plan-status-badge <?php echo $estadoMostrado === 'activo' ? 'status-active' : 'status-inactive'; ?>">
                            <i class="fas <?php echo $estadoMostrado === 'activo' ? 'fa-circle-check' : 'fa-circle-pause'; ?>"></i>
                            <?php echo $estadoMostrado === 'activo' ? 'Disponible' : 'Inactivo'; ?>
                        </span>
                    </div>

                    <div class="plan-card-body">
                        <div class="plan-summary-row">
                            <div class="plan-price-block">
                                <small><?php echo $vistaGlobalPlanes ? 'Precio base' : 'Precio en esta sucursal'; ?></small>
                                <strong>$<?php echo number_format($precioMostrado, 2); ?></strong>
                            </div>

                            <span class="plan-duration-chip">
                                <i class="fas fa-calendar-days"></i>
                                <?php echo planes_h(planes_formato_duracion((int) $plan['duracion_dias'])); ?>
                            </span>
                        </div>

                        <div class="plan-data-grid">
                            <?php if ($vistaGlobalPlanes): ?>
                                <div class="plan-data-box plan-data-box-wide">
                                    <small>Rango por sucursal</small>
                                    <strong>
                                        <?php if ($precioMin === null || $precioMax === null): ?>
                                            Sin precios locales
                                        <?php elseif (abs($precioMin - $precioMax) < 0.001): ?>
                                            $<?php echo number_format($precioMin, 2); ?>
                                        <?php else: ?>
                                            $<?php echo number_format($precioMin, 2); ?> – $<?php echo number_format($precioMax, 2); ?>
                                        <?php endif; ?>
                                    </strong>
                                </div>

                                <div class="plan-data-box">
                                    <small>Sucursales activas</small>
                                    <strong><?php echo number_format((int) $plan['sucursales_activas']); ?></strong>
                                </div>
                            <?php else: ?>
                                <div class="plan-data-box plan-data-box-wide">
                                    <small>Vista de sucursal</small>
                                    <strong><?php echo planes_h($sucursalPlanesNombre); ?></strong>
                                </div>

                                <div class="plan-data-box">
                                    <small>Estado local</small>
                                    <strong><?php echo $estadoMostrado === 'activo' ? 'Disponible' : 'No disponible'; ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>

                        <p class="plan-description">
                            <?php echo planes_h(
                                $descripcion !== ''
                                    ? $descripcion
                                    : 'Sin descripción registrada.'
                            ); ?>
                        </p>

                        <div class="plan-metrics">
                            <div>
                                <span class="plan-metric-icon">
                                    <i class="fas fa-users"></i>
                                </span>
                                <span>
                                    <small>Membresías vigentes</small>
                                    <strong><?php echo number_format($membresiasPlan); ?></strong>
                                </span>
                            </div>

                            <div>
                                <span class="plan-metric-icon">
                                    <i class="fas fa-clock-rotate-left"></i>
                                </span>
                                <span>
                                    <small>Usos históricos</small>
                                    <strong><?php echo number_format($usosHistoricosPlan); ?></strong>
                                </span>
                            </div>
                        </div>
                    </div>

                    <footer class="plan-card-footer">
                        <span>
                            <i class="fas fa-pen-to-square"></i>
                            Puedes editar precio, estado y descripción
                        </span>

                        <button
                            type="button"
                            class="plan-edit-button"
                            data-edit-plan="<?php echo $planId; ?>"
                        >
                            <i class="fas fa-pen"></i>
                            Editar
                        </button>
                    </footer>
                </article>

                <script
                    type="application/json"
                    id="planData<?php echo $planId; ?>"
                ><?php echo json_encode(
                    $datosPlan,
                    JSON_UNESCAPED_UNICODE
                    | JSON_HEX_TAG
                    | JSON_HEX_APOS
                    | JSON_HEX_AMP
                    | JSON_HEX_QUOT
                ); ?></script>
            <?php endforeach; ?>
        </section>

        <div class="plans-empty" id="plansEmpty" hidden>
            <span><i class="fas fa-layer-group"></i></span>
            <h2>No encontramos planes</h2>
            <p>Modifica la búsqueda o limpia los filtros.</p>
        </div>
    </div>
</main>

<div class="plans-modal" id="planModal" aria-hidden="true">
    <div class="plans-modal-backdrop" data-close-plan-modal></div>

    <section
        class="plans-modal-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="planModalTitle"
    >
        <header class="plans-modal-header">
            <div>
                <span class="plans-modal-kicker">
                    <?php echo $vistaGlobalPlanes ? 'Catálogo general' : planes_h($sucursalPlanesNombre); ?>
                </span>
                <h2 id="planModalTitle">Editar plan</h2>
                <p id="planModalSubtitle">
                    Actualiza la información sin afectar pagos anteriores.
                </p>
            </div>

            <button
                type="button"
                class="plans-modal-close"
                data-close-plan-modal
                aria-label="Cerrar"
            >
                <i class="fas fa-xmark"></i>
            </button>
        </header>

        <form id="planForm" class="plans-form" novalidate>
            <input type="hidden" name="accion" value="guardar_plan">
            <input type="hidden" name="csrf" value="<?php echo planes_h($csrfPlanes); ?>">
            <input type="hidden" name="plan_id" id="planId" value="0">

            <?php if ($vistaGlobalPlanes): ?>
                <div class="plans-form-grid">
                    <label class="plans-field plans-field-wide">
                        <span>Nombre del plan</span>
                        <input
                            type="text"
                            name="nombre"
                            id="planName"
                            maxlength="50"
                            required
                        >
                    </label>

                    <label class="plans-field">
                        <span>Duración en días</span>
                        <input
                            type="number"
                            name="duracion_dias"
                            id="planDuration"
                            min="1"
                            max="3650"
                            step="1"
                            required
                        >
                    </label>

                    <label class="plans-field">
                        <span>Precio base</span>
                        <span class="plans-money-input">
                            <i class="fas fa-dollar-sign"></i>
                            <input
                                type="number"
                                name="precio"
                                id="planPrice"
                                min="0"
                                max="99999999"
                                step="1"
                                inputmode="numeric"
                                required
                            >
                        </span>
                        <small>Tu tabla actual guarda el precio base en pesos enteros.</small>
                    </label>

                    <label class="plans-field">
                        <span>Estado general</span>
                        <select name="estado" id="planStatus">
                            <option value="activo">Activo</option>
                            <option value="inactivo">Inactivo</option>
                        </select>
                    </label>

                    <label class="plans-field plans-field-wide">
                        <span>Descripción</span>
                        <textarea
                            name="descripcion"
                            id="planDescription"
                            maxlength="1000"
                            rows="4"
                            placeholder="Describe qué incluye o para quién está pensado"
                        ></textarea>
                    </label>
                </div>

                <label class="plans-check" id="applyPriceAllWrap">
                    <input
                        type="checkbox"
                        name="aplicar_precio_todas"
                        id="applyPriceAll"
                        value="1"
                    >
                    <span>
                        <strong>Aplicar el precio base a todas las sucursales</strong>
                        <small>
                            Úsalo solo cuando quieras reemplazar los precios locales actuales.
                        </small>
                    </span>
                </label>
            <?php else: ?>
                <div class="plans-local-summary">
                    <span class="plans-local-summary-icon">
                        <i class="fas fa-id-card"></i>
                    </span>
                    <span>
                        <small>Plan seleccionado</small>
                        <strong id="localPlanName">Plan</strong>
                        <em id="localPlanDuration">Duración</em>
                    </span>
                </div>

                <div class="plans-form-grid">
                    <label class="plans-field">
                        <span>Precio en <?php echo planes_h($sucursalPlanesNombre); ?></span>
                        <span class="plans-money-input">
                            <i class="fas fa-dollar-sign"></i>
                            <input
                                type="number"
                                name="precio"
                                id="planPrice"
                                min="0"
                                max="99999999.99"
                                step="0.01"
                                inputmode="decimal"
                                required
                            >
                        </span>
                    </label>

                    <label class="plans-field">
                        <span>Disponibilidad</span>
                        <select name="estado" id="planStatus">
                            <option value="activo">Disponible</option>
                            <option value="inactivo">No disponible</option>
                        </select>
                    </label>
                </div>
            <?php endif; ?>

            <footer class="plans-modal-footer">
                <button
                    type="button"
                    class="plans-secondary-button"
                    data-close-plan-modal
                >
                    Cancelar
                </button>

                <button
                    type="submit"
                    class="plans-primary-button"
                    id="savePlanButton"
                >
                    <i class="fas fa-floppy-disk"></i>
                    Guardar cambios
                </button>
            </footer>
        </form>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
    const isGlobalView = <?php echo $vistaGlobalPlanes ? 'true' : 'false'; ?>;
    const modal = document.getElementById('planModal');
    const form = document.getElementById('planForm');
    const planId = document.getElementById('planId');
    const modalTitle = document.getElementById('planModalTitle');
    const modalSubtitle = document.getElementById('planModalSubtitle');
    const saveButton = document.getElementById('savePlanButton');
    const search = document.getElementById('plansSearch');
    const statusFilter = document.getElementById('plansStatusFilter');
    const clearFilters = document.getElementById('plansClearFilters');
    const resultCount = document.getElementById('plansResultCount');
    const emptyState = document.getElementById('plansEmpty');
    const cards = Array.from(document.querySelectorAll('[data-plan-card]'));
    const statTotal = document.getElementById('statTotalPlanes');
    const statAvailable = document.getElementById('statAvailablePlanes');
    const statMemberships = document.getElementById('statActiveMemberships');
    const statHistory = document.getElementById('statPlanHistory');
    const editButtons = Array.from(document.querySelectorAll('[data-edit-plan]'));
    const closeButtons = Array.from(document.querySelectorAll('[data-close-plan-modal]'));
    const newPlanButton = document.getElementById('newPlanButton');
    let searchTimer = null;

    function normalize(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    }

    function planData(id) {
        const source = document.getElementById('planData' + id);

        if (!source) {
            return null;
        }

        try {
            return JSON.parse(source.textContent || '{}');
        } catch (error) {
            return null;
        }
    }

    function openModal() {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('plans-modal-open');

        window.setTimeout(function () {
            const firstInput = form.querySelector(
                'input:not([type="hidden"]), select, textarea'
            );

            if (firstInput) {
                firstInput.focus();
            }
        }, 60);
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('plans-modal-open');
        form.reset();
        planId.value = '0';
    }

    function prepareCreate() {
        form.reset();
        planId.value = '0';
        modalTitle.textContent = 'Nuevo plan';
        modalSubtitle.textContent =
            'Crea el plan y déjalo disponible en las sucursales activas.';

        document.getElementById('planStatus').value = 'activo';
        document.getElementById('applyPriceAll').checked = true;
        openModal();
    }

    function prepareEdit(data) {
        if (!data) {
            return;
        }

        form.reset();
        planId.value = String(data.id || 0);
        modalTitle.textContent = 'Editar ' + String(data.nombre || 'plan');
        modalSubtitle.textContent = isGlobalView
            ? 'Actualiza el catálogo general sin borrar su historial.'
            : 'Ajusta precio y disponibilidad solo para la sucursal activa.';

        document.getElementById('planPrice').value = String(
            data.precio == null ? '' : data.precio
        );
        document.getElementById('planStatus').value = String(
            data.estado || 'activo'
        );

        if (isGlobalView) {
            document.getElementById('planName').value = String(data.nombre || '');
            document.getElementById('planDuration').value = String(
                data.duracion_dias || ''
            );
            document.getElementById('planPrice').value = String(
                data.precio_base == null ? '' : data.precio_base
            );
            document.getElementById('planDescription').value = String(
                data.descripcion || ''
            );
            document.getElementById('applyPriceAll').checked = false;
        } else {
            document.getElementById('localPlanName').textContent = String(
                data.nombre || 'Plan'
            );
            document.getElementById('localPlanDuration').textContent =
                String(data.duracion_dias || 0) + ' días';

            const status = document.getElementById('planStatus');
            status.disabled = false;
        }

        openModal();
    }

    function updateVisibleStats() {
        let visibleTotal = 0;
        let visibleAvailable = 0;
        let visibleMemberships = 0;
        let visibleHistory = 0;

        cards.forEach(function (card) {
            if (card.hidden) {
                return;
            }

            visibleTotal += 1;

            if (card.dataset.status === 'activo') {
                visibleAvailable += 1;
            }

            visibleMemberships += Number(card.dataset.memberships || 0);

            if (Number(card.dataset.history || 0) > 0) {
                visibleHistory += 1;
            }
        });

        if (statTotal) {
            statTotal.textContent = String(visibleTotal);
        }

        if (statAvailable) {
            statAvailable.textContent = String(visibleAvailable);
        }

        if (statMemberships) {
            statMemberships.textContent = String(visibleMemberships);
        }

        if (statHistory) {
            statHistory.textContent = String(visibleHistory);
        }
    }

    function applyFilters() {
        const query = normalize(search.value);
        const status = statusFilter.value;
        let visible = 0;

        cards.forEach(function (card) {
            const matchesQuery = query === ''
                || normalize(card.dataset.search).includes(query);
            const matchesStatus = status === 'all'
                || card.dataset.status === status;
            const show = matchesQuery && matchesStatus;

            card.hidden = !show;

            if (show) {
                visible++;
            }
        });

        resultCount.textContent = visible + (visible === 1 ? ' plan' : ' planes');
        emptyState.hidden = visible !== 0;
        clearFilters.hidden = query === '' && status === 'all';
        updateVisibleStats();
    }

    search.addEventListener('input', function () {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(applyFilters, 560);
    });

    statusFilter.addEventListener('change', applyFilters);

    clearFilters.addEventListener('click', function () {
        search.value = '';
        statusFilter.value = 'all';
        applyFilters();
        search.focus();
    });

    editButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            prepareEdit(planData(button.dataset.editPlan));
        });
    });

    closeButtons.forEach(function (button) {
        button.addEventListener('click', closeModal);
    });

    if (newPlanButton) {
        newPlanButton.addEventListener('click', prepareCreate);
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (!form.reportValidity()) {
            return;
        }

        saveButton.disabled = true;
        saveButton.innerHTML =
            '<i class="fas fa-spinner fa-spin"></i> Guardando...';

        try {
            const payload = new FormData(form);
            const statusSelect = document.getElementById('planStatus');

            if (statusSelect && statusSelect.disabled) {
                payload.set('estado', statusSelect.value);
            }

            const response = await fetch(
                'planes.php?vista=' + (isGlobalView ? 'global' : 'sucursal'),
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: payload
                }
            );

            const text = await response.text();
            let data;

            try {
                data = JSON.parse(text);
            } catch (parseError) {
                throw new Error(
                    'El servidor devolvió una respuesta no válida.'
                );
            }

            if (!response.ok || !data.ok) {
                throw new Error(
                    data.mensaje || 'No fue posible guardar el plan.'
                );
            }

            closeModal();

            await Swal.fire({
                icon: 'success',
                title: 'Cambios guardados',
                text: data.mensaje,
                timer: 1800,
                showConfirmButton: false
            });

            window.location.reload();
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'No se pudo guardar',
                text: error instanceof Error
                    ? error.message
                    : 'No fue posible completar la operación.',
                confirmButtonText: 'Entendido',
                confirmButtonColor: '#1e3a8a'
            });
        } finally {
            saveButton.disabled = false;
            saveButton.innerHTML =
                '<i class="fas fa-floppy-disk"></i> Guardar cambios';
        }
    });

    applyFilters();
})();
</script>
</body>
</html>
