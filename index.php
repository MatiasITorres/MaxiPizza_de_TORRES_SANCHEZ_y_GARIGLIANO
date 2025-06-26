<?php
session_start();
require_once 'config.php'; // Aquí $conn ya debería estar definida y conectada

$error_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, email, password, rol FROM usuarios WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // ✅ Verificar contraseña encriptada
        if (password_verify($password, $user['password'])) {
            $_SESSION['usuario_id'] = $user['id'];
            $_SESSION['usuario_email'] = $user['email'];
            $_SESSION['usuario_rol'] = $user['rol'];

            // Redirigir según el rol del usuario
            switch ($user['rol']) {
                case 'administrador':
                    header("Location: ./admin/admin_dashboard.php");
                    break;
                case 'empleado':
                    header("Location: ./empleado/empleado_dashboard.php");
                    break;
                case 'cocinero':
                    header("Location: ./cocinero/cocinero_dashboard.php");
                    break;
                case 'cliente':
                    header("Location: ./cliente/cliente_dashboard.php");
                    break;
                case 'panel':
                    header("Location: ./panel/pedidos.php");
                    break;
                default:
                    $error_message = "Rol de usuario no reconocido.";
                    session_destroy();
                    break;
            }
            exit();
        } else {
            $error_message = "Credenciales inválidas. Contraseña incorrecta.";
        }
    } else {
        $error_message = "Credenciales inválidas. Usuario no encontrado.";
    }
    $stmt->close();
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso a MaxiPizza</title>
    <link rel="stylesheet" href="./css/style.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .login-container {
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 350px;
            text-align: center;
        }
        h2 {
            color: #333;
            margin-bottom: 20px;
        }
        .error-message {
            color: #dc3545;
            margin-bottom: 15px;
            font-weight: bold;
        }
        .form-group {
            margin-bottom: 15px;
            text-align: left;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #555;
        }
        .form-group input {
            width: calc(100% - 20px);
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1em;
        }
        button {
            width: 100%;
            padding: 10px;
            background-color: #ff3366;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 1.1em;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }
        button:hover {
            background-color: #e6004c;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Acceso a MaxiPizza</h2>
        <?php if (!empty($error_message)): ?>
            <p class="error-message"><?php echo htmlspecialchars($error_message); ?></p>
        <?php endif; ?>
        <form action="index.php" method="POST">
            <div class="form-group">
                <label for="email">Correo:</label>
                <input type="text" id="email" name="email" required>
            </div>
            <div class="form-group">
                <label for="password">Contraseña:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit">Ingresar</button>
        </form>
        <p style="margin-top: 20px; font-size: 0.8em; color: #777;">&copy; <?php echo date("Y"); ?> MaxiPizza</p>
    </div>
</body>
</html>
