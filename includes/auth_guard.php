<?php
// Archivo: includes/auth_guard.php
// Debe incluirse como la PRIMERA instrucción de cada página protegida.

// La sucursal se valida antes del rol y de los permisos, porque el rol
// efectivo del mismo usuario puede cambiar entre sedes.

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

function destruirSesionProtegida(): void
{
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
$panelEntrenadorUrl = $baseUrl . '/panel_entrenador.php';

if (empty($_SESSION['user_id'])) {
    redirigirSeguramente(
        $loginUrl . '?error=sesion_requerida'
    );
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/sucursal_context.php';
require_once __DIR__ . '/permisos_helper.php';

$databasePermisos = new Database();
$connPermisos = $databasePermisos->getConnection();

if (!$connPermisos instanceof mysqli) {
    destruirSesionProtegida();

    redirigirSeguramente(
        $loginUrl . '?error=conexion_sucursal'
    );
}

$connPermisos->set_charset('utf8mb4');

try {
    /*
     * Revalida usuario, asignación, estado de la sede y rol efectivo.
     * Nunca se confía únicamente en sucursal_id guardado en sesión.
     */
    $sucursalActual = sucursal_inicializar_sesion(
        $connPermisos
    );
} catch (Throwable $sucursalException) {
    error_log(
        '[Auth guard sucursal] '
        . $sucursalException->getMessage()
    );

    destruirSesionProtegida();

    redirigirSeguramente(
        $loginUrl . '?error=sin_sucursal'
    );
}

// Variables disponibles para cualquier página protegida.
$sucursal_id = sucursal_id_actual();
$sucursal_nombre = sucursal_nombre_actual();

// Se actualiza después de cargar la sede porque sucursal_context.php coloca
// aquí el rol efectivo correspondiente a la sucursal activa.
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
    destruirSesionProtegida();

    redirigirSeguramente(
        $loginUrl . '?error=rol_invalido'
    );
}

/*
 * El entrenador cuenta con un espacio operativo exclusivo.
 * Aunque posteriormente se modifiquen los permisos por rol, nunca podrá
 * entrar a los módulos administrativos de clases, socios, ventas o caja.
 */
$esPanelEntrenador = $paginaActual === 'panel_entrenador.php';

if ($rolActual === 'entrenador') {
    $rutasEntrenador = [
        'panel_entrenador.php',
        'legal.php',
        'mi_perfil.php',
    ];

    if (!in_array($paginaActual, $rutasEntrenador, true)) {
        $_SESSION['mensaje_acceso'] =
            'Tu cuenta de entrenador utiliza exclusivamente Mi agenda de clases.';

        redirigirSeguramente($panelEntrenadorUrl);
    }
}

$_SESSION['last_activity'] = time();

$claveModuloActual = $esPanelEntrenador
    ? 'panel_entrenador'
    : permisos_modulo_por_pagina($paginaActual);

$nombreModuloConsultado = $esPanelEntrenador
    ? 'Mi agenda de clases'
    : '';

/*
 * Permite registrar nuevos módulos desde modulos_sistema sin tener que
 * editar inmediatamente el mapa fijo de permisos_helper.php.
 */
if ($claveModuloActual === null) {
    $stmtModuloRuta = $connPermisos->prepare(
        "SELECT clave, nombre
         FROM modulos_sistema
         WHERE ruta = ?
           AND activo = 1
         LIMIT 1"
    );

    if ($stmtModuloRuta) {
        $stmtModuloRuta->bind_param('s', $paginaActual);
        $stmtModuloRuta->execute();
        $resultadoModuloRuta = $stmtModuloRuta->get_result();
        $moduloRuta = $resultadoModuloRuta
            ? $resultadoModuloRuta->fetch_assoc()
            : null;
        $stmtModuloRuta->close();

        if ($moduloRuta) {
            $claveModuloActual = trim((string) ($moduloRuta['clave'] ?? ''));
            $nombreModuloConsultado = trim((string) ($moduloRuta['nombre'] ?? ''));
        }
    }
}

$esAdministradorActual = in_array(
    $rolActual,
    ['admin', 'administrador'],
    true
);

$accesoPermitido =
    ($rolActual === 'entrenador' && $esPanelEntrenador)
    || (
        $claveModuloActual !== null
        && $claveModuloActual !== ''
        && (
            $esAdministradorActual
            || permisos_rol_tiene_modulo(
                $connPermisos,
                $rolActual,
                $claveModuloActual
            )
        )
    );

if (!$accesoPermitido) {
    $nombreRol = $nombresRoles[$rolActual]
        ?? ucfirst($rolActual);

    $nombreModulo = $nombreModuloConsultado !== ''
        ? $nombreModuloConsultado
        : ($claveModuloActual !== null
            ? permisos_nombre_modulo($claveModuloActual)
            : 'el módulo solicitado');

    $_SESSION['alerta_acceso_denegado'] = [
        'titulo' => 'Acceso restringido',
        'mensaje' =>
            "Tu perfil de {$nombreRol} en {$sucursal_nombre} no tiene permiso para ingresar a {$nombreModulo}.",
        'rol' => $nombreRol,
        'modulo' => $nombreModulo,
        'sucursal' => $sucursal_nombre,
    ];

    $_SESSION['mensaje_acceso'] =
        'No tienes permisos para acceder a ese módulo en la sucursal activa.';

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