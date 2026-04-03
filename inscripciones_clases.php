<?php
// inscripciones_clases.php
session_start();
require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

if (!$conn) {
    die("Error: No se pudo establecer la conexión a la base de datos");
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$usuario_id = $_SESSION['user_id'];
$usuario_nombre = $_SESSION['user_name'];
$usuario_rol = $_SESSION['user_rol'];

// Procesar inscripción a clase
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'inscribir_cliente') {
    try {
        $clase_id = $_POST['clase_id'];
        $cliente_id = $_POST['cliente_id'];
        $fecha_inscripcion = date('Y-m-d');
        
        // Verificar si ya está inscrito
        $stmt = $conn->prepare("SELECT id FROM inscripciones_clases WHERE clase_id = ? AND cliente_id = ? AND estado = 'activa'");
        $stmt->bind_param("ii", $clase_id, $cliente_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            throw new Exception('El cliente ya está inscrito en esta clase');
        }
        
        // Verificar cupo disponible
        $stmt = $conn->prepare("SELECT cupo_maximo, cupo_actual FROM clases WHERE id = ?");
        $stmt->bind_param("i", $clase_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $clase = $result->fetch_assoc();
        
        if ($clase['cupo_actual'] >= $clase['cupo_maximo']) {
            throw new Exception('No hay cupo disponible en esta clase');
        }
        
        // Iniciar transacción
        $conn->begin_transaction();
        
        // Insertar inscripción
        $stmt = $conn->prepare("INSERT INTO inscripciones_clases (clase_id, cliente_id, fecha_inscripcion, estado, asistencia) VALUES (?, ?, ?, 'activa', 0)");
        $stmt->bind_param("iis", $clase_id, $cliente_id, $fecha_inscripcion);
        $stmt->execute();
        
        // Actualizar cupo actual de la clase
        $stmt = $conn->prepare("UPDATE clases SET cupo_actual = cupo_actual + 1 WHERE id = ?");
        $stmt->bind_param("i", $clase_id);
        $stmt->execute();
        
        $conn->commit();
        
        $_SESSION['mensaje_exito'] = 'Cliente inscrito exitosamente en la clase';
        header('Location: inscripciones_clases.php');
        exit;
        
    } catch (Exception $e) {
        if (isset($conn)) $conn->rollback();
        $_SESSION['error'] = $e->getMessage();
        header('Location: inscripciones_clases.php');
        exit;
    }
}

// Cancelar inscripción
if (isset($_GET['cancelar']) && is_numeric($_GET['cancelar'])) {
    $id = $_GET['cancelar'];
    try {
        // Obtener clase_id antes de cancelar
        $stmt = $conn->prepare("SELECT clase_id FROM inscripciones_clases WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $inscripcion = $result->fetch_assoc();
        $clase_id = $inscripcion['clase_id'];
        
        $conn->begin_transaction();
        
        // Cancelar inscripción
        $stmt = $conn->prepare("UPDATE inscripciones_clases SET estado = 'cancelada' WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        // Disminuir cupo actual
        $stmt = $conn->prepare("UPDATE clases SET cupo_actual = cupo_actual - 1 WHERE id = ?");
        $stmt->bind_param("i", $clase_id);
        $stmt->execute();
        
        $conn->commit();
        
        $_SESSION['mensaje_exito'] = 'Inscripción cancelada exitosamente';
        header('Location: inscripciones_clases.php');
        exit;
        
    } catch (Exception $e) {
        if (isset($conn)) $conn->rollback();
        $_SESSION['error'] = $e->getMessage();
        header('Location: inscripciones_clases.php');
        exit;
    }
}

// Registrar asistencia
if (isset($_GET['asistencia']) && is_numeric($_GET['asistencia'])) {
    $id = $_GET['asistencia'];
    try {
        $stmt = $conn->prepare("UPDATE inscripciones_clases SET asistencia = asistencia + 1, fecha_ultima_asistencia = CURDATE() WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        $_SESSION['mensaje_exito'] = 'Asistencia registrada exitosamente';
        header('Location: inscripciones_clases.php');
        exit;
        
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: inscripciones_clases.php');
        exit;
    }
}

// Obtener listado de inscripciones
$search = isset($_GET['search']) ? $_GET['search'] : '';
$estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$query = "SELECT ic.*, c.nombre as clase_nombre, c.horario, c.instructor, 
          cl.nombre as cliente_nombre, cl.apellido as cliente_apellido, cl.telefono
          FROM inscripciones_clases ic
          INNER JOIN clases c ON ic.clase_id = c.id
          INNER JOIN clientes cl ON ic.cliente_id = cl.id
          WHERE 1=1";
$count_query = "SELECT COUNT(*) as total FROM inscripciones_clases ic
                INNER JOIN clases c ON ic.clase_id = c.id
                INNER JOIN clientes cl ON ic.cliente_id = cl.id
                WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (cl.nombre LIKE ? OR cl.apellido LIKE ? OR c.nombre LIKE ? OR cl.telefono LIKE ?)";
    $count_query .= " AND (cl.nombre LIKE ? OR cl.apellido LIKE ? OR c.nombre LIKE ? OR cl.telefono LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

if (!empty($estado)) {
    $query .= " AND ic.estado = ?";
    $count_query .= " AND ic.estado = ?";
    $params[] = $estado;
    $types .= "s";
}

$query .= " ORDER BY ic.fecha_inscripcion DESC LIMIT ? OFFSET ?";
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
$inscripciones = $result->fetch_all(MYSQLI_ASSOC);

// Obtener total de registros
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

// Obtener listado de clases activas para el formulario
$clases_activas = $conn->query("SELECT id, nombre, cupo_maximo, cupo_actual, horario, instructor FROM clases WHERE estado = 'activa' AND cupo_actual < cupo_maximo ORDER BY nombre");
$clases_list = $clases_activas->fetch_all(MYSQLI_ASSOC);

// Obtener listado de clientes activos
$clientes_activos = $conn->query("SELECT id, nombre, apellido, telefono FROM clientes WHERE estado = 'activo' ORDER BY nombre");
$clientes_list = $clientes_activos->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscripciones a Clases - Sistema Gimnasio</title>
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
        
        .btn-cancelar {
            background: #dc2626;
            color: white;
        }
        
        .btn-cancelar:hover {
            background: #b91c1c;
        }
        
        .btn-asistencia {
            background: #10b981;
            color: white;
        }
        
        .btn-asistencia:hover {
            background: #059669;
        }
        
        .badge-activa {
            background: #10b981;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .badge-cancelada {
            background: #6b7280;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .badge-completada {
            background: #3b82f6;
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
            
            .btn-accion span {
                display: none;
            }
            
            .btn-accion i {
                font-size: 14px;
                margin: 0;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="mb-4">
            <h2>Inscripciones a Clases</h2>
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
        
        <!-- Botón Nueva Inscripción -->
        <div class="mb-3">
            <button class="btn-custom-primary" data-bs-toggle="modal" data-bs-target="#modalNuevaInscripcion">
                <i class="fas fa-user-plus"></i> Nueva Inscripción
            </button>
        </div>
        
        <!-- Filtros -->
        <div class="card-custom">
            <div class="card-header-custom">
                <i class="fas fa-filter"></i> Filtros de Búsqueda
            </div>
            <div class="card-body-custom">
                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label class="form-label">Buscar</label>
                        <input type="text" class="form-control" id="searchInput" placeholder="Nombre, apellido, clase o teléfono..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Estado</label>
                        <select class="form-select" id="estadoSelect">
                            <option value="">Todos</option>
                            <option value="activa" <?php echo $estado == 'activa' ? 'selected' : ''; ?>>Activa</option>
                            <option value="cancelada" <?php echo $estado == 'cancelada' ? 'selected' : ''; ?>>Cancelada</option>
                            <option value="completada" <?php echo $estado == 'completada' ? 'selected' : ''; ?>>Completada</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabla de Inscripciones -->
        <div class="card-custom">
            <div class="card-header-custom">
                <i class="fas fa-list"></i> Listado de Inscripciones
            </div>
            <div class="card-body-custom" style="padding: 0;">
                <div style="overflow-x: auto;">
                    <table class="table-simple">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Teléfono</th>
                                <th>Clase</th>
                                <th>Instructor</th>
                                <th>Horario</th>
                                <th>Fecha Inscripción</th>
                                <th>Asistencias</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($inscripciones as $inscripcion): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($inscripcion['cliente_nombre'] . ' ' . $inscripcion['cliente_apellido']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($inscripcion['telefono']); ?></td>
                                <td><?php echo htmlspecialchars($inscripcion['clase_nombre']); ?></td>
                                <td><?php echo htmlspecialchars($inscripcion['instructor']); ?></td>
                                <td><i class="far fa-clock"></i> <?php echo htmlspecialchars($inscripcion['horario']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($inscripcion['fecha_inscripcion'])); ?></td>
                                <td>
                                    <span class="badge bg-info"><?php echo $inscripcion['asistencia']; ?> asistencias</span>
                                </td>
                                <td>
                                    <?php if($inscripcion['estado'] == 'activa'): ?>
                                        <span class="badge-activa">Activa</span>
                                    <?php elseif($inscripcion['estado'] == 'cancelada'): ?>
                                        <span class="badge-cancelada">Cancelada</span>
                                    <?php else: ?>
                                        <span class="badge-completada">Completada</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($inscripcion['estado'] == 'activa'): ?>
                                    <div style="display: flex; gap: 8px; flex-wrap: nowrap;">
                                        <button class="btn-accion btn-asistencia" onclick="registrarAsistencia(<?php echo $inscripcion['id']; ?>)" title="Registrar asistencia">
                                            <i class="fas fa-calendar-check"></i> <span>Asistencia</span>
                                        </button>
                                        <button class="btn-accion btn-cancelar" onclick="cancelarInscripcion(<?php echo $inscripcion['id']; ?>)" title="Cancelar inscripción">
                                            <i class="fas fa-times-circle"></i> <span>Cancelar</span>
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($inscripciones)): ?>
                            <tr>
                                <td colspan="9" class="text-center" style="padding: 40px;">
                                    <i class="fas fa-users-slash" style="font-size: 48px; color: #ccc;"></i>
                                    <p class="mt-2">No hay inscripciones registradas</p>
                                    <button class="btn-custom-primary" data-bs-toggle="modal" data-bs-target="#modalNuevaInscripcion">
                                        <i class="fas fa-user-plus"></i> Crear primera inscripción
                                    </button>
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
                            <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Anterior</a>
                        </li>
                        <?php for($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Siguiente</a>
                        </li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Nueva Inscripción -->
    <div class="modal fade" id="modalNuevaInscripcion" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: #1e3a8a; color: white;">
                    <h5 class="modal-title"><i class="fas fa-user-plus"></i> Nueva Inscripción a Clase</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="formInscripcion" method="POST">
                    <input type="hidden" name="action" value="inscribir_cliente">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Cliente *</label>
                            <select class="form-select" name="cliente_id" required>
                                <option value="">Seleccionar cliente</option>
                                <?php foreach($clientes_list as $cliente): ?>
                                <option value="<?php echo $cliente['id']; ?>">
                                    <?php echo htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apellido'] . ' - ' . $cliente['telefono']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Clase *</label>
                            <select class="form-select" name="clase_id" required>
                                <option value="">Seleccionar clase</option>
                                <?php foreach($clases_list as $clase): ?>
                                <option value="<?php echo $clase['id']; ?>">
                                    <?php echo htmlspecialchars($clase['nombre'] . ' - ' . $clase['horario'] . ' (' . $clase['instructor'] . ') - Cupo: ' . $clase['cupo_actual'] . '/' . $clase['cupo_maximo']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> La fecha de inscripción se registrará automáticamente como hoy
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Inscribir Cliente</button>
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
        
        // Prevenir doble envío
        $('#formInscripcion').on('submit', function() {
            const $btn = $(this).find('button[type="submit"]');
            if ($btn.data('submitted') === true) {
                return false;
            }
            $btn.data('submitted', true);
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Inscribiendo...');
            return true;
        });
        
        // Resetear formulario al cerrar modal
        $('#modalNuevaInscripcion').on('hidden.bs.modal', function() {
            const $btn = $('#formInscripcion button[type="submit"]');
            $btn.prop('disabled', false).html('Inscribir Cliente');
            $btn.removeData('submitted');
            $('#formInscripcion')[0].reset();
        });
        
        // Función para registrar asistencia
        function registrarAsistencia(id) {
            Swal.fire({
                title: '¿Registrar asistencia?',
                text: "Confirme la asistencia del cliente a la clase",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Sí, registrar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '?asistencia=' + id;
                }
            });
        }
        
        // Función para cancelar inscripción
        function cancelarInscripcion(id) {
            Swal.fire({
                title: '¿Cancelar inscripción?',
                text: "Esta acción liberará el cupo en la clase",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Sí, cancelar',
                cancelButtonText: 'No'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '?cancelar=' + id;
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