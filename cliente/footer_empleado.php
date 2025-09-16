<?php
// Lógica para cerrar la sesión
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ./../index.php");
    exit();
}
?>
</body>
</html>