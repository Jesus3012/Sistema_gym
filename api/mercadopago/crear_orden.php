<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/sucursal_context.php';

try {
    if (sucursal_dashboard_vista_global()) {
        mp_api_error(
            'Selecciona una sucursal concreta antes de enviar el cobro a la terminal.',
            409,
            ['code' => 'sucursal_venta_requerida']
        );
    }

    $input = mp_api_input();

    $items = $input['items']
        ?? $input['carrito']
        ?? [];

    $totalCliente = round(
        (float) ($input['total'] ?? 0),
        2
    );

    $paymentType = trim((string) (
        $input['payment_type'] ?? ''
    ));

    $sucursalId = (int) (
        $_SESSION['sucursal_id'] ?? 0
    );

    if ($sucursalId <= 0) {
        mp_api_error(
            'Selecciona una sucursal antes de cobrar.',
            409,
            ['code' => 'sucursal_requerida']
        );
    }

    if (!is_array($items) || $items === []) {
        mp_api_error('El carrito está vacío.');
    }

    if (!in_array(
        $paymentType,
        ['debit_card', 'credit_card'],
        true
    )) {
        mp_api_error('Tipo de tarjeta inválido.');
    }

    $stmtTerminal = $conn->prepare(
        "SELECT terminal_id, nombre
         FROM mercadopago_terminales
         WHERE sucursal_id = ?
           AND activo = 1
         ORDER BY predeterminada DESC, id ASC
         LIMIT 1"
    );

    $stmtTerminal->bind_param('i', $sucursalId);
    $stmtTerminal->execute();

    $terminal = $stmtTerminal
        ->get_result()
        ->fetch_assoc();

    $stmtTerminal->close();

    $terminalId = trim((string) (
        $terminal['terminal_id'] ?? ''
    ));

    if ($terminalId === '') {
        mp_api_error(
            'La sucursal seleccionada no tiene una terminal Point activa.',
            409,
            ['code' => 'terminal_sucursal_no_configurada']
        );
    }

    $stmtProducto = $conn->prepare(
        "SELECT
            p.nombre,
            p.estado AS producto_estado,
            inv.precio_venta,
            inv.stock,
            inv.estado AS inventario_estado
         FROM inventario_sucursales inv
         INNER JOIN productos p
            ON p.id = inv.producto_id
         WHERE inv.sucursal_id = ?
           AND inv.producto_id = ?
         LIMIT 1"
    );

    $totalBd = 0.0;

    foreach ($items as $item) {
        $productoId = (int) (
            $item['id'] ?? 0
        );

        $cantidad = (int) (
            $item['cantidad'] ?? 0
        );

        if ($productoId <= 0 || $cantidad <= 0) {
            $stmtProducto->close();
            mp_api_error('Producto o cantidad inválida.');
        }

        $stmtProducto->bind_param(
            'ii',
            $sucursalId,
            $productoId
        );

        $stmtProducto->execute();

        $producto = $stmtProducto
            ->get_result()
            ->fetch_assoc();

        if (
            !is_array($producto) ||
            ($producto['producto_estado'] ?? '') !== 'activo' ||
            ($producto['inventario_estado'] ?? '') !== 'activo'
        ) {
            $stmtProducto->close();

            mp_api_error(
                'El producto no está disponible en esta sucursal.',
                409,
                [
                    'code' => 'producto_no_disponible',
                    'producto_id' => $productoId,
                ]
            );
        }

        $stock = (int) (
            $producto['stock'] ?? 0
        );

        if ($stock < $cantidad) {
            $stmtProducto->close();

            mp_api_error(
                'Stock insuficiente para ' .
                (string) $producto['nombre'] .
                '. Disponibles: ' .
                $stock .
                '.',
                409,
                [
                    'code' => 'stock_sucursal_insuficiente',
                    'producto_id' => $productoId,
                    'stock_disponible' => $stock,
                ]
            );
        }

        $totalBd +=
            (float) $producto['precio_venta']
            * $cantidad;
    }

    $stmtProducto->close();
    $totalBd = round($totalBd, 2);

    if (
        $totalBd <= 0 ||
        abs($totalBd - $totalCliente) > 0.01
    ) {
        mp_api_error(
            'El precio o las existencias cambiaron. Actualiza la venta.',
            409,
            [
                'code' => 'total_sucursal_actualizado',
                'total_cliente' => $totalCliente,
                'total_bd' => $totalBd,
            ]
        );
    }

    $externalReference = sprintf(
        'GYM-POS-S%d-%s-%d-%s',
        $sucursalId,
        date('YmdHis'),
        (int) $_SESSION['user_id'],
        substr(
            bin2hex(random_bytes(5)),
            0,
            10
        )
    );

    $reflection = new ReflectionFunction(
        'mp_create_point_order'
    );

    if ($reflection->getNumberOfParameters() >= 5) {
        $order = mp_create_point_order(
            $totalBd,
            $paymentType,
            $externalReference,
            'Venta de productos - ' .
                (string) (
                    $_SESSION['sucursal_nombre']
                    ?? 'Sucursal'
                ),
            $terminalId
        );
    } else {
        $terminalConfig = defined('MP_TERMINAL_ID')
            ? trim((string) MP_TERMINAL_ID)
            : '';

        if (
            $terminalConfig === '' ||
            $terminalConfig !== $terminalId
        ) {
            mp_api_error(
                'El cliente de Mercado Pago necesita actualizarse para usar terminales distintas por sucursal.',
                500,
                ['code' => 'cliente_point_sin_terminal_dinamica']
            );
        }

        $order = mp_create_point_order(
            $totalBd,
            $paymentType,
            $externalReference,
            'Venta de productos - ' .
                (string) (
                    $_SESSION['sucursal_nombre']
                    ?? 'Sucursal'
                )
        );
    }

    $saved = mp_save_order(
        $conn,
        $order,
        $paymentType,
        1,
        (int) $_SESSION['user_id']
    );

    $orderId = (string) (
        $saved['order_id'] ?? ''
    );

    $stmtOrigen = $conn->prepare(
        "UPDATE mercadopago_operaciones
         SET
            sucursal_id = ?,
            origen = 'venta',
            updated_at = NOW()
         WHERE order_id = ?
           AND venta_id IS NULL"
    );

    $stmtOrigen->bind_param(
        'is',
        $sucursalId,
        $orderId
    );

    $stmtOrigen->execute();
    $stmtOrigen->close();

    mp_api_ok([
        'order_id' => $orderId,
        'payment_id' =>
            $saved['payment_id'] ?? '',
        'external_reference' =>
            $saved['external_reference'] ?? '',
        'order_status' =>
            $saved['order_status'] ?? '',
        'payment_status' =>
            $saved['payment_status'] ?? '',
        'amount' => $totalBd,
        'payment_type' =>
            $saved['payment_type']
            ?? $paymentType,
        'installments' =>
            (int) (
                $saved['installments'] ?? 1
            ),
        'terminal_id' =>
            $saved['terminal_id']
            ?? $terminalId,
        'sucursal_id' => $sucursalId,
    ]);
} catch (Throwable $error) {
    mp_api_exception($error);
}
