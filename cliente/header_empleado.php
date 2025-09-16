<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Usuario | MaxiPizza</title>
    <link rel="stylesheet" href="./../css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&family=Lobster&display=swap" rel="stylesheet">
    <style>
        /*
        ========================================
        Variables y Estilos Generales
        ========================================
        */
        :root {
            --primary-color: #c0392b;
            --secondary-color: #e67e22;
            --tertiary-color: #f39c12;
            --dark-text: #333;
            --light-bg: #f8f8f8;
            --white-bg: #ffffff;
            --light-border: #ddd;
            --success-color: #27ae60;
            --error-color: #e74c3c;
            --info-color: #3498db;
            --grey-button: #7f8c8d;
            --status-pendiente: #f39c12;
            --status-en_preparacion: #3498db;
            --status-listo: #27ae60;
            --status-entregado: #7f8c8d;
            --status-cancelado: #e74c3c;
            --shadow-light: 0 2px 5px rgba(0, 0, 0, 0.05);
            --shadow-medium: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        body {
            font-family: 'Open Sans', sans-serif;
            margin: 0;
            padding: 20px;
            background-color: var(--light-bg);
            color: var(--dark-text);
            line-height: 1.6;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
        }

        /*
        ========================================
        Tipografía y Encabezados
        ========================================
        */
        h1 {
            font-family: 'Lobster', cursive;
            text-align: center;
            color: var(--primary-color);
            margin-bottom: 25px;
            font-size: 2.8em;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1);
            padding-bottom: 10px;
            border-bottom: 2px solid var(--secondary-color);
            line-height: 1.2;
            width: 95%;
            max-width: 1300px;
        }

        h2, h3 {
            font-family: 'Lobster', cursive;
            color: var(--secondary-color);
            margin-top: 0;
            margin-bottom: 20px;
            font-size: 2em;
            text-align: center;
            border-bottom: 2px solid var(--light-border);
            padding-bottom: 10px;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.08);
        }

        /*
        ========================================
        Estructura de la Página
        ========================================
        */
        .main-content-wrapper {
            display: flex;
            gap: 30px;
            margin-top: 20px;
            flex-wrap: wrap;
            width: 95%;
            max-width: 1300px;
        }

        .products-section, .order-summary-section, .manage-orders-section {
            background-color: var(--white-bg);
            border: 1px solid var(--light-border);
            border-radius: 10px;
            padding: 30px;
            box-shadow: var(--shadow-medium);
            margin-bottom: 20px;
            box-sizing: border-box;
        }
        .products-section { flex: 2; min-width: 350px; }
        .order-summary-section { flex: 1; min-width: 350px; }
        .manage-orders-section { flex-basis: 100%; margin-top: 30px; }

        /*
        ========================================
        Sección de Productos
        ========================================
        */
        .category-details {
            margin-bottom: 20px;
            border: 1px solid var(--light-border);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: var(--shadow-light);
        }

        .category-summary {
            background-color: var(--secondary-color);
            color: white;
            padding: 15px;
            cursor: pointer;
            font-weight: 700;
            font-size: 1.2em;
            display: block;
            position: relative;
            user-select: none;
            transition: background-color 0.2s ease;
        }
        .category-summary:hover {
            background-color: #d35400;
        }

        .category-summary::marker,
        .category-summary::-webkit-details-marker {
            display: none;
            content: "";
        }

        .category-details[open] > .category-summary::before {
            content: '▼';
            transform: rotate(0deg);
        }

        .category-content {
            padding: 15px;
            background-color: #fefefe;
        }

        .product-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid var(--light-border);
        }
        .product-item:last-child { border-bottom: none; }

        .product-info { flex-grow: 1; margin-right: 15px; }
        .product-name {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 1.1em;
        }
        .product-price-value {
            font-weight: bold;
            color: var(--secondary-color);
        }

        .product-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .product-actions input[type="number"] {
            width: 60px;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 5px;
            text-align: center;
            font-size: 1em;
        }
        .product-actions button {
            background-color: var(--success-color);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.2s ease, transform 0.1s ease, box-shadow 0.2s ease;
            box-shadow: var(--shadow-light);
        }
        .product-actions button:hover {
            background-color: #218838;
            transform: translateY(-1px);
        }
        
        /*
        ========================================
        Sección de Pedidos
        ========================================
        */
        .customer-info-fields {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--light-border);
        }
        .customer-info-fields label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark-text);
            font-size: 1.05em;
        }
        .customer-info-fields input[type="text"],
        #existing_cliente_id {
            width: calc(100% - 24px);
            padding: 12px;
            margin-bottom: 18px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 1em;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        .customer-info-fields input[type="text"]:focus,
        #existing_cliente_id:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 8px rgba(230, 126, 34, 0.2);
            outline: none;
        }

        #current-order-list {
            list-style: none;
            padding: 0;
            margin-top: 15px;
        }
        #current-order-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px dotted #eee;
        }
        #current-order-list li:last-child { border-bottom: none; }

        #current-order-total {
            font-weight: bold;
            text-align: right;
            margin-top: 15px;
            padding-top: 10px;
            border-top: 2px solid var(--secondary-color);
            font-size: 1.3em;
            color: var(--primary-color);
        }

        .order-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        .order-buttons button {
            background-color: var(--info-color);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.2s ease, transform 0.1s ease, box-shadow 0.2s ease;
            box-shadow: 2px 2px 5px rgba(0, 0, 0, 0.1);
        }
        .order-buttons button:hover {
            background-color: #2874a7;
            transform: translateY(-1px);
        }
        .order-buttons button.clear { background-color: var(--grey-button); }
        .order-buttons button.clear:hover { background-color: #6c7a89; }
        .order-buttons button:disabled {
            background-color: #ccc;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        .status-message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 600;
            display: none;
            border: 1px solid transparent;
            box-shadow: var(--shadow-light);
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        .status-message.success { background-color: #e6ffe6; color: var(--success-color); border-color: #d0f0d0; }
        .status-message.error { background-color: #ffe6e6; color: var(--error-color); border-color: #f0d0d0; }

        /*
        ========================================
        Tabla de Gestión de Pedidos
        ========================================
        */
        .order-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 20px;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            background-color: var(--white-bg);
            border: 1px solid var(--light-border);
        }
        .order-table th, .order-table td {
            border-bottom: 1px solid var(--light-border);
            padding: 12px 15px;
            text-align: left;
            vertical-align: middle;
            font-size: 1em;
        }
        .order-table th {
            background-color: var(--secondary-color);
            color: white;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .order-table tr:nth-child(even) { background-color: #f9f9f9; }
        .order-table tr:hover { background-color: #f5f5f5; }

        .order-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-start;
        }
        .order-actions button, .order-actions select {
            padding: 8px 12px;
            border-radius: 5px;
            border: 1px solid #ccc;
            cursor: pointer;
            margin-right: 0;
            font-weight: 600;
            transition: background-color 0.2s ease, transform 0.1s ease;
        }
        .order-actions select {
            background-color: #fefefe;
            border-color: var(--light-border);
            color: var(--dark-text);
        }
        .order-actions button {
            background-color: var(--tertiary-color);
            color: var(--dark-text);
            border: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .order-actions button:hover {
            background-color: #e67e22;
            color: white;
            transform: translateY(-1px);
        }

        /*
        ========================================
        Insignias de Estado
        ========================================
        */
        .status-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 5px;
            font-size: 0.9em;
            font-weight: bold;
            color: white;
            text-transform: capitalize;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .status-pendiente { background-color: var(--status-pendiente); color: var(--dark-text); }
        .status-en_preparacion { background-color: var(--status-en_preparacion); }
        .status-listo { background-color: var(--status-listo); }
        .status-entregado { background-color: var(--status-entregado); }
        .status-cancelado { background-color: var(--status-cancelado); }

        /*
        ========================================
        Botón de Cerrar Sesión
        ========================================
        */
        .logout-button-container {
            width: 100%;
            max-width: 1300px;
            text-align: center;
            margin-top: 40px;
        }
        .logout-button {
            background-color: var(--grey-button);
            color: white;
            padding: 12px 25px;
            border-radius: 5px;
            font-size: 1em;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            transition: background-color 0.2s ease, transform 0.1s ease, box-shadow 0.2s ease;
        }
        .logout-button:hover {
            background-color: #6c7a89;
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
        }

        /*
        ========================================
        Media Queries (Responsive)
        ========================================
        */
        @media (max-width: 992px) {
            .main-content-wrapper { gap: 25px; }
            h1 { font-size: 2.5em; }
            h2, h3 { font-size: 1.8em; }
            th, td { padding: 12px 15px; }
        }

        @media (max-width: 768px) {
            body { padding: 15px; }
            h1 { font-size: 2em; margin-bottom: 20px; }
            .main-content-wrapper { flex-direction: column; gap: 20px; }
            .products-section, .order-summary-section, .manage-orders-section { padding: 25px; }
            h2, h3 { font-size: 1.5em; margin-bottom: 15px; }
            .customer-info-fields input[type="text"],
            #existing_cliente_id { width: 100%; padding: 10px; margin-bottom: 15px; }
            .order-buttons { flex-direction: column; gap: 10px; }
            .order-buttons button { width: 100%; }
            .product-actions { flex-direction: column; gap: 10px; align-items: flex-end;}
            .product-actions input[type="number"] { width: 100px; }
            .order-actions { flex-direction: column; gap: 5px; }
            .order-actions button, .order-actions select { width: 100%; }
        }
        @media (max-width: 480px) {
            h1 { font-size: 1.8em; }
            h2, h3 { font-size: 1.3em; }
            th, td { padding: 10px; font-size: 0.9em; }
            .status-badge { padding: 5px 8px; font-size: 0.8em; }
            .logout-button-container { margin-top: 30px; }
            .logout-button { width: 100%; }
        }
    </style>
</head>
<body>