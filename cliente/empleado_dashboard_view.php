<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Empleado | MaxiPizza</title>
    <link rel="stylesheet" href="./../css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&family=Lobster&display=swap" rel="stylesheet">
    <style>
        <?php include 'style_for_view.css'; ?>
    </style>
</head>
<body>
    <h1>Bienvenido, Empleado <?php echo htmlspecialchars($_SESSION['usuario_email']); ?></h1>
    <p>Este es el panel para el personal de ventas y atención al cliente.</p>

    <div class="status-message" id="js-message"></div>

    <div class="main-content-wrapper">
        <div class="products-section">
            <h2>Productos/Combos Disponibles</h2>
            <?php if (empty($productos_por_categoria)): ?>
                <p class="no-data">No hay productos disponibles.</p>
            <?php else: ?>
                <?php foreach ($productos_por_categoria as $categoria_nombre => $productos): ?>
                    <details class="category-details">
                        <summary class="category-summary"><b><?php echo htmlspecialchars($categoria_nombre); ?></b></summary>
                        <div class="category-content">
                            <?php foreach ($productos as $producto): ?>
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
                        </div>
                    </details>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="order-summary-section">
            <h2>Crear Pedido Nuevo</h2>
            <div class="customer-info-fields">
                <h3>Datos del Cliente</h3>
                <div class="form-group">
                    <label for="existing_cliente_id">Seleccionar Cliente:</label>
                    <select name="existing_cliente_id" id="existing_cliente_id">
                        <option value="0">-- Cliente no registrado --</option>
                        <?php foreach ($clientes_disponibles as $cliente): ?>
                            <option value="<?php echo htmlspecialchars($cliente['id']); ?>">
                                <?php echo htmlspecialchars($cliente['email'] . ' - ' . $cliente['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="new-client-fields" class="client-fields" style="display: none;">
                    <label for="new_client_name">Nombre:</label>
                    <input type="text" id="new_client_name" name="new_client_name" placeholder="Nombre completo">
                    <label for="new_client_phone">Teléfono:</label>
                    <input type="text" id="new_client_phone" name="new_client_phone" placeholder="Teléfono del cliente">
                    <label for="new_client_location">Ubicación/Dirección:</label>
                    <input type="text" id="new_client_location" name="new_client_location" placeholder="Dirección de entrega">
                </div>
            </div>

            <ul id="current-order-list">
                <li id="empty-order-message" style="text-align: center; color: var(--dark-text);">No hay productos en el pedido.</li>
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
                                    <?php if ($pedido['cliente_id'] !== null): ?>
                                        <b>Registrado:</b><br><?php echo htmlspecialchars($pedido['cliente_email'] ?? 'N/A'); ?><br><?php echo htmlspecialchars($pedido['cliente_nombre'] ?? 'N/A'); ?>
                                    <?php else: ?>
                                        <b>A la calle:</b><br><?php echo htmlspecialchars($pedido['nombre_cliente_calle'] ?? 'N/A'); ?><br><?php echo htmlspecialchars($pedido['ubicacion_cliente_calle'] ?? 'N/A'); ?>
                                    <?php endif; ?>
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

    <div class="logout-button-container">
        <a href="./../index.php?logout=true" class="logout-button">Cerrar Sesión</a>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const orderElements = {
            currentOrderList: document.getElementById('current-order-list'),
            currentOrderTotalDisplay: document.getElementById('current-order-total'),
            emptyOrderMessage: document.getElementById('empty-order-message'),
            placeOrderBtn: document.getElementById('place-order-btn'),
            clearOrderBtn: document.getElementById('clear-order-btn'),
            statusMessageDiv: document.getElementById('js-message'),
            existingClientIdSelect: document.getElementById('existing_cliente_id'),
            newClientFieldsDiv: document.getElementById('new-client-fields'),
            newClientNameInput: document.getElementById('new_client_name'),
            newClientPhoneInput: document.getElementById('new_client_phone'),
            newClientLocationInput: document.getElementById('new_client_location')
        };

        let currentOrder = [];

        function showStatusMessage(message, type) {
            orderElements.statusMessageDiv.textContent = message;
            orderElements.statusMessageDiv.className = `status-message ${type}`;
            orderElements.statusMessageDiv.style.display = 'block';
            setTimeout(() => {
                orderElements.statusMessageDiv.style.display = 'none';
            }, 5000);
        }

        function updateOrderDisplay() {
            orderElements.currentOrderList.innerHTML = '';
            let totalAmount = 0;
            if (currentOrder.length === 0) {
                orderElements.currentOrderList.appendChild(orderElements.emptyOrderMessage);
                orderElements.emptyOrderMessage.style.display = 'block';
                orderElements.placeOrderBtn.disabled = true;
                orderElements.clearOrderBtn.disabled = true;
            } else {
                orderElements.emptyOrderMessage.style.display = 'none';
                orderElements.placeOrderBtn.disabled = false;
                orderElements.clearOrderBtn.disabled = false;
                currentOrder.forEach((item, index) => {
                    const listItem = document.createElement('li');
                    const itemSubtotal = item.quantity * item.price;
                    totalAmount += itemSubtotal;
                    listItem.innerHTML = `
                        <span>${item.name} (x${item.quantity})</span>
                        <span>$${itemSubtotal.toFixed(2)}</span>
                        <button class="remove-item-btn" data-index="${index}">X</button>
                    `;
                    orderElements.currentOrderList.appendChild(listItem);
                });
            }
            orderElements.currentOrderTotalDisplay.textContent = `Total: $${totalAmount.toFixed(2)}`;
        }

        orderElements.existingClientIdSelect.addEventListener('change', function() {
            if (this.value === '0') {
                orderElements.newClientFieldsDiv.style.display = 'block';
            } else {
                orderElements.newClientFieldsDiv.style.display = 'none';
                orderElements.newClientNameInput.value = '';
                orderElements.newClientPhoneInput.value = '';
                orderElements.newClientLocationInput.value = '';
            }
        });

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

        orderElements.currentOrderList.addEventListener('click', function(event) {
            if (event.target.classList.contains('remove-item-btn')) {
                const indexToRemove = parseInt(event.target.dataset.index);
                currentOrder.splice(indexToRemove, 1);
                updateOrderDisplay();
            }
        });

        orderElements.placeOrderBtn.addEventListener('click', async function() {
            const clienteId = parseInt(orderElements.existingClientIdSelect.value);

            if (currentOrder.length === 0) {
                showStatusMessage('No hay productos para el pedido.', 'error');
                return;
            }

            const params = new URLSearchParams();
            params.append('productos_json', JSON.stringify(currentOrder.map(item => ({ producto_id: item.id, cantidad: item.quantity }))));
            params.append('place_order', '1');

            if (clienteId > 0) {
                params.append('cliente_id', clienteId);
            } else {
                if (!orderElements.newClientNameInput.value.trim() || !orderElements.newClientPhoneInput.value.trim() || !orderElements.newClientLocationInput.value.trim()) {
                    showStatusMessage('Por favor, completa todos los campos del cliente no registrado.', 'error');
                    return;
                }
                params.append('new_client_name', orderElements.newClientNameInput.value.trim());
                params.append('new_client_phone', orderElements.newClientPhoneInput.value.trim());
                params.append('new_client_location', orderElements.newClientLocationInput.value.trim());
            }

            try {
                const response = await fetch('empleado_dashboard_controller.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params
                });
                const data = await response.json();
                showStatusMessage(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    currentOrder = [];
                    updateOrderDisplay();
                    orderElements.existingClientIdSelect.value = '0';
                    orderElements.newClientFieldsDiv.style.display = 'none';
                    orderElements.newClientNameInput.value = '';
                    orderElements.newClientPhoneInput.value = '';
                    orderElements.newClientLocationInput.value = '';
                    setTimeout(() => location.reload(), 1500);
                }
            } catch (error) {
                console.error('Error:', error);
                showStatusMessage('Error de conexión al guardar el pedido.', 'error');
            }
        });

        orderElements.clearOrderBtn.addEventListener('click', () => {
             if (confirm('¿Limpiar el pedido actual?')) {
                currentOrder = [];
                updateOrderDisplay();
             }
        });

        document.querySelectorAll('.update-status-btn').forEach(button => {
            button.addEventListener('click', async function() {
                const orderId = this.dataset.orderId;
                const newStatus = document.querySelector(`.status-selector[data-order-id="${orderId}"]`).value;
                if (!newStatus) return;

                if (confirm(`¿Cambiar estado del Pedido #${orderId} a "${newStatus}"?`)) {
                    const params = new URLSearchParams();
                    params.append('update_order_status', '1');
                    params.append('order_id', orderId);
                    params.append('new_status', newStatus);

                    try {
                        const response = await fetch('empleado_dashboard_controller.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: params
                        });
                        const data = await response.json();
                        showStatusMessage(data.message, data.success ? 'success' : 'error');
                        if (data.success) {
                            setTimeout(() => location.reload(), 1500);
                        }
                    } catch (error) {
                        showStatusMessage('Error de conexión al actualizar el estado.', 'error');
                    }
                }
            });
        });

        updateOrderDisplay();
    });
    </script>
</body>
</html>