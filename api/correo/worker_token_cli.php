<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$token = trim((string) ($argv[1] ?? ''));
if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
    exit(2);
}

$raiz = dirname(__DIR__, 2);
$directorioLogs = $raiz . DIRECTORY_SEPARATOR . 'logs';
$archivoLog = $directorioLogs . DIRECTORY_SEPARATOR . 'correo_worker.log';

function correo_worker_log(string $archivo, string $mensaje): void
{
    $directorio = dirname($archivo);
    if (!is_dir($directorio)) {
        @mkdir($directorio, 0775, true);
    }

    @file_put_contents(
        $archivo,
        '[' . date('Y-m-d H:i:s') . '] ' . $mensaje . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

try {
    require_once $raiz . '/config/database.php';
    require_once $raiz . '/includes/correo_cola.php';

    $database = new Database();
    $conn = $database->getConnection();
    if (!$conn instanceof mysqli) {
        throw new RuntimeException('Sin conexión a la base de datos.');
    }
    $conn->set_charset('utf8mb4');

    $resultado = correo_cola_procesar_token($conn, $token);
    $estado = correo_cola_estado_token($conn, $token);

    correo_worker_log(
        $archivoLog,
        json_encode(
            [
                'id' => (int) ($estado['id'] ?? 0),
                'tipo' => (string) ($estado['tipo'] ?? ''),
                'estado' => (string) ($estado['estado'] ?? ''),
                'enviado' => !empty($resultado['enviado']),
                'error' => (string) ($estado['ultimo_error'] ?? ''),
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: 'Resultado no serializable.'
    );

    exit((string) ($estado['estado'] ?? '') === 'enviado' ? 0 : 1);
} catch (Throwable $e) {
    correo_worker_log($archivoLog, 'ERROR: ' . $e->getMessage());
    exit(1);
}
