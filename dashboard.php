<?php
// Archivo: dashboard.php
// Dashboard por sucursal con vista global para administradores.

/*
 * El auth_guard valida la sesión, el usuario, el rol efectivo y la
 * sucursal operativa antes de cargar el dashboard.
 */
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';

date_default_timezone_set(
    (string) (
        $_SESSION['sucursal_zona_horaria']
        ?? 'America/Mexico_City'
    )
);

$user_rol = strtolower(trim((string) ($_SESSION['user_rol'] ?? '')));

if ($user_rol === 'administrador') {
    $user_rol = 'admin';
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$user_name = (string) ($_SESSION['user_name'] ?? 'Usuario');
$sucursal_id = (int) ($_SESSION['sucursal_id'] ?? 0);
$sucursal_nombre = trim((string) ($_SESSION['sucursal_nombre'] ?? 'Sucursal'));

/* Evita que el JavaScript del temporizador reciba un valor vacío. */
if (empty($_SESSION['login_time'])) {
    $_SESSION['login_time'] = time();
}

if ($user_id <= 0 || $sucursal_id <= 0) {
    header('Location: login.php?error=sesion_requerida');
    exit();
}

$database = new Database();
$db = $database->getConnection();

if (!$db instanceof mysqli) {
    die('Error de conexión a la base de datos');
}

$db->set_charset('utf8mb4');

/**
 * Ejecuta una consulta preparada y devuelve todas las filas.
 * Compatible con PHP 7 y PHP 8.
 */
function dashboardConsultarFilas(
    mysqli $db,
    string $sql,
    string $tipos = '',
    array $parametros = []
): array {
    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'No fue posible preparar la consulta del dashboard: ' . $db->error
        );
    }

    if ($tipos !== '' && $parametros !== []) {
        $argumentos = [$tipos];

        foreach ($parametros as $indice => $valor) {
            $argumentos[] = &$parametros[$indice];
        }

        call_user_func_array([$stmt, 'bind_param'], $argumentos);
    }

    if (!$stmt->execute()) {
        $detalle = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'No fue posible ejecutar la consulta del dashboard: ' . $detalle
        );
    }

    $resultado = $stmt->get_result();
    $filas = [];

    if ($resultado) {
        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = $fila;
        }
    }

    $stmt->close();

    return $filas;
}

/** Devuelve un solo valor de una consulta preparada. */
function dashboardConsultarValor(
    mysqli $db,
    string $sql,
    string $campo,
    string $tipos = '',
    array $parametros = [],
    $predeterminado = 0
) {
    $filas = dashboardConsultarFilas(
        $db,
        $sql,
        $tipos,
        $parametros
    );

    if ($filas === [] || !array_key_exists($campo, $filas[0])) {
        return $predeterminado;
    }

    return $filas[0][$campo] ?? $predeterminado;
}

// Verificar si el usuario necesita cambiar la contraseña.
$require_password_change = false;

$usuarioFilas = dashboardConsultarFilas(
    $db,
    "SELECT password_change_required, estado
     FROM usuarios
     WHERE id = ?
     LIMIT 1",
    'i',
    [$user_id]
);

if ($usuarioFilas !== []) {
    $usuario = $usuarioFilas[0];
    $require_password_change =
        (int) ($usuario['password_change_required'] ?? 0) === 1;

    if (strtolower((string) ($usuario['estado'] ?? '')) !== 'activo') {
        session_destroy();
        header('Location: login.php?error=usuario_inactivo');
        exit();
    }
}

// Mensajes de cambio de contraseña.
if (isset($_SESSION['password_change_success'])) {
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: 'success',
                title: '¡Contraseña actualizada!',
                text: 'Tu contraseña ha sido cambiada exitosamente',
                confirmButtonColor: '#003366'
            });
        });
    </script>";

    unset($_SESSION['password_change_success']);
}

if (isset($_SESSION['password_change_error'])) {
    $mensajePassword = json_encode(
        (string) $_SESSION['password_change_error'],
        JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_APOS
        | JSON_HEX_AMP
        | JSON_HEX_QUOT
    );

    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: {$mensajePassword},
                confirmButtonColor: '#003366'
            });
        });
    </script>";

    unset($_SESSION['password_change_error']);
}

// Variables consumidas por la vista original.
$total_clientes = 0;
$total_inscripciones = 0;
$total_productos = 0;
$total_clases = 0;
$ingresos_mes = 0.0;
$asistencias_hoy = 0;
$todos_clientes = [];
$ultimos_clientes = [];
$todos_productos = [];
$productos_bajo_stock = [];
$todas_inscripciones = [];
$vencimientos_proximos = 0;
$todas_clases = [];
$proximas_clases = [];
$alumnos_entrenador = [];
$labels = [];
$datos = [];

$esAdmin = $user_rol === 'admin';
$esRecepcionista = $user_rol === 'recepcionista';
$esEntrenador = $user_rol === 'entrenador';

/*
 * La vista global se puede activar directamente con:
 * dashboard.php?vista=global
 *
 * También se conserva en sesión para que una recarga normal del dashboard
 * no pierda el consolidado. Al elegir una sede concreta, el API limpia
 * esta bandera y redirige con vista=sucursal.
 */
$rolBaseDashboard = strtolower(trim((string) (
    $_SESSION['user_rol_base'] ?? $user_rol
)));

$puedeVistaGlobalDashboard = in_array(
    $rolBaseDashboard,
    ['admin', 'administrador'],
    true
);

$vistaSolicitadaDashboard = strtolower(trim((string) (
    $_GET['vista'] ?? ''
)));

if ($vistaSolicitadaDashboard === 'global') {
    if ($puedeVistaGlobalDashboard) {
        $_SESSION['dashboard_vista_global'] = 1;
    } else {
        unset($_SESSION['dashboard_vista_global']);
    }
} elseif (in_array(
    $vistaSolicitadaDashboard,
    ['sucursal', 'local'],
    true
)) {
    unset($_SESSION['dashboard_vista_global']);
}

$vista_global_dashboard =
    $puedeVistaGlobalDashboard
    && !empty($_SESSION['dashboard_vista_global']);

$total_sucursales_global = 0;

$dashboard_contexto_nombre = $vista_global_dashboard
    ? 'Todas las sucursales'
    : $sucursal_nombre;

$dashboard_contexto_clave = $vista_global_dashboard
    ? 'GLOBAL'
    : (string) ($_SESSION['sucursal_clave'] ?? '');

$dashboard_contexto_storage = $vista_global_dashboard
    ? 'global'
    : (string) $sucursal_id;

/*
 * La bienvenida se controla por inicio de sesión, no por sucursal.
 * Así cambiar de sede o entrar a la vista global no vuelve a mostrarla.
 */
$dashboard_bienvenida_storage_key =
    'welcomeAlertShown_'
    . $user_id
    . '_login_'
    . (int) ($_SESSION['login_time'] ?? 0);

$sucursal_es_matriz_dashboard = false;

try {
    if (!$vista_global_dashboard) {
        $sucursal_es_matriz_dashboard =
            (int) dashboardConsultarValor(
                $db,
                "SELECT es_matriz
                 FROM sucursales
                 WHERE id = ?
                 LIMIT 1",
                'es_matriz',
                'i',
                [$sucursal_id],
                0
            ) === 1;
    }
    if ($vista_global_dashboard) {
        $total_sucursales_global = (int) dashboardConsultarValor(
            $db,
            "SELECT COUNT(*) AS total
             FROM sucursales
             WHERE estado = 'activa'",
            'total',
            '',
            [],
            0
        );

        /* Socios únicos de todo el gimnasio. */
        $todos_clientes = dashboardConsultarFilas(
            $db,
            "SELECT
                c.id,
                c.nombre,
                c.apellido,
                c.telefono,
                c.email,
                c.fecha_registro,
                suc.nombre AS sucursal_nombre
             FROM clientes c
             LEFT JOIN sucursales suc
                ON suc.id = c.sucursal_registro_id
             WHERE c.estado = 'activo'
             ORDER BY c.fecha_registro DESC"
        );

        /* Inscripciones únicas; no se duplican por acceso multisede. */
        $todas_inscripciones = dashboardConsultarFilas(
            $db,
            "SELECT
                i.id,
                c.nombre AS cliente_nombre,
                c.apellido AS cliente_apellido,
                p.nombre AS plan_nombre,
                i.fecha_inicio,
                i.fecha_fin,
                i.precio_pagado,
                i.estado,
                suc.nombre AS sucursal_nombre
             FROM inscripciones i
             INNER JOIN clientes c
                ON c.id = i.cliente_id
             INNER JOIN planes p
                ON p.id = i.plan_id
             LEFT JOIN sucursales suc
                ON suc.id = i.sucursal_id
             WHERE i.estado = 'activa'
             ORDER BY i.fecha_fin ASC"
        );

        $vencimientos_proximos = (int) dashboardConsultarValor(
            $db,
            "SELECT COUNT(*) AS total
             FROM inscripciones
             WHERE estado = 'activa'
               AND fecha_fin BETWEEN CURDATE()
                   AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)",
            'total'
        );

        $asistencias_hoy = (int) dashboardConsultarValor(
            $db,
            "SELECT COUNT(*) AS total
             FROM asistencias
             WHERE fecha = CURDATE()",
            'total'
        );

        $todas_clases = dashboardConsultarFilas(
            $db,
            "SELECT
                c.id,
                c.nombre,
                c.descripcion,
                c.horario,
                c.instructor,
                c.cupo_maximo,
                c.cupo_actual,
                c.duracion_minutos,
                c.estado,
                suc.nombre AS sucursal_nombre
             FROM clases c
             LEFT JOIN sucursales suc
                ON suc.id = c.sucursal_id
             WHERE c.estado = 'activa'
             ORDER BY c.horario ASC"
        );

        if ($esAdmin || $esRecepcionista) {
            /*
             * Un producto aparece una vez y el stock representa la suma
             * existente en todas las sucursales activas.
             */
            $todos_productos = dashboardConsultarFilas(
                $db,
                "SELECT
                    p.id,
                    p.nombre,
                    p.descripcion,
                    SUM(inv.stock) AS stock,
                    SUM(inv.stock_minimo) AS stock_minimo,
                    CASE
                        WHEN SUM(inv.stock) > 0 THEN
                            SUM(inv.precio_venta * inv.stock)
                            / SUM(inv.stock)
                        ELSE MAX(inv.precio_venta)
                    END AS precio_venta,
                    categoria.nombre AS categoria
                 FROM inventario_sucursales inv
                 INNER JOIN productos p
                    ON p.id = inv.producto_id
                 INNER JOIN sucursales suc
                    ON suc.id = inv.sucursal_id
                   AND suc.estado = 'activa'
                 LEFT JOIN categorias_productos categoria
                    ON categoria.id = p.categoria_id
                 WHERE inv.estado = 'activo'
                   AND p.estado = 'activo'
                 GROUP BY
                    p.id,
                    p.nombre,
                    p.descripcion,
                    categoria.nombre
                 ORDER BY p.nombre ASC"
            );
        }

        if ($esAdmin) {
            $ingresos_mes = (float) dashboardConsultarValor(
                $db,
                "SELECT COALESCE(SUM(pag.monto), 0) AS total
                 FROM pagos pag
                 INNER JOIN sucursales suc_pago
                    ON suc_pago.id = pag.sucursal_id
                 WHERE MONTH(pag.fecha_pago) = MONTH(CURDATE())
                   AND YEAR(pag.fecha_pago) = YEAR(CURDATE())
                   AND pag.estado = 'completado'",
                'total'
            );

            $ingresosFilas = dashboardConsultarFilas(
                $db,
                "SELECT
                    DATE_FORMAT(pag.fecha_pago, '%Y-%m') AS mes,
                    COALESCE(SUM(pag.monto), 0) AS total
                 FROM pagos pag
                 INNER JOIN sucursales suc_pago
                    ON suc_pago.id = pag.sucursal_id
                 WHERE pag.fecha_pago >= DATE_SUB(
                        DATE_FORMAT(CURDATE(), '%Y-%m-01'),
                        INTERVAL 5 MONTH
                   )
                   AND pag.estado = 'completado'
                 GROUP BY DATE_FORMAT(pag.fecha_pago, '%Y-%m')
                 ORDER BY mes ASC"
            );
        } else {
            $ingresosFilas = [];
        }
    } else {
        /* Socios registrados o habilitados para la sucursal activa. */
        $todos_clientes = dashboardConsultarFilas(
            $db,
            "SELECT
                c.id,
                c.nombre,
                c.apellido,
                c.telefono,
                c.email,
                c.fecha_registro
             FROM clientes c
             WHERE c.estado = 'activo'
               AND (
                    c.sucursal_registro_id = ?
                    OR EXISTS (
                        SELECT 1
                        FROM inscripciones i_cliente
                        LEFT JOIN inscripciones_sucursales is_cliente
                            ON is_cliente.inscripcion_id = i_cliente.id
                        WHERE i_cliente.cliente_id = c.id
                          AND i_cliente.estado = 'activa'
                          AND CURDATE() BETWEEN i_cliente.fecha_inicio
                              AND i_cliente.fecha_fin
                          AND (
                               i_cliente.sucursal_id = ?
                               OR is_cliente.sucursal_id = ?
                          )
                    )
               )
             ORDER BY c.fecha_registro DESC",
            'iii',
            [$sucursal_id, $sucursal_id, $sucursal_id]
        );

        $todas_inscripciones = dashboardConsultarFilas(
            $db,
            "SELECT DISTINCT
                i.id,
                c.nombre AS cliente_nombre,
                c.apellido AS cliente_apellido,
                p.nombre AS plan_nombre,
                i.fecha_inicio,
                i.fecha_fin,
                i.precio_pagado,
                i.estado
             FROM inscripciones i
             INNER JOIN clientes c
                ON c.id = i.cliente_id
             INNER JOIN planes p
                ON p.id = i.plan_id
             LEFT JOIN inscripciones_sucursales isuc
                ON isuc.inscripcion_id = i.id
               AND isuc.sucursal_id = ?
             WHERE i.estado = 'activa'
               AND (
                    i.sucursal_id = ?
                    OR isuc.sucursal_id = ?
               )
             ORDER BY i.fecha_fin ASC",
            'iii',
            [$sucursal_id, $sucursal_id, $sucursal_id]
        );

        $vencimientos_proximos = (int) dashboardConsultarValor(
            $db,
            "SELECT COUNT(DISTINCT i.id) AS total
             FROM inscripciones i
             LEFT JOIN inscripciones_sucursales isuc
                ON isuc.inscripcion_id = i.id
               AND isuc.sucursal_id = ?
             WHERE i.estado = 'activa'
               AND i.fecha_fin BETWEEN CURDATE()
                   AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
               AND (
                    i.sucursal_id = ?
                    OR isuc.sucursal_id = ?
               )",
            'total',
            'iii',
            [$sucursal_id, $sucursal_id, $sucursal_id]
        );

        $asistencias_hoy = (int) dashboardConsultarValor(
            $db,
            "SELECT COUNT(*) AS total
             FROM asistencias
             WHERE sucursal_id = ?
               AND fecha = CURDATE()",
            'total',
            'i',
            [$sucursal_id]
        );

        $todas_clases = dashboardConsultarFilas(
            $db,
            "SELECT
                c.id,
                c.nombre,
                c.descripcion,
                c.horario,
                c.instructor,
                c.cupo_maximo,
                c.cupo_actual,
                c.duracion_minutos,
                c.estado
             FROM clases c
             WHERE c.sucursal_id = ?
               AND c.estado = 'activa'
             ORDER BY c.horario ASC",
            'i',
            [$sucursal_id]
        );

        if ($esEntrenador) {
            $clasesDelEntrenador = [];

            foreach ($todas_clases as $clase) {
                $instructor = trim(
                    (string) ($clase['instructor'] ?? '')
                );

                if (
                    $instructor !== ''
                    && (
                        stripos($instructor, $user_name) !== false
                        || stripos($user_name, $instructor) !== false
                    )
                ) {
                    $clasesDelEntrenador[] = $clase;
                }
            }

            $todas_clases = $clasesDelEntrenador;
        }

        if ($esAdmin || $esRecepcionista) {
            $todos_productos = dashboardConsultarFilas(
                $db,
                "SELECT
                    p.id,
                    p.nombre,
                    p.descripcion,
                    inv.stock,
                    inv.stock_minimo,
                    inv.precio_venta,
                    categoria.nombre AS categoria
                 FROM inventario_sucursales inv
                 INNER JOIN productos p
                    ON p.id = inv.producto_id
                 LEFT JOIN categorias_productos categoria
                    ON categoria.id = p.categoria_id
                 WHERE inv.sucursal_id = ?
                   AND inv.estado = 'activo'
                   AND p.estado = 'activo'
                 ORDER BY p.nombre ASC",
                'i',
                [$sucursal_id]
            );
        }

        if ($esAdmin) {
            $ingresos_mes = (float) dashboardConsultarValor(
                $db,
                "SELECT COALESCE(SUM(monto), 0) AS total
                 FROM pagos
                 WHERE sucursal_id = ?
                   AND MONTH(fecha_pago) = MONTH(CURDATE())
                   AND YEAR(fecha_pago) = YEAR(CURDATE())
                   AND estado = 'completado'",
                'total',
                'i',
                [$sucursal_id]
            );

            $ingresosFilas = dashboardConsultarFilas(
                $db,
                "SELECT
                    DATE_FORMAT(fecha_pago, '%Y-%m') AS mes,
                    COALESCE(SUM(monto), 0) AS total
                 FROM pagos
                 WHERE sucursal_id = ?
                   AND fecha_pago >= DATE_SUB(
                        DATE_FORMAT(CURDATE(), '%Y-%m-01'),
                        INTERVAL 5 MONTH
                   )
                   AND estado = 'completado'
                 GROUP BY DATE_FORMAT(fecha_pago, '%Y-%m')
                 ORDER BY mes ASC",
                'i',
                [$sucursal_id]
            );
        } else {
            $ingresosFilas = [];
        }
    }

    $ultimos_clientes = $todos_clientes;
    $total_clientes = count($todos_clientes);
    $total_inscripciones = count($todas_inscripciones);
    $proximas_clases = $todas_clases;
    $total_clases = count($todas_clases);
    $total_productos = count($todos_productos);

    foreach ($todos_productos as $producto) {
        if (
            (int) ($producto['stock'] ?? 0)
            <= (int) ($producto['stock_minimo'] ?? 0)
        ) {
            $productos_bajo_stock[] = $producto;
        }
    }

    usort(
        $productos_bajo_stock,
        static function (array $a, array $b): int {
            return (int) ($a['stock'] ?? 0)
                <=> (int) ($b['stock'] ?? 0);
        }
    );

    if ($esAdmin) {
        $ingresos_por_mes = [];

        foreach ($ingresosFilas as $ingresoFila) {
            $ingresos_por_mes[(string) $ingresoFila['mes']] =
                (float) $ingresoFila['total'];
        }

        for ($i = 5; $i >= 0; $i--) {
            $fecha = date('Y-m', strtotime("-{$i} months"));
            $labels[] = date('M Y', strtotime("-{$i} months"));
            $datos[] = isset($ingresos_por_mes[$fecha])
                ? (float) $ingresos_por_mes[$fecha]
                : 0.0;
        }
    }

    if ($esEntrenador) {
        $alumnos_entrenador = $todos_clientes;
    }
} catch (Throwable $dashboardError) {
    error_log(
        '[Dashboard multisucursal] ' . $dashboardError->getMessage()
    );

    die(
        'No fue posible cargar los datos del dashboard. '
        . 'Verifica que la migración multisucursal esté completa.'
    );
}

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo htmlspecialchars($dashboard_contexto_nombre, ENT_QUOTES, 'UTF-8'); ?></title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- AdminLTE / Bootstrap -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="css/dashboard.css">
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content dashboard-page">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div class="row">
                <div class="col-md-8">
                    <h3>
                        <i class="fas fa-hand-wave"></i> ¡Bienvenido, <?php echo htmlspecialchars($user_name); ?>!
                    </h3>
                    <div class="mb-2">
                        <span class="badge badge-primary">
                            <i class="fas <?php echo $vista_global_dashboard ? 'fa-chart-pie' : 'fa-building'; ?>"></i>

                            <?php if ($vista_global_dashboard): ?>
                                Vista global: Todas las sucursales
                                (<?php echo (int) $total_sucursales_global; ?>
                                <?php echo (int) $total_sucursales_global === 1
                                    ? 'sede'
                                    : 'sedes'; ?>)
                            <?php else: ?>
                                Sucursal:
                                <?php echo htmlspecialchars(
                                    $sucursal_nombre,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>

                                <?php if ($sucursal_es_matriz_dashboard): ?>
                                    · Matriz
                                <?php endif; ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <p class="access-time">
                        <i class="fas fa-clock"></i> 
                        Último acceso: <?php echo date('d/m/Y H:i:s', $_SESSION['login_time']); ?>
                        <span class="session-timer ml-3">
                            <i class="fas fa-hourglass-half"></i> 
                            Sesión expira en: <span id="session-timer">calculando...</span>
                        </span>
                    </p>
                </div>
                <div class="col-md-4 text-right">
                    <i class="fas fa-chart-line" style="font-size: 60px; opacity: 0.3;"></i>
                    <br>
                    <span class="badge badge-light mt-2">
                        <i class="fas fa-fingerprint"></i> <?php echo $asistencias_hoy; ?> asistencias hoy
                    </span>
                </div>
            </div>
        </div>

        <!-- Small boxes (Stat box) - VISTA ADMIN -->
        <?php if ($user_rol == 'admin'): ?>
        <div class="row">
            <div class="col-lg-3 col-6">
                <div class="small-box bg-info">
                    <div class="inner">
                        <h3><?php echo $total_clientes; ?></h3>
                        <p>Clientes Registrados</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <a href="javascript:void(0)" onclick="verTodosClientes()" class="small-box-footer">
                        Ver más <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
            
            <div class="col-lg-3 col-6">
                <div class="small-box bg-success">
                    <div class="inner">
                        <h3><?php echo $total_inscripciones; ?></h3>
                        <p>Inscripciones Activas</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-id-card"></i>
                    </div>
                    <a href="javascript:void(0)" onclick="verTodasInscripciones()" class="small-box-footer">
                        Ver más <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
            
            <div class="col-lg-3 col-6">
                <div class="small-box bg-warning">
                    <div class="inner">
                        <h3><?php echo $total_productos; ?></h3>
                        <p>Productos en Stock</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <a href="javascript:void(0)" onclick="verTodosProductos()" class="small-box-footer">
                        Ver más <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
            
            <div class="col-lg-3 col-6">
                <div class="small-box bg-danger">
                    <div class="inner">
                        <h3><?php echo $total_clases; ?></h3>
                        <p>Clases Activas</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <a href="javascript:void(0)" onclick="verTodasClases()" class="small-box-footer">
                        Ver más <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Small boxes - VISTA RECEPCIONISTA -->
        <?php if ($user_rol == 'recepcionista'): ?>
        <div class="row">
            <div class="col-lg-3 col-6">
                <div class="small-box bg-info">
                    <div class="inner">
                        <h3><?php echo $total_clientes; ?></h3>
                        <p>Clientes Registrados</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <a href="javascript:void(0)" onclick="verTodosClientes()" class="small-box-footer">
                        Ver más <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
            
            <div class="col-lg-3 col-6">
                <div class="small-box bg-success">
                    <div class="inner">
                        <h3><?php echo $total_inscripciones; ?></h3>
                        <p>Inscripciones Activas</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-id-card"></i>
                    </div>
                    <a href="javascript:void(0)" onclick="verTodasInscripciones()" class="small-box-footer">
                        Ver más <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
            
            <div class="col-lg-3 col-6">
                <div class="small-box bg-warning">
                    <div class="inner">
                        <h3><?php echo $total_productos; ?></h3>
                        <p>Productos en Stock</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <a href="javascript:void(0)" onclick="verTodosProductos()" class="small-box-footer">
                        Ver más <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
            
            <div class="col-lg-3 col-6">
                <div class="small-box bg-danger">
                    <div class="inner">
                        <h3><?php echo $total_clases; ?></h3>
                        <p>Clases Activas</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <a href="javascript:void(0)" onclick="verTodasClases()" class="small-box-footer">
                        Ver más <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Small boxes - VISTA ENTRENADOR -->
        <?php if ($user_rol == 'entrenador'): ?>
        <div class="row">
            <div class="col-lg-3 col-6">
                <div class="small-box bg-info">
                    <div class="inner">
                        <h3><?php echo $total_clientes; ?></h3>
                        <p>Alumnos Registrados</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <a href="javascript:void(0)" onclick="verTodosClientes()" class="small-box-footer">
                        Ver más <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
            
            <div class="col-lg-3 col-6">
                <div class="small-box bg-success">
                    <div class="inner">
                        <h3><?php echo $total_inscripciones; ?></h3>
                        <p>Inscripciones Activas</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-id-card"></i>
                    </div>
                    <a href="javascript:void(0)" onclick="verTodasInscripciones()" class="small-box-footer">
                        Ver más <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
            
            <div class="col-lg-3 col-6">
                <div class="small-box bg-danger">
                    <div class="inner">
                        <h3><?php echo $total_clases; ?></h3>
                        <p>Mis Clases</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <a href="javascript:void(0)" onclick="verTodasClases()" class="small-box-footer">
                        Ver más <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
            
            <div class="col-lg-3 col-6">
                <div class="small-box bg-warning">
                    <div class="inner">
                        <h3><?php echo $asistencias_hoy; ?></h3>
                        <p>Asistencias Hoy</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-fingerprint"></i>
                    </div>
                    <a href="asistencias.php" class="small-box-footer">
                        Registrar <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Charts and Tables Row - SOLO PARA ADMIN (tiene gráfico) -->
        <?php if ($user_rol == 'admin'): ?>
        <div class="row equal-height-cards">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-chart-line mr-2"></i>
                            Ingresos Mensuales
                        </h3>
                        <div class="card-tools">
                            <span class="badge badge-info">
                                <i class="fas fa-dollar-sign"></i> Total mes: $<?php echo number_format($ingresos_mes, 2); ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <canvas id="incomeChart" style="min-height: 250px; width: 100%; flex: 1;"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-user-plus mr-2"></i>
                            Últimos Clientes Registrados
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0 d-flex flex-column">
                        <div class="table-responsive flex-grow-1">
                            <table class="table table-striped dashboard-table">
                                <thead>
                                    <tr>
                                        <th>Nombre</th>
                                        <th>Teléfono</th>
                                        <th>Fecha</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ultimos_clientes as $cliente): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apellido']); ?></td>
                                        <td><?php echo htmlspecialchars($cliente['telefono'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($cliente['fecha_registro'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($ultimos_clientes)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center">No hay clientes registrados</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer text-center">
                        <a href="javascript:void(0)" onclick="verTodosClientes()" class="btn btn-sm btn-primary">Ver todos los clientes</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Últimos Clientes - PARA RECEPCIONISTA Y ENTRENADOR -->
        <?php if ($user_rol != 'admin'): ?>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-user-plus mr-2"></i>
                            Últimos Clientes Registrados
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped dashboard-table">
                                <thead>
                                    <tr>
                                        <th>Nombre</th>
                                        <th>Teléfono</th>
                                        <th>Fecha</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ultimos_clientes as $cliente): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apellido']); ?></td>
                                        <td><?php echo htmlspecialchars($cliente['telefono'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($cliente['fecha_registro'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($ultimos_clientes)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center">No hay clientes registrados</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer text-center">
                        <a href="javascript:void(0)" onclick="verTodosClientes()" class="btn btn-sm btn-primary">Ver todos los clientes</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Second Row - ADMIN: Próximas Clases + Stock Bajo -->
        <?php if ($user_rol == 'admin'): ?>
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-calendar-check mr-2"></i>
                            Próximas Clases
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped dashboard-table">
                                <thead>
                                    <tr>
                                        <th>Clase</th>
                                        <th>Horario</th>
                                        <th>Instructor</th>
                                        <th>Cupo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($proximas_clases as $clase): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($clase['nombre']); ?></td>
                                        <td><?php echo htmlspecialchars($clase['horario']); ?></td>
                                        <td><?php echo htmlspecialchars($clase['instructor'] ?? 'Por asignar'); ?></td>
                                        <td>
                                            <span class="badge badge-info"><?php echo $clase['cupo_actual']; ?>/<?php echo $clase['cupo_maximo']; ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($proximas_clases)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center">No hay clases programadas</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer text-center">
                        <a href="javascript:void(0)" onclick="verTodasClases()" class="btn btn-sm btn-primary">Ver todas las clases</a>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            Productos con Bajo Stock
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped dashboard-table">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th>Stock Actual</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($productos_bajo_stock as $producto): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($producto['nombre']); ?></td>
                                        <td><?php echo $producto['stock']; ?> unidades</td>
                                        <td>
                                            <?php if ($producto['stock'] <= 5): ?>
                                                <span class="badge badge-danger">Stock Crítico</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning">Stock Bajo</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($productos_bajo_stock)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-success">
                                            <i class="fas fa-check-circle"></i> Todos los productos tienen stock suficiente
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer text-center">
                        <a href="javascript:void(0)" onclick="verTodosProductos()" class="btn btn-sm btn-primary">Ver inventario completo</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Second Row - RECEPCIONISTA: Próximas Clases + Stock Bajo -->
        <?php if ($user_rol == 'recepcionista'): ?>
        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-calendar-check mr-2"></i>
                            Próximas Clases
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped dashboard-table">
                                <thead>
                                    <tr>
                                        <th>Clase</th>
                                        <th>Horario</th>
                                        <th>Instructor</th>
                                        <th>Cupo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($proximas_clases as $clase): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($clase['nombre']); ?></td>
                                        <td><?php echo htmlspecialchars($clase['horario']); ?></td>
                                        <td><?php echo htmlspecialchars($clase['instructor'] ?? 'Por asignar'); ?></td>
                                        <td>
                                            <span class="badge badge-info"><?php echo $clase['cupo_actual']; ?>/<?php echo $clase['cupo_maximo']; ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($proximas_clases)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center">No hay clases programadas</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer text-center">
                        <a href="javascript:void(0)" onclick="verTodasClases()" class="btn btn-sm btn-primary">Ver todas las clases</a>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            Productos con Bajo Stock
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped dashboard-table">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th>Stock Actual</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($productos_bajo_stock as $producto): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($producto['nombre']); ?></td>
                                        <td><?php echo $producto['stock']; ?> unidades</td>
                                        <td>
                                            <?php if ($producto['stock'] <= 5): ?>
                                                <span class="badge badge-danger">Stock Crítico</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning">Stock Bajo</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($productos_bajo_stock)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-success">
                                            <i class="fas fa-check-circle"></i> Todos los productos tienen stock suficiente
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer text-center">
                        <a href="javascript:void(0)" onclick="verTodosProductos()" class="btn btn-sm btn-primary">Ver inventario</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ENTRENADOR: Mis Clases (detalladas) -->
        <?php if ($user_rol == 'entrenador'): ?>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-chalkboard-teacher mr-2"></i>
                            Mis Clases
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped dashboard-table">
                                <thead>
                                    <tr>
                                        <th>Clase</th>
                                        <th>Horario</th>
                                        <th>Duración</th>
                                        <th>Cupo Actual</th>
                                        <th>Cupo Máximo</th>
                                        <th>Ocupación</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($todas_clases as $clase): 
                                        $porcentaje = ($clase['cupo_maximo'] > 0) ? ($clase['cupo_actual'] / $clase['cupo_maximo']) * 100 : 0;
                                        $ocupacion_color = $porcentaje >= 90 ? 'danger' : ($porcentaje >= 70 ? 'warning' : 'success');
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($clase['nombre']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($clase['horario']); ?></td>
                                        <td><?php echo $clase['duracion_minutos']; ?> min</td>
                                        <td><?php echo $clase['cupo_actual']; ?></td>
                                        <td><?php echo $clase['cupo_maximo']; ?></td>
                                        <td><div class="progress" style="width: 100px;"><div class="progress-bar bg-<?php echo $ocupacion_color; ?>" style="width: <?php echo $porcentaje; ?>%"></div></div></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($todas_clases)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center">No tienes clases asignadas</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer text-center">
                        <a href="clases.php" class="btn btn-sm btn-primary">Ver todas mis clases</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ENTRENADOR: Alumnos Activos -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-users mr-2"></i>
                            Alumnos Activos
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped dashboard-table">
                                <thead>
                                    <tr>
                                        <th>Alumno</th>
                                        <th>Teléfono</th>
                                        <th>Email</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($alumnos_entrenador as $alumno): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($alumno['nombre'] . ' ' . $alumno['apellido']); ?></td>
                                        <td><?php echo htmlspecialchars($alumno['telefono'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($alumno['email'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($alumnos_entrenador)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center">No hay alumnos registrados</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer text-center">
                        <a href="clientes.php" class="btn btn-sm btn-primary">Ver todos los alumnos</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Alertas de Vencimientos (solo admin y recepcionista) -->
        <?php if (($user_rol == 'admin' || $user_rol == 'recepcionista') && $vencimientos_proximos > 0): ?>
        <div class="row">
            <div class="col-12">
                <div class="card" style="border-left: 4px solid #ffc107;">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-bell mr-2"></i>
                            Inscripciones por Vencer
                        </h3>
                    </div>
                    <div class="card-body">
                        <p class="mb-0">
                            <i class="fas fa-calendar-times"></i> 
                            <strong><?php echo $vencimientos_proximos; ?> inscripciones</strong> están por vencer en los próximos 7 días.
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Acciones Rápidas - ADMIN (tiene acceso a clases) -->
        <?php if ($user_rol == 'admin'): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-bolt mr-2"></i>
                            Acciones Rápidas
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-2 col-6 mb-3">
                                <a href="inscripciones.php?action=nuevo_cliente" class="btn-app">
                                    <i class="fas fa-user-plus"></i> Nuevo Cliente
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="inscripciones.php?action=create" class="btn-app">
                                    <i class="fas fa-id-card"></i> Nueva Inscripción
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="asistencias.php" class="btn-app">
                                    <i class="fas fa-fingerprint"></i> Registrar Asistencia
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="clases.php?action=create" class="btn-app">
                                    <i class="fas fa-plus-circle"></i> Nueva Clase
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="productos.php?action=create" class="btn-app">
                                    <i class="fas fa-box"></i> Agregar Producto
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="reportes.php" class="btn-app">
                                    <i class="fas fa-chart-bar"></i> Generar Reporte
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Acciones Rápidas - RECEPCIONISTA (sin acceso a clases) -->
        <?php if ($user_rol == 'recepcionista'): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-bolt mr-2"></i>
                            Acciones Rápidas
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="row text-center justify-content-center">
                            <div class="col-md-2 col-6 mb-3">
                                <a href="inscripciones.php?action=nuevo_cliente" class="btn-app">
                                    <i class="fas fa-user-plus"></i> Nuevo Cliente
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="inscripciones.php?action=create" class="btn-app">
                                    <i class="fas fa-id-card"></i> Nueva Inscripción
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="asistencias.php" class="btn-app">
                                    <i class="fas fa-fingerprint"></i> Registrar Asistencia
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="productos.php?action=create" class="btn-app">
                                    <i class="fas fa-box"></i> Agregar Producto
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="reportes.php" class="btn-app">
                                    <i class="fas fa-chart-bar"></i> Generar Reporte
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Actions Row para ENTRENADOR -->
        <?php if ($user_rol == 'entrenador'): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-bolt mr-2"></i>
                            Acciones Rápidas
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-3 col-6 mb-3">
                                <a href="asistencias.php" class="btn-app">
                                    <i class="fas fa-fingerprint"></i> Registrar Asistencia
                                </a>
                            </div>
                            <div class="col-md-3 col-6 mb-3">
                                <a href="clases.php" class="btn-app">
                                    <i class="fas fa-chalkboard-teacher"></i> Ver Mis Clases
                                </a>
                            </div>
                            <div class="col-md-3 col-6 mb-3">
                                <a href="clientes.php" class="btn-app">
                                    <i class="fas fa-users"></i> Ver Alumnos
                                </a>
                            </div>
                            <div class="col-md-3 col-6 mb-3">
                                <a href="reportes.php?tipo=clases" class="btn-app">
                                    <i class="fas fa-chart-bar"></i> Reporte de Clases
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- MODALES (Clientes, Productos, Inscripciones, Clases) -->
    <!-- Modal de Clientes -->
    <div class="modal fade" id="modalClientes" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-users"></i> Clientes Registrados
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="stats-bar">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo count($todos_clientes); ?></div>
                        <div class="stat-label">Total Clientes</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number">
                            <?php 
                                $con_telefono = 0;
                                foreach($todos_clientes as $c) if($c['telefono']) $con_telefono++;
                                echo $con_telefono;
                            ?>
                        </div>
                        <div class="stat-label">Con Teléfono</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number">
                            <?php 
                                $con_email = 0;
                                foreach($todos_clientes as $c) if($c['email']) $con_email++;
                                echo $con_email;
                            ?>
                        </div>
                        <div class="stat-label">Con Email</div>
                    </div>
                </div>
                <div class="modal-search">
                    <div class="input-group">
                        <div class="input-group-prepend">
                        <span class="input-group-text">
                            <i class="fas fa-search"></i>
                        </span>
                        </div>
                        <input type="text" id="searchClientes" class="form-control" placeholder="Buscar cliente por nombre, teléfono o email...">
                    </div>
                </div>
                <div class="modal-body">
                    <div class="modal-grid" id="clientesGrid">
                        <?php foreach ($todos_clientes as $cliente): 
                            $inicial = strtoupper(substr($cliente['nombre'], 0, 1) . substr($cliente['apellido'], 0, 1));
                        ?>
                        <div class="client-card" data-name="<?php echo strtolower($cliente['nombre'] . ' ' . $cliente['apellido']); ?>" data-phone="<?php echo $cliente['telefono']; ?>" data-email="<?php echo $cliente['email']; ?>">
                            <div class="client-header">
                                <div class="client-avatar"><?php echo $inicial; ?></div>
                                <div>
                                    <div class="client-name"><?php echo htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apellido']); ?></div>
                                    <div class="client-date"><i class="far fa-calendar-alt"></i> <?php echo date('d/m/Y', strtotime($cliente['fecha_registro'])); ?></div>
                                </div>
                            </div>
                            <div class="client-info">
                                <div class="info-item">
                                    <i class="fas fa-phone"></i>
                                    <span><?php echo htmlspecialchars($cliente['telefono'] ?? 'No registrado'); ?></span>
                                </div>
                                <div class="info-item">
                                    <i class="fas fa-envelope"></i>
                                    <span><?php echo htmlspecialchars($cliente['email'] ?? 'No registrado'); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($todos_clientes)): ?>
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <p>No hay clientes registrados</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-close-modal" data-dismiss="modal">
                        <i class="fas fa-times"></i> Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Productos -->
    <div class="modal fade" id="modalProductos" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-boxes"></i> Inventario de Productos
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="stats-bar">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo count($todos_productos); ?></div>
                        <div class="stat-label">Total Productos</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number">
                            <?php 
                                $stock_bajo = 0;
                                foreach($todos_productos as $p) if($p['stock'] <= $p['stock_minimo']) $stock_bajo++;
                                echo $stock_bajo;
                            ?>
                        </div>
                        <div class="stat-label">Stock Bajo</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number">
                            <?php 
                                $stock_total = 0;
                                foreach($todos_productos as $p) $stock_total += $p['stock'];
                                echo $stock_total;
                            ?>
                        </div>
                        <div class="stat-label">Unidades Totales</div>
                    </div>
                </div>
                <div class="modal-search">
                    <div class="input-group">
                        <div class="input-group-prepend">
                        <span class="input-group-text">
                            <i class="fas fa-search"></i>
                        </span>
                        </div>
                        <input type="text" id="searchProductos" class="form-control" placeholder="Buscar producto por nombre o categoría...">
                    </div>
                </div>
                <div class="modal-body">
                    <div class="modal-grid" id="productosGrid">
                        <?php foreach ($todos_productos as $producto): 
                            $stock = $producto['stock'];
                            $minimo = $producto['stock_minimo'];
                            $porcentaje = ($minimo > 0) ? ($stock / $minimo) * 100 : 100;
                            $stock_class = $stock <= 5 ? 'critical' : ($stock <= $minimo ? 'low' : 'normal');
                        ?>
                        <div class="product-card" data-name="<?php echo strtolower($producto['nombre']); ?>" data-category="<?php echo strtolower($producto['categoria'] ?? ''); ?>">
                            <div class="product-header">
                                <div class="product-name"><?php echo htmlspecialchars($producto['nombre']); ?></div>
                                <div class="product-category"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($producto['categoria'] ?? 'Sin categoría'); ?></div>
                            </div>
                            <div class="product-stats">
                                <div class="stock-info">
                                    <div class="stock-number <?php echo $stock_class; ?>"><?php echo $stock; ?></div>
                                    <div class="stock-label">Unidades</div>
                                </div>
                                <div class="price-info">
                                    <div class="price-number">$<?php echo number_format($producto['precio_venta'], 2); ?></div>
                                    <div class="stock-label">Precio Venta</div>
                                </div>
                            </div>
                            <div class="stock-bar">
                                <div class="stock-fill <?php echo $stock_class; ?>" style="width: <?php echo min(100, $porcentaje); ?>%"></div>
                            </div>
                            <div class="info-item" style="margin-top: 10px; font-size: 0.7rem; color: #6c757d;">
                                <i class="fas fa-chart-line"></i> Stock mínimo: <?php echo $minimo; ?> unidades
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($todos_productos)): ?>
                        <div class="empty-state">
                            <i class="fas fa-boxes"></i>
                            <p>No hay productos registrados</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-close-modal" data-dismiss="modal">
                        <i class="fas fa-times"></i> Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Inscripciones -->
    <div class="modal fade" id="modalInscripciones" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-id-card"></i> Inscripciones Activas
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="stats-bar">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo count($todas_inscripciones); ?></div>
                        <div class="stat-label">Inscripciones Activas</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number">
                            <?php 
                                $vencen_7dias = 0;
                                foreach($todas_inscripciones as $ins) {
                                    $fecha_fin = new DateTime($ins['fecha_fin']);
                                    $hoy = new DateTime();
                                    $diff = $hoy->diff($fecha_fin)->days;
                                    if($fecha_fin >= $hoy && $diff <= 7) $vencen_7dias++;
                                }
                                echo $vencen_7dias;
                            ?>
                        </div>
                        <div class="stat-label">Vencen pronto</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number">
                            <?php 
                                $total_ingresos = 0;
                                foreach($todas_inscripciones as $ins) $total_ingresos += $ins['precio_pagado'];
                                echo '$' . number_format($total_ingresos, 0);
                            ?>
                        </div>
                        <div class="stat-label">Ingresos Totales</div>
                    </div>
                </div>
                <div class="modal-search">
                    <div class="input-group">
                        <div class="input-group-prepend">
                        <span class="input-group-text">
                            <i class="fas fa-search"></i>
                        </span>
                    </div>
                    <input type="text" id="searchInscripciones" class="form-control" placeholder="Buscar por cliente o plan...">
                    </div>
                </div>
                <div class="modal-body">
                    <div class="modal-grid" id="inscripcionesGrid">
                        <?php foreach ($todas_inscripciones as $inscripcion): 
                            $fecha_fin = new DateTime($inscripcion['fecha_fin']);
                            $hoy = new DateTime();
                            $dias_restantes = $hoy->diff($fecha_fin)->days;
                            if ($fecha_fin < $hoy) $dias_restantes = 0;
                            $es_urgente = ($dias_restantes <= 7 && $dias_restantes > 0);
                        ?>
                        <div class="inscripcion-card <?php echo $es_urgente ? 'urgent' : ''; ?>" data-name="<?php echo strtolower($inscripcion['cliente_nombre'] . ' ' . $inscripcion['cliente_apellido']); ?>" data-plan="<?php echo strtolower($inscripcion['plan_nombre']); ?>">
                            <div class="inscripcion-header">
                                <div class="cliente-name"><?php echo htmlspecialchars($inscripcion['cliente_nombre'] . ' ' . $inscripcion['cliente_apellido']); ?></div>
                                <div class="plan-name"><i class="fas fa-dumbbell"></i> <?php echo htmlspecialchars($inscripcion['plan_nombre']); ?></div>
                            </div>
                            <div class="fechas">
                                <div class="fecha-item">
                                    <span class="fecha-label">Inicio</span>
                                    <span class="fecha-value"><?php echo date('d/m/Y', strtotime($inscripcion['fecha_inicio'])); ?></span>
                                </div>
                                <div class="fecha-item">
                                    <span class="fecha-label">Fin</span>
                                    <span class="fecha-value"><?php echo date('d/m/Y', strtotime($inscripcion['fecha_fin'])); ?></span>
                                </div>
                            </div>
                            <div class="dias-restantes <?php echo $es_urgente ? 'urgent' : 'normal'; ?>">
                                <?php if ($dias_restantes <= 0): ?>
                                    <i class="fas fa-exclamation-circle"></i> Vencida
                                <?php elseif ($dias_restantes <= 7): ?>
                                    <i class="fas fa-clock"></i> Vence en <?php echo $dias_restantes; ?> días
                                <?php else: ?>
                                    <i class="fas fa-calendar-check"></i> Vence en <?php echo $dias_restantes; ?> días
                                <?php endif; ?>
                            </div>
                            <div class="info-item" style="margin-top: 10px; justify-content: center;">
                                <i class="fas fa-dollar-sign" style="color: #28a745;"></i>
                                <span>Pagado: $<?php echo number_format($inscripcion['precio_pagado'], 2); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($todas_inscripciones)): ?>
                        <div class="empty-state">
                            <i class="fas fa-id-card"></i>
                            <p>No hay inscripciones activas</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-close-modal" data-dismiss="modal">
                        <i class="fas fa-times"></i> Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Clases -->
    <div class="modal fade" id="modalClases" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-alt"></i> Clases Disponibles
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="stats-bar">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo count($todas_clases); ?></div>
                        <div class="stat-label">Total Clases</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number">
                            <?php 
                                $cupo_total = 0;
                                $inscritos_total = 0;
                                foreach($todas_clases as $c) {
                                    $cupo_total += $c['cupo_maximo'];
                                    $inscritos_total += $c['cupo_actual'];
                                }
                                $porcentaje_global = $cupo_total > 0 ? round(($inscritos_total / $cupo_total) * 100) : 0;
                                echo $porcentaje_global . '%';
                            ?>
                        </div>
                        <div class="stat-label">Ocupación Global</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number">
                            <?php 
                                $instructores_unicos = [];
                                foreach($todas_clases as $c) if($c['instructor']) $instructores_unicos[$c['instructor']] = true;
                                echo count($instructores_unicos);
                            ?>
                        </div>
                        <div class="stat-label">Instructores</div>
                    </div>
                </div>
                <div class="modal-search">
                    <div class="input-group">
                        <div class="input-group-prepend">
                        <span class="input-group-text">
                            <i class="fas fa-search"></i>
                        </span>
                        </div>
                        <input type="text" id="searchClases" class="form-control" placeholder="Buscar clase, instructor o horario...">
                    </div>
                </div>
                <div class="modal-body">
                    <div class="modal-grid" id="clasesGrid">
                        <?php foreach ($todas_clases as $clase): 
                            $porcentaje = ($clase['cupo_maximo'] > 0) ? ($clase['cupo_actual'] / $clase['cupo_maximo']) * 100 : 0;
                            $cupo_class = $porcentaje >= 90 ? 'danger' : ($porcentaje >= 70 ? 'warning' : 'success');
                            $espacios = $clase['cupo_maximo'] - $clase['cupo_actual'];
                        ?>
                        <div class="clase-card" data-name="<?php echo strtolower($clase['nombre']); ?>" data-instructor="<?php echo strtolower($clase['instructor'] ?? ''); ?>" data-horario="<?php echo strtolower($clase['horario']); ?>">
                            <div class="clase-header">
                                <div class="clase-name"><?php echo htmlspecialchars($clase['nombre']); ?></div>
                                <div class="instructor-name"><i class="fas fa-chalkboard-teacher"></i> <?php echo htmlspecialchars($clase['instructor'] ?? 'Por asignar'); ?></div>
                            </div>
                            <div class="horario">
                                <i class="far fa-clock"></i>
                                <span class="horario-text"><?php echo htmlspecialchars($clase['horario']); ?></span>
                                <i class="fas fa-hourglass-half"></i>
                                <span><?php echo $clase['duracion_minutos']; ?> min</span>
                            </div>
                            <div class="cupo-info">
                                <div class="cupo-numbers">
                                    <span>Cupo: <?php echo $clase['cupo_actual']; ?>/<?php echo $clase['cupo_maximo']; ?></span>
                                    <span><?php echo $espacios > 0 ? $espacios . ' lugares libres' : 'Completo'; ?></span>
                                </div>
                                <div class="cupo-bar">
                                    <div class="cupo-fill <?php echo $cupo_class; ?>" style="width: <?php echo $porcentaje; ?>%"></div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($todas_clases)): ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-alt"></i>
                            <p>No hay clases disponibles</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-close-modal" data-dismiss="modal">
                        <i class="fas fa-times"></i> Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal para cambio de contraseña -->
    <div id="passwordModal" class="modal-overlay" style="display: none;">
        <div class="change-password-modal">
            <h2><i class="fas fa-key"></i> Cambiar Contraseña</h2>
            <p>Por seguridad, debes cambiar tu contraseña de acceso.</p>
            
            <form id="changePasswordForm" method="POST" action="cambiar_password.php">
                <div class="form-group">
                    <label for="new_password">Nueva Contraseña</label>
                    <input type="password" id="new_password" name="new_password" required>
                    <div class="password-requirements">
                        <i class="fas fa-info-circle"></i> Mínimo 6 caracteres
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirmar Contraseña</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                    <div id="passwordError" class="error-message">
                        <i class="fas fa-exclamation-circle"></i> Las contraseñas no coinciden
                    </div>
                </div>
                
                <button type="submit" class="btn-change">
                    <i class="fas fa-save"></i> Cambiar Contraseña
                </button>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>

    <script>

    // Convierte automáticamente las tablas del dashboard en tarjetas legibles en móvil.
    function prepararTablasResponsivas() {
        document.querySelectorAll('.dashboard-table').forEach(function(table) {
            const headers = Array.from(table.querySelectorAll('thead th')).map(function(th) {
                return th.textContent.replace(/\s+/g, ' ').trim();
            });

            table.querySelectorAll('tbody tr').forEach(function(row) {
                Array.from(row.children).forEach(function(cell, index) {
                    if (cell.tagName === 'TD' && !cell.hasAttribute('colspan')) {
                        cell.setAttribute('data-label', headers[index] || '');
                    }
                });
            });
        });
    }


    function inicializarPaginacionTablas() {
        document.querySelectorAll('.dashboard-table').forEach(function(table, tableIndex) {
            if (table.dataset.paginationReady === 'true') {
                return;
            }

            const tbody = table.querySelector('tbody');
            const tableWrap = table.closest('.table-responsive');

            if (!tbody || !tableWrap || !tableWrap.parentNode) {
                return;
            }

            table.dataset.paginationReady = 'true';

            const allRows = Array.from(tbody.children).filter(function(element) {
                return element.tagName === 'TR';
            });

            const dataRows = allRows.filter(function(row) {
                return !row.querySelector('td[colspan]');
            });

            const emptyRows = allRows.filter(function(row) {
                return Boolean(row.querySelector('td[colspan]'));
            });

            let currentPage = 1;
            let pageSize = 5;

            const pagination = document.createElement('div');
            pagination.className = 'dashboard-table-pagination';
            pagination.dataset.tableIndex = String(tableIndex);

            pagination.innerHTML = `
                <div class="dashboard-pagination-left">
                    <label class="dashboard-page-size">
                        <span>Mostrar</span>
                        <select aria-label="Cantidad de registros por página">
                            <option value="5">5</option>
                            <option value="10">10</option>
                            <option value="20">20</option>
                        </select>
                        <span>registros</span>
                    </label>

                    <span class="dashboard-pagination-info" aria-live="polite"></span>
                </div>

                <div class="dashboard-pagination-buttons" aria-label="Paginación de la tabla"></div>
            `;

            tableWrap.insertAdjacentElement('afterend', pagination);

            const sizeSelect = pagination.querySelector('select');
            const info = pagination.querySelector('.dashboard-pagination-info');
            const buttons = pagination.querySelector('.dashboard-pagination-buttons');

            function getTotalPages() {
                return Math.max(1, Math.ceil(dataRows.length / pageSize));
            }

            function createButton(html, targetPage, options) {
                const settings = options || {};
                const button = document.createElement('button');

                button.type = 'button';
                button.className = 'dashboard-page-button';
                button.innerHTML = html;
                button.disabled = Boolean(settings.disabled);

                if (settings.active) {
                    button.classList.add('active');
                    button.setAttribute('aria-current', 'page');
                }

                if (settings.ariaLabel) {
                    button.setAttribute('aria-label', settings.ariaLabel);
                }

                button.addEventListener('click', function() {
                    if (button.disabled) {
                        return;
                    }

                    currentPage = targetPage;
                    render();
                });

                return button;
            }

            function renderButtons() {
                buttons.innerHTML = '';

                const totalPages = getTotalPages();

                buttons.appendChild(
                    createButton(
                        '<i class="fas fa-chevron-left"></i>',
                        Math.max(1, currentPage - 1),
                        {
                            disabled: currentPage <= 1,
                            ariaLabel: 'Página anterior'
                        }
                    )
                );

                let startPage = Math.max(1, currentPage - 2);
                let endPage = Math.min(totalPages, currentPage + 2);

                if ((endPage - startPage) < 4) {
                    startPage = Math.max(1, endPage - 4);
                    endPage = Math.min(totalPages, startPage + 4);
                }

                for (let page = startPage; page <= endPage; page += 1) {
                    buttons.appendChild(
                        createButton(
                            String(page),
                            page,
                            {
                                active: page === currentPage,
                                ariaLabel: 'Página ' + page
                            }
                        )
                    );
                }

                buttons.appendChild(
                    createButton(
                        '<i class="fas fa-chevron-right"></i>',
                        Math.min(totalPages, currentPage + 1),
                        {
                            disabled: currentPage >= totalPages,
                            ariaLabel: 'Página siguiente'
                        }
                    )
                );
            }

            function render() {
                const totalPages = getTotalPages();

                if (currentPage > totalPages) {
                    currentPage = totalPages;
                }

                const startIndex = (currentPage - 1) * pageSize;
                const endIndex = Math.min(startIndex + pageSize, dataRows.length);

                dataRows.forEach(function(row, index) {
                    row.style.display =
                        index >= startIndex && index < endIndex
                            ? ''
                            : 'none';
                });

                emptyRows.forEach(function(row) {
                    row.style.display = dataRows.length === 0 ? '' : 'none';
                });

                if (dataRows.length === 0) {
                    info.innerHTML = '<span>Sin registros</span>';
                } else {
                    info.innerHTML =
                        '<span>Mostrando</span>' +
                        '<strong>' + (startIndex + 1) + '–' + endIndex + '</strong>' +
                        '<span>de</span>' +
                        '<strong>' + dataRows.length + '</strong>';
                }

                renderButtons();
            }

            sizeSelect.addEventListener('change', function() {
                pageSize = Math.max(1, parseInt(sizeSelect.value, 10) || 5);
                currentPage = 1;
                render();
            });

            render();
        });
    }

    let alertaMostrada = false;
    let tiempoRestanteInterval;
    
    // Funciones para abrir modales
    function verTodosClientes() {
        $('#modalClientes').modal('show');
        setTimeout(() => {
            document.getElementById('searchClientes').value = '';
            filtrarClientes();
        }, 100);
    }
    
    function verTodosProductos() {
        $('#modalProductos').modal('show');
        setTimeout(() => {
            document.getElementById('searchProductos').value = '';
            filtrarProductos();
        }, 100);
    }
    
    function verTodasInscripciones() {
        $('#modalInscripciones').modal('show');
        setTimeout(() => {
            document.getElementById('searchInscripciones').value = '';
            filtrarInscripciones();
        }, 100);
    }
    
    function verTodasClases() {
        $('#modalClases').modal('show');
        setTimeout(() => {
            document.getElementById('searchClases').value = '';
            filtrarClases();
        }, 100);
    }
    
    // Filtros de búsqueda
    function filtrarClientes() {
        const searchTerm = document.getElementById('searchClientes').value.toLowerCase();
        const cards = document.querySelectorAll('#clientesGrid .client-card');
        
        cards.forEach(card => {
            const name = card.getAttribute('data-name') || '';
            const phone = card.getAttribute('data-phone') || '';
            const email = card.getAttribute('data-email') || '';
            
            if (name.includes(searchTerm) || phone.includes(searchTerm) || email.includes(searchTerm)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    }
    
    function filtrarProductos() {
        const searchTerm = document.getElementById('searchProductos').value.toLowerCase();
        const cards = document.querySelectorAll('#productosGrid .product-card');
        
        cards.forEach(card => {
            const name = card.getAttribute('data-name') || '';
            const category = card.getAttribute('data-category') || '';
            
            if (name.includes(searchTerm) || category.includes(searchTerm)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    }
    
    function filtrarInscripciones() {
        const searchTerm = document.getElementById('searchInscripciones').value.toLowerCase();
        const cards = document.querySelectorAll('#inscripcionesGrid .inscripcion-card');
        
        cards.forEach(card => {
            const name = card.getAttribute('data-name') || '';
            const plan = card.getAttribute('data-plan') || '';
            
            if (name.includes(searchTerm) || plan.includes(searchTerm)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    }
    
    function filtrarClases() {
        const searchTerm = document.getElementById('searchClases').value.toLowerCase();
        const cards = document.querySelectorAll('#clasesGrid .clase-card');
        
        cards.forEach(card => {
            const name = card.getAttribute('data-name') || '';
            const instructor = card.getAttribute('data-instructor') || '';
            const horario = card.getAttribute('data-horario') || '';
            
            if (name.includes(searchTerm) || instructor.includes(searchTerm) || horario.includes(searchTerm)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    }
    
    // Event listeners para búsqueda
    document.addEventListener('DOMContentLoaded', function() {
        prepararTablasResponsivas();
        inicializarPaginacionTablas();

        const searchClientes = document.getElementById('searchClientes');
        if (searchClientes) searchClientes.addEventListener('keyup', filtrarClientes);
        
        const searchProductos = document.getElementById('searchProductos');
        if (searchProductos) searchProductos.addEventListener('keyup', filtrarProductos);
        
        const searchInscripciones = document.getElementById('searchInscripciones');
        if (searchInscripciones) searchInscripciones.addEventListener('keyup', filtrarInscripciones);
        
        const searchClases = document.getElementById('searchClases');
        if (searchClases) searchClases.addEventListener('keyup', filtrarClases);
    });
    
    // Función para actualizar el temporizador de sesión
    function actualizarTemporizador() {
        const loginTime = <?php echo (int) ($_SESSION['login_time'] ?? time()); ?> * 1000;
        const maxSessionTime = 12 * 3600 * 1000;
        const now = new Date().getTime();
        const elapsed = now - loginTime;
        const remaining = maxSessionTime - elapsed;
        
        if (remaining > 0) {
            const hours = Math.floor(remaining / (3600 * 1000));
            const minutes = Math.floor((remaining % (3600 * 1000)) / (60 * 1000));
            const seconds = Math.floor((remaining % (60 * 1000)) / 1000);
            
            const timerElement = document.getElementById('session-timer');
            if (timerElement) {
                timerElement.textContent = `${hours}h ${minutes}m ${seconds}s`;
                
                if (remaining <= 30 * 60 * 1000 && !alertaMostrada) {
                    alertaMostrada = true;
                    Swal.fire({
                        icon: 'warning',
                        title: 'Sesión próxima a expirar',
                        html: `Tu sesión expirará en <strong>${hours}h ${minutes}m ${seconds}s</strong><br><br>Por seguridad, la sesión se cerrará automáticamente después de 12 horas.`,
                        confirmButtonColor: '#ff6b6b',
                        confirmButtonText: 'Continuar',
                        timer: 10000,
                        timerProgressBar: true
                    });
                }
            }
        } else {
            clearInterval(tiempoRestanteInterval);
            Swal.fire({
                icon: 'info',
                title: 'Sesión expirada',
                text: 'Tu sesión ha expirado después de 12 horas. Serás redirigido al inicio de sesión.',
                confirmButtonColor: '#ff6b6b',
                confirmButtonText: 'Aceptar',
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then(() => {
                window.location.href = 'logout.php';
            });
        }
    }
    
    // Detectar inactividad del usuario
    let inactivityTimer;
    const maxInactivityTime = 12 * 3600 * 1000;
    
    function resetInactivityTimer() {
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(() => {
            Swal.fire({
                icon: 'warning',
                title: 'Sesión expirada por inactividad',
                text: 'Has estado inactivo por 12 horas. Tu sesión se cerrará por seguridad.',
                confirmButtonColor: '#ff6b6b',
                confirmButtonText: 'Aceptar',
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then(() => {
                window.location.href = 'logout.php';
            });
        }, maxInactivityTime);
    }
    
    // Función para mostrar el modal de cambio de contraseña
    function showPasswordModal() {
        const modal = document.getElementById('passwordModal');
        if (modal) {
            modal.style.display = 'flex';
        }
    }
    
    // Gráfico de ingresos mensuales (solo para admin)
    <?php if ($user_rol == 'admin'): ?>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('incomeChart').getContext('2d');
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [{
                    label: 'Ingresos ($)',
                    data: <?php echo json_encode($datos); ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#3b82f6'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return '$' + context.raw.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        
        // Mostrar alerta de bienvenida
        const bienvenidaStorageKey = <?php echo json_encode(
            $dashboard_bienvenida_storage_key,
            JSON_HEX_TAG
            | JSON_HEX_APOS
            | JSON_HEX_AMP
            | JSON_HEX_QUOT
        ); ?>;

        const alertaMostradaStorage =
            sessionStorage.getItem(bienvenidaStorageKey);
        
        if (!alertaMostradaStorage) {
            <?php if ($require_password_change): ?>
            Swal.fire({
                icon: 'info',
                title: '¡Bienvenido al Sistema!',
                html: `
                    <div style="text-align: center; padding: 10px;">
                        <h3 style="color: #003366; margin-bottom: 15px;">¡Hola, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</h3>
                        <p style="font-size: 16px; margin-bottom: 10px;">
                            <i class="fas fa-shield-alt"></i> <strong>Por seguridad, necesitas cambiar tu contraseña</strong>
                        </p>
                        <p style="font-size: 14px; color: #666;">
                            La contraseña actual es temporal y debe ser actualizada.
                        </p>
                    </div>
                `,
                confirmButtonColor: '#003366',
                confirmButtonText: 'Continuar',
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then(() => {
                sessionStorage.setItem(bienvenidaStorageKey, 'true');
                showPasswordModal();
            });
            <?php else: ?>
            Swal.fire({
                icon: 'success',
                title: '¡Bienvenido al Sistema!',
                html: `
                    <div style="text-align: center; padding: 10px;">
                        <h3 style="color: #28a745; margin-bottom: 15px;">¡Hola, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</h3>
                        <p style="font-size: 16px; margin-bottom: 10px;">
                            <i class="fas fa-sign-in-alt"></i> <strong>Accediendo al sistema como</strong><br>
                            <span style="color: #ff6b6b; font-size: 18px;"><?php echo htmlspecialchars($_SESSION['user_rol']); ?></span>
                        </p>
                    </div>
                `,
                showConfirmButton: false,
                timer: 2000,
                timerProgressBar: true,
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then(() => {
                sessionStorage.setItem(bienvenidaStorageKey, 'true');
            });
            <?php endif; ?>
        } else if (<?php echo $require_password_change ? 'true' : 'false'; ?>) {
            showPasswordModal();
        }
        
        // Iniciar temporizadores
        <?php if (!$require_password_change): ?>
        actualizarTemporizador();
        tiempoRestanteInterval = setInterval(actualizarTemporizador, 1000);
        
        const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
        events.forEach(event => {
            document.addEventListener(event, resetInactivityTimer);
        });
        resetInactivityTimer();
        <?php endif; ?>
    });
    <?php else: ?>
    // Para recepcionista y entrenador (sin gráfico)
    document.addEventListener('DOMContentLoaded', function() {
        // Mostrar alerta de bienvenida
        const bienvenidaStorageKey = <?php echo json_encode(
            $dashboard_bienvenida_storage_key,
            JSON_HEX_TAG
            | JSON_HEX_APOS
            | JSON_HEX_AMP
            | JSON_HEX_QUOT
        ); ?>;

        const alertaMostradaStorage =
            sessionStorage.getItem(bienvenidaStorageKey);
        
        if (!alertaMostradaStorage) {
            <?php if ($require_password_change): ?>
            Swal.fire({
                icon: 'info',
                title: '¡Bienvenido al Sistema!',
                html: `
                    <div style="text-align: center; padding: 10px;">
                        <h3 style="color: #003366; margin-bottom: 15px;">¡Hola, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</h3>
                        <p style="font-size: 16px; margin-bottom: 10px;">
                            <i class="fas fa-shield-alt"></i> <strong>Por seguridad, necesitas cambiar tu contraseña</strong>
                        </p>
                        <p style="font-size: 14px; color: #666;">
                            La contraseña actual es temporal y debe ser actualizada.
                        </p>
                    </div>
                `,
                confirmButtonColor: '#003366',
                confirmButtonText: 'Continuar',
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then(() => {
                sessionStorage.setItem(bienvenidaStorageKey, 'true');
                showPasswordModal();
            });
            <?php else: ?>
            Swal.fire({
                icon: 'success',
                title: '¡Bienvenido al Sistema!',
                html: `
                    <div style="text-align: center; padding: 10px;">
                        <h3 style="color: #28a745; margin-bottom: 15px;">¡Hola, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</h3>
                        <p style="font-size: 16px; margin-bottom: 10px;">
                            <i class="fas fa-sign-in-alt"></i> <strong>Accediendo al sistema como</strong><br>
                            <span style="color: #ff6b6b; font-size: 18px;"><?php echo htmlspecialchars($_SESSION['user_rol']); ?></span>
                        </p>
                    </div>
                `,
                showConfirmButton: false,
                timer: 2000,
                timerProgressBar: true,
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then(() => {
                sessionStorage.setItem(bienvenidaStorageKey, 'true');
            });
            <?php endif; ?>
        } else if (<?php echo $require_password_change ? 'true' : 'false'; ?>) {
            showPasswordModal();
        }
        
        // Iniciar temporizadores
        <?php if (!$require_password_change): ?>
        actualizarTemporizador();
        tiempoRestanteInterval = setInterval(actualizarTemporizador, 1000);
        
        const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
        events.forEach(event => {
            document.addEventListener(event, resetInactivityTimer);
        });
        resetInactivityTimer();
        <?php endif; ?>
    });
    <?php endif; ?>
    
    // Validación del formulario de cambio de contraseña
    const changePasswordForm = document.getElementById('changePasswordForm');
    if (changePasswordForm) {
    changePasswordForm.addEventListener('submit', function(e) {
        const newPassword = document.getElementById('new_password').value;
        const confirmPassword = document.getElementById('confirm_password').value;
        const errorDiv = document.getElementById('passwordError');
        
        if (newPassword.length < 6) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Contraseña muy corta',
                text: 'La contraseña debe tener al menos 6 caracteres',
                confirmButtonColor: '#003366'
            });
            return false;
        }
        
        if (newPassword !== confirmPassword) {
            e.preventDefault();
            errorDiv.style.display = 'block';
            return false;
        }
        
        errorDiv.style.display = 'none';
    });
    }
    </script>
</body>
</html>