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

        if (!$sucursalSolicitada && $sucursales !== []) {
            $sucursalSolicitada = (int) ($sucursales[0]['id'] ?? 0);
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
            $sucursalIdSeleccionada = (int) $sucursalSeleccionada['id'];
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

    <style>
        :root {
            --br-blue: #1e3a8a;
            --br-blue-dark: #14275c;
            --br-blue-soft: #eef4ff;
            --br-bg: #f4f6f9;
            --br-card: #ffffff;
            --br-text: #1f2937;
            --br-muted: #64748b;
            --br-border: #dce4ef;
            --br-border-soft: #edf1f6;
            --br-green: #047857;
            --br-green-soft: #ecfdf5;
            --br-red: #b91c1c;
            --br-red-soft: #fef2f2;
            --br-yellow: #92400e;
            --br-yellow-soft: #fffbeb;
            --br-shadow: 0 12px 30px rgba(15, 23, 42, .06);
        }

        * { box-sizing: border-box; }

        html,
        body {
            max-width: 100%;
            min-height: 100%;
            margin: 0;
            overflow-x: hidden;
        }

        body {
            color: var(--br-text);
            background: var(--br-bg);
            font-family: "Segoe UI", Roboto, Arial, sans-serif;
        }

        button,
        input,
        select,
        textarea,
        a { font: inherit; }

        .br-main {
            min-height: 100vh;
            padding: 27px;
        }

        .br-container {
            width: min(1240px, 100%);
            margin: 0 auto;
        }

        .br-page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 17px;
        }

        .br-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 6px;
            color: var(--br-blue);
            font-size: .68rem;
            font-weight: 850;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .br-page-header h1 {
            margin: 0 0 6px;
            color: var(--br-blue-dark);
            font-size: clamp(1.65rem, 4vw, 2.25rem);
            line-height: 1.08;
            letter-spacing: -.035em;
        }

        .br-page-header p {
            max-width: 720px;
            margin: 0;
            color: var(--br-muted);
            font-size: .8rem;
            line-height: 1.55;
        }

        .br-primary,
        .br-secondary,
        .br-danger,
        .br-icon-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 40px;
            border-radius: 10px;
            cursor: pointer;
            font-size: .68rem;
            font-weight: 850;
            text-decoration: none;
            transition: background .18s ease, border-color .18s ease, transform .18s ease;
        }

        .br-primary {
            padding: 0 14px;
            border: 0;
            color: #fff;
            background: var(--br-blue);
        }

        .br-primary:hover { background: #254a9e; transform: translateY(-1px); }

        .br-secondary {
            padding: 0 13px;
            border: 1px solid #cbd5e1;
            color: #334155;
            background: #fff;
        }

        .br-secondary:hover { border-color: #9fb3d0; background: #f8fafc; }

        .br-danger {
            padding: 0 13px;
            border: 1px solid #fecaca;
            color: var(--br-red);
            background: #fff7f7;
        }

        .br-danger:hover { border-color: #fca5a5; background: var(--br-red-soft); }

        .br-icon-button {
            width: 36px;
            min-height: 36px;
            padding: 0;
            border: 1px solid var(--br-border);
            color: #475569;
            background: #fff;
        }

        .br-icon-button:hover { color: var(--br-blue); border-color: #9fb3d0; }

        .br-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 17px;
        }

        .br-stat {
            display: flex;
            align-items: center;
            gap: 11px;
            min-width: 0;
            padding: 14px;
            border: 1px solid var(--br-border);
            border-radius: 14px;
            background: #fff;
            box-shadow: var(--br-shadow);
        }

        .br-stat-icon {
            display: grid;
            flex: 0 0 39px;
            width: 39px;
            height: 39px;
            place-items: center;
            border-radius: 11px;
            color: var(--br-blue);
            background: var(--br-blue-soft);
        }

        .br-stat strong,
        .br-stat span { display: block; }
        .br-stat strong { color: var(--br-blue-dark); font-size: 1rem; }
        .br-stat span { margin-top: 2px; color: var(--br-muted); font-size: .61rem; }

        .br-message {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            margin-bottom: 15px;
            padding: 12px 14px;
            border: 1px solid #fecaca;
            border-radius: 12px;
            color: #991b1b;
            background: var(--br-red-soft);
            font-size: .72rem;
            line-height: 1.5;
        }

        .br-layout {
            display: grid;
            grid-template-columns: 300px minmax(0, 1fr);
            gap: 15px;
            align-items: start;
        }

        .br-card {
            min-width: 0;
            overflow: hidden;
            border: 1px solid var(--br-border);
            border-radius: 17px;
            background: #fff;
            box-shadow: var(--br-shadow);
        }

        .br-directory {
            position: sticky;
            top: 16px;
        }

        .br-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 15px 16px;
            border-bottom: 1px solid var(--br-border-soft);
        }

        .br-card-header h2,
        .br-card-header h3 {
            margin: 0;
            color: var(--br-blue-dark);
            font-size: .88rem;
        }

        .br-card-header p {
            margin: 3px 0 0;
            color: var(--br-muted);
            font-size: .61rem;
        }

        .br-search {
            position: relative;
            margin: 12px 13px 5px;
        }

        .br-search i {
            position: absolute;
            top: 50%;
            left: 11px;
            color: #94a3b8;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .br-search input {
            width: 100%;
            min-height: 38px;
            padding: 0 11px 0 34px;
            border: 1px solid var(--br-border);
            border-radius: 9px;
            outline: none;
            color: var(--br-text);
            background: #f8fafc;
            font-size: .68rem;
        }

        .br-search input:focus {
            border-color: #8facd9;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, .07);
        }

        .br-branch-list {
            display: grid;
            gap: 7px;
            max-height: calc(100vh - 285px);
            padding: 8px 9px 12px;
            overflow-y: auto;
            scrollbar-width: thin;
        }

        .br-branch-item {
            display: block;
            min-width: 0;
            padding: 11px;
            border: 1px solid var(--br-border-soft);
            border-radius: 11px;
            color: inherit;
            background: #fff;
            text-decoration: none;
            transition: border-color .18s ease, background .18s ease;
        }

        .br-branch-item:hover { border-color: #a9bddd; background: #fbfdff; }
        .br-branch-item.active { border-color: #8facd9; background: var(--br-blue-soft); }
        .br-branch-item.inactive { opacity: .65; }

        .br-branch-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
        }

        .br-branch-name { min-width: 0; }
        .br-branch-name strong,
        .br-branch-name span { display: block; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        .br-branch-name strong { color: var(--br-blue-dark); font-size: .72rem; }
        .br-branch-name span { margin-top: 3px; color: var(--br-muted); font-size: .57rem; }

        .br-status-dot {
            flex: 0 0 8px;
            width: 8px;
            height: 8px;
            margin-top: 4px;
            border-radius: 50%;
            background: #94a3b8;
        }

        .br-status-dot.active { background: #10b981; }

        .br-branch-tags,
        .br-branch-metrics {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 8px;
        }

        .br-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            min-height: 22px;
            padding: 0 7px;
            border-radius: 999px;
            color: #475569;
            background: #f1f5f9;
            font-size: .53rem;
            font-weight: 800;
        }

        .br-tag.matrix { color: #1e40af; background: #dbeafe; }
        .br-tag.session { color: #065f46; background: var(--br-green-soft); }

        .br-branch-metrics {
            color: var(--br-muted);
            font-size: .54rem;
        }

        .br-detail-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 15px;
            padding: 18px;
            border-bottom: 1px solid var(--br-border-soft);
        }

        .br-detail-title { min-width: 0; }
        .br-detail-title h2 { margin: 0 0 5px; color: var(--br-blue-dark); font-size: 1.15rem; }
        .br-detail-title p { margin: 0; color: var(--br-muted); font-size: .66rem; line-height: 1.45; }

        .br-detail-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 7px;
        }

        .br-tabs {
            display: flex;
            gap: 4px;
            padding: 9px 12px 0;
            overflow-x: auto;
            border-bottom: 1px solid var(--br-border-soft);
            scrollbar-width: none;
        }

        .br-tabs::-webkit-scrollbar { display: none; }

        .br-tab {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            flex: 0 0 auto;
            min-height: 38px;
            padding: 0 11px;
            border: 0;
            border-bottom: 2px solid transparent;
            color: var(--br-muted);
            background: transparent;
            cursor: pointer;
            font-size: .63rem;
            font-weight: 850;
        }

        .br-tab.active { color: var(--br-blue); border-bottom-color: var(--br-blue); }

        .br-tab-panel { display: none; padding: 16px; }
        .br-tab-panel.active { display: block; }

        .br-info-grid,
        .br-mini-stats {
            display: grid;
            gap: 10px;
        }

        .br-info-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .br-mini-stats { grid-template-columns: repeat(5, minmax(0, 1fr)); margin-bottom: 14px; }

        .br-info-box,
        .br-mini-stat {
            min-width: 0;
            padding: 12px;
            border: 1px solid var(--br-border);
            border-radius: 11px;
            background: #fbfcfe;
        }

        .br-info-box.full { grid-column: 1 / -1; }
        .br-info-box span,
        .br-mini-stat span { display: block; color: var(--br-muted); font-size: .56rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; }
        .br-info-box strong,
        .br-mini-stat strong { display: block; margin-top: 5px; overflow-wrap: anywhere; color: var(--br-text); font-size: .7rem; line-height: 1.45; }
        .br-mini-stat strong { color: var(--br-blue-dark); font-size: .83rem; }

        .br-section {
            overflow: hidden;
            border: 1px solid var(--br-border);
            border-radius: 13px;
            background: #fff;
        }

        .br-section + .br-section { margin-top: 13px; }

        .br-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 13px 14px;
            border-bottom: 1px solid var(--br-border-soft);
            background: #fbfcfe;
        }

        .br-section-header h3 { margin: 0 0 3px; color: var(--br-blue-dark); font-size: .76rem; }
        .br-section-header p { margin: 0; color: var(--br-muted); font-size: .58rem; }

        .br-list { display: grid; }

        .br-person-row,
        .br-plan-row,
        .br-terminal-row {
            display: grid;
            align-items: center;
            gap: 12px;
            min-width: 0;
            padding: 12px 14px;
            border-bottom: 1px solid var(--br-border-soft);
        }

        .br-person-row:last-child,
        .br-plan-row:last-child,
        .br-terminal-row:last-child { border-bottom: 0; }

        .br-person-row { grid-template-columns: minmax(0, 1fr) auto; }
        .br-plan-row { grid-template-columns: minmax(150px, 1fr) 150px 112px auto; }
        .br-terminal-row { grid-template-columns: minmax(0, 1fr) auto; }

        .br-person-main,
        .br-terminal-main,
        .br-plan-main {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .br-avatar,
        .br-row-icon {
            display: grid;
            flex: 0 0 38px;
            width: 38px;
            height: 38px;
            place-items: center;
            overflow: hidden;
            border-radius: 10px;
            color: var(--br-blue);
            background: var(--br-blue-soft);
        }

        .br-avatar img { width: 100%; height: 100%; object-fit: cover; }

        .br-row-copy { min-width: 0; }
        .br-row-copy strong,
        .br-row-copy span { display: block; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        .br-row-copy strong { color: var(--br-text); font-size: .7rem; }
        .br-row-copy span { margin-top: 3px; color: var(--br-muted); font-size: .57rem; }

        .br-row-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
        }

        .br-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            min-height: 25px;
            padding: 0 8px;
            border-radius: 999px;
            color: #475569;
            background: #f1f5f9;
            font-size: .55rem;
            font-weight: 850;
        }

        .br-badge.active { color: #065f46; background: var(--br-green-soft); }
        .br-badge.inactive { color: #991b1b; background: var(--br-red-soft); }
        .br-badge.default { color: #1e40af; background: #dbeafe; }

        .br-plan-price,
        .br-plan-state {
            width: 100%;
            min-height: 37px;
            border: 1px solid var(--br-border);
            border-radius: 9px;
            outline: none;
            color: var(--br-text);
            background: #fff;
            font-size: .66rem;
        }

        .br-plan-price { padding: 0 10px; }
        .br-plan-state { padding: 0 8px; }
        .br-plan-price:focus,
        .br-plan-state:focus { border-color: #8facd9; box-shadow: 0 0 0 3px rgba(30, 58, 138, .07); }

        .br-empty {
            padding: 30px 16px;
            color: var(--br-muted);
            text-align: center;
            font-size: .68rem;
        }

        .br-empty i { display: block; margin-bottom: 8px; color: #94a3b8; font-size: 1.25rem; }

        .br-modal {
            position: fixed;
            inset: 0;
            z-index: 12000;
            display: none;
            place-items: center;
            padding: 14px;
            overflow-y: auto;
            background: rgba(15, 23, 42, .58);
            backdrop-filter: blur(5px);
        }

        .br-modal.open { display: grid; }

        .br-modal-card {
            width: min(650px, 100%);
            max-height: calc(100dvh - 28px);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, .75);
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 30px 80px rgba(2, 6, 23, .3);
        }

        .br-modal-card.small { width: min(520px, 100%); }

        .br-modal-header,
        .br-modal-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 16px;
        }

        .br-modal-header { border-bottom: 1px solid var(--br-border-soft); }
        .br-modal-footer { justify-content: flex-end; border-top: 1px solid var(--br-border-soft); }
        .br-modal-header h2 { margin: 0; color: var(--br-blue-dark); font-size: .95rem; }
        .br-modal-header p { margin: 4px 0 0; color: var(--br-muted); font-size: .59rem; }

        .br-modal-close {
            display: grid;
            flex: 0 0 35px;
            width: 35px;
            height: 35px;
            place-items: center;
            border: 0;
            border-radius: 9px;
            color: #64748b;
            background: #f1f5f9;
            cursor: pointer;
        }

        .br-modal-body {
            max-height: calc(100dvh - 190px);
            padding: 16px;
            overflow-y: auto;
            overscroll-behavior: contain;
        }

        .br-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .br-field { min-width: 0; }
        .br-field.full { grid-column: 1 / -1; }
        .br-field label { display: block; margin-bottom: 6px; color: #334155; font-size: .62rem; font-weight: 800; }

        .br-control {
            width: 100%;
            min-height: 42px;
            padding: 9px 11px;
            border: 1px solid var(--br-border);
            border-radius: 10px;
            outline: none;
            color: var(--br-text);
            background: #f8fafc;
            font-size: .7rem;
        }

        textarea.br-control { min-height: 82px; resize: vertical; }
        .br-control:focus { border-color: #8facd9; background: #fff; box-shadow: 0 0 0 3px rgba(30, 58, 138, .07); }

        .br-checks { display: grid; gap: 8px; }

        .br-check {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            padding: 10px;
            border: 1px solid var(--br-border);
            border-radius: 10px;
            background: #fbfcfe;
            cursor: pointer;
        }

        .br-check input { width: 17px; height: 17px; margin: 1px 0 0; accent-color: var(--br-blue); }
        .br-check strong { display: block; color: var(--br-text); font-size: .64rem; }
        .br-check span span { display: block; margin-top: 2px; color: var(--br-muted); font-size: .56rem; line-height: 1.35; }

        body.br-modal-open { overflow: hidden !important; }

        @media (max-width: 1020px) {
            .br-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .br-layout { grid-template-columns: 260px minmax(0, 1fr); }
            .br-mini-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .br-plan-row { grid-template-columns: minmax(140px, 1fr) 130px 105px auto; }
        }

        @media (max-width: 820px) {
            .br-main { padding: 20px; }
            .br-layout { grid-template-columns: 1fr; }
            .br-directory { position: static; }
            .br-branch-list { grid-template-columns: repeat(2, minmax(0, 1fr)); max-height: none; }
        }

        @media (max-width: 620px) {
            .br-main { padding: 74px 10px 24px; }
            .br-page-header,
            .br-detail-header,
            .br-section-header { align-items: stretch; flex-direction: column; }
            .br-page-header .br-primary { width: 100%; }
            .br-stats { grid-template-columns: 1fr 1fr; }
            .br-branch-list { grid-template-columns: 1fr; }
            .br-detail-actions { justify-content: stretch; }
            .br-detail-actions > * { flex: 1; }
            .br-info-grid,
            .br-form-grid { grid-template-columns: 1fr; }
            .br-info-box.full,
            .br-field.full { grid-column: auto; }
            .br-mini-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .br-person-row,
            .br-terminal-row,
            .br-plan-row { grid-template-columns: 1fr; }
            .br-row-actions { justify-content: flex-start; flex-wrap: wrap; }
            .br-plan-row .br-primary { width: 100%; }
            .br-modal { padding: 7px; }
            .br-modal-card { max-height: calc(100dvh - 14px); border-radius: 15px; }
            .br-modal-body { max-height: calc(100dvh - 170px); }
            .br-modal-footer > * { flex: 1; }
        }

        @media (max-width: 390px) {
            .br-stats,
            .br-mini-stats { grid-template-columns: 1fr; }
            .br-detail-actions { flex-direction: column; }
            .br-modal-footer { flex-direction: column-reverse; }
            .br-modal-footer > * { width: 100%; }
        }

        /* ===== Rediseño compacto y estable del módulo ===== */
        .br-main {
            padding: 26px 28px 38px;
        }

        .br-container {
            width: 100%;
            max-width: 1500px;
        }

        .br-page-header {
            align-items: center;
            margin-bottom: 20px;
            padding: 2px 2px 0;
        }

        .br-page-header h1 {
            font-size: clamp(1.8rem, 3vw, 2.35rem);
        }

        .br-page-header p {
            max-width: 860px;
            font-size: .84rem;
        }

        .br-page-header > .br-primary {
            min-height: 44px;
            padding-inline: 17px;
            border-radius: 12px;
            box-shadow: 0 10px 24px rgba(30, 58, 138, .18);
        }

        .br-stats {
            gap: 14px;
            margin-bottom: 18px;
        }

        .br-stat {
            min-height: 88px;
            padding: 16px 17px;
            border-color: #e2e8f0;
            border-radius: 17px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .05);
        }

        .br-stat-icon {
            width: 44px;
            height: 44px;
            flex-basis: 44px;
            border-radius: 13px;
            font-size: .9rem;
        }

        .br-stat strong {
            font-size: 1.12rem;
        }

        .br-stat span {
            font-size: .64rem;
        }

        .br-layout {
            display: block;
        }

        .br-directory {
            position: static;
            margin-bottom: 16px;
        }

        .br-directory .br-card-header {
            padding: 17px 18px 12px;
            border-bottom: 0;
        }

        .br-directory .br-search {
            width: min(360px, calc(100% - 32px));
            margin: 0 16px 6px;
        }

        .br-branch-list {
            display: grid !important;
            grid-template-columns: repeat(auto-fill, minmax(255px, 330px));
            justify-content: start;
            gap: 11px;
            max-height: none;
            min-height: 108px;
            padding: 9px 16px 17px;
            overflow: visible;
        }

        .br-branch-item {
            display: block !important;
            min-height: 118px;
            padding: 14px;
            border-color: #dfe7f2;
            border-radius: 14px;
            background:
                linear-gradient(145deg, rgba(238, 244, 255, .55), rgba(255, 255, 255, .96));
            box-shadow: 0 6px 18px rgba(15, 23, 42, .04);
        }

        .br-branch-item:hover {
            transform: translateY(-1px);
            border-color: #8facd9;
            box-shadow: 0 10px 24px rgba(30, 58, 138, .08);
        }

        .br-branch-item.active {
            border-color: #6f94cf;
            background:
                linear-gradient(145deg, #edf4ff, #ffffff);
            box-shadow:
                0 0 0 3px rgba(30, 58, 138, .07),
                0 10px 24px rgba(30, 58, 138, .08);
        }

        .br-branch-name strong {
            font-size: .78rem;
        }

        .br-branch-name span,
        .br-branch-metrics {
            font-size: .59rem;
        }

        .br-layout > section.br-card {
            display: block !important;
            width: 100%;
            min-height: 250px;
            overflow: visible;
        }

        .br-detail-header {
            align-items: center;
            padding: 20px 21px;
            border-bottom-color: #e4eaf2;
            background:
                linear-gradient(135deg, rgba(238, 244, 255, .95), rgba(255, 255, 255, .98));
        }

        .br-detail-title h2 {
            font-size: 1.32rem;
        }

        .br-detail-title p {
            font-size: .69rem;
        }

        .br-tabs {
            gap: 8px;
            padding: 10px 16px 0;
            background: #fbfcfe;
        }

        .br-tab {
            min-height: 42px;
            padding-inline: 14px;
            font-size: .67rem;
        }

        .br-tab-panel {
            padding: 18px;
        }

        .br-mini-stats {
            gap: 12px;
        }

        .br-mini-stat,
        .br-info-box {
            border-color: #e2e8f0;
            background: #ffffff;
            box-shadow: 0 5px 16px rgba(15, 23, 42, .035);
        }

        .br-section {
            border-color: #e1e8f1;
            border-radius: 15px;
        }

        .br-section-header {
            padding: 15px 16px;
            background: #f8fafc;
        }

        .br-person-row,
        .br-plan-row,
        .br-terminal-row {
            padding: 14px 16px;
        }

        /* Modal visible, sin depender de display:none ni de animaciones externas */
        .br-modal {
            display: grid;
            visibility: hidden;
            opacity: 0;
            pointer-events: none;
            z-index: 50000;
            transition: opacity .16s ease, visibility .16s ease;
        }

        .br-modal.open {
            visibility: visible;
            opacity: 1;
            pointer-events: auto;
        }

        .br-modal-card {
            transform: translateY(10px) scale(.985);
            transition: transform .18s ease;
        }

        .br-modal.open .br-modal-card {
            transform: translateY(0) scale(1);
        }

        /*
         * SweetAlert se crea fuera del modal. Su z-index original es menor
         * que el del módulo, por eso antes aparecía detrás.
         */
        .swal2-container {
            z-index: 70000 !important;
        }

        .br-modal-header {
            padding: 17px 18px;
            background: #fbfcfe;
        }

        .br-modal-body {
            padding: 18px;
        }

        .br-modal-footer {
            padding: 14px 18px 16px;
            background: #fbfcfe;
        }

        .br-control {
            min-height: 44px;
            font-size: .73rem;
        }

        @media (max-width: 820px) {
            .br-main {
                padding: 20px 18px 32px;
            }

            .br-branch-list {
                grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            }
        }

        @media (max-width: 620px) {
            .br-main {
                padding: 74px 10px 26px;
            }

            .br-page-header {
                align-items: stretch;
            }

            .br-branch-list {
                grid-template-columns: 1fr;
            }

            .br-directory .br-search {
                width: calc(100% - 24px);
                margin-inline: 12px;
            }

            .br-detail-header {
                align-items: stretch;
            }
        }

    </style>
</head>
<body>
<?php require __DIR__ . '/includes/sidebar.php'; ?>

<main class="main-content br-main">
    <div class="br-container">
        <header class="br-page-header">
            <div>
                <span class="br-eyebrow">
                    <i class="fas fa-building"></i>
                    Administración
                </span>
                <h1>Sucursales</h1>
                <p>
                    Registra sedes y administra el personal, los planes,
                    el inventario inicial y las terminales disponibles en cada una.
                </p>
            </div>

            <?php if ($errorInstalacion === ''): ?>
                <button type="button" class="br-primary" id="newBranchButton" onclick="if(window.sucursalesOpenModal){window.sucursalesOpenModal('branchModal');}else{var m=document.getElementById('branchModal');if(m){m.classList.add('open');m.setAttribute('aria-hidden','false');document.body.classList.add('br-modal-open');}}">
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
            <section class="br-stats" aria-label="Resumen de sucursales">
                <article class="br-stat">
                    <span class="br-stat-icon"><i class="fas fa-building"></i></span>
                    <div><strong><?php echo $totalSucursales; ?></strong><span>Sucursales registradas</span></div>
                </article>
                <article class="br-stat">
                    <span class="br-stat-icon"><i class="fas fa-circle-check"></i></span>
                    <div><strong><?php echo $totalActivas; ?></strong><span>Sedes activas</span></div>
                </article>
                <article class="br-stat">
                    <span class="br-stat-icon"><i class="fas fa-users"></i></span>
                    <div><strong><?php echo $totalPersonal; ?></strong><span>Asignaciones activas</span></div>
                </article>
                <article class="br-stat">
                    <span class="br-stat-icon"><i class="fas fa-boxes-stacked"></i></span>
                    <div><strong><?php echo number_format($totalUnidades); ?></strong><span>Unidades en inventario</span></div>
                </article>
            </section>

            <div class="br-layout">
                <aside class="br-card br-directory">
                    <div class="br-card-header">
                        <div>
                            <h2>Sedes registradas</h2>
                            <p>Selecciona una para administrarla.</p>
                        </div>
                        <span class="br-badge"><?php echo $totalSucursales; ?></span>
                    </div>

                    <div class="br-search">
                        <i class="fas fa-search"></i>
                        <input type="search" id="branchSearch" placeholder="Buscar sucursal...">
                    </div>

                    <div class="br-branch-list" id="branchList">
                        <?php foreach ($sucursales as $sucursal): ?>
                            <?php
                            $seleccionada = $sucursalSeleccionada !== null
                                && (int) $sucursalSeleccionada['id'] === (int) $sucursal['id'];
                            $esSesion = (int) $sucursal['id'] === $sucursalActivaSesion;
                            ?>
                            <a
                                href="sucursales.php?sucursal=<?php echo (int) $sucursal['id']; ?>"
                                class="br-branch-item <?php echo $seleccionada ? 'active' : ''; ?> <?php echo $sucursal['estado'] === 'inactiva' ? 'inactive' : ''; ?>"
                                data-branch-search="<?php echo sucursalesH(strtolower($sucursal['nombre'] . ' ' . $sucursal['clave'])); ?>"
                            >
                                <div class="br-branch-top">
                                    <div class="br-branch-name">
                                        <strong><?php echo sucursalesH($sucursal['nombre']); ?></strong>
                                        <span><?php echo sucursalesH($sucursal['clave']); ?></span>
                                    </div>
                                    <span class="br-status-dot <?php echo $sucursal['estado'] === 'activa' ? 'active' : ''; ?>"></span>
                                </div>

                                <div class="br-branch-tags">
                                    <?php if ((int) $sucursal['es_matriz'] === 1): ?>
                                        <span class="br-tag matrix"><i class="fas fa-star"></i>Matriz</span>
                                    <?php endif; ?>
                                    <?php if ($esSesion): ?>
                                        <span class="br-tag session"><i class="fas fa-location-dot"></i>Actual</span>
                                    <?php endif; ?>
                                    <?php if ($sucursal['estado'] === 'inactiva'): ?>
                                        <span class="br-tag">Inactiva</span>
                                    <?php endif; ?>
                                </div>

                                <div class="br-branch-metrics">
                                    <span><i class="fas fa-user"></i> <?php echo (int) $sucursal['usuarios_activos']; ?></span>
                                    <span><i class="fas fa-box"></i> <?php echo (int) $sucursal['unidades_stock']; ?></span>
                                    <span><i class="fas fa-credit-card"></i> <?php echo (int) $sucursal['terminales_activas']; ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </aside>

                <section class="br-card">
                    <?php if ($sucursalSeleccionada === null): ?>
                        <div class="br-empty">
                            <i class="fas fa-building-circle-xmark"></i>
                            Todavía no hay una sucursal disponible.
                        </div>
                    <?php else: ?>
                        <?php $sucursalId = (int) $sucursalSeleccionada['id']; ?>

                        <header class="br-detail-header">
                            <div class="br-detail-title">
                                <h2><?php echo sucursalesH($sucursalSeleccionada['nombre']); ?></h2>
                                <p>
                                    <?php echo sucursalesH($sucursalSeleccionada['clave']); ?>
                                    · <?php echo $sucursalSeleccionada['estado'] === 'activa' ? 'Sucursal activa' : 'Sucursal inactiva'; ?>
                                    <?php if ($sucursalId === $sucursalActivaSesion): ?>
                                        · Sede actual de tu sesión
                                    <?php endif; ?>
                                </p>
                            </div>

                            <div class="br-detail-actions">
                                <button
                                    type="button"
                                    class="br-secondary"
                                    id="editBranchButton"
                                    onclick="if(window.sucursalesOpenModal){window.sucursalesOpenModal('branchModal');}"
                                    data-branch='<?php echo sucursalesH(json_encode($sucursalSeleccionada, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>'
                                >
                                    <i class="fas fa-pen"></i>
                                    Editar
                                </button>

                                <button
                                    type="button"
                                    class="<?php echo $sucursalSeleccionada['estado'] === 'activa' ? 'br-danger' : 'br-primary'; ?>"
                                    id="toggleBranchButton"
                                    data-id="<?php echo $sucursalId; ?>"
                                    data-current-state="<?php echo sucursalesH($sucursalSeleccionada['estado']); ?>"
                                    <?php echo (int) $sucursalSeleccionada['es_matriz'] === 1 ? 'disabled title="La matriz no puede desactivarse"' : ''; ?>
                                >
                                    <i class="fas <?php echo $sucursalSeleccionada['estado'] === 'activa' ? 'fa-circle-pause' : 'fa-circle-play'; ?>"></i>
                                    <?php echo $sucursalSeleccionada['estado'] === 'activa' ? 'Desactivar' : 'Activar'; ?>
                                </button>
                            </div>
                        </header>

                        <nav class="br-tabs" aria-label="Secciones de la sucursal">
                            <button type="button" class="br-tab active" data-tab="general"><i class="fas fa-building"></i>General</button>
                            <button type="button" class="br-tab" data-tab="personal"><i class="fas fa-users"></i>Personal</button>
                            <button type="button" class="br-tab" data-tab="planes"><i class="fas fa-id-card"></i>Planes</button>
                            <button type="button" class="br-tab" data-tab="terminales"><i class="fas fa-credit-card"></i>Terminales</button>
                        </nav>

                        <div class="br-tab-panel active" data-panel="general">
                            <div class="br-mini-stats">
                                <article class="br-mini-stat"><span>Productos</span><strong><?php echo $resumenInventario['productos']; ?></strong></article>
                                <article class="br-mini-stat"><span>Unidades</span><strong><?php echo number_format($resumenInventario['unidades']); ?></strong></article>
                                <article class="br-mini-stat"><span>Bajo mínimo</span><strong><?php echo $resumenInventario['bajo_minimo']; ?></strong></article>
                                <article class="br-mini-stat"><span>Valor compra</span><strong><?php echo sucursalesDinero((float) $resumenInventario['valor_compra']); ?></strong></article>
                                <article class="br-mini-stat"><span>Valor venta</span><strong><?php echo sucursalesDinero((float) $resumenInventario['valor_venta']); ?></strong></article>
                            </div>

                            <section class="br-section">
                                <div class="br-section-header">
                                    <div>
                                        <h3>Información de la sede</h3>
                                        <p>Datos visibles en operaciones y documentos de esta sucursal.</p>
                                    </div>
                                    <button type="button" class="br-secondary" id="syncCatalogsButton" data-id="<?php echo $sucursalId; ?>">
                                        <i class="fas fa-rotate"></i>
                                        Sincronizar catálogos
                                    </button>
                                </div>

                                <div style="padding:14px">
                                    <div class="br-info-grid">
                                        <div class="br-info-box"><span>Clave</span><strong><?php echo sucursalesH($sucursalSeleccionada['clave']); ?></strong></div>
                                        <div class="br-info-box"><span>Zona horaria</span><strong><?php echo sucursalesH($zonasHorarias[$sucursalSeleccionada['zona_horaria']] ?? $sucursalSeleccionada['zona_horaria']); ?></strong></div>
                                        <div class="br-info-box"><span>Teléfono</span><strong><?php echo sucursalesH($sucursalSeleccionada['telefono'] ?: 'No registrado'); ?></strong></div>
                                        <div class="br-info-box"><span>Correo</span><strong><?php echo sucursalesH($sucursalSeleccionada['email'] ?: 'No registrado'); ?></strong></div>
                                        <div class="br-info-box full"><span>Dirección</span><strong><?php echo nl2br(sucursalesH($sucursalSeleccionada['direccion'] ?: 'No registrada')); ?></strong></div>
                                        <div class="br-info-box full"><span>Horario</span><strong><?php echo sucursalesH($sucursalSeleccionada['horario'] ?: 'No registrado'); ?></strong></div>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <div class="br-tab-panel" data-panel="personal">
                            <section class="br-section">
                                <div class="br-section-header">
                                    <div>
                                        <h3>Personal asignado</h3>
                                        <p>Cada colaborador puede tener un rol diferente en esta sede.</p>
                                    </div>
                                    <button type="button" class="br-primary" id="newAssignmentButton" onclick="if(window.sucursalesOpenModal){window.sucursalesOpenModal('assignmentModal');}">
                                        <i class="fas fa-user-plus"></i>
                                        Asignar personal
                                    </button>
                                </div>

                                <div class="br-list">
                                    <?php if ($personal === []): ?>
                                        <div class="br-empty"><i class="fas fa-user-slash"></i>No hay personal asignado.</div>
                                    <?php else: ?>
                                        <?php foreach ($personal as $persona): ?>
                                            <?php
                                            $rolPersona = (string) $persona['rol_efectivo'];
                                            $asignacionActiva = (string) $persona['asignacion_estado'] === 'activo';
                                            ?>
                                            <article class="br-person-row">
                                                <div class="br-person-main">
                                                    <span class="br-avatar">
                                                        <?php if (!empty($persona['foto_perfil']) && is_file(__DIR__ . '/' . $persona['foto_perfil'])): ?>
                                                            <img src="<?php echo sucursalesH($persona['foto_perfil']); ?>" alt="">
                                                        <?php else: ?>
                                                            <i class="fas fa-user"></i>
                                                        <?php endif; ?>
                                                    </span>
                                                    <div class="br-row-copy">
                                                        <strong><?php echo sucursalesH($persona['nombre']); ?></strong>
                                                        <span>
                                                            <?php echo sucursalesH($persona['email']); ?> ·
                                                            <?php echo sucursalesH($rolesSucursal[$rolPersona] ?? ucfirst($rolPersona)); ?>
                                                        </span>
                                                    </div>
                                                </div>

                                                <div class="br-row-actions">
                                                    <?php if ((int) $persona['es_principal'] === 1): ?>
                                                        <span class="br-badge default"><i class="fas fa-house"></i>Principal</span>
                                                    <?php endif; ?>
                                                    <?php if ((int) $persona['puede_operar_caja'] === 1): ?>
                                                        <span class="br-badge"><i class="fas fa-cash-register"></i>Caja</span>
                                                    <?php endif; ?>
                                                    <span class="br-badge <?php echo $asignacionActiva ? 'active' : 'inactive'; ?>">
                                                        <?php echo $asignacionActiva ? 'Activo' : 'Inactivo'; ?>
                                                    </span>
                                                    <button
                                                        type="button"
                                                        class="br-icon-button edit-assignment-button"
                                                        title="Editar acceso"
                                                        data-assignment='<?php echo sucursalesH(json_encode($persona, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>'
                                                    >
                                                        <i class="fas fa-pen"></i>
                                                    </button>
                                                    <?php if ($asignacionActiva): ?>
                                                        <button
                                                            type="button"
                                                            class="br-icon-button remove-assignment-button"
                                                            title="Retirar acceso"
                                                            data-user-id="<?php echo (int) $persona['usuario_id']; ?>"
                                                            data-user-name="<?php echo sucursalesH($persona['nombre']); ?>"
                                                        >
                                                            <i class="fas fa-user-minus"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </section>
                        </div>

                        <div class="br-tab-panel" data-panel="planes">
                            <section class="br-section">
                                <div class="br-section-header">
                                    <div>
                                        <h3>Planes disponibles</h3>
                                        <p>El catálogo es global, pero el precio y disponibilidad pertenecen a la sede.</p>
                                    </div>
                                </div>

                                <div class="br-list">
                                    <?php foreach ($planesSucursal as $plan): ?>
                                        <form class="br-plan-row plan-form">
                                            <input type="hidden" name="sucursal_id" value="<?php echo $sucursalId; ?>">
                                            <input type="hidden" name="plan_id" value="<?php echo (int) $plan['plan_id']; ?>">

                                            <div class="br-plan-main">
                                                <span class="br-row-icon"><i class="fas fa-id-card"></i></span>
                                                <div class="br-row-copy">
                                                    <strong><?php echo sucursalesH($plan['nombre']); ?></strong>
                                                    <span><?php echo (int) $plan['duracion_dias']; ?> días · Catálogo <?php echo sucursalesDinero((float) $plan['precio_catalogo']); ?></span>
                                                </div>
                                            </div>

                                            <input
                                                class="br-plan-price"
                                                type="number"
                                                name="precio"
                                                min="0"
                                                step="0.01"
                                                value="<?php echo sucursalesH(number_format((float) $plan['precio_sucursal'], 2, '.', '')); ?>"
                                                aria-label="Precio de <?php echo sucursalesH($plan['nombre']); ?>"
                                            >

                                            <select class="br-plan-state" name="estado" aria-label="Estado del plan">
                                                <option value="activo" <?php echo $plan['estado_sucursal'] === 'activo' ? 'selected' : ''; ?>>Activo</option>
                                                <option value="inactivo" <?php echo $plan['estado_sucursal'] === 'inactivo' ? 'selected' : ''; ?>>Inactivo</option>
                                            </select>

                                            <button type="submit" class="br-primary"><i class="fas fa-check"></i>Guardar</button>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        </div>

                        <div class="br-tab-panel" data-panel="terminales">
                            <section class="br-section">
                                <div class="br-section-header">
                                    <div>
                                        <h3>Terminales Mercado Pago</h3>
                                        <p>Relaciona los dispositivos Point autorizados para cobrar en esta sede.</p>
                                    </div>
                                    <button type="button" class="br-primary" id="newTerminalButton" onclick="if(window.sucursalesOpenModal){window.sucursalesOpenModal('terminalModal');}">
                                        <i class="fas fa-plus"></i>
                                        Agregar terminal
                                    </button>
                                </div>

                                <div class="br-list">
                                    <?php if ($terminalesSucursal === []): ?>
                                        <div class="br-empty"><i class="fas fa-credit-card"></i>No hay terminales registradas.</div>
                                    <?php else: ?>
                                        <?php foreach ($terminalesSucursal as $terminal): ?>
                                            <article class="br-terminal-row">
                                                <div class="br-terminal-main">
                                                    <span class="br-row-icon"><i class="fas fa-mobile-screen-button"></i></span>
                                                    <div class="br-row-copy">
                                                        <strong><?php echo sucursalesH($terminal['nombre']); ?></strong>
                                                        <span><?php echo sucursalesH($terminal['terminal_id']); ?></span>
                                                    </div>
                                                </div>

                                                <div class="br-row-actions">
                                                    <?php if ((int) $terminal['predeterminada'] === 1): ?>
                                                        <span class="br-badge default">Predeterminada</span>
                                                    <?php endif; ?>
                                                    <span class="br-badge <?php echo (int) $terminal['activo'] === 1 ? 'active' : 'inactive'; ?>">
                                                        <?php echo (int) $terminal['activo'] === 1 ? 'Activa' : 'Inactiva'; ?>
                                                    </span>
                                                    <button
                                                        type="button"
                                                        class="br-icon-button edit-terminal-button"
                                                        title="Editar terminal"
                                                        data-terminal='<?php echo sucursalesH(json_encode($terminal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>'
                                                    >
                                                        <i class="fas fa-pen"></i>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="br-icon-button toggle-terminal-button"
                                                        title="Cambiar estado"
                                                        data-id="<?php echo (int) $terminal['id']; ?>"
                                                        data-active="<?php echo (int) $terminal['activo']; ?>"
                                                        data-name="<?php echo sucursalesH($terminal['nombre']); ?>"
                                                    >
                                                        <i class="fas <?php echo (int) $terminal['activo'] === 1 ? 'fa-circle-pause' : 'fa-circle-play'; ?>"></i>
                                                    </button>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </section>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
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
                    <h2 id="branchModalTitle">Nueva sucursal</h2>
                    <p>El inventario iniciará en cero y el administrador actual quedará asignado.</p>
                </div>
                <button type="button" class="br-modal-close" data-close-modal="branchModal"><i class="fas fa-xmark"></i></button>
            </header>

            <div class="br-modal-body">
                <div class="br-form-grid">
                    <div class="br-field">
                        <label for="branchKey">Clave *</label>
                        <input class="br-control" type="text" id="branchKey" name="clave" maxlength="30" placeholder="NORTE" required>
                    </div>
                    <div class="br-field">
                        <label for="branchName">Nombre *</label>
                        <input class="br-control" type="text" id="branchName" name="nombre" maxlength="150" placeholder="Sucursal Norte" required>
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
                <button type="button" class="br-secondary" data-close-modal="branchModal">Cancelar</button>
                <button type="submit" class="br-primary"><i class="fas fa-check"></i>Guardar sucursal</button>
            </footer>
        </form>
    </div>
</div>

<?php if ($sucursalSeleccionada !== null): ?>
<div class="br-modal" id="assignmentModal" aria-hidden="true">
    <div class="br-modal-card small" role="dialog" aria-modal="true" aria-labelledby="assignmentModalTitle">
        <form id="assignmentForm">
            <input type="hidden" name="sucursal_id" value="<?php echo (int) $sucursalSeleccionada['id']; ?>">

            <header class="br-modal-header">
                <div>
                    <h2 id="assignmentModalTitle">Asignar personal</h2>
                    <p>Define el rol y los permisos del colaborador en esta sede.</p>
                </div>
                <button type="button" class="br-modal-close" data-close-modal="assignmentModal"><i class="fas fa-xmark"></i></button>
            </header>

            <div class="br-modal-body">
                <div class="br-form-grid">
                    <div class="br-field full">
                        <label for="assignmentUser">Colaborador *</label>
                        <select class="br-control" id="assignmentUser" name="usuario_id" required>
                            <option value="">Selecciona un usuario</option>
                            <?php foreach ($usuariosDisponibles as $usuarioDisponible): ?>
                                <option
                                    value="<?php echo (int) $usuarioDisponible['id']; ?>"
                                    data-global-role="<?php echo sucursalesH($usuarioDisponible['rol']); ?>"
                                >
                                    <?php echo sucursalesH($usuarioDisponible['nombre'] . ' · ' . $usuarioDisponible['email']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="br-field">
                        <label for="assignmentRole">Rol en la sucursal *</label>
                        <select class="br-control" id="assignmentRole" name="rol_sucursal" required>
                            <?php foreach ($rolesSucursal as $rol => $nombreRol): ?>
                                <option value="<?php echo sucursalesH($rol); ?>"><?php echo sucursalesH($nombreRol); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="br-field">
                        <label for="assignmentState">Estado</label>
                        <select class="br-control" id="assignmentState" name="estado">
                            <option value="activo">Activo</option>
                            <option value="inactivo">Inactivo</option>
                        </select>
                    </div>
                    <div class="br-field full">
                        <div class="br-checks">
                            <label class="br-check">
                                <input type="checkbox" id="assignmentMain" name="es_principal" value="1">
                                <span><strong>Sucursal principal</strong><span>Será la sede inicial del usuario al iniciar sesión.</span></span>
                            </label>
                            <label class="br-check">
                                <input type="checkbox" id="assignmentCash" name="puede_operar_caja" value="1">
                                <span><strong>Puede operar caja</strong><span>Permite aperturas, cobros y cierres cuando su rol tenga acceso.</span></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="br-modal-footer">
                <button type="button" class="br-secondary" data-close-modal="assignmentModal">Cancelar</button>
                <button type="submit" class="br-primary"><i class="fas fa-check"></i>Guardar acceso</button>
            </footer>
        </form>
    </div>
</div>

<div class="br-modal" id="terminalModal" aria-hidden="true">
    <div class="br-modal-card small" role="dialog" aria-modal="true" aria-labelledby="terminalModalTitle">
        <form id="terminalForm">
            <input type="hidden" name="sucursal_id" value="<?php echo (int) $sucursalSeleccionada['id']; ?>">
            <input type="hidden" name="terminal_registro_id" id="terminalRegistryId" value="">

            <header class="br-modal-header">
                <div>
                    <h2 id="terminalModalTitle">Agregar terminal</h2>
                    <p>Usa el identificador exacto reportado por Mercado Pago Point.</p>
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

        function resetBranchForm() {
            if (!branchForm) {
                return;
            }

            branchForm.reset();

            if (byId('branchId')) {
                byId('branchId').value = '';
            }

            if (byId('branchModalTitle')) {
                byId('branchModalTitle').textContent = 'Nueva sucursal';
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
                    byId('branchModalTitle').textContent = 'Editar sucursal';
                }

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

        var syncCatalogsButton = byId('syncCatalogsButton');

        if (syncCatalogsButton) {
            syncCatalogsButton.addEventListener('click', async function () {
                syncCatalogsButton.disabled = true;

                try {
                    var payload = await postAction(
                        'sincronizar_catalogos',
                        {
                            sucursal_id:
                                syncCatalogsButton.getAttribute('data-id')
                        }
                    );

                    await showAlert({
                        icon: 'success',
                        title: 'Catálogos sincronizados',
                        text: payload.mensaje,
                        confirmButtonColor: '#1e3a8a'
                    });

                    window.location.reload();
                } catch (error) {
                    showAlert({
                        icon: 'error',
                        title: 'No se pudo sincronizar',
                        text: error.message,
                        confirmButtonColor: '#1e3a8a'
                    });
                } finally {
                    syncCatalogsButton.disabled = false;
                }
            });
        }

        var assignmentForm = byId('assignmentForm');
        var assignmentUser = byId('assignmentUser');
        var newAssignmentButton = byId('newAssignmentButton');

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
                    'Asignar personal';
            }

            if (byId('assignmentState')) {
                byId('assignmentState').value = 'activo';
            }
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
                    && byId('assignmentRole')
                ) {
                    byId('assignmentRole').value =
                        option.getAttribute('data-global-role');
                }
            });
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
                        'Editar acceso';
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

                if (byId('assignmentCash')) {
                    byId('assignmentCash').checked =
                        Number(assignment.puede_operar_caja) === 1;
                }

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
                        title: 'Acceso actualizado',
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
                    'Agregar terminal';
            }

            if (byId('terminalActive')) {
                byId('terminalActive').checked = true;
            }
        }

        if (newTerminalButton) {
            newTerminalButton.addEventListener('click', function () {
                resetTerminalForm();
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