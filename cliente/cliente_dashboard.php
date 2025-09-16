<?php
session_start();
require_once './../config.php';

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'cliente') {
    header("Location: ./../index.php");
    exit();
}

$categorias_disponibles = [];
try {
    $sql_categorias = "SELECT id, nombre FROM categorias_productos ORDER BY nombre ASC";
    $result_categorias = $conn->query($sql_categorias);

    if ($result_categorias && $result_categorias->num_rows > 0) {
        while ($row = $result_categorias->fetch_assoc()) {
            $categorias_disponibles[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error al obtener categorías: " . $e->getMessage());
    $categorias_disponibles = [];
}

$productos_por_categoria = [];
try {
    $sql_productos = "SELECT p.id, p.nombre, p.precio, c.nombre AS categoria_nombre
                      FROM productos p
                      LEFT JOIN categorias_productos c ON p.categoria_id = c.id
                      ORDER BY c.nombre ASC, p.nombre ASC";
    $result_productos = $conn->query($sql_productos);

    if ($result_productos && $result_productos->num_rows > 0) {
        while ($row = $result_productos->fetch_assoc()) {
            $categoria_nombre = $row['categoria_nombre'] ?: 'Sin Categoría';
            if (!isset($productos_por_categoria[$categoria_nombre])) {
                $productos_por_categoria[$categoria_nombre] = [];
            }
            $productos_por_categoria[$categoria_nombre][] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error al obtener productos: " . $e->getMessage());
    $productos_por_categoria = [];
}

$clientes_disponibles = [];
try {
    $sql_clientes = "SELECT id, nombre, email, telefono, ubicacion FROM clientes ORDER BY email ASC";
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

$pedidos_a_gestionar = [];
try {
    $sql_pedidos = "SELECT p.id, p.cliente_id, p.token, c.nombre AS cliente_nombre, c.email AS cliente_email,
                           p.fecha, p.total, p.estado
                     FROM pedidos p
                     LEFT JOIN clientes c ON p.cliente_id = c.id
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    function generarToken($length = 6) {
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $token .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $token;
    }

    if (isset($_POST['place_order'])) {
        $conn->begin_transaction();
        try {
            $productos = json_decode($_POST['productos_json'], true);
            $cliente_id = isset($_POST['cliente_id']) && $_POST['cliente_id'] !== '0' ? intval($_POST['cliente_id']) : null;
            $total_pedido = 0;

            $ids_productos = array_column($productos, 'producto_id');
            $placeholders = implode(',', array_fill(0, count($ids_productos), '?'));
            $sql_precios = "SELECT id, precio FROM productos WHERE id IN ($placeholders)";
            $stmt_precios = $conn->prepare($sql_precios);
            $types = str_repeat('i', count($ids_productos));
            $stmt_precios->bind_param($types, ...$ids_productos);
            $stmt_precios->execute();
            $result_precios = $stmt_precios->get_result();
            $precios_productos = [];
            while ($row = $result_precios->fetch_assoc()) {
                $precios_productos[$row['id']] = $row['precio'];
            }
            $stmt_precios->close();

            foreach ($productos as $item) {
                $total_pedido += $precios_productos[$item['producto_id']] * $item['cantidad'];
            }

            $token = '';
            $token_unique = false;
            while (!$token_unique) {
                $token = generarToken();
                $sql_check_token = "SELECT 1 FROM pedidos WHERE token = ?";
                $stmt_check = $conn->prepare($sql_check_token);
                $stmt_check->bind_param("s", $token);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows === 0) {
                    $token_unique = true;
                }
                $stmt_check->close();
            }

            $sql_pedido = "INSERT INTO pedidos (cliente_id, total, token) VALUES (?, ?, ?)";
            $stmt_pedido = $conn->prepare($sql_pedido);
            $stmt_pedido->bind_param("ids", $cliente_id, $total_pedido, $token);
            $stmt_pedido->execute();
            $pedido_id = $stmt_pedido->insert_id;
            $stmt_pedido->close();

            $sql_productos_pedido = "INSERT INTO pedido_productos (pedido_id, producto_id, cantidad, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?)";
            $stmt_productos = $conn->prepare($sql_productos_pedido);
            foreach ($productos as $item) {
                $producto_id = $item['producto_id'];
                $cantidad = $item['cantidad'];
                $precio_unitario = $precios_productos[$producto_id];
                $subtotal = $precio_unitario * $cantidad;
                $stmt_productos->bind_param("iiidd", $pedido_id, $producto_id, $cantidad, $precio_unitario, $subtotal);
                $stmt_productos->execute();
            }
            $stmt_productos->close();

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Pedido realizado con éxito. Token: ' . $token, 'token' => $token]);

        } catch (Exception $e) {
            $conn->rollback();
            error_log("Error al realizar el pedido: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error al procesar el pedido.']);
        }
        $conn->close();
        exit();
    }

    if (isset($_POST['update_order_status'])) {
        $pedido_id = intval($_POST['order_id']);
        $new_status = $_POST['new_status'];
        $valid_statuses = ['pendiente', 'en_preparacion', 'listo', 'entregado', 'cancelado'];

        if (!in_array($new_status, $valid_statuses)) {
            echo json_encode(['success' => false, 'message' => 'Estado no válido.']);
            $conn->close();
            exit();
        }

        $sql = "UPDATE pedidos SET estado = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $new_status, $pedido_id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Estado del pedido actualizado.']);
        } else {
            error_log("Error al actualizar estado: " . $stmt->error);
            echo json_encode(['success' => false, 'message' => 'Error al actualizar el estado.']);
        }
        $stmt->close();
        $conn->close();
        exit();
    }
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
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&family=Lobster&display=swap" rel="stylesheet">
    <style>
        /*
        ========================================
        Variables y Estilos Generales
        ========================================
        */
        :root {
            --primary-color: #c0392b;
            --secondary-color: #e67e22;
            --tertiary-color: #f39c12;
            --dark-text: #333;
            --light-bg: #f8f8f8;
            --white-bg: #ffffff;
            --light-border: #ddd;
            --success-color: #27ae60;
            --error-color: #e74c3c;
            --info-color: #3498db;
            --grey-button: #7f8c8d;
            --status-pendiente: #f39c12;
            --status-en_preparacion: #3498db;
            --status-listo: #27ae60;
            --status-entregado: #7f8c8d;
            --status-cancelado: #e74c3c;
            --shadow-light: 0 2px 5px rgba(0, 0, 0, 0.05);
            --shadow-medium: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: 'Open Sans', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: var(--light-bg);
            color: var(--dark-text);
            line-height: 1.6;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
        }

        /*
        ========================================
        Tipografía y Encabezados
        ========================================
        */
        h1 {
            font-family: 'Lobster', cursive;
            text-align: center;
            color: var(--primary-color);
            margin-bottom: 25px;
            font-size: 2.8em;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1);
            padding-bottom: 10px;
            border-bottom: 2px solid var(--secondary-color);
            line-height: 1.2;
            width: 95%;
            max-width: 1300px;
        }

        h2, h3 {
            font-family: 'Lobster', cursive;
            color: var(--secondary-color);
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 2em;
            text-align: center;
            border-bottom: 2px solid var(--light-border);
            padding-bottom: 10px;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.08);
        }

        /*
        ========================================
        Estructura de la Página
        ========================================
        */
        .main-content-wrapper {
            display: flex;
            gap: 30px;
            margin-top: 20px;
            flex-wrap: wrap;
            width: 95%;
            max-width: 1300px;
        }

        .products-section, .order-summary-section, .manage-orders-section {
            background-color: var(--white-bg);
            border: 1px solid var(--light-border);
            border-radius: 10px;
            padding: 30px;
            box-shadow: var(--shadow-medium);
            margin-bottom: 20px;
            box-sizing: border-box;
        }
        .products-section { flex: 2; min-width: 350px; }
        .order-summary-section { flex: 1; min-width: 350px; }
        .manage-orders-section { flex-basis: 100%; margin-top: 30px; }

        /*
        ========================================
        Sección de Productos
        ========================================
        */
        .category-details {
            margin-bottom: 20px; /* Más espacio entre categorías */
            border: 1px solid var(--light-border);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: var(--shadow-light);
        }

        .category-summary {
            background-color: var(--secondary-color);
            color: white;
            padding: 15px; /* Padding más simple */
            cursor: pointer;
            font-weight: 700;
            font-size: 1.2em;
            display: block;
            position: relative;
            user-select: none;
            transition: background-color 0.2s ease;
        }
        .category-summary:hover {
            background-color: #d35400;
        }

        .category-summary::marker,
        .category-summary::-webkit-details-marker {
            display: none;
            content: "";
        }

        /* El icono de flecha se mantiene en el CSS anterior ya que no está en la imagen */
        .category-details[open] > .category-summary::before {
            content: '▼';
            transform: rotate(0deg);
        }

        .category-content {
            padding: 15px; /* Padding más simple */
            background-color: #fefefe;
        }

        .product-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0; /* Padding más compacto */
            border-bottom: 1px solid var(--light-border);
        }
        .product-item:last-child { border-bottom: none; }

        .product-info { flex-grow: 1; margin-right: 15px; }
        .product-name {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 1.1em;
        }
        .product-price-value {
            font-weight: bold;
            color: var(--secondary-color);
        }

        .product-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .product-actions input[type="number"] {
            width: 60px; /* Ancho más ajustado */
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 5px;
            text-align: center;
            font-size: 1em;
        }
        .product-actions button {
            background-color: var(--success-color);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.2s ease, transform 0.1s ease, box-shadow 0.2s ease;
            box-shadow: var(--shadow-light);
        }
        .product-actions button:hover {
            background-color: #218838;
            transform: translateY(-1px);
        }

        /*
        ========================================
        Sección de Pedidos
        ========================================
        */
        .customer-info-fields {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--light-border);
        }
        .customer-info-fields label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark-text);
            font-size: 1.05em;
        }
        .customer-info-fields input[type="text"],
        #existing_cliente_id {
            width: calc(100% - 24px);
            padding: 12px;
            margin-bottom: 18px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 1em;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        .customer-info-fields input[type="text"]:focus,
        #existing_cliente_id:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 8px rgba(230, 126, 34, 0.2);
            outline: none;
        }

        #current-order-list {
            list-style: none;
            padding: 0;
            margin-top: 15px;
        }
        #current-order-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px dotted #eee;
        }
        #current-order-list li:last-child { border-bottom: none; }

        #current-order-total {
            font-weight: bold;
            text-align: right;
            margin-top: 15px;
            padding-top: 10px;
            border-top: 2px solid var(--secondary-color);
            font-size: 1.3em;
            color: var(--primary-color);
        }

        .order-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        .order-buttons button {
            background-color: var(--info-color);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.2s ease, transform 0.1s ease, box-shadow 0.2s ease;
            box-shadow: 2px 2px 5px rgba(0, 0, 0, 0.1);
        }
        .order-buttons button:hover {
            background-color: #2874a7;
            transform: translateY(-1px);
        }
        .order-buttons button.clear { background-color: var(--grey-button); }
        .order-buttons button.clear:hover { background-color: #6c7a89; }
        .order-buttons button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        .status-message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 600;
            display: none;
            border: 1px solid transparent;
            box-shadow: var(--shadow-light);
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        .status-message.success { background-color: #e6ffe6; color: var(--success-color); border-color: #d0f0d0; }
        .status-message.error { background-color: #ffe6e6; color: var(--error-color); border-color: #f0d0d0; }

        /*
        ========================================
        Tabla de Gestión de Pedidos
        ========================================
        */
        .order-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 20px;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            background-color: var(--white-bg);
            border: 1px solid var(--light-border);
        }
        .order-table th, .order-table td {
            border-bottom: 1px solid var(--light-border);
            padding: 12px 15px;
            text-align: left;
            vertical-align: middle;
            font-size: 1em;
        }
        .order-table th {
            background-color: var(--secondary-color);
            color: white;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .order-table tr:nth-child(even) { background-color: #f9f9f9; }
        .order-table tr:hover { background-color: #f5f5f5; }

        .order-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-start;
        }
        .order-actions button, .order-actions select {
            padding: 8px 12px;
            border-radius: 5px;
            border: 1px solid #ccc;
            cursor: pointer;
            margin-right: 0;
            font-weight: 600;
            transition: background-color 0.2s ease, transform 0.1s ease;
        }
        .order-actions select {
            background-color: #fefefe;
            border-color: var(--light-border);
            color: var(--dark-text);
        }
        .order-actions button {
            background-color: var(--tertiary-color);
            color: var(--dark-text);
            border: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .order-actions button:hover {
            background-color: #e67e22;
            color: white;
            transform: translateY(-1px);
        }

        /*
        ========================================
        Insignias de Estado
        ========================================
        */
        .status-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 5px;
            font-size: 0.9em;
            font-weight: bold;
            color: white;
            text-transform: capitalize;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .status-pendiente { background-color: var(--status-pendiente); color: var(--dark-text); }
        .status-en_preparacion { background-color: var(--status-en_preparacion); }
        .status-listo { background-color: var(--status-listo); }
        .status-entregado { background-color: var(--status-entregado); }
        .status-cancelado { background-color: var(--status-cancelado); }

        /*
        ========================================
        Botón de Cerrar Sesión
        ========================================
        */
        .logout-button-container {
            width: 100%;
            max-width: 1300px;
            text-align: center;
            margin-top: 40px;
        }
        .logout-button {
            background-color: var(--grey-button);
            color: white;
            padding: 12px 25px;
            border-radius: 5px;
            font-size: 1em;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            transition: background-color 0.2s ease, transform 0.1s ease, box-shadow 0.2s ease;
        }
        .logout-button:hover {
            background-color: #6c7a89;
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
        }

        /*
        ========================================
        Media Queries (Responsive)
        ========================================
        */
        @media (max-width: 992px) {
            .main-content-wrapper { gap: 25px; }
            h1 { font-size: 2.5em; }
            h2, h3 { font-size: 1.8em; }
            th, td { padding: 12px 15px; }
        }

        @media (max-width: 768px) {
            body { padding: 15px; }
            h1 { font-size: 2em; margin-bottom: 20px; }
            .main-content-wrapper { flex-direction: column; gap: 20px; }
            .products-section, .order-summary-section, .manage-orders-section { padding: 25px; }
            h2, h3 { font-size: 1.5em; margin-bottom: 15px; }
            .customer-info-fields input[type="text"],
            #existing_cliente_id { width: 100%; padding: 10px; margin-bottom: 15px; }
            .order-buttons { flex-direction: column; gap: 10px; }
            .order-buttons button { width: 100%; }
            .product-actions { flex-direction: column; gap: 10px; align-items: flex-end;}
            .product-actions input[type="number"] { width: 100px; }
            .order-actions { flex-direction: column; gap: 5px; }
            .order-actions button, .order-actions select { width: 100%; }
        }
        @media (max-width: 480px) {
            h1 { font-size: 1.8em; }
            h2, h3 { font-size: 1.3em; }
            th, td { padding: 10px; font-size: 0.9em; }
            .status-badge { padding: 5px 8px; font-size: 0.8em; }
            .logout-button-container { margin-top: 30px; }
            .logout-button { width: 100%; }
        }
    </style>
</head>
<body>
    <h1>Bienvenido, Empleado <?php echo htmlspecialchars($_SESSION['usuario_email']); ?></h1>
    <p>Este es el panel para el personal de ventas y atención al cliente.</p>

    <div class="status-message" id="php-message"></div>

    <div class="main-content-wrapper">
        <div class="products-section">
            <h2>Productos/Combos Disponibles</h2>
            <?php if (empty($productos_por_categoria)): ?>
                <p class="no-data">No hay productos disponibles.</p>
            <?php else: ?>
                <?php foreach ($productos_por_categoria as $categoria_nombre => $productos): ?>
                    <div class="category-details">
                        <div class="category-summary"><b><?php echo htmlspecialchars($categoria_nombre); ?></b></div>
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
                    </div>
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
                            <th>Token</th>
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
                                <td><b><?php echo htmlspecialchars($pedido['token'] ?? 'N/A'); ?></b></td>
                                <td>
                                    <?php
                                    if ($pedido['cliente_id'] !== null) {
                                        echo '<b>Registrado:</b><br>' . htmlspecialchars($pedido['cliente_email'] ?? 'N/A') . '<br>' . htmlspecialchars($pedido['cliente_nombre'] ?? 'N/A');
                                    } else {
                                        echo '<b>A la calle:</b><br>' . htmlspecialchars($pedido['nombre'] ?? 'N/A') . '<br>' . htmlspecialchars($pedido['ubicacion'] ?? 'N/A');
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
            statusMessageDiv: document.getElementById('php-message'),
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
                        <button class="remove-item-btn" data-index="${index}" style="background: var(--error-color); color: white; border: none; cursor: pointer; border-radius: 50%; width: 20px; height: 20px; display: flex; justify-content: center; align-items: center; font-size: 0.8em;">X</button>
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
                const response = await fetch('empleado_dashboard.php', {
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
                        const response = await fetch('empleado_dashboard.php', {
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
