<?php
// admin/view_orders.php
// This file assumes $conn and $message are available from admin_dashboard.php

// --- ORDER VIEWING LOGIC ---
// Get history of all orders
$all_orders = [];
$result_orders = $conn->query("SELECT p.id, p.fecha, p.total, p.estado, u.email AS cliente_registrado, p.nombre_cliente_calle FROM pedidos p LEFT JOIN usuarios u ON p.cliente_id = u.id ORDER BY p.fecha DESC");
if ($result_orders) {
    while ($row = $result_orders->fetch_assoc()) {
        $all_orders[] = $row;
    }
}
?>

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