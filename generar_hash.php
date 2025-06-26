<?php
$usuarios = [
    'admin' => password_hash('admin', PASSWORD_DEFAULT),
    'cliente' => password_hash('cliente', PASSWORD_DEFAULT),
    'cocinero' => password_hash('cocinero', PASSWORD_DEFAULT),
    'empleado' => password_hash('empleado', PASSWORD_DEFAULT),
    'panel' => password_hash('panel', PASSWORD_DEFAULT),
];

foreach ($usuarios as $usuario => $hash) {
    echo "$usuario: $hash<br>";
}
?>
