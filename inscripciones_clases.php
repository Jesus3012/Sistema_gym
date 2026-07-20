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

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'inscribir_cliente'
) {
    $transaccion = false;

    try {
        $sucursal_operativa = clases_exigir_sucursal(
            $contexto
        );

        $clase_id = (int) (
            $_POST['clase_id'] ?? 0
        );
        $cliente_id = (int) (
            $_POST['cliente_id'] ?? 0
        );

        if ($clase_id <= 0 || $cliente_id <= 0) {
            throw new RuntimeException(
                'Selecciona un socio y una clase válidos.'
            );
        }

        $conn->begin_transaction();
        $transaccion = true;

        $stmtCliente = $conn->prepare(
            "SELECT id
             FROM clientes
             WHERE id = ?
               AND estado = 'activo'
             LIMIT 1"
        );

        $stmtCliente->bind_param(
            'i',
            $cliente_id
        );

        $stmtCliente->execute();

        $cliente = $stmtCliente
            ->get_result()
            ->fetch_assoc();

        $stmtCliente->close();

        if (!$cliente) {
            throw new RuntimeException(
                'El socio seleccionado no existe o está inactivo.'
            );
        }

        $stmtClase = $conn->prepare(
            "SELECT
                id,
                cupo_maximo,
                cupo_actual,
                estado
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

        $clase = $stmtClase
            ->get_result()
            ->fetch_assoc();

        $stmtClase->close();

        if (!$clase) {
            throw new RuntimeException(
                'La clase no pertenece a la sucursal seleccionada.'
            );
        }

        if ($clase['estado'] !== 'activa') {
            throw new RuntimeException(
                'La clase seleccionada está inactiva.'
            );
        }

        if (
            (int) $clase['cupo_actual']
            >= (int) $clase['cupo_maximo']
        ) {
            throw new RuntimeException(
                'No hay cupo disponible en esta clase.'
            );
        }

        $stmtDuplicado = $conn->prepare(
            "SELECT id
             FROM inscripciones_clases
             WHERE clase_id = ?
               AND cliente_id = ?
               AND estado = 'activa'
             LIMIT 1
             FOR UPDATE"
        );

        $stmtDuplicado->bind_param(
            'ii',
            $clase_id,
            $cliente_id
        );

        $stmtDuplicado->execute();

        $duplicado = $stmtDuplicado
            ->get_result()
            ->fetch_assoc();

        $stmtDuplicado->close();

        if ($duplicado) {
            throw new RuntimeException(
                'El socio ya está inscrito en esta clase.'
            );
        }

        $fecha_inscripcion = date('Y-m-d');

        $stmtInsert = $conn->prepare(
            "INSERT INTO inscripciones_clases (
                clase_id,
                cliente_id,
                fecha_inscripcion,
                estado,
                asistencia
             ) VALUES (
                ?,
                ?,
                ?,
                'activa',
                0
             )"
        );

        $stmtInsert->bind_param(
            'iis',
            $clase_id,
            $cliente_id,
            $fecha_inscripcion
        );

        $stmtInsert->execute();
        $stmtInsert->close();

        $stmtCupo = $conn->prepare(
            "UPDATE clases
             SET cupo_actual = cupo_actual + 1
             WHERE id = ?
               AND sucursal_id = ?
               AND cupo_actual < cupo_maximo"
        );

        $stmtCupo->bind_param(
            'ii',
            $clase_id,
            $sucursal_operativa
        );

        $stmtCupo->execute();

        if ($stmtCupo->affected_rows !== 1) {
            throw new RuntimeException(
                'El cupo cambió mientras se procesaba la inscripción.'
            );
        }

        $stmtCupo->close();

        $conn->commit();
        $transaccion = false;

        clases_swal_guardar(
            'success',
            'Inscripción registrada',
            'El socio se inscribió correctamente en una clase de '
            . $sucursal_nombre
            . '.'
        );
    } catch (Throwable $error) {
        if ($transaccion) {
            $conn->rollback();
        }

        clases_swal_guardar(
            'error',
            'No fue posible registrar la inscripción',
            $error->getMessage()
        );
    }

    clases_redirect(
        'inscripciones_clases.php',
        $contexto
    );
}

if (
    isset($_GET['cancelar'])
    && ctype_digit((string) $_GET['cancelar'])
) {
    $transaccion = false;

    try {
        $sucursal_operativa = clases_exigir_sucursal(
            $contexto
        );
        $inscripcion_id = (int) $_GET['cancelar'];

        $conn->begin_transaction();
        $transaccion = true;

        $stmtInscripcion = $conn->prepare(
            "SELECT
                ic.id,
                ic.clase_id,
                ic.estado
             FROM inscripciones_clases ic
             INNER JOIN clases c
                ON c.id = ic.clase_id
             WHERE ic.id = ?
               AND c.sucursal_id = ?
             LIMIT 1
             FOR UPDATE"
        );

        $stmtInscripcion->bind_param(
            'ii',
            $inscripcion_id,
            $sucursal_operativa
        );

        $stmtInscripcion->execute();

        $inscripcion = $stmtInscripcion
            ->get_result()
            ->fetch_assoc();

        $stmtInscripcion->close();

        if (!$inscripcion) {
            throw new RuntimeException(
                'La inscripción no pertenece a la sucursal seleccionada.'
            );
        }

        if ($inscripcion['estado'] !== 'activa') {
            throw new RuntimeException(
                'La inscripción ya no está activa.'
            );
        }

        $clase_id = (int) $inscripcion['clase_id'];

        $stmtCancelar = $conn->prepare(
            "UPDATE inscripciones_clases
             SET estado = 'cancelada'
             WHERE id = ?
               AND estado = 'activa'"
        );

        $stmtCancelar->bind_param(
            'i',
            $inscripcion_id
        );

        $stmtCancelar->execute();

        if ($stmtCancelar->affected_rows !== 1) {
            throw new RuntimeException(
                'No se pudo cancelar la inscripción.'
            );
        }

        $stmtCancelar->close();

        $stmtCupo = $conn->prepare(
            "UPDATE clases
             SET cupo_actual = GREATEST(
                cupo_actual - 1,
                0
             )
             WHERE id = ?
               AND sucursal_id = ?"
        );

        $stmtCupo->bind_param(
            'ii',
            $clase_id,
            $sucursal_operativa
        );

        $stmtCupo->execute();

        if ($stmtCupo->affected_rows !== 1) {
            throw new RuntimeException(
                'No se pudo liberar el cupo de la clase.'
            );
        }

        $stmtCupo->close();

        $conn->commit();
        $transaccion = false;

        clases_swal_guardar(
            'success',
            'Inscripción cancelada',
            'La inscripción se canceló y el cupo quedó disponible.'
        );
    } catch (Throwable $error) {
        if ($transaccion) {
            $conn->rollback();
        }

        clases_swal_guardar(
            'error',
            'No fue posible cancelar la inscripción',
            $error->getMessage()
        );
    }

    clases_redirect(
        'inscripciones_clases.php',
        $contexto
    );
}

if (
    isset($_GET['asistencia'])
    && ctype_digit((string) $_GET['asistencia'])
) {
    try {
        $sucursal_operativa = clases_exigir_sucursal(
            $contexto
        );
        $inscripcion_id = (int) $_GET['asistencia'];

        $stmt = $conn->prepare(
            "UPDATE inscripciones_clases ic
             INNER JOIN clases c
                ON c.id = ic.clase_id
             SET
                ic.asistencia =
                    ic.asistencia + 1,
                ic.fecha_ultima_asistencia =
                    CURDATE()
             WHERE ic.id = ?
               AND ic.estado = 'activa'
               AND c.sucursal_id = ?"
        );

        $stmt->bind_param(
            'ii',
            $inscripcion_id,
            $sucursal_operativa
        );

        $stmt->execute();

        if ($stmt->affected_rows !== 1) {
            throw new RuntimeException(
                'La inscripción no está activa o pertenece a otra sucursal.'
            );
        }

        $stmt->close();

        clases_swal_guardar(
            'success',
            'Asistencia registrada',
            'La asistencia del socio se registró correctamente.'
        );
    } catch (Throwable $error) {
        clases_swal_guardar(
            'error',
            'No fue posible registrar la asistencia',
            $error->getMessage()
        );
    }

    clases_redirect(
        'inscripciones_clases.php',
        $contexto
    );
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
$limit = 10;

if (
    !in_array(
        $estado,
        ['', 'activa', 'cancelada', 'completada'],
        true
    )
) {
    $estado = '';
}

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
        cl.nombre LIKE ?
        OR cl.apellido LIKE ?
        OR c.nombre LIKE ?
        OR cl.telefono LIKE ?
        OR s.nombre LIKE ?
        OR s.clave LIKE ?
    )";

    for ($i = 0; $i < 6; $i++) {
        $params[] = $searchParam;
        $types .= 's';
    }
}

if ($estado !== '') {
    $where[] = 'ic.estado = ?';
    $params[] = $estado;
    $types .= 's';
}

$whereSql = 'WHERE ' . implode(
    ' AND ',
    $where
);

$joins = "
    FROM inscripciones_clases ic
    INNER JOIN clases c
        ON c.id = ic.clase_id
    INNER JOIN clientes cl
        ON cl.id = ic.cliente_id
    INNER JOIN sucursales s
        ON s.id = c.sucursal_id
";

$countSql = "
    SELECT COUNT(*) AS total
    $joins
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
        ic.*,
        c.nombre AS clase_nombre,
        c.horario,
        c.instructor,
        cl.nombre AS cliente_nombre,
        cl.apellido AS cliente_apellido,
        cl.telefono,
        s.nombre AS sucursal_nombre,
        s.clave AS sucursal_clave,
        s.es_matriz AS sucursal_es_matriz
    $joins
    $whereSql
    ORDER BY
        ic.fecha_inscripcion DESC,
        ic.id DESC
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

$inscripciones = $stmt
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$stmt->close();

$clases_list = [];

if (!$vista_global) {
    $stmtClases = $conn->prepare(
        "SELECT
            id,
            nombre,
            cupo_maximo,
            cupo_actual,
            horario,
            instructor
         FROM clases
         WHERE sucursal_id = ?
           AND estado = 'activa'
           AND cupo_actual < cupo_maximo
         ORDER BY nombre ASC"
    );

    $stmtClases->bind_param(
        'i',
        $sucursal_id
    );

    $stmtClases->execute();

    $clases_list = $stmtClases
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmtClases->close();
}

$clientesResult = $conn->query(
    "SELECT
        id,
        nombre,
        apellido,
        telefono
     FROM clientes
     WHERE estado = 'activo'
     ORDER BY nombre ASC, apellido ASC"
);

$clientes_list = $clientesResult
    ? $clientesResult->fetch_all(MYSQLI_ASSOC)
    : [];

$vista_param = $vista_global
    ? 'global'
    : 'sucursal';

$baseQuery = [
    'vista' => $vista_param,
    'search' => $search,
    'estado' => $estado,
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
    <title>
        Inscripciones a Clases - Sistema Gimnasio
    </title>

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
    $inscripcionesClasesCss =
        __DIR__ . '/css/inscripciones_clases.css';
    $clasesMultiCss =
        __DIR__ . '/css/clases_multisucursal.css';
    ?>
    <link
        rel="stylesheet"
        href="css/inscripciones_clases.css?v=<?php echo is_file($inscripcionesClasesCss) ? (int) filemtime($inscripcionesClasesCss) : time(); ?>"
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
                <h2>Inscripciones a Clases</h2>
                <p>
                    <?php echo $vista_global
                        ? 'Consulta inscripciones y asistencias de todas las sucursales.'
                        : 'Administra inscripciones, asistencias y cupos de ' . clases_h($sucursal_nombre) . '.';
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
                        data-bs-target="#modalNuevaInscripcion"
                        <?php echo $clases_list === [] ? 'disabled' : ''; ?>
                    >
                        <i class="fas fa-user-plus"></i>
                        Nueva Inscripción
                    </button>
                <?php else: ?>
                    <button
                        class="btn-custom-primary is-disabled"
                        type="button"
                        disabled
                    >
                        <i class="fas fa-lock"></i>
                        Selecciona una sucursal
                    </button>
                <?php endif; ?>
            </div>
        </div>


        <?php if (!$vista_global && $clases_list === []): ?>
            <div class="class-branch-warning">
                <i class="fas fa-circle-info"></i>
                No hay clases activas con cupo disponible en
                <strong>
                    <?php echo clases_h($sucursal_nombre); ?>
                </strong>.
            </div>
        <?php endif; ?>

        <div class="card-custom">
            <div class="card-header-custom card-header-flex">
                <span>
                    <i class="fas fa-filter"></i>
                    Filtros de búsqueda
                </span>

                <button
                    type="button"
                    class="btn-limpiar"
                    id="btnLimpiarFiltros"
                >
                    <i class="fas fa-rotate-left"></i>
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
                    <div class="col-md-8 mb-3">
                        <label class="form-label">
                            Buscar
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            id="searchInput"
                            placeholder="<?php echo $vista_global ? 'Socio, clase, teléfono o sucursal...' : 'Nombre, apellido, clase o teléfono...'; ?>"
                            value="<?php echo clases_h($search); ?>"
                        >
                    </div>

                    <div class="col-md-4 mb-3">
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
                                value="cancelada"
                                <?php echo $estado === 'cancelada' ? 'selected' : ''; ?>
                            >
                                Cancelada
                            </option>
                            <option
                                value="completada"
                                <?php echo $estado === 'completada' ? 'selected' : ''; ?>
                            >
                                Completada
                            </option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-custom">
            <div class="card-header-custom card-header-flex">
                <span>
                    <i class="fas fa-list"></i>
                    Listado de inscripciones
                </span>

                <span class="contador-registros">
                    <?php echo number_format($total_rows); ?>
                    registros
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
                                <th>Cliente</th>
                                <th>Teléfono</th>
                                <th>Clase</th>

                                <?php if ($vista_global): ?>
                                    <th>Sucursal</th>
                                <?php endif; ?>

                                <th>Instructor</th>
                                <th>Horario</th>
                                <th>Fecha</th>
                                <th>Asistencias</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($inscripciones as $inscripcion): ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <?php echo clases_h(
                                                trim(
                                                    $inscripcion['cliente_nombre']
                                                    . ' '
                                                    . $inscripcion['cliente_apellido']
                                                )
                                            ); ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?php echo clases_h($inscripcion['telefono']); ?>
                                    </td>

                                    <td>
                                        <?php echo clases_h($inscripcion['clase_nombre']); ?>
                                    </td>

                                    <?php if ($vista_global): ?>
                                        <td>
                                            <span class="class-branch-pill">
                                                <i class="fas fa-building"></i>
                                                <span>
                                                    <strong>
                                                        <?php echo clases_h($inscripcion['sucursal_nombre']); ?>
                                                    </strong>
                                                    <small>
                                                        <?php echo clases_h($inscripcion['sucursal_clave']); ?>
                                                        <?php if ((int) $inscripcion['sucursal_es_matriz'] === 1): ?>
                                                            · Matriz
                                                        <?php endif; ?>
                                                    </small>
                                                </span>
                                            </span>
                                        </td>
                                    <?php endif; ?>

                                    <td>
                                        <?php echo clases_h($inscripcion['instructor']); ?>
                                    </td>

                                    <td>
                                        <i class="far fa-clock"></i>
                                        <?php echo clases_h($inscripcion['horario']); ?>
                                    </td>

                                    <td>
                                        <?php echo date(
                                            'd/m/Y',
                                            strtotime(
                                                (string) $inscripcion['fecha_inscripcion']
                                            )
                                        ); ?>
                                    </td>

                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo (int) $inscripcion['asistencia']; ?>
                                            asistencias
                                        </span>
                                    </td>

                                    <td>
                                        <?php if ($inscripcion['estado'] === 'activa'): ?>
                                            <span class="badge-activa">
                                                Activa
                                            </span>
                                        <?php elseif ($inscripcion['estado'] === 'cancelada'): ?>
                                            <span class="badge-cancelada">
                                                Cancelada
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-completada">
                                                Completada
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if (!$vista_global && $inscripcion['estado'] === 'activa'): ?>
                                            <div class="acciones-inscripcion">
                                                <button
                                                    type="button"
                                                    class="btn-accion btn-asistencia"
                                                    onclick="registrarAsistencia(<?php echo (int) $inscripcion['id']; ?>)"
                                                    title="Registrar asistencia"
                                                >
                                                    <i class="fas fa-calendar-check"></i>
                                                    <span>Asistencia</span>
                                                </button>

                                                <button
                                                    type="button"
                                                    class="btn-accion btn-cancelar"
                                                    onclick="cancelarInscripcion(<?php echo (int) $inscripcion['id']; ?>)"
                                                    title="Cancelar inscripción"
                                                >
                                                    <i class="fas fa-times-circle"></i>
                                                    <span>Cancelar</span>
                                                </button>
                                            </div>
                                        <?php elseif ($vista_global): ?>
                                            <span class="class-readonly">
                                                <i class="fas fa-eye"></i>
                                                Consulta
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if ($inscripciones === []): ?>
                                <tr>
                                    <td
                                        colspan="<?php echo $vista_global ? 10 : 9; ?>"
                                        class="text-center"
                                        style="padding:40px;"
                                    >
                                        <i
                                            class="fas fa-users-slash"
                                            style="font-size:48px;color:#ccc;"
                                        ></i>
                                        <p class="mt-2">
                                            No hay inscripciones registradas
                                        </p>
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
                                    href="<?php echo clases_h(clases_url('inscripciones_clases.php', array_merge($baseQuery, [
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
                                        href="<?php echo clases_h(clases_url('inscripciones_clases.php', array_merge($baseQuery, [
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
                                    href="<?php echo clases_h(clases_url('inscripciones_clases.php', array_merge($baseQuery, [
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
        <div
            class="modal fade"
            id="modalNuevaInscripcion"
            tabindex="-1"
        >
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content modal-custom">
                    <div class="modal-header modal-header-custom">
                        <h5 class="modal-title">
                            <i class="fas fa-user-plus"></i>
                            Nueva Inscripción a Clase
                        </h5>
                        <button
                            type="button"
                            class="btn-close btn-close-white"
                            data-bs-dismiss="modal"
                        ></button>
                    </div>

                    <form
                        id="formInscripcion"
                        method="POST"
                    >
                        <input
                            type="hidden"
                            name="action"
                            value="inscribir_cliente"
                        >

                        <div class="modal-body">
                            <div class="class-modal-branch">
                                <i class="fas fa-building"></i>
                                Sucursal:
                                <strong>
                                    <?php echo clases_h($sucursal_nombre); ?>
                                </strong>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    Cliente *
                                </label>

                                <select
                                    class="form-select"
                                    name="cliente_id"
                                    required
                                >
                                    <option value="">
                                        Seleccionar cliente
                                    </option>

                                    <?php foreach ($clientes_list as $cliente): ?>
                                        <option value="<?php echo (int) $cliente['id']; ?>">
                                            <?php echo clases_h(
                                                trim(
                                                    $cliente['nombre']
                                                    . ' '
                                                    . $cliente['apellido']
                                                )
                                                . ' - '
                                                . $cliente['telefono']
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    Clase *
                                </label>

                                <select
                                    class="form-select"
                                    name="clase_id"
                                    required
                                >
                                    <option value="">
                                        Seleccionar clase
                                    </option>

                                    <?php foreach ($clases_list as $clase): ?>
                                        <option value="<?php echo (int) $clase['id']; ?>">
                                            <?php echo clases_h(
                                                $clase['nombre']
                                                . ' - '
                                                . $clase['horario']
                                                . ' ('
                                                . $clase['instructor']
                                                . ') - Cupo: '
                                                . $clase['cupo_actual']
                                                . '/'
                                                . $clase['cupo_maximo']
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                La fecha de inscripción se registrará automáticamente.
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button
                                type="button"
                                class="btn btn-secondary"
                                data-bs-dismiss="modal"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                Inscribir Cliente
                            </button>
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
                    showConfirmButton: !isSuccess
                });
            }
        );
        </script>
    <?php endif; ?>

    <script>
    (function () {
        const vista = document.getElementById(
            'vistaActual'
        )?.value || 'sucursal';

        function prepararTablaResponsive() {
            const tabla = document.querySelector(
                '.responsive-table'
            );

            if (!tabla) {
                return;
            }

            const encabezados = Array.from(
                tabla.querySelectorAll('thead th')
            ).map(function (th) {
                return th.textContent.trim();
            });

            tabla.querySelectorAll('tbody tr').forEach(
                function (fila) {
                    fila.querySelectorAll('td').forEach(
                        function (celda, indice) {
                            if (!celda.hasAttribute('colspan')) {
                                celda.setAttribute(
                                    'data-label',
                                    encabezados[indice] || ''
                                );
                            }
                        }
                    );
                }
            );
        }

        prepararTablaResponsive();

        function irAFiltros() {
            const params = new URLSearchParams({
                vista: vista,
                search:
                    $('#searchInput').val().trim(),
                estado:
                    $('#estadoSelect').val()
            });

            window.location.href =
                'inscripciones_clases.php?'
                + params.toString();
        }

        let timeoutBusqueda;

        $('#searchInput').on('input', function () {
            clearTimeout(timeoutBusqueda);

            timeoutBusqueda = setTimeout(
                irAFiltros,
                500
            );
        });

        $('#estadoSelect').on(
            'change',
            irAFiltros
        );

        $('#btnLimpiarFiltros').on(
            'click',
            function () {
                window.location.href =
                    'inscripciones_clases.php?vista='
                    + encodeURIComponent(vista);
            }
        );

        $('#formInscripcion').on(
            'submit',
            function () {
                const $btn = $(this).find(
                    'button[type="submit"]'
                );

                if ($btn.data('submitted') === true) {
                    return false;
                }

                $btn.data('submitted', true);
                $btn.prop('disabled', true).html(
                    '<i class="fas fa-spinner fa-spin"></i> Inscribiendo...'
                );

                return true;
            }
        );

        $('#modalNuevaInscripcion').on(
            'hidden.bs.modal',
            function () {
                const form =
                    $('#formInscripcion')[0];

                const $btn =
                    $('#formInscripcion button[type="submit"]');

                $btn.prop('disabled', false).html(
                    'Inscribir Cliente'
                );

                $btn.removeData('submitted');

                if (form) {
                    form.reset();
                }
            }
        );

        window.registrarAsistencia = function (id) {
            Swal.fire({
                title: '¿Registrar asistencia?',
                text:
                    'Confirma la asistencia del socio a la clase.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Sí, registrar',
                cancelButtonText: 'Cancelar'
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                const params = new URLSearchParams({
                    vista: vista,
                    asistencia: String(id)
                });

                window.location.href =
                    'inscripciones_clases.php?'
                    + params.toString();
            });
        };

        window.cancelarInscripcion = function (id) {
            Swal.fire({
                title: '¿Cancelar inscripción?',
                text:
                    'Esta acción liberará el cupo de la clase.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Sí, cancelar',
                cancelButtonText: 'No'
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                const params = new URLSearchParams({
                    vista: vista,
                    cancelar: String(id)
                });

                window.location.href =
                    'inscripciones_clases.php?'
                    + params.toString();
            });
        };
    })();
    </script>
</body>
</html>
