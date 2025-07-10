<?php
// admin/manage_users.php
// This file assumes $conn and $message are available from admin_dashboard.php

// --- USER MANAGEMENT LOGIC ---

// Add new user
if (isset($_POST['add_user'])) {
    $email = htmlspecialchars($_POST['email']);
    $password_plano = htmlspecialchars($_POST['password']);
    $rol = htmlspecialchars($_POST['rol']);

    // Encrypt the password
    $password_hash = password_hash($password_plano, PASSWORD_BCRYPT);

    $stmt = $conn->prepare("INSERT INTO usuarios (email, password, rol) VALUES (?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("sss", $email, $password_hash, $rol);
        if ($stmt->execute()) {
            $message = "Usuario añadido correctamente.";
        } else {
            if ($conn->errno == 1062) { // Duplicate entry error
                $message = "Error: El email ya está registrado. Por favor, usa otro email.";
            } else {
                $message = "Error al añadir usuario: " . $stmt->error;
            }
        }
        $stmt->close();
    } else {
        $message = "Error al preparar la consulta para añadir usuario: " . $conn->error;
    }
}

// Modify existing user
if (isset($_POST['edit_user'])) {
    $id = intval($_POST['user_id']);
    $email = htmlspecialchars($_POST['email']);
    $rol = htmlspecialchars($_POST['rol']);
    $password_plano = htmlspecialchars($_POST['password']);

    if (!empty($password_plano)) {
        $password_hash = password_hash($password_plano, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE usuarios SET email = ?, password = ?, rol = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("sssi", $email, $password_hash, $rol, $id);
        } else {
            $message = "Error al preparar la consulta para modificar usuario (con password): " . $conn->error;
        }
    } else {
        $stmt = $conn->prepare("UPDATE usuarios SET email = ?, rol = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("ssi", $email, $rol, $id);
        } else {
            $message = "Error al preparar la consulta para modificar usuario (sin password): " . $conn->error;
        }
    }

    if (isset($stmt) && $stmt->execute()) {
        $message = "Usuario modificado correctamente.";
    } elseif (isset($stmt)) {
        if ($conn->errno == 1062) { // Duplicate entry error
            $message = "Error: El email ya está registrado para otro usuario. Por favor, usa otro email.";
        } else {
            $message = "Error al modificar usuario: " . $stmt->error;
        }
    }
    if (isset($stmt)) {
        $stmt->close();
    }
}

// Delete user
if (isset($_GET['delete_user_id'])) {
    $id = intval($_GET['delete_user_id']);
    $stmt = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $message = "Usuario eliminado correctamente.";
        } else {
            $message = "Error al eliminar usuario: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $message = "Error al preparar la consulta para eliminar usuario: " . $conn->error;
    }
    header("Location: admin_dashboard.php?tab=users"); // Redirect to clean URL
    exit();
}

// Get all users for display
$users = [];
$result_users = $conn->query("SELECT id, email, rol FROM usuarios");
if ($result_users) {
    while ($row = $result_users->fetch_assoc()) {
        $users[] = $row;
    }
}

?>

<h2>Gestionar Usuarios</h2>

<?php
// Form for editing a user
if (isset($_GET['edit_user_id'])):
    $edit_user_id = intval($_GET['edit_user_id']);
    $stmt = $conn->prepare("SELECT id, email, rol FROM usuarios WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $edit_user_id);
        $stmt->execute();
        $user_to_edit = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        $user_to_edit = null;
    }

    if ($user_to_edit):
?>
        <h3>Modificar Usuario (ID: <?php echo $user_to_edit['id']; ?>)</h3>
        <form action="admin_dashboard.php?tab=users" method="POST">
            <input type="hidden" name="user_id" value="<?php echo $user_to_edit['id']; ?>">
            <label for="edit_user_email">Email:</label>
            <input type="email" id="edit_user_email" name="email" value="<?php echo htmlspecialchars($user_to_edit['email']); ?>" required>
            <label for="edit_user_password">Nueva Contraseña (dejar en blanco para no cambiar):</label>
            <input type="password" id="edit_user_password" name="password">
            <label for="edit_user_rol">Rol:</label>
            <select id="edit_user_rol" name="rol" required>
                <option value="panel" <?php echo ($user_to_edit['rol'] === 'panel') ? 'selected' : ''; ?>>Panel</option>
                <option value="administrador" <?php echo ($user_to_edit['rol'] === 'administrador') ? 'selected' : ''; ?>>Administrador</option>
                <option value="empleado" <?php echo ($user_to_edit['rol'] === 'empleado') ? 'selected' : ''; ?>>Empleado</option>
                <option value="cocinero" <?php echo ($user_to_edit['rol'] === 'cocinero') ? 'selected' : ''; ?>>Cocinero</option>
                <option value="cliente" <?php echo ($user_to_edit['rol'] === 'cliente') ? 'selected' : ''; ?>>Cliente</option>
            </select>
            <button type="submit" name="edit_user">Guardar Cambios</button>
            <a href="admin_dashboard.php?tab=users" class="btn cancel-button">Cancelar</a>
        </form>
<?php
    endif;
endif;
?>

<h3>Añadir Nuevo Usuario</h3>
<form action="admin_dashboard.php?tab=users" method="POST">
    <label for="user_email">Email:</label>
    <input type="email" name="email" required>
    <label for="user_password">Contraseña:</label>
    <input type="password" name="password" required>
    <label for="user_rol">Rol:</label>
    <select name="rol" required>
        <option value="administrador">Administrador</option>
        <option value="empleado">Empleado</option>
        <option value="cocinero">Cocinero</option>
        <option value="cliente">Cliente</option>
        <option value="panel">Panel</option>
    </select>
    <button type="submit" name="add_user">Añadir Usuario</button>
</form>

<h3>Lista de Usuarios</h3>
<table>
    <thead><tr><th>ID</th><th>Email</th><th>Rol</th><th>Acciones</th></tr></thead>
    <tbody>
        <?php if (empty($users)): ?>
            <tr><td colspan="4" class="no-data">No hay usuarios registrados.</td></tr>
        <?php else: ?>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?php echo $user['id']; ?></td>
                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                    <td><?php echo htmlspecialchars($user['rol']); ?></td>
                    <td class="action-buttons">
                        <a href="?tab=users&edit_user_id=<?php echo $user['id']; ?>" class="btn edit">Modificar</a>
                        <a href="?tab=users&delete_user_id=<?php echo $user['id']; ?>" class="btn delete" onclick="return confirm('¿Estás seguro de eliminar a este usuario?');">Eliminar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>