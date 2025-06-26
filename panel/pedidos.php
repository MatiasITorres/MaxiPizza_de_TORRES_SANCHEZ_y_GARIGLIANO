<?php
session_start();

// Conexión directa si config.php no está funcionando
$conexion = new mysqli("localhost", "root", "", "maxipizza");
if ($conexion->connect_error) {
    die("Error de conexión: " . $conexion->connect_error);
}

if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'panel') {
    header("Location: ./../index.php");
    exit();
}

// Consulta todos los pedidos
$sql = "SELECT 
            p.id,
            p.fecha,
            p.total,
            p.estado,
            COALESCE(c.nombre, p.nombre_cliente_calle) AS cliente,
            COALESCE(c.direccion, p.ubicacion_cliente_calle) AS direccion,
            COALESCE(c.telefono, p.telefono_cliente_calle) AS telefono
        FROM pedidos p
        LEFT JOIN clientes c ON p.cliente_id = c.id
        ORDER BY p.fecha DESC";

$resultado = $conexion->query($sql);

$pedidos_listos = [];
$pedidos_no_listos = [];

if ($resultado && $resultado->num_rows > 0) {
    while ($fila = $resultado->fetch_assoc()) {
        if (in_array($fila['estado'], ['listo', 'entregado'])) {
            $pedidos_listos[] = $fila;
        } else {
            $pedidos_no_listos[] = $fila;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Pedidos MaxiPizza</title>
    <link rel="stylesheet" href="./../css/style.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            display: flex;
            gap: 20px;
            background-color: #f8f9fa;
        }
        .columna {
            flex: 1;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            background: #fff;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
            border-radius: 8px;
            overflow: hidden;
        }
        th, td {
            padding: 10px;
            border-bottom: 1px solid #ccc;
            text-align: left;
        }
        th {
            background: #e9ecef;
        }
        h2 {
            text-align: center;
            color: #343a40;
        }
        .estado-pendiente { background-color: #fff3cd; }
        .estado-en_preparacion { background-color: #cce5ff; }
        .estado-listo { background-color: #d4edda; }
        .estado-entregado { background-color: #c3e6cb; }
        .estado-cancelado { background-color: #f8d7da; }
    </style>
</head>
<body>

<div class="columna">
    <h2>Pedidos en curso</h2>
    <?php if (count($pedidos_no_listos) > 0): ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Cliente</th>
                <th>Teléfono</th>
                <th>Dirección</th>
                <th>Fecha</th>
                <th>Total</th>
                <th>Estado</th>
            </tr>
            <?php foreach ($pedidos_no_listos as $p): ?>
                <tr class="estado-<?php echo $p['estado']; ?>">
                    <td><?php echo $p['id']; ?></td>
                    <td><?php echo htmlspecialchars($p['cliente']); ?></td>
                    <td><?php echo htmlspecialchars($p['telefono']); ?></td>
                    <td><?php echo htmlspecialchars($p['direccion']); ?></td>
                    <td><?php echo $p['fecha']; ?></td>
                    <td>$<?php echo number_format($p['total'], 2); ?></td>
                    <td><strong><?php echo ucfirst(str_replace('_', ' ', $p['estado'])); ?></strong></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>No hay pedidos pendientes.</p>
    <?php endif; ?>
</div>

<div class="columna">
    <h2>Pedidos Listos</h2>
    <?php if (count($pedidos_listos) > 0): ?>
        <table>
            <tr>
                <th>ID</th>
                <th>Cliente</th>
                <th>Teléfono</th>
                <th>Dirección</th>
                <th>Fecha</th>
                <th>Total</th>
                <th>Estado</th>
            </tr>
            <?php foreach ($pedidos_listos as $p): ?>
                <tr class="estado-<?php echo $p['estado']; ?>">
                    <td><?php echo $p['id']; ?></td>
                    <td><?php echo htmlspecialchars($p['cliente']); ?></td>
                    <td><?php echo htmlspecialchars($p['telefono']); ?></td>
                    <td><?php echo htmlspecialchars($p['direccion']); ?></td>
                    <td><?php echo $p['fecha']; ?></td>
                    <td>$<?php echo number_format($p['total'], 2); ?></td>
                    <td><strong><?php echo ucfirst(str_replace('_', ' ', $p['estado'])); ?></strong></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>No hay pedidos listos.</p>
    <?php endif; ?>
</div>

</body>
</html>
<?php $conexion->close(); ?>