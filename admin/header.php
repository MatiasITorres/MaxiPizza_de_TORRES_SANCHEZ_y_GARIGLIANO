<?php
// header.php - **MODIFICADO PARA SER LA BARRA LATERAL (SIDEBAR)**
// Toda la lógica PHP (sesión, conexión, CRUD) se ha consolidado en admin_dashboard.php
// Se asume que $current_section ya está disponible para resaltar el menú activo.

// Define la función para verificar si la sección está activa
$is_active = function($section) use ($current_section) {
    return ($current_section === $section) ? 'active' : '';
};
?>
<aside class="sidebar">
    <div class="sidebar-header">
       <img src="./../img/SGPP.png" alt="SGPP"> 
    </div> 
    <nav class="sidebar-nav">
        <ul>
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
            <li class="nav-item <?php echo $is_active('log_changes'); ?>">
                <a href="admin_dashboard.php?section=log_changes"><i class="fas fa-history"></i> Log de Cambios</a>
            </li>
            <li class="nav-item <?php echo $is_active('settings'); ?>">
                <a href="admin_dashboard.php?section=settings"><i class="fas fa-cog"></i> Configuración</a>
            </li>
            <li class="nav-item">
                <a href="admin_dashboard.php?logout=true"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
            </li>
        </ul>
    </nav>
    <div class="sidebar-footer">
        <p>&copy; <?php echo date('Y'); ?> SGPP</p>
    </div>
</aside>