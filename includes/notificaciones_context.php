<?php
declare(strict_types=1);

require_once __DIR__ . '/sucursal_context.php';

if (!function_exists('notif_h')) {
    function notif_h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('notif_bind')) {
    function notif_bind(mysqli_stmt $stmt, string $types, array &$params): void
    {
        if ($types === '' || $params === []) {
            return;
        }

        $args = [$types];
        foreach ($params as &$value) {
            $args[] = &$value;
        }

        call_user_func_array([$stmt, 'bind_param'], $args);
    }
}

if (!function_exists('notif_role')) {
    function notif_role(): string
    {
        $role = strtolower(trim((string) (
            $_SESSION['user_rol_base']
            ?? $_SESSION['user_rol']
            ?? 'recepcionista'
        )));

        return $role === 'administrador' ? 'admin' : $role;
    }
}

if (!function_exists('notif_context')) {
    function notif_context(mysqli $db, int $userId): array
    {
        if (function_exists('sucursal_inicializar_sesion')) {
            sucursal_inicializar_sesion($db);
        }

        $role = notif_role();
        $canGlobal = $role === 'admin';
        $requested = strtolower(trim((string) ($_GET['vista'] ?? '')));

        if ($requested === 'global' && $canGlobal) {
            sucursal_activar_vista_global($db, $userId);
        } elseif ($requested === 'sucursal') {
            sucursal_desactivar_vista_global();
        }

        $global = $canGlobal
            && function_exists('sucursal_dashboard_vista_global')
            && sucursal_dashboard_vista_global();

        $branches = sucursal_obtener_asignadas($db, $userId);

        if ($branches === [] && $canGlobal) {
            $result = $db->query(
                "SELECT id, clave, nombre, zona_horaria, es_matriz
                 FROM sucursales
                 WHERE estado = 'activa'
                 ORDER BY es_matriz DESC, nombre ASC"
            );

            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $branches[] = $row;
                }
            }
        }

        $sessionBranchId = (int) ($_SESSION['sucursal_id'] ?? 0);
        $current = null;
        $ids = [];

        foreach ($branches as $branch) {
            $id = (int) ($branch['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $ids[] = $id;

            if ($id === $sessionBranchId) {
                $current = $branch;
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            throw new RuntimeException('No tienes sucursales activas disponibles.');
        }

        if (!$global && !$current) {
            throw new RuntimeException(
                'Selecciona una sucursal válida antes de abrir notificaciones.'
            );
        }

        $timezone = $global
            ? 'America/Mexico_City'
            : trim((string) (
                $current['zona_horaria']
                ?? $_SESSION['sucursal_zona_horaria']
                ?? 'America/Mexico_City'
            ));

        return [
            'vista_global' => $global,
            'sucursal_id' => $global ? 0 : (int) $current['id'],
            'sucursal_nombre' => $global
                ? 'Todas las sucursales'
                : trim((string) ($current['nombre'] ?? 'Sucursal')),
            'sucursal_clave' => $global
                ? 'GLOBAL'
                : trim((string) ($current['clave'] ?? '')),
            'sucursales' => $branches,
            'sucursales_ids' => $ids,
            'total_sedes' => count($ids),
            'timezone' => $timezone !== '' ? $timezone : 'America/Mexico_City',
        ];
    }
}

if (!function_exists('notif_scope')) {
    function notif_scope(
        string $column,
        array $context,
        bool $includeCorporate,
        string &$types,
        array &$params
    ): string {
        if (!empty($context['vista_global'])) {
            $ids = array_map('intval', (array) $context['sucursales_ids']);
            $marks = implode(',', array_fill(0, count($ids), '?'));

            foreach ($ids as $id) {
                $params[] = $id;
                $types .= 'i';
            }

            $sql = $column . " IN ($marks)";

            return $includeCorporate
                ? '(' . $column . ' IS NULL OR ' . $sql . ')'
                : $sql;
        }

        $params[] = (int) $context['sucursal_id'];
        $types .= 'i';

        return $column . ' = ?';
    }
}

if (!function_exists('notif_membership_scope')) {
    function notif_membership_scope(
        string $alias,
        array $context,
        string &$types,
        array &$params
    ): string {
        if (!empty($context['vista_global'])) {
            $ids = array_map('intval', (array) $context['sucursales_ids']);
            $marksOrigin = implode(',', array_fill(0, count($ids), '?'));

            foreach ($ids as $id) {
                $params[] = $id;
                $types .= 'i';
            }

            $marksAccess = implode(',', array_fill(0, count($ids), '?'));

            foreach ($ids as $id) {
                $params[] = $id;
                $types .= 'i';
            }

            return "(
                {$alias}.sucursal_id IN ($marksOrigin)
                OR EXISTS (
                    SELECT 1
                    FROM inscripciones_sucursales is_scope
                    WHERE is_scope.inscripcion_id = {$alias}.id
                      AND is_scope.sucursal_id IN ($marksAccess)
                )
            )";
        }

        $branchId = (int) $context['sucursal_id'];
        $params[] = $branchId;
        $params[] = $branchId;
        $types .= 'ii';

        return "(
            {$alias}.sucursal_id = ?
            OR EXISTS (
                SELECT 1
                FROM inscripciones_sucursales is_scope
                WHERE is_scope.inscripcion_id = {$alias}.id
                  AND is_scope.sucursal_id = ?
            )
        )";
    }
}

if (!function_exists('notif_json')) {
    function notif_json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        exit;
    }
}

if (!function_exists('notif_csrf')) {
    function notif_csrf(): string
    {
        if (empty($_SESSION['notificaciones_csrf'])) {
            $_SESSION['notificaciones_csrf'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['notificaciones_csrf'];
    }
}

if (!function_exists('notif_check_csrf')) {
    function notif_check_csrf(): void
    {
        $received = trim((string) ($_POST['csrf'] ?? ''));
        $expected = (string) ($_SESSION['notificaciones_csrf'] ?? '');

        if (
            $received === ''
            || $expected === ''
            || !hash_equals($expected, $received)
        ) {
            throw new RuntimeException(
                'La sesión del módulo expiró. Recarga la página.'
            );
        }
    }
}
