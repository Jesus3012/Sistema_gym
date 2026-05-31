<?php
date_default_timezone_set('America/Mexico_City');
session_start();
require_once 'config/database.php';

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Registro de Asistencias - Sistema Gimnasio</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f0f2f5;
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

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .page-header h2 {
            color: #0f172a;
            font-weight: 800;
            margin: 0;
            font-size: 26px;
        }

        .btn-manual {
            background: #16a34a;
            color: white;
            border: none;
            padding: 11px 22px;
            border-radius: 14px;
            font-weight: 700;
            transition: all 0.2s ease;
            cursor: pointer;
            box-shadow: 0 8px 18px rgba(22, 163, 74, 0.22);
        }

        .btn-manual:hover {
            background: #15803d;
            transform: translateY(-1px);
            color: white;
        }

        .qr-module {
            background: linear-gradient(135deg, #0f2f75 0%, #1e3a8a 48%, #2563eb 100%);
            border-radius: 26px;
            padding: 28px;
            color: white;
            margin-bottom: 30px;
            box-shadow: 0 18px 45px rgba(30, 58, 138, 0.28);
            position: relative;
            overflow: hidden;
        }

        .qr-module::before {
            content: "";
            position: absolute;
            width: 230px;
            height: 230px;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
            top: -95px;
            right: -85px;
        }

        .qr-module::after {
            content: "";
            position: absolute;
            width: 160px;
            height: 160px;
            border-radius: 50%;
            background: rgba(255,255,255,0.06);
            bottom: -80px;
            left: -70px;
        }

        .qr-header {
            display: flex;
            align-items: center;
            gap: 18px;
            margin-bottom: 22px;
            position: relative;
            z-index: 2;
        }

        .qr-header h3 {
            margin: 0;
            font-size: 28px;
            font-weight: 800;
        }

        .qr-header p {
            margin: 5px 0 0;
            color: rgba(255,255,255,0.82);
            font-size: 15px;
        }

        .qr-icon-wrap {
            width: 74px;
            height: 74px;
            min-width: 74px;
            border-radius: 24px;
            background: rgba(255,255,255,0.16);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 34px;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.18);
            transition: all 0.3s ease;
        }

        .qr-icon-wrap.active {
            transform: scale(1.07);
            background: rgba(34,197,94,0.28);
            box-shadow: 0 0 30px rgba(34,197,94,0.45);
        }

        .qr-reader-shell {
            width: 100%;
            max-width: 470px;
            min-height: 335px;
            margin: 0 auto;
            border-radius: 26px;
            background: #020617;
            padding: 12px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 15px 38px rgba(0,0,0,0.35);
            z-index: 2;
        }

        #reader-container {
            width: 100%;
            min-height: 310px;
            border-radius: 20px;
            overflow: hidden;
            background: #000;
        }

        #reader-container video {
            width: 100% !important;
            height: auto !important;
            min-height: 310px;
            object-fit: cover;
            border-radius: 20px;
        }

        #reader-container canvas {
            border-radius: 20px;
        }

        .qr-reader-placeholder {
            position: absolute;
            inset: 12px;
            border-radius: 20px;
            background: radial-gradient(circle at top, #1e293b, #020617 70%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: rgba(255,255,255,0.78);
            text-align: center;
            gap: 12px;
            pointer-events: none;
            transition: opacity 0.2s ease;
        }

        .qr-reader-placeholder.hidden {
            opacity: 0;
            visibility: hidden;
        }

        .qr-reader-placeholder i {
            font-size: 50px;
            color: rgba(255,255,255,0.92);
        }

        .qr-reader-placeholder span {
            font-size: 14px;
            max-width: 240px;
            line-height: 1.4;
        }

        .qr-status-card {
            width: fit-content;
            max-width: 100%;
            margin: 18px auto 0;
            padding: 10px 18px;
            border-radius: 999px;
            background: rgba(255,255,255,0.14);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: rgba(255,255,255,0.92);
            position: relative;
            z-index: 2;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            background: #f59e0b;
            border-radius: 50%;
            box-shadow: 0 0 0 4px rgba(245,158,11,0.18);
        }

        .qr-status-card.active .status-dot {
            background: #22c55e;
            box-shadow: 0 0 0 4px rgba(34,197,94,0.20);
        }

        .qr-status-card.error .status-dot {
            background: #ef4444;
            box-shadow: 0 0 0 4px rgba(239,68,68,0.20);
        }

        .qr-controls {
            display: flex;
            justify-content: center;
            gap: 14px;
            margin-top: 20px;
            flex-wrap: wrap;
            position: relative;
            z-index: 2;
        }

        .btn-qr {
            border: none;
            color: white;
            padding: 12px 22px;
            border-radius: 16px;
            transition: all 0.2s ease;
            cursor: pointer;
            font-weight: 700;
            min-width: 165px;
        }

        .btn-start {
            background: #22c55e;
            box-shadow: 0 10px 22px rgba(34,197,94,0.25);
        }

        .btn-start:hover {
            background: #16a34a;
            transform: translateY(-2px);
        }

        .btn-stop {
            background: rgba(239,68,68,0.95);
            box-shadow: 0 10px 22px rgba(239,68,68,0.25);
        }

        .btn-stop:hover {
            background: #dc2626;
            transform: translateY(-2px);
        }

        .btn-qr:disabled {
            opacity: 0.48;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .qr-last-code {
            margin: 16px auto 0;
            width: fit-content;
            max-width: 100%;
            padding: 10px 16px;
            border-radius: 14px;
            background: rgba(15,23,42,0.35);
            text-align: center;
            position: relative;
            z-index: 2;
        }

        .qr-last-code small {
            display: block;
            color: rgba(255,255,255,0.72);
            font-size: 12px;
        }

        .qr-last-code strong {
            display: block;
            font-size: 15px;
            margin-top: 2px;
            word-break: break-all;
        }

        .stats-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
            margin-bottom: 20px;
            transition: transform 0.2s;
            min-height: 135px;
        }

        .stats-card:hover {
            transform: translateY(-3px);
        }

        .stats-number {
            font-size: 2rem;
            font-weight: bold;
            color: #1e3a8a;
        }

        .stats-label {
            color: #6c757d;
            font-size: 0.85rem;
            margin-top: 5px;
        }

        .stats-icon {
            font-size: 32px;
            color: #1e3a8a;
            margin-bottom: 8px;
        }

        .table-custom {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
        }

        .table-custom thead {
            background: #1e3a8a;
            color: white;
        }

        .table-custom th,
        .table-custom td {
            padding: 12px 15px;
            vertical-align: middle;
        }

        .table-custom tbody tr:hover {
            background: #f8f9fa;
        }

        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            max-width: calc(100vw - 30px);
        }

        .badge-plan {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-visita { background: #f59e0b; color: white; }
        .badge-mensual { background: #10b981; color: white; }
        .badge-anual { background: #3b82f6; color: white; }
        .badge-semanal { background: #8b5cf6; color: white; }

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

        .empty-state-simple {
            text-align: center;
            padding: 40px 20px;
        }

        .empty-state-simple i {
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            body {
                background: #eef2f7;
            }

            .sidebar {
                transform: translateX(-100%);
            }

            .main-content {
                margin-left: 0;
                padding: 14px;
            }

            .page-header {
                align-items: stretch;
                margin-bottom: 16px;
            }

            .page-header h2 {
                font-size: 21px;
                line-height: 1.25;
            }

            .btn-manual {
                width: 100%;
                padding: 12px;
                border-radius: 14px;
            }

            .qr-module {
                border-radius: 22px;
                padding: 18px;
                margin-bottom: 22px;
            }

            .qr-header {
                gap: 13px;
                margin-bottom: 18px;
            }

            .qr-header h3 {
                font-size: 23px;
            }

            .qr-header p {
                font-size: 13px;
                line-height: 1.35;
            }

            .qr-icon-wrap {
                width: 58px;
                height: 58px;
                min-width: 58px;
                border-radius: 18px;
                font-size: 27px;
            }

            .qr-reader-shell {
                max-width: 100%;
                min-height: 285px;
                border-radius: 22px;
                padding: 10px;
            }

            #reader-container {
                min-height: 265px;
                border-radius: 17px;
            }

            #reader-container video {
                min-height: 265px;
                border-radius: 17px;
            }

            .qr-reader-placeholder {
                inset: 10px;
                border-radius: 17px;
            }

            .qr-reader-placeholder i {
                font-size: 42px;
            }

            .qr-status-card {
                width: 100%;
                justify-content: center;
                text-align: center;
                padding: 10px 12px;
                font-size: 13px;
                border-radius: 14px;
            }

            .qr-controls {
                gap: 10px;
                margin-top: 14px;
            }

            .btn-qr {
                width: 100%;
                min-width: 100%;
                padding: 13px;
                border-radius: 14px;
            }

            .qr-last-code {
                width: 100%;
                border-radius: 14px;
            }

            .stats-card {
                padding: 15px 10px;
                min-height: 118px;
                border-radius: 14px;
            }

            .stats-icon {
                font-size: 25px;
            }

            .stats-number {
                font-size: 1.45rem;
            }

            .stats-label {
                font-size: 0.76rem;
            }

            .table-custom {
                border-radius: 14px;
            }

            .table-custom th,
            .table-custom td {
                padding: 10px;
                font-size: 13px;
                white-space: nowrap;
            }

            .toast-notification {
                left: 12px;
                right: 12px;
                bottom: 12px;
                min-width: unset;
                max-width: unset;
            }

            .modal-dialog {
                margin: 10px;
            }
        }

        @media (max-width: 420px) {
            .main-content {
                padding: 10px;
            }

            .qr-module {
                padding: 15px;
                border-radius: 20px;
            }

            .qr-header h3 {
                font-size: 21px;
            }

            .qr-header p {
                font-size: 12px;
            }

            .qr-reader-shell {
                min-height: 255px;
            }

            #reader-container {
                min-height: 235px;
            }

            #reader-container video {
                min-height: 235px;
            }
        }
    </style>
</head>

<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="container-fluid">

            <div class="page-header">
                <h2><i class="fas fa-qrcode"></i> Registro de Asistencias</h2>
                <button class="btn-manual" data-bs-toggle="modal" data-bs-target="#modalRegistroManual">
                    <i class="fas fa-hand-pointer"></i> Registro Manual
                </button>
            </div>

            <div class="qr-module">
                <div class="qr-header">
                    <div class="qr-icon-wrap" id="qrAnimation">
                        <i class="fas fa-qrcode"></i>
                    </div>

                    <div>
                        <h3>Lector QR</h3>
                        <p>Escanea el código del socio para registrar entrada o salida.</p>
                    </div>
                </div>

                <div class="qr-reader-shell">
                    <div id="reader-container"></div>

                    <div class="qr-reader-placeholder" id="qrPlaceholder">
                        <i class="fas fa-camera"></i>
                        <span>Presiona iniciar para activar la cámara</span>
                    </div>
                </div>

                <div class="qr-status-card" id="lectorStatus">
                    <span class="status-dot"></span>
                    <span id="statusText">Cámara inactiva</span>
                </div>

                <div class="qr-controls">
                    <button class="btn-qr btn-start" id="startCameraBtn">
                        <i class="fas fa-camera"></i> Iniciar cámara
                    </button>

                    <button class="btn-qr btn-stop" id="stopCameraBtn" disabled>
                        <i class="fas fa-stop"></i> Detener
                    </button>
                </div>

                <!-- <div class="qr-last-code" id="ultimoCodigoQR" style="display:none;">
                    <small>Último código leído:</small>
                    <strong id="ultimoCodigoTexto"></strong>
                </div> -->
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
                            <tr>
                                <td colspan="6" class="text-center py-4">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Cargando...</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <div class="modal fade" id="modalRegistroManual" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header" style="background: #1e3a8a; color: white;">
                    <h5 class="modal-title"><i class="fas fa-hand-pointer"></i> Registro Manual de Asistencia</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
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
        let html5QrCode = null;
        let isScanning = false;
        let escaneoBloqueado = false;
        let tiempoUltimoEscaneo = 0;

        let currentListadoTipo = 'todos';
        let currentListadoFiltro = '';

        function actualizarHora() {
            const now = new Date();
            document.getElementById('horaActual').innerHTML = now.toLocaleTimeString();
        }

        setInterval(actualizarHora, 1000);
        actualizarHora();

        function escapeHtml(text) {
            if (!text) return '';

            return String(text).replace(/[&<>"]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                if (m === '"') return '&quot;';
                return m;
            });
        }

        function mostrarNotificacion(titulo, mensaje, tipo = 'success') {
            const toast = $(`
                <div class="toast align-items-center text-white bg-${tipo} border-0 show" role="alert">
                    <div class="d-flex">
                        <div class="toast-body">
                            <strong>${escapeHtml(titulo)}</strong><br>${escapeHtml(mensaje)}
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            `);

            $('#toastNotification').html(toast);
            setTimeout(() => toast.remove(), 4000);
        }

        function animarLector() {
            $('#qrAnimation').addClass('active');
            setTimeout(() => $('#qrAnimation').removeClass('active'), 600);
        }

        function setEstadoLector(estado, texto) {
            const status = $('#lectorStatus');
            status.removeClass('active error');

            if (estado === 'active') {
                status.addClass('active');
            }

            if (estado === 'error') {
                status.addClass('error');
            }

            $('#statusText').html(texto);
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

                        const metodoBadge = a.metodo_registro === 'qr'
                            ? '<span class="badge bg-info"><i class="fas fa-qrcode"></i> QR</span>'
                            : '<span class="badge bg-secondary"><i class="fas fa-hand-pointer"></i> Manual</span>';

                        tbody.append(`
                            <tr>
                                <td>
                                    <strong>${escapeHtml(a.nombre)} ${escapeHtml(a.apellido)}</strong>
                                    <br><small class="text-muted">${escapeHtml(a.telefono || 'Sin teléfono')}</small>
                                </td>
                                <td><span class="badge-plan ${badgeClass}">${escapeHtml(a.plan_nombre || 'Sin plan')}</span></td>
                                <td class="${diasClass}">${a.dias_restantes !== null ? a.dias_restantes + ' días' : 'N/A'}</td>
                                <td>${escapeHtml(a.hora_entrada)}</td>
                                <td>${escapeHtml(a.hora_salida || '--:--')}</td>
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
                                        <i class="fas fa-qrcode"></i> Use el lector QR o
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

        async function iniciarLectorQR() {
            if (isScanning) {
                mostrarNotificacion('Info', 'El lector ya está activo', 'info');
                return;
            }

            const readerContainer = document.getElementById("reader-container");
            readerContainer.innerHTML = "";

            html5QrCode = new Html5Qrcode("reader-container");

            const isMobile = window.innerWidth <= 768;

            const config = {
                fps: 10,
                qrbox: isMobile
                    ? { width: 220, height: 220 }
                    : { width: 260, height: 260 },
                aspectRatio: 1.0,
                disableFlip: false
            };

            try {
                $('#qrPlaceholder').addClass('hidden');

                await html5QrCode.start(
                    { facingMode: "environment" },
                    config,
                    onScanSuccess,
                    onScanError
                );

                isScanning = true;

                setEstadoLector('active', '<i class="fas fa-camera"></i> Cámara activa - Escaneando...');
                $('#startCameraBtn').prop('disabled', true);
                $('#stopCameraBtn').prop('disabled', false);

                mostrarNotificacion('Lector QR', 'Cámara activada. Escanea el código del socio.', 'success');
            } catch (err) {
                console.error("Error al iniciar cámara:", err);

                $('#qrPlaceholder').removeClass('hidden');
                setEstadoLector('error', '<i class="fas fa-exclamation-triangle"></i> Error al acceder a la cámara');

                Swal.fire('Error', 'No se pudo acceder a la cámara. Verifica los permisos.\n\nError: ' + err, 'error');
            }
        }

        function detenerLectorQR() {
            if (html5QrCode && isScanning) {
                html5QrCode.stop().then(() => {
                    isScanning = false;

                    $('#reader-container').html('');
                    $('#qrPlaceholder').removeClass('hidden');

                    setEstadoLector('', 'Cámara inactiva');
                    $('#startCameraBtn').prop('disabled', false);
                    $('#stopCameraBtn').prop('disabled', true);

                    mostrarNotificacion('Lector QR', 'Cámara detenida', 'info');
                }).catch(err => {
                    console.error("Error al detener cámara:", err);
                });
            }
        }

        function onScanSuccess(decodedText, decodedResult) {
            const ahora = Date.now();

            if (escaneoBloqueado || (ahora - tiempoUltimoEscaneo < 2000)) {
                return;
            }

            tiempoUltimoEscaneo = ahora;
            escaneoBloqueado = true;

            const codigoQR = String(decodedText).trim();

            console.log("Código escaneado:", codigoQR);

            $('#ultimoCodigoTexto').text(codigoQR);
            $('#ultimoCodigoQR').show();

            animarLector();
            setEstadoLector('active', '<i class="fas fa-check-circle"></i> Código leído, procesando...');

            $.ajax({
                url: 'includes/procesar_qr_asistencia.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    codigo_qr: codigoQR
                },
                success: function(response) {
                    console.log("Respuesta backend:", response);

                    if (response.success) {
                        const mensaje = response.tipo === 'entrada'
                            ? `Entrada registrada a las ${response.hora_entrada}`
                            : `Salida registrada a las ${response.hora_salida}`;

                        mostrarNotificacion(
                            response.tipo === 'entrada' ? 'ENTRADA' : 'SALIDA',
                            `${response.cliente_nombre || 'Cliente'} - ${mensaje}`,
                            'success'
                        );

                        setEstadoLector('active', '<i class="fas fa-check-circle"></i> Registro aplicado correctamente');

                        actualizarTabla();
                        actualizarEstadisticas();
                    } else {
                        const mensajeError =
                            response.message ||
                            response.mensaje ||
                            response.error ||
                            'Acceso denegado. No se encontró información del cliente o no tiene membresía activa.';

                        mostrarNotificacion('ACCESO DENEGADO', mensajeError, 'danger');
                        setEstadoLector('error', '<i class="fas fa-times-circle"></i> Acceso denegado');

                        actualizarEstadisticas();
                    }

                    setTimeout(() => {
                        escaneoBloqueado = false;

                        if (isScanning) {
                            setEstadoLector('active', '<i class="fas fa-camera"></i> Cámara activa - Escaneando...');
                        }
                    }, 2200);
                },
                error: function(xhr) {
                    console.error("Error AJAX:", xhr.responseText);

                    mostrarNotificacion(
                        'Error',
                        'El servidor no respondió correctamente. Revisa procesar_qr_asistencia.php',
                        'danger'
                    );

                    setEstadoLector('error', '<i class="fas fa-exclamation-triangle"></i> Error del servidor');

                    setTimeout(() => {
                        escaneoBloqueado = false;

                        if (isScanning) {
                            setEstadoLector('active', '<i class="fas fa-camera"></i> Cámara activa - Escaneando...');
                        }
                    }, 2200);
                }
            });
        }

        function onScanError(errorMessage) {
            if (
                errorMessage.includes("NotFoundException") ||
                errorMessage.includes("No MultiFormat Readers") ||
                errorMessage.includes("QR code parse error")
            ) {
                return;
            }

            console.log("Error de escaneo:", errorMessage);
        }

        document.getElementById('startCameraBtn').addEventListener('click', iniciarLectorQR);
        document.getElementById('stopCameraBtn').addEventListener('click', detenerLectorQR);

        window.addEventListener('beforeunload', function() {
            if (html5QrCode && isScanning) {
                html5QrCode.stop().catch(e => console.log("Error al detener"));
            }
        });

        function cambiarPaso(paso) {
            $('#pasoSeleccion, #pasoListado, #pasoConfirmacion').hide();
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

            $.post('includes/obtener_clientes_asistencia.php', {
                tipo: tipo,
                filtro: filtro
            }, function(response) {
                if (response.success && response.clientes && response.clientes.length > 0) {
                    container.empty();

                    response.clientes.forEach(cliente => {
                        const tienePlan = cliente.tiene_plan;

                        const estadoPlan = tienePlan
                            ? `<span class="badge bg-success">${escapeHtml(cliente.plan_nombre || 'Activo')} - ${cliente.dias_restantes} días</span>`
                            : `<span class="badge bg-danger">Sin plan activo</span>`;

                        const disabledClass = !tienePlan ? 'disabled' : '';

                        const nombreCompleto = `${cliente.nombre} ${cliente.apellido}`;
                        const onclickAttr = tienePlan
                            ? `onclick="seleccionarClienteFinal(${cliente.id}, '${escapeHtml(nombreCompleto)}', '${escapeHtml(cliente.plan_nombre || 'Sin plan')}', ${cliente.dias_restantes || 0})"`
                            : '';

                        container.append(`
                            <div class="list-group-item cliente-item ${disabledClass}" ${onclickAttr}>
                                <div class="d-flex justify-content-between align-items-center gap-2">
                                    <div>
                                        <strong>${escapeHtml(cliente.nombre)} ${escapeHtml(cliente.apellido)}</strong>
                                        <br><small class="text-muted">${escapeHtml(cliente.telefono || 'Sin teléfono')}</small>
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
            $('#confirmacionDetalle').html(`${escapeHtml(plan)} - ${dias} días restantes`);
            $('#btnRegistrarManual').prop('disabled', false);
            cambiarPaso('pasoConfirmacion');
        }

        $(document).on('input', '#filtroListado', function() {
            const filtro = $(this).val().toLowerCase();

            if (currentListadoTipo === 'buscar') {
                cargarClientes('buscar', filtro);
            } else {
                $('#listaClientes .list-group-item').each(function() {
                    const texto = $(this).text().toLowerCase();
                    $(this).toggle(texto.includes(filtro));
                });
            }
        });

        $('#btnRegistrarManual').on('click', function() {
            const clienteId = $('#clienteSeleccionadoId').val();
            const tipo = $('#tipoRegistro').val();

            if (!clienteId) {
                Swal.fire('Error', 'Seleccione un cliente', 'error');
                return;
            }

            const btn = $(this);
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Procesando...');

            $.post('includes/registrar_asistencia_manual.php', {
                cliente_id: clienteId,
                tipo: tipo
            }, function(response) {
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

        $('#modalRegistroManual').on('hidden.bs.modal', function() {
            $('#clienteSeleccionadoId, #filtroListado').val('');
            $('#confirmacionNombre, #confirmacionDetalle').html('');
            $('#btnRegistrarManual').prop('disabled', true);
            volverAlInicio();
        });

        actualizarTabla();
        actualizarEstadisticas();
        setInterval(actualizarEstadisticas, 30000);
        setInterval(actualizarTabla, 15000);
    </script>
</body>
</html>