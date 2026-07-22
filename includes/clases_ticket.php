<?php
declare(strict_types=1);

function clases_ticket_cargar_fpdf(): void
{
    if (class_exists('FPDF')) {
        return;
    }

    $rutas = [
        __DIR__ . '/../vendor/setasign/fpdf/fpdf.php',
        __DIR__ . '/../vendor/fpdf/fpdf.php',
        __DIR__ . '/../fpdf/fpdf.php',
        __DIR__ . '/../lib/fpdf/fpdf.php',
        __DIR__ . '/../includes/fpdf/fpdf.php',
    ];

    foreach ($rutas as $ruta) {
        if (is_file($ruta)) {
            require_once $ruta;
            break;
        }
    }

    if (!class_exists('FPDF')) {
        throw new RuntimeException(
            'No se encontró FPDF. Ajusta la ruta dentro de includes/clases_ticket.php.'
        );
    }
}

function clases_ticket_texto(string $texto): string
{
    $convertido = @iconv('UTF-8', 'windows-1252//TRANSLIT', $texto);
    return is_string($convertido) ? $convertido : $texto;
}

function clases_ticket_pdf(array $datos): string
{
    clases_ticket_cargar_fpdf();

    $folioVisible = trim((string) (
        $datos['folio_visible']
        ?? $datos['folio']
        ?? ''
    ));

    $pdf = new FPDF('P', 'mm', [80, 170]);
    $pdf->SetMargins(6, 7, 6);
    $pdf->SetAutoPageBreak(true, 7);
    $pdf->AddPage();

    $pdf->SetFont('Arial', 'B', 15);
    $pdf->Cell(
        0,
        7,
        clases_ticket_texto((string) $datos['sucursal_nombre']),
        0,
        1,
        'C'
    );

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(95, 105, 120);
    $pdf->Cell(
        0,
        4,
        clases_ticket_texto('Comprobante de acceso a clase'),
        0,
        1,
        'C'
    );
    $pdf->Ln(2);

    $pdf->SetDrawColor(220, 225, 234);
    $pdf->Line(6, $pdf->GetY(), 74, $pdf->GetY());
    $pdf->Ln(4);

    $pdf->SetTextColor(31, 41, 55);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->MultiCell(
        0,
        6,
        clases_ticket_texto((string) $datos['clase_nombre']),
        0,
        'L'
    );

    $pdf->SetFont('Arial', '', 8.5);
    $filas = [
        'Participante' => (string) $datos['participante_nombre'],
        'Fecha' => (string) $datos['fecha_clase_texto'],
        'Horario' => (string) $datos['horario_texto'],
        'Entrenador' => (string) $datos['instructor'],
        'Acceso' => $folioVisible,
    ];

    foreach ($filas as $etiqueta => $valor) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(
            21,
            5,
            clases_ticket_texto($etiqueta . ':'),
            0,
            0
        );
        $pdf->SetFont('Arial', '', 8);
        $pdf->MultiCell(
            0,
            5,
            clases_ticket_texto($valor),
            0,
            'L'
        );
    }

    $pdf->Ln(2);
    $pdf->SetFillColor(244, 247, 252);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(
        34,
        7,
        clases_ticket_texto('Importe'),
        0,
        0,
        'L',
        true
    );
    $pdf->Cell(
        34,
        7,
        '$' . number_format((float) $datos['monto'], 2),
        0,
        1,
        'R',
        true
    );

    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(
        34,
        6,
        clases_ticket_texto('Forma de acceso'),
        0,
        0,
        'L'
    );
    $pdf->Cell(
        34,
        6,
        clases_ticket_texto((string) $datos['forma_acceso']),
        0,
        1,
        'R'
    );

    if ((float) ($datos['cambio'] ?? 0) > 0) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(
            34,
            6,
            clases_ticket_texto('Cambio'),
            0,
            0,
            'L'
        );
        $pdf->Cell(
            34,
            6,
            '$' . number_format((float) $datos['cambio'], 2),
            0,
            1,
            'R'
        );
    }

    $pdf->Ln(4);
    $pdf->SetTextColor(95, 105, 120);
    $pdf->SetFont('Arial', '', 7.5);
    $pdf->MultiCell(
        0,
        4,
        clases_ticket_texto(
            'Presenta este comprobante al ingresar. El acceso es válido únicamente para la clase, fecha y horario indicados.'
        ),
        0,
        'C'
    );

    return (string) $pdf->Output('S');
}

function clases_ticket_emitir(
    mysqli $conn,
    int $inscripcionClaseId,
    array $datos
): array {
    $resultado = [
        'guardado' => false,
        'correo_enviado' => false,
        'nombre_archivo' => '',
        'error' => '',
    ];

    try {
        $pdf = clases_ticket_pdf($datos);

        /*
         * El folio técnico continúa en la base para auditoría, pero el nombre
         * del archivo queda corto, único y entendible.
         * Ejemplo: acceso_000004.pdf
         */
        $nombreArchivo = 'acceso_'
            . str_pad(
                (string) $inscripcionClaseId,
                6,
                '0',
                STR_PAD_LEFT
            )
            . '.pdf';

        $resultado['nombre_archivo'] = $nombreArchivo;

        $email = trim((string) ($datos['email'] ?? ''));
        $stmt = $conn->prepare(
            "INSERT INTO tickets_clases (
                inscripcion_clase_id,
                folio,
                participante_nombre,
                email_destino,
                total,
                metodo_pago,
                ticket_pdf,
                ticket_nombre,
                fecha_emision
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );

        $metodo = (string) (
            $datos['metodo_pago_local']
            ?? 'sin_cobro'
        );
        $participante = (string) $datos['participante_nombre'];
        $folio = (string) $datos['folio'];
        $folioVisible = trim((string) (
            $datos['folio_visible']
            ?? $folio
        ));
        $total = round((float) $datos['monto'], 2);
        $null = null;

        $stmt->bind_param(
            'isssdsbs',
            $inscripcionClaseId,
            $folio,
            $participante,
            $email,
            $total,
            $metodo,
            $null,
            $nombreArchivo
        );
        $stmt->send_long_data(6, $pdf);
        $stmt->execute();
        $stmt->close();
        $resultado['guardado'] = true;

        if (
            $email !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            $correoHelper = __DIR__ . '/correo_inscripciones.php';

            if (is_file($correoHelper)) {
                require_once $correoHelper;
            }

            if (function_exists('crearMailerInscripciones')) {
                $paquete = crearMailerInscripciones($conn);

                if (is_array($paquete) && isset($paquete['mail'])) {
                    $mail = $paquete['mail'];
                    $mail->addAddress($email, $participante);
                    $mail->Subject =
                        'Acceso confirmado - '
                        . (string) $datos['clase_nombre'];
                    $mail->isHTML(true);
                    $mail->Body =
                        '<div style="font-family:Arial,sans-serif;background:#f4f6f9;padding:24px">' .
                        '<div style="max-width:620px;margin:auto;background:#fff;border-radius:14px;padding:26px;border:1px solid #e5e7eb">' .
                        '<h2 style="margin:0;color:#1e3a8a">Acceso confirmado</h2>' .
                        '<p style="color:#4b5563">Hola ' .
                        htmlspecialchars(
                            $participante,
                            ENT_QUOTES,
                            'UTF-8'
                        ) .
                        ', tu lugar quedó reservado.</p>' .
                        '<div style="background:#f8fafc;border-radius:10px;padding:16px">' .
                        '<strong>' .
                        htmlspecialchars(
                            (string) $datos['clase_nombre'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) .
                        '</strong><br>' .
                        htmlspecialchars(
                            (string) $datos['fecha_clase_texto'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) .
                        ' · ' .
                        htmlspecialchars(
                            (string) $datos['horario_texto'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) .
                        '<br>Acceso: ' .
                        htmlspecialchars(
                            $folioVisible,
                            ENT_QUOTES,
                            'UTF-8'
                        ) .
                        '</div><p style="color:#6b7280;font-size:13px">Adjuntamos tu comprobante PDF.</p>' .
                        '</div></div>';
                    $mail->AltBody =
                        "Acceso confirmado\n" .
                        (string) $datos['clase_nombre'] .
                        "\n" .
                        (string) $datos['fecha_clase_texto'] .
                        ' ' .
                        (string) $datos['horario_texto'] .
                        "\nAcceso: " .
                        $folioVisible;
                    $mail->addStringAttachment(
                        $pdf,
                        $nombreArchivo,
                        'base64',
                        'application/pdf'
                    );
                    $mail->send();
                    $resultado['correo_enviado'] = true;

                    $stmtUpdate = $conn->prepare(
                        "UPDATE tickets_clases
                         SET correo_enviado = 1
                         WHERE inscripcion_clase_id = ?"
                    );
                    $stmtUpdate->bind_param(
                        'i',
                        $inscripcionClaseId
                    );
                    $stmtUpdate->execute();
                    $stmtUpdate->close();
                }
            }
        }
    } catch (Throwable $error) {
        $resultado['error'] = $error->getMessage();
        error_log('[Ticket clase] ' . $error->getMessage());
    }

    return $resultado;
}
