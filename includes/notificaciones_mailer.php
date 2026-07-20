<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

if (!function_exists('notif_load_phpmailer')) {
    function notif_load_phpmailer(): void
    {
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            return;
        }

        $root = dirname(__DIR__);
        $autoloads = [
            $root . '/vendor/autoload.php',
            __DIR__ . '/vendor/autoload.php',
        ];

        foreach ($autoloads as $autoload) {
            if (is_file($autoload)) {
                require_once $autoload;
            }

            if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                return;
            }
        }

        $groups = [
            [
                $root . '/PHPMailer/Exception.php',
                $root . '/PHPMailer/PHPMailer.php',
                $root . '/PHPMailer/SMTP.php',
            ],
            [
                $root . '/PHPMailer/src/Exception.php',
                $root . '/PHPMailer/src/PHPMailer.php',
                $root . '/PHPMailer/src/SMTP.php',
            ],
            [
                $root . '/phpmailer/Exception.php',
                $root . '/phpmailer/PHPMailer.php',
                $root . '/phpmailer/SMTP.php',
            ],
            [
                $root . '/phpmailer/src/Exception.php',
                $root . '/phpmailer/src/PHPMailer.php',
                $root . '/phpmailer/src/SMTP.php',
            ],
        ];

        foreach ($groups as $group) {
            if (
                !is_file($group[0])
                || !is_file($group[1])
                || !is_file($group[2])
            ) {
                continue;
            }

            require_once $group[0];
            require_once $group[1];
            require_once $group[2];

            if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                return;
            }
        }

        throw new RuntimeException(
            'No se encontró PHPMailer en vendor/, PHPMailer/ ni PHPMailer/src/.'
        );
    }
}

if (!function_exists('notif_mail_config')) {
    function notif_mail_config(mysqli $db): array
    {
        $result = $db->query(
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

        if (!$result) {
            throw new RuntimeException(
                'No fue posible leer configuracion_correo: ' . $db->error
            );
        }

        $config = $result->fetch_assoc();

        if (!$config) {
            throw new RuntimeException(
                'No existe la configuración SMTP con id = 1.'
            );
        }

        if ((int) $config['activo'] !== 1) {
            throw new RuntimeException(
                'El envío SMTP está desactivado en Configuración.'
            );
        }

        $required = ['host', 'puerto', 'remitente_email'];

        if ((int) $config['smtp_auth'] === 1) {
            $required[] = 'usuario';
            $required[] = 'password_smtp';
        }

        foreach ($required as $field) {
            if (trim((string) ($config[$field] ?? '')) === '') {
                throw new RuntimeException(
                    'La configuración SMTP está incompleta: '
                    . $field
                    . ' está vacío.'
                );
            }
        }

        if (!filter_var(
            $config['remitente_email'],
            FILTER_VALIDATE_EMAIL
        )) {
            throw new RuntimeException(
                'El correo del remitente configurado no es válido.'
            );
        }

        $port = (int) $config['puerto'];

        if ($port < 1 || $port > 65535) {
            throw new RuntimeException(
                'El puerto SMTP configurado no es válido.'
            );
        }

        return $config;
    }
}

if (!function_exists('notif_gym_name')) {
    function notif_gym_name(mysqli $db): string
    {
        $name = 'EGO';
        $result = $db->query(
            "SELECT nombre
             FROM configuracion_gimnasio
             WHERE id = 1
             LIMIT 1"
        );

        if ($result && $row = $result->fetch_assoc()) {
            $configured = trim((string) ($row['nombre'] ?? ''));

            if ($configured !== '') {
                $name = $configured;
            }
        }

        return $name;
    }
}

if (!function_exists('notif_mailer')) {
    function notif_mailer(mysqli $db): PHPMailer
    {
        notif_load_phpmailer();

        $config = notif_mail_config($db);
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = trim((string) $config['host']);
        $mail->Port = (int) $config['puerto'];
        $mail->SMTPAuth = (int) $config['smtp_auth'] === 1;
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->Timeout = 25;

        if ($mail->SMTPAuth) {
            $mail->Username = (string) $config['usuario'];
            $mail->Password = (string) $config['password_smtp'];
        }

        $encryption = strtolower(trim((string) ($config['cifrado'] ?? '')));

        if (in_array($encryption, ['ssl', 'smtps'], true)) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (in_array($encryption, ['tls', 'starttls'], true)) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $verifySsl = (int) $config['verificar_ssl'] === 1;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => $verifySsl,
                'verify_peer_name' => $verifySsl,
                'allow_self_signed' => !$verifySsl,
            ],
        ];

        $senderName = trim((string) ($config['remitente_nombre'] ?? ''));

        if ($senderName === '') {
            $senderName = notif_gym_name($db);
        }

        $mail->setFrom(
            (string) $config['remitente_email'],
            $senderName
        );
        $mail->isHTML(true);

        return $mail;
    }
}

if (!function_exists('notif_type_color')) {
    function notif_type_color(string $type): string
    {
        $colors = [
            'info' => '#2563eb',
            'aviso' => '#d97706',
            'alerta' => '#dc2626',
            'promocion' => '#059669',
        ];

        return $colors[$type] ?? $colors['info'];
    }
}

if (!function_exists('notif_send_manual')) {
    function notif_send_manual(
        mysqli $db,
        string $email,
        string $name,
        string $title,
        string $message,
        string $type,
        string $branchContext
    ): array {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Correo destinatario inválido.'];
        }

        try {
            $gym = notif_gym_name($db);
            $mail = notif_mailer($db);
            $mail->addAddress($email, $name);
            $mail->Subject = $gym . ' - ' . $title;

            $messageHtml = nl2br(htmlspecialchars(
                trim($message),
                ENT_QUOTES,
                'UTF-8'
            ));

            $color = notif_type_color($type);

            $mail->Body = '
            <!DOCTYPE html>
            <html lang="es">
            <head><meta charset="UTF-8"></head>
            <body style="margin:0;padding:24px;background:#f4f6f9;font-family:Arial,sans-serif;color:#1f2937;">
                <div style="max-width:620px;margin:0 auto;overflow:hidden;border:1px solid #e5e7eb;border-radius:14px;background:#fff;">
                    <div style="padding:22px 24px;color:#fff;background:' . $color . ';">
                        <div style="font-size:12px;font-weight:700;opacity:.88;">'
                            . htmlspecialchars($branchContext, ENT_QUOTES, 'UTF-8')
                            . '</div>
                        <h1 style="margin:5px 0 0;font-size:22px;">'
                            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
                            . '</h1>
                    </div>
                    <div style="padding:25px 24px;">
                        <p style="margin:0 0 18px;">Hola <strong>'
                            . htmlspecialchars($name !== '' ? $name : 'socio', ENT_QUOTES, 'UTF-8')
                            . '</strong>,</p>
                        <div style="font-size:15px;line-height:1.65;">'
                            . $messageHtml
                            . '</div>
                    </div>
                    <div style="padding:13px 24px;border-top:1px solid #e5e7eb;color:#64748b;background:#f8fafc;font-size:11px;text-align:center;">'
                        . htmlspecialchars($gym, ENT_QUOTES, 'UTF-8')
                        . ' · ' . date('Y') . '
                    </div>
                </div>
            </body>
            </html>';

            $mail->AltBody =
                $title
                . "\n\nHola "
                . ($name !== '' ? $name : 'socio')
                . ",\n\n"
                . trim($message)
                . "\n\n"
                . $branchContext;

            $mail->send();

            return ['ok' => true, 'error' => ''];
        } catch (Throwable $error) {
            $detail = $error->getMessage();

            if (
                isset($mail)
                && $mail instanceof PHPMailer
                && trim((string) $mail->ErrorInfo) !== ''
            ) {
                $detail = $mail->ErrorInfo;
            }

            error_log('[Notificaciones manuales] ' . $email . ': ' . $detail);

            return ['ok' => false, 'error' => $detail];
        }
    }
}

if (!function_exists('notif_send_expiration')) {
    function notif_send_expiration(
        mysqli $db,
        string $email,
        string $name,
        int $days,
        string $expirationDate,
        string $planName,
        string $branchName
    ): array {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Correo destinatario inválido.'];
        }

        try {
            $gym = notif_gym_name($db);
            $mail = notif_mailer($db);
            $mail->addAddress($email, $name);

            $today = $days === 0;
            $title = $today
                ? 'Tu membresía vence hoy'
                : 'Tu membresía está por vencer';

            $message = $today
                ? 'Tu membresía vence hoy. Renueva para mantener tu acceso.'
                : 'Tu membresía vencerá en 3 días. Puedes renovarla con anticipación.';

            $mail->Subject = $title . ' - ' . $gym;

            $mail->Body = '
            <!DOCTYPE html>
            <html lang="es">
            <head><meta charset="UTF-8"></head>
            <body style="margin:0;padding:24px;background:#f4f6f9;font-family:Arial,sans-serif;color:#1f2937;">
                <div style="max-width:620px;margin:0 auto;overflow:hidden;border:1px solid #e5e7eb;border-radius:14px;background:#fff;">
                    <div style="padding:22px 24px;color:#fff;background:#dc2626;">
                        <div style="font-size:12px;font-weight:700;opacity:.88;">'
                            . htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8')
                            . '</div>
                        <h1 style="margin:5px 0 0;font-size:22px;">'
                            . $title
                            . '</h1>
                    </div>
                    <div style="padding:25px 24px;">
                        <p>Hola <strong>'
                            . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                            . '</strong>,</p>
                        <p style="line-height:1.6;">'
                            . $message
                            . '</p>
                        <div style="margin:20px 0;padding:15px;border:1px solid #fecaca;border-radius:10px;background:#fef2f2;">
                            <strong>Plan:</strong> '
                            . htmlspecialchars($planName, ENT_QUOTES, 'UTF-8')
                            . '<br>
                            <strong>Fecha de vencimiento:</strong> '
                            . date('d/m/Y', strtotime($expirationDate))
                            . '
                        </div>
                        <p style="line-height:1.6;">Acércate a recepción para renovar tu membresía.</p>
                    </div>
                    <div style="padding:13px 24px;border-top:1px solid #e5e7eb;color:#64748b;background:#f8fafc;font-size:11px;text-align:center;">'
                        . htmlspecialchars($gym, ENT_QUOTES, 'UTF-8')
                        . ' · ' . date('Y') . '
                    </div>
                </div>
            </body>
            </html>';

            $mail->AltBody =
                $title
                . "\n\nHola "
                . $name
                . ",\n\n"
                . $message
                . "\nPlan: "
                . $planName
                . "\nFecha: "
                . $expirationDate
                . "\nSucursal: "
                . $branchName;

            $mail->send();

            return ['ok' => true, 'error' => ''];
        } catch (Throwable $error) {
            $detail = $error->getMessage();

            if (
                isset($mail)
                && $mail instanceof PHPMailer
                && trim((string) $mail->ErrorInfo) !== ''
            ) {
                $detail = $mail->ErrorInfo;
            }

            error_log('[Notificaciones vencimiento] ' . $email . ': ' . $detail);

            return ['ok' => false, 'error' => $detail];
        }
    }
}
