<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/sucursal_context.php';
require_once __DIR__ . '/mercadopago_service.php';
require_once __DIR__ . '/ventas_operaciones_multisucursal.php';

function responderDevolucion(bool $success, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($_SESSION['user_id'])) {
    responderDevolucion(false, 'No autorizado.', [], 401);
}
if (!in_array(ventas_multi_rol_base(), ['admin', 'recepcionista'], true)) {
    responderDevolucion(false, 'No tienes permiso para procesar devoluciones.', [], 403);
}

$input = json_decode(file_get_contents('php://input'), true);
$ventaId = is_array($input) ? (int) ($input['venta_id'] ?? 0) : 0;
$productoId = is_array($input) ? (int) ($input['producto_id'] ?? 0) : 0;
$cantidad = is_array($input) ? (int) ($input['cantidad'] ?? 0) : 0;
$motivo = is_array($input) ? trim((string) ($input['motivo'] ?? '')) : '';

if ($ventaId <= 0 || $productoId <= 0 || $cantidad <= 0) {
    responderDevolucion(false, 'Datos inválidos.', [], 400);
}
if ($motivo === '') {
    responderDevolucion(false, 'Debe ingresar un motivo para la devolución.', [], 400);
}

$database = new Database();
$conn = $database->getConnection();
if (!$conn instanceof mysqli) {
    responderDevolucion(false, 'No fue posible conectar con la base de datos.', [], 500);
}
$conn->set_charset('utf8mb4');

$tx = false;
try {
    $conn->begin_transaction();
    $tx = true;

    $venta = ventas_multi_obtener_venta($conn, $ventaId, true);
    ventas_multi_validar_acceso($venta);

    if ($venta['estado'] !== 'completada') {
        throw new RuntimeException('Solo se pueden devolver productos de ventas completadas.');
    }

    ventas_multi_validar_plazo($conn, $venta, 'devolucion');

    $usuarioId = (int) $_SESSION['user_id'];
    $caja = ventas_multi_obtener_caja_abierta(
        $conn,
        (int) $venta['sucursal_id'],
        $usuarioId
    );

    $stmt = $conn->prepare(
        "SELECT cantidad, precio_unitario, subtotal
         FROM detalle_ventas
         WHERE venta_id = ? AND producto_id = ?
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->bind_param('ii', $ventaId, $productoId);
    $stmt->execute();
    $detalle = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($detalle)) {
        throw new RuntimeException('El producto ya no está disponible para devolución en esta venta.');
    }

    $cantidadDisponible = (int) $detalle['cantidad'];
    if ($cantidad > $cantidadDisponible) {
        throw new RuntimeException(
            'La cantidad excede las unidades pendientes de devolución (máximo: ' .
            $cantidadDisponible . ').'
        );
    }

    $precio = round((float) $detalle['precio_unitario'], 2);
    $monto = round($precio * $cantidad, 2);
    if ($monto <= 0 || $monto > (float) $venta['total'] + 0.01) {
        throw new RuntimeException('El monto calculado para la devolución no es válido.');
    }

    ventas_multi_bloquear_inventario(
        $conn,
        (int) $venta['sucursal_id'],
        $productoId
    );

    $reembolsoMp = mp_refund_sale_if_needed($conn, $ventaId, $monto);

    if ($cantidad === $cantidadDisponible) {
        $updateDetalle = $conn->prepare(
            "DELETE FROM detalle_ventas
             WHERE venta_id = ? AND producto_id = ?"
        );
        $updateDetalle->bind_param('ii', $ventaId, $productoId);
    } else {
        $updateDetalle = $conn->prepare(
            "UPDATE detalle_ventas
             SET cantidad = cantidad - ?,
                 subtotal = subtotal - ?
             WHERE venta_id = ? AND producto_id = ?"
        );
        $updateDetalle->bind_param('idii', $cantidad, $monto, $ventaId, $productoId);
    }
    $updateDetalle->execute();
    $updateDetalle->close();

    $updateVenta = $conn->prepare(
        "UPDATE ventas
         SET total = GREATEST(total - ?, 0)
         WHERE id = ? AND estado = 'completada'"
    );
    $updateVenta->bind_param('di', $monto, $ventaId);
    $updateVenta->execute();
    $updateVenta->close();

    ventas_multi_reponer_stock(
        $conn,
        (int) $venta['sucursal_id'],
        $productoId,
        $cantidad,
        $usuarioId,
        $ventaId,
        'Devolución parcial de venta',
        'devolucion_venta',
        'Venta #' . $ventaId . ' · Motivo: ' . $motivo
    );

    $productosJson = json_encode([[
        'producto_id' => $productoId,
        'cantidad' => $cantidad,
    ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';

    $descripcion = 'Devolución de ' . $cantidad .
        ' unidad(es) · Motivo: ' . $motivo;
    $modificacionId = ventas_multi_registrar_modificacion(
        $conn,
        $ventaId,
        $usuarioId,
        'devolucion_parcial',
        $descripcion,
        $monto,
        $productosJson
    );

    ventas_multi_registrar_caja(
        $conn,
        (int) $caja['id'],
        $modificacionId,
        $ventaId,
        (string) $venta['metodo_pago'],
        $monto,
        'Devolución parcial',
        'Venta #' . $ventaId . ' · ' . $descripcion
    );

    $conn->commit();
    $tx = false;

    responderDevolucion(true,
        'Devolución procesada correctamente. Monto devuelto: $' . number_format($monto, 2),
        [
            'sucursal_id' => (int) $venta['sucursal_id'],
            'sucursal_nombre' => (string) $venta['sucursal_nombre'],
            'stock_restaurado' => true,
            'monto_devuelto' => $monto,
            'reembolso_mercadopago' => $reembolsoMp !== null,
            'refund_id' => is_array($reembolsoMp) ? ($reembolsoMp['refund_id'] ?? null) : null,
            'refund_status' => is_array($reembolsoMp) ? ($reembolsoMp['refund_status'] ?? null) : null,
        ]
    );
} catch (Throwable $error) {
    if ($tx) {
        $conn->rollback();
    }
    error_log('[procesar_devolucion #' . $ventaId . '] ' . $error->getMessage());
    responderDevolucion(false, $error->getMessage(), [], 409);
}
