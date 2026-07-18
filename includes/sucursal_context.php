<?php
// Archivo: includes/sucursal_context.php
// Contexto seguro de la sucursal activa para todo el sistema.

declare(strict_types=1);

/**
 * Devuelve las sucursales activas asignadas al usuario.
 * El rol efectivo puede variar por sucursal.
 */
function sucursal_obtener_asignadas(mysqli $db, int $usuarioId): array
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
            us.es_principal,
            us.puede_operar_caja,
            COALESCE(us.rol_sucursal, u.rol) AS rol_efectivo
        FROM usuarios_sucursales us
        INNER JOIN sucursales s
            ON s.id = us.sucursal_id
        INNER JOIN usuarios u
            ON u.id = us.usuario_id
        WHERE us.usuario_id = ?
          AND us.estado = 'activo'
          AND s.estado = 'activa'
          AND u.estado = 'activo'
        ORDER BY us.es_principal DESC, s.es_matriz DESC, s.nombre ASC
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException(
            'No fue posible preparar la consulta de sucursales.'
        );
    }

    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    $result = $stmt->get_result();

    $sucursales = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int) $row['id'];
        $row['es_principal'] = (int) $row['es_principal'];
        $row['puede_operar_caja'] = (int) $row['puede_operar_caja'];
        $sucursales[] = $row;
    }

    $stmt->close();

    return $sucursales;
}

/**
 * Busca una sucursal concreta dentro de las asignaciones del usuario.
 */
function sucursal_buscar_asignada(
    mysqli $db,
    int $usuarioId,
    int $sucursalId
): ?array {
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
            us.es_principal,
            us.puede_operar_caja,
            COALESCE(us.rol_sucursal, u.rol) AS rol_efectivo
        FROM usuarios_sucursales us
        INNER JOIN sucursales s
            ON s.id = us.sucursal_id
        INNER JOIN usuarios u
            ON u.id = us.usuario_id
        WHERE us.usuario_id = ?
          AND us.sucursal_id = ?
          AND us.estado = 'activo'
          AND s.estado = 'activa'
          AND u.estado = 'activo'
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException(
            'No fue posible validar la sucursal solicitada.'
        );
    }

    $stmt->bind_param('ii', $usuarioId, $sucursalId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc() ?: null;
    $stmt->close();

    if ($row !== null) {
        $row['id'] = (int) $row['id'];
        $row['es_principal'] = (int) $row['es_principal'];
        $row['puede_operar_caja'] = (int) $row['puede_operar_caja'];
    }

    return $row;
}

/**
 * Guarda únicamente datos confiables obtenidos desde la BD.
 */
function sucursal_guardar_en_sesion(array $sucursal): void
{
    /*
     * Conserva el rol general para validar funciones administrativas que
     * no dependen del rol efectivo de una sede concreta.
     */
    if (empty($_SESSION['user_rol_base'])) {
        $_SESSION['user_rol_base'] = (string) (
            $_SESSION['user_rol']
            ?? $sucursal['rol_efectivo']
            ?? 'recepcionista'
        );
    }

    $_SESSION['sucursal_id'] = (int) $sucursal['id'];
    $_SESSION['sucursal_clave'] = (string) $sucursal['clave'];
    $_SESSION['sucursal_nombre'] = (string) $sucursal['nombre'];
    $_SESSION['sucursal_zona_horaria'] = (string) (
        $sucursal['zona_horaria'] ?? 'America/Mexico_City'
    );
    $_SESSION['sucursal_puede_operar_caja'] = (int) (
        $sucursal['puede_operar_caja'] ?? 0
    );

    // Los permisos por módulo siguen usando user_rol, pero ahora el valor
    // representa el rol efectivo en la sucursal activa.
    $_SESSION['user_rol'] = (string) $sucursal['rol_efectivo'];

    date_default_timezone_set(
        $_SESSION['sucursal_zona_horaria']
    );
}

/**
 * Inicializa o revalida la sucursal activa después del login y en cada
 * petición protegida. Nunca confía solo en el valor guardado en sesión.
 */
function sucursal_inicializar_sesion(mysqli $db): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $usuarioId = (int) ($_SESSION['user_id'] ?? 0);
    if ($usuarioId <= 0) {
        throw new RuntimeException('La sesión del usuario no es válida.');
    }

    $sucursalActualId = (int) ($_SESSION['sucursal_id'] ?? 0);

    if ($sucursalActualId > 0) {
        $actual = sucursal_buscar_asignada(
            $db,
            $usuarioId,
            $sucursalActualId
        );

        if ($actual !== null) {
            sucursal_guardar_en_sesion($actual);
            sucursal_asegurar_csrf();
            return $actual;
        }
    }

    $asignadas = sucursal_obtener_asignadas($db, $usuarioId);
    if ($asignadas === []) {
        throw new RuntimeException(
            'Tu cuenta no tiene una sucursal activa asignada.'
        );
    }

    $principal = $asignadas[0];
    sucursal_guardar_en_sesion($principal);
    sucursal_asegurar_csrf();

    return $principal;
}

/**
 * Comprueba el rol general del usuario directamente desde la base de datos.
 */
function sucursal_usuario_es_administrador(
    mysqli $db,
    int $usuarioId
): bool {
    $sql = "
        SELECT rol
        FROM usuarios
        WHERE id = ?
          AND estado = 'activo'
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'No fue posible validar el permiso administrativo.'
        );
    }

    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $fila = $resultado ? $resultado->fetch_assoc() : null;
    $stmt->close();

    $rol = strtolower(trim((string) ($fila['rol'] ?? '')));

    return in_array(
        $rol,
        ['admin', 'administrador'],
        true
    );
}

/**
 * Activa el resumen global del dashboard sin cambiar la sucursal operativa.
 */
function sucursal_activar_vista_global(
    mysqli $db,
    int $usuarioId
): void {
    if ($usuarioId <= 0) {
        throw new InvalidArgumentException('Usuario no válido.');
    }

    if (!sucursal_usuario_es_administrador($db, $usuarioId)) {
        throw new RuntimeException(
            'No tienes permiso para consultar todas las sucursales.'
        );
    }

    $_SESSION['dashboard_vista_global'] = 1;
}

/** Desactiva el resumen global y vuelve al contexto de la sede operativa. */
function sucursal_desactivar_vista_global(): void
{
    unset($_SESSION['dashboard_vista_global']);
}

/** Indica si el dashboard está mostrando el consolidado de todas las sedes. */
function sucursal_dashboard_vista_global(): bool
{
    return isset($_SESSION['dashboard_vista_global'])
        && (int) $_SESSION['dashboard_vista_global'] === 1;
}

/**
 * Cambia la sede activa validando la relación usuario-sucursal.
 */
function sucursal_cambiar_activa(
    mysqli $db,
    int $usuarioId,
    int $sucursalId
): array {
    if ($usuarioId <= 0 || $sucursalId <= 0) {
        throw new InvalidArgumentException('Sucursal no válida.');
    }

    $sucursal = sucursal_buscar_asignada(
        $db,
        $usuarioId,
        $sucursalId
    );

    if ($sucursal === null) {
        throw new RuntimeException(
            'No tienes acceso a la sucursal seleccionada.'
        );
    }

    sucursal_guardar_en_sesion($sucursal);
    sucursal_desactivar_vista_global();

    // Evita reutilizar filtros, carritos o datos temporales de otra sede.
    unset(
        $_SESSION['venta_carrito'],
        $_SESSION['inscripcion_borrador'],
        $_SESSION['caja_id_activa']
    );

    return $sucursal;
}

function sucursal_id_actual(): int
{
    $sucursalId = (int) ($_SESSION['sucursal_id'] ?? 0);

    if ($sucursalId <= 0) {
        throw new RuntimeException(
            'No existe una sucursal activa en la sesión.'
        );
    }

    return $sucursalId;
}

function sucursal_nombre_actual(): string
{
    return trim((string) (
        $_SESSION['sucursal_nombre'] ?? 'Sucursal'
    ));
}

function sucursal_asegurar_csrf(): string
{
    if (empty($_SESSION['sucursal_csrf'])) {
        $_SESSION['sucursal_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['sucursal_csrf'];
}

function sucursal_validar_csrf(string $token): bool
{
    $esperado = (string) ($_SESSION['sucursal_csrf'] ?? '');

    return $esperado !== ''
        && $token !== ''
        && hash_equals($esperado, $token);
}
