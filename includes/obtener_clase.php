<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/clases_context.php';

function responder_clase(
    bool $success,
    array $data = [],
    int $status = 200
): never {
    http_response_code($status);

    echo json_encode(
        array_merge(['success' => $success], $data),
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    );
    exit;
}

try {
    if (empty($_SESSION['user_id'])) {
        responder_clase(
            false,
            ['message' => 'La sesión ha expirado.'],
            401
        );
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        responder_clase(
            false,
            ['message' => 'Método no permitido.'],
            405
        );
    }

    $claseId = (int) ($_POST['id'] ?? 0);

    if ($claseId <= 0) {
        responder_clase(
            false,
            ['message' => 'La clase seleccionada no es válida.'],
            422
        );
    }

    $database = new Database();
    $conn = $database->getConnection();

    if (!$conn instanceof mysqli) {
        throw new RuntimeException(
            'No fue posible establecer la conexión con la base de datos.'
        );
    }

    $conn->set_charset('utf8mb4');

    $contexto = clases_contexto(
        $conn,
        (int) $_SESSION['user_id']
    );
    $sucursalId = clases_exigir_sucursal($contexto);

    $stmt = $conn->prepare(
        "SELECT
            id,
            nombre,
            descripcion,
            precio_clase,
            horario,
            instructor,
            instructor_tipo,
            instructor_usuario_id,
            entrenador_externo_id,
            cupo_maximo,
            cupo_actual,
            duracion_minutos,
            estado
         FROM clases
         WHERE id = ?
           AND sucursal_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('ii', $claseId, $sucursalId);
    $stmt->execute();
    $clase = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$clase) {
        responder_clase(
            false,
            ['message' => 'La clase no pertenece a la sucursal seleccionada.'],
            404
        );
    }

    $stmtHorarios = $conn->prepare(
        "SELECT
            dia_semana,
            TIME_FORMAT(hora_inicio, '%H:%i') AS hora_inicio,
            TIME_FORMAT(hora_fin, '%H:%i') AS hora_fin
         FROM clases_horarios
         WHERE clase_id = ?
           AND estado = 'activo'
         ORDER BY dia_semana ASC, hora_inicio ASC"
    );
    $stmtHorarios->bind_param('i', $claseId);
    $stmtHorarios->execute();
    $horarios = $stmtHorarios
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);
    $stmtHorarios->close();

    responder_clase(
        true,
        [
            'id' => (int) $clase['id'],
            'nombre' => (string) $clase['nombre'],
            'descripcion' => (string) ($clase['descripcion'] ?? ''),
            'precio_clase' => number_format(
                (float) $clase['precio_clase'],
                2,
                '.',
                ''
            ),
            'horario' => (string) ($clase['horario'] ?? ''),
            'horarios' => array_map(
                static fn (array $horario): array => [
                    'dia_semana' => (int) $horario['dia_semana'],
                    'hora_inicio' => (string) $horario['hora_inicio'],
                    'hora_fin' => (string) $horario['hora_fin'],
                ],
                $horarios
            ),
            'instructor' => (string) $clase['instructor'],
            'instructor_tipo' => in_array(
                $clase['instructor_tipo'],
                ['interno', 'externo'],
                true
            )
                ? (string) $clase['instructor_tipo']
                : 'interno',
            'instructor_usuario_id' => $clase['instructor_usuario_id'] !== null
                ? (int) $clase['instructor_usuario_id']
                : null,
            'entrenador_externo_id' => $clase['entrenador_externo_id'] !== null
                ? (int) $clase['entrenador_externo_id']
                : null,
            'cupo_maximo' => (int) $clase['cupo_maximo'],
            'cupo_actual' => (int) $clase['cupo_actual'],
            'duracion_minutos' => (int) $clase['duracion_minutos'],
            'estado' => (string) $clase['estado'],
        ]
    );
} catch (Throwable $error) {
    responder_clase(
        false,
        ['message' => $error->getMessage()],
        500
    );
}
