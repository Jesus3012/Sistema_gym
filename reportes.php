<?php
// Archivo: reportes.php

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';

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

            if ($busqueda !== '') {
                $texto = '%' . $busqueda . '%';

                $condiciones[] = "(
                    CONCAT(c.nombre, ' ', c.apellido) LIKE ?
                    OR c.telefono LIKE ?
                    OR c.email LIKE ?
                    OR p.nombre LIKE ?
                    OR CAST(i.id AS CHAR) LIKE ?
                )";

                $tipos .= 'sssss';
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

            if ($busqueda !== '') {
                $texto = '%' . $busqueda . '%';

                $condiciones[] = "(
                    CAST(v.id AS CHAR) LIKE ?
                    OR CONCAT(c.nombre, ' ', c.apellido) LIKE ?
                    OR u.nombre LIKE ?
                    OR detalle.productos LIKE ?
                )";

                $tipos .= 'ssss';
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

$usuarioId = isset($_SESSION['user_id'])
    ? (int) $_SESSION['user_id']
    : 0;

$usuarioNombre = isset($_SESSION['user_name'])
    ? (string) $_SESSION['user_name']
    : 'Usuario';

$usuarioRol = isset($_SESSION['user_rol'])
    ? (string) $_SESSION['user_rol']
    : '';

$empresa = obtenerEmpresaReportes($conn);

$planes = array();
$resultadoPlanes = $conn->query(
    "SELECT id, nombre
     FROM planes
     ORDER BY nombre ASC"
);

if ($resultadoPlanes) {
    while ($plan = $resultadoPlanes->fetch_assoc()) {
        $planes[] = array(
            'id' => (int) $plan['id'],
            'nombre' => (string) $plan['nombre']
        );
    }
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

    <style>
        :root {
            --report-primary: #0a2540;
            --report-primary-soft: #edf4fb;
            --report-blue: #2563eb;
            --report-blue-dark: #1d4ed8;
            --report-cyan: #0891b2;
            --report-green: #17875d;
            --report-orange: #d97706;
            --report-red: #c2414b;
            --report-purple: #7c3aed;
            --report-text: #172033;
            --report-muted: #667085;
            --report-border: #dce4ed;
            --report-bg: #f4f7fb;
            --report-card: #ffffff;
            --report-shadow: 0 14px 36px rgba(15, 36, 58, 0.07);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--report-bg);
            color: var(--report-text);
            font-family:
                Inter,
                ui-sans-serif,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;
        }

        .main-content {
            min-height: 100vh;
            margin-left: 270px;
            transition: margin-left 0.3s ease;
        }

        body.sidebar-collapsed .main-content {
            margin-left: 70px;
        }

        .report-page {
            min-height: 100vh;
            padding: 24px;
            background:
                radial-gradient(
                    circle at top right,
                    rgba(37, 99, 235, 0.08),
                    transparent 28%
                ),
                radial-gradient(
                    circle at bottom left,
                    rgba(8, 145, 178, 0.05),
                    transparent 26%
                ),
                var(--report-bg);
        }

        .report-shell {
            width: min(1500px, 100%);
            margin: 0 auto;
        }

        .report-topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            margin-bottom: 18px;
        }

        .report-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            color: var(--report-blue);
            font-size: 0.73rem;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .report-heading h1 {
            margin: 0;
            color: var(--report-primary);
            font-size: clamp(1.75rem, 3vw, 2.35rem);
            line-height: 1.05;
        }

        .report-heading p {
            max-width: 760px;
            margin: 8px 0 0;
            color: var(--report-muted);
            line-height: 1.55;
        }

        .report-user-chip {
            display: inline-flex;
            align-items: center;
            gap: 11px;
            padding: 10px 13px;
            border: 1px solid var(--report-border);
            border-radius: 13px;
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 8px 24px rgba(15, 36, 58, 0.05);
            white-space: nowrap;
        }

        .report-user-chip i {
            width: 36px;
            height: 36px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            color: var(--report-blue);
            background: var(--report-primary-soft);
        }

        .report-user-chip strong,
        .report-user-chip small {
            display: block;
        }

        .report-user-chip strong {
            color: var(--report-primary);
            font-size: 0.84rem;
        }

        .report-user-chip small {
            margin-top: 2px;
            color: var(--report-muted);
            font-size: 0.71rem;
            text-transform: capitalize;
        }

        .report-card {
            background: var(--report-card);
            border: 1px solid var(--report-border);
            border-radius: 18px;
            box-shadow: var(--report-shadow);
        }

        .report-type-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .report-type {
            position: relative;
            display: flex;
            align-items: center;
            gap: 14px;
            min-height: 92px;
            padding: 17px 18px;
            border: 1px solid var(--report-border);
            border-radius: 16px;
            background: #fff;
            cursor: pointer;
            text-align: left;
            transition:
                transform 0.18s ease,
                border-color 0.18s ease,
                box-shadow 0.18s ease,
                background 0.18s ease;
        }

        .report-type:hover {
            transform: translateY(-2px);
            border-color: #b8c9df;
            box-shadow: 0 10px 25px rgba(15, 36, 58, 0.08);
        }

        .report-type.active {
            border-color: rgba(37, 99, 235, 0.42);
            background:
                linear-gradient(
                    135deg,
                    rgba(37, 99, 235, 0.09),
                    rgba(8, 145, 178, 0.04)
                ),
                #fff;
            box-shadow:
                inset 4px 0 0 var(--report-blue),
                0 10px 25px rgba(37, 99, 235, 0.1);
        }

        .report-type-icon {
            width: 48px;
            height: 48px;
            flex: 0 0 48px;
            display: grid;
            place-items: center;
            border-radius: 14px;
            color: var(--report-blue);
            background: var(--report-primary-soft);
            font-size: 1.2rem;
        }

        .report-type[data-type="ventas"] .report-type-icon {
            color: var(--report-purple);
            background: #f2edff;
        }

        .report-type-copy strong,
        .report-type-copy span {
            display: block;
        }

        .report-type-copy strong {
            color: var(--report-primary);
            font-size: 0.98rem;
        }

        .report-type-copy span {
            margin-top: 4px;
            color: var(--report-muted);
            font-size: 0.78rem;
            line-height: 1.4;
        }

        .report-type-check {
            position: absolute;
            top: 13px;
            right: 13px;
            width: 23px;
            height: 23px;
            display: grid;
            place-items: center;
            border: 1px solid var(--report-border);
            border-radius: 50%;
            color: transparent;
            background: #fff;
            font-size: 0.7rem;
        }

        .report-type.active .report-type-check {
            color: #fff;
            border-color: var(--report-blue);
            background: var(--report-blue);
        }

        .report-filter-card {
            margin-bottom: 18px;
            overflow: visible;
        }

        .report-filter-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding: 18px 20px 14px;
            border-bottom: 1px solid #e8edf3;
        }

        .report-filter-title {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .report-filter-title i {
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            border-radius: 11px;
            color: var(--report-blue);
            background: var(--report-primary-soft);
        }

        .report-filter-title h2 {
            margin: 0;
            color: var(--report-primary);
            font-size: 1rem;
        }

        .report-filter-title p {
            margin: 3px 0 0;
            color: var(--report-muted);
            font-size: 0.75rem;
        }

        .report-filter-count {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 10px;
            border-radius: 999px;
            color: #42526a;
            background: #eef3f8;
            border: 1px solid #d9e3ed;
            font-size: 0.72rem;
            font-weight: 850;
        }

        .report-filter-body {
            padding: 18px 20px 20px;
        }

        .report-filter-grid {
            display: grid;
            grid-template-columns:
                minmax(220px, 1.25fr)
                minmax(170px, 0.75fr)
                minmax(170px, 0.75fr)
                minmax(280px, 1fr);
            gap: 13px;
            align-items: end;
        }

        .report-field {
            min-width: 0;
        }

        .report-field label {
            display: block;
            margin-bottom: 7px;
            color: #46556a;
            font-size: 0.73rem;
            font-weight: 850;
            text-transform: uppercase;
            letter-spacing: 0.035em;
        }

        .report-control {
            position: relative;
        }

        .report-control > i {
            position: absolute;
            top: 50%;
            left: 12px;
            transform: translateY(-50%);
            color: #8190a5;
            font-size: 0.86rem;
            pointer-events: none;
        }

        .report-control input,
        .report-control select {
            width: 100%;
            height: 44px;
            border: 1px solid #cfd9e5;
            border-radius: 11px;
            background: #fff;
            color: var(--report-text);
            font: inherit;
            font-size: 0.86rem;
            transition:
                border-color 0.18s ease,
                box-shadow 0.18s ease;
        }

        .report-control input {
            padding: 0 40px 0 38px;
        }

        .report-control select {
            padding: 0 34px 0 12px;
        }

        .report-control input:focus,
        .report-control select:focus {
            outline: none;
            border-color: #6b9bea;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .report-date-clear {
            position: absolute;
            top: 50%;
            right: 8px;
            width: 28px;
            height: 28px;
            display: grid;
            place-items: center;
            transform: translateY(-50%);
            border: 0;
            border-radius: 8px;
            color: #8190a5;
            background: transparent;
            cursor: pointer;
        }

        .report-date-clear:hover {
            color: var(--report-red);
            background: #fff0f2;
        }

        .report-date-quick {
            display: flex;
            gap: 7px;
            margin-top: 9px;
            flex-wrap: wrap;
        }

        .report-quick-btn {
            min-height: 30px;
            padding: 5px 10px;
            border: 1px solid #dbe4ed;
            border-radius: 999px;
            color: #546176;
            background: #f8fafc;
            font: inherit;
            font-size: 0.7rem;
            font-weight: 750;
            cursor: pointer;
        }

        .report-quick-btn:hover,
        .report-quick-btn.active {
            color: var(--report-blue);
            border-color: #bfd1ed;
            background: var(--report-primary-soft);
        }

        .report-filter-actions {
            display: flex;
            justify-content: flex-end;
            gap: 9px;
            margin-top: 17px;
            padding-top: 15px;
            border-top: 1px dashed #dfe6ee;
        }

        .report-btn {
            min-height: 41px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 15px;
            border: 0;
            border-radius: 10px;
            font: inherit;
            font-size: 0.82rem;
            font-weight: 850;
            cursor: pointer;
            text-decoration: none;
            transition:
                transform 0.18s ease,
                box-shadow 0.18s ease,
                opacity 0.18s ease;
        }

        .report-btn:hover:not(:disabled) {
            transform: translateY(-1px);
        }

        .report-btn:disabled {
            opacity: 0.48;
            cursor: not-allowed;
        }

        .report-btn-primary {
            color: #fff;
            background:
                linear-gradient(
                    135deg,
                    var(--report-blue),
                    var(--report-blue-dark)
                );
            box-shadow: 0 8px 18px rgba(37, 99, 235, 0.2);
        }

        .report-btn-soft {
            color: var(--report-primary);
            background: #eef3f8;
            border: 1px solid #d8e1eb;
        }

        .report-btn-excel {
            color: #fff;
            background: linear-gradient(135deg, #17875d, #116847);
            box-shadow: 0 8px 18px rgba(23, 135, 93, 0.18);
        }

        .report-btn-pdf {
            color: #fff;
            background: linear-gradient(135deg, #c2414b, #9f2935);
            box-shadow: 0 8px 18px rgba(194, 65, 75, 0.17);
        }

        .report-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 13px;
            margin-bottom: 18px;
        }

        .report-stat {
            position: relative;
            min-height: 112px;
            padding: 17px;
            overflow: hidden;
        }

        .report-stat::after {
            content: "";
            position: absolute;
            top: -24px;
            right: -24px;
            width: 88px;
            height: 88px;
            border-radius: 50%;
            background: rgba(37, 99, 235, 0.06);
        }

        .report-stat-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }

        .report-stat-icon {
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            border-radius: 11px;
            color: var(--report-blue);
            background: var(--report-primary-soft);
        }

        .report-stat:nth-child(2) .report-stat-icon {
            color: var(--report-green);
            background: #eaf7f1;
        }

        .report-stat:nth-child(3) .report-stat-icon {
            color: var(--report-orange);
            background: #fff6e7;
        }

        .report-stat:nth-child(4) .report-stat-icon {
            color: var(--report-purple);
            background: #f2edff;
        }

        .report-stat-label {
            color: var(--report-muted);
            font-size: 0.72rem;
            font-weight: 850;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .report-stat-value {
            display: block;
            margin-top: 10px;
            color: var(--report-primary);
            font-size: 1.45rem;
            font-weight: 900;
        }

        .report-stat-note {
            display: block;
            margin-top: 4px;
            color: #8290a4;
            font-size: 0.7rem;
        }

        .report-table-card {
            overflow: hidden;
        }

        .report-table-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding: 18px 20px;
            border-bottom: 1px solid #e8edf3;
        }

        .report-table-head h2 {
            margin: 0;
            color: var(--report-primary);
            font-size: 1.05rem;
        }

        .report-table-head p {
            margin: 4px 0 0;
            color: var(--report-muted);
            font-size: 0.76rem;
        }

        .report-export-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .report-table-wrap {
            overflow-x: auto;
        }

        .report-table {
            width: 100%;
            min-width: 980px;
            border-collapse: collapse;
        }

        .report-table th,
        .report-table td {
            padding: 13px 14px;
            text-align: left;
            border-bottom: 1px solid #e7edf3;
            vertical-align: middle;
        }

        .report-table th {
            color: #46556a;
            background: #f6f8fb;
            font-size: 0.7rem;
            font-weight: 900;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            white-space: nowrap;
            cursor: pointer;
            user-select: none;
        }

        .report-table th:hover {
            color: var(--report-blue);
            background: #f0f5fb;
        }

        .report-table th i {
            margin-left: 5px;
            color: #9aa6b7;
            font-size: 0.68rem;
        }

        .report-table td {
            color: #334155;
            font-size: 0.8rem;
        }

        .report-table tbody tr:hover {
            background: #fafcff;
        }

        .report-primary-cell {
            color: var(--report-primary);
            font-weight: 800;
        }

        .report-secondary-cell {
            display: block;
            margin-top: 3px;
            color: #7b8798;
            font-size: 0.69rem;
        }

        .report-money {
            color: var(--report-primary);
            font-weight: 900;
            white-space: nowrap;
        }

        .report-money.positive {
            color: var(--report-green);
        }

        .report-money.negative {
            color: var(--report-red);
        }

        .report-products {
            max-width: 320px;
            line-height: 1.4;
        }

        .report-products-text {
            display: -webkit-box;
            overflow: hidden;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }

        .report-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 8px;
            border-radius: 999px;
            font-size: 0.68rem;
            font-weight: 850;
            white-space: nowrap;
        }

        .report-pill.active,
        .report-pill.completed {
            color: #136342;
            background: #e9f7f0;
            border: 1px solid #c1e8d5;
        }

        .report-pill.expired,
        .report-pill.cancelled {
            color: #9b2f3b;
            background: #fff0f2;
            border: 1px solid #f0c6cc;
        }

        .report-pill.pending,
        .report-pill.warning {
            color: #8c5707;
            background: #fff7e8;
            border: 1px solid #f1d49e;
        }

        .report-pill.neutral {
            color: #4b5a6f;
            background: #eef3f7;
            border: 1px solid #d9e2ea;
        }

        .report-empty,
        .report-loading {
            padding: 56px 20px;
            text-align: center;
            color: var(--report-muted);
        }

        .report-empty i,
        .report-loading i {
            display: block;
            margin-bottom: 12px;
            color: #a3afbf;
            font-size: 2.6rem;
        }

        .report-loading i {
            color: var(--report-blue);
            animation: reportSpin 0.9s linear infinite;
        }

        @keyframes reportSpin {
            to {
                transform: rotate(360deg);
            }
        }

        .report-pagination-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 14px 20px;
            background: #fafbfd;
            border-top: 1px solid #e7edf3;
            flex-wrap: wrap;
        }

        .report-pagination-info {
            color: var(--report-muted);
            font-size: 0.76rem;
        }

        .report-pagination {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .report-page-btn {
            min-width: 35px;
            height: 35px;
            padding: 0 9px;
            display: grid;
            place-items: center;
            border: 1px solid #d8e1eb;
            border-radius: 9px;
            color: var(--report-primary);
            background: #fff;
            font: inherit;
            font-size: 0.76rem;
            font-weight: 850;
            cursor: pointer;
        }

        .report-page-btn:hover:not(:disabled),
        .report-page-btn.active {
            color: #fff;
            border-color: var(--report-blue);
            background: var(--report-blue);
        }

        .report-page-btn:disabled {
            opacity: 0.42;
            cursor: not-allowed;
        }

        .report-page-size {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--report-muted);
            font-size: 0.75rem;
        }

        .report-page-size select {
            height: 35px;
            border: 1px solid #d8e1eb;
            border-radius: 9px;
            padding: 0 28px 0 10px;
            background: #fff;
            color: var(--report-primary);
            font: inherit;
            font-size: 0.76rem;
            font-weight: 750;
        }

        /* Calendario Flatpickr */
        .flatpickr-calendar {
            width: 330px !important;
            border: 1px solid #d9e3ed !important;
            border-radius: 16px !important;
            box-shadow: 0 18px 45px rgba(15, 36, 58, 0.16) !important;
            overflow: hidden;
            font-family: inherit !important;
        }

        .flatpickr-months {
            padding: 9px 7px 4px;
            background:
                linear-gradient(
                    135deg,
                    var(--report-primary),
                    #16446f
                );
        }

        .flatpickr-months .flatpickr-month {
            color: #fff !important;
            fill: #fff !important;
            height: 40px;
        }

        .flatpickr-current-month {
            padding-top: 7px !important;
            font-size: 0.92rem !important;
        }

        .flatpickr-current-month .flatpickr-monthDropdown-months,
        .flatpickr-current-month input.cur-year {
            color: #fff !important;
            font-weight: 800 !important;
        }

        .flatpickr-current-month .flatpickr-monthDropdown-months {
            background: transparent !important;
        }

        .flatpickr-prev-month,
        .flatpickr-next-month {
            top: 10px !important;
            color: #fff !important;
            fill: #fff !important;
        }

        .flatpickr-weekdays {
            padding-top: 8px;
            background: #f4f7fb;
        }

        span.flatpickr-weekday {
            color: #66758a !important;
            font-size: 0.68rem !important;
            font-weight: 900 !important;
        }

        .flatpickr-days {
            padding: 7px 7px 10px;
        }

        .flatpickr-day {
            border-radius: 9px !important;
            color: #354257 !important;
            font-weight: 650 !important;
        }

        .flatpickr-day:hover {
            border-color: var(--report-primary-soft) !important;
            background: var(--report-primary-soft) !important;
        }

        .flatpickr-day.today {
            color: var(--report-blue) !important;
            border-color: #93b4ec !important;
        }

        .flatpickr-day.selected,
        .flatpickr-day.startRange,
        .flatpickr-day.endRange {
            color: #fff !important;
            border-color: var(--report-blue) !important;
            background:
                linear-gradient(
                    135deg,
                    var(--report-blue),
                    var(--report-blue-dark)
                ) !important;
            box-shadow: none !important;
        }

        .flatpickr-day.inRange {
            border-color: #e7effc !important;
            background: #e7effc !important;
            box-shadow:
                -5px 0 0 #e7effc,
                5px 0 0 #e7effc !important;
        }

        @media (max-width: 1200px) {
            .report-filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .report-stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 900px) {
            .report-topbar {
                flex-direction: column;
            }

            .report-user-chip {
                width: 100%;
            }

            .report-type-grid {
                grid-template-columns: 1fr;
            }

            .report-table-head {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0 !important;
            }

            .report-page {
                padding: 76px 14px 18px;
            }

            .report-filter-grid,
            .report-stats-grid {
                grid-template-columns: 1fr;
            }

            .report-filter-head,
            .report-filter-actions,
            .report-pagination-bar {
                align-items: stretch;
                flex-direction: column;
            }

            .report-filter-actions .report-btn,
            .report-export-actions .report-btn {
                flex: 1;
            }

            .report-export-actions {
                width: 100%;
            }
        }

        @media (max-width: 390px) {
            .flatpickr-calendar {
                width: calc(100vw - 24px) !important;
            }
        }


        /* ==========================================================
           DISEÑO ADMINISTRATIVO SOBRIO
           ========================================================== */
        :root {
            --report-primary: #15263a;
            --report-primary-soft: #eef2f6;
            --report-blue: #2f66b3;
            --report-blue-dark: #255390;
            --report-green: #2f7d5a;
            --report-orange: #a86712;
            --report-red: #b54752;
            --report-purple: #6652a3;
            --report-text: #243244;
            --report-muted: #68768a;
            --report-border: #dbe2ea;
            --report-bg: #f3f5f8;
            --report-card: #ffffff;
            --report-shadow: 0 3px 12px rgba(24, 39, 58, 0.055);
        }

        .report-page {
            padding: 24px;
            background: var(--report-bg);
        }

        .report-shell {
            width: min(1460px, 100%);
        }

        .report-topbar {
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid #dfe5ec;
        }

        .report-eyebrow {
            display: none;
        }

        .report-heading h1 {
            font-size: clamp(1.55rem, 2.4vw, 2rem);
            line-height: 1.15;
            letter-spacing: -0.025em;
        }

        .report-heading p {
            margin-top: 5px;
            font-size: 0.86rem;
            line-height: 1.45;
        }

        .report-user-chip {
            padding: 8px 11px;
            border-radius: 9px;
            background: #ffffff;
            box-shadow: none;
        }

        .report-user-chip i {
            width: 31px;
            height: 31px;
            border-radius: 8px;
            color: #526277;
            background: #edf1f5;
            font-size: 0.82rem;
        }

        .report-card {
            border-radius: 11px;
            box-shadow: var(--report-shadow);
        }

        /* Pestañas compactas */
        .report-type-grid {
            width: fit-content;
            max-width: 100%;
            display: flex;
            gap: 4px;
            margin-bottom: 16px;
            padding: 4px;
            border: 1px solid #d8e0e8;
            border-radius: 10px;
            background: #e9eef3;
        }

        .report-type {
            min-height: 42px;
            gap: 8px;
            padding: 8px 14px;
            border: 0;
            border-radius: 7px;
            background: transparent;
            box-shadow: none;
        }

        .report-type:hover {
            transform: none;
            border-color: transparent;
            background: rgba(255, 255, 255, 0.56);
            box-shadow: none;
        }

        .report-type.active {
            border: 0;
            background: #ffffff;
            box-shadow: 0 1px 4px rgba(18, 35, 54, 0.14);
        }

        .report-type-icon {
            width: 27px;
            height: 27px;
            flex-basis: 27px;
            border-radius: 7px;
            color: #526277;
            background: transparent;
            font-size: 0.88rem;
        }

        .report-type[data-type="ventas"] .report-type-icon {
            color: #526277;
            background: transparent;
        }

        .report-type.active .report-type-icon {
            color: var(--report-blue);
            background: #edf3fb;
        }

        .report-type-copy strong {
            font-size: 0.84rem;
        }

        .report-type-copy span,
        .report-type-check {
            display: none;
        }

        /* Filtros */
        .report-filter-card {
            margin-bottom: 16px;
        }

        .report-filter-head {
            padding: 15px 17px 12px;
        }

        .report-filter-title {
            gap: 9px;
        }

        .report-filter-title i {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            color: #526277;
            background: #edf1f5;
            font-size: 0.82rem;
        }

        .report-filter-title h2 {
            font-size: 0.94rem;
        }

        .report-filter-title p {
            margin-top: 2px;
            font-size: 0.72rem;
        }

        .report-filter-meta {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }

        .report-live-status {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-height: 29px;
            padding: 5px 9px;
            border: 1px solid #dce3ea;
            border-radius: 7px;
            color: #667487;
            background: #f8fafc;
            font-size: 0.69rem;
            font-weight: 750;
            white-space: nowrap;
        }

        .report-live-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #7a8797;
        }

        .report-live-status.ready .report-live-dot {
            background: #4d8b6d;
        }

        .report-live-status.loading .report-live-dot {
            background: #2f66b3;
            animation: reportPulse 0.9s ease-in-out infinite;
        }

        .report-live-status.error .report-live-dot {
            background: #b54752;
        }

        @keyframes reportPulse {
            0%, 100% {
                opacity: 0.38;
                transform: scale(0.8);
            }

            50% {
                opacity: 1;
                transform: scale(1.15);
            }
        }

        .report-filter-count {
            min-height: 29px;
            padding: 5px 9px;
            border-radius: 7px;
            font-size: 0.69rem;
        }

        .report-filter-body {
            padding: 15px 17px 17px;
        }

        .report-filter-grid {
            gap: 11px;
        }

        .report-field label {
            margin-bottom: 6px;
            font-size: 0.68rem;
            letter-spacing: 0.025em;
        }

        .report-control input,
        .report-control select {
            height: 41px;
            border-radius: 8px;
            font-size: 0.81rem;
        }

        .report-date-quick {
            margin-top: 7px;
        }

        .report-quick-btn {
            min-height: 27px;
            padding: 4px 9px;
            border-radius: 7px;
            font-size: 0.66rem;
        }

        .report-filter-actions {
            margin-top: 13px;
            padding-top: 12px;
        }

        .report-btn {
            min-height: 38px;
            padding: 8px 13px;
            border-radius: 8px;
            font-size: 0.77rem;
        }

        .report-btn-primary,
        .report-btn-excel,
        .report-btn-pdf {
            box-shadow: none;
        }

        .report-btn-primary {
            background: var(--report-blue);
        }

        .report-btn-primary:hover:not(:disabled) {
            background: var(--report-blue-dark);
        }

        .report-btn-excel {
            background: #347456;
        }

        .report-btn-pdf {
            background: #a9414b;
        }

        .report-btn-soft {
            color: #536176;
            background: #f5f7f9;
        }

        /* Indicadores */
        .report-stats-grid {
            gap: 11px;
            margin-bottom: 16px;
        }

        .report-stat {
            min-height: 94px;
            padding: 15px;
            border-top: 3px solid #6f8daf;
        }

        .report-stat:nth-child(2) {
            border-top-color: #5d8b72;
        }

        .report-stat:nth-child(3) {
            border-top-color: #a47b44;
        }

        .report-stat:nth-child(4) {
            border-top-color: #776b9d;
        }

        .report-stat::after {
            display: none;
        }

        .report-stat-icon {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            color: #64748b !important;
            background: #f0f3f6 !important;
            font-size: 0.78rem;
        }

        .report-stat-label {
            font-size: 0.67rem;
        }

        .report-stat-value {
            margin-top: 7px;
            font-size: 1.27rem;
        }

        /* Tabla */
        .report-table-head {
            padding: 15px 17px;
        }

        .report-table-head h2 {
            font-size: 0.98rem;
        }

        .report-table-head p {
            margin-top: 3px;
            font-size: 0.72rem;
        }

        .report-table th,
        .report-table td {
            padding: 11px 12px;
        }

        .report-table th {
            color: #526176;
            background: #f3f6f9;
            font-size: 0.66rem;
        }

        .report-table td {
            font-size: 0.77rem;
        }

        .report-table tbody tr:nth-child(even) {
            background: #fbfcfd;
        }

        .report-table tbody tr:hover {
            background: #f2f6fa;
        }

        .report-table-wrap {
            transition: opacity 0.18s ease;
        }

        .report-table-wrap.is-updating {
            opacity: 0.52;
            pointer-events: none;
        }

        .report-pill {
            padding: 4px 7px;
            border-radius: 6px;
            font-size: 0.64rem;
        }

        .report-pagination-bar {
            padding: 11px 17px;
        }

        .report-page-btn {
            min-width: 32px;
            height: 32px;
            border-radius: 7px;
            font-size: 0.7rem;
        }

        .report-page-size select {
            height: 32px;
            border-radius: 7px;
        }

        /* Calendario más formal */
        .flatpickr-calendar {
            border-radius: 11px !important;
            box-shadow: 0 12px 34px rgba(15, 36, 58, 0.16) !important;
        }

        .flatpickr-months {
            background: #1e3147;
        }

        .flatpickr-day {
            border-radius: 6px !important;
            font-weight: 600 !important;
        }

        .flatpickr-day.selected,
        .flatpickr-day.startRange,
        .flatpickr-day.endRange {
            background: #2f66b3 !important;
            border-color: #2f66b3 !important;
        }

        @media (max-width: 900px) {
            .report-type-grid {
                width: 100%;
            }

            .report-type {
                flex: 1;
            }
        }

        @media (max-width: 768px) {
            .report-filter-meta {
                width: 100%;
                justify-content: flex-start;
            }

            .report-type-grid {
                display: grid;
                grid-template-columns: 1fr;
            }
        }

        @media print {
            .sidebar,
            .mobile-menu-toggle {
                display: none !important;
            }

            .main-content {
                margin-left: 0 !important;
            }
        }
    </style>
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
                        Consulta la información del gimnasio, aplica filtros
                        y descarga el resultado en Excel o PDF.
                    </p>
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
                'Listado de membresías según los filtros seleccionados.';
        } else {
            dom.search.placeholder =
                'Ticket, cliente, vendedor o producto...';

            dom.tableTitle.textContent =
                'Detalle de ventas de productos';

            dom.tableSubtitle.textContent =
                'Listado de ventas con importe original, devoluciones y total neto.';
        }
    }

    function getFilterParams() {
        const params = new URLSearchParams({
            action: 'datos',
            tipo: reportType
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
        const filters = [];

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
        return reportType === 'inscripciones'
            ? 'Reporte de Inscripciones'
            : 'Reporte de Ventas de Productos';
    }

    function getExportRows() {
        if (reportType === 'inscripciones') {
            return rows.map(function (row) {
                return {
                    Folio: row.id,
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
                    'TOTAL',
                    formatMoney(totalIncome),
                    ''
                ]];

                columnStyles = {
                    1: {
                        cellWidth: 37
                    },
                    3: {
                        cellWidth: 28
                    },
                    7: {
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
                    'TOTALES',
                    formatMoney(totals.gross),
                    formatMoney(totals.returns),
                    formatMoney(totals.net),
                    ''
                ]];

                columnStyles = {
                    2: {
                        cellWidth: 53
                    },
                    4: {
                        cellWidth: 29
                    },
                    6: {
                        halign: 'right'
                    },
                    7: {
                        halign: 'right'
                    },
                    8: {
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
                            ? 6.15
                            : 6.65,
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
                        empresa.nombre || 'Gimnasio',
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
