<?php

date_default_timezone_set('America/Mexico_City');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesión no válida.']);
    exit;
}

if (!in_array($_SESSION['user_rol'] ?? '', ['admin', 'recepcionista'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/mercadopago_service.php';

$database = new Database();
$conn = $database->getConnection();

if (!$conn instanceof mysqli) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Sin conexión a base de datos.']);
    exit;
}

$conn->set_charset('utf8mb4');

function mp_api_input(): array
{
    $raw = file_get_contents('php://input');
    $input = json_decode($raw ?: '', true);

    if (!is_array($input)) {
        mp_api_error('JSON inválido.', 400);
    }

    return $input;
}

function mp_api_error(string $message, int $http = 400, array $extra = []): void
{
    http_response_code($http);
    echo json_encode(
        array_merge(['success' => false, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function mp_api_ok(array $data = []): void
{
    echo json_encode(
        array_merge(['success' => true], $data),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function mp_api_exception(Throwable $error): void
{
    error_log('[MercadoPago Point] ' . $error->getMessage());
    mp_api_error($error->getMessage(), 500);
}
