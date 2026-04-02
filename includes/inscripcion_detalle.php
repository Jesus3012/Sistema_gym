<?php
// includes/inscripcion_detalle.php
session_start();
require_once '../config/database.php';

if (!isset($_POST['id'])) {
    echo '<div class="alert alert-danger">ID no proporcionado</div>';
    exit;
}

$id = $_POST['id'];

$database = new Database();
$conn = $database->getConnection();

// Obtener datos de la inscripción
$stmt = $conn->prepare("
    SELECT i.*, c.nombre as cliente_nombre, c.apellido as cliente_apellido, c.telefono, c.email,
           p.nombre as plan_nombre, p.duracion_dias
    FROM inscripciones i
    INNER JOIN clientes c ON i.cliente_id = c.id
    INNER JOIN planes p ON i.plan_id = p.id
    WHERE i.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$inscripcion = $stmt->get_result()->fetch_assoc();

if (!$inscripcion) {
    echo '<div class="alert alert-danger">Inscripción no encontrada</div>';
    exit;
}

// Determinar clase de estado
$estado_class = '';
$estado_texto = '';
if($inscripcion['estado'] == 'activa') {
    $estado_class = 'bg-success';
    $estado_texto = 'Activa';
} elseif($inscripcion['estado'] == 'vencida') {
    $estado_class = 'bg-danger';
    $estado_texto = 'Vencida';
} else {
    $estado_class = 'bg-secondary';
    $estado_texto = 'Cancelada';
}
?>

<style>
    .detalle-wrapper {
        padding: 10px;
    }
    
    .card-info-modern {
        background: white;
        border-radius: 15px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        border-left: 4px solid;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    
    .card-info-modern:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }
    
    .card-info-modern.cliente {
        border-left-color: #3b82f6;
    }
    
    .card-info-modern.inscripcion {
        border-left-color: #10b981;
    }
    
    .card-title-modern {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 15px;
        color: #333;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .card-title-modern i {
        font-size: 20px;
    }
    
    .card-title-modern.cliente i {
        color: #3b82f6;
    }
    
    .card-title-modern.inscripcion i {
        color: #10b981;
    }
    
    .info-row {
        display: flex;
        margin-bottom: 10px;
        font-size: 14px;
    }
    
    .info-label {
        width: 100px;
        font-weight: 600;
        color: #666;
    }
    
    .info-value {
        flex: 1;
        color: #333;
    }
    
    .historial-modern {
        background: white;
        border-radius: 15px;
        padding: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    
    .historial-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .historial-title {
        font-size: 18px;
        font-weight: 600;
        color: #333;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .historial-title i {
        color: #3b82f6;
        font-size: 20px;
    }
    
    .search-box-modern {
        position: relative;
        width: 300px;
    }
    
    .search-box-modern input {
        width: 100%;
        padding: 10px 15px 10px 40px;
        border: 1px solid #e0e0e0;
        border-radius: 25px;
        font-size: 14px;
        transition: all 0.3s;
    }
    
    .search-box-modern input:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
    }
    
    .search-box-modern i {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #999;
    }
    
    .table-modern {
        width: 100%;
        border-collapse: collapse;
    }
    
    .table-modern thead th {
        padding: 12px 15px;
        background: #f8f9fa;
        font-weight: 600;
        font-size: 13px;
        color: #555;
        border-bottom: 2px solid #e0e0e0;
        cursor: pointer;
        transition: background 0.2s;
    }
    
    .table-modern thead th:hover {
        background: #e9ecef;
    }
    
    .table-modern thead th i {
        margin-left: 5px;
        font-size: 11px;
        opacity: 0.6;
    }
    
    .table-modern tbody td {
        padding: 12px 15px;
        font-size: 14px;
        color: #333;
        border-bottom: 1px solid #f0f0f0;
    }
    
    .table-modern tbody tr:hover {
        background: #f8f9fa;
    }
    
    .badge-metodo {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }
    
    .badge-efectivo {
        background: #dbeafe;
        color: #1e40af;
    }
    
    .badge-tarjeta {
        background: #fed7aa;
        color: #9a3412;
    }
    
    .badge-transferencia {
        background: #d1fae5;
        color: #065f46;
    }
    
    .total-modern {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        border-radius: 12px;
        padding: 15px 20px;
        margin-top: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        color: white;
    }
    
    .total-modern span:first-child {
        font-size: 14px;
        opacity: 0.9;
    }
    
    .total-modern span:last-child {
        font-size: 24px;
        font-weight: 700;
    }
    
    .pagination-modern {
        display: flex;
        justify-content: center;
        gap: 5px;
        margin-top: 20px;
        flex-wrap: wrap;
    }
    
    .page-link-modern {
        padding: 8px 12px;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        color: #3b82f6;
        cursor: pointer;
        transition: all 0.2s;
        background: white;
        font-size: 14px;
    }
    
    .page-link-modern:hover {
        background: #3b82f6;
        color: white;
        border-color: #3b82f6;
    }
    
    .page-item-modern.active .page-link-modern {
        background: #3b82f6;
        color: white;
        border-color: #3b82f6;
    }
    
    .page-item-modern.disabled .page-link-modern {
        opacity: 0.5;
        cursor: not-allowed;
    }
    
    .badge-estado {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }
    
    @media (max-width: 768px) {
        .historial-header {
            flex-direction: column;
            align-items: stretch;
        }
        
        .search-box-modern {
            width: 100%;
        }
        
        .table-modern thead th,
        .table-modern tbody td {
            padding: 8px 10px;
            font-size: 12px;
        }
        
        .info-row {
            flex-direction: column;
        }
        
        .info-label {
            width: auto;
            margin-bottom: 5px;
        }
    }

    .badge-estado {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
        color: white;  /* Esto asegura que el texto sea blanco */
    }
</style>

<div class="detalle-wrapper">
    <div class="row">
        <div class="col-md-6">
            <div class="card-info-modern cliente">
                <div class="card-title-modern cliente">
                    <i class="fas fa-user-circle"></i>
                    Información del Cliente
                </div>
                <div class="info-row">
                    <div class="info-label">Nombre:</div>
                    <div class="info-value"><strong><?php echo htmlspecialchars($inscripcion['cliente_nombre'] . ' ' . $inscripcion['cliente_apellido']); ?></strong></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Teléfono:</div>
                    <div class="info-value"><?php echo htmlspecialchars($inscripcion['telefono']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Email:</div>
                    <div class="info-value"><?php echo htmlspecialchars($inscripcion['email'] ?: 'No registrado'); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card-info-modern inscripcion">
                <div class="card-title-modern inscripcion">
                    <i class="fas fa-dumbbell"></i>
                    Información de la Inscripción
                </div>
                <div class="info-row">
                    <div class="info-label">Plan:</div>
                    <div class="info-value"><strong><?php echo htmlspecialchars($inscripcion['plan_nombre']); ?></strong></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Fecha Inicio:</div>
                    <div class="info-value"><?php echo date('d/m/Y', strtotime($inscripcion['fecha_inicio'])); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Fecha Fin:</div>
                    <div class="info-value"><?php echo $inscripcion['fecha_fin'] ? date('d/m/Y', strtotime($inscripcion['fecha_fin'])) : 'Sin vencimiento'; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Estado:</div>
                    <div class="info-value">
                        <span class="badge-estado <?php echo $estado_class; ?>"><?php echo $estado_texto; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="historial-modern">
        <div class="historial-header">
            <div class="historial-title">
                Historial de Pagos
            </div>
            <div class="search-box-modern">
                <i class="fas fa-search"></i>
                <input type="text" id="searchHistorialInput" placeholder="Buscar pagos...">
            </div>
        </div>
        
        <div style="overflow-x: auto;">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th onclick="window.ordenarHistorial('fecha_pago')">Fecha <i class="fas fa-sort"></i></th>
                        <th onclick="window.ordenarHistorial('monto')">Monto <i class="fas fa-sort"></i></th>
                        <th onclick="window.ordenarHistorial('metodo_pago')">Método <i class="fas fa-sort"></i></th>
                        <th onclick="window.ordenarHistorial('referencia')">Referencia <i class="fas fa-sort"></i></th>
                        <th onclick="window.ordenarHistorial('periodo_inicio')">Período <i class="fas fa-sort"></i></th>
                        <th onclick="window.ordenarHistorial('plan_nombre')">Plan <i class="fas fa-sort"></i></th>
                    </tr>
                </thead>
                <tbody id="tablaHistorialBody">
                    <tr><td colspan="6" class="text-center"><div class="spinner-border text-primary"></div><p>Cargando...</p></td></tr>
                </tbody>
            </table>
        </div>
        
        <div class="total-modern">
            <span><i class="fas fa-chart-line"></i> Total acumulado</span>
            <span id="totalPagadoSpan">$0.00</span>
        </div>
        
        <div id="paginacionHistorial" class="pagination-modern"></div>
    </div>
</div>