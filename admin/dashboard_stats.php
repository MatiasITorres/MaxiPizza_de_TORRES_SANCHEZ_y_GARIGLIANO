<?php
// admin/dashboard_stats.php
// This file assumes $conn and $message are available from admin_dashboard.php

// --- ALGORITHM TO GET BEST-SELLING PRODUCTS ---
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
                        LIMIT 5"; // Limit to 5 best-selling products

$result_best_sellers = $conn->query($sql_best_sellers);
if ($result_best_sellers) {
    while ($row = $result_best_sellers->fetch_assoc()) {
        $best_selling_products[] = $row;
    }
} else {
    error_log("Error al obtener productos más vendidos: " . $conn->error);
}
?>

<h2>Estadísticas del Tablero</h2>

<h3>Productos Más Vendidos</h3>
<?php if (empty($best_selling_products)): ?>
    <p class="no-data">No hay datos de productos más vendidos aún.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Producto</th>
                <th>Cantidad Vendida</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($best_selling_products as $product): ?>
                <tr>
                    <td><?php echo htmlspecialchars($product['product_name']); ?></td>
                    <td><?php echo htmlspecialchars($product['total_quantity_sold']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>