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

// Obtener detalles
$query_detalles = "SELECT dv.*, p.nombre as producto_nombre 
                   FROM detalle_ventas dv
                   LEFT JOIN productos p ON dv.producto_id = p.id
                   WHERE dv.venta_id = ?";
$stmt = $conn->prepare($query_detalles);
$stmt->bind_param("i", $venta_id);
$stmt->execute();
$detalles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Obtener configuración del gimnasio
$query_config = "SELECT nombre, logo, telefono, email, direccion FROM configuracion_gimnasio WHERE id = 1";
$result_config = $conn->query($query_config);
$config = $result_config->fetch_assoc();
$gym_nombre = $config['nombre'] ?? 'EGO GYM';
$gym_logo = $config['logo'] ?? '';
$gym_email = $config['email'] ?? 'egogym@gmail.com';
$gym_telefono = $config['telefono'] ?? '';
$gym_direccion = $config['direccion'] ?? '';

// Función para obtener URL absoluta del logo
function getLogoUrlAbsoluta($ruta_relativa) {
    if (empty($ruta_relativa)) {
        return '';
    }
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $base_url = $protocol . $host . '/';
    $ruta_limpia = ltrim($ruta_relativa, '/');
    return $base_url . $ruta_limpia;
}

// Generar PDF del ticket
function generarPDFTicketVenta($venta_id, $detalles, $venta, $gym_nombre, $gym_logo) {
    $pdf = new FPDF('P', 'mm', array(80, 200));
    $pdf->AddPage();
    $pdf->SetFont('Courier', '', 10);

    // Logo
    if (!empty($gym_logo) && file_exists('../' . $gym_logo)) {
        $pdf->Image('../' . $gym_logo, 25, 5, 30);
        $pdf->Ln(30);
    } else {
        $pdf->Ln(10);
    }

    // Encabezado
    $pdf->SetFont('Courier', 'B', 12);
    $pdf->Cell(0, 6, utf8_decode($gym_nombre), 0, 1, 'C');
    $pdf->SetFont('Courier', '', 9);
    $pdf->Cell(0, 5, 'Ticket de Venta #' . $venta_id, 0, 1, 'C');
    $pdf->Cell(0, 5, date('d/m/Y H:i:s', strtotime($venta['fecha_venta'])), 0, 1, 'C');
    $pdf->Ln(3);
    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
    $pdf->Ln(3);

    // Productos
    foreach ($detalles as $detalle) {
        $pdf->SetFont('Courier', 'B', 10);
        $pdf->Cell(0, 5, utf8_decode($detalle['producto_nombre']) . ' x' . $detalle['cantidad'], 0, 1, 'L');
        $pdf->SetFont('Courier', '', 10);
        $pdf->Cell(65, 5, '', 0, 0);
        $pdf->Cell(0, 5, '$' . number_format($detalle['subtotal'], 2), 0, 1, 'R');
        $pdf->Ln(2);
    }

    $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
    $pdf->Ln(3);
    
    // Total
    $pdf->SetFont('Courier', 'B', 11);
    $pdf->Cell(50, 7, 'TOTAL', 0, 0, 'L');
    $pdf->Cell(0, 7, '$' . number_format($venta['total'], 2), 0, 1, 'R');

    $pdf->SetY(-15);
    $pdf->SetFont('Courier', 'I', 8);
    $pdf->Cell(0, 5, 'Gracias por su compra', 0, 1, 'C');

    return $pdf->Output('S');
}

$pdf_content = generarPDFTicketVenta($venta_id, $detalles, $venta, $gym_nombre, $gym_logo);
$logo_url_absoluta = getLogoUrlAbsoluta($gym_logo);

// Generar HTML del email
$asunto = "Ticket de Venta #$venta_id - " . htmlspecialchars($gym_nombre);

$cuerpo_html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Ticket de Venta - ' . htmlspecialchars($gym_nombre) . '</title>
    <style>
        body {
            font-family: "Segoe UI", Arial, sans-serif;
            background-color: #f0f2f5;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: white;
            padding: 25px;
            text-align: center;
        }
        .logo {
            max-width: 80px;
            max-height: 80px;
            margin-bottom: 15px;
            border-radius: 50%;
            background: white;
            padding: 8px;
            object-fit: contain;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .content {
            padding: 30px;
        }
        .greeting {
            font-size: 18px;
            color: #1e3a8a;
            margin-bottom: 15px;
        }
        .message {
            color: #555;
            line-height: 1.6;
            margin-bottom: 25px;
        }
        .ticket-info {
            background: #f8fafc;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
            border-left: 4px solid #1e3a8a;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .label {
            font-weight: 600;
            color: #475569;
        }
        .value {
            color: #1e293b;
            font-weight: 500;
        }
        .total {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin: 20px 0;
        }
        .total .amount {
            font-size: 32px;
            font-weight: bold;
        }
        .footer {
            background: #f8fafc;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            ' . ($logo_url_absoluta ? '<img src="' . $logo_url_absoluta . '" alt="Logo" class="logo">' : '<div style="width:80px;height:80px;margin:0 auto 15px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;"><span style="font-size:40px;">🏋️</span></div>') . '
            <h1>' . htmlspecialchars($gym_nombre) . '</h1>
        </div>
        <div class="content">
            <div class="greeting">Hola, ' . htmlspecialchars($venta['cliente_nombre'] ?? 'Cliente') . '!</div>
            <div class="message">
                Adjunto encontrarás el ticket de tu compra realizada el día ' . date('d/m/Y H:i:s', strtotime($venta['fecha_venta'])) . '.
            </div>
            
            <div class="ticket-info">
                <div class="info-row">
                    <span class="label">Ticket #:</span>
                    <span class="value">' . str_pad($venta_id, 8, '0', STR_PAD_LEFT) . '</span>
                </div>
                <div class="info-row">
                    <span class="label">Fecha:</span>
                    <span class="value">' . date('d/m/Y H:i:s', strtotime($venta['fecha_venta'])) . '</span>
                </div>
                <div class="info-row">
                    <span class="label">Método de pago:</span>
                    <span class="value">' . ucfirst($venta['metodo_pago']) . '</span>
                </div>
            </div>
            
            <div class="total">
                <div>TOTAL PAGADO</div>
                <div class="amount">$ ' . number_format($venta['total'], 2) . '</div>
            </div>
            
            <div style="text-align: center; margin-top: 20px;">
                <p style="font-size: 13px; color: #666;">
                    <strong>Adjunto a este correo encontrarás tu ticket en formato PDF.</strong>
                </p>
            </div>
        </div>
        <div class="footer">
            <p>&copy; ' . date('Y') . ' ' . htmlspecialchars($gym_nombre) . ' - Todos los derechos reservados</p>
        </div>
    </div>
</body>
</html>';

// Enviar correo usando SMTP
try {
    $mail = new PHPMailer(true);
    
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'jesusgabrielmtz78@gmail.com';
    $mail->Password = 'iwdf uyqu erzq wvbm';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    
    $mail->SMTPOptions = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        )
    );
    
    $mail->setFrom('jesusgabrielmtz78@gmail.com', $gym_nombre);
    $mail->addAddress($email, $venta['cliente_nombre'] ?? 'Cliente');
    
    // Adjuntar PDF
    $mail->addStringAttachment($pdf_content, 'ticket_venta_' . $venta_id . '.pdf', 'base64', 'application/pdf');
    
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = $asunto;
    $mail->Body = $cuerpo_html;
    $mail->AltBody = strip_tags($cuerpo_html);
    
    $mail->send();
    
    echo json_encode(['success' => true, 'message' => 'Ticket enviado correctamente a ' . $email]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error al enviar: ' . $mail->ErrorInfo]);
}
?>