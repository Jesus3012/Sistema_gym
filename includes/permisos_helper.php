<?php
// Archivo: includes/permisos_helper.php
// Catálogo y funciones compartidas para auth_guard.php, sidebar.php y permisos_roles.php.

declare(strict_types=1);

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
            'inscripciones' => [
                'nombre' => 'Inscripciones',
                'descripcion' => 'Altas, renovaciones y administración de membresías.',
                'ruta' => 'inscripciones.php',
                'grupo' => 'Socios',
                'icono' => 'fa-id-card',
                'tipo_acceso' => 'asignable',
                'orden' => 20,
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
                'descripcion' => 'Consulta de ventas y operaciones realizadas.',
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
                'descripcion' => 'Catálogo, existencias, precios y movimientos.',
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
                'nombre' => 'Inscripciones a clases',
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
                'descripcion' => 'Aprobación y rechazo de cuentas del personal.',
                'ruta' => 'solicitudes_usuarios.php',
                'grupo' => 'Administración',
                'icono' => 'fa-user-clock',
                'tipo_acceso' => 'solo_admin',
                'orden' => 130,
            ],
            'configuracion' => [
                'nombre' => 'Configuración',
                'descripcion' => 'Configuraciones generales y credenciales del sistema.',
                'ruta' => 'configuracion.php',
                'grupo' => 'Administración',
                'icono' => 'fa-gear',
                'tipo_acceso' => 'solo_admin',
                'orden' => 140,
            ],
            'permisos_roles' => [
                'nombre' => 'Permisos por rol',
                'descripcion' => 'Administración de módulos disponibles para cada rol.',
                'ruta' => 'permisos_roles.php',
                'grupo' => 'Administración',
                'icono' => 'fa-user-shield',
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
        return in_array(
            strtolower(trim($rol)),
            ['admin', 'administrador'],
            true
        );
    }
}

if (!function_exists('permisos_modulo_por_pagina')) {
    function permisos_modulo_por_pagina(string $pagina): ?string
    {
        $pagina = basename(trim($pagina));

        $mapa = [
            'dashboard.php' => 'dashboard',
            'inscripciones.php' => 'inscripciones',
            'asistencias.php' => 'asistencias',
            'ventas.php' => 'ventas',
            'historial_ventas.php' => 'historial_ventas',
            'corte_caja.php' => 'corte_caja',
            'corte_caja_detalle.php' => 'corte_caja',
            'productos.php' => 'productos',
            'historial_stock.php' => 'historial_stock',
            'clases.php' => 'clases',
            'inscripciones_clases.php' => 'inscripciones_clases',
            'reportes.php' => 'reportes',
            'notificaciones.php' => 'notificaciones',
            'solicitudes_usuarios.php' => 'solicitudes_usuarios',
            'configuracion.php' => 'configuracion',
            'permisos_roles.php' => 'permisos_roles',
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

if (!function_exists('permisos_tablas_disponibles')) {
    function permisos_tablas_disponibles(?mysqli $db): bool
    {
        if (!$db) {
            return false;
        }

        $modulos = $db->query(
            "SHOW TABLES LIKE 'modulos_sistema'"
        );
        $roles = $db->query(
            "SHOW TABLES LIKE 'roles_modulos'"
        );

        return $modulos
            && $roles
            && $modulos->num_rows > 0
            && $roles->num_rows > 0;
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
                'clases',
                'inscripciones_clases',
                'asistencias',
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

if (!function_exists('permisos_obtener_mapa_rol')) {
    function permisos_obtener_mapa_rol(
        ?mysqli $db,
        string $rol
    ): array {
        $rol = strtolower(trim($rol));
        $catalogo = permisos_catalogo_base();

        if (permisos_es_admin($rol)) {
            return array_fill_keys(array_keys($catalogo), true);
        }

        if (!array_key_exists($rol, permisos_roles_configurables())) {
            return array_fill_keys(array_keys($catalogo), false);
        }

        if (!permisos_tablas_disponibles($db)) {
            return permisos_mapa_predeterminado($rol);
        }

        $mapa = array_fill_keys(array_keys($catalogo), false);

        foreach ($catalogo as $clave => $modulo) {
            if ($modulo['tipo_acceso'] === 'esencial') {
                $mapa[$clave] = true;
            }
        }

        $stmt = $db->prepare(
            "SELECT m.clave, m.tipo_acceso, COALESCE(rm.permitido, 0) AS permitido
             FROM modulos_sistema m
             LEFT JOIN roles_modulos rm
               ON rm.modulo_id = m.id
              AND rm.rol = ?
             WHERE m.activo = 1"
        );

        if (!$stmt) {
            return permisos_mapa_predeterminado($rol);
        }

        $stmt->bind_param('s', $rol);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($result && $fila = $result->fetch_assoc()) {
            $clave = (string) ($fila['clave'] ?? '');
            $tipo = (string) ($fila['tipo_acceso'] ?? '');

            if (!array_key_exists($clave, $mapa)) {
                continue;
            }

            if ($tipo === 'esencial') {
                $mapa[$clave] = true;
            } elseif ($tipo === 'asignable') {
                $mapa[$clave] = (int) ($fila['permitido'] ?? 0) === 1;
            } else {
                $mapa[$clave] = false;
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
        string $clave
    ): bool {
        $mapa = permisos_obtener_mapa_rol($db, $rol);

        return !empty($mapa[$clave]);
    }
}

if (!function_exists('permisos_modulos_asignables')) {
    function permisos_modulos_asignables(?mysqli $db): array
    {
        $catalogo = permisos_catalogo_base();
        $modulos = [];

        if (permisos_tablas_disponibles($db)) {
            $result = $db->query(
                "SELECT clave, nombre, descripcion, ruta, grupo, icono, orden
                 FROM modulos_sistema
                 WHERE activo = 1
                   AND tipo_acceso = 'asignable'
                 ORDER BY orden ASC, nombre ASC"
            );

            if ($result) {
                while ($fila = $result->fetch_assoc()) {
                    $clave = (string) ($fila['clave'] ?? '');

                    if ($clave === '') {
                        continue;
                    }

                    $modulos[$clave] = [
                        'nombre' => (string) ($fila['nombre'] ?? $clave),
                        'descripcion' => (string) ($fila['descripcion'] ?? ''),
                        'ruta' => (string) ($fila['ruta'] ?? ''),
                        'grupo' => (string) ($fila['grupo'] ?? 'Otros'),
                        'icono' => (string) ($fila['icono'] ?? 'fa-circle'),
                        'tipo_acceso' => 'asignable',
                        'orden' => (int) ($fila['orden'] ?? 0),
                    ];
                }

                return $modulos;
            }
        }

        foreach ($catalogo as $clave => $modulo) {
            if ($modulo['tipo_acceso'] === 'asignable') {
                $modulos[$clave] = $modulo;
            }
        }

        uasort(
            $modulos,
            static fn(array $a, array $b): int =>
                ((int) $a['orden']) <=> ((int) $b['orden'])
        );

        return $modulos;
    }
}

if (!function_exists('permisos_guardar_rol')) {
    function permisos_guardar_rol(
        mysqli $db,
        string $rol,
        array $seleccionados,
        int $administradorId
    ): void {
        $rol = strtolower(trim($rol));
        $rolesValidos = permisos_roles_configurables();

        if (!array_key_exists($rol, $rolesValidos)) {
            throw new InvalidArgumentException(
                'El rol seleccionado no es configurable.'
            );
        }

        if (!permisos_tablas_disponibles($db)) {
            throw new RuntimeException(
                'Primero debes ejecutar la migración del módulo de permisos.'
            );
        }

        $asignables = permisos_modulos_asignables($db);
        $seleccionados = array_values(
            array_intersect(
                array_keys($asignables),
                array_map('strval', $seleccionados)
            )
        );

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

            $stmtGuardar = $db->prepare(
                "INSERT INTO roles_modulos
                    (rol, modulo_id, permitido, actualizado_por, actualizado_en)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    permitido = VALUES(permitido),
                    actualizado_por = VALUES(actualizado_por),
                    actualizado_en = NOW()"
            );

            if (!$stmtModulo || !$stmtGuardar) {
                throw new RuntimeException(
                    'No fue posible preparar la actualización de permisos.'
                );
            }

            foreach ($asignables as $clave => $_modulo) {
                $stmtModulo->bind_param('s', $clave);
                $stmtModulo->execute();
                $result = $stmtModulo->get_result();
                $fila = $result ? $result->fetch_assoc() : null;

                if (!$fila) {
                    continue;
                }

                $moduloId = (int) $fila['id'];
                $permitido = in_array($clave, $seleccionados, true) ? 1 : 0;

                $stmtGuardar->bind_param(
                    'siii',
                    $rol,
                    $moduloId,
                    $permitido,
                    $administradorId
                );

                if (!$stmtGuardar->execute()) {
                    throw new RuntimeException(
                        'No fue posible guardar todos los permisos.'
                    );
                }
            }

            $stmtModulo->close();
            $stmtGuardar->close();
            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }
    }
}
