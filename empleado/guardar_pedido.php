<?php
session_start();
require_once './../config.php'; // Asegúrate de que la ruta a tu archivo de conexión sea correcta.

header('Content-Type: application/json');

// 1. Verificación de Seguridad y Autenticación
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['place_order'])) {
    echo json_encode(['success' => false, 'message' => 'Solicitud no válida.']);
    exit;
}
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'empleado') {
    echo json_encode(['success' => false, 'message' => 'Acceso no autorizado.']);
    exit;
}

// 2. Recepción y Validación de Datos
$cliente_id = filter_input(INPUT_POST, 'cliente_id', FILTER_VALIDATE_INT);
$productos_data = json_decode($_POST['productos_json'] ?? '[]', true);

// Usar htmlspecialchars en lugar del obsoleto FILTER_SANITIZE_STRING
$nombre_calle = $cliente_id === 0 ? trim(htmlspecialchars($_POST['nombre_cliente_calle'] ?? '')) : null;
$ubicacion_calle = $cliente_id === 0 ? trim(htmlspecialchars($_POST['ubicacion_cliente_calle'] ?? '')) : null;
$telefono_calle = $cliente_id === 0 ? trim(htmlspecialchars($_POST['telefono_cliente_calle'] ?? '')) : null;

if (empty($productos_data)) {
    echo json_encode(['success' => false, 'message' => 'El pedido no puede estar vacío.']);
    exit;
}
if ($cliente_id === 0 && (empty($nombre_calle) || empty($ubicacion_calle))) {
    echo json_encode(['success' => false, 'message' => 'Debe completar el nombre y la ubicación para clientes no registrados.']);
    exit;
}

$conn->begin_transaction();

try {
    // 3. Cálculo del Total en el Servidor (para evitar manipulación de precios)
    $total_pedido = 0;
    $productos_finales = [];
    $ids_productos = array_column($productos_data, 'producto_id');
    
    if(!empty($ids_productos)) {
        $placeholders = implode(',', array_fill(0, count($ids_productos), '?'));
        $stmt_precios = $conn->prepare("SELECT id, precio FROM productos WHERE id IN ($placeholders)");
        $stmt_precios->bind_param(str_repeat('i', count($ids_productos)), ...$ids_productos);
        $stmt_precios->execute();
        $precios_db = $stmt_precios->get_result()->fetch_all(MYSQLI_ASSOC);
        $precios_map = array_column($precios_db, 'precio', 'id');

        foreach ($productos_data as $producto) {
            $id = $producto['producto_id'];
            $cantidad = $producto['cantidad'];
            if (!isset($precios_map[$id]) || $cantidad <= 0) {
                throw new Exception("Producto inválido (ID: $id) o cantidad incorrecta.");
            }
            $precio_unitario = $precios_map[$id];
            $subtotal = $precio_unitario * $cantidad;
            $total_pedido += $subtotal;
            $productos_finales[] = [
                'id' => $id,
                'cantidad' => $cantidad,
                'precio_unitario' => $precio_unitario,
                'subtotal' => $subtotal
            ];
        }
    }

    // 4. Inserción en la tabla `pedidos`
    $stmt_pedido = $conn->prepare(
        "INSERT INTO pedidos (cliente_id, total, estado, nombre_cliente_calle, ubicacion_cliente_calle, telefono_cliente_calle) VALUES (?, ?, 'pendiente', ?, ?, ?)"
    );
    $id_cliente_final = ($cliente_id === 0) ? null : $cliente_id;
    $stmt_pedido->bind_param('idsss', $id_cliente_final, $total_pedido, $nombre_calle, $ubicacion_calle, $telefono_calle);
    $stmt_pedido->execute();
    $pedido_id = $conn->insert_id;

    // 5. Inserción en la tabla `pedido_productos`
    $stmt_items = $conn->prepare(
        "INSERT INTO pedido_productos (pedido_id, producto_id, cantidad, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?)"
    );
    foreach ($productos_finales as $item) {
        $stmt_items->bind_param('iiidd', $pedido_id, $item['id'], $item['cantidad'], $item['precio_unitario'], $item['subtotal']);
        $stmt_items->execute();
    }

    // 6. Confirmar Transacción
    $conn->commit();
    echo json_encode(['success' => true, 'message' => "Pedido #$pedido_id creado exitosamente."]);

} catch (Exception $e) {
    $conn->rollback();
    error_log("Error al guardar pedido: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error del servidor al procesar el pedido.']);
}

$conn->close();
?>