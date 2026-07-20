<?php
// VERSION COMPATIBLE PHP 7
// Archivo: corte_caja_detalle.php
// Ubicación: raíz de Sistema_gym

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/caja_helper.php';
require_once __DIR__ . '/includes/sucursal_context.php';

date_default_timezone_set('America/Mexico_City');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$usuarioId = (int) ($_SESSION['user_id'] ?? 0);
$usuarioRol = strtolower(trim((string) ($_SESSION['user_rol'] ?? '')));
$cajaId = max(0, (int) ($_GET['id'] ?? 0));
$modoImpresion = isset($_GET['imprimir']) && (string) $_GET['imprimir'] === '1';

if ($usuarioId <= 0) {
    header('Location: login.php');
    exit();
}

if ($cajaId <= 0) {
    header('Location: corte_caja.php');
    exit();
}

$database = new Database();
$conn = $database->getConnection();

if (!$conn instanceof mysqli) {
    die('No fue posible establecer conexión con la base de datos.');
}

$conn->set_charset('utf8mb4');

$usuarioRolBase = strtolower(trim((string) (
    $_SESSION['user_rol_base'] ?? $usuarioRol
)));

$esAdministradorCaja = in_array(
    $usuarioRolBase,
    array('admin', 'administrador'),
    true
);

try {
    if (function_exists('sucursal_inicializar_sesion')) {
        sucursal_inicializar_sesion($conn);
    }
} catch (Throwable $errorSucursal) {
    die(htmlspecialchars(
        $errorSucursal->getMessage(),
        ENT_QUOTES,
        'UTF-8'
    ));
}

$vistaSolicitadaCaja = strtolower(trim((string) (
    $_GET['vista'] ?? ''
)));

if (
    $esAdministradorCaja
    && $vistaSolicitadaCaja === 'global'
    && function_exists('sucursal_activar_vista_global')
) {
    sucursal_activar_vista_global($conn, $usuarioId);
}

if (
    $vistaSolicitadaCaja === 'sucursal'
    && function_exists('sucursal_desactivar_vista_global')
) {
    sucursal_desactivar_vista_global();
}

$vistaGlobalCaja = $esAdministradorCaja
    && function_exists('sucursal_dashboard_vista_global')
    && sucursal_dashboard_vista_global();

$sucursalIdSesion = (int) ($_SESSION['sucursal_id'] ?? 0);
$rutaRegresoCaja = 'corte_caja.php?vista=' . (
    $vistaGlobalCaja ? 'global' : 'sucursal'
);

$caja = obtenerCajaPorId($conn, $cajaId);

if (!$caja) {
    http_response_code(404);
    die('El corte solicitado no existe.');
}

if (
    !$vistaGlobalCaja
    && (int) $caja['sucursal_id'] !== $sucursalIdSesion
) {
    $_SESSION['mensaje_acceso'] =
        'El corte pertenece a otra sucursal. Cambia de sede para consultarlo.';
    header('Location: ' . $rutaRegresoCaja . '&error=sucursal_distinta');
    exit();
}

if (
    !$esAdministradorCaja
    && (int) $caja['usuario_apertura_id'] !== $usuarioId
) {
    $_SESSION['mensaje_acceso'] =
        'Solo puedes consultar los cortes realizados por tu usuario.';
    header('Location: ' . $rutaRegresoCaja . '&error=acceso_denegado');
    exit();
}

if (empty($_SESSION['csrf_recalcular_corte'])) {
    $_SESSION['csrf_recalcular_corte'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) ($_POST['accion'] ?? '') === 'recalcular_corte'
) {
    try {
        if (!$esAdministradorCaja) {
            throw new RuntimeException('Solo un administrador puede recalcular un corte cerrado.');
        }

        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!hash_equals((string) $_SESSION['csrf_recalcular_corte'], $token)) {
            throw new RuntimeException('La sesión del formulario expiró. Recarga la página.');
        }

        if ((string) $caja['estado'] !== 'cerrada' || empty($caja['fecha_cierre'])) {
            throw new RuntimeException('Solo se pueden recalcular cortes que ya estén cerrados.');
        }

        $conn->begin_transaction();

        $stmtLock = $conn->prepare(
            "SELECT id
             FROM cajas
             WHERE id = ?
               AND sucursal_id = ?
               AND estado = 'cerrada'
             FOR UPDATE"
        );

        if (!$stmtLock) {
            throw new RuntimeException('No se pudo bloquear el corte para recalcularlo.');
        }

        $sucursalCajaId = (int) $caja['sucursal_id'];
        $stmtLock->bind_param('ii', $cajaId, $sucursalCajaId);
        $stmtLock->execute();
        $resultadoLock = $stmtLock->get_result();
        $filaLock = $resultadoLock->fetch_assoc();
        $stmtLock->close();

        if (!$filaLock) {
            throw new RuntimeException('El corte ya no está disponible para recalcular.');
        }

        $cajaActual = obtenerCajaPorId($conn, $cajaId);
        if (!$cajaActual) {
            throw new RuntimeException('No fue posible volver a consultar el corte.');
        }

        $fechaCierre = (string) $cajaActual['fecha_cierre'];
        $resumenNuevo = calcularResumenCaja($conn, $cajaActual, $fechaCierre);

        if (!empty($resumenNuevo['advertencias'])) {
            throw new RuntimeException(implode(' ', $resumenNuevo['advertencias']));
        }

        guardarSnapshotOperacionesCaja($conn, $cajaActual, $fechaCierre);

        $efectivoContado = (float) ($cajaActual['efectivo_contado'] ?? 0);
        $efectivoEsperado = (float) $resumenNuevo['efectivo_esperado'];
        $diferenciaNueva = round($efectivoContado - $efectivoEsperado, 2);

        if (abs($diferenciaNueva) < 0.005) {
            $diferenciaNueva = 0.00;
        }

        $ventasEfectivo = (float) $resumenNuevo['ventas']['efectivo'];
        $ventasTarjeta = (float) $resumenNuevo['ventas']['tarjeta'];
        $ventasTransferencia = (float) $resumenNuevo['ventas']['transferencia'];

        $membresiasEfectivo = (float) $resumenNuevo['membresias']['efectivo'];
        $membresiasTarjeta = (float) $resumenNuevo['membresias']['tarjeta'];
        $membresiasTransferencia = (float) $resumenNuevo['membresias']['transferencia'];

        $devolucionesEfectivo = (float) $resumenNuevo['devoluciones']['efectivo'];
        $devolucionesTarjeta = (float) $resumenNuevo['devoluciones']['tarjeta'];
        $devolucionesTransferencia = (float) $resumenNuevo['devoluciones']['transferencia'];

        $entradasManuales = (float) $resumenNuevo['manuales']['entradas'];
        $salidasManuales = (float) $resumenNuevo['manuales']['salidas'];

        $totalBruto = (float) $resumenNuevo['total_bruto'];
        $totalDevoluciones = (float) $resumenNuevo['total_devoluciones'];
        $totalNeto = (float) $resumenNuevo['total_neto'];

        $operacionesVentas = (int) $resumenNuevo['ventas']['operaciones'];
        $operacionesMembresias = (int) $resumenNuevo['membresias']['operaciones'];
        $operacionesDevoluciones = (int) $resumenNuevo['devoluciones']['operaciones'];

        $sqlUpdate = "UPDATE cajas SET
                        ventas_efectivo = ?,
                        ventas_tarjeta = ?,
                        ventas_transferencia = ?,
                        membresias_efectivo = ?,
                        membresias_tarjeta = ?,
                        membresias_transferencia = ?,
                        devoluciones_efectivo = ?,
                        devoluciones_tarjeta = ?,
                        devoluciones_transferencia = ?,
                        entradas_manuales = ?,
                        salidas_manuales = ?,
                        total_bruto = ?,
                        total_devoluciones = ?,
                        total_neto = ?,
                        efectivo_esperado = ?,
                        diferencia = ?,
                        operaciones_ventas = ?,
                        operaciones_membresias = ?,
                        operaciones_devoluciones = ?
                      WHERE id = ?
                        AND sucursal_id = ?
                        AND estado = 'cerrada'";

        $stmtUpdate = $conn->prepare($sqlUpdate);
        if (!$stmtUpdate) {
            throw new RuntimeException(
                'No se pudo preparar la actualización del corte. Detalle MySQL: ' . $conn->error
            );
        }

        $stmtUpdate->bind_param(
            'ddddddddddddddddiiiii',
            $ventasEfectivo,
            $ventasTarjeta,
            $ventasTransferencia,
            $membresiasEfectivo,
            $membresiasTarjeta,
            $membresiasTransferencia,
            $devolucionesEfectivo,
            $devolucionesTarjeta,
            $devolucionesTransferencia,
            $entradasManuales,
            $salidasManuales,
            $totalBruto,
            $totalDevoluciones,
            $totalNeto,
            $efectivoEsperado,
            $diferenciaNueva,
            $operacionesVentas,
            $operacionesMembresias,
            $operacionesDevoluciones,
            $cajaId,
            $sucursalCajaId
        );

        if (!$stmtUpdate->execute()) {
            $detalleMysql = $stmtUpdate->error;
            $stmtUpdate->close();
            throw new RuntimeException(
                'No se pudo actualizar el corte. Detalle MySQL: ' . $detalleMysql
            );
        }

        $stmtUpdate->close();
        $conn->commit();

        $_SESSION['caja_flash'] = array(
            'tipo' => 'success',
            'titulo' => 'Corte recalculado',
            'mensaje' => 'Los importes del corte se actualizaron con las ventas y devoluciones correctas.',
        );

        header('Location: corte_caja_detalle.php?id=' . $cajaId . '&vista=' . ($vistaGlobalCaja ? 'global' : 'sucursal'));
        exit();
    } catch (Throwable $errorRecalculo) {
        try {
            $conn->rollback();
        } catch (Throwable $errorRollback) {
        }

        $_SESSION['caja_flash'] = array(
            'tipo' => 'error',
            'titulo' => 'No fue posible recalcular el corte',
            'mensaje' => $errorRecalculo->getMessage(),
        );

        header('Location: corte_caja_detalle.php?id=' . $cajaId . '&vista=' . ($vistaGlobalCaja ? 'global' : 'sucursal'));
        exit();
    }
}

function hDetalle($valor) {
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function moneyDetalle($valor) {
    return '$' . number_format((float) ($valor ?? 0), 2, '.', ',');
}

function fechaDetalle($valor, $alternativa = 'Pendiente') {
    if (empty($valor)) {
        return $alternativa;
    }

    $timestamp = strtotime((string) $valor);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : $alternativa;
}

$gimnasio = array(
    'nombre' => 'Gimnasio',
    'telefono' => '',
    'email' => '',
    'direccion' => '',
    'logo' => '',
);

$resultadoGym = $conn->query(
    "SELECT nombre, telefono, email, direccion, logo
     FROM configuracion_gimnasio
     WHERE id = 1
     LIMIT 1"
);

if ($resultadoGym && $filaGym = $resultadoGym->fetch_assoc()) {
    $gimnasio = array_merge($gimnasio, $filaGym);
}

/*
 * El documento y la pantalla muestran la sede propietaria del corte.
 * La configuración general solo funciona como respaldo.
 */
$gimnasio['nombre'] = trim((string) (
    $caja['sucursal_nombre'] ?? $gimnasio['nombre']
));
$gimnasio['telefono'] = trim((string) (
    $caja['sucursal_telefono'] ?? $gimnasio['telefono']
));
$gimnasio['email'] = trim((string) (
    $caja['sucursal_email'] ?? $gimnasio['email']
));
$gimnasio['direccion'] = trim((string) (
    $caja['sucursal_direccion'] ?? $gimnasio['direccion']
));

if (!empty($caja['sucursal_logo'])) {
    $gimnasio['logo'] = $caja['sucursal_logo'];
}

$logoDisponible = false;
$logoWeb = trim((string) ($gimnasio['logo'] ?? ''));

if ($logoWeb !== '') {
    $logoFisico = $logoWeb;

    if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $logoFisico)) {
        $logoFisico = __DIR__ . '/' . ltrim($logoFisico, '/\\');
    }

    $logoDisponible = file_exists($logoFisico);
}

$esCerrada = (string) $caja['estado'] === 'cerrada';
$paginaActual = max(1, (int) ($_GET['pagina'] ?? 1));
$porPagina = 25;
$operaciones = array();
$operacionesImpresion = array();
$totalOperaciones = 0;
$totalPaginas = 1;

try {
    $resumen = $esCerrada
        ? resumenCongeladoCaja($caja)
        : calcularResumenCaja($conn, $caja);

    $totalOperaciones = $esCerrada
        ? contarSnapshotOperacionesCaja($conn, $cajaId)
        : contarOperacionesCaja($conn, $caja);

    $totalPaginas = max(1, (int) ceil($totalOperaciones / $porPagina));
    $paginaActual = min($paginaActual, $totalPaginas);
    $offset = ($paginaActual - 1) * $porPagina;

    $operaciones = $esCerrada
        ? listarSnapshotOperacionesCaja($conn, $cajaId, $porPagina, $offset)
        : listarOperacionesCaja($conn, $caja, $porPagina, $offset);

    if ($modoImpresion && $totalOperaciones > 0) {
        $limiteImpresion = max(1, $totalOperaciones);

        $operacionesImpresion = $esCerrada
            ? listarSnapshotOperacionesCaja($conn, $cajaId, $limiteImpresion, 0)
            : listarOperacionesCaja($conn, $caja, $limiteImpresion, 0);
    }
} catch (Throwable $errorDetalle) {
    $_SESSION['caja_flash'] = array(
        'tipo' => 'error',
        'titulo' => 'No fue posible consultar el corte',
        'mensaje' => $errorDetalle->getMessage(),
    );

    header('Location: corte_caja.php');
    exit();
}

$flash = $_SESSION['caja_flash'] ?? null;
unset($_SESSION['caja_flash']);

$diferencia = round((float) ($caja['diferencia'] ?? 0), 2);

if (abs($diferencia) < 0.005) {
    $estadoDiferencia = 'cuadrada';
    $textoDiferencia = 'Sin diferencia';
} elseif ($diferencia > 0) {
    $estadoDiferencia = 'sobrante';
    $textoDiferencia = 'Sobrante';
} else {
    $estadoDiferencia = 'faltante';
    $textoDiferencia = 'Faltante';
}

$totalEfectivoIngresos =
    (float) $resumen['ventas']['efectivo'] +
    (float) $resumen['membresias']['efectivo'];

$totalTarjetaIngresos =
    (float) $resumen['ventas']['tarjeta'] +
    (float) $resumen['membresias']['tarjeta'];

$totalTransferenciaIngresos =
    (float) $resumen['ventas']['transferencia'] +
    (float) $resumen['membresias']['transferencia'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $modoImpresion ? 'Imprimir ' : 'Detalle ' ?><?= hDetalle($caja['folio']) ?></title>
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
        referrerpolicy="no-referrer"
    >
    <?php if (!$modoImpresion): ?>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php endif; ?>

    <style>
        :root {
            --detail-primary: #0a2540;
            --detail-blue: #2563eb;
            --detail-green: #17875d;
            --detail-orange: #c46b09;
            --detail-red: #c2414b;
            --detail-text: #172033;
            --detail-muted: #667085;
            --detail-border: #dce4ed;
            --detail-bg: #f5f7fa;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: var(--detail-text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        }

        .detail-page {
            min-height: 100vh;
            padding: 22px;
            background:
                radial-gradient(circle at top right, rgba(37, 99, 235, .07), transparent 30%),
                var(--detail-bg);
        }

        .detail-shell {
            width: min(1260px, 100%);
            margin: 0 auto;
        }

        .detail-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 16px;
        }

        .detail-toolbar-actions {
            display: flex;
            gap: 9px;
            flex-wrap: wrap;
        }

        .detail-btn {
            min-height: 42px;
            border: 0;
            border-radius: 10px;
            padding: 10px 15px;
            text-decoration: none;
            cursor: pointer;
            font: inherit;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: transform .18s ease, box-shadow .18s ease;
        }

        .detail-btn:hover {
            transform: translateY(-1px);
        }

        .detail-btn-primary {
            color: #fff;
            background: linear-gradient(135deg, #2563eb, #1d4fb8);
            box-shadow: 0 8px 18px rgba(37, 99, 235, .2);
        }

        .detail-btn-soft {
            color: var(--detail-primary);
            background: #edf2f7;
            border: 1px solid #d6e0e9;
        }

        .detail-card {
            background: #fff;
            border: 1px solid var(--detail-border);
            border-radius: 16px;
            box-shadow: 0 12px 30px rgba(15, 36, 58, .07);
        }

        .detail-hero {
            position: relative;
            overflow: hidden;
            padding: 23px 25px;
            color: #fff;
            background: linear-gradient(135deg, #0a2540 0%, #17436d 100%);
            border-radius: 16px;
        }

        .detail-hero::after {
            content: '';
            position: absolute;
            right: -55px;
            top: -70px;
            width: 190px;
            height: 190px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .055);
        }

        .detail-hero-main {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            gap: 18px;
            align-items: flex-start;
        }

        .detail-hero h1 {
            margin: 0 0 6px;
            font-size: 1.62rem;
        }

        .detail-hero p {
            margin: 0;
            color: rgba(255,255,255,.76);
        }

        .detail-hero-badges {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }

        .detail-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: .74rem;
            font-weight: 900;
            white-space: nowrap;
        }

        .detail-badge.branch {
            color: #dbeafe;
            background: rgba(37, 99, 235, .18);
            border: 1px solid rgba(191, 219, 254, .28);
        }

        .detail-badge.closed {
            color: #eff6ff;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.22);
        }

        .detail-badge.cuadrada {
            color: #d9ffed;
            background: rgba(16, 185, 129, .18);
            border: 1px solid rgba(167, 243, 208, .3);
        }

        .detail-badge.sobrante {
            color: #fff1cf;
            background: rgba(217, 119, 6, .22);
            border: 1px solid rgba(253, 230, 138, .3);
        }

        .detail-badge.faltante {
            color: #ffe0e3;
            background: rgba(225, 29, 72, .2);
            border: 1px solid rgba(254, 205, 211, .3);
        }

        .detail-meta {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
            margin: 16px 0;
        }

        .detail-meta-card {
            padding: 15px 16px;
        }

        .detail-meta-label {
            display: block;
            margin-bottom: 6px;
            color: var(--detail-muted);
            font-size: .7rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .045em;
        }

        .detail-meta-value {
            color: var(--detail-primary);
            font-size: .9rem;
            font-weight: 900;
            line-height: 1.35;
        }

        .detail-reconciliation {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .detail-reconciliation-card {
            position: relative;
            overflow: hidden;
            padding: 17px;
        }

        .detail-reconciliation-card::after {
            content: '';
            position: absolute;
            right: -20px;
            top: -20px;
            width: 75px;
            height: 75px;
            border-radius: 50%;
            background: rgba(37, 99, 235, .055);
        }

        .detail-reconciliation-card span {
            display: block;
            color: var(--detail-muted);
            font-size: .72rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .detail-reconciliation-card strong {
            position: relative;
            z-index: 1;
            display: block;
            margin-top: 7px;
            color: var(--detail-primary);
            font-size: 1.28rem;
        }

        .detail-reconciliation-card.cuadrada strong { color: var(--detail-green); }
        .detail-reconciliation-card.sobrante strong { color: var(--detail-orange); }
        .detail-reconciliation-card.faltante strong { color: var(--detail-red); }

        .detail-section {
            padding: 20px;
            margin-bottom: 16px;
        }

        .detail-section-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 12px;
            margin-bottom: 15px;
        }

        .detail-section h2 {
            margin: 0;
            color: var(--detail-primary);
            font-size: 1.2rem;
        }

        .detail-section-head p {
            margin: 4px 0 0;
            color: var(--detail-muted);
            font-size: .82rem;
        }

        .detail-count {
            padding: 6px 10px;
            border-radius: 999px;
            color: #42526a;
            background: #eef3f8;
            border: 1px solid #d9e2eb;
            font-size: .72rem;
            font-weight: 900;
            white-space: nowrap;
        }

        .detail-breakdown {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .detail-breakdown-box {
            padding: 15px;
            border: 1px solid #e0e7ef;
            border-radius: 12px;
            background: #fafbfd;
        }

        .detail-breakdown-box h3 {
            margin: 0 0 10px;
            color: var(--detail-primary);
            font-size: .94rem;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 7px 0;
            border-bottom: 1px dashed #e2e8f0;
            color: var(--detail-muted);
            font-size: .82rem;
        }

        .detail-row:last-child {
            border-bottom: 0;
        }

        .detail-row strong {
            color: var(--detail-text);
            white-space: nowrap;
        }

        .detail-table-wrap {
            overflow-x: auto;
        }

        .detail-table {
            width: 100%;
            min-width: 760px;
            border-collapse: collapse;
        }

        .detail-table th,
        .detail-table td {
            padding: 11px 10px;
            border-bottom: 1px solid #e7ecf2;
            text-align: left;
            font-size: .82rem;
        }

        .detail-table th {
            color: #4b5970;
            background: #f6f8fb;
            font-size: .7rem;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .detail-amount {
            font-weight: 900;
            white-space: nowrap;
        }

        .detail-amount.entrada {
            color: var(--detail-green);
        }

        .detail-amount.salida {
            color: var(--detail-red);
        }

        .detail-pill {
            display: inline-flex;
            padding: 4px 8px;
            border-radius: 999px;
            color: #42526a;
            background: #eef3f8;
            font-size: .68rem;
            font-weight: 800;
        }

        .detail-pagination {
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            margin-top: 14px;
            flex-wrap: wrap;
        }

        .detail-page-link {
            min-width: 34px;
            height: 34px;
            padding: 0 9px;
            display: grid;
            place-items: center;
            border-radius: 8px;
            border: 1px solid var(--detail-border);
            color: var(--detail-primary);
            background: #fff;
            text-decoration: none;
            font-size: .78rem;
            font-weight: 800;
        }

        .detail-page-link.active {
            color: #fff;
            background: var(--detail-blue);
            border-color: var(--detail-blue);
        }

        .detail-empty {
            padding: 26px 15px;
            text-align: center;
            color: var(--detail-muted);
        }

        .detail-note-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .detail-note-box {
            min-height: 95px;
            padding: 14px;
            border-radius: 11px;
            color: #4e5e73;
            background: #f8fafc;
            border: 1px solid #e1e7ee;
            line-height: 1.55;
            white-space: pre-wrap;
            font-size: .84rem;
        }

        .detail-note-box strong {
            display: block;
            margin-bottom: 5px;
            color: var(--detail-primary);
        }

        /* Documento de impresión independiente */
        .print-body {
            background: #eef1f4;
        }

        .print-document {
            width: 210mm;
            min-height: 297mm;
            margin: 18px auto;
            padding: 13mm 12mm 12mm;
            color: #1f2937;
            background: #fff;
            font-family: Arial, Helvetica, sans-serif;
            box-shadow: 0 12px 38px rgba(15, 23, 42, .16);
        }

        .print-header {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 12px;
            align-items: center;
            padding-bottom: 10px;
            border-bottom: 2px solid #172f49;
        }

        .print-logo {
            width: 52px;
            height: 52px;
            display: grid;
            place-items: center;
            overflow: hidden;
            border: 1px solid #cfd6dd;
            border-radius: 7px;
            color: #172f49;
            font-size: 1.5rem;
            font-weight: 900;
        }

        .print-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .print-business h1 {
            margin: 0 0 3px;
            color: #172f49;
            font-size: 16px;
        }

        .print-business p {
            margin: 1px 0;
            color: #566272;
            font-size: 9px;
            line-height: 1.3;
        }

        .print-title {
            text-align: right;
        }

        .print-title strong {
            display: block;
            color: #172f49;
            font-size: 14px;
        }

        .print-title span {
            display: block;
            margin-top: 4px;
            color: #4b5563;
            font-size: 9px;
        }

        .print-section {
            margin-top: 10px;
            break-inside: avoid;
        }

        .print-section.allow-break {
            break-inside: auto;
        }

        .print-section-title {
            margin: 0 0 5px;
            padding: 5px 7px;
            color: #172f49;
            background: #edf1f5;
            border-left: 3px solid #172f49;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .print-info-table,
        .print-summary-table,
        .print-operations-table {
            width: 100%;
            border-collapse: collapse;
        }

        .print-info-table td,
        .print-summary-table th,
        .print-summary-table td,
        .print-operations-table th,
        .print-operations-table td {
            border: 1px solid #cfd6dd;
        }

        .print-info-table td {
            width: 20%;
            padding: 6px 7px;
            vertical-align: top;
            font-size: 9px;
        }

        .print-info-table span {
            display: block;
            margin-bottom: 2px;
            color: #647180;
            font-size: 7.5px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .print-info-table strong {
            color: #172f49;
            font-size: 9px;
        }

        .print-summary-table th,
        .print-summary-table td {
            padding: 5px 6px;
            font-size: 8.5px;
        }

        .print-summary-table th {
            color: #344154;
            background: #f5f7f9;
            text-align: left;
        }

        .print-summary-table td:last-child,
        .print-summary-table th:last-child {
            text-align: right;
            white-space: nowrap;
        }

        .print-balance {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            border: 1px solid #cfd6dd;
        }

        .print-balance-item {
            padding: 7px;
            border-right: 1px solid #cfd6dd;
        }

        .print-balance-item:last-child {
            border-right: 0;
        }

        .print-balance-item span {
            display: block;
            color: #647180;
            font-size: 7.5px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .print-balance-item strong {
            display: block;
            margin-top: 3px;
            color: #172f49;
            font-size: 11px;
        }

        .print-operations-table {
            table-layout: fixed;
        }

        .print-operations-table thead {
            display: table-header-group;
        }

        .print-operations-table tr {
            break-inside: avoid;
        }

        .print-operations-table th,
        .print-operations-table td {
            padding: 4px 4px;
            font-size: 7.3px;
            line-height: 1.25;
            vertical-align: top;
            word-wrap: break-word;
        }

        .print-operations-table th {
            color: #344154;
            background: #edf1f5;
            text-transform: uppercase;
        }

        .print-operations-table .col-num { width: 5%; }
        .print-operations-table .col-date { width: 15%; }
        .print-operations-table .col-origin { width: 13%; }
        .print-operations-table .col-concept { width: 31%; }
        .print-operations-table .col-method { width: 12%; }
        .print-operations-table .col-type { width: 11%; }
        .print-operations-table .col-amount { width: 13%; text-align: right; }

        .print-notes {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .print-note {
            min-height: 44px;
            padding: 6px;
            border: 1px solid #cfd6dd;
            font-size: 8px;
            line-height: 1.35;
            white-space: pre-wrap;
        }

        .print-note strong {
            display: block;
            margin-bottom: 3px;
            color: #172f49;
        }

        .print-signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 28mm;
            margin-top: 18mm;
            break-inside: avoid;
        }

        .print-signature {
            padding-top: 5px;
            border-top: 1px solid #4b5563;
            text-align: center;
            color: #374151;
            font-size: 8px;
        }

        .print-footer {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-top: 9px;
            padding-top: 5px;
            border-top: 1px solid #d5dbe1;
            color: #6b7280;
            font-size: 7px;
        }

        @media (max-width: 980px) {
            .detail-meta,
            .detail-reconciliation {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .detail-breakdown {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 650px) {
            .detail-page {
                padding: 14px;
            }

            .detail-toolbar,
            .detail-hero-main,
            .detail-section-head {
                align-items: flex-start;
                flex-direction: column;
            }

            .detail-hero-badges {
                justify-content: flex-start;
            }

            .detail-meta,
            .detail-reconciliation,
            .detail-note-grid {
                grid-template-columns: 1fr;
            }
        }

        @page {
            size: A4 portrait;
            margin: 10mm;
        }

        @media print {
            html,
            body {
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
            }

            .print-document {
                width: auto;
                min-height: auto;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }

            .print-no-print {
                display: none !important;
            }
        }
    </style>
</head>

<?php if ($modoImpresion): ?>
<body class="print-body">
    <main class="print-document">
        <header class="print-header">
            <div class="print-logo">
                <?php if ($logoDisponible): ?>
                    <img src="<?= hDetalle($logoWeb) ?>" alt="Logo">
                <?php else: ?>
                    <i class="fas fa-dumbbell"></i>
                <?php endif; ?>
            </div>

            <div class="print-business">
                <h1><?= hDetalle($gimnasio['nombre']) ?></h1>
                <?php if (!empty($gimnasio['direccion'])): ?>
                    <p><?= hDetalle($gimnasio['direccion']) ?></p>
                <?php endif; ?>
                <?php if (!empty($gimnasio['telefono']) || !empty($gimnasio['email'])): ?>
                    <p>
                        <?= !empty($gimnasio['telefono']) ? 'Tel. ' . hDetalle($gimnasio['telefono']) : '' ?>
                        <?= !empty($gimnasio['telefono']) && !empty($gimnasio['email']) ? ' · ' : '' ?>
                        <?= !empty($gimnasio['email']) ? hDetalle($gimnasio['email']) : '' ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="print-title">
                <strong>REPORTE DE CORTE DE CAJA</strong>
                <span>Folio: <?= hDetalle($caja['folio']) ?></span>
                <span>Estado: <?= $esCerrada ? 'CERRADA' : 'ABIERTA' ?></span>
            </div>
        </header>

        <section class="print-section">
            <h2 class="print-section-title">Datos del turno</h2>
            <table class="print-info-table">
                <tr>
                    <td>
                        <span>Sucursal</span>
                        <strong><?= hDetalle($caja['sucursal_nombre']) ?></strong>
                    </td>
                    <td>
                        <span>Responsable</span>
                        <strong><?= hDetalle($caja['usuario_apertura']) ?></strong>
                    </td>
                    <td>
                        <span>Apertura</span>
                        <strong><?= hDetalle(fechaDetalle($caja['fecha_apertura'])) ?></strong>
                    </td>
                    <td>
                        <span>Cierre</span>
                        <strong><?= hDetalle(fechaDetalle($caja['fecha_cierre'], 'En curso')) ?></strong>
                    </td>
                    <td>
                        <span>Resultado</span>
                        <strong><?= hDetalle($esCerrada ? $textoDiferencia : 'Pendiente') ?></strong>
                    </td>
                </tr>
            </table>
        </section>

        <section class="print-section">
            <h2 class="print-section-title">Conciliación de efectivo</h2>
            <div class="print-balance">
                <div class="print-balance-item">
                    <span>Fondo inicial</span>
                    <strong><?= moneyDetalle($caja['monto_inicial']) ?></strong>
                </div>
                <div class="print-balance-item">
                    <span>Efectivo esperado</span>
                    <strong><?= moneyDetalle($resumen['efectivo_esperado']) ?></strong>
                </div>
                <div class="print-balance-item">
                    <span>Efectivo contado</span>
                    <strong><?= $esCerrada ? moneyDetalle($caja['efectivo_contado']) : 'Pendiente' ?></strong>
                </div>
                <div class="print-balance-item">
                    <span>Diferencia</span>
                    <strong><?= $esCerrada ? moneyDetalle($diferencia) : 'Pendiente' ?></strong>
                </div>
            </div>
        </section>

        <section class="print-section">
            <h2 class="print-section-title">Resumen financiero</h2>
            <table class="print-summary-table">
                <thead>
                    <tr>
                        <th>Concepto</th>
                        <th>Operaciones</th>
                        <th>Efectivo</th>
                        <th>Tarjeta</th>
                        <th>Transferencia</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Ventas de productos</td>
                        <td><?= (int) $resumen['ventas']['operaciones'] ?></td>
                        <td><?= moneyDetalle($resumen['ventas']['efectivo']) ?></td>
                        <td><?= moneyDetalle($resumen['ventas']['tarjeta']) ?></td>
                        <td><?= moneyDetalle($resumen['ventas']['transferencia']) ?></td>
                        <td><?= moneyDetalle(
                            (float) $resumen['ventas']['efectivo'] +
                            (float) $resumen['ventas']['tarjeta'] +
                            (float) $resumen['ventas']['transferencia']
                        ) ?></td>
                    </tr>
                    <tr>
                        <td>Pagos de membresías</td>
                        <td><?= (int) $resumen['membresias']['operaciones'] ?></td>
                        <td><?= moneyDetalle($resumen['membresias']['efectivo']) ?></td>
                        <td><?= moneyDetalle($resumen['membresias']['tarjeta']) ?></td>
                        <td><?= moneyDetalle($resumen['membresias']['transferencia']) ?></td>
                        <td><?= moneyDetalle(
                            (float) $resumen['membresias']['efectivo'] +
                            (float) $resumen['membresias']['tarjeta'] +
                            (float) $resumen['membresias']['transferencia']
                        ) ?></td>
                    </tr>
                    <tr>
                        <td>Entradas manuales</td>
                        <td>—</td>
                        <td><?= moneyDetalle($resumen['manuales']['entradas']) ?></td>
                        <td>$0.00</td>
                        <td>$0.00</td>
                        <td><?= moneyDetalle($resumen['manuales']['entradas']) ?></td>
                    </tr>
                    <tr>
                        <td>Salidas manuales</td>
                        <td>—</td>
                        <td>−<?= moneyDetalle($resumen['manuales']['salidas']) ?></td>
                        <td>$0.00</td>
                        <td>$0.00</td>
                        <td>−<?= moneyDetalle($resumen['manuales']['salidas']) ?></td>
                    </tr>
                    <tr>
                        <td>Devoluciones</td>
                        <td><?= (int) $resumen['devoluciones']['operaciones'] ?></td>
                        <td>−<?= moneyDetalle($resumen['devoluciones']['efectivo']) ?></td>
                        <td>−<?= moneyDetalle($resumen['devoluciones']['tarjeta']) ?></td>
                        <td>−<?= moneyDetalle($resumen['devoluciones']['transferencia']) ?></td>
                        <td>−<?= moneyDetalle($resumen['total_devoluciones']) ?></td>
                    </tr>
                    <tr>
                        <th colspan="5">Ingreso neto del turno</th>
                        <th><?= moneyDetalle($resumen['total_neto']) ?></th>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="print-section allow-break">
            <h2 class="print-section-title">Detalle de operaciones (<?= (int) $totalOperaciones ?>)</h2>

            <?php if (!$operacionesImpresion): ?>
                <table class="print-operations-table">
                    <tr>
                        <td style="padding:10px;text-align:center;">El corte no contiene operaciones.</td>
                    </tr>
                </table>
            <?php else: ?>
                <table class="print-operations-table">
                    <thead>
                        <tr>
                            <th class="col-num">No.</th>
                            <th class="col-date">Fecha</th>
                            <th class="col-origin">Origen</th>
                            <th class="col-concept">Concepto</th>
                            <th class="col-method">Método</th>
                            <th class="col-type">Tipo</th>
                            <th class="col-amount">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($operacionesImpresion as $indice => $operacion): ?>
                            <tr>
                                <td class="col-num"><?= (int) $indice + 1 ?></td>
                                <td class="col-date"><?= hDetalle(fechaDetalle($operacion['fecha'])) ?></td>
                                <td class="col-origin"><?= hDetalle($operacion['origen']) ?></td>
                                <td class="col-concept"><?= hDetalle($operacion['concepto']) ?></td>
                                <td class="col-method"><?= hDetalle(ucfirst((string) $operacion['metodo_pago'])) ?></td>
                                <td class="col-type"><?= $operacion['naturaleza'] === 'entrada' ? 'Entrada' : 'Salida' ?></td>
                                <td class="col-amount">
                                    <?= $operacion['naturaleza'] === 'entrada' ? '+' : '−' ?>
                                    <?= moneyDetalle($operacion['monto']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="print-section">
            <h2 class="print-section-title">Observaciones</h2>
            <div class="print-notes">
                <div class="print-note">
                    <strong>Apertura</strong>
                    <?= hDetalle($caja['observaciones_apertura'] ?: 'Sin observaciones.') ?>
                </div>
                <div class="print-note">
                    <strong>Cierre</strong>
                    <?= hDetalle($caja['observaciones_cierre'] ?: 'Sin observaciones.') ?>
                </div>
            </div>
        </section>

        <section class="print-signatures">
            <div class="print-signature">
                <?= hDetalle($caja['usuario_apertura']) ?><br>
                Responsable de caja
            </div>
            <div class="print-signature">
                Nombre y firma<br>
                Revisión / Administración
            </div>
        </section>

        <footer class="print-footer">
            <span>Documento de control interno · <?= hDetalle($caja['folio']) ?></span>
            <span>Generado: <?= hDetalle(date('d/m/Y H:i')) ?></span>
        </footer>
    </main>

    <script>
        window.addEventListener('load', function () {
            window.setTimeout(function () {
                window.print();
            }, 350);
        });
    </script>
</body>
<?php else: ?>
<body>
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content detail-page">
        <div class="detail-shell">
            <div class="detail-toolbar">
                <a href="<?= hDetalle($rutaRegresoCaja) ?>" class="detail-btn detail-btn-soft">
                    <i class="fas fa-arrow-left"></i>
                    Regresar
                </a>

                <div class="detail-toolbar-actions">
                    <?php if ($esAdministradorCaja && $esCerrada): ?>
                        <form method="post" id="formRecalcularCorte" style="margin:0;">
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= hDetalle($_SESSION['csrf_recalcular_corte']) ?>"
                            >
                            <input type="hidden" name="accion" value="recalcular_corte">

                            <button type="submit" class="detail-btn detail-btn-soft">
                                <i class="fas fa-rotate"></i>
                                Recalcular corte
                            </button>
                        </form>
                    <?php endif; ?>

                    <a
                        href="corte_caja_detalle.php?id=<?= $cajaId ?>&imprimir=1&vista=<?= $vistaGlobalCaja ? 'global' : 'sucursal' ?>"
                        target="_blank"
                        rel="noopener"
                        class="detail-btn detail-btn-primary"
                    >
                        <i class="fas fa-file-lines"></i>
                        Imprimir formato
                    </a>
                </div>
            </div>

            <header class="detail-hero detail-card">
                <div class="detail-hero-main">
                    <div>
                        <h1>Corte <?= hDetalle($caja['folio']) ?></h1>
                        <p>Resumen consolidado y auditoría del turno de caja.</p>
                    </div>

                    <div class="detail-hero-badges">
                        <span class="detail-badge branch">
                            <i class="fas fa-building"></i>
                            <?= hDetalle($caja['sucursal_nombre']) ?>
                        </span>

                        <span class="detail-badge closed">
                            <i class="fas <?= $esCerrada ? 'fa-lock' : 'fa-lock-open' ?>"></i>
                            <?= $esCerrada ? 'Caja cerrada' : 'Caja abierta' ?>
                        </span>

                        <?php if ($esCerrada): ?>
                            <span class="detail-badge <?= hDetalle($estadoDiferencia) ?>">
                                <i class="fas fa-scale-balanced"></i>
                                <?= hDetalle($textoDiferencia) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </header>

            <section class="detail-meta">
                <article class="detail-card detail-meta-card">
                    <span class="detail-meta-label">Sucursal</span>
                    <strong class="detail-meta-value">
                        <?= hDetalle($caja['sucursal_nombre']) ?>
                        · <?= hDetalle($caja['sucursal_clave']) ?>
                    </strong>
                </article>

                <article class="detail-card detail-meta-card">
                    <span class="detail-meta-label">Responsable</span>
                    <strong class="detail-meta-value"><?= hDetalle($caja['usuario_apertura']) ?></strong>
                </article>

                <article class="detail-card detail-meta-card">
                    <span class="detail-meta-label">Apertura</span>
                    <strong class="detail-meta-value"><?= hDetalle(fechaDetalle($caja['fecha_apertura'])) ?></strong>
                </article>

                <article class="detail-card detail-meta-card">
                    <span class="detail-meta-label">Cierre</span>
                    <strong class="detail-meta-value"><?= hDetalle(fechaDetalle($caja['fecha_cierre'], 'En curso')) ?></strong>
                </article>

                <article class="detail-card detail-meta-card">
                    <span class="detail-meta-label">Operaciones auditadas</span>
                    <strong class="detail-meta-value"><?= (int) $totalOperaciones ?></strong>
                </article>
            </section>

            <section class="detail-reconciliation">
                <article class="detail-card detail-reconciliation-card">
                    <span>Fondo inicial</span>
                    <strong><?= moneyDetalle($caja['monto_inicial']) ?></strong>
                </article>

                <article class="detail-card detail-reconciliation-card">
                    <span>Efectivo esperado</span>
                    <strong><?= moneyDetalle($resumen['efectivo_esperado']) ?></strong>
                </article>

                <article class="detail-card detail-reconciliation-card">
                    <span>Efectivo contado</span>
                    <strong><?= $esCerrada ? moneyDetalle($caja['efectivo_contado']) : 'Pendiente' ?></strong>
                </article>

                <article class="detail-card detail-reconciliation-card <?= $esCerrada ? hDetalle($estadoDiferencia) : '' ?>">
                    <span>Diferencia</span>
                    <strong><?= $esCerrada ? moneyDetalle($diferencia) : 'Pendiente' ?></strong>
                </article>
            </section>

            <section class="detail-card detail-section">
                <div class="detail-section-head">
                    <div>
                        <h2>Resumen financiero</h2>
                        <p>Ingresos, métodos de pago y movimientos del turno.</p>
                    </div>
                </div>

                <div class="detail-breakdown">
                    <div class="detail-breakdown-box">
                        <h3>Ventas de productos</h3>
                        <div class="detail-row"><span>Efectivo</span><strong><?= moneyDetalle($resumen['ventas']['efectivo']) ?></strong></div>
                        <div class="detail-row"><span>Tarjeta</span><strong><?= moneyDetalle($resumen['ventas']['tarjeta']) ?></strong></div>
                        <div class="detail-row"><span>Transferencia</span><strong><?= moneyDetalle($resumen['ventas']['transferencia']) ?></strong></div>
                        <div class="detail-row"><span>Operaciones</span><strong><?= (int) $resumen['ventas']['operaciones'] ?></strong></div>
                    </div>

                    <div class="detail-breakdown-box">
                        <h3>Pagos de membresías</h3>
                        <div class="detail-row"><span>Efectivo</span><strong><?= moneyDetalle($resumen['membresias']['efectivo']) ?></strong></div>
                        <div class="detail-row"><span>Tarjeta</span><strong><?= moneyDetalle($resumen['membresias']['tarjeta']) ?></strong></div>
                        <div class="detail-row"><span>Transferencia</span><strong><?= moneyDetalle($resumen['membresias']['transferencia']) ?></strong></div>
                        <div class="detail-row"><span>Operaciones</span><strong><?= (int) $resumen['membresias']['operaciones'] ?></strong></div>
                    </div>

                    <div class="detail-breakdown-box">
                        <h3>Ajustes y resultado</h3>
                        <div class="detail-row"><span>Entradas manuales</span><strong><?= moneyDetalle($resumen['manuales']['entradas']) ?></strong></div>
                        <div class="detail-row"><span>Salidas manuales</span><strong><?= moneyDetalle($resumen['manuales']['salidas']) ?></strong></div>
                        <div class="detail-row"><span>Devoluciones</span><strong><?= moneyDetalle($resumen['total_devoluciones']) ?></strong></div>
                        <div class="detail-row"><span>Ingreso neto</span><strong><?= moneyDetalle($resumen['total_neto']) ?></strong></div>
                    </div>
                </div>
            </section>

            <section class="detail-card detail-section">
                <div class="detail-section-head">
                    <div>
                        <h2>Detalle de operaciones</h2>
                        <p>Movimientos que forman parte de este corte.</p>
                    </div>
                    <span class="detail-count"><?= (int) $totalOperaciones ?> registros</span>
                </div>

                <?php if (!$operaciones): ?>
                    <div class="detail-empty">Este corte no contiene operaciones.</div>
                <?php else: ?>
                    <div class="detail-table-wrap">
                        <table class="detail-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Origen</th>
                                    <th>Concepto</th>
                                    <th>Método</th>
                                    <th>Tipo</th>
                                    <th>Monto</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($operaciones as $operacion): ?>
                                    <tr>
                                        <td><?= hDetalle(fechaDetalle($operacion['fecha'])) ?></td>
                                        <td><span class="detail-pill"><?= hDetalle($operacion['origen']) ?></span></td>
                                        <td><?= hDetalle($operacion['concepto']) ?></td>
                                        <td><?= hDetalle(ucfirst((string) $operacion['metodo_pago'])) ?></td>
                                        <td><?= $operacion['naturaleza'] === 'entrada' ? 'Entrada' : 'Salida' ?></td>
                                        <td class="detail-amount <?= hDetalle($operacion['naturaleza']) ?>">
                                            <?= $operacion['naturaleza'] === 'entrada' ? '+' : '−' ?>
                                            <?= moneyDetalle($operacion['monto']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPaginas > 1): ?>
                        <nav class="detail-pagination">
                            <?php for ($pagina = 1; $pagina <= $totalPaginas; $pagina++): ?>
                                <a
                                    class="detail-page-link <?= $pagina === $paginaActual ? 'active' : '' ?>"
                                    href="?id=<?= $cajaId ?>&pagina=<?= $pagina ?>&vista=<?= $vistaGlobalCaja ? 'global' : 'sucursal' ?>"
                                ><?= $pagina ?></a>
                            <?php endfor; ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="detail-card detail-section">
                <div class="detail-section-head">
                    <div>
                        <h2>Observaciones</h2>
                        <p>Notas registradas durante la apertura y el cierre.</p>
                    </div>
                </div>

                <div class="detail-note-grid">
                    <div class="detail-note-box">
                        <strong>Apertura</strong>
                        <?= hDetalle($caja['observaciones_apertura'] ?: 'Sin observaciones de apertura.') ?>
                    </div>

                    <div class="detail-note-box">
                        <strong>Cierre</strong>
                        <?= hDetalle($caja['observaciones_cierre'] ?: 'Sin observaciones de cierre.') ?>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <script>
    (function () {
        const flash = <?= json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        if (flash) {
            let html = flash.mensaje || '';

            if (flash.detalle) {
                html += `
                    <div style="text-align:left;margin-top:14px;line-height:1.8">
                        <div><strong>Esperado:</strong> ${flash.detalle.esperado}</div>
                        <div><strong>Contado:</strong> ${flash.detalle.contado}</div>
                        <div><strong>Diferencia:</strong> ${flash.detalle.diferencia}</div>
                    </div>
                `;
            }

            if (window.Swal) {
                Swal.fire({
                    icon: flash.tipo || 'success',
                    title: flash.titulo || 'Corte de caja',
                    html: html,
                    confirmButtonText: 'Entendido',
                    confirmButtonColor: '#2563eb'
                });
            } else {
                alert((flash.titulo || 'Corte de caja') + '\n' + (flash.mensaje || ''));
            }
        }

        const formRecalcular = document.getElementById('formRecalcularCorte');

        if (formRecalcular) {
            formRecalcular.addEventListener('submit', function (event) {
                if (!window.Swal) {
                    return;
                }

                event.preventDefault();

                Swal.fire({
                    icon: 'question',
                    title: '¿Recalcular este corte?',
                    html: `
                        <div style="text-align:left;line-height:1.65;color:#556176">
                            Se volverán a consultar las ventas y devoluciones comprendidas
                            entre la apertura y el cierre de este turno.
                            <br><br>
                            El efectivo contado no se modificará.
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Sí, recalcular',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#2563eb',
                    cancelButtonColor: '#64748b',
                    reverseButtons: true
                }).then(function (resultado) {
                    if (resultado.isConfirmed) {
                        formRecalcular.submit();
                    }
                });
            });
        }
    })();
    </script>
</body>
<?php endif; ?>
</html>
