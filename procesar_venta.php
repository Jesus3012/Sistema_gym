<?php
// Archivo: procesar_venta.php
// Procesar venta de productos con inventario multisucursal

declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/sucursal_context.php';
require_once __DIR__ . '/fpdf/fpdf.php';

function responderVenta(bool $success, string $message, array $extra = [], int $http = 200): void
{
    http_response_code($http);

    echo json_encode(
        array_merge([
            'success' => $success,
            'message' => $message
        ], $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

// Validar sesión
if (empty($_SESSION['user_id'])) {
    responderVenta(false, 'No autorizado', [], 401);
}

$rol = strtolower(trim((string) ($_SESSION['user_rol'] ?? '')));

if ($rol === 'administrador') {
    $rol = 'admin';
}

if (!in_array($rol, ['admin', 'recepcionista'], true)) {
    responderVenta(false, 'No autorizado', [], 403);
}

// No permitir ventas en "Todas las sucursales"
if (
    function_exists('sucursal_dashboard_vista_global')
    && sucursal_dashboard_vista_global()
) {
    responderVenta(
        false,
        'Selecciona una sucursal concreta antes de registrar la venta.',
        [],
        409
    );
}

$database = new Database();
$conn = $database->getConnection();

if (!$conn instanceof mysqli) {
    responderVenta(false, 'No fue posible conectar con la base de datos.', [], 500);
}

$conn->set_charset('utf8mb4');

$data = json_decode(file_get_contents('php://input'), true);

if (
    !is_array($data)
    || empty($data['items'])
    || !is_array($data['items'])
) {
    responderVenta(false, 'Datos inválidos.', [], 400);
}

$usuario_id = (int) $_SESSION['user_id'];
$sucursal_id = (int) ($_SESSION['sucursal_id'] ?? 0);
$cliente_id = !empty($data['cliente_id'])
    ? (int) $data['cliente_id']
    : 0;

$items = $data['items'];
$total_cliente = round((float) ($data['total'] ?? 0), 2);
$metodo_pago = strtolower(trim((string) ($data['metodo_pago'] ?? '')));
$monto_recibido = isset($data['monto_recibido'])
    ? round((float) $data['monto_recibido'], 2)
    : null;

if ($sucursal_id <= 0) {
    responderVenta(false, 'Selecciona una sucursal antes de vender.', [], 409);
}

if (!in_array($metodo_pago, ['efectivo', 'tarjeta', 'transferencia'], true)) {
    responderVenta(false, 'Método de pago inválido.', [], 400);
}

try {
    // Confirmar que la sucursal de la sesión sí exista
    $stmtSucursal = $conn->prepare(
        "SELECT id, nombre, clave, es_matriz, estado
         FROM sucursales
         WHERE id = ?
         LIMIT 1"
    );

    $stmtSucursal->bind_param('i', $sucursal_id);
    $stmtSucursal->execute();
    $sucursal = $stmtSucursal->get_result()->fetch_assoc();
    $stmtSucursal->close();

    if (
        !$sucursal
        || ($sucursal['estado'] ?? '') !== 'activa'
    ) {
        throw new RuntimeException(
            'La sucursal seleccionada no existe o está inactiva.'
        );
    }

    // Evita procesar una venta abierta antes de cambiar de sucursal
    $sucursal_enviada = (int) ($data['sucursal_id'] ?? 0);

    if (
        $sucursal_enviada > 0
        && $sucursal_enviada !== $sucursal_id
    ) {
        throw new RuntimeException(
            'La sucursal cambió mientras preparabas la venta. Recarga la página.'
        );
    }

    $conn->begin_transaction();

    /*
     * Primero se valida todo el carrito con precio y stock de la sede.
     * Nunca se utiliza item['precio'] para registrar la venta.
     */
    $stmtProducto = $conn->prepare(
        "SELECT
            inv.id AS inventario_id,
            inv.precio_venta,
            inv.stock,
            inv.estado AS inventario_estado,
            p.nombre,
            p.estado AS producto_estado
         FROM inventario_sucursales inv
         INNER JOIN productos p
            ON p.id = inv.producto_id
         WHERE inv.sucursal_id = ?
           AND inv.producto_id = ?
         LIMIT 1
         FOR UPDATE"
    );

    $items_validados = [];
    $total_bd = 0.0;

    foreach ($items as $item) {
        $producto_id = (int) ($item['id'] ?? 0);
        $cantidad = (int) ($item['cantidad'] ?? 0);

        if ($producto_id <= 0 || $cantidad <= 0) {
            throw new RuntimeException(
                'El carrito contiene un producto o cantidad inválida.'
            );
        }

        $stmtProducto->bind_param(
            'ii',
            $sucursal_id,
            $producto_id
        );

        $stmtProducto->execute();
        $producto = $stmtProducto->get_result()->fetch_assoc();

        if (
            !$producto
            || ($producto['producto_estado'] ?? '') !== 'activo'
            || ($producto['inventario_estado'] ?? '') !== 'activo'
        ) {
            throw new RuntimeException(
                "El producto ID {$producto_id} no está disponible en esta sucursal."
            );
        }

        $stock_actual = (int) $producto['stock'];

        if ($stock_actual < $cantidad) {
            throw new RuntimeException(
                'Stock insuficiente para ' .
                $producto['nombre'] .
                '. Disponibles: ' .
                $stock_actual .
                '.'
            );
        }

        $precio_unitario = round(
            (float) $producto['precio_venta'],
            2
        );

        $subtotal = round(
            $precio_unitario * $cantidad,
            2
        );

        $total_bd += $subtotal;

        $items_validados[] = [
            'inventario_id' => (int) $producto['inventario_id'],
            'id' => $producto_id,
            'nombre' => (string) $producto['nombre'],
            'cantidad' => $cantidad,
            'precio' => $precio_unitario,
            'subtotal' => $subtotal,
            'stock_actual' => $stock_actual
        ];
    }

    $stmtProducto->close();
    $total_bd = round($total_bd, 2);

    if (
        $total_bd <= 0
        || abs($total_bd - $total_cliente) > 0.01
    ) {
        throw new RuntimeException(
            'El precio o las existencias cambiaron. Actualiza el carrito.'
        );
    }

    if (
        $metodo_pago === 'efectivo'
        && (
            $monto_recibido === null
            || $monto_recibido < $total_bd
        )
    ) {
        throw new RuntimeException(
            'El monto recibido es menor al total de la venta.'
        );
    }

    $cambio = $metodo_pago === 'efectivo'
        ? round((float) $monto_recibido - $total_bd, 2)
        : null;

    // Insertar venta con la sucursal correcta
    $queryVenta = "
        INSERT INTO ventas (
            sucursal_id,
            cliente_id,
            usuario_id,
            fecha_venta,
            total,
            metodo_pago,
            estado
        ) VALUES (
            ?,
            NULLIF(?, 0),
            ?,
            NOW(),
            ?,
            ?,
            'completada'
        )
    ";

    $stmtVenta = $conn->prepare($queryVenta);

    $stmtVenta->bind_param(
        'iiids',
        $sucursal_id,
        $cliente_id,
        $usuario_id,
        $total_bd,
        $metodo_pago
    );

    $stmtVenta->execute();
    $venta_id = (int) $stmtVenta->insert_id;
    $stmtVenta->close();

    if ($venta_id <= 0) {
        throw new RuntimeException(
            'No se pudo obtener el ID de la venta.'
        );
    }

    $stmtDetalle = $conn->prepare(
        "INSERT INTO detalle_ventas (
            venta_id,
            producto_id,
            cantidad,
            precio_unitario,
            subtotal
         ) VALUES (?, ?, ?, ?, ?)"
    );

    $stmtStock = $conn->prepare(
        "UPDATE inventario_sucursales
         SET stock = stock - ?,
             updated_at = NOW()
         WHERE id = ?
           AND sucursal_id = ?
           AND stock >= ?"
    );

    foreach ($items_validados as $item) {
        $producto_id = (int) $item['id'];
        $cantidad = (int) $item['cantidad'];
        $precio = (float) $item['precio'];
        $subtotal = (float) $item['subtotal'];
        $inventario_id = (int) $item['inventario_id'];

        $stmtDetalle->bind_param(
            'iiidd',
            $venta_id,
            $producto_id,
            $cantidad,
            $precio,
            $subtotal
        );

        $stmtDetalle->execute();

        $stmtStock->bind_param(
            'iiii',
            $cantidad,
            $inventario_id,
            $sucursal_id,
            $cantidad
        );

        $stmtStock->execute();

        if ($stmtStock->affected_rows !== 1) {
            throw new RuntimeException(
                'El stock cambió mientras se procesaba la venta.'
            );
        }
    }

    $stmtDetalle->close();
    $stmtStock->close();

    // Generar ticket conservando tu formato anterior
    $ticket_info = generarTicketPDF(
        $conn,
        $venta_id,
        $items_validados,
        $total_bd,
        $metodo_pago,
        $monto_recibido,
        $cambio,
        $cliente_id,
        $sucursal
    );

    $conn->commit();

    responderVenta(
        true,
        'Venta procesada correctamente.',
        [
            'venta_id' => $venta_id,
            'sucursal_id' => $sucursal_id,
            'sucursal_nombre' => $sucursal['nombre'],
            'total' => $total_bd,
            'cambio' => $cambio,
            'ticket_url' => $ticket_info['url'] ?? null,
            'ticket_file' => $ticket_info['file'] ?? null
        ]
    );
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        // La transacción puede no haberse iniciado todavía.
    }

    error_log(
        '[Procesar venta] ' .
        $e->getMessage()
    );

    responderVenta(
        false,
        $e->getMessage(),
        [],
        409
    );
}

// Generar PDF del ticket estilo térmico
function generarTicketPDF(
    mysqli $conn,
    int $venta_id,
    array $items,
    float $total,
    string $metodo_pago,
    ?float $monto_recibido,
    ?float $cambio,
    int $cliente_id,
    array $sucursal
): ?array {
    try {
        $resultConfig = $conn->query(
            "SELECT nombre, logo, telefono, email, direccion
             FROM configuracion_gimnasio
             WHERE id = 1
             LIMIT 1"
        );

        $config = $resultConfig
            ? $resultConfig->fetch_assoc()
            : [];

        $gym_nombre = $config['nombre'] ?? 'EGO';
        $gym_logo = $config['logo'] ?? '';

        $cliente_nombre = '';

        if ($cliente_id > 0) {
            $stmtCliente = $conn->prepare(
                "SELECT CONCAT(nombre, ' ', apellido) AS nombre
                 FROM clientes
                 WHERE id = ?
                 LIMIT 1"
            );

            $stmtCliente->bind_param('i', $cliente_id);
            $stmtCliente->execute();

            $cliente = $stmtCliente
                ->get_result()
                ->fetch_assoc();

            $stmtCliente->close();

            $cliente_nombre = $cliente['nombre'] ?? '';
        }

        if (!class_exists('PDF_Ticket')) {
            class PDF_Ticket extends FPDF
            {
                public function __construct()
                {
                    parent::__construct(
                        'P',
                        'mm',
                        [80, 300]
                    );
                }

                public function Header()
                {
                    $this->SetY(5);
                }

                public function Footer()
                {
                    $this->SetY(-15);
                    $this->SetFont(
                        'Courier',
                        'I',
                        8
                    );

                    $this->Cell(
                        0,
                        5,
                        utf8_decode(
                            'Gracias por su compra'
                        ),
                        0,
                        1,
                        'C'
                    );
                }
            }
        }

        $pdf = new PDF_Ticket();
        $pdf->AddPage();

        if (
            $gym_logo !== ''
            && file_exists($gym_logo)
        ) {
            $pdf->Image($gym_logo, 25, 8, 30);
            $pdf->Ln(35);
        } else {
            $pdf->Ln(10);
        }

        $pdf->SetFont('Courier', 'B', 14);
        $pdf->Cell(
            0,
            6,
            utf8_decode((string) $gym_nombre),
            0,
            1,
            'C'
        );

        $pdf->SetFont('Courier', 'B', 9);
        $pdf->Cell(
            0,
            5,
            utf8_decode((string) $sucursal['nombre']),
            0,
            1,
            'C'
        );

        $pdf->SetFont('Courier', '', 9);
        $pdf->Cell(
            0,
            5,
            'Ticket de Venta #' . $venta_id,
            0,
            1,
            'C'
        );

        $pdf->Cell(
            0,
            5,
            date('d/m/Y H:i:s'),
            0,
            1,
            'C'
        );

        if ($cliente_nombre !== '') {
            $pdf->Ln(2);
            $pdf->SetFont('Courier', 'B', 9);
            $pdf->MultiCell(
                0,
                5,
                utf8_decode(
                    'Socio: ' . $cliente_nombre
                )
            );
        }

        $pdf->Ln(3);
        $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
        $pdf->Ln(3);

        foreach ($items as $item) {
            $pdf->SetFont('Courier', 'B', 9);

            $pdf->MultiCell(
                0,
                5,
                utf8_decode(
                    $item['nombre'] .
                    ' x' .
                    $item['cantidad']
                )
            );

            $pdf->SetFont('Courier', '', 9);

            $pdf->Cell(
                0,
                5,
                '$' . number_format(
                    (float) $item['subtotal'],
                    2
                ),
                0,
                1,
                'R'
            );
        }

        $pdf->Line(5, $pdf->GetY(), 75, $pdf->GetY());
        $pdf->Ln(3);

        $pdf->SetFont('Courier', 'B', 11);
        $pdf->Cell(45, 7, 'TOTAL', 0, 0);
        $pdf->Cell(
            25,
            7,
            '$' . number_format($total, 2),
            0,
            1,
            'R'
        );

        $pdf->SetFont('Courier', '', 9);
        $pdf->Cell(40, 5, 'Metodo:', 0, 0);
        $pdf->Cell(
            30,
            5,
            ucfirst($metodo_pago),
            0,
            1,
            'R'
        );

        if (
            $metodo_pago === 'efectivo'
            && $monto_recibido !== null
        ) {
            $pdf->Cell(40, 5, 'Recibido:', 0, 0);
            $pdf->Cell(
                30,
                5,
                '$' . number_format(
                    $monto_recibido,
                    2
                ),
                0,
                1,
                'R'
            );

            $pdf->Cell(40, 5, 'Cambio:', 0, 0);
            $pdf->Cell(
                30,
                5,
                '$' . number_format(
                    (float) $cambio,
                    2
                ),
                0,
                1,
                'R'
            );
        }

        $ticket_dir =
            __DIR__ . '/uploads/tickets/';

        if (!is_dir($ticket_dir)) {
            mkdir($ticket_dir, 0775, true);
        }

        $filename =
            'ticket_' .
            $venta_id .
            '_' .
            date('Ymd_His') .
            '.pdf';

        $filepath = $ticket_dir . $filename;
        $pdf->Output('F', $filepath);

        $pdf_content = file_get_contents($filepath);

        if ($pdf_content === false) {
            throw new RuntimeException(
                'No se pudo leer el PDF generado.'
            );
        }

        $queryTicket = "
            INSERT INTO tickets_venta (
                venta_id,
                cliente_id,
                cliente_nombre,
                total,
                metodo_pago,
                monto_recibido,
                cambio,
                ticket_pdf,
                ticket_nombre,
                fecha_venta
            ) VALUES (
                ?,
                NULLIF(?, 0),
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                NOW()
            )
        ";

        $stmtTicket = $conn->prepare($queryTicket);

        $stmtTicket->bind_param(
            'iisdsddss',
            $venta_id,
            $cliente_id,
            $cliente_nombre,
            $total,
            $metodo_pago,
            $monto_recibido,
            $cambio,
            $pdf_content,
            $filename
        );

        $stmtTicket->execute();
        $stmtTicket->close();

        return [
            'url' =>
                'uploads/tickets/' . $filename,
            'file' => $filename,
            'path' => $filepath
        ];
    } catch (Throwable $e) {
        error_log(
            '[Ticket venta] ' .
            $e->getMessage()
        );

        return null;
    }
}