<?php
// includes/enviar_correo_phpmailer.php

require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/SMTP.php';
require_once __DIR__ . '/../PHPMailer/Exception.php';
require_once __DIR__ . '/fpdf_ticket.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function enviarTicketInscripcion($email, $nombre_cliente, $plan_nombre, $fecha_inicio, $fecha_fin, $monto, $metodo_pago, $referencia) {
    
    $asunto = "Bienvenido al Gimnasio - Ticket de Inscripcion";
    
    $cuerpo_html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Ticket de Inscripcion</title>
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
            .header h1 {
                margin: 0;
                font-size: 26px;
                letter-spacing: 1px;
            }
            .header p {
                margin: 8px 0 0;
                opacity: 0.9;
                font-size: 14px;
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
            .footer {
                background: #f8fafc;
                padding: 20px;
                text-align: center;
                font-size: 12px;
                color: #64748b;
                border-top: 1px solid #e2e8f0;
            }
            .btn-download {
                display: inline-block;
                background: #1e3a8a;
                color: white;
                text-decoration: none;
                padding: 10px 20px;
                border-radius: 8px;
                margin-top: 15px;
                font-size: 14px;
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
                <h1>🏋️ SISTEMA GIMNASIO</h1>
                <p>Comprobante Oficial de Pago</p>
            </div>
            <div class="content">
                <div class="greeting">¡Hola, ' . htmlspecialchars($nombre_cliente) . '!</div>
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
                
                <div class="total">
                    <div>TOTAL PAGADO</div>
                    <div class="amount">$ ' . number_format($monto, 2) . '</div>
                    <div class="badge">PAGADO</div>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <div style="background: #f0fdf4; padding: 15px; border-radius: 10px; border-left: 4px solid #10b981;">
                        <strong style="color: #166534;">✅ INFORMACION IMPORTANTE</strong>
                        <p style="margin: 8px 0 0; font-size: 13px; color: #166534;">
                            Presenta este correo o tu identificacion en recepcion para ingresar al gimnasio.
                        </p>
                    </div>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <p style="font-size: 13px; color: #666;">
                        <strong>📎 Adjunto a este correo encontraras tu ticket en formato PDF.</strong>
                    </p>
                </div>
            </div>
            <div class="footer">
                <p>&copy; ' . date('Y') . ' Sistema Gimnasio - Todos los derechos reservados</p>
                <p>Este es un comprobante de pago valido</p>
            </div>
        </div>
    </body>
    </html>';
    
    $pdf_content = generarPDFTicket($nombre_cliente, $plan_nombre, $fecha_inicio, $fecha_fin, $monto, $metodo_pago, $referencia, 'inscripcion');
    return enviarCorreoSMTP($email, $nombre_cliente, $asunto, $cuerpo_html, $pdf_content);
}

function enviarTicketRenovacion($email, $nombre_cliente, $plan_nombre, $fecha_inicio, $fecha_fin, $monto, $metodo_pago, $referencia) {
    
    $asunto = "Renovacion Exitosa - Ticket de Renovacion";
    
    $cuerpo_html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Ticket de Renovacion</title>
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
            .header h1 {
                margin: 0;
                font-size: 26px;
                letter-spacing: 1px;
            }
            .header p {
                margin: 8px 0 0;
                opacity: 0.9;
                font-size: 14px;
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
                <h1>🔄 SISTEMA GIMNASIO</h1>
                <p>Comprobante de Renovacion</p>
            </div>
            <div class="content">
                <div class="greeting">¡Hola, ' . htmlspecialchars($nombre_cliente) . '!</div>
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
                
                <div style="text-align: center; margin-top: 20px;">
                    <div style="background: #f0fdf4; padding: 15px; border-radius: 10px; border-left: 4px solid #10b981;">
                        <strong style="color: #166534;">✅ RENOVACION EXITOSA</strong>
                        <p style="margin: 8px 0 0; font-size: 13px; color: #166534;">
                            Tu membresia ha sido renovada exitosamente. Disfruta de tus nuevos beneficios.
                        </p>
                    </div>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <p style="font-size: 13px; color: #666;">
                        <strong>📎 Adjunto a este correo encontraras tu ticket en formato PDF.</strong>
                    </p>
                </div>
            </div>
            <div class="footer">
                <p>&copy; ' . date('Y') . ' Sistema Gimnasio - Todos los derechos reservados</p>
                <p>Este es un comprobante de pago valido</p>
            </div>
        </div>
    </body>
    </html>';
    
    $pdf_content = generarPDFTicket($nombre_cliente, $plan_nombre, $fecha_inicio, $fecha_fin, $monto, $metodo_pago, $referencia, 'renovacion');
    return enviarCorreoSMTP($email, $nombre_cliente, $asunto, $cuerpo_html, $pdf_content);
}

function enviarCorreoSMTP($email, $nombre, $asunto, $cuerpo_html, $pdf_content = null) {
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
        
        $mail->setFrom('jesusgabrielmtz78@gmail.com', 'Sistema Gimnasio');
        $mail->addAddress($email, $nombre);
        
        if ($pdf_content) {
            $mail->addStringAttachment($pdf_content, 'ticket_gimnasio.pdf', 'base64', 'application/pdf');
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