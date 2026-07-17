<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Archivo: ventas.php
// BUILD: FLUJO_RAPIDO_20260716_FINAL
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
                                <option value="" data-email="">Venta al público (sin cliente)</option>
                                <?php while ($cliente = $result_clientes->fetch_assoc()): ?>
                                    <option
                                        value="<?php echo (int) $cliente['id']; ?>"
                                        data-email="<?php echo htmlspecialchars((string) ($cliente['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apellido']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                            <small id="clienteCorreoEstado" style="display:block;margin-top:7px;color:#64748b;font-size:.76rem;line-height:1.35;">
                                En ventas al público no se envía correo.
                            </small>
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

    async function enviarTicketCliente(ventaId, clienteEmail) {
        if (!ventaId || !clienteEmail) {
            return {
                intentado: false,
                enviado: false,
                message: 'El cliente no tiene un correo electrónico registrado.'
            };
        }

        try {
            const response = await fetch('includes/reenviar_ticket.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    venta_id: Number(ventaId),
                    email: clienteEmail
                })
            });

            const text = await response.text();
            let data;

            try {
                data = JSON.parse(text);
            } catch (error) {
                throw new Error('El servidor no devolvió una respuesta válida al enviar el ticket.');
            }

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'No se pudo enviar el ticket por correo.');
            }

            return {
                intentado: true,
                enviado: true,
                message: data.message || `Ticket enviado correctamente a ${clienteEmail}.`
            };
        } catch (error) {
            console.error('Envío automático del ticket:', error);
            return {
                intentado: true,
                enviado: false,
                message: error.message || 'No se pudo enviar el ticket por correo.'
            };
        }
    }

    async function procesarVenta() {
        if (carrito.length === 0) {
            await Swal.fire('Carrito vacío', 'Agrega productos antes de pagar.', 'warning');
            return;
        }

        const payButton = document.getElementById('btnPagar');
        const payButtonOriginal = payButton.innerHTML;
        const clienteSelect = document.getElementById('clienteId');
        const clienteId = clienteSelect.value;
        const clienteOption = clienteSelect.options[clienteSelect.selectedIndex];
        const clienteNombre = clienteId
            ? clienteOption.text.trim()
            : 'Venta al público';
        const clienteEmail = clienteId
            ? String(clienteOption.dataset.email || '').trim()
            : '';
        const total = carrito.reduce((sum, item) => sum + item.precio * item.cantidad, 0);
        const totalArticulos = carrito.reduce(
            (sum, item) => sum + Number(item.cantidad || 0),
            0
        );
        const metodo = await seleccionarMetodoPago(total);

        if (!metodo) return;

        let installments = 1;
        let montoRecibido = total;
        let cambio = 0;
        let mpPayment = null;
        const esEfectivo = metodo === 'efectivo';

        const confirmacion = await Swal.fire({
            title: 'Confirmar venta',
            width: '520px',
            html: `
                <div style="font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;text-align:left;">
                    ${esEfectivo ? `
                        <div style="display:grid;gap:9px;margin-bottom:11px;">
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:18px;padding:13px 16px;border:1px solid #dbe3ef;border-radius:14px;background:#f8fafc;">
                                <div>
                                    <span style="display:block;font-size:.72rem;font-weight:800;letter-spacing:.04em;color:#64748b;">TOTAL A COBRAR</span>
                                    <small style="display:block;margin-top:2px;color:#94a3b8;font-size:.72rem;">Importe de la venta</small>
                                </div>
                                <strong style="white-space:nowrap;color:#1e3a8a;font-size:clamp(1.55rem,5vw,2rem);line-height:1;">${formatMoney(total)}</strong>
                            </div>

                            <label for="confirm-monto-recibido" style="display:flex;align-items:center;justify-content:space-between;gap:18px;padding:12px 16px;border:2px solid #bfdbfe;border-radius:14px;background:#fff;cursor:text;">
                                <div>
                                    <span style="display:block;font-size:.72rem;font-weight:800;letter-spacing:.04em;color:#1e3a8a;">PAGÓ CON</span>
                                    <small style="display:block;margin-top:2px;color:#64748b;font-size:.72rem;">Monto recibido</small>
                                </div>
                                <div style="display:flex;align-items:center;justify-content:flex-end;min-width:0;">
                                    <span style="font-size:1.35rem;font-weight:900;color:#1e3a8a;line-height:1;">$</span>
                                    <input
                                        type="text"
                                        id="confirm-monto-recibido"
                                        value="${total.toFixed(2)}"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        autofocus
                                        aria-label="Monto recibido"
                                        style="width:145px;max-width:42vw;min-width:0;border:0;outline:0;background:transparent;color:#0f172a;text-align:right;font-size:clamp(1.45rem,5vw,1.85rem);font-weight:900;line-height:1;padding:0 0 0 5px;"
                                    >
                                </div>
                            </label>

                            <div id="confirm-cambio-card" style="padding:12px 16px;border-radius:14px;background:#ecfdf5;border:1px solid #a7f3d0;text-align:center;">
                                <span id="confirm-cambio-label" style="display:block;font-size:.72rem;font-weight:900;letter-spacing:.04em;color:#047857;margin-bottom:2px;">CAMBIO A ENTREGAR</span>
                                <strong id="confirm-cambio-valor" style="display:block;font-size:clamp(2rem,7vw,2.55rem);line-height:1.05;color:#047857;">${formatMoney(0)}</strong>
                            </div>
                        </div>
                    ` : `
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:18px;padding:14px 17px;border:1px solid #dbe3ef;border-radius:14px;background:#f8fafc;margin-bottom:11px;">
                            <div>
                                <span style="display:block;font-size:.72rem;font-weight:800;letter-spacing:.04em;color:#64748b;">TOTAL A COBRAR</span>
                                <small style="display:block;margin-top:2px;color:#94a3b8;font-size:.72rem;">Importe de la venta</small>
                            </div>
                            <strong style="white-space:nowrap;color:#1e3a8a;font-size:clamp(1.65rem,5vw,2.1rem);line-height:1;">${formatMoney(total)}</strong>
                        </div>
                    `}

                    <div style="border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;background:#fff;">
                        <div style="display:flex;justify-content:space-between;gap:16px;padding:10px 13px;border-bottom:1px solid #e2e8f0;font-size:.88rem;">
                            <span style="color:#64748b;">Método</span>
                            <strong style="color:#0f172a;text-align:right;">${nombreMetodoPago(metodo, installments)}</strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;gap:16px;padding:10px 13px;${clienteId ? 'border-bottom:1px solid #e2e8f0;' : ''}font-size:.88rem;">
                            <span style="color:#64748b;">Productos</span>
                            <strong style="color:#0f172a;text-align:right;">${totalArticulos} ${totalArticulos === 1 ? 'artículo' : 'artículos'}</strong>
                        </div>
                        ${clienteId ? `
                            <div style="display:flex;justify-content:space-between;gap:16px;padding:10px 13px;font-size:.88rem;">
                                <span style="color:#64748b;">Cliente</span>
                                <strong style="color:#0f172a;text-align:right;">${escapeHtml(clienteNombre)}</strong>
                            </div>
                        ` : ''}
                    </div>

                    ${clienteId && clienteEmail ? `
                        <div style="margin-top:9px;padding:9px 11px;border-radius:11px;background:#eff6ff;color:#1d4ed8;font-size:.78rem;line-height:1.35;text-align:center;overflow-wrap:anywhere;">
                            <i class="fas fa-envelope" style="margin-right:5px;"></i>
                            El ticket se enviará automáticamente a <strong>${escapeHtml(clienteEmail)}</strong>
                        </div>
                    ` : ''}
                    ${clienteId && !clienteEmail ? `
                        <div style="margin-top:9px;padding:9px 11px;border-radius:11px;background:#fff7ed;color:#c2410c;font-size:.78rem;line-height:1.35;text-align:center;">
                            <i class="fas fa-triangle-exclamation" style="margin-right:5px;"></i>
                            Este cliente no tiene correo registrado.
                        </div>
                    ` : ''}
                    ${metodo === 'tarjeta_credito' ? `
                        <div style="margin-top:9px;font-size:.76rem;color:#64748b;text-align:center;">
                            Las mensualidades disponibles se seleccionan en la terminal.
                        </div>
                    ` : ''}
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: metodo.startsWith('tarjeta_') ? 'Enviar a terminal' : 'Confirmar venta',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#10b981',
            cancelButtonColor: '#dc2626',
            focusConfirm: !esEfectivo,
            customClass: { popup: 'swal-modern' },
            didOpen: () => {
                if (!esEfectivo) return;

                const input = document.getElementById('confirm-monto-recibido');
                const card = document.getElementById('confirm-cambio-card');
                const label = document.getElementById('confirm-cambio-label');
                const valueNode = document.getElementById('confirm-cambio-valor');

                const actualizarCambio = () => {
                    const recibido = Number.parseFloat(input.value.replace(',', '.')) || 0;
                    const diferencia = recibido - total;
                    const esValido = diferencia >= 0;

                    label.textContent = esValido ? 'CAMBIO A ENTREGAR' : 'FALTA POR RECIBIR';
                    valueNode.textContent = formatMoney(Math.abs(diferencia));
                    card.style.background = esValido ? '#ecfdf5' : '#fef2f2';
                    card.style.borderColor = esValido ? '#a7f3d0' : '#fecaca';
                    label.style.color = esValido ? '#047857' : '#dc2626';
                    valueNode.style.color = esValido ? '#047857' : '#dc2626';
                };

                input.addEventListener('input', () => {
                    input.value = input.value.replace(/[^0-9.,]/g, '');
                    actualizarCambio();
                });
                actualizarCambio();

                // SweetAlert mueve el foco mientras termina su animación. Por eso
                // mantenemos seleccionado el monto durante los primeros instantes,
                // pero dejamos de hacerlo en cuanto el usuario empieza a escribir.
                let usuarioYaEscribio = false;

                const seleccionarMontoCompleto = () => {
                    if (usuarioYaEscribio || !document.body.contains(input)) return;

                    input.focus({ preventScroll: true });
                    input.setSelectionRange(0, input.value.length);
                };

                input.addEventListener('keydown', event => {
                    const teclasQueEscriben =
                        event.key.length === 1 ||
                        event.key === 'Backspace' ||
                        event.key === 'Delete';

                    if (teclasQueEscriben) usuarioYaEscribio = true;
                });

                input.addEventListener('input', () => {
                    usuarioYaEscribio = true;
                });

                // Evita que el primer clic quite la selección antes de escribir.
                input.addEventListener('pointerup', event => {
                    if (usuarioYaEscribio) return;
                    event.preventDefault();
                    seleccionarMontoCompleto();
                });

                // Se repite porque el gestor de foco de SweetAlert puede ejecutarse
                // después de didOpen y quitar la selección inicial.
                [0, 80, 180, 320].forEach(delay => {
                    window.setTimeout(seleccionarMontoCompleto, delay);
                });
            },
            preConfirm: () => {
                if (!esEfectivo) {
                    return { montoRecibido: total, cambio: 0 };
                }

                const recibido = Number.parseFloat(
                    document.getElementById('confirm-monto-recibido').value.replace(',', '.')
                );

                if (!Number.isFinite(recibido) || recibido < total) {
                    Swal.showValidationMessage('El monto recibido debe ser mayor o igual al total.');
                    return false;
                }

                return {
                    montoRecibido: recibido,
                    cambio: recibido - total
                };
            }
        });

        if (!confirmacion.isConfirmed) return;

        montoRecibido = Number(confirmacion.value?.montoRecibido ?? total);
        cambio = Number(confirmacion.value?.cambio ?? 0);

        payButton.disabled = true;
        payButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando venta...';

        try {
            if (metodo === 'tarjeta_debito' || metodo === 'tarjeta_credito') {
                const paymentType = metodo === 'tarjeta_credito'
                    ? 'credit_card'
                    : 'debit_card';
                const order = await crearOrdenPoint(total, paymentType);
                mpPayment = await esperarPagoPoint(order);

                if (!mpPayment) {
                    payButton.innerHTML = payButtonOriginal;
                    updateCartDisplay();
                    return;
                }

                installments = Math.max(1, Number(mpPayment.installments || 1));
            }

            const payload = {
                cliente_id: clienteId || null,
                items: carrito,
                total,
                metodo_pago: metodo.startsWith('tarjeta_') ? 'tarjeta' : metodo,
                monto_recibido: esEfectivo ? montoRecibido : null,
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

            if (clienteId && clienteEmail) {
                const envioTicket = await enviarTicketCliente(result.venta_id, clienteEmail);

                if (!envioTicket.enviado) {
                    console.warn('La venta se registró, pero el ticket no pudo enviarse:', envioTicket.message);
                }
            }

            carrito = [];
            saveCart();
            updateCartDisplay();

            await Swal.fire({
                icon: 'success',
                title: 'Venta exitosa',
                text: 'La venta se completó correctamente.',
                timer: 3000,
                timerProgressBar: true,
                showConfirmButton: false,
                allowOutsideClick: false,
                allowEscapeKey: false,
                customClass: { popup: 'swal-modern' }
            });

            // Recarga la misma página para actualizar productos e inventario.
            window.location.reload();
        } catch (error) {
            payButton.innerHTML = payButtonOriginal;
            updateCartDisplay();

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

    const clienteSelectCorreo = document.getElementById('clienteId');
    const clienteCorreoEstado = document.getElementById('clienteCorreoEstado');

    function actualizarEstadoCorreoCliente() {
        const option = clienteSelectCorreo.options[clienteSelectCorreo.selectedIndex];
        const clienteId = clienteSelectCorreo.value;
        const email = String(option?.dataset?.email || '').trim();

        if (!clienteId) {
            clienteCorreoEstado.textContent = 'En ventas al público no se envía correo.';
            clienteCorreoEstado.style.color = '#64748b';
            return;
        }

        if (email) {
            clienteCorreoEstado.textContent = `Al completar la venta, el ticket PDF se enviará a ${email}.`;
            clienteCorreoEstado.style.color = '#047857';
            return;
        }

        clienteCorreoEstado.textContent = 'Este cliente no tiene correo registrado; la venta se guardará sin envío.';
        clienteCorreoEstado.style.color = '#c2410c';
    }

    clienteSelectCorreo.addEventListener('change', actualizarEstadoCorreoCliente);
    actualizarEstadoCorreoCliente();

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