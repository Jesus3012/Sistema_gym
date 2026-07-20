<?php
// Archivo: includes/sidebar.php
// Componente visual del sidebar. La protección de rutas se realiza en auth_guard.php.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// auth_guard.php debe ejecutarse antes. Este retorno evita imprimir el sidebar
// si por error se incluye sin una sesión válida.
if (empty($_SESSION['user_id'])) {
    return;
}

$sidebar_project_root = dirname(__DIR__);

if (!function_exists('sidebarArchivoExiste')) {
    function sidebarArchivoExiste(string $ruta, string $projectRoot): bool
    {
        $ruta = trim($ruta);

        if ($ruta === '' || preg_match('#^https?://#i', $ruta)) {
            return false;
        }

        return is_file(
            rtrim($projectRoot, '/\\')
            . DIRECTORY_SEPARATOR
            . ltrim($ruta, '/\\')
        );
    }
}

$user_rol = strtolower(trim((string) ($_SESSION['user_rol'] ?? 'recepcionista')));
$current_page = basename((string) ($_SERVER['PHP_SELF'] ?? ''));

$sidebar_legal_pendiente = false;
$sidebar_legal_error = '';
$sidebar_legal_documentos = [];
$sidebar_legal_configuracion = [];
$sidebar_legal_return = '';

require_once __DIR__ . '/legal_guard.php';

if ($current_page !== 'legal.php') {
    try {
        $sidebar_legal_db = legal_get_database();

        legal_ensure_table($sidebar_legal_db);

        $sidebar_legal_configuracion =
            legal_get_gym_config($sidebar_legal_db);

        $sidebar_legal_documentos =
            legal_get_documents(
                $sidebar_legal_configuracion
            );

        $sidebar_legal_aceptacion =
            legal_get_acceptance(
                $sidebar_legal_db,
                (int) $_SESSION['user_id']
            );

        $sidebar_legal_pendiente =
            !legal_acceptance_is_current(
                $sidebar_legal_aceptacion,
                $sidebar_legal_documentos
            );

        if ($sidebar_legal_pendiente) {
            if (empty($_SESSION['legal_csrf'])) {
                $_SESSION['legal_csrf'] =
                    bin2hex(random_bytes(32));
            }

            $sidebar_legal_return =
                legal_safe_return_url(
                    (string) (
                        $_SESSION['legal_return_after_accept']
                        ?? legal_current_local_url()
                    )
                );
        }
    } catch (Throwable $sidebarLegalException) {
        $sidebar_legal_pendiente = true;
        $sidebar_legal_error =
            $sidebarLegalException->getMessage();

        if (empty($_SESSION['legal_csrf'])) {
            $_SESSION['legal_csrf'] =
                bin2hex(random_bytes(32));
        }

        $sidebar_legal_return =
            legal_base_url() . '/dashboard.php';

        error_log(
            '[Sidebar legal] '
            . $sidebarLegalException->getMessage()
        );
    }
}

$sidebar_legal_activo =
    $current_page === 'legal.php'
    || $sidebar_legal_pendiente;

// Recuperar una alerta de acceso denegado únicamente en el dashboard.
$alerta_acceso_denegado = null;
if ($current_page === 'dashboard.php' && isset($_SESSION['alerta_acceso_denegado'])) {
    $alerta_acceso_denegado = $_SESSION['alerta_acceso_denegado'];
    unset($_SESSION['alerta_acceso_denegado']);
}

// Conectar a la base de datos para obtener configuración del gimnasio.
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$conn = $database->getConnection();

require_once __DIR__ . '/sucursal_context.php';

$sidebar_sucursales = [];
$sidebar_sucursal_actual = (int) (
    $_SESSION['sucursal_id'] ?? 0
);
$sidebar_sucursal_nombre = trim((string) (
    $_SESSION['sucursal_nombre'] ?? 'Sucursal'
));
$sidebar_sucursal_clave = trim((string) (
    $_SESSION['sucursal_clave'] ?? ''
));
$sidebar_sucursal_csrf = '';


$sidebar_user_rol_base = strtolower(trim((string) (
    $_SESSION['user_rol_base'] ?? $user_rol
)));

$sidebar_puede_vista_global = in_array(
    $sidebar_user_rol_base,
    ['admin', 'administrador'],
    true
);

/*
 * Dashboard e Inscripciones admiten una vista consolidada.
 * La sucursal operativa permanece disponible para cobros y movimientos.
 */
$sidebar_paginas_globales = [
    'dashboard.php',
    'inscripciones.php',
    'asistencias.php',
    'ventas.php',
    'historial_ventas.php',
    'corte_caja.php',
    'corte_caja_detalle.php',
    'productos.php',
    'inventario.php',
    'historial_stock.php',
    'clases.php',
    'inscripciones_clases.php',
    'solicitudes_usuarios.php',
    'reportes.php',
    'notificaciones.php',
    'sucursales.php',
];

$sidebar_vista_solicitada = strtolower(trim((string) (
    $_GET['vista'] ?? ''
)));

$sidebar_vista_global =
    $sidebar_puede_vista_global
    && in_array($current_page, $sidebar_paginas_globales, true)
    && (
        $sidebar_vista_solicitada === 'global'
        || (
            function_exists('sucursal_dashboard_vista_global')
            && sucursal_dashboard_vista_global()
        )
    );

$sidebar_global_urls = [
    'dashboard.php' =>
        'dashboard.php?vista=global',
    'inscripciones.php' =>
        'inscripciones.php?vista=global',
    'asistencias.php' =>
        'asistencias.php?vista=global',
    'ventas.php' =>
        'ventas.php?vista=global',
    'historial_ventas.php' =>
        'historial_ventas.php?vista=global',
    'corte_caja.php' =>
        'corte_caja.php?vista=global',
    'corte_caja_detalle.php' =>
        'corte_caja.php?vista=global',
    'productos.php' =>
        'productos.php?vista=global',
    'inventario.php' =>
        'inventario.php?vista=global',
    'historial_stock.php' =>
        'historial_stock.php?vista=global',
    'clases.php' =>
        'clases.php?vista=global',
    'inscripciones_clases.php' =>
        'inscripciones_clases.php?vista=global',
    'solicitudes_usuarios.php' =>
        'solicitudes_usuarios.php?vista=global',
    'reportes.php' =>
        'reportes.php?vista=global',
    'notificaciones.php' =>
        'notificaciones.php?vista=global',
    'sucursales.php' =>
        'sucursales.php?vista=global',
];

$sidebar_sucursal_urls = [
    'dashboard.php' =>
        'dashboard.php?vista=sucursal',
    'inscripciones.php' =>
        'inscripciones.php?vista=sucursal',
    'asistencias.php' =>
        'asistencias.php?vista=sucursal',
    'ventas.php' =>
        'ventas.php?vista=sucursal',
    'historial_ventas.php' =>
        'historial_ventas.php?vista=sucursal',
    'corte_caja.php' =>
        'corte_caja.php?vista=sucursal',
    'corte_caja_detalle.php' =>
        'corte_caja.php?vista=sucursal',
    'productos.php' =>
        'productos.php?vista=sucursal',
    'inventario.php' =>
        'inventario.php?vista=sucursal',
    'historial_stock.php' =>
        'historial_stock.php?vista=sucursal',
    'clases.php' =>
        'clases.php?vista=sucursal',
    'inscripciones_clases.php' =>
        'inscripciones_clases.php?vista=sucursal',
    'solicitudes_usuarios.php' =>
        'solicitudes_usuarios.php?vista=sucursal',
    'reportes.php' =>
        'reportes.php?vista=sucursal',
    'notificaciones.php' =>
        'notificaciones.php?vista=sucursal',
    'sucursales.php' =>
        'sucursales.php?vista=sucursal',
];

$sidebar_contexto_titulos = [
    'dashboard.php' => 'Vista del panel',
    'inscripciones.php' =>
        'Vista de inscripciones',
    'asistencias.php' =>
        'Vista de asistencias',
    'ventas.php' =>
        'Sucursal de venta',
    'historial_ventas.php' =>
        'Vista del historial',
    'corte_caja.php' =>
        'Vista de caja',
    'corte_caja_detalle.php' =>
        'Vista de caja',
    'productos.php' =>
        'Vista de productos',
    'inventario.php' =>
        'Vista de inventario',
    'historial_stock.php' =>
        'Vista del stock',
    'clases.php' =>
        'Vista de clases',
    'inscripciones_clases.php' =>
        'Vista de inscripciones a clases',
    'solicitudes_usuarios.php' =>
        'Asignación de personal',
    'reportes.php' =>
        'Vista de reportes',
    'notificaciones.php' =>
        'Vista de notificaciones',
    'sucursales.php' =>
        'Sucursal administrada',
];

$sidebar_global_url =
    $sidebar_global_urls[$current_page]
    ?? 'dashboard.php?vista=global';

$sidebar_retorno_sucursal_url =
    $sidebar_sucursal_urls[$current_page]
    ?? 'dashboard.php?vista=sucursal';

$sidebar_contexto_titulo =
    $sidebar_contexto_titulos[$current_page]
    ?? 'Sucursal activa';

if ($conn instanceof mysqli) {
    $conn->set_charset('utf8mb4');

    try {
        $sidebar_sucursales = sucursal_obtener_asignadas(
            $conn,
            (int) $_SESSION['user_id']
        );
        $sidebar_sucursal_csrf = sucursal_asegurar_csrf();
    } catch (Throwable $sidebarSucursalException) {
        error_log(
            '[Sidebar sucursal] '
            . $sidebarSucursalException->getMessage()
        );
    }
}


$sidebar_sucursal_es_matriz = false;

foreach ($sidebar_sucursales as $sidebarSucursalContexto) {
    if (
        (int) ($sidebarSucursalContexto['id'] ?? 0)
        === $sidebar_sucursal_actual
    ) {
        $sidebar_sucursal_es_matriz =
            (int) ($sidebarSucursalContexto['es_matriz'] ?? 0)
            === 1;
        break;
    }
}

$sidebar_total_sedes = count($sidebar_sucursales);
$sidebar_total_sedes_texto =
    $sidebar_total_sedes === 1
        ? '1 sede'
        : $sidebar_total_sedes . ' sedes';

$sidebar_contexto_nombre = $sidebar_vista_global
    ? 'Todas las sucursales'
    : ($sidebar_sucursal_nombre !== ''
        ? $sidebar_sucursal_nombre
        : 'Sucursal');

$sidebar_contexto_detalle = $sidebar_vista_global
    ? (
        $current_page === 'inscripciones.php'
            ? 'Inscripciones globales · ' . $sidebar_total_sedes_texto
            : (
                $current_page === 'asistencias.php'
                    ? 'Asistencias globales · ' . $sidebar_total_sedes_texto
                    : (
                        $current_page === 'ventas.php'
                            ? 'Elige una sede para vender'
                            : (
                                $current_page === 'historial_ventas.php'
                                    ? 'Ventas de todas las sucursales · ' . $sidebar_total_sedes_texto
                                    : (
                                        in_array($current_page, ['corte_caja.php', 'corte_caja_detalle.php'], true)
                                            ? 'Cortes consolidados · ' . $sidebar_total_sedes_texto
                                            : (
                                                $current_page === 'productos.php'
                                                    ? 'Inventario consolidado · ' . $sidebar_total_sedes_texto
                                                    : (
                                                        $current_page === 'inventario.php'
                                                            ? 'Existencias consolidadas · ' . $sidebar_total_sedes_texto
                                                            : (
                                                                $current_page === 'historial_stock.php'
                                                                    ? 'Movimientos consolidados · ' . $sidebar_total_sedes_texto
                                                                    : (
                                                                        $current_page === 'clases.php'
                                                                            ? 'Clases consolidadas · ' . $sidebar_total_sedes_texto
                                                                            : (
                                                                                $current_page === 'inscripciones_clases.php'
                                                                                    ? 'Inscripciones a clases · ' . $sidebar_total_sedes_texto
                                                                                    : (
                                                                                        $current_page === 'solicitudes_usuarios.php'
                                                                                            ? 'Asignación de personal · ' . $sidebar_total_sedes_texto
                                                                                            : (
                                                                                                $current_page === 'reportes.php'
                                                                                                    ? 'Reportes consolidados · ' . $sidebar_total_sedes_texto
                                                                                                    : (
                                                                                                        $current_page === 'notificaciones.php'
                                                                                                            ? 'Notificaciones consolidadas · ' . $sidebar_total_sedes_texto
                                                                                                            : (
                                                                                                                $current_page === 'sucursales.php'
                                                                                                                    ? 'Administración de ' . $sidebar_total_sedes_texto
                                                                                                                    : 'Estadísticas globales · ' . $sidebar_total_sedes_texto
                                                                                                            )
                                                                                                    )
                                                                                            )
                                                                                    )
                                                                            )
                                                                    )
                                                            )
                                                    )
                                            )
                                    )
                            )
                    )
            )
    )
    : (
        ($sidebar_sucursal_clave !== ''
            ? $sidebar_sucursal_clave
            : 'Sucursal')
        . ($sidebar_sucursal_es_matriz
            ? ' · Matriz'
            : ' · Sucursal')
    );

$sidebar_contexto_valor = $sidebar_vista_global
    ? 0
    : $sidebar_sucursal_actual;

$gym_nombre = 'Gimnasio';
$gym_logo = '';
$gym_logo_url = '';

if ($conn) {
    $query = "
        SELECT nombre, logo
        FROM configuracion_gimnasio
        WHERE id = 1
        LIMIT 1
    ";

    $result = $conn->query($query);

    if ($result && $row = $result->fetch_assoc()) {
        $nombreConfigurado = trim((string) ($row['nombre'] ?? ''));
        $logoConfigurado = trim((string) ($row['logo'] ?? ''));

        if ($nombreConfigurado !== '') {
            $gym_nombre = $nombreConfigurado;
        }

        if (
            $logoConfigurado !== ''
            && sidebarArchivoExiste(
                $logoConfigurado,
                $sidebar_project_root
            )
        ) {
            $gym_logo = $logoConfigurado;
            $gym_logo_url = $logoConfigurado;
        }
    }
}

if ($gym_logo === '') {
    $extensiones = [
        'png',
        'jpg',
        'jpeg',
        'gif',
        'webp',
        'bmp',
        'svg',
        'ico',
    ];

    foreach ($extensiones as $ext) {
        $ruta = 'img/logo-gym.' . $ext;

        if (
            sidebarArchivoExiste(
                $ruta,
                $sidebar_project_root
            )
        ) {
            $gym_logo = $ruta;
            $gym_logo_url = $ruta;
            break;
        }
    }
}

require_once __DIR__ . '/permisos_helper.php';

$sidebar_permisos = permisos_obtener_mapa_rol(
    $conn,
    $user_rol
);

$sidebar_puede = static function (string $clave) use ($sidebar_permisos): bool {
    return !empty($sidebar_permisos[$clave]);
};

// Determinar módulo activo basado en la página actual.
$active_module = '';

if ($sidebar_legal_activo) {
    $active_module = 'legal_acceptances';
} elseif ($current_page === 'dashboard.php') {
    $active_module = 'dashboard';
} elseif ($current_page === 'inventario.php') {
    $active_module = 'inventory_overview';
} elseif ($current_page === 'productos.php') {
    $active_module = 'products';
} elseif ($current_page === 'ventas.php') {
    $active_module = 'ventas';
} elseif ($current_page === 'historial_stock.php') {
    $active_module = 'historial';
} elseif ($current_page === 'historial_ventas.php') {
    $active_module = 'historial_ventas';
} elseif ($current_page === 'inscripciones.php') {
    $active_module = 'inscriptions';
} elseif ($current_page === 'asistencias.php') {
    $active_module = 'assistance';
} elseif ($current_page === 'clases.php') {
    $active_module = 'classes';
} elseif ($current_page === 'inscripciones_clases.php') {
    $active_module = 'clases_inscriptions';
} elseif ($current_page === 'reportes.php') {
    $active_module = 'reports';
} elseif ($current_page === 'notificaciones.php') {
    $active_module = 'notificaciones';
} elseif ($current_page === 'configuracion.php') {
    $active_module = 'settings';
} elseif ($current_page === 'sucursales.php') {
    $active_module = 'branches';
} elseif ($current_page === 'solicitudes_usuarios.php') {
    $active_module = 'user_requests';
} elseif ($current_page === 'permisos_roles.php') {
    $active_module = 'role_permissions';
} elseif ($current_page === 'mi_perfil.php') {
    $active_module = 'perfil';
} elseif (
    $current_page === 'corte_caja.php'
    || $current_page === 'corte_caja_detalle.php'
) {
    $active_module = 'corte_caja';
}

// Obtener datos del usuario desde la sesión
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Usuario';
$user_email = isset($_SESSION['user_email']) ? $_SESSION['user_email'] : 'usuario@email.com';
$user_rol = strtolower(trim((string) ($_SESSION['user_rol'] ?? 'recepcionista')));

// Mostrar rol en español
$rol_spanish = [
    'admin' => 'Administrador',
    'administrador' => 'Administrador',
    'recepcionista' => 'Recepcionista',
    'entrenador' => 'Entrenador'
];
$user_rol_display = isset($rol_spanish[$user_rol]) ? $rol_spanish[$user_rol] : ucfirst($user_rol);

// Contador visible únicamente para administradores.
$solicitudes_pendientes = 0;

if (
    $conn
    && in_array(
        $user_rol,
        ['admin', 'administrador'],
        true
    )
) {
    $query_solicitudes = "
        SELECT COUNT(*) AS total
        FROM usuarios
        WHERE estado = 'pendiente'
          AND rol IN ('recepcionista', 'entrenador')
    ";

    $result_solicitudes = $conn->query($query_solicitudes);

    if (
        $result_solicitudes
        && $row_solicitudes = $result_solicitudes->fetch_assoc()
    ) {
        $solicitudes_pendientes = (int) (
            $row_solicitudes['total'] ?? 0
        );
    }
}
?>

<?php
$sidebar_navbar_css = $sidebar_project_root
    . DIRECTORY_SEPARATOR
    . 'css'
    . DIRECTORY_SEPARATOR
    . 'navbar.css';

$sidebar_navbar_version = is_file($sidebar_navbar_css)
    ? (string) filemtime($sidebar_navbar_css)
    : '1';
?>
<link
    rel="stylesheet"
    href="css/navbar.css?v=<?php echo htmlspecialchars($sidebar_navbar_version, ENT_QUOTES, 'UTF-8'); ?>"
>



<!-- Botón Hamburguesa para móvil (solo visible en móvil) -->
<button class="hamburger-mobile" id="hamburgerMobile" type="button" aria-label="Abrir menú lateral" aria-controls="sidebar" aria-expanded="false">
    <i class="fas fa-bars"></i>
</button>

<!-- Overlay para móvil -->
<div class="mobile-overlay" id="mobileOverlay"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar" aria-label="Navegación principal">
    <div class="drag-handle" id="dragHandle"></div>
    
    <div class="sidebar-header">
        <a href="dashboard.php" class="logo">
            <?php if (
                $gym_logo_url !== ''
                && sidebarArchivoExiste(
                    $gym_logo_url,
                    $sidebar_project_root
                )
            ): ?>
                <img src="<?php echo htmlspecialchars($gym_logo_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo" class="logo-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <i class="fas fa-dumbbell" style="display: none;"></i>
            <?php else: ?>
                <i class="fas fa-dumbbell"></i>
            <?php endif; ?>
            <div class="logo-text">
                <?php echo htmlspecialchars($gym_nombre, ENT_QUOTES, 'UTF-8'); ?>
                <small>Panel de Control</small>
            </div>
        </a>
        <!-- Botón de colapsar DENTRO del sidebar (visible solo en PC) -->
        <button class="sidebar-collapse-btn" id="sidebarCollapseBtn" type="button" aria-label="Contraer menú lateral" title="Contraer menú">
            <i class="fas fa-chevron-left"></i>
        </button>
    </div>

    <div class="user-profile">
        <div class="user-avatar">
            <?php
            $user_id = (int) $_SESSION['user_id'];
            $foto_perfil = '';

            if ($conn) {
                $query_foto = "
                    SELECT foto_perfil
                    FROM usuarios
                    WHERE id = ?
                    LIMIT 1
                ";

                $stmt_foto = $conn->prepare($query_foto);

                if ($stmt_foto) {
                    $stmt_foto->bind_param('i', $user_id);
                    $stmt_foto->execute();

                    $result_foto = $stmt_foto->get_result();

                    if (
                        $result_foto
                        && $row_foto = $result_foto->fetch_assoc()
                    ) {
                        $foto_perfil = trim(
                            (string) ($row_foto['foto_perfil'] ?? '')
                        );
                    }

                    $stmt_foto->close();
                }
            }

            if (
                $foto_perfil !== ''
                && sidebarArchivoExiste(
                    $foto_perfil,
                    $sidebar_project_root
                )
            ):
            ?>
                <img
                    src="<?php echo htmlspecialchars(
                        $foto_perfil,
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>"
                    alt="Foto de perfil"
                >
            <?php else: ?>
                <i class="fas fa-user-circle"></i>
            <?php endif; ?>
        </div>
        <div class="user-info">
            <h4><?php echo htmlspecialchars((string) $user_name, ENT_QUOTES, 'UTF-8'); ?></h4>
            <p>
                <i class="fas fa-envelope"></i> 
                <?php echo htmlspecialchars((string) $user_email, ENT_QUOTES, 'UTF-8'); ?>
            </p>
            <span class="rol-badge">
                <i class="fas fa-user-tag"></i> 
                <?php echo htmlspecialchars($user_rol_display, ENT_QUOTES, 'UTF-8'); ?>
            </span>
        </div>
    </div>

    <?php if ($sidebar_sucursales !== []): ?>
        <section
            class="sidebar-branch-switcher <?php echo $sidebar_vista_global ? 'is-global' : ''; ?>"
            id="sidebarBranchSwitcher"
            aria-label="Contexto del dashboard"
        >
            <div class="sidebar-branch-kicker">
                <span>
                    <i class="fas fa-chart-simple"></i>
                    <?php echo htmlspecialchars(
                        $sidebar_contexto_titulo,
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>
                </span>

                <span class="sidebar-branch-live" aria-hidden="true"></span>
            </div>

            <button
                type="button"
                class="sidebar-branch-trigger"
                id="sidebarBranchTrigger"
                aria-haspopup="true"
                aria-expanded="false"
                aria-controls="sidebarBranchMenu"
            >
                <span class="sidebar-branch-trigger-icon">
                    <i class="fas <?php echo $sidebar_vista_global ? 'fa-chart-pie' : 'fa-building'; ?>"></i>
                </span>

                <span class="sidebar-branch-trigger-copy">
                    <strong>
                        <?php echo htmlspecialchars(
                            $sidebar_contexto_nombre,
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>
                    </strong>

                    <small>
                        <?php echo htmlspecialchars(
                            $sidebar_contexto_detalle,
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>
                    </small>
                </span>

                <i class="fas fa-chevron-down sidebar-branch-trigger-chevron"></i>
            </button>

            <div
                class="sidebar-branch-menu"
                id="sidebarBranchMenu"
                role="menu"
                aria-label="Seleccionar vista o sucursal"
            >
                <?php if ($sidebar_puede_vista_global): ?>
                    <button
                        type="button"
                        class="sidebar-branch-option <?php echo $sidebar_vista_global ? 'active' : ''; ?>"
                        data-sucursal-id="0"
                        data-dashboard-url="<?php echo htmlspecialchars(
                            $sidebar_global_url,
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>"
                        role="menuitem"
                    >
                        <span class="sidebar-branch-option-icon global">
                            <i class="fas fa-chart-pie"></i>
                        </span>

                        <span class="sidebar-branch-option-copy">
                            <strong>Todas las sucursales</strong>
                            <small>
                                <?php
                                if ($current_page === 'inscripciones.php') {
                                    echo 'Inscripciones de todas las sucursales';
                                } elseif ($current_page === 'asistencias.php') {
                                    echo 'Actividad de todas las sucursales';
                                } elseif ($current_page === 'ventas.php') {
                                    echo 'Selecciona después una sede para vender';
                                } elseif ($current_page === 'historial_ventas.php') {
                                    echo 'Ventas, cancelaciones y devoluciones globales';
                                } elseif (in_array($current_page, ['corte_caja.php', 'corte_caja_detalle.php'], true)) {
                                    echo 'Historial de cortes de todas las sucursales';
                                } elseif ($current_page === 'productos.php') {
                                    echo 'Catálogo e inventario de todas las sucursales';
                                } elseif ($current_page === 'inventario.php') {
                                    echo 'Existencias y precios consolidados por producto';
                                } elseif ($current_page === 'historial_stock.php') {
                                    echo 'Movimientos de stock de todas las sucursales';
                                } elseif ($current_page === 'clases.php') {
                                    echo 'Clases y cupos de todas las sucursales';
                                } elseif ($current_page === 'inscripciones_clases.php') {
                                    echo 'Inscripciones a clases de todas las sucursales';
                                } elseif ($current_page === 'solicitudes_usuarios.php') {
                                    echo 'Elegir la sede al aprobar cada solicitud';
                                } elseif ($current_page === 'reportes.php') {
                                    echo 'Inscripciones, ventas e ingresos de todas las sucursales';
                                } elseif ($current_page === 'notificaciones.php') {
                                    echo 'Comunicados e historial de todas las sucursales';
                                } elseif ($current_page === 'sucursales.php') {
                                    echo 'Listado y administración de todas las sedes';
                                } else {
                                    echo 'Estadísticas globales del gimnasio';
                                }
                                ?>
                            </small>
                        </span>

                        <?php if ($sidebar_vista_global): ?>
                            <i class="fas fa-check sidebar-branch-option-check"></i>
                        <?php endif; ?>
                    </button>

                    <div class="sidebar-branch-menu-label">
                        Sucursales operativas
                    </div>
                <?php endif; ?>

                <?php foreach ($sidebar_sucursales as $sidebarSucursal): ?>
                    <?php
                    $sidebarOpcionActiva =
                        !$sidebar_vista_global
                        && (int) $sidebarSucursal['id']
                            === $sidebar_sucursal_actual;
                    ?>

                    <button
                        type="button"
                        class="sidebar-branch-option <?php echo $sidebarOpcionActiva ? 'active' : ''; ?>"
                        data-sucursal-id="<?php echo (int) $sidebarSucursal['id']; ?>"
                        role="menuitem"
                    >
                        <span class="sidebar-branch-option-icon">
                            <i class="fas fa-building"></i>
                        </span>

                        <span class="sidebar-branch-option-copy">
                            <strong>
                                <?php echo htmlspecialchars(
                                    (string) $sidebarSucursal['nombre'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                            </strong>

                            <small>
                                <?php echo htmlspecialchars(
                                    (string) $sidebarSucursal['clave'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>
                                <?php if ((int) ($sidebarSucursal['es_matriz'] ?? 0) === 1): ?>
                                    · Matriz
                                <?php endif; ?>
                            </small>
                        </span>

                        <?php if ($sidebarOpcionActiva): ?>
                            <i class="fas fa-check sidebar-branch-option-check"></i>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="sidebar-branch-context-note">
                <?php if ($sidebar_vista_global): ?>
                    <i class="fas fa-chart-column"></i>
                    <?php
                    if ($current_page === 'inscripciones.php') {
                        echo 'Listado consolidado de';
                    } elseif ($current_page === 'asistencias.php') {
                        echo 'Actividad consolidada de';
                    } elseif ($current_page === 'ventas.php') {
                        echo 'Selecciona una sucursal para vender';
                    } elseif ($current_page === 'historial_ventas.php') {
                        echo 'Historial consolidado de';
                    } elseif (in_array($current_page, ['corte_caja.php', 'corte_caja_detalle.php'], true)) {
                        echo 'Cortes consolidados de';
                    } elseif ($current_page === 'productos.php') {
                        echo 'Inventario consolidado de';
                    } elseif ($current_page === 'inventario.php') {
                        echo 'Existencias consolidadas de';
                    } elseif ($current_page === 'historial_stock.php') {
                        echo 'Movimientos consolidados de';
                    } elseif ($current_page === 'clases.php') {
                        echo 'Clases consolidadas de';
                    } elseif ($current_page === 'inscripciones_clases.php') {
                        echo 'Inscripciones consolidadas de';
                    } elseif ($current_page === 'solicitudes_usuarios.php') {
                        echo 'Asignación disponible para';
                    } elseif ($current_page === 'reportes.php') {
                        echo 'Reportes consolidados de';
                    } elseif ($current_page === 'notificaciones.php') {
                        echo 'Notificaciones consolidadas de';
                    } elseif ($current_page === 'sucursales.php') {
                        echo 'Administración disponible para';
                    } else {
                        echo 'Estadísticas consolidadas de';
                    }
                    ?>
                    <?php if ($current_page !== 'ventas.php'): ?>
                        <strong>
                            <?php echo htmlspecialchars(
                                $sidebar_total_sedes_texto,
                                ENT_QUOTES,
                                'UTF-8'
                            ); ?>
                        </strong>
                    <?php endif; ?>
                <?php else: ?>
                    <i class="fas fa-location-dot"></i>
                    Vista de
                    <strong>
                        <?php echo $sidebar_sucursal_es_matriz
                            ? 'sucursal matriz'
                            : 'sucursal'; ?>
                    </strong>
                <?php endif; ?>
            </div>

            <div class="sidebar-branch-loading" aria-live="polite">
                <i class="fas fa-spinner fa-spin"></i>
                <?php
                if ($current_page === 'asistencias.php') {
                    echo 'Actualizando asistencias...';
                } elseif ($current_page === 'ventas.php') {
                    echo 'Cambiando sucursal de venta...';
                } elseif ($current_page === 'historial_ventas.php') {
                    echo 'Actualizando historial de ventas...';
                } elseif (in_array($current_page, ['corte_caja.php', 'corte_caja_detalle.php'], true)) {
                    echo 'Actualizando cortes de caja...';
                } elseif ($current_page === 'productos.php') {
                    echo 'Actualizando productos...';
                } elseif ($current_page === 'inventario.php') {
                    echo 'Actualizando inventario...';
                } elseif ($current_page === 'historial_stock.php') {
                    echo 'Actualizando historial de stock...';
                } elseif ($current_page === 'clases.php') {
                    echo 'Actualizando clases...';
                } elseif ($current_page === 'inscripciones_clases.php') {
                    echo 'Actualizando inscripciones a clases...';
                } elseif ($current_page === 'solicitudes_usuarios.php') {
                    echo 'Actualizando solicitudes de usuarios...';
                } elseif ($current_page === 'reportes.php') {
                    echo 'Actualizando reportes...';
                } elseif ($current_page === 'notificaciones.php') {
                    echo 'Actualizando notificaciones...';
                } elseif ($current_page === 'sucursales.php') {
                    echo 'Cambiando sucursal administrada...';
                } else {
                    echo 'Actualizando estadísticas...';
                }
                ?>
            </div>
        </section>
    <?php endif; ?>

    <?php
    $puede_dashboard = $sidebar_puede('dashboard');
    $puede_inscripciones = $sidebar_puede('inscripciones');
    $puede_asistencias = $sidebar_puede('asistencias');
    $puede_ventas = $sidebar_puede('ventas');
    $puede_historial_ventas = $sidebar_puede('historial_ventas');
    $puede_corte_caja = $sidebar_puede('corte_caja');
    $puede_inventario_resumen = in_array(
        $user_rol,
        ['admin', 'administrador'],
        true
    );
    $puede_productos = $sidebar_puede('productos');
    $puede_historial_stock = $sidebar_puede('historial_stock');
    $puede_clases = $sidebar_puede('clases');
    $puede_inscripciones_clases = $sidebar_puede('inscripciones_clases');
    $puede_reportes = $sidebar_puede('reportes');
    $puede_notificaciones = $sidebar_puede('notificaciones');
    $puede_solicitudes = $sidebar_puede('solicitudes_usuarios');
    $puede_configuracion = $sidebar_puede('configuracion');
    $puede_sucursales = in_array(
        $user_rol,
        ['admin', 'administrador'],
        true
    );
    $puede_permisos_roles = $sidebar_puede('permisos_roles');

    $mostrar_grupo_socios = $puede_inscripciones || $puede_asistencias;
    $mostrar_grupo_ventas = $puede_ventas || $puede_historial_ventas || $puede_corte_caja;
    $mostrar_grupo_inventario = $puede_inventario_resumen
        || $puede_productos
        || $puede_historial_stock;
    $mostrar_grupo_clases = $puede_clases || $puede_inscripciones_clases;
    $mostrar_grupo_admin = $puede_reportes
        || $puede_notificaciones
        || $puede_solicitudes
        || $puede_sucursales
        || $puede_configuracion;

    $grupo_inventario_activo = in_array(
        $active_module,
        ['inventory_overview', 'products', 'historial'],
        true
    );
    $grupo_ventas_activo = in_array($active_module, ['ventas', 'historial_ventas', 'corte_caja'], true);
    $grupo_socios_activo = in_array($active_module, ['inscriptions', 'assistance'], true);
    $grupo_clases_activo = in_array($active_module, ['classes', 'clases_inscriptions'], true);
    $grupo_admin_activo = in_array(
        $active_module,
        ['reports', 'notificaciones', 'settings', 'user_requests', 'branches'],
        true
    );
    ?>

    <nav class="sidebar-nav" aria-label="Módulos del sistema">
        <ul class="sidebar-menu">
            <?php if ($puede_dashboard): ?>
                <li class="nav-item nav-dashboard">
                    <a href="dashboard.php" class="nav-link <?php echo $active_module === 'dashboard' ? 'active' : ''; ?>">
                        <i class="fas fa-gauge-high"></i>
                        <span class="nav-text">Panel principal</span>
                    </a>
                </li>
            <?php endif; ?>

            <?php if ($mostrar_grupo_socios): ?>
                <li class="nav-group <?php echo $grupo_socios_activo ? 'open' : ''; ?>" data-group="socios">
                    <button type="button" class="nav-group-toggle" aria-expanded="<?php echo $grupo_socios_activo ? 'true' : 'false'; ?>">
                        <i class="fas fa-users"></i>
                        <span class="nav-group-label">Socios</span>
                        <i class="fas fa-chevron-down group-chevron"></i>
                    </button>
                    <ul class="nav-submenu">
                        <?php if ($puede_inscripciones): ?>
                            <li>
                                <a href="inscripciones.php" class="nav-link <?php echo $active_module === 'inscriptions' ? 'active' : ''; ?>">
                                    <i class="fas fa-id-card"></i>
                                    <span class="nav-text">Inscripciones</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($puede_asistencias): ?>
                            <li>
                                <a href="asistencias.php" class="nav-link <?php echo $active_module === 'assistance' ? 'active' : ''; ?>">
                                    <i class="fas fa-fingerprint"></i>
                                    <span class="nav-text">Asistencias</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </li>
            <?php endif; ?>

            <?php if ($mostrar_grupo_ventas): ?>
                <li class="nav-group <?php echo $grupo_ventas_activo ? 'open' : ''; ?>" data-group="ventas">
                    <button type="button" class="nav-group-toggle" aria-expanded="<?php echo $grupo_ventas_activo ? 'true' : 'false'; ?>">
                        <i class="fas fa-cash-register"></i>
                        <span class="nav-group-label">Ventas y caja</span>
                        <i class="fas fa-chevron-down group-chevron"></i>
                    </button>
                    <ul class="nav-submenu">
                        <?php if ($puede_ventas): ?>
                            <li>
                                <a href="ventas.php" class="nav-link <?php echo $active_module === 'ventas' ? 'active' : ''; ?>">
                                    <i class="fas fa-cart-shopping"></i>
                                    <span class="nav-text">Venta de productos</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($puede_historial_ventas): ?>
                            <li>
                                <a href="historial_ventas.php" class="nav-link <?php echo $active_module === 'historial_ventas' ? 'active' : ''; ?>">
                                    <i class="fas fa-receipt"></i>
                                    <span class="nav-text">Historial de ventas</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($puede_corte_caja): ?>
                            <li>
                                <a href="corte_caja.php" class="nav-link <?php echo $active_module === 'corte_caja' ? 'active' : ''; ?>">
                                    <i class="fas fa-coins"></i>
                                    <span class="nav-text">Corte de caja</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </li>
            <?php endif; ?>

            <?php if ($mostrar_grupo_inventario): ?>
                <li class="nav-group <?php echo $grupo_inventario_activo ? 'open' : ''; ?>" data-group="inventario">
                    <button type="button" class="nav-group-toggle" aria-expanded="<?php echo $grupo_inventario_activo ? 'true' : 'false'; ?>">
                        <i class="fas fa-boxes-stacked"></i>
                        <span class="nav-group-label">Inventario</span>
                        <i class="fas fa-chevron-down group-chevron"></i>
                    </button>
                    <ul class="nav-submenu">
                        <?php if ($puede_productos): ?>
                            <li>
                                <a href="productos.php" class="nav-link <?php echo $active_module === 'products' ? 'active' : ''; ?>">
                                    <i class="fas fa-box"></i>
                                    <span class="nav-text">Productos</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($puede_inventario_resumen): ?>
                            <li>
                                <a
                                    href="inventario.php"
                                    class="nav-link <?php echo $active_module === 'inventory_overview' ? 'active' : ''; ?>"
                                >
                                    <i class="fas fa-boxes-stacked"></i>
                                    <span class="nav-text">Inventario</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($puede_historial_stock): ?>
                            <li>
                                <a href="historial_stock.php" class="nav-link <?php echo $active_module === 'historial' ? 'active' : ''; ?>">
                                    <i class="fas fa-clock-rotate-left"></i>
                                    <span class="nav-text">Historial de stock</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </li>
            <?php endif; ?>

            <?php if ($mostrar_grupo_clases): ?>
                <li class="nav-group <?php echo $grupo_clases_activo ? 'open' : ''; ?>" data-group="clases">
                    <button type="button" class="nav-group-toggle" aria-expanded="<?php echo $grupo_clases_activo ? 'true' : 'false'; ?>">
                        <i class="fas fa-calendar-days"></i>
                        <span class="nav-group-label">Clases</span>
                        <i class="fas fa-chevron-down group-chevron"></i>
                    </button>
                    <ul class="nav-submenu">
                        <?php if ($puede_clases): ?>
                            <li>
                                <a href="clases.php" class="nav-link <?php echo $active_module === 'classes' ? 'active' : ''; ?>">
                                    <i class="fas fa-dumbbell"></i>
                                    <span class="nav-text"><?php echo $user_rol === 'entrenador' ? 'Mis clases' : 'Administrar clases'; ?></span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($puede_inscripciones_clases): ?>
                            <li>
                                <a href="inscripciones_clases.php" class="nav-link <?php echo $active_module === 'clases_inscriptions' ? 'active' : ''; ?>">
                                    <i class="fas fa-user-check"></i>
                                    <span class="nav-text">Socios por clase</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </li>
            <?php endif; ?>

            <?php if ($mostrar_grupo_admin): ?>
                <li class="nav-group <?php echo $grupo_admin_activo ? 'open' : ''; ?>" data-group="administracion">
                    <button type="button" class="nav-group-toggle" aria-expanded="<?php echo $grupo_admin_activo ? 'true' : 'false'; ?>">
                        <i class="fas fa-sliders"></i>
                        <span class="nav-group-label">Administración</span>
                        <i class="fas fa-chevron-down group-chevron"></i>
                    </button>
                    <ul class="nav-submenu">
                        <?php if ($puede_solicitudes): ?>
                            <li>
                                <a href="solicitudes_usuarios.php" class="nav-link <?php echo $active_module === 'user_requests' ? 'active' : ''; ?>">
                                    <i class="fas fa-user-clock"></i>
                                    <span class="nav-text">Solicitudes de usuarios</span>
                                    <?php if ($solicitudes_pendientes > 0): ?>
                                        <span class="nav-count" aria-label="<?php echo $solicitudes_pendientes; ?> solicitudes pendientes" title="<?php echo $solicitudes_pendientes; ?> solicitudes pendientes">
                                            <?php echo $solicitudes_pendientes > 99 ? '99+' : $solicitudes_pendientes; ?>
                                        </span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($puede_reportes): ?>
                            <li>
                                <a href="reportes.php" class="nav-link <?php echo $active_module === 'reports' ? 'active' : ''; ?>">
                                    <i class="fas fa-chart-column"></i>
                                    <span class="nav-text">Reportes</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($puede_notificaciones): ?>
                            <li>
                                <a href="notificaciones.php" class="nav-link <?php echo $active_module === 'notificaciones' ? 'active' : ''; ?>">
                                    <i class="fas fa-bell"></i>
                                    <span class="nav-text">Notificaciones</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($puede_sucursales): ?>
                            <li>
                                <a href="sucursales.php" class="nav-link <?php echo $active_module === 'branches' ? 'active' : ''; ?>">
                                    <i class="fas fa-building"></i>
                                    <span class="nav-text">Sucursales</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php if ($puede_configuracion): ?>
                            <li>
                                <a href="configuracion.php" class="nav-link <?php echo $active_module === 'settings' ? 'active' : ''; ?>">
                                    <i class="fas fa-gear"></i>
                                    <span class="nav-text">Configuración</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </li>
            <?php endif; ?>

            <?php if ($puede_permisos_roles): ?>
                <li class="nav-item">
                    <a
                        href="permisos_roles.php"
                        class="nav-link <?php echo
                            $active_module === 'role_permissions'
                                ? 'active'
                                : '';
                        ?>"
                        <?php echo
                            $active_module === 'role_permissions'
                                ? 'aria-current="page"'
                                : '';
                        ?>
                    >
                        <i class="fas fa-key"></i>
                        <span class="nav-text">Control de acceso</span>
                    </a>
                </li>
            <?php endif; ?>

            <li class="nav-item">
                <a href="legal.php" class="nav-link legal-access-link <?php echo $active_module === 'legal_acceptances' ? 'active' : ''; ?>" <?php echo $active_module === 'legal_acceptances' ? 'aria-current="page"' : ''; ?>>
                    <i class="fas fa-shield-halved"></i>
                    <span class="nav-text">Aviso y términos</span>
                </a>
            </li>

            <li class="nav-divider" aria-hidden="true"></li>

            <li class="nav-item">
                <a href="mi_perfil.php" class="nav-link <?php echo $active_module === 'perfil' ? 'active' : ''; ?>">
                    <i class="fas fa-circle-user"></i>
                    <span class="nav-text">Mi perfil</span>
                </a>
            </li>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span class="logout-text">Cerrar Sesión</span>
        </a>
    </div>
</aside>

<?php if ($sidebar_sucursal_csrf !== '' && $sidebar_sucursales !== []): ?>
<script>
(function () {
    const switcher = document.getElementById(
        'sidebarBranchSwitcher'
    );
    const trigger = document.getElementById(
        'sidebarBranchTrigger'
    );
    const menu = document.getElementById(
        'sidebarBranchMenu'
    );

    if (!switcher || !trigger || !menu) {
        return;
    }

    const options = Array.from(
        menu.querySelectorAll('[data-sucursal-id]')
    );

    let currentValue = <?php echo json_encode(
        (string) $sidebar_contexto_valor,
        JSON_HEX_TAG
        | JSON_HEX_APOS
        | JSON_HEX_AMP
        | JSON_HEX_QUOT
    ); ?>;

    const returnAfterBranchChange = <?php echo json_encode(
        $sidebar_retorno_sucursal_url,
        JSON_HEX_TAG
        | JSON_HEX_APOS
        | JSON_HEX_AMP
        | JSON_HEX_QUOT
    ); ?>;

    let changing = false;

    function setOpen(open) {
        switcher.classList.toggle('is-open', open);
        trigger.setAttribute(
            'aria-expanded',
            open ? 'true' : 'false'
        );
    }

    trigger.addEventListener('click', function () {
        if (changing) {
            return;
        }

        setOpen(!switcher.classList.contains('is-open'));
    });

    options.forEach(function (option) {
        option.addEventListener('click', async function () {
            const newValue = String(
                option.getAttribute('data-sucursal-id') || ''
            );

            if (changing || newValue === '') {
                return;
            }

            if (newValue === currentValue) {
                setOpen(false);
                return;
            }

            changing = true;
            switcher.classList.add('is-loading');
            setOpen(false);

            trigger.disabled = true;
            options.forEach(function (item) {
                item.disabled = true;
            });

            /*
             * Cada módulo resuelve su propia vista global.
             * En ventas esta opción muestra un bloqueo hasta que se elija
             * una sucursal operativa.
             */
            if (newValue === '0') {
                const globalUrl = option.getAttribute(
                    'data-dashboard-url'
                ) || 'dashboard.php?vista=global';

                window.location.assign(globalUrl);
                return;
            }

            const body = new URLSearchParams({
                sucursal_id: newValue,
                csrf: <?php echo json_encode(
                    $sidebar_sucursal_csrf,
                    JSON_HEX_TAG
                    | JSON_HEX_APOS
                    | JSON_HEX_AMP
                    | JSON_HEX_QUOT
                ); ?>
            });

            try {
                const response = await fetch(
                    'api/cambiar_sucursal.php',
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type':
                                'application/x-www-form-urlencoded; charset=UTF-8',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        credentials: 'same-origin',
                        body: body.toString()
                    }
                );

                const data = await response.json();

                if (!response.ok || !data.ok) {
                    throw new Error(
                        data.mensaje
                        || 'No fue posible cambiar la vista.'
                    );
                }

                currentValue = newValue;
                window.location.replace(
                    returnAfterBranchChange
                );
            } catch (error) {
                changing = false;
                trigger.disabled = false;
                switcher.classList.remove('is-loading');

                options.forEach(function (item) {
                    item.disabled = false;
                });

                const message = error instanceof Error
                    ? error.message
                    : 'No fue posible cambiar la vista.';

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'Cambio no realizado',
                        text: message,
                        confirmButtonText: 'Entendido',
                        confirmButtonColor: '#1e3a8a'
                    });
                } else {
                    alert(message);
                }
            }
        });
    });

    document.addEventListener('click', function (event) {
        if (!switcher.contains(event.target)) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            setOpen(false);
            trigger.focus();
        }
    });
})();
</script>
<?php endif; ?>

<?php if ($sidebar_legal_pendiente): ?>
<style>
    html.legal-acceptance-required,
    body.legal-acceptance-required {
        width: 100%;
        height: 100%;
        overflow: hidden !important;
    }

    body.legal-acceptance-required
    .sidebar
    .nav-link:not(.legal-access-link),
    body.legal-acceptance-required
    .sidebar
    .nav-group-toggle,
    body.legal-acceptance-required
    .sidebar
    .sidebar-collapse-btn,
    body.legal-acceptance-required
    .sidebar
    .drag-handle {
        pointer-events: none !important;
        opacity: .42 !important;
    }

    body.legal-acceptance-required
    .sidebar
    .legal-access-link,
    body.legal-acceptance-required
    .sidebar
    .logout-btn {
        pointer-events: auto !important;
        opacity: 1 !important;
    }

    .legal-required-overlay {
        position: fixed;
        inset: 0;
        z-index: 11000;
        display: grid;
        place-items: center;
        padding: 14px;
        overflow: hidden;
        background: rgba(15, 23, 42, .58);
        backdrop-filter: blur(7px);
    }

    .legal-required-card {
        display: grid;
        grid-template-rows: auto auto auto minmax(0, 1fr) auto;
        width: min(940px, 100%);
        max-height: calc(100dvh - 28px);
        overflow: hidden;
        border: 1px solid rgba(255, 255, 255, .78);
        border-radius: 21px;
        background: #ffffff;
        box-shadow: 0 30px 90px rgba(2, 6, 23, .34);
    }

    .legal-required-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 18px;
        padding: 18px 20px 15px;
        border-bottom: 1px solid #e8edf4;
        background: #ffffff;
    }

    .legal-required-heading {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        min-width: 0;
    }

    .legal-required-header-icon {
        display: grid;
        flex: 0 0 42px;
        width: 42px;
        height: 42px;
        place-items: center;
        border-radius: 12px;
        color: #1e3a8a;
        background: #eef4ff;
        font-size: .95rem;
    }

    .legal-required-kicker {
        display: block;
        margin-bottom: 3px;
        color: #64748b;
        font-size: .61rem;
        font-weight: 850;
        letter-spacing: .075em;
        text-transform: uppercase;
    }

    .legal-required-header h2 {
        margin: 0 0 4px;
        color: #13275c;
        font-size: clamp(1.2rem, 3vw, 1.55rem);
        line-height: 1.12;
        letter-spacing: -.025em;
    }

    .legal-required-header p {
        max-width: 610px;
        margin: 0;
        color: #64748b;
        font-size: .7rem;
        line-height: 1.48;
    }

    .legal-required-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        flex: 0 0 auto;
        min-height: 29px;
        padding: 0 10px;
        border-radius: 999px;
        color: #92400e;
        background: #fffbeb;
        font-size: .6rem;
        font-weight: 850;
    }

    .legal-document-tabs {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        padding: 11px 20px;
        border-bottom: 1px solid #e8edf4;
        background: #f8fafc;
    }

    .legal-document-tab {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        min-width: 0;
        min-height: 45px;
        padding: 8px 11px;
        border: 1px solid #d7e0ec;
        border-radius: 10px;
        color: #475569;
        background: #ffffff;
        cursor: pointer;
        transition:
            border-color .18s ease,
            color .18s ease,
            background .18s ease,
            box-shadow .18s ease;
    }

    .legal-document-tab:hover {
        border-color: #a9bddd;
        color: #1e3a8a;
    }

    .legal-document-tab.active {
        border-color: #8facd9;
        color: #1e3a8a;
        background: #eef4ff;
        box-shadow: 0 0 0 3px rgba(30, 58, 138, .07);
    }

    .legal-document-tab.completed {
        border-color: #a7f3d0;
        color: #047857;
        background: #ecfdf5;
    }

    .legal-document-tab.completed.active {
        border-color: #6ee7b7;
        box-shadow: 0 0 0 3px rgba(4, 120, 87, .07);
    }

    .legal-document-tab-main {
        display: flex;
        align-items: center;
        gap: 9px;
        min-width: 0;
    }

    .legal-document-tab-icon {
        display: grid;
        flex: 0 0 31px;
        width: 31px;
        height: 31px;
        place-items: center;
        border-radius: 9px;
        color: inherit;
        background: rgba(148, 163, 184, .12);
        font-size: .72rem;
    }

    .legal-document-tab-copy {
        min-width: 0;
        text-align: left;
    }

    .legal-document-tab-copy strong {
        display: block;
        overflow: hidden;
        font-size: .69rem;
        white-space: nowrap;
        text-overflow: ellipsis;
    }

    .legal-document-tab-copy span {
        display: block;
        margin-top: 2px;
        color: #64748b;
        font-size: .56rem;
    }

    .legal-document-tab-state {
        display: grid;
        flex: 0 0 23px;
        width: 23px;
        height: 23px;
        place-items: center;
        border-radius: 999px;
        color: #94a3b8;
        background: #f1f5f9;
        font-size: .57rem;
    }

    .legal-document-tab.completed
    .legal-document-tab-state {
        color: #ffffff;
        background: #10b981;
    }

    .legal-reading-progress {
        height: 3px;
        background: #e8edf5;
    }

    .legal-reading-progress > span {
        display: block;
        width: 0;
        height: 100%;
        background: #2563eb;
        transition: width .12s linear;
    }

    .legal-required-content {
        min-height: 0;
        padding: 17px 20px 21px;
        overflow-x: hidden;
        overflow-y: auto;
        overscroll-behavior: contain;
        scrollbar-width: thin;
        scrollbar-color: #aebbd0 transparent;
    }

    .legal-required-content::-webkit-scrollbar {
        width: 6px;
    }

    .legal-required-content::-webkit-scrollbar-track {
        background: transparent;
    }

    .legal-required-content::-webkit-scrollbar-thumb {
        border-radius: 999px;
        background: #aebbd0;
    }

    .legal-required-greeting {
        margin: 0 0 15px;
        color: #475569;
        font-size: .7rem;
        line-height: 1.48;
    }

    .legal-required-greeting strong {
        color: #1f2937;
    }

    .legal-document-panel {
        display: none;
    }

    .legal-document-panel.active {
        display: block;
    }

    .legal-inline-document {
        margin-bottom: 0;
        padding: 18px 19px;
        border: 1px solid #dce4ef;
        border-radius: 15px;
        background: #ffffff;
    }

    .legal-inline-document:last-of-type {
        margin-bottom: 14px;
    }

    .legal-inline-document-header {
        display: flex;
        align-items: flex-start;
        gap: 11px;
        margin-bottom: 15px;
        padding-bottom: 13px;
        border-bottom: 1px solid #e8edf4;
    }

    .legal-inline-document-icon {
        display: grid;
        flex: 0 0 39px;
        width: 39px;
        height: 39px;
        place-items: center;
        border-radius: 11px;
        color: #1e3a8a;
        background: #eef4ff;
        font-size: .85rem;
    }

    .legal-inline-document-header h3 {
        margin: 0 0 4px;
        color: #13275c;
        font-size: .94rem;
    }

    .legal-inline-document-header p {
        margin: 0;
        color: #64748b;
        font-size: .66rem;
        line-height: 1.42;
    }

    .legal-inline-document-version {
        display: inline-flex;
        margin-top: 6px;
        padding: 4px 7px;
        border-radius: 999px;
        color: #475569;
        background: #f1f5f9;
        font-size: .57rem;
        font-weight: 750;
    }

    .legal-inline-document-body h2 {
        margin: 19px 0 7px;
        color: #13275c;
        font-size: .84rem;
    }

    .legal-inline-document-body h2:first-child {
        margin-top: 0;
    }

    .legal-inline-document-body p,
    .legal-inline-document-body li {
        color: #475569;
        font-size: .71rem;
        line-height: 1.62;
    }

    .legal-inline-document-body p {
        margin: 0 0 8px;
    }

    .legal-inline-document-body ul {
        margin: 7px 0 11px;
        padding-left: 20px;
    }

    .legal-inline-document-body li {
        margin-bottom: 5px;
    }

    .legal-protection-block {
        margin: 17px 0;
        padding: 15px;
        border: 1px solid #bfdbfe;
        border-radius: 13px;
        background: #f8fbff;
    }

    .legal-protection-block h2 {
        margin-top: 0;
    }

    .legal-copy-warning {
        margin: 12px 0;
        padding: 13px;
        border: 1px solid #fde68a;
        border-radius: 11px;
        color: #78350f;
        background: #fffbeb;
    }

    .legal-copy-warning li {
        color: #78350f;
    }

    .legal-document-note {
        margin-top: 18px;
        padding: 11px 12px;
        border-radius: 9px;
        color: #64748b;
        background: #f8fafc;
        font-size: .62rem;
        line-height: 1.45;
    }

    .legal-required-warning {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        margin-bottom: 15px;
        padding: 11px 12px;
        border: 1px solid #fde68a;
        border-radius: 11px;
        color: #78350f;
        background: #fffbeb;
        font-size: .64rem;
        line-height: 1.46;
    }

    .legal-required-warning > i {
        flex: 0 0 auto;
        margin-top: 2px;
    }

    .legal-required-warning strong {
        display: block;
        margin-bottom: 2px;
        font-size: .68rem;
    }

    .legal-required-error {
        margin-bottom: 14px;
        padding: 11px 12px;
        border: 1px solid #fecaca;
        border-radius: 10px;
        color: #991b1b;
        background: #fef2f2;
        font-size: .66rem;
        line-height: 1.48;
    }

    .legal-document-finished {
        display: none;
        align-items: center;
        gap: 7px;
        margin-top: 14px;
        padding: 9px 11px;
        border: 1px solid #a7f3d0;
        border-radius: 10px;
        color: #065f46;
        background: #ecfdf5;
        font-size: .64rem;
        font-weight: 750;
    }

    .legal-document-finished.show {
        display: flex;
    }

    .legal-read-complete {
        display: none;
        align-items: center;
        gap: 7px;
        margin: 0 0 11px;
        padding: 9px 11px;
        border: 1px solid #a7f3d0;
        border-radius: 10px;
        color: #065f46;
        background: #ecfdf5;
        font-size: .64rem;
        font-weight: 750;
    }

    .legal-read-complete.show {
        display: flex;
    }

    .legal-required-checks {
        display: grid;
        grid-template-columns: 1fr;
        gap: 9px;
    }

    .legal-required-check {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        width: 100%;
        padding: 11px 12px;
        border: 1px solid #dce4ef;
        border-radius: 11px;
        cursor: pointer;
        transition:
            border-color .18s ease,
            background .18s ease,
            opacity .18s ease;
    }

    .legal-required-check.locked {
        cursor: not-allowed;
        opacity: .52;
        background: #f8fafc;
    }

    .legal-required-check:not(.locked):hover {
        border-color: #abc0df;
        background: #fbfdff;
    }

    .legal-required-check input {
        flex: 0 0 auto;
        width: 18px;
        height: 18px;
        margin-top: 1px;
        accent-color: #1e3a8a;
    }

    .legal-required-check strong {
        display: block;
        color: #1f2937;
        font-size: .71rem;
    }

    .legal-required-check span span {
        display: block;
        margin-top: 3px;
        color: #64748b;
        font-size: .63rem;
        line-height: 1.4;
    }

    .legal-required-footer {
        display: grid;
        grid-template-columns: auto minmax(180px, 1fr) auto;
        align-items: center;
        gap: 13px;
        padding: 13px 20px 14px;
        border-top: 1px solid #e8edf4;
        background: #ffffff;
        box-shadow: 0 -8px 22px rgba(15, 23, 42, .045);
    }

    .legal-required-note {
        color: #64748b;
        font-size: .59rem;
        line-height: 1.36;
        text-align: center;
    }

    .legal-required-note strong {
        color: #334155;
    }

    .legal-required-submit,
    .legal-required-logout {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        min-height: 41px;
        padding: 0 15px;
        border-radius: 10px;
        font-size: .69rem;
        font-weight: 850;
        text-decoration: none;
        white-space: nowrap;
    }

    .legal-required-submit {
        min-width: 190px;
        border: 0;
        color: #ffffff;
        background: #1e3a8a;
        cursor: pointer;
        transition:
            background .18s ease,
            transform .18s ease;
    }

    .legal-required-submit:hover:not(:disabled) {
        background: #254a9e;
        transform: translateY(-1px);
    }

    .legal-required-submit:disabled {
        cursor: not-allowed;
        opacity: .42;
        transform: none;
    }

    .legal-required-logout {
        min-width: 132px;
        border: 1px solid #fecaca;
        color: #b91c1c;
        background: #fff7f7;
    }

    .legal-required-logout:hover {
        border-color: #fca5a5;
        background: #fef2f2;
    }

    .legal-required-loading {
        position: fixed;
        inset: 0;
        z-index: 13000;
        display: none;
        place-items: center;
        background: rgba(243, 246, 250, .95);
    }

    .legal-required-loading.show {
        display: grid;
    }

    .legal-required-loading-box {
        min-width: 245px;
        padding: 24px;
        border: 1px solid #dce4ef;
        border-radius: 15px;
        background: #ffffff;
        box-shadow: 0 18px 45px rgba(15, 23, 42, .14);
        text-align: center;
    }

    .legal-required-loading-box i {
        color: #1e3a8a;
        font-size: 1.6rem;
    }

    .legal-required-loading-box strong,
    .legal-required-loading-box span {
        display: block;
    }

    .legal-required-loading-box strong {
        margin-top: 11px;
        font-size: .8rem;
    }

    .legal-required-loading-box span {
        margin-top: 4px;
        color: #64748b;
        font-size: .66rem;
    }

    @media (max-width: 720px) {
        .legal-required-overlay {
            padding: 7px;
        }

        .legal-required-card {
            width: 100%;
            max-height: calc(100dvh - 14px);
            border-radius: 16px;
        }

        .legal-required-header {
            padding: 15px 14px 12px;
        }

        .legal-required-header-icon {
            width: 39px;
            height: 39px;
            flex-basis: 39px;
        }

        .legal-required-badge {
            display: none;
        }

        .legal-document-tabs {
            gap: 6px;
            padding: 9px 10px;
        }

        .legal-document-tab {
            min-height: 42px;
            padding: 7px 8px;
        }

        .legal-document-tab-icon {
            display: none;
        }

        .legal-document-tab-copy strong {
            font-size: .63rem;
        }

        .legal-document-tab-copy span {
            font-size: .52rem;
        }

        .legal-required-content {
            padding: 13px 14px 17px;
        }

        .legal-inline-document {
            padding: 15px 14px;
        }

        .legal-required-footer {
            grid-template-columns: 1fr;
            gap: 8px;
            padding: 11px 14px 13px;
        }

        .legal-required-note {
            order: -1;
        }

        .legal-required-submit,
        .legal-required-logout {
            width: 100%;
            min-width: 0;
            min-height: 44px;
        }
    }
</style>

<div
    class="legal-required-overlay"
    id="legalRequiredOverlay"
    role="dialog"
    aria-modal="true"
    aria-labelledby="legalRequiredTitle"
>
    <form
        method="POST"
        action="legal.php?obligatorio=1"
        class="legal-required-card"
        id="sidebarLegalAcceptanceForm"
    >
        <input
            type="hidden"
            name="legal_action"
            value="accept"
        >

        <input
            type="hidden"
            name="legal_csrf"
            value="<?php echo legal_h(
                (string) $_SESSION['legal_csrf']
            ); ?>"
        >

        <input
            type="hidden"
            name="return"
            value="<?php echo legal_h(
                $sidebar_legal_return
            ); ?>"
        >

        <header>
            <div class="legal-required-header">
                <div class="legal-required-heading">
                    <div class="legal-required-header-icon">
                        <i class="fas fa-shield-halved"></i>
                    </div>

                    <div>
                        <span class="legal-required-kicker">
                            Acceso protegido
                        </span>

                        <h2 id="legalRequiredTitle">
                            Aviso de privacidad y términos
                        </h2>

                        <p>
                            Lee los dos documentos dentro de esta ventana.
                            Al llegar al final se habilitarán las casillas
                            de aceptación.
                        </p>
                    </div>
                </div>

                <span class="legal-required-badge">
                    <i class="fas fa-lock"></i>
                    Requerido
                </span>
            </div>

            <nav
                class="legal-document-tabs"
                aria-label="Documentos legales"
            >
                <button
                    type="button"
                    class="legal-document-tab active"
                    id="legalPrivacyTab"
                    data-legal-document-tab="privacy"
                    aria-selected="true"
                    aria-controls="legalPrivacyPanel"
                >
                    <span class="legal-document-tab-main">
                        <span class="legal-document-tab-icon">
                            <i class="fas fa-user-shield"></i>
                        </span>

                        <span class="legal-document-tab-copy">
                            <strong>Aviso de privacidad</strong>
                            <span>
                                Documento 1 de 2 · Versión
                                <?php echo legal_h(
                                    $sidebar_legal_documentos[
                                        'aviso'
                                    ]['version'] ?? LEGAL_AVISO_VERSION
                                ); ?>
                            </span>
                        </span>
                    </span>

                    <span
                        class="legal-document-tab-state"
                        id="legalPrivacyTabState"
                    >
                        <i class="fas fa-book-open"></i>
                    </span>
                </button>

                <button
                    type="button"
                    class="legal-document-tab"
                    id="legalTermsTab"
                    data-legal-document-tab="terms"
                    aria-selected="false"
                    aria-controls="legalTermsPanel"
                >
                    <span class="legal-document-tab-main">
                        <span class="legal-document-tab-icon">
                            <i class="fas fa-file-signature"></i>
                        </span>

                        <span class="legal-document-tab-copy">
                            <strong>Términos y condiciones</strong>
                            <span>
                                Documento 2 de 2 · Versión
                                <?php echo legal_h(
                                    $sidebar_legal_documentos[
                                        'terminos'
                                    ]['version'] ?? LEGAL_TERMINOS_VERSION
                                ); ?>
                            </span>
                        </span>
                    </span>

                    <span
                        class="legal-document-tab-state"
                        id="legalTermsTabState"
                    >
                        <i class="fas fa-book-open"></i>
                    </span>
                </button>
            </nav>

            <div class="legal-reading-progress">
                <span id="legalReadingProgressBar"></span>
            </div>
        </header>

        <div
            class="legal-required-content"
            id="legalReadingArea"
            tabindex="0"
        >
            <p class="legal-required-greeting">
                Hola,
                <strong>
                    <?php echo htmlspecialchars(
                        (string) $user_name,
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>
                </strong>.
                Tu aceptación quedará vinculada a esta cuenta.
            </p>

            <?php if ($sidebar_legal_error !== ''): ?>
                <div class="legal-required-error">
                    <i class="fas fa-circle-exclamation"></i>
                    No fue posible preparar el registro de aceptación.

                    <?php if (
                        in_array(
                            $user_rol,
                            ['admin', 'administrador'],
                            true
                        )
                    ): ?>
                        <br>
                        Detalle:
                        <?php echo legal_h($sidebar_legal_error); ?>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <section
                    class="legal-document-panel active"
                    id="legalPrivacyPanel"
                    data-legal-document-panel="privacy"
                    role="tabpanel"
                    aria-labelledby="legalPrivacyTab"
                >
                    <article class="legal-inline-document">
                        <header class="legal-inline-document-header">
                            <div class="legal-inline-document-icon">
                                <i class="fas fa-user-shield"></i>
                            </div>

                            <div>
                                <h3>Aviso de privacidad</h3>

                                <p>
                                    Tratamiento de datos personales,
                                    finalidades, conservación, seguridad y
                                    derechos.
                                </p>

                                <span class="legal-inline-document-version">
                                    Versión
                                    <?php echo legal_h(
                                        $sidebar_legal_documentos[
                                            'aviso'
                                        ]['version'] ?? LEGAL_AVISO_VERSION
                                    ); ?>
                                </span>
                            </div>
                        </header>

                        <div class="legal-inline-document-body">
                            <?php echo
                                $sidebar_legal_documentos[
                                    'aviso'
                                ]['content'] ?? '';
                            ?>
                        </div>

                        <div
                            class="legal-document-finished"
                            id="legalPrivacyFinished"
                        >
                            <i class="fas fa-circle-check"></i>
                            Aviso de privacidad revisado. Continúa con los
                            términos y condiciones.
                        </div>
                    </article>
                </section>

                <section
                    class="legal-document-panel"
                    id="legalTermsPanel"
                    data-legal-document-panel="terms"
                    role="tabpanel"
                    aria-labelledby="legalTermsTab"
                    hidden
                >
                    <article class="legal-inline-document">
                        <header class="legal-inline-document-header">
                            <div class="legal-inline-document-icon">
                                <i class="fas fa-file-signature"></i>
                            </div>

                            <div>
                                <h3>Términos y condiciones</h3>

                                <p>
                                    Reglas de acceso, confidencialidad,
                                    seguridad y protección contra copia o
                                    plagio.
                                </p>

                                <span class="legal-inline-document-version">
                                    Versión
                                    <?php echo legal_h(
                                        $sidebar_legal_documentos[
                                            'terminos'
                                        ]['version'] ?? LEGAL_TERMINOS_VERSION
                                    ); ?>
                                </span>
                            </div>
                        </header>

                        <div class="legal-inline-document-body">
                            <?php echo
                                $sidebar_legal_documentos[
                                    'terminos'
                                ]['content'] ?? '';
                            ?>
                        </div>

                        <div
                            class="legal-document-finished"
                            id="legalTermsFinished"
                        >
                            <i class="fas fa-circle-check"></i>
                            Términos y condiciones revisados.
                        </div>
                    </article>
                </section>

                <div class="legal-required-warning">
                    <i class="fas fa-copyright"></i>

                    <div>
                        <strong>
                            Protección de la aplicación
                        </strong>

                        El código, la composición original de la interfaz,
                        plantillas, textos, reportes y materiales
                        confidenciales no pueden copiarse ni utilizarse
                        para crear un clon o producto derivado sin
                        autorización.
                    </div>
                </div>

                <div
                    class="legal-read-complete"
                    id="legalReadComplete"
                >
                    <i class="fas fa-circle-check"></i>
                    Ambos documentos fueron revisados. Ya puedes confirmar la aceptación.
                </div>

                <div class="legal-required-checks">
                    <label
                        class="legal-required-check locked"
                        id="legalPrivacyLabel"
                    >
                        <input
                            type="checkbox"
                            name="acepto_aviso"
                            id="sidebarLegalPrivacyCheck"
                            value="1"
                            disabled
                        >

                        <span>
                            <strong>
                                He leído el aviso de privacidad.
                            </strong>

                            <span>
                                Conozco el tratamiento de mis datos y mis
                                derechos.
                            </span>
                        </span>
                    </label>

                    <label
                        class="legal-required-check locked"
                        id="legalTermsLabel"
                    >
                        <input
                            type="checkbox"
                            name="acepto_terminos"
                            id="sidebarLegalTermsCheck"
                            value="1"
                            disabled
                        >

                        <span>
                            <strong>
                                Acepto los términos y condiciones.
                            </strong>

                            <span>
                                Respetaré el uso autorizado, la
                                confidencialidad y la propiedad
                                intelectual.
                            </span>
                        </span>
                    </label>
                </div>
            <?php endif; ?>
        </div>

        <footer class="legal-required-footer">
            <a
                href="logout.php"
                class="legal-required-logout"
            >
                <i class="fas fa-right-from-bracket"></i>
                Cerrar sesión
            </a>

            <span
                class="legal-required-note"
                id="legalReadingStatus"
            >
                <strong>Desplázate hasta el final</strong>
                para habilitar la aceptación.
            </span>

            <button
                type="submit"
                class="legal-required-submit"
                id="sidebarLegalSubmit"
                disabled
            >
                <i class="fas fa-check"></i>
                Aceptar y entrar
            </button>
        </footer>
    </form>
</div>

<div
    class="legal-required-loading"
    id="sidebarLegalLoading"
>
    <div class="legal-required-loading-box">
        <i class="fas fa-spinner fa-spin"></i>
        <strong>Guardando aceptación</strong>
        <span>
            Espera mientras registramos los documentos.
        </span>
    </div>
</div>

<script>
(function () {
    const html = document.documentElement;
    const body = document.body;

    html.classList.add('legal-acceptance-required');
    body.classList.add('legal-acceptance-required');

    const readingArea = document.getElementById(
        'legalReadingArea'
    );

    const progressBar = document.getElementById(
        'legalReadingProgressBar'
    );

    const readingStatus = document.getElementById(
        'legalReadingStatus'
    );

    const readComplete = document.getElementById(
        'legalReadComplete'
    );

    const privacyCheck = document.getElementById(
        'sidebarLegalPrivacyCheck'
    );

    const termsCheck = document.getElementById(
        'sidebarLegalTermsCheck'
    );

    const privacyLabel = document.getElementById(
        'legalPrivacyLabel'
    );

    const termsLabel = document.getElementById(
        'legalTermsLabel'
    );

    const submitButton = document.getElementById(
        'sidebarLegalSubmit'
    );

    const form = document.getElementById(
        'sidebarLegalAcceptanceForm'
    );

    const tabs = {
        privacy: document.getElementById('legalPrivacyTab'),
        terms: document.getElementById('legalTermsTab')
    };

    const panels = {
        privacy: document.getElementById('legalPrivacyPanel'),
        terms: document.getElementById('legalTermsPanel')
    };

    const finishedMessages = {
        privacy: document.getElementById(
            'legalPrivacyFinished'
        ),
        terms: document.getElementById(
            'legalTermsFinished'
        )
    };

    const tabStates = {
        privacy: document.getElementById(
            'legalPrivacyTabState'
        ),
        terms: document.getElementById(
            'legalTermsTabState'
        )
    };

    const readState = {
        privacy: false,
        terms: false
    };

    const scrollState = {
        privacy: 0,
        terms: 0
    };

    let activeDocument = 'privacy';
    let acceptanceUnlocked = false;

    function documentProgress(documentKey) {
        if (readState[documentKey]) {
            return 100;
        }

        if (documentKey !== activeDocument || !readingArea) {
            return 0;
        }

        const maximum =
            readingArea.scrollHeight
            - readingArea.clientHeight;

        if (maximum <= 0) {
            return 100;
        }

        return Math.min(
            100,
            Math.max(
                0,
                (readingArea.scrollTop / maximum) * 100
            )
        );
    }

    function updateOverallProgress() {
        const privacyProgress = documentProgress('privacy');
        const termsProgress = documentProgress('terms');

        const total =
            (privacyProgress + termsProgress) / 2;

        if (progressBar) {
            progressBar.style.width =
                total.toFixed(1) + '%';
        }
    }

    function updateReadingStatus() {
        if (!readingStatus) {
            return;
        }

        if (readState.privacy && readState.terms) {
            readingStatus.innerHTML =
                '<strong>Documentos revisados.</strong> ' +
                'Marca las dos casillas para continuar.';
            return;
        }

        if (!readState.privacy && activeDocument === 'privacy') {
            readingStatus.innerHTML =
                '<strong>Documento 1 de 2.</strong> ' +
                'Lee el aviso hasta el final.';
            return;
        }

        if (readState.privacy && !readState.terms) {
            readingStatus.innerHTML =
                '<strong>Documento 2 de 2.</strong> ' +
                'Lee los términos hasta el final.';
            return;
        }

        readingStatus.innerHTML =
            '<strong>Revisión pendiente.</strong> ' +
            'Debes completar ambos documentos.';
    }

    function markDocumentRead(documentKey) {
        if (readState[documentKey]) {
            return;
        }

        readState[documentKey] = true;
        scrollState[documentKey] = 0;

        const tab = tabs[documentKey];
        const state = tabStates[documentKey];
        const message = finishedMessages[documentKey];

        if (tab) {
            tab.classList.add('completed');
        }

        if (state) {
            state.innerHTML =
                '<i class="fas fa-check"></i>';
        }

        if (message) {
            message.classList.add('show');
        }

        if (documentKey === 'privacy' && !readState.terms) {
            window.setTimeout(function () {
                activateDocument('terms');
            }, 450);
        }

        if (readState.privacy && readState.terms) {
            unlockAcceptance();
        }

        updateOverallProgress();
        updateReadingStatus();
    }

    function unlockAcceptance() {
        if (
            acceptanceUnlocked
            || !privacyCheck
            || !termsCheck
        ) {
            return;
        }

        acceptanceUnlocked = true;

        privacyCheck.disabled = false;
        termsCheck.disabled = false;

        if (privacyLabel) {
            privacyLabel.classList.remove('locked');
        }

        if (termsLabel) {
            termsLabel.classList.remove('locked');
        }

        if (readComplete) {
            readComplete.classList.add('show');
        }

        updateReadingStatus();
    }

    function reachedBottom() {
        if (!readingArea) {
            return false;
        }

        return (
            readingArea.scrollHeight
            <= readingArea.clientHeight + 4
        ) || (
            readingArea.scrollTop
            + readingArea.clientHeight
            >= readingArea.scrollHeight - 18
        );
    }

    function updateActiveDocumentProgress() {
        if (!readingArea) {
            return;
        }

        scrollState[activeDocument] =
            readingArea.scrollTop;

        updateOverallProgress();

        if (reachedBottom()) {
            markDocumentRead(activeDocument);
        }
    }

    function activateDocument(documentKey) {
        if (
            !tabs[documentKey]
            || !panels[documentKey]
        ) {
            return;
        }

        if (readingArea) {
            scrollState[activeDocument] =
                readingArea.scrollTop;
        }

        activeDocument = documentKey;

        Object.keys(tabs).forEach(function (key) {
            const isActive = key === documentKey;

            tabs[key].classList.toggle(
                'active',
                isActive
            );

            tabs[key].setAttribute(
                'aria-selected',
                isActive ? 'true' : 'false'
            );

            panels[key].classList.toggle(
                'active',
                isActive
            );

            panels[key].hidden = !isActive;
        });

        if (readingArea) {
            readingArea.scrollTop =
                scrollState[documentKey] || 0;

            requestAnimationFrame(function () {
                updateActiveDocumentProgress();
                readingArea.focus({
                    preventScroll: true
                });
            });
        }

        updateReadingStatus();
    }

    function syncSubmitButton() {
        if (!submitButton) {
            return;
        }

        submitButton.disabled = !(
            acceptanceUnlocked
            && privacyCheck
            && privacyCheck.checked
            && termsCheck
            && termsCheck.checked
        );
    }

    Object.keys(tabs).forEach(function (key) {
        tabs[key].addEventListener(
            'click',
            function () {
                activateDocument(key);
            }
        );
    });

    if (readingArea) {
        readingArea.addEventListener(
            'scroll',
            updateActiveDocumentProgress,
            { passive: true }
        );

        window.addEventListener(
            'resize',
            updateActiveDocumentProgress
        );

        requestAnimationFrame(function () {
            activateDocument('privacy');
        });
    }

    if (privacyCheck) {
        privacyCheck.addEventListener(
            'change',
            syncSubmitButton
        );
    }

    if (termsCheck) {
        termsCheck.addEventListener(
            'change',
            syncSubmitButton
        );
    }

    if (form) {
        form.addEventListener(
            'submit',
            function (event) {
                if (
                    !acceptanceUnlocked
                    || !privacyCheck
                    || !privacyCheck.checked
                    || !termsCheck
                    || !termsCheck.checked
                ) {
                    event.preventDefault();
                    syncSubmitButton();
                    return;
                }

                submitButton.disabled = true;

                document.getElementById(
                    'sidebarLegalLoading'
                ).classList.add('show');
            }
        );
    }
})();
</script>
<?php endif; ?>

<?php if (is_array($alerta_acceso_denegado)): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const alertaAcceso = <?php echo json_encode(
        $alerta_acceso_denegado,
        JSON_UNESCAPED_UNICODE |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ); ?>;

    if (typeof Swal === 'undefined') {
        alert(alertaAcceso.mensaje || 'No tienes permiso para acceder a este módulo.');
        return;
    }

    Swal.fire({
        icon: 'warning',
        title: alertaAcceso.titulo || 'Acceso restringido',
        html: `
            <div class="swal-gym-access-content">
                <p>${alertaAcceso.mensaje}</p>

                <div class="swal-gym-access-data">
                    <div class="swal-gym-access-row">
                        <span><i class="fas fa-user-shield"></i> Rol actual</span>
                        <strong>${alertaAcceso.rol}</strong>
                    </div>
                    <div class="swal-gym-access-row">
                        <span><i class="fas fa-ban"></i> Módulo solicitado</span>
                        <strong>${alertaAcceso.modulo}</strong>
                    </div>
                </div>

                <p class="swal-gym-access-help">
                    Si necesitas utilizar esta función, solicita autorización a un administrador del sistema.
                </p>
            </div>
        `,
        confirmButtonText: 'Entendido',
        confirmButtonColor: '#2563eb',
        allowOutsideClick: false,
        allowEscapeKey: true,
        buttonsStyling: true,
        customClass: {
            popup: 'swal-gym-popup',
            title: 'swal-gym-title',
            confirmButton: 'swal-gym-confirm'
        }
    });
});
</script>
<?php endif; ?>

<script>
(function() {
    const sidebar = document.getElementById('sidebar');
    const sidebarCollapseBtn = document.getElementById('sidebarCollapseBtn');
    const hamburgerMobile = document.getElementById('hamburgerMobile');
    const mobileOverlay = document.getElementById('mobileOverlay');
    const dragHandle = document.getElementById('dragHandle');
    

    function syncSidebarWidth(width) {
        const numericWidth = parseInt(width, 10);

        if (!Number.isFinite(numericWidth) || numericWidth <= 0) {
            return;
        }

        document.documentElement.style.setProperty('--sidebar-width', numericWidth + 'px');
    }

    function refreshCollapsedLinkTitles() {
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            const text = link.querySelector('.nav-text');
            if (!text) return;

            const label = text.textContent.trim();
            link.setAttribute('aria-label', label);

            if (sidebar.classList.contains('collapsed')) {
                link.setAttribute('title', label);
            } else {
                link.removeAttribute('title');
            }
        });
    }

    let isCollapsed = false;
    let isDragging = false;
    let startX = 0;
    let startWidth = 0;
    let savedWidth = 280;
    
    function toggleCollapse() {
        if (window.innerWidth <= 768) return;
        
        if (isCollapsed) {
            sidebar.classList.remove('collapsed');
            document.body.classList.remove('sidebar-collapsed');
            
            const storedWidth = localStorage.getItem('sidebarWidth');
            if (storedWidth && storedWidth > 70) {
                sidebar.style.width = storedWidth + 'px';
                syncSidebarWidth(storedWidth);
                savedWidth = storedWidth;
            } else {
                sidebar.style.width = '280px';
                syncSidebarWidth(280);
                savedWidth = 280;
            }
            
            isCollapsed = false;
            localStorage.setItem('sidebarCollapsed', 'false');
            refreshCollapsedLinkTitles();
        } else {
            const currentWidth = sidebar.offsetWidth;
            if (currentWidth > 70) {
                savedWidth = currentWidth;
                localStorage.setItem('sidebarWidth', savedWidth);
            }
            
            sidebar.classList.add('collapsed');
            document.body.classList.add('sidebar-collapsed');
            sidebar.style.width = '78px';
            document.documentElement.style.setProperty('--sidebar-collapsed-width', '78px');
            
            isCollapsed = true;
            localStorage.setItem('sidebarCollapsed', 'true');
            refreshCollapsedLinkTitles();
        }
    }
    
    function initDragResize() {
        if (!dragHandle) return;
        
        dragHandle.addEventListener('mousedown', (e) => {
            if (window.innerWidth <= 768) return;
            if (isCollapsed) return;
            
            isDragging = true;
            startX = e.clientX;
            startWidth = sidebar.offsetWidth;
            
            document.body.style.cursor = 'ew-resize';
            document.body.style.userSelect = 'none';
            e.preventDefault();
        });
        
        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            
            let newWidth = startWidth + (e.clientX - startX);
            newWidth = Math.min(320, Math.max(200, newWidth));
            sidebar.style.width = newWidth + 'px';
            syncSidebarWidth(newWidth);
        });
        
        document.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
                
                if (!isCollapsed && window.innerWidth > 768) {
                    const currentWidth = sidebar.offsetWidth;
                    if (currentWidth >= 200 && currentWidth <= 320) {
                        savedWidth = currentWidth;
                        localStorage.setItem('sidebarWidth', savedWidth);
                    }
                }
            }
        });
    }
    
    function toggleMobileSidebar() {
        if (window.innerWidth <= 768) {
            sidebar.classList.toggle('mobile-open');
            mobileOverlay.classList.toggle('active');
            document.body.classList.toggle('sidebar-open');
            hamburgerMobile.setAttribute('aria-expanded', sidebar.classList.contains('mobile-open') ? 'true' : 'false');
        }
    }
    
    function closeMobileSidebar() {
        sidebar.classList.remove('mobile-open');
        mobileOverlay.classList.remove('active');
        document.body.classList.remove('sidebar-open');
        if (hamburgerMobile) hamburgerMobile.setAttribute('aria-expanded', 'false');
    }
    
    function handleResize() {
        if (window.innerWidth <= 768) {
            if (!isCollapsed && document.body.classList.contains('sidebar-collapsed')) {
                document.body.classList.remove('sidebar-collapsed');
            }
            if (sidebar.classList.contains('collapsed')) {
                sidebar.classList.remove('collapsed');
                sidebar.style.width = '';
            }
            closeMobileSidebar();
        } else {
            sidebar.classList.remove('mobile-open');
            mobileOverlay.classList.remove('active');
            document.body.classList.remove('sidebar-open');
            
            const storedCollapsed = localStorage.getItem('sidebarCollapsed');
            const storedWidthVal = localStorage.getItem('sidebarWidth');
            
            if (storedCollapsed === 'true') {
                if (!sidebar.classList.contains('collapsed')) {
                    sidebar.classList.add('collapsed');
                    document.body.classList.add('sidebar-collapsed');
                    sidebar.style.width = '78px';
            document.documentElement.style.setProperty('--sidebar-collapsed-width', '78px');
                    isCollapsed = true;
                }
            } else {
                if (sidebar.classList.contains('collapsed')) {
                    sidebar.classList.remove('collapsed');
                    document.body.classList.remove('sidebar-collapsed');
                }
                if (storedWidthVal && storedWidthVal > 70) {
                    sidebar.style.width = storedWidthVal + 'px';
                    syncSidebarWidth(storedWidthVal);
                    savedWidth = storedWidthVal;
                } else {
                    sidebar.style.width = '280px';
                    syncSidebarWidth(280);
                    savedWidth = 280;
                }
                isCollapsed = false;
            }
        }
    }
    
    const loadInitialState = () => {
        if (window.innerWidth > 768) {
            const storedCollapsed = localStorage.getItem('sidebarCollapsed');
            const storedWidthVal = localStorage.getItem('sidebarWidth');
            
            if (storedCollapsed === 'true') {
                sidebar.classList.add('collapsed');
                document.body.classList.add('sidebar-collapsed');
                sidebar.style.width = '78px';
            document.documentElement.style.setProperty('--sidebar-collapsed-width', '78px');
                isCollapsed = true;
            } else {
                sidebar.classList.remove('collapsed');
                document.body.classList.remove('sidebar-collapsed');
                if (storedWidthVal && storedWidthVal > 70) {
                    sidebar.style.width = storedWidthVal + 'px';
                    syncSidebarWidth(storedWidthVal);
                    savedWidth = storedWidthVal;
                } else {
                    sidebar.style.width = '280px';
                    syncSidebarWidth(280);
                    savedWidth = 280;
                }
                isCollapsed = false;
            }
        }
    };
    
    if (sidebarCollapseBtn) {
        sidebarCollapseBtn.addEventListener('click', toggleCollapse);
    }
    
    if (hamburgerMobile) {
        hamburgerMobile.addEventListener('click', toggleMobileSidebar);
    }
    
    if (mobileOverlay) {
        mobileOverlay.addEventListener('click', closeMobileSidebar);
    }
    
    window.addEventListener('resize', handleResize);
    
    document.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                closeMobileSidebar();
            }
        });
    });
    
    initDragResize();
    loadInitialState();
    refreshCollapsedLinkTitles();
})();
</script>


<script>
(function () {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    const groups = Array.from(
        sidebar.querySelectorAll('.nav-group')
    );

    const toggles = Array.from(
        sidebar.querySelectorAll('.nav-group-toggle')
    );

    function setGroupState(group, open) {
        const toggle = group.querySelector(
            ':scope > .nav-group-toggle'
        );

        group.classList.toggle('open', open);

        if (toggle) {
            toggle.setAttribute(
                'aria-expanded',
                open ? 'true' : 'false'
            );
        }
    }

    function closeAllGroups(exceptGroup = null) {
        groups.forEach(function (group) {
            if (group !== exceptGroup) {
                setGroupState(group, false);
            }
        });
    }

    /*
     * El grupo abierto depende únicamente de la página actual.
     * No se conserva en localStorage porque eso provocaba que,
     * por ejemplo, Inventario siguiera desplegado en el dashboard.
     */
    try {
        localStorage.removeItem('sidebarOpenGroup');
    } catch (error) {
        // El acordeón funciona aunque localStorage esté bloqueado.
    }

    toggles.forEach(function (toggle) {
        const group = toggle.closest('.nav-group');
        const label = toggle.querySelector('.nav-group-label');

        if (!group) return;

        if (label) {
            const groupName = label.textContent.trim();
            toggle.setAttribute('aria-label', groupName);
            toggle.setAttribute('title', groupName);
        }

        toggle.addEventListener('click', function () {
            const willOpen = !group.classList.contains('open');

            closeAllGroups(willOpen ? group : null);
            setGroupState(group, willOpen);
        });
    });

    const activeGroup = sidebar
        .querySelector('.nav-group .nav-link.active')
        ?.closest('.nav-group');

    if (activeGroup) {
        closeAllGroups(activeGroup);
        setGroupState(activeGroup, true);
    } else {
        /*
         * Panel principal, Control de acceso, Aviso y términos,
         * Mi perfil y cualquier enlace independiente deben mostrar
         * todos los grupos cerrados.
         */
        closeAllGroups();
    }

    /*
     * Se cierran inmediatamente al pulsar un enlace independiente.
     * Aunque la navegación tarde un instante, el menú ya no queda
     * visualmente desplegado.
     */
    sidebar.querySelectorAll(
        '.sidebar-menu > .nav-item > .nav-link'
    ).forEach(function (link) {
        link.addEventListener('click', function () {
            closeAllGroups();
        });
    });

    sidebar.querySelectorAll(
        '.nav-submenu .nav-link'
    ).forEach(function (link) {
        const text = link.querySelector('.nav-text');

        if (text) {
            link.setAttribute(
                'title',
                text.textContent.trim()
            );
        }
    });
})();
</script>