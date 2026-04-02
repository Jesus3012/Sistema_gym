<?php
// includes/inscripcion_detalle_historial.php
session_start();
require_once '../config/database.php';

if (!isset($_POST['id'])) {
    echo json_encode(['error' => 'ID no proporcionado']);
    exit;
}

$id = $_POST['id'];
$page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
$sort = isset($_POST['sort']) ? $_POST['sort'] : 'fecha_pago';
$order = isset($_POST['order']) ? $_POST['order'] : 'DESC';
$search = isset($_POST['search']) ? $_POST['search'] : '';

$database = new Database();
$conn = $database->getConnection();

$limit = 10;
$offset = ($page - 1) * $limit;

$sort_columns = [
    'fecha_pago' => 'h.fecha_pago',
    'monto' => 'h.monto',
    'metodo_pago' => 'h.metodo_pago',
    'referencia' => 'h.referencia',
    'periodo_inicio' => 'h.periodo_inicio',
    'plan_nombre' => 'h.plan_nombre'
];

$order_by = isset($sort_columns[$sort]) ? $sort_columns[$sort] : 'h.fecha_pago';
$order_dir = ($order == 'ASC') ? 'ASC' : 'DESC';

$query = "SELECT h.*, 
          DATE_FORMAT(h.fecha_pago, '%d/%m/%Y') as fecha_pago_formateada,
          DATE_FORMAT(h.periodo_inicio, '%d/%m/%Y') as periodo_inicio_formateado,
          DATE_FORMAT(h.periodo_fin, '%d/%m/%Y') as periodo_fin_formateado
          FROM historial_pagos h
          WHERE h.inscripcion_id = ?";
$count_query = "SELECT COUNT(*) as total FROM historial_pagos h WHERE h.inscripcion_id = ?";
$params = [$id];
$types = "i";

if (!empty($search)) {
    $query .= " AND (h.metodo_pago LIKE ? OR h.referencia LIKE ? OR h.plan_nombre LIKE ? OR CAST(h.monto AS CHAR) LIKE ?)";
    $count_query .= " AND (h.metodo_pago LIKE ? OR h.referencia LIKE ? OR h.plan_nombre LIKE ? OR CAST(h.monto AS CHAR) LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

$query .= " ORDER BY $order_by $order_dir LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$historial = $result->fetch_all(MYSQLI_ASSOC);

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
$total_pagado_query = "SELECT SUM(monto) as total FROM historial_pagos WHERE inscripcion_id = ? AND monto > 0";
$stmt_total = $conn->prepare($total_pagado_query);
$stmt_total->bind_param("i", $id);
$stmt_total->execute();
$total_pagado_result = $stmt_total->get_result();
$total_pagado = $total_pagado_result->fetch_assoc()['total'] ?? 0;

// Generar HTML de la tabla
ob_start();
if (empty($historial)): ?>
    <tr>
        <td colspan="6" style="text-align: center; padding: 60px 20px;">
            <i class="fas fa-receipt" style="font-size: 48px; color: #ccc;"></i>
            <p class="mt-3" style="color: #999;">No hay pagos registrados</p>
        </td>
    </tr>
<?php else: ?>
    <?php foreach($historial as $pago): ?>
    <tr>
        <td><?php echo $pago['fecha_pago_formateada']; ?></td>
        <td><strong style="color: #10b981;">$<?php echo number_format($pago['monto'], 2); ?></strong></td>
        <td>
            <?php 
            if($pago['metodo_pago'] == 'efectivo') 
                echo '<span class="badge-metodo badge-efectivo"><i class="fas fa-money-bill"></i> Efectivo</span>';
            elseif($pago['metodo_pago'] == 'tarjeta') 
                echo '<span class="badge-metodo badge-tarjeta"><i class="fas fa-credit-card"></i> Tarjeta</span>';
            elseif($pago['metodo_pago'] == 'transferencia') 
                echo '<span class="badge-metodo badge-transferencia"><i class="fas fa-exchange-alt"></i> Transferencia</span>';
            else 
                echo '<span class="badge-metodo"><i class="fas fa-times-circle"></i> Cancelación</span>';
            ?>
        </td>
        <td><?php echo $pago['referencia'] ?: '—'; ?></td>
        <td>
            <?php 
            if($pago['periodo_inicio'] && $pago['periodo_fin']):
                echo $pago['periodo_inicio_formateado'] . ' - ' . $pago['periodo_fin_formateado'];
            else:
                echo '—';
            endif;
            ?>
        </td>
        <td><?php echo htmlspecialchars($pago['plan_nombre']); ?></td>
    </tr>
    <?php endforeach; ?>
<?php endif;
$tbody = ob_get_clean();

// Generar HTML de la paginación
ob_start();
if($total_pages > 1): ?>
    <div class="page-item-modern <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
        <a class="page-link-modern" onclick="cambiarPaginaHistorial(<?php echo $page-1; ?>)">« Anterior</a>
    </div>
    <?php 
    $startPage = max(1, $page - 2);
    $endPage = min($total_pages, $page + 2);
    
    if ($startPage > 1): ?>
        <div class="page-item-modern">
            <a class="page-link-modern" onclick="cambiarPaginaHistorial(1)">1</a>
        </div>
        <?php if ($startPage > 2): ?>
            <div class="page-item-modern disabled"><span class="page-link-modern">...</span></div>
        <?php endif; ?>
    <?php endif; ?>
    
    <?php for($i = $startPage; $i <= $endPage; $i++): ?>
        <div class="page-item-modern <?php echo ($i == $page) ? 'active' : ''; ?>">
            <a class="page-link-modern" onclick="cambiarPaginaHistorial(<?php echo $i; ?>)"><?php echo $i; ?></a>
        </div>
    <?php endfor; ?>
    
    <?php if($endPage < $total_pages): ?>
        <?php if($endPage < $total_pages - 1): ?>
            <div class="page-item-modern disabled"><span class="page-link-modern">...</span></div>
        <?php endif; ?>
        <div class="page-item-modern">
            <a class="page-link-modern" onclick="cambiarPaginaHistorial(<?php echo $total_pages; ?>)"><?php echo $total_pages; ?></a>
        </div>
    <?php endif; ?>
    
    <div class="page-item-modern <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
        <a class="page-link-modern" onclick="cambiarPaginaHistorial(<?php echo $page+1; ?>)">Siguiente »</a>
    </div>
<?php endif;
$pagination = ob_get_clean();

echo json_encode([
    'tbody' => $tbody,
    'pagination' => $pagination,
    'total_pagado' => number_format($total_pagado, 2)
]);
?>