<?php
// Archivo: api/cambiar_sucursal.php
// Cambia la sede operativa o activa la vista global del dashboard.

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function responderSucursal(int $status, array $payload): void
{
    http_response_code($status);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderSucursal(405, [
        'ok' => false,
        'mensaje' => 'Método no permitido.',
    ]);
}

$usuarioId = (int) ($_SESSION['user_id'] ?? 0);

if ($usuarioId <= 0) {
    responderSucursal(401, [
        'ok' => false,
        'mensaje' => 'La sesión expiró.',
        'redirect' => 'login.php',
    ]);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/sucursal_context.php';

$database = new Database();
$db = $database->getConnection();

if (!$db instanceof mysqli) {
    responderSucursal(500, [
        'ok' => false,
        'mensaje' => 'No fue posible conectar con la base de datos.',
    ]);
}

$db->set_charset('utf8mb4');

$token = trim((string) ($_POST['csrf'] ?? ''));

if (!sucursal_validar_csrf($token)) {
    responderSucursal(419, [
        'ok' => false,
        'mensaje' => 'La solicitud expiró. Recarga la página.',
    ]);
}

$sucursalId = filter_input(
    INPUT_POST,
    'sucursal_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0]]
);

if ($sucursalId === null || $sucursalId === false) {
    responderSucursal(422, [
        'ok' => false,
        'mensaje' => 'Selecciona una opción válida.',
    ]);
}

try {
    if ((int) $sucursalId === 0) {
        /*
         * La vista global modifica únicamente las estadísticas del
         * dashboard. La sucursal operativa se conserva para ventas,
         * caja, inventario, asistencias e inscripciones.
         */
        sucursal_activar_vista_global($db, $usuarioId);

        responderSucursal(200, [
            'ok' => true,
            'mensaje' => 'Estadísticas globales activadas.',
            'contexto' => [
                'id' => 0,
                'nombre' => 'Todas las sucursales',
                'tipo' => 'global',
            ],
            'redirect' => 'dashboard.php?vista=global',
        ]);
    }

    $sucursal = sucursal_cambiar_activa(
        $db,
        $usuarioId,
        (int) $sucursalId
    );

    responderSucursal(200, [
        'ok' => true,
        'mensaje' => 'Sucursal cambiada correctamente.',
        'contexto' => [
            'id' => (int) $sucursal['id'],
            'clave' => (string) $sucursal['clave'],
            'nombre' => (string) $sucursal['nombre'],
            'tipo' => 'sucursal',
        ],
        'redirect' => 'dashboard.php?vista=sucursal',
    ]);
} catch (Throwable $exception) {
    error_log('[Cambiar sucursal] ' . $exception->getMessage());

    responderSucursal(403, [
        'ok' => false,
        'mensaje' => $exception->getMessage(),
    ]);
}
