<?php
session_start();
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/requestHandler.php';

// Cargar config para verificar si el login está habilitado
$config_main = load_config(false);
$login_enabled = filter_var($config_main['security']['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($login_enabled && !isset($_SESSION['authenticated'])) {
    header('Location: ./auth/login.php');
    exit;
}

// Determine which network type to load
$network_type = isset($_GET['network']) ? $_GET['network'] : 'external';
$is_local_network = ($network_type === 'local');
$network_param = isset($_GET['network']) ? '&network=' . urlencode($_GET['network']) : '';

// Load configuration from appropriate config file
$config_path = __DIR__ . '/conf/' . ($is_local_network ? 'config_private.ini' : 'config_public.ini');

$config = load_config($is_local_network);

if ($is_local_network) {
    $ips_to_monitor = $config['ips-host'] ?? [];
    $ips_network = $config['ips-network'] ?? [];
    $host_color = $config['settings']['host_color'] ?? '#6B7280';
    $network_color = $config['settings']['network_color'] ?? '#1e40af';

    // For compatibility with functions that expect $services
    $services = [];
    foreach ($ips_to_monitor as $ip => $host_name) {
        $device_type = strtolower(trim($config['ips-type'][$ip] ?? ''));
        $is_network_category = ($device_type === 'gateway' || $device_type === 'router' || $device_type === 'ap-mesh' || $device_type === 'ap/mesh');
        $services[$host_name] = $is_network_category ? $network_color : $host_color;
    }
} else {
    $ips_to_monitor = $config['ips-services'] ?? [];
    $services = $config['services-colors'] ?? [];
    $ips_network = [];
}

$services_methods = $config['services-methods'] ?? [];
$ips_types = $config['ips-type'] ?? [];
$ping_interval = $config['settings']['ping_interval'] ?? 300;

// Cargar resultados previos desde la base de datos
$ping_data = load_ping_data_from_db($is_local_network);
if (!is_array($ping_data)) {
    $ping_data = [];
}


// Dispatch request handlers (AJAX + POST forms)
$dispatcher = new requestHandler($is_local_network, $network_param, $config_path, $login_enabled);
$dispatcher->handle();

// Check if speed_connection_mbps is set for local network
$show_speed_prompt = false;
if ($is_local_network) {
    if (!isset($config['settings']['speed_connection_mbps']) || empty($config['settings']['speed_connection_mbps'])) {
        $show_speed_prompt = true;
    }
}



// Ejecutar pings en paralelo (siempre, a menos que sea una acción AJAX que ya salió, o si estamos paginando, o si acabamos de realizar una acción)
// Obtener solo las IPs en un array
$ips_array = array_keys($ips_to_monitor);
if (!isset($_GET['page']) && !isset($_GET['action']) && !isset($_GET['no_ping'])) {
    // Intrusos (solo red local): escanear con nmap y alertar por Telegram si hay IPs desconocidas
    if ($is_local_network) {
        $telegram_cfg = get_telegram_config($config);
        if ($telegram_cfg['enabled'] && !empty($telegram_cfg['notify_on_intruder'])) {
            try {
                $unknown_devices = detect_unknown_local_devices();
                // Guardar intrusos detectados como "conocidos" (type=intruder) en SQLite
                record_intruders_in_devices($unknown_devices);
                notify_intruders_via_telegram($unknown_devices, $telegram_cfg);
            } catch (Throwable $e) {
                error_log('Intruder detection failed: ' . $e->getMessage());
            }
        }
    }
    update_ping_results_parallel($ips_array);
}

// Manejar notificaciones
$notifications = [
    'added' => ['type' => 'success', 'icon' => 'fas fa-check-circle', 'message' => 'IP/Dominio añadido exitosamente al monitoreo.'],
    'deleted' => ['type' => 'success', 'icon' => 'fas fa-trash', 'message' => 'IP/Dominio eliminado exitosamente del monitoreo.'],
    'service_added' => ['type' => 'success', 'icon' => 'fas fa-plus-circle', 'message' => 'Servicio creado exitosamente.'],
    'service_updated' => ['type' => 'success', 'icon' => 'fas fa-edit', 'message' => 'Servicio actualizado exitosamente.'],
    'service_cleared' => ['type' => 'success', 'icon' => 'fas fa-server', 'message' => 'Servicio eliminado exitosamente.'],
    'timer_updated' => ['type' => 'success', 'icon' => 'fas fa-clock', 'message' => 'Intervalo de ping actualizado exitosamente.'],
    'data_cleared' => ['type' => 'success', 'icon' => 'fas fa-broom', 'message' => 'Datos de ping eliminados exitosamente.'],
    'password_updated' => ['type' => 'success', 'icon' => 'fas fa-key', 'message' => 'Contraseña actualizada correctamente.'],
    'telegram_updated' => ['type' => 'success', 'icon' => 'fab fa-telegram-plane', 'message' => 'Alertas de Telegram actualizadas correctamente.'],
    'error' => ['type' => 'error', 'icon' => 'fas fa-exclamation-circle', 'message' => 'Error: Por favor, verifica los datos ingresados.']
];

// Handle specific error messages
if (isset($_GET['action']) && $_GET['action'] === 'error' && isset($_GET['msg'])) {
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
        'invalid_service_name' => 'Error: Nombre de servicio inválido.',
        'wrong_current_password' => 'Error: La contraseña actual no es correcta.',
        'password_mismatch' => 'Error: Las contraseñas nuevas no coinciden.',
        'empty_password' => 'Error: Las contraseñas no pueden estar vacías.',
        'same_password' => 'Error: La nueva contraseña debe ser distinta a la actual.',
        'login_not_configured' => 'Error: El acceso con contraseña no está configurado.',
        'password_change_disabled' => 'Error: El inicio de sesión no está habilitado.',
        'telegram_config_error' => 'Error: No se pudo guardar la configuración de Telegram.'
    ];

    $error_msg = $_GET['msg'];
    if (array_key_exists($error_msg, $error_messages)) {
        $notifications['error']['message'] = $error_messages[$error_msg];
    }
}

// Recargar configuración después de procesar POST requests
$config = load_config($is_local_network);

if ($is_local_network) {
    $ips_to_monitor = $config['ips-host'] ?? [];
    $ips_network = $config['ips-network'] ?? [];
    $host_color = $config['settings']['host_color'] ?? '#6B7280';
    $network_color = $config['settings']['network_color'] ?? '#1e40af';

    $services = [];
    foreach ($ips_to_monitor as $ip => $host_name) {
        $device_type = strtolower(trim($config['ips-type'][$ip] ?? ''));
        $is_network_category = ($device_type === 'gateway' || $device_type === 'router' || $device_type === 'ap-mesh' || $device_type === 'ap/mesh');
        $services[$host_name] = $is_network_category ? $network_color : $host_color;
    }
} else {
    $ips_to_monitor = $config['ips-services'] ?? [];
    $services = $config['services-colors'] ?? [];
    $ips_network = [];
}

$ping_interval = $config['settings']['ping_interval'] ?? 300;
$telegram_config = get_telegram_config($config);
$telegram_alert_history = get_telegram_alert_history(25);
$telegram_config_json = json_encode([
    'enabled' => $telegram_config['enabled'],
    'bot_token' => $telegram_config['bot_token'],
    'chat_id' => $telegram_config['chat_id'],
    'notify_on_up' => $telegram_config['notify_on_up'],
    'notify_on_down' => $telegram_config['notify_on_down'],
    'notify_on_latency' => $telegram_config['notify_on_latency'],
    'notify_on_intruder' => $telegram_config['notify_on_intruder'],
    'latency_threshold' => $telegram_config['latency_threshold'],
    'message_template' => $telegram_config['message_template'],
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$telegram_alert_history_json = json_encode($telegram_alert_history, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$ai_config_json = json_encode(get_ai_config($config), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

// Cargar la vista al final, con los datos actualizados
require_once __DIR__ . '/views.php';
?>
