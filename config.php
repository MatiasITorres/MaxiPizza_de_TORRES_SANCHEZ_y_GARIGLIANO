<?php
$servername = "localhost";
$username = "root";
$password = "";
$database = "maxipizza";

define('DB_HOST', 'localhost');
define('DB_USER', 'root'); // Usuario por defecto de XAMPP
define('DB_PASS', '');     // Contraseña por defecto de XAMPP (vacía)
define('DB_NAME', 'maxipizza'); // Nombre de tu base de datos

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
