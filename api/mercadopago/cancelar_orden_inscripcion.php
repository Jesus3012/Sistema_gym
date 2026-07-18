<?php
declare(strict_types=1);

/*
 * Estas variables son inicializadas realmente dentro de
 * _bootstrap_inscripciones.php.
 *
 * Se declaran antes para que Intelephense reconozca su existencia
 * y pueda inferir correctamente sus tipos.
 */

/** @var mysqli|null $conn */
$conn = null;

/** @var int $sucursalId */
$sucursalId = 0;

require_once __DIR__ . '/_bootstrap_inscripciones.php';

if (!$conn instanceof mysqli) {
    throw new RuntimeException(
        'No se inicializó correctamente la conexión con la base de datos.'
    );
}

if ($sucursalId <= 0) {
    mp_api_error(
        'Selecciona una sucursal operativa antes de cancelar el cobro.',
        409
    );
}

try {
    $input = mp_api_input();
    $orderId = trim((string) ($input['order_id'] ?? ''));

    if ($orderId === '') {
        mp_api_error(
            'Falta el identificador de la orden.'
        );
    }

    $local = mp_get_local_operation(
        $conn,
        $orderId,
        false
    );

    if (
        (int) ($local['sucursal_id'] ?? 0)
        !== $sucursalId
    ) {
        mp_api_error(
            'La orden pertenece a otra sucursal.',
            403
        );
    }

    $origen = (string) ($local['origen'] ?? '');

    if (
        !in_array(
            $origen,
            ['inscripcion', 'renovacion'],
            true
        )
    ) {
        mp_api_error(
            'La orden no corresponde a una inscripción o renovación.',
            403
        );
    }

    $remote = mp_get_order($orderId);

    $data = mp_update_order_safe(
        $conn,
        $remote
    );

    $orderStatus = trim(
        (string) ($data['order_status'] ?? '')
    );

    $paymentStatus = trim(
        (string) ($data['payment_status'] ?? '')
    );

    /*
     * Una orden que todavía está en "created" puede cancelarse
     * mediante la API de Mercado Pago.
     */
    if ($orderStatus === 'created') {
        $canceled = mp_cancel_order($orderId);

        $updated = mp_update_order_safe(
            $conn,
            $canceled
        );

        mp_api_ok([
            'order_id' =>
                (string) ($updated['order_id'] ?? $orderId),
            'order_status' =>
                (string) ($updated['order_status'] ?? 'canceled'),
            'payment_status' =>
                (string) ($updated['payment_status'] ?? ''),
            'requires_terminal' => false,
            'message' =>
                'Cobro cancelado correctamente.',
        ]);
    }

    /*
     * Cuando la orden ya llegó físicamente a la Point,
     * debe cancelarse en la propia terminal.
     */
    if (
        $orderStatus === 'at_terminal' ||
        $paymentStatus === 'at_terminal'
    ) {
        mp_api_ok([
            'order_id' =>
                (string) ($data['order_id'] ?? $orderId),
            'order_status' =>
                $orderStatus,
            'payment_status' =>
                $paymentStatus,
            'requires_terminal' => true,
            'message' =>
                'La orden ya está en la Point. ' .
                'Cancélala directamente en la terminal.',
        ]);
    }

    /*
     * Un pago procesado ya no debe tratarse como una orden pendiente.
     * En ese caso correspondería un flujo de reembolso.
     */
    if (
        $orderStatus === 'processed' ||
        $paymentStatus === 'processed'
    ) {
        mp_api_error(
            'El pago ya fue aprobado y no puede cancelarse como orden.',
            409,
            [
                'code' =>
                    'order_already_processed',
                'order_id' =>
                    $orderId,
            ]
        );
    }

    /*
     * Estados finales que ya no requieren ninguna acción.
     */
    $finalStatuses = [
        'canceled',
        'expired',
        'failed',
        'rejected',
        'refunded',
    ];

    if (
        in_array($orderStatus, $finalStatuses, true) ||
        in_array($paymentStatus, $finalStatuses, true)
    ) {
        mp_api_ok([
            'order_id' =>
                (string) ($data['order_id'] ?? $orderId),
            'order_status' =>
                $orderStatus,
            'payment_status' =>
                $paymentStatus,
            'requires_terminal' => false,
            'message' =>
                'La orden ya se encontraba finalizada.',
        ]);
    }

    /*
     * Cualquier otro estado queda informado para evitar asumir
     * que la cancelación sí ocurrió.
     */
    mp_api_error(
        'La orden no se pudo cancelar en su estado actual.',
        409,
        [
            'code' =>
                'order_not_cancelable',
            'order_id' =>
                $orderId,
            'order_status' =>
                $orderStatus,
            'payment_status' =>
                $paymentStatus,
        ]
    );
} catch (Throwable $error) {
    mp_api_exception($error);
}
