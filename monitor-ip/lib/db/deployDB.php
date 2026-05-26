<?php

$version = '1.2.1'; // Versión actual del esquema de la base de datos
$ping_interval = 300; // Intervalo de ping en segundos (valor por defecto)
$security_enabled = true; // Habilitar seguridad (valor por defecto)
$security_username = ''; // Nombre de usuario para autenticación (vacío por defecto)
$security_password = ''; // Contraseña para autenticación (vacío por defecto)

if (!extension_loaded('pdo_sqlite')) {
    die("Error: La extensión pdo_sqlite de PHP no está disponible en este servidor.");
}

$db_dir = __DIR__ . '/../../database';
if (!is_dir($db_dir)) {
    mkdir($db_dir, 0775, true);
}

$db_path = $db_dir . '/monitor.db';

try {
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Crear tabla de dispositivos
    $db->exec("CREATE TABLE IF NOT EXISTS devices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT UNIQUE NOT NULL,
        host TEXT,
        type TEXT,
        network TEXT,
        is_local INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 2. Crear tabla de ping_results
    $db->exec("CREATE TABLE IF NOT EXISTS ping_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        device_id INTEGER,
        status TEXT, -- 'UP' o 'DOWN'
        latency REAL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
    )");

    // 3. Crear tabla de configuraciones generales
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        section TEXT NOT NULL,
        key TEXT NOT NULL,
        value TEXT,
        PRIMARY KEY (section, key)
    )");

    $db->exec("INSERT OR IGNORE INTO settings (section, key, value) VALUES ('settings', 'version', '$version')");
    $db->exec("INSERT OR IGNORE INTO settings (section, key, value) VALUES ('settings', 'ping_interval', '$ping_interval')");
    $db->exec("INSERT OR IGNORE INTO settings (section, key, value) VALUES ('security', 'enabled', '" . ($security_enabled ? '1' : '0') . "')");
    $db->exec("INSERT OR IGNORE INTO settings (section, key, value) VALUES ('security', 'username', '$security_username')");
    $db->exec("INSERT OR IGNORE INTO settings (section, key, value) VALUES ('security', 'password', '$security_password')");

    // 4. Crear tabla de servicios de la red pública
    $db->exec("CREATE TABLE IF NOT EXISTS services (
        name TEXT PRIMARY KEY,
        method TEXT DEFAULT 'icmp',
        color TEXT DEFAULT '#6b7280'
    )");

    // 5. Crear tabla de alertas de telegram
    $db->exec("CREATE TABLE IF NOT EXISTS telegram_alerts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        service TEXT,
        ip TEXT,
        old_status TEXT,
        new_status TEXT,
        response_time TEXT,
        message TEXT
    )");

    // 6. Crear tabla de resultados de speedtest
    $db->exec("CREATE TABLE IF NOT EXISTS speedtest_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        latency REAL,
        download REAL,
        upload REAL,
        jitter TEXT,
        packet_loss REAL
    )");

} catch (PDOException $e) {
    die("Error al conectar con SQLite: " . $e->getMessage());
}

function load_ping_data_from_db($is_local_network)
{
    global $db;
    $ping_data = [];
    try {
        $stmt = $db->prepare("SELECT id, ip FROM devices WHERE is_local = ?");
        $stmt->execute([$is_local_network ? 1 : 0]);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt_pings = $db->prepare("SELECT status, timestamp, latency FROM ping_results WHERE device_id = ? ORDER BY timestamp DESC");

        foreach ($devices as $d) {
            $stmt_pings->bindValue(1, $d['id'], PDO::PARAM_INT);
            $stmt_pings->execute();
            $rows = $stmt_pings->fetchAll(PDO::FETCH_ASSOC);

            $pings = [];
            foreach ($rows as $r) {
                $pings[] = [
                    'status' => $r['status'],
                    'timestamp' => $r['timestamp'],
                    'response_time' => ($r['latency'] !== null && $r['latency'] !== 'N/A') ? $r['latency'] . ' ms' : 'N/A'
                ];
            }
            $ping_data[$d['ip']] = $pings;
        }
    } catch (PDOException $e) {
        error_log("Failed to load ping data from SQLite: " . $e->getMessage());
    }
    return $ping_data;
}
