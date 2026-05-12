<?php
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
    global $ping_attempts, $ping_data;

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
    if (count($ping_data[$ip]) > $ping_attempts) {
        // Mantiene solo los últimos registros
        array_pop($ping_data[$ip]);
    }
}

// Nueva función para hacer pings en paralelo
function update_ping_results_parallel($ips)
{
    global $ping_attempts, $ping_data, $config_path;

    // Load methods configuration
    // Load configuration
    $config = parse_ini_file($config_path, true);
    $services_methods = $config['services-methods'] ?? [];
    global $is_local_network;
    $ips_section = $is_local_network ? 'ips-host' : 'ips-services';
    $ips_services = $config[$ips_section] ?? [];

    $isWindows = (PHP_OS_FAMILY === 'Windows');
    $processes = [];
    $pipes = [];
    $results = [];

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

        array_unshift($ping_data[$ip], [
            "status" => $ping_status,
            "timestamp" => $timestamp,
            "response_time" => $response_time,
        ]);
        if (count($ping_data[$ip]) > $ping_attempts) {
            array_pop($ping_data[$ip]);
        }
    }
}

// Función para calcular el estado de la IP
function analyze_ip($ip)
{
    global $ping_data;

    $ping_results = $ping_data[$ip] ?? array_fill(0, 5, ["status" => "-", "timestamp" => "-", "response_time" => "-"]);
    $success_count = 0;
    $total_response_time = 0;
    $response_time_count = 0;

    foreach ($ping_results as $ping) {
        if ($ping['status'] === "UP") {
            $success_count++;
        }
        if ($ping['response_time'] !== 'N/A' && $ping['response_time'] !== '-') {
            $response_time = floatval(str_replace(['ms', ' '], '', $ping['response_time']));
            $total_response_time += $response_time;
            $response_time_count++;
        }
    }
    $percentage = ($success_count / count($ping_results)) * 100;
    $status = ($ping_results[0]['status']);

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
        'label' => $label,
        'average_response_time' => $average_response_time
    ];
}

// Función para eliminar una IP del archivo config.ini y ping_results.json
function delete_ip_from_config($ip)
{
    global $ping_data;

    // Eliminar del archivo config.ini
    global $config_path;
    $config = parse_ini_file($config_path, true);
    global $is_local_network;
    $ips_section = $is_local_network ? 'ips-host' : 'ips-services';
    if (isset($config[$ips_section][$ip])) {
        unset($config[$ips_section][$ip]);
        // Also remove from ips-network if exists
        if (isset($config['ips-network'][$ip])) {
            unset($config['ips-network'][$ip]);
        }
        $new_content = '';
        foreach ($config as $section => $values) {
            $new_content .= "[$section]\n";
            foreach ($values as $key => $value) {
                $new_content .= "$key = \"$value\"\n";
            }
        }
        file_put_contents($config_path, $new_content);
    }

    // Eliminar del archivo ping_results.json
    if (isset($ping_data[$ip])) {
        unset($ping_data[$ip]);
        global $ping_file;
        file_put_contents($ping_file, json_encode($ping_data));
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

// Función para agregar una IP al archivo config.ini
function add_ip_to_config($ip, $service, $method = 'icmp')
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

    global $config_path;
    $config = parse_ini_file($config_path, true);

    // Sanitizar los datos
    // For domains, we just sanitize special chars, for IPs filter_var is good but we need to handle both.
    // htmlspecialchars is generally safe for config values if we treat them as strings.
    $clean_ip = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
    $clean_service = htmlspecialchars($service, ENT_QUOTES, 'UTF-8');
    $clean_method = htmlspecialchars($method, ENT_QUOTES, 'UTF-8');

    global $is_local_network;
    $ips_section = $is_local_network ? 'ips-host' : 'ips-services';
    $config[$ips_section][$clean_ip] = $clean_service;

    // Store monitoring method for the service in services-methods
    if (!isset($config['services-methods'])) {
        $config['services-methods'] = [];
    }
    // Only set method if it doesn't exist for this service (prevent overwriting existing service methods)
    if (!isset($config['services-methods'][$clean_service])) {
        $config['services-methods'][$clean_service] = $clean_method;
    }

    $new_content = '';
    foreach ($config as $section => $values) {
        $new_content .= "[$section]\n";
        foreach ($values as $key => $value) {
            $new_content .= "$key = \"$value\"\n";
        }
    }

    return file_put_contents($config_path, $new_content) !== false;
}

// Exporta el archivo config.ini (devuelve el contenido para descarga)
function export_config_ini()
{
    global $config_path;
    $file = $config_path;
    if (file_exists($file)) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="config.ini"');
        readfile($file);
        exit;
    }
}

// Importa un archivo config.ini subido y lo reemplaza
function import_config_ini($uploaded_file)
{
    global $config_path;
    $destination = $config_path;
    $tmp_path = is_array($uploaded_file) ? ($uploaded_file['tmp_name'] ?? '') : $uploaded_file;
    if ($tmp_path && is_uploaded_file($tmp_path)) {
        move_uploaded_file($tmp_path, $destination);
        return true;
    }
    return false;
}

// Función para eliminar un servicio y todas sus IPs asociadas
function delete_service_from_config($service_name)
{
    global $config_path;
    $config = parse_ini_file($config_path, true);

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

    // Write the updated config back to file
    $new_content = '';
    foreach ($config as $section => $values) {
        // Ensure values is an array before iterating
        if (!is_array($values))
            continue;

        $new_content .= "[$section]\n";
        foreach ($values as $key => $value) {
            $new_content .= "$key = \"$value\"\n";
        }
    }

    return file_put_contents($config_path, $new_content) !== false;
}

/**
 * Save configuration to an INI file
 */
function save_config_file($config, $file_path)
{
    $new_content = '';
    foreach ($config as $section => $values) {
        $new_content .= "[$section]\n";
        foreach ($values as $key => $value) {
            $new_content .= "$key = \"$value\"\n";
        }
    }
    return file_put_contents($file_path, $new_content) !== false;
}

// Función para actualizar el servicio de una IP específica
function update_ip_service($ip, $new_service)
{
    global $config_path;
    $config = parse_ini_file($config_path, true);

    global $is_local_network;
    $ips_section = $is_local_network ? 'ips-host' : 'ips-services';
    if (!$config || !isset($config[$ips_section][$ip])) {
        return false;
    }

    $clean_ip = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
    $clean_service = htmlspecialchars($new_service, ENT_QUOTES, 'UTF-8');

    $config[$ips_section][$clean_ip] = $clean_service;

    return save_config_file($config, $config_path);
}

// Función para actualizar el host y la red de una IP local
function update_local_ip_config($ip, $new_name, $new_network)
{
    global $config_path;
    $config = parse_ini_file($config_path, true);

    if (!$config)
        return false;

    $clean_ip = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');
    $clean_name = htmlspecialchars($new_name, ENT_QUOTES, 'UTF-8');
    $clean_network = htmlspecialchars($new_network, ENT_QUOTES, 'UTF-8');

    // Update or Create sections if they don't exist
    if (!isset($config['ips-host']))
        $config['ips-host'] = [];
    if (!isset($config['ips-network']))
        $config['ips-network'] = [];

    $config['ips-host'][$clean_ip] = $clean_name;
    $config['ips-network'][$clean_ip] = $clean_network;

    // Guardar configuración
    $new_content = '';
    foreach ($config as $section => $values) {
        $new_content .= "[$section]\n";
        foreach ($values as $key => $value) {
            $new_content .= "$key = \"$value\"\n";
        }
    }

    return file_put_contents($config_path, $new_content) !== false;
}

// STYLING FUNCTIONS
function getNotificationData($action, $msg = null)
{
    $notifications = [
        'added' => ['type' => 'success', 'icon' => 'fas fa-check-circle', 'message' => 'IP añadida exitosamente al monitoreo.'],
        'deleted' => ['type' => 'success', 'icon' => 'fas fa-trash', 'message' => 'IP eliminada exitosamente del monitoreo.'],
        'service_added' => ['type' => 'success', 'icon' => 'fas fa-plus-circle', 'message' => 'Servicio creado exitosamente.'],
        'timer_updated' => ['type' => 'success', 'icon' => 'fas fa-clock', 'message' => 'Intervalo de ping actualizado exitosamente.'],
        'ping_attempts_updated' => ['type' => 'success', 'icon' => 'fas fa-network-wired', 'message' => 'Número de intentos de ping actualizado exitosamente.'],
        'data_cleared' => ['type' => 'success', 'icon' => 'fas fa-broom', 'message' => 'Datos de ping eliminados exitosamente.'],
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
        'traceroute_failed' => 'Error: Falló el comando tracert en Windows.'
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
    // Remove # if present
    $hexcolor = ltrim($hexcolor, '#');

    // Convert to RGB
    $r = hexdec(substr($hexcolor, 0, 2));
    $g = hexdec(substr($hexcolor, 2, 2));
    $b = hexdec(substr($hexcolor, 4, 2));

    // Calculate brightness (YIQ formula)
    $yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;

    // Return black or white based on brightness
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

    foreach ($ips_to_monitor as $ip => $service) {
        $result = analyze_ip($ip);
        if ($result['status'] === "UP") {
            $ips_up++;
        } else {
            $ips_down++;
        }

        if ($result['average_response_time'] !== 'N/A') {
            $total_ping += $result['average_response_time'];
            $ping_count++;
        }
    }

    $average_ping = $ping_count > 0 ? round($total_ping / $ping_count, 2) : 'N/A';
    $system_status = $ips_down === 0 ? "Healthy" : ($ips_up > $ips_down ? "Degraded" : "Critical");

    return [
        'total_ips' => $total_ips,
        'ips_up' => $ips_up,
        'ips_down' => $ips_down,
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
    global $config_path;
    $config = parse_ini_file($config_path, true);

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

    // Guardar configuración
    $new_content = '';
    foreach ($config as $section => $values) {
        $new_content .= "[$section]\n";
        foreach ($values as $key => $value) {
            $new_content .= "$key = \"$value\"\n";
        }
    }

    return file_put_contents($config_path, $new_content) !== false;
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
            if ($hostname === $ip)
                $hostname = 'Unknown';
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
                $device['hostname'] = 'Gateway'; // Update name if found
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
                'hostname' => 'Gateway'
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
 * Save discovered local network devices to config_local.ini
 */
function save_local_network_scan($devices)
{
    $config_path = __DIR__ . '/../conf/config_local.ini';

    // Load existing config or create new
    if (file_exists($config_path)) {
        $config = parse_ini_file($config_path, true);
    } else {
        $config = [
            'settings' => [
                'version' => '0.8.0',
                'ping_attempts' => '5',
                'ping_interval' => '300',
                'host_color' => '#b4ecb9ff',
                'gateway_color' => '#f59e0b'
            ],
            'ips-host' => [],
            'ips-network' => []
        ];
    }

    $ips_section = 'ips-host';

    // Add discovered devices
    foreach ($devices as $device) {
        $ip = $device['ip'];
        // Use custom name if provided, otherwise hostname or default
        $name = !empty($device['name']) ? $device['name'] : (!empty($device['hostname']) ? $device['hostname'] : 'Local Device');

        // Sanitize name to prevent INI format issues
        $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

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
    }

    // Save config
    return save_config_file($config, $config_path);
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
    $history_file = __DIR__ . '/../results/speedtest_results.json';
    $history = [];

    if (file_exists($history_file)) {
        $content = @file_get_contents($history_file);
        if ($content !== false) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $history = $decoded;
            }
        }
    }

    $new_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'latency' => $results['latency'],
        'download' => $results['download'],
        'upload' => $results['upload'],
        'jitter' => $results['jitter'] ?? 'N/A',
        'packet_loss' => $results['packet_loss'] ?? 0,
    ];

    array_unshift($history, $new_entry);
    $history = array_slice($history, 0, 5); // Keep only last 5

    // Use LOCK_EX to prevent race conditions and @ to suppress warnings that might break JSON response
    @file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT), LOCK_EX);
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

/**
 * Analyze the health of the local network
 */
function get_network_health()
{
    global $ping_file, $config_path;

    // Load configuration for theoretical speed
    $config = parse_ini_file($config_path, true);
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
    if (file_exists($ping_file)) {
        $ping_data = json_decode(file_get_contents($ping_file), true);
        $total_latency = 0;
        $active_count = 0;

        foreach ($ping_data as $ip => $history) {
            // Robust local IP check (Private ranges + Loopback)
            $is_local = false;
            $ip_long = ip2long($ip);

            if ($ip_long !== false) {
                $is_local = (
                    ($ip_long & 0xFF000000) === 0x0A000000 || // 10.0.0.0/8
                    ($ip_long & 0xFFF00000) === 0xAC100000 || // 172.16.0.0/12
                    ($ip_long & 0xFFFF0000) === 0xC0A80000 || // 192.168.0.0/16
                    ($ip_long & 0xFF000000) === 0x7F000000    // 127.0.0.0/8
                );
            } elseif ($ip === '::1') {
                $is_local = true;
            }

            if ($is_local) {
                $report['devices']['total']++;
                if (!empty($history) && isset($history[0]['status']) && $history[0]['status'] === 'UP') {
                    $report['devices']['up']++;
                    $active_count++;

                    $latency_str = $history[0]['response_time'] ?? '0';
                    $latency_val = floatval(str_replace(['ms', ' '], '', $latency_str));
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

    // 4. Get Speed Test results
    $history_file = __DIR__ . '/../results/speedtest_results.json';
    if (file_exists($history_file)) {
        $speed_history = json_decode(file_get_contents($history_file), true);
        if (!empty($speed_history) && is_array($speed_history[0])) {
            $report['speed'] = $speed_history[0]; // Latest test
        }
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
