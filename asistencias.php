<?php
date_default_timezone_set('America/Mexico_City');
session_start();
require_once 'config/database.php';

// Verificar sesión
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$database = new Database();
$conn = $database->getConnection();

$fecha_hoy = date('Y-m-d');
$hora_actual = date('H:i:s');
$usuario_id = $_SESSION['user_id'];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Asistencias - Sistema Gimnasio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { position: fixed; left: 0; top: 0; width: 260px; height: 100vh; background: #1e3a8a; color: white; z-index: 1000; overflow-y: auto; }
        .main-content { margin-left: 260px; padding: 20px; min-height: 100vh; }
        
        .lector-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 20px; padding: 30px; text-align: center; color: white; margin-bottom: 30px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); }
        .lector-icon { font-size: 80px; margin-bottom: 20px; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.1); opacity: 0.8; } 100% { transform: scale(1); opacity: 1; } }
        .huella-animation { width: 100px; height: 100px; background: rgba(255,255,255,0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; transition: all 0.3s; }
        .huella-animation.active { background: rgba(255,255,255,0.3); box-shadow: 0 0 30px rgba(255,255,255,0.5); }
        .lector-status { font-size: 18px; margin-top: 15px; padding: 8px 20px; border-radius: 50px; display: inline-block; background: rgba(255,255,255,0.2); }
        
        .stats-card { background: white; border-radius: 15px; padding: 20px; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-bottom: 20px; transition: transform 0.2s; }
        .stats-card:hover { transform: translateY(-5px); }
        .stats-number { font-size: 2.5rem; font-weight: bold; color: #1e3a8a; }
        .stats-label { color: #6c757d; font-size: 0.9rem; margin-top: 5px; }
        .stats-icon { font-size: 40px; color: #1e3a8a; margin-bottom: 10px; }
        
        .table-custom { background: white; border-radius: 15px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .table-custom thead { background: #1e3a8a; color: white; }
        .table-custom th, .table-custom td { padding: 12px 15px; vertical-align: middle; }
        .table-custom tbody tr:hover { background: #f8f9fa; }
        
        .btn-manual { background: #28a745; color: white; border: none; padding: 12px 30px; border-radius: 50px; font-weight: 600; transition: all 0.2s; }
        .btn-manual:hover { background: #218838; transform: translateY(-2px); }
        
        .toast-notification { position: fixed; bottom: 20px; right: 20px; z-index: 9999; min-width: 300px; }
        
        .search-client { position: relative; }
        .search-results { position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #ddd; border-radius: 8px; max-height: 300px; overflow-y: auto; z-index: 1000; display: none; }
        .search-result-item { padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #eee; }
        .search-result-item:hover { background: #f0f0f0; }
        
        .badge-plan { padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-visita { background: #f59e0b; color: white; }
        .badge-mensual { background: #10b981; color: white; }
        .badge-anual { background: #3b82f6; color: white; }
        
        @media (max-width: 768px) { .sidebar { transform: translateX(-100%); } .main-content { margin-left: 0; } }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-fingerprint"></i> Registro de Asistencias</h2>
                <button class="btn-manual" data-bs-toggle="modal" data-bs-target="#modalRegistroManual">
                    <i class="fas fa-hand-pointer"></i> Registro Manual
                </button>
            </div>

            <div class="lector-card">
                <div class="huella-animation" id="huellaAnimation">
                    <i class="fas fa-fingerprint lector-icon"></i>
                </div>
                <h3>Lector de Huellas Digitales</h3>
                <p>Coloque su dedo en el lector para registrar entrada o salida</p>
                <div class="lector-status" id="lectorStatus">
                    <i class="fas fa-circle" style="font-size: 10px; color: #28a745;"></i>
                    Lector activo - Esperando huella...
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="stats-icon"><i class="fas fa-calendar-day"></i></div>
                        <div class="stats-number" id="totalAsistencias">0</div>
                        <div class="stats-label">Asistencias hoy</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="stats-icon"><i class="fas fa-users"></i></div>
                        <div class="stats-number" id="clientesActivos">0</div>
                        <div class="stats-label">Clientes activos hoy</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="stats-icon"><i class="fas fa-clock"></i></div>
                        <div class="stats-number" id="horaActual">--:--:--</div>
                        <div class="stats-label">Hora actual</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card">
                        <div class="stats-icon"><i class="fas fa-ban"></i></div>
                        <div class="stats-number" id="asistenciasDenegadas">0</div>
                        <div class="stats-label">Accesos denegados</div>
                    </div>
                </div>
            </div>

            <div class="table-custom">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Plan</th>
                            <th>Días restantes</th>
                            <th>Hora Entrada</th>
                            <th>Hora Salida</th>
                            <th>Método</th>
                        </tr>
                    </thead>
                    <tbody id="tablaAsistencias">
                        <tr><td colspan="6" class="text-center py-4">Cargando...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Registro Manual -->
    <div class="modal fade" id="modalRegistroManual" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: #1e3a8a; color: white;">
                    <h5 class="modal-title"><i class="fas fa-hand-pointer"></i> Registro Manual de Asistencia</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="search-client mb-3">
                        <label class="form-label">Buscar cliente</label>
                        <input type="text" class="form-control" id="buscarCliente" placeholder="Nombre, apellido o teléfono...">
                        <div class="search-results" id="searchResults"></div>
                    </div>
                    <input type="hidden" id="clienteSeleccionadoId">
                    <div id="clienteInfo" class="alert alert-info" style="display: none;">
                        <i class="fas fa-user-check"></i> Cliente: <strong id="clienteNombre"></strong><br>
                        <span id="clientePlan"></span>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tipo de registro</label>
                        <select class="form-select" id="tipoRegistro">
                            <option value="entrada">Entrada</option>
                            <option value="salida">Salida</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="btnRegistrarManual" disabled>Registrar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="toast-notification" id="toastNotification"></div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        let pollingInterval;
        let ultimoProcesado = <?php echo time(); ?>;

        function actualizarHora() {
            document.getElementById('horaActual').innerHTML = new Date().toLocaleTimeString('es-MX');
        }
        setInterval(actualizarHora, 1000);
        actualizarHora();

        function mostrarNotificacion(titulo, mensaje, tipo = 'success') {
            const toast = $(`<div class="toast align-items-center text-white bg-${tipo} border-0 show" role="alert"><div class="d-flex"><div class="toast-body"><strong>${titulo}</strong><br>${mensaje}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`);
            $('#toastNotification').html(toast);
            setTimeout(() => toast.remove(), 4000);
        }

        function animarLector() {
            $('#huellaAnimation').addClass('active');
            setTimeout(() => $('#huellaAnimation').removeClass('active'), 500);
        }

        function actualizarEstadisticas() {
            $.get('includes/obtener_estadisticas_asistencias.php', function(response) {
                if (response.success) {
                    $('#totalAsistencias').text(response.total_asistencias);
                    $('#clientesActivos').text(response.clientes_activos);
                    $('#asistenciasDenegadas').text(response.asistencias_denegadas || 0);
                }
            }, 'json');
        }

        function actualizarTabla() {
            $.get('includes/obtener_asistencias.php', function(response) {
                if (response.success && response.data.length > 0) {
                    const tbody = $('#tablaAsistencias');
                    tbody.empty();
                    response.data.forEach(a => {
                        const badgePlan = a.plan_nombre === 'Visita' ? 'badge-visita' : (a.plan_nombre.includes('Anual') ? 'badge-anual' : 'badge-mensual');
                        const diasClass = a.dias_restantes <= 3 ? 'text-danger fw-bold' : (a.dias_restantes <= 7 ? 'text-warning' : '');
                        tbody.append(`<tr>
                            <td><strong>${a.nombre} ${a.apellido}</strong><br><small class="text-muted">${a.telefono || 'Sin teléfono'}</small></td>
                            <td><span class="badge-plan ${badgePlan}">${a.plan_nombre || 'Sin plan'}</span></td>
                            <td class="${diasClass}">${a.dias_restantes !== null ? a.dias_restantes + ' días' : 'N/A'}</td>
                            <td>${a.hora_entrada}</td>
                            <td>${a.hora_salida || '--:--'}</td>
                            <td>${a.metodo_registro === 'huella' ? '<span class="badge bg-info"><i class="fas fa-fingerprint"></i> Huella</span>' : '<span class="badge bg-secondary"><i class="fas fa-hand-pointer"></i> Manual</span>'}</td>
                        </tr>`);
                    });
                } else {
                    $('#tablaAsistencias').html('<tr><td colspan="6" class="text-center py-4">No hay asistencias registradas hoy</td></tr>');
                }
            }, 'json');
        }

        function iniciarPolling() {
            pollingInterval = setInterval(function() {
                $.post('includes/verificar_huellas_pendientes.php', { last_check: ultimoProcesado }, function(response) {
                    if (response.success && response.huellas && response.huellas.length > 0) {
                        response.huellas.forEach(huella => {
                            $.post('includes/procesar_huella.php', { huella: huella }, function(res) {
                                if (res.success) {
                                    animarLector();
                                    const mensaje = res.tipo === 'entrada' ? `Entrada: ${res.hora_entrada}` : `Salida: ${res.hora_salida}`;
                                    mostrarNotificacion(`✅ ${res.tipo === 'entrada' ? 'Entrada' : 'Salida'} Registrada`, `${res.cliente_nombre} - ${mensaje}`, 'success');
                                    actualizarTabla();
                                    actualizarEstadisticas();
                                } else {
                                    mostrarNotificacion('❌ Acceso Denegado', res.message, 'danger');
                                    actualizarEstadisticas();
                                }
                            }, 'json');
                        });
                        ultimoProcesado = response.current_time;
                    }
                }, 'json');
            }, 2000);
        }

        // Búsqueda de clientes
        let timeoutBusqueda;
        $('#buscarCliente').on('input', function() {
            clearTimeout(timeoutBusqueda);
            const termino = $(this).val();
            if (termino.length < 2) { $('#searchResults').hide(); return; }
            timeoutBusqueda = setTimeout(function() {
                $.post('includes/buscar_clientes_asistencia.php', { termino: termino }, function(response) {
                    if (response.success && response.clientes.length > 0) {
                        const results = $('#searchResults').empty().show();
                        response.clientes.forEach(c => {
                            const planInfo = c.plan_nombre ? `${c.plan_nombre} - ${c.dias_restantes} días restantes` : 'Sin plan activo';
                            results.append(`<div class="search-result-item" onclick="seleccionarCliente(${c.id}, '${c.nombre} ${c.apellido}', '${planInfo}', ${c.tiene_plan})"><strong>${c.nombre} ${c.apellido}</strong><br><small>${c.telefono || 'Sin teléfono'} | ${planInfo}</small></div>`);
                        });
                    } else { $('#searchResults').hide(); }
                }, 'json');
            }, 300);
        });

        $(document).on('click', function(e) {
            if (!$(e.target).closest('.search-client').length) $('#searchResults').hide();
        });

        window.seleccionarCliente = function(id, nombre, planInfo, tienePlan) {
            $('#clienteSeleccionadoId').val(id);
            $('#clienteNombre').text(nombre);
            $('#clientePlan').html(planInfo);
            $('#clienteInfo').show();
            $('#searchResults').hide();
            $('#buscarCliente').val('');
            
            if (!tienePlan) {
                $('#btnRegistrarManual').prop('disabled', true);
                Swal.fire('Aviso', 'Este cliente no tiene un plan activo', 'warning');
            } else {
                $('#btnRegistrarManual').prop('disabled', false);
            }
        };

        $('#btnRegistrarManual').on('click', function() {
            const clienteId = $('#clienteSeleccionadoId').val();
            const tipo = $('#tipoRegistro').val();
            if (!clienteId) { Swal.fire('Error', 'Seleccione un cliente', 'error'); return; }
            
            $.post('includes/registrar_asistencia_manual.php', { cliente_id: clienteId, tipo: tipo }, function(response) {
                if (response.success) {
                    Swal.fire('Éxito', response.message, 'success');
                    $('#modalRegistroManual').modal('hide');
                    $('#clienteSeleccionadoId').val('');
                    $('#clienteInfo').hide();
                    $('#btnRegistrarManual').prop('disabled', true);
                    actualizarTabla();
                    actualizarEstadisticas();
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            }, 'json');
        });

        $('#modalRegistroManual').on('hidden.bs.modal', function() {
            $('#clienteSeleccionadoId').val('');
            $('#clienteInfo').hide();
            $('#buscarCliente').val('');
            $('#btnRegistrarManual').prop('disabled', true);
        });

        actualizarTabla();
        actualizarEstadisticas();
        iniciarPolling();
        setInterval(actualizarEstadisticas, 30000);
        setInterval(actualizarTabla, 10000);
    </script>
</body>
</html>