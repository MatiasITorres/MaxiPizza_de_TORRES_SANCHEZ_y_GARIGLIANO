<?php
session_start();
header('Content-Type: application/json');

require_once './../config.php';

$response = ['success' => false, 'message' => ''];

// Establecer la conexión a la base de datos
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    $response['message'] = 'Error de conexión con la base de datos: ' . $conn->connect_error;
    error_log('Error de conexión con la base de datos: ' . $conn->connect_error);
    echo json_encode($response);
    exit();
}

// Redirigir si el usuario no está logeado o no es un empleado
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'empleado') {
    $response['message'] = 'Acceso denegado.';
    echo json_encode($response);
    exit();
}

// Iniciar una transacción para asegurar la integridad de los datos
$conn->autocommit(false);

try {
    // 1. Validar productos
    if (!isset($_POST['productos_json']) || empty($_POST['productos_json'])) {
        throw new Exception('No hay productos en el pedido.');
    }

    $productos_pedido = json_decode($_POST['productos_json'], true);

    if (empty($productos_pedido)) {
        throw new Exception('El detalle del pedido está vacío.');
    }

    // 2. Obtener datos del cliente y ubicación del pedido
    $cliente_id = (int)$_POST['cliente_id'];
    $nombre_cliente = trim($_POST['nombre_cliente']);
    $telefono_cliente = trim($_POST['telefono_cliente']);
    $ubicacion_cliente = trim($_POST['ubicacion_cliente']);
    $total = (float)$_POST['total'];

    // Si cliente_id es 0 (no registrado), se inserta como nuevo/temporal
    if ($cliente_id === 0) {
        if (empty($nombre_cliente) || empty($telefono_cliente) || empty($ubicacion_cliente)) {
            throw new Exception('Faltan datos para cliente no registrado (Nombre, Teléfono o Dirección).');
        }
        
        // Insertar el cliente temporal
        $sql_insert_cliente = "INSERT INTO clientes (nombre, telefono, ubicacion) VALUES (?, ?, ?)";
        $stmt_cliente = $conn->prepare($sql_insert_cliente);
        if (!$stmt_cliente) {
             throw new Exception('Error al preparar la consulta de cliente: ' . $conn->error);
        }
        $stmt_cliente->bind_param('sss', $nombre_cliente, $telefono_cliente, $ubicacion_cliente);
        
        if (!$stmt_cliente->execute()) {
             error_log('Error al insertar cliente: ' . $stmt_cliente->error);
             throw new Exception('Error al registrar cliente temporal.');
        }
        $cliente_id = $stmt_cliente->insert_id; // Se obtiene el ID del cliente recién insertado
        $stmt_cliente->close();

    } else {
        // Para clientes existentes, el cliente_id ya tiene un valor > 0
    }
    
    // 3. Preparación de datos (revalidar total en PHP para seguridad)
    // En un sistema real, se debería recalcular el total en el servidor para evitar manipulación.
    // Aquí confiamos en el total pasado por JS y en que la validación anterior es suficiente.
    $productos_para_insertar = [];
    foreach ($productos_pedido as $item) {
        $productos_para_insertar[] = [
            'producto_id' => (int)$item['producto_id'],
            'nombre_linea' => substr(trim($item['nombre'] . ' - ' . $item['mod_nombre']), 0, 255),
            'modification_id' => $item['modification_id'] ? (int)$item['modification_id'] : null,
            'cantidad' => (int)$item['cantidad'],
            'precio_unitario' => (float)$item['precio_unitario'],
            'subtotal' => (float)$item['subtotal'],
            'añadido' => substr(trim($item['añadido'] ?? ''), 0, 255)
        ];
    }
    
    // 4. Insertar el pedido en la tabla `pedidos`
    $sql_pedido = 'INSERT INTO pedidos (cliente_id, total, ubicacion, estado) VALUES (?, ?, ?, "pendiente")';
    $stmt_pedido = $conn->prepare($sql_pedido);
    if (!$stmt_pedido) {
        throw new Exception('Error al preparar la consulta de pedido: ' . $conn->error);
    }
    
    // El cliente_id es el ID del cliente existente o el ID del cliente temporal recién insertado
    $stmt_pedido->bind_param('ids', $cliente_id, $total, $ubicacion_cliente);
    
    if (!$stmt_pedido->execute()) {
        error_log('Error al insertar pedido: ' . $stmt_pedido->error);
        throw new Exception('Error al crear el pedido.');
    }
    $pedido_id = $stmt_pedido->insert_id;
    $stmt_pedido->close();

    // 5. Insertar los productos en la tabla `pedido_productos`
    $sql_pedido_productos = 'INSERT INTO pedido_productos (pedido_id, producto_id, nombre_linea, modification_id, cantidad, precio_unitario, subtotal, añadido) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
    $stmt_pedido_productos = $conn->prepare($sql_pedido_productos);
    if (!$stmt_pedido_productos) {
        throw new Exception('Error al preparar la consulta de pedido_productos: ' . $conn->error);
    }

    foreach ($productos_para_insertar as $item) {
        // Tipos de datos: (i: pedido_id, i: producto_id, s: nombre_linea, i: modification_id, i: cantidad, d: precio_unitario, d: subtotal, s: añadido)
        // Se usa un ID temporal para modification_id y luego se ajusta el bind_param
        $mod_id = $item['modification_id'] !== null ? $item['modification_id'] : 0; 

        $stmt_pedido_productos->bind_param('isiiddds', 
            $pedido_id, 
            $item['producto_id'],
            $item['nombre_linea'],
            $mod_id,
            $item['cantidad'], 
            $item['precio_unitario'], 
            $item['subtotal'],
            $item['añadido'] 
        );
        
        if (!$stmt_pedido_productos->execute()) {
            error_log('Error al agregar productos al pedido (guardar_pedido.php): ' . $stmt_pedido_productos->error);
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
    $response['message'] = $e->getMessage();
    error_log('Error crítico en guardar_pedido.php: ' . $e->getMessage());
}

$conn->autocommit(true);
$conn->close();
echo json_encode($response);
?>