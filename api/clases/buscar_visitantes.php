<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function visitantes_json_responder(
    bool $success,
    array $extra = [],
    int $status = 200
): void {
    http_response_code($status);

    if (ob_get_length() > 0) {
        ob_clean();
    }

    echo json_encode(
        array_merge(['success' => $success], $extra),
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

set_error_handler(
    static function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException(
            $message,
            0,
            $severity,
            $file,
            $line
        );
    }
);

try {
    $usuarioId = (int) ($_SESSION['user_id'] ?? 0);

    if ($usuarioId <= 0) {
        visitantes_json_responder(
            false,
            ['message' => 'Tu sesión terminó. Inicia sesión nuevamente.'],
            401
        );
    }

    require_once dirname(__DIR__, 2) . '/config/database.php';

    $database = new Database();
    $conn = $database->getConnection();

    if (!$conn instanceof mysqli) {
        throw new RuntimeException(
            'No fue posible conectar con la base de datos.'
        );
    }

    $conn->set_charset('utf8mb4');

    $query = trim((string) ($_GET['q'] ?? ''));

    if (mb_strlen($query, 'UTF-8') > 80) {
        $query = mb_substr($query, 0, 80, 'UTF-8');
    }

    $visitantes = [];

    if ($query === '') {
        $stmt = $conn->prepare(
            "SELECT
                id,
                nombre,
                apellido,
                telefono,
                email,
                total_visitas,
                fecha_ultima_visita
             FROM visitantes_clases
             WHERE estado = 'activo'
             ORDER BY
                fecha_ultima_visita DESC,
                updated_at DESC,
                id DESC
             LIMIT 10"
        );
    } else {
        $like = '%' . $query . '%';
        $nombreInicio = $query . '%';
        $telefono = preg_replace('/\D+/', '', $query) ?? '';
        $buscarPorTelefono = strlen($telefono) >= 3;

        if ($buscarPorTelefono) {
            $telefonoLike = '%' . $telefono . '%';

            $stmt = $conn->prepare(
                "SELECT
                    id,
                    nombre,
                    apellido,
                    telefono,
                    email,
                    total_visitas,
                    fecha_ultima_visita
                 FROM visitantes_clases
                 WHERE estado = 'activo'
                   AND (
                        CONCAT(nombre, ' ', apellido) LIKE ?
                        OR nombre LIKE ?
                        OR apellido LIKE ?
                        OR telefono LIKE ?
                        OR telefono_normalizado LIKE ?
                        OR email LIKE ?
                   )
                 ORDER BY
                    CASE
                        WHEN telefono_normalizado = ? THEN 0
                        WHEN telefono_normalizado LIKE ? THEN 1
                        WHEN CONCAT(nombre, ' ', apellido) LIKE ? THEN 2
                        WHEN nombre LIKE ? THEN 3
                        WHEN apellido LIKE ? THEN 4
                        ELSE 5
                    END,
                    fecha_ultima_visita DESC,
                    updated_at DESC,
                    id DESC
                 LIMIT 12"
            );

            $stmt->bind_param(
                'sssssssssss',
                $like,
                $like,
                $like,
                $like,
                $telefonoLike,
                $like,
                $telefono,
                $telefonoLike,
                $nombreInicio,
                $nombreInicio,
                $nombreInicio
            );
        } else {
            /*
             * Importante: cuando la búsqueda no contiene dígitos no se usa
             * telefono_normalizado LIKE '%%', porque eso hacía coincidir a
             * todos los visitantes y siempre mostraba el mismo registro.
             */
            $stmt = $conn->prepare(
                "SELECT
                    id,
                    nombre,
                    apellido,
                    telefono,
                    email,
                    total_visitas,
                    fecha_ultima_visita
                 FROM visitantes_clases
                 WHERE estado = 'activo'
                   AND (
                        CONCAT(nombre, ' ', apellido) LIKE ?
                        OR nombre LIKE ?
                        OR apellido LIKE ?
                        OR email LIKE ?
                   )
                 ORDER BY
                    CASE
                        WHEN CONCAT(nombre, ' ', apellido) LIKE ? THEN 0
                        WHEN nombre LIKE ? THEN 1
                        WHEN apellido LIKE ? THEN 2
                        ELSE 3
                    END,
                    fecha_ultima_visita DESC,
                    updated_at DESC,
                    id DESC
                 LIMIT 12"
            );

            $stmt->bind_param(
                'sssssss',
                $like,
                $like,
                $like,
                $like,
                $nombreInicio,
                $nombreInicio,
                $nombreInicio
            );
        }
    }

    $stmt->execute();
    $resultado = $stmt->get_result();

    while ($fila = $resultado->fetch_assoc()) {
        $id = (int) $fila['id'];
        $visitantes[] = [
            'id' => $id,
            'codigo' => 'Visitante #' . str_pad(
                (string) $id,
                6,
                '0',
                STR_PAD_LEFT
            ),
            'nombre' => (string) $fila['nombre'],
            'apellido' => (string) $fila['apellido'],
            'nombre_completo' => trim(
                (string) $fila['nombre']
                . ' '
                . (string) $fila['apellido']
            ),
            'telefono' => (string) $fila['telefono'],
            'email' => (string) ($fila['email'] ?? ''),
            'total_visitas' => (int) ($fila['total_visitas'] ?? 0),
            'fecha_ultima_visita' =>
                (string) ($fila['fecha_ultima_visita'] ?? ''),
        ];
    }

    $stmt->close();

    visitantes_json_responder(
        true,
        [
            'visitantes' => $visitantes,
            'query' => $query,
            'total' => count($visitantes),
        ]
    );
} catch (Throwable $error) {
    error_log(
        '[Buscar visitantes clases] '
        . $error->getMessage()
    );

    visitantes_json_responder(
        false,
        [
            'message' =>
                'No fue posible consultar el registro de visitantes.',
        ],
        500
    );
}
