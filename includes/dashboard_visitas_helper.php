<?php

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/**
 * Utilidades compartidas por los endpoints del registro rápido de visitas.
 *
 * Este helper no imprime HTML ni redirige: todas las respuestas son JSON.
 */

function dashboard_visitas_responder(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function dashboard_visitas_normalizar_rol(string $rol): string
{
    $rol = strtolower(trim($rol));

    if ($rol === 'administrador') {
        return 'admin';
    }

    return $rol;
}

/**
 * @return array{
 *   db: mysqli,
 *   usuario_id: int,
 *   rol: string,
 *   sucursal_id: int,
 *   sucursal_nombre: string
 * }
 */
function dashboard_visitas_contexto(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $raiz = dirname(__DIR__);
    require_once $raiz . '/config/database.php';

    $usuarioId = (int) ($_SESSION['user_id'] ?? 0);
    $sucursalId = (int) ($_SESSION['sucursal_id'] ?? 0);
    $rolSesion = dashboard_visitas_normalizar_rol(
        (string) ($_SESSION['user_rol'] ?? '')
    );

    if ($usuarioId <= 0) {
        dashboard_visitas_responder([
            'success' => false,
            'message' => 'La sesión terminó. Inicia sesión nuevamente.',
        ], 401);
    }

    if ($sucursalId <= 0) {
        dashboard_visitas_responder([
            'success' => false,
            'message' => 'Selecciona una sucursal operativa.',
        ], 409);
    }

    $database = new Database();
    $db = $database->getConnection();

    if (!$db instanceof mysqli) {
        dashboard_visitas_responder([
            'success' => false,
            'message' => 'No fue posible conectar con la base de datos.',
        ], 500);
    }

    $db->set_charset('utf8mb4');

    $stmt = $db->prepare(
        "SELECT id, rol, estado
         FROM usuarios
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    $usuario = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$usuario || (string) ($usuario['estado'] ?? '') !== 'activo') {
        dashboard_visitas_responder([
            'success' => false,
            'message' => 'El usuario no está disponible para realizar operaciones.',
        ], 403);
    }

    $rolBase = dashboard_visitas_normalizar_rol(
        (string) ($usuario['rol'] ?? '')
    );
    $rolEfectivo = $rolSesion !== '' ? $rolSesion : $rolBase;

    if (!in_array(
        $rolEfectivo,
        ['super_administrador', 'admin', 'recepcionista'],
        true
    )) {
        dashboard_visitas_responder([
            'success' => false,
            'message' => 'No tienes permiso para registrar visitas.',
        ], 403);
    }

    $stmt = $db->prepare(
        "SELECT id, nombre, estado
         FROM sucursales
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $sucursalId);
    $stmt->execute();
    $sucursal = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sucursal || (string) ($sucursal['estado'] ?? '') !== 'activa') {
        dashboard_visitas_responder([
            'success' => false,
            'message' => 'La sucursal seleccionada no está activa.',
        ], 409);
    }

    /*
     * Superadministración puede operar cualquier sede. Para los demás roles
     * se confirma que exista una asignación activa a la sucursal de sesión.
     */
    if ($rolBase !== 'super_administrador') {
        $stmt = $db->prepare(
            "SELECT 1
             FROM usuarios_sucursales
             WHERE usuario_id = ?
               AND sucursal_id = ?
               AND estado = 'activo'
             LIMIT 1"
        );
        $stmt->bind_param('ii', $usuarioId, $sucursalId);
        $stmt->execute();
        $asignado = $stmt->get_result()->fetch_row();
        $stmt->close();

        if (!$asignado) {
            dashboard_visitas_responder([
                'success' => false,
                'message' => 'No tienes acceso operativo a la sucursal seleccionada.',
            ], 403);
        }
    }

    return [
        'db' => $db,
        'usuario_id' => $usuarioId,
        'rol' => $rolEfectivo,
        'sucursal_id' => $sucursalId,
        'sucursal_nombre' => (string) ($sucursal['nombre'] ?? 'Sucursal'),
    ];
}

function dashboard_visitas_texto(string $valor, int $maximo): string
{
    $valor = trim(preg_replace('/\s+/u', ' ', $valor) ?? '');

    if (function_exists('mb_substr')) {
        return mb_substr($valor, 0, $maximo, 'UTF-8');
    }

    return substr($valor, 0, $maximo);
}
