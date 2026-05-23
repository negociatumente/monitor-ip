<?php
require_once __DIR__ . '/deployDB.php';

$conf_dir = __DIR__ . '/../conf';
$private_ini = $conf_dir . '/config_private.ini';
$public_ini = $conf_dir . '/config_public.ini';

echo "Iniciando migración a SQLite...\n";

try {
    $db->beginTransaction();
    $stmt = $db->prepare("INSERT OR IGNORE INTO devices (ip, host, type, network, is_local) VALUES (?, ?, ?, ?, ?)");
    $migrated_count = 0;

    // 1. Migrar Dispositivos Locales (config_private.ini)
    if (file_exists($private_ini)) {
        $private_config = parse_ini_file($private_ini, true);
        if (isset($private_config['ips-host']) && is_array($private_config['ips-host'])) {
            foreach ($private_config['ips-host'] as $ip => $host) {
                $type = $private_config['ips-type'][$ip] ?? 'other';
                $network = $private_config['ips-network'][$ip] ?? 'Ethernet';
                $stmt->execute([$ip, $host, $type, $network, 1]);
                $migrated_count++;
            }
            echo "-> Migrados $migrated_count dispositivos locales desde config_private.ini.\n";
        }
    }

    // 2. Migrar Servicios Externos (config_public.ini)
    if (file_exists($public_ini)) {
        $public_config = parse_ini_file($public_ini, true);
        $public_count = 0;
        if (isset($public_config['ips-services']) && is_array($public_config['ips-services'])) {
            foreach ($public_config['ips-services'] as $ip => $host) {
                $type = $public_config['ips-type'][$ip] ?? 'other';
                $network = 'Ethernet';
                $stmt->execute([$ip, $host, $type, $network, 0]);
                $public_count++;
            }
            echo "-> Migrados $public_count servicios externos desde config_public.ini.\n";
        }
    }

    // 3. Migrar Configuraciones Generales (config.ini)
    $config_ini_path = $conf_dir . '/config.ini';
    if (file_exists($config_ini_path)) {
        $config_ini = parse_ini_file($config_ini_path, true);
        if (is_array($config_ini)) {
            $stmt_setting = $db->prepare("INSERT OR REPLACE INTO settings (section, key, value) VALUES (?, ?, ?)");
            foreach ($config_ini as $section => $keys) {
                if (is_array($keys)) {
                    foreach ($keys as $key => $val) {
                        $stmt_setting->execute([$section, $key, (string)$val]);
                    }
                }
            }
            echo "-> Migradas configuraciones generales desde config.ini.\n";
        }
    }

    // 4. Migrar Servicios de la Red Pública (config_public.ini)
    if (file_exists($public_ini)) {
        $public_config = parse_ini_file($public_ini, true);
        $services_colors = $public_config['services-colors'] ?? [];
        $services_methods = $public_config['services-methods'] ?? [];
        
        $all_service_names = array_unique(array_merge(array_keys($services_colors), array_keys($services_methods)));
        
        $stmt_service = $db->prepare("INSERT OR REPLACE INTO services (name, method, color) VALUES (?, ?, ?)");
        foreach ($all_service_names as $name) {
            $color = $services_colors[$name] ?? '#6b7280';
            $method = $services_methods[$name] ?? 'icmp';
            $stmt_service->execute([$name, $method, $color]);
        }
        echo "-> Migrados " . count($all_service_names) . " servicios públicos desde config_public.ini.\n";
    }

    // 5. Migrar Alertas de Telegram (telegram_alert_history.json)
    $telegram_json = __DIR__ . '/../../results/telegram_alert_history.json';
    if (file_exists($telegram_json)) {
        $alerts = json_decode(file_get_contents($telegram_json), true);
        if (is_array($alerts)) {
            $stmt_alert = $db->prepare("INSERT INTO telegram_alerts (timestamp, service, ip, old_status, new_status, response_time, message) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $alert_migrated = 0;
            foreach ($alerts as $a) {
                $stmt_alert->execute([
                    $a['timestamp'] ?? date('Y-m-d H:i:s'),
                    $a['service'] ?? 'Unknown',
                    $a['ip'] ?? '0.0.0.0',
                    $a['old_status'] ?? '-',
                    $a['new_status'] ?? '-',
                    $a['response_time'] ?? 'N/A',
                    $a['message'] ?? ''
                ]);
                $alert_migrated++;
            }
            echo "-> Migradas $alert_migrated alertas de Telegram desde telegram_alert_history.json.\n";
        }
    }

    // 6. Migrar Historial de Speedtest (speedtest_results.json)
    $speedtest_json = __DIR__ . '/../../results/speedtest_results.json';
    if (file_exists($speedtest_json)) {
        $speedtests = json_decode(file_get_contents($speedtest_json), true);
        if (is_array($speedtests)) {
            $stmt_speed = $db->prepare("INSERT INTO speedtest_results (timestamp, latency, download, upload, jitter, packet_loss) VALUES (?, ?, ?, ?, ?, ?)");
            $speed_migrated = 0;
            foreach ($speedtests as $st) {
                $stmt_speed->execute([
                    $st['timestamp'] ?? date('Y-m-d H:i:s'),
                    floatval($st['latency'] ?? 0),
                    floatval($st['download'] ?? 0),
                    floatval($st['upload'] ?? 0),
                    $st['jitter'] ?? 'N/A',
                    floatval($st['packet_loss'] ?? 0)
                ]);
                $speed_migrated++;
            }
            echo "-> Migrados $speed_migrated resultados de Speedtest desde speedtest_results.json.\n";
        }
    }

    $db->commit();
    echo "¡Migración completada exitosamente! Total dispositivos insertados: " . ($migrated_count + ($public_count ?? 0)) . "\n";

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "ERROR durante la migración: " . $e->getMessage() . "\n";
    exit(1);
}
