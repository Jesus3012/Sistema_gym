<?php
// Integración de pagos_clases con el corte de caja existente.

if (!function_exists('cajaClasesMonto')) {
    function cajaClasesMonto($valor)
    {
        if (function_exists('cajaMonto')) {
            return cajaMonto($valor);
        }

        return round((float) ($valor ?? 0), 2);
    }
}

if (!function_exists('cajaClasesDisponible')) {
    function cajaClasesDisponible($conn)
    {
        static $disponible = null;

        if ($disponible !== null) {
            return $disponible;
        }

        $resultado = $conn->query("SHOW TABLES LIKE 'pagos_clases'");
        $disponible = $resultado && $resultado->num_rows > 0;

        return $disponible;
    }
}

if (!function_exists('resumenClasesCaja')) {
    function resumenClasesCaja(
        $conn,
        $sucursalId,
        $usuarioId,
        $fechaInicio,
        $fechaFin
    ) {
        if (!cajaClasesDisponible($conn)) {
            return array(
                'operaciones' => 0,
                'efectivo' => 0.00,
                'tarjeta' => 0.00,
                'transferencia' => 0.00,
            );
        }

        $sql = "SELECT
                    COUNT(*) AS operaciones,
                    COALESCE(SUM(CASE
                        WHEN metodo_pago = 'efectivo' THEN monto
                        ELSE 0
                    END), 0) AS efectivo,
                    COALESCE(SUM(CASE
                        WHEN metodo_pago = 'tarjeta' THEN monto
                        ELSE 0
                    END), 0) AS tarjeta,
                    COALESCE(SUM(CASE
                        WHEN metodo_pago = 'transferencia' THEN monto
                        ELSE 0
                    END), 0) AS transferencia
                FROM pagos_clases
                WHERE usuario_id = ?
                  AND sucursal_id = ?
                  AND fecha_pago >= ?
                  AND fecha_pago <= ?
                  AND estado = 'completado'";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException(
                'No se pudo consultar los pagos de clases. Detalle MySQL: ' .
                $conn->error
            );
        }

        $stmt->bind_param(
            'iiss',
            $usuarioId,
            $sucursalId,
            $fechaInicio,
            $fechaFin
        );
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: array();
        $stmt->close();

        return array(
            'operaciones' => (int) ($fila['operaciones'] ?? 0),
            'efectivo' => cajaClasesMonto($fila['efectivo'] ?? 0),
            'tarjeta' => cajaClasesMonto($fila['tarjeta'] ?? 0),
            'transferencia' => cajaClasesMonto($fila['transferencia'] ?? 0),
        );
    }
}

if (!function_exists('integrarClasesEnVentasCaja')) {
    function integrarClasesEnVentasCaja($ventas, $clases)
    {
        $ventas['operaciones'] =
            (int) ($ventas['operaciones'] ?? 0) +
            (int) ($clases['operaciones'] ?? 0);

        foreach (array('efectivo', 'tarjeta', 'transferencia') as $metodo) {
            $ventas[$metodo] = cajaClasesMonto(
                ($ventas[$metodo] ?? 0) +
                ($clases[$metodo] ?? 0)
            );
        }

        // Conserva el desglose por si después deseas mostrarlo en la interfaz.
        $ventas['clases'] = $clases;

        return $ventas;
    }
}
