<?php
session_start();
// NOTA: Asegúrate de que la ruta a tu archivo de conexión sea correcta.
require_once './../config.php'; 

// Redirigir si el usuario no está logeado o no es un empleado
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'empleado') {
    header("Location: ./../index.php");
    exit();
}

// --- SECCIÓN PARA MOSTRAR PRODUCTOS/COMBOS DISPONIBLES (existente) ---
$productos_disponibles = [];
try {
    $sql_productos = "SELECT id, nombre, precio FROM productos ORDER BY nombre ASC";
    $result_productos = $conn->query($sql_productos);

    if ($result_productos && $result_productos->num_rows > 0) {
        while ($row = $result_productos->fetch_assoc()) {
            $productos_disponibles[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error al obtener productos: " . $e->getMessage());
    $productos_disponibles = [];
}

// --- SECCIÓN PARA OBTENER CLIENTES REGISTRADOS (existente) ---
$clientes_disponibles = [];
try {
    // La lógica es correcta: los clientes se obtienen de la tabla 'usuarios'
    $sql_clientes = "SELECT id, email FROM usuarios WHERE rol = 'cliente' ORDER BY email ASC";
    $result_clientes = $conn->query($sql_clientes);
    if ($result_clientes) {
        while ($row = $result_clientes->fetch_assoc()) {
            $clientes_disponibles[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error al obtener clientes: " . $e->getMessage());
    $clientes_disponibles = [];
}

// --- OBTENER PEDIDOS PARA GESTIONAR ---
$pedidos_a_gestionar = [];
try {
    $sql_pedidos = "SELECT p.id, p.cliente_id, u.email AS cliente_email,
                           p.nombre_cliente_calle, p.ubicacion_cliente_calle, p.telefono_cliente_calle,
                           p.fecha, p.total, p.estado
                     FROM pedidos p
                     LEFT JOIN usuarios u ON p.cliente_id = u.id
                     ORDER BY p.fecha DESC"; 

    $result_pedidos = $conn->query($sql_pedidos);

    if ($result_pedidos && $result_pedidos->num_rows > 0) {
        while ($row = $result_pedidos->fetch_assoc()) {
            $pedidos_a_gestionar[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error al obtener pedidos para gestión: " . $e->getMessage());
    $pedidos_a_gestionar = [];
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Empleado | MaxiPizza</title>
    <link rel="stylesheet" href="./../css/style.css">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f4f4; color: #333; line-height: 1.6; }
        h1, h2, h3 { color: #555; margin-bottom: 10px; }
        h1 { border-bottom: 2px solid #ccc; padding-bottom: 10px; }
        a { color: #007bff; text-decoration: none; padding: 8px 15px; background-color: #e9ecef; border-radius: 5px; display: inline-block; margin-top: 20px; }
        a:hover { text-decoration: none; background-color: #dee2e6; }
        .main-content-wrapper { display: flex; gap: 25px; margin-top: 20px; flex-wrap: wrap; }
        .products-section, .order-summary-section, .manage-orders-section { background-color: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 15px; box-shadow: 0 4px 8px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .products-section { flex: 2; }
        .order-summary-section { flex: 1; min-width: 300px; }
        .manage-orders-section { flex-basis: 100%; margin-top: 30px; }
        .product-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px dotted #eee; }
        .product-info { flex-grow: 1; margin-right: 15px; }
        .product-actions input[type="number"] { width: 60px; padding: 5px; border: 1px solid #ccc; border-radius: 4px; text-align: center; }
        .product-actions button { background-color: #28a745; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; }
        .customer-info-fields { margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #eee; }
        .customer-info-fields label { display: block; margin-bottom: 5px; font-weight: bold; }
        .customer-info-fields input[type="text"] { width: calc(100% - 12px); padding: 8px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 4px; }
        #current-order-list { list-style: none; padding: 0; margin-top: 15px; }
        #current-order-total { font-weight: bold; text-align: right; margin-top: 15px; padding-top: 10px; border-top: 2px solid #ccc; font-size: 1.2em; color: #007bff; }
        .order-buttons button { background-color: #007bff; color: white; border: none; padding: 10px 15px; border-radius: 5px; cursor: pointer; margin-left: 10px; }
        .order-buttons button.clear { background-color: #dc3545; }
        .status-message { padding: 10px; border-radius: 5px; margin-bottom: 20px; text-align: center; font-weight: bold; display: none; }
        .status-message.success { background-color: #d4edda; color: #155724; }
        .status-message.error { background-color: #f8d7da; color: #721c24; }
        #existing_cliente_id { width: 100%; padding: 8px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 4px; }
        .order-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .order-table th, .order-table td { border: 1px solid #ddd; padding: 8px; text-align: left; vertical-align: top; }
        .order-table th { background-color: #f2f2f2; }
        .order-actions button, .order-actions select { padding: 6px 10px; border-radius: 4px; border: 1px solid #ccc; cursor: pointer; margin-right: 5px; }
        .status-badge { display: inline-block; padding: 4px 8px; border-radius: 5px; font-size: 0.8em; font-weight: bold; color: white; text-transform: uppercase; }
        .status-pendiente { background-color: #6c757d; }
        .status-en_preparacion { background-color: #007bff; }
        .status-listo { background-color: #28a745; }
        .status-entregado { background-color: #17a2b8; }
        .status-cancelado { background-color: #dc3545; }
    </style>
</head>
<body>
    <h1>Bienvenido, Empleado <?php echo htmlspecialchars($_SESSION['usuario_email']); ?></h1>
    <p>Este es el panel para el personal de ventas y atención al cliente.</p>

    <div class="status-message" id="php-message"></div>

    <div class="main-content-wrapper">
        <div class="products-section">
            <h2>Productos/Combos Disponibles</h2>
            <?php if (!empty($productos_disponibles)): ?>
                <?php foreach ($productos_disponibles as $producto): ?>
                    <div class="product-item">
                        <div class="product-info">
                            <span class="product-name" data-id="<?php echo htmlspecialchars($producto['id']); ?>"><?php echo htmlspecialchars($producto['nombre']); ?></span>
                            <br><small>Precio: $<span class="product-price-value"><?php echo htmlspecialchars($producto['precio']); ?></span></small>
                        </div>
                        <div class="product-actions">
                            <input type="number" class="quantity-input" value="1" min="1">
                            <button class="add-to-order-btn">Añadir al Pedido</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No hay productos disponibles.</p>
            <?php endif; ?>
        </div>

        <div class="order-summary-section">
            <h2>Crear Pedido Nuevo</h2>
            <div class="customer-info-fields">
                <h3>Datos del Cliente</h3>
                <div class="form-group">
                    <label for="existing_cliente_id">Seleccionar Cliente Registrado (Opcional):</label>
                    <select name="existing_cliente_id" id="existing_cliente_id">
                        <option value="0">-- Cliente no registrado / A la calle --</option>
                        <?php foreach ($clientes_disponibles as $cliente): ?>
                            <option value="<?php echo htmlspecialchars($cliente['id']); ?>">
                                <?php echo htmlspecialchars($cliente['email']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="customer-name">Nombre (si es "a la calle"):</label>
                    <input type="text" id="customer-name" placeholder="Nombre completo del cliente">
                </div>
                <div>
                    <label for="customer-location">Ubicación/Dirección (si es "a la calle"):</label>
                    <input type="text" id="customer-location" placeholder="Dirección de entrega">
                </div>
                <div>
                    <label for="customer-phone">Teléfono (si es "a la calle"):</label>
                    <input type="text" id="customer-phone" placeholder="Número de teléfono">
                </div>
            </div>

            <ul id="current-order-list">
                <li id="empty-order-message" style="text-align: center; color: #777;">No hay productos en el pedido.</li>
            </ul>
            <div id="current-order-total">Total: $0,00</div>
            <div class="order-buttons">
                <button class="clear" id="clear-order-btn">Limpiar Pedido</button>
                <button id="place-order-btn">Realizar Pedido</button>
            </div>
        </div>

        <div class="manage-orders-section">
            <h2>Gestionar Pedidos Existentes</h2>
            <?php if (!empty($pedidos_a_gestionar)): ?>
                <table class="order-table">
                    <thead>
                        <tr>
                            <th>ID Pedido</th>
                            <th>Cliente</th>
                            <th>Fecha</th>
                            <th>Total</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pedidos_a_gestionar as $pedido): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($pedido['id']); ?></td>
                                <td>
                                    <?php
                                    if ($pedido['cliente_id'] !== null && $pedido['cliente_email']) {
                                        echo '<b>Registrado:</b><br>' . htmlspecialchars($pedido['cliente_email']);
                                    } else {
                                        echo '<b>A la calle:</b><br>' . htmlspecialchars($pedido['nombre_cliente_calle'] ?: 'N/A') .
                                             '<br><small>Dir: ' . htmlspecialchars($pedido['ubicacion_cliente_calle'] ?: 'N/A') . '</small>' .
                                             '<br><small>Tel: ' . htmlspecialchars($pedido['telefono_cliente_calle'] ?: 'N/A') . '</small>';
                                    }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($pedido['fecha']))); ?></td>
                                <td>$<?php echo htmlspecialchars(number_format($pedido['total'], 2, ',', '.')); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo htmlspecialchars($pedido['estado']); ?>">
                                        <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($pedido['estado']))); ?>
                                    </span>
                                </td>
                                <td class="order-actions">
                                    <select class="status-selector" data-order-id="<?php echo htmlspecialchars($pedido['id']); ?>">
                                        <option value="pendiente" <?php echo ($pedido['estado'] == 'pendiente') ? 'selected' : ''; ?>>Pendiente</option>
                                        <option value="en_preparacion" <?php echo ($pedido['estado'] == 'en_preparacion') ? 'selected' : ''; ?>>En Preparación</option>
                                        <option value="listo" <?php echo ($pedido['estado'] == 'listo') ? 'selected' : ''; ?>>Listo</option>
                                        <option value="entregado" <?php echo ($pedido['estado'] == 'entregado') ? 'selected' : ''; ?>>Entregado</option>
                                        <option value="cancelado" <?php echo ($pedido['estado'] == 'cancelado') ? 'selected' : ''; ?>>Cancelado</option>
                                    </select>
                                    <button class="update-status-btn" data-order-id="<?php echo htmlspecialchars($pedido['id']); ?>">Actualizar</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No hay pedidos registrados.</p>
            <?php endif; ?>
        </div>
    </div>

    <p><a href="index.php?logout=true">Cerrar Sesión</a></p>

    <script>
    // Tu código JavaScript está bien y no necesita cambios.
    // Se deja aquí para mantener el archivo completo.
    document.addEventListener('DOMContentLoaded', function() {
        const currentOrderList = document.getElementById('current-order-list');
        const currentOrderTotalDisplay = document.getElementById('current-order-total');
        const emptyOrderMessage = document.getElementById('empty-order-message');
        const placeOrderBtn = document.getElementById('place-order-btn');
        const clearOrderBtn = document.getElementById('clear-order-btn');
        const statusMessageDiv = document.getElementById('php-message');

        const customerNameInput = document.getElementById('customer-name');
        const customerLocationInput = document.getElementById('customer-location');
        const customerPhoneInput = document.getElementById('customer-phone');
        const existingClientIdSelect = document.getElementById('existing_cliente_id');

        let currentOrder = [];

        function showStatusMessage(message, type) {
            statusMessageDiv.textContent = message;
            statusMessageDiv.className = 'status-message ' + type;
            statusMessageDiv.style.display = 'block';
            setTimeout(() => {
                statusMessageDiv.style.display = 'none';
            }, 5000);
        }

        function updateOrderDisplay() {
            currentOrderList.innerHTML = '';
            let totalAmount = 0;

            if (currentOrder.length === 0) {
                currentOrderList.appendChild(emptyOrderMessage);
                emptyOrderMessage.style.display = 'block';
                placeOrderBtn.disabled = true;
                clearOrderBtn.disabled = true;
            } else {
                emptyOrderMessage.style.display = 'none';
                placeOrderBtn.disabled = false;
                clearOrderBtn.disabled = false;

                currentOrder.forEach((item, index) => {
                    const listItem = document.createElement('li');
                    const itemSubtotal = item.quantity * item.price;
                    totalAmount += itemSubtotal;
                    listItem.innerHTML = `
                        <span>${item.name} (x${item.quantity})</span>
                        <span>$${itemSubtotal.toFixed(2)}</span>
                        <button class="remove-item-btn" data-index="${index}" style="background: #dc3545; color: white; border: none; cursor: pointer; border-radius: 50%; width: 20px; height: 20px;">X</button>
                    `;
                    currentOrderList.appendChild(listItem);
                });
            }
            currentOrderTotalDisplay.textContent = `Total: $${totalAmount.toFixed(2)}`;
        }

        document.querySelectorAll('.add-to-order-btn').forEach(button => {
            button.addEventListener('click', function() {
                const productItem = this.closest('.product-item');
                const productId = productItem.querySelector('.product-name').dataset.id;
                const productName = productItem.querySelector('.product-name').textContent;
                const productPrice = parseFloat(productItem.querySelector('.product-price-value').textContent);
                const quantity = parseInt(productItem.querySelector('.quantity-input').value);

                if (isNaN(quantity) || quantity <= 0) { return; }

                const existingItem = currentOrder.find(item => item.id === productId);
                if (existingItem) {
                    existingItem.quantity += quantity;
                } else {
                    currentOrder.push({ id: productId, name: productName, price: productPrice, quantity: quantity });
                }
                updateOrderDisplay();
            });
        });

        currentOrderList.addEventListener('click', function(event) {
            if (event.target.classList.contains('remove-item-btn')) {
                currentOrder.splice(parseInt(event.target.dataset.index), 1);
                updateOrderDisplay();
            }
        });

        placeOrderBtn.addEventListener('click', function() {
            const clienteId = parseInt(existingClientIdSelect.value);
            let customerData = {
                cliente_id: clienteId,
                productos_json: JSON.stringify(currentOrder.map(item => ({ producto_id: item.id, cantidad: item.quantity }))),
                place_order: '1'
            };

            if (clienteId === 0) {
                customerData.nombre_cliente_calle = customerNameInput.value.trim();
                customerData.ubicacion_cliente_calle = customerLocationInput.value.trim();
                customerData.telefono_cliente_calle = customerPhoneInput.value.trim();
                if (!customerData.nombre_cliente_calle || !customerData.ubicacion_cliente_calle) {
                    showStatusMessage('Por favor, complete Nombre y Ubicación para el cliente "a la calle".', 'error');
                    return;
                }
            }

            fetch('guardar_pedido.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(customerData)
            })
            .then(response => response.json())
            .then(data => {
                showStatusMessage(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    currentOrder = [];
                    updateOrderDisplay();
                    customerNameInput.value = '';
                    customerLocationInput.value = '';
                    customerPhoneInput.value = '';
                    existingClientIdSelect.value = '0';
                    setTimeout(() => location.reload(), 1500);
                }
            })
            .catch(error => showStatusMessage('Error de conexión al guardar el pedido.', 'error'));
        });
        
        clearOrderBtn.addEventListener('click', () => {
             if (confirm('¿Limpiar el pedido actual?')) {
                currentOrder = [];
                updateOrderDisplay();
             }
        });

        document.querySelectorAll('.update-status-btn').forEach(button => {
            button.addEventListener('click', function() {
                const orderId = this.dataset.orderId;
                const newStatus = document.querySelector(`.status-selector[data-order-id="${orderId}"]`).value;
                if (!newStatus) return;

                if (confirm(`¿Cambiar estado del Pedido #${orderId} a "${newStatus}"?`)) {
                    fetch('update_order_status.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ order_id: orderId, new_status: newStatus })
                    })
                    .then(response => response.json())
                    .then(data => {
                        showStatusMessage(data.message, data.success ? 'success' : 'error');
                        if (data.success) {
                            setTimeout(() => location.reload(), 1500);
                        }
                    })
                    .catch(error => showStatusMessage('Error de conexión al actualizar el estado.', 'error'));
                }
            });
        });
        
        updateOrderDisplay(); // Initial call
    });
    </script>
</body>
</html>