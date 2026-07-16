<?php

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/legal_guard.php';

$dbLegal = null;
$errorLegal = '';
$configLegal = [];
$documentsLegal = [];
$acceptanceLegal = null;
$isCurrentLegal = false;
$usersLegal = [];

try {
    $dbLegal = legal_get_database();
    legal_ensure_table($dbLegal);

    $configLegal = legal_get_gym_config($dbLegal);
    $documentsLegal = legal_get_documents($configLegal);

    $userIdLegal = (int) $_SESSION['user_id'];

    $acceptanceLegal = legal_get_acceptance(
        $dbLegal,
        $userIdLegal
    );

    $isCurrentLegal = legal_acceptance_is_current(
        $acceptanceLegal,
        $documentsLegal
    );
} catch (Throwable $initialError) {
    error_log(
        '[Legal page] ' . $initialError->getMessage()
    );

    $errorLegal =
        'No fue posible cargar el módulo legal.';

    if (legal_user_is_admin()) {
        $errorLegal .=
            ' Detalle: '
            . $initialError->getMessage();
    }
}

if (empty($_SESSION['legal_csrf'])) {
    $_SESSION['legal_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$returnUrl = legal_safe_return_url(
    (string) ($_GET['return'] ?? '')
);

$mandatoryLegal =
    isset($_GET['obligatorio'])
    && (string) $_GET['obligatorio'] === '1';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) ($_POST['legal_action'] ?? '') === 'accept'
) {
    $postedReturn = legal_safe_return_url(
        (string) ($_POST['return'] ?? '')
    );

    $csrf = (string) ($_POST['legal_csrf'] ?? '');

    if (
        $csrf === ''
        || !hash_equals(
            (string) $_SESSION['legal_csrf'],
            $csrf
        )
    ) {
        $errorLegal =
            'La sesión del formulario expiró. Actualiza la página.';
    } elseif (
        !isset($_POST['acepto_aviso'])
        || !isset($_POST['acepto_terminos'])
    ) {
        $errorLegal =
            'Debes aceptar ambos documentos para continuar.';
    } elseif (!$dbLegal) {
        $errorLegal =
            'No existe una conexión disponible para guardar la aceptación.';
    } else {
        try {
            legal_save_acceptance(
                $dbLegal,
                (int) $_SESSION['user_id'],
                $documentsLegal
            );

            $_SESSION['legal_csrf'] = bin2hex(
                random_bytes(32)
            );

            unset($_SESSION['legal_return_after_accept']);

            legal_redirect($postedReturn);
        } catch (Throwable $saveError) {
            error_log(
                '[Legal acceptance] '
                . $saveError->getMessage()
            );

            $errorLegal =
                'No fue posible guardar la aceptación.';

            if (legal_user_is_admin()) {
                $errorLegal .=
                    ' Detalle: '
                    . $saveError->getMessage();
            }
        }
    }
}

if (
    legal_user_is_admin()
    && $dbLegal
    && $documentsLegal
) {
    $resultUsers = $dbLegal->query(
        "SELECT
            u.id,
            u.nombre,
            u.email,
            u.rol,
            u.estado,
            a.aviso_version,
            a.aviso_hash,
            a.terminos_version,
            a.terminos_hash,
            a.fecha_aceptacion
         FROM usuarios u
         LEFT JOIN usuarios_aceptacion_legal_v2 a
            ON a.usuario_id = u.id
         ORDER BY u.nombre ASC"
    );

    if ($resultUsers) {
        while ($rowUser = $resultUsers->fetch_assoc()) {
            $rowUser['vigente'] =
                legal_acceptance_is_current(
                    $rowUser,
                    $documentsLegal
                );

            $usersLegal[] = $rowUser;
        }
    }
}

$searchLegal = strtolower(
    trim((string) ($_GET['q'] ?? ''))
);

$statusLegal = strtolower(
    trim((string) ($_GET['estado'] ?? ''))
);

$filteredUsersLegal = array_values(
    array_filter(
        $usersLegal,
        static function ($user) use (
            $searchLegal,
            $statusLegal
        ) {
            if ($searchLegal !== '') {
                $haystack = strtolower(
                    (string) $user['nombre']
                    . ' '
                    . (string) $user['email']
                );

                if (
                    strpos(
                        $haystack,
                        $searchLegal
                    ) === false
                ) {
                    return false;
                }
            }

            if (
                $statusLegal === 'aceptado'
                && !$user['vigente']
            ) {
                return false;
            }

            if (
                $statusLegal === 'pendiente'
                && $user['vigente']
            ) {
                return false;
            }

            return true;
        }
    )
);

$totalUsersLegal = count($usersLegal);
$totalAcceptedLegal = count(
    array_filter(
        $usersLegal,
        static function ($user) {
            return (bool) $user['vigente'];
        }
    )
);
$totalPendingLegal =
    $totalUsersLegal - $totalAcceptedLegal;

$allowedPerPageLegal = [5, 10, 20];
$perPageLegal = (int) ($_GET['por_pagina'] ?? 10);

if (!in_array($perPageLegal, $allowedPerPageLegal, true)) {
    $perPageLegal = 10;
}

$pageLegal = max(
    1,
    (int) ($_GET['pagina'] ?? 1)
);

$totalPagesLegal = max(
    1,
    (int) ceil(
        count($filteredUsersLegal)
        / $perPageLegal
    )
);

$pageLegal = min(
    $pageLegal,
    $totalPagesLegal
);

$visibleUsersLegal = array_slice(
    $filteredUsersLegal,
    ($pageLegal - 1) * $perPageLegal,
    $perPageLegal
);

$totalFilteredLegal = count($filteredUsersLegal);
$firstVisibleLegal = $totalFilteredLegal > 0
    ? (($pageLegal - 1) * $perPageLegal) + 1
    : 0;
$lastVisibleLegal = min(
    $pageLegal * $perPageLegal,
    $totalFilteredLegal
);

function legal_page_url($page)
{
    $params = $_GET;
    unset($params['obligatorio'], $params['return']);

    $params['pagina'] = (int) $page;

    return 'legal.php?'
        . http_build_query($params);
}

$userNameLegal = trim(
    (string) (
        $_SESSION['user_name']
        ?? $_SESSION['nombre']
        ?? 'Usuario'
    )
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

    <title>
        Aviso y términos -
        <?php echo legal_h(
            $configLegal['nombre']
            ?? 'Gimnasio'
        ); ?>
    </title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >

    <style>
        :root {
            --legal-blue: #1e3a8a;
            --legal-blue-dark: #13275c;
            --legal-soft-blue: #eef4ff;
            --legal-bg: #f3f6fa;
            --legal-white: #ffffff;
            --legal-text: #1f2937;
            --legal-muted: #64748b;
            --legal-border: #dce4ef;
            --legal-green: #047857;
            --legal-red: #b91c1c;
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
            color: var(--legal-text);
            background: var(--legal-bg);
            font-family:
                "Segoe UI",
                Roboto,
                Arial,
                sans-serif;
        }

        body.legal-modal-open {
            overflow: hidden;
        }

        button,
        input,
        select,
        a {
            font: inherit;
        }

        .legal-main {
            min-height: 100vh;
            padding: 28px;
        }

        .legal-wrapper {
            width: min(1160px, 100%);
            margin: 0 auto;
        }

        .legal-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 18px;
        }

        .legal-header small {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 7px;
            color: var(--legal-blue);
            font-size: .7rem;
            font-weight: 850;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .legal-header h1 {
            margin: 0 0 7px;
            color: var(--legal-blue-dark);
            font-size: clamp(1.7rem, 4vw, 2.35rem);
            line-height: 1.08;
            letter-spacing: -.035em;
        }

        .legal-header p {
            max-width: 720px;
            margin: 0;
            color: var(--legal-muted);
            font-size: .85rem;
            line-height: 1.55;
        }

        .legal-status {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            flex: 0 0 auto;
            padding: 9px 12px;
            border-radius: 999px;
            font-size: .7rem;
            font-weight: 850;
        }

        .legal-status.ok {
            color: #065f46;
            background: #ecfdf5;
        }

        .legal-status.pending {
            color: #991b1b;
            background: #fef2f2;
        }

        .legal-card {
            margin-bottom: 16px;
            overflow: hidden;
            border: 1px solid var(--legal-border);
            border-radius: 19px;
            background: var(--legal-white);
            box-shadow:
                0 13px 35px
                rgba(15, 23, 42, .07);
        }

        .legal-intro {
            display: grid;
            grid-template-columns:
                minmax(0, 1.2fr)
                minmax(260px, .8fr);
            gap: 21px;
            padding: 23px;
        }

        .legal-intro h2 {
            margin: 0 0 8px;
            color: var(--legal-blue-dark);
            font-size: 1.1rem;
        }

        .legal-intro p {
            margin: 0;
            color: var(--legal-muted);
            font-size: .8rem;
            line-height: 1.6;
        }

        .legal-evidence {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 14px;
            border: 1px solid #dbeafe;
            border-radius: 13px;
            color: var(--legal-blue);
            background: #f8fbff;
            font-size: .73rem;
            line-height: 1.5;
        }

        .legal-doc-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            padding: 0 23px 23px;
        }

        .legal-doc {
            display: flex;
            flex-direction: column;
            min-width: 0;
            padding: 16px;
            border: 1px solid var(--legal-border);
            border-radius: 14px;
            background: #f8fafc;
        }

        .legal-doc-head {
            display: flex;
            align-items: flex-start;
            gap: 11px;
        }

        .legal-doc-icon {
            display: grid;
            flex: 0 0 40px;
            width: 40px;
            height: 40px;
            place-items: center;
            border-radius: 11px;
            color: var(--legal-blue);
            background: var(--legal-soft-blue);
        }

        .legal-doc h3 {
            margin: 0 0 5px;
            color: var(--legal-blue-dark);
            font-size: .88rem;
        }

        .legal-doc p {
            margin: 0;
            color: var(--legal-muted);
            font-size: .72rem;
            line-height: 1.5;
        }

        .legal-doc-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: auto;
            padding-top: 14px;
        }

        .legal-version {
            color: var(--legal-muted);
            font-size: .65rem;
        }

        .legal-open {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 36px;
            padding: 0 11px;
            border: 1px solid #bdcce2;
            border-radius: 9px;
            color: var(--legal-blue);
            background: #ffffff;
            cursor: pointer;
            font-size: .69rem;
            font-weight: 800;
        }

        .legal-warning {
            margin: 0 23px 21px;
            padding: 14px 15px;
            border: 1px solid #fde68a;
            border-radius: 13px;
            color: #78350f;
            background: #fffbeb;
        }

        .legal-warning strong {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .79rem;
        }

        .legal-warning p {
            margin: 6px 0 0;
            font-size: .71rem;
            line-height: 1.55;
        }

        .legal-form {
            padding: 20px 23px 23px;
            border-top: 1px solid #edf1f6;
        }

        .legal-error {
            margin-bottom: 14px;
            padding: 12px 13px;
            border: 1px solid #fecaca;
            border-radius: 11px;
            color: #991b1b;
            background: #fef2f2;
            font-size: .75rem;
            line-height: 1.5;
        }

        .legal-checks {
            display: grid;
            gap: 10px;
            margin-bottom: 16px;
        }

        .legal-check {
            display: flex;
            align-items: flex-start;
            gap: 11px;
            padding: 12px 13px;
            border: 1px solid var(--legal-border);
            border-radius: 12px;
            cursor: pointer;
        }

        .legal-check:hover {
            border-color: #afc3e4;
            background: #fbfdff;
        }

        .legal-check input {
            flex: 0 0 auto;
            width: 18px;
            height: 18px;
            margin-top: 2px;
            accent-color: var(--legal-blue);
        }

        .legal-check strong {
            display: block;
            font-size: .77rem;
        }

        .legal-check span span {
            display: block;
            margin-top: 3px;
            color: var(--legal-muted);
            font-size: .69rem;
            line-height: 1.45;
        }

        .legal-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }

        .legal-actions-note {
            color: var(--legal-muted);
            font-size: .67rem;
            line-height: 1.45;
        }

        .legal-accept {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-width: 215px;
            min-height: 46px;
            padding: 0 18px;
            border: 0;
            border-radius: 11px;
            color: #ffffff;
            background: var(--legal-blue);
            cursor: pointer;
            font-size: .79rem;
            font-weight: 850;
        }

        .legal-accept:disabled {
            cursor: not-allowed;
            opacity: .45;
        }

        .legal-admin {
            padding: 22px 23px 24px;
        }

        .legal-admin h2 {
            margin: 0 0 5px;
            color: var(--legal-blue-dark);
            font-size: 1.08rem;
        }

        .legal-admin > p {
            margin: 0 0 15px;
            color: var(--legal-muted);
            font-size: .74rem;
        }

        .legal-stats {
            display: grid;
            grid-template-columns:
                repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 13px;
        }

        .legal-stat {
            padding: 13px;
            border: 1px solid var(--legal-border);
            border-radius: 12px;
            background: #f8fafc;
        }

        .legal-stat span {
            display: block;
            color: var(--legal-muted);
            font-size: .65rem;
        }

        .legal-stat strong {
            display: block;
            margin-top: 4px;
            color: var(--legal-blue-dark);
            font-size: 1.2rem;
        }

        .legal-filters {
            display: grid;
            grid-template-columns:
                minmax(210px, 1fr)
                170px
                145px
                auto;
            gap: 8px;
            margin-bottom: 13px;
        }

        .legal-filters input,
        .legal-filters select {
            min-width: 0;
            min-height: 40px;
            padding: 0 11px;
            border: 1px solid var(--legal-border);
            border-radius: 9px;
            background: #ffffff;
            outline: none;
        }

        .legal-filters button {
            min-height: 40px;
            padding: 0 15px;
            border: 0;
            border-radius: 9px;
            color: #ffffff;
            background: var(--legal-blue);
            cursor: pointer;
            font-size: .73rem;
            font-weight: 800;
        }

        .legal-users {
            display: grid;
            gap: 9px;
        }

        .legal-user {
            display: grid;
            grid-template-columns:
                minmax(0, 1.2fr)
                minmax(120px, .45fr)
                minmax(190px, .7fr);
            align-items: center;
            gap: 13px;
            padding: 13px 14px;
            border: 1px solid var(--legal-border);
            border-radius: 12px;
            background: #ffffff;
        }

        .legal-person {
            min-width: 0;
        }

        .legal-person strong,
        .legal-person span {
            display: block;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .legal-person strong {
            font-size: .77rem;
        }

        .legal-person span {
            margin-top: 3px;
            color: var(--legal-muted);
            font-size: .66rem;
        }

        .legal-role {
            display: inline-flex;
            width: max-content;
            padding: 5px 8px;
            border-radius: 999px;
            color: #1e40af;
            background: #eff6ff;
            font-size: .61rem;
            font-weight: 800;
        }

        .legal-user-status strong {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: .69rem;
        }

        .legal-user-status span {
            display: block;
            margin-top: 4px;
            color: var(--legal-muted);
            font-size: .63rem;
        }

        .legal-user-status.ok strong {
            color: var(--legal-green);
        }

        .legal-user-status.pending strong {
            color: var(--legal-red);
        }

        .legal-pagination-summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 15px;
            color: var(--legal-muted);
            font-size: .67rem;
        }

        .legal-pagination {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 6px;
            margin-top: 10px;
        }

        .legal-pagination a {
            display: grid;
            min-width: 34px;
            height: 34px;
            place-items: center;
            border: 1px solid var(--legal-border);
            border-radius: 8px;
            color: var(--legal-blue);
            background: #ffffff;
            text-decoration: none;
            font-size: .67rem;
            font-weight: 800;
        }

        .legal-pagination a.active {
            color: #ffffff;
            background: var(--legal-blue);
        }

        .legal-pagination a.disabled {
            pointer-events: none;
            color: #94a3b8;
            background: #f8fafc;
            opacity: .68;
        }

        .legal-pagination a.pagination-wide {
            width: auto;
            padding: 0 11px;
        }

        .legal-modal {
            position: fixed;
            inset: 0;
            z-index: 100000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 18px;
            background: rgba(15, 23, 42, .66);
            backdrop-filter: blur(4px);
        }

        .legal-modal.open {
            display: flex;
        }

        .legal-dialog {
            display: flex;
            flex-direction: column;
            width: min(850px, 100%);
            max-height: min(87vh, 820px);
            overflow: hidden;
            border-radius: 18px;
            background: #ffffff;
            box-shadow:
                0 25px 80px
                rgba(0, 0, 0, .28);
        }

        .legal-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--legal-border);
        }

        .legal-modal-header h2 {
            margin: 0 0 3px;
            color: var(--legal-blue-dark);
            font-size: 1rem;
        }

        .legal-modal-header span {
            color: var(--legal-muted);
            font-size: .66rem;
        }

        .legal-close {
            display: grid;
            flex: 0 0 38px;
            width: 38px;
            height: 38px;
            place-items: center;
            border: 0;
            border-radius: 10px;
            color: #475569;
            background: #f1f5f9;
            cursor: pointer;
        }

        .legal-modal-body {
            overflow-y: auto;
            padding: 21px 22px 25px;
            overscroll-behavior: contain;
        }

        .legal-modal-body h2 {
            margin: 22px 0 8px;
            color: var(--legal-blue-dark);
            font-size: .92rem;
        }

        .legal-modal-body h2:first-child {
            margin-top: 0;
        }

        .legal-modal-body p,
        .legal-modal-body li {
            color: #475569;
            font-size: .77rem;
            line-height: 1.65;
        }

        .legal-modal-body p {
            margin: 0 0 9px;
        }

        .legal-modal-body ul {
            margin: 7px 0 12px;
            padding-left: 21px;
        }

        .legal-modal-body li {
            margin-bottom: 5px;
        }

        .legal-protection-block {
            margin: 18px 0;
            padding: 16px;
            border: 1px solid #bfdbfe;
            border-radius: 14px;
            background: #f8fbff;
        }

        .legal-protection-block h2 {
            margin-top: 0;
        }

        .legal-copy-warning {
            margin: 13px 0;
            padding: 14px;
            border: 1px solid #fde68a;
            border-radius: 12px;
            color: #78350f;
            background: #fffbeb;
        }

        .legal-copy-warning li {
            color: #78350f;
        }

        .legal-document-note {
            margin-top: 19px;
            padding: 12px 13px;
            border-radius: 10px;
            color: var(--legal-muted);
            background: #f8fafc;
            font-size: .67rem;
            line-height: 1.5;
        }

        .legal-loading {
            position: fixed;
            inset: 0;
            z-index: 100001;
            display: none;
            place-items: center;
            padding: 20px;
            background: rgba(243, 246, 250, .93);
        }

        .legal-loading.show {
            display: grid;
        }

        .legal-loading-box {
            min-width: 250px;
            padding: 25px;
            border: 1px solid var(--legal-border);
            border-radius: 16px;
            background: #ffffff;
            box-shadow:
                0 18px 45px
                rgba(15, 23, 42, .14);
            text-align: center;
        }

        .legal-loading-box i {
            color: var(--legal-blue);
            font-size: 1.7rem;
        }

        .legal-loading-box strong,
        .legal-loading-box span {
            display: block;
        }

        .legal-loading-box strong {
            margin-top: 12px;
            font-size: .83rem;
        }

        .legal-loading-box span {
            margin-top: 5px;
            color: var(--legal-muted);
            font-size: .69rem;
        }

        @media (max-width: 850px) {
            .legal-main {
                padding: 20px;
            }

            .legal-intro,
            .legal-doc-grid {
                grid-template-columns: 1fr;
            }

            .legal-user {
                grid-template-columns: 1fr 1fr;
            }

            .legal-person {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 620px) {
            .legal-main {
                padding: 74px 11px 25px;
            }

            .legal-header,
            .legal-actions {
                align-items: stretch;
                flex-direction: column;
            }

            .legal-status {
                width: max-content;
            }

            .legal-intro,
            .legal-admin {
                padding: 19px 17px;
            }

            .legal-doc-grid {
                padding: 0 17px 19px;
            }

            .legal-warning {
                margin: 0 17px 18px;
            }

            .legal-form {
                padding: 18px 17px 20px;
            }

            .legal-accept {
                width: 100%;
            }

            .legal-stats,
            .legal-filters,
            .legal-user {
                grid-template-columns: 1fr;
            }

            .legal-pagination-summary {
                align-items: flex-start;
                flex-direction: column;
            }

            .legal-person {
                grid-column: auto;
            }

            .legal-modal {
                padding: 8px;
            }

            .legal-dialog {
                max-height: 94vh;
                border-radius: 14px;
            }

            .legal-modal-header,
            .legal-modal-body {
                padding-right: 16px;
                padding-left: 16px;
            }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/includes/sidebar.php'; ?>

<main class="main-content legal-main">
    <div class="legal-wrapper">
        <header class="legal-header">
            <div>

                <h1>Aviso de privacidad y términos</h1>
            </div>

            <span
                class="legal-status <?php echo
                    $isCurrentLegal
                        ? 'ok'
                        : 'pending';
                ?>"
            >
                <i class="fas <?php echo
                    $isCurrentLegal
                        ? 'fa-circle-check'
                        : 'fa-clock';
                ?>"></i>

                <?php echo
                    $isCurrentLegal
                        ? 'Aceptación vigente'
                        : 'Aceptación pendiente';
                ?>
            </span>
        </header>

        <section class="legal-card">
            <div class="legal-intro">
                <div>
                    <h2>
                        Hola,
                        <?php echo legal_h($userNameLegal); ?>
                    </h2>

                    <p>
                        Los términos establecen las reglas de uso,
                        confidencialidad y protección de la aplicación.
                    </p>
                </div>
            </div>

            <div class="legal-doc-grid">
                <article class="legal-doc">
                    <div class="legal-doc-head">
                        <div class="legal-doc-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>

                        <div>
                            <h3>Aviso de privacidad</h3>

                            <p>
                                Tratamiento de datos, finalidades,
                                conservación, seguridad y derechos.
                            </p>
                        </div>
                    </div>

                    <div class="legal-doc-footer">
                        <span class="legal-version">
                            Versión
                            <?php echo legal_h(
                                $documentsLegal['aviso']['version']
                                ?? LEGAL_AVISO_VERSION
                            ); ?>
                        </span>

                        <button
                            type="button"
                            class="legal-open"
                            data-open-legal="legalPrivacyModal"
                        >
                            <i class="fas fa-eye"></i>
                            Leer aviso
                        </button>
                    </div>
                </article>

                <article class="legal-doc">
                    <div class="legal-doc-head">
                        <div class="legal-doc-icon">
                            <i class="fas fa-file-signature"></i>
                        </div>

                        <div>
                            <h3>Términos y condiciones</h3>

                            <p>
                                Uso autorizado, confidencialidad y
                                protección contra copia o clonación.
                            </p>
                        </div>
                    </div>

                    <div class="legal-doc-footer">
                        <span class="legal-version">
                            Versión
                            <?php echo legal_h(
                                $documentsLegal['terminos']['version']
                                ?? LEGAL_TERMINOS_VERSION
                            ); ?>
                        </span>

                        <button
                            type="button"
                            class="legal-open"
                            data-open-legal="legalTermsModal"
                        >
                            <i class="fas fa-eye"></i>
                            Leer términos
                        </button>
                    </div>
                </article>
            </div>

            <div class="legal-warning">
                <strong>
                    <i class="fas fa-copyright"></i>
                    Protección contra copia y uso no autorizado
                </strong>

                <p>
                    Los términos prohíben copiar o clonar el código, la
                    composición original de la interfaz, componentes,
                    plantillas, textos, reportes y materiales
                    confidenciales.
                </p>
            </div>

            <?php if ($errorLegal !== ''): ?>
                <div
                    class="legal-error"
                    style="margin: 0 23px 20px;"
                >
                    <i class="fas fa-circle-exclamation"></i>
                    <?php echo legal_h($errorLegal); ?>
                </div>
            <?php endif; ?>

            <?php if (!$isCurrentLegal && $dbLegal): ?>
                <form
                    method="POST"
                    class="legal-form"
                    id="legalAcceptanceForm"
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
                        value="<?php echo legal_h($returnUrl); ?>"
                    >

                    <div class="legal-checks">
                        <label class="legal-check">
                            <input
                                type="checkbox"
                                name="acepto_aviso"
                                id="legalCheckPrivacy"
                                value="1"
                            >

                            <span>
                                <strong>
                                    He leído el aviso de privacidad.
                                </strong>

                                <span>
                                    Conozco las finalidades, medidas de
                                    seguridad y medios para ejercer mis
                                    derechos.
                                </span>
                            </span>
                        </label>

                        <label class="legal-check">
                            <input
                                type="checkbox"
                                name="acepto_terminos"
                                id="legalCheckTerms"
                                value="1"
                            >

                            <span>
                                <strong>
                                    Acepto los términos y condiciones.
                                </strong>

                                <span>
                                    Utilizaré el sistema solo para mis
                                    funciones autorizadas y respetaré la
                                    confidencialidad y la propiedad
                                    intelectual.
                                </span>
                            </span>
                        </label>
                    </div>

                    <div class="legal-actions">
                        <span class="legal-actions-note">
                            Si no aceptas ambos documentos, el sistema
                            continuará bloqueando los demás módulos.
                        </span>

                        <button
                            type="submit"
                            class="legal-accept"
                            id="legalAcceptButton"
                            disabled
                        >
                            <i class="fas fa-check"></i>
                            Aceptar y continuar
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <?php if (
            legal_user_is_admin()
            && $isCurrentLegal
        ): ?>
            <section class="legal-card legal-admin">
                <h2>Control de aceptaciones</h2>

                <p>
                    Usuarios que aceptaron las versiones vigentes y
                    usuarios que todavía están pendientes.
                </p>

                <div class="legal-stats">
                    <article class="legal-stat">
                        <span>Usuarios registrados</span>
                        <strong>
                            <?php echo $totalUsersLegal; ?>
                        </strong>
                    </article>

                    <article class="legal-stat">
                        <span>Aceptación vigente</span>
                        <strong>
                            <?php echo $totalAcceptedLegal; ?>
                        </strong>
                    </article>

                    <article class="legal-stat">
                        <span>Pendientes</span>
                        <strong>
                            <?php echo $totalPendingLegal; ?>
                        </strong>
                    </article>
                </div>

                <form method="GET" class="legal-filters">
                    <input
                        type="hidden"
                        name="pagina"
                        value="1"
                    >
                    <input
                        type="search"
                        name="q"
                        placeholder="Buscar nombre o correo"
                        value="<?php echo legal_h(
                            (string) ($_GET['q'] ?? '')
                        ); ?>"
                    >

                    <select name="estado">
                        <option value="">
                            Todos los estados
                        </option>

                        <option
                            value="aceptado"
                            <?php echo
                                $statusLegal === 'aceptado'
                                    ? 'selected'
                                    : '';
                            ?>
                        >
                            Aceptación vigente
                        </option>

                        <option
                            value="pendiente"
                            <?php echo
                                $statusLegal === 'pendiente'
                                    ? 'selected'
                                    : '';
                            ?>
                        >
                            Pendiente
                        </option>
                    </select>

                    <select name="por_pagina">
                        <?php foreach ([5, 10, 20] as $optionPerPage): ?>
                            <option
                                value="<?php echo $optionPerPage; ?>"
                                <?php echo
                                    $perPageLegal === $optionPerPage
                                        ? 'selected'
                                        : '';
                                ?>
                            >
                                <?php echo $optionPerPage; ?> por página
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit">
                        <i class="fas fa-filter"></i>
                        Filtrar
                    </button>
                </form>

                <div class="legal-users">
                    <?php if (!$visibleUsersLegal): ?>
                        <div class="legal-error">
                            No se encontraron usuarios.
                        </div>
                    <?php else: ?>
                        <?php foreach (
                            $visibleUsersLegal
                            as $legalUser
                        ): ?>
                            <article class="legal-user">
                                <div class="legal-person">
                                    <strong>
                                        <?php echo legal_h(
                                            $legalUser['nombre']
                                        ); ?>
                                    </strong>

                                    <span>
                                        <?php echo legal_h(
                                            $legalUser['email']
                                        ); ?>
                                    </span>
                                </div>

                                <div>
                                    <span class="legal-role">
                                        <?php echo legal_h(
                                            ucfirst(
                                                (string) $legalUser['rol']
                                            )
                                        ); ?>
                                    </span>
                                </div>

                                <div
                                    class="legal-user-status <?php echo
                                        $legalUser['vigente']
                                            ? 'ok'
                                            : 'pending';
                                    ?>"
                                >
                                    <strong>
                                        <i class="fas <?php echo
                                            $legalUser['vigente']
                                                ? 'fa-circle-check'
                                                : 'fa-clock';
                                        ?>"></i>

                                        <?php echo
                                            $legalUser['vigente']
                                                ? 'Aceptación vigente'
                                                : 'Pendiente';
                                        ?>
                                    </strong>

                                    <span>
                                        <?php if (
                                            $legalUser['vigente']
                                            && !empty(
                                                $legalUser[
                                                    'fecha_aceptacion'
                                                ]
                                            )
                                        ): ?>
                                            <?php echo legal_h(
                                                date(
                                                    'd/m/Y H:i',
                                                    strtotime(
                                                        (string)
                                                        $legalUser[
                                                            'fecha_aceptacion'
                                                        ]
                                                    )
                                                )
                                            ); ?>

                                            
                                        <?php else: ?>
                                            Debe aceptar al ingresar
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="legal-pagination-summary">
                    <span>
                        Mostrando
                        <strong><?php echo $firstVisibleLegal; ?></strong>
                        a
                        <strong><?php echo $lastVisibleLegal; ?></strong>
                        de
                        <strong><?php echo $totalFilteredLegal; ?></strong>
                        usuarios
                    </span>

                    <span>
                        Página
                        <strong><?php echo $pageLegal; ?></strong>
                        de
                        <strong><?php echo $totalPagesLegal; ?></strong>
                    </span>
                </div>

                <?php if ($totalPagesLegal > 1): ?>
                    <?php
                    $windowStartLegal = max(1, $pageLegal - 2);
                    $windowEndLegal = min(
                        $totalPagesLegal,
                        $pageLegal + 2
                    );
                    ?>

                    <nav
                        class="legal-pagination"
                        aria-label="Paginación de aceptaciones"
                    >
                        <a
                            href="<?php echo $pageLegal > 1
                                ? legal_h(
                                    legal_page_url(
                                        $pageLegal - 1
                                    )
                                )
                                : '#'; ?>"
                            class="pagination-wide <?php echo
                                $pageLegal <= 1
                                    ? 'disabled'
                                    : '';
                            ?>"
                        >
                            <i class="fas fa-chevron-left"></i>
                            Anterior
                        </a>

                        <?php if ($windowStartLegal > 1): ?>
                            <a href="<?php echo legal_h(
                                legal_page_url(1)
                            ); ?>">
                                1
                            </a>

                            <?php if ($windowStartLegal > 2): ?>
                                <a class="disabled" href="#">…</a>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for (
                            $i = $windowStartLegal;
                            $i <= $windowEndLegal;
                            $i++
                        ): ?>
                            <a
                                href="<?php echo legal_h(
                                    legal_page_url($i)
                                ); ?>"
                                class="<?php echo
                                    $i === $pageLegal
                                        ? 'active'
                                        : '';
                                ?>"
                                <?php echo $i === $pageLegal
                                    ? 'aria-current="page"'
                                    : ''; ?>
                            >
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if (
                            $windowEndLegal
                            < $totalPagesLegal
                        ): ?>
                            <?php if (
                                $windowEndLegal
                                < $totalPagesLegal - 1
                            ): ?>
                                <a class="disabled" href="#">…</a>
                            <?php endif; ?>

                            <a href="<?php echo legal_h(
                                legal_page_url(
                                    $totalPagesLegal
                                )
                            ); ?>">
                                <?php echo $totalPagesLegal; ?>
                            </a>
                        <?php endif; ?>

                        <a
                            href="<?php echo
                                $pageLegal < $totalPagesLegal
                                    ? legal_h(
                                        legal_page_url(
                                            $pageLegal + 1
                                        )
                                    )
                                    : '#';
                            ?>"
                            class="pagination-wide <?php echo
                                $pageLegal >= $totalPagesLegal
                                    ? 'disabled'
                                    : '';
                            ?>"
                        >
                            Siguiente
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </nav>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</main>

<div
    class="legal-modal"
    id="legalPrivacyModal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="legalPrivacyTitle"
>
    <div class="legal-dialog">
        <header class="legal-modal-header">
            <div>
                <h2 id="legalPrivacyTitle">
                    Aviso de privacidad
                </h2>

                <span>
                    Versión
                    <?php echo legal_h(
                        $documentsLegal['aviso']['version']
                        ?? LEGAL_AVISO_VERSION
                    ); ?>
                    · <?php echo legal_h(LEGAL_VERSION_FECHA); ?>
                </span>
            </div>

            <button
                type="button"
                class="legal-close"
                data-close-legal
                aria-label="Cerrar aviso"
            >
                <i class="fas fa-xmark"></i>
            </button>
        </header>

        <div class="legal-modal-body">
            <?php echo
                $documentsLegal['aviso']['content']
                ?? '';
            ?>
        </div>
    </div>
</div>

<div
    class="legal-modal"
    id="legalTermsModal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="legalTermsTitle"
>
    <div class="legal-dialog">
        <header class="legal-modal-header">
            <div>
                <h2 id="legalTermsTitle">
                    Términos y condiciones
                </h2>

                <span>
                    Versión
                    <?php echo legal_h(
                        $documentsLegal['terminos']['version']
                        ?? LEGAL_TERMINOS_VERSION
                    ); ?>
                    · <?php echo legal_h(LEGAL_VERSION_FECHA); ?>
                </span>
            </div>

            <button
                type="button"
                class="legal-close"
                data-close-legal
                aria-label="Cerrar términos"
            >
                <i class="fas fa-xmark"></i>
            </button>
        </header>

        <div class="legal-modal-body">
            <?php echo
                $documentsLegal['terminos']['content']
                ?? '';
            ?>
        </div>
    </div>
</div>

<div class="legal-loading" id="legalLoading">
    <div class="legal-loading-box">
        <i class="fas fa-spinner fa-spin"></i>
        <strong>Guardando aceptación</strong>
        <span>
            Espera mientras registramos los documentos aceptados.
        </span>
    </div>
</div>

<script>
(function () {
    const body = document.body;
    let previousPaddingRight = '';

    function openLegalModal(modal) {
        if (!modal) {
            return;
        }

        const scrollbarWidth =
            window.innerWidth
            - document.documentElement.clientWidth;

        previousPaddingRight = body.style.paddingRight;

        if (scrollbarWidth > 0) {
            body.style.paddingRight =
                scrollbarWidth + 'px';
        }

        body.classList.add('legal-modal-open');
        modal.classList.add('open');

        const close = modal.querySelector(
            '[data-close-legal]'
        );

        if (close) {
            close.focus();
        }
    }

    function closeLegalModal(modal) {
        if (!modal) {
            return;
        }

        modal.classList.remove('open');
        body.classList.remove('legal-modal-open');
        body.style.paddingRight = previousPaddingRight;
    }

    document.querySelectorAll(
        '[data-open-legal]'
    ).forEach(function (button) {
        button.addEventListener('click', function () {
            openLegalModal(
                document.getElementById(
                    button.dataset.openLegal
                )
            );
        });
    });

    document.querySelectorAll(
        '[data-close-legal]'
    ).forEach(function (button) {
        button.addEventListener('click', function () {
            closeLegalModal(
                button.closest('.legal-modal')
            );
        });
    });

    document.querySelectorAll(
        '.legal-modal'
    ).forEach(function (modal) {
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeLegalModal(modal);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        const openModal = document.querySelector(
            '.legal-modal.open'
        );

        if (openModal) {
            closeLegalModal(openModal);
        }
    });

    const privacyCheck = document.getElementById(
        'legalCheckPrivacy'
    );

    const termsCheck = document.getElementById(
        'legalCheckTerms'
    );

    const acceptButton = document.getElementById(
        'legalAcceptButton'
    );

    const form = document.getElementById(
        'legalAcceptanceForm'
    );

    function syncLegalButton() {
        if (
            !privacyCheck
            || !termsCheck
            || !acceptButton
        ) {
            return;
        }

        acceptButton.disabled = !(
            privacyCheck.checked
            && termsCheck.checked
        );
    }

    if (privacyCheck && termsCheck) {
        privacyCheck.addEventListener(
            'change',
            syncLegalButton
        );

        termsCheck.addEventListener(
            'change',
            syncLegalButton
        );
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            if (
                !privacyCheck.checked
                || !termsCheck.checked
            ) {
                event.preventDefault();
                syncLegalButton();
                return;
            }

            acceptButton.disabled = true;

            document.getElementById(
                'legalLoading'
            ).classList.add('show');
        });
    }
})();
</script>
</body>
</html>