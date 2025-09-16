<?php
session_start();
require_once './../config.php';

// Redireccionar si el usuario no está logueado o no es un empleado
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'empleado') {
    // NOTA: Se cambió la validación del rol a 'empleado', ya que un panel de
    // cliente generalmente no permite gestionar todos los pedidos y clientes.
    header("Location: ./../index.php");
    exit();
}

// Conexión a la base de datos usando PDO para seguridad y consistencia
try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error de conexión a la base de datos: " . $e->getMessage());
    die("Error de conexión. Por favor, inténtelo de nuevo más tarde.");
}

// --- FUNCIONES PARA OBTENER DATOS DE LA BASE DE DATOS ---

/**
 * Obtiene los productos disponibles, agrupados por categoría.
 * @param PDO $pdo
 * @return array
 */
function getProductsGroupedByCategory(PDO $pdo) {
    $sql = "SELECT p.id, p.nombre, p.precio, c.nombre AS categoria_nombre
            FROM productos p
            LEFT JOIN categorias_productos c ON p.categoria_id = c.id
            ORDER BY c.nombre ASC, p.nombre ASC";
    
    $stmt = $pdo->query($sql);
    $productos_por_categoria = [];
    while ($row = $stmt->fetch()) {
        $categoria_nombre = $row['categoria_nombre'] ?? 'Sin Categoría';
        if (!isset($productos_por_categoria[$categoria_nombre])) {
            $productos_por_categoria[$categoria_nombre] = [];
        }
        $productos_por_categoria[$categoria_nombre][] = $row;
    }
    return $productos_por_categoria;
}

/**
 * Obtiene la lista de clientes registrados.
 * @param PDO $pdo
 * @return array
 */
function getRegisteredClients(PDO $pdo) {
    $sql = "SELECT id, nombre, email FROM clientes ORDER BY email ASC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

/**
 * Obtiene la lista de pedidos para gestionar, incluyendo info de clientes "a la calle".
 * @param PDO $pdo
 * @return array
 */
function getOrdersToManage(PDO $pdo) {
    $sql = "SELECT p.id, p.cliente_id, c.nombre AS cliente_nombre, c.email AS cliente_email,
                   p.nombre_cliente_calle, p.ubicacion_cliente_calle, p.telefono_cliente_calle,
                   p.fecha, p.total, p.estado
             FROM pedidos p
             LEFT JOIN clientes c ON p.cliente_id = c.id
             ORDER BY p.fecha DESC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

// --- LÓGICA DE PROCESAMIENTO AJAX (POST) ---
// NOTA: Esta lógica se podría mover a un archivo separado (ej. api/pedidos.php)
// para una arquitectura más limpia.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (isset($_POST['place_order'])) {
        $pdo->beginTransaction();
        try {
            $productos = json_decode($_POST['productos_json'], true);
            $cliente_id = isset($_POST['cliente_id']) && $_POST['cliente_id'] !== '0' ? intval($_POST['cliente_id']) : null;
            $total_pedido = 0;

            $ids_productos = array_column($productos, 'producto_id');
            $placeholders = implode(',', array_fill(0, count($ids_productos), '?'));
            $sql_precios = "SELECT id, precio FROM productos WHERE id IN ($placeholders)";
            $stmt_precios = $pdo->prepare($sql_precios);
            $stmt_precios->execute($ids_productos);
            $precios_productos = $stmt_precios->fetchAll(PDO::FETCH_KEY_PAIR);

            foreach ($productos as $item) {
                if (!isset($precios_productos[$item['producto_id']])) {
                    throw new Exception("Producto no encontrado.");
                }
                $total_pedido += $precios_productos[$item['producto_id']] * $item['cantidad'];
            }

            // Generar token y verificar unicidad
            $token = '';
            $token_unique = false;
            while (!$token_unique) {
                $token = bin2hex(random_bytes(3)); // Generación de token más segura
                $sql_check_token = "SELECT 1 FROM pedidos WHERE token = ?";
                $stmt_check = $pdo->prepare($sql_check_token);
                $stmt_check->execute([$token]);
                if ($stmt_check->rowCount() === 0) {
                    $token_unique = true;
                }
            }

            if ($cliente_id !== null) {
                $sql_pedido = "INSERT INTO pedidos (cliente_id, total, token) VALUES (?, ?, ?)";
                $stmt_pedido = $pdo->prepare($sql_pedido);
                $stmt_pedido->execute([$cliente_id, $total_pedido, $token]);
            } else {
                $sql_pedido = "INSERT INTO pedidos (total, token, nombre_cliente_calle, telefono_cliente_calle, ubicacion_cliente_calle) VALUES (?, ?, ?, ?, ?)";
                $stmt_pedido = $pdo->prepare($sql_pedido);
                $stmt_pedido->execute([$total_pedido, $token, $_POST['new_client_name'], $_POST['new_client_phone'], $_POST['new_client_location']]);
            }
            $pedido_id = $pdo->lastInsertId();
            
            $sql_productos_pedido = "INSERT INTO pedido_productos (pedido_id, producto_id, cantidad, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?)";
            $stmt_productos = $pdo->prepare($sql_productos_pedido);

            foreach ($productos as $item) {
                $producto_id = $item['producto_id'];
                $cantidad = $item['cantidad'];
                $precio_unitario = $precios_productos[$producto_id];
                $subtotal = $precio_unitario * $cantidad;
                $stmt_productos->execute([$pedido_id, $producto_id, $cantidad, $precio_unitario, $subtotal]);
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Pedido realizado con éxito. Token: ' . $token, 'token' => $token]);

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error al realizar el pedido: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error al procesar el pedido.']);
        }
        exit();
    }

    if (isset($_POST['update_order_status'])) {
        $pedido_id = intval($_POST['order_id']);
        $new_status = $_POST['new_status'];
        $valid_statuses = ['pendiente', 'en_preparacion', 'listo', 'entregado', 'cancelado'];

        if (!in_array($new_status, $valid_statuses)) {
            echo json_encode(['success' => false, 'message' => 'Estado no válido.']);
            exit();
        }

        $sql = "UPDATE pedidos SET estado = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([$new_status, $pedido_id])) {
            echo json_encode(['success' => true, 'message' => 'Estado del pedido actualizado.']);
        } else {
            error_log("Error al actualizar estado: " . implode(" ", $stmt->errorInfo()));
            echo json_encode(['success' => false, 'message' => 'Error al actualizar el estado.']);
        }
        exit();
    }
}

// --- OBTENER DATOS PARA LA VISTA ---
$productos_por_categoria = getProductsGroupedByCategory($pdo);
$clientes_disponibles = getRegisteredClients($pdo);
$pedidos_a_gestionar = getOrdersToManage($pdo);

// Cerrar la conexión (no es estrictamente necesario con PDO ya que se cierra al finalizar el script)
$pdo = null;

// Incluir la vista (el archivo HTML)
require_once 'empleado_dashboard_view.php';