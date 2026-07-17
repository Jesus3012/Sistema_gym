<?php
// Archivo: includes/devoluciones_config.php

declare(strict_types=1);

const DEVOLUCIONES_LIMITE_TECNICO_TARJETA_DIAS = 90;

/** @return array<string,mixed> */
function devoluciones_obtener_configuracion(mysqli $conn): array
{
    $result = $conn->query(
        "SELECT *
         FROM configuracion_devoluciones
         WHERE id = 1
         LIMIT 1"
    );

    if (!$result) {
        throw new RuntimeException(
            'No se pudo leer configuracion_devoluciones: ' . $conn->error
        );
    }

    $config = $result->fetch_assoc();

    if (!$config) {
        throw new RuntimeException(
            'No existe la configuración de devoluciones con id = 1.'
        );
    }

    if ((int) $config['activo'] !== 1) {
        throw new RuntimeException(
            'Las cancelaciones y devoluciones están desactivadas.'
        );
    }

    return $config;
}

/**
 * @return array<string,mixed>
 */
function devoluciones_validar_plazo_venta(
    mysqli $conn,
    int $ventaId,
    string $accion
): array {
    if (!in_array($accion, ['cancelacion', 'devolucion'], true)) {
        throw new InvalidArgumentException('Acción no válida.');
    }

    $config = devoluciones_obtener_configuracion($conn);

    if (
        $accion === 'cancelacion' &&
        (int) $config['permitir_cancelaciones'] !== 1
    ) {
        throw new RuntimeException(
            'Las cancelaciones están desactivadas por configuración.'
        );
    }

    if (
        $accion === 'devolucion' &&
        (int) $config['permitir_devoluciones'] !== 1
    ) {
        throw new RuntimeException(
            'Las devoluciones parciales están desactivadas por configuración.'
        );
    }

    $stmt = $conn->prepare(
        "SELECT
            v.metodo_pago,
            v.fecha_venta,
            m.created_at AS mp_created_at,
            CASE
                WHEN v.metodo_pago = 'tarjeta' THEN m.created_at
                ELSE v.fecha_venta
            END AS fecha_base,
            TIMESTAMPDIFF(
                DAY,
                CASE
                    WHEN v.metodo_pago = 'tarjeta' THEN m.created_at
                    ELSE v.fecha_venta
                END,
                NOW()
            ) AS dias_transcurridos
         FROM ventas v
         LEFT JOIN mercadopago_operaciones m
             ON m.venta_id = v.id
         WHERE v.id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'No se pudo preparar la validación del plazo: ' . $conn->error
        );
    }

    $stmt->bind_param('i', $ventaId);
    $stmt->execute();
    $venta = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$venta) {
        throw new RuntimeException('Venta no encontrada.');
    }

    $metodo = strtolower(trim((string) $venta['metodo_pago']));

    if (!in_array(
        $metodo,
        ['efectivo', 'tarjeta', 'transferencia'],
        true
    )) {
        throw new RuntimeException('Método de pago no compatible.');
    }

    if ($metodo === 'tarjeta' && empty($venta['mp_created_at'])) {
        throw new RuntimeException(
            'La venta de tarjeta no tiene una operación de Mercado Pago vinculada.'
        );
    }

    if (
        empty($venta['fecha_base']) ||
        $venta['dias_transcurridos'] === null
    ) {
        throw new RuntimeException(
            'No se pudo determinar la antigüedad de la venta.'
        );
    }

    $diasTranscurridos = (int) $venta['dias_transcurridos'];

    if ($diasTranscurridos < 0) {
        throw new RuntimeException(
            'La fecha de la venta es futura o no es válida.'
        );
    }

    $campos = [
        'cancelacion' => [
            'efectivo' => 'dias_cancelacion_efectivo',
            'tarjeta' => 'dias_cancelacion_tarjeta',
            'transferencia' => 'dias_cancelacion_transferencia',
        ],
        'devolucion' => [
            'efectivo' => 'dias_devolucion_efectivo',
            'tarjeta' => 'dias_devolucion_tarjeta',
            'transferencia' => 'dias_devolucion_transferencia',
        ],
    ];

    $campo = $campos[$accion][$metodo];
    $diasConfigurados = max(0, (int) $config[$campo]);
    $diasPermitidos = $diasConfigurados;

    if ($metodo === 'tarjeta') {
        $diasPermitidos = min(
            $diasConfigurados,
            DEVOLUCIONES_LIMITE_TECNICO_TARJETA_DIAS
        );
    }

    if ($diasTranscurridos > $diasPermitidos) {
        $verbo = $accion === 'cancelacion'
            ? 'cancelar'
            : 'devolver artículos de';

        $mensaje =
            'Ya no se puede ' . $verbo . ' esta venta. Han transcurrido ' .
            $diasTranscurridos . ' día(s) y el límite vigente es de ' .
            $diasPermitidos . ' día(s).';

        if (
            $metodo === 'tarjeta' &&
            $diasConfigurados >
                DEVOLUCIONES_LIMITE_TECNICO_TARJETA_DIAS
        ) {
            $mensaje .=
                ' La configuración solicitó ' . $diasConfigurados .
                ' días, pero Mercado Pago Point admite como máximo 90.';
        }

        throw new RuntimeException($mensaje);
    }

    return [
        'accion' => $accion,
        'metodo_pago' => $metodo,
        'dias_transcurridos' => $diasTranscurridos,
        'dias_configurados' => $diasConfigurados,
        'dias_permitidos' => $diasPermitidos,
        'dias_restantes' => max(
            0,
            $diasPermitidos - $diasTranscurridos
        ),
        'fecha_base' => (string) $venta['fecha_base'],
    ];
}
