<?php
// Archivo: includes/reenviar_ticket.php
// Reenviar ticket de venta por email

date_default_timezone_set('America/Mexico_City');

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

require_once '../config/database.php';
require_once '../fpdf/fpdf.php';

// Incluir PHPMailer con rutas directas
require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/SMTP.php';
require_once __DIR__ . '/../PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$data = json_decode(file_get_contents('php://input'), true);
$venta_id = isset($data['venta_id']) ? (int)$data['venta_id'] : 0;
$email = isset($data['email']) ? trim($data['email']) : '';

if (!$venta_id || !$email) {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Correo electrónico inválido']);
    exit();
}

$database = new Database();
$conn = $database->getConnection();

if (!$conn) {
    echo json_encode([
        'success' => false,
        'message' => 'No fue posible conectar con la base de datos.'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

function obtenerConfiguracionCorreoSMTP(mysqli $conn): array
{
    $result = $conn->query(
        "SELECT
            host,
            puerto,
            usuario,
            password_smtp,
            cifrado,
            smtp_auth,
            remitente_email,
            remitente_nombre,
            verificar_ssl,
            activo
         FROM configuracion_correo
         WHERE id = 1
         LIMIT 1"
    );

    if (!$result || $result->num_rows === 0) {
        throw new RuntimeException('No se encontró la configuración de correo con id = 1.');
    }

    $config = $result->fetch_assoc();

    if ((int) ($config['activo'] ?? 0) !== 1) {
        throw new RuntimeException('El envío de correo está desactivado en Configuración.');
    }

    $config['host'] = trim((string) ($config['host'] ?? ''));
    $config['puerto'] = (int) ($config['puerto'] ?? 0);
    $config['smtp_auth'] = (int) ($config['smtp_auth'] ?? 0) === 1;
    $config['usuario'] = trim((string) ($config['usuario'] ?? ''));
    $config['password_smtp'] = (string) ($config['password_smtp'] ?? '');
    $config['remitente_email'] = trim((string) ($config['remitente_email'] ?? ''));
    $config['remitente_nombre'] = trim((string) ($config['remitente_nombre'] ?? ''));

    if ($config['host'] === '') {
        throw new RuntimeException('El host SMTP está vacío.');
    }

    if ($config['puerto'] < 1 || $config['puerto'] > 65535) {
        throw new RuntimeException('El puerto SMTP configurado no es válido.');
    }

    if ($config['smtp_auth'] && ($config['usuario'] === '' || $config['password_smtp'] === '')) {
        throw new RuntimeException('El usuario o la contraseña SMTP están incompletos.');
    }

    if ($config['remitente_email'] === '') {
        $config['remitente_email'] = $config['usuario'];
    }

    if (!filter_var($config['remitente_email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('El correo remitente configurado no es válido.');
    }

    return $config;
}

function getLogoUrlAbsoluta($ruta_relativa): string
{
    if (empty($ruta_relativa)) {
        return '';
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return '';
    }

    return $protocol . $host . '/' . ltrim((string) $ruta_relativa, '/');
}

function resolverRutaLogoLocal($ruta_relativa): string
{
    if (empty($ruta_relativa)) {
        return '';
    }

    $ruta_relativa = ltrim((string) $ruta_relativa, '/');
    $candidatas = [
        dirname(__DIR__) . '/' . $ruta_relativa,
        __DIR__ . '/../' . $ruta_relativa,
        dirname(__DIR__, 2) . '/' . $ruta_relativa,
    ];

    foreach ($candidatas as $ruta) {
        if (is_file($ruta)) {
            return $ruta;
        }
    }

    return '';
}

function formatearFechaTicket(?string $fecha): string
{
    if (empty($fecha)) {
        return date('d/m/Y, h:i a');
    }

    $timestamp = strtotime($fecha);
    if (!$timestamp) {
        return (string) $fecha;
    }

    $formateada = date('d/m/Y, h:i a', $timestamp);
    return str_replace(['am', 'pm'], ['a.m.', 'p.m.'], $formateada);
}

function metodoPagoBonito(array $venta): string
{
    $metodo = strtolower(trim((string) ($venta['metodo_pago'] ?? '')));
    $tipoTarjeta = strtolower(trim((string) ($venta['tipo_tarjeta'] ?? '')));

    if ($metodo === 'tarjeta') {
        if ($tipoTarjeta === 'credito') {
            return 'Tarjeta de crédito';
        }
        if ($tipoTarjeta === 'debito') {
            return 'Tarjeta de débito';
        }
        return 'Tarjeta';
    }

    if ($metodo === 'transferencia') {
        return 'Transferencia';
    }

    if ($metodo === 'efectivo') {
        return 'Efectivo';
    }

    return ucfirst($metodo !== '' ? $metodo : 'Efectivo');
}

function pdfLineaPunteada(FPDF $pdf): void
{
    $pdf->SetFont('Courier', '', 8);
    $pdf->Cell(0, 3, str_repeat('.', 62), 0, 1, 'C');
}

function pdfCampo(FPDF $pdf, string $label, string $value): void
{
    $pdf->SetFont('Courier', '', 10);
    $pdf->Cell(34, 5.5, utf8_decode($label . ':'), 0, 0, 'L');
    $pdf->Cell(0, 5.5, utf8_decode($value), 0, 1, 'R');
}

function generarPDFTicketVenta(
    int $venta_id,
    array $detalles,
    array $venta,
    string $gym_nombre,
    string $gym_logo,
    string $gym_telefono = '',
    string $gym_email = '',
    string $gym_direccion = ''
): string {
    $pdf = new FPDF('P', 'mm', [80, 210]);
    $pdf->SetAutoPageBreak(true, 8);
    $pdf->SetMargins(6, 6, 6);
    $pdf->AddPage();

    $logoPath = resolverRutaLogoLocal($gym_logo);
    if ($logoPath !== '') {
        try {
            $pdf->Image($logoPath, 30, 8, 20);
            $pdf->Ln(20);
        } catch (Throwable $e) {
            $pdf->Ln(5);
        }
    } else {
        $pdf->Ln(5);
    }

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(0, 6, utf8_decode($gym_nombre), 0, 1, 'C');

    if ($gym_email !== '') {
        $pdf->SetFont('Arial', '', 7.5);
        $pdf->Cell(0, 4, utf8_decode($gym_email), 0, 1, 'C');
    }
    if ($gym_direccion !== '') {
        $pdf->SetFont('Arial', '', 7);
        $pdf->MultiCell(0, 3.6, utf8_decode($gym_direccion), 0, 'C');
    }

    $pdf->Ln(2);
    $pdf->SetFont('Courier', '', 10);
    $pdf->Cell(0, 5, utf8_decode('Ticket de venta #' . str_pad((string) $venta_id, 8, '0', STR_PAD_LEFT)), 0, 1, 'C');
    $pdf->Cell(0, 5, utf8_decode(formatearFechaTicket($venta['fecha_venta'] ?? null)), 0, 1, 'C');
    $pdf->Ln(1);

    pdfLineaPunteada($pdf);
    $pdf->Ln(1);

    foreach ($detalles as $detalle) {
        $nombreProducto = trim((string) ($detalle['producto_nombre'] ?? 'Producto'));
        $cantidad = (float) ($detalle['cantidad'] ?? 0);
        $precioUnitario = (float) ($detalle['precio_unitario'] ?? 0);
        $subtotal = (float) ($detalle['subtotal'] ?? 0);

        $cantidadTexto = (floor($cantidad) == $cantidad) ? (string) (int) $cantidad : number_format($cantidad, 2);

        $pdf->SetFont('Courier', 'B', 12);
        $pdf->Cell(48, 6, utf8_decode($nombreProducto . ' x' . $cantidadTexto), 0, 0, 'L');
        $pdf->Cell(20, 6, '$' . number_format($subtotal, 2), 0, 1, 'R');

        $pdf->SetFont('Courier', '', 9);
        $pdf->Cell(0, 4.5, '$' . number_format($precioUnitario, 2) . ' por unidad', 0, 1, 'L');
        $pdf->Ln(1.5);
    }

    pdfLineaPunteada($pdf);
    $pdf->Ln(2);

    $pdf->SetFont('Courier', 'B', 13);
    $pdf->Cell(35, 7, 'TOTAL', 0, 0, 'L');
    $pdf->Cell(0, 7, '$' . number_format((float) ($venta['total'] ?? 0), 2), 0, 1, 'R');
    $pdf->Ln(1);

    pdfCampo($pdf, 'Método', metodoPagoBonito($venta));

    if (isset($venta['monto_recibido']) && $venta['monto_recibido'] !== null && $venta['monto_recibido'] !== '') {
        $montoRecibido = (float) $venta['monto_recibido'];
        $pdfCampoRecibido = '$' . number_format($montoRecibido, 2);
        pdfCampo($pdf, 'Recibido', $pdfCampoRecibido);

        $cambio = $montoRecibido - (float) ($venta['total'] ?? 0);
        if ($cambio < 0) {
            $cambio = 0;
        }
        pdfCampo($pdf, 'Cambio', '$' . number_format($cambio, 2));
    }

    $estadoVenta = trim((string) ($venta['estado'] ?? ''));
    if ($estadoVenta === '') {
        $estadoVenta = 'Completada';
    } else {
        $estadoVenta = ucfirst($estadoVenta);
    }
    pdfCampo($pdf, 'Estado', $estadoVenta);

    $pdf->Ln(1);
    pdfLineaPunteada($pdf);
    $pdf->Ln(2);

    if (!empty(trim((string) ($venta['cliente_nombre'] ?? '')))) {
        $pdf->SetFont('Courier', '', 9.5);
        $pdf->MultiCell(0, 4.8, utf8_decode('Cliente: ' . trim((string) $venta['cliente_nombre'])), 0, 'L');
    }

    if (!empty(trim((string) ($venta['usuario_nombre'] ?? '')))) {
        $pdf->SetFont('Courier', '', 9.5);
        $pdf->MultiCell(0, 4.8, utf8_decode('Atendió: ' . trim((string) $venta['usuario_nombre'])), 0, 'L');
    }

    $pdf->Ln(6);
    $pdf->SetFont('Courier', '', 12);
    $pdf->Cell(0, 6, utf8_decode('Gracias por tu compra'), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 8.5);
    $pdf->MultiCell(0, 4.2, utf8_decode('Conserva este ticket para cualquier aclaración.'), 0, 'C');

    return $pdf->Output('S');
}

// Obtener datos de la venta
$query = "SELECT v.*, u.nombre as usuario_nombre,
          CONCAT(COALESCE(c.nombre,''), ' ', COALESCE(c.apellido,'')) as cliente_nombre,
          c.email as cliente_email
          FROM ventas v
          LEFT JOIN usuarios u ON v.usuario_id = u.id
          LEFT JOIN clientes c ON v.cliente_id = c.id
          WHERE v.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $venta_id);
$stmt->execute();
$venta = $stmt->get_result()->fetch_assoc();

if (!$venta) {
    echo json_encode(['success' => false, 'message' => 'Venta no encontrada']);
    exit();
}

$query_detalles = "SELECT dv.*, p.nombre as producto_nombre 
                   FROM detalle_ventas dv
                   LEFT JOIN productos p ON dv.producto_id = p.id
                   WHERE dv.venta_id = ?";
$stmt = $conn->prepare($query_detalles);
$stmt->bind_param("i", $venta_id);
$stmt->execute();
$detalles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$query_config = "SELECT nombre, logo, telefono, email, direccion FROM configuracion_gimnasio WHERE id = 1";
$result_config = $conn->query($query_config);
$config = $result_config ? $result_config->fetch_assoc() : [];
$gym_nombre = $config['nombre'] ?? 'EGO GYM';
$gym_logo = $config['logo'] ?? '';
$gym_email = $config['email'] ?? 'egogym@gmail.com';
$gym_telefono = $config['telefono'] ?? '';
$gym_direccion = $config['direccion'] ?? '';

$pdf_content = generarPDFTicketVenta(
    $venta_id,
    $detalles,
    $venta,
    $gym_nombre,
    $gym_logo,
    $gym_telefono,
    $gym_email,
    $gym_direccion
);
$logo_url_absoluta = getLogoUrlAbsoluta($gym_logo);

$asunto = 'Ticket de Venta #' . str_pad((string) $venta_id, 8, '0', STR_PAD_LEFT) . ' - ' . htmlspecialchars($gym_nombre);
$cuerpo_html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Ticket de Venta - ' . htmlspecialchars($gym_nombre) . '</title>
    <style>
        body{font-family:Segoe UI,Arial,sans-serif;background:#f1f5f9;margin:0;padding:24px;color:#334155}
        .container{max-width:620px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,.08)}
        .header{padding:28px 24px 20px;text-align:center;background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);border-bottom:1px solid #e2e8f0}
        .logo{max-width:74px;max-height:74px;object-fit:contain;margin-bottom:12px}
        .title{font-size:26px;font-weight:800;color:#1e293b;margin:0 0 6px}
        .subtitle{font-size:14px;color:#64748b;margin:0}
        .content{padding:24px}
        .hello{font-size:18px;font-weight:700;color:#1e3a8a;margin-bottom:10px}
        .text{font-size:14px;line-height:1.65;color:#475569;margin-bottom:18px}
        .info{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:16px 18px;margin:20px 0}
        .row{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #e2e8f0}
        .row:last-child{border-bottom:none}
        .label{font-weight:700;color:#64748b}.value{font-weight:700;color:#1e293b;text-align:right}
        .total{background:#1e3a8a;color:#fff;border-radius:14px;padding:18px;text-align:center;margin:20px 0}
        .total small{display:block;opacity:.9;margin-bottom:6px}.amount{font-size:32px;font-weight:800}
        .note{margin-top:20px;background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;border-radius:12px;padding:14px 16px;font-size:13px}
        .footer{padding:18px 24px;background:#f8fafc;text-align:center;font-size:12px;color:#64748b}
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            ' . ($logo_url_absoluta ? '<img src="' . $logo_url_absoluta . '" alt="Logo" class="logo">' : '') . '
            <h1 class="title">' . htmlspecialchars($gym_nombre) . '</h1>
            <p class="subtitle">Te compartimos el ticket de tu compra.</p>
        </div>
        <div class="content">
            <div class="hello">Hola, ' . htmlspecialchars(trim((string) ($venta['cliente_nombre'] ?? 'Cliente')) ?: 'Cliente') . '.</div>
            <div class="text">Adjunto encontrarás el ticket en PDF de tu compra realizada el ' . htmlspecialchars(formatearFechaTicket($venta['fecha_venta'] ?? null)) . '.</div>
            <div class="info">
                <div class="row"><span class="label">Ticket</span><span class="value">#' . str_pad((string) $venta_id, 8, '0', STR_PAD_LEFT) . '</span></div>
                <div class="row"><span class="label">Fecha</span><span class="value">' . htmlspecialchars(formatearFechaTicket($venta['fecha_venta'] ?? null)) . '</span></div>
                <div class="row"><span class="label">Método de pago</span><span class="value">' . htmlspecialchars(metodoPagoBonito($venta)) . '</span></div>
            </div>
            <div class="total"><small>Total pagado</small><div class="amount">$ ' . number_format((float) ($venta['total'] ?? 0), 2) . '</div></div>
            <div class="note"><strong>Importante:</strong> en este correo se adjunta tu ticket en formato PDF para que puedas guardarlo o imprimirlo cuando lo necesites.</div>
        </div>
        <div class="footer">&copy; ' . date('Y') . ' ' . htmlspecialchars($gym_nombre) . '</div>
    </div>
</body>
</html>';

$mail = null;

try {
    $configCorreo = obtenerConfiguracionCorreoSMTP($conn);

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = (string) $configCorreo['host'];
    $mail->Port = (int) $configCorreo['puerto'];
    $mail->SMTPAuth = (bool) $configCorreo['smtp_auth'];
    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 30;

    if ($mail->SMTPAuth) {
        $mail->Username = (string) $configCorreo['usuario'];
        $mail->Password = (string) $configCorreo['password_smtp'];
    }

    $cifrado = strtolower(trim((string) ($configCorreo['cifrado'] ?? '')));
    if (in_array($cifrado, ['ssl', 'smtps'], true)) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif (in_array($cifrado, ['tls', 'starttls'], true)) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }

    $verificarSsl = (int) ($configCorreo['verificar_ssl'] ?? 0) === 1;
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => $verificarSsl,
            'verify_peer_name' => $verificarSsl,
            'allow_self_signed' => !$verificarSsl
        ]
    ];

    $nombreRemitente = trim((string) ($configCorreo['remitente_nombre'] ?? ''));
    if ($nombreRemitente === '') {
        $nombreRemitente = $gym_nombre;
    }

    $mail->setFrom((string) $configCorreo['remitente_email'], $nombreRemitente);
    $mail->addAddress($email, trim((string) ($venta['cliente_nombre'] ?? '')) ?: 'Cliente');
    $mail->addStringAttachment($pdf_content, 'ticket_venta_' . $venta_id . '.pdf', 'base64', 'application/pdf');
    $mail->isHTML(true);
    $mail->Subject = $asunto;
    $mail->Body = $cuerpo_html;
    $mail->AltBody = html_entity_decode(strip_tags($cuerpo_html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $mail->send();

    echo json_encode([
        'success' => true,
        'message' => 'Ticket enviado correctamente a ' . $email
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $detalle = trim((string) $e->getMessage());
    if ($mail instanceof PHPMailer && trim((string) $mail->ErrorInfo) !== '') {
        $detalle = trim((string) $mail->ErrorInfo);
    }

    error_log('Error al reenviar ticket #' . $venta_id . ': ' . $detalle);

    echo json_encode([
        'success' => false,
        'message' => $detalle !== '' ? 'No fue posible enviar el ticket: ' . $detalle : 'No fue posible enviar el ticket.'
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if ($mail instanceof PHPMailer) {
        try {
            $mail->smtpClose();
        } catch (Throwable $ignored) {
        }
    }
}
?>