<?php
session_start();
// Tu archivo de conexión a la base de datos
require_once './../config.php'; 

// --- 1. CONFIGURACIÓN DE TEMA Y EMPRESA (JSON) ---
// RUTA MODIFICADA: Ahora apunta a la carpeta admin/
$config_file_path = __DIR__ . '/../admin/config_data.json'; 
$body_class = '';
$company_name = 'Sistema de Gestión de Pedidos'; 
$logo_path = '../img/logo/default_logo.png'; // Ruta por defecto

$config_data = [];
$current_theme_mode = 'light';

// Intenta leer el archivo de configuración
if (file_exists($config_file_path)) {
    $config_data_json_content = file_get_contents($config_file_path);
    $config_data = json_decode($config_data_json_content, true);

    if (isset($config_data['theme_mode'])) {
        $current_theme_mode = $config_data['theme_mode'];
        if ($current_theme_mode === 'dark') {
            $body_class = 'dark-mode';
        }
    }
    if (isset($config_data['company_name'])) {
        $company_name = htmlspecialchars($config_data['company_name']);
    }
    // Usamos la ruta del config, o el fallback
    if (isset($config_data['logo_path']) && !empty($config_data['logo_path'])) {
        $logo_path = htmlspecialchars($config_data['logo_path']);
    }
}
// ---------------------------------------------

// Seguridad: Verificar si el usuario ha iniciado sesión y tiene rol de cocinero
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'cocinero') {
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header("Location: ./../index.php");
    } else {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acceso no autorizado.']);
    }
    exit();
}

// Establecer la conexión a la base de datos
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    // Es preferible no mostrar este error al usuario final
    error_log("Error de conexión a la base de datos: " . $conn->connect_error);
    die("Error de conexión a la base de datos.");
}

// --- LÓGICA DE CAMBIO DE TEMA (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_theme') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Error al cambiar tema.', 'new_mode' => ''];

    try {
        // Revisa permisos de escritura
        if (!file_exists($config_file_path) || !is_writable($config_file_path)) {
            throw new Exception("¡ERROR CRÍTICO DE PERMISOS! El archivo existe, pero no se puede escribir. SOLUCIÓN: Asigna PERMISOS DE ESCRITURA (CHMOD 666 o 777 en Linux/Mac o Modificar en Windows) a la ruta: " . $config_file_path);
        }

        // Re-leer el archivo de configuración antes de modificarlo
        $config_data_json_content = file_get_contents($config_file_path);
        $config_data = json_decode($config_data_json_content, true);

        // Determinar el nuevo modo
        $current_mode = $config_data['theme_mode'] ?? 'light';
        $new_mode = ($current_mode === 'dark') ? 'light' : 'dark';
        
        // Aplicar y guardar el nuevo modo
        $config_data['theme_mode'] = $new_mode;
        
        if (file_put_contents($config_file_path, json_encode($config_data, JSON_PRETTY_PRINT)) === false) {
             throw new Exception("Error al guardar la configuración.");
        }

        $response['success'] = true;
        $response['message'] = "Tema actualizado a " . $new_mode . ".";
        $response['new_mode'] = $new_mode;

    } catch (Exception $e) {
        $response['message'] = "Error: " . $e->getMessage();
        error_log("Error al cambiar tema: " . $e->getMessage());
    }

    $conn->close();
    echo json_encode($response);
    exit();
}
// ----------------------------------------


// --- LÓGICA DE ACTUALIZACIÓN DE ESTADO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_estado_producto') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Error desconocido.'];

    try {
        $pedido_producto_id = intval($_POST['pedido_producto_id']);
        $nuevo_estado = $_POST['estado'];

        $estados_validos_producto = ['pendiente', 'en_preparacion', 'listo', 'cancelado'];
        if (!in_array($nuevo_estado, $estados_validos_producto)) {
            $response['message'] = "Estado de producto no válido.";
            echo json_encode($response);
            exit();
        }

        $conn->begin_transaction();

        // 1. Actualizar el estado del producto
        $stmt_producto = $conn->prepare("UPDATE pedido_productos SET estado = ? WHERE id = ?");
        $stmt_producto->bind_param("si", $nuevo_estado, $pedido_producto_id);
        if (!$stmt_producto->execute()) {
            throw new Exception("Error al actualizar el estado del producto.");
        }
        $stmt_producto->close();

        // 2. Comprobar y actualizar el estado del pedido principal
        $stmt_pedido = $conn->prepare("SELECT pedido_id FROM pedido_productos WHERE id = ?");
        $stmt_pedido->bind_param("i", $pedido_producto_id);
        $stmt_pedido->execute();
        $result_pedido = $stmt_pedido->get_result();
        $row_pedido = $result_pedido->fetch_assoc();
        if (!$row_pedido) {
             throw new Exception("Ítem de pedido no encontrado.");
        }
        $pedido_id = $row_pedido['pedido_id'];
        $stmt_pedido->close();

        // Verificar el estado de todos los productos del pedido
        $stmt_check = $conn->prepare("SELECT estado, COUNT(*) as count FROM pedido_productos WHERE pedido_id = ? GROUP BY estado");
        $stmt_check->bind_param("i", $pedido_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        $estados_counts = [];
        $total_productos = 0;
        while ($row_count = $result_check->fetch_assoc()) {
            $estados_counts[$row_count['estado']] = $row_count['count'];
            $total_productos += $row_count['count'];
        }
        $stmt_check->close();

        // Determinar el nuevo estado del pedido principal
        $nuevo_estado_pedido = 'pendiente';
        
        $count_listo = $estados_counts['listo'] ?? 0;
        $count_cancelado = $estados_counts['cancelado'] ?? 0;
        $count_preparacion = $estados_counts['en_preparacion'] ?? 0;
        $count_pendiente = $estados_counts['pendiente'] ?? 0;

        // Si todos los productos están cancelados
        if ($count_cancelado === $total_productos) {
            $nuevo_estado_pedido = 'cancelado';
        } 
        // Si todos los productos están listos
        elseif ($count_listo === $total_productos) {
            $nuevo_estado_pedido = 'listo';
        } 
        // Si hay al menos un producto en preparación (prioridad sobre pendiente)
        elseif ($count_preparacion > 0) {
             $nuevo_estado_pedido = 'en_preparacion';
        } 
        // Si solo quedan pendientes (y posiblemente listos o cancelados)
        elseif ($count_pendiente > 0) {
            $nuevo_estado_pedido = 'pendiente';
        }
        
        // Actualizar el estado del pedido principal
        $stmt_update_pedido = $conn->prepare("UPDATE pedidos SET estado = ? WHERE id = ?");
        $stmt_update_pedido->bind_param("si", $nuevo_estado_pedido, $pedido_id);
        if (!$stmt_update_pedido->execute()) {
            throw new Exception("Error al actualizar el estado del pedido principal.");
        }
        $stmt_update_pedido->close();

        $conn->commit();
        $response['success'] = true;
        $response['message'] = "Estado de producto y pedido actualizado con éxito. Pedido principal: " . $nuevo_estado_pedido;
        $response['nuevo_estado_pedido'] = $nuevo_estado_pedido; // Devolver el estado principal

    } catch (Exception $e) {
        $conn->rollback();
        $response['message'] = "Error en la operación: " . $e->getMessage();
        error_log("Error en update_estado_producto: " . $e->getMessage());
    }
    echo json_encode($response);
    exit();
} 

// --- LÓGICA DE LECTURA DE DATOS PARA EL DASHBOARD (GET) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'fetch_pedidos') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Error al obtener los pedidos.', 'pedidos' => []];

    try {
        // La consulta trae pedidos en estado 'pendiente' y 'en_preparacion'
        $sql = "
            SELECT 
                p.id as pedido_id, 
                p.fecha_pedido as fecha_creacion, 
                p.estado as estado_pedido,
                COALESCE(c.nombre, 'Cliente Anónimo') as cliente_nombre,
                pp.id as pedido_producto_id,
                pp.cantidad,
                pp.estado as estado_producto,
                pr.nombre as producto_nombre,
                pp.añadido as producto_anadido /* CLAVE: Incluir el campo 'añadido' para las notas */
            FROM pedidos p
            LEFT JOIN clientes c ON p.cliente_id = c.id
            JOIN pedido_productos pp ON p.id = pp.pedido_id
            JOIN productos pr ON pr.id = pp.producto_id
            WHERE p.estado IN ('pendiente', 'en_preparacion')
            ORDER BY p.fecha_pedido ASC;
        ";
        $result = $conn->query($sql);

        $pedidos_agrupados = [];
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $pedido_id = $row['pedido_id'];

                if (!isset($pedidos_agrupados[$pedido_id])) {
                    $pedidos_agrupados[$pedido_id] = [
                        'pedido_id' => $pedido_id,
                        'fecha' => $row['fecha_creacion'],
                        'estado_pedido_principal' => $row['estado_pedido'],
                        'cliente_info' => $row['cliente_nombre'],
                        'items' => []
                    ];
                }
                $pedidos_agrupados[$pedido_id]['items'][] = [
                    'pedido_producto_id' => $row['pedido_producto_id'],
                    'producto_nombre' => $row['producto_nombre'],
                    'cantidad' => $row['cantidad'],
                    'estado_producto_individual' => $row['estado_producto'],
                    'adiciones' => $row['producto_anadido'] // Campo de notas añadido
                ];
            }
        }
        $response['success'] = true;
        $response['pedidos'] = array_values($pedidos_agrupados);

    } catch (Exception $e) {
        error_log("Error en fetch_pedidos: " . $e->getMessage());
    } 
    // La respuesta solo devuelve el array de pedidos para el JS
    echo json_encode($response['pedidos']);
    exit();
} 

// Lógica para cerrar sesión
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ./../index.php");
    exit();
}

// Cierra la conexión a la base de datos para el renderizado de la vista
$conn->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Cocinero | <?php echo $company_name; ?></title>
    <style>
        /* [El CSS es el mismo que el anterior, por lo que se omite por brevedad] */
        /* ========================================================= */
        /* VARIABLES CSS y Temas */
        /* ========================================================= */
        :root {
            /* --- Colores Base (Claro) --- */
            --color-primary: #000000; 
            --color-text: #222222; 
            --color-background: #ffffff; 
            --color-surface: #f7f7f7; 
            --color-border: #cccccc; 
            --shadow-light: 0 4px 12px rgba(0, 0, 0, 0.1);
            
            /* --- Colores de Estado --- */
            --status-pendiente: #f0ad4e; 
            --status-en-preparacion: #5bc0de; 
            --status-listo: #5cb85c; 
            --status-cancelado: #dc3545; /* Rojo */
            
            /* Color para las notas/adiciones (Modo Claro) */
            --color-adiciones-text: #a12b2b; 
        }
        
        .dark-mode {
            /* --- Colores Base (Oscuro) --- */
            --color-primary: #ffffff; 
            --color-text: #f8f9fa; 
            --color-background: #121212; 
            --color-surface: #202020; 
            --color-border: #444444; 
            --shadow-light: 0 4px 12px rgba(0, 0, 0, 0.4);
            
            /* Color para las notas/adiciones (Modo Oscuro) */
            --color-adiciones-text: #ff7f7f; 
        }

        /* ====================================================
           BASE Y LAYOUT
           ==================================================== */
        body {
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: var(--color-background);
            color: var(--color-text);
            transition: background-color 0.3s, color 0.3s;
            min-height: 100vh;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        /* --- Barra Superior Fija --- */
        .top-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 30px;
            background-color: var(--color-surface);
            border-bottom: 1px solid var(--color-border);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
            position: sticky; 
            top: 0;
            z-index: 100;
        }
        .dark-mode .top-nav {
             box-shadow: 0 2px 8px rgba(0, 0, 0, 0.5);
        }

        .nav-left, .nav-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .app-logo {
            max-width: 40px; 
            height: auto;
            filter: invert(0); 
            transition: filter 0.3s;
            margin-right: 10px;
        }

        .dark-mode .app-logo {
            filter: invert(1); 
        }

        .dashboard-title {
            font-size: 1.2em;
            font-weight: 700;
            color: var(--color-primary);
            text-transform: uppercase;
        }

        .welcome-message-nav {
            font-size: 0.9em;
            color: var(--color-text);
        }
        
        /* --- Botones de Acción de la barra de navegación --- */
        .nav-right button, .nav-right a {
            text-decoration: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
            
            background-color: transparent; 
            color: var(--color-text); 
            border: 1px solid var(--color-border); 
            padding: 8px 15px;
            border-radius: 6px; 
            font-size: 0.95em;
        }

        .nav-right button:hover, .nav-right a:hover {
            background-color: rgba(0, 0, 0, 0.05); 
            border-color: var(--color-primary); 
            color: var(--color-primary);
        }

        .dark-mode .nav-right button:hover, .dark-mode .nav-right a:hover {
            background-color: rgba(255, 255, 255, 0.1); 
            border-color: var(--color-primary); 
            color: var(--color-primary);
        }

        /* Estilo para el botón de Cerrar Sesión */
        .logout-button {
            background-color: var(--status-cancelado);
            color: white !important;
            border-color: var(--status-cancelado) !important;
        }
        .logout-button:hover {
             background-color: #c82333 !important;
             border-color: #c82333 !important;
        }
        .dark-mode .logout-button {
            background-color: var(--status-cancelado);
            border-color: var(--status-cancelado);
        }

        /* ====================================================
           ESTILOS DE PEDIDOS Y ESTADOS
           ==================================================== */
        .pedido-card {
            background-color: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            transition: background-color 0.3s;
        }
        
        .pedido-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--color-border);
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        
        .pedido-header h2 {
            margin: 0;
            font-size: 1.3em;
            color: var(--color-primary);
        }

        .status {
            padding: 4px 10px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.85em;
            color: white;
            text-transform: capitalize;
            white-space: nowrap; 
        }

        /* Colores de estado del pedido principal */
        .status.pendiente { background-color: var(--status-pendiente); }
        .status.en_preparacion { background-color: var(--status-en-preparacion); }
        .status.listo { background-color: var(--status-listo); }
        .status.cancelado { background-color: var(--status-cancelado); }

        .pedido-info {
            display: flex;
            justify-content: space-between;
            font-size: 0.9em;
            color: var(--color-text);
            margin-bottom: 15px;
        }

        .item-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .item-list li {
            display: flex;
            justify-content: space-between;
            align-items: flex-start; 
            padding: 10px 0;
            border-bottom: 1px dashed var(--color-border);
        }

        .item-list li:last-child {
            border-bottom: none;
        }

        .item-details {
            flex-grow: 1;
            display: flex;
            flex-direction: column; 
            align-items: flex-start;
        }
        
        .item-line {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 5px; 
        }
        
        .item-details strong {
            font-weight: 600;
        }
        
        /* Estilo para las notas/adiciones (usa el color de estado cancelado/rojo) */
        .item-aditions {
            font-size: 0.85em;
            color: var(--color-adiciones-text); 
            padding-left: 10px;
            max-width: 600px;
            word-break: break-word; 
            line-height: 1.4;
        }
        
        .dark-mode .item-aditions {
             color: var(--color-adiciones-text); 
        }

        .item-actions {
            min-width: 250px; 
            text-align: right;
            white-space: nowrap;
        }

        .item-actions button {
            cursor: pointer;
            padding: 6px 12px;
            margin-left: 5px;
            border: none;
            border-radius: 4px;
            font-weight: 500;
            transition: opacity 0.2s, background-color 0.2s;
        }
        
        /* Colores para los botones de estado */
        .btn-preparing { background-color: var(--status-en-preparacion); color: white; }
        .btn-ready { background-color: var(--status-listo); color: white; }
        .btn-reset { background-color: var(--color-border); color: var(--color-text); }
        .dark-mode .btn-reset { color: var(--color-background); }

        .item-actions button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* ====================================================
           MENSAJES DE ESTADO (Notificaciones)
           ==================================================== */
        #status-message-container {
            position: fixed; 
            top: 20px; 
            right: 20px; 
            z-index: 1000;
        }
        
        #statusMessage {
            padding: 15px 25px;
            border-radius: 6px;
            color: white;
            font-weight: bold;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            z-index: 1000;
            display: none; 
            opacity: 0;
            transition: opacity 0.3s, transform 0.3s;
            position: relative; 
        }

        #statusMessage.success {
            background-color: var(--status-listo); 
        }

        #statusMessage.error {
            background-color: var(--status-cancelado); 
        }
    </style>
</head>
<body class="<?php echo $body_class; ?>">

    <nav class="top-nav">
        <div class="nav-left">
            <?php if (!empty($logo_path)): ?>
                <img src="<?php echo $logo_path; ?>" alt="Logo de <?php echo $company_name; ?>" class="app-logo">
            <?php endif; ?>
            <span class="dashboard-title">Panel de Cocinero</span>
        </div>
        <div class="nav-right">
             <span class="welcome-message-nav">Bienvenido, Cocinero <?php echo htmlspecialchars($_SESSION['usuario_email']); ?></span>
            <button type="button" id="theme-toggle-btn">
                Cambiar a Modo <?php echo ($current_theme_mode === 'dark' ? 'Claro' : 'Oscuro'); ?>
            </button>
            <a href="?logout=true" class="logout-button">Cerrar Sesión</a>
        </div>
    </nav>
    <div class="container">
        
        <div id="status-message-container">
             <div id="statusMessage" style="display: none; opacity: 0;"></div>
        </div>

        <div id="pedidos-container">
            </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('pedidos-container');
    const themeToggleButton = document.getElementById('theme-toggle-btn');
    const body = document.body;

    // Función para mostrar mensajes de estado
    function showStatusMessage(message, isSuccess) {
        let messageDiv = document.getElementById('statusMessage');
        
        messageDiv.textContent = message;
        messageDiv.className = ''; 
        messageDiv.classList.add(isSuccess ? 'success' : 'error');
        
        messageDiv.style.display = 'block';
        
        // Forzar reflow para asegurar que la transición se aplique
        void messageDiv.offsetWidth; 
        
        messageDiv.style.opacity = '1';

        setTimeout(() => {
            messageDiv.style.opacity = '0';
            // Ocultar completamente después de la transición
            setTimeout(() => messageDiv.style.display = 'none', 300); 
        }, 5000); 
    }
    
    // Función para ocultar el botón del estado actual (saca lo rojo)
    function updateButtonVisibility(actionContainer) {
        // Obtiene el estado actual del atributo data-current-status
        const currentStatus = actionContainer.dataset.currentStatus;
        
        // 1. Mostrar todos los botones primero (en caso de que estuvieran ocultos)
        actionContainer.querySelectorAll('button').forEach(btn => {
             btn.style.display = 'inline-block';
        });
        
        // 2. Ocultar el botón que corresponde al estado actual del item
        // Los botones tienen data-estado="pendiente", "en_preparacion", "listo"
        const activeBtn = actionContainer.querySelector(`button[data-estado="${currentStatus}"]`);
        if (activeBtn) {
            activeBtn.style.display = 'none';
        }
    }


    // Función para renderizar los pedidos
    function renderPedidos(pedidos) {
        if (pedidos.length === 0) {
            container.innerHTML = `<div class="no-pedidos"><p>No hay pedidos pendientes o en preparación.</p></div>`;
            return;
        }

        let html = '';
        pedidos.forEach(pedido => {
            const estadoPrincipalText = pedido.estado_pedido_principal.replace('_', ' ');
            
            // Formatear la hora
            const pedidoDate = new Date(pedido.fecha.replace(/-/g, '/'));
            const horaFormateada = pedidoDate.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });

            html += `
            <div class="pedido-card ${pedido.estado_pedido_principal}">
                <div class="pedido-header">
                    <h2>Pedido #${pedido.pedido_id}</h2>
                    <span class="status ${pedido.estado_pedido_principal}">${estadoPrincipalText}</span>
                </div>
                <div class="pedido-info">
                    <p><strong>Cliente:</strong> ${pedido.cliente_info}</p>
                    <p><strong>Hora:</strong> ${horaFormateada} hs.</p>
                </div>
                <ul class="item-list">`;

            pedido.items.forEach(item => {
                const estadoIndividualText = item.estado_producto_individual.replace('_', ' ');
                // Deshabilitar botones si el ítem ya está "listo" o "cancelado"
                const isItemDone = ['listo', 'cancelado'].includes(item.estado_producto_individual);
                
                // --- INICIO: LÓGICA DE MODIFICACIONES CON CANTIDAD (AJUSTADA Y SIMPLIFICADA) ---
                let adicionesHtml = '';
                if (item.adiciones && item.adiciones.trim() !== '') {
                    let adiciones = null;
                    let rawJsonString = item.adiciones.trim();

                    try {
                        // 1. Intento de parseo estándar
                        adiciones = JSON.parse(rawJsonString);
                    } catch (e) {
                        // 2. Si falla (probablemente por truncamiento), intentar rescatar el JSON
                        console.warn('Fallo el parseo estándar. Intentando rescatar datos:', rawJsonString);
                        
                        // Buscar el índice del último corchete de cierre '}' para marcar el final del último objeto completo
                        let lastSafeBraceIndex = rawJsonString.lastIndexOf('}');

                        if (lastSafeBraceIndex !== -1 && rawJsonString.startsWith('[')) {
                            // Truncar la cadena después del último '}' y cerrar el array con ']'
                            let fixedJsonString = rawJsonString.substring(0, lastSafeBraceIndex + 1) + ']';
                            
                            try {
                                // Intentar parsear la cadena reparada
                                adiciones = JSON.parse(fixedJsonString);
                                console.log('Datos de adiciones rescatados con éxito.');
                            } catch (e2) {
                                // El rescate falló. 'adiciones' sigue siendo null.
                                console.error('El intento de rescate de JSON también falló:', e2);
                            }
                        }
                    }

                    if (adiciones && Array.isArray(adiciones) && adiciones.length > 0) {
                        
                        // 1. Filtrar y mapear, incluyendo solo cantidad (quantity) y nombre (nombre).
                        const nombresAdiciones = adiciones
                            .filter(a => a.nombre && a.quantity && a.quantity > 0) 
                            .map(a => {
                                // *** Muestra SOLO cantidad y nombre (cantidad + nombre) ***
                                return `<strong>${a.quantity}x</strong> ${a.nombre}`; 
                            })
                            .join(', '); 
                        
                        if (nombresAdiciones.length > 0) {
                            // Si el rescate fue exitoso, se muestra el resultado legible.
                            adicionesHtml = `<div class="item-aditions">Notas: ${nombresAdiciones}</div>`;
                        }
                    } else {
                        // Si adiciones es null o un array vacío después de todos los intentos, mostrar el mensaje de error.
                        adicionesHtml = `<div class="item-aditions">Notas: ¡Detalles Ilegibles! (Error de formato en DB)</div>`;
                    }
                }
                // --- FIN: LÓGICA DE MODIFICACIONES CON CANTIDAD ---

                html += `
                    <li>
                        <div class="item-details">
                            <div class="item-line">
                                <strong>${item.producto_nombre}</strong> x${item.cantidad}
                                <span class="status ${item.estado_producto_individual}">${estadoIndividualText}</span>
                            </div>
                            ${adicionesHtml}
                        </div>
                        <div class="item-actions" data-current-status="${item.estado_producto_individual}">
                            <button type="button" class="btn-preparing update-status-btn" data-id="${item.pedido_producto_id}" data-estado="en_preparacion" ${isItemDone ? 'disabled' : ''}>En Prep.</button>
                            <button type="button" class="btn-ready update-status-btn" data-id="${item.pedido_producto_id}" data-estado="listo" ${isItemDone ? 'disabled' : ''}>Listo</button>
                            <button type="button" class="btn-reset update-status-btn" data-id="${item.pedido_producto_id}" data-estado="pendiente" ${isItemDone ? 'disabled' : ''}>Pendiente</button>
                        </div>
                    </li>`;
            });
            html += `</ul></div>`;
        });
        container.innerHTML = html;
        
        // Aplicar la lógica de visibilidad de botones después de renderizar
        document.querySelectorAll('.item-actions').forEach(updateButtonVisibility);
    }

    // Función para obtener los datos desde el API (el propio archivo)
    async function fetchPedidos() {
        try {
            const response = await fetch('cocinero_dashboard.php?action=fetch_pedidos');
            const pedidos = await response.json();
            // Si la respuesta es un array (pedidos), renderiza. Si es un error JSON, lo maneja.
            if (Array.isArray(pedidos)) {
                renderPedidos(pedidos); 
            } else {
                 throw new Error('Respuesta de la API no es un array de pedidos.');
            }
        } catch (error) {
            console.error('Error al actualizar la lista de pedidos:', error);
            // Solo muestra el mensaje si el contenedor está vacío para no ser intrusivo
            if (container.innerHTML === '' || container.innerHTML.includes('no-pedidos')) {
                showStatusMessage('Error al cargar pedidos. Verifique la conexión a la base de datos.', false);
            }
        }
    }

    // --- LÓGICA PARA EL CAMBIO DE TEMA ---
    themeToggleButton.addEventListener('click', async function() {
        themeToggleButton.disabled = true;
        const originalText = themeToggleButton.textContent;
        themeToggleButton.textContent = 'Cambiando...';

        const formData = new FormData();
        formData.append('action', 'toggle_theme');

        try {
            const response = await fetch('cocinero_dashboard.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                // Actualizar la clase del body y el texto del botón
                if (result.new_mode === 'dark') {
                    body.classList.add('dark-mode');
                    themeToggleButton.textContent = 'Cambiar a Modo Claro';
                } else {
                    body.classList.remove('dark-mode');
                    themeToggleButton.textContent = 'Cambiar a Modo Oscuro';
                }
                showStatusMessage(result.message, true);
            } else {
                themeToggleButton.textContent = originalText;
                showStatusMessage(result.message, false);
            }
        } catch (error) {
            themeToggleButton.textContent = originalText;
            showStatusMessage('Error de red al intentar cambiar el tema.', false);
        } finally {
            themeToggleButton.disabled = false;
        }
    });


    // Manejador de eventos para los botones de estado
    container.addEventListener('click', async function(event) {
        if (!event.target.classList.contains('update-status-btn')) {
            return;
        }

        const button = event.target;
        const pedidoProductoId = button.dataset.id;
        const nuevoEstado = button.dataset.estado;

        // Deshabilitar el botón y sus hermanos para evitar clics múltiples
        const buttonsInSameRow = button.closest('.item-actions').querySelectorAll('button');
        buttonsInSameRow.forEach(btn => btn.disabled = true);


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
                // Recargar para aplicar los cambios de estado y visibilidad de botones
                fetchPedidos();
            } else {
                buttonsInSameRow.forEach(btn => btn.disabled = false);
            }
        } catch (error) {
            console.error('Error al cambiar estado:', error);
            showStatusMessage('Error de conexión al intentar cambiar el estado.', false);
            buttonsInSameRow.forEach(btn => btn.disabled = false);
        }
    });

    // Carga inicial y actualización periódica
    fetchPedidos();
    setInterval(fetchPedidos, 5000);
});
</script>

</body>
</html>