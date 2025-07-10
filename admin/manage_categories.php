<?php
// admin/manage_categories.php
// This file assumes $conn and $message are available from admin_dashboard.php

// --- CATEGORY MANAGEMENT LOGIC ---

// Add Category
if (isset($_POST['add_categoria'])) {
    $nombre = htmlspecialchars($_POST['nombre_categoria']);
    $descripcion = htmlspecialchars($_POST['descripcion_categoria']);

    $stmt = $conn->prepare("INSERT INTO categorias_productos (nombre, descripcion) VALUES (?, ?)");
    if ($stmt) {
        $stmt->bind_param("ss", $nombre, $descripcion);
        if ($stmt->execute()) {
            $message = "Categoría agregada correctamente.";
        } else {
            $message = "Error al agregar la categoría: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $message = "Error al preparar la consulta para agregar categoría: " . $conn->error;
    }
}

// Modify Category
if (isset($_POST['edit_categoria'])) {
    $id = intval($_POST['categoria_id']);
    $nombre = htmlspecialchars($_POST['nombre_categoria']);
    $descripcion = htmlspecialchars($_POST['descripcion_categoria']);

    $stmt = $conn->prepare("UPDATE categorias_productos SET nombre = ?, descripcion = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("ssi", $nombre, $descripcion, $id);
        if ($stmt->execute()) {
            $message = "Categoría modificada correctamente.";
        } else {
            $message = "Error al modificar la categoría: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $message = "Error al preparar la consulta para modificar categoría: " . $conn->error;
    }
}

// Delete Category
if (isset($_GET['delete_categoria_id'])) {
    $id = intval($_GET['delete_categoria_id']);
    // You might want to add logic here to reassign products to a default category or prevent deletion if products are linked.
    $stmt = $conn->prepare("DELETE FROM categorias_productos WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $message = "Categoría eliminada correctamente.";
        } else {
            // Check for foreign key constraint violation (e.g., if products are linked)
            if ($conn->errno == 1451) { // MySQL error code for foreign key constraint
                $message = "Error: No se puede eliminar la categoría porque hay productos asociados a ella. Por favor, reasigna los productos primero.";
            } else {
                $message = "Error al eliminar categoría: " . $stmt->error;
            }
        }
        $stmt->close();
    } else {
        $message = "Error al preparar la consulta para eliminar categoría: " . $conn->error;
    }
    header("Location: admin_dashboard.php?tab=categories"); // Redirect to clean URL
    exit();
}

// Get all categories for display
$categories = [];
$result_categories = $conn->query("SELECT id, nombre, descripcion FROM categorias_productos ORDER BY nombre ASC");
if ($result_categories) {
    while ($row = $result_categories->fetch_assoc()) {
        $categories[] = $row;
    }
}
?>

<h2>Gestionar Categorías</h2>

<?php
// Form for editing a category
if (isset($_GET['edit_categoria_id'])):
    $edit_categoria_id = intval($_GET['edit_categoria_id']);
    $stmt = $conn->prepare("SELECT id, nombre, descripcion FROM categorias_productos WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $edit_categoria_id);
        $stmt->execute();
        $categoria_to_edit = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        $categoria_to_edit = null;
    }

    if ($categoria_to_edit):
?>
        <h3>Modificar Categoría (ID: <?php echo $categoria_to_edit['id']; ?>)</h3>
        <form action="admin_dashboard.php?tab=categories" method="POST">
            <input type="hidden" name="categoria_id" value="<?php echo $categoria_to_edit['id']; ?>">
            <label for="edit_categoria_nombre">Nombre:</label>
            <input type="text" id="edit_categoria_nombre" name="nombre_categoria" value="<?php echo htmlspecialchars($categoria_to_edit['nombre']); ?>" required>
            <label for="edit_categoria_descripcion">Descripción:</label>
            <textarea id="edit_categoria_descripcion" name="descripcion_categoria"><?php echo htmlspecialchars($categoria_to_edit['descripcion']); ?></textarea>
            <button type="submit" name="edit_categoria">Guardar Cambios</button>
            <a href="admin_dashboard.php?tab=categories" class="btn cancel-button">Cancelar</a>
        </form>
<?php
    endif;
endif;
?>

<h3>Añadir Nueva Categoría</h3>
<form action="admin_dashboard.php?tab=categories" method="POST">
    <label for="add_categoria_nombre">Nombre:</label>
    <input type="text" id="add_categoria_nombre" name="nombre_categoria" required>
    <label for="add_categoria_descripcion">Descripción:</label>
    <textarea id="add_categoria_descripcion" name="descripcion_categoria"></textarea>
    <button type="submit" name="add_categoria">Añadir Categoría</button>
</form>

<h3>Lista de Categorías</h3>
<table>
    <thead><tr><th>ID</th><th>Nombre</th><th>Descripción</th><th>Acciones</th></tr></thead>
    <tbody>
        <?php if (empty($categories)): ?>
            <tr><td colspan="4" class="no-data">No hay categorías registradas.</td></tr>
        <?php else: ?>
            <?php foreach ($categories as $category): ?>
                <tr>
                    <td><?php echo $category['id']; ?></td>
                    <td><?php echo htmlspecialchars($category['nombre']); ?></td>
                    <td><?php echo htmlspecialchars($category['descripcion']); ?></td>
                    <td class="action-buttons">
                        <a href="?tab=categories&edit_categoria_id=<?php echo $category['id']; ?>" class="btn edit">Modificar</a>
                        <a href="?tab=categories&delete_categoria_id=<?php echo $category['id']; ?>" class="btn delete" onclick="return confirm('¿Estás seguro de eliminar esta categoría?');">Eliminar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>