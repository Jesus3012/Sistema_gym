<?php
// Archivo: sucursales.php
// Administración de sedes y terminales Point.

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
$terminalesSucursal = [];

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

            $terminalesSucursal = sucursales_terminales(
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

foreach ($sucursales as $sucursalResumen) {
    if ((string) $sucursalResumen['estado'] === 'activa') {
        $totalActivas++;
    }
}

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
                        Crea nuevas sedes, actualiza sus datos generales y
                        configura las terminales Point de cada ubicación.
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
                class="br-summary-bar"
                aria-label="Resumen de sucursales"
            >
                <div class="br-summary-item">
                    <span class="br-summary-icon blue">
                        <i class="fas fa-building"></i>
                    </span>

                    <div class="br-summary-copy">
                        <span>Sedes registradas</span>
                        <strong><?php echo $totalSucursales; ?></strong>
                    </div>
                </div>

                <span class="br-summary-divider" aria-hidden="true"></span>

                <div class="br-summary-item">
                    <span class="br-summary-icon green">
                        <i class="fas fa-circle-check"></i>
                    </span>

                    <div class="br-summary-copy">
                        <span>Sedes activas</span>
                        <strong><?php echo $totalActivas; ?></strong>
                    </div>
                </div>

                <div class="br-summary-message">
                    <i class="fas fa-circle-info"></i>

                    <span>
                        Las altas y ediciones se administran desde esta
                        pantalla. Personal, planes e inventario permanecen
                        en Configuración.
                    </span>
                </div>
            </section>

            <section class="br-card">
                <header class="br-card-header">
                    <div>
                        <h2>Sucursales registradas</h2>
                        <p>
                            Selecciona una sede para editar sus datos o
                            configurar sus terminales.
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
                    <div
                        class="br-table-head br-table-head-simple"
                        aria-hidden="true"
                    >
                        <span>Sucursal</span>
                        <span>Estado</span>
                        <span></span>
                    </div>

                    <div class="br-branch-list" id="branchList">
                        <?php foreach ($sucursales as $sucursal): ?>
                            <?php
                            $seleccionada =
                                $sucursalSeleccionada !== null
                                && (int) $sucursalSeleccionada['id']
                                    === (int) $sucursal['id'];

                            $esSesion =
                                (int) $sucursal['id']
                                === $sucursalActivaSesion;
                            ?>

                            <article
                                class="br-branch-item br-branch-item-simple <?php echo $seleccionada ? 'active' : ''; ?> <?php echo $sucursal['estado'] === 'inactiva' ? 'inactive' : ''; ?>"
                                data-branch-search="<?php echo sucursalesH(
                                    strtolower(
                                        $sucursal['nombre']
                                        . ' '
                                        . $sucursal['clave']
                                    )
                                ); ?>"
                            >
                                <div class="br-branch-main">
                                    <span class="br-branch-icon">
                                        <i class="fas fa-building"></i>
                                    </span>

                                    <div class="br-branch-copy">
                                        <strong>
                                            <?php echo sucursalesH(
                                                $sucursal['nombre']
                                            ); ?>
                                        </strong>

                                        <span>
                                            Código:
                                            <?php echo sucursalesH(
                                                $sucursal['clave']
                                            ); ?>

                                            <?php if (
                                                (int) $sucursal['es_matriz']
                                                === 1
                                            ): ?>
                                                · Matriz
                                            <?php endif; ?>

                                            <?php if ($esSesion): ?>
                                                · Sucursal actual
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>

                                <div>
                                    <span class="br-mobile-label">
                                        Estado
                                    </span>

                                    <span class="br-badge <?php echo
                                        $sucursal['estado'] === 'activa'
                                            ? 'active'
                                            : 'inactive';
                                    ?>">
                                        <i class="fas <?php echo
                                            $sucursal['estado'] === 'activa'
                                                ? 'fa-circle-check'
                                                : 'fa-circle-pause';
                                        ?>"></i>

                                        <?php echo
                                            $sucursal['estado'] === 'activa'
                                                ? 'Activa'
                                                : 'Inactiva';
                                        ?>
                                    </span>
                                </div>

                                <div class="br-row-action">
                                    <a
                                        class="br-soft-button br-manage-link"
                                        href="sucursales.php?sucursal=<?php echo
                                            (int) $sucursal['id'];
                                        ?>#branch-management"
                                    >
                                        <i class="fas fa-arrow-right"></i>
                                        Administrar
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($sucursalSeleccionada !== null): ?>
                <?php
                $sucursalId = (int) $sucursalSeleccionada['id'];
                $terminalesActivas = count(array_filter(
                    $terminalesSucursal,
                    static function (array $terminal): bool {
                        return (int) ($terminal['activo'] ?? 0) === 1;
                    }
                ));
                ?>

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
                                <?php echo sucursalesH(
                                    $sucursalSeleccionada['nombre']
                                ); ?>
                            </h2>

                            <p>
                                Código interno:
                                <?php echo sucursalesH(
                                    $sucursalSeleccionada['clave']
                                ); ?>

                                ·
                                <?php echo
                                    $sucursalSeleccionada['estado']
                                        === 'activa'
                                            ? 'Sucursal activa'
                                            : 'Sucursal inactiva';
                                ?>

                                <?php if (
                                    $sucursalId === $sucursalActivaSesion
                                ): ?>
                                    · Sucursal actual de tu sesión
                                <?php endif; ?>
                            </p>
                        </div>

                        <div class="br-management-actions">
                            <button
                                type="button"
                                class="br-secondary br-terminal-settings"
                                id="technicalSettingsButton"
                            >
                                <i class="fas fa-credit-card"></i>
                                Terminales
                                <span class="br-action-count">
                                    <?php echo count(
                                        $terminalesSucursal
                                    ); ?>
                                </span>
                            </button>

                            <button
                                type="button"
                                class="<?php echo
                                    $sucursalSeleccionada['estado']
                                        === 'activa'
                                            ? 'br-danger'
                                            : 'br-primary';
                                ?>"
                                id="toggleBranchButton"
                                data-id="<?php echo $sucursalId; ?>"
                                data-current-state="<?php echo
                                    sucursalesH(
                                        $sucursalSeleccionada['estado']
                                    );
                                ?>"
                                <?php echo
                                    (int) $sucursalSeleccionada['es_matriz']
                                        === 1
                                            ? 'disabled title="La matriz no puede desactivarse"'
                                            : '';
                                ?>
                            >
                                <i class="fas <?php echo
                                    $sucursalSeleccionada['estado']
                                        === 'activa'
                                            ? 'fa-circle-pause'
                                            : 'fa-circle-play';
                                ?>"></i>

                                <?php echo
                                    $sucursalSeleccionada['estado']
                                        === 'activa'
                                            ? 'Desactivar'
                                            : 'Activar';
                                ?>
                            </button>
                        </div>
                    </header>

                    <div class="br-management-body">
                        <div class="br-branch-status-bar">
                            <div class="br-branch-status-main">
                                <span class="br-status-dot <?php echo
                                    $sucursalSeleccionada['estado']
                                        === 'activa'
                                            ? 'active'
                                            : 'inactive';
                                ?>"></span>

                                <div>
                                    <span>Estado de la sede</span>

                                    <strong class="<?php echo
                                        $sucursalSeleccionada['estado']
                                            === 'activa'
                                                ? 'is-active'
                                                : 'is-inactive';
                                    ?>">
                                        <?php echo
                                            $sucursalSeleccionada['estado']
                                                === 'activa'
                                                    ? 'Activa'
                                                    : 'Inactiva';
                                        ?>
                                    </strong>
                                </div>
                            </div>

                            <span
                                class="br-status-divider"
                                aria-hidden="true"
                            ></span>

                            <div class="br-branch-status-metric">
                                <span>Terminales</span>

                                <strong>
                                    <?php echo count(
                                        $terminalesSucursal
                                    ); ?>
                                </strong>
                            </div>

                            <span
                                class="br-status-divider"
                                aria-hidden="true"
                            ></span>

                            <div class="br-branch-status-metric">
                                <span>Activas</span>

                                <strong>
                                    <?php echo $terminalesActivas; ?>
                                </strong>
                            </div>

                            <button
                                type="button"
                                class="br-status-terminal-link"
                                id="statusTerminalButton"
                            >
                                <i class="fas fa-credit-card"></i>
                                Administrar terminales
                            </button>
                        </div>

                        <section class="br-section">
                            <header class="br-section-header">
                                <div>
                                    <h3>Datos generales</h3>
                                    <p>
                                        Información de contacto y ubicación
                                        de la sede.
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    class="br-light-edit"
                                    id="editBranchButton"
                                    data-branch='<?php echo sucursalesH(
                                        json_encode(
                                            $sucursalSeleccionada,
                                            JSON_UNESCAPED_UNICODE
                                            | JSON_UNESCAPED_SLASHES
                                        )
                                    ); ?>'
                                >
                                    <i class="fas fa-pen"></i>
                                    Editar información
                                </button>
                            </header>

                            <div class="br-info-grid">
                                <div class="br-info-box">
                                    <span>Código interno</span>
                                    <strong>
                                        <?php echo sucursalesH(
                                            $sucursalSeleccionada['clave']
                                        ); ?>
                                    </strong>
                                </div>

                                <div class="br-info-box">
                                    <span>Zona horaria</span>
                                    <strong>
                                        <?php echo sucursalesH(
                                            $zonasHorarias[
                                                $sucursalSeleccionada[
                                                    'zona_horaria'
                                                ]
                                            ]
                                            ?? $sucursalSeleccionada[
                                                'zona_horaria'
                                            ]
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
                                        <?php echo nl2br(
                                            sucursalesH(
                                                $sucursalSeleccionada[
                                                    'direccion'
                                                ]
                                                ?: 'No registrada'
                                            )
                                        ); ?>
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
                        Registra los datos básicos de la nueva sede.
                        Después podrás configurar sus terminales Point.
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

        function openBranchEditModal() {
            if (!editBranchButton) {
                return;
            }

            var branch = {};

            try {
                branch = JSON.parse(
                    editBranchButton.getAttribute('data-branch')
                    || '{}'
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

            if (byId('branchId')) {
                byId('branchId').value = branch.id || '';
            }

            if (byId('branchKey')) {
                byId('branchKey').value = branch.clave || '';
            }

            if (byId('branchName')) {
                byId('branchName').value = branch.nombre || '';
            }

            if (byId('branchPhone')) {
                byId('branchPhone').value = branch.telefono || '';
            }

            if (byId('branchEmail')) {
                byId('branchEmail').value = branch.email || '';
            }

            if (byId('branchAddress')) {
                byId('branchAddress').value =
                    branch.direccion || '';
            }

            if (byId('branchSchedule')) {
                byId('branchSchedule').value =
                    branch.horario || '';
            }

            if (byId('branchTimezone')) {
                byId('branchTimezone').value =
                    branch.zona_horaria
                    || 'America/Mexico_City';
            }

            openModal('branchModal');
        }

        if (editBranchButton) {
            editBranchButton.addEventListener(
                'click',
                openBranchEditModal
            );
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

        function openTechnicalSettings() {
            openModal('technicalModal');
        }

        if (technicalSettingsButton) {
            technicalSettingsButton.addEventListener(
                'click',
                openTechnicalSettings
            );
        }

        var statusTerminalButton =
            byId('statusTerminalButton');

        if (statusTerminalButton) {
            statusTerminalButton.addEventListener(
                'click',
                openTechnicalSettings
            );
        }

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