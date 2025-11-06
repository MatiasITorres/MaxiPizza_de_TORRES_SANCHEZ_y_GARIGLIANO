<?php
// productos.php - Gestión de Inventario, Categorías y Modificaciones
// Debe ubicarse en la carpeta /empleado/

// ----------------------------------------------------
// 0. DEPENDENCIAS Y CONFIGURACIÓN INICIAL
// ----------------------------------------------------
session_start();
require_once './../config.php'; // Requiere la configuración de la DB

// Conexión a la Base de Datos
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Redirección si el rol no es 'empleado'
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'empleado') {
    header("Location: ./../index.php");
    exit();
}

// LÓGICA DE CONFIGURACIÓN DE EMPRESA (JSON)
$settings = [];
$config_file_path = __DIR__ . '/../admin/config_data.json'; 
if (file_exists($config_file_path)) {
    $settings = json_decode(file_get_contents($config_file_path), true);
}

$company_name = $settings['company_name'] ?? 'SGPP Default';
$logo_path = $settings['logo_path'] ?? './../img/SGPP.jpg';
$theme_mode = $settings['theme_mode'] ?? 'light';

// ----------------------------------------------------
// 1. MANEJADOR DE ACCIONES (AJAX CRUD)
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    $action = $_POST['action'];

    try {
        switch ($action) {
            // --- PRODUCTOS CRUD (MANTENIDO) ---
            case 'save_product':
                $id = (int)($_POST['product_id'] ?? 0);
                $nombre = trim($_POST['nombre']);
                $precio = floatval($_POST['precio']);
                $categoria_id = (int)$_POST['categoria_id'];

                if (empty($nombre) || $precio <= 0 || $categoria_id <= 0) {
                    throw new Exception("Datos incompletos o inválidos.");
                }

                if ($id > 0) {
                    $stmt = $conn->prepare("UPDATE productos SET nombre = ?, precio = ?, categoria_id = ? WHERE id = ?");
                    $stmt->bind_param("sdis", $nombre, $precio, $categoria_id, $id);
                    $message = "Producto actualizado con éxito.";
                } else {
                    $stmt = $conn->prepare("INSERT INTO productos (nombre, precio, categoria_id) VALUES (?, ?, ?)");
                    $stmt->bind_param("sdi", $nombre, $precio, $categoria_id);
                    $message = "Producto creado con éxito.";
                }
                
                if (!$stmt->execute()) {
                    throw new Exception("Error al guardar el producto: " . $stmt->error);
                }
                $response['success'] = true;
                $response['message'] = $message;
                $stmt->close();
                break;
                
            case 'delete_product':
                $id = (int)$_POST['product_id'];
                if ($id <= 0) throw new Exception("ID de producto inválido.");
                
                $conn->begin_transaction();
                
                $stmt_mods = $conn->prepare("DELETE FROM modificaciones_productos WHERE producto_id = ?");
                $stmt_mods->bind_param("i", $id);
                $stmt_mods->execute();
                $stmt_mods->close();
                
                $stmt = $conn->prepare("DELETE FROM productos WHERE id = ?");
                $stmt->bind_param("i", $id);
                if (!$stmt->execute()) {
                    $conn->rollback();
                    throw new Exception("Error al eliminar el producto.");
                }
                $conn->commit();
                
                $response['success'] = true;
                $response['message'] = "Producto eliminado con éxito.";
                $stmt->close();
                break;

            // --- CATEGORIAS CRUD (MANTENIDO) ---
            case 'save_category':
                $id = (int)($_POST['category_id'] ?? 0);
                $nombre = trim($_POST['nombre']);

                if (empty($nombre)) {
                    throw new Exception("El nombre de la categoría no puede estar vacío.");
                }

                if ($id > 0) {
                    $stmt = $conn->prepare("UPDATE categorias_productos SET nombre = ? WHERE id = ?");
                    $stmt->bind_param("si", $nombre, $id);
                    $message = "Categoría actualizada con éxito.";
                } else {
                    $stmt = $conn->prepare("INSERT INTO categorias_productos (nombre) VALUES (?)");
                    $stmt->bind_param("s", $nombre);
                    $message = "Categoría creada con éxito.";
                }
                
                if (!$stmt->execute()) {
                    throw new Exception("Error al guardar la categoría: " . $stmt->error);
                }
                $response['success'] = true;
                $response['message'] = $message;
                $stmt->close();
                break;

            case 'delete_category':
                $id = (int)$_POST['category_id'];
                if ($id <= 0) throw new Exception("ID de categoría inválido.");
                
                $stmt_check = $conn->prepare("SELECT COUNT(*) FROM productos WHERE categoria_id = ?");
                $stmt_check->bind_param("i", $id);
                $stmt_check->execute();
                $stmt_check->bind_result($count);
                $stmt_check->fetch();
                $stmt_check->close();
                
                if ($count > 0) {
                    throw new Exception("No se puede eliminar la categoría porque tiene $count productos asociados. Reasigna o elimina los productos primero.");
                }

                $stmt = $conn->prepare("DELETE FROM categorias_productos WHERE id = ?");
                $stmt->bind_param("i", $id);
                if (!$stmt->execute()) {
                    throw new Exception("Error al eliminar la categoría.");
                }
                $response['success'] = true;
                $response['message'] = "Categoría eliminada con éxito.";
                $stmt->close();
                break;

            // --- MODIFICACIONES CRUD (ADAPTADO PARA CANTIDAD) ---
            case 'save_modification':
                $id = (int)($_POST['modification_id'] ?? 0);
                $producto_id = (int)$_POST['mod_producto_id'];
                $nombre = trim($_POST['mod_nombre']);
                $precio_adicional = floatval($_POST['mod_precio_adicional']);
                $categoria_id = (int)$_POST['mod_categoria_id']; 
                $cantidad = (int)($_POST['mod_cantidad'] ?? 1); // <-- CAMBIO: NUEVO CAMPO CANTIDAD

                if (empty($nombre) || $producto_id <= 0 || $precio_adicional < 0 || $categoria_id <= 0 || $cantidad < 1) { // <-- Validación de cantidad
                    throw new Exception("Datos incompletos o inválidos para la modificación. Asegúrese de seleccionar una categoría y una cantidad límite válida (mínimo 1).");
                }

                if ($id > 0) {
                    // EDITAR modificación (Se añade 'cantidad' al UPDATE)
                    $stmt = $conn->prepare("UPDATE modificaciones_productos SET nombre = ?, precio_adicional = ?, categoria_id = ?, cantidad = ? WHERE id = ? AND producto_id = ?");
                    $stmt->bind_param("sdiisi", $nombre, $precio_adicional, $categoria_id, $cantidad, $id, $producto_id); 
                    $message = "Modificación actualizada con éxito.";
                } else {
                    // AÑADIR modificación (Se añade 'cantidad' al INSERT)
                    $stmt = $conn->prepare("INSERT INTO modificaciones_productos (producto_id, nombre, precio_adicional, categoria_id, cantidad) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("isdii", $producto_id, $nombre, $precio_adicional, $categoria_id, $cantidad);
                    $message = "Modificación creada con éxito.";
                }
                
                if (!$stmt->execute()) {
                    throw new Exception("Error al guardar la modificación: " . $stmt->error);
                }
                $response['success'] = true;
                $response['message'] = $message;
                $stmt->close();
                break;
                
            case 'delete_modification':
                $id = (int)$_POST['modification_id'];
                if ($id <= 0) throw new Exception("ID de modificación inválido.");
                
                $stmt = $conn->prepare("DELETE FROM modificaciones_productos WHERE id = ?");
                $stmt->bind_param("i", $id);
                if (!$stmt->execute()) {
                    throw new Exception("Error al eliminar la modificación.");
                }
                $response['success'] = true;
                $response['message'] = "Modificación eliminada con éxito.";
                $stmt->close();
                break;
                
            case 'fetch_modifications':
                $producto_id = (int)$_POST['producto_id'];
                
                // Consulta con JOIN para obtener el nombre de la categoría de la modificación y la CANTIDAD
                $stmt = $conn->prepare("SELECT m.id, m.nombre, m.precio_adicional, m.categoria_id, m.cantidad, c.nombre as categoria_nombre 
                                         FROM modificaciones_productos m
                                         LEFT JOIN categorias_productos c ON m.categoria_id = c.id
                                         WHERE m.producto_id = ?
                                         ORDER BY m.categoria_id ASC, m.nombre ASC");
                $stmt->bind_param("i", $producto_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $mods_grouped = [];
                while ($row = $result->fetch_assoc()) {
                    $category_id = $row['categoria_id'] ?? 0;
                    $category_name = $row['categoria_nombre'] ?? 'Sin Categoría Asignada'; 

                    if (!isset($mods_grouped[$category_id])) {
                        $mods_grouped[$category_id] = [
                            'id' => $category_id,
                            'name' => $category_name,
                            'items' => []
                        ];
                    }
                    $mods_grouped[$category_id]['items'][] = $row;
                }
                $stmt->close();
                
                $response['success'] = true;
                $response['modifications'] = array_values($mods_grouped); 
                $response['message'] = 'Modificaciones cargadas y agrupadas.';
                break;
                
            default:
                throw new Exception('Acción no válida.');
        }
    } catch (Exception $e) {
        $response['message'] = 'Error: ' . $e->getMessage();
    }
    
    echo json_encode($response);
    exit();
}

// ----------------------------------------------------
// 2. CARGA DE DATOS PARA LA VISTA (MANTENIDO)
// ----------------------------------------------------

// Cargar categorías disponibles (para productos y modificaciones)
$categorias_disponibles = [];
$result_cats = $conn->query("SELECT id, nombre FROM categorias_productos ORDER BY nombre ASC");
if ($result_cats) {
    while ($row = $result_cats->fetch_assoc()) {
        $categorias_disponibles[] = $row;
    }
}

// Cargar productos con sus categorías
$productos = [];
$sql_productos = "SELECT p.id, p.nombre, p.precio, p.categoria_id, c.nombre as categoria_nombre 
                  FROM productos p 
                  JOIN categorias_productos c ON p.categoria_id = c.id
                  ORDER BY p.nombre ASC";
$result_productos = $conn->query($sql_productos);

if ($result_productos) {
    while ($row = $result_productos->fetch_assoc()) {
        $productos[] = $row;
    }
}

$conn->close();

// ----------------------------------------------------
// 3. ESTRUCTURA HTML, ESTILOS Y MODALES
// ----------------------------------------------------
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($company_name); ?> | Gestión de Productos</title>
    <link rel="stylesheet" href="./a.css"> <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
/* ========================================================= */
/* VARIABLES Y ESTILOS BASE (Consolidados del Dashboard) */
/* ========================================================= */
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');

:root {
    --primary-color: #c0392b; 
    --secondary-color: #e67e22; 
    --text-color: #343a40; 
    --background-color: #f4f6f9; 
    --card-background: #ffffff;
    --border-color: #e9ecef;
    --success-color: #27ae60; 
    --error-color: #e74c3c;
    --info-color: #3498db; 
    font-family: 'Poppins', sans-serif;
}

/* DARK MODE - Colores consistentes con el dashboard */
.dark-mode {
    --text-color: #ecf0f1;
    --background-color: #2c3e50; 
    --card-background: #34495e; 
    --border-color: #44586d;
}
.dark-mode body { background-color: var(--background-color); color: var(--text-color); }
.dark-mode .card, .dark-mode .modal-content { background-color: var(--card-background); }
.dark-mode input[type="text"], .dark-mode input[type="number"], .dark-mode select, .dark-mode textarea {
    background-color: #44586d;
    border: 1px solid var(--border-color);
    color: var(--text-color);
}
.dark-mode table, .dark-mode th, .dark-mode td { border-color: #44586d; }
.dark-mode tr:nth-child(even) { background-color: #2c3e50; }
.dark-mode .modification-group { background-color: #44586d; }
.dark-mode .modification-group-title { color: var(--secondary-color); border-bottom-color: var(--border-color); }

/* Estilos Generales y CRUD */
* { margin: 0; padding: 0; box-sizing: border-box; }
body { line-height: 1.6; transition: background-color 0.3s, color 0.3s; }
.main-container { max-width: 1200px; margin: 20px auto; padding: 0 20px; }
.card {
    background-color: var(--card-background);
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    margin-bottom: 20px;
}
.card h2, .card h4 {
    border-bottom: 1px solid var(--border-color);
    padding-bottom: 10px;
    margin-bottom: 15px;
    color: var(--primary-color);
}
.text-primary { color: var(--primary-color); }

/* Botones */
.btn {
    padding: 10px 15px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    transition: background-color 0.2s;
    font-weight: 500;
    margin-right: 5px;
}
.btn-small { padding: 6px 12px; font-size: 0.9rem; }
.btn-primary { background-color: var(--primary-color); color: white; }
.btn-success { background-color: var(--success-color); color: white; }
.btn-warning { background-color: var(--secondary-color); color: white; }
.btn-delete { background-color: var(--error-color); color: white; }
.btn-icon { width: 35px; height: 35px; padding: 0; border-radius: 50%; }
.btn:hover { opacity: 0.9; }

/* Formulario */
.form-group { margin-bottom: 15px; }
.form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
.form-group input, .form-group select {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--border-color);
    border-radius: 5px;
    box-sizing: border-box;
}

/* Tablas */
.table-responsive { overflow-x: auto; }
table {
    width: 100%;
    border-collapse: collapse;
}
th, td {
    padding: 12px 15px;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}
th {
    background-color: var(--primary-color);
    color: white;
    font-weight: 600;
}
tr:nth-child(even) {
    background-color: #f8f9fa;
}
.dark-mode tr:nth-child(even) { background-color: #3a506b; }

.table-actions { 
    display: flex; 
    gap: 5px; 
    flex-wrap: wrap;
}

/* Modals */
.modal {
    display: none; 
    position: fixed; 
    z-index: 1000; 
    left: 0;
    top: 0;
    width: 100%; 
    height: 100%; 
    overflow: auto; 
    background-color: rgba(0,0,0,0.4); 
    align-items: center;
    justify-content: center;
}
.modal-content {
    background-color: var(--card-background);
    margin: auto;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    width: 90%;
    max-width: 700px;
    position: relative;
}
.close-button {
    color: var(--text-color);
    float: right;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
}
.close-button:hover, .close-button:focus {
    color: var(--primary-color);
    text-decoration: none;
}

/* Alertas y Mensajes de estado */
.status-message-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1001;
}
.alert {
    padding: 15px;
    margin-bottom: 10px;
    border-radius: 5px;
    font-weight: 600;
    opacity: 0.95;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}
.alert-success { background-color: var(--success-color); color: white; }
.alert-error { background-color: var(--error-color); color: white; }
.alert-info { background-color: var(--info-color); color: white; }

/* Estilos de Modificaciones */
.modification-item-display {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 5px 0;
    border-bottom: 1px dashed var(--border-color);
    font-size: 0.9rem;
}
.mod-price {
    color: var(--success-color);
    font-weight: 600;
    margin-right: 10px;
}

/* --- ESTILOS PARA AGRUPACIÓN DE MODIFICACIONES (Mantenidos) --- */
.modification-group {
    margin-bottom: 20px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    padding: 10px;
    background-color: var(--background-color);
}

.modification-group-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--secondary-color);
    margin-bottom: 10px;
    padding-bottom: 5px;
    border-bottom: 1px dashed var(--border-color);
}

.modification-group .modification-item-display:last-child {
    border-bottom: none; 
}


/* ========================================================= */
/* ESTILOS DE HEADER Y NAVEGACIÓN (Consolidados del Dashboard) */
/* ========================================================= */
.main-header {
    background-color: var(--card-background);
    padding: 15px 20px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.dark-mode .main-header {
    background-color: var(--card-background); 
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.5);
}
.logo {
    display: flex;
    align-items: center;
}
.header-logo {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    margin-right: 10px;
    object-fit: cover;
}
.logo-tag {
    font-size: 1.2rem;
    font-weight: 600;
    color: var(--primary-color);
}
.header-nav ul {
    list-style: none;
    display: flex;
    align-items: center;
    gap: 20px;
}
.header-nav a {
    text-decoration: none;
    color: var(--text-color);
    padding: 5px 10px;
    border-radius: 5px;
    transition: background-color 0.2s, color 0.2s;
}
.header-nav a:hover, .header-nav a.active {
    background-color: var(--primary-color);
    color: white;
}
.user-profile {
    color: var(--secondary-color);
    font-weight: 500;
}
.logout-link {
    background-color: var(--error-color) !important;
    color: white !important;
}

/* SWITCH DARK MODE (Consolidado del Dashboard) */
.theme-switch-wrapper {
    display: flex;
    align-items: center;
}
.theme-switch {
    display: inline-block;
    height: 34px;
    position: relative;
    width: 60px;
}
.theme-switch input {
    display: none;
}
.slider {
    background-color: #ccc;
    bottom: 0;
    cursor: pointer;
    left: 0;
    position: absolute;
    right: 0;
    top: 0;
    transition: 0.4s;
}
.slider:before {
    background-color: #fff;
    bottom: 4px;
    content: "";
    height: 26px;
    left: 4px;
    position: absolute;
    transition: 0.4s;
    width: 26px;
}
input:checked + .slider {
    background-color: var(--primary-color);
}
input:checked + .slider:before {
    transform: translateX(26px);
}
.slider.round {
    border-radius: 34px;
}
.slider.round:before {
    border-radius: 50%;
}
    </style>
</head>
<body class="<?php echo $theme_mode . '-mode'; ?>">
    
    <header class="main-header">
        <div class="logo">
            <img src="<?php echo htmlspecialchars($logo_path); ?>" alt="Logo Empresa" class="header-logo">
            <span class="logo-tag"><?php echo htmlspecialchars($company_name); ?></span>
        </div>
        <nav class="header-nav">
            <ul>
                <li><a href="./empleado_dashboard.php">Pedidos</a></li>
                <li><a href="./productos.php" class="active">Inventario</a></li>
                <li>
                    <div class="theme-switch-wrapper">
                        <label class="theme-switch" for="darkModeToggle">
                            <input type="checkbox" id="darkModeToggle" />
                            <div class="slider round"></div>
                        </label>
                    </div>
                </li>
                <li>
                    <span class="user-profile"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Empleado'); ?></span>
                </li>
                <li>
                    <a href="./../logout.php" class="logout-link"><i class="fas fa-sign-out-alt"></i> Salir</a>
                </li>
            </ul>
        </nav>
    </header>
    
    <div id="status-message-container" class="status-message-container"></div>

    <div class="main-container">
        
        <div class="card">
            <h2><i class="fas fa-box-open"></i> Gestión de Productos</h2>
            <button class="btn btn-primary" id="add-product-btn"><i class="fas fa-plus"></i> Añadir Producto</button>
            <div class="table-responsive" style="margin-top: 20px;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Precio</th>
                            <th>Categoría</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="products-table-body">
                        <?php foreach ($productos as $p): ?>
                            <tr data-product-id="<?= $p['id'] ?>" data-nombre="<?= htmlspecialchars($p['nombre']) ?>" data-precio="<?= $p['precio'] ?>" data-categoria-id="<?= $p['categoria_id'] ?>">
                                <td><?= $p['id'] ?></td>
                                <td><?= htmlspecialchars($p['nombre']) ?></td>
                                <td>$<?= number_format($p['precio'], 2) ?></td>
                                <td><?= htmlspecialchars($p['categoria_nombre']) ?></td>
                                <td class="table-actions">
                                    <button class="btn btn-small btn-info btn-icon manage-mods-btn" title="Gestionar Modificaciones"><i class="fas fa-cogs"></i></button>
                                    <button class="btn btn-small btn-warning btn-icon edit-product-btn" title="Editar Producto"><i class="fas fa-edit"></i></button>
                                    <button class="btn btn-small btn-delete btn-icon delete-product-btn" title="Eliminar Producto"><i class="fas fa-trash-alt"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h2><i class="fas fa-tags"></i> Gestión de Categorías de Productos</h2>
            <button class="btn btn-primary" id="add-category-btn"><i class="fas fa-plus"></i> Añadir Categoría</button>
            <div class="table-responsive" style="margin-top: 20px;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="categories-table-body">
                        <?php foreach ($categorias_disponibles as $c): ?>
                            <tr data-category-id="<?= $c['id'] ?>" data-nombre="<?= htmlspecialchars($c['nombre']) ?>">
                                <td><?= $c['id'] ?></td>
                                <td><?= htmlspecialchars($c['nombre']) ?></td>
                                <td class="table-actions">
                                    <button class="btn btn-small btn-warning btn-icon edit-category-btn" title="Editar Categoría"><i class="fas fa-edit"></i></button>
                                    <button class="btn btn-small btn-delete btn-icon delete-category-btn" title="Eliminar Categoría"><i class="fas fa-trash-alt"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    </div>
    
    <div id="product-modal" class="modal">
        <div class="modal-content">
            <span class="close-button" data-modal="product-modal">&times;</span>
            <h3 id="product-modal-title"><i class="fas fa-box"></i> Nuevo Producto</h3>
            <form id="product-form">
                <input type="hidden" id="product-id" name="product_id" value="0">
                <div class="form-group">
                    <label for="nombre">Nombre del Producto:</label>
                    <input type="text" id="nombre" name="nombre" required>
                </div>
                <div class="form-group">
                    <label for="precio">Precio ($):</label>
                    <input type="number" id="precio" name="precio" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label for="categoria_id">Categoría Principal:</label>
                    <select id="categoria_id" name="categoria_id" required>
                        <option value="">-- Seleccione Categoría --</option>
                        <?php foreach ($categorias_disponibles as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-success" id="save-product-btn">Guardar Producto</button>
            </form>
        </div>
    </div>

    <div id="category-modal" class="modal">
        <div class="modal-content">
            <span class="close-button" data-modal="category-modal">&times;</span>
            <h3 id="category-modal-title"><i class="fas fa-tag"></i> Nueva Categoría</h3>
            <form id="category-form">
                <input type="hidden" id="category-id" name="category_id" value="0">
                <div class="form-group">
                    <label for="cat-nombre">Nombre de la Categoría:</label>
                    <input type="text" id="cat-nombre" name="nombre" required>
                </div>
                <button type="submit" class="btn btn-success" id="save-category-btn">Guardar Categoría</button>
            </form>
        </div>
    </div>

    <div id="mods-modal" class="modal">
        <div class="modal-content">
            <span class="close-button" data-modal="mods-modal">&times;</span>
            <h3 class="modal-title"><i class="fas fa-cogs"></i> Modificaciones para <span id="mod-product-name-title" class="text-primary"></span></h3>
            
            <div id="modifications-groups-container">
                </div>
            
            <hr style="margin: 15px 0;">
            
            <h4><i class="fas fa-plus"></i> Añadir/Editar Modificación</h4>
            <form id="modification-form">
                <input type="hidden" id="mod-id" name="modification_id" value="0">
                <input type="hidden" id="mod-product-id" name="mod_producto_id">
                
                <div class="form-group">
                    <label for="mod-name">Nombre (Ej: Extra Queso, Tamaño Grande):</label>
                    <input type="text" id="mod-name" name="mod_nombre" required>
                </div>
                <div class="form-group">
                    <label for="mod-price">Precio Adicional ($):</label>
                    <input type="number" id="mod-price" name="mod_precio_adicional" step="0.01" min="0" value="0.00" required>
                </div>
                <div class="form-group">
                    <label for="mod-quantity">Cantidad / Límite:</label>
                    <input type="number" id="mod-quantity" name="mod_cantidad" value="1" min="1" required>
                    <small>Número máximo de veces que esta modificación puede ser elegida por ítem.</small>
                </div>
                <div class="form-group">
                    <label for="mod-category">Categoría de la Modificación:</label>
                    <select id="mod-category" name="mod_categoria_id" required>
                        <option value="">-- Seleccione Categoría --</option>
                        <?php foreach ($categorias_disponibles as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-success" id="save-modification-btn">Guardar Modificación</button>
                <button type="button" class="btn btn-warning" id="reset-modification-btn" style="display:none;">Limpiar Formulario</button>
            </form>
        </div>
    </div>


    <script>
    document.addEventListener('DOMContentLoaded', function() {
        
        // --- 0. UTILITIES Y SETUP (Añadir Lógica de Dark Mode) ---
        const themeKey = 'sgpp_theme_mode';
        const toggle = document.getElementById('darkModeToggle');
        const body = document.body;

        function setInitialTheme() {
            const savedTheme = localStorage.getItem(themeKey);
            if (savedTheme) {
                if (savedTheme === 'dark') {
                    body.classList.add('dark-mode');
                    if (toggle) toggle.checked = true; 
                }
            } else {
                // Si no hay tema guardado, usa el de PHP (por defecto 'light')
                const initialThemeIsDark = body.classList.contains('dark-mode'); 
                if (toggle) toggle.checked = initialThemeIsDark;
                localStorage.setItem(themeKey, initialThemeIsDark ? 'dark' : 'light');
            }
        }
        
        if (toggle) {
            toggle.addEventListener('change', function() {
                if (this.checked) {
                    body.classList.add('dark-mode');
                    localStorage.setItem(themeKey, 'dark');
                } else {
                    body.classList.remove('dark-mode');
                    localStorage.setItem(themeKey, 'light');
                }
            });
            setInitialTheme(); 
        }
        
        const productModal = document.getElementById('product-modal');
        const categoryModal = document.getElementById('category-modal');
        const modsModal = document.getElementById('mods-modal');
        
        function showStatusMessage(message, type = 'info') {
            const container = document.getElementById('status-message-container');
            const alert = document.createElement('div');
            alert.className = `alert alert-${type}`;
            alert.textContent = message;
            container.appendChild(alert);

            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s';
                setTimeout(() => alert.remove(), 500);
            }, 3000);
        }

        document.querySelectorAll('.close-button').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById(this.dataset.modal).style.display = 'none';
            });
        });
        window.addEventListener('click', function(event) {
            if (event.target == productModal) productModal.style.display = 'none';
            if (event.target == categoryModal) categoryModal.style.display = 'none';
            if (event.target == modsModal) modsModal.style.display = 'none';
        });

        function loadProducts() {
            location.reload(); 
        }

        // --- 1. PRODUCTOS (CRUD - MANTENIDO) ---
        const productForm = document.getElementById('product-form');
        const productsTableBody = document.getElementById('products-table-body');

        document.getElementById('add-product-btn').addEventListener('click', function() {
            document.getElementById('product-modal-title').textContent = 'Nuevo Producto';
            productForm.reset();
            document.getElementById('product-id').value = '0';
            productModal.style.display = 'flex';
        });

        productsTableBody.addEventListener('click', function(e) {
            const tr = e.target.closest('tr');
            if (!tr) return;

            if (e.target.closest('.edit-product-btn')) {
                document.getElementById('product-modal-title').textContent = 'Editar Producto';
                document.getElementById('product-id').value = tr.dataset.productId;
                document.getElementById('nombre').value = tr.dataset.nombre;
                document.getElementById('precio').value = parseFloat(tr.dataset.precio).toFixed(2);
                document.getElementById('categoria_id').value = tr.dataset.categoriaId;
                productModal.style.display = 'flex';
            } else if (e.target.closest('.delete-product-btn')) {
                if (confirm('¿Está seguro de eliminar este producto? También se eliminarán todas sus modificaciones.')) {
                    sendProductAction('delete_product', tr.dataset.productId);
                }
            }
        });

        productForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new URLSearchParams(new FormData(productForm));
            formData.append('action', 'save_product');
            
            fetch('productos.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showStatusMessage(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    productModal.style.display = 'none';
                    loadProducts(); 
                }
            })
            .catch(error => showStatusMessage('Error de conexión al guardar producto.', 'error'));
        });

        function sendProductAction(action, id) {
             const formData = new URLSearchParams();
             formData.append('action', action);
             formData.append('product_id', id);
             
             fetch('productos.php', {
                 method: 'POST',
                 headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                 body: formData
             })
             .then(response => response.json())
             .then(data => {
                 showStatusMessage(data.message, data.success ? 'success' : 'error');
                 if (data.success) {
                     loadProducts();
                 }
             })
             .catch(error => showStatusMessage('Error de conexión al ejecutar acción.', 'error'));
        }

        // --- 2. CATEGORÍAS (CRUD - MANTENIDO) ---
        const categoryForm = document.getElementById('category-form');
        const categoriesTableBody = document.getElementById('categories-table-body');
        
        document.getElementById('add-category-btn').addEventListener('click', function() {
            document.getElementById('category-modal-title').textContent = 'Nueva Categoría';
            categoryForm.reset();
            document.getElementById('category-id').value = '0';
            categoryModal.style.display = 'flex';
        });

        categoriesTableBody.addEventListener('click', function(e) {
            const tr = e.target.closest('tr');
            if (!tr) return;

            if (e.target.closest('.edit-category-btn')) {
                document.getElementById('category-modal-title').textContent = 'Editar Categoría';
                document.getElementById('category-id').value = tr.dataset.categoryId;
                document.getElementById('cat-nombre').value = tr.dataset.nombre;
                categoryModal.style.display = 'flex';
            } else if (e.target.closest('.delete-category-btn')) {
                if (confirm('¿Está seguro de eliminar esta categoría? Solo se eliminará si no tiene productos asociados.')) {
                    sendCategoryAction('delete_category', tr.dataset.categoryId);
                }
            }
        });

        categoryForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new URLSearchParams(new FormData(categoryForm));
            formData.append('action', 'save_category');
            
            fetch('productos.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showStatusMessage(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    categoryModal.style.display = 'none';
                    loadProducts(); 
                }
            })
            .catch(error => showStatusMessage('Error de conexión al guardar categoría.', 'error'));
        });

        function sendCategoryAction(action, id) {
             const formData = new URLSearchParams();
             formData.append('action', action);
             formData.append('category_id', id);
             
             fetch('productos.php', {
                 method: 'POST',
                 headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                 body: formData
             })
             .then(response => response.json())
             .then(data => {
                 showStatusMessage(data.message, data.success ? 'success' : 'error');
                 if (data.success) {
                     loadProducts();
                 }
             })
             .catch(error => showStatusMessage('Error de conexión al ejecutar acción.', 'error'));
        }

        // --- 3. MODIFICACIONES (CRUD con Agrupación por Categoría - ADAPTADO) ---
        const modificationForm = document.getElementById('modification-form');
        const modificationsGroupsContainer = document.getElementById('modifications-groups-container'); 
        
        let currentProductId = 0;
        
        function resetModForm() {
            document.getElementById('mod-id').value = '0';
            document.getElementById('mod-name').value = '';
            document.getElementById('mod-price').value = '0.00';
            document.getElementById('mod-quantity').value = '1'; 
            document.getElementById('mod-category').value = ''; 
            document.getElementById('save-modification-btn').textContent = 'Guardar Modificación';
            document.getElementById('reset-modification-btn').style.display = 'none';
        }
        
        // FUNCIÓN CLAVE: Carga y renderiza modificaciones agrupadas (Ahora con Cantidad)
        function loadModifications(productId, productName) {
            currentProductId = productId;
            document.getElementById('mod-product-id').value = productId;
            document.getElementById('mod-product-name-title').textContent = productName;
            
            modificationsGroupsContainer.innerHTML = '';
            resetModForm();
            
            const formData = new URLSearchParams();
            formData.append('action', 'fetch_modifications');
            formData.append('producto_id', productId);
            
            fetch('productos.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.modifications.length > 0) {
                    
                    data.modifications.forEach(group => {
                        const groupDiv = document.createElement('div');
                        groupDiv.className = 'modification-group';
                        
                        groupDiv.innerHTML = `<div class="modification-group-title">${group.name}</div>`;
                        
                        const itemsContainer = document.createElement('div');
                        
                        group.items.forEach(mod => {
                            const div = document.createElement('div');
                            div.className = 'modification-item-display';

                            div.innerHTML = `
                                <span>${mod.nombre}</span>
                                <div class="table-actions">
                                    <span class="mod-price">(+ $${parseFloat(mod.precio_adicional).toFixed(2)}) | Máx: ${mod.cantidad}x</span>
                                    <button class="btn btn-small btn-warning btn-icon edit-mod-btn" 
                                        data-mod-id="${mod.id}" 
                                        data-mod-name="${mod.nombre}" 
                                        data-mod-price="${mod.precio_adicional}"
                                        data-mod-category-id="${mod.categoria_id}"
                                        data-mod-quantity="${mod.cantidad}" 
                                        title="Editar"><i class="fas fa-edit"></i></button>
                                    <button class="btn btn-small btn-delete btn-icon delete-mod-btn" data-mod-id="${mod.id}" title="Eliminar"><i class="fas fa-trash-alt"></i></button>
                                </div>
                            `;
                            itemsContainer.appendChild(div);
                        });
                        
                        groupDiv.appendChild(itemsContainer);
                        modificationsGroupsContainer.appendChild(groupDiv);
                    });

                } else if (data.success) {
                    modificationsGroupsContainer.innerHTML = '<div class="alert alert-info">No hay modificaciones para este producto.</div>';
                } else {
                     modificationsGroupsContainer.innerHTML = `<div class="alert alert-error">${data.message}</div>`;
                }
            })
            .catch(error => showStatusMessage('Error al cargar modificaciones.', 'error'));
        }
        
        // Abrir modal de Modificaciones (Mantenido)
        productsTableBody.addEventListener('click', function(e) {
            if (e.target.closest('.manage-mods-btn')) {
                const tr = e.target.closest('tr');
                const productId = tr.dataset.productId;
                const productName = tr.dataset.nombre;
                
                loadModifications(productId, productName);
                modsModal.style.display = 'flex';
            }
        });
        
        // Guardar/Actualizar Modificación (ADAPTADO)
        modificationForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new URLSearchParams(new FormData(modificationForm));
            formData.append('action', 'save_modification');
            
            fetch('productos.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showStatusMessage(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    loadModifications(currentProductId, document.getElementById('mod-product-name-title').textContent); 
                }
            })
            .catch(error => showStatusMessage('Error al guardar modificación.', 'error'));
        });
        
        // Botón Editar y Eliminar Modificación (ADAPTADO)
        modsModal.addEventListener('click', function(e) { 
            if (e.target.closest('.edit-mod-btn')) {
                const btn = e.target.closest('.edit-mod-btn');
                document.getElementById('mod-id').value = btn.dataset.modId;
                document.getElementById('mod-name').value = btn.dataset.modName;
                document.getElementById('mod-price').value = parseFloat(btn.dataset.modPrice).toFixed(2);
                document.getElementById('mod-category').value = btn.dataset.modCategoryId; 
                document.getElementById('mod-quantity').value = btn.dataset.modQuantity; 
                document.getElementById('save-modification-btn').textContent = 'Actualizar Modificación';
                document.getElementById('reset-modification-btn').style.display = 'inline-block';
            }
            if (e.target.closest('.delete-mod-btn')) {
                 const modId = e.target.closest('.delete-mod-btn').dataset.modId;
                 if (confirm('¿Está seguro de eliminar esta modificación?')) {
                     const formData = new URLSearchParams();
                     formData.append('action', 'delete_modification');
                     formData.append('modification_id', modId);
                     
                     fetch('productos.php', {
                         method: 'POST',
                         headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                         body: formData
                     })
                     .then(response => response.json())
                     .then(data => {
                         showStatusMessage(data.message, data.success ? 'success' : 'error');
                         if (data.success) {
                             loadModifications(currentProductId, document.getElementById('mod-product-name-title').textContent);
                         }
                     })
                     .catch(error => showStatusMessage('Error al eliminar modificación.', 'error'));
                 }
            }
        });
        
        // Botón Limpiar Formulario de Modificación (Mantenido)
        document.getElementById('reset-modification-btn').addEventListener('click', resetModForm);
        
    });
    </script>
</body>
</html>