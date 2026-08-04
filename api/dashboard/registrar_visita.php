<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/dashboard_visitas_helper.php';
require_once dirname(__DIR__, 2) . '/includes/qr_helper.php';
require_once dirname(__DIR__, 2) . '/includes/mercadopago_inscripciones.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    dashboard_visitas_responder([
        'success' => false,
        'message' => 'Método no permitido.',
    ], 405);
}

$contexto = dashboard_visitas_contexto();
/** @var mysqli $db */
$db = $contexto['db'];
$usuarioId = (int) $contexto['usuario_id'];
$sucursalId = (int) $contexto['sucursal_id'];

$csrf = (string) ($_POST['csrf'] ?? '');
$csrfSesion = (string) ($_SESSION['dashboard_visita_csrf'] ?? '');

if (
    $csrf === ''
    || $csrfSesion === ''
    || !hash_equals($csrfSesion, $csrf)
) {
    dashboard_visitas_responder([
        'success' => false,
        'message' => 'La página cambió o la sesión venció. Recarga el dashboard.',
    ], 419);
}

$requestId = strtolower(trim((string) ($_POST['request_id'] ?? '')));
if (preg_match('/^[a-f0-9]{16,80}$/', $requestId) !== 1) {
    dashboard_visitas_responder([
        'success' => false,
        'message' => 'La solicitud no contiene un identificador válido.',
    ], 422);
}

$operacionesRecientes = (array) (
    $_SESSION['dashboard_visitas_operaciones'] ?? []
);

if (isset($operacionesRecientes[$requestId])) {
    dashboard_visitas_responder(
        (array) $operacionesRecientes[$requestId]
    );
}

$clienteIdSolicitado = (int) ($_POST['cliente_id'] ?? 0);
$planId = (int) ($_POST['plan_id'] ?? 0);
$nombre = dashboard_visitas_texto((string) ($_POST['nombre'] ?? ''), 100);
$apellido = dashboard_visitas_texto((string) ($_POST['apellido'] ?? ''), 100);
$telefono = dashboard_visitas_texto((string) ($_POST['telefono'] ?? ''), 20);
$email = strtolower(dashboard_visitas_texto(
    (string) ($_POST['email'] ?? ''),
    100
));
$emergenciaNombre = dashboard_visitas_texto(
    (string) ($_POST['contacto_emergencia_nombre'] ?? ''),
    150
);
$emergenciaTelefono = dashboard_visitas_texto(
    (string) ($_POST['contacto_emergencia_telefono'] ?? ''),
    25
);
$metodoPagoEntrada = strtolower(trim((string) ($_POST['metodo_pago'] ?? '')));
$referencia = dashboard_visitas_texto(
    (string) ($_POST['referencia'] ?? ''),
    100
);

if ($planId <= 0) {
    dashboard_visitas_responder([
        'success' => false,
        'message' => 'Selecciona un plan de visita.',
    ], 422);
}

if (!in_array(
    $metodoPagoEntrada,
    ['efectivo', 'transferencia', 'tarjeta_debito', 'tarjeta_credito'],
    true
)) {
    dashboard_visitas_responder([
        'success' => false,
        'message' => 'Selecciona un método de pago válido.',
    ], 422);
}

/*
 * La base actual agrupa débito y crédito dentro del valor "tarjeta".
 * El subtipo se conserva en referencia para que siga visible en el historial
 * sin modificar los ENUM usados por pagos, historial y corte de caja.
 */
$esPagoTarjeta = in_array(
    $metodoPagoEntrada,
    ['tarjeta_debito', 'tarjeta_credito'],
    true
);
$metodoPagoDb = $esPagoTarjeta ? 'tarjeta' : $metodoPagoEntrada;
$tipoTarjeta = $metodoPagoEntrada === 'tarjeta_credito'
    ? 'Crédito'
    : ($metodoPagoEntrada === 'tarjeta_debito' ? 'Débito' : '');
$mpPagoData = null;
$mpOrderIdRecibido = trim((string) ($_POST['mp_order_id'] ?? ''));

if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    dashboard_visitas_responder([
        'success' => false,
        'message' => 'El correo electrónico no tiene un formato válido.',
    ], 422);
}

if (
    $telefono !== ''
    && preg_match('/^[0-9+()\-\s]{7,20}$/', $telefono) !== 1
) {
    dashboard_visitas_responder([
        'success' => false,
        'message' => 'El teléfono debe contener entre 7 y 20 caracteres válidos.',
    ], 422);
}

if (
    $emergenciaTelefono !== ''
    && preg_match('/^[0-9+()\-\s]{7,25}$/', $emergenciaTelefono) !== 1
) {
    dashboard_visitas_responder([
        'success' => false,
        'message' => 'El teléfono de emergencia no tiene un formato válido.',
    ], 422);
}

$archivoQrCreado = '';
$transaccionActiva = false;

try {
    $stmt = $db->prepare(
        "SELECT
            p.id,
            p.nombre,
            p.duracion_dias,
            ps.precio
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
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$plan) {
        throw new DomainException(
            'El plan seleccionado no es un plan de un día activo en esta sucursal.'
        );
    }

    $precio = round((float) $plan['precio'], 2);
    if ($precio < 0) {
        throw new DomainException('El precio del plan no es válido.');
    }

    /*
     * Para tarjeta, la visita solo puede guardarse después de que el mismo
     * helper de Inscripciones confirme la order procesada, el monto y el tipo
     * de tarjeta. Nunca se confía únicamente en los campos del navegador.
     */
    if ($esPagoTarjeta) {
        if (
            !function_exists('mp_validar_pago_inscripcion')
            || !function_exists('mp_vincular_pago_inscripcion')
        ) {
            throw new RuntimeException(
                'La integración Point de Inscripciones no está disponible.'
            );
        }

        $mpPagoData = mp_validar_pago_inscripcion(
            $db,
            $_POST,
            $precio,
            $metodoPagoEntrada,
            'inscripcion'
        );

        $metodoPagoDb = 'tarjeta';
        $referencia = trim((string) (
            $mpPagoData['payment_reference_id']
            ?? $mpPagoData['external_reference']
            ?? $mpPagoData['order_id']
            ?? ''
        ));
    }

    $db->begin_transaction();
    $transaccionActiva = true;
    $cliente = null;
    $clienteReutilizado = false;

    if ($clienteIdSolicitado > 0) {
        $stmt = $db->prepare(
            "SELECT *
             FROM clientes
             WHERE id = ?
               AND estado = 'activo'
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->bind_param('i', $clienteIdSolicitado);
        $stmt->execute();
        $cliente = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$cliente) {
            throw new DomainException(
                'La persona seleccionada ya no está disponible.'
            );
        }

        $clienteReutilizado = true;
    } elseif ($telefono !== '' || $email !== '') {
        if ($telefono !== '' && $email !== '') {
            $stmt = $db->prepare(
                "SELECT *
                 FROM clientes
                 WHERE estado = 'activo'
                   AND (telefono = ? OR email = ?)
                 ORDER BY
                    CASE WHEN telefono = ? THEN 0 ELSE 1 END,
                    id ASC
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->bind_param('sss', $telefono, $email, $telefono);
        } elseif ($telefono !== '') {
            $stmt = $db->prepare(
                "SELECT *
                 FROM clientes
                 WHERE estado = 'activo'
                   AND telefono = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->bind_param('s', $telefono);
        } else {
            $stmt = $db->prepare(
                "SELECT *
                 FROM clientes
                 WHERE estado = 'activo'
                   AND email = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->bind_param('s', $email);
        }

        $stmt->execute();
        $cliente = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($cliente) {
            $clienteReutilizado = true;
        }
    }

    /*
     * Evita crear un segundo socio cuando el único cambio es el uso de
     * mayúsculas/minúsculas. La coincidencia se comprueba nuevamente en el
     * servidor, aunque la persona no haya sido elegida desde el buscador.
     *
     * Solo se reutiliza automáticamente cuando existe una única coincidencia
     * activa. Si hay dos personas distintas con el mismo nombre completo, se
     * obliga a seleccionarlas desde los resultados para no mezclar historiales.
     */
    if (!$cliente && $nombre !== '' && $apellido !== '') {
        $nombreComparacion = preg_replace('/\s+/u', ' ', trim($nombre));
        $apellidoComparacion = preg_replace('/\s+/u', ' ', trim($apellido));

        $stmt = $db->prepare(
            "SELECT *
             FROM clientes
             WHERE estado = 'activo'
               AND LOWER(TRIM(nombre)) = LOWER(TRIM(?))
               AND LOWER(TRIM(apellido)) = LOWER(TRIM(?))
             ORDER BY id ASC
             LIMIT 2
             FOR UPDATE"
        );

        if (!$stmt) {
            throw new RuntimeException(
                'No fue posible comprobar si la persona ya está registrada.'
            );
        }

        $stmt->bind_param(
            'ss',
            $nombreComparacion,
            $apellidoComparacion
        );
        $stmt->execute();
        $resultadoCoincidencias = $stmt->get_result();
        $coincidenciasNombre = [];

        while ($filaCoincidencia = $resultadoCoincidencias->fetch_assoc()) {
            $coincidenciasNombre[] = $filaCoincidencia;
        }

        $stmt->close();

        if (count($coincidenciasNombre) === 1) {
            $cliente = $coincidenciasNombre[0];
            $clienteReutilizado = true;
        } elseif (count($coincidenciasNombre) > 1) {
            throw new DomainException(
                'Hay más de una persona registrada con ese nombre y apellidos. '
                . 'Búscala y selecciona el registro correcto para evitar mezclar datos.'
            );
        }
    }

    if (!$cliente) {
        if ($nombre === '' || $apellido === '') {
            throw new DomainException(
                'Captura el nombre y los apellidos de la persona.'
            );
        }

        $stmt = $db->prepare(
            "INSERT INTO clientes (
                sucursal_registro_id,
                nombre,
                apellido,
                telefono,
                email,
                contacto_emergencia_nombre,
                contacto_emergencia_telefono,
                codigo_qr,
                estado
             ) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 'activo')"
        );
        $stmt->bind_param(
            'issssss',
            $sucursalId,
            $nombre,
            $apellido,
            $telefono,
            $email,
            $emergenciaNombre,
            $emergenciaTelefono
        );
        $stmt->execute();
        $clienteId = (int) $db->insert_id;
        $stmt->close();

        $cliente = [
            'id' => $clienteId,
            'nombre' => $nombre,
            'apellido' => $apellido,
            'telefono' => $telefono,
            'email' => $email,
            'contacto_emergencia_nombre' => $emergenciaNombre,
            'contacto_emergencia_telefono' => $emergenciaTelefono,
            'codigo_qr' => '',
        ];
    } else {
        $clienteId = (int) $cliente['id'];

        /*
         * Solo se completan datos vacíos. El registro rápido nunca reemplaza
         * información que ya había sido confirmada en el expediente del socio.
         */
        $telefonoNuevo = trim((string) ($cliente['telefono'] ?? '')) !== ''
            ? (string) $cliente['telefono']
            : $telefono;
        $emailNuevo = trim((string) ($cliente['email'] ?? '')) !== ''
            ? (string) $cliente['email']
            : $email;
        $emergenciaNombreNueva = trim((string) (
            $cliente['contacto_emergencia_nombre'] ?? ''
        )) !== ''
            ? (string) $cliente['contacto_emergencia_nombre']
            : $emergenciaNombre;
        $emergenciaTelefonoNuevo = trim((string) (
            $cliente['contacto_emergencia_telefono'] ?? ''
        )) !== ''
            ? (string) $cliente['contacto_emergencia_telefono']
            : $emergenciaTelefono;

        $stmt = $db->prepare(
            "UPDATE clientes
             SET telefono = ?,
                 email = ?,
                 contacto_emergencia_nombre = ?,
                 contacto_emergencia_telefono = ?
             WHERE id = ?"
        );
        $stmt->bind_param(
            'ssssi',
            $telefonoNuevo,
            $emailNuevo,
            $emergenciaNombreNueva,
            $emergenciaTelefonoNuevo,
            $clienteId
        );
        $stmt->execute();
        $stmt->close();

        $cliente['telefono'] = $telefonoNuevo;
        $cliente['email'] = $emailNuevo;
        $cliente['contacto_emergencia_nombre'] = $emergenciaNombreNueva;
        $cliente['contacto_emergencia_telefono'] = $emergenciaTelefonoNuevo;
    }

    $codigoQr = trim((string) ($cliente['codigo_qr'] ?? ''));
    if ($codigoQr === '') {
        $codigoQr = (string) (generarCodigoQRUnico($db) ?? '');

        if ($codigoQr === '') {
            throw new RuntimeException(
                'No fue posible generar un identificador QR único.'
            );
        }

        $stmt = $db->prepare(
            "UPDATE clientes
             SET codigo_qr = ?
             WHERE id = ?"
        );
        $stmt->bind_param('si', $codigoQr, $clienteId);
        $stmt->execute();
        $stmt->close();
    }

    $raizProyecto = dirname(__DIR__, 2);
    $directorioQr = $raizProyecto . DIRECTORY_SEPARATOR . 'qrcodes';

    if (!is_dir($directorioQr) && !@mkdir($directorioQr, 0775, true)) {
        throw new RuntimeException(
            'No fue posible crear la carpeta de códigos QR.'
        );
    }

    $nombreArchivoQr = preg_replace(
        '/[^a-zA-Z0-9_-]/',
        '_',
        $codigoQr
    ) . '.png';
    $rutaQr = $directorioQr . DIRECTORY_SEPARATOR . $nombreArchivoQr;

    if (!is_file($rutaQr) || (int) @filesize($rutaQr) < 100) {
        if (!generarCodigoQR($codigoQr, $rutaQr)) {
            throw new RuntimeException(
                'No fue posible generar la imagen del código QR.'
            );
        }
        $archivoQrCreado = $rutaQr;
    }

    /* Marca vencimientos anteriores antes de comprobar acceso vigente. */
    $stmt = $db->prepare(
        "UPDATE inscripciones
         SET estado = 'vencida'
         WHERE cliente_id = ?
           AND estado = 'activa'
           AND fecha_fin < CURDATE()"
    );
    $stmt->bind_param('i', $clienteId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare(
        "SELECT i.id, p.nombre, i.fecha_fin
         FROM inscripciones i
         INNER JOIN planes p
           ON p.id = i.plan_id
         WHERE i.cliente_id = ?
           AND i.estado = 'activa'
           AND CURDATE() BETWEEN i.fecha_inicio AND i.fecha_fin
         ORDER BY i.fecha_fin DESC
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->bind_param('i', $clienteId);
    $stmt->execute();
    $membresiaActiva = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($membresiaActiva) {
        $fechaFinActiva = date(
            'd/m/Y',
            strtotime((string) $membresiaActiva['fecha_fin']) ?: time()
        );
        throw new DomainException(
            'No se registró la visita porque esta persona ya tiene acceso activo con el plan '
            . (string) $membresiaActiva['nombre']
            . ' hasta el ' . $fechaFinActiva . '. '
            . 'El plan de un día solamente puede registrarse cuando la membresía haya vencido.'
        );
    }

    $hoy = date('Y-m-d');
    $fechaFin = $hoy;

    $stmt = $db->prepare(
        "INSERT INTO inscripciones (
            sucursal_id,
            cliente_id,
            plan_id,
            fecha_inicio,
            fecha_fin,
            precio_pagado,
            estado
         ) VALUES (?, ?, ?, ?, ?, ?, 'activa')"
    );
    $stmt->bind_param(
        'iiissd',
        $sucursalId,
        $clienteId,
        $planId,
        $hoy,
        $fechaFin,
        $precio
    );
    $stmt->execute();
    $inscripcionId = (int) $db->insert_id;
    $stmt->close();

    $stmt = $db->prepare(
        "INSERT IGNORE INTO inscripciones_sucursales (
            inscripcion_id,
            sucursal_id
         )
         SELECT ?, s.id
         FROM sucursales s
         WHERE s.estado = 'activa'"
    );
    $stmt->bind_param('i', $inscripcionId);
    $stmt->execute();
    $stmt->close();

    $referenciaDb = $referencia !== '' ? $referencia : null;

    $stmt = $db->prepare(
        "INSERT INTO pagos (
            sucursal_id,
            inscripcion_id,
            cliente_id,
            monto,
            fecha_pago,
            metodo_pago,
            referencia,
            estado
         ) VALUES (?, ?, ?, ?, ?, ?, ?, 'completado')"
    );
    $stmt->bind_param(
        'iiidsss',
        $sucursalId,
        $inscripcionId,
        $clienteId,
        $precio,
        $hoy,
        $metodoPagoDb,
        $referenciaDb
    );
    $stmt->execute();
    $pagoId = (int) $db->insert_id;
    $stmt->close();

    if (is_array($mpPagoData)) {
        mp_vincular_pago_inscripcion(
            $db,
            (string) ($mpPagoData['order_id'] ?? ''),
            $inscripcionId,
            $pagoId,
            'inscripcion'
        );
    }

    $planNombre = (string) $plan['nombre'];
    $stmt = $db->prepare(
        "INSERT INTO historial_pagos (
            sucursal_id,
            inscripcion_id,
            cliente_id,
            monto,
            fecha_pago,
            metodo_pago,
            referencia,
            periodo_inicio,
            periodo_fin,
            plan_nombre,
            usuario_id
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        'iiidssssssi',
        $sucursalId,
        $inscripcionId,
        $clienteId,
        $precio,
        $hoy,
        $metodoPagoDb,
        $referenciaDb,
        $hoy,
        $fechaFin,
        $planNombre,
        $usuarioId
    );
    $stmt->execute();
    $historialPagoId = (int) $db->insert_id;
    $stmt->close();

    $db->commit();
    $transaccionActiva = false;

    clearstatcache(true, $rutaQr);
    $versionQr = is_file($rutaQr) ? (int) @filemtime($rutaQr) : time();

    $respuesta = [
        'success' => true,
        'message' => 'La visita quedó registrada.',
        'cliente_id' => $clienteId,
        'inscripcion_id' => $inscripcionId,
        'pago_id' => $pagoId,
        'historial_pago_id' => $historialPagoId,
        'cliente_reutilizado' => $clienteReutilizado,
        'nombre' => trim(
            (string) $cliente['nombre'] . ' ' . (string) $cliente['apellido']
        ),
        'plan' => $planNombre,
        'total' => $precio,
        'total_formateado' => '$' . number_format($precio, 2),
        'metodo_pago' => $metodoPagoDb,
        'metodo_pago_detalle' => $tipoTarjeta !== '' ? $tipoTarjeta : $metodoPagoDb,
        'point_order_id' => is_array($mpPagoData)
            ? (string) ($mpPagoData['order_id'] ?? '')
            : '',
        'point_payment_id' => is_array($mpPagoData)
            ? (string) ($mpPagoData['payment_id'] ?? '')
            : '',
        'point_installments' => is_array($mpPagoData)
            ? max(1, (int) ($mpPagoData['installments'] ?? 1))
            : 1,
        'codigo_qr' => $codigoQr,
        'qr_url' => 'qrcodes/' . rawurlencode($nombreArchivoQr)
            . '?v=' . $versionQr,
    ];

    $operacionesRecientes[$requestId] = $respuesta;
    if (count($operacionesRecientes) > 20) {
        $operacionesRecientes = array_slice(
            $operacionesRecientes,
            -20,
            null,
            true
        );
    }
    $_SESSION['dashboard_visitas_operaciones'] = $operacionesRecientes;

    dashboard_visitas_responder($respuesta);
} catch (DomainException $e) {
    if ($transaccionActiva) {
        try {
            $db->rollback();
        } catch (Throwable $ignorado) {
        }
    }

    if ($archivoQrCreado !== '' && is_file($archivoQrCreado)) {
        @unlink($archivoQrCreado);
    }

    dashboard_visitas_responder([
        'success' => false,
        'message' => $e->getMessage(),
    ], 422);
} catch (Throwable $e) {
    if ($transaccionActiva) {
        try {
            $db->rollback();
        } catch (Throwable $ignorado) {
        }
    }

    if ($archivoQrCreado !== '' && is_file($archivoQrCreado)) {
        @unlink($archivoQrCreado);
    }

    error_log('[Dashboard visita rápida] ' . $e->getMessage());

    dashboard_visitas_responder([
        'success' => false,
        'message' => 'No fue posible registrar la visita. Revisa el log del servidor.',
    ], 500);
}
