<?php
session_start();
require_once __DIR__ . '/../config.php'; // Ruta corregida y robusta

// Validar sesión y rol
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'cliente') {
    header("Location: ./../index.php");
    exit();
}

$cliente_id = $_SESSION['usuario_id'];

// Procesar nuevo pedido
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hacer_pedido'])) {
    $cantidades = $_POST['cantidades'] ?? [];
    $productos_pedidos = array_filter($cantidades, fn($q) => $q > 0);

    if (count($productos_pedidos) > 0) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO pedidos (cliente_id, total, estado) VALUES (?, 0, 'pendiente')");
            $stmt->bind_param("i", $cliente_id);
            $stmt->execute();
            $pedido_id = $conn->insert_id;
            $stmt->close();

            $total = 0;
            foreach ($productos_pedidos as $producto_id => $cantidad) {
                $stmt = $conn->prepare("SELECT precio FROM productos WHERE id = ?");
                $stmt->bind_param("i", $producto_id);
                $stmt->execute();
                $stmt->bind_result($precio_unitario);
                $stmt->fetch();
                $stmt->close();

                $subtotal = $precio_unitario * $cantidad;
                $total += $subtotal;

                $stmt = $conn->prepare("INSERT INTO pedido_productos (pedido_id, producto_id, cantidad, precio_unitario, subtotal, estado) VALUES (?, ?, ?, ?, ?, 'pendiente')");
                $stmt->bind_param("iiidd", $pedido_id, $producto_id, $cantidad, $precio_unitario, $subtotal);
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $conn->prepare("UPDATE pedidos SET total = ? WHERE id = ?");
            $stmt->bind_param("di", $total, $pedido_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            echo "<p style='color: green;'>✅ Pedido realizado con éxito.</p>";
        } catch (Exception $e) {
            $conn->rollback();
            echo "<p style='color: red;'>❌ Error al procesar el pedido: " . $e->getMessage() . "</p>";
        }
    } else {
        echo "<p style='color: orange;'>⚠️ Debes seleccionar al menos un producto.</p>";
    }
}

// Cargar productos
$productos_disponibles = [];
$prod_sql = "SELECT id, nombre, precio FROM productos ORDER BY nombre ASC";
$prod_result = $conn->query($prod_sql);
if ($prod_result && $prod_result->num_rows > 0) {
    while ($row = $prod_result->fetch_assoc()) {
        $productos_disponibles[] = $row;
    }
}

// Traer pedidos del cliente
$sql = "SELECT 
            p.id AS pedido_id,
            p.fecha,
            pp.cantidad,
            pr.nombre AS producto,
            pp.precio_unitario,
            pp.subtotal,
            pp.estado
        FROM pedidos p
        JOIN pedido_productos pp ON p.id = pp.pedido_id
        JOIN productos pr ON pp.producto_id = pr.id
        WHERE p.cliente_id = ?
        ORDER BY p.fecha DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $cliente_id);
$stmt->execute();
$result = $stmt->get_result();

$en_curso = [];
$finalizados = [];

while ($row = $result->fetch_assoc()) {
    if (in_array($row['estado'], ['pendiente', 'en_preparacion'])) {
        $en_curso[] = $row;
    } else {
        $finalizados[] = $row;
    }
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <title>Mis Pedidos - MaxiPizza</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background-color: #f9f9f9; }
        h2 { color: #333; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 40px; }
        th, td { padding: 12px; border: 1px solid #ccc; text-align: center; }
        th { background-color: #ff3366; color: white; }
        tr:nth-child(even) { background-color: #f2f2f2; }
        .estado-pendiente { color: orange; font-weight: bold; }
        .estado-en_preparacion { color: darkorange; font-weight: bold; }
        .estado-listo { color: green; font-weight: bold; }
        .estado-entregado { color: blue; font-weight: bold; }
        .estado-cancelado { color: red; font-weight: bold; }
    </style>
</head>
<body>
    <h2>🛒 Hacer un nuevo pedido</h2>
    <form method="POST">
        <table>
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>Precio</th>
                    <th>Cantidad</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($productos_disponibles as $producto): ?>
                    <tr>
                        <td><?= htmlspecialchars($producto['nombre']) ?></td>
                        <td>$<?= number_format($producto['precio'], 2) ?></td>
                        <td><input type="number" name="cantidades[<?= $producto['id'] ?>]" min="0" value="0" style="width: 60px;"></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <button type="submit" name="hacer_pedido">Confirmar Pedido</button>
    </form>

    <h2>📦 Pedidos en curso</h2>
    <?php if (count($en_curso) === 0): ?>
        <p>No hay pedidos en curso.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Pedido</th>
                    <th>Fecha</th>
                    <th>Producto</th>
                    <th>Cantidad</th>
                    <th>Subtotal</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($en_curso as $pedido): ?>
                    <tr>
                        <td><?= htmlspecialchars($pedido['pedido_id']) ?></td>
                        <td><?= htmlspecialchars($pedido['fecha']) ?></td>
                        <td><?= htmlspecialchars($pedido['producto']) ?></td>
                        <td><?= htmlspecialchars($pedido['cantidad']) ?></td>
                        <td>$<?= htmlspecialchars(number_format($pedido['subtotal'], 2)) ?></td>
                        <td class="estado-<?= htmlspecialchars($pedido['estado']) ?>"><?= ucfirst(str_replace('_', ' ', $pedido['estado'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2>✅ Pedidos finalizados</h2>
    <?php if (count($finalizados) === 0): ?>
        <p>No hay pedidos finalizados aún.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Pedido</th>
                    <th>Fecha</th>
                    <th>Producto</th>
                    <th>Cantidad</th>
                    <th>Subtotal</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($finalizados as $pedido): ?>
                    <tr>
                        <td><?= htmlspecialchars($pedido['pedido_id']) ?></td>
                        <td><?= htmlspecialchars($pedido['fecha']) ?></td>
                        <td><?= htmlspecialchars($pedido['producto']) ?></td>
                        <td><?= htmlspecialchars($pedido['cantidad']) ?></td>
                        <td>$<?= htmlspecialchars(number_format($pedido['subtotal'], 2)) ?></td>
                        <td class="estado-<?= htmlspecialchars($pedido['estado']) ?>"><?= ucfirst(str_replace('_', ' ', $pedido['estado'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>
