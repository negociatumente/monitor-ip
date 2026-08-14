<?php

$version = '1.2.4'; // Versión actual del esquema de la base de datos
$ping_interval = 300; // Intervalo de ping en segundos (valor por defecto)
$security_enabled = true; // Habilitar seguridad (valor por defecto)
$security_username = ''; // Nombre de usuario para autenticación (vacío por defecto)
$security_password = ''; // Contraseña para autenticación (vacío por defecto)
$network_color = '#012c81'; // Color por defecto para redes (red privada)
$host_color = '#6b7280'; // Color por defecto para hosts (red privada)

if (!extension_loaded('pdo_sqlite')) {
    die("Error: La extensión pdo_sqlite de PHP no está disponible en este servidor.");
}

$db_dir = __DIR__ . '/../db';
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
    $db->exec("INSERT OR IGNORE INTO settings (section, key, value) VALUES ('ai', 'provider', 'chatgpt')");
    $db->exec("INSERT OR IGNORE INTO settings (section, key, value) VALUES ('ai', 'base_url', 'https://chatgpt.com')");
    $db->exec("INSERT OR IGNORE INTO settings (section, key, value) VALUES ('ai', 'gpt_path', '')");
    // Versions before the SQLite migration stored these display settings in
    // the `services` section, while the dashboard reads them from `settings`.
    // Copy existing custom values first, then add defaults for new installs.
    $db->exec("INSERT OR IGNORE INTO settings (section, key, value)
        SELECT 'settings', key, value
        FROM settings
        WHERE section = 'services' AND key IN ('host_color', 'network_color')");
    $db->exec("INSERT OR IGNORE INTO settings (section, key, value) VALUES ('settings', 'host_color', '$host_color')");
    $db->exec("INSERT OR IGNORE INTO settings (section, key, value) VALUES ('settings', 'network_color', '$network_color')");

    // 4. Crear tabla de servicios de la red pública
    $db->exec("CREATE TABLE IF NOT EXISTS services (
        name TEXT PRIMARY KEY,
        method TEXT DEFAULT 'icmp',
        color TEXT DEFAULT '#6b7280'
    )");

    // Recover the service catalog when upgrading databases created before
    // services were stored in their own table. Public devices use `host` as
    // their service name, so this is enough to restore the entries without
    // touching any monitored device.
    $db->exec("INSERT OR IGNORE INTO services (name, method, color)
        SELECT DISTINCT host, 'icmp', '#6b7280'
        FROM devices
        WHERE is_local = 0 AND TRIM(COALESCE(host, '')) <> ''");

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

    // 7. Catálogo editable de pruebas de latencia gaming
    $db->exec("CREATE TABLE IF NOT EXISTS gaming_games (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT UNIQUE NOT NULL,
        name TEXT UNIQUE NOT NULL,
        platform TEXT NOT NULL DEFAULT '',
        target_europe TEXT NOT NULL,
        target_north_america TEXT NOT NULL,
        target_asia_pacific TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // 8. Catálogo editable de resolutores para el comparador DNS
    $db->exec("CREATE TABLE IF NOT EXISTS dns_resolvers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL,
        ip TEXT UNIQUE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Seed the catalog only once. A deleted item must not reappear on restart.
    $catalog_seeded = $db->prepare("SELECT value FROM settings WHERE section = 'catalogs' AND key = ?");
    $catalog_seeded->execute(['gaming_games_seeded']);
    if ($catalog_seeded->fetchColumn() === false) {
        $games = [
            ['league_of_legends', 'League of Legends', 'Riot Games', 'euw1.api.riotgames.com', 'na1.api.riotgames.com', 'oc1.api.riotgames.com'],
            ['valorant', 'Valorant', 'Riot Games', 'euw1.api.riotgames.com', 'na1.api.riotgames.com', 'oc1.api.riotgames.com'],
            ['fortnite', 'Fortnite', 'Epic Games', 'ping-eu.ds.on.epicgames.com', 'ping-nae.ds.on.epicgames.com', 'ping-asia.ds.on.epicgames.com'],
            ['cs2', 'Counter-Strike 2', 'Steam', 'cm0-fra1.cm.steampowered.com', 'cm0-ord1.cm.steampowered.com', 'cm0-sin1.cm.steampowered.com'],
            ['overwatch_2', 'Overwatch 2', 'Battle.net', 'eu.actual.battle.net', 'us.actual.battle.net', 'kr.actual.battle.net'],
            ['rocket_league', 'Rocket League', 'Epic Games', 'ping-eu.ds.on.epicgames.com', 'ping-nae.ds.on.epicgames.com', 'ping-asia.ds.on.epicgames.com'],
            ['apex_legends', 'Apex Legends', 'EA', 'easo.ea.com', 'easo.ea.com', 'easo.ea.com'],
            ['minecraft', 'Minecraft', 'Minecraft Services', 'api.minecraftservices.com', 'api.minecraftservices.com', 'api.minecraftservices.com'],
        ];
        $insert_game = $db->prepare('INSERT OR IGNORE INTO gaming_games (slug, name, platform, target_europe, target_north_america, target_asia_pacific) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($games as $game) {
            $insert_game->execute($game);
        }
        $db->prepare("INSERT INTO settings (section, key, value) VALUES ('catalogs', 'gaming_games_seeded', '1')")->execute();
    }

    $catalog_seeded->execute(['dns_resolvers_seeded']);
    if ($catalog_seeded->fetchColumn() === false) {
        $resolvers = [
            ['Cloudflare', '1.1.1.1'],
            ['Google Public DNS', '8.8.8.8'],
            ['Quad9', '9.9.9.9'],
            ['OpenDNS', '208.67.222.222'],
        ];
        $insert_resolver = $db->prepare('INSERT OR IGNORE INTO dns_resolvers (name, ip) VALUES (?, ?)');
        foreach ($resolvers as $resolver) {
            $insert_resolver->execute($resolver);
        }
        $db->prepare("INSERT INTO settings (section, key, value) VALUES ('catalogs', 'dns_resolvers_seeded', '1')")->execute();
    }

    // Performance indexes for monitor queries
    $db->exec("CREATE INDEX IF NOT EXISTS idx_ping_results_device_timestamp ON ping_results(device_id, timestamp)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_devices_ip_is_local ON devices(ip, is_local)");

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
