<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/super_admin_helper.php';
require_once __DIR__ . '/includes/expediente_salud_helper.php';
require_once __DIR__ . '/includes/correo_cola.php';

$rolExpedienteSesion = rol_normalizar_sistema((string) (
    $_SESSION['user_rol'] ?? ''
));
$rolExpedienteBase = rol_base_real_sesion();

$esAdministradorExpediente =
    rol_es_administrativo($rolExpedienteSesion)
    || rol_es_administrativo($rolExpedienteBase);

$esRecepcionistaExpediente =
    $rolExpedienteSesion === 'recepcionista'
    || $rolExpedienteBase === 'recepcionista';

/*
 * auth_guard.php ya validó el permiso asignado por sucursal.
 * Esta segunda barrera evita que otros perfiles reutilicen la pantalla.
 */
if (!$esAdministradorExpediente && !$esRecepcionistaExpediente) {
    http_response_code(403);
    $_SESSION['alerta_acceso_denegado'] = [
        'titulo' => 'Acceso restringido',
        'mensaje' => 'Tu perfil no puede consultar expedientes de salud.',
        'rol' => (string) ($_SESSION['user_rol'] ?? 'Usuario'),
        'modulo' => 'Expediente de salud',
    ];
    expediente_redirigir('dashboard.php');
}

$database = new Database();
$conn = $database->getConnection();

if (!$conn instanceof mysqli) {
    throw new RuntimeException('No fue posible conectar con la base de datos.');
}

$conn->set_charset('utf8mb4');

/**
 * La versión es interna y se incrementa automáticamente cuando cambia
 * el cuestionario o el documento de responsabilidad.
 */
function expediente_siguiente_version_automatica(string $versionActual): string
{
    $versionActual = trim($versionActual);

    if (preg_match('/^\d+(?:\.\d+)*$/', $versionActual)) {
        $partes = array_map('intval', explode('.', $versionActual));
        $ultimoIndice = count($partes) - 1;
        $partes[$ultimoIndice] = max(0, $partes[$ultimoIndice]) + 1;

        return implode('.', $partes);
    }

    return '1.1';
}

function expediente_incrementar_version(mysqli $conn, int $usuarioId): string
{
    $resultado = $conn->query(
        "SELECT version FROM configuracion_expediente_salud WHERE id = 1 LIMIT 1"
    );

    $versionActual = '1.0';

    if ($resultado && $fila = $resultado->fetch_assoc()) {
        $versionActual = trim((string) ($fila['version'] ?? '1.0')) ?: '1.0';
    }

    $versionNueva = expediente_siguiente_version_automatica($versionActual);

    $stmt = $conn->prepare(
        "UPDATE configuracion_expediente_salud
         SET version = ?, actualizado_por = ?
         WHERE id = 1"
    );

    if (!$stmt) {
        throw new RuntimeException('No fue posible actualizar la versión interna del cuestionario.');
    }

    $stmt->bind_param('si', $versionNueva, $usuarioId);

    if (!$stmt->execute()) {
        $detalle = $stmt->error;
        $stmt->close();
        throw new RuntimeException('No fue posible actualizar la versión interna: ' . $detalle);
    }

    $stmt->close();

    return $versionNueva;
}

/**
 * Reenumera las preguntas de forma consecutiva: 1, 2, 3...
 * Cuando una pregunta cambia de posición, se prioriza dentro del mismo orden.
 */
function expediente_reordenar_preguntas(mysqli $conn, int $preguntaPrioritariaId = 0): void
{
    $stmt = $conn->prepare(
        "SELECT id
         FROM preguntas_expediente_salud
         ORDER BY
             orden ASC,
             CASE WHEN id = ? THEN 0 ELSE 1 END ASC,
             id ASC"
    );

    if (!$stmt) {
        throw new RuntimeException('No fue posible ordenar las preguntas.');
    }

    $stmt->bind_param('i', $preguntaPrioritariaId);

    if (!$stmt->execute()) {
        $detalle = $stmt->error;
        $stmt->close();
        throw new RuntimeException('No fue posible consultar el orden de las preguntas: ' . $detalle);
    }

    $resultado = $stmt->get_result();
    $ids = [];

    while ($resultado && $fila = $resultado->fetch_assoc()) {
        $ids[] = (int) $fila['id'];
    }

    $stmt->close();

    $stmtOrden = $conn->prepare(
        "UPDATE preguntas_expediente_salud SET orden = ? WHERE id = ?"
    );

    if (!$stmtOrden) {
        throw new RuntimeException('No fue posible guardar el nuevo orden de las preguntas.');
    }

    foreach ($ids as $indice => $id) {
        $orden = $indice + 1;
        $stmtOrden->bind_param('ii', $orden, $id);

        if (!$stmtOrden->execute()) {
            $detalle = $stmtOrden->error;
            $stmtOrden->close();
            throw new RuntimeException('No fue posible actualizar el orden de las preguntas: ' . $detalle);
        }
    }

    $stmtOrden->close();
}

function expediente_siguiente_orden(mysqli $conn): int
{
    $resultado = $conn->query(
        "SELECT COALESCE(MAX(orden), 0) + 1 AS siguiente
         FROM preguntas_expediente_salud"
    );

    if ($resultado && $fila = $resultado->fetch_assoc()) {
        return max(1, (int) ($fila['siguiente'] ?? 1));
    }

    return 1;
}

/**
 * Evita guardar dos veces el mismo documento cuando el texto fue pegado
 * accidentalmente de forma duplicada.
 */
function expediente_limpiar_repeticion_documento(string $texto): string
{
    $texto = trim((string) preg_replace("/\r\n?|\r/u", "\n", $texto));

    if ($texto === '') {
        return '';
    }

    $longitud = function_exists('mb_strlen')
        ? mb_strlen($texto, 'UTF-8')
        : strlen($texto);

    if ($longitud < 160) {
        return $texto;
    }

    $tamanoMuestra = min(90, max(45, (int) floor($longitud / 5)));
    $muestra = function_exists('mb_substr')
        ? mb_substr($texto, 0, $tamanoMuestra, 'UTF-8')
        : substr($texto, 0, $tamanoMuestra);

    $posicion = function_exists('mb_stripos')
        ? mb_stripos($texto, $muestra, $tamanoMuestra, 'UTF-8')
        : stripos($texto, $muestra, $tamanoMuestra);

    if ($posicion === false || (int) $posicion <= 0) {
        return $texto;
    }

    $primeraParte = function_exists('mb_substr')
        ? trim(mb_substr($texto, 0, (int) $posicion, 'UTF-8'))
        : trim(substr($texto, 0, (int) $posicion));

    $segundaParte = function_exists('mb_substr')
        ? trim(mb_substr($texto, (int) $posicion, null, 'UTF-8'))
        : trim(substr($texto, (int) $posicion));

    $normalizar = static function (string $valor): string {
        $valor = trim((string) preg_replace('/\s+/u', ' ', $valor));
        return function_exists('mb_strtolower')
            ? mb_strtolower($valor, 'UTF-8')
            : strtolower($valor);
    };

    if ($primeraParte !== '' && $normalizar($primeraParte) === $normalizar($segundaParte)) {
        return $primeraParte;
    }

    return $texto;
}

/**
 * Sustituye etiquetas fáciles de entender dentro de la carta. También
 * conserva compatibilidad con los marcadores antiguos {{SOCIO}}, etc.
 */
function expediente_parentesco_para_documento(string $parentesco): string
{
    $normalizado = trim($parentesco);
    $clave = function_exists('mb_strtolower')
        ? mb_strtolower($normalizado, 'UTF-8')
        : strtolower($normalizado);

    $mapa = [
        'socio' => 'socio',
        'es el socio' => 'socio',
        'madre/padre/tutor' => 'madre, padre o tutor',
        'madre, padre o tutor' => 'madre, padre o tutor',
        'responsable legal' => 'responsable legal',
        'otro' => 'otro responsable',
        'otro responsable' => 'otro responsable',
    ];

    return $mapa[$clave] ?? ($normalizado !== '' ? $normalizado : 'socio');
}

function expediente_documento_con_datos(string $texto, array $datos): string
{
    $texto = expediente_limpiar_repeticion_documento($texto);
    $textoProcesado = expediente_reemplazar_documento($texto, $datos);

    $reemplazos = [
        '[NOMBRE DEL GIMNASIO]' => trim((string) ($datos['gimnasio'] ?? 'Gimnasio')),
        '[GIMNASIO]' => trim((string) ($datos['gimnasio'] ?? 'Gimnasio')),
        '[NOMBRE DEL SOCIO]' => trim((string) ($datos['socio'] ?? 'Socio')),
        '[SOCIO]' => trim((string) ($datos['socio'] ?? 'Socio')),
        '[FECHA]' => trim((string) ($datos['fecha'] ?? date('d/m/Y'))),
        '[SUCURSAL]' => trim((string) ($datos['sucursal'] ?? 'Sucursal')),
        '[ADMINISTRADOR]' => trim((string) ($datos['administrador'] ?? 'Administrador')),
        '[PERSONA QUE ACEPTA]' => trim((string) ($datos['firmante'] ?? $datos['socio'] ?? 'Socio')),
        '[RELACIÓN CON EL SOCIO]' => expediente_parentesco_para_documento((string) ($datos['parentesco'] ?? 'Socio')),
    ];

    return trim(str_ireplace(
        array_keys($reemplazos),
        array_values($reemplazos),
        $textoProcesado
    ));
}

function expediente_documento_tiene_etiqueta(string $texto, array $etiquetas): bool
{
    foreach ($etiquetas as $etiqueta) {
        if (stripos($texto, $etiqueta) !== false) {
            return true;
        }
    }

    return false;
}


/**
 * Convierte los valores internos del cuestionario a una respuesta legible.
 * La base conserva 1 y 0 para mantener compatibilidad con las reglas de alerta,
 * pero la interfaz siempre muestra Sí y No.
 */
function expediente_respuesta_visible(array $respuesta): string
{
    $valor = trim((string) ($respuesta['respuesta_texto'] ?? ''));
    $tipo = trim((string) ($respuesta['tipo_respuesta_snapshot'] ?? ''));

    if ($valor === '') {
        return 'Sin respuesta';
    }

    if ($tipo === 'si_no') {
        $normalizado = function_exists('mb_strtolower')
            ? mb_strtolower($valor, 'UTF-8')
            : strtolower($valor);

        if (in_array($normalizado, ['1', 'si', 'sí', 'true'], true)) {
            return 'Sí';
        }

        if (in_array($normalizado, ['0', 'no', 'false'], true)) {
            return 'No';
        }
    }

    return $valor;
}

$usuarioId = (int) ($_SESSION['user_id'] ?? 0);
$usuarioNombre = trim((string) ($_SESSION['user_name'] ?? 'Administrador'));
$sucursalActual = (int) ($_SESSION['sucursal_id'] ?? 0);
$sucursalNombre = trim((string) ($_SESSION['sucursal_nombre'] ?? 'Sucursal'));
$vista = strtolower(trim((string) ($_GET['vista'] ?? 'sucursal')));

/*
 * Recepción solo consulta la sucursal activa. La vista global y la
 * configuración del cuestionario permanecen reservadas a administración.
 */
$vistaGlobal = $esAdministradorExpediente && $vista === 'global';
$tab = strtolower(trim((string) ($_GET['tab'] ?? 'expedientes')));

/* Compatibilidad con enlaces anteriores: el alta y la gestión ahora viven en un solo panel. */
if ($tab === 'nuevo') {
    $tab = 'expedientes';
}

if (!in_array($tab, ['expedientes', 'configuracion'], true)) {
    $tab = 'expedientes';
}

if (!$esAdministradorExpediente) {
    $tab = 'expedientes';
}

$csrf = expediente_csrf_token();
$mensaje = trim((string) ($_GET['mensaje'] ?? ''));
$tipoMensaje = strtolower(trim((string) ($_GET['tipo'] ?? 'success')));
$errorFormulario = '';

try {
    $configuracion = expediente_configuracion($conn);
    $preguntasActivas = expediente_preguntas($conn, true);
    $preguntasTodas = expediente_preguntas($conn, false);
} catch (Throwable $e) {
    $configuracion = [];
    $preguntasActivas = [];
    $preguntasTodas = [];
    $errorFormulario = $e->getMessage();
}

$gimnasioNombreSistema = 'Gimnasio';
$resultadoNombreGym = $conn->query(
    "SELECT nombre FROM configuracion_gimnasio WHERE id = 1 LIMIT 1"
);
if ($resultadoNombreGym && $filaNombreGym = $resultadoNombreGym->fetch_assoc()) {
    $gimnasioNombreSistema = trim((string) ($filaNombreGym['nombre'] ?? 'Gimnasio')) ?: 'Gimnasio';
}

/* Normaliza automáticamente los órdenes antiguos 10, 20, 30... a 1, 2, 3... */
if ($tab === 'configuracion' && $preguntasTodas !== []) {
    try {
        expediente_reordenar_preguntas($conn);
        $preguntasActivas = expediente_preguntas($conn, true);
        $preguntasTodas = expediente_preguntas($conn, false);
    } catch (Throwable $e) {
        if ($errorFormulario === '') {
            $errorFormulario = $e->getMessage();
        }
    }
}

$siguienteOrdenPregunta = max(1, count($preguntasTodas) + 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = trim((string) ($_POST['accion'] ?? ''));
    $token = trim((string) ($_POST['csrf'] ?? ''));

    if (!expediente_validar_csrf($token)) {
        $errorFormulario = 'La sesión del formulario venció. Recarga la página e inténtalo nuevamente.';
    } else {
        try {
            /*
             * Recepción puede consultar el expediente y registrar únicamente
             * su seguimiento administrativo. No puede crear, corregir ni
             * reconfigurar cuestionarios, aunque manipule el formulario.
             */
            if (
                !$esAdministradorExpediente
                && $accion !== 'resolver_revision_expediente'
            ) {
                throw new RuntimeException(
                    'Tu perfil puede consultar expedientes y registrar seguimientos, pero no modificar el cuestionario ni sus respuestas.'
                );
            }

            if ($accion === 'guardar_configuracion') {
                $nombreCuestionario = trim((string) ($_POST['nombre_cuestionario'] ?? ''));
                $introduccion = trim((string) ($_POST['introduccion'] ?? ''));
                $vigenciaDias = max(1, min(3650, (int) ($_POST['vigencia_dias'] ?? 365)));
                $documentoTitulo = trim((string) ($_POST['documento_titulo'] ?? ''));
                $documentoTexto = expediente_limpiar_repeticion_documento(
                    trim((string) ($_POST['documento_texto'] ?? ''))
                );

                if ($nombreCuestionario === '' || $documentoTitulo === '' || $documentoTexto === '') {
                    throw new RuntimeException('Completa el nombre del cuestionario, el título y el contenido del documento de responsabilidad.');
                }

                if (strlen($documentoTexto) < 120) {
                    throw new RuntimeException('El documento de responsabilidad es demasiado corto. Agrega las condiciones completas que deberá aceptar el socio.');
                }

                if (!expediente_documento_tiene_etiqueta($documentoTexto, ['[NOMBRE DEL SOCIO]', '[SOCIO]', '{{SOCIO}}'])) {
                    throw new RuntimeException('Agrega el nombre del socio dentro de la carta. Coloca el cursor en el texto y presiona el botón “Nombre del socio”.');
                }

                if (!expediente_documento_tiene_etiqueta($documentoTexto, ['[FECHA]', '{{FECHA}}'])) {
                    throw new RuntimeException('Agrega la fecha dentro de la carta. Coloca el cursor en el texto y presiona el botón “Fecha”.');
                }

                $versionActual = trim((string) ($configuracion['version'] ?? '1.0')) ?: '1.0';
                $cambioContenido =
                    $nombreCuestionario !== trim((string) ($configuracion['nombre_cuestionario'] ?? ''))
                    || $introduccion !== trim((string) ($configuracion['introduccion'] ?? ''))
                    || $vigenciaDias !== (int) ($configuracion['vigencia_dias'] ?? 365)
                    || $documentoTitulo !== trim((string) ($configuracion['documento_titulo'] ?? ''))
                    || $documentoTexto !== trim((string) ($configuracion['documento_texto'] ?? ''));

                $versionNueva = $cambioContenido
                    ? expediente_siguiente_version_automatica($versionActual)
                    : $versionActual;

                $requerirFirma = 0;

                $sql = "
                    UPDATE configuracion_expediente_salud
                    SET
                        nombre_cuestionario = ?,
                        introduccion = ?,
                        version = ?,
                        vigencia_dias = ?,
                        requerir_firma = ?,
                        documento_titulo = ?,
                        documento_texto = ?,
                        actualizado_por = ?
                    WHERE id = 1
                ";
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new RuntimeException('No fue posible preparar la actualización de la configuración.');
                }
                $stmt->bind_param(
                    'sssiissi',
                    $nombreCuestionario,
                    $introduccion,
                    $versionNueva,
                    $vigenciaDias,
                    $requerirFirma,
                    $documentoTitulo,
                    $documentoTexto,
                    $usuarioId
                );

                if (!$stmt->execute()) {
                    $detalle = $stmt->error;
                    $stmt->close();
                    throw new RuntimeException('No fue posible guardar la configuración: ' . $detalle);
                }

                $stmt->close();

                expediente_redirigir('expediente_salud.php?tab=configuracion&vista=' . ($vistaGlobal ? 'global' : 'sucursal') . '&tipo=success&mensaje=' . rawurlencode('Cuestionario y documento de responsabilidad actualizados.'));
            }

            if ($accion === 'guardar_pregunta') {
                $preguntaId = (int) ($_POST['pregunta_id'] ?? 0);
                $seccion = trim((string) ($_POST['seccion'] ?? 'Antecedentes generales'));
                $preguntaTexto = trim((string) ($_POST['pregunta'] ?? ''));
                $tipoRespuesta = trim((string) ($_POST['tipo_respuesta'] ?? 'si_no'));
                $opcionesTexto = trim((string) ($_POST['opciones'] ?? ''));
                $obligatoria = isset($_POST['obligatoria']) ? 1 : 0;
                $disparaAlerta = trim((string) ($_POST['dispara_alerta'] ?? 'ninguna'));
                $ayuda = trim((string) ($_POST['ayuda'] ?? ''));
                $orden = max(1, min(9999, (int) ($_POST['orden'] ?? expediente_siguiente_orden($conn))));
                $estado = trim((string) ($_POST['estado'] ?? 'activa'));

                $tiposPermitidos = ['si_no', 'texto', 'numero', 'fecha', 'seleccion'];
                $alertasPermitidas = ['si', 'no', 'cualquier_respuesta', 'ninguna'];
                $estadosPermitidos = ['activa', 'inactiva'];

                if ($preguntaTexto === '') {
                    throw new RuntimeException('Escribe la pregunta que se mostrará al administrador.');
                }
                if (!in_array($tipoRespuesta, $tiposPermitidos, true)) {
                    throw new RuntimeException('El tipo de respuesta no es válido.');
                }
                if (!in_array($disparaAlerta, $alertasPermitidas, true)) {
                    $disparaAlerta = 'ninguna';
                }
                if (!in_array($estado, $estadosPermitidos, true)) {
                    $estado = 'activa';
                }

                $opciones = expediente_opciones_desde_texto($opcionesTexto);
                if ($tipoRespuesta === 'seleccion' && count($opciones) < 2) {
                    throw new RuntimeException('Las preguntas de selección necesitan por lo menos dos opciones.');
                }
                $opcionesJson = $opciones !== [] ? json_encode($opciones, JSON_UNESCAPED_UNICODE) : null;

                $conn->begin_transaction();

                try {
                    if ($preguntaId > 0) {
                    $sql = "
                        UPDATE preguntas_expediente_salud
                        SET
                            seccion = ?,
                            pregunta = ?,
                            tipo_respuesta = ?,
                            opciones_json = ?,
                            obligatoria = ?,
                            dispara_alerta = ?,
                            ayuda = ?,
                            orden = ?,
                            estado = ?,
                            actualizado_por = ?
                        WHERE id = ?
                    ";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        throw new RuntimeException('No fue posible preparar la actualización de la pregunta.');
                    }
                    $stmt->bind_param(
                        'ssssissisii',
                        $seccion,
                        $preguntaTexto,
                        $tipoRespuesta,
                        $opcionesJson,
                        $obligatoria,
                        $disparaAlerta,
                        $ayuda,
                        $orden,
                        $estado,
                        $usuarioId,
                        $preguntaId
                    );
                    if (!$stmt->execute()) {
                        $detalle = $stmt->error;
                        $stmt->close();
                        throw new RuntimeException('No fue posible actualizar la pregunta: ' . $detalle);
                    }
                    $stmt->close();
                    $textoExito = 'Pregunta actualizada correctamente.';
                    } else {
                    $sql = "
                        INSERT INTO preguntas_expediente_salud (
                            seccion,
                            pregunta,
                            tipo_respuesta,
                            opciones_json,
                            obligatoria,
                            dispara_alerta,
                            ayuda,
                            orden,
                            estado,
                            creado_por,
                            actualizado_por
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        throw new RuntimeException('No fue posible preparar el registro de la pregunta.');
                    }
                    $stmt->bind_param(
                        'ssssissisii',
                        $seccion,
                        $preguntaTexto,
                        $tipoRespuesta,
                        $opcionesJson,
                        $obligatoria,
                        $disparaAlerta,
                        $ayuda,
                        $orden,
                        $estado,
                        $usuarioId,
                        $usuarioId
                    );
                    if (!$stmt->execute()) {
                        $detalle = $stmt->error;
                        $stmt->close();
                        throw new RuntimeException('No fue posible agregar la pregunta: ' . $detalle);
                    }
                    $preguntaId = (int) $conn->insert_id;
                    $stmt->close();
                    $textoExito = 'Pregunta agregada al cuestionario.';
                    }

                    expediente_reordenar_preguntas($conn, $preguntaId);
                    expediente_incrementar_version($conn, $usuarioId);
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }

                expediente_redirigir('expediente_salud.php?tab=configuracion&vista=' . ($vistaGlobal ? 'global' : 'sucursal') . '&tipo=success&mensaje=' . rawurlencode($textoExito . ' El orden quedó actualizado automáticamente.') . '#preguntasGuardadas');
            }

            if ($accion === 'cambiar_estado_pregunta') {
                $preguntaId = (int) ($_POST['pregunta_id'] ?? 0);
                $nuevoEstado = trim((string) ($_POST['nuevo_estado'] ?? 'inactiva'));

                if ($preguntaId <= 0 || !in_array($nuevoEstado, ['activa', 'inactiva'], true)) {
                    throw new RuntimeException('No fue posible identificar la pregunta que deseas cambiar.');
                }

                $stmt = $conn->prepare("UPDATE preguntas_expediente_salud SET estado = ?, actualizado_por = ? WHERE id = ?");
                if (!$stmt) {
                    throw new RuntimeException('No fue posible preparar el cambio de estado.');
                }
                $conn->begin_transaction();

                try {
                    $stmt->bind_param('sii', $nuevoEstado, $usuarioId, $preguntaId);

                    if (!$stmt->execute()) {
                        $detalle = $stmt->error;
                        $stmt->close();
                        throw new RuntimeException('No fue posible cambiar el estado de la pregunta: ' . $detalle);
                    }

                    $stmt->close();
                    expediente_reordenar_preguntas($conn, $preguntaId);
                    expediente_incrementar_version($conn, $usuarioId);
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }

                expediente_redirigir('expediente_salud.php?tab=configuracion&vista=' . ($vistaGlobal ? 'global' : 'sucursal') . '&tipo=success&mensaje=' . rawurlencode('Estado de la pregunta actualizado.') . '#preguntasGuardadas');
            }

            if ($accion === 'eliminar_pregunta') {
                $preguntaId = (int) ($_POST['pregunta_id'] ?? 0);

                if ($preguntaId <= 0) {
                    throw new RuntimeException('No fue posible identificar la pregunta que deseas eliminar.');
                }

                $conn->begin_transaction();

                try {
                    $stmt = $conn->prepare("DELETE FROM preguntas_expediente_salud WHERE id = ? LIMIT 1");
                    if (!$stmt) {
                        throw new RuntimeException('No fue posible preparar la eliminación de la pregunta.');
                    }

                    $stmt->bind_param('i', $preguntaId);

                    if (!$stmt->execute()) {
                        $detalle = $stmt->error;
                        $stmt->close();
                        throw new RuntimeException('No fue posible eliminar la pregunta: ' . $detalle);
                    }

                    $stmt->close();
                    expediente_reordenar_preguntas($conn);
                    expediente_incrementar_version($conn, $usuarioId);
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }

                expediente_redirigir('expediente_salud.php?tab=configuracion&vista=' . ($vistaGlobal ? 'global' : 'sucursal') . '&tipo=success&mensaje=' . rawurlencode('Pregunta eliminada correctamente.') . '#preguntasGuardadas');
            }

            if ($accion === 'resolver_revision_expediente') {
                $expedienteIdRevision = (int) ($_POST['expediente_id'] ?? 0);
                $decisionRevision = trim((string) ($_POST['decision_revision'] ?? ''));
                $comentarioRevision = trim((string) ($_POST['observaciones_revision'] ?? ''));

                $decisionesPermitidas = [
                    'aprobar' => 'sin_observaciones',
                    'solicitar_documentacion' => 'documentacion_pendiente',
                    'rechazar_correccion' => 'rechazado_correccion',
                ];

                if ($expedienteIdRevision <= 0 || !isset($decisionesPermitidas[$decisionRevision])) {
                    throw new RuntimeException('No fue posible identificar la decisión administrativa.');
                }

                if (
                    in_array($decisionRevision, ['solicitar_documentacion', 'rechazar_correccion'], true)
                    && $comentarioRevision === ''
                ) {
                    throw new RuntimeException('Escribe la indicación que recibirá el socio.');
                }

                $stmt = $conn->prepare(
                    "SELECT
                        e.observaciones_admin,
                        e.estado_seguimiento,
                        e.cliente_id,
                        c.nombre,
                        c.apellido,
                        c.email,
                        s.nombre AS sucursal_nombre,
                        (
                            SELECT e2.id
                            FROM expedientes_salud e2
                            WHERE e2.cliente_id = e.cliente_id
                            ORDER BY e2.fecha_aplicacion DESC, e2.id DESC
                            LIMIT 1
                        ) AS expediente_actual_id
                     FROM expedientes_salud e
                     INNER JOIN clientes c ON c.id = e.cliente_id
                     INNER JOIN sucursales s ON s.id = e.sucursal_id
                     WHERE e.id = ?
                       AND (? = 1 OR e.sucursal_id = ?)
                     LIMIT 1"
                );

                if (!$stmt) {
                    throw new RuntimeException('No fue posible consultar el expediente que deseas revisar.');
                }

                $banderaAdministradorExpediente =
                    $esAdministradorExpediente ? 1 : 0;

                $stmt->bind_param(
                    'iii',
                    $expedienteIdRevision,
                    $banderaAdministradorExpediente,
                    $sucursalActual
                );
                $stmt->execute();
                $resultadoRevision = $stmt->get_result();
                $expedienteRevision = $resultadoRevision
                    ? $resultadoRevision->fetch_assoc()
                    : null;
                $stmt->close();

                if (!$expedienteRevision) {
                    throw new RuntimeException('El expediente seleccionado ya no existe.');
                }

                if ((int) ($expedienteRevision['expediente_actual_id'] ?? 0) !== $expedienteIdRevision) {
                    throw new RuntimeException('Las versiones históricas son de solo lectura. Abre el expediente más reciente para registrar una decisión.');
                }

                if ((string) ($expedienteRevision['estado_seguimiento'] ?? '') === 'rechazado_correccion') {
                    throw new RuntimeException('Este expediente ya está rechazado para corrección. Debes registrar la corrección antes de tomar otra decisión.');
                }

                $estadoNuevo = $decisionesPermitidas[$decisionRevision];
                $etiquetaDecision = [
                    'aprobar' => 'Expediente revisado y aprobado',
                    'solicitar_documentacion' => 'Se solicitó documentación adicional',
                    'rechazar_correccion' => 'Expediente rechazado para corrección',
                ][$decisionRevision];

                $registroRevision = '[' . date('d/m/Y H:i') . '] '
                    . $usuarioNombre . ': '
                    . $etiquetaDecision
                    . ($comentarioRevision !== '' ? '. ' . $comentarioRevision : '.');

                $observacionesAnteriores = trim((string) ($expedienteRevision['observaciones_admin'] ?? ''));
                $observacionesNuevas = $observacionesAnteriores !== ''
                    ? $observacionesAnteriores . "\n" . $registroRevision
                    : $registroRevision;

                $stmt = $conn->prepare(
                    "UPDATE expedientes_salud
                     SET estado_seguimiento = ?,
                         observaciones_admin = ?
                     WHERE id = ?"
                );

                if (!$stmt) {
                    throw new RuntimeException('No fue posible preparar la actualización del expediente.');
                }

                $stmt->bind_param('ssi', $estadoNuevo, $observacionesNuevas, $expedienteIdRevision);
                if (!$stmt->execute()) {
                    $detalle = $stmt->error;
                    $stmt->close();
                    throw new RuntimeException('No fue posible guardar la decisión: ' . $detalle);
                }
                $stmt->close();

                $emailSocioRevision = trim((string) ($expedienteRevision['email'] ?? ''));
                $nombreSocioRevision = trim(
                    (string) ($expedienteRevision['nombre'] ?? '') . ' ' .
                    (string) ($expedienteRevision['apellido'] ?? '')
                );
                $correoRevisionDetalle = '';

                if ($emailSocioRevision !== '' && filter_var($emailSocioRevision, FILTER_VALIDATE_EMAIL)) {
                    try {
                        $trabajoCorreoRevision = correo_cola_encolar(
                            $conn,
                            'expediente_revision',
                            [
                                'expediente_id' => $expedienteIdRevision,
                                'email' => $emailSocioRevision,
                                'nombre' => $nombreSocioRevision,
                                'decision' => $decisionRevision,
                                'estado' => $estadoNuevo,
                                'comentario' => $comentarioRevision,
                                'sucursal' => (string) ($expedienteRevision['sucursal_nombre'] ?? ''),
                                'administrador' => $usuarioNombre,
                                'fecha_revision' => date('Y-m-d H:i:s'),
                            ]
                        );
                        correo_cola_disparar_async((string) $trabajoCorreoRevision['token']);
                        $correoRevisionDetalle = ' La notificación quedó preparada para enviarse a ' . $emailSocioRevision . '.';
                    } catch (Throwable $correoRevisionError) {
                        error_log('[Expediente revisión correo] ' . $correoRevisionError->getMessage());
                        $correoRevisionDetalle = ' La decisión se guardó, pero la notificación por correo quedó pendiente.';
                    }
                } else {
                    $correoRevisionDetalle = ' La decisión se guardó, pero el socio no tiene un correo válido.';
                }

                expediente_redirigir(
                    'expediente_salud.php?tab=expedientes&ver='
                    . $expedienteIdRevision
                    . '&cliente_id='
                    . (int) ($expedienteRevision['cliente_id'] ?? 0)
                    . '&vista='
                    . ($vistaGlobal ? 'global' : 'sucursal')
                    . '&tipo=success&mensaje='
                    . rawurlencode($etiquetaDecision . '.' . $correoRevisionDetalle)
                    . '#panelSocio'
                );
            }

            if ($accion === 'guardar_expediente') {
                $clienteId = (int) ($_POST['cliente_id'] ?? 0);
                $aceptaResponsabilidad = isset($_POST['acepta_responsabilidad']) ? 1 : 0;
                $nombreFirmante = trim((string) ($_POST['nombre_firmante'] ?? ''));
                $parentescoFirmante = trim((string) ($_POST['parentesco_firmante'] ?? 'Socio'));
                $firmaDataUrl = null;

                if ($clienteId <= 0) {
                    throw new RuntimeException('Selecciona al socio antes de guardar el expediente.');
                }

                $cliente = expediente_cliente($conn, $clienteId);
                if (!$cliente || (string) ($cliente['estado'] ?? '') !== 'activo') {
                    throw new RuntimeException('El socio seleccionado no existe o se encuentra inactivo.');
                }

                $stmtActual = $conn->prepare(
                    "SELECT id, estado_seguimiento, observaciones_admin
                     FROM expedientes_salud
                     WHERE cliente_id = ?
                     ORDER BY fecha_aplicacion DESC, id DESC
                     LIMIT 1"
                );
                if (!$stmtActual) {
                    throw new RuntimeException('No fue posible verificar el expediente actual.');
                }
                $stmtActual->bind_param('i', $clienteId);
                $stmtActual->execute();
                $resultadoActual = $stmtActual->get_result();
                $expedienteAnterior = $resultadoActual ? $resultadoActual->fetch_assoc() : null;
                $stmtActual->close();

                $expedienteAnteriorId = (int) ($expedienteAnterior['id'] ?? 0);
                $esCorreccion = $expedienteAnteriorId > 0;

                if (
                    $esCorreccion
                    && (string) ($expedienteAnterior['estado_seguimiento'] ?? '') !== 'rechazado_correccion'
                ) {
                    throw new RuntimeException(
                        'El expediente está bloqueado. Para modificar respuestas primero debes usar “Rechazar para corrección” desde la revisión administrativa.'
                    );
                }

                if (!$aceptaResponsabilidad || $nombreFirmante === '') {
                    throw new RuntimeException('Confirma la aceptación del documento y escribe el nombre de la persona que lo aceptó.');
                }

                if ($preguntasActivas === []) {
                    throw new RuntimeException('No existen preguntas activas. Configura el cuestionario antes de aplicarlo.');
                }

                $respuestas = [];
                $alertas = 0;

                foreach ($preguntasActivas as $pregunta) {
                    $preguntaId = (int) $pregunta['id'];
                    $campo = 'pregunta_' . $preguntaId;
                    $respuesta = isset($_POST[$campo]) ? trim((string) $_POST[$campo]) : '';

                    if ((int) $pregunta['obligatoria'] === 1 && $respuesta === '') {
                        throw new RuntimeException('Falta responder: ' . (string) $pregunta['pregunta']);
                    }

                    if ($pregunta['tipo_respuesta'] === 'si_no' && $respuesta !== '' && !in_array($respuesta, ['0', '1'], true)) {
                        throw new RuntimeException('Una respuesta de Sí/No contiene un valor inválido.');
                    }

                    if ($pregunta['tipo_respuesta'] === 'seleccion' && $respuesta !== '' && !in_array($respuesta, $pregunta['opciones'], true)) {
                        throw new RuntimeException('La respuesta seleccionada ya no pertenece a las opciones vigentes.');
                    }

                    $generaAlerta = expediente_respuesta_genera_alerta($pregunta, $respuesta) ? 1 : 0;
                    $alertas += $generaAlerta;
                    $respuestas[] = [
                        'pregunta' => $pregunta,
                        'respuesta' => $respuesta,
                        'alerta' => $generaAlerta,
                    ];
                }

                // El estado se calcula; ya no puede elegirse desde el formulario.
                $estadoSeguimiento = $alertas > 0 ? 'requiere_revision' : 'sin_observaciones';
                $registroCreacion = '[' . date('d/m/Y H:i') . '] ' . $usuarioNombre . ': '
                    . ($esCorreccion
                        ? 'Se registró una versión corregida del expediente #' . $expedienteAnteriorId . '.'
                        : 'Se creó el expediente de salud.');
                $observacionesAdmin = $registroCreacion;

                $gimnasioNombre = $gimnasioNombreSistema;
                $sucursalAplicacionId = $sucursalActual > 0
                    ? $sucursalActual
                    : (int) $cliente['sucursal_registro_id'];
                $sucursalAplicacionNombre = $sucursalNombre;

                if ($sucursalAplicacionNombre === '' || $vistaGlobal) {
                    $stmtSucursal = $conn->prepare("SELECT nombre FROM sucursales WHERE id = ? LIMIT 1");
                    if ($stmtSucursal) {
                        $stmtSucursal->bind_param('i', $sucursalAplicacionId);
                        $stmtSucursal->execute();
                        $resultadoSucursal = $stmtSucursal->get_result();
                        if ($resultadoSucursal && $filaSucursal = $resultadoSucursal->fetch_assoc()) {
                            $sucursalAplicacionNombre = (string) $filaSucursal['nombre'];
                        }
                        $stmtSucursal->close();
                    }
                }

                $fechaAplicacion = date('Y-m-d H:i:s');
                $vigenciaDias = max(1, (int) ($configuracion['vigencia_dias'] ?? 365));
                $vigenteHasta = date('Y-m-d', strtotime('+' . $vigenciaDias . ' days'));
                $documentoSnapshot = expediente_documento_con_datos(
                    (string) $configuracion['documento_texto'],
                    [
                        'gimnasio' => $gimnasioNombre,
                        'socio' => expediente_nombre_cliente($cliente),
                        'fecha' => date('d/m/Y'),
                        'sucursal' => $sucursalAplicacionNombre,
                        'administrador' => $usuarioNombre,
                        'firmante' => $nombreFirmante,
                        'parentesco' => $parentescoFirmante,
                    ]
                );

                $conn->begin_transaction();

                try {
                    $sql = "
                        INSERT INTO expedientes_salud (
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
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) {
                        throw new RuntimeException('No fue posible preparar el expediente del socio.');
                    }

                    $cuestionarioNombre = (string) $configuracion['nombre_cuestionario'];
                    $cuestionarioVersion = (string) $configuracion['version'];
                    $introduccionSnapshot = (string) $configuracion['introduccion'];
                    $documentoTitulo = (string) $configuracion['documento_titulo'];

                    $stmt->bind_param(
                        'iiisssssississsss',
                        $clienteId,
                        $sucursalAplicacionId,
                        $usuarioId,
                        $cuestionarioNombre,
                        $cuestionarioVersion,
                        $introduccionSnapshot,
                        $documentoTitulo,
                        $documentoSnapshot,
                        $alertas,
                        $estadoSeguimiento,
                        $observacionesAdmin,
                        $aceptaResponsabilidad,
                        $nombreFirmante,
                        $parentescoFirmante,
                        $firmaDataUrl,
                        $fechaAplicacion,
                        $vigenteHasta
                    );
                    $stmt->execute();
                    $expedienteId = (int) $conn->insert_id;
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
                    if (!$stmtRespuesta) {
                        throw new RuntimeException('No fue posible preparar las respuestas del cuestionario.');
                    }

                    foreach ($respuestas as $item) {
                        $pregunta = $item['pregunta'];
                        $preguntaId = (int) $pregunta['id'];
                        $seccionSnapshot = (string) $pregunta['seccion'];
                        $preguntaSnapshot = (string) $pregunta['pregunta'];
                        $tipoSnapshot = (string) $pregunta['tipo_respuesta'];
                        $opcionesSnapshot = $pregunta['opciones'] !== []
                            ? json_encode($pregunta['opciones'], JSON_UNESCAPED_UNICODE)
                            : null;
                        $respuestaTexto = (string) $item['respuesta'];
                        $generaAlerta = (int) $item['alerta'];
                        $ordenSnapshot = (int) $pregunta['orden'];

                        $stmtRespuesta->bind_param(
                            'iisssssii',
                            $expedienteId,
                            $preguntaId,
                            $seccionSnapshot,
                            $preguntaSnapshot,
                            $tipoSnapshot,
                            $opcionesSnapshot,
                            $respuestaTexto,
                            $generaAlerta,
                            $ordenSnapshot
                        );
                        $stmtRespuesta->execute();
                    }
                    $stmtRespuesta->close();

                    $integridad = hash('sha256', json_encode([
                        'expediente_id' => $expedienteId,
                        'cliente_id' => $clienteId,
                        'version' => $cuestionarioVersion,
                        'documento' => $documentoSnapshot,
                        'respuestas' => $respuestas,
                        'aceptado_por' => $nombreFirmante,
                        'fecha' => $fechaAplicacion,
                    ], JSON_UNESCAPED_UNICODE));

                    $stmtHash = $conn->prepare("UPDATE expedientes_salud SET hash_integridad = ? WHERE id = ?");
                    if (!$stmtHash) {
                        throw new RuntimeException('No fue posible completar la huella de integridad del expediente.');
                    }
                    $stmtHash->bind_param('si', $integridad, $expedienteId);
                    $stmtHash->execute();
                    $stmtHash->close();

                    if ($esCorreccion) {
                        $registroCierre = '[' . date('d/m/Y H:i') . '] ' . $usuarioNombre
                            . ': La corrección fue registrada como expediente #' . $expedienteId . '.';
                        $stmtAnterior = $conn->prepare(
                            "UPDATE expedientes_salud
                             SET observaciones_admin = CONCAT(
                                 COALESCE(observaciones_admin, ''),
                                 CASE WHEN COALESCE(observaciones_admin, '') = '' THEN '' ELSE '\n' END,
                                 ?
                             )
                             WHERE id = ?"
                        );
                        if ($stmtAnterior) {
                            $stmtAnterior->bind_param('si', $registroCierre, $expedienteAnteriorId);
                            $stmtAnterior->execute();
                            $stmtAnterior->close();
                        }
                    }

                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }

                expediente_redirigir(
                    'expediente_salud.php?tab=expedientes&cliente_id=' . $clienteId
                    . '&ver=' . $expedienteId
                    . '&vista=' . ($vistaGlobal ? 'global' : 'sucursal')
                    . '&tipo=success&mensaje='
                    . rawurlencode($esCorreccion
                        ? 'Corrección registrada. La nueva versión quedó bloqueada y lista para revisión.'
                        : 'Cuestionario y aceptación guardados. El expediente quedó bloqueado para proteger la información.')
                    . '#panelSocio'
                );
            }
        } catch (Throwable $e) {
            $errorFormulario = $e->getMessage();
        }
    }

    try {
        $configuracion = expediente_configuracion($conn);
        $preguntasActivas = expediente_preguntas($conn, true);
        $preguntasTodas = expediente_preguntas($conn, false);
    } catch (Throwable $e) {
        if ($errorFormulario === '') {
            $errorFormulario = $e->getMessage();
        }
    }
}

$clienteSeleccionadoId = (int) ($_GET['cliente_id'] ?? $_POST['cliente_id'] ?? 0);
$clienteSeleccionado = $clienteSeleccionadoId > 0
    ? expediente_cliente($conn, $clienteSeleccionadoId)
    : null;

if (
    $clienteSeleccionado
    && !$esAdministradorExpediente
    && (int) ($clienteSeleccionado['sucursal_registro_id'] ?? 0)
        !== $sucursalActual
) {
    $clienteSeleccionado = null;
    $clienteSeleccionadoId = 0;
    $errorFormulario =
        'El socio solicitado no pertenece a la sucursal activa.';
}

$ultimoExpedienteCliente = null;
$respuestasPreviasCliente = [];

if ($clienteSeleccionado) {
    $stmtUltimoExpediente = $conn->prepare(
        "SELECT id, fecha_aplicacion, vigente_hasta, total_alertas,
                estado_seguimiento, observaciones_admin,
                acepta_responsabilidad, nombre_firmante, parentesco_firmante
         FROM expedientes_salud
         WHERE cliente_id = ?
         ORDER BY fecha_aplicacion DESC, id DESC
         LIMIT 1"
    );

    if ($stmtUltimoExpediente) {
        $stmtUltimoExpediente->bind_param('i', $clienteSeleccionadoId);
        $stmtUltimoExpediente->execute();
        $resultadoUltimoExpediente = $stmtUltimoExpediente->get_result();
        $ultimoExpedienteCliente = $resultadoUltimoExpediente
            ? $resultadoUltimoExpediente->fetch_assoc()
            : null;
        $stmtUltimoExpediente->close();
    }

    if ($ultimoExpedienteCliente) {
        $ultimoExpedienteId = (int) ($ultimoExpedienteCliente['id'] ?? 0);
        $stmtRespuestasPrevias = $conn->prepare(
            "SELECT pregunta_id, respuesta_texto
             FROM expedientes_salud_respuestas
             WHERE expediente_id = ?"
        );

        if ($stmtRespuestasPrevias) {
            $stmtRespuestasPrevias->bind_param('i', $ultimoExpedienteId);
            $stmtRespuestasPrevias->execute();
            $resultadoRespuestasPrevias = $stmtRespuestasPrevias->get_result();

            while ($resultadoRespuestasPrevias && $filaRespuestaPrevia = $resultadoRespuestasPrevias->fetch_assoc()) {
                $respuestasPreviasCliente[(int) $filaRespuestaPrevia['pregunta_id']] =
                    (string) ($filaRespuestaPrevia['respuesta_texto'] ?? '');
            }

            $stmtRespuestasPrevias->close();
        }
    }
}

$expedienteVerId = (int) ($_GET['ver'] ?? 0);
if ($expedienteVerId <= 0 && $ultimoExpedienteCliente) {
    $expedienteVerId = (int) ($ultimoExpedienteCliente['id'] ?? 0);
}
if ($expedienteVerId <= 0 && $clienteSeleccionado && $ultimoExpedienteCliente) {
    $expedienteVerId = (int) ($ultimoExpedienteCliente['id'] ?? 0);
}
$expedienteDetalle = null;
$respuestasDetalle = [];
$historialCliente = [];

if ($expedienteVerId > 0) {
    $sql = "
        SELECT
            e.*,
            c.nombre,
            c.apellido,
            c.telefono,
            c.email,
            s.nombre AS sucursal_nombre,
            u.nombre AS administrador_nombre
        FROM expedientes_salud e
        INNER JOIN clientes c ON c.id = e.cliente_id
        INNER JOIN sucursales s ON s.id = e.sucursal_id
        INNER JOIN usuarios u ON u.id = e.aplicado_por
        WHERE e.id = ?
          AND (? = 1 OR e.sucursal_id = ?)
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $banderaAdministradorDetalle =
            $esAdministradorExpediente ? 1 : 0;

        $stmt->bind_param(
            'iii',
            $expedienteVerId,
            $banderaAdministradorDetalle,
            $sucursalActual
        );
        $stmt->execute();
        $resultado = $stmt->get_result();
        $expedienteDetalle = $resultado ? $resultado->fetch_assoc() : null;
        $stmt->close();
    }

    if ($expedienteDetalle) {
        $stmt = $conn->prepare("SELECT * FROM expedientes_salud_respuestas WHERE expediente_id = ? ORDER BY orden_snapshot ASC, id ASC");
        if ($stmt) {
            $stmt->bind_param('i', $expedienteVerId);
            $stmt->execute();
            $resultado = $stmt->get_result();
            while ($resultado && $fila = $resultado->fetch_assoc()) {
                $respuestasDetalle[] = $fila;
            }
            $stmt->close();
        }

        $clienteHistorialId = (int) $expedienteDetalle['cliente_id'];
        $stmt = $conn->prepare("
            SELECT id, cuestionario_version, fecha_aplicacion, vigente_hasta,
                   total_alertas, estado_seguimiento
            FROM expedientes_salud
            WHERE cliente_id = ?
            ORDER BY fecha_aplicacion DESC, id DESC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $clienteHistorialId);
            $stmt->execute();
            $resultado = $stmt->get_result();
            while ($resultado && $fila = $resultado->fetch_assoc()) {
                $historialCliente[] = $fila;
            }
            $stmt->close();
        }
    }
}

$busqueda = trim((string) ($_GET['q'] ?? ''));
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$porPagina = 18;
$offset = ($pagina - 1) * $porPagina;
$parametros = [];
$tipos = '';
$where = ["c.estado = 'activo'"];

if (!$vistaGlobal && $sucursalActual > 0) {
    $where[] = 'c.sucursal_registro_id = ?';
    $tipos .= 'i';
    $parametros[] = $sucursalActual;
}

if ($busqueda !== '') {
    $where[] = "(
        CONCAT(c.nombre, ' ', c.apellido) LIKE ?
        OR c.telefono LIKE ?
        OR c.email LIKE ?
        OR c.codigo_qr LIKE ?
    )";
    $like = '%' . $busqueda . '%';
    $tipos .= 'ssss';
    array_push($parametros, $like, $like, $like, $like);
}

$whereSql = implode(' AND ', $where);
$sqlConteo = "SELECT COUNT(*) AS total FROM clientes c WHERE {$whereSql}";
$stmtConteo = $conn->prepare($sqlConteo);
$totalSocios = 0;
if ($stmtConteo) {
    if ($tipos !== '') {
        expediente_bind_parametros($stmtConteo, $tipos, $parametros);
    }
    $stmtConteo->execute();
    $resultadoConteo = $stmtConteo->get_result();
    if ($resultadoConteo && $filaConteo = $resultadoConteo->fetch_assoc()) {
        $totalSocios = (int) $filaConteo['total'];
    }
    $stmtConteo->close();
}

$sqlSocios = "
    SELECT
        c.id,
        c.nombre,
        c.apellido,
        c.telefono,
        c.email,
        c.codigo_qr,
        s.nombre AS sucursal_registro,
        e.id AS expediente_id,
        e.fecha_aplicacion,
        e.vigente_hasta,
        e.total_alertas,
        e.estado_seguimiento
    FROM clientes c
    LEFT JOIN sucursales s ON s.id = c.sucursal_registro_id
    LEFT JOIN expedientes_salud e ON e.id = (
        SELECT e2.id
        FROM expedientes_salud e2
        WHERE e2.cliente_id = c.id
        ORDER BY e2.fecha_aplicacion DESC, e2.id DESC
        LIMIT 1
    )
    WHERE {$whereSql}
    ORDER BY c.nombre ASC, c.apellido ASC
    LIMIT ? OFFSET ?
";
$tiposSocios = $tipos . 'ii';
$parametrosSocios = $parametros;
$parametrosSocios[] = $porPagina;
$parametrosSocios[] = $offset;
$socios = [];
$stmtSocios = $conn->prepare($sqlSocios);
if ($stmtSocios) {
    expediente_bind_parametros($stmtSocios, $tiposSocios, $parametrosSocios);
    $stmtSocios->execute();
    $resultadoSocios = $stmtSocios->get_result();
    while ($resultadoSocios && $fila = $resultadoSocios->fetch_assoc()) {
        $socios[] = $fila;
    }
    $stmtSocios->close();
}

$totalPaginas = max(1, (int) ceil($totalSocios / $porPagina));
$hoy = date('Y-m-d');
$estadisticas = [
    'con_expediente' => 0,
    'vigentes' => 0,
    'requieren_revision' => 0,
    'sin_expediente' => 0,
];

$sqlStats = "
    SELECT
        SUM(CASE WHEN e.id IS NOT NULL THEN 1 ELSE 0 END) AS con_expediente,
        SUM(CASE WHEN e.vigente_hasta >= CURDATE() THEN 1 ELSE 0 END) AS vigentes,
        SUM(CASE WHEN e.estado_seguimiento IN ('requiere_revision', 'documentacion_pendiente', 'rechazado_correccion') THEN 1 ELSE 0 END) AS requieren_revision,
        SUM(CASE WHEN e.id IS NULL THEN 1 ELSE 0 END) AS sin_expediente
    FROM clientes c
    LEFT JOIN expedientes_salud e ON e.id = (
        SELECT e3.id
        FROM expedientes_salud e3
        WHERE e3.cliente_id = c.id
        ORDER BY e3.fecha_aplicacion DESC, e3.id DESC
        LIMIT 1
    )
    WHERE {$whereSql}
";
$stmtStats = $conn->prepare($sqlStats);
if ($stmtStats) {
    if ($tipos !== '') {
        expediente_bind_parametros($stmtStats, $tipos, $parametros);
    }
    $stmtStats->execute();
    $resultadoStats = $stmtStats->get_result();
    if ($resultadoStats && $filaStats = $resultadoStats->fetch_assoc()) {
        foreach ($estadisticas as $clave => $valor) {
            $estadisticas[$clave] = (int) ($filaStats[$clave] ?? 0);
        }
    }
    $stmtStats->close();
}

$paginaPreguntas = max(1, (int) ($_GET['pagina_preguntas'] ?? 1));
$porPaginaPreguntas = 8;
$totalPreguntasRegistradas = count($preguntasTodas);
$totalPaginasPreguntas = max(1, (int) ceil($totalPreguntasRegistradas / $porPaginaPreguntas));
if ($paginaPreguntas > $totalPaginasPreguntas) {
    $paginaPreguntas = $totalPaginasPreguntas;
}
$offsetPreguntas = ($paginaPreguntas - 1) * $porPaginaPreguntas;
$preguntasPagina = array_slice($preguntasTodas, $offsetPreguntas, $porPaginaPreguntas);

$preguntaEditarId = (int) ($_GET['editar_pregunta'] ?? 0);
$preguntaEditar = null;
foreach ($preguntasTodas as $preguntaItem) {
    if ((int) $preguntaItem['id'] === $preguntaEditarId) {
        $preguntaEditar = $preguntaItem;
        break;
    }
}

function expediente_url_paginacion(int $paginaObjetivo, string $busqueda, bool $vistaGlobal): string
{
    $params = [
        'tab' => 'expedientes',
        'pagina' => $paginaObjetivo,
        'vista' => $vistaGlobal ? 'global' : 'sucursal',
    ];
    if ($busqueda !== '') {
        $params['q'] = $busqueda;
    }
    return 'expediente_salud.php?' . http_build_query($params);
}

function expediente_url_paginacion_preguntas(int $paginaObjetivo, bool $vistaGlobal, int $preguntaEditarId = 0): string
{
    $params = [
        'tab' => 'configuracion',
        'pagina_preguntas' => $paginaObjetivo,
        'vista' => $vistaGlobal ? 'global' : 'sucursal',
    ];

    if ($preguntaEditarId > 0) {
        $params['editar_pregunta'] = $preguntaEditarId;
    }

    return 'expediente_salud.php?' . http_build_query($params) . '#preguntasGuardadas';
}
$correoTokensAsync = correo_cola_extraer_tokens_async();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Expediente de salud</title>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="css/expediente_salud.css?v=2.0.0">
    <style>
        .health-review-panel {
            margin-top: 20px;
            padding: 20px;
            border: 1px solid #dbe5f3;
            border-radius: 18px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        }

        .health-review-panel.is-review {
            border-color: #f5d67b;
            background: linear-gradient(180deg, #fffef8 0%, #fffbeb 100%);
        }

        .health-review-panel.is-pending {
            border-color: #f2b9b9;
            background: linear-gradient(180deg, #fffafa 0%, #fff1f2 100%);
        }

        .health-review-panel.is-approved {
            border-color: #a7e5c8;
            background: linear-gradient(180deg, #fbfffd 0%, #ecfdf5 100%);
        }

        .health-review-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
        }

        .health-review-heading h3 {
            margin: 4px 0 6px;
            color: #102a61;
            font-size: 1.08rem;
        }

        .health-review-heading p {
            margin: 0;
            color: #64748b;
            line-height: 1.55;
        }

        .health-review-count {
            flex: 0 0 auto;
            padding: 7px 11px;
            border-radius: 999px;
            background: #fff7d6;
            color: #9a5b00;
            font-size: .78rem;
            font-weight: 800;
        }

        .health-review-form {
            margin-top: 16px;
        }

        .health-review-form textarea {
            width: 100%;
            min-height: 92px;
            padding: 12px 14px;
            border: 1px solid #cfd9e8;
            border-radius: 12px;
            background: #fff;
            color: #172033;
            resize: vertical;
            outline: none;
        }

        .health-review-form textarea:focus {
            border-color: #6f8ed7;
            box-shadow: 0 0 0 4px rgba(36, 66, 146, .10);
        }

        .health-review-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 12px;
            flex-wrap: wrap;
        }

        .health-review-button {
            display: inline-flex;
            min-height: 42px;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 14px;
            border: 1px solid transparent;
            border-radius: 10px;
            font: inherit;
            font-weight: 800;
            cursor: pointer;
        }

        .health-review-button.approve {
            border-color: #059669;
            background: #059669;
            color: #fff;
        }

        .health-review-button.review {
            border-color: #e5a000;
            background: #fff8df;
            color: #8a5700;
        }

        .health-review-button.documents {
            border-color: #d8a2a2;
            background: #fff;
            color: #a32c2c;
        }

        .health-review-history {
            margin-top: 16px;
            overflow: hidden;
            border-top: 1px solid rgba(148, 163, 184, .25);
            color: #64748b;
        }

        .health-review-history summary {
            display: flex;
            min-height: 52px;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 12px 2px 0;
            color: #425675;
            cursor: pointer;
            list-style: none;
            user-select: none;
        }

        .health-review-history summary::-webkit-details-marker {
            display: none;
        }

        .health-review-history-title {
            display: flex;
            min-width: 0;
            align-items: center;
            gap: 10px;
        }

        .health-review-history-title > i {
            display: grid;
            flex: 0 0 34px;
            width: 34px;
            height: 34px;
            place-items: center;
            border-radius: 10px;
            color: #1e3a8a;
            background: #eef4ff;
        }

        .health-review-history-title strong,
        .health-review-history-title small {
            display: block;
        }

        .health-review-history-title strong {
            color: #314563;
            font-size: .79rem;
        }

        .health-review-history-title small {
            margin-top: 2px;
            color: #718096;
            font-size: .67rem;
            font-weight: 500;
        }

        .health-review-history-chevron {
            flex: 0 0 auto;
            transition: transform .2s ease;
        }

        .health-review-history[open] .health-review-history-chevron {
            transform: rotate(180deg);
        }

        .health-review-history-content {
            margin-top: 8px;
            padding: 13px 14px;
            border: 1px solid rgba(148, 163, 184, .22);
            border-radius: 11px;
            color: #5f6f85;
            background: rgba(255, 255, 255, .72);
            font-size: .76rem;
            white-space: pre-wrap;
            line-height: 1.65;
        }

        @media (max-width: 700px) {
            .health-review-heading {
                flex-direction: column;
            }

            .health-review-actions {
                flex-direction: column;
            }

            .health-review-button {
                width: 100%;
            }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<?php require __DIR__ . '/includes/sidebar.php'; ?>

<main class="health-main">
    <header class="health-page-header">
        <div>
            <span class="health-kicker"><i class="fa-solid fa-shield-heart"></i> Información confidencial</span>
            <h1>Expediente de salud</h1>
            <p>
                <?php echo $esAdministradorExpediente
                    ? 'Registra el cuestionario médico del socio y conserva la aceptación del documento de responsabilidad de forma segura.'
                    : 'Consulta expedientes protegidos y registra el seguimiento administrativo autorizado de los socios de tu sucursal.'; ?>
            </p>
        </div>
        <a class="health-primary-button" href="#listadoSocios">
            <i class="fa-solid fa-user-magnifying-glass"></i>
            Buscar socio
        </a>
    </header>

    <nav class="health-tabs health-tabs-unified" aria-label="Secciones del expediente">
        <a class="<?php echo $tab === 'expedientes' ? 'active' : ''; ?>" href="expediente_salud.php?tab=expedientes&vista=<?php echo $vistaGlobal ? 'global' : 'sucursal'; ?>">
            <i class="fa-solid fa-users-gear"></i>
            Expedientes y seguimiento
        </a>
        <?php if ($esAdministradorExpediente): ?>
            <a class="<?php echo $tab === 'configuracion' ? 'active' : ''; ?>" href="expediente_salud.php?tab=configuracion&vista=<?php echo $vistaGlobal ? 'global' : 'sucursal'; ?>">
                <i class="fa-solid fa-sliders"></i>
                Preguntas y documento
            </a>
        <?php endif; ?>
    </nav>

    <?php if ($errorFormulario !== ''): ?>
        <div class="health-alert danger">
            <i class="fa-solid fa-circle-exclamation"></i>
            <div><strong>No se pudo completar la operación.</strong><span><?php echo expediente_h($errorFormulario); ?></span></div>
        </div>
    <?php endif; ?>

    <?php if ($tab === 'expedientes'): ?>
        <section class="health-stat-grid" aria-label="Resumen de expedientes">
            <article class="health-stat-card health-stat-card-blue">
                <div class="health-stat-card-body">
                    <div class="health-stat-card-copy">
                        <strong><?php echo $estadisticas['con_expediente']; ?></strong>
                        <span>Con expediente</span>
                    </div>
                    <i class="fa-solid fa-folder-closed health-stat-card-icon" aria-hidden="true"></i>
                </div>
            </article>

            <article class="health-stat-card health-stat-card-green">
                <div class="health-stat-card-body">
                    <div class="health-stat-card-copy">
                        <strong><?php echo $estadisticas['vigentes']; ?></strong>
                        <span>Cuestionarios vigentes</span>
                    </div>
                    <i class="fa-solid fa-id-card health-stat-card-icon" aria-hidden="true"></i>
                </div>
            </article>

            <article class="health-stat-card health-stat-card-amber">
                <div class="health-stat-card-body">
                    <div class="health-stat-card-copy">
                        <strong><?php echo $estadisticas['requieren_revision']; ?></strong>
                        <span>Pendientes de revisión</span>
                    </div>
                    <i class="fa-solid fa-triangle-exclamation health-stat-card-icon" aria-hidden="true"></i>
                </div>
            </article>

            <article class="health-stat-card health-stat-card-red">
                <div class="health-stat-card-body">
                    <div class="health-stat-card-copy">
                        <strong><?php echo $estadisticas['sin_expediente']; ?></strong>
                        <span>Sin cuestionario</span>
                    </div>
                    <i class="fa-solid fa-user-clock health-stat-card-icon" aria-hidden="true"></i>
                </div>
            </article>
        </section>

        <?php if ($clienteSeleccionado): ?>
            <?php
            $tieneExpedientePanel = !empty($ultimoExpedienteCliente['id']);
            $expedienteActualId = (int) ($ultimoExpedienteCliente['id'] ?? 0);
            $estadoPanelSocio = (string) ($ultimoExpedienteCliente['estado_seguimiento'] ?? '');
            $expedienteMostradoId = (int) ($expedienteDetalle['id'] ?? 0);
            $esVersionActual = !$tieneExpedientePanel || $expedienteMostradoId === $expedienteActualId;
            $modoCorreccion = $tieneExpedientePanel
                && $esVersionActual
                && $estadoPanelSocio === 'rechazado_correccion';
            $mostrarFormulario = $esAdministradorExpediente && (!$tieneExpedientePanel || $modoCorreccion);
            $aceptadoPanel = (int) ($ultimoExpedienteCliente['acepta_responsabilidad'] ?? 0) === 1;
            $estadoPanelEtiqueta = !$tieneExpedientePanel
                ? 'Sin expediente'
                : expediente_estado_etiqueta($estadoPanelSocio);
            $estadoPanelClase = !$tieneExpedientePanel ? 'pending' : $estadoPanelSocio;
            $historialVisibleTexto = trim((string) ($expedienteDetalle['observaciones_admin'] ?? $ultimoExpedienteCliente['observaciones_admin'] ?? ''));
            $historialEventos = $historialVisibleTexto !== ''
                ? (preg_split('/\R/u', $historialVisibleTexto, -1, PREG_SPLIT_NO_EMPTY) ?: [])
                : [];
            ?>
            <section class="health-case-workspace health-case-workspace-clean" id="panelSocio">
                <header class="health-case-overview">
                    <div class="health-case-identity">
                        <span class="health-member-avatar large"><?php echo expediente_h(strtoupper(substr((string) $clienteSeleccionado['nombre'], 0, 1) . substr((string) $clienteSeleccionado['apellido'], 0, 1))); ?></span>
                        <div>
                            <span class="health-kicker">Expediente del socio</span>
                            <h2><?php echo expediente_h(expediente_nombre_cliente($clienteSeleccionado)); ?></h2>
                            <p>
                                <span><i class="fa-solid fa-phone"></i> <?php echo expediente_h($clienteSeleccionado['telefono'] ?: 'Sin teléfono'); ?></span>
                                <span><i class="fa-solid fa-envelope"></i> <?php echo expediente_h($clienteSeleccionado['email'] ?: 'Sin correo'); ?></span>
                                <span><i class="fa-solid fa-building"></i> <?php echo expediente_h($clienteSeleccionado['sucursal_registro'] ?: 'Sin sucursal'); ?></span>
                            </p>
                        </div>
                    </div>
                    <div class="health-case-overview-actions">
                        <span class="status <?php echo expediente_h($estadoPanelClase); ?>"><?php echo expediente_h($estadoPanelEtiqueta); ?></span>
                        <a class="health-icon-button" href="expediente_salud.php?tab=expedientes&vista=<?php echo $vistaGlobal ? 'global' : 'sucursal'; ?>#listadoSocios" title="Cerrar panel"><i class="fa-solid fa-xmark"></i></a>
                    </div>
                </header>

                <div class="health-case-metrics">
                    <article>
                        <i class="fa-solid fa-notes-medical"></i>
                        <span>Último cuestionario</span>
                        <strong><?php echo $tieneExpedientePanel ? expediente_h(expediente_formatear_fecha((string) $ultimoExpedienteCliente['fecha_aplicacion'], true)) : 'No aplicado'; ?></strong>
                    </article>
                    <article>
                        <i class="fa-solid fa-calendar-check"></i>
                        <span>Vigencia</span>
                        <strong><?php echo $tieneExpedientePanel ? expediente_h(expediente_formatear_fecha((string) $ultimoExpedienteCliente['vigente_hasta'])) : 'Pendiente'; ?></strong>
                    </article>
                    <article class="<?php echo $aceptadoPanel ? 'is-ok' : 'is-pending'; ?>">
                        <i class="fa-solid fa-file-signature"></i>
                        <span>Aceptación</span>
                        <strong><?php echo $aceptadoPanel ? 'Aceptado el ' . expediente_h(expediente_formatear_fecha((string) $ultimoExpedienteCliente['fecha_aplicacion'])) : 'No registrada'; ?></strong>
                    </article>
                    <article class="<?php echo !empty($clienteSeleccionado['email']) ? 'is-ok' : 'is-pending'; ?>">
                        <i class="fa-solid fa-envelope-circle-check"></i>
                        <span>Notificaciones</span>
                        <strong><?php echo !empty($clienteSeleccionado['email']) ? 'Correo disponible' : 'Sin correo válido'; ?></strong>
                    </article>
                </div>

                <?php if ($mostrarFormulario): ?>
                    <section class="health-edit-mode <?php echo $modoCorreccion ? 'is-correction' : 'is-first'; ?>">
                        <div class="health-edit-mode-heading">
                            <span class="health-edit-mode-icon"><i class="fa-solid <?php echo $modoCorreccion ? 'fa-pen-to-square' : 'fa-clipboard-check'; ?>"></i></span>
                            <div>
                                <span class="health-kicker"><?php echo $modoCorreccion ? 'Edición habilitada por rechazo' : 'Primer registro'; ?></span>
                                <h3><?php echo $modoCorreccion ? 'Corregir respuestas y aceptación' : 'Aplicar cuestionario y aceptación'; ?></h3>
                                <p><?php echo $modoCorreccion
                                    ? 'El expediente fue rechazado expresamente. Al guardar se creará una nueva versión y la anterior permanecerá intacta en el historial.'
                                    : 'Al guardar, el expediente quedará bloqueado. Solo podrá corregirse si un administrador lo rechaza para corrección.'; ?></p>
                            </div>
                        </div>
                    </section>

                    <form method="post" class="health-questionnaire health-single-form" id="healthQuestionnaireForm">
                        <input type="hidden" name="accion" value="guardar_expediente">
                        <input type="hidden" name="csrf" value="<?php echo expediente_h($csrf); ?>">
                        <input type="hidden" name="cliente_id" value="<?php echo (int) $clienteSeleccionado['id']; ?>">

                        <section class="health-panel compact health-questionnaire-intro">
                            <div class="health-panel-heading"><div><span class="health-kicker">Cuestionario vigente</span><h2><?php echo expediente_h($configuracion['nombre_cuestionario'] ?? 'Cuestionario médico'); ?></h2><p><?php echo expediente_h($configuracion['introduccion'] ?? ''); ?></p></div></div>
                        </section>

                        <?php $seccionActual = ''; ?>
                        <?php foreach ($preguntasActivas as $indice => $pregunta): ?>
                            <?php if ($seccionActual !== $pregunta['seccion']): ?>
                                <?php $seccionActual = (string) $pregunta['seccion']; ?>
                                <?php if ($indice > 0): ?></div></section><?php endif; ?>
                                <section class="health-question-section"><div class="health-question-section-heading"><span><?php echo $indice + 1; ?></span><div><h2><?php echo expediente_h($seccionActual); ?></h2><p>Registra exactamente la respuesta proporcionada por el socio.</p></div></div><div class="health-question-list">
                            <?php endif; ?>
                            <?php
                            $campo = 'pregunta_' . (int) $pregunta['id'];
                            $preguntaIdActual = (int) $pregunta['id'];
                            $tieneRespuestaPrevia = array_key_exists($preguntaIdActual, $respuestasPreviasCliente);
                            $valorPrevio = (string) ($_POST[$campo] ?? ($respuestasPreviasCliente[$preguntaIdActual] ?? ''));
                            ?>
                            <article class="health-question-card <?php echo $tieneRespuestaPrevia ? 'is-prefilled' : ''; ?>">
                                <label for="<?php echo expediente_h($campo); ?>">
                                    <?php echo expediente_h($pregunta['pregunta']); ?>
                                    <?php if ((int) $pregunta['obligatoria'] === 1): ?><span class="required">Obligatoria</span><?php endif; ?>
                                    <?php if ($pregunta['dispara_alerta'] !== 'ninguna'): ?><span class="review"><i class="fa-solid fa-triangle-exclamation"></i> Puede generar revisión</span><?php endif; ?>
                                </label>
                                <?php if ((string) $pregunta['ayuda'] !== ''): ?><p><?php echo expediente_h($pregunta['ayuda']); ?></p><?php endif; ?>

                                <?php if ($pregunta['tipo_respuesta'] === 'si_no'): ?>
                                    <div class="health-choice-row">
                                        <label><input type="radio" name="<?php echo expediente_h($campo); ?>" value="1" <?php echo $valorPrevio === '1' ? 'checked' : ''; ?> <?php echo (int) $pregunta['obligatoria'] === 1 ? 'required' : ''; ?>><span><i class="fa-solid fa-check"></i> Sí</span></label>
                                        <label><input type="radio" name="<?php echo expediente_h($campo); ?>" value="0" <?php echo $valorPrevio === '0' ? 'checked' : ''; ?>><span><i class="fa-solid fa-xmark"></i> No</span></label>
                                    </div>
                                <?php elseif ($pregunta['tipo_respuesta'] === 'texto'): ?>
                                    <textarea id="<?php echo expediente_h($campo); ?>" name="<?php echo expediente_h($campo); ?>" rows="3" <?php echo (int) $pregunta['obligatoria'] === 1 ? 'required' : ''; ?>><?php echo expediente_h($valorPrevio); ?></textarea>
                                <?php elseif ($pregunta['tipo_respuesta'] === 'numero'): ?>
                                    <input id="<?php echo expediente_h($campo); ?>" type="number" step="any" name="<?php echo expediente_h($campo); ?>" value="<?php echo expediente_h($valorPrevio); ?>" <?php echo (int) $pregunta['obligatoria'] === 1 ? 'required' : ''; ?>>
                                <?php elseif ($pregunta['tipo_respuesta'] === 'fecha'): ?>
                                    <input id="<?php echo expediente_h($campo); ?>" type="date" name="<?php echo expediente_h($campo); ?>" value="<?php echo expediente_h($valorPrevio); ?>" <?php echo (int) $pregunta['obligatoria'] === 1 ? 'required' : ''; ?>>
                                <?php elseif ($pregunta['tipo_respuesta'] === 'seleccion'): ?>
                                    <select id="<?php echo expediente_h($campo); ?>" name="<?php echo expediente_h($campo); ?>" <?php echo (int) $pregunta['obligatoria'] === 1 ? 'required' : ''; ?>><option value="">Selecciona una opción</option><?php foreach ($pregunta['opciones'] as $opcion): ?><option value="<?php echo expediente_h($opcion); ?>" <?php echo $valorPrevio === $opcion ? 'selected' : ''; ?>><?php echo expediente_h($opcion); ?></option><?php endforeach; ?></select>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                        <?php if ($preguntasActivas !== []): ?></div></section><?php endif; ?>

                        <?php
                        $nombreAceptanteActual = (string) (
                            $_POST['nombre_firmante']
                            ?? ($ultimoExpedienteCliente['nombre_firmante'] ?? expediente_nombre_cliente($clienteSeleccionado))
                        );
                        $parentescoAceptanteActual = (string) (
                            $_POST['parentesco_firmante']
                            ?? ($ultimoExpedienteCliente['parentesco_firmante'] ?? 'Socio')
                        );
                        $aceptacionYaMarcada = isset($_POST['acepta_responsabilidad'])
                            || ((int) ($ultimoExpedienteCliente['acepta_responsabilidad'] ?? 0) === 1);
                        ?>

                        <section class="health-question-section responsibility">
                            <div class="health-question-section-heading"><span><i class="fa-solid fa-file-circle-check"></i></span><div><h2><?php echo expediente_h($configuracion['documento_titulo'] ?? 'Documento de responsabilidad'); ?></h2><p>La aceptación se vinculará a esta nueva versión del expediente.</p></div></div>
                            <div class="health-document-preview" id="healthDocumentPreview" data-template="<?php echo expediente_h(expediente_limpiar_repeticion_documento((string) ($configuracion['documento_texto'] ?? ''))); ?>"><?php echo nl2br(expediente_h(expediente_documento_con_datos((string) ($configuracion['documento_texto'] ?? ''), ['gimnasio' => $gimnasioNombreSistema, 'socio' => expediente_nombre_cliente($clienteSeleccionado), 'fecha' => date('d/m/Y'), 'sucursal' => $sucursalNombre, 'administrador' => $usuarioNombre, 'firmante' => $nombreAceptanteActual, 'parentesco' => $parentescoAceptanteActual]))); ?></div>
                            <div class="health-acceptance-panel">
                                <div class="health-acceptance-grid">
                                    <label class="health-field"><span>Nombre de quien acepta</span><input id="healthAcceptingPerson" type="text" name="nombre_firmante" value="<?php echo expediente_h($nombreAceptanteActual); ?>" required></label>
                                    <label class="health-field"><span>Relación con el socio</span><select id="healthAcceptingRelation" name="parentesco_firmante"><option value="Socio" data-document-label="socio" <?php echo $parentescoAceptanteActual === 'Socio' ? 'selected' : ''; ?>>Es el socio</option><option value="Madre/Padre/Tutor" data-document-label="madre, padre o tutor" <?php echo $parentescoAceptanteActual === 'Madre/Padre/Tutor' ? 'selected' : ''; ?>>Madre, padre o tutor</option><option value="Responsable legal" data-document-label="responsable legal" <?php echo $parentescoAceptanteActual === 'Responsable legal' ? 'selected' : ''; ?>>Responsable legal</option><option value="Otro" data-document-label="otro responsable" <?php echo $parentescoAceptanteActual === 'Otro' ? 'selected' : ''; ?>>Otro responsable</option></select></label>
                                </div>
                                <label class="health-acceptance-check prominent"><input type="checkbox" name="acepta_responsabilidad" value="1" required <?php echo $aceptacionYaMarcada ? 'checked' : ''; ?>><span><strong>Confirmo que el socio o responsable leyó y aceptó el documento.</strong><small>Al guardar, la nueva versión volverá a quedar bloqueada.</small></span><i class="fa-solid fa-circle-check health-acceptance-check-icon"></i></label>
                            </div>
                        </section>

                        <footer class="health-form-footer health-form-footer-secure">
                            <div><i class="fa-solid fa-lock"></i><span><?php echo $modoCorreccion ? 'La corrección creará una versión nueva; el expediente rechazado no será alterado.' : 'Después de guardar, las respuestas no podrán editarse directamente.'; ?></span></div>
                            <button class="health-primary-button" type="submit"><i class="fa-solid fa-floppy-disk"></i> <?php echo $modoCorreccion ? 'Guardar corrección' : 'Crear expediente'; ?></button>
                        </footer>
                    </form>
                <?php elseif (!$tieneExpedientePanel && !$esAdministradorExpediente): ?>
                    <div class="health-readonly-notice">
                        <i class="fa-solid fa-circle-info"></i>
                        <span>
                            Este socio todavía no tiene expediente de salud.
                            La aplicación inicial del cuestionario debe realizarla
                            un administrador.
                        </span>
                    </div>
                <?php elseif ($expedienteDetalle): ?>
                    <section class="health-record-readonly">
                        <header class="health-record-toolbar">
                            <div>
                                <span class="health-kicker"><?php echo $esVersionActual ? 'Expediente actual' : 'Versión histórica · solo lectura'; ?></span>
                                <h3>Expediente #<?php echo (int) $expedienteDetalle['id']; ?></h3>
                                <p>Aplicado el <?php echo expediente_h(expediente_formatear_fecha((string) $expedienteDetalle['fecha_aplicacion'], true)); ?> por <?php echo expediente_h((string) $expedienteDetalle['administrador_nombre']); ?>.</p>
                            </div>
                            <a class="health-secondary-button" target="_blank" href="expediente_salud_imprimir.php?id=<?php echo (int) $expedienteDetalle['id']; ?>"><i class="fa-solid fa-print"></i> Imprimir / PDF</a>
                        </header>

                        <div class="health-readonly-notice"><i class="fa-solid fa-lock"></i><span>Las respuestas están protegidas. Solo se habilita una corrección cuando el expediente actual es rechazado expresamente.</span></div>

                        <div class="health-response-list health-response-list-single">
                            <?php $seccionAnterior = ''; ?>
                            <?php foreach ($respuestasDetalle as $respuesta): ?>
                                <?php if ($seccionAnterior !== $respuesta['seccion_snapshot']): ?>
                                    <?php $seccionAnterior = (string) $respuesta['seccion_snapshot']; ?>
                                    <h3><?php echo expediente_h($seccionAnterior); ?></h3>
                                <?php endif; ?>
                                <article class="<?php echo (int) $respuesta['genera_alerta'] === 1 ? 'has-alert' : ''; ?>">
                                    <div><strong><?php echo expediente_h($respuesta['pregunta_snapshot']); ?></strong><span><?php echo expediente_h(expediente_respuesta_visible($respuesta)); ?></span></div>
                                    <?php if ((int) $respuesta['genera_alerta'] === 1): ?><i class="fa-solid fa-triangle-exclamation" title="Respuesta marcada para revisión"></i><?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>

                        <div class="health-responsibility-summary">
                            <div><span class="health-kicker">Aceptación registrada</span><h3><?php echo expediente_h($expedienteDetalle['documento_titulo_snapshot']); ?></h3><p>Aceptado por <strong><?php echo expediente_h($expedienteDetalle['nombre_firmante']); ?></strong> · <?php echo expediente_h(expediente_parentesco_para_documento((string) $expedienteDetalle['parentesco_firmante'])); ?> · <?php echo expediente_h(expediente_formatear_fecha((string) $expedienteDetalle['fecha_aplicacion'], true)); ?></p></div>
                            <span class="health-acceptance-badge"><i class="fa-solid fa-circle-check"></i> Aceptado</span>
                        </div>

                        <?php if ($esVersionActual): ?>
                            <?php
                            $estadoRevisionActual = (string) ($expedienteDetalle['estado_seguimiento'] ?? 'sin_observaciones');
                            $claseRevision = $estadoRevisionActual === 'requiere_revision'
                                ? 'is-review'
                                : ($estadoRevisionActual === 'documentacion_pendiente' ? 'is-pending' : 'is-approved');
                            ?>
                            <section class="health-review-panel health-review-panel-single <?php echo expediente_h($claseRevision); ?>">
                                <div class="health-review-heading">
                                    <div>
                                        <span class="health-kicker">
                                            <?php echo $esAdministradorExpediente
                                                ? 'Decisión administrativa'
                                                : 'Seguimiento de recepción'; ?>
                                        </span>
                                        <h3><?php echo expediente_h(expediente_estado_etiqueta($estadoRevisionActual)); ?></h3>
                                        <p>
                                            Solicitar documentos o rechazar envía una notificación al socio;
                                            aprobar mantiene las respuestas bloqueadas. Ninguna de estas acciones
                                            permite editar directamente el cuestionario.
                                        </p>
                                    </div>
                                    <span class="health-review-count"><?php echo (int) ($expedienteDetalle['total_alertas'] ?? 0); ?> alerta(s)</span>
                                </div>
                                <form method="post" class="health-review-form" id="healthReviewForm">
                                    <input type="hidden" name="accion" value="resolver_revision_expediente">
                                    <input type="hidden" name="csrf" value="<?php echo expediente_h($csrf); ?>">
                                    <input type="hidden" name="expediente_id" value="<?php echo (int) $expedienteDetalle['id']; ?>">
                                    <textarea name="observaciones_revision" placeholder="Escribe la indicación para el socio o el motivo del rechazo."></textarea>
                                    <div class="health-review-actions">
                                        <button class="health-review-button documents" type="submit" name="decision_revision" value="solicitar_documentacion"><i class="fa-solid fa-file-circle-exclamation"></i> Solicitar documentación</button>
                                        <button class="health-review-button reject" type="submit" name="decision_revision" value="rechazar_correccion" data-review-confirm="rechazar"><i class="fa-solid fa-pen-to-square"></i> Rechazar para corrección</button>
                                        <button class="health-review-button approve" type="submit" name="decision_revision" value="aprobar" data-review-confirm="aprobar"><i class="fa-solid fa-circle-check"></i> Aprobar expediente</button>
                                    </div>
                                </form>
                            </section>
                        <?php else: ?>
                            <div class="health-historical-notice"><i class="fa-solid fa-clock-rotate-left"></i><span>Estás consultando una versión histórica. No se permite cambiar su estado ni sus respuestas.</span></div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php if ($tieneExpedientePanel): ?>
                    <details class="health-unified-history">
                        <summary>
                            <span class="health-unified-history-icon"><i class="fa-solid fa-timeline"></i></span>
                            <span><strong>Actividad e historial</strong><small><?php echo count($historialEventos); ?> evento(s) · <?php echo count($historialCliente); ?> versión(es)</small></span>
                            <i class="fa-solid fa-chevron-down health-unified-history-arrow"></i>
                        </summary>
                        <div class="health-unified-history-body">
                            <?php if ($historialEventos !== []): ?>
                                <div class="health-unified-events">
                                    <h4>Actividad de la versión seleccionada</h4>
                                    <?php foreach ($historialEventos as $evento): ?><p><?php echo expediente_h((string) $evento); ?></p><?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="health-unified-versions">
                                <h4>Versiones del expediente</h4>
                                <?php foreach ($historialCliente as $registroHistorial): ?>
                                    <a class="<?php echo (int) $registroHistorial['id'] === $expedienteMostradoId ? 'active' : ''; ?>" href="expediente_salud.php?tab=expedientes&cliente_id=<?php echo (int) $clienteSeleccionado['id']; ?>&ver=<?php echo (int) $registroHistorial['id']; ?>&vista=<?php echo $vistaGlobal ? 'global' : 'sucursal'; ?>#panelSocio">
                                        <span><strong>#<?php echo (int) $registroHistorial['id']; ?> · <?php echo expediente_h(expediente_formatear_fecha((string) $registroHistorial['fecha_aplicacion'], true)); ?></strong><small><?php echo expediente_h(expediente_estado_etiqueta((string) $registroHistorial['estado_seguimiento'])); ?> · <?php echo (int) $registroHistorial['total_alertas']; ?> alerta(s)</small></span>
                                        <i class="fa-solid fa-chevron-right"></i>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </details>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="health-panel" id="listadoSocios">
            <div class="health-panel-heading">
                <div>
                    <h2>Socios</h2>
                    <p><?php echo $vistaGlobal ? 'Mostrando socios de todas las sucursales.' : 'Mostrando socios registrados en la sucursal activa.'; ?></p>
                </div>
                <form class="health-search" method="get">
                    <input type="hidden" name="tab" value="expedientes">
                    <input type="hidden" name="vista" value="<?php echo $vistaGlobal ? 'global' : 'sucursal'; ?>">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="search" name="q" value="<?php echo expediente_h($busqueda); ?>" placeholder="Buscar nombre, teléfono, correo o QR">
                    <?php if ($busqueda !== ''): ?><a href="expediente_salud.php?tab=expedientes&vista=<?php echo $vistaGlobal ? 'global' : 'sucursal'; ?>" title="Borrar búsqueda"><i class="fa-solid fa-xmark"></i></a><?php endif; ?>
                </form>
            </div>

            <?php if ($socios === []): ?>
                <div class="health-empty"><i class="fa-solid fa-user-slash"></i><strong>No se encontraron socios.</strong><span>Prueba con otra búsqueda o cambia la vista de sucursal.</span></div>
            <?php else: ?>
                <div class="health-member-grid">
                    <?php foreach ($socios as $socio): ?>
                        <?php
                        $tieneExpediente = !empty($socio['expediente_id']);
                        $vigente = $tieneExpediente && (string) $socio['vigente_hasta'] >= $hoy;
                        $estadoExpedienteSocio = (string) ($socio['estado_seguimiento'] ?? '');
                        $requiereRevision = $estadoExpedienteSocio === 'requiere_revision';
                        $documentacionPendiente = $estadoExpedienteSocio === 'documentacion_pendiente';
                        $rechazadoCorreccion = $estadoExpedienteSocio === 'rechazado_correccion';
                        $claseEstadoTarjeta = $rechazadoCorreccion
                            ? 'has-correction-rejected'
                            : ($documentacionPendiente
                                ? 'has-documentation-pending'
                                : ($requiereRevision ? 'has-review-pending' : ''));
                        ?>
                        <article class="health-member-card <?php echo expediente_h($claseEstadoTarjeta); ?>">
                            <div class="health-member-avatar"><?php echo expediente_h(strtoupper(substr((string) $socio['nombre'], 0, 1) . substr((string) $socio['apellido'], 0, 1))); ?></div>
                            <div class="health-member-copy">
                                <div class="health-member-title">
                                    <h3><?php echo expediente_h(trim($socio['nombre'] . ' ' . $socio['apellido'])); ?></h3>
                                    <?php if (!$tieneExpediente): ?><span class="status pending">Sin expediente</span>
                                    <?php elseif ($rechazadoCorreccion): ?><span class="status rechazado_correccion">Corrección requerida</span>
                                    <?php elseif ($documentacionPendiente): ?><span class="status documentacion_pendiente" title="El socio debe entregar documentación">Documentación pendiente</span>
                                    <?php elseif ($requiereRevision): ?><span class="status requiere_revision">Requiere revisión</span>
                                    <?php elseif ($vigente): ?><span class="status vigente">Vigente</span>
                                    <?php else: ?><span class="status expired">Vencido</span><?php endif; ?>
                                </div>
                                <p><i class="fa-solid fa-building"></i> <?php echo expediente_h($socio['sucursal_registro'] ?? 'Sin sucursal'); ?></p>
                                <p><i class="fa-solid fa-phone"></i> <?php echo expediente_h($socio['telefono'] ?: 'Sin teléfono'); ?></p>
                                <?php if ($tieneExpediente): ?><small>Última aplicación: <?php echo expediente_h(expediente_formatear_fecha($socio['fecha_aplicacion'])); ?></small><?php endif; ?>
                            </div>
                            <div class="health-member-actions">
                                <a class="health-primary-small health-manage-member" href="expediente_salud.php?tab=expedientes&cliente_id=<?php echo (int) $socio['id']; ?><?php echo $tieneExpediente ? '&ver=' . (int) $socio['expediente_id'] : ''; ?>&vista=<?php echo $vistaGlobal ? 'global' : 'sucursal'; ?>#panelSocio">
                                    <i class="fa-solid fa-sliders"></i> Gestionar
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPaginas > 1): ?>
                    <nav class="health-pagination" aria-label="Paginación">
                        <a class="<?php echo $pagina <= 1 ? 'disabled' : ''; ?>" href="<?php echo $pagina <= 1 ? '#' : expediente_h(expediente_url_paginacion($pagina - 1, $busqueda, $vistaGlobal)); ?>"><i class="fa-solid fa-chevron-left"></i></a>
                        <span>Página <?php echo $pagina; ?> de <?php echo $totalPaginas; ?></span>
                        <a class="<?php echo $pagina >= $totalPaginas ? 'disabled' : ''; ?>" href="<?php echo $pagina >= $totalPaginas ? '#' : expediente_h(expediente_url_paginacion($pagina + 1, $busqueda, $vistaGlobal)); ?>"><i class="fa-solid fa-chevron-right"></i></a>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($tab === 'configuracion'): ?>
        <section class="health-config-intro">
            <div>
                <span class="health-kicker"><i class="fa-solid fa-wand-magic-sparkles"></i> Configuración sencilla</span>
                <h2>Preguntas y documento de responsabilidad</h2>
                <p>Escribe el contenido con palabras normales. El sistema guarda cada cambio y agrega automáticamente los datos del socio, la fecha, la sucursal y el administrador.</p>
            </div>
            <span class="health-auto-version"><i class="fa-solid fa-rotate"></i> Control automático de cambios</span>
        </section>

        <section class="health-config-grid">
            <form method="post" class="health-panel health-config-document">
                <input type="hidden" name="accion" value="guardar_configuracion">
                <input type="hidden" name="csrf" value="<?php echo expediente_h($csrf); ?>">
                <div class="health-panel-heading">
                    <div>
                        <span class="health-kicker">Documento y vigencia</span>
                        <h2>Información general</h2>
                        <p>Los expedientes anteriores conservarán el contenido que fue aceptado en su fecha de registro.</p>
                    </div>
                </div>

                <div class="health-form-grid health-document-editor-grid">
                    <label class="health-field">
                        <span>Nombre del cuestionario</span>
                        <input type="text" name="nombre_cuestionario" value="<?php echo expediente_h($configuracion['nombre_cuestionario'] ?? ''); ?>" required>
                    </label>

                    <label class="health-field">
                        <span>Vigencia del cuestionario</span>
                        <div class="health-input-suffix">
                            <input type="number" name="vigencia_dias" min="1" max="3650" step="1" value="<?php echo (int) ($configuracion['vigencia_dias'] ?? 365); ?>" required>
                            <span>días</span>
                        </div>
                    </label>

                    <label class="health-field full">
                        <span>Indicaciones para quien aplica el cuestionario</span>
                        <textarea name="introduccion" rows="3" placeholder="Ejemplo: realiza las preguntas directamente al socio y registra sus respuestas."><?php echo expediente_h($configuracion['introduccion'] ?? ''); ?></textarea>
                    </label>

                    <label class="health-field full">
                        <span>Título del documento de responsabilidad</span>
                        <input type="text" name="documento_titulo" value="<?php echo expediente_h($configuracion['documento_titulo'] ?? ''); ?>" required>
                    </label>

                    <div class="health-field full health-document-text-field">
                        <div class="health-document-editor-heading">
                            <div>
                                <span>Contenido del documento</span>
                                <small>Coloca el cursor en la parte de la carta donde debe aparecer un dato y presiona el botón correspondiente.</small>
                            </div>
                            <button type="button" class="health-secondary-button health-template-example-button" id="useResponsibilityTemplate">
                                <i class="fa-solid fa-file-circle-plus"></i>
                                Usar ejemplo
                            </button>
                        </div>

                        <div class="health-document-token-helper">
                            <strong><i class="fa-solid fa-wand-magic-sparkles"></i> Insertar dato automático</strong>
                            <div class="health-document-token-list">
                                <button type="button" class="health-document-token" data-document-token="[PERSONA QUE ACEPTA]"><i class="fa-solid fa-user-check"></i> Persona que acepta</button>
                                <button type="button" class="health-document-token" data-document-token="[RELACIÓN CON EL SOCIO]"><i class="fa-solid fa-people-arrows"></i> Relación con el socio</button>
                                <button type="button" class="health-document-token" data-document-token="[NOMBRE DEL SOCIO]"><i class="fa-solid fa-user"></i> Nombre del socio</button>
                                <button type="button" class="health-document-token" data-document-token="[NOMBRE DEL GIMNASIO]"><i class="fa-solid fa-dumbbell"></i> Nombre del gimnasio</button>
                                <button type="button" class="health-document-token" data-document-token="[FECHA]"><i class="fa-solid fa-calendar-day"></i> Fecha</button>
                                <button type="button" class="health-document-token" data-document-token="[SUCURSAL]"><i class="fa-solid fa-building"></i> Sucursal</button>
                                <button type="button" class="health-document-token" data-document-token="[ADMINISTRADOR]"><i class="fa-solid fa-user-shield"></i> Administrador</button>
                            </div>
                            <span class="health-document-token-help"><i class="fa-solid fa-circle-info"></i> En el editor aparecerá una etiqueta entre corchetes. Al aplicar el cuestionario, el sistema la cambia por el dato real.</span>
                        </div>

                        <textarea id="healthDocumentTextEditor" name="documento_texto" rows="18" required placeholder="Ejemplo: Yo, [PERSONA QUE ACEPTA], declaro que..."><?php echo expediente_h(expediente_limpiar_repeticion_documento((string) ($configuracion['documento_texto'] ?? ''))); ?></textarea>
                    </div>
                </div>

                <div class="health-document-example-preview">
                    <div class="health-document-example-heading">
                        <div>
                            <strong><i class="fa-solid fa-eye"></i> Vista previa de ejemplo</strong>
                            <span>Así se verá la carta cuando el sistema coloque los datos reales.</span>
                        </div>
                        <span class="health-preview-badge">Ejemplo</span>
                    </div>
                    <div id="healthDocumentExamplePreview"></div>
                </div>

                <div class="health-config-actions">
                    <div class="health-config-note inline"><i class="fa-solid fa-scale-balanced"></i><span>Revisa legalmente el documento antes de utilizarlo de forma definitiva.</span></div>
                    <button class="health-primary-button" type="submit"><i class="fa-solid fa-floppy-disk"></i> Guardar documento</button>
                </div>
            </form>

            <div class="health-config-questions">
                <form method="post" class="health-panel health-question-editor">
                    <input type="hidden" name="accion" value="guardar_pregunta">
                    <input type="hidden" name="csrf" value="<?php echo expediente_h($csrf); ?>">
                    <input type="hidden" name="pregunta_id" value="<?php echo (int) ($preguntaEditar['id'] ?? 0); ?>">

                    <div class="health-panel-heading">
                        <div>
                            <span class="health-kicker"><?php echo $preguntaEditar ? 'Editando la pregunta #' . (int) $preguntaEditar['orden'] : 'Nueva pregunta'; ?></span>
                            <h2><?php echo $preguntaEditar ? 'Actualizar pregunta' : 'Agregar pregunta'; ?></h2>
                            <p>Completa la pregunta y selecciona cómo deberá responderse.</p>
                        </div>
                        <?php if ($preguntaEditar): ?>
                            <a class="health-icon-button" href="expediente_salud.php?tab=configuracion&vista=<?php echo $vistaGlobal ? 'global' : 'sucursal'; ?>" title="Cancelar edición"><i class="fa-solid fa-xmark"></i></a>
                        <?php endif; ?>
                    </div>

                    <div class="health-question-editor-topnote"><i class="fa-solid fa-circle-info"></i><span>Primero escribe la pregunta, después define cómo se responderá y finalmente guarda. El sistema acomodará el orden automáticamente.</span></div>

                    <div class="health-question-editor-grid">
                        <label class="health-field health-question-main-field">
                            <span>Pregunta</span>
                            <textarea name="pregunta" rows="3" required placeholder="Ejemplo: ¿Actualmente tiene alguna lesión?"><?php echo expediente_h($preguntaEditar['pregunta'] ?? ''); ?></textarea>
                        </label>

                        <label class="health-field health-question-section-field">
                            <span>Sección del cuestionario</span>
                            <input type="text" name="seccion" value="<?php echo expediente_h($preguntaEditar['seccion'] ?? 'Antecedentes generales'); ?>" required>
                        </label>

                        <label class="health-field health-question-type-field">
                            <span>Tipo de respuesta</span>
                            <select name="tipo_respuesta" id="questionType">
                                <option value="si_no" <?php echo ($preguntaEditar['tipo_respuesta'] ?? '') === 'si_no' ? 'selected' : ''; ?>>Sí o No</option>
                                <option value="texto" <?php echo ($preguntaEditar['tipo_respuesta'] ?? '') === 'texto' ? 'selected' : ''; ?>>Texto libre</option>
                                <option value="numero" <?php echo ($preguntaEditar['tipo_respuesta'] ?? '') === 'numero' ? 'selected' : ''; ?>>Número</option>
                                <option value="fecha" <?php echo ($preguntaEditar['tipo_respuesta'] ?? '') === 'fecha' ? 'selected' : ''; ?>>Fecha</option>
                                <option value="seleccion" <?php echo ($preguntaEditar['tipo_respuesta'] ?? '') === 'seleccion' ? 'selected' : ''; ?>>Lista de opciones</option>
                            </select>
                        </label>

                        <label class="health-field health-question-alert-field">
                            <span>Respuesta que requiere revisión</span>
                            <select name="dispara_alerta">
                                <option value="ninguna">Ninguna</option>
                                <option value="si" <?php echo ($preguntaEditar['dispara_alerta'] ?? '') === 'si' ? 'selected' : ''; ?>>Cuando responde Sí</option>
                                <option value="no" <?php echo ($preguntaEditar['dispara_alerta'] ?? '') === 'no' ? 'selected' : ''; ?>>Cuando responde No</option>
                                <option value="cualquier_respuesta" <?php echo ($preguntaEditar['dispara_alerta'] ?? '') === 'cualquier_respuesta' ? 'selected' : ''; ?>>Cuando escribe cualquier respuesta</option>
                            </select>
                        </label>

                        <label class="health-field" id="questionOptionsField">
                            <span>Opciones de respuesta</span>
                            <textarea name="opciones" rows="4" placeholder="Escribe una opción por línea"><?php echo expediente_h(isset($preguntaEditar['opciones']) ? implode("\n", $preguntaEditar['opciones']) : ''); ?></textarea>
                            <small>Solo se muestra cuando eliges “Lista de opciones”.</small>
                        </label>

                        <label class="health-field health-question-help-field">
                            <span>Ayuda para quien realiza la pregunta</span>
                            <input type="text" name="ayuda" value="<?php echo expediente_h($preguntaEditar['ayuda'] ?? ''); ?>" placeholder="Opcional">
                        </label>

                        <label class="health-field health-order-field">
                            <span>Orden de la pregunta</span>
                            <input type="number" name="orden" min="1" max="9999" step="1" value="<?php echo (int) ($preguntaEditar['orden'] ?? $siguienteOrdenPregunta); ?>" required>
                            <small>El sistema acomodará todas las preguntas de 1 en 1.</small>
                        </label>

                        <label class="health-field health-state-field">
                            <span>Disponibilidad</span>
                            <select name="estado">
                                <option value="activa" <?php echo ($preguntaEditar['estado'] ?? 'activa') === 'activa' ? 'selected' : ''; ?>>Mostrar en el cuestionario</option>
                                <option value="inactiva" <?php echo ($preguntaEditar['estado'] ?? '') === 'inactiva' ? 'selected' : ''; ?>>Ocultar temporalmente</option>
                            </select>
                        </label>

                        <label class="health-toggle health-required-toggle">
                            <input type="checkbox" name="obligatoria" value="1" <?php echo !isset($preguntaEditar['obligatoria']) || (int) $preguntaEditar['obligatoria'] === 1 ? 'checked' : ''; ?>>
                            <span><strong>Respuesta obligatoria</strong><small>No permitirá guardar el cuestionario sin responderla.</small></span>
                        </label>
                    </div>

                    <div class="health-question-editor-actions">
                        <?php if ($preguntaEditar): ?>
                            <a class="health-secondary-button" href="expediente_salud.php?tab=configuracion&vista=<?php echo $vistaGlobal ? 'global' : 'sucursal'; ?>"><i class="fa-solid fa-ban"></i> Cancelar</a>
                        <?php endif; ?>
                        <button class="health-primary-button" type="submit"><i class="fa-solid <?php echo $preguntaEditar ? 'fa-floppy-disk' : 'fa-plus'; ?>"></i> <?php echo $preguntaEditar ? 'Guardar cambios' : 'Agregar pregunta'; ?></button>
                    </div>
                </form>
            </div>
        </section>

        <section class="health-panel health-saved-questions-panel" id="preguntasGuardadas">
            <div class="health-panel-heading">
                <div>
                    <h2>Preguntas guardadas</h2>
                    <p><?php echo count($preguntasActivas); ?> activas de <?php echo count($preguntasTodas); ?> registradas. Se muestran <?php echo count($preguntasPagina); ?> por página y el número indica el orden en el cuestionario.</p>
                </div>
            </div>
            <div class="health-question-admin-list">
                <?php foreach ($preguntasPagina as $pregunta): ?>
                    <article class="<?php echo $pregunta['estado'] === 'inactiva' ? 'inactive' : ''; ?>">
                        <div class="health-question-order" title="Orden de la pregunta"><?php echo (int) $pregunta['orden']; ?></div>
                        <div class="health-question-admin-copy">
                            <span><?php echo expediente_h($pregunta['seccion']); ?></span>
                            <strong><?php echo expediente_h($pregunta['pregunta']); ?></strong>
                            <small><?php echo expediente_h(str_replace('_', ' / ', $pregunta['tipo_respuesta'])); ?> · <?php echo (int) $pregunta['obligatoria'] === 1 ? 'Obligatoria' : 'Opcional'; ?><?php echo $pregunta['dispara_alerta'] !== 'ninguna' ? ' · Requiere revisión según respuesta' : ''; ?></small>
                        </div>
                        <div class="health-question-admin-actions">
                            <a class="health-icon-button" href="expediente_salud.php?tab=configuracion&editar_pregunta=<?php echo (int) $pregunta['id']; ?>&pagina_preguntas=<?php echo (int) $paginaPreguntas; ?>&vista=<?php echo $vistaGlobal ? 'global' : 'sucursal'; ?>" title="Editar pregunta"><i class="fa-solid fa-pen"></i></a>
                            <form method="post">
                                <input type="hidden" name="accion" value="cambiar_estado_pregunta">
                                <input type="hidden" name="csrf" value="<?php echo expediente_h($csrf); ?>">
                                <input type="hidden" name="pregunta_id" value="<?php echo (int) $pregunta['id']; ?>">
                                <input type="hidden" name="nuevo_estado" value="<?php echo $pregunta['estado'] === 'activa' ? 'inactiva' : 'activa'; ?>">
                                <button class="health-icon-button" type="submit" title="<?php echo $pregunta['estado'] === 'activa' ? 'Ocultar pregunta' : 'Mostrar pregunta'; ?>"><i class="fa-solid <?php echo $pregunta['estado'] === 'activa' ? 'fa-eye-slash' : 'fa-eye'; ?>"></i></button>
                            </form>
                            <form method="post" onsubmit="return confirm('¿Deseas eliminar esta pregunta? Esta acción no se puede deshacer.');">
                                <input type="hidden" name="accion" value="eliminar_pregunta">
                                <input type="hidden" name="csrf" value="<?php echo expediente_h($csrf); ?>">
                                <input type="hidden" name="pregunta_id" value="<?php echo (int) $pregunta['id']; ?>">
                                <button class="health-icon-button danger" type="submit" title="Eliminar pregunta"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPaginasPreguntas > 1): ?>
                <nav class="health-pagination health-pagination-questions" aria-label="Paginación de preguntas">
                    <a class="<?php echo $paginaPreguntas <= 1 ? 'disabled' : ''; ?>" href="<?php echo $paginaPreguntas <= 1 ? '#' : expediente_h(expediente_url_paginacion_preguntas($paginaPreguntas - 1, $vistaGlobal, $preguntaEditarId)); ?>"><i class="fa-solid fa-chevron-left"></i></a>
                    <span>Página <?php echo $paginaPreguntas; ?> de <?php echo $totalPaginasPreguntas; ?></span>
                    <a class="<?php echo $paginaPreguntas >= $totalPaginasPreguntas ? 'disabled' : ''; ?>" href="<?php echo $paginaPreguntas >= $totalPaginasPreguntas ? '#' : expediente_h(expediente_url_paginacion_preguntas($paginaPreguntas + 1, $vistaGlobal, $preguntaEditarId)); ?>"><i class="fa-solid fa-chevron-right"></i></a>
                </nav>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>

<script>
(function () {
    const mensaje = <?php echo json_encode($mensaje, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const tipo = <?php echo json_encode($tipoMensaje, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    if (mensaje && typeof Swal !== 'undefined') {
        Swal.fire({
            icon: tipo === 'error' ? 'error' : 'success',
            title: tipo === 'error' ? 'No se completó' : 'Cambios guardados',
            text: mensaje,
            confirmButtonText: 'Entendido',
            confirmButtonColor: '#1e3a8a'
        });
    }

    const reviewForm = document.getElementById('healthReviewForm');
    if (reviewForm && typeof Swal !== 'undefined') {
        reviewForm.addEventListener('submit', function (event) {
            const submitter = event.submitter;
            const decision = submitter
                ? String(submitter.value || '')
                : '';

            if (reviewForm.dataset.swalConfirmed === '1') {
                return;
            }

            const observation = reviewForm.querySelector(
                'textarea[name="observaciones_revision"]'
            );
            const observationText = observation
                ? observation.value.trim()
                : '';

            if (
                ['solicitar_documentacion', 'rechazar_correccion'].includes(decision)
                && observationText === ''
            ) {
                event.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: decision === 'rechazar_correccion'
                        ? 'Escribe el motivo de la corrección'
                        : 'Escribe qué documentación se necesita',
                    text: decision === 'rechazar_correccion'
                        ? 'Indica claramente qué información debe corregirse antes de habilitar una nueva versión.'
                        : 'Agrega las indicaciones que recibirá el socio por correo.',
                    confirmButtonText: 'Entendido',
                    confirmButtonColor: '#244292'
                }).then(function () {
                    if (observation) {
                        observation.focus();
                    }
                });
                return;
            }

            if (!['rechazar_correccion', 'aprobar'].includes(decision)) {
                return;
            }

            event.preventDefault();

            const isReject = decision === 'rechazar_correccion';
            Swal.fire({
                icon: isReject ? 'warning' : 'question',
                title: isReject
                    ? '¿Habilitar una corrección?'
                    : '¿Aprobar este expediente?',
                html: isReject
                    ? 'La versión actual quedará protegida en el historial y se habilitará una nueva corrección para el socio.<br><br><strong>Esta acción no elimina las respuestas anteriores.</strong>'
                    : 'Confirma que revisaste las respuestas y que el expediente puede quedar aprobado.',
                showCancelButton: true,
                reverseButtons: true,
                focusCancel: true,
                confirmButtonText: isReject
                    ? 'Sí, habilitar corrección'
                    : 'Sí, aprobar expediente',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#244292',
                cancelButtonColor: '#64748b'
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                reviewForm.dataset.swalConfirmed = '1';
                reviewForm.requestSubmit(submitter);
            });
        });
    }

    const questionType = document.getElementById('questionType');
    const optionsField = document.getElementById('questionOptionsField');
    function syncOptionsField() {
        if (!questionType || !optionsField) return;
        optionsField.style.display = questionType.value === 'seleccion' ? '' : 'none';
    }
    if (questionType) {
        questionType.addEventListener('change', syncOptionsField);
        syncOptionsField();
    }

    function escapeRegExp(value) {
        return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function replaceDocumentData(template, data) {
        const replacements = {
            '[NOMBRE DEL GIMNASIO]': data.gimnasio,
            '[GIMNASIO]': data.gimnasio,
            '[NOMBRE DEL SOCIO]': data.socio,
            '[SOCIO]': data.socio,
            '[FECHA]': data.fecha,
            '[SUCURSAL]': data.sucursal,
            '[ADMINISTRADOR]': data.administrador,
            '[PERSONA QUE ACEPTA]': data.firmante,
            '[RELACIÓN CON EL SOCIO]': data.parentesco,
            '{{GIMNASIO}}': data.gimnasio,
            '{{SOCIO}}': data.socio,
            '{{FECHA}}': data.fecha,
            '{{SUCURSAL}}': data.sucursal,
            '{{ADMINISTRADOR}}': data.administrador
        };

        let result = template || '';

        Object.keys(replacements).forEach(function (token) {
            result = result.replace(
                new RegExp(escapeRegExp(token), 'gi'),
                replacements[token] || ''
            );
        });

        return result;
    }

    const documentEditor = document.getElementById('healthDocumentTextEditor');
    const documentExamplePreview = document.getElementById('healthDocumentExamplePreview');
    const tokenButtons = document.querySelectorAll('[data-document-token]');
    const useTemplateButton = document.getElementById('useResponsibilityTemplate');

    const exampleData = {
        gimnasio: <?php echo json_encode($gimnasioNombreSistema, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
        socio: 'Juan Pérez López',
        fecha: <?php echo json_encode(date('d/m/Y'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
        sucursal: <?php echo json_encode($sucursalNombre !== '' ? $sucursalNombre : 'Sucursal principal', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
        administrador: <?php echo json_encode($usuarioNombre, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
        firmante: 'Juan Pérez López',
        parentesco: 'socio'
    };

    function updateDocumentExamplePreview() {
        if (!documentEditor || !documentExamplePreview) {
            return;
        }

        const content = replaceDocumentData(documentEditor.value, exampleData).trim();
        documentExamplePreview.textContent = content || 'La vista previa aparecerá aquí cuando escribas el documento.';
    }

    function insertDocumentToken(token) {
        if (!documentEditor) {
            return;
        }

        const start = documentEditor.selectionStart ?? documentEditor.value.length;
        const end = documentEditor.selectionEnd ?? start;
        const before = documentEditor.value.slice(0, start);
        const after = documentEditor.value.slice(end);
        const needsLeadingSpace = before !== '' && !/\s$/.test(before);
        const needsTrailingSpace = after !== '' && !/^\s/.test(after);
        const inserted = (needsLeadingSpace ? ' ' : '') + token + (needsTrailingSpace ? ' ' : '');

        documentEditor.value = before + inserted + after;
        const cursor = before.length + inserted.length;
        documentEditor.focus();
        documentEditor.setSelectionRange(cursor, cursor);
        documentEditor.dispatchEvent(new Event('input', { bubbles: true }));
    }

    tokenButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            insertDocumentToken(button.getAttribute('data-document-token') || '');
        });
    });

    if (documentEditor) {
        documentEditor.addEventListener('input', updateDocumentExamplePreview);
        updateDocumentExamplePreview();
    }

    const recommendedTemplate = `Yo, [PERSONA QUE ACEPTA], en mi carácter de [RELACIÓN CON EL SOCIO] respecto de [NOMBRE DEL SOCIO], manifiesto que el día [FECHA] leí y comprendí el presente documento para el uso de las instalaciones de [NOMBRE DEL GIMNASIO], sucursal [SUCURSAL].

Reconozco que el ejercicio físico implica esfuerzo y riesgos inherentes. Me comprometo a utilizar correctamente las instalaciones y el equipo, respetar las indicaciones de seguridad, mantener una conducta responsable y detener la actividad si presento dolor, mareo, falta de aire fuera de lo habitual u otro malestar.

Entiendo que este cuestionario es un registro administrativo y no sustituye una consulta, diagnóstico, autorización o seguimiento médico. Cuando exista una condición, síntoma, antecedente o recomendación profesional que lo amerite, procuraré obtener valoración médica antes de realizar actividad física.

Declaro que la información proporcionada es verdadera y acepto cumplir las reglas de seguridad del gimnasio. El presente registro fue realizado por [ADMINISTRADOR].`;

    if (useTemplateButton && documentEditor) {
        useTemplateButton.addEventListener('click', async function () {
            let replaceContent = true;

            if (documentEditor.value.trim() !== '' && typeof Swal !== 'undefined') {
                const result = await Swal.fire({
                    icon: 'question',
                    title: '¿Usar la plantilla de ejemplo?',
                    text: 'El texto actual del documento será reemplazado.',
                    showCancelButton: true,
                    confirmButtonText: 'Sí, reemplazar',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#1e3a8a'
                });
                replaceContent = result.isConfirmed;
            }

            if (!replaceContent) {
                return;
            }

            documentEditor.value = recommendedTemplate;
            documentEditor.dispatchEvent(new Event('input', { bubbles: true }));
            documentEditor.focus();
        });
    }

    const acceptancePreview = document.getElementById('healthDocumentPreview');
    const acceptingPerson = document.getElementById('healthAcceptingPerson');
    const acceptingRelation = document.getElementById('healthAcceptingRelation');

    if (acceptancePreview) {
        const realData = {
            gimnasio: <?php echo json_encode($gimnasioNombreSistema, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            socio: <?php echo json_encode($clienteSeleccionado ? expediente_nombre_cliente($clienteSeleccionado) : 'Socio', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            fecha: <?php echo json_encode(date('d/m/Y'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            sucursal: <?php echo json_encode($sucursalNombre, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            administrador: <?php echo json_encode($usuarioNombre, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>,
            firmante: '',
            parentesco: 'socio'
        };

        function updateAcceptancePreview() {
            realData.firmante = acceptingPerson ? acceptingPerson.value.trim() : realData.socio;
            if (acceptingRelation) {
                const selectedRelation = acceptingRelation.options[acceptingRelation.selectedIndex];
                realData.parentesco = selectedRelation.dataset.documentLabel
                    || selectedRelation.value.toLowerCase();
            } else {
                realData.parentesco = 'socio';
            }
            acceptancePreview.textContent = replaceDocumentData(
                acceptancePreview.getAttribute('data-template') || '',
                realData
            );
        }

        if (acceptingPerson) {
            acceptingPerson.addEventListener('input', updateAcceptancePreview);
        }

        if (acceptingRelation) {
            acceptingRelation.addEventListener('change', updateAcceptancePreview);
        }

        updateAcceptancePreview();
    }

})();
</script>

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

    function procesarCorreo(token) {
        if (!/^[a-f0-9]{64}$/.test(String(token || ''))) {
            return;
        }

        const body = 'token=' + encodeURIComponent(token);

        fetch('api/correo/procesar_token.php', {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            keepalive: true,
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: body
        }).catch(function (error) {
            console.error('La notificación del expediente quedó pendiente para reintento:', error);
        });
    }

    tokens.forEach(function (token, index) {
        window.setTimeout(function () {
            procesarCorreo(token);
        }, 500 + (index * 250));
    });
})();
</script>
</body>
</html>
