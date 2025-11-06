<?php
// guardar_pedido.php - Procesa y guarda un pedido completo en la base de datos
session_start();
require_once __DIR__ . '/../config.php'; 

// --- 1. Conexión a la Base de Datos ---
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    // Si falla la conexión, es un error fatal de servidor.
    header("Location: cliente_dashboard.php?error_msg=" . urlencode("Error de conexión al servidor. Inténtelo más tarde."));
    exit();
}

// ------------------------------------------------------------------------
// A. LÓGICA PRINCIPAL PARA GUARDAR EL PEDIDO
// ------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'place_order') {
    
    // 1. Recibir y Sanitizar Datos
    $cliente_id = (int)($_POST['cliente_id'] ?? 1); // ID de cliente (1 por defecto para Kiosco)
    $metodo_pago = $conn->real_escape_string($_POST['metodo_pago'] ?? 'Efectivo');
    $tipo_pedido = $conn->real_escape_string($_POST['tipo'] ?? 'MESA');
    $cart_data_json = $_POST['cart_data'] ?? '[]';

    // Decodificar el carrito JSON
    $cart_items = json_decode($cart_data_json, true);
    
    if (empty($cart_items)) {
         header("Location: cliente_dashboard.php?error_msg=" . urlencode("El carrito está vacío o no se pudo decodificar."));
         exit();
    }
    
    // Calcular el total final
    $total_final = 0;
    foreach ($cart_items as $item) {
        // Usamos 'total_item_price' (Precio Unitario * Cantidad)
        $total_final += $item['total_item_price']; 
    }
    
    // 2. Iniciar Transacción
    // Esto asegura que o bien se inserta todo el pedido, o no se inserta nada.
    $conn->begin_transaction(); 
    $pedido_id = 0;
    
    try {
        // --- Paso 1: Insertar en la tabla 'pedidos' ---
        $stmt_pedido = $conn->prepare("INSERT INTO pedidos (cliente_id, fecha, total, estado, metodo_pago, tipo) VALUES (?, NOW(), ?, 'pendiente', ?, ?)");
        
        // La variable $total_final es un float, se usa 'd'
        if (!$stmt_pedido->bind_param("idss", $cliente_id, $total_final, $metodo_pago, $tipo_pedido)) {
             throw new Exception("Error al preparar la declaración de pedido: " . $stmt_pedido->error);
        }
        
        if (!$stmt_pedido->execute()) {
             throw new Exception("Error al ejecutar la inserción del pedido: " . $stmt_pedido->error);
        }
        
        $pedido_id = $conn->insert_id;
        $stmt_pedido->close();
        
        // --- Paso 2: Iterar y Insertar en 'detalle_pedido' y 'modificaciones_pedido' ---
        
        // Preparar las declaraciones fuera del bucle para optimización
        $stmt_detalle = $conn->prepare("INSERT INTO detalle_pedido (pedido_id, producto_id, cantidad, precio_unitario_total, subtotal) VALUES (?, ?, ?, ?, ?)");
        $stmt_modificacion = $conn->prepare("INSERT INTO modificaciones_pedido (detalle_id, modificacion_id, nombre, cantidad, precio_adicional) VALUES (?, ?, ?, ?, ?)");
        
        if (!$stmt_detalle || !$stmt_modificacion) {
             throw new Exception("Error al preparar declaraciones anidadas.");
        }

        foreach ($cart_items as $item) {
            $producto_id = (int)$item['id'];
            $cantidad_producto = (int)$item['quantity'];
            $precio_unitario_total = (float)$item['price']; // Precio unitario (Base + Mods)
            $subtotal_item = (float)$item['total_item_price']; // Precio Unitario * Cantidad
            $modifications = $item['modifications'] ?? [];

            // 1. Insertar en detalle_pedido
            // 'i', 'i', 'i', 'd', 'd' -> (int, int, int, float, float)
            if (!$stmt_detalle->bind_param("iiidd", $pedido_id, $producto_id, $cantidad_producto, $precio_unitario_total, $subtotal_item)) {
                 throw new Exception("Error al vincular detalle: " . $stmt_detalle->error);
            }
            
            if (!$stmt_detalle->execute()) {
                 throw new Exception("Error al insertar detalle: " . $stmt_detalle->error);
            }
            
            $detalle_id = $conn->insert_id; // Obtener el ID del detalle recién insertado
            
            // 2. Insertar modificaciones (si existen)
            foreach ($modifications as $mod) {
                // Solo si la cantidad de la modificación es mayor a 0
                if ((int)$mod['quantity'] > 0) {
                     $modificacion_id = (int)$mod['id'];
                     $mod_nombre = $mod['name'];
                     $mod_cantidad = (int)$mod['quantity'];
                     $mod_precio = (float)$mod['precio_adicional'];
                     
                     // 'i', 'i', 's', 'i', 'd' -> (int, int, string, int, float)
                     if (!$stmt_modificacion->bind_param("iisid", $detalle_id, $modificacion_id, $mod_nombre, $mod_cantidad, $mod_precio)) {
                          throw new Exception("Error al vincular modificación: " . $stmt_modificacion->error);
                     }
                     
                     if (!$stmt_modificacion->execute()) {
                          throw new Exception("Error al insertar modificación: " . $stmt_modificacion->error);
                     }
                }
            }
        }
        
        // Cerrar las declaraciones preparadas
        $stmt_detalle->close();
        $stmt_modificacion->close();

        // 3. Finalizar Transacción (COMMIT)
        $conn->commit();

        // 4. Redirigir con éxito
        $total_format = number_format($total_final, 2, ',', '.');
        $success_msg = "¡Pedido #{$pedido_id} recibido! Tu total es de \${$total_format}. Tu pago por **{$metodo_pago}** será verificado. ¡Ya comenzamos a prepararlo!";
        
        // Redirigir y limpiar el carrito
        header("Location: cliente_dashboard.php?success_msg=" . urlencode($success_msg) . "&clear_cart=1");
        exit();
        
    } catch (Exception $e) {
        // Si hay un error, deshacer todos los cambios de la transacción (ROLLBACK)
        $conn->rollback();
        
        // Redirigir con mensaje de error
        $error_msg = "Error al procesar el pedido. Revierte la transacción. Detalle: " . $e->getMessage();
        header("Location: cliente_dashboard.php?error_msg=" . urlencode($error_msg));
        exit();
    }
} else {
    // Si se accede directamente sin POST
    header("Location: cliente_dashboard.php?error_msg=" . urlencode("Acceso no válido."));
    exit();
}

$conn->close();
?>