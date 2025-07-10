<?php
// admin/manage_products.php
// This file assumes $conn, $message, and $categories are available from admin_dashboard.php

// --- PRODUCT MANAGEMENT LOGIC ---

// Add new product
if (isset($_POST['add_product'])) {
    $nombre = htmlspecialchars($_POST['nombre']);
    $precio = floatval($_POST['precio']);
    $categoria_id = !empty($_POST['categoria_id']) ? intval($_POST['categoria_id']) : null;

    $stmt = $conn->prepare("INSERT INTO productos (nombre, precio, categoria_id) VALUES (?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("sdi", $nombre, $precio, $categoria_id);
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

// Modify existing product
if (isset($_POST['edit_product'])) {
    $id = intval($_POST['product_id']);
    $nombre = htmlspecialchars($_POST['nombre']);
    $precio = floatval($_POST['precio']);
    $categoria_id = !empty($_POST['categoria_id']) ? intval($_POST['categoria_id']) : null;

    $stmt = $conn->prepare("UPDATE productos SET nombre = ?, precio = ?, categoria_id = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("sdis", $nombre, $precio, $categoria_id, $id);
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

// Delete product
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
    header("Location: admin_dashboard.php?tab=products"); // Redirect to clean URL
    exit();
}

// Get all categories for dropdowns (needed for both add and edit forms)
$categories = [];
$result_categories = $conn->query("SELECT id, nombre FROM categorias_productos ORDER BY nombre ASC");
if ($result_categories) {
    while ($row = $result_categories->fetch_assoc()) {
        $categories[] = $row;
    }
}

// Get all products (with category name) for display
$products = [];
$result_products = $conn->query("SELECT p.id, p.nombre, p.precio, c.nombre AS categoria_nombre, p.categoria_id FROM productos p LEFT JOIN categorias_productos c ON p.categoria_id = c.id ORDER BY p.nombre ASC");
if ($result_products) {
    while ($row = $result_products->fetch_assoc()) {
        $products[] = $row;
    }
}
?>

<h2>Gestionar Productos</h2>

<?php
// Form for editing a product
if (isset($_GET['edit_product_id'])):
    $edit_product_id = intval($_GET['edit_product_id']);
    $stmt = $conn->prepare("SELECT id, nombre, precio, categoria_id FROM productos WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $edit_product_id);
        $stmt->execute();
        $product_to_edit = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } else {
        $product_to_edit = null;
    }

    if ($product_to_edit):
?>
        <h3>Modificar Producto (ID: <?php echo $product_to_edit['id']; ?>)</h3>
        <form action="admin_dashboard.php?tab=products" method="POST">
            <input type="hidden" name="product_id" value="<?php echo $product_to_edit['id']; ?>">
            <label for="edit_product_nombre">Nombre:</label>
            <input type="text" name="nombre" value="<?php echo htmlspecialchars($product_to_edit['nombre']); ?>" required>
            <label for="edit_product_precio">Precio:</label>
            <input type="number" step="0.01" name="precio" value="<?php echo htmlspecialchars($product_to_edit['precio']); ?>" required>
            <label for="edit_product_categoria_id">Categoría:</label>
            <select name="categoria_id" id="edit_product_categoria_id">
                <option value="">-- Seleccione una categoría --</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?php echo $category['id']; ?>" <?php echo ($product_to_edit['categoria_id'] == $category['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($category['nombre']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="edit_product">Guardar Cambios</button>
            <a href="admin_dashboard.php?tab=products" class="btn cancel-button">Cancelar</a>
        </form>
<?php
    endif;
endif;
?>

<h3>Añadir Nuevo Producto</h3>
<form action="admin_dashboard.php?tab=products" method="POST">
    <label for="add_product_nombre">Nombre:</label>
    <input type="text" id="add_product_nombre" name="nombre" required>
    <label for="add_product_precio">Precio:</label>
    <input type="number" step="0.01" id="add_product_precio" name="precio" required>
    <label for="add_product_categoria_id">Categoría:</label>
    <select name="categoria_id" id="add_product_categoria_id">
        <option value="">-- Seleccione una categoría --</option>
        <?php foreach ($categories as $category): ?>
            <option value="<?php echo $category['id']; ?>">
                <?php echo htmlspecialchars($category['nombre']); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" name="add_product">Añadir Producto</button>
</form>

<h3>Lista de Productos</h3>
<table>
    <thead><tr><th>ID</th><th>Nombre</th><th>Precio</th><th>Categoría</th><th>Acciones</th></tr></thead>
    <tbody>
        <?php if (empty($products)): ?>
            <tr><td colspan="5" class="no-data">No hay productos registrados.</td></tr>
        <?php else: ?>
            <?php foreach ($products as $product): ?>
                <tr>
                    <td><?php echo $product['id']; ?></td>
                    <td><?php echo htmlspecialchars($product['nombre']); ?></td>
                    <td>$<?php echo number_format($product['precio'], 2); ?></td>
                    <td><?php echo htmlspecialchars($product['categoria_nombre'] ?: 'N/A'); ?></td>
                    <td class="action-buttons">
                        <a href="?tab=products&edit_product_id=<?php echo $product['id']; ?>" class="btn edit">Modificar</a>
                        <a href="?tab=products&delete_product_id=<?php echo $product['id']; ?>" class="btn delete" onclick="return confirm('¿Estás seguro de eliminar este producto?');">Eliminar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>