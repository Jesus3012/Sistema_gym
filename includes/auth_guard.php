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

function obtenerBaseUrlSistema(): string
{
    $documentRootReal = realpath(
        (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')
    );

    $raizSistemaReal = realpath(__DIR__ . '/..');

    if ($documentRootReal !== false && $raizSistemaReal !== false) {
        $documentRoot = rtrim(
            str_replace('\\', '/', $documentRootReal),
            '/'
        );

        $raizSistema = rtrim(
            str_replace('\\', '/', $raizSistemaReal),
            '/'
        );

        if (
            $documentRoot !== ''
            && strncasecmp(
                $raizSistema,
                $documentRoot,
                strlen($documentRoot)
            ) === 0
        ) {
            $rutaRelativa = substr(
                $raizSistema,
                strlen($documentRoot)
            );

            $baseUrl = '/' . ltrim(
                str_replace('\\', '/', $rutaRelativa),
                '/'
            );

            $baseUrl = rtrim($baseUrl, '/');

            return $baseUrl === '/' ? '' : $baseUrl;
        }
    }

    $scriptName = str_replace(
        '\\',
        '/',
        (string) ($_SERVER['SCRIPT_NAME'] ?? '')
    );

    foreach (['/includes/', '/api/'] as $segmentoInterno) {
        $posicion = strpos($scriptName, $segmentoInterno);

        if ($posicion !== false) {
            return rtrim(
                substr($scriptName, 0, $posicion),
                '/'
            );
        }
    }

    $baseUrl = rtrim(
        str_replace('\\', '/', dirname($scriptName)),
        '/'
    );

    return ($baseUrl === '.' || $baseUrl === '/')
        ? ''
        : $baseUrl;
}

$baseUrl = obtenerBaseUrlSistema();

$loginUrl = $baseUrl . '/login.php';
$dashboardUrl = $baseUrl . '/dashboard.php';
$panelEntrenadorUrl = $baseUrl . '/panel_entrenador.php';
$servicioVencidoUrl = $baseUrl . '/servicio_vencido.php';

if (empty($_SESSION['user_id'])) {
    redirigirSeguramente(
        $loginUrl . '?error=sesion_requerida'
    );
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/super_admin_helper.php';
require_once __DIR__ . '/sucursal_context.php';
require_once __DIR__ . '/permisos_helper.php';
require_once __DIR__ . '/servicio_plataforma_helper.php';

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

$sucursal_id = sucursal_id_actual();
$sucursal_nombre = sucursal_nombre_actual();

$rolActual = rol_normalizar_sistema((string) (
    $_SESSION['user_rol'] ?? ''
));
$rolBaseReal = rol_base_real_sesion();
$esSuperAdministradorReal =
    $rolBaseReal === 'super_administrador';

$paginaActual = basename(
    (string) parse_url(
        $_SERVER['PHP_SELF'] ?? '',
        PHP_URL_PATH
    )
);

$rutasInternasPorModulo = [
    'inscripcion_detalle.php' => 'inscripciones.php',
    'inscripcion_detalle_historial.php' => 'inscripciones.php',
    'ver_documento_inscripcion.php' => 'inscripciones.php',
];

$paginaParaPermisos = $rutasInternasPorModulo[$paginaActual]
    ?? $paginaActual;

$nombresRoles = [
    'super_administrador' => 'Superadministrador',
    'admin' => 'Administrador',
    'administrador' => 'Administrador',
    'recepcionista' => 'Recepcionista',
    'entrenador' => 'Entrenador',
];

if (
    !array_key_exists($rolActual, $nombresRoles)
    && !array_key_exists($rolBaseReal, $nombresRoles)
) {
    destruirSesionProtegida();

    redirigirSeguramente(
        $loginUrl . '?error=rol_invalido'
    );
}

/*
 * Este módulo es más restringido que los módulos solo_admin: únicamente
 * puede abrirlo el rol real super_administrador. El rol operativo puede
 * continuar normalizado como admin para el resto del sistema.
 */
if (
    $paginaParaPermisos === 'servicio_plataforma.php'
    && !$esSuperAdministradorReal
) {
    $_SESSION['alerta_acceso_denegado'] = [
        'titulo' => 'Acceso exclusivo',
        'mensaje' =>
            'Solo el superadministrador puede administrar la vigencia del servicio.',
        'rol' => $nombresRoles[$rolBaseReal]
            ?? $nombresRoles[$rolActual]
            ?? 'Usuario',
        'modulo' => 'Servicio de plataforma',
        'sucursal' => $sucursal_nombre,
    ];

    redirigirSeguramente(
        $dashboardUrl . '?error=acceso_denegado'
    );
}

$esPanelEntrenador = $paginaParaPermisos === 'panel_entrenador.php';
$esPantallaServicioVencido =
    $paginaParaPermisos === 'servicio_vencido.php';

if ($rolActual === 'entrenador') {
    $rutasEntrenador = [
        'panel_entrenador.php',
        'legal.php',
        'mi_perfil.php',
        'servicio_vencido.php',
    ];

    if (!in_array($paginaParaPermisos, $rutasEntrenador, true)) {
        $_SESSION['mensaje_acceso'] =
            'Tu cuenta de entrenador utiliza exclusivamente Mi agenda de clases.';

        redirigirSeguramente($panelEntrenadorUrl);
    }
}

$_SESSION['last_activity'] = time();

$claveModuloActual = $esPanelEntrenador
    ? 'panel_entrenador'
    : permisos_modulo_por_pagina($paginaParaPermisos);

$nombreModuloConsultado = $esPanelEntrenador
    ? 'Mi agenda de clases'
    : '';

if ($claveModuloActual === null && !$esPantallaServicioVencido) {
    $stmtModuloRuta = $connPermisos->prepare(
        "SELECT clave, nombre
         FROM modulos_sistema
         WHERE ruta = ?
           AND activo = 1
         LIMIT 1"
    );

    if ($stmtModuloRuta) {
        $stmtModuloRuta->bind_param('s', $paginaParaPermisos);
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

$esAdministradorActual = rol_es_administrativo($rolBaseReal)
    || rol_es_administrativo($rolActual);

$accesoPermitido =
    $esPantallaServicioVencido
    || ($rolActual === 'entrenador' && $esPanelEntrenador)
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
    /*
     * Nunca se redirige dashboard.php hacia sí mismo. Si una sesión quedó
     * inconsistente después de cambiar un rol, se cierra y se vuelve al
     * login en lugar de generar ERR_TOO_MANY_REDIRECTS.
     */
    if ($paginaParaPermisos === 'dashboard.php') {
        destruirSesionProtegida();

        redirigirSeguramente(
            $loginUrl . '?error=rol_actualizado'
        );
    }

    $rolMensaje = $rolBaseReal !== ''
        ? $rolBaseReal
        : $rolActual;

    $nombreRol = $nombresRoles[$rolMensaje]
        ?? ucfirst($rolMensaje);

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
 * El superadministrador siempre conserva acceso para renovar o corregir
 * el servicio. Los demás perfiles se envían a una pantalla informativa
 * únicamente cuando el servicio está suspendido o venció con bloqueo activo.
 * Si todavía no se ejecutó la migración, el sistema sigue funcionando.
 */
if (!$esSuperAdministradorReal && !$esPantallaServicioVencido) {
    try {
        $estadoServicioPlataforma =
            servicio_plataforma_resumen($connPermisos);

        if (!empty($estadoServicioPlataforma['debe_bloquear'])) {
            redirigirSeguramente($servicioVencidoUrl);
        }
    } catch (Throwable $servicioException) {
        error_log(
            '[Auth guard servicio plataforma] '
            . $servicioException->getMessage()
        );
    }
}

/*
 * La pantalla de servicio vencido no carga el modal legal porque debe
 * permanecer simple y permitir únicamente consultar la renovación o salir.
 */
if (!$esPantallaServicioVencido) {
    require_once __DIR__ . '/legal_guard.php';
    legal_require_acceptance();
}
