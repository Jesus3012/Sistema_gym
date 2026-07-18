<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap_inscripciones.php';

try {
    $input = mp_api_input();
    $orderId = trim((string) ($input['order_id'] ?? ''));

    if ($orderId === '') {
        mp_api_error('Falta el identificador de la orden.');
    }

    $local = mp_get_local_operation($conn, $orderId, false);

    if ((int) ($local['sucursal_id'] ?? 0) !== $sucursalId) {
        mp_api_error('La orden pertenece a otra sucursal.', 403);
    }

    if (!in_array(
        (string) ($local['origen'] ?? ''),
        ['inscripcion', 'renovacion'],
        true
    )) {
        mp_api_error(
            'La orden no corresponde a una inscripción.',
            403
        );
    }

    $order = mp_get_order($orderId);
    $data = mp_update_order_safe($conn, $order);

    $paid =
        $data['order_status'] === 'processed' &&
        $data['payment_status'] === 'processed';

    $finalFailure = in_array(
        $data['order_status'],
        ['canceled', 'expired', 'failed', 'refunded'],
        true
    ) || in_array(
        $data['payment_status'],
        ['canceled', 'expired', 'failed', 'rejected', 'refunded'],
        true
    );

    mp_api_ok([
        'order_id' => $data['order_id'],
        'payment_id' => $data['payment_id'],
        'external_reference' => $data['external_reference'],
        'payment_reference_id' =>
            $data['payment_reference_id'],
        'order_status' => $data['order_status'],
        'order_status_detail' =>
            $data['order_status_detail'],
        'payment_status' => $data['payment_status'],
        'payment_status_detail' =>
            $data['payment_status_detail'],
        'payment_type' => $data['payment_type'],
        'installments' => $data['installments'],
        'amount' => $data['amount'],
        'terminal_id' => $data['terminal_id'],
        'paid' => $paid,
        'final_failure' => $finalFailure,
    ]);
} catch (Throwable $error) {
    mp_api_exception($error);
}
