<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/clases_context.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$database = new Database();
$conn = $database->getConnection();

if (!$conn instanceof mysqli) {
    die('Error: No se pudo establecer la conexión a la base de datos');
}

$conn->set_charset('utf8mb4');

$usuario_id = (int) $_SESSION['user_id'];
$usuario_nombre = (string) (
    $_SESSION['user_name'] ?? 'Usuario'
);
$usuario_rol = clases_rol_base();

try {
    $contexto = clases_contexto(
        $conn,
        $usuario_id
    );
} catch (Throwable $errorContexto) {
    die(clases_h($errorContexto->getMessage()));
}

$vista_global = (bool) $contexto['vista_global'];
$sucursal_id = (int) $contexto['sucursal_id'];
$sucursal_nombre = (string) (
    $contexto['sucursal_nombre']
);
$sucursal_clave = (string) (
    $contexto['sucursal_clave']
);
$total_sedes = (int) $contexto['total_sedes'];

$swal_clases = clases_swal_consumir();

/**
 * Días normalizados para los horarios de las clases.
 */
function clases_dias_semana(): array
{
    return [
        1 => ['corto' => 'Lun', 'nombre' => 'Lunes'],
        2 => ['corto' => 'Mar', 'nombre' => 'Martes'],
        3 => ['corto' => 'Mié', 'nombre' => 'Miércoles'],
        4 => ['corto' => 'Jue', 'nombre' => 'Jueves'],
        5 => ['corto' => 'Vie', 'nombre' => 'Viernes'],
        6 => ['corto' => 'Sáb', 'nombre' => 'Sábado'],
        7 => ['corto' => 'Dom', 'nombre' => 'Domingo'],
    ];
}

function clases_hora_valida(string $hora): bool
{
    $fecha = DateTimeImmutable::createFromFormat('!H:i', $hora);

    return $fecha instanceof DateTimeImmutable
        && $fecha->format('H:i') === $hora;
}

/**
 * Recibe filas dinámicas de horario y evita traslapes en el mismo día.
 * Todas las sesiones de una clase deben conservar la misma duración.
 */
function clases_horarios_desde_post(): array
{
    $dias = $_POST['horario_dia'] ?? [];
    $inicios = $_POST['horario_inicio'] ?? [];
    $fines = $_POST['horario_fin'] ?? [];

    if (!is_array($dias) || !is_array($inicios) || !is_array($fines)) {
        throw new RuntimeException('El formato de los horarios es inválido.');
    }

    if ($dias === [] || count($dias) !== count($inicios) || count($dias) !== count($fines)) {
        throw new RuntimeException('Agrega al menos un horario completo para la clase.');
    }

    $filas = [];
    $duracionBase = null;
    $ocupados = [];

    foreach ($dias as $indice => $diaRaw) {
        $dia = (int) $diaRaw;
        $inicio = trim((string) ($inicios[$indice] ?? ''));
        $fin = trim((string) ($fines[$indice] ?? ''));

        if ($dia < 1 || $dia > 7 || !clases_hora_valida($inicio) || !clases_hora_valida($fin)) {
            throw new RuntimeException('Revisa los días y horas seleccionados.');
        }

        $inicioMinutos = ((int) substr($inicio, 0, 2) * 60) + (int) substr($inicio, 3, 2);
        $finMinutos = ((int) substr($fin, 0, 2) * 60) + (int) substr($fin, 3, 2);

        if ($finMinutos <= $inicioMinutos) {
            throw new RuntimeException('La hora de término debe ser posterior a la hora de inicio.');
        }

        $duracion = $finMinutos - $inicioMinutos;

        if ($duracionBase === null) {
            $duracionBase = $duracion;
        } elseif ($duracion !== $duracionBase) {
            throw new RuntimeException('Todos los horarios de la misma clase deben tener la misma duración.');
        }

        foreach ($ocupados[$dia] ?? [] as $rango) {
            if ($inicioMinutos < $rango['fin'] && $finMinutos > $rango['inicio']) {
                throw new RuntimeException('Existen horarios traslapados para el mismo día.');
            }
        }

        $ocupados[$dia][] = [
            'inicio' => $inicioMinutos,
            'fin' => $finMinutos,
        ];

        $filas[] = [
            'dia_semana' => $dia,
            'hora_inicio' => $inicio . ':00',
            'hora_fin' => $fin . ':00',
            'duracion' => $duracion,
        ];
    }

    usort(
        $filas,
        static fn (array $a, array $b): int => [
            $a['dia_semana'],
            $a['hora_inicio'],
        ] <=> [
            $b['dia_semana'],
            $b['hora_inicio'],
        ]
    );

    return $filas;
}

function clases_resumen_horarios(array $horarios): string
{
    $dias = clases_dias_semana();
    $partes = [];

    foreach ($horarios as $horario) {
        $dia = (int) $horario['dia_semana'];
        $inicio = substr((string) $horario['hora_inicio'], 0, 5);
        $fin = substr((string) $horario['hora_fin'], 0, 5);
        $partes[] = ($dias[$dia]['corto'] ?? 'Día') . ' ' . $inicio . '-' . $fin;
    }

    return implode(' · ', $partes);
}

function clases_guardar_horarios(mysqli $conn, int $claseId, array $horarios): void
{
    $stmtDelete = $conn->prepare(
        'DELETE FROM clases_horarios WHERE clase_id = ?'
    );
    $stmtDelete->bind_param('i', $claseId);
    $stmtDelete->execute();
    $stmtDelete->close();

    $stmtInsert = $conn->prepare(
        "INSERT INTO clases_horarios (
            clase_id,
            dia_semana,
            hora_inicio,
            hora_fin,
            estado
         ) VALUES (?, ?, ?, ?, 'activo')"
    );

    foreach ($horarios as $horario) {
        $dia = (int) $horario['dia_semana'];
        $inicio = (string) $horario['hora_inicio'];
        $fin = (string) $horario['hora_fin'];

        $stmtInsert->bind_param(
            'iiss',
            $claseId,
            $dia,
            $inicio,
            $fin
        );
        $stmtInsert->execute();
    }

    $stmtInsert->close();
}

/**
 * Resuelve un entrenador interno o externo. Si se selecciona "nuevo",
 * registra al entrenador externo dentro de la misma transacción de la clase.
 */
function clases_resolver_entrenador(
    mysqli $conn,
    int $sucursalId
): array {
    $tipo = trim((string) ($_POST['instructor_tipo'] ?? 'interno'));

    if ($tipo === 'interno') {
        $usuarioId = (int) ($_POST['instructor_usuario_id'] ?? 0);

        if ($usuarioId <= 0) {
            throw new RuntimeException('Selecciona un entrenador del sistema.');
        }

        $entrenador = clases_validar_entrenador(
            $conn,
            $sucursalId,
            $usuarioId
        );

        return [
            'tipo' => 'interno',
            'nombre' => trim((string) $entrenador['nombre']),
            'usuario_id' => $usuarioId,
            'externo_id' => null,
        ];
    }

    if ($tipo !== 'externo') {
        throw new RuntimeException('El tipo de entrenador seleccionado no es válido.');
    }

    $externoSeleccionado = trim((string) ($_POST['entrenador_externo_id'] ?? ''));

    if ($externoSeleccionado !== '' && $externoSeleccionado !== 'nuevo') {
        $externoId = (int) $externoSeleccionado;
        $stmt = $conn->prepare(
            "SELECT id, nombre
             FROM entrenadores_externos
             WHERE id = ?
               AND sucursal_id = ?
               AND estado = 'activo'
             LIMIT 1"
        );
        $stmt->bind_param('ii', $externoId, $sucursalId);
        $stmt->execute();
        $externo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$externo) {
            throw new RuntimeException('El entrenador externo seleccionado no está disponible.');
        }

        return [
            'tipo' => 'externo',
            'nombre' => trim((string) $externo['nombre']),
            'usuario_id' => null,
            'externo_id' => $externoId,
        ];
    }

    $nombre = trim((string) ($_POST['entrenador_externo_nombre'] ?? ''));
    $email = trim((string) ($_POST['entrenador_externo_email'] ?? ''));
    $telefono = trim((string) ($_POST['entrenador_externo_telefono'] ?? ''));

    if ($nombre === '') {
        throw new RuntimeException('Escribe el nombre del entrenador externo.');
    }

    if (mb_strlen($nombre) > 120) {
        throw new RuntimeException('El nombre del entrenador externo es demasiado largo.');
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('El correo del entrenador externo no es válido.');
    }

    if (mb_strlen($telefono) > 25) {
        throw new RuntimeException('El número celular del entrenador externo es demasiado largo.');
    }

    $stmt = $conn->prepare(
        "INSERT INTO entrenadores_externos (
            sucursal_id,
            nombre,
            email,
            telefono,
            estado
         ) VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), 'activo')"
    );
    $stmt->bind_param(
        'isss',
        $sucursalId,
        $nombre,
        $email,
        $telefono
    );
    $stmt->execute();
    $externoId = (int) $conn->insert_id;
    $stmt->close();

    return [
        'tipo' => 'externo',
        'nombre' => $nombre,
        'usuario_id' => null,
        'externo_id' => $externoId,
    ];
}

function clases_horarios_detalle(string $detalle, string $legacy = ''): array
{
    if ($detalle === '') {
        return $legacy !== ''
            ? [['legacy' => $legacy]]
            : [];
    }

    $dias = clases_dias_semana();
    $resultado = [];

    foreach (explode(';;', $detalle) as $fila) {
        $partes = explode('|', $fila);

        if (count($partes) !== 3) {
            continue;
        }

        $dia = (int) $partes[0];
        $resultado[] = [
            'dia' => $dias[$dia]['corto'] ?? 'Día',
            'inicio' => $partes[1],
            'fin' => $partes[2],
        ];
    }

    return $resultado;
}

$entrenadores = $vista_global
    ? []
    : clases_entrenadores_sucursal(
        $conn,
        $sucursal_id
    );

$entrenadores_externos = [];

if (!$vista_global) {
    $stmtExternos = $conn->prepare(
        "SELECT id, nombre, email, telefono
         FROM entrenadores_externos
         WHERE sucursal_id = ?
           AND estado = 'activo'
         ORDER BY nombre ASC"
    );
    $stmtExternos->bind_param('i', $sucursal_id);
    $stmtExternos->execute();
    $entrenadores_externos = $stmtExternos
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);
    $stmtExternos->close();
}

$hay_entrenadores = $entrenadores !== [];

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
) {
    try {
        $sucursal_operativa = clases_exigir_sucursal($contexto);
        $action = trim((string) $_POST['action']);
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
        $precioTexto = str_replace(',', '.', trim((string) ($_POST['precio_clase'] ?? '')));
        $cupo_maximo = (int) ($_POST['cupo_maximo'] ?? 0);
        $horarios = clases_horarios_desde_post();
        $horario = clases_resumen_horarios($horarios);
        $duracion_minutos = (int) $horarios[0]['duracion'];

        if ($nombre === '' || mb_strlen($nombre) > 100) {
            throw new RuntimeException('Escribe un nombre válido para la clase.');
        }

        if ($precioTexto === '' || !is_numeric($precioTexto)) {
            throw new RuntimeException('Escribe un precio válido para la clase.');
        }

        $precio_clase = round((float) $precioTexto, 2);

        if ($precio_clase < 0 || $precio_clase > 99999999.99) {
            throw new RuntimeException('El precio de la clase está fuera del rango permitido.');
        }

        if ($cupo_maximo <= 0 || $cupo_maximo > 10000) {
            throw new RuntimeException('El cupo máximo de la clase no es válido.');
        }

        if (!in_array($action, ['crear_clase', 'editar_clase'], true)) {
            throw new RuntimeException('La acción solicitada no es válida.');
        }

        $conn->begin_transaction();

        $entrenador = clases_resolver_entrenador(
            $conn,
            $sucursal_operativa
        );

        $instructor = (string) $entrenador['nombre'];
        $instructor_tipo = (string) $entrenador['tipo'];
        $instructor_usuario_id = $entrenador['usuario_id'];
        $entrenador_externo_id = $entrenador['externo_id'];

        if ($action === 'crear_clase') {
            $stmt = $conn->prepare(
                "INSERT INTO clases (
                    sucursal_id,
                    nombre,
                    descripcion,
                    precio_clase,
                    horario,
                    instructor,
                    instructor_tipo,
                    instructor_usuario_id,
                    entrenador_externo_id,
                    cupo_maximo,
                    cupo_actual,
                    duracion_minutos,
                    estado
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'activa')"
            );
            $stmt->bind_param(
                'issdsssiiii',
                $sucursal_operativa,
                $nombre,
                $descripcion,
                $precio_clase,
                $horario,
                $instructor,
                $instructor_tipo,
                $instructor_usuario_id,
                $entrenador_externo_id,
                $cupo_maximo,
                $duracion_minutos
            );
            $stmt->execute();
            $clase_id = (int) $conn->insert_id;
            $stmt->close();

            clases_guardar_horarios($conn, $clase_id, $horarios);
            $conn->commit();

            clases_swal_guardar(
                'success',
                'Clase creada',
                'La clase se registró con un precio de $'
                . number_format($precio_clase, 2)
                . '. Los socios con membresía vigente no pagarán este importe.'
            );
        } else {
            $clase_id = (int) ($_POST['clase_id'] ?? 0);
            $estado = trim((string) ($_POST['estado'] ?? 'activa'));

            if ($clase_id <= 0 || !in_array($estado, ['activa', 'inactiva'], true)) {
                throw new RuntimeException('Los datos de la clase son inválidos.');
            }

            $stmtActual = $conn->prepare(
                "SELECT cupo_actual
                 FROM clases
                 WHERE id = ?
                   AND sucursal_id = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmtActual->bind_param('ii', $clase_id, $sucursal_operativa);
            $stmtActual->execute();
            $claseActual = $stmtActual->get_result()->fetch_assoc();
            $stmtActual->close();

            if (!$claseActual) {
                throw new RuntimeException('La clase no pertenece a la sucursal seleccionada.');
            }

            if ($cupo_maximo < (int) $claseActual['cupo_actual']) {
                throw new RuntimeException('El cupo máximo no puede ser menor al número de personas inscritas.');
            }

            $stmt = $conn->prepare(
                "UPDATE clases
                 SET nombre = ?,
                     descripcion = ?,
                     precio_clase = ?,
                     horario = ?,
                     instructor = ?,
                     instructor_tipo = ?,
                     instructor_usuario_id = ?,
                     entrenador_externo_id = ?,
                     cupo_maximo = ?,
                     duracion_minutos = ?,
                     estado = ?
                 WHERE id = ?
                   AND sucursal_id = ?"
            );
            $stmt->bind_param(
                'ssdsssiiiisii',
                $nombre,
                $descripcion,
                $precio_clase,
                $horario,
                $instructor,
                $instructor_tipo,
                $instructor_usuario_id,
                $entrenador_externo_id,
                $cupo_maximo,
                $duracion_minutos,
                $estado,
                $clase_id,
                $sucursal_operativa
            );
            $stmt->execute();
            $stmt->close();

            clases_guardar_horarios($conn, $clase_id, $horarios);
            $conn->commit();

            clases_swal_guardar(
                'success',
                'Clase actualizada',
                'El precio, entrenador y horarios se actualizaron correctamente.'
            );
        }

        clases_redirect('clases.php', $contexto);
    } catch (Throwable $error) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
        }

        clases_swal_guardar(
            'error',
            'No fue posible guardar la clase',
            $error->getMessage()
        );
        clases_redirect('clases.php', $contexto);
    }
}

if (
    isset($_GET['eliminar'])
    && ctype_digit((string) $_GET['eliminar'])
) {
    try {
        $sucursal_operativa = clases_exigir_sucursal(
            $contexto
        );

        $clase_id = (int) $_GET['eliminar'];

        $conn->begin_transaction();

        $stmtClase = $conn->prepare(
            "SELECT id
             FROM clases
             WHERE id = ?
               AND sucursal_id = ?
             LIMIT 1
             FOR UPDATE"
        );

        $stmtClase->bind_param(
            'ii',
            $clase_id,
            $sucursal_operativa
        );

        $stmtClase->execute();

        $claseExiste = $stmtClase
            ->get_result()
            ->fetch_assoc();

        $stmtClase->close();

        if (!$claseExiste) {
            throw new RuntimeException(
                'La clase no pertenece a la sucursal seleccionada.'
            );
        }

        $stmtCount = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM inscripciones_clases
             WHERE clase_id = ?"
        );

        $stmtCount->bind_param(
            'i',
            $clase_id
        );

        $stmtCount->execute();

        $totalInscripciones = (int) (
            $stmtCount
                ->get_result()
                ->fetch_assoc()['total']
            ?? 0
        );

        $stmtCount->close();

        if ($totalInscripciones > 0) {
            throw new RuntimeException(
                'No se puede eliminar la clase porque tiene inscripciones asociadas.'
            );
        }

        $stmtDelete = $conn->prepare(
            "DELETE FROM clases
             WHERE id = ?
               AND sucursal_id = ?"
        );

        $stmtDelete->bind_param(
            'ii',
            $clase_id,
            $sucursal_operativa
        );

        $stmtDelete->execute();

        if ($stmtDelete->affected_rows !== 1) {
            throw new RuntimeException(
                'No se pudo eliminar la clase.'
            );
        }

        $stmtDelete->close();
        $conn->commit();

        clases_swal_guardar(
            'success',
            'Clase eliminada',
            'La clase se eliminó correctamente.'
        );
    } catch (Throwable $error) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
        }

        clases_swal_guardar(
            'error',
            'No fue posible eliminar la clase',
            $error->getMessage()
        );
    }

    clases_redirect('clases.php', $contexto);
}

$search = trim((string) (
    $_GET['search'] ?? ''
));
$estado = trim((string) (
    $_GET['estado'] ?? ''
));
$page = max(
    1,
    (int) ($_GET['page'] ?? 1)
);
$sort = (string) (
    $_GET['sort'] ?? 'id'
);
$order = strtoupper((string) (
    $_GET['order'] ?? 'DESC'
));
$limit = 10;

if (
    !in_array(
        $estado,
        ['', 'activa', 'inactiva'],
        true
    )
) {
    $estado = '';
}

$sort_columns = [
    'nombre' => 'c.nombre',
    'sucursal' => 's.nombre',
    'instructor' => 'c.instructor',
    'horario' => 'c.horario',
    'precio' => 'c.precio_clase',
    'cupo' => 'c.cupo_maximo',
    'duracion' => 'c.duracion_minutos',
    'estado' => 'c.estado',
    'id' => 'c.id',
];

$order_by = $sort_columns[$sort] ?? 'c.id';
$order_dir = $order === 'ASC'
    ? 'ASC'
    : 'DESC';

$where = [];
$params = [];
$types = '';

if ($vista_global) {
    $ids = $contexto['sucursales_ids'];
    $marks = implode(
        ',',
        array_fill(0, count($ids), '?')
    );

    $where[] = "c.sucursal_id IN ($marks)";

    foreach ($ids as $idSede) {
        $params[] = (int) $idSede;
        $types .= 'i';
    }
} else {
    $where[] = 'c.sucursal_id = ?';
    $params[] = $sucursal_id;
    $types .= 'i';
}

if ($search !== '') {
    $searchParam = '%' . $search . '%';

    $where[] = "(
        c.nombre LIKE ?
        OR c.instructor LIKE ?
        OR c.horario LIKE ?
        OR s.nombre LIKE ?
        OR s.clave LIKE ?
    )";

    for ($i = 0; $i < 5; $i++) {
        $params[] = $searchParam;
        $types .= 's';
    }
}

if ($estado !== '') {
    $where[] = 'c.estado = ?';
    $params[] = $estado;
    $types .= 's';
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$countSql = "
    SELECT COUNT(*) AS total
    FROM clases c
    INNER JOIN sucursales s
        ON s.id = c.sucursal_id
    $whereSql
";

$stmtCount = $conn->prepare($countSql);
$paramsCount = $params;
clases_bind(
    $stmtCount,
    $types,
    $paramsCount
);
$stmtCount->execute();

$total_rows = (int) (
    $stmtCount
        ->get_result()
        ->fetch_assoc()['total']
    ?? 0
);

$stmtCount->close();

$total_pages = max(
    1,
    (int) ceil($total_rows / $limit)
);

$page = min($page, $total_pages);
$offset = ($page - 1) * $limit;

$query = "
    SELECT
        c.*,
        (
            SELECT GROUP_CONCAT(
                CONCAT(
                    ch.dia_semana,
                    '|',
                    TIME_FORMAT(ch.hora_inicio, '%H:%i'),
                    '|',
                    TIME_FORMAT(ch.hora_fin, '%H:%i')
                )
                ORDER BY ch.dia_semana, ch.hora_inicio
                SEPARATOR ';;'
            )
            FROM clases_horarios ch
            WHERE ch.clase_id = c.id
              AND ch.estado = 'activo'
        ) AS horarios_detalle,
        s.nombre AS sucursal_nombre,
        s.clave AS sucursal_clave,
        s.es_matriz AS sucursal_es_matriz
    FROM clases c
    INNER JOIN sucursales s
        ON s.id = c.sucursal_id
    $whereSql
    ORDER BY $order_by $order_dir
    LIMIT ? OFFSET ?
";

$paramsQuery = $params;
$paramsQuery[] = $limit;
$paramsQuery[] = $offset;
$typesQuery = $types . 'ii';

$stmt = $conn->prepare($query);
clases_bind(
    $stmt,
    $typesQuery,
    $paramsQuery
);
$stmt->execute();

$clases = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

$vista_param = $vista_global
    ? 'global'
    : 'sucursal';

$baseQuery = [
    'vista' => $vista_param,
    'search' => $search,
    'estado' => $estado,
    'sort' => $sort,
    'order' => $order_dir,
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Clases - Sistema Gimnasio</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
    >
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css"
    >

    <?php
    $clasesCss = __DIR__ . '/css/clases.css';
    $clasesMultiCss =
        __DIR__ . '/css/clases_multisucursal.css';
    ?>
    <link
        rel="stylesheet"
        href="css/clases.css?v=<?php echo is_file($clasesCss) ? (int) filemtime($clasesCss) : time(); ?>"
    >
    <link
        rel="stylesheet"
        href="css/clases_multisucursal.css?v=<?php echo is_file($clasesMultiCss) ? (int) filemtime($clasesMultiCss) : time(); ?>"
    >
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header class-context-header">
            <div>
                <h2>Gestión de Clases</h2>
                <p>
                    <?php echo $vista_global
                        ? 'Consulta horarios, instructores y cupos de todas las sucursales.'
                        : 'Administra las clases disponibles en ' . clases_h($sucursal_nombre) . '.';
                    ?>
                </p>
            </div>

            <div class="class-header-actions">
                <span class="class-context-badge <?php echo $vista_global ? 'global' : 'branch'; ?>">
                    <i class="fas <?php echo $vista_global ? 'fa-chart-pie' : 'fa-building'; ?>"></i>

                    <span>
                        <strong>
                            <?php echo clases_h($sucursal_nombre); ?>
                        </strong>
                        <small>
                            <?php echo clases_h(
                                $vista_global
                                    ? $total_sedes . (
                                        $total_sedes === 1
                                            ? ' sede consolidada'
                                            : ' sedes consolidadas'
                                    )
                                    : (
                                        $sucursal_clave !== ''
                                            ? $sucursal_clave
                                            : 'Sucursal activa'
                                    )
                            ); ?>
                        </small>
                    </span>
                </span>

                <?php if (!$vista_global): ?>
                    <button
                        class="btn-custom-primary"
                        type="button"
                        data-bs-toggle="modal"
                        data-bs-target="#modalNuevaClase"
                    >
                        <i class="fas fa-plus-circle"></i>
                        Nueva Clase
                    </button>
                <?php else: ?>
                    <button
                        class="btn-custom-primary is-disabled"
                        type="button"
                        disabled
                        title="Selecciona una sucursal para registrar una clase"
                    >
                        <i class="fas fa-lock"></i>
                        Selecciona una sucursal
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="card-custom">
            <div class="card-header-custom card-header-flex">
                <span>
                    <i class="fas fa-filter"></i>
                    Filtros de búsqueda
                </span>

                <button
                    type="button"
                    class="btn-limpiar"
                    id="limpiarFiltros"
                >
                    <i class="fas fa-eraser"></i>
                    Limpiar
                </button>
            </div>

            <div class="card-body-custom">
                <input
                    type="hidden"
                    id="vistaActual"
                    value="<?php echo clases_h($vista_param); ?>"
                >

                <div class="row">
                    <div class="col-lg-8 mb-3 mb-lg-0">
                        <label class="form-label">
                            Buscar
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            id="searchInput"
                            placeholder="<?php echo $vista_global ? 'Clase, instructor, horario o sucursal...' : 'Nombre, instructor o horario...'; ?>"
                            value="<?php echo clases_h($search); ?>"
                        >
                    </div>

                    <div class="col-lg-4">
                        <label class="form-label">
                            Estado
                        </label>

                        <select
                            class="form-select"
                            id="estadoSelect"
                        >
                            <option value="">Todos</option>
                            <option
                                value="activa"
                                <?php echo $estado === 'activa' ? 'selected' : ''; ?>
                            >
                                Activa
                            </option>
                            <option
                                value="inactiva"
                                <?php echo $estado === 'inactiva' ? 'selected' : ''; ?>
                            >
                                Inactiva
                            </option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-custom">
            <div class="card-header-custom card-header-flex">
                <span>
                    <i class="fas fa-chalkboard-user"></i>
                    Listado de clases
                </span>

                <span class="contador-registros">
                    <?php echo number_format($total_rows); ?>
                    <?php echo $total_rows === 1 ? 'clase' : 'clases'; ?>
                </span>
            </div>

            <div
                class="card-body-custom"
                style="padding:0;"
            >
                <div class="table-responsive-custom">
                    <table class="table-simple responsive-table <?php echo $vista_global ? 'is-global' : ''; ?>">
                        <thead>
                            <tr>
                                <th>Clase</th>
                                <?php if ($vista_global): ?>
                                    <th>Sucursal</th>
                                <?php endif; ?>
                                <th>Entrenador</th>
                                <th>Horarios</th>
                                <th>
                                    <a href="<?php echo clases_h(clases_url('clases.php', array_merge($baseQuery, [
                                        'sort' => 'precio',
                                        'order' => $sort === 'precio' && $order_dir === 'ASC' ? 'DESC' : 'ASC',
                                    ]))); ?>">
                                        Precio
                                    </a>
                                </th>
                                <th>Cupo</th>
                                <th>Duración</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($clases as $clase): ?>
                                <?php
                                $cupoMaximo = max(1, (int) $clase['cupo_maximo']);
                                $cupoActual = max(0, (int) $clase['cupo_actual']);
                                $cupoDisponible = max(0, $cupoMaximo - $cupoActual);
                                $porcentajeOcupado = ($cupoActual / $cupoMaximo) * 100;
                                $horariosClase = clases_horarios_detalle(
                                    (string) ($clase['horarios_detalle'] ?? ''),
                                    (string) ($clase['horario'] ?? '')
                                );

                                if ($cupoDisponible === 0) {
                                    $cupoClass = 'text-danger';
                                    $cupoTexto = 'Completo';
                                } elseif ($porcentajeOcupado >= 80) {
                                    $cupoClass = 'text-warning';
                                    $cupoTexto = $cupoDisponible . ' disponibles';
                                } else {
                                    $cupoClass = 'text-success';
                                    $cupoTexto = $cupoDisponible . ' disponibles';
                                }
                                ?>
                                <tr>
                                    <td>
                                        <div class="class-name-cell">
                                            <strong><?php echo clases_h($clase['nombre']); ?></strong>
                                            <small><?php echo clases_h(mb_substr((string) $clase['descripcion'], 0, 70)); ?></small>
                                        </div>
                                    </td>

                                    <?php if ($vista_global): ?>
                                        <td>
                                            <span class="class-branch-pill">
                                                <i class="fas fa-building"></i>
                                                <span>
                                                    <strong><?php echo clases_h($clase['sucursal_nombre']); ?></strong>
                                                    <small>
                                                        <?php echo clases_h($clase['sucursal_clave']); ?>
                                                        <?php echo (int) $clase['sucursal_es_matriz'] === 1 ? ' · Matriz' : ''; ?>
                                                    </small>
                                                </span>
                                            </span>
                                        </td>
                                    <?php endif; ?>

                                    <td>
                                        <div class="trainer-cell">
                                            <span class="trainer-avatar">
                                                <i class="fas <?php echo $clase['instructor_tipo'] === 'externo' ? 'fa-user-plus' : 'fa-user-shield'; ?>"></i>
                                            </span>
                                            <span>
                                                <strong><?php echo clases_h($clase['instructor']); ?></strong>
                                                <small><?php echo $clase['instructor_tipo'] === 'externo' ? 'Entrenador externo' : 'Personal del sistema'; ?></small>
                                            </span>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="schedule-pills">
                                            <?php foreach ($horariosClase as $horarioClase): ?>
                                                <?php if (isset($horarioClase['legacy'])): ?>
                                                    <span class="schedule-pill legacy">
                                                        <i class="far fa-clock"></i>
                                                        <?php echo clases_h($horarioClase['legacy']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="schedule-pill">
                                                        <strong><?php echo clases_h($horarioClase['dia']); ?></strong>
                                                        <?php echo clases_h($horarioClase['inicio'] . '–' . $horarioClase['fin']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>

                                    <td>
                                        <div class="class-price-cell">
                                            <strong>$<?php echo number_format((float) $clase['precio_clase'], 2); ?></strong>
                                            <small>Membresía activa: incluido</small>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="<?php echo $cupoClass; ?>">
                                            <strong><?php echo $cupoActual; ?>/<?php echo $cupoMaximo; ?></strong>
                                            <small class="d-block"><?php echo clases_h($cupoTexto); ?></small>
                                        </span>
                                    </td>

                                    <td><?php echo (int) $clase['duracion_minutos']; ?> min</td>

                                    <td>
                                        <span class="<?php echo $clase['estado'] === 'activa' ? 'badge-activa' : 'badge-inactiva'; ?>">
                                            <?php echo $clase['estado'] === 'activa' ? 'Activa' : 'Inactiva'; ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?php if (!$vista_global): ?>
                                            <div class="acciones-clase">
                                                <button
                                                    type="button"
                                                    class="btn-accion btn-editar"
                                                    onclick="editarClase(<?php echo (int) $clase['id']; ?>)"
                                                >
                                                    <i class="fas fa-edit"></i>
                                                    Editar
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn-accion btn-eliminar"
                                                    onclick='eliminarClase(
                                                        <?php echo (int) $clase['id']; ?>,
                                                        <?php echo json_encode((string) $clase['nombre'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                                                    )'
                                                >
                                                    <i class="fas fa-trash"></i>
                                                    Eliminar
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <span class="class-readonly"><i class="fas fa-eye"></i> Consulta</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if ($clases === []): ?>
                                <tr>
                                    <td colspan="<?php echo $vista_global ? 10 : 9; ?>">
                                        <div class="empty-state">
                                            <i class="fas fa-chalkboard"></i>
                                            <h3>No hay clases registradas</h3>
                                            <p><?php echo $vista_global ? 'No existen clases que coincidan con los filtros.' : 'Crea una nueva clase o modifica los filtros.'; ?></p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <ul class="pagination">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a
                                    class="page-link"
                                    href="<?php echo clases_h(clases_url('clases.php', array_merge($baseQuery, [
                                        'page' => max(1, $page - 1),
                                    ]))); ?>"
                                >
                                    Anterior
                                </a>
                            </li>

                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a
                                        class="page-link"
                                        href="<?php echo clases_h(clases_url('clases.php', array_merge($baseQuery, [
                                            'page' => $i,
                                        ]))); ?>"
                                    >
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a
                                    class="page-link"
                                    href="<?php echo clases_h(clases_url('clases.php', array_merge($baseQuery, [
                                        'page' => min($total_pages, $page + 1),
                                    ]))); ?>"
                                >
                                    Siguiente
                                </a>
                            </li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!$vista_global): ?>
        <div class="modal fade class-editor-modal" id="modalNuevaClase" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content modal-custom">
                    <div class="modal-header modal-header-custom">
                        <div>
                            <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Nueva clase</h5>
                            <small><?php echo clases_h($sucursal_nombre); ?></small>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>

                    <form id="formNuevaClase" method="POST" class="class-editor-form">
                        <input type="hidden" name="action" value="crear_clase">

                        <div class="modal-body">
                            <div class="class-modal-branch">
                                <i class="fas fa-circle-info"></i>
                                El precio solo se cobra a visitantes, externos y socios sin membresía vigente.
                            </div>

                            <section class="class-form-section">
                                <div class="class-section-title">
                                    <span><i class="fas fa-dumbbell"></i></span>
                                    <div><strong>Datos de la clase</strong><small>Información principal y cobro individual.</small></div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <label class="form-label">Nombre de la clase *</label>
                                        <input type="text" class="form-control" name="nombre" maxlength="100" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Precio por persona *</label>
                                        <div class="input-group">
                                            <span class="input-group-text">$</span>
                                            <input type="number" class="form-control" name="precio_clase" min="0" step="0.5" value="0.00" required>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Descripción</label>
                                        <textarea class="form-control" name="descripcion" rows="2" placeholder="Ej. Sesión de box para nivel principiante e intermedio"></textarea>
                                    </div>
                                </div>
                            </section>

                            <section class="class-form-section">
                                <div class="class-section-title with-action">
                                    <div class="d-flex align-items-center gap-2">
                                        <span><i class="fas fa-calendar-days"></i></span>
                                        <div><strong>Horarios semanales</strong><small>Agrega uno o varios días sin escribirlos manualmente.</small></div>
                                    </div>
                                    <button type="button" class="btn-add-schedule" data-schedule-add="new">
                                        <i class="fas fa-plus"></i> Agregar horario
                                    </button>
                                </div>
                                <div class="schedule-editor" id="newScheduleRows"></div>
                            </section>

                            <section class="class-form-section">
                                <div class="class-section-title">
                                    <span><i class="fas fa-user-group"></i></span>
                                    <div><strong>Entrenador</strong><small>Puede pertenecer al sistema o ser externo.</small></div>
                                </div>

                                <div class="trainer-type-grid" data-trainer-scope="new">
                                    <label class="trainer-type-option">
                                        <input type="radio" name="instructor_tipo" value="interno" checked>
                                        <span><i class="fas fa-user-shield"></i><strong>Del sistema</strong><small>Usuario entrenador asignado a la sede.</small></span>
                                    </label>
                                    <label class="trainer-type-option">
                                        <input type="radio" name="instructor_tipo" value="externo">
                                        <span><i class="fas fa-user-plus"></i><strong>Externo</strong><small>Entrenador independiente o invitado.</small></span>
                                    </label>
                                </div>

                                <div class="trainer-panel" data-trainer-panel="new-interno">
                                    <label class="form-label">Entrenador del sistema *</label>
                                    <select class="form-select" name="instructor_usuario_id">
                                        <option value="">Seleccionar entrenador</option>
                                        <?php foreach ($entrenadores as $entrenador): ?>
                                            <option value="<?php echo (int) $entrenador['id']; ?>">
                                                <?php echo clases_h($entrenador['nombre'] . (trim((string) $entrenador['email']) !== '' ? ' · ' . $entrenador['email'] : '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!$hay_entrenadores): ?>
                                        <small class="class-trainer-note warning">No hay entrenadores internos activos; puedes registrar uno externo.</small>
                                    <?php endif; ?>
                                </div>

                                <div class="trainer-panel d-none" data-trainer-panel="new-externo">
                                    <label class="form-label">Entrenador externo *</label>
                                    <select class="form-select external-trainer-select" name="entrenador_externo_id" data-scope="new">
                                        <option value="nuevo">Registrar un entrenador nuevo</option>
                                        <?php foreach ($entrenadores_externos as $externo): ?>
                                            <option value="<?php echo (int) $externo['id']; ?>">
                                                <?php echo clases_h($externo['nombre'] . (trim((string) $externo['telefono']) !== '' ? ' · ' . $externo['telefono'] : '')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <div class="external-trainer-fields" data-external-fields="new">
                                        <div class="row g-3 mt-0">
                                            <div class="col-md-6">
                                                <label class="form-label">Nombre *</label>
                                                <input type="text" class="form-control" name="entrenador_externo_nombre" maxlength="120">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Número celular</label>
                                                <input type="tel" class="form-control" name="entrenador_externo_telefono" maxlength="25">
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Correo electrónico</label>
                                                <input type="email" class="form-control" name="entrenador_externo_email" maxlength="150">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="class-form-section compact">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Cupo máximo *</label>
                                        <input type="number" class="form-control" name="cupo_maximo" min="1" max="10000" value="20" required>
                                    </div>
                                    <div class="col-md-6 class-duration-preview">
                                        <label class="form-label">Duración calculada</label>
                                        <div class="duration-box" data-duration-preview="new"><i class="far fa-clock"></i> 60 minutos</div>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-xmark"></i> Cancelar</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Guardar clase</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade class-editor-modal" id="modalEditarClase" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content modal-custom">
                    <div class="modal-header modal-header-custom">
                        <div>
                            <h5 class="modal-title"><i class="fas fa-pen-to-square"></i> Editar clase</h5>
                            <small><?php echo clases_h($sucursal_nombre); ?></small>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>

                    <form id="formEditarClase" method="POST" class="class-editor-form">
                        <input type="hidden" name="action" value="editar_clase">
                        <input type="hidden" name="clase_id" id="edit_clase_id">

                        <div class="modal-body">
                            <div class="class-modal-branch">
                                <i class="fas fa-circle-info"></i>
                                Los socios con membresía activa conservan la clase incluida sin cobro.
                            </div>

                            <section class="class-form-section">
                                <div class="class-section-title">
                                    <span><i class="fas fa-dumbbell"></i></span>
                                    <div><strong>Datos de la clase</strong><small>Información principal y cobro individual.</small></div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <label class="form-label">Nombre de la clase *</label>
                                        <input type="text" class="form-control" name="nombre" id="edit_nombre" maxlength="100" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Precio por persona *</label>
                                        <div class="input-group">
                                            <span class="input-group-text">$</span>
                                            <input type="number" class="form-control" name="precio_clase" id="edit_precio_clase" min="0" step="0.01" required>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">Descripción</label>
                                        <textarea class="form-control" name="descripcion" id="edit_descripcion" rows="2"></textarea>
                                    </div>
                                </div>
                            </section>

                            <section class="class-form-section">
                                <div class="class-section-title with-action">
                                    <div class="d-flex align-items-center gap-2">
                                        <span><i class="fas fa-calendar-days"></i></span>
                                        <div><strong>Horarios semanales</strong><small>Puedes agregar más de una sesión por semana.</small></div>
                                    </div>
                                    <button type="button" class="btn-add-schedule" data-schedule-add="edit"><i class="fas fa-plus"></i> Agregar horario</button>
                                </div>
                                <div class="schedule-editor" id="editScheduleRows"></div>
                                <div class="legacy-schedule-note d-none" id="editHorarioLegacy"></div>
                            </section>

                            <section class="class-form-section">
                                <div class="class-section-title">
                                    <span><i class="fas fa-user-group"></i></span>
                                    <div><strong>Entrenador</strong><small>Selecciona personal interno o un entrenador externo.</small></div>
                                </div>

                                <div class="trainer-type-grid" data-trainer-scope="edit">
                                    <label class="trainer-type-option">
                                        <input type="radio" name="instructor_tipo" value="interno" checked>
                                        <span><i class="fas fa-user-shield"></i><strong>Del sistema</strong><small>Usuario entrenador asignado.</small></span>
                                    </label>
                                    <label class="trainer-type-option">
                                        <input type="radio" name="instructor_tipo" value="externo">
                                        <span><i class="fas fa-user-plus"></i><strong>Externo</strong><small>Entrenador independiente.</small></span>
                                    </label>
                                </div>

                                <div class="trainer-panel" data-trainer-panel="edit-interno">
                                    <label class="form-label">Entrenador del sistema *</label>
                                    <select class="form-select" name="instructor_usuario_id" id="edit_instructor_usuario_id">
                                        <option value="">Seleccionar entrenador</option>
                                        <?php foreach ($entrenadores as $entrenador): ?>
                                            <option value="<?php echo (int) $entrenador['id']; ?>"><?php echo clases_h($entrenador['nombre'] . (trim((string) $entrenador['email']) !== '' ? ' · ' . $entrenador['email'] : '')); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="trainer-panel d-none" data-trainer-panel="edit-externo">
                                    <label class="form-label">Entrenador externo *</label>
                                    <select class="form-select external-trainer-select" name="entrenador_externo_id" id="edit_entrenador_externo_id" data-scope="edit">
                                        <option value="nuevo">Registrar un entrenador nuevo</option>
                                        <?php foreach ($entrenadores_externos as $externo): ?>
                                            <option value="<?php echo (int) $externo['id']; ?>"><?php echo clases_h($externo['nombre'] . (trim((string) $externo['telefono']) !== '' ? ' · ' . $externo['telefono'] : '')); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="external-trainer-fields" data-external-fields="edit">
                                        <div class="row g-3 mt-0">
                                            <div class="col-md-6">
                                                <label class="form-label">Nombre *</label>
                                                <input type="text" class="form-control" name="entrenador_externo_nombre" maxlength="120">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Número celular</label>
                                                <input type="tel" class="form-control" name="entrenador_externo_telefono" maxlength="25">
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Correo electrónico</label>
                                                <input type="email" class="form-control" name="entrenador_externo_email" maxlength="150">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <section class="class-form-section compact">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Cupo máximo *</label>
                                        <input type="number" class="form-control" name="cupo_maximo" id="edit_cupo_maximo" min="1" max="10000" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Estado *</label>
                                        <select class="form-select" name="estado" id="edit_estado" required>
                                            <option value="activa">Activa</option>
                                            <option value="inactiva">Inactiva</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 class-duration-preview">
                                        <label class="form-label">Duración calculada</label>
                                        <div class="duration-box" data-duration-preview="edit"><i class="far fa-clock"></i> 60 minutos</div>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-xmark"></i> Cancelar</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Guardar cambios</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <?php if (is_array($swal_clases)): ?>
        <script>
        document.addEventListener(
            'DOMContentLoaded',
            function () {
                const alertData = <?php echo json_encode(
                    $swal_clases,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_HEX_TAG
                    | JSON_HEX_AMP
                    | JSON_HEX_APOS
                    | JSON_HEX_QUOT
                ); ?>;

                const isSuccess =
                    alertData.icon === 'success';

                Swal.fire({
                    icon: alertData.icon || 'info',
                    title: alertData.title || 'Información',
                    text: alertData.message || '',
                    confirmButtonText: 'Entendido',
                    confirmButtonColor: '#1e3a8a',
                    timer: isSuccess ? 2600 : undefined,
                    timerProgressBar: isSuccess,
                    showConfirmButton: !isSuccess,
                    allowOutsideClick: isSuccess,
                    allowEscapeKey: true
                });
            }
        );
        </script>
    <?php endif; ?>

    <script>
    (function () {
        const vista = document.getElementById('vistaActual')?.value || 'sucursal';
        const dias = [
            {value: '1', label: 'Lunes'},
            {value: '2', label: 'Martes'},
            {value: '3', label: 'Miércoles'},
            {value: '4', label: 'Jueves'},
            {value: '5', label: 'Viernes'},
            {value: '6', label: 'Sábado'},
            {value: '7', label: 'Domingo'}
        ];

        function escaparHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function prepararTablaResponsive() {
            const tabla = document.querySelector('.responsive-table');
            if (!tabla) return;

            const encabezados = Array.from(tabla.querySelectorAll('thead th'))
                .map((th) => th.textContent.trim());

            tabla.querySelectorAll('tbody tr').forEach((fila) => {
                fila.querySelectorAll('td').forEach((celda, indice) => {
                    if (!celda.hasAttribute('colspan')) {
                        celda.setAttribute('data-label', encabezados[indice] || '');
                    }
                });
            });
        }

        function irAFiltros() {
            const params = new URLSearchParams({
                vista: vista,
                search: $('#searchInput').val().trim(),
                estado: $('#estadoSelect').val()
            });
            window.location.href = 'clases.php?' + params.toString();
        }

        function opcionesDias(seleccionado) {
            return dias.map((dia) => (
                '<option value="' + dia.value + '"'
                + (String(seleccionado) === dia.value ? ' selected' : '')
                + '>' + dia.label + '</option>'
            )).join('');
        }

        function crearFilaHorario(scope, horario = {}) {
            const container = document.getElementById(scope + 'ScheduleRows');
            if (!container) return;

            const row = document.createElement('div');
            row.className = 'schedule-row';
            row.innerHTML = `
                <div class="schedule-field schedule-day">
                    <label>Día</label>
                    <select class="form-select" name="horario_dia[]" required>
                        ${opcionesDias(horario.dia_semana || '1')}
                    </select>
                </div>
                <div class="schedule-field">
                    <label>Inicio</label>
                    <input type="time" class="form-control schedule-time" name="horario_inicio[]" value="${escaparHtml(horario.hora_inicio || '18:00')}" required>
                </div>
                <div class="schedule-field">
                    <label>Fin</label>
                    <input type="time" class="form-control schedule-time" name="horario_fin[]" value="${escaparHtml(horario.hora_fin || '19:00')}" required>
                </div>
                <button type="button" class="schedule-remove" title="Quitar horario" aria-label="Quitar horario">
                    <i class="fas fa-trash"></i>
                </button>
            `;

            row.querySelector('.schedule-remove').addEventListener('click', () => {
                if (container.querySelectorAll('.schedule-row').length <= 1) {
                    Swal.fire({
                        icon: 'info',
                        title: 'Se requiere un horario',
                        text: 'La clase debe conservar al menos un día y una hora.',
                        confirmButtonColor: '#1e3a8a'
                    });
                    return;
                }
                row.remove();
                actualizarDuracion(scope);
            });

            row.querySelectorAll('.schedule-time').forEach((input) => {
                input.addEventListener('change', () => actualizarDuracion(scope));
            });

            container.appendChild(row);
            actualizarDuracion(scope);
        }

        function minutos(hora) {
            if (!/^\d{2}:\d{2}$/.test(hora || '')) return null;
            const [h, m] = hora.split(':').map(Number);
            return (h * 60) + m;
        }

        function actualizarDuracion(scope) {
            const row = document.querySelector('#' + scope + 'ScheduleRows .schedule-row');
            const preview = document.querySelector('[data-duration-preview="' + scope + '"]');
            if (!row || !preview) return;

            const inicio = minutos(row.querySelector('[name="horario_inicio[]"]')?.value);
            const fin = minutos(row.querySelector('[name="horario_fin[]"]')?.value);
            const duracion = inicio !== null && fin !== null && fin > inicio ? fin - inicio : 0;
            preview.innerHTML = '<i class="far fa-clock"></i> ' + (duracion > 0 ? duracion + ' minutos' : 'Por calcular');
        }

        function cambiarTipoEntrenador(scope, tipo) {
            const interno = document.querySelector('[data-trainer-panel="' + scope + '-interno"]');
            const externo = document.querySelector('[data-trainer-panel="' + scope + '-externo"]');
            if (!interno || !externo) return;

            interno.classList.toggle('d-none', tipo !== 'interno');
            externo.classList.toggle('d-none', tipo !== 'externo');

            const selectInterno = interno.querySelector('select[name="instructor_usuario_id"]');
            if (selectInterno) selectInterno.required = tipo === 'interno';

            actualizarCamposExternos(scope);
        }

        function actualizarCamposExternos(scope) {
            const panel = document.querySelector('[data-trainer-panel="' + scope + '-externo"]');
            const fields = document.querySelector('[data-external-fields="' + scope + '"]');
            const select = panel?.querySelector('.external-trainer-select');
            if (!panel || !fields || !select) return;

            const tipo = document.querySelector('[data-trainer-scope="' + scope + '"] input[name="instructor_tipo"]:checked')?.value;
            const registrarNuevo = tipo === 'externo' && select.value === 'nuevo';
            fields.classList.toggle('d-none', !registrarNuevo);

            const nombre = fields.querySelector('input[name="entrenador_externo_nombre"]');
            if (nombre) nombre.required = registrarNuevo;
        }

        function validarFormulario(form, scope) {
            const rows = form.querySelectorAll('.schedule-row');
            if (!rows.length) return 'Agrega al menos un horario.';

            let duracionBase = null;
            const rangos = {};

            for (const row of rows) {
                const dia = row.querySelector('[name="horario_dia[]"]')?.value;
                const inicio = minutos(row.querySelector('[name="horario_inicio[]"]')?.value);
                const fin = minutos(row.querySelector('[name="horario_fin[]"]')?.value);

                if (!dia || inicio === null || fin === null || fin <= inicio) {
                    return 'Revisa las horas de inicio y término.';
                }

                const duracion = fin - inicio;
                if (duracionBase === null) duracionBase = duracion;
                if (duracion !== duracionBase) {
                    return 'Todos los horarios deben conservar la misma duración.';
                }

                rangos[dia] = rangos[dia] || [];
                if (rangos[dia].some((rango) => inicio < rango.fin && fin > rango.inicio)) {
                    return 'Hay horarios traslapados para el mismo día.';
                }
                rangos[dia].push({inicio, fin});
            }

            const tipo = form.querySelector('input[name="instructor_tipo"]:checked')?.value;
            if (tipo === 'interno' && !form.querySelector('select[name="instructor_usuario_id"]')?.value) {
                return 'Selecciona un entrenador del sistema.';
            }

            if (tipo === 'externo') {
                const select = form.querySelector('select[name="entrenador_externo_id"]');
                const nombre = form.querySelector('input[name="entrenador_externo_nombre"]');
                if (select?.value === 'nuevo' && !nombre?.value.trim()) {
                    return 'Escribe el nombre del entrenador externo.';
                }
            }

            actualizarDuracion(scope);
            return '';
        }

        prepararTablaResponsive();
        crearFilaHorario('new');

        document.querySelectorAll('[data-schedule-add]').forEach((button) => {
            button.addEventListener('click', () => crearFilaHorario(button.dataset.scheduleAdd));
        });

        document.querySelectorAll('[data-trainer-scope]').forEach((group) => {
            const scope = group.dataset.trainerScope;
            group.querySelectorAll('input[name="instructor_tipo"]').forEach((radio) => {
                radio.addEventListener('change', () => cambiarTipoEntrenador(scope, radio.value));
            });
        });

        document.querySelectorAll('.external-trainer-select').forEach((select) => {
            select.addEventListener('change', () => actualizarCamposExternos(select.dataset.scope));
        });

        cambiarTipoEntrenador('new', 'interno');
        cambiarTipoEntrenador('edit', 'interno');

        let timeoutBusqueda;
        $('#searchInput').on('input', function () {
            clearTimeout(timeoutBusqueda);
            timeoutBusqueda = setTimeout(irAFiltros, 500);
        });
        $('#estadoSelect').on('change', irAFiltros);
        $('#limpiarFiltros').on('click', function () {
            window.location.href = 'clases.php?vista=' + encodeURIComponent(vista);
        });

        $('#formNuevaClase, #formEditarClase').on('submit', function (event) {
            const scope = this.id === 'formNuevaClase' ? 'new' : 'edit';
            const mensaje = validarFormulario(this, scope);

            if (mensaje) {
                event.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Revisa la información',
                    text: mensaje,
                    confirmButtonColor: '#1e3a8a'
                });
                return false;
            }

            const $btn = $(this).find('button[type="submit"]');
            if ($btn.data('submitted') === true) return false;

            $btn.data('submitted', true);
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Guardando...');
            return true;
        });

        $('button[type="submit"]').each(function () {
            $(this).data('original-text', $(this).html());
        });

        $('#modalNuevaClase, #modalEditarClase').on('hidden.bs.modal', function () {
            const $btn = $(this).find('button[type="submit"]');
            $btn.prop('disabled', false).html($btn.data('original-text') || 'Guardar');
            $btn.removeData('submitted');
        });

        $('#modalNuevaClase').on('hidden.bs.modal', function () {
            const form = document.getElementById('formNuevaClase');
            form?.reset();
            document.getElementById('newScheduleRows').innerHTML = '';
            crearFilaHorario('new');
            cambiarTipoEntrenador('new', 'interno');
        });

        window.editarClase = function (id) {
            $.ajax({
                url: 'includes/obtener_clase.php',
                method: 'POST',
                data: {id: id},
                dataType: 'json',
                success: function (data) {
                    if (!data.success) {
                        Swal.fire('Clase no disponible', data.message || 'No se pudo obtener la clase.', 'error');
                        return;
                    }

                    $('#edit_clase_id').val(data.id);
                    $('#edit_nombre').val(data.nombre);
                    $('#edit_descripcion').val(data.descripcion || '');
                    $('#edit_precio_clase').val(Number(data.precio_clase || 0).toFixed(2));
                    $('#edit_cupo_maximo').val(data.cupo_maximo);
                    $('#edit_estado').val(data.estado);

                    const scope = 'edit';
                    const tipo = data.instructor_tipo === 'externo' ? 'externo' : 'interno';
                    const radio = document.querySelector('[data-trainer-scope="edit"] input[value="' + tipo + '"]');
                    if (radio) radio.checked = true;
                    cambiarTipoEntrenador(scope, tipo);

                    $('#edit_instructor_usuario_id').val(String(data.instructor_usuario_id || ''));
                    $('#edit_entrenador_externo_id').val(String(data.entrenador_externo_id || 'nuevo'));
                    actualizarCamposExternos('edit');

                    const container = document.getElementById('editScheduleRows');
                    container.innerHTML = '';
                    const horarios = Array.isArray(data.horarios) ? data.horarios : [];

                    if (horarios.length) {
                        horarios.forEach((horario) => crearFilaHorario('edit', horario));
                        $('#editHorarioLegacy').addClass('d-none').text('');
                    } else {
                        crearFilaHorario('edit');
                        $('#editHorarioLegacy')
                            .removeClass('d-none')
                            .text('Horario anterior: ' + (data.horario || 'Sin horario estructurado') + '. Define el nuevo horario antes de guardar.');
                    }

                    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEditarClase')).show();
                },
                error: function (xhr) {
                    Swal.fire(
                        'Error',
                        xhr.responseJSON?.message || 'Error al cargar los datos de la clase.',
                        'error'
                    );
                }
            });
        };

        window.eliminarClase = function (id, nombre) {
            Swal.fire({
                title: '¿Eliminar clase?',
                html: '¿Deseas eliminar <strong>' + escaparHtml(nombre) + '</strong>?<br><small class="text-danger">Esta acción no se puede deshacer.</small>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            }).then(function (result) {
                if (!result.isConfirmed) return;
                const params = new URLSearchParams({vista: vista, eliminar: String(id)});
                window.location.href = 'clases.php?' + params.toString();
            });
        };
    })();
    </script>
</body>
</html>
