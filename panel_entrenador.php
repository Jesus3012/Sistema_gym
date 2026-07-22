<?php
// Archivo: panel_entrenador.php
// Panel exclusivo para entrenadores internos registrados como usuarios.

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

date_default_timezone_set(
    (string) ($_SESSION['sucursal_zona_horaria'] ?? 'America/Mexico_City')
);

function entrenador_h(?string $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function entrenador_fecha_valida(string $fecha): bool
{
    $objeto = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);

    return $objeto instanceof DateTimeImmutable
        && $objeto->format('Y-m-d') === $fecha;
}

function entrenador_fecha_texto(string $fecha): string
{
    if (!entrenador_fecha_valida($fecha)) {
        return $fecha;
    }

    $dias = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
        7 => 'Domingo',
    ];

    $meses = [
        1 => 'enero',
        2 => 'febrero',
        3 => 'marzo',
        4 => 'abril',
        5 => 'mayo',
        6 => 'junio',
        7 => 'julio',
        8 => 'agosto',
        9 => 'septiembre',
        10 => 'octubre',
        11 => 'noviembre',
        12 => 'diciembre',
    ];

    $objeto = new DateTimeImmutable($fecha);

    return sprintf(
        '%s %d de %s',
        $dias[(int) $objeto->format('N')] ?? '',
        (int) $objeto->format('j'),
        $meses[(int) $objeto->format('n')] ?? ''
    );
}

function entrenador_hora(?string $hora): string
{
    $hora = trim((string) $hora);

    if ($hora === '') {
        return 'Horario general';
    }

    $timestamp = strtotime($hora);

    return $timestamp === false ? $hora : date('H:i', $timestamp);
}

function entrenador_redirigir(array $params = []): never
{
    $url = 'panel_entrenador.php';

    if ($params !== []) {
        $url .= '?' . http_build_query($params);
    }

    header('Location: ' . $url);
    exit;
}

function entrenador_flash_guardar(
    string $icon,
    string $title,
    string $message
): void {
    $_SESSION['entrenador_flash'] = compact('icon', 'title', 'message');
}

function entrenador_flash_consumir(): ?array
{
    $flash = $_SESSION['entrenador_flash'] ?? null;
    unset($_SESSION['entrenador_flash']);

    return is_array($flash) ? $flash : null;
}

function entrenador_bind(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '' || $params === []) {
        return;
    }

    $refs = [];

    foreach ($params as $key => &$value) {
        $refs[$key] = &$value;
    }

    $stmt->bind_param($types, ...$refs);
}

$rolActual = strtolower(trim((string) ($_SESSION['user_rol'] ?? '')));

if ($rolActual !== 'entrenador') {
    $_SESSION['alerta_acceso_denegado'] = [
        'titulo' => 'Módulo exclusivo para entrenadores',
        'mensaje' => 'Este panel está reservado para entrenadores internos registrados en el sistema.',
        'rol' => ucfirst($rolActual ?: 'Usuario'),
        'modulo' => 'Mi agenda de clases',
        'sucursal' => (string) ($_SESSION['sucursal_nombre'] ?? 'Sucursal'),
    ];

    header('Location: dashboard.php?error=acceso_denegado');
    exit;
}

$database = new Database();
$conn = $database->getConnection();

if (!$conn instanceof mysqli) {
    http_response_code(500);
    exit('No fue posible conectar con la base de datos.');
}

$conn->set_charset('utf8mb4');

$entrenadorId = (int) ($_SESSION['user_id'] ?? 0);
$entrenadorNombre = trim((string) ($_SESSION['user_name'] ?? 'Entrenador'));

$stmtUsuario = $conn->prepare(
    "SELECT id, nombre, estado, rol
     FROM usuarios
     WHERE id = ?
       AND estado = 'activo'
       AND rol = 'entrenador'
     LIMIT 1"
);
$stmtUsuario->bind_param('i', $entrenadorId);
$stmtUsuario->execute();
$usuarioEntrenador = $stmtUsuario->get_result()->fetch_assoc();
$stmtUsuario->close();

if (!$usuarioEntrenador) {
    $_SESSION['mensaje_acceso'] = 'Tu cuenta ya no está habilitada como entrenador.';
    header('Location: logout.php');
    exit;
}

$entrenadorNombre = trim((string) $usuarioEntrenador['nombre']);

if (empty($_SESSION['csrf_panel_entrenador'])) {
    $_SESSION['csrf_panel_entrenador'] = bin2hex(random_bytes(32));
}

$csrf = (string) $_SESSION['csrf_panel_entrenador'];
$hoy = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    $csrfRecibido = (string) ($_POST['csrf_token'] ?? '');

    if ($csrfRecibido === '' || !hash_equals($csrf, $csrfRecibido)) {
        entrenador_flash_guardar(
            'error',
            'Sesión vencida',
            'Actualiza la página e intenta nuevamente.'
        );
        entrenador_redirigir();
    }

    if ($action === 'registrar_asistencia') {
        $inscripcionId = (int) ($_POST['inscripcion_id'] ?? 0);
        $claseId = (int) ($_POST['clase_id'] ?? 0);
        $horarioId = (int) ($_POST['horario_id'] ?? 0);
        $fechaClase = trim((string) ($_POST['fecha_clase'] ?? ''));
        $busqueda = trim((string) ($_POST['buscar'] ?? ''));
        $pagina = max(1, (int) ($_POST['pagina'] ?? 1));
        $periodo = strtolower(trim((string) ($_POST['periodo'] ?? '')));
        $historialPost = (int) ($_POST['historial'] ?? 0) === 1;

        if (!in_array($periodo, ['hoy', 'proximas', 'recientes'], true)) {
            $periodo = '';
        }

        $retorno = [
            'clase' => $claseId,
            'fecha' => $fechaClase,
            'horario' => $horarioId,
            'buscar' => $busqueda,
            'pagina' => $pagina,
        ];

        if ($periodo !== '') {
            $retorno['periodo'] = $periodo;
        }

        if ($historialPost) {
            $retorno['historial'] = 1;
        }

        try {
            if ($inscripcionId <= 0 || $claseId <= 0) {
                throw new RuntimeException('La inscripción seleccionada no es válida.');
            }

            if (!entrenador_fecha_valida($fechaClase)) {
                throw new RuntimeException('La fecha de la sesión no es válida.');
            }

            if ($fechaClase > $hoy) {
                throw new RuntimeException(
                    'La asistencia se habilitará el día de la sesión.'
                );
            }

            $conn->begin_transaction();

            $sqlUpdate = "UPDATE inscripciones_clases ic
                INNER JOIN clases c ON c.id = ic.clase_id
                SET ic.asistencia = 1,
                    ic.fecha_ultima_asistencia = ic.fecha_clase
                WHERE ic.id = ?
                  AND ic.clase_id = ?
                  AND ic.fecha_clase = ?
                  AND ic.estado = 'activa'
                  AND c.instructor_tipo = 'interno'
                  AND c.instructor_usuario_id = ?
                  AND (
                        (? = 0 AND ic.horario_id IS NULL)
                        OR ic.horario_id = ?
                  )
                  AND (
                        COALESCE(ic.asistencia, 0) = 0
                        OR ic.fecha_ultima_asistencia IS NULL
                        OR ic.fecha_ultima_asistencia <> ic.fecha_clase
                  )";

            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->bind_param(
                'iisiii',
                $inscripcionId,
                $claseId,
                $fechaClase,
                $entrenadorId,
                $horarioId,
                $horarioId
            );
            $stmtUpdate->execute();
            $afectadas = $stmtUpdate->affected_rows;
            $stmtUpdate->close();

            if ($afectadas !== 1) {
                throw new RuntimeException(
                    'La asistencia ya estaba registrada o el participante ya no pertenece a esta sesión.'
                );
            }

            $conn->commit();

            entrenador_flash_guardar(
                'success',
                'Asistencia registrada',
                'El participante quedó marcado como presente.'
            );
        } catch (Throwable $error) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
            }

            entrenador_flash_guardar(
                'error',
                'No fue posible registrar la asistencia',
                $error->getMessage()
            );
        }

        entrenador_redirigir($retorno);
    }

    entrenador_redirigir();
}


/* =========================================================
   CLASES ASIGNADAS AL ENTRENADOR INTERNO
========================================================= */
$stmtClases = $conn->prepare(
    "SELECT
        c.id,
        c.nombre,
        c.descripcion,
        c.cupo_maximo,
        c.duracion_minutos,
        c.estado,
        c.sucursal_id,
        s.nombre AS sucursal_nombre,
        s.clave AS sucursal_clave,
        GROUP_CONCAT(
            CONCAT(
                ch.dia_semana,
                '|',
                TIME_FORMAT(ch.hora_inicio, '%H:%i'),
                '|',
                TIME_FORMAT(ch.hora_fin, '%H:%i')
            )
            ORDER BY ch.dia_semana, ch.hora_inicio
            SEPARATOR ';;'
        ) AS horarios_compactos
     FROM clases c
     INNER JOIN sucursales s ON s.id = c.sucursal_id
     LEFT JOIN clases_horarios ch
       ON ch.clase_id = c.id
      AND ch.estado = 'activo'
     WHERE c.instructor_tipo = 'interno'
       AND c.instructor_usuario_id = ?
     GROUP BY c.id
     ORDER BY
        CASE c.estado WHEN 'activa' THEN 0 ELSE 1 END,
        s.nombre,
        c.nombre"
);
$stmtClases->bind_param('i', $entrenadorId);
$stmtClases->execute();
$clasesAsignadas = $stmtClases->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtClases->close();

$diasSemana = [
    1 => 'Lun',
    2 => 'Mar',
    3 => 'Mié',
    4 => 'Jue',
    5 => 'Vie',
    6 => 'Sáb',
    7 => 'Dom',
];

foreach ($clasesAsignadas as &$claseAsignada) {
    $horarios = [];
    $compactos = trim((string) ($claseAsignada['horarios_compactos'] ?? ''));

    if ($compactos !== '') {
        foreach (explode(';;', $compactos) as $bloque) {
            $partes = explode('|', $bloque);

            if (count($partes) !== 3) {
                continue;
            }

            $dia = (int) $partes[0];
            $horarios[] = sprintf(
                '%s %s–%s',
                $diasSemana[$dia] ?? 'Día',
                $partes[1],
                $partes[2]
            );
        }
    }

    $claseAsignada['horarios_lista'] = $horarios;
}
unset($claseAsignada);

/* =========================================================
   SESIONES CON ALUMNOS
========================================================= */
$desde = date('Y-m-d', strtotime('-45 days'));
$hasta = date('Y-m-d', strtotime('+90 days'));

$stmtSesiones = $conn->prepare(
    "SELECT
        c.id AS clase_id,
        c.nombre AS clase_nombre,
        c.cupo_maximo,
        c.duracion_minutos,
        c.sucursal_id,
        s.nombre AS sucursal_nombre,
        s.clave AS sucursal_clave,
        ic.fecha_clase,
        COALESCE(ic.horario_id, 0) AS horario_id,
        TIME_FORMAT(ch.hora_inicio, '%H:%i') AS hora_inicio,
        TIME_FORMAT(ch.hora_fin, '%H:%i') AS hora_fin,
        SUM(CASE WHEN ic.estado = 'activa' THEN 1 ELSE 0 END) AS total_activos,
        SUM(
            CASE
                WHEN ic.estado = 'activa'
                 AND COALESCE(ic.asistencia, 0) > 0
                 AND ic.fecha_ultima_asistencia = ic.fecha_clase
                THEN 1 ELSE 0
            END
        ) AS total_presentes,
        SUM(CASE WHEN ic.estado = 'activa' AND ic.tipo_participante = 'socio' THEN 1 ELSE 0 END) AS total_socios,
        SUM(CASE WHEN ic.estado = 'activa' AND ic.tipo_participante = 'externo' THEN 1 ELSE 0 END) AS total_visitantes
     FROM inscripciones_clases ic
     INNER JOIN clases c ON c.id = ic.clase_id
     INNER JOIN sucursales s ON s.id = c.sucursal_id
     LEFT JOIN clases_horarios ch ON ch.id = ic.horario_id
     WHERE c.instructor_tipo = 'interno'
       AND c.instructor_usuario_id = ?
       AND ic.fecha_clase BETWEEN ? AND ?
     GROUP BY
        c.id,
        c.nombre,
        c.cupo_maximo,
        c.duracion_minutos,
        c.sucursal_id,
        s.nombre,
        s.clave,
        ic.fecha_clase,
        COALESCE(ic.horario_id, 0),
        ch.hora_inicio,
        ch.hora_fin
     HAVING total_activos > 0
     ORDER BY
        CASE
            WHEN ic.fecha_clase = CURDATE() THEN 0
            WHEN ic.fecha_clase > CURDATE() THEN 1
            ELSE 2
        END,
        CASE WHEN ic.fecha_clase >= CURDATE() THEN ic.fecha_clase END ASC,
        CASE WHEN ic.fecha_clase < CURDATE() THEN ic.fecha_clase END DESC,
        ch.hora_inicio ASC"
);
$stmtSesiones->bind_param('iss', $entrenadorId, $desde, $hasta);
$stmtSesiones->execute();
$sesiones = $stmtSesiones->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtSesiones->close();

/* =========================================================
   RESUMEN GENERAL Y ESTADÍSTICAS POR CLASE
========================================================= */
$totalSesionesHoy = 0;
$totalAlumnosHoy = 0;
$totalPresentesHoy = 0;
$sesionesProximas = 0;
$estadisticasPorClase = [];

foreach ($clasesAsignadas as $claseAsignada) {
    $idClase = (int) $claseAsignada['id'];

    $estadisticasPorClase[$idClase] = [
        'sesiones' => 0,
        'alumnos' => 0,
        'hoy' => 0,
        'proximas' => 0,
        'recientes' => 0,
    ];
}

foreach ($sesiones as $sesion) {
    $fechaSesion = (string) $sesion['fecha_clase'];
    $idClase = (int) $sesion['clase_id'];

    if (!isset($estadisticasPorClase[$idClase])) {
        $estadisticasPorClase[$idClase] = [
            'sesiones' => 0,
            'alumnos' => 0,
            'hoy' => 0,
            'proximas' => 0,
            'recientes' => 0,
        ];
    }

    $estadisticasPorClase[$idClase]['sesiones']++;
    $estadisticasPorClase[$idClase]['alumnos'] += (int) $sesion['total_activos'];

    if ($fechaSesion === $hoy) {
        $totalSesionesHoy++;
        $totalAlumnosHoy += (int) $sesion['total_activos'];
        $totalPresentesHoy += (int) $sesion['total_presentes'];
        $estadisticasPorClase[$idClase]['hoy']++;
    } elseif ($fechaSesion > $hoy) {
        $sesionesProximas++;
        $estadisticasPorClase[$idClase]['proximas']++;
    } else {
        $estadisticasPorClase[$idClase]['recientes']++;
    }
}

/* =========================================================
   CLASE Y PERIODO SELECCIONADOS
========================================================= */
$claseSeleccionadaId = max(0, (int) ($_GET['clase'] ?? 0));
$claseSeleccionada = null;

foreach ($clasesAsignadas as $claseAsignada) {
    if ((int) $claseAsignada['id'] === $claseSeleccionadaId) {
        $claseSeleccionada = $claseAsignada;
        break;
    }
}

if ($claseSeleccionada === null && $clasesAsignadas !== []) {
    $claseSeleccionada = $clasesAsignadas[0];
    $claseSeleccionadaId = (int) $claseSeleccionada['id'];
}

$sesionesClase = [];

if ($claseSeleccionadaId > 0) {
    foreach ($sesiones as $sesion) {
        if ((int) $sesion['clase_id'] === $claseSeleccionadaId) {
            $sesionesClase[] = $sesion;
        }
    }
}

$conteosPeriodo = [
    'hoy' => 0,
    'proximas' => 0,
    'recientes' => 0,
];

foreach ($sesionesClase as $sesion) {
    $fecha = (string) $sesion['fecha_clase'];

    if ($fecha === $hoy) {
        $conteosPeriodo['hoy']++;
    } elseif ($fecha > $hoy) {
        $conteosPeriodo['proximas']++;
    } else {
        $conteosPeriodo['recientes']++;
    }
}

$periodo = strtolower(trim((string) ($_GET['periodo'] ?? '')));

if (!in_array($periodo, ['hoy', 'proximas', 'recientes'], true)) {
    if ($conteosPeriodo['hoy'] > 0) {
        $periodo = 'hoy';
    } elseif ($conteosPeriodo['proximas'] > 0) {
        $periodo = 'proximas';
    } else {
        $periodo = 'recientes';
    }
}

$sesionesVisibles = [];

foreach ($sesionesClase as $sesion) {
    $fecha = (string) $sesion['fecha_clase'];
    $coincide = (
        $periodo === 'hoy'
        && $fecha === $hoy
    ) || (
        $periodo === 'proximas'
        && $fecha > $hoy
    ) || (
        $periodo === 'recientes'
        && $fecha < $hoy
    );

    if ($coincide) {
        $sesionesVisibles[] = $sesion;
    }
}

$horarioSeleccionadoId = max(0, (int) ($_GET['horario'] ?? 0));
$fechaSeleccionada = trim((string) ($_GET['fecha'] ?? ''));
$sesionSeleccionada = null;

if (entrenador_fecha_valida($fechaSeleccionada)) {
    foreach ($sesionesClase as $sesion) {
        if (
            (int) $sesion['horario_id'] === $horarioSeleccionadoId
            && (string) $sesion['fecha_clase'] === $fechaSeleccionada
        ) {
            $sesionSeleccionada = $sesion;

            if ($fechaSeleccionada === $hoy) {
                $periodo = 'hoy';
            } elseif ($fechaSeleccionada > $hoy) {
                $periodo = 'proximas';
            } else {
                $periodo = 'recientes';
            }

            break;
        }
    }
}

if ($sesionSeleccionada === null && $sesionesVisibles !== []) {
    $sesionSeleccionada = $sesionesVisibles[0];
}

if ($sesionSeleccionada === null && $sesionesClase !== []) {
    $sesionSeleccionada = $sesionesClase[0];
    $fechaSesionInicial = (string) $sesionSeleccionada['fecha_clase'];

    if ($fechaSesionInicial === $hoy) {
        $periodo = 'hoy';
    } elseif ($fechaSesionInicial > $hoy) {
        $periodo = 'proximas';
    } else {
        $periodo = 'recientes';
    }
}

if (is_array($sesionSeleccionada)) {
    $horarioSeleccionadoId = (int) $sesionSeleccionada['horario_id'];
    $fechaSeleccionada = (string) $sesionSeleccionada['fecha_clase'];
}

$buscar = trim((string) ($_GET['buscar'] ?? ''));
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$porPagina = 10;
$participantes = [];
$totalParticipantes = 0;
$totalPaginas = 1;

/* =========================================================
   PARTICIPANTES DE LA SESIÓN SELECCIONADA
========================================================= */
if (is_array($sesionSeleccionada)) {
    $where = [
        'ic.clase_id = ?',
        'ic.fecha_clase = ?',
        "c.instructor_tipo = 'interno'",
        'c.instructor_usuario_id = ?',
    ];
    $params = [
        $claseSeleccionadaId,
        $fechaSeleccionada,
        $entrenadorId,
    ];
    $types = 'isi';

    if ($horarioSeleccionadoId > 0) {
        $where[] = 'ic.horario_id = ?';
        $params[] = $horarioSeleccionadoId;
        $types .= 'i';
    } else {
        $where[] = 'ic.horario_id IS NULL';
    }

    if ($buscar !== '') {
        $like = '%' . $buscar . '%';
        $where[] = "(
            COALESCE(cl.nombre, ic.nombre_externo, '') LIKE ?
            OR COALESCE(cl.apellido, ic.apellido_externo, '') LIKE ?
            OR COALESCE(cl.telefono, ic.telefono_externo, '') LIKE ?
            OR COALESCE(cl.email, ic.email_externo, '') LIKE ?
        )";

        for ($i = 0; $i < 4; $i++) {
            $params[] = $like;
            $types .= 's';
        }
    }

    $whereSql = implode(' AND ', $where);

    $stmtConteo = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM inscripciones_clases ic
         INNER JOIN clases c ON c.id = ic.clase_id
         LEFT JOIN clientes cl ON cl.id = ic.cliente_id
         WHERE $whereSql"
    );
    $paramsConteo = $params;
    entrenador_bind($stmtConteo, $types, $paramsConteo);
    $stmtConteo->execute();
    $totalParticipantes = (int) (
        $stmtConteo->get_result()->fetch_assoc()['total'] ?? 0
    );
    $stmtConteo->close();

    $totalPaginas = max(1, (int) ceil($totalParticipantes / $porPagina));
    $pagina = min($pagina, $totalPaginas);
    $offset = ($pagina - 1) * $porPagina;

    $stmtParticipantes = $conn->prepare(
        "SELECT
            ic.id,
            ic.clase_id,
            COALESCE(ic.horario_id, 0) AS horario_id,
            ic.fecha_clase,
            ic.tipo_participante,
            ic.estado,
            ic.asistencia,
            ic.fecha_ultima_asistencia,
            COALESCE(
                NULLIF(TRIM(CONCAT(cl.nombre, ' ', cl.apellido)), ''),
                NULLIF(TRIM(CONCAT(ic.nombre_externo, ' ', ic.apellido_externo)), ''),
                'Participante'
            ) AS participante_nombre,
            COALESCE(NULLIF(cl.telefono, ''), ic.telefono_externo, '') AS participante_telefono,
            COALESCE(NULLIF(cl.email, ''), ic.email_externo, '') AS participante_email
         FROM inscripciones_clases ic
         INNER JOIN clases c ON c.id = ic.clase_id
         LEFT JOIN clientes cl ON cl.id = ic.cliente_id
         WHERE $whereSql
         ORDER BY
            CASE ic.estado WHEN 'activa' THEN 0 ELSE 1 END,
            CASE
                WHEN COALESCE(ic.asistencia, 0) > 0
                 AND ic.fecha_ultima_asistencia = ic.fecha_clase
                THEN 1 ELSE 0
            END ASC,
            participante_nombre ASC
         LIMIT ? OFFSET ?"
    );

    $paramsLista = $params;
    $paramsLista[] = $porPagina;
    $paramsLista[] = $offset;
    $typesLista = $types . 'ii';
    entrenador_bind($stmtParticipantes, $typesLista, $paramsLista);
    $stmtParticipantes->execute();
    $participantes = $stmtParticipantes->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtParticipantes->close();
}

$flash = entrenador_flash_consumir();
$sesionEsFutura = is_array($sesionSeleccionada)
    && (string) $sesionSeleccionada['fecha_clase'] > $hoy;
$sesionEsHoy = is_array($sesionSeleccionada)
    && (string) $sesionSeleccionada['fecha_clase'] === $hoy;

$porcentajeAsistencia = 0;

if (is_array($sesionSeleccionada)) {
    $totalSesion = (int) $sesionSeleccionada['total_activos'];
    $presentesSesion = (int) $sesionSeleccionada['total_presentes'];

    if ($totalSesion > 0) {
        $porcentajeAsistencia = min(
            100,
            (int) round(($presentesSesion / $totalSesion) * 100)
        );
    }
}


/* =========================================================
   AGENDA SIMPLE: PENDIENTES Y SESIONES FINALIZADAS
========================================================= */
$sesionesPendientes = [];
$sesionesFinalizadas = [];

foreach ($sesionesClase as $sesionAgenda) {
    if ((string) $sesionAgenda['fecha_clase'] >= $hoy) {
        $sesionesPendientes[] = $sesionAgenda;
    } else {
        $sesionesFinalizadas[] = $sesionAgenda;
    }
}

$mostrarHistorial = (int) ($_GET['historial'] ?? 0) === 1
    || $periodo === 'recientes'
    || (
        is_array($sesionSeleccionada)
        && (string) $sesionSeleccionada['fecha_clase'] < $hoy
    )
    || ($sesionesPendientes === [] && $sesionesFinalizadas !== []);

$sesionesAgenda = $mostrarHistorial
    ? array_merge($sesionesPendientes, $sesionesFinalizadas)
    : $sesionesPendientes;

$horariosClaseTexto = is_array($claseSeleccionada)
    ? implode(' · ', (array) ($claseSeleccionada['horarios_lista'] ?? []))
    : '';

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi agenda de clases</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

    <?php $cssEntrenador = __DIR__ . '/css/panel_entrenador.css'; ?>
    <link rel="stylesheet" href="css/panel_entrenador.css?v=<?php echo is_file($cssEntrenador) ? (int) filemtime($cssEntrenador) : time(); ?>">
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>

<main class="main-content trainer-page">
    <header class="trainer-header-simple">
        <div>
            <span class="trainer-kicker"><i class="fas fa-chalkboard-user"></i> Panel del entrenador</span>
            <h1>Mis clases</h1>
            <p>Hola, <?php echo entrenador_h($entrenadorNombre); ?>. Primero elige una clase, después una fecha y finalmente registra la asistencia.</p>
        </div>
        <div class="trainer-header-pill">
            <strong><?php echo count($clasesAsignadas); ?></strong>
            <span><?php echo count($clasesAsignadas) === 1 ? 'clase asignada' : 'clases asignadas'; ?></span>
        </div>
    </header>

    <?php if ($clasesAsignadas === []): ?>
        <section class="trainer-empty-state">
            <span><i class="fas fa-dumbbell"></i></span>
            <h2>No tienes clases internas asignadas</h2>
            <p>Un administrador debe asignarte como entrenador interno dentro de la clase para que aparezca aquí.</p>
        </section>
    <?php else: ?>

    <section class="trainer-panel-card">
        <div class="trainer-panel-head">
            <div>
                <span class="trainer-step">Paso 1</span>
                <h2>Elige la clase</h2>
                <p>Tus clases se muestran como tarjetas. Haz clic en la que quieres abrir.</p>
            </div>
        </div>

        <div class="trainer-class-cards">
            <?php foreach ($clasesAsignadas as $clase): ?>
                <?php
                $idClase = (int) $clase['id'];
                $statsClase = $estadisticasPorClase[$idClase] ?? [
                    'sesiones' => 0,
                    'alumnos' => 0,
                    'hoy' => 0,
                    'proximas' => 0,
                    'recientes' => 0,
                ];
                $activaClase = $idClase === $claseSeleccionadaId;
                $urlClase = 'panel_entrenador.php?' . http_build_query(['clase' => $idClase]);
                ?>
                <a class="trainer-class-card <?php echo $activaClase ? 'active' : ''; ?> <?php echo (string) $clase['estado'] === 'inactiva' ? 'inactive' : ''; ?>" href="<?php echo entrenador_h($urlClase); ?>">
                    <div class="trainer-class-card-top">
                        <span class="trainer-class-icon"><i class="fas fa-dumbbell"></i></span>
                        <span class="trainer-class-badge <?php echo $activaClase ? 'selected' : ''; ?>"><?php echo $activaClase ? 'Abierta' : 'Abrir'; ?></span>
                    </div>
                    <h3><?php echo entrenador_h((string) $clase['nombre']); ?></h3>
                    <p><?php echo entrenador_h((string) ($clase['descripcion'] ?: 'Sin descripción')); ?></p>
                    <div class="trainer-class-inline-meta">
                        <span><i class="fas fa-location-dot"></i><?php echo entrenador_h((string) $clase['sucursal_nombre']); ?></span>
                        <span><i class="far fa-clock"></i><?php echo (int) $clase['duracion_minutos']; ?> min</span>
                        <span><i class="fas fa-users"></i>Cupo <?php echo (int) $clase['cupo_maximo']; ?></span>
                    </div>
                    <div class="trainer-class-stats">
                        <div><strong><?php echo (int) $statsClase['sesiones']; ?></strong><small>fechas</small></div>
                        <div><strong><?php echo (int) $statsClase['alumnos']; ?></strong><small>registros</small></div>
                        <div><strong><?php echo (int) $statsClase['hoy']; ?></strong><small>hoy</small></div>
                    </div>
                    <div class="trainer-class-schedule-line">
                        <i class="fas fa-calendar-days"></i>
                        <span><?php echo entrenador_h(!empty($clase['horarios_lista']) ? implode(' · ', array_slice((array) $clase['horarios_lista'], 0, 3)) : 'Horario general'); ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if (is_array($claseSeleccionada)): ?>
    <section class="trainer-open-class-card">
        <div class="trainer-open-class-header">
            <div>
                <span class="trainer-step">Clase abierta</span>
                <h2><?php echo entrenador_h((string) $claseSeleccionada['nombre']); ?></h2>
                <p><?php echo entrenador_h((string) ($claseSeleccionada['descripcion'] ?: 'Sin descripción')); ?></p>
            </div>
            <div class="trainer-open-class-quick">
                <span><i class="fas fa-location-dot"></i><?php echo entrenador_h((string) $claseSeleccionada['sucursal_nombre']); ?></span>
                <span><i class="far fa-clock"></i><?php echo (int) $claseSeleccionada['duracion_minutos']; ?> min</span>
                <span><i class="fas fa-users"></i><?php echo (int) $claseSeleccionada['cupo_maximo']; ?> cupo</span>
            </div>
        </div>
    </section>

    <section class="trainer-panel-card">
        <div class="trainer-panel-head trainer-panel-head-between">
            <div>
                <span class="trainer-step">Paso 2</span>
                <h2>Elige la fecha</h2>
                <p>Haz clic en la fecha para abrir la lista de alumnos de esa sesión.</p>
            </div>
            <?php if ($sesionesFinalizadas !== []): ?>
                <?php if ($mostrarHistorial): ?>
                    <a class="trainer-toggle-link" href="<?php echo entrenador_h('panel_entrenador.php?' . http_build_query(['clase' => $claseSeleccionadaId])); ?>">
                        <i class="fas fa-eye-slash"></i> Ocultar finalizadas
                    </a>
                <?php else: ?>
                    <a class="trainer-toggle-link" href="<?php echo entrenador_h('panel_entrenador.php?' . http_build_query(['clase' => $claseSeleccionadaId, 'historial' => 1])); ?>">
                        <i class="fas fa-clock-rotate-left"></i> Ver finalizadas (<?php echo count($sesionesFinalizadas); ?>)
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($sesionesAgenda === []): ?>
            <div class="trainer-empty-inline">
                <i class="fas fa-calendar-xmark"></i>
                <div>
                    <strong>No hay fechas con alumnos registrados</strong>
                    <p>Cuando administración inscriba alumnos, aparecerán aquí.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="trainer-date-cards">
                <?php foreach ($sesionesAgenda as $sesion): ?>
                    <?php
                    $fechaAgenda = (string) $sesion['fecha_clase'];
                    $esHoyAgenda = $fechaAgenda === $hoy;
                    $esFuturaAgenda = $fechaAgenda > $hoy;
                    $esFinalizadaAgenda = $fechaAgenda < $hoy;
                    $seleccionadaAgenda = is_array($sesionSeleccionada)
                        && (int) $sesionSeleccionada['clase_id'] === (int) $sesion['clase_id']
                        && (int) $sesionSeleccionada['horario_id'] === (int) $sesion['horario_id']
                        && (string) $sesionSeleccionada['fecha_clase'] === $fechaAgenda;
                    $totalAgenda = (int) $sesion['total_activos'];
                    $presentesAgenda = (int) $sesion['total_presentes'];
                    $urlAgenda = 'panel_entrenador.php?' . http_build_query([
                        'clase' => (int) $sesion['clase_id'],
                        'fecha' => $fechaAgenda,
                        'horario' => (int) $sesion['horario_id'],
                        'historial' => $mostrarHistorial ? 1 : 0,
                    ]) . '#listaAlumnos';
                    ?>
                    <a class="trainer-date-card <?php echo $seleccionadaAgenda ? 'active' : ''; ?> <?php echo $esFinalizadaAgenda ? 'finished' : ''; ?>" href="<?php echo entrenador_h($urlAgenda); ?>">
                        <div class="trainer-date-daybox <?php echo $esHoyAgenda ? 'today' : ''; ?>">
                            <strong><?php echo date('d', strtotime($fechaAgenda)); ?></strong>
                            <small><?php echo mb_strtoupper((string) date('M', strtotime($fechaAgenda))); ?></small>
                        </div>
                        <div class="trainer-date-copy">
                            <h3><?php echo entrenador_h(entrenador_fecha_texto($fechaAgenda)); ?></h3>
                            <p>
                                <i class="far fa-clock"></i>
                                <?php echo entrenador_h(entrenador_hora((string) ($sesion['hora_inicio'] ?? ''))); ?>
                                <?php if ((string) ($sesion['hora_fin'] ?? '') !== ''): ?>
                                    – <?php echo entrenador_h(entrenador_hora((string) $sesion['hora_fin'])); ?>
                                <?php endif; ?>
                            </p>
                            <div class="trainer-date-facts">
                                <span><?php echo $totalAgenda; ?> alumnos</span>
                                <span><?php echo $presentesAgenda; ?> presentes</span>
                            </div>
                        </div>
                        <div class="trainer-date-side">
                            <?php if ($esHoyAgenda): ?>
                                <span class="trainer-mini-status today">Hoy</span>
                            <?php elseif ($esFuturaAgenda): ?>
                                <span class="trainer-mini-status scheduled">Programada</span>
                            <?php else: ?>
                                <span class="trainer-mini-status finished">Finalizada</span>
                            <?php endif; ?>
                            <span class="trainer-date-open"><?php echo $seleccionadaAgenda ? 'Abierta' : 'Ver alumnos'; ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="trainer-roster-card" id="listaAlumnos">
        <?php if (!is_array($sesionSeleccionada)): ?>
            <div class="trainer-empty-state compact">
                <span><i class="fas fa-user-group"></i></span>
                <h2>Elige una fecha</h2>
                <p>La lista de alumnos aparecerá aquí cuando abras una fecha.</p>
            </div>
        <?php else: ?>
            <div class="trainer-roster-head-clean">
                <div>
                    <span class="trainer-step">Paso 3</span>
                    <h2>Alumnos registrados</h2>
                    <p>
                        <?php echo entrenador_h((string) $sesionSeleccionada['clase_nombre']); ?> ·
                        <?php echo entrenador_h(entrenador_fecha_texto((string) $sesionSeleccionada['fecha_clase'])); ?> ·
                        <?php echo entrenador_h(entrenador_hora((string) ($sesionSeleccionada['hora_inicio'] ?? ''))); ?>
                        <?php if ((string) ($sesionSeleccionada['hora_fin'] ?? '') !== ''): ?>
                            – <?php echo entrenador_h(entrenador_hora((string) $sesionSeleccionada['hora_fin'])); ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="trainer-roster-counter">
                    <strong><?php echo (int) $sesionSeleccionada['total_presentes']; ?>/<?php echo (int) $sesionSeleccionada['total_activos']; ?></strong>
                    <small>presentes</small>
                </div>
            </div>

            <div class="trainer-roster-toolbar" id="buscadorAlumnos">
                <div class="trainer-facts-line">
                    <span><i class="fas fa-location-dot"></i><?php echo entrenador_h((string) $sesionSeleccionada['sucursal_nombre']); ?></span>
                    <span><i class="fas fa-id-card"></i><?php echo (int) $sesionSeleccionada['total_socios']; ?> socios</span>
                    <span><i class="fas fa-user"></i><?php echo (int) $sesionSeleccionada['total_visitantes']; ?> visitantes</span>
                </div>

                <form method="GET" action="panel_entrenador.php#buscadorAlumnos" class="trainer-roster-tools" id="trainerSearchForm">
                    <input type="hidden" name="clase" value="<?php echo $claseSeleccionadaId; ?>">
                    <input type="hidden" name="fecha" value="<?php echo entrenador_h($fechaSeleccionada); ?>">
                    <input type="hidden" name="horario" value="<?php echo $horarioSeleccionadoId; ?>">
                    <?php if ($mostrarHistorial): ?>
                        <input type="hidden" name="historial" value="1">
                    <?php endif; ?>
                    <label>
                        <i class="fas fa-magnifying-glass"></i>
                        <input type="search" name="buscar" id="trainerSearchInput" value="<?php echo entrenador_h($buscar); ?>" placeholder="Buscar alumno o visitante" autocomplete="off">
                    </label>
                    <?php if ($buscar !== ''): ?>
                        <a href="<?php echo entrenador_h('panel_entrenador.php?' . http_build_query([
                            'clase' => $claseSeleccionadaId,
                            'fecha' => $fechaSeleccionada,
                            'horario' => $horarioSeleccionadoId,
                            'historial' => $mostrarHistorial ? 1 : 0,
                        ]) . '#buscadorAlumnos'); ?>" title="Limpiar búsqueda">
                            <i class="fas fa-xmark"></i>
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ($participantes === []): ?>
                <div class="trainer-empty-inline roster-empty">
                    <i class="fas fa-user-slash"></i>
                    <div>
                        <strong><?php echo $buscar !== '' ? 'No encontramos coincidencias' : 'No hay participantes'; ?></strong>
                        <p><?php echo $buscar !== '' ? 'Prueba con otro nombre, teléfono o correo.' : 'La sesión no tiene alumnos registrados.'; ?></p>
                    </div>
                </div>
            <?php else: ?>
                <div class="trainer-table-wrap">
                    <table class="trainer-roster-table" id="trainerRosterTable">
                        <thead>
                            <tr>
                                <th>Participante</th>
                                <th>Tipo</th>
                                <th>Contacto</th>
                                <th>Asistencia</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($participantes as $participante): ?>
                            <?php
                            $presente = (int) ($participante['asistencia'] ?? 0) > 0
                                && (string) ($participante['fecha_ultima_asistencia'] ?? '') === (string) ($participante['fecha_clase'] ?? '');
                            $activa = (string) $participante['estado'] === 'activa';
                            ?>
                            <tr class="<?php echo $presente ? 'is-present' : ''; ?>">
                                <td>
                                    <div class="trainer-participant-cell">
                                        <span><?php echo entrenador_h(mb_strtoupper(mb_substr((string) $participante['participante_nombre'], 0, 1))); ?></span>
                                        <div>
                                            <strong><?php echo entrenador_h((string) $participante['participante_nombre']); ?></strong>
                                            <small><?php echo $activa ? 'Registro activo' : ucfirst(entrenador_h((string) $participante['estado'])); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="trainer-type <?php echo $participante['tipo_participante'] === 'externo' ? 'visitor' : ''; ?>">
                                        <?php echo $participante['tipo_participante'] === 'externo' ? 'Visitante' : 'Socio'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="trainer-contact">
                                        <span><?php echo entrenador_h((string) ($participante['participante_telefono'] ?: 'Sin teléfono')); ?></span>
                                        <?php if ((string) $participante['participante_email'] !== ''): ?>
                                            <small><?php echo entrenador_h((string) $participante['participante_email']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($presente): ?>
                                        <span class="trainer-attendance present"><i class="fas fa-circle-check"></i>Presente</span>
                                    <?php else: ?>
                                        <span class="trainer-attendance pending"><i class="far fa-clock"></i>Pendiente</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$activa): ?>
                                        <button class="trainer-action muted" type="button" disabled><i class="fas fa-ban"></i><span>No activa</span></button>
                                    <?php elseif ($presente): ?>
                                        <button class="trainer-action done" type="button" disabled><i class="fas fa-check"></i><span>Registrada</span></button>
                                    <?php elseif ($sesionEsFutura): ?>
                                        <button class="trainer-action upcoming" type="button" disabled><i class="far fa-calendar"></i><span>Aún no disponible</span></button>
                                    <?php else: ?>
                                        <button class="trainer-action mark" type="button" onclick="registrarAsistenciaEntrenador(<?php echo (int) $participante['id']; ?>, this)">
                                            <i class="fas fa-calendar-check"></i><span>Marcar presente</span>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($totalPaginas > 1): ?>
                <div class="trainer-pagination-bar">
                    <span>Página <?php echo $pagina; ?> de <?php echo $totalPaginas; ?> · <?php echo $totalParticipantes; ?> registros</span>
                    <nav>
                        <?php
                        $basePagina = [
                            'clase' => $claseSeleccionadaId,
                            'fecha' => $fechaSeleccionada,
                            'horario' => $horarioSeleccionadoId,
                            'historial' => $mostrarHistorial ? 1 : 0,
                            'buscar' => $buscar,
                        ];
                        ?>
                        <a class="<?php echo $pagina <= 1 ? 'disabled' : ''; ?>" href="<?php echo entrenador_h('panel_entrenador.php?' . http_build_query(array_merge($basePagina, ['pagina' => max(1, $pagina - 1)])) . '#listaAlumnos'); ?>"><i class="fas fa-chevron-left"></i></a>
                        <?php for ($p = max(1, $pagina - 2); $p <= min($totalPaginas, $pagina + 2); $p++): ?>
                            <a class="<?php echo $p === $pagina ? 'active' : ''; ?>" href="<?php echo entrenador_h('panel_entrenador.php?' . http_build_query(array_merge($basePagina, ['pagina' => $p])) . '#listaAlumnos'); ?>"><?php echo $p; ?></a>
                        <?php endfor; ?>
                        <a class="<?php echo $pagina >= $totalPaginas ? 'disabled' : ''; ?>" href="<?php echo entrenador_h('panel_entrenador.php?' . http_build_query(array_merge($basePagina, ['pagina' => min($totalPaginas, $pagina + 1)])) . '#listaAlumnos'); ?>"><i class="fas fa-chevron-right"></i></a>
                    </nav>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php endif; ?>
</main>

<?php if (is_array($sesionSeleccionada)): ?>
<form method="POST" id="trainerAttendanceForm" class="d-none">
    <input type="hidden" name="action" value="registrar_asistencia">
    <input type="hidden" name="csrf_token" value="<?php echo entrenador_h($csrf); ?>">
    <input type="hidden" name="inscripcion_id" value="">
    <input type="hidden" name="clase_id" value="<?php echo $claseSeleccionadaId; ?>">
    <input type="hidden" name="fecha_clase" value="<?php echo entrenador_h($fechaSeleccionada); ?>">
    <input type="hidden" name="horario_id" value="<?php echo $horarioSeleccionadoId; ?>">
    <?php if ($mostrarHistorial): ?><input type="hidden" name="historial" value="1"><?php endif; ?>
    <input type="hidden" name="buscar" value="<?php echo entrenador_h($buscar); ?>">
    <input type="hidden" name="pagina" value="<?php echo $pagina; ?>">
</form>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php if (is_array($flash)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const data = <?php echo json_encode(
        $flash,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    ); ?>;
    const ok = data.icon === 'success';

    Swal.fire({
        icon: data.icon || 'info',
        title: data.title || 'Información',
        text: data.message || '',
        confirmButtonColor: '#1e3a8a',
        confirmButtonText: 'Entendido',
        timer: ok ? 2800 : undefined,
        timerProgressBar: ok,
        showConfirmButton: !ok
    });
});
</script>
<?php endif; ?>

<script>
(function () {
    const searchForm = document.getElementById('trainerSearchForm');
    const searchInput = document.getElementById('trainerSearchInput');
    let timer;

    if (searchForm && searchInput) {
        searchInput.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                const params = new URLSearchParams(
                    new FormData(searchForm)
                );

                window.location.assign(
                    'panel_entrenador.php?'
                    + params.toString()
                    + '#buscadorAlumnos'
                );
            }, 450);
        });

        if (
            window.location.hash === '#buscadorAlumnos'
            && searchInput.value.trim() !== ''
        ) {
            window.setTimeout(function () {
                searchInput.focus({ preventScroll: true });
                searchInput.setSelectionRange(
                    searchInput.value.length,
                    searchInput.value.length
                );
            }, 80);
        }
    }

    const table = document.getElementById('trainerRosterTable');

    if (table) {
        const labels = Array.from(table.querySelectorAll('thead th')).map(function (th) {
            return th.textContent.trim();
        });

        table.querySelectorAll('tbody tr').forEach(function (row) {
            row.querySelectorAll('td').forEach(function (cell, index) {
                cell.dataset.label = labels[index] || '';
            });
        });
    }

    window.registrarAsistenciaEntrenador = function (id, button) {
        Swal.fire({
            icon: 'question',
            title: '¿Marcar como presente?',
            text: 'La asistencia quedará registrada para esta sesión.',
            showCancelButton: true,
            confirmButtonText: 'Sí, marcar presente',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#10b981',
            cancelButtonColor: '#64748b'
        }).then(function (result) {
            if (!result.isConfirmed) return;

            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Guardando</span>';
            }

            const form = document.getElementById('trainerAttendanceForm');
            if (!form) return;
            form.elements.inscripcion_id.value = String(id);
            form.submit();
        });
    };
})();
</script>
</body>
</html>