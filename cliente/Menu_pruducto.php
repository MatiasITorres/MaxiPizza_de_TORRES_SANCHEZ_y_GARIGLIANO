<?php
// Menu_producto.php - CÓDIGO MEJORADO Y ROBUSTO
// NOTA: Asume que el archivo config.php existe en el directorio padre
require_once __DIR__ . '/../config.php'; 

$conn_pdo = null;
$connection_status = "";
try {
    // Conexión usando PDO (PHP Data Objects)
    $conn_pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $conn_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $connection_status = "✅ Conexión a la base de datos exitosa.";
} catch (PDOException $e) {
    $connection_status = "❌ Error de conexión: " . $e->getMessage() . ". Revise la configuración de DB.";
}

// 2. LÓGICA DE EXTRACCIÓN DE DATOS (MENÚ COMPLETO)
$menu_data = [];
$error_fetching = "";

if ($conn_pdo) {
    try {
        // Consulta SQL para obtener todos los productos junto con su categoría y descripción
        $sql = "
            SELECT 
                c.nombre AS categoria, 
                p.nombre AS producto, 
                p.descripcion, 
                p.precio 
            FROM productos p
            INNER JOIN categorias_productos c ON p.categoria_id = c.id
            WHERE p.stock > 0
            ORDER BY c.nombre, p.nombre
        ";
        $stmt = $conn_pdo->query($sql);
        $menu_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error_fetching = "Error al obtener el menú: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menú de Productos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1>Menú Completo de Productos</h1>
        <p class="mb-4"><?= htmlspecialchars($connection_status) ?></p>

        <?php if ($error_fetching): ?>
            <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error_fetching) ?></div>
        <?php endif; ?>

        <?php if (!empty($menu_data)): ?>
            <div class="card">
                <div class="card-body">
                    <table class="table table-striped table-hover">
                        <thead class="table-primary">
                            <tr>
                                <th>Categoría</th>
                                <th>Producto</th>
                                <th>Descripción</th>
                                <th>Precio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $current_category = '';
                            foreach ($menu_data as $item): 
                                // Separador visual de categorías
                                if ($item['categoria'] !== $current_category):
                                    $current_category = $item['categoria'];
                                    ?>
                                    <tr><td colspan='4' class='table-dark'><strong><?= htmlspecialchars($current_category) ?></strong></td></tr>
                                    <?php
                                endif;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($item['categoria']) ?></td>
                                <td><?= htmlspecialchars($item['producto']) ?></td>
                                <td><?= htmlspecialchars($item['descripcion']) ?></td>
                                <td>$<?= number_format($item['precio'], 2, ',', '.') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning" role="alert">
                No se encontraron productos disponibles en el menú.
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>