<?php

require_once __DIR__ . '/mercadopago_client.php';

function mp_json(array $data): string
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    return $json === false ? '{}' : $json;
}

/** @return array<string,mixed> */
function mp_normalize_order(array $order): array
{
    $payment = mp_first_payment($order);
    $paymentMethod = $payment['payment_method'] ?? [];
    $configMethod = $order['config']['payment_method'] ?? [];

    return [
        'order_id' => (string) ($order['id'] ?? ''),
        'external_reference' => (string) ($order['external_reference'] ?? ''),
        'payment_id' => (string) ($payment['id'] ?? ''),
        'payment_reference_id' => isset($payment['reference_id'])
            ? (string) $payment['reference_id']
            : null,
        'payment_type' => (string) (
            $paymentMethod['type'] ??
            $configMethod['default_type'] ??
            ''
        ),
        'installments' => (int) (
            $paymentMethod['installments'] ??
            $configMethod['default_installments'] ??
            1
        ),
        'installments_cost' => (string) (
            $configMethod['installments_cost'] ?? 'unknown'
        ),
        'amount' => round((float) ($payment['amount'] ?? 0), 2),
        'paid_amount' => round((float) ($payment['paid_amount'] ?? $payment['amount'] ?? 0), 2),
        'refunded_amount' => round((float) ($payment['refunded_amount'] ?? 0), 2),
        'order_status' => (string) ($order['status'] ?? ''),
        'order_status_detail' => (string) ($order['status_detail'] ?? ''),
        'payment_status' => (string) ($payment['status'] ?? ''),
        'payment_status_detail' => (string) ($payment['status_detail'] ?? ''),
        'terminal_id' => (string) (
            $order['config']['point']['terminal_id']
            ?? mp_runtime_terminal_id()
        ),
        'raw_order_json' => mp_json($order),
    ];
}

function mp_save_order(
    mysqli $conn,
    array $order,
    string $requestedType,
    int $requestedInstallments,
    int $usuarioId
): array {
    $sucursalId = (int) ($_SESSION['sucursal_id'] ?? 0);

    if ($sucursalId <= 0) {
        throw new RuntimeException(
            'No existe una sucursal operativa activa para guardar la orden.'
        );
    }

    $data = mp_normalize_order($order);

    if ($data['order_id'] === '' || $data['external_reference'] === '') {
        throw new RuntimeException('Mercado Pago no devolvió identificadores válidos.');
    }

    $paymentType = $data['payment_type'] ?: $requestedType;
    $installments = $data['installments'] > 0
        ? $data['installments']
        : max(1, $requestedInstallments);
    $installmentsCost = in_array(
        $data['installments_cost'],
        ['seller', 'buyer', 'unknown'],
        true
    ) ? $data['installments_cost'] : 'unknown';

    $sql = "INSERT INTO mercadopago_operaciones (
                sucursal_id,
                venta_id,
                usuario_id,
                external_reference,
                order_id,
                payment_id,
                payment_reference_id,
                payment_type,
                installments,
                installments_cost,
                amount,
                paid_amount,
                refunded_amount,
                order_status,
                order_status_detail,
                payment_status,
                payment_status_detail,
                terminal_id,
                raw_order_json,
                created_at,
                updated_at
            ) VALUES (
                ?, NULL, ?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                payment_id = COALESCE(NULLIF(VALUES(payment_id), ''), payment_id),
                payment_reference_id = COALESCE(VALUES(payment_reference_id), payment_reference_id),
                payment_type = VALUES(payment_type),
                installments = VALUES(installments),
                installments_cost = VALUES(installments_cost),
                amount = VALUES(amount),
                paid_amount = VALUES(paid_amount),
                refunded_amount = VALUES(refunded_amount),
                order_status = VALUES(order_status),
                order_status_detail = VALUES(order_status_detail),
                payment_status = VALUES(payment_status),
                payment_status_detail = VALUES(payment_status_detail),
                terminal_id = VALUES(terminal_id),
                raw_order_json = VALUES(raw_order_json),
                updated_at = NOW()";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar el guardado de la orden: ' . $conn->error);
    }

    $stmt->bind_param(
        'iisssssisdddssssss',
        $sucursalId,
        $usuarioId,
        $data['external_reference'],
        $data['order_id'],
        $data['payment_id'],
        $data['payment_reference_id'],
        $paymentType,
        $installments,
        $installmentsCost,
        $data['amount'],
        $data['paid_amount'],
        $data['refunded_amount'],
        $data['order_status'],
        $data['order_status_detail'],
        $data['payment_status'],
        $data['payment_status_detail'],
        $data['terminal_id'],
        $data['raw_order_json']
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('No se pudo guardar la orden: ' . $error);
    }

    $stmt->close();
    $data['payment_type'] = $paymentType;
    $data['installments'] = $installments;
    $data['installments_cost'] = $installmentsCost;

    return $data;
}

/**
 * Actualiza la operación sin depender de una firma bind_param muy larga.
 * Se usa en los endpoints y reemplaza a mp_update_order cuando se requiera.
 */
function mp_update_order_safe(mysqli $conn, array $order): array
{
    $data = mp_normalize_order($order);

    $sql = "UPDATE mercadopago_operaciones SET
                payment_id = COALESCE(NULLIF(?, ''), payment_id),
                payment_reference_id = COALESCE(?, payment_reference_id),
                payment_type = CASE WHEN ? <> '' THEN ? ELSE payment_type END,
                installments = CASE WHEN ? > 0 THEN ? ELSE installments END,
                installments_cost = CASE WHEN ? IN ('seller','buyer') THEN ? ELSE installments_cost END,
                amount = CASE WHEN ? > 0 THEN ? ELSE amount END,
                paid_amount = ?,
                refunded_amount = ?,
                order_status = ?,
                order_status_detail = ?,
                payment_status = ?,
                payment_status_detail = ?,
                terminal_id = CASE WHEN ? <> '' THEN ? ELSE terminal_id END,
                raw_order_json = ?,
                updated_at = NOW()
            WHERE order_id = ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la actualización MP: ' . $conn->error);
    }

    $types = 'ssssii' . 'ss' . 'dddd' . 'ssss' . 'ssss';
    $stmt->bind_param(
        $types,
        $data['payment_id'],
        $data['payment_reference_id'],
        $data['payment_type'],
        $data['payment_type'],
        $data['installments'],
        $data['installments'],
        $data['installments_cost'],
        $data['installments_cost'],
        $data['amount'],
        $data['amount'],
        $data['paid_amount'],
        $data['refunded_amount'],
        $data['order_status'],
        $data['order_status_detail'],
        $data['payment_status'],
        $data['payment_status_detail'],
        $data['terminal_id'],
        $data['terminal_id'],
        $data['raw_order_json'],
        $data['order_id']
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('No se pudo actualizar la orden MP: ' . $error);
    }

    $stmt->close();
    return $data;
}

/** @return array<string,mixed> */
function mp_get_local_operation(mysqli $conn, string $orderId, bool $forUpdate = false): array
{
    $sql = "SELECT * FROM mercadopago_operaciones WHERE order_id = ? LIMIT 1";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo consultar la operación MP: ' . $conn->error);
    }

    $stmt->bind_param('s', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('La orden no pertenece a este sistema.');
    }

    return $row;
}

/**
 * Validación obligatoria dentro de procesar_venta.php.
 * @return array<string,mixed>
 */
function mp_validate_paid_order_for_sale(
    mysqli $conn,
    array $input,
    float $expectedAmount
): array {
    $orderId = trim((string) ($input['mp_order_id'] ?? ''));

    if ($orderId === '') {
        throw new RuntimeException('Falta mp_order_id para registrar la venta con tarjeta.');
    }

    $local = mp_get_local_operation($conn, $orderId, true);

    $sucursalSesion = (int) ($_SESSION['sucursal_id'] ?? 0);
    if (
        $sucursalSesion <= 0
        || (int) ($local['sucursal_id'] ?? 0) !== $sucursalSesion
    ) {
        throw new RuntimeException(
            'La orden de Mercado Pago pertenece a otra sucursal.'
        );
    }

    if (!empty($local['venta_id'])) {
        throw new RuntimeException(
            'Esta orden de Mercado Pago ya fue vinculada a la venta #' .
            (int) $local['venta_id'] . '.'
        );
    }

    mp_terminal_configurar_operacion(
        $conn,
        (int) $local['sucursal_id'],
        (string) $local['terminal_id']
    );

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
        throw new RuntimeException('Mercado Pago no devolvió payment_id.');
    }

    if (abs($data['amount'] - round($expectedAmount, 2)) > 0.01) {
        throw new RuntimeException(
            'El monto cobrado en Mercado Pago no coincide con la venta. ' .
            'MP: $' . number_format($data['amount'], 2) .
            ' | Venta: $' . number_format($expectedAmount, 2)
        );
    }

    if (
        isset($input['mp_payment_id']) &&
        trim((string) $input['mp_payment_id']) !== '' &&
        trim((string) $input['mp_payment_id']) !== $data['payment_id']
    ) {
        throw new RuntimeException('El payment_id recibido no coincide con la orden.');
    }

    return $data;
}

function mp_link_order_to_sale(
    mysqli $conn,
    int $ventaId,
    array $mpData
): void {
    $orderId = (string) ($mpData['order_id'] ?? '');
    if ($orderId === '') {
        throw new RuntimeException('No se puede vincular la venta sin order_id.');
    }

    $stmt = $conn->prepare(
        "UPDATE mercadopago_operaciones
         SET venta_id = ?, updated_at = NOW()
         WHERE order_id = ? AND venta_id IS NULL"
    );

    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar el vínculo MP: ' . $conn->error);
    }

    $stmt->bind_param('is', $ventaId, $orderId);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('La orden ya fue vinculada o dejó de estar disponible.');
    }

    $stmt->close();
}

/**
 * Ejecuta un reembolso únicamente si la venta fue pagada con Mercado Pago.
 * Para ventas de efectivo/transferencia devuelve null y deja el flujo local igual.
 *
 * @return array<string,mixed>|null
 */
function mp_refund_sale_if_needed(
    mysqli $conn,
    int $ventaId,
    ?float $partialAmount = null
): ?array {
    $sql = "SELECT
                v.id,
                v.sucursal_id AS venta_sucursal_id,
                v.metodo_pago,
                v.total,
                m.id AS mp_operacion_id,
                m.sucursal_id AS mp_sucursal_id,
                m.order_id,
                m.payment_id,
                m.terminal_id,
                m.amount,
                m.refunded_amount
            FROM ventas v
            LEFT JOIN mercadopago_operaciones m ON m.venta_id = v.id
            WHERE v.id = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo consultar la venta para reembolso: ' . $conn->error);
    }

    $stmt->bind_param('i', $ventaId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('Venta no encontrada.');
    }

    if ($row['metodo_pago'] !== 'tarjeta') {
        return null;
    }

    if (
        (int) ($row['venta_sucursal_id'] ?? 0) <= 0
        || (int) ($row['venta_sucursal_id'] ?? 0)
            !== (int) ($row['mp_sucursal_id'] ?? 0)
    ) {
        throw new RuntimeException(
            'La venta y la operación de Mercado Pago pertenecen a sucursales distintas.'
        );
    }

    if (empty($row['order_id']) || empty($row['payment_id'])) {
        throw new RuntimeException(
            'La venta es de tarjeta, pero no tiene order_id/payment_id de Mercado Pago.'
        );
    }

    mp_terminal_configurar_operacion(
        $conn,
        (int) $row['mp_sucursal_id'],
        (string) $row['terminal_id']
    );

    $available = round(
        (float) $row['amount'] - (float) $row['refunded_amount'],
        2
    );

    if ($available <= 0) {
        throw new RuntimeException('El pago ya fue reembolsado completamente.');
    }

    $amountToRefund = $partialAmount === null
        ? null
        : round($partialAmount, 2);

    if ($amountToRefund !== null && $amountToRefund > $available + 0.01) {
        throw new RuntimeException(
            'El reembolso solicitado supera el saldo disponible de $' .
            number_format($available, 2) . '.'
        );
    }

    $refundCall = mp_refund_order(
        (string) $row['order_id'],
        (string) $row['payment_id'],
        $amountToRefund
    );

    $response = $refundCall['response'];
    $refunds = $response['transactions']['refunds'] ?? [];
    $latestRefund = [];

    if (is_array($refunds) && count($refunds) > 0) {
        $candidate = end($refunds);
        if (is_array($candidate)) {
            $latestRefund = $candidate;
        }
    }

    $refundStatus = (string) ($latestRefund['status'] ?? '');
    $orderStatus = (string) ($response['status'] ?? '');

    if ($refundStatus !== 'processed' && $orderStatus !== 'refunded') {
        throw new RuntimeException(
            'Mercado Pago recibió el reembolso, pero aún no está procesado. ' .
            'No se modificó stock ni caja. Estado: ' .
            ($refundStatus ?: $orderStatus ?: 'desconocido')
        );
    }

    $refundAmount = round((float) (
        $latestRefund['amount'] ??
        $amountToRefund ??
        $available
    ), 2);

    $refundId = (string) ($latestRefund['id'] ?? '');
    $referenceId = isset($latestRefund['reference_id'])
        ? (string) $latestRefund['reference_id']
        : null;

    $insert = $conn->prepare(
        "INSERT INTO mercadopago_reembolsos (
            sucursal_id,
            mercadopago_operacion_id,
            venta_id,
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
        ) VALUES (?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            raw_response_json = VALUES(raw_response_json),
            updated_at = NOW()"
    );

    if (!$insert) {
        throw new RuntimeException('No se pudo preparar el registro del reembolso: ' . $conn->error);
    }

    $tipo = $amountToRefund === null ? 'total' : 'parcial';
    $raw = mp_json($response);
    $operationId = (int) $row['mp_operacion_id'];
    $refundSucursalId = (int) ($row['mp_sucursal_id'] ?? 0);

    if ($refundSucursalId <= 0) {
        throw new RuntimeException(
            'La operación de Mercado Pago no tiene sucursal válida.'
        );
    }

    $insert->bind_param(
        'iiissssdsss',
        $refundSucursalId,
        $operationId,
        $ventaId,
        $refundId,
        $row['payment_id'],
        $referenceId,
        $tipo,
        $refundAmount,
        $refundStatus,
        $refundCall['idempotency_key'],
        $raw
    );
    $insert->execute();
    $insert->close();

    $normalized = mp_normalize_order($response);
    $update = $conn->prepare(
        "UPDATE mercadopago_operaciones SET
            refunded_amount = ?,
            order_status = ?,
            order_status_detail = ?,
            payment_status = ?,
            payment_status_detail = ?,
            raw_order_json = ?,
            updated_at = NOW()
         WHERE id = ?"
    );

    if (!$update) {
        throw new RuntimeException('No se pudo preparar la actualización del reembolso: ' . $conn->error);
    }

    $update->bind_param(
        'dsssssi',
        $normalized['refunded_amount'],
        $normalized['order_status'],
        $normalized['order_status_detail'],
        $normalized['payment_status'],
        $normalized['payment_status_detail'],
        $normalized['raw_order_json'],
        $operationId
    );
    $update->execute();
    $update->close();

    return [
        'refund_id' => $refundId,
        'refund_status' => $refundStatus,
        'amount' => $refundAmount,
        'order' => $response,
    ];
}
