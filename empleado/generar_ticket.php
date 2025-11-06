<?php
// generar_ticket.php - Genera una vista de ticket para impresión térmica
// Debe ubicarse en la carpeta /empleado/

// ----------------------------------------------------
// 0. DEPENDENCIAS Y CONFIGURACIÓN INICIAL
// ----------------------------------------------------
session_start();
require_once './../config.php'; 

// Conexión a la Base de Datos
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Redirección si el rol no es 'empleado' o si no hay ID de pedido
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] !== 'empleado' || !isset($_GET['pedido_id'])) {
    header("Location: ./empleado_dashboard.php");
    exit();
}

$pedido_id = (int)$_GET['pedido_id'];

// LÓGICA DE CONFIGURACIÓN DE EMPRESA (JSON)
$settings = [];
$config_file = __DIR__ . './../admin/config_data.json'; 

if (file_exists($config_file)) {
    $settings = json_decode(file_get_contents($config_file), true);
}

$company_name = $settings['company_name'] ?? 'SGPP Default';
$company_address = $settings['company_address'] ?? 'Dirección no especificada';
$company_phone = $settings['company_phone'] ?? 'Teléfono no especificado';

// ----------------------------------------------------
// 1. OBTENER DETALLES DEL PEDIDO Y CLIENTE
// ----------------------------------------------------
$pedido = null;
// Se incluye p.metodo_pago en la consulta.
$sql_pedido = "SELECT p.id, p.fecha_pedido, p.total, p.estado, p.nota_pedido, p.tipo, p.ubicacion, p.metodo_pago, 
                      c.nombre as cliente_nombre, c.telefono as cliente_telefono
               FROM pedidos p
               JOIN clientes c ON p.cliente_id = c.id
               WHERE p.id = ?";
$stmt_pedido = $conn->prepare($sql_pedido);

if ($stmt_pedido) {
    $stmt_pedido->bind_param("i", $pedido_id);
    $stmt_pedido->execute();
    $result_pedido = $stmt_pedido->get_result();
    if ($result_pedido->num_rows > 0) {
        $pedido = $result_pedido->fetch_assoc();
    }
    $stmt_pedido->close();
}

if (!$pedido) {
    die("Error: Pedido no encontrado.");
}

// ----------------------------------------------------
// 2. OBTENER PRODUCTOS DEL PEDIDO
// ----------------------------------------------------
$productos_pedido = [];
$sql_productos = "SELECT pp.cantidad, pp.nombre_linea, pp.subtotal, pp.añadido 
                  FROM pedido_productos pp
                  WHERE pp.pedido_id = ?";
$stmt_productos = $conn->prepare($sql_productos);

if ($stmt_productos) {
    $stmt_productos->bind_param("i", $pedido_id);
    $stmt_productos->execute();
    $result_productos = $stmt_productos->get_result();
    while ($producto = $result_productos->fetch_assoc()) {
        $productos_pedido[] = $producto;
    }
    $stmt_productos->close();
}

$conn->close();

// ----------------------------------------------------
// 3. GENERACIÓN DEL TICKET HTML/CSS (Estilo Térmico)
// ----------------------------------------------------
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?php echo $pedido_id; ?></title>
    <style>
        /* =========================================== */
        /* === ESTILOS PARA IMPRESORA TÉRMICA REAL === */
        /* =========================================== */
        body {
            /* Fuente Monoespacio típica de tickets */
            font-family: 'Courier New', Courier, monospace; 
            font-size: 11px;
            /* Ancho estándar de ticket (80mm) */
            width: 80mm; 
            /* Márgenes nulos */
            margin: 0; 
            padding: 5mm 0;
            box-sizing: border-box;
            background: #fff;
            color: #000;
        }
        .ticket-container {
            width: 100%;
            display: block;
            text-align: center; /* Centra todo el contenido */
            padding: 0 5mm;
        }
        .header-company, .header-info, .client-info, .footer-note {
            text-align: center;
            margin-bottom: 8px;
        }
        h1, h2, h3 {
            margin: 0;
            font-size: 13px; /* Tamaño ligeramente más grande para títulos */
            line-height: 1.2;
        }
        p {
            margin: 1px 0;
        }
        /* Separador de línea de puntos o guiones */
        .separator {
            border-top: 1px dashed #000;
            margin: 5px 0;
            height: 1px;
            overflow: hidden;
        }
        /* Listado de productos */
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0;
            text-align: left;
        }
        th {
            font-weight: bold;
            padding: 4px 0;
        }
        td {
            padding: 1px 0;
            line-height: 1.3;
        }
        .desc {
             /* Ocupa el espacio restante */
            width: 70%; 
        }
        .qty {
            width: 10%;
            text-align: left;
            padding-right: 5px;
        }
        .price {
            width: 20%;
            text-align: right;
            font-weight: bold;
        }
        /* Línea de Total */
        .totals {
            text-align: right;
            font-size: 14px;
            font-weight: bold;
            margin-top: 10px;
        }
        .totals p {
            border-top: 2px solid #000; /* Borde sólido para destacar el total */
            padding-top: 5px;
            display: inline-block;
            min-width: 50%; /* Asegura que la línea de total se vea bien */
        }
        .linea-adicional {
            font-size: 0.85em; 
            text-align: left;
            padding-left: 15px; /* Sangría para el detalle */
            display: block;
            margin: 0;
        }
        
        /* Ocultar botones y elementos innecesarios en la impresión */
        @media print {
            body {
                width: 80mm;
                /* Eliminar cualquier sombra o fondo que pueda quedar */
                box-shadow: none; 
                -webkit-print-color-adjust: exact; /* Fuerza colores y fondos */
            }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="ticket-container">

        <div class="header-company">
            <h1><?php echo htmlspecialchars($company_name ?? 'SGPP'); ?></h1>
            <p><?php echo htmlspecialchars($company_address ?? 'Dirección No. 123'); ?></p>
            <p>Tel: <?php echo htmlspecialchars($company_phone ?? '555-1234'); ?></p>
        </div>

        <div class="separator"></div>

        <div class="header-info">
            <h2>--- ORDEN DE PEDIDO ---</h2>
            <p><strong>PEDIDO N° <?php echo $pedido['id'] ?? 'N/A'; ?></strong></p>
            <p>FECHA: <?php echo date('d/m/Y H:i:s', strtotime($pedido['fecha_pedido'] ?? 'now')); ?></p>
            <p>ESTADO: **<?php echo htmlspecialchars(strtoupper($pedido['estado'] ?? '')); ?>**</p>
            <p>TIPO: **<?php echo htmlspecialchars(strtoupper($pedido['tipo'] ?? '')); ?>**</p>
            <p>PAGO: **<?php echo htmlspecialchars(strtoupper($pedido['metodo_pago'] ?? 'NO ESPECIFICADO')); ?>**</p> 
        </div>

        <div class="separator"></div>

        <div class="client-info">
            <h3>DATOS DE CLIENTE</h3>
            <p>Cliente: <?php echo htmlspecialchars($pedido['cliente_nombre'] ?? ''); ?></p>
            <p>Teléfono: <?php echo htmlspecialchars($pedido['cliente_telefono'] ?? ''); ?></p>
            <p>Ubicación: <?php echo htmlspecialchars($pedido['ubicacion'] ?? ''); ?></p>
            <?php if (!empty($pedido['nota_pedido'])): ?>
                <p>Nota: <?php echo htmlspecialchars($pedido['nota_pedido'] ?? ''); ?></p>
            <?php endif; ?>
        </div>

        <div class="separator"></div>

        <table>
            <thead>
                <tr>
                    <th class="qty">CANT</th>
                    <th class="desc">DESCRIPCION</th>
                    <th class="price">SUBTOTAL</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($productos_pedido as $item): ?>
                    <tr>
                        <td class="qty"><?php echo $item['cantidad'] ?? 0; ?></td>
                        <td class="desc">
                            <?php 
                                echo htmlspecialchars(strtoupper($item['nombre_linea'] ?? '')); 
                            ?>
                        </td>
                        <td class="price">$<?php echo number_format($item['subtotal'] ?? 0, 2); ?></td>
                    </tr>
                    <?php if (!empty($item['añadido'])): ?>
                        <tr>
                            <td></td>
                            <td colspan="2" class="linea-adicional">
                                * DETALLE: <?php echo htmlspecialchars($item['añadido'] ?? ''); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="separator"></div>

        <div class="totals total-line">
            <p>TOTAL: $<?php echo number_format($pedido['total'] ?? 0, 2); ?></p>
        </div>

        <div class="separator"></div>
        
        <div class="footer-note">
            <p>-----------------------------------</p>
            <p>¡GRACIAS POR SU COMPRA!</p>
            <p>PEDIDO PREPARADO POR: <?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Empleado'); ?></p>
            <p>-----------------------------------</p>
        </div>

    </div>
</body>
</html>