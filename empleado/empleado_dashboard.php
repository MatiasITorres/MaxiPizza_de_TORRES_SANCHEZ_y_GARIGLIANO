<?php
// empleado_dashboard.php - CÓDIGO CONSOLIDADO (Creación de Pedidos y Gestión de Estado)
// Debe ubicarse en la carpeta /empleado/

// ----------------------------------------------------
// 0. DEPENDENCIAS Y CONFIGURACIÓN INICIAL
// ----------------------------------------------------
session_start();
require_once './../config.php'; // Requiere la configuración de la DB

// Conexión a la Base de Datos
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Redirección si el rol no es 'empleado'
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'empleado') {
    header("Location: ./../index.php");
    exit();
}

// LÓGICA DE CONFIGURACIÓN DE EMPRESA (JSON)
$settings = [];
$config_file_path = __DIR__ . '/../admin/config_data.json'; 
if (file_exists($config_file_path)) {
    $settings = json_decode(file_get_contents($config_file_path), true);
}

$company_name = $settings['company_name'] ?? 'SGPP Default';
$logo_path = $settings['logo_path'] ?? './../img/SGPP.jpg';
$theme_mode = $settings['theme_mode'] ?? 'light';
$payment_options = explode(',', $settings['other_payment_options'] ?? 'Efectivo');

// ----------------------------------------------------
// 1. MANEJADOR DE ACCIONES (AJAX)
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    $action = $_POST['action'];

    switch ($action) {
        case 'save_order':
            $conn->autocommit(false); // Iniciar transacción
            try {
                // Validación y limpieza de datos
                if (empty($_POST['productos_json']) || !($productos_pedido = json_decode($_POST['productos_json'], true)) || empty($productos_pedido)) {
                    throw new Exception('No hay productos en el pedido.');
                }
                
                $client_name = trim($_POST['client_name'] ?? '');
                $client_phone = trim($_POST['client_phone'] ?? '');
                $client_location = trim($_POST['client_location'] ?? 'No especificado');
                $order_type = trim($_POST['order_type'] ?? 'delivery');
                $order_note = trim($_POST['order_note'] ?? '');
                $order_total = floatval($_POST['order_total'] ?? 0);
                
                if (empty($client_name) || empty($client_phone) || $order_total <= 0) {
                     throw new Exception('Datos de cliente o total no válidos.');
                }

                // A. Buscar o crear cliente
                $cliente_id = 0;
                $stmt_cliente = $conn->prepare("SELECT id FROM clientes WHERE telefono = ?");
                $stmt_cliente->bind_param("s", $client_phone);
                $stmt_cliente->execute();
                $result_cliente = $stmt_cliente->get_result();

                if ($row = $result_cliente->fetch_assoc()) {
                    $cliente_id = $row['id'];
                } else {
                    $stmt_insert_cliente = $conn->prepare("INSERT INTO clientes (nombre, telefono, ubicacion) VALUES (?, ?, ?)");
                    $stmt_insert_cliente->bind_param("sss", $client_name, $client_phone, $client_location);
                    if (!$stmt_insert_cliente->execute()) {
                        throw new Exception('Error al registrar el cliente.');
                    }
                    $cliente_id = $conn->insert_id;
                    $stmt_insert_cliente->close();
                }
                $stmt_cliente->close();

                // B. Insertar Pedido
                $estado = 'pendiente';
                $fecha = date('Y-m-d H:i:s');
                $stmt_pedido = $conn->prepare("INSERT INTO pedidos (cliente_id, fecha_pedido, total, estado, nota_pedido, ubicacion, tipo) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt_pedido->bind_param("isdssss", $cliente_id, $fecha, $order_total, $estado, $order_note, $client_location, $order_type);
                
                if (!$stmt_pedido->execute()) {
                    throw new Exception('Error al guardar el pedido: ' . $stmt_pedido->error);
                }
                $pedido_id = $conn->insert_id;
                $stmt_pedido->close();

                // C. Insertar Productos del Pedido
                // Se modifican los campos de 'pedido_productos' para reflejar las cantidades
                $sql_productos = "INSERT INTO pedido_productos (pedido_id, producto_id, nombre_linea, modification_id, cantidad, precio_unitario, subtotal, añadido) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt_pedido_productos = $conn->prepare($sql_productos);
                if (!$stmt_pedido_productos) {
                    throw new Exception('Error al preparar la consulta de pedido_productos: ' . $conn->error);
                }

                foreach ($productos_pedido as $item) {
                    // modification_id se usa solo si es una única modificación (o 0 si son múltiples)
                    // Si el item representa una línea de carrito que tiene múltiples modificaciones combinadas, 
                    // 'modification_id' será 0 (cero).
                    $mod_id = isset($item['modification_id']) && is_numeric($item['modification_id']) ? intval($item['modification_id']) : 0; 
                    $precio_unitario = floatval($item['precio_unitario'] ?? 0);
                    $subtotal_linea = floatval($item['subtotal'] ?? 0);
                    $añadido = trim($item['añadido'] ?? '');
                    
                    // nombre_linea contendrá el nombre base + las modificaciones combinadas
                    $nombre_linea = $item['product_name'] . 
                                    ($item['modification_name'] ? ' (' . $item['modification_name'] . ')' : '');
                    
                    $stmt_pedido_productos->bind_param('iisiidds', 
                        $pedido_id, 
                        $item['producto_id'],
                        $nombre_linea, // Usamos el nombre combinado
                        $mod_id,
                        $item['cantidad'], 
                        $precio_unitario, 
                        $subtotal_linea,
                        $añadido
                    );
                    
                    if (!$stmt_pedido_productos->execute()) {
                        throw new Exception('Error al agregar productos al pedido.');
                    }
                }
                $stmt_pedido_productos->close();

                $conn->commit();
                $response['success'] = true;
                $response['message'] = 'Pedido creado con éxito. ID: ' . $pedido_id;
                $response['pedido_id'] = $pedido_id;

            } catch (Exception $e) {
                $conn->rollback();
                $response['message'] = 'Error al crear el pedido: ' . $e->getMessage();
                error_log('Error de transacción: ' . $e->getMessage());
            }
            echo json_encode($response);
            exit();

        case 'update_status':
            $order_id = (int)$_POST['order_id'];
            $new_status = $_POST['new_status'];
            
            $allowed_statuses = ['pendiente', 'pendiente_pago', 'en_preparacion', 'listo', 'entregado', 'cancelado'];
            if (!in_array($new_status, $allowed_statuses)) {
                $response['message'] = 'Estado inválido.';
            } else {
                try {
                    $stmt = $conn->prepare("UPDATE pedidos SET estado = ? WHERE id = ?");
                    if (!$stmt) {
                         throw new Exception('Error al preparar la consulta: ' . $conn->error);
                    }
                    $stmt->bind_param("si", $new_status, $order_id);
                    $stmt->execute();

                    if ($stmt->affected_rows > 0) {
                        $response['success'] = true;
                        $response['message'] = 'Estado del pedido actualizado con éxito.';
                    } else {
                        $response['message'] = 'No se encontró el pedido o el estado ya era el mismo.';
                    }
                    $stmt->close();
                } catch (Exception $e) {
                    error_log("Error al actualizar el estado del pedido: " . $e->getMessage());
                    $response['message'] = 'Error interno al actualizar el estado.';
                }
            }
            echo json_encode($response);
            exit();
            
        default:
            $response['message'] = 'Acción no válida.';
            echo json_encode($response);
            exit();
    }
}

// ----------------------------------------------------
// 2. CARGA DE DATOS PARA LA VISTA
// ----------------------------------------------------

// A. Obtener categorías
$categorias_disponibles = [];
$sql_categorias = "SELECT id, nombre FROM categorias_productos ORDER BY nombre ASC";
$result_categorias = $conn->query($sql_categorias);

if ($result_categorias && $result_categorias->num_rows > 0) {
    while ($row = $result_categorias->fetch_assoc()) {
        $categorias_disponibles[] = $row;
    }
}

// B. Obtener productos
$productos_por_categoria = [];
$productos_raw = []; 

$sql_productos = "SELECT p.id, p.nombre, p.precio, p.categoria_id, c.nombre as categoria_nombre 
                  FROM productos p 
                  JOIN categorias_productos c ON p.categoria_id = c.id
                  ORDER BY c.nombre ASC, p.nombre ASC";
$result_productos = $conn->query($sql_productos);

if ($result_productos) {
    while ($producto = $result_productos->fetch_assoc()) {
        $categoria_id = $producto['categoria_id'];
        if (!isset($productos_por_categoria[$categoria_id])) {
            $productos_por_categoria[$categoria_id] = [
                'nombre' => $producto['categoria_nombre'],
                'productos' => []
            ];
        }
        $productos_por_categoria[$categoria_id]['productos'][] = $producto;
        $productos_raw[$producto['id']] = $producto;
    }
}

// C. Obtener todas las modificaciones de productos
$modificaciones_por_producto = [];
// CAMBIO CLAVE EN SQL: Se añade m.cantidad (máx. para el input y para el grupo)
$sql_modificaciones = "SELECT m.id, m.producto_id, m.nombre, m.precio_adicional, m.categoria_id, m.cantidad, c.nombre as categoria_nombre 
                       FROM modificaciones_productos m
                       LEFT JOIN categorias_productos c ON m.categoria_id = c.id
                       ORDER BY m.producto_id, m.categoria_id, m.nombre";
$result_modificaciones = $conn->query($sql_modificaciones);

if ($result_modificaciones) {
    while ($modificacion = $result_modificaciones->fetch_assoc()) {
        $producto_id = $modificacion['producto_id'];
        // Usamos categoria_id (de la modificación) como clave del grupo
        $categoria_id = $modificacion['categoria_id'] ?? 0; 
        $categoria_nombre = $modificacion['categoria_nombre'] ?? 'Opciones Adicionales';
        
        // Inicializar la estructura
        if (!isset($modificaciones_por_producto[$producto_id])) {
            $modificaciones_por_producto[$producto_id] = [];
        }
        
        if (!isset($modificaciones_por_producto[$producto_id][$categoria_id])) {
            $modificaciones_por_producto[$producto_id][$categoria_id] = [
                'name' => $categoria_nombre,
                'items' => []
            ];
        }

        $modificacion['cost'] = (float)$modificacion['precio_adicional'];
        // CAPTURA DE LA CANTIDAD MÁXIMA (se usa para el límite individual y el límite de grupo)
        $modificacion['mod_max_quantity'] = (int)$modificacion['cantidad']; 
        // AÑADIR EL ID DEL GRUPO A CADA ITEM
        $modificacion['group_id'] = $categoria_id; 
        
        unset($modificacion['precio_adicional']);
        unset($modificacion['cantidad']);
        // Limpiar datos extra para el frontend
        unset($modificacion['categoria_id']);
        unset($modificacion['categoria_nombre']);
        unset($modificacion['producto_id']);
        
        $modificaciones_por_producto[$producto_id][$categoria_id]['items'][] = $modificacion;
    }
}

// D. Obtener pedidos pendientes (Solo se une con clientes, no con empleados)
$pedidos_pendientes = [];
$sql_pedidos = "SELECT p.id, p.fecha_pedido, p.total, p.estado, p.nota_pedido, p.tipo, p.ubicacion, 
                       c.nombre as cliente_nombre, c.telefono as cliente_telefono
                FROM pedidos p
                JOIN clientes c ON p.cliente_id = c.id
                WHERE p.estado IN ('pendiente', 'pendiente_pago', 'en_preparacion', 'listo')
                ORDER BY p.id ASC";

$result_pedidos = $conn->query($sql_pedidos);

if ($result_pedidos) {
    while ($pedido = $result_pedidos->fetch_assoc()) {
        $pedidos_pendientes[] = $pedido;
    }
}

$conn->close();

// ----------------------------------------------------
// 3. ESTRUCTURA HTML Y CONTENIDO DEL DASHBOARD
// ----------------------------------------------------
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($company_name); ?> | Dashboard Empleado</title>
    <link rel="stylesheet" href="./a.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        /* Estilos base para Dark Mode */
        .dark-mode .modal-content, .dark-mode .modification-item {
            background-color: #34495e; 
            color: #ecf0f1;
        }
        .dark-mode .modification-group-title {
            color: #f1c40f; 
            border-bottom: 1px solid #44586d;
        }

        /* Estilos para Agrupación de Modificaciones */
        #modifications-options {
            padding: 10px;
        }
        .modification-group-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #c0392b; 
            margin-top: 15px;
            margin-bottom: 5px;
            padding-bottom: 5px;
            border-bottom: 1px solid #e9ecef;
        }
        .modification-item {
            padding: 8px 0;
            display: flex;
            align-items: center;
            justify-content: space-between; /* Alinea los elementos a los extremos */
        }
        /* Estilos de la modificación */
        .mod-info {
            display: flex;
            align-items: center;
        }
        .mod-name {
            font-weight: 400;
            margin-right: 10px;
        }
        .mod-cost {
            font-weight: 600;
            color: #27ae60;
            font-size: 0.9em;
        }
        /* Estilos del input de cantidad para la modificación */
        .mod-quantity-input {
            width: 50px;
            padding: 5px;
            text-align: center;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        /* Estilos del modal (asegurar el scroll si hay muchas opciones) */
        #modifications-options {
            max-height: 40vh; /* Altura máxima del contenido */
            overflow-y: auto; /* Habilita scroll vertical */
            padding-right: 15px; /* Espacio para la barra de scroll */
        }
        /* Estilos para el control de cantidad en el modal */
        .quantity-control-modal {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            font-size: 1.1rem;
        }
    </style>
</head>
<body class="<?php echo $theme_mode . '-mode'; ?>">
    
    <header class="main-header">
        <div class="logo">
            <img src="<?php echo htmlspecialchars($logo_path); ?>" alt="Logo Empresa" class="header-logo">
            <span class="logo-tag"><?php echo htmlspecialchars($company_name); ?></span>
        </div>
        <nav class="header-nav">
            <ul>
                <li><a href="./empleado_dashboard.php" class="active">Pedidos</a></li>
                <li><a href="./productos.php">Inventario</a></li>
                <li>
                    <div class="theme-switch-wrapper">
                        <label class="theme-switch" for="darkModeToggle">
                            <input type="checkbox" id="darkModeToggle" />
                            <div class="slider round"></div>
                        </label>
                    </div>
                </li>
                <li>
                    <span class="user-profile"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Empleado'); ?></span>
                </li>
                <li>
                    <a href="./../logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Salir</a>
                </li>
            </ul>
        </nav>
    </header>
    <div id="status-message-container" class="status-message-container"></div>

    <div class="employee-dashboard-container">
        <section class="order-creation-section">
            <h2 class="section-title"><i class="fas fa-plus-circle"></i> Nuevo Pedido</h2>
            
            <div class="card client-info-card">
                <h3><i class="fas fa-user"></i> Información del Cliente</h3>
                <div class="form-group">
                    <label for="client-name">Nombre:</label>
                    <input type="text" id="client-name" name="client_name" required>
                </div>
                <div class="form-group">
                    <label for="client-phone">Teléfono (WhatsApp):</label>
                    <input type="tel" id="client-phone" name="client_phone" required>
                    <small>Se usa para buscar o registrar.</small>
                </div>
                <div class="form-group">
                    <label for="client-location">Ubicación/Dirección:</label>
                    <input type="text" id="client-location" name="client_location" required>
                </div>
                <div class="form-group">
                    <label for="order-type">Tipo de Pedido:</label>
                    <select id="order-type" name="order_type">
                        <option value="delivery">Delivery</option>
                        <option value="pickup">Para Recoger</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="order-note">Nota Adicional:</label>
                    <textarea id="order-note" name="order_note" rows="2"></textarea>
                </div>
            </div>

            <div class="card menu-card">
                <h3><i class="fas fa-pizza-slice"></i> Menú</h3>
                <div class="menu-categories">
                    <?php foreach ($productos_por_categoria as $categoria_id => $categoria): ?>
                        <div class="category-section">
                            <h4><?php echo htmlspecialchars($categoria['nombre']); ?></h4>
                            <div class="product-list">
                                <?php foreach ($categoria['productos'] as $producto): ?>
                                    <div class="product-item" data-product-id="<?php echo $producto['id']; ?>" data-product-name="<?php echo htmlspecialchars($producto['nombre']); ?>" data-base-price="<?php echo $producto['precio']; ?>" data-has-mods="<?php echo isset($modificaciones_por_producto[$producto['id']]) ? 'true' : 'false'; ?>">
                                        <div class="product-info">
                                            <span class="product-name"><?php echo htmlspecialchars($producto['nombre']); ?></span>
                                            <span class="product-price">$<?php echo number_format($producto['precio'], 2); ?></span>
                                        </div>
                                        <button class="btn btn-add-to-cart" title="Añadir"><i class="fas fa-cart-plus"></i></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="card cart-card">
                <h3><i class="fas fa-shopping-cart"></i> Carrito</h3>
                <ul id="cart-list">
                    <li class="empty-cart-message">El carrito está vacío.</li>
                </ul>
                <div class="cart-total">
                    <span>TOTAL:</span>
                    <span id="cart-total-display">$0.00</span>
                </div>
                <div class="payment-methods-selection">
                    <label for="payment-method">Método de Pago:</label>
                    <select id="payment-method">
                        <?php foreach($payment_options as $option): ?>
                            <option value="<?php echo trim($option); ?>"><?php echo htmlspecialchars(trim($option)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-primary btn-full" id="place-order-btn" disabled>Crear Pedido</button>
            </div>
        </section>

        <section class="pending-orders-section">
            <h2 class="section-title"><i class="fas fa-clipboard-list"></i> Pedidos en Proceso (<?php echo count($pedidos_pendientes); ?>)</h2>
            
            <?php if (empty($pedidos_pendientes)): ?>
                <div class="alert alert-info">No hay pedidos pendientes, en preparación o listos.</div>
            <?php else: ?>
                <div class="orders-grid">
                    <?php foreach ($pedidos_pendientes as $pedido): ?>
                        <div class="order-card status-<?php echo htmlspecialchars($pedido['estado']); ?>">
                            <div class="order-header">
                                <span class="order-id">Pedido #<?php echo $pedido['id']; ?></span>
                                <span class="order-time"><?php echo date('H:i', strtotime($pedido['fecha_pedido'])); ?></span>
                            </div>
                            <div class="order-body">
                                <p class="client-name"><i class="fas fa-user"></i> <?php echo htmlspecialchars($pedido['cliente_nombre']); ?></p>
                                <p class="client-phone"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($pedido['cliente_telefono']); ?></p>
                                <p class="order-type"><i class="fas fa-map-marker-alt"></i> **<?php echo htmlspecialchars(strtoupper($pedido['tipo'])); ?>**</p>
                                <?php if (!empty($pedido['nota_pedido'])): ?>
                                    <p class="order-note"><i class="fas fa-sticky-note"></i> Nota: <?php echo htmlspecialchars($pedido['nota_pedido']); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="order-footer">
                                <span class="order-total">$<?php echo number_format($pedido['total'], 2); ?></span>
                                
                                <div class="status-update-controls">
                                    <select class="status-selector" data-order-id="<?php echo $pedido['id']; ?>">
                                        <?php 
                                        $statuses = ['pendiente' => 'Pendiente', 'pendiente_pago' => 'Pendiente Pago', 'en_preparacion' => 'En Preparación', 'listo' => 'Listo', 'entregado' => 'Entregado', 'cancelado' => 'Cancelado'];
                                        foreach ($statuses as $value => $label): 
                                        ?>
                                            <option value="<?php echo $value; ?>" <?php echo ($pedido['estado'] == $value) ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-small btn-success update-status-btn" data-order-id="<?php echo $pedido['id']; ?>" title="Actualizar Estado"><i class="fas fa-sync-alt"></i></button>
                                </div>
                                <a href="generar_ticket.php?pedido_id=<?php echo $pedido['id']; ?>" class="btn btn-small btn-warning" target="_blank" title="Generar Ticket"><i class="fas fa-file-invoice"></i> Ticket</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </section>
    </div>

    <div id="modifications-modal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h3 id="modal-product-name"></h3>
            <div id="modifications-options">
                </div>
            <div class="modal-footer">
                <div class="quantity-control-modal">
                    <label for="modal-quantity">Cantidad del Producto Base:</label>
                    <input type="number" id="modal-quantity" value="1" min="1" style="width: 60px; padding: 5px; text-align: center;">
                </div>
                <div class="product-total-price">
                    Precio Unitario Final: <span id="modal-unit-price-display">$0.00</span><br>
                    Total de Línea: <span id="modal-line-total-display">$0.00</span>
                </div>
                <button class="btn btn-primary" id="confirm-modification-btn">Confirmar y Añadir</button>
            </div>
        </div>
    </div>
    
    <script>
    // ----------------------------------------------------
    // JAVASCRIPT: LÓGICA DEL CARRITO Y PEDIDOS (MODIFICADO)
    // ----------------------------------------------------
    document.addEventListener('DOMContentLoaded', function() {
        // Lógica de Dark Mode (Se mantiene igual)
        const themeKey = 'sgpp_theme_mode';
        const toggle = document.getElementById('darkModeToggle');
        const body = document.body;

        function setInitialTheme() {
            const savedTheme = localStorage.getItem(themeKey);
            if (savedTheme) {
                if (savedTheme === 'dark') {
                    body.classList.add('dark-mode');
                    toggle.checked = true;
                }
            } else {
                const initialThemeIsDark = body.classList.contains('dark-mode');
                toggle.checked = initialThemeIsDark;
                localStorage.setItem(themeKey, initialThemeIsDark ? 'dark' : 'light');
            }
        }
        
        if (toggle) {
            toggle.addEventListener('change', function() {
                if (this.checked) {
                    body.classList.add('dark-mode');
                    localStorage.setItem(themeKey, 'dark');
                } else {
                    body.classList.remove('dark-mode');
                    localStorage.setItem(themeKey, 'light');
                }
            });
            setInitialTheme(); 
        }
        // Fin Lógica de Dark Mode


        // Datos PHP a JS
        const productosRaw = <?php echo json_encode($productos_raw); ?>;
        const modificacionesPorProducto = <?php echo json_encode($modificaciones_por_producto); ?>;

        let orderCart = [];
        const cartList = document.getElementById('cart-list');
        const cartTotalDisplay = document.getElementById('cart-total-display');
        const placeOrderBtn = document.getElementById('place-order-btn');
        const modal = document.getElementById('modifications-modal');
        const closeButton = modal.querySelector('.close-button');
        const modificationsOptionsDiv = document.getElementById('modifications-options');
        const confirmModificationBtn = document.getElementById('confirm-modification-btn');
        
        let currentProduct = null;
        
        // Estructura: { modId: { id: 1, nombre: 'Extra Queso', cost: 5.00, quantity: 2, max: 10, groupId: 1 } }
        let selectedModificationsQuantities = {}; 

        // Función para mostrar mensajes de estado (Se mantiene igual)
        function showStatusMessage(message, type = 'info') {
            const container = document.getElementById('status-message-container');
            const messageElement = document.createElement('div');
            messageElement.className = `alert alert-${type}`;
            messageElement.textContent = message;
            container.appendChild(messageElement);
            setTimeout(() => {
                container.removeChild(messageElement);
            }, 3000);
        }
        
        // --- NUEVAS FUNCIONES DE RESTRICCIÓN DE CANTIDAD DE GRUPO ---

        // Función para obtener el estado de una modificación
        function getModificationState(modId) {
            return selectedModificationsQuantities[modId] || null;
        }

        // Función para obtener el máximo de cantidad permitido para un grupo de modificaciones
        function getGroupMaxQuantity(groupId) {
            // Asume que todas las modificaciones en un grupo comparten el mismo valor de 'cantidad' de la DB
            for (const modId in selectedModificationsQuantities) {
                if (selectedModificationsQuantities[modId].groupId == groupId) {
                    return selectedModificationsQuantities[modId].max;
                }
            }
            return 0; // Por defecto si no se encuentra
        }

        // FUNCIÓN CLAVE: Aplica la restricción de cantidad total (Ejemplo: 6 de Jamón + 6 de Humita = 12 Máx)
        function enforceGroupQuantityConstraint(groupId, sourceInput) {
            const groupMax = getGroupMaxQuantity(groupId);
            
            // Si el máximo es 0, significa que es una restricción individual o no aplica el límite de grupo
            // Sin embargo, si la cantidad es > 0, se aplica como límite de grupo
            if (groupMax <= 0) return; 

            // 1. Calcular el total seleccionado del grupo (usando el estado)
            let totalSelected = 0;
            const inputs = modificationsOptionsDiv.querySelectorAll(`.mod-quantity-input[data-group-id="${groupId}"]`);
            
            inputs.forEach(input => {
                const modId = parseInt(input.dataset.modId);
                // Usar el valor del estado, que ya fue actualizado por el listener de 'input'
                totalSelected += selectedModificationsQuantities[modId].quantity;
            });

            // 2. Si el total excede el máximo permitido
            if (totalSelected > groupMax) {
                const modId = parseInt(sourceInput.dataset.modId);
                const modState = getModificationState(modId);
                
                // Calcular cuánto se excedió y revertir el cambio del input de origen
                const excess = totalSelected - groupMax;
                let newQty = modState.quantity - excess;
                newQty = Math.max(0, newQty); // Asegura que no sea negativo
                
                // Revertir el estado y el valor del input
                modState.quantity = newQty;
                sourceInput.value = newQty;

                showStatusMessage(`¡Límite de grupo alcanzado! La suma total de este grupo de modificaciones no puede superar ${groupMax}.`, 'error');

                // Recalcular el total seleccionado después de la corrección
                totalSelected = groupMax; 
            }
            
            // 3. Establecer el máximo dinámico para todos los inputs del grupo
            const remainingLimit = groupMax - totalSelected;
            
            inputs.forEach(input => {
                const modId = parseInt(input.dataset.modId);
                const modState = getModificationState(modId);
                const currentQty = modState.quantity;
                
                // El nuevo máximo para este input es: Current Qty + Remaining Limit del grupo
                const maxFromGroupConstraint = currentQty + remainingLimit; 
                
                // Máximo individual (definido en la DB para este item específico)
                const individualMax = modState.max;

                // El límite real es el mínimo entre el límite individual y el límite del grupo
                const finalMax = Math.min(individualMax, maxFromGroupConstraint);
                
                input.max = finalMax;

                // Deshabilitar visualmente si ya no se pueden seleccionar más (y no hay nada seleccionado)
                if (finalMax == 0 && currentQty == 0) {
                    input.disabled = true;
                    input.closest('.modification-item').style.opacity = '0.5';
                } else {
                    input.disabled = false;
                    input.closest('.modification-item').style.opacity = '1';
                }
            });
        }

        // 1. RENDERIZADO Y CÁLCULO DEL CARRITO (Se mantiene igual)
        function calculateTotal() {
            let total = orderCart.reduce((sum, item) => sum + item.subtotal, 0);
            cartTotalDisplay.textContent = `$${total.toFixed(2)}`;
            placeOrderBtn.disabled = orderCart.length === 0 || 
                                     !document.getElementById('client-name').value || 
                                     !document.getElementById('client-phone').value || 
                                     !document.getElementById('client-location').value;
            
            const emptyMessage = cartList.querySelector('.empty-cart-message');
            if (orderCart.length > 0) {
                if(emptyMessage) emptyMessage.style.display = 'none';
            } else {
                if(emptyMessage) emptyMessage.style.display = 'block';
            }
        }

        function renderCart() {
            cartList.innerHTML = '<li class="empty-cart-message" style="display: none;">El carrito está vacío.</li>';
            orderCart.forEach((item, index) => {
                const li = document.createElement('li');
                li.className = 'cart-item';
                
                let mod_name = item.modification_name ? ` (${item.modification_name})` : '';
                let price_text = `$${item.subtotal.toFixed(2)}`;

                li.innerHTML = `
                    <div class="item-info">
                        <span class="item-qty">${item.cantidad}x</span> 
                        <span class="item-name">${item.product_name}${mod_name}</span>
                        <span class="item-price">${price_text}</span>
                    </div>
                    <div class="item-actions">
                        <button class="btn btn-small btn-delete remove-item-btn" data-index="${index}"><i class="fas fa-trash-alt"></i></button>
                    </div>
                `;
                cartList.appendChild(li);
            });
            calculateTotal();
        }
        
        // Función auxiliar para renderizar un item de modificación con input de cantidad
        function renderModificationItem(mod, groupId) { 
            const costPerUnit = mod.cost; 
            const costText = costPerUnit > 0 ? `(+$${parseFloat(costPerUnit).toFixed(2)} c/u)` : '';
            
            // Cantidad máxima individual permitida (desde la DB)
            const maxQuantity = mod.mod_max_quantity || 1; 

            // Si es un item real (no 'Sin Selección'), permite seleccionar cantidad hasta el máximo
            if (mod.id !== null) {
                // Se establece el 'max' inicial basado en el límite individual de la DB. El límite de grupo se ajusta dinámicamente en JS.
                return `
                    <div class="modification-item" data-mod-id="${mod.id}" data-cost="${costPerUnit}" data-name="${mod.nombre}" data-group-id="${groupId}">
                        <div class="mod-info">
                            <label for="mod-qty-${mod.id}" class="mod-name">${mod.nombre}</label>
                            <span class="mod-cost">${costText}</span>
                        </div>
                        <input type="number" id="mod-qty-${mod.id}" class="mod-quantity-input" 
                               value="0" min="0" max="${maxQuantity}" 
                               data-mod-id="${mod.id}" data-cost="${costPerUnit}" data-name="${mod.nombre}" data-group-id="${groupId}"
                               title="Máx. ${maxQuantity} unidades individuales. El límite total del grupo se aplica dinámicamente.">
                    </div>
                `;
            } else {
                // Opción por defecto (Sin Selección) - Cantidad siempre 0
                 return `
                    <div class="modification-item" data-mod-id="0" data-cost="0" data-name="${mod.nombre}" data-group-id="${groupId}">
                        <div class="mod-info">
                            <span class="mod-name">${mod.nombre} (por defecto)</span>
                        </div>
                        <input type="hidden" value="0" data-mod-id="0">
                    </div>
                `;
            }
        }

        // 2. LÓGICA DEL MODAL DE MODIFICACIONES
        function showModal(productData) {
            currentProduct = productData;
            selectedModificationsQuantities = {}; // Reset para el nuevo producto
            document.getElementById('modal-product-name').textContent = productData.productName;
            modificationsOptionsDiv.innerHTML = '';
            
            // Resetear la cantidad del producto base al abrir el modal
            const modalQuantityInput = document.getElementById('modal-quantity');
            if (modalQuantityInput) {
                modalQuantityInput.value = 1; 
            }
            
            const modificationsGroups = modificacionesPorProducto[productData.productId] || {};
            
            let htmlContent = '';
            
            // 1. Renderizar modificaciones agrupadas
            if (Object.keys(modificationsGroups).length > 0) {
                // Iteramos sobre los grupos (categorías de modificación)
                Object.entries(modificationsGroups).forEach(([groupId, group]) => {
                    
                    // Título del Grupo de Modificación
                    const groupMax = group.items.length > 0 ? (group.items[0].mod_max_quantity || 0) : 0;
                    
                    let maxText = (groupMax > 0) ? `(Máx. Total: ${groupMax})` : `(Sin Límite de Grupo)`;
                    htmlContent += `<h4 class="modification-group-title">${group.name} ${maxText}</h4>`;
                    
                    // Items de Modificación dentro del grupo
                    group.items.forEach((mod) => {
                        htmlContent += renderModificationItem(mod, groupId);
                        // Inicializar el estado de la cantidad seleccionada
                        selectedModificationsQuantities[mod.id] = { 
                            id: mod.id, 
                            nombre: mod.nombre, 
                            cost: mod.cost, 
                            quantity: 0,
                            max: mod.mod_max_quantity || 1, // Máximo permitido individualmente
                            groupId: groupId // AÑADIR EL ID DEL GRUPO
                        };
                    });
                });
            } else {
                 htmlContent += `<div class="alert alert-info" style="margin-top: 15px;">Este producto no tiene modificaciones adicionales configuradas.</div>`;
            }

            modificationsOptionsDiv.innerHTML = htmlContent;

            // Event listener para los inputs de cantidad de las modificaciones
            modificationsOptionsDiv.querySelectorAll('.mod-quantity-input').forEach(input => {
                
                // 4. Inicializar la restricción al abrir el modal
                enforceGroupQuantityConstraint(input.dataset.groupId, input);
                
                input.addEventListener('input', function() {
                    const modId = parseInt(this.dataset.modId);
                    const groupId = this.dataset.groupId;
                    let qty = parseInt(this.value);

                    // 1. Clamp with minimum 0 (the max attribute from HTML handles the upper bound visually)
                    if (isNaN(qty) || qty < 0) {
                        qty = 0;
                    }
                    this.value = qty; 
                    
                    // 2. Update the state of the quantity selected
                    selectedModificationsQuantities[modId].quantity = qty;
                    
                    // 3. Enforce the group quantity constraint
                    enforceGroupQuantityConstraint(groupId, this);

                    // 5. Update the modal totals
                    updateModalTotal();
                });
            });


            updateModalTotal(); 
            modal.style.display = 'flex';
        }
        
        // Calcula el precio unitario final y el total de la línea (Precio Unitario * Cantidad)
        function updateModalTotal() {
            if (!currentProduct) return { unitPrice: 0, lineTotal: 0, quantity: 0 };
            const basePrice = parseFloat(currentProduct.basePrice);
            
            // Obtener la cantidad del Producto Base (Cantidad de productos base que pide el cliente)
            let quantity = parseInt(document.getElementById('modal-quantity').value);
            if (isNaN(quantity) || quantity < 1) {
                quantity = 1;
                document.getElementById('modal-quantity').value = 1; 
            }
            
            let modificationsTotalCost = 0;
            let addedModNames = [];

            // Sumar el costo total de TODAS las modificaciones seleccionadas (POR UNIDAD de producto base)
            Object.values(selectedModificationsQuantities).forEach(mod => {
                const modQuantitySelected = mod.quantity || 0; // Cantidad seleccionada por el cliente (ej. 2 de Extra Queso)
                
                if (modQuantitySelected > 0) {
                    // Costo total de las modificaciones para UNA unidad del producto base: (Costo de Mod * Cantidad Seleccionada)
                    modificationsTotalCost += mod.cost * modQuantitySelected; 
                    addedModNames.push(`${modQuantitySelected}x ${mod.nombre}`);
                }
            });
            
            // Precio unitario final (Base + Costo Total de Modificaciones por Unidad)
            const finalUnitPrice = basePrice + modificationsTotalCost;
            
            // Total de línea (Precio Unitario Final * Cantidad del Producto Base)
            const lineTotal = finalUnitPrice * quantity;
            
            // Actualizar displays del modal
            document.getElementById('modal-unit-price-display').textContent = `$${finalUnitPrice.toFixed(2)}`;
            document.getElementById('modal-line-total-display').textContent = `$${lineTotal.toFixed(2)}`;
            
            // Adjuntar los nombres de las modificaciones añadidas para guardar en el carrito
            currentProduct.addedModNames = addedModNames.join(', ');

            return { unitPrice: finalUnitPrice, lineTotal: lineTotal, quantity: quantity }; 
        }

        // 3. EVENT LISTENERS
        
        // Listener para la cantidad en el modal (Cantidad del producto base)
        const modalQuantityInput = document.getElementById('modal-quantity');
        if (modalQuantityInput) {
            modalQuantityInput.addEventListener('input', function() {
                if (parseInt(this.value) < 1 || isNaN(parseInt(this.value))) {
                    this.value = 1;
                }
                updateModalTotal();
            });
        }
        
        // Abrir modal o añadir directamente
        document.querySelectorAll('.btn-add-to-cart').forEach(button => {
            button.addEventListener('click', function() {
                const itemDiv = this.closest('.product-item');
                const productId = parseInt(itemDiv.dataset.productId);
                const productName = itemDiv.dataset.productName;
                const basePrice = parseFloat(itemDiv.dataset.basePrice);
                const hasMods = itemDiv.dataset.hasMods === 'true';

                const productData = { productId, productName, basePrice };
                
                if (hasMods) {
                    showModal(productData);
                } else {
                    // Lógica de añadir sin modal
                    let quantity = prompt(`Ingresa la cantidad de ${productName}:`, 1);
                    if (quantity === null) return; 
                    
                    quantity = parseInt(quantity);
                    if (isNaN(quantity) || quantity < 1) {
                         showStatusMessage('Cantidad no válida. Debe ser 1 o más.', 'error');
                         return;
                    }
                    
                    const subtotal = basePrice * quantity;

                    orderCart.push({
                        producto_id: productId,
                        product_name: productName,
                        modification_id: 0, 
                        modification_name: null,
                        cantidad: quantity, // Usa la cantidad seleccionada
                        precio_unitario: basePrice, 
                        subtotal: subtotal, // Calcula el subtotal
                        añadido: '' 
                    });
                    renderCart();
                    showStatusMessage(`${quantity}x ${productName} añadido al carrito.`, 'success');
                }
            });
        });

        // Confirmar modificación y añadir al carrito
        confirmModificationBtn.addEventListener('click', function() {
            if (currentProduct) {
                // Obtener los totales y cantidad del modal
                const totals = updateModalTotal(); 
                const finalUnitPrice = totals.unitPrice; 
                const finalSubtotal = totals.lineTotal;
                const finalQuantity = totals.quantity;
                
                const combinedModName = currentProduct.addedModNames || null;
                
                // Agregar al carrito con la cantidad y subtotal correctos
                orderCart.push({
                    producto_id: currentProduct.productId,
                    product_name: currentProduct.productName,
                    modification_id: 0, // Se mantiene 0 ya que hay un string combinado de modificaciones
                    modification_name: combinedModName,
                    cantidad: finalQuantity, // Usa la cantidad del producto base
                    precio_unitario: finalUnitPrice, // Usa el precio unitario final (base + mods)
                    subtotal: finalSubtotal, // Usa el subtotal (unitario * cantidad)
                    // Añadimos el detalle de las modificaciones seleccionadas con sus cantidades
                    añadido: JSON.stringify(Object.values(selectedModificationsQuantities).filter(m => m.quantity > 0)) 
                });
                renderCart();
                modal.style.display = 'none';
                
                const message = combinedModName ? 
                    `${finalQuantity}x ${currentProduct.productName} con modificaciones añadidas.` :
                    `${finalQuantity}x ${currentProduct.productName} añadido (sin modificaciones).`;
                    
                showStatusMessage(message, 'success');
            }
        });

        // Quitar producto del carrito (Se mantiene igual)
        cartList.addEventListener('click', function(e) {
            if (e.target.closest('.remove-item-btn')) {
                const index = parseInt(e.target.closest('.remove-item-btn').dataset.index);
                orderCart.splice(index, 1);
                renderCart();
                showStatusMessage('Producto eliminado del carrito.', 'info');
            }
        });
        
        // Deshabilitar botón de pedido si la info del cliente no está completa (Se mantiene igual)
        document.querySelectorAll('#client-name, #client-phone, #client-location').forEach(input => {
            input.addEventListener('input', calculateTotal);
        });
        
        // 4. GUARDAR PEDIDO (AJAX POST) (Se mantiene igual)
        placeOrderBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            const clientName = document.getElementById('client-name').value.trim();
            const clientPhone = document.getElementById('client-phone').value.trim();
            const clientLocation = document.getElementById('client-location').value.trim();
            const orderType = document.getElementById('order-type').value;
            const orderNote = document.getElementById('order-note').value.trim();
            const total = orderCart.reduce((sum, item) => sum + item.subtotal, 0).toFixed(2);
            
            if (orderCart.length === 0 || !clientName || !clientPhone || !clientLocation) {
                showStatusMessage('Faltan productos o información del cliente.', 'error');
                return;
            }

            const formData = new URLSearchParams();
            formData.append('action', 'save_order');
            formData.append('client_name', clientName);
            formData.append('client_phone', clientPhone);
            formData.append('client_location', clientLocation);
            formData.append('order_type', orderType);
            formData.append('order_note', orderNote);
            formData.append('order_total', total);
            formData.append('productos_json', JSON.stringify(orderCart));

            fetch(window.location.href, { 
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showStatusMessage(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    orderCart = [];
                    document.getElementById('client-name').value = '';
                    document.getElementById('client-phone').value = '';
                    document.getElementById('client-location').value = '';
                    document.getElementById('order-note').value = '';
                    renderCart();
                    setTimeout(() => location.reload(), 1500); 
                }
            })
            .catch(error => {
                showStatusMessage('Error de conexión al guardar el pedido.', 'error');
                console.error('Error:', error);
            });
        });

        // 5. ACTUALIZAR ESTADO DEL PEDIDO (AJAX POST) (Se mantiene igual)
        document.querySelectorAll('.update-status-btn').forEach(button => {
            button.addEventListener('click', function() {
                const orderId = this.dataset.orderId;
                const statusSelector = document.querySelector(`.status-selector[data-order-id="${orderId}"]`);
                const newStatus = statusSelector.value;
                if (!newStatus) return;

                if (confirm(`¿Cambiar estado del Pedido #${orderId} a "${statusSelector.options[statusSelector.selectedIndex].text}"?`)) {
                    
                    const formData = new URLSearchParams();
                    formData.append('order_id', orderId);
                    formData.append('new_status', newStatus);
                    formData.append('action', 'update_status');

                    fetch(window.location.href, { 
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        showStatusMessage(data.message, data.success ? 'success' : 'error');
                        if (data.success) {
                            if (newStatus === 'entregado' || newStatus === 'cancelado') {
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                const orderCard = statusSelector.closest('.order-card');
                                orderCard.className = `order-card status-${newStatus}`; 
                            }
                        }
                    })
                    .catch(error => showStatusMessage('Error de conexión al actualizar el estado.', 'error'));
                }
            });
        });

        // Inicializar el carrito al cargar la página
        renderCart();
    });
    </script>
</body>
</html>