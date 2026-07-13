<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

$user_id = $_SESSION['user_id'];
$user_nombre = $_SESSION['user_name'];

$query = "SELECT * FROM usuarios WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Obtener estadísticas del usuario
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM ventas WHERE usuario_id = ?) as total_ventas,
    (SELECT COUNT(*) FROM asistencias WHERE verificado_por = ?) as total_asistencias,
    (SELECT COALESCE(SUM(total), 0) FROM ventas WHERE usuario_id = ?) as total_ingresos";
$stmt_stats = $conn->prepare($stats_query);
$stmt_stats->bind_param("iii", $user_id, $user_id, $user_id);
$stmt_stats->execute();
$stats = $stmt_stats->get_result()->fetch_assoc();

// Orden de actividad (asc/desc)
$orden = isset($_GET['orden']) && $_GET['orden'] === 'asc' ? 'asc' : 'desc';
$orden_texto = $orden === 'asc' ? 'ASC' : 'DESC';
$icono_orden = $orden === 'asc' ? 'fa-sort-amount-up' : 'fa-sort-amount-down';
$texto_orden = $orden === 'asc' ? 'Más antiguos primero' : 'Más recientes primero';

// Paginación para actividad reciente
$pagina = max(1, isset($_GET['page']) ? (int) $_GET['page'] : 1);
$por_pagina = 5;

// Obtener total de actividades
$total_query = "SELECT COUNT(*) as total FROM (
    SELECT id FROM ventas WHERE usuario_id = ?
    UNION ALL
    SELECT id FROM asistencias WHERE verificado_por = ?
) as actividades";
$stmt_total = $conn->prepare($total_query);
$stmt_total->bind_param("ii", $user_id, $user_id);
$stmt_total->execute();
$total_actividades = $stmt_total->get_result()->fetch_assoc()['total'];
$total_paginas = max(1, (int) ceil($total_actividades / $por_pagina));
$pagina = min($pagina, $total_paginas);
$offset = ($pagina - 1) * $por_pagina;

// Obtener actividades con paginación y orden
$actividades_query = "SELECT * FROM (
    SELECT 'venta' as tipo, id, fecha_venta as fecha, total as monto FROM ventas WHERE usuario_id = ?
    UNION ALL
    SELECT 'asistencia' as tipo, id, CONCAT(fecha, ' ', hora_entrada) as fecha, NULL as monto FROM asistencias WHERE verificado_por = ?
) as actividades ORDER BY fecha $orden_texto LIMIT ? OFFSET ?";
$stmt_act = $conn->prepare($actividades_query);
$stmt_act->bind_param("iiii", $user_id, $user_id, $por_pagina, $offset);
$stmt_act->execute();
$actividades = $stmt_act->get_result();

// Procesar actualización de perfil
$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        $nombre = trim($_POST['nombre']);
        $email = trim($_POST['email']);
        
        if (empty($nombre) || empty($email)) {
            $error = "Nombre y email son obligatorios";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Email inválido";
        } else {
            $check_query = "SELECT id FROM usuarios WHERE email = ? AND id != ?";
            $stmt_check = $conn->prepare($check_query);
            $stmt_check->bind_param("si", $email, $user_id);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows > 0) {
                $error = "El email ya está registrado por otro usuario";
            } else {
                $update_query = "UPDATE usuarios SET nombre = ?, email = ? WHERE id = ?";
                $stmt_update = $conn->prepare($update_query);
                $stmt_update->bind_param("ssi", $nombre, $email, $user_id);
                if ($stmt_update->execute()) {
                    $_SESSION['user_name'] = $nombre;
                    $_SESSION['user_email'] = $email;
                    $user_nombre = $nombre;
                    $mensaje = "Perfil actualizado correctamente";
                    $user['nombre'] = $nombre;
                    $user['email'] = $email;
                } else {
                    $error = "Error al actualizar el perfil";
                }
            }
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'update_password') {
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if (strlen($new_password) < 6) {
            $error = "La nueva contraseña debe tener al menos 6 caracteres";
        } elseif ($new_password !== $confirm_password) {
            $error = "Las contraseñas no coinciden";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_pass = "UPDATE usuarios SET password = ?, password_change_required = 0, ultimo_cambio_password = NOW() WHERE id = ?";
            $stmt_update = $conn->prepare($update_pass);
            $stmt_update->bind_param("si", $hashed_password, $user_id);
            if ($stmt_update->execute()) {
                $mensaje = "Contraseña actualizada correctamente";
            } else {
                $error = "Error al actualizar la contraseña";
            }
        }
    }
    
    if (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] === UPLOAD_ERR_OK) {
        $archivo = $_FILES['foto_perfil'];
        $tipo = $archivo['type'];
        $tamano = $archivo['size'];
        $temp = $archivo['tmp_name'];
        
        $tipos_permitidos = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        if (!in_array($tipo, $tipos_permitidos)) {
            $error = "Solo se permiten imágenes JPG, JPEG, PNG o WEBP";
        } elseif ($tamano > 10 * 1024 * 1024) {
            $error = "La imagen no puede superar los 10MB";
        } else {
            $directorio = 'uploads/perfiles/';
            if (!file_exists($directorio)) {
                mkdir($directorio, 0777, true);
            }
            
            $nombre_limpio = preg_replace('/[^a-zA-Z0-9]/', '_', $user_nombre);
            $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
            $nombre_archivo = 'perfil_' . $user_id . '_' . $nombre_limpio . '.' . $extension;
            $ruta_completa = $directorio . $nombre_archivo;
            
            if (!empty($user['foto_perfil']) && file_exists($user['foto_perfil'])) {
                unlink($user['foto_perfil']);
            }
            
            if (move_uploaded_file($temp, $ruta_completa)) {
                $update_foto = "UPDATE usuarios SET foto_perfil = ? WHERE id = ?";
                $stmt_foto = $conn->prepare($update_foto);
                $stmt_foto->bind_param("si", $ruta_completa, $user_id);
                if ($stmt_foto->execute()) {
                    $user['foto_perfil'] = $ruta_completa;
                    $mensaje = "Foto de perfil actualizada correctamente";
                } else {
                    $error = "Error al guardar la foto de perfil";
                }
            } else {
                $error = "Error al subir la imagen";
            }
        }
    }
}

// Obtener URL del avatar
$avatar_url = !empty($user['foto_perfil']) && file_exists($user['foto_perfil']) 
    ? $user['foto_perfil'] 
    : 'https://ui-avatars.com/api/?background=3b82f6&color=fff&bold=true&size=120&name=' . urlencode($user['nombre']);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Perfil - Ego Gym</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/mi_perfil.css?v=1.0.0">
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <main class="main-content" id="contenido-principal">
        <!-- Cover y avatar -->
        <section class="profile-cover" aria-label="Resumen del perfil">
            <div class="profile-cover-img"></div>
            <button type="button" class="profile-avatar" onclick="cambiarFotoPerfil()" aria-label="Cambiar foto de perfil">
                <img id="avatar-img" src="<?php echo $avatar_url; ?>" alt="Avatar" onerror="this.src='https://ui-avatars.com/api/?background=3b82f6&color=fff&bold=true&size=120&name=<?php echo urlencode($user['nombre']); ?>'">
            </button>
            <div class="edit-avatar-hint">
                <i class="fas fa-camera"></i> Click para cambiar foto
            </div>
            <div class="profile-name">
                <h2><?php echo htmlspecialchars($user['nombre']); ?></h2>
                <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></p>
            </div>
        </section>

        <!-- Estadísticas -->
        <section class="stats-grid" aria-label="Estadísticas del usuario">
            <div class="stat-card" onclick="cambiarPagina(1)">
                <div class="stat-icon"><i class="fas fa-shopping-cart"></i></div>
                <div class="stat-info">
                    <h3><?php echo $stats['total_ventas'] ?? 0; ?></h3>
                    <p>Ventas Realizadas</p>
                </div>
            </div>
            <div class="stat-card" onclick="cambiarPagina(1)">
                <div class="stat-icon"><i class="fas fa-fingerprint"></i></div>
                <div class="stat-info">
                    <h3><?php echo $stats['total_asistencias'] ?? 0; ?></h3>
                    <p>Asistencias Registradas</p>
                </div>
            </div>
            <div class="stat-card" onclick="cambiarPagina(1)">
                <div class="stat-icon"><i class="fas fa-dollar-sign"></i></div>
                <div class="stat-info">
                    <h3>$<?php echo number_format($stats['total_ingresos'] ?? 0, 2); ?></h3>
                    <p>Total en Ventas</p>
                </div>
            </div>
            <div class="stat-card" onclick="cambiarPagina(1)">
                <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
                <div class="stat-info">
                    <h3><?php echo date('d/m/Y', strtotime($user['fecha_registro'])); ?></h3>
                    <p>Miembro desde</p>
                </div>
            </div>
        </section>

        <!-- Grid de información -->
        <div class="profile-grid">
            <!-- Información personal -->
            <div class="info-card">
                <div class="card-header">
                    <i class="fas fa-id-card"></i>
                    <h3>Editar Información Personal</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Nombre completo</label>
                        <input type="text" id="nombre" value="<?php echo htmlspecialchars($user['nombre']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Correo electrónico</label>
                        <input type="email" id="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-user-tag"></i> Rol</label>
                        <input type="text" value="<?php 
                            $roles = ['admin' => 'Administrador', 'recepcionista' => 'Recepcionista', 'entrenador' => 'Entrenador'];
                            echo $roles[$user['rol']] ?? $user['rol'];
                        ?>" disabled class="readonly-input">
                    </div>
                    <button type="button" class="btn-action" onclick="actualizarPerfil()">
                        <i class="fas fa-save"></i> Actualizar Datos
                    </button>
                </div>
            </div>

            <!-- Seguridad -->
            <div class="info-card">
                <div class="card-header">
                    <i class="fas fa-shield-alt"></i>
                    <h3>Seguridad</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Nueva contraseña</label>
                        <div class="password-input-wrapper">
                            <input type="password" id="new_password" autocomplete="off">
                            <button type="button" class="toggle-password" onclick="togglePasswordVisibility('new_password')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength">
                            <div class="password-strength-bar" id="strength-bar"></div>
                        </div>
                        <div class="password-hint" id="password-hint">Mínimo 6 caracteres</div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-check-circle"></i> Confirmar contraseña</label>
                        <div class="password-input-wrapper">
                            <input type="password" id="confirm_password" autocomplete="off">
                            <button type="button" class="toggle-password" onclick="togglePasswordVisibility('confirm_password')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div id="password-match-indicator" class="password-match"></div>
                    </div>
                    <button type="button" class="btn-action" onclick="cambiarPassword()">
                        <i class="fas fa-sync-alt"></i> Cambiar Contraseña
                    </button>
                    
                    <div class="acciones-buttons">
                        <button type="button" class="btn-action btn-export" onclick="exportarActividad()">
                            <i class="fas fa-download"></i> Exportar
                        </button>
                        <button type="button" class="btn-action btn-session" onclick="verSesiones()">
                            <i class="fas fa-history"></i> Sesiones
                        </button>
                    </div>
                </div>
            </div>

            <!-- Actividad reciente con paginación y orden -->
            <div class="info-card activity-card">
                <div class="card-header">
                    <i class="fas fa-clock"></i>
                    <h3>Actividad Reciente</h3>
                </div>
                <div class="card-body">
                    <div class="activity-toolbar">
                        <button type="button" class="orden-btn" onclick="cambiarOrden()">
                            <i class="fas <?php echo $icono_orden; ?>"></i>
                            <?php echo $texto_orden; ?>
                        </button>
                    </div>
                    
                    <?php if ($actividades->num_rows > 0): ?>
                        <?php while ($act = $actividades->fetch_assoc()): ?>
                            <div class="actividad-item">
                                <div class="actividad-icon">
                                    <i class="fas <?php echo $act['tipo'] == 'venta' ? 'fa-shopping-cart' : 'fa-fingerprint'; ?>" ></i>
                                </div>
                                <div class="actividad-info">
                                    <div class="actividad-titulo">
                                        <?php echo $act['tipo'] == 'venta' ? 'Venta realizada' : 'Asistencia registrada'; ?>
                                        <?php if ($act['tipo'] == 'venta'): ?>
                                            <span class="actividad-folio">#<?php echo str_pad($act['id'], 8, '0', STR_PAD_LEFT); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="actividad-fecha">
                                        <i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y H:i', strtotime($act['fecha'])); ?>
                                    </div>
                                </div>
                                <?php if ($act['tipo'] == 'venta' && $act['monto']): ?>
                                    <div class="actividad-monto">$<?php echo number_format($act['monto'], 2); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                        
                        <?php if ($total_paginas > 1): ?>
                            <div class="pagination">
                                <button onclick="cambiarPagina(1)" <?php echo $pagina <= 1 ? 'disabled' : ''; ?>>
                                    <i class="fas fa-angle-double-left"></i>
                                </button>
                                <button onclick="cambiarPagina(<?php echo max(1, $pagina - 1); ?>)" <?php echo $pagina <= 1 ? 'disabled' : ''; ?>>
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                
                                <?php
                                $start = max(1, $pagina - 2);
                                $end = min($total_paginas, $pagina + 2);
                                for ($i = $start; $i <= $end; $i++):
                                ?>
                                    <button onclick="cambiarPagina(<?php echo $i; ?>)" class="<?php echo $i == $pagina ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </button>
                                <?php endfor; ?>
                                
                                <button onclick="cambiarPagina(<?php echo min($total_paginas, $pagina + 1); ?>)" <?php echo $pagina >= $total_paginas ? 'disabled' : ''; ?>>
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                                <button onclick="cambiarPagina(<?php echo $total_paginas; ?>)" <?php echo $pagina >= $total_paginas ? 'disabled' : ''; ?>>
                                    <i class="fas fa-angle-double-right"></i>
                                </button>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-actividades">
                            <i class="fas fa-inbox" class="empty-icon"></i>
                            <p>No hay actividad reciente</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <form id="uploadFotoForm" method="POST" enctype="multipart/form-data" style="display: none;">
        <input type="file" id="foto_input" name="foto_perfil" accept="image/*">
    </form>

    <script>
    // Función para mostrar/ocultar contraseña
    function togglePasswordVisibility(inputId) {
        const input = document.getElementById(inputId);
        const button = input.nextElementSibling;
        const icon = button.querySelector('i');
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    // Función para detectar correctamente el navegador
    function detectarNavegador() {
        const ua = navigator.userAgent;
        if (ua.indexOf('Chrome') > -1 && ua.indexOf('Edg') === -1) return 'Chrome';
        if (ua.indexOf('Firefox') > -1) return 'Firefox';
        if (ua.indexOf('Safari') > -1 && ua.indexOf('Chrome') === -1) return 'Safari';
        if (ua.indexOf('Edg') > -1) return 'Edge';
        if (ua.indexOf('Opera') > -1 || ua.indexOf('OPR') > -1) return 'Opera';
        return 'Desconocido';
    }

    // Variables para el medidor de fortaleza
    let newPass = document.getElementById('new_password');
    let confirmPass = document.getElementById('confirm_password');
    let matchIndicator = document.getElementById('password-match-indicator');

    function actualizarFortaleza() {
        if (!newPass) return;
        
        const val = newPass.value;
        const strengthBar = document.getElementById('strength-bar');
        const hint = document.getElementById('password-hint');
        let strength = 0;
        let hintText = '';
        
        if (val.length >= 6) strength++;
        if (val.length >= 10) strength++;
        if (/[A-Z]/.test(val)) strength++;
        if (/[0-9]/.test(val)) strength++;
        if (/[^A-Za-z0-9]/.test(val)) strength++;
        
        if (val.length === 0) {
            strengthBar.className = 'password-strength-bar';
            strengthBar.style.width = '0%';
            hintText = 'Mínimo 6 caracteres';
        } else if (strength <= 1) {
            strengthBar.className = 'password-strength-bar strength-weak';
            hintText = 'Contraseña débil';
        } else if (strength <= 3) {
            strengthBar.className = 'password-strength-bar strength-medium';
            hintText = 'Contraseña media';
        } else {
            strengthBar.className = 'password-strength-bar strength-strong';
            hintText = 'Contraseña fuerte';
        }
        hint.textContent = hintText;
        
        // Verificar coincidencia
        verificarCoincidencia();
    }

    function verificarCoincidencia() {
        if (confirmPass && newPass && matchIndicator) {
            const newVal = newPass.value;
            const confirmVal = confirmPass.value;
            
            if (confirmVal.length > 0) {
                if (newVal === confirmVal) {
                    matchIndicator.innerHTML = '<i class="fas fa-check-circle"></i> Las contraseñas coinciden';
                    matchIndicator.className = 'password-match valid';
                } else {
                    matchIndicator.innerHTML = '<i class="fas fa-times-circle"></i> Las contraseñas no coinciden';
                    matchIndicator.className = 'password-match invalid';
                }
            } else {
                matchIndicator.innerHTML = '';
            }
        }
    }

    // Agregar event listeners
    if (newPass) {
        newPass.removeEventListener('input', actualizarFortaleza);
        newPass.addEventListener('input', actualizarFortaleza);
    }
    
    if (confirmPass) {
        confirmPass.removeEventListener('input', verificarCoincidencia);
        confirmPass.addEventListener('input', verificarCoincidencia);
    }

    function cambiarPagina(pagina) {
        const urlParams = new URLSearchParams(window.location.search);
        const orden = urlParams.get('orden') || 'desc';
        window.location.href = 'mi_perfil.php?page=' + pagina + '&orden=' + orden;
    }

    function cambiarOrden() {
        const urlParams = new URLSearchParams(window.location.search);
        const pagina = urlParams.get('page') || 1;
        const ordenActual = urlParams.get('orden') || 'desc';
        const nuevoOrden = ordenActual === 'desc' ? 'asc' : 'desc';
        window.location.href = 'mi_perfil.php?page=' + pagina + '&orden=' + nuevoOrden;
    }

    function cambiarFotoPerfil() {
        Swal.fire({
            title: 'Cambiar foto de perfil',
            text: 'Selecciona una imagen para tu perfil (JPG, PNG, WEBP - máximo 10MB)',
            input: 'file',
            inputAttributes: {
                'accept': 'image/jpeg,image/jpg,image/png,image/webp',
                'aria-label': 'Selecciona tu foto'
            },
            showCancelButton: true,
            confirmButtonText: 'Subir foto',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#3b82f6',
            cancelButtonColor: '#ef4444',
            preConfirm: (file) => {
                if (!file) {
                    Swal.showValidationMessage('Selecciona una imagen');
                    return false;
                }
                if (file.size > 10 * 1024 * 1024) {
                    Swal.showValidationMessage('La imagen no puede superar los 10MB');
                    return false;
                }
                const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
                if (!validTypes.includes(file.type)) {
                    Swal.showValidationMessage('Solo se permiten JPG, PNG o WEBP');
                    return false;
                }
                return file;
            }
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                const formData = new FormData();
                formData.append('foto_perfil', result.value);
                
                Swal.fire({
                    title: 'Subiendo foto...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });
                
                fetch('mi_perfil.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(() => {
                    Swal.fire({
                        icon: 'success',
                        title: '¡Foto actualizada!',
                        text: 'Tu foto de perfil ha sido actualizada',
                        confirmButtonColor: '#3b82f6'
                    }).then(() => {
                        location.reload();
                    });
                })
                .catch(() => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'No se pudo subir la imagen',
                        confirmButtonColor: '#ef4444'
                    });
                });
            }
        });
    }

    function actualizarPerfil() {
        const nombre = document.getElementById('nombre').value;
        const email = document.getElementById('email').value;
        
        if (!nombre || !email) {
            Swal.fire({
                icon: 'error',
                title: 'Campos incompletos',
                text: 'Nombre y email son obligatorios',
                confirmButtonColor: '#ef4444'
            });
            return;
        }
        
        const emailRegex = /^[^\s@]+@([^\s@.,]+\.)+[^\s@.,]{2,}$/;
        if (!emailRegex.test(email)) {
            Swal.fire({
                icon: 'error',
                title: 'Email inválido',
                text: 'Ingresa un correo electrónico válido',
                confirmButtonColor: '#ef4444'
            });
            return;
        }
        
        Swal.fire({
            title: 'Actualizar perfil',
            text: '¿Deseas guardar los cambios?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, guardar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#3b82f6',
            cancelButtonColor: '#ef4444'
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('action', 'update_profile');
                formData.append('nombre', nombre);
                formData.append('email', email);
                
                Swal.fire({
                    title: 'Guardando...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });
                
                fetch('mi_perfil.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(() => {
                    Swal.fire({
                        icon: 'success',
                        title: '¡Perfil actualizado!',
                        text: 'Tus datos han sido guardados',
                        confirmButtonColor: '#3b82f6'
                    }).then(() => {
                        location.reload();
                    });
                })
                .catch(() => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'No se pudieron guardar los cambios',
                        confirmButtonColor: '#ef4444'
                    });
                });
            }
        });
    }

    function cambiarPassword() {
        const newPassValue = document.getElementById('new_password').value;
        const confirmValue = document.getElementById('confirm_password').value;
        
        if (!newPassValue || !confirmValue) {
            Swal.fire({
                icon: 'error',
                title: 'Campos incompletos',
                text: 'Todos los campos son obligatorios',
                confirmButtonColor: '#ef4444'
            });
            return;
        }
        
        if (newPassValue !== confirmValue) {
            Swal.fire({
                icon: 'error',
                title: 'Contraseñas no coinciden',
                text: 'La nueva contraseña y su confirmación deben ser iguales',
                confirmButtonColor: '#ef4444'
            });
            return;
        }
        
        if (newPassValue.length < 6) {
            Swal.fire({
                icon: 'error',
                title: 'Contraseña débil',
                text: 'La contraseña debe tener al menos 6 caracteres',
                confirmButtonColor: '#ef4444'
            });
            return;
        }
        
        Swal.fire({
            title: 'Cambiar contraseña',
            text: '¿Estás seguro de que deseas cambiar tu contraseña?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, cambiar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#3b82f6',
            cancelButtonColor: '#ef4444'
        }).then((result) => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('action', 'update_password');
                formData.append('new_password', newPassValue);
                formData.append('confirm_password', confirmValue);
                
                Swal.fire({
                    title: 'Actualizando...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });
                
                fetch('mi_perfil.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(() => {
                    Swal.fire({
                        icon: 'success',
                        title: '¡Contraseña actualizada!',
                        text: 'Tu contraseña ha sido cambiada exitosamente',
                        confirmButtonColor: '#3b82f6'
                    }).then(() => {
                        document.getElementById('new_password').value = '';
                        document.getElementById('confirm_password').value = '';
                        if (matchIndicator) matchIndicator.innerHTML = '';
                        // Resetear barra de fortaleza
                        const strengthBar = document.getElementById('strength-bar');
                        if (strengthBar) {
                            strengthBar.className = 'password-strength-bar';
                            strengthBar.style.width = '0%';
                        }
                        const hint = document.getElementById('password-hint');
                        if (hint) hint.textContent = 'Mínimo 6 caracteres';
                    });
                })
                .catch(() => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'No se pudo cambiar la contraseña',
                        confirmButtonColor: '#ef4444'
                    });
                });
            }
        });
    }

    function exportarActividad() {
        Swal.fire({
            title: 'Exportar Actividad',
            text: 'Selecciona el formato para exportar tu actividad',
            icon: 'question',
            showCancelButton: true,
            showDenyButton: true,
            confirmButtonText: 'PDF',
            denyButtonText: 'CSV',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#3b82f6',
            denyButtonColor: '#10b981',
            cancelButtonColor: '#ef4444'
        }).then((result) => {
            if (result.isConfirmed) {
                window.open('exportar_actividad.php?format=pdf', '_blank');
            } else if (result.isDenied) {
                window.open('exportar_actividad.php?format=csv', '_blank');
            }
        });
    }

    function verSesiones() {
        const ahora = new Date();
        const fechaSesion = ahora.toLocaleString('es-MX');
        const navegador = detectarNavegador();
        const sistemaOperativo = navigator.platform;
        
        Swal.fire({
            title: 'Información de Sesión',
            html: `
                <div style="text-align: left;">
                    <div style="background: #f8fafc; padding: 12px; border-radius: 10px; margin-bottom: 15px;">
                        <p><strong><i class="fas fa-laptop"></i> Sesión actual</strong></p>
                        <p style="font-size: 0.85rem; color: #64748b;">Navegador: ${navegador}</p>
                        <p style="font-size: 0.85rem; color: #64748b;">Sistema operativo: ${sistemaOperativo}</p>
                        <p style="font-size: 0.85rem; color: #64748b;">Inicio de sesión: ${fechaSesion}</p>
                    </div>
                    <div style="background: #fef3c7; padding: 12px; border-radius: 10px;">
                        <p><i class="fas fa-info-circle"></i> <strong>Consejo de seguridad</strong></p>
                        <p style="font-size: 0.8rem; color: #92400e;">Para mayor seguridad, cierra sesión cuando termines de usar el sistema y no compartas tus credenciales.</p>
                    </div>
                </div>
            `,
            icon: 'info',
            confirmButtonText: 'Entendido',
            confirmButtonColor: '#3b82f6'
        });
    }
    </script>
</body>
</html>