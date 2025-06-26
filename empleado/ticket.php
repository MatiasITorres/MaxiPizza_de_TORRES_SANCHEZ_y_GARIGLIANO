<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL); // Muestra todos los errores para facilitar la depuración

require_once __DIR__ . '/../config.php'; // Asegúrate de que esta ruta sea correcta

// --- INICIO: SECCIÓN DE VALIDACIÓN Y DEPURACIÓN ---

// Depuración inicial de la sesión (descomentar solo para depurar)
/*
echo "<pre>Contenido de \$_SESSION al inicio: ";
print_r($_SESSION);
echo "</pre>";
// die("DEBUG: Sesión inicial."); // Descomentar para ver la sesión y detener la ejecución
*/

// Validación de sesión y roles (versión más robusta)
if (
    !isset($_SESSION['usuario_id']) || // Si el ID de usuario no está seteado
    !isset($_SESSION['usuario_rol']) || // O si el rol de usuario no está seteado
    ($_SESSION['usuario_rol'] !== 'empleado' && $_SESSION['usuario_rol'] !== 'administrador') // O si el rol no es ni empleado NI administrador
) {
    die("Acceso denegado. No tienes permiso para ver esta página. Por favor, inicia sesión con una cuenta autorizada.");
}

// Depuración del parámetro pedido_id (descomentar solo para depurar)
/*
echo "<pre>Contenido de \$_GET: ";
print_r($_GET);
echo "</pre>";
// die("DEBUG: Parámetros GET."); // Descomentar para ver GET y detener la ejecución
*/

$pedido_id = isset($_GET['pedido_id']) ? intval($_GET['pedido_id']) : 0;

// Depuración del valor final de $pedido_id (descomentar solo para depurar)
/*
echo "<p>Valor de \$pedido_id: " . $pedido_id . "</p>";
// die("DEBUG: Valor final de pedido_id."); // Descomentar para ver el ID y detener la ejecución
*/

if ($pedido_id === 0) {
    die("Error: ID de pedido no especificado o inválido. Asegúrate de pasar un ID de pedido válido en la URL (ej. ?pedido_id=123).");
}

// --- FIN: SECCIÓN DE VALIDACIÓN Y DEPURACIÓN ---

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("Error de conexión a la base de datos: " . $conn->connect_error);
}

$pedido_info = null;
$pedido_items = [];

try {
    // Obtener información principal del pedido
    $sql_pedido = "SELECT
                        p.id, p.fecha, p.total, p.estado, p.nota_pedido,
                        COALESCE(u.nombre, p.nombre_cliente_calle) AS cliente_nombre,
                        COALESCE(u.ubicacion, p.ubicacion_cliente_calle) AS cliente_ubicacion,
                        COALESCE(u.telefono, p.telefono_cliente_calle) AS cliente_telefono
                    FROM pedidos p
                    LEFT JOIN usuarios u ON p.cliente_id = u.id
                    WHERE p.id = ?";

    $stmt_pedido = $conn->prepare($sql_pedido);
    if (!$stmt_pedido) {
        throw new Exception("Error al preparar la consulta de pedido: " . $conn->error);
    }
    $stmt_pedido->bind_param("i", $pedido_id);
    $stmt_pedido->execute();
    $result_pedido = $stmt_pedido->get_result();

    if ($result_pedido->num_rows > 0) {
        $pedido_info = $result_pedido->fetch_assoc();
    }
    $stmt_pedido->close();

    if (!$pedido_info) {
        die("Pedido no encontrado. El ID de pedido proporcionado no corresponde a un pedido existente.");
    }

    // Obtener los productos del pedido
    $sql_items = "SELECT
                      pp.cantidad,
                      prod.nombre AS producto_nombre,
                      pp.precio_unitario,
                      pp.subtotal
                  FROM pedido_productos pp
                  JOIN productos prod ON pp.producto_id = prod.id
                  WHERE pp.pedido_id = ?";

    $stmt_items = $conn->prepare($sql_items);
    if (!$stmt_items) {
        throw new Exception("Error al preparar la consulta de productos del pedido: " . $conn->error);
    }
    $stmt_items->bind_param("i", $pedido_id);
    $stmt_items->execute();
    $result_items = $stmt_items->get_result();

    while ($row_item = $result_items->fetch_assoc()) {
        $pedido_items[] = $row_item;
    }
    $stmt_items->close();

} catch (Exception $e) {
    error_log("Error al generar ticket: " . $e->getMessage());
    die("Error al cargar los detalles del pedido para impresión: " . $e->getMessage());
} finally {
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket de Pedido #<?= htmlspecialchars($pedido_id) ?></title>
    <style>
        body {
            font-family: 'Courier New', Courier, monospace; /* Típicamente para tickets */
            margin: 0;
            padding: 10mm; /* Pequeño margen para impresión */
            background-color: #fff;
            color: #000;
            font-size: 11pt; /* Tamaño de fuente por defecto */
        }
        .ticket-container {
            width: 80mm; /* Ancho típico de un ticket térmico de 80mm */
            max-width: 80mm;
            margin: 0 auto;
            border: 1px solid #eee; /* Solo para visualización en pantalla */
            padding: 10px;
            box-sizing: border-box;
        }
        h1, h2, h3, p {
            margin: 0;
            padding: 0;
            text-align: center;
        }
        h1 {
            font-size: 1.8em;
            margin-bottom: 10px;
        }
        h2 {
            font-size: 1.4em;
            margin-bottom: 5px;
        }
        .header-info, .customer-info, .order-details, .footer-info {
            margin-bottom: 15px;
            border-top: 1px dashed #000;
            padding-top: 10px;
        }
        .header-info p, .customer-info p, .footer-info p {
            text-align: left;
            margin-bottom: 3px;
        }
        .order-details table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .order-details th, .order-details td {
            text-align: left;
            padding: 3px 0;
            border-bottom: 1px dotted #ccc;
        }
        .order-details th {
            font-weight: bold;
        }
        .order-details td:nth-child(2) { text-align: center; } /* Cantidad */
        .order-details td:nth-child(3), .order-details td:nth-child(4) { text-align: right; } /* Precio unitario y Subtotal */

        .total {
            text-align: right;
            font-weight: bold;
            font-size: 1.3em;
            border-top: 1px dashed #000;
            padding-top: 10px;
            margin-top: 15px;
        }
        .note {
            text-align: left;
            font-style: italic;
            font-size: 0.9em;
            margin-top: 10px;
        }

        .print-button-container {
            text-align: center;
            margin-top: 20px;
            /* Ocultar el botón al imprimir */
            display: none;
        }

        @media screen {
            /* Mostrar el botón solo en pantalla */
            .print-button-container {
                display: block;
            }
        }

        /* Estilos específicos para la impresión */
        @media print {
            body {
                margin: 0;
                padding: 0;
                font-size: 10pt; /* Ajustar el tamaño de la fuente para impresoras térmicas */
                -webkit-print-color-adjust: exact; /* Para Chrome/Safari */
                print-color-adjust: exact; /* Estándar */
            }
            .ticket-container {
                width: auto; /* Dejar que la impresora maneje el ancho */
                max-width: none;
                border: none;
                box-shadow: none;
                padding: 0;
            }
            /* Asegúrate de que los márgenes de la impresora estén configurados a cero */
            @page {
                margin: 0;
            }
        }
    </style>
</head>
<body>
    <div class="ticket-container">
        <h1>MAXIPIZZA</h1>
        <p>¡Gracias por tu compra!</p>
        <div class="header-info">
            <p><strong>Pedido #<?= htmlspecialchars($pedido_info['id']) ?></strong></p>
            <p>Fecha: <?= htmlspecialchars((new DateTime($pedido_info['fecha']))->format('d/m/Y H:i')) ?></p>
            <p>Estado: <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $pedido_info['estado']))) ?></p>
        </div>

        <div class="customer-info">
            <p><strong>Cliente:</strong> <?= htmlspecialchars($pedido_info['cliente_nombre']) ?></p>
            <p><strong>Teléfono:</strong> <?= htmlspecialchars($pedido_info['cliente_telefono']) ?></p>
            <p><strong>Dirección:</strong> <?= htmlspecialchars($pedido_info['cliente_ubicacion']) ?></p>
        </div>

        <div class="order-details">
            <table>
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>Cant.</th>
                        <th>P. Unit.</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pedido_items as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['producto_nombre']) ?></td>
                            <td><?= htmlspecialchars($item['cantidad']) ?></td>
                            <td>$<?= htmlspecialchars(number_format($item['precio_unitario'], 2, ',', '.')) ?></td>
                            <td>$<?= htmlspecialchars(number_format($item['subtotal'], 2, ',', '.')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="total">
                Total del Pedido: $<?= htmlspecialchars(number_format($pedido_info['total'], 2, ',', '.')) ?>
            </div>
        </div>

        <?php if (!empty($pedido_info['nota_pedido'])): ?>
            <div class="note">
                <p><strong>Nota del Pedido:</strong> <?= htmlspecialchars($pedido_info['nota_pedido']) ?></p>
            </div>
        <?php endif; ?>

        <div class="footer-info">
            <p>¡Vuelve pronto!</p>
            <p>maxipizza.com</p>
        </div>

        <div class="print-button-container">
            <button onclick="window.print()">Imprimir Ticket</button>
        </div>
    </div>

    <script>
        // Si prefieres que se imprima automáticamente al cargar la página, descomenta la siguiente sección.
        // Si solo quieres imprimir con el botón, mantén esta sección comentada.
        /*
        window.onload = function() {
            window.print();
            // Cierra la ventana después de imprimir o cancelar (opcional, puede no funcionar en todos los navegadores)
            window.onafterprint = function() {
                window.close();
            };
        };
        */
    </script>
</body>
</html>