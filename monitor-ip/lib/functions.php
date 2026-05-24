<?php
require_once __DIR__ . '/db/deployDB.php';
/**
 * Detecta si el script se está ejecutando dentro de un contenedor Docker/Podman
 * @return bool True si está en un contenedor, false en caso contrario
 */
function is_running_in_container()
{
    // Método 1: Verificar archivo .dockerenv (Docker)
    if (file_exists('/.dockerenv')) {
        return true;
    }

    // Método 2: Verificar cgroup para referencias de contenedor
    if (file_exists('/proc/1/cgroup')) {
        $cgroup_content = @file_get_contents('/proc/1/cgroup');
        if (
            $cgroup_content && (strpos($cgroup_content, 'docker') !== false ||
                strpos($cgroup_content, 'containerd') !== false ||
                strpos($cgroup_content, 'podman') !== false)
        ) {
            return true;
        }
    }

    // Método 3: Verificar variables de entorno del contenedor
    if (getenv('container') !== false || getenv('DOCKER_CONTAINER') !== false) {
        return true;
    }

    return false;
}

// Función para realizar un ping y actualizar los datos
function update_ping_results($ip)
{
    global $ping_data;

    // Detectar si el sistema es Windows
    $isWindows = (PHP_OS_FAMILY === 'Windows');

    // Comando de ping según el sistema operativo
    $escaped_ip = escapeshellarg($ip);
    if ($isWindows) {
        $pingCommand = "ping -n 1 -w 1000 $escaped_ip";
    } else {
        // En Linux, usar sudo en contenedores o si no es root en sistemas nativos
        $use_sudo = false;
        if (is_running_in_container()) {
            // En contenedores Docker/Podman, usar sudo para ping
            $use_sudo = true;
        }

        $sudoPrefix = $use_sudo ? "sudo " : "";
        $pingCommand = $sudoPrefix . "/bin/ping -c 1 -W 1 $escaped_ip";
    }

    // Ejecutar el ping
    $ping = shell_exec($pingCommand);

    // Evaluar si la IP respondió correctamente
    $ping_status = (strpos($ping, 'TTL=') !== false || strpos($ping, 'bytes from') !== false) ? "UP" : "DOWN";

    // Captura la fecha y hora actual
    $timestamp = date('Y-m-d H:i:s');

    // Captura el tiempo de respuesta
    if ($isWindows) {
        preg_match('/tiempo[=<]\s*(\d+ms)/', $ping, $matches);
    } else {
        preg_match('/time[=<]\s*([\d\.]+\s*ms)/', $ping, $matches);
    }
    $response_time = $matches[1] ?? 'N/A';
    // Redondear a 2 decimales si es numérico
    if ($response_time !== 'N/A' && $response_time !== '-') {
        $num = floatval(str_replace(['ms', ' '], '', $response_time));
        $response_time = round($num, 2) . ' ms';
    }

    if (!isset($ping_data[$ip])) {
        $ping_data[$ip] = [];
    }

    array_unshift($ping_data[$ip], [
        "status" => $ping_status,
        "timestamp" => $timestamp,
        "response_time" => $response_time,
    ]);

    // Guardar en base de datos
    global $db;
    try {
        $stmt_dev = $db->prepare("SELECT id FROM devices WHERE ip = ?");
        $stmt_dev->execute([$ip]);
        $device_id = $stmt_dev->fetchColumn();
        if ($device_id) {
            $clean_latency = null;
            if ($response_time !== 'N/A' && $response_time !== '-') {
                $clean_latency = floatval(str_replace(['ms', ' '], '', $response_time));
            }
            $stmt_ping_insert = $db->prepare("INSERT INTO ping_results (device_id, status, latency, timestamp) VALUES (?, ?, ?, ?)");
            $stmt_ping_insert->execute([$device_id, $ping_status, $clean_latency, $timestamp]);
        }
    } catch (PDOException $e) {
        error_log("Failed to insert single ping result: " . $e->getMessage());
    }
}

// Nueva función para hacer pings en paralelo
function update_ping_results_parallel($ips)
{
    global $ping_data, $config_path;

    // Load methods configuration
    // Load configuration
    $config = get_current_config();
    $services_methods = $config['services-methods'] ?? [];
    global $is_local_network;
    $ips_section = $is_local_network ? 'ips-host' : 'ips-services';
    $ips_services = $config[$ips_section] ?? [];

    $isWindows = (PHP_OS_FAMILY === 'Windows');
    $processes = [];
    $pipes = [];
    $results = [];
    $telegram_events = [];
    $telegram_cfg = get_telegram_config($config);

    foreach ($ips as $ip) {
        // Determine method based on service
        $service = $ips_services[$ip] ?? 'DEFAULT';
        $method = $services_methods[$service] ?? ($services_methods['DEFAULT'] ?? 'icmp');
        $escaped_ip = escapeshellarg($ip);

        // Choose command based on monitoring method
        switch ($method) {
            case 'curl':
                // For cURL, we check HTTP/HTTPS connectivity
                $protocol = filter_var($ip, FILTER_VALIDATE_IP) ? 'http' : 'https';
                $command = "curl -Is --connect-timeout 1 --max-time 2 $protocol://$escaped_ip 2>&1";
                break;

            case 'dns':
                // For DNS, we use nslookup to resolve domain names
                if ($isWindows) {
                    $command = "nslookup $escaped_ip 2>&1";
                } else {
                    // Use dig for better timing info, fallback to nslookup
                    $command = "dig +time=1 +tries=1 $escaped_ip 2>&1 || nslookup -timeout=1 $escaped_ip 2>&1";
                }
                break;

            case 'icmp':
            default:
                // Standard ICMP ping
                if ($isWindows) {
                    $command = "ping -n 1 -w 1000 $escaped_ip";
                } else {
                    // En Linux, usar sudo en contenedores o si no es root en sistemas nativos
                    $use_sudo = false;
                    if (is_running_in_container()) {
                        // En contenedores Docker/Podman, usar sudo para ping
                        $use_sudo = true;
                    }

                    $sudoPrefix = $use_sudo ? "sudo " : "";
                    $command = $sudoPrefix . "/bin/ping -c 1 -W 1 $escaped_ip";
                }
                break;
        }

        $descriptorspec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];

        $process = proc_open($command, $descriptorspec, $pipe);
        if (is_resource($process)) {
            $processes[$ip] = ['process' => $process, 'method' => $method];
            $pipes[$ip] = $pipe;
        }
    }

    // Leer resultados
    foreach ($processes as $ip => $proc_info) {
        $process = $proc_info['process'];
        $method = $proc_info['method'];

        $output = stream_get_contents($pipes[$ip][1]);
        fclose($pipes[$ip][1]);
        fclose($pipes[$ip][2]);
        proc_close($process);

        // Evaluar si la IP respondió correctamente según el método
        $ping_status = "DOWN";
        $response_time = "N/A";

        switch ($method) {
            case 'curl':
                // Check for HTTP response codes (200, 301, 302, etc.)
                if (preg_match('/HTTP\/[\d\.]+ (\d+)/', $output, $code_matches)) {
                    $http_code = intval($code_matches[1]);
                    $ping_status = ($http_code >= 200 && $http_code < 500) ? "UP" : "DOWN";
                }
                // Try to extract time from curl verbose output
                if (preg_match('/time_total:\s*([\d\.]+)/', $output, $time_matches)) {
                    $response_time = round(floatval($time_matches[1]) * 1000, 2) . ' ms';
                }
                break;

            case 'dns':
                // Check for successful DNS resolution
                if (
                    preg_match('/Address(?:es)?:\s*([\d\.]+)/', $output, $addr_matches) ||
                    preg_match('/ANSWER SECTION/', $output) ||
                    preg_match('/Name:\s*(.+)/', $output)
                ) {
                    $ping_status = "UP";
                    // Try to extract query time from dig output
                    if (preg_match('/Query time:\s*(\d+)\s*msec/', $output, $time_matches)) {
                        $response_time = $time_matches[1] . ' ms';
                    } elseif (preg_match('/time[=<]\s*([\d\.]+\s*ms)/', $output, $time_matches)) {
                        $response_time = $time_matches[1];
                    }
                }
                break;

            case 'icmp':
            default:
                // Standard ICMP ping evaluation
                $ping_status = (strpos($output, 'TTL=') !== false || strpos($output, 'bytes from') !== false) ? "UP" : "DOWN";

                // Captura el tiempo de respuesta
                if ($isWindows) {
                    preg_match('/tiempo[=<]\s*(\d+ms)/', $output, $matches);
                } else {
                    preg_match('/time[=<]\s*([\d\.]+\s*ms)/', $output, $matches);
                }
                $response_time = $matches[1] ?? 'N/A';
                break;
        }

        // Normalize response time
        if ($response_time !== 'N/A' && $response_time !== '-') {
            $num = floatval(str_replace(['ms', ' '], '', $response_time));
            $response_time = round($num, 2) . ' ms';
        }

        // Captura la fecha y hora actual
        $timestamp = date('Y-m-d H:i:s');

        if (!isset($ping_data[$ip])) {
            $ping_data[$ip] = [];
        }

        $previous_status = $ping_data[$ip][0]['status'] ?? null;
        $previous_response_time = $ping_data[$ip][0]['response_time'] ?? null;
        if ($previous_status !== null && $previous_status !== $ping_status) {
            $service = $ips_services[$ip] ?? 'DEFAULT';
            if (should_notify_telegram($previous_status, $ping_status, $telegram_cfg)) {
                $telegram_events[] = [
                    'ip' => $ip,
                    'service' => $service,
                    'old_status' => $previous_status,
                    'new_status' => $ping_status,
                    'timestamp' => $timestamp,
                    'response_time' => $response_time,
                ];
            }
        }

        if ($ping_status === 'UP' && should_notify_telegram_latency($previous_response_time, $response_time, $telegram_cfg)) {
            $service = $ips_services[$ip] ?? 'DEFAULT';
            $telegram_events[] = [
                'ip' => $ip,
                'service' => $service,
                'old_status' => 'LATENCY_OK',
                'new_status' => 'LATENCY_HIGH',
                'timestamp' => $timestamp,
                'response_time' => $response_time,
                'latency_threshold' => $telegram_cfg['latency_threshold'],
            ];
        }

        array_unshift($ping_data[$ip], [
            "status" => $ping_status,
            "timestamp" => $timestamp,
            "response_time" => $response_time,
        ]);

        // Guardar en base de datos
        global $db;
        try {
            $stmt_dev = $db->prepare("SELECT id FROM devices WHERE ip = ?");
            $stmt_dev->execute([$ip]);
            $device_id = $stmt_dev->fetchColumn();
            if ($device_id) {
                $clean_latency = null;
                if ($response_time !== 'N/A' && $response_time !== '-') {
                    $clean_latency = floatval(str_replace(['ms', ' '], '', $response_time));
                }
                $stmt_ping_insert = $db->prepare("INSERT INTO ping_results (device_id, status, latency, timestamp) VALUES (?, ?, ?, ?)");
                $stmt_ping_insert->execute([$device_id, $ping_status, $clean_latency, $timestamp]);
            }
        } catch (PDOException $e) {
            error_log("Failed to insert parallel ping result: " . $e->getMessage());
        }
    }

    if (!empty($telegram_events)) {
        $message = format_telegram_status_summary_message($telegram_events);
        if ($message !== '' && send_telegram_message($message, $telegram_cfg)) {
            foreach ($telegram_events as $event) {
                record_telegram_alert(
                    $event['ip'],
                    $event['old_status'],
                    $event['new_status'],
                    $event['service'],
                    $message,
                    $event['timestamp'],
                    $event['response_time']
                );
            }
        }
    }
}

// Función para calcular el estado de la IP
function analyze_ip($ip)
{
    global $ping_data;

    $ping_results = $ping_data[$ip] ?? array_fill(0, 5, ["status" => "-", "timestamp" => "-", "response_time" => "-"]);
    $since_24h = time() - 86400;
    $since_30d = time() - (86400 * 30);
    $filter_since = function ($ping) {
        if (empty($ping['timestamp']) || $ping['timestamp'] === '-') {
            return false;
        }
        $timestamp = strtotime($ping['timestamp']);
        return $timestamp !== false;
    };
    $ping_results_24h = array_values(array_filter($ping_results, function ($ping) use ($since_24h, $filter_since) {
        return $filter_since($ping) && strtotime($ping['timestamp']) >= $since_24h;
    }));
    $ping_results_30d = array_values(array_filter($ping_results, function ($ping) use ($since_30d, $filter_since) {
        return $filter_since($ping) && strtotime($ping['timestamp']) >= $since_30d;
    }));
    $success_count = 0;
    $total_response_time = 0;
    $response_time_count = 0;
    $monthly_success_count = 0;

    foreach ($ping_results_24h as $ping) {
        if ($ping['status'] === "UP") {
            $success_count++;
        }
        if ($ping['response_time'] !== 'N/A' && $ping['response_time'] !== '-') {
            $response_time = floatval(str_replace(['ms', ' '], '', $ping['response_time']));
            $total_response_time += $response_time;
            $response_time_count++;
        }
    }
    foreach ($ping_results_30d as $ping) {
        if ($ping['status'] === "UP") {
            $monthly_success_count++;
        }
    }
    $count_ping_results = count($ping_results_24h);
    $count_ping_results_30d = count($ping_results_30d);
    $percentage = $count_ping_results > 0 ? ($success_count / $count_ping_results) * 100 : 0;
    $monthly_percentage = $count_ping_results_30d > 0 ? ($monthly_success_count / $count_ping_results_30d) * 100 : 0;
    $status = !empty($ping_results) ? ($ping_results[0]['status'] ?? "-") : "-";

    if ($percentage >= 80) {
        $label = "Good";
    } elseif ($percentage >= 60) {
        $label = "Stable";
    } else {
        $label = "Critical";
    }

    $average_response_time = $response_time_count > 0 ? $total_response_time / $response_time_count : 'N/A';

    return [
        'status' => $status,
        'percentage' => $percentage,
        'ping_results' => $ping_results,
        'ping_results_24h' => $ping_results_24h,
        'sample_count_24h' => $count_ping_results,
        'monthly_percentage' => $monthly_percentage,
        'sample_count_30d' => $count_ping_results_30d,
        'label' => $label,
        'average_response_time' => $average_response_time
    ];
}

// Función para eliminar una IP de la base de datos
function delete_ip($ip)
{
    global $db;

    // Eliminar de base de datos SQLite
    if (isset($db)) {
        try {
            // Borrar de ping_results primero
            $stmt = $db->prepare("DELETE FROM ping_results WHERE device_id IN (SELECT id FROM devices WHERE ip = ?)");
            $stmt->execute([$ip]);

            // Luego eliminar el dispositivo
            $stmt = $db->prepare("DELETE FROM devices WHERE ip = ?");
            $stmt->execute([$ip]);
        } catch (PDOException $e) {
            error_log("Error deleting IP from DB: " . $e->getMessage());
        }
    }
}

// Helper function to validate IP or Domain/Hostname
function isValidHost($host)
{
    // Check if it's a valid IP
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return true;
    }
    // Check if it's a valid Hostname (Domain or local hostname)
    // Allows alphanumeric, hyphens, and dots.
    // Must start and end with alphanumeric.
    // Length 1-253 chars.
    return preg_match('/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/', $host)
        && strlen($host) <= 253;
}

// Función para agregar una IP 
function add_ip($ip, $service, $method = 'icmp', $type = '')
{
    // Validar IP o Dominio
    if (!isValidHost($ip)) {
        return false;
    }

    // Validar servicio
    if (empty($service)) {
        return false;
    }

    // Validar método
    $valid_methods = ['icmp', 'curl', 'dns'];
    if (!in_array($method, $valid_methods)) {
        $method = 'icmp'; // Default fallback
    }

    global $is_local_network;
    $config = get_current_config();

    // Sanitizar los datos
    // For domains, we just sanitize special chars, for IPs filter_var is good but we need to handle both.
    // htmlspecialchars is generally safe for config values if we treat them as strings.
    $clean_ip = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
    $clean_service = htmlspecialchars($service, ENT_QUOTES, 'UTF-8');
    $clean_method = htmlspecialchars($method, ENT_QUOTES, 'UTF-8');

    global $is_local_network;
    $ips_section = $is_local_network ? 'ips-host' : 'ips-services';
    $config[$ips_section][$clean_ip] = $clean_service;

    if (!empty($type)) {
        if (!isset($config['ips-type'])) {
            $config['ips-type'] = [];
        }
        $config['ips-type'][$clean_ip] = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
    }

    // Store monitoring method for the service in services-methods
    if (!$is_local_network) {
        if (!isset($config['services-methods'])) {
            $config['services-methods'] = [];
        }
        // Only set method if it doesn't exist for this service (prevent overwriting existing service methods)
        if (!isset($config['services-methods'][$clean_service])) {
            $config['services-methods'][$clean_service] = $clean_method;
        }
    }

    return save_config_file($config);
}

function get_monitor_db_path()
{
    return __DIR__ . '/../database/monitor.db';
}

// Exporta el archivo de base de datos SQLite actual para descarga
function export_monitor_db()
{
    $file = get_monitor_db_path();

    if (file_exists($file)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="monitor.db"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
    echo 'Archivo de base de datos no encontrado.';
    exit;
}

// Importa un archivo monitor.db subido y reemplaza la base de datos actual
function import_monitor_db($uploaded_file)
{
    $tmp_path = is_array($uploaded_file) ? ($uploaded_file['tmp_name'] ?? '') : $uploaded_file;
    $destination = get_monitor_db_path();
    $db_dir = dirname($destination);

    if (!$tmp_path || !is_uploaded_file($tmp_path)) {
        return false;
    }

    if (!is_dir($db_dir)) {
        if (!mkdir($db_dir, 0775, true) && !is_dir($db_dir)) {
            return false;
        }
    }

    if (!move_uploaded_file($tmp_path, $destination)) {
        return false;
    }

    if (!file_exists($destination) || filesize($destination) === 0) {
        return false;
    }

    return true;
}

// Función para eliminar un servicio y todas sus IPs asociadas
function delete_service($service_name)
{
    $config = get_current_config();

    if (!$config) {
        return false;
    }

    global $is_local_network;
    $ips_section = $is_local_network ? 'ips-host' : 'ips-services';

    // Remove all IPs that use this service
    if (isset($config[$ips_section]) && is_array($config[$ips_section])) {
        $ips_to_remove = [];
        foreach ($config[$ips_section] as $ip => $service) {
            if ($service === $service_name) {
                $ips_to_remove[] = $ip;
            }
        }

        foreach ($ips_to_remove as $ip) {
            unset($config[$ips_section][$ip]);
            // Also clean up from ips-services if exists
            if (isset($config['ips-services']) && isset($config['ips-services'][$ip])) {
                unset($config['ips-services'][$ip]);
            }
        }
    }

    // Remove the service itself (color)
    if (isset($config['services-colors'][$service_name])) {
        unset($config['services-colors'][$service_name]);
    }

    // Remove service method
    if (isset($config['services-methods'][$service_name])) {
        unset($config['services-methods'][$service_name]);
    }

    return save_config_file($config);
}

/**
 * Change the dashboard login password (stored in config.ini [security]).
 *
 * @return array{success: bool, error?: string}
 */
function change_user_password($current_password, $new_password, $confirm_password)
{
    $config = get_current_config();

    if (!$config || empty($config['security']['password'])) {
        return ['success' => false, 'error' => 'login_not_configured'];
    }

    $stored_hash = $config['security']['password'];

    if ($current_password === '' || $new_password === '') {
        return ['success' => false, 'error' => 'empty_password'];
    }

    if (hash('sha512', $current_password) !== $stored_hash) {
        return ['success' => false, 'error' => 'wrong_current_password'];
    }

    if ($new_password !== $confirm_password) {
        return ['success' => false, 'error' => 'password_mismatch'];
    }

    if (hash('sha512', $new_password) === $stored_hash) {
        return ['success' => false, 'error' => 'same_password'];
    }

    $config['security']['password'] = hash('sha512', $new_password);

    if (!save_config_file($config)) {
        return ['success' => false, 'error' => 'config_write_error'];
    }

    return ['success' => true];
}

function load_config($is_local_network = false)
{
    global $db;
    $merged = [];

    // 1. Try to load general config (settings, security, telegram) from settings database table
    try {
        $stmt = $db->query("SELECT * FROM settings");
        $db_settings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($db_settings)) {
            foreach ($db_settings as $s) {
                $merged[$s['section']][$s['key']] = $s['value'];
            }
        }
    } catch (PDOException $e) {
        error_log("Failed to load settings from SQLite: " . $e->getMessage());
    }

    // 2. Load services configuration from services database table
    try {
        $stmt_srv = $db->query("SELECT * FROM services");
        $services = $stmt_srv->fetchAll(PDO::FETCH_ASSOC);

        $merged['services-methods'] = [];
        $merged['services-colors'] = [];
        foreach ($services as $srv) {
            $merged['services-methods'][$srv['name']] = $srv['method'];
            $merged['services-colors'][$srv['name']] = $srv['color'];
        }
    } catch (PDOException $e) {
        error_log("Failed to load services from SQLite: " . $e->getMessage());
    }

    $merged = ensure_config_structure($merged, $is_local_network);

    // 3. Load devices from devices database table
    try {
        $stmt = $db->prepare("SELECT * FROM devices WHERE is_local = ?");
        $stmt->execute([$is_local_network ? 1 : 0]);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $db_ips_host = [];
        $db_ips_type = [];
        $db_ips_network = [];

        foreach ($devices as $d) {
            $db_ips_host[$d['ip']] = $d['host'];
            $db_ips_type[$d['ip']] = $d['type'];
            $db_ips_network[$d['ip']] = $d['network'];
        }

        if ($is_local_network) {
            $merged['ips-host'] = $db_ips_host;
            $merged['ips-network'] = $db_ips_network;
        } else {
            $merged['ips-services'] = $db_ips_host;
        }
        $merged['ips-type'] = $db_ips_type;
    } catch (PDOException $e) {
        error_log("Failed to load devices from SQLite: " . $e->getMessage());
    }

    return $merged;
}

function get_current_config()
{
    global $is_local_network;
    return load_config($is_local_network);
}

/**
 * Save configuration to INI files (general and network specific)
 */
function save_config_file($config, $file_path = '')
{
    global $is_local_network;

    // Ensure structure is clean
    $config = ensure_config_structure($config, $is_local_network);

    global $db;
    try {
        $db->beginTransaction();

        // 1. Sync settings, security, telegram to settings database table
        $general_sections = ['settings', 'telegram', 'security'];
        $stmt_setting_check = $db->prepare("SELECT 1 FROM settings WHERE section = ? AND key = ?");
        $stmt_setting_insert = $db->prepare("INSERT INTO settings (section, key, value) VALUES (?, ?, ?)");
        $stmt_setting_update = $db->prepare("UPDATE settings SET value = ? WHERE section = ? AND key = ?");
        $db->prepare("DELETE FROM settings WHERE section = ? AND key = ?")->execute(['settings', 'ping_attempts']);

        foreach ($general_sections as $section) {
            if (isset($config[$section]) && is_array($config[$section])) {
                foreach ($config[$section] as $key => $value) {
                    $stmt_setting_check->execute([$section, $key]);
                    $exists = $stmt_setting_check->fetchColumn();
                    if ($exists) {
                        $stmt_setting_update->execute([(string) $value, $section, $key]);
                    } else {
                        $stmt_setting_insert->execute([$section, $key, (string) $value]);
                    }
                }
            }
        }

        // 2. Sync services to services database table
        $services_colors = $config['services-colors'] ?? [];
        $services_methods = $config['services-methods'] ?? [];
        $all_service_names = array_unique(array_merge(array_keys($services_colors), array_keys($services_methods)));

        $stmt_service_check = $db->prepare("SELECT 1 FROM services WHERE name = ?");
        $stmt_service_insert = $db->prepare("INSERT INTO services (name, method, color) VALUES (?, ?, ?)");
        $stmt_service_update = $db->prepare("UPDATE services SET method = ?, color = ? WHERE name = ?");

        foreach ($all_service_names as $name) {
            $color = $services_colors[$name] ?? '#6b7280';
            $method = $services_methods[$name] ?? 'icmp';

            $stmt_service_check->execute([$name]);
            $exists = $stmt_service_check->fetchColumn();
            if ($exists) {
                $stmt_service_update->execute([$method, $color, $name]);
            } else {
                $stmt_service_insert->execute([$name, $method, $color]);
            }
        }

        // Clean services that are no longer in the config
        if (!empty($all_service_names)) {
            $placeholders = implode(',', array_fill(0, count($all_service_names), '?'));
            $stmt_service_delete = $db->prepare("DELETE FROM services WHERE name NOT IN ($placeholders)");
            $stmt_service_delete->execute($all_service_names);
        } else {
            $db->exec("DELETE FROM services");
        }

        // 3. Sync devices to SQLite database
        $current_ips = [];
        $db_devices = $is_local_network ? ($config['ips-host'] ?? []) : ($config['ips-services'] ?? []);

        $stmt_check = $db->prepare("SELECT id FROM devices WHERE ip = ?");
        $stmt_insert = $db->prepare("INSERT INTO devices (ip, host, type, network, is_local) VALUES (?, ?, ?, ?, ?)");
        $stmt_update = $db->prepare("UPDATE devices SET host = ?, type = ?, network = ?, is_local = ? WHERE ip = ?");

        foreach ($db_devices as $ip => $host) {
            $current_ips[] = $ip;
            $type = $config['ips-type'][$ip] ?? 'other';
            $network = $is_local_network ? ($config['ips-network'][$ip] ?? 'Ethernet') : 'Ethernet';
            $is_local = $is_local_network ? 1 : 0;

            $stmt_check->execute([$ip]);
            $exists = $stmt_check->fetchColumn();

            if ($exists) {
                $stmt_update->execute([$host, $type, $network, $is_local, $ip]);
            } else {
                $stmt_insert->execute([$ip, $host, $type, $network, $is_local]);
            }
        }

        // Delete devices that are no longer in this network's config
        if (!empty($current_ips)) {
            $placeholders = implode(',', array_fill(0, count($current_ips), '?'));
            $stmt_delete = $db->prepare("DELETE FROM devices WHERE is_local = ? AND ip NOT IN ($placeholders)");
            $stmt_delete->execute(array_merge([$is_local_network ? 1 : 0], $current_ips));
        } else {
            $stmt_delete = $db->prepare("DELETE FROM devices WHERE is_local = ?");
            $stmt_delete->execute([$is_local_network ? 1 : 0]);
        }

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Failed to save configuration to SQLite: " . $e->getMessage());
    }

    return true;
}

/**
 * Obtiene la versión actual de la aplicación desde la base de datos
 * @return string Versión de la aplicación
 */
function get_version_from_db()
{
    global $db;
    try {
        if ($db) {
            $stmt = $db->prepare("SELECT value FROM settings WHERE section = ? AND key = ?");
            $stmt->execute(['settings', 'version']);
            $db_version = $stmt->fetchColumn();
            if ($db_version !== false && $db_version !== null && $db_version !== '') {
                return $db_version;
            }
        }
    } catch (PDOException $e) {
        error_log("Failed to fetch version from database: " . $e->getMessage());
    }
    return '1.1.0'; // Fallback a la versión por defecto si falla
}

function ensure_config_structure($config, $is_local_network = false)
{
    if (!is_array($config)) {
        $config = [];
    }

    $config['settings'] = array_merge([
        'ping_interval' => '300',
    ], $config['settings'] ?? []);
    unset($config['settings']['ping_attempts']);

    $config['settings']['version'] = get_version_from_db();

    if ($is_local_network) {
        $config['ips-host'] = $config['ips-host'] ?? [];
        $config['ips-network'] = $config['ips-network'] ?? [];
        $config['ips-type'] = $config['ips-type'] ?? [];
        unset($config['services-colors']);
        unset($config['services-methods']);
        unset($config['ips-services']);
    } else {
        $config['services-colors'] = array_merge([
            'DEFAULT' => '#6B7280',
        ], $config['services-colors'] ?? []);

        $config['services-methods'] = array_merge([
            'DEFAULT' => 'icmp',
        ], $config['services-methods'] ?? []);

        $config['ips-services'] = $config['ips-services'] ?? [];
        $config['ips-type'] = $config['ips-type'] ?? [];
        unset($config['ips-network']);
    }

    $telegram = get_telegram_config($config);
    $config['telegram'] = [
        'enabled' => $telegram['enabled'] ? 'true' : 'false',
        'bot_token' => $telegram['bot_token'],
        'chat_id' => $telegram['chat_id'],
        'notify_on_up' => $telegram['notify_on_up'] ? 'true' : 'false',
        'notify_on_down' => $telegram['notify_on_down'] ? 'true' : 'false',
        'notify_on_latency' => $telegram['notify_on_latency'] ? 'true' : 'false',
        'latency_threshold' => (string) $telegram['latency_threshold'],
        'frequency' => (string) $telegram['frequency'],
        'message_template' => $telegram['message_template'],
    ];

    return $config;
}

function get_telegram_config($config)
{
    $defaults = [
        'enabled' => 'false',
        'bot_token' => '',
        'chat_id' => '',
        'notify_on_up' => 'true',
        'notify_on_down' => 'true',
        'notify_on_latency' => 'false',
        'latency_threshold' => '100',
        'frequency' => '300',
        'message_template' => 'Dispositivo: {service} ({ip}) ha cambiado a estado {status}',
    ];

    $telegram = array_merge($defaults, $config['telegram'] ?? []);
    $message_template = str_replace('\n', "\n", (string) $telegram['message_template']);

    return [
        'enabled' => filter_var($telegram['enabled'], FILTER_VALIDATE_BOOLEAN),
        'bot_token' => trim((string) $telegram['bot_token']),
        'chat_id' => trim((string) $telegram['chat_id']),
        'notify_on_up' => filter_var($telegram['notify_on_up'], FILTER_VALIDATE_BOOLEAN),
        'notify_on_down' => filter_var($telegram['notify_on_down'], FILTER_VALIDATE_BOOLEAN),
        'notify_on_latency' => filter_var($telegram['notify_on_latency'], FILTER_VALIDATE_BOOLEAN),
        'latency_threshold' => max(1, (int) $telegram['latency_threshold']),
        'frequency' => max(0, (int) $telegram['frequency']),
        'message_template' => trim($message_template) !== ''
            ? $message_template
            : $defaults['message_template'],
    ];
}

function send_telegram_message($text, $telegram_cfg)
{
    $token = trim($telegram_cfg['bot_token'] ?? '');
    $chat_id = trim((string) ($telegram_cfg['chat_id'] ?? ''));

    if ($token === '' || $chat_id === '') {
        error_log('Telegram alert skipped: missing bot token or chat ID.');
        return false;
    }

    if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/', $token)) {
        error_log('Telegram alert skipped: invalid bot token format.');
        return false;
    }

    $bot_id = strtok($token, ':');
    if ($chat_id === $bot_id) {
        error_log('Telegram alert skipped: chat ID points to the bot itself.');
        return false;
    }

    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $payload = http_build_query([
        'chat_id' => $chat_id,
        'text' => $text,
        'disable_web_page_preview' => 'true',
    ]);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false || $status_code < 200 || $status_code >= 300) {
            error_log('Telegram alert failed: ' . ($error ?: 'HTTP ' . $status_code . ' ' . (string) $response));
            return false;
        }

        $decoded = json_decode($response, true);
        return (bool) ($decoded['ok'] ?? false);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 8,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        error_log('Telegram alert failed: HTTP request could not be completed.');
        return false;
    }

    $decoded = json_decode($response, true);
    if (!($decoded['ok'] ?? false)) {
        error_log('Telegram alert failed: ' . $response);
        return false;
    }

    return true;
}

function format_telegram_message($template, $ip, $service, $status, $timestamp = null, $response_time = null)
{
    $template = trim((string) $template);
    $values = [
        'service' => (string) $service,
        'ip' => (string) $ip,
        'status' => (string) $status,
        'status_icon' => $status === 'UP' ? '✅' : '🚨',
        'timestamp' => (string) ($timestamp ?? date('Y-m-d H:i:s')),
        'response_time' => (string) ($response_time ?? 'N/A'),
    ];

    if ($template === '') {
        $template = 'Dispositivo: {service} ({ip}) ha cambiado a estado {status}';
    }

    $placeholder_pattern = '/\{\s*(service|ip|status|status_icon|timestamp|response_time)\s*\}/i';
    $has_placeholders = preg_match($placeholder_pattern, $template) === 1;
    $message = preg_replace_callback($placeholder_pattern, function ($matches) use ($values) {
        return $values[strtolower($matches[1])];
    }, $template);

    if (!$has_placeholders) {
        $message .= "\n\nServicio: {$values['service']}\nIP: {$values['ip']}\nEstado: {$values['status']}";
    }

    return $message;
}

function should_notify_telegram($old_status, $new_status, $cfg)
{
    if (!in_array($old_status, ['UP', 'DOWN'], true) || !in_array($new_status, ['UP', 'DOWN'], true)) {
        return false;
    }

    if (empty($cfg['enabled']) || $old_status === $new_status) {
        return false;
    }

    if ($new_status === 'UP') {
        return !empty($cfg['notify_on_up']);
    }

    if ($new_status === 'DOWN') {
        return !empty($cfg['notify_on_down']);
    }

    return false;
}

function extract_latency_ms($response_time)
{
    if ($response_time === null || $response_time === 'N/A' || $response_time === '-') {
        return null;
    }

    $latency = floatval(str_replace(['ms', ' '], '', (string) $response_time));
    return $latency > 0 ? $latency : null;
}

function should_notify_telegram_latency($previous_response_time, $current_response_time, $cfg)
{
    if (empty($cfg['enabled']) || empty($cfg['notify_on_latency'])) {
        return false;
    }

    $threshold = max(1, (int) ($cfg['latency_threshold'] ?? 100));
    $previous_latency = extract_latency_ms($previous_response_time);
    $current_latency = extract_latency_ms($current_response_time);

    if ($current_latency === null || $current_latency <= $threshold) {
        return false;
    }

    return $previous_latency !== null && $previous_latency <= $threshold;
}

function format_telegram_status_summary_message(array $events)
{
    if (empty($events)) {
        return '';
    }

    $down_events = [];
    $up_events = [];
    $latency_events = [];
    foreach ($events as $event) {
        $display_name = !empty($event['ip']) ? $event['ip'] : $event['service'];
        if ($event['new_status'] === 'DOWN') {
            $down_events[] = "• {$display_name} → DOWN";
        } elseif ($event['new_status'] === 'UP') {
            $up_events[] = "• {$display_name} → UP";
        } elseif ($event['new_status'] === 'LATENCY_HIGH') {
            $threshold = (int) ($event['latency_threshold'] ?? 0);
            $latency_events[] = "• {$display_name} → {$event['response_time']} (umbral {$threshold} ms)";
        }
    }

    $message_parts = [];
    if (!empty($down_events)) {
        //$time_label = format_telegram_event_time_label($events[0]['timestamp']);
        $message_parts[] = "🚨 Incidencia detectada\n\n" . count($down_events) . " IPs caídas:\n\n" . implode("\n", $down_events);
    }

    if (!empty($up_events)) {
        //$time_label = format_telegram_event_time_label($events[0]['timestamp']);
        $message_parts[] = "✅ Recuperación detectada\n\n" . count($up_events) . " IPs recuperadas:\n\n" . implode("\n", $up_events);
    }

    if (!empty($latency_events)) {
        $message_parts[] = "⚠️ Latencia alta detectada\n\n" . count($latency_events) . " IPs superan el umbral:\n\n" . implode("\n", $latency_events);
    }

    return implode("\n\n", $message_parts);
}

function format_telegram_event_time_label($timestamp)
{
    try {
        $date = new DateTime($timestamp, new DateTimeZone(date_default_timezone_get()));
        $date->setTimezone(new DateTimeZone('UTC'));
        return $date->format('H:i');
    } catch (Exception $e) {
        return date('H:i');
    }
}

function get_telegram_alert_history($limit = 25)
{
    global $db;
    try {
        $stmt = $db->prepare("SELECT timestamp, service, ip, old_status, new_status, response_time, message FROM telegram_alerts ORDER BY timestamp DESC LIMIT ?");
        $stmt->bindValue(1, max(1, (int) $limit), PDO::PARAM_INT);
        $stmt->execute();
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($history)) {
            return $history;
        }
    } catch (PDOException $e) {
        error_log("Failed to load telegram alerts from SQLite: " . $e->getMessage());
    }

    return [];
}

function record_telegram_alert($ip, $old_status, $new_status, $service, $message, $timestamp = null, $response_time = null)
{
    $formatted_timestamp = (string) ($timestamp ?? date('Y-m-d H:i:s'));

    // Guardar en la base de datos
    global $db;
    try {
        $stmt = $db->prepare("INSERT INTO telegram_alerts (timestamp, service, ip, old_status, new_status, response_time, message) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $formatted_timestamp,
            (string) $service,
            (string) $ip,
            (string) $old_status,
            (string) $new_status,
            (string) ($response_time ?? 'N/A'),
            (string) $message
        ]);

        // Mantener solo las últimas 100 alertas para evitar que la tabla crezca infinitamente
        $db->exec("DELETE FROM telegram_alerts WHERE id NOT IN (
            SELECT id FROM telegram_alerts ORDER BY timestamp DESC LIMIT 100
        )");
    } catch (PDOException $e) {
        error_log("Failed to insert telegram alert to SQLite: " . $e->getMessage());
    }
}

function notify_telegram_status_change($ip, $old_status, $new_status, $service, $telegram_cfg, $timestamp = null, $response_time = null)
{
    if (!should_notify_telegram($old_status, $new_status, $telegram_cfg)) {
        return;
    }

    $message = format_telegram_message($telegram_cfg['message_template'], $ip, $service, $new_status, $timestamp, $response_time);
    if (send_telegram_message($message, $telegram_cfg)) {
        record_telegram_alert($ip, $old_status, $new_status, $service, $message, $timestamp, $response_time);
    }
}

// Función para actualizar el servicio de una IP específica
function update_ip_service($ip, $new_service, $type = '')
{
    $config = get_current_config();

    global $is_local_network;
    $ips_section = $is_local_network ? 'ips-host' : 'ips-services';
    if (!$config || !isset($config[$ips_section][$ip])) {
        return false;
    }

    $clean_ip = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
    $clean_service = htmlspecialchars($new_service, ENT_QUOTES, 'UTF-8');
    $clean_type = htmlspecialchars(strtolower(str_replace('/', '-', trim($type))), ENT_QUOTES, 'UTF-8');

    $config[$ips_section][$clean_ip] = $clean_service;

    if (!isset($config['ips-type'])) {
        $config['ips-type'] = [];
    }

    if (!empty($clean_type)) {
        $config['ips-type'][$clean_ip] = $clean_type;
    } else if (isset($config['ips-type'][$clean_ip])) {
        unset($config['ips-type'][$clean_ip]);
    }

    return save_config_file($config);
}

// Función para actualizar el host y la red de una IP local
function update_local_ip_config($ip, $new_name, $new_network, $new_type = '')
{
    $config = get_current_config();

    if (!$config)
        return false;

    $clean_ip = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
    $clean_name = htmlspecialchars($new_name, ENT_QUOTES, 'UTF-8');
    $clean_type = htmlspecialchars(strtolower(str_replace('/', '-', $new_type)), ENT_QUOTES, 'UTF-8');
    $clean_network = htmlspecialchars($new_network, ENT_QUOTES, 'UTF-8');

    // Update or Create sections if they don't exist
    if (!isset($config['ips-host']))
        $config['ips-host'] = [];
    if (!isset($config['ips-network']))
        $config['ips-network'] = [];
    if (!isset($config['ips-type']))
        $config['ips-type'] = [];

    $config['ips-host'][$clean_ip] = $clean_name;
    $config['ips-network'][$clean_ip] = $clean_network;
    $config['ips-type'][$clean_ip] = $clean_type;

    return save_config_file($config);
}

// STYLING FUNCTIONS
function getNotificationData($action, $msg = null)
{
    $notifications = [
        'added' => ['type' => 'success', 'icon' => 'fas fa-check-circle', 'message' => 'IP añadida exitosamente al monitoreo.'],
        'deleted' => ['type' => 'success', 'icon' => 'fas fa-trash', 'message' => 'IP eliminada exitosamente del monitoreo.'],
        'service_added' => ['type' => 'success', 'icon' => 'fas fa-plus-circle', 'message' => 'Servicio creado exitosamente.'],
        'timer_updated' => ['type' => 'success', 'icon' => 'fas fa-clock', 'message' => 'Intervalo de ping actualizado exitosamente.'],
        'data_cleared' => ['type' => 'success', 'icon' => 'fas fa-broom', 'message' => 'Datos de ping eliminados exitosamente.'],
        'password_updated' => ['type' => 'success', 'icon' => 'fas fa-key', 'message' => 'Contraseña actualizada correctamente.'],
        'telegram_updated' => ['type' => 'success', 'icon' => 'fab fa-telegram-plane', 'message' => 'Alertas de Telegram actualizadas correctamente.'],
        'error' => ['type' => 'error', 'icon' => 'fas fa-exclamation-circle', 'message' => 'Error: Por favor, verifica los datos ingresados.']
    ];

    $error_messages = [
        'invalid_ip' => 'Error: La dirección IP ingresada no es válida.',
        'empty_service_name' => 'Error: El nombre del servicio no puede estar vacío.',
        'empty_service_color' => 'Error: Debe seleccionar un color para el servicio.',
        'service_exists' => 'Error: Ya existe un servicio con ese nombre.',
        'config_write_error' => 'Error: No se pudo guardar la configuración.',
        'invalid_service' => 'Error: Debe seleccionar un servicio válido.',
        'ip_exists' => 'Error: Esta IP ya está siendo monitoreada.',
        'add_ip_failed' => 'Error: No se pudo agregar la IP al sistema.',
        'scan_failed' => 'Error: Falló el escaneo de red local (nmap).',
        'speedtest_failed' => 'Error: Falló la prueba de velocidad (SpeedTest).',
        'traceroute_failed' => 'Error: Falló el comando tracert en Windows.',
        'wrong_current_password' => 'Error: La contraseña actual no es correcta.',
        'password_mismatch' => 'Error: Las contraseñas nuevas no coinciden.',
        'empty_password' => 'Error: Las contraseñas no pueden estar vacías.',
        'same_password' => 'Error: La nueva contraseña debe ser distinta a la actual.',
        'login_not_configured' => 'Error: El acceso con contraseña no está configurado.',
        'password_change_disabled' => 'Error: El inicio de sesión no está habilitado.',
        'telegram_config_error' => 'Error: No se pudo guardar la configuración de Telegram.'
    ];

    if ($action === 'error' && $msg && array_key_exists($msg, $error_messages)) {
        $notification = $notifications['error'];
        $notification['message'] = $error_messages[$msg];
        return $notification;
    }

    return $notifications[$action] ?? null;
}

function renderNotification($action, $msg = null)
{
    $notification = getNotificationData($action, $msg);

    if (!$notification) {
        return '';
    }

    $bgColor = $notification['type'] === 'success'
        ? 'bg-green-100 border-green-500 text-green-700'
        : 'bg-red-100 border-red-500 text-red-700';

    return "
    <div class='mb-6 p-4 rounded-lg border-l-4 {$bgColor} shadow-sm' id='notification'>
        <div class='flex items-center'>
            <i class='{$notification['icon']} mr-3'></i>
            <span>{$notification['message']}</span>
            <button onclick=\"document.getElementById('notification').style.display='none'\" class='ml-auto text-gray-500 hover:text-gray-700'>
                <i class='fas fa-times'></i>
            </button>
        </div>
    </div>";
}

function getContrastColor($hexcolor)
{
    $hexcolor = trim((string) $hexcolor);
    if ($hexcolor === '') {
        return '#000000';
    }

    // RGB notation support (rgb(...) or rgba(...))
    if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/i', $hexcolor, $matches)) {
        $r = min(255, max(0, (int) $matches[1]));
        $g = min(255, max(0, (int) $matches[2]));
        $b = min(255, max(0, (int) $matches[3]));
    } else {
        // Remove # if present
        $hexcolor = ltrim($hexcolor, '#');

        // Support 3-digit and 8-digit hex values
        if (preg_match('/^[0-9a-fA-F]{3}$/', $hexcolor)) {
            $hexcolor = $hexcolor[0] . $hexcolor[0] . $hexcolor[1] . $hexcolor[1] . $hexcolor[2] . $hexcolor[2];
        }

        if (preg_match('/^[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?$/', $hexcolor)) {
            $r = hexdec(substr($hexcolor, 0, 2));
            $g = hexdec(substr($hexcolor, 2, 2));
            $b = hexdec(substr($hexcolor, 4, 2));
        } else {
            return '#000000';
        }
    }

    // Calculate brightness (YIQ formula)
    $yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;

    return ($yiq >= 128) ? '#000000' : '#ffffff';
}

// System Stats Functions
function calculateSystemStats($ips_to_monitor)
{
    $total_ips = count($ips_to_monitor);
    $ips_up = 0;
    $ips_down = 0;
    $total_ping = 0;
    $ping_count = 0;
    $total_24h_pings = 0;
    $up_24h_pings = 0;

    foreach ($ips_to_monitor as $ip => $service) {
        $result = analyze_ip($ip);
        if ($result['status'] === "UP") {
            $ips_up++;
        } else {
            $ips_down++;
        }

        foreach ($result['ping_results_24h'] as $ping) {
            $total_24h_pings++;
            if (($ping['status'] ?? null) === 'UP') {
                $up_24h_pings++;
            }
            if (($ping['response_time'] ?? 'N/A') !== 'N/A' && ($ping['response_time'] ?? '-') !== '-') {
                $total_ping += floatval(str_replace(['ms', ' '], '', $ping['response_time']));
                $ping_count++;
            }
        }
    }

    $average_ping = $ping_count > 0 ? round($total_ping / $ping_count, 2) : 'N/A';
    $uptime_percentage = $total_24h_pings > 0 ? round(($up_24h_pings / $total_24h_pings) * 100, 1) : 0;
    $system_status = $ips_down === 0 ? "Healthy" : ($ips_up > $ips_down ? "Degraded" : "Critical");

    return [
        'total_ips' => $total_ips,
        'ips_up' => $ips_up,
        'ips_down' => $ips_down,
        'uptime_percentage' => $uptime_percentage,
        'sample_count_24h' => $total_24h_pings,
        'average_ping' => $average_ping,
        'system_status' => $system_status,
        'system_status_color' => getSystemStatusColor($system_status),
        'system_status_icon' => getSystemStatusIcon($system_status)
    ];
}

function getSystemStatusColor($status)
{
    switch ($status) {
        case "Healthy":
            return "bg-green-500";
        case "Degraded":
            return "bg-yellow-500";
        case "Critical":
            return "bg-red-500";
        default:
            return "bg-gray-500";
    }
}

function getSystemStatusIcon($status)
{
    switch ($status) {
        case "Healthy":
            return "check-circle";
        case "Degraded":
            return "exclamation-triangle";
        case "Critical":
            return "exclamation-circle";
        default:
            return "question-circle";
    }
}

function getStatusStyling($status)
{
    return $status === "UP"
        ? ['badge' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200', 'icon' => 'check-circle']
        : ['badge' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200', 'icon' => 'times-circle'];
}

function getLabelStyling($label)
{
    switch ($label) {
        case "Good":
            return ['badge' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200', 'icon' => 'thumbs-up'];
        case "Stable":
            return ['badge' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200', 'icon' => 'exclamation-triangle'];
        case "Critical":
            return ['badge' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200', 'icon' => 'thumbs-down'];
        default:
            return ['badge' => 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200', 'icon' => 'question'];
    }
}

function getServiceStyling($service, $services)
{
    $service_color = $services[$service] ?? ($services['DEFAULT'] ?? '#6B7280');
    return [
        'color' => $service_color,
        'text_color' => getContrastColor($service_color)
    ];
}

function getPercentageStyling($percentage)
{
    if ($percentage >= 90) {
        return [
            'percentage' => $percentage,
            'bar_class' => 'bg-green-500',
            'text_class' => 'text-green-600 dark:text-green-400'
        ];
    } elseif ($percentage >= 75) {
        return [
            'percentage' => $percentage,
            'bar_class' => 'bg-yellow-500',
            'text_class' => 'text-yellow-600 dark:text-yellow-400'
        ];
    } else {
        return [
            'percentage' => $percentage,
            'bar_class' => 'bg-red-500',
            'text_class' => 'text-red-600 dark:text-red-400'
        ];
    }
}

function getResponseTimeStyling($average_response_time)
{
    if ($average_response_time === 'N/A') {
        return [
            'display' => 'N/A',
            'class' => 'text-gray-500 dark:text-gray-400'
        ];
    }

    if ($average_response_time < 50) {
        return [
            'display' => $average_response_time . ' ms',
            'class' => 'text-green-600 dark:text-green-400'
        ];
    } elseif ($average_response_time < 100) {
        return [
            'display' => $average_response_time . ' ms',
            'class' => 'text-yellow-600 dark:text-yellow-400'
        ];
    } else {
        return [
            'display' => $average_response_time . ' ms',
            'class' => 'text-red-600 dark:text-red-400'
        ];
    }
}

// Función para actualizar un servicio (renombrar, cambiar color y método)
function update_service_config($old_name, $new_name, $new_color, $new_method)
{
    $config = get_current_config();

    if (!$config) {
        return false;
    }

    $old_name = htmlspecialchars($old_name, ENT_QUOTES, 'UTF-8');
    $new_name = htmlspecialchars($new_name, ENT_QUOTES, 'UTF-8');
    $new_color = htmlspecialchars($new_color, ENT_QUOTES, 'UTF-8');
    $new_method = htmlspecialchars($new_method, ENT_QUOTES, 'UTF-8');

    // Si el nombre cambió
    if ($old_name !== $new_name) {
        global $is_local_network;
        $ips_section = $is_local_network ? 'ips-host' : 'ips-services';
        // Actualizar referencias en la sección de IPs
        if (isset($config[$ips_section])) {
            foreach ($config[$ips_section] as $ip => $service) {
                if ($service === $old_name) {
                    $config[$ips_section][$ip] = $new_name;
                }
            }
        }

        // Eliminar entradas antiguas
        if (isset($config['services-colors'][$old_name])) {
            unset($config['services-colors'][$old_name]);
        }
        if (isset($config['services-methods'][$old_name])) {
            unset($config['services-methods'][$old_name]);
        }
    }

    // Establecer nuevos valores
    if (!isset($config['services-colors'])) {
        $config['services-colors'] = [];
    }
    $config['services-colors'][$new_name] = $new_color;

    if (!isset($config['services-methods'])) {
        $config['services-methods'] = [];
    }
    $config['services-methods'][$new_name] = $new_method;

    return save_config_file($config);
}

/**
 * Scan private network for active devices
 * Returns array of discovered IPs with their MAC addresses and hostnames
 */
function scan_local_network()
{
    $isWindows = (PHP_OS_FAMILY === 'Windows');

    // Obtener la IP local y el prefijo de red
    if ($isWindows) {
        // Obtener la IP local en Windows
        $ipconfig = shell_exec('ipconfig');
        preg_match('/IPv4.*?:\s*(\d+\.\d+\.\d+\.\d+)/', $ipconfig, $matches);
        $local_ip = $matches[1] ?? '192.168.1.1';
        $parts = explode('.', $local_ip);
        $network_prefix = implode('.', array_slice($parts, 0, 3));
    } else {
        // Obtener la IP local en Linux
        $local_ip = trim(shell_exec("hostname -I | awk '{print $1}'"));
        if (empty($local_ip))
            $local_ip = '192.168.1.1';
        $parts = explode('.', $local_ip);
        $network_prefix = implode('.', array_slice($parts, 0, 3));
    }

    $discovered_devices = [];
    $ips_seen = [];

    // Escaneo con nmap en Windows y Linux
    $nmap_output = shell_exec("nmap -sn " . $network_prefix . ".1-254");
    if ($nmap_output === null || strpos($nmap_output, 'Failed') !== false || strpos($nmap_output, 'command not found') !== false) {
        echo renderNotification('error', 'scan_failed');
        return [];
    }
    foreach (explode("Nmap scan report for ", $nmap_output) as $block) {
        if (preg_match('/(\d+\.\d+\.\d+\.\d+)/', $block, $ip_match)) {
            $ip = $ip_match[1];
            // Buscar MAC address
            if (preg_match('/MAC Address: ([0-9A-Fa-f:]+)/', $block, $mac_match)) {
                $mac = strtoupper($mac_match[1]);
            } else {
                $mac = 'UNKNOWN';
            }
            $hostname = gethostbyaddr($ip);
            if ($hostname === $ip) {
                $hostname = 'Unknown';
            } elseif (strtolower($hostname) === '_gateway') {
                $hostname = 'gateway';
            }
            $discovered_devices[] = [
                'ip' => $ip,
                'mac' => $mac,
                'hostname' => $hostname
            ];
            $ips_seen[] = $ip;
        }
    }

    // Añade el propio dispositivo local si no está en la lista
    if (!in_array($local_ip, $ips_seen)) {
        $hostname = gethostname();
        $discovered_devices[] = [
            'ip' => $local_ip,
            'mac' => 'SELF',
            'hostname' => $hostname ?: 'Local Device'
        ];
    }

    // Añade la puerta de enlace predeterminada Gateway si no está en la lista
    $gateway_ip = '';
    if ($isWindows) {
        // En Windows
        $route_output = shell_exec('route print 0.0.0.0');
        if (preg_match('/0\.0\.0\.0\s+0\.0\.0\.0\s+(\d+\.\d+\.\d+\.\d+)/', $route_output, $matches)) {
            $gateway_ip = $matches[1];
        }
    } else {
        // En Linux
        $route_output = shell_exec('ip route | grep default');
        if (preg_match('/default via (\d+\.\d+\.\d+\.\d+)/', $route_output, $matches)) {
            $gateway_ip = $matches[1];
        }
    }

    // Comprobar si la puerta de enlace ya está en la lista
    if (!empty($gateway_ip)) {
        $gateway_found = false;
        foreach ($discovered_devices as &$device) {
            if ($device['ip'] === $gateway_ip) {
                $device['type'] = 'gateway'; // Update type if found
                if ($device['mac'] === 'SELF')
                    $device['mac'] = 'SELF/GATEWAY';
                $gateway_found = true;
                break;
            }
        }
        unset($device);

        // If not found, add it
        if (!$gateway_found) {
            $discovered_devices[] = [
                'ip' => $gateway_ip,
                'mac' => 'GATEWAY',
                'type' => 'gateway'
            ];
        }
    }

    // Sort logic: Sort by IP
    usort($discovered_devices, function ($a, $b) {
        return ip2long($a['ip']) - ip2long($b['ip']);
    });

    return $discovered_devices;
}


/**
 * Save discovered local network devices to config_private.ini
 */
function save_local_network_scan($devices)
{
    global $is_local_network;
    $is_local_network = true;

    // Load existing config explicitly for private network
    $config = load_config(true);

    $ips_section = 'ips-host';

    // Add discovered devices
    foreach ($devices as $device) {
        $ip = $device['ip'];
        // Use custom name if provided, otherwise hostname or default
        $name = !empty($device['name']) ? $device['name'] : (!empty($device['hostname']) ? $device['hostname'] : 'Local Device');
        $type = isset($device['type']) ? strtolower(str_replace('/', '-', trim($device['type']))) : '';

        // Sanitize values to prevent INI format issues
        $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $type = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');

        // Check if IP already exists to avoid overwriting existing names unless explicitly requested
        // But here we want to update if user provided a name
        $config[$ips_section][$ip] = $name;

        // Save network type if provided
        if (isset($device['network'])) {
            if (!isset($config['ips-network'])) {
                $config['ips-network'] = [];
            }
            $config['ips-network'][$ip] = htmlspecialchars($device['network'], ENT_QUOTES, 'UTF-8');
        }

        // Save device type if provided
        if ($type !== '') {
            if (!isset($config['ips-type'])) {
                $config['ips-type'] = [];
            }
            $config['ips-type'][$ip] = $type;
        }
    }

    // Save config
    return save_config_file($config);
}

/**
 * Calculate packet loss for a given host
 */
function calculate_packet_loss($ip, $count = 10)
{
    $isWindows = (PHP_OS_FAMILY === 'Windows');
    $escaped_ip = escapeshellarg($ip);

    if ($isWindows) {
        $cmd = "ping -n $count -w 1000 $escaped_ip";
    } else {
        $use_sudo = is_running_in_container();
        $sudoPrefix = $use_sudo ? "sudo " : "";
        $cmd = $sudoPrefix . "/bin/ping -c $count -W 1 $escaped_ip";
    }

    $output = shell_exec($cmd);
    if (!$output)
        return 0;

    if ($isWindows) {
        // UTF-8 conversion for correct parsing on Windows (might be CP850 or similar)
        $try_encodings = ['CP850', 'CP1252', 'ISO-8859-1'];
        foreach ($try_encodings as $enc) {
            $converted = @iconv($enc, 'UTF-8//IGNORE', $output);
            if ($converted && (strpos($converted, "% loss") !== false || strpos($converted, "% de pérdida") !== false)) {
                $output = $converted;
                break;
            }
        }

        // Example: "(10% loss)" or "(10% de pérdida)"
        if (preg_match('/\((\d+)%\s*(?:de pérdida|loss)\)/u', $output, $matches)) {
            return (int) $matches[1];
        }
    } else {
        // Example: "10% packet loss"
        if (preg_match('/(\d+)%\s*packet loss/', $output, $matches)) {
            return (int) $matches[1];
        }
    }

    return 0;
}


/**
 * Run complete speedtest and return all results
 */
function run_complete_speedtest()
{
    $decimal = "6";

    // Detect OS
    $isWindows = (PHP_OS_FAMILY === 'Windows');

    // Windows
    if ($isWindows) {
        $bin_path = __DIR__ . '\speedtest.exe';
        if (!is_executable($bin_path)) {
            return [
                'success' => false,
                'error' => 'speedtest_not_found',
                'message' => 'No se encontró speedtest.exe',
                'requires_manual_input' => true,
                'ookla_url' => 'https://www.speedtest.net/'
            ];
        }
        $output = shell_exec('"' . $bin_path . '" --accept-license --accept-gdpr -f json 2>&1');
        $data = json_decode($output, true);
        if (!$data) {
            echo renderNotification('error', 'speedtest_failed');
            return [
                'success' => false,
                'error' => 'No se pudo parsear la salida JSON de speedtest.exe.',
                'raw_output' => $output
            ];
        }
        $decimal = "5";

    } else {
        // Linux/Mac
        $bin_path = __DIR__ . '/SpeedTest++/SpeedTest';
        if (!is_executable($bin_path)) {
            echo renderNotification('error', 'speedtest_failed');
            return [
                'success' => false,
                'error' => 'El binario SpeedTest no es ejecutable o no existe en: ' . $bin_path
            ];
        }
        $output = shell_exec($bin_path . ' --output json 2>&1');
        if (!$output) {
            echo renderNotification('error', 'speedtest_failed');
            return [
                'success' => false,
                'error' => 'No se pudo ejecutar SpeedTest. ¿Está instalado y en el PATH?',
                'raw_output' => ''
            ];
        }
        $data = json_decode($output, true);
        if (!$data) {
            echo renderNotification('error', 'speedtest_failed');
            return [
                'success' => false,
                'error' => 'No se pudo parsear la salida JSON de SpeedTest.',
                'raw_output' => $output
            ];
        }
        $decimal = "6";

    }

    // Extract and validate numeric values
    $latency = 'N/A';
    if (isset($data['ping']) && is_numeric($data['ping'])) {
        $latency = floatval($data['ping']);
    } elseif (isset($data['ping']['latency']) && is_numeric($data['ping']['latency'])) {
        $latency = floatval($data['ping']['latency']);
    }

    $download = 'N/A';
    if (isset($data['download']) && is_numeric($data['download'])) {
        $download = round($data['download'] / pow(10, $decimal), 2);
    } elseif (isset($data['download']['bandwidth']) && is_numeric($data['download']['bandwidth'])) {
        $download = round($data['download']['bandwidth'] / pow(10, $decimal), 2);
    }

    $upload = 'N/A';
    if (isset($data['upload']) && is_numeric($data['upload'])) {
        $upload = round($data['upload'] / pow(10, $decimal), 2);
    } elseif (isset($data['upload']['bandwidth']) && is_numeric($data['upload']['bandwidth'])) {
        $upload = round($data['upload']['bandwidth'] / pow(10, $decimal), 2);
    }

    // Extract Jitter
    $jitter = 0;
    if (isset($data['jitter']) && is_numeric($data['jitter'])) {
        $jitter = floatval($data['jitter']);
    }

    // Packet Loss test after speed test
    $packet_loss = calculate_packet_loss('1.1.1.1', 8);

    $results = [
        'success' => true,
        'latency' => $latency,
        'download' => $download,
        'upload' => $upload,
        'jitter' => $jitter,
        'packet_loss' => $packet_loss,
        'server' => $data['server']['name'] ?? 'N/A',
        'isp' => $data['isp'] ?? 'N/A',
        'raw' => $data,
        'raw_output' => $output
    ];
    if (!empty($results['success'])) {
        save_speedtest_results($results);
    }
    return $results;
}


/**
 * Save manual speedtest results
 */
function save_manual_speedtest($download, $upload, $latency)
{
    // Validate input
    if (!is_numeric($download) || !is_numeric($upload) || !is_numeric($latency)) {
        return [
            'success' => false,
            'error' => 'Datos inválidos. Todos los campos deben ser números.'
        ];
    }

    $download = floatval($download);
    $upload = floatval($upload);
    $latency = floatval($latency);

    // Basic validation ranges
    if (
        $download < 0 || $download > 10000 ||
        $upload < 0 || $upload > 10000 ||
        $latency < 0 || $latency > 5000
    ) {
        return [
            'success' => false,
            'error' => 'Los valores están fuera del rango válido.'
        ];
    }

    $results = [
        'success' => true,
        'latency' => $latency,
        'download' => $download,
        'upload' => $upload,
        'jitter' => 'N/A',
        'packet_loss' => 0,
        'server' => 'Manual',
        'isp' => 'Manual',
        'raw' => [],
        'raw_output' => 'Manual entry'
    ];

    save_speedtest_results($results);
    return $results;
}

/**
 * Save speedtest results to history file
 */
function save_speedtest_results($results)
{
    $timestamp = date('Y-m-d H:i:s');
    $latency = floatval($results['latency']);
    $download = floatval($results['download']);
    $upload = floatval($results['upload']);
    $jitter = $results['jitter'] ?? 'N/A';
    $packet_loss = floatval($results['packet_loss'] ?? 0);

    // Guardar en la base de datos
    global $db;
    try {
        $stmt = $db->prepare("INSERT INTO speedtest_results (timestamp, latency, download, upload, jitter, packet_loss) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$timestamp, $latency, $download, $upload, $jitter, $packet_loss]);

        // Mantener solo los últimos 50 registros para histórico general en base de datos
        $db->exec("DELETE FROM speedtest_results WHERE id NOT IN (
            SELECT id FROM speedtest_results ORDER BY timestamp DESC LIMIT 50
        )");
    } catch (PDOException $e) {
        error_log("Failed to save speedtest result to SQLite: " . $e->getMessage());
    }
}

/**
 * Run traceroute to a host
 */
function run_traceroute($host, $max_hops = 15)
{
    $isWindows = (PHP_OS_FAMILY === 'Windows');
    $host_escaped = escapeshellarg($host);
    $max_hops_int = intval($max_hops);

    if ($isWindows) {
        $command = "tracert -d -h $max_hops_int $host_escaped";
    } else {
        // Fallback logic for Linux: try traceroute, then tracepath
        $command = "traceroute -n -m $max_hops_int $host_escaped 2>&1 || tracepath -n -m $max_hops_int $host_escaped 2>&1";
    }

    $output = shell_exec($command);

    if ($isWindows && (empty($output) || strpos($output, 'No se reconoce') !== false || strpos($output, 'not recognized') !== false)) {
        return json_encode([
            'success' => false,
            'error' => 'Comando tracert no disponible en Windows',
            'raw_output' => $output ?: 'No output',
            'hops' => []
        ]);
    }

    if (empty($output)) {
        return json_encode([
            'success' => false,
            'error' => 'Error running traceroute (tool might not be installed)',
            'raw_output' => '',
            'hops' => []
        ]);
    }

    // Process the output into structured JSON
    $lines = explode("\n", $output);
    $hops = [];
    $destination = $host;
    $max_hops = 15;


    foreach ($lines as $lineNum => $line) {
        $line = trim($line);

        // Skip empty lines and headers
        if (
            empty($line) ||
            strpos($line, 'Tracing route to') !== false ||
            strpos($line, 'traceroute to') !== false ||
            strpos($line, 'Traza a') !== false ||
            strpos($line, 'over a maximum of') !== false ||
            strpos($line, 'hop max') !== false ||
            strpos($line, 'sobre caminos de') !== false ||
            strpos($line, 'saltos como máximo') !== false ||
            strpos($line, 'Traza completa') !== false ||
            strpos($line, 'Trace complete') !== false
        ) {

            // Extract destination info if available
            if (preg_match('/(?:Tracing route to|traceroute to|Traza a)\s+([^\s]+)/', $line, $matches)) {
                $destination = $matches[1];
            }
            if (preg_match('/(?:over a maximum of|sobre caminos de)\s+(\d+)\s+(?:hops|saltos)/', $line, $matches)) {
                $max_hops = intval($matches[1]);
            }
            continue;
        }

        $hop = null;

        if ($isWindows) {
            // Windows format: "  1     1 ms     2 ms     1 ms  192.168.1.1"
            // or: "  2  Request timed out."
            if (preg_match('/^\s*(\d+)\s+(.+)$/', $line, $matches)) {
                $hopNumber = intval($matches[1]);
                $content = trim($matches[2]);

                $hop = [
                    'hop' => $hopNumber,
                    'ip' => null,
                    'hostname' => null,
                    'times' => [],
                    'status' => 'success',
                    'raw_line' => $line
                ];

                // Check for timeout
                if (
                    strpos($content, 'Request timed out') !== false ||
                    strpos($content, 'Tiempo de espera agotado') !== false
                ) {
                    $hop['status'] = 'timeout';
                } else {
                    // Extract timing information
                    if (preg_match_all('/(<?\d+)\s*ms/', $content, $timeMatches)) {
                        $hop['times'] = $timeMatches[1];
                    }

                    // Extract IP address
                    if (preg_match('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/', $content, $ipMatch)) {
                        $hop['ip'] = $ipMatch[1];
                        // Try to get hostname by removing IP and times
                        $cleanContent = $content;
                        $cleanContent = preg_replace('/(<?\d+\s*ms\s*)+/', '', $cleanContent);
                        $cleanContent = str_replace($hop['ip'], '', $cleanContent);
                        $hostname = trim($cleanContent);
                        if (!empty($hostname) && $hostname !== $hop['ip']) {
                            $hop['hostname'] = $hostname;
                        }
                    }
                }
            }
        } else {
            // Linux/Unix format: " 1  gateway (192.168.1.1)  1.234 ms  1.123 ms  1.345 ms"
            // or: " 2  * * *"
            if (preg_match('/^\s*(\d+)\s+(.+)$/', $line, $matches)) {
                $hopNumber = intval($matches[1]);
                $content = trim($matches[2]);

                $hop = [
                    'hop' => $hopNumber,
                    'ip' => null,
                    'hostname' => null,
                    'times' => [],
                    'status' => 'success',
                    'raw_line' => $line
                ];

                // Check for timeout (* * *)
                if (strpos($content, '* * *') !== false || preg_match('/^\s*\*/', $content)) {
                    $hop['status'] = 'timeout';
                } else {
                    // Extract timing information
                    if (preg_match_all('/([\d.]+)\s*ms/', $content, $timeMatches)) {
                        $hop['times'] = $timeMatches[1];
                    }

                    // Extract hostname and IP from format like "gateway (192.168.1.1)"
                    if (preg_match('/^([^\(]+)\s*\(([^)]+)\)/', $content, $hostMatch)) {
                        $hop['hostname'] = trim($hostMatch[1]);
                        $hop['ip'] = trim($hostMatch[2]);
                    } elseif (preg_match('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/', $content, $ipMatch)) {
                        // Just IP without hostname
                        $hop['ip'] = $ipMatch[1];
                    }
                }
            }
        }

        if ($hop) {
            $hops[] = $hop;
        }
    }

    // Convertir output de Windows a UTF-8 para mostrar correctamente tildes y caracteres especiales
    $raw_output = $output;
    if ($isWindows) {
        $try_encodings = ['CP850', 'CP1252', 'ISO-8859-1'];
        foreach ($try_encodings as $enc) {
            $converted = @iconv($enc, 'UTF-8//IGNORE', $output);
            if ($converted && strpos($converted, "�") === false) {
                $raw_output = $converted;
                break;
            }
        }
    }
    return json_encode([
        'success' => true,
        'destination' => $destination,
        'max_hops' => $max_hops,
        'total_hops' => count($hops),
        'hops' => $hops,
        'is_windows' => $isWindows,
        'raw_output' => $raw_output
    ], JSON_PRETTY_PRINT);
}



/**
 * Get GeoIP information for an external IP
 */
function get_geoip_info($ip)
{
    // Resolve if it's a hostname
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $resolved_ip = gethostbyname($ip);
        if ($resolved_ip === $ip) {
            return ['status' => 'fail', 'message' => 'Could not resolve host'];
        }
        $ip = $resolved_ip;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return ['status' => 'fail', 'message' => 'Local or private IP detected'];
    }

    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $response = @file_get_contents("http://ip-api.com/json/$ip?fields=status,message,country,countryCode,regionName,city,zip,lat,lon,timezone,isp,org,as,query", false, $ctx);

    if ($response) {
        return json_decode($response, true);
    }
    return ['status' => 'fail', 'message' => 'API connection failed or timed out'];
}

function get_local_ip_diagnostics($ip, $device_type = 'other')
{
    $ip = trim((string) $ip);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return ['status' => 'fail', 'message' => 'Invalid local IP'];
    }

    $is_windows = (PHP_OS_FAMILY === 'Windows');
    $escaped_ip = escapeshellarg($ip);
    $count = 10;

    if ($is_windows) {
        $ping_command = "ping -n $count -w 1000 $escaped_ip";
    } else {
        $sudo_prefix = is_running_in_container() ? 'sudo ' : '';
        $ping_command = $sudo_prefix . "/bin/ping -c $count -W 1 $escaped_ip 2>&1";
    }

    $ping_output = shell_exec($ping_command) ?? '';
    $latencies = [];
    if (preg_match_all('/time[=<]\s*([\d\.]+)\s*ms/i', $ping_output, $matches)) {
        $latencies = array_map('floatval', $matches[1]);
    } elseif (preg_match_all('/tiempo[=<]\s*([\d\.]+)\s*ms/i', $ping_output, $matches)) {
        $latencies = array_map('floatval', $matches[1]);
    }

    $packet_loss = null;
    if (preg_match('/(\d+(?:\.\d+)?)%\s*(?:packet loss|loss|perdidos)/i', $ping_output, $loss_match)) {
        $packet_loss = (float) $loss_match[1];
    } elseif (preg_match('/\((\d+)%\s*(?:loss|perdidos)\)/i', $ping_output, $loss_match)) {
        $packet_loss = (float) $loss_match[1];
    }

    $received = count($latencies);
    if ($packet_loss === null) {
        $packet_loss = $count > 0 ? round((($count - $received) / $count) * 100, 2) : null;
    }

    $avg = $received > 0 ? round(array_sum($latencies) / $received, 2) : null;
    $min = $received > 0 ? round(min($latencies), 2) : null;
    $max = $received > 0 ? round(max($latencies), 2) : null;
    $jitter = null;
    if ($received > 1) {
        $diffs = [];
        for ($i = 1; $i < $received; $i++) {
            $diffs[] = abs($latencies[$i] - $latencies[$i - 1]);
        }
        $jitter = round(array_sum($diffs) / count($diffs), 2);
    }

    $hostname = gethostbyaddr($ip);
    if ($hostname === $ip) {
        $hostname = null;
    }

    $arp_output = shell_exec(($is_windows ? 'arp -a ' : 'arp -n ') . $escaped_ip . ' 2>&1') ?? '';
    $mac = null;
    if (preg_match('/([0-9a-f]{2}(?:[:-][0-9a-f]{2}){5})/i', $arp_output, $mac_match)) {
        $mac = strtoupper(str_replace('-', ':', $mac_match[1]));
    }

    $type_ports = [
        'gateway' => [['port' => 53, 'protocol' => 'DNS'], ['port' => 80, 'protocol' => 'HTTP'], ['port' => 443, 'protocol' => 'HTTPS'], ['port' => 22, 'protocol' => 'SSH']],
        'router' => [['port' => 53, 'protocol' => 'DNS'], ['port' => 80, 'protocol' => 'HTTP'], ['port' => 443, 'protocol' => 'HTTPS'], ['port' => 22, 'protocol' => 'SSH']],
        'ap-mesh' => [['port' => 80, 'protocol' => 'HTTP'], ['port' => 443, 'protocol' => 'HTTPS'], ['port' => 22, 'protocol' => 'SSH']],
        'camera' => [['port' => 80, 'protocol' => 'HTTP'], ['port' => 443, 'protocol' => 'HTTPS'], ['port' => 554, 'protocol' => 'RTSP'], ['port' => 8080, 'protocol' => 'HTTP-ALT']],
        'printer' => [['port' => 80, 'protocol' => 'HTTP'], ['port' => 443, 'protocol' => 'HTTPS'], ['port' => 631, 'protocol' => 'IPP'], ['port' => 9100, 'protocol' => 'RAW']],
        'server' => [['port' => 22, 'protocol' => 'SSH'], ['port' => 80, 'protocol' => 'HTTP'], ['port' => 443, 'protocol' => 'HTTPS'], ['port' => 445, 'protocol' => 'SMB'], ['port' => 2049, 'protocol' => 'NFS']],
        'computer' => [['port' => 22, 'protocol' => 'SSH'], ['port' => 80, 'protocol' => 'HTTP'], ['port' => 443, 'protocol' => 'HTTPS'], ['port' => 445, 'protocol' => 'SMB'], ['port' => 3389, 'protocol' => 'RDP']],
        'iot' => [['port' => 80, 'protocol' => 'HTTP'], ['port' => 443, 'protocol' => 'HTTPS'], ['port' => 1883, 'protocol' => 'MQTT'], ['port' => 8080, 'protocol' => 'HTTP-ALT']],
        'mobile' => [['port' => 80, 'protocol' => 'HTTP'], ['port' => 443, 'protocol' => 'HTTPS']],
        'other' => [['port' => 22, 'protocol' => 'SSH'], ['port' => 80, 'protocol' => 'HTTP'], ['port' => 443, 'protocol' => 'HTTPS'], ['port' => 445, 'protocol' => 'SMB'], ['port' => 8080, 'protocol' => 'HTTP-ALT']],
    ];
    $normalized_type = strtolower(trim((string) $device_type));
    $ports = $type_ports[$normalized_type] ?? $type_ports['other'];
    $port_results = [];
    foreach ($ports as $port_info) {
        $port = (int) $port_info['port'];
        $errno = 0;
        $errstr = '';
        $start = microtime(true);
        $conn = @fsockopen($ip, $port, $errno, $errstr, 0.35);
        $elapsed = round((microtime(true) - $start) * 1000, 1);
        if ($conn) {
            fclose($conn);
        }
        $port_results[] = [
            'port' => $port,
            'protocol' => $port_info['protocol'],
            'open' => (bool) $conn,
            'latency_ms' => $conn ? $elapsed : null,
        ];
    }

    return [
        'status' => 'success',
        'ip' => $ip,
        'hostname' => $hostname,
        'mac' => $mac,
        'ping' => [
            'sent' => $count,
            'received' => $received,
            'packet_loss' => $packet_loss,
            'avg' => $avg,
            'min' => $min,
            'max' => $max,
            'jitter' => $jitter,
        ],
        'ports' => $port_results,
        'raw_ping' => $ping_output,
        'raw_arp' => $arp_output,
    ];
}

/**
 * Analyze the health of the local network
 */
function get_network_health()
{
    global $config_path;

    // Load configuration for theoretical speed
    $config = get_current_config();
    $theoretical_speed = (float) ($config['settings']['speed_connection_mbps'] ?? 0);

    $isWindows = (PHP_OS_FAMILY === 'Windows');
    $gateway_ip = '';

    $use_sudo = false;
    if (is_running_in_container()) {
        // En contenedores Docker/Podman, usar sudo para ping
        $use_sudo = true;
    }

    $sudoPrefix = $use_sudo ? "sudo " : "";

    // 1. Find Gateway
    if ($isWindows) {
        $route_output = @shell_exec('route print 0.0.0.0');
        if (preg_match('/0\.0\.0\.0\s+0\.0\.0\.0\s+(\d+\.\d+\.\d+\.\d+)/', $route_output, $matches)) {
            $gateway_ip = $matches[1];
        }
    } else {
        $route_output = @shell_exec('ip route | grep default 2>/dev/null');
        if (preg_match('/default via (\d+\.\d+\.\d+\.\d+)/', $route_output, $matches)) {
            $gateway_ip = $matches[1];
        }
    }

    $report = [
        'timestamp' => date('Y-m-d H:i:s'),
        'gateway' => [
            'ip' => $gateway_ip ?: 'Unknown',
            'status' => 'OFFLINE',
            'latency' => 'N/A'
        ],
        'devices' => [
            'total' => 0,
            'up' => 0,
            'avg_latency' => 0
        ],
        'speed' => null,
        'theoretical_speed' => $theoretical_speed,
        'summary' => 'Analysis incomplete'
    ];

    // 2. Ping Gateway if found
    if (!empty($gateway_ip)) {
        $escaped_gateway = escapeshellarg($gateway_ip);
        if ($isWindows) {
            $pingCommand = "ping -n 1 -w 1000 $escaped_gateway";
        } else {
            // Re-use logic: if debug_mode is off and not root, sudoPrefix is set
            $pingCommand = $sudoPrefix . "/bin/ping -c 1 -W 1 $escaped_gateway";
        }
        $ping = @shell_exec($pingCommand);

        if (strpos($ping, 'TTL=') !== false || strpos($ping, 'bytes from') !== false) {
            $report['gateway']['status'] = 'ONLINE';
            if ($isWindows) {
                preg_match('/tiempo[=<]\s*(\d+ms)/', $ping, $matches);
            } else {
                preg_match('/time[=<]\s*([\d\.]+\s*ms)/', $ping, $matches);
            }
            $report['gateway']['latency'] = $matches[1] ?? 'N/A';
        }
    }

    // 3. Analyze active local devices
    global $db;
    try {
        if (isset($db)) {
            $stmt = $db->query("SELECT id FROM devices WHERE is_local = 1");
            $local_devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $total_latency = 0;
            $active_count = 0;
            $report['devices']['total'] = count($local_devices);

            $stmt_ping = $db->prepare("SELECT status, latency FROM ping_results WHERE device_id = ? ORDER BY timestamp DESC LIMIT 1");

            foreach ($local_devices as $dev) {
                $stmt_ping->execute([$dev['id']]);
                $latest = $stmt_ping->fetch(PDO::FETCH_ASSOC);

                if ($latest && $latest['status'] === 'UP') {
                    $report['devices']['up']++;
                    $active_count++;

                    if ($latest['latency'] !== null && $latest['latency'] !== 'N/A') {
                        $latency_val = floatval($latest['latency']);
                        if ($latency_val > 0) {
                            $total_latency += $latency_val;
                        }
                    }
                }
            }

            if ($active_count > 0) {
                $report['devices']['avg_latency'] = round($total_latency / $active_count, 2);
            }
        }
    } catch (PDOException $e) {
        error_log("Failed to load local devices for health check: " . $e->getMessage());
    }

    // 4. Get Speed Test results
    global $db;
    try {
        if (isset($db)) {
            $stmt = $db->query("SELECT timestamp, latency, download, upload, jitter, packet_loss FROM speedtest_results ORDER BY timestamp DESC LIMIT 1");
            $latest_speed = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($latest_speed) {
                $report['speed'] = $latest_speed;
            }
        }
    } catch (PDOException $e) {
        error_log("Failed to load speed test history for health check: " . $e->getMessage());
    }

    // 5. Final Summary logic
    $health_score = 0;
    $score_details = [
        'gateway' => ['score' => 0, 'max' => 20, 'label' => 'Gateway Connectivity'],
        'devices' => ['score' => 0, 'max' => 30, 'label' => 'Avg Device Latency'],
        'speed' => ['score' => 0, 'max' => 20, 'label' => 'Download Speed'],
        'latency' => ['score' => 0, 'max' => 30, 'label' => 'Connection Quality']
    ];

    // Gateway connectivity (20 points)
    if ($report['gateway']['status'] === 'ONLINE') {
        $points = 20;
        $health_score += $points;
        $score_details['gateway']['score'] = $points;
    }

    // Local device latency (30 points)
    $avg_dev_latency = $report['devices']['avg_latency'];
    if ($avg_dev_latency > 0) {
        if ($avg_dev_latency < 5) {
            $points = 30;
        } elseif ($avg_dev_latency < 15) {
            $points = 20;
        } elseif ($avg_dev_latency < 50) {
            $points = 10;
        } else {
            $points = 0;
        }
    } else {
        // If no latency data (e.g. no devices UP), give 0 or neutral
        $points = ($report['devices']['up'] > 0) ? 5 : 0;
    }

    // Fallback/Bonus if 0 latency but devices are UP (local monitoring might be too fast or logic issue, but usually implies good connect)
    if ($active_count > 0 && $avg_dev_latency == 0)
        $points = 30;

    $health_score += $points;
    $score_details['devices']['score'] = $points;


    // Speed performance (20 points)
    if (is_array($report['speed']) && $theoretical_speed > 0) {
        $speed_results = (array) $report['speed'];
        $actual_download = (float) ($speed_results['download'] ?? 0);
        $performance_ratio = $actual_download / $theoretical_speed;

        $points = 0;
        if ($performance_ratio >= 0.8)
            $points = 20;
        elseif ($performance_ratio >= 0.5)
            $points = 10;
        elseif ($performance_ratio >= 0.25)
            $points = 5;

        $health_score += $points;
        $score_details['speed']['score'] = $points;

        $speed_results['performance_ratio'] = round($performance_ratio * 100, 1) . '%';
        $report['speed'] = $speed_results;
    } elseif (is_array($report['speed'])) {
        // Fallback score if no theoretical speed is set but test exists
        $points = 10;
        $health_score += $points;
        $score_details['speed']['score'] = $points;
    }

    // Latency performance (30 points total: 15 latency + 10 jitter + 5 packet loss)
    if (!empty($report['speed']) && is_array($report['speed'])) {
        $points = 0;

        // Latency scoring (15 points max - 3 levels)
        if (isset($report['speed']['latency'])) {
            $latency = (float) $report['speed']['latency'];
            if ($latency > 0) {
                if ($latency < 10)
                    $points += 15;
                elseif ($latency < 20)
                    $points += 10;
                elseif ($latency < 30)
                    $points += 5;
            }
        }

        // Jitter scoring (10 points max - 3 levels)
        if (isset($report['speed']['jitter'])) {
            $jitter = (float) $report['speed']['jitter'];
            if ($jitter < 5)
                $points += 10;
            elseif ($jitter < 10)
                $points += 5;
            elseif ($jitter < 15)
                $points += 1;
        }

        // Packet Loss scoring (5 points max - 3 levels)
        if (isset($report['speed']['packet_loss'])) {
            $packet_loss = (float) $report['speed']['packet_loss'];
            if ($packet_loss == 0)
                $points += 5;
            elseif ($packet_loss < 2)
                $points += 3;
            elseif ($packet_loss < 5)
                $points += 1;
        }

        $health_score += $points;
        $score_details['latency']['score'] = $points;
    }

    if ($health_score >= 90)
        $report['summary'] = 'Excellent';
    elseif ($health_score >= 70)
        $report['summary'] = 'Good';
    elseif ($health_score >= 40)
        $report['summary'] = 'Fair';
    else
        $report['summary'] = 'Poor';

    $report['health_score'] = $health_score;
    $report['score_details'] = $score_details;

    return $report;
}
