<?php
session_start();
require_once './../config.php';

// Verificar si el usuario ha iniciado sesión y tiene rol de administrador
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'administrador') {
    header("Location: index.php"); // Redirigir al login si no es administrador o no está logueado
    exit();
}

$message = ""; // Para mensajes de éxito o error

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email_usuario_a_cambiar = $_POST['email_usuario_a_cambiar'];
    $nueva_password = $_POST['nueva_password'];

    // Validaciones básicas
    if (empty($email_usuario_a_cambiar) || empty($nueva_password)) {
        $message = "Ambos campos son obligatorios.";
    } elseif (!filter_var($email_usuario_a_cambiar, FILTER_VALIDATE_EMAIL)) {
        $message = "El formato del correo electrónico a cambiar no es válido.";
    } else {
        // Verificar si el email del usuario a cambiar existe
        $stmt_check = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt_check->bind_param("s", $email_usuario_a_cambiar);
        $stmt_check->execute();
        $stmt_check->store_result();

        if ($stmt_check->num_rows === 0) {
            $message = "El correo electrónico a cambiar no existe en la base de datos.";
        } else {
            // Actualizar la contraseña en texto plano
            // ADVERTENCIA DE SEGURIDAD: Contraseña almacenada en texto plano.
            // Para producción, DEBERÍAS usar: $hashed_password = password_hash($nueva_password, PASSWORD_DEFAULT);
            // y luego actualizar con $hashed_password
            $stmt_update = $conn->prepare("UPDATE usuarios SET password = ? WHERE email = ?");
            $stmt_update->bind_param("ss", $nueva_password, $email_usuario_a_cambiar);

            if ($stmt_update->execute()) {
                $message = "¡Contraseña actualizada exitosamente para " . htmlspecialchars($email_usuario_a_cambiar) . "!";
            } else {
                $message = "Error al actualizar la contraseña: " . $stmt_update->error;
            }
            $stmt_update->close();
        }
        $stmt_check->close();
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambiar Contraseña (Admin)</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f4; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .container { background-color: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 0 10px rgba(0, 0, 0, 0.1); width: 450px; text-align: center; }
        h2 { color: #333; margin-bottom: 20px; }
        .message { margin-bottom: 15px; font-weight: bold; }
        .success-message { color: #28a745; }
        .error-message { color: #dc3545; }
        .form-group { margin-bottom: 15px; text-align: left; }
        .form-group label { display: block; margin-bottom: 5px; color: #555; }
        .form-group input { width: calc(100% - 20px); padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 1em; }
        button { width: 100%; padding: 10px; background-color: #ff3366; color: white; border: none; border-radius: 4px; font-size: 1.1em; cursor: pointer; transition: background-color 0.3s ease; margin-top: 10px; }
        button:hover { background-color: #e6004c; }
        .back-link { margin-top: 20px; display: block; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Cambiar Contraseña de Usuario</h2>
        <?php if (!empty($message)): ?>
            <p class="message <?php echo (strpos($message, 'exitosamente') !== false) ? 'success-message' : 'error-message'; ?>"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>
        <form action="cambiar_password.php" method="POST">
            <div class="form-group">
                <label for="email_usuario_a_cambiar">Correo del usuario a cambiar contraseña:</label>
                <input type="email" id="email_usuario_a_cambiar" name="email_usuario_a_cambiar" required>
            </div>
            <div class="form-group">
                <label for="nueva_password">Nueva Contraseña:</label>
                <input type="password" id="nueva_password" name="nueva_password" required>
            </div>
            <button type="submit">Actualizar Contraseña</button>
        </form>
        <a href="admin_dashboard.php" class="back-link">Volver al Panel de Administrador</a>
    </div>
</body>
</html>