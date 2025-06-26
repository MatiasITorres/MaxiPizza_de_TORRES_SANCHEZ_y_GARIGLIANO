<?php
session_start();
require_once './../config.php'; // Conexión a la DB

// Seguridad: Asegurarse de que solo un cocinero logueado pueda acceder
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'cocinero') {
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Acceso no autorizado']);
    exit();
}

// La misma consulta que tenías en tu dashboard original
$pedidos = [];
$sql = "SELECT
            p.id AS pedido_id,
            p.fecha,
            p.estado AS estado_pedido_principal,
            u.email AS cliente_email,
            p.nombre_cliente_calle,
            p.ubicacion_cliente_calle,
            p.telefono_cliente_calle,
            pp.id AS pedido_producto_id,
            prod.nombre AS producto_nombre,
            pp.cantidad,
            pp.estado AS estado_producto_individual
        FROM pedidos p
        LEFT JOIN usuarios u ON p.cliente_id = u.id
        JOIN pedido_productos pp ON p.id = pp.pedido_id
        JOIN productos prod ON pp.producto_id = prod.id
        WHERE p.estado IN ('pendiente', 'en_preparacion')
        ORDER BY p.fecha ASC, p.id ASC";

$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pedido_id = $row['pedido_id'];
        if (!isset($pedidos[$pedido_id])) {
            $pedidos[$pedido_id] = [
                'pedido_id' => $pedido_id, // Añadimos el id para facilidad en JS
                'fecha' => date('d/m/Y H:i', strtotime($row['fecha'])),
                'cliente_info' => $row['cliente_email']
                    ? htmlspecialchars($row['cliente_email'])
                    : 'A la calle: ' . htmlspecialchars($row['nombre_cliente_calle']) . 
                      ' | Ubicación: ' . htmlspecialchars($row['ubicacion_cliente_calle']) .
                      ' | Teléfono: ' . htmlspecialchars($row['telefono_cliente_calle']),
                'estado_pedido_principal' => $row['estado_pedido_principal'],
                'items' => []
            ];
        }
        $pedidos[$pedido_id]['items'][] = [
            'pedido_producto_id' => $row['pedido_producto_id'],
            'producto_nombre' => $row['producto_nombre'],
            'cantidad' => $row['cantidad'],
            'estado_producto_individual' => $row['estado_producto_individual']
        ];
    }
}

$conn->close();

// Devolver los datos como JSON
header('Content-Type: application/json');
// Usamos array_values para que en JSON sea un array y no un objeto
echo json_encode(array_values($pedidos));
?>