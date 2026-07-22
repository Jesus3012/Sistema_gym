<?php
declare(strict_types=1);

function mp_clase_es_tarjeta(string $metodo): bool
{
    return in_array(
        $metodo,
        ['tarjeta_debito', 'tarjeta_credito'],
        true
    );
}

function mp_clase_etiqueta_pago(string $metodo, int $mensualidades = 1): string
{
    if ($metodo === 'tarjeta_credito') {
        return $mensualidades > 1
            ? 'Tarjeta de crédito · ' . $mensualidades . ' mensualidades'
            : 'Tarjeta de crédito';
    }

    if ($metodo === 'tarjeta_debito') {
        return 'Tarjeta de débito';
    }

    if ($metodo === 'transferencia') {
        return 'Transferencia';
    }

    return 'Efectivo';
}

function mp_clase_validar_pago(
    mysqli $conn,
    array $post,
    float $montoEsperado,
    int $sucursalId
): array {
    $orderId = trim((string) ($post['mp_order_id'] ?? ''));

    if ($orderId === '') {
        throw new RuntimeException(
            'No se recibió la orden aprobada de Mercado Pago.'
        );
    }

    if (
        function_exists('mp_get_order')
        && function_exists('mp_update_order_safe')
    ) {
        $remote = mp_get_order($orderId);
        mp_update_order_safe($conn, $remote);
    }

    $stmt = $conn->prepare(
        "SELECT *
         FROM mercadopago_operaciones
         WHERE order_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $orderId);
    $stmt->execute();
    $operacion = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($operacion)) {
        throw new RuntimeException('La orden Point no existe en el registro local.');
    }

    if ((int) ($operacion['sucursal_id'] ?? 0) !== $sucursalId) {
        throw new RuntimeException('La orden Point pertenece a otra sucursal.');
    }

    if (($operacion['origen'] ?? '') !== 'clase') {
        throw new RuntimeException('La orden Point no corresponde a un acceso a clase.');
    }

    if (
        !empty($operacion['inscripcion_clase_id'])
        || !empty($operacion['pago_clase_id'])
    ) {
        throw new RuntimeException('La orden Point ya fue utilizada anteriormente.');
    }

    $orderStatus = trim((string) ($operacion['order_status'] ?? ''));
    $paymentStatus = trim((string) ($operacion['payment_status'] ?? ''));

    if ($orderStatus !== 'processed' && $paymentStatus !== 'processed') {
        throw new RuntimeException(
            'El pago todavía no aparece como aprobado en Mercado Pago.'
        );
    }

    $pagado = round((float) ($operacion['paid_amount'] ?? 0), 2);

    if ($pagado <= 0) {
        $pagado = round((float) ($operacion['amount'] ?? 0), 2);
    }

    if (abs($pagado - $montoEsperado) > 0.01) {
        throw new RuntimeException(
            'El importe aprobado en Point no coincide con el precio de la clase.'
        );
    }

    return $operacion;
}

function mp_clase_vincular_pago(
    mysqli $conn,
    string $orderId,
    int $inscripcionClaseId,
    int $pagoClaseId
): void {
    $stmt = $conn->prepare(
        "UPDATE mercadopago_operaciones
         SET inscripcion_clase_id = ?,
             pago_clase_id = ?,
             origen = 'clase'
         WHERE order_id = ?
           AND inscripcion_clase_id IS NULL
           AND pago_clase_id IS NULL"
    );
    $stmt->bind_param(
        'iis',
        $inscripcionClaseId,
        $pagoClaseId,
        $orderId
    );
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException(
            'No fue posible vincular el pago Point con la inscripción a clase.'
        );
    }

    $stmt->close();
}
