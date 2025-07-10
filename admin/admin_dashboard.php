<?php
// admin_dashboard.php
session_start();
require_once __DIR__ . '/../includes/db_connection.php'; // Include the database connection

// 1. ADMIN SESSION VERIFICATION
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'administrador') {
    header("Location: ./../index.php");
    exit();
}

// 2. LOGOUT LOGIC
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ./../index.php");
    exit();
}

$message = ""; // Initialize message variable

// Include header
include_once __DIR__ . '/../includes/admin_header.php';

// Determine which tab to display
$tab = $_GET['tab'] ?? 'orders'; // Default tab is 'orders'

echo '<div class="grid-item">'; // Open a grid item for the content

switch ($tab) {
    case 'users':
        include_once __DIR__ . '/manage_users.php';
        break;
    case 'products':
        include_once __DIR__ . '/manage_products.php';
        break;
    case 'categories':
        include_once __DIR__ . '/manage_categories.php';
        break;
    case 'stats':
        include_once __DIR__ . '/dashboard_stats.php';
        break;
    case 'orders':
    default:
        include_once __DIR__ . '/view_orders.php';
        break;
}

echo '</div>'; // Close the grid item

// Include footer
include_once __DIR__ . '/../includes/admin_footer.php';
?>