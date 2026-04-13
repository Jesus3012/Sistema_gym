<?php
// Forzar zona horaria de México
date_default_timezone_set('America/Mexico_City');

// includes/fpdf_ticket.php
require_once __DIR__ . '/../fpdf/fpdf.php';

// Funcion para obtener configuracion del gimnasio
function getConfigGimnasioPDF() {
    global $conn;
    
    $query = "SELECT nombre, direccion, horario, logo FROM configuracion_gimnasio WHERE id = 1";
    $result = $conn->query($query);
    
    if ($result && $row = $result->fetch_assoc()) {
        return $row;
    }
    
    return [
        'nombre' => 'Gimnasio',
        'direccion' => '',
        'horario' => 'Lun-Vie 6am-10pm, Sab 8am-6pm',
        'logo' => ''
    ];
}

class PDF_Ticket extends FPDF
{
    var $header_printed = false;
    
    function __construct() {
        parent::__construct('P', 'mm', array(80, 120));
        $this->SetMargins(5, 5, 5);
        $this->SetAutoPageBreak(true, 5);
    }

    function Header()
    {
        // Solo imprimir el header en la primera página
        if (!$this->header_printed) {
            $config = getConfigGimnasioPDF();
            
            // Logo (si existe)
            if (!empty($config['logo']) && file_exists($config['logo'])) {
                $this->Image($config['logo'], 27, 3, 25);
                $this->SetY(30);
            } else {
                $this->SetY(5);
            }
            
            // Nombre del gimnasio
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(0, 4, $this->iconvUtf8($config['nombre']), 0, 1, 'C');
            
            // Direccion
            if (!empty($config['direccion'])) {
                $this->SetFont('Arial', '', 7);
                $this->Cell(0, 3, $this->iconvUtf8($config['direccion']), 0, 1, 'C');
            }
            
            // Horario
            if (!empty($config['horario'])) {
                $this->SetFont('Arial', '', 6);
                $this->Cell(0, 3, 'Horario: ' . $this->iconvUtf8($config['horario']), 0, 1, 'C');
            }
            
            // Fecha con hora corregida (restando 1 hora por problema del servidor)
            $timestamp = time() - 3600; // Resta 1 hora (3600 segundos)
            $fecha_formateada = date('d/m/Y H:i:s', $timestamp);
            
            $this->SetFont('Arial', '', 6);
            $this->Cell(0, 3, $fecha_formateada, 0, 1, 'C');
            
            // Linea separadora
            $this->Ln(2);
            $this->SetDrawColor(0, 0, 0);
            $this->SetLineWidth(0.3);
            $this->Line(5, $this->GetY(), 75, $this->GetY());
            $this->Ln(3);
            
            $this->header_printed = true;
        } else {
            // En páginas adicionales, solo una línea de separación
            $this->SetY(5);
            $this->SetDrawColor(0, 0, 0);
            $this->SetLineWidth(0.3);
            $this->Line(5, $this->GetY(), 75, $this->GetY());
            $this->Ln(3);
        }
    }
    
    function iconvUtf8($texto) {
        return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $texto);
    }
    
    function TicketTitle($tipo)
    {
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(0, 5, ($tipo == 'inscripcion') ? 'TICKET DE INSCRIPCION' : 'TICKET DE RENOVACION', 0, 1, 'C');
        $this->Ln(1);
    }
    
    function InfoCard($cliente_nombre, $plan_nombre, $fecha_inicio, $fecha_fin, $metodo_pago, $referencia)
    {
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(0, 4, 'DATOS DEL CLIENTE', 0, 1, 'L');
        
        $this->SetFont('Arial', '', 7);
        $this->Cell(28, 4, 'Cliente:', 0, 0);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell(0, 4, $this->iconvUtf8($cliente_nombre), 0, 1);
        
        $this->Ln(1);
        
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(0, 4, 'DETALLE DEL PLAN', 0, 1, 'L');
        
        $this->SetFont('Arial', '', 7);
        $this->Cell(38, 4, 'Plan:', 0, 0);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell(0, 4, $this->iconvUtf8($plan_nombre), 0, 1);
        
        $this->SetFont('Arial', '', 7);
        $this->Cell(38, 4, 'Fecha inicio:', 0, 0);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell(0, 4, date('d/m/Y', strtotime($fecha_inicio)), 0, 1);
        
        $this->SetFont('Arial', '', 7);
        $this->Cell(38, 4, 'Fecha fin:', 0, 0);
        $this->SetFont('Arial', 'B', 7);
        $vencimiento = $fecha_fin ? date('d/m/Y', strtotime($fecha_fin)) : 'Sin vencimiento';
        $this->Cell(0, 4, $this->iconvUtf8($vencimiento), 0, 1);
        
        $this->SetFont('Arial', '', 7);
        $this->Cell(38, 4, 'Metodo pago:', 0, 0);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell(0, 4, ucfirst($metodo_pago), 0, 1);
        
        if ($referencia) {
            $this->SetFont('Arial', '', 7);
            $this->Cell(38, 4, 'Referencia:', 0, 0);
            $this->SetFont('Arial', 'B', 7);
            $this->Cell(0, 4, $referencia, 0, 1);
        }
        
        $this->Ln(1);
    }
    
    function TotalBox($monto, $estado)
    {
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.3);
        $this->Line(5, $this->GetY(), 75, $this->GetY());
        $this->Ln(2);
        
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(0, 5, 'TOTAL PAGADO', 0, 1, 'C');
        
        $this->SetFont('Arial', 'B', 13);
        $this->Cell(0, 6, '$ ' . number_format($monto, 2), 0, 1, 'C');
        
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(16, 185, 129);
        $this->Cell(0, 4, $estado, 0, 1, 'C');
        
        $this->SetTextColor(0, 0, 0);
        $this->Ln(2);
        
        $this->SetDrawColor(0, 0, 0);
        $this->Line(5, $this->GetY(), 75, $this->GetY());
        $this->Ln(2);
    }
    
    function NoticeBox()
    {
        $this->SetFont('Arial', 'B', 7);
        $this->Cell(0, 4, 'INFORMACION IMPORTANTE', 0, 1, 'C');
        
        $this->SetFont('Arial', '', 6);
        $this->MultiCell(0, 3, 'Este comprobante es un documento personal e intransferible.', 0, 'C');
        $this->Ln(1);
    }
    
    function TicketNumber()
    {
        $this->SetFont('Arial', 'I', 5);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 3, 'TKT-' . date('Ymd') . '-' . rand(1000, 9999), 0, 1, 'C');
    }
}

function generarPDFTicket($nombre_cliente, $plan_nombre, $fecha_inicio, $fecha_fin, $monto, $metodo_pago, $referencia, $tipo)
{
    $pdf = new PDF_Ticket();
    $pdf->AddPage();
    
    $pdf->TicketTitle($tipo);
    $pdf->InfoCard($nombre_cliente, $plan_nombre, $fecha_inicio, $fecha_fin, $metodo_pago, $referencia);
    $estado = ($tipo == 'inscripcion') ? 'PAGADO' : 'RENOVADO';
    $pdf->TotalBox($monto, $estado);
    $pdf->NoticeBox();
    $pdf->TicketNumber();
    
    return $pdf->Output('S');
}
?>