<?php

declare(strict_types=1);

/**
 * Mailer central optimizado.
 *
 * - Lee configuracion_correo.
 * - Fuerza IPv4 en Windows/XAMPP para evitar el timeout de IPv6.
 * - Usa tiempos de conexión cortos.
 * - Resuelve rutas absolutas de QR y adjuntos de forma consistente.
 */

function correo_sistema_error(string $mensaje): void
{
    $GLOBALS['ultimo_error_correo_sistema'] = trim($mensaje);
    error_log('[Correo sistema] ' . trim($mensaje));
}

function correo_sistema_ultimo_error(): string
{
    return trim((string) ($GLOBALS['ultimo_error_correo_sistema'] ?? ''));
}

function correo_sistema_cargar_phpmailer(): bool
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return true;
    }

    $raiz = dirname(__DIR__);
    if (is_file($raiz . '/vendor/autoload.php')) {
        require_once $raiz . '/vendor/autoload.php';
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            return true;
        }
    }

    $rutas = [
        [
            $raiz . '/PHPMailer/Exception.php',
            $raiz . '/PHPMailer/PHPMailer.php',
            $raiz . '/PHPMailer/SMTP.php',
        ],
        [
            $raiz . '/PHPMailer/src/Exception.php',
            $raiz . '/PHPMailer/src/PHPMailer.php',
            $raiz . '/PHPMailer/src/SMTP.php',
        ],
    ];

    foreach ($rutas as $grupo) {
        if (is_file($grupo[0]) && is_file($grupo[1]) && is_file($grupo[2])) {
            require_once $grupo[0];
            require_once $grupo[1];
            require_once $grupo[2];
            if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                return true;
            }
        }
    }

    correo_sistema_error('PHPMailer no está disponible en el proyecto.');
    return false;
}

/** @return array<string,mixed>|null */
function correo_sistema_configuracion(mysqli $conn): ?array
{
    try {
        $resultado = $conn->query(
            "SELECT host, puerto, usuario, password_smtp, cifrado, smtp_auth,
                    remitente_email, remitente_nombre, verificar_ssl, activo
             FROM configuracion_correo
             WHERE id = 1
             LIMIT 1"
        );
        $config = $resultado ? $resultado->fetch_assoc() : null;

        if (!$config) {
            correo_sistema_error('No existe la configuración SMTP con id 1.');
            return null;
        }
        if ((int) ($config['activo'] ?? 0) !== 1) {
            correo_sistema_error('El envío de correo está desactivado.');
            return null;
        }

        $host = trim((string) ($config['host'] ?? ''));
        $usuario = trim((string) ($config['usuario'] ?? ''));
        $remitente = trim((string) ($config['remitente_email'] ?? ''));
        $puerto = (int) ($config['puerto'] ?? 0);

        if ($host === '' || $puerto <= 0 || $remitente === '') {
            correo_sistema_error('La configuración SMTP está incompleta.');
            return null;
        }
        if ((int) ($config['smtp_auth'] ?? 0) === 1 && $usuario === '') {
            correo_sistema_error('SMTP requiere autenticación, pero el usuario está vacío.');
            return null;
        }

        return $config;
    } catch (Throwable $e) {
        correo_sistema_error($e->getMessage());
        return null;
    }
}

function correo_sistema_nombre_gimnasio(mysqli $conn): string
{
    try {
        $resultado = $conn->query(
            "SELECT nombre FROM configuracion_gimnasio WHERE id = 1 LIMIT 1"
        );
        $fila = $resultado ? $resultado->fetch_assoc() : null;
        $nombre = trim((string) ($fila['nombre'] ?? ''));
        return $nombre !== '' ? $nombre : 'EGO';
    } catch (Throwable $e) {
        return 'EGO';
    }
}

function correo_sistema_normalizar_password(string $host, string $password): string
{
    $password = trim($password);
    if (stripos($host, 'gmail.com') !== false) {
        return (string) preg_replace('/\\s+/', '', $password);
    }
    return $password;
}

function correo_sistema_resolver_ipv4(string $host): string
{
    $host = trim($host);
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $host;
    }

    $direcciones = @gethostbynamel($host);
    if (is_array($direcciones)) {
        foreach ($direcciones as $direccion) {
            if (filter_var($direccion, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $direccion;
            }
        }
    }

    $direccion = @gethostbyname($host);
    return filter_var($direccion, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ? (string) $direccion
        : $host;
}

function correo_sistema_ruta_archivo(string $ruta): string
{
    $ruta = trim($ruta);
    if ($ruta === '') {
        return '';
    }

    $ruta = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $ruta);
    $esWindows = preg_match('/^[a-zA-Z]:[\\\\\/]/', $ruta) === 1;
    $esUnix = substr($ruta, 0, 1) === DIRECTORY_SEPARATOR;

    if (!$esWindows && !$esUnix) {
        $ruta = dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim($ruta, DIRECTORY_SEPARATOR);
    }

    clearstatcache(true, $ruta);
    return is_file($ruta) && filesize($ruta) > 0 ? $ruta : '';
}


/**
 * Genera o recupera el comprobante PDF fuera del POST de inscripción.
 *
 * @param array<string,mixed> $payload
 * @return array{success:bool,path:string,name:string,error:string}
 */
function correo_sistema_resolver_comprobante(
    mysqli $conn,
    array $payload
): array {
    $rutaExistente = correo_sistema_ruta_archivo(
        (string) ($payload['documento_pdf'] ?? '')
    );

    if ($rutaExistente !== '') {
        return [
            'success' => true,
            'path' => $rutaExistente,
            'name' => basename($rutaExistente),
            'error' => '',
        ];
    }

    $historialPagoId = (int) ($payload['historial_pago_id'] ?? 0);
    if ($historialPagoId <= 0) {
        return [
            'success' => false,
            'path' => '',
            'name' => '',
            'error' => 'No se recibió el movimiento necesario para generar el comprobante PDF.',
        ];
    }

    try {
        require_once __DIR__ . '/qr_helper.php';
        require_once __DIR__ . '/correo_inscripciones.php';
        require_once __DIR__ . '/documentos_inscripciones.php';

        if (!function_exists('asegurarDocumentoHistorialInscripcion')) {
            throw new RuntimeException(
                'No está disponible el generador persistente de documentos.'
            );
        }

        $documento = asegurarDocumentoHistorialInscripcion(
            $conn,
            $historialPagoId
        );

        if (
            empty($documento['success'])
            || empty($documento['path'])
        ) {
            throw new RuntimeException(
                (string) ($documento['error'] ?? 'No fue posible generar el comprobante PDF.')
            );
        }

        $ruta = correo_sistema_ruta_archivo(
            (string) $documento['path']
        );
        if ($ruta === '') {
            throw new RuntimeException(
                'El comprobante se generó, pero el archivo no está disponible.'
            );
        }

        return [
            'success' => true,
            'path' => $ruta,
            'name' => trim((string) ($documento['name'] ?? '')) !== ''
                ? (string) $documento['name']
                : basename($ruta),
            'error' => '',
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'path' => '',
            'name' => '',
            'error' => $e->getMessage(),
        ];
    }
}

/** @return array{mail:object,gimnasio:string,config:array<string,mixed>,host_original:string,host_conexion:string}|null */
function correo_sistema_crear_mailer(mysqli $conn, int $timeout = 6): ?array
{
    $GLOBALS['ultimo_error_correo_sistema'] = '';

    if (!correo_sistema_cargar_phpmailer()) {
        return null;
    }
    $config = correo_sistema_configuracion($conn);
    if (!$config) {
        return null;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $host = trim((string) $config['host']);
        $hostConexion = correo_sistema_resolver_ipv4($host);

        $mail->isSMTP();
        $mail->Host = $hostConexion;
        $mail->Port = max(1, (int) $config['puerto']);
        $mail->SMTPAuth = (int) $config['smtp_auth'] === 1;
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->Timeout = max(3, min(12, $timeout));
        $mail->Timelimit = max(5, min(15, $timeout + 3));
        $mail->SMTPKeepAlive = false;
        $mail->SMTPDebug = 0;
        $mail->WordWrap = 78;

        if ($mail->SMTPAuth) {
            $mail->Username = trim((string) $config['usuario']);
            $mail->Password = correo_sistema_normalizar_password(
                $host,
                (string) $config['password_smtp']
            );
        }

        $cifrado = strtolower(trim((string) $config['cifrado']));
        if ($cifrado === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->SMTPAutoTLS = false;
        } elseif ($cifrado === 'tls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAutoTLS = true;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $verificarSsl = (int) ($config['verificar_ssl'] ?? 0) === 1;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => $verificarSsl,
                'verify_peer_name' => $verificarSsl,
                'allow_self_signed' => !$verificarSsl,
                'peer_name' => $host,
                'SNI_enabled' => true,
                'SNI_server_name' => $host,
            ],
        ];

        $remitente = trim((string) $config['remitente_email']);
        $nombreRemitente = trim((string) $config['remitente_nombre']);
        if ($nombreRemitente === '') {
            $nombreRemitente = correo_sistema_nombre_gimnasio($conn);
        }

        $mail->setFrom($remitente, $nombreRemitente);
        $mail->isHTML(true);

        return [
            'mail' => $mail,
            'gimnasio' => correo_sistema_nombre_gimnasio($conn),
            'config' => $config,
            'host_original' => $host,
            'host_conexion' => $hostConexion,
        ];
    } catch (Throwable $e) {
        correo_sistema_error($e->getMessage());
        return null;
    }
}

function correo_sistema_h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

/** @param array<string,mixed> $payload */
function correo_sistema_enviar_inscripcion(mysqli $conn, array $payload): bool
{
    $email = trim((string) ($payload['email'] ?? ''));
    $nombre = trim((string) ($payload['nombre'] ?? 'Socio'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        correo_sistema_error('El correo del socio no es válido.');
        return false;
    }

    $paquete = correo_sistema_crear_mailer($conn, 6);
    if (!$paquete) {
        return false;
    }

    $mail = $paquete['mail'];
    $gimnasio = (string) $paquete['gimnasio'];

    try {
        $mail->addAddress($email, $nombre);
        $mail->Subject = 'Inscripción confirmada - ' . $gimnasio;

        $codigoQr = trim((string) ($payload['codigo_qr'] ?? ''));
        $qrPath = correo_sistema_ruta_archivo((string) ($payload['ruta_qr'] ?? ''));
        $qrHtml = '<div style="text-align:center;padding:15px;border:1px solid #dfe5ee;border-radius:12px;background:#f8fafc">'
            . '<div style="font-size:12px;color:#667085">Código de acceso</div>'
            . '<div style="margin-top:7px;font-family:monospace;font-size:16px;font-weight:700">'
            . correo_sistema_h($codigoQr) . '</div></div>';

        if ($qrPath !== '') {
            $mail->addEmbeddedImage($qrPath, 'qr_socio', 'codigo-qr.png', 'base64', 'image/png');
            $qrHtml = '<div style="text-align:center;margin:22px 0">'
                . '<img src="cid:qr_socio" alt="Código QR" style="display:block;width:210px;max-width:100%;height:auto;margin:0 auto 9px">'
                . '<div style="font-family:monospace;font-size:15px;font-weight:700">'
                . correo_sistema_h($codigoQr) . '</div></div>';
        }

        $comprobante = correo_sistema_resolver_comprobante($conn, $payload);
        if (empty($comprobante['success'])) {
            correo_sistema_error(
                'No fue posible preparar el comprobante PDF: '
                . (string) ($comprobante['error'] ?? 'Error desconocido')
            );
            return false;
        }
        $mail->addAttachment(
            (string) $comprobante['path'],
            (string) $comprobante['name'],
            'base64',
            'application/pdf'
        );

        $mail->Body = '<!doctype html><html lang="es"><body style="margin:0;background:#f4f7fb;font-family:Arial,sans-serif;color:#172033">'
            . '<div style="padding:28px 14px"><div style="max-width:640px;margin:auto;background:#fff;border:1px solid #dfe5ee;border-radius:16px;overflow:hidden">'
            . '<div style="padding:24px 28px;background:#244292;color:#fff"><h1 style="margin:0;font-size:24px">Inscripción confirmada</h1></div>'
            . '<div style="padding:26px 28px"><p>Hola <strong>' . correo_sistema_h($nombre) . '</strong>,</p>'
            . '<p>Tu inscripción en <strong>' . correo_sistema_h($gimnasio) . '</strong> se registró correctamente.</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:18px 0">'
            . '<tr><td style="padding:7px;color:#667085">Plan</td><td style="padding:7px;text-align:right;font-weight:700">' . correo_sistema_h((string) ($payload['plan'] ?? '')) . '</td></tr>'
            . '<tr><td style="padding:7px;color:#667085">Inicio</td><td style="padding:7px;text-align:right;font-weight:700">' . correo_sistema_h((string) ($payload['fecha_inicio'] ?? '')) . '</td></tr>'
            . '<tr><td style="padding:7px;color:#667085">Vigencia</td><td style="padding:7px;text-align:right;font-weight:700">' . correo_sistema_h((string) ($payload['fecha_fin'] ?? 'Sin vencimiento')) . '</td></tr>'
            . '<tr><td style="padding:7px;color:#667085">Monto</td><td style="padding:7px;text-align:right;font-weight:700">$' . number_format((float) ($payload['monto'] ?? 0), 2) . '</td></tr>'
            . '<tr><td style="padding:7px;color:#667085">Método</td><td style="padding:7px;text-align:right;font-weight:700">' . correo_sistema_h((string) ($payload['metodo_pago'] ?? '')) . '</td></tr>'
            . '</table>' . $qrHtml
            . '<p style="margin-top:22px;color:#667085;font-size:13px">Presenta este QR para registrar tu acceso. Tu comprobante PDF va adjunto en este correo.</p>'
            . '</div></div></div></body></html>';
        $mail->AltBody = "Hola {$nombre}. Tu inscripción en {$gimnasio} fue registrada. Código de acceso: {$codigoQr}.";
        $mail->send();
        return true;
    } catch (Throwable $e) {
        correo_sistema_error($e->getMessage());
        return false;
    }
}

/** @param array<string,mixed> $payload */
function correo_sistema_enviar_renovacion(mysqli $conn, array $payload): bool
{
    $email = trim((string) ($payload['email'] ?? ''));
    $nombre = trim((string) ($payload['nombre'] ?? 'Socio'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        correo_sistema_error('El correo del socio no es válido.');
        return false;
    }

    $paquete = correo_sistema_crear_mailer($conn, 6);
    if (!$paquete) {
        return false;
    }

    $mail = $paquete['mail'];
    $gimnasio = (string) $paquete['gimnasio'];

    try {
        $mail->addAddress($email, $nombre);
        $mail->Subject = 'Renovación confirmada - ' . $gimnasio;

        $codigoQr = trim((string) ($payload['codigo_qr'] ?? ''));
        $qrPath = correo_sistema_ruta_archivo((string) ($payload['ruta_qr'] ?? ''));
        $qrHtml = '';
        if ($qrPath !== '') {
            $mail->addEmbeddedImage($qrPath, 'qr_socio', 'codigo-qr.png', 'base64', 'image/png');
            $qrHtml = '<div style="text-align:center;margin:20px 0"><img src="cid:qr_socio" alt="Código QR" style="width:190px;max-width:100%;height:auto"><div style="font-family:monospace;font-weight:700">'
                . correo_sistema_h($codigoQr) . '</div></div>';
        }

        $comprobante = correo_sistema_resolver_comprobante($conn, $payload);
        if (empty($comprobante['success'])) {
            correo_sistema_error(
                'No fue posible preparar el comprobante PDF: '
                . (string) ($comprobante['error'] ?? 'Error desconocido')
            );
            return false;
        }
        $mail->addAttachment(
            (string) $comprobante['path'],
            (string) $comprobante['name'],
            'base64',
            'application/pdf'
        );

        $mail->Body = '<!doctype html><html lang="es"><body style="margin:0;background:#f4f7fb;font-family:Arial,sans-serif;color:#172033">'
            . '<div style="padding:28px 14px"><div style="max-width:640px;margin:auto;background:#fff;border:1px solid #dfe5ee;border-radius:16px;overflow:hidden">'
            . '<div style="padding:24px 28px;background:#244292;color:#fff"><h1 style="margin:0;font-size:24px">Renovación confirmada</h1></div>'
            . '<div style="padding:26px 28px"><p>Hola <strong>' . correo_sistema_h($nombre) . '</strong>,</p>'
            . '<p>Tu membresía en <strong>' . correo_sistema_h($gimnasio) . '</strong> fue renovada correctamente.</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:18px 0">'
            . '<tr><td style="padding:7px;color:#667085">Plan</td><td style="padding:7px;text-align:right;font-weight:700">' . correo_sistema_h((string) ($payload['plan'] ?? '')) . '</td></tr>'
            . '<tr><td style="padding:7px;color:#667085">Inicio</td><td style="padding:7px;text-align:right;font-weight:700">' . correo_sistema_h((string) ($payload['fecha_inicio'] ?? '')) . '</td></tr>'
            . '<tr><td style="padding:7px;color:#667085">Vigencia</td><td style="padding:7px;text-align:right;font-weight:700">' . correo_sistema_h((string) ($payload['fecha_fin'] ?? '')) . '</td></tr>'
            . '<tr><td style="padding:7px;color:#667085">Monto</td><td style="padding:7px;text-align:right;font-weight:700">$' . number_format((float) ($payload['monto'] ?? 0), 2) . '</td></tr>'
            . '</table>' . $qrHtml
            . '<p style="color:#667085;font-size:13px">El comprobante PDF de esta renovación va adjunto. Conserva este correo.</p>'
            . '</div></div></div></body></html>';
        $mail->AltBody = "Hola {$nombre}. Tu membresía en {$gimnasio} fue renovada correctamente. Código: {$codigoQr}.";
        $mail->send();
        return true;
    } catch (Throwable $e) {
        correo_sistema_error($e->getMessage());
        return false;
    }
}
