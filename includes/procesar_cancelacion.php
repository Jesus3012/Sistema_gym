<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/sucursal_context.php';
require_once __DIR__ . '/mercadopago_service.php';
require_once __DIR__ . '/ventas_operaciones_multisucursal.php';

function responderCancelacion(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($_SESSION['user_id'])) {
    responderCancelacion(false, 'No autorizado.', [], 401);
}

if (!in_array(ventas_multi_rol_base(), ['admin', 'recepcionista'], true)) {
    responderCancelacion(false, 'No tienes permiso para cancelar ventas.', [], 403);
}

$input = json_decode(file_get_contents('php://input'), true);
$ventaId = is_array($input) ? (int) ($input['venta_id'] ?? 0) : 0;
if ($ventaId <= 0) {
    responderCancelacion(false, 'ID de venta inválido.', [], 400);
}

$database = new Database();
$conn = $database->getConnection();
if (!$conn instanceof mysqli) {
    responderCancelacion(false, 'No fue posible conectar con la base de datos.', [], 500);
}
$conn->set_charset('utf8mb4');

$tx = false;
try {
    $conn->begin_transaction();
    $tx = true;

    $venta = ventas_multi_obtener_venta($conn, $ventaId, true);
    ventas_multi_validar_acceso($venta);

    if ($venta['estado'] === 'cancelada') {
        throw new RuntimeException('La venta ya está cancelada.');
    }
    if ($venta['estado'] !== 'completada') {
        throw new RuntimeException('Solo se pueden cancelar ventas completadas.');
    }
    if ((float) $venta['total'] <= 0) {
        throw new RuntimeException('La venta ya no tiene saldo para cancelar.');
    }

    ventas_multi_validar_plazo($conn, $venta, 'cancelacion');

    $usuarioId = (int) $_SESSION['user_id'];
    $caja = ventas_multi_obtener_caja_abierta(
        $conn,
        (int) $venta['sucursal_id'],
        $usuarioId
    );

    $stmt = $conn->prepare(
        "SELECT producto_id, cantidad
         FROM detalle_ventas
         WHERE venta_id = ?
         FOR UPDATE"
    );
    $stmt->bind_param('i', $ventaId);
    $stmt->execute();
    $detalles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($detalles === []) {
        throw new RuntimeException('La venta ya no tiene productos pendientes por devolver.');
    }

    /*
     * Se bloquea y valida todo el inventario antes de solicitar el
     * reembolso remoto. Así un problema local no ocurre después del pago.
     */
    foreach ($detalles as $detalle) {
        ventas_multi_bloquear_inventario(
            $conn,
            (int) $venta['sucursal_id'],
            (int) $detalle['producto_id']
        );
    }

    $reembolsoMp = mp_refund_sale_if_needed($conn, $ventaId, null);

    $productos = [];
    foreach ($detalles as $detalle) {
        $productoId = (int) $detalle['producto_id'];
        $cantidad = (int) $detalle['cantidad'];
        ventas_multi_reponer_stock(
            $conn,
            (int) $venta['sucursal_id'],
            $productoId,
            $cantidad,
            $usuarioId,
            $ventaId,
            'Cancelación de venta',
            'cancelacion_venta',
            'Cancelación total de la venta #' . $ventaId
        );
        $productos[] = ['producto_id' => $productoId, 'cantidad' => $cantidad];
    }

    $update = $conn->prepare(
        "UPDATE ventas
         SET estado = 'cancelada'
         WHERE id = ? AND estado = 'completada'"
    );
    $update->bind_param('i', $ventaId);
    $update->execute();
    if ($update->affected_rows !== 1) {
        $update->close();
        throw new RuntimeException('La venta cambió mientras se procesaba la cancelación.');
    }
    $update->close();

    $descripcion = is_array($reembolsoMp)
        ? 'Venta cancelada y reembolsada en Mercado Pago'
        : 'Venta cancelada';
    $productosJson = json_encode($productos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    $modificacionId = ventas_multi_registrar_modificacion(
        $conn,
        $ventaId,
        $usuarioId,
        'cancelacion',
        $descripcion,
        (float) $venta['total'],
        $productosJson
    );

    ventas_multi_registrar_caja(
        $conn,
        (int) $caja['id'],
        $modificacionId,
        $ventaId,
        (string) $venta['metodo_pago'],
        (float) $venta['total'],
        'Cancelación de venta',
        'Venta #' . $ventaId . ' · ' . $descripcion
    );

    $conn->commit();
    $tx = false;

    responderCancelacion(true, $descripcion . ' correctamente.', [
        'sucursal_id' => (int) $venta['sucursal_id'],
        'sucursal_nombre' => (string) $venta['sucursal_nombre'],
        'stock_restaurado' => true,
        'reembolso_mercadopago' => $reembolsoMp !== null,
        'refund_id' => is_array($reembolsoMp) ? ($reembolsoMp['refund_id'] ?? null) : null,
        'refund_status' => is_array($reembolsoMp) ? ($reembolsoMp['refund_status'] ?? null) : null,
        'monto_reembolsado' => is_array($reembolsoMp) ? ($reembolsoMp['amount'] ?? null) : (float) $venta['total'],
    ]);
} catch (Throwable $error) {
    if ($tx) {
        $conn->rollback();
    }
    error_log('[procesar_cancelacion #' . $ventaId . '] ' . $error->getMessage());
    responderCancelacion(false, $error->getMessage(), [], 409);
}
