<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    echo json_encode(array('success' => false, 'error' => 'No autorizado'));
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/sucursal_context.php';
require_once __DIR__ . '/includes/stock_functions.php';

$database = new Database();
$conn = $database->getConnection();
if (!$conn instanceof mysqli) {
    echo json_encode(array('success' => false, 'error' => 'Error de conexión a la base de datos'));
    exit();
}
$conn->set_charset('utf8mb4');

function ajaxRespuesta($success, $message, $extra = array(), $http = 200)
{
    http_response_code((int) $http);
    echo json_encode(
        array_merge(array('success' => (bool) $success, $success ? 'message' : 'error' => $message), $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit();
}

function ajaxBind($stmt, $types, $params)
{
    if ($types === '') return true;
    $refs = array($types);
    foreach ($params as $k => $v) {
        $params[$k] = $v;
        $refs[] = &$params[$k];
    }
    return call_user_func_array(array($stmt, 'bind_param'), $refs);
}

$user_id = (int) $_SESSION['user_id'];
$rol = strtolower(trim((string) ($_SESSION['user_rol'] ?? 'recepcionista')));
$es_admin = in_array($rol, array('admin', 'administrador'), true);
$vista = strtolower(trim((string) ($_GET['vista'] ?? $_POST['vista'] ?? '')));
if ($vista === 'global' && $es_admin) {
    sucursal_activar_vista_global($conn, $user_id);
} elseif ($vista === 'sucursal') {
    sucursal_desactivar_vista_global();
}
$vista_global = $es_admin && sucursal_dashboard_vista_global();
$sucursal_id = (int) ($_SESSION['sucursal_id'] ?? 0);
$sucursal_nombre = trim((string) ($_SESSION['sucursal_nombre'] ?? 'Sucursal'));
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'list') {
    $limite = max(1, min(100, (int) ($_GET['limite'] ?? 10)));
    $pagina = max(1, (int) ($_GET['pagina'] ?? 1));
    $offset = ($pagina - 1) * $limite;
    $busqueda = trim((string) ($_GET['busqueda'] ?? ''));
    $categoria = max(0, (int) ($_GET['categoria'] ?? 0));
    $estado = (string) ($_GET['estado'] ?? 'todos');
    $where = array(); $params = array(); $types = '';

    if (!$vista_global) { $where[] = 'inv.sucursal_id = ?'; $params[] = $sucursal_id; $types .= 'i'; }
    if ($busqueda !== '') { $where[] = '(p.nombre LIKE ? OR p.descripcion LIKE ?)'; $params[] = '%' . $busqueda . '%'; $params[] = '%' . $busqueda . '%'; $types .= 'ss'; }
    if ($categoria > 0) { $where[] = 'p.categoria_id = ?'; $params[] = $categoria; $types .= 'i'; }
    if ($estado !== 'todos') {
        if ($vista_global) { $where[] = 'p.estado = ?'; $params[] = $estado; $types .= 's'; }
        elseif ($estado === 'activo') $where[] = "p.estado = 'activo' AND inv.estado = 'activo'";
        else $where[] = "(p.estado <> 'activo' OR inv.estado <> 'activo')";
    }
    $where_sql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

    if ($vista_global) {
        $sql = "SELECT p.id, p.nombre, p.descripcion, p.foto, p.estado,
                       c.nombre AS categoria_nombre, prov.nombre AS proveedor_nombre,
                       COUNT(inv.id) AS sucursales_count, COALESCE(SUM(inv.stock),0) AS stock,
                       MIN(inv.precio_compra) AS precio_compra, MAX(inv.precio_venta) AS precio_venta
                FROM productos p
                LEFT JOIN inventario_sucursales inv ON inv.producto_id = p.id
                LEFT JOIN categorias_productos c ON c.id = p.categoria_id
                LEFT JOIN proveedores prov ON prov.id = p.proveedor_id
                {$where_sql}
                GROUP BY p.id, p.nombre, p.descripcion, p.foto, p.estado, c.nombre, prov.nombre
                ORDER BY p.fecha_registro DESC LIMIT ? OFFSET ?";
        $count = "SELECT COUNT(*) AS total FROM productos p {$where_sql}";
    } else {
        $sql = "SELECT p.id, p.nombre, p.descripcion, p.foto, p.categoria_id, p.proveedor_id,
                       c.nombre AS categoria_nombre, prov.nombre AS proveedor_nombre,
                       inv.precio_compra, inv.precio_venta, inv.stock, inv.stock_minimo,
                       CASE WHEN p.estado='activo' AND inv.estado='activo' THEN 'activo' ELSE 'inactivo' END AS estado
                FROM inventario_sucursales inv
                INNER JOIN productos p ON p.id = inv.producto_id
                LEFT JOIN categorias_productos c ON c.id = p.categoria_id
                LEFT JOIN proveedores prov ON prov.id = p.proveedor_id
                {$where_sql}
                ORDER BY p.fecha_registro DESC LIMIT ? OFFSET ?";
        $count = "SELECT COUNT(*) AS total FROM inventario_sucursales inv INNER JOIN productos p ON p.id=inv.producto_id {$where_sql}";
    }

    $stmt = $conn->prepare($count); ajaxBind($stmt, $types, $params); $stmt->execute();
    $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0); $stmt->close();
    $query_params = $params; $query_params[] = $limite; $query_params[] = $offset;
    $stmt = $conn->prepare($sql); ajaxBind($stmt, $types . 'ii', $query_params); $stmt->execute();
    $productos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    ajaxRespuesta(true, 'Productos consultados.', array('productos' => $productos, 'total' => $total, 'total_paginas' => max(1, (int) ceil($total / $limite)), 'pagina_actual' => $pagina, 'vista_global' => $vista_global));
}

if ($vista_global) {
    ajaxRespuesta(false, 'Selecciona una sucursal concreta antes de modificar productos.', array(), 409);
}
if ($sucursal_id <= 0) {
    ajaxRespuesta(false, 'No hay una sucursal operativa seleccionada.', array(), 409);
}

if ($action === 'get') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = $conn->prepare("SELECT p.id, p.nombre, p.descripcion, p.categoria_id, p.proveedor_id, p.foto,
                                  inv.precio_compra, inv.precio_venta, inv.stock, inv.stock_minimo, inv.estado
                           FROM productos p
                           INNER JOIN inventario_sucursales inv ON inv.producto_id = p.id AND inv.sucursal_id = ?
                           WHERE p.id = ? LIMIT 1");
    $stmt->bind_param('ii', $sucursal_id, $id); $stmt->execute();
    $producto = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$producto) ajaxRespuesta(false, 'Producto no encontrado en esta sucursal.', array(), 404);
    ajaxRespuesta(true, 'Producto encontrado.', array('producto' => $producto, 'sucursal_id' => $sucursal_id, 'sucursal_nombre' => $sucursal_nombre));
}

if (in_array($action, array('add_stock', 'ajuste_stock'), true)) {
    $id = (int) ($_POST['producto_id'] ?? 0);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT stock, stock_minimo FROM inventario_sucursales WHERE sucursal_id = ? AND producto_id = ? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('ii', $sucursal_id, $id); $stmt->execute();
        $inventario = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$inventario) throw new RuntimeException('Producto no encontrado en esta sucursal.');

        if ($action === 'add_stock') {
            $cantidad = (int) ($_POST['cantidad'] ?? 0);
            if ($cantidad <= 0) throw new InvalidArgumentException('La cantidad debe ser mayor a cero.');
            $mov = registrarMovimientoStock($conn, $id, 'entrada', $cantidad, 'Entrada de stock', $user_id, null, 'entrada_manual', trim((string) ($_POST['observaciones'] ?? '')), $sucursal_id);
            if (empty($mov['success'])) throw new RuntimeException((string) $mov['error']);
            $stmt = $conn->prepare('UPDATE inventario_sucursales SET stock = stock + ? WHERE sucursal_id = ? AND producto_id = ?');
            $stmt->bind_param('iii', $cantidad, $sucursal_id, $id); $stmt->execute(); $stmt->close();
            $mensaje = 'Stock agregado correctamente. Nuevo stock: ' . (int) $mov['stock_nuevo'] . '.';
        } else {
            $tipo = (string) ($_POST['tipo_ajuste'] ?? '');
            $motivo = trim((string) ($_POST['motivo_ajuste'] ?? 'Ajuste manual'));
            $obs = trim((string) ($_POST['observaciones'] ?? ''));
            if ($tipo === 'stock_correccion') {
                $nuevo = max(0, (int) ($_POST['stock_fisico'] ?? 0));
                $mov = registrarMovimientoStock($conn, $id, 'correccion', $nuevo, $motivo, $user_id, null, 'correccion_inventario', $obs, $sucursal_id);
                if (empty($mov['success'])) throw new RuntimeException((string) $mov['error']);
                $stmt = $conn->prepare('UPDATE inventario_sucursales SET stock = ? WHERE sucursal_id = ? AND producto_id = ?');
                $stmt->bind_param('iii', $nuevo, $sucursal_id, $id); $stmt->execute(); $stmt->close();
                $mensaje = 'Stock corregido a ' . $nuevo . ' unidades.';
            } elseif ($tipo === 'stock_minimo') {
                $nuevo = max(0, (int) ($_POST['nuevo_stock_minimo'] ?? 0));
                $dif = $nuevo - (int) $inventario['stock_minimo'];
                $mov = registrarMovimientoStock($conn, $id, 'ajuste_minimo', $dif, $motivo, $user_id, null, 'ajuste_stock_minimo', $obs, $sucursal_id);
                if (empty($mov['success'])) throw new RuntimeException((string) $mov['error']);
                $stmt = $conn->prepare('UPDATE inventario_sucursales SET stock_minimo = ? WHERE sucursal_id = ? AND producto_id = ?');
                $stmt->bind_param('iii', $nuevo, $sucursal_id, $id); $stmt->execute(); $stmt->close();
                $mensaje = 'Stock mínimo actualizado a ' . $nuevo . ' unidades.';
            } else throw new InvalidArgumentException('Tipo de ajuste inválido.');
        }
        $conn->commit(); ajaxRespuesta(true, $mensaje, array('sucursal_id' => $sucursal_id));
    } catch (Throwable $e) {
        $conn->rollback(); ajaxRespuesta(false, $e->getMessage(), array(), 409);
    }
}

ajaxRespuesta(false, 'Acción no reconocida.', array(), 400);
?>
