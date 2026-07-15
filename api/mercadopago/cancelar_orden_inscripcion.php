<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    $input = mp_api_input();
    $orderId = trim((string) ($input['order_id'] ?? ''));

    if ($orderId === '') {
        mp_api_error('Falta order_id.');
    }

    $local = mp_get_local_operation($conn, $orderId);

    if (!in_array(
        (string) ($local['origen'] ?? ''),
        ['inscripcion', 'renovacion'],
        true
    )) {
        mp_api_error(
            'La orden no corresponde al módulo de inscripciones.',
            409
        );
    }

    if (!empty($local['venta_id']) || !empty($local['pago_id'])) {
        mp_api_error(
            'La orden ya fue cobrada y vinculada. Un pago procesado requiere un reembolso explícito.',
            409
        );
    }

    $current = mp_get_order($orderId);
    $status = (string) ($current['status'] ?? '');

    if ($status === 'created') {
        $canceled = mp_cancel_order($orderId);
        $data = mp_update_order_safe($conn, $canceled);

        mp_api_ok([
            'canceled' => true,
            'requires_terminal' => false,
            'order_status' => $data['order_status'],
        ]);
    }

    if ($status === 'at_terminal') {
        mp_update_order_safe($conn, $current);

        mp_api_ok([
            'canceled' => false,
            'requires_terminal' => true,
            'order_status' => $status,
            'message' => 'La orden ya está en la terminal. Cancélala desde la Point.',
        ]);
    }

    mp_update_order_safe($conn, $current);

    mp_api_ok([
        'canceled' => in_array(
            $status,
            ['canceled', 'expired'],
            true
        ),
        'requires_terminal' => false,
        'order_status' => $status,
        'message' => 'La orden ya no puede cancelarse por API en su estado actual.',
    ]);
} catch (Throwable $error) {
    mp_api_exception($error);
}
