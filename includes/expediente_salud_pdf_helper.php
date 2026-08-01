<?php

declare(strict_types=1);

/**
 * Generador unificado del PDF del expediente de salud.
 *
 * Conserva el diseño limpio anterior: encabezado ligero, tarjetas redondeadas,
 * preguntas por secciones, documento de responsabilidad, aceptación e
 * integridad. Se utiliza tanto para descargar como para adjuntar por correo.
 */

if (!function_exists('expediente_formatear_fecha')) {
    require_once __DIR__ . '/expediente_salud_helper.php';
}

function expediente_pdf_bonito_cargar_libreria(): void
{
    if (class_exists('FPDF', false)) {
        return;
    }

    /*
     * Este archivo vive dentro de /includes. Por eso la raíz real del
     * proyecto es dirname(__DIR__). Usar __DIR__ . '/includes/...' hacía
     * que PHP buscara por error en /includes/includes/fpdf.php.
     */
    $raizProyecto = dirname(__DIR__);

    $autoloads = [
        $raizProyecto . '/vendor/autoload.php',
    ];

    foreach ($autoloads as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;
            if (class_exists('FPDF')) {
                return;
            }
        }
    }

    $rutas = [
        $raizProyecto . '/includes/fpdf.php',
        $raizProyecto . '/includes/fpdf/fpdf.php',
        $raizProyecto . '/fpdf/fpdf.php',
        $raizProyecto . '/lib/fpdf/fpdf.php',
        $raizProyecto . '/libraries/fpdf/fpdf.php',
        $raizProyecto . '/vendor/setasign/fpdf/fpdf.php',
        $raizProyecto . '/vendor/fpdf/fpdf.php',
        __DIR__ . '/fpdf.php',
        __DIR__ . '/fpdf/fpdf.php',
    ];

    $rutasIntentadas = [];

    foreach ($rutas as $ruta) {
        $rutasIntentadas[] = $ruta;

        if (!is_file($ruta)) {
            continue;
        }

        require_once $ruta;

        if (class_exists('FPDF')) {
            return;
        }
    }

    error_log(
        '[Expediente salud PDF] No se encontró FPDF. Rutas revisadas: ' .
        implode(' | ', $rutasIntentadas)
    );

    throw new RuntimeException(
        'No se encontró la librería FPDF. Verifica que continúe instalada en includes/fpdf.php.'
    );
}

function expediente_pdf_bonito_texto(string $texto): string
{
    $texto = str_replace(
        ["\u{2013}", "\u{2014}", "\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2022}"],
        ['-', '-', "'", "'", '"', '"', '-'],
        $texto
    );

    $convertido = @iconv('UTF-8', 'windows-1252//TRANSLIT', $texto);

    return is_string($convertido) ? $convertido : $texto;
}

function expediente_pdf_bonito_respuesta(array $respuesta): string
{
    $valor = trim((string) ($respuesta['respuesta_texto'] ?? ''));
    $tipo = trim((string) ($respuesta['tipo_respuesta_snapshot'] ?? ''));

    if ($valor === '') {
        return 'Sin respuesta';
    }

    if ($tipo === 'si_no') {
        $normalizado = function_exists('mb_strtolower')
            ? mb_strtolower($valor, 'UTF-8')
            : strtolower($valor);

        if (in_array($normalizado, ['1', 'si', 'sí', 'true'], true)) {
            return 'Sí';
        }

        if (in_array($normalizado, ['0', 'no', 'false'], true)) {
            return 'No';
        }
    }

    return $valor;
}

function expediente_pdf_bonito_nombre_archivo(array $expediente): string
{
    $nombre = trim(
        (string) ($expediente['nombre'] ?? '') . ' ' .
        (string) ($expediente['apellido'] ?? '')
    );

    $nombre = preg_replace('/[^\pL\pN _-]+/u', '', $nombre) ?: 'Socio';
    $nombre = preg_replace('/\s+/u', '_', trim($nombre)) ?: 'Socio';

    $fechaTimestamp = strtotime((string) ($expediente['fecha_aplicacion'] ?? ''));
    $fecha = date('Y-m-d', $fechaTimestamp !== false ? $fechaTimestamp : time());

    return 'Expediente_Salud_' . $nombre . '_' . $fecha . '.pdf';
}

expediente_pdf_bonito_cargar_libreria();

class ExpedienteSaludBonitoPDF extends FPDF
{
    /** @var array<string,mixed> */
    private array $encabezado = [];
    private string $logo = '';

    /** @param array<string,mixed> $datos */
    public function configurarEncabezado(array $datos, string $logo): void
    {
        $this->encabezado = $datos;
        $this->logo = $logo;
    }

    public function Header(): void
    {
        $this->SetDrawColor(23, 37, 84);
        $this->SetFillColor(255, 255, 255);

        $xTexto = 15.0;
        if ($this->logo !== '') {
            $extension = strtolower((string) pathinfo($this->logo, PATHINFO_EXTENSION));
            if (in_array($extension, ['png', 'jpg', 'jpeg'], true)) {
                $this->Image($this->logo, 15, 10, 18, 18);
                $xTexto = 38.0;
            }
        }

        if ($xTexto === 15.0) {
            $this->SetFillColor(238, 244, 255);
            $this->SetDrawColor(191, 219, 254);
            $this->RoundedRect(15, 10, 18, 18, 3, 'DF');
            $this->SetXY(15, 15);
            $this->SetTextColor(30, 64, 175);
            $this->SetFont('Arial', 'B', 11);
            $this->Cell(18, 8, 'EGO', 0, 0, 'C');
            $xTexto = 38.0;
        }

        $this->SetXY($xTexto, 10);
        $this->SetTextColor(23, 37, 84);
        $this->SetFont('Arial', 'B', 15);
        $this->Cell(
            112,
            7,
            expediente_pdf_bonito_texto((string) ($this->encabezado['gimnasio_nombre'] ?? 'Gimnasio')),
            0,
            1,
            'L'
        );

        $this->SetX($xTexto);
        $this->SetFont('Arial', '', 8.3);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(
            112,
            5,
            expediente_pdf_bonito_texto('Expediente de salud y aceptación de responsabilidad'),
            0,
            0,
            'L'
        );

        $this->SetXY(153, 10);
        $this->SetFont('Arial', '', 7.2);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(42, 4, expediente_pdf_bonito_texto('FOLIO INTERNO'), 0, 1, 'R');
        $this->SetX(153);
        $this->SetFont('Arial', 'B', 11.5);
        $this->SetTextColor(23, 37, 84);
        $this->Cell(
            42,
            6,
            'MED-' . str_pad((string) ($this->encabezado['id'] ?? 0), 8, '0', STR_PAD_LEFT),
            0,
            1,
            'R'
        );

        $this->SetDrawColor(23, 37, 84);
        $this->SetLineWidth(0.55);
        $this->Line(15, 33, 195, 33);
        $this->SetLineWidth(0.2);
        $this->SetY(41);
    }

    public function Footer(): void
    {
        $this->SetY(-14);
        $this->SetDrawColor(226, 232, 240);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(
            145,
            4,
            expediente_pdf_bonito_texto('Documento generado desde el sistema de gestión. Este registro no sustituye una valoración médica.'),
            0,
            0,
            'L'
        );
        $this->Cell(35, 4, expediente_pdf_bonito_texto('Página ' . $this->PageNo() . '/{nb}'), 0, 0, 'R');
    }

    public function asegurarEspacio(float $altoNecesario): void
    {
        if ($this->GetY() + $altoNecesario > 272) {
            $this->AddPage();
        }
    }

    public function tituloDocumento(string $titulo): void
    {
        $this->SetTextColor(23, 37, 84);
        $this->SetFont('Arial', 'B', 15);
        $this->MultiCell(180, 6.5, expediente_pdf_bonito_texto($titulo), 0, 'L');

        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(180, 4.5, expediente_pdf_bonito_texto('Registro histórico no editable'), 0, 1, 'L');
        $this->Ln(4);
    }

    public function tarjetaMeta(string $etiqueta, string $valor, float $x, float $y, float $ancho = 58): void
    {
        $alto = 17.0;
        $this->SetFillColor(248, 250, 252);
        $this->SetDrawColor(219, 228, 240);
        $this->RoundedRect($x, $y, $ancho, $alto, 2.3, 'DF');

        $this->SetXY($x + 3, $y + 2.5);
        $this->SetFont('Arial', '', 6.7);
        $this->SetTextColor(100, 116, 139);
        $this->Cell($ancho - 6, 3.5, expediente_pdf_bonito_texto(strtoupper($etiqueta)), 0, 1, 'L');

        $this->SetXY($x + 3, $y + 7);
        $this->SetFont('Arial', 'B', 8.1);
        $this->SetTextColor(51, 65, 85);
        $this->MultiCell($ancho - 6, 3.8, expediente_pdf_bonito_texto($valor), 0, 'L');
    }

    public function tituloSeccion(string $titulo): void
    {
        $this->asegurarEspacio(14);
        $this->Ln(5);
        $this->SetTextColor(23, 37, 84);
        $this->SetFont('Arial', 'B', 10.5);
        $this->Cell(180, 6, expediente_pdf_bonito_texto($titulo), 0, 1, 'L');
        $this->SetDrawColor(203, 213, 225);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(2);
    }

    public function filaPregunta(string $pregunta, string $respuesta, bool $alerta): void
    {
        $anchoPregunta = 126.0;
        $anchoRespuesta = 54.0;
        $preguntaPdf = expediente_pdf_bonito_texto($pregunta);
        $respuestaPdf = expediente_pdf_bonito_texto($respuesta);

        $lineasPregunta = $this->numeroLineas($anchoPregunta - 7, $preguntaPdf);
        $lineasRespuesta = $this->numeroLineas($anchoRespuesta - 7, $respuestaPdf);
        $alto = max(8.5, max($lineasPregunta, $lineasRespuesta) * 4.2 + 4.0);

        $this->asegurarEspacio($alto + 1.5);
        $x = 15.0;
        $y = $this->GetY();

        if ($alerta) {
            $this->SetFillColor(255, 251, 235);
            $this->SetDrawColor(252, 211, 77);
            $this->RoundedRect($x, $y, 180, $alto, 1.8, 'DF');
        } else {
            $this->SetDrawColor(237, 242, 247);
            $this->Line($x, $y + $alto, 195, $y + $alto);
        }

        $this->SetXY($x + 2, $y + 2);
        $this->SetTextColor(31, 41, 55);
        $this->SetFont('Arial', 'B', 8.3);
        $this->MultiCell($anchoPregunta - 5, 4.2, $preguntaPdf, 0, 'L');

        $this->SetXY($x + $anchoPregunta + 2, $y + 2);
        $this->SetTextColor(71, 85, 105);
        $this->SetFont('Arial', '', 8.3);
        $this->MultiCell($anchoRespuesta - 4, 4.2, $respuestaPdf, 0, 'L');

        $this->SetY($y + $alto);
    }

    public function bloqueDocumento(string $titulo, string $texto): void
    {
        $tituloPdf = expediente_pdf_bonito_texto($titulo);
        $textoPdf = expediente_pdf_bonito_texto($texto);
        $lineas = $this->numeroLineas(170, $textoPdf);
        $altoTexto = max(12.0, $lineas * 4.5 + 4.0);
        $altoTotal = 10.0 + $altoTexto;

        if ($altoTotal <= 95.0) {
            $this->asegurarEspacio($altoTotal + 7);
            $this->Ln(6);
            $x = 15.0;
            $y = $this->GetY();

            $this->SetFillColor(248, 251, 255);
            $this->SetDrawColor(191, 219, 254);
            $this->RoundedRect($x, $y, 180, $altoTotal, 2.5, 'DF');

            $this->SetXY($x + 5, $y + 4);
            $this->SetFont('Arial', 'B', 10.5);
            $this->SetTextColor(23, 37, 84);
            $this->Cell(170, 5, $tituloPdf, 0, 1, 'L');

            $this->SetXY($x + 5, $y + 11);
            $this->SetFont('Arial', '', 8.5);
            $this->SetTextColor(71, 85, 105);
            $this->MultiCell(170, 4.5, $textoPdf, 0, 'J');
            $this->SetY($y + $altoTotal);
            return;
        }

        $this->tituloSeccion($titulo);
        $this->SetFillColor(248, 251, 255);
        $this->SetDrawColor(191, 219, 254);
        $this->SetTextColor(71, 85, 105);
        $this->SetFont('Arial', '', 8.5);
        $this->MultiCell(180, 4.5, $textoPdf, 1, 'J', true);
    }

    /** @param array<string,mixed> $expediente */
    public function bloqueAceptacion(array $expediente): void
    {
        $this->asegurarEspacio(38);
        $this->Ln(6);

        $x = 15.0;
        $y = $this->GetY();
        $alto = 30.0;

        $this->SetFillColor(240, 253, 244);
        $this->SetDrawColor(134, 239, 172);
        $this->RoundedRect($x, $y, 180, $alto, 2.5, 'DF');

        $this->SetXY($x + 5, $y + 4);
        $this->SetFont('Arial', 'B', 9.5);
        $this->SetTextColor(4, 120, 87);
        $this->Cell(135, 5, expediente_pdf_bonito_texto('Aceptación del documento: Registrada'), 0, 1, 'L');

        $this->SetX($x + 5);
        $this->SetFont('Arial', '', 8.1);
        $this->SetTextColor(71, 85, 105);
        $lineas = [
            'Aceptado por: ' . trim((string) ($expediente['nombre_firmante'] ?? '')),
            'Relación con el socio: ' . trim((string) ($expediente['parentesco_firmante'] ?? '')),
            'Fecha: ' . expediente_formatear_fecha((string) ($expediente['fecha_aplicacion'] ?? ''), true),
        ];
        $this->MultiCell(135, 4.5, expediente_pdf_bonito_texto(implode("\n", $lineas)), 0, 'L');

        $cx = $x + 158;
        $cy = $y + 15;
        $this->SetFillColor(220, 252, 231);
        $this->SetDrawColor(34, 197, 94);
        $this->Circle($cx, $cy, 9, 'DF');
        $this->SetXY($cx - 8, $cy - 5.8);
        $this->SetFont('Arial', 'B', 15);
        $this->SetTextColor(4, 120, 87);
        $this->Cell(16, 11, 'OK', 0, 0, 'C');

        $this->SetY($y + $alto);
    }

    public function bloqueObservaciones(string $texto): void
    {
        $textoPdf = expediente_pdf_bonito_texto($texto !== '' ? $texto : 'Sin observaciones adicionales.');
        $lineas = $this->numeroLineas(170, $textoPdf);
        $alto = max(19.0, 10.0 + ($lineas * 4.4));

        $this->asegurarEspacio($alto + 7);
        $this->Ln(6);
        $x = 15.0;
        $y = $this->GetY();

        $this->SetFillColor(255, 255, 255);
        $this->SetDrawColor(219, 228, 240);
        $this->RoundedRect($x, $y, 180, $alto, 2.3, 'DF');

        $this->SetXY($x + 4, $y + 3.5);
        $this->SetFont('Arial', 'B', 9.2);
        $this->SetTextColor(23, 37, 84);
        $this->Cell(172, 4.5, expediente_pdf_bonito_texto('Observaciones administrativas'), 0, 1, 'L');

        $this->SetXY($x + 4, $y + 10);
        $this->SetFont('Arial', '', 8.1);
        $this->SetTextColor(100, 116, 139);
        $this->MultiCell(172, 4.4, $textoPdf, 0, 'L');

        $this->SetY($y + $alto);
    }

    public function huellaIntegridad(string $hash): void
    {
        if ($hash === '') {
            return;
        }

        $this->asegurarEspacio(12);
        $this->Ln(5);
        $this->SetFont('Arial', '', 6.2);
        $this->SetTextColor(148, 163, 184);
        $this->MultiCell(180, 3.4, expediente_pdf_bonito_texto('Huella de integridad: ' . $hash), 0, 'L');
    }

    public function RoundedRect(float $x, float $y, float $w, float $h, float $r, string $style = ''): void
    {
        $k = $this->k;
        $hp = $this->h;

        if ($style === 'F') {
            $op = 'f';
        } elseif ($style === 'FD' || $style === 'DF') {
            $op = 'B';
        } else {
            $op = 'S';
        }

        $myArc = 4 / 3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r;
        $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));
        $this->_arc($xc + $r * $myArc, $yc - $r, $xc + $r, $yc - $r * $myArc, $xc + $r, $yc);
        $xc = $x + $w - $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->_arc($xc + $r, $yc + $r * $myArc, $xc + $r * $myArc, $yc + $r, $xc, $yc + $r);
        $xc = $x + $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->_arc($xc - $r * $myArc, $yc + $r, $xc - $r, $yc + $r * $myArc, $xc - $r, $yc);
        $xc = $x + $r;
        $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));
        $this->_arc($xc - $r, $yc - $r * $myArc, $xc - $r * $myArc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }

    public function Circle(float $x, float $y, float $r, string $style = ''): void
    {
        $this->Ellipse($x, $y, $r, $r, $style);
    }

    public function Ellipse(float $x, float $y, float $rx, float $ry, string $style = ''): void
    {
        if ($style === 'F') {
            $op = 'f';
        } elseif ($style === 'FD' || $style === 'DF') {
            $op = 'B';
        } else {
            $op = 'S';
        }

        $lx = 4 / 3 * (sqrt(2) - 1) * $rx;
        $ly = 4 / 3 * (sqrt(2) - 1) * $ry;
        $k = $this->k;
        $h = $this->h;

        $this->_out(sprintf('%.2F %.2F m', ($x + $rx) * $k, ($h - $y) * $k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x + $rx) * $k,
            ($h - ($y - $ly)) * $k,
            ($x + $lx) * $k,
            ($h - ($y - $ry)) * $k,
            $x * $k,
            ($h - ($y - $ry)) * $k
        ));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x - $lx) * $k,
            ($h - ($y - $ry)) * $k,
            ($x - $rx) * $k,
            ($h - ($y - $ly)) * $k,
            ($x - $rx) * $k,
            ($h - $y) * $k
        ));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x - $rx) * $k,
            ($h - ($y + $ly)) * $k,
            ($x - $lx) * $k,
            ($h - ($y + $ry)) * $k,
            $x * $k,
            ($h - ($y + $ry)) * $k
        ));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x + $lx) * $k,
            ($h - ($y + $ry)) * $k,
            ($x + $rx) * $k,
            ($h - ($y + $ly)) * $k,
            ($x + $rx) * $k,
            ($h - $y) * $k
        ));
        $this->_out($op);
    }

    private function _arc(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): void
    {
        $h = $this->h;
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1 * $this->k,
            ($h - $y1) * $this->k,
            $x2 * $this->k,
            ($h - $y2) * $this->k,
            $x3 * $this->k,
            ($h - $y3) * $this->k
        ));
    }

    private function numeroLineas(float $ancho, string $texto): int
    {
        $cw = $this->CurrentFont['cw'];
        if ($ancho === 0.0) {
            $ancho = $this->w - $this->rMargin - $this->x;
        }

        $wmax = ($ancho - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $texto);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] === "\n") {
            $nb--;
        }

        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;

        while ($i < $nb) {
            $c = $s[$i];
            if ($c === "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }

            if ($c === ' ') {
                $sep = $i;
            }

            $l += $cw[$c] ?? 500;
            if ($l > $wmax) {
                if ($sep === -1) {
                    if ($i === $j) {
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }

                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else {
                $i++;
            }
        }

        return $nl;
    }
}


/**
 * @return array{expediente:array<string,mixed>,respuestas:array<int,array<string,mixed>>,logo:string}
 */
function expediente_pdf_bonito_obtener_datos(mysqli $conn, int $expedienteId): array
{
    if ($expedienteId <= 0) {
        throw new InvalidArgumentException('El expediente no es válido.');
    }

    $stmt = $conn->prepare(
        "SELECT
            e.*,
            c.nombre,
            c.apellido,
            c.telefono,
            c.email,
            c.contacto_emergencia_nombre,
            c.contacto_emergencia_telefono,
            s.nombre AS sucursal_nombre,
            u.nombre AS administrador_nombre,
            g.nombre AS gimnasio_nombre,
            g.logo AS gimnasio_logo,
            g.telefono AS gimnasio_telefono,
            g.email AS gimnasio_email,
            g.direccion AS gimnasio_direccion
         FROM expedientes_salud e
         INNER JOIN clientes c ON c.id = e.cliente_id
         INNER JOIN sucursales s ON s.id = e.sucursal_id
         INNER JOIN usuarios u ON u.id = e.aplicado_por
         LEFT JOIN configuracion_gimnasio g ON g.id = 1
         WHERE e.id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        throw new RuntimeException('No fue posible preparar el expediente.');
    }

    $stmt->bind_param('i', $expedienteId);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $expediente = $resultado ? $resultado->fetch_assoc() : null;
    $stmt->close();

    if (!is_array($expediente)) {
        throw new RuntimeException('El expediente solicitado no existe.');
    }

    $respuestas = [];
    $stmt = $conn->prepare(
        "SELECT *
         FROM expedientes_salud_respuestas
         WHERE expediente_id = ?
         ORDER BY orden_snapshot ASC, id ASC"
    );

    if (!$stmt) {
        throw new RuntimeException('No fue posible preparar las respuestas del expediente.');
    }

    $stmt->bind_param('i', $expedienteId);
    $stmt->execute();
    $resultado = $stmt->get_result();
    while ($resultado && $fila = $resultado->fetch_assoc()) {
        $respuestas[] = $fila;
    }
    $stmt->close();

    $logoAbsoluto = '';
    $logoRelativo = trim((string) ($expediente['gimnasio_logo'] ?? ''));
    if ($logoRelativo !== '') {
        $candidato = dirname(__DIR__) . DIRECTORY_SEPARATOR . ltrim($logoRelativo, '/\\');
        if (is_file($candidato)) {
            $extension = strtolower((string) pathinfo($candidato, PATHINFO_EXTENSION));
            if (in_array($extension, ['png', 'jpg', 'jpeg'], true)) {
                $logoAbsoluto = $candidato;
            }
        }
    }

    return [
        'expediente' => $expediente,
        'respuestas' => $respuestas,
        'logo' => $logoAbsoluto,
    ];
}

/**
 * @param array<string,mixed> $expediente
 * @param array<int,array<string,mixed>> $respuestas
 */
function expediente_pdf_bonito_construir(
    array $expediente,
    array $respuestas,
    string $logoAbsoluto = ''
): ExpedienteSaludBonitoPDF {
    $pdf = new ExpedienteSaludBonitoPDF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->configurarEncabezado($expediente, $logoAbsoluto);
    $pdf->AddPage();

    $pdf->tituloDocumento(
        (string) ($expediente['cuestionario_nombre'] ?? 'Cuestionario médico')
    );

    $inicioMeta = $pdf->GetY();
    $columnas = [15.0, 76.0, 137.0];
    $filasMeta = [
        [
            ['Socio', trim((string) ($expediente['nombre'] ?? '') . ' ' . (string) ($expediente['apellido'] ?? ''))],
            ['Teléfono', trim((string) ($expediente['telefono'] ?? '')) !== '' ? (string) $expediente['telefono'] : 'No registrado'],
            ['Correo', trim((string) ($expediente['email'] ?? '')) !== '' ? (string) $expediente['email'] : 'No registrado'],
        ],
        [
            ['Sucursal', (string) ($expediente['sucursal_nombre'] ?? 'Sin sucursal')],
            ['Fecha de aplicación', expediente_formatear_fecha((string) ($expediente['fecha_aplicacion'] ?? ''), true)],
            ['Vigente hasta', expediente_formatear_fecha((string) ($expediente['vigente_hasta'] ?? ''))],
        ],
        [
            ['Administrador', (string) ($expediente['administrador_nombre'] ?? 'No registrado')],
            ['Seguimiento', expediente_estado_etiqueta((string) ($expediente['estado_seguimiento'] ?? 'sin_observaciones'))],
            ['Respuestas para revisión', (string) ((int) ($expediente['total_alertas'] ?? 0))],
        ],
    ];

    foreach ($filasMeta as $indiceFila => $fila) {
        $y = $inicioMeta + ($indiceFila * 20.0);
        foreach ($fila as $indiceColumna => $dato) {
            $pdf->tarjetaMeta(
                (string) $dato[0],
                (string) $dato[1],
                $columnas[$indiceColumna],
                $y
            );
        }
    }
    $pdf->SetY($inicioMeta + 61.0);

    $seccionActual = '';
    foreach ($respuestas as $respuesta) {
        $seccion = trim((string) ($respuesta['seccion_snapshot'] ?? 'Cuestionario'));
        if ($seccion === '') {
            $seccion = 'Cuestionario';
        }

        if ($seccion !== $seccionActual) {
            $seccionActual = $seccion;
            $pdf->tituloSeccion($seccionActual);
        }

        $pdf->filaPregunta(
            (string) ($respuesta['pregunta_snapshot'] ?? ''),
            expediente_pdf_bonito_respuesta($respuesta),
            (int) ($respuesta['genera_alerta'] ?? 0) === 1
        );
    }

    $pdf->bloqueDocumento(
        (string) ($expediente['documento_titulo_snapshot'] ?? 'Documento de responsabilidad'),
        (string) ($expediente['documento_texto_snapshot'] ?? '')
    );

    $pdf->bloqueAceptacion($expediente);
    $pdf->bloqueObservaciones(
        trim((string) ($expediente['observaciones_admin'] ?? ''))
    );
    $pdf->huellaIntegridad(
        trim((string) ($expediente['hash_integridad'] ?? ''))
    );

    return $pdf;
}

/**
 * Mantiene la interfaz que ya utiliza cuestionario_salud.php y el correo.
 *
 * @return array{contenido:string,nombre:string,expediente:array<string,mixed>,respuestas:array<int,array<string,mixed>>}
 */
function expediente_generar_pdf_memoria(mysqli $conn, int $expedienteId): array
{
    $datos = expediente_pdf_bonito_obtener_datos($conn, $expedienteId);
    $pdf = expediente_pdf_bonito_construir(
        $datos['expediente'],
        $datos['respuestas'],
        $datos['logo']
    );

    $contenido = $pdf->Output('S');
    if (!is_string($contenido) || substr($contenido, 0, 4) !== '%PDF') {
        throw new RuntimeException('FPDF no devolvió un documento válido.');
    }

    return [
        'contenido' => $contenido,
        'nombre' => expediente_pdf_bonito_nombre_archivo($datos['expediente']),
        'expediente' => $datos['expediente'],
        'respuestas' => $datos['respuestas'],
    ];
}