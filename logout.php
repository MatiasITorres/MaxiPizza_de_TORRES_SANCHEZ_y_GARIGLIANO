<?php
// 1. Iniciar la sesión
// Esto es necesario para poder acceder a las variables de sesión ($_SESSION)
session_start();

// 2. Destruir todas las variables de sesión
// Esto elimina los datos específicos de la sesión actual
$_SESSION = array();

// 3. Si se desea destruir la cookie de sesión (opcional pero recomendado)
// Esto asegura que incluso la cookie de sesión del navegador se invalide
// Nota: La cookie de sesión por defecto se llama 'PHPSESSID'
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 4. Finalmente, destruir la sesión
// Esto elimina el archivo de sesión del servidor
session_destroy();

// 5. Redirigir al usuario a la página de inicio de sesión o a la página principal
// Reemplaza 'login.php' con la URL a la que deseas redirigir al usuario después del cierre de sesión
header("Location: ./index.php");
exit; // Asegura que el script se detenga inmediatamente después de la redirección
?>