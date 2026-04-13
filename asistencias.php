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

// Obtener fecha y hora desde PHP para mostrar
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
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { position: fixed; left: 0; top: 0; width: 260px; height: 100vh; background: #1e3a8a; color: white; z-index: 1000; overflow-y: auto; }
        .main-content { margin-left: 260px; padding: 20px; min-height: 100vh; }
        
        .lector-card { background: #1e3a8a; border-radius: 20px; padding: 30px; text-align: center; color: white; margin-bottom: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .lector-icon { font-size: 80px; margin-bottom: 20px; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.1); opacity: 0.8; } 100% { transform: scale(1); opacity: 1; } }
        .huella-animation { width: 100px; height: 100px; background: rgba(255,255,255,0.15); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; transition: all 0.3s; }
        .huella-animation.active { background: rgba(255,255,255,0.3); box-shadow: 0 0 20px rgba(255,255,255,0.3); }
        .lector-status { font-size: 16px; margin-top: 15px; padding: 8px 20px; border-radius: 50px; display: inline-block; background: #152c6b; }
        
        .stats-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 20px; transition: transform 0.2s; }
        .stats-card:hover { transform: translateY(-3px); }
        .stats-number { font-size: 2rem; font-weight: bold; color: #1e3a8a; }
        .stats-label { color: #6c757d; font-size: 0.85rem; margin-top: 5px; }
        .stats-icon { font-size: 32px; color: #1e3a8a; margin-bottom: 8px; }
        
        .table-custom { background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .table-custom thead { background: #1e3a8a; color: white; }
        .table-custom th, .table-custom td { padding: 12px 15px; vertical-align: middle; }
        .table-custom tbody tr:hover { background: #f8f9fa; }
        
        .btn-manual { background: #28a745; color: white; border: none; padding: 10px 25px; border-radius: 8px; font-weight: 600; transition: all 0.2s; }
        .btn-manual:hover { background: #1e7e34; transform: translateY(-1px); }
        
        .toast-notification { position: fixed; bottom: 20px; right: 20px; z-index: 9999; min-width: 300px; }
        
        .badge-plan { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-visita { background: #f59e0b; color: white; }
        .badge-mensual { background: #10b981; color: white; }
        .badge-anual { background: #3b82f6; color: white; }
        .badge-semanal { background: #8b5cf6; color: white; }
        
        /* Estilos para el selector de clientes */
        .cliente-item {
            cursor: pointer;
            transition: background 0.2s;
        }
        .cliente-item:hover {
            background: #e8f0fe !important;
        }
        .cliente-item.disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        @media (max-width: 768px) { 
            .sidebar { transform: translateX(-100%); } 
            .main-content { margin-left: 0; } 
        }
        
        .empty-state-simple {
            text-align: center;
            padding: 40px 20px;
        }

        .empty-state-simple i {
            opacity: 0.5;
        }

        .empty-state-simple a {
            text-decoration: none;
            font-weight: 500;
        }

        .empty-state-simple a:hover {
            text-decoration: underline;
        }
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
                <div class="col-md-3 col-6">
                    <div class="stats-card">
                        <div class="stats-icon"><i class="fas fa-calendar-day"></i></div>
                        <div class="stats-number" id="totalAsistencias">0</div>
                        <div class="stats-label">Asistencias hoy</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stats-card">
                        <div class="stats-icon"><i class="fas fa-users"></i></div>
                        <div class="stats-number" id="clientesActivos">0</div>
                        <div class="stats-label">Clientes activos</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stats-card">
                        <div class="stats-icon"><i class="fas fa-clock"></i></div>
                        <div class="stats-number" id="horaActual">--:--:--</div>
                        <div class="stats-label">Hora actual</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="stats-card">
                        <div class="stats-icon"><i class="fas fa-ban"></i></div>
                        <div class="stats-number" id="asistenciasDenegadas">0</div>
                        <div class="stats-label">Accesos denegados</div>
                    </div>
                </div>
            </div>

            <div class="table-custom">
                <div style="overflow-x: auto;">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Plan</th>
                                <th>Días restantes</th>
                                <th>Entrada</th>
                                <th>Salida</th>
                                <th>Método</th>
                            </tr>
                        </thead>
                        <tbody id="tablaAsistencias">
                            <tr><td colspan="6" class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Cargando...</span>
                                </div>
                            </td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Registro Manual - Selección por pasos -->
    <div class="modal fade" id="modalRegistroManual" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background: #1e3a8a; color: white;">
                    <h5 class="modal-title"><i class="fas fa-hand-pointer"></i> Registro Manual de Asistencia</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Paso 1: Selección rápida por categorías -->
                    <div id="pasoSeleccion">
                        <label class="form-label fw-bold mb-3">Seleccione una opción:</label>
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-outline-primary btn-lg" onclick="mostrarListadoCompleto()">
                                <i class="fas fa-list"></i> Ver todos los clientes activos
                            </button>
                            <button type="button" class="btn btn-outline-success btn-lg" onclick="mostrarRecientes()">
                                <i class="fas fa-clock"></i> Clientes que asistieron hoy
                            </button>
                            <button type="button" class="btn btn-outline-warning btn-lg" onclick="mostrarProximosAVencer()">
                                <i class="fas fa-exclamation-triangle"></i> Planes por vencer (7 días)
                            </button>
                            <hr>
                            <button type="button" class="btn btn-outline-secondary btn-lg" onclick="mostrarBuscador()">
                                <i class="fas fa-search"></i> Buscar por nombre/teléfono
                            </button>
                        </div>
                    </div>

                    <!-- Paso 2: Listado de clientes -->
                    <div id="pasoListado" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="volverAlInicio()">
                                <i class="fas fa-arrow-left"></i> Volver
                            </button>
                            <span id="tituloListado" class="fw-bold"></span>
                        </div>
                        <input type="text" class="form-control mb-2" id="filtroListado" placeholder="Filtrar resultados..." autocomplete="off">
                        <div id="listaClientesContainer" style="max-height: 400px; overflow-y: auto;">
                            <div id="listaClientes" class="list-group"></div>
                        </div>
                    </div>

                    <!-- Paso 3: Confirmación de cliente -->
                    <div id="pasoConfirmacion" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="volverAlListado()">
                                <i class="fas fa-arrow-left"></i> Volver
                            </button>
                            <span class="fw-bold">Confirmar cliente</span>
                        </div>
                        
                        <input type="hidden" id="clienteSeleccionadoId">
                        
                        <div id="clienteConfirmacionInfo" class="alert alert-success">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-user-circle fa-3x me-3"></i>
                                <div>
                                    <strong id="confirmacionNombre"></strong><br>
                                    <small id="confirmacionDetalle"></small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Tipo de registro</label>
                            <select class="form-select" id="tipoRegistro">
                                <option value="entrada">Entrada</option>
                                <option value="salida">Salida</option>
                            </select>
                        </div>
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
        let currentListadoTipo = 'todos';
        let currentListadoFiltro = '';

        function actualizarHora() {
            const now = new Date();
            const horas = now.getHours().toString().padStart(2, '0');
            const minutos = now.getMinutes().toString().padStart(2, '0');
            const segundos = now.getSeconds().toString().padStart(2, '0');
            document.getElementById('horaActual').innerHTML = `${horas}:${minutos}:${segundos}`;
        }
        setInterval(actualizarHora, 1000);
        actualizarHora();

        function escapeHtml(text) {
            if (!text) return '';
            return text.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

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
                    $('#totalAsistencias').text(response.total_asistencias || 0);
                    $('#clientesActivos').text(response.clientes_activos || 0);
                    $('#asistenciasDenegadas').text(response.asistencias_denegadas || 0);
                }
            }, 'json').fail(function() {
                console.log('Error al cargar estadísticas');
            });
        }

        function actualizarTabla() {
            $.get('includes/obtener_asistencias.php', function(response) {
                const tbody = $('#tablaAsistencias');
                if (response.success && response.data && response.data.length > 0) {
                    tbody.empty();
                    response.data.forEach(a => {
                        let badgeClass = 'badge-mensual';
                        if (a.plan_nombre === 'Visita') badgeClass = 'badge-visita';
                        else if (a.plan_nombre === 'Semanal') badgeClass = 'badge-semanal';
                        else if (a.plan_nombre === 'Anual') badgeClass = 'badge-anual';
                        
                        const diasClass = a.dias_restantes <= 3 ? 'text-danger fw-bold' : (a.dias_restantes <= 7 ? 'text-warning' : '');
                        const metodoBadge = a.metodo_registro === 'huella' ? 
                            '<span class="badge bg-info"><i class="fas fa-fingerprint"></i> Huella</span>' : 
                            '<span class="badge bg-secondary"><i class="fas fa-hand-pointer"></i> Manual</span>';
                        
                        tbody.append(`
                            <tr>
                                <td>
                                    <strong>${escapeHtml(a.nombre)} ${escapeHtml(a.apellido)}</strong>
                                    <br><small class="text-muted">${escapeHtml(a.telefono || 'Sin teléfono')}</small>
                                </td>
                                <td><span class="badge-plan ${badgeClass}">${escapeHtml(a.plan_nombre || 'Sin plan')}</span></td>
                                <td class="${diasClass}">${a.dias_restantes !== null ? a.dias_restantes + ' días' : 'N/A'}</td>
                                <td>${a.hora_entrada}</td>
                                <td>${a.hora_salida || '--:--'}</td>
                                <td>${metodoBadge}</td>
                            </tr>
                        `);
                    });
                    } else {
                        tbody.html(`
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <div class="empty-state-simple">
                                        <i class="fas fa-clipboard-list fa-4x mb-3" style="color: #cbd5e1;"></i>
                                        <h5 class="text-muted mb-2">No hay asistencias registradas hoy</h5>
                                        <p class="text-muted small mb-0">
                                            <i class="fas fa-fingerprint"></i> Use el lector de huellas o 
                                            <a href="#" onclick="$('#modalRegistroManual').modal('show'); return false;" style="color: #1e3a8a;">
                                                <i class="fas fa-hand-pointer"></i> registro manual
                                            </a>
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        `);
                    }
            }, 'json').fail(function() {
                $('#tablaAsistencias').html('<tr><td colspan="6" class="text-center py-4 text-danger">Error al cargar asistencias</td></tr>');
            });
        }

        // Funciones de navegación del modal
        function cambiarPaso(paso) {
            $('#pasoSeleccion').hide();
            $('#pasoListado').hide();
            $('#pasoConfirmacion').hide();
            $(`#${paso}`).show();
        }

        function volverAlInicio() {
            $('#filtroListado').val('');
            cambiarPaso('pasoSeleccion');
        }

        function volverAlListado() {
            cambiarPaso('pasoListado');
            cargarClientes(currentListadoTipo, currentListadoFiltro);
        }

        function mostrarListadoCompleto() {
            currentListadoTipo = 'todos';
            currentListadoFiltro = '';
            $('#tituloListado').text('Todos los clientes activos');
            cargarClientes('todos', '');
            cambiarPaso('pasoListado');
        }

        function mostrarRecientes() {
            currentListadoTipo = 'recientes';
            currentListadoFiltro = '';
            $('#tituloListado').text('Clientes que asistieron hoy');
            cargarClientes('recientes', '');
            cambiarPaso('pasoListado');
        }

        function mostrarProximosAVencer() {
            currentListadoTipo = 'vencer';
            currentListadoFiltro = '';
            $('#tituloListado').text('Planes por vencer (próximos 7 días)');
            cargarClientes('vencer', '');
            cambiarPaso('pasoListado');
        }

        function mostrarBuscador() {
            currentListadoTipo = 'buscar';
            $('#tituloListado').text('Buscar cliente');
            $('#filtroListado').val('');
            cargarClientes('buscar', '');
            cambiarPaso('pasoListado');
        }

        function cargarClientes(tipo, filtro) {
            const container = $('#listaClientes');
            container.html('<div class="text-center py-4"><div class="spinner-border text-primary"></div><br>Cargando clientes...</div>');
            
            let url = 'includes/obtener_clientes_asistencia.php';
            let data = { tipo: tipo, filtro: filtro };
            
            $.post(url, data, function(response) {
                if (response.success && response.clientes && response.clientes.length > 0) {
                    container.empty();
                    response.clientes.forEach(cliente => {
                        const tienePlan = cliente.tiene_plan;
                        const estadoPlan = tienePlan ? 
                            `<span class="badge bg-success">${cliente.plan_nombre || 'Activo'} - ${cliente.dias_restantes} días</span>` : 
                            `<span class="badge bg-danger">Sin plan activo</span>`;
                        
                        const disabledClass = !tienePlan ? 'disabled' : '';
                        const onclickAttr = tienePlan ? `onclick="seleccionarClienteFinal(${cliente.id}, '${escapeHtml(cliente.nombre)} ${escapeHtml(cliente.apellido)}', '${escapeHtml(cliente.plan_nombre || 'Sin plan')}', ${cliente.dias_restantes || 0})"` : '';
                        
                        container.append(`
                            <div class="list-group-item cliente-item ${disabledClass}" ${onclickAttr}>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>${escapeHtml(cliente.nombre)} ${escapeHtml(cliente.apellido)}</strong>
                                        <br><small class="text-muted"> ${escapeHtml(cliente.telefono || 'Sin teléfono')}</small>
                                    </div>
                                    <div>${estadoPlan}</div>
                                </div>
                            </div>
                        `);
                    });
                } else {
                    container.html('<div class="text-center py-4 text-muted"><i class="fas fa-users fa-2x mb-2"></i><br>No se encontraron clientes</div>');
                }
            }, 'json').fail(function() {
                container.html('<div class="text-center py-4 text-danger">Error al cargar clientes</div>');
            });
        }

        function seleccionarClienteFinal(id, nombre, plan, dias) {
            $('#clienteSeleccionadoId').val(id);
            $('#confirmacionNombre').text(nombre);
            $('#confirmacionDetalle').html(`${plan} - ${dias} días restantes`);
            $('#btnRegistrarManual').prop('disabled', false);
            cambiarPaso('pasoConfirmacion');
        }

        // Filtro en tiempo real
        $(document).on('input', '#filtroListado', function() {
            const filtro = $(this).val().toLowerCase();
            if (currentListadoTipo === 'buscar') {
                cargarClientes('buscar', filtro);
            } else {
                $('#listaClientes .list-group-item').each(function() {
                    const texto = $(this).text().toLowerCase();
                    if (texto.includes(filtro)) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            }
        });

        // Registrar asistencia manual
        $('#btnRegistrarManual').on('click', function() {
            const clienteId = $('#clienteSeleccionadoId').val();
            const tipo = $('#tipoRegistro').val();
            
            if (!clienteId) {
                Swal.fire('Error', 'Seleccione un cliente', 'error');
                return;
            }
            
            const btn = $(this);
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Procesando...');
            
            $.post('includes/registrar_asistencia_manual.php', { cliente_id: clienteId, tipo: tipo }, function(response) {
                if (response.success) {
                    Swal.fire('Éxito', response.message, 'success');
                    $('#modalRegistroManual').modal('hide');
                    actualizarTabla();
                    actualizarEstadisticas();
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
                btn.prop('disabled', false).html('Registrar');
            }, 'json').fail(function() {
                btn.prop('disabled', false).html('Registrar');
                Swal.fire('Error', 'Error al registrar asistencia', 'error');
            });
        });

        // Limpiar al cerrar modal
        $('#modalRegistroManual').on('hidden.bs.modal', function() {
            $('#clienteSeleccionadoId').val('');
            $('#confirmacionNombre').text('');
            $('#confirmacionDetalle').html('');
            $('#btnRegistrarManual').prop('disabled', true);
            $('#filtroListado').val('');
            volverAlInicio();
        });

        // Polling para huellas
        function iniciarPolling() {
            pollingInterval = setInterval(function() {
                $.post('includes/verificar_huellas_pendientes.php', { last_check: ultimoProcesado }, function(response) {
                    if (response.success && response.huellas && response.huellas.length > 0) {
                        response.huellas.forEach(huella => {
                            $.post('includes/procesar_huella.php', { huella: huella }, function(res) {
                                if (res.success) {
                                    animarLector();
                                    const mensaje = res.tipo === 'entrada' ? `Entrada: ${res.hora_entrada}` : `Salida: ${res.hora_salida}`;
                                    mostrarNotificacion(`${res.tipo === 'entrada' ? 'Entrada' : 'Salida'}`, `${res.cliente_nombre} - ${mensaje}`, 'success');
                                    actualizarTabla();
                                    actualizarEstadisticas();
                                } else {
                                    mostrarNotificacion('Acceso Denegado', res.message, 'danger');
                                    actualizarEstadisticas();
                                }
                            }, 'json');
                        });
                        ultimoProcesado = response.current_time;
                    }
                }, 'json');
            }, 3000);
        }

        // Inicializar
        actualizarTabla();
        actualizarEstadisticas();
        iniciarPolling();
        setInterval(actualizarEstadisticas, 30000);
        setInterval(actualizarTabla, 15000);
    </script>
</body>
</html>