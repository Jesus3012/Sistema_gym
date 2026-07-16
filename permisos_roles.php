<?php
// Archivo: permisos_roles.php

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/permisos_helper.php';
require_once __DIR__ . '/config/database.php';

if (!permisos_es_admin((string) ($_SESSION['user_rol'] ?? ''))) {
    header('Location: dashboard.php?error=acceso_denegado');
    exit();
}

$databasePermisos = new Database();
$dbPermisos = $databasePermisos->getConnection();

if ($dbPermisos) {
    $dbPermisos->set_charset('utf8mb4');
}

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

$mensaje = '';
$tipoMensaje = '';
$errorInstalacion = '';

if (!$dbPermisos) {
    $errorInstalacion =
        'No fue posible conectar con la base de datos.';
} elseif (!permisos_tablas_disponibles($dbPermisos)) {
    $errorInstalacion =
        'El módulo todavía no está instalado. Ejecuta sql/migracion_permisos_roles.sql.';
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) ($_POST['accion'] ?? '') === 'guardar'
) {
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
            $seleccionados = isset($_POST['modulos'])
                && is_array($_POST['modulos'])
                    ? $_POST['modulos']
                    : [];

            permisos_guardar_rol(
                $dbPermisos,
                $rolSeleccionado,
                $seleccionados,
                (int) $_SESSION['user_id']
            );

            $_SESSION['permisos_roles_flash'] = [
                'tipo' => 'success',
                'mensaje' =>
                    'Los permisos de '
                    . $rolesConfigurables[$rolSeleccionado]
                    . ' se actualizaron correctamente.',
            ];

            $_SESSION['permisos_roles_csrf'] = bin2hex(
                random_bytes(32)
            );

            header(
                'Location: permisos_roles.php?rol='
                . rawurlencode($rolSeleccionado)
            );
            exit();
        } catch (Throwable $error) {
            error_log(
                '[Permisos por rol] ' . $error->getMessage()
            );

            $mensaje =
                'No fue posible guardar los permisos. '
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

$modulosAsignables = permisos_modulos_asignables(
    $dbPermisos
);
$mapaPermisos = permisos_obtener_mapa_rol(
    $dbPermisos,
    $rolSeleccionado
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

function permisosVistaH(string $valor): string
{
    return htmlspecialchars(
        $valor,
        ENT_QUOTES,
        'UTF-8'
    );
}
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

    <title>Permisos por rol</title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <style>
        :root {
            --rp-blue: #1e3a8a;
            --rp-blue-dark: #14275c;
            --rp-blue-soft: #eef4ff;
            --rp-bg: #f4f6f9;
            --rp-card: #ffffff;
            --rp-text: #1f2937;
            --rp-muted: #64748b;
            --rp-border: #dce4ef;
            --rp-border-soft: #edf1f6;
            --rp-green: #047857;
            --rp-green-soft: #ecfdf5;
            --rp-red: #b91c1c;
            --rp-red-soft: #fef2f2;
            --rp-yellow: #92400e;
            --rp-yellow-soft: #fffbeb;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            max-width: 100%;
            min-height: 100%;
            margin: 0;
            overflow-x: hidden;
        }

        body {
            color: var(--rp-text);
            background: var(--rp-bg);
            font-family:
                "Segoe UI",
                Roboto,
                Arial,
                sans-serif;
        }

        button,
        input,
        a {
            font: inherit;
        }

        .rp-main {
            min-height: 100vh;
            padding: 27px;
        }

        .rp-container {
            width: min(1180px, 100%);
            margin: 0 auto;
        }

        .rp-page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 18px;
        }

        .rp-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 6px;
            color: var(--rp-blue);
            font-size: .68rem;
            font-weight: 850;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .rp-page-header h1 {
            margin: 0 0 6px;
            color: var(--rp-blue-dark);
            font-size: clamp(1.65rem, 4vw, 2.25rem);
            line-height: 1.08;
            letter-spacing: -.035em;
        }

        .rp-page-header p {
            max-width: 700px;
            margin: 0;
            color: var(--rp-muted);
            font-size: .8rem;
            line-height: 1.55;
        }

        .rp-admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            flex: 0 0 auto;
            min-height: 35px;
            padding: 0 12px;
            border: 1px solid #bfdbfe;
            border-radius: 999px;
            color: #1e40af;
            background: #eff6ff;
            font-size: .66rem;
            font-weight: 850;
        }

        .rp-message {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            margin-bottom: 14px;
            padding: 12px 14px;
            border-radius: 11px;
            font-size: .72rem;
            line-height: 1.5;
        }

        .rp-message.success {
            border: 1px solid #a7f3d0;
            color: #065f46;
            background: var(--rp-green-soft);
        }

        .rp-message.error {
            border: 1px solid #fecaca;
            color: #991b1b;
            background: var(--rp-red-soft);
        }

        .rp-card {
            overflow: hidden;
            border: 1px solid var(--rp-border);
            border-radius: 18px;
            background: var(--rp-card);
            box-shadow: 0 12px 32px rgba(15, 23, 42, .065);
        }

        .rp-role-section {
            padding: 17px;
            border-bottom: 1px solid var(--rp-border-soft);
        }

        .rp-section-label {
            display: block;
            margin-bottom: 9px;
            color: var(--rp-muted);
            font-size: .64rem;
            font-weight: 800;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .rp-role-tabs {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 9px;
        }

        .rp-role-tab {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            min-width: 0;
            padding: 12px 13px;
            border: 1px solid var(--rp-border);
            border-radius: 12px;
            color: var(--rp-text);
            background: #f8fafc;
            text-decoration: none;
            transition:
                border-color .18s ease,
                background .18s ease,
                box-shadow .18s ease;
        }

        .rp-role-tab:hover {
            border-color: #a9bddd;
            background: #ffffff;
        }

        .rp-role-tab.active {
            border-color: #90acd7;
            color: var(--rp-blue);
            background: var(--rp-blue-soft);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, .055);
        }

        .rp-role-tab-main {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .rp-role-icon {
            display: grid;
            flex: 0 0 38px;
            width: 38px;
            height: 38px;
            place-items: center;
            border-radius: 10px;
            color: var(--rp-blue);
            background: #ffffff;
            box-shadow: inset 0 0 0 1px #e3eaf4;
        }

        .rp-role-copy {
            min-width: 0;
        }

        .rp-role-copy strong,
        .rp-role-copy span {
            display: block;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .rp-role-copy strong {
            font-size: .76rem;
        }

        .rp-role-copy span {
            margin-top: 3px;
            color: var(--rp-muted);
            font-size: .62rem;
        }

        .rp-role-count {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            flex: 0 0 auto;
            color: var(--rp-muted);
            font-size: .61rem;
            font-weight: 800;
        }

        .rp-info-bar {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            margin: 14px 17px 0;
            padding: 11px 12px;
            border: 1px solid #dbeafe;
            border-radius: 11px;
            color: #1e40af;
            background: #f8fbff;
            font-size: .66rem;
            line-height: 1.48;
        }

        .rp-workspace {
            margin-top: 16px;
        }

        .rp-workspace-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 17px 18px;
            border-bottom: 1px solid var(--rp-border-soft);
        }

        .rp-workspace-title {
            min-width: 0;
        }

        .rp-workspace-title h2 {
            margin: 0 0 4px;
            color: var(--rp-blue-dark);
            font-size: 1rem;
        }

        .rp-workspace-title p {
            margin: 0;
            color: var(--rp-muted);
            font-size: .66rem;
        }

        .rp-live-summary {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 0 0 auto;
        }

        .rp-access-count,
        .rp-dirty-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 31px;
            padding: 0 10px;
            border-radius: 999px;
            font-size: .62rem;
            font-weight: 850;
        }

        .rp-access-count {
            color: #065f46;
            background: var(--rp-green-soft);
        }

        .rp-dirty-status {
            display: none;
            color: var(--rp-yellow);
            background: var(--rp-yellow-soft);
        }

        .rp-dirty-status.show {
            display: inline-flex;
        }

        .rp-tools {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) auto;
            gap: 10px;
            padding: 13px 18px;
            border-bottom: 1px solid var(--rp-border-soft);
            background: #fbfcfe;
        }

        .rp-search {
            position: relative;
            min-width: 0;
        }

        .rp-search i {
            position: absolute;
            top: 50%;
            left: 12px;
            color: #94a3b8;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .rp-search input {
            width: 100%;
            min-height: 39px;
            padding: 0 12px 0 35px;
            border: 1px solid var(--rp-border);
            border-radius: 9px;
            color: var(--rp-text);
            background: #ffffff;
            outline: none;
            font-size: .7rem;
        }

        .rp-search input:focus {
            border-color: #8facd9;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, .07);
        }

        .rp-quick-actions {
            display: flex;
            gap: 7px;
        }

        .rp-light-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 39px;
            padding: 0 11px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            color: #334155;
            background: #ffffff;
            cursor: pointer;
            font-size: .64rem;
            font-weight: 800;
        }

        .rp-light-button:hover {
            border-color: #9fb3d0;
            background: #f8fafc;
        }

        .rp-groups {
            display: grid;
            gap: 17px;
            padding: 18px;
        }

        .rp-group {
            min-width: 0;
        }

        .rp-group.hidden {
            display: none;
        }

        .rp-group-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
        }

        .rp-group-title {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
            color: var(--rp-blue-dark);
            font-size: .73rem;
        }

        .rp-group-count {
            color: var(--rp-muted);
            font-size: .59rem;
        }

        .rp-module-list {
            overflow: hidden;
            border: 1px solid var(--rp-border);
            border-radius: 12px;
            background: #ffffff;
        }

        .rp-module-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 122px;
            align-items: center;
            gap: 14px;
            min-width: 0;
            padding: 12px 13px;
            border-bottom: 1px solid var(--rp-border-soft);
            cursor: pointer;
            transition:
                background .16s ease,
                border-color .16s ease;
        }

        .rp-module-row:last-child {
            border-bottom: 0;
        }

        .rp-module-row:hover {
            background: #fbfdff;
        }

        .rp-module-row.enabled {
            background: #f8fbff;
        }

        .rp-module-row.hidden {
            display: none;
        }

        .rp-module-main {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            min-width: 0;
        }

        .rp-module-icon {
            display: grid;
            flex: 0 0 37px;
            width: 37px;
            height: 37px;
            place-items: center;
            border-radius: 10px;
            color: var(--rp-blue);
            background: var(--rp-blue-soft);
            font-size: .8rem;
        }

        .rp-module-copy {
            min-width: 0;
        }

        .rp-module-copy strong {
            display: block;
            margin-bottom: 3px;
            color: var(--rp-text);
            font-size: .71rem;
        }

        .rp-module-copy p {
            margin: 0;
            color: var(--rp-muted);
            font-size: .61rem;
            line-height: 1.4;
        }

        .rp-module-control {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 9px;
        }

        .rp-state-text {
            min-width: 57px;
            color: var(--rp-red);
            font-size: .59rem;
            font-weight: 850;
            text-align: right;
        }

        .rp-module-row.enabled .rp-state-text {
            color: var(--rp-green);
        }

        .rp-switch {
            position: relative;
            flex: 0 0 39px;
            width: 39px;
            height: 23px;
        }

        .rp-switch input {
            position: absolute;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }

        .rp-switch-track {
            position: absolute;
            inset: 0;
            border-radius: 999px;
            background: #cbd5e1;
            transition: background .18s ease;
        }

        .rp-switch-track::after {
            content: "";
            position: absolute;
            top: 3px;
            left: 3px;
            width: 17px;
            height: 17px;
            border-radius: 50%;
            background: #ffffff;
            box-shadow: 0 1px 4px rgba(15, 23, 42, .24);
            transition: transform .18s ease;
        }

        .rp-switch input:checked + .rp-switch-track {
            background: var(--rp-blue);
        }

        .rp-switch input:checked + .rp-switch-track::after {
            transform: translateX(16px);
        }

        .rp-no-results {
            display: none;
            margin: 0 18px 18px;
            padding: 28px 15px;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            color: var(--rp-muted);
            text-align: center;
            font-size: .7rem;
        }

        .rp-no-results.show {
            display: block;
        }

        .rp-always-on {
            margin: 0 18px 18px;
            padding: 13px;
            border: 1px solid #a7f3d0;
            border-radius: 12px;
            background: var(--rp-green-soft);
        }

        .rp-always-on-title {
            display: flex;
            align-items: center;
            gap: 7px;
            margin: 0 0 9px;
            color: #065f46;
            font-size: .68rem;
        }

        .rp-essential-list {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }

        .rp-essential-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 29px;
            padding: 0 9px;
            border-radius: 999px;
            color: #065f46;
            background: #ffffff;
            font-size: .61rem;
            font-weight: 800;
        }

        .rp-footer {
            position: sticky;
            bottom: 0;
            z-index: 4;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 14px 18px 15px;
            border-top: 1px solid var(--rp-border-soft);
            background: rgba(255, 255, 255, .97);
            box-shadow: 0 -9px 24px rgba(15, 23, 42, .045);
            backdrop-filter: blur(8px);
        }

        .rp-footer-copy {
            max-width: 590px;
            color: var(--rp-muted);
            font-size: .62rem;
            line-height: 1.45;
        }

        .rp-footer-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 0 0 auto;
        }

        .rp-reset-button,
        .rp-save-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 42px;
            padding: 0 15px;
            border-radius: 10px;
            cursor: pointer;
            font-size: .68rem;
            font-weight: 850;
        }

        .rp-reset-button {
            border: 1px solid #cbd5e1;
            color: #475569;
            background: #ffffff;
        }

        .rp-reset-button:disabled {
            cursor: not-allowed;
            opacity: .42;
        }

        .rp-save-button {
            min-width: 185px;
            border: 0;
            color: #ffffff;
            background: var(--rp-blue);
        }

        .rp-save-button:hover:not(:disabled) {
            background: #254a9e;
        }

        .rp-save-button:disabled {
            cursor: not-allowed;
            opacity: .5;
        }

        @media (max-width: 820px) {
            .rp-main {
                padding: 20px;
            }

            .rp-tools {
                grid-template-columns: 1fr;
            }

            .rp-quick-actions {
                width: 100%;
            }

            .rp-light-button {
                flex: 1;
            }
        }

        @media (max-width: 620px) {
            .rp-main {
                padding: 74px 10px 24px;
            }

            .rp-page-header,
            .rp-workspace-header,
            .rp-footer {
                align-items: stretch;
                flex-direction: column;
            }

            .rp-admin-badge {
                width: max-content;
            }

            .rp-role-tabs {
                grid-template-columns: 1fr;
            }

            .rp-live-summary {
                flex-wrap: wrap;
            }

            .rp-module-row {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .rp-module-control {
                justify-content: space-between;
                padding-left: 47px;
            }

            .rp-state-text {
                text-align: left;
            }

            .rp-footer-actions {
                width: 100%;
            }

            .rp-reset-button,
            .rp-save-button {
                flex: 1;
                min-width: 0;
            }
        }

        @media (max-width: 410px) {
            .rp-quick-actions,
            .rp-footer-actions {
                flex-direction: column;
            }

            .rp-light-button,
            .rp-reset-button,
            .rp-save-button {
                width: 100%;
            }
        }
    </style>
</head>

<body>
<?php require __DIR__ . '/includes/sidebar.php'; ?>

<main class="main-content rp-main">
    <div class="rp-container">
        <header class="rp-page-header">
            <div>
                <h1>Control de acceso</h1>

                <p>
                    Selecciona un rol y decide qué módulos puede ver y
                    utilizar.
                </p>
            </div>

            <span class="rp-admin-badge">
                <i class="fas fa-shield-halved"></i>
                Solo administradores
            </span>
        </header>

        <?php if ($mensaje !== ''): ?>
            <div
                class="rp-message <?php echo permisosVistaH(
                    $tipoMensaje
                ); ?>"
            >
                <i class="fas <?php echo
                    $tipoMensaje === 'success'
                        ? 'fa-circle-check'
                        : 'fa-circle-exclamation';
                ?>"></i>

                <span>
                    <?php echo permisosVistaH($mensaje); ?>
                </span>
            </div>
        <?php endif; ?>

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
                <span class="rp-section-label">
                    1. Selecciona el rol
                </span>

                <nav
                    class="rp-role-tabs"
                    aria-label="Roles configurables"
                >
                    <?php foreach (
                        $rolesConfigurables
                        as $claveRol => $nombreRol
                    ): ?>
                        <?php
                        $mapaRolTarjeta =
                            permisos_obtener_mapa_rol(
                                $dbPermisos,
                                $claveRol
                            );

                        $activosRolTarjeta = 0;

                        foreach (
                            $modulosAsignables
                            as $claveModulo => $_modulo
                        ) {
                            if (
                                !empty(
                                    $mapaRolTarjeta[
                                        $claveModulo
                                    ]
                                )
                            ) {
                                $activosRolTarjeta++;
                            }
                        }
                        ?>

                        <a
                            href="permisos_roles.php?rol=<?php echo
                                rawurlencode($claveRol);
                            ?>"
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
                                        Configurar accesos
                                    </span>
                                </span>
                            </span>

                            <span class="rp-role-count">
                                <i class="fas fa-check"></i>
                                <?php echo $activosRolTarjeta; ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <div class="rp-info-bar">
                <i class="fas fa-circle-info"></i>

                <span>
                    El administrador conserva acceso completo.
                    Panel principal, Mi perfil y Aviso y términos siempre
                    estarán disponibles para todos los roles.
                </span>
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
                            2. Accesos de
                            <?php echo permisosVistaH(
                                $rolesConfigurables[
                                    $rolSeleccionado
                                ]
                            ); ?>
                        </h2>

                        <p>
                            Activa solamente los módulos que este rol
                            necesita para trabajar.
                        </p>
                    </div>

                    <div class="rp-live-summary">
                        <span
                            class="rp-access-count"
                            id="accessCount"
                        >
                            <i class="fas fa-circle-check"></i>

                            <span id="activeCount">
                                <?php echo $totalActivos; ?>
                            </span>
                            de
                            <?php echo $totalAsignables; ?>
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
                        $iconosGruposPermisos = [
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

                        $iconoGrupoPermisos =
                            $iconosGruposPermisos[$grupo]
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
                                            $iconoGrupoPermisos
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
                                    $habilitado =
                                        !empty(
                                            $mapaPermisos[$clave]
                                        );

                                    $textoBusqueda = strtolower(
                                        trim(
                                            (string) $modulo[
                                                'nombre'
                                            ]
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
                                                <?php echo
                                                    $habilitado
                                                        ? 'Permitido'
                                                        : 'Bloqueado';
                                                ?>
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

                <div
                    class="rp-no-results"
                    id="noResults"
                >
                    <i class="fas fa-magnifying-glass"></i>
                    <br>
                    No se encontraron módulos con ese nombre.
                </div>

                <section class="rp-always-on">
                    <h3 class="rp-always-on-title">
                        <i class="fas fa-lock"></i>
                        Siempre disponibles
                    </h3>

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
                        Al guardar, el sidebar y el acceso directo por URL
                        se actualizarán para este rol.
                    </span>

                    <div class="rp-footer-actions">
                        <button
                            type="button"
                            class="rp-reset-button"
                            id="resetChanges"
                            disabled
                        >
                            <i class="fas fa-rotate-left"></i>
                            Restaurar
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
                            Guardar permisos
                        </button>
                    </div>
                </footer>
            </form>
        </section>
    </div>
</main>

<script>
(function () {
    const form = document.getElementById('permissionsForm');

    if (!form) {
        return;
    }

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
            checkbox.checked = value;
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
            const groupHasVisibleRows = Array.from(
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
                !groupHasVisibleRows
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

    form.addEventListener(
        'submit',
        function () {
            if (
                saveButton
                && !saveButton.disabled
            ) {
                saveButton.disabled = true;

                saveButton.innerHTML =
                    '<i class="fas fa-spinner fa-spin"></i>'
                    + ' Guardando...';
            }
        }
    );

    refreshRows();
    filterModules();
})();
</script>
</body>
</html>