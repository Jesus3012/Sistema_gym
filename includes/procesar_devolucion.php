<?php
// Archivo: includes/procesar_devolucion.php
// Procesa una devolución parcial local y, cuando corresponde,
// ejecuta primero el reembolso parcial en Mercado Pago Point.

session_start();
header('Content-Type: application/json; charset=utf-8');

function responderDevolucion(bool $success, string $message, array $extra = []): void
{
    echo json_encode(
        array_merge([
            'success' => $success,
            'message' => $message,
        ], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit();
}

if (!isset($_SESSION['user_id'])) {
    responderDevolucion(false, 'No autorizado');
}

if (
    !isset($_SESSION['user_rol']) ||
    !in_array($_SESSION['user_rol'], ['admin', 'recepcionista'], true)
) {
    responderDevolucion(
        false,
        'No tienes permiso para procesar devoluciones'
    );
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mercadopago_service.php';
require_once __DIR__ . '/devoluciones_config.php';

$database = new Database();
$conn = $database->getConnection();

if (!$conn instanceof mysqli) {
    responderDevolucion(
        false,
        'No fue posible conectar con la base de datos'
    );
}

$conn->set_charset('utf8mb4');

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    responderDevolucion(false, 'La solicitud JSON no es válida');
}

$ventaId = isset($input['venta_id'])
    ? (int) $input['venta_id']
    : 0;

$productoId = isset($input['producto_id'])
    ? (int) $input['producto_id']
    : 0;

$cantidad = isset($input['cantidad'])
    ? (int) $input['cantidad']
    : 0;

$motivo = trim((string) ($input['motivo'] ?? ''));

if ($ventaId <= 0 || $productoId <= 0 || $cantidad <= 0) {
    responderDevolucion(false, 'Datos inválidos');
}

if ($motivo === '') {
    responderDevolucion(
        false,
        'Debe ingresar un motivo para la devolución'
    );
}

$transaccionIniciada = false;

try {
    $conn->begin_transaction();
    $transaccionIniciada = true;

    /*
     * Bloquear la venta para impedir dos devoluciones simultáneas.
     */
    $stmtVenta = $conn->prepare(
        "SELECT id, estado, metodo_pago, total
         FROM ventas
         WHERE id = ?
         LIMIT 1
         FOR UPDATE"
    );

    if (!$stmtVenta) {
        throw new RuntimeException(
            'No se pudo preparar la consulta de la venta: ' .
            $conn->error
        );
    }

    $stmtVenta->bind_param('i', $ventaId);
    $stmtVenta->execute();
    $venta = $stmtVenta->get_result()->fetch_assoc();
    $stmtVenta->close();

    if (!$venta) {
        throw new RuntimeException('Venta no encontrada');
    }

    if ($venta['estado'] !== 'completada') {
        throw new RuntimeException(
            'Solo se pueden devolver productos de ventas completadas'
        );
    }

    $plazoOperacion = devoluciones_validar_plazo_venta(
        $conn,
        $ventaId,
        'devolucion'
    );

    /*
     * Bloquear el detalle para impedir devolver más unidades que las
     * actualmente disponibles en la venta.
     */
    $stmtDetalle = $conn->prepare(
        "SELECT cantidad, precio_unitario, subtotal
         FROM detalle_ventas
         WHERE venta_id = ? AND producto_id = ?
         LIMIT 1
         FOR UPDATE"
    );

    if (!$stmtDetalle) {
        throw new RuntimeException(
            'No se pudo preparar la consulta del producto vendido: ' .
            $conn->error
        );
    }

    $stmtDetalle->bind_param('ii', $ventaId, $productoId);
    $stmtDetalle->execute();
    $detalle = $stmtDetalle->get_result()->fetch_assoc();
    $stmtDetalle->close();

    if (!$detalle) {
        throw new RuntimeException(
            'Producto no encontrado en esta venta'
        );
    }

    $cantidadVendida = (int) $detalle['cantidad'];

    if ($cantidad > $cantidadVendida) {
        throw new RuntimeException(
            'Cantidad a devolver excede la cantidad disponible ' .
            '(máximo: ' . $cantidadVendida . ')'
        );
    }

    $precioUnitario = round(
        (float) $detalle['precio_unitario'],
        2
    );

    $montoDevuelto = round(
        $precioUnitario * $cantidad,
        2
    );

    if ($montoDevuelto <= 0) {
        throw new RuntimeException(
            'El monto de devolución calculado no es válido'
        );
    }

    if ($montoDevuelto > (float) $venta['total'] + 0.01) {
        throw new RuntimeException(
            'El monto a devolver supera el saldo actual de la venta'
        );
    }

    /*
     * Para tarjeta, esta llamada hace el reembolso real en Mercado Pago.
     * Para efectivo o transferencia devuelve null y continúa localmente.
     *
     * Se ejecuta después de validar todo, pero antes de modificar stock,
     * detalle, total o caja.
     */
    $reembolsoMp = mp_refund_sale_if_needed(
        $conn,
        $ventaId,
        $montoDevuelto
    );

    if ($cantidadVendida === $cantidad) {
        $stmtEliminar = $conn->prepare(
            "DELETE FROM detalle_ventas
             WHERE venta_id = ? AND producto_id = ?"
        );

        if (!$stmtEliminar) {
            throw new RuntimeException(
                'No se pudo preparar la eliminación del detalle: ' .
                $conn->error
            );
        }

        $stmtEliminar->bind_param('ii', $ventaId, $productoId);

        if (!$stmtEliminar->execute()) {
            $error = $stmtEliminar->error;
            $stmtEliminar->close();

            throw new RuntimeException(
                'Error al eliminar el detalle de la venta: ' . $error
            );
        }

        $stmtEliminar->close();
    } else {
        /*
         * Es indispensable reducir cantidad Y subtotal.
         */
        $stmtActualizar = $conn->prepare(
            "UPDATE detalle_ventas
             SET cantidad = cantidad - ?,
                 subtotal = subtotal - ?
             WHERE venta_id = ? AND producto_id = ?"
        );

        if (!$stmtActualizar) {
            throw new RuntimeException(
                'No se pudo preparar la actualización del detalle: ' .
                $conn->error
            );
        }

        $stmtActualizar->bind_param(
            'idii',
            $cantidad,
            $montoDevuelto,
            $ventaId,
            $productoId
        );

        if (!$stmtActualizar->execute()) {
            $error = $stmtActualizar->error;
            $stmtActualizar->close();

            throw new RuntimeException(
                'Error al actualizar el producto devuelto: ' . $error
            );
        }

        $stmtActualizar->close();
    }

    $stmtTotal = $conn->prepare(
        "UPDATE ventas
         SET total = GREATEST(total - ?, 0)
         WHERE id = ?"
    );

    if (!$stmtTotal) {
        throw new RuntimeException(
            'No se pudo preparar la actualización del total: ' .
            $conn->error
        );
    }

    $stmtTotal->bind_param('di', $montoDevuelto, $ventaId);

    if (!$stmtTotal->execute()) {
        $error = $stmtTotal->error;
        $stmtTotal->close();

        throw new RuntimeException(
            'Error al actualizar el total de la venta: ' . $error
        );
    }

    $stmtTotal->close();

    $stmtStock = $conn->prepare(
        "UPDATE productos
         SET stock = stock + ?
         WHERE id = ?"
    );

    if (!$stmtStock) {
        throw new RuntimeException(
            'No se pudo preparar la devolución de stock: ' .
            $conn->error
        );
    }

    $stmtStock->bind_param('ii', $cantidad, $productoId);

    if (!$stmtStock->execute()) {
        $error = $stmtStock->error;
        $stmtStock->close();

        throw new RuntimeException(
            'Error al devolver el stock del producto: ' . $error
        );
    }

    $stmtStock->close();

    $tablaModificaciones = $conn->query(
        "SHOW TABLES LIKE 'ventas_modificaciones'"
    );

    if (
        $tablaModificaciones &&
        $tablaModificaciones->num_rows > 0
    ) {
        $stmtModificacion = $conn->prepare(
            "INSERT INTO ventas_modificaciones (
                venta_id,
                usuario_id,
                tipo_modificacion,
                descripcion,
                monto_devuelto,
                productos_devueltos,
                fecha_modificacion
             ) VALUES (
                ?,
                ?,
                'devolucion_parcial',
                ?,
                ?,
                ?,
                NOW()
             )"
        );

        if (!$stmtModificacion) {
            throw new RuntimeException(
                'No se pudo preparar el historial de devolución: ' .
                $conn->error
            );
        }

        $descripcion =
            'Devolución de ' . $cantidad .
            ' unidad(es) - Motivo: ' . $motivo;

        $productosDevueltos = json_encode(
            [[
                'producto_id' => $productoId,
                'cantidad' => $cantidad,
            ]],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($productosDevueltos === false) {
            $productosDevueltos = '[]';
        }

        $usuarioId = (int) $_SESSION['user_id'];

        $stmtModificacion->bind_param(
            'iisds',
            $ventaId,
            $usuarioId,
            $descripcion,
            $montoDevuelto,
            $productosDevueltos
        );

        if (!$stmtModificacion->execute()) {
            $error = $stmtModificacion->error;
            $stmtModificacion->close();

            throw new RuntimeException(
                'No se pudo registrar la devolución: ' . $error
            );
        }

        $stmtModificacion->close();
    }

    $conn->commit();
    $transaccionIniciada = false;

    $mensaje =
        'Devolución procesada correctamente. Monto devuelto: $' .
        number_format($montoDevuelto, 2);

    if (is_array($reembolsoMp)) {
        $mensaje .= ' · Reembolso enviado a Mercado Pago.';
    }

    responderDevolucion(true, $mensaje, [
        'monto_devuelto' => $montoDevuelto,
        'reembolso_mercadopago' => $reembolsoMp !== null,
        'refund_id' => is_array($reembolsoMp)
            ? ($reembolsoMp['refund_id'] ?? null)
            : null,
        'refund_status' => is_array($reembolsoMp)
            ? ($reembolsoMp['refund_status'] ?? null)
            : null,
    ]);
} catch (Throwable $error) {
    if ($transaccionIniciada) {
        $conn->rollback();
    }

    error_log(
        'Error procesar_devolucion venta ' .
        $ventaId . ': ' . $error->getMessage()
    );

    responderDevolucion(false, $error->getMessage());
}
