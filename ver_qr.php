<?php
// ver_qr.php - Ver y descargar QR de un socio
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$cliente_id = $_GET['id'] ?? 0;

$database = new Database();
$conn = $database->getConnection();

$stmt = $conn->prepare("SELECT nombre, apellido, codigo_qr FROM clientes WHERE id = ?");
$stmt->bind_param("i", $cliente_id);
$stmt->execute();
$result = $stmt->get_result();
$cliente = $result->fetch_assoc();

if (!$cliente || empty($cliente['codigo_qr'])) {
    die("Cliente no encontrado o no tiene código QR asignado");
}

$nombre_archivo = preg_replace('/[^a-zA-Z0-9_-]/', '_', $cliente['codigo_qr']) . '.png';
$ruta_qr = 'qrcodes/' . $nombre_archivo;

// Si no existe el archivo, generarlo
if (!file_exists($ruta_qr)) {
    require_once 'includes/qr_helper.php';
    generarCodigoQR($cliente['codigo_qr'], $ruta_qr);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Código QR - <?php echo htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apellido']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; padding: 20px; }
        .qr-card { max-width: 500px; margin: 50px auto; text-align: center; }
        .qr-code { border: 2px solid #1e3a8a; border-radius: 10px; padding: 20px; background: white; }
        .btn-imprimir { margin-top: 20px; }
    </style>
</head>
<body>
    <div class="qr-card">
        <div class="qr-code">
            <h3><?php echo htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apellido']); ?></h3>
            <p>Código de membresía</p>
            <?php if (file_exists($ruta_qr)): ?>
                <img src="<?php echo $ruta_qr; ?>" alt="Código QR" style="max-width: 250px;">
            <?php else: ?>
                <div class="alert alert-warning">No se pudo generar el código QR</div>
                <p>Código: <strong><?php echo htmlspecialchars($cliente['codigo_qr']); ?></strong></p>
            <?php endif; ?>
            <hr>
            <p class="text-muted small">Presente este código en la entrada del gimnasio</p>
        </div>
        <div class="btn-imprimir">
            <button class="btn btn-primary" onclick="window.print();">
                <i class="fas fa-print"></i> Imprimir / Guardar como PDF
            </button>
            <a href="inscripciones.php" class="btn btn-secondary">Volver</a>
        </div>
    </div>
</body>
</html>