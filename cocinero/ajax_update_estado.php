<?php
// Habilitar la visualización de errores para depuración. ¡QUITAR EN PRODUCCIÓN!
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../config.php'; // Asegúrate de que esta ruta sea correcta

header('Content-Type: application/json'); // Siempre devuelve JSON

// Esto es un endpoint AJAX, no debe redirigir si no está logueado, sino devolver un error JSON
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'cocinero') {
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado. No eres cocinero o no has iniciado sesión.']);
    exit();
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos: ' . $conn->connect_error]);
    exit();
}

$pedidos = [];

// Consulta para obtener todos los detalles de los pedidos, incluyendo información del cliente.
// Usamos LEFT JOIN con 'usuarios' porque `pedidos.cliente_id` referencia a `usuarios.id` según tu `maxipizza.sql`.
// Esto permite obtener datos de clientes registrados O pedidos a la calle (donde cliente_id es NULL).
$sql = "SELECT
            p.id AS pedido_id,
            p.cliente_id,
            p.fecha,
            p.total,
            p.estado AS estado_pedido_principal,
            p.nombre_cliente_calle,
            p.ubicacion_cliente_calle,
            p.telefono_cliente_calle,
            p.nota_pedido,

            pp.id AS pedido_producto_id,
            pp.cantidad,
            prod.nombre AS producto_nombre,
            pp.precio_unitario,
            pp.estado AS estado_producto_individual,

            u.nombre AS cliente_nombre_registrado,
            u.telefono AS cliente_telefono_registrado,
            u.ubicacion AS cliente_direccion_registrado
        FROM pedidos p
        JOIN pedido_productos pp ON p.id = pp.pedido_id
        JOIN productos prod ON pp.producto_id = prod.id
        LEFT JOIN usuarios u ON p.cliente_id = u.id
        WHERE p.estado IN ('pendiente', 'en_preparacion', 'listo')
        ORDER BY p.fecha ASC, p.id ASC";

$result = $conn->query($sql);

if ($result) {
    $temp_pedidos = [];
    while ($row = $result->fetch_assoc()) {
        $pedido_id = $row['pedido_id'];

        if (!isset($temp_pedidos[$pedido_id])) {
            $temp_pedidos[$pedido_id] = [
                'pedido_id' => $row['pedido_id'],
                'cliente_id' => $row['cliente_id'],
                'fecha' => $row['fecha'],
                'total' => $row['total'],
                'estado_pedido_principal' => $row['estado_pedido_principal'],
                'nota_pedido' => $row['nota_pedido'] ?? null,
                'items' => []
            ];

            if ($row['cliente_id'] !== null) {
                $temp_pedidos[$pedido_id]['cliente_nombre'] = $row['cliente_nombre_registrado'];
                $temp_pedidos[$pedido_id]['cliente_telefono'] = $row['cliente_telefono_registrado'];
                $temp_pedidos[$pedido_id]['cliente_direccion'] = $row['cliente_direccion_registrado'];
            } else {
                $temp_pedidos[$pedido_id]['nombre_cliente_calle'] = $row['nombre_cliente_calle'];
                $temp_pedidos[$pedido_id]['ubicacion_cliente_calle'] = $row['ubicacion_cliente_calle'];
                $temp_pedidos[$pedido_id]['telefono_cliente_calle'] = $row['telefono_cliente_calle'];
            }
        }

        $temp_pedidos[$pedido_id]['items'][] = [
            'pedido_producto_id' => $row['pedido_producto_id'],
            'producto_nombre' => $row['producto_nombre'],
            'cantidad' => $row['cantidad'],
            'estado_producto_individual' => $row['estado_producto_individual']
        ];
    }

    foreach ($temp_pedidos as $pedido) {
        if ($pedido['estado_pedido_principal'] !== 'entregado' && $pedido['estado_pedido_principal'] !== 'cancelado') {
            $pedidos[] = $pedido;
        }
    }

} else {
    echo json_encode(['status' => 'error', 'message' => 'Error al ejecutar la consulta SQL: ' . $conn->error]);
    exit();
}

$conn->close();

echo json_encode(['status' => 'success', 'pedidos' => array_values($pedidos)]);
?>