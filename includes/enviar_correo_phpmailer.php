<?php

date_default_timezone_set('America/Mexico_City');
// includes/enviar_correo_phpmailer.php

require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/SMTP.php';
require_once __DIR__ . '/../PHPMailer/Exception.php';
require_once __DIR__ . '/fpdf_ticket.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Funcion para obtener configuracion del gimnasio
function getConfiguracionGimnasio() {
    global $conn;
    
    $query = "SELECT nombre, email, direccion, horario, logo FROM configuracion_gimnasio WHERE id = 1";
    $result = $conn->query($query);
    
    if ($result && $row = $result->fetch_assoc()) {
        if (!empty($row['logo']) && file_exists($row['logo'])) {
            return $row;
        }
    }
    
    $extensiones = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg', 'ico'];
    foreach ($extensiones as $ext) {
        $ruta = "img/logo-gym." . $ext;
        if (file_exists($ruta)) {
            return [
                'nombre' => $row['nombre'] ?? 'Gimnasio',
                'email' => $row['email'] ?? '',
                'direccion' => $row['direccion'] ?? '',
                'horario' => $row['horario'] ?? 'Lunes a Viernes 6am-10pm, Sabado 8am-6pm',
                'logo' => $ruta
            ];
        }
    }
    
    return [
        'nombre' => 'Gimnasio',
        'email' => '',
        'direccion' => '',
        'horario' => 'Lunes a Viernes 6am-10pm, Sabado 8am-6pm',
        'logo' => ''
    ];
}

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

// ========== FUNCIÓN PRINCIPAL PARA ENVIAR TICKET DE INSCRIPCIÓN ==========
function enviarTicketInscripcion($email, $nombre_cliente, $plan_nombre, $fecha_inicio, $fecha_fin, $monto, $metodo_pago, $referencia, $codigo_qr = null, $ruta_qr = null) {
    $config = getConfiguracionGimnasio();
    $logo_url_absoluta = getLogoUrlAbsoluta($config['logo']);
    
    // ========== PREPARAR QR PARA ADJUNTAR ==========
    $qr_content = null;
    $qr_adjuntado = false;
    
    if ($ruta_qr && file_exists($ruta_qr)) {
        $qr_content = file_get_contents($ruta_qr);
        if ($qr_content !== false) {
            $qr_adjuntado = true;
        }
    }
    
    // HTML del correo (sin imagen QR incrustada, solo texto informativo)
    $qr_html = '';
    if ($codigo_qr) {
        $qr_html = '
        <div style="text-align: center; margin: 25px 0; padding: 20px; background: #f0fdf4; border-radius: 10px; border: 1px solid #bbf7d0;">
            <div style="background: #1e3a8a; color: white; padding: 10px; border-radius: 8px; margin-bottom: 15px;">
                <strong>📎 CÓDIGO QR ADJUNTO</strong>
            </div>
            <p style="font-size: 14px; color: #166534;">
                Tu código QR personal ha sido <strong>adjuntado a este correo</strong> como archivo PNG.
            </p>
            <p style="font-size: 12px; color: #666; margin-top: 10px;">
                Por favor, revisa los archivos adjuntos para encontrar tu código.
            </p>
            <p style="font-size: 11px; background: #f8fafc; padding: 8px; border-radius: 5px; word-break: break-all; margin-top: 15px;">
                Código de membresía: <strong>' . htmlspecialchars($codigo_qr) . '</strong>
            </p>
        </div>';
    }
    
    $asunto = "Bienvenido al Gimnasio - Ticket de Inscripcion";
    
    $cuerpo_html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Ticket de Inscripcion - ' . htmlspecialchars($config['nombre']) . '</title>
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
                letter-spacing: 1px;
            }
            .header p {
                margin: 8px 0 0;
                opacity: 0.9;
                font-size: 13px;
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
                margin: 5px 0;
            }
            .badge {
                display: inline-block;
                padding: 6px 14px;
                background: #10b981;
                color: white;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
                margin-top: 10px;
            }
            .gym-info {
                background: #eff6ff;
                border-radius: 10px;
                padding: 15px;
                margin: 20px 0;
                border: 1px solid #bfdbfe;
            }
            .gym-info-title {
                font-weight: bold;
                color: #1e3a8a;
                margin-bottom: 10px;
                font-size: 14px;
            }
            .gym-info-row {
                display: flex;
                align-items: center;
                padding: 5px 0;
                font-size: 13px;
                color: #334155;
            }
            .gym-info-row strong {
                width: 80px;
            }
            .footer {
                background: #f8fafc;
                padding: 20px;
                text-align: center;
                font-size: 12px;
                color: #64748b;
                border-top: 1px solid #e2e8f0;
            }
            .highlight {
                color: #1e3a8a;
                font-weight: bold;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                ' . ($logo_url_absoluta ? '<img src="' . $logo_url_absoluta . '" alt="Logo" class="logo" style="max-width:80px;max-height:80px;object-fit:contain;">' : '<div style="width:80px;height:80px;margin:0 auto 15px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;"><span style="font-size:40px;">🏋️</span></div>') . '
                <h1>' . htmlspecialchars($config['nombre']) . '</h1>
                <p>Comprobante Oficial de Pago</p>
            </div>
            <div class="content">
                <div class="greeting">Hola, ' . htmlspecialchars($nombre_cliente) . '!</div>
                <div class="message">
                    Gracias por confiar en nosotros. Tu <span class="highlight">inscripcion</span> ha sido procesada exitosamente.
                </div>
                
                <div class="ticket-info">
                    <div class="info-row">
                        <span class="label">Plan contratado:</span>
                        <span class="value">' . htmlspecialchars($plan_nombre) . '</span>
                    </div>
                    <div class="info-row">
                        <span class="label">Fecha de inicio:</span>
                        <span class="value">' . date('d/m/Y', strtotime($fecha_inicio)) . '</span>
                    </div>
                    <div class="info-row">
                        <span class="label">Fecha de vencimiento:</span>
                        <span class="value">' . ($fecha_fin ? date('d/m/Y', strtotime($fecha_fin)) : 'Sin vencimiento') . '</span>
                    </div>
                    <div class="info-row">
                        <span class="label">Metodo de pago:</span>
                        <span class="value">' . ucfirst($metodo_pago) . '</span>
                    </div>';
    
    if ($referencia) {
        $cuerpo_html .= '
                    <div class="info-row">
                        <span class="label">Referencia:</span>
                        <span class="value">' . htmlspecialchars($referencia) . '</span>
                    </div>';
    }
    
    $cuerpo_html .= '
                </div>
                
                ' . $qr_html . '
                
                <div class="total">
                    <div>TOTAL PAGADO</div>
                    <div class="amount">$ ' . number_format($monto, 2) . '</div>
                    <div class="badge">PAGADO</div>
                </div>
                
                <div class="gym-info">
                    <div class="gym-info-title">INFORMACION DEL GIMNASIO</div>
                    <div class="gym-info-row"><strong>Direccion:</strong> ' . htmlspecialchars($config['direccion'] ?: 'No especificada') . '</div>
                    <div class="gym-info-row"><strong>Email:</strong> ' . htmlspecialchars($config['email'] ?: 'No especificado') . '</div>
                    <div class="gym-info-row"><strong>Horario:</strong> ' . htmlspecialchars($config['horario'] ?: 'Lunes a Viernes 6am-10pm, Sabado 8am-6pm') . '</div>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <div style="background: #f0fdf4; padding: 15px; border-radius: 10px; border-left: 4px solid #10b981;">
                        <strong style="color: #166534;">INFORMACION IMPORTANTE</strong>
                        <p style="margin: 8px 0 0; font-size: 13px; color: #166534;">
                            Este comprobante es un documento personal e intransferible. Conservalo para cualquier aclaracion futura.
                        </p>
                    </div>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <p style="font-size: 13px; color: #666;">
                        <strong>Adjunto a este correo encontraras tu ticket en formato PDF y tu código QR.</strong>
                    </p>
                </div>
            </div>
            <div class="footer">
                <p>&copy; ' . date('Y') . ' ' . htmlspecialchars($config['nombre']) . ' - Todos los derechos reservados</p>
                <p>Este es un comprobante de pago valido</p>
            </div>
        </div>
    </body>
    </html>';
    
    $pdf_content = generarPDFTicket($nombre_cliente, $plan_nombre, $fecha_inicio, $fecha_fin, $monto, $metodo_pago, $referencia, 'inscripcion');
    
    // Enviar correo con QR adjunto
    return enviarCorreoSMTP($email, $nombre_cliente, $asunto, $cuerpo_html, $pdf_content, $qr_content, 'codigo_qr.png');
}

// ========== FUNCIÓN PARA ENVIAR TICKET DE RENOVACIÓN ==========
function enviarTicketRenovacion($email, $nombre_cliente, $plan_nombre, $fecha_inicio, $fecha_fin, $monto, $metodo_pago, $referencia) {
    $config = getConfiguracionGimnasio();
    $logo_url_absoluta = getLogoUrlAbsoluta($config['logo']);
    
    $asunto = "Renovacion Exitosa - Ticket de Renovacion - " . htmlspecialchars($config['nombre']);
    
    $cuerpo_html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Ticket de Renovacion - ' . htmlspecialchars($config['nombre']) . '</title>
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
                background: linear-gradient(135deg, #059669 0%, #10b981 100%);
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
                letter-spacing: 1px;
            }
            .header p {
                margin: 8px 0 0;
                opacity: 0.9;
                font-size: 13px;
            }
            .content {
                padding: 30px;
            }
            .greeting {
                font-size: 18px;
                color: #059669;
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
                border-left: 4px solid #059669;
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
                background: linear-gradient(135deg, #059669 0%, #10b981 100%);
                color: white;
                padding: 20px;
                border-radius: 10px;
                text-align: center;
                margin: 20px 0;
            }
            .total .amount {
                font-size: 32px;
                font-weight: bold;
                margin: 5px 0;
            }
            .badge {
                display: inline-block;
                padding: 6px 14px;
                background: #1e3a8a;
                color: white;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
                margin-top: 10px;
            }
            .gym-info {
                background: #eff6ff;
                border-radius: 10px;
                padding: 15px;
                margin: 20px 0;
                border: 1px solid #bfdbfe;
            }
            .gym-info-title {
                font-weight: bold;
                color: #1e3a8a;
                margin-bottom: 10px;
                font-size: 14px;
            }
            .gym-info-row {
                display: flex;
                align-items: center;
                padding: 5px 0;
                font-size: 13px;
                color: #334155;
            }
            .gym-info-row strong {
                width: 80px;
            }
            .footer {
                background: #f8fafc;
                padding: 20px;
                text-align: center;
                font-size: 12px;
                color: #64748b;
                border-top: 1px solid #e2e8f0;
            }
            .highlight {
                color: #059669;
                font-weight: bold;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                ' . ($logo_url_absoluta ? '<img src="' . $logo_url_absoluta . '" alt="Logo" class="logo" style="max-width:80px;max-height:80px;object-fit:contain;">' : '<div style="width:80px;height:80px;margin:0 auto 15px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;"><span style="font-size:40px;">🏋️</span></div>') . '
                <h1>' . htmlspecialchars($config['nombre']) . '</h1>
                <p>Comprobante de Renovacion</p>
            </div>
            <div class="content">
                <div class="greeting">Hola, ' . htmlspecialchars($nombre_cliente) . '!</div>
                <div class="message">
                    Gracias por renovar tu membresia. Tu plan ha sido <span class="highlight">actualizado exitosamente</span>.
                </div>
                
                <div class="ticket-info">
                    <div class="info-row">
                        <span class="label">Plan renovado:</span>
                        <span class="value">' . htmlspecialchars($plan_nombre) . '</span>
                    </div>
                    <div class="info-row">
                        <span class="label">Nueva fecha de inicio:</span>
                        <span class="value">' . date('d/m/Y', strtotime($fecha_inicio)) . '</span>
                    </div>
                    <div class="info-row">
                        <span class="label">Nueva fecha de vencimiento:</span>
                        <span class="value">' . ($fecha_fin ? date('d/m/Y', strtotime($fecha_fin)) : 'Sin vencimiento') . '</span>
                    </div>
                    <div class="info-row">
                        <span class="label">Metodo de pago:</span>
                        <span class="value">' . ucfirst($metodo_pago) . '</span>
                    </div>';
    
    if ($referencia) {
        $cuerpo_html .= '
                    <div class="info-row">
                        <span class="label">Referencia:</span>
                        <span class="value">' . htmlspecialchars($referencia) . '</span>
                    </div>';
    }
    
    $cuerpo_html .= '
                </div>
                
                <div class="total">
                    <div>TOTAL PAGADO</div>
                    <div class="amount">$ ' . number_format($monto, 2) . '</div>
                    <div class="badge">RENOVADO</div>
                </div>
                
                <div class="gym-info">
                    <div class="gym-info-title">INFORMACION DEL GIMNASIO</div>
                    <div class="gym-info-row"><strong>Direccion:</strong> ' . htmlspecialchars($config['direccion'] ?: 'No especificada') . '</div>
                    <div class="gym-info-row"><strong>Email:</strong> ' . htmlspecialchars($config['email'] ?: 'No especificado') . '</div>
                    <div class="gym-info-row"><strong>Horario:</strong> ' . htmlspecialchars($config['horario'] ?: 'Lunes a Viernes 6am-10pm, Sabado 8am-6pm') . '</div>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <div style="background: #f0fdf4; padding: 15px; border-radius: 10px; border-left: 4px solid #10b981;">
                        <strong style="color: #166534;">RENOVACION EXITOSA</strong>
                        <p style="margin: 8px 0 0; font-size: 13px; color: #166534;">
                            Tu membresia ha sido renovada exitosamente. Disfruta de tus nuevos beneficios.
                        </p>
                    </div>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <p style="font-size: 13px; color: #666;">
                        <strong>Adjunto a este correo encontraras tu ticket en formato PDF.</strong>
                    </p>
                </div>
            </div>
            <div class="footer">
                <p>&copy; ' . date('Y') . ' ' . htmlspecialchars($config['nombre']) . ' - Todos los derechos reservados</p>
                <p>Este es un comprobante de pago valido</p>
            </div>
        </div>
    </body>
    </html>';
    
    $pdf_content = generarPDFTicket($nombre_cliente, $plan_nombre, $fecha_inicio, $fecha_fin, $monto, $metodo_pago, $referencia, 'renovacion');
    return enviarCorreoSMTP($email, $nombre_cliente, $asunto, $cuerpo_html, $pdf_content);
}

// ========== FUNCIÓN PRINCIPAL DE ENVÍO SMTP ==========
function enviarCorreoSMTP($email, $nombre, $asunto, $cuerpo_html, $pdf_content = null, $qr_content = null, $qr_nombre = null) {
    $config = getConfiguracionGimnasio();
    $mail = new PHPMailer(true);
    
    try {
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
        
        $nombre_gimnasio = !empty($config['nombre']) ? $config['nombre'] : 'Sistema Gimnasio';
        $mail->setFrom('jesusgabrielmtz78@gmail.com', $nombre_gimnasio);
        $mail->addAddress($email, $nombre);
        
        // Adjuntar PDF del ticket
        if ($pdf_content) {
            $mail->addStringAttachment($pdf_content, 'ticket_gimnasio.pdf', 'base64', 'application/pdf');
        }
        
        // ========== ADJUNTAR CÓDIGO QR ==========
        if ($qr_content && $qr_nombre) {
            $mail->addStringAttachment($qr_content, $qr_nombre, 'base64', 'image/png');
        }
        
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body = $cuerpo_html;
        $mail->AltBody = strip_tags($cuerpo_html);
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Error PHPMailer: " . $mail->ErrorInfo);
        return false;
    }
}
?>