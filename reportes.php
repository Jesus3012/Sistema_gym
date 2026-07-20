<?php
// Archivo: reportes.php

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/sucursal_context.php';

date_default_timezone_set('America/Mexico_City');

$database = new Database();
$conn = $database->getConnection();

if (!$conn instanceof mysqli) {
    die('No fue posible establecer conexión con la base de datos.');
}

$conn->set_charset('utf8mb4');

function reporteJson($codigo, $respuesta)
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    echo json_encode(
        $respuesta,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit();
}

function reporteBindParams($stmt, $tipos, &$parametros)
{
    if ($tipos === '' || empty($parametros)) {
        return true;
    }

    $referencias = array();
    $referencias[] = $tipos;

    foreach ($parametros as $indice => $valor) {
        $referencias[] = &$parametros[$indice];
    }

    return call_user_func_array(
        array($stmt, 'bind_param'),
        $referencias
    );
}

function reporteFechaValida($fecha)
{
    if ($fecha === '') {
        return true;
    }

    $objeto = DateTime::createFromFormat('Y-m-d', $fecha);

    return $objeto &&
        $objeto->format('Y-m-d') === $fecha;
}

function obtenerEmpresaReportes($conn)
{
    $empresa = array(
        'nombre' => 'Gimnasio',
        'telefono' => '',
        'email' => '',
        'direccion' => '',
        'logo' => ''
    );

    $resultado = $conn->query(
        "SELECT nombre, telefono, email, direccion, logo
         FROM configuracion_gimnasio
         ORDER BY id ASC
         LIMIT 1"
    );

    if ($resultado && $fila = $resultado->fetch_assoc()) {
        foreach ($empresa as $campo => $valor) {
            if (isset($fila[$campo]) && $fila[$campo] !== null) {
                $empresa[$campo] = (string) $fila[$campo];
            }
        }
    }

    if ($empresa['logo'] === '') {
        $candidatos = array(
            'img/logo-gym.png',
            'img/logo-gym.jpg',
            'img/logo-gym.jpeg',
            'img/logo.png',
            'img/logo.jpg'
        );

        foreach ($candidatos as $ruta) {
            if (file_exists(__DIR__ . '/' . $ruta)) {
                $empresa['logo'] = $ruta;
                break;
            }
        }
    }

    return $empresa;
}


$usuarioId = isset($_SESSION['user_id'])
    ? (int) $_SESSION['user_id']
    : 0;

$usuarioNombre = isset($_SESSION['user_name'])
    ? (string) $_SESSION['user_name']
    : 'Usuario';

$usuarioRol = isset($_SESSION['user_rol'])
    ? (string) $_SESSION['user_rol']
    : '';

$usuarioRolBase = strtolower(trim((string) (
    $_SESSION['user_rol_base'] ?? $usuarioRol
)));

$puedeVistaGlobalReportes = in_array(
    $usuarioRolBase,
    array('admin', 'administrador'),
    true
);

try {
    if (function_exists('sucursal_inicializar_sesion')) {
        sucursal_inicializar_sesion($conn);
    }
} catch (Throwable $errorSucursal) {
    if (
        isset($_GET['action'])
        && $_GET['action'] === 'datos'
    ) {
        reporteJson(409, array(
            'success' => false,
            'message' => $errorSucursal->getMessage()
        ));
    }

    die(htmlspecialchars(
        $errorSucursal->getMessage(),
        ENT_QUOTES,
        'UTF-8'
    ));
}

$vistaSolicitadaReportes = strtolower(trim((string) (
    $_GET['vista'] ?? ''
)));

if (
    $vistaSolicitadaReportes === 'global'
    && $puedeVistaGlobalReportes
) {
    sucursal_activar_vista_global(
        $conn,
        $usuarioId
    );
} elseif ($vistaSolicitadaReportes === 'sucursal') {
    sucursal_desactivar_vista_global();
}

$vistaGlobalReportes =
    $puedeVistaGlobalReportes
    && function_exists('sucursal_dashboard_vista_global')
    && sucursal_dashboard_vista_global();

$sucursalIdReportes = (int) (
    $_SESSION['sucursal_id'] ?? 0
);

$sucursalActualReportes = null;

if ($sucursalIdReportes > 0) {
    $sucursalActualReportes = sucursal_buscar_asignada(
        $conn,
        $usuarioId,
        $sucursalIdReportes
    );
}

if (!$sucursalActualReportes) {
    if (
        isset($_GET['action'])
        && $_GET['action'] === 'datos'
    ) {
        reporteJson(409, array(
            'success' => false,
            'message' =>
                'Selecciona una sucursal operativa antes de generar reportes.'
        ));
    }

    $_SESSION['error'] =
        'Selecciona una sucursal operativa antes de abrir reportes.';

    header('Location: dashboard.php');
    exit;
}

date_default_timezone_set((string) (
    $sucursalActualReportes['zona_horaria']
    ?? 'America/Mexico_City'
));

$totalSucursalesReportes = 0;

$resultadoTotalSucursales = $conn->query(
    "SELECT COUNT(*) AS total
     FROM sucursales
     WHERE estado = 'activa'"
);

if (
    $resultadoTotalSucursales
    && $filaTotalSucursales =
        $resultadoTotalSucursales->fetch_assoc()
) {
    $totalSucursalesReportes = (int) (
        $filaTotalSucursales['total'] ?? 0
    );
}

$contextoNombreReportes = $vistaGlobalReportes
    ? 'Todas las sucursales'
    : (string) $sucursalActualReportes['nombre'];

$contextoClaveReportes = $vistaGlobalReportes
    ? 'GLOBAL'
    : (string) $sucursalActualReportes['clave'];

$contextoDetalleReportes = $vistaGlobalReportes
    ? (
        $totalSucursalesReportes === 1
            ? '1 sede activa'
            : $totalSucursalesReportes . ' sedes activas'
    )
    : (
        $contextoClaveReportes
        . (
            (int) (
                $sucursalActualReportes['es_matriz'] ?? 0
            ) === 1
                ? ' · Matriz'
                : ' · Sucursal'
        )
    );

$contextoReportes = array(
    'vista_global' => $vistaGlobalReportes,
    'sucursal_id' => $vistaGlobalReportes
        ? 0
        : $sucursalIdReportes,
    'nombre' => $contextoNombreReportes,
    'clave' => $contextoClaveReportes,
    'detalle' => $contextoDetalleReportes
);

/*
 * Endpoint interno.
 * El mismo reportes.php entrega los datos en JSON antes de cargar el sidebar.
 */
if (
    isset($_GET['action']) &&
    $_GET['action'] === 'datos'
) {
    try {
        $tipo = isset($_GET['tipo'])
            ? strtolower(trim((string) $_GET['tipo']))
            : 'inscripciones';

        if (!in_array($tipo, array('inscripciones', 'ventas'), true)) {
            throw new InvalidArgumentException(
                'El tipo de reporte no es válido.'
            );
        }

        $busqueda = isset($_GET['search'])
            ? trim((string) $_GET['search'])
            : '';

        $fechaInicio = isset($_GET['fecha_inicio'])
            ? trim((string) $_GET['fecha_inicio'])
            : '';

        $fechaFin = isset($_GET['fecha_fin'])
            ? trim((string) $_GET['fecha_fin'])
            : '';

        if (mb_strlen($busqueda) > 120) {
            throw new InvalidArgumentException(
                'La búsqueda es demasiado larga.'
            );
        }

        if (
            !reporteFechaValida($fechaInicio) ||
            !reporteFechaValida($fechaFin)
        ) {
            throw new InvalidArgumentException(
                'El rango de fechas no es válido.'
            );
        }

        if (
            $fechaInicio !== '' &&
            $fechaFin !== '' &&
            $fechaInicio > $fechaFin
        ) {
            throw new InvalidArgumentException(
                'La fecha inicial no puede ser mayor que la final.'
            );
        }

        if ($tipo === 'inscripciones') {
            $plan = isset($_GET['plan'])
                ? (int) $_GET['plan']
                : 0;

            $estado = isset($_GET['estado'])
                ? strtolower(trim((string) $_GET['estado']))
                : '';

            if (
                $estado !== '' &&
                !in_array(
                    $estado,
                    array('activa', 'vencida', 'cancelada'),
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    'El estado de inscripción no es válido.'
                );
            }

            $condiciones = array('1 = 1');
            $tipos = '';
            $parametros = array();

            if (!$vistaGlobalReportes) {
                $condiciones[] = 'i.sucursal_id = ?';
                $tipos .= 'i';
                $parametros[] = $sucursalIdReportes;
            }

            if ($busqueda !== '') {
                $texto = '%' . $busqueda . '%';

                $condiciones[] = "(
                    CONCAT(c.nombre, ' ', c.apellido) LIKE ?
                    OR c.telefono LIKE ?
                    OR c.email LIKE ?
                    OR p.nombre LIKE ?
                    OR s.nombre LIKE ?
                    OR s.clave LIKE ?
                    OR CAST(i.id AS CHAR) LIKE ?
                )";

                $tipos .= 'sssssss';
                $parametros[] = $texto;
                $parametros[] = $texto;
                $parametros[] = $texto;
                $parametros[] = $texto;
                $parametros[] = $texto;
                $parametros[] = $texto;
                $parametros[] = $texto;
            }

            if ($plan > 0) {
                $condiciones[] = 'i.plan_id = ?';
                $tipos .= 'i';
                $parametros[] = $plan;
            }

            if ($estado !== '') {
                $condiciones[] = 'i.estado = ?';
                $tipos .= 's';
                $parametros[] = $estado;
            }

            if ($fechaInicio !== '') {
                $condiciones[] = 'i.fecha_inicio >= ?';
                $tipos .= 's';
                $parametros[] = $fechaInicio;
            }

            if ($fechaFin !== '') {
                $condiciones[] = 'i.fecha_inicio <= ?';
                $tipos .= 's';
                $parametros[] = $fechaFin;
            }

            $sql = "SELECT
                        i.id,
                        i.sucursal_id,
                        s.nombre AS sucursal_nombre,
                        s.clave AS sucursal_clave,
                        c.id AS cliente_id,
                        c.nombre AS cliente_nombre,
                        c.apellido AS cliente_apellido,
                        c.telefono,
                        c.email,
                        p.id AS plan_id,
                        p.nombre AS plan_nombre,
                        i.fecha_inicio,
                        i.fecha_fin,
                        i.precio_pagado,
                        i.estado,
                        i.fecha_registro,
                        DATEDIFF(i.fecha_fin, CURDATE()) AS dias_restantes
                    FROM inscripciones i
                    INNER JOIN sucursales s
                        ON s.id = i.sucursal_id
                    INNER JOIN clientes c
                        ON c.id = i.cliente_id
                    INNER JOIN planes p
                        ON p.id = i.plan_id
                    WHERE " . implode(' AND ', $condiciones) . "
                    ORDER BY
                        i.fecha_inicio DESC,
                        i.id DESC
                    LIMIT 5000";
        } else {
            $metodo = isset($_GET['metodo'])
                ? strtolower(trim((string) $_GET['metodo']))
                : '';

            $estado = isset($_GET['estado'])
                ? strtolower(trim((string) $_GET['estado']))
                : '';

            if (
                $metodo !== '' &&
                !in_array(
                    $metodo,
                    array('efectivo', 'tarjeta', 'transferencia'),
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    'El método de pago no es válido.'
                );
            }

            if (
                $estado !== '' &&
                !in_array(
                    $estado,
                    array('completada', 'cancelada', 'pendiente'),
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    'El estado de venta no es válido.'
                );
            }

            $condiciones = array('1 = 1');
            $tipos = '';
            $parametros = array();

            if (!$vistaGlobalReportes) {
                $condiciones[] = 'v.sucursal_id = ?';
                $tipos .= 'i';
                $parametros[] = $sucursalIdReportes;
            }

            if ($busqueda !== '') {
                $texto = '%' . $busqueda . '%';

                $condiciones[] = "(
                    CAST(v.id AS CHAR) LIKE ?
                    OR CONCAT(c.nombre, ' ', c.apellido) LIKE ?
                    OR u.nombre LIKE ?
                    OR detalle.productos LIKE ?
                    OR s.nombre LIKE ?
                    OR s.clave LIKE ?
                )";

                $tipos .= 'ssssss';
                $parametros[] = $texto;
                $parametros[] = $texto;
                $parametros[] = $texto;
                $parametros[] = $texto;
                $parametros[] = $texto;
                $parametros[] = $texto;
            }

            if ($metodo !== '') {
                $condiciones[] = 'v.metodo_pago = ?';
                $tipos .= 's';
                $parametros[] = $metodo;
            }

            if ($estado !== '') {
                $condiciones[] = 'v.estado = ?';
                $tipos .= 's';
                $parametros[] = $estado;
            }

            if ($fechaInicio !== '') {
                $condiciones[] = 'DATE(v.fecha_venta) >= ?';
                $tipos .= 's';
                $parametros[] = $fechaInicio;
            }

            if ($fechaFin !== '') {
                $condiciones[] = 'DATE(v.fecha_venta) <= ?';
                $tipos .= 's';
                $parametros[] = $fechaFin;
            }

            $sql = "SELECT
                        v.id,
                        v.sucursal_id,
                        s.nombre AS sucursal_nombre,
                        s.clave AS sucursal_clave,
                        v.fecha_venta,
                        v.cliente_id,
                        CASE
                            WHEN c.id IS NULL
                                THEN 'Venta al público'
                            ELSE CONCAT(c.nombre, ' ', c.apellido)
                        END AS cliente_nombre,
                        u.nombre AS vendedor_nombre,
                        v.metodo_pago,
                        v.estado,
                        COALESCE(ticket.total_original, v.total) AS total_bruto,
                        CASE
                            WHEN v.estado = 'cancelada'
                             AND COALESCE(devolucion.total_devuelto, 0) = 0
                                THEN COALESCE(ticket.total_original, v.total)
                            ELSE COALESCE(devolucion.total_devuelto, 0)
                        END AS devoluciones,
                        CASE
                            WHEN v.estado = 'cancelada'
                                THEN 0
                            ELSE GREATEST(
                                COALESCE(ticket.total_original, v.total) -
                                COALESCE(devolucion.total_devuelto, 0),
                                0
                            )
                        END AS total_neto,
                        COALESCE(detalle.productos_distintos, 0)
                            AS productos_distintos,
                        COALESCE(detalle.unidades, 0) AS unidades,
                        COALESCE(detalle.productos, 'Sin detalle')
                            AS productos
                    FROM ventas v
                    INNER JOIN sucursales s
                        ON s.id = v.sucursal_id
                    LEFT JOIN clientes c
                        ON c.id = v.cliente_id
                    INNER JOIN usuarios u
                        ON u.id = v.usuario_id
                    LEFT JOIN (
                        SELECT
                            venta_id,
                            MAX(total) AS total_original
                        FROM tickets_venta
                        GROUP BY venta_id
                    ) ticket
                        ON ticket.venta_id = v.id
                    LEFT JOIN (
                        SELECT
                            venta_id,
                            SUM(
                                CASE
                                    WHEN monto_devuelto IS NOT NULL
                                        THEN monto_devuelto
                                    ELSE 0
                                END
                            ) AS total_devuelto
                        FROM ventas_modificaciones
                        WHERE tipo_modificacion IN (
                            'cancelacion',
                            'devolucion_parcial'
                        )
                        GROUP BY venta_id
                    ) devolucion
                        ON devolucion.venta_id = v.id
                    LEFT JOIN (
                        SELECT
                            dv.venta_id,
                            COUNT(DISTINCT dv.producto_id)
                                AS productos_distintos,
                            SUM(dv.cantidad) AS unidades,
                            GROUP_CONCAT(
                                CONCAT(
                                    p.nombre,
                                    ' x',
                                    dv.cantidad
                                )
                                ORDER BY p.nombre
                                SEPARATOR ', '
                            ) AS productos
                        FROM detalle_ventas dv
                        INNER JOIN productos p
                            ON p.id = dv.producto_id
                        GROUP BY dv.venta_id
                    ) detalle
                        ON detalle.venta_id = v.id
                    WHERE " . implode(' AND ', $condiciones) . "
                    ORDER BY
                        v.fecha_venta DESC,
                        v.id DESC
                    LIMIT 5000";
        }

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException(
                'No se pudo preparar el reporte: ' . $conn->error
            );
        }

        if (!reporteBindParams($stmt, $tipos, $parametros)) {
            throw new RuntimeException(
                'No se pudieron vincular los filtros del reporte.'
            );
        }

        if (!$stmt->execute()) {
            $detalle = $stmt->error;
            $stmt->close();

            throw new RuntimeException(
                'No se pudo generar el reporte: ' . $detalle
            );
        }

        $resultado = $stmt->get_result();
        $datos = array();

        while ($fila = $resultado->fetch_assoc()) {
            if ($tipo === 'inscripciones') {
                $fila['id'] = (int) $fila['id'];
                $fila['sucursal_id'] =
                    (int) $fila['sucursal_id'];
                $fila['cliente_id'] = (int) $fila['cliente_id'];
                $fila['plan_id'] = (int) $fila['plan_id'];
                $fila['precio_pagado'] =
                    (float) $fila['precio_pagado'];

                if ($fila['dias_restantes'] !== null) {
                    $fila['dias_restantes'] =
                        (int) $fila['dias_restantes'];
                }
            } else {
                $fila['id'] = (int) $fila['id'];
                $fila['sucursal_id'] =
                    (int) $fila['sucursal_id'];

                if ($fila['cliente_id'] !== null) {
                    $fila['cliente_id'] =
                        (int) $fila['cliente_id'];
                }

                $fila['total_bruto'] =
                    (float) $fila['total_bruto'];

                $fila['devoluciones'] =
                    (float) $fila['devoluciones'];

                $fila['total_neto'] =
                    (float) $fila['total_neto'];

                $fila['productos_distintos'] =
                    (int) $fila['productos_distintos'];

                $fila['unidades'] =
                    (int) $fila['unidades'];
            }

            $datos[] = $fila;
        }

        $stmt->close();

        reporteJson(200, array(
            'success' => true,
            'tipo' => $tipo,
            'contexto' => $contextoReportes,
            'datos' => $datos,
            'total' => count($datos),
            'limitado' => count($datos) >= 5000
        ));
    } catch (Throwable $error) {
        reporteJson(500, array(
            'success' => false,
            'message' => $error->getMessage()
        ));
    }
}

$empresa = obtenerEmpresaReportes($conn);

if (!$vistaGlobalReportes) {
    foreach (
        array(
            'nombre',
            'telefono',
            'email',
            'direccion',
            'logo'
        ) as $campoEmpresa
    ) {
        $valorSucursal = trim((string) (
            $sucursalActualReportes[$campoEmpresa] ?? ''
        ));

        if ($valorSucursal !== '') {
            $empresa[$campoEmpresa] = $valorSucursal;
        }
    }
}

$empresa['contexto_nombre'] = $contextoNombreReportes;
$empresa['contexto_clave'] = $contextoClaveReportes;
$empresa['vista_global'] = $vistaGlobalReportes;

$planes = array();

$sqlPlanes = "
    SELECT DISTINCT
        p.id,
        p.nombre
    FROM inscripciones i
    INNER JOIN planes p
        ON p.id = i.plan_id
";

if (!$vistaGlobalReportes) {
    $sqlPlanes .= " WHERE i.sucursal_id = ?";
}

$sqlPlanes .= " ORDER BY p.nombre ASC";

$stmtPlanes = $conn->prepare($sqlPlanes);

if ($stmtPlanes) {
    if (!$vistaGlobalReportes) {
        $stmtPlanes->bind_param(
            'i',
            $sucursalIdReportes
        );
    }

    $stmtPlanes->execute();
    $resultadoPlanes = $stmtPlanes->get_result();

    while ($plan = $resultadoPlanes->fetch_assoc()) {
        $planes[] = array(
            'id' => (int) $plan['id'],
            'nombre' => (string) $plan['nombre']
        );
    }

    $stmtPlanes->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Centro de Reportes</title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css"
    >

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>


    <?php
    $reportesCss = __DIR__ . '/css/reportes.css';
    ?>
    <link
        rel="stylesheet"
        href="css/reportes.css?v=<?php echo is_file($reportesCss) ? (int) filemtime($reportesCss) : time(); ?>"
    >
</head>
<body>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<main class="main-content">
    <div class="report-page">
        <div class="report-shell">
            <header class="report-topbar">
                <div class="report-heading">
                    <h1>Reportes del sistema</h1>

                    <p>
                        <?php if ($vistaGlobalReportes): ?>
                            Consulta inscripciones y ventas consolidadas de
                            todas las sucursales, aplica filtros y exporta
                            el resultado.
                        <?php else: ?>
                            Consulta la actividad de
                            <strong>
                                <?php echo htmlspecialchars(
                                    $contextoNombreReportes,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </strong>,
                            aplica filtros y descarga el resultado.
                        <?php endif; ?>
                    </p>
                </div>

                <div class="report-topbar-actions">
                    <div class="report-context-chip <?php echo $vistaGlobalReportes ? 'global' : 'branch'; ?>">
                        <i class="fas <?php echo $vistaGlobalReportes ? 'fa-chart-pie' : 'fa-building'; ?>"></i>

                        <div>
                            <strong>
                                <?php echo htmlspecialchars(
                                    $contextoNombreReportes,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </strong>

                            <small>
                                <?php echo htmlspecialchars(
                                    $contextoDetalleReportes,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </small>
                        </div>
                    </div>

                    <div class="report-user-chip">
                        <i class="fas fa-user"></i>

                        <div>
                            <strong>
                                <?php
                                echo htmlspecialchars(
                                    $usuarioNombre,
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                                ?>
                            </strong>

                            <small>
                                <?php
                                echo htmlspecialchars(
                                    $usuarioRol,
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                                ?>
                            </small>
                        </div>
                    </div>
                </div>
            </header>

            <section class="report-type-grid">
                <button
                    type="button"
                    class="report-type active"
                    data-type="inscripciones"
                >
                    <span class="report-type-icon">
                        <i class="fas fa-id-card"></i>
                    </span>

                    <span class="report-type-copy">
                        <strong>Inscripciones</strong>
                        <span>
                            Membresías, planes, vigencias, clientes e ingresos.
                        </span>
                    </span>

                    <span class="report-type-check">
                        <i class="fas fa-check"></i>
                    </span>
                </button>

                <button
                    type="button"
                    class="report-type"
                    data-type="ventas"
                >
                    <span class="report-type-icon">
                        <i class="fas fa-basket-shopping"></i>
                    </span>

                    <span class="report-type-copy">
                        <strong>Ventas de productos</strong>
                        <span>
                            Productos vendidos, devoluciones, métodos y totales.
                        </span>
                    </span>

                    <span class="report-type-check">
                        <i class="fas fa-check"></i>
                    </span>
                </button>
            </section>

            <section class="report-card report-filter-card">
                <div class="report-filter-head">
                    <div class="report-filter-title">
                        <i class="fas fa-filter"></i>

                        <div>
                            <h2>Filtros</h2>
                            <p>
                                La tabla se actualiza automáticamente al
                                cambiar cualquier criterio.
                            </p>
                        </div>
                    </div>

                    <div class="report-filter-meta">
                        <span
                            class="report-live-status ready"
                            id="liveStatus"
                        >
                            <span class="report-live-dot"></span>
                            Actualización automática
                        </span>

                        <span
                            class="report-filter-count"
                            id="filterCount"
                        >
                            <i class="fas fa-filter"></i>
                            Sin filtros
                        </span>
                    </div>
                </div>

                <div class="report-filter-body">
                    <div class="report-filter-grid">
                        <div class="report-field">
                            <label for="searchInput">
                                Búsqueda
                            </label>

                            <div class="report-control">
                                <i class="fas fa-magnifying-glass"></i>

                                <input
                                    type="text"
                                    id="searchInput"
                                    maxlength="120"
                                    placeholder="Cliente, plan, ticket, producto..."
                                >
                            </div>
                        </div>

                        <div
                            class="report-field"
                            id="inscriptionPlanField"
                        >
                            <label for="planSelect">
                                Plan
                            </label>

                            <div class="report-control">
                                <select id="planSelect">
                                    <option value="">
                                        Todos los planes
                                    </option>

                                    <?php foreach ($planes as $plan): ?>
                                        <option
                                            value="<?php
                                            echo (int) $plan['id'];
                                            ?>"
                                        >
                                            <?php
                                            echo htmlspecialchars(
                                                $plan['nombre'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            );
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div
                            class="report-field"
                            id="salesMethodField"
                            hidden
                        >
                            <label for="methodSelect">
                                Método
                            </label>

                            <div class="report-control">
                                <select id="methodSelect">
                                    <option value="">
                                        Todos los métodos
                                    </option>
                                    <option value="efectivo">
                                        Efectivo
                                    </option>
                                    <option value="tarjeta">
                                        Tarjeta
                                    </option>
                                    <option value="transferencia">
                                        Transferencia
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="report-field">
                            <label for="statusSelect">
                                Estado
                            </label>

                            <div class="report-control">
                                <select id="statusSelect"></select>
                            </div>
                        </div>

                        <div class="report-field">
                            <label for="dateRange">
                                Periodo del reporte
                            </label>

                            <div class="report-control">
                                <i class="fas fa-calendar-days"></i>

                                <input
                                    type="text"
                                    id="dateRange"
                                    placeholder="Selecciona un rango"
                                    readonly
                                >

                                <button
                                    type="button"
                                    class="report-date-clear"
                                    id="clearDates"
                                    title="Limpiar fechas"
                                >
                                    <i class="fas fa-xmark"></i>
                                </button>
                            </div>

                            <div class="report-date-quick">
                                <button
                                    type="button"
                                    class="report-quick-btn"
                                    data-range="today"
                                >
                                    Hoy
                                </button>

                                <button
                                    type="button"
                                    class="report-quick-btn"
                                    data-range="7days"
                                >
                                    Últimos 7 días
                                </button>

                                <button
                                    type="button"
                                    class="report-quick-btn"
                                    data-range="month"
                                >
                                    Este mes
                                </button>

                                <button
                                    type="button"
                                    class="report-quick-btn"
                                    data-range="previous"
                                >
                                    Mes anterior
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="report-filter-actions">
                        <button
                            type="button"
                            class="report-btn report-btn-soft"
                            id="clearFilters"
                        >
                            <i class="fas fa-eraser"></i>
                            Limpiar
                        </button>

                        <button
                            type="button"
                            class="report-btn report-btn-primary"
                            id="applyFilters"
                        >
                            <i class="fas fa-rotate"></i>
                            Actualizar
                        </button>
                    </div>
                </div>
            </section>

            <section
                class="report-stats-grid"
                id="statsGrid"
            >
                <article class="report-card report-stat">
                    <div class="report-stat-top">
                        <span class="report-stat-label" id="statLabel1">
                            Registros
                        </span>

                        <span class="report-stat-icon">
                            <i class="fas fa-list-check"></i>
                        </span>
                    </div>

                    <strong class="report-stat-value" id="statValue1">
                        0
                    </strong>

                    <small class="report-stat-note" id="statNote1">
                        Resultado del filtro
                    </small>
                </article>

                <article class="report-card report-stat">
                    <div class="report-stat-top">
                        <span class="report-stat-label" id="statLabel2">
                            Ingresos
                        </span>

                        <span class="report-stat-icon">
                            <i class="fas fa-dollar-sign"></i>
                        </span>
                    </div>

                    <strong class="report-stat-value" id="statValue2">
                        $0.00
                    </strong>

                    <small class="report-stat-note" id="statNote2">
                        Acumulado
                    </small>
                </article>

                <article class="report-card report-stat">
                    <div class="report-stat-top">
                        <span class="report-stat-label" id="statLabel3">
                            Activas
                        </span>

                        <span class="report-stat-icon">
                            <i class="fas fa-circle-check"></i>
                        </span>
                    </div>

                    <strong class="report-stat-value" id="statValue3">
                        0
                    </strong>

                    <small class="report-stat-note" id="statNote3">
                        Inscripciones vigentes
                    </small>
                </article>

                <article class="report-card report-stat">
                    <div class="report-stat-top">
                        <span class="report-stat-label" id="statLabel4">
                            Por vencer
                        </span>

                        <span class="report-stat-icon">
                            <i class="fas fa-clock"></i>
                        </span>
                    </div>

                    <strong class="report-stat-value" id="statValue4">
                        0
                    </strong>

                    <small class="report-stat-note" id="statNote4">
                        Próximos 7 días
                    </small>
                </article>
            </section>

            <section class="report-card report-table-card">
                <div class="report-table-head">
                    <div>
                        <h2 id="tableTitle">
                            Detalle de inscripciones
                        </h2>

                        <p id="tableSubtitle">
                            Los datos mostrados respetan todos los filtros.
                        </p>
                    </div>

                    <div class="report-export-actions">
                        <button
                            type="button"
                            class="report-btn report-btn-excel"
                            id="exportExcel"
                            disabled
                        >
                            <i class="fas fa-file-excel"></i>
                            Excel
                        </button>

                        <button
                            type="button"
                            class="report-btn report-btn-pdf"
                            id="exportPdf"
                            disabled
                        >
                            <i class="fas fa-file-pdf"></i>
                            PDF
                        </button>
                    </div>
                </div>

                <div id="tableState">
                    <div class="report-loading">
                        <i class="fas fa-spinner"></i>
                        Preparando el reporte...
                    </div>
                </div>

                <div
                    class="report-table-wrap"
                    id="tableWrap"
                    hidden
                >
                    <table class="report-table">
                        <thead id="tableHead"></thead>
                        <tbody id="tableBody"></tbody>
                    </table>
                </div>

                <div
                    class="report-pagination-bar"
                    id="paginationBar"
                    hidden
                >
                    <div class="report-page-size">
                        <span>Mostrar</span>

                        <select id="pageSize">
                            <option value="10">10</option>
                            <option value="15" selected>15</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>

                        <span>por página</span>
                    </div>

                    <div
                        class="report-pagination-info"
                        id="paginationInfo"
                    ></div>

                    <nav
                        class="report-pagination"
                        id="pagination"
                    ></nav>
                </div>
            </section>
        </div>
    </div>
</main>

<script>
(function () {
    'use strict';

    const empresa = <?php
        echo json_encode(
            $empresa,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    ?>;

    const contexto = <?php
        echo json_encode(
            $contextoReportes,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    ?>;

    const usuario = {
        nombre: <?php
            echo json_encode(
                $usuarioNombre,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        ?>,
        rol: <?php
            echo json_encode(
                $usuarioRol,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        ?>
    };

    let reportType = 'inscripciones';
    let rows = [];
    let currentPage = 1;
    let pageSize = 15;
    let sortKey = 'fecha_inicio';
    let sortDirection = 'desc';
    let startDate = '';
    let endDate = '';
    let loading = false;
    let filterTimer = null;
    let requestController = null;
    let requestSequence = 0;

    const dom = {
        reportTypes: document.querySelectorAll('.report-type'),
        search: document.getElementById('searchInput'),
        plan: document.getElementById('planSelect'),
        method: document.getElementById('methodSelect'),
        status: document.getElementById('statusSelect'),
        dateRange: document.getElementById('dateRange'),
        clearDates: document.getElementById('clearDates'),
        quickRanges: document.querySelectorAll('.report-quick-btn'),
        clearFilters: document.getElementById('clearFilters'),
        applyFilters: document.getElementById('applyFilters'),
        inscriptionPlanField:
            document.getElementById('inscriptionPlanField'),
        salesMethodField:
            document.getElementById('salesMethodField'),
        filterCount: document.getElementById('filterCount'),
        liveStatus: document.getElementById('liveStatus'),
        tableState: document.getElementById('tableState'),
        tableWrap: document.getElementById('tableWrap'),
        tableHead: document.getElementById('tableHead'),
        tableBody: document.getElementById('tableBody'),
        tableTitle: document.getElementById('tableTitle'),
        tableSubtitle: document.getElementById('tableSubtitle'),
        paginationBar: document.getElementById('paginationBar'),
        paginationInfo: document.getElementById('paginationInfo'),
        pagination: document.getElementById('pagination'),
        pageSize: document.getElementById('pageSize'),
        exportExcel: document.getElementById('exportExcel'),
        exportPdf: document.getElementById('exportPdf')
    };

    const stats = [1, 2, 3, 4].map(function (index) {
        return {
            label: document.getElementById(
                'statLabel' + index
            ),
            value: document.getElementById(
                'statValue' + index
            ),
            note: document.getElementById(
                'statNote' + index
            )
        };
    });

    const statuses = {
        inscripciones: [
            ['', 'Todos los estados'],
            ['activa', 'Activa'],
            ['vencida', 'Vencida'],
            ['cancelada', 'Cancelada']
        ],
        ventas: [
            ['', 'Todos los estados'],
            ['completada', 'Completada'],
            ['cancelada', 'Cancelada'],
            ['pendiente', 'Pendiente']
        ]
    };

    const columnConfig = {
        inscripciones: [
            {
                key: 'cliente',
                label: 'Cliente'
            },
            {
                key: 'plan_nombre',
                label: 'Plan'
            },
            {
                key: 'fecha_inicio',
                label: 'Inicio'
            },
            {
                key: 'fecha_fin',
                label: 'Fin'
            },
            {
                key: 'dias_restantes',
                label: 'Vigencia'
            },
            {
                key: 'precio_pagado',
                label: 'Importe'
            },
            {
                key: 'estado',
                label: 'Estado'
            }
        ],
        ventas: [
            {
                key: 'id',
                label: 'Ticket'
            },
            {
                key: 'fecha_venta',
                label: 'Fecha'
            },
            {
                key: 'productos',
                label: 'Productos'
            },
            {
                key: 'cliente_nombre',
                label: 'Cliente'
            },
            {
                key: 'vendedor_nombre',
                label: 'Vendedor'
            },
            {
                key: 'metodo_pago',
                label: 'Método'
            },
            {
                key: 'total_bruto',
                label: 'Venta'
            },
            {
                key: 'devoluciones',
                label: 'Devoluciones'
            },
            {
                key: 'total_neto',
                label: 'Neto'
            },
            {
                key: 'estado',
                label: 'Estado'
            }
        ]
    };

    if (contexto.vista_global) {
        columnConfig.inscripciones.unshift({
            key: 'sucursal_nombre',
            label: 'Sucursal'
        });

        columnConfig.ventas.splice(2, 0, {
            key: 'sucursal_nombre',
            label: 'Sucursal'
        });
    }

    const datePicker = flatpickr(dom.dateRange, {
        mode: 'range',
        dateFormat: 'Y-m-d',
        locale: 'es',
        allowInput: false,
        showMonths: window.innerWidth >= 900 ? 2 : 1,
        maxDate: 'today',
        onChange: function (selectedDates) {
            clearQuickRangeActive();

            if (selectedDates.length === 2) {
                startDate = formatDateIso(selectedDates[0]);
                endDate = formatDateIso(selectedDates[1]);
                updateFilterCount();
                scheduleReportReload(80);
            } else if (selectedDates.length === 0) {
                startDate = '';
                endDate = '';
                updateFilterCount();
            }
        }
    });

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value === null ||
            value === undefined
            ? ''
            : String(value);

        return div.innerHTML;
    }

    function formatMoney(value) {
        return new Intl.NumberFormat('es-MX', {
            style: 'currency',
            currency: 'MXN'
        }).format(Number(value) || 0);
    }

    function formatNumber(value) {
        return new Intl.NumberFormat('es-MX').format(
            Number(value) || 0
        );
    }

    function formatDateIso(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1)
            .padStart(2, '0');
        const day = String(date.getDate())
            .padStart(2, '0');

        return year + '-' + month + '-' + day;
    }

    function formatDate(value, includeTime) {
        if (!value) {
            return '—';
        }

        const normalized = String(value).replace(' ', 'T');
        const date = new Date(normalized);

        if (Number.isNaN(date.getTime())) {
            const parts = String(value).split('-');

            if (parts.length >= 3) {
                return parts[2].substring(0, 2) +
                    '/' +
                    parts[1] +
                    '/' +
                    parts[0];
            }

            return value;
        }

        return new Intl.DateTimeFormat('es-MX', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: includeTime ? '2-digit' : undefined,
            minute: includeTime ? '2-digit' : undefined
        }).format(date);
    }

    function capitalize(value) {
        const text = String(value || '');

        return text.charAt(0).toUpperCase() +
            text.slice(1);
    }

    function renderStatus(status, type) {
        let cssClass = 'neutral';
        let icon = 'fa-circle';

        if (
            status === 'activa' ||
            status === 'completada'
        ) {
            cssClass = status === 'activa'
                ? 'active'
                : 'completed';
            icon = 'fa-circle-check';
        } else if (
            status === 'vencida' ||
            status === 'cancelada'
        ) {
            cssClass = status === 'vencida'
                ? 'expired'
                : 'cancelled';
            icon = 'fa-circle-xmark';
        } else if (status === 'pendiente') {
            cssClass = 'pending';
            icon = 'fa-clock';
        }

        return `
            <span class="report-pill ${cssClass}">
                <i class="fas ${icon}"></i>
                ${escapeHtml(capitalize(status))}
            </span>
        `;
    }

    function renderValidity(row) {
        if (row.estado === 'cancelada') {
            return `
                <span class="report-pill neutral">
                    No aplica
                </span>
            `;
        }

        const days = Number(row.dias_restantes);

        if (Number.isNaN(days)) {
            return `
                <span class="report-pill neutral">
                    Sin dato
                </span>
            `;
        }

        if (days < 0) {
            return `
                <span class="report-pill expired">
                    Vencida
                </span>
            `;
        }

        if (days === 0) {
            return `
                <span class="report-pill warning">
                    Vence hoy
                </span>
            `;
        }

        if (days <= 7) {
            return `
                <span class="report-pill warning">
                    ${days} días
                </span>
            `;
        }

        return `
            <span class="report-pill active">
                ${days} días
            </span>
        `;
    }

    function updateStatusOptions() {
        dom.status.innerHTML = statuses[reportType]
            .map(function (item) {
                return `
                    <option value="${item[0]}">
                        ${item[1]}
                    </option>
                `;
            })
            .join('');
    }

    function clearQuickRangeActive() {
        dom.quickRanges.forEach(function (button) {
            button.classList.remove('active');
        });
    }

    function setQuickRange(type, button) {
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        let start = new Date(today);
        let end = new Date(today);

        if (type === '7days') {
            start.setDate(today.getDate() - 6);
        } else if (type === 'month') {
            start = new Date(
                today.getFullYear(),
                today.getMonth(),
                1
            );
        } else if (type === 'previous') {
            start = new Date(
                today.getFullYear(),
                today.getMonth() - 1,
                1
            );

            end = new Date(
                today.getFullYear(),
                today.getMonth(),
                0
            );
        }

        startDate = formatDateIso(start);
        endDate = formatDateIso(end);

        datePicker.setDate([start, end], true);

        clearQuickRangeActive();

        if (button) {
            button.classList.add('active');
        }

        updateFilterCount();
        loadReport();
    }

    function setReportType(type) {
        if (type === reportType) {
            return;
        }

        reportType = type;

        dom.reportTypes.forEach(function (button) {
            button.classList.toggle(
                'active',
                button.dataset.type === type
            );
        });

        dom.inscriptionPlanField.hidden =
            type !== 'inscripciones';

        dom.salesMethodField.hidden =
            type !== 'ventas';

        dom.plan.value = '';
        dom.method.value = '';

        updateStatusOptions();

        sortKey = type === 'inscripciones'
            ? 'fecha_inicio'
            : 'fecha_venta';

        sortDirection = 'desc';
        currentPage = 1;

        updateReportCopy();
        updateFilterCount();
        loadReport();
    }

    function updateReportCopy() {
        if (reportType === 'inscripciones') {
            dom.search.placeholder =
                'Cliente, plan, teléfono, email o folio...';

            dom.tableTitle.textContent =
                'Detalle de inscripciones';

            dom.tableSubtitle.textContent =
                'Listado de membresías · ' + contexto.nombre + '.';
        } else {
            dom.search.placeholder =
                'Ticket, cliente, vendedor o producto...';

            dom.tableTitle.textContent =
                'Detalle de ventas de productos';

            dom.tableSubtitle.textContent =
                'Ventas, devoluciones y total neto · '
                + contexto.nombre
                + '.';
        }
    }

    function getFilterParams() {
        const params = new URLSearchParams({
            action: 'datos',
            tipo: reportType,
            vista: contexto.vista_global
                ? 'global'
                : 'sucursal'
        });

        const search = dom.search.value.trim();

        if (search) {
            params.set('search', search);
        }

        if (startDate) {
            params.set('fecha_inicio', startDate);
        }

        if (endDate) {
            params.set('fecha_fin', endDate);
        }

        if (dom.status.value) {
            params.set('estado', dom.status.value);
        }

        if (
            reportType === 'inscripciones' &&
            dom.plan.value
        ) {
            params.set('plan', dom.plan.value);
        }

        if (
            reportType === 'ventas' &&
            dom.method.value
        ) {
            params.set('metodo', dom.method.value);
        }

        return params;
    }

    function setLiveStatus(text, state) {
        const status = state || 'ready';

        dom.liveStatus.className =
            'report-live-status ' + status;

        dom.liveStatus.innerHTML = `
            <span class="report-live-dot"></span>
            ${escapeHtml(text)}
        `;
    }

    function scheduleReportReload(delay) {
        clearTimeout(filterTimer);

        filterTimer = setTimeout(function () {
            loadReport();
        }, typeof delay === 'number' ? delay : 320);
    }

    function updateFilterCount() {
        let count = 0;

        if (dom.search.value.trim()) {
            count++;
        }

        if (startDate || endDate) {
            count++;
        }

        if (dom.status.value) {
            count++;
        }

        if (
            reportType === 'inscripciones' &&
            dom.plan.value
        ) {
            count++;
        }

        if (
            reportType === 'ventas' &&
            dom.method.value
        ) {
            count++;
        }

        dom.filterCount.innerHTML = count > 0
            ? `
                <i class="fas fa-filter"></i>
                ${count} filtro${count === 1 ? '' : 's'}
              `
            : `
                <i class="fas fa-filter"></i>
                Sin filtros
              `;
    }

    function setLoadingState() {
        loading = true;
        dom.applyFilters.disabled = true;
        dom.exportExcel.disabled = true;
        dom.exportPdf.disabled = true;
        dom.tableWrap.classList.add('is-updating');
        setLiveStatus('Actualizando datos...', 'loading');

        if (rows.length === 0) {
            dom.tableWrap.hidden = true;
            dom.paginationBar.hidden = true;
            dom.tableState.innerHTML = `
                <div class="report-loading">
                    <i class="fas fa-spinner"></i>
                    Consultando la información...
                </div>
            `;
        }
    }

    function setEmptyState() {
        dom.tableWrap.hidden = true;
        dom.paginationBar.hidden = true;
        dom.tableState.innerHTML = `
            <div class="report-empty">
                <i class="fas fa-folder-open"></i>
                <strong>Sin resultados</strong>
                <p>
                    No hay registros que coincidan con los filtros actuales.
                </p>
            </div>
        `;
    }

    function setErrorState(message) {
        dom.tableWrap.hidden = true;
        dom.paginationBar.hidden = true;
        dom.tableState.innerHTML = `
            <div class="report-empty">
                <i class="fas fa-triangle-exclamation"></i>
                <strong>No fue posible cargar el reporte</strong>
                <p>${escapeHtml(message)}</p>
            </div>
        `;
    }

    async function loadReport() {
        clearTimeout(filterTimer);
        updateFilterCount();

        if (requestController) {
            requestController.abort();
        }

        requestController = new AbortController();

        const currentRequest = ++requestSequence;
        const signal = requestController.signal;

        setLoadingState();

        try {
            const response = await fetch(
                'reportes.php?' + getFilterParams().toString(),
                {
                    headers: {
                        'Accept': 'application/json'
                    },
                    signal: signal
                }
            );

            const data = await response.json();

            if (currentRequest !== requestSequence) {
                return;
            }

            if (!response.ok || !data.success) {
                throw new Error(
                    data.message ||
                    'No se pudo obtener la información.'
                );
            }

            rows = Array.isArray(data.datos)
                ? data.datos
                : [];

            currentPage = 1;

            updateStats();
            renderTable();

            const updatedAt = new Intl.DateTimeFormat(
                'es-MX',
                {
                    hour: '2-digit',
                    minute: '2-digit'
                }
            ).format(new Date());

            setLiveStatus(
                rows.length +
                ' registros · Actualizado ' +
                updatedAt,
                'ready'
            );

            if (data.limitado) {
                Swal.fire({
                    icon: 'info',
                    title: 'Límite de resultados',
                    text:
                        'Se muestran los primeros 5,000 registros. ' +
                        'Selecciona un periodo más específico.',
                    confirmButtonColor: '#2f66b3'
                });
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }

            if (currentRequest !== requestSequence) {
                return;
            }

            rows = [];
            updateStats();
            setErrorState(error.message);
            setLiveStatus('Error al actualizar', 'error');

            Swal.fire({
                icon: 'error',
                title: 'No se pudo cargar el reporte',
                text: error.message,
                confirmButtonColor: '#2f66b3'
            });
        } finally {
            if (currentRequest === requestSequence) {
                loading = false;
                dom.applyFilters.disabled = false;
                dom.tableWrap.classList.remove('is-updating');

                const hasRows = rows.length > 0;

                dom.exportExcel.disabled = !hasRows;
                dom.exportPdf.disabled = !hasRows;
            }
        }
    }

    function updateStats() {
        if (reportType === 'inscripciones') {
            const total = rows.length;

            const income = rows.reduce(function (sum, row) {
                return sum + Number(row.precio_pagado || 0);
            }, 0);

            const active = rows.filter(function (row) {
                return row.estado === 'activa';
            }).length;

            const expiring = rows.filter(function (row) {
                const days = Number(row.dias_restantes);

                return row.estado === 'activa' &&
                    !Number.isNaN(days) &&
                    days >= 0 &&
                    days <= 7;
            }).length;

            const values = [
                {
                    label: 'Inscripciones',
                    value: formatNumber(total),
                    note: 'Registros encontrados'
                },
                {
                    label: 'Ingresos',
                    value: formatMoney(income),
                    note: 'Importe acumulado'
                },
                {
                    label: 'Activas',
                    value: formatNumber(active),
                    note: 'Membresías vigentes'
                },
                {
                    label: 'Por vencer',
                    value: formatNumber(expiring),
                    note: 'Dentro de 7 días'
                }
            ];

            applyStats(values);
        } else {
            const transactions = rows.length;

            const units = rows.reduce(function (sum, row) {
                return sum + Number(row.unidades || 0);
            }, 0);

            const gross = rows.reduce(function (sum, row) {
                return sum + Number(row.total_bruto || 0);
            }, 0);

            const returns = rows.reduce(function (sum, row) {
                return sum + Number(row.devoluciones || 0);
            }, 0);

            const net = rows.reduce(function (sum, row) {
                return sum + Number(row.total_neto || 0);
            }, 0);

            const values = [
                {
                    label: 'Ventas',
                    value: formatNumber(transactions),
                    note: 'Operaciones encontradas'
                },
                {
                    label: 'Unidades',
                    value: formatNumber(units),
                    note: 'Artículos vendidos'
                },
                {
                    label: 'Venta bruta',
                    value: formatMoney(gross),
                    note: 'Antes de devoluciones'
                },
                {
                    label: 'Ingreso neto',
                    value: formatMoney(net),
                    note: 'Devoluciones: ' + formatMoney(returns)
                }
            ];

            applyStats(values);
        }
    }

    function applyStats(values) {
        values.forEach(function (item, index) {
            stats[index].label.textContent = item.label;
            stats[index].value.textContent = item.value;
            stats[index].note.textContent = item.note;
        });
    }

    function getSortValue(row, key) {
        if (key === 'cliente') {
            return (
                String(row.cliente_nombre || '') +
                ' ' +
                String(row.cliente_apellido || '')
            ).toLowerCase();
        }

        const value = row[key];

        if (
            [
                'precio_pagado',
                'dias_restantes',
                'id',
                'total_bruto',
                'devoluciones',
                'total_neto',
                'unidades'
            ].includes(key)
        ) {
            return Number(value || 0);
        }

        return String(value || '').toLowerCase();
    }

    function getSortedRows() {
        return rows.slice().sort(function (a, b) {
            const valueA = getSortValue(a, sortKey);
            const valueB = getSortValue(b, sortKey);

            if (valueA < valueB) {
                return sortDirection === 'asc' ? -1 : 1;
            }

            if (valueA > valueB) {
                return sortDirection === 'asc' ? 1 : -1;
            }

            return 0;
        });
    }

    function renderTableHead() {
        const columns = columnConfig[reportType];

        dom.tableHead.innerHTML = `
            <tr>
                ${columns.map(function (column) {
                    const active = sortKey === column.key;
                    const icon = !active
                        ? 'fa-sort'
                        : (
                            sortDirection === 'asc'
                                ? 'fa-sort-up'
                                : 'fa-sort-down'
                        );

                    return `
                        <th data-sort="${column.key}">
                            ${column.label}
                            <i class="fas ${icon}"></i>
                        </th>
                    `;
                }).join('')}
            </tr>
        `;

        dom.tableHead
            .querySelectorAll('[data-sort]')
            .forEach(function (header) {
                header.addEventListener('click', function () {
                    const key = header.dataset.sort;

                    if (sortKey === key) {
                        sortDirection =
                            sortDirection === 'asc'
                                ? 'desc'
                                : 'asc';
                    } else {
                        sortKey = key;
                        sortDirection = 'asc';
                    }

                    currentPage = 1;
                    renderTable();
                });
            });
    }

    function renderBranchCell(row) {
        if (!contexto.vista_global) {
            return '';
        }

        return `
            <td class="report-branch-cell">
                <span class="report-branch-name">
                    ${escapeHtml(row.sucursal_nombre || 'Sucursal')}
                </span>
                <span class="report-branch-key">
                    ${escapeHtml(row.sucursal_clave || 'SIN CLAVE')}
                </span>
            </td>
        `;
    }

    function renderInscriptionRow(row) {
        const client = [
            row.cliente_nombre,
            row.cliente_apellido
        ].filter(Boolean).join(' ');

        const contact = row.telefono ||
            row.email ||
            'Sin contacto';

        return `
            <tr>
                ${renderBranchCell(row)}
                <td>
                    <span class="report-primary-cell">
                        ${escapeHtml(client)}
                    </span>
                    <span class="report-secondary-cell">
                        ${escapeHtml(contact)}
                    </span>
                </td>
                <td>
                    <span class="report-pill neutral">
                        ${escapeHtml(row.plan_nombre)}
                    </span>
                </td>
                <td>${formatDate(row.fecha_inicio, false)}</td>
                <td>${formatDate(row.fecha_fin, false)}</td>
                <td>${renderValidity(row)}</td>
                <td class="report-money positive">
                    ${formatMoney(row.precio_pagado)}
                </td>
                <td>${renderStatus(row.estado, 'inscripcion')}</td>
            </tr>
        `;
    }

    function renderSaleRow(row) {
        return `
            <tr>
                <td>
                    <span class="report-primary-cell">
                        #${String(row.id).padStart(8, '0')}
                    </span>
                    <span class="report-secondary-cell">
                        ${Number(row.unidades || 0)} unidades
                    </span>
                </td>
                <td>${formatDate(row.fecha_venta, true)}</td>
                ${renderBranchCell(row)}
                <td class="report-products">
                    <span
                        class="report-products-text"
                        title="${escapeHtml(row.productos)}"
                    >
                        ${escapeHtml(row.productos)}
                    </span>
                </td>
                <td>${escapeHtml(row.cliente_nombre)}</td>
                <td>${escapeHtml(row.vendedor_nombre)}</td>
                <td>
                    <span class="report-pill neutral">
                        ${escapeHtml(capitalize(row.metodo_pago))}
                    </span>
                </td>
                <td class="report-money">
                    ${formatMoney(row.total_bruto)}
                </td>
                <td class="report-money negative">
                    ${formatMoney(row.devoluciones)}
                </td>
                <td class="report-money positive">
                    ${formatMoney(row.total_neto)}
                </td>
                <td>${renderStatus(row.estado, 'venta')}</td>
            </tr>
        `;
    }

    function renderTable() {
        if (rows.length === 0) {
            setEmptyState();
            return;
        }

        const sortedRows = getSortedRows();
        const totalPages = Math.max(
            1,
            Math.ceil(sortedRows.length / pageSize)
        );

        currentPage = Math.min(currentPage, totalPages);

        const start = (currentPage - 1) * pageSize;
        const pageRows = sortedRows.slice(
            start,
            start + pageSize
        );

        renderTableHead();

        dom.tableBody.innerHTML = pageRows
            .map(function (row) {
                return reportType === 'inscripciones'
                    ? renderInscriptionRow(row)
                    : renderSaleRow(row);
            })
            .join('');

        dom.tableState.innerHTML = '';
        dom.tableWrap.hidden = false;
        dom.paginationBar.hidden = false;

        renderPagination(totalPages, sortedRows.length);
    }

    function renderPagination(totalPages, totalRows) {
        const startRecord =
            (currentPage - 1) * pageSize + 1;

        const endRecord = Math.min(
            currentPage * pageSize,
            totalRows
        );

        dom.paginationInfo.textContent =
            'Mostrando ' +
            startRecord +
            '–' +
            endRecord +
            ' de ' +
            totalRows +
            ' registros';

        const pages = [];

        pages.push({
            label: '<i class="fas fa-chevron-left"></i>',
            page: currentPage - 1,
            disabled: currentPage === 1
        });

        let first = Math.max(1, currentPage - 2);
        let last = Math.min(totalPages, currentPage + 2);

        if (first > 1) {
            pages.push({
                label: '1',
                page: 1
            });

            if (first > 2) {
                pages.push({
                    label: '…',
                    disabled: true
                });
            }
        }

        for (let page = first; page <= last; page++) {
            pages.push({
                label: String(page),
                page: page,
                active: page === currentPage
            });
        }

        if (last < totalPages) {
            if (last < totalPages - 1) {
                pages.push({
                    label: '…',
                    disabled: true
                });
            }

            pages.push({
                label: String(totalPages),
                page: totalPages
            });
        }

        pages.push({
            label: '<i class="fas fa-chevron-right"></i>',
            page: currentPage + 1,
            disabled: currentPage === totalPages
        });

        dom.pagination.innerHTML = pages
            .map(function (item) {
                return `
                    <button
                        type="button"
                        class="report-page-btn ${
                            item.active ? 'active' : ''
                        }"
                        data-page="${item.page || ''}"
                        ${item.disabled ? 'disabled' : ''}
                    >
                        ${item.label}
                    </button>
                `;
            })
            .join('');

        dom.pagination
            .querySelectorAll('[data-page]')
            .forEach(function (button) {
                button.addEventListener('click', function () {
                    const page = Number(button.dataset.page);

                    if (
                        page >= 1 &&
                        page <= totalPages
                    ) {
                        currentPage = page;
                        renderTable();

                        dom.tableWrap.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                });
            });
    }

    function clearFilters() {
        dom.search.value = '';
        dom.plan.value = '';
        dom.method.value = '';
        dom.status.value = '';
        startDate = '';
        endDate = '';
        datePicker.clear();
        clearQuickRangeActive();
        currentPage = 1;
        updateFilterCount();
        loadReport();
    }

    function getFiltersDescription() {
        const filters = [
            'Vista: ' + contexto.nombre
        ];

        if (dom.search.value.trim()) {
            filters.push('Búsqueda: ' + dom.search.value.trim());
        }

        if (startDate || endDate) {
            filters.push(
                'Periodo: ' +
                (startDate || 'Inicio') +
                ' a ' +
                (endDate || 'Hoy')
            );
        }

        if (dom.status.value) {
            filters.push(
                'Estado: ' +
                dom.status.options[
                    dom.status.selectedIndex
                ].text
            );
        }

        if (
            reportType === 'inscripciones' &&
            dom.plan.value
        ) {
            filters.push(
                'Plan: ' +
                dom.plan.options[
                    dom.plan.selectedIndex
                ].text
            );
        }

        if (
            reportType === 'ventas' &&
            dom.method.value
        ) {
            filters.push(
                'Método: ' +
                dom.method.options[
                    dom.method.selectedIndex
                ].text
            );
        }

        return filters.length > 0
            ? filters.join(' · ')
            : 'Sin filtros adicionales';
    }

    function getReportName() {
        const base = reportType === 'inscripciones'
            ? 'Reporte de Inscripciones'
            : 'Reporte de Ventas de Productos';

        return base + ' · ' + contexto.nombre;
    }

    function getExportRows() {
        if (reportType === 'inscripciones') {
            return rows.map(function (row) {
                return {
                    Folio: row.id,
                    Sucursal:
                        (row.sucursal_nombre || 'Sucursal')
                        + ' · '
                        + (row.sucursal_clave || 'SIN CLAVE'),
                    Cliente: [
                        row.cliente_nombre,
                        row.cliente_apellido
                    ].filter(Boolean).join(' '),
                    Teléfono: row.telefono || '',
                    Email: row.email || '',
                    Plan: row.plan_nombre || '',
                    'Fecha inicio': row.fecha_inicio || '',
                    'Fecha fin': row.fecha_fin || '',
                    'Días restantes':
                        row.dias_restantes !== null
                            ? row.dias_restantes
                            : '',
                    Importe: Number(row.precio_pagado || 0),
                    Estado: capitalize(row.estado)
                };
            });
        }

        return rows.map(function (row) {
            return {
                Ticket: '#' + String(row.id).padStart(8, '0'),
                Fecha: row.fecha_venta || '',
                Sucursal:
                    (row.sucursal_nombre || 'Sucursal')
                    + ' · '
                    + (row.sucursal_clave || 'SIN CLAVE'),
                Productos: row.productos || '',
                Unidades: Number(row.unidades || 0),
                Cliente: row.cliente_nombre || '',
                Vendedor: row.vendedor_nombre || '',
                Método: capitalize(row.metodo_pago),
                'Venta bruta': Number(row.total_bruto || 0),
                Devoluciones: Number(row.devoluciones || 0),
                'Ingreso neto': Number(row.total_neto || 0),
                Estado: capitalize(row.estado)
            };
        });
    }

    function getStatsForExport() {
        return stats.map(function (item) {
            return [
                item.label.textContent,
                item.value.textContent
            ];
        });
    }

    function exportExcel() {
        if (rows.length === 0) {
            return;
        }

        try {
            const workbook = XLSX.utils.book_new();
            const generated = new Date().toLocaleString('es-MX');
            const reportRows = getExportRows();
            const statsRows = getStatsForExport();

            workbook.Props = {
                Title: getReportName(),
                Subject: getFiltersDescription(),
                Author: empresa.nombre || 'Gimnasio',
                Company: empresa.nombre || 'Gimnasio',
                CreatedDate: new Date()
            };

            const summary = [
                [empresa.nombre || 'Gimnasio'],
                [getReportName()],
                [],
                ['INFORMACIÓN DEL REPORTE', ''],
                ['Fecha de generación', generated],
                ['Responsable', usuario.nombre],
                ['Perfil', capitalize(usuario.rol)],
                ['Vista', contexto.nombre],
                ['Contexto', contexto.detalle],
                ['Filtros aplicados', getFiltersDescription()],
                ['Total de registros', rows.length],
                [],
                ['RESUMEN', 'VALOR']
            ].concat(statsRows);

            const summarySheet =
                XLSX.utils.aoa_to_sheet(summary);

            summarySheet['!cols'] = [
                { wch: 30 },
                { wch: 62 }
            ];

            summarySheet['!merges'] = [
                {
                    s: { r: 0, c: 0 },
                    e: { r: 0, c: 1 }
                },
                {
                    s: { r: 1, c: 0 },
                    e: { r: 1, c: 1 }
                }
            ];

            XLSX.utils.book_append_sheet(
                workbook,
                summarySheet,
                'Resumen'
            );

            const detailSheet =
                XLSX.utils.json_to_sheet(reportRows);

            detailSheet['!cols'] =
                reportType === 'inscripciones'
                    ? [
                        { wch: 10 },
                        { wch: 28 },
                        { wch: 30 },
                        { wch: 16 },
                        { wch: 32 },
                        { wch: 22 },
                        { wch: 14 },
                        { wch: 14 },
                        { wch: 16 },
                        { wch: 14 },
                        { wch: 14 }
                    ]
                    : [
                        { wch: 15 },
                        { wch: 20 },
                        { wch: 28 },
                        { wch: 52 },
                        { wch: 11 },
                        { wch: 27 },
                        { wch: 24 },
                        { wch: 15 },
                        { wch: 16 },
                        { wch: 16 },
                        { wch: 16 },
                        { wch: 14 }
                    ];

            if (detailSheet['!ref']) {
                detailSheet['!autofilter'] = {
                    ref: detailSheet['!ref']
                };
            }

            const detailRange = XLSX.utils.decode_range(
                detailSheet['!ref']
            );

            const currencyHeaders =
                reportType === 'inscripciones'
                    ? ['Importe']
                    : [
                        'Venta bruta',
                        'Devoluciones',
                        'Ingreso neto'
                    ];

            const headerMap = {};

            for (
                let column = detailRange.s.c;
                column <= detailRange.e.c;
                column++
            ) {
                const address = XLSX.utils.encode_cell({
                    r: 0,
                    c: column
                });

                if (detailSheet[address]) {
                    headerMap[
                        String(detailSheet[address].v)
                    ] = column;
                }
            }

            currencyHeaders.forEach(function (header) {
                const column = headerMap[header];

                if (column === undefined) {
                    return;
                }

                for (
                    let row = 1;
                    row <= detailRange.e.r;
                    row++
                ) {
                    const address = XLSX.utils.encode_cell({
                        r: row,
                        c: column
                    });

                    if (detailSheet[address]) {
                        detailSheet[address].z =
                            '$#,##0.00';
                    }
                }
            });

            XLSX.utils.book_append_sheet(
                workbook,
                detailSheet,
                reportType === 'inscripciones'
                    ? 'Inscripciones'
                    : 'Ventas'
            );

            const analysisMap = new Map();

            rows.forEach(function (row) {
                const key = reportType === 'inscripciones'
                    ? row.plan_nombre
                    : capitalize(row.metodo_pago);

                if (!analysisMap.has(key)) {
                    analysisMap.set(key, {
                        registros: 0,
                        importe: 0
                    });
                }

                const current = analysisMap.get(key);
                current.registros++;

                current.importe += Number(
                    reportType === 'inscripciones'
                        ? row.precio_pagado
                        : row.total_neto
                ) || 0;
            });

            const analysis = [
                [
                    reportType === 'inscripciones'
                        ? 'Plan'
                        : 'Método de pago',
                    'Registros',
                    'Importe'
                ]
            ];

            let analysisTotal = 0;
            let analysisCount = 0;

            analysisMap.forEach(function (value, key) {
                analysis.push([
                    key,
                    value.registros,
                    value.importe
                ]);

                analysisCount += value.registros;
                analysisTotal += value.importe;
            });

            analysis.push([]);
            analysis.push([
                'TOTAL',
                analysisCount,
                analysisTotal
            ]);

            const analysisSheet =
                XLSX.utils.aoa_to_sheet(analysis);

            analysisSheet['!cols'] = [
                { wch: 30 },
                { wch: 14 },
                { wch: 19 }
            ];

            const analysisRange =
                XLSX.utils.decode_range(
                    analysisSheet['!ref']
                );

            for (
                let row = 1;
                row <= analysisRange.e.r;
                row++
            ) {
                const address = XLSX.utils.encode_cell({
                    r: row,
                    c: 2
                });

                if (analysisSheet[address]) {
                    analysisSheet[address].z =
                        '$#,##0.00';
                }
            }

            XLSX.utils.book_append_sheet(
                workbook,
                analysisSheet,
                'Análisis'
            );

            if (contexto.vista_global) {
                const branchMap = new Map();

                rows.forEach(function (row) {
                    const key =
                        (row.sucursal_nombre || 'Sucursal')
                        + ' · '
                        + (row.sucursal_clave || 'SIN CLAVE');

                    if (!branchMap.has(key)) {
                        branchMap.set(key, {
                            registros: 0,
                            importe: 0
                        });
                    }

                    const current =
                        branchMap.get(key);

                    current.registros++;
                    current.importe += Number(
                        reportType === 'inscripciones'
                            ? row.precio_pagado
                            : row.total_neto
                    ) || 0;
                });

                const branchRows = [
                    ['Sucursal', 'Registros', 'Importe']
                ];

                let branchTotalRecords = 0;
                let branchTotalAmount = 0;

                branchMap.forEach(function (value, key) {
                    branchRows.push([
                        key,
                        value.registros,
                        value.importe
                    ]);

                    branchTotalRecords += value.registros;
                    branchTotalAmount += value.importe;
                });

                branchRows.push([]);
                branchRows.push([
                    'TOTAL',
                    branchTotalRecords,
                    branchTotalAmount
                ]);

                const branchSheet =
                    XLSX.utils.aoa_to_sheet(branchRows);

                branchSheet['!cols'] = [
                    { wch: 34 },
                    { wch: 14 },
                    { wch: 19 }
                ];

                const branchRange =
                    XLSX.utils.decode_range(
                        branchSheet['!ref']
                    );

                for (
                    let row = 1;
                    row <= branchRange.e.r;
                    row++
                ) {
                    const address =
                        XLSX.utils.encode_cell({
                            r: row,
                            c: 2
                        });

                    if (branchSheet[address]) {
                        branchSheet[address].z =
                            '$#,##0.00';
                    }
                }

                XLSX.utils.book_append_sheet(
                    workbook,
                    branchSheet,
                    'Sucursales'
                );
            }

            const now = new Date();
            const stamp =
                formatDateIso(now).replace(/-/g, '') +
                '_' +
                String(now.getHours()).padStart(2, '0') +
                String(now.getMinutes()).padStart(2, '0');

            const fileName =
                (
                    reportType === 'inscripciones'
                        ? 'Reporte_Inscripciones_'
                        : 'Reporte_Ventas_'
                ) +
                stamp +
                '.xlsx';

            XLSX.writeFile(workbook, fileName);

            Swal.fire({
                icon: 'success',
                title: 'Reporte descargado',
                text:
                    rows.length +
                    ' registros incluidos en el archivo Excel.',
                timer: 2300,
                showConfirmButton: false
            });
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'No se pudo generar Excel',
                text: error.message,
                confirmButtonColor: '#2f66b3'
            });
        }
    }

    async function imageToDataUrl(url) {
        if (!url) {
            return null;
        }

        return new Promise(function (resolve) {
            const image = new Image();
            image.crossOrigin = 'anonymous';
            image.onload = function () {
                try {
                    const canvas =
                        document.createElement('canvas');

                    canvas.width = image.naturalWidth;
                    canvas.height = image.naturalHeight;

                    const context =
                        canvas.getContext('2d');

                    context.drawImage(image, 0, 0);

                    resolve(
                        canvas.toDataURL('image/png')
                    );
                } catch (error) {
                    resolve(null);
                }
            };

            image.onerror = function () {
                resolve(null);
            };

            image.src =
                url +
                (url.includes('?') ? '&' : '?') +
                'v=' +
                Date.now();
        });
    }

    async function exportPdf() {
        if (rows.length === 0) {
            return;
        }

        Swal.fire({
            title: 'Generando PDF',
            text: 'Preparando el documento...',
            allowOutsideClick: false,
            didOpen: function () {
                Swal.showLoading();
            }
        });

        try {
            const jsPDF = window.jspdf.jsPDF;
            const doc = new jsPDF({
                orientation: 'landscape',
                unit: 'mm',
                format: 'a4'
            });

            const pageWidth =
                doc.internal.pageSize.getWidth();

            const pageHeight =
                doc.internal.pageSize.getHeight();

            const navy = [30, 49, 71];
            const gray = [93, 107, 124];
            const light = [245, 247, 249];
            const line = [214, 222, 231];
            const logo = await imageToDataUrl(
                empresa.logo
            );

            doc.setFillColor(
                navy[0],
                navy[1],
                navy[2]
            );

            doc.rect(0, 0, pageWidth, 5, 'F');

            if (logo) {
                doc.addImage(
                    logo,
                    'PNG',
                    12,
                    11,
                    18,
                    18
                );
            }

            const titleX = logo ? 36 : 12;

            doc.setTextColor(
                navy[0],
                navy[1],
                navy[2]
            );

            doc.setFont('helvetica', 'bold');
            doc.setFontSize(14);
            doc.text(
                empresa.nombre || 'Gimnasio',
                titleX,
                15
            );

            doc.setFont('helvetica', 'normal');
            doc.setFontSize(7.3);
            doc.setTextColor(
                gray[0],
                gray[1],
                gray[2]
            );

            const contact = [
                empresa.direccion,
                empresa.telefono
                    ? 'Tel. ' + empresa.telefono
                    : '',
                empresa.email
            ].filter(Boolean).join(' · ');

            doc.text(
                contact || 'Sistema de administración',
                titleX,
                21
            );

            doc.setTextColor(
                navy[0],
                navy[1],
                navy[2]
            );

            doc.setFont('helvetica', 'bold');
            doc.setFontSize(12);
            doc.text(
                getReportName(),
                pageWidth - 12,
                14,
                {
                    align: 'right'
                }
            );

            doc.setFont('helvetica', 'normal');
            doc.setFontSize(7.3);
            doc.setTextColor(
                gray[0],
                gray[1],
                gray[2]
            );

            doc.text(
                'Generado: ' +
                new Date().toLocaleString('es-MX'),
                pageWidth - 12,
                20,
                {
                    align: 'right'
                }
            );

            doc.text(
                'Responsable: ' +
                usuario.nombre +
                ' · ' +
                capitalize(usuario.rol),
                pageWidth - 12,
                25,
                {
                    align: 'right'
                }
            );

            doc.setDrawColor(
                line[0],
                line[1],
                line[2]
            );

            doc.line(12, 34, pageWidth - 12, 34);

            const statData = getStatsForExport();
            const infoRows = [
                [
                    'Vista',
                    contexto.nombre
                    + ' · '
                    + contexto.detalle
                ],
                [
                    'Periodo',
                    startDate || endDate
                        ? (
                            (startDate || 'Inicio') +
                            ' al ' +
                            (endDate || 'Hoy')
                        )
                        : 'Todo el historial'
                ],
                [
                    'Registros',
                    String(rows.length)
                ],
                [
                    'Filtros',
                    getFiltersDescription()
                ]
            ];

            doc.autoTable({
                body: infoRows,
                startY: 39,
                margin: {
                    left: 12,
                    right: 12
                },
                theme: 'plain',
                tableWidth: pageWidth - 24,
                styles: {
                    font: 'helvetica',
                    fontSize: 7.2,
                    cellPadding: 2.3,
                    textColor: gray,
                    valign: 'top'
                },
                columnStyles: {
                    0: {
                        cellWidth: 23,
                        fontStyle: 'bold',
                        textColor: navy
                    }
                },
                didParseCell: function (data) {
                    if (data.row.index % 2 === 0) {
                        data.cell.styles.fillColor = light;
                    }
                }
            });

            const summaryY =
                doc.lastAutoTable.finalY + 5;

            const gap = 4;
            const summaryWidth =
                (pageWidth - 24 - gap * 3) / 4;

            statData.forEach(function (item, index) {
                const x =
                    12 +
                    index * (summaryWidth + gap);

                doc.setFillColor(
                    light[0],
                    light[1],
                    light[2]
                );

                doc.setDrawColor(
                    line[0],
                    line[1],
                    line[2]
                );

                doc.roundedRect(
                    x,
                    summaryY,
                    summaryWidth,
                    17,
                    1.5,
                    1.5,
                    'FD'
                );

                doc.setTextColor(
                    gray[0],
                    gray[1],
                    gray[2]
                );

                doc.setFont('helvetica', 'normal');
                doc.setFontSize(6.3);
                doc.text(
                    String(item[0]).toUpperCase(),
                    x + 3,
                    summaryY + 6
                );

                doc.setTextColor(
                    navy[0],
                    navy[1],
                    navy[2]
                );

                doc.setFont('helvetica', 'bold');
                doc.setFontSize(10);
                doc.text(
                    String(item[1]),
                    x + 3,
                    summaryY + 13
                );
            });

            const exportRows = getExportRows();
            let headers;
            let body;
            let foot;
            let columnStyles;

            if (reportType === 'inscripciones') {
                const totalIncome = rows.reduce(
                    function (sum, row) {
                        return sum +
                            Number(row.precio_pagado || 0);
                    },
                    0
                );

                headers = [
                    'Folio',
                    'Sucursal',
                    'Cliente',
                    'Teléfono',
                    'Plan',
                    'Inicio',
                    'Fin',
                    'Días',
                    'Importe',
                    'Estado'
                ];

                body = exportRows.map(function (row) {
                    return [
                        row.Folio,
                        row.Sucursal,
                        row.Cliente,
                        row.Teléfono,
                        row.Plan,
                        row['Fecha inicio'],
                        row['Fecha fin'],
                        row['Días restantes'],
                        formatMoney(row.Importe),
                        row.Estado
                    ];
                });

                foot = [[
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    'TOTAL',
                    formatMoney(totalIncome),
                    ''
                ]];

                columnStyles = {
                    1: {
                        cellWidth: 28
                    },
                    2: {
                        cellWidth: 34
                    },
                    4: {
                        cellWidth: 25
                    },
                    8: {
                        halign: 'right'
                    }
                };
            } else {
                const totals = rows.reduce(
                    function (result, row) {
                        result.gross +=
                            Number(row.total_bruto || 0);

                        result.returns +=
                            Number(row.devoluciones || 0);

                        result.net +=
                            Number(row.total_neto || 0);

                        return result;
                    },
                    {
                        gross: 0,
                        returns: 0,
                        net: 0
                    }
                );

                headers = [
                    'Ticket',
                    'Fecha',
                    'Sucursal',
                    'Productos',
                    'Unid.',
                    'Cliente',
                    'Método',
                    'Venta',
                    'Dev.',
                    'Neto',
                    'Estado'
                ];

                body = exportRows.map(function (row) {
                    return [
                        row.Ticket,
                        row.Fecha,
                        row.Sucursal,
                        row.Productos,
                        row.Unidades,
                        row.Cliente,
                        row.Método,
                        formatMoney(row['Venta bruta']),
                        formatMoney(row.Devoluciones),
                        formatMoney(row['Ingreso neto']),
                        row.Estado
                    ];
                });

                foot = [[
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    'TOTALES',
                    formatMoney(totals.gross),
                    formatMoney(totals.returns),
                    formatMoney(totals.net),
                    ''
                ]];

                columnStyles = {
                    2: {
                        cellWidth: 27
                    },
                    3: {
                        cellWidth: 45
                    },
                    5: {
                        cellWidth: 27
                    },
                    7: {
                        halign: 'right'
                    },
                    8: {
                        halign: 'right'
                    },
                    9: {
                        halign: 'right'
                    }
                };
            }

            doc.autoTable({
                head: [headers],
                body: body,
                foot: foot,
                startY: summaryY + 23,
                margin: {
                    left: 12,
                    right: 12,
                    bottom: 14
                },
                theme: 'grid',
                showFoot: 'lastPage',
                styles: {
                    font: 'helvetica',
                    fontSize:
                        reportType === 'ventas'
                            ? 5.75
                            : 6.15,
                    cellPadding: 2.25,
                    lineColor: line,
                    lineWidth: 0.1,
                    textColor: [54, 66, 81],
                    valign: 'middle',
                    overflow: 'linebreak'
                },
                headStyles: {
                    fillColor: navy,
                    textColor: [255, 255, 255],
                    fontStyle: 'bold',
                    halign: 'center'
                },
                footStyles: {
                    fillColor: [235, 239, 243],
                    textColor: navy,
                    fontStyle: 'bold'
                },
                alternateRowStyles: {
                    fillColor: [248, 249, 251]
                },
                columnStyles: columnStyles,
                didDrawPage: function () {
                    const current =
                        doc.internal.getCurrentPageInfo()
                            .pageNumber;

                    doc.setDrawColor(
                        line[0],
                        line[1],
                        line[2]
                    );

                    doc.line(
                        12,
                        pageHeight - 10,
                        pageWidth - 12,
                        pageHeight - 10
                    );

                    doc.setTextColor(
                        gray[0],
                        gray[1],
                        gray[2]
                    );

                    doc.setFontSize(6.8);
                    doc.setFont('helvetica', 'normal');

                    doc.text(
                        (empresa.nombre || 'Gimnasio')
                        + ' · '
                        + contexto.nombre,
                        12,
                        pageHeight - 5.3
                    );

                    doc.text(
                        'Página ' +
                        current +
                        ' · ' +
                        rows.length +
                        ' registros',
                        pageWidth - 12,
                        pageHeight - 5.3,
                        {
                            align: 'right'
                        }
                    );
                }
            });

            const now = new Date();
            const stamp =
                formatDateIso(now).replace(/-/g, '') +
                '_' +
                String(now.getHours()).padStart(2, '0') +
                String(now.getMinutes()).padStart(2, '0');

            const fileName =
                (
                    reportType === 'inscripciones'
                        ? 'Reporte_Inscripciones_'
                        : 'Reporte_Ventas_'
                ) +
                stamp +
                '.pdf';

            doc.save(fileName);

            Swal.fire({
                icon: 'success',
                title: 'Reporte descargado',
                text:
                    rows.length +
                    ' registros incluidos en el archivo PDF.',
                timer: 2300,
                showConfirmButton: false
            });
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'No se pudo generar PDF',
                text: error.message,
                confirmButtonColor: '#2f66b3'
            });
        }
    }

    dom.reportTypes.forEach(function (button) {
        button.addEventListener('click', function () {
            setReportType(button.dataset.type);
        });
    });

    dom.quickRanges.forEach(function (button) {
        button.addEventListener('click', function () {
            setQuickRange(
                button.dataset.range,
                button
            );
        });
    });

    dom.clearDates.addEventListener('click', function () {
        startDate = '';
        endDate = '';
        datePicker.clear();
        clearQuickRangeActive();
        updateFilterCount();
        scheduleReportReload(50);
    });

    dom.clearFilters.addEventListener(
        'click',
        clearFilters
    );

    dom.applyFilters.addEventListener(
        'click',
        loadReport
    );

    dom.search.addEventListener('input', function () {
        updateFilterCount();
        scheduleReportReload(350);
    });

    dom.search.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            loadReport();
        }
    });

    [
        dom.plan,
        dom.method,
        dom.status
    ].forEach(function (control) {
        control.addEventListener('change', function () {
            updateFilterCount();
            scheduleReportReload(80);
        });
    });

    dom.pageSize.addEventListener('change', function () {
        pageSize = Number(dom.pageSize.value) || 15;
        currentPage = 1;
        renderTable();
    });

    dom.exportExcel.addEventListener(
        'click',
        exportExcel
    );

    dom.exportPdf.addEventListener(
        'click',
        exportPdf
    );

    updateStatusOptions();
    updateReportCopy();
    updateFilterCount();
    loadReport();
})();
</script>
</body>
</html>