<?php
// Archivo: includes/sucursales_helper.php
// Operaciones administrativas del módulo de sucursales.

declare(strict_types=1);

require_once __DIR__ . '/super_admin_helper.php';

function sucursales_actor_es_super(): bool
{
    return rol_es_super_administrador();
}

function sucursales_tabla_existe(mysqli $db, string $tabla): bool
{
    $permitidas = [
        'sucursales',
        'usuarios_sucursales',
        'inventario_sucursales',
        'planes_sucursales',
        'mercadopago_terminales',
        'configuracion_devoluciones_sucursales',
    ];

    if (!in_array($tabla, $permitidas, true)) {
        return false;
    }

    $tablaSegura = $db->real_escape_string($tabla);
    $resultado = $db->query("SHOW TABLES LIKE '{$tablaSegura}'");

    return $resultado instanceof mysqli_result
        && $resultado->num_rows > 0;
}

function sucursales_modulo_instalado(mysqli $db): bool
{
    foreach ([
        'sucursales',
        'usuarios_sucursales',
        'inventario_sucursales',
        'planes_sucursales',
        'mercadopago_terminales',
        'configuracion_devoluciones_sucursales',
    ] as $tabla) {
        if (!sucursales_tabla_existe($db, $tabla)) {
            return false;
        }
    }

    return true;
}

function sucursales_roles(): array
{
    return [
        'admin' => 'Administrador',
        'recepcionista' => 'Recepcionista',
        'entrenador' => 'Entrenador',
    ];
}

function sucursales_zonas_horarias(): array
{
    return [
        'America/Mexico_City' => 'Centro de México',
        'America/Cancun' => 'Quintana Roo',
        'America/Chihuahua' => 'Chihuahua',
        'America/Hermosillo' => 'Sonora',
        'America/Tijuana' => 'Baja California',
    ];
}

function sucursales_normalizar_clave(string $clave): string
{
    $clave = strtoupper(trim($clave));
    $clave = preg_replace('/\s+/', '_', $clave) ?? '';
    $clave = preg_replace('/[^A-Z0-9_-]/', '', $clave) ?? '';

    return substr($clave, 0, 30);
}

function sucursales_validar_datos(array $datos): array
{
    $clave = sucursales_normalizar_clave((string) ($datos['clave'] ?? ''));
    $nombre = trim((string) ($datos['nombre'] ?? ''));
    $telefono = trim((string) ($datos['telefono'] ?? ''));
    $email = strtolower(trim((string) ($datos['email'] ?? '')));
    $direccion = trim((string) ($datos['direccion'] ?? ''));
    $horario = trim((string) ($datos['horario'] ?? ''));
    $zonaHoraria = trim((string) ($datos['zona_horaria'] ?? 'America/Mexico_City'));

    if (strlen($clave) < 2) {
        throw new InvalidArgumentException(
            'La clave debe tener al menos 2 caracteres.'
        );
    }

    if (!preg_match('/^[A-Z0-9_-]{2,30}$/', $clave)) {
        throw new InvalidArgumentException(
            'La clave solo puede contener letras, números, guion y guion bajo.'
        );
    }

    if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 150) {
        throw new InvalidArgumentException(
            'El nombre de la sucursal debe tener entre 3 y 150 caracteres.'
        );
    }

    if ($telefono !== '' && mb_strlen($telefono) > 20) {
        throw new InvalidArgumentException(
            'El teléfono no puede superar 20 caracteres.'
        );
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException(
            'El correo de la sucursal no es válido.'
        );
    }

    if (!array_key_exists($zonaHoraria, sucursales_zonas_horarias())) {
        $zonaHoraria = 'America/Mexico_City';
    }

    return [
        'clave' => $clave,
        'nombre' => $nombre,
        'telefono' => $telefono,
        'email' => $email,
        'direccion' => $direccion,
        'horario' => $horario,
        'zona_horaria' => $zonaHoraria,
    ];
}

function sucursales_listar(mysqli $db): array
{
    $sql = "
        SELECT
            s.id,
            s.clave,
            s.nombre,
            s.telefono,
            s.email,
            s.direccion,
            s.horario,
            s.logo,
            s.zona_horaria,
            s.es_matriz,
            s.estado,
            s.created_at,
            s.updated_at,
            (
                SELECT COUNT(*)
                FROM usuarios_sucursales us
                WHERE us.sucursal_id = s.id
                  AND us.estado = 'activo'
            ) AS usuarios_activos,
            (
                SELECT COUNT(*)
                FROM planes_sucursales ps
                WHERE ps.sucursal_id = s.id
                  AND ps.estado = 'activo'
            ) AS planes_activos,
            (
                SELECT COUNT(*)
                FROM inventario_sucursales inv
                WHERE inv.sucursal_id = s.id
                  AND inv.estado = 'activo'
            ) AS productos_activos,
            (
                SELECT COALESCE(SUM(inv.stock), 0)
                FROM inventario_sucursales inv
                WHERE inv.sucursal_id = s.id
                  AND inv.estado = 'activo'
            ) AS unidades_stock,
            (
                SELECT COUNT(*)
                FROM mercadopago_terminales mt
                WHERE mt.sucursal_id = s.id
                  AND mt.activo = 1
            ) AS terminales_activas
        FROM sucursales s
        ORDER BY s.es_matriz DESC, s.estado = 'activa' DESC, s.nombre ASC
    ";

    $resultado = $db->query($sql);
    if (!$resultado) {
        throw new RuntimeException(
            'No fue posible consultar las sucursales: ' . $db->error
        );
    }

    $sucursales = [];
    while ($fila = $resultado->fetch_assoc()) {
        foreach ([
            'id',
            'es_matriz',
            'usuarios_activos',
            'planes_activos',
            'productos_activos',
            'unidades_stock',
            'terminales_activas',
        ] as $campoEntero) {
            $fila[$campoEntero] = (int) ($fila[$campoEntero] ?? 0);
        }

        $sucursales[] = $fila;
    }

    return $sucursales;
}

function sucursales_obtener(mysqli $db, int $sucursalId): ?array
{
    if ($sucursalId <= 0) {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT * FROM sucursales WHERE id = ? LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('No fue posible preparar la sucursal.');
    }

    $stmt->bind_param('i', $sucursalId);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $fila = $resultado ? ($resultado->fetch_assoc() ?: null) : null;
    $stmt->close();

    if ($fila !== null) {
        $fila['id'] = (int) $fila['id'];
        $fila['es_matriz'] = (int) $fila['es_matriz'];
    }

    return $fila;
}

function sucursales_personal(mysqli $db, int $sucursalId): array
{
    $filtroSuper = sucursales_actor_es_super()
        ? ''
        : " AND u.rol <> 'super_administrador'";

    $stmt = $db->prepare(
        "SELECT
            us.usuario_id,
            us.sucursal_id,
            us.rol_sucursal,
            us.es_principal,
            us.puede_operar_caja,
            us.estado AS asignacion_estado,
            u.nombre,
            u.email,
            u.foto_perfil,
            u.rol AS rol_global,
            u.estado AS usuario_estado,
            COALESCE(us.rol_sucursal, u.rol) AS rol_efectivo
         FROM usuarios_sucursales us
         INNER JOIN usuarios u ON u.id = us.usuario_id
         WHERE us.sucursal_id = ?{$filtroSuper}
         ORDER BY us.estado = 'activo' DESC,
                  FIELD(u.rol, 'super_administrador', 'admin', 'recepcionista', 'entrenador'),
                  u.nombre ASC"
    );

    if (!$stmt) {
        throw new RuntimeException('No fue posible consultar el personal.');
    }

    $stmt->bind_param('i', $sucursalId);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $personal = [];

    while ($resultado && $fila = $resultado->fetch_assoc()) {
        $fila['usuario_id'] = (int) $fila['usuario_id'];
        $fila['sucursal_id'] = (int) $fila['sucursal_id'];
        $fila['es_principal'] = (int) $fila['es_principal'];
        $fila['puede_operar_caja'] = (int) $fila['puede_operar_caja'];
        $personal[] = $fila;
    }

    $stmt->close();

    return $personal;
}

function sucursales_usuarios_disponibles(mysqli $db, int $sucursalId): array
{
    $filtroSuper = sucursales_actor_es_super()
        ? ''
        : " AND u.rol <> 'super_administrador'";

    $stmt = $db->prepare(
        "SELECT
            u.id,
            u.nombre,
            u.email,
            u.rol,
            u.estado,
            us.rol_sucursal,
            us.es_principal,
            us.puede_operar_caja,
            us.estado AS asignacion_estado
         FROM usuarios u
         LEFT JOIN usuarios_sucursales us
           ON us.usuario_id = u.id
          AND us.sucursal_id = ?
         WHERE u.estado = 'activo'{$filtroSuper}
         ORDER BY u.nombre ASC"
    );

    if (!$stmt) {
        throw new RuntimeException('No fue posible consultar los usuarios.');
    }

    $stmt->bind_param('i', $sucursalId);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $usuarios = [];

    while ($resultado && $fila = $resultado->fetch_assoc()) {
        $fila['id'] = (int) $fila['id'];
        $fila['es_principal'] = (int) ($fila['es_principal'] ?? 0);
        $fila['puede_operar_caja'] = (int) ($fila['puede_operar_caja'] ?? 0);
        $usuarios[] = $fila;
    }

    $stmt->close();

    return $usuarios;
}

function sucursales_planes(mysqli $db, int $sucursalId): array
{
    $stmt = $db->prepare(
        "SELECT
            p.id AS plan_id,
            p.nombre,
            p.duracion_dias,
            p.precio AS precio_catalogo,
            p.estado AS catalogo_estado,
            COALESCE(ps.precio, p.precio) AS precio_sucursal,
            COALESCE(ps.estado, 'inactivo') AS estado_sucursal
         FROM planes p
         LEFT JOIN planes_sucursales ps
           ON ps.plan_id = p.id
          AND ps.sucursal_id = ?
         ORDER BY p.duracion_dias ASC, p.nombre ASC"
    );

    if (!$stmt) {
        throw new RuntimeException('No fue posible consultar los planes.');
    }

    $stmt->bind_param('i', $sucursalId);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $planes = [];

    while ($resultado && $fila = $resultado->fetch_assoc()) {
        $fila['plan_id'] = (int) $fila['plan_id'];
        $fila['duracion_dias'] = (int) $fila['duracion_dias'];
        $fila['precio_catalogo'] = (float) $fila['precio_catalogo'];
        $fila['precio_sucursal'] = (float) $fila['precio_sucursal'];
        $planes[] = $fila;
    }

    $stmt->close();

    return $planes;
}

function sucursales_terminales(mysqli $db, int $sucursalId): array
{
    $stmt = $db->prepare(
        "SELECT id, sucursal_id, terminal_id, nombre, predeterminada, activo, created_at, updated_at
         FROM mercadopago_terminales
         WHERE sucursal_id = ?
         ORDER BY predeterminada DESC, activo DESC, nombre ASC"
    );

    if (!$stmt) {
        throw new RuntimeException('No fue posible consultar las terminales.');
    }

    $stmt->bind_param('i', $sucursalId);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $terminales = [];

    while ($resultado && $fila = $resultado->fetch_assoc()) {
        $fila['id'] = (int) $fila['id'];
        $fila['sucursal_id'] = (int) $fila['sucursal_id'];
        $fila['predeterminada'] = (int) $fila['predeterminada'];
        $fila['activo'] = (int) $fila['activo'];
        $terminales[] = $fila;
    }

    $stmt->close();

    return $terminales;
}

function sucursales_resumen_inventario(mysqli $db, int $sucursalId): array
{
    $stmt = $db->prepare(
        "SELECT
            COUNT(*) AS productos,
            COALESCE(SUM(stock), 0) AS unidades,
            SUM(CASE WHEN stock <= stock_minimo THEN 1 ELSE 0 END) AS bajo_minimo,
            COALESCE(SUM(stock * precio_compra), 0) AS valor_compra,
            COALESCE(SUM(stock * precio_venta), 0) AS valor_venta
         FROM inventario_sucursales
         WHERE sucursal_id = ?
           AND estado = 'activo'"
    );

    if (!$stmt) {
        throw new RuntimeException('No fue posible consultar el inventario.');
    }

    $stmt->bind_param('i', $sucursalId);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $fila = $resultado ? ($resultado->fetch_assoc() ?: []) : [];
    $stmt->close();

    return [
        'productos' => (int) ($fila['productos'] ?? 0),
        'unidades' => (int) ($fila['unidades'] ?? 0),
        'bajo_minimo' => (int) ($fila['bajo_minimo'] ?? 0),
        'valor_compra' => (float) ($fila['valor_compra'] ?? 0),
        'valor_venta' => (float) ($fila['valor_venta'] ?? 0),
    ];
}

function sucursales_crear(
    mysqli $db,
    array $datos,
    int $administradorId
): int {
    $validados = sucursales_validar_datos($datos);

    $db->begin_transaction();

    try {
        $stmt = $db->prepare(
            "INSERT INTO sucursales
                (clave, nombre, telefono, email, direccion, horario, zona_horaria, es_matriz, estado)
             VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, 0, 'activa')"
        );

        if (!$stmt) {
            throw new RuntimeException('No fue posible preparar la nueva sucursal.');
        }

        $stmt->bind_param(
            'sssssss',
            $validados['clave'],
            $validados['nombre'],
            $validados['telefono'],
            $validados['email'],
            $validados['direccion'],
            $validados['horario'],
            $validados['zona_horaria']
        );

        if (!$stmt->execute()) {
            if ((int) $stmt->errno === 1062) {
                throw new RuntimeException(
                    'Ya existe una sucursal con esa clave.'
                );
            }

            throw new RuntimeException(
                'No fue posible crear la sucursal: ' . $stmt->error
            );
        }

        $sucursalId = (int) $db->insert_id;
        $stmt->close();

        $rolAdmin = 'admin';
        $estadoActivo = 'activo';
        $esPrincipal = 0;
        $puedeCaja = 1;

        $stmtAdmin = $db->prepare(
            "INSERT INTO usuarios_sucursales
                (usuario_id, sucursal_id, rol_sucursal, es_principal, puede_operar_caja, estado)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        if (!$stmtAdmin) {
            throw new RuntimeException('No fue posible asignar al administrador.');
        }

        $stmtAdmin->bind_param(
            'iisiis',
            $administradorId,
            $sucursalId,
            $rolAdmin,
            $esPrincipal,
            $puedeCaja,
            $estadoActivo
        );

        if (!$stmtAdmin->execute()) {
            throw new RuntimeException(
                'La sucursal fue creada, pero no se pudo asignar al administrador.'
            );
        }
        $stmtAdmin->close();

        if (!$db->query(
            "INSERT INTO planes_sucursales
                (sucursal_id, plan_id, precio, estado)
             SELECT {$sucursalId}, id, precio, estado
             FROM planes"
        )) {
            throw new RuntimeException(
                'No fue posible habilitar los planes de la sucursal.'
            );
        }

        if (!$db->query(
            "INSERT INTO inventario_sucursales
                (sucursal_id, producto_id, precio_compra, precio_venta, stock, stock_minimo, estado)
             SELECT {$sucursalId}, id, precio_compra, precio_venta, 0, stock_minimo, estado
             FROM productos"
        )) {
            throw new RuntimeException(
                'No fue posible crear el inventario de la sucursal.'
            );
        }

        $observaciones = 'Configuración inicial de ' . $validados['nombre'];
        $stmtCopiarPolitica = $db->prepare(
            "INSERT INTO configuracion_devoluciones_sucursales
                (sucursal_id, activo, permitir_cancelaciones, permitir_devoluciones,
                 dias_cancelacion_efectivo, dias_devolucion_efectivo,
                 dias_cancelacion_tarjeta, dias_devolucion_tarjeta,
                 dias_cancelacion_transferencia, dias_devolucion_transferencia,
                 observaciones)
             SELECT ?, activo, permitir_cancelaciones, permitir_devoluciones,
                    dias_cancelacion_efectivo, dias_devolucion_efectivo,
                    dias_cancelacion_tarjeta, dias_devolucion_tarjeta,
                    dias_cancelacion_transferencia, dias_devolucion_transferencia, ?
             FROM configuracion_devoluciones
             WHERE id = 1"
        );

        if (!$stmtCopiarPolitica) {
            throw new RuntimeException(
                'No fue posible preparar la política de devoluciones.'
            );
        }

        $stmtCopiarPolitica->bind_param('is', $sucursalId, $observaciones);
        if (!$stmtCopiarPolitica->execute()) {
            throw new RuntimeException(
                'No fue posible copiar la política de devoluciones.'
            );
        }
        $politicaCopiada = $stmtCopiarPolitica->affected_rows > 0;
        $stmtCopiarPolitica->close();

        if (!$politicaCopiada) {
            $stmtPolitica = $db->prepare(
                "INSERT INTO configuracion_devoluciones_sucursales
                    (sucursal_id, observaciones)
                 VALUES (?, ?)"
            );
            if (!$stmtPolitica) {
                throw new RuntimeException(
                    'No fue posible crear la política de devoluciones.'
                );
            }

            $stmtPolitica->bind_param('is', $sucursalId, $observaciones);
            if (!$stmtPolitica->execute()) {
                throw new RuntimeException(
                    'No fue posible crear la política de devoluciones.'
                );
            }
            $stmtPolitica->close();
        }

        $db->commit();

        return $sucursalId;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function sucursales_actualizar(
    mysqli $db,
    int $sucursalId,
    array $datos
): void {
    $sucursal = sucursales_obtener($db, $sucursalId);
    if ($sucursal === null) {
        throw new RuntimeException('La sucursal no existe.');
    }

    $validados = sucursales_validar_datos($datos);

    $stmt = $db->prepare(
        "UPDATE sucursales
         SET clave = ?,
             nombre = ?,
             telefono = NULLIF(?, ''),
             email = NULLIF(?, ''),
             direccion = NULLIF(?, ''),
             horario = NULLIF(?, ''),
             zona_horaria = ?
         WHERE id = ?"
    );

    if (!$stmt) {
        throw new RuntimeException('No fue posible preparar la actualización.');
    }

    $stmt->bind_param(
        'sssssssi',
        $validados['clave'],
        $validados['nombre'],
        $validados['telefono'],
        $validados['email'],
        $validados['direccion'],
        $validados['horario'],
        $validados['zona_horaria'],
        $sucursalId
    );

    if (!$stmt->execute()) {
        if ((int) $stmt->errno === 1062) {
            throw new RuntimeException('Ya existe otra sucursal con esa clave.');
        }

        throw new RuntimeException(
            'No fue posible actualizar la sucursal: ' . $stmt->error
        );
    }

    $stmt->close();
}

function sucursales_cambiar_estado(
    mysqli $db,
    int $sucursalId,
    string $nuevoEstado,
    int $sucursalActivaId
): void {
    if (!in_array($nuevoEstado, ['activa', 'inactiva'], true)) {
        throw new InvalidArgumentException('Estado de sucursal no válido.');
    }

    $sucursal = sucursales_obtener($db, $sucursalId);
    if ($sucursal === null) {
        throw new RuntimeException('La sucursal no existe.');
    }

    if ($nuevoEstado === 'inactiva') {
        if ((int) $sucursal['es_matriz'] === 1) {
            throw new RuntimeException(
                'La sucursal matriz no puede desactivarse.'
            );
        }

        if ($sucursalId === $sucursalActivaId) {
            throw new RuntimeException(
                'Primero cambia a otra sucursal antes de desactivar la actual.'
            );
        }

        $stmtCaja = $db->prepare(
            "SELECT COUNT(*) AS total
             FROM cajas
             WHERE sucursal_id = ?
               AND estado = 'abierta'"
        );
        if ($stmtCaja) {
            $stmtCaja->bind_param('i', $sucursalId);
            $stmtCaja->execute();
            $resultadoCaja = $stmtCaja->get_result();
            $filaCaja = $resultadoCaja
                ? $resultadoCaja->fetch_assoc()
                : null;
            $cajasAbiertas = (int) ($filaCaja['total'] ?? 0);
            $stmtCaja->close();

            if ($cajasAbiertas > 0) {
                throw new RuntimeException(
                    'No puedes desactivar una sucursal con caja abierta.'
                );
            }
        }
    }

    $stmt = $db->prepare(
        "UPDATE sucursales SET estado = ? WHERE id = ?"
    );
    if (!$stmt) {
        throw new RuntimeException('No fue posible preparar el cambio de estado.');
    }

    $stmt->bind_param('si', $nuevoEstado, $sucursalId);
    if (!$stmt->execute()) {
        throw new RuntimeException('No fue posible cambiar el estado.');
    }
    $stmt->close();
}

function sucursales_guardar_asignacion(
    mysqli $db,
    int $sucursalId,
    int $usuarioId,
    string $rol,
    bool $esPrincipal,
    bool $puedeOperarCaja,
    string $estado,
    int $administradorActualId,
    int $sucursalActivaId
): void {
    $roles = sucursales_roles();
    if (!array_key_exists($rol, $roles)) {
        throw new InvalidArgumentException('Rol no válido.');
    }

    if (!in_array($estado, ['activo', 'inactivo'], true)) {
        throw new InvalidArgumentException('Estado de asignación no válido.');
    }

    if ($usuarioId <= 0 || sucursales_obtener($db, $sucursalId) === null) {
        throw new InvalidArgumentException('Usuario o sucursal no válidos.');
    }

    $stmtUsuario = $db->prepare(
        "SELECT id, rol
         FROM usuarios
         WHERE id = ? AND estado = 'activo'
         LIMIT 1"
    );
    if (!$stmtUsuario) {
        throw new RuntimeException('No fue posible validar el usuario.');
    }
    $stmtUsuario->bind_param('i', $usuarioId);
    $stmtUsuario->execute();
    $resultadoUsuario = $stmtUsuario->get_result();
    $usuarioObjetivo = $resultadoUsuario
        ? $resultadoUsuario->fetch_assoc()
        : null;
    $stmtUsuario->close();

    if (!$usuarioObjetivo) {
        throw new RuntimeException('El usuario no está activo.');
    }

    $rolGlobalObjetivo = rol_normalizar_sistema(
        (string) ($usuarioObjetivo['rol'] ?? '')
    );

    if ($rolGlobalObjetivo === 'super_administrador') {
        if (!sucursales_actor_es_super()) {
            throw new RuntimeException(
                'No tienes permiso para consultar o modificar esa cuenta.'
            );
        }

        if (
            $usuarioId !== $administradorActualId
            || $rol !== 'admin'
            || $estado !== 'activo'
        ) {
            throw new RuntimeException(
                'La cuenta superadministradora debe conservar acceso administrativo activo.'
            );
        }
    }

    if (
        $usuarioId === $administradorActualId
        && $sucursalId === $sucursalActivaId
        && ($estado !== 'activo' || $rol !== 'admin')
    ) {
        throw new RuntimeException(
            'No puedes retirar tu propio acceso administrativo de la sucursal activa.'
        );
    }

    $db->begin_transaction();

    try {
        if ($esPrincipal) {
            $stmtPrincipal = $db->prepare(
                "UPDATE usuarios_sucursales
                 SET es_principal = 0
                 WHERE usuario_id = ?"
            );
            if (!$stmtPrincipal) {
                throw new RuntimeException('No fue posible actualizar la sede principal.');
            }
            $stmtPrincipal->bind_param('i', $usuarioId);
            $stmtPrincipal->execute();
            $stmtPrincipal->close();
        }

        $principal = $esPrincipal ? 1 : 0;
        $caja = $puedeOperarCaja ? 1 : 0;

        $stmt = $db->prepare(
            "INSERT INTO usuarios_sucursales
                (usuario_id, sucursal_id, rol_sucursal, es_principal, puede_operar_caja, estado)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                rol_sucursal = VALUES(rol_sucursal),
                es_principal = VALUES(es_principal),
                puede_operar_caja = VALUES(puede_operar_caja),
                estado = VALUES(estado),
                updated_at = CURRENT_TIMESTAMP"
        );

        if (!$stmt) {
            throw new RuntimeException('No fue posible preparar la asignación.');
        }

        $stmt->bind_param(
            'iisiis',
            $usuarioId,
            $sucursalId,
            $rol,
            $principal,
            $caja,
            $estado
        );

        if (!$stmt->execute()) {
            throw new RuntimeException(
                'No fue posible guardar la asignación: ' . $stmt->error
            );
        }
        $stmt->close();

        $db->commit();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function sucursales_desactivar_asignacion(
    mysqli $db,
    int $sucursalId,
    int $usuarioId,
    int $administradorActualId,
    int $sucursalActivaId
): void {
    if (
        $usuarioId === $administradorActualId
        && $sucursalId === $sucursalActivaId
    ) {
        throw new RuntimeException(
            'No puedes retirar tu propio acceso de la sucursal activa.'
        );
    }

    $stmtRolGlobal = $db->prepare(
        "SELECT rol FROM usuarios WHERE id = ? LIMIT 1"
    );
    if (!$stmtRolGlobal) {
        throw new RuntimeException('No fue posible validar la cuenta.');
    }
    $stmtRolGlobal->bind_param('i', $usuarioId);
    $stmtRolGlobal->execute();
    $filaRolGlobal = $stmtRolGlobal->get_result()->fetch_assoc();
    $stmtRolGlobal->close();

    if (
        rol_normalizar_sistema((string) ($filaRolGlobal['rol'] ?? ''))
        === 'super_administrador'
    ) {
        throw new RuntimeException(
            'El acceso del superadministrador no puede retirarse.'
        );
    }

    $stmtActual = $db->prepare(
        "SELECT COALESCE(us.rol_sucursal, u.rol) AS rol_efectivo
         FROM usuarios_sucursales us
         INNER JOIN usuarios u ON u.id = us.usuario_id
         WHERE us.usuario_id = ?
           AND us.sucursal_id = ?
           AND us.estado = 'activo'
         LIMIT 1"
    );
    if (!$stmtActual) {
        throw new RuntimeException('No fue posible validar la asignación.');
    }

    $stmtActual->bind_param('ii', $usuarioId, $sucursalId);
    $stmtActual->execute();
    $resultadoAsignacion = $stmtActual->get_result();
    $asignacion = $resultadoAsignacion
        ? $resultadoAsignacion->fetch_assoc()
        : null;
    $stmtActual->close();

    if (!$asignacion) {
        throw new RuntimeException('La asignación ya no está activa.');
    }

    if ((string) $asignacion['rol_efectivo'] === 'admin') {
        $stmtAdmins = $db->prepare(
            "SELECT COUNT(*) AS total
             FROM usuarios_sucursales us
             INNER JOIN usuarios u ON u.id = us.usuario_id
             WHERE us.sucursal_id = ?
               AND us.estado = 'activo'
               AND u.estado = 'activo'
               AND COALESCE(us.rol_sucursal, u.rol) = 'admin'"
        );
        if (!$stmtAdmins) {
            throw new RuntimeException('No fue posible validar los administradores.');
        }
        $stmtAdmins->bind_param('i', $sucursalId);
        $stmtAdmins->execute();
        $resultadoAdmins = $stmtAdmins->get_result();
        $filaAdmins = $resultadoAdmins
            ? $resultadoAdmins->fetch_assoc()
            : null;
        $totalAdmins = (int) ($filaAdmins['total'] ?? 0);
        $stmtAdmins->close();

        if ($totalAdmins <= 1) {
            throw new RuntimeException(
                'La sucursal debe conservar al menos un administrador activo.'
            );
        }
    }

    $stmt = $db->prepare(
        "UPDATE usuarios_sucursales
         SET estado = 'inactivo', es_principal = 0
         WHERE usuario_id = ? AND sucursal_id = ?"
    );
    if (!$stmt) {
        throw new RuntimeException('No fue posible preparar la desactivación.');
    }
    $stmt->bind_param('ii', $usuarioId, $sucursalId);
    if (!$stmt->execute()) {
        throw new RuntimeException('No fue posible retirar el acceso.');
    }
    $stmt->close();
}

function sucursales_guardar_plan(
    mysqli $db,
    int $sucursalId,
    int $planId,
    float $precio,
    string $estado
): void {
    if ($precio < 0 || $precio > 99999999.99) {
        throw new InvalidArgumentException('El precio del plan no es válido.');
    }

    if (!in_array($estado, ['activo', 'inactivo'], true)) {
        throw new InvalidArgumentException('El estado del plan no es válido.');
    }

    $stmt = $db->prepare(
        "INSERT INTO planes_sucursales
            (sucursal_id, plan_id, precio, estado)
         SELECT ?, id, ?, ?
         FROM planes
         WHERE id = ?
         ON DUPLICATE KEY UPDATE
            precio = VALUES(precio),
            estado = VALUES(estado),
            updated_at = CURRENT_TIMESTAMP"
    );

    if (!$stmt) {
        throw new RuntimeException('No fue posible preparar el plan.');
    }

    $stmt->bind_param('idsi', $sucursalId, $precio, $estado, $planId);
    if (!$stmt->execute()) {
        throw new RuntimeException('No fue posible guardar el plan.');
    }
    $stmt->close();
}

function sucursales_sincronizar_catalogos(mysqli $db, int $sucursalId): array
{
    if (sucursales_obtener($db, $sucursalId) === null) {
        throw new RuntimeException('La sucursal no existe.');
    }

    $db->begin_transaction();

    try {
        $sqlPlanes = "
            INSERT IGNORE INTO planes_sucursales
                (sucursal_id, plan_id, precio, estado)
            SELECT {$sucursalId}, id, precio, estado
            FROM planes
        ";
        if (!$db->query($sqlPlanes)) {
            throw new RuntimeException('No fue posible sincronizar los planes.');
        }
        $planesAgregados = $db->affected_rows;

        $sqlProductos = "
            INSERT IGNORE INTO inventario_sucursales
                (sucursal_id, producto_id, precio_compra, precio_venta, stock, stock_minimo, estado)
            SELECT {$sucursalId}, id, precio_compra, precio_venta, 0, stock_minimo, estado
            FROM productos
        ";
        if (!$db->query($sqlProductos)) {
            throw new RuntimeException('No fue posible sincronizar los productos.');
        }
        $productosAgregados = $db->affected_rows;

        $db->commit();

        return [
            'planes' => max(0, (int) $planesAgregados),
            'productos' => max(0, (int) $productosAgregados),
        ];
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function sucursales_guardar_terminal(
    mysqli $db,
    int $sucursalId,
    int $terminalRegistroId,
    string $terminalId,
    string $nombre,
    bool $predeterminada,
    bool $activa
): int {
    $terminalId = trim($terminalId);
    $nombre = trim($nombre);

    if ($terminalId === '' || mb_strlen($terminalId) > 120) {
        throw new InvalidArgumentException('El identificador de la terminal no es válido.');
    }

    if ($nombre === '' || mb_strlen($nombre) > 100) {
        throw new InvalidArgumentException('El nombre de la terminal no es válido.');
    }

    $db->begin_transaction();

    try {
        if ($predeterminada) {
            $stmtReset = $db->prepare(
                "UPDATE mercadopago_terminales
                 SET predeterminada = 0
                 WHERE sucursal_id = ?"
            );
            if (!$stmtReset) {
                throw new RuntimeException('No fue posible actualizar la terminal predeterminada.');
            }
            $stmtReset->bind_param('i', $sucursalId);
            $stmtReset->execute();
            $stmtReset->close();
        }

        $valorPredeterminada = $predeterminada ? 1 : 0;
        $valorActiva = $activa ? 1 : 0;

        if ($terminalRegistroId > 0) {
            $stmt = $db->prepare(
                "UPDATE mercadopago_terminales
                 SET terminal_id = ?, nombre = ?, predeterminada = ?, activo = ?
                 WHERE id = ? AND sucursal_id = ?"
            );
            if (!$stmt) {
                throw new RuntimeException('No fue posible preparar la terminal.');
            }
            $stmt->bind_param(
                'ssiiii',
                $terminalId,
                $nombre,
                $valorPredeterminada,
                $valorActiva,
                $terminalRegistroId,
                $sucursalId
            );
        } else {
            $stmt = $db->prepare(
                "INSERT INTO mercadopago_terminales
                    (sucursal_id, terminal_id, nombre, predeterminada, activo)
                 VALUES (?, ?, ?, ?, ?)"
            );
            if (!$stmt) {
                throw new RuntimeException('No fue posible preparar la terminal.');
            }
            $stmt->bind_param(
                'issii',
                $sucursalId,
                $terminalId,
                $nombre,
                $valorPredeterminada,
                $valorActiva
            );
        }

        if (!$stmt->execute()) {
            if ((int) $stmt->errno === 1062) {
                throw new RuntimeException(
                    'Esa terminal ya está registrada en otra sucursal.'
                );
            }

            throw new RuntimeException(
                'No fue posible guardar la terminal: ' . $stmt->error
            );
        }

        $id = $terminalRegistroId > 0
            ? $terminalRegistroId
            : (int) $db->insert_id;
        $stmt->close();

        if (!$predeterminada && $activa) {
            $stmtExiste = $db->prepare(
                "SELECT COUNT(*) AS total
                 FROM mercadopago_terminales
                 WHERE sucursal_id = ?
                   AND activo = 1
                   AND predeterminada = 1"
            );
            if ($stmtExiste) {
                $stmtExiste->bind_param('i', $sucursalId);
                $stmtExiste->execute();
                $resultadoPredeterminadas = $stmtExiste->get_result();
                $filaPredeterminadas = $resultadoPredeterminadas
                    ? $resultadoPredeterminadas->fetch_assoc()
                    : null;
                $totalPredeterminadas = (int) (
                    $filaPredeterminadas['total'] ?? 0
                );
                $stmtExiste->close();

                if ($totalPredeterminadas === 0) {
                    $stmtDefault = $db->prepare(
                        "UPDATE mercadopago_terminales
                         SET predeterminada = 1
                         WHERE id = ? AND sucursal_id = ?"
                    );
                    if ($stmtDefault) {
                        $stmtDefault->bind_param('ii', $id, $sucursalId);
                        $stmtDefault->execute();
                        $stmtDefault->close();
                    }
                }
            }
        }

        $db->commit();

        return $id;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function sucursales_cambiar_estado_terminal(
    mysqli $db,
    int $sucursalId,
    int $terminalId,
    bool $activa
): void {
    $valor = $activa ? 1 : 0;

    $stmt = $db->prepare(
        "UPDATE mercadopago_terminales
         SET activo = ?,
             predeterminada = CASE WHEN ? = 0 THEN 0 ELSE predeterminada END
         WHERE id = ? AND sucursal_id = ?"
    );
    if (!$stmt) {
        throw new RuntimeException('No fue posible preparar la terminal.');
    }

    $stmt->bind_param('iiii', $valor, $valor, $terminalId, $sucursalId);
    if (!$stmt->execute() || $stmt->affected_rows === 0) {
        throw new RuntimeException('No fue posible cambiar el estado de la terminal.');
    }
    $stmt->close();
}