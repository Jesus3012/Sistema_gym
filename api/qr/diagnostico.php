<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Inicia sesión para ejecutar el diagnóstico.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$raiz = dirname(__DIR__, 2);
require_once $raiz . '/includes/qr_helper.php';

$codigo = date('ymdHis') . '001';
$directorio = $raiz . DIRECTORY_SEPARATOR . 'qrcodes';
$ruta = $directorio . DIRECTORY_SEPARATOR . 'diagnostico_' . $codigo . '.png';

$success = generarCodigoQR($codigo, $ruta);

echo json_encode([
    'success' => $success,
    'codigo' => $codigo,
    'archivo' => $success
        ? 'qrcodes/' . basename($ruta)
        : null,
    'ruta_absoluta' => $success ? $ruta : null,
    'zlib' => function_exists('gzcompress'),
    'directorio_existe' => is_dir($directorio),
    'directorio_escribible' => is_dir($directorio) && is_writable($directorio),
    'error' => $success ? null : obtenerUltimoErrorQR(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
