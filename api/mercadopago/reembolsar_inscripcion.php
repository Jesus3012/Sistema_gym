<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/mercadopago_inscripciones.php';

if (($_SESSION['user_rol'] ?? '') !== 'admin') {
    mp_api_error(
        'Solo un administrador puede procesar reembolsos.',
        403
    );
}

try {
    $input = mp_api_input();
    $orderId = trim((string) ($input['order_id'] ?? ''));

    if ($orderId === '') {
        mp_api_error('Falta order_id.');
    }

    $amount = null;

    if (
        array_key_exists('amount', $input) &&
        $input['amount'] !== null &&
        $input['amount'] !== ''
    ) {
        $amount = round((float) $input['amount'], 2);

        if ($amount <= 0) {
            mp_api_error('El monto del reembolso debe ser mayor que cero.');
        }
    }

    $refund = mp_reembolsar_pago_inscripcion(
        $conn,
        $orderId,
        $amount
    );

    mp_api_ok([
        'refund_id' => $refund['refund_id'],
        'refund_status' => $refund['refund_status'],
        'amount' => $refund['amount'],
    ]);
} catch (Throwable $error) {
    mp_api_exception($error);
}
