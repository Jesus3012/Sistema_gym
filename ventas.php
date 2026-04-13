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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f5f7fa;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }

        .ventas-container {
            padding: 20px;
        }

        .ventas-header {
            margin-bottom: 25px;
        }

        .ventas-header h1 {
            font-size: 1.8rem;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .ventas-header h1 i {
            color: #3b82f6;
        }

        .ventas-grid {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 25px;
        }

        .productos-section {
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .section-header {
            padding: 20px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .section-header h2 {
            font-size: 1.2rem;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-header h2 i {
            color: #3b82f6;
        }

        .search-box {
            margin-top: 15px;
            position: relative;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .search-box input {
            width: 100%;
            padding: 10px 12px 10px 38px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.9rem;
            transition: all 0.3s;
        }

        .search-box input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }

        .productos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            padding: 20px;
            max-height: 600px;
            overflow-y: auto;
        }

        .producto-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }

        .producto-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-color: #3b82f6;
        }

        .producto-imagen {
            width: 100%;
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
            background: #f8fafc;
            border-radius: 8px;
            overflow: hidden;
        }

        .producto-imagen img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .producto-imagen i {
            font-size: 3rem;
            color: #94a3b8;
        }

        .producto-nombre {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 5px;
            font-size: 0.9rem;
        }

        .producto-precio {
            color: #3b82f6;
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 8px;
        }

        .producto-stock {
            font-size: 0.75rem;
            color: #64748b;
        }

        .producto-stock.bajo {
            color: #ef4444;
        }

        .carrito-section {
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            height: fit-content;
            position: sticky;
            top: 20px;
        }

        .carrito-header {
            padding: 20px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .carrito-header h2 {
            font-size: 1.2rem;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .carrito-items {
            max-height: 400px;
            overflow-y: auto;
            padding: 15px;
        }

        .carrito-vacio {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }

        .carrito-vacio i {
            font-size: 3rem;
            margin-bottom: 10px;
        }

        .carrito-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            position: relative;
        }

        .carrito-item-imagen {
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            border-radius: 8px;
            overflow: hidden;
        }

        .carrito-item-imagen img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .carrito-item-imagen i {
            font-size: 1.5rem;
            color: #94a3b8;
        }

        .carrito-item-info {
            flex: 1;
        }

        .carrito-item-nombre {
            font-weight: 600;
            color: #1e293b;
            font-size: 0.85rem;
            margin-bottom: 4px;
        }

        .carrito-item-precio {
            color: #3b82f6;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .carrito-item-cantidad {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 5px;
        }

        .cantidad-btn {
            width: 24px;
            height: 24px;
            border: 1px solid #e2e8f0;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            transition: all 0.2s;
        }

        .cantidad-btn:hover {
            background: #f1f5f9;
            border-color: #3b82f6;
        }

        .carrito-item-cantidad span {
            min-width: 30px;
            text-align: center;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .carrito-item-total {
            font-weight: 700;
            color: #1e293b;
            font-size: 0.9rem;
            min-width: 60px;
            text-align: right;
        }

        .carrito-item-eliminar {
            color: #ef4444;
            cursor: pointer;
            padding: 5px;
            transition: all 0.2s;
        }

        .carrito-item-eliminar:hover {
            color: #dc2626;
            transform: scale(1.1);
        }

        .carrito-footer {
            padding: 20px;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
            border-radius: 0 0 16px 16px;
        }

        .carrito-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            font-size: 1.2rem;
            font-weight: 700;
        }

        .carrito-total span:last-child {
            color: #3b82f6;
            font-size: 1.4rem;
        }

        .cliente-select {
            margin-bottom: 15px;
        }

        .cliente-select label {
            display: block;
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 5px;
        }

        .cliente-select select {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.9rem;
        }

        .btn-pagar {
            width: 100%;
            padding: 12px;
            background: #3b82f6;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-pagar:hover {
            background: #2563eb;
            transform: translateY(-2px);
        }

        .btn-pagar:disabled {
            background: #94a3b8;
            cursor: not-allowed;
            transform: none;
        }

        @media (max-width: 968px) {
            .ventas-grid {
                grid-template-columns: 1fr;
            }
            
            .carrito-section {
                position: static;
            }
        }

        /* Estilos para el modal personalizado */
        .custom-modal-content {
            border-radius: 20px;
        }
        
        .modal-header-solid {
            background: #1e293b;
            color: white;
            padding: 20px;
            border-radius: 20px 20px 0 0;
        }
        
        .modal-body-solid {
            padding: 25px;
            background: white;
        }
        
        .modal-footer-solid {
            padding: 15px 25px;
            background: #f8fafc;
            border-radius: 0 0 20px 20px;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="ventas-container">
            <div class="ventas-header">
                <h1>
                    Venta de Productos
                </h1>
            </div>

            <div class="ventas-grid">
                <div class="productos-section">
                    <div class="section-header">
                        <h2>
                            <i class="fas fa-box"></i>
                            Productos Disponibles
                        </h2>
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="searchProducto" placeholder="Buscar producto...">
                        </div>
                    </div>
                    <div class="productos-grid" id="productosGrid">
                        <?php foreach ($productos as $producto): ?>
                            <div class="producto-card" data-id="<?php echo $producto['id']; ?>" data-nombre="<?php echo htmlspecialchars($producto['nombre']); ?>" data-precio="<?php echo $producto['precio_venta']; ?>" data-stock="<?php echo $producto['stock']; ?>" data-imagen="<?php echo $producto['foto']; ?>">
                                <div class="producto-imagen">
                                    <?php if (!empty($producto['foto']) && file_exists($producto['foto'])): ?>
                                        <img src="<?php echo htmlspecialchars($producto['foto']); ?>" alt="<?php echo htmlspecialchars($producto['nombre']); ?>">
                                    <?php else: ?>
                                        <i class="fas fa-box-open"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="producto-nombre"><?php echo htmlspecialchars($producto['nombre']); ?></div>
                                <div class="producto-precio">$<?php echo number_format($producto['precio_venta'], 2); ?></div>
                                <div class="producto-stock <?php echo $producto['stock'] <= $producto['stock_minimo'] ? 'bajo' : ''; ?>">
                                    Stock: <?php echo $producto['stock']; ?> unidades
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="carrito-section">
                    <div class="carrito-header">
                        <h2>
                            <i class="fas fa-shopping-cart"></i>
                            Carrito de Compras
                        </h2>
                    </div>
                    <div class="carrito-items" id="carritoItems">
                        <div class="carrito-vacio">
                            <i class="fas fa-shopping-basket"></i>
                            <p>No hay productos en el carrito</p>
                            <small>Haz clic en un producto para agregarlo</small>
                        </div>
                    </div>
                    <div class="carrito-footer">
                        <div class="cliente-select">
                            <label><i class="fas fa-user"></i> Cliente (Opcional)</label>
                            <select id="clienteId">
                                <option value="">Venta al publico (sin cliente)</option>
                                <?php while ($cliente = $result_clientes->fetch_assoc()): ?>
                                    <option value="<?php echo $cliente['id']; ?>">
                                        <?php echo htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apellido']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="carrito-total">
                            <span>Total:</span>
                            <span id="totalCarrito">$0.00</span>
                        </div>
                        <button class="btn-pagar" id="btnPagar">
                            <i class="fas fa-credit-card"></i>
                            Proceder al Pago
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    let carrito = [];

    function loadCart() {
        const savedCart = localStorage.getItem('carritoVentas');
        if (savedCart) {
            carrito = JSON.parse(savedCart);
            updateCartDisplay();
        }
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
        
        if (carrito.length === 0) {
            carritoContainer.innerHTML = `
                <div class="carrito-vacio">
                    <i class="fas fa-shopping-basket"></i>
                    <p>No hay productos en el carrito</p>
                    <small>Haz clic en un producto para agregarlo</small>
                </div>
            `;
            totalSpan.textContent = '$0.00';
            document.getElementById('btnPagar').disabled = true;
            return;
        }
        
        let total = 0;
        let html = '';
        
        carrito.forEach(item => {
            const subtotal = item.precio * item.cantidad;
            total += subtotal;
            
            html += `
                <div class="carrito-item">
                    <div class="carrito-item-imagen">
                        ${item.imagen && item.imagen !== 'null' ? 
                            '<img src="' + item.imagen + '" alt="' + item.nombre + '">' : 
                            '<i class="fas fa-box-open"></i>'
                        }
                    </div>
                    <div class="carrito-item-info">
                        <div class="carrito-item-nombre">${item.nombre}</div>
                        <div class="carrito-item-precio">$${item.precio.toFixed(2)} c/u</div>
                        <div class="carrito-item-cantidad">
                            <button class="cantidad-btn" onclick="updateQuantity(${item.id}, -1)">-</button>
                            <span>${item.cantidad}</span>
                            <button class="cantidad-btn" onclick="updateQuantity(${item.id}, 1)">+</button>
                        </div>
                    </div>
                    <div class="carrito-item-total">
                        $${subtotal.toFixed(2)}
                    </div>
                    <div class="carrito-item-eliminar" onclick="removeFromCart(${item.id})">
                        <i class="fas fa-trash-alt"></i>
                    </div>
                </div>
            `;
        });
        
        carritoContainer.innerHTML = html;
        totalSpan.textContent = '$' + total.toFixed(2);
        document.getElementById('btnPagar').disabled = false;
    }

    async function procesarVenta() {
        if (carrito.length === 0) {
            Swal.fire({
                icon: 'warning',
                title: 'Carrito Vacio',
                text: 'Agrega productos al carrito antes de pagar',
                confirmButtonColor: '#3b82f6',
                confirmButtonText: 'Aceptar'
            });
            return;
        }
        
        const clienteId = document.getElementById('clienteId').value;
        const clienteNombre = clienteId ? document.getElementById('clienteId').options[document.getElementById('clienteId').selectedIndex].text : 'Venta al publico';
        const total = carrito.reduce((sum, item) => sum + (item.precio * item.cantidad), 0);
        
        // Modal de método de pago
        const metodoPago = await Swal.fire({
            title: 'Método de Pago',
            width: '400px',
            padding: '1rem',
            html: `
                <div style="text-align: center;">
                    <div style="background: #f0fdf4; padding: 12px; border-radius: 10px; margin-bottom: 15px;">
                        <div style="font-size: 0.75rem; color: #166534;">Total a pagar</div>
                        <div style="font-size: 1.6rem; font-weight: bold; color: #16a34a;">$${total.toFixed(2)}</div>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <button id="btn-efectivo" style="padding: 12px; background: #3b82f6; color: white; border: none; border-radius: 10px; cursor: pointer; font-size: 0.95rem; font-weight: 600;">
                            Efectivo
                        </button>
                        <button id="btn-tarjeta" style="padding: 12px; background: #8b5cf6; color: white; border: none; border-radius: 10px; cursor: pointer; font-size: 0.95rem; font-weight: 600;">
                            Tarjeta
                        </button>
                        <button id="btn-transferencia" style="padding: 12px; background: #10b981; color: white; border: none; border-radius: 10px; cursor: pointer; font-size: 0.95rem; font-weight: 600;">
                            Transferencia
                        </button>
                    </div>
                </div>
            `,
            showConfirmButton: false,
            showCancelButton: true,
            cancelButtonText: 'Cancelar',
            cancelButtonColor: '#ef4444',
            background: 'white',
            customClass: {
                popup: 'metodo-pago-modal'
            },
            didOpen: () => {
                const style = document.createElement('style');
                style.textContent = `
                    .metodo-pago-modal {
                        max-height: 90vh !important;
                        overflow-y: hidden !important;
                    }
                    .metodo-pago-modal .swal2-html-container {
                        overflow: visible !important;
                        padding: 0 !important;
                    }
                `;
                document.head.appendChild(style);
                
                document.getElementById('btn-efectivo').onclick = () => { Swal.clickConfirm(); window.metodoSeleccionado = 'efectivo'; };
                document.getElementById('btn-tarjeta').onclick = () => { Swal.clickConfirm(); window.metodoSeleccionado = 'tarjeta'; };
                document.getElementById('btn-transferencia').onclick = () => { Swal.clickConfirm(); window.metodoSeleccionado = 'transferencia'; };
            },
            preConfirm: () => window.metodoSeleccionado
        });
        
        if (!metodoPago.value) return;
        
        let montoRecibido = total;
        if (metodoPago.value === 'efectivo') {
            const pago = await Swal.fire({
                title: 'Pago en Efectivo',
                width: '420px',
                padding: '1rem',
                showConfirmButton: true,
                showCancelButton: true,
                confirmButtonText: 'Aceptar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#3b82f6',
                cancelButtonColor: '#ef4444',
                html: `
                    <div style="text-align: center;">
                        <!-- Mostrar total -->
                        <div style="background: #f8fafc; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                            <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 4px;">Total a pagar</div>
                            <div style="font-size: 1.8rem; font-weight: bold; color: #1e293b;">$${total.toFixed(2)}</div>
                        </div>
                        
                        <!-- Input para monto recibido -->
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-size: 0.8rem; color: #1e293b; margin-bottom: 8px; font-weight: 500; text-align: left;">
                                Monto recibido
                            </label>
                            <div style="position: relative;">
                                <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 1rem; font-weight: bold; color: #64748b;">$</span>
                                <input type="number" id="monto-recibido" 
                                    value="${total}" 
                                    step="0.01" 
                                    min="${total}"
                                    style="width: 100%; padding: 12px 12px 12px 28px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 1.1rem; text-align: left; box-sizing: border-box; outline: none; transition: all 0.2s;"
                                    onfocus="this.style.borderColor='#3b82f6'; this.style.boxShadow='0 0 0 2px rgba(59,130,246,0.1)';"
                                    onblur="this.style.borderColor='#e2e8f0'; this.style.boxShadow='none';">
                            </div>
                        </div>
                        
                        <!-- Preview del cambio -->
                        <div id="cambio-preview" style="background: #f1f5f9; padding: 12px; border-radius: 8px;">
                            <span style="color: #64748b; font-size: 0.85rem;">Cambio para el cliente:</span>
                            <div style="font-size: 1.4rem; font-weight: bold; color: #3b82f6; margin-top: 4px;">$0.00</div>
                        </div>
                    </div>
                `,
                didOpen: () => {
                    // Eliminar scroll del modal
                    const modal = Swal.getPopup();
                    if (modal) {
                        modal.style.overflow = 'visible';
                        modal.style.maxHeight = 'none';
                        modal.style.height = 'auto';
                    }
                    
                    const htmlContainer = Swal.getHtmlContainer();
                    if (htmlContainer) {
                        htmlContainer.style.overflow = 'visible';
                        htmlContainer.style.padding = '0';
                        htmlContainer.style.height = 'auto';
                    }
                    
                    // Obtener elementos
                    const input = document.getElementById('monto-recibido');
                    const preview = document.getElementById('cambio-preview');
                    
                    if (!input || !preview) return;
                    
                    // Enfocar el input
                    setTimeout(() => {
                        input.focus();
                        input.select();
                    }, 100);
                    
                    // Función para actualizar el cambio
                    const actualizarCambio = () => {
                        let recibido = parseFloat(input.value) || 0;
                        let cambio = recibido - total;
                        
                        if (cambio >= 0) {
                            preview.innerHTML = `
                                <span style="color: #64748b; font-size: 0.85rem;">Cambio para el cliente:</span>
                                <div style="font-size: 1.4rem; font-weight: bold; color: #16a34a; margin-top: 4px;">$${cambio.toFixed(2)}</div>
                            `;
                        } else {
                            preview.innerHTML = `
                                <span style="color: #64748b; font-size: 0.85rem;">Cambio para el cliente:</span>
                                <div style="font-size: 1.4rem; font-weight: bold; color: #ef4444; margin-top: 4px;">$0.00</div>
                                <div style="color: #ef4444; font-size: 0.7rem; margin-top: 4px;">Faltan $${Math.abs(cambio).toFixed(2)}</div>
                            `;
                        }
                    };
                    
                    // Eventos del input
                    input.addEventListener('input', actualizarCambio);
                    input.addEventListener('change', actualizarCambio);
                    
                    // Actualizar cambio inicial
                    actualizarCambio();
                    
                    // Enter para confirmar
                    input.addEventListener('keypress', (e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            const recibido = parseFloat(input.value);
                            if (!isNaN(recibido) && recibido >= total) {
                                Swal.clickConfirm();
                            } else {
                                Swal.showValidationMessage('El monto debe ser mayor o igual al total');
                            }
                        }
                    });
                },
                preConfirm: () => {
                    const input = document.getElementById('monto-recibido');
                    if (!input) return false;
                    
                    const recibido = parseFloat(input.value);
                    if (isNaN(recibido)) {
                        Swal.showValidationMessage('Ingrese un monto válido');
                        return false;
                    }
                    if (recibido < total) {
                        Swal.showValidationMessage('El monto debe ser mayor o igual al total');
                        return false;
                    }
                    return recibido;
                }
            });
            
            if (!pago.value) return;
            montoRecibido = pago.value;
        }
        
        const cambio = metodoPago.value === 'efectivo' ? (montoRecibido - total) : 0;
        
        // Variable para controlar si ya se confirmó
        let confirmButtonClicked = false;
        
        const confirmacion = await Swal.fire({
            title: 'Confirmar Venta',
            html: `
                <div style="text-align: left;">
                    <div style="background: #1e293b; padding: 15px; border-radius: 12px; color: white; margin-bottom: 15px; text-align: center;">
                        <div style="font-size: 0.8rem;">Total a pagar</div>
                        <div style="font-size: 1.8rem; font-weight: bold;">$${total.toFixed(2)}</div>
                    </div>
                    <div style="display: grid; gap: 8px;">
                        <div style="display: flex; justify-content: space-between; padding: 10px; background: #f8fafc; border-radius: 8px;">
                            <span>Metodo de pago</span>
                            <span style="font-weight: 600; text-transform: capitalize;">${metodoPago.value}</span>
                        </div>
                        ${metodoPago.value === 'efectivo' ? `
                        <div style="display: flex; justify-content: space-between; padding: 10px; background: #f8fafc; border-radius: 8px;">
                            <span>Recibido</span>
                            <span style="font-weight: 600;">$${montoRecibido.toFixed(2)}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; padding: 10px; background: #f0fdf4; border-radius: 8px;">
                            <span>Cambio</span>
                            <span style="font-weight: 600;">$${cambio.toFixed(2)}</span>
                        </div>
                        ` : ''}
                        ${clienteId ? `
                        <div style="display: flex; justify-content: space-between; padding: 10px; background: #f8fafc; border-radius: 8px;">
                            <span>Cliente</span>
                            <span style="font-weight: 600;">${clienteNombre}</span>
                        </div>
                        ` : ''}
                    </div>
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Confirmar Venta',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#10b981',
            cancelButtonColor: '#ef4444',
            didOpen: () => {
                // Obtener el botón de confirmar
                const confirmButton = Swal.getConfirmButton();
                
                // Deshabilitar el botón después del primer click
                confirmButton.addEventListener('click', (e) => {
                    if (confirmButtonClicked) {
                        e.preventDefault();
                        e.stopPropagation();
                        return false;
                    }
                    confirmButtonClicked = true;
                    confirmButton.disabled = true;
                    confirmButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';
                });
            }
        });
        
        if (!confirmacion.isConfirmed) return;
        
        // Mostrar loading
        Swal.fire({
            title: 'Procesando venta',
            text: 'Por favor espere',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        try {
            const response = await fetch('procesar_venta.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    cliente_id: clienteId || null,
                    items: carrito,
                    total: total,
                    metodo_pago: metodoPago.value,
                    monto_recibido: metodoPago.value === 'efectivo' ? montoRecibido : null,
                    cliente_nombre: clienteNombre
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                let logoHtml = '';
                const gymLogo = '<?php echo addslashes($gym_logo); ?>';
                const gymNombre = '<?php echo addslashes($gym_nombre); ?>';
                
                if (gymLogo && gymLogo !== '') {
                    logoHtml = '<img src="' + gymLogo + '" alt="' + gymNombre + '" style="max-width: 60px; max-height: 60px; margin-bottom: 5px;">';
                }
                
                let ticketHtml = `
                    <div style="text-align: center; font-family: 'Courier New', monospace; max-width: 300px; margin: 0 auto;">
                        ${logoHtml}
                        <div style="font-weight: bold; font-size: 16px;">${gymNombre}</div>
                        <div style="font-size: 11px; margin: 3px 0;">Ticket de Venta #${result.venta_id}</div>
                        <div style="font-size: 11px; margin: 3px 0;">${new Date().toLocaleString()}</div>
                        <hr style="border: 1px dashed #000; margin: 8px 0;">
                `;
                
                carrito.forEach(item => {
                    ticketHtml += `
                        <div style="text-align: left; margin: 5px 0;">
                            <div style="font-weight: bold;">${item.nombre} x${item.cantidad}</div>
                            <div style="text-align: right;">$${(item.precio * item.cantidad).toFixed(2)}</div>
                        </div>
                    `;
                });
                
                ticketHtml += `
                        <hr style="border: 1px dashed #000; margin: 8px 0;">
                        <div style="display: flex; justify-content: space-between; margin: 5px 0;">
                            <strong>TOTAL</strong>
                            <strong>$${total.toFixed(2)}</strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin: 3px 0;">
                            <span>Metodo:</span>
                            <span style="text-transform: capitalize;">${metodoPago.value}</span>
                        </div>
                        ${metodoPago.value === 'efectivo' ? `
                        <div style="display: flex; justify-content: space-between; margin: 3px 0;">
                            <span>Recibido:</span>
                            <span>$${montoRecibido.toFixed(2)}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin: 3px 0;">
                            <span>Cambio:</span>
                            <span>$${cambio.toFixed(2)}</span>
                        </div>
                        ` : ''}
                        ${clienteId ? `
                        <div style="display: flex; justify-content: space-between; margin: 3px 0;">
                            <span>Cliente:</span>
                            <span>${clienteNombre}</span>
                        </div>
                        ` : ''}
                        <hr style="border: 1px dashed #000; margin: 8px 0;">
                        <div style="margin-top: 8px;">
                            <div>Gracias por su compra</div>
                            <div style="font-size: 9px; color: #666;">Este ticket es su comprobante de pago</div>
                        </div>
                    </div>
                `;
                
                await Swal.fire({
                    title: 'Venta Completada',
                    html: ticketHtml,
                    icon: 'success',
                    width: '450px',
                    confirmButtonText: 'Descargar Ticket PDF',
                    showCancelButton: true,
                    cancelButtonText: 'Cerrar',
                    confirmButtonColor: '#3b82f6',
                    cancelButtonColor: '#94a3b8'
                }).then((resultModal) => {
                    if (resultModal.isConfirmed && result.ticket_url) {
                        window.open(result.ticket_url, '_blank');
                    }
                });
                
                carrito = [];
                saveCart();
                updateCartDisplay();
                location.reload();
            } else {
                throw new Error(result.message || 'Error al procesar la venta');
            }
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: error.message,
                confirmButtonColor: '#3b82f6'
            });
        }
    }

    document.querySelectorAll('.producto-card').forEach(card => {
        card.addEventListener('click', () => {
            const id = parseInt(card.dataset.id);
            const nombre = card.dataset.nombre;
            const precio = parseFloat(card.dataset.precio);
            const stock = parseInt(card.dataset.stock);
            const imagen = card.dataset.imagen;
            
            addToCart(id, nombre, precio, stock, imagen);
        });
    });
    
    document.getElementById('btnPagar').addEventListener('click', procesarVenta);
    
    document.getElementById('searchProducto').addEventListener('input', (e) => {
        const searchTerm = e.target.value.toLowerCase();
        const cards = document.querySelectorAll('.producto-card');
        
        cards.forEach(card => {
            const nombre = card.dataset.nombre.toLowerCase();
            if (nombre.includes(searchTerm)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    });
    
    loadCart();
    </script>
</body>
</html>