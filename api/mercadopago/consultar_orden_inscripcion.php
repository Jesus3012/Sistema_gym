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

    $order = mp_get_order($orderId);
    $data = mp_update_order_safe($conn, $order);

    $isPaid = $data['order_status'] === 'processed' &&
        $data['payment_status'] === 'processed';

    $isFinalFailure = in_array(
        $data['order_status'],
        ['canceled', 'expired', 'failed'],
        true
    ) || in_array(
        $data['payment_status'],
        ['canceled', 'rejected', 'failed', 'expired'],
        true
    );

    mp_api_ok([
        'paid' => $isPaid,
        'final_failure' => $isFinalFailure,
        'order_id' => $data['order_id'],
        'payment_id' => $data['payment_id'],
        'external_reference' => $data['external_reference'],
        'order_status' => $data['order_status'],
        'order_status_detail' => $data['order_status_detail'],
        'payment_status' => $data['payment_status'],
        'payment_status_detail' => $data['payment_status_detail'],
        'payment_reference_id' => $data['payment_reference_id'],
        'payment_type' => $data['payment_type'],
        'installments' => max(1, (int) $data['installments']),
        'amount' => $data['amount'],
    ]);
} catch (Throwable $error) {
    mp_api_exception($error);
}
