<?php
require_once 'config.php'; // Conexión $conn

$sql = "SELECT id, password FROM usuarios";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $id = $row['id'];
        $password_plano = $row['password'];

        // Verificamos si ya parece un hash (empieza con $2y$ o $argon2i$, etc)
        if (substr($password_plano, 0, 4) !== '$2y$' && substr($password_plano, 0, 7) !== '$argon2') {
            // No es hash, entonces hasheamos la contraseña
            $password_hash = password_hash($password_plano, PASSWORD_DEFAULT);

            // Actualizamos en la base
            $update = $conn->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
            $update->bind_param("si", $password_hash, $id);
            $update->execute();

            if ($update->affected_rows > 0) {
                echo "Contraseña del usuario ID $id actualizada a hash.<br>";
            }

            $update->close();
        } else {
            echo "Usuario ID $id ya tiene contraseña hasheada.<br>";
        }
    }
} else {
    echo "No hay usuarios en la base.<br>";
}

$conn->close();
?>