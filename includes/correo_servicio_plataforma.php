<?php
// Archivo: includes/correo_servicio_plataforma.php
// Envía el comprobante PDF de una renovación a los administradores activos.

declare(strict_types=1);

if (!function_exists('servicio_correo_cargar_phpmailer')) {
    function servicio_correo_cargar_phpmailer(): void
    {
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            return;
        }

        $raiz = dirname(__DIR__);
        $autoloads = [
            $raiz . '/vendor/autoload.php',
        ];

        foreach ($autoloads as $autoload) {
            if (is_file($autoload)) {
                require_once $autoload;
            }

            if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                return;
            }
        }

        $grupos = [
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
        ];

        foreach ($grupos as $grupo) {
            if (
                !is_file($grupo[0])
                || !is_file($grupo[1])
                || !is_file($grupo[2])
            ) {
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
            'No se encontró PHPMailer en vendor/ ni en la carpeta PHPMailer/.'
        );
    }
}

if (!function_exists('servicio_correo_cargar_fpdf')) {
    function servicio_correo_cargar_fpdf(): void
    {
        if (class_exists('FPDF')) {
            return;
        }

        $raiz = dirname(__DIR__);
        $candidatos = [
            $raiz . '/fpdf/fpdf.php',
            $raiz . '/FPDF/fpdf.php',
            $raiz . '/vendor/setasign/fpdf/fpdf.php',
        ];

        foreach ($candidatos as $archivo) {
            if (is_file($archivo)) {
                require_once $archivo;
            }

            if (class_exists('FPDF')) {
                return;
            }
        }

        throw new RuntimeException(
            'No se encontró FPDF para generar el comprobante de renovación.'
        );
    }
}

if (!function_exists('servicio_correo_configuracion')) {
    function servicio_correo_configuracion(mysqli $db): array
    {
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

        if (!$resultado) {
            throw new RuntimeException(
                'No fue posible leer configuracion_correo: ' . $db->error
            );
        }

        $configuracion = $resultado->fetch_assoc();

        if (!$configuracion) {
            throw new RuntimeException(
                'No existe la configuración de correo con id = 1.'
            );
        }

        if ((int) ($configuracion['activo'] ?? 0) !== 1) {
            throw new RuntimeException(
                'El envío de correo está desactivado en Configuración.'
            );
        }

        $requeridos = ['host', 'puerto', 'remitente_email'];

        if ((int) ($configuracion['smtp_auth'] ?? 0) === 1) {
            $requeridos[] = 'usuario';
            $requeridos[] = 'password_smtp';
        }

        foreach ($requeridos as $campo) {
            if (trim((string) ($configuracion[$campo] ?? '')) === '') {
                throw new RuntimeException(
                    'La configuración SMTP está incompleta: falta ' . $campo . '.'
                );
            }
        }

        if (!filter_var(
            (string) $configuracion['remitente_email'],
            FILTER_VALIDATE_EMAIL
        )) {
            throw new RuntimeException(
                'El correo remitente configurado no es válido.'
            );
        }

        $puerto = (int) $configuracion['puerto'];

        if ($puerto < 1 || $puerto > 65535) {
            throw new RuntimeException(
                'El puerto SMTP configurado no es válido.'
            );
        }

        return $configuracion;
    }
}

if (!function_exists('servicio_correo_nombre_gimnasio')) {
    function servicio_correo_nombre_gimnasio(mysqli $db): string
    {
        $nombre = 'EGO';
        $resultado = $db->query(
            "SELECT nombre
             FROM configuracion_gimnasio
             WHERE id = 1
             LIMIT 1"
        );

        if ($resultado && $fila = $resultado->fetch_assoc()) {
            $configurado = trim((string) ($fila['nombre'] ?? ''));

            if ($configurado !== '') {
                $nombre = $configurado;
            }
        }

        return $nombre;
    }
}

if (!function_exists('servicio_correo_obtener_administradores')) {
    function servicio_correo_obtener_administradores(mysqli $db): array
    {
        $resultado = $db->query(
            "SELECT id, nombre, email
             FROM usuarios
             WHERE rol = 'admin'
               AND estado = 'activo'
               AND email IS NOT NULL
               AND TRIM(email) <> ''
             ORDER BY nombre ASC"
        );

        if (!$resultado) {
            throw new RuntimeException(
                'No fue posible consultar a los administradores: ' . $db->error
            );
        }

        $administradores = [];

        while ($fila = $resultado->fetch_assoc()) {
            $email = trim((string) ($fila['email'] ?? ''));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $administradores[] = [
                'id' => (int) ($fila['id'] ?? 0),
                'nombre' => trim((string) ($fila['nombre'] ?? 'Administrador')),
                'email' => $email,
            ];
        }

        return $administradores;
    }
}

if (!function_exists('servicio_correo_crear_mailer')) {
    function servicio_correo_crear_mailer(mysqli $db): object
    {
        servicio_correo_cargar_phpmailer();
        $configuracion = servicio_correo_configuracion($db);

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = trim((string) $configuracion['host']);
        $mail->Port = (int) $configuracion['puerto'];
        $mail->SMTPAuth = (int) $configuracion['smtp_auth'] === 1;
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->Timeout = 25;

        if ($mail->SMTPAuth) {
            $mail->Username = (string) $configuracion['usuario'];
            $mail->Password = (string) $configuracion['password_smtp'];
        }

        $cifrado = strtolower(trim((string) (
            $configuracion['cifrado'] ?? ''
        )));

        if (in_array($cifrado, ['ssl', 'smtps'], true)) {
            $mail->SMTPSecure =
                \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif (in_array($cifrado, ['tls', 'starttls'], true)) {
            $mail->SMTPSecure =
                \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $verificarSsl =
            (int) ($configuracion['verificar_ssl'] ?? 0) === 1;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => $verificarSsl,
                'verify_peer_name' => $verificarSsl,
                'allow_self_signed' => !$verificarSsl,
            ],
        ];

        $remitenteNombre = trim((string) (
            $configuracion['remitente_nombre'] ?? ''
        ));

        if ($remitenteNombre === '') {
            $remitenteNombre = servicio_correo_nombre_gimnasio($db);
        }

        $mail->setFrom(
            (string) $configuracion['remitente_email'],
            $remitenteNombre
        );
        $mail->isHTML(true);

        return $mail;
    }
}

if (!function_exists('servicio_correo_texto_pdf')) {
    function servicio_correo_texto_pdf(string $texto): string
    {
        $convertido = @iconv(
            'UTF-8',
            'windows-1252//TRANSLIT//IGNORE',
            $texto
        );

        return $convertido !== false ? $convertido : $texto;
    }
}

if (!function_exists('servicio_correo_fecha')) {
    function servicio_correo_fecha(string $fecha): string
    {
        $timestamp = strtotime($fecha);

        return $timestamp ? date('d/m/Y', $timestamp) : $fecha;
    }
}

if (!function_exists('servicio_correo_nombre_archivo')) {
    function servicio_correo_nombre_archivo(string $texto): string
    {
        $texto = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $texto);
        $texto = trim((string) $texto, '_');

        return $texto !== '' ? strtolower($texto) : 'renovacion';
    }
}

if (!function_exists('servicio_correo_generar_comprobante')) {
    function servicio_correo_generar_comprobante(array $datos): array
    {
        servicio_correo_cargar_fpdf();

        $pdf = new class extends \FPDF {
            public function Footer(): void
            {
                $this->SetY(-13);
                $this->SetFont('Arial', '', 8);
                $this->SetTextColor(100, 116, 139);
                $this->Cell(
                    0,
                    5,
                    servicio_correo_texto_pdf(
                        'Comprobante generado por EGO · Página ' . $this->PageNo()
                    ),
                    0,
                    0,
                    'C'
                );
            }
        };

        $pdf->SetMargins(16, 15, 16);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        $pdf->SetFillColor(30, 58, 138);
        $pdf->Rect(0, 0, 210, 38, 'F');
        $pdf->SetXY(16, 10);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->Cell(
            0,
            8,
            servicio_correo_texto_pdf('Comprobante de renovación'),
            0,
            1
        );
        $pdf->SetX(16);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(
            0,
            6,
            servicio_correo_texto_pdf(
                (string) ($datos['gimnasio'] ?? 'EGO')
            ),
            0,
            1
        );

        $pdf->SetY(48);
        $pdf->SetTextColor(31, 41, 55);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(
            0,
            7,
            servicio_correo_texto_pdf('Datos del servicio'),
            0,
            1
        );
        $pdf->Ln(2);

        $filas = [
            ['Proveedor', (string) ($datos['proveedor'] ?? 'GGFit')],
            [
                'Periodo renovado',
                servicio_correo_fecha((string) ($datos['periodo_inicio'] ?? ''))
                . ' al '
                . servicio_correo_fecha((string) ($datos['periodo_fin'] ?? '')),
            ],
            ['Meses contratados', (string) ((int) ($datos['meses'] ?? 0))],
            [
                'Precio mensual',
                '$' . number_format((float) ($datos['precio_mensual'] ?? 0), 2)
                . ' MXN',
            ],
            [
                'Importe total',
                '$' . number_format((float) ($datos['importe_total'] ?? 0), 2)
                . ' MXN',
            ],
            [
                'Referencia',
                trim((string) ($datos['referencia'] ?? '')) !== ''
                    ? (string) $datos['referencia']
                    : 'Sin referencia',
            ],
            [
                'Registrado por',
                (string) ($datos['registrado_por'] ?? 'Superadministrador'),
            ],
            [
                'Fecha de registro',
                (string) ($datos['fecha_registro'] ?? date('d/m/Y H:i')),
            ],
        ];

        foreach ($filas as $indice => $fila) {
            $alto = 11;
            $pdf->SetFillColor(
                $indice % 2 === 0 ? 248 : 255,
                $indice % 2 === 0 ? 250 : 255,
                $indice % 2 === 0 ? 252 : 255
            );
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor(71, 85, 105);
            $pdf->Cell(
                48,
                $alto,
                servicio_correo_texto_pdf($fila[0]),
                0,
                0,
                'L',
                true
            );
            $pdf->SetFont('Arial', '', 9.5);
            $pdf->SetTextColor(31, 41, 55);
            $pdf->Cell(
                130,
                $alto,
                servicio_correo_texto_pdf($fila[1]),
                0,
                1,
                'L',
                true
            );
        }

        $notas = trim((string) ($datos['notas'] ?? ''));

        if ($notas !== '') {
            $pdf->Ln(7);
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->SetTextColor(31, 41, 55);
            $pdf->Cell(
                0,
                6,
                servicio_correo_texto_pdf('Notas'),
                0,
                1
            );
            $pdf->SetFont('Arial', '', 9);
            $pdf->SetTextColor(71, 85, 105);
            $pdf->MultiCell(
                0,
                5.5,
                servicio_correo_texto_pdf($notas),
                0,
                'L'
            );
        }

        $pdf->Ln(10);
        $pdf->SetFillColor(239, 246, 255);
        $pdf->SetTextColor(30, 58, 138);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(
            0,
            14,
            servicio_correo_texto_pdf(
                'La plataforma quedó vigente hasta el '
                . servicio_correo_fecha((string) ($datos['periodo_fin'] ?? ''))
                . '.'
            ),
            0,
            1,
            'C',
            true
        );

        $nombre = 'comprobante_renovacion_'
            . servicio_correo_nombre_archivo(
                (string) ($datos['gimnasio'] ?? 'ego')
            )
            . '_'
            . date('Ymd_His')
            . '.pdf';

        return [
            'nombre' => $nombre,
            'contenido' => $pdf->Output('S'),
            'mime' => 'application/pdf',
        ];
    }
}

if (!function_exists('servicio_correo_enviar_renovacion')) {
    function servicio_correo_enviar_renovacion(
        mysqli $db,
        array $datos
    ): array {
        $administradores = servicio_correo_obtener_administradores($db);

        if ($administradores === []) {
            return [
                'ok' => false,
                'parcial' => false,
                'enviados' => 0,
                'total' => 0,
                'errores' => [],
                'mensaje' =>
                    'No hay administradores activos con un correo válido.',
            ];
        }

        try {
            $comprobante = servicio_correo_generar_comprobante($datos);
        } catch (Throwable $error) {
            error_log(
                '[Correo servicio comprobante] ' . $error->getMessage()
            );

            return [
                'ok' => false,
                'parcial' => false,
                'enviados' => 0,
                'total' => count($administradores),
                'errores' => [$error->getMessage()],
                'mensaje' =>
                    'La renovación se guardó, pero no se pudo generar el comprobante PDF.',
            ];
        }

        $gimnasio = (string) ($datos['gimnasio'] ?? 'EGO');
        $enviados = 0;
        $errores = [];

        foreach ($administradores as $administrador) {
            try {
                $mail = servicio_correo_crear_mailer($db);
                $mail->addAddress(
                    (string) $administrador['email'],
                    (string) $administrador['nombre']
                );
                $mail->Subject =
                    'Renovación del servicio confirmada - ' . $gimnasio;

                $nombreSeguro = htmlspecialchars(
                    (string) $administrador['nombre'],
                    ENT_QUOTES,
                    'UTF-8'
                );
                $gimnasioSeguro = htmlspecialchars(
                    $gimnasio,
                    ENT_QUOTES,
                    'UTF-8'
                );
                $inicioSeguro = htmlspecialchars(
                    servicio_correo_fecha(
                        (string) ($datos['periodo_inicio'] ?? '')
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                );
                $finSeguro = htmlspecialchars(
                    servicio_correo_fecha(
                        (string) ($datos['periodo_fin'] ?? '')
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                );
                $referencia = trim((string) ($datos['referencia'] ?? ''));
                $referenciaSeguro = htmlspecialchars(
                    $referencia !== '' ? $referencia : 'Sin referencia',
                    ENT_QUOTES,
                    'UTF-8'
                );

                $mail->Body = '<!doctype html><html lang="es"><body style="margin:0;padding:24px;background:#f4f6f9;font-family:Arial,sans-serif;color:#1f2937;">'
                    . '<div style="max-width:640px;margin:0 auto;overflow:hidden;border:1px solid #e5e7eb;border-radius:16px;background:#ffffff;">'
                    . '<div style="padding:24px 26px;background:#1e3a8a;color:#ffffff;">'
                    . '<div style="font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;opacity:.82;">Servicio de plataforma</div>'
                    . '<h1 style="margin:7px 0 0;font-size:23px;">Renovación confirmada</h1>'
                    . '</div>'
                    . '<div style="padding:26px;">'
                    . '<p style="margin-top:0;">Hola <strong>' . $nombreSeguro . '</strong>,</p>'
                    . '<p>Se registró correctamente la renovación del servicio de <strong>' . $gimnasioSeguro . '</strong>.</p>'
                    . '<table style="width:100%;margin:22px 0;border-collapse:collapse;border-top:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb;">'
                    . '<tr><td style="padding:11px 0;color:#64748b;">Nuevo periodo</td><td style="padding:11px 0;text-align:right;font-weight:700;">' . $inicioSeguro . ' al ' . $finSeguro . '</td></tr>'
                    . '<tr><td style="padding:11px 0;color:#64748b;">Meses</td><td style="padding:11px 0;text-align:right;font-weight:700;">' . (int) ($datos['meses'] ?? 0) . '</td></tr>'
                    . '<tr><td style="padding:11px 0;color:#64748b;">Importe</td><td style="padding:11px 0;text-align:right;font-weight:700;">$' . number_format((float) ($datos['importe_total'] ?? 0), 2) . ' MXN</td></tr>'
                    . '<tr><td style="padding:11px 0;color:#64748b;">Referencia</td><td style="padding:11px 0;text-align:right;font-weight:700;">' . $referenciaSeguro . '</td></tr>'
                    . '</table>'
                    . '<p style="margin-bottom:0;color:#64748b;font-size:13px;">Se adjunta el comprobante PDF de la renovación.</p>'
                    . '</div>'
                    . '<div style="padding:14px 26px;border-top:1px solid #e5e7eb;background:#f8fafc;color:#64748b;font-size:11px;text-align:center;">EGO · Control de servicio</div>'
                    . '</div></body></html>';

                $mail->AltBody =
                    "Renovación confirmada\n\n"
                    . "Nuevo periodo: "
                    . servicio_correo_fecha(
                        (string) ($datos['periodo_inicio'] ?? '')
                    )
                    . ' al '
                    . servicio_correo_fecha(
                        (string) ($datos['periodo_fin'] ?? '')
                    )
                    . "\nImporte: $"
                    . number_format(
                        (float) ($datos['importe_total'] ?? 0),
                        2
                    )
                    . " MXN\nReferencia: "
                    . ($referencia !== '' ? $referencia : 'Sin referencia');

                $mail->addStringAttachment(
                    (string) $comprobante['contenido'],
                    (string) $comprobante['nombre'],
                    'base64',
                    (string) $comprobante['mime']
                );
                $mail->send();
                $enviados++;
            } catch (Throwable $error) {
                $detalle = $error->getMessage();

                if (
                    isset($mail)
                    && $mail instanceof \PHPMailer\PHPMailer\PHPMailer
                    && trim((string) $mail->ErrorInfo) !== ''
                ) {
                    $detalle = (string) $mail->ErrorInfo;
                }

                $errores[] =
                    (string) $administrador['nombre'] . ': ' . $detalle;
                error_log(
                    '[Correo renovación servicio] '
                    . (string) $administrador['email']
                    . ': '
                    . $detalle
                );
            }
        }

        $total = count($administradores);
        $parcial = $enviados > 0 && $enviados < $total;

        return [
            'ok' => $enviados === $total,
            'parcial' => $parcial,
            'enviados' => $enviados,
            'total' => $total,
            'errores' => $errores,
            'mensaje' => $enviados === $total
                ? 'El comprobante se envió a todos los administradores.'
                : ($enviados > 0
                    ? 'El comprobante se envió solo a algunos administradores.'
                    : 'No fue posible enviar el comprobante por correo.'),
        ];
    }
}
