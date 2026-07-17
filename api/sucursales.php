<?php
// Archivo: api/sucursales.php
// Acciones AJAX del módulo administrativo de sucursales.

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function sucursalesApiResponder(int $codigo, array $datos): void
{
    http_response_code($codigo);
    echo json_encode(
        $datos,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sucursalesApiResponder(405, [
        'ok' => false,
        'mensaje' => 'Método no permitido.',
    ]);
}

$usuarioId = (int) ($_SESSION['user_id'] ?? 0);
if ($usuarioId <= 0) {
    sucursalesApiResponder(401, [
        'ok' => false,
        'mensaje' => 'Tu sesión expiró.',
        'redirect' => '../login.php',
    ]);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/sucursal_context.php';
require_once __DIR__ . '/../includes/sucursales_helper.php';

$database = new Database();
$db = $database->getConnection();

if (!$db instanceof mysqli) {
    sucursalesApiResponder(500, [
        'ok' => false,
        'mensaje' => 'No fue posible conectar con la base de datos.',
    ]);
}

$db->set_charset('utf8mb4');

try {
    sucursal_inicializar_sesion($db);
} catch (Throwable $error) {
    sucursalesApiResponder(401, [
        'ok' => false,
        'mensaje' => $error->getMessage(),
        'redirect' => '../login.php',
    ]);
}

$rolActual = strtolower(trim((string) ($_SESSION['user_rol'] ?? '')));
if (!in_array($rolActual, ['admin', 'administrador'], true)) {
    sucursalesApiResponder(403, [
        'ok' => false,
        'mensaje' => 'Solo un administrador puede modificar sucursales.',
    ]);
}

if (!sucursales_modulo_instalado($db)) {
    sucursalesApiResponder(409, [
        'ok' => false,
        'mensaje' => 'Primero ejecuta la migración de multisucursalidad.',
    ]);
}

$csrf = trim((string) ($_POST['csrf'] ?? ''));
$csrfEsperado = (string) ($_SESSION['sucursales_admin_csrf'] ?? '');

if (
    $csrf === ''
    || $csrfEsperado === ''
    || !hash_equals($csrfEsperado, $csrf)
) {
    sucursalesApiResponder(419, [
        'ok' => false,
        'mensaje' => 'La sesión del formulario expiró. Recarga la página.',
    ]);
}

$accion = trim((string) ($_POST['accion'] ?? ''));
$sucursalActivaId = (int) ($_SESSION['sucursal_id'] ?? 0);

try {
    switch ($accion) {
        case 'guardar_sucursal':
            $sucursalId = filter_input(
                INPUT_POST,
                'sucursal_id',
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            $datos = [
                'clave' => $_POST['clave'] ?? '',
                'nombre' => $_POST['nombre'] ?? '',
                'telefono' => $_POST['telefono'] ?? '',
                'email' => $_POST['email'] ?? '',
                'direccion' => $_POST['direccion'] ?? '',
                'horario' => $_POST['horario'] ?? '',
                'zona_horaria' => $_POST['zona_horaria'] ?? '',
            ];

            if ($sucursalId) {
                sucursales_actualizar($db, (int) $sucursalId, $datos);
                $idGuardado = (int) $sucursalId;
                $mensaje = 'Sucursal actualizada correctamente.';
            } else {
                $idGuardado = sucursales_crear($db, $datos, $usuarioId);
                $mensaje = 'Sucursal creada y preparada correctamente.';
            }

            $_SESSION['sucursales_admin_csrf'] = bin2hex(random_bytes(32));

            sucursalesApiResponder(200, [
                'ok' => true,
                'mensaje' => $mensaje,
                'sucursal_id' => $idGuardado,
                'csrf' => $_SESSION['sucursales_admin_csrf'],
                'redirect' => 'sucursales.php?sucursal=' . $idGuardado,
            ]);

        case 'cambiar_estado_sucursal':
            $sucursalId = (int) ($_POST['sucursal_id'] ?? 0);
            $estado = trim((string) ($_POST['estado'] ?? ''));

            sucursales_cambiar_estado(
                $db,
                $sucursalId,
                $estado,
                $sucursalActivaId
            );

            sucursalesApiResponder(200, [
                'ok' => true,
                'mensaje' => $estado === 'activa'
                    ? 'Sucursal activada correctamente.'
                    : 'Sucursal desactivada correctamente.',
            ]);

        case 'guardar_asignacion':
            $sucursalId = (int) ($_POST['sucursal_id'] ?? 0);
            $usuarioAsignadoId = (int) ($_POST['usuario_id'] ?? 0);
            $rol = trim((string) ($_POST['rol_sucursal'] ?? ''));
            $estado = trim((string) ($_POST['estado'] ?? 'activo'));
            $esPrincipal = (int) ($_POST['es_principal'] ?? 0) === 1;
            $puedeCaja = (int) ($_POST['puede_operar_caja'] ?? 0) === 1;

            sucursales_guardar_asignacion(
                $db,
                $sucursalId,
                $usuarioAsignadoId,
                $rol,
                $esPrincipal,
                $puedeCaja,
                $estado,
                $usuarioId,
                $sucursalActivaId
            );

            sucursalesApiResponder(200, [
                'ok' => true,
                'mensaje' => 'Acceso del colaborador actualizado.',
            ]);

        case 'desactivar_asignacion':
            $sucursalId = (int) ($_POST['sucursal_id'] ?? 0);
            $usuarioAsignadoId = (int) ($_POST['usuario_id'] ?? 0);

            sucursales_desactivar_asignacion(
                $db,
                $sucursalId,
                $usuarioAsignadoId,
                $usuarioId,
                $sucursalActivaId
            );

            sucursalesApiResponder(200, [
                'ok' => true,
                'mensaje' => 'El acceso a esta sucursal fue retirado.',
            ]);

        case 'guardar_plan':
            $sucursalId = (int) ($_POST['sucursal_id'] ?? 0);
            $planId = (int) ($_POST['plan_id'] ?? 0);
            $precioTexto = str_replace(',', '', (string) ($_POST['precio'] ?? ''));
            $precio = filter_var($precioTexto, FILTER_VALIDATE_FLOAT);
            $estado = trim((string) ($_POST['estado'] ?? 'activo'));

            if ($precio === false) {
                throw new InvalidArgumentException('Ingresa un precio válido.');
            }

            sucursales_guardar_plan(
                $db,
                $sucursalId,
                $planId,
                (float) $precio,
                $estado
            );

            sucursalesApiResponder(200, [
                'ok' => true,
                'mensaje' => 'Plan actualizado para esta sucursal.',
            ]);

        case 'sincronizar_catalogos':
            $sucursalId = (int) ($_POST['sucursal_id'] ?? 0);
            $resultado = sucursales_sincronizar_catalogos($db, $sucursalId);

            sucursalesApiResponder(200, [
                'ok' => true,
                'mensaje' => sprintf(
                    'Sincronización completada: %d planes y %d productos nuevos.',
                    $resultado['planes'],
                    $resultado['productos']
                ),
                'resultado' => $resultado,
            ]);

        case 'guardar_terminal':
            $sucursalId = (int) ($_POST['sucursal_id'] ?? 0);
            $terminalRegistroId = (int) ($_POST['terminal_registro_id'] ?? 0);
            $terminalId = (string) ($_POST['terminal_id'] ?? '');
            $nombre = (string) ($_POST['nombre'] ?? '');
            $predeterminada = (int) ($_POST['predeterminada'] ?? 0) === 1;
            $activa = (int) ($_POST['activo'] ?? 0) === 1;

            $idGuardado = sucursales_guardar_terminal(
                $db,
                $sucursalId,
                $terminalRegistroId,
                $terminalId,
                $nombre,
                $predeterminada,
                $activa
            );

            sucursalesApiResponder(200, [
                'ok' => true,
                'mensaje' => 'Terminal guardada correctamente.',
                'terminal_id' => $idGuardado,
            ]);

        case 'cambiar_estado_terminal':
            $sucursalId = (int) ($_POST['sucursal_id'] ?? 0);
            $terminalId = (int) ($_POST['terminal_registro_id'] ?? 0);
            $activa = (int) ($_POST['activo'] ?? 0) === 1;

            sucursales_cambiar_estado_terminal(
                $db,
                $sucursalId,
                $terminalId,
                $activa
            );

            sucursalesApiResponder(200, [
                'ok' => true,
                'mensaje' => $activa
                    ? 'Terminal activada correctamente.'
                    : 'Terminal desactivada correctamente.',
            ]);

        default:
            sucursalesApiResponder(422, [
                'ok' => false,
                'mensaje' => 'Acción no reconocida.',
            ]);
    }
} catch (Throwable $error) {
    error_log('[Módulo sucursales] ' . $error->getMessage());

    sucursalesApiResponder(422, [
        'ok' => false,
        'mensaje' => $error->getMessage(),
    ]);
}
