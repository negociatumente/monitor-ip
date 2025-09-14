<?php
// Cargar configuración desde config.ini
$config_path = __DIR__ . '/conf/config.ini';
$config = parse_ini_file($config_path, true);
$ips_to_monitor = $config['ips'];
$services = $config['services'];
$ping_attempts = $config['settings']['ping_attempts'];
$ping_interval = $config['settings']['ping_interval'];

$ping_file = __DIR__ . '/conf/ping_results.json';

// Cargar resultados previos si existen
if (file_exists($ping_file)) {
    $ping_data = json_decode(file_get_contents($ping_file), true);
} else {
    $ping_data = [];
}

// Cargar funciones
require_once __DIR__ . '/lib/functions.php';

// Manejar la adición de IP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_ip'])) {
    $new_ip = trim($_POST['new_ip']);
    $new_service = trim($_POST['new_service']);

    // Validar IP
    $validated_ip = filter_var($new_ip, FILTER_VALIDATE_IP);
    if (!$validated_ip) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=invalid_ip");
        exit;
    }

    // Verificar si se está creando un nuevo servicio
    if ($new_service === 'create_new') {
        $new_service_name = trim($_POST['new_service_name_inline'] ?? '');
        $new_service_color = trim($_POST['new_service_color_inline'] ?? '');

        if (empty($new_service_name)) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=empty_service_name");
            exit;
        }

        if (empty($new_service_color)) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=empty_service_color");
            exit;
        }

    // Cargar la configuración actual
    $config = parse_ini_file($config_path, true);

        // Verificar si el servicio ya existe
    if (isset($config['services'][$new_service_name])) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=service_exists");
            exit;
        }

        // Añadir el nuevo servicio
        $new_service_name = htmlspecialchars($new_service_name, ENT_QUOTES, 'UTF-8');
        $new_service_color = htmlspecialchars($new_service_color, ENT_QUOTES, 'UTF-8');
        $config['services'][$new_service_name] = $new_service_color;

        // Guardar los cambios en config.ini
        $new_content = '';
        foreach ($config as $section => $values) {
            $new_content .= "[$section]\n";
            foreach ($values as $key => $value) {
                $new_content .= "$key = \"$value\"\n";
            }
        }

    if (!file_put_contents($config_path, $new_content)) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=config_write_error");
            exit;
        }

        // Usar el nuevo servicio para la IP
        $new_service = $new_service_name;
    }

    // Validar que tenemos un servicio válido
    if (empty($new_service) || $new_service === 'create_new') {
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=invalid_service");
        exit;
    }

    // Verificar si la IP ya existe
    $config = parse_ini_file($config_path, true);
    if (isset($config['ips'][$validated_ip])) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=ip_exists");
        exit;
    }

    // Añadir la IP
    if (add_ip_to_config($validated_ip, $new_service)) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=added");
        exit;
    } else {
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=add_ip_failed");
        exit;
    }
}

// Manejar la eliminación de IP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ip'])) {
    $ip_to_delete = filter_var($_POST['delete_ip'], FILTER_VALIDATE_IP);
    delete_ip_from_config($ip_to_delete);
    header("Location: " . $_SERVER['PHP_SELF'] . "?action=deleted");
    exit;
}

// Manejar el cambio del temporizador
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_timer'])) {
    $new_timer_value = intval($_POST['new_timer_value']);

    if ($new_timer_value > 0) {
        // Cargar la configuración actual
    $config = parse_ini_file($config_path, true);

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
    file_put_contents($config_path, $new_content);

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
    $config = parse_ini_file($config_path, true);

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
    file_put_contents($config_path, $new_content);

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

// Manejar la eliminación de servicio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_service'])) {
    $service_to_delete = trim($_POST['service_name']);

    if (!empty($service_to_delete)) {
        if (delete_service_from_config($service_to_delete)) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?action=service_cleared");
            exit;
        } else {
            header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=service_clear_failed");
            exit;
        }
    } else {
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=invalid_service_name");
        exit;
    }
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
require_once __DIR__ . '/views.php';

// Manejar notificaciones
$notifications = [
    'added' => ['type' => 'success', 'icon' => 'fas fa-check-circle', 'message' => 'IP añadida exitosamente al monitoreo.'],
    'deleted' => ['type' => 'success', 'icon' => 'fas fa-trash', 'message' => 'IP eliminada exitosamente del monitoreo.'],
    'service_added' => ['type' => 'success', 'icon' => 'fas fa-plus-circle', 'message' => 'Servicio creado exitosamente.'],
    'service_cleared' => ['type' => 'success', 'icon' => 'fas fa-server', 'message' => 'Servicio eliminado exitosamente.'],
    'timer_updated' => ['type' => 'success', 'icon' => 'fas fa-clock', 'message' => 'Intervalo de ping actualizado exitosamente.'],
    'ping_attempts_updated' => ['type' => 'success', 'icon' => 'fas fa-network-wired', 'message' => 'Número de intentos de ping actualizado exitosamente.'],
    'data_cleared' => ['type' => 'success', 'icon' => 'fas fa-broom', 'message' => 'Datos de ping eliminados exitosamente.'],
    'error' => ['type' => 'error', 'icon' => 'fas fa-exclamation-circle', 'message' => 'Error: Por favor, verifica los datos ingresados.']
];

// Handle specific error messages
if ($_GET['action'] === 'error' && isset($_GET['msg'])) {
    $error_messages = [
        'invalid_ip' => 'Error: La dirección IP ingresada no es válida.',
        'empty_service_name' => 'Error: El nombre del servicio no puede estar vacío.',
        'empty_service_color' => 'Error: Debe seleccionar un color para el servicio.',
        'service_exists' => 'Error: Ya existe un servicio con ese nombre.',
        'config_write_error' => 'Error: No se pudo guardar la configuración.',
        'invalid_service' => 'Error: Debe seleccionar un servicio válido.',
        'ip_exists' => 'Error: Esta IP ya está siendo monitoreada.',
        'add_ip_failed' => 'Error: No se pudo agregar la IP al sistema.',
        'service_clear_failed' => 'Error: No se pudo eliminar el servicio.',
        'invalid_service_name' => 'Error: Nombre de servicio inválido.'
    ];

    $error_msg = $_GET['msg'];
    if (array_key_exists($error_msg, $error_messages)) {
        $notifications['error']['message'] = $error_messages[$error_msg];
    }
}
?>