<?php
session_start();
require_once 'config.php'; // Asegúrate de que tu archivo config.php esté en la misma carpeta

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $rol = $_POST['rol'];

    $roles_validos = ['cliente', 'empleado', 'cocinero', 'administrador'];

    if (empty($email) || empty($password) || empty($rol)) {
        $message = "Todos los campos son obligatorios.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "El formato del correo electrónico no es válido.";
    } elseif (!in_array($rol, $roles_validos)) {
        $message = "El rol seleccionado no es válido.";
    } else {
        $stmt_check = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt_check->bind_param("s", $email);
        $stmt_check->execute();
        $stmt_check->store_result();

        if ($stmt_check->num_rows > 0) {
            $message = "El correo electrónico ya está registrado.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT); // ✅ Contraseña segura

            $stmt_insert = $conn->prepare("INSERT INTO usuarios (email, password, rol) VALUES (?, ?, ?)");
            $stmt_insert->bind_param("sss", $email, $hashed_password, $rol);

            if ($stmt_insert->execute()) {
                // ✅ Registro exitoso, redirigir
                header("Location: index.php?registro=exitoso");
                exit();
            } else {
                $message = "Error al registrar el usuario: " . $stmt_insert->error;
            }
            $stmt_insert->close();
        }
        $stmt_check->close();
    }
}
$conn->close();
?>
