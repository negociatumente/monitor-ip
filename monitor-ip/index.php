<?php
// Cargar configuración desde config.ini
$config = parse_ini_file('config.ini', true);
$ips_to_monitor = $config['ips'];
$services = $config['services'];
$ping_attempts = $config['settings']['ping_attempts'];
$ping_interval = $config['settings']['ping_interval'];

$ping_file = 'ping_results.json';

// Cargar resultados previos si existen
if (file_exists($ping_file)) {
    $ping_data = json_decode(file_get_contents($ping_file), true);
} else {
    $ping_data = [];
}

// Cargar funciones
require_once 'functions.php';

// Manejar la adición de IP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_ip'])) {
    $new_ip = filter_var($_POST['new_ip'], FILTER_VALIDATE_IP);
    $new_service = htmlspecialchars($_POST['new_service'], ENT_QUOTES, 'UTF-8');
    add_ip_to_config($new_ip, $new_service);
    header("Location: " . $_SERVER['PHP_SELF'] . "?action=added");
    exit;
}

// Manejar la eliminación de IP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ip'])) {
    $ip_to_delete = filter_var($_POST['delete_ip'], FILTER_VALIDATE_IP);
    delete_ip_from_config($ip_to_delete);
    header("Location: " . $_SERVER['PHP_SELF'] . "?action=deleted");
    exit;
}

// Ejecutar un solo ping por ciclo solo si no se está eliminando una IP o añadiendo una nueva
if (!isset($_GET['action'])) {
    foreach ($ips_to_monitor as $ip => $service) {
        update_ping_results($ip);
    }

    // Guardar resultados actualizados en JSON
    file_put_contents($ping_file, json_encode($ping_data));
}

// Incluir la vista
require_once 'views.php';
?>