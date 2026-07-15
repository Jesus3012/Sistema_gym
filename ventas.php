<?php
// Archivo: ventas.php
// Módulo de venta de productos

// Asegurar que la sesión está iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Verificar rol (solo admin y recepcionista)
if ($_SESSION['user_rol'] !== 'admin' && $_SESSION['user_rol'] !== 'recepcionista') {
    header("Location: dashboard.php");
    exit();
}

// Incluir sidebar y configuración
require_once 'includes/sidebar.php';
require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

// Obtener configuración del gimnasio
$query_config = "SELECT nombre, logo FROM configuracion_gimnasio WHERE id = 1";
$result_config = $conn->query($query_config);
$config = $result_config->fetch_assoc();
$gym_nombre = $config['nombre'] ?? 'Ego Gym';
$gym_logo = $config['logo'] ?? '';

// Obtener productos activos
$query_productos = "SELECT p.*, c.nombre as categoria_nombre 
                    FROM productos p 
                    LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
                    WHERE p.estado = 'activo' 
                    ORDER BY p.nombre ASC";
$result_productos = $conn->query($query_productos);
$productos = [];
while ($row = $result_productos->fetch_assoc()) {
    $productos[] = $row;
}

// Obtener clientes para asociar la venta (opcional)
$query_clientes = "SELECT id, nombre, apellido, telefono, email FROM clientes WHERE estado = 'activo' ORDER BY nombre ASC";
$result_clientes = $conn->query($query_clientes);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Venta de Productos - <?php echo htmlspecialchars($gym_nombre); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/ventas.css">
</head>
<body>
    <div class="main-content">
        <div class="ventas-container">
            <header class="ventas-header ventas-header-minimal">
                <h1><i class="fas fa-cash-register" aria-hidden="true"></i> Venta de productos</h1>
            </header>

            <div class="ventas-grid">
                <div class="productos-section">
                    <div class="section-header">
                        <div class="section-heading">
                            <h2>Productos disponibles</h2>
                            <div class="section-meta"><span id="productosVisibles"><?php echo count($productos); ?></span> productos</div>
                        </div>
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="search" id="searchProducto" placeholder="Buscar por nombre o categoría..." autocomplete="off">
                            <button type="button" class="clear-search" id="clearSearch" aria-label="Limpiar búsqueda">
                                <i class="fas fa-xmark"></i>
                            </button>
                        </div>
                    </div>
                    <div class="productos-grid" id="productosGrid">
                        <?php foreach ($productos as $producto): ?>
                            <div class="producto-card"
                                 role="button"
                                 tabindex="0"
                                 aria-label="Agregar <?php echo htmlspecialchars($producto['nombre']); ?> al carrito"
                                 data-id="<?php echo $producto['id']; ?>"
                                 data-nombre="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                 data-categoria="<?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'Sin categoría'); ?>"
                                 data-precio="<?php echo $producto['precio_venta']; ?>"
                                 data-stock="<?php echo $producto['stock']; ?>"
                                 data-imagen="<?php echo htmlspecialchars($producto['foto'] ?? ''); ?>">
                                <div class="producto-card-top">
                                    <span class="producto-categoria"><?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'Sin categoría'); ?></span>
                                    <span class="producto-stock-badge <?php echo $producto['stock'] <= $producto['stock_minimo'] ? 'bajo' : ''; ?>">
                                        <?php echo (int) $producto['stock']; ?> disp.
                                    </span>
                                </div>
                                <div class="producto-imagen">
                                    <?php if (!empty($producto['foto']) && file_exists($producto['foto'])): ?>
                                        <img src="<?php echo htmlspecialchars($producto['foto']); ?>" alt="<?php echo htmlspecialchars($producto['nombre']); ?>" loading="lazy">
                                    <?php else: ?>
                                        <i class="fas fa-box-open"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="producto-contenido">
                                    <div class="producto-nombre"><?php echo htmlspecialchars($producto['nombre']); ?></div>
                                    <div class="producto-footer">
                                        <div>
                                            <div class="producto-precio">$<?php echo number_format($producto['precio_venta'], 2); ?></div>
                                            <div class="producto-stock <?php echo $producto['stock'] <= $producto['stock_minimo'] ? 'bajo' : ''; ?>">
                                                <?php echo $producto['stock'] <= $producto['stock_minimo'] ? 'Stock bajo' : 'Disponible'; ?>
                                            </div>
                                        </div>
                                        <span class="producto-add" aria-hidden="true"><i class="fas fa-plus"></i></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="sin-resultados" id="sinResultados">
                        <i class="fas fa-magnifying-glass"></i>
                        <strong>No encontramos productos</strong>
                        <span>Prueba con otro nombre o categoría.</span>
                    </div>
                </div>

                <aside class="carrito-section" aria-label="Carrito de compra">
                    <div class="carrito-header">
                        <div class="carrito-heading">
                            <h2><i class="fas fa-cart-shopping"></i> Carrito</h2>
                        </div>
                        <div class="carrito-header-actions">
                            <span class="carrito-badge" id="carritoCantidad">0</span>
                            <button type="button" class="btn-vaciar" id="btnVaciarCarrito" aria-label="Vaciar carrito" title="Vaciar carrito">
                                <i class="fas fa-trash-can"></i>
                            </button>
                        </div>
                    </div>
                    <div class="carrito-items" id="carritoItems">
                        <div class="carrito-vacio">
                            <div class="empty-icon"><i class="fas fa-basket-shopping"></i></div>
                            <p>Tu carrito está vacío</p>
                            <small>Selecciona un producto del catálogo para comenzar la venta.</small>
                        </div>
                    </div>
                    <div class="carrito-footer">
                        <div class="cliente-select">
                            <label><i class="fas fa-user"></i> Cliente (Opcional)</label>
                            <select id="clienteId">
                                <option value="">Venta al público (sin cliente)</option>
                                <?php while ($cliente = $result_clientes->fetch_assoc()): ?>
                                    <option value="<?php echo $cliente['id']; ?>">
                                        <?php echo htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apellido']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="carrito-total">
                            <div class="carrito-total-label">
                                <span>Total de la venta</span>
                                <small id="carritoResumenTexto">0 artículos</small>
                            </div>
                            <strong id="totalCarrito">$0.00</strong>
                        </div>
                        <button class="btn-pagar" id="btnPagar" disabled>
                            <i class="fas fa-arrow-right-to-bracket"></i>
                            Cobrar venta
                        </button>
                        <div class="pago-seguro"><i class="fas fa-shield-halved"></i> El cobro se valida antes de registrar la venta</div>
                    </div>
                </aside>
            </div>
        </div>
    </div>

    <script>
    let carrito = [];

    const moneyFormatter = new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN'
    });

    function formatMoney(value) {
        return moneyFormatter.format(Number(value) || 0);
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#039;',
            '"': '&quot;'
        }[character]));
    }

    function loadCart() {
        const savedCart = localStorage.getItem('carritoVentas');
        if (savedCart) {
            try {
                const parsed = JSON.parse(savedCart);
                carrito = Array.isArray(parsed) ? parsed : [];
            } catch (error) {
                localStorage.removeItem('carritoVentas');
                carrito = [];
            }
        }
        updateCartDisplay();
    }

    function saveCart() {
        localStorage.setItem('carritoVentas', JSON.stringify(carrito));
    }

    function addToCart(productoId, nombre, precio, stock, imagen) {
        const existingItem = carrito.find(item => item.id === productoId);
        const currentQuantity = existingItem ? existingItem.cantidad : 0;
        
        if (currentQuantity >= stock) {
            Swal.fire({
                icon: 'error',
                title: 'Stock Insuficiente',
                text: 'Solo hay ' + stock + ' unidades disponibles de ' + nombre,
                confirmButtonColor: '#3b82f6',
                background: 'white',
                confirmButtonText: 'Aceptar'
            });
            return false;
        }

        if (existingItem) {
            existingItem.cantidad++;
        } else {
            carrito.push({
                id: productoId,
                nombre: nombre,
                precio: parseFloat(precio),
                cantidad: 1,
                imagen: imagen,
                stock: stock
            });
        }
        
        saveCart();
        updateCartDisplay();
        
        Swal.fire({
            icon: 'success',
            title: 'Producto Agregado',
            text: nombre + ' agregado al carrito',
            showConfirmButton: false,
            timer: 1500,
            toast: true,
            position: 'top-end',
            background: '#f8fafc',
            color: '#1e293b'
        });
        
        return true;
    }

    function updateQuantity(productoId, change) {
        const item = carrito.find(item => item.id === productoId);
        if (item) {
            const newQuantity = item.cantidad + change;
            if (newQuantity <= 0) {
                removeFromCart(productoId);
            } else if (newQuantity <= item.stock) {
                item.cantidad = newQuantity;
                saveCart();
                updateCartDisplay();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Stock Insuficiente',
                    text: 'Solo hay ' + item.stock + ' unidades disponibles',
                    confirmButtonColor: '#3b82f6',
                    confirmButtonText: 'Aceptar'
                });
            }
        }
    }

    function removeFromCart(productoId) {
        const item = carrito.find(item => item.id === productoId);
        carrito = carrito.filter(item => item.id !== productoId);
        saveCart();
        updateCartDisplay();
        
        Swal.fire({
            icon: 'info',
            title: 'Producto Eliminado',
            text: item.nombre + ' ha sido eliminado del carrito',
            showConfirmButton: false,
            timer: 1500,
            toast: true,
            position: 'top-end',
            background: '#f8fafc',
            color: '#1e293b'
        });
    }

    function updateCartDisplay() {
        const carritoContainer = document.getElementById('carritoItems');
        const totalSpan = document.getElementById('totalCarrito');
        const payButton = document.getElementById('btnPagar');
        const cartBadge = document.getElementById('carritoCantidad');
        const headerCartCount = document.getElementById('headerCartCount');
        const summaryText = document.getElementById('carritoResumenTexto');
        const clearButton = document.getElementById('btnVaciarCarrito');
        const totalItems = carrito.reduce((sum, item) => sum + Number(item.cantidad || 0), 0);

        cartBadge.textContent = totalItems;
        if (headerCartCount) headerCartCount.textContent = totalItems;
        summaryText.textContent = `${totalItems} ${totalItems === 1 ? 'artículo' : 'artículos'}`;
        clearButton.classList.toggle('visible', totalItems > 0);

        if (carrito.length === 0) {
            carritoContainer.innerHTML = `
                <div class="carrito-vacio">
                    <div class="empty-icon"><i class="fas fa-basket-shopping"></i></div>
                    <p>Tu carrito está vacío</p>
                    <small>Selecciona un producto del catálogo para comenzar la venta.</small>
                </div>
            `;
            totalSpan.textContent = formatMoney(0);
            payButton.disabled = true;
            return;
        }

        let total = 0;
        let html = '';

        carrito.forEach(item => {
            const itemId = Number(item.id);
            const itemPrice = Number(item.precio) || 0;
            const itemQuantity = Number(item.cantidad) || 0;
            const subtotal = itemPrice * itemQuantity;
            const safeName = escapeHtml(item.nombre);
            const safeImage = escapeHtml(item.imagen);
            total += subtotal;

            html += `
                <div class="carrito-item">
                    <div class="carrito-item-imagen">
                        ${safeImage && safeImage !== 'null'
                            ? `<img src="${safeImage}" alt="${safeName}" loading="lazy">`
                            : '<i class="fas fa-box-open"></i>'
                        }
                    </div>
                    <div class="carrito-item-info">
                        <div class="carrito-item-nombre" title="${safeName}">${safeName}</div>
                        <div class="carrito-item-precio">${formatMoney(itemPrice)} por unidad</div>
                        <div class="carrito-item-cantidad">
                            <button type="button" class="cantidad-btn" onclick="updateQuantity(${itemId}, -1)" aria-label="Disminuir cantidad">
                                <i class="fas fa-minus"></i>
                            </button>
                            <span>${itemQuantity}</span>
                            <button type="button" class="cantidad-btn" onclick="updateQuantity(${itemId}, 1)" aria-label="Aumentar cantidad">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="carrito-item-side">
                        <div class="carrito-item-total">${formatMoney(subtotal)}</div>
                        <button type="button" class="carrito-item-eliminar" onclick="removeFromCart(${itemId})" aria-label="Eliminar ${safeName}">
                            <i class="fas fa-trash-can"></i>
                        </button>
                    </div>
                </div>
            `;
        });

        carritoContainer.innerHTML = html;
        totalSpan.textContent = formatMoney(total);
        payButton.disabled = false;
    }

    function sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    async function fetchJson(url, options = {}) {
        const response = await fetch(url, options);
        const text = await response.text();
        let data;

        try {
            data = JSON.parse(text);
        } catch (error) {
            throw new Error('El servidor devolvió una respuesta no válida.');
        }

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Ocurrió un error en el servidor.');
        }

        return data;
    }

    function nombreMetodoPago(metodo, installments = 1) {
        if (metodo === 'tarjeta_debito') return 'Tarjeta de débito';
        if (metodo === 'tarjeta_credito') {
            return installments > 1
                ? `Tarjeta de crédito · ${installments} mensualidades`
                : 'Tarjeta de crédito · una exhibición';
        }
        if (metodo === 'transferencia') return 'Transferencia';
        return 'Efectivo';
    }

    async function seleccionarMetodoPago(total) {
        window.metodoSeleccionado = null;

        const resultado = await Swal.fire({
            title: 'Selecciona el método de pago',
            html: `
                <div class="payment-total-card">
                    <span>Total a cobrar</span>
                    <strong>${formatMoney(total)}</strong>
                </div>
                <div class="payment-options">
                    <button type="button" id="btn-efectivo" class="payment-option cash">
                        <span class="payment-icon"><i class="fas fa-money-bill-wave"></i></span>
                        <strong>Efectivo</strong>
                        <small>Calcula automáticamente el cambio.</small>
                    </button>
                    <button type="button" id="btn-debito" class="payment-option debit">
                        <span class="payment-icon"><i class="fas fa-credit-card"></i></span>
                        <strong>Tarjeta de débito</strong>
                        <small>Envía el cobro a la terminal Point.</small>
                    </button>
                    <button type="button" id="btn-credito" class="payment-option credit">
                        <span class="payment-icon"><i class="fas fa-layer-group"></i></span>
                        <strong>Tarjeta de crédito</strong>
                        <small>La terminal mostrará las opciones y MSI disponibles.</small>
                    </button>
                    <button type="button" id="btn-transferencia" class="payment-option transfer">
                        <span class="payment-icon"><i class="fas fa-building-columns"></i></span>
                        <strong>Transferencia</strong>
                        <small>Registra el pago en el corte de caja.</small>
                    </button>
                </div>
            `,
            showConfirmButton: false,
            showCancelButton: true,
            cancelButtonText: 'Cancelar',
            cancelButtonColor: '#dc2626',
            customClass: { popup: 'swal-modern' },
            didOpen: () => {
                const elegir = metodo => {
                    window.metodoSeleccionado = metodo;
                    Swal.clickConfirm();
                };

                document.getElementById('btn-efectivo').onclick = () => elegir('efectivo');
                document.getElementById('btn-debito').onclick = () => elegir('tarjeta_debito');
                document.getElementById('btn-credito').onclick = () => elegir('tarjeta_credito');
                document.getElementById('btn-transferencia').onclick = () => elegir('transferencia');
            },
            preConfirm: () => window.metodoSeleccionado
        });

        return resultado.value || null;
    }

    async function solicitarMontoEfectivo(total) {
        const resultado = await Swal.fire({
            title: 'Pago en efectivo',
            html: `
                <div class="cash-summary">
                    <span>Total a pagar</span>
                    <strong>${formatMoney(total)}</strong>
                </div>
                <label class="swal-field-label" for="monto-recibido">Monto recibido</label>
                <input type="number" id="monto-recibido" class="swal2-input swal-input-modern" value="${total.toFixed(2)}" min="${total.toFixed(2)}" step="0.01" inputmode="decimal">
                <div id="cambio-preview" class="cash-change">Cambio: ${formatMoney(0)}</div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Aceptar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#2563eb',
            customClass: { popup: 'swal-modern' },
            didOpen: () => {
                const input = document.getElementById('monto-recibido');
                const preview = document.getElementById('cambio-preview');
                const update = () => {
                    const value = parseFloat(input.value) || 0;
                    const change = value - total;
                    preview.textContent = change >= 0
                        ? `Cambio: ${formatMoney(change)}`
                        : `Faltan: ${formatMoney(Math.abs(change))}`;
                    preview.style.color = change >= 0 ? '#047857' : '#dc2626';
                };
                input.addEventListener('input', update);
                input.focus();
                input.select();
                update();
            },
            preConfirm: () => {
                const value = parseFloat(document.getElementById('monto-recibido').value);
                if (!Number.isFinite(value) || value < total) {
                    Swal.showValidationMessage('El monto debe ser mayor o igual al total.');
                    return false;
                }
                return value;
            }
        });

        return resultado.isConfirmed ? Number(resultado.value) : null;
    }

    async function crearOrdenPoint(total, paymentType) {
        return fetchJson('api/mercadopago/crear_orden.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                items: carrito,
                total,
                payment_type: paymentType
            })
        });
    }

    async function consultarOrdenPoint(orderId) {
        return fetchJson('api/mercadopago/consultar_orden.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: orderId })
        });
    }

    async function cancelarOrdenPoint(orderId) {
        return fetchJson('api/mercadopago/cancelar_orden.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: orderId })
        });
    }

    async function esperarPagoPoint(order) {
        let settled = false;
        let polling = true;
        let latest = null;
        const startedAt = Date.now();
        const maxWaitMs = 190000;

        return new Promise((resolve, reject) => {
            const finish = value => {
                if (settled) return;
                settled = true;
                polling = false;
                Swal.close();
                resolve(value);
            };

            const fail = error => {
                if (settled) return;
                settled = true;
                polling = false;
                Swal.close();
                reject(error);
            };

            Swal.fire({
                title: 'Esperando pago en terminal',
                html: `
                    <div class="point-wait-icon"><i class="fas fa-credit-card"></i></div>
                    <div style="font-size:.84rem;color:#475569;line-height:1.55;text-align:center;">Completa el cobro en la terminal Point. No cierres esta ventana hasta recibir la confirmación.</div>
                    <div class="point-order-card">Orden: ${escapeHtml(order.order_id)}</div>
                    <div id="mp-status-live" class="point-status-live">Estado: creada</div>
                `,
                showConfirmButton: false,
                showCancelButton: true,
                cancelButtonText: 'Cancelar cobro',
                cancelButtonColor: '#ef4444',
                allowOutsideClick: false,
                allowEscapeKey: false,
                customClass: { popup: 'swal-modern' },
                didOpen: async () => {
                    while (polling && !settled) {
                        try {
                            latest = await consultarOrdenPoint(order.order_id);
                            const statusNode = document.getElementById('mp-status-live');
                            if (statusNode) {
                                statusNode.textContent = `Orden: ${latest.order_status || '-'} · Pago: ${latest.payment_status || '-'}`;
                            }

                            if (latest.paid) {
                                finish(latest);
                                return;
                            }

                            if (latest.final_failure) {
                                fail(new Error(
                                    `El pago terminó en estado ${latest.payment_status || latest.order_status}.`
                                ));
                                return;
                            }
                        } catch (error) {
                            console.error('Consulta Point:', error);
                        }

                        if (Date.now() - startedAt >= maxWaitMs) {
                            fail(new Error('El tiempo para completar el pago terminó. La orden puede consultarse con su ID.'));
                            return;
                        }

                        await sleep(2200);
                    }
                }
            }).then(async result => {
                if (settled || result.isConfirmed) return;

                polling = false;
                try {
                    const canceled = await cancelarOrdenPoint(order.order_id);
                    if (canceled.requires_terminal) {
                        await Swal.fire({
                            icon: 'warning',
                            title: 'Cancela en la terminal',
                            text: canceled.message || 'La orden ya está en la Point y debe cancelarse desde allí.'
                        });
                    }
                    finish(null);
                } catch (error) {
                    fail(error);
                }
            });
        });
    }

    async function registrarVentaLocal(payload) {
        return fetchJson('procesar_venta.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
    }

    async function mostrarTicketVenta(result, contexto) {
        const gymLogo = '<?php echo addslashes($gym_logo); ?>';
        const gymNombre = '<?php echo addslashes($gym_nombre); ?>';
        const { total, metodo, installments, montoRecibido, cambio, clienteId, clienteNombre } = contexto;
        let logoHtml = gymLogo
            ? `<img src="${gymLogo}" alt="${gymNombre}" style="max-width:60px;max-height:60px;margin-bottom:5px;">`
            : '';
        let ticketHtml = `
            <div style="text-align:center;font-family:'Courier New',monospace;max-width:300px;margin:0 auto;">
                ${logoHtml}
                <div style="font-weight:bold;font-size:16px;">${gymNombre}</div>
                <div style="font-size:11px;margin:3px 0;">Ticket de Venta #${result.venta_id}</div>
                <div style="font-size:11px;margin:3px 0;">${new Date().toLocaleString()}</div>
                <hr style="border:1px dashed #000;margin:8px 0;">
        `;

        carrito.forEach(item => {
            ticketHtml += `
                <div style="text-align:left;margin:5px 0;">
                    <div style="font-weight:bold;">${item.nombre} x${item.cantidad}</div>
                    <div style="text-align:right;">$${(item.precio * item.cantidad).toFixed(2)}</div>
                </div>
            `;
        });

        ticketHtml += `
                <hr style="border:1px dashed #000;margin:8px 0;">
                <div style="display:flex;justify-content:space-between;margin:5px 0;"><strong>TOTAL</strong><strong>$${total.toFixed(2)}</strong></div>
                <div style="display:flex;justify-content:space-between;margin:3px 0;"><span>Método:</span><span>${nombreMetodoPago(metodo, installments)}</span></div>
                ${metodo === 'efectivo' ? `
                    <div style="display:flex;justify-content:space-between;margin:3px 0;"><span>Recibido:</span><span>$${montoRecibido.toFixed(2)}</span></div>
                    <div style="display:flex;justify-content:space-between;margin:3px 0;"><span>Cambio:</span><span>$${cambio.toFixed(2)}</span></div>
                ` : ''}
                ${clienteId ? `<div style="display:flex;justify-content:space-between;margin:3px 0;"><span>Cliente:</span><span>${clienteNombre}</span></div>` : ''}
                ${result.mp_order_id ? `<div style="margin-top:6px;font-size:8px;color:#666;overflow-wrap:anywhere;">MP Order: ${result.mp_order_id}</div>` : ''}
                <hr style="border:1px dashed #000;margin:8px 0;">
                <div style="margin-top:8px;"><div>Gracias por su compra</div><div style="font-size:9px;color:#666;">Este ticket es su comprobante de pago</div></div>
            </div>
        `;

        const modal = await Swal.fire({
            title: 'Venta completada',
            html: ticketHtml,
            icon: 'success',
            width: '450px',
            confirmButtonText: 'Descargar Ticket PDF',
            showCancelButton: true,
            cancelButtonText: 'Cerrar',
            confirmButtonColor: '#3b82f6',
            cancelButtonColor: '#94a3b8'
        });

        if (modal.isConfirmed && result.ticket_url) {
            window.open(result.ticket_url, '_blank');
        }
    }

    async function procesarVenta() {
        if (carrito.length === 0) {
            await Swal.fire('Carrito vacío', 'Agrega productos antes de pagar.', 'warning');
            return;
        }

        const clienteSelect = document.getElementById('clienteId');
        const clienteId = clienteSelect.value;
        const clienteNombre = clienteId
            ? clienteSelect.options[clienteSelect.selectedIndex].text
            : 'Venta al público';
        const total = carrito.reduce((sum, item) => sum + item.precio * item.cantidad, 0);
        const metodo = await seleccionarMetodoPago(total);

        if (!metodo) return;

        let installments = 1;
        let montoRecibido = total;
        let mpPayment = null;

        if (metodo === 'efectivo') {
            const cash = await solicitarMontoEfectivo(total);
            if (cash === null) return;
            montoRecibido = cash;
        }

        const cambio = metodo === 'efectivo' ? montoRecibido - total : 0;
        const confirmacion = await Swal.fire({
            title: 'Confirmar venta',
            html: `
                <div class="confirm-total-card">
                    <span>Total a cobrar</span>
                    <strong>${formatMoney(total)}</strong>
                </div>
                <div class="confirm-list">
                    <div class="confirm-row"><span>Método</span><strong>${nombreMetodoPago(metodo, installments)}</strong></div>
                    <div class="confirm-row"><span>Productos</span><strong>${carrito.reduce((sum, item) => sum + Number(item.cantidad || 0), 0)} artículos</strong></div>
                    ${clienteId ? `<div class="confirm-row"><span>Cliente</span><strong>${escapeHtml(clienteNombre)}</strong></div>` : ''}
                    ${metodo === 'tarjeta_credito' ? `<div class="confirm-row"><span>Mensualidades</span><strong>Se seleccionan en la terminal</strong></div>` : ''}
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: metodo.startsWith('tarjeta_') ? 'Enviar a terminal' : 'Confirmar venta',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#10b981',
            cancelButtonColor: '#dc2626',
            customClass: { popup: 'swal-modern' }
        });

        if (!confirmacion.isConfirmed) return;

        try {
            if (metodo === 'tarjeta_debito' || metodo === 'tarjeta_credito') {
                Swal.fire({
                    title: 'Creando orden',
                    text: 'Enviando el cobro a la terminal Point.',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                const paymentType = metodo === 'tarjeta_credito'
                    ? 'credit_card'
                    : 'debit_card';
                const order = await crearOrdenPoint(total, paymentType);
                mpPayment = await esperarPagoPoint(order);

                if (!mpPayment) return;
                installments = Math.max(1, Number(mpPayment.installments || 1));
            }

            Swal.fire({
                title: 'Registrando venta',
                text: 'Actualizando inventario, ticket y corte de caja.',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });

            const payload = {
                cliente_id: clienteId || null,
                items: carrito,
                total,
                metodo_pago: metodo.startsWith('tarjeta_') ? 'tarjeta' : metodo,
                monto_recibido: metodo === 'efectivo' ? montoRecibido : null,
                cliente_nombre: clienteNombre,
                tipo_tarjeta: metodo.startsWith('tarjeta_')
                    ? (metodo === 'tarjeta_credito' ? 'credito' : 'debito')
                    : null,
                mp_order_id: mpPayment?.order_id || null,
                mp_payment_id: mpPayment?.payment_id || null,
                mp_external_reference: mpPayment?.external_reference || null,
                mp_payment_reference_id: mpPayment?.payment_reference_id || null,
                mp_payment_type: mpPayment?.payment_type || null,
                mp_installments: mpPayment?.installments || installments,
                mp_order_status: mpPayment?.order_status || null,
                mp_payment_status: mpPayment?.payment_status || null
            };

            const result = await registrarVentaLocal(payload);
            result.mp_order_id = payload.mp_order_id;

            await mostrarTicketVenta(result, {
                total,
                metodo,
                installments,
                montoRecibido,
                cambio,
                clienteId,
                clienteNombre
            });

            carrito = [];
            saveCart();
            updateCartDisplay();
            location.reload();
        } catch (error) {
            const paidMessage = mpPayment?.paid
                ? ` El pago sí fue aprobado. No vuelvas a cobrar. Conserva la orden ${mpPayment.order_id} y corrige el registro local.`
                : '';

            await Swal.fire({
                icon: 'error',
                title: mpPayment?.paid ? 'Pago aprobado, venta no registrada' : 'No se completó la venta',
                text: (error.message || 'Error desconocido.') + paidMessage,
                confirmButtonColor: '#3b82f6'
            });
        }
    }

    function agregarProductoDesdeTarjeta(card) {
        const id = parseInt(card.dataset.id, 10);
        const nombre = card.dataset.nombre;
        const precio = parseFloat(card.dataset.precio);
        const stock = parseInt(card.dataset.stock, 10);
        const imagen = card.dataset.imagen;

        addToCart(id, nombre, precio, stock, imagen);
    }

    document.querySelectorAll('.producto-card').forEach(card => {
        card.addEventListener('click', () => agregarProductoDesdeTarjeta(card));
        card.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                agregarProductoDesdeTarjeta(card);
            }
        });
    });

    document.getElementById('btnPagar').addEventListener('click', procesarVenta);

    const searchInput = document.getElementById('searchProducto');
    const clearSearchButton = document.getElementById('clearSearch');
    const visibleProducts = document.getElementById('productosVisibles');
    const noResults = document.getElementById('sinResultados');

    function filtrarProductos() {
        const searchTerm = searchInput.value.trim().toLocaleLowerCase('es-MX');
        const cards = [...document.querySelectorAll('.producto-card')];
        let visibleCount = 0;

        cards.forEach(card => {
            const hayCoincidencia = `${card.dataset.nombre} ${card.dataset.categoria || ''}`
                .toLocaleLowerCase('es-MX')
                .includes(searchTerm);

            card.hidden = !hayCoincidencia;
            if (hayCoincidencia) visibleCount++;
        });

        visibleProducts.textContent = visibleCount;
        noResults.classList.toggle('visible', visibleCount === 0);
        productsGrid.classList.toggle('is-empty', visibleCount === 0);
        clearSearchButton.classList.toggle('visible', searchTerm.length > 0);
    }

    searchInput.addEventListener('input', filtrarProductos);
    clearSearchButton.addEventListener('click', () => {
        searchInput.value = '';
        filtrarProductos();
        searchInput.focus();
    });

    document.getElementById('btnVaciarCarrito').addEventListener('click', async () => {
        if (carrito.length === 0) return;

        const result = await Swal.fire({
            icon: 'warning',
            title: '¿Vaciar el carrito?',
            text: 'Se eliminarán todos los productos agregados.',
            showCancelButton: true,
            confirmButtonText: 'Sí, vaciar',
            cancelButtonText: 'Conservar',
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            customClass: { popup: 'swal-modern' }
        });

        if (result.isConfirmed) {
            carrito = [];
            saveCart();
            updateCartDisplay();
        }
    });

    filtrarProductos();
    loadCart();
    </script>
</body>
</html>