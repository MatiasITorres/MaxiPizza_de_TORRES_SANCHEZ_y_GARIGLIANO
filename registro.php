<?php
session_start();
require_once './config.php'; 

// --- 1. LÓGICA PARA CARGAR CONFIGURACIÓN DE EMPRESA DESDE JSON (Necesario para el logo/nombre) ---
$settings = [];
$config_file = __DIR__ . '/admin/config_data.json'; 

if (file_exists($config_file)) {
    $settings = json_decode(file_get_contents($config_file), true);
}
$company_name = $settings['company_name'] ?? 'MaxiPizza Default';
$default_grayscale_mode = $settings['default_grayscale_mode'] ?? false; 

$logo_path_json = $settings['logo_path'] ?? './img/SGPP.png'; 
$logo_path = str_replace('../', '', $logo_path_json);
$logo_path = './' . ltrim($logo_path, './');

$message = ""; // Mensaje de éxito o error
$message_type = ""; // 'error' o 'success'

// --- 2. LÓGICA DE CONEXIÓN Y REGISTRO ---
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    $message = "Error de conexión al sistema.";
    $message_type = "error";
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && $message_type !== "error") {
    // 2.1. Recoger y sanear datos
    $nombre = trim($_POST['nombre']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $telefono = trim($_POST['telefono']);
    $ubicacion = trim($_POST['ubicacion']); // Asumimos que 'ubicacion' es la dirección

    // 2.2. Validación básica
    if (empty($nombre) || empty($email) || empty($password)) {
        $message = "Por favor, complete todos los campos obligatorios (Nombre, Correo, Contraseña).";
        $message_type = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Formato de correo electrónico inválido.";
        $message_type = "error";
    } elseif (strlen($password) < 6) {
        $message = "La contraseña debe tener al menos 6 caracteres.";
        $message_type = "error";
    } else {
        // 2.3. Verificar si el email ya existe
        $stmt_check = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt_check->bind_param("s", $email);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();

        if ($result_check->num_rows > 0) {
            $message = "Este correo electrónico ya está registrado.";
            $message_type = "error";
        } else {
            // 2.4. Encriptar contraseña y registrar usuario
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $rol = 'cliente'; // Rol fijo para nuevos registros

            $stmt_insert = $conn->prepare("INSERT INTO usuarios (nombre, email, password, rol, telefono, ubicacion) VALUES (?, ?, ?, ?, ?, ?)");
            
            if ($stmt_insert) {
                $stmt_insert->bind_param("ssssss", $nombre, $email, $hashed_password, $rol, $telefono, $ubicacion);
                
                if ($stmt_insert->execute()) {
                    // Registro exitoso: redirigir a login
                    $_SESSION['registration_success'] = "¡Registro exitoso! Por favor, inicia sesión.";
                    header("Location: index.php");
                    exit();
                } else {
                    $message = "Error al registrar el usuario: " . $conn->error;
                    $message_type = "error";
                }
                $stmt_insert->close();
            } else {
                $message = "Error en la preparación de la consulta de registro.";
                $message_type = "error";
            }
        }
        $stmt_check->close();
    }
}

// Cierra la conexión si existe
if (isset($conn)) {
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Clientes - <?php echo htmlspecialchars($company_name); ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body id="body-main">
    <div class="login-container">
        <div class="grayscale-toggle">
            B/N
            <input type="checkbox" id="grayscale-switch">
        </div>
        
        <img src="<?php echo htmlspecialchars($logo_path); ?>" alt="<?php echo htmlspecialchars($company_name); ?>"> 
        
        <h2>Registro de Cliente</h2>
        
        <?php 
        // Mostrar mensaje de éxito o error
        if (!empty($message)): 
            $alert_class = ($message_type === 'success') ? 'alert-success' : 'alert-error';
        ?>
            <p class="error-message <?php echo htmlspecialchars($alert_class); ?>"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>
        
        <form action="registro.php" method="POST">
            <div class="form-group">
                <label for="nombre">Nombre Completo:*</label>
                <input type="text" id="nombre" name="nombre" required value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="email">Correo Electrónico:*</label>
                <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="password">Contraseña:*</label>
                <input type="password" id="password" name="password" required>
            </div>
             <div class="form-group">
                <label for="telefono">Teléfono:</label>
                <input type="text" id="telefono" name="telefono" value="<?php echo htmlspecialchars($_POST['telefono'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="ubicacion">Ubicación/Dirección:</label>
                <input type="text" id="ubicacion" name="ubicacion" value="<?php echo htmlspecialchars($_POST['ubicacion'] ?? ''); ?>">
            </div>

            <button type="submit">Registrarme</button>
        </form>

        <div style="margin-top: 15px; font-size: 0.9em;">
            ¿Ya tienes cuenta? <a href="index.php">Ir al Login</a>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const body = document.getElementById('body-main');
            const switchInput = document.getElementById('grayscale-switch');
            const COOKIE_NAME = 'grayscaleMode';

            const DEFAULT_MODE_FROM_CONFIG = <?php echo json_encode($default_grayscale_mode); ?>;

            function setCookie(name, value, days) {
                let expires = "";
                if (days) {
                    const date = new Date();
                    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                    expires = "; expires=" + date.toUTCString();
                }
                document.cookie = name + "=" + (value || "")  + expires + "; path=/; SameSite=Lax";
            }

            function getCookie(name) {
                const nameEQ = name + "=";
                const ca = document.cookie.split(';');
                for(let i=0; i < ca.length; i++) {
                    let c = ca[i];
                    while (c.charAt(0) === ' ') c = c.substring(1, c.length);
                    if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
                }
                return null;
            }

            function applyGrayscaleMode(isGrayscale, savePreference = true) {
                if (isGrayscale) {
                    body.classList.add('grayscale-mode');
                } else {
                    body.classList.remove('grayscale-mode');
                }
                switchInput.checked = isGrayscale;
                
                if (savePreference) {
                    setCookie(COOKIE_NAME, isGrayscale ? 'on' : 'off', 30);
                }
            }

            const savedMode = getCookie(COOKIE_NAME);
            
            if (savedMode === 'on') {
                applyGrayscaleMode(true, false);
            } else if (savedMode === 'off') {
                applyGrayscaleMode(false, false);
            } else {
                applyGrayscaleMode(DEFAULT_MODE_FROM_CONFIG, false); 
            }

            switchInput.addEventListener('change', function() {
                applyGrayscaleMode(this.checked, true); 
            });
        });
    </script>
</body>
</html>