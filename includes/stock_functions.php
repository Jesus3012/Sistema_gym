<?php
/**
 * Funciones para manejar movimientos de stock
 */

function registrarMovimientoStock($conn, $producto_id, $tipo_movimiento, $cantidad, $motivo, $usuario_id, $referencia_id = null, $referencia_tipo = null, $observaciones = null) {
    try {
        // Verificar que la conexión sea válida
        if (!$conn || !($conn instanceof mysqli)) {
            return ['success' => false, 'error' => 'Conexión a base de datos inválida'];
        }
        
        // Obtener stock actual del producto
        $stmt = $conn->prepare("SELECT stock FROM productos WHERE id = ?");
        if (!$stmt) {
            return ['success' => false, 'error' => 'Error al preparar consulta de producto: ' . $conn->error];
        }
        
        $stmt->bind_param("i", $producto_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $producto = $result->fetch_assoc();
        $stmt->close();
        
        if (!$producto) {
            return ['success' => false, 'error' => 'Producto no encontrado'];
        }
        
        $stock_anterior = $producto['stock'];
        $cantidad_registro = 0;
        $stock_nuevo = $stock_anterior;
        
        // Calcular según tipo de movimiento
        if ($tipo_movimiento == 'entrada') {
            // Entrada manual de stock: cantidad POSITIVA que se suma
            $cantidad_registro = abs($cantidad); // Aseguramos que sea positivo
            $stock_nuevo = $stock_anterior + $cantidad_registro;
            
        } elseif ($tipo_movimiento == 'inicial') {
            // Stock inicial al crear producto: stock anterior es 0
            $stock_anterior = 0; // Forzamos a 0 para nuevo producto
            $cantidad_registro = $cantidad; // Stock inicial
            $stock_nuevo = $cantidad;
            
        } elseif ($tipo_movimiento == 'salida') {
            // Salida de stock (ventas, etc): cantidad POSITIVA que se resta
            $cantidad_registro = -abs($cantidad); // Negativo para mostrar disminución
            $stock_nuevo = $stock_anterior + $cantidad_registro;
            
        } elseif ($tipo_movimiento == 'correccion') {
            // Corrección de inventario: $cantidad es el NUEVO STOCK
            $nuevo_stock = $cantidad;
            $diferencia = $nuevo_stock - $stock_anterior;
            $cantidad_registro = $diferencia; // Puede ser positivo o negativo
            $stock_nuevo = $nuevo_stock;
            
        } elseif ($tipo_movimiento == 'ajuste_minimo') {
            // Ajuste de stock mínimo: no cambia el stock
            $cantidad_registro = $cantidad; // Diferencia en stock mínimo
            $stock_nuevo = $stock_anterior; // No cambia
            
        } else {
            return ['success' => false, 'error' => 'Tipo de movimiento no válido: ' . $tipo_movimiento];
        }
        
        // Verificar que el stock nuevo no sea negativo
        if ($stock_nuevo < 0) {
            return ['success' => false, 'error' => 'El stock no puede ser negativo. Stock actual: ' . $stock_anterior . ', cambio: ' . $cantidad_registro];
        }
        
        // Insertar en tabla de movimientos
        $sql = "INSERT INTO movimientos_stock (producto_id, tipo_movimiento, cantidad, stock_anterior, stock_nuevo, motivo, referencia_id, referencia_tipo, usuario_id, observaciones, fecha_movimiento) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['success' => false, 'error' => 'Error al preparar consulta de inserción: ' . $conn->error];
        }
        
        $stmt->bind_param("isiiisssis", 
            $producto_id, 
            $tipo_movimiento, 
            $cantidad_registro,  // Cantidad que se agregó/quitó (positivo o negativo)
            $stock_anterior,      // Stock antes del movimiento
            $stock_nuevo,         // Stock después del movimiento
            $motivo, 
            $referencia_id, 
            $referencia_tipo, 
            $usuario_id, 
            $observaciones
        );
        
        if ($stmt->execute()) {
            $movimiento_id = $stmt->insert_id;
            $stmt->close();
            
            return [
                'success' => true, 
                'movimiento_id' => $movimiento_id, 
                'stock_anterior' => $stock_anterior,
                'stock_nuevo' => $stock_nuevo,
                'cantidad' => $cantidad_registro
            ];
        } else {
            $error = $stmt->error;
            $stmt->close();
            return ['success' => false, 'error' => 'Error al insertar movimiento: ' . $error];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Excepción: ' . $e->getMessage()];
    }
}
?>