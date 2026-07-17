<?php
// Archivo: includes/procesar_cancelacion.php
// Cancela una venta local y, cuando corresponde,
// ejecuta primero el reembolso en Mercado Pago Point.

session_start();
header('Content-Type: application/json; charset=utf-8');

function responderCancelacion(bool $success, string $message, array $extra = []): void
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
    responderCancelacion(false, 'No autorizado');
}

if (
    !isset($_SESSION['user_rol']) ||
    !in_array($_SESSION['user_rol'], ['admin', 'recepcionista'], true)
) {
    responderCancelacion(
        false,
        'No tienes permiso para cancelar ventas'
    );
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mercadopago_service.php';
require_once __DIR__ . '/devoluciones_config.php';

$database = new Database();
$conn = $database->getConnection();

if (!$conn instanceof mysqli) {
    responderCancelacion(
        false,
        'No fue posible conectar con la base de datos'
    );
}

$conn->set_charset('utf8mb4');

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    responderCancelacion(false, 'La solicitud JSON no es válida');
}

$ventaId = isset($input['venta_id'])
    ? (int) $input['venta_id']
    : 0;

if ($ventaId <= 0) {
    responderCancelacion(false, 'ID de venta inválido');
}

$transaccionIniciada = false;

try {
    $conn->begin_transaction();
    $transaccionIniciada = true;

    /*
     * Bloquear la venta para impedir dos cancelaciones simultáneas.
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

    if ($venta['estado'] === 'cancelada') {
        throw new RuntimeException('La venta ya está cancelada');
    }

    if ($venta['estado'] !== 'completada') {
        throw new RuntimeException(
            'Solo se pueden cancelar ventas completadas'
        );
    }

    $plazoOperacion = devoluciones_validar_plazo_venta(
        $conn,
        $ventaId,
        'cancelacion'
    );

    $stmtDetalles = $conn->prepare(
        "SELECT producto_id, cantidad
         FROM detalle_ventas
         WHERE venta_id = ?
         FOR UPDATE"
    );

    if (!$stmtDetalles) {
        throw new RuntimeException(
            'No se pudo preparar la consulta de productos: ' .
            $conn->error
        );
    }

    $stmtDetalles->bind_param('i', $ventaId);
    $stmtDetalles->execute();
    $detalles = $stmtDetalles
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);
    $stmtDetalles->close();

    if (empty($detalles)) {
        throw new RuntimeException(
            'No se encontraron productos en esta venta'
        );
    }

    /*
     * Para tarjeta ejecuta el reembolso real antes de cambiar la venta.
     * Para efectivo o transferencia devuelve null.
     *
     * null significa reembolso total. Si ya hubo devoluciones parciales,
     * el servicio debe devolver únicamente el saldo todavía disponible.
     */
    $reembolsoMp = mp_refund_sale_if_needed(
        $conn,
        $ventaId,
        null
    );

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

    foreach ($detalles as $detalle) {
        $cantidad = (int) $detalle['cantidad'];
        $productoId = (int) $detalle['producto_id'];

        $stmtStock->bind_param(
            'ii',
            $cantidad,
            $productoId
        );

        if (!$stmtStock->execute()) {
            $error = $stmtStock->error;
            $stmtStock->close();

            throw new RuntimeException(
                'Error al devolver stock del producto ID ' .
                $productoId . ': ' . $error
            );
        }
    }

    $stmtStock->close();

    $stmtActualizarVenta = $conn->prepare(
        "UPDATE ventas
         SET estado = 'cancelada'
         WHERE id = ?"
    );

    if (!$stmtActualizarVenta) {
        throw new RuntimeException(
            'No se pudo preparar la cancelación de la venta: ' .
            $conn->error
        );
    }

    $stmtActualizarVenta->bind_param('i', $ventaId);

    if (!$stmtActualizarVenta->execute()) {
        $error = $stmtActualizarVenta->error;
        $stmtActualizarVenta->close();

        throw new RuntimeException(
            'Error al actualizar el estado de la venta: ' . $error
        );
    }

    $stmtActualizarVenta->close();

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
                fecha_modificacion
             ) VALUES (
                ?,
                ?,
                'cancelacion',
                ?,
                ?,
                NOW()
             )"
        );

        if (!$stmtModificacion) {
            throw new RuntimeException(
                'No se pudo preparar el historial de cancelación: ' .
                $conn->error
            );
        }

        $usuarioId = (int) $_SESSION['user_id'];
        $montoCancelado = round((float) $venta['total'], 2);
        $descripcion = is_array($reembolsoMp)
            ? 'Venta cancelada y reembolsada en Mercado Pago'
            : 'Venta cancelada';

        $stmtModificacion->bind_param(
            'iisd',
            $ventaId,
            $usuarioId,
            $descripcion,
            $montoCancelado
        );

        if (!$stmtModificacion->execute()) {
            $error = $stmtModificacion->error;
            $stmtModificacion->close();

            throw new RuntimeException(
                'No se pudo registrar la cancelación: ' . $error
            );
        }

        $stmtModificacion->close();
    }

    $conn->commit();
    $transaccionIniciada = false;

    $mensaje = 'Venta cancelada correctamente';

    if (is_array($reembolsoMp)) {
        $mensaje .= ' y reembolsada en Mercado Pago';
    }

    responderCancelacion(true, $mensaje, [
        'reembolso_mercadopago' => $reembolsoMp !== null,
        'refund_id' => is_array($reembolsoMp)
            ? ($reembolsoMp['refund_id'] ?? null)
            : null,
        'refund_status' => is_array($reembolsoMp)
            ? ($reembolsoMp['refund_status'] ?? null)
            : null,
        'monto_reembolsado' => is_array($reembolsoMp)
            ? ($reembolsoMp['amount'] ?? null)
            : null,
    ]);
} catch (Throwable $error) {
    if ($transaccionIniciada) {
        $conn->rollback();
    }

    error_log(
        'Error procesar_cancelacion venta ' .
        $ventaId . ': ' . $error->getMessage()
    );

    responderCancelacion(false, $error->getMessage());
}
