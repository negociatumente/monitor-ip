<?php
// Función para realizar un ping y actualizar los datos
function update_ping_results($ip)
{
    global $ping_attempts, $ping_data;

    // Detectar si el sistema es Windows
    $isWindows = (PHP_OS_FAMILY === 'Windows');

    // Comando de ping según el sistema operativo
    $escaped_ip = escapeshellarg($ip);
    $pingCommand = $isWindows ? "ping -n 1 -w 1000 $escaped_ip" : "ping -c 1 -W 1 $escaped_ip";

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
    $ips_services = $config['ips-services'] ?? [];

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
                $command = $isWindows
                    ? "ping -n 1 -w 1000 $escaped_ip"
                    : "ping -c 1 -W 1 $escaped_ip";
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

    $ping_results = $ping_data[$ip] ?? array_fill(0, 5, ["status" => "-", "timestamp" => "-", "response_time" => "-", "packet_loss" => "-"]);
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
    if (isset($config['ips-services'][$ip])) {
        unset($config['ips-services'][$ip]);
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

    $config['ips-services'][$clean_ip] = $clean_service;

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

    // Remove all IPs that use this service
    if (isset($config['ips-services'])) {
        foreach ($config['ips-services'] as $ip => $service) {
            if ($service === $service_name) {
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
        $new_content .= "[$section]\n";
        foreach ($values as $key => $value) {
            $new_content .= "$key = \"$value\"\n";
        }
    }

    return file_put_contents($config_path, $new_content) !== false;
}

//STYLING FUNCTIONS
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
        'add_ip_failed' => 'Error: No se pudo agregar la IP al sistema.'
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
        // Actualizar referencias en ips-services
        if (isset($config['ips-services'])) {
            foreach ($config['ips-services'] as $ip => $service) {
                if ($service === $old_name) {
                    $config['ips-services'][$ip] = $new_name;
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
 * Scan local network for active devices
 * Returns array of discovered IPs with their MAC addresses and hostnames
 */
function scan_local_network()
{
    $isWindows = (PHP_OS_FAMILY === 'Windows');
    $discovered_devices = [];

    // Get local network range
    if ($isWindows) {
        // Windows: use ipconfig to get network info
        $ipconfig = shell_exec('ipconfig');
        preg_match('/IPv4.*?:\s*(\d+\.\d+\.\d+\.\d+)/', $ipconfig, $matches);
        $local_ip = $matches[1] ?? '192.168.1.1';

        // Extract network prefix (e.g., 192.168.1)
        $parts = explode('.', $local_ip);
        $network_prefix = implode('.', array_slice($parts, 0, 3));

        // Use arp -a to scan
        $arp_output = shell_exec('arp -a');
        preg_match_all('/(\d+\.\d+\.\d+\.\d+)\s+([0-9a-f\-]+)/i', $arp_output, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $ip = $match[1];
            $mac = $match[2];

            // Filter only local network IPs
            if (strpos($ip, $network_prefix) === 0 && $ip !== $local_ip) {
                // Try to get hostname
                $hostname = gethostbyaddr($ip);
                if ($hostname === $ip) {
                    $hostname = 'Unknown';
                }

                $discovered_devices[] = [
                    'ip' => $ip,
                    'mac' => strtoupper(str_replace('-', ':', $mac)),
                    'hostname' => $hostname
                ];
            }
        }
    } else {
        // Linux: try to use nmap or arp-scan
        $local_ip = shell_exec("hostname -I | awk '{print $1}'");
        $local_ip = trim($local_ip);

        if (empty($local_ip)) {
            $local_ip = '192.168.1.1';
        }

        // Extract network prefix
        $parts = explode('.', $local_ip);
        $network_prefix = implode('.', array_slice($parts, 0, 3));
        $network_range = $network_prefix . '.0/24';


        // Fallback: use arp command
        $nmap_output = shell_exec("nmap -sn $network_range");
        preg_match_all('/Nmap scan report for (.*?)\n/', $nmap_output, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $ip = $match[1];

            if (strpos($ip, $network_prefix) === 0 && $ip !== $local_ip) {
                // Try to get hostname
                $hostname = gethostbyaddr($ip);
                if ($hostname === $ip) {
                    $hostname = 'Unknown';
                }

                $discovered_devices[] = [
                    'ip' => $ip,
                    'hostname' => $hostname
                ];
            }
        }
    }

    // Add local device itself
    $hostname = gethostname();
    $discovered_devices[] = [
        'ip' => $local_ip,
        'mac' => 'SELF',
        'hostname' => $hostname ?: 'Local Device'
    ];

    // Add Gateway
    $gateway_ip = '';
    if ($isWindows) {
        $route_output = shell_exec('route print 0.0.0.0');
        if (preg_match('/0\.0\.0\.0\s+0\.0\.0\.0\s+(\d+\.\d+\.\d+\.\d+)/', $route_output, $matches)) {
            $gateway_ip = $matches[1];
        }
    } else {
        $route_output = shell_exec('ip route | grep default');
        if (preg_match('/default via (\d+\.\d+\.\d+\.\d+)/', $route_output, $matches)) {
            $gateway_ip = $matches[1];
        }
    }

    if (!empty($gateway_ip)) {
        // Check if gateway is already in the list
        $gateway_found = false;
        foreach ($discovered_devices as &$device) {
            if ($device['ip'] === $gateway_ip) {
                $device['hostname'] = 'Gateway'; // Update name if found
                $device['mac'] = ($device['mac'] === 'SELF') ? 'SELF/GATEWAY' : $device['mac'];
                $gateway_found = true;
                break;
            }
        }
        unset($device); // Break reference

        // If not found, add it
        if (!$gateway_found) {
            $discovered_devices[] = [
                'ip' => $gateway_ip,
                'mac' => 'GATEWAY',
                'hostname' => 'Gateway'
            ];
        }
    }

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
                'version' => '0.7.0',
                'ping_attempts' => '5',
                'ping_interval' => '300'
            ],
            'services-colors' => [
                'DEFAULT' => '#6B7280',
                'Local Network' => '#10B981'
            ],
            'services-methods' => [
                'DEFAULT' => 'icmp',
                'Local Network' => 'icmp'
            ],
            'ips-services' => []
        ];
    }

    // Add discovered devices
    foreach ($devices as $device) {
        $ip = $device['ip'];
        // Use custom name if provided, otherwise hostname or default
        $name = !empty($device['name']) ? $device['name'] : (!empty($device['hostname']) ? $device['hostname'] : 'Local Device');

        // Check if IP already exists to avoid overwriting existing names unless explicitly requested
        // But here we want to update if user provided a name
        $config['ips-services'][$ip] = $name;
    }

    // Save config
    $content = '';
    foreach ($config as $section => $values) {
        $content .= "[$section]\n";
        foreach ($values as $key => $value) {
            $content .= "$key = \"$value\"\n";
        }
    }

    return file_put_contents($config_path, $content) !== false;
}


/**
 * Test network latency using speedtest
 */
function test_network_latency()
{
    // Check if speedtest-cli is installed
    $speedtest_check = shell_exec('which speedtest-cli 2>/dev/null');

    if (empty($speedtest_check)) {
        // Fallback to simple ping test
        $ping_output = shell_exec('ping -c 3 8.8.8.8 2>/dev/null');
        preg_match('/rtt min\/avg\/max\/mdev = [\d\.]+\/([\d\.]+)/', $ping_output, $matches);
        return isset($matches[1]) ? round(floatval($matches[1]), 1) : 'N/A';
    }

    // Use speedtest-cli for accurate latency
    $output = shell_exec('speedtest-cli --simple 2>/dev/null');

    if (preg_match('/Ping:\s+([\d\.]+)\s+ms/', $output, $matches)) {
        return round(floatval($matches[1]), 1);
    }

    return 'N/A';
}

/**
 * Test download speed using speedtest-cli
 */
function test_download_speed()
{
    // Check if speedtest-cli is installed
    $speedtest_check = shell_exec('which speedtest-cli 2>/dev/null');

    if (empty($speedtest_check)) {
        // Check for speedtest (Ookla official)
        $speedtest_ookla = shell_exec('which speedtest 2>/dev/null');

        if (!empty($speedtest_ookla)) {
            // Use Ookla speedtest
            $output = shell_exec('speedtest --format=json 2>/dev/null');
            $data = json_decode($output, true);

            if (isset($data['download']['bandwidth'])) {
                // Convert from bytes/s to Mbps
                $mbps = round(($data['download']['bandwidth'] * 8) / 1000000, 2);
                return $mbps;
            }
        }

        return 'N/A';
    }

    // Use speedtest-cli
    $output = shell_exec('speedtest-cli --simple 2>/dev/null');

    if (preg_match('/Download:\s+([\d\.]+)\s+Mbit/', $output, $matches)) {
        return round(floatval($matches[1]), 2);
    }

    return 'N/A';
}

/**
 * Test upload speed using speedtest-cli
 */
function test_upload_speed()
{
    // Check if speedtest-cli is installed
    $speedtest_check = shell_exec('which speedtest-cli 2>/dev/null');

    if (empty($speedtest_check)) {
        // Check for speedtest (Ookla official)
        $speedtest_ookla = shell_exec('which speedtest 2>/dev/null');

        if (!empty($speedtest_ookla)) {
            // Use Ookla speedtest
            $output = shell_exec('speedtest --format=json 2>/dev/null');
            $data = json_decode($output, true);

            if (isset($data['upload']['bandwidth'])) {
                // Convert from bytes/s to Mbps
                $mbps = round(($data['upload']['bandwidth'] * 8) / 1000000, 2);
                return $mbps;
            }
        }

        return 'N/A';
    }

    // Use speedtest-cli
    $output = shell_exec('speedtest-cli --simple 2>/dev/null');

    if (preg_match('/Upload:\s+([\d\.]+)\s+Mbit/', $output, $matches)) {
        return round(floatval($matches[1]), 2);
    }

    return 'N/A';
}

/**
 * Run complete speedtest and return all results
 */
function run_complete_speedtest()
{
    $results = [
        'success' => false,
        'latency' => 'N/A',
        'download' => 'N/A',
        'upload' => 'N/A',
        'server' => 'N/A',
        'isp' => 'N/A'
    ];

    // Check if speedtest-cli is installed
    $speedtest_check = shell_exec('which speedtest-cli 2>/dev/null');

    if (empty($speedtest_check)) {
        // Check for Ookla speedtest
        $speedtest_ookla = shell_exec('which speedtest 2>/dev/null');

        if (!empty($speedtest_ookla)) {
            // Use Ookla speedtest (JSON format)
            $output = shell_exec('speedtest --format=json --accept-license --accept-gdpr 2>/dev/null');
            $data = json_decode($output, true);

            if ($data && isset($data['download'])) {
                $results['success'] = true;
                $results['latency'] = round($data['ping']['latency'], 1);
                $results['download'] = round(($data['download']['bandwidth'] * 8) / 1000000, 2);
                $results['upload'] = round(($data['upload']['bandwidth'] * 8) / 1000000, 2);
                $results['server'] = $data['server']['name'] ?? 'Unknown';
                $results['isp'] = $data['isp'] ?? 'Unknown';
            }

            return $results;
        }

        $results['error'] = 'speedtest-cli or speedtest not installed. Install with: pip install speedtest-cli or download from speedtest.net';
        return $results;
    }

    // Use speedtest-cli
    $output = shell_exec('speedtest-cli --simple 2>/dev/null');

    if (!empty($output)) {
        $results['success'] = true;

        if (preg_match('/Ping:\s+([\d\.]+)\s+ms/', $output, $matches)) {
            $results['latency'] = round(floatval($matches[1]), 1);
        }

        if (preg_match('/Download:\s+([\d\.]+)\s+Mbit/', $output, $matches)) {
            $results['download'] = round(floatval($matches[1]), 2);
        }

        if (preg_match('/Upload:\s+([\d\.]+)\s+Mbit/', $output, $matches)) {
            $results['upload'] = round(floatval($matches[1]), 2);
        }
    }

    return $results;
}

?>