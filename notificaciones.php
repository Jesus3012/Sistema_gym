<?php
declare(strict_types=1);

// Toda página protegida debe pasar primero por el guard central.
require_once __DIR__ . '/includes/auth_guard.php';

date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/super_admin_helper.php';
require_once __DIR__ . '/includes/notificaciones_context.php';
require_once __DIR__ . '/includes/notificaciones_mailer.php';

$database = new Database();
$db = $database->getConnection();

if (!$db instanceof mysqli) {
    die('No fue posible establecer la conexión a la base de datos.');
}

$db->set_charset('utf8mb4');

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = rol_normalizar_sistema(notif_role());
$baseRole = rol_base_real_sesion();

/*
 * El rol general super_administrador conserva acceso total aunque el rol
 * operativo de una sucursal sea admin. Recepción continúa entrando cuando
 * tenga disponible el módulo de Notificaciones.
 */
$canAccessNotifications =
    rol_es_administrativo($baseRole)
    || rol_es_administrativo($role)
    || $role === 'recepcionista';

if (!$canAccessNotifications) {
    $_SESSION['alerta_acceso_denegado'] = [
        'titulo' => 'Acceso restringido',
        'mensaje' => 'Tu perfil no tiene permiso para administrar notificaciones.',
        'rol' => $baseRole !== '' ? $baseRole : $role,
        'modulo' => 'Notificaciones',
    ];

    header('Location: dashboard.php?error=acceso_denegado');
    exit;
}

try {
    $context = notif_context($db, $userId);
} catch (Throwable $error) {
    die(notif_h($error->getMessage()));
}

date_default_timezone_set((string) $context['timezone']);

$global = (bool) $context['vista_global'];
$branchId = (int) $context['sucursal_id'];
$branchName = (string) $context['sucursal_nombre'];
$branchKey = (string) $context['sucursal_clave'];
$totalBranches = (int) $context['total_sedes'];
$view = $global ? 'global' : 'sucursal';
$csrf = notif_csrf();
$pageSize = 10;

function notif_recipient_labels($value): array
{
    $map = [
        'socios_membresia_activa' => 'Socios con membresía activa',
        'socios_membresia_activa_vencida' => 'Socios con membresía activa o vencida',
        'usuarios_sistema' => 'Usuarios del sistema',
        'todos_clientes_activos' => 'Clientes activos del sistema',
        'clientes_membresia_activa' => 'Clientes con membresía activa',
        'todos_usuarios' => 'Usuarios del sistema',
        'todos_membresia_usuarios' => 'Clientes con membresía activa y usuarios',
        'todos' => 'Todos los destinatarios',
    ];

    if (is_array($value)) {
        $items = $value;
    } else {
        $text = trim((string) $value);
        $decoded = $text !== '' ? json_decode($text, true) : null;
        $items = is_array($decoded)
            ? $decoded
            : ($text !== '' ? explode(',', $text) : []);
    }

    $labels = [];

    foreach ($items as $item) {
        $key = trim((string) $item);

        if ($key !== '') {
            $labels[] = $map[$key] ?? $key;
        }
    }

    return array_values(array_unique($labels));
}

function notif_recipient_text($value): string
{
    $labels = notif_recipient_labels($value);

    return $labels === []
        ? 'Sin destinatarios registrados'
        : implode(' + ', $labels);
}

function notif_add_recipient(
    array &$list,
    $email,
    $name,
    string $type
): void {
    $email = trim((string) $email);

    if (
        $email === ''
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        return;
    }

    $key = strtolower($email);

    if (!isset($list[$key])) {
        $list[$key] = [
            'email' => $email,
            'nombre' => trim((string) $name),
            'tipo' => $type,
        ];
    }
}

function notif_value(
    mysqli $db,
    string $sql,
    string $types,
    array $params,
    string $field = 'total'
): int {
    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'No fue posible preparar una consulta: ' . $db->error
        );
    }

    notif_bind($stmt, $types, $params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row[$field] ?? 0);
}

function notif_group_recipients(
    mysqli $db,
    array $context,
    string $group
): array {
    $recipients = [];

    if (in_array(
        $group,
        ['socios_membresia_activa', 'socios_membresia_activa_vencida'],
        true
    )) {
        $types = '';
        $params = [];
        $scope = notif_membership_scope('i', $context, $types, $params);

        $statusSql = $group === 'socios_membresia_activa'
            ? "i.estado = 'activa' AND i.fecha_fin >= CURDATE()"
            : "i.estado IN ('activa', 'vencida')";

        $sql = "
            SELECT DISTINCT
                c.nombre,
                c.apellido,
                c.email
            FROM clientes c
            INNER JOIN inscripciones i
                ON i.cliente_id = c.id
            WHERE c.estado = 'activo'
              AND $statusSql
              AND $scope
              AND c.email IS NOT NULL
              AND TRIM(c.email) <> ''
            ORDER BY c.nombre, c.apellido
        ";

        $stmt = $db->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException(
                'No fue posible consultar a los socios destinatarios.'
            );
        }

        notif_bind($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            notif_add_recipient(
                $recipients,
                $row['email'],
                trim($row['nombre'] . ' ' . $row['apellido']),
                'cliente'
            );
        }

        $stmt->close();

        return $recipients;
    }

    if ($group === 'usuarios_sistema') {
        $types = '';
        $params = [];
        $scope = notif_scope(
            'us.sucursal_id',
            $context,
            false,
            $types,
            $params
        );

        $sql = "
            SELECT DISTINCT
                u.nombre,
                u.email
            FROM usuarios u
            INNER JOIN usuarios_sucursales us
                ON us.usuario_id = u.id
            WHERE u.estado = 'activo'
              AND us.estado = 'activo'
              AND $scope
              AND u.email IS NOT NULL
              AND TRIM(u.email) <> ''
            ORDER BY u.nombre
        ";

        $stmt = $db->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException(
                'No fue posible consultar a los usuarios destinatarios.'
            );
        }

        notif_bind($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            notif_add_recipient(
                $recipients,
                $row['email'],
                $row['nombre'],
                'usuario'
            );
        }

        $stmt->close();
    }

    return $recipients;
}

function notif_type_info(string $type): array
{
    $map = [
        'info' => ['info', 'Informativo'],
        'aviso' => ['aviso', 'Aviso'],
        'alerta' => ['alerta', 'Alerta'],
        'promocion' => ['promocion', 'Promoción'],
    ];

    return $map[$type] ?? $map['info'];
}

function notif_render_manual(array $row, bool $showBranch): string
{
    $type = notif_type_info((string) $row['tipo']);
    $class = $type[0];
    $label = $type[1];

    $rowBranch = trim((string) ($row['sucursal_nombre'] ?? ''));

    if ($rowBranch === '') {
        $rowBranch = 'Todas las sucursales';
    }

    ob_start();
    ?>
    <article class="notificacion-item <?php echo notif_h($class); ?>">
        <div class="titulo">
            <span><?php echo notif_h($row['titulo']); ?></span>
            <span class="badge-custom badge-<?php echo notif_h($class); ?>">
                <?php echo notif_h($label); ?>
            </span>
        </div>

        <div class="mensaje">
            <?php echo nl2br(notif_h($row['mensaje'])); ?>
        </div>

        <div class="meta">
            <span>
                <i class="fas fa-calendar"></i>
                <?php echo date(
                    'd/m/Y h:i A',
                    strtotime((string) $row['fecha_envio'])
                ); ?>
            </span>

            <span>
                <i class="fas fa-user"></i>
                Enviado por:
                <?php echo notif_h($row['usuario_envio'] ?? 'Usuario'); ?>
            </span>

            <?php if ($showBranch): ?>
                <span class="notificacion-sucursal">
                    <i class="fas fa-building"></i>
                    <?php echo notif_h($rowBranch); ?>
                </span>
            <?php endif; ?>

            <span>
                <i class="fas fa-users"></i>
                <?php echo notif_h(notif_recipient_text($row['destinatarios'])); ?>
            </span>

            <span>
                <i class="fas fa-envelope"></i>
                Enviados: <?php echo (int) $row['total_enviados']; ?>
            </span>
        </div>
    </article>
    <?php

    return (string) ob_get_clean();
}

function notif_render_auto(array $row, bool $showBranch): string
{
    $threeDays = $row['tipo_notificacion'] === '3_dias';
    $class = $threeDays ? 'info' : 'danger';
    $typeText = $threeDays ? '3 días antes' : 'Día del vencimiento';
    $sent = $row['estado'] === 'enviado';

    ob_start();
    ?>
    <article class="notificacion-item <?php echo $class; ?>">
        <div class="titulo">
            <span>
                <i class="fas fa-bell"></i>
                Vencimiento · <?php echo $typeText; ?>
            </span>

            <span class="badge-custom badge-<?php echo $sent ? 'success' : 'danger'; ?>">
                <?php echo $sent ? 'Enviado' : 'Fallido'; ?>
            </span>
        </div>

        <div class="mensaje automatic-message">
            <span>
                <strong>Socio:</strong>
                <?php echo notif_h($row['cliente_nombre']); ?>
            </span>
            <span>
                <strong>Correo:</strong>
                <?php echo notif_h($row['cliente_email']); ?>
            </span>
            <span>
                <strong>Plan:</strong>
                <?php echo notif_h($row['plan_nombre']); ?>
            </span>
            <span>
                <strong>Vencimiento:</strong>
                <?php echo date(
                    'd/m/Y',
                    strtotime((string) $row['fecha_vencimiento'])
                ); ?>
            </span>
        </div>

        <div class="meta">
            <span>
                <i class="fas fa-calendar"></i>
                <?php echo date(
                    'd/m/Y h:i A',
                    strtotime((string) $row['fecha_envio'])
                ); ?>
            </span>

            <?php if ($showBranch): ?>
                <span class="notificacion-sucursal">
                    <i class="fas fa-building"></i>
                    <?php echo notif_h($row['sucursal_nombre'] ?? 'Sucursal'); ?>
                </span>
            <?php endif; ?>

            <?php if ((int) $row['dias_restantes'] > 0): ?>
                <span>
                    <i class="fas fa-hourglass-half"></i>
                    Días restantes:
                    <?php echo (int) $row['dias_restantes']; ?>
                </span>
            <?php endif; ?>
        </div>
    </article>
    <?php

    return (string) ob_get_clean();
}

function notif_search_manual(
    mysqli $db,
    array $context,
    string $search,
    int $page,
    int $limit
): array {
    $page = max(1, $page);
    $types = '';
    $params = [];

    $where = [
        notif_scope(
            'n.sucursal_id',
            $context,
            true,
            $types,
            $params
        ),
    ];

    if ($search !== '') {
        $like = '%' . $search . '%';

        $where[] = "(
            n.titulo LIKE ?
            OR n.mensaje LIKE ?
            OR u.nombre LIKE ?
            OR s.nombre LIKE ?
            OR s.clave LIKE ?
        )";

        for ($i = 0; $i < 5; $i++) {
            $params[] = $like;
            $types .= 's';
        }
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $total = notif_value(
        $db,
        "SELECT COUNT(*) AS total
         FROM notificaciones n
         LEFT JOIN usuarios u ON u.id = n.enviado_por
         LEFT JOIN sucursales s ON s.id = n.sucursal_id
         $whereSql",
        $types,
        $params
    );

    $totalPages = max(1, (int) ceil($total / $limit));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $limit;

    $sql = "
        SELECT
            n.*,
            u.nombre AS usuario_envio,
            s.nombre AS sucursal_nombre,
            s.clave AS sucursal_clave,
            (
                SELECT COUNT(*)
                FROM notificaciones_enviadas ne
                WHERE ne.notificacion_id = n.id
            ) AS total_enviados
        FROM notificaciones n
        LEFT JOIN usuarios u ON u.id = n.enviado_por
        LEFT JOIN sucursales s ON s.id = n.sucursal_id
        $whereSql
        ORDER BY n.fecha_envio DESC, n.id DESC
        LIMIT ? OFFSET ?
    ";

    $dataParams = $params;
    $dataParams[] = $limit;
    $dataParams[] = $offset;
    $dataTypes = $types . 'ii';

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'No fue posible consultar el historial manual.'
        );
    }

    notif_bind($stmt, $dataTypes, $dataParams);
    $stmt->execute();
    $result = $stmt->get_result();
    $html = '';

    while ($row = $result->fetch_assoc()) {
        $html .= notif_render_manual(
            $row,
            !empty($context['vista_global'])
        );
    }

    $stmt->close();

    if ($html === '') {
        $html = '
            <div class="empty-notifications">
                <i class="fas fa-envelope-open"></i>
                <strong>Sin notificaciones manuales</strong>
                <span>No hay registros que coincidan con la búsqueda.</span>
            </div>
        ';
    }

    return [
        'html' => $html,
        'total' => $total,
        'total_paginas' => $totalPages,
        'pagina_actual' => $page,
    ];
}

function notif_search_auto(
    mysqli $db,
    array $context,
    string $search,
    int $page,
    int $limit
): array {
    $page = max(1, $page);
    $types = '';
    $params = [];

    $where = [
        notif_scope(
            'h.sucursal_id',
            $context,
            false,
            $types,
            $params
        ),
    ];

    if ($search !== '') {
        $like = '%' . $search . '%';

        $where[] = "(
            h.cliente_nombre LIKE ?
            OR h.cliente_email LIKE ?
            OR h.plan_nombre LIKE ?
            OR h.tipo_notificacion LIKE ?
            OR s.nombre LIKE ?
            OR s.clave LIKE ?
        )";

        for ($i = 0; $i < 6; $i++) {
            $params[] = $like;
            $types .= 's';
        }
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $total = notif_value(
        $db,
        "SELECT COUNT(*) AS total
         FROM notificaciones_vencimiento_historial h
         INNER JOIN sucursales s ON s.id = h.sucursal_id
         $whereSql",
        $types,
        $params
    );

    $totalPages = max(1, (int) ceil($total / $limit));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $limit;

    $sql = "
        SELECT
            h.*,
            s.nombre AS sucursal_nombre,
            s.clave AS sucursal_clave
        FROM notificaciones_vencimiento_historial h
        INNER JOIN sucursales s ON s.id = h.sucursal_id
        $whereSql
        ORDER BY h.fecha_envio DESC, h.id DESC
        LIMIT ? OFFSET ?
    ";

    $dataParams = $params;
    $dataParams[] = $limit;
    $dataParams[] = $offset;
    $dataTypes = $types . 'ii';

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'No fue posible consultar el historial automático.'
        );
    }

    notif_bind($stmt, $dataTypes, $dataParams);
    $stmt->execute();
    $result = $stmt->get_result();
    $html = '';

    while ($row = $result->fetch_assoc()) {
        $html .= notif_render_auto(
            $row,
            !empty($context['vista_global'])
        );
    }

    $stmt->close();

    if ($html === '') {
        $html = '
            <div class="empty-notifications">
                <i class="fas fa-bell-slash"></i>
                <strong>Sin avisos de vencimiento</strong>
                <span>No hay registros que coincidan con la búsqueda.</span>
            </div>
        ';
    }

    return [
        'html' => $html,
        'total' => $total,
        'total_paginas' => $totalPages,
        'pagina_actual' => $page,
    ];
}

function notif_process_expirations(
    mysqli $db,
    array $context
): array {
    /*
     * Se procesa la sucursal que vendió la inscripción. Una membresía
     * multi-sede recibe un solo aviso, no uno por cada sede de acceso.
     */
    $types = '';
    $params = [];

    $scope = notif_scope(
        'i.sucursal_id',
        $context,
        false,
        $types,
        $params
    );

    $sql = "
        SELECT
            i.id,
            i.sucursal_id,
            i.cliente_id,
            i.fecha_fin,
            c.nombre,
            c.apellido,
            c.email,
            p.nombre AS plan_nombre,
            s.nombre AS sucursal_nombre,
            DATEDIFF(i.fecha_fin, CURDATE()) AS dias_restantes
        FROM inscripciones i
        INNER JOIN clientes c ON c.id = i.cliente_id
        INNER JOIN planes p ON p.id = i.plan_id
        INNER JOIN sucursales s ON s.id = i.sucursal_id
        WHERE i.estado = 'activa'
          AND $scope
          AND DATEDIFF(i.fecha_fin, CURDATE()) IN (3, 0)
          AND LOWER(TRIM(p.nombre)) <> 'visita'
          AND c.estado = 'activo'
          AND c.email IS NOT NULL
          AND TRIM(c.email) <> ''
        ORDER BY i.fecha_fin ASC, i.id ASC
    ";

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'No fue posible preparar las membresías por vencer: '
            . $db->error
        );
    }

    notif_bind($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();

    $checkStmt = $db->prepare(
        "SELECT id
         FROM notificaciones_vencimiento_historial
         WHERE inscripcion_id = ?
           AND tipo_notificacion = ?
           AND estado = 'enviado'
         LIMIT 1"
    );

    $insertStmt = $db->prepare(
        "INSERT INTO notificaciones_vencimiento_historial (
            sucursal_id,
            inscripcion_id,
            cliente_id,
            cliente_nombre,
            cliente_email,
            plan_nombre,
            tipo_notificacion,
            dias_restantes,
            fecha_vencimiento,
            fecha_envio,
            estado
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$checkStmt || !$insertStmt) {
        throw new RuntimeException(
            'No fue posible preparar el historial de vencimientos.'
        );
    }

    $summary = [
        'encontradas' => 0,
        'omitidas_ya_enviadas' => 0,
        'enviados_3_dias' => 0,
        'enviados_vencidos' => 0,
        'errores' => 0,
        'errores_detalle' => [],
    ];

    while ($row = $result->fetch_assoc()) {
        $summary['encontradas']++;

        $days = (int) $row['dias_restantes'];
        $type = $days === 3 ? '3_dias' : 'vencido';
        $inscriptionId = (int) $row['id'];

        $checkStmt->bind_param('is', $inscriptionId, $type);
        $checkStmt->execute();
        $alreadySent = $checkStmt->get_result()->fetch_assoc();

        if ($alreadySent) {
            $summary['omitidas_ya_enviadas']++;
            continue;
        }

        $fullName = trim($row['nombre'] . ' ' . $row['apellido']);

        $send = notif_send_expiration(
            $db,
            (string) $row['email'],
            $fullName,
            $days,
            (string) $row['fecha_fin'],
            (string) $row['plan_nombre'],
            (string) $row['sucursal_nombre']
        );

        $status = $send['ok'] ? 'enviado' : 'fallido';
        $rowBranchId = (int) $row['sucursal_id'];
        $clientId = (int) $row['cliente_id'];
        $email = (string) $row['email'];
        $plan = (string) $row['plan_nombre'];
        $expiration = (string) $row['fecha_fin'];
        $sentAt = date('Y-m-d H:i:s');

        $insertStmt->bind_param(
            'iiissssisss',
            $rowBranchId,
            $inscriptionId,
            $clientId,
            $fullName,
            $email,
            $plan,
            $type,
            $days,
            $expiration,
            $sentAt,
            $status
        );

        if (!$insertStmt->execute()) {
            $summary['errores']++;
            $summary['errores_detalle'][] =
                $fullName
                . ': no se pudo guardar el historial ('
                . $insertStmt->error
                . ').';
            continue;
        }

        if ($send['ok']) {
            if ($type === '3_dias') {
                $summary['enviados_3_dias']++;
            } else {
                $summary['enviados_vencidos']++;
            }
        } else {
            $summary['errores']++;

            if (count($summary['errores_detalle']) < 12) {
                $summary['errores_detalle'][] =
                    $fullName
                    . ' · '
                    . $email
                    . ': '
                    . $send['error'];
            }
        }
    }

    $stmt->close();
    $checkStmt->close();
    $insertStmt->close();

    return $summary;
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
) {
    try {
        notif_check_csrf();

        $action = trim((string) $_POST['action']);

        if ($action === 'buscar_manuales') {
            notif_json(array_merge(
                ['success' => true],
                notif_search_manual(
                    $db,
                    $context,
                    trim((string) ($_POST['search'] ?? '')),
                    max(1, (int) ($_POST['page'] ?? 1)),
                    $pageSize
                )
            ));
        }

        if ($action === 'buscar_automaticas') {
            notif_json(array_merge(
                ['success' => true],
                notif_search_auto(
                    $db,
                    $context,
                    trim((string) ($_POST['search'] ?? '')),
                    max(1, (int) ($_POST['page'] ?? 1)),
                    $pageSize
                )
            ));
        }

        if ($action === 'procesar_vencimientos') {
            notif_json([
                'success' => true,
                'message' => 'Proceso de vencimientos terminado.',
                'contexto' => $branchName,
                'detalles' => notif_process_expirations($db, $context),
            ]);
        }

        if ($action === 'enviar_notificacion') {
            $title = trim((string) ($_POST['titulo'] ?? ''));
            $message = str_replace(
                ['\r\n', '\r', '\n', '\\r\\n', '\\n'],
                "\n",
                (string) ($_POST['mensaje'] ?? '')
            );
            $type = strtolower(trim((string) ($_POST['tipo'] ?? 'info')));
            $receivedGroups = $_POST['destinatarios'] ?? [];

            if (!is_array($receivedGroups)) {
                $receivedGroups = [$receivedGroups];
            }

            $allowedGroups = [
                'socios_membresia_activa',
                'socios_membresia_activa_vencida',
                'usuarios_sistema',
            ];
            $allowedTypes = ['info', 'aviso', 'alerta', 'promocion'];
            $groups = [];

            foreach ($receivedGroups as $group) {
                $group = trim((string) $group);

                if (
                    in_array($group, $allowedGroups, true)
                    && !in_array($group, $groups, true)
                ) {
                    $groups[] = $group;
                }
            }

            if ($title === '' || trim($message) === '') {
                throw new RuntimeException(
                    'El título y el mensaje son obligatorios.'
                );
            }

            if (!in_array($type, $allowedTypes, true)) {
                throw new RuntimeException(
                    'El tipo de notificación no es válido.'
                );
            }

            if ($groups === []) {
                throw new RuntimeException(
                    'Selecciona por lo menos un grupo de destinatarios.'
                );
            }

            if (count($groups) > 2) {
                throw new RuntimeException(
                    'Solo puedes seleccionar hasta dos grupos.'
                );
            }

            if (
                in_array('socios_membresia_activa', $groups, true)
                && in_array(
                    'socios_membresia_activa_vencida',
                    $groups,
                    true
                )
            ) {
                throw new RuntimeException(
                    'Las dos opciones de socios no pueden combinarse.'
                );
            }

            /*
             * Valida SMTP antes de crear un registro sin posibilidad
             * de envío.
             */
            notif_mail_config($db);

            $recipientsByEmail = [];

            foreach ($groups as $group) {
                foreach (
                    notif_group_recipients($db, $context, $group)
                    as $key => $recipient
                ) {
                    if (!isset($recipientsByEmail[$key])) {
                        $recipientsByEmail[$key] = $recipient;
                    }
                }
            }

            $recipients = array_values($recipientsByEmail);

            if ($recipients === []) {
                throw new RuntimeException(
                    'Los grupos seleccionados no tienen correos válidos en este contexto.'
                );
            }

            $recordBranch = $global ? null : $branchId;
            $groupsStored = implode(',', $groups);
            $sentAt = date('Y-m-d H:i:s');
            $initialStatus = 'programado';

            $insertNotification = $db->prepare(
                "INSERT INTO notificaciones (
                    sucursal_id,
                    titulo,
                    mensaje,
                    tipo,
                    destinatarios,
                    fecha_envio,
                    enviado_por,
                    estado
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );

            if (!$insertNotification) {
                throw new RuntimeException(
                    'No fue posible preparar la notificación: ' . $db->error
                );
            }

            $insertNotification->bind_param(
                'isssssis',
                $recordBranch,
                $title,
                $message,
                $type,
                $groupsStored,
                $sentAt,
                $userId,
                $initialStatus
            );

            $insertNotification->execute();
            $notificationId = (int) $db->insert_id;
            $insertNotification->close();

            $insertDetail = $db->prepare(
                "INSERT INTO notificaciones_enviadas (
                    notificacion_id,
                    destinatario_email,
                    destinatario_nombre,
                    tipo_destinatario,
                    fecha_envio
                 ) VALUES (?, ?, ?, ?, ?)"
            );

            $sent = 0;
            $failed = 0;
            $errors = [];

            foreach ($recipients as $recipient) {
                $send = notif_send_manual(
                    $db,
                    (string) $recipient['email'],
                    (string) $recipient['nombre'],
                    $title,
                    $message,
                    $type,
                    $branchName
                );

                if ($send['ok']) {
                    $sent++;

                    if ($insertDetail) {
                        $detailEmail = (string) $recipient['email'];
                        $detailName = (string) $recipient['nombre'];
                        $detailType = (string) $recipient['tipo'];

                        $insertDetail->bind_param(
                            'issss',
                            $notificationId,
                            $detailEmail,
                            $detailName,
                            $detailType,
                            $sentAt
                        );

                        if (!$insertDetail->execute()) {
                            error_log(
                                '[Notificaciones detalle] '
                                . $insertDetail->error
                            );
                        }
                    }
                } else {
                    $failed++;

                    if (count($errors) < 12) {
                        $errors[] =
                            $recipient['email']
                            . ': '
                            . $send['error'];
                    }
                }
            }

            if ($insertDetail) {
                $insertDetail->close();
            }

            $finalStatus = $sent > 0 ? 'enviado' : 'cancelado';
            $update = $db->prepare(
                "UPDATE notificaciones
                 SET estado = ?
                 WHERE id = ?"
            );

            if ($update) {
                $update->bind_param('si', $finalStatus, $notificationId);
                $update->execute();
                $update->close();
            }

            notif_json([
                'success' => $sent > 0,
                'enviados' => $sent,
                'fallidos' => $failed,
                'total' => count($recipients),
                'errores' => $errors,
                'grupos' => notif_recipient_labels($groups),
                'contexto' => $branchName,
                'error' => $sent === 0
                    ? 'No se pudo entregar ningún correo. Revisa el detalle SMTP.'
                    : '',
            ], $sent > 0 ? 200 : 502);
        }

        throw new RuntimeException(
            'La acción solicitada no está disponible.'
        );
    } catch (Throwable $error) {
        notif_json([
            'success' => false,
            'error' => $error->getMessage(),
        ], 400);
    }
}

$stats = [];

$types = '';
$params = [];
$scope = notif_membership_scope('i', $context, $types, $params);

$stats['active_members'] = notif_value(
    $db,
    "SELECT COUNT(DISTINCT c.id) AS total
     FROM clientes c
     INNER JOIN inscripciones i ON i.cliente_id = c.id
     WHERE c.estado = 'activo'
       AND i.estado = 'activa'
       AND i.fecha_fin >= CURDATE()
       AND $scope",
    $types,
    $params
);

$types = '';
$params = [];
$scope = notif_membership_scope('i', $context, $types, $params);

$stats['active_expired_members'] = notif_value(
    $db,
    "SELECT COUNT(DISTINCT c.id) AS total
     FROM clientes c
     INNER JOIN inscripciones i ON i.cliente_id = c.id
     WHERE c.estado = 'activo'
       AND i.estado IN ('activa', 'vencida')
       AND $scope",
    $types,
    $params
);

$types = '';
$params = [];
$scope = notif_scope(
    'us.sucursal_id',
    $context,
    false,
    $types,
    $params
);

$stats['active_users'] = notif_value(
    $db,
    "SELECT COUNT(DISTINCT u.id) AS total
     FROM usuarios u
     INNER JOIN usuarios_sucursales us ON us.usuario_id = u.id
     WHERE u.estado = 'activo'
       AND us.estado = 'activo'
       AND $scope",
    $types,
    $params
);

$types = '';
$params = [];
$scope = notif_scope(
    'n.sucursal_id',
    $context,
    true,
    $types,
    $params
);

$stats['notifications'] = notif_value(
    $db,
    "SELECT COUNT(*) AS total
     FROM notificaciones n
     WHERE $scope",
    $types,
    $params
);

$manualInitial = notif_search_manual(
    $db,
    $context,
    '',
    max(1, (int) ($_GET['pagina_manual'] ?? 1)),
    $pageSize
);

$autoInitial = notif_search_auto(
    $db,
    $context,
    '',
    max(1, (int) ($_GET['pagina_automatica'] ?? 1)),
    $pageSize
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Notificaciones por correo - Sistema Gimnasio</title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
    >
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css"
    >
    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css"
    >

    <?php
    $notificacionesCss = __DIR__ . '/css/notificaciones.css';
    ?>
    <link
        rel="stylesheet"
        href="css/notificaciones.css?v=<?php echo is_file($notificacionesCss) ? (int) filemtime($notificacionesCss) : time(); ?>"
    >
</head>
<body class="hold-transition sidebar-mini notificaciones-page">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <header class="notifications-page-header">
            <div>
                <span class="notifications-kicker">
                    Comunicación
                </span>
                <h1>Notificaciones por correo</h1>
                <p>
                    <?php echo $global
                        ? 'Envía comunicados corporativos y revisa el historial consolidado de todas las sucursales.'
                        : 'Envía comunicados y procesa vencimientos de ' . notif_h($branchName) . '.';
                    ?>
                </p>
            </div>

            <span class="notifications-context <?php echo $global ? 'global' : 'branch'; ?>">
                <i class="fas <?php echo $global ? 'fa-chart-pie' : 'fa-building'; ?>"></i>
                <span>
                    <strong><?php echo notif_h($branchName); ?></strong>
                    <small>
                        <?php echo notif_h(
                            $global
                                ? $totalBranches . (
                                    $totalBranches === 1
                                        ? ' sede consolidada'
                                        : ' sedes consolidadas'
                                )
                                : ($branchKey !== '' ? $branchKey : 'Sucursal activa')
                        ); ?>
                    </small>
                </span>
            </span>
        </header>

        <section class="notifications-stats">
            <article class="stats-card">
                <span class="stats-icon info">
                    <i class="fas fa-id-card"></i>
                </span>
                <strong><?php echo number_format($stats['active_members']); ?></strong>
                <span>Socios con membresía activa</span>
            </article>

            <article class="stats-card">
                <span class="stats-icon success">
                    <i class="fas fa-users"></i>
                </span>
                <strong><?php echo number_format($stats['active_expired_members']); ?></strong>
                <span>Socios activos y vencidos</span>
            </article>

            <article class="stats-card">
                <span class="stats-icon warning">
                    <i class="fas fa-user-shield"></i>
                </span>
                <strong><?php echo number_format($stats['active_users']); ?></strong>
                <span>Usuarios del sistema</span>
            </article>

            <article class="stats-card">
                <span class="stats-icon danger">
                    <i class="fas fa-envelope"></i>
                </span>
                <strong><?php echo number_format($stats['notifications']); ?></strong>
                <span>Notificaciones registradas</span>
            </article>
        </section>

        <section class="notification-card">
            <header class="notification-card-header primary">
                <div>
                    <i class="fas fa-paper-plane"></i>
                    <span>
                        <strong>Nueva notificación</strong>
                        <small>
                            <?php echo $global
                                ? 'Envío corporativo para todas las sedes disponibles'
                                : 'Envío local para ' . notif_h($branchName);
                            ?>
                        </small>
                    </span>
                </div>
            </header>

            <div class="notification-card-body">
                <?php if ($global): ?>
                    <div class="notification-context-note">
                        <i class="fas fa-layer-group"></i>
                        Este envío se guardará como corporativo y reunirá
                        destinatarios de las sucursales disponibles.
                    </div>
                <?php endif; ?>

                <form id="formNotificacion">
                    <input
                        type="hidden"
                        name="csrf"
                        value="<?php echo notif_h($csrf); ?>"
                    >

                    <div class="form-row">
                        <div class="form-group col-md-8">
                            <label for="titulo">Título</label>
                            <input
                                type="text"
                                class="form-control"
                                name="titulo"
                                id="titulo"
                                maxlength="200"
                                required
                                placeholder="Ej: Horario especial por festividad"
                            >
                        </div>

                        <div class="form-group col-md-4">
                            <label for="tipo">Tipo</label>
                            <select
                                class="form-control"
                                name="tipo"
                                id="tipo"
                                required
                            >
                                <option value="info">Informativo</option>
                                <option value="aviso">Aviso</option>
                                <option value="alerta">Alerta</option>
                                <option value="promocion">Promoción</option>
                            </select>
                        </div>

                        <div class="form-group col-12">
                            <label for="mensaje">Mensaje</label>
                            <textarea
                                class="form-control"
                                name="mensaje"
                                id="mensaje"
                                required
                                placeholder="Escribe el mensaje que recibirán los destinatarios..."
                            ></textarea>
                        </div>
                    </div>

                    <div class="destinatarios-header">
                        <div>
                            <label>Selecciona los destinatarios</label>
                            <p>
                                Puedes elegir uno o dos grupos. Las dos
                                opciones de socios no se pueden seleccionar juntas.
                            </p>
                        </div>

                        <span id="seleccionContador">
                            0 de 2 seleccionados
                        </span>
                    </div>

                    <div class="destinatarios-grid">
                        <label
                            class="destinatario-card"
                            data-destinatario="socios_membresia_activa"
                        >
                            <input
                                type="checkbox"
                                class="destinatario-checkbox"
                                name="destinatarios[]"
                                value="socios_membresia_activa"
                            >
                            <span class="destinatario-check">
                                <i class="fas fa-check"></i>
                            </span>
                            <span class="destinatario-icono">
                                <i class="fas fa-id-card"></i>
                            </span>
                            <strong>Socios con membresía activa</strong>
                            <p>Inscripción activa y fecha de vigencia actual.</p>
                            <small>
                                <?php echo number_format($stats['active_members']); ?>
                                socios
                            </small>
                        </label>

                        <label
                            class="destinatario-card"
                            data-destinatario="socios_membresia_activa_vencida"
                        >
                            <input
                                type="checkbox"
                                class="destinatario-checkbox"
                                name="destinatarios[]"
                                value="socios_membresia_activa_vencida"
                            >
                            <span class="destinatario-check">
                                <i class="fas fa-check"></i>
                            </span>
                            <span class="destinatario-icono">
                                <i class="fas fa-users"></i>
                            </span>
                            <strong>Socios activos y vencidos</strong>
                            <p>Incluye membresías vigentes y vencidas.</p>
                            <small>
                                <?php echo number_format($stats['active_expired_members']); ?>
                                socios
                            </small>
                        </label>

                        <label
                            class="destinatario-card"
                            data-destinatario="usuarios_sistema"
                        >
                            <input
                                type="checkbox"
                                class="destinatario-checkbox"
                                name="destinatarios[]"
                                value="usuarios_sistema"
                            >
                            <span class="destinatario-check">
                                <i class="fas fa-check"></i>
                            </span>
                            <span class="destinatario-icono">
                                <i class="fas fa-user-shield"></i>
                            </span>
                            <strong>Usuarios del sistema</strong>
                            <p>
                                Personal activo asignado a las sucursales
                                del contexto actual.
                            </p>
                            <small>
                                <?php echo number_format($stats['active_users']); ?>
                                usuarios
                            </small>
                        </label>
                    </div>

                    <div class="destinatarios-regla" id="destinatariosRegla">
                        <i class="fas fa-circle-info"></i>
                        Puedes combinar una opción de socios con
                        “Usuarios del sistema”.
                    </div>

                    <div class="notification-form-actions">
                        <button
                            type="submit"
                            class="notification-primary-button"
                        >
                            <i class="fas fa-paper-plane"></i>
                            Enviar notificación
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <section class="notification-card expiration-card">
            <header class="notification-card-header warning">
                <div>
                    <i class="fas fa-calendar-alt"></i>
                    <span>
                        <strong>Notificaciones de vencimiento</strong>
                        <small>3 días antes y día del vencimiento</small>
                    </span>
                </div>
            </header>

            <div class="notification-card-body expiration-layout">
                <div>
                    <p>
                        El botón procesa las inscripciones originadas en
                        <strong><?php echo notif_h($branchName); ?></strong>.
                    </p>

                    <ul>
                        <li>
                            <i class="fas fa-envelope"></i>
                            Tres días antes del vencimiento.
                        </li>
                        <li>
                            <i class="fas fa-triangle-exclamation"></i>
                            El día exacto del vencimiento.
                        </li>
                        <li>
                            <i class="fas fa-rotate"></i>
                            Los intentos fallidos se pueden reintentar.
                        </li>
                    </ul>
                </div>

                <button
                    type="button"
                    id="btnProcesarVencimientos"
                    class="notification-danger-button"
                >
                    <i class="fas fa-bolt"></i>
                    Forzar envío
                </button>
            </div>
        </section>

        <section class="notification-card">
            <header class="notification-card-header dark">
                <div>
                    <i class="fas fa-history"></i>
                    <span>
                        <strong>Historial de notificaciones</strong>
                        <small>Registros del contexto seleccionado</small>
                    </span>
                </div>
            </header>

            <div class="notification-card-body history-body">
                <ul
                    class="nav nav-tabs"
                    id="historialTabs"
                    role="tablist"
                >
                    <li class="nav-item">
                        <a
                            class="nav-link active"
                            id="manual-tab"
                            data-toggle="tab"
                            href="#manual"
                            role="tab"
                        >
                            <i class="fas fa-paper-plane"></i>
                            Manuales
                        </a>
                    </li>
                    <li class="nav-item">
                        <a
                            class="nav-link"
                            id="automatica-tab"
                            data-toggle="tab"
                            href="#automatica"
                            role="tab"
                        >
                            <i class="fas fa-calendar-alt"></i>
                            Vencimientos
                        </a>
                    </li>
                </ul>

                <div class="tab-content">
                    <div
                        class="tab-pane fade show active"
                        id="manual"
                        role="tabpanel"
                    >
                        <div class="history-toolbar">
                            <div class="history-search">
                                <i class="fas fa-search"></i>
                                <input
                                    type="text"
                                    id="searchManualInput"
                                    placeholder="<?php echo $global ? 'Título, mensaje, usuario o sucursal...' : 'Título, mensaje o usuario...'; ?>"
                                    autocomplete="off"
                                >
                            </div>
                            <button
                                type="button"
                                id="clearManualSearch"
                                class="history-clear"
                                hidden
                            >
                                <i class="fas fa-times"></i>
                                Limpiar
                            </button>
                            <span
                                id="manualLoading"
                                class="history-loading"
                                hidden
                            >
                                <i class="fas fa-spinner fa-spin"></i>
                            </span>
                        </div>

                        <div id="manualResultCount" class="result-count">
                            <?php echo number_format($manualInitial['total']); ?>
                            registros
                        </div>

                        <div id="manualResultados">
                            <?php echo $manualInitial['html']; ?>
                        </div>

                        <div
                            id="manualPagination"
                            class="pagination-container"
                        ></div>
                    </div>

                    <div
                        class="tab-pane fade"
                        id="automatica"
                        role="tabpanel"
                    >
                        <div class="history-toolbar">
                            <div class="history-search">
                                <i class="fas fa-search"></i>
                                <input
                                    type="text"
                                    id="searchAutomaticaInput"
                                    placeholder="<?php echo $global ? 'Socio, correo, plan o sucursal...' : 'Socio, correo o plan...'; ?>"
                                    autocomplete="off"
                                >
                            </div>
                            <button
                                type="button"
                                id="clearAutomaticaSearch"
                                class="history-clear"
                                hidden
                            >
                                <i class="fas fa-times"></i>
                                Limpiar
                            </button>
                            <span
                                id="automaticaLoading"
                                class="history-loading"
                                hidden
                            >
                                <i class="fas fa-spinner fa-spin"></i>
                            </span>
                        </div>

                        <div id="automaticaResultCount" class="result-count">
                            <?php echo number_format($autoInitial['total']); ?>
                            registros
                        </div>

                        <div id="automaticaResultados">
                            <?php echo $autoInitial['html']; ?>
                        </div>

                        <div
                            id="automaticaPagination"
                            class="pagination-container"
                        ></div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
    (function () {
        const endpoint =
            'notificaciones.php?vista=<?php echo $view; ?>';

        const csrf = <?php echo json_encode(
            $csrf,
            JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        ); ?>;

        const contextName = <?php echo json_encode(
            $branchName,
            JSON_UNESCAPED_UNICODE
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        ); ?>;

        const initialManual = <?php echo json_encode([
            'total_paginas' => $manualInitial['total_paginas'],
            'pagina_actual' => $manualInitial['pagina_actual'],
        ]); ?>;

        const initialAutomatic = <?php echo json_encode([
            'total_paginas' => $autoInitial['total_paginas'],
            'pagina_actual' => $autoInitial['pagina_actual'],
        ]); ?>;

        const GROUP_ACTIVE = 'socios_membresia_activa';
        const GROUP_EXPIRED = 'socios_membresia_activa_vencida';
        const MAX_GROUPS = 2;

        let manualTimeout;
        let automaticTimeout;

        function escapeHtml(value) {
            return $('<div>')
                .text(String(value ?? ''))
                .html();
        }

        function ajaxMessage(xhr) {
            if (xhr.responseJSON) {
                return xhr.responseJSON.error
                    || xhr.responseJSON.message
                    || 'No fue posible completar la operación.';
            }

            if (xhr.responseText) {
                try {
                    const parsed = JSON.parse(xhr.responseText);

                    return parsed.error
                        || parsed.message
                        || 'No fue posible completar la operación.';
                } catch (error) {
                    return 'El servidor devolvió una respuesta no válida.';
                }
            }

            return 'No fue posible conectar con el servidor.';
        }

        function selectedGroups() {
            return $('.destinatario-checkbox:checked')
                .map(function () {
                    return $(this).val();
                })
                .get();
        }

        function syncRecipients() {
            const selected = selectedGroups();
            const active = selected.includes(GROUP_ACTIVE);
            const expired = selected.includes(GROUP_EXPIRED);

            $('.destinatario-card').each(function () {
                const $card = $(this);
                const $checkbox = $card.find(
                    '.destinatario-checkbox'
                );
                const value = $checkbox.val();
                const checked = $checkbox.is(':checked');

                let disabled = false;

                if (
                    active
                    && value === GROUP_EXPIRED
                    && !checked
                ) {
                    disabled = true;
                }

                if (
                    expired
                    && value === GROUP_ACTIVE
                    && !checked
                ) {
                    disabled = true;
                }

                if (
                    selected.length >= MAX_GROUPS
                    && !checked
                ) {
                    disabled = true;
                }

                $checkbox.prop('disabled', disabled);
                $card.toggleClass('selected', checked);
                $card.toggleClass('disabled', disabled);
            });

            $('#seleccionContador').text(
                selected.length
                + ' de '
                + MAX_GROUPS
                + ' seleccionados'
            );

            let message =
                'Puedes combinar una opción de socios con “Usuarios del sistema”.';

            if (active) {
                message =
                    'La opción de socios activos y vencidos quedó bloqueada.';
            }

            if (expired) {
                message =
                    'La opción de socios con membresía activa quedó bloqueada.';
            }

            if (selected.length >= MAX_GROUPS) {
                message = 'Alcanzaste el máximo de dos grupos.';
            }

            $('#destinatariosRegla').html(
                '<i class="fas fa-circle-info"></i>'
                + escapeHtml(message)
            );
        }

        $('.destinatario-checkbox').on('change', function () {
            let selected = selectedGroups();

            if (selected.length > MAX_GROUPS) {
                $(this).prop('checked', false);
            }

            selected = selectedGroups();

            if (
                selected.includes(GROUP_ACTIVE)
                && selected.includes(GROUP_EXPIRED)
            ) {
                $(this).prop('checked', false);

                Swal.fire({
                    icon: 'info',
                    title: 'Opciones incompatibles',
                    text:
                        'Las dos opciones de socios no pueden seleccionarse juntas.',
                    confirmButtonColor: '#1e3a8a'
                });
            }

            syncRecipients();
        });

        syncRecipients();

        function pagination(
            container,
            totalPages,
            currentPage,
            loader
        ) {
            const $container = $(container);

            if (totalPages <= 1) {
                $container.empty();
                return;
            }

            const start = Math.max(1, currentPage - 2);
            const end = Math.min(totalPages, currentPage + 2);
            let html =
                '<nav><ul class="pagination justify-content-center">';

            html +=
                '<li class="page-item '
                + (currentPage <= 1 ? 'disabled' : '')
                + '"><button class="page-link" data-page="'
                + (currentPage - 1)
                + '">Anterior</button></li>';

            for (let page = start; page <= end; page++) {
                html +=
                    '<li class="page-item '
                    + (page === currentPage ? 'active' : '')
                    + '"><button class="page-link" data-page="'
                    + page
                    + '">'
                    + page
                    + '</button></li>';
            }

            html +=
                '<li class="page-item '
                + (currentPage >= totalPages ? 'disabled' : '')
                + '"><button class="page-link" data-page="'
                + (currentPage + 1)
                + '">Siguiente</button></li>';

            html += '</ul></nav>';
            $container.html(html);

            $container.find('.page-link').on(
                'click',
                function () {
                    const page = Number($(this).data('page'));

                    if (
                        page >= 1
                        && page <= totalPages
                        && page !== currentPage
                    ) {
                        loader(page);
                    }
                }
            );
        }

        function loadManual(page = 1) {
            const search =
                $('#searchManualInput').val().trim();

            $('#manualLoading').prop('hidden', false);

            $.ajax({
                url: endpoint,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'buscar_manuales',
                    csrf: csrf,
                    search: search,
                    page: page
                }
            }).done(function (response) {
                $('#manualResultados').html(response.html);
                $('#manualResultCount').text(
                    response.total
                    + (
                        response.total === 1
                            ? ' registro'
                            : ' registros'
                    )
                );
                $('#clearManualSearch').prop(
                    'hidden',
                    search === ''
                );
                pagination(
                    '#manualPagination',
                    response.total_paginas,
                    response.pagina_actual,
                    loadManual
                );
            }).fail(function (xhr) {
                Swal.fire({
                    icon: 'error',
                    title: 'No se pudo cargar el historial',
                    text: ajaxMessage(xhr),
                    confirmButtonColor: '#1e3a8a'
                });
            }).always(function () {
                $('#manualLoading').prop('hidden', true);
            });
        }

        function loadAutomatic(page = 1) {
            const search =
                $('#searchAutomaticaInput').val().trim();

            $('#automaticaLoading').prop('hidden', false);

            $.ajax({
                url: endpoint,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'buscar_automaticas',
                    csrf: csrf,
                    search: search,
                    page: page
                }
            }).done(function (response) {
                $('#automaticaResultados').html(response.html);
                $('#automaticaResultCount').text(
                    response.total
                    + (
                        response.total === 1
                            ? ' registro'
                            : ' registros'
                    )
                );
                $('#clearAutomaticaSearch').prop(
                    'hidden',
                    search === ''
                );
                pagination(
                    '#automaticaPagination',
                    response.total_paginas,
                    response.pagina_actual,
                    loadAutomatic
                );
            }).fail(function (xhr) {
                Swal.fire({
                    icon: 'error',
                    title: 'No se pudo cargar el historial',
                    text: ajaxMessage(xhr),
                    confirmButtonColor: '#1e3a8a'
                });
            }).always(function () {
                $('#automaticaLoading').prop('hidden', true);
            });
        }

        pagination(
            '#manualPagination',
            initialManual.total_paginas,
            initialManual.pagina_actual,
            loadManual
        );

        pagination(
            '#automaticaPagination',
            initialAutomatic.total_paginas,
            initialAutomatic.pagina_actual,
            loadAutomatic
        );

        $('#searchManualInput').on('input', function () {
            clearTimeout(manualTimeout);
            manualTimeout = setTimeout(function () {
                loadManual(1);
            }, 450);
        });

        $('#searchAutomaticaInput').on('input', function () {
            clearTimeout(automaticTimeout);
            automaticTimeout = setTimeout(function () {
                loadAutomatic(1);
            }, 450);
        });

        $('#clearManualSearch').on('click', function () {
            $('#searchManualInput').val('');
            loadManual(1);
        });

        $('#clearAutomaticaSearch').on('click', function () {
            $('#searchAutomaticaInput').val('');
            loadAutomatic(1);
        });

        $('#formNotificacion').on('submit', function (event) {
            event.preventDefault();

            const $form = $(this);
            const groups = selectedGroups();
            const title = $('#titulo').val().trim();
            const message = $('#mensaje').val().trim();

            if (title === '' || message === '') {
                Swal.fire({
                    icon: 'error',
                    title: 'Campos incompletos',
                    text: 'Completa el título y el mensaje.',
                    confirmButtonColor: '#1e3a8a'
                });
                return;
            }

            if (groups.length === 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Selecciona destinatarios',
                    text: 'Selecciona por lo menos un grupo.',
                    confirmButtonColor: '#1e3a8a'
                });
                return;
            }

            const names = $('.destinatario-checkbox:checked')
                .map(function () {
                    return $(this)
                        .closest('.destinatario-card')
                        .find('strong')
                        .first()
                        .text()
                        .trim();
                })
                .get();

            const list = names.map(function (name) {
                return '<li>' + escapeHtml(name) + '</li>';
            }).join('');

            Swal.fire({
                icon: 'question',
                title: '¿Enviar notificación?',
                html:
                    '<div class="swal-notification-summary">'
                    + '<strong>Destinatarios:</strong>'
                    + '<ul>' + list + '</ul>'
                    + '<span>' + escapeHtml(contextName) + '</span>'
                    + '</div>',
                showCancelButton: true,
                confirmButtonText: 'Sí, enviar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#1e3a8a',
                cancelButtonColor: '#64748b'
            }).then(function (confirmation) {
                if (!confirmation.isConfirmed) {
                    return;
                }

                Swal.fire({
                    title: 'Enviando correos',
                    text:
                        'Espera mientras se procesan los destinatarios.',
                    allowOutsideClick: false,
                    didOpen: function () {
                        Swal.showLoading();
                    }
                });

                const data = $form.serializeArray();
                data.push({
                    name: 'action',
                    value: 'enviar_notificacion'
                });

                $.ajax({
                    url: endpoint,
                    method: 'POST',
                    dataType: 'json',
                    data: data
                }).done(function (response) {
                    const errors =
                        Array.isArray(response.errores)
                            ? response.errores
                            : [];

                    let html =
                        '<div class="swal-result-grid">'
                        + '<div><strong>'
                        + response.enviados
                        + '</strong><span>Enviados</span></div>'
                        + '<div><strong>'
                        + response.fallidos
                        + '</strong><span>Fallidos</span></div>'
                        + '<div><strong>'
                        + response.total
                        + '</strong><span>Total</span></div>'
                        + '</div>';

                    if (errors.length > 0) {
                        html +=
                            '<details class="swal-error-details">'
                            + '<summary>Ver errores SMTP</summary>'
                            + '<ul>'
                            + errors.map(function (error) {
                                return '<li>'
                                    + escapeHtml(error)
                                    + '</li>';
                            }).join('')
                            + '</ul>'
                            + '</details>';
                    }

                    Swal.fire({
                        icon:
                            response.fallidos > 0
                                ? 'warning'
                                : 'success',
                        title:
                            response.fallidos > 0
                                ? 'Envío completado con incidencias'
                                : 'Notificaciones enviadas',
                        html: html,
                        confirmButtonText: 'Entendido',
                        confirmButtonColor: '#1e3a8a'
                    }).then(function () {
                        window.location.reload();
                    });
                }).fail(function (xhr) {
                    const response = xhr.responseJSON || {};
                    let html = escapeHtml(
                        response.error || ajaxMessage(xhr)
                    );

                    if (
                        Array.isArray(response.errores)
                        && response.errores.length > 0
                    ) {
                        html +=
                            '<details class="swal-error-details">'
                            + '<summary>Ver errores SMTP</summary>'
                            + '<ul>'
                            + response.errores.map(function (error) {
                                return '<li>'
                                    + escapeHtml(error)
                                    + '</li>';
                            }).join('')
                            + '</ul>'
                            + '</details>';
                    }

                    Swal.fire({
                        icon: 'error',
                        title: 'No se enviaron los correos',
                        html: html,
                        confirmButtonText: 'Entendido',
                        confirmButtonColor: '#1e3a8a'
                    });
                });
            });
        });

        $('#btnProcesarVencimientos').on('click', function () {
            Swal.fire({
                icon: 'warning',
                title: '¿Forzar el proceso?',
                html:
                    '<p>Se revisarán únicamente las membresías que vencen hoy o dentro de 3 días.</p>'
                    + '<p><strong>Contexto:</strong> '
                    + escapeHtml(contextName)
                    + '</p>'
                    + '<small>Los envíos exitosos no se repetirán. Los fallidos sí podrán reintentarse.</small>',
                showCancelButton: true,
                confirmButtonText: 'Sí, procesar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#64748b'
            }).then(function (confirmation) {
                if (!confirmation.isConfirmed) {
                    return;
                }

                Swal.fire({
                    title: 'Procesando vencimientos',
                    text:
                        'La duración depende del número de correos.',
                    allowOutsideClick: false,
                    didOpen: function () {
                        Swal.showLoading();
                    }
                });

                $.ajax({
                    url: endpoint,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'procesar_vencimientos',
                        csrf: csrf
                    }
                }).done(function (response) {
                    const data = response.detalles;
                    const errors =
                        Array.isArray(data.errores_detalle)
                            ? data.errores_detalle
                            : [];

                    let html =
                        '<div class="swal-result-grid force">'
                        + '<div><strong>'
                        + data.encontradas
                        + '</strong><span>Encontradas</span></div>'
                        + '<div><strong>'
                        + data.enviados_3_dias
                        + '</strong><span>3 días</span></div>'
                        + '<div><strong>'
                        + data.enviados_vencidos
                        + '</strong><span>Vencen hoy</span></div>'
                        + '<div><strong>'
                        + data.omitidas_ya_enviadas
                        + '</strong><span>Ya enviadas</span></div>'
                        + '<div><strong>'
                        + data.errores
                        + '</strong><span>Errores</span></div>'
                        + '</div>';

                    if (errors.length > 0) {
                        html +=
                            '<details class="swal-error-details" open>'
                            + '<summary>Detalle de errores</summary>'
                            + '<ul>'
                            + errors.map(function (error) {
                                return '<li>'
                                    + escapeHtml(error)
                                    + '</li>';
                            }).join('')
                            + '</ul>'
                            + '</details>';
                    }

                    if (data.encontradas === 0) {
                        html +=
                            '<p class="swal-neutral-note">'
                            + 'No existen membresías activas que venzan hoy o dentro de 3 días en este contexto.'
                            + '</p>';
                    }

                    Swal.fire({
                        icon:
                            data.errores > 0
                                ? 'warning'
                                : 'success',
                        title:
                            data.errores > 0
                                ? 'Proceso terminado con incidencias'
                                : 'Proceso terminado',
                        html: html,
                        confirmButtonText: 'Entendido',
                        confirmButtonColor: '#1e3a8a'
                    }).then(function () {
                        loadAutomatic(1);
                    });
                }).fail(function (xhr) {
                    Swal.fire({
                        icon: 'error',
                        title: 'No se pudo procesar',
                        text: ajaxMessage(xhr),
                        confirmButtonText: 'Entendido',
                        confirmButtonColor: '#1e3a8a'
                    });
                });
            });
        });

        if (window.location.hash === '#automatica') {
            $('#automatica-tab').tab('show');
        }
    })();
    </script>
</body>
</html>