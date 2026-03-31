<?php
// cambiar_password.php
session_start();
require_once 'config/database.php';

// Verificar que el usuario esté logueado
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validaciones
    if (empty($new_password) || empty($confirm_password)) {
        $error = "Por favor complete todos los campos";
    } elseif (strlen($new_password) < 6) {
        $error = "La contraseña debe tener al menos 6 caracteres";
    } elseif ($new_password !== $confirm_password) {
        $error = "Las contraseñas no coinciden";
    } else {
        // Conectar a la base de datos
        $database = new Database();
        $db = $database->getConnection();
        
        // Hashear la nueva contraseña
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Actualizar la contraseña y marcar que ya no requiere cambio
        $query = "UPDATE usuarios SET password = ?, password_change_required = 0, ultimo_cambio_password = NOW() WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("si", $hashed_password, $user_id);
        
        if ($stmt->execute()) {
            $success = true;
            // Actualizar la sesión para que no muestre el modal nuevamente
            $_SESSION['password_changed'] = true;
        } else {
            $error = "Error al actualizar la contraseña. Por favor intente nuevamente.";
        }
        
        $stmt->close();
    }
}

// Si hay error, redirigir con mensaje
if ($error) {
    $_SESSION['password_change_error'] = $error;
    header("Location: dashboard.php");
    exit();
}

// Si fue exitoso, redirigir con mensaje de éxito
if ($success) {
    $_SESSION['password_change_success'] = true;
    header("Location: dashboard.php");
    exit();
}
?>