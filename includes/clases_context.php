<?php
declare(strict_types=1);

require_once __DIR__ . '/sucursal_context.php';

if (!function_exists('clases_h')) {
    function clases_h($value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('clases_bind')) {
    function clases_bind(
        mysqli_stmt $stmt,
        string $types,
        array &$params
    ): void {
        if ($types === '' || $params === []) {
            return;
        }

        $arguments = [$types];

        foreach ($params as $key => &$value) {
            $arguments[] = &$value;
        }

        call_user_func_array(
            [$stmt, 'bind_param'],
            $arguments
        );
    }
}

if (!function_exists('clases_rol_base')) {
    function clases_rol_base(): string
    {
        $role = strtolower(trim((string) (
            $_SESSION['user_rol_base']
            ?? $_SESSION['user_rol']
            ?? 'recepcionista'
        )));

        return $role === 'administrador'
            ? 'admin'
            : $role;
    }
}

if (!function_exists('clases_contexto')) {
    /**
     * @return array{
     *   vista_global:bool,
     *   puede_global:bool,
     *   sucursal_id:int,
     *   sucursal_nombre:string,
     *   sucursal_clave:string,
     *   sucursal_es_matriz:bool,
     *   sucursales:array,
     *   sucursales_ids:array,
     *   total_sedes:int
     * }
     */
    function clases_contexto(
        mysqli $conn,
        int $usuarioId
    ): array {
        if (
            function_exists('sucursal_inicializar_sesion')
        ) {
            sucursal_inicializar_sesion($conn);
        }

        $role = clases_rol_base();
        $puedeGlobal = $role === 'admin';

        $vistaSolicitada = strtolower(trim((string) (
            $_GET['vista'] ?? ''
        )));

        if (
            $vistaSolicitada === 'global'
            && $puedeGlobal
        ) {
            sucursal_activar_vista_global(
                $conn,
                $usuarioId
            );
        } elseif ($vistaSolicitada === 'sucursal') {
            sucursal_desactivar_vista_global();
        }

        $vistaGlobal =
            $puedeGlobal
            && function_exists(
                'sucursal_dashboard_vista_global'
            )
            && sucursal_dashboard_vista_global();

        $sucursales = sucursal_obtener_asignadas(
            $conn,
            $usuarioId
        );

        /*
         * Compatibilidad con administradores creados antes de la
         * tabla usuarios_sucursales.
         */
        if ($sucursales === [] && $puedeGlobal) {
            $result = $conn->query(
                "SELECT
                    id,
                    clave,
                    nombre,
                    es_matriz
                 FROM sucursales
                 WHERE estado = 'activa'
                 ORDER BY es_matriz DESC, nombre ASC"
            );

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $sucursales[] = $row;
                }
            }
        }

        $sucursalId = (int) (
            $_SESSION['sucursal_id'] ?? 0
        );

        $sucursalActual = null;
        $ids = [];

        foreach ($sucursales as $sucursal) {
            $id = (int) ($sucursal['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $ids[] = $id;

            if ($id === $sucursalId) {
                $sucursalActual = $sucursal;
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            throw new RuntimeException(
                'No tienes sucursales activas disponibles.'
            );
        }

        if (!$vistaGlobal && !$sucursalActual) {
            throw new RuntimeException(
                'Selecciona una sucursal válida antes de continuar.'
            );
        }

        return [
            'vista_global' => $vistaGlobal,
            'puede_global' => $puedeGlobal,
            'sucursal_id' => $vistaGlobal
                ? 0
                : (int) $sucursalActual['id'],
            'sucursal_nombre' => $vistaGlobal
                ? 'Todas las sucursales'
                : trim((string) (
                    $sucursalActual['nombre']
                    ?? 'Sucursal'
                )),
            'sucursal_clave' => $vistaGlobal
                ? 'GLOBAL'
                : trim((string) (
                    $sucursalActual['clave']
                    ?? ''
                )),
            'sucursal_es_matriz' => !$vistaGlobal
                && (int) (
                    $sucursalActual['es_matriz']
                    ?? 0
                ) === 1,
            'sucursales' => $sucursales,
            'sucursales_ids' => $ids,
            'total_sedes' => count($ids),
        ];
    }
}

if (!function_exists('clases_exigir_sucursal')) {
    function clases_exigir_sucursal(
        array $contexto
    ): int {
        if (!empty($contexto['vista_global'])) {
            throw new RuntimeException(
                'Selecciona una sucursal concreta para realizar esta operación.'
            );
        }

        $id = (int) (
            $contexto['sucursal_id'] ?? 0
        );

        if ($id <= 0) {
            throw new RuntimeException(
                'No hay una sucursal operativa seleccionada.'
            );
        }

        return $id;
    }
}


if (!function_exists('clases_entrenadores_sucursal')) {
    /**
     * Devuelve únicamente usuarios activos con rol efectivo de entrenador
     * y asignación activa en la sucursal indicada.
     */
    function clases_entrenadores_sucursal(
        mysqli $conn,
        int $sucursalId
    ): array {
        $stmt = $conn->prepare(
            "SELECT
                u.id,
                u.nombre,
                u.email,
                u.foto_perfil
             FROM usuarios_sucursales us
             INNER JOIN usuarios u
                ON u.id = us.usuario_id
             WHERE us.sucursal_id = ?
               AND us.estado = 'activo'
               AND u.estado = 'activo'
               AND COALESCE(
                    us.rol_sucursal,
                    u.rol
               ) = 'entrenador'
             ORDER BY u.nombre ASC, u.id ASC"
        );

        if (!$stmt) {
            throw new RuntimeException(
                'No fue posible consultar los entrenadores de la sucursal.'
            );
        }

        $stmt->bind_param(
            'i',
            $sucursalId
        );
        $stmt->execute();

        $result = $stmt->get_result();
        $entrenadores = [];

        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $entrenadores[] = $row;
        }

        $stmt->close();

        return $entrenadores;
    }
}

if (!function_exists('clases_validar_entrenador')) {
    /**
     * Comprueba que el usuario sea entrenador activo precisamente
     * en la sucursal donde se registrará o editará la clase.
     */
    function clases_validar_entrenador(
        mysqli $conn,
        int $sucursalId,
        int $entrenadorId
    ): array {
        if ($entrenadorId <= 0) {
            throw new RuntimeException(
                'Selecciona un entrenador válido.'
            );
        }

        $stmt = $conn->prepare(
            "SELECT
                u.id,
                u.nombre,
                u.email
             FROM usuarios_sucursales us
             INNER JOIN usuarios u
                ON u.id = us.usuario_id
             WHERE us.sucursal_id = ?
               AND us.usuario_id = ?
               AND us.estado = 'activo'
               AND u.estado = 'activo'
               AND COALESCE(
                    us.rol_sucursal,
                    u.rol
               ) = 'entrenador'
             LIMIT 1"
        );

        if (!$stmt) {
            throw new RuntimeException(
                'No fue posible validar al entrenador seleccionado.'
            );
        }

        $stmt->bind_param(
            'ii',
            $sucursalId,
            $entrenadorId
        );
        $stmt->execute();

        $entrenador = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        if (!$entrenador) {
            throw new RuntimeException(
                'El usuario seleccionado no es un entrenador activo de esta sucursal.'
            );
        }

        $entrenador['id'] = (int) $entrenador['id'];

        return $entrenador;
    }
}

if (!function_exists('clases_buscar_entrenador_por_nombre')) {
    /**
     * Compatibilidad con clases antiguas que solo guardaban el nombre.
     */
    function clases_buscar_entrenador_por_nombre(
        mysqli $conn,
        int $sucursalId,
        string $nombre
    ): ?array {
        $nombre = trim($nombre);

        if ($nombre === '') {
            return null;
        }

        $stmt = $conn->prepare(
            "SELECT
                u.id,
                u.nombre,
                u.email
             FROM usuarios_sucursales us
             INNER JOIN usuarios u
                ON u.id = us.usuario_id
             WHERE us.sucursal_id = ?
               AND us.estado = 'activo'
               AND u.estado = 'activo'
               AND COALESCE(
                    us.rol_sucursal,
                    u.rol
               ) = 'entrenador'
               AND u.nombre = ?
             ORDER BY u.id ASC
             LIMIT 1"
        );

        if (!$stmt) {
            throw new RuntimeException(
                'No fue posible identificar al entrenador de la clase.'
            );
        }

        $stmt->bind_param(
            'is',
            $sucursalId,
            $nombre
        );
        $stmt->execute();

        $entrenador = $stmt
            ->get_result()
            ->fetch_assoc() ?: null;

        $stmt->close();

        if ($entrenador) {
            $entrenador['id'] = (int) $entrenador['id'];
        }

        return $entrenador;
    }
}

if (!function_exists('clases_swal_guardar')) {
    function clases_swal_guardar(
        string $icon,
        string $title,
        string $message
    ): void {
        $_SESSION['clases_swal'] = [
            'icon' => $icon,
            'title' => $title,
            'message' => $message,
        ];
    }
}

if (!function_exists('clases_swal_consumir')) {
    function clases_swal_consumir(): ?array
    {
        $alert = $_SESSION['clases_swal'] ?? null;
        unset($_SESSION['clases_swal']);

        return is_array($alert)
            ? $alert
            : null;
    }
}

if (!function_exists('clases_url')) {
    function clases_url(
        string $page,
        array $params = []
    ): string {
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                unset($params[$key]);
            }
        }

        $query = http_build_query($params);

        return $page . (
            $query !== ''
                ? '?' . $query
                : ''
        );
    }
}

if (!function_exists('clases_redirect')) {
    function clases_redirect(
        string $page,
        array $contexto
    ): void {
        header(
            'Location: ' . clases_url(
                $page,
                [
                    'vista' =>
                        !empty($contexto['vista_global'])
                            ? 'global'
                            : 'sucursal',
                ]
            )
        );

        exit;
    }
}
