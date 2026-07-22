<?php
declare(strict_types=1);

/**
 * Calcula el importe que corresponde al registrar una persona en una clase.
 *
 * Reglas:
 * - Persona externa: paga el precio completo.
 * - Socio con membresía activa en la fecha de la clase: no paga.
 * - Socio sin membresía, con membresía vencida o cancelada: paga completo.
 *
 * Este archivo se incluye desde inscripciones_clases.php antes de registrar
 * el pago o insertar la inscripción a la clase.
 */
function clases_calcular_cobro(
    mysqli $conn,
    int $claseId,
    ?int $clienteId,
    ?string $fechaClase = null
): array {
    $fechaClase = $fechaClase ?: date('Y-m-d');

    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaClase);
    if (!$fecha instanceof DateTimeImmutable || $fecha->format('Y-m-d') !== $fechaClase) {
        throw new InvalidArgumentException('La fecha de la clase no es válida.');
    }

    $stmtClase = $conn->prepare(
        "SELECT id, sucursal_id, nombre, precio_clase, estado
         FROM clases
         WHERE id = ?
         LIMIT 1"
    );
    $stmtClase->bind_param('i', $claseId);
    $stmtClase->execute();
    $clase = $stmtClase->get_result()->fetch_assoc();
    $stmtClase->close();

    if (!$clase || $clase['estado'] !== 'activa') {
        throw new RuntimeException('La clase seleccionada no está disponible.');
    }

    $precio = round((float) $clase['precio_clase'], 2);

    if ($clienteId === null || $clienteId <= 0) {
        return [
            'clase_id' => (int) $clase['id'],
            'sucursal_id' => (int) $clase['sucursal_id'],
            'precio_clase' => $precio,
            'monto_cobrar' => $precio,
            'cubierto_membresia' => false,
            'inscripcion_membresia_id' => null,
            'tipo_participante' => 'externo',
            'motivo' => 'La persona es externa y debe cubrir el precio de la clase.',
        ];
    }

    $stmtCliente = $conn->prepare(
        "SELECT id, estado
         FROM clientes
         WHERE id = ?
         LIMIT 1"
    );
    $stmtCliente->bind_param('i', $clienteId);
    $stmtCliente->execute();
    $cliente = $stmtCliente->get_result()->fetch_assoc();
    $stmtCliente->close();

    if (!$cliente) {
        throw new RuntimeException('El socio seleccionado no existe.');
    }

    $stmtMembresia = $conn->prepare(
        "SELECT id, fecha_inicio, fecha_fin
         FROM inscripciones
         WHERE cliente_id = ?
           AND estado = 'activa'
           AND fecha_inicio <= ?
           AND fecha_fin >= ?
         ORDER BY fecha_fin DESC, id DESC
         LIMIT 1"
    );
    $stmtMembresia->bind_param(
        'iss',
        $clienteId,
        $fechaClase,
        $fechaClase
    );
    $stmtMembresia->execute();
    $membresia = $stmtMembresia->get_result()->fetch_assoc();
    $stmtMembresia->close();

    $cubierto = $cliente['estado'] === 'activo' && (bool) $membresia;

    return [
        'clase_id' => (int) $clase['id'],
        'sucursal_id' => (int) $clase['sucursal_id'],
        'precio_clase' => $precio,
        'monto_cobrar' => $cubierto ? 0.00 : $precio,
        'cubierto_membresia' => $cubierto,
        'inscripcion_membresia_id' => $cubierto
            ? (int) $membresia['id']
            : null,
        'tipo_participante' => 'socio',
        'motivo' => $cubierto
            ? 'La membresía está activa y cubre la clase.'
            : 'El socio no tiene una membresía vigente y debe cubrir el precio de la clase.',
    ];
}
