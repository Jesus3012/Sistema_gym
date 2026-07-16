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

$rutasPermitidasPorRol = [
    'admin' => [
        'dashboard.php',
        'productos.php',
        'historial_stock.php',
        'ventas.php',
        'historial_ventas.php',
        'inscripciones.php',
        'asistencias.php',
        'clases.php',
        'inscripciones_clases.php',
        'reportes.php',
        'notificaciones.php',
        'configuracion.php',
        'mi_perfil.php',
        'corte_caja.php',
        'corte_caja_detalle.php',
        'solicitudes_usuarios.php',
        'legal.php',
    ],

    'recepcionista' => [
        'dashboard.php',
        'inscripciones.php',
        'asistencias.php',
        'reportes.php',
        'ventas.php',
        'historial_ventas.php',
        'mi_perfil.php',
        'legal.php',
    ],

    'entrenador' => [
        'dashboard.php',
        'clases.php',
        'inscripciones_clases.php',
        'asistencias.php',
        'mi_perfil.php',
        'legal.php',
    ],
];

$nombresRoles = [
    'admin' => 'Administrador',
    'recepcionista' => 'Recepcionista',
    'entrenador' => 'Entrenador',
];

$nombresModulos = [
    'dashboard.php' => 'Dashboard',
    'productos.php' => 'Productos',
    'historial_stock.php' => 'Historial de stock',
    'ventas.php' => 'Venta de productos',
    'historial_ventas.php' => 'Historial de ventas',
    'inscripciones.php' => 'Inscripciones',
    'asistencias.php' => 'Asistencias',
    'clases.php' => 'Clases',
    'inscripciones_clases.php' => 'Inscripciones a clases',
    'reportes.php' => 'Reportes',
    'notificaciones.php' => 'Notificaciones',
    'configuracion.php' => 'Configuración',
    'mi_perfil.php' => 'Mi perfil',
    'corte_caja.php' => 'Corte de caja',
    'corte_caja_detalle.php' => 'Detalle del corte de caja',
    'solicitudes_usuarios.php' => 'Solicitudes de usuarios',
    'legal.php' => 'Aviso y términos',
];

if (!array_key_exists($rolActual, $rutasPermitidasPorRol)) {
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

if (
    !in_array(
        $paginaActual,
        $rutasPermitidasPorRol[$rolActual],
        true
    )
) {
    $nombreRol = $nombresRoles[$rolActual]
        ?? ucfirst($rolActual);

    $nombreModulo = $nombresModulos[$paginaActual]
        ?? 'el módulo solicitado';

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