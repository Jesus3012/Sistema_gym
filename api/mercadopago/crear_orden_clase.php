<?php
declare(strict_types=1);

/** @var mysqli|null $conn */
$conn = null;
/** @var int $sucursalId */
$sucursalId = 0;
/** @var string $terminalId */
$terminalId = '';
/** @var int $usuarioId */
$usuarioId = 0;

require_once __DIR__ . '/_bootstrap_inscripciones.php';

if (!$conn instanceof mysqli) {
    mp_api_error('No se inicializó la conexión con la base de datos.', 500);
}

try {
    $input = mp_api_input();
    $claseId = (int) ($input['clase_id'] ?? 0);
    $totalCliente = round((float) ($input['total'] ?? 0), 2);
    $paymentType = trim((string) ($input['payment_type'] ?? ''));

    if ($claseId <= 0) {
        mp_api_error('Selecciona una clase válida.');
    }

    if (!in_array($paymentType, ['debit_card', 'credit_card'], true)) {
        mp_api_error('El tipo de tarjeta no es válido.');
    }

    $stmt = $conn->prepare(
        "SELECT nombre, precio_clase, estado
         FROM clases
         WHERE id = ?
           AND sucursal_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('ii', $claseId, $sucursalId);
    $stmt->execute();
    $clase = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($clase) || ($clase['estado'] ?? '') !== 'activa') {
        mp_api_error('La clase ya no está disponible.');
    }

    $totalDb = round((float) ($clase['precio_clase'] ?? 0), 2);

    if ($totalDb <= 0 || abs($totalDb - $totalCliente) > 0.01) {
        mp_api_error(
            'El precio de la clase cambió. Actualiza el formulario.',
            409,
            ['total_bd' => $totalDb, 'total_cliente' => $totalCliente]
        );
    }

    /* No permite dejar dos cobros de clase pendientes en la misma terminal. */
    $stmtPendiente = $conn->prepare(
        "SELECT order_id
         FROM mercadopago_operaciones
         WHERE sucursal_id = ?
           AND terminal_id = ?
           AND origen = 'clase'
           AND (
                order_status IN ('created', 'at_terminal')
                OR payment_status IN ('created', 'at_terminal')
           )
         ORDER BY id DESC
         LIMIT 3"
    );
    $stmtPendiente->bind_param('is', $sucursalId, $terminalId);
    $stmtPendiente->execute();
    $pendientes = $stmtPendiente->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtPendiente->close();

    foreach ($pendientes as $pendiente) {
        $orderPendiente = trim((string) ($pendiente['order_id'] ?? ''));
        if ($orderPendiente === '') {
            continue;
        }

        $remote = mp_get_order($orderPendiente);
        $actual = mp_update_order_safe($conn, $remote);

        if (($actual['order_status'] ?? '') === 'created') {
            try {
                $cancelada = mp_cancel_order($orderPendiente);
                mp_update_order_safe($conn, $cancelada);
            } catch (Throwable $cancelError) {
                error_log('[Point clase pendiente] ' . $cancelError->getMessage());
            }
            continue;
        }

        if (
            ($actual['order_status'] ?? '') === 'at_terminal'
            || ($actual['payment_status'] ?? '') === 'at_terminal'
        ) {
            mp_api_error(
                'La terminal tiene otro cobro esperando confirmación. Termínalo o cancélalo en la Point.',
                409,
                [
                    'code' => 'already_queued_order_on_terminal',
                    'requires_terminal' => true,
                    'order_id' => $orderPendiente,
                ]
            );
        }
    }

    $externalReference = sprintf(
        'GYM-CLS-S%d-C%d-%s-%d-%s',
        $sucursalId,
        $claseId,
        date('YmdHis'),
        $usuarioId,
        substr(bin2hex(random_bytes(4)), 0, 8)
    );

    $descripcion = 'Acceso a clase - ' . (string) $clase['nombre'];
    $order = mp_create_point_order(
        $totalDb,
        $paymentType,
        $externalReference,
        $descripcion,
        $terminalId
    );

    $saved = mp_save_order(
        $conn,
        $order,
        $paymentType,
        1,
        $usuarioId
    );

    $orderId = (string) ($saved['order_id'] ?? '');

    if ($orderId === '') {
        throw new RuntimeException('Mercado Pago no devolvió un order_id.');
    }

    $stmtOrigen = $conn->prepare(
        "UPDATE mercadopago_operaciones
         SET origen = 'clase'
         WHERE order_id = ?"
    );
    $stmtOrigen->bind_param('s', $orderId);
    $stmtOrigen->execute();
    $stmtOrigen->close();

    mp_api_ok([
        'order_id' => $orderId,
        'payment_id' => (string) ($saved['payment_id'] ?? ''),
        'external_reference' => (string) ($saved['external_reference'] ?? ''),
        'order_status' => (string) ($saved['order_status'] ?? ''),
        'payment_status' => (string) ($saved['payment_status'] ?? ''),
        'amount' => $totalDb,
        'payment_type' => (string) ($saved['payment_type'] ?? $paymentType),
        'installments' => (int) ($saved['installments'] ?? 1),
        'message' => $paymentType === 'credit_card'
            ? 'Las mensualidades disponibles se eligen en la terminal.'
            : 'Cobro enviado como tarjeta de débito.',
    ]);
} catch (Throwable $error) {
    mp_api_exception($error);
}
