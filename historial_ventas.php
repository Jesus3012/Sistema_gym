<?php
// Archivo: historial_ventas.php
// Módulo de historial de ventas

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
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

    async function verDetalle(ventaId) {
        const response = await fetch(`api/ventas_api.php?action=detalle&venta_id=${ventaId}`);
        const data = await response.json();
        
        if (data.success) {
            let productosHtml = '<div style="max-height: 300px; overflow-y: auto;">';
            data.detalles.forEach(item => {
                productosHtml += `
                    <div style="display: flex; justify-content: space-between; padding: 10px; border-bottom: 1px solid #e2e8f0;">
                        <div>
                            <strong>${escapeHtml(item.producto_nombre)}</strong>
                            <div style="font-size: 0.75rem; color: #64748b;">x${item.cantidad}</div>
                        </div>
                        <div style="font-weight: 600;">$${parseFloat(item.subtotal).toFixed(2)}</div>
                    </div>
                `;
            });
            productosHtml += '</div>';
            
            const clienteNombre = data.venta.cliente_nombre && data.venta.cliente_nombre.trim() !== '' ? data.venta.cliente_nombre : 'Venta al público';
            
            Swal.fire({
                title: `Detalle de Venta`,
                html: `
                    <div style="text-align: left;">
                        <div style="background: #1e293b; padding: 12px; border-radius: 10px; color: #ffffff; margin-bottom: 15px; text-align: center;">
                            <div style="font-size: 0.7rem;">Ticket</div>
                            <div style="font-size: 1.1rem; font-weight: bold;">#${String(ventaId).padStart(8, '0')}</div>
                        </div>
                        <div style="display: grid; gap: 8px; margin-bottom: 15px;">
                            <div style="display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #e2e8f0;">
                                <span><i class="fas fa-calendar"></i> Fecha</span>
                                <span>${new Date(data.venta.fecha_venta).toLocaleString()}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #e2e8f0;">
                                <span><i class="fas fa-user"></i> Cliente</span>
                                <span>${escapeHtml(clienteNombre)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #e2e8f0;">
                                <span><i class="fas fa-store"></i> Vendedor</span>
                                <span>${escapeHtml(data.venta.usuario_nombre)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding: 6px 0;">
                                <span><i class="fas fa-credit-card"></i> Método</span>
                                <span>${data.venta.metodo_pago}</span>
                            </div>
                        </div>
                        <div style="background: #f8fafc; border-radius: 10px; padding: 12px;">
                            <div style="font-weight: 600; margin-bottom: 8px;">Productos</div>
                            ${productosHtml}
                            <div style="margin-top: 10px; padding-top: 8px; border-top: 2px solid #e2e8f0; text-align: right;">
                                <strong>TOTAL: <span style="color: #16a34a;">$${parseFloat(data.venta.total).toFixed(2)}</span></strong>
                            </div>
                        </div>
                    </div>
                `,
                width: '500px',
                confirmButtonColor: '#3b82f6',
                confirmButtonText: 'Cerrar'
            });
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