<?php
// Determine which network type to load
$network_type = isset($_GET['network']) ? $_GET['network'] : 'external';
$is_local_network = ($network_type === 'local');
$network_param = isset($_GET['network']) ? '&network=' . urlencode($_GET['network']) : '';

// Load configuration from appropriate config file
$config_path = __DIR__ . '/conf/' . ($is_local_network ? 'config_local.ini' : 'config.ini');

// Create config_local.ini if it doesn't exist
if ($is_local_network && !file_exists($config_path)) {
    $default_local_config = "[settings]\nversion = \"0.7.0\"\nping_attempts = \"5\"\nping_interval = \"300\"\n[services-colors]\nDEFAULT = \"#6B7280\"\n\"Local Network\" = \"#10B981\"\n[services-methods]\nDEFAULT = \"icmp\"\n\"Local Network\" = \"icmp\"\n[ips-services]\n";
    file_put_contents($config_path, $default_local_config);
}

$config = parse_ini_file($config_path, true);
$ips_to_monitor = $config['ips-services'] ?? [];
$services = $config['services-colors'];
$services_methods = $config['services-methods'] ?? [];
$ping_attempts = $config['settings']['ping_attempts'];
$ping_interval = $config['settings']['ping_interval'];

// Cargar archivos
$ping_file = __DIR__ . '/conf/' . ($is_local_network ? 'ping_results_local.json' : 'ping_results.json');
require_once __DIR__ . '/lib/functions.php';

// Cargar resultados previos si existen
if (file_exists($ping_file)) {
    $ping_data = json_decode(file_get_contents($ping_file), true);
} else {
    $ping_data = [];
}


// Handle AJAX requests for network scanning
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'scan_network' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');

        try {
            $devices = scan_local_network();
            echo json_encode([
                'success' => true,
                'devices' => $devices,
                'count' => count($devices)
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }

    if ($_GET['action'] === 'save_scanned_devices' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');

        try {
            $json = file_get_contents('php://input');
            $data = json_decode($json, true);

            if (!isset($data['devices']) || !is_array($data['devices'])) {
                throw new Exception('Invalid devices data');
            }

            $result = save_local_network_scan($data['devices']);

            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Devices saved successfully' : 'Failed to save devices'
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }

    // Speed Test Endpoints
    if ($_GET['action'] === 'speed_test_ping' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');

        try {
            $latency = test_network_latency();
            echo json_encode([
                'success' => true,
                'latency' => $latency
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }

    if ($_GET['action'] === 'speed_test_download' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');

        try {
            $speed = test_download_speed();
            echo json_encode([
                'success' => true,
                'speed' => $speed
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }

    if ($_GET['action'] === 'speed_test_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');

        try {
            $speed = test_upload_speed();
            echo json_encode([
                'success' => true,
                'speed' => $speed
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }
}


// Manejar la adición de IP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_ip'])) {
    $new_ip = trim($_POST['new_ip']);
    $new_service = trim($_POST['new_service']);
    $new_method = trim($_POST['new_method'] ?? 'icmp'); // Default to ICMP

    // Validar IP o Dominio
    if (!isValidHost($new_ip)) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=invalid_ip" . $network_param);
        exit;
    }
    $validated_ip = $new_ip;

    // Verificar si se está creando un nuevo servicio
    if ($new_service === 'create_new') {
        $new_service_name = trim($_POST['new_service_name_inline'] ?? '');
        $new_service_color = trim($_POST['new_service_color_inline'] ?? '');

        if (empty($new_service_name)) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=empty_service_name" . $network_param);
            exit;
        }

        if (empty($new_service_color)) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=empty_service_color" . $network_param);
            exit;
        }

        // Cargar la configuración actual
        $config = parse_ini_file($config_path, true);

        // Verificar si el servicio ya existe
        if (isset($config['services-colors'][$new_service_name])) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=service_exists" . $network_param);
            exit;
        }

        // Añadir el nuevo servicio
        $new_service_name = htmlspecialchars($new_service_name, ENT_QUOTES, 'UTF-8');
        $new_service_color = htmlspecialchars($new_service_color, ENT_QUOTES, 'UTF-8');
        $config['services-colors'][$new_service_name] = $new_service_color;

        // Guardar los cambios en config.ini
        $new_content = '';
        foreach ($config as $section => $values) {
            $new_content .= "[$section]\n";
            foreach ($values as $key => $value) {
                $new_content .= "$key = \"$value\"\n";
            }
        }

        if (!file_put_contents($config_path, $new_content)) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=config_write_error" . $network_param);
            exit;
        }

        // Usar el nuevo servicio para la IP
        $new_service = $new_service_name;
    }

    // Validar que tenemos un servicio válido
    if (empty($new_service) || $new_service === 'create_new') {
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=invalid_service" . $network_param);
        exit;
    }

    // Verificar si la IP ya existe
    $config = parse_ini_file($config_path, true);
    if (isset($config['ips-services'][$validated_ip])) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=ip_exists" . $network_param);
        exit;
    }

    // Añadir la IP
    if (add_ip_to_config($validated_ip, $new_service, $new_method)) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=added" . $network_param);
        exit;
    } else {
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=add_ip_failed" . $network_param);
        exit;
    }
}

// Manejar la eliminación de IP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ip'])) {
    $ip_to_delete = $_POST['delete_ip']; // No strict IP validation here to allow deleting domains
    delete_ip_from_config($ip_to_delete);
    header("Location: " . $_SERVER['PHP_SELF'] . "?action=deleted" . $network_param);
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
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=timer_updated" . $network_param);
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
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=ping_attempts_updated" . $network_param);
        exit;
    } else {
        echo "<script>alert('Please enter a valid number greater than 0.');</script>";
    }
}
// Manejar la eliminación de datos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_data'])) {
    // Limpiar los datos de ping
    if (file_exists($ping_file)) {
        file_put_contents($ping_file, json_encode([])); // Vaciar el archivo
    }

    // Verificar si se deben borrar también las IPs
    if (isset($_POST['delete_ips'])) {
        $config = parse_ini_file($config_path, true);
        $config['ips-services'] = []; // Vaciar la sección de IPs

        // Reconstruir el contenido del archivo ini
        $new_content = '';
        foreach ($config as $section => $values) {
            $new_content .= "[$section]\n";
            foreach ($values as $key => $value) {
                $new_content .= "$key = \"$value\"\n";
            }
        }
        file_put_contents($config_path, $new_content);
    }

    // Redirigir para evitar reenvío del formulario
    header("Location: " . $_SERVER['PHP_SELF'] . "?action=data_cleared" . $network_param);
    exit;
}

// Manejar la actualización de servicio (Renombrar, Color, Método)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_service'])) {
    $old_service_name = trim($_POST['old_service_name']);
    $new_service_name = trim($_POST['service_name']);
    $new_service_color = trim($_POST['service_color']);
    $new_service_method = trim($_POST['service_method']);

    if (empty($old_service_name) || empty($new_service_name)) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=empty_service_name" . $network_param);
        exit;
    }

    // Si el nombre cambió, verificar que el nuevo no exista ya (a menos que sea el mismo)
    $config = parse_ini_file($config_path, true);
    if ($old_service_name !== $new_service_name && isset($config['services-colors'][$new_service_name])) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=service_exists" . $network_param);
        exit;
    }

    if (update_service_config($old_service_name, $new_service_name, $new_service_color, $new_service_method)) {
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=service_updated" . $network_param);
        exit;
    } else {
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=service_update_failed" . $network_param);
        exit;
    }
}

// Manejar la eliminación de servicio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_service'])) {
    $service_to_delete = trim($_POST['service_name']);

    if (!empty($service_to_delete)) {
        if (delete_service_from_config($service_to_delete)) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?action=service_cleared" . $network_param);
            exit;
        } else {
            header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=service_clear_failed" . $network_param);
            exit;
        }
    } else {
        header("Location: " . $_SERVER['PHP_SELF'] . "?action=error&msg=invalid_service_name" . $network_param);
        exit;
    }
}

// Ejecutar pings en paralelo (siempre, a menos que sea una acción AJAX que ya salió, o si estamos paginando)
// Obtener solo las IPs en un array
$ips_array = array_keys($ips_to_monitor);
if (!isset($_GET['page'])) {
    update_ping_results_parallel($ips_array);
}

// Guardar resultados actualizados en JSON
file_put_contents($ping_file, json_encode($ping_data));


// Manejar notificaciones
$notifications = [
    'added' => ['type' => 'success', 'icon' => 'fas fa-check-circle', 'message' => 'IP/Dominio añadido exitosamente al monitoreo.'],
    'deleted' => ['type' => 'success', 'icon' => 'fas fa-trash', 'message' => 'IP/Dominio eliminado exitosamente del monitoreo.'],
    'service_added' => ['type' => 'success', 'icon' => 'fas fa-plus-circle', 'message' => 'Servicio creado exitosamente.'],
    'service_updated' => ['type' => 'success', 'icon' => 'fas fa-edit', 'message' => 'Servicio actualizado exitosamente.'],
    'service_cleared' => ['type' => 'success', 'icon' => 'fas fa-server', 'message' => 'Servicio eliminado exitosamente.'],
    'timer_updated' => ['type' => 'success', 'icon' => 'fas fa-clock', 'message' => 'Intervalo de ping actualizado exitosamente.'],
    'ping_attempts_updated' => ['type' => 'success', 'icon' => 'fas fa-network-wired', 'message' => 'Número de intentos de ping actualizado exitosamente.'],
    'data_cleared' => ['type' => 'success', 'icon' => 'fas fa-broom', 'message' => 'Datos de ping eliminados exitosamente.'],
    'error' => ['type' => 'error', 'icon' => 'fas fa-exclamation-circle', 'message' => 'Error: Por favor, verifica los datos ingresados.']
];

// Handle specific error messages
if ($_GET['action'] === 'error' && isset($_GET['msg'])) {
    $error_messages = [
        'invalid_ip' => 'Error: La dirección IP o Dominio ingresado no es válido.',
        'empty_service_name' => 'Error: El nombre del servicio no puede estar vacío.',
        'empty_service_color' => 'Error: Debe seleccionar un color para el servicio.',
        'service_exists' => 'Error: Ya existe un servicio con ese nombre.',
        'config_write_error' => 'Error: No se pudo guardar la configuración.',
        'invalid_service' => 'Error: Debe seleccionar un servicio válido.',
        'ip_exists' => 'Error: Esta IP o Dominio ya está siendo monitoreada.',
        'add_ip_failed' => 'Error: No se pudo agregar la IP al sistema.',
        'service_update_failed' => 'Error: No se pudo actualizar el servicio.',
        'service_clear_failed' => 'Error: No se pudo eliminar el servicio.',
        'invalid_service_name' => 'Error: Nombre de servicio inválido.'
    ];

    $error_msg = $_GET['msg'];
    if (array_key_exists($error_msg, $error_messages)) {
        $notifications['error']['message'] = $error_messages[$error_msg];
    }
}

// Recargar configuración después de procesar POST requests
$config = parse_ini_file($config_path, true);
$ips_to_monitor = $config['ips-services'] ?? [];
$services = $config['services-colors'] ?? [];
$ping_attempts = $config['settings']['ping_attempts'] ?? 5;
$ping_interval = $config['settings']['ping_interval'] ?? 30;

// Cargar la vista al final, con los datos actualizados
require_once __DIR__ . '/views.php';
?>