<?php
// Archivo: permisos_roles.php

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/permisos_helper.php';
require_once __DIR__ . '/includes/sucursal_context.php';
require_once __DIR__ . '/config/database.php';

$rolAdministrador = (string) (
    $_SESSION['user_rol_base']
    ?? $_SESSION['user_rol']
    ?? ''
);

if (!permisos_es_admin($rolAdministrador)) {
    header('Location: dashboard.php?error=acceso_denegado');
    exit();
}

$databasePermisos = new Database();
$dbPermisos = $databasePermisos->getConnection();

if ($dbPermisos instanceof mysqli) {
    $dbPermisos->set_charset('utf8mb4');
}

$usuarioId = (int) ($_SESSION['user_id'] ?? 0);
$sucursalId = (int) ($_SESSION['sucursal_id'] ?? 0);
$sucursalNombre = trim((string) (
    $_SESSION['sucursal_nombre'] ?? 'Sucursal'
));
$sucursalClave = trim((string) (
    $_SESSION['sucursal_clave'] ?? ''
));

$vistaSolicitada = strtolower(trim((string) (
    $_POST['vista']
    ?? $_GET['vista']
    ?? ''
)));

if ($dbPermisos instanceof mysqli) {
    try {
        if ($vistaSolicitada === 'global') {
            if (function_exists('sucursal_activar_vista_global')) {
                sucursal_activar_vista_global(
                    $dbPermisos,
                    $usuarioId
                );
            } else {
                $_SESSION['dashboard_vista_global'] = 1;
            }
        } elseif ($vistaSolicitada === 'sucursal') {
            if (function_exists('sucursal_desactivar_vista_global')) {
                sucursal_desactivar_vista_global();
            } else {
                unset($_SESSION['dashboard_vista_global']);
            }
        }
    } catch (Throwable $errorVista) {
        error_log(
            '[Control de acceso vista] '
            . $errorVista->getMessage()
        );
    }
}

$vistaGlobal = function_exists(
    'sucursal_dashboard_vista_global'
)
    ? sucursal_dashboard_vista_global()
    : (
        isset($_SESSION['dashboard_vista_global'])
        && (int) $_SESSION['dashboard_vista_global'] === 1
    );

$rolesConfigurables = permisos_roles_configurables();
$rolSeleccionado = strtolower(
    trim((string) (
        $_POST['rol']
        ?? $_GET['rol']
        ?? 'recepcionista'
    ))
);

if (!array_key_exists($rolSeleccionado, $rolesConfigurables)) {
    $rolSeleccionado = 'recepcionista';
}

if (empty($_SESSION['permisos_roles_csrf'])) {
    $_SESSION['permisos_roles_csrf'] = bin2hex(
        random_bytes(32)
    );
}

function permisosVistaH(string $valor): string
{
    return htmlspecialchars(
        $valor,
        ENT_QUOTES,
        'UTF-8'
    );
}

function permisosVistaUrl(
    string $rol,
    bool $vistaGlobal
): string {
    return 'permisos_roles.php?vista='
        . ($vistaGlobal ? 'global' : 'sucursal')
        . '&rol='
        . rawurlencode($rol);
}

function permisosVistaContarPersonal(
    ?mysqli $db,
    string $rol,
    bool $vistaGlobal,
    int $sucursalId
): int {
    if (!$db) {
        return 0;
    }

    if ($vistaGlobal) {
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT us.usuario_id) AS total
             FROM usuarios_sucursales us
             INNER JOIN usuarios u
               ON u.id = us.usuario_id
             INNER JOIN sucursales s
               ON s.id = us.sucursal_id
             WHERE us.rol_sucursal = ?
               AND us.estado = 'activo'
               AND u.estado = 'activo'
               AND s.estado = 'activa'"
        );

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('s', $rol);
    } else {
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT us.usuario_id) AS total
             FROM usuarios_sucursales us
             INNER JOIN usuarios u
               ON u.id = us.usuario_id
             WHERE us.sucursal_id = ?
               AND us.rol_sucursal = ?
               AND us.estado = 'activo'
               AND u.estado = 'activo'"
        );

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param(
            'is',
            $sucursalId,
            $rol
        );
    }

    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($fila['total'] ?? 0);
}

$mensaje = '';
$tipoMensaje = '';
$errorInstalacion = '';

if (!$dbPermisos instanceof mysqli) {
    $errorInstalacion =
        'No fue posible conectar con la base de datos.';
} elseif (!permisos_tablas_disponibles($dbPermisos)) {
    $errorInstalacion =
        'El módulo base de permisos todavía no está instalado.';
} elseif (!permisos_tablas_disponibles($dbPermisos, true)) {
    $errorInstalacion =
        'Falta instalar la tabla de permisos por sucursal. '
        . 'Ejecuta sql/migracion_permisos_multisucursal.sql.';
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array(
        (string) ($_POST['accion'] ?? ''),
        ['guardar', 'restaurar_global'],
        true
    )
) {
    $accion = (string) ($_POST['accion'] ?? '');
    $csrf = (string) ($_POST['csrf'] ?? '');

    if (
        $csrf === ''
        || !hash_equals(
            (string) $_SESSION['permisos_roles_csrf'],
            $csrf
        )
    ) {
        $mensaje =
            'La sesión del formulario expiró. Actualiza la página.';
        $tipoMensaje = 'error';
    } elseif ($errorInstalacion !== '') {
        $mensaje = $errorInstalacion;
        $tipoMensaje = 'error';
    } else {
        try {
            if ($accion === 'restaurar_global') {
                if ($vistaGlobal) {
                    throw new RuntimeException(
                        'Esta acción solo está disponible dentro de una sucursal.'
                    );
                }

                permisos_restaurar_sucursal_desde_global(
                    $dbPermisos,
                    $sucursalId,
                    $rolSeleccionado,
                    $usuarioId
                );

                $mensajeFlash =
                    'La configuración general se copió a '
                    . $sucursalNombre
                    . ' para el rol '
                    . $rolesConfigurables[$rolSeleccionado]
                    . '.';
            } else {
                $seleccionados = isset($_POST['modulos'])
                    && is_array($_POST['modulos'])
                        ? $_POST['modulos']
                        : [];

                if ($vistaGlobal) {
                    permisos_guardar_rol(
                        $dbPermisos,
                        $rolSeleccionado,
                        $seleccionados,
                        $usuarioId
                    );

                    $mensajeFlash =
                        'Los accesos de '
                        . $rolesConfigurables[$rolSeleccionado]
                        . ' se aplicaron a todas las sucursales.';
                } else {
                    permisos_guardar_rol_sucursal(
                        $dbPermisos,
                        $sucursalId,
                        $rolSeleccionado,
                        $seleccionados,
                        $usuarioId
                    );

                    $mensajeFlash =
                        'Los accesos de '
                        . $rolesConfigurables[$rolSeleccionado]
                        . ' se actualizaron en '
                        . $sucursalNombre
                        . '.';
                }
            }

            $_SESSION['permisos_roles_flash'] = [
                'tipo' => 'success',
                'mensaje' => $mensajeFlash,
            ];

            $_SESSION['permisos_roles_csrf'] = bin2hex(
                random_bytes(32)
            );

            header(
                'Location: '
                . permisosVistaUrl(
                    $rolSeleccionado,
                    $vistaGlobal
                )
            );
            exit();
        } catch (Throwable $error) {
            error_log(
                '[Control de acceso multisucursal] '
                . $error->getMessage()
            );

            $mensaje =
                'No fue posible guardar los accesos. '
                . $error->getMessage();
            $tipoMensaje = 'error';
        }
    }
}

if (isset($_SESSION['permisos_roles_flash'])) {
    $flash = $_SESSION['permisos_roles_flash'];
    unset($_SESSION['permisos_roles_flash']);

    if (is_array($flash)) {
        $mensaje = (string) ($flash['mensaje'] ?? '');
        $tipoMensaje = (string) ($flash['tipo'] ?? 'success');
    }
}

if (
    $dbPermisos instanceof mysqli
    && !$vistaGlobal
    && permisos_tablas_disponibles($dbPermisos, true)
) {
    try {
        permisos_sincronizar_sucursal(
            $dbPermisos,
            $sucursalId
        );
    } catch (Throwable $errorSincronizacion) {
        error_log(
            '[Permisos sincronización] '
            . $errorSincronizacion->getMessage()
        );
    }
}

$modulosAsignables = permisos_modulos_asignables(
    $dbPermisos
);

$mapaPermisos = permisos_obtener_mapa_rol(
    $dbPermisos,
    $rolSeleccionado,
    $vistaGlobal ? null : $sucursalId,
    $vistaGlobal
);

$modulosAgrupados = [];

foreach ($modulosAsignables as $clave => $modulo) {
    $grupo = (string) ($modulo['grupo'] ?? 'Otros');
    $modulosAgrupados[$grupo][$clave] = $modulo;
}

$totalAsignables = count($modulosAsignables);
$totalActivos = 0;

foreach ($modulosAsignables as $clave => $_modulo) {
    if (!empty($mapaPermisos[$clave])) {
        $totalActivos++;
    }
}

$conteosRol = [];

foreach ($rolesConfigurables as $claveRol => $_nombreRol) {
    $conteosRol[$claveRol] = [
        'modulos' => 0,
        'personal' => permisosVistaContarPersonal(
            $dbPermisos,
            $claveRol,
            $vistaGlobal,
            $sucursalId
        ),
    ];

    $mapaTarjeta = permisos_obtener_mapa_rol(
        $dbPermisos,
        $claveRol,
        $vistaGlobal ? null : $sucursalId,
        $vistaGlobal
    );

    foreach ($modulosAsignables as $claveModulo => $_modulo) {
        if (!empty($mapaTarjeta[$claveModulo])) {
            $conteosRol[$claveRol]['modulos']++;
        }
    }
}

$totalSucursales = 0;

if ($dbPermisos instanceof mysqli) {
    $resultadoSucursales = $dbPermisos->query(
        "SELECT COUNT(*) AS total
         FROM sucursales
         WHERE estado = 'activa'"
    );

    if (
        $resultadoSucursales
        && $filaSucursales =
            $resultadoSucursales->fetch_assoc()
    ) {
        $totalSucursales = (int) (
            $filaSucursales['total'] ?? 0
        );
    }
}

$contextoNombre = $vistaGlobal
    ? 'Todas las sucursales'
    : $sucursalNombre;

$contextoDetalle = $vistaGlobal
    ? (
        $totalSucursales === 1
            ? '1 sede activa'
            : $totalSucursales . ' sedes activas'
    )
    : (
        ($sucursalClave !== ''
            ? 'Código ' . $sucursalClave
            : 'Sucursal activa')
        . ' · configuración local'
    );
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta name="theme-color" content="#1e3a8a">

    <title>Control de acceso</title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css"
    >

    <?php
    $permisosCss = __DIR__
        . '/css/permisos_roles.css';
    ?>

    <link
        rel="stylesheet"
        href="css/permisos_roles.css?v=<?php echo is_file($permisosCss)
            ? (int) filemtime($permisosCss)
            : time(); ?>"
    >
</head>

<body class="permisos-page">
<?php require __DIR__ . '/includes/sidebar.php'; ?>

<main class="main-content rp-main">
    <div class="rp-container">
        <header class="rp-page-header">
            <div class="rp-page-heading">
                <span class="rp-page-icon">
                    <i class="fas fa-key"></i>
                </span>

                <div>
                    <span class="rp-eyebrow">
                        Seguridad del equipo
                    </span>

                    <h1>Control de acceso</h1>

                    <p>
                        Define los módulos disponibles para cada función
                        del personal sin mezclar las configuraciones de
                        otras sucursales.
                    </p>
                </div>
            </div>

            <div class="rp-context <?php echo $vistaGlobal
                ? 'global'
                : 'branch'; ?>">
                <span class="rp-context-icon">
                    <i class="fas <?php echo $vistaGlobal
                        ? 'fa-layer-group'
                        : 'fa-building'; ?>"></i>
                </span>

                <span>
                    <small>
                        <?php echo $vistaGlobal
                            ? 'Aplicación general'
                            : 'Aplicación por sede'; ?>
                    </small>

                    <strong>
                        <?php echo permisosVistaH(
                            $contextoNombre
                        ); ?>
                    </strong>

                    <span>
                        <?php echo permisosVistaH(
                            $contextoDetalle
                        ); ?>
                    </span>
                </span>
            </div>
        </header>

        <div class="rp-scope-note <?php echo $vistaGlobal
            ? 'global'
            : 'branch'; ?>">
            <i class="fas <?php echo $vistaGlobal
                ? 'fa-triangle-exclamation'
                : 'fa-circle-info'; ?>"></i>

            <div>
                <strong>
                    <?php echo $vistaGlobal
                        ? 'Cambios para todas las sucursales'
                        : 'Cambios únicamente para esta sucursal'; ?>
                </strong>

                <span>
                    <?php if ($vistaGlobal): ?>
                        Al guardar, la selección reemplazará los accesos
                        actuales del rol en todas las sedes activas.
                    <?php else: ?>
                        Los cambios solo afectarán al personal con este rol
                        en <?php echo permisosVistaH($sucursalNombre); ?>.
                        Las demás sedes conservarán su configuración.
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <?php if ($errorInstalacion !== ''): ?>
            <div class="rp-message error">
                <i class="fas fa-database"></i>

                <span>
                    <?php echo permisosVistaH(
                        $errorInstalacion
                    ); ?>
                </span>
            </div>
        <?php endif; ?>

        <section class="rp-card">
            <div class="rp-role-section">
                <div class="rp-section-heading">
                    <div>
                        <span class="rp-section-label">
                            Selecciona la función del personal
                        </span>

                        <p>
                            Cada función puede tener accesos distintos en
                            el alcance seleccionado.
                        </p>
                    </div>
                </div>

                <nav
                    class="rp-role-tabs"
                    aria-label="Funciones configurables"
                >
                    <?php foreach (
                        $rolesConfigurables
                        as $claveRol => $nombreRol
                    ): ?>
                        <a
                            href="<?php echo permisosVistaH(
                                permisosVistaUrl(
                                    $claveRol,
                                    $vistaGlobal
                                )
                            ); ?>"
                            class="rp-role-tab <?php echo
                                $rolSeleccionado === $claveRol
                                    ? 'active'
                                    : '';
                            ?>"
                            <?php echo
                                $rolSeleccionado === $claveRol
                                    ? 'aria-current="page"'
                                    : '';
                            ?>
                        >
                            <span class="rp-role-tab-main">
                                <span class="rp-role-icon">
                                    <i class="fas <?php echo
                                        $claveRol === 'recepcionista'
                                            ? 'fa-headset'
                                            : 'fa-dumbbell';
                                    ?>"></i>
                                </span>

                                <span class="rp-role-copy">
                                    <strong>
                                        <?php echo permisosVistaH(
                                            $nombreRol
                                        ); ?>
                                    </strong>

                                    <span>
                                        <?php echo (int) (
                                            $conteosRol[$claveRol][
                                                'personal'
                                            ] ?? 0
                                        ); ?>
                                        persona<?php echo
                                            (int) (
                                                $conteosRol[$claveRol][
                                                    'personal'
                                                ] ?? 0
                                            ) === 1
                                                ? ''
                                                : 's';
                                        ?>
                                        en este alcance
                                    </span>
                                </span>
                            </span>

                            <span class="rp-role-count">
                                <strong>
                                    <?php echo (int) (
                                        $conteosRol[$claveRol][
                                            'modulos'
                                        ] ?? 0
                                    ); ?>
                                </strong>

                                <small>módulos</small>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <form
                method="POST"
                class="rp-workspace"
                id="permissionsForm"
            >
                <input
                    type="hidden"
                    name="accion"
                    value="guardar"
                    id="permissionsAction"
                >

                <input
                    type="hidden"
                    name="rol"
                    value="<?php echo permisosVistaH(
                        $rolSeleccionado
                    ); ?>"
                >

                <input
                    type="hidden"
                    name="vista"
                    value="<?php echo $vistaGlobal
                        ? 'global'
                        : 'sucursal'; ?>"
                >

                <input
                    type="hidden"
                    name="csrf"
                    value="<?php echo permisosVistaH(
                        (string) $_SESSION[
                            'permisos_roles_csrf'
                        ]
                    ); ?>"
                >

                <div class="rp-workspace-header">
                    <div class="rp-workspace-title">
                        <h2>
                            Accesos de
                            <?php echo permisosVistaH(
                                $rolesConfigurables[
                                    $rolSeleccionado
                                ]
                            ); ?>
                        </h2>

                        <p>
                            Activa solamente lo que esta función necesita
                            para trabajar en
                            <?php echo permisosVistaH(
                                $contextoNombre
                            ); ?>.
                        </p>
                    </div>

                    <div class="rp-live-summary">
                        <span class="rp-access-count">
                            <i class="fas fa-circle-check"></i>

                            <span id="activeCount">
                                <?php echo $totalActivos; ?>
                            </span>

                            de <?php echo $totalAsignables; ?>
                            permitidos
                        </span>

                        <span
                            class="rp-dirty-status"
                            id="dirtyStatus"
                        >
                            <i class="fas fa-pen"></i>
                            Cambios sin guardar
                        </span>
                    </div>
                </div>

                <div class="rp-tools">
                    <label class="rp-search">
                        <i class="fas fa-magnifying-glass"></i>

                        <input
                            type="search"
                            id="moduleSearch"
                            placeholder="Buscar un módulo..."
                            autocomplete="off"
                        >
                    </label>

                    <div class="rp-quick-actions">
                        <button
                            type="button"
                            class="rp-light-button"
                            id="enableAll"
                        >
                            <i class="fas fa-check-double"></i>
                            Permitir todos
                        </button>

                        <button
                            type="button"
                            class="rp-light-button"
                            id="disableAll"
                        >
                            <i class="fas fa-ban"></i>
                            Bloquear todos
                        </button>
                    </div>
                </div>

                <div class="rp-groups" id="permissionsGroups">
                    <?php foreach (
                        $modulosAgrupados
                        as $grupo => $modulos
                    ): ?>
                        <?php
                        $iconosGrupos = [
                            'Socios' => 'fa-users',
                            'Ventas y caja' =>
                                'fa-cash-register',
                            'Inventario' =>
                                'fa-boxes-stacked',
                            'Clases' =>
                                'fa-calendar-days',
                            'Administración' =>
                                'fa-sliders',
                        ];

                        $iconoGrupo =
                            $iconosGrupos[$grupo]
                            ?? 'fa-folder';
                        ?>

                        <section
                            class="rp-group"
                            data-permission-group
                        >
                            <div class="rp-group-header">
                                <h3 class="rp-group-title">
                                    <i class="fas <?php echo
                                        permisosVistaH(
                                            $iconoGrupo
                                        );
                                    ?>"></i>

                                    <?php echo permisosVistaH(
                                        $grupo
                                    ); ?>
                                </h3>

                                <span class="rp-group-count">
                                    <?php echo count($modulos); ?>
                                    módulo<?php echo
                                        count($modulos) === 1
                                            ? ''
                                            : 's';
                                    ?>
                                </span>
                            </div>

                            <div class="rp-module-list">
                                <?php foreach (
                                    $modulos
                                    as $clave => $modulo
                                ): ?>
                                    <?php
                                    $habilitado = !empty(
                                        $mapaPermisos[$clave]
                                    );

                                    $textoBusqueda = strtolower(
                                        trim(
                                            (string) $modulo['nombre']
                                            . ' '
                                            . (string) $modulo[
                                                'descripcion'
                                            ]
                                            . ' '
                                            . $grupo
                                        )
                                    );
                                    ?>

                                    <label
                                        class="rp-module-row <?php echo
                                            $habilitado
                                                ? 'enabled'
                                                : '';
                                        ?>"
                                        data-module-row
                                        data-search="<?php echo
                                            permisosVistaH(
                                                $textoBusqueda
                                            );
                                        ?>"
                                    >
                                        <span class="rp-module-main">
                                            <span class="rp-module-icon">
                                                <i class="fas <?php echo
                                                    permisosVistaH(
                                                        (string)
                                                        $modulo[
                                                            'icono'
                                                        ]
                                                    );
                                                ?>"></i>
                                            </span>

                                            <span class="rp-module-copy">
                                                <strong>
                                                    <?php echo
                                                        permisosVistaH(
                                                            (string)
                                                            $modulo[
                                                                'nombre'
                                                            ]
                                                        );
                                                    ?>
                                                </strong>

                                                <p>
                                                    <?php echo
                                                        permisosVistaH(
                                                            (string)
                                                            $modulo[
                                                                'descripcion'
                                                            ]
                                                        );
                                                    ?>
                                                </p>
                                            </span>
                                        </span>

                                        <span class="rp-module-control">
                                            <span
                                                class="rp-state-text"
                                                data-state-text
                                            >
                                                <?php echo $habilitado
                                                    ? 'Permitido'
                                                    : 'Bloqueado'; ?>
                                            </span>

                                            <span class="rp-switch">
                                                <input
                                                    type="checkbox"
                                                    name="modulos[]"
                                                    value="<?php echo
                                                        permisosVistaH(
                                                            $clave
                                                        );
                                                    ?>"
                                                    <?php echo
                                                        $habilitado
                                                            ? 'checked'
                                                            : '';
                                                    ?>
                                                    <?php echo
                                                        $errorInstalacion !== ''
                                                            ? 'disabled'
                                                            : '';
                                                    ?>
                                                >

                                                <span
                                                    class="rp-switch-track"
                                                ></span>
                                            </span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>

                <div class="rp-no-results" id="noResults">
                    <i class="fas fa-magnifying-glass"></i>
                    <strong>Sin resultados</strong>
                    <span>
                        No encontramos módulos con ese nombre.
                    </span>
                </div>

                <section class="rp-always-on">
                    <div>
                        <h3 class="rp-always-on-title">
                            <i class="fas fa-lock"></i>
                            Siempre disponibles
                        </h3>

                        <p>
                            Estos accesos protegen la entrada al sistema y
                            no pueden desactivarse desde esta pantalla.
                        </p>
                    </div>

                    <div class="rp-essential-list">
                        <span class="rp-essential-item">
                            <i class="fas fa-house"></i>
                            Panel principal
                        </span>

                        <span class="rp-essential-item">
                            <i class="fas fa-user"></i>
                            Mi perfil
                        </span>

                        <span class="rp-essential-item">
                            <i class="fas fa-shield-halved"></i>
                            Aviso y términos
                        </span>
                    </div>
                </section>

                <footer class="rp-footer">
                    <span class="rp-footer-copy">
                        Los cambios se aplican únicamente al alcance seleccionado y no afectan a otras sucursales.
                    </span>

                    <div class="rp-footer-actions">

                        <button
                            type="button"
                            class="rp-reset-button"
                            id="resetChanges"
                            disabled
                        >
                            <i class="fas fa-rotate-left"></i>
                            Descartar cambios
                        </button>

                        <button
                            type="submit"
                            class="rp-save-button"
                            id="savePermissions"
                            <?php echo
                                $errorInstalacion !== ''
                                    ? 'disabled'
                                    : '';
                            ?>
                        >
                            <i class="fas fa-floppy-disk"></i>
                            Guardar accesos
                        </button>
                    </div>
                </footer>
            </form>
        </section>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
(function () {
    const form = document.getElementById('permissionsForm');

    if (!form) {
        return;
    }

    const isGlobal = <?php echo $vistaGlobal
        ? 'true'
        : 'false'; ?>;

    const contextName = <?php echo json_encode(
        $contextoNombre,
        JSON_HEX_TAG
        | JSON_HEX_APOS
        | JSON_HEX_AMP
        | JSON_HEX_QUOT
    ); ?>;

    const roleName = <?php echo json_encode(
        $rolesConfigurables[$rolSeleccionado],
        JSON_HEX_TAG
        | JSON_HEX_APOS
        | JSON_HEX_AMP
        | JSON_HEX_QUOT
    ); ?>;

    const checkboxes = Array.from(
        form.querySelectorAll(
            'input[name="modulos[]"]'
        )
    );

    const rows = Array.from(
        form.querySelectorAll('[data-module-row]')
    );

    const groups = Array.from(
        form.querySelectorAll('[data-permission-group]')
    );

    const activeCount = document.getElementById(
        'activeCount'
    );

    const dirtyStatus = document.getElementById(
        'dirtyStatus'
    );

    const resetButton = document.getElementById(
        'resetChanges'
    );

    const saveButton = document.getElementById(
        'savePermissions'
    );

    const actionInput = document.getElementById(
        'permissionsAction'
    );

    const copyGlobalButton = document.getElementById(
        'copyGlobalButton'
    );

    const searchInput = document.getElementById(
        'moduleSearch'
    );

    const noResults = document.getElementById(
        'noResults'
    );

    const initialStates = new Map();

    checkboxes.forEach(function (checkbox) {
        initialStates.set(
            checkbox.value,
            checkbox.checked
        );
    });

    function normalizeText(value) {
        return String(value || '')
            .toLocaleLowerCase('es')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();
    }

    function refreshRows() {
        let enabled = 0;
        let changed = false;

        checkboxes.forEach(function (checkbox) {
            const row = checkbox.closest(
                '[data-module-row]'
            );

            if (!row) {
                return;
            }

            const stateText = row.querySelector(
                '[data-state-text]'
            );

            row.classList.toggle(
                'enabled',
                checkbox.checked
            );

            if (stateText) {
                stateText.textContent = checkbox.checked
                    ? 'Permitido'
                    : 'Bloqueado';
            }

            if (checkbox.checked) {
                enabled++;
            }

            if (
                initialStates.get(checkbox.value)
                !== checkbox.checked
            ) {
                changed = true;
            }
        });

        if (activeCount) {
            activeCount.textContent = String(enabled);
        }

        if (dirtyStatus) {
            dirtyStatus.classList.toggle(
                'show',
                changed
            );
        }

        if (resetButton) {
            resetButton.disabled = !changed;
        }
    }

    function setAll(value) {
        checkboxes.forEach(function (checkbox) {
            if (!checkbox.disabled) {
                checkbox.checked = value;
            }
        });

        refreshRows();
    }

    function filterModules() {
        const query = normalizeText(
            searchInput ? searchInput.value : ''
        );

        let visibleRows = 0;

        rows.forEach(function (row) {
            const haystack = normalizeText(
                row.dataset.search
            );

            const visible =
                query === ''
                || haystack.includes(query);

            row.classList.toggle(
                'hidden',
                !visible
            );

            if (visible) {
                visibleRows++;
            }
        });

        groups.forEach(function (group) {
            const hasVisibleRows = Array.from(
                group.querySelectorAll(
                    '[data-module-row]'
                )
            ).some(function (row) {
                return !row.classList.contains(
                    'hidden'
                );
            });

            group.classList.toggle(
                'hidden',
                !hasVisibleRows
            );
        });

        if (noResults) {
            noResults.classList.toggle(
                'show',
                visibleRows === 0
            );
        }
    }

    const enableAll = document.getElementById(
        'enableAll'
    );

    const disableAll = document.getElementById(
        'disableAll'
    );

    if (enableAll) {
        enableAll.addEventListener(
            'click',
            function () {
                setAll(true);
            }
        );
    }

    if (disableAll) {
        disableAll.addEventListener(
            'click',
            function () {
                setAll(false);
            }
        );
    }

    if (resetButton) {
        resetButton.addEventListener(
            'click',
            function () {
                checkboxes.forEach(
                    function (checkbox) {
                        checkbox.checked =
                            initialStates.get(
                                checkbox.value
                            );
                    }
                );

                refreshRows();
            }
        );
    }

    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener(
            'change',
            refreshRows
        );
    });

    if (searchInput) {
        searchInput.addEventListener(
            'input',
            filterModules
        );
    }

    if (copyGlobalButton) {
        copyGlobalButton.addEventListener(
            'click',
            async function () {
                const result = await Swal.fire({
                    icon: 'question',
                    title: 'Copiar configuración general',
                    html:
                        'Se reemplazarán los accesos de <strong>'
                        + roleName
                        + '</strong> únicamente en <strong>'
                        + contextName
                        + '</strong>.',
                    showCancelButton: true,
                    confirmButtonText: 'Sí, copiar',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#1e3a8a'
                });

                if (!result.isConfirmed) {
                    return;
                }

                actionInput.value = 'restaurar_global';
                form.submit();
            }
        );
    }

    form.addEventListener(
        'submit',
        async function (event) {
            if (actionInput.value !== 'guardar') {
                return;
            }

            event.preventDefault();

            const result = await Swal.fire({
                icon: isGlobal ? 'warning' : 'question',
                title: isGlobal
                    ? 'Aplicar a todas las sucursales'
                    : 'Guardar accesos',
                html: isGlobal
                    ? (
                        'La selección de <strong>'
                        + roleName
                        + '</strong> reemplazará los accesos '
                        + 'en todas las sedes activas.'
                    )
                    : (
                        'Se actualizarán los accesos de <strong>'
                        + roleName
                        + '</strong> en <strong>'
                        + contextName
                        + '</strong>.'
                    ),
                showCancelButton: true,
                confirmButtonText: isGlobal
                    ? 'Aplicar a todas'
                    : 'Guardar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#1e3a8a'
            });

            if (!result.isConfirmed) {
                return;
            }

            if (saveButton) {
                saveButton.disabled = true;
                saveButton.innerHTML =
                    '<i class="fas fa-spinner fa-spin"></i>'
                    + ' Guardando...';
            }

            form.submit();
        }
    );

    refreshRows();
    filterModules();

    <?php if ($mensaje !== ''): ?>
    Swal.fire({
        icon: <?php echo json_encode(
            $tipoMensaje === 'success'
                ? 'success'
                : 'error'
        ); ?>,
        title: <?php echo json_encode(
            $tipoMensaje === 'success'
                ? 'Accesos actualizados'
                : 'No se pudo completar'
        ); ?>,
        text: <?php echo json_encode(
            $mensaje,
            JSON_HEX_TAG
            | JSON_HEX_APOS
            | JSON_HEX_AMP
            | JSON_HEX_QUOT
        ); ?>,
        confirmButtonText: 'Entendido',
        confirmButtonColor: '#1e3a8a'
    });
    <?php endif; ?>
})();
</script>
</body>
</html>
