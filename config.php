<?php
$servername = "192.168.101.93";
$username = "AG08";
$password = "St2025#QUcwOA";
$database = "ag08";

define('DB_HOST', '192.168.101.93');
define('DB_USER', 'AG08'); // Usuario por defecto de XAMPP
define('DB_PASS', 'St2025#QUcwOA');     // Contraseña por defecto de XAMPP (vacía)
define('DB_NAME', 'ag08'); // Nombre de tu base de datos

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
