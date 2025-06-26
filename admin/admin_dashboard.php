<?php
// admin_dashboard.php
session_start();
require_once './../config.php';

// Establecer la conexión a la base de datos al principio
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// 1. VERIFICACIÓN DE SESIÓN DE ADMINISTRADOR
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'administrador') {
    header("Location: ./../index.php");
    exit();
}

// 2. LÓGICA PARA CERRAR SESIÓN
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ./../index.php");
    exit();
}

$message = "";

// 3. MANEJO DE ACCIONES POST (Añadir/Modificar) Y GET (Eliminar)
// Esta sección se ejecuta cuando se envía un formulario o se hace clic en un enlace de acción.

// --- GESTIÓN DE USUARIOS ---

// Añadir nuevo usuario
if (isset($_POST['add_user'])) {
    $email = htmlspecialchars($_POST['email']);
    $password_plano = htmlspecialchars($_POST['password']);
    $rol = htmlspecialchars($_POST['rol']);

    // Encriptar la contraseña
    $password_hash = password_hash($password_plano, PASSWORD_BCRYPT);

    $stmt = $conn->prepare("INSERT INTO usuarios (email, password, rol) VALUES (?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("sss", $email, $password_hash, $rol);
        if ($stmt->execute()) {
            $message = "Usuario añadido correctamente.";
        } else {
            // Check for duplicate entry error (error code 1062 for MySQL)
            if ($conn->errno == 1062) {
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

// Modificar usuario existente
if (isset($_POST['edit_user'])) {
    $id = intval($_POST['user_id']);
    $email = htmlspecialchars($_POST['email']);
    $rol = htmlspecialchars($_POST['rol']);
    $password_plano = htmlspecialchars($_POST['password']);

    if (!empty($password_plano)) {
        // Encriptar la nueva contraseña
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
        // Check for duplicate entry error (error code 1062 for MySQL)
        if ($conn->errno == 1062) {
            $message = "Error: El email ya está registrado para otro usuario. Por favor, usa otro email.";
        } else {
            $message = "Error al modificar usuario: " . $stmt->error;
        }
    }
    if (isset($stmt)) {
        $stmt->close();
    }
}

// Eliminar usuario
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
    header("Location: admin_dashboard.php"); // Redirigir para limpiar la URL
    exit();
}


// --- GESTIÓN DE PRODUCTOS ---

// Añadir nuevo producto
if (isset($_POST['add_product'])) {
    $nombre = htmlspecialchars($_POST['nombre']);
    $precio = floatval($_POST['precio']);
    $stmt = $conn->prepare("INSERT INTO productos (nombre, precio) VALUES (?, ?)");
    if ($stmt) {
        $stmt->bind_param("sd", $nombre, $precio);
        if ($stmt->execute()) {
            $message = "Producto añadido correctamente.";
        } else {
            $message = "Error al añadir producto: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $message = "Error al preparar la consulta para añadir producto: " . $conn->error;
    }
}

// Modificar producto existente
if (isset($_POST['edit_product'])) {
    $id = intval($_POST['product_id']);
    $nombre = htmlspecialchars($_POST['nombre']);
    $precio = floatval($_POST['precio']);
    $stmt = $conn->prepare("UPDATE productos SET nombre = ?, precio = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("sdi", $nombre, $precio, $id);
        if ($stmt->execute()) {
            $message = "Producto modificado correctamente.";
        } else {
            $message = "Error al modificar producto: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $message = "Error al preparar la consulta para modificar producto: " . $conn->error;
    }
}

// Eliminar producto
if (isset($_GET['delete_product_id'])) {
    $id = intval($_GET['delete_product_id']);
    $stmt = $conn->prepare("DELETE FROM productos WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $message = "Producto eliminado correctamente.";
        } else {
            $message = "Error al eliminar producto: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $message = "Error al preparar la consulta para eliminar producto: " . $conn->error;
    }
    header("Location: admin_dashboard.php"); // Redirigir para limpiar la URL
    exit();
}


// 4. OBTENCIÓN DE DATOS PARA MOSTRAR EN LA PÁGINA
// Se obtienen después de cualquier acción de modificación para reflejar los cambios.

// Obtener historial de todos los pedidos
$all_orders = [];
$result_orders = $conn->query("SELECT p.id, p.fecha, p.total, p.estado, u.email AS cliente_registrado, p.nombre_cliente_calle FROM pedidos p LEFT JOIN usuarios u ON p.cliente_id = u.id ORDER BY p.fecha DESC");
if ($result_orders) {
    while ($row = $result_orders->fetch_assoc()) {
        $all_orders[] = $row;
    }
}

// Obtener todos los usuarios
$users = [];
$result_users = $conn->query("SELECT id, email, rol FROM usuarios");
if ($result_users) {
    while ($row = $result_users->fetch_assoc()) {
        $users[] = $row;
    }
}

// Obtener todos los productos
$products = [];
$result_products = $conn->query("SELECT id, nombre, precio FROM productos");
if ($result_products) {
    while ($row = $result_products->fetch_assoc()) {
        $products[] = $row;
    }
}

// --- ALGORITMO PARA OBTENER LOS PRODUCTOS MÁS VENDIDOS ---
$best_selling_products = [];
$sql_best_sellers = "SELECT
                            prod.nombre AS product_name,
                            SUM(pp.cantidad) AS total_quantity_sold
                        FROM
                            productos prod
                        JOIN
                            pedido_productos pp ON prod.id = pp.producto_id
                        GROUP BY
                            prod.id, prod.nombre
                        ORDER BY
                            total_quantity_sold DESC
                        LIMIT 5"; // Limitar a los 5 productos más vendidos

$result_best_sellers = $conn->query($sql_best_sellers);
if ($result_best_sellers) {
    while ($row = $result_best_sellers->fetch_assoc()) {
        $best_selling_products[] = $row;
    }
} else {
    // Manejo de errores si la consulta falla
    error_log("Error al obtener productos más vendidos: " . $conn->error);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administración | MaxiPizza</title>
    <link rel="stylesheet" href="./../css/style.css"> 
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&family=Lobster&display=swap" rel="stylesheet">
    <style>
    /* Variables CSS para consistencia de la paleta de pizzería (ajustadas según la nueva imagen) */
    :root {
        --primary-color: #c0392b; /* Rojo pizza! */
        --secondary-color: #e67e22; /* Naranja vibrante (para encabezados) */
        --tertiary-color: #f39c12; /* Amarillo-naranja para modificar */
        --dark-text: #333; /* Texto oscuro */
        --light-bg: #f8f8f8; /* Fondo general claro */
        --white-bg: #ffffff;
        --light-border: #ddd; /* Bordes grises claros */
        --success-color: #27ae60;
        --error-color: #e74c3c;
        --info-color: #3498db;
        --grey-button: #7f8c8d; /* Gris para botones de cancelar/cerrar sesión */

        /* Estados de pedido */
        --status-pendiente: #f39c12;
        --status-en_preparacion: #3498db;
        --status-listo: #27ae60;
        --status-entregado: #7f8c8d;
        --status-cancelado: #e74c3c;
    }

    /* General Body & Container Styles */
    body {
        font-family: 'Open Sans', sans-serif;
        background-color: var(--light-bg);
        margin: 0;
        padding: 20px;
        color: var(--dark-text);
        line-height: 1.6;
        display: flex;
        flex-direction: column;
        align-items: center;
        min-height: 100vh;
    }

    .container {
        max-width: 1300px;
        width: 95%;
        margin: 20px auto;
        background-color: var(--white-bg);
        padding: 30px;
        border-radius: 10px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        border: 1px solid var(--light-border);
        box-sizing: border-box;
    }

    h1 {
        font-family: 'Lobster', cursive;
        text-align: center;
        color: var(--primary-color);
        margin-bottom: 25px;
        font-size: 2.8em;
        text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1);
        padding-bottom: 10px;
        border-bottom: 2px solid var(--secondary-color);
        line-height: 1.2;
    }

    p {
        text-align: center;
        color: var(--dark-text);
        margin-bottom: 25px;
        font-size: 1.1em;
    }

    /* Message & Alerts */
    .message {
        padding: 15px;
        border-radius: 8px;
        margin: 20px auto;
        font-weight: 600;
        border: 1px solid transparent;
        text-align: center;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        max-width: 600px;
    }
    .message.success { background-color: #e6ffe6; color: var(--success-color); border-color: #d0f0d0; }
    .message.error { background-color: #ffe6e6; color: var(--error-color); border-color: #f0d0d0; }

    /* Dashboard Grid Layout */
    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
        gap: 30px;
        margin-top: 30px;
    }

    .grid-item {
        background-color: var(--white-bg);
        border: 1px solid var(--light-border);
        border-radius: 10px;
        padding: 30px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        box-sizing: border-box;
    }

    .grid-item h2, .grid-item h3 {
        font-family: 'Lobster', cursive;
        color: var(--secondary-color);
        margin-top: 0;
        margin-bottom: 20px;
        font-size: 2em;
        text-align: center;
        border-bottom: 2px solid var(--light-border);
        padding-bottom: 10px;
        text-shadow: 1px 1px 3px rgba(0,0,0,0.08);
    }
    .grid-item h3 {
        font-size: 1.6em;
        color: var(--primary-color);
        border-bottom: 1px dashed var(--light-border);
        margin-top: 30px;
        margin-bottom: 20px;
    }

    /* Table Styles (adjusted for the new look) */
    table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        margin-bottom: 20px;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        background-color: var(--white-bg);
        border: 1px solid var(--light-border); /* Añadido borde a la tabla */
    }

    th, td {
        padding: 12px 15px;
        border-bottom: 1px solid var(--light-border); /* Bordes de celda */
        text-align: left;
        vertical-align: middle;
        font-size: 1em;
    }

    th {
        background-color: var(--secondary-color); /* Naranja para encabezados */
        color: white;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        position: sticky;
        top: 0;
        z-index: 10;
    }

    tr:nth-child(even) { background-color: #f9f9f9; } /* Fondo ligeramente grisáceo para filas pares */
    tr:hover { background-color: #f5f5f5; }
    td { color: var(--dark-text); }

    /* Status Badges */
    .status {
        display: inline-block;
        padding: 6px 10px;
        border-radius: 5px;
        font-weight: bold;
        font-size: 0.9em;
        text-transform: capitalize;
        color: white;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .status.pendiente { background-color: var(--status-pendiente); color: var(--dark-text); } /* Texto oscuro para pendiente */
    .status.en_preparacion { background-color: var(--status-en_preparacion); }
    .status.listo { background-color: var(--status-listo); }
    .status.entregado { background-color: var(--status-entregado); }
    .status.cancelado { background-color: var(--status-cancelado); }


    /* Form Styles */
    form {
        margin-top: 25px;
        padding: 25px;
        border: 1px solid var(--light-border);
        border-radius: 8px;
        background-color: #fefefe;
        box-shadow: inset 0 1px 5px rgba(0,0,0,0.02);
    }
    form label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: var(--dark-text);
        font-size: 1.05em;
    }
    form input, form select {
        width: calc(100% - 24px);
        padding: 12px;
        margin-bottom: 18px;
        border: 1px solid #ccc;
        border-radius: 4px;
        box-sizing: border-box;
        font-size: 1em;
        transition: border-color 0.3s, box-shadow 0.3s;
    }
    form input:focus, form select:focus {
        border-color: var(--secondary-color);
        box-shadow: 0 0 8px rgba(230, 126, 34, 0.2);
        outline: none;
    }

    /* Botones generales */
    button, .btn {
        background-color: var(--secondary-color);
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 5px;
        font-size: 1em;
        font-weight: 600;
        cursor: pointer;
        transition: background-color 0.2s ease, transform 0.1s ease, box-shadow 0.2s ease;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        display: inline-block;
        text-align: center;
        text-decoration: none;
        margin-right: 10px;
        margin-top: 10px;
    }
    button:hover, .btn:hover {
        background-color: #d35400;
        transform: translateY(-1px);
        box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
    }

    /* Botones de Acción en tabla (Modificar/Eliminar) - Ajustados para la nueva imagen */
    .action-buttons {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
        white-space: nowrap;
    }

    .action-buttons .btn {
        padding: 8px 12px;
        font-size: 0.9em;
        border-radius: 4px;
        font-weight: 600;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        margin-right: 0;
    }
    .action-buttons .btn.edit {
        background-color: var(--tertiary-color); /* Amarillo-naranja para Modificar */
        color: var(--dark-text); /* Texto oscuro para Modificar */
    }
    .action-buttons .btn.edit:hover {
        background-color: #e67e22; /* Naranja más oscuro al pasar el ratón */
        color: white;
        transform: translateY(-1px);
    }

    .action-buttons .btn.delete {
        background-color: var(--primary-color); /* Rojo para Eliminar */
        color: white;
    }
    .action-buttons .btn.delete:hover {
        background-color: #a93226; /* Rojo más oscuro al pasar el ratón */
        transform: translateY(-1px);
    }

    /* Botón Cancelar */
    .btn.cancel-button {
        background-color: var(--grey-button);
        color: white;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }
    .btn.cancel-button:hover {
        background-color: #6c7a89;
        transform: translateY(-1px);
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15);
    }

    /* Botón Cerrar Sesión */
    .logout-button {
        background-color: var(--grey-button);
        color: white;
        padding: 12px 25px;
        border-radius: 5px;
        font-size: 1em;
        font-weight: 600;
        text-decoration: none;
        display: inline-block;
        margin-top: 30px;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        transition: background-color 0.2s ease, transform 0.1s ease, box-shadow 0.2s ease;
    }
    .logout-button:hover {
        background-color: #6c7a89;
        transform: translateY(-1px);
        box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
    }

    .no-data {
        text-align: center;
        color: var(--dark-text);
        padding: 30px;
        border: 2px dashed var(--light-border);
        border-radius: 8px;
        margin-top: 20px;
        font-style: italic;
        font-size: 1.1em;
        background-color: #fefefe;
    }

    /* Scrollbar */
    ::-webkit-scrollbar { width: 8px; }
    ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
    ::-webkit-scrollbar-thumb { background: var(--secondary-color); border-radius: 4px; }
    ::-webkit-scrollbar-thumb:hover { background: #d35400; }
    html { scrollbar-color: var(--secondary-color) #f1f1f1; scrollbar-width: thin; }


    /* Media Queries */
    @media (max-width: 992px) {
        .dashboard-grid { grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 25px; }
        .container { padding: 25px; }
        h1 { font-size: 2.5em; }
        .grid-item h2, .grid-item h3 { font-size: 1.8em; }
        th, td { padding: 12px 15px; }
    }

    @media (max-width: 768px) {
        body { padding: 15px; }
        .container { padding: 20px; }
        h1 { font-size: 2em; margin-bottom: 20px; }
        p { font-size: 1em; margin-bottom: 20px; }
        .dashboard-grid { grid-template-columns: 1fr; gap: 20px; }
        .grid-item { padding: 25px; }
        .grid-item h2, .grid-item h3 { font-size: 1.5em; margin-bottom: 15px; }
        form input, form select { width: 100%; padding: 10px; margin-bottom: 15px; }
        button, .btn { width: 100%; margin-right: 0; margin-bottom: 10px; padding: 12px; font-size: 1em; }
        .action-buttons { flex-direction: column; gap: 5px; }
        .action-buttons .btn { width: 100%; }
    }
    @media (max-width: 480px) {
        .container { padding: 15px; }
        h1 { font-size: 1.8em; }
        .grid-item h2, .grid-item h3 { font-size: 1.3em; }
        th, td { padding: 10px; font-size: 0.9em; }
        .status { padding: 5px 8px; font-size: 0.8em; }
        .no-data { padding: 20px; font-size: 1em; }
        .logout-button { width: 100%; }
    }
</style>
</head>
<body>
    <div class="container">
        <h1>Panel de Administración</h1>
        <p>Bienvenido, <strong><?php echo htmlspecialchars($_SESSION['usuario_email']); ?></strong>. Gestiona el sistema desde aquí.</p>

        <?php if (!empty($message)): ?>
            <div class="message <?php echo (strpos($message, 'Error') !== false) ? 'error' : 'success'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="grid-item" style="margin-bottom: 40px;">
            <h2>Historial General de Pedidos</h2>
            <?php if (empty($all_orders)): ?>
                <p class="no-data">Aún no se ha registrado ningún pedido.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Fecha</th>
                            <th>Cliente</th>
                            <th>Total</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_orders as $order): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($order['id']); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($order['fecha'])); ?></td>
                                <td>
                                    <?php
                                    echo htmlspecialchars($order['cliente_registrado'] ?: ($order['nombre_cliente_calle'] ?: 'N/D'));
                                    if (empty($order['cliente_registrado'])) echo ' <small>(A la calle)</small>';
                                    ?>
                                </td>
                                <td>$<?php echo number_format($order['total'], 2); ?></td>
                                <td>
                                    <span class="status <?php echo htmlspecialchars($order['estado']); ?>">
                                        <?php echo str_replace('_', ' ', htmlspecialchars($order['estado'])); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="dashboard-grid">

            <div class="grid-item">
                <h2>Gestionar Usuarios</h2>

                <?php
                // Formulario de Modificar Usuario
                if (isset($_GET['edit_user_id'])):
                    $edit_user_id = intval($_GET['edit_user_id']);
                    $stmt = $conn->prepare("SELECT id, email, rol FROM usuarios WHERE id = ?");
                    if ($stmt) {
                        $stmt->bind_param("i", $edit_user_id);
                        $stmt->execute();
                        $user_to_edit = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                    } else {
                        $user_to_edit = null; // En caso de error en la preparación
                        // El mensaje de error ya se maneja arriba con $message
                    }

                    if ($user_to_edit):
                ?>
                        <h3>Modificar Usuario (ID: <?php echo $user_to_edit['id']; ?>)</h3>
                        <form action="admin_dashboard.php" method="POST">
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
                            <a href="admin_dashboard.php" class="btn cancel-button">Cancelar</a>
                        </form>
                <?php
                    endif;
                endif;
                ?>

                <h3>Añadir Nuevo Usuario</h3>
                <form action="admin_dashboard.php" method="POST">
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
                                        <a href="?edit_user_id=<?php echo $user['id']; ?>" class="btn edit">Modificar</a>
                                        <a href="?delete_user_id=<?php echo $user['id']; ?>" class="btn delete" onclick="return confirm('¿Estás seguro de eliminar a este usuario?');">Eliminar</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="grid-item">
                <h2>Gestionar Productos</h2>

                <?php
                // Formulario de Modificar Producto
                if (isset($_GET['edit_product_id'])):
                    $edit_product_id = intval($_GET['edit_product_id']);
                    $stmt = $conn->prepare("SELECT id, nombre, precio FROM productos WHERE id = ?");
                    if ($stmt) {
                        $stmt->bind_param("i", $edit_product_id);
                        $stmt->execute();
                        $product_to_edit = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                    } else {
                        $product_to_edit = null; // En caso de error
                    }

                    if ($product_to_edit):
                ?>
                        <h3>Modificar Producto (ID: <?php echo $product_to_edit['id']; ?>)</h3>
                        <form action="admin_dashboard.php" method="POST">
                            <input type="hidden" name="product_id" value="<?php echo $product_to_edit['id']; ?>">
                            <label for="edit_product_nombre">Nombre:</label>
                            <input type="text" name="nombre" value="<?php echo htmlspecialchars($product_to_edit['nombre']); ?>" required>
                            <label for="edit_product_precio">Precio:</label>
                            <input type="number" name="precio" step="0.01" value="<?php echo htmlspecialchars($product_to_edit['precio']); ?>" required>
                            <button type="submit" name="edit_product">Guardar Cambios</button>
                            <a href="admin_dashboard.php" class="btn cancel-button">Cancelar</a>
                        </form>
                <?php
                    endif;
                endif;
                ?>

                <h3>Añadir Nuevo Producto</h3>
                <form action="admin_dashboard.php" method="POST">
                    <label for="product_nombre">Nombre:</label>
                    <input type="text" name="nombre" required>
                    <label for="product_precio">Precio:</label>
                    <input type="number" name="precio" step="0.01" required>
                    <button type="submit" name="add_product">Añadir Producto</button>
                </form>

                <h3>Lista de Productos</h3>
                <table>
                    <thead><tr><th>ID</th><th>Nombre</th><th>Precio</th><th>Acciones</th></tr></thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                            <tr><td colspan="4" class="no-data">No hay productos registrados.</td></tr>
                        <?php else: ?>
                            <?php foreach ($products as $product): ?>
                                <tr>
                                    <td><?php echo $product['id']; ?></td>
                                    <td><?php echo htmlspecialchars($product['nombre']); ?></td>
                                    <td>$<?php echo number_format($product['precio'], 2); ?></td>
                                    <td class="action-buttons">
                                        <a href="?edit_product_id=<?php echo $product['id']; ?>" class="btn edit">Modificar</a>
                                        <a href="?delete_product_id=<?php echo $product['id']; ?>" class="btn delete" onclick="return confirm('¿Estás seguro de eliminar este producto?');">Eliminar</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="grid-item">
                <h2>📈 Productos Más Vendidos</h2>
                <?php if (empty($best_selling_products)): ?>
                    <p class="no-data">No hay datos de ventas disponibles para mostrar los productos más vendidos.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Cantidad Total Vendida</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($best_selling_products as $product_sale): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($product_sale['product_name']); ?></td>
                                    <td><?php echo htmlspecialchars($product_sale['total_quantity_sold']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        </div> <div style="text-align: center; margin-top: 40px;">
            <a href="?logout=true" class="btn logout-button">Cerrar Sesión</a>
        </div>
    </div> </body>
</html>
<?php
// 5. CERRAR LA CONEXIÓN A LA BASE DE DATOS
// Se cierra al final de todo el script, después de que se haya renderizado todo el HTML.
$conn->close();
?>