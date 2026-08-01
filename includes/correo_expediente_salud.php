<?php

declare(strict_types=1);

require_once __DIR__ . '/correo_sistema.php';

function expediente_correo_error(string $mensaje): void
{
    $GLOBALS['ultimo_error_correo_expediente_salud'] = trim($mensaje);
    correo_sistema_error(trim($mensaje));
}

function expediente_correo_ultimo_error(): string
{
    $propio = trim((string) (
        $GLOBALS['ultimo_error_correo_expediente_salud'] ?? ''
    ));

    return $propio !== '' ? $propio : correo_sistema_ultimo_error();
}

function expediente_correo_h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

/**
 * Envía en un único mensaje la confirmación de inscripción, el QR y el enlace
 * privado del cuestionario. El comprobante PDF se genera dentro del worker y
 * se adjunta al mismo correo, sin bloquear el alta de la inscripción.
 */
function enviarCorreoInvitacionExpedienteSalud(
    mysqli $conn,
    string $email,
    string $nombreSocio,
    string $url,
    string $venceEn,
    array $datosInscripcion = [],
    int $invitacionId = 0
): bool {
    $GLOBALS['ultimo_error_correo_expediente_salud'] = '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        expediente_correo_error('El correo del socio no es válido.');
        return false;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        expediente_correo_error('El enlace del cuestionario no es válido.');
        return false;
    }

    $paquete = correo_sistema_crear_mailer($conn, 6);
    if (!$paquete || empty($paquete['mail'])) {
        return false;
    }

    /** @var \PHPMailer\PHPMailer\PHPMailer $mail */
    $mail = $paquete['mail'];
    $gimnasio = trim((string) ($paquete['gimnasio'] ?? 'EGO')) ?: 'EGO';
    $venceTexto = date('d/m/Y H:i', strtotime($venceEn) ?: time());

    $plan = trim((string) ($datosInscripcion['plan'] ?? ''));
    $fechaInicio = trim((string) ($datosInscripcion['fecha_inicio'] ?? ''));
    $fechaFin = trim((string) ($datosInscripcion['fecha_fin'] ?? ''));
    $monto = isset($datosInscripcion['monto'])
        ? (float) $datosInscripcion['monto']
        : 0.0;
    $metodoPago = trim((string) ($datosInscripcion['metodo_pago'] ?? ''));
    $codigoQr = trim((string) ($datosInscripcion['codigo_qr'] ?? ''));
    $rutaQr = correo_sistema_ruta_archivo(
        (string) ($datosInscripcion['ruta_qr'] ?? '')
    );

    /*
     * Compatibilidad con correos encolados antes de esta corrección: si el
     * payload todavía no contiene historial_pago_id, lo recuperamos desde la
     * invitación y su inscripción asociada.
     */
    if (
        (int) ($datosInscripcion['historial_pago_id'] ?? 0) <= 0
        && $invitacionId > 0
    ) {
        try {
            $stmtDocumento = $conn->prepare(
                "SELECT MAX(hp.id) AS historial_pago_id
                 FROM expedientes_salud_invitaciones inv
                 INNER JOIN historial_pagos hp
                    ON hp.inscripcion_id = inv.inscripcion_id
                 WHERE inv.id = ?
                   AND hp.monto > 0"
            );
            $stmtDocumento->bind_param('i', $invitacionId);
            $stmtDocumento->execute();
            $filaDocumento = $stmtDocumento->get_result()->fetch_assoc();
            $stmtDocumento->close();

            $datosInscripcion['historial_pago_id'] = (int) (
                $filaDocumento['historial_pago_id'] ?? 0
            );
        } catch (Throwable $e) {
            expediente_correo_error(
                'No fue posible localizar el comprobante de la inscripción: '
                . $e->getMessage()
            );
            return false;
        }
    }

    $comprobante = correo_sistema_resolver_comprobante(
        $conn,
        $datosInscripcion
    );
    if (empty($comprobante['success'])) {
        expediente_correo_error(
            'No fue posible preparar el comprobante PDF: '
            . (string) ($comprobante['error'] ?? 'Error desconocido')
        );
        return false;
    }

    $qrHtml = '';
    if ($codigoQr !== '') {
        $qrHtml = '<div style="margin:20px 0;text-align:center;padding:16px;border:1px solid #dfe5ee;border-radius:12px;background:#f8fafc">'
            . '<div style="font-size:12px;color:#667085;margin-bottom:7px">Código de acceso</div>'
            . '<div style="font-family:monospace;font-size:16px;font-weight:700;color:#172033">'
            . expediente_correo_h($codigoQr)
            . '</div></div>';
    }

    try {
        if ($rutaQr !== '') {
            $mail->addEmbeddedImage(
                $rutaQr,
                'qr_expediente',
                'codigo-qr-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $codigoQr) . '.png',
                'base64',
                'image/png'
            );

            $qrHtml = '<div style="margin:22px 0;text-align:center">'
                . '<img src="cid:qr_expediente" alt="Código QR del socio" style="display:block;width:210px;max-width:100%;height:auto;margin:0 auto 9px">'
                . '<div style="font-family:monospace;font-size:15px;font-weight:700;color:#172033">'
                . expediente_correo_h($codigoQr)
                . '</div><div style="margin-top:7px;color:#667085;font-size:12px">Presenta este QR para registrar tu acceso.</div></div>';
        }

        $mail->addAddress($email, $nombreSocio);
        $mail->Subject = 'Inscripción y cuestionario de salud - ' . $gimnasio;
        $mail->addAttachment(
            (string) $comprobante['path'],
            (string) $comprobante['name'],
            'base64',
            'application/pdf'
        );

        $mail->Body = '<!doctype html><html lang="es"><body style="margin:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#172033">'
            . '<div style="padding:28px 14px"><div style="max-width:660px;margin:0 auto;background:#fff;border:1px solid #dfe5ee;border-radius:18px;overflow:hidden">'
            . '<div style="padding:26px 30px;background:#244292;color:#fff">'
            . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.86">' . expediente_correo_h($gimnasio) . '</div>'
            . '<h1 style="margin:8px 0 5px;font-size:26px">Inscripción confirmada</h1>'
            . '<p style="margin:0;opacity:.9;font-size:14px">Tu acceso y cuestionario de salud están listos.</p>'
            . '</div><div style="padding:28px 30px">'
            . '<p style="margin:0 0 16px;font-size:16px">Hola <strong>' . expediente_correo_h($nombreSocio) . '</strong>,</p>'
            . '<p style="margin:0 0 18px;color:#526075;line-height:1.65">Tu inscripción quedó registrada correctamente. Conserva este correo para consultar tus datos de acceso.</p>'
            . '<div style="margin:0 0 20px;padding:16px;border:1px solid #dfe5ee;border-radius:12px;background:#f8fafc">'
            . '<table style="width:100%;border-collapse:collapse;color:#526075;font-size:14px">'
            . ($plan !== '' ? '<tr><td style="padding:5px 0">Plan</td><td style="padding:5px 0;text-align:right;font-weight:700;color:#172033">' . expediente_correo_h($plan) . '</td></tr>' : '')
            . ($fechaInicio !== '' ? '<tr><td style="padding:5px 0">Inicio</td><td style="padding:5px 0;text-align:right;font-weight:700;color:#172033">' . expediente_correo_h($fechaInicio) . '</td></tr>' : '')
            . ($fechaFin !== '' ? '<tr><td style="padding:5px 0">Vigencia</td><td style="padding:5px 0;text-align:right;font-weight:700;color:#172033">' . expediente_correo_h($fechaFin) . '</td></tr>' : '')
            . ($monto > 0 ? '<tr><td style="padding:5px 0">Pago</td><td style="padding:5px 0;text-align:right;font-weight:700;color:#172033">$' . number_format($monto, 2) . '</td></tr>' : '')
            . ($metodoPago !== '' ? '<tr><td style="padding:5px 0">Método</td><td style="padding:5px 0;text-align:right;font-weight:700;color:#172033">' . expediente_correo_h($metodoPago) . '</td></tr>' : '')
            . '</table></div>'
            . $qrHtml
            . '<div style="margin-top:24px;padding-top:22px;border-top:1px solid #e6eaf0">'
            . '<h2 style="margin:0 0 9px;font-size:19px;color:#172033">Completa tu expediente de salud</h2>'
            . '<p style="margin:0 0 18px;color:#526075;line-height:1.65">Responde el cuestionario médico administrativo y acepta el documento de responsabilidad. El formulario no sustituye una valoración médica.</p>'
            . '<div style="text-align:center;margin:25px 0">'
            . '<a href="' . expediente_correo_h($url) . '" style="display:inline-block;padding:14px 22px;border-radius:10px;background:#244292;color:#fff;text-decoration:none;font-weight:700">Responder cuestionario</a>'
            . '</div>'
            . '<div style="padding:14px 16px;border:1px solid #dbeafe;border-radius:10px;background:#eff6ff;color:#1e40af;font-size:13px;line-height:1.55">El enlace es personal, solo puede utilizarse una vez y estará disponible hasta <strong>' . expediente_correo_h($venceTexto) . '</strong>.</div>'
            . '</div>'
            . '<p style="margin:20px 0 0;color:#7b8799;font-size:12px;line-height:1.5">No compartas el enlace. También adjuntamos el comprobante PDF de tu inscripción. El documento permanece disponible en el historial del sistema.</p>'
            . '</div></div></div></body></html>';

        $mail->AltBody = "Hola {$nombreSocio}. Tu inscripción en {$gimnasio} fue registrada. Adjuntamos tu comprobante PDF. Código de acceso: {$codigoQr}. Completa tu cuestionario de salud en: {$url}. El enlace vence el {$venceTexto}.";
        $mail->send();
        return true;
    } catch (Throwable $e) {
        expediente_correo_error($e->getMessage());
        return false;
    }
}

function enviarCorreoExpedienteSaludCompletado(
    mysqli $conn,
    int $expedienteId,
    string $email,
    string $nombreSocio
): bool {
    $GLOBALS['ultimo_error_correo_expediente_salud'] = '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        expediente_correo_error('El correo del socio no es válido.');
        return false;
    }

    if ($expedienteId <= 0) {
        expediente_correo_error('El expediente no es válido.');
        return false;
    }

    require_once __DIR__ . '/expediente_salud_pdf_helper.php';

    try {
        $pdf = expediente_generar_pdf_memoria($conn, $expedienteId);
    } catch (Throwable $e) {
        expediente_correo_error(
            'No fue posible generar el PDF del expediente: ' . $e->getMessage()
        );
        return false;
    }

    $paquete = correo_sistema_crear_mailer($conn, 6);
    if (!$paquete || empty($paquete['mail'])) {
        return false;
    }

    /** @var \PHPMailer\PHPMailer\PHPMailer $mail */
    $mail = $paquete['mail'];
    $gimnasio = trim((string) ($paquete['gimnasio'] ?? 'EGO')) ?: 'EGO';
    $expediente = (array) ($pdf['expediente'] ?? []);
    $alertas = (int) ($expediente['total_alertas'] ?? 0);

    try {
        $mail->addAddress($email, $nombreSocio);
        $mail->Subject = 'Tu expediente de salud fue registrado - ' . $gimnasio;
        $mail->Body = '<!doctype html><html lang="es"><body style="margin:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#172033">'
            . '<div style="padding:28px 14px"><div style="max-width:660px;margin:0 auto;background:#fff;border:1px solid #dfe5ee;border-radius:18px;overflow:hidden">'
            . '<div style="padding:26px 30px;background:#244292;color:#fff">'
            . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;opacity:.86">' . expediente_correo_h($gimnasio) . '</div>'
            . '<h1 style="margin:8px 0 5px;font-size:26px">Expediente registrado</h1>'
            . '<p style="margin:0;opacity:.9;font-size:14px">Guardamos tus respuestas y la aceptación del documento.</p>'
            . '</div><div style="padding:28px 30px">'
            . '<p style="margin:0 0 16px;font-size:16px">Hola <strong>' . expediente_correo_h($nombreSocio) . '</strong>,</p>'
            . '<p style="margin:0 0 18px;color:#526075;line-height:1.65">Tu cuestionario fue registrado correctamente. Encontrarás una copia completa en el archivo PDF adjunto.</p>'
            . '<div style="padding:14px;border:1px solid #dfe5ee;border-radius:10px;background:#f8fafc">'
            . '<div style="font-size:11px;color:#7b8799;text-transform:uppercase">Respuestas para revisión</div>'
            . '<strong style="display:block;margin-top:5px">' . $alertas . '</strong></div>'
            . '<p style="margin:18px 0 0;color:#7b8799;font-size:12px;line-height:1.55">Este cuestionario es un registro administrativo y no sustituye una consulta, diagnóstico o autorización médica.</p>'
            . '</div></div></div></body></html>';

        $mail->AltBody = "Hola {$nombreSocio}. Tu cuestionario de salud fue registrado correctamente. Adjuntamos una copia PDF de tus respuestas y de la aceptación del documento.";
        $mail->addStringAttachment(
            (string) ($pdf['contenido'] ?? ''),
            (string) ($pdf['nombre'] ?? 'expediente_salud.pdf'),
            'base64',
            'application/pdf'
        );
        $mail->send();
        return true;
    } catch (Throwable $e) {
        expediente_correo_error($e->getMessage());
        return false;
    }
}
