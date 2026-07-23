<?php
declare(strict_types=1);

/**
 * Utilidades compartidas por cancelaciones y devoluciones multisucursal.
 */

const VENTAS_LIMITE_TECNICO_TARJETA_DIAS = 90;

function ventas_multi_rol_base(): string
{
    $rol = strtolower(trim((string) (
        $_SESSION['user_rol_base']
        ?? $_SESSION['user_rol']
        ?? ''
    )));

    $rol = str_replace([' ', '-'], '_', $rol);

    return in_array(
        $rol,
        [
            'admin',
            'administrador',
            'super_administrador',
            'superadministrador',
            'super_admin',
        ],
        true
    )
        ? 'admin'
        : $rol;
}

function ventas_multi_es_admin(): bool
{
    return ventas_multi_rol_base() === 'admin';
}

function ventas_multi_vista_global(): bool
{
    return ventas_multi_es_admin()
        && function_exists('sucursal_dashboard_vista_global')
        && sucursal_dashboard_vista_global();
}

/** @return array<string,mixed> */
function ventas_multi_obtener_venta(
    mysqli $conn,
    int $ventaId,
    bool $forUpdate = false
): array {
    $sql = "SELECT
                v.id,
                v.sucursal_id,
                v.cliente_id,
                v.usuario_id,
                v.fecha_venta,
                v.total,
                v.metodo_pago,
                v.estado,
                s.nombre AS sucursal_nombre,
                s.clave AS sucursal_clave
            FROM ventas v
            INNER JOIN sucursales s
                ON s.id = v.sucursal_id
            WHERE v.id = ?
            LIMIT 1";

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $ventaId);
    $stmt->execute();
    $venta = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($venta)) {
        throw new RuntimeException('Venta no encontrada.');
    }

    $venta['id'] = (int) $venta['id'];
    $venta['sucursal_id'] = (int) $venta['sucursal_id'];
    $venta['usuario_id'] = (int) $venta['usuario_id'];
    $venta['total'] = round((float) $venta['total'], 2);

    return $venta;
}

function ventas_multi_validar_acceso(array $venta): void
{
    $usuarioId = (int) ($_SESSION['user_id'] ?? 0);
    $sucursalActual = (int) ($_SESSION['sucursal_id'] ?? 0);
    $esAdmin = ventas_multi_es_admin();
    $esGlobal = ventas_multi_vista_global();

    if ($usuarioId <= 0 || $sucursalActual <= 0) {
        throw new RuntimeException(
            'No existe un contexto de usuario y sucursal válido.'
        );
    }

    if (!$esGlobal && (int) $venta['sucursal_id'] !== $sucursalActual) {
        throw new RuntimeException(
            'La venta pertenece a otra sucursal. Cambia de sede antes de continuar.'
        );
    }

    if (!$esAdmin && (int) $venta['usuario_id'] !== $usuarioId) {
        throw new RuntimeException(
            'No tienes permiso para modificar esta venta.'
        );
    }
}

/** @return array<string,mixed> */
function ventas_multi_obtener_politica(
    mysqli $conn,
    int $sucursalId
): array {
    $stmt = $conn->prepare(
        "SELECT *
         FROM configuracion_devoluciones_sucursales
         WHERE sucursal_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $sucursalId);
    $stmt->execute();
    $politica = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($politica)) {
        $result = $conn->query(
            "SELECT *
             FROM configuracion_devoluciones
             WHERE id = 1
             LIMIT 1"
        );
        $politica = $result ? $result->fetch_assoc() : null;
    }

    if (!is_array($politica)) {
        throw new RuntimeException(
            'No existe una configuración de cancelaciones y devoluciones.'
        );
    }

    return $politica;
}

function ventas_multi_validar_plazo(
    mysqli $conn,
    array $venta,
    string $accion
): void {
    if (!in_array($accion, ['cancelacion', 'devolucion'], true)) {
        throw new InvalidArgumentException('Acción de devolución inválida.');
    }

    $politica = ventas_multi_obtener_politica(
        $conn,
        (int) $venta['sucursal_id']
    );

    if ((int) ($politica['activo'] ?? 0) !== 1) {
        throw new RuntimeException(
            'Las cancelaciones y devoluciones están desactivadas en esta sucursal.'
        );
    }

    $permitirCampo = $accion === 'cancelacion'
        ? 'permitir_cancelaciones'
        : 'permitir_devoluciones';

    if ((int) ($politica[$permitirCampo] ?? 0) !== 1) {
        throw new RuntimeException(
            $accion === 'cancelacion'
                ? 'Las cancelaciones están desactivadas en esta sucursal.'
                : 'Las devoluciones están desactivadas en esta sucursal.'
        );
    }

    $metodo = strtolower(trim((string) $venta['metodo_pago']));
    if (!in_array($metodo, ['efectivo', 'tarjeta', 'transferencia'], true)) {
        throw new RuntimeException('El método de pago no admite esta operación.');
    }

    $campoDias = 'dias_' . $accion . '_' . $metodo;
    $limite = max(0, (int) ($politica[$campoDias] ?? 0));

    if ($metodo === 'tarjeta') {
        $limite = min($limite, VENTAS_LIMITE_TECNICO_TARJETA_DIAS);

        $stmt = $conn->prepare(
            "SELECT created_at, order_id, payment_id
             FROM mercadopago_operaciones
             WHERE venta_id = ?
             ORDER BY id DESC
             LIMIT 1"
        );
        $ventaId = (int) $venta['id'];
        $stmt->bind_param('i', $ventaId);
        $stmt->execute();
        $operacion = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (
            !is_array($operacion)
            || empty($operacion['created_at'])
            || empty($operacion['order_id'])
            || empty($operacion['payment_id'])
        ) {
            throw new RuntimeException(
                'La venta de tarjeta no tiene una operación de Mercado Pago vinculada.'
            );
        }

        $fechaBase = (string) $operacion['created_at'];
    } else {
        $fechaBase = (string) $venta['fecha_venta'];
    }

    $inicio = new DateTimeImmutable($fechaBase);
    $ahora = new DateTimeImmutable('now');

    if ($inicio > $ahora) {
        throw new RuntimeException(
            'No se pudo determinar correctamente la antigüedad de la venta.'
        );
    }

    $dias = (int) $inicio->diff($ahora)->format('%a');

    if ($dias > $limite) {
        throw new RuntimeException(
            'Han transcurrido ' . $dias .
            ' día(s) y el límite permitido es de ' .
            $limite . ' día(s).'
        );
    }
}

/** @return array<string,mixed> */
function ventas_multi_obtener_caja_abierta(
    mysqli $conn,
    int $sucursalId,
    int $usuarioId
): array {
    $stmt = $conn->prepare(
        "SELECT id
         FROM cajas
         WHERE sucursal_id = ?
           AND usuario_apertura_id = ?
           AND estado = 'abierta'
         ORDER BY id DESC
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->bind_param('ii', $sucursalId, $usuarioId);
    $stmt->execute();
    $caja = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($caja)) {
        throw new RuntimeException(
            'Abre una caja en la sucursal de la venta antes de procesar la operación.'
        );
    }

    return ['id' => (int) $caja['id']];
}

function ventas_multi_bloquear_inventario(
    mysqli $conn,
    int $sucursalId,
    int $productoId
): void {
    $stmt = $conn->prepare(
        "SELECT id
         FROM inventario_sucursales
         WHERE sucursal_id = ?
           AND producto_id = ?
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->bind_param('ii', $sucursalId, $productoId);
    $stmt->execute();
    $inventario = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($inventario)) {
        throw new RuntimeException(
            'El producto #' . $productoId .
            ' no tiene inventario configurado en la sucursal original.'
        );
    }
}

function ventas_multi_reponer_stock(
    mysqli $conn,
    int $sucursalId,
    int $productoId,
    int $cantidad,
    int $usuarioId,
    int $ventaId,
    string $motivo,
    string $referenciaTipo,
    string $observaciones
): void {
    $stmt = $conn->prepare(
        "SELECT id, stock
         FROM inventario_sucursales
         WHERE sucursal_id = ?
           AND producto_id = ?
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->bind_param('ii', $sucursalId, $productoId);
    $stmt->execute();
    $inventario = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($inventario)) {
        throw new RuntimeException(
            'El producto #' . $productoId .
            ' no tiene inventario configurado en la sucursal original.'
        );
    }

    $stockAnterior = (int) $inventario['stock'];
    $stockNuevo = $stockAnterior + $cantidad;
    $inventarioId = (int) $inventario['id'];

    $update = $conn->prepare(
        "UPDATE inventario_sucursales
         SET stock = ?, updated_at = NOW()
         WHERE id = ?"
    );
    $update->bind_param('ii', $stockNuevo, $inventarioId);
    $update->execute();
    $update->close();

    $mov = $conn->prepare(
        "INSERT INTO movimientos_stock (
            sucursal_id,
            producto_id,
            tipo_movimiento,
            cantidad,
            stock_anterior,
            stock_nuevo,
            motivo,
            referencia_id,
            referencia_tipo,
            usuario_id,
            observaciones,
            fecha_movimiento
         ) VALUES (
            ?, ?, 'entrada', ?, ?, ?, ?, ?, ?, ?, ?, NOW()
         )"
    );
    $mov->bind_param(
        'iiiiisisis',
        $sucursalId,
        $productoId,
        $cantidad,
        $stockAnterior,
        $stockNuevo,
        $motivo,
        $ventaId,
        $referenciaTipo,
        $usuarioId,
        $observaciones
    );
    $mov->execute();
    $mov->close();
}

function ventas_multi_registrar_modificacion(
    mysqli $conn,
    int $ventaId,
    int $usuarioId,
    string $tipo,
    string $descripcion,
    float $monto,
    ?string $productosJson = null
): int {
    $stmt = $conn->prepare(
        "INSERT INTO ventas_modificaciones (
            venta_id,
            usuario_id,
            tipo_modificacion,
            descripcion,
            monto_devuelto,
            productos_devueltos,
            fecha_modificacion
         ) VALUES (?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->bind_param(
        'iissds',
        $ventaId,
        $usuarioId,
        $tipo,
        $descripcion,
        $monto,
        $productosJson
    );
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function ventas_multi_registrar_caja(
    mysqli $conn,
    int $cajaId,
    int $modificacionId,
    int $ventaId,
    string $metodo,
    float $monto,
    string $origen,
    string $concepto
): void {
    $stmt = $conn->prepare(
        "INSERT INTO caja_operaciones (
            caja_id,
            origen,
            referencia_id,
            fecha_operacion,
            concepto,
            metodo_pago,
            naturaleza,
            monto
         ) VALUES (?, ?, ?, NOW(), ?, ?, 'salida', ?)"
    );
    $stmt->bind_param(
        'isissd',
        $cajaId,
        $origen,
        $modificacionId,
        $concepto,
        $metodo,
        $monto
    );
    $stmt->execute();
    $stmt->close();

    $columnas = [
        'efectivo' => 'devoluciones_efectivo',
        'tarjeta' => 'devoluciones_tarjeta',
        'transferencia' => 'devoluciones_transferencia',
    ];
    $columna = $columnas[$metodo] ?? null;

    if ($columna === null) {
        throw new RuntimeException('Método de devolución inválido.');
    }

    if ($metodo === 'efectivo') {
        $sql = "UPDATE cajas
                SET {$columna} = {$columna} + ?,
                    total_devoluciones = total_devoluciones + ?,
                    total_neto = total_neto - ?,
                    efectivo_esperado = efectivo_esperado - ?,
                    operaciones_devoluciones = operaciones_devoluciones + 1
                WHERE id = ? AND estado = 'abierta'";
        $update = $conn->prepare($sql);
        $update->bind_param('ddddi', $monto, $monto, $monto, $monto, $cajaId);
    } else {
        $sql = "UPDATE cajas
                SET {$columna} = {$columna} + ?,
                    total_devoluciones = total_devoluciones + ?,
                    total_neto = total_neto - ?,
                    operaciones_devoluciones = operaciones_devoluciones + 1
                WHERE id = ? AND estado = 'abierta'";
        $update = $conn->prepare($sql);
        $update->bind_param('dddi', $monto, $monto, $monto, $cajaId);
    }

    $update->execute();
    if ($update->affected_rows !== 1) {
        $update->close();
        throw new RuntimeException(
            'La caja se cerró mientras se procesaba la devolución.'
        );
    }
    $update->close();
}