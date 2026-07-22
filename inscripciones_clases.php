<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/clases_context.php';
require_once __DIR__ . '/includes/clases_registro.php';
require_once __DIR__ . '/includes/mercadopago_clases.php';
require_once __DIR__ . '/includes/clases_ticket.php';

$mpService = __DIR__ . '/includes/mercadopago_service.php';
$mpInscripciones = __DIR__ . '/includes/mercadopago_inscripciones.php';

if (is_file($mpService)) {
    require_once $mpService;
}
if (is_file($mpInscripciones)) {
    require_once $mpInscripciones;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

date_default_timezone_set(
    (string) ($_SESSION['sucursal_zona_horaria'] ?? 'America/Mexico_City')
);

$database = new Database();
$conn = $database->getConnection();

if (!$conn instanceof mysqli) {
    die('Error: No se pudo establecer la conexión a la base de datos.');
}

$conn->set_charset('utf8mb4');
$usuario_id = (int) ($_SESSION['user_id'] ?? 0);

try {
    $contexto = clases_contexto($conn, $usuario_id);
} catch (Throwable $errorContexto) {
    die(clases_h($errorContexto->getMessage()));
}

$vista_global = (bool) $contexto['vista_global'];
$sucursal_id = (int) $contexto['sucursal_id'];
$sucursal_nombre = (string) $contexto['sucursal_nombre'];
$sucursal_clave = (string) $contexto['sucursal_clave'];
$total_sedes = (int) $contexto['total_sedes'];
$swal_clases = clases_swal_consumir();

if (empty($_SESSION['csrf_clases_registro'])) {
    $_SESSION['csrf_clases_registro'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['csrf_clases_registro'];

function clase_registro_redirigir(
    array $contexto,
    int $claseId = 0,
    string $fechaClase = '',
    int $horarioId = 0,
    string $grupoBuscar = '',
    int $grupoPage = 1
): void {
    $extra = [];

    if ($claseId > 0) {
        $extra['clase'] = $claseId;
    }
    if ($fechaClase !== '') {
        $extra['fecha'] = $fechaClase;
    }
    if ($horarioId > 0) {
        $extra['horario'] = $horarioId;
    }
    if ($grupoBuscar !== '') {
        $extra['grupo_buscar'] = $grupoBuscar;
    }
    if ($grupoPage > 1) {
        $extra['grupo_page'] = $grupoPage;
    }

    $url = clases_url(
        'inscripciones_clases.php',
        array_merge(
            ['vista' => $contexto['vista_global'] ? 'global' : 'sucursal'],
            $extra
        )
    );

    header('Location: ' . $url);
    exit;
}

function clase_registro_proxima_fecha_dia(int $diaSemana): string
{
    $diaSemana = max(1, min(7, $diaSemana));
    $fecha = new DateTimeImmutable('today');
    $diaActual = (int) $fecha->format('N');
    $diferencia = $diaSemana - $diaActual;

    if ($diferencia < 0) {
        $diferencia += 7;
    }

    return $fecha->modify('+' . $diferencia . ' days')->format('Y-m-d');
}

function clase_registro_fecha_corta(string $fecha): string
{
    $timestamp = strtotime($fecha);

    if ($timestamp === false) {
        return $fecha;
    }

    return date('d/m/Y', $timestamp);
}

function clase_registro_validar_csrf(): void
{
    $recibido = (string) ($_POST['csrf_token'] ?? '');
    $esperado = (string) ($_SESSION['csrf_clases_registro'] ?? '');

    if ($esperado === '' || !hash_equals($esperado, $recibido)) {
        throw new RuntimeException(
            'La sesión del formulario venció. Actualiza la página e intenta nuevamente.'
        );
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = trim((string) ($_POST['action'] ?? ''));

    if ($accion === 'registrar_participante') {
        $transaccion = false;
        $clase_id_post = (int) ($_POST['clase_id'] ?? 0);
        $fecha_clase_post = trim((string) ($_POST['fecha_clase'] ?? ''));
        $horario_id = (int) ($_POST['horario_id'] ?? 0);
        $datosTicket = null;
        $inscripcionClaseId = 0;

        try {
            clase_registro_validar_csrf();
            $sucursal_operativa = clases_exigir_sucursal($contexto);

            $horario_id = (int) ($_POST['horario_id'] ?? 0);
            $tipo = trim((string) ($_POST['tipo_participante'] ?? 'socio'));
            $cliente_id = (int) ($_POST['cliente_id'] ?? 0);
            $visitante_id = (int) ($_POST['visitante_id'] ?? 0);
            $nombre_externo = trim((string) ($_POST['nombre_externo'] ?? ''));
            $apellido_externo = trim((string) ($_POST['apellido_externo'] ?? ''));
            $telefono_externo = trim((string) ($_POST['telefono_externo'] ?? ''));
            $email_externo = trim((string) ($_POST['email_externo'] ?? ''));
            $metodo_solicitado = trim((string) ($_POST['metodo_pago'] ?? ''));
            $referencia = trim((string) ($_POST['referencia'] ?? ''));
            $monto_recibido = round((float) ($_POST['monto_recibido'] ?? 0), 2);

            if ($clase_id_post <= 0) {
                throw new RuntimeException('Selecciona una clase válida.');
            }

            if (!clase_registro_fecha_valida($fecha_clase_post)) {
                throw new RuntimeException('Selecciona una fecha válida para la clase.');
            }

            $hoy = new DateTimeImmutable(date('Y-m-d'));
            $fechaObj = new DateTimeImmutable($fecha_clase_post);

            if ($fechaObj < $hoy) {
                throw new RuntimeException('No puedes registrar una clase en una fecha pasada.');
            }

            if ($fechaObj > $hoy->modify('+1 year')) {
                throw new RuntimeException('La fecha seleccionada está demasiado lejos.');
            }

            if (!in_array($tipo, ['socio', 'externo'], true)) {
                throw new RuntimeException('El tipo de participante no es válido.');
            }

            if ($tipo === 'socio' && $cliente_id <= 0) {
                throw new RuntimeException('Selecciona el socio que asistirá.');
            }

            if ($tipo === 'externo') {
                $cliente_id = 0;

                /*
                 * Si se eligió un visitante previamente registrado, recuperamos
                 * sus datos como respaldo. Los campos siguen siendo editables
                 * para corregir teléfono, nombre o correo cuando sea necesario.
                 */
                if ($visitante_id > 0) {
                    $visitante_previo = clase_registro_obtener_visitante(
                        $conn,
                        $visitante_id,
                        false
                    );

                    if ($nombre_externo === '') {
                        $nombre_externo = (string) $visitante_previo['nombre'];
                    }
                    if ($apellido_externo === '') {
                        $apellido_externo = (string) $visitante_previo['apellido'];
                    }
                    if ($telefono_externo === '') {
                        $telefono_externo = (string) $visitante_previo['telefono'];
                    }
                    if ($email_externo === '') {
                        $email_externo = (string) ($visitante_previo['email'] ?? '');
                    }
                }

                if ($nombre_externo === '' || $apellido_externo === '') {
                    throw new RuntimeException(
                        'Escribe el nombre y los apellidos del visitante.'
                    );
                }

                if ($telefono_externo === '') {
                    throw new RuntimeException(
                        'El número celular del visitante es obligatorio.'
                    );
                }

                if (
                    $email_externo !== ''
                    && !filter_var($email_externo, FILTER_VALIDATE_EMAIL)
                ) {
                    throw new RuntimeException('El correo del visitante no es válido.');
                }
            } else {
                $visitante_id = 0;
            }

            $cobroPrevio = clase_registro_calcular_cobro(
                $conn,
                $clase_id_post,
                $sucursal_operativa,
                $tipo === 'socio' ? $cliente_id : null,
                $fecha_clase_post
            );
            $montoEsperado = round((float) $cobroPrevio['monto_cobrar'], 2);
            $mpData = null;

            if ($montoEsperado > 0) {
                if (!in_array(
                    $metodo_solicitado,
                    ['efectivo', 'transferencia', 'tarjeta_debito', 'tarjeta_credito'],
                    true
                )) {
                    throw new RuntimeException('Selecciona un método de pago válido.');
                }

                if ($metodo_solicitado === 'transferencia' && $referencia === '') {
                    throw new RuntimeException(
                        'Escribe la referencia de la transferencia.'
                    );
                }

                if ($metodo_solicitado === 'efectivo') {
                    if ($monto_recibido <= 0) {
                        $monto_recibido = $montoEsperado;
                    }
                    if ($monto_recibido < $montoEsperado) {
                        throw new RuntimeException(
                            'El efectivo recibido es menor al precio de la clase.'
                        );
                    }
                }

                if (mp_clase_es_tarjeta($metodo_solicitado)) {
                    $mpData = mp_clase_validar_pago(
                        $conn,
                        $_POST,
                        $montoEsperado,
                        $sucursal_operativa
                    );
                    $referencia = (string) (
                        $mpData['payment_reference_id']
                        ?? $mpData['external_reference']
                        ?? $mpData['order_id']
                    );
                }
            } else {
                $metodo_solicitado = '';
                $referencia = '';
                $monto_recibido = 0.00;
            }

            $conn->begin_transaction();
            $transaccion = true;

            $clase = clase_registro_obtener_clase(
                $conn,
                $clase_id_post,
                $sucursal_operativa,
                true
            );
            $horario = clase_registro_obtener_horario(
                $conn,
                $clase_id_post,
                $horario_id,
                $fecha_clase_post
            );

            if ($tipo === 'externo') {
                $visitante = clase_registro_guardar_visitante(
                    $conn,
                    $visitante_id,
                    $sucursal_operativa,
                    $nombre_externo,
                    $apellido_externo,
                    $telefono_externo,
                    $email_externo,
                    $usuario_id
                );

                $visitante_id = (int) $visitante['id'];
                $nombre_externo = (string) $visitante['nombre'];
                $apellido_externo = (string) $visitante['apellido'];
                $telefono_externo = (string) $visitante['telefono'];
                $email_externo = (string) ($visitante['email'] ?? '');
            }

            $cobro = clase_registro_calcular_cobro(
                $conn,
                $clase_id_post,
                $sucursal_operativa,
                $tipo === 'socio' ? $cliente_id : null,
                $fecha_clase_post
            );
            $monto = round((float) $cobro['monto_cobrar'], 2);

            if (abs($monto - $montoEsperado) > 0.01) {
                throw new RuntimeException(
                    'La condición de membresía cambió mientras se registraba el acceso. Intenta nuevamente.'
                );
            }

            if ($tipo === 'socio') {
                $stmtDuplicado = $conn->prepare(
                    "SELECT id
                     FROM inscripciones_clases
                     WHERE clase_id = ?
                       AND fecha_clase = ?
                       AND cliente_id = ?
                       AND estado = 'activa'
                       AND (
                            (? IS NULL AND horario_id IS NULL)
                            OR horario_id = ?
                       )
                     LIMIT 1
                     FOR UPDATE"
                );
                $horarioNullable = $horario_id > 0 ? $horario_id : null;
                $stmtDuplicado->bind_param(
                    'isiii',
                    $clase_id_post,
                    $fecha_clase_post,
                    $cliente_id,
                    $horarioNullable,
                    $horarioNullable
                );
            } else {
                $stmtDuplicado = $conn->prepare(
                    "SELECT id
                     FROM inscripciones_clases
                     WHERE clase_id = ?
                       AND fecha_clase = ?
                       AND tipo_participante = 'externo'
                       AND visitante_id = ?
                       AND estado = 'activa'
                       AND (
                            (? IS NULL AND horario_id IS NULL)
                            OR horario_id = ?
                       )
                     LIMIT 1
                     FOR UPDATE"
                );
                $horarioNullable = $horario_id > 0 ? $horario_id : null;
                $stmtDuplicado->bind_param(
                    'isiii',
                    $clase_id_post,
                    $fecha_clase_post,
                    $visitante_id,
                    $horarioNullable,
                    $horarioNullable
                );
            }

            $stmtDuplicado->execute();
            $duplicado = $stmtDuplicado->get_result()->fetch_assoc();
            $stmtDuplicado->close();

            if ($duplicado) {
                throw new RuntimeException(
                    'La persona ya está registrada en esta sesión de clase.'
                );
            }

            $ocupados = clase_registro_contar_cupo(
                $conn,
                $clase_id_post,
                $fecha_clase_post,
                $horario_id > 0 ? $horario_id : null,
                true
            );

            if ($ocupados >= (int) $clase['cupo_maximo']) {
                throw new RuntimeException(
                    'La sesión seleccionada ya alcanzó su cupo máximo.'
                );
            }

            $folio = clase_registro_folio($sucursal_operativa, $clase_id_post);
            $precioClase = round((float) $cobro['precio_clase'], 2);
            $cubierto = !empty($cobro['cubierto_membresia']) ? 1 : 0;
            $membresiaId = is_array($cobro['membresia'])
                ? (int) $cobro['membresia']['id']
                : null;
            $metodoLocal = $monto <= 0
                ? null
                : (mp_clase_es_tarjeta($metodo_solicitado)
                    ? 'tarjeta'
                    : $metodo_solicitado);
            $estadoPago = $monto <= 0 ? 'no_cobro' : 'pagado';
            $clienteNullable = $tipo === 'socio' ? $cliente_id : null;
            $visitanteNullable = $tipo === 'externo' ? $visitante_id : null;
            $horarioNullable = $horario_id > 0 ? $horario_id : null;
            $nombreExternoDb = $tipo === 'externo' ? $nombre_externo : null;
            $apellidoExternoDb = $tipo === 'externo' ? $apellido_externo : null;
            $emailExternoDb = $tipo === 'externo' ? $email_externo : null;
            $telefonoExternoDb = $tipo === 'externo' ? $telefono_externo : null;
            $fechaInscripcion = date('Y-m-d');

            $stmtInsert = $conn->prepare(
                "INSERT INTO inscripciones_clases (
                    sucursal_id,
                    clase_id,
                    horario_id,
                    cliente_id,
                    visitante_id,
                    tipo_participante,
                    nombre_externo,
                    apellido_externo,
                    email_externo,
                    telefono_externo,
                    fecha_inscripcion,
                    fecha_clase,
                    folio_acceso,
                    precio_clase,
                    monto_cobrado,
                    cubierto_membresia,
                    inscripcion_membresia_id,
                    metodo_pago,
                    estado_pago,
                    usuario_registro_id,
                    estado,
                    asistencia
                 ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'activa', 0
                 )"
            );

            $stmtInsert->bind_param(
                'iiiiissssssssddiissi',
                $sucursal_operativa,
                $clase_id_post,
                $horarioNullable,
                $clienteNullable,
                $visitanteNullable,
                $tipo,
                $nombreExternoDb,
                $apellidoExternoDb,
                $emailExternoDb,
                $telefonoExternoDb,
                $fechaInscripcion,
                $fecha_clase_post,
                $folio,
                $precioClase,
                $monto,
                $cubierto,
                $membresiaId,
                $metodoLocal,
                $estadoPago,
                $usuario_id
            );
            $stmtInsert->execute();
            $inscripcionClaseId = (int) $conn->insert_id;
            $stmtInsert->close();

            $pagoClaseId = 0;
            $cambio = 0.00;

            if ($monto > 0) {
                $nombrePagador = $tipo === 'socio'
                    ? trim(
                        (string) $cobro['cliente']['nombre'] . ' ' .
                        (string) $cobro['cliente']['apellido']
                    )
                    : trim($nombre_externo . ' ' . $apellido_externo);
                $recibidoDb = $metodoLocal === 'efectivo'
                    ? max($monto, $monto_recibido)
                    : $monto;
                $cambio = $metodoLocal === 'efectivo'
                    ? round($recibidoDb - $monto, 2)
                    : 0.00;

                $stmtPago = $conn->prepare(
                    "INSERT INTO pagos_clases (
                        sucursal_id,
                        inscripcion_clase_id,
                        cliente_id,
                        nombre_pagador,
                        monto,
                        monto_recibido,
                        cambio,
                        metodo_pago,
                        referencia,
                        estado,
                        usuario_id,
                        fecha_pago
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'completado', ?, NOW())"
                );
                $stmtPago->bind_param(
                    'iiisdddssi',
                    $sucursal_operativa,
                    $inscripcionClaseId,
                    $clienteNullable,
                    $nombrePagador,
                    $monto,
                    $recibidoDb,
                    $cambio,
                    $metodoLocal,
                    $referencia,
                    $usuario_id
                );
                $stmtPago->execute();
                $pagoClaseId = (int) $conn->insert_id;
                $stmtPago->close();

                if (is_array($mpData)) {
                    mp_clase_vincular_pago(
                        $conn,
                        (string) $mpData['order_id'],
                        $inscripcionClaseId,
                        $pagoClaseId
                    );
                }
            }

            if ($tipo === 'externo' && $visitante_id > 0) {
                clase_registro_marcar_visita_visitante(
                    $conn,
                    $visitante_id,
                    $sucursal_operativa,
                    $fecha_clase_post,
                    $usuario_id
                );
            }

            $conn->commit();
            $transaccion = false;

            $participanteNombre = $tipo === 'socio'
                ? trim(
                    (string) $cobro['cliente']['nombre'] . ' ' .
                    (string) $cobro['cliente']['apellido']
                )
                : trim($nombre_externo . ' ' . $apellido_externo);
            $emailTicket = $tipo === 'socio'
                ? trim((string) ($cobro['cliente']['email'] ?? ''))
                : $email_externo;
            $horarioTexto = is_array($horario)
                ? clase_registro_formatear_hora((string) $horario['hora_inicio'])
                    . ' - '
                    . clase_registro_formatear_hora((string) $horario['hora_fin'])
                : (string) $clase['horario'];
            $formaAcceso = $monto <= 0
                ? 'Incluido en membresía'
                : mp_clase_etiqueta_pago(
                    $metodo_solicitado,
                    (int) ($mpData['installments'] ?? 1)
                );

            $datosTicket = [
                'sucursal_nombre' => (string) $clase['sucursal_nombre'],
                'clase_nombre' => (string) $clase['nombre'],
                'participante_nombre' => $participanteNombre,
                'email' => $emailTicket,
                'fecha_clase_texto' => date('d/m/Y', strtotime($fecha_clase_post)),
                'horario_texto' => $horarioTexto,
                'instructor' => (string) $clase['instructor'],
                'folio' => $folio,
                'folio_visible' => 'Acceso #' . str_pad(
                    (string) $inscripcionClaseId,
                    6,
                    '0',
                    STR_PAD_LEFT
                ),
                'monto' => $monto,
                'cambio' => $cambio,
                'forma_acceso' => $formaAcceso,
                'metodo_pago_local' => $monto <= 0 ? 'sin_cobro' : (string) $metodoLocal,
            ];

            $ticket = clases_ticket_emitir(
                $conn,
                $inscripcionClaseId,
                $datosTicket
            );

            $mensaje = $monto <= 0
                ? 'El lugar quedó reservado sin cobro porque la membresía está vigente.'
                : 'El pago y el acceso a la clase quedaron registrados correctamente.';

            if ($emailTicket !== '') {
                $mensaje .= !empty($ticket['correo_enviado'])
                    ? ' Se envió el comprobante PDF por correo.'
                    : ' El registro se completó, pero no fue posible enviar el correo.';
            }

            clases_swal_guardar(
                'success',
                'Acceso registrado',
                $mensaje
            );
        } catch (Throwable $error) {
            if ($transaccion) {
                try {
                    $conn->rollback();
                } catch (Throwable $rollbackError) {
                }
            }

            clases_swal_guardar(
                'error',
                'No fue posible registrar el acceso',
                $error->getMessage()
            );
        }

        clase_registro_redirigir(
            $contexto,
            $clase_id_post,
            $fecha_clase_post,
            $horario_id
        );
    }

    if ($accion === 'registrar_asistencia') {
        try {
            clase_registro_validar_csrf();
            $sucursal_operativa = clases_exigir_sucursal($contexto);
            $inscripcionId = (int) ($_POST['inscripcion_id'] ?? 0);
            $claseId = (int) ($_POST['clase_id'] ?? 0);

            $fechaClase = trim((string) ($_POST['fecha_clase'] ?? ''));

            if (!clase_registro_fecha_valida($fechaClase)) {
                throw new RuntimeException('La fecha de la sesión no es válida.');
            }

            if ($fechaClase > date('Y-m-d')) {
                throw new RuntimeException('No puedes registrar asistencia antes de que ocurra la sesión.');
            }

            $stmt = $conn->prepare(
                "UPDATE inscripciones_clases ic
                 INNER JOIN clases c ON c.id = ic.clase_id
                 SET ic.asistencia = 1,
                     ic.fecha_ultima_asistencia = ic.fecha_clase
                 WHERE ic.id = ?
                   AND ic.clase_id = ?
                   AND ic.fecha_clase = ?
                   AND ic.estado = 'activa'
                   AND c.sucursal_id = ?
                   AND (
                        COALESCE(ic.asistencia, 0) = 0
                        OR ic.fecha_ultima_asistencia IS NULL
                        OR ic.fecha_ultima_asistencia <> ic.fecha_clase
                   )"
            );
            $stmt->bind_param(
                'iisi',
                $inscripcionId,
                $claseId,
                $fechaClase,
                $sucursal_operativa
            );
            $stmt->execute();

            if ($stmt->affected_rows !== 1) {
                throw new RuntimeException(
                    'La asistencia ya estaba registrada o la inscripción dejó de estar activa.'
                );
            }
            $stmt->close();

            clases_swal_guardar(
                'success',
                'Asistencia registrada',
                'El participante quedó marcado como presente en esta sesión.'
            );
        } catch (Throwable $error) {
            clases_swal_guardar('error', 'No fue posible registrar la asistencia', $error->getMessage());
        }

        clase_registro_redirigir(
            $contexto,
            (int) ($_POST['clase_id'] ?? 0),
            trim((string) ($_POST['fecha_clase'] ?? '')),
            (int) ($_POST['horario_id'] ?? 0),
            trim((string) ($_POST['grupo_buscar'] ?? '')),
            max(1, (int) ($_POST['grupo_page'] ?? 1))
        );
    }

    if ($accion === 'cancelar_inscripcion') {
        $transaccion = false;

        try {
            clase_registro_validar_csrf();
            $sucursal_operativa = clases_exigir_sucursal($contexto);
            $inscripcionId = (int) ($_POST['inscripcion_id'] ?? 0);
            $claseId = (int) ($_POST['clase_id'] ?? 0);

            $conn->begin_transaction();
            $transaccion = true;

            $stmt = $conn->prepare(
                "SELECT
                    ic.id,
                    ic.estado,
                    ic.metodo_pago,
                    ic.estado_pago,
                    ic.visitante_id
                 FROM inscripciones_clases ic
                 INNER JOIN clases c ON c.id = ic.clase_id
                 WHERE ic.id = ?
                   AND ic.clase_id = ?
                   AND c.sucursal_id = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->bind_param('iii', $inscripcionId, $claseId, $sucursal_operativa);
            $stmt->execute();
            $registro = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!is_array($registro) || ($registro['estado'] ?? '') !== 'activa') {
                throw new RuntimeException('La inscripción ya no está activa.');
            }

            if (($registro['metodo_pago'] ?? '') === 'tarjeta') {
                throw new RuntimeException(
                    'El acceso fue pagado con tarjeta. Realiza primero el reembolso desde Mercado Pago antes de cancelarlo.'
                );
            }

            $stmt = $conn->prepare(
                "UPDATE inscripciones_clases
                 SET estado = 'cancelada',
                     estado_pago = CASE
                        WHEN estado_pago = 'pagado' THEN 'cancelado'
                        ELSE estado_pago
                     END
                 WHERE id = ?"
            );
            $stmt->bind_param('i', $inscripcionId);
            $stmt->execute();
            $stmt->close();

            $stmtPago = $conn->prepare(
                "UPDATE pagos_clases
                 SET estado = 'cancelado'
                 WHERE inscripcion_clase_id = ?
                   AND estado = 'completado'"
            );
            $stmtPago->bind_param('i', $inscripcionId);
            $stmtPago->execute();
            $stmtPago->close();

            $visitanteCanceladoId = (int) ($registro['visitante_id'] ?? 0);

            if ($visitanteCanceladoId > 0) {
                clase_registro_recalcular_visitante(
                    $conn,
                    $visitanteCanceladoId,
                    $sucursal_operativa,
                    $usuario_id
                );
            }

            $conn->commit();
            $transaccion = false;

            clases_swal_guardar(
                'success',
                'Inscripción cancelada',
                'El lugar volvió a quedar disponible.'
            );
        } catch (Throwable $error) {
            if ($transaccion) {
                try {
                    $conn->rollback();
                } catch (Throwable $rollbackError) {
                }
            }
            clases_swal_guardar('error', 'No fue posible cancelar', $error->getMessage());
        }

        clase_registro_redirigir(
            $contexto,
            (int) ($_POST['clase_id'] ?? 0),
            trim((string) ($_POST['fecha_clase'] ?? '')),
            (int) ($_POST['horario_id'] ?? 0),
            trim((string) ($_POST['grupo_buscar'] ?? '')),
            max(1, (int) ($_POST['grupo_page'] ?? 1))
        );
    }
}

/* =========================================================
   CONSULTAS DE LA INTERFAZ
========================================================= */

$whereClases = [];
$paramsClases = [];
$typesClases = '';

if ($vista_global) {
    $ids = array_map('intval', (array) $contexto['sucursales_ids']);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $whereClases[] = "c.sucursal_id IN ($marks)";

    foreach ($ids as $idSede) {
        $paramsClases[] = $idSede;
        $typesClases .= 'i';
    }
} else {
    $whereClases[] = 'c.sucursal_id = ?';
    $paramsClases[] = $sucursal_id;
    $typesClases .= 'i';
}

$whereClases[] = "c.estado = 'activa'";
$sqlClases = "SELECT
                c.*,
                s.nombre AS sucursal_nombre,
                s.clave AS sucursal_clave,
                s.es_matriz AS sucursal_es_matriz
              FROM clases c
              INNER JOIN sucursales s ON s.id = c.sucursal_id
              WHERE " . implode(' AND ', $whereClases) . "
              ORDER BY s.es_matriz DESC, s.nombre ASC, c.nombre ASC";

$stmtClases = $conn->prepare($sqlClases);
clases_bind($stmtClases, $typesClases, $paramsClases);
$stmtClases->execute();
$clases = $stmtClases->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtClases->close();

$horariosPorClase = [];
if ($clases !== []) {
    $idsClases = array_map(static fn(array $c): int => (int) $c['id'], $clases);
    $marks = implode(',', array_fill(0, count($idsClases), '?'));
    $types = str_repeat('i', count($idsClases));
    $stmtHorarios = $conn->prepare(
        "SELECT id, clase_id, dia_semana, hora_inicio, hora_fin
         FROM clases_horarios
         WHERE clase_id IN ($marks)
           AND estado = 'activo'
         ORDER BY clase_id, dia_semana, hora_inicio"
    );
    clases_bind($stmtHorarios, $types, $idsClases);
    $stmtHorarios->execute();
    $filasHorarios = $stmtHorarios->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtHorarios->close();

    foreach ($filasHorarios as $horario) {
        $horariosPorClase[(int) $horario['clase_id']][] = $horario;
    }
}

$claseSeleccionadaId = max(0, (int) ($_GET['clase'] ?? 0));
$claseSeleccionada = null;

foreach ($clases as $claseItem) {
    if ((int) $claseItem['id'] === $claseSeleccionadaId) {
        $claseSeleccionada = $claseItem;
        break;
    }
}

/*
 * No se selecciona automáticamente la primera clase.
 * El catálogo y el interior de una clase son vistas distintas para evitar
 * que la información aparezca debajo y el usuario pierda el contexto.
 */
if ($claseSeleccionada === null) {
    $claseSeleccionadaId = 0;
}

$horariosSeleccionados = $claseSeleccionada
    ? ($horariosPorClase[$claseSeleccionadaId] ?? [])
    : [];

$horarioSeleccionadoId = max(0, (int) ($_GET['horario'] ?? 0));
$horarioSeleccionado = null;

foreach ($horariosSeleccionados as $horarioItem) {
    if ((int) $horarioItem['id'] === $horarioSeleccionadoId) {
        $horarioSeleccionado = $horarioItem;
        break;
    }
}

if ($horarioSeleccionado === null && $horariosSeleccionados !== []) {
    $horarioSeleccionado = $horariosSeleccionados[0];
    $horarioSeleccionadoId = (int) $horarioSeleccionado['id'];
}

$fechaRecibida = isset($_GET['fecha'])
    ? trim((string) $_GET['fecha'])
    : '';

if ($fechaRecibida !== '' && clase_registro_fecha_valida($fechaRecibida)) {
    $fechaSeleccionada = $fechaRecibida;
} elseif (is_array($horarioSeleccionado)) {
    $fechaSeleccionada = clase_registro_proxima_fecha_dia(
        (int) $horarioSeleccionado['dia_semana']
    );
} else {
    $fechaSeleccionada = date('Y-m-d');
}

$grupoBuscar = trim((string) ($_GET['grupo_buscar'] ?? ''));
$grupoPage = max(1, (int) ($_GET['grupo_page'] ?? 1));
$grupoLimit = 8;
$sesionEsFutura = $fechaSeleccionada > date('Y-m-d');

$clientes = [];
$terminalPointDisponible = false;

if (!$vista_global && $claseSeleccionada !== null) {
    $resultClientes = $conn->query(
        "SELECT id, nombre, apellido, telefono, email
         FROM clientes
         WHERE estado = 'activo'
         ORDER BY nombre, apellido"
    );
    $clientes = $resultClientes
        ? $resultClientes->fetch_all(MYSQLI_ASSOC)
        : [];

    $stmtTerminal = $conn->prepare(
        "SELECT id
         FROM mercadopago_terminales
         WHERE sucursal_id = ?
           AND activo = 1
         ORDER BY predeterminada DESC, id ASC
         LIMIT 1"
    );
    $stmtTerminal->bind_param('i', $sucursal_id);
    $stmtTerminal->execute();
    $terminalPointDisponible = (bool) $stmtTerminal->get_result()->fetch_assoc();
    $stmtTerminal->close();
}

$participantes = [];
$totalParticipantes = 0;
$totalPaginasGrupo = 1;
$activosFecha = 0;

if ($claseSeleccionada !== null) {
    $whereGrupo = [
        'ic.clase_id = ?',
        'ic.fecha_clase = ?',
    ];
    $paramsGrupo = [
        $claseSeleccionadaId,
        $fechaSeleccionada,
    ];
    $typesGrupo = 'is';

    if ($horarioSeleccionadoId > 0) {
        $whereGrupo[] = 'ic.horario_id = ?';
        $paramsGrupo[] = $horarioSeleccionadoId;
        $typesGrupo .= 'i';
    }

    if ($grupoBuscar !== '') {
        $busquedaGrupo = '%' . $grupoBuscar . '%';
        $whereGrupo[] = "(
            COALESCE(cl.nombre, ic.nombre_externo, '') LIKE ?
            OR COALESCE(cl.apellido, ic.apellido_externo, '') LIKE ?
            OR COALESCE(cl.telefono, ic.telefono_externo, '') LIKE ?
            OR COALESCE(cl.email, ic.email_externo, '') LIKE ?
            OR ic.folio_acceso LIKE ?
        )";

        for ($indiceBusqueda = 0; $indiceBusqueda < 5; $indiceBusqueda++) {
            $paramsGrupo[] = $busquedaGrupo;
            $typesGrupo .= 's';
        }
    }

    $whereGrupoSql = implode(' AND ', $whereGrupo);

    $stmtConteoGrupo = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM inscripciones_clases ic
         LEFT JOIN clientes cl ON cl.id = ic.cliente_id
         WHERE $whereGrupoSql"
    );
    $paramsConteoGrupo = $paramsGrupo;
    clases_bind($stmtConteoGrupo, $typesGrupo, $paramsConteoGrupo);
    $stmtConteoGrupo->execute();
    $totalParticipantes = (int) (
        $stmtConteoGrupo->get_result()->fetch_assoc()['total'] ?? 0
    );
    $stmtConteoGrupo->close();

    $totalPaginasGrupo = max(1, (int) ceil($totalParticipantes / $grupoLimit));
    $grupoPage = min($grupoPage, $totalPaginasGrupo);
    $grupoOffset = ($grupoPage - 1) * $grupoLimit;

    $sqlParticipantes = "SELECT
            ic.*,
            COALESCE(
                NULLIF(TRIM(CONCAT(cl.nombre, ' ', cl.apellido)), ''),
                NULLIF(TRIM(CONCAT(ic.nombre_externo, ' ', ic.apellido_externo)), ''),
                'Participante'
            ) AS participante_nombre,
            COALESCE(NULLIF(cl.telefono, ''), ic.telefono_externo, '') AS participante_telefono,
            COALESCE(NULLIF(cl.email, ''), ic.email_externo, '') AS participante_email,
            ch.hora_inicio,
            ch.hora_fin,
            pc.id AS pago_clase_id,
            pc.estado AS pago_estado
         FROM inscripciones_clases ic
         LEFT JOIN clientes cl ON cl.id = ic.cliente_id
         LEFT JOIN clases_horarios ch ON ch.id = ic.horario_id
         LEFT JOIN pagos_clases pc
            ON pc.id = (
                SELECT MAX(pc2.id)
                FROM pagos_clases pc2
                WHERE pc2.inscripcion_clase_id = ic.id
            )
         WHERE $whereGrupoSql
         ORDER BY
            CASE ic.estado WHEN 'activa' THEN 0 ELSE 1 END,
            CASE
                WHEN COALESCE(ic.asistencia, 0) > 0
                 AND ic.fecha_ultima_asistencia = ic.fecha_clase
                    THEN 0
                ELSE 1
            END,
            ic.created_at DESC
         LIMIT ? OFFSET ?";

    $paramsParticipantes = $paramsGrupo;
    $paramsParticipantes[] = $grupoLimit;
    $paramsParticipantes[] = $grupoOffset;
    $typesParticipantes = $typesGrupo . 'ii';

    $stmtParticipantes = $conn->prepare($sqlParticipantes);
    clases_bind($stmtParticipantes, $typesParticipantes, $paramsParticipantes);
    $stmtParticipantes->execute();
    $participantes = $stmtParticipantes->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtParticipantes->close();

    $whereActivos = [
        'clase_id = ?',
        'fecha_clase = ?',
        "estado = 'activa'",
    ];
    $paramsActivos = [$claseSeleccionadaId, $fechaSeleccionada];
    $typesActivos = 'is';

    if ($horarioSeleccionadoId > 0) {
        $whereActivos[] = 'horario_id = ?';
        $paramsActivos[] = $horarioSeleccionadoId;
        $typesActivos .= 'i';
    }

    $stmtActivos = $conn->prepare(
        'SELECT COUNT(*) AS total FROM inscripciones_clases WHERE '
        . implode(' AND ', $whereActivos)
    );
    clases_bind($stmtActivos, $typesActivos, $paramsActivos);
    $stmtActivos->execute();
    $activosFecha = (int) (
        $stmtActivos->get_result()->fetch_assoc()['total'] ?? 0
    );
    $stmtActivos->close();
}

$horarioSeleccionadoTexto = '';
if (is_array($horarioSeleccionado)) {
    $horarioSeleccionadoTexto =
        clase_registro_nombre_dia((int) $horarioSeleccionado['dia_semana'])
        . ' · '
        . clase_registro_formatear_hora((string) $horarioSeleccionado['hora_inicio'])
        . ' - '
        . clase_registro_formatear_hora((string) $horarioSeleccionado['hora_fin']);
} elseif ($claseSeleccionada !== null) {
    $horarioSeleccionadoTexto = (string) $claseSeleccionada['horario'];
}

$diasJson = json_encode([
    1 => 'Lunes',
    2 => 'Martes',
    3 => 'Miércoles',
    4 => 'Jueves',
    5 => 'Viernes',
    6 => 'Sábado',
    7 => 'Domingo',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accesos a clases</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <?php $cssPath = __DIR__ . '/css/inscripciones_clases.css'; ?>
    <link rel="stylesheet" href="css/inscripciones_clases.css?v=<?php echo is_file($cssPath) ? (int) filemtime($cssPath) : time(); ?>">
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<main class="main-content class-access-page">
    <header class="page-header class-page-header">
        <div>
            <h1>
                <?php echo $claseSeleccionada !== null
                    ? clases_h((string) $claseSeleccionada['nombre'])
                    : 'Inscripciones a clases'; ?>
            </h1>
            <p>
                <?php if ($claseSeleccionada !== null): ?>
                    Administra el grupo, los accesos y las asistencias sin salir de esta clase.
                <?php else: ?>
                    <?php echo $vista_global
                        ? 'Consulta las clases disponibles de todas las sucursales.'
                        : 'Selecciona una clase para entrar a su grupo y registrar accesos en ' . clases_h($sucursal_nombre) . '.'; ?>
                <?php endif; ?>
            </p>
        </div>

        <div class="class-context-chip <?php echo $vista_global ? 'global' : ''; ?>">
            <span class="class-context-icon">
                <i class="fas <?php echo $vista_global ? 'fa-layer-group' : 'fa-building'; ?>"></i>
            </span>
            <span>
                <strong><?php echo clases_h($sucursal_nombre); ?></strong>
                <small><?php echo clases_h($vista_global ? $total_sedes . ' sedes' : ($sucursal_clave ?: 'Sucursal activa')); ?></small>
            </span>
        </div>
    </header>

    <?php if ($claseSeleccionada === null): ?>
    <section class="class-catalog-panel">
        <div class="class-section-heading">
            <div>
                <h2>Clases disponibles</h2>
                <p>Las nuevas clases aparecen aquí automáticamente.</p>
            </div>
            <div class="class-catalog-tools">
                <label class="class-search-box">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="search" id="classCardSearch" placeholder="Buscar clase o entrenador">
                </label>
                <span class="class-count">
                    <?php $totalClases = count($clases); ?>
                    <?php echo $totalClases . ($totalClases === 1 ? ' clase' : ' clases'); ?>
                </span>
            </div>
        </div>

        <?php if ($clases === []): ?>
            <div class="class-empty-state">
                <i class="fas fa-dumbbell"></i>
                <h3>No hay clases activas</h3>
                <p>Las clases que agregues desde el módulo de clases aparecerán en esta sección.</p>
            </div>
        <?php else: ?>
            <div class="class-card-grid" id="classCardGrid">
                <?php foreach ($clases as $clase): ?>
                    <?php
                    $idClase = (int) $clase['id'];
                    $seleccionada = $idClase === $claseSeleccionadaId;
                    $horariosClase = $horariosPorClase[$idClase] ?? [];
                    $urlClase = clases_url('inscripciones_clases.php', [
                        'vista' => $vista_global ? 'global' : 'sucursal',
                        'clase' => $idClase,
                    ]);
                    ?>
                    <a
                        class="class-access-card <?php echo $seleccionada ? 'selected' : ''; ?>"
                        href="<?php echo clases_h($urlClase); ?>"
                        data-class-search="<?php echo clases_h(strtolower(
                            (string) $clase['nombre'] . ' ' .
                            (string) $clase['instructor'] . ' ' .
                            (string) $clase['sucursal_nombre']
                        )); ?>"
                    >
                        <div class="class-card-topline">
                            <span class="class-card-icon"><i class="fas fa-dumbbell"></i></span>
                            <?php if ($vista_global): ?>
                                <span class="class-card-branch"><?php echo clases_h((string) $clase['sucursal_clave']); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="class-card-copy">
                            <h3><?php echo clases_h((string) $clase['nombre']); ?></h3>
                            <p><?php echo clases_h((string) ($clase['descripcion'] ?: 'Clase disponible')); ?></p>
                        </div>

                        <div class="class-card-meta">
                            <span><i class="fas fa-user-tie"></i><?php echo clases_h((string) $clase['instructor']); ?></span>
                            <span><i class="far fa-clock"></i><?php echo (int) $clase['duracion_minutos']; ?> min</span>
                        </div>

                        <div class="class-card-schedules">
                            <?php if ($horariosClase !== []): ?>
                                <?php foreach (array_slice($horariosClase, 0, 2) as $horario): ?>
                                    <span>
                                        <?php echo clases_h(clase_registro_nombre_dia((int) $horario['dia_semana'])); ?>
                                        <?php echo clases_h(clase_registro_formatear_hora((string) $horario['hora_inicio'])); ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if (count($horariosClase) > 2): ?>
                                    <span>+<?php echo count($horariosClase) - 2; ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span><?php echo clases_h((string) $clase['horario']); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="class-card-footer">
                            <span>
                                <small>Acceso individual</small>
                                <strong>$<?php echo number_format((float) $clase['precio_clase'], 2); ?></strong>
                            </span>
                            <span class="class-open-label">
                                Entrar a clase
                                <i class="fas fa-arrow-right"></i>
                            </span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php else: ?>
        <div class="class-view-toolbar">
            <a class="class-back-link" href="<?php echo clases_h(clases_url('inscripciones_clases.php', ['vista' => $vista_global ? 'global' : 'sucursal'])); ?>">
                <i class="fas fa-arrow-left"></i>
                Volver a clases
            </a>

            <span class="class-view-location">
                <i class="fas fa-location-dot"></i>
                <?php echo clases_h((string) $claseSeleccionada['sucursal_nombre']); ?>
            </span>
        </div>

        <section class="class-workspace">
            <div class="class-workspace-main">
                <div class="class-detail-card">
                    <div class="class-detail-head">
                        <div>
                            <span class="class-detail-label">Clase seleccionada</span>
                            <h2><?php echo clases_h((string) $claseSeleccionada['nombre']); ?></h2>
                            <p><?php echo clases_h((string) ($claseSeleccionada['descripcion'] ?: 'Sin descripción')); ?></p>
                        </div>

                        <?php if (!$vista_global): ?>
                            <button
                                class="btn-register-class"
                                type="button"
                                data-bs-toggle="modal"
                                data-bs-target="#modalRegistrarClase"
                            >
                                <i class="fas fa-user-plus"></i>
                                Registrar persona
                            </button>
                        <?php endif; ?>
                    </div>

                    <div class="class-detail-stats">
                        <div>
                            <span class="stat-icon"><i class="fas fa-user-tie"></i></span>
                            <span><small>Entrenador</small><strong><?php echo clases_h((string) $claseSeleccionada['instructor']); ?></strong></span>
                        </div>
                        <div>
                            <span class="stat-icon"><i class="fas fa-users"></i></span>
                            <span><small>Cupo por sesión</small><strong><?php echo $activosFecha; ?>/<?php echo (int) $claseSeleccionada['cupo_maximo']; ?></strong></span>
                        </div>
                        <div>
                            <span class="stat-icon"><i class="fas fa-tag"></i></span>
                            <span><small>Precio externo</small><strong>$<?php echo number_format((float) $claseSeleccionada['precio_clase'], 2); ?></strong></span>
                        </div>
                        <div>
                            <span class="stat-icon"><i class="far fa-clock"></i></span>
                            <span><small>Duración</small><strong><?php echo (int) $claseSeleccionada['duracion_minutos']; ?> min</strong></span>
                        </div>
                    </div>
                </div>

                <div class="class-roster-card">
                    <div class="class-roster-head">
                        <div class="roster-session-copy">
                            <span class="roster-session-kicker">Sesión seleccionada</span>
                            <h3>Grupo de la sesión</h3>
                            <p>
                                <?php echo clases_h(clase_registro_fecha_corta($fechaSeleccionada)); ?>
                                <?php if ($horarioSeleccionadoTexto !== ''): ?>
                                    · <?php echo clases_h($horarioSeleccionadoTexto); ?>
                                <?php endif; ?>
                                · <?php echo number_format($totalParticipantes); ?> registrados
                            </p>
                        </div>

                        <form method="GET" class="class-roster-filters" id="rosterSearchForm">
                            <input type="hidden" name="vista" value="<?php echo $vista_global ? 'global' : 'sucursal'; ?>">
                            <input type="hidden" name="clase" value="<?php echo $claseSeleccionadaId; ?>">
                            <?php if ($horarioSeleccionadoId > 0): ?>
                                <input type="hidden" name="horario" value="<?php echo $horarioSeleccionadoId; ?>">
                            <?php endif; ?>

                            <label class="roster-search-field">
                                <i class="fas fa-magnifying-glass"></i>
                                <input
                                    type="search"
                                    name="grupo_buscar"
                                    id="rosterSearchInput"
                                    value="<?php echo clases_h($grupoBuscar); ?>"
                                    placeholder="Buscar socio o visitante"
                                    autocomplete="off"
                                >
                            </label>

                            <label class="roster-date-field">
                                <span>Fecha</span>
                                <input
                                    type="date"
                                    name="fecha"
                                    id="rosterDateInput"
                                    value="<?php echo clases_h($fechaSeleccionada); ?>"
                                >
                            </label>

                            <?php if ($grupoBuscar !== ''): ?>
                                <a
                                    class="roster-clear-filter"
                                    href="<?php echo clases_h(clases_url('inscripciones_clases.php', [
                                        'vista' => $vista_global ? 'global' : 'sucursal',
                                        'clase' => $claseSeleccionadaId,
                                        'fecha' => $fechaSeleccionada,
                                        'horario' => $horarioSeleccionadoId,
                                    ])); ?>"
                                    title="Limpiar búsqueda"
                                ><i class="fas fa-xmark"></i></a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <?php if ($participantes === []): ?>
                        <div class="class-roster-empty">
                            <i class="fas fa-user-group"></i>
                            <h4><?php echo $grupoBuscar !== '' ? 'No encontramos coincidencias' : 'Todavía no hay personas registradas'; ?></h4>
                            <p>
                                <?php echo $grupoBuscar !== ''
                                    ? 'Prueba con otro nombre, teléfono, correo o folio.'
                                    : 'Selecciona “Registrar persona” para agregar al primer participante.'; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="class-roster-table-wrap">
                            <table class="class-roster-table responsive-roster">
                                <thead>
                                    <tr>
                                        <th>Participante</th>
                                        <th>Tipo</th>
                                        <th>Acceso</th>
                                        <th>Contacto</th>
                                        <th>Asistencia</th>
                                        <th>Estado</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($participantes as $participante): ?>
                                    <?php
                                    $asistenciaRegistrada =
                                        (int) ($participante['asistencia'] ?? 0) > 0
                                        && (string) ($participante['fecha_ultima_asistencia'] ?? '')
                                            === (string) ($participante['fecha_clase'] ?? '');
                                    ?>
                                    <tr class="<?php echo $asistenciaRegistrada ? 'attendance-complete' : ''; ?>">
                                        <td>
                                            <div class="participant-cell">
                                                <span><?php echo strtoupper(substr((string) $participante['participante_nombre'], 0, 1)); ?></span>
                                                <div>
                                                    <strong><?php echo clases_h((string) $participante['participante_nombre']); ?></strong>
                                                    <small
                                                        class="participant-access-code"
                                                        title="Folio completo: <?php echo clases_h((string) $participante['folio_acceso']); ?>"
                                                    >
                                                        <i class="fas fa-ticket"></i>
                                                        Acceso #<?php echo str_pad((string) ((int) $participante['id']), 5, '0', STR_PAD_LEFT); ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="participant-type <?php echo $participante['tipo_participante'] === 'externo' ? 'external' : ''; ?>">
                                                <?php echo $participante['tipo_participante'] === 'externo' ? 'Visitante' : 'Socio'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ((int) $participante['cubierto_membresia'] === 1): ?>
                                                <span class="access-status included"><i class="fas fa-circle-check"></i>Membresía</span>
                                            <?php else: ?>
                                                <span class="access-status paid"><i class="fas fa-receipt"></i>$<?php echo number_format((float) $participante['monto_cobrado'], 2); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="contact-cell">
                                                <span><?php echo clases_h((string) $participante['participante_telefono']); ?></span>
                                                <?php if ((string) $participante['participante_email'] !== ''): ?>
                                                    <small><?php echo clases_h((string) $participante['participante_email']); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($asistenciaRegistrada): ?>
                                                <span class="attendance-state present">
                                                    <i class="fas fa-circle-check"></i>
                                                    Presente
                                                </span>
                                            <?php else: ?>
                                                <span class="attendance-state pending">
                                                    <i class="far fa-clock"></i>
                                                    Pendiente
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="roster-state <?php echo clases_h((string) $participante['estado']); ?>">
                                                <?php echo ucfirst(clases_h((string) $participante['estado'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!$vista_global && $participante['estado'] === 'activa'): ?>
                                                <div class="roster-actions">
                                                    <?php if ($asistenciaRegistrada): ?>
                                                        <button
                                                            type="button"
                                                            class="roster-action-button attendance-done"
                                                            disabled
                                                            title="La asistencia ya fue registrada"
                                                        >
                                                            <i class="fas fa-check"></i>
                                                            <span>Registrada</span>
                                                        </button>
                                                    <?php elseif ($sesionEsFutura): ?>
                                                        <button
                                                            type="button"
                                                            class="roster-action-button attendance-upcoming"
                                                            disabled
                                                            title="La asistencia se habilitará el día de la sesión"
                                                        >
                                                            <i class="far fa-calendar"></i>
                                                            <span>Próxima</span>
                                                        </button>
                                                    <?php else: ?>
                                                        <button
                                                            type="button"
                                                            class="roster-action-button attendance"
                                                            title="Registrar asistencia"
                                                            onclick="registrarAsistenciaClase(<?php echo (int) $participante['id']; ?>, this)"
                                                        >
                                                            <i class="fas fa-calendar-check"></i>
                                                            <span>Marcar</span>
                                                        </button>
                                                    <?php endif; ?>

                                                    <button
                                                        type="button"
                                                        class="roster-action-button cancel"
                                                        title="Cancelar inscripción"
                                                        onclick="cancelarAccesoClase(<?php echo (int) $participante['id']; ?>, '<?php echo clases_h((string) $participante['metodo_pago']); ?>')"
                                                    >
                                                        <i class="fas fa-ban"></i>
                                                        <span>Cancelar</span>
                                                    </button>
                                                </div>
                                            <?php elseif ($vista_global): ?>
                                                <span class="readonly-label">Consulta</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if ($totalPaginasGrupo > 1): ?>
                        <div class="roster-pagination-bar">
                            <span>
                                Página <?php echo $grupoPage; ?> de <?php echo $totalPaginasGrupo; ?>
                                · <?php echo number_format($totalParticipantes); ?> registros
                            </span>
                            <nav class="roster-pagination" aria-label="Paginación del grupo">
                                <?php
                                $grupoBase = [
                                    'vista' => $vista_global ? 'global' : 'sucursal',
                                    'clase' => $claseSeleccionadaId,
                                    'fecha' => $fechaSeleccionada,
                                    'horario' => $horarioSeleccionadoId,
                                    'grupo_buscar' => $grupoBuscar,
                                ];
                                ?>
                                <a
                                    class="<?php echo $grupoPage <= 1 ? 'disabled' : ''; ?>"
                                    href="<?php echo clases_h(clases_url('inscripciones_clases.php', array_merge($grupoBase, [
                                        'grupo_page' => max(1, $grupoPage - 1),
                                    ]))); ?>"
                                ><i class="fas fa-chevron-left"></i></a>

                                <?php
                                $inicioPagina = max(1, $grupoPage - 2);
                                $finPagina = min($totalPaginasGrupo, $grupoPage + 2);
                                for ($numeroPagina = $inicioPagina; $numeroPagina <= $finPagina; $numeroPagina++):
                                ?>
                                    <a
                                        class="<?php echo $numeroPagina === $grupoPage ? 'active' : ''; ?>"
                                        href="<?php echo clases_h(clases_url('inscripciones_clases.php', array_merge($grupoBase, [
                                            'grupo_page' => $numeroPagina,
                                        ]))); ?>"
                                    ><?php echo $numeroPagina; ?></a>
                                <?php endfor; ?>

                                <a
                                    class="<?php echo $grupoPage >= $totalPaginasGrupo ? 'disabled' : ''; ?>"
                                    href="<?php echo clases_h(clases_url('inscripciones_clases.php', array_merge($grupoBase, [
                                        'grupo_page' => min($totalPaginasGrupo, $grupoPage + 1),
                                    ]))); ?>"
                                ><i class="fas fa-chevron-right"></i></a>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <aside class="class-schedule-card">
                <div class="schedule-card-head">
                    <span><i class="far fa-calendar"></i></span>
                    <div><h3>Horarios</h3><p>Sesiones disponibles</p></div>
                </div>

                <div class="selected-session-summary">
                    <small>Mostrando grupo de</small>
                    <strong><?php echo clases_h(clase_registro_fecha_corta($fechaSeleccionada)); ?></strong>
                    <span><?php echo clases_h($horarioSeleccionadoTexto ?: 'Horario general'); ?></span>
                </div>

                <div class="schedule-list">
                    <?php if ($horariosSeleccionados !== []): ?>
                        <?php foreach ($horariosSeleccionados as $horario): ?>
                            <?php
                            $idHorario = (int) $horario['id'];
                            $horarioActivo = $idHorario === $horarioSeleccionadoId;
                            $fechaHorario = $horarioActivo
                                ? $fechaSeleccionada
                                : clase_registro_proxima_fecha_dia((int) $horario['dia_semana']);
                            $urlHorario = clases_url('inscripciones_clases.php', [
                                'vista' => $vista_global ? 'global' : 'sucursal',
                                'clase' => $claseSeleccionadaId,
                                'horario' => $idHorario,
                                'fecha' => $fechaHorario,
                            ]);
                            ?>
                            <a
                                class="schedule-list-item <?php echo $horarioActivo ? 'active' : ''; ?>"
                                href="<?php echo clases_h($urlHorario); ?>"
                                title="Mostrar el grupo de esta sesión"
                            >
                                <span><?php echo substr(clase_registro_nombre_dia((int) $horario['dia_semana']), 0, 3); ?></span>
                                <div>
                                    <strong><?php echo clases_h(clase_registro_nombre_dia((int) $horario['dia_semana'])); ?></strong>
                                    <small>
                                        <?php echo clases_h(clase_registro_formatear_hora((string) $horario['hora_inicio'])); ?> -
                                        <?php echo clases_h(clase_registro_formatear_hora((string) $horario['hora_fin'])); ?>
                                    </small>
                                </div>
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="legacy-schedule">
                            <i class="far fa-clock"></i>
                            <span><?php echo clases_h((string) $claseSeleccionada['horario']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="membership-note">
                    <i class="fas fa-id-card"></i>
                    <div>
                        <strong>Socios con membresía vigente</strong>
                        <p>La clase queda incluida y el sistema no solicita ningún cobro.</p>
                    </div>
                </div>
            </aside>
        </section>
    <?php endif; ?>
</main>

<?php if (!$vista_global && $claseSeleccionada !== null): ?>
<div class="modal fade class-register-modal" id="modalRegistrarClase" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <span class="modal-icon"><i class="fas fa-user-plus"></i></span>
                    <div>
                        <h5 class="modal-title">Registrar acceso</h5>
                        <p><?php echo clases_h((string) $claseSeleccionada['nombre']); ?> · $<?php echo number_format((float) $claseSeleccionada['precio_clase'], 2); ?></p>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <form id="classRegistrationForm" method="POST" novalidate>
                <input type="hidden" name="action" value="registrar_participante">
                <input type="hidden" name="csrf_token" value="<?php echo clases_h($csrf); ?>">
                <input type="hidden" name="clase_id" value="<?php echo $claseSeleccionadaId; ?>">
                <input type="hidden" name="mp_order_id" value="">
                <input type="hidden" name="mp_payment_id" value="">
                <input type="hidden" name="mp_external_reference" value="">
                <input type="hidden" name="mp_payment_reference_id" value="">
                <input type="hidden" name="mp_installments" value="1">

                <div class="modal-body">
                    <section class="registration-section compact">
                        <div class="registration-section-title">
                            <span>1</span>
                            <div><strong>Sesión</strong><small>Elige la fecha y el horario.</small></div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Fecha de la clase *</label>
                                <input
                                    class="form-control"
                                    type="date"
                                    name="fecha_clase"
                                    id="classDateInput"
                                    min="<?php echo date('Y-m-d'); ?>"
                                    value="<?php echo clases_h($fechaSeleccionada); ?>"
                                    required
                                >
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Horario *</label>
                                <?php if ($horariosSeleccionados !== []): ?>
                                    <select class="form-select" name="horario_id" id="classScheduleSelect" required>
                                        <option value="">Seleccionar horario</option>
                                        <?php foreach ($horariosSeleccionados as $horario): ?>
                                            <option
                                                value="<?php echo (int) $horario['id']; ?>"
                                                data-day="<?php echo (int) $horario['dia_semana']; ?>"
                                                <?php echo (int) $horario['id'] === $horarioSeleccionadoId ? 'selected' : ''; ?>
                                            >
                                                <?php echo clases_h(clase_registro_nombre_dia((int) $horario['dia_semana'])); ?> ·
                                                <?php echo clases_h(clase_registro_formatear_hora((string) $horario['hora_inicio'])); ?> -
                                                <?php echo clases_h(clase_registro_formatear_hora((string) $horario['hora_fin'])); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="hidden" name="horario_id" value="0">
                                    <div class="legacy-schedule-input"><i class="far fa-clock"></i><?php echo clases_h((string) $claseSeleccionada['horario']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>

                    <section class="registration-section">
                        <div class="registration-section-title">
                            <span>2</span>
                            <div><strong>Participante</strong><small>Busca un socio o registra a un visitante.</small></div>
                        </div>

                        <div class="participant-mode-switch">
                            <label>
                                <input type="radio" name="tipo_participante" value="socio" checked>
                                <span><i class="fas fa-id-card"></i><strong>Socio</strong><small>Puede estar incluido por membresía</small></span>
                            </label>
                            <label>
                                <input type="radio" name="tipo_participante" value="externo">
                                <span><i class="fas fa-user"></i><strong>Visitante</strong><small>Se cobra el acceso individual</small></span>
                            </label>
                        </div>

                        <div id="memberFields">
                            <label class="form-label">Socio *</label>
                            <select class="form-select" name="cliente_id" id="memberSelect">
                                <option value="">Buscar o seleccionar socio</option>
                                <?php foreach ($clientes as $cliente): ?>
                                    <option value="<?php echo (int) $cliente['id']; ?>">
                                        <?php echo clases_h(trim((string) $cliente['nombre'] . ' ' . (string) $cliente['apellido']) . ' · ' . (string) $cliente['telefono']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="membership-result idle" id="membershipResult">
                                <i class="fas fa-circle-info"></i>
                                <span>Selecciona un socio para revisar su membresía.</span>
                            </div>
                        </div>

                        <div id="externalFields" class="d-none">
                            <input type="hidden" name="visitante_id" id="visitorId" value="">

                            <div class="visitor-lookup-card">
                                <div class="visitor-lookup-heading">
                                    <div>
                                        <strong>Visitante registrado</strong>
                                        <small>Busca por nombre, celular o correo para no capturarlo nuevamente.</small>
                                    </div>
                                    <button type="button" class="visitor-new-button" id="newVisitorButton">
                                        <i class="fas fa-user-plus"></i>
                                        Nuevo
                                    </button>
                                </div>

                                <label class="visitor-search-control">
                                    <i class="fas fa-magnifying-glass"></i>
                                    <input
                                        type="search"
                                        id="visitorSearchInput"
                                        placeholder="Ej. Fabiola, 2221234567 o correo..."
                                        autocomplete="off"
                                    >
                                    <span class="visitor-search-spinner d-none" id="visitorSearchSpinner">
                                        <i class="fas fa-spinner fa-spin"></i>
                                    </span>
                                </label>

                                <div class="visitor-search-results d-none" id="visitorSearchResults"></div>

                                <div class="visitor-selected d-none" id="visitorSelected">
                                    <span class="visitor-selected-icon"><i class="fas fa-user-check"></i></span>
                                    <div>
                                        <strong id="visitorSelectedName">Visitante seleccionado</strong>
                                        <small id="visitorSelectedMeta"></small>
                                    </div>
                                    <button type="button" id="clearVisitorButton" title="Cambiar visitante">
                                        <i class="fas fa-xmark"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="visitor-data-heading">
                                <strong>Datos del visitante</strong>
                                <small>Al confirmar, quedará guardado para futuras clases.</small>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Nombre *</label>
                                    <input class="form-control" type="text" name="nombre_externo" maxlength="100" autocomplete="given-name">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Apellidos *</label>
                                    <input class="form-control" type="text" name="apellido_externo" maxlength="120" autocomplete="family-name">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Número celular *</label>
                                    <input class="form-control" type="tel" name="telefono_externo" maxlength="25" autocomplete="tel">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Correo <small>(opcional)</small></label>
                                    <input class="form-control" type="email" name="email_externo" maxlength="150" autocomplete="email">
                                    <div class="form-hint">Se utilizará para enviar el comprobante PDF.</div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="registration-section payment-section" id="paymentSection">
                        <div class="registration-section-title">
                            <span>3</span>
                            <div><strong>Cobro</strong><small>El precio siempre se valida desde la base de datos.</small></div>
                            <div class="class-total-box">
                                <small>Total</small>
                                <strong id="classTotalLabel">$<?php echo number_format((float) $claseSeleccionada['precio_clase'], 2); ?></strong>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Método de pago *</label>
                                <select class="form-select" name="metodo_pago" id="paymentMethod">
                                    <option value="efectivo">Efectivo</option>
                                    <?php if ($terminalPointDisponible): ?>
                                        <option value="tarjeta_debito">Tarjeta de débito · Point</option>
                                        <option value="tarjeta_credito">Tarjeta de crédito · Point</option>
                                    <?php else: ?>
                                        <option value="" disabled>Point no configurada en esta sucursal</option>
                                    <?php endif; ?>
                                    <option value="transferencia">Transferencia</option>
                                </select>
                            </div>
                            <div class="col-md-6" id="cashReceivedField">
                                <label class="form-label">Efectivo recibido</label>
                                <input class="form-control" type="number" name="monto_recibido" min="0" step="0.01" value="<?php echo number_format((float) $claseSeleccionada['precio_clase'], 2, '.', ''); ?>">
                            </div>
                            <div class="col-12 d-none" id="referenceField">
                                <label class="form-label">Referencia de transferencia *</label>
                                <input class="form-control" type="text" name="referencia" maxlength="120" placeholder="Folio o referencia bancaria">
                            </div>
                        </div>

                        <div class="point-payment-note d-none" id="pointPaymentNote">
                            <i class="fas fa-credit-card"></i>
                            <span>El cobro se enviará a la terminal Mercado Pago Point de esta sucursal.</span>
                        </div>
                    </section>

                    <div class="included-summary d-none" id="includedSummary">
                        <i class="fas fa-circle-check"></i>
                        <div><strong>Acceso incluido</strong><p>La membresía está vigente. No se aplicará ningún cobro.</p></div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-modal-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn-modal-primary" id="saveClassRegistration">
                        <i class="fas fa-check"></i>
                        Confirmar registro
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<form method="POST" id="attendanceForm" class="d-none">
    <input type="hidden" name="action" value="registrar_asistencia">
    <input type="hidden" name="csrf_token" value="<?php echo clases_h($csrf); ?>">
    <input type="hidden" name="clase_id" value="<?php echo $claseSeleccionadaId; ?>">
    <input type="hidden" name="fecha_clase" value="<?php echo clases_h($fechaSeleccionada); ?>">
    <input type="hidden" name="horario_id" value="<?php echo $horarioSeleccionadoId; ?>">
    <input type="hidden" name="grupo_buscar" value="<?php echo clases_h($grupoBuscar); ?>">
    <input type="hidden" name="grupo_page" value="<?php echo $grupoPage; ?>">
    <input type="hidden" name="inscripcion_id" value="">
</form>

<form method="POST" id="cancelAccessForm" class="d-none">
    <input type="hidden" name="action" value="cancelar_inscripcion">
    <input type="hidden" name="csrf_token" value="<?php echo clases_h($csrf); ?>">
    <input type="hidden" name="clase_id" value="<?php echo $claseSeleccionadaId; ?>">
    <input type="hidden" name="fecha_clase" value="<?php echo clases_h($fechaSeleccionada); ?>">
    <input type="hidden" name="horario_id" value="<?php echo $horarioSeleccionadoId; ?>">
    <input type="hidden" name="grupo_buscar" value="<?php echo clases_h($grupoBuscar); ?>">
    <input type="hidden" name="grupo_page" value="<?php echo $grupoPage; ?>">
    <input type="hidden" name="inscripcion_id" value="">
</form>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php if (is_array($swal_clases)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const data = <?php echo json_encode(
        $swal_clases,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES |
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ); ?>;
    const ok = data.icon === 'success';
    Swal.fire({
        icon: data.icon || 'info',
        title: data.title || 'Información',
        text: data.message || '',
        confirmButtonColor: '#1e3a8a',
        confirmButtonText: 'Entendido',
        timer: ok ? 3600 : undefined,
        timerProgressBar: ok,
        showConfirmButton: !ok
    });
});
</script>
<?php endif; ?>

<script>
(function () {
    const cardsSearch = document.getElementById('classCardSearch');
    if (cardsSearch) {
        cardsSearch.addEventListener('input', function () {
            const value = this.value.trim().toLowerCase();
            document.querySelectorAll('.class-access-card').forEach(function (card) {
                const haystack = card.dataset.classSearch || '';
                card.hidden = value !== '' && !haystack.includes(value);
            });
        });
    }

    document.querySelectorAll('.responsive-roster').forEach(function (table) {
        const labels = Array.from(table.querySelectorAll('thead th')).map(function (th) {
            return th.textContent.trim();
        });
        table.querySelectorAll('tbody tr').forEach(function (row) {
            row.querySelectorAll('td').forEach(function (cell, index) {
                cell.dataset.label = labels[index] || '';
            });
        });
    });

    const rosterSearchForm = document.getElementById('rosterSearchForm');
    const rosterSearchInput = document.getElementById('rosterSearchInput');
    const rosterDateInput = document.getElementById('rosterDateInput');
    let rosterSearchTimer;

    if (rosterSearchForm && rosterSearchInput) {
        rosterSearchInput.addEventListener('input', function () {
            clearTimeout(rosterSearchTimer);
            rosterSearchTimer = setTimeout(function () {
                rosterSearchForm.submit();
            }, 450);
        });
    }

    if (rosterSearchForm && rosterDateInput) {
        rosterDateInput.addEventListener('change', function () {
            rosterSearchForm.submit();
        });
    }

    const form = document.getElementById('classRegistrationForm');
    if (!form) return;

    const classId = Number(form.elements.clase_id.value || 0);
    const classPrice = Number(<?php echo json_encode((float) ($claseSeleccionada['precio_clase'] ?? 0)); ?>);
    const memberFields = document.getElementById('memberFields');
    const externalFields = document.getElementById('externalFields');
    const memberSelect = document.getElementById('memberSelect');
    const dateInput = document.getElementById('classDateInput');
    const scheduleSelect = document.getElementById('classScheduleSelect');
    const membershipResult = document.getElementById('membershipResult');
    const paymentSection = document.getElementById('paymentSection');
    const includedSummary = document.getElementById('includedSummary');
    const paymentMethod = document.getElementById('paymentMethod');
    const cashField = document.getElementById('cashReceivedField');
    const referenceField = document.getElementById('referenceField');
    const pointNote = document.getElementById('pointPaymentNote');
    const totalLabel = document.getElementById('classTotalLabel');
    const saveButton = document.getElementById('saveClassRegistration');
    const visitorIdInput = document.getElementById('visitorId');
    const visitorSearchInput = document.getElementById('visitorSearchInput');
    const visitorSearchResults = document.getElementById('visitorSearchResults');
    const visitorSearchSpinner = document.getElementById('visitorSearchSpinner');
    const visitorSelected = document.getElementById('visitorSelected');
    const visitorSelectedName = document.getElementById('visitorSelectedName');
    const visitorSelectedMeta = document.getElementById('visitorSelectedMeta');
    const newVisitorButton = document.getElementById('newVisitorButton');
    const clearVisitorButton = document.getElementById('clearVisitorButton');
    let amountToCharge = classPrice;
    let memberCovered = false;
    let visitorSearchTimer = null;
    let visitorSearchController = null;

    function visitorFields() {
        return {
            name: form.elements.nombre_externo,
            lastName: form.elements.apellido_externo,
            phone: form.elements.telefono_externo,
            email: form.elements.email_externo
        };
    }

    function closeVisitorResults() {
        if (!visitorSearchResults) return;
        visitorSearchResults.classList.add('d-none');
        visitorSearchResults.innerHTML = '';
    }

    function releaseVisitorSelection(clearData) {
        if (visitorIdInput) visitorIdInput.value = '';
        if (visitorSelected) visitorSelected.classList.add('d-none');

        if (clearData) {
            const fields = visitorFields();
            fields.name.value = '';
            fields.lastName.value = '';
            fields.phone.value = '';
            fields.email.value = '';
        }
    }

    function clearVisitorSelection(clearData) {
        releaseVisitorSelection(clearData);

        if (visitorSearchInput) {
            visitorSearchInput.value = '';
            visitorSearchInput.focus();
        }

        closeVisitorResults();
    }

    function splitVisitorName(query) {
        const parts = String(query || '')
            .trim()
            .replace(/\s+/g, ' ')
            .split(' ')
            .filter(Boolean);

        if (parts.length === 0) {
            return {name: '', lastName: ''};
        }

        if (parts.length === 1) {
            return {name: parts[0], lastName: ''};
        }

        if (parts.length >= 4) {
            return {
                name: parts.slice(0, 2).join(' '),
                lastName: parts.slice(2).join(' ')
            };
        }

        return {
            name: parts[0],
            lastName: parts.slice(1).join(' ')
        };
    }

    function useQueryAsNewVisitor(query) {
        const value = String(query || '').trim().replace(/\s+/g, ' ');
        if (!value) return;

        releaseVisitorSelection(false);

        const fields = visitorFields();
        const digits = value.replace(/\D+/g, '');
        const looksLikeEmail = value.includes('@');
        const looksLikePhone = /^[+()\d\s.-]+$/.test(value) && digits.length >= 7;

        if (looksLikeEmail) {
            fields.email.value = value;
            return;
        }

        if (looksLikePhone) {
            fields.phone.value = value;
            return;
        }

        const parsed = splitVisitorName(value);

        if (parsed.lastName !== '') {
            fields.name.value = parsed.name;
            fields.lastName.value = parsed.lastName;
            return;
        }

        /*
         * Una sola palabra se manda primero a Nombre. Si Nombre ya fue
         * capturado y Apellidos está vacío, se interpreta como apellido.
         */
        if (fields.name.value.trim() === '') {
            fields.name.value = parsed.name;
        } else if (fields.lastName.value.trim() === '') {
            fields.lastName.value = parsed.name;
        } else {
            fields.name.value = parsed.name;
        }
    }

    function selectVisitor(visitor) {
        const fields = visitorFields();

        visitorIdInput.value = String(visitor.id || '');
        fields.name.value = visitor.nombre || '';
        fields.lastName.value = visitor.apellido || '';
        fields.phone.value = visitor.telefono || '';
        fields.email.value = visitor.email || '';

        visitorSelectedName.textContent =
            (visitor.nombre_completo || ((visitor.nombre || '') + ' ' + (visitor.apellido || ''))).trim();

        const visits = Number(visitor.total_visitas || 0);
        const parts = [
            visitor.codigo || ('Visitante #' + String(visitor.id || '').padStart(6, '0')),
            visitor.telefono || 'Sin celular',
            visits + (visits === 1 ? ' acceso' : ' accesos')
        ];

        visitorSelectedMeta.textContent = parts.join(' · ');
        visitorSelected.classList.remove('d-none');
        visitorSearchInput.value = '';
        closeVisitorResults();
    }

    function renderVisitorResults(visitors, query) {
        if (!visitorSearchResults) return;

        visitorSearchResults.innerHTML = '';

        if (!Array.isArray(visitors) || visitors.length === 0) {
            useQueryAsNewVisitor(query);

            const empty = document.createElement('div');
            empty.className = 'visitor-result-empty';
            empty.innerHTML =
                '<i class="fas fa-user-plus"></i>'
                + '<span>No se encontró a <strong>' + escapeHtml(query) + '</strong>. '
                + 'El dato se pasó al formulario para registrarlo como visitante nuevo.</span>';
            visitorSearchResults.appendChild(empty);
            visitorSearchResults.classList.remove('d-none');
            return;
        }

        visitors.forEach(function (visitor) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'visitor-result-item';

            const initials = ((visitor.nombre || '').charAt(0) + (visitor.apellido || '').charAt(0)).toUpperCase();
            const visits = Number(visitor.total_visitas || 0);

            button.innerHTML =
                '<span class="visitor-result-avatar">' + escapeHtml(initials || 'V') + '</span>'
                + '<span class="visitor-result-copy">'
                + '<strong>' + escapeHtml(visitor.nombre_completo || '') + '</strong>'
                + '<small>' + escapeHtml(visitor.telefono || 'Sin celular')
                + (visitor.email ? ' · ' + escapeHtml(visitor.email) : '')
                + '</small>'
                + '</span>'
                + '<span class="visitor-result-visits">'
                + visits + (visits === 1 ? ' acceso' : ' accesos')
                + '</span>';

            button.addEventListener('click', function () {
                selectVisitor(visitor);
            });

            visitorSearchResults.appendChild(button);
        });

        visitorSearchResults.classList.remove('d-none');
    }

    async function searchVisitors(query) {
        if (!visitorSearchInput || selectedType() !== 'externo') return;

        if (visitorSearchController) {
            visitorSearchController.abort();
        }

        visitorSearchController = new AbortController();
        visitorSearchSpinner.classList.remove('d-none');

        try {
            const response = await fetch(
                'api/clases/buscar_visitantes.php?q=' + encodeURIComponent(query),
                {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    signal: visitorSearchController.signal
                }
            );

            const text = await response.text();
            let data;

            try {
                data = JSON.parse(text);
            } catch (error) {
                throw new Error('El buscador de visitantes devolvió una respuesta no válida.');
            }

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'No fue posible buscar visitantes.');
            }

            renderVisitorResults(data.visitantes || [], query);
        } catch (error) {
            if (error.name === 'AbortError') return;

            visitorSearchResults.innerHTML =
                '<div class="visitor-result-empty is-error">'
                + '<i class="fas fa-triangle-exclamation"></i>'
                + '<span>' + escapeHtml(error.message) + '</span>'
                + '</div>';
            visitorSearchResults.classList.remove('d-none');
        } finally {
            visitorSearchSpinner.classList.add('d-none');
        }
    }

    function isPoint(method) {
        return method === 'tarjeta_debito' || method === 'tarjeta_credito';
    }

    function setPaymentVisibility(show) {
        paymentSection.classList.toggle('d-none', !show);
        includedSummary.classList.toggle('d-none', show);
        if (paymentMethod) paymentMethod.required = show;
        amountToCharge = show ? classPrice : 0;
        totalLabel.textContent = '$' + amountToCharge.toFixed(2);
    }

    function updatePaymentFields() {
        const method = paymentMethod ? paymentMethod.value : '';
        cashField.classList.toggle('d-none', method !== 'efectivo');
        referenceField.classList.toggle('d-none', method !== 'transferencia');
        pointNote.classList.toggle('d-none', !isPoint(method));
        const ref = form.elements.referencia;
        if (ref) ref.required = method === 'transferencia';
    }

    function selectedType() {
        return form.querySelector('input[name="tipo_participante"]:checked')?.value || 'socio';
    }

    function updateParticipantMode() {
        const type = selectedType();
        const external = type === 'externo';
        memberFields.classList.toggle('d-none', external);
        externalFields.classList.toggle('d-none', !external);
        memberSelect.required = !external;
        ['nombre_externo', 'apellido_externo', 'telefono_externo'].forEach(function (name) {
            form.elements[name].required = external;
        });

        if (external) {
            memberCovered = false;
            setPaymentVisibility(true);
        } else {
            checkMembership();
        }
    }

    async function checkMembership() {
        if (selectedType() !== 'socio') return;
        const clientId = Number(memberSelect.value || 0);
        const date = dateInput.value;

        if (!clientId || !date) {
            memberCovered = false;
            membershipResult.className = 'membership-result idle';
            membershipResult.innerHTML = '<i class="fas fa-circle-info"></i><span>Selecciona un socio para revisar su membresía.</span>';
            setPaymentVisibility(true);
            return;
        }

        membershipResult.className = 'membership-result loading';
        membershipResult.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Consultando membresía...</span>';

        try {
            const response = await fetch('api/clases/consultar_cobro.php', {
                method: 'POST',
                credentials: 'same-origin',
                redirect: 'follow',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    clase_id: classId,
                    cliente_id: clientId,
                    fecha_clase: date,
                    csrf_token: <?php echo json_encode(
                        $csrf,
                        JSON_HEX_TAG
                        | JSON_HEX_APOS
                        | JSON_HEX_AMP
                        | JSON_HEX_QUOT
                    ); ?>
                })
            });

            const responseText = await response.text();
            let data = null;

            try {
                data = JSON.parse(responseText);
            } catch (parseError) {
                const looksLikeHtml = /^\s*</.test(responseText);

                throw new Error(
                    looksLikeHtml
                        ? 'El servidor devolvió una página HTML en lugar de JSON. Reemplaza api/clases/consultar_cobro.php por la versión corregida.'
                        : 'La respuesta del servidor no contiene JSON válido.'
                );
            }

            if (!response.ok || !data.success) {
                if (response.status === 401) {
                    throw new Error('Tu sesión terminó. Actualiza la página e inicia sesión nuevamente.');
                }

                throw new Error(
                    data.message
                    || 'No se pudo consultar la membresía.'
                );
            }

            memberCovered = Boolean(data.cubierto_membresia);
            membershipResult.className = 'membership-result ' + (memberCovered ? 'covered' : 'not-covered');

            if (memberCovered) {
                const planName = data.membresia && data.membresia.plan_nombre
                    ? ' · ' + escapeHtml(data.membresia.plan_nombre)
                    : '';

                membershipResult.innerHTML =
                    '<i class="fas fa-circle-check"></i>'
                    + '<span><strong>Membresía activa y vigente</strong>'
                    + planName
                    + '. La clase está incluida y el total es $0.00.</span>';
            } else {
                const reason = data.motivo
                    ? escapeHtml(data.motivo)
                    : 'El socio no tiene una membresía activa y vigente para esta fecha.';
                const charge = Number(
                    data.monto_cobrar !== undefined
                        ? data.monto_cobrar
                        : classPrice
                ).toFixed(2);

                membershipResult.innerHTML =
                    '<i class="fas fa-circle-exclamation"></i>'
                    + '<span>'
                    + reason
                    + ' <strong>Total a cobrar: $'
                    + charge
                    + '.</strong></span>';
            }

            setPaymentVisibility(!memberCovered);
        } catch (error) {
            memberCovered = false;
            membershipResult.className = 'membership-result not-covered';
            membershipResult.innerHTML = '<i class="fas fa-triangle-exclamation"></i><span>' + escapeHtml(error.message) + '</span>';
            setPaymentVisibility(true);
        }
    }

    function escapeHtml(value) {
        const node = document.createElement('div');
        node.textContent = String(value || '');
        return node.innerHTML;
    }

    function nextDateForDay(day) {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const current = today.getDay() === 0 ? 7 : today.getDay();
        let delta = day - current;
        if (delta < 0) delta += 7;
        today.setDate(today.getDate() + delta);
        return today.toISOString().slice(0, 10);
    }

    if (scheduleSelect) {
        scheduleSelect.addEventListener('change', function () {
            const option = this.options[this.selectedIndex];
            const day = Number(option?.dataset.day || 0);
            if (day > 0) dateInput.value = nextDateForDay(day);
            checkMembership();
        });
    }

    if (visitorSearchInput) {
        visitorSearchInput.addEventListener('input', function () {
            const query = this.value.trim();
            clearTimeout(visitorSearchTimer);

            /*
             * Al comenzar otra búsqueda se elimina inmediatamente la selección
             * anterior. Así un visitante viejo nunca queda ligado por accidente
             * a los datos de una persona nueva.
             */
            if (visitorIdInput && visitorIdInput.value !== '') {
                releaseVisitorSelection(true);
            }

            if (query.length < 2) {
                closeVisitorResults();
                return;
            }

            visitorSearchTimer = setTimeout(function () {
                searchVisitors(query);
            }, 280);
        });

        visitorSearchInput.addEventListener('focus', function () {
            const query = this.value.trim();
            if (query.length >= 2) {
                searchVisitors(query);
            }
        });
    }

    if (newVisitorButton) {
        newVisitorButton.addEventListener('click', function () {
            clearVisitorSelection(true);
        });
    }

    if (clearVisitorButton) {
        clearVisitorButton.addEventListener('click', function () {
            clearVisitorSelection(true);
        });
    }

    document.addEventListener('click', function (event) {
        if (
            visitorSearchResults
            && !visitorSearchResults.contains(event.target)
            && event.target !== visitorSearchInput
        ) {
            closeVisitorResults();
        }
    });

    form.querySelectorAll('input[name="tipo_participante"]').forEach(function (radio) {
        radio.addEventListener('change', updateParticipantMode);
    });
    memberSelect.addEventListener('change', checkMembership);
    dateInput.addEventListener('change', checkMembership);
    paymentMethod.addEventListener('change', updatePaymentFields);
    updatePaymentFields();
    updateParticipantMode();

    async function fetchPoint(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });
        const text = await response.text();
        let data;
        try { data = JSON.parse(text); }
        catch (error) { throw new Error('El servidor devolvió una respuesta no válida.'); }
        if (!response.ok || !data.success) {
            const err = new Error(data.message || 'No se pudo procesar el cobro Point.');
            err.requiresTerminal = Boolean(data.requires_terminal);
            throw err;
        }
        return data;
    }

    async function waitPoint(order) {
        let active = true;
        const started = Date.now();
        const maxWait = 190000;

        return new Promise(function (resolve, reject) {
            Swal.fire({
                title: 'Esperando pago en terminal',
                html: '<p>Completa el cobro en la Point. No cierres esta ventana.</p>' +
                      '<div class="point-order-card">Orden: ' + escapeHtml(order.order_id) + '</div>' +
                      '<div id="class-point-status">Estado: creada</div>',
                showConfirmButton: false,
                showCancelButton: true,
                cancelButtonText: 'Cancelar cobro',
                cancelButtonColor: '#dc2626',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: async function () {
                    while (active) {
                        try {
                            const latest = await fetchPoint(
                                'api/mercadopago/consultar_orden_clase.php',
                                {order_id: order.order_id}
                            );
                            const node = document.getElementById('class-point-status');
                            if (node) node.textContent = 'Orden: ' + (latest.order_status || '-') + ' · Pago: ' + (latest.payment_status || '-');
                            if (latest.paid) {
                                active = false;
                                Swal.close();
                                resolve(latest);
                                return;
                            }
                            if (latest.final_failure) {
                                active = false;
                                Swal.close();
                                reject(new Error('El pago no fue aprobado.'));
                                return;
                            }
                        } catch (error) {
                            console.error(error);
                        }

                        if (Date.now() - started >= maxWait) {
                            active = false;
                            Swal.close();
                            reject(new Error('Terminó el tiempo de espera para completar el pago.'));
                            return;
                        }
                        await new Promise(function (r) { setTimeout(r, 2500); });
                    }
                }
            }).then(async function (result) {
                if (!result.dismiss || !active) return;
                active = false;
                try {
                    const canceled = await fetchPoint(
                        'api/mercadopago/cancelar_orden_clase.php',
                        {order_id: order.order_id}
                    );
                    if (canceled.requires_terminal) {
                        reject(new Error('Cancela el cobro directamente en la terminal Point.'));
                    } else {
                        reject(new Error('Cobro cancelado.'));
                    }
                } catch (error) {
                    reject(error);
                }
            });
        });
    }

    form.addEventListener('submit', async function (event) {
        if (form.dataset.ready === '1') return;
        event.preventDefault();

        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        if (scheduleSelect && scheduleSelect.value) {
            const option = scheduleSelect.options[scheduleSelect.selectedIndex];
            const expectedDay = Number(option.dataset.day || 0);
            const dateDay = new Date(dateInput.value + 'T12:00:00').getDay();
            const normalized = dateDay === 0 ? 7 : dateDay;
            if (expectedDay && expectedDay !== normalized) {
                Swal.fire({icon: 'warning', title: 'Fecha incorrecta', text: 'La fecha no coincide con el día del horario seleccionado.', confirmButtonColor: '#1e3a8a'});
                return;
            }
        }

        const method = paymentMethod.value;
        saveButton.disabled = true;
        saveButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';

        try {
            if (amountToCharge > 0 && isPoint(method)) {
                const paymentType = method === 'tarjeta_credito' ? 'credit_card' : 'debit_card';
                const order = await fetchPoint(
                    'api/mercadopago/crear_orden_clase.php',
                    {clase_id: classId, total: amountToCharge, payment_type: paymentType}
                );
                const paid = await waitPoint(order);
                form.elements.mp_order_id.value = paid.order_id || order.order_id || '';
                form.elements.mp_payment_id.value = paid.payment_id || order.payment_id || '';
                form.elements.mp_external_reference.value = paid.external_reference || order.external_reference || '';
                form.elements.mp_payment_reference_id.value = paid.payment_reference_id || '';
                form.elements.mp_installments.value = String(paid.installments || order.installments || 1);
            }

            form.dataset.ready = '1';
            form.submit();
        } catch (error) {
            saveButton.disabled = false;
            saveButton.innerHTML = '<i class="fas fa-check"></i> Confirmar registro';
            Swal.fire({
                icon: 'error',
                title: 'No se completó el registro',
                text: error.message || 'Ocurrió un error al procesar el pago.',
                confirmButtonColor: '#1e3a8a'
            });
        }
    });

    window.registrarAsistenciaClase = function (id, button) {
        Swal.fire({
            icon: 'question',
            title: '¿Marcar como presente?',
            text: 'Se registrará la asistencia del participante en la sesión seleccionada.',
            showCancelButton: true,
            confirmButtonText: 'Sí, marcar presente',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#10b981'
        }).then(function (result) {
            if (!result.isConfirmed) return;

            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Guardando</span>';
            }

            const target = document.getElementById('attendanceForm');
            target.elements.inscripcion_id.value = String(id);
            target.submit();
        });
    };

    window.cancelarAccesoClase = function (id, method) {
        if (method === 'tarjeta') {
            Swal.fire({
                icon: 'info',
                title: 'Pago con tarjeta',
                text: 'Primero debe realizarse el reembolso en Mercado Pago. El sistema no cancelará localmente un pago aprobado sin devolución.',
                confirmButtonColor: '#1e3a8a'
            });
            return;
        }

        Swal.fire({
            icon: 'warning',
            title: '¿Cancelar inscripción?',
            text: 'El lugar volverá a quedar disponible.',
            showCancelButton: true,
            confirmButtonText: 'Sí, cancelar',
            cancelButtonText: 'No',
            confirmButtonColor: '#dc2626'
        }).then(function (result) {
            if (!result.isConfirmed) return;
            const target = document.getElementById('cancelAccessForm');
            target.elements.inscripcion_id.value = String(id);
            target.submit();
        });
    };
})();
</script>
</body>
</html>
