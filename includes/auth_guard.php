<?php
// Archivo: includes/auth_guard.php
// Debe incluirse como la PRIMERA instrucción de cada página protegida.

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function redirigirSeguramente(string $destino): void
{
    if (!headers_sent()) {
        header('Location: ' . $destino);
        exit();
    }

    http_response_code(403);

    $destinoJson = json_encode(
        $destino,
        JSON_HEX_TAG
        | JSON_HEX_APOS
        | JSON_HEX_AMP
        | JSON_HEX_QUOT
    );

    $destinoHtml = htmlspecialchars(
        $destino,
        ENT_QUOTES,
        'UTF-8'
    );

    echo '<script>window.location.replace('
        . $destinoJson
        . ');</script>';

    echo '<noscript><meta http-equiv="refresh" content="0;url='
        . $destinoHtml
        . '"></noscript>';

    exit();
}

$scriptName = str_replace(
    '\\',
    '/',
    (string) ($_SERVER['SCRIPT_NAME'] ?? '')
);

$baseUrl = rtrim(
    str_replace('\\', '/', dirname($scriptName)),
    '/'
);

if ($baseUrl === '.' || $baseUrl === '/') {
    $baseUrl = '';
}

$loginUrl = $baseUrl . '/login.php';
$dashboardUrl = $baseUrl . '/dashboard.php';

if (empty($_SESSION['user_id'])) {
    redirigirSeguramente(
        $loginUrl . '?error=sesion_requerida'
    );
}

$rolActual = strtolower(
    trim((string) ($_SESSION['user_rol'] ?? ''))
);

$paginaActual = basename(
    (string) parse_url(
        $_SERVER['PHP_SELF'] ?? '',
        PHP_URL_PATH
    )
);

$nombresRoles = [
    'admin' => 'Administrador',
    'administrador' => 'Administrador',
    'recepcionista' => 'Recepcionista',
    'entrenador' => 'Entrenador',
];

if (!array_key_exists($rolActual, $nombresRoles)) {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }

    session_destroy();

    redirigirSeguramente(
        $loginUrl . '?error=rol_invalido'
    );
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/permisos_helper.php';

$databasePermisos = new Database();
$connPermisos = $databasePermisos->getConnection();

if ($connPermisos) {
    $connPermisos->set_charset('utf8mb4');
}

$claveModuloActual = permisos_modulo_por_pagina(
    $paginaActual
);

$accesoPermitido = $claveModuloActual !== null
    && permisos_rol_tiene_modulo(
        $connPermisos,
        $rolActual,
        $claveModuloActual
    );

if (!$accesoPermitido) {
    $nombreRol = $nombresRoles[$rolActual]
        ?? ucfirst($rolActual);

    $nombreModulo = $claveModuloActual !== null
        ? permisos_nombre_modulo($claveModuloActual)
        : 'el módulo solicitado';

    $_SESSION['alerta_acceso_denegado'] = [
        'titulo' => 'Acceso restringido',
        'mensaje' =>
            "Tu perfil de {$nombreRol} no tiene permiso para ingresar a {$nombreModulo}.",
        'rol' => $nombreRol,
        'modulo' => $nombreModulo,
    ];

    $_SESSION['mensaje_acceso'] =
        'No tienes permisos para acceder a ese módulo.';

    redirigirSeguramente(
        $dashboardUrl . '?error=acceso_denegado'
    );
}

/*
 * Debe permanecer al final. El guard legal permite cargar el dashboard
 * para mostrar la aceptación obligatoria y bloquea los demás módulos.
 */
require_once __DIR__ . '/legal_guard.php';
legal_require_acceptance();
