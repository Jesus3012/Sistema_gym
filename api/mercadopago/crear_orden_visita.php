<?php

declare(strict_types=1);

/*
 * Crea una orden Point para el registro rápido de una visita de un día.
 * Reutiliza el mismo bootstrap, cliente, servicio, consulta, cancelación y
 * tabla mercadopago_operaciones que el módulo principal de Inscripciones.
 */

$conn = null;
$sucursalId = 0;
$terminalId = '';
$usuarioId = 0;

require_once __DIR__ . '/_bootstrap_inscripciones.php';

if (!$conn instanceof mysqli) {
    throw new RuntimeException(
        'No se inicializó la conexión con la base de datos.'
    );
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mp_api_error('Método no permitido.', 405);
}

/** @return mysqli_result */
function mp_visita_resultado(mysqli_stmt $stmt): mysqli_result
{
    $result = $stmt->get_result();

    if (!$result instanceof mysqli_result) {
        throw new RuntimeException(
            'No se pudo obtener el resultado de la consulta.'
        );
    }

    return $result;
}

function mp_visita_validar_acceso_actual(
    mysqli $conn,
    int $clienteId,
    string $telefono,
    string $email
): void {
    $cliente = null;

    if ($clienteId > 0) {
        $stmt = $conn->prepare(
            "SELECT id
             FROM clientes
             WHERE id = ?
               AND estado = 'activo'
             LIMIT 1"
        );
        $stmt->bind_param('i', $clienteId);
        $stmt->execute();
        $cliente = mp_visita_resultado($stmt)->fetch_assoc();
        $stmt->close();
    } elseif ($telefono !== '' || $email !== '') {
        if ($telefono !== '' && $email !== '') {
            $stmt = $conn->prepare(
                "SELECT id
                 FROM clientes
                 WHERE estado = 'activo'
                   AND (telefono = ? OR email = ?)
                 ORDER BY
                    CASE WHEN telefono = ? THEN 0 ELSE 1 END,
                    id ASC
                 LIMIT 1"
            );
            $stmt->bind_param('sss', $telefono, $email, $telefono);
        } elseif ($telefono !== '') {
            $stmt = $conn->prepare(
                "SELECT id
                 FROM clientes
                 WHERE estado = 'activo'
                   AND telefono = ?
                 LIMIT 1"
            );
            $stmt->bind_param('s', $telefono);
        } else {
            $stmt = $conn->prepare(
                "SELECT id
                 FROM clientes
                 WHERE estado = 'activo'
                   AND email = ?
                 LIMIT 1"
            );
            $stmt->bind_param('s', $email);
        }

        $stmt->execute();
        $cliente = mp_visita_resultado($stmt)->fetch_assoc();
        $stmt->close();
    }

    if (!is_array($cliente)) {
        return;
    }

    $clienteIdReal = (int) ($cliente['id'] ?? 0);

    $stmt = $conn->prepare(
        "UPDATE inscripciones
         SET estado = 'vencida'
         WHERE cliente_id = ?
           AND estado = 'activa'
           AND fecha_fin < CURDATE()"
    );
    $stmt->bind_param('i', $clienteIdReal);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT p.nombre, i.fecha_fin
         FROM inscripciones i
         INNER JOIN planes p
            ON p.id = i.plan_id
         WHERE i.cliente_id = ?
           AND i.estado = 'activa'
           AND CURDATE() BETWEEN i.fecha_inicio AND i.fecha_fin
         ORDER BY i.fecha_fin DESC
         LIMIT 1"
    );
    $stmt->bind_param('i', $clienteIdReal);
    $stmt->execute();
    $activa = mp_visita_resultado($stmt)->fetch_assoc();
    $stmt->close();

    if (!is_array($activa)) {
        return;
    }

    $fechaFin = date(
        'd/m/Y',
        strtotime((string) ($activa['fecha_fin'] ?? '')) ?: time()
    );

    mp_api_error(
        'Esta persona ya tiene acceso vigente con el plan '
        . (string) ($activa['nombre'] ?? 'activo')
        . ' hasta el ' . $fechaFin
        . '. La visita de un día solamente puede cobrarse cuando la membresía haya vencido.',
        409,
        ['code' => 'active_membership']
    );
}

function mp_visita_liberar_orden_pendiente(
    mysqli $conn,
    int $sucursalId,
    string $terminalId
): void {
    $stmt = $conn->prepare(
        "SELECT order_id
         FROM mercadopago_operaciones
         WHERE sucursal_id = ?
           AND terminal_id = ?
           AND (
                order_status IN ('created', 'at_terminal')
                OR payment_status IN ('created', 'at_terminal')
           )
         ORDER BY id DESC
         LIMIT 5"
    );
    $stmt->bind_param('is', $sucursalId, $terminalId);
    $stmt->execute();
    $rows = mp_visita_resultado($stmt)->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $row) {
        $orderId = trim((string) ($row['order_id'] ?? ''));

        if ($orderId === '') {
            continue;
        }

        try {
            $remote = mp_get_order($orderId);
            $data = mp_update_order_safe($conn, $remote);
        } catch (Throwable $error) {
            error_log(
                '[Point visita pendiente] '
                . $orderId . ': ' . $error->getMessage()
            );
            continue;
        }

        if (($data['order_status'] ?? '') === 'created') {
            try {
                $cancelada = mp_cancel_order($orderId);
                mp_update_order_safe($conn, $cancelada);
            } catch (Throwable $error) {
                error_log(
                    '[Point visita cancelación automática] '
                    . $orderId . ': ' . $error->getMessage()
                );
            }

            continue;
        }

        if (
            ($data['order_status'] ?? '') === 'at_terminal'
            || ($data['payment_status'] ?? '') === 'at_terminal'
        ) {
            mp_api_error(
                'La terminal ya tiene un cobro esperando confirmación. '
                . 'Termínalo o cancélalo directamente en la Point.',
                409,
                [
                    'code' => 'already_queued_order_on_terminal',
                    'requires_terminal' => true,
                    'order_id' => $orderId,
                ]
            );
        }
    }
}

function mp_visita_bloquear_aprobado_sin_vincular(
    mysqli $conn,
    int $sucursalId,
    string $terminalId
): void {
    $stmt = $conn->prepare(
        "SELECT order_id
         FROM mercadopago_operaciones
         WHERE sucursal_id = ?
           AND terminal_id = ?
           AND venta_id IS NULL
           AND pago_id IS NULL
           AND origen IN ('inscripcion', 'renovacion')
           AND (
                order_status = 'processed'
                OR payment_status = 'processed'
           )
           AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->bind_param('is', $sucursalId, $terminalId);
    $stmt->execute();
    $row = mp_visita_resultado($stmt)->fetch_assoc();
    $stmt->close();

    if (!is_array($row)) {
        return;
    }

    $orderId = trim((string) ($row['order_id'] ?? ''));

    mp_api_error(
        'Existe un pago aprobado que todavía no fue vinculado a una inscripción. '
        . 'No vuelvas a cobrar. Orden: ' . $orderId,
        409,
        [
            'code' => 'processed_order_not_linked',
            'order_id' => $orderId,
        ]
    );
}

try {
    if ($sucursalId <= 0 || $usuarioId <= 0) {
        mp_api_error(
            'Tu sesión no contiene una sucursal operativa válida.',
            401
        );
    }

    if ($terminalId === '') {
        mp_api_error(
            'La sucursal activa no tiene una terminal Point configurada.',
            409,
            ['code' => 'terminal_not_configured']
        );
    }

    $input = mp_api_input();

    $csrf = trim((string) ($input['csrf'] ?? ''));
    $csrfSesion = trim((string) (
        $_SESSION['dashboard_visita_csrf'] ?? ''
    ));

    if (
        $csrf === ''
        || $csrfSesion === ''
        || !hash_equals($csrfSesion, $csrf)
    ) {
        mp_api_error(
            'La página cambió o la sesión venció. Recarga el dashboard.',
            419,
            ['code' => 'csrf_invalid']
        );
    }

    $planId = (int) ($input['plan_id'] ?? 0);
    $totalCliente = round((float) ($input['total'] ?? 0), 2);
    $paymentType = trim((string) ($input['payment_type'] ?? ''));
    $clienteId = (int) ($input['cliente_id'] ?? 0);
    $nombre = trim((string) ($input['nombre'] ?? ''));
    $apellido = trim((string) ($input['apellido'] ?? ''));
    $telefono = trim((string) ($input['telefono'] ?? ''));
    $email = strtolower(trim((string) ($input['email'] ?? '')));

    if (!in_array(
        $paymentType,
        ['debit_card', 'credit_card'],
        true
    )) {
        mp_api_error('Tipo de tarjeta inválido.');
    }

    if ($planId <= 0) {
        mp_api_error('Selecciona un plan de visita válido.');
    }

    if ($nombre === '' || $apellido === '') {
        mp_api_error(
            'Completa nombre y apellidos antes de enviar el cobro.'
        );
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        mp_api_error('El correo electrónico no tiene un formato válido.');
    }

    $stmt = $conn->prepare(
        "SELECT p.nombre, p.duracion_dias, ps.precio
         FROM planes p
         INNER JOIN planes_sucursales ps
            ON ps.plan_id = p.id
           AND ps.sucursal_id = ?
         WHERE p.id = ?
           AND p.duracion_dias = 1
           AND p.estado = 'activo'
           AND ps.estado = 'activo'
         LIMIT 1"
    );
    $stmt->bind_param('ii', $sucursalId, $planId);
    $stmt->execute();
    $plan = mp_visita_resultado($stmt)->fetch_assoc();
    $stmt->close();

    if (!is_array($plan)) {
        mp_api_error(
            'El plan seleccionado no es un plan de un día activo en esta sucursal.'
        );
    }

    $totalDb = round((float) ($plan['precio'] ?? 0), 2);

    if (
        $totalDb <= 0
        || abs($totalDb - $totalCliente) > 0.01
    ) {
        mp_api_error(
            'El precio del plan cambió. Actualiza el dashboard antes de cobrar.',
            409,
            [
                'total_cliente' => $totalCliente,
                'total_bd' => $totalDb,
            ]
        );
    }

    mp_visita_validar_acceso_actual(
        $conn,
        $clienteId,
        $telefono,
        $email
    );

    mp_visita_bloquear_aprobado_sin_vincular(
        $conn,
        $sucursalId,
        $terminalId
    );

    mp_visita_liberar_orden_pendiente(
        $conn,
        $sucursalId,
        $terminalId
    );

    $externalReference = sprintf(
        'GYM-INS-VISITA-%s-%d-%s',
        date('YmdHis'),
        $usuarioId,
        substr(bin2hex(random_bytes(4)), 0, 8)
    );

    $descripcion = 'Visita de un día - '
        . (string) ($plan['nombre'] ?? 'Plan');

    try {
        $order = mp_create_point_order(
            $totalDb,
            $paymentType,
            $externalReference,
            $descripcion,
            $terminalId
        );
    } catch (Throwable $error) {
        $properties = get_object_vars($error);
        $httpCode = (int) ($properties['mp_http_code'] ?? 0);
        $mpResponse = isset($properties['mp_response'])
            && is_array($properties['mp_response'])
                ? $properties['mp_response']
                : [];
        $errors = $mpResponse['errors'] ?? [];
        $code = '';

        if (
            is_array($errors)
            && isset($errors[0])
            && is_array($errors[0])
        ) {
            $code = (string) ($errors[0]['code'] ?? '');
        }

        if (
            $httpCode === 409
            && $code === 'already_queued_order_on_terminal'
        ) {
            mp_visita_liberar_orden_pendiente(
                $conn,
                $sucursalId,
                $terminalId
            );

            mp_api_error(
                'La terminal ya tiene otro cobro pendiente. '
                . 'Termínalo o cancélalo directamente en la Point.',
                409,
                [
                    'code' => $code,
                    'requires_terminal' => true,
                ]
            );
        }

        throw $error;
    }

    $saved = mp_save_order(
        $conn,
        $order,
        $paymentType,
        1,
        $usuarioId
    );

    mp_marcar_origen_orden(
        $conn,
        (string) ($saved['order_id'] ?? ''),
        'inscripcion'
    );

    mp_api_ok([
        'order_id' => $saved['order_id'] ?? '',
        'payment_id' => $saved['payment_id'] ?? '',
        'external_reference' =>
            $saved['external_reference'] ?? '',
        'payment_reference_id' =>
            $saved['payment_reference_id'] ?? '',
        'order_status' => $saved['order_status'] ?? '',
        'payment_status' => $saved['payment_status'] ?? '',
        'amount' => $totalDb,
        'payment_type' =>
            $saved['payment_type'] ?? $paymentType,
        'installments' => (int) (
            $saved['installments'] ?? 1
        ),
        'terminal_id' =>
            $saved['terminal_id'] ?? $terminalId,
        'message' => $paymentType === 'credit_card'
            ? 'El cobro fue enviado a la Point. Las mensualidades se eligen en la terminal.'
            : 'El cobro fue enviado como tarjeta de débito a la Point.',
    ]);
} catch (Throwable $error) {
    mp_api_exception($error);
}
