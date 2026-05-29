<?php

class requestHandler
{
    private bool $isLocalNetwork;
    private string $networkParam;
    private string $configPath;
    private bool $loginEnabled;

    public function __construct(bool $isLocalNetwork, string $networkParam, string $configPath, bool $loginEnabled)
    {
        $this->isLocalNetwork = $isLocalNetwork;
        $this->networkParam = $networkParam;
        $this->configPath = $configPath;
        $this->loginEnabled = $loginEnabled;
    }

    public function handle(): bool
    {
        if (isset($_GET['action']) && $this->handleAction((string) $_GET['action'])) {
            return true;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $this->handlePost()) {
            return true;
        }

        return false;
    }

    private function handleAction(string $action): bool
    {
        if ($action === 'scan_network' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $devices = scan_local_network();
                $this->jsonResponse([
                    'success' => true,
                    'devices' => $devices,
                    'count' => count($devices),
                ]);
            } catch (Exception $e) {
                $this->jsonResponse(['success' => false, 'message' => $e->getMessage()]);
            }
            return true;
        }

        if ($action === 'save_scanned_devices' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $json = file_get_contents('php://input');
                $data = json_decode($json, true);

                if (!isset($data['devices']) || !is_array($data['devices'])) {
                    throw new Exception('Invalid devices data');
                }

                $result = save_local_network_scan($data['devices']);
                $this->jsonResponse([
                    'success' => $result,
                    'message' => $result ? 'Devices saved successfully' : 'Failed to save devices',
                ]);
            } catch (Exception $e) {
                $this->jsonResponse(['success' => false, 'message' => $e->getMessage()]);
            }
            return true;
        }

        if ($action === 'speed_test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $results = run_complete_speedtest();
                $this->jsonResponse($results);
            } catch (Exception $e) {
                $this->jsonResponse(['success' => false, 'message' => $e->getMessage()]);
            }
            return true;
        }

        if ($action === 'speed_test_history' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            global $db;
            $history = [];
            try {
                $stmt = $db->query("SELECT timestamp, latency, download, upload, jitter, packet_loss FROM speedtest_results ORDER BY timestamp DESC LIMIT 5");
                $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log('Failed to load speedtest history from SQLite: ' . $e->getMessage());
            }

            $this->jsonResponse($history ?: []);
            return true;
        }

        if ($action === 'clear_speed_test_history' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                global $db;
                $db->exec('DELETE FROM speedtest_results');
                $this->jsonResponse(['success' => true]);
            } catch (Throwable $e) {
                $this->jsonResponse(['success' => false, 'message' => $e->getMessage()]);
            }
            return true;
        }

        if ($action === 'diagnose' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleDiagnose();
            return true;
        }

        if ($action === 'get_public_ip' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            try {
                $ctx = stream_context_create(['http' => ['timeout' => 5]]);
                $json = @file_get_contents('http://ip-api.com/json/?fields=status,message,country,countryCode,regionName,city,zip,lat,lon,timezone,isp,org,as,query', false, $ctx);

                if ($json === false) {
                    throw new Exception('Failed to contact IP API');
                }

                $data = json_decode($json, true);
                if ($data['status'] !== 'success') {
                    throw new Exception('External API Error: ' . ($data['message'] ?? 'Unknown'));
                }

                $this->jsonResponse(['success' => true, 'result' => $data]);
            } catch (Exception $e) {
                $this->jsonResponse(['success' => false, 'message' => $e->getMessage()]);
            }
            return true;
        }

        if ($action === 'check_cgnat' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            try {
                $json = run_traceroute('1.1.1.1', 3);
                $data = json_decode($json, true);
                $cgnat_detected = false;
                $cgnat_hop = null;

                if ($data && $data['success'] && !empty($data['hops'])) {
                    foreach ($data['hops'] as $hop) {
                        $ip = $hop['ip'];
                        if ($ip) {
                            $parts = explode('.', $ip);
                            if (count($parts) === 4 && $parts[0] == 100 && $parts[1] >= 64 && $parts[1] <= 127) {
                                $cgnat_detected = true;
                                $cgnat_hop = $ip;
                                break;
                            }
                        }
                    }
                }
                $this->jsonResponse(['success' => true, 'is_cgnat' => $cgnat_detected, 'hop' => $cgnat_hop]);
            } catch (Exception $e) {
                $this->jsonResponse(['success' => false, 'message' => $e->getMessage()]);
            }
            return true;
        }

        if ($action === 'get_topology_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            try {
                $ctx = stream_context_create(['http' => ['timeout' => 3]]);
                $public_json = @file_get_contents('http://ip-api.com/json/?fields=query,isp', false, $ctx);
                $public_data = json_decode($public_json, true);
                $public_ip = $public_data['query'] ?? 'Unknown';

                $isWindows = (PHP_OS_FAMILY === 'Windows');
                $gateway_ip = '';
                if ($isWindows) {
                    $route_output = @shell_exec('route print 0.0.0.0');
                    if (preg_match('/0\.0\.0\.0\s+0\.0\.0\.0\s+(\d+\.\d+\.\d+\.\d+)/', $route_output, $matches)) {
                        $gateway_ip = $matches[1];
                    }
                } else {
                    $route_output = @shell_exec('ip route | grep default');
                    if (preg_match('/via (\d+\.\d+\.\d+\.\d+)/', $route_output, $matches)) {
                        $gateway_ip = $matches[1];
                    }
                }
                if (empty($gateway_ip)) {
                    $gateway_ip = '192.168.1.1 (Est.)';
                }

                $local_ip = '';
                if ($isWindows) {
                    $ipconfig = shell_exec('ipconfig');
                    if (preg_match('/IPv4.*?:\s*(\d+\.\d+\.\d+\.\d+)(?=[^}]*?Default Gateway.*?:\s*(?!0\.0\.0\.0)(?:\d+\.){3}\d+)/s', $ipconfig, $matches)) {
                        $local_ip = $matches[1];
                    } elseif (preg_match('/IPv4.*?:\s*(\d+\.\d+\.\d+\.\d+)/', $ipconfig, $matches)) {
                        $local_ip = $matches[1];
                    }
                } else {
                    $local_ip = trim(shell_exec("ip route get 8.8.8.8 2>/dev/null | grep -oP 'src \\K\\S+'"));
                    if (empty($local_ip)) {
                        $local_ip = trim(shell_exec("hostname -I | awk '{print $1}'"));
                    }
                }
                if (empty($local_ip)) {
                    $local_ip = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
                }

                $this->jsonResponse([
                    'success' => true,
                    'public_ip' => $public_ip ?: 'Unknown',
                    'gateway_ip' => $gateway_ip,
                    'local_ip' => $local_ip,
                    'isp' => $public_data['isp'] ?? 'Unknown ISP',
                    'target_ip' => 'www.google.es',
                ]);
            } catch (Exception $e) {
                $this->jsonResponse(['success' => false, 'message' => $e->getMessage()]);
            }
            return true;
        }

        if ($action === 'update_ip_service' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $json = file_get_contents('php://input');
                $data = json_decode($json, true);
                $ip = $data['ip'] ?? '';
                $service = $data['service'] ?? '';

                if (empty($ip) || empty($service)) {
                    throw new Exception('Invalid IP or Service');
                }

                $result = update_ip_service($ip, $service);
                $this->jsonResponse(['success' => $result]);
            } catch (Exception $e) {
                $this->jsonResponse(['success' => false, 'message' => $e->getMessage()]);
            }
            return true;
        }

        if ($action === 'save_network_speed' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $json = file_get_contents('php://input');
                $data = json_decode($json, true);
                $speed = intval($data['speed'] ?? 0);

                if ($speed <= 0) {
                    throw new Exception('Invalid speed value');
                }

                $config = load_config($this->isLocalNetwork);
                $config['settings']['speed_connection_mbps'] = $speed;
                save_config_file($config, $this->configPath);

                $this->jsonResponse(['success' => true]);
            } catch (Exception $e) {
                $this->jsonResponse(['success' => false, 'message' => $e->getMessage()]);
            }
            return true;
        }

        if ($action === 'save_telegram_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $config = load_config($this->isLocalNetwork);
            $message_template = trim($_POST['message_template'] ?? '');

            $config['telegram'] = [
                'enabled' => isset($_POST['enabled']) ? 'true' : 'false',
                'bot_token' => trim($_POST['bot_token'] ?? ''),
                'chat_id' => trim($_POST['chat_id'] ?? ''),
                'notify_on_up' => isset($_POST['notify_on_up']) ? 'true' : 'false',
                'notify_on_down' => isset($_POST['notify_on_down']) ? 'true' : 'false',
                'notify_on_latency' => isset($_POST['notify_on_latency']) ? 'true' : 'false',
                'notify_on_intruder' => isset($_POST['notify_on_intruder']) ? 'true' : 'false',
                'latency_threshold' => (string) max(1, (int) ($_POST['latency_threshold'] ?? 100)),
                'message_template' => $message_template !== ''
                    ? $message_template
                    : '{status_icon} IP {ip} ({service}) is {status} at {timestamp}',
            ];

            if (save_config_file($config, $this->configPath)) {
                $this->redirectWithAction('telegram_updated');
            }

            $this->redirectWithAction('error', 'telegram_config_error');
            return true;
        }

        if ($action === 'get_ai_config' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $config = load_config($this->isLocalNetwork);
            $ai_cfg = get_ai_config($config);
            $this->jsonResponse(['success' => true, 'config' => $ai_cfg]);
            return true;
        }

        if ($action === 'save_ai_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $json = file_get_contents('php://input');
                $data = json_decode($json, true);
                if (!is_array($data)) {
                    throw new Exception('Invalid payload');
                }

                $config = load_config($this->isLocalNetwork);
                $config['ai'] = [
                    'provider' => trim((string) ($data['provider'] ?? 'chatgpt')),
                    'base_url' => trim((string) ($data['base_url'] ?? 'https://chatgpt.com')),
                    'gpt_path' => trim((string) ($data['gpt_path'] ?? '')),
                ];

                if (!save_config_file($config, $this->configPath)) {
                    throw new Exception('Failed to persist AI config');
                }

                $this->jsonResponse(['success' => true, 'config' => get_ai_config($config)]);
            } catch (Exception $e) {
                $this->jsonResponse(['success' => false, 'message' => $e->getMessage()]);
            }
            return true;
        }

        if ($action === 'test_telegram' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $config = load_config($this->isLocalNetwork);
            $telegram_cfg = get_telegram_config($config);
            $telegram_cfg['enabled'] = true;
            $telegram_cfg['bot_token'] = trim($_POST['bot_token'] ?? $telegram_cfg['bot_token']);
            $telegram_cfg['chat_id'] = trim($_POST['chat_id'] ?? $telegram_cfg['chat_id']);

            $bot_id = strtok($telegram_cfg['bot_token'], ':');
            if ($bot_id !== false && $telegram_cfg['chat_id'] === $bot_id) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => 'El Chat ID no puede ser el ID del bot. Abre el bot con tu usuario, pulsa Start y usa tu Chat ID de usuario o grupo.',
                ]);
            }

            $message = 'Monitor-IP: prueba de alertas Telegram OK';
            $success = send_telegram_message($message, $telegram_cfg);
            $this->jsonResponse([
                'success' => $success,
                'message' => $success
                    ? 'Conexión con Telegram verificada.'
                    : 'No se pudo enviar el mensaje. Revisa el token, chat ID y la salida a internet.',
            ]);
            return true;
        }

        return false;
    }

    private function handlePost(): bool
    {
        if (isset($_POST['add_ip'])) {
            $new_ip = trim($_POST['new_ip']);
            $new_service = trim($_POST['new_service']);
            $new_method = trim($_POST['new_method'] ?? 'icmp');
            $new_type = trim($_POST['new_type'] ?? '');

            if (!isValidHost($new_ip)) {
                $this->redirectWithAction('error', 'invalid_ip');
            }
            $validated_ip = $new_ip;

            if ($new_service === 'create_new') {
                $new_service_name = trim($_POST['new_service_name_inline'] ?? '');
                $new_service_color = trim($_POST['new_service_color_inline'] ?? '');

                if (empty($new_service_name)) {
                    $this->redirectWithAction('error', 'empty_service_name');
                }

                if (empty($new_service_color)) {
                    $this->redirectWithAction('error', 'empty_service_color');
                }

                $config = load_config($this->isLocalNetwork);

                if (isset($config['services-colors'][$new_service_name])) {
                    $this->redirectWithAction('error', 'service_exists');
                }

                $new_service_name = htmlspecialchars($new_service_name, ENT_QUOTES, 'UTF-8');
                $new_service_color = htmlspecialchars($new_service_color, ENT_QUOTES, 'UTF-8');
                $config['services-colors'][$new_service_name] = $new_service_color;

                if (!save_config_file($config, $this->configPath)) {
                    $this->redirectWithAction('error', 'config_write_error');
                }

                $new_service = $new_service_name;
            }

            if (empty($new_service) || $new_service === 'create_new') {
                $this->redirectWithAction('error', 'invalid_service');
            }

            $config = load_config($this->isLocalNetwork);
            $check_ips_section = $this->isLocalNetwork ? 'ips-host' : 'ips-services';
            if (isset($config[$check_ips_section][$validated_ip])) {
                $this->redirectWithAction('error', 'ip_exists');
            }

            if (add_ip($validated_ip, $new_service, $new_method, $new_type)) {
                $this->redirectWithAction('added');
            }

            $this->redirectWithAction('error', 'add_ip_failed');
            return true;
        }

        if (isset($_POST['delete_ip'])) {
            $ip_to_delete = $_POST['delete_ip'];
            delete_ip($ip_to_delete);
            $this->redirectWithAction('deleted');
            return true;
        }

        if (isset($_POST['change_timer'])) {
            $new_timer_value = intval($_POST['new_timer_value']);

            if ($new_timer_value > 0) {
                $config = load_config($this->isLocalNetwork);
                $config['settings']['ping_interval'] = $new_timer_value;
                save_config_file($config, $this->configPath);
                $this->redirectWithAction('timer_updated');
            } else {
                echo "<script>alert('Please enter a valid number greater than 0.');</script>";
            }
            return true;
        }

        if (isset($_POST['clear_data'])) {
            global $db;
            try {
                if (isset($_POST['delete_ips'])) {
                    $stmt = $db->prepare('DELETE FROM devices WHERE is_local = ?');
                    $stmt->execute([$this->isLocalNetwork ? 1 : 0]);
                } else {
                    $stmt = $db->prepare('DELETE FROM ping_results WHERE device_id IN (SELECT id FROM devices WHERE is_local = ?)');
                    $stmt->execute([$this->isLocalNetwork ? 1 : 0]);
                }
            } catch (PDOException $e) {
                error_log('Failed to clear SQLite data: ' . $e->getMessage());
            }

            $config = ensure_config_structure(load_config($this->isLocalNetwork), $this->isLocalNetwork);

            if (isset($_POST['delete_ips'])) {
                if ($this->isLocalNetwork) {
                    $config['ips-host'] = [];
                    $config['ips-network'] = [];
                    $config['ips-type'] = [];
                    unset($config['ips-services'], $config['services-colors'], $config['services-methods']);
                } else {
                    $config['ips-host'] = [];
                    $config['ips-type'] = [];
                    $config['ips-services'] = [];
                    $config['services-colors'] = ['DEFAULT' => '#6b7280'];
                    $config['services-methods'] = ['DEFAULT' => 'icmp'];
                    unset($config['ips-network']);
                }
            }

            save_config_file($config, $this->configPath);
            $this->redirectWithAction('data_cleared');
            return true;
        }

        if (isset($_POST['update_service'])) {
            $old_service_name = trim($_POST['old_service_name']);
            $new_service_name = trim($_POST['service_name']);
            $new_service_color = trim($_POST['service_color']);
            $new_service_method = trim($_POST['service_method']);

            if (empty($old_service_name) || empty($new_service_name)) {
                $this->redirectWithAction('error', 'empty_service_name');
            }

            $config = load_config($this->isLocalNetwork);
            if ($old_service_name !== $new_service_name && isset($config['services-colors'][$new_service_name])) {
                $this->redirectWithAction('error', 'service_exists');
            }

            if (update_service_config($old_service_name, $new_service_name, $new_service_color, $new_service_method)) {
                $this->redirectWithAction('service_updated');
            }

            $this->redirectWithAction('error', 'service_update_failed');
            return true;
        }

        if (isset($_POST['confirm_update_ip_service'])) {
            $ip = trim($_POST['update_ip_service'] ?? '');
            $new_service = trim($_POST['new_service_name'] ?? '');

            if ($new_service === 'create_new') {
                $new_service = trim($_POST['new_service_inline_name'] ?? '');
                $new_color = trim($_POST['new_service_inline_color'] ?? '');

                if (empty($new_service) || empty($new_color)) {
                    $this->redirectWithAction('error', 'empty_service_name');
                }

                $config = load_config($this->isLocalNetwork);
                if (!isset($config['services-colors'][$new_service])) {
                    $config['services-colors'][$new_service] = $new_color;
                    if (!isset($config['services-methods'][$new_service])) {
                        $config['services-methods'][$new_service] = 'icmp';
                    }

                    save_config_file($config, $this->configPath);
                }
            }

            $new_type = trim($_POST['new_device_type'] ?? '');

            if (empty($ip)) {
                $this->redirectWithAction('error', 'invalid_ip');
            }

            if ($this->isLocalNetwork) {
                $new_name = trim($_POST['new_device_name'] ?? '');
                $new_network = trim($_POST['new_network_type'] ?? '');

                if (update_local_ip_config($ip, $new_name, $new_network, $new_type)) {
                    $this->redirectWithAction('service_updated');
                }
            } else {
                if (update_ip_service($ip, $new_service, $new_type)) {
                    $this->redirectWithAction('service_updated');
                }
            }

            $this->redirectWithAction('error', 'service_update_failed');
            return true;
        }

        if (isset($_POST['change_password'])) {
            if (!$this->loginEnabled) {
                $this->redirectWithAction('error', 'password_change_disabled');
            }

            $result = change_user_password(
                $_POST['current_password'] ?? '',
                $_POST['new_password'] ?? '',
                $_POST['confirm_password'] ?? ''
            );

            if ($result['success']) {
                $this->redirectWithAction('password_updated');
            }

            $this->redirectWithAction('error', $result['error']);
            return true;
        }

        if (isset($_POST['clear_service'])) {
            $service_to_delete = trim($_POST['service_name']);
            $is_ajax = true;

            if (!empty($service_to_delete)) {
                $result = delete_service($service_to_delete);
                if ($is_ajax) {
                    if ($result) {
                        $this->jsonResponse(['success' => true]);
                    } else {
                        $this->jsonResponse(['success' => false, 'message' => 'No se pudo eliminar el servicio']);
                    }
                }
            }

            if ($is_ajax) {
                $this->jsonResponse(['success' => false, 'message' => 'Nombre de servicio inválido']);
            }
            return true;
        }

        return false;
    }

    private function handleDiagnose(): void
    {
        header('Content-Type: application/json');
        ob_start();
        register_shutdown_function(function () {
            $last = error_get_last();
            if ($last && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                if (!headers_sent()) {
                    header('Content-Type: application/json');
                }
                if (ob_get_level() > 0) {
                    ob_clean();
                }
                echo json_encode([
                    'success' => false,
                    'message' => 'Fatal error in diagnostics endpoint',
                    'error' => $last['message'] ?? 'Unknown fatal error',
                    'file' => $last['file'] ?? null,
                    'line' => $last['line'] ?? null,
                ]);
            }
        });
        $ip = $_POST['ip'] ?? '';
        $type = $_POST['type'] ?? 'all';

        try {
            $response = ['success' => true];

            if ($type === 'traceroute') {
                $traceroute_json = run_traceroute($ip);
                $traceroute_data = json_decode($traceroute_json, true);
                if ($traceroute_data) {
                    $response = $traceroute_data;
                } else {
                    $response = ['success' => false, 'message' => 'Failed to parse traceroute output', 'result' => $traceroute_json];
                }
            } elseif ($type === 'geoip') {
                $response['result'] = get_geoip_info($ip);
            } elseif ($type === 'local_diagnostics') {
                $device_type = $_POST['device_type'] ?? 'other';
                $response['result'] = get_local_ip_diagnostics($ip, $device_type);
            } elseif ($type === 'network_health') {
                $response['result'] = get_network_health();
            }

            if (ob_get_level() > 0) {
                ob_clean();
            }
            $json = json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) {
                $json = json_encode([
                    'success' => false,
                    'message' => 'JSON encoding failed in diagnostics endpoint',
                    'error' => json_last_error_msg(),
                ]);
            }
            echo $json;
        } catch (Throwable $e) {
            if (ob_get_level() > 0) {
                ob_clean();
            }
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
        }
        exit;
    }

    private function jsonResponse(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }

    private function redirectWithAction(string $action, ?string $msg = null): void
    {
        $url = $_SERVER['PHP_SELF'] . '?action=' . urlencode($action);
        if ($msg !== null) {
            $url .= '&msg=' . urlencode($msg);
        }
        $url .= $this->networkParam;
        header('Location: ' . $url);
        exit;
    }
}
