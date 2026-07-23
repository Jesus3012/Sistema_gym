<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_guard.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/qr_helper.php';
require_once __DIR__ . '/correo_inscripciones.php';
require_once __DIR__ . '/documentos_inscripciones.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$database = new Database();
$conn = $database->getConnection();

if (!$conn) {
    http_response_code(500);
    exit('No fue posible conectar con la base de datos.');
}

$historialPagoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$historialPagoId || $historialPagoId <= 0) {
    http_response_code(400);
    exit('Documento no válido.');
}

$sucursalId = (int) ($_SESSION['sucursal_id'] ?? 0);
$rolBase = strtolower(trim((string) ($_SESSION['user_rol_base'] ?? $_SESSION['user_rol'] ?? '')));
$esAdministrador = in_array($rolBase, ['admin', 'administrador'], true);

$stmt = $conn->prepare(
    "SELECT hp.id
     FROM historial_pagos hp
     INNER JOIN inscripciones i ON i.id = hp.inscripcion_id
     LEFT JOIN inscripciones_sucursales acceso
        ON acceso.inscripcion_id = i.id
       AND acceso.sucursal_id = ?
     WHERE hp.id = ?
       AND (
            i.sucursal_id = ?
            OR acceso.sucursal_id IS NOT NULL
            OR ? = 1
       )
     LIMIT 1"
);
$adminFlag = $esAdministrador ? 1 : 0;
$stmt->bind_param('iiii', $sucursalId, $historialPagoId, $sucursalId, $adminFlag);
$stmt->execute();
$autorizado = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$autorizado) {
    http_response_code(404);
    exit('El documento no existe o no está disponible para la sucursal activa.');
}

$documento = asegurarDocumentoHistorialInscripcion($conn, (int) $historialPagoId);
if (!$documento['success'] || empty($documento['path']) || !is_file($documento['path'])) {
    http_response_code(500);
    exit('No se pudo generar el documento de membresía.');
}

$ruta = (string) $documento['path'];
$nombre = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) ($documento['name'] ?? 'documento_membresia.pdf'));
$tamano = filesize($ruta);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $nombre . '"');
header('Content-Length: ' . (string) $tamano);
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

readfile($ruta);
exit;
