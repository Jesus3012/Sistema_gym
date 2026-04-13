<?php
// Archivo: api/ventas_api.php
// API para el historial de ventas

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit();
}

require_once '../config/database.php';

$database = new Database();
$conn = $database->getConnection();

$user_id = $_SESSION['user_id'];
$user_rol = $_SESSION['user_rol'];
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

if ($action == 'list') {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
    $fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : '';
    $fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : '';
    $metodo_pago = isset($_GET['metodo_pago']) ? $_GET['metodo_pago'] : '';
    $limit = 15;
    $offset = ($page - 1) * $limit;
    
    // Construir condiciones WHERE
    $conditions = ["v.estado != 'cancelada'"];
    $params = [];
    $types = "";
    
    if ($user_rol != 'admin') {
        $conditions[] = "v.usuario_id = ?";
        $params[] = $user_id;
        $types .= "i";
    }
    
    if (!empty($buscar)) {
        $conditions[] = "(CAST(v.id AS CHAR) LIKE ? OR CONCAT(COALESCE(c.nombre,''), ' ', COALESCE(c.apellido,'')) LIKE ?)";
        $search = "%$buscar%";
        $params[] = $search;
        $params[] = $search;
        $types .= "ss";
    }
    
    if (!empty($fecha_inicio)) {
        $conditions[] = "DATE(v.fecha_venta) >= ?";
        $params[] = $fecha_inicio;
        $types .= "s";
    }
    
    if (!empty($fecha_fin)) {
        $conditions[] = "DATE(v.fecha_venta) <= ?";
        $params[] = $fecha_fin;
        $types .= "s";
    }
    
    if (!empty($metodo_pago)) {
        $conditions[] = "v.metodo_pago = ?";
        $params[] = $metodo_pago;
        $types .= "s";
    }
    
    $where = implode(" AND ", $conditions);
    
    // Query principal
    $query = "SELECT v.*, u.nombre as usuario_nombre, 
              CONCAT(COALESCE(c.nombre,''), ' ', COALESCE(c.apellido,'')) as cliente_nombre,
              v.cliente_id
              FROM ventas v
              LEFT JOIN usuarios u ON v.usuario_id = u.id
              LEFT JOIN clientes c ON v.cliente_id = c.id
              WHERE $where
              ORDER BY v.fecha_venta DESC
              LIMIT $offset, $limit";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $ventas = $result->fetch_all(MYSQLI_ASSOC);
    
    // Query para contar total (sin LIMIT)
    $count_query = "SELECT COUNT(*) as total FROM ventas v
                    LEFT JOIN clientes c ON v.cliente_id = c.id
                    WHERE $where";
    $stmt_count = $conn->prepare($count_query);
    if (!empty($params)) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $total_result = $stmt_count->get_result();
    $total = $total_result->fetch_assoc()['total'];
    
    echo json_encode([
        'success' => true,
        'ventas' => $ventas,
        'total_pages' => ceil($total / $limit),
        'current_page' => $page,
        'total' => $total
    ]);
    
} elseif ($action == 'detalle') {
    $venta_id = isset($_GET['venta_id']) ? (int)$_GET['venta_id'] : 0;
    
    $query_venta = "SELECT v.*, u.nombre as usuario_nombre,
                    CONCAT(COALESCE(c.nombre,''), ' ', COALESCE(c.apellido,'')) as cliente_nombre,
                    c.email as cliente_email,
                    v.cliente_id
                    FROM ventas v
                    LEFT JOIN usuarios u ON v.usuario_id = u.id
                    LEFT JOIN clientes c ON v.cliente_id = c.id
                    WHERE v.id = ?";
    $stmt = $conn->prepare($query_venta);
    $stmt->bind_param("i", $venta_id);
    $stmt->execute();
    $venta = $stmt->get_result()->fetch_assoc();
    
    $query_detalles = "SELECT dv.*, p.nombre as producto_nombre, p.foto
                       FROM detalle_ventas dv
                       LEFT JOIN productos p ON dv.producto_id = p.id
                       WHERE dv.venta_id = ?";
    $stmt = $conn->prepare($query_detalles);
    $stmt->bind_param("i", $venta_id);
    $stmt->execute();
    $detalles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode([
        'success' => true,
        'venta' => $venta,
        'detalles' => $detalles
    ]);
}
?>