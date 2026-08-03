<?php
// Archivo: includes/permisos_helper.php
// Permisos globales y por sucursal para auth_guard.php, sidebar.php
// y permisos_roles.php.

declare(strict_types=1);

require_once __DIR__ . '/super_admin_helper.php';

if (!function_exists('permisos_catalogo_base')) {
    function permisos_catalogo_base(): array
    {
        return [
            'dashboard' => [
                'nombre' => 'Panel principal',
                'descripcion' => 'Resumen general y acceso inicial al sistema.',
                'ruta' => 'dashboard.php',
                'grupo' => 'General',
                'icono' => 'fa-gauge-high',
                'tipo_acceso' => 'esencial',
                'orden' => 10,
            ],
            'socios' => [
                'nombre' => 'Socios',
                'descripcion' => 'Directorio de personas registradas, membresías, actividad y edición de datos personales.',
                'ruta' => 'socios.php',
                'grupo' => 'Socios',
                'icono' => 'fa-user-group',
                'tipo_acceso' => 'asignable',
                'orden' => 15,
            ],
            'expediente_salud' => [
                'nombre' => 'Expediente de salud',
                'descripcion' => 'Consulta de expedientes y seguimiento administrativo de los socios.',
                'ruta' => 'expediente_salud.php',
                'grupo' => 'Socios',
                'icono' => 'fa-heart-pulse',
                'tipo_acceso' => 'asignable',
                'orden' => 18,
            ],
            'inscripciones' => [
                'nombre' => 'Inscripciones',
                'descripcion' => 'Altas, renovaciones y administración de membresías.',
                'ruta' => 'inscripciones.php',
                'grupo' => 'Socios',
                'icono' => 'fa-id-card',
                'tipo_acceso' => 'asignable',
                'orden' => 20,
            ],
            'planes' => [
                'nombre' => 'Planes',
                'descripcion' => 'Administración del catálogo de membresías, precios y disponibilidad por sucursal.',
                'ruta' => 'planes.php',
                'grupo' => 'Socios',
                'icono' => 'fa-layer-group',
                'tipo_acceso' => 'solo_admin',
                'orden' => 25,
            ],
            'asistencias' => [
                'nombre' => 'Asistencias',
                'descripcion' => 'Registro y consulta de accesos de socios.',
                'ruta' => 'asistencias.php',
                'grupo' => 'Socios',
                'icono' => 'fa-fingerprint',
                'tipo_acceso' => 'asignable',
                'orden' => 30,
            ],
            'ventas' => [
                'nombre' => 'Venta de productos',
                'descripcion' => 'Cobro y registro de ventas de productos.',
                'ruta' => 'ventas.php',
                'grupo' => 'Ventas y caja',
                'icono' => 'fa-cart-shopping',
                'tipo_acceso' => 'asignable',
                'orden' => 40,
            ],
            'historial_ventas' => [
                'nombre' => 'Historial de ventas',
                'descripcion' => 'Consulta de ventas, cancelaciones y devoluciones.',
                'ruta' => 'historial_ventas.php',
                'grupo' => 'Ventas y caja',
                'icono' => 'fa-receipt',
                'tipo_acceso' => 'asignable',
                'orden' => 50,
            ],
            'corte_caja' => [
                'nombre' => 'Corte de caja',
                'descripcion' => 'Apertura, cierre y revisión de operaciones del turno.',
                'ruta' => 'corte_caja.php',
                'grupo' => 'Ventas y caja',
                'icono' => 'fa-coins',
                'tipo_acceso' => 'asignable',
                'orden' => 60,
            ],
            'productos' => [
                'nombre' => 'Productos',
                'descripcion' => 'Catálogo, precios y administración de productos.',
                'ruta' => 'productos.php',
                'grupo' => 'Inventario',
                'icono' => 'fa-box',
                'tipo_acceso' => 'asignable',
                'orden' => 70,
            ],
            'historial_stock' => [
                'nombre' => 'Historial de stock',
                'descripcion' => 'Consulta de entradas, salidas y ajustes de inventario.',
                'ruta' => 'historial_stock.php',
                'grupo' => 'Inventario',
                'icono' => 'fa-clock-rotate-left',
                'tipo_acceso' => 'asignable',
                'orden' => 80,
            ],
            'clases' => [
                'nombre' => 'Clases',
                'descripcion' => 'Creación y administración de clases y horarios.',
                'ruta' => 'clases.php',
                'grupo' => 'Clases',
                'icono' => 'fa-dumbbell',
                'tipo_acceso' => 'asignable',
                'orden' => 90,
            ],
            'inscripciones_clases' => [
                'nombre' => 'Socios por clase',
                'descripcion' => 'Asignación y consulta de socios inscritos en clases.',
                'ruta' => 'inscripciones_clases.php',
                'grupo' => 'Clases',
                'icono' => 'fa-user-check',
                'tipo_acceso' => 'asignable',
                'orden' => 100,
            ],
            'reportes' => [
                'nombre' => 'Reportes',
                'descripcion' => 'Indicadores y consultas generales del sistema.',
                'ruta' => 'reportes.php',
                'grupo' => 'Administración',
                'icono' => 'fa-chart-column',
                'tipo_acceso' => 'asignable',
                'orden' => 110,
            ],
            'notificaciones' => [
                'nombre' => 'Notificaciones',
                'descripcion' => 'Envío y administración de comunicaciones.',
                'ruta' => 'notificaciones.php',
                'grupo' => 'Administración',
                'icono' => 'fa-bell',
                'tipo_acceso' => 'asignable',
                'orden' => 120,
            ],
            'solicitudes_usuarios' => [
                'nombre' => 'Solicitudes de usuarios',
                'descripcion' => 'Aprobación y asignación de cuentas del personal.',
                'ruta' => 'solicitudes_usuarios.php',
                'grupo' => 'Administración',
                'icono' => 'fa-user-clock',
                'tipo_acceso' => 'solo_admin',
                'orden' => 130,
            ],
            'sucursales' => [
                'nombre' => 'Sucursales',
                'descripcion' => 'Administración de sedes, personal y configuración técnica.',
                'ruta' => 'sucursales.php',
                'grupo' => 'Administración',
                'icono' => 'fa-building',
                'tipo_acceso' => 'solo_admin',
                'orden' => 135,
            ],
            'configuracion' => [
                'nombre' => 'Configuración',
                'descripcion' => 'Catálogos y ajustes corporativos del sistema.',
                'ruta' => 'configuracion.php',
                'grupo' => 'Administración',
                'icono' => 'fa-gear',
                'tipo_acceso' => 'solo_admin',
                'orden' => 140,
            ],
            'servicio_plataforma' => [
                'nombre' => 'Servicio de plataforma',
                'descripcion' => 'Vigencia, renovaciones y precio del servicio contratado.',
                'ruta' => 'servicio_plataforma.php',
                'grupo' => 'Administración',
                'icono' => 'fa-calendar-check',
                'tipo_acceso' => 'solo_admin',
                'orden' => 145,
            ],
            'permisos_roles' => [
                'nombre' => 'Control de acceso',
                'descripcion' => 'Módulos disponibles para cada rol y sucursal.',
                'ruta' => 'permisos_roles.php',
                'grupo' => 'Administración',
                'icono' => 'fa-key',
                'tipo_acceso' => 'solo_admin',
                'orden' => 150,
            ],
            'legal' => [
                'nombre' => 'Aviso y términos',
                'descripcion' => 'Consulta y aceptación de documentos legales.',
                'ruta' => 'legal.php',
                'grupo' => 'General',
                'icono' => 'fa-shield-halved',
                'tipo_acceso' => 'esencial',
                'orden' => 160,
            ],
            'mi_perfil' => [
                'nombre' => 'Mi perfil',
                'descripcion' => 'Información y seguridad de la cuenta personal.',
                'ruta' => 'mi_perfil.php',
                'grupo' => 'General',
                'icono' => 'fa-circle-user',
                'tipo_acceso' => 'esencial',
                'orden' => 170,
            ],
        ];
    }
}

if (!function_exists('permisos_roles_configurables')) {
    function permisos_roles_configurables(): array
    {
        return [
            'recepcionista' => 'Recepcionista',
            'entrenador' => 'Entrenador',
        ];
    }
}

if (!function_exists('permisos_es_admin')) {
    function permisos_es_admin(string $rol): bool
    {
        return rol_es_administrativo($rol);
    }
}

if (!function_exists('permisos_modulo_por_pagina')) {
    function permisos_modulo_por_pagina(string $pagina): ?string
    {
        $pagina = basename(trim($pagina));

        $mapa = [
            'dashboard.php' => 'dashboard',
            'socios.php' => 'socios',
            'expediente_salud.php' => 'expediente_salud',
            'expediente_salud_imprimir.php' => 'expediente_salud',
            'inscripciones.php' => 'inscripciones',
            'planes.php' => 'planes',
            'asistencias.php' => 'asistencias',
            'ventas.php' => 'ventas',
            'historial_ventas.php' => 'historial_ventas',
            'corte_caja.php' => 'corte_caja',
            'corte_caja_detalle.php' => 'corte_caja',
            'productos.php' => 'productos',
            'inventario.php' => 'dashboard',
            'historial_stock.php' => 'historial_stock',
            'clases.php' => 'clases',
            'inscripciones_clases.php' => 'inscripciones_clases',
            'reportes.php' => 'reportes',
            'notificaciones.php' => 'notificaciones',
            'solicitudes_usuarios.php' => 'solicitudes_usuarios',
            'sucursales.php' => 'sucursales',
            'configuracion.php' => 'configuracion',
            'permisos_roles.php' => 'permisos_roles',
            'servicio_plataforma.php' => 'servicio_plataforma',
            'legal.php' => 'legal',
            'mi_perfil.php' => 'mi_perfil',
        ];

        return $mapa[$pagina] ?? null;
    }
}

if (!function_exists('permisos_nombre_modulo')) {
    function permisos_nombre_modulo(string $clave): string
    {
        $catalogo = permisos_catalogo_base();

        return (string) (
            $catalogo[$clave]['nombre']
            ?? 'el módulo solicitado'
        );
    }
}

if (!function_exists('permisos_tabla_existe')) {
    function permisos_tabla_existe(
        ?mysqli $db,
        string $tabla
    ): bool {
        if (!$db || !preg_match('/^[a-zA-Z0-9_]+$/', $tabla)) {
            return false;
        }

        $tablaEscapada = $db->real_escape_string($tabla);
        $resultado = $db->query(
            "SHOW TABLES LIKE '{$tablaEscapada}'"
        );

        return $resultado && $resultado->num_rows > 0;
    }
}

if (!function_exists('permisos_tablas_disponibles')) {
    function permisos_tablas_disponibles(
        ?mysqli $db,
        bool $incluirSucursal = false
    ): bool {
        if (
            !permisos_tabla_existe($db, 'modulos_sistema')
            || !permisos_tabla_existe($db, 'roles_modulos')
        ) {
            return false;
        }

        if (
            $incluirSucursal
            && !permisos_tabla_existe(
                $db,
                'roles_modulos_sucursales'
            )
        ) {
            return false;
        }

        return true;
    }
}

if (!function_exists('permisos_mapa_predeterminado')) {
    function permisos_mapa_predeterminado(string $rol): array
    {
        $catalogo = permisos_catalogo_base();
        $mapa = array_fill_keys(array_keys($catalogo), false);
        $rol = strtolower(trim($rol));

        if (permisos_es_admin($rol)) {
            return array_fill_keys(array_keys($catalogo), true);
        }

        foreach ($catalogo as $clave => $modulo) {
            if ($modulo['tipo_acceso'] === 'esencial') {
                $mapa[$clave] = true;
            }
        }

        $predeterminados = [
            'recepcionista' => [
                'inscripciones',
                'asistencias',
                'ventas',
                'historial_ventas',
                'reportes',
            ],
            'entrenador' => [
                'asistencias',
                'clases',
                'inscripciones_clases',
            ],
        ];

        foreach ($predeterminados[$rol] ?? [] as $clave) {
            if (array_key_exists($clave, $mapa)) {
                $mapa[$clave] = true;
            }
        }

        return $mapa;
    }
}

if (!function_exists('permisos_sucursal_sesion')) {
    function permisos_sucursal_sesion(?int $sucursalId = null): int
    {
        if ($sucursalId !== null && $sucursalId > 0) {
            return $sucursalId;
        }

        return (int) ($_SESSION['sucursal_id'] ?? 0);
    }
}

if (!function_exists('permisos_modulos_asignables')) {
    function permisos_modulos_asignables(?mysqli $db): array
    {
        $catalogo = permisos_catalogo_base();

        if (!permisos_tablas_disponibles($db)) {
            return array_filter(
                $catalogo,
                static function (array $modulo): bool {
                    return $modulo['tipo_acceso'] === 'asignable';
                }
            );
        }

        $resultado = $db->query(
            "SELECT clave, nombre, descripcion, ruta, grupo, icono,
                    tipo_acceso, orden
             FROM modulos_sistema
             WHERE activo = 1
               AND tipo_acceso = 'asignable'
             ORDER BY orden ASC, nombre ASC"
        );

        if (!$resultado) {
            return array_filter(
                $catalogo,
                static function (array $modulo): bool {
                    return $modulo['tipo_acceso'] === 'asignable';
                }
            );
        }

        $modulos = [];

        while ($fila = $resultado->fetch_assoc()) {
            $clave = trim((string) ($fila['clave'] ?? ''));

            if ($clave === '') {
                continue;
            }

            $base = $catalogo[$clave] ?? [];
            $modulos[$clave] = [
                'nombre' => (string) (
                    $fila['nombre']
                    ?? $base['nombre']
                    ?? $clave
                ),
                'descripcion' => (string) (
                    $fila['descripcion']
                    ?? $base['descripcion']
                    ?? ''
                ),
                'ruta' => (string) (
                    $fila['ruta']
                    ?? $base['ruta']
                    ?? ''
                ),
                'grupo' => (string) (
                    $fila['grupo']
                    ?? $base['grupo']
                    ?? 'Otros'
                ),
                'icono' => (string) (
                    $fila['icono']
                    ?? $base['icono']
                    ?? 'fa-circle'
                ),
                'tipo_acceso' => 'asignable',
                'orden' => (int) (
                    $fila['orden']
                    ?? $base['orden']
                    ?? 0
                ),
            ];
        }

        return $modulos;
    }
}

if (!function_exists('permisos_sincronizar_sucursal')) {
    function permisos_sincronizar_sucursal(
        mysqli $db,
        int $sucursalId
    ): void {
        if (
            $sucursalId <= 0
            || !permisos_tablas_disponibles($db, true)
        ) {
            return;
        }

        $roles = permisos_roles_configurables();
        $asignables = permisos_modulos_asignables($db);

        $stmtModulo = $db->prepare(
            "SELECT id
             FROM modulos_sistema
             WHERE clave = ?
               AND tipo_acceso = 'asignable'
               AND activo = 1
             LIMIT 1"
        );

        $stmtGlobal = $db->prepare(
            "SELECT permitido
             FROM roles_modulos
             WHERE rol = ?
               AND modulo_id = ?
             LIMIT 1"
        );

        $stmtInsertar = $db->prepare(
            "INSERT IGNORE INTO roles_modulos_sucursales
                (sucursal_id, rol, modulo_id, permitido,
                 actualizado_por, actualizado_en)
             VALUES (?, ?, ?, ?, NULL, NOW())"
        );

        if (!$stmtModulo || !$stmtGlobal || !$stmtInsertar) {
            throw new RuntimeException(
                'No fue posible preparar los permisos de la sucursal.'
            );
        }

        foreach ($roles as $rol => $_nombreRol) {
            $predeterminados = permisos_mapa_predeterminado($rol);

            foreach ($asignables as $clave => $_modulo) {
                $stmtModulo->bind_param('s', $clave);
                $stmtModulo->execute();
                $filaModulo = $stmtModulo
                    ->get_result()
                    ->fetch_assoc();

                if (!$filaModulo) {
                    continue;
                }

                $moduloId = (int) $filaModulo['id'];
                $permitido = !empty($predeterminados[$clave])
                    ? 1
                    : 0;

                $stmtGlobal->bind_param(
                    'si',
                    $rol,
                    $moduloId
                );
                $stmtGlobal->execute();
                $filaGlobal = $stmtGlobal
                    ->get_result()
                    ->fetch_assoc();

                if ($filaGlobal) {
                    $permitido = (int) (
                        $filaGlobal['permitido'] ?? 0
                    );
                }

                $stmtInsertar->bind_param(
                    'isii',
                    $sucursalId,
                    $rol,
                    $moduloId,
                    $permitido
                );
                $stmtInsertar->execute();
            }
        }

        $stmtModulo->close();
        $stmtGlobal->close();
        $stmtInsertar->close();
    }
}

if (!function_exists('permisos_obtener_mapa_rol')) {
    function permisos_obtener_mapa_rol(
        ?mysqli $db,
        string $rol,
        ?int $sucursalId = null,
        bool $forzarGlobal = false
    ): array {
        $rol = strtolower(trim($rol));
        $catalogo = permisos_catalogo_base();

        if (permisos_es_admin($rol)) {
            return array_fill_keys(array_keys($catalogo), true);
        }

        if (!array_key_exists($rol, permisos_roles_configurables())) {
            return array_fill_keys(array_keys($catalogo), false);
        }

        $mapa = permisos_mapa_predeterminado($rol);

        /*
         * El expediente de salud solo puede asignarse a recepción.
         * El entrenador conserva su agenda exclusiva aunque exista un
         * permiso incorrecto o antiguo en la base de datos.
         */
        if ($rol === 'entrenador' && array_key_exists('expediente_salud', $mapa)) {
            $mapa['expediente_salud'] = false;
        }

        if (!permisos_tablas_disponibles($db)) {
            return $mapa;
        }

        $sucursalId = permisos_sucursal_sesion($sucursalId);
        $usarSucursal = !$forzarGlobal
            && $sucursalId > 0
            && permisos_tablas_disponibles($db, true);

        if ($usarSucursal) {
            try {
                permisos_sincronizar_sucursal(
                    $db,
                    $sucursalId
                );
            } catch (Throwable $error) {
                error_log(
                    '[Permisos sucursal] '
                    . $error->getMessage()
                );
            }

            $stmt = $db->prepare(
                "SELECT
                    m.clave,
                    m.tipo_acceso,
                    rms.permitido AS permitido_sucursal,
                    rm.permitido AS permitido_global
                 FROM modulos_sistema m
                 LEFT JOIN roles_modulos rm
                   ON rm.modulo_id = m.id
                  AND rm.rol = ?
                 LEFT JOIN roles_modulos_sucursales rms
                   ON rms.modulo_id = m.id
                  AND rms.rol = ?
                  AND rms.sucursal_id = ?
                 WHERE m.activo = 1"
            );

            if (!$stmt) {
                return $mapa;
            }

            $stmt->bind_param(
                'ssi',
                $rol,
                $rol,
                $sucursalId
            );
        } else {
            $stmt = $db->prepare(
                "SELECT
                    m.clave,
                    m.tipo_acceso,
                    rm.permitido AS permitido_global
                 FROM modulos_sistema m
                 LEFT JOIN roles_modulos rm
                   ON rm.modulo_id = m.id
                  AND rm.rol = ?
                 WHERE m.activo = 1"
            );

            if (!$stmt) {
                return $mapa;
            }

            $stmt->bind_param('s', $rol);
        }

        $stmt->execute();
        $resultado = $stmt->get_result();

        while ($resultado && $fila = $resultado->fetch_assoc()) {
            $clave = (string) ($fila['clave'] ?? '');
            $tipo = (string) ($fila['tipo_acceso'] ?? '');

            if (!array_key_exists($clave, $mapa)) {
                continue;
            }

            if ($tipo === 'esencial') {
                $mapa[$clave] = true;
                continue;
            }

            if ($rol === 'entrenador' && $clave === 'expediente_salud') {
                $mapa[$clave] = false;
                continue;
            }

            if ($tipo !== 'asignable') {
                $mapa[$clave] = false;
                continue;
            }

            if (
                $usarSucursal
                && array_key_exists(
                    'permitido_sucursal',
                    $fila
                )
                && $fila['permitido_sucursal'] !== null
            ) {
                $mapa[$clave] =
                    (int) $fila['permitido_sucursal'] === 1;
            } elseif (
                array_key_exists('permitido_global', $fila)
                && $fila['permitido_global'] !== null
            ) {
                $mapa[$clave] =
                    (int) $fila['permitido_global'] === 1;
            }
        }

        $stmt->close();

        return $mapa;
    }
}

if (!function_exists('permisos_rol_tiene_modulo')) {
    function permisos_rol_tiene_modulo(
        ?mysqli $db,
        string $rol,
        string $clave,
        ?int $sucursalId = null,
        bool $forzarGlobal = false
    ): bool {
        $mapa = permisos_obtener_mapa_rol(
            $db,
            $rol,
            $sucursalId,
            $forzarGlobal
        );

        return !empty($mapa[$clave]);
    }
}

if (!function_exists('permisos_validar_guardado')) {
    function permisos_validar_guardado(
        ?mysqli $db,
        string $rol,
        array $seleccionados
    ): array {
        $rol = strtolower(trim($rol));
        $rolesValidos = permisos_roles_configurables();

        if (!array_key_exists($rol, $rolesValidos)) {
            throw new InvalidArgumentException(
                'El rol seleccionado no es configurable.'
            );
        }

        if (!permisos_tablas_disponibles($db)) {
            throw new RuntimeException(
                'Primero debes instalar las tablas del módulo de permisos.'
            );
        }

        $asignables = permisos_modulos_asignables($db);

        $seleccionValida = array_values(
            array_intersect(
                array_keys($asignables),
                array_map('strval', $seleccionados)
            )
        );

        if ($rol === 'entrenador') {
            $seleccionValida = array_values(array_filter(
                $seleccionValida,
                static function (string $clave): bool {
                    return $clave !== 'expediente_salud';
                }
            ));
        }

        return $seleccionValida;
    }
}

if (!function_exists('permisos_guardar_rol')) {
    function permisos_guardar_rol(
        mysqli $db,
        string $rol,
        array $seleccionados,
        int $administradorId
    ): void {
        $seleccionados = permisos_validar_guardado(
            $db,
            $rol,
            $seleccionados
        );
        $rol = strtolower(trim($rol));
        $asignables = permisos_modulos_asignables($db);

        $db->begin_transaction();

        try {
            $stmtModulo = $db->prepare(
                "SELECT id
                 FROM modulos_sistema
                 WHERE clave = ?
                   AND tipo_acceso = 'asignable'
                   AND activo = 1
                 LIMIT 1"
            );

            $stmtGlobal = $db->prepare(
                "INSERT INTO roles_modulos
                    (rol, modulo_id, permitido,
                     actualizado_por, actualizado_en)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    permitido = VALUES(permitido),
                    actualizado_por = VALUES(actualizado_por),
                    actualizado_en = NOW()"
            );

            $stmtSucursales = permisos_tablas_disponibles(
                $db,
                true
            )
                ? $db->prepare(
                    "INSERT INTO roles_modulos_sucursales
                        (sucursal_id, rol, modulo_id, permitido,
                         actualizado_por, actualizado_en)
                     SELECT
                        s.id, ?, ?, ?, ?, NOW()
                     FROM sucursales s
                     WHERE s.estado = 'activa'
                     ON DUPLICATE KEY UPDATE
                        permitido = VALUES(permitido),
                        actualizado_por = VALUES(actualizado_por),
                        actualizado_en = NOW()"
                )
                : null;

            if (!$stmtModulo || !$stmtGlobal) {
                throw new RuntimeException(
                    'No fue posible preparar la actualización global.'
                );
            }

            foreach ($asignables as $clave => $_modulo) {
                $stmtModulo->bind_param('s', $clave);
                $stmtModulo->execute();
                $fila = $stmtModulo
                    ->get_result()
                    ->fetch_assoc();

                if (!$fila) {
                    continue;
                }

                $moduloId = (int) $fila['id'];
                $permitido = in_array(
                    $clave,
                    $seleccionados,
                    true
                ) ? 1 : 0;

                $stmtGlobal->bind_param(
                    'siii',
                    $rol,
                    $moduloId,
                    $permitido,
                    $administradorId
                );

                if (!$stmtGlobal->execute()) {
                    throw new RuntimeException(
                        'No fue posible guardar todos los permisos globales.'
                    );
                }

                if ($stmtSucursales) {
                    $stmtSucursales->bind_param(
                        'siii',
                        $rol,
                        $moduloId,
                        $permitido,
                        $administradorId
                    );

                    if (!$stmtSucursales->execute()) {
                        throw new RuntimeException(
                            'No fue posible aplicar los permisos a todas las sucursales.'
                        );
                    }
                }
            }

            $stmtModulo->close();
            $stmtGlobal->close();

            if ($stmtSucursales) {
                $stmtSucursales->close();
            }

            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }
    }
}

if (!function_exists('permisos_guardar_rol_sucursal')) {
    function permisos_guardar_rol_sucursal(
        mysqli $db,
        int $sucursalId,
        string $rol,
        array $seleccionados,
        int $administradorId
    ): void {
        if ($sucursalId <= 0) {
            throw new InvalidArgumentException(
                'La sucursal seleccionada no es válida.'
            );
        }

        if (!permisos_tablas_disponibles($db, true)) {
            throw new RuntimeException(
                'Ejecuta la migración de permisos multisucursal.'
            );
        }

        $seleccionados = permisos_validar_guardado(
            $db,
            $rol,
            $seleccionados
        );
        $rol = strtolower(trim($rol));
        $asignables = permisos_modulos_asignables($db);

        $db->begin_transaction();

        try {
            $stmtSucursal = $db->prepare(
                "SELECT id
                 FROM sucursales
                 WHERE id = ?
                   AND estado = 'activa'
                 LIMIT 1"
            );

            $stmtModulo = $db->prepare(
                "SELECT id
                 FROM modulos_sistema
                 WHERE clave = ?
                   AND tipo_acceso = 'asignable'
                   AND activo = 1
                 LIMIT 1"
            );

            $stmtGuardar = $db->prepare(
                "INSERT INTO roles_modulos_sucursales
                    (sucursal_id, rol, modulo_id, permitido,
                     actualizado_por, actualizado_en)
                 VALUES (?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    permitido = VALUES(permitido),
                    actualizado_por = VALUES(actualizado_por),
                    actualizado_en = NOW()"
            );

            if (
                !$stmtSucursal
                || !$stmtModulo
                || !$stmtGuardar
            ) {
                throw new RuntimeException(
                    'No fue posible preparar los permisos de la sucursal.'
                );
            }

            $stmtSucursal->bind_param('i', $sucursalId);
            $stmtSucursal->execute();

            if (!$stmtSucursal->get_result()->fetch_assoc()) {
                throw new RuntimeException(
                    'La sucursal ya no está activa.'
                );
            }

            foreach ($asignables as $clave => $_modulo) {
                $stmtModulo->bind_param('s', $clave);
                $stmtModulo->execute();
                $fila = $stmtModulo
                    ->get_result()
                    ->fetch_assoc();

                if (!$fila) {
                    continue;
                }

                $moduloId = (int) $fila['id'];
                $permitido = in_array(
                    $clave,
                    $seleccionados,
                    true
                ) ? 1 : 0;

                $stmtGuardar->bind_param(
                    'isiii',
                    $sucursalId,
                    $rol,
                    $moduloId,
                    $permitido,
                    $administradorId
                );

                if (!$stmtGuardar->execute()) {
                    throw new RuntimeException(
                        'No fue posible guardar todos los permisos de la sucursal.'
                    );
                }
            }

            $stmtSucursal->close();
            $stmtModulo->close();
            $stmtGuardar->close();
            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }
    }
}

if (!function_exists('permisos_restaurar_sucursal_desde_global')) {
    function permisos_restaurar_sucursal_desde_global(
        mysqli $db,
        int $sucursalId,
        string $rol,
        int $administradorId
    ): void {
        $mapaGlobal = permisos_obtener_mapa_rol(
            $db,
            $rol,
            null,
            true
        );

        $seleccionados = [];

        foreach ($mapaGlobal as $clave => $permitido) {
            if ($permitido) {
                $seleccionados[] = $clave;
            }
        }

        permisos_guardar_rol_sucursal(
            $db,
            $sucursalId,
            $rol,
            $seleccionados,
            $administradorId
        );
    }
}
