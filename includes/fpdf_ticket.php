<?php
// includes/fpdf_ticket.php
require_once __DIR__ . '/../fpdf/fpdf.php';

class PDF_Ticket extends FPDF
{
    function Header()
    {
        // Fondo del header
        $this->SetFillColor(30, 58, 138);
        $this->Rect(0, 0, 210, 50, 'F');
        
        // Línea decorativa
        $this->SetDrawColor(255, 193, 7);
        $this->SetLineWidth(1.5);
        $this->Line(0, 50, 210, 50);
        
        // Título
        $this->SetY(15);
        $this->SetFont('Arial', 'B', 22);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 10, 'SISTEMA GIMNASIO', 0, 1, 'C');
        
        // Subtítulo
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 6, 'Comprobante Oficial de Pago', 0, 1, 'C');
        
        // Fecha
        $this->SetY(10);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(200, 200, 200);
        $this->Cell(0, 0, 'Fecha: ' . date('d/m/Y H:i:s'), 0, 0, 'R');
        
        $this->Ln(20);
    }
    
    function Footer()
    {
        $this->SetY(-30);
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.5);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        
        $this->SetY(-25);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 5, 'Pagina ' . $this->PageNo() . ' - Documento valido como comprobante', 0, 0, 'C');
        
        $this->SetY(-20);
        $this->SetFont('Arial', 'I', 7);
        $this->Cell(0, 5, 'Sistema Gimnasio - Todos los derechos reservados', 0, 0, 'C');
        
        $this->SetY(-15);
        $this->Cell(0, 5, 'Este documento es un comprobante de pago valido', 0, 0, 'C');
    }
    
    function TicketTitle($tipo)
    {
        $this->Ln(5);
        
        $y_start = $this->GetY();
        $this->SetFillColor(248, 249, 250);
        $this->Rect(10, $y_start, 190, 12, 'F');
        
        $this->SetDrawColor(30, 58, 138);
        $this->SetLineWidth(0.5);
        $this->Rect(10, $y_start, 190, 12, 'D');
        
        $this->SetY($y_start + 3);
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(30, 58, 138);
        $this->Cell(0, 6, strtoupper($tipo), 0, 1, 'C');
        
        $this->Ln(8);
    }
    
    function InfoCard($cliente_nombre, $plan_nombre, $fecha_inicio, $fecha_fin, $metodo_pago, $referencia)
    {
        $y_start = $this->GetY();
        
        // Fondo de la tarjeta
        $this->SetFillColor(248, 249, 250);
        $this->Rect(10, $y_start, 190, 85, 'F');
        
        // Título de la tarjeta
        $this->SetY($y_start + 6);
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(30, 58, 138);
        $this->Cell(0, 6, 'INFORMACION DE LA MEMBRESIA', 0, 1, 'C');
        
        // Línea separadora
        $this->SetDrawColor(30, 58, 138);
        $this->SetLineWidth(0.3);
        $this->Line(20, $this->GetY() + 2, 190, $this->GetY() + 2);
        
        $this->SetY($y_start + 20);
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(80, 80, 80);
        
        // Fila 1
        $this->SetX(20);
        $this->Cell(55, 9, 'Cliente:', 0, 0);
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 9, $cliente_nombre, 0, 1);
        
        // Fila 2
        $this->SetX(20);
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(55, 9, 'Plan contratado:', 0, 0);
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 9, $plan_nombre, 0, 1);
        
        // Fila 3
        $this->SetX(20);
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(55, 9, 'Fecha de inicio:', 0, 0);
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 9, date('d/m/Y', strtotime($fecha_inicio)), 0, 1);
        
        // Fila 4
        $this->SetX(20);
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(55, 9, 'Fecha de vencimiento:', 0, 0);
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(0, 0, 0);
        $vencimiento = $fecha_fin ? date('d/m/Y', strtotime($fecha_fin)) : 'Sin vencimiento';
        $this->Cell(0, 9, $vencimiento, 0, 1);
        
        // Fila 5
        $this->SetX(20);
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(55, 9, 'Metodo de pago:', 0, 0);
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 9, ucfirst($metodo_pago), 0, 1);
        
        // Fila 6 (opcional)
        if ($referencia) {
            $this->SetX(20);
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(80, 80, 80);
            $this->Cell(55, 9, 'Referencia:', 0, 0);
            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(0, 0, 0);
            $this->Cell(0, 9, $referencia, 0, 1);
        }
        
        $this->SetY($y_start + 90);
    }
    
    function TotalBox($monto, $estado)
    {
        $this->Ln(5);
        
        $y_start = $this->GetY();
        $box_height = 45;
        
        // Fondo
        $this->SetFillColor(30, 58, 138);
        $this->Rect(25, $y_start, 160, $box_height, 'F');
        
        // Borde dorado
        $this->SetDrawColor(255, 193, 7);
        $this->SetLineWidth(1);
        $this->Rect(25, $y_start, 160, $box_height, 'D');
        
        // Título
        $this->SetY($y_start + 8);
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 8, 'TOTAL PAGADO', 0, 1, 'C');
        
        // Monto
        $this->SetFont('Arial', 'B', 22);
        $this->SetY($y_start + 18);
        $this->Cell(0, 12, '$ ' . number_format($monto, 2), 0, 1, 'C');
        
        // Badge de estado
        $badge_color = ($estado == 'PAGADO') ? [16, 185, 129] : [30, 58, 138];
        $this->SetFillColor($badge_color[0], $badge_color[1], $badge_color[2]);
        $this->SetFont('Arial', 'B', 10);
        
        $badge_width = 60;
        $badge_x = (210 - $badge_width) / 2;
        $this->Rect($badge_x, $y_start + 33, $badge_width, 8, 'F');
        $this->SetXY($badge_x + 5, $y_start + 34);
        $this->Cell($badge_width - 10, 6, $estado, 0, 1, 'C');
        
        $this->SetTextColor(0, 0, 0);
        $this->SetY($y_start + $box_height + 8);
    }
    
    function NoticeBox()
    {
        $y_start = $this->GetY();
        
        $this->SetFillColor(255, 248, 225);
        $this->Rect(10, $y_start, 190, 32, 'F');
        
        $this->SetDrawColor(255, 193, 7);
        $this->SetLineWidth(0.5);
        $this->Rect(10, $y_start, 190, 32, 'D');
        
        $this->SetY($y_start + 6);
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(255, 140, 0);
        $this->Cell(0, 5, 'INFORMACION IMPORTANTE', 0, 1, 'C');
        
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(80, 80, 80);
        $this->SetY($y_start + 14);
        $this->MultiCell(0, 5, 'Presente este comprobante en la recepcion del gimnasio junto con su identificacion oficial. Este documento es personal e intransferible.', 0, 'C');
        
        $this->SetY($y_start + 38);
    }
    
    function TicketNumber($nombre_cliente)
    {
        $this->Ln(5);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(150, 150, 150);
        $ticket_num = 'TKT-' . date('Ymd') . '-' . rand(1000, 9999) . '-' . strtoupper(substr(md5($nombre_cliente), 0, 6));
        $this->Cell(0, 5, $ticket_num, 0, 1, 'C');
    }
}

function generarPDFTicket($nombre_cliente, $plan_nombre, $fecha_inicio, $fecha_fin, $monto, $metodo_pago, $referencia, $tipo)
{
    $pdf = new PDF_Ticket();
    $pdf->AddPage();
    
    // Título
    $titulo = ($tipo == 'inscripcion') ? 'TICKET DE INSCRIPCION' : 'TICKET DE RENOVACION';
    $pdf->TicketTitle($titulo);
    
    // Información
    $pdf->InfoCard($nombre_cliente, $plan_nombre, $fecha_inicio, $fecha_fin, $metodo_pago, $referencia);
    
    // Total
    $estado = ($tipo == 'inscripcion') ? 'PAGADO' : 'RENOVADO';
    $pdf->TotalBox($monto, $estado);
    
    // Notas
    $pdf->NoticeBox();
    
    // Número de ticket
    $pdf->TicketNumber($nombre_cliente);
    
    return $pdf->Output('S');
}
?>