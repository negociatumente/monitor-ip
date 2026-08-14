<?php
require_once __DIR__ . '/deployDB.php';

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
    return __DIR__ . '/../db/monitor.db';
}

function get_report_window_start_30d()
{
    // Use SQLite datetime-compatible format (UTC/local depends on PHP config; consistent within this app).
    return date('Y-m-d H:i:s', strtotime('-30 days'));
}

function fetch_monthly_report_data($is_local_network, $window_start)
{
    global $db;

    $report = [
        'window_start' => $window_start,
        'window_end' => date('Y-m-d H:i:s'),
        'devices' => [],
        'totals' => [
            'samples' => 0,
            'up' => 0,
            'down' => 0,
        ],
    ];

    try {
        $stmt_devices = $db->prepare("SELECT id, ip, host, type, network FROM devices WHERE is_local = ? ORDER BY ip ASC");
        $stmt_devices->execute([$is_local_network ? 1 : 0]);
        $devices = $stmt_devices->fetchAll(PDO::FETCH_ASSOC);

        $stmt_rows = $db->prepare("
            SELECT status, latency, timestamp
            FROM ping_results
            WHERE device_id = ? AND timestamp >= ?
            ORDER BY timestamp ASC
        ");

        foreach ($devices as $device) {
            $stmt_rows->execute([(int) $device['id'], $window_start]);
            $rows = $stmt_rows->fetchAll(PDO::FETCH_ASSOC);

            $samples = count($rows);
            $up = 0;
            $down = 0;
            $latencies = [];

            foreach ($rows as $r) {
                if (($r['status'] ?? '') === 'UP') {
                    $up++;
                    if ($r['latency'] !== null && is_numeric($r['latency'])) {
                        $latencies[] = (float) $r['latency'];
                    }
                } else {
                    $down++;
                }
            }

            sort($latencies);
            $lat_count = count($latencies);
            $avg_latency = $lat_count > 0 ? array_sum($latencies) / $lat_count : null;
            $min_latency = $lat_count > 0 ? $latencies[0] : null;
            $max_latency = $lat_count > 0 ? $latencies[$lat_count - 1] : null;
            $p95_latency = null;
            if ($lat_count > 0) {
                $idx = (int) ceil(0.95 * $lat_count) - 1;
                $idx = max(0, min($idx, $lat_count - 1));
                $p95_latency = $latencies[$idx];
            }

            $uptime_pct = $samples > 0 ? ($up / $samples) * 100 : 0;

            $report['devices'][] = [
                'ip' => $device['ip'],
                'host' => $device['host'],
                'type' => $device['type'],
                'network' => $device['network'],
                'samples' => $samples,
                'up' => $up,
                'down' => $down,
                'uptime_pct' => $uptime_pct,
                'avg_latency_ms' => $avg_latency,
                'min_latency_ms' => $min_latency,
                'max_latency_ms' => $max_latency,
                'p95_latency_ms' => $p95_latency,
            ];

            $report['totals']['samples'] += $samples;
            $report['totals']['up'] += $up;
            $report['totals']['down'] += $down;
        }
    } catch (PDOException $e) {
        error_log("Failed to fetch monthly report data: " . $e->getMessage());
    }

    return $report;
}

function fetch_system_monthly_report_data($is_local_network, $window_start)
{
    global $db;

    $report = [
        'window_start' => $window_start,
        'window_end' => date('Y-m-d H:i:s'),
        'network_label' => $is_local_network ? 'local' : 'external',
        'totals' => [
            'samples' => 0,
            'up' => 0,
            'down' => 0,
            'uptime_pct' => 0,
            'avg_latency_ms' => null,
            'p95_latency_ms' => null,
        ],
        'days' => [], // YYYY-MM-DD => metrics
    ];

    try {
        $windowStartTs = strtotime($window_start);
        $windowStartDay = date('Y-m-d', $windowStartTs);
        $windowEndDay = date('Y-m-d');

        // Daily totals across all IPs (sample-weighted)
        $stmt = $db->prepare("
            SELECT
                date(pr.timestamp) AS day,
                COUNT(*) AS samples,
                SUM(CASE WHEN pr.status = 'UP' THEN 1 ELSE 0 END) AS up,
                SUM(CASE WHEN pr.status != 'UP' THEN 1 ELSE 0 END) AS down,
                AVG(CASE WHEN pr.status = 'UP' AND pr.latency IS NOT NULL THEN pr.latency ELSE NULL END) AS avg_latency_ms
            FROM ping_results pr
            JOIN devices d ON d.id = pr.device_id
            WHERE d.is_local = ? AND pr.timestamp >= ?
            GROUP BY day
            ORDER BY day ASC
        ");
        $stmt->execute([$is_local_network ? 1 : 0, $window_start]);
        $rowsTotals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalsByDay = [];
        foreach ($rowsTotals as $r) {
            $day = (string) ($r['day'] ?? '');
            if ($day === '') {
                continue;
            }
            $totalsByDay[$day] = [
                'samples' => (int) ($r['samples'] ?? 0),
                'up' => (int) ($r['up'] ?? 0),
                'down' => (int) ($r['down'] ?? 0),
                'avg_latency_ms' => ($r['avg_latency_ms'] !== null && is_numeric($r['avg_latency_ms'])) ? (float) $r['avg_latency_ms'] : null,
            ];
        }

        // Daily average uptime across IPs (device-weighted: each IP has same weight)
        $stmtIpAvg = $db->prepare("
            SELECT
                day_data.day AS day,
                AVG(day_data.uptime_pct) AS uptime_pct_ip_avg
            FROM (
                SELECT
                    date(pr.timestamp) AS day,
                    pr.device_id,
                    (100.0 * SUM(CASE WHEN pr.status = 'UP' THEN 1 ELSE 0 END) / COUNT(*)) AS uptime_pct
                FROM ping_results pr
                JOIN devices d ON d.id = pr.device_id
                WHERE d.is_local = ? AND pr.timestamp >= ?
                GROUP BY date(pr.timestamp), pr.device_id
            ) AS day_data
            GROUP BY day_data.day
            ORDER BY day_data.day ASC
        ");
        $stmtIpAvg->execute([$is_local_network ? 1 : 0, $window_start]);
        $rowsIpAvg = $stmtIpAvg->fetchAll(PDO::FETCH_ASSOC);
        $ipAvgByDay = [];
        foreach ($rowsIpAvg as $r) {
            $day = (string) ($r['day'] ?? '');
            if ($day === '') {
                continue;
            }
            $ipAvgByDay[$day] = ($r['uptime_pct_ip_avg'] !== null && is_numeric($r['uptime_pct_ip_avg'])) ? (float) $r['uptime_pct_ip_avg'] : 0.0;
        }

        // Optional: latency distribution for p95 (overall)
        $stmt_lat = $db->prepare("
            SELECT pr.latency AS latency
            FROM ping_results pr
            JOIN devices d ON d.id = pr.device_id
            WHERE d.is_local = ? AND pr.timestamp >= ? AND pr.status = 'UP' AND pr.latency IS NOT NULL
            ORDER BY pr.latency ASC
        ");
        $stmt_lat->execute([$is_local_network ? 1 : 0, $window_start]);
        $latencies = $stmt_lat->fetchAll(PDO::FETCH_COLUMN, 0);
        $latencies = array_values(array_filter($latencies, fn($v) => $v !== null && is_numeric($v)));
        $latencies = array_map('floatval', $latencies);
        sort($latencies);
        $latCount = count($latencies);
        $p95 = null;
        if ($latCount > 0) {
            $idx = (int) ceil(0.95 * $latCount) - 1;
            $idx = max(0, min($idx, $latCount - 1));
            $p95 = $latencies[$idx];
        }

        $totalSamples = 0;
        $totalUp = 0;
        $totalDown = 0;
        // Fill complete day range (all month window), even if day has no samples.
        $cur = strtotime($windowStartDay . ' 00:00:00');
        $end = strtotime($windowEndDay . ' 00:00:00');
        while ($cur <= $end) {
            $day = date('Y-m-d', $cur);
            $samples = (int) ($totalsByDay[$day]['samples'] ?? 0);
            $up = (int) ($totalsByDay[$day]['up'] ?? 0);
            $down = (int) ($totalsByDay[$day]['down'] ?? 0);
            $avgLatency = $totalsByDay[$day]['avg_latency_ms'] ?? null;
            $uptimeBySamples = $samples > 0 ? ($up / $samples) * 100 : 0;
            $uptimeByIpAvg = isset($ipAvgByDay[$day]) ? (float) $ipAvgByDay[$day] : 0.0;

            $report['days'][$day] = [
                'day' => $day,
                'samples' => $samples,
                'up' => $up,
                'down' => $down,
                'uptime_pct' => $uptimeByIpAvg,
                'uptime_pct_weighted' => $uptimeBySamples,
                'avg_latency_ms' => $avgLatency,
            ];

            $totalSamples += $samples;
            $totalUp += $up;
            $totalDown += $down;
            $cur = strtotime('+1 day', $cur);
        }

        // Accurate overall avg latency for UP samples with latency.
        $stmt_avg = $db->prepare("
            SELECT AVG(pr.latency) AS avg_latency_ms
            FROM ping_results pr
            JOIN devices d ON d.id = pr.device_id
            WHERE d.is_local = ? AND pr.timestamp >= ? AND pr.status = 'UP' AND pr.latency IS NOT NULL
        ");
        $stmt_avg->execute([$is_local_network ? 1 : 0, $window_start]);
        $overallAvg = $stmt_avg->fetchColumn();
        $overallAvg = ($overallAvg !== false && $overallAvg !== null && is_numeric($overallAvg)) ? (float) $overallAvg : null;

        $report['totals']['samples'] = $totalSamples;
        $report['totals']['up'] = $totalUp;
        $report['totals']['down'] = $totalDown;
        // Monthly uptime total as average of daily IP-average uptime (same weighting per day).
        $dailyUptimes = array_map(
            fn($d) => (float) ($d['uptime_pct'] ?? 0),
            array_values($report['days'])
        );
        $report['totals']['uptime_pct'] = count($dailyUptimes) > 0 ? (array_sum($dailyUptimes) / count($dailyUptimes)) : 0;
        $report['totals']['avg_latency_ms'] = $overallAvg;
        $report['totals']['p95_latency_ms'] = $p95;
    } catch (PDOException $e) {
        error_log("Failed to fetch system monthly report data: " . $e->getMessage());
    }

    return $report;
}

function export_monthly_report_csv($is_local_network)
{
    $window_start = get_report_window_start_30d();
    $report = fetch_system_monthly_report_data($is_local_network, $window_start);

    $network_label = $is_local_network ? 'local' : 'external';
    $safe_date = date('Y-m-d');
    $filename = "monitor-report-{$network_label}-30d-{$safe_date}.csv";

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error');
        echo 'No se pudo generar el CSV.';
        exit;
    }

    // UTF-8 BOM for Excel compatibility
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, ['IP Monitor - Reporte general (ultimos 30 días)']);
    fputcsv($out, ['Red', $network_label]);
    fputcsv($out, ['Ventana inicio', $report['window_start']]);
    fputcsv($out, ['Ventana fin', $report['window_end']]);
    fputcsv($out, []);

    fputcsv($out, ['Resumen']);
    fputcsv($out, ['samples_total', (int) ($report['totals']['samples'] ?? 0)]);
    fputcsv($out, ['up_total', (int) ($report['totals']['up'] ?? 0)]);
    fputcsv($out, ['down_total', (int) ($report['totals']['down'] ?? 0)]);
    fputcsv($out, ['uptime_pct_total', round((float) ($report['totals']['uptime_pct'] ?? 0), 2)]);
    fputcsv($out, ['avg_latency_ms_total', $report['totals']['avg_latency_ms'] === null ? '' : round((float) $report['totals']['avg_latency_ms'], 2)]);
    fputcsv($out, ['p95_latency_ms_total', $report['totals']['p95_latency_ms'] === null ? '' : round((float) $report['totals']['p95_latency_ms'], 2)]);

    fputcsv($out, []);
    fputcsv($out, ['Serie diaria (por fecha)']);
    fputcsv($out, ['day', 'samples', 'up', 'down', 'uptime_pct', 'avg_latency_ms']);
    foreach ($report['days'] as $day => $d) {
        fputcsv($out, [
            $day,
            (int) ($d['samples'] ?? 0),
            (int) ($d['up'] ?? 0),
            (int) ($d['down'] ?? 0),
            round((float) ($d['uptime_pct'] ?? 0), 2),
            $d['avg_latency_ms'] === null ? '' : round((float) $d['avg_latency_ms'], 2),
        ]);
    }

    fclose($out);
    exit;
}

function export_monthly_report_pdf($is_local_network)
{
    $window_start = get_report_window_start_30d();
    $report = fetch_system_monthly_report_data($is_local_network, $window_start);

    $network_label = $is_local_network ? 'local' : 'external';
    $safe_date = date('Y-m-d');
    $filename = "monitor-report-{$network_label}-30d-{$safe_date}.pdf";

    $pdf = build_system_report_pdf($report, [
        'title' => 'Reporte general ultimos 30 dias',
    ]);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo $pdf;
    exit;
}

function build_system_report_pdf(array $report, array $meta = [])
{
    $title = (string) ($meta['title'] ?? 'IP Monitor - Reporte');
    $network = (string) ($report['network_label'] ?? '');
    $window = (string) ($report['window_start'] ?? '') . ' -> ' . (string) ($report['window_end'] ?? '');

    $totSamples = (int) ($report['totals']['samples'] ?? 0);
    $totUp = (int) ($report['totals']['up'] ?? 0);
    $totDown = (int) ($report['totals']['down'] ?? 0);
    $totUptime = $totSamples > 0 ? round(($totUp / $totSamples) * 100, 2) : 0;
    $avgLat = $report['totals']['avg_latency_ms'] === null ? null : round((float) $report['totals']['avg_latency_ms'], 2);
    $p95Lat = $report['totals']['p95_latency_ms'] === null ? null : round((float) $report['totals']['p95_latency_ms'], 2);

    // Page setup (A4)
    $pageW = 595;
    $pageH = 842;
    $margin = 40;
    $contentW = $pageW - $margin * 2;

    $days = array_values($report['days'] ?? []);
    $dayCount = count($days);
    if ($dayCount === 0) {
        // still render a basic text-only PDF
        $lines = [
            $title,
            "Red: {$network}",
            "Ventana: {$window}",
            "",
            "No hay datos en los últimos 30 días.",
        ];
        return build_simple_text_pdf($lines, ['title' => $title]);
    }

    $chartX = $margin;
    $chartW = $contentW;
    $dayWidth = $chartW / $dayCount;

    $escape = function ($s) {
        $s = (string) $s;
        $s = preg_replace('/[^\\x09\\x0A\\x0D\\x20-\\x7E]/', '', $s);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    };

    $stream = "";
    // Background
    $stream .= "1 1 1 rg\n";
    $stream .= sprintf("%.2f %.2f %.2f %.2f re f\n", 0, 0, $pageW, $pageH);

    // Header text
    $stream .= "0.08 0.10 0.14 rg\n";
    $stream .= "BT\n/F1 18 Tf\n";
    $stream .= sprintf("1 0 0 1 %d %d Tm (%s) Tj\n", $margin, 800, $escape($title));
    $stream .= "/F1 11 Tf\n";
    $stream .= "ET\n";

    // Summary cards
    $cardY = 720;
    $cardH = 42;
    $gap = 10;
    $cardW = ($contentW - $gap * 2) / 3;

    $drawCard = function ($x, $label, $value, $colorRgb) use (&$stream, $cardY, $cardW, $cardH, $escape) {
        [$r, $g, $b] = $colorRgb;
        $stream .= sprintf("%.3f %.3f %.3f rg\n", $r, $g, $b);
        $stream .= sprintf("%.2f %.2f %.2f %.2f re f\n", $x, $cardY, $cardW, $cardH);
        $stream .= "0.08 0.10 0.14 rg\n";
        $stream .= "BT\n/F1 10 Tf\n";
        $stream .= sprintf("1 0 0 1 %.2f %.2f Tm (%s) Tj\n", $x + 10, $cardY + 26, $escape($label));
        $stream .= "/F1 14 Tf\n";
        $stream .= sprintf("1 0 0 1 %.2f %.2f Tm (%s) Tj\n", $x + 10, $cardY + 8, $escape($value));
        $stream .= "ET\n";
    };

    $drawCard($margin, 'Uptime total', $totUptime . ' %', ($totUptime >= 95 ? [0.78, 0.92, 0.84] : ($totUptime >= 80 ? [0.99, 0.93, 0.76] : [0.98, 0.82, 0.80])));
    $drawCard($margin + $cardW + $gap, 'Muestras (UP/DOWN)', $totSamples . " ({$totUp}/{$totDown})", [0.82, 0.89, 0.98]);
    $drawCard($margin + ($cardW + $gap) * 2, 'Latencia (avg/p95)', (($avgLat === null ? '-' : $avgLat) . ' / ' . ($p95Lat === null ? '-' : $p95Lat) . ' ms'), [0.90, 0.85, 0.97]);

    // Charts title
    $stream .= "0.08 0.10 0.14 rg\nBT\n/F1 12 Tf\n";
    $stream .= sprintf("1 0 0 1 %d %d Tm (%s) Tj\n", $margin, 684, $escape('Graficas diarias (30 dias)'));
    $stream .= "ET\n";

    // Chart 1: Uptime by IP average (color by threshold)
    $uptimeY = 646;
    $chartH = 18;
    $stream .= "0.92 0.95 0.99 rg\n";
    $stream .= sprintf("%.2f %.2f %.2f %.2f re f\n", $chartX, $uptimeY, $chartW, $chartH);
    foreach ($days as $i => $d) {
        $uptime = (float) ($d['uptime_pct'] ?? 0);
        if ($uptime >= 95) {
            $rgb = [0.25, 0.72, 0.44];
        } elseif ($uptime >= 80) {
            $rgb = [0.93, 0.71, 0.24];
        } else {
            $rgb = [0.88, 0.40, 0.38];
        }
        [$r, $g, $b] = $rgb;
        $x = $chartX + ($i * $dayWidth);
        $stream .= sprintf("%.3f %.3f %.3f rg\n%.2f %.2f %.2f %.2f re f\n", $r, $g, $b, $x, $uptimeY, $dayWidth, $chartH);
    }
    $stream .= "0.10 0.12 0.18 rg\nBT\n/F1 9 Tf\n";
    $stream .= sprintf("1 0 0 1 %d %d Tm (%s) Tj\n", $margin, $uptimeY + 22, $escape('Uptime diario (%)'));
    $stream .= "ET\n";

    // Chart 2: Avg latency heat strip (green -> red by latency)
    $latY = 614;
    $stream .= "0.92 0.95 0.99 rg\n";
    $stream .= sprintf("%.2f %.2f %.2f %.2f re f\n", $chartX, $latY, $chartW, $chartH);
    foreach ($days as $i => $d) {
        $lat = $d['avg_latency_ms'];
        $x = $chartX + ($i * $dayWidth);
        if ($lat === null) {
            $rgb = [0.85, 0.88, 0.92];
        } else {
            $lat = (float) $lat;
            $t = min(1, max(0, $lat / 120.0));
            $rgb = [0.30 + (0.58 * $t), 0.78 - (0.35 * $t), 0.42 - (0.12 * $t)];
        }
        [$r, $g, $b] = $rgb;
        $stream .= sprintf("%.3f %.3f %.3f rg\n%.2f %.2f %.2f %.2f re f\n", $r, $g, $b, $x, $latY, $dayWidth, $chartH);
    }
    $stream .= "0.10 0.12 0.18 rg\nBT\n/F1 9 Tf\n";
    $stream .= sprintf("1 0 0 1 %d %d Tm (%s) Tj\n", $margin, $latY + 22, $escape('Latencia media diaria (ms)'));
    $stream .= "ET\n";

    // Chart 3: Samples intensity strip (low -> high)
    $samplesY = 582;
    $maxSamples = 0;
    foreach ($days as $d) {
        $maxSamples = max($maxSamples, (int) ($d['samples'] ?? 0));
    }
    $stream .= "0.92 0.95 0.99 rg\n";
    $stream .= sprintf("%.2f %.2f %.2f %.2f re f\n", $chartX, $samplesY, $chartW, $chartH);
    foreach ($days as $i => $d) {
        $s = (int) ($d['samples'] ?? 0);
        $x = $chartX + ($i * $dayWidth);
        $t = $maxSamples > 0 ? ($s / $maxSamples) : 0;
        $rgb = [0.70 - 0.20 * $t, 0.82 - 0.15 * $t, 0.96 - 0.20 * $t];
        [$r, $g, $b] = $rgb;
        $stream .= sprintf("%.3f %.3f %.3f rg\n%.2f %.2f %.2f %.2f re f\n", $r, $g, $b, $x, $samplesY, $dayWidth, $chartH);
    }
    $stream .= "0.10 0.12 0.18 rg\nBT\n/F1 9 Tf\n";
    $stream .= sprintf("1 0 0 1 %d %d Tm (%s) Tj\n", $margin, $samplesY + 22, $escape('Volumen de muestras diario'));
    $stream .= "ET\n";

    // Legend
    $legendY = 552;
    $legendX = $margin;
    $legendItemW = 12;
    $legendItemH = 10;
    $legendGap = 8;

    $legend = [
        [[0.25, 0.72, 0.44], '>=95% OK'],
        [[0.93, 0.71, 0.24], '80-95% Degradado'],
        [[0.88, 0.40, 0.38], '<80% Incidencia'],
    ];
    foreach ($legend as $idx => $item) {
        [$rgb, $label] = $item;
        [$r, $g, $b] = $rgb;
        $x = $legendX + $idx * 170;
        $stream .= sprintf("%.3f %.3f %.3f rg\n", $r, $g, $b);
        $stream .= sprintf("%.2f %.2f %.2f %.2f re f\n", $x, $legendY, $legendItemW, $legendItemH);
        $stream .= "0.10 0.12 0.18 rg\nBT\n/F1 10 Tf\n";
        $stream .= sprintf("1 0 0 1 %.2f %.2f Tm (%s) Tj\n", $x + $legendItemW + $legendGap, $legendY + 1, $escape($label));
        $stream .= "ET\n";
    }

    // Footnote with first/last day labels
    $firstDay = (string) ($days[0]['day'] ?? '');
    $lastDay = (string) ($days[$dayCount - 1]['day'] ?? '');
    $stream .= "0.10 0.12 0.18 rg\nBT\n/F1 9 Tf\n";
    $stream .= sprintf("1 0 0 1 %d %d Tm (%s) Tj\n", $margin, 534, $escape("Inicio: {$firstDay}"));
    $stream .= sprintf("1 0 0 1 %d %d Tm (%s) Tj\n", $margin + (int) ($chartW - 140), 534, $escape("Fin: {$lastDay}"));
    $stream .= "ET\n";

    // Small table (last 10 days)
    $stream .= "0.08 0.10 0.14 rg\nBT\n/F1 11 Tf\n";
    $stream .= sprintf("1 0 0 1 %d %d Tm (%s) Tj\n", $margin, 506, $escape('Detalle (ultimos 10 dias)'));
    $stream .= "ET\n";

    $tableY = 486;
    $rowH = 14;
    $colXs = [$margin, $margin + 120, $margin + 240, $margin + 360, $margin + 470];
    $headers = ['Fecha', 'Uptime %', 'Muestras', 'Avg ms', 'UP/DOWN'];
    $stream .= "0.10 0.12 0.18 rg\nBT\n/F1 9 Tf\n";
    foreach ($headers as $ci => $h) {
        $stream .= sprintf("1 0 0 1 %d %d Tm (%s) Tj\n", $colXs[$ci], $tableY, $escape($h));
    }
    $stream .= "ET\n";

    $lastDays = array_slice($days, max(0, $dayCount - 10));
    $y = $tableY - $rowH;
    foreach ($lastDays as $d) {
        $day = (string) ($d['day'] ?? '');
        $uptime = round((float) ($d['uptime_pct'] ?? 0), 2);
        $samples = (int) ($d['samples'] ?? 0);
        $avg = ($d['avg_latency_ms'] === null) ? '-' : round((float) $d['avg_latency_ms'], 2);
        $up = (int) ($d['up'] ?? 0);
        $down = (int) ($d['down'] ?? 0);

        $stream .= "0.10 0.12 0.18 rg\nBT\n/F1 9 Tf\n";
        $stream .= sprintf("1 0 0 1 %d %d Tm (%s) Tj\n", $colXs[0], $y, $escape($day));
        $stream .= sprintf("1 0 0 1 %d %d Tm (%s) Tj\n", $colXs[1], $y, $escape((string) $uptime));
        $stream .= sprintf("1 0 0 1 %d %d Tm (%s) Tj\n", $colXs[2], $y, $escape((string) $samples));
        $stream .= sprintf("1 0 0 1 %d %d Tm (%s) Tj\n", $colXs[3], $y, $escape((string) $avg));
        $stream .= sprintf("1 0 0 1 %d %d Tm (%s) Tj\n", $colXs[4], $y, $escape("{$up}/{$down}"));
        $stream .= "ET\n";
        $y -= $rowH;
    }

    return build_vector_pdf_single_page($stream, ['title' => $title]);
}

function build_vector_pdf_single_page($contentStream, array $meta = [])
{
    $title = (string) ($meta['title'] ?? 'Report');
    $escape = function ($s) {
        $s = (string) $s;
        $s = preg_replace('/[^\\x20-\\x7E]/', '', $s);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    };

    $objects = [];
    $offsets = [];
    $out = "%PDF-1.4\n";

    $addObj = function ($content) use (&$objects) {
        $objects[] = $content;
        return count($objects);
    };

    $catalogId = $addObj("<< /Type /Catalog /Pages 2 0 R >>");
    $pagesId = $addObj(""); // placeholder
    $fontId = $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>");

    $content = (string) $contentStream;
    $contentObj = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
    $contentId = $addObj($contentObj);

    $pageObj = "<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 {$fontId} 0 R >> >> /MediaBox [0 0 595 842] /Contents {$contentId} 0 R >>";
    $pageId = $addObj($pageObj);

    $pagesObj = "<< /Type /Pages /Kids [ {$pageId} 0 R ] /Count 1 >>";
    $objects[$pagesId - 1] = $pagesObj;

    $infoId = $addObj("<< /Title (" . $escape($title) . ") /Producer (IP Monitor) >>");

    $offsets[0] = 0;
    foreach ($objects as $i => $obj) {
        $objNum = $i + 1;
        $offsets[$objNum] = strlen($out);
        $out .= $objNum . " 0 obj\n" . $obj . "\nendobj\n";
    }

    $xrefPos = strlen($out);
    $out .= "xref\n0 " . (count($objects) + 1) . "\n";
    $out .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $out .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root {$catalogId} 0 R /Info {$infoId} 0 R >>\n";
    $out .= "startxref\n{$xrefPos}\n%%EOF";

    return $out;
}

function build_simple_text_pdf(array $lines, array $meta = [])
{
    // Very small PDF generator (Helvetica) for ASCII-ish reports.
    // If line contains non-ASCII chars, they are stripped (PDF core fonts are WinAnsi).
    $title = (string) ($meta['title'] ?? 'Report');

    $maxLinesPerPage = 52;
    $pages = array_chunk($lines, $maxLinesPerPage);

    $objects = [];
    $offsets = [];
    $out = "%PDF-1.4\n";

    $addObj = function ($content) use (&$objects) {
        $objects[] = $content;
        return count($objects);
    };

    // 1: Catalog (filled later)
    // 2: Pages
    // 3: Font
    $catalogId = $addObj("<< /Type /Catalog /Pages 2 0 R >>");
    $pagesId = $addObj(""); // placeholder
    $fontId = $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>");

    $pageIds = [];
    $contentIds = [];

    foreach ($pages as $pageIndex => $pageLines) {
        $yStart = 800;
        $lineHeight = 14;
        $x = 40;

        $stream = "BT\n/F1 10 Tf\n";
        $y = $yStart;
        foreach ($pageLines as $line) {
            $line = (string) $line;
            $line = preg_replace('/[^\\x09\\x0A\\x0D\\x20-\\x7E]/', '', $line);
            $line = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $stream .= sprintf("1 0 0 1 %d %d Tm (%s) Tj\n", $x, $y, $line);
            $y -= $lineHeight;
        }
        $stream .= "ET\n";

        $content = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        $contentId = $addObj($content);
        $contentIds[] = $contentId;

        $pageObj = "<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 {$fontId} 0 R >> >> /MediaBox [0 0 595 842] /Contents {$contentId} 0 R >>";
        $pageId = $addObj($pageObj);
        $pageIds[] = $pageId;
    }

    $kids = implode(' ', array_map(fn($id) => "{$id} 0 R", $pageIds));
    $pagesObj = "<< /Type /Pages /Kids [ {$kids} ] /Count " . count($pageIds) . " >>";
    $objects[$pagesId - 1] = $pagesObj;

    $infoId = $addObj("<< /Title (" . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], preg_replace('/[^\\x20-\\x7E]/', '', $title)) . ") /Producer (IP Monitor) >>");

    // Write objects with xref
    $offsets[0] = 0;
    foreach ($objects as $i => $obj) {
        $objNum = $i + 1;
        $offsets[$objNum] = strlen($out);
        $out .= $objNum . " 0 obj\n" . $obj . "\nendobj\n";
    }

    $xrefPos = strlen($out);
    $out .= "xref\n0 " . (count($objects) + 1) . "\n";
    $out .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $out .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root {$catalogId} 0 R /Info {$infoId} 0 R >>\n";
    $out .= "startxref\n{$xrefPos}\n%%EOF";

    return $out;
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

function import_monitor_config_ini($ini_path)
{
    global $db;

    $config_ini = @parse_ini_file($ini_path, true);
    if (!is_array($config_ini)) {
        return ['success' => false, 'message' => 'El archivo INI no es válido.'];
    }

    if (!($db instanceof PDO)) {
        return ['success' => false, 'message' => 'No hay conexión activa con la base de datos.'];
    }

    $local_count = 0;
    $external_count = 0;
    $skipped_count = 0;

    try {
        $db->beginTransaction();

        $general_sections = ['settings', 'telegram', 'security', 'ai'];
        $stmt_setting = $db->prepare("INSERT OR REPLACE INTO settings (section, key, value) VALUES (?, ?, ?)");
        foreach ($general_sections as $section) {
            if (!isset($config_ini[$section]) || !is_array($config_ini[$section])) {
                continue;
            }
            foreach ($config_ini[$section] as $key => $val) {
                $stmt_setting->execute([$section, $key, (string) $val]);
            }
        }

        $services_colors = $config_ini['services-colors'] ?? [];
        $services_methods = $config_ini['services-methods'] ?? [];
        $all_service_names = array_unique(array_merge(array_keys($services_colors), array_keys($services_methods)));
        if (!empty($all_service_names)) {
            $stmt_service = $db->prepare("INSERT OR REPLACE INTO services (name, method, color) VALUES (?, ?, ?)");
            foreach ($all_service_names as $name) {
                $color = $services_colors[$name] ?? '#6b7280';
                $method = $services_methods[$name] ?? 'icmp';
                $stmt_service->execute([$name, $method, $color]);
            }
        }

        $stmt_select_device = $db->prepare("SELECT id FROM devices WHERE ip = ?");
        $stmt_insert_device = $db->prepare("INSERT INTO devices (ip, host, type, network, is_local) VALUES (?, ?, ?, ?, ?)");

        if (isset($config_ini['ips-host']) && is_array($config_ini['ips-host'])) {
            foreach ($config_ini['ips-host'] as $ip => $host) {
                $type = $config_ini['ips-type'][$ip] ?? 'other';
                $network = $config_ini['ips-network'][$ip] ?? 'Ethernet';
                $stmt_select_device->execute([$ip]);
                $exists = $stmt_select_device->fetchColumn();
                if ($exists !== false) {
                    $skipped_count++;
                } else {
                    $stmt_insert_device->execute([$ip, $host, $type, $network, 1]);
                    $local_count++;
                }
            }
        }

        if (isset($config_ini['ips-services']) && is_array($config_ini['ips-services'])) {
            foreach ($config_ini['ips-services'] as $ip => $host) {
                $type = $config_ini['ips-type'][$ip] ?? 'other';
                $network = $config_ini['ips-network'][$ip] ?? 'Ethernet';
                $stmt_select_device->execute([$ip]);
                $exists = $stmt_select_device->fetchColumn();
                if ($exists !== false) {
                    $skipped_count++;
                } else {
                    $stmt_insert_device->execute([$ip, $host, $type, $network, 0]);
                    $external_count++;
                }
            }
        }

        $db->commit();
        $total = $local_count + $external_count;
        return ['success' => true, 'type' => 'ini', 'message' => "Configuración importada sin borrar datos previos: $total IPs nuevas ($local_count locales, $external_count externas), $skipped_count ya existentes."];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Failed to import config.ini into SQLite: " . $e->getMessage());
        return ['success' => false, 'message' => 'No se pudo importar el archivo config.ini.'];
    }
}

// Importa monitor.db o config.ini
function import_monitor_db($uploaded_file)
{
    $tmp_path = is_array($uploaded_file) ? ($uploaded_file['tmp_name'] ?? '') : $uploaded_file;
    $original_name = is_array($uploaded_file) ? ($uploaded_file['name'] ?? '') : '';
    $destination = get_monitor_db_path();
    $db_dir = dirname($destination);

    if (!$tmp_path || !is_uploaded_file($tmp_path)) {
        return ['success' => false, 'message' => 'No se recibió ningún archivo válido.'];
    }

    $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    if ($extension === 'ini') {
        return import_monitor_config_ini($tmp_path);
    }

    if ($extension !== 'db') {
        return ['success' => false, 'message' => 'Formato no soportado. Usa monitor.db o config.ini.'];
    }

    if (!is_dir($db_dir)) {
        if (!mkdir($db_dir, 0775, true) && !is_dir($db_dir)) {
            return ['success' => false, 'message' => 'No se pudo crear el directorio de la base de datos.'];
        }
    }

    if (!move_uploaded_file($tmp_path, $destination)) {
        return ['success' => false, 'message' => 'No se pudo reemplazar monitor.db.'];
    }

    if (!file_exists($destination) || filesize($destination) === 0) {
        return ['success' => false, 'message' => 'El archivo monitor.db importado es inválido o está vacío.'];
    }

    return ['success' => true, 'type' => 'db', 'message' => 'Base de datos importada correctamente.'];
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
        $general_sections = ['settings', 'telegram', 'security', 'ai'];
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
        // Keep intruders discovered at runtime (type = 'intruder')
        if (!empty($current_ips)) {
            $placeholders = implode(',', array_fill(0, count($current_ips), '?'));
            $stmt_delete = $db->prepare("DELETE FROM devices WHERE is_local = ? AND type != 'intruder' AND ip NOT IN ($placeholders)");
            $stmt_delete->execute(array_merge([$is_local_network ? 1 : 0], $current_ips));
        } else {
            $stmt_delete = $db->prepare("DELETE FROM devices WHERE is_local = ? AND type != 'intruder'");
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
        'notify_on_intruder' => $telegram['notify_on_intruder'] ? 'true' : 'false',
        'latency_threshold' => (string) $telegram['latency_threshold'],
        'message_template' => $telegram['message_template'],
    ];

    $ai = get_ai_config($config);
    $config['ai'] = [
        'provider' => $ai['provider'],
        'base_url' => $ai['base_url'],
        'gpt_path' => $ai['gpt_path'],
    ];

    return $config;
}

function get_ai_config($config)
{
    $defaults = [
        'provider' => 'chatgpt',
        'base_url' => 'https://chatgpt.com',
        'gpt_path' => '',
    ];

    $ai = array_merge($defaults, $config['ai'] ?? []);
    $provider = trim((string) $ai['provider']);
    $base_url = trim((string) $ai['base_url']);
    $gpt_path = trim((string) $ai['gpt_path']);

    if ($provider === '') {
        $provider = $defaults['provider'];
    }

    if ($base_url === '') {
        $base_url = $defaults['base_url'];
    }

    return [
        'provider' => $provider,
        'base_url' => rtrim($base_url, '/'),
        'gpt_path' => $gpt_path,
    ];
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
        'notify_on_intruder' => 'true',
        'latency_threshold' => '100',
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
        'notify_on_intruder' => filter_var($telegram['notify_on_intruder'], FILTER_VALIDATE_BOOLEAN),
        'latency_threshold' => max(1, (int) $telegram['latency_threshold']),
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
        // In PHP 8+, CurlHandle is an object; releasing the reference closes the handle.
        // Avoid calling curl_close() to silence deprecation warnings reported by some PHP 8.5 stubs/linters.
        $ch = null;

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
            $latency_events[] = "• {$display_name} → {$event['response_time']}";
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
        $local_ip = trim(shell_exec("hostname -I 2>/dev/null | awk '{print $1}'"));
        if (empty($local_ip))
            $local_ip = '192.168.1.1';
        $parts = explode('.', $local_ip);
        $network_prefix = implode('.', array_slice($parts, 0, 3));
    }

    $discovered_devices = [];
    $ips_seen = [];

    // Escaneo con nmap en Windows y Linux
    $nmap_output = shell_exec("nmap -sn " . $network_prefix . ".1-254 2>&1");
    if (
        $nmap_output === null ||
        strpos($nmap_output, 'Failed') !== false ||
        strpos($nmap_output, 'command not found') !== false ||
        strpos($nmap_output, 'Operation not permitted') !== false
    ) {
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
        $route_output = shell_exec('ip route 2>/dev/null | grep default');
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
 * Scan local network without echoing UI notifications.
 * Returns [] on failure and logs the error instead.
 */
function scan_local_network_silent()
{
    ob_start();
    try {
        $devices = scan_local_network();
        $output = (string) ob_get_clean();
        if ($output !== '') {
            error_log('scan_local_network_silent(): suppressed output from scan_local_network().');
        }
        return is_array($devices) ? $devices : [];
    } catch (Throwable $e) {
        ob_end_clean();
        error_log('scan_local_network_silent(): exception: ' . $e->getMessage());
        return [];
    }
}

function is_gateway_or_self_device(array $device)
{
    $mac = strtoupper(trim((string)($device['mac'] ?? '')));
    if ($mac === 'SELF' || $mac === 'SELF/GATEWAY') {
        return true;
    }
    if ($mac === 'GATEWAY') {
        return true;
    }

    $type = strtolower(trim((string)($device['type'] ?? '')));
    if ($type === 'gateway') {
        return true;
    }

    $hostname = strtolower(trim((string)($device['hostname'] ?? '')));
    if ($hostname === 'gateway' || $hostname === '_gateway') {
        return true;
    }

    return false;
}

/**
 * Returns discovered devices not present in SQLite `devices` (is_local=1),
 * excluding gateway and self.
 */
function detect_unknown_local_devices()
{
    global $db;

    $discovered = scan_local_network_silent();
    if (empty($discovered)) {
        return [];
    }

    $known_ips = [];
    try {
        $stmt = $db->prepare("SELECT ip FROM devices WHERE is_local = 1");
        $stmt->execute();
        $known_ips = $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    } catch (PDOException $e) {
        error_log('detect_unknown_local_devices(): failed to load known devices: ' . $e->getMessage());
        $known_ips = [];
    }
    $known_lookup = array_fill_keys(array_map('strval', $known_ips), true);

    $unknown = [];
    foreach ($discovered as $device) {
        if (!is_array($device)) {
            continue;
        }
        $ip = trim((string)($device['ip'] ?? ''));
        if ($ip === '') {
            continue;
        }
        if (isset($known_lookup[$ip])) {
            continue;
        }
        if (is_gateway_or_self_device($device)) {
            continue;
        }
        $unknown[] = $device;
    }

    return $unknown;
}

function record_intruders_in_devices(array $unknown_devices)
{
    if (empty($unknown_devices)) {
        return 0;
    }

    global $db;
    $inserted_or_updated = 0;

    try {
        $stmt_check = $db->prepare("SELECT id FROM devices WHERE ip = ? LIMIT 1");
        $stmt_insert = $db->prepare("INSERT INTO devices (ip, host, type, network, is_local) VALUES (?, ?, ?, ?, 1)");
        $stmt_update = $db->prepare("UPDATE devices SET host = ?, type = ? WHERE ip = ?");

        foreach ($unknown_devices as $device) {
            if (!is_array($device)) {
                continue;
            }
            $ip = trim((string)($device['ip'] ?? ''));
            if ($ip === '') {
                continue;
            }
            if (is_gateway_or_self_device($device)) {
                continue;
            }

            $hostname = trim((string)($device['hostname'] ?? ''));
            $host = ($hostname !== '' && strtolower($hostname) !== 'unknown') ? $hostname : 'intruder';
            $type = 'intruder';
            $network = 'Ethernet';

            $stmt_check->execute([$ip]);
            $id = $stmt_check->fetchColumn();
            if ($id) {
                $stmt_update->execute([$host, $type, $ip]);
                $inserted_or_updated++;
                continue;
            }

            $stmt_insert->execute([$ip, $host, $type, $network]);
            $inserted_or_updated++;
        }
    } catch (PDOException $e) {
        error_log('record_intruders_in_devices(): failed: ' . $e->getMessage());
    }

    return $inserted_or_updated;
}

function has_intruder_alert_been_sent_for_ip($ip)
{
    global $db;
    try {
        $stmt = $db->prepare("SELECT 1 FROM telegram_alerts WHERE service = 'INTRUDER' AND ip = ? LIMIT 1");
        $stmt->execute([(string)$ip]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('has_intruder_alert_been_sent_for_ip(): query failed: ' . $e->getMessage());
        return false;
    }
}

function format_intruder_telegram_message(array $device)
{
    $ip = trim((string)($device['ip'] ?? ''));
    $hostname = trim((string)($device['hostname'] ?? ''));
    $mac = trim((string)($device['mac'] ?? ''));

    $lines = ['Nuevo dispositivo desconocido conectado a tu red'];
    if ($ip !== '') {
        $lines[] = 'IP: ' . $ip;
    }
    if ($hostname !== '' && strtolower($hostname) !== 'unknown') {
        $lines[] = 'Host: ' . $hostname;
    }
    if ($mac !== '' && strtoupper($mac) !== 'UNKNOWN') {
        $lines[] = 'MAC: ' . $mac;
    }
    return implode("\n", $lines);
}

function notify_intruders_via_telegram(array $unknown_devices, array $telegram_cfg)
{
    if (empty($unknown_devices)) {
        return;
    }
    if (!($telegram_cfg['enabled'] ?? false)) {
        return;
    }
    if (empty($telegram_cfg['notify_on_intruder'])) {
        return;
    }

    $to_notify = [];
    foreach ($unknown_devices as $device) {
        $ip = trim((string)($device['ip'] ?? ''));
        if ($ip === '') {
            continue;
        }
        if (has_intruder_alert_been_sent_for_ip($ip)) {
            continue;
        }
        $to_notify[] = $device;
    }

    if (empty($to_notify)) {
        return;
    }

    $lines = ['Nuevo dispositivo desconocido conectado a tu red'];
    foreach ($to_notify as $device) {
        $ip = trim((string)($device['ip'] ?? ''));
        $hostname = trim((string)($device['hostname'] ?? ''));
        $mac = trim((string)($device['mac'] ?? ''));

        $parts = [];
        if ($ip !== '') {
            $parts[] = $ip;
        }
        if ($hostname !== '' && strtolower($hostname) !== 'unknown') {
            $parts[] = $hostname;
        }
        if ($mac !== '' && strtoupper($mac) !== 'UNKNOWN') {
            $parts[] = $mac;
        }
        $lines[] = '- ' . implode(' | ', $parts);
    }
    $message = implode("\n", $lines);

    if (!send_telegram_message($message, $telegram_cfg)) {
        return;
    }

    foreach ($to_notify as $device) {
        $ip = trim((string)($device['ip'] ?? ''));
        if ($ip === '') {
            continue;
        }
        record_telegram_alert($ip, 'UNKNOWN', 'UNKNOWN', 'INTRUDER', $message);
    }
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
 * Return configured platform endpoints for the selected gaming region.
 */
function get_gaming_latency_catalog($region = 'europe')
{
    global $db;
    $regions = [
        'europe' => ['label' => 'Europa', 'column' => 'target_europe'],
        'north_america' => ['label' => 'Norteamérica', 'column' => 'target_north_america'],
        'asia_pacific' => ['label' => 'Asia-Pacífico', 'column' => 'target_asia_pacific'],
    ];
    if (!isset($regions[$region])) {
        throw new InvalidArgumentException('Región de juego no válida.');
    }

    $column = $regions[$region]['column'];
    $games = $db->query("SELECT id, slug, name, platform, $column AS target FROM gaming_games ORDER BY name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static function ($game) use ($regions, $region) {
        return [
            'id' => $game['slug'],
            'database_id' => (int) $game['id'],
            'name' => $game['name'],
            'platform' => $game['platform'],
            'region' => $regions[$region]['label'],
            'target' => $game['target'],
        ];
    }, $games);
}

function get_gaming_games()
{
    global $db;
    return $db->query('SELECT id, slug, name, platform, target_europe, target_north_america, target_asia_pacific FROM gaming_games ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
}

function validate_catalog_target($target)
{
    $target = strtolower(trim((string) $target));
    $is_ip = filter_var($target, FILTER_VALIDATE_IP) !== false;
    $is_host = preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $target) === 1;
    if (!$is_ip && !$is_host) {
        throw new InvalidArgumentException('El destino debe ser una IP o un dominio válido.');
    }
    return $target;
}

function add_gaming_game(array $data)
{
    global $db;
    $name = trim((string) ($data['name'] ?? ''));
    $platform = trim((string) ($data['platform'] ?? ''));
    if ($name === '' || strlen($name) > 80 || strlen($platform) > 80) {
        throw new InvalidArgumentException('El nombre y la plataforma no son válidos.');
    }

    $slug = trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name)), '-');
    $slug = $slug !== '' ? $slug : 'game';
    $base_slug = $slug;
    $suffix = 2;
    $slug_exists = $db->prepare('SELECT 1 FROM gaming_games WHERE slug = ?');
    while (true) {
        $slug_exists->execute([$slug]);
        if (!$slug_exists->fetchColumn()) {
            break;
        }
        $slug = $base_slug . '-' . $suffix++;
    }

    $targets = [
        validate_catalog_target($data['target_europe'] ?? ''),
        validate_catalog_target($data['target_north_america'] ?? ''),
        validate_catalog_target($data['target_asia_pacific'] ?? ''),
    ];
    $insert = $db->prepare('INSERT INTO gaming_games (slug, name, platform, target_europe, target_north_america, target_asia_pacific) VALUES (?, ?, ?, ?, ?, ?)');
    $insert->execute([$slug, $name, $platform, ...$targets]);
    return ['id' => (int) $db->lastInsertId(), 'slug' => $slug];
}

function delete_gaming_game($id)
{
    global $db;
    $delete = $db->prepare('DELETE FROM gaming_games WHERE id = ?');
    $delete->execute([(int) $id]);
    return $delete->rowCount() > 0;
}

/**
 * Parse a five-packet ping output into latency metrics for one game.
 */
function parse_gaming_latency_result($output, array $game)
{
    preg_match_all('/(?:time|tiempo)[=<]\\s*([\\d.,]+)\\s*ms/i', $output, $matches);
    $samples = array_map(
        static fn($value) => (float) str_replace(',', '.', $value),
        $matches[1] ?? []
    );

    $loss = 100;
    if (preg_match('/(\\d+(?:[.,]\\d+)?)%\\s*(?:packet\\s+loss|loss|de\\s+pérdida)/iu', $output, $loss_match)) {
        $loss = (float) str_replace(',', '.', $loss_match[1]);
    } elseif ($samples) {
        $loss = round((1 - (count($samples) / 5)) * 100, 2);
    }

    $result = [
        'id' => $game['id'],
        'name' => $game['name'],
        'platform' => $game['platform'],
        'region' => $game['region'],
        'available' => count($samples) > 0,
        'samples' => count($samples),
        'packet_loss' => $loss,
        'average' => null,
        'minimum' => null,
        'maximum' => null,
        'jitter' => null,
    ];

    if (!$samples) {
        return $result;
    }

    $differences = [];
    for ($index = 1, $total = count($samples); $index < $total; $index++) {
        $differences[] = abs($samples[$index] - $samples[$index - 1]);
    }

    $result['average'] = round(array_sum($samples) / count($samples), 2);
    $result['minimum'] = round(min($samples), 2);
    $result['maximum'] = round(max($samples), 2);
    $result['jitter'] = $differences ? round(array_sum($differences) / count($differences), 2) : 0.0;

    return $result;
}

/**
 * Measure every catalogued endpoint concurrently so the UI is not held up by
 * one unavailable platform.
 */
function run_gaming_latency_test($region = 'europe')
{
    $is_windows = PHP_OS_FAMILY === 'Windows';
    $processes = [];

    foreach (get_gaming_latency_catalog($region) as $game) {
        $target = escapeshellarg($game['target']);
        $command = $is_windows
            ? "ping -n 5 -w 1000 $target"
            : (is_running_in_container() ? 'sudo ' : '') . "/bin/ping -n -c 5 -W 1 $target";
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (is_resource($process)) {
            fclose($pipes[0]);
            $processes[] = ['game' => $game, 'process' => $process, 'pipes' => $pipes];
        } else {
            $processes[] = ['game' => $game, 'process' => null, 'pipes' => null];
        }
    }

    $results = [];
    foreach ($processes as $entry) {
        if (!is_resource($entry['process'])) {
            $results[] = parse_gaming_latency_result('', $entry['game']);
            continue;
        }

        $output = stream_get_contents($entry['pipes'][1]);
        $error_output = stream_get_contents($entry['pipes'][2]);
        fclose($entry['pipes'][1]);
        fclose($entry['pipes'][2]);
        proc_close($entry['process']);
        $results[] = parse_gaming_latency_result($output . "\n" . $error_output, $entry['game']);
    }

    return ['success' => true, 'region' => $results[0]['region'] ?? null, 'results' => $results];
}

/**
 * Return the editable DNS resolver catalog.
 */
function get_dns_benchmark_catalog()
{
    global $db;
    return $db->query('SELECT id, name, ip FROM dns_resolvers ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
}

function add_dns_resolver(array $data)
{
    global $db;
    $name = trim((string) ($data['name'] ?? ''));
    $ip = trim((string) ($data['ip'] ?? ''));
    if ($name === '' || strlen($name) > 80 || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        throw new InvalidArgumentException('El nombre o la IP IPv4 del DNS no son válidos.');
    }

    $insert = $db->prepare('INSERT INTO dns_resolvers (name, ip) VALUES (?, ?)');
    $insert->execute([$name, $ip]);
    return ['id' => (int) $db->lastInsertId()];
}

function delete_dns_resolver($id)
{
    global $db;
    $delete = $db->prepare('DELETE FROM dns_resolvers WHERE id = ?');
    $delete->execute([(int) $id]);
    return $delete->rowCount() > 0;
}

/**
 * Extract benchmark metrics from dig or Windows PowerShell DNS output.
 */
function parse_dns_benchmark_result($output, array $resolver)
{
    preg_match_all('/Query time:\s*([\d.,]+)\s*msec/i', $output, $dig_matches);
    preg_match_all('/QUERY_TIME_MS:([\d.,]+)/i', $output, $powershell_matches);
    $values = array_merge($dig_matches[1] ?? [], $powershell_matches[1] ?? []);
    $samples = array_map(
        static fn($value) => (float) str_replace(',', '.', $value),
        $values
    );

    $result = [
        'id' => $resolver['id'],
        'name' => $resolver['name'],
        'ip' => $resolver['ip'],
        'available' => count($samples) > 0,
        'samples' => count($samples),
        'failure_rate' => round((1 - (count($samples) / 5)) * 100, 2),
        'average' => null,
        'minimum' => null,
        'maximum' => null,
        'jitter' => null,
    ];

    if (!$samples) {
        return $result;
    }

    $differences = [];
    for ($index = 1, $total = count($samples); $index < $total; $index++) {
        $differences[] = abs($samples[$index] - $samples[$index - 1]);
    }

    $result['average'] = round(array_sum($samples) / count($samples), 2);
    $result['minimum'] = round(min($samples), 2);
    $result['maximum'] = round(max($samples), 2);
    $result['jitter'] = $differences ? round(array_sum($differences) / count($differences), 2) : 0.0;

    return $result;
}

/**
 * Run five direct A-record lookups for every catalogued resolver in parallel.
 */
function run_dns_benchmark()
{
    $is_windows = PHP_OS_FAMILY === 'Windows';
    $processes = [];

    foreach (get_dns_benchmark_catalog() as $resolver) {
        if ($is_windows) {
            $script = '$ErrorActionPreference = \'Stop\'; 1..5 | ForEach-Object { $timer = [System.Diagnostics.Stopwatch]::StartNew(); try { Resolve-DnsName -Name example.com -Type A -Server ' . $resolver['ip'] . ' -DnsOnly | Out-Null; $timer.Stop(); Write-Output (\'QUERY_TIME_MS:\' + [math]::Round($timer.Elapsed.TotalMilliseconds, 2)) } catch { Write-Output \'QUERY_FAILED\' } }';
            $command = 'powershell -NoProfile -Command ' . escapeshellarg($script);
        } else {
            $server = escapeshellarg('@' . $resolver['ip']);
            $command = "for attempt in 1 2 3 4 5; do dig +time=2 +tries=1 +stats $server example.com A; done";
        }

        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (is_resource($process)) {
            fclose($pipes[0]);
            $processes[] = ['resolver' => $resolver, 'process' => $process, 'pipes' => $pipes];
        } else {
            $processes[] = ['resolver' => $resolver, 'process' => null, 'pipes' => null];
        }
    }

    $results = [];
    foreach ($processes as $entry) {
        if (!is_resource($entry['process'])) {
            $results[] = parse_dns_benchmark_result('', $entry['resolver']);
            continue;
        }

        $output = stream_get_contents($entry['pipes'][1]);
        $error_output = stream_get_contents($entry['pipes'][2]);
        fclose($entry['pipes'][1]);
        fclose($entry['pipes'][2]);
        proc_close($entry['process']);
        $results[] = parse_dns_benchmark_result($output . "\n" . $error_output, $entry['resolver']);
    }

    return ['success' => true, 'query' => 'example.com', 'results' => $results];
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
    // The IP is already validated as IPv4/IPv6, so we can safely pass it directly on Windows.
    // cmd.exe quoting rules are different and shell escaping may break built-in commands there.
    $command_ip = $is_windows ? $ip : escapeshellarg($ip);
    // Keep this endpoint fast for UI requests.
    $count = $is_windows ? 3 : 5;
    $errors = [];

    if ($is_windows) {
        // -w is per-echo timeout (ms). Lower value to avoid UI request timeouts on Windows.
        $ping_command = "ping -n $count -w 400 $command_ip";
    } else {
        $sudo_prefix = is_running_in_container() ? 'sudo ' : '';
        $ping_command = $sudo_prefix . "/bin/ping -c $count -W 1 $command_ip 2>&1";
    }

    $ping_output = shell_exec($ping_command) ?? '';
    if ($is_windows && $ping_output !== '') {
        $converted = @iconv('CP850', 'UTF-8//IGNORE', $ping_output);
        if ($converted !== false && $converted !== '') {
            $ping_output = $converted;
        } else {
            $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $ping_output);
            if ($converted !== false && $converted !== '') {
                $ping_output = $converted;
            }
        }
    }
    if (trim($ping_output) === '') {
        $errors[] = 'ping command returned no output';
    } elseif ($is_windows && (stripos($ping_output, 'no se reconoce como un comando') !== false || stripos($ping_output, 'not recognized as an internal or external command') !== false)) {
        $errors[] = 'ping command not available';
    } elseif (!$is_windows && (stripos($ping_output, 'not found') !== false || stripos($ping_output, 'permission denied') !== false || stripos($ping_output, 'operation not permitted') !== false)) {
        $errors[] = 'ping command failed (permission or missing binary)';
    }
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

    // Reverse DNS can block for several seconds on Windows; avoid stalling the diagnostics request.
    $hostname = null;
    if (!$is_windows) {
        $hostname = gethostbyaddr($ip);
        if ($hostname === $ip) {
            $hostname = null;
        }
    }

    $arp_output = shell_exec(($is_windows ? 'arp -a ' : 'arp -n ') . $command_ip . ' 2>&1') ?? '';
    if ($is_windows && $arp_output !== '') {
        $converted = @iconv('CP850', 'UTF-8//IGNORE', $arp_output);
        if ($converted !== false && $converted !== '') {
            $arp_output = $converted;
        } else {
            $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $arp_output);
            if ($converted !== false && $converted !== '') {
                $arp_output = $converted;
            }
        }
    }
    if (trim($arp_output) === '') {
        // On Windows this is common when ARP cache has no entry for the host yet.
        // Keep diagnostics successful and just return null MAC.
        if (!$is_windows) {
            $errors[] = 'arp command returned no output';
        }
    } elseif ($is_windows && (stripos($arp_output, 'no se reconoce como un comando') !== false || stripos($arp_output, 'not recognized as an internal or external command') !== false)) {
        $errors[] = 'arp command not available';
    } elseif (!$is_windows && (stripos($arp_output, 'permission denied') !== false || stripos($arp_output, 'operation not permitted') !== false)) {
        $errors[] = 'arp command failed (permission)';
    }
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
        // Some PHP environments still surface fsockopen() warnings (e.g. when an IDE/debugger traps them)
        // even when using @. Swallow them explicitly to avoid breaking the diagnostics UI.
        $prevHandler = set_error_handler(static function () {
            return true;
        });
        try {
        $conn = fsockopen($ip, $port, $errno, $errstr, $is_windows ? 0.12 : 0.2);
        } finally {
            restore_error_handler();
            if ($prevHandler !== null) {
                // restore_error_handler() restores the previous handler already; keep symmetry if null.
            }
        }
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

    if (!empty($errors)) {
        return [
            'status' => 'fail',
            'message' => 'Local diagnostics failed to run one or more commands',
            'errors' => $errors,
            'ip' => $ip,
            'hostname' => $hostname,
            'mac' => $mac,
            'ports' => $port_results,
            'raw_ping' => $ping_output,
            'raw_arp' => $arp_output,
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
