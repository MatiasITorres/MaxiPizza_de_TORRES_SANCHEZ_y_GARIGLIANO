<?php
// generar_qr_menu.php

// =========================================================================
// Función para generar la URL de la imagen del Código QR
// Utiliza la API de Google Charts (gratuita y sencilla, no requiere librerías PHP)
// =========================================================================
function generar_qr_code_url(string $data, string $size = '300x300'): string
{
    // CHT=qr: Tipo de gráfico (QR Code)
    // CHS: Tamaño del QR (ancho x alto en píxeles)
    // CHL: Contenido del QR (la URL que se escaneará)
    $encoded_data = urlencode($data);
    return "https://chart.googleapis.com/chart?cht=qr&chs={$size}&chl={$encoded_data}";
}

// =========================================================================
// 1. Definir la URL de tu menú o kiosco
// IMPORTANTE: AJUSTA ESTA URL
// Si está en XAMPP, usa la dirección de tu red local (o localhost si lo usas en el mismo PC).
// =========================================================================
$url_del_menu = 'http://localhost/maxipizza12/cliente/cliente_dashboard.php'; 

// 2. Generar la URL de la imagen del QR (300x300 píxeles)
$qr_code_image_url = generar_qr_code_url($url_del_menu, '300x300');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Código QR de Pedido MaxiPizza</title>
    <style>
        body { font-family: sans-serif; text-align: center; padding: 20px; background-color: #f8f8f8; }
        .qr-container { 
            max-width: 400px; 
            margin: 50px auto; 
            padding: 30px; 
            background-color: #ffffff; 
            border-radius: 10px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.1); 
        }
        h2 { color: #f75c4e; margin-bottom: 25px; }
        img { border: 8px solid #f75c4e; border-radius: 5px; max-width: 100%; height: auto; display: block; margin: 0 auto 20px; }
        p { font-size: 1.1em; color: #333; }
    </style>
</head>
<body>
    <div class="qr-container">
        <h2>🍕 Escanea para Iniciar tu Pedido</h2>
        <img src="<?= htmlspecialchars($qr_code_image_url) ?>" alt="Código QR del Menú de MaxiPizza">
        <p>Apunta la cámara de tu teléfono a la imagen.</p>
        <p>URL de destino: 
            <a href="<?= htmlspecialchars($url_del_menu) ?>" target="_blank">
                <?= htmlspecialchars($url_del_menu) ?>
            </a>
        </p>
    </div>
</body>
</html>