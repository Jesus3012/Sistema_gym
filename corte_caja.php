<?php
// VERSION COMPATIBLE PHP 7 - SIN TIPOS UNION - 2026-07-11
// Archivo: corte_caja.php
// Ubicación: raíz de Sistema_gym


require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/caja_helper.php';

date_default_timezone_set('America/Mexico_City');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$usuarioId = (int) ($_SESSION['user_id'] ?? 0);
$usuarioRol = strtolower(trim((string) ($_SESSION['user_rol'] ?? '')));
$usuarioNombre = trim((string) ($_SESSION['user_name'] ?? 'Usuario'));

if ($usuarioId <= 0) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$conn = $database->getConnection();

if (!$conn instanceof mysqli) {
    die('No fue posible establecer conexión con la base de datos.');
}

$conn->set_charset('utf8mb4');

if (empty($_SESSION['csrf_corte_caja'])) {
    $_SESSION['csrf_corte_caja'] = bin2hex(random_bytes(32));
}

function hCaja($valor) {
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function dineroCaja($valor) {
    return '$' . number_format((float) ($valor ?? 0), 2, '.', ',');
}

function validarMontoCaja($valor, $permitirCero = false) {
    $valor = trim($valor);

    if ($valor === '' || !is_numeric($valor)) {
        throw new InvalidArgumentException('Ingresa un monto válido.');
    }

    $monto = round((float) $valor, 2);

    if ($permitirCero ? $monto < 0 : $monto <= 0) {
        throw new InvalidArgumentException(
            $permitirCero
                ? 'El monto no puede ser negativo.'
                : 'El monto debe ser mayor a cero.'
        );
    }

    // Permite 0.00, 0.50, 1.00, 1.50, etc.
    if (abs(($monto * 2) - round($monto * 2)) > 0.00001) {
        throw new InvalidArgumentException('Usa montos en incrementos de $0.50.');
    }

    return $monto;
}

function flashCaja($tipo, $titulo, $mensaje) {
    $_SESSION['caja_flash'] = [
        'tipo' => $tipo,
        'titulo' => $titulo,
        'mensaje' => $mensaje,
    ];

    header('Location: corte_caja.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!hash_equals((string) $_SESSION['csrf_corte_caja'], $token)) {
            throw new RuntimeException('La sesión del formulario expiró. Recarga la página.');
        }

        $accion = trim((string) ($_POST['accion'] ?? ''));

        if ($accion === 'abrir_caja') {
            $montoInicial = validarMontoCaja((string) ($_POST['monto_inicial'] ?? ''), true);
            $observaciones = trim((string) ($_POST['observaciones_apertura'] ?? ''));

            if (mb_strlen($observaciones) > 1000) {
                throw new InvalidArgumentException('Las observaciones son demasiado largas.');
            }

            $conn->begin_transaction();

            $cajaExistente = obtenerCajaAbierta($conn, $usuarioId, true);
            if ($cajaExistente) {
                throw new RuntimeException('Ya tienes una caja abierta con folio ' . $cajaExistente['folio'] . '.');
            }

            $stmt = $conn->prepare(
                "INSERT INTO cajas (
                    usuario_apertura_id,
                    fecha_apertura,
                    monto_inicial,
                    estado,
                    observaciones_apertura
                ) VALUES (?, NOW(), ?, 'abierta', ?)"
            );

            if (!$stmt) {
                throw new RuntimeException('No se pudo preparar la apertura de caja.');
            }

            $stmt->bind_param('ids', $usuarioId, $montoInicial, $observaciones);
            $stmt->execute();
            $cajaId = (int) $conn->insert_id;
            $stmt->close();

            $folio = 'CAJ-' . date('Ymd') . '-' . str_pad((string) $cajaId, 6, '0', STR_PAD_LEFT);
            $stmt = $conn->prepare("UPDATE cajas SET folio = ? WHERE id = ?");
            $stmt->bind_param('si', $folio, $cajaId);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            flashCaja(
                'success',
                'Caja abierta',
                'La caja se abrió correctamente con el folio ' . $folio . '.'
            );
        }

        if ($accion === 'movimiento_manual') {
            $caja = obtenerCajaAbierta($conn, $usuarioId);
            if (!$caja) {
                throw new RuntimeException('Primero debes abrir una caja.');
            }

            $tipo = trim((string) ($_POST['tipo_movimiento'] ?? ''));
            $categoria = trim((string) ($_POST['categoria'] ?? ''));
            $concepto = trim((string) ($_POST['concepto'] ?? ''));
            $monto = validarMontoCaja((string) ($_POST['monto'] ?? ''));

            $categoriasEntrada = ['fondo_adicional', 'ingreso_vario', 'ajuste'];
            $categoriasSalida = ['retiro', 'gasto', 'devolucion_manual', 'ajuste'];

            if ($tipo === 'entrada' && !in_array($categoria, $categoriasEntrada, true)) {
                throw new InvalidArgumentException('La categoría no corresponde a una entrada.');
            }

            if ($tipo === 'salida' && !in_array($categoria, $categoriasSalida, true)) {
                throw new InvalidArgumentException('La categoría no corresponde a una salida.');
            }

            registrarMovimientoManual(
                $conn,
                (int) $caja['id'],
                $usuarioId,
                $tipo,
                $categoria,
                $concepto,
                $monto
            );

            flashCaja(
                'success',
                $tipo === 'entrada' ? 'Entrada registrada' : 'Salida registrada',
                'El movimiento de ' . dineroCaja($monto) . ' se guardó correctamente.'
            );
        }

        if ($accion === 'cerrar_caja') {
            $efectivoContado = validarMontoCaja(
                (string) ($_POST['efectivo_contado'] ?? ''),
                true
            );
            $observaciones = trim((string) ($_POST['observaciones_cierre'] ?? ''));

            if (mb_strlen($observaciones) > 1500) {
                throw new InvalidArgumentException('Las observaciones son demasiado largas.');
            }

            $conn->begin_transaction();

            $caja = obtenerCajaAbierta($conn, $usuarioId, true);
            if (!$caja) {
                throw new RuntimeException('No tienes una caja abierta para cerrar.');
            }

            $fechaCierre = date('Y-m-d H:i:s');
            $resumen = calcularResumenCaja($conn, $caja, $fechaCierre);

            if (!empty($resumen['advertencias'])) {
                throw new RuntimeException(implode(' ', $resumen['advertencias']));
            }

            guardarSnapshotOperacionesCaja($conn, $caja, $fechaCierre);
            $diferencia = round($efectivoContado - $resumen['efectivo_esperado'], 2);

            if (abs($diferencia) < 0.005) {
                $diferencia = 0.00;
            }

            $ventasEfectivo = $resumen['ventas']['efectivo'];
            $ventasTarjeta = $resumen['ventas']['tarjeta'];
            $ventasTransferencia = $resumen['ventas']['transferencia'];
            $membresiasEfectivo = $resumen['membresias']['efectivo'];
            $membresiasTarjeta = $resumen['membresias']['tarjeta'];
            $membresiasTransferencia = $resumen['membresias']['transferencia'];
            $devolucionesEfectivo = $resumen['devoluciones']['efectivo'];
            $devolucionesTarjeta = $resumen['devoluciones']['tarjeta'];
            $devolucionesTransferencia = $resumen['devoluciones']['transferencia'];
            $entradasManuales = $resumen['manuales']['entradas'];
            $salidasManuales = $resumen['manuales']['salidas'];
            $totalBruto = $resumen['total_bruto'];
            $totalDevoluciones = $resumen['total_devoluciones'];
            $totalNeto = $resumen['total_neto'];
            $efectivoEsperado = $resumen['efectivo_esperado'];
            $operacionesVentas = $resumen['ventas']['operaciones'];
            $operacionesMembresias = $resumen['membresias']['operaciones'];
            $operacionesDevoluciones = $resumen['devoluciones']['operaciones'];
            $cajaId = (int) $caja['id'];

            $sql = "UPDATE cajas SET
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
                        efectivo_contado = ?,
                        diferencia = ?,
                        operaciones_ventas = ?,
                        operaciones_membresias = ?,
                        operaciones_devoluciones = ?,
                        usuario_cierre_id = ?,
                        fecha_cierre = ?,
                        observaciones_cierre = ?,
                        estado = 'cerrada'
                    WHERE id = ?
                      AND estado = 'abierta'";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('No se pudo preparar el cierre de caja.');
            }

            $stmt->bind_param(
                'dddddddddddddddddiiiissi',
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
                $efectivoContado,
                $diferencia,
                $operacionesVentas,
                $operacionesMembresias,
                $operacionesDevoluciones,
                $usuarioId,
                $fechaCierre,
                $observaciones,
                $cajaId
            );
            $stmt->execute();

            if ($stmt->affected_rows !== 1) {
                throw new RuntimeException('La caja ya fue cerrada o cambió durante la operación.');
            }

            $stmt->close();
            $conn->commit();

            $_SESSION['caja_flash'] = [
                'tipo' => 'success',
                'titulo' => 'Corte finalizado',
                'mensaje' => 'La caja ' . $caja['folio'] . ' se cerró correctamente.',
                'detalle' => [
                    'esperado' => dineroCaja($efectivoEsperado),
                    'contado' => dineroCaja($efectivoContado),
                    'diferencia' => dineroCaja($diferencia),
                ],
            ];

            header('Location: corte_caja_detalle.php?id=' . $cajaId . '&cerrada=1');
            exit();
        }

        throw new InvalidArgumentException('La acción solicitada no es válida.');
    } catch (Throwable $e) {
        if ($conn->errno === 0) {
            // No siempre existe una transacción activa, por eso se protege el rollback.
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
            }
        } else {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
            }
        }

        $_SESSION['caja_flash'] = [
            'tipo' => 'error',
            'titulo' => 'No fue posible completar la operación',
            'mensaje' => $e->getMessage(),
        ];

        header('Location: corte_caja.php');
        exit();
    }
}

$flash = $_SESSION['caja_flash'] ?? null;
unset($_SESSION['caja_flash']);

$cajaAbierta = obtenerCajaAbierta($conn, $usuarioId);
$resumen = null;
$operaciones = array();
$totalOperaciones = 0;
$totalPaginas = 1;
$errorConsultaCaja = null;
$paginaActual = max(1, (int) (isset($_GET['pagina']) ? $_GET['pagina'] : 1));
$porPagina = 12;

if ($cajaAbierta) {
    try {
        $resumen = calcularResumenCaja($conn, $cajaAbierta);
        $totalOperaciones = contarOperacionesCaja($conn, $cajaAbierta);
        $totalPaginas = max(1, (int) ceil($totalOperaciones / $porPagina));
        $paginaActual = min($paginaActual, $totalPaginas);
        $offset = ($paginaActual - 1) * $porPagina;
        $operaciones = listarOperacionesCaja($conn, $cajaAbierta, $porPagina, $offset);
    } catch (Throwable $errorCaja) {
        $errorConsultaCaja = $errorCaja->getMessage();
    }
}

$cortesRecientes = listarCortesRecientes(
    $conn,
    $usuarioId,
    $usuarioRol === 'admin',
    12
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corte de Caja</title>
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
        referrerpolicy="no-referrer"
    >
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --caja-primary: #0a2540;
            --caja-primary-soft: #edf4fb;
            --caja-blue: #2563eb;
            --caja-green: #17875d;
            --caja-red: #c2414b;
            --caja-orange: #d97706;
            --caja-text: #172033;
            --caja-muted: #667085;
            --caja-border: #dce4ed;
            --caja-bg: #f5f7fa;
            --caja-card: #ffffff;
        }

        .caja-page {
            min-height: 100vh;
            padding: 22px;
            background:
                radial-gradient(circle at top right, rgba(37, 99, 235, .08), transparent 28%),
                var(--caja-bg);
        }

        .caja-shell {
            width: min(1450px, 100%);
            margin: 0 auto;
        }

        .caja-topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            margin-bottom: 18px;
        }

        .caja-heading h1 {
            margin: 0;
            color: var(--caja-primary);
            font-size: clamp(1.6rem, 3vw, 2.15rem);
        }

        .caja-heading p {
            margin: 6px 0 0;
            color: var(--caja-muted);
        }

        .caja-status {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 10px 15px;
            border-radius: 999px;
            font-weight: 800;
            white-space: nowrap;
        }

        .caja-status.abierta {
            color: #126342;
            background: #e8f7f0;
            border: 1px solid #b9e7d1;
        }

        .caja-status.cerrada {
            color: #7b3440;
            background: #fff0f2;
            border: 1px solid #f2c7ce;
        }

        .caja-status-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: currentColor;
            box-shadow: 0 0 0 4px rgba(23, 135, 93, .12);
        }

        .caja-card {
            background: var(--caja-card);
            border: 1px solid var(--caja-border);
            border-radius: 18px;
            box-shadow: 0 12px 34px rgba(15, 36, 58, .07);
        }

        .caja-open-layout {
            display: block;
        }

        .caja-open-panel {
            width: min(820px, 100%);
            margin: 0 auto;
            overflow: hidden;
            border-radius: 16px;
        }

        .caja-open-hero {
            padding: 22px 25px;
            background: linear-gradient(135deg, #0a2540, #173f67);
            color: #fff;
        }

        .caja-open-hero h2 {
            margin: 0 0 6px;
            font-size: 1.32rem;
        }

        .caja-open-hero p {
            margin: 0;
            color: rgba(255,255,255,.78);
            line-height: 1.5;
            font-size: .92rem;
        }

        .caja-form-body {
            padding: 20px 25px 23px;
        }

        .caja-field {
            margin-bottom: 17px;
        }

        .caja-field label {
            display: block;
            margin-bottom: 7px;
            color: var(--caja-text);
            font-size: .9rem;
            font-weight: 800;
        }

        .caja-field input,
        .caja-field select,
        .caja-field textarea {
            width: 100%;
            border: 1px solid #cfd9e5;
            border-radius: 11px;
            padding: 12px 13px;
            background: #fff;
            color: var(--caja-text);
            font: inherit;
            transition: border-color .2s, box-shadow .2s;
        }

        .caja-field input:focus,
        .caja-field select:focus,
        .caja-field textarea:focus {
            outline: none;
            border-color: #6b9bea;
            box-shadow: 0 0 0 4px rgba(37,99,235,.11);
        }

        .caja-field textarea {
            min-height: 74px;
            resize: vertical;
        }

        .caja-open-panel .caja-field {
            margin-bottom: 14px;
        }

        .caja-open-panel .caja-btn {
            min-height: 44px;
        }

        .caja-help {
            display: block;
            margin-top: 6px;
            color: var(--caja-muted);
            font-size: .78rem;
        }

        .caja-btn {
            appearance: none;
            border: 0;
            border-radius: 11px;
            padding: 12px 17px;
            font: inherit;
            font-weight: 800;
            cursor: pointer;
            transition: transform .18s, box-shadow .18s, opacity .18s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .caja-btn:hover {
            transform: translateY(-1px);
        }

        .caja-btn-primary {
            color: #fff;
            background: linear-gradient(135deg, #2563eb, #174db7);
            box-shadow: 0 9px 20px rgba(37,99,235,.2);
        }

        .caja-btn-danger {
            color: #fff;
            background: linear-gradient(135deg, #c2414b, #9f2935);
            box-shadow: 0 9px 20px rgba(194,65,75,.18);
        }

        .caja-btn-soft {
            color: var(--caja-primary);
            background: #eef3f8;
            border: 1px solid #d7e0ea;
        }

        .caja-btn-block {
            width: 100%;
        }

        .caja-info-card {
            padding: 24px;
        }

        .caja-info-card h3,
        .caja-section-title {
            margin: 0 0 14px;
            color: var(--caja-primary);
        }

        .caja-info-list {
            display: grid;
            gap: 12px;
            color: var(--caja-muted);
            line-height: 1.5;
        }

        .caja-info-item {
            display: grid;
            grid-template-columns: 34px 1fr;
            gap: 10px;
            align-items: start;
        }

        .caja-info-number {
            width: 30px;
            height: 30px;
            border-radius: 9px;
            background: var(--caja-primary-soft);
            color: var(--caja-blue);
            display: grid;
            place-items: center;
            font-weight: 900;
        }


        .caja-warning-box,
        .caja-error-box {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 18px;
            padding: 16px 18px;
            border-radius: 14px;
            border: 1px solid #f2c879;
            background: #fff8e8;
            color: #7a4a00;
            box-shadow: 0 8px 24px rgba(122, 74, 0, 0.06);
        }

        .caja-error-box {
            border-color: #efb5bb;
            background: #fff1f2;
            color: #8f2530;
        }

        .caja-warning-box i,
        .caja-error-box i {
            margin-top: 2px;
            font-size: 1.1rem;
        }

        .caja-warning-box strong,
        .caja-error-box strong {
            display: block;
            margin-bottom: 4px;
        }

        .caja-main-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 355px;
            gap: 18px;
            align-items: start;
        }

        .caja-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 13px;
            margin-bottom: 18px;
        }

        .caja-summary-card {
            padding: 18px;
            position: relative;
            overflow: hidden;
        }

        .caja-summary-card::after {
            content: '';
            position: absolute;
            right: -18px;
            top: -18px;
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: rgba(37,99,235,.06);
        }

        .caja-summary-label {
            color: var(--caja-muted);
            font-size: .79rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .caja-summary-value {
            display: block;
            margin-top: 8px;
            color: var(--caja-primary);
            font-size: 1.42rem;
            font-weight: 900;
        }

        .caja-summary-note {
            display: block;
            margin-top: 5px;
            color: var(--caja-muted);
            font-size: .75rem;
        }

        .caja-detail-card,
        .caja-table-card,
        .caja-side-card,
        .caja-history-card {
            padding: 21px;
        }

        .caja-breakdown {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .caja-breakdown-box {
            padding: 15px;
            border: 1px solid var(--caja-border);
            border-radius: 13px;
            background: #fafbfd;
        }

        .caja-breakdown-box h4 {
            margin: 0 0 12px;
            color: var(--caja-primary);
        }

        .caja-breakdown-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 7px 0;
            color: var(--caja-muted);
            border-bottom: 1px dashed #e1e7ee;
        }

        .caja-breakdown-row:last-child {
            border-bottom: 0;
        }

        .caja-breakdown-row strong {
            color: var(--caja-text);
        }

        .caja-side-stack {
            display: grid;
            gap: 18px;
        }

        .caja-side-card h3 {
            margin: 0 0 16px;
            color: var(--caja-primary);
        }

        .caja-table-wrap {
            overflow-x: auto;
        }

        .caja-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }

        .caja-table th,
        .caja-table td {
            padding: 12px 11px;
            text-align: left;
            border-bottom: 1px solid #e7ecf2;
            font-size: .86rem;
        }

        .caja-table th {
            color: #4b5970;
            background: #f7f9fc;
            font-size: .76rem;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .caja-table td {
            color: var(--caja-text);
        }

        .caja-amount {
            font-weight: 900;
            white-space: nowrap;
        }

        .caja-amount.entrada { color: var(--caja-green); }
        .caja-amount.salida { color: var(--caja-red); }

        .caja-pill {
            display: inline-flex;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 800;
            background: #eef3f8;
            color: #42526a;
            white-space: nowrap;
        }

        .caja-empty {
            padding: 30px 15px;
            text-align: center;
            color: var(--caja-muted);
        }

        .caja-pagination {
            display: flex;
            justify-content: flex-end;
            gap: 6px;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        .caja-page-link {
            min-width: 36px;
            height: 36px;
            padding: 0 10px;
            display: grid;
            place-items: center;
            border: 1px solid var(--caja-border);
            border-radius: 9px;
            color: var(--caja-primary);
            text-decoration: none;
            font-weight: 800;
            background: #fff;
        }

        .caja-page-link.active {
            color: #fff;
            background: var(--caja-blue);
            border-color: var(--caja-blue);
        }

        .caja-history-card {
            margin-top: 18px;
        }

        .caja-difference-preview {
            margin: 14px 0 17px;
            padding: 13px;
            border-radius: 11px;
            background: #f5f8fc;
            border: 1px solid #dde6ef;
            color: var(--caja-muted);
            font-size: .88rem;
        }

        .caja-difference-preview strong {
            color: var(--caja-primary);
        }


        /* Historial de cortes cerrados */
        .caja-history-card {
            margin-top: 18px;
            padding: 22px;
        }

        .caja-history-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 14px;
            margin-bottom: 17px;
        }

        .caja-history-head .caja-section-title {
            margin: 0;
        }

        .caja-history-head p {
            margin: 5px 0 0;
            color: var(--caja-muted);
            font-size: .86rem;
        }

        .caja-history-count {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 11px;
            border-radius: 999px;
            color: #42526a;
            background: #eef3f8;
            border: 1px solid #dae3ec;
            font-size: .76rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .caja-history-table-wrap {
            overflow-x: auto;
            border: 1px solid #e1e7ee;
            border-radius: 13px;
            background: #fff;
        }

        .caja-history-table {
            width: 100%;
            min-width: 1050px;
            border-collapse: collapse;
        }

        .caja-history-table th,
        .caja-history-table td {
            padding: 14px 15px;
            text-align: left;
            border-bottom: 1px solid #e8edf3;
            vertical-align: middle;
        }

        .caja-history-table th {
            color: #45546a;
            background: #f5f8fc;
            font-size: .72rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .045em;
            white-space: nowrap;
        }

        .caja-history-table tbody tr {
            transition: background .18s ease;
        }

        .caja-history-table tbody tr:hover {
            background: #f9fbfd;
        }

        .caja-history-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .caja-history-folio {
            color: var(--caja-primary);
            font-weight: 900;
            white-space: nowrap;
        }

        .caja-history-owner {
            color: var(--caja-text);
            font-weight: 700;
            white-space: nowrap;
        }

        .caja-history-date {
            color: #526176;
            font-size: .82rem;
            white-space: nowrap;
        }

        .caja-history-money {
            color: var(--caja-primary);
            font-weight: 850;
            white-space: nowrap;
        }

        .caja-history-difference {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 92px;
            padding: 6px 9px;
            border-radius: 999px;
            font-size: .76rem;
            font-weight: 900;
            white-space: nowrap;
        }

        .caja-history-difference.neutral {
            color: #35644f;
            background: #edf8f2;
            border: 1px solid #cce9da;
        }

        .caja-history-difference.positive {
            color: #9a5a00;
            background: #fff7e8;
            border: 1px solid #f2d39d;
        }

        .caja-history-difference.negative {
            color: #9c2f3b;
            background: #fff0f2;
            border: 1px solid #efc3c9;
        }

        .caja-history-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 9px;
            border-radius: 999px;
            color: #4b5a6f;
            background: #eef2f6;
            border: 1px solid #d9e1e9;
            font-size: .72rem;
            font-weight: 850;
            white-space: nowrap;
        }

        .caja-history-action {
            padding: 9px 12px;
            border-radius: 9px;
            font-size: .78rem;
            white-space: nowrap;
        }

        @media (max-width: 1180px) {
            .caja-main-grid { grid-template-columns: 1fr; }
            .caja-side-stack { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .caja-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 900px) {
            .caja-open-layout { grid-template-columns: 1fr; }
            .caja-breakdown { grid-template-columns: 1fr; }
        }

        @media (max-width: 700px) {
            .caja-page { padding: 15px; }
            .caja-topbar { align-items: flex-start; flex-direction: column; }
            .caja-summary-grid { grid-template-columns: 1fr; }
            .caja-side-stack { grid-template-columns: 1fr; }
            .caja-open-hero { padding: 19px 20px; }
            .caja-form-body { padding: 18px 20px 20px; }
            .caja-history-head { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<main class="main-content caja-page">
    <div class="caja-shell">
        <header class="caja-topbar">
            <div class="caja-heading">
                <h1>Corte de Caja</h1>
                <p>Administra la apertura, los movimientos y el cierre de cada turno.</p>
            </div>

            <span class="caja-status <?= $cajaAbierta ? 'abierta' : 'cerrada' ?>">
                <span class="caja-status-dot"></span>
                <?= $cajaAbierta ? 'Caja abierta' : 'Sin caja abierta' ?>
            </span>
        </header>

        <?php if (!$cajaAbierta): ?>
            <section class="caja-open-layout">
                <article class="caja-card caja-open-panel">
                    <div class="caja-open-hero">
                        <h2>Iniciar un nuevo turno</h2>
                        <p>
                            Desde la apertura se contabilizarán automáticamente las operaciones registradas por
                            <?= hCaja($usuarioNombre) ?>.
                        </p>
                    </div>

                    <form method="post" class="caja-form-body">
                        <input type="hidden" name="csrf_token" value="<?= hCaja($_SESSION['csrf_corte_caja']) ?>">
                        <input type="hidden" name="accion" value="abrir_caja">

                        <div class="caja-field">
                            <label for="monto_inicial">Fondo inicial en efectivo</label>
                            <input
                                type="number"
                                id="monto_inicial"
                                name="monto_inicial"
                                min="0"
                                step="0.50"
                                value="0.00"
                                required
                            >
                            <small class="caja-help">Puedes capturar cantidades cerradas o medios pesos, por ejemplo 500.00 o 500.50.</small>
                        </div>

                        <div class="caja-field">
                            <label for="observaciones_apertura">Observaciones de apertura</label>
                            <textarea
                                id="observaciones_apertura"
                                name="observaciones_apertura"
                                maxlength="1000"
                                placeholder="Opcional: estado del fondo, cambio disponible, responsable, etc."
                            ></textarea>
                        </div>

                        <button type="submit" class="caja-btn caja-btn-primary caja-btn-block">
                            <i class="fas fa-cash-register"></i>
                            Abrir caja
                        </button>
                    </form>
                </article>
            </section>
        <?php elseif ($errorConsultaCaja): ?>
            <section class="caja-error-box">
                <i class="fas fa-triangle-exclamation"></i>
                <div>
                    <strong>No fue posible calcular el corte de caja</strong>
                    <span><?= hCaja($errorConsultaCaja) ?></span>
                </div>
            </section>
        <?php else: ?>
            <?php if (!empty($resumen['advertencias'])): ?>
                <section class="caja-warning-box">
                    <i class="fas fa-circle-exclamation"></i>
                    <div>
                        <strong>Revisión necesaria</strong>
                        <span><?= hCaja(implode(' ', $resumen['advertencias'])) ?></span>
                    </div>
                </section>
            <?php endif; ?>

            <section class="caja-summary-grid">
                <article class="caja-card caja-summary-card">
                    <span class="caja-summary-label">Efectivo esperado</span>
                    <strong class="caja-summary-value"><?= dineroCaja($resumen['efectivo_esperado']) ?></strong>
                    <small class="caja-summary-note">Incluye fondo inicial y movimientos en efectivo.</small>
                </article>

                <article class="caja-card caja-summary-card">
                    <span class="caja-summary-label">Ingresos netos</span>
                    <strong class="caja-summary-value"><?= dineroCaja($resumen['total_neto']) ?></strong>
                    <small class="caja-summary-note">Productos + membresías − devoluciones.</small>
                </article>

                <article class="caja-card caja-summary-card">
                    <span class="caja-summary-label">Tarjeta neta</span>
                    <strong class="caja-summary-value"><?= dineroCaja($resumen['total_tarjeta_neto']) ?></strong>
                    <small class="caja-summary-note">No forma parte del efectivo físico.</small>
                </article>

                <article class="caja-card caja-summary-card">
                    <span class="caja-summary-label">Transferencias netas</span>
                    <strong class="caja-summary-value"><?= dineroCaja($resumen['total_transferencia_neto']) ?></strong>
                    <small class="caja-summary-note"><?= (int) $resumen['operaciones'] ?> operaciones detectadas.</small>
                </article>
            </section>

            <section class="caja-main-grid">
                <div>
                    <article class="caja-card caja-detail-card">
                        <h2 class="caja-section-title">Resumen del turno <?= hCaja($cajaAbierta['folio']) ?></h2>

                        <div class="caja-breakdown">
                            <div class="caja-breakdown-box">
                                <h4>Ventas de productos</h4>
                                <div class="caja-breakdown-row"><span>Efectivo</span><strong><?= dineroCaja($resumen['ventas']['efectivo']) ?></strong></div>
                                <div class="caja-breakdown-row"><span>Tarjeta</span><strong><?= dineroCaja($resumen['ventas']['tarjeta']) ?></strong></div>
                                <div class="caja-breakdown-row"><span>Transferencia</span><strong><?= dineroCaja($resumen['ventas']['transferencia']) ?></strong></div>
                                <div class="caja-breakdown-row"><span>Operaciones</span><strong><?= (int) $resumen['ventas']['operaciones'] ?></strong></div>
                            </div>

                            <div class="caja-breakdown-box">
                                <h4>Pagos de membresías</h4>
                                <div class="caja-breakdown-row"><span>Efectivo</span><strong><?= dineroCaja($resumen['membresias']['efectivo']) ?></strong></div>
                                <div class="caja-breakdown-row"><span>Tarjeta</span><strong><?= dineroCaja($resumen['membresias']['tarjeta']) ?></strong></div>
                                <div class="caja-breakdown-row"><span>Transferencia</span><strong><?= dineroCaja($resumen['membresias']['transferencia']) ?></strong></div>
                                <div class="caja-breakdown-row"><span>Operaciones</span><strong><?= (int) $resumen['membresias']['operaciones'] ?></strong></div>
                            </div>

                            <div class="caja-breakdown-box">
                                <h4>Ajustes y devoluciones</h4>
                                <div class="caja-breakdown-row"><span>Entradas manuales</span><strong><?= dineroCaja($resumen['manuales']['entradas']) ?></strong></div>
                                <div class="caja-breakdown-row"><span>Salidas manuales</span><strong><?= dineroCaja($resumen['manuales']['salidas']) ?></strong></div>
                                <div class="caja-breakdown-row"><span>Devoluciones efectivo</span><strong><?= dineroCaja($resumen['devoluciones']['efectivo']) ?></strong></div>
                                <div class="caja-breakdown-row"><span>Fondo inicial</span><strong><?= dineroCaja($resumen['monto_inicial']) ?></strong></div>
                            </div>
                        </div>
                    </article>

                    <article class="caja-card caja-table-card" style="margin-top:18px;">
                        <h2 class="caja-section-title">Operaciones del turno</h2>

                        <?php if (!$operaciones): ?>
                            <div class="caja-empty">Todavía no hay operaciones registradas durante este turno.</div>
                        <?php else: ?>
                            <div class="caja-table-wrap">
                                <table class="caja-table">
                                    <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Origen</th>
                                        <th>Concepto</th>
                                        <th>Método</th>
                                        <th>Movimiento</th>
                                        <th>Monto</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($operaciones as $operacion): ?>
                                        <tr>
                                            <td><?= hCaja(date('d/m/Y H:i', strtotime((string) $operacion['fecha']))) ?></td>
                                            <td><span class="caja-pill"><?= hCaja($operacion['origen']) ?></span></td>
                                            <td><?= hCaja($operacion['concepto']) ?></td>
                                            <td><?= hCaja(ucfirst((string) $operacion['metodo_pago'])) ?></td>
                                            <td><?= $operacion['naturaleza'] === 'entrada' ? 'Entrada' : 'Salida' ?></td>
                                            <td class="caja-amount <?= hCaja($operacion['naturaleza']) ?>">
                                                <?= $operacion['naturaleza'] === 'entrada' ? '+' : '−' ?><?= dineroCaja($operacion['monto']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($totalPaginas > 1): ?>
                                <nav class="caja-pagination" aria-label="Paginación de operaciones">
                                    <?php for ($pagina = 1; $pagina <= $totalPaginas; $pagina++): ?>
                                        <a
                                            href="?pagina=<?= $pagina ?>"
                                            class="caja-page-link <?= $pagina === $paginaActual ? 'active' : '' ?>"
                                        ><?= $pagina ?></a>
                                    <?php endfor; ?>
                                </nav>
                            <?php endif; ?>
                        <?php endif; ?>
                    </article>
                </div>

                <aside class="caja-side-stack">
                    <article class="caja-card caja-side-card">
                        <h3>Registrar movimiento manual</h3>
                        <form method="post" id="formMovimiento">
                            <input type="hidden" name="csrf_token" value="<?= hCaja($_SESSION['csrf_corte_caja']) ?>">
                            <input type="hidden" name="accion" value="movimiento_manual">

                            <div class="caja-field">
                                <label for="tipo_movimiento">Tipo</label>
                                <select id="tipo_movimiento" name="tipo_movimiento" required>
                                    <option value="entrada">Entrada de efectivo</option>
                                    <option value="salida">Salida de efectivo</option>
                                </select>
                            </div>

                            <div class="caja-field">
                                <label for="categoria">Categoría</label>
                                <select id="categoria" name="categoria" required></select>
                            </div>

                            <div class="caja-field">
                                <label for="monto">Monto</label>
                                <input type="number" id="monto" name="monto" min="0.50" step="0.50" required>
                            </div>

                            <div class="caja-field">
                                <label for="concepto">Concepto</label>
                                <textarea id="concepto" name="concepto" maxlength="255" required placeholder="Describe claramente el motivo."></textarea>
                            </div>

                            <button type="submit" class="caja-btn caja-btn-soft caja-btn-block">
                                Guardar movimiento
                            </button>
                        </form>
                    </article>

                    <article class="caja-card caja-side-card">
                        <h3>Cerrar caja</h3>
                        <form method="post" id="formCerrarCaja">
                            <input type="hidden" name="csrf_token" value="<?= hCaja($_SESSION['csrf_corte_caja']) ?>">
                            <input type="hidden" name="accion" value="cerrar_caja">

                            <div class="caja-field">
                                <label for="efectivo_contado">Efectivo contado</label>
                                <input
                                    type="number"
                                    id="efectivo_contado"
                                    name="efectivo_contado"
                                    min="0"
                                    step="0.50"
                                    required
                                    data-esperado="<?= hCaja($resumen['efectivo_esperado']) ?>"
                                >
                                <small class="caja-help">Cuenta físicamente el dinero disponible en el cajón.</small>
                            </div>

                            <div class="caja-difference-preview" id="differencePreview">
                                Esperado: <strong><?= dineroCaja($resumen['efectivo_esperado']) ?></strong><br>
                                Diferencia: <strong>—</strong>
                            </div>

                            <div class="caja-field">
                                <label for="observaciones_cierre">Observaciones</label>
                                <textarea id="observaciones_cierre" name="observaciones_cierre" maxlength="1500" placeholder="Opcional: explica faltantes, sobrantes o incidencias."></textarea>
                            </div>

                            <button type="submit" class="caja-btn caja-btn-danger caja-btn-block">
                                <i class="fas fa-lock"></i>
                                Realizar corte y cerrar
                            </button>
                        </form>
                    </article>
                </aside>
            </section>
        <?php endif; ?>

        <section class="caja-card caja-history-card">
            <div class="caja-history-head">
                <div>
                    <h2 class="caja-section-title">Historial de cortes</h2>
                    <p>Consulta las cajas cerradas y revisa sus importes principales.</p>
                </div>

                <?php if ($cortesRecientes): ?>
                    <span class="caja-history-count">
                        <i class="fas fa-box-archive"></i>
                        <?= count($cortesRecientes) ?> cortes
                    </span>
                <?php endif; ?>
            </div>

            <?php if (!$cortesRecientes): ?>
                <div class="caja-empty">Aún no existen cortes cerrados.</div>
            <?php else: ?>
                <div class="caja-history-table-wrap">
                    <table class="caja-history-table">
                        <thead>
                        <tr>
                            <th>Folio</th>
                            <th>Responsable</th>
                            <th>Apertura</th>
                            <th>Cierre</th>
                            <th>Esperado</th>
                            <th>Contado</th>
                            <th>Diferencia</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cortesRecientes as $corte): ?>
                            <?php
                            $diferenciaCorte = round((float) ($corte['diferencia'] ?? 0), 2);

                            if (abs($diferenciaCorte) < 0.005) {
                                $claseDiferencia = 'neutral';
                            } elseif ($diferenciaCorte > 0) {
                                $claseDiferencia = 'positive';
                            } else {
                                $claseDiferencia = 'negative';
                            }
                            ?>
                            <tr>
                                <td>
                                    <span class="caja-history-folio">
                                        <?= hCaja($corte['folio']) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="caja-history-owner">
                                        <?= hCaja($corte['usuario_apertura']) ?>
                                    </span>
                                </td>

                                <td class="caja-history-date">
                                    <?= hCaja(date('d/m/Y H:i', strtotime((string) $corte['fecha_apertura']))) ?>
                                </td>

                                <td class="caja-history-date">
                                    <?= hCaja(date('d/m/Y H:i', strtotime((string) $corte['fecha_cierre']))) ?>
                                </td>

                                <td class="caja-history-money">
                                    <?= dineroCaja($corte['efectivo_esperado']) ?>
                                </td>

                                <td class="caja-history-money">
                                    <?= dineroCaja($corte['efectivo_contado']) ?>
                                </td>

                                <td>
                                    <span class="caja-history-difference <?= hCaja($claseDiferencia) ?>">
                                        <?= dineroCaja($diferenciaCorte) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="caja-history-status">
                                        <i class="fas fa-lock"></i>
                                        Cerrada
                                    </span>
                                </td>

                                <td>
                                    <a
                                        class="caja-btn caja-btn-soft caja-history-action"
                                        href="corte_caja_detalle.php?id=<?= (int) $corte['id'] ?>"
                                    >
                                        Ver detalle
                                        <i class="fas fa-arrow-right"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<script>
(function () {
    const flash = <?= json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    if (flash) {
        const config = {
            icon: flash.tipo || 'info',
            title: flash.titulo || 'Corte de caja',
            text: flash.mensaje || '',
            confirmButtonText: 'Entendido',
            confirmButtonColor: '#2563eb'
        };

        if (window.Swal) {
            Swal.fire(config);
        } else {
            alert((config.title ? config.title + '\n' : '') + config.text);
        }
    }

    const tipoMovimiento = document.getElementById('tipo_movimiento');
    const categoria = document.getElementById('categoria');

    const opciones = {
        entrada: [
            ['fondo_adicional', 'Fondo adicional'],
            ['ingreso_vario', 'Ingreso diverso'],
            ['ajuste', 'Ajuste de entrada']
        ],
        salida: [
            ['retiro', 'Retiro de efectivo'],
            ['gasto', 'Gasto operativo'],
            ['devolucion_manual', 'Devolución manual'],
            ['ajuste', 'Ajuste de salida']
        ]
    };

    function actualizarCategorias() {
        if (!tipoMovimiento || !categoria) return;
        categoria.innerHTML = '';

        (opciones[tipoMovimiento.value] || []).forEach(([value, label]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            categoria.appendChild(option);
        });
    }

    if (tipoMovimiento) {
        tipoMovimiento.addEventListener('change', actualizarCategorias);
        actualizarCategorias();
    }

    const efectivoContado = document.getElementById('efectivo_contado');
    const differencePreview = document.getElementById('differencePreview');

    function formatoDinero(valor) {
        return new Intl.NumberFormat('es-MX', {
            style: 'currency',
            currency: 'MXN'
        }).format(valor || 0);
    }

    function actualizarDiferencia() {
        if (!efectivoContado || !differencePreview) return;

        const esperado = Number(efectivoContado.dataset.esperado || 0);
        const contado = Number(efectivoContado.value);
        const diferencia = Number.isFinite(contado) ? contado - esperado : null;

        differencePreview.innerHTML = `
            Esperado: <strong>${formatoDinero(esperado)}</strong><br>
            Diferencia: <strong>${diferencia === null ? '—' : formatoDinero(diferencia)}</strong>
        `;
    }

    if (efectivoContado) {
        efectivoContado.addEventListener('input', actualizarDiferencia);
    }

    const formCerrar = document.getElementById('formCerrarCaja');
    if (formCerrar) {
        formCerrar.addEventListener('submit', function (event) {
            if (!window.Swal) return;

            event.preventDefault();
            const esperado = Number(efectivoContado?.dataset.esperado || 0);
            const contado = Number(efectivoContado?.value || 0);
            const diferencia = contado - esperado;

            Swal.fire({
                icon: 'warning',
                title: '¿Cerrar la caja?',
                html: `
                    <div style="text-align:left;line-height:1.7">
                        <div><strong>Efectivo esperado:</strong> ${formatoDinero(esperado)}</div>
                        <div><strong>Efectivo contado:</strong> ${formatoDinero(contado)}</div>
                        <div><strong>Diferencia:</strong> ${formatoDinero(diferencia)}</div>
                        <p style="margin:12px 0 0;color:#667085">Después del cierre, los totales quedarán congelados para auditoría.</p>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Sí, cerrar caja',
                cancelButtonText: 'Revisar',
                confirmButtonColor: '#c2414b',
                cancelButtonColor: '#64748b',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    formCerrar.submit();
                }
            });
        });
    }
})();
</script>
</body>
</html>
