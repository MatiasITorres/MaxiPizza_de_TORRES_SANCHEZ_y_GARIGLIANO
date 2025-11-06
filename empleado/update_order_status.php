<?php
session_start();
header('Content-Type: application/json');
require_once './../config.php';

// Redirigir si el rol no es 'empleado'
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'empleado') {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Acceso denegado.']));
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(['success' => false, 'message' => 'Error de conexión: ' . $conn->connect_error]));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id']) && isset($_POST['new_status'])) {
    $order_id = (int)$_POST['order_id'];
    $new_status = $_POST['new_status'];

    // Validar estados permitidos para prevenir inyección SQL o datos inválidos
    $allowed_statuses = ['pendiente', 'en_preparacion', 'listo', 'entregado', 'cancelado'];
    if (!in_array($new_status, $allowed_statuses)) {
        echo json_encode(['success' => false, 'message' => 'Estado inválido.']);
        $conn->close();
        exit();
    }

    try {
        $stmt = $conn->prepare("UPDATE pedidos SET estado = ? WHERE id = ?");
        if (!$stmt) {
             throw new Exception('Error al preparar la consulta: ' . $conn->error);
        }
        $stmt->bind_param("si", $new_status, $order_id);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'Estado del pedido actualizado con éxito.']);
        } else {
            // Podría ser que el estado ya sea el mismo
            echo json_encode(['success' => false, 'message' => 'No se encontró el pedido o el estado ya era el mismo.']);
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error al actualizar el estado del pedido: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error interno al actualizar el estado.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Solicitud POST inválida.']);
}

$conn->close();
?>