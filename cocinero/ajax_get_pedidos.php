<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php'; // Asegúrate de que esta ruta sea correcta

header('Content-Type: application/json'); // Siempre devuelve JSON

// Validar sesión y rol
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'cocinero') {
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado.']);
    exit();
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos: ' . $conn->connect_error]);
    exit();
}

$pedidos_data = [];
$pedidos_agrupados = [];

try {
    // Consulta para obtener todos los detalles de los pedidos y sus productos individuales,
    // incluyendo información del cliente (registrado o a la calle)
    $sql = "SELECT
                p.id AS pedido_id,
                p.fecha,
                p.total,
                p.estado AS estado_pedido_principal,
                p.nota_pedido,
                pp.id AS pedido_producto_id,
                pp.cantidad,
                prod.nombre AS producto_nombre,
                pp.precio_unitario,
                pp.subtotal,
                pp.estado AS estado_producto_individual,
                COALESCE(u.nombre, p.nombre_cliente_calle) AS cliente_nombre,
                COALESCE(u.ubicacion, p.ubicacion_cliente_calle) AS cliente_ubicacion,
                COALESCE(u.telefono, p.telefono_cliente_calle) AS cliente_telefono,
                p.cliente_id -- Para determinar si es un cliente registrado o a la calle
            FROM pedidos p
            JOIN pedido_productos pp ON p.id = pp.pedido_id
            JOIN productos prod ON pp.producto_id = prod.id
            LEFT JOIN usuarios u ON p.cliente_id = u.id
            WHERE p.estado IN ('pendiente', 'en_preparacion', 'listo') -- El cocinero solo ve estos estados
            ORDER BY p.fecha ASC, p.id ASC"; // Ordena por fecha para ver los más antiguos primero (normalmente para un cocinero)

    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception("Error al ejecutar la consulta SQL: " . $conn->error);
    }

    while ($row = $result->fetch_assoc()) {
        $pedido_id = $row['pedido_id'];

        // Agrupar los productos bajo su pedido principal
        if (!isset($pedidos_agrupados[$pedido_id])) {
            $pedidos_agrupados[$pedido_id] = [
                'pedido_id' => $row['pedido_id'],
                'fecha' => $row['fecha'],
                'total' => $row['total'],
                'estado_pedido_principal' => $row['estado_pedido_principal'],
                'nota_pedido' => $row['nota_pedido'],
                'cliente_id' => $row['cliente_id'], // Puede ser NULL para cliente a la calle
                'cliente_nombre' => $row['cliente_nombre'],
                'cliente_ubicacion' => $row['cliente_ubicacion'],
                'cliente_telefono' => $row['cliente_telefono'],
                'nombre_cliente_calle' => $row['nombre_cliente_calle'], // Mantener por si acaso, aunque COALESCE lo gestiona
                'ubicacion_cliente_calle' => $row['ubicacion_cliente_calle'],
                'telefono_cliente_calle' => $row['telefono_cliente_calle'],
                'items' => []
            ];
        }

        // Añadir el producto individual al array de items del pedido
        $pedidos_agrupados[$pedido_id]['items'][] = [
            'pedido_producto_id' => $row['pedido_producto_id'],
            'producto_nombre' => $row['producto_nombre'],
            'cantidad' => $row['cantidad'],
            'precio_unitario' => $row['precio_unitario'],
            'subtotal' => $row['subtotal'],
            'estado_producto_individual' => $row['estado_producto_individual']
        ];
    }

    // Convertir el array asociativo a un array indexado numéricamente para JSON
    $pedidos_data = array_values($pedidos_agrupados);

    echo json_encode(['status' => 'success', 'pedidos' => $pedidos_data]);

} catch (Exception $e) {
    error_log("Error en ajax_get_pedidos.php: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error al obtener pedidos: ' . $e->getMessage()]);
} finally {
    $conn->close();
}
?>
