<?php
/**
 * Colocar como:
 * includes/password_reset_mailer.php
 *
 * Compatible con las mismas ubicaciones de PHPMailer utilizadas
 * por los demás módulos del sistema.
 */

function cargarPHPMailerRecuperacion(): void
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return;
    }

    $raiz = dirname(__DIR__);

    $autoloads = [
        $raiz . '/vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
    ];

    foreach ($autoloads as $autoload) {
        if (!is_file($autoload)) {
            continue;
        }

        require_once $autoload;

        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            return;
        }
    }

    /*
     * Tu sistema ya utiliza PHPMailer directamente en:
     * PHPMailer/PHPMailer.php
     * PHPMailer/SMTP.php
     * PHPMailer/Exception.php
     *
     * También se admiten instalaciones con la carpeta src.
     */
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
        [
            $raiz . '/phpmailer/Exception.php',
            $raiz . '/phpmailer/PHPMailer.php',
            $raiz . '/phpmailer/SMTP.php',
        ],
        [
            $raiz . '/phpmailer/src/Exception.php',
            $raiz . '/phpmailer/src/PHPMailer.php',
            $raiz . '/phpmailer/src/SMTP.php',
        ],
        [
            __DIR__ . '/PHPMailer/Exception.php',
            __DIR__ . '/PHPMailer/PHPMailer.php',
            __DIR__ . '/PHPMailer/SMTP.php',
        ],
        [
            __DIR__ . '/PHPMailer/src/Exception.php',
            __DIR__ . '/PHPMailer/src/PHPMailer.php',
            __DIR__ . '/PHPMailer/src/SMTP.php',
        ],
    ];

    foreach ($rutas as $grupo) {
        if (!is_file($grupo[0]) || !is_file($grupo[1]) || !is_file($grupo[2])) {
            continue;
        }

        require_once $grupo[0];
        require_once $grupo[1];
        require_once $grupo[2];

        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            return;
        }
    }

    throw new RuntimeException(
        'No se encontró PHPMailer. Se revisaron vendor/autoload.php, PHPMailer/ y PHPMailer/src/.'
    );
}

function obtenerConfiguracionCorreoRecuperacion(mysqli $db): array
{
    $tabla = $db->query("SHOW TABLES LIKE 'configuracion_correo'");

    if (!$tabla || $tabla->num_rows === 0) {
        throw new RuntimeException('La tabla configuracion_correo no existe.');
    }

    $resultado = $db->query(
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

    if (!$resultado || $resultado->num_rows === 0) {
        throw new RuntimeException('No existe la configuración SMTP con id = 1.');
    }

    $config = $resultado->fetch_assoc();

    if ((int) ($config['activo'] ?? 0) !== 1) {
        throw new RuntimeException('El envío de correo está desactivado.');
    }

    if (trim((string) ($config['host'] ?? '')) === '') {
        throw new RuntimeException('El host SMTP está vacío.');
    }

    if ((int) ($config['smtp_auth'] ?? 0) === 1) {
        if (trim((string) ($config['usuario'] ?? '')) === '') {
            throw new RuntimeException('El usuario SMTP está vacío.');
        }

        if (trim((string) ($config['password_smtp'] ?? '')) === '') {
            throw new RuntimeException('La contraseña SMTP está vacía.');
        }
    }

    $puerto = (int) ($config['puerto'] ?? 0);

    if ($puerto < 1 || $puerto > 65535) {
        throw new RuntimeException('El puerto SMTP configurado no es válido.');
    }

    $remitenteEmail = trim((string) ($config['remitente_email'] ?? ''));

    if ($remitenteEmail === '') {
        $remitenteEmail = trim((string) ($config['usuario'] ?? ''));
    }

    if (!filter_var($remitenteEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('El correo remitente no es válido.');
    }

    $config['remitente_email'] = $remitenteEmail;

    return $config;
}

function obtenerMensajeSeguroCorreo(Throwable $error): string
{
    $mensaje = trim($error->getMessage());

    if ($mensaje === '') {
        return 'El servidor de correo rechazó la solicitud sin proporcionar detalles.';
    }

    $mensajePlano = preg_replace('/\s+/', ' ', strip_tags($mensaje));
    $mensajePlano = trim((string) $mensajePlano);
    $mensajeMinusculas = strtolower($mensajePlano);

    if (
        str_contains($mensajeMinusculas, 'authenticate')
        || str_contains($mensajeMinusculas, 'authentication')
        || str_contains($mensajeMinusculas, 'username and password not accepted')
    ) {
        return 'Gmail rechazó el usuario o la contraseña de aplicación SMTP.';
    }

    if (
        str_contains($mensajeMinusculas, 'connect() failed')
        || str_contains($mensajeMinusculas, 'could not connect')
        || str_contains($mensajeMinusculas, 'connection refused')
        || str_contains($mensajeMinusculas, 'timed out')
        || str_contains($mensajeMinusculas, 'timeout')
    ) {
        return 'El servidor no pudo conectarse con el host SMTP. Revisa el puerto, el firewall o el acceso saliente del hosting.';
    }

    if (
        str_contains($mensajeMinusculas, 'certificate')
        || str_contains($mensajeMinusculas, 'crypto')
        || str_contains($mensajeMinusculas, 'ssl operation failed')
    ) {
        return 'Falló la conexión segura SSL/TLS con el servidor SMTP.';
    }

    if (str_contains($mensajeMinusculas, 'no se encontró phpmailer')) {
        return $mensajePlano;
    }

    if (strlen($mensajePlano) > 240) {
        $mensajePlano = substr($mensajePlano, 0, 237) . '...';
    }

    return $mensajePlano;
}

function enviarCorreoRecuperacion(
    mysqli $db,
    string $destinatarioEmail,
    string $destinatarioNombre,
    string $urlRecuperacion,
    string $nombreGimnasio,
    int $minutosVigencia = 30
): void {
    cargarPHPMailerRecuperacion();
    $config = obtenerConfiguracionCorreoRecuperacion($db);

    if (!filter_var($destinatarioEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('El correo destinatario no es válido.');
    }

    if (!filter_var($urlRecuperacion, FILTER_VALIDATE_URL)) {
        throw new RuntimeException(
            'La URL de recuperación no es válida. Revisa el dominio o la variable APP_URL.'
        );
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        /*
         * Se replica la configuración utilizada por los módulos
         * de correo que ya funcionan dentro del sistema.
         */
        $mail->isSMTP();
        $mail->Host = trim((string) $config['host']);
        $mail->Port = (int) $config['puerto'];
        $mail->SMTPAuth = (int) $config['smtp_auth'] === 1;
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 30;
        $mail->SMTPDebug = 0;

        if ($mail->SMTPAuth) {
            $mail->Username = (string) $config['usuario'];

            /*
             * Se usa exactamente el valor almacenado, igual que en el módulo
             * de configuración que ya envía correctamente.
             */
            $mail->Password = (string) $config['password_smtp'];
        }

        $cifrado = strtolower(trim((string) ($config['cifrado'] ?? '')));

        if ($cifrado === 'ssl' || $cifrado === 'smtps') {
            $mail->SMTPSecure =
                \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($cifrado === 'tls' || $cifrado === 'starttls') {
            $mail->SMTPSecure =
                \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
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
            ],
        ];

        $remitenteNombre = trim((string) ($config['remitente_nombre'] ?? ''));

        if ($remitenteNombre === '') {
            $remitenteNombre = $nombreGimnasio !== ''
                ? $nombreGimnasio
                : 'Gimnasio';
        }

        $mail->setFrom(
            (string) $config['remitente_email'],
            $remitenteNombre
        );

        $mail->addAddress($destinatarioEmail, $destinatarioNombre);
        $mail->isHTML(true);
        $mail->Subject = 'Recuperación de contraseña - ' . $nombreGimnasio;

        $nombreSeguro = htmlspecialchars(
            $destinatarioNombre,
            ENT_QUOTES,
            'UTF-8'
        );
        $gimnasioSeguro = htmlspecialchars(
            $nombreGimnasio,
            ENT_QUOTES,
            'UTF-8'
        );
        $urlSegura = htmlspecialchars(
            $urlRecuperacion,
            ENT_QUOTES,
            'UTF-8'
        );

        $mail->Body = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
</head>
<body style="margin:0;padding:0;background:#f3f6fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:28px 14px;background:#f3f6fb;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:590px;overflow:hidden;border-radius:18px;background:#ffffff;box-shadow:0 12px 32px rgba(15,23,42,.10);">
                    <tr>
                        <td style="padding:25px 30px;background:#1e3a8a;color:#ffffff;text-align:center;">
                            <div style="font-size:22px;font-weight:800;">{$gimnasioSeguro}</div>
                            <div style="margin-top:6px;color:#dbeafe;font-size:13px;">
                                Recuperación segura de contraseña
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px;">
                            <p style="margin:0 0 15px;font-size:16px;">
                                Hola, <strong>{$nombreSeguro}</strong>.
                            </p>

                            <p style="margin:0 0 22px;color:#4b5563;font-size:14px;line-height:1.65;">
                                Recibimos una solicitud para cambiar la contraseña de tu cuenta.
                            </p>

                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 auto 24px;">
                                <tr>
                                    <td style="border-radius:11px;background:#1e3a8a;">
                                        <a href="{$urlSegura}" style="display:inline-block;padding:14px 24px;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;">
                                            Restablecer contraseña
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <div style="padding:14px 16px;border:1px solid #dbeafe;border-radius:12px;background:#f8fbff;color:#475569;font-size:13px;line-height:1.55;">
                                El enlace vencerá en <strong>{$minutosVigencia} minutos</strong>
                                y solo puede utilizarse una vez.
                            </div>

                            <p style="margin:22px 0 0;color:#64748b;font-size:12px;line-height:1.55;">
                                Si no solicitaste este cambio, ignora este mensaje.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

        $mail->AltBody =
            "Hola {$destinatarioNombre}.\n\n" .
            "Abre este enlace para restablecer tu contraseña:\n" .
            $urlRecuperacion . "\n\n" .
            "El enlace vencerá en {$minutosVigencia} minutos.";

        $mail->send();
    } catch (\Throwable $error) {
        $detalle = $mail->ErrorInfo !== ''
            ? $mail->ErrorInfo
            : $error->getMessage();

        error_log(
            '[Recuperación contraseña SMTP] ' .
            $detalle
        );

        throw new RuntimeException(
            obtenerMensajeSeguroCorreo(
                new RuntimeException($detalle, 0, $error)
            ),
            0,
            $error
        );
    } finally {
        try {
            $mail->smtpClose();
        } catch (\Throwable $ignored) {
        }
    }
}