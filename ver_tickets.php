<?php
// Archivo: ver_tickets.php
// Ver todos los tickets guardados

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'includes/sidebar.php';
require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

// Obtener todos los tickets
$query = "SELECT * FROM tickets_venta ORDER BY fecha_venta DESC LIMIT 50";
$result = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Tickets de Venta</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .tickets-container {
            padding: 20px;
        }
        .ticket-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .ticket-info {
            flex: 1;
        }
        .ticket-actions button {
            padding: 8px 16px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <?php echo $sidebar_html; ?>
    
    <div class="main-content">
        <div class="tickets-container">
            <h1><i class="fas fa-ticket-alt"></i> Tickets de Venta</h1>
            
            <?php while ($ticket = $result->fetch_assoc()): ?>
            <div class="ticket-card">
                <div class="ticket-info">
                    <strong>Ticket #<?php echo $ticket['venta_id']; ?></strong><br>
                    Fecha: <?php echo date('d/m/Y H:i', strtotime($ticket['fecha_venta'])); ?><br>
                    Total: $<?php echo number_format($ticket['total'], 2); ?>
                </div>
                <div class="ticket-actions">
                    <button onclick="window.open('uploads/tickets/<?php echo $ticket['ticket_nombre']; ?>', '_blank')">
                        <i class="fas fa-download"></i> Ver PDF
                    </button>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
    </div>
</body>
</html>