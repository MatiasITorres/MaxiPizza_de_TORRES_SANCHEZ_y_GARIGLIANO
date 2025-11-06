<?php
// UNIFICACIÓN Y MODIFICACIÓN: Cliente Dashboard con Lógica de Restricción de Cantidad por Grupo

session_start();
// Asegúrate de que esta ruta a config.php sea correcta
// NOTA: Si config.php está en un nivel superior, el path es correcto si estás en 'cliente/cliente_dashboard.php'
require_once __DIR__ . '/../config.php'; 

// Conexión a la Base de Datos
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// ------------------------------------------------------------------------
// Cargar la configuración desde JSON
// ------------------------------------------------------------------------
$config_file = __DIR__ . '/../admin/config_data.json';
$settings = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];

// Asignación de variables de configuración con valores por defecto
$company_name = $settings['company_name'] ?? 'Tu Kiosco Digital'; 
$mercadopago_activo = ($settings['mercadopago_active'] ?? '0') === '1'; 
$metodos_adicionales_raw = $settings['other_payment_options'] ?? 'Efectivo, Transferencia';
$metodos_adicionales = array_map('trim', explode(',', $metodos_adicionales_raw));

// Lógica de Mensajes y Carrito
$cliente_id_actual = $_SESSION['usuario_id'] ?? 1;

// Limpiar carrito si se recibió el parámetro 'clear_cart' después de un pedido exitoso
if (isset($_GET['clear_cart']) && $_GET['clear_cart'] == 1) {
    echo "<script>
        localStorage.removeItem('kiosco_cart');
        localStorage.removeItem('kiosco_order_type');
    </script>";
}

// 1. Obtener Productos del Menú
$productos = [];
// CORRECCIÓN: Asegurar que se obtiene img_path
$sql = "SELECT id, nombre, descripcion, precio, categoria_id, img_path FROM productos WHERE disponible = 1 ORDER BY categoria_id, nombre";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $productos[] = $row;
    }
}

// 2. Obtener Categorías para agrupar
$categorias = [];
$sql_cat = "SELECT id, nombre FROM categorias_productos WHERE activo = 1 ORDER BY orden, nombre";
$result_cat = $conn->query($sql_cat);

if ($result_cat && $result_cat->num_rows > 0) {
    while ($row_cat = $result_cat->fetch_assoc()) {
        $categorias[$row_cat['id']] = $row_cat['nombre'];
    }
}

// ------------------------------------------------------------------------
// 3. Obtener Modificaciones de Productos (AGRUPADAS POR NOMBRE DE CATEGORÍA)
// ------------------------------------------------------------------------
$modificaciones_por_producto = []; 
$sql_mod = "SELECT 
                mp.id, mp.producto_id, mp.nombre, mp.precio_adicional, mp.tipo, mp.categoria_id, mp.cantidad, 
                cp.nombre AS categoria_nombre
            FROM modificaciones_productos mp 
            LEFT JOIN categorias_productos cp ON mp.categoria_id = cp.id
            ORDER BY mp.producto_id, cp.nombre, mp.tipo DESC, mp.id"; 

$result_mod = $conn->query($sql_mod);

if ($result_mod && $result_mod->num_rows > 0) {
    while ($modificacion = $result_mod->fetch_assoc()) {
        $producto_id = $modificacion['producto_id'];
        
        $categoria_nombre = $modificacion['categoria_nombre'] ?? 'Opciones Adicionales'; 
        
        if (!isset($modificaciones_por_producto[$producto_id])) {
            $modificaciones_por_producto[$producto_id] = [];
        }
        
        if (!isset($modificaciones_por_producto[$producto_id][$categoria_nombre])) {
            $modificaciones_por_producto[$producto_id][$categoria_nombre] = [
                'group_name' => $categoria_nombre, 
                'group_id' => (int)($modificacion['categoria_id'] ?? 0), 
                'mod_max_quantity' => (int)$modificacion['cantidad'], 
                'items' => []
            ];
        }
        
        $modificaciones_por_producto[$producto_id][$categoria_nombre]['items'][] = [
             'id' => (int)$modificacion['id'],
             'name' => $modificacion['nombre'],
             'precio_adicional' => (float)$modificacion['precio_adicional'],
             'tipo' => $modificacion['tipo'],
             'mod_max_quantity' => (int)$modificacion['cantidad'],
             'group_name' => $categoria_nombre 
        ];
    }
}

// Exportar productos y modificaciones como JSON para JS
$productos_json = json_encode($productos);
$modificaciones_json = json_encode($modificaciones_por_producto); 

$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($company_name) ?> - digital</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        /* ======================================================= */
        /* == ESTILOS MEJORADOS UX/UI Y RESPONSIVE == */
        /* ======================================================= */
        :root {
            --color-primary: #007bff;
            --color-primary-dark: #0056b3;
            --color-secondary: #6c757d;
            --color-success: #28a745;
            --color-danger: #dc3545;
            --color-warning: #ffc107;
            --color-background: #f4f5f7; /* Fondo más suave */
            --color-text: #343a40;
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: var(--color-background);
            color: var(--color-text);
            line-height: 1.6;
        }

        .container {
            display: flex;
            min-height: 100vh;
            max-width: 1400px;
            margin: 0 auto;
        }

        .menu-section {
            flex: 2;
            padding: 20px;
            overflow-y: auto;
            background-color: white;
            box-shadow: var(--shadow-md);
        }

        /* --- Cart Section (Desktop Sidebar / Mobile Drawer) --- */
        .cart-section {
            flex: 1;
            padding: 20px;
            display: flex;
            flex-direction: column;
            background-color: #e9ecef;
            border-left: 1px solid #dee2e6;
        }
        
        /* Fixed/Sticky Cart for Desktop */
        @media (min-width: 769px) {
            .cart-section {
                position: sticky;
                top: 0;
                height: 100vh;
                overflow-y: auto;
            }
        }

        .category-header {
            border-bottom: 3px solid var(--color-primary);
            padding-bottom: 8px;
            margin-top: 30px;
            color: var(--color-primary-dark);
            font-size: 1.6em;
            font-weight: 600;
        }

        .product-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
            padding: 15px 0;
        }

        .product-card {
            background-color: white;
            border: none; 
            border-radius: 12px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            padding: 15px; 
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 280px; 
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.15);
            border: 1px solid var(--color-primary);
        }

        .product-image-container {
            width: 100%;
            height: 140px; 
            margin-bottom: 10px;
            overflow: hidden; 
            border-radius: 8px;
        }

        .product-image-container img {
            width: 100%;
            height: 100%;
            object-fit: cover; 
            transition: transform 0.3s;
        }
        
        .product-info {
            flex-grow: 1; 
            text-align: left;
        }
        
        .product-name {
            font-weight: 700;
            margin-bottom: 3px;
            font-size: 1.2em;
        }
        
        .product-description {
            font-size: 0.8em;
            color: var(--color-secondary);
            margin-bottom: 15px;
            height: 3.2em; /* Limita la altura para uniformidad */
            overflow: hidden;
        }

        .product-price {
            color: var(--color-success);
            font-size: 1.3em;
            font-weight: 700;
        }
        
        /* Botones generales */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            text-align: center;
            transition: background-color 0.2s;
            margin-top: 10px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary { background-color: var(--color-primary); color: white; }
        .btn-primary:hover { background-color: #0056b3; }
        .btn-success { background-color: var(--color-success); color: white; }
        .btn-success:hover { background-color: #1e7e34; }
        .btn-danger { background-color: var(--color-danger); color: white; }
        .btn-danger:hover { background-color: #bd2130; }
        .btn-secondary { background-color: var(--color-secondary); color: white; }
        .btn-secondary:hover { background-color: #5a6268; }


        /* --- Cart Item Styling --- */
        #cart-items {
            flex-grow: 1;
            overflow-y: auto;
            margin-bottom: 15px;
            padding-right: 5px; /* Espacio para scrollbar */
        }

        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 12px 0;
            border-bottom: 1px dashed #ced4da;
        }
        
        .cart-item-name {
            font-weight: 600;
            font-size: 1em;
        }
        
        .cart-quantity-controls {
            display: flex;
            align-items: center;
            margin: 0 10px;
            min-width: 90px;
            justify-content: space-between;
        }
        
        .cart-quantity-controls button {
            background: var(--color-primary);
            width: 25px;
            height: 25px;
            padding: 0;
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2em;
            border-radius: 4px;
        }
        
        .cart-item-total {
            min-width: 90px;
            text-align: right;
            font-weight: 700;
            color: var(--color-success);
        }
        
        .cart-summary {
            padding-top: 15px;
            border-top: 2px solid var(--color-primary);
        }

        /* --- Mobile Floating Cart Button --- */
        #mobile-cart-button {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 900;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--color-primary);
            color: white;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            border: none;
            font-size: 1.5em;
            display: none; /* Oculto por defecto, visible en móvil */
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        #mobile-cart-button:hover {
             background-color: var(--color-primary-dark);
        }
        
        #cart-count-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: var(--color-danger);
            color: white;
            border-radius: 50%;
            padding: 4px 8px;
            font-size: 0.7em;
            font-weight: bold;
            line-height: 1;
        }

        /* --- Toast Notifications --- */
        #toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 2000;
            pointer-events: none;
        }

        .toast {
            background-color: white;
            color: var(--color-text);
            padding: 15px 20px;
            margin-bottom: 10px;
            border-radius: 8px;
            box-shadow: var(--shadow-md);
            opacity: 0;
            transition: opacity 0.5s, transform 0.5s;
            transform: translateX(100%);
            display: flex;
            align-items: center;
            font-weight: 600;
            min-width: 250px;
        }

        .toast.show {
            opacity: 1;
            transform: translateX(0);
        }

        .toast-success { border-left: 5px solid var(--color-success); }
        .toast-warning { border-left: 5px solid var(--color-warning); }
        .toast-error { border-left: 5px solid var(--color-danger); }
        
        .toast i {
            margin-right: 10px;
            font-size: 1.2em;
        }

        /* --- MODAL STYLING IMPROVEMENTS --- */
        .modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.6); 
            display: none;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background-color: white;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 500px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            text-align: center;
        }
        
        #modifications-options-container {
            max-height: 250px;
            overflow-y: auto;
            border: 1px solid #ccc;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: left;
        }
        
        #modifications-options-container h4 {
            margin-top: 10px;
            padding-bottom: 5px;
            color: var(--color-primary);
            border-bottom: 1px solid #eee;
        }

        .modification-option {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px dashed #eee;
        }
        
        .modification-option.mod-required {
            background-color: #f0f0f0;
            padding: 8px;
            border-radius: 4px;
            font-weight: bold;
        }
        
        .mod-item-quantity-controls {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .payment-option {
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 8px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: background-color 0.2s, border-color 0.2s;
            text-align: left;
            display: flex;
            align-items: center;
        }

        .payment-option.selected {
            background-color: #e6f7ff;
            border-color: var(--color-primary);
            box-shadow: 0 0 5px rgba(0, 123, 255, 0.5);
            font-weight: bold;
        }
        
        /* ======================================================= */
        /* == MEDIA QUERY: Móvil (Max 768px) == */
        /* ======================================================= */
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }
            
            .menu-section {
                flex: 1;
                padding: 10px;
                padding-bottom: 80px; /* Espacio para el botón flotante */
            }
            
            .product-list {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
                gap: 15px;
            }

            .product-card {
                 min-height: 200px;
                 padding: 10px;
            }

            .product-image-container {
                height: 100px; 
            }
            
            .product-name {
                 font-size: 1em;
            }
            
            .product-description {
                 display: none; /* Oculta descripciones en móvil */
            }

            /* Cart section as full screen modal on mobile */
            .cart-section {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 1000; 
                display: none; 
                background-color: white;
                padding: 20px;
                border-left: none;
                overflow-y: auto;
            }

            #mobile-cart-button {
                display: flex;
            }
            
            /* Ajuste para modal de modificaciones en móvil */
            #modificationsModal .modal-content {
                max-width: 95%;
                margin: 20px;
            }
            
            #toast-container {
                top: auto;
                bottom: 20px;
                right: 50%;
                transform: translateX(50%);
            }
            
            .toast {
                 min-width: 90vw;
                 text-align: center;
                 justify-content: center;
            }
        }
        
    </style>

</head>
<body>

<div id="kiosco-start-screen" style="display: none; height: 100vh; align-items: center; justify-content: center; text-align: center;">
    <div class="kiosco-start-content" style="max-width: 400px; padding: 30px; background: white; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <h1>Bienvenido a **<?= htmlspecialchars($company_name) ?>** 👋</h1>
        <p>Selecciona cómo deseas disfrutar tu pedido:</p>
        <div class="kiosco-order-options">
            <button class="start-order-btn btn btn-primary" data-order-type="MESA">
                <i class="fas fa-utensils"></i> Para **MESA**
            </button>
            <button class="start-order-btn btn btn-primary" data-order-type="LLEVAR">
                <i class="fas fa-shopping-bag"></i> Para **LLEVAR**
            </button>
            <button class="start-order-btn btn btn-primary" data-order-type="DELIVERY">
                <i class="fas fa-truck"></i> Para **DELIVERY**
            </button>
        </div>
    </div>
</div>

<div id="main-app-container" class="container" style="display: none;">
    
    <div class="menu-section">
        <h1>Menú Digital</h1>
        <p>Toca un producto para añadirlo al carrito.</p>
        
        <?php 
        $category_groups = [];

        // Agrupar productos por categoría
        foreach ($productos as $producto) {
            $category_id = $producto['categoria_id'] ?? 0;
            if (!isset($category_groups[$category_id])) {
                $category_groups[$category_id] = [
                    'name' => $categorias[$category_id] ?? 'Sin Categoría',
                    'products' => []
                ];
            }
            $category_groups[$category_id]['products'][] = $producto;
        }
        
        foreach ($category_groups as $category_id => $group) :
        ?>
            <h2 class="category-header"><?= htmlspecialchars($group['name']) ?></h2>
            <div class="product-list">
                <?php foreach ($group['products'] as $producto) : 
                    $has_mods = isset($modificaciones_por_producto[$producto['id']]) && count($modificaciones_por_producto[$producto['id']]) > 0;
                ?>
                    <div 
                        class="product-card" 
                        data-id="<?= $producto['id'] ?>" 
                        data-has-mods="<?= $has_mods ? 'true' : 'false' ?>"
                        onclick="addToCart(this)"
                    >
                        <div class="product-image-container">
                            <?php if (!empty($producto['img_path'])) : ?>
                                <img src="../images/productos/<?= htmlspecialchars($producto['img_path']) ?>" alt="<?= htmlspecialchars($producto['nombre']) ?>">
                            <?php else: ?>
                                <img src="../images/productos/default.jpg" alt="Producto sin imagen">
                            <?php endif; ?>
                        </div>
                        <div class="product-info">
                            <div class="product-name"><?= htmlspecialchars($producto['nombre']) ?></div>
                            <div class="product-description"><?= htmlspecialchars($producto['descripcion']) ?></div>
                        </div>
                        <div class="product-price">$<?= number_format($producto['precio'], 2, ',', '.') ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

    </div>

    <div id="cart-drawer" class="cart-section">
        <div id="cart-header-mobile" style="display: none; justify-content: space-between; align-items: center; margin-bottom: 20px;">
             <h2>Tu Pedido (<span id="order-type-display-mobile"></span>)</h2>
             <button class="btn btn-secondary" onclick="toggleMobileCart()" style="width: auto; margin-top: 0;"><i class="fas fa-times"></i> Cerrar</button>
        </div>
        
        <h2 id="cart-header-desktop">Tu Pedido (<span id="order-type-display-desktop">MESA</span>)</h2>
        <div id="cart-items">
            <p id="empty-cart-message">El carrito está vacío.</p>
        </div>
        
        <div class="cart-summary">
            <div>Subtotal: <span id="cart-subtotal">$0,00</span></div>
            <div>**Total:** <span id="cart-total">$0,00</span></div>
        </div>
        
        <button id="checkout-btn" class="btn btn-success" disabled onclick="openCheckoutModal()">
            <i class="fas fa-cash-register"></i> Proceder al Pago
        </button>
        <button id="clear-cart-btn" class="btn btn-danger" onclick="clearCart()">
            <i class="fas fa-trash-alt"></i> Vaciar Carrito
        </button>
    </div>

</div>

<button id="mobile-cart-button" onclick="toggleMobileCart()">
    <i class="fas fa-shopping-cart"></i>
    <span id="cart-count-badge" style="display: none;">0</span>
</button>

<div id="modificationsModal" class="modal">
    <div class="modal-content">
        <h3 id="mod-product-name"></h3>
        <p>Precio Base: <strong id="mod-base-price">$0,00</strong></p>
        
        <div id="modifications-options-container">
            </div>

        <div class="mod-summary">
            <span>Cantidad:</span> 
            <div class="mod-quantity-controls">
                <button class="btn btn-secondary" data-action="minus" onclick="updateMainQuantity(this)"><i class="fas fa-minus"></i></button>
                <span id="mod-quantity-display">1</span>
                <button class="btn btn-secondary" data-action="plus" onclick="updateMainQuantity(this)"><i class="fas fa-plus"></i></button>
            </div>
        </div>
        
        <div class="mod-summary">
            <span>Total Item:</span> <span id="mod-total-price">$0,00</span>
        </div>

        <button class="btn btn-success" onclick="addModProductToCart()">
            <span id="add-mod-count">1</span> x Agregar al Carrito
        </button>
        <button class="btn btn-secondary" onclick="closeModificationsModal()">Cancelar</button>
    </div>
</div>

<div id="checkoutModal" class="modal">
    <form id="checkout-form" method="POST" action="guardar_pedido.php">
        <div class="modal-content">
            <h2>Finalizar Pedido</h2>
            <p>Total a pagar: <strong id="modal-cart-total">$0,00</strong></p>
            <p>Tipo de Pedido: <strong id="modal-order-type"></strong></p>

            <div class="payment-options-container">
                 <?php if ($mercadopago_activo) : ?>
                    <div class="payment-option" data-method="Mercado Pago" onclick="selectPaymentMethod(this)">
                        <span class="payment-icon"><i class="fas fa-qrcode"></i></span> Mercado Pago (Pagas con QR en la barra)
                    </div>
                <?php endif; ?>
                <?php foreach ($metodos_adicionales as $method) : 
                    $icon = '';
                    if ($method === 'Efectivo') { $icon = 'fas fa-money-bill-wave'; } 
                    elseif ($method === 'Transferencia') { $icon = 'fas fa-university'; }
                    else { $icon = 'fas fa-credit-card'; }
                ?>
                    <div class="payment-option" data-method="<?= htmlspecialchars($method) ?>" onclick="selectPaymentMethod(this)">
                        <span class="payment-icon"><i class="<?= $icon ?>"></i></span> <?= htmlspecialchars($method) ?> (Pagas en la barra/Transferencia)
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div id="mesa-input-group" style="display: none; margin-top: 20px; padding: 10px; background: #eee; border-radius: 5px;">
                <label for="mesa_numero">Número de Mesa / Nombre para el pedido:</label>
                <input type="text" id="mesa_numero" name="mesa_numero" placeholder="Ej: Mesa 5 o Juan Pérez">
            </div>

            <input type="hidden" name="action" value="place_order">
            <input type="hidden" name="cliente_id" value="<?= $cliente_id_actual ?>">
            <input type="hidden" id="metodo_pago_input" name="metodo_pago" value="">
            <input type="hidden" id="tipo_pedido_input" name="tipo" value="">
            <input type="hidden" id="cart_data_input" name="cart_data" value="">

            <button type="submit" id="place-order-btn" class="btn btn-success" disabled>Confirmar Pedido</button>
            <button type="button" class="btn btn-secondary" onclick="closeCheckoutModal()">Cancelar</button>
        </div>
    </form>
</div>

<div id="toast-container"></div>

<script>
    // Variables de inicialización
    let cart = {}; 
    let selectedOrderType = '';
    const modificationsModal = document.getElementById('modificationsModal');
    const checkoutModal = document.getElementById('checkoutModal');
    
    // El PHP ahora exporta las modificaciones AGRUPADAS POR NOMBRE DE CATEGORÍA
    const PRODUCT_DATA = <?= $productos_json ?>.reduce((acc, p) => { acc[p.id] = p; return acc; }, {});
    const MODIFICATIONS_DATA = <?= $modificaciones_json ?>;

    // Estado del modal de modificaciones
    let selectedModificationsQuantities = {};
    let currentModProduct = {
        id: null,
        basePrice: 0,
        quantity: 1, 
        modGroups: {}
    };

    // Funciones auxiliares
    const formatCurrency = (number) => {
        // Asegura que el número sea un valor finito antes de formatear
        if (!isFinite(number) || isNaN(number)) {
            return '$0,00'; 
        }
        return '$' + new Intl.NumberFormat('es-AR', { minimumFractionDigits: 2 }).format(number);
    };
    
    // =========================================================== 
    // FUNCIÓN TOAST DE MEJORA UX 
    // =========================================================== 
    function showToast(message, type = 'success', duration = 3000) {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        let icon = '';
        
        switch (type) {
            case 'success':
                icon = '<i class="fas fa-check-circle" style="color: var(--color-success);"></i>';
                toast.classList.add('toast-success');
                break;
            case 'warning':
                icon = '<i class="fas fa-exclamation-triangle" style="color: var(--color-warning);"></i>';
                toast.classList.add('toast-warning');
                break;
            case 'error':
                icon = '<i class="fas fa-times-circle" style="color: var(--color-danger);"></i>';
                toast.classList.add('toast-error');
                break;
            default:
                icon = '<i class="fas fa-info-circle" style="color: var(--color-primary);"></i>';
        }
        
        toast.className = `toast ${toast.className}`;
        toast.innerHTML = `${icon}<span>${message}</span>`;
        
        container.appendChild(toast);
        
        // Mostrar con transición
        setTimeout(() => toast.classList.add('show'), 10); 

        // Ocultar y eliminar después de la duración
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                toast.remove();
            }, 500); // Espera que termine la transición de salida
        }, duration);
    }
    
    // Reemplazamos la función de alerta anterior
    function showStatusMessage(message, type) {
        showToast(message, type);
    }


    function loadCart() {
        try {
            const storedCart = localStorage.getItem('kiosco_cart');
            if (storedCart) {
                cart = JSON.parse(storedCart);
            } else {
                cart = {};
            }
        } catch (e) {
            console.error("Error cargando el carrito:", e);
            cart = {};
            showToast("Error al cargar el carrito. Se ha reiniciado.", 'error');
        }
    }

    function saveCart() {
        localStorage.setItem('kiosco_cart', JSON.stringify(cart));
        renderCart();
    }

    // =========================================================== 
    // LÓGICA DE RESTRICCIÓN DE CANTIDAD POR GRUPO 
    // =========================================================== 

    function enforceGroupQuantityConstraint(groupName) {
        if (groupName === 'Opciones Adicionales' || !currentModProduct.modGroups[groupName]) return true; 

        const groupData = currentModProduct.modGroups[groupName];
        const groupMax = groupData.mod_max_quantity;
        let totalSelected = 0;
        let inputs = [];

        groupData.items.forEach(modItem => {
            const modId = modItem.id;
            const modState = selectedModificationsQuantities[modId];
            if (modState && modState.group_name === groupName) { 
                totalSelected += modState.quantity;
                
                const inputElement = document.querySelector(`.mod-item-quantity-controls[data-mod-id="${modId}"] .mod-item-quantity-input`);
                if (inputElement) inputs.push(inputElement);
            }
        });
        
        const remainingLimit = groupMax - totalSelected;

        inputs.forEach(input => {
            const modId = parseInt(input.dataset.modId);
            const modState = selectedModificationsQuantities[modId];
            if (!modState) return;

            const currentQty = modState.quantity;
            
            const maxFromGroupConstraint = currentQty + remainingLimit;
            const individualMax = modState.max;
            
            const finalMax = Math.min(individualMax, maxFromGroupConstraint); 

            const plusButton = input.closest('.mod-item-quantity-controls').querySelector('[data-action="plus"]');
            if (currentQty >= finalMax) {
                plusButton.disabled = true;
            } else {
                plusButton.disabled = false;
            }

            const minusButton = input.closest('.mod-item-quantity-controls').querySelector('[data-action="minus"]');
            const minQty = modState.tipo === 'obligatorio' ? 1 : 0;
            if (currentQty <= minQty) {
                minusButton.disabled = true;
            } else {
                minusButton.disabled = false;
            }
            
            const optionContainer = input.closest('.modification-option');
            if (finalMax === 0 && currentQty === 0) {
                 optionContainer.style.opacity = '0.5';
            } else {
                 optionContainer.style.opacity = '1';
            }
        });

        calculateModPrice(); 
        return totalSelected <= groupMax; 
    }

    function updateModItemQuantity(button) {
        const modControls = button.closest('.mod-item-quantity-controls');
        const modId = modControls.dataset.modId;
        const action = button.dataset.action;
        const input = modControls.querySelector('.mod-item-quantity-input');
        
        const modState = selectedModificationsQuantities[modId];
        if (!modState) return;
        
        const groupName = modState.group_name; 
        const groupData = currentModProduct.modGroups[groupName];
        const groupMax = (groupData || {}).mod_max_quantity || 999;
        
        let currentQty = modState.quantity;
        const minQty = modState.tipo === 'obligatorio' ? 1 : 0;

        let totalInGroup = 0;
        if (groupName !== 'Opciones Adicionales' && groupData) {
            groupData.items.forEach(item => {
                const itemState = selectedModificationsQuantities[item.id];
                if (itemState) totalInGroup += itemState.quantity;
            });
        }
        
        let newQty = currentQty;
        
        if (action === 'plus') {
            newQty = currentQty + 1;
            
            if (newQty > modState.max) {
                showToast(`Límite individual alcanzado para ${modState.name}: ${modState.max}.`, 'warning');
                return;
            }
            if (groupName !== 'Opciones Adicionales' && totalInGroup >= groupMax) {
                 showToast(`Se alcanzó el límite de opciones del grupo "${groupName}" (${groupMax} en total).`, 'warning');
                 return;
            }

        } else if (action === 'minus') {
            newQty = currentQty - 1;
            if (newQty < minQty) {
                return;
            }
        }

        modState.quantity = newQty;
        input.value = newQty;
        
        enforceGroupQuantityConstraint(groupName);

        calculateModPrice();
    }


    // -----------------------------------------------------------
    // Lógica del Modal de Modificaciones
    // -----------------------------------------------------------

    /**
     * Abre el modal de modificaciones para un producto.
     */
    function openModificationsModal(productData) {
        console.log(`[DEBUG] Abriendo modal para: ${productData.nombre}`);
        
        try {
            // Reiniciar estado
            selectedModificationsQuantities = {};
            currentModProduct.id = productData.id;
            currentModProduct.basePrice = parseFloat(productData.precio);
            currentModProduct.quantity = 1; 

            const productMods = MODIFICATIONS_DATA[productData.id];
            currentModProduct.modGroups = productMods || {};
            
            document.getElementById('mod-product-name').textContent = productData.nombre;
            document.getElementById('mod-quantity-display').textContent = currentModProduct.quantity;
            
            const optionsContainer = document.getElementById('modifications-options-container');
            optionsContainer.innerHTML = '';
            
            for (const groupName in productMods) { 
                const groupData = productMods[groupName];
                const maxQty = groupData.mod_max_quantity;
                
                const groupHeader = document.createElement('h4');
                groupHeader.textContent = `${groupName} (Máx: ${maxQty} opciones)`;
                optionsContainer.appendChild(groupHeader);

                groupData.items.forEach(mod => {
                    const modId = mod.id;
                    const individualMax = mod.mod_max_quantity;
                    const isObligatory = mod.tipo === 'obligatorio';
                    let priceLabel = '';
                    if (parseFloat(mod.precio_adicional) > 0) {
                        priceLabel = `<span class="mod-price-label"> (+${formatCurrency(mod.precio_adicional)} c/u)</span>`;
                    }

                    const initialQuantity = isObligatory ? Math.min(1, individualMax) : 0; 

                    selectedModificationsQuantities[modId] = {
                        id: modId,
                        name: mod.name,
                        precio_adicional: mod.precio_adicional,
                        quantity: initialQuantity,
                        max: individualMax, 
                        group_name: groupName, 
                        tipo: mod.tipo
                    };

                    const modDiv = document.createElement('div');
                    modDiv.className = `modification-option ${isObligatory ? 'mod-required' : ''}`;
                    modDiv.innerHTML = `
                        <div>${mod.name} ${priceLabel}</div>
                        <div class="mod-item-quantity-controls" data-mod-id="${modId}" data-group-name="${groupName}">
                            <button class="btn-mod-qty" data-action="minus" type="button" onclick="updateModItemQuantity(this)"><i class="fas fa-minus"></i></button>
                            <input class="mod-item-quantity-input" type="number" min="${isObligatory ? 1 : 0}" value="${initialQuantity}" readonly>
                            <button class="btn-mod-qty" data-action="plus" type="button" onclick="updateModItemQuantity(this)"><i class="fas fa-plus"></i></button>
                        </div>
                    `;
                    optionsContainer.appendChild(modDiv);
                });
            }
            
            for (const groupName in productMods) {
                 enforceGroupQuantityConstraint(groupName);
            }

            calculateModPrice(); // Calcular precio inicial
            modificationsModal.style.display = 'flex';
            
        } catch (e) {
            console.error("Error CRÍTICO al abrir el Modal de Modificaciones:", e);
            showToast(`Error al cargar opciones. Revise la consola para detalles.`, 'error');
        }
    }

    
    function updateMainQuantity(button) {
        const action = button.dataset.action;
        if (action === 'plus') {
            currentModProduct.quantity++;
        } else if (action === 'minus' && currentModProduct.quantity > 1) {
            currentModProduct.quantity--;
        }
        document.getElementById('mod-quantity-display').textContent = currentModProduct.quantity;
        calculateModPrice();
    }
    
    function calculateModPrice() {
        let basePrice = currentModProduct.basePrice;
        let modPrice = 0;
        
        for (const modId in selectedModificationsQuantities) {
            const mod = selectedModificationsQuantities[modId];
            if (mod.quantity > 0) {
                modPrice += parseFloat(mod.precio_adicional) * mod.quantity;
            }
        }
        const itemTotalUnit = basePrice + modPrice;
        const totalCost = itemTotalUnit * currentModProduct.quantity;
        
        document.getElementById('mod-base-price').textContent = formatCurrency(basePrice);
        document.getElementById('mod-total-price').textContent = formatCurrency(totalCost);
        document.getElementById('add-mod-count').textContent = currentModProduct.quantity;
    }
    
    function addModProductToCart() {
        const productMods = currentModProduct.modGroups;
        let selectedModsList = [];
        
        for (const groupName in productMods) {
            const groupData = productMods[groupName];
            
            const obligatoryItems = groupData.items.filter(item => item.tipo === 'obligatorio');
            if (obligatoryItems.length > 0) {
                const obligatorySelected = obligatoryItems.reduce((sum, item) => {
                    const modState = selectedModificationsQuantities[item.id];
                    return sum + (modState ? modState.quantity : 0);
                }, 0);
                
                if (obligatorySelected === 0) {
                    showToast(`Debes seleccionar al menos una opción obligatoria en el grupo "${groupName}".`, 'warning');
                    return; 
                }
            }
            
            if (!enforceGroupQuantityConstraint(groupName)) {
                return;
            }
        }

        let unitModPrice = 0;
        for (const modId in selectedModificationsQuantities) {
            const mod = selectedModificationsQuantities[modId];
            if (mod.quantity > 0) {
                unitModPrice += parseFloat(mod.precio_adicional) * mod.quantity;
                selectedModsList.push({
                    id: mod.id,
                    name: mod.name,
                    precio_adicional: mod.precio_adicional,
                    quantity: mod.quantity
                });
            }
        }
        
        const unitItemPrice = currentModProduct.basePrice + unitModPrice;
        const totalItemPrice = unitItemPrice * currentModProduct.quantity;

        const baseProduct = PRODUCT_DATA[currentModProduct.id];
        const uniqueId = generateUniqueCartId(baseProduct.id, selectedModsList);
        
        const addedQuantity = currentModProduct.quantity;

        if (cart[uniqueId]) {
            cart[uniqueId].quantity += addedQuantity;
            cart[uniqueId].price += totalItemPrice;
        } else {
            cart[uniqueId] = {
                id: baseProduct.id,
                name: baseProduct.nombre,
                price: totalItemPrice, 
                unitPrice: unitItemPrice, 
                quantity: addedQuantity,
                modifications: selectedModsList
            };
        }

        closeModificationsModal();
        saveCart();
        showToast(`${addedQuantity} x ${baseProduct.nombre} agregado al pedido.`, 'success');
    }
    
    function generateUniqueCartId(productId, selectedModsList) {
        const modKey = selectedModsList
            .map(mod => `${mod.id}:${mod.quantity}`)
            .sort()
            .join('-');
        return `${productId}_${modKey}`;
    }

    function closeModificationsModal() {
        modificationsModal.style.display = 'none';
        selectedModificationsQuantities = {};
        currentModProduct.id = null;
        currentModProduct.quantity = 1;
    }

    // --- Lógica de Carrito y Renderización ---
    function addToCart(productElement) {
        console.log("[DEBUG] Click en producto. Ejecutando addToCart.");
        const id = productElement.getAttribute('data-id');
        const hasMods = productElement.getAttribute('data-has-mods') === 'true';
        const productData = PRODUCT_DATA[id];

        if (hasMods) {
            openModificationsModal(productData);
        } else {
            const uniqueId = id + '_no_mods';
            const price = parseFloat(productData.precio);
            
            if (cart[uniqueId]) {
                cart[uniqueId].quantity += 1;
                cart[uniqueId].price += price; 
            } else {
                cart[uniqueId] = {
                    id: productData.id,
                    name: productData.nombre,
                    price: price, 
                    unitPrice: price, 
                    quantity: 1,
                    modifications: []
                };
            }
            saveCart();
            showToast(`${productData.nombre} agregado al pedido.`, 'success');
        }
    }

    function updateCartQuantity(uniqueId, action) {
        if (!cart[uniqueId]) return;

        const item = cart[uniqueId];
        const unitPrice = item.unitPrice; // Usamos el unitPrice guardado
        
        if (action === 'plus') {
            item.quantity += 1;
            item.price += unitPrice;
            showToast(`Añadido 1x ${item.name}.`, 'success', 1500);
        } else if (action === 'minus') {
            item.quantity -= 1;
            item.price -= unitPrice;
            showToast(`Quitado 1x ${item.name}.`, 'warning', 1500);
        }

        if (item.quantity <= 0) {
            removeItem(uniqueId);
        } else {
            saveCart();
        }
    }

    function removeItem(uniqueId) {
        const itemName = cart[uniqueId].name;
        delete cart[uniqueId];
        saveCart();
        showToast(`${itemName} eliminado del carrito.`, 'error');
    }

    function clearCart() {
        if (confirm('¿Estás seguro que deseas vaciar el carrito?')) {
            cart = {};
            localStorage.removeItem('kiosco_order_type'); // También reiniciamos el tipo de pedido para forzar la re-selección
            saveCart(); 
            initializeAppDisplay(); 
            showToast('Carrito vacío. Reiniciando tipo de pedido.', 'error');
        }
    }
    
    // =========================================================== 
    // FUNCIÓN DE LÓGICA RESPONSIVE (Móvil)
    // =========================================================== 
    
    function isMobile() {
        return window.innerWidth <= 768;
    }

    function toggleMobileCart() {
        const cartDrawer = document.getElementById('cart-drawer');
        const isCurrentlyOpen = cartDrawer.style.display === 'flex';
        
        if (isMobile()) {
            cartDrawer.style.display = isCurrentlyOpen ? 'none' : 'flex';
            document.body.style.overflow = isCurrentlyOpen ? 'auto' : 'hidden'; // Evita el scroll del cuerpo detrás del modal
            document.getElementById('mobile-cart-button').style.display = isCurrentlyOpen ? 'flex' : 'none';
        }
    }
    
    /**
     * RENDERIZA EL CARRITO y actualiza el badge móvil.
     */
    function renderCart() {
        const cartItemsContainer = document.getElementById('cart-items');
        const subtotalDisplay = document.getElementById('cart-subtotal');
        const totalDisplay = document.getElementById('cart-total');
        const checkoutBtn = document.getElementById('checkout-btn');
        const emptyMessage = document.getElementById('empty-cart-message');
        const cartBadge = document.getElementById('cart-count-badge');
        let totalItems = 0;

        cartItemsContainer.innerHTML = '';
        let subtotal = 0;
        
        for (const uniqueId in cart) {
            const item = cart[uniqueId];
            
            const itemPrice = parseFloat(item.price);
            if (!isFinite(itemPrice) || isNaN(itemPrice)) {
                 console.error("Error de precio en renderCart:", item.name, "Precio inválido:", item.price);
                 continue; 
            }
            
            subtotal += itemPrice;
            totalItems += item.quantity;

            let modHtml = '';
            if (item.modifications && item.modifications.length > 0) {
                modHtml = `<div class="cart-item-mods">`;
                item.modifications.forEach(m => {
                    const totalModPrice = parseFloat(m.precio_adicional) * m.quantity;
                    const qtyLabel = m.quantity > 1 ? ` (x${m.quantity})` : '';
                    const priceLabel = totalModPrice > 0 ? ` +${formatCurrency(totalModPrice)}` : '';
                    modHtml += `<span>- ${m.name}${qtyLabel}</span>`; 
                });
                modHtml += `</div>`;
            }

            const itemDiv = document.createElement('div');
            itemDiv.className = 'cart-item';
            
            const unitPriceHtml = `<span class="cart-item-unit-price">(${formatCurrency(item.unitPrice)} c/u)</span>`;
            
            itemDiv.innerHTML = `
                <div class="cart-item-info">
                    <div class="cart-item-name">${item.name} ${unitPriceHtml}</div>
                    ${modHtml}
                </div>
                <div class="cart-quantity-controls">
                    <button onclick="updateCartQuantity('${uniqueId}', 'minus')"><i class="fas fa-minus"></i></button>
                    <span>${item.quantity}</span>
                    <button onclick="updateCartQuantity('${uniqueId}', 'plus')"><i class="fas fa-plus"></i></button>
                </div>
                <div class="cart-item-total">${formatCurrency(item.price)}</div>
            `;
            cartItemsContainer.appendChild(itemDiv);
        }
        
        // Actualización del DOM
        subtotalDisplay.textContent = formatCurrency(subtotal);
        totalDisplay.textContent = formatCurrency(subtotal);
        
        if (subtotal <= 0) {
             emptyMessage.style.display = 'block';
             checkoutBtn.disabled = true;
             cartBadge.style.display = 'none';
        } else {
             emptyMessage.style.display = 'none';
             checkoutBtn.disabled = false;
             cartBadge.textContent = totalItems;
             cartBadge.style.display = 'block';
             
             // Si estamos en móvil y el carrito está cerrado, mostramos el botón flotante
             if (isMobile() && document.getElementById('cart-drawer').style.display !== 'flex') {
                 document.getElementById('mobile-cart-button').style.display = 'flex';
             }
        }
        
        if (isMobile()) {
            document.getElementById('cart-header-desktop').style.display = 'none';
            document.getElementById('cart-header-mobile').style.display = 'flex';
        } else {
            document.getElementById('cart-header-desktop').style.display = 'block';
            document.getElementById('cart-header-mobile').style.display = 'none';
        }
    }


    // --- Lógica de Checkout ---
    function openCheckoutModal() {
        document.getElementById('modal-cart-total').textContent = document.getElementById('cart-total').textContent;
        document.getElementById('modal-order-type').textContent = selectedOrderType;
        document.getElementById('tipo_pedido_input').value = selectedOrderType;
        document.getElementById('cart_data_input').value = JSON.stringify(cart);

        document.getElementById('mesa-input-group').style.display = (selectedOrderType === 'MESA' || selectedOrderType === 'LLEVAR') ? 'block' : 'none';

        document.getElementById('place-order-btn').disabled = true;
        
        document.querySelectorAll('.payment-option').forEach(el => el.classList.remove('selected'));
        document.getElementById('metodo_pago_input').value = '';

        checkoutModal.style.display = 'flex';
    }

    function closeCheckoutModal() {
        checkoutModal.style.display = 'none';
    }
    
    function selectPaymentMethod(element) {
        document.querySelectorAll('.payment-option').forEach(el => el.classList.remove('selected'));
        element.classList.add('selected');
        const method = element.getAttribute('data-method');
        document.getElementById('metodo_pago_input').value = method;
        document.getElementById('place-order-btn').disabled = Object.keys(cart).length === 0;
    }
    
    // --- Lógica de Inicio de App ---
    function initializeAppDisplay() {
        selectedOrderType = localStorage.getItem('kiosco_order_type');

        if (selectedOrderType) {
            // Actualiza display en desktop y mobile
            document.getElementById('order-type-display-desktop').textContent = selectedOrderType;
            document.getElementById('order-type-display-mobile').textContent = selectedOrderType;
            
            document.getElementById('kiosco-start-screen').style.display = 'none';
            document.getElementById('main-app-container').style.display = 'flex';
            renderCart();
            
            // Si está en móvil, oculta el carrito sidebar para empezar solo con el botón flotante
            if (isMobile()) {
                 document.getElementById('cart-drawer').style.display = 'none';
                 document.getElementById('mobile-cart-button').style.display = 'flex';
            }

        } else {
            document.getElementById('kiosco-start-screen').style.display = 'flex';
            document.getElementById('main-app-container').style.display = 'none';
            document.getElementById('mobile-cart-button').style.display = 'none';
        }
    }

    // --- Listeners y Inicialización ---
    document.addEventListener('DOMContentLoaded', () => {
        loadCart();
        
        document.querySelectorAll('.start-order-btn').forEach(button => {
            button.addEventListener('click', (event) => {
                selectedOrderType = event.currentTarget.getAttribute('data-order-type');
                localStorage.setItem('kiosco_order_type', selectedOrderType);
                initializeAppDisplay();
            });
        });
        
        initializeAppDisplay();
        
        // Listener para redimensionar y ajustar la vista del carrito
        window.addEventListener('resize', () => {
             if (!selectedOrderType) return;
             
             // Si pasa de móvil a desktop
             if (!isMobile()) {
                 document.getElementById('cart-drawer').style.display = 'flex';
                 document.getElementById('mobile-cart-button').style.display = 'none';
                 document.body.style.overflow = 'auto'; 
             } else {
                 // Si pasa de desktop a móvil y no está abierto, se oculta el sidebar y se muestra el botón
                 if (document.getElementById('cart-drawer').style.display === 'flex' && !window.matchMedia("(min-width: 769px)").matches) {
                    document.getElementById('cart-drawer').style.display = 'none';
                    document.getElementById('mobile-cart-button').style.display = 'flex';
                 }
             }
             renderCart(); // Re-renderiza para ajustar los headers de carrito
        });
    });
    
    // Cerrar modales al hacer click fuera de ellos
    window.onclick = function(event) {
        if (event.target === checkoutModal) {
            closeCheckoutModal();
        }
        if (event.target === modificationsModal) {
            closeModificationsModal();
        }
    }
</script>
</body>
</html>
