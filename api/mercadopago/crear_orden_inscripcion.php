<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/mercadopago_inscripciones.php';

function mp_validar_renovacion_antes_de_cobrar(
    mysqli $conn,
    int $inscripcionId,
    string $fechaInicio
): void {
    if ($inscripcionId <= 0) {
        mp_api_error('Falta la inscripción que deseas renovar.');
    }

    if (
        $fechaInicio === '' ||
        strtotime($fechaInicio) < strtotime(date('Y-m-d'))
    ) {
        mp_api_error('La fecha de inicio no puede ser anterior a hoy.');
    }

    $stmt = $conn->prepare(
        "SELECT
            i.estado,
            i.fecha_fin,
            p.duracion_dias,
            c.estado AS cliente_estado
         FROM inscripciones i
         INNER JOIN planes p ON p.id = i.plan_id
         INNER JOIN clientes c ON c.id = i.cliente_id
         WHERE i.id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'No se pudo validar la renovación: ' . $conn->error
        );
    }

    $stmt->bind_param('i', $inscripcionId);
    $stmt->execute();
    $actual = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$actual) {
        mp_api_error('La inscripción seleccionada no existe.');
    }

    if (($actual['cliente_estado'] ?? '') !== 'activo') {
        mp_api_error('El socio está inactivo y no puede renovar.');
    }

    if (($actual['estado'] ?? '') === 'cancelada') {
        mp_api_error('No se puede renovar una inscripción cancelada.');
    }

    if (($actual['estado'] ?? '') === 'activa') {
        if ((int) ($actual['duracion_dias'] ?? 0) === 1) {
            $hoy = new DateTime(date('Y-m-d'));
            $fechaFin = new DateTime((string) $actual['fecha_fin']);
            $hoy->setTime(0, 0, 0);
            $fechaFin->setTime(0, 0, 0);

            if ($hoy <= $fechaFin) {
                mp_api_error(
                    'El plan de un día sigue activo. Podrás renovarlo mañana.'
                );
            }
        } else {
            mp_api_error(
                'La inscripción sigue activa. Espera a que venza para renovarla.'
            );
        }
    }
}

try {
    $input = mp_api_input();

    $planId = (int) ($input['plan_id'] ?? 0);
    $totalCliente = round((float) ($input['total'] ?? 0), 2);
    $paymentType = trim((string) ($input['payment_type'] ?? ''));
    $operacion = trim((string) ($input['operation'] ?? 'new'));

    if (!in_array($paymentType, ['debit_card', 'credit_card'], true)) {
        mp_api_error('Tipo de tarjeta inválido.');
    }

    if (!in_array($operacion, ['new', 'renewal'], true)) {
        mp_api_error('Tipo de operación inválido.');
    }

    if ($planId <= 0) {
        mp_api_error('Selecciona un plan válido.');
    }

    if ($operacion === 'new') {
        $nombre = trim((string) ($input['nombre'] ?? ''));
        $apellido = trim((string) ($input['apellido'] ?? ''));
        $telefono = trim((string) ($input['telefono'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));

        if ($nombre === '' || $apellido === '' || $telefono === '') {
            mp_api_error(
                'Completa nombre, apellido y teléfono antes de cobrar.'
            );
        }

        $stmtDuplicado = $conn->prepare(
            "SELECT id
             FROM clientes
             WHERE telefono = ? OR (email = ? AND email <> '')
             LIMIT 1"
        );

        if (!$stmtDuplicado) {
            throw new RuntimeException(
                'No se pudo validar al socio: ' . $conn->error
            );
        }

        $stmtDuplicado->bind_param('ss', $telefono, $email);
        $stmtDuplicado->execute();
        $duplicado = $stmtDuplicado->get_result()->fetch_assoc();
        $stmtDuplicado->close();

        if ($duplicado) {
            mp_api_error(
                'Ya existe un socio con ese teléfono o correo.'
            );
        }
    } else {
        mp_validar_renovacion_antes_de_cobrar(
            $conn,
            (int) ($input['inscripcion_id'] ?? 0),
            trim((string) ($input['fecha_inicio'] ?? ''))
        );
    }

    $stmt = $conn->prepare(
        "SELECT nombre, precio
         FROM planes
         WHERE id = ? AND estado = 'activo'
         LIMIT 1"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'No se pudo consultar el plan: ' . $conn->error
        );
    }

    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$plan) {
        mp_api_error('El plan ya no está disponible.');
    }

    $totalDb = round((float) $plan['precio'], 2);

    if ($totalDb <= 0 || abs($totalDb - $totalCliente) > 0.01) {
        mp_api_error(
            'El precio del plan cambió. Actualiza el formulario antes de cobrar.',
            409,
            [
                'total_cliente' => $totalCliente,
                'total_bd' => $totalDb,
            ]
        );
    }

    $origen = $operacion === 'renewal'
        ? 'renovacion'
        : 'inscripcion';

    $externalReference = sprintf(
        'GYM-INS-%s-%s-%d-%s',
        $operacion === 'renewal' ? 'REN' : 'NUEVA',
        date('YmdHis'),
        (int) $_SESSION['user_id'],
        substr(bin2hex(random_bytes(4)), 0, 8)
    );

    $descripcion = (
        $operacion === 'renewal'
            ? 'Renovacion'
            : 'Inscripcion'
    ) . ' - ' . (string) $plan['nombre'];

    /*
     * Point Orders permite enviar credit_card o debit_card.
     * No se envía default_installments porque la API lo rechaza.
     */
    $order = mp_create_point_order(
        $totalDb,
        $paymentType,
        $externalReference,
        $descripcion
    );

    $saved = mp_save_order(
        $conn,
        $order,
        $paymentType,
        1,
        (int) $_SESSION['user_id']
    );

    mp_marcar_origen_orden(
        $conn,
        (string) $saved['order_id'],
        $origen
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
        'installments_control' => 'terminal',
        'message' => $paymentType === 'credit_card'
            ? 'Las mensualidades disponibles se eligen en la terminal.'
            : 'Cobro enviado como tarjeta de débito.',
    ]);
} catch (Throwable $error) {
    mp_api_exception($error);
}
