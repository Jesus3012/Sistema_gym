<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/super_admin_helper.php';
require_once __DIR__ . '/includes/expediente_salud_helper.php';
require_once __DIR__ . '/includes/expediente_salud_pdf_helper.php';

if (!expediente_es_administrativo()) {
    http_response_code(403);
    exit('Acceso restringido.');
}

$expedienteId = (int) ($_GET['id'] ?? 0);
if ($expedienteId <= 0) {
    http_response_code(400);
    exit('Expediente no válido.');
}

try {
    $database = new Database();
    $conn = $database->getConnection();

    if (!$conn instanceof mysqli) {
        throw new RuntimeException('No fue posible conectar con la base de datos.');
    }

    $conn->set_charset('utf8mb4');
    $resultado = expediente_generar_pdf_memoria($conn, $expedienteId);

    $modo = strtolower(trim((string) ($_GET['modo'] ?? 'ver')));
    $disposicion = $modo === 'descargar' ? 'attachment' : 'inline';
    $nombre = str_replace(['"', "\r", "\n"], '', (string) $resultado['nombre']);
    $contenido = (string) $resultado['contenido'];

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . $disposicion . '; filename="' . $nombre . '"');
    header('Content-Length: ' . strlen($contenido));
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo $contenido;
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    exit('No fue posible generar el PDF: ' . $e->getMessage());
}