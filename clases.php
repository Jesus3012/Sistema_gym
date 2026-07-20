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

$entrenadores = $vista_global
    ? []
    : clases_entrenadores_sucursal(
        $conn,
        $sucursal_id
    );

$hay_entrenadores = $entrenadores !== [];

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
) {
    try {
        $sucursal_operativa = clases_exigir_sucursal(
            $contexto
        );

        $action = (string) $_POST['action'];
        $nombre = trim((string) (
            $_POST['nombre'] ?? ''
        ));
        $descripcion = trim((string) (
            $_POST['descripcion'] ?? ''
        ));
        $horario = trim((string) (
            $_POST['horario'] ?? ''
        ));
        $instructor_usuario_id = (int) (
            $_POST['instructor_usuario_id'] ?? 0
        );

        $entrenador_seleccionado =
            clases_validar_entrenador(
                $conn,
                $sucursal_operativa,
                $instructor_usuario_id
            );

        /*
         * La estructura actual de clases conserva instructor como texto.
         * El nombre nunca se toma del navegador: sale del usuario validado.
         */
        $instructor = trim((string) (
            $entrenador_seleccionado['nombre'] ?? ''
        ));

        $cupo_maximo = (int) (
            $_POST['cupo_maximo'] ?? 0
        );
        $duracion_minutos = (int) (
            $_POST['duracion_minutos'] ?? 0
        );

        if (
            $nombre === ''
            || $horario === ''
            || $instructor_usuario_id <= 0
            || $instructor === ''
            || $cupo_maximo <= 0
            || $duracion_minutos <= 0
        ) {
            throw new RuntimeException(
                'Completa todos los campos requeridos.'
            );
        }

        if ($action === 'crear_clase') {
            $stmt = $conn->prepare(
                "INSERT INTO clases (
                    sucursal_id,
                    nombre,
                    descripcion,
                    horario,
                    instructor,
                    cupo_maximo,
                    cupo_actual,
                    duracion_minutos,
                    estado
                 ) VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    0,
                    ?,
                    'activa'
                 )"
            );

            $stmt->bind_param(
                'issssii',
                $sucursal_operativa,
                $nombre,
                $descripcion,
                $horario,
                $instructor,
                $cupo_maximo,
                $duracion_minutos
            );

            $stmt->execute();
            $stmt->close();

            clases_swal_guardar(
                'success',
                'Clase creada',
                'La clase se registró correctamente en '
                . $sucursal_nombre
                . ' con '
                . $instructor
                . ' como entrenador.'
            );
        } elseif ($action === 'editar_clase') {
            $clase_id = (int) (
                $_POST['clase_id'] ?? 0
            );

            $estado = (string) (
                $_POST['estado'] ?? 'activa'
            );

            if (
                $clase_id <= 0
                || !in_array(
                    $estado,
                    ['activa', 'inactiva'],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Los datos de la clase son inválidos.'
                );
            }

            $stmtActual = $conn->prepare(
                "SELECT cupo_actual
                 FROM clases
                 WHERE id = ?
                   AND sucursal_id = ?
                 LIMIT 1"
            );

            $stmtActual->bind_param(
                'ii',
                $clase_id,
                $sucursal_operativa
            );

            $stmtActual->execute();

            $claseActual = $stmtActual
                ->get_result()
                ->fetch_assoc();

            $stmtActual->close();

            if (!$claseActual) {
                throw new RuntimeException(
                    'La clase no pertenece a la sucursal seleccionada.'
                );
            }

            if (
                $cupo_maximo
                < (int) $claseActual['cupo_actual']
            ) {
                throw new RuntimeException(
                    'El cupo máximo no puede ser menor al número de personas inscritas.'
                );
            }

            $stmt = $conn->prepare(
                "UPDATE clases
                 SET
                    nombre = ?,
                    descripcion = ?,
                    horario = ?,
                    instructor = ?,
                    cupo_maximo = ?,
                    duracion_minutos = ?,
                    estado = ?
                 WHERE id = ?
                   AND sucursal_id = ?"
            );

            $stmt->bind_param(
                'ssssiisii',
                $nombre,
                $descripcion,
                $horario,
                $instructor,
                $cupo_maximo,
                $duracion_minutos,
                $estado,
                $clase_id,
                $sucursal_operativa
            );

            $stmt->execute();

            if ($stmt->affected_rows < 0) {
                throw new RuntimeException(
                    'No se pudo actualizar la clase.'
                );
            }

            $stmt->close();

            clases_swal_guardar(
                'success',
                'Clase actualizada',
                'Los datos de la clase se actualizaron correctamente.'
            );
        }

        clases_redirect('clases.php', $contexto);
    } catch (Throwable $error) {
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
                    <?php if ($hay_entrenadores): ?>
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
                            class="btn-custom-primary"
                            type="button"
                            onclick="mostrarSinEntrenadores()"
                        >
                            <i class="fas fa-user-slash"></i>
                            Nueva Clase
                        </button>
                    <?php endif; ?>
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


        <?php if (!$vista_global && !$hay_entrenadores): ?>
            <div class="class-branch-warning class-trainer-warning">
                <i class="fas fa-user-slash"></i>
                <span>
                    No hay
                    <strong>entrenadores</strong> asignados a
                    <strong><?php echo clases_h($sucursal_nombre); ?></strong>.
                    Asigna un entrenador a esta sucursal para registrar o editar clases.
                </span>
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
                                <th>
                                    <a href="<?php echo clases_h(clases_url('clases.php', array_merge($baseQuery, [
                                        'sort' => 'nombre',
                                        'order' => $sort === 'nombre' && $order_dir === 'ASC' ? 'DESC' : 'ASC',
                                    ]))); ?>">
                                        Clase
                                    </a>
                                </th>

                                <?php if ($vista_global): ?>
                                    <th>
                                        <a href="<?php echo clases_h(clases_url('clases.php', array_merge($baseQuery, [
                                            'sort' => 'sucursal',
                                            'order' => $sort === 'sucursal' && $order_dir === 'ASC' ? 'DESC' : 'ASC',
                                        ]))); ?>">
                                            Sucursal
                                        </a>
                                    </th>
                                <?php endif; ?>

                                <th>Instructor</th>
                                <th>Horario</th>
                                <th>Cupo</th>
                                <th>Duración</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($clases as $clase): ?>
                                <?php
                                $cupoMaximo = max(
                                    1,
                                    (int) $clase['cupo_maximo']
                                );
                                $cupoActual = max(
                                    0,
                                    (int) $clase['cupo_actual']
                                );
                                $cupoDisponible = max(
                                    0,
                                    $cupoMaximo - $cupoActual
                                );
                                $porcentajeOcupado =
                                    ($cupoActual / $cupoMaximo) * 100;

                                if ($cupoDisponible === 0) {
                                    $cupoClass = 'text-danger';
                                    $cupoTexto = 'Completo';
                                } elseif ($porcentajeOcupado >= 80) {
                                    $cupoClass = 'text-warning';
                                    $cupoTexto =
                                        $cupoDisponible
                                        . ' disponibles';
                                } else {
                                    $cupoClass = 'text-success';
                                    $cupoTexto =
                                        $cupoDisponible
                                        . ' disponibles';
                                }
                                ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <?php echo clases_h($clase['nombre']); ?>
                                        </strong>
                                        <br>
                                        <small class="text-muted">
                                            <?php echo clases_h(
                                                mb_substr(
                                                    (string) $clase['descripcion'],
                                                    0,
                                                    50
                                                )
                                            ); ?>
                                        </small>
                                    </td>

                                    <?php if ($vista_global): ?>
                                        <td>
                                            <span class="class-branch-pill">
                                                <i class="fas fa-building"></i>
                                                <span>
                                                    <strong>
                                                        <?php echo clases_h($clase['sucursal_nombre']); ?>
                                                    </strong>
                                                    <small>
                                                        <?php echo clases_h($clase['sucursal_clave']); ?>
                                                        <?php if ((int) $clase['sucursal_es_matriz'] === 1): ?>
                                                            · Matriz
                                                        <?php endif; ?>
                                                    </small>
                                                </span>
                                            </span>
                                        </td>
                                    <?php endif; ?>

                                    <td>
                                        <?php echo clases_h($clase['instructor']); ?>
                                    </td>

                                    <td>
                                        <i class="far fa-clock"></i>
                                        <?php echo clases_h($clase['horario']); ?>
                                    </td>

                                    <td>
                                        <span class="<?php echo $cupoClass; ?>">
                                            <?php echo $cupoActual; ?>/<?php echo $cupoMaximo; ?>
                                            <small>
                                                (<?php echo clases_h($cupoTexto); ?>)
                                            </small>
                                        </span>
                                    </td>

                                    <td>
                                        <?php echo (int) $clase['duracion_minutos']; ?>
                                        min
                                    </td>

                                    <td>
                                        <?php if ($clase['estado'] === 'activa'): ?>
                                            <span class="badge-activa">
                                                Activa
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-inactiva">
                                                Inactiva
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if (!$vista_global): ?>
                                            <div class="acciones-clase">
                                                <?php if ($hay_entrenadores): ?>
                                                    <button
                                                        type="button"
                                                        class="btn-accion btn-editar"
                                                        onclick="editarClase(<?php echo (int) $clase['id']; ?>)"
                                                        title="Editar"
                                                    >
                                                        <i class="fas fa-edit"></i>
                                                        Editar
                                                    </button>
                                                <?php else: ?>
                                                    <button
                                                        type="button"
                                                        class="btn-accion btn-editar"
                                                        onclick="mostrarSinEntrenadores()"
                                                        title="No hay entrenadores disponibles"
                                                    >
                                                        <i class="fas fa-user-slash"></i>
                                                        Editar
                                                    </button>
                                                <?php endif; ?>

                                                <button
                                                    type="button"
                                                    class="btn-accion btn-eliminar"
                                                    onclick='eliminarClase(
                                                        <?php echo (int) $clase['id']; ?>,
                                                        <?php echo json_encode(
                                                            (string) $clase['nombre'],
                                                            JSON_HEX_TAG
                                                            | JSON_HEX_AMP
                                                            | JSON_HEX_APOS
                                                            | JSON_HEX_QUOT
                                                        ); ?>
                                                    )'
                                                    title="Eliminar"
                                                >
                                                    <i class="fas fa-trash"></i>
                                                    Eliminar
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <span class="class-readonly">
                                                <i class="fas fa-eye"></i>
                                                Consulta
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if ($clases === []): ?>
                                <tr>
                                    <td colspan="<?php echo $vista_global ? 8 : 7; ?>">
                                        <div class="empty-state">
                                            <i class="fas fa-chalkboard"></i>
                                            <h3>
                                                No hay clases registradas
                                            </h3>
                                            <p>
                                                <?php echo $vista_global
                                                    ? 'No existen clases que coincidan con los filtros.'
                                                    : 'Crea una nueva clase o modifica los filtros.';
                                                ?>
                                            </p>
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
        <div
            class="modal fade"
            id="modalNuevaClase"
            tabindex="-1"
        >
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content modal-custom">
                    <div class="modal-header modal-header-custom">
                        <h5 class="modal-title">
                            <i class="fas fa-plus-circle"></i>
                            Nueva Clase
                        </h5>
                        <button
                            type="button"
                            class="btn-close btn-close-white"
                            data-bs-dismiss="modal"
                        ></button>
                    </div>

                    <form
                        id="formNuevaClase"
                        method="POST"
                    >
                        <input
                            type="hidden"
                            name="action"
                            value="crear_clase"
                        >

                        <div class="modal-body">
                            <div class="class-modal-branch">
                                <i class="fas fa-building"></i>
                                Se registrará en
                                <strong>
                                    <?php echo clases_h($sucursal_nombre); ?>
                                </strong>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    Nombre de la clase *
                                </label>
                                <input
                                    type="text"
                                    class="form-control"
                                    name="nombre"
                                    required
                                >
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    Descripción
                                </label>
                                <textarea
                                    class="form-control"
                                    name="descripcion"
                                    rows="3"
                                    placeholder="Descripción de la clase..."
                                ></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    Horario *
                                </label>
                                <input
                                    type="text"
                                    class="form-control"
                                    name="horario"
                                    placeholder="Ej: Lunes y Miércoles 19:00 - 20:00"
                                    required
                                >
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    Entrenador *
                                </label>

                                <select
                                    class="form-select"
                                    name="instructor_usuario_id"
                                    required
                                >
                                    <option value="">
                                        Seleccionar entrenador
                                    </option>

                                    <?php foreach ($entrenadores as $entrenador): ?>
                                        <option value="<?php echo (int) $entrenador['id']; ?>">
                                            <?php echo clases_h(
                                                $entrenador['nombre']
                                                . (
                                                    trim((string) $entrenador['email']) !== ''
                                                        ? ' · ' . $entrenador['email']
                                                        : ''
                                                )
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <small class="class-trainer-note">
                                    Solo aparecen entrenadores activos asignados a esta sucursal.
                                </small>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">
                                        Cupo máximo *
                                    </label>
                                    <input
                                        type="number"
                                        class="form-control"
                                        name="cupo_maximo"
                                        min="1"
                                        value="20"
                                        required
                                    >
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">
                                        Duración (minutos) *
                                    </label>
                                    <input
                                        type="number"
                                        class="form-control"
                                        name="duracion_minutos"
                                        min="15"
                                        step="15"
                                        value="60"
                                        required
                                    >
                                </div>
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
                                Guardar Clase
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div
            class="modal fade"
            id="modalEditarClase"
            tabindex="-1"
        >
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content modal-custom">
                    <div class="modal-header modal-header-custom">
                        <h5 class="modal-title">
                            <i class="fas fa-edit"></i>
                            Editar Clase
                        </h5>
                        <button
                            type="button"
                            class="btn-close btn-close-white"
                            data-bs-dismiss="modal"
                        ></button>
                    </div>

                    <form
                        id="formEditarClase"
                        method="POST"
                    >
                        <input
                            type="hidden"
                            name="action"
                            value="editar_clase"
                        >
                        <input
                            type="hidden"
                            name="clase_id"
                            id="edit_clase_id"
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
                                    Nombre de la clase *
                                </label>
                                <input
                                    type="text"
                                    class="form-control"
                                    name="nombre"
                                    id="edit_nombre"
                                    required
                                >
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    Descripción
                                </label>
                                <textarea
                                    class="form-control"
                                    name="descripcion"
                                    id="edit_descripcion"
                                    rows="3"
                                ></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    Horario *
                                </label>
                                <input
                                    type="text"
                                    class="form-control"
                                    name="horario"
                                    id="edit_horario"
                                    required
                                >
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    Entrenador *
                                </label>

                                <select
                                    class="form-select"
                                    name="instructor_usuario_id"
                                    id="edit_instructor_usuario_id"
                                    required
                                >
                                    <option value="">
                                        Seleccionar entrenador
                                    </option>

                                    <?php foreach ($entrenadores as $entrenador): ?>
                                        <option value="<?php echo (int) $entrenador['id']; ?>">
                                            <?php echo clases_h(
                                                $entrenador['nombre']
                                                . (
                                                    trim((string) $entrenador['email']) !== ''
                                                        ? ' · ' . $entrenador['email']
                                                        : ''
                                                )
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <small
                                    class="class-trainer-note d-none"
                                    id="edit_instructor_legacy"
                                ></small>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">
                                        Cupo máximo *
                                    </label>
                                    <input
                                        type="number"
                                        class="form-control"
                                        name="cupo_maximo"
                                        id="edit_cupo_maximo"
                                        min="1"
                                        required
                                    >
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">
                                        Duración (minutos) *
                                    </label>
                                    <input
                                        type="number"
                                        class="form-control"
                                        name="duracion_minutos"
                                        id="edit_duracion_minutos"
                                        min="15"
                                        step="15"
                                        required
                                    >
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    Estado *
                                </label>
                                <select
                                    class="form-select"
                                    name="estado"
                                    id="edit_estado"
                                    required
                                >
                                    <option value="activa">
                                        Activa
                                    </option>
                                    <option value="inactiva">
                                        Inactiva
                                    </option>
                                </select>
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
                                Actualizar Clase
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
            const search =
                $('#searchInput').val().trim();
            const estado =
                $('#estadoSelect').val();

            const params = new URLSearchParams({
                vista: vista,
                search: search,
                estado: estado
            });

            window.location.href =
                'clases.php?' + params.toString();
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

        $('#limpiarFiltros').on(
            'click',
            function () {
                window.location.href =
                    'clases.php?vista='
                    + encodeURIComponent(vista);
            }
        );

        $('#formNuevaClase, #formEditarClase').on(
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
                    '<i class="fas fa-spinner fa-spin"></i> Procesando...'
                );

                return true;
            }
        );

        $('button[type="submit"]').each(function () {
            $(this).data(
                'original-text',
                $(this).html()
            );
        });

        $('#modalNuevaClase, #modalEditarClase').on(
            'hidden.bs.modal',
            function () {
                const $btn = $(this).find(
                    'button[type="submit"]'
                );

                $btn.prop('disabled', false).html(
                    $btn.data('original-text')
                    || 'Guardar'
                );

                $btn.removeData('submitted');

                const form = $(this).find('form')[0];

                if (form) {
                    form.reset();
                }
            }
        );

        window.mostrarSinEntrenadores = function () {
            Swal.fire({
                icon: 'warning',
                title: 'No hay entrenadores disponibles',
                text:
                    'Asigna un entrenador a esta sucursal antes de registrar o editar una clase.',
                confirmButtonText: 'Entendido',
                confirmButtonColor: '#1e3a8a'
            });
        };

        window.editarClase = function (id) {
            $.ajax({
                url: 'includes/obtener_clase.php',
                method: 'POST',
                data: {id: id},
                dataType: 'json',
                success: function (data) {
                    if (!data.success) {
                        Swal.fire(
                            'Clase no disponible',
                            data.message
                            || 'No se pudo obtener la clase.',
                            'error'
                        );
                        return;
                    }

                    $('#edit_clase_id').val(data.id);
                    $('#edit_nombre').val(data.nombre);
                    $('#edit_descripcion').val(
                        data.descripcion
                    );
                    $('#edit_horario').val(data.horario);
                    const entrenadorId = String(
                        data.instructor_usuario_id || ''
                    );

                    $('#edit_instructor_usuario_id').val(
                        entrenadorId
                    );

                    const $notaInstructor =
                        $('#edit_instructor_legacy');

                    if (entrenadorId === '') {
                        $notaInstructor
                            .removeClass('d-none')
                            .text(
                                'Instructor guardado anteriormente: '
                                + (data.instructor || 'Sin identificar')
                                + '. Selecciona un entrenador activo de la sucursal.'
                            );
                    } else {
                        $notaInstructor
                            .addClass('d-none')
                            .text('');
                    }

                    $('#edit_cupo_maximo').val(
                        data.cupo_maximo
                    );
                    $('#edit_duracion_minutos').val(
                        data.duracion_minutos
                    );
                    $('#edit_estado').val(data.estado);

                    const modal = bootstrap.Modal
                        .getOrCreateInstance(
                            document.getElementById(
                                'modalEditarClase'
                            )
                        );

                    modal.show();
                },
                error: function () {
                    Swal.fire(
                        'Error',
                        'Error al cargar los datos de la clase.',
                        'error'
                    );
                }
            });
        };

        window.eliminarClase = function (
            id,
            nombre
        ) {
            Swal.fire({
                title: '¿Eliminar clase?',
                html:
                    '¿Deseas eliminar "<strong>'
                    + nombre
                    + '</strong>"?<br>'
                    + '<small class="text-danger">'
                    + 'Esta acción no se puede deshacer.'
                    + '</small>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                const params = new URLSearchParams({
                    vista: vista,
                    eliminar: String(id)
                });

                window.location.href =
                    'clases.php?' + params.toString();
            });
        };
    })();
    </script>
</body>
</html>
