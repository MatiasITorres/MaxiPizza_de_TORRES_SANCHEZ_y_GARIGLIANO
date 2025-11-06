<?php
// dashboard_content.php
// Este archivo contiene el contenido dinámico del panel de administración

// Asegurarse de que $conn esté disponible
if (!isset($conn)) {
    die("Error: La conexión a la base de datos no está disponible.");
}

// ----------------- LÓGICA DE VISTAS (PÁGINAS) -----------------

// Vista de Pedidos Detallados (Ya existía)
$order_details = null;
if ($current_section === 'orders' && isset($_GET['view_order_id'])) {
    $view_order_id = intval($_GET['view_order_id']);

    // Query para obtener detalles del pedido y del cliente
    $sql_details = "SELECT p.id AS id_pedido, p.fecha, p.total, p.estado, p.nota_pedido, p.ubicacion, p.tipo,
                           c.nombre AS nombre_cliente, c.email AS email_cliente, c.telefono AS telefono_cliente, c.ubicacion AS ubicacion_cliente
                    FROM pedidos p
                    LEFT JOIN clientes c ON p.cliente_id = c.id
                    WHERE p.id = ?";
    
    $stmt_details = $conn->prepare($sql_details);
    if ($stmt_details) {
        $stmt_details->bind_param("i", $view_order_id);
        $stmt_details->execute();
        $result_details = $stmt_details->get_result();
        $order_details = $result_details->fetch_assoc();
        $stmt_details->close();
    }
}


// Función helper para obtener todos los productos/usuarios/etc.
function fetch_all_data($conn, $table, $order_by = 'id DESC') {
    $data = [];
    $query = "SELECT * FROM {$table} ORDER BY {$order_by}";
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    return $data;
}


// ----------------- CONTENIDO DINÁMICO -----------------

// Mostrar el mensaje si existe
if (!empty($message)) {
    $class = (strpos($message, 'Error') !== false) ? 'error-message' : 'success-message';
    echo "<div class='message-box {$class}'>{$message}</div>";
}

echo "<header class='content-header'>";
echo "<h2>" . ucfirst(str_replace('_', ' ', $current_section)) . "</h2>";
echo "</header>";

// El $action se usa en productos, usuarios y categorías para mostrar el formulario de edición/añadir
$action = $_GET['action'] ?? '';


switch ($current_section) {

    case 'overview':
        // Contenido del Dashboard (Estadísticas rápidas)
        // ... (Contenido existente, no modificado)
        ?>
        <div class='dashboard-stats'>
            <div class='stat-card'><i class='fas fa-box'></i> <h3>Pedidos Totales</h3> <p><?php echo $dashboard_stats['total_pedidos']; ?></p></div>
            <div class='stat-card'><i class='fas fa-clock'></i> <h3>Pendientes</h3> <p><?php echo $dashboard_stats['pedidos_pendientes']; ?></p></div>
            <div class='stat-card'><i class='fas fa-users'></i> <h3>Usuarios</h3> <p><?php echo $dashboard_stats['total_usuarios']; ?></p></div>
            <div class='stat-card'><i class='fas fa-dollar-sign'></i> <h3>Ganancias (Entregados)</h3> <p>$<?php echo number_format($dashboard_stats['total_ganancias'], 2); ?></p></div>
        </div>
        <div class='card mt-20'><p>Detalle de Vista Rápida del Dashboard (Overview) iría aquí.</p></div>
        <?php
        break;
        
    case 'orders':
        
        if ($order_details) {
            // VISTA DE DETALLE DEL PEDIDO (Ya existía)
            // ... (HTML de Detalle de Pedido)
            ?>
            <div class='order-detail-view'>
                <a href='admin_dashboard.php?section=orders' class='btn btn-secondary'><i class='fas fa-arrow-left'></i> Volver a Pedidos</a>

                <div class='card order-info-card'>
                    <h3>Pedido #<?php echo $order_details['id_pedido']; ?></h3>
                    <p><strong>Fecha:</strong> <?php echo date('d/m/Y H:i:s', strtotime($order_details['fecha'])); ?></p>
                    <p><strong>Total:</strong> $<?php echo number_format($order_details['total'], 2); ?></p>
                    <p><strong>Estado Actual:</strong> <span class='status-badge status-<?php echo $order_details['estado']; ?>'><?php echo ucfirst($order_details['estado']); ?></span></p>
                    <p><strong>Tipo:</strong> <?php echo htmlspecialchars($order_details['tipo']); ?></p>
                    
                    <?php if ($order_details['ubicacion']): ?>
                        <p><strong>Ubicación:</strong> <?php echo htmlspecialchars($order_details['ubicacion']); ?></p>
                    <?php endif; ?>

                    <?php if ($order_details['nota_pedido']): ?>
                        <p><strong>Nota:</strong> <?php echo nl2br(htmlspecialchars($order_details['nota_pedido'])); ?></p>
                    <?php endif; ?>
                </div>
                
                <div class='card customer-info-card'>
                    <h3>Información del Cliente</h3>
                    <?php if ($order_details['nombre_cliente']): ?>
                        <p><strong>Nombre:</strong> <?php echo htmlspecialchars($order_details['nombre_cliente']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($order_details['email_cliente'] ?? 'N/A'); ?></p>
                        <p><strong>Teléfono:</strong> <?php echo htmlspecialchars($order_details['telefono_cliente'] ?? 'N/A'); ?></p>
                    <?php else: ?>
                        <p>Cliente no registrado o eliminado.</p>
                    <?php endif; ?>
                </div>
                
                <div class='card status-update-card'>
                    <h3>Actualizar Estado</h3>
                    <form method='POST' action='admin_dashboard.php?section=orders&view_order_id=<?php echo $order_details['id_pedido']; ?>'>
                        <input type='hidden' name='action' value='update_order_status'>
                        <input type='hidden' name='order_id' value='<?php echo $order_details['id_pedido']; ?>'>
                        
                        <label for='new_status'>Nuevo Estado:</label>
                        <select id='new_status' name='new_status' required>
                            <?php $statuses = ['pendiente', 'en_preparacion', 'listo', 'entregado', 'cancelado']; ?>
                            <?php foreach ($statuses as $status): ?>
                                <?php $selected = ($order_details['estado'] === $status) ? 'selected' : ''; ?>
                                <option value='<?php echo $status; ?>' <?php echo $selected; ?>><?php echo ucfirst(str_replace('_', ' ', $status)); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type='submit' class='btn btn-primary mt-10'><i class='fas fa-sync'></i> Actualizar Estado</button>
                    </form>
                </div>
                
                <div class='card products-detail-card'>
                    <h3>Productos del Pedido</h3>
                    
                    <?php 
                    $sql_items = "SELECT pp.nombre_linea, pp.cantidad, pp.precio_unitario, pp.subtotal 
                                  FROM pedido_productos pp WHERE pp.pedido_id = ?";
                    $stmt_items = $conn->prepare($sql_items);
                    $order_items = [];
                    if ($stmt_items) {
                        $stmt_items->bind_param("i", $view_order_id);
                        $stmt_items->execute();
                        $result_items = $stmt_items->get_result();
                        while($row = $result_items->fetch_assoc()) {
                            $order_items[] = $row;
                        }
                        $stmt_items->close();
                    }
                    ?>

                    <?php if (!empty($order_items)): ?>
                        <table class='data-table'>
                        <thead><tr><th>Producto</th><th>Cantidad</th><th>Precio Unitario</th><th>Subtotal</th></tr></thead>
                        <tbody>
                        <?php foreach ($order_items as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['nombre_linea'] ?? 'Producto Desconocido'); ?></td>
                                <td><?php echo $item['cantidad']; ?></td>
                                <td class='text-right'>$<?php echo number_format($item['precio_unitario'], 2); ?></td>
                                <td class='text-right'>$<?php echo number_format($item['subtotal'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        </table>
                    <?php else: ?>
                        <p>No se encontraron productos para este pedido.</p>
                    <?php endif; ?>
                    
                </div>
            </div>
            
            <?php
        } else {
            // VISTA DE LISTADO DE PEDIDOS 
            $pedidos = fetch_all_data($conn, 'pedidos', 'fecha DESC');
            ?>
            <div class='card'>
                <div class="table-actions">
                    <h3>Listado de Pedidos</h3>
                </div>
                
                <?php if (!empty($pedidos)): ?>
                <table class='data-table'>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Fecha</th>
                            <th>Total</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pedidos as $pedido): ?>
                        <tr>
                            <td>#<?php echo $pedido['id']; ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($pedido['fecha'])); ?></td>
                            <td>$<?php echo number_format($pedido['total'], 2); ?></td>
                            <td><span class='status-badge status-<?php echo $pedido['estado']; ?>'><?php echo ucfirst($pedido['estado']); ?></span></td>
                            <td>
                                <a href="admin_dashboard.php?section=orders&view_order_id=<?php echo $pedido['id']; ?>" class='btn btn-small btn-info'><i class='fas fa-eye'></i> Ver Detalle</a>
                                <a href="ver_pedido.php?id=<?php echo $pedido['id']; ?>" target="_blank" class='btn btn-small btn-secondary'><i class='fas fa-file-invoice'></i> Factura</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p>No se encontraron pedidos.</p>
                <?php endif; ?>
            </div>
            <?php
        }
        break;
        
    case 'users':
        
        if ($action === 'add' || $action === 'edit') {
            // VISTA DE AÑADIR/EDITAR USUARIO
            $is_edit = $action === 'edit' && $user_to_edit;
            $user_id = $is_edit ? $user_to_edit['id'] : 0;
            $nombre = $is_edit ? htmlspecialchars($user_to_edit['nombre']) : '';
            $email = $is_edit ? htmlspecialchars($user_to_edit['email']) : '';
            $rol = $is_edit ? htmlspecialchars($user_to_edit['rol']) : '';
            $roles_options = ['administrador', 'empleado'];
            
            ?>
            <a href='admin_dashboard.php?section=users' class='btn btn-secondary'><i class='fas fa-arrow-left'></i> Volver al Listado</a>
            <form method="POST" action="admin_dashboard.php?section=users" class="card form-crud mt-20">
                <h3><?php echo $is_edit ? 'Editar Usuario #' . $user_id : 'Añadir Nuevo Usuario'; ?></h3>
                <input type="hidden" name="save_user" value="1">
                <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">

                <div class="form-group">
                    <label for="nombre">Nombre Completo</label>
                    <input type="text" id="nombre" name="nombre" value="<?php echo $nombre; ?>" required>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo $email; ?>" required>
                </div>

                <div class="form-group">
                    <label for="rol">Rol</label>
                    <select id="rol" name="rol" required>
                        <?php foreach ($roles_options as $role): ?>
                            <option value="<?php echo $role; ?>" <?php echo $rol === $role ? 'selected' : ''; ?>>
                                <?php echo ucfirst($role); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="password">Contraseña (<?php echo $is_edit ? 'Dejar vacío para no cambiar' : 'Requerida'; ?>)</label>
                    <input type="password" id="password" name="password" <?php echo $is_edit ? '' : 'required'; ?>>
                </div>

                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Usuario</button>
            </form>
            <?php
        } else {
            // VISTA DE LISTADO DE USUARIOS
            $usuarios = fetch_all_data($conn, 'usuarios', 'id ASC');
            ?>
            <div class='card'>
                <div class="table-actions">
                    <h3>Listado de Usuarios</h3>
                    <a href="admin_dashboard.php?section=users&action=add" class="btn btn-primary"><i class="fas fa-plus"></i> Añadir Usuario</a>
                </div>
                
                <?php if (!empty($usuarios)): ?>
                <table class='data-table'>
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
                        <?php foreach ($usuarios as $usuario): ?>
                        <tr>
                            <td><?php echo $usuario['id']; ?></td>
                            <td><?php echo htmlspecialchars($usuario['nombre']); ?></td>
                            <td><?php echo htmlspecialchars($usuario['email']); ?></td>
                            <td><span class='status-badge status-<?php echo $usuario['rol'] === 'administrador' ? 'listo' : 'en_preparacion'; ?>'><?php echo ucfirst($usuario['rol']); ?></span></td>
                            <td>
                                <a href="admin_dashboard.php?section=users&action=edit&id=<?php echo $usuario['id']; ?>" class='btn btn-small btn-warning'><i class='fas fa-edit'></i> Editar</a>
                                <?php if ($usuario['id'] != $current_user_id): // No permitir que el usuario actual se elimine ?>
                                    <a href="admin_dashboard.php?section=users&delete_user_id=<?php echo $usuario['id']; ?>" class='btn btn-small btn-danger' onclick="return confirm('¿Está seguro de que desea eliminar este usuario?');"><i class='fas fa-trash'></i> Eliminar</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p>No se encontraron usuarios.</p>
                <?php endif; ?>
            </div>
            <?php
        }
        break;

    case 'products':
        
        if ($action === 'add' || $action === 'edit') {
            // VISTA DE AÑADIR/EDITAR PRODUCTO
            $is_edit = $action === 'edit' && $product_to_edit;
            $product_id = $is_edit ? $product_to_edit['id'] : 0;
            $nombre = $is_edit ? htmlspecialchars($product_to_edit['nombre']) : '';
            $descripcion = $is_edit ? htmlspecialchars($product_to_edit['descripcion']) : '';
            $precio = $is_edit ? $product_to_edit['precio'] : '';
            $stock = $is_edit ? $product_to_edit['stock'] : '';
            $categoria_id = $is_edit ? $product_to_edit['categoria_id'] : '';
            $imagen_url = $is_edit ? htmlspecialchars($product_to_edit['imagen_url']) : '';
            
            // Obtener todas las categorías para el select
            $categorias = fetch_all_data($conn, 'categorias_productos', 'nombre ASC');

            ?>
            <a href='admin_dashboard.php?section=products' class='btn btn-secondary'><i class='fas fa-arrow-left'></i> Volver al Listado</a>
            <form method="POST" action="admin_dashboard.php?section=products" class="card form-crud mt-20">
                <h3><?php echo $is_edit ? 'Editar Producto #' . $product_id : 'Añadir Nuevo Producto'; ?></h3>
                <input type="hidden" name="save_product" value="1">
                <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">

                <div class="form-group">
                    <label for="nombre">Nombre</label>
                    <input type="text" id="nombre" name="nombre" value="<?php echo $nombre; ?>" required>
                </div>

                <div class="form-group">
                    <label for="descripcion">Descripción</label>
                    <textarea id="descripcion" name="descripcion" required><?php echo $descripcion; ?></textarea>
                </div>

                <div class="form-group half-width">
                    <label for="precio">Precio ($)</label>
                    <input type="number" step="0.01" id="precio" name="precio" value="<?php echo $precio; ?>" required>
                </div>

                <div class="form-group half-width">
                    <label for="stock">Stock</label>
                    <input type="number" id="stock" name="stock" value="<?php echo $stock; ?>" required>
                </div>

                <div class="form-group">
                    <label for="categoria_id">Categoría</label>
                    <select id="categoria_id" name="categoria_id" required>
                        <option value="">-- Seleccione Categoría --</option>
                        <?php foreach ($categorias as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo $categoria_id == $cat['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="imagen_url">URL de Imagen</label>
                    <input type="text" id="imagen_url" name="imagen_url" value="<?php echo $imagen_url; ?>" placeholder="ej: ../img/pizza_peperoni.png">
                </div>

                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Producto</button>
            </form>
            <?php
        } else {
            // VISTA DE LISTADO DE PRODUCTOS
            // Query con JOIN para mostrar el nombre de la categoría
            $sql_productos = "SELECT p.id, p.nombre, p.precio, p.stock, p.imagen_url, c.nombre AS categoria_nombre 
                              FROM productos p 
                              LEFT JOIN categorias_productos c ON p.categoria_id = c.id 
                              ORDER BY p.id DESC";
            $result_productos = $conn->query($sql_productos);
            $productos = $result_productos ? $result_productos->fetch_all(MYSQLI_ASSOC) : [];
            ?>
            <div class='card'>
                <div class="table-actions">
                    <h3>Listado de Productos</h3>
                    <a href="admin_dashboard.php?section=products&action=add" class="btn btn-primary"><i class="fas fa-plus"></i> Añadir Producto</a>
                </div>
                
                <?php if (!empty($productos)): ?>
                <table class='data-table'>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Imagen</th>
                            <th>Nombre</th>
                            <th>Categoría</th>
                            <th>Precio</th>
                            <th>Stock</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($productos as $producto): ?>
                        <tr>
                            <td><?php echo $producto['id']; ?></td>
                            <td><img src="<?php echo htmlspecialchars($producto['imagen_url']); ?>" alt="<?php echo htmlspecialchars($producto['nombre']); ?>" style="width: 50px; height: 50px; object-fit: cover;"></td>
                            <td><?php echo htmlspecialchars($producto['nombre']); ?></td>
                            <td><?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'Sin Categoría'); ?></td>
                            <td>$<?php echo number_format($producto['precio'], 2); ?></td>
                            <td><?php echo $producto['stock']; ?></td>
                            <td>
                                <a href="admin_dashboard.php?section=products&action=edit&id=<?php echo $producto['id']; ?>" class='btn btn-small btn-warning'><i class='fas fa-edit'></i> Editar</a>
                                <a href="admin_dashboard.php?section=products&delete_product_id=<?php echo $producto['id']; ?>" class='btn btn-small btn-danger' onclick="return confirm('¿Está seguro de que desea eliminar este producto?');"><i class='fas fa-trash'></i> Eliminar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p>No se encontraron productos.</p>
                <?php endif; ?>
            </div>
            <?php
        }
        break;

    case 'categories':
        
        if ($action === 'add' || $action === 'edit') {
            // VISTA DE AÑADIR/EDITAR CATEGORÍA
            $is_edit = $action === 'edit' && $category_to_edit;
            $category_id = $is_edit ? $category_to_edit['id'] : 0;
            $nombre = $is_edit ? htmlspecialchars($category_to_edit['nombre']) : '';
            $descripcion = $is_edit ? htmlspecialchars($category_to_edit['descripcion']) : '';
            $imagen_url = $is_edit ? htmlspecialchars($category_to_edit['imagen_url']) : '';
            
            ?>
            <a href='admin_dashboard.php?section=categories' class='btn btn-secondary'><i class='fas fa-arrow-left'></i> Volver al Listado</a>
            <form method="POST" action="admin_dashboard.php?section=categories" class="card form-crud mt-20">
                <h3><?php echo $is_edit ? 'Editar Categoría #' . $category_id : 'Añadir Nueva Categoría'; ?></h3>
                <input type="hidden" name="save_category" value="1">
                <input type="hidden" name="category_id" value="<?php echo $category_id; ?>">

                <div class="form-group">
                    <label for="nombre">Nombre</label>
                    <input type="text" id="nombre" name="nombre" value="<?php echo $nombre; ?>" required>
                </div>

                <div class="form-group">
                    <label for="descripcion">Descripción</label>
                    <textarea id="descripcion" name="descripcion"><?php echo $descripcion; ?></textarea>
                </div>
                
                <div class="form-group">
                    <label for="imagen_url">URL de Imagen</label>
                    <input type="text" id="imagen_url" name="imagen_url" value="<?php echo $imagen_url; ?>" placeholder="ej: ../img/icon_pizzas.png">
                </div>

                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Categoría</button>
            </form>
            <?php
        } else {
            // VISTA DE LISTADO DE CATEGORÍAS
            $categories = fetch_all_data($conn, 'categorias_productos', 'id ASC');
            ?>
            <div class='card'>
                <div class="table-actions">
                    <h3>Listado de Categorías de Productos</h3>
                    <a href="admin_dashboard.php?section=categories&action=add" class="btn btn-primary"><i class="fas fa-plus"></i> Añadir Categoría</a>
                </div>
                
                <?php if (!empty($categories)): ?>
                <table class='data-table'>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Descripción</th>
                            <th>Imagen URL</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td><?php echo $cat['id']; ?></td>
                            <td><?php echo htmlspecialchars($cat['nombre']); ?></td>
                            <td><?php echo htmlspecialchars(substr($cat['descripcion'], 0, 50)); ?>...</td>
                            <td><?php echo htmlspecialchars(substr($cat['imagen_url'], 0, 50)); ?>...</td>
                            <td>
                                <a href="admin_dashboard.php?section=categories&action=edit&id=<?php echo $cat['id']; ?>" class='btn btn-small btn-warning'><i class='fas fa-edit'></i> Editar</a>
                                <a href="admin_dashboard.php?section=categories&delete_category_id=<?php echo $cat['id']; ?>" class='btn btn-small btn-danger' onclick="return confirm('¿Está seguro de que desea eliminar esta categoría? Esto podría afectar a los productos asociados.');"><i class='fas fa-trash'></i> Eliminar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p>No se encontraron categorías.</p>
                <?php endif; ?>
            </div>
            <?php
        }
        break;
        
    case 'settings':
        // Contenido de la sección de Configuración (Ya existía)
        // ... (Se mantiene el formulario de configuración)
        $company_name = htmlspecialchars($settings['company_name'] ?? '');
        $contact_email = htmlspecialchars($settings['contact_email'] ?? '');
        $theme_mode_dark_checked = ($settings['theme_mode'] ?? 'light') === 'dark' ? 'checked' : '';
        $mercadopago_active_checked = ($settings['mercadopago_active'] ?? '0') === '1' ? 'checked' : '';
        $other_payment_options = htmlspecialchars($settings['other_payment_options'] ?? '');
        $cbu_cvu_value = htmlspecialchars($settings['cbu_cvu'] ?? ''); 
        $alias_value = htmlspecialchars($settings['alias'] ?? ''); 
        ?>
        <div class="settings-section">
            <form method="POST" action="admin_dashboard.php?section=settings" class="card form-settings">
                <h3>Configuración General</h3>
                <input type="hidden" name="save_settings" value="1">

                <div class="form-group">
                    <label for="company_name">Nombre de la Empresa</label>
                    <input type="text" id="company_name" name="company_name" value="<?php echo $company_name; ?>" required>
                </div>

                <div class="form-group">
                    <label for="contact_email">Email de Contacto</label>
                    <input type="email" id="contact_email" name="contact_email" value="<?php echo $contact_email; ?>" required>
                </div>
                
                <hr>

                <h3>Apariencia</h3>
                <div class="form-group">
                    <label for="theme_mode_dark">Modo Oscuro</label>
                    <label class="switch">
                        <input type="checkbox" id="theme_mode_dark" name="theme_mode" value="dark" <?php echo $theme_mode_dark_checked; ?>>
                        <span class="slider round"></span>
                    </label>
                </div>
                
                <hr>

                <h3>Configuración de Pagos</h3>
                
                <div class="form-group">
                    <label for="alias">Alias de Transferencia</label>
                    <input type="text" id="alias" name="alias" value="<?php echo $alias_value; ?>" placeholder="ej: PIZZA.MAXI.APP">
                </div>

                <div class="form-group">
                    <label for="cbu_cvu">CBU / CVU para Transferencias</label>
                    <input type="text" id="cbu_cvu" name="cbu_cvu" value="<?php echo $cbu_cvu_value; ?>" placeholder="22 dígitos CBU o CVU">
                </div>

                <div class="form-group">
                    <label>Mercado Pago Activo:</label>
                    <label class="switch switch-mp">
                        <input type="checkbox" id="mercadopago_active_switch" name="mercadopago_active" value="1" <?php echo $mercadopago_active_checked; ?>>
                        <span class="slider round"></span>
                    </label>
                    <span id="mercadopago_active_text" class="status-badge status-<?php echo ($mercadopago_active_checked ? 'listo' : 'cancelado'); ?>">
                        <?php echo ($mercadopago_active_checked ? 'Activado' : 'Inactivo'); ?>
                    </span>
                </div>
                
                <div class="form-group">
                    <label for="other_payment_options">Otras Opciones de Pago (separadas por coma)</label>
                    <input type="text" id="other_payment_options" name="other_payment_options" value="<?php echo $other_payment_options; ?>">
                </div>

                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Configuración</button>
            </form>
        </div>
        
        <?php
        break;


    case 'reports':
        // Contenido de la sección de Reportes (Se mantiene)
        ?>
        <div class="reports-section">
            <h2>Generación de Reportes</h2>
            
            <div class='card'>
                <h3>Exportar Pedidos a CSV</h3>
                <p>Genera un archivo CSV con el detalle de todos los pedidos registrados en el sistema, ideal para análisis contables o de ventas.</p>
                <a href='admin_dashboard.php?section=reports&action=export_orders' class='btn btn-success'><i class='fas fa-download'></i> Exportar Pedidos</a>
            </div>
            
            <div class='card'>
                <h3>Log de Cambios</h3>
                <p>Ver el registro de todas las modificaciones realizadas por los administradores.</p>
                <p>Aquí se mostraría el listado de la tabla <code>registro_cambios</code>.</p>
            </div>
        </div>
        <?php
        break;


    default:
        // Contenido por defecto
        echo "<div class='card'>";
        echo "<p>Contenido no disponible para la sección '<strong>" . htmlspecialchars($current_section ?? '') . "</strong>'.</p>"; 
        echo "<p>Selecciona una opción del menú lateral.</p>";
        echo "</div>";
        break;
}
?>