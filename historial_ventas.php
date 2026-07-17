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

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Ventas - Ego Gym</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/historial_ventas.css">
</head>
<body>
    <!-- historial-ventas-acciones-v4 -->
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content ventas-page">
        <div class="ventas-shell">
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
                    <div class="filtro-group filtro-actions">
                        <button type="button" class="btn-limpiar" id="btn-limpiar">
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
    </main>

    <script>
    let currentPage = 1;
    let totalPages = 1;
    let detallesVentasCache = {};
    let plazosVentasCache = {};

    const nombreGimnasio = <?= json_encode(
        isset($gym_nombre) && $gym_nombre !== '' ? $gym_nombre : 'EGO',
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?>;

    const logoGimnasio = <?= json_encode(
        isset($gym_logo_url) ? $gym_logo_url : '',
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) ?>;

    async function cargarPlazosVentas(ventas) {
        const ids = Array.isArray(ventas)
            ? ventas
                .map(venta => Number(venta.id))
                .filter(id => id > 0)
            : [];

        if (!ids.length) {
            plazosVentasCache = {};
            return;
        }

        try {
            const response = await fetch(
                'api/plazos_devoluciones_api.php?action=ventas' +
                `&venta_ids=${encodeURIComponent(ids.join(','))}`,
                {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store'
                }
            );

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(
                    data.message || 'No se pudieron validar los plazos.'
                );
            }

            plazosVentasCache = data.plazos || {};
        } catch (error) {
            console.error('Error al validar plazos:', error);
            plazosVentasCache = {};
        }
    }

    function obtenerPlazoVenta(ventaId) {
        return plazosVentasCache[String(ventaId)] || null;
    }

    function mostrarMotivoPlazo(ventaId, accion) {
        const plazo = obtenerPlazoVenta(ventaId);
        const esCancelacion = accion === 'cancelacion';

        const titulo = esCancelacion
            ? 'Cancelación no disponible'
            : 'Devolución no disponible';

        const mensaje = plazo
            ? (
                esCancelacion
                    ? plazo.motivo_cancelacion
                    : plazo.motivo_devolucion
            )
            : 'No fue posible validar el plazo de esta venta. Recarga la página.';

        Swal.fire({
            title: titulo,
            text: mensaje,
            icon: 'info',
            confirmButtonText: 'Entendido'
        });
    }

    function validarAccionPorPlazo(ventaId, accion) {
        const plazo = obtenerPlazoVenta(ventaId);

        if (!plazo) {
            mostrarMotivoPlazo(ventaId, accion);
            return false;
        }

        const permitido = accion === 'cancelacion'
            ? plazo.puede_cancelar === true
            : plazo.puede_devolver === true;

        if (!permitido) {
            mostrarMotivoPlazo(ventaId, accion);
            return false;
        }

        return true;
    }

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
                
                await cargarPlazosVentas(data.ventas);

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
            
            // Regla comercial existente: solo mostrar devolución si quedan
            // varios productos o más de una unidad del mismo producto.
            const puedeDevolverProductos =
                venta.estado === 'completada' &&
                (!tieneUnSoloProducto || cantidadUnicaProducto > 1);

            const plazo = obtenerPlazoVenta(venta.id);

            const puedeCancelarPlazo =
                plazo?.puede_cancelar === true;

            const puedeDevolverPlazo =
                plazo?.puede_devolver === true;

            const motivoCancelar = plazo?.motivo_cancelacion ||
                'No fue posible validar el plazo de cancelación.';

            const motivoDevolver = plazo?.motivo_devolucion ||
                'No fue posible validar el plazo de devolución.';
            
            // Determinar si tiene email (cliente_id válido y mayor que 0)
            const tieneEmail = venta.cliente_id !== null && venta.cliente_id !== undefined && parseInt(venta.cliente_id) > 0;
            
            // Cliente nombre: si es null mostrar "Venta al público"
            const clienteNombre = venta.cliente_nombre && venta.cliente_nombre.trim() !== ''
                ? venta.cliente_nombre
                : 'Venta al público';

            const metodoPago = String(venta.metodo_pago || '').toLowerCase();
            const metodoClase = ['efectivo', 'tarjeta', 'transferencia'].includes(metodoPago)
                ? metodoPago
                : 'otro';

            const estadoVenta = String(venta.estado || '').toLowerCase();

            row.innerHTML = `
                <td data-label="Ticket" class="ticket-number">
                    #${String(venta.id).padStart(8, '0')}
                </td>
                <td data-label="Fecha" class="venta-fecha">
                    ${new Date(venta.fecha_venta).toLocaleString('es-MX')}
                </td>
                <td data-label="Cliente">
                    <span class="venta-persona">
                        <i class="fas fa-user" style="color:#2563eb;"></i>
                        <span>${escapeHtml(clienteNombre)}</span>
                    </span>
                </td>
                <td data-label="Vendedor">
                    <span class="venta-persona">
                        <i class="fas fa-store" style="color:#7c3aed;"></i>
                        <span>${escapeHtml(venta.usuario_nombre)}</span>
                    </span>
                </td>
                <td data-label="Total">
                    <strong class="venta-total">$${parseFloat(venta.total).toFixed(2)}</strong>
                </td>
                <td data-label="Método">
                    <span class="payment-badge payment-${metodoClase}">
                        ${escapeHtml(capitalizarTexto(metodoPago))}
                    </span>
                </td>
                <td data-label="Estado">
                    <span class="badge badge-${estadoVenta}">
                        ${escapeHtml(capitalizarTexto(estadoVenta))}
                    </span>
                </td>
                <td data-label="Acciones" class="acciones">
                    <div class="acciones-list">
                        <button type="button" class="btn-icon btn-ver" onclick="event.stopPropagation(); verDetalle(${venta.id})">
                            <i class="fas fa-eye"></i> Ver
                        </button>
                        ${estadoVenta === 'completada' ? `
                            ${puedeCancelarPlazo
                                ? `<button type="button"
                                           class="btn-icon btn-cancelar"
                                           title="Quedan ${Number(plazo?.dias_restantes_cancelacion || 0)} día(s) para cancelar"
                                           onclick="event.stopPropagation(); cancelarVenta(${venta.id})">
                                    <i class="fas fa-times"></i> Cancelar
                                   </button>`
                                : `<button type="button"
                                           class="btn-icon btn-cancelar-disabled btn-plazo-bloqueado"
                                           title="${escapeHtml(motivoCancelar)}"
                                           onclick="event.stopPropagation(); mostrarMotivoPlazo(${venta.id}, 'cancelacion')">
                                    <i class="fas fa-clock"></i> Cancelar
                                   </button>`
                            }

                            ${!puedeDevolverProductos
                                ? `<button type="button"
                                           class="btn-icon btn-devolver-disabled"
                                           disabled
                                           title="Solo se puede devolver si hay más de un producto o más de una unidad">
                                    <i class="fas fa-undo-alt"></i> Devolver
                                   </button>`
                                : (
                                    puedeDevolverPlazo
                                        ? `<button type="button"
                                                   class="btn-icon btn-devolver"
                                                   title="Quedan ${Number(plazo?.dias_restantes_devolucion || 0)} día(s) para devolver"
                                                   onclick="event.stopPropagation(); devolverArticulos(${venta.id})">
                                            <i class="fas fa-undo-alt"></i> Devolver
                                           </button>`
                                        : `<button type="button"
                                                   class="btn-icon btn-devolver-disabled btn-plazo-bloqueado"
                                                   title="${escapeHtml(motivoDevolver)}"
                                                   onclick="event.stopPropagation(); mostrarMotivoPlazo(${venta.id}, 'devolucion')">
                                            <i class="fas fa-clock"></i> Devolver
                                           </button>`
                                )
                            }
                        ` : ''}
                        ${tieneEmail
                            ? `<button type="button" class="btn-icon btn-email" onclick="event.stopPropagation(); reenviarTicket(${venta.id})">
                                <i class="fas fa-envelope"></i> Ticket
                               </button>`
                            : `<button type="button" class="btn-icon btn-email-disabled" disabled
                                       title="Cliente sin correo registrado">
                                <i class="fas fa-envelope"></i> Ticket
                               </button>`
                        }
                    </div>
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
        prevBtn.type = 'button';
        prevBtn.innerHTML = '<i class="fas fa-chevron-left"></i>';
        prevBtn.disabled = currentPage <= 1;
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
            btn.type = 'button';
            btn.textContent = i;
            btn.className = i === currentPage ? 'active' : '';
            btn.onclick = () => {
                currentPage = i;
                cargarVentas();
            };
            pagination.appendChild(btn);
        }
        
        const nextBtn = document.createElement('button');
        nextBtn.type = 'button';
        nextBtn.innerHTML = '<i class="fas fa-chevron-right"></i>';
        nextBtn.disabled = currentPage >= total;
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
        if (!validarAccionPorPlazo(ventaId, 'cancelacion')) {
            return;
        }

        const plazo = obtenerPlazoVenta(ventaId);
        const esTarjeta = plazo?.metodo_pago === 'tarjeta';

        const confirmacion = await Swal.fire({
            title: '¿Cancelar venta?',
            text: esTarjeta
                ? 'Se solicitará el reembolso a Mercado Pago y después se devolverá el stock.'
                : 'Esta acción devolverá el stock y no se podrá deshacer.',
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
                Swal.fire(
                    'Cancelada',
                    result.message || 'La venta ha sido cancelada correctamente',
                    'success'
                );
                cargarVentas();
            } else {
                Swal.fire('Error', result.message, 'error');
            }
        }
    }

    async function devolverArticulos(ventaId) {
        if (!validarAccionPorPlazo(ventaId, 'devolucion')) {
            return;
        }

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
                    <div style="margin-bottom:12px;padding:9px 10px;border:1px solid #dbe5f0;border-radius:8px;background:#f8fafc;color:#667085;font-size:.76rem;line-height:1.4;">
                        <i class="fas fa-clock" style="color:#244292;margin-right:5px;"></i>
                        Esta venta está dentro del plazo permitido.
                        Quedan ${Number(obtenerPlazoVenta(ventaId)?.dias_restantes_devolucion || 0)} día(s).
                    </div>
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
    let temporizadorBusqueda = null;

    document.getElementById('buscar').addEventListener('input', () => {
        clearTimeout(temporizadorBusqueda);
        temporizadorBusqueda = setTimeout(() => {
            currentPage = 1;
            cargarVentas();
        }, 320);
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