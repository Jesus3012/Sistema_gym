<?php
require_once __DIR__ . '/caja_clases_helper.php';
// VERSION PHP 7 - TOTAL ORIGINAL Y DEVOLUCIONES CORREGIDOS - 2026-07-12
// Archivo: includes/caja_helper.php
// Corte de caja calculado directamente desde ventas, pagos y ventas_modificaciones.


/**
 * Convierte un valor monetario a float con dos decimales.
 */
function cajaMonto($valor) {
    return round((float) ($valor ?? 0), 2);
}

/**
 * Devuelve las columnas disponibles de una tabla de la base actual.
 */
function cajaColumnasTabla($conn, $tabla) {
    static $cache = array();

    if (isset($cache[$tabla])) {
        return $cache[$tabla];
    }

    $sql = "SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $cache[$tabla] = array();
        return $cache[$tabla];
    }

    $stmt->bind_param('s', $tabla);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $columnas = array();

    while ($fila = $resultado->fetch_assoc()) {
        $nombre = (string) $fila['COLUMN_NAME'];
        $columnas[strtolower($nombre)] = $nombre;
    }

    $stmt->close();
    $cache[$tabla] = $columnas;
    return $columnas;
}

/**
 * Protege un identificador SQL obtenido desde INFORMATION_SCHEMA.
 */
function cajaIdentificador($identificador) {
    return '`' . str_replace('`', '``', (string) $identificador) . '`';
}

/**
 * Detecta qué tabla puede utilizarse como fuente de pagos de membresías.
 * Se prefiere pagos; historial_pagos solo funciona como respaldo.
 */
function cajaResolverFuenteMembresias($conn) {
    static $resuelto = false;
    static $fuente = null;

    if ($resuelto) {
        return $fuente;
    }

    $resuelto = true;
    $candidatas = array('pagos', 'historial_pagos');

    foreach ($candidatas as $tabla) {
        $columnas = cajaColumnasTabla($conn, $tabla);
        if (empty($columnas)) {
            continue;
        }

        if (!isset($columnas['id'], $columnas['monto'], $columnas['metodo_pago'], $columnas['usuario_id'])) {
            continue;
        }

        $columnaFecha = null;
        if (isset($columnas['fecha_registro'])) {
            $columnaFecha = $columnas['fecha_registro'];
        } elseif (isset($columnas['fecha_pago'])) {
            $columnaFecha = $columnas['fecha_pago'];
        }

        if ($columnaFecha === null) {
            continue;
        }

        $fuente = array(
            'tabla' => $tabla,
            'id' => $columnas['id'],
            'monto' => $columnas['monto'],
            'metodo_pago' => $columnas['metodo_pago'],
            'usuario_id' => $columnas['usuario_id'],
            'sucursal_id' => isset($columnas['sucursal_id']) ? $columnas['sucursal_id'] : null,
            'fecha' => $columnaFecha,
            'estado' => isset($columnas['estado']) ? $columnas['estado'] : null,
            'cliente_id' => isset($columnas['cliente_id']) ? $columnas['cliente_id'] : null,
        );
        break;
    }

    return $fuente;
}

/**
 * Vincula parámetros construidos dinámicamente a una sentencia mysqli.
 */
function cajaEjecutarConParametros($stmt, $tipos, $valores) {
    $referencias = array();
    $referencias[] = $tipos;

    foreach ($valores as $indice => $valor) {
        $valores[$indice] = $valor;
        $referencias[] = &$valores[$indice];
    }

    $vinculado = call_user_func_array(array($stmt, 'bind_param'), $referencias);
    if (!$vinculado) {
        return false;
    }

    return $stmt->execute();
}

/**
 * Devuelve la caja abierta del usuario, si existe.
 */
function obtenerCajaAbierta(
    $conn,
    $usuarioId,
    $sucursalId = null,
    $forUpdate = false
) {
    /*
     * Compatibilidad con llamadas antiguas:
     * obtenerCajaAbierta($conn, $usuarioId, true)
     */
    if (is_bool($sucursalId)) {
        $forUpdate = $sucursalId;
        $sucursalId = null;
    }

    if ($sucursalId === null) {
        $sucursalId = (int) ($_SESSION['sucursal_id'] ?? 0);
    }

    $sucursalId = (int) $sucursalId;

    if ($sucursalId <= 0) {
        return null;
    }

    $sql = "SELECT c.*,
                   u.nombre AS usuario_apertura,
                   s.nombre AS sucursal_nombre,
                   s.clave AS sucursal_clave,
                   s.es_matriz AS sucursal_es_matriz
            FROM cajas c
            INNER JOIN usuarios u
                ON u.id = c.usuario_apertura_id
            INNER JOIN sucursales s
                ON s.id = c.sucursal_id
            WHERE c.usuario_apertura_id = ?
              AND c.sucursal_id = ?
              AND c.estado = 'abierta'
            ORDER BY c.id DESC
            LIMIT 1";

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'No se pudo preparar la consulta de caja abierta.'
        );
    }

    $stmt->bind_param('ii', $usuarioId, $sucursalId);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $caja = $resultado->fetch_assoc();
    $stmt->close();

    return $caja ?: null;
}

/**
 * Obtiene una caja por ID, incluyendo nombres de apertura y cierre.
 */
function obtenerCajaPorId($conn, $cajaId) {
    $sql = "SELECT c.*,
                   ua.nombre AS usuario_apertura,
                   uc.nombre AS usuario_cierre,
                   s.nombre AS sucursal_nombre,
                   s.clave AS sucursal_clave,
                   s.telefono AS sucursal_telefono,
                   s.email AS sucursal_email,
                   s.direccion AS sucursal_direccion,
                   s.logo AS sucursal_logo,
                   s.zona_horaria AS sucursal_zona_horaria,
                   s.es_matriz AS sucursal_es_matriz
            FROM cajas c
            INNER JOIN usuarios ua
                ON ua.id = c.usuario_apertura_id
            LEFT JOIN usuarios uc
                ON uc.id = c.usuario_cierre_id
            INNER JOIN sucursales s
                ON s.id = c.sucursal_id
            WHERE c.id = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'No se pudo preparar la consulta del corte.'
        );
    }

    $stmt->bind_param('i', $cajaId);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $caja = $resultado->fetch_assoc();
    $stmt->close();

    return $caja ?: null;
}


/**
 * Obtiene los ingresos brutos de ventas de productos dentro del turno.
 *
 * Regla importante:
 * - La venta se considera si fue registrada por el responsable de la caja.
 * - También se considera si fue modificada/devolvida por ese responsable
 *   durante el mismo turno y la venta original pertenece al mismo intervalo.
 *
 * Esto evita restar una devolución sin incluir primero la venta que la originó.
 */
function resumenVentasCaja(
    $conn,
    $sucursalId,
    $usuarioId,
    $fechaInicio,
    $fechaFin
) {
    /*
     * ventas.total puede disminuir después de una devolución parcial.
     * El total bruto se toma primero de tickets_venta.total, que conserva
     * el importe original emitido. Si no existe ticket, se reconstruye.
     */
    $sql = "SELECT
                COUNT(*) AS operaciones,
                COALESCE(SUM(CASE
                    WHEN ventas_turno.metodo_pago = 'efectivo'
                        THEN ventas_turno.monto_bruto
                    ELSE 0
                END), 0) AS efectivo,
                COALESCE(SUM(CASE
                    WHEN ventas_turno.metodo_pago = 'tarjeta'
                        THEN ventas_turno.monto_bruto
                    ELSE 0
                END), 0) AS tarjeta,
                COALESCE(SUM(CASE
                    WHEN ventas_turno.metodo_pago = 'transferencia'
                        THEN ventas_turno.monto_bruto
                    ELSE 0
                END), 0) AS transferencia
            FROM (
                SELECT
                    v.id,
                    v.metodo_pago,
                    COALESCE(
                        tv.total_original,
                        CASE
                            WHEN v.estado = 'cancelada'
                                THEN GREATEST(
                                    v.total,
                                    COALESCE(dev.total_devuelto, 0)
                                )
                            ELSE v.total + COALESCE(dev.total_devuelto, 0)
                        END
                    ) AS monto_bruto
                FROM ventas v
                LEFT JOIN (
                    SELECT venta_id, MAX(total) AS total_original
                    FROM tickets_venta
                    GROUP BY venta_id
                ) tv ON tv.venta_id = v.id
                LEFT JOIN (
                    SELECT
                        venta_id,
                        SUM(
                            CASE
                                WHEN monto_devuelto IS NOT NULL
                                 AND monto_devuelto > 0
                                    THEN monto_devuelto
                                ELSE 0
                            END
                        ) AS total_devuelto
                    FROM ventas_modificaciones
                    WHERE tipo_modificacion IN (
                        'cancelacion',
                        'devolucion_parcial'
                    )
                      AND fecha_modificacion <= ?
                    GROUP BY venta_id
                ) dev ON dev.venta_id = v.id
                WHERE v.fecha_venta >= ?
                  AND v.fecha_venta <= ?
                  AND v.sucursal_id = ?
                  AND v.estado <> 'pendiente'
                  AND (
                        v.usuario_id = ?
                        OR EXISTS (
                            SELECT 1
                            FROM ventas_modificaciones vm_rel
                            WHERE vm_rel.venta_id = v.id
                              AND vm_rel.usuario_id = ?
                              AND vm_rel.fecha_modificacion >= ?
                              AND vm_rel.fecha_modificacion <= ?
                              AND vm_rel.tipo_modificacion IN (
                                  'cancelacion',
                                  'devolucion_parcial'
                              )
                        )
                  )
            ) ventas_turno";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'No se pudo consultar las ventas del corte. Detalle MySQL: ' .
            $conn->error
        );
    }

    $stmt->bind_param(
        'sssiiiss',
        $fechaFin,
        $fechaInicio,
        $fechaFin,
        $sucursalId,
        $usuarioId,
        $usuarioId,
        $fechaInicio,
        $fechaFin
    );

    if (!$stmt->execute()) {
        $detalle = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'No se pudo ejecutar la consulta de ventas. Detalle MySQL: ' .
            $detalle
        );
    }

    $fila = $stmt->get_result()->fetch_assoc() ?: array();
    $stmt->close();

    return array(
        'operaciones' => (int) ($fila['operaciones'] ?? 0),
        'efectivo' => cajaMonto($fila['efectivo'] ?? 0),
        'tarjeta' => cajaMonto($fila['tarjeta'] ?? 0),
        'transferencia' => cajaMonto($fila['transferencia'] ?? 0),
    );
}

/**
 * Obtiene cobros de membresías directamente desde pagos.
 * historial_pagos no se consulta para evitar duplicar el mismo ingreso.
 */
function resumenMembresiasCaja(
    $conn,
    $sucursalId,
    $usuarioId,
    $fechaInicio,
    $fechaFin
) {
    $fuente = cajaResolverFuenteMembresias($conn);

    if ($fuente === null || empty($fuente['sucursal_id'])) {
        return array(
            'operaciones' => 0,
            'efectivo' => 0.00,
            'tarjeta' => 0.00,
            'transferencia' => 0.00,
            'fuente' => null,
            'advertencia' =>
                'No se encontró una fuente de membresías compatible con sucursal_id.',
        );
    }

    $tabla = cajaIdentificador($fuente['tabla']);
    $colMonto = cajaIdentificador($fuente['monto']);
    $colMetodo = cajaIdentificador($fuente['metodo_pago']);
    $colUsuario = cajaIdentificador($fuente['usuario_id']);
    $colSucursal = cajaIdentificador($fuente['sucursal_id']);
    $colFecha = cajaIdentificador($fuente['fecha']);

    $condicionEstado = '';

    if (!empty($fuente['estado'])) {
        $colEstado = cajaIdentificador($fuente['estado']);
        $condicionEstado = " AND {$colEstado} = 'completado'";
    }

    $sql = "SELECT
                COUNT(*) AS operaciones,
                COALESCE(SUM(CASE
                    WHEN {$colMetodo} = 'efectivo'
                        THEN {$colMonto}
                    ELSE 0
                END), 0) AS efectivo,
                COALESCE(SUM(CASE
                    WHEN {$colMetodo} = 'tarjeta'
                        THEN {$colMonto}
                    ELSE 0
                END), 0) AS tarjeta,
                COALESCE(SUM(CASE
                    WHEN {$colMetodo} = 'transferencia'
                        THEN {$colMonto}
                    ELSE 0
                END), 0) AS transferencia
            FROM {$tabla}
            WHERE {$colUsuario} = ?
              AND {$colSucursal} = ?
              AND {$colFecha} >= ?
              AND {$colFecha} <= ?
              {$condicionEstado}";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'No se pudo consultar los pagos de membresías. Detalle MySQL: ' .
            $conn->error
        );
    }

    $stmt->bind_param(
        'iiss',
        $usuarioId,
        $sucursalId,
        $fechaInicio,
        $fechaFin
    );

    if (!$stmt->execute()) {
        $detalle = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'No se pudo ejecutar la consulta de membresías. Detalle MySQL: ' .
            $detalle
        );
    }

    $fila = $stmt->get_result()->fetch_assoc() ?: array();
    $stmt->close();

    return array(
        'operaciones' => (int) ($fila['operaciones'] ?? 0),
        'efectivo' => cajaMonto($fila['efectivo'] ?? 0),
        'tarjeta' => cajaMonto($fila['tarjeta'] ?? 0),
        'transferencia' => cajaMonto($fila['transferencia'] ?? 0),
        'fuente' => $fuente['tabla'],
        'advertencia' => null,
    );
}


/**
 * Obtiene devoluciones asociadas únicamente a ventas que pertenecen al turno.
 *
 * Una devolución ya no puede disminuir la caja si su venta original no está
 * dentro del mismo intervalo. De esta forma nunca se resta dinero "huérfano".
 */
function resumenDevolucionesCaja(
    $conn,
    $sucursalId,
    $usuarioId,
    $fechaInicio,
    $fechaFin
) {
    $montoSql = "CASE
                    WHEN vm.monto_devuelto IS NOT NULL
                     AND vm.monto_devuelto > 0
                        THEN vm.monto_devuelto
                    WHEN vm.tipo_modificacion = 'cancelacion'
                        THEN COALESCE(tv.total_original, v.total)
                    ELSE 0
                 END";

    $sql = "SELECT
                COUNT(*) AS operaciones,
                COALESCE(SUM(CASE
                    WHEN v.metodo_pago = 'efectivo'
                        THEN {$montoSql}
                    ELSE 0
                END), 0) AS efectivo,
                COALESCE(SUM(CASE
                    WHEN v.metodo_pago = 'tarjeta'
                        THEN {$montoSql}
                    ELSE 0
                END), 0) AS tarjeta,
                COALESCE(SUM(CASE
                    WHEN v.metodo_pago = 'transferencia'
                        THEN {$montoSql}
                    ELSE 0
                END), 0) AS transferencia
            FROM ventas_modificaciones vm
            INNER JOIN ventas v
                ON v.id = vm.venta_id
            LEFT JOIN (
                SELECT venta_id, MAX(total) AS total_original
                FROM tickets_venta
                GROUP BY venta_id
            ) tv ON tv.venta_id = v.id
            WHERE vm.fecha_modificacion >= ?
              AND vm.fecha_modificacion <= ?
              AND v.sucursal_id = ?
              AND v.estado <> 'pendiente'
              AND vm.tipo_modificacion IN (
                  'cancelacion',
                  'devolucion_parcial'
              )
              AND (
                    v.usuario_id = ?
                    OR vm.usuario_id = ?
              )";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'No se pudo consultar las devoluciones del corte. Detalle MySQL: ' .
            $conn->error
        );
    }

    $stmt->bind_param(
        'ssiii',
        $fechaInicio,
        $fechaFin,
        $sucursalId,
        $usuarioId,
        $usuarioId
    );

    if (!$stmt->execute()) {
        $detalle = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'No se pudo ejecutar la consulta de devoluciones. Detalle MySQL: ' .
            $detalle
        );
    }

    $fila = $stmt->get_result()->fetch_assoc() ?: array();
    $stmt->close();

    return array(
        'operaciones' => (int) ($fila['operaciones'] ?? 0),
        'efectivo' => cajaMonto($fila['efectivo'] ?? 0),
        'tarjeta' => cajaMonto($fila['tarjeta'] ?? 0),
        'transferencia' => cajaMonto($fila['transferencia'] ?? 0),
    );
}

/**
 * Obtiene entradas y salidas manuales capturadas para una caja específica.
 */
function resumenMovimientosManuales($conn, $cajaId) {
    $sql = "SELECT
                COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN monto ELSE 0 END), 0) AS entradas,
                COALESCE(SUM(CASE WHEN tipo = 'salida' THEN monto ELSE 0 END), 0) AS salidas,
                COUNT(*) AS operaciones
            FROM caja_movimientos
            WHERE caja_id = ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo consultar los movimientos manuales.');
    }

    $stmt->bind_param('i', $cajaId);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return [
        'entradas' => cajaMonto($fila['entradas'] ?? 0),
        'salidas' => cajaMonto($fila['salidas'] ?? 0),
        'operaciones' => (int) ($fila['operaciones'] ?? 0),
    ];
}

/**
 * Calcula el corte completo directamente desde la base de datos.
 */
function calcularResumenCaja(
    $conn,
    $caja,
    $fechaFin = null
) {
    $fechaInicio = (string) $caja['fecha_apertura'];
    $fechaFin = $fechaFin
        ?? (!empty($caja['fecha_cierre'])
            ? (string) $caja['fecha_cierre']
            : date('Y-m-d H:i:s'));

    $usuarioId = (int) $caja['usuario_apertura_id'];
    $sucursalId = (int) ($caja['sucursal_id'] ?? 0);
    $cajaId = (int) $caja['id'];

    if ($sucursalId <= 0) {
        throw new RuntimeException(
            'El corte no tiene una sucursal válida asignada.'
        );
    }

    $ventas = resumenVentasCaja(
        $conn,
        $sucursalId,
        $usuarioId,
        $fechaInicio,
        $fechaFin
    );

    $membresias = resumenMembresiasCaja(
        $conn,
        $sucursalId,
        $usuarioId,
        $fechaInicio,
        $fechaFin
    );

    $clases = resumenClasesCaja(
        $conn,
        $sucursalId,
        $usuarioId,
        $fechaInicio,
        $fechaFin
    );

    /*
     * Los accesos pagados a clases se integran en ventas para conservar
     * la estructura actual de cajas y del corte existente.
     */
    $ventas = integrarClasesEnVentasCaja($ventas, $clases);

    $devoluciones = resumenDevolucionesCaja(
        $conn,
        $sucursalId,
        $usuarioId,
        $fechaInicio,
        $fechaFin
    );

    $manuales = resumenMovimientosManuales(
        $conn,
        $cajaId
    );

    $montoInicial = cajaMonto($caja['monto_inicial'] ?? 0);

    $totalVentas =
        $ventas['efectivo'] +
        $ventas['tarjeta'] +
        $ventas['transferencia'];

    $totalMembresias =
        $membresias['efectivo'] +
        $membresias['tarjeta'] +
        $membresias['transferencia'];

    $totalDevoluciones =
        $devoluciones['efectivo'] +
        $devoluciones['tarjeta'] +
        $devoluciones['transferencia'];

    $totalBruto = $totalVentas + $totalMembresias;
    $totalNeto = $totalBruto - $totalDevoluciones;

    $efectivoEsperado =
        $montoInicial +
        $ventas['efectivo'] +
        $membresias['efectivo'] +
        $manuales['entradas'] -
        $manuales['salidas'] -
        $devoluciones['efectivo'];

    $advertencias = array();

    if (!empty($membresias['advertencia'])) {
        $advertencias[] = $membresias['advertencia'];
    }

    return array(
        'sucursal_id' => $sucursalId,
        'fecha_inicio' => $fechaInicio,
        'fecha_fin' => $fechaFin,
        'monto_inicial' => $montoInicial,
        'ventas' => $ventas,
        'membresias' => $membresias,
        'devoluciones' => $devoluciones,
        'manuales' => $manuales,
        'total_ventas' => cajaMonto($totalVentas),
        'total_membresias' => cajaMonto($totalMembresias),
        'total_bruto' => cajaMonto($totalBruto),
        'total_devoluciones' => cajaMonto($totalDevoluciones),
        'total_neto' => cajaMonto($totalNeto),
        'efectivo_esperado' => cajaMonto($efectivoEsperado),
        'total_tarjeta_neto' => cajaMonto(
            $ventas['tarjeta'] +
            $membresias['tarjeta'] -
            $devoluciones['tarjeta']
        ),
        'total_transferencia_neto' => cajaMonto(
            $ventas['transferencia'] +
            $membresias['transferencia'] -
            $devoluciones['transferencia']
        ),
        'operaciones' =>
            $ventas['operaciones'] +
            $membresias['operaciones'] +
            $devoluciones['operaciones'] +
            $manuales['operaciones'],
        'advertencias' => $advertencias,
    );
}

/**
 * Registra una entrada o salida manual.
 */
function registrarMovimientoManual(
    $conn,
    $cajaId,
    $usuarioId,
    $tipo,
    $categoria,
    $concepto,
    $monto
) {
    $tipos = ['entrada', 'salida'];
    $categorias = [
        'fondo_adicional',
        'ingreso_vario',
        'retiro',
        'gasto',
        'devolucion_manual',
        'ajuste',
    ];

    if (!in_array($tipo, $tipos, true)) {
        throw new InvalidArgumentException('El tipo de movimiento no es válido.');
    }

    if (!in_array($categoria, $categorias, true)) {
        throw new InvalidArgumentException('La categoría del movimiento no es válida.');
    }

    $concepto = trim($concepto);
    if (mb_strlen($concepto) < 4 || mb_strlen($concepto) > 255) {
        throw new InvalidArgumentException('Escribe un concepto de entre 4 y 255 caracteres.');
    }

    $monto = cajaMonto($monto);
    if ($monto <= 0) {
        throw new InvalidArgumentException('El monto debe ser mayor a cero.');
    }

    $sql = "INSERT INTO caja_movimientos
                (caja_id, usuario_id, tipo, categoria, concepto, monto, fecha_movimiento)
            VALUES (?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar el movimiento manual.');
    }

    $stmt->bind_param('iisssd', $cajaId, $usuarioId, $tipo, $categoria, $concepto, $monto);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    return $id;
}

/**
 * Devuelve el total de operaciones combinadas para paginación.
 */
function contarOperacionesCaja(
    $conn,
    $caja,
    $fechaFin = null
) {
    $resumen = calcularResumenCaja($conn, $caja, $fechaFin);
    return (int) $resumen['operaciones'];
}

/**
 * Lista movimientos combinando ventas, membresías, devoluciones y manuales.
 */
function listarOperacionesCaja(
    $conn,
    $caja,
    $limite,
    $offset,
    $fechaFin = null
) {
    $fechaInicio = (string) $caja['fecha_apertura'];

    if ($fechaFin === null) {
        $fechaFin = !empty($caja['fecha_cierre'])
            ? (string) $caja['fecha_cierre']
            : date('Y-m-d H:i:s');
    }

    $usuarioId = (int) $caja['usuario_apertura_id'];
    $sucursalId = (int) ($caja['sucursal_id'] ?? 0);
    $cajaId = (int) $caja['id'];
    $limite = max(1, (int) $limite);
    $offset = max(0, (int) $offset);

    if ($sucursalId <= 0) {
        throw new RuntimeException(
            'El corte no tiene una sucursal válida asignada.'
        );
    }

    $montoDevolucion = "CASE
                            WHEN vm.monto_devuelto IS NOT NULL
                             AND vm.monto_devuelto > 0
                                THEN vm.monto_devuelto
                            WHEN vm.tipo_modificacion = 'cancelacion'
                                THEN COALESCE(tv_dev.total_original, v.total)
                            ELSE 0
                        END";

    $partes = array();
    $tipos = '';
    $parametros = array();

    $partes[] = "SELECT
                    v.fecha_venta AS fecha,
                    'Venta de productos' AS origen,
                    CONCAT('Venta #', v.id) AS concepto,
                    v.metodo_pago AS metodo_pago,
                    'entrada' AS naturaleza,
                    COALESCE(
                        tv.total_original,
                        CASE
                            WHEN v.estado = 'cancelada'
                                THEN GREATEST(
                                    v.total,
                                    COALESCE(dev.total_devuelto, 0)
                                )
                            ELSE v.total + COALESCE(dev.total_devuelto, 0)
                        END
                    ) AS monto,
                    v.id AS referencia_id
                FROM ventas v
                LEFT JOIN (
                    SELECT venta_id, MAX(total) AS total_original
                    FROM tickets_venta
                    GROUP BY venta_id
                ) tv ON tv.venta_id = v.id
                LEFT JOIN (
                    SELECT
                        venta_id,
                        SUM(
                            CASE
                                WHEN monto_devuelto IS NOT NULL
                                 AND monto_devuelto > 0
                                    THEN monto_devuelto
                                ELSE 0
                            END
                        ) AS total_devuelto
                    FROM ventas_modificaciones
                    WHERE tipo_modificacion IN (
                        'cancelacion',
                        'devolucion_parcial'
                    )
                      AND fecha_modificacion <= ?
                    GROUP BY venta_id
                ) dev ON dev.venta_id = v.id
                WHERE v.fecha_venta >= ?
                  AND v.fecha_venta <= ?
                  AND v.sucursal_id = ?
                  AND v.estado <> 'pendiente'
                  AND (
                        v.usuario_id = ?
                        OR EXISTS (
                            SELECT 1
                            FROM ventas_modificaciones vm_rel
                            WHERE vm_rel.venta_id = v.id
                              AND vm_rel.usuario_id = ?
                              AND vm_rel.fecha_modificacion >= ?
                              AND vm_rel.fecha_modificacion <= ?
                              AND vm_rel.tipo_modificacion IN (
                                  'cancelacion',
                                  'devolucion_parcial'
                              )
                        )
                  )";

    $tipos .= 'sssiiiss';
    $parametros[] = $fechaFin;
    $parametros[] = $fechaInicio;
    $parametros[] = $fechaFin;
    $parametros[] = $sucursalId;
    $parametros[] = $usuarioId;
    $parametros[] = $usuarioId;
    $parametros[] = $fechaInicio;
    $parametros[] = $fechaFin;

    $fuente = cajaResolverFuenteMembresias($conn);

    if ($fuente !== null && !empty($fuente['sucursal_id'])) {
        $tabla = cajaIdentificador($fuente['tabla']);
        $colId = cajaIdentificador($fuente['id']);
        $colMonto = cajaIdentificador($fuente['monto']);
        $colMetodo = cajaIdentificador($fuente['metodo_pago']);
        $colUsuario = cajaIdentificador($fuente['usuario_id']);
        $colSucursal = cajaIdentificador($fuente['sucursal_id']);
        $colFecha = cajaIdentificador($fuente['fecha']);

        $joinCliente = '';
        $conceptoCliente = '';

        if (!empty($fuente['cliente_id'])) {
            $colCliente = cajaIdentificador($fuente['cliente_id']);
            $joinCliente =
                " LEFT JOIN clientes c ON c.id = p.{$colCliente}";
            $conceptoCliente = ", CASE
                                    WHEN c.id IS NOT NULL
                                        THEN CONCAT(
                                            ' · ',
                                            c.nombre,
                                            ' ',
                                            c.apellido
                                        )
                                    ELSE ''
                                  END";
        }

        $condicionEstado = '';

        if (!empty($fuente['estado'])) {
            $colEstado = cajaIdentificador($fuente['estado']);
            $condicionEstado =
                " AND p.{$colEstado} = 'completado'";
        }

        $partes[] = "SELECT
                        p.{$colFecha} AS fecha,
                        'Pago de membresía' AS origen,
                        CONCAT(
                            'Pago #',
                            p.{$colId}
                            {$conceptoCliente}
                        ) AS concepto,
                        p.{$colMetodo} AS metodo_pago,
                        'entrada' AS naturaleza,
                        p.{$colMonto} AS monto,
                        p.{$colId} AS referencia_id
                    FROM {$tabla} p
                    {$joinCliente}
                    WHERE p.{$colUsuario} = ?
                      AND p.{$colSucursal} = ?
                      AND p.{$colFecha} >= ?
                      AND p.{$colFecha} <= ?
                      {$condicionEstado}";

        $tipos .= 'iiss';
        $parametros[] = $usuarioId;
        $parametros[] = $sucursalId;
        $parametros[] = $fechaInicio;
        $parametros[] = $fechaFin;
    }

    if (cajaClasesDisponible($conn)) {
        $partes[] = "SELECT
                        pc.fecha_pago AS fecha,
                        'Acceso a clase' AS origen,
                        CONCAT(
                            'Clase · ',
                            c.nombre,
                            ' · ',
                            pc.nombre_pagador
                        ) AS concepto,
                        pc.metodo_pago AS metodo_pago,
                        'entrada' AS naturaleza,
                        pc.monto AS monto,
                        pc.id AS referencia_id
                    FROM pagos_clases pc
                    INNER JOIN inscripciones_clases ic
                        ON ic.id = pc.inscripcion_clase_id
                    INNER JOIN clases c
                        ON c.id = ic.clase_id
                    WHERE pc.usuario_id = ?
                      AND pc.sucursal_id = ?
                      AND pc.fecha_pago >= ?
                      AND pc.fecha_pago <= ?
                      AND pc.estado = 'completado'";

        $tipos .= 'iiss';
        $parametros[] = $usuarioId;
        $parametros[] = $sucursalId;
        $parametros[] = $fechaInicio;
        $parametros[] = $fechaFin;
    }

    $partes[] = "SELECT
                    vm.fecha_modificacion AS fecha,
                    CASE
                        WHEN vm.tipo_modificacion = 'cancelacion'
                            THEN 'Cancelación de venta'
                        ELSE 'Devolución parcial'
                    END AS origen,
                    CONCAT(
                        'Venta #', vm.venta_id,
                        CASE
                            WHEN vm.descripcion IS NOT NULL
                             AND vm.descripcion <> ''
                                THEN CONCAT(' · ', vm.descripcion)
                            ELSE ''
                        END
                    ) AS concepto,
                    v.metodo_pago AS metodo_pago,
                    'salida' AS naturaleza,
                    {$montoDevolucion} AS monto,
                    vm.id AS referencia_id
                FROM ventas_modificaciones vm
                INNER JOIN ventas v
                    ON v.id = vm.venta_id
                LEFT JOIN (
                    SELECT venta_id, MAX(total) AS total_original
                    FROM tickets_venta
                    GROUP BY venta_id
                ) tv_dev ON tv_dev.venta_id = v.id
                WHERE vm.fecha_modificacion >= ?
                  AND vm.fecha_modificacion <= ?
                  AND v.sucursal_id = ?
                  AND v.estado <> 'pendiente'
                  AND vm.tipo_modificacion IN (
                      'cancelacion',
                      'devolucion_parcial'
                  )
                  AND (
                        v.usuario_id = ?
                        OR vm.usuario_id = ?
                  )";

    $tipos .= 'ssiii';
    $parametros[] = $fechaInicio;
    $parametros[] = $fechaFin;
    $parametros[] = $sucursalId;
    $parametros[] = $usuarioId;
    $parametros[] = $usuarioId;

    $partes[] = "SELECT
                    cm.fecha_movimiento AS fecha,
                    CASE
                        WHEN cm.tipo = 'entrada'
                            THEN 'Entrada manual'
                        ELSE 'Salida manual'
                    END AS origen,
                    cm.concepto AS concepto,
                    'efectivo' AS metodo_pago,
                    cm.tipo AS naturaleza,
                    cm.monto AS monto,
                    cm.id AS referencia_id
                FROM caja_movimientos cm
                WHERE cm.caja_id = ?";

    $tipos .= 'i';
    $parametros[] = $cajaId;

    $sql = "SELECT *
            FROM (" . implode(" UNION ALL ", $partes) . ") movimientos
            WHERE monto > 0
            ORDER BY fecha DESC, referencia_id DESC
            LIMIT ? OFFSET ?";

    $tipos .= 'ii';
    $parametros[] = $limite;
    $parametros[] = $offset;

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'No se pudo preparar el historial de operaciones. Detalle MySQL: ' .
            $conn->error
        );
    }

    if (!cajaEjecutarConParametros($stmt, $tipos, $parametros)) {
        $detalle = $stmt->error;
        $stmt->close();

        throw new RuntimeException(
            'No se pudo consultar el historial de operaciones. Detalle MySQL: ' .
            $detalle
        );
    }

    $resultado = $stmt->get_result();
    $filas = array();

    while ($fila = $resultado->fetch_assoc()) {
        $fila['monto'] = cajaMonto($fila['monto'] ?? 0);
        $filas[] = $fila;
    }

    $stmt->close();

    return $filas;
}

/**
 * Devuelve cortes recientes. Administrador ve todos; recepción solo los propios.
 */
function listarCortesRecientes(
    $conn,
    $usuarioId,
    $esAdmin,
    $limite = 15,
    $sucursalId = 0,
    $vistaGlobal = false
) {
    $limite = max(1, (int) $limite);
    $sucursalId = (int) $sucursalId;

    $seleccion = "SELECT c.*,
                         ua.nombre AS usuario_apertura,
                         uc.nombre AS usuario_cierre,
                         s.nombre AS sucursal_nombre,
                         s.clave AS sucursal_clave
                  FROM cajas c
                  INNER JOIN usuarios ua
                      ON ua.id = c.usuario_apertura_id
                  LEFT JOIN usuarios uc
                      ON uc.id = c.usuario_cierre_id
                  INNER JOIN sucursales s
                      ON s.id = c.sucursal_id";

    if ($esAdmin && $vistaGlobal) {
        $stmt = $conn->prepare(
            $seleccion . "
             WHERE c.estado = 'cerrada'
             ORDER BY c.fecha_cierre DESC, c.id DESC
             LIMIT ?"
        );
        $stmt->bind_param('i', $limite);
    } elseif ($esAdmin) {
        $stmt = $conn->prepare(
            $seleccion . "
             WHERE c.estado = 'cerrada'
               AND c.sucursal_id = ?
             ORDER BY c.fecha_cierre DESC, c.id DESC
             LIMIT ?"
        );
        $stmt->bind_param('ii', $sucursalId, $limite);
    } else {
        $stmt = $conn->prepare(
            $seleccion . "
             WHERE c.estado = 'cerrada'
               AND c.sucursal_id = ?
               AND c.usuario_apertura_id = ?
             ORDER BY c.fecha_cierre DESC, c.id DESC
             LIMIT ?"
        );
        $stmt->bind_param(
            'iii',
            $sucursalId,
            $usuarioId,
            $limite
        );
    }

    if (!$stmt) {
        throw new RuntimeException(
            'No se pudo consultar los cortes recientes.'
        );
    }

    $stmt->execute();
    $resultado = $stmt->get_result();
    $cortes = array();

    while ($fila = $resultado->fetch_assoc()) {
        $cortes[] = $fila;
    }

    $stmt->close();
    return $cortes;
}

/**
 * Usa los totales congelados de una caja cerrada.
 */
function resumenCongeladoCaja($caja) {
    return [
        'fecha_inicio' => (string) $caja['fecha_apertura'],
        'fecha_fin' => (string) ($caja['fecha_cierre'] ?? ''),
        'monto_inicial' => cajaMonto($caja['monto_inicial'] ?? 0),
        'ventas' => [
            'operaciones' => (int) ($caja['operaciones_ventas'] ?? 0),
            'efectivo' => cajaMonto($caja['ventas_efectivo'] ?? 0),
            'tarjeta' => cajaMonto($caja['ventas_tarjeta'] ?? 0),
            'transferencia' => cajaMonto($caja['ventas_transferencia'] ?? 0),
        ],
        'membresias' => [
            'operaciones' => (int) ($caja['operaciones_membresias'] ?? 0),
            'efectivo' => cajaMonto($caja['membresias_efectivo'] ?? 0),
            'tarjeta' => cajaMonto($caja['membresias_tarjeta'] ?? 0),
            'transferencia' => cajaMonto($caja['membresias_transferencia'] ?? 0),
        ],
        'devoluciones' => [
            'operaciones' => (int) ($caja['operaciones_devoluciones'] ?? 0),
            'efectivo' => cajaMonto($caja['devoluciones_efectivo'] ?? 0),
            'tarjeta' => cajaMonto($caja['devoluciones_tarjeta'] ?? 0),
            'transferencia' => cajaMonto($caja['devoluciones_transferencia'] ?? 0),
        ],
        'manuales' => [
            'entradas' => cajaMonto($caja['entradas_manuales'] ?? 0),
            'salidas' => cajaMonto($caja['salidas_manuales'] ?? 0),
            'operaciones' => 0,
        ],
        'total_bruto' => cajaMonto($caja['total_bruto'] ?? 0),
        'total_devoluciones' => cajaMonto($caja['total_devoluciones'] ?? 0),
        'total_neto' => cajaMonto($caja['total_neto'] ?? 0),
        'efectivo_esperado' => cajaMonto($caja['efectivo_esperado'] ?? 0),
        'total_tarjeta_neto' => cajaMonto(
            ($caja['ventas_tarjeta'] ?? 0)
            + ($caja['membresias_tarjeta'] ?? 0)
            - ($caja['devoluciones_tarjeta'] ?? 0)
        ),
        'total_transferencia_neto' => cajaMonto(
            ($caja['ventas_transferencia'] ?? 0)
            + ($caja['membresias_transferencia'] ?? 0)
            - ($caja['devoluciones_transferencia'] ?? 0)
        ),
        'operaciones' => (int) ($caja['operaciones_ventas'] ?? 0)
            + (int) ($caja['operaciones_membresias'] ?? 0)
            + (int) ($caja['operaciones_devoluciones'] ?? 0),
    ];
}

/**
 * Congela el detalle de operaciones al momento de cerrar la caja.
 * No requiere cambios en ventas ni pagos; la copia se realiza desde este módulo.
 */
function guardarSnapshotOperacionesCaja(
    $conn,
    $caja,
    $fechaFin
) {
    $cajaId = (int) $caja['id'];
    $total = contarOperacionesCaja($conn, $caja, $fechaFin);

    $stmtDelete = $conn->prepare("DELETE FROM caja_operaciones WHERE caja_id = ?");
    if (!$stmtDelete) {
        throw new RuntimeException('No se pudo limpiar el detalle anterior del corte.');
    }
    $stmtDelete->bind_param('i', $cajaId);
    $stmtDelete->execute();
    $stmtDelete->close();

    if ($total <= 0) {
        return;
    }

    $operaciones = listarOperacionesCaja($conn, $caja, $total, 0, $fechaFin);
    $sql = "INSERT INTO caja_operaciones (
                caja_id, origen, referencia_id, fecha_operacion,
                concepto, metodo_pago, naturaleza, monto
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo preparar el detalle histórico del corte.');
    }

    foreach ($operaciones as $operacion) {
        $origen = (string) $operacion['origen'];
        $referenciaId = (int) ($operacion['referencia_id'] ?? 0);
        $fecha = (string) $operacion['fecha'];
        $concepto = (string) $operacion['concepto'];
        $metodo = (string) $operacion['metodo_pago'];
        $naturaleza = (string) $operacion['naturaleza'];
        $monto = cajaMonto($operacion['monto'] ?? 0);

        $stmt->bind_param(
            'isissssd',
            $cajaId,
            $origen,
            $referenciaId,
            $fecha,
            $concepto,
            $metodo,
            $naturaleza,
            $monto
        );
        $stmt->execute();
    }

    $stmt->close();
}

function contarSnapshotOperacionesCaja($conn, $cajaId) {
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM caja_operaciones WHERE caja_id = ?");
    if (!$stmt) {
        throw new RuntimeException('No se pudo contar el detalle histórico.');
    }
    $stmt->bind_param('i', $cajaId);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return (int) ($fila['total'] ?? 0);
}

function listarSnapshotOperacionesCaja(
    $conn,
    $cajaId,
    $limite,
    $offset
) {
    $sql = "SELECT
                fecha_operacion AS fecha,
                origen,
                concepto,
                metodo_pago,
                naturaleza,
                monto,
                referencia_id
            FROM caja_operaciones
            WHERE caja_id = ?
            ORDER BY fecha_operacion DESC, id DESC
            LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('No se pudo consultar el detalle histórico.');
    }
    $stmt->bind_param('iii', $cajaId, $limite, $offset);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $filas = [];

    while ($fila = $resultado->fetch_assoc()) {
        $fila['monto'] = cajaMonto($fila['monto'] ?? 0);
        $filas[] = $fila;
    }

    $stmt->close();
    return $filas;
}
