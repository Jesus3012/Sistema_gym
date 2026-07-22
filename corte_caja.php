<?php

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/caja_helper.php';
require_once __DIR__ . '/includes/sucursal_context.php';

date_default_timezone_set('America/Mexico_City');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$usuarioId = (int) ($_SESSION['user_id'] ?? 0);
$usuarioRol = strtolower(trim((string) ($_SESSION['user_rol'] ?? '')));
$usuarioNombre = trim((string) ($_SESSION['user_name'] ?? 'Usuario'));

if ($usuarioId <= 0) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$conn = $database->getConnection();

if (!$conn instanceof mysqli) {
    die('No fue posible establecer conexión con la base de datos.');
}

$conn->set_charset('utf8mb4');

$usuarioRolBase = strtolower(trim((string) (
    $_SESSION['user_rol_base'] ?? $usuarioRol
)));

$esAdministradorCaja = in_array(
    $usuarioRolBase,
    array('admin', 'administrador'),
    true
);

try {
    $sucursalActiva = function_exists('sucursal_inicializar_sesion')
        ? sucursal_inicializar_sesion($conn)
        : null;
} catch (Throwable $errorSucursal) {
    die(htmlspecialchars($errorSucursal->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$vistaSolicitadaCaja = strtolower(trim((string) (
    $_GET['vista'] ?? ''
)));

if (
    $esAdministradorCaja
    && $vistaSolicitadaCaja === 'global'
    && function_exists('sucursal_activar_vista_global')
) {
    sucursal_activar_vista_global($conn, $usuarioId);
}

if (
    $vistaSolicitadaCaja === 'sucursal'
    && function_exists('sucursal_desactivar_vista_global')
) {
    sucursal_desactivar_vista_global();
}

$vistaGlobalCaja = $esAdministradorCaja
    && function_exists('sucursal_dashboard_vista_global')
    && sucursal_dashboard_vista_global();

$sucursalId = (int) ($_SESSION['sucursal_id'] ?? 0);

if (!$vistaGlobalCaja) {
    if (
        function_exists('sucursal_buscar_asignada')
        && $sucursalId > 0
    ) {
        $sucursalActiva = sucursal_buscar_asignada(
            $conn,
            $usuarioId,
            $sucursalId
        );
    }

    if (!is_array($sucursalActiva)) {
        die('No existe una sucursal operativa seleccionada.');
    }

    $zonaCaja = trim((string) (
        $sucursalActiva['zona_horaria']
        ?? 'America/Mexico_City'
    ));

    if ($zonaCaja !== '') {
        date_default_timezone_set($zonaCaja);
    }
}

$puedeOperarCajaSucursal = !$vistaGlobalCaja
    && is_array($sucursalActiva)
    && (
        $esAdministradorCaja
        || (int) ($sucursalActiva['puede_operar_caja'] ?? 0) === 1
    );

if (empty($_SESSION['csrf_corte_caja'])) {
    $_SESSION['csrf_corte_caja'] = bin2hex(random_bytes(32));
}

function hCaja($valor) {
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function dineroCaja($valor) {
    return '$' . number_format((float) ($valor ?? 0), 2, '.', ',');
}

function validarMontoCaja($valor, $permitirCero = false) {
    $valor = trim($valor);

    if ($valor === '' || !is_numeric($valor)) {
        throw new InvalidArgumentException('Ingresa un monto válido.');
    }

    $monto = round((float) $valor, 2);

    if ($permitirCero ? $monto < 0 : $monto <= 0) {
        throw new InvalidArgumentException(
            $permitirCero
                ? 'El monto no puede ser negativo.'
                : 'El monto debe ser mayor a cero.'
        );
    }

    // Permite 0.00, 0.50, 1.00, 1.50, etc.
    if (abs(($monto * 2) - round($monto * 2)) > 0.00001) {
        throw new InvalidArgumentException('Usa montos en incrementos de $0.50.');
    }

    return $monto;
}

function flashCaja($tipo, $titulo, $mensaje) {
    $_SESSION['caja_flash'] = [
        'tipo' => $tipo,
        'titulo' => $titulo,
        'mensaje' => $mensaje,
    ];

    header('Location: corte_caja.php');
    exit();
}


/**
 * Construye enlaces conservando los filtros/páginas del otro listado.
 */
function urlCorteCaja($cambios = array()) {
    $parametros = $_GET;

    foreach ($cambios as $clave => $valor) {
        if ($valor === null || $valor === '') {
            unset($parametros[$clave]);
        } else {
            $parametros[$clave] = $valor;
        }
    }

    $consulta = http_build_query($parametros);
    return 'corte_caja.php' . ($consulta !== '' ? '?' . $consulta : '');
}

/**
 * Devuelve un rango compacto de páginas con separadores.
 */
function paginasCorteCaja($paginaActual, $totalPaginas) {
    $paginaActual = max(1, (int) $paginaActual);
    $totalPaginas = max(1, (int) $totalPaginas);

    if ($totalPaginas <= 7) {
        return range(1, $totalPaginas);
    }

    $paginas = array(1);
    $inicio = max(2, $paginaActual - 1);
    $fin = min($totalPaginas - 1, $paginaActual + 1);

    if ($inicio > 2) {
        $paginas[] = '...';
    }

    for ($pagina = $inicio; $pagina <= $fin; $pagina++) {
        $paginas[] = $pagina;
    }

    if ($fin < $totalPaginas - 1) {
        $paginas[] = '...';
    }

    $paginas[] = $totalPaginas;
    return $paginas;
}

function contarCortesCerradosPaginados(
    $conn,
    $usuarioId,
    $esAdmin,
    $sucursalId,
    $vistaGlobal
) {
    if ($esAdmin && $vistaGlobal) {
        $resultado = $conn->query(
            "SELECT COUNT(*) AS total
             FROM cajas
             WHERE estado = 'cerrada'"
        );

        if (!$resultado) {
            throw new RuntimeException(
                'No fue posible contar el historial de cortes.'
            );
        }

        return (int) $resultado->fetch_assoc()['total'];
    }

    if ($esAdmin) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM cajas
             WHERE estado = 'cerrada'
               AND sucursal_id = ?"
        );
        $stmt->bind_param('i', $sucursalId);
    } else {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM cajas
             WHERE estado = 'cerrada'
               AND sucursal_id = ?
               AND usuario_apertura_id = ?"
        );
        $stmt->bind_param(
            'ii',
            $sucursalId,
            $usuarioId
        );
    }

    if (!$stmt) {
        throw new RuntimeException(
            'No fue posible preparar el conteo del historial.'
        );
    }

    $stmt->execute();
    $resultado = $stmt->get_result();
    $total = $resultado
        ? (int) $resultado->fetch_assoc()['total']
        : 0;
    $stmt->close();

    return $total;
}

function listarCortesCerradosPaginados(
    $conn,
    $usuarioId,
    $esAdmin,
    $sucursalId,
    $vistaGlobal,
    $limite,
    $offset
) {
    $limite = max(1, (int) $limite);
    $offset = max(0, (int) $offset);

    $seleccion = "SELECT c.*,
                         COALESCE(
                             u.nombre,
                             'Usuario no disponible'
                         ) AS usuario_apertura,
                         s.nombre AS sucursal_nombre,
                         s.clave AS sucursal_clave,
                         s.es_matriz AS sucursal_es_matriz
                  FROM cajas c
                  LEFT JOIN usuarios u
                      ON u.id = c.usuario_apertura_id
                  INNER JOIN sucursales s
                      ON s.id = c.sucursal_id";

    if ($esAdmin && $vistaGlobal) {
        $stmt = $conn->prepare(
            $seleccion . "
             WHERE c.estado = 'cerrada'
             ORDER BY c.fecha_cierre DESC, c.id DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param('ii', $limite, $offset);
    } elseif ($esAdmin) {
        $stmt = $conn->prepare(
            $seleccion . "
             WHERE c.estado = 'cerrada'
               AND c.sucursal_id = ?
             ORDER BY c.fecha_cierre DESC, c.id DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param(
            'iii',
            $sucursalId,
            $limite,
            $offset
        );
    } else {
        $stmt = $conn->prepare(
            $seleccion . "
             WHERE c.estado = 'cerrada'
               AND c.sucursal_id = ?
               AND c.usuario_apertura_id = ?
             ORDER BY c.fecha_cierre DESC, c.id DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bind_param(
            'iiii',
            $sucursalId,
            $usuarioId,
            $limite,
            $offset
        );
    }

    if (!$stmt) {
        throw new RuntimeException(
            'No fue posible preparar el historial de cortes.'
        );
    }

    $stmt->execute();
    $resultado = $stmt->get_result();
    $filas = $resultado
        ? $resultado->fetch_all(MYSQLI_ASSOC)
        : array();
    $stmt->close();

    return $filas;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = (string) ($_POST['csrf_token'] ?? '');
        if (!hash_equals((string) $_SESSION['csrf_corte_caja'], $token)) {
            throw new RuntimeException('La sesión del formulario expiró. Recarga la página.');
        }

        $accion = trim((string) ($_POST['accion'] ?? ''));

        if ($vistaGlobalCaja) {
            throw new RuntimeException(
                'Selecciona una sucursal concreta antes de operar una caja.'
            );
        }

        if (!$puedeOperarCajaSucursal) {
            throw new RuntimeException(
                'No tienes autorización para operar caja en esta sucursal.'
            );
        }

        if ($accion === 'abrir_caja') {
            $montoInicial = validarMontoCaja((string) ($_POST['monto_inicial'] ?? ''), true);
            $observaciones = trim((string) ($_POST['observaciones_apertura'] ?? ''));

            if (mb_strlen($observaciones) > 1000) {
                throw new InvalidArgumentException('Las observaciones son demasiado largas.');
            }

            $conn->begin_transaction();

            $cajaExistente = obtenerCajaAbierta($conn, $usuarioId, $sucursalId, true);
            if ($cajaExistente) {
                throw new RuntimeException('Ya tienes una caja abierta con folio ' . $cajaExistente['folio'] . '.');
            }

            $stmt = $conn->prepare(
                "INSERT INTO cajas (
                    sucursal_id,
                    usuario_apertura_id,
                    fecha_apertura,
                    monto_inicial,
                    estado,
                    observaciones_apertura
                ) VALUES (?, ?, NOW(), ?, 'abierta', ?)"
            );

            if (!$stmt) {
                throw new RuntimeException('No se pudo preparar la apertura de caja.');
            }

            $stmt->bind_param('iids', $sucursalId, $usuarioId, $montoInicial, $observaciones);
            $stmt->execute();
            $cajaId = (int) $conn->insert_id;
            $stmt->close();

            $claveFolio = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($sucursalActiva['clave'] ?? 'SUC')));
            $folio = 'CAJ-' . $claveFolio . '-' . date('Ymd') . '-' . str_pad((string) $cajaId, 6, '0', STR_PAD_LEFT);
            $stmt = $conn->prepare("UPDATE cajas SET folio = ? WHERE id = ?");
            $stmt->bind_param('si', $folio, $cajaId);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            flashCaja(
                'success',
                'Caja abierta',
                'La caja se abrió correctamente con el folio ' . $folio . '.'
            );
        }

        if ($accion === 'movimiento_manual') {
            $caja = obtenerCajaAbierta($conn, $usuarioId, $sucursalId);
            if (!$caja) {
                throw new RuntimeException('Primero debes abrir una caja.');
            }

            $tipo = trim((string) ($_POST['tipo_movimiento'] ?? ''));
            $categoria = trim((string) ($_POST['categoria'] ?? ''));
            $concepto = trim((string) ($_POST['concepto'] ?? ''));
            $monto = validarMontoCaja((string) ($_POST['monto'] ?? ''));

            $categoriasEntrada = ['fondo_adicional', 'ingreso_vario', 'ajuste'];
            $categoriasSalida = ['retiro', 'gasto', 'devolucion_manual', 'ajuste'];

            if ($tipo === 'entrada' && !in_array($categoria, $categoriasEntrada, true)) {
                throw new InvalidArgumentException('La categoría no corresponde a una entrada.');
            }

            if ($tipo === 'salida' && !in_array($categoria, $categoriasSalida, true)) {
                throw new InvalidArgumentException('La categoría no corresponde a una salida.');
            }

            registrarMovimientoManual(
                $conn,
                (int) $caja['id'],
                $usuarioId,
                $tipo,
                $categoria,
                $concepto,
                $monto
            );

            flashCaja(
                'success',
                $tipo === 'entrada' ? 'Entrada registrada' : 'Salida registrada',
                'El movimiento de ' . dineroCaja($monto) . ' se guardó correctamente.'
            );
        }

        if ($accion === 'cerrar_caja') {
            $efectivoContado = validarMontoCaja(
                (string) ($_POST['efectivo_contado'] ?? ''),
                true
            );
            $observaciones = trim((string) ($_POST['observaciones_cierre'] ?? ''));

            if (mb_strlen($observaciones) > 1500) {
                throw new InvalidArgumentException('Las observaciones son demasiado largas.');
            }

            $conn->begin_transaction();

            $caja = obtenerCajaAbierta($conn, $usuarioId, $sucursalId, true);
            if (!$caja) {
                throw new RuntimeException('No tienes una caja abierta para cerrar.');
            }

            $fechaCierre = date('Y-m-d H:i:s');
            $resumen = calcularResumenCaja($conn, $caja, $fechaCierre);

            if (!empty($resumen['advertencias'])) {
                throw new RuntimeException(implode(' ', $resumen['advertencias']));
            }

            guardarSnapshotOperacionesCaja($conn, $caja, $fechaCierre);
            $diferencia = round($efectivoContado - $resumen['efectivo_esperado'], 2);

            if (abs($diferencia) < 0.005) {
                $diferencia = 0.00;
            }

            $ventasEfectivo = $resumen['ventas']['efectivo'];
            $ventasTarjeta = $resumen['ventas']['tarjeta'];
            $ventasTransferencia = $resumen['ventas']['transferencia'];
            $membresiasEfectivo = $resumen['membresias']['efectivo'];
            $membresiasTarjeta = $resumen['membresias']['tarjeta'];
            $membresiasTransferencia = $resumen['membresias']['transferencia'];
            $devolucionesEfectivo = $resumen['devoluciones']['efectivo'];
            $devolucionesTarjeta = $resumen['devoluciones']['tarjeta'];
            $devolucionesTransferencia = $resumen['devoluciones']['transferencia'];
            $entradasManuales = $resumen['manuales']['entradas'];
            $salidasManuales = $resumen['manuales']['salidas'];
            $totalBruto = $resumen['total_bruto'];
            $totalDevoluciones = $resumen['total_devoluciones'];
            $totalNeto = $resumen['total_neto'];
            $efectivoEsperado = $resumen['efectivo_esperado'];
            $operacionesVentas = $resumen['ventas']['operaciones'];
            $operacionesMembresias = $resumen['membresias']['operaciones'];
            $operacionesDevoluciones = $resumen['devoluciones']['operaciones'];
            $cajaId = (int) $caja['id'];

            $sql = "UPDATE cajas SET
                        ventas_efectivo = ?,
                        ventas_tarjeta = ?,
                        ventas_transferencia = ?,
                        membresias_efectivo = ?,
                        membresias_tarjeta = ?,
                        membresias_transferencia = ?,
                        devoluciones_efectivo = ?,
                        devoluciones_tarjeta = ?,
                        devoluciones_transferencia = ?,
                        entradas_manuales = ?,
                        salidas_manuales = ?,
                        total_bruto = ?,
                        total_devoluciones = ?,
                        total_neto = ?,
                        efectivo_esperado = ?,
                        efectivo_contado = ?,
                        diferencia = ?,
                        operaciones_ventas = ?,
                        operaciones_membresias = ?,
                        operaciones_devoluciones = ?,
                        usuario_cierre_id = ?,
                        fecha_cierre = ?,
                        observaciones_cierre = ?,
                        estado = 'cerrada'
                    WHERE id = ?
                      AND sucursal_id = ?
                      AND estado = 'abierta'";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException('No se pudo preparar el cierre de caja.');
            }

            $stmt->bind_param(
                'dddddddddddddddddiiiissii',
                $ventasEfectivo,
                $ventasTarjeta,
                $ventasTransferencia,
                $membresiasEfectivo,
                $membresiasTarjeta,
                $membresiasTransferencia,
                $devolucionesEfectivo,
                $devolucionesTarjeta,
                $devolucionesTransferencia,
                $entradasManuales,
                $salidasManuales,
                $totalBruto,
                $totalDevoluciones,
                $totalNeto,
                $efectivoEsperado,
                $efectivoContado,
                $diferencia,
                $operacionesVentas,
                $operacionesMembresias,
                $operacionesDevoluciones,
                $usuarioId,
                $fechaCierre,
                $observaciones,
                $cajaId,
                $sucursalId
            );
            $stmt->execute();

            if ($stmt->affected_rows !== 1) {
                throw new RuntimeException('La caja ya fue cerrada o cambió durante la operación.');
            }

            $stmt->close();
            $conn->commit();

            $_SESSION['caja_flash'] = [
                'tipo' => 'success',
                'titulo' => 'Corte finalizado',
                'mensaje' => 'La caja ' . $caja['folio'] . ' se cerró correctamente.',
                'detalle' => [
                    'esperado' => dineroCaja($efectivoEsperado),
                    'contado' => dineroCaja($efectivoContado),
                    'diferencia' => dineroCaja($diferencia),
                ],
            ];

            header('Location: corte_caja_detalle.php?id=' . $cajaId . '&cerrada=1&vista=sucursal');
            exit();
        }

        throw new InvalidArgumentException('La acción solicitada no es válida.');
    } catch (Throwable $e) {
        if ($conn->errno === 0) {
            // No siempre existe una transacción activa, por eso se protege el rollback.
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
            }
        } else {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
            }
        }

        $_SESSION['caja_flash'] = [
            'tipo' => 'error',
            'titulo' => 'No fue posible completar la operación',
            'mensaje' => $e->getMessage(),
        ];

        header('Location: corte_caja.php');
        exit();
    }
}

$flash = $_SESSION['caja_flash'] ?? null;
unset($_SESSION['caja_flash']);

$cajaAbierta = $vistaGlobalCaja
    ? null
    : obtenerCajaAbierta($conn, $usuarioId, $sucursalId);
$resumen = null;
$operaciones = array();
$totalOperaciones = 0;
$totalPaginasOperaciones = 1;
$errorConsultaCaja = null;

$paginaOperaciones = max(1, (int) (isset($_GET['pagina_operaciones']) ? $_GET['pagina_operaciones'] : 1));
$porPaginaOperaciones = 8;

if ($cajaAbierta) {
    try {
        $resumen = calcularResumenCaja($conn, $cajaAbierta);
        $totalOperaciones = contarOperacionesCaja($conn, $cajaAbierta);
        $totalPaginasOperaciones = max(1, (int) ceil($totalOperaciones / $porPaginaOperaciones));
        $paginaOperaciones = min($paginaOperaciones, $totalPaginasOperaciones);
        $offsetOperaciones = ($paginaOperaciones - 1) * $porPaginaOperaciones;
        $operaciones = listarOperacionesCaja(
            $conn,
            $cajaAbierta,
            $porPaginaOperaciones,
            $offsetOperaciones
        );
    } catch (Throwable $errorCaja) {
        $errorConsultaCaja = $errorCaja->getMessage();
    }
}

/*
 * El helper conserva los accesos a clases dentro de ventas para no romper
 * los cortes históricos existentes. Aquí se separan únicamente para la
 * presentación visual del turno actual.
 */
$resumenClasesVisual = array(
    'operaciones' => 0,
    'efectivo' => 0.00,
    'tarjeta' => 0.00,
    'transferencia' => 0.00,
);
$resumenProductosVisual = array(
    'operaciones' => 0,
    'efectivo' => 0.00,
    'tarjeta' => 0.00,
    'transferencia' => 0.00,
);
$totalProductosVisual = 0.00;
$totalMembresiasVisual = 0.00;
$totalClasesVisual = 0.00;

if (is_array($resumen)) {
    $resumenClasesVisual = is_array($resumen['clases'] ?? null)
        ? $resumen['clases']
        : (is_array($resumen['ventas']['clases'] ?? null)
            ? $resumen['ventas']['clases']
            : $resumenClasesVisual);

    $resumenProductosVisual = is_array($resumen['ventas'] ?? null)
        ? $resumen['ventas']
        : $resumenProductosVisual;

    /*
     * En la integración compatible, clases se suma internamente a ventas.
     * Se resta solo para que la tarjeta "Productos" muestre su monto real.
     */
    if (is_array($resumen['ventas']['clases'] ?? null)) {
        foreach (array('efectivo', 'tarjeta', 'transferencia') as $metodoVisual) {
            $resumenProductosVisual[$metodoVisual] = max(
                0,
                round(
                    (float) ($resumenProductosVisual[$metodoVisual] ?? 0)
                    - (float) ($resumenClasesVisual[$metodoVisual] ?? 0),
                    2
                )
            );
        }

        $resumenProductosVisual['operaciones'] = max(
            0,
            (int) ($resumenProductosVisual['operaciones'] ?? 0)
            - (int) ($resumenClasesVisual['operaciones'] ?? 0)
        );
    }

    $totalProductosVisual = round(
        (float) ($resumenProductosVisual['efectivo'] ?? 0)
        + (float) ($resumenProductosVisual['tarjeta'] ?? 0)
        + (float) ($resumenProductosVisual['transferencia'] ?? 0),
        2
    );

    $totalMembresiasVisual = round(
        (float) ($resumen['membresias']['efectivo'] ?? 0)
        + (float) ($resumen['membresias']['tarjeta'] ?? 0)
        + (float) ($resumen['membresias']['transferencia'] ?? 0),
        2
    );

    $totalClasesVisual = round(
        (float) ($resumenClasesVisual['efectivo'] ?? 0)
        + (float) ($resumenClasesVisual['tarjeta'] ?? 0)
        + (float) ($resumenClasesVisual['transferencia'] ?? 0),
        2
    );
}

$cortesRecientes = array();
$totalCortes = 0;
$totalPaginasCortes = 1;
$errorHistorialCaja = null;
$paginaCortes = max(1, (int) (isset($_GET['pagina_cortes']) ? $_GET['pagina_cortes'] : 1));
$porPaginaCortes = 8;

try {
    $totalCortes = contarCortesCerradosPaginados(
        $conn,
        $usuarioId,
        $esAdministradorCaja,
        $sucursalId,
        $vistaGlobalCaja
    );
    $totalPaginasCortes = max(1, (int) ceil($totalCortes / $porPaginaCortes));
    $paginaCortes = min($paginaCortes, $totalPaginasCortes);
    $offsetCortes = ($paginaCortes - 1) * $porPaginaCortes;
    $cortesRecientes = listarCortesCerradosPaginados(
        $conn,
        $usuarioId,
        $esAdministradorCaja,
        $sucursalId,
        $vistaGlobalCaja,
        $porPaginaCortes,
        $offsetCortes
    );
} catch (Throwable $errorHistorial) {
    $errorHistorialCaja = $errorHistorial->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corte de Caja</title>
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
        referrerpolicy="no-referrer"
    >
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/corte_caja.css?v=<?= is_file(__DIR__ . '/css/corte_caja.css') ? filemtime(__DIR__ . '/css/corte_caja.css') : '1' ?>">
    <link rel="stylesheet" href="css/corte_caja_multisucursal.css?v=<?= is_file(__DIR__ . '/css/corte_caja_multisucursal.css') ? filemtime(__DIR__ . '/css/corte_caja_multisucursal.css') : '1' ?>">
    <link rel="stylesheet" href="css/corte_caja_clases.css?v=<?= is_file(__DIR__ . '/css/corte_caja_clases.css') ? filemtime(__DIR__ . '/css/corte_caja_clases.css') : '1' ?>">
</head>
<body>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<main class="main-content caja-page">
    <div class="caja-shell">
        <header class="caja-topbar">
            <div class="caja-heading">
                <h1>Corte de Caja</h1>
                <p>Administra la apertura, los movimientos y el cierre de cada turno.</p>
            </div>

            <div class="caja-topbar-context">
                <span class="caja-branch-badge <?= $vistaGlobalCaja ? 'global' : '' ?>">
                    <i class="fas <?= $vistaGlobalCaja ? 'fa-chart-pie' : 'fa-building' ?>"></i>
                    <?= hCaja(
                        $vistaGlobalCaja
                            ? 'Todas las sucursales'
                            : ($sucursalActiva['nombre'] ?? 'Sucursal')
                    ) ?>
                </span>

                <span class="caja-status <?= $vistaGlobalCaja ? 'global' : ($cajaAbierta ? 'abierta' : 'cerrada') ?>">
                    <span class="caja-status-dot"></span>
                    <?= $vistaGlobalCaja
                        ? 'Vista consolidada'
                        : ($cajaAbierta ? 'Caja abierta' : 'Sin caja abierta') ?>
                </span>
            </div>
        </header>

        <?php if ($vistaGlobalCaja): ?>
            <section class="caja-global-view caja-card">
                <span class="caja-global-view-icon">
                    <i class="fas fa-building-circle-check"></i>
                </span>

                <div>
                    <span class="caja-global-view-kicker">Consulta consolidada</span>
                    <h2>Cortes de todas las sucursales</h2>
                    <p>
                        Esta vista es únicamente informativa. Selecciona una sucursal
                        concreta desde el menú lateral para abrir, mover o cerrar una caja.
                    </p>
                </div>
            </section>
        <?php elseif (!$puedeOperarCajaSucursal): ?>
            <section class="caja-global-view caja-card is-warning">
                <span class="caja-global-view-icon">
                    <i class="fas fa-user-lock"></i>
                </span>

                <div>
                    <span class="caja-global-view-kicker">Operación restringida</span>
                    <h2>No puedes operar caja en esta sucursal</h2>
                    <p>
                        Puedes consultar el historial disponible, pero la apertura,
                        los movimientos y el cierre están deshabilitados para tu asignación.
                    </p>
                </div>
            </section>
        <?php elseif (!$cajaAbierta): ?>
            <section class="caja-open-layout">
                <article class="caja-card caja-open-panel">
                    <div class="caja-open-hero">
                        <h2><i class="fas fa-door-open"></i> Iniciar un nuevo turno</h2>
                        <p>
                            Desde la apertura se contabilizarán automáticamente las operaciones registradas por
                            <?= hCaja($usuarioNombre) ?>.
                        </p>
                    </div>

                    <form method="post" class="caja-form-body">
                        <input type="hidden" name="csrf_token" value="<?= hCaja($_SESSION['csrf_corte_caja']) ?>">
                        <input type="hidden" name="accion" value="abrir_caja">

                        <div class="caja-field">
                            <label for="monto_inicial">Fondo inicial en efectivo</label>
                            <input
                                type="number"
                                id="monto_inicial"
                                name="monto_inicial"
                                min="0"
                                step="0.50"
                                value="0.00"
                                required
                            >
                            <small class="caja-help">Puedes capturar cantidades cerradas o medios pesos, por ejemplo 500.00 o 500.50.</small>
                        </div>

                        <div class="caja-field">
                            <label for="observaciones_apertura">Observaciones de apertura</label>
                            <textarea
                                id="observaciones_apertura"
                                name="observaciones_apertura"
                                maxlength="1000"
                                placeholder="Opcional: estado del fondo, cambio disponible, responsable, etc."
                            ></textarea>
                        </div>

                        <button type="submit" class="caja-btn caja-btn-primary caja-btn-block">
                            <i class="fas fa-cash-register"></i>
                            Abrir caja
                        </button>
                    </form>
                </article>
            </section>
        <?php elseif ($errorConsultaCaja): ?>
            <section class="caja-error-box">
                <i class="fas fa-triangle-exclamation"></i>
                <div>
                    <strong>No fue posible calcular el corte de caja</strong>
                    <span><?= hCaja($errorConsultaCaja) ?></span>
                </div>
            </section>
        <?php else: ?>
            <?php if (!empty($resumen['advertencias'])): ?>
                <section class="caja-warning-box">
                    <i class="fas fa-circle-exclamation"></i>
                    <div>
                        <strong>Revisión necesaria</strong>
                        <span><?= hCaja(implode(' ', $resumen['advertencias'])) ?></span>
                    </div>
                </section>
            <?php endif; ?>

            <section class="caja-summary-grid">
                <article class="caja-card caja-summary-card caja-summary-cash">
                    <span class="caja-summary-icon"><i class="fas fa-money-bill-wave"></i></span>
                    <span class="caja-summary-label">Efectivo esperado</span>
                    <strong class="caja-summary-value"><?= dineroCaja($resumen['efectivo_esperado']) ?></strong>
                    <small class="caja-summary-note">Incluye fondo inicial y movimientos en efectivo.</small>
                </article>

                <article class="caja-card caja-summary-card caja-summary-net">
                    <span class="caja-summary-icon"><i class="fas fa-chart-line"></i></span>
                    <span class="caja-summary-label">Ingresos netos</span>
                    <strong class="caja-summary-value"><?= dineroCaja($resumen['total_neto']) ?></strong>
                    <small class="caja-summary-note">Productos + membresías + clases − devoluciones.</small>
                </article>

                <article class="caja-card caja-summary-card caja-summary-cardpay">
                    <span class="caja-summary-icon"><i class="fas fa-credit-card"></i></span>
                    <span class="caja-summary-label">Tarjeta neta</span>
                    <strong class="caja-summary-value"><?= dineroCaja($resumen['total_tarjeta_neto']) ?></strong>
                    <small class="caja-summary-note">No forma parte del efectivo físico.</small>
                </article>

                <article class="caja-card caja-summary-card caja-summary-transfer">
                    <span class="caja-summary-icon"><i class="fas fa-building-columns"></i></span>
                    <span class="caja-summary-label">Transferencias netas</span>
                    <strong class="caja-summary-value"><?= dineroCaja($resumen['total_transferencia_neto']) ?></strong>
                    <small class="caja-summary-note"><?= (int) $resumen['operaciones'] ?> operaciones detectadas.</small>
                </article>
            </section>

            <section class="caja-main-grid">
                <div>
                    <article class="caja-card caja-detail-card">
                        <div class="caja-card-head">
                            <h2><i class="fas fa-chart-pie"></i> Resumen del turno</h2>
                            <span class="caja-head-badge"><?= hCaja($cajaAbierta['folio']) ?></span>
                        </div>
                        <div class="caja-card-body">
                        <div class="caja-income-compact">
                            <div class="caja-income-table-wrap">
                                <table class="caja-income-table">
                                    <thead>
                                        <tr>
                                            <th>Concepto</th>
                                            <th>Efectivo</th>
                                            <th>Tarjeta</th>
                                            <th>Transferencia</th>
                                            <th>Operaciones</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr class="is-products">
                                            <td data-label="Concepto">
                                                <span class="caja-income-concept">
                                                    <i class="fas fa-cart-shopping"></i>
                                                    <span>
                                                        <strong>Productos</strong>
                                                        <small>Ventas del turno</small>
                                                    </span>
                                                </span>
                                            </td>
                                            <td data-label="Efectivo"><?= dineroCaja($resumenProductosVisual['efectivo']) ?></td>
                                            <td data-label="Tarjeta"><?= dineroCaja($resumenProductosVisual['tarjeta']) ?></td>
                                            <td data-label="Transferencia"><?= dineroCaja($resumenProductosVisual['transferencia']) ?></td>
                                            <td data-label="Operaciones"><?= (int) $resumenProductosVisual['operaciones'] ?></td>
                                            <td data-label="Total" class="caja-income-total"><?= dineroCaja($totalProductosVisual) ?></td>
                                        </tr>

                                        <tr class="is-memberships">
                                            <td data-label="Concepto">
                                                <span class="caja-income-concept">
                                                    <i class="fas fa-id-card"></i>
                                                    <span>
                                                        <strong>Membresías</strong>
                                                        <small>Pagos de planes</small>
                                                    </span>
                                                </span>
                                            </td>
                                            <td data-label="Efectivo"><?= dineroCaja($resumen['membresias']['efectivo']) ?></td>
                                            <td data-label="Tarjeta"><?= dineroCaja($resumen['membresias']['tarjeta']) ?></td>
                                            <td data-label="Transferencia"><?= dineroCaja($resumen['membresias']['transferencia']) ?></td>
                                            <td data-label="Operaciones"><?= (int) $resumen['membresias']['operaciones'] ?></td>
                                            <td data-label="Total" class="caja-income-total"><?= dineroCaja($totalMembresiasVisual) ?></td>
                                        </tr>

                                        <tr class="is-classes">
                                            <td data-label="Concepto">
                                                <span class="caja-income-concept">
                                                    <i class="fas fa-dumbbell"></i>
                                                    <span>
                                                        <strong>Clases</strong>
                                                        <small>Accesos individuales</small>
                                                    </span>
                                                </span>
                                            </td>
                                            <td data-label="Efectivo"><?= dineroCaja($resumenClasesVisual['efectivo']) ?></td>
                                            <td data-label="Tarjeta"><?= dineroCaja($resumenClasesVisual['tarjeta']) ?></td>
                                            <td data-label="Transferencia"><?= dineroCaja($resumenClasesVisual['transferencia']) ?></td>
                                            <td data-label="Operaciones"><?= (int) $resumenClasesVisual['operaciones'] ?></td>
                                            <td data-label="Total" class="caja-income-total"><?= dineroCaja($totalClasesVisual) ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="caja-adjustments-compact">
                                <span><small>Fondo inicial</small><strong><?= dineroCaja($resumen['monto_inicial']) ?></strong></span>
                                <span><small>Entradas manuales</small><strong><?= dineroCaja($resumen['manuales']['entradas']) ?></strong></span>
                                <span><small>Salidas manuales</small><strong><?= dineroCaja($resumen['manuales']['salidas']) ?></strong></span>
                                <span><small>Devoluciones</small><strong><?= dineroCaja($resumen['total_devoluciones']) ?></strong></span>
                            </div>
                        </div>
                        </div>
                    </article>

                    <article class="caja-card caja-table-card caja-spaced-card">
                        <div class="caja-card-head">
                            <h2><i class="fas fa-list"></i> Operaciones del turno</h2>
                            <span class="caja-head-badge"><?= (int) $totalOperaciones ?> registros</span>
                        </div>
                        <div class="caja-card-body caja-table-body">

                        <?php if (!$operaciones): ?>
                            <div class="caja-empty">Todavía no hay operaciones registradas durante este turno.</div>
                        <?php else: ?>
                            <div class="caja-table-wrap">
                                <table class="caja-table">
                                    <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Origen</th>
                                        <th>Concepto</th>
                                        <th>Método</th>
                                        <th>Movimiento</th>
                                        <th>Monto</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($operaciones as $operacion): ?>
                                        <tr>
                                            <td data-label="Fecha"><?= hCaja(date('d/m/Y H:i', strtotime((string) $operacion['fecha']))) ?></td>
                                            <td data-label="Origen"><span class="caja-pill"><?= hCaja($operacion['origen']) ?></span></td>
                                            <td data-label="Concepto"><?= hCaja($operacion['concepto']) ?></td>
                                            <td data-label="Método"><?= hCaja(ucfirst((string) $operacion['metodo_pago'])) ?></td>
                                            <td data-label="Movimiento"><?= $operacion['naturaleza'] === 'entrada' ? 'Entrada' : 'Salida' ?></td>
                                            <td data-label="Monto" class="caja-amount <?= hCaja($operacion['naturaleza']) ?>">
                                                <?= $operacion['naturaleza'] === 'entrada' ? '+' : '−' ?><?= dineroCaja($operacion['monto']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($totalPaginasOperaciones > 1): ?>
                                <div class="caja-pagination-wrap">
                                    <span class="caja-pagination-info">
                                        Página <?= (int) $paginaOperaciones ?> de <?= (int) $totalPaginasOperaciones ?>
                                    </span>
                                    <nav class="caja-pagination" aria-label="Paginación de operaciones">
                                        <a
                                            href="<?= hCaja(urlCorteCaja(array('pagina_operaciones' => max(1, $paginaOperaciones - 1)))) ?>"
                                            class="caja-page-link caja-page-arrow <?= $paginaOperaciones <= 1 ? 'disabled' : '' ?>"
                                            aria-label="Página anterior"
                                        ><i class="fas fa-chevron-left"></i></a>

                                        <?php foreach (paginasCorteCaja($paginaOperaciones, $totalPaginasOperaciones) as $pagina): ?>
                                            <?php if ($pagina === '...'): ?>
                                                <span class="caja-page-ellipsis">…</span>
                                            <?php else: ?>
                                                <a
                                                    href="<?= hCaja(urlCorteCaja(array('pagina_operaciones' => $pagina))) ?>"
                                                    class="caja-page-link <?= (int) $pagina === $paginaOperaciones ? 'active' : '' ?>"
                                                    <?= (int) $pagina === $paginaOperaciones ? 'aria-current="page"' : '' ?>
                                                ><?= (int) $pagina ?></a>
                                            <?php endif; ?>
                                        <?php endforeach; ?>

                                        <a
                                            href="<?= hCaja(urlCorteCaja(array('pagina_operaciones' => min($totalPaginasOperaciones, $paginaOperaciones + 1)))) ?>"
                                            class="caja-page-link caja-page-arrow <?= $paginaOperaciones >= $totalPaginasOperaciones ? 'disabled' : '' ?>"
                                            aria-label="Página siguiente"
                                        ><i class="fas fa-chevron-right"></i></a>
                                    </nav>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        </div>
                    </article>
                </div>

                <aside class="caja-side-stack">
                    <article class="caja-card caja-side-card">
                        <div class="caja-card-head caja-card-head-compact">
                            <h2><i class="fas fa-right-left"></i> Movimiento manual</h2>
                        </div>
                        <div class="caja-card-body caja-side-body">
                        <form method="post" id="formMovimiento">
                            <input type="hidden" name="csrf_token" value="<?= hCaja($_SESSION['csrf_corte_caja']) ?>">
                            <input type="hidden" name="accion" value="movimiento_manual">

                            <div class="caja-field">
                                <label for="tipo_movimiento">Tipo</label>
                                <select id="tipo_movimiento" name="tipo_movimiento" required>
                                    <option value="entrada">Entrada de efectivo</option>
                                    <option value="salida">Salida de efectivo</option>
                                </select>
                            </div>

                            <div class="caja-field">
                                <label for="categoria">Categoría</label>
                                <select id="categoria" name="categoria" required></select>
                            </div>

                            <div class="caja-field">
                                <label for="monto">Monto</label>
                                <input type="number" id="monto" name="monto" min="0.50" step="0.50" required>
                            </div>

                            <div class="caja-field">
                                <label for="concepto">Concepto</label>
                                <textarea id="concepto" name="concepto" maxlength="255" required placeholder="Describe claramente el motivo."></textarea>
                            </div>

                            <button type="submit" class="caja-btn caja-btn-primary caja-btn-block">
                                <i class="fas fa-floppy-disk"></i>
                                Guardar movimiento
                            </button>
                        </form>
                        </div>
                    </article>

                    <article class="caja-card caja-side-card caja-close-card">
                        <div class="caja-card-head caja-card-head-compact">
                            <h2><i class="fas fa-lock"></i> Cerrar caja</h2>
                        </div>
                        <div class="caja-card-body caja-side-body">
                        <form method="post" id="formCerrarCaja">
                            <input type="hidden" name="csrf_token" value="<?= hCaja($_SESSION['csrf_corte_caja']) ?>">
                            <input type="hidden" name="accion" value="cerrar_caja">

                            <div class="caja-field">
                                <label for="efectivo_contado">Efectivo contado</label>
                                <input
                                    type="number"
                                    id="efectivo_contado"
                                    name="efectivo_contado"
                                    min="0"
                                    step="0.50"
                                    required
                                    data-esperado="<?= hCaja($resumen['efectivo_esperado']) ?>"
                                >
                                <small class="caja-help">Cuenta físicamente el dinero disponible en el cajón.</small>
                            </div>

                            <div class="caja-difference-preview" id="differencePreview">
                                Esperado: <strong><?= dineroCaja($resumen['efectivo_esperado']) ?></strong><br>
                                Diferencia: <strong>—</strong>
                            </div>

                            <div class="caja-field">
                                <label for="observaciones_cierre">Observaciones</label>
                                <textarea id="observaciones_cierre" name="observaciones_cierre" maxlength="1500" placeholder="Opcional: explica faltantes, sobrantes o incidencias."></textarea>
                            </div>

                            <button type="submit" class="caja-btn caja-btn-danger caja-btn-block">
                                <i class="fas fa-lock"></i>
                                Realizar corte y cerrar
                            </button>
                        </form>
                        </div>
                    </article>
                </aside>
            </section>
        <?php endif; ?>

        <section class="caja-card caja-history-card">
            <div class="caja-card-head caja-history-head">
                <div>
                    <h2><i class="fas fa-box-archive"></i> Historial de cortes</h2>
                    <p><?= hCaja($vistaGlobalCaja ? 'Consulta consolidada por sucursal.' : 'Cajas cerradas de la sucursal seleccionada.') ?></p>
                </div>
                <span class="caja-head-badge"><?= (int) $totalCortes ?> cortes</span>
            </div>
            <div class="caja-card-body caja-history-body">

            <?php if ($errorHistorialCaja): ?>
                <div class="caja-empty caja-empty-error">
                    <i class="fas fa-triangle-exclamation"></i>
                    <?= hCaja($errorHistorialCaja) ?>
                </div>
            <?php elseif (!$cortesRecientes): ?>
                <div class="caja-empty">Aún no existen cortes cerrados.</div>
            <?php else: ?>
                <div class="caja-history-table-wrap">
                    <table class="caja-history-table">
                        <thead>
                        <tr>
                            <th>Folio</th>
                            <th>Sucursal</th>
                            <th>Responsable</th>
                            <th>Apertura</th>
                            <th>Cierre</th>
                            <th>Esperado</th>
                            <th>Contado</th>
                            <th>Diferencia</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cortesRecientes as $corte): ?>
                            <?php
                            $diferenciaCorte = round((float) ($corte['diferencia'] ?? 0), 2);

                            if (abs($diferenciaCorte) < 0.005) {
                                $claseDiferencia = 'neutral';
                            } elseif ($diferenciaCorte > 0) {
                                $claseDiferencia = 'positive';
                            } else {
                                $claseDiferencia = 'negative';
                            }
                            ?>
                            <tr>
                                <td data-label="Folio">
                                    <span class="caja-history-folio">
                                        <?= hCaja($corte['folio']) ?>
                                    </span>
                                </td>

                                <td data-label="Sucursal">
                                    <span class="caja-branch-table-pill">
                                        <i class="fas fa-building"></i>
                                        <?= hCaja($corte['sucursal_nombre']) ?>
                                        <small><?= hCaja($corte['sucursal_clave']) ?></small>
                                    </span>
                                </td>

                                <td data-label="Responsable">
                                    <span class="caja-history-owner">
                                        <?= hCaja($corte['usuario_apertura']) ?>
                                    </span>
                                </td>

                                <td data-label="Apertura" class="caja-history-date">
                                    <?= hCaja(date('d/m/Y H:i', strtotime((string) $corte['fecha_apertura']))) ?>
                                </td>

                                <td data-label="Cierre" class="caja-history-date">
                                    <?= hCaja(date('d/m/Y H:i', strtotime((string) $corte['fecha_cierre']))) ?>
                                </td>

                                <td data-label="Esperado" class="caja-history-money">
                                    <?= dineroCaja($corte['efectivo_esperado']) ?>
                                </td>

                                <td data-label="Contado" class="caja-history-money">
                                    <?= dineroCaja($corte['efectivo_contado']) ?>
                                </td>

                                <td data-label="Diferencia">
                                    <span class="caja-history-difference <?= hCaja($claseDiferencia) ?>">
                                        <?= dineroCaja($diferenciaCorte) ?>
                                    </span>
                                </td>

                                <td data-label="Estado">
                                    <span class="caja-history-status">
                                        <i class="fas fa-lock"></i>
                                        Cerrada
                                    </span>
                                </td>

                                <td data-label="Acciones">
                                    <a
                                        class="caja-btn caja-btn-soft caja-history-action"
                                        href="corte_caja_detalle.php?id=<?= (int) $corte['id'] ?>&vista=<?= $vistaGlobalCaja ? 'global' : 'sucursal' ?>"
                                    >
                                        Ver detalle
                                        <i class="fas fa-arrow-right"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPaginasCortes > 1): ?>
                    <div class="caja-pagination-wrap caja-history-pagination">
                        <span class="caja-pagination-info">
                            Página <?= (int) $paginaCortes ?> de <?= (int) $totalPaginasCortes ?>
                        </span>
                        <nav class="caja-pagination" aria-label="Paginación del historial de cortes">
                            <a
                                href="<?= hCaja(urlCorteCaja(array('pagina_cortes' => max(1, $paginaCortes - 1)))) ?>"
                                class="caja-page-link caja-page-arrow <?= $paginaCortes <= 1 ? 'disabled' : '' ?>"
                                aria-label="Página anterior"
                            ><i class="fas fa-chevron-left"></i></a>

                            <?php foreach (paginasCorteCaja($paginaCortes, $totalPaginasCortes) as $pagina): ?>
                                <?php if ($pagina === '...'): ?>
                                    <span class="caja-page-ellipsis">…</span>
                                <?php else: ?>
                                    <a
                                        href="<?= hCaja(urlCorteCaja(array('pagina_cortes' => $pagina))) ?>"
                                        class="caja-page-link <?= (int) $pagina === $paginaCortes ? 'active' : '' ?>"
                                        <?= (int) $pagina === $paginaCortes ? 'aria-current="page"' : '' ?>
                                    ><?= (int) $pagina ?></a>
                                <?php endif; ?>
                            <?php endforeach; ?>

                            <a
                                href="<?= hCaja(urlCorteCaja(array('pagina_cortes' => min($totalPaginasCortes, $paginaCortes + 1)))) ?>"
                                class="caja-page-link caja-page-arrow <?= $paginaCortes >= $totalPaginasCortes ? 'disabled' : '' ?>"
                                aria-label="Página siguiente"
                            ><i class="fas fa-chevron-right"></i></a>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<script>
(function () {
    const flash = <?= json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    if (flash) {
        const config = {
            icon: flash.tipo || 'info',
            title: flash.titulo || 'Corte de caja',
            text: flash.mensaje || '',
            confirmButtonText: 'Entendido',
            confirmButtonColor: '#244292'
        };

        if (window.Swal) {
            Swal.fire(config);
        } else {
            alert((config.title ? config.title + '\n' : '') + config.text);
        }
    }

    const tipoMovimiento = document.getElementById('tipo_movimiento');
    const categoria = document.getElementById('categoria');

    const opciones = {
        entrada: [
            ['fondo_adicional', 'Fondo adicional'],
            ['ingreso_vario', 'Ingreso diverso'],
            ['ajuste', 'Ajuste de entrada']
        ],
        salida: [
            ['retiro', 'Retiro de efectivo'],
            ['gasto', 'Gasto operativo'],
            ['devolucion_manual', 'Devolución manual'],
            ['ajuste', 'Ajuste de salida']
        ]
    };

    function actualizarCategorias() {
        if (!tipoMovimiento || !categoria) return;
        categoria.innerHTML = '';

        (opciones[tipoMovimiento.value] || []).forEach(([value, label]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            categoria.appendChild(option);
        });
    }

    if (tipoMovimiento) {
        tipoMovimiento.addEventListener('change', actualizarCategorias);
        actualizarCategorias();
    }

    const efectivoContado = document.getElementById('efectivo_contado');
    const differencePreview = document.getElementById('differencePreview');

    function formatoDinero(valor) {
        return new Intl.NumberFormat('es-MX', {
            style: 'currency',
            currency: 'MXN'
        }).format(valor || 0);
    }

    function actualizarDiferencia() {
        if (!efectivoContado || !differencePreview) return;

        const esperado = Number(efectivoContado.dataset.esperado || 0);
        const contado = Number(efectivoContado.value);
        const diferencia = Number.isFinite(contado) ? contado - esperado : null;

        differencePreview.innerHTML = `
            Esperado: <strong>${formatoDinero(esperado)}</strong><br>
            Diferencia: <strong>${diferencia === null ? '—' : formatoDinero(diferencia)}</strong>
        `;
    }

    if (efectivoContado) {
        efectivoContado.addEventListener('input', actualizarDiferencia);
    }

    const formCerrar = document.getElementById('formCerrarCaja');
    if (formCerrar) {
        formCerrar.addEventListener('submit', function (event) {
            if (!window.Swal) return;

            event.preventDefault();
            const esperado = Number(efectivoContado?.dataset.esperado || 0);
            const contado = Number(efectivoContado?.value || 0);
            const diferencia = contado - esperado;

            Swal.fire({
                icon: 'warning',
                title: '¿Cerrar la caja?',
                html: `
                    <div style="text-align:left;line-height:1.7">
                        <div><strong>Efectivo esperado:</strong> ${formatoDinero(esperado)}</div>
                        <div><strong>Efectivo contado:</strong> ${formatoDinero(contado)}</div>
                        <div><strong>Diferencia:</strong> ${formatoDinero(diferencia)}</div>
                        <p style="margin:12px 0 0;color:#667085">Después del cierre, los totales quedarán congelados para auditoría.</p>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Sí, cerrar caja',
                cancelButtonText: 'Revisar',
                confirmButtonColor: '#c2414b',
                cancelButtonColor: '#64748b',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    formCerrar.submit();
                }
            });
        });
    }
})();
</script>
</body>
</html>