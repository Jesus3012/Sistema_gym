<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/sucursal_context.php';

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

$database = new Database();
$conn = $database->getConnection();

if (!$conn instanceof mysqli) {
    die('No se pudo establecer la conexión con la base de datos.');
}

$conn->set_charset('utf8mb4');

$usuario_id = (int) ($_SESSION['user_id'] ?? 0);
$usuario_rol = strtolower(trim((string) (
    $_SESSION['user_rol'] ?? ''
)));
$usuario_rol_base = strtolower(trim((string) (
    $_SESSION['user_rol_base'] ?? $usuario_rol
)));

$sucursal_id = (int) ($_SESSION['sucursal_id'] ?? 0);
$sucursal_nombre = trim((string) (
    $_SESSION['sucursal_nombre'] ?? 'Sucursal'
));
$sucursal_clave = trim((string) (
    $_SESSION['sucursal_clave'] ?? ''
));

$puede_vista_global = in_array(
    $usuario_rol_base,
    ['admin', 'administrador'],
    true
);

$vista_solicitada = strtolower(trim((string) (
    $_GET['vista'] ?? ''
)));

if (
    $vista_solicitada === 'global'
    && $puede_vista_global
) {
    sucursal_activar_vista_global(
        $conn,
        $usuario_id
    );
} elseif ($vista_solicitada === 'sucursal') {
    sucursal_desactivar_vista_global();
}

$vista_global_asistencias =
    $puede_vista_global
    && sucursal_dashboard_vista_global();

if ($sucursal_id <= 0) {
    $_SESSION['error'] =
        'Selecciona una sucursal operativa antes de abrir asistencias.';
    header('Location: dashboard.php');
    exit;
}

$stmtSucursal = $conn->prepare(
    "SELECT
        nombre,
        clave,
        es_matriz,
        estado
     FROM sucursales
     WHERE id = ?
     LIMIT 1"
);
$stmtSucursal->bind_param('i', $sucursal_id);
$stmtSucursal->execute();
$sucursal_actual = $stmtSucursal
    ->get_result()
    ->fetch_assoc();
$stmtSucursal->close();

if (
    !is_array($sucursal_actual)
    || ($sucursal_actual['estado'] ?? '') !== 'activa'
) {
    $_SESSION['error'] =
        'La sucursal seleccionada está inactiva.';
    header('Location: dashboard.php');
    exit;
}

$sucursal_nombre = trim((string) (
    $sucursal_actual['nombre'] ?? $sucursal_nombre
));
$sucursal_clave = trim((string) (
    $sucursal_actual['clave'] ?? $sucursal_clave
));
$sucursal_es_matriz =
    (int) ($sucursal_actual['es_matriz'] ?? 0) === 1;

date_default_timezone_set(
    (string) (
        $_SESSION['sucursal_zona_horaria']
        ?? 'America/Mexico_City'
    )
);

$fecha_hoy = date('Y-m-d');
$hora_actual = date('H:i:s');

/*
 * En el consolidado solo se consulta información.
 * Para registrar una entrada o salida debe elegirse una sede concreta,
 * porque toda asistencia necesita un sucursal_id real.
 */
$registro_asistencia_habilitado =
    !$vista_global_asistencias;
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
    <link rel="stylesheet" href="css/asistencias.css?v=<?php echo is_file(
        __DIR__ . '/css/asistencias.css'
    ) ? filemtime(__DIR__ . '/css/asistencias.css') : time(); ?>">

    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

    <style>
        .attendance-context {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-height: 31px;
            margin-top: 10px;
            padding: 0 11px;
            border: 1px solid #bfd3fb;
            border-radius: 8px;
            color: #1e3a8a;
            background: #eff6ff;
            font-size: .76rem;
            font-weight: 800;
        }

        .attendance-context.is-global {
            border-color: #a7f3d0;
            color: #047857;
            background: #ecfdf5;
        }

        .global-readonly-notice {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin: 0 0 18px;
            padding: 12px 14px;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            color: #1e40af;
            background: #eff6ff;
            font-size: .84rem;
            line-height: 1.45;
        }

        .global-readonly-notice i {
            margin-top: 2px;
        }

        .attendance-branch-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            max-width: 120px;
            padding: 5px 8px;
            border: 1px solid #c7d7fe;
            border-radius: 7px;
            color: #1d4ed8;
            background: #eff6ff;
            font-size: .68rem;
            font-weight: 850;
            line-height: 1;
            text-transform: uppercase;
        }

        .attendance-branch-chip span {
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .qr-module.is-readonly {
            opacity: .76;
        }

        .qr-module.is-readonly .qr-reader-shell {
            pointer-events: none;
        }

        @media (max-width: 767.98px) {
            .attendance-context {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="container-fluid">

            <div class="page-header">
                <div class="page-header-copy">
                    <span class="page-kicker">Control de acceso</span>
                    <h2>
                        <i class="fas fa-qrcode"></i>
                        Registro de Asistencias
                    </h2>
                    <p>
                        <?php if ($vista_global_asistencias): ?>
                            Consulta la actividad consolidada de todas las sucursales.
                        <?php else: ?>
                            Escanea el código del socio o registra su asistencia en esta sede.
                        <?php endif; ?>
                    </p>

                    <span class="attendance-context <?php echo $vista_global_asistencias
                        ? 'is-global'
                        : ''; ?>">
                        <i class="fas <?php echo $vista_global_asistencias
                            ? 'fa-chart-pie'
                            : 'fa-location-dot'; ?>"></i>

                        <?php if ($vista_global_asistencias): ?>
                            Todas las sucursales
                        <?php else: ?>
                            <?php echo htmlspecialchars(
                                $sucursal_nombre,
                                ENT_QUOTES,
                                'UTF-8'
                            ); ?>
                            <?php if ($sucursal_es_matriz): ?>
                                · Matriz
                            <?php endif; ?>
                        <?php endif; ?>
                    </span>
                </div>

                <button
                    class="btn-manual"
                    type="button"
                    <?php if ($registro_asistencia_habilitado): ?>
                        data-bs-toggle="modal"
                        data-bs-target="#modalRegistroManual"
                    <?php else: ?>
                        disabled
                        title="Selecciona una sucursal para registrar asistencias"
                    <?php endif; ?>
                >
                    <i class="fas fa-hand-pointer"></i>
                    Registro Manual
                </button>
            </div>

            <?php if ($vista_global_asistencias): ?>
                <div class="global-readonly-notice">
                    <i class="fas fa-circle-info"></i>
                    <div>
                        <strong>Vista consolidada.</strong>
                        Para escanear un QR o registrar una entrada/salida manual,
                        selecciona una sucursal específica desde el menú lateral.
                    </div>
                </div>
            <?php endif; ?>

            <div class="qr-module <?php echo $vista_global_asistencias
                ? 'is-readonly'
                : ''; ?>">
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
                    <button
                        class="btn-qr btn-start"
                        id="startCameraBtn"
                        <?php echo $registro_asistencia_habilitado
                            ? ''
                            : 'disabled'; ?>
                    >
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

            <div class="section-heading">
                <div>
                    <span class="section-kicker">Resumen</span>
                    <h3>Actividad de hoy</h3>
                </div>
            </div>

            <div class="row mb-4 stats-grid">
                <div class="col-md-3 col-6">
                    <div class="stats-card">
                        <div class="stats-icon"><i class="fas fa-calendar-day"></i></div>
                        <div class="stats-number" id="totalAsistencias">0</div>
                        <div class="stats-label">
                            <?php echo $vista_global_asistencias
                                ? 'Asistencias globales hoy'
                                : 'Asistencias hoy'; ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 col-6">
                    <div class="stats-card">
                        <div class="stats-icon"><i class="fas fa-users"></i></div>
                        <div class="stats-number" id="clientesActivos">0</div>
                        <div class="stats-label">Socios con acceso</div>
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
                        <div class="stats-label">
                            <?php echo $vista_global_asistencias
                                ? 'Denegados globales'
                                : 'Accesos denegados'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="section-heading section-heading-table">
                <div>
                    <span class="section-kicker">Movimientos</span>
                    <h3>Asistencias registradas</h3>
                </div>
            </div>

            <div class="table-custom">
                <div class="table-responsive-custom">
                    <table class="table table-hover mb-0 responsive-table">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Plan</th>
                                <?php if ($vista_global_asistencias): ?>
                                    <th>Sede</th>
                                <?php endif; ?>
                                <th>Días restantes</th>
                                <th>Entrada</th>
                                <th>Salida</th>
                                <th>Método</th>
                            </tr>
                        </thead>
                        <tbody id="tablaAsistencias">
                            <tr>
                                <td colspan="<?php echo $vista_global_asistencias ? 7 : 6; ?>" class="text-center py-4">
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
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content modal-custom">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-hand-pointer"></i> Registro Manual de Asistencia</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div id="pasoSeleccion">
                        <label class="form-label fw-bold mb-3">Seleccione una opción:</label>

                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-outline-primary btn-lg manual-option" onclick="mostrarListadoCompleto()">
                                <i class="fas fa-list"></i> Ver todos los clientes activos
                            </button>

                            <button type="button" class="btn btn-outline-success btn-lg manual-option" onclick="mostrarRecientes()">
                                <i class="fas fa-clock"></i> Clientes que asistieron hoy
                            </button>

                            <button type="button" class="btn btn-outline-warning btn-lg manual-option" onclick="mostrarProximosAVencer()">
                                <i class="fas fa-exclamation-triangle"></i> Planes por vencer (7 días)
                            </button>

                            <hr>

                            <button type="button" class="btn btn-outline-secondary btn-lg manual-option" onclick="mostrarBuscador()">
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
        const vistaGlobalAsistencias = <?php echo $vista_global_asistencias
            ? 'true'
            : 'false'; ?>;

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

        /*
         * Los endpoints usan códigos HTTP 4xx para reglas de negocio,
         * por ejemplo una membresía vencida. jQuery envía esas respuestas
         * a error/fail aunque el servidor sí haya devuelto JSON válido.
         */
        function obtenerDetalleErrorAjax(xhr, mensajeAlternativo) {
            let respuesta = xhr && xhr.responseJSON
                ? xhr.responseJSON
                : null;

            if (
                !respuesta &&
                xhr &&
                typeof xhr.responseText === 'string' &&
                xhr.responseText.trim() !== ''
            ) {
                try {
                    respuesta = JSON.parse(xhr.responseText);
                } catch (error) {
                    respuesta = null;
                }
            }

            const status = Number(
                xhr && xhr.status
                    ? xhr.status
                    : 0
            );

            return {
                status: status,
                code: respuesta && respuesta.code
                    ? String(respuesta.code)
                    : '',
                message:
                    respuesta &&
                    (
                        respuesta.message ||
                        respuesta.mensaje ||
                        respuesta.error
                    )
                        ? String(
                            respuesta.message ||
                            respuesta.mensaje ||
                            respuesta.error
                        )
                        : mensajeAlternativo,
                esReglaNegocio:
                    status === 400 ||
                    status === 404 ||
                    status === 409 ||
                    status === 422
            };
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

        function prepararTablaResponsive() {
            const tabla = document.querySelector('.responsive-table');

            if (!tabla) {
                return;
            }

            const encabezados = Array.from(
                tabla.querySelectorAll('thead th')
            ).map(function(th) {
                return th.textContent.trim();
            });

            tabla.querySelectorAll('tbody tr').forEach(function(fila) {
                fila.querySelectorAll('td').forEach(function(celda, indice) {
                    if (!celda.hasAttribute('colspan')) {
                        celda.setAttribute(
                            'data-label',
                            encabezados[indice] || ''
                        );
                    }
                });
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
                                ${vistaGlobalAsistencias ? `
                                    <td>
                                        <span
                                            class="attendance-branch-chip"
                                            title="${escapeHtml(a.sucursal_nombre || 'Sucursal')}"
                                        >
                                            <i class="fas fa-building"></i>
                                            <span>${escapeHtml(a.sucursal_clave || 'SEDE')}</span>
                                        </span>
                                    </td>
                                ` : ''}
                                <td class="${diasClass}">${a.dias_restantes !== null ? a.dias_restantes + ' días' : 'N/A'}</td>
                                <td>${escapeHtml(a.hora_entrada)}</td>
                                <td>${escapeHtml(a.hora_salida || '--:--')}</td>
                                <td>${metodoBadge}</td>
                            </tr>
                        `);
                    });

                    prepararTablaResponsive();
                } else {
                    tbody.html(`
                        <tr>
                            <td colspan="${vistaGlobalAsistencias ? 7 : 6}" class="text-center py-5">
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
            if (vistaGlobalAsistencias) {
                Swal.fire({
                    icon: 'info',
                    title: 'Selecciona una sucursal',
                    text: 'El registro de asistencias requiere una sede concreta.',
                    confirmButtonColor: '#1e3a8a'
                });
                return;
            }

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
                    console.error(
                        'Respuesta del endpoint QR:',
                        xhr.responseJSON || xhr.responseText
                    );

                    const detalle = obtenerDetalleErrorAjax(
                        xhr,
                        'No se pudo procesar el código QR.'
                    );

                    if (detalle.status === 401) {
                        mostrarNotificacion(
                            'SESIÓN FINALIZADA',
                            detalle.message,
                            'danger'
                        );

                        setEstadoLector(
                            'error',
                            '<i class="fas fa-user-lock"></i> Sesión finalizada'
                        );
                    } else if (detalle.esReglaNegocio) {
                        mostrarNotificacion(
                            'ACCESO DENEGADO',
                            detalle.message,
                            'danger'
                        );

                        setEstadoLector(
                            'error',
                            '<i class="fas fa-times-circle"></i> ' +
                            escapeHtml(detalle.message)
                        );

                        /*
                         * El intento denegado puede haberse registrado,
                         * por eso se actualiza la tarjeta correspondiente.
                         */
                        actualizarEstadisticas();
                    } else {
                        mostrarNotificacion(
                            'ERROR DEL SERVIDOR',
                            detalle.message,
                            'danger'
                        );

                        setEstadoLector(
                            'error',
                            '<i class="fas fa-exclamation-triangle"></i> Error del servidor'
                        );
                    }

                    setTimeout(() => {
                        escaneoBloqueado = false;

                        if (isScanning) {
                            setEstadoLector(
                                'active',
                                '<i class="fas fa-camera"></i> Cámara activa - Escaneando...'
                            );
                        }
                    }, 2600);
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

        const startCameraBtn = document.getElementById('startCameraBtn');
        const stopCameraBtn = document.getElementById('stopCameraBtn');

        if (startCameraBtn) {
            startCameraBtn.addEventListener('click', iniciarLectorQR);
        }

        if (stopCameraBtn) {
            stopCameraBtn.addEventListener('click', detenerLectorQR);
        }

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

                    prepararTablaResponsive();
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
            }, 'json').fail(function(xhr) {
                btn.prop('disabled', false).html('Registrar');

                const detalle = obtenerDetalleErrorAjax(
                    xhr,
                    'No se pudo registrar la asistencia.'
                );

                Swal.fire({
                    icon: detalle.esReglaNegocio
                        ? 'warning'
                        : 'error',
                    title: detalle.esReglaNegocio
                        ? 'Registro no permitido'
                        : 'Error del servidor',
                    text: detalle.message,
                    confirmButtonColor: '#1e3a8a'
                });

                if (detalle.esReglaNegocio) {
                    actualizarEstadisticas();
                }
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