<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/expediente_salud_helper.php';
require_once __DIR__ . '/includes/expediente_salud_invitaciones.php';
require_once __DIR__ . '/includes/correo_expediente_salud.php';
require_once __DIR__ . '/includes/correo_cola.php';

function cuestionario_salud_h($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function cuestionario_salud_parentesco_documento(string $parentesco): string
{
    $clave = function_exists('mb_strtolower')
        ? mb_strtolower(trim($parentesco), 'UTF-8')
        : strtolower(trim($parentesco));

    $mapa = [
        'socio' => 'socio',
        'madre/padre/tutor' => 'madre, padre o tutor',
        'responsable legal' => 'responsable legal',
        'otro' => 'otro responsable',
    ];

    return $mapa[$clave] ?? (trim($parentesco) !== '' ? trim($parentesco) : 'socio');
}

function cuestionario_salud_documento(string $texto, array $datos): string
{
    $reemplazos = [
        '{{GIMNASIO}}' => (string) ($datos['gimnasio'] ?? 'Gimnasio'),
        '{{SOCIO}}' => (string) ($datos['socio'] ?? 'Socio'),
        '{{FECHA}}' => (string) ($datos['fecha'] ?? date('d/m/Y')),
        '{{SUCURSAL}}' => (string) ($datos['sucursal'] ?? 'Sucursal'),
        '{{ADMINISTRADOR}}' => (string) ($datos['administrador'] ?? 'Administrador'),
        '[NOMBRE DEL GIMNASIO]' => (string) ($datos['gimnasio'] ?? 'Gimnasio'),
        '[GIMNASIO]' => (string) ($datos['gimnasio'] ?? 'Gimnasio'),
        '[NOMBRE DEL SOCIO]' => (string) ($datos['socio'] ?? 'Socio'),
        '[SOCIO]' => (string) ($datos['socio'] ?? 'Socio'),
        '[FECHA]' => (string) ($datos['fecha'] ?? date('d/m/Y')),
        '[SUCURSAL]' => (string) ($datos['sucursal'] ?? 'Sucursal'),
        '[ADMINISTRADOR]' => (string) ($datos['administrador'] ?? 'Administrador'),
        '[PERSONA QUE ACEPTA]' => (string) ($datos['firmante'] ?? $datos['socio'] ?? 'Socio'),
        '[RELACIÓN CON EL SOCIO]' => cuestionario_salud_parentesco_documento(
            (string) ($datos['parentesco'] ?? 'Socio')
        ),
    ];

    return trim(str_ireplace(
        array_keys($reemplazos),
        array_values($reemplazos),
        strtr($texto, $reemplazos)
    ));
}

/**
 * Relaciona las preguntas de detalle con las respuestas Sí/No que las activan.
 *
 * La estructura actual del cuestionario utiliza:
 *  - 4  -> 5  (lesión, cirugía o limitación)
 *  - 6/7 -> 8 (medicamentos o alergias)
 *  - 9  -> 10 (otro antecedente o recomendación)
 *
 * Si alguna pregunta deja de existir en la configuración, se ignora sin
 * interrumpir el formulario.
 *
 * @return array<int, int[]>
 */
function cuestionario_salud_dependencias(array $preguntas): array
{
    $idsDisponibles = [];
    foreach ($preguntas as $pregunta) {
        $idsDisponibles[(int) ($pregunta['id'] ?? 0)] = true;
    }

    $candidatas = [
        5 => [4],
        8 => [6, 7],
        10 => [9],
    ];

    $dependencias = [];
    foreach ($candidatas as $preguntaDetalleId => $disparadores) {
        if (!isset($idsDisponibles[$preguntaDetalleId])) {
            continue;
        }

        $activos = [];
        foreach ($disparadores as $disparadorId) {
            if (isset($idsDisponibles[$disparadorId])) {
                $activos[] = $disparadorId;
            }
        }

        if ($activos !== []) {
            $dependencias[$preguntaDetalleId] = $activos;
        }
    }

    return $dependencias;
}

function cuestionario_salud_condicional_activa(
    int $preguntaId,
    array $dependencias,
    array $respuestas
): bool {
    if (!isset($dependencias[$preguntaId])) {
        return true;
    }

    foreach ((array) $dependencias[$preguntaId] as $disparadorId) {
        $campo = 'pregunta_' . (int) $disparadorId;
        if (trim((string) ($respuestas[$campo] ?? '')) === '1') {
            return true;
        }
    }

    return false;
}


$database = new Database();
$conn = $database->getConnection();
if (!$conn instanceof mysqli) {
    http_response_code(500);
    exit('No fue posible conectar con la base de datos.');
}
$conn->set_charset('utf8mb4');

$token = strtolower(trim((string) ($_GET['token'] ?? $_POST['token'] ?? '')));
$invitacion = null;
$error = '';
$completadoAhora = false;
$correoFinalProgramado = false;
$emailFinalDisponible = false;
$expedienteCreadoId = 0;

try {
    $invitacion = expediente_obtener_invitacion($conn, $token);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if (!$invitacion && $error === '') {
    $error = 'El enlace no existe o no es válido.';
}

if (
    $invitacion
    && (string) $invitacion['estado'] === 'completada'
    && isset($_GET['descargar'])
    && (int) ($invitacion['expediente_id'] ?? 0) > 0
) {
    try {
        require_once __DIR__ . '/includes/expediente_salud_pdf_helper.php';
        $pdf = expediente_generar_pdf_memoria(
            $conn,
            (int) $invitacion['expediente_id']
        );
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . addslashes((string) $pdf['nombre']) . '"');
        header('Content-Length: ' . strlen((string) $pdf['contenido']));
        echo $pdf['contenido'];
        exit;
    } catch (Throwable $e) {
        $error = 'No fue posible preparar el PDF: ' . $e->getMessage();
    }
}

$configuracion = [];
$preguntas = [];
if ($invitacion && (string) $invitacion['estado'] === 'pendiente') {
    try {
        $configuracion = expediente_configuracion($conn);
        $preguntas = expediente_preguntas($conn, true);
        if ($preguntas === []) {
            throw new RuntimeException('El cuestionario todavía no tiene preguntas activas.');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$dependenciasCondicionales = cuestionario_salud_dependencias($preguntas);

$csrfKey = $invitacion ? 'cuestionario_salud_csrf_' . (int) $invitacion['id'] : '';
if ($csrfKey !== '' && empty($_SESSION[$csrfKey])) {
    $_SESSION[$csrfKey] = bin2hex(random_bytes(32));
}
$csrf = $csrfKey !== '' ? (string) $_SESSION[$csrfKey] : '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $invitacion
    && (string) $invitacion['estado'] === 'pendiente'
    && $error === ''
) {
    expediente_incrementar_intentos_invitacion($conn, (int) $invitacion['id']);

    try {
        $csrfPost = trim((string) ($_POST['csrf'] ?? ''));
        if ($csrf === '' || $csrfPost === '' || !hash_equals($csrf, $csrfPost)) {
            throw new RuntimeException('La sesión del formulario venció. Actualiza la página.');
        }

        if ((string) ($invitacion['cliente_estado'] ?? '') !== 'activo') {
            throw new RuntimeException('La cuenta del socio ya no se encuentra activa.');
        }

        $nombreFirmante = trim((string) ($_POST['nombre_firmante'] ?? ''));
        $parentescoFirmante = trim((string) ($_POST['parentesco_firmante'] ?? 'Socio'));
        $acepta = isset($_POST['acepta_responsabilidad']) ? 1 : 0;

        if ($nombreFirmante === '' || strlen($nombreFirmante) < 3) {
            throw new RuntimeException('Escribe el nombre completo de la persona que acepta.');
        }

        if (!in_array($parentescoFirmante, ['Socio', 'Madre/Padre/Tutor', 'Responsable legal', 'Otro'], true)) {
            $parentescoFirmante = 'Socio';
        }

        if ($acepta !== 1) {
            throw new RuntimeException('Debes aceptar el documento de responsabilidad para finalizar.');
        }

        $respuestas = [];
        $alertas = 0;

        foreach ($preguntas as $pregunta) {
            $preguntaId = (int) $pregunta['id'];
            $campo = 'pregunta_' . $preguntaId;
            $esCondicional = isset($dependenciasCondicionales[$preguntaId]);
            $condicionalActiva = cuestionario_salud_condicional_activa(
                $preguntaId,
                $dependenciasCondicionales,
                $_POST
            );
            $respuesta = $condicionalActiva
                ? trim((string) ($_POST[$campo] ?? ''))
                : '';

            if (
                !$esCondicional
                && (int) $pregunta['obligatoria'] === 1
                && $respuesta === ''
            ) {
                throw new RuntimeException(
                    'Falta responder: ' . (string) $pregunta['pregunta']
                );
            }

            if ($esCondicional && $condicionalActiva && $respuesta === '') {
                throw new RuntimeException(
                    'Escribe el detalle solicitado: ' . (string) $pregunta['pregunta']
                );
            }

            if (
                (string) $pregunta['tipo_respuesta'] === 'si_no'
                && $respuesta !== ''
                && !in_array($respuesta, ['0', '1'], true)
            ) {
                throw new RuntimeException('Una respuesta de Sí o No no es válida.');
            }

            if (
                (string) $pregunta['tipo_respuesta'] === 'seleccion'
                && $respuesta !== ''
                && !in_array($respuesta, (array) $pregunta['opciones'], true)
            ) {
                throw new RuntimeException('Una opción seleccionada dejó de estar disponible.');
            }

            $generaAlerta = expediente_respuesta_genera_alerta(
                $pregunta,
                $respuesta
            ) ? 1 : 0;
            $alertas += $generaAlerta;
            $respuestas[] = [
                'pregunta' => $pregunta,
                'respuesta' => $respuesta,
                'alerta' => $generaAlerta,
            ];
        }

        $clienteNombre = trim(
            (string) $invitacion['nombre'] . ' ' .
            (string) $invitacion['apellido']
        );
        $gimnasio = trim((string) ($invitacion['gimnasio_nombre'] ?? 'EGO')) ?: 'EGO';
        $fechaAplicacion = date('Y-m-d H:i:s');
        $vigenciaDias = max(1, (int) ($configuracion['vigencia_dias'] ?? 365));
        $vigenteHasta = date('Y-m-d', strtotime('+' . $vigenciaDias . ' days'));
        $documentoSnapshot = cuestionario_salud_documento(
            (string) $configuracion['documento_texto'],
            [
                'gimnasio' => $gimnasio,
                'socio' => $clienteNombre,
                'fecha' => date('d/m/Y'),
                'sucursal' => (string) $invitacion['sucursal_nombre'],
                'administrador' => (string) $invitacion['administrador_nombre'],
                'firmante' => $nombreFirmante,
                'parentesco' => $parentescoFirmante,
            ]
        );
        $estadoSeguimiento = $alertas > 0
            ? 'requiere_revision'
            : 'sin_observaciones';
        $observaciones = (string) $invitacion['modo'] === 'correo'
            ? 'Cuestionario respondido directamente por el socio mediante enlace seguro.'
            : 'Cuestionario completado en recepción durante el proceso de inscripción.';
        $firma = '';

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                "INSERT INTO expedientes_salud (
                    cliente_id,
                    sucursal_id,
                    aplicado_por,
                    cuestionario_nombre,
                    cuestionario_version,
                    introduccion_snapshot,
                    documento_titulo_snapshot,
                    documento_texto_snapshot,
                    total_alertas,
                    estado_seguimiento,
                    observaciones_admin,
                    acepta_responsabilidad,
                    nombre_firmante,
                    parentesco_firmante,
                    firma_data_url,
                    fecha_aplicacion,
                    vigente_hasta
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $clienteId = (int) $invitacion['cliente_id'];
            $sucursalId = (int) $invitacion['sucursal_id'];
            $aplicadoPor = (int) $invitacion['creado_por'];
            $nombreCuestionario = (string) $configuracion['nombre_cuestionario'];
            $version = (string) $configuracion['version'];
            $introduccion = (string) $configuracion['introduccion'];
            $documentoTitulo = (string) $configuracion['documento_titulo'];
            $stmt->bind_param(
                'iiisssssississsss',
                $clienteId,
                $sucursalId,
                $aplicadoPor,
                $nombreCuestionario,
                $version,
                $introduccion,
                $documentoTitulo,
                $documentoSnapshot,
                $alertas,
                $estadoSeguimiento,
                $observaciones,
                $acepta,
                $nombreFirmante,
                $parentescoFirmante,
                $firma,
                $fechaAplicacion,
                $vigenteHasta
            );
            $stmt->execute();
            $expedienteCreadoId = (int) $conn->insert_id;
            $stmt->close();

            $stmtRespuesta = $conn->prepare(
                "INSERT INTO expedientes_salud_respuestas (
                    expediente_id,
                    pregunta_id,
                    seccion_snapshot,
                    pregunta_snapshot,
                    tipo_respuesta_snapshot,
                    opciones_snapshot_json,
                    respuesta_texto,
                    genera_alerta,
                    orden_snapshot
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            foreach ($respuestas as $item) {
                $pregunta = $item['pregunta'];
                $preguntaId = (int) $pregunta['id'];
                $seccion = (string) $pregunta['seccion'];
                $preguntaTexto = (string) $pregunta['pregunta'];
                $tipo = (string) $pregunta['tipo_respuesta'];
                $opcionesJson = $pregunta['opciones'] !== []
                    ? json_encode($pregunta['opciones'], JSON_UNESCAPED_UNICODE)
                    : null;
                $respuestaTexto = (string) $item['respuesta'];
                $alerta = (int) $item['alerta'];
                $orden = (int) $pregunta['orden'];
                $stmtRespuesta->bind_param(
                    'iisssssii',
                    $expedienteCreadoId,
                    $preguntaId,
                    $seccion,
                    $preguntaTexto,
                    $tipo,
                    $opcionesJson,
                    $respuestaTexto,
                    $alerta,
                    $orden
                );
                $stmtRespuesta->execute();
            }
            $stmtRespuesta->close();

            $integridad = hash('sha256', json_encode([
                'expediente_id' => $expedienteCreadoId,
                'cliente_id' => $clienteId,
                'version' => $version,
                'documento' => $documentoSnapshot,
                'respuestas' => $respuestas,
                'aceptado_por' => $nombreFirmante,
                'fecha' => $fechaAplicacion,
            ], JSON_UNESCAPED_UNICODE));

            $stmt = $conn->prepare(
                "UPDATE expedientes_salud
                 SET hash_integridad = ?
                 WHERE id = ?"
            );
            $stmt->bind_param('si', $integridad, $expedienteCreadoId);
            $stmt->execute();
            $stmt->close();

            expediente_completar_invitacion(
                $conn,
                (int) $invitacion['id'],
                $expedienteCreadoId
            );

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        unset($_SESSION[$csrfKey]);
        $emailDestino = trim((string) (
            $invitacion['email_destino'] ?: $invitacion['cliente_email']
        ));
        $emailFinalDisponible = $emailDestino !== '' && filter_var($emailDestino, FILTER_VALIDATE_EMAIL);
        if ($emailFinalDisponible) {
            try {
                $correoFinalJob = correo_cola_encolar(
                    $conn,
                    'expediente_completado',
                    [
                        'expediente_id' => $expedienteCreadoId,
                        'email' => $emailDestino,
                        'nombre' => $clienteNombre,
                    ]
                );
                $correoFinalProgramado = true;
                correo_cola_disparar_async((string) $correoFinalJob['token']);
            } catch (Throwable $correoFinalError) {
                error_log('[Cuestionario salud cola correo] ' . $correoFinalError->getMessage());
            }
        }

        $completadoAhora = true;
        $invitacion['estado'] = 'completada';
        $invitacion['expediente_id'] = $expedienteCreadoId;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$nombreSocio = $invitacion
    ? trim((string) $invitacion['nombre'] . ' ' . (string) $invitacion['apellido'])
    : 'Socio';
$esAdministrativo = !empty($_SESSION['user_id']);
$correoTokensAsync = correo_cola_extraer_tokens_async();

$preguntasPorSeccion = [];
foreach ($preguntas as $pregunta) {
    $seccion = trim((string) ($pregunta['seccion'] ?? 'Información general'));
    if ($seccion === '') {
        $seccion = 'Información general';
    }
    if (!isset($preguntasPorSeccion[$seccion])) {
        $preguntasPorSeccion[$seccion] = [];
    }
    $preguntasPorSeccion[$seccion][] = $pregunta;
}

$pasoInicial = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '') {
    $pasoSolicitado = trim((string) ($_POST['form_step'] ?? 'questionnaire'));
    $errorEsPregunta = strpos($error, 'Falta responder:') === 0
        || strpos($error, 'Escribe el detalle solicitado:') === 0;
    $pasoInicial = $errorEsPregunta
        ? 0
        : ($pasoSolicitado === 'document' ? 1 : 0);
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Cuestionario de salud</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="css/cuestionario_salud_publico.css?v=<?php echo file_exists(__DIR__ . '/css/cuestionario_salud_publico.css') ? filemtime(__DIR__ . '/css/cuestionario_salud_publico.css') : time(); ?>">
</head>
<body>
<main class="public-health-shell">
    <header class="public-health-header">
        <div class="public-health-brand">
            <span class="public-health-logo">EGO</span>
            <div>
                <strong><?php echo cuestionario_salud_h($invitacion['gimnasio_nombre'] ?? 'EGO'); ?></strong>
                <small>Expediente de salud</small>
            </div>
        </div>
        <?php if ($invitacion): ?>
            <span class="public-health-private"><i class="fa-solid fa-lock"></i> Enlace privado</span>
        <?php endif; ?>
    </header>

    <?php if ($error !== ''): ?>
        <section class="public-health-state is-error">
            <span class="public-health-state-icon"><i class="fa-solid fa-circle-exclamation"></i></span>
            <h1>No fue posible continuar</h1>
            <p><?php echo cuestionario_salud_h($error); ?></p>
            <?php if ($esAdministrativo): ?>
                <a href="inscripciones.php" class="public-health-primary">Volver a inscripciones</a>
            <?php endif; ?>
        </section>
    <?php elseif ($invitacion && (string) $invitacion['estado'] === 'completada'): ?>
        <section class="public-health-state is-success">
            <span class="public-health-state-icon"><i class="fa-solid fa-check"></i></span>
            <h1>Cuestionario finalizado</h1>
            <p>Las respuestas de <strong><?php echo cuestionario_salud_h($nombreSocio); ?></strong> y la aceptación del documento quedaron guardadas correctamente.</p>
            <?php if ($completadoAhora): ?>
                <div class="public-health-mail-status <?php echo $correoFinalProgramado ? 'is-sent' : 'is-warning'; ?>">
                    <i class="fa-solid <?php echo $correoFinalProgramado ? 'fa-envelope-circle-check' : 'fa-envelope-open-text'; ?>"></i>
                    <span><?php echo $correoFinalProgramado
                        ? 'La copia PDF quedó preparada para enviarse al correo registrado.'
                        : (!$emailFinalDisponible
                            ? 'El expediente se guardó correctamente. No se programó correo porque el socio no tiene un email válido registrado.'
                            : 'El expediente se guardó, pero no fue posible agregar la copia PDF a la cola de correo.'); ?></span>
                </div>
            <?php endif; ?>
            <div class="public-health-state-actions">
                <a class="public-health-primary" href="cuestionario_salud.php?token=<?php echo rawurlencode($token); ?>&descargar=1">
                    <i class="fa-solid fa-file-arrow-down"></i> Descargar PDF
                </a>
                <?php if ($esAdministrativo): ?>
                    <a class="public-health-secondary" href="inscripciones.php">Volver a inscripciones</a>
                <?php endif; ?>
            </div>
        </section>
    <?php elseif ($invitacion && in_array((string) $invitacion['estado'], ['vencida', 'revocada'], true)): ?>
        <section class="public-health-state is-warning">
            <span class="public-health-state-icon"><i class="fa-solid fa-clock-rotate-left"></i></span>
            <h1>El enlace ya no está disponible</h1>
            <p>Solicita al gimnasio que genere una nueva invitación para responder el cuestionario.</p>
            <?php if ($esAdministrativo): ?>
                <a href="inscripciones.php" class="public-health-primary">Volver a inscripciones</a>
            <?php endif; ?>
        </section>
    <?php elseif ($invitacion): ?>
        <section class="public-health-intro">
            <span class="public-health-kicker"><i class="fa-solid fa-shield-heart"></i> Información confidencial</span>
            <h1><?php echo cuestionario_salud_h($configuracion['nombre_cuestionario'] ?? 'Cuestionario de salud'); ?></h1>
            <p>Hola <strong><?php echo cuestionario_salud_h($nombreSocio); ?></strong>. Responde con información verdadera. Este registro administrativo no sustituye una consulta o valoración médica.</p>
        </section>

        <div class="public-health-progress" aria-label="Progreso del cuestionario">
            <div class="public-health-progress-track"><span id="progressBar"></span></div>
            <span id="progressText">Cuestionario · paso 1 de 2</span>
        </div>

        <?php if ($error !== ''): ?>
            <div class="public-health-alert"><i class="fa-solid fa-circle-exclamation"></i> <?php echo cuestionario_salud_h($error); ?></div>
        <?php endif; ?>

        <form method="post" id="publicHealthForm" novalidate data-initial-step="<?php echo (int) $pasoInicial; ?>">
            <input type="hidden" name="token" value="<?php echo cuestionario_salud_h($token); ?>">
            <input type="hidden" name="csrf" value="<?php echo cuestionario_salud_h($csrf); ?>">
            <input type="hidden" name="form_step" id="formStep" value="<?php echo $pasoInicial === 1 ? 'document' : 'questionnaire'; ?>">

            <div class="public-health-steps" id="healthSteps">
                <section class="public-health-step public-health-questionnaire-step" data-step="0" <?php echo $pasoInicial === 0 ? '' : 'hidden'; ?>>
                    <div class="public-health-step-heading">
                        <div>
                            <span class="public-health-question-number">Paso 1 de 2</span>
                            <h2>Cuestionario de salud</h2>
                            <p>Responde todas las preguntas. Cuando una respuesta requiera más información, el campo de detalle aparecerá automáticamente.</p>
                        </div>
                        <span class="public-health-step-badge"><i class="fa-solid fa-list-check"></i> <?php echo count($preguntas); ?> preguntas</span>
                    </div>

                    <div class="public-health-sections">
                        <?php $numeroPregunta = 0; ?>
                        <?php foreach ($preguntasPorSeccion as $seccion => $preguntasSeccion): ?>
                            <section class="public-health-question-group">
                                <header class="public-health-group-header">
                                    <span class="public-health-group-icon"><i class="fa-solid fa-shield-heart"></i></span>
                                    <div>
                                        <h3><?php echo cuestionario_salud_h($seccion); ?></h3>
                                        <small><?php echo count($preguntasSeccion); ?> <?php echo count($preguntasSeccion) === 1 ? 'pregunta' : 'preguntas'; ?></small>
                                    </div>
                                </header>

                                <div class="public-health-question-list">
                                    <?php foreach ($preguntasSeccion as $pregunta): ?>
                                        <?php
                                        $numeroPregunta++;
                                        $id = (int) $pregunta['id'];
                                        $campo = 'pregunta_' . $id;
                                        $valor = (string) ($_POST[$campo] ?? '');
                                        $tipo = (string) $pregunta['tipo_respuesta'];
                                        $esCondicional = isset($dependenciasCondicionales[$id]);
                                        $condicionalActiva = cuestionario_salud_condicional_activa(
                                            $id,
                                            $dependenciasCondicionales,
                                            $_POST
                                        );
                                        $triggerIds = $esCondicional
                                            ? implode(',', array_map('intval', $dependenciasCondicionales[$id]))
                                            : '';
                                        ?>
                                        <article
                                            class="public-health-question-card<?php echo $esCondicional ? ' is-conditional' : ''; ?>"
                                            data-question-id="<?php echo $id; ?>"
                                            <?php if ($esCondicional): ?>
                                                data-conditional-question="1"
                                                data-trigger-ids="<?php echo cuestionario_salud_h($triggerIds); ?>"
                                                <?php echo $condicionalActiva ? '' : 'hidden'; ?>
                                            <?php endif; ?>
                                        >
                                            <div class="public-health-question-copy">
                                                <span class="public-health-question-index"><?php echo $numeroPregunta; ?></span>
                                                <div>
                                                    <?php if ($esCondicional): ?>
                                                        <span class="public-health-followup-label"><i class="fa-solid fa-pen-to-square"></i> Cuéntanos un poco más</span>
                                                    <?php endif; ?>
                                                    <h4><?php echo cuestionario_salud_h($pregunta['pregunta']); ?></h4>
                                                    <?php if (trim((string) $pregunta['ayuda']) !== ''): ?>
                                                        <p class="public-health-help"><i class="fa-solid fa-circle-info"></i> <?php echo cuestionario_salud_h($pregunta['ayuda']); ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <div class="public-health-answer">
                                                <?php if ($tipo === 'si_no'): ?>
                                                    <div class="public-health-choice-grid">
                                                        <label class="public-health-choice is-yes">
                                                            <input
                                                                type="radio"
                                                                name="<?php echo $campo; ?>"
                                                                value="1"
                                                                data-question-answer="<?php echo $id; ?>"
                                                                <?php echo $valor === '1' ? 'checked' : ''; ?>
                                                                <?php echo (int) $pregunta['obligatoria'] === 1 ? 'required' : ''; ?>
                                                            >
                                                            <span><i class="fa-solid fa-check"></i> Sí</span>
                                                        </label>
                                                        <label class="public-health-choice is-no">
                                                            <input
                                                                type="radio"
                                                                name="<?php echo $campo; ?>"
                                                                value="0"
                                                                data-question-answer="<?php echo $id; ?>"
                                                                <?php echo $valor === '0' ? 'checked' : ''; ?>
                                                                <?php echo (int) $pregunta['obligatoria'] === 1 ? 'required' : ''; ?>
                                                            >
                                                            <span><i class="fa-solid fa-xmark"></i> No</span>
                                                        </label>
                                                    </div>
                                                <?php elseif ($tipo === 'seleccion'): ?>
                                                    <select name="<?php echo $campo; ?>" <?php echo (int) $pregunta['obligatoria'] === 1 ? 'required' : ''; ?> <?php echo $esCondicional && !$condicionalActiva ? 'disabled' : ''; ?>>
                                                        <option value="">Selecciona una opción</option>
                                                        <?php foreach ((array) $pregunta['opciones'] as $opcion): ?>
                                                            <option value="<?php echo cuestionario_salud_h($opcion); ?>" <?php echo $valor === (string) $opcion ? 'selected' : ''; ?>><?php echo cuestionario_salud_h($opcion); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php elseif ($tipo === 'texto'): ?>
                                                    <textarea
                                                        name="<?php echo $campo; ?>"
                                                        rows="4"
                                                        placeholder="Escribe la información necesaria"
                                                        <?php echo $esCondicional ? 'data-required-when-visible="1"' : ''; ?>
                                                        <?php echo !$esCondicional && (int) $pregunta['obligatoria'] === 1 ? 'required' : ''; ?>
                                                        <?php echo $esCondicional && !$condicionalActiva ? 'disabled' : ''; ?>
                                                    ><?php echo cuestionario_salud_h($valor); ?></textarea>
                                                <?php else: ?>
                                                    <input
                                                        type="<?php echo $tipo === 'numero' ? 'number' : ($tipo === 'fecha' ? 'date' : 'text'); ?>"
                                                        name="<?php echo $campo; ?>"
                                                        value="<?php echo cuestionario_salud_h($valor); ?>"
                                                        <?php echo $esCondicional ? 'data-required-when-visible="1"' : ''; ?>
                                                        <?php echo !$esCondicional && (int) $pregunta['obligatoria'] === 1 ? 'required' : ''; ?>
                                                        <?php echo $esCondicional && !$condicionalActiva ? 'disabled' : ''; ?>
                                                    >
                                                <?php endif; ?>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="public-health-step public-health-acceptance" data-step="1" <?php echo $pasoInicial === 1 ? '' : 'hidden'; ?>>
                    <div class="public-health-step-heading">
                        <div>
                            <span class="public-health-question-number">Paso 2 de 2</span>
                            <h2><?php echo cuestionario_salud_h($configuracion['documento_titulo'] ?? 'Documento de responsabilidad'); ?></h2>
                            <p>Lee el documento completo. El expediente solamente podrá guardarse después de marcar la aceptación.</p>
                        </div>
                        <span class="public-health-step-badge is-final"><i class="fa-solid fa-file-signature"></i> Aceptación final</span>
                    </div>

                    <div class="public-health-document-heading">
                        <span><i class="fa-solid fa-file-shield"></i></span>
                        <div>
                            <strong>Documento para <?php echo cuestionario_salud_h($nombreSocio); ?></strong>
                            <small>Fecha de registro: <?php echo date('d/m/Y'); ?></small>
                        </div>
                    </div>

                    <div class="public-health-document" id="responsibilityDocument" tabindex="0">
                        <?php echo nl2br(cuestionario_salud_h(cuestionario_salud_documento(
                            (string) ($configuracion['documento_texto'] ?? ''),
                            [
                                'gimnasio' => (string) ($invitacion['gimnasio_nombre'] ?? 'EGO'),
                                'socio' => $nombreSocio,
                                'fecha' => date('d/m/Y'),
                                'sucursal' => (string) $invitacion['sucursal_nombre'],
                                'administrador' => (string) $invitacion['administrador_nombre'],
                                'firmante' => '[Tu nombre completo]',
                                'parentesco' => 'Socio',
                            ]
                        ))); ?>
                    </div>

                    <div class="public-health-signatory-grid">
                        <label>
                            <span>Nombre completo de quien acepta</span>
                            <input type="text" name="nombre_firmante" minlength="3" maxlength="180" value="<?php echo cuestionario_salud_h($_POST['nombre_firmante'] ?? $nombreSocio); ?>" required>
                        </label>
                        <label>
                            <span>Relación con el socio</span>
                            <?php $parentescoValor = (string) ($_POST['parentesco_firmante'] ?? 'Socio'); ?>
                            <select name="parentesco_firmante" required>
                                <?php foreach (['Socio', 'Madre/Padre/Tutor', 'Responsable legal', 'Otro'] as $opcion): ?>
                                    <option value="<?php echo cuestionario_salud_h($opcion); ?>" <?php echo $parentescoValor === $opcion ? 'selected' : ''; ?>><?php echo cuestionario_salud_h($opcion); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>

                    <label class="public-health-accept-check" id="acceptanceCard">
                        <input type="checkbox" name="acepta_responsabilidad" id="acceptResponsibility" value="1" <?php echo isset($_POST['acepta_responsabilidad']) ? 'checked' : ''; ?> required>
                        <span class="public-health-accept-box"><i class="fa-solid fa-check"></i></span>
                        <span>
                            <strong>Acepto el documento de responsabilidad</strong>
                            <small>Confirmo que leí el contenido y que las respuestas proporcionadas son verdaderas.</small>
                        </span>
                    </label>

                    <div class="public-health-submit-hint" id="submitHint">
                        <i class="fa-solid fa-lock"></i>
                        <span>Marca la aceptación para habilitar el botón de finalizar.</span>
                    </div>
                </section>
            </div>

            <div class="public-health-actions">
                <button type="button" class="public-health-secondary" id="prevStep" <?php echo $pasoInicial === 0 ? 'hidden' : ''; ?>><i class="fa-solid fa-arrow-left"></i> Volver al cuestionario</button>
                <button type="button" class="public-health-primary" id="nextStep" <?php echo $pasoInicial === 1 ? 'hidden' : ''; ?>>Siguiente: leer documento <i class="fa-solid fa-arrow-right"></i></button>
                <button type="submit" class="public-health-primary public-health-finish" id="submitHealth" <?php echo $pasoInicial === 0 ? 'hidden' : ''; ?> disabled><i class="fa-solid fa-shield-check"></i> Guardar y finalizar</button>
            </div>
        </form>
    <?php endif; ?>
</main>
<script>
(function () {
    const tokens = <?php echo json_encode(
        $correoTokensAsync,
        JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_APOS
        | JSON_HEX_AMP
        | JSON_HEX_QUOT
    ); ?>;

    function lanzarCorreo(token) {
        if (!/^[a-f0-9]{64}$/.test(String(token || ''))) return;
        const contenido = 'token=' + encodeURIComponent(token);
        const url = 'api/correo/procesar_token.php';

        if (navigator.sendBeacon) {
            const cuerpo = new Blob(
                [contenido],
                { type: 'application/x-www-form-urlencoded;charset=UTF-8' }
            );
            if (navigator.sendBeacon(url, cuerpo)) return;
        }

        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {
                'Content-Type':
                    'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: contenido
        }).catch(function (error) {
            console.error('El PDF quedó pendiente para reintento:', error);
        });
    }

    tokens.forEach(function (token, indice) {
        window.setTimeout(function () {
            lanzarCorreo(token);
        }, 180 + (indice * 120));
    });
})();
</script>
<script>
(function () {
    const form = document.getElementById('publicHealthForm');
    if (!form) return;

    const steps = Array.from(form.querySelectorAll('.public-health-step'));
    const prev = document.getElementById('prevStep');
    const next = document.getElementById('nextStep');
    const submit = document.getElementById('submitHealth');
    const accept = document.getElementById('acceptResponsibility');
    const acceptanceCard = document.getElementById('acceptanceCard');
    const submitHint = document.getElementById('submitHint');
    const formStep = document.getElementById('formStep');
    const bar = document.getElementById('progressBar');
    const text = document.getElementById('progressText');
    const initialStep = Number(form.getAttribute('data-initial-step') || 0);
    let current = initialStep === 1 ? 1 : 0;

    function controlsIn(element) {
        return Array.from(element.querySelectorAll('input, select, textarea'))
            .filter(function (control) {
                return !control.disabled;
            });
    }

    function focusInvalid(control) {
        const card = control.closest('.public-health-question-card, .public-health-acceptance');
        if (card) {
            card.classList.add('has-error');
            card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            window.setTimeout(function () {
                card.classList.remove('has-error');
            }, 1800);
        }
        control.focus({ preventScroll: true });
        control.reportValidity();
    }

    function validateCurrentStep() {
        const controls = controlsIn(steps[current]);
        for (const control of controls) {
            if (!control.checkValidity()) {
                focusInvalid(control);
                return false;
            }
        }
        return true;
    }

    function triggerAnsweredYes(triggerId) {
        const checked = form.querySelector(
            'input[name="pregunta_' + triggerId + '"]:checked'
        );
        return checked && checked.value === '1';
    }

    function updateConditionalQuestion(card) {
        const ids = String(card.getAttribute('data-trigger-ids') || '')
            .split(',')
            .map(function (value) { return Number(value); })
            .filter(function (value) { return value > 0; });

        const visible = ids.some(triggerAnsweredYes);
        card.hidden = !visible;
        card.classList.toggle('is-visible', visible);

        const controls = Array.from(card.querySelectorAll('input, select, textarea'));
        controls.forEach(function (control) {
            control.disabled = !visible;
            if (control.hasAttribute('data-required-when-visible')) {
                control.required = visible;
            }
        });
    }

    function updateAllConditionals() {
        form.querySelectorAll('[data-conditional-question="1"]').forEach(
            updateConditionalQuestion
        );
    }

    function updateSubmitAvailability() {
        const accepted = Boolean(accept && accept.checked);
        submit.disabled = !accepted;
        if (acceptanceCard) {
            acceptanceCard.classList.toggle('is-accepted', accepted);
        }
        if (submitHint) {
            submitHint.classList.toggle('is-ready', accepted);
            submitHint.innerHTML = accepted
                ? '<i class="fa-solid fa-circle-check"></i><span>Listo. Ya puedes guardar y finalizar el expediente.</span>'
                : '<i class="fa-solid fa-lock"></i><span>Marca la aceptación para habilitar el botón de finalizar.</span>';
        }
    }

    function render(scroll) {
        steps.forEach(function (step, index) {
            step.hidden = index !== current;
        });

        const finalStep = current === 1;
        prev.hidden = !finalStep;
        next.hidden = finalStep;
        submit.hidden = !finalStep;
        formStep.value = finalStep ? 'document' : 'questionnaire';
        bar.style.width = finalStep ? '100%' : '50%';
        text.textContent = finalStep
            ? 'Documento y aceptación · paso 2 de 2'
            : 'Cuestionario · paso 1 de 2';

        if (scroll !== false) {
            const target = document.querySelector('.public-health-progress');
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        updateSubmitAvailability();
    }

    form.addEventListener('change', function (event) {
        if (event.target.matches('input[type="radio"][data-question-answer]')) {
            updateAllConditionals();
        }
        if (event.target === accept) {
            updateSubmitAvailability();
        }
    });

    next.addEventListener('click', function () {
        updateAllConditionals();
        if (!validateCurrentStep()) return;
        current = 1;
        render(true);
    });

    prev.addEventListener('click', function () {
        current = 0;
        render(true);
    });

    form.addEventListener('submit', function (event) {
        updateAllConditionals();

        if (current !== 1) {
            event.preventDefault();
            current = 1;
            render(true);
            return;
        }

        if (!accept || !accept.checked) {
            event.preventDefault();
            updateSubmitAvailability();
            if (accept) focusInvalid(accept);
            return;
        }

        if (!validateCurrentStep() || !form.checkValidity()) {
            event.preventDefault();
            const invalid = form.querySelector(':invalid');
            if (invalid) focusInvalid(invalid);
            return;
        }

        submit.disabled = true;
        submit.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Guardando expediente...';
    });

    updateAllConditionals();
    render(false);
})();
</script>
</body>
</html>