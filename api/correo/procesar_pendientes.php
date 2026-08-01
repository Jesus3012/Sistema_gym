<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesión no válida.']);
    exit;
}

/*
 * Libera de inmediato el archivo de sesión. Sin esto, PHPMailer mantiene
 * bloqueadas todas las demás peticiones del mismo usuario mientras envía.
 */
session_write_close();
ignore_user_abort(true);
@set_time_limit(40);

$raiz = dirname(__DIR__, 2);
require_once $raiz . '/config/database.php';
require_once $raiz . '/includes/correo_cola.php';

$database = new Database();
$conn = $database->getConnection();
if (!$conn instanceof mysqli) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Sin conexión a la base de datos.']);
    exit;
}
$conn->set_charset('utf8mb4');

try {
    $resultados = correo_cola_procesar_pendientes($conn, 1);
    $enviados = 0;
    $errores = [];
    foreach ($resultados as $resultado) {
        if (!empty($resultado['enviado'])) {
            $enviados++;
        } elseif (empty($resultado['omitido'])) {
            $errores[] = (string) ($resultado['error'] ?? 'Error desconocido');
        }
    }
    echo json_encode([
        'success' => $errores === [],
        'procesados' => count($resultados),
        'enviados' => $enviados,
        'errores' => $errores,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
