<?php
// Archivo: generar_ticket_pdf.php
// Generar ticket en PDF usando FPDF

require_once 'fpdf/fpdf.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    die('No autorizado');
}

require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

// Obtener datos de la venta
$venta_id = isset($_GET['venta_id']) ? (int)$_GET['venta_id'] : 0;

if (!$venta_id) {
    die('ID de venta no válido');
}

// Obtener información de la venta
$query = "SELECT v.*, u.nombre as usuario_nombre, 
          CONCAT(c.nombre, ' ', c.apellido) as cliente_nombre_completo,
          c.telefono as cliente_telefono,
          c.email as cliente_email
          FROM ventas v
          LEFT JOIN usuarios u ON v.usuario_id = u.id
          LEFT JOIN clientes c ON v.cliente_id = c.id
          WHERE v.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $venta_id);
$stmt->execute();
$venta = $stmt->get_result()->fetch_assoc();

if (!$venta) {
    die('Venta no encontrada');
}

// Obtener detalles de la venta
$query_detalles = "SELECT dv.*, p.nombre as producto_nombre, p.foto 
                   FROM detalle_ventas dv
                   LEFT JOIN productos p ON dv.producto_id = p.id
                   WHERE dv.venta_id = ?";
$stmt = $conn->prepare($query_detalles);
$stmt->bind_param("i", $venta_id);
$stmt->execute();
$detalles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Obtener configuración del gimnasio
$query_config = "SELECT nombre, logo, telefono, email, direccion FROM configuracion_gimnasio WHERE id = 1";
$result_config = $conn->query($query_config);
$config = $result_config->fetch_assoc();
$gym_nombre = $config['nombre'] ?? 'Gimnasio';
$gym_logo = $config['logo'] ?? '';
$gym_telefono = $config['telefono'] ?? '';
$gym_email = $config['email'] ?? '';
$gym_direccion = $config['direccion'] ?? '';

// Crear PDF
class PDF_Ticket extends FPDF
{
    function Header()
    {
        // Logo
        global $gym_logo, $gym_nombre, $gym_direccion, $gym_telefono, $gym_email;
        
        if (!empty($gym_logo) && file_exists($gym_logo)) {
            $this->Image($gym_logo, 80, 10, 30);
        }
        
        $this->SetY(45);
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 8, utf8_decode($gym_nombre), 0, 1, 'C');
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 4, utf8_decode($gym_direccion), 0, 1, 'C');
        $this->Cell(0, 4, 'Tel: ' . utf8_decode($gym_telefono) . ' | Email: ' . utf8_decode($gym_email), 0, 1, 'C');
        $this->Ln(5);
    }
    
    function Footer()
    {
        $this->SetY(-20);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 5, utf8_decode('¡Gracias por su compra!'), 0, 1, 'C');
        $this->Cell(0, 4, 'Este ticket es su comprobante de pago', 0, 1, 'C');
        $this->Cell(0, 4, 'Pagina ' . $this->PageNo(), 0, 0, 'C');
    }
    
    function TicketTitle($venta_id, $fecha, $usuario, $cliente_nombre = null, $cliente_telefono = null)
    {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, 'TICKET DE VENTA', 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 6, 'N°: ' . str_pad($venta_id, 8, '0', STR_PAD_LEFT), 0, 1, 'C');
        $this->Cell(0, 6, 'Fecha: ' . date('d/m/Y H:i:s', strtotime($fecha)), 0, 1, 'C');
        $this->Cell(0, 6, 'Cajero: ' . utf8_decode($usuario), 0, 1, 'C');
        
        if ($cliente_nombre) {
            $this->Ln(2);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(0, 6, 'DATOS DEL CLIENTE', 0, 1, 'C');
            $this->SetFont('Arial', '', 10);
            $this->Cell(0, 5, 'Nombre: ' . utf8_decode($cliente_nombre), 0, 1, 'L');
            if ($cliente_telefono) {
                $this->Cell(0, 5, 'Telefono: ' . utf8_decode($cliente_telefono), 0, 1, 'L');
            }
        }
        
        $this->Ln(5);
    }
    
    function ProductsTable($detalles)
    {
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(230, 230, 230);
        $this->Cell(25, 8, 'Cantidad', 1, 0, 'C', true);
        $this->Cell(85, 8, utf8_decode('Producto'), 1, 0, 'C', true);
        $this->Cell(35, 8, 'Precio', 1, 0, 'C', true);
        $this->Cell(40, 8, 'Subtotal', 1, 1, 'C', true);
        
        $this->SetFont('Arial', '', 9);
        foreach ($detalles as $detalle) {
            $this->Cell(25, 7, $detalle['cantidad'], 1, 0, 'C');
            $this->Cell(85, 7, utf8_decode(substr($detalle['producto_nombre'], 0, 35)), 1, 0, 'L');
            $this->Cell(35, 7, '$' . number_format($detalle['precio_unitario'], 2), 1, 0, 'R');
            $this->Cell(40, 7, '$' . number_format($detalle['subtotal'], 2), 1, 1, 'R');
        }
    }
    
    function TotalSection($total, $metodo_pago, $monto_recibido = null, $cambio = null)
    {
        $this->Ln(5);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(145, 8, 'TOTAL:', 0, 0, 'R');
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(40, 8, '$' . number_format($total, 2), 0, 1, 'R');
        
        $this->SetFont('Arial', '', 10);
        $this->Cell(145, 6, 'Metodo de pago:', 0, 0, 'R');
        $this->Cell(40, 6, ucfirst(utf8_decode($metodo_pago)), 0, 1, 'R');
        
        if ($monto_recibido && $metodo_pago == 'efectivo') {
            $this->Cell(145, 6, 'Recibido:', 0, 0, 'R');
            $this->Cell(40, 6, '$' . number_format($monto_recibido, 2), 0, 1, 'R');
            $this->Cell(145, 6, 'Cambio:', 0, 0, 'R');
            $this->Cell(40, 6, '$' . number_format($cambio, 2), 0, 1, 'R');
        }
    }
}

// Crear el PDF
$pdf = new PDF_Ticket('P', 'mm', 'A4');
$pdf->AddPage();
$pdf->TicketTitle(
    $venta_id,
    $venta['fecha_venta'],
    $venta['usuario_nombre'],
    $venta['cliente_nombre_completo'] ?? null,
    $venta['cliente_telefono'] ?? null
);
$pdf->ProductsTable($detalles);
$pdf->TotalSection(
    $venta['total'],
    $venta['metodo_pago'],
    $venta['metodo_pago'] == 'efectivo' ? $venta['total'] : null, // Aquí deberías pasar el monto recibido real
    $venta['metodo_pago'] == 'efectivo' ? 0 : null // Aquí el cambio real
);

// Salida del PDF
$pdf->Output('D', 'ticket_venta_' . $venta_id . '.pdf');
?>