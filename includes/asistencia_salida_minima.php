<?php
declare(strict_types=1);

/*
 * Impide registrar una salida antes de que hayan transcurrido
 * los minutos mínimos desde la entrada abierta del socio.
 *
 * Requiere que asistencia_context.php ya esté cargado porque usa
 * asistencia_error() para devolver una respuesta JSON consistente.
 */
function asistencia_exigir_tiempo_minimo_salida(
    mysqli $conn,
    int $sucursalId,
    int $clienteId,
    int $minutosMinimos = 5
): void {
    if (
        $sucursalId <= 0
        || $clienteId <= 0
        || $minutosMinimos <= 0
    ) {
        return;
    }

    $segundosMinimos = $minutosMinimos * 60;

    $stmt = $conn->prepare(
        "SELECT
            id,
            hora_entrada,
            hora_salida,
            GREATEST(
                TIMESTAMPDIFF(
                    SECOND,
                    TIMESTAMP(fecha, hora_entrada),
                    NOW()
                ),
                0
            ) AS segundos_desde_entrada,
            DATE_FORMAT(
                DATE_ADD(
                    TIMESTAMP(fecha, hora_entrada),
                    INTERVAL ? MINUTE
                ),
                '%H:%i:%s'
            ) AS salida_disponible
         FROM asistencias
         WHERE sucursal_id = ?
           AND cliente_id = ?
           AND fecha = CURDATE()
         ORDER BY id DESC
         LIMIT 1"
    );

    $stmt->bind_param(
        'iii',
        $minutosMinimos,
        $sucursalId,
        $clienteId
    );

    $stmt->execute();
    $registro = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (
        !is_array($registro)
        || !empty($registro['hora_salida'])
    ) {
        return;
    }

    $segundosTranscurridos = max(
        0,
        (int) ($registro['segundos_desde_entrada'] ?? 0)
    );

    if ($segundosTranscurridos >= $segundosMinimos) {
        return;
    }

    $segundosRestantes = max(
        1,
        $segundosMinimos - $segundosTranscurridos
    );

    $minutosRestantes = (int) ceil(
        $segundosRestantes / 60
    );

    $salidaDisponible = trim((string) (
        $registro['salida_disponible'] ?? ''
    ));

    $mensaje = sprintf(
        'La entrada ya está registrada. La salida podrá marcarse después de %d minutos desde la entrada. Falta aproximadamente %d %s%s.',
        $minutosMinimos,
        $minutosRestantes,
        $minutosRestantes === 1 ? 'minuto' : 'minutos',
        $salidaDisponible !== ''
            ? ' (disponible a las ' . $salidaDisponible . ')'
            : ''
    );

    asistencia_error(
        $mensaje,
        409,
        [
            'code' => 'salida_espera_minima',
            'minutos_minimos' => $minutosMinimos,
            'segundos_restantes' => $segundosRestantes,
            'salida_disponible' => $salidaDisponible,
            'hora_entrada' => (string) (
                $registro['hora_entrada'] ?? ''
            ),
        ]
    );
}
