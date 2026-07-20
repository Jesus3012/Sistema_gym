<?php
declare(strict_types=1);

require_once __DIR__ . '/sucursal_context.php';

/**
 * Utilidades para adaptar procesar_venta.php al inventario multisucursal.
 */

function ventasSucursalSesion(mysqli $conn): int
{
    if (sucursal_dashboard_vista_global()) {
        throw new RuntimeException(
            'Selecciona una sucursal concreta antes de registrar la venta.'
        );
    }

    $sucursalId = (int) (
        $_SESSION['sucursal_id'] ?? 0
    );

    if ($sucursalId <= 0) {
        throw new RuntimeException(
            'Selecciona una sucursal operativa.'
        );
    }

    $stmt = $conn->prepare(
        "SELECT id
         FROM sucursales
         WHERE id = ?
           AND estado = 'activa'
         LIMIT 1"
    );

    $stmt->bind_param('i', $sucursalId);
    $stmt->execute();

    $sucursal = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    if (!is_array($sucursal)) {
        throw new RuntimeException(
            'La sucursal seleccionada está inactiva.'
        );
    }

    return $sucursalId;
}

/**
 * @return array{
 *   total: float,
 *   productos: array<int,array<string,mixed>>
 * }
 */
function ventasValidarCarritoSucursal(
    mysqli $conn,
    int $sucursalId,
    array $items,
    bool $bloquearFilas = false
): array {
    if ($items === []) {
        throw new InvalidArgumentException(
            'El carrito está vacío.'
        );
    }

    $sql =
        "SELECT
            inv.id AS inventario_id,
            inv.producto_id,
            inv.precio_venta,
            inv.stock,
            inv.stock_minimo,
            inv.estado AS inventario_estado,
            p.nombre,
            p.estado AS producto_estado
         FROM inventario_sucursales inv
         INNER JOIN productos p
            ON p.id = inv.producto_id
         WHERE inv.sucursal_id = ?
           AND inv.producto_id = ?
         LIMIT 1";

    if ($bloquearFilas) {
        $sql .= " FOR UPDATE";
    }

    $stmt = $conn->prepare($sql);
    $total = 0.0;
    $productos = [];

    foreach ($items as $item) {
        $productoId = (int) (
            $item['id'] ?? 0
        );

        $cantidad = (int) (
            $item['cantidad'] ?? 0
        );

        if ($productoId <= 0 || $cantidad <= 0) {
            $stmt->close();

            throw new InvalidArgumentException(
                'Producto o cantidad inválida.'
            );
        }

        $stmt->bind_param(
            'ii',
            $sucursalId,
            $productoId
        );

        $stmt->execute();

        $producto = $stmt
            ->get_result()
            ->fetch_assoc();

        if (
            !is_array($producto) ||
            ($producto['producto_estado'] ?? '') !== 'activo' ||
            ($producto['inventario_estado'] ?? '') !== 'activo'
        ) {
            $stmt->close();

            throw new RuntimeException(
                'El producto #' .
                $productoId .
                ' no está disponible en esta sucursal.'
            );
        }

        $stock = (int) (
            $producto['stock'] ?? 0
        );

        if ($stock < $cantidad) {
            $stmt->close();

            throw new RuntimeException(
                'Stock insuficiente para ' .
                (string) $producto['nombre'] .
                '. Disponibles: ' .
                $stock .
                '.'
            );
        }

        $precio = round(
            (float) $producto['precio_venta'],
            2
        );

        $productos[] = [
            'inventario_id' =>
                (int) $producto['inventario_id'],
            'producto_id' => $productoId,
            'nombre' =>
                (string) $producto['nombre'],
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'subtotal' => round(
                $precio * $cantidad,
                2
            ),
            'stock_anterior' => $stock,
            'stock_nuevo' =>
                $stock - $cantidad,
        ];

        $total += $precio * $cantidad;
    }

    $stmt->close();

    return [
        'total' => round($total, 2),
        'productos' => $productos,
    ];
}

function ventasDescontarInventarioSucursal(
    mysqli $conn,
    int $sucursalId,
    int $usuarioId,
    int $ventaId,
    array $productos
): void {
    $stmtStock = $conn->prepare(
        "UPDATE inventario_sucursales
         SET stock = ?, updated_at = NOW()
         WHERE id = ?
           AND sucursal_id = ?
           AND stock = ?"
    );

    $stmtMovimiento = $conn->prepare(
        "INSERT INTO movimientos_stock (
            sucursal_id,
            producto_id,
            tipo_movimiento,
            cantidad,
            stock_anterior,
            stock_nuevo,
            motivo,
            referencia_id,
            referencia_tipo,
            usuario_id,
            observaciones,
            fecha_movimiento
         ) VALUES (
            ?,
            ?,
            'salida',
            ?,
            ?,
            ?,
            'Venta de producto',
            ?,
            'venta',
            ?,
            ?,
            NOW()
         )"
    );

    foreach ($productos as $producto) {
        $inventarioId =
            (int) $producto['inventario_id'];
        $productoId =
            (int) $producto['producto_id'];
        $cantidad =
            (int) $producto['cantidad'];
        $stockAnterior =
            (int) $producto['stock_anterior'];
        $stockNuevo =
            (int) $producto['stock_nuevo'];

        $stmtStock->bind_param(
            'iiii',
            $stockNuevo,
            $inventarioId,
            $sucursalId,
            $stockAnterior
        );

        $stmtStock->execute();

        if ($stmtStock->affected_rows !== 1) {
            $stmtStock->close();
            $stmtMovimiento->close();

            throw new RuntimeException(
                'El stock cambió mientras se procesaba la venta.'
            );
        }

        $cantidadMovimiento = -$cantidad;

        $observaciones = sprintf(
            'Venta #%d · %s · %d unidad(es)',
            $ventaId,
            (string) $producto['nombre'],
            $cantidad
        );

        $stmtMovimiento->bind_param(
            'iiiiiiis',
            $sucursalId,
            $productoId,
            $cantidadMovimiento,
            $stockAnterior,
            $stockNuevo,
            $ventaId,
            $usuarioId,
            $observaciones
        );

        $stmtMovimiento->execute();
    }

    $stmtStock->close();
    $stmtMovimiento->close();
}
