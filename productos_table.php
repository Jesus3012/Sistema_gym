<?php
if (!isset($productos) || !isset($total_paginas) || !isset($pagina_actual) || !isset($busqueda) || !isset($categoria_filtro) || !isset($estado_filtro)) {
    return;
}

// Obtener la cantidad de registros por página (por defecto 10)
$registros_por_pagina = isset($_GET['limite']) ? (int)$_GET['limite'] : 10;
?>

<div class="table-responsive">
    <table class="table table-hover table-striped">
        <thead class="thead-light">
            <tr>
                <th style="width: 60px">Foto</th>
                <th>Producto</th>
                <th>Categoría</th>
                <th>Proveedor</th>
                <th style="width: 100px">P. Compra</th>
                <th style="width: 100px">P. Venta</th>
                <th style="width: 80px">Stock</th>
                <th style="width: 80px">Estado</th>
                <th style="width: 200px">Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($productos) > 0): ?>
                <?php foreach ($productos as $producto): ?>
                <tr>
                    <td class="align-middle">
                        <?php if ($producto['foto'] && file_exists($producto['foto'])): ?>
                            <img src="<?php echo htmlspecialchars($producto['foto']); ?>" class="producto-imagen" alt="Producto" style="width: 50px; height: 50px; object-fit: cover; border-radius: 8px;">
                        <?php else: ?>
                            <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-image" style="color: white; font-size: 20px;"></i>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="align-middle">
                        <strong><?php echo htmlspecialchars($producto['nombre']); ?></strong>
                        <?php if ($producto['descripcion']): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars(substr($producto['descripcion'], 0, 50)); ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="align-middle">
                        <span class="badge badge-info"><?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'N/A'); ?></span>
                    </td>
                    <td class="align-middle"><?php echo htmlspecialchars($producto['proveedor_nombre'] ?? 'N/A'); ?></td>
                    <td class="align-middle text-success">$<?php echo number_format($producto['precio_compra'], 2); ?></td>
                    <td class="align-middle"><strong class="text-primary">$<?php echo number_format($producto['precio_venta'], 2); ?></strong></td>
                    <td class="align-middle text-center">
                        <?php if ($producto['stock'] <= $producto['stock_minimo']): ?>
                            <span class="badge badge-danger" style="font-size: 14px;">
                                <i class="fas fa-exclamation-triangle"></i> <?php echo $producto['stock']; ?>
                            </span>
                            <br><small class="text-muted">Mín: <?php echo $producto['stock_minimo']; ?></small>
                        <?php else: ?>
                            <span class="badge badge-success" style="font-size: 14px;"><?php echo $producto['stock']; ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="align-middle text-center">
                        <span class="badge <?php echo $producto['estado'] == 'activo' ? 'badge-success' : 'badge-secondary'; ?>" style="padding: 6px 12px;">
                            <?php echo ucfirst($producto['estado']); ?>
                        </span>
                    </td>
                    <td class="align-middle">
                        <div class="btn-group btn-group-sm" style="display: flex; flex-wrap: wrap; gap: 5px;">
                            <button onclick="editProducto(<?php echo $producto['id']; ?>)" class="btn btn-primary" title="Editar">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="openStockModal(<?php echo $producto['id']; ?>, '<?php echo addslashes($producto['nombre']); ?>', <?php echo $producto['stock']; ?>)" class="btn btn-success" title="Agregar Stock">
                                <i class="fas fa-plus-circle"></i>
                            </button>
                            <button onclick="openAjusteModal(<?php echo $producto['id']; ?>, '<?php echo addslashes($producto['nombre']); ?>', <?php echo $producto['stock']; ?>, <?php echo $producto['stock_minimo']; ?>)" class="btn btn-warning" title="Ajustes de Stock">
                                <i class="fas fa-sliders-h"></i>
                            </button>
                            <?php if ($producto['estado'] == 'activo'): ?>
                                <button onclick="toggleStatus(<?php echo $producto['id']; ?>, 'inactivo')" class="btn btn-danger" title="Desactivar">
                                    <i class="fas fa-ban"></i>
                                </button>
                            <?php else: ?>
                                <button onclick="toggleStatus(<?php echo $producto['id']; ?>, 'activo')" class="btn btn-info" title="Activar">
                                    <i class="fas fa-check-circle"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="9" style="padding: 0;">
                        <div class="empty-state" style="text-align: center; padding: 60px 20px;">
                            <i class="fas fa-box-open" style="font-size: 64px; color: #cbd5e0; margin-bottom: 20px; display: block;"></i>
                            <p style="color: #6c757d; font-size: 16px; margin: 0;">No se encontraron productos</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<span id="totalProductosSpan" data-total="<?php echo $total_registros; ?>" style="display: none;"></span>

<?php if ($total_paginas > 1 || count($productos) > 0): ?>
<div class="card-footer clearfix">
    <div class="row">
        <div class="col-sm-12 col-md-6">
            <div class="dataTables_info" style="margin-top: 8px;">
                Mostrando <?php echo count($productos); ?> de <?php echo $total_registros; ?> productos
                <?php if ($total_registros > 0): ?>
                    (Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?>)
                <?php endif; ?>
            </div>
        </div>
        <div class="col-sm-12 col-md-6">
            <div class="dataTables_paginate paging_simple_numbers" style="float: right;">
                <div class="d-flex justify-content-end align-items-center">
                    <!-- Selector de cantidad por página -->
                    <div class="mr-3">
                        <label class="mr-2 mb-0">Mostrar:</label>
                        <select id="registrosPorPagina" class="form-control form-control-sm" style="width: auto; display: inline-block;" onchange="cambiarLimite()">
                            <option value="5" <?php echo $registros_por_pagina == 5 ? 'selected' : ''; ?>>5</option>
                            <option value="10" <?php echo $registros_por_pagina == 10 ? 'selected' : ''; ?>>10</option>
                            <option value="25" <?php echo $registros_por_pagina == 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $registros_por_pagina == 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $registros_por_pagina == 100 ? 'selected' : ''; ?>>100</option>
                        </select>
                        <span>registros</span>
                    </div>
                    
                    <!-- Paginación -->
                    <?php if ($total_paginas > 1): ?>
                    <ul class="pagination pagination-sm m-0">
                        <?php if ($pagina_actual > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="javascript:void(0)" onclick="cambiarPagina(1)">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="javascript:void(0)" onclick="cambiarPagina(<?php echo $pagina_actual - 1; ?>)">
                                    <i class="fas fa-angle-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php 
                        $startPage = max(1, $pagina_actual - 2);
                        $endPage = min($total_paginas, $pagina_actual + 2);
                        
                        for ($i = $startPage; $i <= $endPage; $i++): 
                        ?>
                            <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                <a class="page-link" href="javascript:void(0)" onclick="cambiarPagina(<?php echo $i; ?>)">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($pagina_actual < $total_paginas): ?>
                            <li class="page-item">
                                <a class="page-link" href="javascript:void(0)" onclick="cambiarPagina(<?php echo $pagina_actual + 1; ?>)">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="javascript:void(0)" onclick="cambiarPagina(<?php echo $total_paginas; ?>)">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let timeoutId;

function cambiarPagina(pagina) {
    const busqueda = document.getElementById('searchInput').value;
    const categoria = document.getElementById('categoriaFilter').value;
    const estado = document.getElementById('estadoFilter').value;
    const limite = document.getElementById('registrosPorPagina').value;
    
    fetch(`productos_ajax.php?action=list&busqueda=${encodeURIComponent(busqueda)}&categoria=${categoria}&estado=${estado}&pagina=${pagina}&limite=${limite}`)
        .then(response => response.text())
        .then(data => {
            document.getElementById('tablaProductos').innerHTML = data;
            
            // Actualizar el total de productos en el header
            const totalSpan = document.getElementById('totalProductosSpan');
            if (totalSpan) {
                const total = totalSpan.getAttribute('data-total');
                $('.card-header .badge').text('Total: ' + total);
            }
            
            // Scroll suave hacia arriba
            $('html, body').animate({
                scrollTop: $('#tablaProductos').offset().top - 100
            }, 300);
        })
        .catch(error => {
            Swal.fire('Error', 'Error al cargar los productos', 'error');
        });
}

function cambiarLimite() {
    // Debounce para evitar múltiples llamadas
    clearTimeout(timeoutId);
    timeoutId = setTimeout(() => {
        const busqueda = document.getElementById('searchInput').value;
        const categoria = document.getElementById('categoriaFilter').value;
        const estado = document.getElementById('estadoFilter').value;
        const limite = document.getElementById('registrosPorPagina').value;
        
        fetch(`productos_ajax.php?action=list&busqueda=${encodeURIComponent(busqueda)}&categoria=${categoria}&estado=${estado}&pagina=1&limite=${limite}`)
            .then(response => response.text())
            .then(data => {
                document.getElementById('tablaProductos').innerHTML = data;
                
                // Actualizar el total de productos en el header
                const totalSpan = document.getElementById('totalProductosSpan');
                if (totalSpan) {
                    const total = totalSpan.getAttribute('data-total');
                    $('.card-header .badge').text('Total: ' + total);
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Error al cambiar el límite de registros', 'error');
            });
    }, 300);
}

// Guardar preferencia del límite en localStorage
$(document).ready(function() {
    const limiteGuardado = localStorage.getItem('productos_limite');
    if (limiteGuardado && $('#registrosPorPagina').length) {
        $('#registrosPorPagina').val(limiteGuardado);
    }
    
    $('#registrosPorPagina').on('change', function() {
        localStorage.setItem('productos_limite', $(this).val());
    });
});
</script>
<?php endif; ?>