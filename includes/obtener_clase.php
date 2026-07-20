<?php
declare(strict_types=1);

session_start();

header(
    'Content-Type: application/json; charset=utf-8'
);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/clases_context.php';

function obtenerClaseResponder(
    bool $success,
    string $message,
    array $extra = [],
    int $http = 200
): void {
    http_response_code($http);

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message,
            ],
            $extra
        ),
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}

if (empty($_SESSION['user_id'])) {
    obtenerClaseResponder(
        false,
        'No autorizado.',
        [],
        401
    );
}

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    || empty($_POST['id'])
) {
    obtenerClaseResponder(
        false,
        'Solicitud inválida.',
        [],
        400
    );
}

$database = new Database();
$conn = $database->getConnection();

if (!$conn instanceof mysqli) {
    obtenerClaseResponder(
        false,
        'No fue posible conectar con la base de datos.',
        [],
        500
    );
}

$conn->set_charset('utf8mb4');

try {
    $contexto = clases_contexto(
        $conn,
        (int) $_SESSION['user_id']
    );

    $sucursalId = clases_exigir_sucursal(
        $contexto
    );

    $claseId = (int) $_POST['id'];

    $stmt = $conn->prepare(
        "SELECT
            id,
            nombre,
            descripcion,
            horario,
            instructor,
            cupo_maximo,
            cupo_actual,
            duracion_minutos,
            estado
         FROM clases
         WHERE id = ?
           AND sucursal_id = ?
         LIMIT 1"
    );

    $stmt->bind_param(
        'ii',
        $claseId,
        $sucursalId
    );

    $stmt->execute();

    $row = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    if (!$row) {
        throw new RuntimeException(
            'La clase no existe en la sucursal seleccionada.'
        );
    }

    $entrenadorActual =
        clases_buscar_entrenador_por_nombre(
            $conn,
            $sucursalId,
            (string) $row['instructor']
        );

    obtenerClaseResponder(
        true,
        'Clase encontrada.',
        [
            'id' => (int) $row['id'],
            'nombre' => (string) $row['nombre'],
            'descripcion' =>
                (string) $row['descripcion'],
            'horario' =>
                (string) $row['horario'],
            'instructor' =>
                (string) $row['instructor'],
            'instructor_usuario_id' =>
                $entrenadorActual
                    ? (int) $entrenadorActual['id']
                    : null,
            'cupo_maximo' =>
                (int) $row['cupo_maximo'],
            'cupo_actual' =>
                (int) $row['cupo_actual'],
            'duracion_minutos' =>
                (int) $row['duracion_minutos'],
            'estado' =>
                (string) $row['estado'],
        ]
    );
} catch (Throwable $error) {
    obtenerClaseResponder(
        false,
        $error->getMessage(),
        [],
        409
    );
}
