<?php
// includes/inscripcion_detalle.php
session_start();
require_once '../config/database.php';

// Crear instancia de la base de datos y obtener la conexión
$database = new Database();
$conn = $database->getConnection();

if (!isset($_SESSION['user_id']) || !isset($_POST['id'])) {
    exit;
}

$id = $_POST['id'];

try {
    // Obtener datos de la inscripción usando MySQLi
    $stmt = $conn->prepare("
        SELECT i.*, 
               c.nombre as cliente_nombre, 
               c.apellido as cliente_apellido,
               c.telefono as cliente_telefono,
               c.email as cliente_email,
               c.huella_digital,
               p.nombre as plan_nombre,
               p.duracion_dias,
               p.precio as plan_precio
        FROM inscripciones i
        INNER JOIN clientes c ON i.cliente_id = c.id
        INNER JOIN planes p ON i.plan_id = p.id
        WHERE i.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $inscripcion = $result->fetch_assoc();
    
    if ($inscripcion) {
        // Obtener pagos asociados
        $stmt_pagos = $conn->prepare("
            SELECT * FROM pagos 
            WHERE inscripcion_id = ? 
            ORDER BY fecha_pago DESC
        ");
        $stmt_pagos->bind_param("i", $id);
        $stmt_pagos->execute();
        $result_pagos = $stmt_pagos->get_result();
        $pagos = $result_pagos->fetch_all(MYSQLI_ASSOC);
        
        ?>
        <div class="detalle-inscripcion">
            <div class="mb-3">
                <h6 class="text-primary">Información del Cliente</h6>
                <hr>
                <p><strong><i class="fas fa-user"></i> Nombre:</strong> <?php echo htmlspecialchars($inscripcion['cliente_nombre'] . ' ' . $inscripcion['cliente_apellido']); ?></p>
                <p><strong><i class="fas fa-phone"></i> Teléfono:</strong> <?php echo htmlspecialchars($inscripcion['cliente_telefono']); ?></p>
                <p><strong><i class="fas fa-envelope"></i> Email:</strong> <?php echo htmlspecialchars($inscripcion['cliente_email']); ?></p>
                <p><strong><i class="fas fa-fingerprint"></i> Huella Digital:</strong> 
                    <?php echo $inscripcion['huella_digital'] ? '<span class="text-success"><i class="fas fa-check-circle"></i> Registrada</span>' : '<span class="text-danger"><i class="fas fa-times-circle"></i> No registrada</span>'; ?>
                </p>
            </div>
            
            <div class="mb-3">
                <h6 class="text-primary">Información del Plan</h6>
                <hr>
                <p><strong><i class="fas fa-dumbbell"></i> Plan:</strong> <?php echo htmlspecialchars($inscripcion['plan_nombre']); ?></p>
                <p><strong><i class="fas fa-calendar-alt"></i> Duración:</strong> 
                    <?php 
                    if($inscripcion['duracion_dias'] == 0) echo 'Paquete de Visitas';
                    elseif($inscripcion['duracion_dias'] == 1) echo '1 Día';
                    elseif($inscripcion['duracion_dias'] == 7) echo '1 Semana';
                    elseif($inscripcion['duracion_dias'] == 15) echo '15 Días';
                    elseif($inscripcion['duracion_dias'] == 30) echo '1 Mes';
                    else echo $inscripcion['duracion_dias'] . ' días';
                    ?>
                </p>
                <p><strong><i class="fas fa-tag"></i> Precio pagado:</strong> <span class="text-success fw-bold">$<?php echo number_format($inscripcion['precio_pagado'], 2); ?></span></p>
            </div>
            
            <div class="mb-3">
                <h6 class="text-primary">Período de Inscripción</h6>
                <hr>
                <p><strong><i class="fas fa-play"></i> Fecha inicio:</strong> <?php echo date('d/m/Y', strtotime($inscripcion['fecha_inicio'])); ?></p>
                <p><strong><i class="fas fa-stop"></i> Fecha fin:</strong> <?php echo $inscripcion['duracion_dias'] > 0 ? date('d/m/Y', strtotime($inscripcion['fecha_fin'])) : 'N/A (Paquete de visitas)'; ?></p>
                <p><strong><i class="fas fa-info-circle"></i> Estado:</strong> 
                    <?php
                    if($inscripcion['estado'] == 'activa') {
                        echo '<span style="background: #10b981; color: white; padding: 5px 10px; border-radius: 5px;">Activa</span>';
                    } elseif($inscripcion['estado'] == 'vencida') {
                        echo '<span style="background: #ef4444; color: white; padding: 5px 10px; border-radius: 5px;">Vencida</span>';
                    } else {
                        echo '<span style="background: #6b7280; color: white; padding: 5px 10px; border-radius: 5px;">Cancelada</span>';
                    }
                    ?>
                </p>
            </div>
            
            <?php if(count($pagos) > 0): ?>
            <div class="mb-3">
                <h6 class="text-primary">Historial de Pagos</h6>
                <hr>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Fecha</th>
                                <th>Monto</th>
                                <th>Método</th>
                                <th>Referencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($pagos as $pago): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($pago['fecha_pago'])); ?></td>
                                <td class="text-success fw-bold">$<?php echo number_format($pago['monto'], 2); ?></td>
                                <td>
                                    <?php 
                                    $icono = '';
                                    if($pago['metodo_pago'] == 'efectivo') $icono = '<i class="fas fa-money-bill"></i>';
                                    if($pago['metodo_pago'] == 'tarjeta') $icono = '<i class="fas fa-credit-card"></i>';
                                    if($pago['metodo_pago'] == 'transferencia') $icono = '<i class="fas fa-exchange-alt"></i>';
                                    echo $icono . ' ' . ucfirst($pago['metodo_pago']);
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($pago['referencia']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    } else {
        echo '<div class="alert alert-danger">Inscripción no encontrada</div>';
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al cargar los detalles: ' . $e->getMessage() . '</div>';
}
?>