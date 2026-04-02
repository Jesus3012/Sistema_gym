<?php
// includes/historial_pagos.php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">No autorizado</div>';
    exit;
}

$database = new Database();
$conn = $database->getConnection();

$inscripcion_id = isset($_POST['inscripcion_id']) ? (int)$_POST['inscripcion_id'] : 0;
$page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
$sort = isset($_POST['sort']) ? $_POST['sort'] : 'fecha_pago';
$order = isset($_POST['order']) ? $_POST['order'] : 'DESC';
$search = isset($_POST['search']) ? $_POST['search'] : '';
$limit = 10;
$offset = ($page - 1) * $limit;

$sort_columns = [
    'fecha_pago' => 'p.fecha_pago',
    'monto' => 'p.monto',
    'metodo_pago' => 'p.metodo_pago',
    'referencia' => 'p.referencia'
];

$order_by = isset($sort_columns[$sort]) ? $sort_columns[$sort] : 'p.fecha_pago';
$order_dir = ($order == 'ASC') ? 'ASC' : 'DESC';

$query = "SELECT p.*, 
          DATE_FORMAT(p.fecha_pago, '%d/%m/%Y') as fecha_pago_formateada
          FROM pagos p
          WHERE p.inscripcion_id = ?";
$count_query = "SELECT COUNT(*) as total FROM pagos p WHERE p.inscripcion_id = ?";
$params = [$inscripcion_id];
$types = "i";

if (!empty($search)) {
    $query .= " AND (p.metodo_pago LIKE ? OR p.referencia LIKE ? OR CAST(p.monto AS CHAR) LIKE ?)";
    $count_query .= " AND (p.metodo_pago LIKE ? OR p.referencia LIKE ? OR CAST(p.monto AS CHAR) LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$query .= " ORDER BY $order_by $order_dir LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$pagos = $result->fetch_all(MYSQLI_ASSOC);

// Obtener total de registros
$count_params = array_slice($params, 0, count($params) - 2);
$count_types = substr($types, 0, -2);
$stmt_count = $conn->prepare($count_query);
if (!empty($count_params)) {
    $stmt_count->bind_param($count_types, ...$count_params);
}
$stmt_count->execute();
$total_result = $stmt_count->get_result();
$total_rows = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// Calcular total pagado
$total_pagado_query = "SELECT SUM(monto) as total FROM pagos WHERE inscripcion_id = ?";
$stmt_total = $conn->prepare($total_pagado_query);
$stmt_total->bind_param("i", $inscripcion_id);
$stmt_total->execute();
$total_pagado_result = $stmt_total->get_result();
$total_pagado = $total_pagado_result->fetch_assoc()['total'] ?? 0;
?>

<style>
    .tabla-pagos {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
    }
    .tabla-pagos th,
    .tabla-pagos td {
        padding: 10px;
        text-align: left;
        border-bottom: 1px solid #e0e0e0;
    }
    .tabla-pagos th {
        background: #f8f9fa;
        font-weight: 600;
        cursor: pointer;
    }
    .tabla-pagos th:hover {
        background: #e9ecef;
    }
    .tabla-pagos tr:hover {
        background: #f8f9fa;
    }
    .pagos-pagination {
        margin-top: 20px;
        justify-content: center;
    }
    .search-pagos {
        margin-bottom: 15px;
        display: flex;
        gap: 10px;
    }
    .search-pagos input {
        flex: 1;
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
    }
    .search-pagos button {
        padding: 8px 16px;
        background: #1e3a8a;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
    }
    .search-pagos button:hover {
        background: #152c6b;
    }
    .total-pagado {
        background: #f0fdf4;
        font-weight: bold;
        margin-top: 15px;
        padding: 10px;
        border-radius: 4px;
    }
    .page-link {
        cursor: pointer;
    }
</style>

<div class="search-pagos">
    <input type="text" id="searchPagos" placeholder="Buscar por método, referencia o monto..." value="<?php echo htmlspecialchars($search); ?>">
    <button onclick="buscarPagos()"><i class="fas fa-search"></i> Buscar</button>
</div>

<div style="overflow-x: auto;">
    <table class="tabla-pagos" id="tablaPagos">
        <thead>
            <tr>
                <th onclick="ordenarPagos('fecha_pago')">Fecha <i class="fas fa-sort"></i></th>
                <th onclick="ordenarPagos('monto')">Monto <i class="fas fa-sort"></i></th>
                <th onclick="ordenarPagos('metodo_pago')">Método <i class="fas fa-sort"></i></th>
                <th onclick="ordenarPagos('referencia')">Referencia <i class="fas fa-sort"></i></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($pagos)): ?>
            <tr>
                <td colspan="4" style="text-align: center; padding: 40px;">
                    <i class="fas fa-receipt" style="font-size: 48px; color: #ccc;"></i>
                    <p class="mt-2">No hay pagos registrados</p>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach($pagos as $pago): ?>
            <tr>
                <td><?php echo $pago['fecha_pago_formateada']; ?></td>
                <td>$<?php echo number_format($pago['monto'], 2); ?></td>
                <td>
                    <?php 
                    if($pago['metodo_pago'] == 'efectivo') echo '<i class="fas fa-money-bill"></i> Efectivo';
                    elseif($pago['metodo_pago'] == 'tarjeta') echo '<i class="fas fa-credit-card"></i> Tarjeta';
                    else echo '<i class="fas fa-exchange-alt"></i> Transferencia';
                    ?>
                </td>
                <td><?php echo $pago['referencia'] ?: '—'; ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="total-pagado">
    <strong>Total Pagado: $<?php echo number_format($total_pagado, 2); ?></strong>
</div>

<?php if($total_pages > 1): ?>
<div class="pagination pagos-pagination">
    <ul class="pagination" style="margin: 0;">
        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
            <a class="page-link" onclick="cambiarPaginaPagos(<?php echo $page-1; ?>)">Anterior</a>
        </li>
        <?php 
        $startPage = max(1, $page - 2);
        $endPage = min($total_pages, $page + 2);
        
        if ($startPage > 1): ?>
            <li class="page-item"><a class="page-link" onclick="cambiarPaginaPagos(1)">1</a></li>
            <?php if ($startPage > 2): ?>
                <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php for($i = $startPage; $i <= $endPage; $i++): ?>
            <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                <a class="page-link" onclick="cambiarPaginaPagos(<?php echo $i; ?>)"><?php echo $i; ?></a>
            </li>
        <?php endfor; ?>
        
        <?php if($endPage < $total_pages): ?>
            <?php if($endPage < $total_pages - 1): ?>
                <li class="page-item disabled"><span class="page-link">...</span></li>
            <?php endif; ?>
            <li class="page-item"><a class="page-link" onclick="cambiarPaginaPagos(<?php echo $total_pages; ?>)"><?php echo $total_pages; ?></a></li>
        <?php endif; ?>
        
        <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
            <a class="page-link" onclick="cambiarPaginaPagos(<?php echo $page+1; ?>)">Siguiente</a>
        </li>
    </ul>
</div>
<?php endif; ?>

<script>
let currentPagePagos = <?php echo $page; ?>;
let currentSortPagos = '<?php echo $sort; ?>';
let currentOrderPagos = '<?php echo $order; ?>';
let currentSearchPagos = '<?php echo $search; ?>';
let timeoutPagos;

function buscarPagos() {
    currentSearchPagos = document.getElementById('searchPagos').value;
    currentPagePagos = 1;
    cargarHistorialPagos();
}

function ordenarPagos(columna) {
    if (currentSortPagos === columna) {
        currentOrderPagos = currentOrderPagos === 'ASC' ? 'DESC' : 'ASC';
    } else {
        currentSortPagos = columna;
        currentOrderPagos = 'ASC';
    }
    currentPagePagos = 1;
    cargarHistorialPagos();
}

function cambiarPaginaPagos(page) {
    currentPagePagos = page;
    cargarHistorialPagos();
}

function cargarHistorialPagos() {
    const inscripcionId = <?php echo $inscripcion_id; ?>;
    
    $.ajax({
        url: 'includes/historial_pagos.php',
        method: 'POST',
        data: {
            inscripcion_id: inscripcionId,
            page: currentPagePagos,
            sort: currentSortPagos,
            order: currentOrderPagos,
            search: currentSearchPagos
        },
        success: function(response) {
            $('#historialPagosContenido').html(response);
        },
        error: function() {
            $('#historialPagosContenido').html('<div class="alert alert-danger">Error al cargar el historial de pagos</div>');
        }
    });
}

// Búsqueda en tiempo real para el historial de pagos
document.getElementById('searchPagos').addEventListener('input', function() {
    clearTimeout(timeoutPagos);
    timeoutPagos = setTimeout(function() {
        buscarPagos();
    }, 500);
});
</script>