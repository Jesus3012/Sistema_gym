<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Connection: close');

ignore_user_abort(true);
@set_time_limit(35);

$entrada = $_POST;
if (empty($entrada)) {
    $contenidoCrudo = (string) file_get_contents('php://input');
    parse_str($contenidoCrudo, $entrada);
}

$token = trim((string) ($entrada['token'] ?? $_GET['token'] ?? ''));
if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Token no válido.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$raiz = dirname(__DIR__, 2);
require_once $raiz . '/config/database.php';
require_once $raiz . '/includes/correo_cola.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    if (!$conn instanceof mysqli) {
        throw new RuntimeException('Sin conexión a la base de datos.');
    }
    $conn->set_charset('utf8mb4');

    $resultado = correo_cola_procesar_token($conn, $token);
    $estado = correo_cola_estado_token($conn, $token);
    $enviado = (string) ($estado['estado'] ?? '') === 'enviado';
    $omitido = !empty($resultado['omitido']);

    echo json_encode([
        'success' => $enviado || $omitido,
        'enviado' => $enviado,
        'omitido' => $omitido,
        'id' => (int) ($estado['id'] ?? 0),
        'estado' => (string) ($estado['estado'] ?? ''),
        'intentos' => (int) ($estado['intentos'] ?? 0),
        'error' => $enviado
            ? null
            : (string) ($estado['ultimo_error'] ?? $resultado['error'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
