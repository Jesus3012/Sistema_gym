<?php
// Archivo: guardar_ticket.php
// Guardar ticket de venta en archivo HTML

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['venta_id'])) {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
    exit();
}

// Crear directorio tickets si no existe
$tickets_dir = __DIR__ . '/uploads/tickets';
if (!file_exists($tickets_dir)) {
    mkdir($tickets_dir, 0777, true);
}

// Generar nombre de archivo
$filename = 'ticket_venta_' . $data['venta_id'] . '_' . date('Ymd_His') . '.html';
$filepath = $tickets_dir . '/' . $filename;

// Guardar ticket
$html_content = $data['html'];

// Agregar metadatos
$full_html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Ticket Venta #' . $data['venta_id'] . '</title>
    <style>
        body {
            font-family: monospace;
            margin: 0;
            padding: 20px;
            background: white;
        }
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body>' . $html_content . '</body>
</html>';

if (file_put_contents($filepath, $full_html)) {
    echo json_encode([
        'success' => true,
        'file' => $filename,
        'message' => 'Ticket guardado correctamente'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Error al guardar el ticket'
    ]);
}
?>