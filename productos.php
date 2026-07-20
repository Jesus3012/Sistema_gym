<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/sucursal_context.php';
require_once __DIR__ . '/includes/stock_functions.php';

$database = new Database();
$conn = $database->getConnection();

if (!$conn instanceof mysqli) {
    die('Error: No se pudo establecer la conexión a la base de datos');
}

$conn->set_charset('utf8mb4');

$user_id = (int) $_SESSION['user_id'];
$user_rol = strtolower(trim((string) ($_SESSION['user_rol'] ?? 'recepcionista')));
$es_admin = in_array($user_rol, array('admin', 'administrador'), true);
$vista_solicitada = strtolower(trim((string) ($_GET['vista'] ?? '')));

if ($vista_solicitada === 'global' && $es_admin) {
    sucursal_activar_vista_global($conn, $user_id);
} elseif ($vista_solicitada === 'sucursal') {
    sucursal_desactivar_vista_global();
}

$vista_global = $es_admin
    && function_exists('sucursal_dashboard_vista_global')
    && sucursal_dashboard_vista_global();

$sucursal_id = (int) ($_SESSION['sucursal_id'] ?? 0);
$sucursal_nombre = trim((string) ($_SESSION['sucursal_nombre'] ?? 'Sucursal'));
$sucursal_clave = trim((string) ($_SESSION['sucursal_clave'] ?? ''));
$sucursales_asignadas = sucursal_obtener_asignadas($conn, $user_id);
$sucursal_actual = null;

foreach ($sucursales_asignadas as $sucursal_asignada) {
    if ((int) ($sucursal_asignada['id'] ?? 0) === $sucursal_id) {
        $sucursal_actual = $sucursal_asignada;
        break;
    }
}

if (!$vista_global && !$sucursal_actual) {
    die('No hay una sucursal operativa seleccionada para administrar productos.');
}

if ($sucursal_actual) {
    $sucursal_nombre = trim((string) ($sucursal_actual['nombre'] ?? $sucursal_nombre));
    $sucursal_clave = trim((string) ($sucursal_actual['clave'] ?? $sucursal_clave));
}

if (!file_exists(__DIR__ . '/uploads/productos/defaults/')) {
    mkdir(__DIR__ . '/uploads/productos/defaults/', 0777, true);
}

function productosJson($success, $message, $extra = array(), $http = 200)
{
    http_response_code((int) $http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        array_merge(array(
            'success' => (bool) $success,
            $success ? 'message' : 'error' => (string) $message,
        ), $extra),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit();
}

function productosBindParams($stmt, $types, $params)
{
    if ($types === '' || empty($params)) {
        return true;
    }

    $refs = array($types);
    foreach ($params as $key => $value) {
        $params[$key] = $value;
        $refs[] = &$params[$key];
    }

    return call_user_func_array(array($stmt, 'bind_param'), $refs);
}

function generarNombreLimpio($nombre_producto)
{
    $nombre_limpio = strtolower((string) $nombre_producto);
    $nombre_limpio = str_replace(
        array('á', 'é', 'í', 'ó', 'ú', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ'),
        array('a', 'e', 'i', 'o', 'u', 'n', 'a', 'e', 'i', 'o', 'u', 'n'),
        $nombre_limpio
    );
    $nombre_limpio = preg_replace('/[^a-z0-9]+/', '_', $nombre_limpio);
    $nombre_limpio = trim((string) $nombre_limpio, '_');
    return strlen($nombre_limpio) > 50
        ? substr($nombre_limpio, 0, 50)
        : $nombre_limpio;
}

function generarNombreImagen($nombre_producto, $extension)
{
    return generarNombreLimpio($nombre_producto) . '.' . strtolower((string) $extension);
}

function getImagenPorDefecto($nombre_producto, $categoria_nombre)
{
    $nombre_producto = strtolower((string) $nombre_producto);
    $categoria_nombre = strtolower((string) $categoria_nombre);
    $imagenes = array(
        'agua' => 'uploads/productos/defaults/agua.png',
        'agua mineral' => 'uploads/productos/defaults/agua.png',
        'gatorade' => 'uploads/productos/defaults/gatorade.png',
        'electrolit' => 'uploads/productos/defaults/electrolit.png',
        'powerade' => 'uploads/productos/defaults/powerade.png',
        'monster' => 'uploads/productos/defaults/monster.png',
        'red bull' => 'uploads/productos/defaults/redbull.png',
        'proteina' => 'uploads/productos/defaults/proteina.png',
        'whey' => 'uploads/productos/defaults/proteina.png',
        'creatina' => 'uploads/productos/defaults/creatina.png',
        'bcaa' => 'uploads/productos/defaults/bcaa.png',
        'aminoacidos' => 'uploads/productos/defaults/aminoacidos.png',
        'glutamina' => 'uploads/productos/defaults/glutamina.png',
        'pre entreno' => 'uploads/productos/defaults/pre_entreno.png',
        'playera' => 'uploads/productos/defaults/playera.png',
        'camiseta' => 'uploads/productos/defaults/playera.png',
        'pants' => 'uploads/productos/defaults/pants.png',
        'short' => 'uploads/productos/defaults/short.png',
        'tenis' => 'uploads/productos/defaults/tenis.png',
        'guantes' => 'uploads/productos/defaults/guantes.png',
        'cuerda' => 'uploads/productos/defaults/cuerda.png',
        'toalla' => 'uploads/productos/defaults/toalla.png',
        'botella' => 'uploads/productos/defaults/botella.png',
        'shaker' => 'uploads/productos/defaults/shaker.png',
        'barra energetica' => 'uploads/productos/defaults/barra_energetica.png',
        'barra proteica' => 'uploads/productos/defaults/barra_proteica.png',
        'suplementos' => 'uploads/productos/defaults/suplementos_generico.png',
        'ropa' => 'uploads/productos/defaults/ropa_generica.png',
        'accesorios' => 'uploads/productos/defaults/accesorio_generico.png',
        'bebidas' => 'uploads/productos/defaults/bebida_generica.png',
        'alimentos' => 'uploads/productos/defaults/alimento_generico.png',
    );

    foreach ($imagenes as $palabra => $imagen) {
        if (strpos($nombre_producto, $palabra) !== false) {
            return $imagen;
        }
    }

    return isset($imagenes[$categoria_nombre])
        ? $imagenes[$categoria_nombre]
        : 'uploads/productos/defaults/producto_generico.png';
}

function productosProcesarImagen($nombre, $archivo)
{
    if (!is_array($archivo) || (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $permitidos = array('image/jpeg', 'image/png', 'image/jpg', 'image/webp');
    $tipo = (string) ($archivo['type'] ?? '');
    $tamano = (int) ($archivo['size'] ?? 0);

    if (!in_array($tipo, $permitidos, true) || $tamano > 2 * 1024 * 1024) {
        throw new RuntimeException('La imagen debe ser JPG, PNG o WEBP y pesar máximo 2 MB.');
    }

    $extension = strtolower((string) pathinfo((string) $archivo['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, array('jpg', 'jpeg', 'png', 'webp'), true)) {
        throw new RuntimeException('La extensión de la imagen no es válida.');
    }

    $directorio = __DIR__ . '/uploads/productos/';
    if (!is_dir($directorio) && !mkdir($directorio, 0777, true) && !is_dir($directorio)) {
        throw new RuntimeException('No se pudo crear el directorio de imágenes.');
    }

    $archivo_nombre = generarNombreImagen($nombre, $extension);
    $ruta_absoluta = $directorio . $archivo_nombre;
    $ruta_relativa = 'uploads/productos/' . $archivo_nombre;

    if (!move_uploaded_file((string) $archivo['tmp_name'], $ruta_absoluta)) {
        throw new RuntimeException('No se pudo guardar la imagen del producto.');
    }

    return $ruta_relativa;
}

$categorias = array();
$result = $conn->query("SELECT * FROM categorias_productos WHERE estado = 'activo' ORDER BY nombre");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $categorias[] = $row;
    }
}

$proveedores = array();
$result = $conn->query("SELECT * FROM proveedores WHERE estado = 'activo' ORDER BY nombre");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $proveedores[] = $row;
    }
}

$error = '';
$success = '';

/*
 * Mensaje temporal para SweetAlert.
 * Se elimina al cargar para que no reaparezca al actualizar.
 */
$swal_producto_agregado = $_SESSION['swal_producto_agregado'] ?? null;
unset($_SESSION['swal_producto_agregado']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string) $_POST['action'];
    $acciones_locales = array('create', 'add_stock', 'ajuste_stock', 'toggle_status', 'update');

    if ($vista_global && in_array($action, $acciones_locales, true)) {
        $mensaje_global = 'Selecciona una sucursal concreta antes de modificar productos, precios o existencias.';
        if (in_array($action, array('ajuste_stock', 'update'), true)) {
            productosJson(false, $mensaje_global, array(), 409);
        }
        $error = $mensaje_global;
    } elseif ($action === 'create') {
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $categoria_id = (int) ($_POST['categoria_id'] ?? 0);
        $proveedor_id = !empty($_POST['proveedor_id']) ? (int) $_POST['proveedor_id'] : null;
        $precio_compra = round((float) ($_POST['precio_compra'] ?? 0), 2);
        $precio_venta = round((float) ($_POST['precio_venta'] ?? 0), 2);
        $stock = max(0, (int) ($_POST['stock'] ?? 0));
        $stock_minimo = max(0, (int) ($_POST['stock_minimo'] ?? 0));
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));

        if ($nombre === '') {
            $error = 'El nombre del producto es obligatorio.';
        } elseif ($categoria_id <= 0) {
            $error = 'Debe seleccionar una categoría.';
        } elseif ($precio_compra <= 0 || $precio_venta <= 0) {
            $error = 'Los precios de compra y venta deben ser mayores a cero.';
        } else {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT id FROM productos WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?)) LIMIT 1 FOR UPDATE");
                $stmt->bind_param('s', $nombre);
                $stmt->execute();
                $existente = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $producto_id = $existente ? (int) $existente['id'] : 0;
                $producto_nuevo = $producto_id <= 0;

                if ($producto_nuevo) {
                    $stmt_cat = $conn->prepare('SELECT nombre FROM categorias_productos WHERE id = ? AND estado = \'activo\' LIMIT 1');
                    $stmt_cat->bind_param('i', $categoria_id);
                    $stmt_cat->execute();
                    $categoria = $stmt_cat->get_result()->fetch_assoc();
                    $stmt_cat->close();
                    if (!$categoria) {
                        throw new RuntimeException('La categoría seleccionada no existe o está inactiva.');
                    }

                    $foto_ruta = productosProcesarImagen($nombre, $_FILES['foto'] ?? null);
                    if (!$foto_ruta) {
                        $foto_ruta = getImagenPorDefecto($nombre, (string) $categoria['nombre']);
                    }

                    $stock_legacy = 0;
                    $sql = "INSERT INTO productos
                                (nombre, descripcion, categoria_id, proveedor_id, precio_compra, precio_venta, stock, stock_minimo, foto, estado)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo')";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param(
                        'ssiiddiis',
                        $nombre,
                        $descripcion,
                        $categoria_id,
                        $proveedor_id,
                        $precio_compra,
                        $precio_venta,
                        $stock_legacy,
                        $stock_minimo,
                        $foto_ruta
                    );
                    $stmt->execute();
                    $producto_id = (int) $stmt->insert_id;
                    $stmt->close();
                }

                $stmt = $conn->prepare('SELECT id FROM inventario_sucursales WHERE sucursal_id = ? AND producto_id = ? LIMIT 1 FOR UPDATE');
                $stmt->bind_param('ii', $sucursal_id, $producto_id);
                $stmt->execute();
                $inventario_existente = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($inventario_existente) {
                    throw new RuntimeException('Este producto ya forma parte del inventario de ' . $sucursal_nombre . '.');
                }

                $stmt = $conn->prepare(
                    "INSERT INTO inventario_sucursales
                        (sucursal_id, producto_id, precio_compra, precio_venta, stock, stock_minimo, estado)
                     VALUES (?, ?, ?, ?, ?, ?, 'activo')"
                );
                $stmt->bind_param('iiddii', $sucursal_id, $producto_id, $precio_compra, $precio_venta, $stock, $stock_minimo);
                $stmt->execute();
                $stmt->close();

                $movimiento = registrarMovimientoStock(
                    $conn,
                    $producto_id,
                    'inicial',
                    $stock,
                    'Stock inicial al agregar producto a la sucursal',
                    $user_id,
                    null,
                    'alta_inventario',
                    'Inventario inicial de ' . $sucursal_nombre . ': ' . $stock . ' unidades',
                    $sucursal_id
                );

                if (empty($movimiento['success'])) {
                    throw new RuntimeException((string) ($movimiento['error'] ?? 'No se pudo registrar el movimiento inicial.'));
                }

                $conn->commit();

                $success = $producto_nuevo
                    ? 'Producto registrado en el catálogo y agregado a ' . $sucursal_nombre . ' con ' . $stock . ' unidades.'
                    : 'Producto existente agregado al inventario de ' . $sucursal_nombre . ' con ' . $stock . ' unidades.';

                /*
                 * Aplicar POST/Redirect/GET evita insertar nuevamente
                 * el producto cuando el usuario recarga el navegador.
                 */
                $_SESSION['swal_producto_agregado'] = array(
                    'titulo' => $producto_nuevo
                        ? 'Producto agregado'
                        : 'Producto asignado a la sucursal',
                    'mensaje' => $success,
                    'producto' => $nombre,
                    'sucursal' => $sucursal_nombre,
                    'stock' => $stock,
                );

                header(
                    'Location: productos.php?vista=sucursal#lista-productos'
                );
                exit();
            } catch (Throwable $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    } elseif ($action === 'add_stock') {
        $producto_id = (int) ($_POST['producto_id'] ?? 0);
        $cantidad = (int) ($_POST['cantidad'] ?? 0);
        $motivo = trim((string) ($_POST['motivo'] ?? 'Entrada de stock'));
        $observaciones = trim((string) ($_POST['observaciones'] ?? 'Agregado manualmente desde productos'));

        if ($cantidad <= 0) {
            $error = 'La cantidad debe ser mayor a cero.';
        } else {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('SELECT stock FROM inventario_sucursales WHERE sucursal_id = ? AND producto_id = ? LIMIT 1 FOR UPDATE');
                $stmt->bind_param('ii', $sucursal_id, $producto_id);
                $stmt->execute();
                $inventario = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$inventario) {
                    throw new RuntimeException('El producto no pertenece al inventario de esta sucursal.');
                }

                $movimiento = registrarMovimientoStock($conn, $producto_id, 'entrada', $cantidad, $motivo, $user_id, null, 'entrada_manual', $observaciones, $sucursal_id);
                if (empty($movimiento['success'])) {
                    throw new RuntimeException((string) ($movimiento['error'] ?? 'No se pudo registrar el movimiento.'));
                }

                $stmt = $conn->prepare('UPDATE inventario_sucursales SET stock = stock + ? WHERE sucursal_id = ? AND producto_id = ?');
                $stmt->bind_param('iii', $cantidad, $sucursal_id, $producto_id);
                $stmt->execute();
                if ($stmt->affected_rows !== 1) {
                    throw new RuntimeException('No se pudo actualizar la existencia de la sucursal.');
                }
                $stmt->close();

                $conn->commit();
                $success = 'Se agregaron ' . $cantidad . ' unidades en ' . $sucursal_nombre . '. Nuevo stock: ' . (int) $movimiento['stock_nuevo'] . '.';
            } catch (Throwable $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    } elseif ($action === 'ajuste_stock') {
        $producto_id = (int) ($_POST['producto_id'] ?? 0);
        $tipo_ajuste = (string) ($_POST['tipo_ajuste'] ?? '');
        $motivo = trim((string) ($_POST['motivo_ajuste'] ?? 'Ajuste manual'));
        $observaciones = trim((string) ($_POST['observaciones'] ?? ''));

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('SELECT stock, stock_minimo FROM inventario_sucursales WHERE sucursal_id = ? AND producto_id = ? LIMIT 1 FOR UPDATE');
            $stmt->bind_param('ii', $sucursal_id, $producto_id);
            $stmt->execute();
            $inventario = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$inventario) {
                throw new RuntimeException('El producto no pertenece al inventario de esta sucursal.');
            }

            if ($tipo_ajuste === 'stock_correccion') {
                $nuevo_stock = max(0, (int) ($_POST['stock_fisico'] ?? 0));
                $movimiento = registrarMovimientoStock($conn, $producto_id, 'correccion', $nuevo_stock, $motivo, $user_id, null, 'correccion_inventario', $observaciones, $sucursal_id);
                if (empty($movimiento['success'])) {
                    throw new RuntimeException((string) ($movimiento['error'] ?? 'No se pudo registrar la corrección.'));
                }

                $stmt = $conn->prepare('UPDATE inventario_sucursales SET stock = ? WHERE sucursal_id = ? AND producto_id = ?');
                $stmt->bind_param('iii', $nuevo_stock, $sucursal_id, $producto_id);
                $stmt->execute();
                $stmt->close();
                $mensaje = 'Stock corregido de ' . (int) $inventario['stock'] . ' a ' . $nuevo_stock . ' unidades en ' . $sucursal_nombre . '.';
            } elseif ($tipo_ajuste === 'stock_minimo') {
                $nuevo_minimo = max(0, (int) ($_POST['nuevo_stock_minimo'] ?? 0));
                $diferencia = $nuevo_minimo - (int) $inventario['stock_minimo'];
                $movimiento = registrarMovimientoStock($conn, $producto_id, 'ajuste_minimo', $diferencia, $motivo, $user_id, null, 'ajuste_stock_minimo', $observaciones, $sucursal_id);
                if (empty($movimiento['success'])) {
                    throw new RuntimeException((string) ($movimiento['error'] ?? 'No se pudo registrar el ajuste mínimo.'));
                }

                $stmt = $conn->prepare('UPDATE inventario_sucursales SET stock_minimo = ? WHERE sucursal_id = ? AND producto_id = ?');
                $stmt->bind_param('iii', $nuevo_minimo, $sucursal_id, $producto_id);
                $stmt->execute();
                $stmt->close();
                $mensaje = 'Stock mínimo actualizado de ' . (int) $inventario['stock_minimo'] . ' a ' . $nuevo_minimo . ' unidades en ' . $sucursal_nombre . '.';
            } else {
                throw new InvalidArgumentException('El tipo de ajuste no es válido.');
            }

            $conn->commit();
            productosJson(true, $mensaje, array(
                'sucursal_id' => $sucursal_id,
                'sucursal_nombre' => $sucursal_nombre,
            ));
        } catch (Throwable $e) {
            $conn->rollback();
            productosJson(false, $e->getMessage(), array(), 409);
        }
    } elseif ($action === 'toggle_status') {
        $producto_id = (int) ($_POST['producto_id'] ?? 0);
        $nuevo_estado = (string) ($_POST['nuevo_estado'] ?? '');
        if (!in_array($nuevo_estado, array('activo', 'inactivo'), true)) {
            $error = 'El estado solicitado no es válido.';
        } else {
            $stmt = $conn->prepare('UPDATE inventario_sucursales SET estado = ? WHERE sucursal_id = ? AND producto_id = ?');
            $stmt->bind_param('sii', $nuevo_estado, $sucursal_id, $producto_id);
            $stmt->execute();
            if ($stmt->affected_rows === 1) {
                $success = 'Producto ' . ($nuevo_estado === 'activo' ? 'activado' : 'desactivado') . ' en ' . $sucursal_nombre . '.';
            } else {
                $error = 'No se encontró el producto dentro de esta sucursal.';
            }
            $stmt->close();
        }
    } elseif ($action === 'add_categoria') {
        $nombre = trim((string) ($_POST['nombre_categoria'] ?? ''));
        $descripcion = trim((string) ($_POST['descripcion_categoria'] ?? ''));
        if ($nombre === '') {
            productosJson(false, 'El nombre de la categoría es obligatorio.', array(), 400);
        }
        $stmt = $conn->prepare("INSERT INTO categorias_productos (nombre, descripcion, estado) VALUES (?, ?, 'activo')");
        $stmt->bind_param('ss', $nombre, $descripcion);
        $stmt->execute();
        $nuevo_id = (int) $stmt->insert_id;
        $stmt->close();
        productosJson(true, 'Categoría agregada correctamente.', array('id' => $nuevo_id, 'nombre' => $nombre));
    } elseif ($action === 'add_proveedor') {
        $nombre = trim((string) ($_POST['nombre_proveedor'] ?? ''));
        $contacto = trim((string) ($_POST['contacto_proveedor'] ?? ''));
        $telefono = trim((string) ($_POST['telefono_proveedor'] ?? ''));
        $email = trim((string) ($_POST['email_proveedor'] ?? ''));
        if ($nombre === '') {
            productosJson(false, 'El nombre del proveedor es obligatorio.', array(), 400);
        }
        $stmt = $conn->prepare("INSERT INTO proveedores (nombre, contacto, telefono, email, estado) VALUES (?, ?, ?, ?, 'activo')");
        $stmt->bind_param('ssss', $nombre, $contacto, $telefono, $email);
        $stmt->execute();
        $nuevo_id = (int) $stmt->insert_id;
        $stmt->close();
        productosJson(true, 'Proveedor agregado correctamente.', array('id' => $nuevo_id, 'nombre' => $nombre));
    } elseif ($action === 'update') {
        $producto_id = (int) ($_POST['producto_id'] ?? 0);
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $categoria_id = (int) ($_POST['categoria_id'] ?? 0);
        $proveedor_id = !empty($_POST['proveedor_id']) ? (int) $_POST['proveedor_id'] : null;
        $precio_compra = round((float) ($_POST['precio_compra'] ?? 0), 2);
        $precio_venta = round((float) ($_POST['precio_venta'] ?? 0), 2);
        $descripcion = trim((string) ($_POST['descripcion'] ?? ''));

        if ($nombre === '' || $categoria_id <= 0 || $precio_compra <= 0 || $precio_venta <= 0) {
            productosJson(false, 'Completa correctamente el nombre, la categoría y los precios.', array(), 400);
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('SELECT id FROM inventario_sucursales WHERE sucursal_id = ? AND producto_id = ? LIMIT 1 FOR UPDATE');
            $stmt->bind_param('ii', $sucursal_id, $producto_id);
            $stmt->execute();
            $inventario = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$inventario) {
                throw new RuntimeException('El producto no pertenece al inventario de esta sucursal.');
            }

            $foto_ruta = productosProcesarImagen($nombre, $_FILES['foto'] ?? null);
            if ($foto_ruta) {
                $stmt = $conn->prepare('UPDATE productos SET nombre = ?, categoria_id = ?, proveedor_id = ?, descripcion = ?, foto = ? WHERE id = ?');
                $stmt->bind_param('siissi', $nombre, $categoria_id, $proveedor_id, $descripcion, $foto_ruta, $producto_id);
            } else {
                $stmt = $conn->prepare('UPDATE productos SET nombre = ?, categoria_id = ?, proveedor_id = ?, descripcion = ? WHERE id = ?');
                $stmt->bind_param('siisi', $nombre, $categoria_id, $proveedor_id, $descripcion, $producto_id);
            }
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare('UPDATE inventario_sucursales SET precio_compra = ?, precio_venta = ? WHERE sucursal_id = ? AND producto_id = ?');
            $stmt->bind_param('ddii', $precio_compra, $precio_venta, $sucursal_id, $producto_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            productosJson(true, 'Producto actualizado en ' . $sucursal_nombre . '.', array(
                'sucursal_id' => $sucursal_id,
                'sucursal_nombre' => $sucursal_nombre,
            ));
        } catch (Throwable $e) {
            $conn->rollback();
            productosJson(false, $e->getMessage(), array(), 409);
        }
    }
}

$limites_permitidos = array(10, 20, 50, 100);
$registros_por_pagina = isset($_GET['limite']) ? (int) $_GET['limite'] : 10;
if (!in_array($registros_por_pagina, $limites_permitidos, true)) {
    $registros_por_pagina = 10;
}

$pagina_actual = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$busqueda = trim((string) ($_GET['busqueda'] ?? ''));
$categoria_filtro = isset($_GET['categoria']) ? max(0, (int) $_GET['categoria']) : 0;
$estado_filtro = (string) ($_GET['estado'] ?? 'todos');
if (!in_array($estado_filtro, array('todos', 'activo', 'inactivo'), true)) {
    $estado_filtro = 'todos';
}

$where = array();
$params = array();
$types = '';

if (!$vista_global) {
    $where[] = 'inv.sucursal_id = ?';
    $params[] = $sucursal_id;
    $types .= 'i';
}

if ($busqueda !== '') {
    $where[] = '(p.nombre LIKE ? OR p.descripcion LIKE ?)';
    $params[] = '%' . $busqueda . '%';
    $params[] = '%' . $busqueda . '%';
    $types .= 'ss';
}

if ($categoria_filtro > 0) {
    $where[] = 'p.categoria_id = ?';
    $params[] = $categoria_filtro;
    $types .= 'i';
}

if ($estado_filtro !== 'todos') {
    if ($vista_global) {
        $where[] = 'p.estado = ?';
        $params[] = $estado_filtro;
        $types .= 's';
    } elseif ($estado_filtro === 'activo') {
        $where[] = "p.estado = 'activo' AND inv.estado = 'activo'";
    } else {
        $where[] = "(p.estado <> 'activo' OR inv.estado <> 'activo')";
    }
}

$where_sql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

if ($vista_global) {
    $count_sql = "SELECT COUNT(*) AS total FROM productos p {$where_sql}";
} else {
    $count_sql = "SELECT COUNT(*) AS total
                  FROM inventario_sucursales inv
                  INNER JOIN productos p ON p.id = inv.producto_id
                  {$where_sql}";
}

$count_stmt = $conn->prepare($count_sql);
if (!$count_stmt) {
    die('Error al preparar el conteo de productos: ' . $conn->error);
}
productosBindParams($count_stmt, $types, $params);
$count_stmt->execute();
$total_registros = (int) (($count_stmt->get_result()->fetch_assoc()['total'] ?? 0));
$count_stmt->close();

$total_paginas = max(1, (int) ceil($total_registros / $registros_por_pagina));
if ($pagina_actual > $total_paginas) {
    $pagina_actual = $total_paginas;
}
$offset = ($pagina_actual - 1) * $registros_por_pagina;

if ($vista_global) {
    $sql = "SELECT
                p.id, p.nombre, p.descripcion, p.categoria_id, p.proveedor_id,
                p.foto, p.estado, p.fecha_registro,
                c.nombre AS categoria_nombre,
                prov.nombre AS proveedor_nombre,
                COUNT(inv.id) AS sucursales_count,
                COALESCE(SUM(inv.stock), 0) AS stock,
                COALESCE(SUM(inv.stock_minimo), 0) AS stock_minimo,
                MIN(inv.precio_compra) AS precio_compra_min,
                MAX(inv.precio_compra) AS precio_compra_max,
                MIN(inv.precio_venta) AS precio_venta_min,
                MAX(inv.precio_venta) AS precio_venta_max,
                COALESCE(SUM(CASE WHEN inv.estado = 'activo' THEN 1 ELSE 0 END), 0) AS sucursales_activas
            FROM productos p
            LEFT JOIN inventario_sucursales inv ON inv.producto_id = p.id
            LEFT JOIN categorias_productos c ON c.id = p.categoria_id
            LEFT JOIN proveedores prov ON prov.id = p.proveedor_id
            {$where_sql}
            GROUP BY p.id, p.nombre, p.descripcion, p.categoria_id, p.proveedor_id,
                     p.foto, p.estado, p.fecha_registro, c.nombre, prov.nombre
            ORDER BY p.fecha_registro DESC
            LIMIT ? OFFSET ?";
} else {
    $sql = "SELECT
                p.id, p.nombre, p.descripcion, p.categoria_id, p.proveedor_id,
                p.foto, p.fecha_registro, p.estado AS catalogo_estado,
                c.nombre AS categoria_nombre,
                prov.nombre AS proveedor_nombre,
                inv.id AS inventario_id,
                inv.precio_compra, inv.precio_venta,
                inv.stock, inv.stock_minimo,
                CASE
                    WHEN p.estado = 'activo' AND inv.estado = 'activo' THEN 'activo'
                    ELSE 'inactivo'
                END AS estado
            FROM inventario_sucursales inv
            INNER JOIN productos p ON p.id = inv.producto_id
            LEFT JOIN categorias_productos c ON c.id = p.categoria_id
            LEFT JOIN proveedores prov ON prov.id = p.proveedor_id
            {$where_sql}
            ORDER BY p.fecha_registro DESC
            LIMIT ? OFFSET ?";
}

$params_consulta = $params;
$types_consulta = $types . 'ii';
$params_consulta[] = $registros_por_pagina;
$params_consulta[] = $offset;
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Error al preparar la consulta de productos: ' . $conn->error);
}
productosBindParams($stmt, $types_consulta, $params_consulta);
$stmt->execute();
$result = $stmt->get_result();
$productos = array();
while ($row = $result->fetch_assoc()) {
    $productos[] = $row;
}
$stmt->close();

$query_base = array(
    'vista' => $vista_global ? 'global' : 'sucursal',
    'busqueda' => $busqueda,
    'categoria' => $categoria_filtro,
    'estado' => $estado_filtro,
    'limite' => $registros_por_pagina,
);

function construirUrlProductos($base, $cambios = array())
{
    $parametros = array_merge($base, $cambios);
    foreach ($parametros as $clave => $valor) {
        if ($valor === '' || $valor === null || $valor === 'todos' || $valor === 0 || $valor === '0') {
            unset($parametros[$clave]);
        }
    }
    return '?' . http_build_query($parametros) . '#lista-productos';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Productos - Gym System</title>
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Bootstrap 4 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/css/bootstrap.min.css">
    <!-- AdminLTE CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <?php
    $productos_css_path = __DIR__ . '/css/productos.css';
    $productos_css_version = is_file($productos_css_path) ? filemtime($productos_css_path) : time();
    ?>
    <link rel="stylesheet" href="css/productos.css?v=<?php echo (int) $productos_css_version; ?>">
</head>
<body>
    <div class="dashboard-layout">
        <?php include 'includes/sidebar.php'; ?>

        <main class="main-content">
            <div class="container-fluid p-0">
                <div class="products-page">
                    <!-- Encabezado -->
                    <header class="module-header products-context-header">
                        <div class="module-heading">
                            <span class="module-heading-icon" aria-hidden="true">
                                <i class="fas fa-boxes-stacked"></i>
                            </span>
                            <div>
                                <h1>Gestión de productos</h1>
                                <p>
                                    <?php echo $vista_global
                                        ? ''
                                        : 'Administra el catálogo y el inventario operativo de la sucursal seleccionada.';
                                    ?>
                                </p>
                            </div>
                        </div>

                        <div class="products-context-badge <?php echo $vista_global ? 'global' : ''; ?>">
                            <i class="fas <?php echo $vista_global ? 'fa-chart-pie' : 'fa-building'; ?>"></i>
                            <span>
                                <strong><?php echo htmlspecialchars($vista_global ? 'Todas las sucursales' : $sucursal_nombre, ENT_QUOTES, 'UTF-8'); ?></strong>
                                <small><?php echo htmlspecialchars($vista_global ? count($sucursales_asignadas) . ' sedes consolidadas' : ($sucursal_clave !== '' ? $sucursal_clave : 'Sucursal activa'), ENT_QUOTES, 'UTF-8'); ?></small>
                            </span>
                        </div>
                    </header>

                    <!-- Alertas -->
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show module-alert" role="alert">
                            <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                            <span><?php echo htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'); ?></span>
                            <button type="button" class="close ml-auto" data-dismiss="alert" aria-label="Cerrar">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show module-alert" role="alert">
                            <i class="fas fa-circle-check" aria-hidden="true"></i>
                            <span><?php echo htmlspecialchars((string) $success, ENT_QUOTES, 'UTF-8'); ?></span>
                            <button type="button" class="close ml-auto" data-dismiss="alert" aria-label="Cerrar">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                    <?php endif; ?>

                <?php if ($vista_global): ?>
                    <section class="card module-card products-global-note">
                        <div class="products-global-note-icon"><i class="fas fa-layer-group"></i></div>
                        <div>
                            <span class="products-global-kicker">Vista consolidada</span>
                            <h2>Catálogo e inventario de todas las sucursales</h2>
                            <p>Los precios, el stock y el estado operativo se administran por sede. Selecciona una sucursal en el menú lateral para registrar productos, agregar existencias o realizar ajustes.</p>
                        </div>
                    </section>
                <?php endif; ?>

                <!-- Formulario de Nuevo Producto -->
                <?php if (!$vista_global): ?>
                <div class="card module-card create-card" id="nuevoProductoCard">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">
                                <i class="fas fa-plus-circle"></i> Nuevo producto
                            </h3>
                            <p class="card-description">Registra el artículo y su inventario inicial para <?php echo htmlspecialchars($sucursal_nombre, ENT_QUOTES, 'UTF-8'); ?>.</p>
                        </div>
                        <span class="section-chip"><?php echo htmlspecialchars($sucursal_clave !== '' ? $sucursal_clave : 'Sucursal', ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" id="nuevoProductoForm">
                            <input type="hidden" name="action" value="create">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><i class="fas fa-tag"></i> Nombre del Producto *</label>
                                        <input type="text" name="nombre" class="form-control" placeholder="Ej: Proteína Whey Protein" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><i class="fas fa-list"></i> Categoría *</label>
                                        <div class="select-with-add">
                                            <select name="categoria_id" class="form-control" required>
                                                <option value="">Seleccionar categoría</option>
                                                <?php foreach ($categorias as $cat): ?>
                                                    <option value="<?php echo $cat['id']; ?>">
                                                        <?php echo htmlspecialchars($cat['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn-add" onclick="openCategoriaModal()" title="Agregar nueva categoría">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><i class="fas fa-truck"></i> Proveedor</label>
                                        <div class="select-with-add">
                                            <select name="proveedor_id" class="form-control">
                                                <option value="">Seleccionar proveedor</option>
                                                <?php foreach ($proveedores as $prov): ?>
                                                    <option value="<?php echo $prov['id']; ?>">
                                                        <?php echo htmlspecialchars($prov['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn-add" onclick="openProveedorModal()" title="Agregar nuevo proveedor">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label><i class="fas fa-image"></i> Foto del Producto</label>
                                        <input type="file" name="foto" class="form-control" accept="image/*" onchange="previewImage(this, 'previewNuevo')">
                                        <div id="previewNuevo" style="margin-top: 10px; display: none;"></div>
                                        <small class="text-muted">Formatos: JPG, PNG, WEBP (Max 2MB). Si no se selecciona, se usará una imagen por defecto.</small>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label><i class="fas fa-dollar-sign"></i> Precio de Compra *</label>
                                        <input type="number" name="precio_compra" class="form-control" step="0.01" min="0" placeholder="0.00" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label><i class="fas fa-money-bill-wave"></i> Precio de Venta *</label>
                                        <input type="number" name="precio_venta" class="form-control" step="0.01" min="0" placeholder="0.00" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label><i class="fas fa-box"></i> Stock Actual</label>
                                        <input type="number" name="stock" class="form-control" min="0" value="0" required>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label><i class="fas fa-exclamation-triangle"></i> Stock Mínimo</label>
                                        <input type="number" name="stock_minimo" class="form-control" min="0" value="5" required>
                                        <small class="text-muted">Alertar cuando el stock esté por debajo</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-align-left"></i> Descripción</label>
                                <textarea name="descripcion" class="form-control" rows="3" placeholder="Descripción del producto..."></textarea>
                            </div>
                            
                            <div class="form-group form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Guardar Producto
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class="fas fa-undo"></i> Limpiar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Filtros de búsqueda -->
                <section class="card module-card filter-card" id="filtros-productos">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title">
                                <i class="fas fa-filter"></i> Filtros
                            </h3>
                            <p class="card-description">Busca por nombre, descripción, categoría o estado.</p>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="filters-grid">
                            <div class="form-group">
                                <label for="searchInput"><i class="fas fa-search"></i> Buscar producto</label>
                                <input
                                    type="search"
                                    id="searchInput"
                                    class="form-control"
                                    placeholder="Nombre o descripción..."
                                    value="<?php echo htmlspecialchars($busqueda, ENT_QUOTES, 'UTF-8'); ?>"
                                    autocomplete="off"
                                    oninput="buscarProductos()"
                                >
                            </div>

                            <div class="form-group">
                                <label for="categoriaFilter"><i class="fas fa-list"></i> Categoría</label>
                                <select id="categoriaFilter" class="form-control" onchange="buscarProductos(true)">
                                    <option value="0">Todas las categorías</option>
                                    <?php foreach ($categorias as $cat): ?>
                                        <option
                                            value="<?php echo (int) $cat['id']; ?>"
                                            <?php echo $categoria_filtro === (int) $cat['id'] ? 'selected' : ''; ?>
                                        >
                                            <?php echo htmlspecialchars((string) $cat['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="estadoFilter"><i class="fas fa-circle"></i> Estado</label>
                                <select id="estadoFilter" class="form-control" onchange="buscarProductos(true)">
                                    <option value="todos" <?php echo $estado_filtro === 'todos' ? 'selected' : ''; ?>>Todos</option>
                                    <option value="activo" <?php echo $estado_filtro === 'activo' ? 'selected' : ''; ?>>Activos</option>
                                    <option value="inactivo" <?php echo $estado_filtro === 'inactivo' ? 'selected' : ''; ?>>Inactivos</option>
                                </select>
                            </div>

                            <button type="button" class="btn btn-secondary filter-clear" onclick="limpiarFiltros()">
                                <i class="fas fa-eraser"></i> Limpiar
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Lista de productos -->
                <section class="card module-card product-list-card" id="lista-productos">
                    <div class="card-header">
                        <div class="list-heading-group">
                            <div class="list-title-row">
                                <h3 class="card-title">
                                    <i class="fas fa-boxes"></i> Lista de productos
                                </h3>
                                <span class="section-chip">
                                    <?php echo number_format($total_registros); ?>
                                    <?php echo $total_registros === 1 ? 'registro' : 'registros'; ?>
                                </span>
                            </div>
                            <p class="card-description">
                                <?php echo $vista_global
                                    ? 'Stock total, rango de precios y cobertura por sucursal.'
                                    : 'Precios, existencias y estado de ' . htmlspecialchars($sucursal_nombre, ENT_QUOTES, 'UTF-8') . '.';
                                ?>
                            </p>
                        </div>
                    </div>

                    <div class="card-body">
                        <?php if (!empty($productos)): ?>
                            <div class="products-table-wrap" id="tablaProductos">
                                <table class="table products-table">
                                    <thead>
                                        <tr>
                                            <th>Producto</th>
                                            <th>Categoría</th>
                                            <th>Proveedor</th>
                                            <th>Compra</th>
                                            <th>Venta</th>
                                            <th>Existencia</th>
                                            <th>Estado</th>
                                            <th><?php echo $vista_global ? 'Sucursales' : 'Acciones'; ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($productos as $producto): ?>
                                            <?php
                                            $stock_actual = (int) ($producto['stock'] ?? 0);
                                            $stock_minimo_actual = (int) ($producto['stock_minimo'] ?? 0);
                                            $stock_bajo = $stock_actual <= $stock_minimo_actual;
                                            $producto_activo = ($producto['estado'] ?? '') === 'activo';
                                            $sucursales_count = (int) ($producto['sucursales_count'] ?? 0);
                                            ?>
                                            <tr>
                                                <td data-label="Producto">
                                                    <div class="product-cell">
                                                        <div class="producto-imagen">
                                                            <?php if (!empty($producto['foto']) && file_exists($producto['foto'])): ?>
                                                                <img
                                                                    src="<?php echo htmlspecialchars((string) $producto['foto'], ENT_QUOTES, 'UTF-8'); ?>"
                                                                    alt="<?php echo htmlspecialchars((string) $producto['nombre'], ENT_QUOTES, 'UTF-8'); ?>"
                                                                    loading="lazy"
                                                                >
                                                            <?php else: ?>
                                                                <i class="fas fa-box-open" aria-hidden="true"></i>
                                                            <?php endif; ?>
                                                        </div>

                                                        <div class="product-info">
                                                            <span class="product-name">
                                                                <?php echo htmlspecialchars((string) $producto['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                                            </span>
                                                            <span class="product-description">
                                                                <?php echo htmlspecialchars((string) ($producto['descripcion'] ?: 'Sin descripción'), ENT_QUOTES, 'UTF-8'); ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </td>

                                                <td data-label="Categoría">
                                                    <?php echo htmlspecialchars((string) ($producto['categoria_nombre'] ?: 'Sin categoría'), ENT_QUOTES, 'UTF-8'); ?>
                                                </td>

                                                <td data-label="Proveedor">
                                                    <?php echo htmlspecialchars((string) ($producto['proveedor_nombre'] ?: 'Sin proveedor'), ENT_QUOTES, 'UTF-8'); ?>
                                                </td>

                                                <td data-label="Compra">
                                                    <?php if ($vista_global): ?>
                                                        <?php if ($sucursales_count > 0): ?>
                                                            <span class="money price-range">
                                                                <?php
                                                                $pc_min = (float) $producto['precio_compra_min'];
                                                                $pc_max = (float) $producto['precio_compra_max'];
                                                                echo '$' . number_format($pc_min, 2);
                                                                if (abs($pc_max - $pc_min) > 0.009) {
                                                                    echo ' – $' . number_format($pc_max, 2);
                                                                }
                                                                ?>
                                                            </span>
                                                        <?php else: ?>—<?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="money">$<?php echo number_format((float) $producto['precio_compra'], 2); ?></span>
                                                    <?php endif; ?>
                                                </td>

                                                <td data-label="Venta">
                                                    <?php if ($vista_global): ?>
                                                        <?php if ($sucursales_count > 0): ?>
                                                            <span class="money price-range">
                                                                <?php
                                                                $pv_min = (float) $producto['precio_venta_min'];
                                                                $pv_max = (float) $producto['precio_venta_max'];
                                                                echo '$' . number_format($pv_min, 2);
                                                                if (abs($pv_max - $pv_min) > 0.009) {
                                                                    echo ' – $' . number_format($pv_max, 2);
                                                                }
                                                                ?>
                                                            </span>
                                                        <?php else: ?>—<?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="money">$<?php echo number_format((float) $producto['precio_venta'], 2); ?></span>
                                                    <?php endif; ?>
                                                </td>

                                                <td data-label="Existencia">
                                                    <span class="stock-box <?php echo $stock_bajo ? 'low' : ''; ?>">
                                                        <i class="fas <?php echo $stock_bajo ? 'fa-triangle-exclamation' : 'fa-cube'; ?>"></i>
                                                        <?php if ($vista_global): ?>
                                                            <?php echo $stock_actual; ?> total
                                                        <?php else: ?>
                                                            <?php echo $stock_actual; ?> / mín. <?php echo $stock_minimo_actual; ?>
                                                        <?php endif; ?>
                                                    </span>
                                                </td>

                                                <td data-label="Estado">
                                                    <span class="status-badge <?php echo $producto_activo ? 'active' : 'inactive'; ?>">
                                                        <?php echo $producto_activo ? 'Activo' : 'Inactivo'; ?>
                                                    </span>
                                                </td>

                                                <td data-label="<?php echo $vista_global ? 'Sucursales' : 'Acciones'; ?>">
                                                    <?php if ($vista_global): ?>
                                                        <span class="product-coverage <?php echo $sucursales_count > 0 ? '' : 'empty'; ?>">
                                                            <i class="fas fa-building"></i>
                                                            <?php echo $sucursales_count; ?> <?php echo $sucursales_count === 1 ? 'sede' : 'sedes'; ?>
                                                        </span>
                                                    <?php else: ?>
                                                    <div class="product-actions">
                                                        <button
                                                            type="button"
                                                            class="product-action edit"
                                                            onclick="editProducto(<?php echo (int) $producto['id']; ?>)"
                                                            title="Editar producto"
                                                        >
                                                            <i class="fas fa-pen"></i><span></span>
                                                        </button>

                                                        <button
                                                            type="button"
                                                            class="product-action stock"
                                                            onclick='openStockModal(
                                                                <?php echo (int) $producto['id']; ?>,
                                                                <?php echo json_encode((string) $producto['nombre'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                                                                <?php echo $stock_actual; ?>
                                                            )'
                                                            title="Agregar stock"
                                                        >
                                                            <i class="fas fa-plus"></i><span></span>
                                                        </button>

                                                        <button
                                                            type="button"
                                                            class="product-action adjust"
                                                            onclick='openAjusteModal(
                                                                <?php echo (int) $producto['id']; ?>,
                                                                <?php echo json_encode((string) $producto['nombre'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                                                                <?php echo $stock_actual; ?>,
                                                                <?php echo $stock_minimo_actual; ?>
                                                            )'
                                                            title="Ajustar inventario"
                                                        >
                                                            <i class="fas fa-sliders"></i><span></span>
                                                        </button>

                                                        <button
                                                            type="button"
                                                            class="product-action <?php echo $producto_activo ? 'disable' : 'enable'; ?>"
                                                            onclick="toggleStatus(
                                                                <?php echo (int) $producto['id']; ?>,
                                                                '<?php echo $producto_activo ? 'inactivo' : 'activo'; ?>'
                                                            )"
                                                            title="<?php echo $producto_activo ? 'Desactivar producto' : 'Activar producto'; ?>"
                                                        >
                                                            <i class="fas <?php echo $producto_activo ? 'fa-ban' : 'fa-check'; ?>"></i>
                                                            <span><?php echo $producto_activo ? '' : ''; ?></span>
                                                        </button>
                                                    </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php
                            $registro_desde = $total_registros > 0 ? $offset + 1 : 0;
                            $registro_hasta = min($offset + count($productos), $total_registros);
                            ?>
                            <footer class="table-footer">
                                <div class="table-footer-left">
                                    <label class="limit-control" for="registrosPorPagina">
                                        <span>Mostrar</span>
                                        <select id="registrosPorPagina" onchange="cambiarLimite()">
                                            <?php foreach ($limites_permitidos as $limite): ?>
                                                <option value="<?php echo $limite; ?>" <?php echo $registros_por_pagina === $limite ? 'selected' : ''; ?>>
                                                    <?php echo $limite; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span>registros</span>
                                    </label>

                                    <span class="table-info">
                                        <span>Mostrando</span>
                                        <strong><?php echo $registro_desde; ?>–<?php echo $registro_hasta; ?></strong>
                                        <span>de</span>
                                        <strong><?php echo number_format($total_registros); ?></strong>
                                    </span>
                                </div>

                                <?php if ($total_paginas > 1): ?>
                                    <nav aria-label="Paginación de productos">
                                        <ul class="pagination">
                                            <li class="page-item <?php echo $pagina_actual <= 1 ? 'disabled' : ''; ?>">
                                                <a
                                                    class="page-link"
                                                    href="<?php echo htmlspecialchars(construirUrlProductos($query_base, ['pagina' => max(1, $pagina_actual - 1)]), ENT_QUOTES, 'UTF-8'); ?>"
                                                    aria-label="Página anterior"
                                                >
                                                    <i class="fas fa-chevron-left"></i>
                                                </a>
                                            </li>

                                            <?php
                                            $inicio_paginacion = max(1, $pagina_actual - 2);
                                            $fin_paginacion = min($total_paginas, $pagina_actual + 2);
                                            for ($pagina = $inicio_paginacion; $pagina <= $fin_paginacion; $pagina++):
                                            ?>
                                                <li class="page-item <?php echo $pagina === $pagina_actual ? 'active' : ''; ?>">
                                                    <a
                                                        class="page-link"
                                                        href="<?php echo htmlspecialchars(construirUrlProductos($query_base, ['pagina' => $pagina]), ENT_QUOTES, 'UTF-8'); ?>"
                                                        <?php echo $pagina === $pagina_actual ? 'aria-current="page"' : ''; ?>
                                                    >
                                                        <?php echo $pagina; ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>

                                            <li class="page-item <?php echo $pagina_actual >= $total_paginas ? 'disabled' : ''; ?>">
                                                <a
                                                    class="page-link"
                                                    href="<?php echo htmlspecialchars(construirUrlProductos($query_base, ['pagina' => min($total_paginas, $pagina_actual + 1)]), ENT_QUOTES, 'UTF-8'); ?>"
                                                    aria-label="Página siguiente"
                                                >
                                                    <i class="fas fa-chevron-right"></i>
                                                </a>
                                            </li>
                                        </ul>
                                    </nav>
                                <?php endif; ?>
                            </footer>
                        <?php else: ?>
                            <div class="empty-products">
                                <i class="fas fa-box-open" aria-hidden="true"></i>
                                <strong>No se encontraron productos</strong>
                                <span>Prueba cambiando o limpiando los filtros.</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
                </div>
            </div>
        </main>
    </div>

    <!-- Modales -->
    <div class="modal fade" id="editProductoModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Producto</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="editProductoForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="producto_id" id="edit_producto_id">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><i class="fas fa-tag"></i> Nombre del Producto *</label>
                                    <input type="text" name="nombre" id="edit_nombre" class="form-control" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><i class="fas fa-list"></i> Categoría *</label>
                                    <div class="select-with-add">
                                        <select name="categoria_id" id="edit_categoria_id" class="form-control" required>
                                            <option value="">Seleccionar categoría</option>
                                            <?php foreach ($categorias as $cat): ?>
                                                <option value="<?php echo $cat['id']; ?>">
                                                    <?php echo htmlspecialchars($cat['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn-add" onclick="openCategoriaModalEdit()" title="Agregar nueva categoría">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><i class="fas fa-truck"></i> Proveedor</label>
                                    <div class="select-with-add">
                                        <select name="proveedor_id" id="edit_proveedor_id" class="form-control">
                                            <option value="">Seleccionar proveedor</option>
                                            <?php foreach ($proveedores as $prov): ?>
                                                <option value="<?php echo $prov['id']; ?>">
                                                    <?php echo htmlspecialchars($prov['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn-add" onclick="openProveedorModalEdit()" title="Agregar nuevo proveedor">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><i class="fas fa-image"></i> Foto del Producto</label>
                                    <input type="file" name="foto" id="edit_foto" class="form-control" accept="image/*" onchange="previewImageEdit(this)">
                                    <div id="edit_preview" style="margin-top: 10px; display: none;"></div>
                                    <div id="edit_current_image" style="margin-top: 10px; display: none;"></div>
                                    <small class="text-muted">Formatos: JPG, PNG, WEBP (Max 2MB)</small>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><i class="fas fa-dollar-sign"></i> Precio de Compra *</label>
                                    <input type="number" name="precio_compra" id="edit_precio_compra" class="form-control" step="0.01" min="0" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label><i class="fas fa-money-bill-wave"></i> Precio de Venta *</label>
                                    <input type="number" name="precio_venta" id="edit_precio_venta" class="form-control" step="0.01" min="0" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-align-left"></i> Descripción</label>
                            <textarea name="descripcion" id="edit_descripcion" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Actualizar Producto</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para Agregar Stock -->
    <div class="modal fade" id="stockModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-scrollable" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Agregar Stock</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="POST" action="productos.php">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_stock">
                        <input type="hidden" name="producto_id" id="stock_producto_id">
                        <input type="hidden" name="motivo" value="Entrada de stock">
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Esta acción INCREMENTARÁ el stock del producto.
                        </div>
                        
                        <div class="form-group">
                            <label>Producto:</label>
                            <p><strong id="stock_producto_nombre" class="text-primary"></strong></p>
                        </div>
                        
                        <div class="form-group">
                            <label>Stock Actual:</label>
                            <p><span id="stock_actual" class="badge badge-info" style="font-size: 14px; padding: 8px 12px;"></span></p>
                        </div>
                        
                        <div class="form-group">
                            <label>Cantidad a agregar *</label>
                            <input type="number" name="cantidad" id="cantidad_stock" class="form-control" min="1" required>
                            <small class="text-muted">Nuevo stock: <span id="nuevo_stock_preview">0</span> unidades</small>
                        </div>
                        
                        <div class="form-group">
                            <label>Observaciones (opcional)</label>
                            <textarea name="observaciones" class="form-control" rows="2" placeholder="Motivo de la entrada de stock..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Agregar Stock</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal para Ajuste de Stock -->
    <div class="modal fade" id="ajusteModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-scrollable" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-balance-scale"></i> Corrección de Inventario</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="POST" action="productos.php" id="ajusteForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="ajuste_stock">
                        <input type="hidden" name="producto_id" id="ajuste_producto_id">
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> <strong>Importante:</strong> Esta acción CORREGIRÁ el stock. Úsala con precaución.
                        </div>
                        
                        <div class="form-group">
                            <label>Producto:</label>
                            <p><strong id="ajuste_producto_nombre" class="text-primary"></strong></p>
                        </div>
                        
                        <div class="form-group">
                            <label>Tipo de Ajuste *</label>
                            <select name="tipo_ajuste" id="tipo_ajuste" class="form-control" onchange="mostrarCampoAjuste()" required>
                                <option value="">Seleccionar tipo de ajuste</option>
                                <option value="stock_correccion">Corregir Stock</option>
                                <option value="stock_minimo">Cambiar Stock Mínimo</option>
                            </select>
                        </div>
                        
                        <div id="campo_correccion_stock" style="display: none;">
                            <div class="form-group">
                                <label>Stock Actual en Sistema:</label>
                                <p><span id="ajuste_stock_actual" class="badge badge-info" style="font-size: 14px; padding: 8px 12px;"></span></p>
                            </div>
                            
                            <div class="form-group">
                                <label>Stock Físico (real) *</label>
                                <input type="number" name="stock_fisico" id="stock_fisico" class="form-control" min="0" step="1">
                                <small id="diferencia_stock" class="text-muted"></small>
                            </div>
                        </div>
                        
                        <div id="campo_stock_minimo" style="display: none;">
                            <div class="form-group">
                                <label>Stock Mínimo Actual:</label>
                                <p><span id="ajuste_stock_minimo_actual" class="badge badge-warning" style="font-size: 14px; padding: 8px 12px;"></span></p>
                            </div>
                            
                            <div class="form-group">
                                <label>Nuevo Stock Mínimo *</label>
                                <input type="number" name="nuevo_stock_minimo" id="nuevo_stock_minimo" class="form-control" min="0" step="1">
                                <small id="preview_stock_minimo" class="text-muted"></small>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Motivo del ajuste *</label>
                            <select name="motivo_ajuste" class="form-control" required>
                                <option value="">Seleccionar motivo</option>
                                <option value="inventario_fisico">Inventario físico</option>
                                <option value="merma">Merma / Pérdida</option>
                                <option value="sobrante">Sobrante encontrado</option>
                                <option value="error_sistema">Error de sistema</option>
                                <option value="otros">Otros</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Observaciones</label>
                            <textarea name="observaciones" class="form-control" rows="2" placeholder="Detalles de la corrección..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-warning">Aplicar Corrección</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modales para Categoría y Proveedor -->
    <div class="modal fade" id="categoriaModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-scrollable" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Agregar Nueva Categoría</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nombre de la categoría *</label>
                        <input type="text" id="nombre_categoria" class="form-control" placeholder="Ej: Suplementos">
                    </div>
                    <div class="form-group">
                        <label>Descripción</label>
                        <textarea id="descripcion_categoria" class="form-control" rows="2" placeholder="Descripción opcional"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="guardarCategoria()">Guardar Categoría</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="proveedorModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-scrollable" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Agregar Nuevo Proveedor</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Nombre del proveedor *</label>
                        <input type="text" id="nombre_proveedor" class="form-control" placeholder="Ej: Suplementos Pro">
                    </div>
                    <div class="form-group">
                        <label>Contacto</label>
                        <input type="text" id="contacto_proveedor" class="form-control" placeholder="Nombre del contacto">
                    </div>
                    <div class="form-group">
                        <label>Teléfono</label>
                        <input type="text" id="telefono_proveedor" class="form-control" placeholder="Ej: 555-1234">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" id="email_proveedor" class="form-control" placeholder="correo@ejemplo.com">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="guardarProveedor()">Guardar Proveedor</button>
                </div>
            </div>
        </div>
    </div>

    <form id="toggleStatusForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="toggle_status">
        <input type="hidden" name="producto_id" id="toggle_producto_id">
        <input type="hidden" name="nuevo_estado" id="toggle_nuevo_estado">
    </form>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <?php if (is_array($swal_producto_agregado)): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof Swal === 'undefined') {
            return;
        }

        const alertaProducto = <?php echo json_encode(
            $swal_producto_agregado,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        ); ?>;

        Swal.fire({
            icon: 'success',
            title: alertaProducto.titulo || 'Producto agregado',
            html: `
                <div style="text-align:center;">
                    <p style="
                        margin:0 0 12px;
                        color:#475569;
                        line-height:1.45;
                    ">
                        ${alertaProducto.mensaje || 'El producto se guardó correctamente.'}
                    </p>

                    <div style="
                        display:grid;
                        grid-template-columns:repeat(2,minmax(0,1fr));
                        gap:8px;
                        padding:11px;
                        border:1px solid #dbe4ef;
                        border-radius:10px;
                        background:#f8fafc;
                        text-align:left;
                    ">
                        <div>
                            <span style="
                                display:block;
                                color:#64748b;
                                font-size:.7rem;
                                font-weight:700;
                            ">
                                Sucursal
                            </span>

                            <strong style="color:#1e3a8a;">
                                ${alertaProducto.sucursal || 'Sucursal activa'}
                            </strong>
                        </div>

                        <div>
                            <span style="
                                display:block;
                                color:#64748b;
                                font-size:.7rem;
                                font-weight:700;
                            ">
                                Stock inicial
                            </span>

                            <strong style="color:#047857;">
                                ${Number(alertaProducto.stock || 0)} unidades
                            </strong>
                        </div>
                    </div>
                </div>
            `,
            confirmButtonText: 'Entendido',
            confirmButtonColor: '#1e3a8a',
            allowOutsideClick: false,
            allowEscapeKey: true
        });
    });
    </script>
    <?php endif; ?>
    
    <script>
        let ajusteData = {};
                
        if ('scrollRestoration' in history) {
            history.scrollRestoration = 'manual';
        }

        function desplazarAlAnclaActual() {
            if (!window.location.hash) {
                return;
            }

            const destino = document.querySelector(window.location.hash);
            if (!destino) {
                return;
            }

            destino.scrollIntoView({
                behavior: 'auto',
                block: 'start'
            });
        }

        function construirUrlProductos(pagina = 1) {
            const params = new URLSearchParams();
            params.set('vista', <?php echo json_encode($vista_global ? 'global' : 'sucursal'); ?>);
            const busqueda = document.getElementById('searchInput').value.trim();
            const categoria = document.getElementById('categoriaFilter').value;
            const estado = document.getElementById('estadoFilter').value;
            const limiteSelect = document.getElementById('registrosPorPagina');
            const limite = limiteSelect ? limiteSelect.value : '<?php echo $registros_por_pagina; ?>';

            if (busqueda) params.set('busqueda', busqueda);
            if (categoria && categoria !== '0') params.set('categoria', categoria);
            if (estado && estado !== 'todos') params.set('estado', estado);
            if (limite) params.set('limite', limite);
            params.set('pagina', pagina);

            return '?' + params.toString() + '#filtros-productos';
        }

        function buscarProductos(inmediato = false) {
            clearTimeout(window.searchTimeout);

            const ejecutar = function() {
                window.location.assign(construirUrlProductos(1));
            };

            if (inmediato) {
                ejecutar();
                return;
            }

            window.searchTimeout = setTimeout(ejecutar, 450);
        }

        function cambiarLimite() {
            const url = construirUrlProductos(1).replace('#filtros-productos', '#lista-productos');
            window.location.assign(url);
        }

        function limpiarFiltros() {
            window.location.assign('?vista=<?php echo $vista_global ? 'global' : 'sucursal'; ?>&pagina=1&limite=<?php echo $registros_por_pagina; ?>#filtros-productos');
        }

        function previewImage(input, previewId) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    var preview = document.getElementById(previewId);
                    preview.innerHTML = '<img src="' + e.target.result + '" class="preview-image"><small class="text-muted d-block">Vista previa</small>';
                    preview.style.display = 'block';
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        function previewImageEdit(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    var preview = document.getElementById('edit_preview');
                    preview.innerHTML = '<img src="' + e.target.result + '" class="preview-image"><small class="text-muted d-block">Nueva imagen</small>';
                    preview.style.display = 'block';
                    document.getElementById('edit_current_image').style.display = 'none';
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        function editProducto(id) {
            Swal.fire({
                title: 'Cargando...',
                text: 'Obteniendo datos del producto',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            fetch(`productos_ajax.php?action=get&id=${id}&vista=sucursal`)
                .then(response => response.json())
                .then(data => {
                    Swal.close();
                    
                    if (data.success) {
                        $('#edit_producto_id').val(data.producto.id);
                        $('#edit_nombre').val(data.producto.nombre);
                        $('#edit_categoria_id').val(data.producto.categoria_id);
                        $('#edit_proveedor_id').val(data.producto.proveedor_id || '');
                        $('#edit_precio_compra').val(data.producto.precio_compra);
                        $('#edit_precio_venta').val(data.producto.precio_venta);
                        $('#edit_descripcion').val(data.producto.descripcion || '');
                        
                        // Mostrar imagen actual si existe
                        if (data.producto.foto && data.producto.foto !== 'null' && data.producto.foto !== '') {
                            $('#edit_current_image').html(`
                                <div class="current-image-card">
                                    <div class="current-image-title">
                                        <i class="fas fa-image" aria-hidden="true"></i>
                                        <span>Imagen actual</span>
                                    </div>
                                    <img src="${data.producto.foto}" class="preview-image" alt="Imagen actual del producto">
                                </div>
                            `).show();
                        } else {
                            $('#edit_current_image').hide();
                        }
                        
                        $('#edit_preview').hide().html('');
                        $('#edit_foto').val('');
                        
                        $('#editProductoModal').modal('show');
                    } else {
                        Swal.fire('Error', data.error || 'No se pudo cargar el producto', 'error');
                    }
                })
                .catch(error => {
                    Swal.close();
                    Swal.fire('Error', 'Error al cargar los datos del producto', 'error');
                });
        }

        function openStockModal(id, nombre, stockActual) {
            $('#stock_producto_id').val(id);
            $('#stock_producto_nombre').text(nombre);
            $('#stock_actual').text(stockActual + ' unidades');
            $('#cantidad_stock').val('');
            $('#nuevo_stock_preview').text('0 unidades');
            
            $('#cantidad_stock').off('input').on('input', function() {
                const cantidad = parseInt($(this).val()) || 0;
                const nuevoStock = parseInt(stockActual) + cantidad;
                $('#nuevo_stock_preview').text(nuevoStock + ' unidades');
            });
            
            $('#stockModal').modal('show');
        }

        function openAjusteModal(id, nombre, stockActual, stockMinimo) {
            $('#ajuste_producto_id').val(id);
            $('#ajuste_producto_nombre').text(nombre);
            $('#ajuste_stock_actual').text(stockActual + ' unidades');
            $('#ajuste_stock_minimo_actual').text(stockMinimo + ' unidades');
            
            ajusteData = {
                stock: stockActual,
                stockMinimo: stockMinimo
            };
            
            $('#tipo_ajuste').val('');
            $('#campo_correccion_stock').hide();
            $('#campo_stock_minimo').hide();
            $('#stock_fisico').val('');
            $('#nuevo_stock_minimo').val('');
            $('#diferencia_stock').text('');
            $('#preview_stock_minimo').text('');
            
            $('#ajusteModal').modal('show');
        }

        function mostrarCampoAjuste() {
            var tipo = document.getElementById('tipo_ajuste').value;
            
            $('#campo_correccion_stock').hide();
            $('#campo_stock_minimo').hide();
            
            if (tipo === 'stock_correccion') {
                $('#campo_correccion_stock').show();
                
                $('#stock_fisico').off('input').on('input', function() {
                    const stockFisico = parseInt($(this).val()) || 0;
                    const diferencia = stockFisico - ajusteData.stock;
                    
                    if (diferencia > 0) {
                        $('#diferencia_stock').html('<span class="text-success">Aumentará en ' + diferencia + ' unidades. Nuevo stock: ' + stockFisico + '</span>');
                    } else if (diferencia < 0) {
                        $('#diferencia_stock').html('<span class="text-danger">Disminuirá en ' + Math.abs(diferencia) + ' unidades. Nuevo stock: ' + stockFisico + '</span>');
                    } else {
                        $('#diferencia_stock').html('<span class="text-muted">Sin cambios. Stock actual: ' + ajusteData.stock + ' unidades</span>');
                    }
                });
                
                $('#nuevo_stock_minimo').removeAttr('required');
                $('#stock_fisico').attr('required', true);
                
            } else if (tipo === 'stock_minimo') {
                $('#campo_stock_minimo').show();
                
                $('#nuevo_stock_minimo').off('input').on('input', function() {
                    const nuevoMinimo = parseInt($(this).val()) || 0;
                    if (nuevoMinimo !== ajusteData.stockMinimo) {
                        if (nuevoMinimo > ajusteData.stockMinimo) {
                            $('#preview_stock_minimo').html('<span class="text-warning">Stock mínimo aumentará de ' + ajusteData.stockMinimo + ' a ' + nuevoMinimo + ' unidades</span>');
                        } else {
                            $('#preview_stock_minimo').html('<span class="text-info">Stock mínimo disminuirá de ' + ajusteData.stockMinimo + ' a ' + nuevoMinimo + ' unidades</span>');
                        }
                    } else {
                        $('#preview_stock_minimo').html('<span class="text-muted">Stock mínimo actual: ' + ajusteData.stockMinimo + ' unidades (sin cambios)</span>');
                    }
                });
                
                $('#stock_fisico').removeAttr('required');
                $('#nuevo_stock_minimo').attr('required', true);
            }
        }

        $('#ajusteForm').on('submit', function(e) {
            e.preventDefault();
            
            const tipoAjuste = $('#tipo_ajuste').val();
            let mensajeConfirmacion = '';
            let confirmText = '';
            
            if (tipoAjuste === 'stock_correccion') {
                const stockFisico = $('#stock_fisico').val();
                const diferencia = stockFisico - ajusteData.stock;
                if (diferencia > 0) {
                    mensajeConfirmacion = `
                        <div style="text-align: left;">
                            <p><strong>Producto:</strong> ${$('#ajuste_producto_nombre').text()}</p>
                            <p><strong>Stock actual:</strong> ${ajusteData.stock} unidades</p>
                            <p><strong>Stock físico:</strong> ${stockFisico} unidades</p>
                            <p class="text-success"><strong>Cambio:</strong> Aumentará en ${diferencia} unidades</p>
                            <p><strong>Nuevo stock:</strong> ${stockFisico} unidades</p>
                        </div>
                    `;
                    confirmText = 'Sí, aumentar stock';
                } else if (diferencia < 0) {
                    mensajeConfirmacion = `
                        <div style="text-align: left;">
                            <p><strong>Producto:</strong> ${$('#ajuste_producto_nombre').text()}</p>
                            <p><strong>Stock actual:</strong> ${ajusteData.stock} unidades</p>
                            <p><strong>Stock físico:</strong> ${stockFisico} unidades</p>
                            <p class="text-danger"><strong>Cambio:</strong> Disminuirá en ${Math.abs(diferencia)} unidades</p>
                            <p><strong>Nuevo stock:</strong> ${stockFisico} unidades</p>
                        </div>
                    `;
                    confirmText = 'Sí, disminuir stock';
                } else {
                    mensajeConfirmacion = `
                        <div style="text-align: left;">
                            <p><strong>Producto:</strong> ${$('#ajuste_producto_nombre').text()}</p>
                            <p><strong>Stock actual:</strong> ${ajusteData.stock} unidades</p>
                            <p><strong>Stock físico:</strong> ${stockFisico} unidades</p>
                            <p class="text-muted"><strong>Sin cambios</strong></p>
                        </div>
                    `;
                    confirmText = 'Sí, continuar';
                }
            } else if (tipoAjuste === 'stock_minimo') {
                const nuevoMinimo = $('#nuevo_stock_minimo').val();
                if (nuevoMinimo != ajusteData.stockMinimo) {
                    mensajeConfirmacion = `
                        <div style="text-align: left;">
                            <p><strong>Producto:</strong> ${$('#ajuste_producto_nombre').text()}</p>
                            <p><strong>Stock mínimo actual:</strong> ${ajusteData.stockMinimo} unidades</p>
                            <p><strong>Nuevo stock mínimo:</strong> ${nuevoMinimo} unidades</p>
                            <p class="text-warning"><strong>Cambio:</strong> ${nuevoMinimo > ajusteData.stockMinimo ? 'Aumentará' : 'Disminuirá'} en ${Math.abs(nuevoMinimo - ajusteData.stockMinimo)} unidades</p>
                        </div>
                    `;
                } else {
                    mensajeConfirmacion = `
                        <div style="text-align: left;">
                            <p><strong>Producto:</strong> ${$('#ajuste_producto_nombre').text()}</p>
                            <p><strong>Stock mínimo actual:</strong> ${ajusteData.stockMinimo} unidades</p>
                            <p><strong>Nuevo stock mínimo:</strong> ${nuevoMinimo} unidades</p>
                            <p class="text-muted"><strong>Sin cambios</strong></p>
                        </div>
                    `;
                }
                confirmText = 'Sí, actualizar';
            }
            
            Swal.fire({
                title: '¿Confirmar ajuste?',
                html: mensajeConfirmacion,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ffc107',
                cancelButtonColor: '#d33',
                confirmButtonText: confirmText,
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Procesando...',
                        text: 'Guardando los cambios',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    const formData = new FormData(this);
                    
                    fetch('productos.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                title: '¡Éxito!',
                                text: data.message,
                                icon: 'success',
                                confirmButtonText: 'OK'
                            }).then(() => {
                                $('#ajusteModal').modal('hide');
                                buscarProductos();
                            });
                        } else {
                            Swal.fire({
                                title: 'Error',
                                text: data.error || 'Error al aplicar el ajuste',
                                icon: 'error',
                                confirmButtonText: 'OK'
                            });
                        }
                    })
                    .catch(error => {
                        Swal.fire({
                            title: 'Error',
                            text: 'Error de conexión: ' + error,
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    });
                }
            });
        });
        
        function toggleStatus(id, estado) {
            const accion = estado === 'activo' ? 'activar' : 'desactivar';
            Swal.fire({
                title: '¿Estás seguro?',
                text: `¿Deseas ${accion} este producto?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Sí, ' + accion,
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('toggle_producto_id').value = id;
                    document.getElementById('toggle_nuevo_estado').value = estado;
                    document.getElementById('toggleStatusForm').submit();
                }
            });
        }
        
        let categoriaOrigen = '';
        let proveedorOrigen = '';
        
        function openCategoriaModal() {
            categoriaOrigen = 'nuevo';
            $('#categoriaModal').modal('show');
        }
        
        function openCategoriaModalEdit() {
            categoriaOrigen = 'edit';
            $('#categoriaModal').modal('show');
        }
        
        function guardarCategoria() {
            const nombre = document.getElementById('nombre_categoria').value;
            const descripcion = document.getElementById('descripcion_categoria').value;
            
            if (!nombre) {
                Swal.fire('Advertencia', 'Por favor ingrese el nombre de la categoría', 'warning');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'add_categoria');
            formData.append('nombre_categoria', nombre);
            formData.append('descripcion_categoria', descripcion);
            
            fetch('productos.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (categoriaOrigen === 'nuevo') {
                        const select = document.querySelector('#nuevoProductoForm select[name="categoria_id"]');
                        const option = document.createElement('option');
                        option.value = data.id;
                        option.text = data.nombre;
                        select.appendChild(option);
                        select.value = data.id;
                    } else if (categoriaOrigen === 'edit') {
                        const select = document.getElementById('edit_categoria_id');
                        const option = document.createElement('option');
                        option.value = data.id;
                        option.text = data.nombre;
                        select.appendChild(option);
                        select.value = data.id;
                    }
                    
                    const filterSelect = document.getElementById('categoriaFilter');
                    const filterOption = document.createElement('option');
                    filterOption.value = data.id;
                    filterOption.text = data.nombre;
                    filterSelect.appendChild(filterOption);
                    
                    $('#categoriaModal').modal('hide');
                    document.getElementById('nombre_categoria').value = '';
                    document.getElementById('descripcion_categoria').value = '';
                    
                    Swal.fire('Éxito', 'Categoría agregada correctamente', 'success');
                } else {
                    Swal.fire('Error', 'Error al guardar categoría: ' + data.error, 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Error de conexión', 'error');
            });
        }
        
        function openProveedorModal() {
            proveedorOrigen = 'nuevo';
            $('#proveedorModal').modal('show');
        }
        
        function openProveedorModalEdit() {
            proveedorOrigen = 'edit';
            $('#proveedorModal').modal('show');
        }
        
        function guardarProveedor() {
            const nombre = document.getElementById('nombre_proveedor').value;
            const contacto = document.getElementById('contacto_proveedor').value;
            const telefono = document.getElementById('telefono_proveedor').value;
            const email = document.getElementById('email_proveedor').value;
            
            if (!nombre) {
                Swal.fire('Advertencia', 'Por favor ingrese el nombre del proveedor', 'warning');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'add_proveedor');
            formData.append('nombre_proveedor', nombre);
            formData.append('contacto_proveedor', contacto);
            formData.append('telefono_proveedor', telefono);
            formData.append('email_proveedor', email);
            
            fetch('productos.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (proveedorOrigen === 'nuevo') {
                        const select = document.querySelector('#nuevoProductoForm select[name="proveedor_id"]');
                        const option = document.createElement('option');
                        option.value = data.id;
                        option.text = data.nombre;
                        select.appendChild(option);
                        select.value = data.id;
                    } else if (proveedorOrigen === 'edit') {
                        const select = document.getElementById('edit_proveedor_id');
                        const option = document.createElement('option');
                        option.value = data.id;
                        option.text = data.nombre;
                        select.appendChild(option);
                        select.value = data.id;
                    }
                    
                    $('#proveedorModal').modal('hide');
                    document.getElementById('nombre_proveedor').value = '';
                    document.getElementById('contacto_proveedor').value = '';
                    document.getElementById('telefono_proveedor').value = '';
                    document.getElementById('email_proveedor').value = '';
                    
                    Swal.fire('Éxito', 'Proveedor agregado correctamente', 'success');
                } else {
                    Swal.fire('Error', 'Error al guardar proveedor: ' + data.error, 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Error de conexión', 'error');
            });
        }
        
        $('#editProductoForm').on('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('productos.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Éxito', data.message, 'success').then(() => {
                        $('#editProductoModal').modal('hide');
                        buscarProductos();
                    });
                } else {
                    Swal.fire('Error', data.error, 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Error al actualizar el producto', 'error');
            });
        });
        
        $(document).ready(function() {
            $('[title]').tooltip();
        });

        window.addEventListener('load', function() {
            requestAnimationFrame(function() {
                desplazarAlAnclaActual();
                setTimeout(desplazarAlAnclaActual, 120);
            });
        });
    </script>
</body>
</html>