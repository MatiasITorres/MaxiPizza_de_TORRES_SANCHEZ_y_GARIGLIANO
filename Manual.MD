# 🍕 Software de Gestión de Pedidos para Pizzería (SGPP)

## 📜 Descripción General del Proyecto

Este proyecto es un **Sistema de Gestión de Pedidos para Pizzería (SGPP)** completo, desarrollado en PHP y MySQL. Está diseñado para modernizar y digitalizar el flujo de trabajo completo de una pizzería, desde la toma de pedidos hasta la visualización en cocina y la gestión administrativa.

El sistema es una aplicación multi-panel con diferentes vistas y roles de usuario para cubrir todas las necesidades operativas del negocio.

## ✨ Características Principales

Este proyecto va más allá de un simple CRUD e implementa varias lógicas de negocio complejas:

*   **Panel de Empleado (POS):** Interfaz principal para la toma de pedidos (telefónicos o en mostrador), con búsqueda y creación de clientes al vuelo.
*   **Panel de Cocina (KDS):** Un *Kitchen Display System* en tiempo real que recibe los pedidos y permite a los cocineros gestionar el estado de *cada producto individualmente* (`pendiente`, `en_preparacion`, `listo`).
*   **Panel de Cliente (Kiosco):** Interfaz para que los clientes realicen sus propios pedidos en el local.
*   **Panel de Visualización:** Pantalla pública que muestra el estado de los pedidos en columnas ("En Curso" y "Listos") con un ID formateado (ej. `L001`).
*   **Panel de Administración:** Centro de control para la gestión de:
    *   **Productos (CRUD):** Con lógica de stock avanzada (soporta stock numérico o infinito `∞`).
    *   **Categorías (CRUD):** Con carga de imágenes.
    *   **Usuarios (CRUD):** Con roles (`admin`, `empleado`, `cocinero`, `panel`).
    *   **Reportes:** Exportación de pedidos a `.csv`.
    *   **Auditoría:** Log de cambios de todas las acciones de los empleados/admin.
    *   **Configuración:** Gestión de ajustes del sistema (logo, tema, métodos de pago) vía un archivo `config_data.json`.
*   **Lógica de Pedidos Avanzada:**
    *   **Modificaciones Agrupadas:** Permite crear grupos de modificaciones (ej. "Empanadas") con límites de cantidad por grupo (ej. "Máx. 12 unidades totales").
    *   **Transaccional:** Los pedidos se guardan usando transacciones SQL para garantizar la integridad de los datos (Cliente, Pedido, Productos del Pedido).

## 🛠️ Lenguajes y Tecnologías Utilizadas

| Categoría | Tecnología/Lenguaje | Uso Principal |
| :--- | :--- | :--- |
| **Backend** | `PHP 8.x` | Lógica de negocio, gestión de sesiones y API interna (AJAX). |
| **Base de Datos** | `MySQL` / `MariaDB` | Almacenamiento de pedidos, productos, clientes, usuarios, etc. |
| **Frontend** | `JavaScript (ES6+)` | Manejo de estado del carrito, lógica de modificaciones, peticiones AJAX (`fetch`). |
| **Estilos** | `HTML5` / `CSS3` | Estructura y diseño de todos los paneles y dashboards. |
| **Configuración** | `JSON` | Almacenamiento dinámico de la configuración del sistema (`config_data.json`). |
| **Servidor Local** | `XAMPP` / `WAMP` | Entorno de desarrollo Apache + MySQL. |

## 📦 Guía de Instalación y Despliegue

Sigue estos pasos para ejecutar el proyecto en un entorno local (ej. XAMPP).

### 1. Prerrequisitos
*   Tener un entorno de servidor local instalado (se recomienda **XAMPP**).
*   Acceso a un gestor de base de datos (como **phpMyAdmin**).

### 2. Descargar y Mover Archivos
1.  Descarga o clona este repositorio.
2.  Copia **todo el contenido** de la carpeta del proyecto dentro de tu carpeta de servidor web.
    *   *Ejemplo en XAMPP (Windows):* `C:\xampp\htdocs\pizzeria-sgpp`

### 3. Configuración de la Base de Datos
1.  Inicia Apache y MySQL desde el panel de control de XAMPP.
2.  Abre `phpMyAdmin` (usualmente `http://localhost/phpmyadmin`).
3.  Crea una nueva base de datos. El nombre recomendado es `maxipizza`.
4.  Selecciona la base de datos `maxipizza` y ve a la pestaña **Importar**.
5.  Importa el archivo `carta.sql` (incluido en este repositorio) para crear todas las tablas y datos iniciales.

### 4. Configurar la Conexión (PHP)
1.  Localiza el archivo `config.php` en la raíz del proyecto.
2.  Abre el archivo y **revisa los parámetros de conexión** para que coincidan con tu configuración de MySQL.
    ```php
    <?php
    // config.php
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');      // Usuario por defecto de XAMPP
    define('DB_PASS', '');          // Contraseña por defecto de XAMPP
    define('DB_NAME', 'maxipizza'); // El nombre que usaste en el paso 3.
    ?>
    ```

### 5. Configurar Permisos (¡Importante!)
Para que el Panel de Administración pueda guardar la configuración (logo, nombre de la empresa, tema), la carpeta `/admin` necesita permisos de escritura.

*   Busca el archivo `/admin/config_data.json`.
*   Asegúrate de que el servidor (Apache) tenga permisos para **escribir** en este archivo. En Windows, esto generalmente funciona por defecto. En Linux/macOS, podrías necesitar `chmod 775` para la carpeta `admin` o `chmod 664` para el archivo.

### 6. Ejecutar el Proyecto
1.  Abre tu navegador web.
2.  Ve a la dirección donde copiaste los archivos:
    *   `http://localhost/pizzeria-sgpp/` (o el nombre de la carpeta que hayas usado).

---

## 🔐 Demo / Acceso de Usuarios

Puedes usar las siguientes credenciales por defecto (creadas por `carta.sql`) para probar los diferentes roles:

| Rol | Email (Usuario) | Contraseña | Panel |
| :--- | :--- | :--- | :--- |
| **Administrador** | `admin@sgpp.com` | `Admin123!` | `/admin/admin_dashboard.php` |
| **Empleado (POS)** | `empleado@sgpp.com` | `Empleado123!` | `/empleado/empleado_dashboard.php` |
| **Cocinero (KDS)** | `cocina@sgpp.com` | `Cocina123!` | `/cocinero/cocinero_dashboard.php` |
| **Panel (Display)** | `panel@sgpp.com` | `Panel123!` | `/panel/panel_pedidos.php` |
| **Cliente (Kiosco)**| `cliente@sgpp.com`| `Cliente123!` | `/cliente/cliente_dashboard.php` |

## 👥 Integrantes del Equipo

*   **Ian Garigliano**
*   **Matías Torres**
*   **Tomás Sánchez**

*(Instituto Leonardo Murialdo - 7mo Informática A y B)*

## 🔗 Enlaces Importantes

| Recurso | Enlace |
| :--- | :--- |
| **Web del Proyecto** | [LINK AL WEBSITE DEL PROYECTO (8.5)] |
| **Manual de Uso** | [LINK AL MANUAL DE USO (8.3)] |
