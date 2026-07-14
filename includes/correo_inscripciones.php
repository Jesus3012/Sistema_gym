<?php

function cargarPHPMailerInscripciones()
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return true;
    }

    $raiz = dirname(__DIR__);
    $autoloads = array(
        $raiz . '/vendor/autoload.php'
    );

    foreach ($autoloads as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;
            if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                return true;
            }
        }
    }

    $rutas = array(
        array(
            $raiz . '/PHPMailer/Exception.php',
            $raiz . '/PHPMailer/PHPMailer.php',
            $raiz . '/PHPMailer/SMTP.php'
        ),
        array(
            $raiz . '/PHPMailer/src/Exception.php',
            $raiz . '/PHPMailer/src/PHPMailer.php',
            $raiz . '/PHPMailer/src/SMTP.php'
        )
    );

    foreach ($rutas as $grupo) {
        if (is_file($grupo[0]) && is_file($grupo[1]) && is_file($grupo[2])) {
            require_once $grupo[0];
            require_once $grupo[1];
            require_once $grupo[2];
            return class_exists('PHPMailer\\PHPMailer\\PHPMailer');
        }
    }

    error_log('PHPMailer no está disponible para el módulo de inscripciones.');
    return false;
}

function establecerErrorCorreoInscripciones($mensaje)
{
    $GLOBALS['ultimo_error_correo_inscripciones'] = $mensaje;
    error_log('[Correo inscripciones] ' . $mensaje);
}

function obtenerUltimoErrorCorreoInscripciones()
{
    return isset($GLOBALS['ultimo_error_correo_inscripciones'])
        ? $GLOBALS['ultimo_error_correo_inscripciones']
        : '';
}

function obtenerConfiguracionCorreoInscripciones($conn)
{
    if (!$conn || !is_object($conn) || !method_exists($conn, 'query')) {
        establecerErrorCorreoInscripciones('No se recibió una conexión válida a la base de datos.');
        return null;
    }

    $tabla = $conn->query("SHOW TABLES LIKE 'configuracion_correo'");
    if (!$tabla || $tabla->num_rows === 0) {
        establecerErrorCorreoInscripciones('La tabla configuracion_correo no existe.');
        return null;
    }

    $resultado = $conn->query(
        "SELECT host, puerto, usuario, password_smtp, cifrado, smtp_auth,
                remitente_email, remitente_nombre, verificar_ssl, activo
         FROM configuracion_correo
         WHERE id = 1
         LIMIT 1"
    );

    if (!$resultado || $resultado->num_rows === 0) {
        establecerErrorCorreoInscripciones('No hay configuración SMTP guardada en configuracion_correo.');
        return null;
    }

    $config = $resultado->fetch_assoc();
    if ((int) $config['activo'] !== 1) {
        establecerErrorCorreoInscripciones('El envío de correo está desactivado en la configuración.');
        return null;
    }

    if (trim((string) $config['host']) === '' || trim((string) $config['remitente_email']) === '') {
        establecerErrorCorreoInscripciones('La configuración SMTP está incompleta.');
        return null;
    }

    return $config;
}

function obtenerNombreGimnasioCorreo($conn)
{
    $nombre = 'Gimnasio';
    $resultado = $conn->query(
        "SELECT nombre
         FROM configuracion_gimnasio
         WHERE id = 1
         LIMIT 1"
    );

    if ($resultado && $fila = $resultado->fetch_assoc()) {
        $valor = trim((string) $fila['nombre']);
        if ($valor !== '') {
            $nombre = $valor;
        }
    }

    return $nombre;
}

function crearMailerInscripciones($conn)
{
    if (!cargarPHPMailerInscripciones()) {
        establecerErrorCorreoInscripciones('No se pudo cargar PHPMailer.');
        return null;
    }

    $config = obtenerConfiguracionCorreoInscripciones($conn);
    if (!$config) {
        return null;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = trim((string) $config['host']);
        $mail->Port = (int) $config['puerto'];
        $mail->SMTPAuth = (int) $config['smtp_auth'] === 1;
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 20;

        if ($mail->SMTPAuth) {
            $mail->Username = (string) $config['usuario'];
            $mail->Password = (string) $config['password_smtp'];
        }

        $cifrado = strtolower(trim((string) $config['cifrado']));
        if ($cifrado === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($cifrado === 'tls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $verificarSsl = (int) $config['verificar_ssl'] === 1;
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => $verificarSsl,
                'verify_peer_name' => $verificarSsl,
                'allow_self_signed' => !$verificarSsl
            )
        );

        $remitenteEmail = trim((string) $config['remitente_email']);
        if ($remitenteEmail === '') {
            $remitenteEmail = trim((string) $config['usuario']);
        }

        $remitenteNombre = trim((string) $config['remitente_nombre']);
        if ($remitenteNombre === '') {
            $remitenteNombre = obtenerNombreGimnasioCorreo($conn);
        }

        $mail->setFrom($remitenteEmail, $remitenteNombre);
        $mail->isHTML(true);

        return array(
            'mail' => $mail,
            'gimnasio' => obtenerNombreGimnasioCorreo($conn)
        );
    } catch (\Throwable $e) {
        establecerErrorCorreoInscripciones($e->getMessage());
        return null;
    }
}

function textoSeguroCorreo($valor)
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function fechaCorreoInscripciones($fecha)
{
    if (empty($fecha)) {
        return 'Sin vencimiento';
    }

    $timestamp = strtotime((string) $fecha);
    return $timestamp ? date('d/m/Y', $timestamp) : (string) $fecha;
}

function metodoPagoCorreo($metodo)
{
    $metodos = array(
        'efectivo' => 'Efectivo',
        'tarjeta' => 'Tarjeta',
        'transferencia' => 'Transferencia'
    );

    return isset($metodos[$metodo]) ? $metodos[$metodo] : ucfirst((string) $metodo);
}

function normalizarArgumentosCorreo($argumentos, $cantidadSinConexion)
{
    $conn = null;
    if (isset($argumentos[0]) && is_object($argumentos[0]) && method_exists($argumentos[0], 'query')) {
        $conn = array_shift($argumentos);
    } elseif (isset($GLOBALS['conn']) && is_object($GLOBALS['conn'])) {
        $conn = $GLOBALS['conn'];
    }

    if (!$conn || count($argumentos) < $cantidadSinConexion) {
        establecerErrorCorreoInscripciones('Faltan datos para construir el correo.');
        return null;
    }

    return array($conn, $argumentos);
}

function rutaQrAbsolutaInscripciones($rutaQr)
{
    $rutaQr = (string) $rutaQr;
    if ($rutaQr === '') {
        return '';
    }

    if (is_file($rutaQr)) {
        return $rutaQr;
    }

    $rutaAbsoluta = dirname(__DIR__) . '/' . ltrim($rutaQr, '/\\');
    return is_file($rutaAbsoluta) ? $rutaAbsoluta : '';
}

function imagenBase64Inscripciones($ruta)
{
    $ruta = rutaQrAbsolutaInscripciones($ruta);
    if ($ruta === '') {
        return '';
    }

    $contenido = @file_get_contents($ruta);
    if ($contenido === false) {
        return '';
    }

    return 'data:image/png;base64,' . base64_encode($contenido);
}

function limpiarNombreArchivoInscripciones($texto)
{
    $texto = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) $texto);
    $texto = trim((string) $texto, '_');
    return $texto !== '' ? $texto : 'archivo';
}

function construirHtmlAdjuntoInscripciones($tipo, $gimnasio, $datos)
{
    $tipoEtiqueta = $tipo === 'renovacion' ? 'Renovación de membresía' : 'Inscripción de socio';
    $qrData = !empty($datos['qr_data_uri'])
        ? '<img src="' . $datos['qr_data_uri'] . '" alt="Código QR" style="width:210px;height:210px;display:block;max-width:100%;margin:0 auto">'
        : '<div style="padding:24px;border:2px dashed #cbd5e1;border-radius:14px;text-align:center;color:#475569;font-weight:600">QR no disponible<br><span style="font-family:monospace;font-size:14px">' . textoSeguroCorreo($datos['codigo_qr']) . '</span></div>';

    $referenciaHtml = trim((string) $datos['referencia']) !== ''
        ? '<div style="display:flex;justify-content:space-between;gap:14px;padding:10px 0;border-bottom:1px solid #e5e7eb"><span style="color:#64748b">Referencia</span><strong style="text-align:right">' . textoSeguroCorreo($datos['referencia']) . '</strong></div>'
        : '';

    return '<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . textoSeguroCorreo($tipoEtiqueta) . '</title>
</head>
<body style="margin:0;padding:24px;background:#eef2f7;font-family:Arial,Helvetica,sans-serif;color:#14213d">
    <div style="max-width:760px;margin:0 auto;background:#ffffff;border:1px solid #dbe3ee;border-radius:18px;overflow:hidden;box-shadow:0 16px 40px rgba(15,23,42,.08)">
        <div style="background:linear-gradient(135deg,#244292 0%,#2f55b8 100%);color:#fff;padding:28px 30px">
            <div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;opacity:.85">' . textoSeguroCorreo($gimnasio) . '</div>
            <h1 style="margin:8px 0 6px;font-size:28px;line-height:1.2">' . textoSeguroCorreo($tipoEtiqueta) . '</h1>
            <p style="margin:0;font-size:14px;opacity:.9">Comprobante con código QR del socio</p>
        </div>
        <div style="padding:28px 30px">
            <table role="presentation" style="width:100%;border-collapse:collapse">
                <tr>
                    <td style="width:52%;padding:0 24px 0 0;vertical-align:top">
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:22px">
                            <div style="font-size:13px;color:#64748b;text-transform:uppercase;letter-spacing:.08em">Socio</div>
                            <div style="font-size:28px;font-weight:700;margin:8px 0 18px">' . textoSeguroCorreo($datos['nombre']) . '</div>
                            <div style="display:flex;justify-content:space-between;gap:14px;padding:10px 0;border-top:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb"><span style="color:#64748b">Plan</span><strong style="text-align:right">' . textoSeguroCorreo($datos['plan']) . '</strong></div>
                            <div style="display:flex;justify-content:space-between;gap:14px;padding:10px 0;border-bottom:1px solid #e5e7eb"><span style="color:#64748b">Inicio</span><strong style="text-align:right">' . textoSeguroCorreo(fechaCorreoInscripciones($datos['fecha_inicio'])) . '</strong></div>
                            <div style="display:flex;justify-content:space-between;gap:14px;padding:10px 0;border-bottom:1px solid #e5e7eb"><span style="color:#64748b">Vencimiento</span><strong style="text-align:right">' . textoSeguroCorreo(fechaCorreoInscripciones($datos['fecha_fin'])) . '</strong></div>
                            <div style="display:flex;justify-content:space-between;gap:14px;padding:10px 0;border-bottom:1px solid #e5e7eb"><span style="color:#64748b">Monto</span><strong style="text-align:right">$' . number_format((float) $datos['monto'], 2) . '</strong></div>
                            <div style="display:flex;justify-content:space-between;gap:14px;padding:10px 0;border-bottom:1px solid #e5e7eb"><span style="color:#64748b">Pago</span><strong style="text-align:right">' . textoSeguroCorreo(metodoPagoCorreo($datos['metodo_pago'])) . '</strong></div>
                            ' . $referenciaHtml . '
                            <div style="display:flex;justify-content:space-between;gap:14px;padding:10px 0 ' . (trim((string) $datos['referencia']) !== '' ? '0' : '10px') . '"><span style="color:#64748b">Código QR</span><strong style="text-align:right;font-family:monospace;font-size:16px">' . textoSeguroCorreo($datos['codigo_qr']) . '</strong></div>
                        </div>
                    </td>
                    <td style="width:48%;vertical-align:top">
                        <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;padding:22px;text-align:center">
                            <div style="font-size:13px;color:#64748b;text-transform:uppercase;letter-spacing:.08em;margin-bottom:12px">Acceso del socio</div>
                            ' . $qrData . '
                            <div style="margin-top:16px;padding:12px 14px;background:#eff6ff;border-radius:12px;color:#1e3a8a;font-size:14px;line-height:1.5">
                                Presenta este código al ingresar o guárdalo para futuras renovaciones.
                            </div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
        <div style="padding:18px 30px;background:#f8fafc;color:#64748b;font-size:12px;line-height:1.6;border-top:1px solid #e2e8f0">
            Documento generado automáticamente por ' . textoSeguroCorreo($gimnasio) . '. Conserva este comprobante como respaldo de tu movimiento.
        </div>
    </div>
</body>
</html>';
}

function cargarFpdfInscripciones()
{
    if (class_exists('FPDF')) {
        return true;
    }

    $raiz = dirname(__DIR__);
    $autoload = $raiz . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        if (class_exists('FPDF')) {
            return true;
        }
    }

    $rutas = array(
        $raiz . '/fpdf/fpdf.php',
        $raiz . '/FPDF/fpdf.php',
        $raiz . '/lib/fpdf/fpdf.php',
        $raiz . '/libs/fpdf/fpdf.php',
        $raiz . '/includes/fpdf/fpdf.php',
        $raiz . '/vendor/setasign/fpdf/fpdf.php'
    );

    foreach ($rutas as $ruta) {
        if (is_file($ruta)) {
            require_once $ruta;
            if (class_exists('FPDF')) {
                return true;
            }
        }
    }

    error_log('[Correo inscripciones] No se encontró FPDF.');
    return false;
}

function textoFpdfInscripciones($texto)
{
    $texto = (string) $texto;

    if (function_exists('iconv')) {
        $convertido = @iconv('UTF-8', 'windows-1252//TRANSLIT', $texto);
        if ($convertido !== false) {
            return $convertido;
        }
    }

    return utf8_decode($texto);
}

function recortarTextoFpdfInscripciones($texto, $maximo)
{
    $texto = trim((string) $texto);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($texto, 'UTF-8') > $maximo
            ? mb_substr($texto, 0, $maximo - 3, 'UTF-8') . '...'
            : $texto;
    }

    return strlen($texto) > $maximo
        ? substr($texto, 0, $maximo - 3) . '...'
        : $texto;
}

function filaDetalleFpdfInscripciones($pdf, $etiqueta, $valor, $x, $y, $ancho)
{
    $pdf->SetXY($x, $y);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(38, 7, textoFpdfInscripciones($etiqueta), 0, 0, 'L');

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(23, 32, 51);
    $pdf->Cell($ancho - 38, 7, textoFpdfInscripciones(recortarTextoFpdfInscripciones($valor, 35)), 0, 0, 'R');

    $pdf->SetDrawColor(226, 232, 240);
    $pdf->Line($x, $y + 7.5, $x + $ancho, $y + 7.5);
}

function generarAdjuntoComprobanteInscripciones($tipo, $gimnasio, $datos)
{
    if (!cargarFpdfInscripciones()) {
        establecerErrorCorreoInscripciones('No se pudo generar el comprobante PDF porque FPDF no está disponible.');
        return null;
    }

    try {
        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetMargins(14, 14, 14);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        $azul = array(36, 66, 146);
        $azulClaro = array(239, 246, 255);
        $grisFondo = array(248, 250, 252);
        $grisBorde = array(226, 232, 240);
        $texto = array(23, 32, 51);
        $muted = array(100, 116, 139);
        $verde = array(5, 150, 105);

        // Encabezado.
        $pdf->SetFillColor($azul[0], $azul[1], $azul[2]);
        $pdf->Rect(0, 0, 210, 42, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 20);
        $pdf->SetXY(14, 12);
        $titulo = $tipo === 'renovacion' ? 'Renovacion confirmada' : 'Inscripcion confirmada';
        $pdf->Cell(182, 9, textoFpdfInscripciones($titulo), 0, 1, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetX(14);
        $pdf->Cell(182, 6, textoFpdfInscripciones(trim((string) $gimnasio)), 0, 1, 'L');

        // Tarjeta principal.
        $pdf->SetFillColor($grisFondo[0], $grisFondo[1], $grisFondo[2]);
        $pdf->SetDrawColor($grisBorde[0], $grisBorde[1], $grisBorde[2]);
        $pdf->Rect(14, 52, 118, 133, 'DF');

        $pdf->SetXY(20, 60);
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor($muted[0], $muted[1], $muted[2]);
        $pdf->Cell(106, 5, textoFpdfInscripciones('SOCIO'), 0, 1, 'L');
        $pdf->SetX(20);
        $pdf->SetFont('Arial', 'B', 17);
        $pdf->SetTextColor($texto[0], $texto[1], $texto[2]);
        $pdf->MultiCell(106, 8, textoFpdfInscripciones(recortarTextoFpdfInscripciones($datos['nombre'], 48)), 0, 'L');

        $inicioFilas = max(82, $pdf->GetY() + 4);
        filaDetalleFpdfInscripciones($pdf, 'Plan', (string) $datos['plan'], 20, $inicioFilas, 106);
        filaDetalleFpdfInscripciones($pdf, 'Inicio', fechaCorreoInscripciones($datos['fecha_inicio']), 20, $inicioFilas + 10, 106);
        filaDetalleFpdfInscripciones($pdf, 'Vencimiento', fechaCorreoInscripciones($datos['fecha_fin']), 20, $inicioFilas + 20, 106);
        filaDetalleFpdfInscripciones($pdf, 'Monto', '$' . number_format((float) $datos['monto'], 2), 20, $inicioFilas + 30, 106);
        filaDetalleFpdfInscripciones($pdf, 'Metodo de pago', metodoPagoCorreo($datos['metodo_pago']), 20, $inicioFilas + 40, 106);

        $desfase = 50;
        if (trim((string) $datos['referencia']) !== '') {
            filaDetalleFpdfInscripciones($pdf, 'Referencia', (string) $datos['referencia'], 20, $inicioFilas + $desfase, 106);
            $desfase += 10;
        }
        filaDetalleFpdfInscripciones($pdf, 'Codigo QR', (string) $datos['codigo_qr'], 20, $inicioFilas + $desfase, 106);

        // Columna QR.
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetDrawColor($grisBorde[0], $grisBorde[1], $grisBorde[2]);
        $pdf->Rect(138, 52, 58, 96, 'DF');
        $pdf->SetXY(142, 59);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor($texto[0], $texto[1], $texto[2]);
        $pdf->Cell(50, 6, textoFpdfInscripciones('CODIGO DE ACCESO'), 0, 1, 'C');

        $rutaQr = isset($datos['ruta_qr']) ? rutaQrAbsolutaInscripciones($datos['ruta_qr']) : '';
        if ($rutaQr !== '') {
            $pdf->Image($rutaQr, 142, 69, 50, 50, 'PNG');
        } else {
            $pdf->SetFillColor($grisFondo[0], $grisFondo[1], $grisFondo[2]);
            $pdf->Rect(142, 69, 50, 50, 'F');
            $pdf->SetXY(145, 88);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor($muted[0], $muted[1], $muted[2]);
            $pdf->MultiCell(44, 5, textoFpdfInscripciones('Imagen QR no disponible'), 0, 'C');
        }

        $pdf->SetXY(142, 123);
        $pdf->SetFont('Courier', 'B', 9);
        $pdf->SetTextColor($texto[0], $texto[1], $texto[2]);
        $pdf->Cell(50, 6, textoFpdfInscripciones(recortarTextoFpdfInscripciones($datos['codigo_qr'], 22)), 0, 1, 'C');

        $pdf->SetFillColor($azulClaro[0], $azulClaro[1], $azulClaro[2]);
        $pdf->Rect(138, 154, 58, 31, 'F');
        $pdf->SetXY(143, 160);
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->SetTextColor($azul[0], $azul[1], $azul[2]);
        $pdf->MultiCell(48, 5, textoFpdfInscripciones('Presenta este QR al ingresar. El mismo codigo se conserva en cada renovacion.'), 0, 'C');

        // Estado de la membresia.
        $pdf->SetFillColor(236, 253, 245);
        $pdf->Rect(14, 194, 182, 22, 'F');
        $pdf->SetXY(20, 200);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetTextColor($verde[0], $verde[1], $verde[2]);
        $mensajeEstado = $tipo === 'renovacion'
            ? 'La membresia quedo renovada y activa con el nuevo periodo.'
            : 'La inscripcion fue registrada correctamente y la membresia esta activa.';
        $pdf->Cell(170, 8, textoFpdfInscripciones($mensajeEstado), 0, 1, 'C');

        // Pie.
        $pdf->SetDrawColor($grisBorde[0], $grisBorde[1], $grisBorde[2]);
        $pdf->Line(14, 231, 196, 231);
        $pdf->SetXY(14, 236);
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->SetTextColor($muted[0], $muted[1], $muted[2]);
        $pdf->MultiCell(182, 5, textoFpdfInscripciones('Documento generado automaticamente. Conserva este comprobante y la imagen QR adjunta para futuras visitas.'), 0, 'C');

        $baseNombre = ($tipo === 'renovacion' ? 'renovacion' : 'inscripcion') . '_' .
            limpiarNombreArchivoInscripciones($datos['nombre']) . '_' .
            limpiarNombreArchivoInscripciones($datos['codigo_qr']);

        return array(
            'name' => $baseNombre . '.pdf',
            'mime' => 'application/pdf',
            'content' => $pdf->Output('S')
        );
    } catch (\Throwable $e) {
        establecerErrorCorreoInscripciones('No se pudo generar el comprobante PDF con FPDF: ' . $e->getMessage());
        return null;
    }
}

function prepararBloquesQrCorreoInscripciones($codigoQr, $rutaQr)
{
    $rutaAbsoluta = rutaQrAbsolutaInscripciones($rutaQr);
    $qrHtml = '<p style="margin:16px 0 6px;color:#667085">Tu código de acceso:</p>' .
        '<div style="font-family:monospace;font-size:15px;font-weight:bold">' . textoSeguroCorreo($codigoQr) . '</div>';

    $qrDataUri = '';
    if ($rutaAbsoluta !== '') {
        $qrDataUri = imagenBase64Inscripciones($rutaAbsoluta);
        $qrHtml = '<p style="margin:18px 0 10px;color:#667085">Presenta este QR para registrar tu acceso:</p>' .
            '<img src="cid:qr_socio" alt="Código QR" style="display:block;width:190px;max-width:100%;height:auto;margin:0 auto 10px">' .
            '<div style="font-family:monospace;font-size:14px;font-weight:bold">' . textoSeguroCorreo($codigoQr) . '</div>';
    }

    return array($rutaAbsoluta, $qrHtml, $qrDataUri);
}

function adjuntarQrYComprobanteInscripciones($mail, $tipo, $gimnasio, $datos)
{
    // El PNG se usa únicamente para dibujar el QR dentro del PDF.
    // No se adjunta como archivo independiente al correo.
    $rutaAbsoluta = isset($datos['ruta_qr']) ? rutaQrAbsolutaInscripciones($datos['ruta_qr']) : '';
    $datos['qr_data_uri'] = imagenBase64Inscripciones($rutaAbsoluta);

    $adjunto = generarAdjuntoComprobanteInscripciones($tipo, $gimnasio, $datos);
    if ($adjunto && !empty($adjunto['content'])) {
        $mail->addStringAttachment($adjunto['content'], $adjunto['name'], 'base64', $adjunto['mime']);
    }
}

function enviarCorreoBienvenidaInscripcion(...$argumentos)
{
    $normalizados = normalizarArgumentosCorreo($argumentos, 10);
    if (!$normalizados) {
        return false;
    }

    list($conn, $datos) = $normalizados;
    list(
        $email,
        $nombreCompleto,
        $planNombre,
        $fechaInicio,
        $fechaFin,
        $monto,
        $metodoPago,
        $referencia,
        $codigoQr,
        $rutaQr
    ) = array_slice($datos, 0, 10);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        establecerErrorCorreoInscripciones('El correo del socio no es válido.');
        return false;
    }

    $paquete = crearMailerInscripciones($conn);
    if (!$paquete) {
        return false;
    }

    /** @var \PHPMailer\PHPMailer\PHPMailer $mail */
    $mail = $paquete['mail'];
    $gimnasio = $paquete['gimnasio'];

    try {
        $mail->addAddress($email, $nombreCompleto);
        $mail->Subject = 'Bienvenido a ' . $gimnasio . ' - Inscripción confirmada';

        list($rutaAbsoluta, $qrHtml, $qrDataUri) = prepararBloquesQrCorreoInscripciones($codigoQr, $rutaQr);
        if ($rutaAbsoluta !== '') {
            $mail->addEmbeddedImage($rutaAbsoluta, 'qr_socio', 'codigo-qr.png');
        }

        $referenciaHtml = trim((string) $referencia) !== ''
            ? '<tr><td style="padding:8px 0;color:#667085">Referencia</td><td style="padding:8px 0;text-align:right;font-weight:bold">' . textoSeguroCorreo($referencia) . '</td></tr>'
            : '';

        $mail->Body = '<!doctype html><html lang="es"><body style="margin:0;padding:24px;background:#f4f7fb;font-family:Arial,sans-serif;color:#172033">' .
            '<div style="max-width:620px;margin:0 auto;overflow:hidden;border:1px solid #dfe5ee;border-radius:12px;background:#fff">' .
            '<div style="padding:22px 26px;background:#244292;color:#fff"><h2 style="margin:0;font-size:22px">Inscripción confirmada</h2></div>' .
            '<div style="padding:26px"><p style="margin-top:0">Hola <strong>' . textoSeguroCorreo($nombreCompleto) . '</strong>,</p>' .
            '<p>Tu inscripción en <strong>' . textoSeguroCorreo($gimnasio) . '</strong> se registró correctamente.</p>' .
            '<table style="width:100%;border-collapse:collapse;margin:20px 0;border-top:1px solid #e6ebf2;border-bottom:1px solid #e6ebf2">' .
            '<tr><td style="padding:8px 0;color:#667085">Plan</td><td style="padding:8px 0;text-align:right;font-weight:bold">' . textoSeguroCorreo($planNombre) . '</td></tr>' .
            '<tr><td style="padding:8px 0;color:#667085">Inicio</td><td style="padding:8px 0;text-align:right;font-weight:bold">' . textoSeguroCorreo(fechaCorreoInscripciones($fechaInicio)) . '</td></tr>' .
            '<tr><td style="padding:8px 0;color:#667085">Vencimiento</td><td style="padding:8px 0;text-align:right;font-weight:bold">' . textoSeguroCorreo(fechaCorreoInscripciones($fechaFin)) . '</td></tr>' .
            '<tr><td style="padding:8px 0;color:#667085">Monto</td><td style="padding:8px 0;text-align:right;font-weight:bold">$' . number_format((float) $monto, 2) . '</td></tr>' .
            '<tr><td style="padding:8px 0;color:#667085">Método de pago</td><td style="padding:8px 0;text-align:right;font-weight:bold">' . textoSeguroCorreo(metodoPagoCorreo($metodoPago)) . '</td></tr>' .
            $referenciaHtml . '</table>' .
            '<div style="padding:18px;text-align:center;border:1px solid #dfe5ee;border-radius:10px;background:#f8fafc">' . $qrHtml . '</div>' .
            '<p style="margin:22px 0 0;color:#667085;font-size:13px">Adjuntamos tu comprobante PDF con el código QR para que puedas guardarlo fácilmente.</p>' .
            '</div></div></body></html>';

        $mail->AltBody =
            "Inscripción confirmada\n" .
            "Socio: {$nombreCompleto}\n" .
            "Plan: {$planNombre}\n" .
            "Inicio: " . fechaCorreoInscripciones($fechaInicio) . "\n" .
            "Vencimiento: " . fechaCorreoInscripciones($fechaFin) . "\n" .
            "Monto: $" . number_format((float) $monto, 2) . "\n" .
            "Código QR: {$codigoQr}";

        adjuntarQrYComprobanteInscripciones($mail, 'inscripcion', $gimnasio, array(
            'nombre' => $nombreCompleto,
            'plan' => $planNombre,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'monto' => $monto,
            'metodo_pago' => $metodoPago,
            'referencia' => $referencia,
            'codigo_qr' => $codigoQr,
            'ruta_qr' => $rutaQr,
            'qr_data_uri' => $qrDataUri
        ));

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        establecerErrorCorreoInscripciones($e->getMessage());
        return false;
    }
}

function enviarCorreoRenovacionInscripcion(...$argumentos)
{
    $normalizados = normalizarArgumentosCorreo($argumentos, 8);
    if (!$normalizados) {
        return false;
    }

    list($conn, $datos) = $normalizados;
    $email = $datos[0] ?? '';
    $nombreCompleto = $datos[1] ?? '';
    $planNombre = $datos[2] ?? '';
    $fechaInicio = $datos[3] ?? '';
    $fechaFin = $datos[4] ?? '';
    $monto = $datos[5] ?? 0;
    $metodoPago = $datos[6] ?? '';
    $referencia = $datos[7] ?? '';
    $codigoQr = $datos[8] ?? '';
    $rutaQr = $datos[9] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        establecerErrorCorreoInscripciones('El correo del socio no es válido.');
        return false;
    }

    $paquete = crearMailerInscripciones($conn);
    if (!$paquete) {
        return false;
    }

    /** @var \PHPMailer\PHPMailer\PHPMailer $mail */
    $mail = $paquete['mail'];
    $gimnasio = $paquete['gimnasio'];

    try {
        $mail->addAddress($email, $nombreCompleto);
        $mail->Subject = 'Renovación confirmada - ' . $gimnasio;

        list($rutaAbsoluta, $qrHtml, $qrDataUri) = prepararBloquesQrCorreoInscripciones($codigoQr, $rutaQr);
        if ($rutaAbsoluta !== '') {
            $mail->addEmbeddedImage($rutaAbsoluta, 'qr_socio', 'codigo-qr.png');
        }

        $referenciaHtml = trim((string) $referencia) !== ''
            ? '<tr><td style="padding:8px 0;color:#667085">Referencia</td><td style="padding:8px 0;text-align:right;font-weight:bold">' . textoSeguroCorreo($referencia) . '</td></tr>'
            : '';

        $bloqueQr = trim((string) $codigoQr) !== ''
            ? '<div style="padding:18px;text-align:center;border:1px solid #dfe5ee;border-radius:10px;background:#f8fafc;margin-top:18px">' . $qrHtml . '</div>'
            : '<div style="padding:14px 16px;border-radius:8px;background:#eff6ff;color:#1d4ed8;font-weight:600;margin-top:18px">Tu renovación quedó activa. Si tu socio ya contaba con QR, se conserva el mismo código para sus accesos.</div>';

        $mail->Body = '<!doctype html><html lang="es"><body style="margin:0;padding:24px;background:#f4f7fb;font-family:Arial,sans-serif;color:#172033">' .
            '<div style="max-width:620px;margin:0 auto;overflow:hidden;border:1px solid #dfe5ee;border-radius:12px;background:#fff">' .
            '<div style="padding:22px 26px;background:#244292;color:#fff"><h2 style="margin:0;font-size:22px">Renovación confirmada</h2></div>' .
            '<div style="padding:26px"><p style="margin-top:0">Hola <strong>' . textoSeguroCorreo($nombreCompleto) . '</strong>,</p>' .
            '<p>Tu plan en <strong>' . textoSeguroCorreo($gimnasio) . '</strong> fue renovado correctamente.</p>' .
            '<table style="width:100%;border-collapse:collapse;margin:20px 0;border-top:1px solid #e6ebf2;border-bottom:1px solid #e6ebf2">' .
            '<tr><td style="padding:8px 0;color:#667085">Plan</td><td style="padding:8px 0;text-align:right;font-weight:bold">' . textoSeguroCorreo($planNombre) . '</td></tr>' .
            '<tr><td style="padding:8px 0;color:#667085">Nuevo inicio</td><td style="padding:8px 0;text-align:right;font-weight:bold">' . textoSeguroCorreo(fechaCorreoInscripciones($fechaInicio)) . '</td></tr>' .
            '<tr><td style="padding:8px 0;color:#667085">Nuevo vencimiento</td><td style="padding:8px 0;text-align:right;font-weight:bold">' . textoSeguroCorreo(fechaCorreoInscripciones($fechaFin)) . '</td></tr>' .
            '<tr><td style="padding:8px 0;color:#667085">Monto</td><td style="padding:8px 0;text-align:right;font-weight:bold">$' . number_format((float) $monto, 2) . '</td></tr>' .
            '<tr><td style="padding:8px 0;color:#667085">Método de pago</td><td style="padding:8px 0;text-align:right;font-weight:bold">' . textoSeguroCorreo(metodoPagoCorreo($metodoPago)) . '</td></tr>' .
            $referenciaHtml . '</table>' .
            $bloqueQr .
            '<p style="margin:22px 0 0;color:#667085;font-size:13px">Adjuntamos tu comprobante PDF con el código QR para que puedas guardarlo fácilmente.</p>' .
            '</div></div></body></html>';

        $mail->AltBody =
            "Renovación confirmada\n" .
            "Socio: {$nombreCompleto}\n" .
            "Plan: {$planNombre}\n" .
            "Inicio: " . fechaCorreoInscripciones($fechaInicio) . "\n" .
            "Vencimiento: " . fechaCorreoInscripciones($fechaFin) . "\n" .
            "Monto: $" . number_format((float) $monto, 2) .
            (trim((string) $codigoQr) !== '' ? "\nCódigo QR: {$codigoQr}" : '');

        if (trim((string) $codigoQr) !== '') {
            adjuntarQrYComprobanteInscripciones($mail, 'renovacion', $gimnasio, array(
                'nombre' => $nombreCompleto,
                'plan' => $planNombre,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'monto' => $monto,
                'metodo_pago' => $metodoPago,
                'referencia' => $referencia,
                'codigo_qr' => $codigoQr,
                'ruta_qr' => $rutaQr,
                'qr_data_uri' => $qrDataUri
            ));
        }

        $mail->send();
        return true;
    } catch (\Throwable $e) {
        establecerErrorCorreoInscripciones($e->getMessage());
        return false;
    }
}