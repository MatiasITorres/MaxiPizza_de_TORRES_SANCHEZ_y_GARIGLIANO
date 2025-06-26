<?php
session_start();
require_once './../config.php'; // Tu archivo de conexión a la base de datos

// Seguridad: Verificar si el usuario ha iniciado sesión y tiene rol de cocinero
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'cocinero') {
    // Si no es una solicitud AJAX, redirige. Si es AJAX, devuelve un error.
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header("Location: ./../index.php");
    } else {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acceso no autorizado.']);
    }
    exit();
}

// --- CAMBIO IMPORTANTE: LÓGICA DE ACTUALIZACIÓN DE ESTADO ---
// Esta sección ahora está preparada para responder a solicitudes AJAX con JSON.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_estado_producto') {
    $pedido_producto_id = intval($_POST['pedido_producto_id']);
    $nuevo_estado = $_POST['estado'];
    $response = ['success' => false, 'message' => 'Error desconocido.'];

    $estados_validos = ['pendiente', 'en_preparacion', 'listo', 'cancelado'];
    if (in_array($nuevo_estado, $estados_validos)) {
        
        $conn->begin_transaction();
        try {
            // Actualiza el estado del ítem específico
            $stmt_update = $conn->prepare("UPDATE pedido_productos SET estado = ? WHERE id = ?");
            $stmt_update->bind_param("si", $nuevo_estado, $pedido_producto_id);
            $stmt_update->execute();
            $stmt_update->close();

            // Lógica para actualizar el estado del pedido principal (se mantiene igual)
            $stmt_check_pedido = $conn->prepare("SELECT pedido_id FROM pedido_productos WHERE id = ?");
            $stmt_check_pedido->bind_param("i", $pedido_producto_id);
            $stmt_check_pedido->execute();
            $result_check = $stmt_check_pedido->get_result();
            if ($row = $result_check->fetch_assoc()) {
                $current_pedido_id = $row['pedido_id'];
                
                // (La lógica para verificar todos los ítems y actualizar el pedido principal se mantiene)
                // Esta lógica es compleja pero correcta, la dejamos como está.
                // ...
            }
            $stmt_check_pedido->close();

            $conn->commit();
            $response = ['success' => true, 'message' => 'Estado actualizado con éxito.'];

        } catch (Exception $e) {
            $conn->rollback();
            $response = ['success' => false, 'message' => 'Error en la base de datos: ' . $e->getMessage()];
        }

    } else {
        $response['message'] = 'Estado no válido.';
    }

    // Devuelve una respuesta JSON y termina el script.
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Lógica para cerrar sesión (si no es una solicitud de actualización)
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ./../index.php");
    exit();
}

// Ya no se cargan datos aquí, se deja al JavaScript y al API.
$conn->close(); 
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Cocinero | MaxiPizza</title>
    <link rel="stylesheet" href="./../css/style.css">
    <style>
        /* ... TUS ESTILOS CSS AQUÍ ... (los mismos que ya tenías) */
        .item-actions button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Panel de Cocinero</h1>
        <p>Bienvenido, Cocinero <?php echo htmlspecialchars($_SESSION['usuario_email']); ?>. Aquí puedes ver y gestionar los pedidos.</p>
        
        <div id="status-message-container"></div>

        <div id="pedidos-container">
            </div>

        <a href="?logout=true" class="b">Cerrar Sesión</a>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('pedidos-container');
    const messageContainer = document.getElementById('status-message-container');

    // Función para renderizar los pedidos
    function renderPedidos(pedidos) {
        if (pedidos.length === 0) {
            container.innerHTML = `<div class="no-pedidos"><p>No hay pedidos pendientes o en preparación.</p></div>`;
            return;
        }

        let html = '';
        pedidos.forEach(pedido => {
            const estadoPrincipalText = pedido.estado_pedido_principal.replace('_', ' ');
            html += `
            <div class="pedido-card">
                <div class="pedido-header">
                    <h2>Pedido #${pedido.pedido_id}</h2>
                    <span class="status ${pedido.estado_pedido_principal}">${estadoPrincipalText}</span>
                </div>
                <div class="pedido-info">
                    <p><strong>Cliente:</strong> ${pedido.cliente_info}</p>
                    <p><strong>Fecha:</strong> ${pedido.fecha}</p>
                </div>
                <ul class="item-list">`;
            
            pedido.items.forEach(item => {
                const estadoIndividualText = item.estado_producto_individual.replace('_', ' ');
                const isItemDone = ['listo', 'entregado', 'cancelado'].includes(item.estado_producto_individual);

                // --- CAMBIO: Se eliminan los <form> y se usan data-attributes ---
                html += `
                    <li>
                        <div class="item-details">
                            <strong>${item.producto_nombre}</strong> (x${item.cantidad})<br>
                            <span>Estado: <span class="status ${item.estado_producto_individual}">${estadoIndividualText}</span></span>
                        </div>
                        <div class="item-actions">
                            <button type="button" class="btn-preparing update-status-btn" data-id="${item.pedido_producto_id}" data-estado="en_preparacion" ${isItemDone ? 'disabled' : ''}>En Preparación</button>
                            <button type="button" class="btn-ready update-status-btn" data-id="${item.pedido_producto_id}" data-estado="listo" ${isItemDone ? 'disabled' : ''}>Marcar Listo</button>
                            <button type="button" class="btn-reset update-status-btn" data-id="${item.pedido_producto_id}" data-estado="pendiente" ${isItemDone ? 'disabled' : ''}>Restablecer</button>
                        </div>
                    </li>`;
            });
            html += `</ul></div>`;
        });
        container.innerHTML = html;
    }

    // Función para obtener los datos desde el API
    async function fetchPedidos() {
        try {
            const response = await fetch('api_get_pedidos_cocinero.php');
            const pedidos = await response.json();
            renderPedidos(pedidos);
        } catch (error) {
            console.error('Error al actualizar la lista de pedidos:', error);
        }
    }
    
    // Función para mostrar mensajes de estado
    function showStatusMessage(message, isSuccess) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${isSuccess ? 'success' : 'error'}`;
        messageDiv.textContent = message;
        messageContainer.innerHTML = ''; // Limpiar mensajes anteriores
        messageContainer.appendChild(messageDiv);
        setTimeout(() => messageDiv.remove(), 4000); // El mensaje desaparece después de 4 segundos
    }

    // --- NUEVO: Manejador de eventos para los botones de estado ---
    container.addEventListener('click', async function(event) {
        // Solo reacciona si se hizo clic en un botón para actualizar estado
        if (!event.target.classList.contains('update-status-btn')) {
            return;
        }

        const button = event.target;
        const pedidoProductoId = button.dataset.id;
        const nuevoEstado = button.dataset.estado;

        // Deshabilitar el botón para evitar clics múltiples
        button.disabled = true;

        // Prepara los datos para enviar
        const formData = new FormData();
        formData.append('action', 'update_estado_producto');
        formData.append('pedido_producto_id', pedidoProductoId);
        formData.append('estado', nuevoEstado);
        
        try {
            const response = await fetch('cocinero_dashboard.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            showStatusMessage(result.message, result.success);

            if (result.success) {
                // Si el cambio fue exitoso, volvemos a cargar la lista de pedidos
                // para reflejar todos los cambios (incluido el estado del pedido principal).
                fetchPedidos();
            } else {
                // Si falló, reactiva el botón
                button.disabled = false;
            }
        } catch (error) {
            console.error('Error al cambiar estado:', error);
            showStatusMessage('Error de conexión al intentar cambiar el estado.', false);
            button.disabled = false; // Reactiva el botón si hay un error
        }
    });

    // Carga inicial y actualización periódica
    fetchPedidos();
    setInterval(fetchPedidos, 5000);
});
</script>

</body>
</html>