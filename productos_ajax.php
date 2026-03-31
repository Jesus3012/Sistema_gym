<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit();
}

require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action == 'list') {
    $busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
    $categoria = isset($_GET['categoria']) ? (int)$_GET['categoria'] : 0;
    $estado = isset($_GET['estado']) ? $_GET['estado'] : 'todos';
    $pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
    $registros_por_pagina = 10;
    $offset = ($pagina - 1) * $registros_por_pagina;
    
    $where = [];
    $params = [];
    $types = "";
    
    if (!empty($busqueda)) {
        $where[] = "(p.nombre LIKE ? OR p.descripcion LIKE ?)";
        $params[] = "%$busqueda%";
        $params[] = "%$busqueda%";
        $types .= "ss";
    }
    
    if ($categoria > 0) {
        $where[] = "p.categoria_id = ?";
        $params[] = $categoria;
        $types .= "i";
    }
    
    if ($estado !== 'todos') {
        $where[] = "p.estado = ?";
        $params[] = $estado;
        $types .= "s";
    }
    
    $where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
    
    // Contar total
    $count_sql = "SELECT COUNT(*) as total FROM productos p $where_sql";
    $count_stmt = $conn->prepare($count_sql);
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_registros = $count_result->fetch_assoc()['total'];
    $total_paginas = ceil($total_registros / $registros_por_pagina);
    $count_stmt->close();
    
    // Obtener productos
    $sql = "SELECT p.*, c.nombre as categoria_nombre, prov.nombre as proveedor_nombre 
            FROM productos p 
            LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
            LEFT JOIN proveedores prov ON p.proveedor_id = prov.id 
            $where_sql 
            ORDER BY p.fecha_registro DESC 
            LIMIT ? OFFSET ?";
    
    $params[] = $registros_por_pagina;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $productos = [];
    while ($row = $result->fetch_assoc()) {
        $productos[] = $row;
    }
    $stmt->close();
    
    // Generar HTML de la tabla
    ?>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Foto</th>
                    <th>Producto</th>
                    <th>Categoría</th>
                    <th>Proveedor</th>
                    <th>P. Compra</th>
                    <th>P. Venta</th>
                    <th>Stock</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($productos as $producto): ?>
                <tr>
                    <td>
                        <?php if ($producto['foto'] && file_exists($producto['foto'])): ?>
                            <img src="<?php echo htmlspecialchars($producto['foto']); ?>" class="producto-imagen">
                        <?php else: ?>
                            <div style="width: 50px; height: 50px; background: #f0f2f5; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-image" style="color: #adb5bd;"></i>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?php echo htmlspecialchars($producto['nombre']); ?></strong>
                        <?php if ($producto['descripcion']): ?>
                            <br><small><?php echo htmlspecialchars(substr($producto['descripcion'], 0, 40)); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($producto['proveedor_nombre'] ?? 'N/A'); ?></td>
                    <td>$<?php echo number_format($producto['precio_compra'], 2); ?></td>
                    <td><strong>$<?php echo number_format($producto['precio_venta'], 2); ?></strong></td>
                    <td>
                        <?php if ($producto['stock'] <= $producto['stock_minimo']): ?>
                            <span class="stock-bajo">
                                <i class="fas fa-exclamation-triangle"></i> <?php echo $producto['stock']; ?>
                            </span>
                            <br><small>Mín: <?php echo $producto['stock_minimo']; ?></small>
                        <?php else: ?>
                            <span class="stock-normal"><?php echo $producto['stock']; ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-<?php echo $producto['estado'] == 'activo' ? 'success' : 'danger'; ?>">
                            <?php echo ucfirst($producto['estado']); ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <button onclick="editProducto(<?php echo $producto['id']; ?>)" class="action-btn btn-primary" style="background: #1e3c72;">
                                <i class="fas fa-edit"></i> Editar
                            </button>
                            <button onclick="openStockModal(<?php echo $producto['id']; ?>, '<?php echo addslashes($producto['nombre']); ?>')" class="action-btn btn-success">
                                <i class="fas fa-plus-circle"></i> +Stock
                            </button>
                            <button onclick="openAjusteModal(<?php echo $producto['id']; ?>, '<?php echo addslashes($producto['nombre']); ?>', <?php echo $producto['precio_venta']; ?>, <?php echo $producto['precio_compra']; ?>, <?php echo $producto['stock']; ?>, <?php echo $producto['stock_minimo']; ?>)" class="action-btn btn-warning">
                                <i class="fas fa-sliders-h"></i> Ajuste
                            </button>
                            <?php if ($producto['estado'] == 'activo'): ?>
                                <button onclick="toggleStatus(<?php echo $producto['id']; ?>, 'inactivo')" class="action-btn btn-danger">
                                    <i class="fas fa-ban"></i>
                                </button>
                            <?php else: ?>
                                <button onclick="toggleStatus(<?php echo $producto['id']; ?>, 'activo')" class="action-btn btn-success">
                                    <i class="fas fa-check-circle"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($total_paginas > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
            <span onclick="cambiarPagina(<?php echo $i; ?>)" class="page-link <?php echo $i == $pagina ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </span>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    
    <script>
        function cambiarPagina(pagina) {
            const busqueda = document.getElementById('searchInput').value;
            const categoria = document.getElementById('categoriaFilter').value;
            const estado = document.getElementById('estadoFilter').value;
            
            fetch(`productos_ajax.php?action=list&busqueda=${encodeURIComponent(busqueda)}&categoria=${categoria}&estado=${estado}&pagina=${pagina}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('tablaProductos').innerHTML = data;
                });
        }
    </script>
    <?php
}
elseif ($action == 'get') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    
    if ($id > 0) {
        $stmt = $conn->prepare("SELECT * FROM productos WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $producto = $result->fetch_assoc();
        
        if ($producto) {
            echo json_encode(['success' => true, 'producto' => $producto]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Producto no encontrado']);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => 'ID inválido']);
    }
}
?>