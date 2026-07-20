<?php
// Archivo: sucursales.php
// Administración de sedes, personal, planes e integración de terminales.

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/permisos_helper.php';
require_once __DIR__ . '/includes/sucursales_helper.php';
require_once __DIR__ . '/config/database.php';

if (!permisos_es_admin((string) ($_SESSION['user_rol'] ?? ''))) {
    header('Location: dashboard.php?error=acceso_denegado');
    exit();
}

function sucursalesH($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function sucursalesDinero(float $valor): string
{
    return '$' . number_format($valor, 2, '.', ',');
}

$databaseSucursales = new Database();
$dbSucursales = $databaseSucursales->getConnection();

if ($dbSucursales instanceof mysqli) {
    $dbSucursales->set_charset('utf8mb4');
}

if (empty($_SESSION['sucursales_admin_csrf'])) {
    $_SESSION['sucursales_admin_csrf'] = bin2hex(random_bytes(32));
}

$csrfSucursales = (string) $_SESSION['sucursales_admin_csrf'];
$errorInstalacion = '';
$sucursales = [];
$sucursalSeleccionada = null;
$personal = [];
$usuariosDisponibles = [];
$planesSucursal = [];
$terminalesSucursal = [];
$resumenInventario = [
    'productos' => 0,
    'unidades' => 0,
    'bajo_minimo' => 0,
    'valor_compra' => 0.0,
    'valor_venta' => 0.0,
];

if (!$dbSucursales instanceof mysqli) {
    $errorInstalacion = 'No fue posible conectar con la base de datos.';
} elseif (!sucursales_modulo_instalado($dbSucursales)) {
    $errorInstalacion =
        'El módulo todavía no está instalado. Ejecuta primero '
        . 'sql/01_migracion_multisucursal.sql.';
} else {
    try {
        $sucursales = sucursales_listar($dbSucursales);

        $sucursalSolicitada = filter_input(
            INPUT_GET,
            'sucursal',
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!$sucursalSolicitada) {
            $sucursalSolicitada = (int) (
                $_SESSION['sucursal_id'] ?? 0
            );
        }

        if (!$sucursalSolicitada && $sucursales !== []) {
            $sucursalSolicitada = (int) (
                $sucursales[0]['id'] ?? 0
            );
        }

        if ($sucursalSolicitada) {
            $sucursalSeleccionada = sucursales_obtener(
                $dbSucursales,
                (int) $sucursalSolicitada
            );
        }

        if ($sucursalSeleccionada === null && $sucursales !== []) {
            $sucursalSeleccionada = sucursales_obtener(
                $dbSucursales,
                (int) $sucursales[0]['id']
            );
        }

        if ($sucursalSeleccionada !== null) {
            $sucursalIdSeleccionada = (int) (
                $sucursalSeleccionada['id']
            );

            /*
             * Garantiza silenciosamente que los catálogos globales tengan
             * su registro local en la sede. La función solo crea faltantes,
             * por lo que puede ejecutarse cada vez que se abre la sucursal.
             */
            try {
                sucursales_sincronizar_catalogos(
                    $dbSucursales,
                    $sucursalIdSeleccionada
                );
            } catch (Throwable $errorSincronizacion) {
                error_log(
                    '[Sucursales sincronización automática] '
                    . $errorSincronizacion->getMessage()
                );
            }

            $personal = sucursales_personal(
                $dbSucursales,
                $sucursalIdSeleccionada
            );
            $usuariosDisponibles = sucursales_usuarios_disponibles(
                $dbSucursales,
                $sucursalIdSeleccionada
            );
            $planesSucursal = sucursales_planes(
                $dbSucursales,
                $sucursalIdSeleccionada
            );
            $terminalesSucursal = sucursales_terminales(
                $dbSucursales,
                $sucursalIdSeleccionada
            );
            $resumenInventario = sucursales_resumen_inventario(
                $dbSucursales,
                $sucursalIdSeleccionada
            );
        }
    } catch (Throwable $error) {
        error_log('[Vista sucursales] ' . $error->getMessage());
        $errorInstalacion = $error->getMessage();
    }
}

$totalSucursales = count($sucursales);
$totalActivas = 0;
$totalPersonal = 0;
$totalUnidades = 0;

foreach ($sucursales as $sucursalResumen) {
    if ((string) $sucursalResumen['estado'] === 'activa') {
        $totalActivas++;
    }
    $totalPersonal += (int) $sucursalResumen['usuarios_activos'];
    $totalUnidades += (int) $sucursalResumen['unidades_stock'];
}

$rolesSucursal = sucursales_roles();
$zonasHorarias = sucursales_zonas_horarias();
$sucursalActivaSesion = (int) ($_SESSION['sucursal_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1e3a8a">
    <title>Sucursales</title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css"
    >

    <?php
    $sucursalesCss = __DIR__ . '/css/sucursales.css';
    ?>
    <link
        rel="stylesheet"
        href="css/sucursales.css?v=<?php echo is_file($sucursalesCss) ? (int) filemtime($sucursalesCss) : time(); ?>"
    >

</head>
<body class="sucursales-page">
<?php require __DIR__ . '/includes/sidebar.php'; ?>

<main class="main-content br-main">
    <div class="br-container">
        <header class="br-page-header">
            <div class="br-page-heading">
                <span class="br-page-icon">
                    <i class="fas fa-building"></i>
                </span>

                <div class="br-page-copy">
                    <span class="br-page-kicker">
                        Administración de sedes
                    </span>

                    <h1>Sucursales</h1>

                    <p>
                        Organiza la información, el personal, los planes y el
                        inventario disponible en cada ubicación.
                    </p>
                </div>
            </div>

            <?php if ($errorInstalacion === ''): ?>
                <button
                    type="button"
                    class="br-primary br-new-branch"
                    id="newBranchButton"
                    onclick="if(window.sucursalesOpenModal){window.sucursalesOpenModal('branchModal');}"
                >
                    <i class="fas fa-plus"></i>
                    Nueva sucursal
                </button>
            <?php endif; ?>
        </header>

        <?php if ($errorInstalacion !== ''): ?>
            <div class="br-message">
                <i class="fas fa-database"></i>
                <div><?php echo sucursalesH($errorInstalacion); ?></div>
            </div>
        <?php else: ?>
            <section
                class="br-overview"
                aria-label="Resumen de sucursales"
            >
                <article class="br-overview-card">
                    <span class="br-overview-icon blue">
                        <i class="fas fa-building"></i>
                    </span>

                    <div>
                        <span>Sedes registradas</span>
                        <strong><?php echo $totalSucursales; ?></strong>
                    </div>
                </article>

                <article class="br-overview-card">
                    <span class="br-overview-icon green">
                        <i class="fas fa-circle-check"></i>
                    </span>

                    <div>
                        <span>Sedes activas</span>
                        <strong><?php echo $totalActivas; ?></strong>
                    </div>
                </article>

                <article class="br-overview-card">
                    <span class="br-overview-icon purple">
                        <i class="fas fa-users"></i>
                    </span>

                    <div>
                        <span>Personal asignado</span>
                        <strong><?php echo $totalPersonal; ?></strong>
                    </div>
                </article>

                <article class="br-overview-card">
                    <span class="br-overview-icon orange">
                        <i class="fas fa-boxes-stacked"></i>
                    </span>

                    <div>
                        <span>Unidades en inventario</span>
                        <strong>
                            <?php echo number_format($totalUnidades); ?>
                        </strong>
                    </div>
                </article>
            </section>

            <section class="br-card">
                <header class="br-card-header">
                    <div>
                        <h2>Sucursales registradas</h2>
                        <p>
                            Busca una sede y presiona Abrir sede para revisar su configuración.
                        </p>
                    </div>

                    <div class="br-card-tools">
                        <div class="br-search">
                            <i class="fas fa-search"></i>

                            <input
                                type="search"
                                id="branchSearch"
                                placeholder="Buscar por nombre o código..."
                            >
                        </div>
                    </div>
                </header>

                <?php if ($sucursales === []): ?>
                    <div class="br-empty">
                        <i class="fas fa-building-circle-xmark"></i>
                        Todavía no hay sucursales registradas.
                    </div>
                <?php else: ?>
                    <div class="br-table-head" aria-hidden="true">
                        <span>Sucursal</span>
                        <span>Estado</span>
                        <span>Personal</span>
                        <span>Inventario</span>
                        <span></span>
                    </div>

                    <div class="br-branch-list" id="branchList">
                        <?php foreach ($sucursales as $sucursal): ?>
                            <?php
                            $seleccionada = $sucursalSeleccionada !== null
                                && (int) $sucursalSeleccionada['id'] === (int) $sucursal['id'];

                            $esSesion =
                                (int) $sucursal['id'] === $sucursalActivaSesion;
                            ?>

                            <article
                                class="br-branch-item <?php echo $seleccionada ? 'active' : ''; ?> <?php echo $sucursal['estado'] === 'inactiva' ? 'inactive' : ''; ?>"
                                data-branch-search="<?php echo sucursalesH(strtolower($sucursal['nombre'] . ' ' . $sucursal['clave'])); ?>"
                            >
                                <div class="br-branch-main">
                                    <span class="br-branch-icon">
                                        <i class="fas fa-building"></i>
                                    </span>

                                    <div class="br-branch-copy">
                                        <strong>
                                            <?php echo sucursalesH($sucursal['nombre']); ?>
                                        </strong>

                                        <span>
                                            Código: <?php echo sucursalesH($sucursal['clave']); ?>

                                            <?php if ((int) $sucursal['es_matriz'] === 1): ?>
                                                · Matriz
                                            <?php endif; ?>

                                            <?php if ($esSesion): ?>
                                                · Sucursal actual
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>

                                <div>
                                    <span class="br-mobile-label">Estado</span>

                                    <span class="br-badge <?php echo $sucursal['estado'] === 'activa' ? 'active' : 'inactive'; ?>">
                                        <i class="fas <?php echo $sucursal['estado'] === 'activa' ? 'fa-circle-check' : 'fa-circle-pause'; ?>"></i>
                                        <?php echo $sucursal['estado'] === 'activa' ? 'Activa' : 'Inactiva'; ?>
                                    </span>
                                </div>

                                <div>
                                    <span class="br-mobile-label">Personal</span>

                                    <span class="br-cell-value">
                                        <?php echo (int) $sucursal['usuarios_activos']; ?>
                                        personas
                                    </span>
                                </div>

                                <div>
                                    <span class="br-mobile-label">Inventario</span>

                                    <span class="br-cell-value">
                                        <?php echo number_format((int) $sucursal['unidades_stock']); ?>
                                        unidades
                                    </span>
                                </div>

                                <div class="br-row-action">
                                    <a
                                        class="br-soft-button"
                                        href="sucursales.php?sucursal=<?php echo (int) $sucursal['id']; ?>#branch-management"
                                    >
                                        <i class="fas fa-arrow-right"></i>
                                        Abrir sede
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($sucursalSeleccionada !== null): ?>
                <?php $sucursalId = (int) $sucursalSeleccionada['id']; ?>

                <section
                    class="br-card br-management"
                    id="branch-management"
                >
                    <header class="br-management-header">
                        <div class="br-management-title">
                            <span class="br-management-kicker">
                                Administrando sucursal
                            </span>

                            <h2>
                                <?php echo sucursalesH($sucursalSeleccionada['nombre']); ?>
                            </h2>

                            <p>
                                Código interno: <?php echo sucursalesH($sucursalSeleccionada['clave']); ?>

                                · <?php echo $sucursalSeleccionada['estado'] === 'activa'
                                    ? 'Sucursal activa'
                                    : 'Sucursal inactiva'; ?>

                                <?php if ($sucursalId === $sucursalActivaSesion): ?>
                                    · Es la sucursal actual de tu sesión
                                <?php endif; ?>
                            </p>
                        </div>

                        <div class="br-management-actions">
                            <button
                                type="button"
                                class="br-icon-button br-technical-button"
                                id="technicalSettingsButton"
                                title="Configuración técnica"
                                aria-label="Abrir configuración técnica"
                            >
                                <i class="fas fa-gear"></i>
                            </button>

                            <button
                                type="button"
                                class="br-secondary"
                                id="editBranchButton"
                                data-branch='<?php echo sucursalesH(json_encode($sucursalSeleccionada, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>'
                            >
                                <i class="fas fa-pen"></i>
                                Editar datos
                            </button>

                            <button
                                type="button"
                                class="<?php echo $sucursalSeleccionada['estado'] === 'activa'
                                    ? 'br-danger'
                                    : 'br-primary'; ?>"
                                id="toggleBranchButton"
                                data-id="<?php echo $sucursalId; ?>"
                                data-current-state="<?php echo sucursalesH($sucursalSeleccionada['estado']); ?>"
                                <?php echo (int) $sucursalSeleccionada['es_matriz'] === 1
                                    ? 'disabled title="La matriz no puede desactivarse"'
                                    : ''; ?>
                            >
                                <i class="fas <?php echo $sucursalSeleccionada['estado'] === 'activa'
                                    ? 'fa-circle-pause'
                                    : 'fa-circle-play'; ?>"></i>

                                <?php echo $sucursalSeleccionada['estado'] === 'activa'
                                    ? 'Desactivar'
                                    : 'Activar'; ?>
                            </button>
                        </div>
                    </header>

                    <nav
                        class="br-tabs"
                        aria-label="Configuración de la sucursal"
                    >
                        <button
                            type="button"
                            class="br-tab active"
                            data-tab="general"
                        >
                            <span class="br-tab-icon">
                                <i class="fas fa-building"></i>
                            </span>

                            <span class="br-tab-copy">
                                <strong>Datos generales</strong>
                                <span>Dirección, contacto e inventario</span>
                            </span>
                        </button>

                        <button
                            type="button"
                            class="br-tab"
                            data-tab="personal"
                        >
                            <span class="br-tab-icon">
                                <i class="fas fa-users"></i>
                            </span>

                            <span class="br-tab-copy">
                                <strong>Personal</strong>
                                <span>Usuarios y permisos de la sede</span>
                            </span>
                        </button>

                        <button
                            type="button"
                            class="br-tab"
                            data-tab="planes"
                        >
                            <span class="br-tab-icon">
                                <i class="fas fa-id-card"></i>
                            </span>

                            <span class="br-tab-copy">
                                <strong>Planes</strong>
                                <span>Precios y disponibilidad</span>
                            </span>
                        </button>

                    </nav>

                    <div
                        class="br-tab-panel active"
                        data-panel="general"
                    >
                        <div class="br-panel-intro">
                            <div>
                                <h3>Información general</h3>
                                <p>
                                    Consulta los datos principales y el resumen del inventario.
                                </p>
                            </div>
                        </div>

                        <div class="br-metrics">
                            <article class="br-metric">
                                <span>Productos</span>
                                <strong><?php echo $resumenInventario['productos']; ?></strong>
                            </article>

                            <article class="br-metric">
                                <span>Unidades</span>
                                <strong><?php echo number_format($resumenInventario['unidades']); ?></strong>
                            </article>

                            <article class="br-metric">
                                <span>Bajo mínimo</span>
                                <strong><?php echo $resumenInventario['bajo_minimo']; ?></strong>
                            </article>

                            <article class="br-metric">
                                <span>Valor de compra</span>
                                <strong><?php echo sucursalesDinero((float) $resumenInventario['valor_compra']); ?></strong>
                            </article>

                            <article class="br-metric">
                                <span>Valor de venta</span>
                                <strong><?php echo sucursalesDinero((float) $resumenInventario['valor_venta']); ?></strong>
                            </article>
                        </div>

                        <section class="br-section">
                            <header class="br-section-header">
                                <div>
                                    <h3>Datos de la sucursal</h3>
                                    <p>
                                        Esta información identifica la sede dentro del sistema.
                                    </p>
                                </div>
                            </header>

                            <div class="br-info-grid">
                                <div class="br-info-box">
                                    <span>Código interno</span>
                                    <strong>
                                        <?php echo sucursalesH($sucursalSeleccionada['clave']); ?>
                                    </strong>
                                </div>

                                <div class="br-info-box">
                                    <span>Zona horaria</span>
                                    <strong>
                                        <?php echo sucursalesH(
                                            $zonasHorarias[$sucursalSeleccionada['zona_horaria']]
                                            ?? $sucursalSeleccionada['zona_horaria']
                                        ); ?>
                                    </strong>
                                </div>

                                <div class="br-info-box">
                                    <span>Teléfono</span>
                                    <strong>
                                        <?php echo sucursalesH(
                                            $sucursalSeleccionada['telefono']
                                            ?: 'No registrado'
                                        ); ?>
                                    </strong>
                                </div>

                                <div class="br-info-box">
                                    <span>Correo</span>
                                    <strong>
                                        <?php echo sucursalesH(
                                            $sucursalSeleccionada['email']
                                            ?: 'No registrado'
                                        ); ?>
                                    </strong>
                                </div>

                                <div class="br-info-box full">
                                    <span>Dirección</span>
                                    <strong>
                                        <?php echo nl2br(sucursalesH(
                                            $sucursalSeleccionada['direccion']
                                            ?: 'No registrada'
                                        )); ?>
                                    </strong>
                                </div>

                                <div class="br-info-box full">
                                    <span>Horario</span>
                                    <strong>
                                        <?php echo sucursalesH(
                                            $sucursalSeleccionada['horario']
                                            ?: 'No registrado'
                                        ); ?>
                                    </strong>
                                </div>
                            </div>
                        </section>
                    </div>

                    <div
                        class="br-tab-panel"
                        data-panel="personal"
                    >
                        <div class="br-panel-intro">
                            <div>
                                <h3>Personal con acceso a esta sede</h3>
                                <p>
                                    Aquí decides quién puede trabajar en esta
                                    sucursal y qué función tendrá.
                                </p>
                            </div>

                            <button
                                type="button"
                                class="br-primary"
                                id="newAssignmentButton"
                            >
                                <i class="fas fa-user-plus"></i>
                                Agregar personal
                            </button>
                        </div>

                        <section class="br-section">
                            <div class="br-list">
                                <?php if ($personal === []): ?>
                                    <div class="br-empty">
                                        <i class="fas fa-user-slash"></i>
                                        Esta sucursal todavía no tiene personal asignado.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($personal as $persona): ?>
                                        <?php
                                        $rolPersona = (string) $persona['rol_efectivo'];

                                        $asignacionActiva =
                                            (string) $persona['asignacion_estado']
                                            === 'activo';
                                        ?>

                                        <article class="br-person-row">
                                            <div class="br-person-main">
                                                <span class="br-avatar">
                                                    <?php if (
                                                        !empty($persona['foto_perfil'])
                                                        && is_file(
                                                            __DIR__
                                                            . '/'
                                                            . $persona['foto_perfil']
                                                        )
                                                    ): ?>
                                                        <img
                                                            src="<?php echo sucursalesH($persona['foto_perfil']); ?>"
                                                            alt=""
                                                        >
                                                    <?php else: ?>
                                                        <i class="fas fa-user"></i>
                                                    <?php endif; ?>
                                                </span>

                                                <div class="br-row-copy">
                                                    <strong>
                                                        <?php echo sucursalesH($persona['nombre']); ?>
                                                    </strong>

                                                    <span>
                                                        <?php echo sucursalesH($persona['email']); ?>
                                                        ·
                                                        <?php echo sucursalesH(
                                                            $rolesSucursal[$rolPersona]
                                                            ?? ucfirst($rolPersona)
                                                        ); ?>
                                                    </span>
                                                </div>
                                            </div>

                                            <div class="br-row-actions">
                                                <?php if ((int) $persona['es_principal'] === 1): ?>
                                                    <span class="br-badge default">
                                                        <i class="fas fa-house"></i>
                                                        Principal
                                                    </span>
                                                <?php endif; ?>

                                                <?php if ((int) $persona['puede_operar_caja'] === 1): ?>
                                                    <span class="br-badge">
                                                        <i class="fas fa-cash-register"></i>
                                                        Caja
                                                    </span>
                                                <?php endif; ?>

                                                <span class="br-badge <?php echo $asignacionActiva ? 'active' : 'inactive'; ?>">
                                                    <?php echo $asignacionActiva
                                                        ? 'Activo'
                                                        : 'Inactivo'; ?>
                                                </span>

                                                <button
                                                    type="button"
                                                    class="br-action-text edit-assignment-button"
                                                    data-assignment='<?php echo sucursalesH(json_encode($persona, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>'
                                                >
                                                    <i class="fas fa-pen"></i>
                                                    Editar
                                                </button>

                                                <?php if ($asignacionActiva): ?>
                                                    <button
                                                        type="button"
                                                        class="br-action-text danger remove-assignment-button"
                                                        data-user-id="<?php echo (int) $persona['usuario_id']; ?>"
                                                        data-user-name="<?php echo sucursalesH($persona['nombre']); ?>"
                                                    >
                                                        <i class="fas fa-user-minus"></i>
                                                        Retirar
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>

                    <div
                        class="br-tab-panel"
                        data-panel="planes"
                    >
                        <div class="br-panel-intro">
                            <div>
                                <h3>Planes disponibles</h3>
                                <p>
                                    Define el precio y si cada plan se puede vender en esta sede.
                                </p>
                            </div>
                        </div>

                        <section class="br-section">
                            <div class="br-list">
                                <?php if ($planesSucursal === []): ?>
                                    <div class="br-empty">
                                        <i class="fas fa-id-card"></i>
                                        No hay planes disponibles para esta sucursal.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($planesSucursal as $plan): ?>
                                        <form class="br-plan-row plan-form">
                                            <input
                                                type="hidden"
                                                name="sucursal_id"
                                                value="<?php echo $sucursalId; ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="plan_id"
                                                value="<?php echo (int) $plan['plan_id']; ?>"
                                            >

                                            <div class="br-plan-main">
                                                <span class="br-row-icon">
                                                    <i class="fas fa-id-card"></i>
                                                </span>

                                                <div class="br-row-copy">
                                                    <strong>
                                                        <?php echo sucursalesH($plan['nombre']); ?>
                                                    </strong>

                                                    <span>
                                                        <?php echo (int) $plan['duracion_dias']; ?>
                                                        días · Precio general
                                                        <?php echo sucursalesDinero((float) $plan['precio_catalogo']); ?>
                                                    </span>
                                                </div>
                                            </div>

                                            <div class="br-plan-field">
                                                <label>
                                                    Precio en esta sucursal
                                                </label>

                                                <input
                                                    class="br-plan-price"
                                                    type="number"
                                                    name="precio"
                                                    min="0"
                                                    step="0.01"
                                                    value="<?php echo sucursalesH(number_format((float) $plan['precio_sucursal'], 2, '.', '')); ?>"
                                                >
                                            </div>

                                            <div class="br-plan-field">
                                                <label>Disponibilidad</label>

                                                <select
                                                    class="br-plan-state"
                                                    name="estado"
                                                >
                                                    <option
                                                        value="activo"
                                                        <?php echo $plan['estado_sucursal'] === 'activo' ? 'selected' : ''; ?>
                                                    >
                                                        Disponible
                                                    </option>

                                                    <option
                                                        value="inactivo"
                                                        <?php echo $plan['estado_sucursal'] === 'inactivo' ? 'selected' : ''; ?>
                                                    >
                                                        No disponible
                                                    </option>
                                                </select>
                                            </div>

                                            <button
                                                type="submit"
                                                class="br-primary"
                                            >
                                                <i class="fas fa-check"></i>
                                                Guardar
                                            </button>
                                        </form>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>
                </section>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<?php if ($errorInstalacion === ''): ?>
<div class="br-modal" id="branchModal" aria-hidden="true">
    <div class="br-modal-card" role="dialog" aria-modal="true" aria-labelledby="branchModalTitle">
        <form id="branchForm">
            <input type="hidden" name="sucursal_id" id="branchId" value="">

            <header class="br-modal-header">
                <div>
                    <span class="br-modal-kicker br-modal-kicker-blue">
                        Nueva ubicación
                    </span>

                    <h2 id="branchModalTitle">
                        Registrar una sucursal
                    </h2>

                    <p>
                        Escribe los datos básicos de la sede. El inventario
                        comenzará en cero y tu cuenta quedará asignada
                        automáticamente.
                    </p>
                </div>

                <button
                    type="button"
                    class="br-modal-close"
                    data-close-modal="branchModal"
                    aria-label="Cerrar"
                >
                    <i class="fas fa-xmark"></i>
                </button>
            </header>

            <div class="br-modal-body">
                <div class="br-form-grid">
                    <div class="br-field full">
                        <label for="branchName">
                            Nombre de la sucursal *
                        </label>

                        <input
                            class="br-control"
                            type="text"
                            id="branchName"
                            name="nombre"
                            maxlength="150"
                            placeholder="Ej. Sucursal Norte"
                            autocomplete="organization"
                            required
                        >

                        <small class="br-field-help">
                            Es el nombre que verá el personal al elegir una sede.
                        </small>
                    </div>

                    <div class="br-field full">
                        <label for="branchKey">
                            Código interno de la sucursal *
                        </label>

                        <input
                            class="br-control br-code-control"
                            type="text"
                            id="branchKey"
                            name="clave"
                            maxlength="30"
                            placeholder="Ej. NORTE"
                            autocomplete="off"
                            required
                        >

                        <small class="br-field-help">
                            Es una abreviatura para identificar la sede dentro
                            del sistema. No es una contraseña. Usa algo corto
                            como <strong>NORTE</strong>, <strong>SUR</strong> o
                            <strong>CENTRO</strong>.
                        </small>
                    </div>
                    <div class="br-field">
                        <label for="branchPhone">Teléfono</label>
                        <input class="br-control" type="text" id="branchPhone" name="telefono" maxlength="20">
                    </div>
                    <div class="br-field">
                        <label for="branchEmail">Correo</label>
                        <input class="br-control" type="email" id="branchEmail" name="email" maxlength="120">
                    </div>
                    <div class="br-field full">
                        <label for="branchAddress">Dirección</label>
                        <textarea class="br-control" id="branchAddress" name="direccion"></textarea>
                    </div>
                    <div class="br-field full">
                        <label for="branchSchedule">Horario</label>
                        <input class="br-control" type="text" id="branchSchedule" name="horario" maxlength="255" placeholder="Lunes a domingo de 06:00 a 22:00">
                    </div>
                    <div class="br-field full">
                        <label for="branchTimezone">Zona horaria</label>
                        <select class="br-control" id="branchTimezone" name="zona_horaria">
                            <?php foreach ($zonasHorarias as $zona => $nombreZona): ?>
                                <option value="<?php echo sucursalesH($zona); ?>"><?php echo sucursalesH($nombreZona); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <footer class="br-modal-footer">
                <button
                    type="button"
                    class="br-secondary"
                    data-close-modal="branchModal"
                >
                    Cancelar
                </button>

                <button
                    type="submit"
                    class="br-primary"
                    id="branchSubmitButton"
                >
                    <i class="fas fa-check"></i>
                    Crear sucursal
                </button>
            </footer>
        </form>
    </div>
</div>

<?php if ($sucursalSeleccionada !== null): ?>
<div class="br-modal" id="assignmentModal" aria-hidden="true">
    <div
        class="br-modal-card br-assignment-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="assignmentModalTitle"
    >
        <form id="assignmentForm">
            <input
                type="hidden"
                name="sucursal_id"
                value="<?php echo (int) $sucursalSeleccionada['id']; ?>"
            >

            <header class="br-modal-header">
                <div>
                    <span class="br-modal-kicker br-modal-kicker-blue">
                        Acceso del equipo
                    </span>

                    <h2 id="assignmentModalTitle">
                        Agregar personal
                    </h2>

                    <p>
                        Selecciona una cuenta existente y define lo que podrá
                        hacer únicamente en esta sucursal.
                    </p>
                </div>

                <button
                    type="button"
                    class="br-modal-close"
                    data-close-modal="assignmentModal"
                    aria-label="Cerrar"
                >
                    <i class="fas fa-xmark"></i>
                </button>
            </header>

            <div class="br-modal-body">
                <div class="br-assignment-context">
                    <span class="br-assignment-context-icon">
                        <i class="fas fa-building"></i>
                    </span>

                    <span>
                        <small>Sucursal que recibirá el acceso</small>
                        <strong>
                            <?php echo sucursalesH(
                                $sucursalSeleccionada['nombre']
                            ); ?>
                        </strong>
                    </span>
                </div>

                <div class="br-form-grid">
                    <div class="br-field full">
                        <label for="assignmentUser">
                            Usuario que tendrá acceso *
                        </label>

                        <select
                            class="br-control"
                            id="assignmentUser"
                            name="usuario_id"
                            required
                        >
                            <option value="">
                                Selecciona una cuenta
                            </option>

                            <?php foreach ($usuariosDisponibles as $usuarioDisponible): ?>
                                <option
                                    value="<?php echo (int) $usuarioDisponible['id']; ?>"
                                    data-global-role="<?php echo sucursalesH($usuarioDisponible['rol']); ?>"
                                >
                                    <?php echo sucursalesH(
                                        $usuarioDisponible['nombre']
                                        . ' · '
                                        . $usuarioDisponible['email']
                                    ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <small class="br-field-help">
                            Solo aparecen usuarios que ya tienen una cuenta
                            registrada en el sistema.
                        </small>
                    </div>

                    <div class="br-field">
                        <label for="assignmentRole">
                            Función dentro de esta sede *
                        </label>

                        <select
                            class="br-control"
                            id="assignmentRole"
                            name="rol_sucursal"
                            required
                        >
                            <?php foreach ($rolesSucursal as $rol => $nombreRol): ?>
                                <option value="<?php echo sucursalesH($rol); ?>">
                                    <?php echo sucursalesH($nombreRol); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <small
                            class="br-field-help"
                            id="assignmentRoleHelp"
                        >
                            La función determina los módulos que podrá usar.
                        </small>
                    </div>

                    <div class="br-field">
                        <label for="assignmentState">
                            Acceso a esta sucursal
                        </label>

                        <select
                            class="br-control"
                            id="assignmentState"
                            name="estado"
                        >
                            <option value="activo">
                                Habilitado
                            </option>

                            <option value="inactivo">
                                Suspendido
                            </option>
                        </select>

                        <small class="br-field-help">
                            Suspendido conserva el registro, pero impide
                            seleccionar esta sede.
                        </small>
                    </div>

                    <div class="br-field full">
                        <div class="br-permissions-title">
                            Opciones adicionales
                        </div>

                        <div class="br-checks">
                            <label class="br-check">
                                <input
                                    type="checkbox"
                                    id="assignmentMain"
                                    name="es_principal"
                                    value="1"
                                >

                                <span>
                                    <strong>
                                        Abrir esta sede al iniciar sesión
                                    </strong>

                                    <span>
                                        Márcalo cuando esta sea la ubicación
                                        donde trabaja habitualmente.
                                    </span>
                                </span>
                            </label>

                            <label
                                class="br-check"
                                id="assignmentCashCard"
                            >
                                <input
                                    type="checkbox"
                                    id="assignmentCash"
                                    name="puede_operar_caja"
                                    value="1"
                                >

                                <span>
                                    <strong>
                                        Permitir operaciones de caja
                                    </strong>

                                    <span id="assignmentCashHelp">
                                        Autoriza aperturas, cobros y cierres
                                        únicamente si su función también tiene
                                        acceso al módulo de caja.
                                    </span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="br-modal-footer">
                <button
                    type="button"
                    class="br-secondary"
                    data-close-modal="assignmentModal"
                >
                    Cancelar
                </button>

                <button
                    type="submit"
                    class="br-primary"
                >
                    <i class="fas fa-check"></i>
                    Guardar asignación
                </button>
            </footer>
        </form>
    </div>
</div>


<div class="br-modal" id="technicalModal" aria-hidden="true">
    <div
        class="br-modal-card br-technical-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="technicalModalTitle"
    >
        <header class="br-modal-header">
            <div>
                <span class="br-modal-kicker">Uso administrativo interno</span>
                <h2 id="technicalModalTitle">Configuración técnica</h2>
                <p>
                    Herramientas sensibles de integración para
                    <?php echo sucursalesH($sucursalSeleccionada['nombre']); ?>.
                </p>
            </div>

            <button
                type="button"
                class="br-modal-close"
                data-close-modal="technicalModal"
                aria-label="Cerrar"
            >
                <i class="fas fa-xmark"></i>
            </button>
        </header>

        <div class="br-modal-body">
            <div class="br-technical-warning">
                <i class="fas fa-shield-halved"></i>

                <div>
                    <strong>Configuración restringida</strong>
                    <span>
                        Los identificadores de terminal y las acciones de integración
                        no forman parte de la operación diaria. Modifícalos solo
                        cuando se configure o reemplace un dispositivo Point.
                    </span>
                </div>
            </div>

            <section class="br-technical-section">
                <header class="br-technical-section-header">
                    <div class="br-technical-heading">
                        <span class="br-technical-icon">
                            <i class="fas fa-mobile-screen-button"></i>
                        </span>

                        <div class="br-technical-copy">
                            <h3>Terminales Mercado Pago</h3>

                            <p>
                                <?php echo count($terminalesSucursal); ?>
                                <?php echo count($terminalesSucursal) === 1
                                    ? 'terminal registrada'
                                    : 'terminales registradas'; ?>
                                ·
                                <?php echo count(array_filter(
                                    $terminalesSucursal,
                                    static function ($terminal): bool {
                                        return (int) ($terminal['activo'] ?? 0) === 1;
                                    }
                                )); ?>
                                activas
                            </p>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="br-primary br-terminal-add"
                        id="newTerminalButton"
                    >
                        <i class="fas fa-plus"></i>
                        Registrar terminal
                    </button>
                </header>

                <div class="br-technical-terminal-list">
                    <?php if ($terminalesSucursal === []): ?>
                        <div class="br-empty br-empty-terminal">
                            <span class="br-empty-terminal-icon">
                                <i class="fas fa-credit-card"></i>
                            </span>

                            <strong>
                                Sin terminales registradas
                            </strong>

                            <span>
                                Registra una terminal Point cuando esta sede
                                comience a realizar cobros con tarjeta.
                            </span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($terminalesSucursal as $terminal): ?>
                            <article class="br-terminal-row">
                                <div class="br-terminal-main">
                                    <span class="br-row-icon">
                                        <i class="fas fa-mobile-screen-button"></i>
                                    </span>

                                    <div class="br-row-copy">
                                        <strong>
                                            <?php echo sucursalesH($terminal['nombre']); ?>
                                        </strong>

                                        <span class="br-terminal-id">
                                            ID técnico:
                                            <?php echo sucursalesH($terminal['terminal_id']); ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="br-row-actions">
                                    <?php if ((int) $terminal['predeterminada'] === 1): ?>
                                        <span class="br-badge default">
                                            Predeterminada
                                        </span>
                                    <?php endif; ?>

                                    <span class="br-badge <?php echo (int) $terminal['activo'] === 1 ? 'active' : 'inactive'; ?>">
                                        <?php echo (int) $terminal['activo'] === 1
                                            ? 'Activa'
                                            : 'Inactiva'; ?>
                                    </span>

                                    <button
                                        type="button"
                                        class="br-action-text edit-terminal-button"
                                        data-terminal='<?php echo sucursalesH(json_encode($terminal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>'
                                    >
                                        <i class="fas fa-pen"></i>
                                        Editar
                                    </button>

                                    <button
                                        type="button"
                                        class="br-action-text toggle-terminal-button"
                                        data-id="<?php echo (int) $terminal['id']; ?>"
                                        data-active="<?php echo (int) $terminal['activo']; ?>"
                                        data-name="<?php echo sucursalesH($terminal['nombre']); ?>"
                                    >
                                        <i class="fas <?php echo (int) $terminal['activo'] === 1
                                            ? 'fa-circle-pause'
                                            : 'fa-circle-play'; ?>"></i>

                                        <?php echo (int) $terminal['activo'] === 1
                                            ? 'Desactivar'
                                            : 'Activar'; ?>
                                    </button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>

<div class="br-modal" id="terminalModal" aria-hidden="true">
    <div class="br-modal-card small" role="dialog" aria-modal="true" aria-labelledby="terminalModalTitle">
        <form id="terminalForm">
            <input type="hidden" name="sucursal_id" value="<?php echo (int) $sucursalSeleccionada['id']; ?>">
            <input type="hidden" name="terminal_registro_id" id="terminalRegistryId" value="">

            <header class="br-modal-header">
                <div>
                    <h2 id="terminalModalTitle">Registrar terminal Point</h2>
                    <p>Este identificador es técnico y debe coincidir exactamente con Mercado Pago.</p>
                </div>
                <button type="button" class="br-modal-close" data-close-modal="terminalModal"><i class="fas fa-xmark"></i></button>
            </header>

            <div class="br-modal-body">
                <div class="br-form-grid">
                    <div class="br-field full">
                        <label for="terminalExternalId">Terminal ID *</label>
                        <input class="br-control" type="text" id="terminalExternalId" name="terminal_id" maxlength="120" required placeholder="NEWLAND_N950__...">
                    </div>
                    <div class="br-field full">
                        <label for="terminalName">Nombre para identificarla *</label>
                        <input class="br-control" type="text" id="terminalName" name="nombre" maxlength="100" required placeholder="Terminal recepción">
                    </div>
                    <div class="br-field full">
                        <div class="br-checks">
                            <label class="br-check">
                                <input type="checkbox" id="terminalDefault" name="predeterminada" value="1">
                                <span><strong>Terminal predeterminada</strong><span>Se seleccionará automáticamente en los cobros de esta sede.</span></span>
                            </label>
                            <label class="br-check">
                                <input type="checkbox" id="terminalActive" name="activo" value="1" checked>
                                <span><strong>Terminal activa</strong><span>Permite utilizarla en nuevas operaciones.</span></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="br-modal-footer">
                <button type="button" class="br-secondary" data-close-modal="terminalModal">Cancelar</button>
                <button type="submit" class="br-primary"><i class="fas fa-check"></i>Guardar terminal</button>
            </footer>
        </form>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
(function () {
    'use strict';

    function byId(id) {
        return document.getElementById(id);
    }

    function all(selector, root) {
        return Array.prototype.slice.call(
            (root || document).querySelectorAll(selector)
        );
    }

    function showAlert(options) {
        if (typeof Swal !== 'undefined') {
            return Swal.fire(options);
        }

        window.alert(options.text || options.title || 'Operación completada.');
        return Promise.resolve({ isConfirmed: true });
    }

    function openModal(id) {
        var modal = byId(id);
        if (!modal) {
            return;
        }

        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('br-modal-open');

        window.setTimeout(function () {
            var firstControl = modal.querySelector(
                'input:not([type="hidden"]), select, textarea, button'
            );

            if (firstControl && typeof firstControl.focus === 'function') {
                firstControl.focus({ preventScroll: true });
            }
        }, 60);
    }

    function closeModal(id) {
        var modal = byId(id);
        if (!modal) {
            return;
        }

        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');

        if (!document.querySelector('.br-modal.open')) {
            document.body.classList.remove('br-modal-open');
        }
    }

    window.sucursalesOpenModal = openModal;
    window.sucursalesCloseModal = closeModal;

    document.addEventListener('DOMContentLoaded', function () {
        var csrf = <?php echo json_encode($csrfSucursales, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        var apiUrl = 'api/sucursales.php';
        var selectedBranchId = <?php echo (int) ($sucursalSeleccionada['id'] ?? 0); ?>;

        async function postAction(action, data) {
            var body = new URLSearchParams();
            var entries = data || {};

            body.set('accion', action);
            body.set('csrf', csrf);

            Object.keys(entries).forEach(function (key) {
                var value = entries[key];
                body.set(key, value === null || typeof value === 'undefined' ? '' : String(value));
            });

            var response = await fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString(),
                credentials: 'same-origin'
            });

            var payload;

            try {
                payload = await response.json();
            } catch (error) {
                payload = {
                    ok: false,
                    mensaje: 'El servidor devolvió una respuesta no válida.'
                };
            }

            if (!response.ok || !payload.ok) {
                throw new Error(
                    payload.mensaje || 'No fue posible completar la operación.'
                );
            }

            return payload;
        }

        all('[data-close-modal]').forEach(function (button) {
            button.addEventListener('click', function () {
                closeModal(button.getAttribute('data-close-modal'));
            });
        });

        all('.br-modal').forEach(function (modal) {
            modal.addEventListener('mousedown', function (event) {
                if (event.target === modal) {
                    closeModal(modal.id);
                }
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                var opened = document.querySelector('.br-modal.open');
                if (opened) {
                    closeModal(opened.id);
                }
            }
        });

        var branchSearch = byId('branchSearch');

        if (branchSearch) {
            branchSearch.addEventListener('input', function () {
                var term = branchSearch.value.trim().toLowerCase();

                all('.br-branch-item').forEach(function (item) {
                    var source = item.getAttribute('data-branch-search') || '';
                    item.hidden = term !== '' && source.indexOf(term) === -1;
                });
            });
        }

        all('.br-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                all('.br-tab').forEach(function (item) {
                    item.classList.remove('active');
                });

                all('.br-tab-panel').forEach(function (panel) {
                    panel.classList.remove('active');
                });

                tab.classList.add('active');

                var targetPanel = document.querySelector(
                    '[data-panel="' + tab.getAttribute('data-tab') + '"]'
                );

                if (targetPanel) {
                    targetPanel.classList.add('active');
                }
            });
        });

        var branchForm = byId('branchForm');
        var newBranchButton = byId('newBranchButton');
        var editBranchButton = byId('editBranchButton');
        var branchNameInput = byId('branchName');
        var branchKeyInput = byId('branchKey');
        var branchKeyEdited = false;

        function branchCodeFromName(value) {
            return String(value || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toUpperCase()
                .replace(/[^A-Z0-9]+/g, '_')
                .replace(/^_+|_+$/g, '')
                .slice(0, 30);
        }

        if (branchKeyInput) {
            branchKeyInput.addEventListener(
                'input',
                function () {
                    branchKeyEdited =
                        branchKeyInput.value.trim() !== '';
                }
            );
        }

        if (branchNameInput) {
            branchNameInput.addEventListener(
                'input',
                function () {
                    var editingExisting =
                        byId('branchId')
                        && byId('branchId').value !== '';

                    if (
                        !editingExisting
                        && !branchKeyEdited
                        && branchKeyInput
                    ) {
                        branchKeyInput.value =
                            branchCodeFromName(
                                branchNameInput.value
                            );
                    }
                }
            );
        }

        function resetBranchForm() {
            if (!branchForm) {
                return;
            }

            branchForm.reset();

            if (byId('branchId')) {
                byId('branchId').value = '';
            }

            branchKeyEdited = false;

            if (byId('branchModalTitle')) {
                byId('branchModalTitle').textContent =
                    'Registrar una sucursal';
            }

            if (byId('branchSubmitButton')) {
                byId('branchSubmitButton').innerHTML =
                    '<i class="fas fa-check"></i> Crear sucursal';
            }

            if (byId('branchTimezone')) {
                byId('branchTimezone').value = 'America/Mexico_City';
            }
        }

        if (newBranchButton) {
            newBranchButton.addEventListener('click', function () {
                resetBranchForm();
                openModal('branchModal');
            });
        }

        if (editBranchButton) {
            editBranchButton.addEventListener('click', function () {
                var branch = {};

                try {
                    branch = JSON.parse(
                        editBranchButton.getAttribute('data-branch') || '{}'
                    );
                } catch (error) {
                    branch = {};
                }

                resetBranchForm();

                if (byId('branchModalTitle')) {
                    byId('branchModalTitle').textContent =
                        'Editar datos de la sucursal';
                }

                if (byId('branchSubmitButton')) {
                    byId('branchSubmitButton').innerHTML =
                        '<i class="fas fa-check"></i> Guardar cambios';
                }

                branchKeyEdited = true;

                if (byId('branchId')) byId('branchId').value = branch.id || '';
                if (byId('branchKey')) byId('branchKey').value = branch.clave || '';
                if (byId('branchName')) byId('branchName').value = branch.nombre || '';
                if (byId('branchPhone')) byId('branchPhone').value = branch.telefono || '';
                if (byId('branchEmail')) byId('branchEmail').value = branch.email || '';
                if (byId('branchAddress')) byId('branchAddress').value = branch.direccion || '';
                if (byId('branchSchedule')) byId('branchSchedule').value = branch.horario || '';

                if (byId('branchTimezone')) {
                    byId('branchTimezone').value =
                        branch.zona_horaria || 'America/Mexico_City';
                }

                openModal('branchModal');
            });
        }

        if (branchForm) {
            branchForm.addEventListener('submit', async function (event) {
                event.preventDefault();

                var submit = branchForm.querySelector('[type="submit"]');
                if (submit) submit.disabled = true;

                try {
                    var form = new FormData(branchForm);
                    var data = {};

                    form.forEach(function (value, key) {
                        data[key] = value;
                    });

                    var payload = await postAction(
                        'guardar_sucursal',
                        data
                    );

                    closeModal('branchModal');

                    await showAlert({
                        icon: 'success',
                        title: form.get('sucursal_id')
                            ? 'Sucursal actualizada'
                            : 'Sucursal creada',
                        text: payload.mensaje,
                        confirmButtonColor: '#1e3a8a'
                    });

                    window.location.href =
                        payload.redirect
                        || 'sucursales.php?sucursal=' + payload.sucursal_id;
                } catch (error) {
                    showAlert({
                        icon: 'error',
                        title: 'No se pudo guardar',
                        text: error.message,
                        confirmButtonColor: '#1e3a8a'
                    });
                } finally {
                    if (submit) submit.disabled = false;
                }
            });
        }

        var toggleBranchButton = byId('toggleBranchButton');

        if (toggleBranchButton) {
            toggleBranchButton.addEventListener('click', async function () {
                if (toggleBranchButton.disabled) {
                    return;
                }

                var currentState =
                    toggleBranchButton.getAttribute('data-current-state');

                var nextState =
                    currentState === 'activa' ? 'inactiva' : 'activa';

                var confirmation = await showAlert({
                    icon: 'warning',
                    title: nextState === 'inactiva'
                        ? '¿Desactivar sucursal?'
                        : '¿Activar sucursal?',
                    text: nextState === 'inactiva'
                        ? 'El personal ya no podrá seleccionarla. El historial se conservará.'
                        : 'La sede volverá a estar disponible para el personal asignado.',
                    showCancelButton: true,
                    confirmButtonText: nextState === 'inactiva'
                        ? 'Sí, desactivar'
                        : 'Sí, activar',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor:
                        nextState === 'inactiva' ? '#b91c1c' : '#1e3a8a'
                });

                if (!confirmation.isConfirmed) {
                    return;
                }

                try {
                    var payload = await postAction(
                        'cambiar_estado_sucursal',
                        {
                            sucursal_id:
                                toggleBranchButton.getAttribute('data-id'),
                            estado: nextState
                        }
                    );

                    await showAlert({
                        icon: 'success',
                        title: 'Estado actualizado',
                        text: payload.mensaje,
                        timer: 1500,
                        showConfirmButton: false
                    });

                    window.location.reload();
                } catch (error) {
                    showAlert({
                        icon: 'error',
                        title: 'No se pudo cambiar',
                        text: error.message,
                        confirmButtonColor: '#1e3a8a'
                    });
                }
            });
        }

        var technicalSettingsButton =
            byId('technicalSettingsButton');

        if (technicalSettingsButton) {
            technicalSettingsButton.addEventListener(
                'click',
                function () {
                    openModal('technicalModal');
                }
            );
        }

        var assignmentForm = byId('assignmentForm');
        var assignmentUser = byId('assignmentUser');
        var assignmentRole = byId('assignmentRole');
        var assignmentCash = byId('assignmentCash');
        var assignmentCashCard = byId('assignmentCashCard');
        var assignmentCashHelp = byId('assignmentCashHelp');
        var assignmentRoleHelp = byId('assignmentRoleHelp');
        var newAssignmentButton = byId('newAssignmentButton');
        var assignmentBranchName = <?php echo json_encode(
            (string) $sucursalSeleccionada['nombre'],
            JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        ); ?>;

        function syncAssignmentOptions() {
            if (!assignmentRole) {
                return;
            }

            var role = assignmentRole.value;
            var isTrainer = role === 'entrenador';

            if (assignmentRoleHelp) {
                assignmentRoleHelp.textContent =
                    isTrainer
                        ? 'El entrenador podrá aparecer como instructor en las clases de esta sede.'
                        : (
                            role === 'recepcionista'
                                ? 'El recepcionista atenderá socios y solo operará caja si habilitas el permiso inferior.'
                                : 'El administrador tendrá los accesos definidos para este rol.'
                        );
            }

            if (assignmentCash) {
                assignmentCash.disabled = isTrainer;

                if (isTrainer) {
                    assignmentCash.checked = false;
                }
            }

            if (assignmentCashCard) {
                assignmentCashCard.classList.toggle(
                    'disabled',
                    isTrainer
                );
            }

            if (assignmentCashHelp) {
                assignmentCashHelp.textContent =
                    isTrainer
                        ? 'No aplica para entrenadores.'
                        : 'Autoriza aperturas, cobros y cierres únicamente si su función también tiene acceso al módulo de caja.';
            }
        }

        function resetAssignmentForm() {
            if (!assignmentForm) {
                return;
            }

            assignmentForm.reset();

            if (assignmentUser) {
                assignmentUser.disabled = false;
            }

            if (byId('assignmentModalTitle')) {
                byId('assignmentModalTitle').textContent =
                    'Agregar personal a ' + assignmentBranchName;
            }

            if (byId('assignmentState')) {
                byId('assignmentState').value = 'activo';
            }

            syncAssignmentOptions();
        }

        if (newAssignmentButton) {
            newAssignmentButton.addEventListener('click', function () {
                resetAssignmentForm();
                openModal('assignmentModal');
            });
        }

        if (assignmentUser) {
            assignmentUser.addEventListener('change', function () {
                var option =
                    assignmentUser.options[assignmentUser.selectedIndex];

                if (
                    option
                    && option.getAttribute('data-global-role')
                    && assignmentRole
                ) {
                    assignmentRole.value =
                        option.getAttribute('data-global-role');
                }

                syncAssignmentOptions();
            });
        }

        if (assignmentRole) {
            assignmentRole.addEventListener(
                'change',
                syncAssignmentOptions
            );
        }

        all('.edit-assignment-button').forEach(function (button) {
            button.addEventListener('click', function () {
                var assignment = {};

                try {
                    assignment = JSON.parse(
                        button.getAttribute('data-assignment') || '{}'
                    );
                } catch (error) {
                    assignment = {};
                }

                resetAssignmentForm();

                if (byId('assignmentModalTitle')) {
                    byId('assignmentModalTitle').textContent =
                        'Editar acceso de personal';
                }

                if (assignmentUser) {
                    assignmentUser.value =
                        assignment.usuario_id || '';
                    assignmentUser.disabled = true;
                }

                if (byId('assignmentRole')) {
                    byId('assignmentRole').value =
                        assignment.rol_sucursal
                        || assignment.rol_global
                        || 'recepcionista';
                }

                if (byId('assignmentState')) {
                    byId('assignmentState').value =
                        assignment.asignacion_estado || 'activo';
                }

                if (byId('assignmentMain')) {
                    byId('assignmentMain').checked =
                        Number(assignment.es_principal) === 1;
                }

                if (assignmentCash) {
                    assignmentCash.checked =
                        Number(assignment.puede_operar_caja) === 1;
                }

                syncAssignmentOptions();
                openModal('assignmentModal');
            });
        });

        if (assignmentForm) {
            assignmentForm.addEventListener('submit', async function (event) {
                event.preventDefault();

                var submit =
                    assignmentForm.querySelector('[type="submit"]');

                if (submit) submit.disabled = true;

                var data = {
                    sucursal_id: selectedBranchId,
                    usuario_id: assignmentUser
                        ? assignmentUser.value
                        : '',
                    rol_sucursal: byId('assignmentRole')
                        ? byId('assignmentRole').value
                        : '',
                    estado: byId('assignmentState')
                        ? byId('assignmentState').value
                        : 'activo',
                    es_principal:
                        byId('assignmentMain')
                        && byId('assignmentMain').checked
                            ? 1
                            : 0,
                    puede_operar_caja:
                        byId('assignmentCash')
                        && byId('assignmentCash').checked
                            ? 1
                            : 0
                };

                try {
                    var payload = await postAction(
                        'guardar_asignacion',
                        data
                    );

                    closeModal('assignmentModal');

                    await showAlert({
                        icon: 'success',
                        title: 'Asignación guardada',
                        text: payload.mensaje,
                        timer: 1500,
                        showConfirmButton: false
                    });

                    window.location.reload();
                } catch (error) {
                    showAlert({
                        icon: 'error',
                        title: 'No se pudo guardar',
                        text: error.message,
                        confirmButtonColor: '#1e3a8a'
                    });
                } finally {
                    if (submit) submit.disabled = false;
                }
            });
        }

        all('.remove-assignment-button').forEach(function (button) {
            button.addEventListener('click', async function () {
                var confirmation = await showAlert({
                    icon: 'warning',
                    title: '¿Retirar acceso?',
                    text:
                        (button.getAttribute('data-user-name') || 'El usuario')
                        + ' ya no podrá ingresar a esta sucursal.',
                    showCancelButton: true,
                    confirmButtonText: 'Retirar acceso',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#b91c1c'
                });

                if (!confirmation.isConfirmed) {
                    return;
                }

                try {
                    var payload = await postAction(
                        'desactivar_asignacion',
                        {
                            sucursal_id: selectedBranchId,
                            usuario_id:
                                button.getAttribute('data-user-id')
                        }
                    );

                    await showAlert({
                        icon: 'success',
                        title: 'Acceso retirado',
                        text: payload.mensaje,
                        timer: 1400,
                        showConfirmButton: false
                    });

                    window.location.reload();
                } catch (error) {
                    showAlert({
                        icon: 'error',
                        title: 'No se pudo retirar',
                        text: error.message,
                        confirmButtonColor: '#1e3a8a'
                    });
                }
            });
        });

        all('.plan-form').forEach(function (form) {
            form.addEventListener('submit', async function (event) {
                event.preventDefault();

                var submit = form.querySelector('[type="submit"]');
                if (submit) submit.disabled = true;

                var data = {};
                var formData = new FormData(form);

                formData.forEach(function (value, key) {
                    data[key] = value;
                });

                try {
                    var payload = await postAction(
                        'guardar_plan',
                        data
                    );

                    showAlert({
                        icon: 'success',
                        title: 'Plan actualizado',
                        text: payload.mensaje,
                        timer: 1200,
                        showConfirmButton: false
                    });
                } catch (error) {
                    showAlert({
                        icon: 'error',
                        title: 'No se pudo guardar',
                        text: error.message,
                        confirmButtonColor: '#1e3a8a'
                    });
                } finally {
                    if (submit) submit.disabled = false;
                }
            });
        });

        var terminalForm = byId('terminalForm');
        var newTerminalButton = byId('newTerminalButton');

        function resetTerminalForm() {
            if (!terminalForm) {
                return;
            }

            terminalForm.reset();

            if (byId('terminalRegistryId')) {
                byId('terminalRegistryId').value = '';
            }

            if (byId('terminalModalTitle')) {
                byId('terminalModalTitle').textContent =
                    'Registrar terminal Point';
            }

            if (byId('terminalActive')) {
                byId('terminalActive').checked = true;
            }
        }

        if (newTerminalButton) {
            newTerminalButton.addEventListener('click', function () {
                resetTerminalForm();
                closeModal('technicalModal');
                openModal('terminalModal');
            });
        }

        all('.edit-terminal-button').forEach(function (button) {
            button.addEventListener('click', function () {
                var terminal = {};

                try {
                    terminal = JSON.parse(
                        button.getAttribute('data-terminal') || '{}'
                    );
                } catch (error) {
                    terminal = {};
                }

                resetTerminalForm();

                if (byId('terminalModalTitle')) {
                    byId('terminalModalTitle').textContent =
                        'Editar terminal';
                }

                if (byId('terminalRegistryId')) {
                    byId('terminalRegistryId').value =
                        terminal.id || '';
                }

                if (byId('terminalExternalId')) {
                    byId('terminalExternalId').value =
                        terminal.terminal_id || '';
                }

                if (byId('terminalName')) {
                    byId('terminalName').value =
                        terminal.nombre || '';
                }

                if (byId('terminalDefault')) {
                    byId('terminalDefault').checked =
                        Number(terminal.predeterminada) === 1;
                }

                if (byId('terminalActive')) {
                    byId('terminalActive').checked =
                        Number(terminal.activo) === 1;
                }

                closeModal('technicalModal');
                openModal('terminalModal');
            });
        });

        if (terminalForm) {
            terminalForm.addEventListener('submit', async function (event) {
                event.preventDefault();

                var submit =
                    terminalForm.querySelector('[type="submit"]');

                if (submit) submit.disabled = true;

                var data = {
                    sucursal_id: selectedBranchId,
                    terminal_registro_id: byId('terminalRegistryId')
                        ? byId('terminalRegistryId').value
                        : '',
                    terminal_id: byId('terminalExternalId')
                        ? byId('terminalExternalId').value
                        : '',
                    nombre: byId('terminalName')
                        ? byId('terminalName').value
                        : '',
                    predeterminada:
                        byId('terminalDefault')
                        && byId('terminalDefault').checked
                            ? 1
                            : 0,
                    activo:
                        byId('terminalActive')
                        && byId('terminalActive').checked
                            ? 1
                            : 0
                };

                try {
                    var payload = await postAction(
                        'guardar_terminal',
                        data
                    );

                    closeModal('terminalModal');

                    await showAlert({
                        icon: 'success',
                        title: 'Terminal guardada',
                        text: payload.mensaje,
                        timer: 1400,
                        showConfirmButton: false
                    });

                    window.location.reload();
                } catch (error) {
                    showAlert({
                        icon: 'error',
                        title: 'No se pudo guardar',
                        text: error.message,
                        confirmButtonColor: '#1e3a8a'
                    });
                } finally {
                    if (submit) submit.disabled = false;
                }
            });
        }

        all('.toggle-terminal-button').forEach(function (button) {
            button.addEventListener('click', async function () {
                var nextActive =
                    Number(button.getAttribute('data-active')) === 1
                        ? 0
                        : 1;

                var confirmation = await showAlert({
                    icon: 'question',
                    title: nextActive
                        ? '¿Activar terminal?'
                        : '¿Desactivar terminal?',
                    text: button.getAttribute('data-name') || '',
                    showCancelButton: true,
                    confirmButtonText: nextActive
                        ? 'Activar'
                        : 'Desactivar',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor:
                        nextActive ? '#1e3a8a' : '#b91c1c'
                });

                if (!confirmation.isConfirmed) {
                    return;
                }

                try {
                    var payload = await postAction(
                        'cambiar_estado_terminal',
                        {
                            sucursal_id: selectedBranchId,
                            terminal_registro_id:
                                button.getAttribute('data-id'),
                            activo: nextActive
                        }
                    );

                    await showAlert({
                        icon: 'success',
                        title: 'Terminal actualizada',
                        text: payload.mensaje,
                        timer: 1300,
                        showConfirmButton: false
                    });

                    window.location.reload();
                } catch (error) {
                    showAlert({
                        icon: 'error',
                        title: 'No se pudo actualizar',
                        text: error.message,
                        confirmButtonColor: '#1e3a8a'
                    });
                }
            });
        });
    });
})();
</script>
</body>
</html>