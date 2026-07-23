<?php
// Archivo: includes/super_admin_helper.php
// Utilidades centrales para distinguir el rol real del rol operativo.

declare(strict_types=1);

/**
 * Normaliza los alias de roles utilizados por el sistema.
 *
 * Esta función se declara directamente y no dentro de function_exists(),
 * porque super_admin_helper.php siempre se carga mediante require_once.
 * Así PHP e Intelephense reconocen la función como disponible.
 */
function rol_normalizar_sistema(?string $rol): string
{
    $rol = strtolower(trim((string) $rol));

    return $rol === 'administrador'
        ? 'admin'
        : $rol;
}

/** Devuelve el rol general real conservado en la sesión. */
function rol_base_real_sesion(): string
{
    return rol_normalizar_sistema((string) (
        $_SESSION['user_rol_base']
        ?? $_SESSION['user_rol']
        ?? ''
    ));
}

/** Indica si un rol, o el rol de la sesión, es superadministrador. */
function rol_es_super_administrador(?string $rol = null): bool
{
    $rol = $rol === null
        ? rol_base_real_sesion()
        : rol_normalizar_sistema($rol);

    return $rol === 'super_administrador';
}

/** Indica si el rol tiene autoridad administrativa completa. */
function rol_es_administrativo(?string $rol): bool
{
    return in_array(
        rol_normalizar_sistema($rol),
        ['admin', 'super_administrador'],
        true
    );
}

/**
 * Convierte el rol general en el rol operativo usado por módulos antiguos.
 */
function rol_operativo_desde_base(?string $rol): string
{
    $rol = rol_normalizar_sistema($rol);

    return $rol === 'super_administrador'
        ? 'admin'
        : $rol;
}

/*
 * configuracion_context.php puede declarar esta función en instalaciones
 * anteriores. Se conserva esta única comprobación para evitar duplicados.
 */
if (!function_exists('configuracion_rol_base')) {
    function configuracion_rol_base(): string
    {
        return rol_operativo_desde_base(
            rol_base_real_sesion()
        );
    }
}
