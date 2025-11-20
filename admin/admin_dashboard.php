<?php
// admin_dashboard.php - CÓDIGO ORIGINAL + FIX CAMPO 'ORDEN'

session_start();
// Se asume que config.php contiene las constantes de conexión (DB_HOST, DB_USER, etc.)
require_once './../config.php'; 

// Establecer la conexión a la base de datos al principio
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// 1. VERIFICACIÓN DE SESIÓN Y ROLES
$allowed_roles = ['administrador', 'empleado', 'cocinero']; 
if (!isset($_SESSION['usuario_rol']) || !in_array($_SESSION['usuario_rol'], $allowed_roles)) {
    header("Location: ./../index.php");
    exit();
}

// Lógica de cierre de sesión por GET
if (isset($_GET['logout']) && $_GET['logout'] === 'true') {
    session_destroy();
    header("Location: ./../index.php");
    exit();
}

// Obtener el rol, ID y EMAIL del usuario actual
$current_user_rol = $_SESSION['usuario_rol'] ?? 'invitado';
$current_user_email = $_SESSION['usuario_email'] ?? null;
$current_user_id = null;
if (isset($_SESSION['usuario_email'])) {
    $stmt_user_id = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
    if ($stmt_user_id) {
        $stmt_user_id->bind_param("s", $_SESSION['usuario_email']);
        $stmt_user_id->execute();
        $result_id = $stmt_user_id->get_result();
        $user_row = $result_id->fetch_assoc();
        $current_user_id = $user_row['id'] ?? null;
        $stmt_user_id->close();
    }
}

// Obtener la sección actual y el modo de operación (CRUD)
$current_section = $_GET['section'] ?? 'overview';
$action = $_GET['action'] ?? ''; 
$item_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// 2. LECTURA DE CONFIGURACIÓN (PARA HEADER Y SETTINGS)
$config = [];
$config_file = './config_data.json';
if (file_exists($config_file)) {
    $json_data = file_get_contents($config_file);
    $config = json_decode($json_data, true);
} else {
    // Valores por defecto si el archivo no existe
    $config = [
        'company_name' => 'Sistema de Gestión',
        'logo_path' => './../img/default_logo.png',
        'contact_email' => 'contacto@ejemplo.com',
        'theme_mode' => 'light',
        'mercadopago_active' => '0',
        'other_payment_options' => 'Efectivo',
        'transfer_alias' => '',
        'transfer_cbu_cvu' => ''
    ];
}
$current_theme_mode = $config['theme_mode'] ?? 'light';

// 3. FUNCIÓN DE UTILIDAD PARA FORMATO DE STOCK
function format_stock($stock) {
    if ($stock === '∞') {
        return '∞ Ilimitado';
    }
    $stock_num = intval($stock);
    return number_format($stock_num, 0, '', '.');
}

// 4. LÓGICA DE AUDITORÍA (REPARADA)
function log_change($conn, $user_id, $user_rol, $user_email, $action_type, $entity_type, $entity_id, $description) {
    // Si el usuario no es admin ni empleado, o si no hay ID de usuario, no registrar.
    if (!in_array($user_rol, ['administrador', 'empleado']) || empty($user_id)) {
        return;
    }

    $stmt = $conn->prepare("INSERT INTO registro_cambios (usuario_id, user_rol, email_usuario, action_type, entity_type, entity_id, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    if ($stmt) {
        // FIX: Usamos bind_param directamente para evitar el error de referencia en PHP 8+
        // Tipos: i=int, s=string (issssis)
        $stmt->bind_param("issssis", 
            $user_id, 
            $user_rol, 
            $user_email, 
            $action_type, 
            $entity_type, 
            $entity_id, 
            $description
        );
        
        $stmt->execute();
        $stmt->close();
    }
}

// 5. LÓGICA DE EXPORTACIÓN CSV
if ($current_section === 'reports' && $action === 'export_orders') {
    if ($current_user_rol !== 'administrador') {
        header("Location: admin_dashboard.php?section=reports&message=" . urlencode("Error: Permiso denegado para exportar datos."));
        exit();
    }
    
    $query = "SELECT p.id, p.fecha_pedido, p.total, p.estado, c.nombre AS cliente_nombre, c.email AS cliente_email, p.ubicacion, p.tipo 
              FROM pedidos p 
              LEFT JOIN clientes c ON p.cliente_id = c.id
              ORDER BY p.fecha_pedido DESC";
    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        $filename = "pedidos_" . date('Ymd_His') . ".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID Pedido', 'fecha_pedido', 'Total', 'Estado', 'Cliente', 'Email Cliente', 'Ubicacion', 'Tipo'], ',', '"');
        
        while ($row = $result->fetch_assoc()) {
            fputcsv($output, $row, ',', '"');
        }

        fclose($output);
        log_change($conn, $current_user_id, $current_user_rol, $current_user_email, 'READ', 'PEDIDOS', 0, 'Exportación masiva de pedidos a CSV.');
        exit();
    } else {
        header("Location: admin_dashboard.php?section=reports&message=" . urlencode("Advertencia: No hay pedidos para exportar."));
        exit();
    }
}

// 6. LÓGICA DE ACCIONES CRUD (GENERAL)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entity_type = $_POST['entity_type'] ?? '';
    $action_type = $_POST['action_type'] ?? '';

    // Lógica de subida de imagen
    $img_path = ''; 
    $upload_dir = './../img/uploads/'; 

    if (isset($_FILES['img_path']) && $_FILES['img_path']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['img_path']['tmp_name'];
        $file_name = basename($_FILES['img_path']['name']);
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $unique_name = uniqid('img_', true) . '.' . $file_ext;
        $destination = $upload_dir . $unique_name;

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        if (move_uploaded_file($file_tmp, $destination)) {
            $img_path = './../img/uploads/' . $unique_name;
        } else {
            $message = "Error al subir la imagen.";
            if ($action_type !== 'edit' && $entity_type !== 'settings') {
                 // ...
            } else if ($entity_type === 'settings') {
                $img_path = $config['logo_path']; 
            }
        }
    } else if ($action_type === 'edit') {
        if ($entity_type === 'product' && isset($_POST['current_image'])) {
            $img_path = $_POST['current_image'];
        } elseif ($entity_type === 'category' && isset($_POST['current_category_image'])) {
            $img_path = $_POST['current_category_image'];
        } elseif ($entity_type === 'settings') {
            $img_path = $config['logo_path'];
        }
    }

    $category_img_path = $img_path; 

    switch ($entity_type) {
        case 'user':
            // CRUD Usuarios 
            if ($action_type === 'create') {
                $nombre = $_POST['nombre'];
                $email = $_POST['email'];
                $raw_password = $_POST['password'];
                $rol = $_POST['rol'];
                $min_length = 8;
                $is_secure = preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z\d]).{' . $min_length . ',}$/', $raw_password);

                if (!$is_secure) {
                    $message = "Error al crear usuario: La contraseña debe tener al menos {$min_length} caracteres, una mayúscula, una minúscula, un número y un carácter especial.";
                    break;
                }
                $password_hash = password_hash($raw_password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("INSERT INTO usuarios (nombre, email, password, rol) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $nombre, $email, $password_hash, $rol);
                if ($stmt->execute()) {
                    $message = "Usuario creado exitosamente.";
                    log_change($conn, $current_user_id, $current_user_rol, $current_user_email, 'INSERT', 'USUARIO', $conn->insert_id, "Nuevo usuario ($rol): $nombre.");
                } else {
                    $message = "Error al crear usuario: " . $stmt->error;
                }
                $stmt->close();

            } elseif ($action_type === 'edit') {
                $id = intval($_POST['id']);
                $nombre = $_POST['nombre'];
                $rol = $_POST['rol'];
                $email = $_POST['email'];
                
                $sql = "UPDATE usuarios SET nombre = ?, email = ?, rol = ? WHERE id = ?";
                $params = [$nombre, $email, $rol, $id];
                $types = "sssi";
                
                if (!empty($_POST['password'])) {
                    $raw_password = $_POST['password'];
                    $min_length = 8;
                    $is_secure = preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z\d]).{' . $min_length . ',}$/', $raw_password);

                    if (!$is_secure) {
                        $message = "Error al actualizar contraseña: La nueva contraseña debe tener al menos {$min_length} caracteres, una mayúscula, una minúscula, un número y un carácter especial.";
                        break;
                    }
                    $password = password_hash($raw_password, PASSWORD_DEFAULT);
                    $sql = "UPDATE usuarios SET nombre = ?, email = ?, password = ?, rol = ? WHERE id = ?";
                    $params = [$nombre, $email, $password, $rol, $id];
                    $types = "ssssi";
                }

                $stmt = $conn->prepare($sql);
                // Aquí es seguro usar el spread operator (...) en versiones modernas, pero si fallara, se puede hacer manual.
                $stmt->bind_param($types, ...$params);
                if ($stmt->execute()) {
                    $message = "Usuario actualizado exitosamente.";
                    log_change($conn, $current_user_id, $current_user_rol, $current_user_email, 'UPDATE', 'USUARIO', $id, "Usuario editado: $nombre.");
                } else {
                    $message = "Error al actualizar usuario: " . $stmt->error;
                }
                $stmt->close();
            }
            break;

        case 'product':
            // CRUD Productos
            $nombre = $_POST['nombre'];
            $descripcion = $_POST['descripcion'];
            $precio = floatval($_POST['precio']);
            $categoria_id = intval($_POST['categoria_id']);
            $disponible = isset($_POST['disponible']) ? 1 : 0;
            $stock_ingresado = trim($_POST['stock'] ?? ''); 
            
            if ($stock_ingresado === '') {
                $stock_for_db = '∞';
            } elseif (is_numeric($stock_ingresado)) {
                $stock_for_db = strval(max(0, intval($stock_ingresado)));
            } else {
                $stock_for_db = '0'; 
            }

            if ($action_type === 'create') {
                // --- [SOLUCIÓN APLICADA AQUÍ] ---
                // Agregamos la columna 'orden' con valor 0 para evitar el error: Field 'orden' doesn't have a default value
                $orden_default = 0;
                
                $stmt = $conn->prepare("INSERT INTO productos (nombre, descripcion, precio, categoria_id, img_path, disponible, stock, orden) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                
                if ($stmt) {
                    // 'ssdisisi' -> el ultimo 'i' es para $orden_default
                    $stmt->bind_param("ssdisisi", $nombre, $descripcion, $precio, $categoria_id, $img_path, $disponible, $stock_for_db, $orden_default); 
                    if ($stmt->execute()) {
                        $message = "Producto creado exitosamente.";
                        log_change($conn, $current_user_id, $current_user_rol, $current_user_email, 'INSERT', 'PRODUCTO', $conn->insert_id, "Nuevo producto: $nombre. Stock: " . format_stock($stock_for_db));
                    } else {
                        $message = "Error al crear producto: " . $stmt->error;
                    }
                    $stmt->close();
                }
                
            } elseif ($action_type === 'edit') {
                $id = intval($_POST['id']);
                $stmt = $conn->prepare("UPDATE productos SET nombre = ?, descripcion = ?, precio = ?, categoria_id = ?, img_path = ?, disponible = ?, stock = ? WHERE id = ?");
                
                if ($stmt) {
                    $stmt->bind_param("ssdisisi", $nombre, $descripcion, $precio, $categoria_id, $img_path, $disponible, $stock_for_db, $id); 
                    if ($stmt->execute()) {
                        $message = "Producto actualizado exitosamente.";
                        log_change($conn, $current_user_id, $current_user_rol, $current_user_email, 'UPDATE', 'PRODUCTO', $id, "Producto editado: $nombre. Stock: " . format_stock($stock_for_db));
                    } else {
                        $message = "Error al actualizar producto: " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
            break;

        case 'category':
            // CRUD Categorías
            $nombre = $_POST['nombre'];
            $descripcion = $_POST['descripcion'];

            if ($action_type === 'create') {
                $stmt = $conn->prepare("INSERT INTO categorias_productos (nombre, descripcion, img_path) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $nombre, $descripcion, $category_img_path);
                if ($stmt->execute()) {
                    $message = "Categoría creada exitosamente.";
                    log_change($conn, $current_user_id, $current_user_rol, $current_user_email, 'INSERT', 'CATEGORIA', $conn->insert_id, "Nueva categoría: $nombre.");
                } else {
                    $message = "Error al crear categoría: " . $stmt->error;
                }
                $stmt->close();
            } elseif ($action_type === 'edit') {
                $id = intval($_POST['id']);
                $stmt = $conn->prepare("UPDATE categorias_productos SET nombre = ?, descripcion = ?, img_path = ? WHERE id = ?");
                $stmt->bind_param("sssi", $nombre, $descripcion, $category_img_path, $id);
                if ($stmt->execute()) {
                    $message = "Categoría actualizada exitosamente.";
                    log_change($conn, $current_user_id, $current_user_rol, $current_user_email, 'UPDATE', 'CATEGORIA', $id, "Categoría editada: $nombre.");
                } else {
                    $message = "Error al actualizar categoría: " . $stmt->error;
                }
                $stmt->close();
            }
            break;
            
        case 'settings':
            // CRUD Config
            $new_config = $config; 
            
            $new_config['company_name'] = $_POST['company_name'] ?? $new_config['company_name'];
            $new_config['contact_email'] = $_POST['contact_email'] ?? $new_config['contact_email'];
            $new_config['theme_mode'] = $_POST['theme_mode'] ?? $new_config['theme_mode'];
            $new_config['mercadopago_active'] = $_POST['mercadopago_active'] ?? '0';
            $new_config['other_payment_options'] = $_POST['other_payment_options'] ?? $new_config['other_payment_options'];
            $new_config['transfer_alias'] = $_POST['transfer_alias'] ?? $new_config['transfer_alias'];
            $new_config['transfer_cbu_cvu'] = $_POST['transfer_cbu_cvu'] ?? $new_config['transfer_cbu_cvu'];

            if (!empty($img_path)) {
                $new_config['logo_path'] = $img_path;
            }
            
            $json_content = json_encode($new_config, JSON_PRETTY_PRINT);
            
            if (file_put_contents($config_file, $json_content) !== false) {
                $message = "Configuración guardada exitosamente. El cambio de tema se aplicará al recargar.";
                $current_theme_mode = $new_config['theme_mode'];
                log_change($conn, $current_user_id, $current_user_rol, $current_user_email, 'UPDATE', 'CONFIG', 1, 'Configuración del sistema actualizada.');
            } else {
                $message = "Error al guardar la configuración. Verifique los permisos de escritura para el archivo config_data.json.";
            }
            break;
            
        default:
            break;
    }
    
    header("Location: admin_dashboard.php?section=" . urlencode($current_section) . "&message=" . urlencode($message));
    exit();
}

// 7. LÓGICA DE ACCIONES DELETE/STATE UPDATE (GET/URL)

// 7.1 LÓGICA DE ELIMINACIÓN
if ($action === 'delete' && $item_id > 0) {
    if ($current_user_rol !== 'administrador') {
        $message = "Error: Permiso denegado para eliminar elementos.";
        header("Location: admin_dashboard.php?section=" . urlencode($current_section) . "&message=" . urlencode($message));
        exit();
    }
    
    $entity_type = $_GET['entity'] ?? '';

    switch ($entity_type) {
        case 'user':
            $stmt = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
            $stmt->bind_param("i", $item_id);
            if ($stmt->execute()) {
                $message = "Usuario eliminado exitosamente.";
                log_change($conn, $current_user_id, $current_user_rol, $current_user_email, 'DELETE', 'USUARIO', $item_id, 'Usuario eliminado (ID: ' . $item_id . ').');
            } else {
                $message = "Error al eliminar usuario: " . $stmt->error;
            }
            $stmt->close();
            break;
            
        case 'product':
            $img_query = $conn->prepare("SELECT img_path FROM productos WHERE id = ?");
            $img_query->bind_param("i", $item_id);
            $img_query->execute();
            $img_result = $img_query->get_result();
            $img_path_to_delete = $img_result->fetch_assoc()['img_path'] ?? null;
            $img_query->close();
            
            $stmt = $conn->prepare("DELETE FROM productos WHERE id = ?");
            $stmt->bind_param("i", $item_id);
            if ($stmt->execute()) {
                $message = "Producto eliminado exitosamente.";
                if (!empty($img_path_to_delete) && file_exists($img_path_to_delete) && $img_path_to_delete !== './../img/default_product.png') {
                    unlink($img_path_to_delete);
                }
                log_change($conn, $current_user_id, $current_user_rol, $current_user_email, 'DELETE', 'PRODUCTO', $item_id, 'Producto eliminado (ID: ' . $item_id . ').');
            } else {
                $message = "Error al eliminar producto: " . $stmt->error;
            }
            $stmt->close();
            break;
            
        case 'category':
            $img_query = $conn->prepare("SELECT img_path FROM categorias_productos WHERE id = ?");
            $img_query->bind_param("i", $item_id);
            $img_query->execute();
            $img_result = $img_query->get_result();
            $img_path_to_delete = $img_result->fetch_assoc()['img_path'] ?? null;
            $img_query->close();
            
            $stmt = $conn->prepare("DELETE FROM categorias_productos WHERE id = ?");
            $stmt->bind_param("i", $item_id);
            if ($stmt->execute()) {
                $message = "Categoría eliminada exitosamente.";
                if (!empty($img_path_to_delete) && file_exists($img_path_to_delete) && $img_path_to_delete !== './../img/default_category.png') {
                    unlink($img_path_to_delete);
                }
                log_change($conn, $current_user_id, $current_user_rol, $current_user_email, 'DELETE', 'CATEGORIA', $item_id, 'Categoría eliminada (ID: ' . $item_id . ').');
            } else {
                $message = "Error al eliminar categoría: " . $stmt->error;
            }
            $stmt->close();
            break;
            
        default:
            $message = "Error: Tipo de entidad desconocido para eliminar.";
            break;
    }
    
    header("Location: admin_dashboard.php?section=" . urlencode($current_section) . "&message=" . urlencode($message));
    exit();
}

// 7.2 LÓGICA DE ACTUALIZACIÓN DE ESTADO (Pedidos)
if ($current_section === 'orders' && $action === 'update_status' && $item_id > 0) {
    if ($current_user_rol === 'empleado' || $current_user_rol === 'administrador') {
        $new_status = $_GET['new_status'] ?? '';
        $valid_statuses = ['pendiente', 'en_preparacion', 'listo', 'entregado', 'cancelado'];
        if (in_array($new_status, $valid_statuses)) {
            $stmt = $conn->prepare("UPDATE pedidos SET estado = ? WHERE id = ?");
            $stmt->bind_param("si", $new_status, $item_id);
            if ($stmt->execute()) {
                $message = "Estado del pedido #{$item_id} actualizado a: " . ucfirst($new_status) . ".";
                log_change($conn, $current_user_id, $current_user_rol, $current_user_email, 'UPDATE', 'PEDIDO', $item_id, "Estado actualizado a: $new_status.");
            } else {
                $message = "Error al actualizar estado: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $message = "Error: Estado no válido.";
        }
    } else {
        $message = "Error: Permiso denegado para cambiar estados.";
    }
    header("Location: admin_dashboard.php?section=orders&message=" . urlencode($message));
    exit();
}

// 8. HTML Y VISTAS
function get_xampp_status() {
    $status = ['apache' => 'success', 'mysql' => 'success'];
    global $conn;
    if ($conn->connect_error) {
        $status['mysql'] = 'error';
    }
    return $status;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($config['company_name']); ?> | Panel de Administración</title>
    <link rel="stylesheet" href="./style.css">
    <link rel="stylesheet" href="admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        .filter-bar {
            padding: 15px;
            margin-bottom: 20px;
            background-color: var(--card-bg); 
            border-radius: 8px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }
        .form-inline .form-group {
            display: inline-block;
            margin-bottom: 0;
        }
        .form-inline .form-control {
            width: auto;
            display: inline-block;
        }
        .mr-3 {
            margin-right: 1rem !important;
        }
        .mr-2 {
            margin-right: 0.5rem !important;
        }
    </style>
</head>
<body class="<?php echo htmlspecialchars($current_theme_mode); ?>-mode">
    <?php 
    $message = $_GET['message'] ?? '';
    if (!empty($message)): 
        $alert_type = (strpos($message, 'Error') !== false || strpos($message, 'eliminar') !== false || strpos($message, 'contraseña') !== false) ? 'alert-danger' : 'alert-success';
    ?>
    <div class="alert <?php echo $alert_type; ?>" role="alert">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="close-btn" onclick="this.parentElement.style.display='none';">&times;</button>
    </div>
    <?php endif; ?>
    <div class="wrapper">
        <aside class="sidebar">
            <div class="sidebar-header">
                <img src="<?php echo htmlspecialchars($config['logo_path'] ?? './../img/default_logo.png'); ?>" alt="<?php echo htmlspecialchars($config['company_name']); ?>">
            </div>
            <nav class="sidebar-nav">
                <ul>
                    <?php $is_active = function($section) use ($current_section) { return ($current_section === $section) ? 'active' : ''; }; ?>
                    <li class="nav-item <?php echo $is_active('overview'); ?>">
                        <a href="admin_dashboard.php?section=overview"><i class="fas fa-th-large"></i> Dashboard</a>
                    </li>
                    <li class="nav-item <?php echo $is_active('orders'); ?>">
                        <a href="admin_dashboard.php?section=orders"><i class="fas fa-box"></i> Pedidos</a>
                    </li>
                    <li class="nav-item <?php echo $is_active('users'); ?>">
                        <a href="admin_dashboard.php?section=users"><i class="fas fa-users-cog"></i> Usuarios</a>
                    </li>
                    <li class="nav-item <?php echo $is_active('products'); ?>">
                        <a href="admin_dashboard.php?section=products"><i class="fas fa-pizza-slice"></i> Productos</a>
                    </li>
                    <li class="nav-item <?php echo $is_active('categories'); ?>">
                        <a href="admin_dashboard.php?section=categories"><i class="fas fa-list-alt"></i> Categorías de Productos</a>
                    </li>
                    <li class="nav-item <?php echo $is_active('reports'); ?>">
                        <a href="admin_dashboard.php?section=reports"><i class="fas fa-chart-line"></i> Reportes</a>
                    </li>
                    <?php if ($current_user_rol === 'administrador'): ?>
                    <li class="nav-item <?php echo $is_active('log_changes'); ?>">
                        <a href="admin_dashboard.php?section=log_changes"><i class="fas fa-history"></i> Log de Cambios</a>
                    </li>
                    <li class="nav-item <?php echo $is_active('settings'); ?>">
                        <a href="admin_dashboard.php?section=settings"><i class="fas fa-cog"></i> Configuración</a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item logout-link">
                        <a href="admin_dashboard.php?logout=true"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
                    </li>
                </ul>
            </nav>
        </aside>
        <main class="main-content-wrapper">
            <?php switch ($current_section) {
                case 'overview':
                    // Contenido del Dashboard principal
                    $status = get_xampp_status();
                    $total_pedidos = $conn->query("SELECT COUNT(*) as total FROM pedidos")->fetch_assoc()['total'] ?? 0;
                    $total_productos = $conn->query("SELECT COUNT(*) as total FROM productos")->fetch_assoc()['total'] ?? 0;
                    $total_clientes = $conn->query("SELECT COUNT(*) as total FROM clientes")->fetch_assoc()['total'] ?? 0;
                    $total_usuarios = $conn->query("SELECT COUNT(*) as total FROM usuarios")->fetch_assoc()['total'] ?? 0;

                    $pedidos_hoy = $conn->query("SELECT COUNT(*) as total FROM pedidos WHERE DATE(fecha_pedido) = CURDATE()")->fetch_assoc()['total'] ?? 0;
                    $ingresos_hoy = $conn->query("SELECT SUM(total) as total FROM pedidos WHERE DATE(fecha_pedido) = CURDATE() AND estado = 'entregado'")->fetch_assoc()['total'] ?? 0;
                    $ingresos_mes = $conn->query("SELECT SUM(total) as total FROM pedidos WHERE MONTH(fecha_pedido) = MONTH(CURDATE()) AND YEAR(fecha_pedido) = YEAR(CURDATE()) AND estado = 'entregado'")->fetch_assoc()['total'] ?? 0;
                    $pendientes = $conn->query("SELECT COUNT(*) as total FROM pedidos WHERE estado = 'pendiente' OR estado = 'en_preparacion'")->fetch_assoc()['total'] ?? 0;
                    
                    ?>
                    <div class="section-header">
                        <h2>Dashboard General</h2>
                        <p class="section-description">Resumen y estado del sistema.</p>
                    </div>

                    <div class="row info-cards">
                        <div class="col-md-3">
                            <div class="card card-small card-primary">
                                <h4>Pedidos Pendientes</h4>
                                <p><?php echo $pendientes; ?></p>
                                <i class="fas fa-box"></i>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card card-small card-success">
                                <h4>Pedidos Hoy</h4>
                                <p><?php echo $pedidos_hoy; ?></p>
                                <i class="fas fa-calendar-check"></i>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card card-small card-warning">
                                <h4>Ingresos Hoy</h4>
                                <p>$<?php echo number_format($ingresos_hoy, 2, ',', '.'); ?></p>
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card card-small card-info">
                                <h4>Ingresos Mes</h4>
                                <p>$<?php echo number_format($ingresos_mes, 2, ',', '.'); ?></p>
                                <i class="fas fa-chart-bar"></i>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <h3>Estadísticas de Entidades</h3>
                                <ul class="stat-list">
                                    <li><i class="fas fa-users"></i> Total de Usuarios: <span><?php echo $total_usuarios; ?></span></li>
                                    <li><i class="fas fa-user-tag"></i> Total de Clientes: <span><?php echo $total_clientes; ?></span></li>
                                    <li><i class="fas fa-pizza-slice"></i> Total de Productos: <span><?php echo $total_productos; ?></span></li>
                                    <li><i class="fas fa-inbox"></i> Total de Pedidos: <span><?php echo $total_pedidos; ?></span></li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <h3>Estado del Sistema</h3>
                                <ul class="stat-list">
                                    <li>
                                        <i class="fas fa-server"></i> Servidor Web (Apache): 
                                        <span class="status-indicator status-<?php echo $status['apache']; ?>">
                                            <?php echo ($status['apache'] === 'success') ? 'Operativo' : 'Fallo'; ?>
                                        </span>
                                    </li>
                                    <li>
                                        <i class="fas fa-database"></i> Base de Datos (MySQL): 
                                        <span class="status-indicator status-<?php echo $status['mysql']; ?>">
                                            <?php echo ($status['mysql'] === 'success') ? 'Conectado' : 'Fallo'; ?>
                                        </span>
                                    </li>
                                    <li><i class="fas fa-envelope"></i> Email de Contacto: <span><?php echo htmlspecialchars($config['contact_email']); ?></span></li>
                                    <li><i class="fas fa-sun"></i> Modo de Tema Actual: <span><?php echo ucfirst(htmlspecialchars($current_theme_mode)); ?></span></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <?php
                    break;

                case 'orders':
                    // Contenido de Pedidos 
                    $filter_estado = $_GET['filter_status'] ?? 'all';
                    $query = "SELECT p.id, p.fecha_pedido, p.total, p.estado, c.nombre AS cliente_nombre, c.email AS cliente_email FROM pedidos p LEFT JOIN clientes c ON p.cliente_id = c.id";
                    $where_clauses = [];
                    $params = [];
                    $types = "";

                    if (!empty($filter_estado) && $filter_estado !== 'all') {
                        $where_clauses[] = "p.estado = ?";
                        $params[] = $filter_estado;
                        $types .= "s";
                    }

                    if (!empty($where_clauses)) {
                        $query .= " WHERE " . implode(" AND ", $where_clauses);
                    }
                    $query .= " ORDER BY p.fecha_pedido DESC";

                    if (!empty($params)) {
                        $stmt = $conn->prepare($query);
                        if ($stmt) {
                            $stmt->bind_param($types, ...$params);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $pedidos = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                            $stmt->close();
                        } else {
                            $pedidos = [];
                        }
                    } else {
                        $result = $conn->query($query);
                        $pedidos = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                    }
                    ?>
                    <div class="section-header">
                        <h2>Gestión de Pedidos</h2>
                        <p class="section-description">Visualiza y actualiza el estado de los pedidos. Nota: La gestión en tiempo real se realiza mejor con el panel de cocina (`pedidos.php`).</p>
                    </div>

                    <div class="card filter-bar">
                        <form action="admin_dashboard.php" method="GET" class="form-inline">
                            <input type="hidden" name="section" value="orders">
                            <div class="form-group mr-3">
                                <label for="filter_status" class="mr-2">Filtrar por Estado:</label>
                                <select id="filter_status" name="filter_status" class="form-control" onchange="this.form.submit()">
                                    <option value="all">Todos</option>
                                    <option value="pendiente" <?php echo ($filter_estado === 'pendiente') ? 'selected' : ''; ?>>Pendiente</option>
                                    <option value="en_preparacion" <?php echo ($filter_estado === 'en_preparacion') ? 'selected' : ''; ?>>En Preparación</option>
                                    <option value="listo" <?php echo ($filter_estado === 'listo') ? 'selected' : ''; ?>>Listo</option>
                                    <option value="entregado" <?php echo ($filter_estado === 'entregado') ? 'selected' : ''; ?>>Entregado</option>
                                    <option value="cancelado" <?php echo ($filter_estado === 'cancelado') ? 'selected' : ''; ?>>Cancelado</option>
                                </select>
                            </div>
                            <a href="admin_dashboard.php?section=orders" class="btn btn-secondary">Limpiar Filtro</a>
                        </form>
                    </div>

                    <div class="card table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Fecha/Hora</th>
                                    <th>Cliente</th>
                                    <th>Total</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pedidos as $p): ?>
                                <tr>
                                    <td><?php echo $p['id']; ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($p['fecha_pedido'])); ?></td>
                                    <td><?php echo htmlspecialchars($p['cliente_nombre']); ?></td>
                                    <td>$<?php echo number_format($p['total'], 2, ',', '.'); ?></td>
                                    <td><span class="status-badge status-color-<?php echo $p['estado']; ?>"><?php echo ucfirst(str_replace('_', ' ', $p['estado'])); ?></span></td>
                                    <td>
                                        <a href="ver_pedido.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-primary" title="Ver Detalles del Pedido"><i class="fas fa-eye"></i> Ver Pedido</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                    break;

                case 'users':
                    // Contenido de Usuarios
                    $filter_rol = $_GET['filter_rol'] ?? 'all';
                    $users_query = "SELECT id, nombre, email, rol FROM usuarios";
                    $where_clauses = [];
                    $params = [];
                    $types = "";

                    if (!empty($filter_rol) && $filter_rol !== 'all') {
                        $where_clauses[] = "rol = ?";
                        $params[] = $filter_rol;
                        $types .= "s";
                    }

                    if (!empty($where_clauses)) {
                        $users_query .= " WHERE " . implode(" AND ", $where_clauses);
                    }
                    $users_query .= " ORDER BY rol, nombre";

                    if (!empty($params)) {
                        $stmt = $conn->prepare($users_query);
                        if ($stmt) {
                            $stmt->bind_param($types, ...$params);
                            $stmt->execute();
                            $users_result = $stmt->get_result();
                            $users = $users_result ? $users_result->fetch_all(MYSQLI_ASSOC) : [];
                            $stmt->close();
                        } else {
                            $users = [];
                        }
                    } else {
                        $users_result = $conn->query($users_query);
                        $users = $users_result ? $users_result->fetch_all(MYSQLI_ASSOC) : [];
                    }

                    $editing_user = null;
                    if ($action === 'edit' && $item_id > 0) {
                        $stmt = $conn->prepare("SELECT id, nombre, email, rol FROM usuarios WHERE id = ?");
                        $stmt->bind_param("i", $item_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $editing_user = $result->fetch_assoc();
                        $stmt->close();
                    }
                    ?>
                    <div class="section-header">
                        <h2>Gestión de Usuarios</h2>
                        <p class="section-description">Administra el acceso del personal al sistema (Administradores y Empleados).</p>
                    </div>

                    <div class="card filter-bar">
                        <form action="admin_dashboard.php" method="GET" class="form-inline">
                            <input type="hidden" name="section" value="users">
                            <div class="form-group mr-3">
                                <label for="filter_rol" class="mr-2">Filtrar por Rol:</label>
                                <select id="filter_rol" name="filter_rol" class="form-control" onchange="this.form.submit()">
                                    <option value="all">Todos</option>
                                    <option value="administrador" <?php echo ($filter_rol === 'administrador') ? 'selected' : ''; ?>>Administrador</option>
                                    <option value="empleado" <?php echo ($filter_rol === 'empleado') ? 'selected' : ''; ?>>Empleado</option>
                                    <option value="cocinero" <?php echo ($filter_rol === 'cocinero') ? 'selected' : ''; ?>>Cocinero</option>
                                    <option value="cliente" <?php echo ($filter_rol === 'cliente') ? 'selected' : ''; ?>>Cliente</option>
                                    <option value="panel" <?php echo ($filter_rol === 'panel') ? 'selected' : ''; ?>>Panel</option>
                                </select>
                            </div>
                            <a href="admin_dashboard.php?section=users" class="btn btn-secondary">Cancelar Filtro</a>
                        </form>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="card">
                                <h3><?php echo $editing_user ? 'Editar Usuario' : 'Crear Nuevo Usuario'; ?></h3>
                                <form action="admin_dashboard.php?section=users" method="POST">
                                    <input type="hidden" name="entity_type" value="user">
                                    <input type="hidden" name="action_type" value="<?php echo $editing_user ? 'edit' : 'create'; ?>">
                                    <?php if ($editing_user): ?>
                                        <input type="hidden" name="id" value="<?php echo $editing_user['id']; ?>">
                                    <?php endif; ?>

                                    <div class="form-group">
                                        <label for="nombre">Nombre</label>
                                        <input type="text" id="nombre" name="nombre" class="form-control" value="<?php echo $editing_user ? htmlspecialchars($editing_user['nombre']) : ''; ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="email">Email</label>
                                        <input type="email" id="email" name="email" class="form-control" value="<?php echo $editing_user ? htmlspecialchars($editing_user['email']) : ''; ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="password">Contraseña (Mín. 8 chars, 1 mayús, 1 minús, 1 num, 1 especial)<?php echo $editing_user ? ' - Dejar vacío para no cambiar' : ''; ?></label>
                                        <input type="password" id="password" name="password" class="form-control" <?php echo $editing_user ? '' : 'required'; ?>>
                                    </div>
                                    <div class="form-group">
                                        <label for="rol">Rol</label>
                                        <select id="rol" name="rol" class="form-control" required>
                                            <option value="administrador" <?php echo ($editing_user && $editing_user['rol'] === 'administrador') ? 'selected' : ''; ?>>Administrador</option>
                                            <option value="empleado" <?php echo ($editing_user && $editing_user['rol'] === 'empleado') ? 'selected' : ''; ?>>Empleado</option>
                                            <option value="cocinero" <?php echo ($editing_user && $editing_user['rol'] === 'cocinero') ? 'selected' : ''; ?>>Cocinero</option>
                                            <option value="cliente" <?php echo ($editing_user && $editing_user['rol'] === 'cliente') ? 'selected' : ''; ?>>Cliente</option>
                                            <option value="panel" <?php echo ($editing_user && $editing_user['rol'] === 'panel') ? 'selected' : ''; ?>>Panel</option>
                                        </select>
                                    </div>

                                    <button type="submit" class="btn btn-primary"><?php echo $editing_user ? 'Actualizar Usuario' : 'Crear Usuario'; ?></button>
                                    <?php if ($editing_user): ?>
                                        <a href="admin_dashboard.php?section=users" class="btn btn-secondary">Cancelar</a>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <div class="card table-responsive">
                                <h3>Lista de Usuarios</h3>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Nombre</th>
                                            <th>Email</th>
                                            <th>Rol</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($users as $u): ?>
                                        <tr>
                                            <td><?php echo $u['id']; ?></td>
                                            <td><?php echo htmlspecialchars($u['nombre']); ?></td>
                                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                                            <td><span class="status-badge status-color-<?php echo str_replace(['administrador', 'empleado'], ['admin', 'employee'], $u['rol']); ?>"><?php echo ucfirst($u['rol']); ?></span></td>
                                            <td>
                                                <a href="admin_dashboard.php?section=users&action=edit&id=<?php echo $u['id']; ?>" class="btn btn-sm btn-warning" title="Editar"><i class="fas fa-edit"></i></a>
                                                <?php if ($current_user_rol === 'administrador'): ?>
                                                    <a href="admin_dashboard.php?section=users&action=delete&entity=user&id=<?php echo $u['id']; ?>" class="btn btn-sm btn-danger delete-confirm" title="Eliminar"><i class="fas fa-trash-alt"></i></a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php
                    break;

                case 'products':
                    // Contenido de Productos (CRUD)
                    $products_result = $conn->query("SELECT p.*, c.nombre as categoria_nombre FROM productos p LEFT JOIN categorias_productos c ON p.categoria_id = c.id ORDER BY p.nombre");
                    $products = $products_result ? $products_result->fetch_all(MYSQLI_ASSOC) : [];
                    $categories_result = $conn->query("SELECT id, nombre FROM categorias_productos ORDER BY nombre");
                    $categories = $categories_result ? $categories_result->fetch_all(MYSQLI_ASSOC) : [];
                    
                    $editing_product = null;
                    if ($action === 'edit' && $item_id > 0) {
                        $stmt = $conn->prepare("SELECT * FROM productos WHERE id = ?");
                        $stmt->bind_param("i", $item_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $editing_product = $result->fetch_assoc();
                        $stmt->close();
                    }
                    ?>
                    <div class="section-header">
                        <h2>Gestión de Productos</h2>
                        <p class="section-description">Crea, edita y administra los productos disponibles.</p>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="card">
                                <h3><?php echo $editing_product ? 'Editar Producto' : 'Crear Nuevo Producto'; ?></h3>
                                <form action="admin_dashboard.php?section=products" method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="entity_type" value="product">
                                    <input type="hidden" name="action_type" value="<?php echo $editing_product ? 'edit' : 'create'; ?>">
                                    <?php if ($editing_product): ?>
                                        <input type="hidden" name="id" value="<?php echo $editing_product['id']; ?>">
                                        <input type="hidden" name="current_image" value="<?php echo htmlspecialchars($editing_product['img_path']); ?>">
                                    <?php endif; ?>

                                    <div class="form-group">
                                        <label for="nombre">Nombre</label>
                                        <input type="text" id="nombre" name="nombre" class="form-control" value="<?php echo $editing_product ? htmlspecialchars($editing_product['nombre']) : ''; ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="descripcion">Descripción</label>
                                        <textarea id="descripcion" name="descripcion" class="form-control" rows="3"><?php echo $editing_product ? htmlspecialchars($editing_product['descripcion']) : ''; ?></textarea>
                                    </div>
                                    <div class="form-group">
                                        <label for="precio">Precio ($)</label>
                                        <input type="number" id="precio" name="precio" class="form-control" step="0.01" min="0" value="<?php echo $editing_product ? htmlspecialchars($editing_product['precio']) : ''; ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="stock">Stock (Dejar vacío para ∞ Ilimitado)</label>
                                        <input type="text" id="stock" name="stock" class="form-control" value="<?php echo $editing_product && $editing_product['stock'] !== '∞' ? htmlspecialchars($editing_product['stock']) : ''; ?>" placeholder="Ej: 50 o dejar vacío para ilimitado" pattern="[0-9]*" title="Solo se permiten números. Deje vacío para stock ilimitado (∞).">
                                    </div>
                                    <div class="form-group">
                                        <label for="categoria_id">Categoría</label>
                                        <select id="categoria_id" name="categoria_id" class="form-control" required>
                                            <?php foreach ($categories as $c): ?>
                                                <option value="<?php echo $c['id']; ?>" <?php echo ($editing_product && $editing_product['categoria_id'] == $c['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($c['nombre']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label for="img_path">Imagen del Producto</label>
                                        <input type="file" id="img_path" name="img_path" class="form-control-file" accept="image/*">
                                        <?php if ($editing_product && $editing_product['img_path']): ?>
                                            <small class="form-text text-muted">Imagen actual: <a href="<?php echo htmlspecialchars($editing_product['img_path']); ?>" target="_blank">Ver imagen</a></small>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="form-group form-check">
                                        <input type="checkbox" class="form-check-input" id="disponible" name="disponible" value="1" <?php echo ($editing_product === null || $editing_product['disponible'] == 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="disponible">Disponible para venta</label>
                                    </div>

                                    <button type="submit" class="btn btn-primary"><?php echo $editing_product ? 'Actualizar Producto' : 'Crear Producto'; ?></button>
                                    <?php if ($editing_product): ?>
                                        <a href="admin_dashboard.php?section=products" class="btn btn-secondary">Cancelar</a>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <div class="card table-responsive">
                                <h3>Lista de Productos</h3>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Nombre</th>
                                            <th>Categoría</th>
                                            <th>Precio</th>
                                            <th>Stock</th>
                                            <th>Disponible</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($products as $p): ?>
                                        <tr>
                                            <td><?php echo $p['id']; ?></td>
                                            <td><?php echo htmlspecialchars($p['nombre']); ?></td>
                                            <td><?php echo htmlspecialchars($p['categoria_nombre']); ?></td>
                                            <td>$<?php echo number_format($p['precio'], 2, ',', '.'); ?></td>
                                            <td><?php echo format_stock($p['stock']); ?></td>
                                            <td>
                                                <span class="status-badge status-color-<?php echo $p['disponible'] ? 'success' : 'danger'; ?>">
                                                    <?php echo $p['disponible'] ? 'Sí' : 'No'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="admin_dashboard.php?section=products&action=edit&id=<?php echo $p['id']; ?>" class="btn btn-sm btn-warning" title="Editar"><i class="fas fa-edit"></i></a>
                                                <?php if ($current_user_rol === 'administrador'): ?>
                                                    <a href="admin_dashboard.php?section=products&action=delete&entity=product&id=<?php echo $p['id']; ?>" class="btn btn-sm btn-danger delete-confirm" title="Eliminar"><i class="fas fa-trash-alt"></i></a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php
                    break;

                case 'categories':
                    // Contenido de Categorías (CRUD)
                    $categories_query = $conn->query("SELECT * FROM categorias_productos ORDER BY nombre");
                    $categories_list = $categories_query ? $categories_query->fetch_all(MYSQLI_ASSOC) : [];
                    
                    $editing_category = null;
                    if ($action === 'edit' && $item_id > 0) {
                        $stmt = $conn->prepare("SELECT * FROM categorias_productos WHERE id = ?");
                        $stmt->bind_param("i", $item_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $editing_category = $result->fetch_assoc();
                        $stmt->close();
                    }
                    ?>
                    <div class="section-header">
                        <h2>Gestión de Categorías</h2>
                        <p class="section-description">Administra las categorías de productos.</p>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="card">
                                <h3><?php echo $editing_category ? 'Editar Categoría' : 'Crear Nueva Categoría'; ?></h3>
                                <form action="admin_dashboard.php?section=categories" method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="entity_type" value="category">
                                    <input type="hidden" name="action_type" value="<?php echo $editing_category ? 'edit' : 'create'; ?>">
                                    <?php if ($editing_category): ?>
                                        <input type="hidden" name="id" value="<?php echo $editing_category['id']; ?>">
                                        <input type="hidden" name="current_category_image" value="<?php echo htmlspecialchars($editing_category['img_path']); ?>">
                                    <?php endif; ?>

                                    <div class="form-group">
                                        <label for="nombre">Nombre</label>
                                        <input type="text" id="nombre" name="nombre" class="form-control" value="<?php echo $editing_category ? htmlspecialchars($editing_category['nombre']) : ''; ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="descripcion">Descripción</label>
                                        <textarea id="descripcion" name="descripcion" class="form-control" rows="3"><?php echo $editing_category ? htmlspecialchars($editing_category['descripcion']) : ''; ?></textarea>
                                    </div>
                                    <div class="form-group">
                                        <label for="img_path">Imagen de la Categoría</label>
                                        <input type="file" id="img_path" name="img_path" class="form-control-file" accept="image/*">
                                        <?php if ($editing_category && $editing_category['img_path']): ?>
                                            <small class="form-text text-muted">Imagen actual: <a href="<?php echo htmlspecialchars($editing_category['img_path']); ?>" target="_blank">Ver imagen</a></small>
                                        <?php endif; ?>
                                    </div>

                                    <button type="submit" class="btn btn-primary"><?php echo $editing_category ? 'Actualizar Categoría' : 'Crear Categoría'; ?></button>
                                    <?php if ($editing_category): ?>
                                        <a href="admin_dashboard.php?section=categories" class="btn btn-secondary">Cancelar</a>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <div class="card table-responsive">
                                <h3>Lista de Categorías</h3>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Nombre</th>
                                            <th>Descripción</th>
                                            <th>Productos (Count)</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        foreach ($categories_list as $c): 
                                            $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM productos WHERE categoria_id = ?");
                                            $count_stmt->bind_param("i", $c['id']);
                                            $count_stmt->execute();
                                            $count_result = $count_stmt->get_result();
                                            $product_count = $count_result->fetch_assoc()['total'];
                                            $count_stmt->close();
                                        ?>
                                        <tr>
                                            <td><?php echo $c['id']; ?></td>
                                            <td><?php echo htmlspecialchars($c['nombre']); ?></td>
                                            <td><?php echo htmlspecialchars($c['descripcion']); ?></td>
                                            <td><?php echo $product_count; ?></td>
                                            <td>
                                                <a href="admin_dashboard.php?section=categories&action=edit&id=<?php echo $c['id']; ?>" class="btn btn-sm btn-warning" title="Editar"><i class="fas fa-edit"></i></a>
                                                <?php if ($current_user_rol === 'administrador'): ?>
                                                    <a href="admin_dashboard.php?section=categories&action=delete&entity=category&id=<?php echo $c['id']; ?>" class="btn btn-sm btn-danger delete-confirm" title="Eliminar"><i class="fas fa-trash-alt"></i></a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php
                    break;

                case 'reports':
                    // Contenido de Reportes (Ingresos por Día)
                    $query_ingresos = "
                        SELECT 
                            DATE(fecha_pedido) AS fecha, 
                            SUM(total) AS ingresos_diarios
                        FROM 
                            pedidos
                        WHERE 
                            estado = 'entregado'
                        GROUP BY 
                            fecha
                        ORDER BY 
                            fecha DESC
                    ";
                    
                    $result_ingresos = $conn->query($query_ingresos);
                    $ingresos_por_dia = $result_ingresos ? $result_ingresos->fetch_all(MYSQLI_ASSOC) : [];
                    
                    $export_url = "admin_dashboard.php?section=reports&action=export_orders";
                    
                    ?>
                    <div class="section-header">
                        <h2>Reportes y Estadísticas</h2>
                        <p class="section-description">Análisis de ingresos y datos del sistema.</p>
                    </div>

                    <div class="card mb-4">
                        <h3>Ingresos Totales por Día</h3>
                        
                        <div class="card-actions">
                            <a href="<?php echo htmlspecialchars($export_url); ?>" class="btn btn-primary" title="Exportar Pedidos Completos">
                                <i class="fas fa-file-csv mr-2"></i> Exportar Pedidos
                            </a>
                        </div>
                        
                        <?php if (!empty($ingresos_por_dia)): ?>
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Total de Ingresos</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $ingreso_total_historico = 0;
                                        foreach ($ingresos_por_dia as $ingreso): 
                                            $ingreso_total_historico += $ingreso['ingresos_diarios'];
                                        ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y', strtotime($ingreso['fecha'])); ?></td>
                                            <td>$<?php echo number_format($ingreso['ingresos_diarios'], 2, ',', '.'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <th style="text-align: right;">Ingreso Total Histórico:</th>
                                            <th>$<?php echo number_format($ingreso_total_historico, 2, ',', '.'); ?></th>
                                        </tr>
                                    </tfoot>
                                
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mt-3">
                                No hay ingresos registrados con estado 'Entregado' para mostrar en este reporte.
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php 
                    break;
                case 'log_changes':
                    // Contenido de Log de Cambios (solo visible para admin)
                    if ($current_user_rol !== 'administrador') {
                         echo '<div class="section-header"><h2>Permiso Denegado</h2><p class="section-description">Solo los administradores pueden acceder al log de cambios.</p></div>';
                         break;
                    }
                    $log_result = $conn->query("SELECT lc.*, u.nombre as user_name FROM registro_cambios lc LEFT JOIN usuarios u ON lc.usuario_id = u.id ORDER BY lc.fecha DESC LIMIT 500");
                    $logs = $log_result ? $log_result->fetch_all(MYSQLI_ASSOC) : [];
                    ?>
                    <div class="section-header">
                        <h2>Log de Cambios (Auditoría)</h2>
                        <p class="section-description">Registro de todas las acciones CRUD realizadas por el personal.</p>
                    </div>

                    <div class="card table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Usuario</th>
                                    <th>Email</th>
                                    <th>Rol</th>
                                    <th>Acción</th>
                                    <th>Entidad</th>
                                    <th>ID Entidad</th>
                                    <th>Descripción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y H:i:s', strtotime($log['fecha'])); ?></td>
                                    <td><?php echo htmlspecialchars($log['user_name'] ?? 'ID: ' . $log['usuario_id']); ?></td>
                                    <td><?php echo htmlspecialchars($log['email_usuario'] ?? 'N/A'); ?></td>
                                    <td><span class="status-badge status-color-<?php echo str_replace(['administrador', 'empleado'], ['admin', 'employee'], $log['user_rol']); ?>"><?php echo ucfirst($log['user_rol']); ?></span></td>
                                    <td><?php echo htmlspecialchars($log['action_type']); ?></td>
                                    <td><?php echo htmlspecialchars($log['entity_type']); ?></td>
                                    <td><?php echo $log['entity_id'] > 0 ? $log['entity_id'] : 'N/A'; ?></td>
                                    <td><?php echo htmlspecialchars($log['description']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                    break;

                case 'settings':
                    // Contenido de Configuración (solo visible para admin)
                    if ($current_user_rol !== 'administrador') {
                         echo '<div class="section-header"><h2>Permiso Denegado</h2><p class="section-description">Solo los administradores pueden acceder a la configuración del sistema.</p></div>';
                         break;
                    }
                    ?>
                    <div class="section-header">
                        <h2>Configuración del Sistema</h2>
                        <p class="section-description">Ajustes globales de la compañía y opciones de pago.</p>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <h3>Información General</h3>
                                <form action="admin_dashboard.php?section=settings" method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="entity_type" value="settings">
                                    <input type="hidden" name="action_type" value="update">

                                    <div class="form-group">
                                        <label for="company_name">Nombre de la Compañía</label>
                                        <input type="text" id="company_name" name="company_name" class="form-control" value="<?php echo htmlspecialchars($config['company_name']); ?>" required>
                                    </div>

                                    <div class="form-group">
                                        <label for="contact_email">Email de Contacto</label>
                                        <input type="email" id="contact_email" name="contact_email" class="form-control" value="<?php echo htmlspecialchars($config['contact_email']); ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="theme_mode">Tema por Defecto</label>
                                        <select id="theme_mode" name="theme_mode" class="form-control" required>
                                            <option value="light" <?php echo ($config['theme_mode'] === 'light') ? 'selected' : ''; ?>>Claro (Light)</option>
                                            <option value="dark" <?php echo ($config['theme_mode'] === 'dark') ? 'selected' : ''; ?>>Oscuro (Dark)</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="logo_path">Logo de la Compañía</label>
                                        <input type="file" id="logo_path" name="img_path" class="form-control-file" accept="image/*">
                                        <small class="form-text text-muted">Logo actual: <a href="<?php echo htmlspecialchars($config['logo_path']); ?>" target="_blank">Ver logo</a></small>
                                    </div>

                                    <button type="submit" class="btn btn-primary">Guardar Configuración General</button>
                                </form>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card">
                                <h3>Opciones de Pago</h3>
                                <form action="admin_dashboard.php?section=settings" method="POST">
                                    <input type="hidden" name="entity_type" value="settings">
                                    <input type="hidden" name="action_type" value="update">
                                    
                                    <input type="hidden" name="company_name" value="<?php echo htmlspecialchars($config['company_name']); ?>">
                                    <input type="hidden" name="contact_email" value="<?php echo htmlspecialchars($config['contact_email']); ?>">
                                    <input type="hidden" name="theme_mode" value="<?php echo htmlspecialchars($config['theme_mode']); ?>">
                                    
                                    <div class="form-group form-check">
                                        <input type="checkbox" class="form-check-input" id="mercadopago_active" name="mercadopago_active" value="1" <?php echo ($config['mercadopago_active'] == '1') ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="mercadopago_active">Activar Mercado Pago</label>
                                    </div>
                                    
                                    <h4>Opciones de Transferencia/Efectivo</h4>
                                    <div class="form-group">
                                        <label for="other_payment_options">Otros Métodos (Ej: Efectivo, Débito)</label>
                                        <input type="text" id="other_payment_options" name="other_payment_options" class="form-control" value="<?php echo htmlspecialchars($config['other_payment_options']); ?>" placeholder="Separe las opciones con comas">
                                    </div>
                                    <div class="form-group">
                                        <label for="transfer_alias">Alias de Transferencia</label>
                                        <input type="text" id="transfer_alias" name="transfer_alias" class="form-control" value="<?php echo htmlspecialchars($config['transfer_alias']); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="transfer_cbu_cvu">CBU/CVU de Transferencia</label>
                                        <input type="text" id="transfer_cbu_cvu" name="transfer_cbu_cvu" class="form-control" value="<?php echo htmlspecialchars($config['transfer_cbu_cvu']); ?>">
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">Guardar Configuración de Pago</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php
                    break;
                
                default:
                    // Página de error 404
                    ?>
                    <div class="section-header">
                        <h2>Error 404</h2>
                        <p class="section-description">La sección solicitada no existe.</p>
                    </div>
                    <?php
                    break;
            } ?>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Confirmación para eliminación
            document.querySelectorAll('.delete-confirm').forEach(link => {
                link.addEventListener('click', (e) => {
                    if (!confirm('¿Estás seguro de que quieres eliminar este elemento? Esta acción es irreversible.')) {
                        e.preventDefault();
                    }
                });
            });

        });
    </script>
    
    <?php
    // Cierre de la conexión a la BD
    $conn->close();
    ?>
    <script src="./../js/script.js"></script> 
</body>
</html>
