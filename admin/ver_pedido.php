<?php
// ver_pedido.php - Facturación y Detalles del Pedido
session_start();
require_once './../config.php';

// 1. VERIFICACIÓN DE SESIÓN DE ADMINISTRADOR
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'administrador') {
    header("Location: ./../index.php");
    exit();
}

// 2. Conexión a la Base de Datos
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// 3. Obtener ID del Pedido
$pedido_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($pedido_id === 0) {
    $conn->close();
    header("Location: admin_dashboard.php?section=orders&message=" . urlencode("Error: ID de pedido no especificado."));
    exit();
}

// 4. Obtener Detalles del Pedido y Cliente
$sql_pedido = "SELECT 
    p.id AS pedido_id, p.fecha_pedido, p.total, p.estado, 
    c.nombre AS cliente_nombre, c.telefono AS cliente_telefono, 
    c.ubicacion AS cliente_ubicacion
FROM pedidos p
LEFT JOIN clientes c ON p.cliente_id = c.id
WHERE p.id = ?";

$stmt = $conn->prepare($sql_pedido);
$stmt->bind_param("i", $pedido_id);
$stmt->execute();
$result_pedido = $stmt->get_result();
$pedido = $result_pedido->fetch_assoc();
$stmt->close();

if (!$pedido) {
    $conn->close();
    header("Location: admin_dashboard.php?section=orders&message=" . urlencode("Error: Pedido no encontrado."));
    exit();
}

// 5. Obtener Items del Pedido (Productos y Modificaciones)
// NOTA: Se ha modificado la consulta para usar el campo 'pp.añadido' 
// en lugar de la tabla 'pedido_producto_modificaciones' que no existe en el esquema.
$sql_items = "SELECT 
    pp.cantidad, pp.precio_unitario, pp.subtotal, 
    prod.nombre AS producto_nombre,
    pp.añadido AS modificaciones_nombres
FROM pedido_productos pp
JOIN productos prod ON pp.producto_id = prod.id
WHERE pp.pedido_id = ?
ORDER BY pp.id ASC";

$stmt_items = $conn->prepare($sql_items);
$stmt_items->bind_param("i", $pedido_id);
$stmt_items->execute();
$result_items = $stmt_items->get_result();
$items = [];
while ($row = $result_items->fetch_assoc()) {
    $items[] = $row;
}
$stmt_items->close();
// Continuar con el resto del código...

$stmt_items = $conn->prepare($sql_items);
$stmt_items->bind_param("i", $pedido_id);
$stmt_items->execute();
$result_items = $stmt_items->get_result();
$items = [];
while ($row = $result_items->fetch_assoc()) {
    $items[] = $row;
}
$stmt_items->close();

// Calcular Subtotal y posibles Impuestos/IGV (para la sección de totales)
$subtotal_pedido = 0;
foreach($items as $item) {
    $subtotal_pedido += $item['subtotal'];
}
// Se asume que la diferencia es un impuesto (ej. IGV/IVA).
$impuesto = $pedido['total'] - $subtotal_pedido;
if ($impuesto < 0) $impuesto = 0; 

$conn->close();

// --- INICIO DE LA VISTA DE FACTURA HTML ---
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factura Pedido #<?php echo $pedido['pedido_id']; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="style.css"> 
    <style>
        @media print {
            .btn, .invoice-container > div:first-child {
                display: none;
            }
            .invoice-container {
                margin: 0;
                box-shadow: none;
                border: none;
                border-radius: 0;
                padding: 0 !important;
            }
            body {
                background-color: #fff;
            }
        }
    </style>
</head>
<body>
    <div class="main-content" style="padding: 0;"> 
        <div class="invoice-container">
            <div style="margin-bottom: 20px; overflow: hidden;">
                <a href="admin_dashboard.php?section=orders" class="btn cancel-button" style="float: left;"><i class="fas fa-arrow-left"></i> Volver a Pedidos</a>
                <button onclick="window.print()" class="btn submit-button" style="float: right;"><i class="fas fa-print"></i> Imprimir Factura</button>
            </div>
            
            <div class="invoice-header">
                <h1>FACTURA</h1>
                <div class="invoice-details">
                    <p><strong>Pedido ID:</strong> #<?php echo $pedido['pedido_id']; ?></p>
                    <p><strong>Fecha:</strong> <?php echo date("d/m/Y", strtotime($pedido['fecha_pedido'])); ?></p>
                    <p><strong>Estado:</strong> 
                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '_', htmlspecialchars($pedido['estado']))); ?>">
                            <?php echo htmlspecialchars($pedido['estado']); ?>
                        </span>
                    </p>
                </div>
            </div>

            <div class="address-section">
                <div class="business-info">
                    <h4>Datos del Emisor (SGPP)</h4>
                    <p>Administrador: <?php echo htmlspecialchars($_SESSION['usuario_email'] ?? 'admin@lezato.com'); ?></p>
                    <p>Dirección: [Tu Dirección Comercial Aquí]</p>
                    <p>Teléfono: [Tu Teléfono Comercial Aquí]</p>
                </div>
                <div class="client-info">
                    <h4>Datos del Cliente</h4>
                    <p><strong>Nombre:</strong> <?php echo htmlspecialchars($pedido['cliente_nombre'] ?? 'N/A'); ?></p>
                    <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($pedido['cliente_telefono'] ?? 'N/A'); ?></p>
                    <p><strong>Ubicación de Envío:</strong> <?php echo htmlspecialchars($pedido['cliente_ubicacion'] ?? 'N/A'); ?></p>
                </div>
            </div>

            <table class="invoice-table">
                <thead>
                    <tr>
                        <th style="width: 5%;">Cant.</th>
                        <th style="width: 55%;">Descripción</th>
                        <th style="width: 15%; text-align: right;">Precio Unit.</th>
                        <th style="width: 25%; text-align: right;">Total Línea</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($items)): ?>
                        <?php foreach ($items as $item): 
                            $modificaciones = htmlspecialchars($item['modificaciones_nombres'] ?? '');
                            $producto_nombre = htmlspecialchars($item['producto_nombre']);
                        ?>
                            <tr>
                                <td><?php echo $item['cantidad']; ?></td>
                                <td>
                                    <strong><?php echo $producto_nombre; ?></strong>
                                    <?php 
                                    $modificaciones = htmlspecialchars($item['modificaciones_nombres'] ?? '');
                                    if (!empty($modificaciones)): 
                                    ?>
                                        <br><small style="color: var(--secondary-color); font-size: 0.8em;">(Extras: <?php echo $modificaciones; ?>)</small>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">$<?php echo number_format($item['precio_unitario'], 2); ?></td>
                                <td style="text-align: right;">$<?php echo number_format($item['subtotal'], 2); ?></td>
                            </tr>

                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4">No hay productos en este pedido.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="invoice-totals">
                <table class="invoice-totals-table">
                    <tr>
                        <td>Subtotal:</td>
                        <td>$<?php echo number_format($subtotal_pedido, 2); ?></td>
                    </tr>
                    <tr>
                        <td>Impuesto/IGV:</td>
                        <td>$<?php echo number_format($impuesto, 2); ?></td>
                    </tr>
                    <tr>
                        <td>TOTAL A PAGAR:</td>
                        <td>$<?php echo number_format($pedido['total'], 2); ?></td>
                    </tr>
                </table>
            </div>

            <div style="margin-top: 50px; text-align: center; font-style: italic; color: var(--secondary-color);">
                <p>Gracias por su pedido. Por favor, póngase en contacto si tiene alguna pregunta.</p>
            </div>
        </div>
    </div>
</body>
</html>