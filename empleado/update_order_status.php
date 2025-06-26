<?php
session_start();
require_once './../config.php'; // Asegúrate de que la ruta a tu archivo de conexión sea correcta.

header('Content-Type: application/json');

// 1. Verificación de Seguridad y Autenticación
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['order_id']) || empty($_POST['new_status'])) {
    echo json_encode(['success' => false, 'message' => 'Solicitud no válida.']);
    exit;
}
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'empleado') {
    echo json_encode(['success' => false, 'message' => 'Acceso no autorizado.']);
    exit;
}

// 2. Recepción y Validación de Datos
$order_id = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
$new_status = filter_input(INPUT_POST, 'new_status', FILTER_SANITIZE_STRING);

$allowed_statuses = ['pendiente', 'en_preparacion', 'listo', 'entregado', 'cancelado'];
if (!$order_id || !in_array($new_status, $allowed_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Datos inválidos proporcionados.']);
    exit;
}

try {
    // 3. Actualización en la Base de Datos
    $stmt = $conn->prepare("UPDATE pedidos SET estado = ? WHERE id = ?");
    $stmt->bind_param('si', $new_status, $order_id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => "Estado del pedido #$order_id actualizado correctamente."]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No se pudo actualizar el estado (puede que ya estuviera asignado).']);
    }

} catch (Exception $e) {
    error_log("Error al actualizar estado: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error del servidor al actualizar el estado.']);
}

$conn->close();
?>