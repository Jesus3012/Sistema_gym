<?php
// Archivo: dashboard.php
// Dashboard por sucursal con vista global para administradores.

/*
 * El auth_guard valida la sesión, el usuario, el rol efectivo y la
 * sucursal operativa antes de cargar el dashboard.
 */
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/servicio_plataforma_helper.php';

/*
 * Reutiliza la misma integración Point del módulo de Inscripciones.
 * Si la configuración no está disponible, el dashboard conserva efectivo
 * y transferencia, pero no permite seleccionar tarjetas.
 */
$terminal_point_disponible_dashboard = false;
$terminal_point_id_dashboard = '';

try {
    $mpInscripcionesDashboard =
        __DIR__ . '/includes/mercadopago_inscripciones.php';

    if (is_file($mpInscripcionesDashboard)) {
        require_once $mpInscripcionesDashboard;
    }

    $terminal_point_id_dashboard = defined('MP_TERMINAL_ID')
        ? trim((string) MP_TERMINAL_ID)
        : '';

    $terminal_point_disponible_dashboard =
        defined('MP_ACCESS_TOKEN')
        && trim((string) MP_ACCESS_TOKEN) !== ''
        && $terminal_point_id_dashboard !== '';
} catch (Throwable $pointDashboardError) {
    error_log(
        '[Dashboard visita Point] '
        . $pointDashboardError->getMessage()
    );
}

/* Permite limpiar cualquier salida previa antes de generar reportes PDF. */
if (ob_get_level() === 0) {
    ob_start();
}

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

/*
 * Mantiene sincronizado el estado real de las membresías antes de construir
 * las alertas del dashboard. Los planes de un día se conservan en su historial,
 * pero no se incluyen en el seguimiento de renovaciones.
 */
try {
    $db->query(
        "UPDATE inscripciones
         SET estado = 'vencida'
         WHERE estado = 'activa'
           AND fecha_fin IS NOT NULL
           AND fecha_fin < CURDATE()"
    );
} catch (Throwable $estadoInscripcionesError) {
    error_log(
        '[Dashboard actualización de vencimientos] '
        . $estadoInscripcionesError->getMessage()
    );
}

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
$inscripciones_vencidas = 0;
$vencimientos_en_7_dias = 0;
$inscripciones_por_vencer = [];
$todas_clases = [];
$proximas_clases = [];
$alumnos_entrenador = [];
$labels = [];
$datos = [];

$esAdmin = $user_rol === 'admin';
$esRecepcionista = $user_rol === 'recepcionista';
$esEntrenador = $user_rol === 'entrenador';

/*
 * Registro rápido de visitas.
 *
 * Se utilizan únicamente planes activos de un día asignados a la sucursal
 * operativa. El alta rápida conserva las mismas tablas de clientes,
 * inscripciones, pagos e historial utilizadas por el módulo completo.
 */
$puede_registrar_visita_rapida = $esAdmin || $esRecepcionista;
$planes_visita_rapida = [];
$dashboard_visita_csrf = '';

if ($puede_registrar_visita_rapida) {
    if (
        empty($_SESSION['dashboard_visita_csrf'])
        || !is_string($_SESSION['dashboard_visita_csrf'])
    ) {
        $_SESSION['dashboard_visita_csrf'] = bin2hex(random_bytes(32));
    }

    $dashboard_visita_csrf = (string) $_SESSION['dashboard_visita_csrf'];

    try {
        $planes_visita_rapida = dashboardConsultarFilas(
            $db,
            "SELECT
                p.id,
                p.nombre,
                p.descripcion,
                p.duracion_dias,
                ps.precio
             FROM planes p
             INNER JOIN planes_sucursales ps
                ON ps.plan_id = p.id
               AND ps.sucursal_id = ?
             WHERE p.estado = 'activo'
               AND ps.estado = 'activo'
               AND p.duracion_dias = 1
             ORDER BY p.nombre ASC",
            'i',
            [$sucursal_id]
        );
    } catch (Throwable $visitaPlanesError) {
        error_log(
            '[Dashboard planes de visita] '
            . $visitaPlanesError->getMessage()
        );
        $planes_visita_rapida = [];
    }
}

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

$puedeVistaGlobalDashboard = rol_es_administrativo(
    $rolBaseDashboard
);

$servicio_plataforma_dashboard = null;
$mostrar_aviso_servicio_dashboard = false;
$es_super_administrador_dashboard =
    $rolBaseDashboard === 'super_administrador';
$es_administrador_dashboard = in_array(
    $rolBaseDashboard,
    ['super_administrador', 'admin', 'administrador'],
    true
);

if ($es_administrador_dashboard) {
    try {
        $servicio_plataforma_dashboard =
            servicio_plataforma_resumen($db);

        $mostrar_aviso_servicio_dashboard =
            $es_super_administrador_dashboard
            || !empty(
                $servicio_plataforma_dashboard['mostrar_aviso']
            );
    } catch (Throwable $servicioDashboardError) {
        error_log(
            '[Dashboard servicio plataforma] '
            . $servicioDashboardError->getMessage()
        );
    }
}

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
                i.cliente_id,
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
               AND CURDATE() BETWEEN i.fecha_inicio
                   AND i.fecha_fin
             ORDER BY i.fecha_fin ASC"
        );

        $vencimientos_proximos = (int) dashboardConsultarValor(
            $db,
            "SELECT COUNT(*) AS total
             FROM inscripciones i
             INNER JOIN planes p
                ON p.id = i.plan_id
             WHERE i.estado IN ('activa', 'vencida')
               AND p.duracion_dias > 1
               AND i.fecha_fin IS NOT NULL
               AND i.fecha_fin <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)",
            'total'
        );

        $inscripciones_por_vencer = dashboardConsultarFilas(
            $db,
            "SELECT
                i.id,
                i.cliente_id,
                c.nombre AS cliente_nombre,
                c.apellido AS cliente_apellido,
                c.telefono,
                c.email,
                p.nombre AS plan_nombre,
                i.fecha_inicio,
                i.fecha_fin,
                i.precio_pagado,
                DATEDIFF(i.fecha_fin, CURDATE()) AS dias_restantes,
                suc.nombre AS sucursal_nombre
             FROM inscripciones i
             INNER JOIN clientes c
                ON c.id = i.cliente_id
             INNER JOIN planes p
                ON p.id = i.plan_id
             LEFT JOIN sucursales suc
                ON suc.id = i.sucursal_id
             WHERE i.estado IN ('activa', 'vencida')
               AND p.duracion_dias > 1
               AND i.fecha_fin IS NOT NULL
               AND i.fecha_fin <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             ORDER BY
                CASE WHEN i.fecha_fin < CURDATE() THEN 0 ELSE 1 END ASC,
                CASE WHEN i.fecha_fin < CURDATE() THEN i.fecha_fin END DESC,
                i.fecha_fin ASC,
                c.nombre ASC,
                c.apellido ASC"
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
        /*
         * SOCIOS REGISTRADOS EN LA SEDE.
         *
         * El acceso de una membresía continúa siendo multisucursal, pero
         * esta métrica representa únicamente dónde fue dado de alta el socio.
         * Por eso NO se utiliza inscripciones_sucursales en este conteo.
         */
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
               AND c.sucursal_registro_id = ?
             ORDER BY c.fecha_registro DESC",
            'i',
            [$sucursal_id]
        );

        /*
         * INSCRIPCIONES ORIGINADAS EN LA SEDE.
         *
         * inscripciones_sucursales controla dónde puede ingresar el socio;
         * no debe utilizarse para atribuir una venta o inscripción a otra sede.
         */
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
                i.estado
             FROM inscripciones i
             INNER JOIN clientes c
                ON c.id = i.cliente_id
             INNER JOIN planes p
                ON p.id = i.plan_id
             WHERE i.sucursal_id = ?
               AND i.estado = 'activa'
               AND CURDATE() BETWEEN i.fecha_inicio
                   AND i.fecha_fin
             ORDER BY i.fecha_fin ASC",
            'i',
            [$sucursal_id]
        );

        $vencimientos_proximos = (int) dashboardConsultarValor(
            $db,
            "SELECT COUNT(*) AS total
             FROM inscripciones i
             INNER JOIN planes p
                ON p.id = i.plan_id
             WHERE i.sucursal_id = ?
               AND i.estado IN ('activa', 'vencida')
               AND p.duracion_dias > 1
               AND i.fecha_fin IS NOT NULL
               AND i.fecha_fin <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)",
            'total',
            'i',
            [$sucursal_id]
        );

        $inscripciones_por_vencer = dashboardConsultarFilas(
            $db,
            "SELECT
                i.id,
                i.cliente_id,
                c.nombre AS cliente_nombre,
                c.apellido AS cliente_apellido,
                c.telefono,
                c.email,
                p.nombre AS plan_nombre,
                i.fecha_inicio,
                i.fecha_fin,
                i.precio_pagado,
                DATEDIFF(i.fecha_fin, CURDATE()) AS dias_restantes,
                suc.nombre AS sucursal_nombre
             FROM inscripciones i
             INNER JOIN clientes c
                ON c.id = i.cliente_id
             INNER JOIN planes p
                ON p.id = i.plan_id
             LEFT JOIN sucursales suc
                ON suc.id = i.sucursal_id
             WHERE i.sucursal_id = ?
               AND i.estado IN ('activa', 'vencida')
               AND p.duracion_dias > 1
               AND i.fecha_fin IS NOT NULL
               AND i.fecha_fin <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             ORDER BY
                CASE WHEN i.fecha_fin < CURDATE() THEN 0 ELSE 1 END ASC,
                CASE WHEN i.fecha_fin < CURDATE() THEN i.fecha_fin END DESC,
                i.fecha_fin ASC,
                c.nombre ASC,
                c.apellido ASC",
            'i',
            [$sucursal_id]
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

    /*
     * La tarjeta "Productos con stock" no debe contar productos
     * asignados a la sucursal cuando su existencia es cero.
     */
    $total_productos = 0;

    foreach ($todos_productos as $producto) {
        $stockActual = (int) ($producto['stock'] ?? 0);
        $stockMinimo = (int) ($producto['stock_minimo'] ?? 0);

        if ($stockActual > 0) {
            $total_productos++;
        }

        if ($stockActual <= $stockMinimo) {
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

$vencimientos_hoy = 0;
$vencimientos_tres_dias = 0;
$monto_vencimientos = 0.0;

foreach ($inscripciones_por_vencer as $inscripcionVencimiento) {
    $diasVencimiento = (int) (
        $inscripcionVencimiento['dias_restantes'] ?? 0
    );

    if ($diasVencimiento < 0) {
        $inscripciones_vencidas++;
    } elseif ($diasVencimiento === 0) {
        $vencimientos_hoy++;
    } elseif ($diasVencimiento <= 7) {
        $vencimientos_en_7_dias++;
    }

    if ($diasVencimiento >= 0 && $diasVencimiento <= 3) {
        $vencimientos_tres_dias++;
    }

    $monto_vencimientos += (float) (
        $inscripcionVencimiento['precio_pagado'] ?? 0
    );
}

$pdf_vencimientos_url = 'dashboard.php?accion=pdf_vencimientos&vista=' . (
    $vista_global_dashboard ? 'global' : 'sucursal'
);

/*
 * El reporte se genera desde el mismo dashboard. Así hereda exactamente
 * la sesión, el permiso y la sucursal activa sin agregar endpoints sueltos.
 */
if (
    isset($_GET['accion'])
    && (string) $_GET['accion'] === 'pdf_vencimientos'
) {
    if (!in_array($user_rol, ['admin', 'recepcionista'], true)) {
        http_response_code(403);
        exit('No tienes permiso para generar este reporte.');
    }

    require_once __DIR__ . '/includes/correo_inscripciones.php';

    if (
        !function_exists('cargarFpdfInscripciones')
        || !cargarFpdfInscripciones()
        || !class_exists('FPDF')
    ) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(500);
        exit('No se encontró la librería FPDF utilizada por el sistema.');
    }

    $configuracionGimnasio = dashboardConsultarFilas(
        $db,
        "SELECT nombre, logo
         FROM configuracion_gimnasio
         WHERE id = 1
         LIMIT 1"
    );

    $nombreGimnasioPdf = trim((string) (
        $configuracionGimnasio[0]['nombre']
        ?? 'EGO'
    ));

    $logoGimnasioPdf = trim((string) (
        $configuracionGimnasio[0]['logo']
        ?? ''
    ));

    $rutaLogoGimnasioPdf = null;

    if ($logoGimnasioPdf !== '') {
        $logoNormalizado = str_replace('\\', '/', $logoGimnasioPdf);
        $logoNormalizado = ltrim($logoNormalizado, '/');

        $candidatosLogo = [
            __DIR__ . '/' . $logoNormalizado,
            __DIR__ . '/uploads/' . basename($logoNormalizado),
            __DIR__ . '/img/' . basename($logoNormalizado),
        ];

        foreach ($candidatosLogo as $candidatoLogo) {
            $rutaRealLogo = realpath($candidatoLogo);

            if (
                $rutaRealLogo !== false
                && is_file($rutaRealLogo)
                && is_readable($rutaRealLogo)
            ) {
                $extensionLogo = strtolower(
                    (string) pathinfo($rutaRealLogo, PATHINFO_EXTENSION)
                );

                if (in_array($extensionLogo, ['jpg', 'jpeg', 'png'], true)) {
                    $rutaLogoGimnasioPdf = $rutaRealLogo;
                    break;
                }
            }
        }
    }

    if (!class_exists('DashboardVencimientosPDF')) {
        class DashboardVencimientosPDF extends FPDF
        {
            public function Footer()
            {
                $this->SetY(-12);
                $this->SetFont('Arial', '', 7.5);
                $this->SetTextColor(100, 116, 139);
                $this->Cell(
                    0,
                    5,
                    textoFpdfInscripciones(
                        'Página ' . $this->PageNo()
                    ),
                    0,
                    0,
                    'C'
                );
            }
        }
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $pdf = new DashboardVencimientosPDF('L', 'mm', 'A4');
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 17);
    $pdf->AddPage();

    $dibujarEncabezadoReporte = static function (
        DashboardVencimientosPDF $documento,
        string $gimnasio,
        string $contexto,
        int $total,
        ?string $rutaLogo
    ): void {
        $documento->SetFillColor(30, 58, 138);
        $documento->Rect(0, 0, 297, 31, 'F');

        $tituloX = 12;
        $tituloAncho = 190;

        if ($rutaLogo !== null) {
            try {
                $documento->Image($rutaLogo, 12, 5.5, 19, 19);
                $tituloX = 36;
                $tituloAncho = 166;
            } catch (Throwable $logoError) {
                error_log(
                    '[Dashboard PDF vencimientos logo] '
                    . $logoError->getMessage()
                );
            }
        }

        $documento->SetXY($tituloX, 8);
        $documento->SetTextColor(255, 255, 255);
        $documento->SetFont('Arial', 'B', 16);
        $documento->Cell(
            $tituloAncho,
            7,
            textoFpdfInscripciones('Membresías vencidas y por vencer'),
            0,
            0,
            'L'
        );

        $documento->SetFont('Arial', 'B', 10);
        $documento->Cell(
            83,
            7,
            textoFpdfInscripciones($gimnasio),
            0,
            1,
            'R'
        );

        $documento->SetX($tituloX);
        $documento->SetFont('Arial', '', 8.5);
        $documento->Cell(
            $tituloAncho,
            6,
            textoFpdfInscripciones(
                'Vencidas y próximos 7 días · ' . $contexto
            ),
            0,
            0,
            'L'
        );

        $documento->Cell(
            83,
            6,
            textoFpdfInscripciones(
                $total . ($total === 1 ? ' inscripción' : ' inscripciones')
            ),
            0,
            1,
            'R'
        );

        $documento->SetY(37);
        $documento->SetTextColor(71, 85, 105);
        $documento->SetFont('Arial', '', 8);
        $documento->Cell(
            0,
            5,
            textoFpdfInscripciones(
                'Generado el ' . date('d/m/Y H:i')
            ),
            0,
            1,
            'L'
        );
        $documento->Ln(2);
    };

    $dibujarCabeceraTabla = static function (
        DashboardVencimientosPDF $documento
    ): void {
        $anchos = [9, 47, 33, 29, 26, 24, 46, 52];
        $titulos = [
            '#',
            'Socio',
            'Plan',
            'Teléfono',
            'Vence',
            'Estado',
            'Sucursal',
            'Correo',
        ];

        $documento->SetFillColor(226, 232, 240);
        $documento->SetTextColor(30, 41, 59);
        $documento->SetFont('Arial', 'B', 7.5);

        foreach ($titulos as $indice => $titulo) {
            $documento->Cell(
                $anchos[$indice],
                8,
                textoFpdfInscripciones($titulo),
                0,
                0,
                $indice === 0 || $indice === 5 ? 'C' : 'L',
                true
            );
        }

        $documento->Ln();
    };

    $dibujarEncabezadoReporte(
        $pdf,
        $nombreGimnasioPdf,
        $dashboard_contexto_nombre,
        count($inscripciones_por_vencer),
        $rutaLogoGimnasioPdf
    );
    $dibujarCabeceraTabla($pdf);

    if ($inscripciones_por_vencer === []) {
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(
            266,
            18,
            textoFpdfInscripciones(
                'No hay membresías vencidas ni próximas a vencer.'
            ),
            1,
            1,
            'C'
        );
    } else {
        $anchos = [9, 47, 33, 29, 26, 24, 46, 52];

        foreach ($inscripciones_por_vencer as $indice => $inscripcionPdf) {
            if ($pdf->GetY() > 184) {
                $pdf->AddPage();
                $dibujarEncabezadoReporte(
                    $pdf,
                    $nombreGimnasioPdf,
                    $dashboard_contexto_nombre,
                    count($inscripciones_por_vencer),
                    $rutaLogoGimnasioPdf
                );
                $dibujarCabeceraTabla($pdf);
            }

            $diasPdf = (int) ($inscripcionPdf['dias_restantes'] ?? 0);
            $nombrePdf = trim(
                (string) ($inscripcionPdf['cliente_nombre'] ?? '')
                . ' '
                . (string) ($inscripcionPdf['cliente_apellido'] ?? '')
            );
            $estadoDiasPdf = $diasPdf < 0
                ? 'Vencida ' . abs($diasPdf) . 'd'
                : ($diasPdf === 0 ? 'Vence hoy' : $diasPdf . ' días');

            $valores = [
                (string) ($indice + 1),
                recortarTextoFpdfInscripciones($nombrePdf, 31),
                recortarTextoFpdfInscripciones(
                    (string) ($inscripcionPdf['plan_nombre'] ?? ''),
                    22
                ),
                recortarTextoFpdfInscripciones(
                    (string) ($inscripcionPdf['telefono'] ?? 'No registrado'),
                    18
                ),
                date(
                    'd/m/Y',
                    strtotime((string) $inscripcionPdf['fecha_fin'])
                ),
                $estadoDiasPdf,
                recortarTextoFpdfInscripciones(
                    (string) ($inscripcionPdf['sucursal_nombre'] ?? $dashboard_contexto_nombre),
                    29
                ),
                recortarTextoFpdfInscripciones(
                    (string) ($inscripcionPdf['email'] ?? 'No registrado'),
                    41
                ),
            ];

            $pdf->SetFillColor(
                $indice % 2 === 0 ? 248 : 255,
                $indice % 2 === 0 ? 250 : 255,
                $indice % 2 === 0 ? 252 : 255
            );
            $pdf->SetTextColor(31, 41, 55);
            $pdf->SetFont('Arial', '', 7.3);

            foreach ($valores as $columna => $valor) {
                if ($columna === 5 && $diasPdf <= 1) {
                    $pdf->SetTextColor(185, 28, 28);
                    $pdf->SetFont('Arial', 'B', 7.3);
                } else {
                    $pdf->SetTextColor(31, 41, 55);
                    $pdf->SetFont('Arial', '', 7.3);
                }

                $pdf->Cell(
                    $anchos[$columna],
                    8,
                    textoFpdfInscripciones($valor),
                    0,
                    0,
                    $columna === 0 || $columna === 5 ? 'C' : 'L',
                    true
                );
            }

            $pdf->Ln();
        }
    }

    $nombreArchivoPdf = 'membresias_pendientes_renovacion_'
        . date('Ymd_His')
        . '.pdf';

    $pdf->Output('I', $nombreArchivoPdf);
    exit;
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
    <?php $modalVisitaFixCss = __DIR__ . '/css/dashboard_visita_modal_fix.css'; ?>
    <link
        rel="stylesheet"
        href="css/dashboard_visita_modal_fix.css?v=<?php echo is_file($modalVisitaFixCss) ? (int) filemtime($modalVisitaFixCss) : time(); ?>"
    >
    <link rel="stylesheet" href="css/servicio_plataforma_alerta.css?v=1">

    <style>
        /*
         * Los estados vacíos viven dentro de una cuadrícula. Sin ocupar
         * todas las columnas quedan pegados al lado izquierdo.
         */
        .modal-grid > .empty-state {
            grid-column: 1 / -1 !important;
            width: 100%;
            min-height: 280px;
            margin: 0 !important;
            padding: 40px 20px;
            display: flex !important;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #94a3b8;
        }

        .modal-grid > .empty-state i {
            margin: 0 0 16px;
            font-size: 4rem;
            line-height: 1;
            color: #aab3bd;
        }

        .modal-grid > .empty-state p {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            color: #64748b;
        }

        /* Mensajes vacíos de las tablas principales. */
        .dashboard-table td.text-center[colspan] {
            height: 110px;
            vertical-align: middle;
            color: #64748b;
            font-weight: 600;
        }

        @media (max-width: 767.98px) {
            .modal-grid > .empty-state {
                min-height: 210px;
                padding: 30px 16px;
            }

            .modal-grid > .empty-state i {
                font-size: 3rem;
            }
        }
    </style>
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
                    <i class="fas fa-chart-line welcome-banner-hero-icon"></i>
                    <br>
                    <span class="badge badge-light mt-2">
                        <i class="fas fa-fingerprint"></i> <?php echo $asistencias_hoy; ?> asistencias hoy
                    </span>
                </div>
            </div>
        </div>

        <section class="dashboard-priority-actions" aria-label="Acciones rápidas">
            <header class="dashboard-priority-actions__heading">
                <div>
                    <span>Acciones rápidas</span>
                </div>
            </header>
        <?php if ($puede_registrar_visita_rapida): ?>
        <section class="quick-visit-row" aria-label="Registro rápido de visitas">
            <div class="quick-visit-card">
                <span class="quick-visit-card__icon" aria-hidden="true">
                    <i class="fas fa-person-walking-arrow-right"></i>
                </span>

                <div class="quick-visit-card__copy">
                    <span class="quick-visit-card__kicker">Acceso de un día</span>
                    <h2>Registrar visita rápidamente</h2>
                    <p>
                        Busca una persona registrada o captura solamente nombre y apellidos.
                        El sistema reutiliza su información, genera el QR y registra el pago.
                    </p>

                    <div class="quick-visit-card__meta">
                        <span>
                            <i class="fas fa-building"></i>
                            <?php echo htmlspecialchars(
                                $sucursal_nombre,
                                ENT_QUOTES,
                                'UTF-8'
                            ); ?>
                        </span>
                        <span>
                            <i class="fas fa-layer-group"></i>
                            <?php echo count($planes_visita_rapida); ?>
                            <?php echo count($planes_visita_rapida) === 1
                                ? 'plan de un día'
                                : 'planes de un día'; ?>
                        </span>
                    </div>
                </div>

                <button
                    type="button"
                    class="quick-visit-card__action"
                    onclick="abrirRegistroVisitaRapida()"
                    <?php echo $planes_visita_rapida === [] ? 'disabled' : ''; ?>
                    title="<?php echo $planes_visita_rapida === []
                        ? 'Primero activa un plan con duración de un día en esta sucursal.'
                        : 'Registrar una visita'; ?>"
                >
                    <i class="fas fa-bolt"></i>
                    <?php echo $planes_visita_rapida === []
                        ? 'Sin planes disponibles'
                        : 'Registrar visita'; ?>
                </button>
            </div>
        </section>
        <?php endif; ?>

        <!-- Alertas de Vencimientos (solo admin y recepcionista) -->
        <?php if (($user_rol == 'admin' || $user_rol == 'recepcionista') && $vencimientos_proximos > 0): ?>
        <div class="row expiry-alert-row">
            <div class="col-12">
                <div
                    class="expiry-alert-card"
                    role="button"
                    tabindex="0"
                    onclick="verInscripcionesPorVencer()"
                    onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); verInscripcionesPorVencer(); }"
                    aria-label="Ver membresías vencidas y próximas a vencer"
                >
                    <span class="expiry-alert-icon" aria-hidden="true">
                        <i class="fas fa-calendar-exclamation"></i>
                    </span>

                    <div class="expiry-alert-copy">
                        <span class="expiry-alert-kicker">Vencidas y próximos 7 días</span>
                        <h3>Membresías por renovar</h3>
                        <p>
                            <strong>
                                <?php echo number_format($vencimientos_proximos); ?>
                                <?php echo $vencimientos_proximos === 1 ? 'membresía' : 'membresías'; ?>
                            </strong>
                            requieren seguimiento:
                            <?php echo number_format($inscripciones_vencidas); ?> vencida(s) y
                            <?php echo number_format($vencimientos_hoy + $vencimientos_en_7_dias); ?> próxima(s) a vencer.
                        </p>
                    </div>

                    <div class="expiry-alert-meta">
                        <span class="expiry-alert-count">
                            <strong><?php echo number_format($vencimientos_proximos); ?></strong>
                            <small>pendientes</small>
                        </span>

                        <span class="expiry-alert-action">
                            Revisar y renovar
                            <i class="fas fa-arrow-right"></i>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        </section>

        <?php if (
            $mostrar_aviso_servicio_dashboard
            && is_array($servicio_plataforma_dashboard)
        ): ?>
            <?php
            $servicioDashboardNivel = (string) (
                $servicio_plataforma_dashboard['nivel']
                ?? 'neutral'
            );
            $servicioDashboardConfig = (array) (
                $servicio_plataforma_dashboard['configuracion']
                ?? []
            );
            $servicioDashboardDias =
                $servicio_plataforma_dashboard['dias_restantes']
                ?? null;
            ?>
            <section
                class="platform-service-alert platform-service-alert--<?php echo htmlspecialchars($servicioDashboardNivel, ENT_QUOTES, 'UTF-8'); ?>"
                aria-label="Estado del servicio de la plataforma"
            >
                <span class="platform-service-alert__icon">
                    <i class="fas <?php echo in_array(
                        (string) ($servicio_plataforma_dashboard['estado'] ?? ''),
                        ['vencido', 'suspendido', 'configuracion_invalida'],
                        true
                    ) ? 'fa-triangle-exclamation' : 'fa-clock'; ?>"></i>
                </span>

                <div class="platform-service-alert__copy">
                    <span class="platform-service-alert__eyebrow">
                        Servicio de plataforma
                    </span>
                    <h2>
                        <?php echo htmlspecialchars(
                            (string) (
                                $servicio_plataforma_dashboard['titulo']
                                ?? 'Estado del servicio'
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>
                    </h2>
                    <p>
                        <?php echo htmlspecialchars(
                            (string) (
                                $servicio_plataforma_dashboard['mensaje']
                                ?? ''
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>
                    </p>

                    <div class="platform-service-alert__meta">
                        <span>
                            <i class="fas fa-calendar-day"></i>
                            Vence:
                            <strong>
                                <?php echo htmlspecialchars(
                                    (string) (
                                        $servicio_plataforma_dashboard['fecha_vencimiento_formateada']
                                        ?? 'Sin definir'
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </strong>
                        </span>

                        <?php if ($servicioDashboardDias !== null): ?>
                            <span>
                                <i class="fas fa-hourglass-half"></i>
                                <strong><?php echo (int) $servicioDashboardDias; ?></strong>
                                <?php echo (int) $servicioDashboardDias === 1
                                    ? 'día restante'
                                    : 'días restantes'; ?>
                            </span>
                        <?php endif; ?>

                        <?php if (!empty($servicioDashboardConfig['proveedor_nombre'])): ?>
                            <span>
                                <i class="fas fa-headset"></i>
                                <?php echo htmlspecialchars(
                                    (string) $servicioDashboardConfig['proveedor_nombre'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($es_super_administrador_dashboard): ?>
                    <a
                        href="servicio_plataforma.php"
                        class="platform-service-alert__action"
                    >
                        <i class="fas fa-pen-to-square"></i>
                        Administrar servicio
                    </a>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <!-- Small boxes (Stat box) - VISTA ADMIN -->
        <?php if ($user_rol == 'admin'): ?>
        <div class="row">
            <div class="col-lg-3 col-6">
                <div class="small-box bg-info">
                    <div class="inner">
                        <h3><?php echo $total_clientes; ?></h3>
                        <p>Socios Registrados</p>
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
                        <p>Productos con Stock</p>
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
                        <p>Socios Registrados</p>
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
                        <p>Productos con Stock</p>
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
                            Últimos Socios Registrados
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
                                        <td colspan="3" class="text-center">No hay socios registrados</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer text-center">
                        <a href="javascript:void(0)" onclick="verTodosClientes()" class="btn btn-sm btn-primary">Ver todos los socios</a>
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
                            Últimos Socios Registrados
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
                                        <td colspan="3" class="text-center">No hay socios registrados</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer text-center">
                        <a href="javascript:void(0)" onclick="verTodosClientes()" class="btn btn-sm btn-primary">Ver todos los socios</a>
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
                                    <i class="fas fa-user-plus"></i> Nuevo Socio
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
                                    <i class="fas fa-user-plus"></i> Nuevo Socio
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

    <!-- MODALES (Socios, Productos, Inscripciones, Clases) -->
    <?php if ($puede_registrar_visita_rapida): ?>
    <div
        class="modal fade"
        id="modalVisitaRapida"
        tabindex="-1"
        role="dialog"
        aria-labelledby="modalVisitaRapidaTitulo"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-lg quick-visit-modal-dialog" role="document">
            <div class="modal-content quick-visit-modal-content">
                <div class="modal-header quick-visit-modal-header">
                    <div>
                        <span class="quick-visit-modal-kicker">Acceso de un día</span>
                        <h5 class="modal-title" id="modalVisitaRapidaTitulo">
                            <i class="fas fa-person-walking-arrow-right"></i>
                            Registro rápido de visita
                        </h5>
                        <p>
                            Sucursal:
                            <strong><?php echo htmlspecialchars(
                                $sucursal_nombre,
                                ENT_QUOTES,
                                'UTF-8'
                            ); ?></strong>
                        </p>
                    </div>

                    <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <form id="formVisitaRapida" autocomplete="off">
                    <input
                        type="hidden"
                        name="csrf"
                        value="<?php echo htmlspecialchars(
                            $dashboard_visita_csrf,
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>"
                    >
                    <input type="hidden" name="cliente_id" id="visitaClienteId" value="">
                    <input type="hidden" name="request_id" id="visitaRequestId" value="">
                    <input type="hidden" name="mp_order_id" id="visitaMpOrderId" value="">
                    <input type="hidden" name="mp_payment_id" id="visitaMpPaymentId" value="">
                    <input type="hidden" name="mp_external_reference" id="visitaMpExternalReference" value="">
                    <input type="hidden" name="mp_payment_reference_id" id="visitaMpPaymentReferenceId" value="">
                    <input type="hidden" name="mp_installments" id="visitaMpInstallments" value="1">

                    <div class="modal-body quick-visit-modal-body">
                        <section class="quick-visit-step">
                            <div class="quick-visit-step__heading">
                                <span>1</span>
                                <div>
                                    <h3>Buscar o registrar persona</h3>
                                    <p>Escribe nombre, teléfono, correo o código QR.</p>
                                </div>
                            </div>

                            <label class="quick-visit-search" for="visitaBusqueda">
                                <i class="fas fa-magnifying-glass"></i>
                                <input
                                    type="search"
                                    id="visitaBusqueda"
                                    placeholder="Ej. Juan Pérez, 222..., correo o QR"
                                    autocomplete="off"
                                >
                                <span id="visitaBusquedaEstado">Escribe al menos 2 caracteres</span>
                            </label>

                            <div
                                class="quick-visit-search-results"
                                id="visitaResultados"
                                aria-live="polite"
                            ></div>

                            <div
                                class="quick-visit-selected"
                                id="visitaSeleccionado"
                                hidden
                            >
                                <span class="quick-visit-selected__avatar" id="visitaSeleccionadoAvatar">VP</span>
                                <div>
                                    <strong id="visitaSeleccionadoNombre">Persona seleccionada</strong>
                                    <small id="visitaSeleccionadoDetalle">Datos registrados</small>
                                </div>
                                <button type="button" id="visitaLimpiarSeleccion">
                                    Cambiar
                                </button>
                            </div>

                            <div class="quick-visit-name-grid">
                                <label>
                                    <span>Nombre <strong>*</strong></span>
                                    <input
                                        type="text"
                                        name="nombre"
                                        id="visitaNombre"
                                        maxlength="100"
                                        required
                                    >
                                </label>
                                <label>
                                    <span>Apellidos <strong>*</strong></span>
                                    <input
                                        type="text"
                                        name="apellido"
                                        id="visitaApellido"
                                        maxlength="100"
                                        required
                                    >
                                </label>
                            </div>

                            <label class="quick-visit-extra-toggle">
                                <input type="checkbox" id="visitaMostrarDatos">
                                <span>
                                    <strong>Capturar más datos</strong>
                                    <small>Teléfono, correo y contacto de emergencia.</small>
                                </span>
                                <i class="fas fa-chevron-down"></i>
                            </label>

                            <div class="quick-visit-extra-fields" id="visitaDatosAdicionales" hidden>
                                <label>
                                    <span>Teléfono</span>
                                    <input type="tel" name="telefono" id="visitaTelefono" maxlength="20">
                                </label>
                                <label>
                                    <span>Correo electrónico</span>
                                    <input type="email" name="email" id="visitaEmail" maxlength="100">
                                </label>
                                <label>
                                    <span>Contacto de emergencia</span>
                                    <input
                                        type="text"
                                        name="contacto_emergencia_nombre"
                                        id="visitaEmergenciaNombre"
                                        maxlength="150"
                                    >
                                </label>
                                <label>
                                    <span>Teléfono de emergencia</span>
                                    <input
                                        type="tel"
                                        name="contacto_emergencia_telefono"
                                        id="visitaEmergenciaTelefono"
                                        maxlength="25"
                                    >
                                </label>
                            </div>
                        </section>

                        <section class="quick-visit-step">
                            <div class="quick-visit-step__heading">
                                <span>2</span>
                                <div>
                                    <h3>Plan y pago</h3>
                                    <p>Solo se muestran planes activos con duración de un día.</p>
                                </div>
                            </div>

                            <div class="quick-visit-payment-grid">
                                <label>
                                    <span>Plan de visita <strong>*</strong></span>
                                    <select name="plan_id" id="visitaPlanId" required>
                                        <option value="" disabled>Selecciona un plan</option>
                                        <?php foreach ($planes_visita_rapida as $indicePlanVisita => $planVisita): ?>
                                            <option
                                                value="<?php echo (int) $planVisita['id']; ?>"
                                                <?php echo (int) $indicePlanVisita === 0 ? 'selected' : ''; ?>
                                                data-precio="<?php echo htmlspecialchars(
                                                    number_format(
                                                        (float) $planVisita['precio'],
                                                        2,
                                                        '.',
                                                        ''
                                                    ),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>"
                                                data-nombre="<?php echo htmlspecialchars(
                                                    (string) $planVisita['nombre'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>"
                                            >
                                                <?php echo htmlspecialchars(
                                                    (string) $planVisita['nombre'],
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>
                                                · $<?php echo number_format(
                                                    (float) $planVisita['precio'],
                                                    2
                                                ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>

                                <label>
                                    <span>Método de pago <strong>*</strong></span>
                                    <select name="metodo_pago" id="visitaMetodoPago" required>
                                        <option value="efectivo">Efectivo</option>
                                        <?php if ($terminal_point_disponible_dashboard): ?>
                                            <option value="tarjeta_debito">Tarjeta de débito · Point</option>
                                            <option value="tarjeta_credito">Tarjeta de crédito · Point</option>
                                        <?php else: ?>
                                            <option value="" disabled>Point no configurada</option>
                                        <?php endif; ?>
                                        <option value="transferencia">Transferencia</option>
                                    </select>
                                </label>
                            </div>

                            <div
                                class="quick-visit-point-help"
                                id="visitaPointHelp"
                                hidden
                            >
                                <i class="fas fa-credit-card"></i>
                                <span id="visitaPointHelpText">
                                    El cobro se enviará a la terminal Mercado Pago Point.
                                </span>
                            </div>

                            <label
                                class="quick-visit-reference"
                                id="visitaReferenciaWrap"
                                hidden
                            >
                                <span>Referencia de transferencia</span>
                                <input
                                    type="text"
                                    name="referencia"
                                    id="visitaReferencia"
                                    maxlength="100"
                                    placeholder="Opcional"
                                >
                            </label>

                            <div class="quick-visit-summary">
                                <div>
                                    <small>Vigencia</small>
                                    <strong>Hoy · acceso por un día</strong>
                                </div>
                                <div>
                                    <small>Total</small>
                                    <strong id="visitaPrecioResumen">$0.00</strong>
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="modal-footer quick-visit-modal-footer">
                        <p>
                            <i class="fas fa-qrcode"></i>
                            El QR se crea o recupera automáticamente y permanece asociado a la persona.
                        </p>
                        <div>
                            <button type="button" class="btn-close-modal" data-dismiss="modal">
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                class="quick-visit-submit"
                                id="visitaGuardar"
                            >
                                <i class="fas fa-bolt"></i>
                                Registrar visita
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal de inscripciones por vencer -->
    <?php if ($user_rol == 'admin' || $user_rol == 'recepcionista'): ?>
    <div class="modal fade" id="modalVencimientos" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content expiry-modal-content">
                <div class="modal-header expiry-modal-header">
                    <div>
                        <span class="expiry-modal-kicker">Vencidas y próximos 7 días</span>
                        <h5 class="modal-title">
                            <i class="fas fa-calendar-days"></i>
                            Membresías vencidas y por vencer
                        </h5>
                        <p><?php echo htmlspecialchars($dashboard_contexto_nombre, ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>

                    <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                        <span>&times;</span>
                    </button>
                </div>

                <div class="stats-bar expiry-stats-bar">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo number_format($vencimientos_proximos); ?></div>
                        <div class="stat-label">Total pendientes</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number"><?php echo number_format($inscripciones_vencidas); ?></div>
                        <div class="stat-label">Ya vencidas</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number"><?php echo number_format($vencimientos_hoy); ?></div>
                        <div class="stat-label">Vencen hoy</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number"><?php echo number_format($vencimientos_en_7_dias); ?></div>
                        <div class="stat-label">Próximos 7 días</div>
                    </div>
                </div>

                <div class="expiry-modal-toolbar">
                    <label class="expiry-search-wrap" for="searchVencimientos">
                        <i class="fas fa-magnifying-glass"></i>
                        <input
                            type="search"
                            id="searchVencimientos"
                            class="form-control"
                            placeholder="Buscar por socio, plan, teléfono o sucursal"
                            autocomplete="off"
                        >
                    </label>

                    <label class="expiry-sort-wrap" for="ordenVencimientos">
                        <i class="fas fa-arrow-down-wide-short"></i>
                        <span>Ordenar</span>
                        <select id="ordenVencimientos" aria-label="Ordenar membresías por fecha de vencimiento">
                            <option value="recent" selected>Más recientes</option>
                            <option value="oldest">Más antiguas</option>
                        </select>
                    </label>

                    <a
                        href="<?php echo htmlspecialchars($pdf_vencimientos_url, ENT_QUOTES, 'UTF-8'); ?>"
                        target="_blank"
                        rel="noopener"
                        class="expiry-pdf-button"
                    >
                        <i class="fas fa-file-pdf"></i>
                        Generar PDF
                    </a>
                </div>

                <div class="modal-body expiry-modal-body">
                    <div class="expiry-grid" id="vencimientosGrid">
                        <?php foreach ($inscripciones_por_vencer as $indiceVencer => $inscripcionVencer): ?>
                            <?php
                            $diasRestantesVencer = (int) ($inscripcionVencer['dias_restantes'] ?? 0);
                            $nombreCompletoVencer = trim(
                                (string) ($inscripcionVencer['cliente_nombre'] ?? '')
                                . ' '
                                . (string) ($inscripcionVencer['cliente_apellido'] ?? '')
                            );
                            $claseUrgenciaVencer = $diasRestantesVencer < 0
                                ? 'is-expired'
                                : ($diasRestantesVencer === 0
                                    ? 'expires-today'
                                    : ($diasRestantesVencer <= 3 ? 'expires-soon' : 'expires-week'));
                            ?>
                            <article
                                class="expiry-member-card <?php echo $claseUrgenciaVencer; ?>"
                                data-expiry-card
                                data-expiry-timestamp="<?php echo (int) (strtotime((string) ($inscripcionVencer['fecha_fin'] ?? '')) ?: 0); ?>"
                                data-expiry-days="<?php echo (int) $diasRestantesVencer; ?>"
                                data-expiry-index="<?php echo (int) $indiceVencer; ?>"
                                data-search="<?php echo htmlspecialchars(strtolower(
                                    $nombreCompletoVencer . ' '
                                    . (string) ($inscripcionVencer['plan_nombre'] ?? '') . ' '
                                    . (string) ($inscripcionVencer['telefono'] ?? '') . ' '
                                    . (string) ($inscripcionVencer['email'] ?? '') . ' '
                                    . (string) ($inscripcionVencer['sucursal_nombre'] ?? '')
                                ), ENT_QUOTES, 'UTF-8'); ?>"
                            >
                                <header class="expiry-member-header">
                                    <div class="expiry-member-avatar">
                                        <?php echo htmlspecialchars(strtoupper(
                                            substr((string) ($inscripcionVencer['cliente_nombre'] ?? 'S'), 0, 1)
                                            . substr((string) ($inscripcionVencer['cliente_apellido'] ?? 'O'), 0, 1)
                                        ), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>

                                    <div class="expiry-member-identity">
                                        <h3><?php echo htmlspecialchars($nombreCompletoVencer, ENT_QUOTES, 'UTF-8'); ?></h3>
                                        <span>
                                            <i class="fas fa-id-card"></i>
                                            <?php echo htmlspecialchars((string) ($inscripcionVencer['plan_nombre'] ?? 'Sin plan'), ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </div>

                                    <span class="expiry-days-badge">
                                        <?php if ($diasRestantesVencer < 0): ?>
                                            Vencida hace <?php echo abs($diasRestantesVencer); ?>
                                            <?php echo abs($diasRestantesVencer) === 1 ? 'día' : 'días'; ?>
                                        <?php elseif ($diasRestantesVencer === 0): ?>
                                            Vence hoy
                                        <?php elseif ($diasRestantesVencer === 1): ?>
                                            1 día
                                        <?php else: ?>
                                            <?php echo $diasRestantesVencer; ?> días
                                        <?php endif; ?>
                                    </span>
                                </header>

                                <div class="expiry-member-period">
                                    <div>
                                        <small>Inicio</small>
                                        <strong><?php echo date('d/m/Y', strtotime((string) $inscripcionVencer['fecha_inicio'])); ?></strong>
                                    </div>
                                    <i class="fas fa-arrow-right"></i>
                                    <div>
                                        <small>Vencimiento</small>
                                        <strong><?php echo date('d/m/Y', strtotime((string) $inscripcionVencer['fecha_fin'])); ?></strong>
                                    </div>
                                </div>

                                <div class="expiry-member-contact">
                                    <span>
                                        <i class="fas fa-phone"></i>
                                        <?php echo htmlspecialchars(
                                            trim((string) ($inscripcionVencer['telefono'] ?? '')) !== ''
                                                ? (string) $inscripcionVencer['telefono']
                                                : 'No registrado',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ); ?>
                                    </span>
                                    <span>
                                        <i class="fas fa-envelope"></i>
                                        <?php echo htmlspecialchars(
                                            trim((string) ($inscripcionVencer['email'] ?? '')) !== ''
                                                ? (string) $inscripcionVencer['email']
                                                : 'No registrado',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ); ?>
                                    </span>
                                    <span>
                                        <i class="fas fa-building"></i>
                                        <?php echo htmlspecialchars(
                                            (string) ($inscripcionVencer['sucursal_nombre'] ?? $dashboard_contexto_nombre),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ); ?>
                                    </span>
                                </div>

                                <?php
                                $fechaInicioSugerida = date(
                                    'Y-m-d',
                                    strtotime(
                                        (string) $inscripcionVencer['fecha_fin']
                                        . ' +1 day'
                                    )
                                );
                                $fechaInicioRenovacion = $fechaInicioSugerida < date('Y-m-d')
                                    ? date('Y-m-d')
                                    : $fechaInicioSugerida;
                                $urlRenovacionDashboard =
                                    'inscripciones.php?'
                                    . http_build_query([
                                        'action' => 'renovar',
                                        'inscripcion_id' => (int) $inscripcionVencer['id'],
                                        'cliente_id' => (int) $inscripcionVencer['cliente_id'],
                                        'fecha_inicio' => $fechaInicioRenovacion,
                                        'origen' => 'dashboard',
                                    ]);
                                ?>
                                <footer class="expiry-member-actions">
                                    <a
                                        href="<?php echo htmlspecialchars(
                                            $urlRenovacionDashboard,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ); ?>"
                                        class="expiry-renew-button"
                                        onclick="event.stopPropagation();"
                                    >
                                        <i class="fas fa-arrows-rotate"></i>
                                        Renovar inscripción
                                    </a>
                                </footer>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <div class="expiry-filter-empty" id="vencimientosFilterEmpty" hidden>
                        <i class="fas fa-magnifying-glass"></i>
                        <h3>No encontramos inscripciones</h3>
                        <p>Prueba con otro nombre, plan, teléfono o sucursal.</p>
                    </div>

                    <?php if ($inscripciones_por_vencer === []): ?>
                        <div class="expiry-filter-empty is-initial-empty">
                            <i class="fas fa-circle-check"></i>
                            <h3>No hay membresías pendientes de renovación</h3>
                            <p>No existen membresías vencidas ni membresías que venzan durante los próximos 7 días.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="modal-footer expiry-modal-footer">
                    <span>
                        El reporte incluye membresías vencidas y las que vencen durante los próximos 7 días.
                    </span>

                    <div>
                        <button type="button" class="btn-close-modal" data-dismiss="modal">
                            <i class="fas fa-times"></i>
                            Cerrar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal de Socios -->
    <div class="modal fade" id="modalClientes" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-users"></i> Socios Registrados
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="stats-bar">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo count($todos_clientes); ?></div>
                        <div class="stat-label">Total Socios</div>
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
                        <input type="text" id="searchClientes" class="form-control" placeholder="Buscar socio por nombre, teléfono o email...">
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
                            <p>No hay socios registrados</p>
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


    const visitaRapidaState = {
        searchTimer: null,
        searchController: null,
        selected: null,
        submitting: false,
        autoFilledQuery: '',
        pointOrderId: ''
    };

    function crearRequestIdVisita() {
        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            const bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            return Array.from(bytes).map(function(byte) {
                return byte.toString(16).padStart(2, '0');
            }).join('');
        }

        return String(Date.now()) + Math.random().toString(16).slice(2);
    }

    function visitaEscaparHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value == null ? '' : value);
        return div.innerHTML;
    }

    function visitaIniciales(nombre, apellido) {
        const primera = String(nombre || '').trim().charAt(0);
        const segunda = String(apellido || '').trim().charAt(0);
        return (primera + segunda || 'VP').toUpperCase();
    }

    function visitaFormatearFecha(fecha) {
        const valor = String(fecha || '').trim();
        const partes = valor.split('-');

        if (partes.length !== 3) return valor;
        return partes[2] + '/' + partes[1] + '/' + partes[0];
    }

    function visitaAutocompletarIdentidadDesdeBusqueda(busqueda) {
        if (visitaRapidaState.selected) return false;

        const original = String(busqueda || '').trim();
        if (original === '' || /[@0-9]/.test(original)) return false;

        const limpio = original
            .replace(/[^A-Za-zÁÉÍÓÚÜÑáéíóúüñ'\- ,]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();

        if (limpio.length < 2) return false;

        let nombre = '';
        let apellido = '';

        if (limpio.includes(',')) {
            const partesComa = limpio.split(',');
            apellido = String(partesComa.shift() || '').trim();
            nombre = partesComa.join(' ').trim();
        } else {
            const palabras = limpio.split(' ').filter(Boolean);
            nombre = palabras.shift() || '';
            apellido = palabras.join(' ');
        }

        const inputNombre = document.getElementById('visitaNombre');
        const inputApellido = document.getElementById('visitaApellido');

        if (!inputNombre || !inputApellido || nombre === '') return false;

        inputNombre.value = nombre;
        inputApellido.value = apellido;
        visitaRapidaState.autoFilledQuery = original;

        if (apellido === '') {
            inputApellido.focus();
        }

        return true;
    }

    function visitaActualizarPrecio() {
        const select = document.getElementById('visitaPlanId');
        const resumen = document.getElementById('visitaPrecioResumen');

        if (!select || !resumen) return;

        const option = select.options[select.selectedIndex];
        const precio = Number(option ? option.dataset.precio || 0 : 0);
        resumen.textContent = precio.toLocaleString('es-MX', {
            style: 'currency',
            currency: 'MXN'
        });
    }

    function visitaEsMetodoPoint(metodo) {
        return metodo === 'tarjeta_debito'
            || metodo === 'tarjeta_credito';
    }

    function visitaActualizarMetodoPago() {
        const metodo = document.getElementById('visitaMetodoPago');
        const wrap = document.getElementById('visitaReferenciaWrap');
        const pointHelp = document.getElementById('visitaPointHelp');
        const pointHelpText = document.getElementById('visitaPointHelpText');

        if (!metodo || !wrap) return;

        const esTransferencia = metodo.value === 'transferencia';
        const esPoint = visitaEsMetodoPoint(metodo.value);

        wrap.hidden = !esTransferencia;

        if (pointHelp) {
            pointHelp.hidden = !esPoint;
        }

        if (pointHelpText && esPoint) {
            pointHelpText.textContent = metodo.value === 'tarjeta_credito'
                ? 'El cobro se enviará a la Point. Las mensualidades disponibles se eligen directamente en la terminal.'
                : 'El cobro se enviará como tarjeta de débito a la terminal Mercado Pago Point.';
        }

        if (!esPoint) {
            visitaLimpiarDatosPoint();
        }
    }

    function visitaLimpiarDatosPoint() {
        visitaRapidaState.pointOrderId = '';

        [
            'visitaMpOrderId',
            'visitaMpPaymentId',
            'visitaMpExternalReference',
            'visitaMpPaymentReferenceId'
        ].forEach(function(id) {
            const input = document.getElementById(id);
            if (input) input.value = '';
        });

        const installments = document.getElementById('visitaMpInstallments');
        if (installments) installments.value = '1';
    }

    function visitaAsignarDatosPoint(data) {
        const valores = {
            visitaMpOrderId: data.order_id || '',
            visitaMpPaymentId: data.payment_id || '',
            visitaMpExternalReference: data.external_reference || '',
            visitaMpPaymentReferenceId: data.payment_reference_id || '',
            visitaMpInstallments: String(data.installments || 1)
        };

        Object.keys(valores).forEach(function(id) {
            const input = document.getElementById(id);
            if (input) input.value = valores[id];
        });

        visitaRapidaState.pointOrderId = valores.visitaMpOrderId;
    }

    function visitaDormir(ms) {
        return new Promise(function(resolve) {
            window.setTimeout(resolve, ms);
        });
    }

    async function visitaFetchJsonPoint(url, options) {
        const response = await fetch(url, options || {});
        const text = await response.text();
        let data = null;

        try {
            data = JSON.parse(text);
        } catch (error) {
            console.error('Respuesta Point no JSON:', {
                url: url,
                status: response.status,
                response: text
            });
            throw new Error(
                'La integración Point devolvió una respuesta inválida. Revisa el log de PHP.'
            );
        }

        if (!response.ok || !data.success) {
            const pointError = new Error(
                data.message || 'No fue posible comunicarse con Mercado Pago.'
            );
            pointError.code = data.code || '';
            pointError.orderId = data.order_id || '';
            pointError.requiresTerminal = Boolean(data.requires_terminal);
            throw pointError;
        }

        return data;
    }

    async function visitaCrearOrdenPoint(form) {
        const formData = new FormData(form);
        const metodo = String(formData.get('metodo_pago') || '');
        const plan = document.getElementById('visitaPlanId');
        const option = plan ? plan.options[plan.selectedIndex] : null;
        const total = Number(option ? option.dataset.precio || 0 : 0);
        const paymentType = metodo === 'tarjeta_credito'
            ? 'credit_card'
            : 'debit_card';

        return visitaFetchJsonPoint(
            'api/mercadopago/crear_orden_visita.php',
            {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    csrf: String(formData.get('csrf') || ''),
                    plan_id: Number(formData.get('plan_id') || 0),
                    total: total,
                    payment_type: paymentType,
                    cliente_id: Number(formData.get('cliente_id') || 0),
                    nombre: String(formData.get('nombre') || ''),
                    apellido: String(formData.get('apellido') || ''),
                    telefono: String(formData.get('telefono') || ''),
                    email: String(formData.get('email') || '')
                })
            }
        );
    }

    function visitaConsultarOrdenPoint(orderId) {
        return visitaFetchJsonPoint(
            'api/mercadopago/consultar_orden_inscripcion.php',
            {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({order_id: orderId})
            }
        );
    }

    function visitaCancelarOrdenPoint(orderId) {
        return visitaFetchJsonPoint(
            'api/mercadopago/cancelar_orden_inscripcion.php',
            {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({order_id: orderId})
            }
        );
    }

    async function visitaEsperarPagoPoint(order) {
        let terminado = false;
        let consultando = true;
        const inicio = Date.now();
        const maximoEspera = 190000;

        return new Promise(function(resolve, reject) {
            function completar(data) {
                if (terminado) return;
                terminado = true;
                consultando = false;
                Swal.close();
                resolve(data);
            }

            function fallar(error) {
                if (terminado) return;
                terminado = true;
                consultando = false;
                Swal.close();
                reject(error);
            }

            Swal.fire({
                title: 'Esperando pago en terminal',
                html:
                    '<p>Completa el cobro en la terminal Point. No cierres esta ventana.</p>'
                    + '<div class="point-order-card">Orden: '
                    + visitaEscaparHtml(order.order_id || '')
                    + '</div>'
                    + '<div id="visita-point-status" class="point-status-live">Estado: creada</div>',
                showConfirmButton: false,
                showCancelButton: true,
                cancelButtonText: 'Cancelar cobro',
                cancelButtonColor: '#dc2626',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: async function() {
                    while (consultando && !terminado) {
                        try {
                            const latest = await visitaConsultarOrdenPoint(
                                order.order_id
                            );
                            const status = document.getElementById(
                                'visita-point-status'
                            );

                            if (status) {
                                status.textContent =
                                    'Orden: ' + (latest.order_status || '-')
                                    + ' · Pago: '
                                    + (latest.payment_status || '-');
                            }

                            if (latest.paid) {
                                completar(latest);
                                return;
                            }

                            if (latest.final_failure) {
                                fallar(new Error(
                                    'El pago terminó en estado '
                                    + (latest.payment_status
                                        || latest.order_status
                                        || 'desconocido')
                                    + '.'
                                ));
                                return;
                            }
                        } catch (error) {
                            console.error('Consulta de Point:', error);
                        }

                        if (Date.now() - inicio >= maximoEspera) {
                            fallar(new Error(
                                'Terminó el tiempo de espera para completar el pago.'
                            ));
                            return;
                        }

                        await visitaDormir(2200);
                    }
                }
            }).then(async function(result) {
                if (terminado || result.isConfirmed) return;

                consultando = false;

                try {
                    const cancelada = await visitaCancelarOrdenPoint(
                        order.order_id
                    );

                    if (cancelada.requires_terminal) {
                        await Swal.fire({
                            icon: 'warning',
                            title: 'Cancela en la terminal',
                            text: cancelada.message
                                || 'La orden debe cancelarse desde la Point.',
                            confirmButtonColor: '#1e3a8a'
                        });
                    }

                    fallar(new Error('El cobro fue cancelado.'));
                } catch (error) {
                    fallar(error);
                }
            });
        });
    }

    function visitaMostrarDatosAdicionales(mostrar) {
        const checkbox = document.getElementById('visitaMostrarDatos');
        const fields = document.getElementById('visitaDatosAdicionales');

        if (checkbox) checkbox.checked = Boolean(mostrar);
        if (fields) fields.hidden = !mostrar;
    }

    function visitaBloquearIdentidad(bloquear) {
        ['visitaNombre', 'visitaApellido'].forEach(function(id) {
            const input = document.getElementById(id);
            if (input) input.readOnly = Boolean(bloquear);
        });
    }

    function visitaLimpiarResultados() {
        const resultados = document.getElementById('visitaResultados');
        if (resultados) resultados.innerHTML = '';
    }

    function visitaSeleccionarPersona(persona) {
        if (persona && persona.tiene_membresia_activa) {
            const plan = persona.membresia_activa_plan || 'membresía activa';
            const vence = visitaFormatearFecha(
                persona.membresia_activa_fecha_fin
            );

            Swal.fire({
                icon: 'info',
                title: 'Ya tiene acceso vigente',
                html: '<p>Esta persona tiene el plan <strong>'
                    + visitaEscaparHtml(plan)
                    + '</strong> vigente hasta <strong>'
                    + visitaEscaparHtml(vence)
                    + '</strong>.</p><p>La visita de un día solamente puede registrarse cuando esa membresía haya vencido.</p>',
                confirmButtonText: 'Entendido',
                confirmButtonColor: '#1e3a8a'
            });
            return;
        }

        visitaRapidaState.selected = persona;
        visitaRapidaState.autoFilledQuery = '';

        document.getElementById('visitaClienteId').value = persona.id || '';
        document.getElementById('visitaNombre').value = persona.nombre || '';
        document.getElementById('visitaApellido').value = persona.apellido || '';
        document.getElementById('visitaTelefono').value = persona.telefono || '';
        document.getElementById('visitaEmail').value = persona.email || '';
        document.getElementById('visitaEmergenciaNombre').value =
            persona.contacto_emergencia_nombre || '';
        document.getElementById('visitaEmergenciaTelefono').value =
            persona.contacto_emergencia_telefono || '';

        const selected = document.getElementById('visitaSeleccionado');
        selected.hidden = false;
        document.getElementById('visitaSeleccionadoAvatar').textContent =
            visitaIniciales(persona.nombre, persona.apellido);
        document.getElementById('visitaSeleccionadoNombre').textContent =
            (persona.nombre + ' ' + persona.apellido).trim();

        const estadoAcceso = persona.ultima_membresia_vencida
            ? 'Membresía vencida el '
                + visitaFormatearFecha(persona.ultima_membresia_vencida)
            : 'Sin membresía vigente';
        const detalles = [
            persona.telefono || 'Sin teléfono',
            estadoAcceso,
            persona.codigo_qr ? 'QR ' + persona.codigo_qr : 'QR por generar'
        ];
        document.getElementById('visitaSeleccionadoDetalle').textContent =
            detalles.join(' · ');

        visitaBloquearIdentidad(true);
        visitaLimpiarResultados();
        document.getElementById('visitaBusqueda').value = '';
        document.getElementById('visitaBusquedaEstado').textContent =
            'Persona registrada seleccionada y disponible para visita';
    }

    function visitaQuitarSeleccion() {
        visitaRapidaState.selected = null;
        document.getElementById('visitaClienteId').value = '';
        document.getElementById('visitaSeleccionado').hidden = true;
        visitaBloquearIdentidad(false);

        [
            'visitaNombre',
            'visitaApellido',
            'visitaTelefono',
            'visitaEmail',
            'visitaEmergenciaNombre',
            'visitaEmergenciaTelefono'
        ].forEach(function(id) {
            const input = document.getElementById(id);
            if (input) input.value = '';
        });

        visitaMostrarDatosAdicionales(false);
        const search = document.getElementById('visitaBusqueda');
        if (search) search.focus();
    }

    function visitaRenderizarResultados(personas, busqueda) {
        const resultados = document.getElementById('visitaResultados');
        const estado = document.getElementById('visitaBusquedaEstado');

        if (!resultados || !estado) return;

        if (!Array.isArray(personas) || personas.length === 0) {
            const autocompletado =
                visitaAutocompletarIdentidadDesdeBusqueda(busqueda);

            resultados.innerHTML = `
                <div class="quick-visit-search-empty">
                    <i class="fas fa-user-plus"></i>
                    <span>${autocompletado
                        ? 'No encontramos coincidencias. Pasamos la búsqueda a Nombre y Apellidos; revisa los datos y continúa.'
                        : 'No encontramos coincidencias. Captura nombre y apellidos para registrarlo.'}</span>
                </div>
            `;
            estado.textContent = autocompletado
                ? 'Nueva persona preparada'
                : 'Sin coincidencias';
            return;
        }

        let bloqueadas = 0;

        resultados.innerHTML = personas.map(function(persona, index) {
            const fullName = (persona.nombre + ' ' + persona.apellido).trim();
            const accesoActivo = Boolean(persona.tiene_membresia_activa);
            if (accesoActivo) bloqueadas++;

            let estadoMembresia = 'Sin membresía vigente';
            if (accesoActivo) {
                estadoMembresia = (persona.membresia_activa_plan || 'Plan activo')
                    + ' hasta '
                    + visitaFormatearFecha(persona.membresia_activa_fecha_fin);
            } else if (persona.ultima_membresia_vencida) {
                estadoMembresia = 'Venció el '
                    + visitaFormatearFecha(persona.ultima_membresia_vencida);
            }

            const detail = [
                persona.telefono || 'Sin teléfono',
                persona.sucursal || 'Sin sucursal',
                estadoMembresia
            ].join(' · ');

            return `
                <button
                    type="button"
                    class="quick-visit-search-result${accesoActivo ? ' is-blocked' : ''}"
                    data-visita-index="${index}"
                    ${accesoActivo ? 'disabled aria-disabled="true"' : ''}
                >
                    <span>${visitaEscaparHtml(
                        visitaIniciales(persona.nombre, persona.apellido)
                    )}</span>
                    <div>
                        <strong>${visitaEscaparHtml(fullName)}</strong>
                        <small>${visitaEscaparHtml(detail)}</small>
                    </div>
                    ${accesoActivo
                        ? '<em class="quick-visit-result-status is-active"><i class="fas fa-lock"></i> Acceso vigente</em>'
                        : '<i class="fas fa-chevron-right"></i>'}
                </button>
            `;
        }).join('');

        Array.from(
            resultados.querySelectorAll('[data-visita-index]:not(:disabled)')
        ).forEach(function(button) {
            button.addEventListener('click', function() {
                const index = Number(button.dataset.visitaIndex || -1);
                if (personas[index]) visitaSeleccionarPersona(personas[index]);
            });
        });

        const disponibles = personas.length - bloqueadas;
        estado.textContent = personas.length + ' coincidencia(s) · '
            + disponibles + ' disponible(s) para visita';
    }

    async function visitaBuscarPersonas() {
        const input = document.getElementById('visitaBusqueda');
        const estado = document.getElementById('visitaBusquedaEstado');
        const q = String(input ? input.value : '').trim();

        if (!estado) return;

        if (q.length < 2) {
            visitaLimpiarResultados();
            estado.textContent = 'Escribe al menos 2 caracteres';
            return;
        }

        if (visitaRapidaState.searchController) {
            visitaRapidaState.searchController.abort();
        }

        visitaRapidaState.searchController = new AbortController();
        estado.textContent = 'Buscando...';

        try {
            const response = await fetch(
                'api/dashboard/buscar_visitantes.php?q='
                + encodeURIComponent(q),
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: visitaRapidaState.searchController.signal
                }
            );

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'No fue posible buscar personas.');
            }

            visitaRenderizarResultados(data.personas || [], q);
        } catch (error) {
            if (error && error.name === 'AbortError') return;
            estado.textContent = 'No fue posible buscar';
            console.error('Búsqueda de visitas:', error);
        }
    }

    function visitaResetearFormulario() {
        const form = document.getElementById('formVisitaRapida');
        if (!form) return;

        form.reset();

        /*
         * El primer plan de un día disponible queda seleccionado de forma
         * predeterminada cada vez que se abre o reinicia el registro rápido.
         */
        const planPredeterminado = document.getElementById('visitaPlanId');
        if (
            planPredeterminado
            && !planPredeterminado.value
            && planPredeterminado.options.length > 1
        ) {
            planPredeterminado.selectedIndex = 1;
        }

        visitaRapidaState.selected = null;
        visitaRapidaState.submitting = false;
        visitaRapidaState.autoFilledQuery = '';
        document.getElementById('visitaClienteId').value = '';
        document.getElementById('visitaRequestId').value =
            crearRequestIdVisita();
        document.getElementById('visitaSeleccionado').hidden = true;
        document.getElementById('visitaBusquedaEstado').textContent =
            'Escribe al menos 2 caracteres';
        visitaBloquearIdentidad(false);
        visitaMostrarDatosAdicionales(false);
        visitaLimpiarResultados();
        visitaActualizarPrecio();
        visitaActualizarMetodoPago();

        const button = document.getElementById('visitaGuardar');
        if (button) {
            button.disabled = false;
            button.innerHTML =
                '<i class="fas fa-bolt"></i> Registrar visita';
        }
    }

    function abrirRegistroVisitaRapida() {
        const modal = document.getElementById('modalVisitaRapida');
        if (!modal) {
            Swal.fire({
                icon: 'info',
                title: 'Registro no disponible',
                text: 'No hay planes de un día disponibles en esta sucursal.',
                confirmButtonColor: '#1e3a8a'
            });
            return;
        }

        visitaResetearFormulario();
        $('#modalVisitaRapida').modal('show');

        window.setTimeout(function() {
            const search = document.getElementById('visitaBusqueda');
            if (search) search.focus();
        }, 180);
    }

    async function visitaEnviarFormulario(event) {
        event.preventDefault();

        if (visitaRapidaState.submitting) return false;

        const form = event.currentTarget;
        const button = document.getElementById('visitaGuardar');

        if (!form.reportValidity()) return false;

        visitaRapidaState.submitting = true;
        button.disabled = true;
        button.innerHTML =
            '<i class="fas fa-spinner fa-spin"></i> Registrando...';

        try {
            const metodo = String(
                new FormData(form).get('metodo_pago') || ''
            );

            if (visitaEsMetodoPoint(metodo)) {
                visitaLimpiarDatosPoint();
                button.innerHTML =
                    '<i class="fas fa-spinner fa-spin"></i> Enviando a Point...';

                const order = await visitaCrearOrdenPoint(form);
                visitaRapidaState.pointOrderId = order.order_id || '';

                const paid = await visitaEsperarPagoPoint(order);

                if (!paid || !paid.paid) {
                    throw new Error(
                        'Mercado Pago no confirmó el cobro.'
                    );
                }

                visitaAsignarDatosPoint(
                    Object.assign({}, order, paid)
                );
            }

            button.innerHTML =
                '<i class="fas fa-spinner fa-spin"></i> Guardando visita...';

            const response = await fetch(
                'api/dashboard/registrar_visita.php',
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new FormData(form)
                }
            );

            const responseText = await response.text();
            let data = null;

            try {
                data = JSON.parse(responseText);
            } catch (parseError) {
                console.error('Registro de visita no JSON:', {
                    status: response.status,
                    response: responseText
                });
                throw new Error(
                    'El servidor devolvió una respuesta inválida al guardar la visita.'
                );
            }

            if (!response.ok || !data.success) {
                const registroError = new Error(
                    data.message || 'No fue posible registrar la visita.'
                );
                registroError.orderId = data.order_id
                    || visitaRapidaState.pointOrderId
                    || '';
                throw registroError;
            }

            $('#modalVisitaRapida').modal('hide');

            const reusedText = data.cliente_reutilizado
                ? 'Se reutilizó la información registrada de la persona.'
                : 'Se creó el registro de la persona.';

            const result = await Swal.fire({
                icon: 'success',
                title: 'Visita registrada',
                html:
                    '<p class="quick-visit-success-name">'
                    + visitaEscaparHtml(data.nombre || '')
                    + '</p>'
                    + '<p>'
                    + visitaEscaparHtml(data.plan || '')
                    + ' · '
                    + visitaEscaparHtml(data.total_formateado || '')
                    + '</p>'
                    + '<p class="quick-visit-success-note">'
                    + visitaEscaparHtml(reusedText)
                    + '</p>'
                    + (
                        data.qr_url
                            ? '<img class="quick-visit-success-qr" src="'
                                + visitaEscaparHtml(data.qr_url)
                                + '" alt="Código QR de la persona">'
                            : ''
                    )
                    + '<p><strong>QR:</strong> '
                    + visitaEscaparHtml(data.codigo_qr || '')
                    + '</p>'
                    + (
                        data.point_order_id
                            ? '<p class="quick-visit-success-note"><strong>Point:</strong> '
                                + visitaEscaparHtml(data.point_order_id)
                                + '</p>'
                            : ''
                    ),
                showCancelButton: true,
                confirmButtonText: 'Registrar otra visita',
                cancelButtonText: 'Cerrar',
                confirmButtonColor: '#1e3a8a',
                cancelButtonColor: '#64748b'
            });

            if (result.isConfirmed) {
                abrirRegistroVisitaRapida();
            }
        } catch (error) {
            await Swal.fire({
                icon: 'error',
                title: 'No se registró la visita',
                text: error.message || 'Ocurrió un error inesperado.',
                confirmButtonColor: '#1e3a8a'
            });
        } finally {
            visitaRapidaState.submitting = false;
            button.disabled = false;
            button.innerHTML =
                '<i class="fas fa-bolt"></i> Registrar visita';
        }

        return false;
    }

    document.addEventListener('DOMContentLoaded', function() {
        const search = document.getElementById('visitaBusqueda');
        const extras = document.getElementById('visitaMostrarDatos');
        const clear = document.getElementById('visitaLimpiarSeleccion');
        const plan = document.getElementById('visitaPlanId');
        const method = document.getElementById('visitaMetodoPago');
        const form = document.getElementById('formVisitaRapida');

        if (search) {
            search.addEventListener('input', function() {
                window.clearTimeout(visitaRapidaState.searchTimer);
                visitaRapidaState.searchTimer =
                    window.setTimeout(visitaBuscarPersonas, 280);
            });
        }

        if (extras) {
            extras.addEventListener('change', function() {
                visitaMostrarDatosAdicionales(extras.checked);
            });
        }

        if (clear) clear.addEventListener('click', visitaQuitarSeleccion);
        if (plan) plan.addEventListener('change', visitaActualizarPrecio);
        if (method) method.addEventListener('change', visitaActualizarMetodoPago);
        if (form) form.addEventListener('submit', visitaEnviarFormulario);

        $('#modalVisitaRapida')
            .on('show.bs.modal', function() {
                document.documentElement.classList.add(
                    'quick-visit-modal-open'
                );
                document.body.classList.add(
                    'quick-visit-modal-open'
                );
            })
            .on('hidden.bs.modal', function() {
                document.documentElement.classList.remove(
                    'quick-visit-modal-open'
                );
                document.body.classList.remove(
                    'quick-visit-modal-open'
                );
                visitaResetearFormulario();
            });
    });

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

    function verInscripcionesPorVencer() {
        $('#modalVencimientos').modal('show');

        window.setTimeout(function() {
            const searchVencimientos = document.getElementById('searchVencimientos');

            if (searchVencimientos) {
                searchVencimientos.value = '';
            }

            const ordenVencimientos = document.getElementById('ordenVencimientos');
            if (ordenVencimientos) {
                ordenVencimientos.value = 'recent';
            }

            ordenarVencimientos();

            if (searchVencimientos) {
                searchVencimientos.focus();
            }
        }, 120);
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
    
    function ordenarVencimientos() {
        const grid = document.getElementById('vencimientosGrid');
        const selector = document.getElementById('ordenVencimientos');

        if (!grid) {
            return;
        }

        const modo = selector ? selector.value : 'recent';
        const cards = Array.from(
            grid.querySelectorAll('[data-expiry-card]')
        );

        cards.sort(function(cardA, cardB) {
            const diasA = Number(cardA.dataset.expiryDays || 0);
            const diasB = Number(cardB.dataset.expiryDays || 0);
            const fechaA = Number(cardA.dataset.expiryTimestamp || 0);
            const fechaB = Number(cardB.dataset.expiryTimestamp || 0);
            const indiceA = Number(cardA.dataset.expiryIndex || 0);
            const indiceB = Number(cardB.dataset.expiryIndex || 0);
            const vencidaA = diasA < 0;
            const vencidaB = diasB < 0;

            /*
             * Las membresías ya vencidas permanecen arriba. El selector
             * cambia el orden entre ellas; las próximas conservan primero
             * la fecha más urgente para no perder renovaciones cercanas.
             */
            if (vencidaA !== vencidaB) {
                return vencidaA ? -1 : 1;
            }

            if (vencidaA && vencidaB) {
                if (fechaA !== fechaB) {
                    return modo === 'oldest'
                        ? fechaA - fechaB
                        : fechaB - fechaA;
                }
            } else if (fechaA !== fechaB) {
                return fechaA - fechaB;
            }

            return indiceA - indiceB;
        });

        cards.forEach(function(card) {
            grid.appendChild(card);
        });

        filtrarVencimientos();
    }

    function filtrarVencimientos() {
        const input = document.getElementById('searchVencimientos');
        const empty = document.getElementById('vencimientosFilterEmpty');
        const cards = Array.from(
            document.querySelectorAll('#vencimientosGrid [data-expiry-card]')
        );
        const term = (input ? input.value : '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
        let visible = 0;

        cards.forEach(function(card) {
            const hayCoincidencia = term === ''
                || String(card.dataset.search || '')
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .includes(term);

            card.hidden = !hayCoincidencia;

            if (hayCoincidencia) {
                visible++;
            }
        });

        if (empty) {
            empty.hidden = visible !== 0 || cards.length === 0;
        }
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

        const searchVencimientos = document.getElementById('searchVencimientos');
        let vencimientosSearchTimer = null;

        if (searchVencimientos) {
            searchVencimientos.addEventListener('input', function() {
                window.clearTimeout(vencimientosSearchTimer);
                vencimientosSearchTimer = window.setTimeout(
                    filtrarVencimientos,
                    450
                );
            });
        }

        const ordenVencimientos = document.getElementById('ordenVencimientos');
        if (ordenVencimientos) {
            ordenVencimientos.addEventListener('change', ordenarVencimientos);
        }
        
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