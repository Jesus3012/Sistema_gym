<?php
declare(strict_types=1);

require_once __DIR__ . '/mercadopago_service.php';

function mp_inscripcion_es_tarjeta(string $metodoPago): bool
{
    return in_array(
        $metodoPago,
        ['tarjeta_debito', 'tarjeta_credito'],
        true
    );
}

function mp_inscripcion_tipo_tarjeta(string $metodoPago): string
{
    if ($metodoPago === 'tarjeta_debito') {
        return 'debit_card';
    }

    if ($metodoPago === 'tarjeta_credito') {
        return 'credit_card';
    }

    throw new InvalidArgumentException(
        'El método no corresponde a una tarjeta Point.'
    );
}

function mp_inscripcion_origen_valido(string $origen): string
{
    if (!in_array($origen, ['inscripcion', 'renovacion'], true)) {
        throw new InvalidArgumentException(
            'Origen de operación Point no válido.'
        );
    }

    return $origen;
}

function mp_inscripcion_sucursal_actual(): int
{
    $sucursalId = (int) ($_SESSION['sucursal_id'] ?? 0);

    if ($sucursalId <= 0) {
        throw new RuntimeException(
            'No existe una sucursal operativa activa.'
        );
    }

    return $sucursalId;
}

function mp_inscripcion_etiqueta_pago(
    string $metodoPago,
    int $mensualidades = 1
): string {
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

function mp_marcar_origen_orden(
    mysqli $conn,
    string $orderId,
    string $origen
): void {
    $origen = mp_inscripcion_origen_valido($origen);
    $sucursalId = mp_inscripcion_sucursal_actual();

    $stmt = $conn->prepare(
        "UPDATE mercadopago_operaciones
         SET origen = ?,
             updated_at = NOW()
         WHERE order_id = ?
           AND sucursal_id = ?
           AND venta_id IS NULL
           AND pago_id IS NULL"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'No se pudo preparar el origen de la orden Point: ' .
            $conn->error
        );
    }

    $stmt->bind_param('ssi', $origen, $orderId, $sucursalId);
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

    $sucursalId = mp_inscripcion_sucursal_actual();
    $origenEsperado = mp_inscripcion_origen_valido($origenEsperado);
    $orderId = trim((string) ($input['mp_order_id'] ?? ''));

    if ($orderId === '') {
        throw new RuntimeException(
            'Falta la orden de Mercado Pago para registrar el pago.'
        );
    }

    $local = mp_get_local_operation($conn, $orderId, false);

    if ((int) ($local['sucursal_id'] ?? 0) !== $sucursalId) {
        throw new RuntimeException(
            'La orden de Mercado Pago pertenece a otra sucursal.'
        );
    }

    if (!empty($local['venta_id'])) {
        throw new RuntimeException(
            'La orden de Mercado Pago ya fue utilizada en una venta.'
        );
    }

    if (!empty($local['pago_id'])) {
        throw new RuntimeException(
            'La orden ya fue utilizada en otra inscripción.'
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
            'La orden todavía no está procesada. Estado: ' .
            ($data['order_status'] ?: 'desconocido')
        );
    }

    if ($data['payment_status'] !== 'processed') {
        throw new RuntimeException(
            'El pago todavía no está procesado. Estado: ' .
            ($data['payment_status'] ?: 'desconocido')
        );
    }

    if ($data['payment_id'] === '') {
        throw new RuntimeException(
            'Mercado Pago no devolvió el identificador del pago.'
        );
    }

    if (abs($data['amount'] - round($montoEsperado, 2)) > 0.01) {
        throw new RuntimeException(
            'El monto cobrado no coincide con el precio del plan. ' .
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
            'El tipo de tarjeta procesado no coincide con el seleccionado.'
        );
    }

    $paymentIdRecibido = trim((string) (
        $input['mp_payment_id'] ?? ''
    ));

    if (
        $paymentIdRecibido !== '' &&
        $paymentIdRecibido !== $data['payment_id']
    ) {
        throw new RuntimeException(
            'El identificador del pago no coincide con la orden.'
        );
    }

    return $data;
}

function mp_vincular_pago_inscripcion(
    mysqli $conn,
    string $orderId,
    int $inscripcionId,
    int $pagoId,
    string $origen
): void {
    $origen = mp_inscripcion_origen_valido($origen);
    $sucursalId = mp_inscripcion_sucursal_actual();

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
           AND sucursal_id = ?
           AND venta_id IS NULL
           AND pago_id IS NULL
           AND origen = ?"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'No se pudo preparar el vínculo del pago Point: ' .
            $conn->error
        );
    }

    $stmt->bind_param(
        'iissis',
        $inscripcionId,
        $pagoId,
        $origen,
        $orderId,
        $sucursalId,
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
