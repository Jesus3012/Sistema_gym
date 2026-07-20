<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth_guard = __DIR__ . '/includes/auth_guard.php';

if (is_file($auth_guard)) {
    require_once $auth_guard;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/sucursal_context.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$usuario_actual_id = (int) $_SESSION['user_id'];

$rol_actual = strtolower(trim((string) (
    $_SESSION['user_rol_base']
    ?? $_SESSION['user_rol']
    ?? ''
)));

if (!in_array($rol_actual, ['administrador', 'admin'], true)) {
    $_SESSION['alerta_acceso_denegado'] = [
        'titulo' => 'Acceso restringido',
        'mensaje' =>
            'Solo un administrador puede revisar y autorizar solicitudes de usuarios.',
        'rol' => ucfirst($rol_actual ?: 'Sin rol'),
        'modulo' => 'Solicitudes de usuarios',
    ];

    header('Location: dashboard.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

if (!$db instanceof mysqli) {
    http_response_code(500);
    exit('No fue posible conectar con la base de datos.');
}

$db->set_charset('utf8mb4');

if (function_exists('sucursal_inicializar_sesion')) {
    sucursal_inicializar_sesion($db);
}

$vista_solicitada = strtolower(trim((string) (
    $_GET['vista'] ?? ''
)));

if ($vista_solicitada === 'global') {
    sucursal_activar_vista_global(
        $db,
        $usuario_actual_id
    );
} elseif ($vista_solicitada === 'sucursal') {
    sucursal_desactivar_vista_global();
}

$vista_global =
    function_exists('sucursal_dashboard_vista_global')
    && sucursal_dashboard_vista_global();

$sucursales = sucursal_obtener_asignadas(
    $db,
    $usuario_actual_id
);

/*
 * Compatibilidad con administradores antiguos que todavía no tengan
 * registros en usuarios_sucursales.
 */
if ($sucursales === []) {
    $resultado_sucursales = $db->query(
        "SELECT
            id,
            clave,
            nombre,
            es_matriz,
            estado
         FROM sucursales
         WHERE estado = 'activa'
         ORDER BY es_matriz DESC, nombre ASC"
    );

    if ($resultado_sucursales) {
        while (
            $sucursal = $resultado_sucursales->fetch_assoc()
        ) {
            $sucursales[] = $sucursal;
        }
    }
}

$sucursales_mapa = [];

foreach ($sucursales as $sucursal) {
    $id_sucursal = (int) ($sucursal['id'] ?? 0);

    if ($id_sucursal <= 0) {
        continue;
    }

    $sucursales_mapa[$id_sucursal] = [
        'id' => $id_sucursal,
        'clave' => trim((string) (
            $sucursal['clave'] ?? ''
        )),
        'nombre' => trim((string) (
            $sucursal['nombre'] ?? 'Sucursal'
        )),
        'es_matriz' =>
            (int) ($sucursal['es_matriz'] ?? 0) === 1,
    ];
}

$sucursal_actual_id = (int) (
    $_SESSION['sucursal_id'] ?? 0
);

$sucursal_actual = $sucursales_mapa[
    $sucursal_actual_id
] ?? null;

if (
    !$vista_global
    && !$sucursal_actual
    && $sucursales_mapa !== []
) {
    $sucursal_actual = reset($sucursales_mapa);
    $sucursal_actual_id = (int) $sucursal_actual['id'];
}

$contexto_nombre = $vista_global
    ? 'Todas las sucursales'
    : (
        $sucursal_actual['nombre']
        ?? 'Sucursal no seleccionada'
    );

$contexto_detalle = $vista_global
    ? count($sucursales_mapa)
        . (
            count($sucursales_mapa) === 1
                ? ' sede disponible'
                : ' sedes disponibles'
        )
    : (
        ($sucursal_actual['clave'] ?? 'Sin clave')
        . (
            !empty($sucursal_actual['es_matriz'])
                ? ' · Matriz'
                : ' · Sucursal'
        )
    );

if (empty($_SESSION['solicitudes_csrf'])) {
    $_SESSION['solicitudes_csrf'] =
        bin2hex(random_bytes(32));
}

$mensaje = (string) (
    $_SESSION['solicitudes_mensaje'] ?? ''
);
$tipo_mensaje = (string) (
    $_SESSION['solicitudes_tipo'] ?? ''
);

unset(
    $_SESSION['solicitudes_mensaje'],
    $_SESSION['solicitudes_tipo']
);

function solicitudesFlash(
    string $mensaje,
    string $tipo
): void {
    $_SESSION['solicitudes_mensaje'] = $mensaje;
    $_SESSION['solicitudes_tipo'] = $tipo;
}

function solicitudesRedirigir(
    bool $vistaGlobal
): void {
    header(
        'Location: solicitudes_usuarios.php?vista='
        . ($vistaGlobal ? 'global' : 'sucursal')
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $usuario_id = filter_input(
        INPUT_POST,
        'usuario_id',
        FILTER_VALIDATE_INT
    );
    $accion = strtolower(trim((string) (
        $_POST['accion'] ?? ''
    )));

    if (
        $csrf === ''
        || !hash_equals(
            (string) $_SESSION['solicitudes_csrf'],
            $csrf
        )
    ) {
        solicitudesFlash(
            'La solicitud no es válida. Actualiza la página e inténtalo nuevamente.',
            'error'
        );

        solicitudesRedirigir($vista_global);
    }

    if (
        !$usuario_id
        || !in_array(
            $accion,
            ['aprobar', 'rechazar'],
            true
        )
    ) {
        solicitudesFlash(
            'No fue posible identificar la solicitud seleccionada.',
            'error'
        );

        solicitudesRedirigir($vista_global);
    }

    $transaccion_iniciada = false;

    try {
        $db->begin_transaction();
        $transaccion_iniciada = true;

        $stmt_usuario = $db->prepare(
            "SELECT
                id,
                nombre,
                email,
                rol,
                estado
             FROM usuarios
             WHERE id = ?
             LIMIT 1
             FOR UPDATE"
        );

        if (!$stmt_usuario) {
            throw new RuntimeException(
                'No fue posible consultar la solicitud.'
            );
        }

        $stmt_usuario->bind_param(
            'i',
            $usuario_id
        );
        $stmt_usuario->execute();

        $usuario_pendiente = $stmt_usuario
            ->get_result()
            ->fetch_assoc();

        $stmt_usuario->close();

        if (!$usuario_pendiente) {
            throw new RuntimeException(
                'La solicitud seleccionada ya no existe.'
            );
        }

        if (
            $usuario_pendiente['estado'] !== 'pendiente'
        ) {
            throw new RuntimeException(
                'La solicitud ya había sido procesada.'
            );
        }

        $rol_usuario = strtolower(trim((string) (
            $usuario_pendiente['rol'] ?? ''
        )));

        if (
            !in_array(
                $rol_usuario,
                ['recepcionista', 'entrenador'],
                true
            )
        ) {
            throw new RuntimeException(
                'El rol solicitado no puede aprobarse desde este módulo.'
            );
        }

        if ($accion === 'rechazar') {
            $estado_rechazado = 'rechazado';

            $stmt_rechazar = $db->prepare(
                "UPDATE usuarios
                 SET estado = ?
                 WHERE id = ?
                   AND estado = 'pendiente'"
            );

            if (!$stmt_rechazar) {
                throw new RuntimeException(
                    'No fue posible preparar el rechazo.'
                );
            }

            $stmt_rechazar->bind_param(
                'si',
                $estado_rechazado,
                $usuario_id
            );
            $stmt_rechazar->execute();

            if ($stmt_rechazar->affected_rows !== 1) {
                throw new RuntimeException(
                    'La solicitud dejó de estar disponible.'
                );
            }

            $stmt_rechazar->close();

            /*
             * Respaldo para solicitudes creadas incorrectamente con una
             * asignación previa.
             */
            $stmt_desactivar = $db->prepare(
                "UPDATE usuarios_sucursales
                 SET
                    estado = 'inactivo',
                    es_principal = 0
                 WHERE usuario_id = ?"
            );

            if ($stmt_desactivar) {
                $stmt_desactivar->bind_param(
                    'i',
                    $usuario_id
                );
                $stmt_desactivar->execute();
                $stmt_desactivar->close();
            }

            $db->commit();
            $transaccion_iniciada = false;

            solicitudesFlash(
                'La solicitud de '
                . $usuario_pendiente['nombre']
                . ' fue rechazada correctamente.',
                'info'
            );

            solicitudesRedirigir($vista_global);
        }

        $sucursal_post = filter_input(
            INPUT_POST,
            'sucursal_id',
            FILTER_VALIDATE_INT
        );

        if (!$vista_global && $sucursal_actual_id > 0) {
            $sucursal_post = $sucursal_actual_id;
        }

        if (
            !$sucursal_post
            || !isset($sucursales_mapa[$sucursal_post])
        ) {
            throw new RuntimeException(
                'Selecciona una sucursal válida para aprobar la cuenta.'
            );
        }

        $sucursal_destino =
            $sucursales_mapa[$sucursal_post];

        $puede_operar_caja =
            $rol_usuario === 'recepcionista'
            && (string) (
                $_POST['puede_operar_caja'] ?? '0'
            ) === '1'
                ? 1
                : 0;

        /*
         * Una cuenta aprobada por primera vez inicia con una sola sede
         * principal. Las sedes adicionales se administran después desde
         * el módulo de Sucursales.
         */
        $stmt_quitar_principal = $db->prepare(
            "UPDATE usuarios_sucursales
             SET es_principal = 0
             WHERE usuario_id = ?"
        );

        if (!$stmt_quitar_principal) {
            throw new RuntimeException(
                'No fue posible preparar la sede principal.'
            );
        }

        $stmt_quitar_principal->bind_param(
            'i',
            $usuario_id
        );
        $stmt_quitar_principal->execute();
        $stmt_quitar_principal->close();

        $estado_asignacion = 'activo';
        $es_principal = 1;

        $stmt_asignar = $db->prepare(
            "INSERT INTO usuarios_sucursales (
                usuario_id,
                sucursal_id,
                rol_sucursal,
                es_principal,
                puede_operar_caja,
                estado
             ) VALUES (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
             )
             ON DUPLICATE KEY UPDATE
                rol_sucursal = VALUES(rol_sucursal),
                es_principal = VALUES(es_principal),
                puede_operar_caja =
                    VALUES(puede_operar_caja),
                estado = VALUES(estado),
                updated_at = CURRENT_TIMESTAMP"
        );

        if (!$stmt_asignar) {
            throw new RuntimeException(
                'No fue posible preparar la asignación de sucursal.'
            );
        }

        $stmt_asignar->bind_param(
            'iisiis',
            $usuario_id,
            $sucursal_post,
            $rol_usuario,
            $es_principal,
            $puede_operar_caja,
            $estado_asignacion
        );
        $stmt_asignar->execute();
        $stmt_asignar->close();

        $estado_activo = 'activo';

        $stmt_aprobar = $db->prepare(
            "UPDATE usuarios
             SET estado = ?
             WHERE id = ?
               AND estado = 'pendiente'"
        );

        if (!$stmt_aprobar) {
            throw new RuntimeException(
                'No fue posible preparar la aprobación.'
            );
        }

        $stmt_aprobar->bind_param(
            'si',
            $estado_activo,
            $usuario_id
        );
        $stmt_aprobar->execute();

        if ($stmt_aprobar->affected_rows !== 1) {
            throw new RuntimeException(
                'La solicitud dejó de estar disponible.'
            );
        }

        $stmt_aprobar->close();

        $db->commit();
        $transaccion_iniciada = false;

        $permiso_caja_texto =
            $rol_usuario === 'recepcionista'
                ? (
                    $puede_operar_caja === 1
                        ? ' Podrá operar caja en esta sede.'
                        : ' No tendrá permiso operativo de caja.'
                )
                : '';

        solicitudesFlash(
            'La cuenta de '
            . $usuario_pendiente['nombre']
            . ' fue aprobada y asignada a '
            . $sucursal_destino['nombre']
            . '.'
            . $permiso_caja_texto,
            'success'
        );
    } catch (Throwable $error) {
        if ($transaccion_iniciada) {
            $db->rollback();
        }

        error_log(
            '[Solicitudes usuarios] '
            . $error->getMessage()
        );

        solicitudesFlash(
            $error->getMessage(),
            'error'
        );
    }

    solicitudesRedirigir($vista_global);
}

$solicitudes = [];

$query = "
    SELECT
        id,
        nombre,
        email,
        rol,
        fecha_registro
    FROM usuarios
    WHERE estado = 'pendiente'
      AND rol IN ('recepcionista', 'entrenador')
    ORDER BY fecha_registro ASC, id ASC
";

$result = $db->query($query);

if ($result) {
    while ($fila = $result->fetch_assoc()) {
        $solicitudes[] = $fila;
    }
}

$total_solicitudes = count($solicitudes);

function e(string $valor): string
{
    return htmlspecialchars(
        $valor,
        ENT_QUOTES,
        'UTF-8'
    );
}

function etiquetaRol(string $rol): string
{
    return strtolower(trim($rol)) === 'entrenador'
        ? 'Entrenador'
        : 'Recepcionista';
}

function inicialUsuario(string $nombre): string
{
    $nombre = trim($nombre);

    if ($nombre === '') {
        return 'U';
    }

    if (function_exists('mb_substr')) {
        return mb_strtoupper(
            mb_substr($nombre, 0, 1, 'UTF-8'),
            'UTF-8'
        );
    }

    return strtoupper(substr($nombre, 0, 1));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#101f3d">

    <title>Solicitudes de usuarios</title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --sol-azul: #1e3a8a;
            --sol-azul-oscuro: #172f73;
            --sol-azul-suave: #eef4ff;
            --sol-fondo: #f5f7fa;
            --sol-blanco: #ffffff;
            --sol-texto: #1f2937;
            --sol-suave: #64748b;
            --sol-borde: #e2e8f0;
            --sol-verde: #059669;
            --sol-verde-hover: #047857;
            --sol-rojo: #dc2626;
            --sol-rojo-hover: #b91c1c;
            --sol-sombra: 0 8px 24px rgba(15, 23, 42, .065);
        }

        .solicitudes-page,
        .solicitudes-page * {
            box-sizing: border-box;
        }

        .solicitudes-page {
            width: min(1160px, 100%);
            margin: 0 auto;
            color: var(--sol-texto);
        }

        .solicitudes-header {
            margin-bottom: 20px;
        }

        .solicitudes-heading {
            min-width: 0;
        }

        .solicitudes-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 7px;
            color: var(--sol-azul);
            font-size: .72rem;
            font-weight: 850;
            letter-spacing: .07em;
            text-transform: uppercase;
        }

        .solicitudes-heading h1 {
            margin: 0 0 7px;
            color: var(--sol-azul-oscuro);
            font-size: clamp(1.7rem, 3vw, 2.3rem);
            line-height: 1.12;
            letter-spacing: -.035em;
        }

        .solicitudes-heading p {
            max-width: 760px;
            margin: 0;
            color: var(--sol-suave);
            font-size: .91rem;
            line-height: 1.55;
        }

        .solicitudes-summary {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 18px;
            padding: 15px 17px;
            border: 1px solid #dbe5f5;
            border-radius: 15px;
            background: var(--sol-blanco);
            box-shadow: 0 5px 18px rgba(30, 58, 138, .05);
        }

        .solicitudes-summary-icon {
            display: grid;
            flex: 0 0 44px;
            width: 44px;
            height: 44px;
            place-items: center;
            border-radius: 12px;
            color: #ffffff;
            background: linear-gradient(135deg, #1e3a8a, #3154a5);
            font-size: .96rem;
        }

        .solicitudes-summary-copy {
            min-width: 0;
            flex: 1;
        }

        .solicitudes-summary-copy strong {
            display: block;
            margin-bottom: 3px;
            color: var(--sol-azul-oscuro);
            font-size: .95rem;
        }

        .solicitudes-summary-copy span {
            display: block;
            color: var(--sol-suave);
            font-size: .79rem;
            line-height: 1.45;
        }

        .solicitudes-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 38px;
            height: 34px;
            padding: 0 10px;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            color: var(--sol-azul);
            background: var(--sol-azul-suave);
            font-size: .88rem;
            font-weight: 850;
        }

        .solicitudes-grid {
            display: grid;
            gap: 11px;
        }

        .solicitud-card {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 20px;
            min-width: 0;
            padding: 16px 18px;
            border: 1px solid var(--sol-borde);
            border-radius: 15px;
            background: var(--sol-blanco);
            box-shadow: var(--sol-sombra);
            transition:
                border-color .18s ease,
                box-shadow .18s ease,
                transform .18s ease;
        }

        .solicitud-card:hover {
            border-color: #cbd8eb;
            box-shadow: 0 11px 28px rgba(15, 23, 42, .085);
            transform: translateY(-1px);
        }

        .solicitud-card-header {
            display: flex;
            align-items: center;
            min-width: 0;
            gap: 13px;
            margin: 0;
        }

        .solicitud-avatar {
            display: grid;
            flex: 0 0 46px;
            width: 46px;
            height: 46px;
            place-items: center;
            border: 1px solid #d7e4fb;
            border-radius: 13px;
            color: var(--sol-azul);
            background: var(--sol-azul-suave);
            font-size: .95rem;
            font-weight: 850;
        }

        .solicitud-persona {
            min-width: 0;
            flex: 1;
        }

        .solicitud-persona h2 {
            margin: 0 0 5px;
            overflow-wrap: anywhere;
            color: var(--sol-texto);
            font-size: .96rem;
            line-height: 1.25;
        }

        .solicitud-meta {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 7px 11px;
        }

        .solicitud-email {
            display: inline-flex;
            align-items: center;
            min-width: 0;
            max-width: 100%;
            gap: 6px;
            margin: 0;
            color: var(--sol-suave);
            font-size: .76rem;
        }

        .solicitud-email i {
            flex: 0 0 auto;
            color: #94a3b8;
        }

        .solicitud-email span {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .solicitud-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin: 0;
            padding: 5px 8px;
            border: 1px solid #fde68a;
            border-radius: 999px;
            color: #92400e;
            background: #fffbeb;
            font-size: .63rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .solicitud-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            min-width: 250px;
        }

        .solicitud-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-width: 116px;
            min-height: 40px;
            padding: 8px 14px;
            border: 0;
            border-radius: 10px;
            color: #ffffff;
            cursor: pointer;
            font: inherit;
            font-size: .77rem;
            font-weight: 800;
            transition:
                background .18s ease,
                box-shadow .18s ease,
                transform .18s ease;
        }

        .solicitud-button:hover {
            transform: translateY(-1px);
        }

        .solicitud-button:focus-visible {
            outline: 3px solid rgba(59, 130, 246, .24);
            outline-offset: 2px;
        }

        .solicitud-button:disabled {
            cursor: wait;
            opacity: .72;
            transform: none;
        }

        .solicitud-button.aprobar {
            background: var(--sol-verde);
            box-shadow: 0 5px 12px rgba(5, 150, 105, .15);
        }

        .solicitud-button.aprobar:hover {
            background: var(--sol-verde-hover);
        }

        .solicitud-button.rechazar {
            background: var(--sol-rojo);
            box-shadow: 0 5px 12px rgba(220, 38, 38, .13);
        }

        .solicitud-button.rechazar:hover {
            background: var(--sol-rojo-hover);
        }

        .solicitudes-empty {
            display: grid;
            min-height: 280px;
            place-items: center;
            padding: 40px 22px;
            border: 1px dashed #cbd5e1;
            border-radius: 17px;
            background: rgba(255, 255, 255, .78);
            text-align: center;
        }

        .solicitudes-empty-content {
            max-width: 430px;
        }

        .solicitudes-empty-icon {
            display: grid;
            width: 60px;
            height: 60px;
            margin: 0 auto 14px;
            place-items: center;
            border: 1px solid #bbf7d0;
            border-radius: 17px;
            color: #047857;
            background: #ecfdf5;
            font-size: 1.35rem;
        }

        .solicitudes-empty strong {
            display: block;
            margin-bottom: 6px;
            color: var(--sol-texto);
            font-size: .98rem;
        }

        .solicitudes-empty span {
            color: var(--sol-suave);
            font-size: .8rem;
            line-height: 1.5;
        }

        .swal2-popup.solicitudes-swal {
            width: min(430px, calc(100vw - 28px));
            border-radius: 18px;
            padding: 18px;
        }

        .swal2-popup.solicitudes-swal .swal2-title {
            color: var(--sol-azul-oscuro);
            font-size: 1.3rem;
        }

        .swal2-popup.solicitudes-swal .swal2-html-container {
            color: var(--sol-suave);
            font-size: .88rem;
            line-height: 1.55;
        }


        .solicitudes-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
        }

        .solicitudes-contexto {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            flex: 0 0 auto;
            max-width: 260px;
            min-height: 45px;
            padding: 7px 10px;
            border: 1px solid #dbeafe;
            border-radius: 12px;
            color: #1e3a8a;
            background: #eff6ff;
        }

        .solicitudes-contexto.global {
            border-color: #bbf7d0;
            color: #047857;
            background: #f0fdf4;
        }

        .solicitudes-contexto-icon {
            display: grid;
            flex: 0 0 30px;
            width: 30px;
            height: 30px;
            place-items: center;
            border-radius: 8px;
            background: rgba(30, 58, 138, .1);
            font-size: .72rem;
        }

        .solicitudes-contexto.global
        .solicitudes-contexto-icon {
            background: rgba(5, 150, 105, .12);
        }

        .solicitudes-contexto-copy {
            min-width: 0;
        }

        .solicitudes-contexto-copy strong,
        .solicitudes-contexto-copy small {
            display: block;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .solicitudes-contexto-copy strong {
            font-size: .74rem;
            line-height: 1.2;
        }

        .solicitudes-contexto-copy small {
            margin-top: 3px;
            color: #64748b;
            font-size: .61rem;
            font-weight: 700;
        }

        .solicitud-sede-badge,
        .solicitud-fecha {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: #475569;
            font-size: .64rem;
            font-weight: 750;
            white-space: nowrap;
        }

        .solicitud-sede-badge {
            padding: 5px 8px;
            border: 1px solid #dbeafe;
            border-radius: 999px;
            color: #1e3a8a;
            background: #f8fbff;
        }

        .solicitud-fecha {
            color: #64748b;
        }

        .solicitud-sede-badge i,
        .solicitud-fecha i {
            font-size: .6rem;
        }

        .solicitudes-swal-branch {
            margin-top: 14px;
            padding: 12px;
            border: 1px solid #dbe5f5;
            border-radius: 12px;
            background: #f8fbff;
            text-align: left;
        }

        .solicitudes-swal-label {
            display: block;
            margin-bottom: 6px;
            color: #334155;
            font-size: .72rem;
            font-weight: 800;
        }

        .solicitudes-swal-select {
            width: 100%;
            min-height: 42px;
            padding: 8px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            color: #1f2937;
            background: #ffffff;
            font-size: .78rem;
        }

        .solicitudes-swal-fixed {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #1e3a8a;
            font-size: .78rem;
            font-weight: 800;
        }

        .solicitudes-swal-cash {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            margin-top: 11px;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            color: #475569;
            background: #ffffff;
            cursor: pointer;
            font-size: .72rem;
            line-height: 1.4;
        }

        .solicitudes-swal-cash input {
            width: 17px;
            height: 17px;
            flex: 0 0 17px;
            margin-top: 1px;
            accent-color: #1e3a8a;
        }

        .solicitudes-swal-help {
            display: block;
            margin-top: 6px;
            color: #64748b;
            font-size: .64rem;
            line-height: 1.4;
        }

        @media (max-width: 900px) {
            .solicitud-card {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .solicitud-actions {
                width: 100%;
                min-width: 0;
                justify-content: stretch;
            }

            .solicitud-button {
                flex: 1;
            }
        }

        @media (max-width: 520px) {
            .solicitudes-header {
                flex-direction: column;
            }

            .solicitudes-contexto {
                width: 100%;
                max-width: none;
            }

            .solicitudes-summary {
                align-items: flex-start;
                padding: 14px;
            }

            .solicitudes-count {
                margin-left: auto;
            }

            .solicitud-card {
                padding: 15px;
            }

            .solicitud-card-header {
                align-items: flex-start;
            }

            .solicitud-meta {
                align-items: flex-start;
                flex-direction: column;
                gap: 7px;
            }

            .solicitud-actions {
                flex-direction: column;
            }

            .solicitud-button {
                width: 100%;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .solicitud-card,
            .solicitud-button {
                transition: none;
            }
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<main class="main-content">
    <div class="solicitudes-page">
        <header class="solicitudes-header">
            <div class="solicitudes-heading">
                <h1>Solicitudes de usuarios</h1>

                <p>
                    <?php if ($vista_global): ?>
                        Revisa las cuentas pendientes y elige la sucursal
                        principal al momento de aprobarlas.
                    <?php else: ?>
                        Las cuentas aprobadas desde esta vista serán asignadas
                        a <?php echo e($contexto_nombre); ?>.
                    <?php endif; ?>
                </p>
            </div>

            <div class="solicitudes-contexto <?php echo $vista_global ? 'global' : 'sucursal'; ?>">
                <span class="solicitudes-contexto-icon">
                    <i class="fas <?php echo $vista_global ? 'fa-chart-pie' : 'fa-building'; ?>"></i>
                </span>

                <span class="solicitudes-contexto-copy">
                    <strong>
                        <?php echo e($contexto_nombre); ?>
                    </strong>
                    <small>
                        <?php echo e($contexto_detalle); ?>
                    </small>
                </span>
            </div>
        </header>

        <section class="solicitudes-summary" aria-label="Resumen de solicitudes">
            <div class="solicitudes-summary-icon">
                <i class="fas fa-user-clock" aria-hidden="true"></i>
            </div>

            <div class="solicitudes-summary-copy">
                <strong>
                    <?php echo $total_solicitudes; ?>
                    solicitud<?php echo $total_solicitudes === 1 ? '' : 'es'; ?>
                    pendiente<?php echo $total_solicitudes === 1 ? '' : 's'; ?>
                </strong>

                <span>
                    <?php if ($vista_global): ?>
                        Cada aprobación debe quedar ligada a una sucursal
                        principal antes de habilitar el acceso.
                    <?php else: ?>
                        Destino de aprobación:
                        <strong><?php echo e($contexto_nombre); ?></strong>.
                        Las sedes adicionales podrán asignarse después.
                    <?php endif; ?>
                </span>
            </div>

            <span class="solicitudes-count" aria-hidden="true">
                <?php echo $total_solicitudes > 99 ? '99+' : $total_solicitudes; ?>
            </span>
        </section>

        <section class="solicitudes-grid" aria-label="Listado de solicitudes pendientes">
            <?php if (!$solicitudes): ?>
                <div class="solicitudes-empty">
                    <div class="solicitudes-empty-content">
                        <div class="solicitudes-empty-icon">
                            <i class="fas fa-check" aria-hidden="true"></i>
                        </div>

                        <strong>No hay solicitudes pendientes</strong>

                        <span>
                            Cuando un recepcionista o entrenador solicite acceso,
                            su cuenta aparecerá automáticamente en esta sección.
                        </span>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($solicitudes as $solicitud): ?>
                    <?php
                    $nombre_solicitud = (string) ($solicitud['nombre'] ?? '');
                    $email_solicitud = (string) ($solicitud['email'] ?? '');
                    $rol_solicitud = etiquetaRol((string) ($solicitud['rol'] ?? ''));
                    ?>
                    <article class="solicitud-card">
                        <div class="solicitud-card-header">
                            <div class="solicitud-avatar" aria-hidden="true">
                                <?php echo e(inicialUsuario($nombre_solicitud)); ?>
                            </div>

                            <div class="solicitud-persona">
                                <h2><?php echo e($nombre_solicitud); ?></h2>

                                <div class="solicitud-meta">
                                    <p class="solicitud-email" title="<?php echo e($email_solicitud); ?>">
                                        <i class="fas fa-envelope" aria-hidden="true"></i>
                                        <span><?php echo e($email_solicitud); ?></span>
                                    </p>

                                    <span class="solicitud-badge">
                                        <i class="fas fa-clock" aria-hidden="true"></i>
                                        <?php echo e($rol_solicitud); ?> pendiente
                                    </span>

                                    <span class="solicitud-sede-badge">
                                        <i class="fas fa-building" aria-hidden="true"></i>

                                        <?php if ($vista_global): ?>
                                            Elegir sucursal al aprobar
                                        <?php else: ?>
                                            <?php echo e($contexto_nombre); ?>
                                        <?php endif; ?>
                                    </span>

                                    <?php if (!empty($solicitud['fecha_registro'])): ?>
                                        <span class="solicitud-fecha">
                                            <i class="far fa-calendar"></i>
                                            <?php echo date(
                                                'd/m/Y',
                                                strtotime(
                                                    (string) $solicitud['fecha_registro']
                                                )
                                            ); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <form method="POST" class="solicitud-actions solicitud-form">
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?php echo e((string) $_SESSION['solicitudes_csrf']); ?>"
                            >

                            <input
                                type="hidden"
                                name="usuario_id"
                                value="<?php echo (int) $solicitud['id']; ?>"
                            >

                            <button
                                type="submit"
                                name="accion"
                                value="aprobar"
                                class="solicitud-button aprobar"
                                data-action="aprobar"
                                data-user="<?php echo e($nombre_solicitud); ?>"
                                data-role="<?php echo e(
                                    strtolower(
                                        (string) ($solicitud['rol'] ?? '')
                                    )
                                ); ?>"
                            >
                                <i class="fas fa-check" aria-hidden="true"></i>
                                Aprobar
                            </button>

                            <button
                                type="submit"
                                name="accion"
                                value="rechazar"
                                class="solicitud-button rechazar"
                                data-action="rechazar"
                                data-user="<?php echo e($nombre_solicitud); ?>"
                            >
                                <i class="fas fa-xmark" aria-hidden="true"></i>
                                Rechazar
                            </button>
                        </form>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const forms = document.querySelectorAll(
        '.solicitud-form'
    );

    const branchOptions = <?php echo json_encode(
        array_values($sucursales_mapa),
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    ); ?>;

    const globalView = <?php echo $vista_global
        ? 'true'
        : 'false'; ?>;

    const activeBranchId = <?php echo json_encode(
        (string) $sucursal_actual_id
    ); ?>;

    const activeBranchName = <?php echo json_encode(
        (string) $contexto_nombre,
        JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    ); ?>;

    forms.forEach(function (form) {
        const buttons = form.querySelectorAll(
            'button[type="submit"]'
        );

        buttons.forEach(function (button) {
            button.addEventListener(
                'click',
                async function (event) {
                    event.preventDefault();

                    const action =
                        button.dataset.action;
                    const userName =
                        button.dataset.user
                        || 'este usuario';
                    const userRole =
                        button.dataset.role || '';
                    const approving =
                        action === 'aprobar';

                    let result;

                    if (approving) {
                        result = await confirmarAprobacion(
                            userName,
                            userRole,
                            branchOptions,
                            globalView,
                            activeBranchId,
                            activeBranchName
                        );
                    } else {
                        result = await Swal.fire({
                            icon: 'warning',
                            title:
                                '¿Rechazar esta solicitud?',
                            html:
                                'La solicitud de <strong>'
                                + escapeHtml(userName)
                                + '</strong> será marcada '
                                + 'como rechazada.',
                            showCancelButton: true,
                            confirmButtonText:
                                'Sí, rechazar',
                            cancelButtonText: 'Cancelar',
                            confirmButtonColor: '#dc2626',
                            cancelButtonColor: '#64748b',
                            reverseButtons: true,
                            focusCancel: true,
                            customClass: {
                                popup:
                                    'solicitudes-swal'
                            }
                        });
                    }

                    if (!result.isConfirmed) {
                        return;
                    }

                    form.querySelectorAll(
                        '[data-generated="true"]'
                    ).forEach(function (field) {
                        field.remove();
                    });

                    agregarCampo(
                        form,
                        'accion',
                        action
                    );

                    if (approving) {
                        agregarCampo(
                            form,
                            'sucursal_id',
                            result.value.sucursalId
                        );

                        agregarCampo(
                            form,
                            'puede_operar_caja',
                            result.value.puedeCaja
                                ? '1'
                                : '0'
                        );
                    }

                    buttons.forEach(function (item) {
                        item.disabled = true;
                    });

                    form.submit();
                }
            );
        });
    });

    <?php if ($mensaje !== ''): ?>
    Swal.fire({
        icon: <?php echo json_encode(
            in_array(
                $tipo_mensaje,
                ['success', 'info', 'warning', 'error'],
                true
            )
                ? $tipo_mensaje
                : 'info',
            JSON_UNESCAPED_UNICODE
        ); ?>,
        title: <?php echo json_encode(
            $tipo_mensaje === 'success'
                ? 'Cuenta aprobada'
                : (
                    $tipo_mensaje === 'info'
                        ? 'Solicitud rechazada'
                        : (
                            $tipo_mensaje === 'warning'
                                ? 'Solicitud no disponible'
                                : 'No fue posible procesarla'
                        )
                ),
            JSON_UNESCAPED_UNICODE
        ); ?>,
        text: <?php echo json_encode(
            $mensaje,
            JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        ); ?>,
        confirmButtonText: 'Entendido',
        confirmButtonColor: '#1e3a8a',
        timer: <?php echo
            $tipo_mensaje === 'success'
                ? '3200'
                : 'undefined';
        ?>,
        timerProgressBar:
            <?php echo
                $tipo_mensaje === 'success'
                    ? 'true'
                    : 'false';
            ?>,
        showConfirmButton:
            <?php echo
                $tipo_mensaje === 'success'
                    ? 'false'
                    : 'true';
            ?>,
        customClass: {
            popup: 'solicitudes-swal'
        }
    });
    <?php endif; ?>
});

async function confirmarAprobacion(
    userName,
    userRole,
    branches,
    globalView,
    activeBranchId,
    activeBranchName
) {
    let branchHtml = '';

    if (globalView) {
        const options = branches.map(
            function (branch) {
                const detail = branch.es_matriz
                    ? ' · Matriz'
                    : '';

                return (
                    '<option value="'
                    + String(branch.id)
                    + '">'
                    + escapeHtml(branch.nombre)
                    + ' · '
                    + escapeHtml(branch.clave)
                    + detail
                    + '</option>'
                );
            }
        ).join('');

        branchHtml =
            '<label class="solicitudes-swal-label" '
            + 'for="solicitudSucursal">'
            + 'Sucursal principal'
            + '</label>'
            + '<select id="solicitudSucursal" '
            + 'class="solicitudes-swal-select">'
            + '<option value="">Seleccionar sucursal</option>'
            + options
            + '</select>'
            + '<small class="solicitudes-swal-help">'
            + 'El acceso inicial quedará ligado a esta sede.'
            + '</small>';
    } else {
        branchHtml =
            '<div class="solicitudes-swal-fixed">'
            + '<i class="fas fa-building"></i>'
            + '<span>'
            + escapeHtml(activeBranchName)
            + '</span>'
            + '</div>'
            + '<small class="solicitudes-swal-help">'
            + 'Esta será la sucursal principal de la cuenta.'
            + '</small>';
    }

    const cashHtml =
        userRole === 'recepcionista'
            ? (
                '<label class="solicitudes-swal-cash">'
                + '<input type="checkbox" '
                + 'id="solicitudPuedeCaja">'
                + '<span>'
                + '<strong>Permitir operar caja</strong><br>'
                + 'Habilita operaciones de caja en esta '
                + 'sucursal. El acceso al módulo continúa '
                + 'dependiendo de los permisos por rol.'
                + '</span>'
                + '</label>'
            )
            : (
                '<small class="solicitudes-swal-help">'
                + 'Los entrenadores se aprobarán sin '
                + 'permiso operativo de caja.'
                + '</small>'
            );

    return Swal.fire({
        icon: 'question',
        title: '¿Aprobar esta cuenta?',
        html:
            'Se permitirá que <strong>'
            + escapeHtml(userName)
            + '</strong> inicie sesión.'
            + '<div class="solicitudes-swal-branch">'
            + branchHtml
            + cashHtml
            + '</div>',
        showCancelButton: true,
        confirmButtonText: 'Sí, aprobar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#059669',
        cancelButtonColor: '#64748b',
        reverseButtons: true,
        focusCancel: true,
        customClass: {
            popup: 'solicitudes-swal'
        },
        preConfirm: function () {
            const branchId = globalView
                ? (
                    document.getElementById(
                        'solicitudSucursal'
                    )?.value || ''
                )
                : activeBranchId;

            if (!branchId) {
                Swal.showValidationMessage(
                    'Selecciona la sucursal principal.'
                );
                return false;
            }

            const puedeCaja =
                userRole === 'recepcionista'
                && Boolean(
                    document.getElementById(
                        'solicitudPuedeCaja'
                    )?.checked
                );

            return {
                sucursalId: branchId,
                puedeCaja: puedeCaja
            };
        }
    });
}

function agregarCampo(
    form,
    name,
    value
) {
    const input = document.createElement('input');

    input.type = 'hidden';
    input.name = name;
    input.value = String(value);
    input.dataset.generated = 'true';

    form.appendChild(input);
}

function escapeHtml(value) {
    const element = document.createElement('div');
    element.textContent = String(value ?? '');
    return element.innerHTML;
}
</script>

</body>
</html>