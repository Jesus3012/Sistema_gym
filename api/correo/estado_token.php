<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
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

    $estado = correo_cola_estado_token($conn, $token);
    echo json_encode(array_merge(
        ['success' => true],
        $estado
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
