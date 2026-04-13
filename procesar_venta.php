<?php
// Archivo: procesar_venta.php
// Procesar venta de productos

session_start();
header('Content-Type: application/json');

// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

// Verificar rol
if ($_SESSION['user_rol'] !== 'admin' && $_SESSION['user_rol'] !== 'recepcionista') {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

require_once 'config/database.php';
require_once 'fpdf/fpdf.php';

$database = new Database();
$conn = $database->getConnection();

// Obtener datos
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['items']) || empty($data['items'])) {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
    exit();
}

$cliente_id = !empty($data['cliente_id']) ? (int)$data['cliente_id'] : null;
$items = $data['items'];
$total = (float)$data['total'];
$metodo_pago = $data['metodo_pago'];
$usuario_id = (int)$_SESSION['user_id'];
$monto_recibido = isset($data['monto_recibido']) ? (float)$data['monto_recibido'] : null;
$cambio = $monto_recibido ? $monto_recibido - $total : null;

// Validar método de pago
$metodos_validos = ['efectivo', 'tarjeta', 'transferencia'];
if (!in_array($metodo_pago, $metodos_validos)) {
    echo json_encode(['success' => false, 'message' => 'Método de pago inválido']);
    exit();
}

try {
    // Iniciar transacción
    $conn->begin_transaction();
    
    // 1. Insertar venta
    $query_venta = "INSERT INTO ventas (cliente_id, usuario_id, fecha_venta, total, metodo_pago, estado) 
                    VALUES (?, ?, NOW(), ?, ?, 'completada')";
    $stmt_venta = $conn->prepare($query_venta);
    $stmt_venta->bind_param("iids", $cliente_id, $usuario_id, $total, $metodo_pago);
    
    if (!$stmt_venta->execute()) {
        throw new Exception("Error al insertar venta: " . $stmt_venta->error);
    }
    
    $venta_id = $conn->insert_id;
    
    if (!$venta_id) {
        throw new Exception("No se pudo obtener el ID de la venta");
    }
    
    // 2. Insertar detalles y actualizar stock
    foreach ($items as $item) {
        $producto_id = (int)$item['id'];
        $cantidad = (int)$item['cantidad'];
        $precio_unitario = (float)$item['precio'];
        $subtotal = $precio_unitario * $cantidad;
        
        // Verificar stock actual
        $query_check_stock = "SELECT stock FROM productos WHERE id = ? FOR UPDATE";
        $stmt_check = $conn->prepare($query_check_stock);
        $stmt_check->bind_param("i", $producto_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        $producto_actual = $result_check->fetch_assoc();
        
        if (!$producto_actual || $producto_actual['stock'] < $cantidad) {
            throw new Exception("Stock insuficiente para el producto ID: $producto_id");
        }
        
        // Insertar detalle
        $query_detalle = "INSERT INTO detalle_ventas (venta_id, producto_id, cantidad, precio_unitario, subtotal) 
                          VALUES (?, ?, ?, ?, ?)";
        $stmt_detalle = $conn->prepare($query_detalle);
        $stmt_detalle->bind_param("iiidd", $venta_id, $producto_id, $cantidad, $precio_unitario, $subtotal);
        
        if (!$stmt_detalle->execute()) {
            throw new Exception("Error al insertar detalle: " . $stmt_detalle->error);
        }
        
        // Actualizar stock
        $query_update_stock = "UPDATE productos SET stock = stock - ? WHERE id = ?";
        $stmt_update = $conn->prepare($query_update_stock);
        $stmt_update->bind_param("ii", $cantidad, $producto_id);
        
        if (!$stmt_update->execute()) {
            throw new Exception("Error al actualizar stock: " . $stmt_update->error);
        }
    }
    
    // 3. Generar y guardar ticket PDF automáticamente
    $ticket_info = generarTicketPDF($conn, $venta_id, $items, $total, $metodo_pago, $monto_recibido, $cambio, $cliente_id);
    
    // Confirmar transacción
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'venta_id' => $venta_id,
        'message' => 'Venta procesada correctamente',
        'ticket_url' => $ticket_info['url'],
        'ticket_file' => $ticket_info['file']
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Función para generar PDF del ticket estilo térmico
function generarTicketPDF($conn, $venta_id, $items, $total, $metodo_pago, $monto_recibido, $cambio, $cliente_id) {
    try {
        // Obtener configuración del gimnasio
        $query_config = "SELECT nombre, logo, telefono, email, direccion FROM configuracion_gimnasio WHERE id = 1";
        $result_config = $conn->query($query_config);
        $config = $result_config->fetch_assoc();
        
        $gym_nombre = $config['nombre'] ?? 'EGO GYM';
        $gym_logo = $config['logo'] ?? '';
        $gym_telefono = $config['telefono'] ?? '';
        $gym_email = $config['email'] ?? '';
        $gym_direccion = $config['direccion'] ?? '';
        
        // Obtener nombre del cliente
        $cliente_nombre = '';
        if ($cliente_id) {
            $query_cliente = "SELECT CONCAT(nombre, ' ', apellido) as nombre FROM clientes WHERE id = ?";
            $stmt_cliente = $conn->prepare($query_cliente);
            $stmt_cliente->bind_param("i", $cliente_id);
            $stmt_cliente->execute();
            $result_cliente = $stmt_cliente->get_result();
            $cliente = $result_cliente->fetch_assoc();
            $cliente_nombre = $cliente['nombre'] ?? '';
        }
        
        // Crear PDF tamaño ticket térmico (80mm x auto)
        class PDF_Ticket extends FPDF
        {
            function __construct()
            {
                parent::__construct('P', 'mm', array(80, 300)); // 80mm de ancho, altura automática
            }
            
            function Header()
            {
                // Espacio para que no se superponga
                $this->SetY(5);
            }
            
            function Footer()
            {
                $this->SetY(-15);
                $this->SetFont('Courier', 'I', 8);
                $this->Cell(0, 5, utf8_decode('Gracias por su compra'), 0, 1, 'C');
                $this->Cell(0, 4, utf8_decode('Este ticket es su comprobante de pago'), 0, 1, 'C');
            }
        }
        
        $pdf = new PDF_Ticket();
        $pdf->AddPage();
        $pdf->SetFont('Courier', '', 10);
        
        // Logo (si existe)
        if (!empty($gym_logo) && file_exists($gym_logo)) {
            $pdf->Image($gym_logo, 25, 8, 30);
            $pdf->Ln(35);
        } else {
            $pdf->Ln(10);
        }
        
        // Nombre del gimnasio
        $pdf->SetFont('Courier', 'B', 14);
        $pdf->Cell(0, 6, utf8_decode($gym_nombre), 0, 1, 'C');
        $pdf->Ln(3);
        
        // Información del ticket
        $pdf->SetFont('Courier', '', 9);
        $pdf->Cell(0, 5, 'Ticket de Venta #' . $venta_id, 0, 1, 'C');
        $pdf->Cell(0, 5, date('d/m/Y, H:i:s'), 0, 1, 'C');
        
        // Datos del cliente (si existe)
        if ($cliente_nombre) {
            $pdf->Ln(2);
            $pdf->SetFont('Courier', 'B', 9);
            $pdf->Cell(0, 5, 'Cliente: ' . utf8_decode($cliente_nombre), 0, 1, 'L');
        }
        
        $pdf->Ln(3);
        
        // Línea separadora
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
        $pdf->Ln(3);
        
        // Productos
        $pdf->SetFont('Courier', '', 10);
        foreach ($items as $item) {
            $nombre = utf8_decode($item['nombre']);
            $cantidad = $item['cantidad'];
            $precio = $item['precio'];
            $subtotal = $cantidad * $precio;
            
            // Nombre del producto
            $pdf->SetFont('Courier', 'B', 10);
            $pdf->Cell(0, 5, $nombre . ' x' . $cantidad, 0, 1, 'L');
            
            // Precio alineado a la derecha
            $pdf->SetFont('Courier', '', 10);
            $pdf->Cell(65, 5, '', 0, 0);
            $pdf->Cell(0, 5, '$' . number_format($subtotal, 2), 0, 1, 'R');
            $pdf->Ln(2);
        }
        
        // Línea separadora
        $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
        $pdf->Ln(3);
        
        // Totales
        $pdf->SetFont('Courier', 'B', 11);
        $pdf->Cell(50, 7, 'TOTAL', 0, 0, 'L');
        $pdf->Cell(0, 7, '$' . number_format($total, 2), 0, 1, 'R');
        
        $pdf->SetFont('Courier', '', 10);
        $pdf->Cell(50, 6, 'Metodo:', 0, 0, 'L');
        $pdf->Cell(0, 6, ucfirst(utf8_decode($metodo_pago)), 0, 1, 'R');
        
        if ($metodo_pago == 'efectivo' && $monto_recibido) {
            $pdf->Cell(50, 6, 'Recibido:', 0, 0, 'L');
            $pdf->Cell(0, 6, '$' . number_format($monto_recibido, 2), 0, 1, 'R');
            $pdf->Cell(50, 6, 'Cambio:', 0, 0, 'L');
            $pdf->Cell(0, 6, '$' . number_format($cambio, 2), 0, 1, 'R');
        }
        
        $pdf->Ln(5);
        
        // Línea final
        $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
        
        // Crear directorio si no existe
        $ticket_dir = __DIR__ . '/uploads/tickets/';
        if (!file_exists($ticket_dir)) {
            mkdir($ticket_dir, 0777, true);
        }
        
        // Generar nombre de archivo
        $filename = 'ticket_' . $venta_id . '_' . date('Ymd_His') . '.pdf';
        $filepath = $ticket_dir . $filename;
        
        // Guardar PDF
        $pdf->Output('F', $filepath);
        
        // También guardar en la base de datos como BLOB (opcional)
        $pdf_content = file_get_contents($filepath);
        $query_ticket = "INSERT INTO tickets_venta (venta_id, cliente_id, cliente_nombre, total, metodo_pago, monto_recibido, cambio, ticket_pdf, ticket_nombre, fecha_venta) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt_ticket = $conn->prepare($query_ticket);
        $stmt_ticket->bind_param("iisdsdsss", $venta_id, $cliente_id, $cliente_nombre, $total, $metodo_pago, $monto_recibido, $cambio, $pdf_content, $filename);
        $stmt_ticket->execute();
        
        return [
            'url' => 'uploads/tickets/' . $filename,
            'file' => $filename,
            'path' => $filepath
        ];
        
    } catch (Exception $e) {
        error_log("Error generando PDF: " . $e->getMessage());
        return null;
    }
}
?>