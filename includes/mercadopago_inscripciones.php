<?php

require_once __DIR__ . '/mercadopago_service.php';

function mp_inscripcion_es_tarjeta(string $metodoPago): bool
{
    return in_array($metodoPago, ['tarjeta_debito', 'tarjeta_credito'], true);
}

function mp_inscripcion_tipo_tarjeta(string $metodoPago): string
{
    if ($metodoPago === 'tarjeta_debito') {
        return 'debit_card';
    }

    if ($metodoPago === 'tarjeta_credito') {
        return 'credit_card';
    }

    throw new InvalidArgumentException('El método no corresponde a una tarjeta Point.');
}

function mp_inscripcion_origen_valido(string $origen): string
{
    if (!in_array($origen, ['inscripcion', 'renovacion'], true)) {
        throw new InvalidArgumentException('Origen de operación Point no válido.');
    }

    return $origen;
}

function mp_inscripcion_etiqueta_pago(string $metodoPago, int $mensualidades = 1): string
{
    if ($metodoPago === 'tarjeta_debito') {
        return 'Tarjeta de débito';
    }

    if ($metodoPago === 'tarjeta_credito') {
        return $mensualidades > 1
            ? 'Tarjeta de crédito · ' . $mensualidades . ' mensualidades'
            : 'Tarjeta de crédito · una exhibición';
    }

    if ($metodoPago === 'transferencia') {
        return 'Transferencia';
    }

    return 'Efectivo';
}

/**
 * Marca una orden recién creada con el contexto local.
 * mp_save_order() sigue siendo el mismo que ya utiliza ventas.php.
 */
function mp_marcar_origen_orden(
    mysqli $conn,
    string $orderId,
    string $origen
): void {
    $origen = mp_inscripcion_origen_valido($origen);

    $stmt = $conn->prepare(
        "UPDATE mercadopago_operaciones
         SET origen = ?, updated_at = NOW()
         WHERE order_id = ?
           AND venta_id IS NULL
           AND pago_id IS NULL"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'No se pudo preparar el origen de la orden Point: ' . $conn->error
        );
    }

    $stmt->bind_param('ss', $origen, $orderId);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException(
            'No se pudo asignar el origen a la orden Point.'
        );
    }

    $stmt->close();
}

/**
 * Valida en el servidor que el pago:
 * - pertenece a esta aplicación;
 * - corresponde a inscripción o renovación;
 * - está procesado;
 * - tiene el monto y tipo de tarjeta correctos;
 * - todavía no ha sido utilizado.
 *
 * @return array<string,mixed>
 */
function mp_validar_pago_inscripcion(
    mysqli $conn,
    array $input,
    float $montoEsperado,
    string $metodoPago,
    string $origenEsperado
): array {
    if (!mp_inscripcion_es_tarjeta($metodoPago)) {
        throw new InvalidArgumentException(
            'El método de pago no requiere validación Point.'
        );
    }

    $origenEsperado = mp_inscripcion_origen_valido($origenEsperado);
    $orderId = trim((string) ($input['mp_order_id'] ?? ''));

    if ($orderId === '') {
        throw new RuntimeException(
            'Falta la orden de Mercado Pago para registrar el pago con tarjeta.'
        );
    }

    $local = mp_get_local_operation($conn, $orderId, false);

    if (!empty($local['venta_id'])) {
        throw new RuntimeException(
            'La orden de Mercado Pago ya fue utilizada en una venta.'
        );
    }

    if (!empty($local['pago_id'])) {
        throw new RuntimeException(
            'La orden de Mercado Pago ya fue utilizada en otra inscripción.'
        );
    }

    if (($local['origen'] ?? '') !== $origenEsperado) {
        throw new RuntimeException(
            'La orden no corresponde al tipo de operación solicitado.'
        );
    }

    $order = mp_get_order($orderId);
    $data = mp_update_order_safe($conn, $order);

    if ($data['order_status'] !== 'processed') {
        throw new RuntimeException(
            'La orden de Mercado Pago todavía no está procesada. Estado: ' .
            ($data['order_status'] !== '' ? $data['order_status'] : 'desconocido')
        );
    }

    if ($data['payment_status'] !== 'processed') {
        throw new RuntimeException(
            'El pago de Mercado Pago todavía no está procesado. Estado: ' .
            ($data['payment_status'] !== '' ? $data['payment_status'] : 'desconocido')
        );
    }

    if ($data['payment_id'] === '') {
        throw new RuntimeException(
            'Mercado Pago no devolvió el identificador del pago.'
        );
    }

    if (abs($data['amount'] - round($montoEsperado, 2)) > 0.01) {
        throw new RuntimeException(
            'El monto cobrado en la terminal no coincide con el precio del plan. ' .
            'Mercado Pago: $' . number_format($data['amount'], 2) .
            ' | Plan: $' . number_format($montoEsperado, 2)
        );
    }

    $tipoEsperado = mp_inscripcion_tipo_tarjeta($metodoPago);

    if (
        $data['payment_type'] !== '' &&
        $data['payment_type'] !== $tipoEsperado
    ) {
        throw new RuntimeException(
            'El tipo de tarjeta procesado no coincide con el método seleccionado.'
        );
    }

    $paymentIdRecibido = trim((string) ($input['mp_payment_id'] ?? ''));

    if (
        $paymentIdRecibido !== '' &&
        $paymentIdRecibido !== $data['payment_id']
    ) {
        throw new RuntimeException(
            'El identificador del pago no coincide con la orden de Mercado Pago.'
        );
    }

    return $data;
}

/**
 * Vincula la orden con el pago local. El UPDATE condicional evita reutilizar
 * una misma orden en dos solicitudes concurrentes.
 */
function mp_vincular_pago_inscripcion(
    mysqli $conn,
    string $orderId,
    int $inscripcionId,
    int $pagoId,
    string $origen
): void {
    $origen = mp_inscripcion_origen_valido($origen);

    if ($orderId === '' || $inscripcionId <= 0 || $pagoId <= 0) {
        throw new InvalidArgumentException(
            'No se puede vincular una operación Point incompleta.'
        );
    }

    $stmt = $conn->prepare(
        "UPDATE mercadopago_operaciones
         SET inscripcion_id = ?,
             pago_id = ?,
             origen = ?,
             updated_at = NOW()
         WHERE order_id = ?
           AND venta_id IS NULL
           AND pago_id IS NULL
           AND origen = ?"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'No se pudo preparar el vínculo del pago Point: ' . $conn->error
        );
    }

    $stmt->bind_param(
        'iisss',
        $inscripcionId,
        $pagoId,
        $origen,
        $orderId,
        $origen
    );
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException(
            'La orden Point ya fue utilizada o dejó de estar disponible.'
        );
    }

    $stmt->close();
}

/**
 * Reembolso opcional. No debe ejecutarse automáticamente al cancelar una
 * inscripción, porque la membresía pudo haberse utilizado.
 *
 * @return array<string,mixed>
 */
function mp_reembolsar_pago_inscripcion(
    mysqli $conn,
    string $orderId,
    ?float $montoParcial = null
): array {
    $local = mp_get_local_operation($conn, $orderId, false);
    $origen = (string) ($local['origen'] ?? '');

    if (!in_array($origen, ['inscripcion', 'renovacion'], true)) {
        throw new RuntimeException(
            'La orden no corresponde a una inscripción o renovación.'
        );
    }

    $inscripcionId = (int) ($local['inscripcion_id'] ?? 0);
    $pagoId = (int) ($local['pago_id'] ?? 0);
    $paymentId = (string) ($local['payment_id'] ?? '');

    if ($inscripcionId <= 0 || $pagoId <= 0 || $paymentId === '') {
        throw new RuntimeException(
            'La operación no está vinculada correctamente con el pago local.'
        );
    }

    $disponible = round(
        (float) $local['amount'] - (float) $local['refunded_amount'],
        2
    );

    if ($disponible <= 0) {
        throw new RuntimeException(
            'El pago ya fue reembolsado completamente.'
        );
    }

    $monto = $montoParcial === null
        ? null
        : round($montoParcial, 2);

    if ($monto !== null && ($monto <= 0 || $monto > $disponible + 0.01)) {
        throw new RuntimeException(
            'El monto del reembolso no es válido. Disponible: $' .
            number_format($disponible, 2)
        );
    }

    $refundCall = mp_refund_order($orderId, $paymentId, $monto);
    $response = $refundCall['response'];
    $refunds = $response['transactions']['refunds'] ?? [];
    $latest = [];

    if (is_array($refunds) && count($refunds) > 0) {
        $candidate = end($refunds);
        if (is_array($candidate)) {
            $latest = $candidate;
        }
    }

    $refundStatus = (string) ($latest['status'] ?? '');
    $orderStatus = (string) ($response['status'] ?? '');

    if ($refundStatus !== 'processed' && $orderStatus !== 'refunded') {
        throw new RuntimeException(
            'Mercado Pago recibió el reembolso, pero todavía no está procesado. ' .
            'Estado: ' . ($refundStatus ?: $orderStatus ?: 'desconocido')
        );
    }

    $refundAmount = round((float) (
        $latest['amount'] ?? $monto ?? $disponible
    ), 2);

    $refundId = (string) ($latest['id'] ?? '');
    $referenceId = isset($latest['reference_id'])
        ? (string) $latest['reference_id']
        : null;
    $tipo = $monto === null ? 'total' : 'parcial';
    $raw = mp_json($response);
    $operationId = (int) $local['id'];

    $insert = $conn->prepare(
        "INSERT INTO mercadopago_reembolsos (
            mercadopago_operacion_id,
            venta_id,
            inscripcion_id,
            pago_id,
            origen,
            refund_id,
            transaction_id,
            reference_id,
            tipo,
            monto,
            status,
            idempotency_key,
            raw_response_json,
            created_at,
            updated_at
        ) VALUES (
            ?, NULL, ?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            raw_response_json = VALUES(raw_response_json),
            updated_at = NOW()"
    );

    if (!$insert) {
        throw new RuntimeException(
            'No se pudo preparar el registro del reembolso: ' . $conn->error
        );
    }

    $insert->bind_param(
        'iiisssssdsss',
        $operationId,
        $inscripcionId,
        $pagoId,
        $origen,
        $refundId,
        $paymentId,
        $referenceId,
        $tipo,
        $refundAmount,
        $refundStatus,
        $refundCall['idempotency_key'],
        $raw
    );
    $insert->execute();
    $insert->close();

    $actualizada = mp_update_order_safe($conn, $response);

    return [
        'refund_id' => $refundId,
        'refund_status' => $refundStatus,
        'amount' => $refundAmount,
        'order' => $actualizada,
    ];
}
