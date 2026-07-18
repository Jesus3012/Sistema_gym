<?php
declare(strict_types=1);

/*
 * Este buffer y este manejador se instalan antes de cargar cualquier archivo.
 * Así un error en el bootstrap o en un require nunca devuelve HTML al fetch().
 */
if (ob_get_level() === 0) {
    ob_start();
}

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

function mp_endpoint_emergency_json(
    Throwable $error
): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    http_response_code(500);

    error_log(
        '[crear_orden_inscripcion][bootstrap] ' .
        get_class($error) .
        ': ' .
        $error->getMessage() .
        ' en ' .
        $error->getFile() .
        ':' .
        $error->getLine()
    );

    echo json_encode(
        [
            'success' => false,
            'message' =>
                'No se pudo cargar correctamente el endpoint Point: ' .
                $error->getMessage(),
            'code' => 'bootstrap_load_error',
            'file' => basename($error->getFile()),
            'line' => $error->getLine(),
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/** @var mysqli|null $conn */
$conn = null;

/** @var int $sucursalId */
$sucursalId = 0;

/** @var string $terminalId */
$terminalId = '';

/** @var int $usuarioId */
$usuarioId = 0;

try {
    $bootstrapFile =
        __DIR__ . '/_bootstrap_inscripciones.php';

    if (!is_file($bootstrapFile)) {
        throw new RuntimeException(
            'No existe api/mercadopago/_bootstrap_inscripciones.php.'
        );
    }

    require_once $bootstrapFile;
} catch (Throwable $error) {
    mp_endpoint_emergency_json($error);
}

if (!function_exists('mp_api_error')) {
    mp_endpoint_emergency_json(
        new RuntimeException(
            'El bootstrap no definió mp_api_error().'
        )
    );
}

if (!function_exists('mp_api_ok')) {
    mp_endpoint_emergency_json(
        new RuntimeException(
            'El bootstrap no definió mp_api_ok().'
        )
    );
}

if (!$conn instanceof mysqli) {
    mp_api_error(
        'El bootstrap no inicializó la conexión mysqli.',
        500,
        ['code' => 'database_not_initialized']
    );
}

if ($sucursalId <= 0) {
    mp_api_error(
        'Selecciona una sucursal operativa antes de cobrar.',
        409
    );
}

if ($terminalId === '') {
    mp_api_error(
        'MP_TERMINAL_ID no está disponible en el endpoint.',
        500,
        ['code' => 'terminal_not_configured']
    );
}

if ($usuarioId <= 0) {
    mp_api_error(
        'Tu sesión terminó. Inicia sesión nuevamente.',
        401
    );
}
/**
 * Obtiene el resultado de una consulta preparada.
 * Esta función también evita advertencias de Intelephense sobre get_result().
 */
function mp_resultado_stmt(mysqli_stmt $stmt): mysqli_result
{
    $result = $stmt->get_result();

    if (!$result instanceof mysqli_result) {
        throw new RuntimeException(
            'No se pudo obtener el resultado de la consulta.'
        );
    }

    return $result;
}

function mp_validar_renovacion_antes_de_cobrar(
    mysqli $conn,
    int $sucursalId,
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
         LEFT JOIN inscripciones_sucursales acceso
            ON acceso.inscripcion_id = i.id
           AND acceso.sucursal_id = ?
         WHERE i.id = ?
           AND (
                i.sucursal_id = ?
                OR acceso.sucursal_id IS NOT NULL
           )
         LIMIT 1"
    );

    if (!$stmt instanceof mysqli_stmt) {
        throw new RuntimeException(
            'No se pudo preparar la validación de la renovación: ' .
            $conn->error
        );
    }

    $stmt->bind_param(
        'iii',
        $sucursalId,
        $inscripcionId,
        $sucursalId
    );
    $stmt->execute();

    $actual = mp_resultado_stmt($stmt)->fetch_assoc();
    $stmt->close();

    if (!is_array($actual)) {
        mp_api_error(
            'La inscripción no existe en la sucursal activa.'
        );
    }

    if (($actual['cliente_estado'] ?? '') !== 'activo') {
        mp_api_error('El socio está inactivo y no puede renovar.');
    }

    if (($actual['estado'] ?? '') === 'cancelada') {
        mp_api_error(
            'No se puede renovar una inscripción cancelada.'
        );
    }

    if (($actual['estado'] ?? '') !== 'activa') {
        return;
    }

    if ((int) ($actual['duracion_dias'] ?? 0) === 1) {
        $hoy = new DateTime(date('Y-m-d'));
        $fechaFin = new DateTime(
            (string) ($actual['fecha_fin'] ?? date('Y-m-d'))
        );

        $hoy->setTime(0, 0, 0);
        $fechaFin->setTime(0, 0, 0);

        if ($hoy <= $fechaFin) {
            mp_api_error(
                'El plan de un día sigue activo. ' .
                'Podrás renovarlo mañana.'
            );
        }

        return;
    }

    mp_api_error(
        'La inscripción sigue activa. ' .
        'Espera a que venza para renovarla.'
    );
}

function mp_liberar_orden_created_si_existe(
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

    if (!$stmt instanceof mysqli_stmt) {
        throw new RuntimeException(
            'No se pudo consultar las órdenes pendientes: ' .
            $conn->error
        );
    }

    $stmt->bind_param('is', $sucursalId, $terminalId);
    $stmt->execute();

    $rows = mp_resultado_stmt($stmt)->fetch_all(MYSQLI_ASSOC);
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
                '[Point pendiente] ' .
                $orderId .
                ': ' .
                $error->getMessage()
            );
            continue;
        }

        if (($data['order_status'] ?? '') === 'created') {
            try {
                $canceled = mp_cancel_order($orderId);
                mp_update_order_safe($conn, $canceled);
            } catch (Throwable $error) {
                error_log(
                    '[Point cancelación automática] ' .
                    $orderId .
                    ': ' .
                    $error->getMessage()
                );
            }

            continue;
        }

        if (
            ($data['order_status'] ?? '') === 'at_terminal' ||
            ($data['payment_status'] ?? '') === 'at_terminal'
        ) {
            mp_api_error(
                'La terminal tiene un cobro esperando confirmación. ' .
                'Termínalo o cancélalo directamente en la Point.',
                409,
                [
                    'code' =>
                        'already_queued_order_on_terminal',
                    'requires_terminal' => true,
                    'order_id' => $orderId,
                ]
            );
        }
    }
}

function mp_bloquear_pago_aprobado_sin_vincular(
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

    if (!$stmt instanceof mysqli_stmt) {
        throw new RuntimeException(
            'No se pudo consultar pagos pendientes de vincular: ' .
            $conn->error
        );
    }

    $stmt->bind_param('is', $sucursalId, $terminalId);
    $stmt->execute();

    $row = mp_resultado_stmt($stmt)->fetch_assoc();
    $stmt->close();

    if (!is_array($row)) {
        return;
    }

    mp_api_error(
        'Existe un pago aprobado que todavía no fue vinculado ' .
        'a una inscripción. No vuelvas a cobrar. Orden: ' .
        (string) ($row['order_id'] ?? ''),
        409,
        [
            'code' => 'processed_order_not_linked',
            'order_id' => (string) ($row['order_id'] ?? ''),
        ]
    );
}

try {
    $input = mp_api_input();

    $planId = (int) ($input['plan_id'] ?? 0);
    $totalCliente = round(
        (float) ($input['total'] ?? 0),
        2
    );
    $paymentType = trim((string) (
        $input['payment_type'] ?? ''
    ));
    $operacion = trim((string) (
        $input['operation'] ?? 'new'
    ));

    if (!in_array(
        $paymentType,
        ['debit_card', 'credit_card'],
        true
    )) {
        mp_api_error('Tipo de tarjeta inválido.');
    }

    if (!in_array(
        $operacion,
        ['new', 'renewal'],
        true
    )) {
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

        if (
            $nombre === '' ||
            $apellido === '' ||
            $telefono === ''
        ) {
            mp_api_error(
                'Completa nombre, apellido y teléfono antes de cobrar.'
            );
        }

        $stmtDuplicado = $conn->prepare(
            "SELECT id
             FROM clientes
             WHERE telefono = ?
                OR (email = ? AND email <> '')
             LIMIT 1"
        );

        if (!$stmtDuplicado instanceof mysqli_stmt) {
            throw new RuntimeException(
                'No se pudo validar el socio duplicado: ' .
                $conn->error
            );
        }

        $stmtDuplicado->bind_param('ss', $telefono, $email);
        $stmtDuplicado->execute();

        $duplicado = mp_resultado_stmt(
            $stmtDuplicado
        )->fetch_assoc();
        $stmtDuplicado->close();

        if (is_array($duplicado)) {
            mp_api_error(
                'Ya existe un socio con ese teléfono o correo.'
            );
        }
    } else {
        mp_validar_renovacion_antes_de_cobrar(
            $conn,
            $sucursalId,
            (int) ($input['inscripcion_id'] ?? 0),
            trim((string) ($input['fecha_inicio'] ?? ''))
        );
    }

    $stmtPlan = $conn->prepare(
        "SELECT
            p.nombre,
            ps.precio
         FROM planes p
         INNER JOIN planes_sucursales ps
            ON ps.plan_id = p.id
           AND ps.sucursal_id = ?
         WHERE p.id = ?
           AND p.estado = 'activo'
           AND ps.estado = 'activo'
         LIMIT 1"
    );

    if (!$stmtPlan instanceof mysqli_stmt) {
        throw new RuntimeException(
            'No se pudo consultar el plan de la sucursal: ' .
            $conn->error
        );
    }

    $stmtPlan->bind_param('ii', $sucursalId, $planId);
    $stmtPlan->execute();

    $plan = mp_resultado_stmt($stmtPlan)->fetch_assoc();
    $stmtPlan->close();

    if (!is_array($plan)) {
        mp_api_error(
            'El plan no está disponible en esta sucursal.'
        );
    }

    $totalDb = round((float) ($plan['precio'] ?? 0), 2);

    if (
        $totalDb <= 0 ||
        abs($totalDb - $totalCliente) > 0.01
    ) {
        mp_api_error(
            'El precio del plan cambió. ' .
            'Actualiza el formulario antes de cobrar.',
            409,
            [
                'total_cliente' => $totalCliente,
                'total_bd' => $totalDb,
            ]
        );
    }

    mp_bloquear_pago_aprobado_sin_vincular(
        $conn,
        $sucursalId,
        $terminalId
    );

    mp_liberar_orden_created_si_existe(
        $conn,
        $sucursalId,
        $terminalId
    );

    $origen = $operacion === 'renewal'
        ? 'renovacion'
        : 'inscripcion';

    $externalReference = sprintf(
        'GYM-INS-%s-%s-%d-%s',
        $operacion === 'renewal' ? 'REN' : 'NUEVA',
        date('YmdHis'),
        $usuarioId,
        substr(bin2hex(random_bytes(4)), 0, 8)
    );

    $descripcion = (
        $operacion === 'renewal'
            ? 'Renovación'
            : 'Inscripción'
    ) . ' - ' . (string) ($plan['nombre'] ?? 'Plan');

    try {
        $order = mp_create_point_order(
            $totalDb,
            $paymentType,
            $externalReference,
            $descripcion,
            $terminalId
        );
    } catch (Throwable $error) {
        /*
         * No se usa "catch (MpHttpException ...)" para evitar que
         * Intelephense marque la clase como desconocida cuando todavía
         * no ha indexado mercadopago_client.php.
         */
        $properties = get_object_vars($error);
        $httpCode = (int) ($properties['mp_http_code'] ?? 0);
        $mpResponse = isset($properties['mp_response']) &&
            is_array($properties['mp_response'])
                ? $properties['mp_response']
                : [];

        $errors = $mpResponse['errors'] ?? [];
        $code = '';

        if (
            is_array($errors) &&
            isset($errors[0]) &&
            is_array($errors[0])
        ) {
            $code = (string) ($errors[0]['code'] ?? '');
        }

        if (
            $httpCode === 409 &&
            $code === 'already_queued_order_on_terminal'
        ) {
            mp_liberar_orden_created_si_existe(
                $conn,
                $sucursalId,
                $terminalId
            );

            mp_api_error(
                'La terminal ya tiene otro cobro pendiente. ' .
                'Termínalo o cancélalo directamente en la Point.',
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
        $origen
    );

    mp_api_ok([
        'order_id' => $saved['order_id'] ?? '',
        'payment_id' => $saved['payment_id'] ?? '',
        'external_reference' =>
            $saved['external_reference'] ?? '',
        'order_status' => $saved['order_status'] ?? '',
        'payment_status' => $saved['payment_status'] ?? '',
        'amount' => $totalDb,
        'payment_type' => $saved['payment_type'] ?? $paymentType,
        'installments' => (int) ($saved['installments'] ?? 1),
        'terminal_id' => $saved['terminal_id'] ?? $terminalId,
        'installments_control' => 'terminal',
        'message' => $paymentType === 'credit_card'
            ? 'Las mensualidades disponibles se eligen en la terminal.'
            : 'Cobro enviado como tarjeta de débito.',
    ]);
} catch (Throwable $error) {
    mp_api_exception($error);
}
