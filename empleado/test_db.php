<?php
// C:\xampp\htdocs\maxipizza1.1\test_db.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . './../config.php'; // Esta ruta es para test_db.php

echo "Intentando conectar a la base de datos...<br>";

if (!defined('DB_HOST')) {
    die("Error: La constante DB_HOST no está definida. Revisa config.php.");
}
if (!defined('DB_USER')) {
    die("Error: La constante DB_USER no está definida. Revisa config.php.");
}
if (!defined('DB_PASS')) {
    die("Error: La constante DB_PASS no está definida. Revisa config.php.");
}
if (!defined('DB_NAME')) {
    die("Error: La constante DB_NAME no está definida. Revisa config.php.");
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("Error de conexión a la base de datos: " . $conn->connect_error);
}

echo "Conexión exitosa a la base de datos '" . DB_NAME . "'!";
$conn->close();
?>