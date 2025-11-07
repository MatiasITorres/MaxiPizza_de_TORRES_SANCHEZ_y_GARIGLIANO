<?php
$servername = "your servername";
$username = "your sername";
$password = "tu password";
$database = "your database";

define('DB_HOST', 'your servername');
define('DB_USER', 'your sername'); // Usuario por defecto de XAMPP
define('DB_PASS', 'tu password');     // Contraseña por defecto de XAMPP (vacía)
define('DB_NAME', 'your database'); // Nombre de tu base de datos

// Opcional: Configuración para mostrar errores de PHP (solo para desarrollo)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


// Crear conexión
$conn = new mysqli($servername, $username, $password, $database);

// Verificar conexión
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}
?>

