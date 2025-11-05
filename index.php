<?php
session_start();
// Asegúrate de que config.php esté en el mismo nivel que index.php
require_once './config.php'; 

// --- 1. LÓGICA PARA CARGAR CONFIGURACIÓN DE EMPRESA DESDE JSON ---
$settings = [];
// El archivo JSON está en el directorio 'admin/'
$config_file = __DIR__ . '/admin/config_data.json'; 

if (file_exists($config_file)) {
    // Lee y decodifica el contenido del JSON
    $settings = json_decode(file_get_contents($config_file), true);
}

// Establece valores predeterminados si no se encuentran en el JSON
$company_name = $settings['company_name'] ?? 'MaxiPizza Default';

// Carga la configuración de tema (dark/white) desde el JSON
$default_theme_mode = $settings['theme_mode'] ?? 'white'; 
// Carga la configuración inicial de B/N desde el JSON
$default_grayscale_mode = $settings['default_grayscale_mode'] ?? false; 


// La ruta guardada en el JSON ('../img/...') es relativa al admin/, 
// para el index.php (en la raíz) debemos ajustarla a './img/...'
$logo_path_json = $settings['logo_path'] ?? './img/default_logo.png'; 
$logo_path = str_replace('../', '', $logo_path_json);
$logo_path = './' . ltrim($logo_path, './'); // Asegura que la ruta comience correctamente

$error_message = "";

// --- 2. LÓGICA DE CONEXIÓN Y PROCESAMIENTO DE LOGIN ---
// La conexión debe manejarse aquí si no lo hace config.php automáticamente.
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    // Solo mostramos un error genérico para la vista de usuario
    $error_message = "Error de conexión al sistema.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($error_message)) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, email, password, rol FROM usuarios WHERE email = ?");
    if ($stmt) {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            // Verificar contraseña encriptada
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
                        header("Location: ./cliente/cliente_dashboard.php"); // Corregido a .php
                        break;
                    case 'panel':
                        header("Location: ./panel/pedidos.php");
                        break;
                    default:
                        $error_message = "Rol de usuario no reconocido.";
                        break;
                }
                exit();
            } else {
                $error_message = "Credenciales incorrectas.";
            }
        } else {
            $error_message = "Credenciales incorrectas.";
        }

        $stmt->close();
    } else {
        $error_message = "Error en la consulta de login.";
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
    <title>Acceso al Sistema - <?php echo htmlspecialchars($company_name); ?></title>
    <link rel="stylesheet" href="./style.css">
    <link rel="stylesheet" href="./admin/style.css">
    </head>
<body id="body-main">
    
    <div class="login-container">
        
        <img src="<?php echo htmlspecialchars($logo_path); ?>" alt="<?php echo htmlspecialchars($company_name); ?>"> 
        
        <h2>Acceso a <?php echo htmlspecialchars($company_name); ?></h2>
        
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
        
        <div style="margin-top: 15px; font-size: 0.9em;">
            ¿No tienes cuenta? <a href="registro.php">Regístrate aquí</a>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const body = document.getElementById('body-main');
            
            // Variables de configuración obtenidas del JSON (PHP)
            const DEFAULT_THEME_FROM_CONFIG = <?php echo json_encode($default_theme_mode); ?>; // 'dark' o 'white'
            const DEFAULT_GRAY_FROM_CONFIG = <?php echo json_encode($default_grayscale_mode); ?>; // true o false

            // --- APLICACIÓN DEL MODO OSCURO (dark-mode) ---
            if (DEFAULT_THEME_FROM_CONFIG === 'dark') {
                body.classList.add('dark-mode');
            } else {
                body.classList.remove('dark-mode');
            }
            
            // --- APLICACIÓN DEL MODO B/N (grayscale-mode) ---
            if (DEFAULT_GRAY_FROM_CONFIG) {
                body.classList.add('grayscale-mode');
            } else {
                body.classList.remove('grayscale-mode');
            }

            // SE ELIMINÓ TODA LA LÓGICA DE COOKIES Y ESCUCHADORES DE EVENTOS
        });
    </script>
</body>
</html>