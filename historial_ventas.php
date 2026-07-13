<?php
// Archivo: historial_ventas.php
// Módulo de historial de ventas

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

/*
 * Endpoint interno del mismo historial_ventas.php.
 * Consulta directamente tickets_venta sin crear archivos adicionales.
 */
if (
    isset($_GET['action']) &&
    $_GET['action'] === 'ticket_datos'
) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    function responderTicketHistorial($codigo, $respuesta)
    {
        http_response_code($codigo);
        echo json_encode(
            $respuesta,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit();
    }

    $ventaId = isset($_GET['venta_id'])
        ? (int) $_GET['venta_id']
        : 0;

    if ($ventaId <= 0) {
        responderTicketHistorial(400, array(
            'success' => false,
            'message' => 'El identificador de la venta no es válido.'
        ));
    }

    try {
        require_once __DIR__ . '/config/database.php';

        $database = new Database();
        $connTicket = $database->getConnection();

        if (!$connTicket instanceof mysqli) {
            throw new RuntimeException(
                'No fue posible establecer conexión con la base de datos.'
            );
        }

        $connTicket->set_charset('utf8mb4');

        $sqlTicket = "SELECT
                        tv.id,
                        tv.venta_id,
                        tv.cliente_id,
                        tv.cliente_nombre,
                        tv.total,
                        tv.metodo_pago,
                        tv.monto_recibido,
                        tv.cambio,
                        tv.ticket_nombre,
                        tv.fecha_venta,
                        tv.fecha_registro
                      FROM tickets_venta tv
                      WHERE tv.venta_id = ?
                      ORDER BY tv.id DESC
                      LIMIT 1";

        $stmtTicket = $connTicket->prepare($sqlTicket);

        if (!$stmtTicket) {
            throw new RuntimeException(
                'No se pudo preparar la consulta del ticket: ' .
                $connTicket->error
            );
        }

        $stmtTicket->bind_param('i', $ventaId);

        if (!$stmtTicket->execute()) {
            $detalleError = $stmtTicket->error;
            $stmtTicket->close();

            throw new RuntimeException(
                'No se pudo consultar el ticket: ' . $detalleError
            );
        }

        $resultadoTicket = $stmtTicket->get_result();
        $ticket = $resultadoTicket->fetch_assoc();

        $stmtTicket->close();

        if (!$ticket) {
            responderTicketHistorial(200, array(
                'success' => true,
                'ticket' => null,
                'message' => 'La venta no tiene un ticket guardado.'
            ));
        }

        $ticket['id'] = (int) $ticket['id'];
        $ticket['venta_id'] = (int) $ticket['venta_id'];

        if ($ticket['cliente_id'] !== null) {
            $ticket['cliente_id'] = (int) $ticket['cliente_id'];
        }

        $ticket['total'] = (float) $ticket['total'];

        if ($ticket['monto_recibido'] !== null) {
            $ticket['monto_recibido'] =
                (float) $ticket['monto_recibido'];
        }

        if ($ticket['cambio'] !== null) {
            $ticket['cambio'] = (float) $ticket['cambio'];
        }

        responderTicketHistorial(200, array(
            'success' => true,
            'ticket' => $ticket
        ));
    } catch (Throwable $errorTicket) {
        responderTicketHistorial(500, array(
            'success' => false,
            'message' => $errorTicket->getMessage()
        ));
    }
}

require_once 'includes/sidebar.php';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Ventas - Ego Gym</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #0f172a;
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            min-height: 100vh;
        }

        .main-content {
            margin-left: 280px;
            padding: 25px;
            transition: all 0.3s;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 80px 15px 15px 15px;
            }
        }

        .historial-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .historial-header {
            background: #1e293b;
            padding: 20px 25px;
            color: #ffffff;
        }

        .historial-header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .historial-header h1 i {
            color: #3b82f6;
        }

        .historial-header p {
            color: #94a3b8;
            margin-top: 5px;
            font-size: 0.85rem;
        }

        .filtros-section {
            padding: 20px 25px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .filtros-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }

        .filtro-group {
            flex: 1;
            min-width: 160px;
        }

        .filtro-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        .filtro-group input,
        .filtro-group select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.85rem;
            background: #ffffff;
        }

        .filtro-group input:focus,
        .filtro-group select:focus {
            outline: none;
            border-color: #3b82f6;
        }

        .btn-limpiar {
            background: #ef4444;
            color: #ffffff;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .btn-limpiar:hover {
            background: #dc2626;
        }

        .stats-grid {
            display: flex;
            gap: 20px;
            padding: 20px 25px;
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            flex-wrap: wrap;
        }

        .stat-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 15px 20px;
            text-align: center;
            flex: 1;
            min-width: 150px;
        }

        .stat-card i {
            font-size: 1.5rem;
            color: #3b82f6;
            margin-bottom: 8px;
        }

        .stat-card .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
        }

        .stat-card .stat-label {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 4px;
        }

        .tabla-container {
            overflow-x: auto;
            padding: 0 25px 25px 25px;
        }

        .ventas-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .ventas-table th {
            text-align: left;
            padding: 12px 10px;
            background: #f8fafc;
            color: #1e293b;
            font-weight: 600;
            border-bottom: 2px solid #e2e8f0;
        }

        .ventas-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #e2e8f0;
            color: #334155;
        }

        .ventas-table tbody tr:hover {
            background: #f8fafc;
            cursor: pointer;
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-completada {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-cancelada {
            background: #fee2e2;
            color: #991b1b;
        }

        .acciones {
            display: flex;
            gap: 6px;
            flex-wrap: nowrap;
        }

        .btn-icon {
            padding: 5px 8px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.7rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }

        .btn-ver { background: #3b82f6; color: #ffffff; }
        .btn-cancelar { background: #ef4444; color: #ffffff; }
        .btn-devolver { background: #f59e0b; color: #ffffff; }
        .btn-devolver-disabled { background: #94a3b8; color: #ffffff; cursor: not-allowed; }
        .btn-email { background: #10b981; color: #ffffff; }
        .btn-email-disabled { background: #94a3b8; color: #ffffff; cursor: not-allowed; }

        .btn-icon:hover:not(.btn-email-disabled):not(.btn-devolver-disabled) {
            filter: brightness(0.9);
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 6px;
            padding: 15px 25px;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        .pagination button {
            padding: 6px 12px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
        }

        .pagination button:hover,
        .pagination button.active {
            background: #3b82f6;
            color: #ffffff;
            border-color: #3b82f6;
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: #94a3b8;
        }

        .loading i {
            font-size: 2rem;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .empty-state {
            text-align: center;
            padding: 50px 25px;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 10px;
            opacity: 0.5;
        }

        .ticket-number {
            font-family: monospace;
            font-weight: 600;
            color: #3b82f6;
        }

        /* ============================================
           MODAL DE DETALLE CON ESTILO DE TICKET
        ============================================ */
        .swal2-popup.swal-ticket-popup {
            width: 470px !important;
            max-width: calc(100vw - 24px) !important;
            padding: 0 0 18px !important;
            border-radius: 22px !important;
            overflow: hidden !important;
            background: #f3f6fa !important;
            box-shadow: 0 24px 70px rgba(15, 23, 42, 0.24) !important;
        }

        .swal-ticket-popup .swal2-html-container {
            margin: 0 !important;
            padding: 0 !important;
            overflow: visible !important;
        }

        .swal-ticket-popup .swal2-actions {
            width: calc(100% - 36px);
            margin: 16px 18px 0 !important;
            gap: 10px;
        }

        .swal-ticket-popup .swal2-confirm,
        .swal-ticket-popup .swal2-cancel {
            flex: 1;
            min-height: 44px;
            margin: 0 !important;
            border-radius: 10px !important;
            font-size: 0.88rem !important;
            font-weight: 750 !important;
            transition:
                transform 0.18s ease,
                box-shadow 0.18s ease,
                background 0.18s ease !important;
        }

        .swal-ticket-popup .ticket-print-btn {
            color: #ffffff !important;
            border: 1px solid #1d4ed8 !important;
            background: linear-gradient(135deg, #2563eb, #1d4ed8) !important;
            box-shadow: 0 7px 16px rgba(37, 99, 235, 0.22) !important;
        }

        .swal-ticket-popup .ticket-print-btn:hover {
            transform: translateY(-1px);
            background: linear-gradient(135deg, #2f6df2, #1e40af) !important;
            box-shadow: 0 9px 20px rgba(37, 99, 235, 0.28) !important;
        }

        .swal-ticket-popup .ticket-close-btn {
            color: #475569 !important;
            border: 1px solid #cbd5e1 !important;
            background: #ffffff !important;
            box-shadow: none !important;
        }

        .swal-ticket-popup .ticket-close-btn:hover {
            transform: translateY(-1px);
            color: #1e293b !important;
            border-color: #94a3b8 !important;
            background: #f8fafc !important;
        }

        .swal-ticket-popup .swal2-confirm:focus-visible,
        .swal-ticket-popup .swal2-cancel:focus-visible {
            outline: 3px solid rgba(37, 99, 235, 0.18) !important;
            outline-offset: 2px !important;
        }

        .ticket-modal-status {
            padding: 25px 20px 17px;
            text-align: center;
        }

        .ticket-status-icon {
            width: 72px;
            height: 72px;
            margin: 0 auto 12px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            font-size: 2rem;
            border: 4px solid;
        }

        .ticket-status-icon.completada {
            color: #65b94a;
            background: #f6fff2;
            border-color: #d9efcf;
        }

        .ticket-status-icon.cancelada {
            color: #dc3545;
            background: #fff4f5;
            border-color: #f3c9ce;
        }

        .ticket-modal-status h2 {
            margin: 0;
            color: #334155;
            font-size: 1.65rem;
            font-weight: 750;
        }

        .ticket-modal-status p {
            margin: 5px 0 0;
            color: #94a3b8;
            font-size: .78rem;
        }

        .ticket-paper {
            width: min(365px, calc(100% - 36px));
            margin: 0 auto;
            padding: 22px 24px 20px;
            color: #3f3f46;
            background: #ffffff;
            border: 1px solid #e7ebf0;
            border-radius: 4px;
            box-shadow: 0 10px 28px rgba(15, 23, 42, .11);
            font-family: "Courier New", Courier, monospace;
            text-align: left;
        }

        .ticket-brand {
            text-align: center;
            margin-bottom: 8px;
        }

        .ticket-brand img {
            display: block;
            width: 48px;
            height: 48px;
            margin: 0 auto 6px;
            object-fit: contain;
        }

        .ticket-brand-placeholder {
            width: 46px;
            height: 46px;
            margin: 0 auto 6px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            color: #2563eb;
            background: #edf4ff;
            font-size: 1.2rem;
        }

        .ticket-brand-name {
            color: #30343b;
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: 1rem;
            font-weight: 900;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .ticket-number-main {
            margin-top: 4px;
            color: #52525b;
            font-size: .78rem;
            text-align: center;
        }

        .ticket-date {
            margin-top: 3px;
            color: #71717a;
            font-size: .72rem;
            text-align: center;
        }

        .ticket-divider {
            margin: 11px 0;
            border-top: 2px dotted #3f3f46;
        }

        .ticket-products {
            display: grid;
            gap: 10px;
        }

        .ticket-product {
            display: grid;
            gap: 3px;
        }

        .ticket-product-main {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 14px;
            align-items: start;
            color: #46464e;
            font-size: .88rem;
        }

        .ticket-product-main span {
            font-weight: 800;
            overflow-wrap: anywhere;
        }

        .ticket-product-main strong {
            font-weight: 500;
            white-space: nowrap;
        }

        .ticket-product-unit {
            color: #8a8a93;
            font-size: .67rem;
        }

        .ticket-total-row,
        .ticket-info-row {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            align-items: baseline;
        }

        .ticket-total-row {
            color: #404047;
            font-size: 1rem;
            font-weight: 900;
        }

        .ticket-info-list {
            display: grid;
            gap: 5px;
            margin-top: 9px;
            color: #6b6b73;
            font-size: .78rem;
        }

        .ticket-info-row span:last-child {
            color: #52525b;
            text-align: right;
            overflow-wrap: anywhere;
        }

        .ticket-sale-meta {
            display: grid;
            gap: 4px;
            margin-top: 10px;
            color: #7b7b84;
            font-size: .67rem;
        }

        .ticket-footer {
            margin-top: 12px;
            text-align: center;
            color: #6b6b73;
        }

        .ticket-footer strong {
            display: block;
            font-size: .98rem;
            font-weight: 500;
        }

        .ticket-footer small {
            display: block;
            margin-top: 3px;
            font-size: .61rem;
        }

        @media (max-width: 520px) {
            .swal-ticket-popup .swal2-actions {
                flex-direction: column;
            }

            .swal-ticket-popup .swal2-confirm,
            .swal-ticket-popup .swal2-deny {
                width: 100%;
            }

            .ticket-paper {
                width: calc(100% - 24px);
                padding: 19px 17px;
            }

            .ticket-modal-status h2 {
                font-size: 1.4rem;
            }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="historial-card">
            <div class="historial-header">
                <h1>
                    <i class="fas fa-history"></i>
                    Historial de Ventas
                </h1>
                <p>Consulta y gestiona todas las ventas realizadas en el sistema</p>
            </div>

            <div class="filtros-section">
                <div class="filtros-grid">
                    <div class="filtro-group">
                        <label>Buscar</label>
                        <input type="text" id="buscar" placeholder="Ticket o cliente...">
                    </div>
                    <div class="filtro-group">
                        <label>Desde</label>
                        <input type="date" id="fecha-inicio">
                    </div>
                    <div class="filtro-group">
                        <label>Hasta</label>
                        <input type="date" id="fecha-fin">
                    </div>
                    <div class="filtro-group">
                        <label>Método</label>
                        <select id="metodo-pago">
                            <option value="">Todos</option>
                            <option value="efectivo">Efectivo</option>
                            <option value="tarjeta">Tarjeta</option>
                            <option value="transferencia">Transferencia</option>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <button class="btn-limpiar" id="btn-limpiar">
                            <i class="fas fa-eraser"></i> Limpiar
                        </button>
                    </div>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <i class="fas fa-chart-line"></i>
                    <div class="stat-value" id="total-ventas">0</div>
                    <div class="stat-label">Total Ventas</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-dollar-sign"></i>
                    <div class="stat-value" id="total-ingresos">$0</div>
                    <div class="stat-label">Total Ingresos</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-ticket-alt"></i>
                    <div class="stat-value" id="total-clientes">0</div>
                    <div class="stat-label">Total Tickets</div>
                </div>
            </div>

            <div class="tabla-container">
                <div id="loading" class="loading" style="display: none;">
                    <i class="fas fa-spinner"></i>
                    <p>Cargando ventas...</p>
                </div>
                <div id="empty-state" class="empty-state" style="display: none;">
                    <i class="fas fa-receipt"></i>
                    <p>No se encontraron ventas</p>
                    <small>Prueba con otros filtros o realiza una nueva venta</small>
                </div>
                <table class="ventas-table" id="tabla-ventas" style="display: none;">
                    <thead>
                        <tr>
                            <th>Ticket</th>
                            <th>Fecha</th>
                            <th>Cliente</th>
                            <th>Vendedor</th>
                            <th>Total</th>
                            <th>Método</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="ventas-body"></tbody>
                </table>
            </div>

            <div class="pagination" id="pagination" style="display: none;"></div>
        </div>
    </div>

    <script>
    let currentPage = 1;
    let totalPages = 1;
    let detallesVentasCache = {};

    const nombreGimnasio = <?= json_encode(
        isset($gym_nombre) && $gym_nombre !== '' ? $gym_nombre : 'EGO',
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?>;

    const logoGimnasio = <?= json_encode(
        isset($gym_logo_url) ? $gym_logo_url : '',
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?>;

    async function cargarVentas() {
        const buscar = document.getElementById('buscar').value;
        const fechaInicio = document.getElementById('fecha-inicio').value;
        const fechaFin = document.getElementById('fecha-fin').value;
        const metodoPago = document.getElementById('metodo-pago').value;

        mostrarLoading(true);
        
        try {
            let url = `api/ventas_api.php?page=${currentPage}`;
            if (buscar) url += `&buscar=${encodeURIComponent(buscar)}`;
            if (fechaInicio) url += `&fecha_inicio=${fechaInicio}`;
            if (fechaFin) url += `&fecha_fin=${fechaFin}`;
            if (metodoPago) url += `&metodo_pago=${metodoPago}`;
            
            const response = await fetch(url);
            const data = await response.json();
            
            if (data.success) {
                // Cargar detalles para cada venta (para saber cuántos productos tiene)
                for (const venta of data.ventas) {
                    if (!detallesVentasCache[venta.id]) {
                        const detalleResponse = await fetch(`api/ventas_api.php?action=detalle&venta_id=${venta.id}`);
                        const detalleData = await detalleResponse.json();
                        if (detalleData.success) {
                            detallesVentasCache[venta.id] = detalleData.detalles;
                        }
                    }
                }
                
                actualizarEstadisticas(data.ventas);
                mostrarVentas(data.ventas);
                actualizarPaginacion(data.total_pages);
                mostrarLoading(false);
                
                if (data.ventas.length === 0) {
                    document.getElementById('empty-state').style.display = 'block';
                    document.getElementById('tabla-ventas').style.display = 'none';
                    document.getElementById('pagination').style.display = 'none';
                } else {
                    document.getElementById('empty-state').style.display = 'none';
                    document.getElementById('tabla-ventas').style.display = 'table';
                    document.getElementById('pagination').style.display = 'flex';
                }
            } else {
                throw new Error(data.message || 'Error al cargar datos');
            }
        } catch (error) {
            console.error('Error:', error);
            mostrarLoading(false);
            document.getElementById('empty-state').style.display = 'block';
            document.getElementById('tabla-ventas').style.display = 'none';
            document.getElementById('pagination').style.display = 'none';
            Swal.fire('Error', 'No se pudieron cargar las ventas', 'error');
        }
    }

    function actualizarEstadisticas(ventas) {
        const total = ventas.reduce((sum, v) => sum + parseFloat(v.total), 0);
        // Total de tickets = cantidad de ventas
        const totalTickets = ventas.length;
        
        document.getElementById('total-ventas').textContent = ventas.length;
        document.getElementById('total-ingresos').textContent = '$' + total.toFixed(2);
        document.getElementById('total-clientes').textContent = totalTickets;
    }

    function mostrarVentas(ventas) {
        const tbody = document.getElementById('ventas-body');
        tbody.innerHTML = '';
        
        if (!ventas || ventas.length === 0) {
            return;
        }
        
        ventas.forEach(venta => {
            const row = tbody.insertRow();
            row.style.cursor = 'pointer';
            row.onclick = (e) => {
                if (!e.target.closest('.btn-icon')) {
                    verDetalle(venta.id);
                }
            };
            
            // Obtener detalles de la venta para saber cuántos productos tiene
            const detalles = detallesVentasCache[venta.id] || [];
            const tieneUnSoloProducto = detalles.length === 1;
            const cantidadUnicaProducto = tieneUnSoloProducto ? (detalles[0]?.cantidad || 0) : 0;
            
            // Determinar si se puede devolver (solo si tiene más de un producto o más de 1 unidad)
            const puedeDevolver = venta.estado === 'completada' && (!tieneUnSoloProducto || cantidadUnicaProducto > 1);
            
            // Determinar si tiene email (cliente_id válido y mayor que 0)
            const tieneEmail = venta.cliente_id !== null && venta.cliente_id !== undefined && parseInt(venta.cliente_id) > 0;
            
            // Cliente nombre: si es null mostrar "Venta al público"
            const clienteNombre = venta.cliente_nombre && venta.cliente_nombre.trim() !== '' ? venta.cliente_nombre : 'Venta al público';
            
            row.innerHTML = `
                <td class="ticket-number">#${String(venta.id).padStart(8, '0')}</td>
                <td style="white-space: nowrap;">${new Date(venta.fecha_venta).toLocaleString()}</td>
                <td><i class="fas fa-user" style="color: #3b82f6; margin-right: 6px;"></i>${escapeHtml(clienteNombre)}</td>
                <td><i class="fas fa-store" style="color: #8b5cf6; margin-right: 6px;"></i>${escapeHtml(venta.usuario_nombre)}</td>
                <td><strong style="color: #16a34a;">$${parseFloat(venta.total).toFixed(2)}</strong></td>
                <td><span style="background: #e2e8f0; padding: 4px 8px; border-radius: 12px; font-size: 0.7rem;">${venta.metodo_pago.charAt(0).toUpperCase() + venta.metodo_pago.slice(1)}</span></td>
                <td><span class="badge badge-${venta.estado}">${venta.estado}</span></td>
                <td class="acciones">
                    <button class="btn-icon btn-ver" onclick="event.stopPropagation(); verDetalle(${venta.id})">
                        <i class="fas fa-eye"></i> Ver
                    </button>
                    ${venta.estado === 'completada' ? `
                        <button class="btn-icon btn-cancelar" onclick="event.stopPropagation(); cancelarVenta(${venta.id})">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                        ${puedeDevolver ? 
                            `<button class="btn-icon btn-devolver" onclick="event.stopPropagation(); devolverArticulos(${venta.id})">
                                <i class="fas fa-undo-alt"></i> Devolver
                            </button>` : 
                            `<button class="btn-icon btn-devolver-disabled" disabled style="opacity:0.5; cursor:not-allowed;" title="Solo se puede devolver si hay más de un producto o más de una unidad">
                                <i class="fas fa-undo-alt"></i> Devolver
                            </button>`
                        }
                    ` : ''}
                    ${tieneEmail ? 
                        `<button class="btn-icon btn-email" onclick="event.stopPropagation(); reenviarTicket(${venta.id})">
                            <i class="fas fa-envelope"></i> Ticket
                        </button>` : 
                        `<button class="btn-icon btn-email-disabled" disabled style="opacity:0.5; cursor:not-allowed;" title="Cliente sin correo registrado">
                            <i class="fas fa-envelope"></i> Ticket
                        </button>`
                    }
                </td>
            `;
        });
    }
    
    // Función para escapar HTML y prevenir XSS
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function actualizarPaginacion(total) {
        totalPages = total;
        const pagination = document.getElementById('pagination');
        pagination.innerHTML = '';
        
        const prevBtn = document.createElement('button');
        prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i>';
        prevBtn.onclick = () => {
            if (currentPage > 1) {
                currentPage--;
                cargarVentas();
            }
        };
        pagination.appendChild(prevBtn);
        
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(total, currentPage + 2);
        
        for (let i = startPage; i <= endPage; i++) {
            const btn = document.createElement('button');
            btn.textContent = i;
            btn.className = i === currentPage ? 'active' : '';
            btn.onclick = () => {
                currentPage = i;
                cargarVentas();
            };
            pagination.appendChild(btn);
        }
        
        const nextBtn = document.createElement('button');
        nextBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
        nextBtn.onclick = () => {
            if (currentPage < total) {
                currentPage++;
                cargarVentas();
            }
        };
        pagination.appendChild(nextBtn);
    }

    function mostrarLoading(show) {
        const loading = document.getElementById('loading');
        const tabla = document.getElementById('tabla-ventas');
        const empty = document.getElementById('empty-state');
        
        if (show) {
            loading.style.display = 'block';
            tabla.style.display = 'none';
            empty.style.display = 'none';
        } else {
            loading.style.display = 'none';
        }
    }

    function formatoMonedaTicket(valor) {
        const numero = Number(valor || 0);

        return numero.toLocaleString('es-MX', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function formatearFechaTicket(fecha) {
        if (!fecha) return 'Fecha no disponible';

        const valorNormalizado = String(fecha).replace(' ', 'T');
        const fechaObjeto = new Date(valorNormalizado);

        if (Number.isNaN(fechaObjeto.getTime())) {
            return escapeHtml(String(fecha));
        }

        return fechaObjeto.toLocaleString('es-MX', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function capitalizarTexto(texto) {
        const valor = String(texto || '');

        if (!valor) return 'No especificado';

        return valor.charAt(0).toUpperCase() + valor.slice(1);
    }

    function construirTicketVenta(data, ventaId) {
        const venta = data.venta || {};
        const detalles = Array.isArray(data.detalles) ? data.detalles : [];

        const clienteNombre = venta.cliente_nombre && venta.cliente_nombre.trim() !== ''
            ? venta.cliente_nombre
            : 'Venta al público';

        const vendedorNombre = venta.usuario_nombre && venta.usuario_nombre.trim() !== ''
            ? venta.usuario_nombre
            : 'Usuario del sistema';

        /*
         * Los importes del comprobante se toman de tickets_venta.
         * No se vuelven a calcular en JavaScript.
         */
        const ticketGuardado = data.ticket || {};

        const metodoPago = String(
            ticketGuardado.metodo_pago || venta.metodo_pago || ''
        ).toLowerCase();

        const totalTicket = Number(ticketGuardado.total);
        const totalVenta = Number(venta.total);

        const total = Number.isFinite(totalTicket)
            ? totalTicket
            : (Number.isFinite(totalVenta) ? totalVenta : 0);

        const montoRecibidoGuardado = ticketGuardado.monto_recibido;
        const cambioGuardado = ticketGuardado.cambio;

        const tieneMontoRecibido =
            montoRecibidoGuardado !== null &&
            montoRecibidoGuardado !== undefined &&
            montoRecibidoGuardado !== '' &&
            Number.isFinite(Number(montoRecibidoGuardado));

        const tieneCambio =
            cambioGuardado !== null &&
            cambioGuardado !== undefined &&
            cambioGuardado !== '' &&
            Number.isFinite(Number(cambioGuardado));

        const montoRecibido = tieneMontoRecibido
            ? Number(montoRecibidoGuardado)
            : null;

        const cambio = tieneCambio
            ? Number(cambioGuardado)
            : null;

        const logoHtml = logoGimnasio
            ? `<img src="${escapeHtml(logoGimnasio)}" alt="Logo del gimnasio"
                    onerror="this.style.display='none'; this.nextElementSibling.style.display='grid';">
               <span class="ticket-brand-placeholder" style="display:none;">
                    <i class="fas fa-dumbbell"></i>
               </span>`
            : `<span class="ticket-brand-placeholder">
                    <i class="fas fa-dumbbell"></i>
               </span>`;

        const productosHtml = detalles.length
            ? detalles.map(item => {
                const cantidad = Math.max(0, parseInt(item.cantidad, 10) || 0);
                const subtotal = Number(item.subtotal || 0);
                const precioUnitarioApi = Number(item.precio_unitario);
                const precioUnitario = Number.isFinite(precioUnitarioApi)
                    ? precioUnitarioApi
                    : (cantidad > 0 ? subtotal / cantidad : subtotal);

                return `
                    <div class="ticket-product">
                        <div class="ticket-product-main">
                            <span>${escapeHtml(item.producto_nombre || 'Producto')} x${cantidad}</span>
                            <strong>$${formatoMonedaTicket(subtotal)}</strong>
                        </div>
                        <div class="ticket-product-unit">
                            $${formatoMonedaTicket(precioUnitario)} por unidad
                        </div>
                    </div>
                `;
            }).join('')
            : `
                <div class="ticket-product">
                    <div class="ticket-product-main">
                        <span>Sin productos disponibles</span>
                        <strong>$0.00</strong>
                    </div>
                </div>
            `;

        const datosEfectivo = metodoPago === 'efectivo'
            ? `
                <div class="ticket-info-row">
                    <span>Recibido:</span>
                    <span>
                        ${montoRecibido !== null
                            ? '$' + formatoMonedaTicket(montoRecibido)
                            : 'No registrado'}
                    </span>
                </div>
                <div class="ticket-info-row">
                    <span>Cambio:</span>
                    <span>
                        ${cambio !== null
                            ? '$' + formatoMonedaTicket(cambio)
                            : 'No registrado'}
                    </span>
                </div>
            `
            : '';

        return `
            <article class="ticket-paper" id="ticket-compra-${ventaId}">
                <header class="ticket-brand">
                    ${logoHtml}
                    <div class="ticket-brand-name">${escapeHtml(nombreGimnasio)}</div>
                    <div class="ticket-number-main">
                        Ticket de venta #${String(ventaId).padStart(8, '0')}
                    </div>
                    <div class="ticket-date">${formatearFechaTicket(venta.fecha_venta)}</div>
                </header>

                <div class="ticket-divider"></div>

                <section class="ticket-products">
                    ${productosHtml}
                </section>

                <div class="ticket-divider"></div>

                <div class="ticket-total-row">
                    <span>TOTAL</span>
                    <span>$${formatoMonedaTicket(total)}</span>
                </div>

                <div class="ticket-info-list">
                    <div class="ticket-info-row">
                        <span>Método:</span>
                        <span>${escapeHtml(capitalizarTexto(metodoPago))}</span>
                    </div>
                    ${datosEfectivo}
                    <div class="ticket-info-row">
                        <span>Estado:</span>
                        <span>${escapeHtml(capitalizarTexto(venta.estado))}</span>
                    </div>
                </div>

                <div class="ticket-divider"></div>

                <section class="ticket-sale-meta">
                    <div><strong>Cliente:</strong> ${escapeHtml(clienteNombre)}</div>
                    <div><strong>Atendió:</strong> ${escapeHtml(vendedorNombre)}</div>
                </section>

                <footer class="ticket-footer">
                    <strong>Gracias por tu compra</strong>
                    <small>Conserva este ticket para cualquier aclaración.</small>
                </footer>
            </article>
        `;
    }

    function imprimirTicketVenta(data, ventaId) {
        const ticketHtml = construirTicketVenta(data, ventaId);
        const ventana = window.open('', '_blank', 'width=500,height=760');

        if (!ventana) {
            Swal.fire(
                'Ventana bloqueada',
                'Permite las ventanas emergentes para imprimir el ticket.',
                'warning'
            );
            return;
        }

        ventana.document.open();
        ventana.document.write(`
            <!DOCTYPE html>
            <html lang="es">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Ticket #${String(ventaId).padStart(8, '0')}</title>
                <link rel="stylesheet"
                      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
                <style>
                    @page {
                        size: 80mm auto;
                        margin: 5mm;
                    }

                    * {
                        box-sizing: border-box;
                    }

                    body {
                        margin: 0;
                        color: #222;
                        background: #fff;
                        font-family: "Courier New", Courier, monospace;
                    }

                    .ticket-paper {
                        width: 70mm;
                        margin: 0 auto;
                        padding: 2mm 1mm;
                    }

                    .ticket-brand {
                        text-align: center;
                    }

                    .ticket-brand img {
                        display: block;
                        width: 13mm;
                        height: 13mm;
                        margin: 0 auto 2mm;
                        object-fit: contain;
                    }

                    .ticket-brand-placeholder {
                        width: 12mm;
                        height: 12mm;
                        margin: 0 auto 2mm;
                        display: grid;
                        place-items: center;
                        border: 1px solid #222;
                        border-radius: 50%;
                    }

                    .ticket-brand-name {
                        font-family: Arial, sans-serif;
                        font-size: 12pt;
                        font-weight: 800;
                        text-transform: uppercase;
                    }

                    .ticket-number-main {
                        margin-top: 1mm;
                        font-size: 8.5pt;
                    }

                    .ticket-date {
                        margin-top: 1mm;
                        font-size: 8pt;
                    }

                    .ticket-divider {
                        margin: 3mm 0;
                        border-top: 1px dashed #111;
                    }

                    .ticket-products {
                        display: grid;
                        gap: 2.5mm;
                    }

                    .ticket-product-main {
                        display: grid;
                        grid-template-columns: 1fr auto;
                        gap: 3mm;
                        font-size: 9pt;
                    }

                    .ticket-product-main span {
                        font-weight: 700;
                    }

                    .ticket-product-unit {
                        margin-top: .5mm;
                        font-size: 7pt;
                    }

                    .ticket-total-row,
                    .ticket-info-row {
                        display: flex;
                        justify-content: space-between;
                        gap: 3mm;
                    }

                    .ticket-total-row {
                        font-size: 11pt;
                        font-weight: 800;
                    }

                    .ticket-info-list {
                        display: grid;
                        gap: 1mm;
                        margin-top: 2mm;
                        font-size: 8.5pt;
                    }

                    .ticket-sale-meta {
                        display: grid;
                        gap: 1mm;
                        font-size: 7.5pt;
                    }

                    .ticket-footer {
                        margin-top: 3mm;
                        text-align: center;
                    }

                    .ticket-footer strong {
                        display: block;
                        font-size: 10pt;
                        font-weight: 500;
                    }

                    .ticket-footer small {
                        display: block;
                        margin-top: 1mm;
                        font-size: 6.5pt;
                    }
                </style>
            </head>
            <body>
                ${ticketHtml}
                <script>
                    window.addEventListener('load', function () {
                        setTimeout(function () {
                            window.print();
                        }, 250);
                    });
                <\/script>
            </body>
            </html>
        `);
        ventana.document.close();
    }

    async function verDetalle(ventaId) {
        try {
            Swal.fire({
                title: 'Cargando ticket...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const response = await fetch(
                `api/ventas_api.php?action=detalle&venta_id=${ventaId}`
            );

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'No se pudo cargar el ticket.');
            }

            /*
             * Consultar el comprobante guardado para obtener exactamente:
             * total, método de pago, monto recibido y cambio.
             */
            const ticketResponse = await fetch(
                `historial_ventas.php?action=ticket_datos&venta_id=${encodeURIComponent(ventaId)}`,
                {
                    headers: {
                        'Accept': 'application/json'
                    }
                }
            );

            const ticketData = await ticketResponse.json();

            if (!ticketResponse.ok || !ticketData.success) {
                throw new Error(
                    ticketData.message ||
                    'No se pudieron consultar los importes del ticket.'
                );
            }

            data.ticket = ticketData.ticket || null;

            const estado = String(data.venta.estado || '').toLowerCase();
            const estaCancelada = estado === 'cancelada';

            const resultado = await Swal.fire({
                html: `
                    <div class="ticket-modal-status">
                        <div class="ticket-status-icon ${estaCancelada ? 'cancelada' : 'completada'}">
                            <i class="fas ${estaCancelada ? 'fa-xmark' : 'fa-check'}"></i>
                        </div>
                        <h2>${estaCancelada ? 'Venta cancelada' : 'Venta completada'}</h2>
                        <p>Consulta el comprobante de la operación.</p>
                    </div>

                    ${construirTicketVenta(data, ventaId)}
                `,
                customClass: {
                    popup: 'swal-ticket-popup',
                    confirmButton: 'ticket-print-btn',
                    cancelButton: 'ticket-close-btn'
                },
                showDenyButton: false,
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-print"></i> Imprimir ticket',
                cancelButtonText: '<i class="fas fa-xmark"></i> Cerrar',
                reverseButtons: false,
                buttonsStyling: false,
                focusConfirm: false,
                focusCancel: true
            });

            if (resultado.isConfirmed) {
                imprimirTicketVenta(data, ventaId);
            }
        } catch (error) {
            console.error('Error al consultar la venta:', error);

            Swal.fire(
                'No se pudo abrir el ticket',
                error.message || 'Ocurrió un error al consultar la venta.',
                'error'
            );
        }
    }

    async function cancelarVenta(ventaId) {
        const confirmacion = await Swal.fire({
            title: '¿Cancelar venta?',
            text: 'Esta acción devolverá el stock y no se podrá deshacer.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sí, cancelar',
            cancelButtonText: 'No',
            confirmButtonColor: '#ef4444'
        });
        
        if (confirmacion.isConfirmed) {
            Swal.fire({
                title: 'Procesando...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            const response = await fetch('includes/procesar_cancelacion.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ venta_id: ventaId })
            });
            
            const result = await response.json();
            
            if (result.success) {
                Swal.fire('Cancelada', 'La venta ha sido cancelada correctamente', 'success');
                cargarVentas();
            } else {
                Swal.fire('Error', result.message, 'error');
            }
        }
    }

    async function devolverArticulos(ventaId) {
        const response = await fetch(`api/ventas_api.php?action=detalle&venta_id=${ventaId}`);
        const data = await response.json();
        
        if (!data.success) {
            Swal.fire('Error', 'No se pudieron cargar los detalles', 'error');
            return;
        }
        
        let opciones = '<option value="">Seleccione un producto</option>';
        data.detalles.forEach(item => {
            opciones += `<option value="${item.producto_id}" data-max="${item.cantidad}">${escapeHtml(item.producto_nombre)} (x${item.cantidad})</option>`;
        });
        
        const { value: formValues } = await Swal.fire({
            title: 'Devolver Artículos',
            width: '450px',
            html: `
                <div style="text-align: left;">
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; margin-bottom: 6px; font-weight: 600; font-size: 0.85rem;">Producto</label>
                        <select id="producto-select" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid #e2e8f0;">${opciones}</select>
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label style="display: block; margin-bottom: 6px; font-weight: 600; font-size: 0.85rem;">Cantidad</label>
                        <input type="number" id="cantidad-devolver" min="1" value="1" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid #e2e8f0;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 6px; font-weight: 600; font-size: 0.85rem;">Motivo</label>
                        <textarea id="motivo" rows="2" style="width: 100%; padding: 8px; border-radius: 6px; border: 1px solid #e2e8f0;" placeholder="Ingrese el motivo..."></textarea>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Procesar',
            cancelButtonText: 'Cancelar',
            preConfirm: () => {
                const productoSelect = document.getElementById('producto-select');
                const cantidad = document.getElementById('cantidad-devolver').value;
                const maxCantidad = productoSelect.options[productoSelect.selectedIndex]?.dataset.max || 0;
                const motivo = document.getElementById('motivo').value;
                
                if (!productoSelect.value) {
                    Swal.showValidationMessage('Seleccione un producto');
                    return false;
                }
                if (!cantidad || cantidad < 1 || cantidad > maxCantidad) {
                    Swal.showValidationMessage(`Ingrese una cantidad válida (1-${maxCantidad})`);
                    return false;
                }
                if (!motivo.trim()) {
                    Swal.showValidationMessage('Ingrese el motivo de la devolución');
                    return false;
                }
                
                return {
                    producto_id: productoSelect.value,
                    cantidad: parseInt(cantidad),
                    motivo: motivo
                };
            }
        });
        
        if (formValues) {
            Swal.fire({
                title: 'Procesando...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            const devolucionResponse = await fetch('includes/procesar_devolucion.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    venta_id: ventaId,
                    producto_id: formValues.producto_id,
                    cantidad: formValues.cantidad,
                    motivo: formValues.motivo
                })
            });
            
            const result = await devolucionResponse.json();
            
            if (result.success) {
                Swal.fire('Completado', result.message, 'success');
                cargarVentas();
            } else {
                Swal.fire('Error', result.message, 'error');
            }
        }
    }

    async function reenviarTicket(ventaId) {
        // Mostrar loading
        Swal.fire({
            title: 'Obteniendo datos...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });
        
        try {
            // Obtener el email del cliente
            const response = await fetch(`api/ventas_api.php?action=detalle&venta_id=${ventaId}`);
            const data = await response.json();
            
            if (!data.success) {
                Swal.close();
                Swal.fire('Error', 'No se pudieron obtener los datos de la venta', 'error');
                return;
            }
            
            const emailCliente = data.venta.cliente_email;
            
            if (!emailCliente) {
                Swal.close();
                Swal.fire('Error', 'Este cliente no tiene correo electrónico registrado', 'error');
                return;
            }
            
            Swal.close();
            
            // Confirmar envío
            const confirm = await Swal.fire({
                title: 'Confirmar envío',
                text: `¿Enviar ticket al correo ${emailCliente}?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí, enviar',
                cancelButtonText: 'Cancelar'
            });
            
            if (!confirm.isConfirmed) return;
            
            // Enviar ticket
            Swal.fire({
                title: 'Enviando ticket...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            const sendResponse = await fetch('includes/reenviar_ticket.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    venta_id: ventaId,
                    email: emailCliente
                })
            });
            
            const result = await sendResponse.json();
            
            if (result.success) {
                Swal.fire('¡Enviado!', result.message, 'success');
            } else {
                Swal.fire('Error', result.message, 'error');
            }
            
        } catch (error) {
            console.error('Error:', error);
            Swal.close();
            Swal.fire('Error', 'Ocurrió un error al procesar la solicitud', 'error');
        }
    }

    // Event listeners
    document.getElementById('buscar').addEventListener('input', () => {
        currentPage = 1;
        cargarVentas();
    });
    
    document.getElementById('fecha-inicio').addEventListener('change', () => {
        currentPage = 1;
        cargarVentas();
    });
    
    document.getElementById('fecha-fin').addEventListener('change', () => {
        currentPage = 1;
        cargarVentas();
    });
    
    document.getElementById('metodo-pago').addEventListener('change', () => {
        currentPage = 1;
        cargarVentas();
    });
    
    document.getElementById('btn-limpiar').addEventListener('click', () => {
        document.getElementById('buscar').value = '';
        document.getElementById('fecha-inicio').value = '';
        document.getElementById('fecha-fin').value = '';
        document.getElementById('metodo-pago').value = '';
        currentPage = 1;
        cargarVentas();
    });
    
    cargarVentas();
    </script>
</body>
</html>