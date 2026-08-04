<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/two_factor_helper.php';

$database = new Database();
$conn = $database->getConnection();

if (!$conn) {
    http_response_code(500);
    exit('No fue posible conectar con la base de datos.');
}

$userId = (int) $_SESSION['user_id'];

$tabPerfil = strtolower(trim((string) (
    $_GET['tab'] ?? 'datos'
)));

if (!in_array(
    $tabPerfil,
    ['datos', 'seguridad'],
    true
)) {
    $tabPerfil = 'datos';
}

function responderJson(bool $success, string $message, array $extra = []): void
{
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        array_merge(['success' => $success, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE
    );
    exit();
}

function obtenerUsuario(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare(
        'SELECT
            u.id,
            u.nombre,
            u.email,
            u.password,
            u.rol,
            u.foto_perfil,
            u.fecha_registro,
            u.ultimo_cambio_password,
            COALESCE(u.auth_version, 1) AS auth_version,
            COALESCE(u2.enabled, 0) AS two_factor_enabled,
            u2.secret_encrypted,
            u2.last_counter,
            u2.confirmed_at,
            u2.last_verified_at
         FROM usuarios u
         LEFT JOIN usuarios_2fa u2 ON u2.usuario_id = u.id
         WHERE u.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result->fetch_assoc() ?: null;
}

$user = obtenerUsuario($conn, $userId);

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit();
}

if (empty($_SESSION['profile_csrf'])) {
    $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
}

/**
 * Confirma la contraseña actual y un código TOTP vigente antes de ejecutar
 * una operación sensible de segundo factor.
 */
function perfilValidarSegundoFactor(
    mysqli $conn,
    array $user,
    string $currentPassword,
    string $code
): void {
    if (!password_verify($currentPassword, (string) ($user['password'] ?? ''))) {
        throw new RuntimeException('La contraseña actual no es correcta.');
    }

    if (!two_factor_user_enabled($user)) {
        throw new RuntimeException('La verificación en dos pasos todavía no está activa.');
    }

    $secret = two_factor_decrypt_secret((string) $user['secret_encrypted']);
    $counter = two_factor_verify_totp(
        $secret,
        $code,
        (int) ($user['last_counter'] ?? -1),
        1
    );

    if ($counter === null) {
        throw new RuntimeException('El código de la aplicación autenticadora no es válido.');
    }

    $stmt = $conn->prepare(
        'UPDATE usuarios_2fa
         SET last_counter = ?, last_verified_at = NOW(),
             failed_attempts = 0, locked_until = NULL,
             updated_at = CURRENT_TIMESTAMP
         WHERE usuario_id = ?'
    );
    $userId = (int) $user['id'];
    $stmt->bind_param('ii', $counter, $userId);
    $stmt->execute();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf'] ?? '');
    $csrfExpected = (string) ($_SESSION['profile_csrf'] ?? '');

    if ($csrfExpected === '' || !hash_equals($csrfExpected, $csrf)) {
        responderJson(false, 'La sesión de seguridad cambió. Recarga la página.');
    }
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'update_profile') {
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));

        if ($nombre === '' || $email === '') {
            responderJson(false, 'El nombre y el correo son obligatorios.');
        }

        if (mb_strlen($nombre) > 120) {
            responderJson(false, 'El nombre es demasiado largo.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            responderJson(false, 'Ingresa un correo electrónico válido.');
        }

        $check = $conn->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ? LIMIT 1');
        $check->bind_param('si', $email, $userId);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            responderJson(false, 'Ese correo ya está registrado por otro usuario.');
        }

        $update = $conn->prepare('UPDATE usuarios SET nombre = ?, email = ? WHERE id = ?');
        $update->bind_param('ssi', $nombre, $email, $userId);

        if (!$update->execute()) {
            responderJson(false, 'No se pudieron guardar los cambios.');
        }

        $_SESSION['user_name'] = $nombre;
        $_SESSION['user_email'] = $email;

        responderJson(true, 'Perfil actualizado correctamente.', [
            'nombre' => $nombre,
            'email' => $email,
        ]);
    }

    if ($action === 'update_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            responderJson(false, 'Completa la contraseña actual y los dos campos nuevos.');
        }

        if (!password_verify($currentPassword, (string) ($user['password'] ?? ''))) {
            responderJson(false, 'La contraseña actual no es correcta.');
        }

        if (strlen($newPassword) < 6) {
            responderJson(false, 'La contraseña debe tener al menos 6 caracteres.');
        }

        if ($newPassword !== $confirmPassword) {
            responderJson(false, 'Las contraseñas no coinciden.');
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $update = $conn->prepare(
            'UPDATE usuarios
             SET password = ?,
                 password_change_required = 0,
                 ultimo_cambio_password = NOW(),
                 auth_version = auth_version + 1
             WHERE id = ?'
        );
        $update->bind_param('si', $hashedPassword, $userId);

        if (!$update->execute()) {
            responderJson(false, 'No se pudo actualizar la contraseña.');
        }

        $versionResult = $conn->prepare(
            'SELECT auth_version FROM usuarios WHERE id = ? LIMIT 1'
        );
        $versionResult->bind_param('i', $userId);
        $versionResult->execute();
        $versionRow = $versionResult->get_result()->fetch_assoc();
        $versionResult->close();
        $_SESSION['auth_version'] = (int) ($versionRow['auth_version'] ?? 1);

        two_factor_revoke_devices($conn, $userId);
        two_factor_forget_trusted_cookie();

        two_factor_log_event(
            $conn,
            $userId,
            'password_actualizado',
            'Se cambió la contraseña y se invalidaron las demás sesiones.'
        );

        responderJson(true, 'Contraseña actualizada. Las demás sesiones y dispositivos confiables fueron revocados.');
    }

    if ($action === 'revoke_2fa_devices') {
        try {
            perfilValidarSegundoFactor(
                $conn,
                $user,
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['two_factor_code'] ?? '')
            );

            two_factor_revoke_devices($conn, $userId);
            two_factor_forget_trusted_cookie();

            $versionStmt = $conn->prepare(
                'UPDATE usuarios
                 SET auth_version = auth_version + 1
                 WHERE id = ?'
            );
            $versionStmt->bind_param('i', $userId);
            $versionStmt->execute();
            $versionStmt->close();

            $versionStmt = $conn->prepare(
                'SELECT auth_version FROM usuarios WHERE id = ? LIMIT 1'
            );
            $versionStmt->bind_param('i', $userId);
            $versionStmt->execute();
            $newVersionRow = $versionStmt->get_result()->fetch_assoc();
            $versionStmt->close();
            $_SESSION['auth_version'] = (int) ($newVersionRow['auth_version'] ?? 1);

            two_factor_log_event(
                $conn,
                $userId,
                '2fa_dispositivos_revocados',
                'Se revocaron dispositivos confiables y se cerraron las demás sesiones.'
            );

            responderJson(true, 'Los dispositivos confiables fueron revocados y las demás sesiones se cerraron.');
        } catch (Throwable $errorSeguridad) {
            responderJson(false, $errorSeguridad->getMessage());
        }
    }

    if ($action === 'regenerate_recovery_codes') {
        try {
            perfilValidarSegundoFactor(
                $conn,
                $user,
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['two_factor_code'] ?? '')
            );

            $codes = two_factor_generate_recovery_codes(10);
            $json = two_factor_hash_recovery_codes($codes);
            $stmt = $conn->prepare(
                'UPDATE usuarios_2fa
                 SET recovery_codes_json = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE usuario_id = ?'
            );
            $stmt->bind_param('si', $json, $userId);
            $stmt->execute();
            $stmt->close();

            two_factor_log_event(
                $conn,
                $userId,
                '2fa_codigos_regenerados',
                'Se regeneraron los códigos de recuperación.'
            );

            responderJson(true, 'Códigos de recuperación regenerados.', [
                'codes' => $codes,
            ]);
        } catch (Throwable $errorSeguridad) {
            responderJson(false, $errorSeguridad->getMessage());
        }
    }

    if ($action === 'reconfigure_2fa') {
        try {
            perfilValidarSegundoFactor(
                $conn,
                $user,
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['two_factor_code'] ?? '')
            );

            $conn->begin_transaction();
            try {
                two_factor_revoke_devices($conn, $userId);

                $delete = $conn->prepare(
                    'DELETE FROM usuarios_2fa WHERE usuario_id = ?'
                );
                $delete->bind_param('i', $userId);
                $delete->execute();
                $delete->close();

                $version = $conn->prepare(
                    'UPDATE usuarios
                     SET auth_version = auth_version + 1
                     WHERE id = ?'
                );
                $version->bind_param('i', $userId);
                $version->execute();
                $version->close();

                $conn->commit();
            } catch (Throwable $transactionError) {
                $conn->rollback();
                throw $transactionError;
            }

            two_factor_forget_trusted_cookie();
            two_factor_log_event(
                $conn,
                $userId,
                '2fa_reconfiguracion_iniciada',
                'Se eliminó la configuración anterior para vincular una nueva aplicación.'
            );

            $pendingUser = two_factor_get_user($conn, $userId);
            if (!$pendingUser) {
                throw new RuntimeException('No fue posible preparar la nueva configuración.');
            }

            two_factor_start_pending($pendingUser);

            responderJson(true, 'La configuración anterior fue retirada.', [
                'redirect' => 'configurar_2fa.php',
            ]);
        } catch (Throwable $errorSeguridad) {
            responderJson(false, $errorSeguridad->getMessage());
        }
    }

    if ($action === 'update_photo') {
        if (!isset($_FILES['foto_perfil']) || $_FILES['foto_perfil']['error'] !== UPLOAD_ERR_OK) {
            responderJson(false, 'Selecciona una imagen válida.');
        }

        $archivo = $_FILES['foto_perfil'];
        $maxSize = 10 * 1024 * 1024;

        if ((int) $archivo['size'] > $maxSize) {
            responderJson(false, 'La imagen no puede superar los 10 MB.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($archivo['tmp_name']);
        $extensiones = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($extensiones[$mime])) {
            responderJson(false, 'Solo se permiten imágenes JPG, PNG o WEBP.');
        }

        $directorio = 'uploads/perfiles/';
        if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
            responderJson(false, 'No fue posible preparar la carpeta de perfiles.');
        }

        $nombreArchivo = 'perfil_' . $userId . '_' . bin2hex(random_bytes(5)) . '.' . $extensiones[$mime];
        $rutaNueva = $directorio . $nombreArchivo;

        if (!move_uploaded_file($archivo['tmp_name'], $rutaNueva)) {
            responderJson(false, 'No fue posible guardar la imagen.');
        }

        $update = $conn->prepare('UPDATE usuarios SET foto_perfil = ? WHERE id = ?');
        $update->bind_param('si', $rutaNueva, $userId);

        if (!$update->execute()) {
            @unlink($rutaNueva);
            responderJson(false, 'No fue posible actualizar la foto de perfil.');
        }

        $rutaAnterior = trim((string) ($user['foto_perfil'] ?? ''));
        if ($rutaAnterior !== '' && $rutaAnterior !== $rutaNueva && is_file($rutaAnterior)) {
            @unlink($rutaAnterior);
        }

        responderJson(true, 'Foto actualizada correctamente.', [
            'avatar_url' => $rutaNueva,
        ]);
    }

    responderJson(false, 'Acción no válida.');
}

$configResult = $conn->query('SELECT nombre FROM configuracion_gimnasio WHERE id = 1 LIMIT 1');
$configGym = $configResult ? $configResult->fetch_assoc() : [];
$gymNombre = trim((string) ($configGym['nombre'] ?? 'Ego Gym')) ?: 'Ego Gym';

$config2fa = two_factor_get_config($conn);
$twoFactorEnabled = two_factor_user_enabled($user);
$twoFactorRequired = two_factor_role_required(
    $config2fa,
    (string) $user['rol']
);

$trustedDevices = 0;
$stmtDevices = $conn->prepare(
    'SELECT COUNT(*) AS total
     FROM usuarios_2fa_dispositivos
     WHERE usuario_id = ?
       AND revoked_at IS NULL
       AND expires_at > NOW()'
);
$stmtDevices->bind_param('i', $userId);
$stmtDevices->execute();
$deviceRow = $stmtDevices->get_result()->fetch_assoc();
$stmtDevices->close();
$trustedDevices = (int) ($deviceRow['total'] ?? 0);

$twoFactorConfirmed = !empty($user['confirmed_at'])
    ? date('d/m/Y H:i', strtotime((string) $user['confirmed_at']))
    : 'Sin configurar';
$twoFactorLastVerified = !empty($user['last_verified_at'])
    ? date('d/m/Y H:i', strtotime((string) $user['last_verified_at']))
    : 'Sin registro';

$roles = [
    'admin' => 'Administrador',
    'recepcionista' => 'Recepcionista',
    'entrenador' => 'Entrenador',
];
$rolTexto = $roles[$user['rol']] ?? ucfirst((string) $user['rol']);

$fechaRegistro = !empty($user['fecha_registro'])
    ? date('d/m/Y', strtotime($user['fecha_registro']))
    : 'Sin fecha';

$avatarFallback = 'https://ui-avatars.com/api/?background=1e3a8a&color=fff&bold=true&size=240&name=' . urlencode((string) $user['nombre']);
$avatarUrl = !empty($user['foto_perfil']) && is_file($user['foto_perfil'])
    ? $user['foto_perfil']
    : $avatarFallback;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi perfil - <?php echo htmlspecialchars($gymNombre); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/mi_perfil.css?v=3.0.0">
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content" id="contenido-principal">
        <div class="profile-page">
            <header class="page-heading">
                <div>
                    <h1>Mi perfil</h1>
                    <p class="page-description">Actualiza tus datos personales, foto y contraseña.</p>
                </div>
            </header>

            <div class="profile-layout">
                <aside class="profile-summary-card" aria-label="Resumen de la cuenta">
                    <div class="profile-avatar-wrap">
                        <img
                            id="avatar-img"
                            class="profile-avatar-img"
                            src="<?php echo htmlspecialchars($avatarUrl); ?>"
                            data-fallback="<?php echo htmlspecialchars($avatarFallback); ?>"
                            alt="Foto de perfil de <?php echo htmlspecialchars($user['nombre']); ?>"
                        >
                        <button type="button" class="avatar-edit-button" id="btnCambiarFoto" aria-label="Cambiar foto de perfil">
                            <i class="fas fa-camera" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="profile-identity">
                        <h2 id="profile-name-display"><?php echo htmlspecialchars($user['nombre']); ?></h2>
                        <p id="profile-email-display"><?php echo htmlspecialchars($user['email']); ?></p>
                    </div>

                    <div class="profile-badges">
                        <span class="profile-badge">
                            <i class="fas fa-user-shield" aria-hidden="true"></i>
                            <?php echo htmlspecialchars($rolTexto); ?>
                        </span>
                        <span class="profile-badge profile-badge-muted">
                            <i class="fas fa-calendar" aria-hidden="true"></i>
                            Desde <?php echo htmlspecialchars($fechaRegistro); ?>
                        </span>
                    </div>

                    <button type="button" class="change-photo-link" id="btnCambiarFotoTexto">
                        <i class="fas fa-image" aria-hidden="true"></i>
                        Cambiar foto
                    </button>
                    <p class="photo-help">JPG, PNG o WEBP · máximo 10 MB</p>
                </aside>

                <section class="profile-settings-card">
                    <div class="settings-tabs" role="tablist" aria-label="Opciones del perfil">
                        <button
                            type="button"
                            class="settings-tab <?php echo
                                $tabPerfil === 'datos'
                                    ? 'active'
                                    : '';
                            ?>"
                            data-tab="datos"
                            role="tab"
                            aria-selected="<?php echo
                                $tabPerfil === 'datos'
                                    ? 'true'
                                    : 'false';
                            ?>"
                        >
                            <i class="fas fa-user" aria-hidden="true"></i>
                            Datos personales
                        </button>

                        <button
                            type="button"
                            class="settings-tab <?php echo
                                $tabPerfil === 'seguridad'
                                    ? 'active'
                                    : '';
                            ?>"
                            data-tab="seguridad"
                            role="tab"
                            aria-selected="<?php echo
                                $tabPerfil === 'seguridad'
                                    ? 'true'
                                    : 'false';
                            ?>"
                        >
                            <i class="fas fa-lock" aria-hidden="true"></i>
                            Seguridad
                        </button>
                    </div>

                    <div
                        class="settings-panel <?php echo
                            $tabPerfil === 'datos'
                                ? 'active'
                                : '';
                        ?>"
                        data-panel="datos"
                        role="tabpanel"
                        <?php echo $tabPerfil !== 'datos'
                            ? 'hidden'
                            : ''; ?>
                    >
                        <div class="panel-heading">
                            <h2>Información personal</h2>
                            <p>Estos datos identifican tu cuenta dentro del sistema.</p>
                        </div>

                        <form id="profileForm" novalidate>
                            <div class="form-grid">
                                <div class="form-field form-field-full">
                                    <label for="nombre">Nombre completo</label>
                                    <div class="input-control">
                                        <i class="fas fa-user" aria-hidden="true"></i>
                                        <input
                                            type="text"
                                            id="nombre"
                                            name="nombre"
                                            value="<?php echo htmlspecialchars($user['nombre']); ?>"
                                            maxlength="120"
                                            autocomplete="name"
                                            required
                                        >
                                    </div>
                                </div>

                                <div class="form-field form-field-full">
                                    <label for="email">Correo electrónico</label>
                                    <div class="input-control">
                                        <i class="fas fa-envelope" aria-hidden="true"></i>
                                        <input
                                            type="email"
                                            id="email"
                                            name="email"
                                            value="<?php echo htmlspecialchars($user['email']); ?>"
                                            autocomplete="email"
                                            required
                                        >
                                    </div>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="primary-button" id="btnGuardarPerfil">
                                    <i class="fas fa-check" aria-hidden="true"></i>
                                    Guardar cambios
                                </button>
                            </div>
                        </form>
                    </div>

                    <div
                        class="settings-panel <?php echo
                            $tabPerfil === 'seguridad'
                                ? 'active'
                                : '';
                        ?>"
                        data-panel="seguridad"
                        role="tabpanel"
                        <?php echo $tabPerfil !== 'seguridad'
                            ? 'hidden'
                            : ''; ?>
                    >
                        <div class="panel-heading">
                            <h2>Cambiar contraseña</h2>
                            <p>Utiliza al menos 6 caracteres y evita contraseñas fáciles de adivinar.</p>
                        </div>

                        <form id="passwordForm" novalidate>
                            <div class="form-grid">
                                <div class="form-field form-field-full">
                                    <label for="current_password">Contraseña actual</label>
                                    <div class="input-control password-control">
                                        <i class="fas fa-lock" aria-hidden="true"></i>
                                        <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                                        <button type="button" class="password-toggle" data-target="current_password" aria-label="Mostrar contraseña">
                                            <i class="fas fa-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="form-field">
                                    <label for="new_password">Nueva contraseña</label>
                                    <div class="input-control password-control">
                                        <i class="fas fa-key" aria-hidden="true"></i>
                                        <input type="password" id="new_password" name="new_password" minlength="6" autocomplete="new-password" required>
                                        <button type="button" class="password-toggle" data-target="new_password" aria-label="Mostrar contraseña">
                                            <i class="fas fa-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="form-field">
                                    <label for="confirm_password">Confirmar contraseña</label>
                                    <div class="input-control password-control">
                                        <i class="fas fa-shield-halved" aria-hidden="true"></i>
                                        <input type="password" id="confirm_password" name="confirm_password" minlength="6" autocomplete="new-password" required>
                                        <button type="button" class="password-toggle" data-target="confirm_password" aria-label="Mostrar contraseña">
                                            <i class="fas fa-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="password-status" id="passwordStatus" aria-live="polite">
                                <span class="password-meter"><span id="passwordMeterBar"></span></span>
                                <span id="passwordStatusText">Mínimo 6 caracteres</span>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="primary-button" id="btnGuardarPassword">
                                    <i class="fas fa-lock" aria-hidden="true"></i>
                                    Actualizar contraseña
                                </button>
                            </div>
                        </form>

                        <section class="two-factor-profile-card" aria-labelledby="two-factor-profile-title">
                            <div class="two-factor-profile-heading">
                                <span class="two-factor-profile-icon">
                                    <i class="fas fa-shield-halved" aria-hidden="true"></i>
                                </span>
                                <div>
                                    <div class="two-factor-title-row">
                                        <h2 id="two-factor-profile-title">Verificación en dos pasos</h2>
                                        <span class="two-factor-status <?php echo $twoFactorEnabled ? 'active' : 'inactive'; ?>">
                                            <i class="fas <?php echo $twoFactorEnabled ? 'fa-circle-check' : 'fa-circle-xmark'; ?>"></i>
                                            <?php echo $twoFactorEnabled ? 'Activa' : 'Sin configurar'; ?>
                                        </span>
                                    </div>
                                    <p>Protege tu cuenta con un código temporal generado en tu teléfono.</p>
                                </div>
                            </div>

                            <?php if ($twoFactorEnabled): ?>
                                <div class="two-factor-metrics">
                                    <div>
                                        <small>Activada</small>
                                        <strong><?php echo htmlspecialchars($twoFactorConfirmed, ENT_QUOTES, 'UTF-8'); ?></strong>
                                    </div>
                                    <div>
                                        <small>Última verificación</small>
                                        <strong><?php echo htmlspecialchars($twoFactorLastVerified, ENT_QUOTES, 'UTF-8'); ?></strong>
                                    </div>
                                    <div>
                                        <small>Dispositivos confiables</small>
                                        <strong><?php echo (int) $trustedDevices; ?></strong>
                                    </div>
                                </div>

                                <div class="two-factor-actions">
                                    <button type="button" class="security-action-button" data-2fa-action="regenerate_recovery_codes">
                                        <i class="fas fa-key"></i>
                                        <span><strong>Nuevos códigos</strong><small>Invalida los códigos anteriores.</small></span>
                                    </button>
                                    <button type="button" class="security-action-button" data-2fa-action="revoke_2fa_devices">
                                        <i class="fas fa-laptop-slash"></i>
                                        <span><strong>Revocar dispositivos</strong><small>Solicitará el código en todos los equipos.</small></span>
                                    </button>
                                    <button type="button" class="security-action-button danger" data-2fa-action="reconfigure_2fa">
                                        <i class="fas fa-rotate"></i>
                                        <span><strong>Cambiar autenticador</strong><small>Retira la vinculación actual y crea una nueva.</small></span>
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="two-factor-empty">
                                    <i class="fas fa-mobile-screen-button"></i>
                                    <div>
                                        <strong>La cuenta necesita vincular una aplicación autenticadora.</strong>
                                        <span><?php echo $twoFactorRequired
                                            ? 'La configuración es obligatoria para tu rol y se solicitará en el próximo inicio.'
                                            : 'El administrador puede hacer obligatoria esta protección para tu rol.'; ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="two-factor-privacy-note">
                                <i class="fas fa-lock"></i>
                                <span>Los secretos se almacenan cifrados. Nunca se guarda el código de seis dígitos.</span>
                            </div>
                        </section>
                    </div>
                </section>
            </div>
        </div>
    </main>

    <input type="file" id="foto_input" accept="image/jpeg,image/png,image/webp" hidden>

    <script>
    const profileEndpoint = 'mi_perfil.php';
    const profileCsrf = <?php echo json_encode((string) $_SESSION['profile_csrf'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#039;',
            '"': '&quot;'
        }[character]));
    }

    async function enviarFormulario(formData) {
        if (!formData.has('csrf')) {
            formData.append('csrf', profileCsrf);
        }

        const response = await fetch(profileEndpoint, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const text = await response.text();
        let data;

        try {
            data = JSON.parse(text);
        } catch (error) {
            throw new Error('El servidor devolvió una respuesta no válida.');
        }

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'No fue posible completar la operación.');
        }

        return data;
    }

    function mostrarExito(message) {
        return Swal.fire({
            icon: 'success',
            title: 'Listo',
            text: message,
            showConfirmButton: false,
            timer: 1800,
            timerProgressBar: true
        });
    }

    function mostrarError(message) {
        return Swal.fire({
            icon: 'error',
            title: 'No se pudo guardar',
            text: message,
            confirmButtonText: 'Entendido',
            confirmButtonColor: '#1e3a8a'
        });
    }

    function setButtonLoading(button, loading, loadingText = 'Guardando...') {
        if (!button) return;

        if (loading) {
            button.dataset.originalHtml = button.innerHTML;
            button.disabled = true;
            button.innerHTML = `<i class="fas fa-circle-notch fa-spin"></i>${escapeHtml(loadingText)}`;
        } else {
            button.disabled = false;
            button.innerHTML = button.dataset.originalHtml || button.innerHTML;
        }
    }

    document.querySelectorAll('.settings-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.tab;

            document.querySelectorAll('.settings-tab').forEach(item => {
                const active = item === tab;
                item.classList.toggle('active', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
            });

            document.querySelectorAll('.settings-panel').forEach(panel => {
                const active = panel.dataset.panel === target;
                panel.classList.toggle('active', active);
                panel.hidden = !active;
            });

            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('tab', target);

            window.history.replaceState(
                {},
                '',
                currentUrl.pathname + currentUrl.search
            );
        });
    });

    document.querySelectorAll('.password-toggle').forEach(button => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.target);
            const icon = button.querySelector('i');
            const mostrar = input.type === 'password';

            input.type = mostrar ? 'text' : 'password';
            icon.classList.toggle('fa-eye', !mostrar);
            icon.classList.toggle('fa-eye-slash', mostrar);
            button.setAttribute('aria-label', mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña');
        });
    });

    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');
    const passwordMeterBar = document.getElementById('passwordMeterBar');
    const passwordStatus = document.getElementById('passwordStatus');
    const passwordStatusText = document.getElementById('passwordStatusText');

    function actualizarEstadoPassword() {
        if (
            !newPassword
            || !confirmPassword
            || !passwordMeterBar
            || !passwordStatus
            || !passwordStatusText
        ) {
            return;
        }

        const password = newPassword.value;
        const confirm = confirmPassword.value;
        let score = 0;

        if (password.length >= 6) score++;
        if (password.length >= 10) score++;
        if (/[A-Z]/.test(password) && /[a-z]/.test(password)) score++;
        if (/\d/.test(password)) score++;
        if (/[^A-Za-z0-9]/.test(password)) score++;

        const percentage = password.length === 0 ? 0 : Math.max(20, Math.min(100, score * 20));
        passwordMeterBar.style.width = `${percentage}%`;

        passwordStatus.classList.remove('weak', 'medium', 'strong', 'match-error', 'match-success');

        if (password.length === 0) {
            passwordStatusText.textContent = 'Mínimo 6 caracteres';
            return;
        }

        if (confirm.length > 0 && password !== confirm) {
            passwordStatus.classList.add('match-error');
            passwordStatusText.textContent = 'Las contraseñas no coinciden';
            return;
        }

        if (confirm.length > 0 && password === confirm) {
            passwordStatus.classList.add('match-success');
            passwordStatusText.textContent = 'Las contraseñas coinciden';
            return;
        }

        if (score <= 1) {
            passwordStatus.classList.add('weak');
            passwordStatusText.textContent = 'Contraseña débil';
        } else if (score <= 3) {
            passwordStatus.classList.add('medium');
            passwordStatusText.textContent = 'Contraseña aceptable';
        } else {
            passwordStatus.classList.add('strong');
            passwordStatusText.textContent = 'Contraseña segura';
        }
    }

    if (newPassword && confirmPassword) {
        newPassword.addEventListener('input', actualizarEstadoPassword);
        confirmPassword.addEventListener('input', actualizarEstadoPassword);
    }

    const profileForm = document.getElementById('profileForm');

    if (profileForm) {
        profileForm.addEventListener('submit', async event => {
        event.preventDefault();

        const nombre = document.getElementById('nombre').value.trim();
        const email = document.getElementById('email').value.trim();
        const button = document.getElementById('btnGuardarPerfil');

        if (!nombre || !email) {
            await mostrarError('Completa el nombre y el correo electrónico.');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'update_profile');
        formData.append('nombre', nombre);
        formData.append('email', email);

        try {
            setButtonLoading(button, true);
            const data = await enviarFormulario(formData);
            document.getElementById('profile-name-display').textContent = data.nombre;
            document.getElementById('profile-email-display').textContent = data.email;
            await mostrarExito(data.message);
        } catch (error) {
            await mostrarError(error.message);
        } finally {
            setButtonLoading(button, false);
        }
        });
    }

    const passwordForm = document.getElementById('passwordForm');

    if (passwordForm) {
        passwordForm.addEventListener('submit', async event => {
            event.preventDefault();

            const form = event.currentTarget;
            const currentPassword = document.getElementById('current_password')?.value || '';
            const password = newPassword ? newPassword.value : '';
            const confirm = confirmPassword ? confirmPassword.value : '';
            const button = document.getElementById('btnGuardarPassword');

            if (!currentPassword) {
                await mostrarError('Escribe tu contraseña actual.');
                return;
            }

            if (password.length < 6) {
                await mostrarError('La contraseña debe tener al menos 6 caracteres.');
                return;
            }

            if (password !== confirm) {
                await mostrarError('Las contraseñas no coinciden.');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'update_password');
            formData.append('current_password', currentPassword);
            formData.append('new_password', password);
            formData.append('confirm_password', confirm);

            try {
                setButtonLoading(button, true, 'Actualizando...');

                const data = await enviarFormulario(formData);

                form.reset();
                actualizarEstadoPassword();
                await mostrarExito(data.message);
            } catch (error) {
                await mostrarError(
                    error instanceof Error
                        ? error.message
                        : 'No fue posible actualizar la contraseña.'
                );
            } finally {
                setButtonLoading(button, false);
            }
        });
    }

    async function solicitarCredenciales2fa(action) {
        const labels = {
            regenerate_recovery_codes: {
                title: 'Generar nuevos códigos',
                text: 'Los códigos anteriores dejarán de funcionar.'
            },
            revoke_2fa_devices: {
                title: 'Revocar dispositivos confiables',
                text: 'Los demás equipos deberán validar nuevamente el segundo factor.'
            },
            reconfigure_2fa: {
                title: 'Cambiar aplicación autenticadora',
                text: 'La configuración actual se eliminará y tendrás que vincular una nueva.'
            }
        };

        const copy = labels[action] || labels.revoke_2fa_devices;
        const result = await Swal.fire({
            icon: action === 'reconfigure_2fa' ? 'warning' : 'question',
            title: copy.title,
            html:
                '<p style="margin:0 0 16px;color:#667085;font-size:14px;">' +
                escapeHtml(copy.text) +
                '</p>' +
                '<input id="swalCurrentPassword" type="password" class="swal2-input" placeholder="Contraseña actual" autocomplete="current-password">' +
                '<input id="swalTwoFactorCode" type="text" class="swal2-input" placeholder="Código de 6 dígitos" inputmode="numeric" autocomplete="one-time-code" maxlength="6">',
            showCancelButton: true,
            confirmButtonText: action === 'reconfigure_2fa' ? 'Sí, reconfigurar' : 'Confirmar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: action === 'reconfigure_2fa' ? '#dc2626' : '#1e3a8a',
            focusConfirm: false,
            preConfirm: () => {
                const password = document.getElementById('swalCurrentPassword').value;
                const code = document.getElementById('swalTwoFactorCode').value.replace(/\D/g, '');

                if (!password || code.length !== 6) {
                    Swal.showValidationMessage('Escribe tu contraseña actual y un código válido de seis dígitos.');
                    return false;
                }

                return { password, code };
            }
        });

        if (!result.isConfirmed || !result.value) {
            return;
        }

        const formData = new FormData();
        formData.append('action', action);
        formData.append('current_password', result.value.password);
        formData.append('two_factor_code', result.value.code);

        Swal.fire({
            title: 'Validando seguridad...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        try {
            const data = await enviarFormulario(formData);

            if (Array.isArray(data.codes)) {
                const codesHtml = data.codes
                    .map(code => '<code class="swal-recovery-code">' + escapeHtml(code) + '</code>')
                    .join('');

                await Swal.fire({
                    icon: 'success',
                    title: 'Guarda los nuevos códigos',
                    html:
                        '<p style="color:#667085;font-size:14px;">Cada código funciona una sola vez. Los anteriores quedaron invalidados.</p>' +
                        '<div class="swal-recovery-grid">' + codesHtml + '</div>',
                    confirmButtonText: 'Ya los guardé',
                    confirmButtonColor: '#1e3a8a',
                    width: 600
                });
                return;
            }

            if (data.redirect) {
                window.location.href = data.redirect;
                return;
            }

            await mostrarExito(data.message);
            window.location.reload();
        } catch (error) {
            await mostrarError(error.message);
        }
    }

    document.querySelectorAll('[data-2fa-action]').forEach(button => {
        button.addEventListener('click', () => {
            solicitarCredenciales2fa(button.dataset.twoFactorAction);
        });
    });

    const fotoInput = document.getElementById('foto_input');
    const avatarImage = document.getElementById('avatar-img');

    function abrirSelectorFoto() {
        fotoInput.value = '';
        fotoInput.click();
    }

    const btnCambiarFoto = document.getElementById('btnCambiarFoto');
    const btnCambiarFotoTexto = document.getElementById('btnCambiarFotoTexto');

    if (btnCambiarFoto) {
        btnCambiarFoto.addEventListener('click', abrirSelectorFoto);
    }

    if (btnCambiarFotoTexto) {
        btnCambiarFotoTexto.addEventListener('click', abrirSelectorFoto);
    }

    if (avatarImage) {
        avatarImage.addEventListener('error', () => {
            avatarImage.src = avatarImage.dataset.fallback;
        });
    }

    if (fotoInput) {
        fotoInput.addEventListener('change', async () => {
        const file = fotoInput.files?.[0];
        if (!file) return;

        const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            await mostrarError('Solo se permiten imágenes JPG, PNG o WEBP.');
            return;
        }

        if (file.size > 10 * 1024 * 1024) {
            await mostrarError('La imagen no puede superar los 10 MB.');
            return;
        }

        const photoButtons = [
            document.getElementById('btnCambiarFoto'),
            document.getElementById('btnCambiarFotoTexto')
        ];
        photoButtons.forEach(button => button.disabled = true);
        document.querySelector('.profile-avatar-wrap').classList.add('uploading');

        const formData = new FormData();
        formData.append('action', 'update_photo');
        formData.append('foto_perfil', file);

        try {
            const data = await enviarFormulario(formData);
            avatarImage.src = `${data.avatar_url}?v=${Date.now()}`;
            await mostrarExito(data.message);
        } catch (error) {
            await mostrarError(error.message);
        } finally {
            photoButtons.forEach(button => button.disabled = false);
            document.querySelector('.profile-avatar-wrap').classList.remove('uploading');
        }
        });
    }
    </script>
</body>
</html>