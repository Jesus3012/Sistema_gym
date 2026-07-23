<?php
// Archivo: includes/sucursal_context.php
// Contexto seguro de la sucursal activa para todo el sistema.

declare(strict_types=1);

require_once __DIR__ . '/super_admin_helper.php';

/**
 * Devuelve las sucursales activas disponibles para el usuario.
 * El superadministrador accede automáticamente a todas las sedes activas.
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
            COALESCE(us.es_principal, s.es_matriz, 0) AS es_principal,
            CASE
                WHEN u.rol = 'super_administrador' THEN 1
                ELSE COALESCE(us.puede_operar_caja, 0)
            END AS puede_operar_caja,
            CASE
                WHEN u.rol = 'super_administrador' THEN 'admin'
                ELSE COALESCE(us.rol_sucursal, u.rol)
            END AS rol_efectivo,
            u.rol AS rol_base
        FROM usuarios u
        INNER JOIN sucursales s
            ON s.estado = 'activa'
        LEFT JOIN usuarios_sucursales us
            ON us.usuario_id = u.id
           AND us.sucursal_id = s.id
        WHERE u.id = ?
          AND u.estado = 'activo'
          AND (
                u.rol = 'super_administrador'
                OR us.estado = 'activo'
          )
        ORDER BY
            CASE WHEN u.rol = 'super_administrador' THEN s.es_matriz ELSE COALESCE(us.es_principal, 0) END DESC,
            s.es_matriz DESC,
            s.nombre ASC
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

/** Busca una sucursal concreta dentro de las sedes permitidas. */
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
            COALESCE(us.es_principal, s.es_matriz, 0) AS es_principal,
            CASE
                WHEN u.rol = 'super_administrador' THEN 1
                ELSE COALESCE(us.puede_operar_caja, 0)
            END AS puede_operar_caja,
            CASE
                WHEN u.rol = 'super_administrador' THEN 'admin'
                ELSE COALESCE(us.rol_sucursal, u.rol)
            END AS rol_efectivo,
            u.rol AS rol_base
        FROM usuarios u
        INNER JOIN sucursales s
            ON s.id = ?
           AND s.estado = 'activa'
        LEFT JOIN usuarios_sucursales us
            ON us.usuario_id = u.id
           AND us.sucursal_id = s.id
        WHERE u.id = ?
          AND u.estado = 'activo'
          AND (
                u.rol = 'super_administrador'
                OR us.estado = 'activo'
          )
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException(
            'No fue posible validar la sucursal solicitada.'
        );
    }

    $stmt->bind_param('ii', $sucursalId, $usuarioId);
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

/** Guarda únicamente datos confiables obtenidos desde la BD. */
function sucursal_guardar_en_sesion(array $sucursal): void
{
    $rolBaseBd = rol_normalizar_sistema((string) (
        $sucursal['rol_base']
        ?? $_SESSION['user_rol_base']
        ?? $_SESSION['user_rol']
        ?? 'recepcionista'
    ));

    $_SESSION['user_rol_base'] = $rolBaseBd;
    $_SESSION['sucursal_id'] = (int) $sucursal['id'];
    $_SESSION['sucursal_clave'] = (string) $sucursal['clave'];
    $_SESSION['sucursal_nombre'] = (string) $sucursal['nombre'];
    $_SESSION['sucursal_zona_horaria'] = (string) (
        $sucursal['zona_horaria'] ?? 'America/Mexico_City'
    );
    $_SESSION['sucursal_puede_operar_caja'] = rol_es_super_administrador($rolBaseBd)
        ? 1
        : (int) ($sucursal['puede_operar_caja'] ?? 0);

    $rolEfectivo = (string) (
        $sucursal['rol_efectivo']
        ?? $rolBaseBd
    );

    $_SESSION['user_rol'] = rol_es_super_administrador($rolBaseBd)
        ? 'admin'
        : rol_operativo_desde_base($rolEfectivo);

    date_default_timezone_set(
        $_SESSION['sucursal_zona_horaria']
    );
}

/** Inicializa o revalida la sucursal activa en cada petición protegida. */
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

/** Comprueba el rol general directamente desde la base de datos. */
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

    return rol_es_administrativo((string) ($fila['rol'] ?? ''));
}

/** Activa el resumen global sin cambiar la sucursal operativa. */
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

function sucursal_desactivar_vista_global(): void
{
    unset($_SESSION['dashboard_vista_global']);
}

function sucursal_dashboard_vista_global(): bool
{
    return isset($_SESSION['dashboard_vista_global'])
        && (int) $_SESSION['dashboard_vista_global'] === 1;
}

/** Cambia la sede activa validando el acceso. */
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
