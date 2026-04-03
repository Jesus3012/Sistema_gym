<?php
// clases.php
session_start();
require_once 'config/database.php';

// Crear instancia de la base de datos y obtener la conexión
$database = new Database();
$conn = $database->getConnection();

// Verificar que la conexión existe
if (!$conn) {
    die("Error: No se pudo establecer la conexión a la base de datos");
}

// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Obtener datos del usuario actual
$usuario_id = $_SESSION['user_id'];
$usuario_nombre = $_SESSION['user_name'];
$usuario_rol = $_SESSION['user_rol'];

// Procesar acciones
$mensaje = '';
$error = '';

// Crear nueva clase
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'crear_clase') {
    try {
        $nombre = trim($_POST['nombre']);
        $descripcion = trim($_POST['descripcion']);
        $horario = trim($_POST['horario']);
        $instructor = trim($_POST['instructor']);
        $cupo_maximo = intval($_POST['cupo_maximo']);
        $duracion_minutos = intval($_POST['duracion_minutos']);
        
        if (empty($nombre) || empty($horario) || empty($instructor) || $cupo_maximo <= 0) {
            throw new Exception('Por favor complete todos los campos requeridos');
        }
        
        $stmt = $conn->prepare("INSERT INTO clases (nombre, descripcion, horario, instructor, cupo_maximo, cupo_actual, duracion_minutos, estado) VALUES (?, ?, ?, ?, ?, 0, ?, 'activa')");
        $stmt->bind_param("ssssii", $nombre, $descripcion, $horario, $instructor, $cupo_maximo, $duracion_minutos);
        $stmt->execute();
        
        $_SESSION['mensaje_exito'] = 'Clase creada exitosamente';
        header('Location: clases.php');
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: clases.php');
        exit;
    }
}

// Editar clase
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'editar_clase') {
    try {
        $id = $_POST['clase_id'];
        $nombre = trim($_POST['nombre']);
        $descripcion = trim($_POST['descripcion']);
        $horario = trim($_POST['horario']);
        $instructor = trim($_POST['instructor']);
        $cupo_maximo = intval($_POST['cupo_maximo']);
        $duracion_minutos = intval($_POST['duracion_minutos']);
        $estado = $_POST['estado'];
        
        if (empty($nombre) || empty($horario) || empty($instructor) || $cupo_maximo <= 0) {
            throw new Exception('Por favor complete todos los campos requeridos');
        }
        
        $stmt = $conn->prepare("UPDATE clases SET nombre = ?, descripcion = ?, horario = ?, instructor = ?, cupo_maximo = ?, duracion_minutos = ?, estado = ? WHERE id = ?");
        $stmt->bind_param("ssssiisi", $nombre, $descripcion, $horario, $instructor, $cupo_maximo, $duracion_minutos, $estado, $id);
        $stmt->execute();
        
        $_SESSION['mensaje_exito'] = 'Clase actualizada exitosamente';
        header('Location: clases.php');
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: clases.php');
        exit;
    }
}

// Eliminar clase
if (isset($_GET['eliminar']) && is_numeric($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    try {
        // Verificar si hay inscripciones asociadas
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM inscripciones_clases WHERE clase_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = $result->fetch_assoc();
        
        if ($count['total'] > 0) {
            throw new Exception('No se puede eliminar la clase porque tiene inscripciones asociadas');
        }
        
        $stmt = $conn->prepare("DELETE FROM clases WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        $_SESSION['mensaje_exito'] = 'Clase eliminada exitosamente';
        header('Location: clases.php');
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: clases.php');
        exit;
    }
}

// Obtener listado de clases
$search = isset($_GET['search']) ? $_GET['search'] : '';
$estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'id';
$order = isset($_GET['order']) ? $_GET['order'] : 'DESC';
$limit = 10;
$offset = ($page - 1) * $limit;

// Mapeo de columnas para ordenamiento
$sort_columns = [
    'nombre' => 'nombre',
    'instructor' => 'instructor',
    'horario' => 'horario',
    'cupo' => 'cupo_maximo',
    'duracion' => 'duracion_minutos',
    'estado' => 'estado'
];

$order_by = isset($sort_columns[$sort]) ? $sort_columns[$sort] : 'id';
$order_dir = ($order == 'ASC') ? 'ASC' : 'DESC';

$query = "SELECT * FROM clases WHERE 1=1";
$count_query = "SELECT COUNT(*) as total FROM clases WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (nombre LIKE ? OR instructor LIKE ? OR horario LIKE ?)";
    $count_query .= " AND (nombre LIKE ? OR instructor LIKE ? OR horario LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if (!empty($estado)) {
    $query .= " AND estado = ?";
    $count_query .= " AND estado = ?";
    $params[] = $estado;
    $types .= "s";
}

$query .= " ORDER BY $order_by $order_dir LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $bind_params = array_values($params);
    $stmt->bind_param($types, ...$bind_params);
}
$stmt->execute();
$result = $stmt->get_result();
$clases = $result->fetch_all(MYSQLI_ASSOC);

// Obtener total de registros para paginación
$count_params = array_slice($params, 0, count($params) - 2);
$count_types = substr($types, 0, -2);
$stmt_count = $conn->prepare($count_query);
if (!empty($count_params)) {
    $bind_count_params = array_values($count_params);
    $stmt_count->bind_param($count_types, ...$bind_count_params);
}
$stmt_count->execute();
$total_result = $stmt_count->get_result();
$total_rows = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clases - Sistema Gimnasio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #f4f6f9;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100vh;
            background: #1e3a8a;
            color: white;
            z-index: 1000;
            overflow-y: auto;
        }
        
        .main-content {
            margin-left: 260px;
            padding: 20px;
            min-height: 100vh;
        }
        
        .card-custom {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .card-header-custom {
            background: #1e3a8a;
            color: white;
            padding: 12px 20px;
            border-radius: 8px 8px 0 0;
            font-weight: 600;
        }
        
        .card-body-custom {
            padding: 20px;
        }
        
        .btn-custom-primary {
            background: #1e3a8a;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-custom-primary:hover {
            background: #152c6b;
            transform: translateY(-1px);
        }
        
        .btn-accion {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-editar {
            background: #3b82f6;
            color: white;
        }
        
        .btn-editar:hover {
            background: #2563eb;
        }
        
        .btn-eliminar {
            background: #dc2626;
            color: white;
        }
        
        .btn-eliminar:hover {
            background: #b91c1c;
        }
        
        .badge-activa {
            background: #10b981;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .badge-inactiva {
            background: #6b7280;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .table-simple {
            width: 100%;
            background: white;
            border-collapse: collapse;
        }
        
        .table-simple th,
        .table-simple td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
            vertical-align: middle;
        }
        
        .table-simple th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        
        .table-simple tr:hover {
            background: #f8f9fa;
        }
        
        .pagination {
            margin-top: 20px;
            justify-content: center;
        }
        
        .page-link {
            color: #1e3a8a;
            cursor: pointer;
        }
        
        .page-item.active .page-link {
            background-color: #1e3a8a;
            border-color: #1e3a8a;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .table-simple th,
            .table-simple td {
                padding: 8px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="mb-4">
            <h2>Gestión de Clases</h2>
        </div>
        
        <!-- Alertas -->
        <?php if(isset($_SESSION['mensaje_exito'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php 
            echo $_SESSION['mensaje_exito'];
            unset($_SESSION['mensaje_exito']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php 
            echo $_SESSION['error'];
            unset($_SESSION['error']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Botón Nueva Clase -->
        <div class="mb-3">
            <button class="btn-custom-primary" data-bs-toggle="modal" data-bs-target="#modalNuevaClase">
                <i class="fas fa-plus-circle"></i> Nueva Clase
            </button>
        </div>
        
        <!-- Filtros -->
        <div class="card-custom">
            <div class="card-header-custom">
                <i class="fas fa-filter"></i> Filtros de Búsqueda
            </div>
            <div class="card-body-custom">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Buscar</label>
                        <input type="text" class="form-control" id="searchInput" placeholder="Nombre, instructor o horario..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Estado</label>
                        <select class="form-select" id="estadoSelect">
                            <option value="">Todos</option>
                            <option value="activa" <?php echo $estado == 'activa' ? 'selected' : ''; ?>>Activa</option>
                            <option value="inactiva" <?php echo $estado == 'inactiva' ? 'selected' : ''; ?>>Inactiva</option>
                        </select>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label">&nbsp;</label>
                        <button class="btn btn-secondary w-100" id="limpiarFiltros">
                            <i class="fas fa-eraser"></i> Limpiar
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabla de Clases -->
        <div class="card-custom">
            <div class="card-header-custom">
                <i class="fas fa-chalkboard-user"></i> Listado de Clases
            </div>
            <div class="card-body-custom" style="padding: 0;">
                <div style="overflow-x: auto;">
                    <table class="table-simple">
                        <thead>
                            <tr>
                                <th><a href="?sort=nombre&order=<?php echo ($sort == 'nombre' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Clase</a></th>
                                <th><a href="?sort=instructor&order=<?php echo ($sort == 'instructor' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Instructor</a></th>
                                <th><a href="?sort=horario&order=<?php echo ($sort == 'horario' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Horario</a></th>
                                <th><a href="?sort=cupo&order=<?php echo ($sort == 'cupo' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Cupo</a></th>
                                <th><a href="?sort=duracion&order=<?php echo ($sort == 'duracion' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Duración</a></th>
                                <th><a href="?sort=estado&order=<?php echo ($sort == 'estado' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Estado</a></th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($clases as $clase): 
                                $cupo_disponible = $clase['cupo_maximo'] - $clase['cupo_actual'];
                                $porcentaje_ocupado = ($clase['cupo_actual'] / $clase['cupo_maximo']) * 100;
                                
                                if ($cupo_disponible == 0) {
                                    $cupo_class = 'text-danger';
                                    $cupo_texto = 'Completo';
                                } elseif ($porcentaje_ocupado >= 80) {
                                    $cupo_class = 'text-warning';
                                    $cupo_texto = "{$cupo_disponible} disponibles";
                                } else {
                                    $cupo_class = 'text-success';
                                    $cupo_texto = "{$cupo_disponible} disponibles";
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($clase['nombre']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars(substr($clase['descripcion'], 0, 50)); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($clase['instructor']); ?></td>
                                <td><i class="far fa-clock"></i> <?php echo htmlspecialchars($clase['horario']); ?></td>
                                <td>
                                    <span class="<?php echo $cupo_class; ?>">
                                        <?php echo $clase['cupo_actual']; ?>/<?php echo $clase['cupo_maximo']; ?>
                                        <small>(<?php echo $cupo_texto; ?>)</small>
                                    </span>
                                </td>
                                <td><?php echo $clase['duracion_minutos']; ?> min</td>
                                <td>
                                    <?php if($clase['estado'] == 'activa'): ?>
                                        <span class="badge-activa">Activa</span>
                                    <?php else: ?>
                                        <span class="badge-inactiva">Inactiva</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn-accion btn-editar" onclick="editarClase(<?php echo $clase['id']; ?>)" title="Editar">
                                        <i class="fas fa-edit"></i> Editar
                                    </button>
                                    <button class="btn-accion btn-eliminar" onclick="eliminarClase(<?php echo $clase['id']; ?>, '<?php echo htmlspecialchars($clase['nombre']); ?>')" title="Eliminar">
                                        <i class="fas fa-trash"></i> Eliminar
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($clases)): ?>
                            <tr>
                                <td colspan="7" class="text-center" style="padding: 40px;">
                                    <i class="fas fa-chalkboard" style="font-size: 48px; color: #ccc;"></i>
                                    <p class="mt-2">No hay clases registradas</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Paginación -->
                <?php if($total_pages > 1): ?>
                <div class="pagination">
                    <ul class="pagination">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>&sort=<?php echo $sort; ?>&order=<?php echo $order; ?>">Anterior</a>
                        </li>
                        <?php for($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>&sort=<?php echo $sort; ?>&order=<?php echo $order; ?>"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>&sort=<?php echo $sort; ?>&order=<?php echo $order; ?>">Siguiente</a>
                        </li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Nueva Clase -->
    <div class="modal fade" id="modalNuevaClase" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: #1e3a8a; color: white;">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Nueva Clase</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="formNuevaClase" method="POST">
                    <input type="hidden" name="action" value="crear_clase">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nombre de la Clase *</label>
                            <input type="text" class="form-control" name="nombre" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Descripción</label>
                            <textarea class="form-control" name="descripcion" rows="3" placeholder="Descripción de la clase..."></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Horario *</label>
                            <input type="text" class="form-control" name="horario" placeholder="Ej: Lunes y Miércoles 19:00 - 20:00" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Instructor *</label>
                            <input type="text" class="form-control" name="instructor" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Cupo Máximo *</label>
                                <input type="number" class="form-control" name="cupo_maximo" min="1" value="20" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Duración (minutos) *</label>
                                <input type="number" class="form-control" name="duracion_minutos" min="15" step="15" value="60" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar Clase</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Editar Clase -->
    <div class="modal fade" id="modalEditarClase" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: #1e3a8a; color: white;">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Clase</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="formEditarClase" method="POST">
                    <input type="hidden" name="action" value="editar_clase">
                    <input type="hidden" name="clase_id" id="edit_clase_id">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nombre de la Clase *</label>
                            <input type="text" class="form-control" name="nombre" id="edit_nombre" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Descripción</label>
                            <textarea class="form-control" name="descripcion" id="edit_descripcion" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Horario *</label>
                            <input type="text" class="form-control" name="horario" id="edit_horario" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Instructor *</label>
                            <input type="text" class="form-control" name="instructor" id="edit_instructor" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Cupo Máximo *</label>
                                <input type="number" class="form-control" name="cupo_maximo" id="edit_cupo_maximo" min="1" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Duración (minutos) *</label>
                                <input type="number" class="form-control" name="duracion_minutos" id="edit_duracion_minutos" min="15" step="15" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Estado *</label>
                            <select class="form-select" name="estado" id="edit_estado" required>
                                <option value="activa">Activa</option>
                                <option value="inactiva">Inactiva</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Actualizar Clase</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Filtros en tiempo real
        let timeoutBusqueda;
        $('#searchInput').on('input', function() {
            clearTimeout(timeoutBusqueda);
            timeoutBusqueda = setTimeout(function() {
                const search = $('#searchInput').val();
                const estado = $('#estadoSelect').val();
                window.location.href = '?search=' + encodeURIComponent(search) + '&estado=' + encodeURIComponent(estado);
            }, 500);
        });
        
        $('#estadoSelect').on('change', function() {
            const search = $('#searchInput').val();
            const estado = $(this).val();
            window.location.href = '?search=' + encodeURIComponent(search) + '&estado=' + encodeURIComponent(estado);
        });
        
        $('#limpiarFiltros').on('click', function() {
            window.location.href = '?';
        });
        
        // Prevenir doble envío
        $('#formNuevaClase, #formEditarClase').on('submit', function() {
            const $btn = $(this).find('button[type="submit"]');
            if ($btn.data('submitted') === true) {
                return false;
            }
            $btn.data('submitted', true);
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Procesando...');
            return true;
        });
        
        // Resetear formularios
        $('#modalNuevaClase, #modalEditarClase').on('hidden.bs.modal', function() {
            const $btn = $(this).find('button[type="submit"]');
            $btn.prop('disabled', false).html($btn.data('original-text') || 'Guardar');
            $btn.removeData('submitted');
            if ($(this).find('form')[0]) {
                $(this).find('form')[0].reset();
            }
        });
        
        // Guardar texto original de los botones
        $('button[type="submit"]').each(function() {
            $(this).data('original-text', $(this).html());
        });
        
        // Función para editar clase
        function editarClase(id) {
            $.ajax({
                url: 'includes/obtener_clase.php',
                method: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        $('#edit_clase_id').val(data.id);
                        $('#edit_nombre').val(data.nombre);
                        $('#edit_descripcion').val(data.descripcion);
                        $('#edit_horario').val(data.horario);
                        $('#edit_instructor').val(data.instructor);
                        $('#edit_cupo_maximo').val(data.cupo_maximo);
                        $('#edit_duracion_minutos').val(data.duracion_minutos);
                        $('#edit_estado').val(data.estado);
                        $('#modalEditarClase').modal('show');
                    } else {
                        Swal.fire('Error', 'No se pudo obtener los datos de la clase', 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Error al cargar los datos de la clase', 'error');
                }
            });
        }
        
        // Función para eliminar clase
        function eliminarClase(id, nombre) {
            Swal.fire({
                title: '¿Eliminar clase?',
                html: `¿Estás seguro de que deseas eliminar la clase "<strong>${nombre}</strong>"?<br><small class="text-danger">Esta acción no se puede deshacer</small>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '?eliminar=' + id;
                }
            });
        }
        
        // Para móvil: toggle sidebar
        if (window.innerWidth <= 768) {
            $('.sidebar').addClass('sidebar-hidden');
            $('.main-content').css('margin-left', '0');
        }
    </script>
</body>
</html>