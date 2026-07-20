<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/sucursal_context.php';

function ventasApiResponder(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function ventasApiBind(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '' || $params === []) {
        return;
    }

    $refs = [];
    foreach ($params as $key => &$value) {
        $refs[$key] = &$value;
    }
    unset($value);

    $stmt->bind_param($types, ...$refs);
}

if (empty($_SESSION['user_id'])) {
    ventasApiResponder([
        'success' => false,
        'message' => 'No autorizado.',
    ], 401);
}

$database = new Database();
$conn = $database->getConnection();

if (!$conn instanceof mysqli) {
    ventasApiResponder([
        'success' => false,
        'message' => 'No fue posible conectar con la base de datos.',
    ], 500);
}

$conn->set_charset('utf8mb4');

$userId = (int) $_SESSION['user_id'];
$userRole = strtolower(trim((string) (
    $_SESSION['user_rol_base']
    ?? $_SESSION['user_rol']
    ?? ''
)));
if ($userRole === 'administrador') {
    $userRole = 'admin';
}

$isAdmin = in_array($userRole, ['admin', 'administrador'], true);
$isGlobal = $isAdmin
    && function_exists('sucursal_dashboard_vista_global')
    && sucursal_dashboard_vista_global();
$sucursalId = (int) ($_SESSION['sucursal_id'] ?? 0);

if ($sucursalId <= 0) {
    ventasApiResponder([
        'success' => false,
        'message' => 'No existe una sucursal operativa seleccionada.',
    ], 409);
}

$action = trim((string) ($_GET['action'] ?? 'list'));

try {
    /*
     * El historial operativo muestra únicamente ventas vigentes.
     * Las canceladas permanecen en la base de datos para auditoría,
     * reembolsos y corte de caja, pero no se listan en este módulo.
     */
    $conditions = ["v.estado <> 'cancelada'"];
    $params = [];
    $types = '';

    if (!$isGlobal) {
        $conditions[] = 'v.sucursal_id = ?';
        $params[] = $sucursalId;
        $types .= 'i';
    }

    if (!$isAdmin) {
        $conditions[] = 'v.usuario_id = ?';
        $params[] = $userId;
        $types .= 'i';
    }

    if ($action === 'list') {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = 15;
        $offset = ($page - 1) * $limit;
        $buscar = trim((string) ($_GET['buscar'] ?? ''));
        $fechaInicio = trim((string) ($_GET['fecha_inicio'] ?? ''));
        $fechaFin = trim((string) ($_GET['fecha_fin'] ?? ''));
        $metodo = trim((string) ($_GET['metodo_pago'] ?? ''));

        if ($buscar !== '') {
            $conditions[] = "(
                CAST(v.id AS CHAR) LIKE ?
                OR CONCAT(COALESCE(c.nombre,''), ' ', COALESCE(c.apellido,'')) LIKE ?
                OR s.nombre LIKE ?
                OR s.clave LIKE ?
            )";
            $search = '%' . $buscar . '%';
            array_push($params, $search, $search, $search, $search);
            $types .= 'ssss';
        }

        if ($fechaInicio !== '') {
            $conditions[] = 'DATE(v.fecha_venta) >= ?';
            $params[] = $fechaInicio;
            $types .= 's';
        }

        if ($fechaFin !== '') {
            $conditions[] = 'DATE(v.fecha_venta) <= ?';
            $params[] = $fechaFin;
            $types .= 's';
        }

        if ($metodo !== '') {
            $conditions[] = 'v.metodo_pago = ?';
            $params[] = $metodo;
            $types .= 's';
        }

        $where = $conditions === []
            ? '1 = 1'
            : implode(' AND ', $conditions);

        $sql = "SELECT
                    v.id,
                    v.sucursal_id,
                    v.cliente_id,
                    v.usuario_id,
                    v.fecha_venta,
                    v.total,
                    v.metodo_pago,
                    v.estado,
                    u.nombre AS usuario_nombre,
                    CONCAT(COALESCE(c.nombre,''), ' ', COALESCE(c.apellido,'')) AS cliente_nombre,
                    s.nombre AS sucursal_nombre,
                    s.clave AS sucursal_clave,
                    s.es_matriz AS sucursal_es_matriz
                FROM ventas v
                LEFT JOIN usuarios u ON u.id = v.usuario_id
                LEFT JOIN clientes c ON c.id = v.cliente_id
                INNER JOIN sucursales s ON s.id = v.sucursal_id
                WHERE {$where}
                ORDER BY v.fecha_venta DESC, v.id DESC
                LIMIT {$offset}, {$limit}";

        $stmt = $conn->prepare($sql);
        ventasApiBind($stmt, $types, $params);
        $stmt->execute();
        $ventas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $countSql = "SELECT COUNT(*) AS total
                     FROM ventas v
                     LEFT JOIN clientes c ON c.id = v.cliente_id
                     INNER JOIN sucursales s ON s.id = v.sucursal_id
                     WHERE {$where}";
        $countParams = $params;
        $count = $conn->prepare($countSql);
        ventasApiBind($count, $types, $countParams);
        $count->execute();
        $total = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
        $count->close();

        $statsSql = "SELECT
                        COUNT(*) AS total_ventas,
                        COALESCE(SUM(v.total), 0) AS total_ingresos,
                        0 AS total_canceladas,
                        COUNT(DISTINCT v.sucursal_id) AS total_sucursales
                     FROM ventas v
                     LEFT JOIN clientes c ON c.id = v.cliente_id
                     INNER JOIN sucursales s ON s.id = v.sucursal_id
                     WHERE {$where}";
        $statsParams = $params;
        $statsStmt = $conn->prepare($statsSql);
        ventasApiBind($statsStmt, $types, $statsParams);
        $statsStmt->execute();
        $stats = $statsStmt->get_result()->fetch_assoc() ?: [];
        $statsStmt->close();

        ventasApiResponder([
            'success' => true,
            'scope' => $isGlobal ? 'global' : 'sucursal',
            'sucursal_id' => $isGlobal ? null : $sucursalId,
            'ventas' => $ventas,
            'total_pages' => max(1, (int) ceil($total / $limit)),
            'current_page' => $page,
            'total' => $total,
            'estadisticas' => [
                'total_ventas' => (int) ($stats['total_ventas'] ?? 0),
                'total_ingresos' => round((float) ($stats['total_ingresos'] ?? 0), 2),
                'total_canceladas' => (int) ($stats['total_canceladas'] ?? 0),
                'total_sucursales' => (int) ($stats['total_sucursales'] ?? 0),
            ],
        ]);
    }

    if ($action === 'detalle') {
        $ventaId = (int) ($_GET['venta_id'] ?? 0);
        if ($ventaId <= 0) {
            ventasApiResponder([
                'success' => false,
                'message' => 'Venta no válida.',
            ], 400);
        }

        $detailConditions = $conditions;
        $detailParams = $params;
        $detailTypes = $types;
        $detailConditions[] = 'v.id = ?';
        $detailParams[] = $ventaId;
        $detailTypes .= 'i';
        $whereDetail = implode(' AND ', $detailConditions);

        $sqlVenta = "SELECT
                        v.*,
                        u.nombre AS usuario_nombre,
                        CONCAT(COALESCE(c.nombre,''), ' ', COALESCE(c.apellido,'')) AS cliente_nombre,
                        c.email AS cliente_email,
                        s.nombre AS sucursal_nombre,
                        s.clave AS sucursal_clave,
                        s.es_matriz AS sucursal_es_matriz
                     FROM ventas v
                     LEFT JOIN usuarios u ON u.id = v.usuario_id
                     LEFT JOIN clientes c ON c.id = v.cliente_id
                     INNER JOIN sucursales s ON s.id = v.sucursal_id
                     WHERE {$whereDetail}
                     LIMIT 1";

        $stmt = $conn->prepare($sqlVenta);
        ventasApiBind($stmt, $detailTypes, $detailParams);
        $stmt->execute();
        $venta = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!is_array($venta)) {
            ventasApiResponder([
                'success' => false,
                'message' => 'Venta no encontrada o sin permiso para consultarla.',
            ], 404);
        }

        $detalleStmt = $conn->prepare(
            "SELECT
                dv.id,
                dv.venta_id,
                dv.producto_id,
                dv.cantidad,
                dv.precio_unitario,
                dv.subtotal,
                p.nombre AS producto_nombre,
                p.foto
             FROM detalle_ventas dv
             LEFT JOIN productos p ON p.id = dv.producto_id
             WHERE dv.venta_id = ?
             ORDER BY dv.id ASC"
        );
        $detalleStmt->bind_param('i', $ventaId);
        $detalleStmt->execute();
        $detalles = $detalleStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $detalleStmt->close();

        ventasApiResponder([
            'success' => true,
            'venta' => $venta,
            'detalles' => $detalles,
        ]);
    }

    ventasApiResponder([
        'success' => false,
        'message' => 'Acción no válida.',
    ], 400);
} catch (Throwable $error) {
    error_log('[ventas_api] ' . $error->getMessage());
    ventasApiResponder([
        'success' => false,
        'message' => $error->getMessage(),
    ], 500);
}