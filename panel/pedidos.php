<?php
session_start();
require_once __DIR__ . '/../config.php'; 

// Conexión a la Base de Datos
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'panel') {
    header("Location: ./../index.php");
    exit();
}

// 1. OBTENER EL MODO DE TEMA DINÁMICAMENTE
$theme_mode = 'light'; // Default
$config_content = @file_get_contents(__DIR__ . '/../admin/config_data.json'); 
if ($config_content !== false) {
    $config_data = @json_decode($config_content, true);
    if ($config_data && isset($config_data['theme_mode'])) {
        $theme_mode = $config_data['theme_mode']; 
        // Conversión: 'black' (de la DB/JSON) a 'dark' (para la clase CSS)
        // y manejo del nuevo modo 'monocromo'.
        if ($theme_mode === 'black') { 
            $theme_mode = 'dark';
        }
    }
}


// Consulta todos los pedidos
$sql = "SELECT
            p.id,
            p.fecha_pedido,
            p.total,
            p.estado,
            p.cliente_id  
        FROM pedidos p
        LEFT JOIN clientes c ON p.cliente_id = c.id
        ORDER BY p.fecha_pedido DESC";

$resultado = $conn->query($sql);

$pedidos_listos = [];
$pedidos_no_listos = []; // Para "Pendientes" y "En Preparación"

if ($resultado && $resultado->num_rows > 0) {
    $pedidos_brutos = $resultado->fetch_all(MYSQLI_ASSOC);
    $contador_general = 0; 
    
    foreach ($pedidos_brutos as $fila) {
        $contador_general++;
        $id_numerico_formato = str_pad($contador_general, 3, '0', STR_PAD_LEFT);

        $prefijo = 'D'; // Por defecto: Delivery
        if (!empty($fila['cliente_id'])) {
            $prefijo = 'L'; // Si tiene cliente_id, asumimos local
        }
        $fila['id_formateado'] = $prefijo . $id_numerico_formato;
        $fila['tipo_entrega_texto'] = ($prefijo === 'D') ? 'A Domicilio' : 'Retira en Local'; 
        
        // Separa los pedidos en Curso y Listos
        if (in_array($fila['estado'], ['listo', 'entregado', 'cancelado'])) {
            $pedidos_listos[] = $fila; 
        } else { // 'pendiente' y 'en_preparacion'
            $pedidos_no_listos[] = $fila;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Panel de Pedidos | MaxiPizza</title>
    <link rel="stylesheet" href="./style.css">
    <link rel="stylesheet" href="./../admin/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&family=Lobster&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* ------------------------------------------- */
        /* ESTILOS GLOBALES Y DE TEMA (INLINE)         */
        /* ------------------------------------------- */
        
        :root {
            --primary-color: #f75c4e; 
            --secondary-color: #6c757d; 
            --text-color: #343a40; 
            --background-color: #f8f9fa; 
            --card-background: #ffffff;
            --border-color: #e9ecef;
            --navbar-bg: #ffffff; 
            --navbar-text: #343a40;
            --navbar-hover-bg: #f8f9fa;

            /* Estados de pedido */
            --status-pendiente: #f39c12; 
            --status-en_preparacion: #3498db; 
            --status-listo: #27ae60; 
            --status-entregado: #7f8c8d; 
            --status-cancelado: #e74c3c; 
        }

        .dark-mode {
            --background-color: #1a1a1a;
            --card-background: #2a2a2a;
            --text-color: #e0e0e0;
            --border-color: #444444;
            --primary-color: #ff8a80; 
            --secondary-color: #bbbbbb;
            --navbar-bg: #212121; 
            --navbar-text: #e0e0e0;
            --navbar-hover-bg: #c0392b; 
        }

        /* INICIO MODO BLANCO Y NEGRO (MONOCROMO) */
        .monocromo-mode {
            /* Colores de alto contraste */
            --background-color: #e9e9e9; 
            --card-background: #ffffff; 
            --text-color: #1a1a1a; 
            --border-color: #cccccc; 
            --primary-color: #333333; /* Gris oscuro para énfasis */
            --secondary-color: #888888; 
            --navbar-bg: #000000; 
            --navbar-text: #ffffff;
            --navbar-hover-bg: #444444; 
            
            /* Filtro global para desaturar elementos */
            filter: grayscale(100%);
        }
        
        /* Opcional: Revertir la escala de grises en elementos clave */
        .monocromo-mode .status-color-pendiente,
        .monocromo-mode .status-color-en_preparacion,
        .monocromo-mode .status-color-listo,
        .monocromo-mode .status-color-entregado,
        .monocromo-mode .status-color-cancelado,
        .monocromo-mode img { 
            filter: grayscale(0%); 
        }
        /* FIN MODO BLANCO Y NEGRO (MONOCROMO) */

        /* Estilos base del cuerpo y columnas */
        body {
            font-family: 'Open Sans', sans-serif;
            margin: 0; 
            display: flex;
            flex-direction: column; 
            background-color: var(--background-color);
            color: var(--text-color);
            min-height: 100vh;
            transition: background-color 0.3s, color 0.3s;
        }

        h1, h2 {
            font-family: 'Lobster', cursive;
            text-align: center;
            padding-bottom: 10px;
            color: var(--primary-color);
            border-bottom: 2px solid var(--border-color);
            margin-top: 20px;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.08);
        }
        h1 { font-size: 3em; margin-bottom: 30px; }
        h2 { font-size: 2em; margin-bottom: 20px; }
        
        .main-content {
            padding: 20px;
            width: 100%;
            max-width: 1300px;
            margin: 0 auto;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 25px;
        }

        .dashboard-columns {
            display: flex;
            gap: 25px;
            width: 100%;
            flex-wrap: wrap; 
            justify-content: center; 
        }

        .columna {
            flex: 1;
            min-width: 300px; 
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-radius: 10px;
            box-sizing: border-box; 
            background-color: var(--card-background);
        }
        
        .no-pedidos {
            text-align: center;
            padding: 30px 20px;
            border: 1px dashed var(--border-color);
            border-radius: 8px;
            font-style: italic;
            margin-top: 20px;
            color: var(--text-color);
        }

        /* GRIDS DE PEDIDOS */
        .pedidos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); 
            gap: 20px;
            margin-top: 20px;
        }

        /* TARJETA DE PEDIDO (MINIMALISTA) */
        .tarjeta-pedido {
            background-color: var(--card-background);
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 15px;
            transition: transform 0.2s, box-shadow 0.2s;
            position: relative;
            border-left: 8px solid var(--primary-color); 
            display: flex; 
            flex-direction: column;
            justify-content: center; /* Centramos el ID verticalmente */
            min-height: 80px; /* Altura más compacta */
            cursor: pointer;
        }
        
        .tarjeta-pedido:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }
        
        /* Colores de estado para el borde lateral */
        .status-color-pendiente { border-left-color: var(--status-pendiente); }
        .status-color-en_preparacion { border-left-color: var(--status-en_preparacion); }
        .status-color-listo { border-left-color: var(--status-listo); }
        .status-color-entregado { border-left-color: var(--status-entregado); }
        .status-color-cancelado { border-left-color: var(--status-cancelado); }
        
        /* ESTRUCTURA DE LA TARJETA MEJORADA */
        .tarjeta-header {
            display: flex;
            justify-content: center; /* Centramos el ID */
            align-items: center;
        }

        .tarjeta-id {
            font-size: 2.2em;
            font-weight: 700;
            color: var(--primary-color);
            line-height: 1; 
        }

        /* Estilos de la barra de navegación (Ajustados para temas) */
        .navbar {
            background-color: var(--navbar-bg);
            color: var(--navbar-text);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            font-size: 1.5em;
            font-weight: 700;
        }

        .navbar-brand img {
            height: 30px;
            margin-right: 10px;
            object-fit: contain;
        }
        
        .navbar-nav {
            list-style: none;
            display: flex;
            margin: 0;
            padding: 0;
        }

        .nav-item .nav-link {
            color: var(--navbar-text);
            text-decoration: none;
            padding: 10px 15px;
            display: block;
            border-radius: 5px;
            transition: background-color 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-item .nav-link:hover {
            background-color: var(--navbar-hover-bg);
        }

        /* Responsive */
        @media (max-width: 992px) {
            .dashboard-columns { flex-direction: column; align-items: center; gap: 20px; }
            .columna { width: 95%; max-width: 600px; }
            .navbar { flex-direction: column; }
            .navbar-nav { margin-top: 10px; }
            .nav-item { margin: 5px 0; }
        }

    </style>
</head>
<body class="<?php echo htmlspecialchars($theme_mode); ?>-mode">

    
<nav class="navbar">
        <div class="navbar-brand">
            <img src="..\/img\/SGPP.png" alt="Logo SIPP">
            <img src="..\/img\/logo_1761071794.jpg" alt="Logo SIPP">  

<span>| Panel Empleado</span>
        </div>
        <ul class="navbar-nav">
            <li class="nav-item">
                <a href="pedidos.php" class="nav-link">
                    <i class="fas fa-box"></i> Ver pedidos
                </a>
            </li>
            <li class="nav-item">
                <a href="#" class="nav-link">
                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Usuario'); ?>
                </a>
            </li>
            <li class="nav-item">
                <a href="./../index.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i> Salir
                </a>
            </li>
        </ul>
    </nav>

    <div class="main-content">
        <h1>Panel General de Pedidos</h1>

        <div class="dashboard-columns">
            <div class="columna">
                <h2>Pedidos en Curso</h2>
                <?php if (count($pedidos_no_listos) > 0): ?>
                    <div class="pedidos-grid">
                        <?php foreach ($pedidos_no_listos as $p): ?>
                            <div class="tarjeta-pedido status-color-<?php echo $p['estado']; ?>">
                                <div class="tarjeta-header">
                                    <span class="tarjeta-id"><?php echo $p['id_formateado']; ?></span>
                                </div>
                                
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-pedidos"><p>No hay pedidos pendientes o en preparación. ¡A descansar! 😴</p></div>
                <?php endif; ?>
            </div>

            <div class="columna">
                <h2>Pedidos Listos</h2>
                <?php if (count($pedidos_listos) > 0): ?>
                    <div class="pedidos-grid">
                        <?php foreach ($pedidos_listos as $p): ?>
                            <div class="tarjeta-pedido status-color-<?php echo $p['estado']; ?>">
                                <div class="tarjeta-header">
                                    <span class="tarjeta-id"><?php echo $p['id_formateado']; ?></span>
                                </div>
                                
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-pedidos"><p>No hay pedidos finalizados.</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</body>
</html>
<?php 
$conn->close(); 
?>