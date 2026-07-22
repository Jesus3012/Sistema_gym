<?php
declare(strict_types=1);

/** @var mysqli|null $conn */
$conn = null;
/** @var int $sucursalId */
$sucursalId = 0;

require_once __DIR__ . '/_bootstrap_inscripciones.php';

if (!$conn instanceof mysqli) {
    mp_api_error('No se inicializó la conexión con la base de datos.', 500);
}

try {
    $input = mp_api_input();
    $orderId = trim((string) ($input['order_id'] ?? ''));

    if ($orderId === '') {
        mp_api_error('Falta el identificador de la orden.');
    }

    $stmt = $conn->prepare(
        "SELECT sucursal_id, origen
         FROM mercadopago_operaciones
         WHERE order_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $orderId);
    $stmt->execute();
    $local = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($local)) {
        mp_api_error('La orden no existe.', 404);
    }

    if ((int) $local['sucursal_id'] !== $sucursalId) {
        mp_api_error('La orden pertenece a otra sucursal.', 403);
    }

    if (($local['origen'] ?? '') !== 'clase') {
        mp_api_error('La orden no corresponde a una clase.', 403);
    }

    $remote = mp_get_order($orderId);
    $data = mp_update_order_safe($conn, $remote);

    $orderStatus = trim((string) ($data['order_status'] ?? ''));
    $paymentStatus = trim((string) ($data['payment_status'] ?? ''));
    $paid = $orderStatus === 'processed' || $paymentStatus === 'processed';
    $finalFailure = in_array(
        $orderStatus,
        ['canceled', 'expired', 'failed', 'rejected'],
        true
    ) || in_array(
        $paymentStatus,
        ['canceled', 'expired', 'failed', 'rejected'],
        true
    );

    mp_api_ok([
        'order_id' => $orderId,
        'payment_id' => (string) ($data['payment_id'] ?? ''),
        'payment_reference_id' => (string) ($data['payment_reference_id'] ?? ''),
        'external_reference' => (string) ($data['external_reference'] ?? ''),
        'order_status' => $orderStatus,
        'payment_status' => $paymentStatus,
        'installments' => (int) ($data['installments'] ?? 1),
        'paid' => $paid,
        'final_failure' => $finalFailure,
    ]);
} catch (Throwable $error) {
    mp_api_exception($error);
}
