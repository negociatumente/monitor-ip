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
//Manejar la insercion de un servicio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_service'])) {
    $new_service_name = htmlspecialchars($_POST['new_service_name'], ENT_QUOTES, 'UTF-8');
    $new_service_color = htmlspecialchars($_POST['new_service_color'], ENT_QUOTES, 'UTF-8');

    // Cargar la configuración actual
    $config = parse_ini_file('config.ini', true);

    // Añadir el nuevo servicio
    $config['services'][$new_service_name] = $new_service_color;

    // Guardar los cambios en config.ini
    $new_content = '';
    foreach ($config as $section => $values) {
        $new_content .= "[$section]\n";
        foreach ($values as $key => $value) {
            $new_content .= "$key = \"$value\"\n";
        }
    }
    file_put_contents('config.ini', $new_content);

    // Redirigir para evitar reenvío del formulario
    header("Location: " . $_SERVER['PHP_SELF'] . "?action=service_added");
    exit;
}

// Manejar el cambio del temporizador
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_timer'])) {
    $new_timer_value = intval($_POST['new_timer_value']);

    if ($new_timer_value > 0) {
        // Cargar la configuración actual
        $config = parse_ini_file('config.ini', true);

        // Actualizar el valor del temporizador
        $config['settings']['ping_interval'] = $new_timer_value;

        // Guardar los cambios en config.ini
        $new_content = '';
        foreach ($config as $section => $values) {
            $new_content .= "[$section]\n";
            foreach ($values as $key => $value) {
                $new_content .= "$key = \"$value\"\n";
            }
        }
        file_put_contents('config.ini', $new_content);

        // Redirigir para evitar reenvío del formulario
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=timer_updated");
        exit;
    } else {
        echo "<script>alert('Please enter a valid number greater than 0.');</script>";
    }
}

// Manejar el cambio de intentos de ping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_ping_attempts'])) {
    $new_ping_attempts = intval($_POST['new_ping_attempts']);

    if ($new_ping_attempts > 0) {
        // Cargar la configuración actual
        $config = parse_ini_file('config.ini', true);

        // Actualizar el valor de ping_attempts
        $config['settings']['ping_attempts'] = $new_ping_attempts;

        // Guardar los cambios en config.ini
        $new_content = '';
        foreach ($config as $section => $values) {
            $new_content .= "[$section]\n";
            foreach ($values as $key => $value) {
                $new_content .= "$key = \"$value\"\n";
            }
        }
        file_put_contents('config.ini', $new_content);

        // Redirigir para evitar reenvío del formulario
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=ping_attempts_updated");
        exit;
    } else {
        echo "<script>alert('Please enter a valid number greater than 0.');</script>";
    }
}
//Manejar la eliminación de datos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_data'])) {
    // Limpiar los datos de ping
    if (file_exists($ping_file)) {
        file_put_contents($ping_file, json_encode([])); // Vaciar el archivo
    }

    // Redirigir para evitar reenvío del formulario
    header("Location: " . $_SERVER['PHP_SELF'] . "?action=data_cleared");
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