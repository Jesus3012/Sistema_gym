<?php

require_once __DIR__ . '/_bootstrap.php';

try {
    $input = mp_api_input();
    $items = $input['items'] ?? $input['carrito'] ?? [];
    $totalClient = round((float) ($input['total'] ?? 0), 2);
    $paymentType = trim((string) ($input['payment_type'] ?? ''));

    if (!is_array($items) || count($items) === 0) {
        mp_api_error('El carrito está vacío.');
    }

    if (!in_array($paymentType, ['debit_card', 'credit_card'], true)) {
        mp_api_error('Tipo de tarjeta inválido.');
    }


    $totalDb = 0.0;

    $stmt = $conn->prepare(
        "SELECT nombre, precio_venta, stock, estado
         FROM productos
         WHERE id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar la consulta de productos: ' . $conn->error);
    }

    foreach ($items as $item) {
        $productId = (int) ($item['id'] ?? 0);
        $quantity = (int) ($item['cantidad'] ?? 0);

        if ($productId <= 0 || $quantity <= 0) {
            $stmt->close();
            mp_api_error('Producto o cantidad inválida.');
        }

        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();

        if (!$product || $product['estado'] !== 'activo') {
            $stmt->close();
            mp_api_error('Producto no disponible: ' . $productId);
        }

        if ((int) $product['stock'] < $quantity) {
            $stmt->close();
            mp_api_error('Stock insuficiente para ' . $product['nombre'] . '.');
        }

        $totalDb += (float) $product['precio_venta'] * $quantity;
    }

    $stmt->close();
    $totalDb = round($totalDb, 2);

    if ($totalDb <= 0 || abs($totalDb - $totalClient) > 0.01) {
        mp_api_error(
            'El total del carrito cambió. Actualiza la venta.',
            409,
            ['total_cliente' => $totalClient, 'total_bd' => $totalDb]
        );
    }

    $externalReference = sprintf(
        'GYM-POS-%s-%d-%s',
        date('YmdHis'),
        (int) $_SESSION['user_id'],
        substr(bin2hex(random_bytes(5)), 0, 10)
    );

    $order = mp_create_point_order(
        $totalDb,
        $paymentType,
        $externalReference,
        'Venta de productos Ego Gym'
    );

    $saved = mp_save_order(
        $conn,
        $order,
        $paymentType,
        1,
        (int) $_SESSION['user_id']
    );

    mp_api_ok([
        'order_id' => $saved['order_id'],
        'payment_id' => $saved['payment_id'],
        'external_reference' => $saved['external_reference'],
        'order_status' => $saved['order_status'],
        'payment_status' => $saved['payment_status'],
        'amount' => $totalDb,
        'payment_type' => $saved['payment_type'],
        'installments' => $saved['installments'],
        'terminal_id' => $saved['terminal_id'],
    ]);
} catch (Throwable $error) {
    mp_api_exception($error);
}