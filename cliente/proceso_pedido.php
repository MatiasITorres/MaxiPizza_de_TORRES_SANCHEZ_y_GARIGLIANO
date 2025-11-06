<?php
// proceso_pedido.php - Maneja la inserción y confirmación de pedidos en la base de datos

session_start();
// Asegúrate de que esta ruta a config.php sea correcta
require_once __DIR__ . '/../config.php'; 

// 1. Verificación de Sesión y Rol
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'cliente') {
    header("Location: ./../index.php");
    exit();
}

// Conexión a la Base de Datos
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    // En un entorno de producción, esta línea debería ser solo un log
    die("Error de conexión: " . $conn->connect_error);
}

// 2. Procesar el Pedido
if ($_SERVER['REQUEST_METHOD'] === 'POST') { // Solo verificamos si es POST aquí para manejar múltiples acciones

    // ------------------------------------------------------------------------
    // A. Lógica para COLOCAR ORDEN (PLACE_ORDER)
    // ------------------------------------------------------------------------
    if (isset($_POST['action']) && $_POST['action'] === 'place_order') {
        
        $cliente_id = (int)$_POST['cliente_id'];
        // Sanitiza y escapa los datos de entrada
        $pedido_tipo = $conn->real_escape_string($_POST['pedido_tipo']);
        $metodo_pago = $conn->real_escape_string($_POST['metodo_pago']);
        $cart_data_json = $_POST['cart_data'];
    
        // ... (Lógica de decodificación y validación existente)
        
        // NOTA: Se asume que $total_final se calcula aquí (aunque no se ve en el snippet, es necesario)
        $total_final = floatval($_POST['total_final'] ?? 0.00); 
        $productos_para_stock = []; 
        
        // Iniciar Transacción para asegurar la integridad de los datos
        $conn->begin_transaction();
    
        try {
            // ... (Lógica de validación de precios y stock existente)
            
            // ------------------------------------------------------------------------
            // **MODIFICACIÓN CLAVE: Determinar el estado inicial del pedido y el campo 'pagado'**
            // ------------------------------------------------------------------------
            $metodo_pago_clean = strtolower(trim($metodo_pago)); 
            $is_paid = 0; // 0 = Pendiente de pago
            
            if ($metodo_pago_clean === 'efectivo') {
                $initial_status = 'en_preparacion'; 
                $is_paid = 1; // 1 = Pagado (Se paga al recibir o retirar)
            } else {
                $initial_status = 'pendiente_pago'; // Nuevo estado para pagos electrónicos
                $is_paid = 0; 
            }
            // ------------------------------------------------------------------------
            
            // C. Inserción del Pedido (Se añade la columna 'pagado')
            $sql_pedido = "INSERT INTO pedidos (cliente_id, fecha_pedido, total, estado, pedido_tipo, metodo_pago, pagado) 
                           VALUES (?, NOW(), ?, ?, ?, ?, ?)"; 
            $stmt_pedido = $conn->prepare($sql_pedido);
            if (!$stmt_pedido) { throw new Exception("Error al preparar la consulta de pedido: " . $conn->error); }
            
            // Los tipos son: int, float, string, string, string, int (i d s s s i)
            $stmt_pedido->bind_param("idsssi", $cliente_id, $total_final, $initial_status, $pedido_tipo, $metodo_pago, $is_paid);
            $stmt_pedido->execute();
            $pedido_id = $conn->insert_id;
            $stmt_pedido->close();
            
            // ... (Lógica de inserción de detalle y descuento de stock existente)
            
            // D. Finalizar la Transacción
            $conn->commit();
            
            // 3. Redirección Final al Panel de Cliente con Mensaje de Éxito
            $total_format = number_format($total_final, 2, ',', '.');
            
            if ($is_paid === 1) {
                $success_msg = "¡Tu pedido #{$pedido_id} ha sido tomado y está en preparación! Pagarás \${$total_format} en efectivo.";
                header("Location: cliente_dashboard.php?success_msg=" . urlencode($success_msg) . "&clear_cart=1");
                exit();
            } 
            
            // Redirección para pagos pendientes: enviamos el ID y total para la confirmación
            $success_msg = "Pedido #{$pedido_id} creado. Por favor, completa el pago de \${$total_format} por {$metodo_pago}.";
            header("Location: cliente_dashboard.php?success_msg=" . urlencode($success_msg) . "&clear_cart=1&pedido_id={$pedido_id}&total={$total_final}&pago_pendiente=1&metodo_pago_url=" . urlencode($metodo_pago));
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            // Redirigir con mensaje de error
            header("Location: cliente_dashboard.php?error_msg=" . urlencode("Error al procesar el pedido: " . $e->getMessage()));
            exit();
        }
    } 
    
    // ------------------------------------------------------------------------
    // B. **LÓGICA: CONFIRMACIÓN DE PAGO (CONFIRM_PAYMENT) - AHORA USA 'pagado'**
    // ------------------------------------------------------------------------
    elseif (isset($_POST['action']) && $_POST['action'] === 'confirm_payment') {
        
        $cliente_id = (int)($_SESSION['usuario_id'] ?? 1); // Usar ID de sesión o ID de quiosco por defecto
        $pedido_id = (int)$_POST['pedido_id'];
        $metodo_pago = $conn->real_escape_string($_POST['metodo_pago'] ?? 'Desconocido');

        if ($pedido_id <= 0 || $cliente_id <= 0) {
            header("Location: cliente_dashboard.php?error_msg=" . urlencode("Datos no válidos para la confirmación de pago."));
            exit();
        }

        try {
            // 1. Verificar estado actual del pedido, propiedad y columna 'pagado'
            $sql_check = "SELECT estado, total, pagado FROM pedidos WHERE id = ? AND cliente_id = ?";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->bind_param("ii", $pedido_id, $cliente_id);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            
            $pedido = $result_check->fetch_assoc();
            
            if (!$pedido) {
                throw new Exception("Pedido no encontrado o no pertenece a este cliente.");
            }
            
            // Condición de confirmación: debe estar pendiente de pago y no marcado como pagado (pagado = 0)
            if ($pedido['estado'] === 'pendiente_pago' && (int)($pedido['pagado'] ?? 0) === 0) {
                
                // 2. Actualizar el estado a 'en_preparacion' Y establecer 'pagado' a 1
                $sql_update = "UPDATE pedidos SET estado = 'en_preparacion', pagado = 1 WHERE id = ?";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->bind_param("i", $pedido_id);
                
                if ($stmt_update->execute()) {
                    $total_format = number_format($pedido['total'], 2, ',', '.');
                    // Mensaje de confirmación (el empleado ve el cambio de estado)
                    $success_msg = "✅ ¡Pago de pedido #{$pedido_id} CONFIRMADO! Tu pedido de \${$total_format} por {$metodo_pago} ahora está en preparación.";
                    header("Location: cliente_dashboard.php?success_msg=" . urlencode($success_msg) . "&confirmado_id={$pedido_id}");
                    exit();
                } else {
                    throw new Exception("Error al actualizar el estado del pago.");
                }
                
            } else {
                 // Mensaje si ya se pagó o está en otro estado
                 $status_display = (int)($pedido['pagado'] ?? 0) === 1 ? 'pagado' : 'en estado ' . $pedido['estado'];
                 $success_msg = "El pedido #{$pedido_id} ya se encuentra $status_display y no requiere confirmación.";
                 header("Location: cliente_dashboard.php?success_msg=" . urlencode($success_msg));
                 exit();
            }
            
        } catch (Exception $e) {
            header("Location: cliente_dashboard.php?error_msg=" . urlencode("Error al confirmar el pago: " . $e->getMessage()));
            exit();
        }
    }
}
// Cierre de conexión a la BD
if (isset($conn) && $conn->ping()) {
    $conn->close();
}
?>