<?php
// Archivo: includes/stock_functions.php
// Registra movimientos contra el inventario de una sucursal.

function registrarMovimientoStock(
    $conn,
    $producto_id,
    $tipo_movimiento,
    $cantidad,
    $motivo,
    $usuario_id,
    $referencia_id = null,
    $referencia_tipo = null,
    $observaciones = null,
    $sucursal_id = null
) {
    try {
        if (!$conn || !($conn instanceof mysqli)) {
            return array('success' => false, 'error' => 'Conexión a base de datos inválida');
        }

        $producto_id = (int) $producto_id;
        $usuario_id = (int) $usuario_id;
        $sucursal_id = (int) ($sucursal_id ?: ($_SESSION['sucursal_id'] ?? 0));

        if ($producto_id <= 0 || $sucursal_id <= 0) {
            return array('success' => false, 'error' => 'Producto o sucursal inválidos');
        }

        $stmt = $conn->prepare(
            'SELECT stock
             FROM inventario_sucursales
             WHERE sucursal_id = ?
               AND producto_id = ?
             LIMIT 1'
        );
        if (!$stmt) {
            return array('success' => false, 'error' => 'No se pudo consultar el inventario: ' . $conn->error);
        }
        $stmt->bind_param('ii', $sucursal_id, $producto_id);
        $stmt->execute();
        $inventario = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$inventario) {
            return array('success' => false, 'error' => 'El producto no pertenece al inventario de la sucursal');
        }

        $stock_anterior = (int) $inventario['stock'];
        $cantidad_registro = 0;
        $stock_nuevo = $stock_anterior;

        if ($tipo_movimiento === 'entrada') {
            $cantidad_registro = abs((int) $cantidad);
            $stock_nuevo = $stock_anterior + $cantidad_registro;
        } elseif ($tipo_movimiento === 'inicial') {
            $stock_anterior = 0;
            $cantidad_registro = max(0, (int) $cantidad);
            $stock_nuevo = $cantidad_registro;
        } elseif ($tipo_movimiento === 'salida') {
            $cantidad_registro = -abs((int) $cantidad);
            $stock_nuevo = $stock_anterior + $cantidad_registro;
        } elseif ($tipo_movimiento === 'correccion') {
            $stock_nuevo = max(0, (int) $cantidad);
            $cantidad_registro = $stock_nuevo - $stock_anterior;
        } elseif ($tipo_movimiento === 'ajuste_minimo') {
            $cantidad_registro = (int) $cantidad;
            $stock_nuevo = $stock_anterior;
        } else {
            return array('success' => false, 'error' => 'Tipo de movimiento no válido: ' . $tipo_movimiento);
        }

        if ($stock_nuevo < 0) {
            return array(
                'success' => false,
                'error' => 'El stock no puede ser negativo. Stock actual: ' . $stock_anterior,
            );
        }

        $sql = "INSERT INTO movimientos_stock
                    (sucursal_id, producto_id, tipo_movimiento, cantidad,
                     stock_anterior, stock_nuevo, motivo, referencia_id,
                     referencia_tipo, usuario_id, observaciones, fecha_movimiento)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return array('success' => false, 'error' => 'No se pudo preparar el movimiento: ' . $conn->error);
        }

        $stmt->bind_param(
            'iisiiisisis',
            $sucursal_id,
            $producto_id,
            $tipo_movimiento,
            $cantidad_registro,
            $stock_anterior,
            $stock_nuevo,
            $motivo,
            $referencia_id,
            $referencia_tipo,
            $usuario_id,
            $observaciones
        );

        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            return array('success' => false, 'error' => 'No se pudo registrar el movimiento: ' . $error);
        }

        $movimiento_id = (int) $stmt->insert_id;
        $stmt->close();

        return array(
            'success' => true,
            'movimiento_id' => $movimiento_id,
            'sucursal_id' => $sucursal_id,
            'stock_anterior' => $stock_anterior,
            'stock_nuevo' => $stock_nuevo,
            'cantidad' => $cantidad_registro,
        );
    } catch (Throwable $e) {
        return array('success' => false, 'error' => $e->getMessage());
    }
}
?>
