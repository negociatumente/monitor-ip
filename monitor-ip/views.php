<?php

// Centralizar rutas (solo si no están definidas previamente por index.php)
if (!isset($functions_path))
    $functions_path = __DIR__ . '/lib/functions.php';
if (!isset($config_path))
    $config_path = __DIR__ . '/conf/config.ini';
if (!isset($ping_file))
    $ping_file = __DIR__ . '/results/ping_results.json';

// Require functions
require_once $functions_path;

// Cargar resultados previos si existen
if (file_exists($ping_file)) {
    $ping_data = json_decode(file_get_contents($ping_file), true);
} else {
    $ping_data = [];
}

// Manejo de importación/exportación de configuración
$import_export_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_config'])) {
    $success = import_config_ini($_FILES['import_config']);
    // Limpiar datos de ping automáticamente tras importar
    if (file_exists($ping_file)) {
        file_put_contents($ping_file, json_encode([]));
    }
    $import_export_message = $success ? 'Configuración importada correctamente. (Datos de ping limpiados)' : 'Error al importar configuración.';

    // Redirigir para evitar reenvío del archivo
    $network_param = isset($_GET['network']) ? '&network=' . urlencode($_GET['network']) : '';
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?imported=1' . $network_param);
    exit;
}
if (isset($_GET['export_config'])) {
    export_config_ini();
    exit;
}
if (isset($_GET['imported'])) {
    $import_export_message = 'Configuración importada correctamente.';
}


?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="IP Monitor - Track and monitor your network devices">
    <title>IP Monitor Dashboard</title>
    <link rel="icon"
        href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><rect width=%22100%22 height=%22100%22 rx=%2220%22 fill=%22%234f46e5%22/><circle cx=%2250%22 cy=%2250%22 r=%2235%22 stroke=%22white%22 stroke-width=%226%22 fill=%22none%22/><path d=%22M50 15 A35 35 0 0 1 50 85 A35 35 0 0 1 50 15 Z%22 stroke=%22white%22 stroke-width=%226%22 fill=%22none%22/><line x1=%2215%22 y1=%2250%22 x2=%2285%22 y2=%2250%22 stroke=%22white%22 stroke-width=%226%22/></svg>">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="lib/styles.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Global variables
        let pingInterval = <?php echo $ping_interval; ?>;
        let countdown = pingInterval;
        let countdownInterval = null;
        let pingStopped = false;
        let currentPage = 1;
        const pingsPerPage = 5;
        let showSpeedPrompt = <?php echo $show_speed_prompt ? 'true' : 'false'; ?>;
        let currentNetworkType = '<?php echo $network_type; ?>';
        let currentNetworkSpeed = <?php echo (int) ($config['settings']['speed_connection_mbps'] ?? 0); ?>;

        window.ipDetails = {
            <?php
            $ip_entries = [];
            foreach ($ips_to_monitor as $ip => $service) {
                $result = analyze_ip($ip);
                $service_styling = getServiceStyling($service, $services);
                $status_styling = getStatusStyling($result['status']);
                $label_styling = getLabelStyling($result['label']);
                $percentage_styling = getPercentageStyling($result['percentage']);
                $response_styling = getResponseTimeStyling($result['average_response_time']);

                // Get monitoring method
                $config_methods = parse_ini_file($config_path, true);
                $services_methods_conf = $config_methods['services-methods'] ?? [];
                $method = $services_methods_conf[$service] ?? ($services_methods_conf['DEFAULT'] ?? 'icmp');

                $ip_entries[] = "'" . addslashes($ip) . "': " . json_encode([
                    'service' => $service,
                    'service_color' => $service_styling['color'],
                    'service_text_color' => $service_styling['text_color'],
                    'method' => strtoupper($method),
                    'status' => $result['status'],
                    'status_badge' => $status_styling['badge'],
                    'status_icon' => $status_styling['icon'],
                    'label' => $result['label'],
                    'label_badge' => $label_styling['badge'],
                    'label_icon' => $label_styling['icon'],
                    'percentage' => $result['percentage'],
                    'percentage_text_class' => $percentage_styling['text_class'],
                    'average_response_time' => $result['average_response_time'],
                    'response_class' => $response_styling['class'],
                    'ping_results' => $result['ping_results'],
                    'network_type' => $ips_network[$ip] ?? null,
                    'ip' => $ip,
                ]);
            }
            echo implode(",\n", $ip_entries);
            ?>
        };
    </script>

    <script src="lib/script.js"></script>
    <script src="lib/network_scan.js"></script>

</head>

<?php
$header_bg = isset($is_local_network) && $is_local_network
    ? 'from-emerald-600 via-teal-600 to-cyan-700'
    : 'from-blue-600 via-blue-700 to-indigo-800';
$header_icon = isset($is_local_network) && $is_local_network ? 'fa-house-signal' : 'fa-globe';
$network_label = isset($is_local_network) && $is_local_network ? 'Private Network' : 'Public Network';
?>

<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200">
    <!-- Header/Navigation Bar -->
    <header class="bg-gradient-to-r <?php echo $header_bg; ?> text-white shadow-2xl relative overflow-hidden">
        <!-- Background pattern -->
        <div class="absolute inset-0 opacity-10">
            <div
                class="absolute top-0 left-0 w-full h-full bg-gradient-to-br from-transparent via-white to-transparent transform rotate-12">
            </div>
        </div>

        <div class="container mx-auto py-3 px-4 sm:px-6 relative z-10">
            <!-- Top row with logo and actions -->
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 sm:gap-4">
                <!-- Logo and title -->
                <div class="flex items-center gap-2 sm:gap-4 flex-shrink-0">
                    <div
                        class="bg-white bg-opacity-20 p-2 sm:p-3 rounded-full group-hover:bg-opacity-30 transition-all duration-300">
                        <i class="fas <?php echo $header_icon; ?> text-lg sm:text-2xl text-white"></i>
                    </div>
                    <div>
                        <h1 class="text-lg sm:text-2xl lg:text-3xl font-bold tracking-tight">
                            IP Monitor <span
                                class="hidden sm:inline text-sm sm:text-base lg:text-lg font-normal opacity-90 border-l border-white/30 pl-2 sm:pl-3 ml-2"><?php echo $network_label; ?></span>
                            <?php if (isset($config['settings']['version'])): ?>
                                <span
                                    class="hidden sm:inline text-xs sm:text-sm font-normal bg-white bg-opacity-20 px-2 py-1 rounded-full ml-1 sm:ml-2">
                                    v<?php echo htmlspecialchars($config['settings']['version'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            <?php endif; ?>
                        </h1>
                        <p class="text-blue-100 text-xs sm:text-sm mt-0.5">Real-time monitoring & analytics</p>
                    </div>
                </div>

                <!-- Navigation links - Hidden on mobile, visible on sm+ -->
                <div class="hidden sm:flex flex-wrap justify-center lg:justify-end gap-2">
                    <!--<a href="https://nordvpn.com/es/pricing/" target="_blank"
                        class="bg-green-400 hover:bg-green-500 text-blue-900 font-bold px-4 py-2 rounded-lg transition-all duration-300 flex items-center gap-2 text-sm shadow-lg transform hover:scale-105">
                        <i class="fas fa-shield-alt"></i>
                        <span class="hidden sm:inline">Get Secure VPN</span>
                    </a>-->
                    <a href="https://negociatumente.com" target="_blank"
                        class="bg-white bg-opacity-10 hover:bg-opacity-20 px-3 sm:px-4 py-2 rounded-lg transition-all duration-300 flex items-center gap-2 text-xs sm:text-sm backdrop-blur-sm">
                        <i class="fas fa-globe"></i>
                        <span class="hidden md:inline">Web</span>
                    </a>
                    <a href="https://github.com/negociatumente/monitor-ip" target="_blank"
                        class="bg-white bg-opacity-10 hover:bg-opacity-20 px-3 sm:px-4 py-2 rounded-lg transition-all duration-300 flex items-center gap-2 text-xs sm:text-sm backdrop-blur-sm">
                        <i class="fab fa-github"></i>
                        <span class="hidden md:inline">GitHub</span>
                    </a>
                    <a href="https://negociatumente.com/guia-redes/" target="_blank"
                        class="bg-white bg-opacity-10 hover:bg-opacity-20 px-3 sm:px-4 py-2 rounded-lg transition-all duration-300 flex items-center gap-2 text-xs sm:text-sm backdrop-blur-sm">
                        <i class="fas fa-book"></i>
                        <span class="hidden md:inline">Learn More</span>
                    </a>
                </div>
            </div>
        </div>

    </header>

    <!-- Main Layout with Sidebar -->
    <div class="flex flex-col lg:flex-row">
        <!-- Sidebar - Hidden on mobile, visible on lg+ / Can be toggled -->
        <aside id="sidebarPanel"
            class="sidebar-panel fixed inset-y-0 left-0 z-40 w-64 bg-white dark:bg-gray-800 shadow-lg lg:shadow-none lg:static lg:sticky lg:top-0 lg:block border-r border-gray-200 dark:border-gray-700 transform -translate-x-full lg:translate-x-0 transition-all duration-300 lg:w-64 lg:sidebar-expanded"
            data-collapsed="false">

            <div class="p-4 max-h-screen overflow-y-auto sidebar-content">
                <!-- Close button on mobile -->
                <button onclick="toggleSidebar()" id="sidebarCloseBtn"
                    class="lg:hidden absolute top-4 right-4 p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700">
                    <i class="fas fa-times text-lg"></i>
                </button>
                <!-- Desktop Sidebar Toggle Button -->
                <button id="desktopSidebarToggle" onclick="toggleDesktopSidebar()"
                    class="hidden lg:flex px-2 py-3 w-full  gap-2  rounded-lg hover:bg-opacity-20 transition-all items-center mb-4">
                    <i class="fas fa-chevron-left text-lg "></i> <span class="ml-2 sidebar-label">Hide Menu</span>
                </button>
                <!-- Network Selector -->
                <div class="mb-6 network-selector">
                    <h3
                        class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3 sidebar-label">
                        Network Type
                    </h3>
                    <div class="space-y-2">
                        <button onclick="switchNetworkType('external')" id="externalTab"
                            class="btn-sidebar network-selector-btn w-full flex items-center gap-3 px-4 py-3 rounded-lg transition-all text-sm sm:text-base <?php echo !$is_local_network ? 'bg-gradient-to-r from-blue-500 to-blue-600 text-white shadow-md' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?>"
                            title="Public Network">
                            <i class="fas fa-globe text-lg"></i>
                            <span class="font-medium sidebar-label">Public Network</span>
                        </button>
                        <button onclick="switchNetworkType('local')" id="localTab"
                            class="btn-sidebar network-selector-btn w-full flex items-center gap-3 px-4 py-3 rounded-lg transition-all text-sm sm:text-base <?php echo $is_local_network ? 'bg-gradient-to-r from-emerald-500 to-emerald-600 text-white shadow-md' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?>"
                            title="Private Network">
                            <i class="fas fa-home text-lg"></i>
                            <span class="font-medium sidebar-label">Private Network</span>
                        </button>
                    </div>
                </div>

                <!-- Quick Actions (for External Network) -->
                <?php if (!isset($is_local_network) || !$is_local_network): ?>

                <?php else: ?>
                    <!-- Quick Actions (only for Local Network) -->
                    <div class="mb-6">
                        <h3
                            class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3 sidebar-label">
                            Network Tools
                        </h3>
                        <div class="space-y-2">
                            <button onclick="showNetworkHealth(); toggleSidebar();" class="btn-sidebar btn-primary"
                                title="Network Health">
                                <i class="fas fa-heartbeat"></i>
                                <span class="sidebar-label">Network Health</span>
                            </button>
                            <button onclick="showTopologyMapModal(); toggleSidebar();"
                                class="btn-sidebar bg-purple-600 text-white" title="Topology Map">
                                <i class="fas fa-sitemap"></i>
                                <span class="sidebar-label">Topology Map</span>
                            </button>
                        </div>
                    </div>

                <?php endif; ?>


            </div>
        </aside>

        <!-- Overlay for mobile sidebar -->
        <div id="sidebarOverlay" onclick="toggleSidebar()"
            class="fixed inset-0 bg-black bg-opacity-50 z-30 hidden lg:hidden"></div>

        <!-- Main Content Area -->
        <div class="flex-1">
            <div class="container mx-auto px-4 py-4">
                <!-- Notifications -->
                <?php if (isset($_GET['action'])): ?>
                    <?php echo renderNotification($_GET['action'], $_GET['msg'] ?? null); ?>
                <?php endif; ?>

                <!-- Dash Menu -->
                <?php require_once __DIR__ . '/menu.php'; ?>

                <!-- IP Monitoring Table -->
                <div id="monitoringTable" class='bg-white dark:bg-gray-800 rounded-lg shadow-sm overflow-hidden'>
                    <div
                        class='p-3 sm:p-4 border-b border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 sm:gap-4'>
                        <h2 class='text-base sm:text-lg font-semibold text-gray-800 dark:text-gray-200'>
                            <i class='fas fa-table mr-2'></i> IP Monitoring Table
                        </h2>

                        <!-- Action Buttons -->
                        <div class="">
                            <div
                                class="flex flex-col sm:flex-row flex-wrap gap-2 sm:gap-3 justify-center sm:justify-start">
                                <?php if (isset($network_type) && $network_type === 'local'): ?>
                                    <button type="button" onclick="showScanNetworkModal();"
                                        class="btn  btn-primary px-3 sm:px-6 py-2 rounded-lg text-xs sm:text-sm w-full sm:w-auto">
                                        <i class="fas fa-network-wired"></i> <span class="hidden sm:inline">Scan
                                            Network</span><span class="sm:hidden">Scan</span>
                                    </button>
                                    <button type="button" onclick="showSpeedTestModal();"
                                        class="btn  btn-secondary px-3 sm:px-6 py-2 rounded-lg text-xs sm:text-sm w-full sm:w-auto">
                                        <i class="fas fa-tachometer-alt"></i> <span class="hidden sm:inline">Speed
                                            Test</span><span class="sm:hidden">Speed Test</span>
                                    </button>
                                <?php else: ?>
                                    <button onclick="showAddIpForm();"
                                        class="btn  btn-primary px-3 sm:px-6 py-2 rounded-lg text-xs sm:text-sm w-full sm:w-auto">
                                        <i class="fas fa-network-wired"></i> <span class="hidden sm:inline">Add IP /
                                            Domain</span><span class="sm:hidden">Add IP</span>
                                    </button>
                                    <button type="button" onclick="showManageServiceForm();"
                                        class="btn  btn-secondary px-3 sm:px-6 py-2 rounded-lg text-xs sm:text-sm w-full sm:w-auto">
                                        <i class="fas fa-tasks"></i> <span class="hidden sm:inline">Manage
                                            Services</span><span class="sm:hidden">Services</span>
                                    </button>
                                <?php endif; ?>

                                <button onclick="showConfigModal();"
                                    class="btn  btn-accent px-3 sm:px-6 py-2 rounded-lg text-xs sm:text-sm w-full sm:w-auto">
                                    <i class="fas fa-file-import"></i> <span class="hidden sm:inline">
                                        Manage Config</span><span class="sm:hidden">Config</span>
                                </button>

                                <button type="button" onclick="showClearDataConfirmation();"
                                    class="btn  btn-danger px-3 sm:px-6 py-2 rounded-lg text-xs sm:text-sm w-full sm:w-auto">
                                    <i class="fas fa-trash-alt"></i> <span class="hidden sm:inline">Clear
                                        Data</span><span class="sm:hidden">Clear</span>
                                </button>
                            </div>
                        </div>


                    </div>

                    <!-- Filters -->
                    <div
                        class="p-3 sm:p-4 bg-gray-50 dark:bg-gray-700/30 border-b border-gray-200 dark:border-gray-700 flex flex-col gap-3 sm:flex-row sm:gap-4 items-stretch sm:items-center">
                        <div class="flex-1 min-w-0">
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                    <i class="fas fa-search text-gray-400"></i>
                                </div>
                                <input type="text" id="searchFilter" onkeyup="filterTable()"
                                    placeholder="Search IP or Domain..."
                                    class="w-full pl-10 p-2 text-xs sm:text-sm bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                            </div>
                        </div>
                        <div class="w-full sm:w-auto min-w-0">
                            <select id="serviceFilter" onchange="filterTable()"
                                class="w-full p-2 text-xs sm:text-sm bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                <option value=""><?php echo $is_local_network ? 'All Devices' : 'All Services'; ?>
                                </option>
                                <?php foreach ($services as $service_name => $color): ?>
                                    <?php if ($service_name !== "DEFAULT"): ?>
                                        <option value="<?php echo htmlspecialchars($service_name); ?>">
                                            <?php echo htmlspecialchars($service_name); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="w-full sm:w-auto min-w-0">
                            <select id="statusFilter" onchange="filterTable()"
                                class="w-full p-2 text-xs sm:text-sm bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                <option value="">All Statuses</option>
                                <option value="UP">UP</option>
                                <option value="DOWN">DOWN</option>
                            </select>
                        </div>
                        <button onclick="resetFilters()"
                            class="text-xs sm:text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 whitespace-nowrap">
                            Reset Filters
                        </button>
                    </div>
                    <div class='overflow-x-auto'>
                        <table class='min-w-max w-full'>
                            <thead>
                                <tr class='bg-gray-50 dark:bg-gray-700 text-left'>
                                    <th class='p-3 whitespace-nowrap'>
                                        <?php echo $is_local_network ? 'Device' : 'Service'; ?>
                                    </th>
                                    <th class='p-3 whitespace-nowrap cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600'
                                        onclick="window.location.href='?sort=ip&order=<?php echo (isset($_GET['sort']) && $_GET['sort'] === 'ip' && isset($_GET['order']) && $_GET['order'] === 'asc') ? 'desc' : 'asc'; ?><?php echo $network_param; ?>&no_ping=1'">
                                        <div class="flex items-center gap-1">
                                            <?php echo $is_local_network ? 'IP' : 'IP / Domain'; ?>
                                            <?php if (isset($_GET['sort']) && $_GET['sort'] === 'ip'): ?>
                                                <i
                                                    class="fas fa-sort-<?php echo $_GET['order'] === 'asc' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort text-gray-300"></i>
                                            <?php endif; ?>
                                        </div>
                                    </th>
                                    <?php if ($is_local_network): ?>
                                        <th class='p-3 whitespace-nowrap cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600'
                                            onclick="window.location.href='?sort=network&order=<?php echo (isset($_GET['sort']) && $_GET['sort'] === 'network' && isset($_GET['order']) && $_GET['order'] === 'asc') ? 'desc' : 'asc'; ?><?php echo $network_param; ?>&no_ping=1'">
                                            <div class="flex items-center gap-1">
                                                Network
                                                <?php if (isset($_GET['sort']) && $_GET['sort'] === 'network'): ?>
                                                    <i
                                                        class="fas fa-sort-<?php echo $_GET['order'] === 'asc' ? 'up' : 'down'; ?>"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-sort text-gray-300"></i>
                                                <?php endif; ?>
                                            </div>
                                        </th>
                                    <?php endif; ?>
                                    <?php if (!$is_local_network): ?>
                                        <th class='p-3 whitespace-nowrap'>Method</th>
                                    <?php endif; ?>
                                    <th class='p-3 whitespace-nowrap'>Status</th>
                                    <th class='p-3 whitespace-nowrap'>Reliability</th>
                                    <th class='p-3 whitespace-nowrap'>Uptime</th>
                                    <th class='p-3 whitespace-nowrap cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600'
                                        onclick="window.location.href='?sort=latency&order=<?php echo (isset($_GET['sort']) && $_GET['sort'] === 'latency' && isset($_GET['order']) && $_GET['order'] === 'asc') ? 'desc' : 'asc'; ?><?php echo $network_param; ?>&no_ping=1'">
                                        <div class="flex items-center gap-1">
                                            LATENCY
                                            <?php if (isset($_GET['sort']) && $_GET['sort'] === 'latency'): ?>
                                                <i
                                                    class="fas fa-sort-<?php echo $_GET['order'] === 'asc' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort text-gray-300"></i>
                                            <?php endif; ?>
                                        </div>
                                    </th>
                                    <?php
                                    // Encabezados de pings (de derecha a izquierda)
                                    $max_pings_to_show = 5;
                                    $sample_ip = array_key_last($ping_data);
                                    if ($sample_ip !== null) {
                                        $latest_pings = $ping_data[$sample_ip] ?? [];
                                    } else {
                                        $latest_pings = [];
                                    }
                                    $num_pings = count($latest_pings);
                                    $header_labels = [];
                                    for ($i = 0; $i < $max_pings_to_show; $i++) {
                                        if ($i === 0) {
                                            $header_labels[] = 'now';
                                        } else {
                                            if ($num_pings >= $max_pings_to_show) {
                                                $ping_index = $i - 1;
                                            } else {
                                                $ping_index = $i;
                                            }
                                            $timestamp = isset($latest_pings[$ping_index]) ? ($latest_pings[$ping_index]['timestamp'] ?? '-') : '-';
                                            if ($timestamp !== '-') {
                                                $date = new DateTime($timestamp);
                                                $now = new DateTime();
                                                if ($date->format('Y-m-d') === $now->format('Y-m-d')) {
                                                    $timestamp = $date->format('H:i:s');
                                                }
                                            }
                                            $header_labels[] = $timestamp;
                                        }
                                    }
                                    $header_labels = array_reverse($header_labels);
                                    foreach ($header_labels as $label) {
                                        echo "<th class='p-2 text-center text-xs whitespace-nowrap'>{$label}</th>";
                                    }
                                    ?>
                                    <th class='p-3 text-center whitespace-nowrap'>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="monitoringTableBody" class='divide-y divide-gray-200 dark:divide-gray-700'>
                                <?php if (empty($ips_to_monitor)): ?>
                                    <tr>
                                        <td colspan='<?php echo 8 + $max_pings_to_show; ?>'
                                            class='p-4 text-center text-gray-500 dark:text-gray-400'>
                                            <div class='py-8'>
                                                <i class='fas fa-info-circle text-blue-500 text-4xl mb-3'></i>
                                                <p class='text-lg'>No IPs or Domains being monitored yet</p>
                                                <p class='text-sm'>Click "Add IP / Domain" button above to start monitoring
                                                </p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php
                                    // Prepare all IPs with their analysis for sorting
                                    $analyzed_ips = [];
                                    // Need to get ips_network array for network sorting if needed
                                    $parsed_config = parse_ini_file($config_path, true);
                                    $ips_network_map = $parsed_config['ips-network'] ?? [];

                                    foreach ($ips_to_monitor as $ip => $service) {
                                        $result = analyze_ip($ip);
                                        $analyzed_ips[] = [
                                            'ip' => $ip,
                                            'service' => $service,
                                            'network' => $ips_network_map[$ip] ?? '',
                                            'result' => $result
                                        ];
                                    }

                                    // Sorting logic
                                    if (isset($_GET['sort'])) {
                                        $sort = $_GET['sort'];
                                        $order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'desc' : 'asc';

                                        usort($analyzed_ips, function ($a, $b) use ($sort, $order) {
                                            $valA = null;
                                            $valB = null;

                                            if ($sort === 'latency') {
                                                $valA = $a['result']['average_response_time'];
                                                $valB = $b['result']['average_response_time'];

                                                // Handle N/A or non-numeric values (treat as high latency/infinity)
                                                $isNumA = is_numeric($valA);
                                                $isNumB = is_numeric($valB);

                                                if (!$isNumA && !$isNumB)
                                                    return 0;
                                                if (!$isNumA)
                                                    return 1; // N/A goes to bottom
                                                if (!$isNumB)
                                                    return -1; // N/A goes to bottom
                                
                                                return $order === 'asc' ? ($valA <=> $valB) : ($valB <=> $valA);

                                            } elseif ($sort === 'ip') {
                                                // Sort by IP address naturally
                                                $ipA = $a['ip'];
                                                $ipB = $b['ip'];

                                                $packedA = @inet_pton($ipA);
                                                $packedB = @inet_pton($ipB);

                                                if ($packedA !== false && $packedB !== false) {
                                                    // Both are valid IPs
                                                    $cmp = strcmp($packedA, $packedB);
                                                } else {
                                                    // Fallback to natural string comparison (handles mixed or invalid IPs)
                                                    $cmp = strnatcmp($ipA, $ipB);
                                                }
                                                return $order === 'asc' ? $cmp : -$cmp;

                                            } elseif ($sort === 'network') {
                                                $valA = $a['network'];
                                                $valB = $b['network'];
                                                $cmp = strnatcmp($valA, $valB);
                                                return $order === 'asc' ? $cmp : -$cmp;
                                            }

                                            return 0;
                                        });
                                    }

                                    // Pagination logic
                                    $items_per_page = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 10;
                                    $current_page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
                                    $total_ips = count($analyzed_ips);
                                    $total_pages = max(1, ceil($total_ips / $items_per_page));
                                    $current_page = min($current_page, $total_pages);
                                    $offset = ($current_page - 1) * $items_per_page;

                                    // Get IPs for current page from sorted list
                                    $ips_on_page = array_slice($analyzed_ips, $offset, $items_per_page);
                                    ?>
                                    <?php foreach ($ips_on_page as $item): ?>
                                        <?php
                                        // Extract variables from prepared item
                                        $ip = $item['ip'];
                                        $service = $item['service'];
                                        $result = $item['result'];

                                        $status = $result['status'];
                                        $percentage = $result['percentage'];
                                        $label = $result['label'];
                                        $ping_results = $result['ping_results'];
                                        $average_response_time = $result['average_response_time'];
                                        $status_styling = getStatusStyling($status);
                                        $label_styling = getLabelStyling($label);
                                        $service_styling = getServiceStyling($service, $services);
                                        $percentage_styling = getPercentageStyling($percentage);
                                        $response_styling = getResponseTimeStyling($average_response_time);

                                        // Note: analyze_ip is NOT called again here
                                

                                        // Get monitoring method
                                        $config = parse_ini_file($config_path, true);
                                        $services_methods = $config['services-methods'] ?? [];
                                        $method = $services_methods[$service] ?? ($services_methods['DEFAULT'] ?? 'icmp');
                                        $method_display = strtoupper($method);
                                        $method_icon = $method === 'icmp' ? 'network-wired' : ($method === 'curl' ? 'globe' : 'server');
                                        ?>
                                        <tr class='hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-all duration-150 cursor-pointer'
                                            onclick="showIpDetailModal('<?php echo htmlspecialchars($ip, ENT_QUOTES, 'UTF-8'); ?>')">
                                            <td class='p-3 group'>
                                                <div class='flex items-center gap-2'>
                                                    <span
                                                        class='inline-block px-3 py-1 rounded-full text-sm font-medium transition-transform group-hover:scale-105 shadow-sm'
                                                        style='background-color: <?php echo $service_styling['color']; ?>; color: <?php echo $service_styling['text_color']; ?>'>
                                                        <?php echo $service; ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class='p-3 font-mono text-sm'><?php echo $ip; ?></td>
                                            <?php if ($is_local_network): ?>
                                                <td class='p-3'>
                                                    <span
                                                        class='inline-block px-2 py-1 rounded text-xs font-medium bg-gray-100 dark:bg-gray-600 text-gray-700 dark:text-gray-300'>
                                                        <?php echo $ips_network[$ip] ?? 'Unknown'; ?>
                                                    </span>
                                                </td>
                                            <?php endif; ?>
                                            <?php if (!$is_local_network): ?>
                                                <td class='p-3'>
                                                    <span
                                                        class='inline-block px-2 py-1 rounded text-xs font-medium bg-gray-100 dark:bg-gray-600 text-gray-700 dark:text-gray-300'>
                                                        <i class='fas fa-<?php echo $method_icon; ?> mr-1'></i>
                                                        <?php echo $method_display; ?>
                                                    </span>
                                                </td>
                                            <?php endif; ?>
                                            <td class='p-3'>
                                                <span class='status-badge <?php echo $status_styling['badge']; ?>'>
                                                    <i class='fas fa-<?php echo $status_styling['icon']; ?> mr-1'></i>
                                                    <?php echo $status; ?>
                                                </span>
                                            </td>
                                            <td class='p-3'>
                                                <span class='status-badge <?php echo $label_styling['badge']; ?>'>
                                                    <i class='fas fa-<?php echo $label_styling['icon']; ?> mr-1'></i>
                                                    <?php echo $label; ?>
                                                </span>
                                            </td>
                                            <td class='p-3'>
                                                <div class='flex items-center'>
                                                    <div class='w-16 bg-gray-200 dark:bg-gray-700 rounded-full h-2 mr-2 progress-animated'
                                                        style='overflow:hidden;'>
                                                        <div class='progress-inner <?php echo $percentage_styling['bar_class']; ?>'
                                                            style='width: <?php echo round($percentage_styling['percentage']); ?>%'>
                                                        </div>
                                                    </div>
                                                    <span class='font-medium <?php echo $percentage_styling['text_class']; ?>'>
                                                        <?php echo round($percentage_styling['percentage']); ?>%
                                                    </span>
                                                </div>
                                            </td>
                                            <td class='p-3 font-medium <?php echo $response_styling['class']; ?>'>
                                                <?php
                                                if (is_numeric($average_response_time)) {
                                                    echo number_format($average_response_time, 2) . ' ms';
                                                } else {
                                                    echo $response_styling['display'];
                                                }
                                                ?>
                                            </td>
                                            <?php
                                            // Mostrar los pings de derecha a izquierda, primero celdas vacías (izquierda), luego pings reales (derecha)
                                            $max_pings_to_show = 5;
                                            $ping_results = $result['ping_results'];
                                            $num_pings = count(value: $ping_results);
                                            $pings_to_show = array_slice($ping_results, 0, $max_pings_to_show);
                                            $empty_cells = $max_pings_to_show - count($pings_to_show);
                                            // Primero las celdas vacías (izquierda)
                                            for ($i = 0; $i < $empty_cells; $i++) {
                                                echo "<td class='p-2 text-center'><div class='inline-flex items-center justify-center w-6 h-6 bg-gray-300 text-gray-500 rounded-md shadow-sm' title='No data'><i class='fas fa-minus text-xs'></i></div></td>";
                                            }
                                            // Luego los pings reales (derecha)
                                            for ($i = count($pings_to_show) - 1; $i >= 0; $i--) {
                                                $ping = $pings_to_show[$i];
                                                if ($num_pings >= $max_pings_to_show && $i === 0) {
                                                    // Última columna (derecha), mostrar 'now' si hay 5 o más pings
                                                    if (($ping['status'] ?? '-') === 'UP') {
                                                        echo "<td class='p-2 text-center'><div class='inline-flex items-center justify-center w-6 h-6 bg-green-500 text-white rounded-md shadow-sm hover:shadow-md transition-shadow' title='UP - now'><i class='fas fa-check text-xs'></i></div></td>";
                                                    } elseif (($ping['status'] ?? '-') === 'DOWN') {
                                                        echo "<td class='p-2 text-center'><div class='inline-flex items-center justify-center w-6 h-6 bg-red-500 text-white rounded-md shadow-sm hover:shadow-md transition-shadow' title='DOWN - now'><i class='fas fa-times text-xs'></i></div></td>";
                                                    } else {
                                                        echo "<td class='p-2 text-center'><div class='inline-flex items-center justify-center w-6 h-6 bg-gray-300 text-gray-500 rounded-md shadow-sm' title='No data'><i class='fas fa-minus text-xs'></i></div></td>";
                                                    }
                                                } else {
                                                    if (($ping['status'] ?? 'EMPTY') === 'UP') {
                                                        $response_time = isset($ping['response_time']) ? $ping['response_time'] : 'N/A';
                                                        echo "<td class='p-2 text-center'><div class='inline-flex items-center justify-center w-6 h-6 bg-green-500 text-white rounded-md shadow-sm hover:shadow-md transition-shadow' title='UP - {$response_time}'><i class='fas fa-check text-xs'></i></div></td>";
                                                    } elseif (($ping['status'] ?? 'EMPTY') === 'DOWN') {
                                                        echo "<td class='p-2 text-center'><div class='inline-flex items-center justify-center w-6 h-6 bg-red-500 text-white rounded-md shadow-sm hover:shadow-md transition-shadow' title='DOWN'><i class='fas fa-times text-xs'></i></div></td>";
                                                    } else {
                                                        echo "<td class='p-2 text-center'><div class='inline-flex items-center justify-center w-6 h-6 bg-gray-300 text-gray-500 rounded-md shadow-sm' title='No data'><i class='fas fa-minus text-xs'></i></div></td>";
                                                    }
                                                }
                                            }
                                            ?>
                                            <td class='p-3 text-center'>
                                                <div class="flex items-center justify-center gap-2">

                                                    <button type='button'
                                                        onclick="event.stopPropagation(); showChangeIpServiceModal('<?php echo htmlspecialchars($ip, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($service, ENT_QUOTES, 'UTF-8'); ?>', <?php echo $is_local_network ? 'true' : 'false'; ?>, '<?php echo $is_local_network ? htmlspecialchars($ips_network[$ip] ?? '', ENT_QUOTES, 'UTF-8') : ''; ?>')"
                                                        class='btn text-blue-700 hover:bg-blue-200 dark:bg-blue-900/30 dark:text-blue-300 dark:hover:bg-blue-800/50 p-2 rounded-md transition-all'
                                                        title="<?php echo isset($is_local_network) && $is_local_network ? 'Change Host' : 'Change Service'; ?>">
                                                        <i class='fas fa-edit text-sm'></i>
                                                    </button>

                                                    <button type='button'
                                                        onclick="event.stopPropagation();confirmDelete('<?php echo htmlspecialchars($ip, ENT_QUOTES, 'UTF-8'); ?>')"
                                                        class='btn  text-red-700 hover:bg-red-200 dark:bg-red-900/30 dark:text-red-300 dark:hover:bg-red-800/50 p-2 rounded-md transition-all'
                                                        title='Delete IP'>
                                                        <i class='fas fa-trash-alt text-sm'></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_ips > 0): ?>
                        <!-- Pagination Controls -->
                        <div class="p-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/30">
                            <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                                <!-- Items per page selector -->
                                <div class="flex items-center gap-2">
                                    <label for="perPageSelect"
                                        class="text-sm text-gray-600 dark:text-gray-400">Show:</label>
                                    <select id="perPageSelect" onchange="changePerPage(this.value)"
                                        class="p-2 text-sm bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                        <option value="10" <?php echo $items_per_page == 10 ? 'selected' : ''; ?>>10</option>
                                        <option value="25" <?php echo $items_per_page == 25 ? 'selected' : ''; ?>>25</option>
                                        <option value="50" <?php echo $items_per_page == 50 ? 'selected' : ''; ?>>50</option>
                                        <option value="100" <?php echo $items_per_page == 100 ? 'selected' : ''; ?>>100
                                        </option>
                                        <option value="<?php echo $total_ips; ?>" <?php echo $items_per_page >= $total_ips ? 'selected' : ''; ?>>All</option>
                                    </select>
                                    <span class="text-sm text-gray-600 dark:text-gray-400">of <?php echo $total_ips; ?>
                                        hosts</span>
                                </div>

                                <!-- Page info -->
                                <div class="text-sm text-gray-600 dark:text-gray-400">
                                    Showing <?php echo $offset + 1; ?> to
                                    <?php echo min($offset + $items_per_page, $total_ips); ?>
                                    of <?php echo $total_ips; ?> hosts
                                </div>

                                <!-- Page navigation -->
                                <div class="flex items-center gap-2">
                                    <?php if ($current_page > 1): ?>
                                        <a href="?page=<?php echo $current_page - 1; ?>&per_page=<?php echo $items_per_page; ?><?php echo $network_param; ?><?php echo isset($_GET['sort']) ? '&sort=' . $_GET['sort'] . '&order=' . $_GET['order'] : ''; ?>"
                                            class="px-3 py-2 text-sm bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                                            <i class="fas fa-chevron-left"></i> Previous
                                        </a>
                                    <?php else: ?>
                                        <span
                                            class="px-3 py-2 text-sm bg-gray-300 text-gray-500 rounded-lg cursor-not-allowed dark:bg-gray-600 dark:text-gray-400">
                                            <i class="fas fa-chevron-left"></i> Previous
                                        </span>
                                    <?php endif; ?>

                                    <!-- Page numbers -->
                                    <div class="flex items-center gap-1">
                                        <?php
                                        $max_visible_pages = 5;
                                        $start_page = max(1, $current_page - floor($max_visible_pages / 2));
                                        $end_page = min($total_pages, $start_page + $max_visible_pages - 1);

                                        if ($end_page - $start_page + 1 < $max_visible_pages) {
                                            $start_page = max(1, $end_page - $max_visible_pages + 1);
                                        }

                                        if ($start_page > 1): ?>
                                            <a href="?page=1&per_page=<?php echo $items_per_page; ?><?php echo $network_param; ?><?php echo isset($_GET['sort']) ? '&sort=' . $_GET['sort'] . '&order=' . $_GET['order'] : ''; ?>"
                                                class="px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                                1
                                            </a>
                                            <?php if ($start_page > 2): ?>
                                                <span class="px-2 text-gray-500">...</span>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                            <?php if ($i == $current_page): ?>
                                                <span
                                                    class="px-3 py-2 text-sm bg-blue-500 text-white rounded-lg"><?php echo $i; ?></span>
                                            <?php else: ?>
                                                <a href="?page=<?php echo $i; ?>&per_page=<?php echo $items_per_page; ?><?php echo $network_param; ?><?php echo isset($_GET['sort']) ? '&sort=' . $_GET['sort'] . '&order=' . $_GET['order'] : ''; ?>"
                                                    class="px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                                    <?php echo $i; ?>
                                                </a>
                                            <?php endif; ?>
                                        <?php endfor; ?>

                                        <?php if ($end_page < $total_pages): ?>
                                            <?php if ($end_page < $total_pages - 1): ?>
                                                <span class="px-2 text-gray-500">...</span>
                                            <?php endif; ?>
                                            <a href="?page=<?php echo $total_pages; ?>&per_page=<?php echo $items_per_page; ?><?php echo $network_param; ?><?php echo isset($_GET['sort']) ? '&sort=' . $_GET['sort'] . '&order=' . $_GET['order'] : ''; ?>"
                                                class="px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                                <?php echo $total_pages; ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($current_page < $total_pages): ?>
                                        <a href="?page=<?php echo $current_page + 1; ?>&per_page=<?php echo $items_per_page; ?><?php echo $network_param; ?><?php echo isset($_GET['sort']) ? '&sort=' . $_GET['sort'] . '&order=' . $_GET['order'] : ''; ?>"
                                            class="px-3 py-2 text-sm bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                                            Next <i class="fas fa-chevron-right"></i>
                                        </a>
                                    <?php else: ?>
                                        <span
                                            class="px-3 py-2 text-sm bg-gray-300 text-gray-500 rounded-lg cursor-not-allowed dark:bg-gray-600 dark:text-gray-400">
                                            Next <i class="fas fa-chevron-right"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Modal para información detallada de IP -->
                <div id="ipDetailModal"
                    class="fixed inset-0 z-[100] flex items-center justify-center bg-black bg-opacity-50 hidden p-4">
                    <div
                        class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl p-0 w-full max-w-2xl lg:max-w-4xl relative max-h-[95vh] flex flex-col overflow-hidden border border-gray-200 dark:border-gray-700">

                        <!-- Modal Header -->
                        <div
                            class="p-4 sm:p-6 bg-gradient-to-r from-blue-600 to-indigo-700 text-white flex justify-between items-center shrink-0">
                            <div class="flex items-center gap-2 min-w-0">
                                <i class="fas fa-network-wired text-lg sm:text-2xl mr-2 opacity-80"></i>
                                <h3 class="text-lg sm:text-xl font-bold tracking-tight truncate" id="modalIpTitle">Host
                                    Detail</h3>
                            </div>
                            <button onclick="closeIpModal()"
                                class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center transition-all flex-shrink-0">
                                <i class="fas fa-times text-lg sm:text-xl"></i>
                            </button>
                        </div>

                        <!-- Tab Navigation (Visible mainly for External Network) -->
                        <div id="ipDetailTabsNav"
                            class="flex bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 shrink-0 overflow-x-auto">
                            <button onclick="switchIpDetailTab('general')" id="ipTabGeneral"
                                class="px-3 sm:px-6 py-2 sm:py-3 text-xs sm:text-sm font-bold uppercase tracking-wider transition-all border-b-2 border-blue-600 text-blue-600 bg-white dark:bg-gray-800 dark:text-blue-400 whitespace-nowrap">
                                <i class="fas fa-chart-line mr-1 sm:mr-2"></i> <span
                                    class="hidden sm:inline">Statistics</span><span class="sm:hidden">Stats</span>
                            </button>
                            <button onclick="switchIpDetailTab('diagnostics')" id="ipTabDiagnostics"
                                class="px-3 sm:px-6 py-2 sm:py-3 text-xs sm:text-sm font-bold uppercase tracking-wider transition-all border-b-2 border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 whitespace-nowrap">
                                <i class="fas fa-search-location mr-1 sm:mr-2"></i> <span
                                    class="hidden sm:inline">Diagnostics</span><span class="sm:hidden">Diag</span>
                            </button>
                            <button onclick="switchIpDetailTab('aireport')" id="ipTabAIReport"
                                class="px-3 sm:px-6 py-2 sm:py-3 text-xs sm:text-sm font-bold uppercase tracking-wider transition-all border-b-2 border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 whitespace-nowrap">
                                <i class="fas fa-robot mr-1 sm:mr-2"></i> <span class="hidden sm:inline">AI
                                    Report</span><span class="sm:hidden">AI</span>
                            </button>
                        </div>

                        <!-- Scrollable Content -->
                        <div class="flex-1 overflow-y-auto p-4 sm:p-6 bg-white dark:bg-gray-900 custom-scrollbar">
                            <!-- General Tab Content -->
                            <div id="ipDetailContentGeneral" class="space-y-4 sm:space-y-6">
                                <div id="modalIpContent"></div>

                                <div class="mt-4 sm:mt-2">
                                    <h4
                                        class="text-sm sm:text-base font-semibold text-gray-700 dark:text-gray-300 mb-3 sm:mb-4 flex items-center">
                                        <i class="fas fa-chart-area mr-2 text-blue-500"></i>
                                        Latency Performance (Last <?php echo $ping_attempts; ?> pings)
                                    </h4>
                                    <div
                                        class="bg-gray-50 dark:bg-gray-800 p-3 sm:p-4 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-inner overflow-auto">
                                        <canvas id="pingChart" width="400" height="200"></canvas>
                                    </div>
                                </div>
                            </div>

                            <!-- Diagnostics Tab Content (WHOIS/Traceroute) -->
                            <div id="ipDetailContentDiagnostics" class="hidden h-full flex flex-col">
                                <!-- Integrated from diagnostics modal logic -->
                                <div class="flex flex-col gap-4 sm:gap-6">
                                    <!-- GeoIP/WHOIS section -->
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 sm:gap-4"
                                        id="detail_geoip_container">
                                        <div class="col-span-2 py-8 sm:py-10 flex flex-col items-center">
                                            <i
                                                class="fas fa-spinner fa-spin text-xl sm:text-2xl mb-3 sm:mb-4 text-purple-500"></i>
                                            <p class="text-sm text-gray-500">Initiating geolocation analysis...</p>
                                        </div>
                                    </div>

                                    <!-- Traceroute Section -->
                                    <div class="border-t border-gray-100 dark:border-gray-800 pt-4 sm:pt-2">
                                        <div
                                            class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 sm:gap-4 mb-3 sm:mb-4">
                                            <h4
                                                class="text-xs sm:text-sm font-bold uppercase tracking-wider text-gray-500">
                                                Routing Path</h4>
                                            <div class="flex bg-gray-100 dark:bg-gray-800 p-1 rounded-lg">
                                                <button id="btn-detail-trace-visual"
                                                    onclick="setDetailTracerouteView('visual')"
                                                    class="px-2 sm:px-3 py-1 text-[10px] font-bold rounded-md transition-all bg-white dark:bg-gray-700 shadow-sm text-indigo-600 dark:text-indigo-400">VISUAL</button>
                                                <button id="btn-detail-trace-raw"
                                                    onclick="setDetailTracerouteView('raw')"
                                                    class="px-2 sm:px-3 py-1 text-[10px] font-bold rounded-md transition-all text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">RAW</button>
                                            </div>
                                        </div>

                                        <div id="detail_traceroute_visual"
                                            class="min-h-[200px] max-h-[350px] bg-gray-50 dark:bg-gray-800/50 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-inner overflow-y-auto">
                                            <div
                                                class="flex flex-col items-center justify-center py-8 sm:py-10 text-gray-400 italic text-sm">
                                                Click "Run Traceroute" to analyze path...
                                            </div>
                                        </div>
                                        <pre id="detail_traceroute_raw"
                                            class="hidden min-h-[200px] max-h-[350px] m-0 p-3 sm:p-6 bg-black text-green-400 font-mono text-[10px] sm:text-xs overflow-auto rounded-2xl whitespace-pre-wrap leading-relaxed opacity-90">-- Raw output --</pre>

                                        <div class="mt-3 sm:mt-4">
                                            <button id="btnRunDetailTraceroute" onclick="runDetailTraceroute()"
                                                class="w-full py-2 sm:py-3 bg-indigo-500 hover:bg-indigo-600 text-white font-bold text-sm sm:text-base rounded-xl transition-all shadow-lg shadow-indigo-500/20 flex items-center justify-center gap-2">
                                                <i class="fas fa-route"></i> <span class="hidden sm:inline">Run
                                                    Traceroute</span><span class="sm:hidden">Traceroute</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- AI Report Tab Content -->
                            <div id="ipDetailContentAIReport" class="hidden h-full flex flex-col">
                                <div
                                    class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 sm:gap-4 mb-3 sm:mb-4">
                                    <h4 class="text-xs sm:text-sm font-bold uppercase tracking-wider text-gray-500">AI
                                        Diagnostic Report</h4>
                                    <button onclick="copyAIReportDetail()"
                                        class="px-2 sm:px-3 py-1.5 bg-emerald-500 hover:bg-emerald-600 text-white text-[10px] sm:text-xs font-bold rounded-lg transition-all flex items-center gap-2 shadow-lg shadow-emerald-500/20 whitespace-nowrap">
                                        <i class="fas fa-copy"></i> <span class="hidden sm:inline">COPY
                                            REPORT</span><span class="sm:hidden">COPY</span>
                                    </button>
                                </div>
                                <div
                                    class="flex-1 bg-gray-900 rounded-2xl border border-gray-800 p-3 sm:p-6 overflow-y-auto custom-scrollbar font-mono text-[10px] sm:text-xs text-emerald-400/90 leading-relaxed shadow-inner min-h-[300px]">
                                    <div id="detail_aireport_content">Generating comprehensive report...</div>
                                </div>
                                <p class="text-[10px] text-gray-500 mt-4 italic">
                                    <i class="fas fa-info-circle mr-1"></i> This summary includes network latency
                                    trends, geolocation and status for advanced AI analysis.
                                </p>
                            </div>
                        </div>

                        <!-- Modal Footer -->
                        <div
                            class="p-4 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 flex justify-between items-center shrink-0">
                            <button type="button" onclick="closeIpModal()"
                                class="px-6 py-2 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-600 rounded-lg hober:bg-gray-100 dark:hover:bg-gray-600 font-bold transition-all shadow-sm">
                                Close
                            </button>
                            <button type="button" onclick="showDeleteConfirmFromDetail()"
                                class="px-6 py-2 bg-red-500 hover:bg-red-600 text-white font-bold rounded-lg transition-all shadow-lg shadow-red-500/20 flex items-center gap-2">
                                <i class="fas fa-trash-alt"></i> Delete IP
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Modal para cambiar servicio de una IP -->
                <div id="changeIpServiceModal"
                    class="fixed inset-0 z-[60] flex items-center justify-center bg-black bg-opacity-50 hidden">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-md relative">
                        <button onclick="closeChangeIpServiceModal()"
                            class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                        <div class="flex items-center mb-6">
                            <i class="fas fa-edit text-blue-500 text-2xl mr-3"></i>
                            <h3 class="text-xl font-bold text-gray-800 dark:text-gray-200">
                                <?php echo isset($is_local_network) && $is_local_network ? 'Change Host' : 'Change Service'; ?>
                            </h3>
                        </div>
                        <form method="POST" action="">
                            <input type="hidden" id="change_service_ip" name="update_ip_service">
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Host IP /
                                    Domain</label>
                                <input type="text" id="change_service_ip_display" disabled
                                    class="w-full p-2.5 bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-500 dark:text-gray-400 font-mono">
                            </div>
                            <?php if ($is_local_network): ?>
                                <div class="mb-5">
                                    <label for="new_device_name"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Device
                                        Name</label>

                                    <!-- Quick Select for special devices -->
                                    <div
                                        class="flex items-center gap-4 mb-3 p-2 bg-gray-50 dark:bg-gray-900/50 rounded-lg border border-gray-100 dark:border-gray-800">
                                        <label class="flex items-center gap-2 cursor-pointer group">
                                            <input type="checkbox" id="check_is_gateway"
                                                onchange="handleSpecialDeviceCheck('gateway')"
                                                class="w-4 h-4 text-blue-600 rounded border-gray-300 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600">
                                            <span
                                                class="text-[10px] font-black uppercase tracking-widest text-gray-500 group-hover:text-blue-500 transition-colors">Gateway</span>
                                        </label>
                                        <label class="flex items-center gap-2 cursor-pointer group">
                                            <input type="checkbox" id="check_is_repeater"
                                                onchange="handleSpecialDeviceCheck('repeater')"
                                                class="w-4 h-4 text-red-600 rounded border-gray-300 focus:ring-red-500 dark:bg-gray-700 dark:border-gray-600">
                                            <span
                                                class="text-[10px] font-black uppercase tracking-widest text-gray-500 group-hover:text-red-500 transition-colors">Repetidor</span>
                                        </label>
                                    </div>

                                    <input type="text" id="new_device_name" name="new_device_name"
                                        class="w-full p-2.5 bg-gray-50 border border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-white rounded-lg focus:ring-blue-500 focus:border-blue-500"
                                        required>
                                </div>
                                <div class="mb-5">
                                    <label for="new_network_type"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Network
                                        Connection</label>
                                    <div class="relative">
                                        <select id="new_network_type" name="new_network_type"
                                            class="w-full p-2.5 bg-gray-50 border border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-white rounded-lg focus:ring-blue-500 focus:border-blue-500 appearance-none"
                                            required>
                                            <option value="WiFi-2.4GHz">WiFi-2.4GHz</option>
                                            <option value="WiFi-5GHz">WiFi-5GHz</option>
                                            <option value="WiFi-6GHz">WiFi-6GHz</option>
                                            <option value="AP/Mesh">AP/Mesh</option>
                                            <option value="Ethernet">Ethernet</option>
                                        </select>
                                        <div
                                            class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700 dark:text-gray-300">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="mb-5">
                                    <label for="new_service_for_ip"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">New
                                        Service</label>
                                    <div class="relative">
                                        <select id="new_service_for_ip" name="new_service_name"
                                            onchange="toggleNewServiceFormInChangeModal()"
                                            class="w-full p-2.5 bg-gray-50 border border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-white rounded-lg focus:ring-blue-500 focus:border-blue-500 appearance-none"
                                            required>
                                            <?php foreach ($services as $service_name => $color): ?>
                                                <?php if ($service_name !== "DEFAULT"): ?>
                                                    <option
                                                        value="<?php echo htmlspecialchars($service_name, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <?php echo htmlspecialchars($service_name, ENT_QUOTES, 'UTF-8'); ?>
                                                    </option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                            <option value="create_new">➕ Create New Service</option>
                                        </select>
                                        <div
                                            class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700 dark:text-gray-300">
                                            <i class="fas fa-chevron-down"></i>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- New Service Creation -->
                            <div id="newServiceForIpForm" style="display: none;"
                                class="mb-5 p-4 bg-blue-50 dark:bg-blue-900/30 rounded-lg border border-blue-200 dark:border-blue-700">
                                <div class="mb-3">
                                    <label for="new_service_inline_name"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Service
                                        Name</label>
                                    <input type="text" id="new_service_inline_name" name="new_service_inline_name"
                                        placeholder="e.g. Critical Server"
                                        class="w-full p-2.5 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:text-white">
                                </div>
                                <div class="mb-3">
                                    <label for="new_service_inline_color"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Color</label>
                                    <div class="flex items-center space-x-3">
                                        <input type="color" id="new_service_inline_color"
                                            name="new_service_inline_color" value="#3b82f6"
                                            class="h-10 w-16 rounded border border-gray-300 cursor-pointer dark:bg-gray-700 dark:border-gray-600">
                                        <div class="flex-1">
                                            <div class="w-full h-10 rounded shadow-sm border border-gray-200 dark:border-gray-700"
                                                id="new_service_inline_color_preview" style="background-color: #3b82f6">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-end gap-3 mt-6">
                                <button type="button" onclick="closeChangeIpServiceModal()"
                                    class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                                    Cancel
                                </button>
                                <button type="submit" name="confirm_update_ip_service"
                                    class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 shadow-md transition-all active:scale-95">
                                    <i class="fas fa-save mr-2"></i> Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-gradient-to-r from-gray-800 to-gray-900 dark:from-gray-900 dark:to-black text-white py-8 mt-12">
        <div class="container mx-auto px-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Project Info -->
                <div class="text-center md:text-left">
                    <div class="flex items-center justify-center md:justify-start mb-4">
                        <i class="fas fa-network-wired text-2xl text-blue-400 mr-3"></i>
                        <h3 class="text-xl font-bold">IP Monitor</h3>
                    </div>
                    <p class="text-gray-300 text-sm leading-relaxed">
                        A powerful and elegant network monitoring solution to keep track of your critical
                        infrastructure.
                    </p>
                </div>

                <!-- Links -->
                <div class="text-center">
                    <h4 class="text-lg font-semibold mb-4 text-blue-400">Resources</h4>
                    <div class="space-y-2">
                        <a href="https://github.com/negociatumente/monitor-ip" target="_blank"
                            class="block text-gray-300 hover:text-white transition-colors duration-200 text-sm">
                            <i class="fab fa-github mr-2"></i>Source Code
                        </a>
                        <a href="https://negociatumente.com/guia-redes/" target="_blank"
                            class="block text-gray-300 hover:text-white transition-colors duration-200 text-sm">
                            <i class="fas fa-book mr-2"></i>Documentation
                        </a>
                        <a href="https://negociatumente.com" target="_blank"
                            class="block text-gray-300 hover:text-white transition-colors duration-200 text-sm">
                            <i class="fas fa-globe mr-2"></i>Visit Website
                        </a>
                    </div>
                </div>

                <!-- Author & Copyright -->
                <div class="text-center md:text-right">
                    <h4 class="text-lg font-semibold mb-4 text-blue-400">Developer</h4>
                    <div class="space-y-2">
                        <a href="https://negociatumente.com" target="_blank"
                            class="block text-gray-300 hover:text-white transition-colors duration-200 text-sm">
                            <i class="fas fa-user mr-2"></i>Antonio Cañavate
                        </a>
                        <p class="text-gray-400 text-xs">
                            &copy; <?php echo date('Y'); ?> All rights reserved
                        </p>
                        <p class="text-gray-500 text-xs">
                            Made with <i class="fas fa-heart text-red-400"></i> for the community
                        </p>
                    </div>
                </div>
            </div>

            <!-- Bottom bar -->
            <div class="border-t border-gray-700 mt-8 pt-6 text-center">
                <p class="text-gray-400 text-xs">
                    IP Monitor Dashboard • Open Source Network Monitoring Tool • Version
                    <?php echo isset($config['settings']['version']) ? htmlspecialchars($config['settings']['version'], ENT_QUOTES, 'UTF-8') : '1.0'; ?>
                </p>
            </div>
        </div>
    </footer>


    <!-- Modal: Network Health Analysis -->
    <div id="networkHealthModal"
        class="fixed inset-0 z-[70] flex items-center justify-center bg-black/60 backdrop-blur-sm hidden">
        <div
            class="bg-white dark:bg-gray-900 w-full max-w-2xl rounded-3xl shadow-2xl transition-all border border-gray-200 dark:border-gray-800 overflow-hidden">

            <div class="p-4 bg-indigo-500 text-white relative">
                <div class="flex justify-between items-start relative z-10">
                    <div class="flex items-center gap-4">
                        <div class="p-4 bg-white/20 rounded-2xl backdrop-blur-xl shadow-inner">
                            <i class="fas fa-heartbeat text-4xl"></i>
                        </div>
                        <div>
                            <h3 class="text-3xl font-extrabold tracking-tight">Network Health</h3>
                            <p class="text-blue-100/80 text-sm mt-1 flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-green-400 animate-pulse"></span>
                                Network Performance Analysis
                            </p>
                        </div>
                    </div>
                    <button onclick="closeNetworkHealthModal()"
                        class="text-white/50 hover:text-white transition-colors">
                        <i class="fas fa-times text-2xl"></i>
                    </button>
                </div>
            </div>

            <div id="network_health_content"
                class="p-4 max-h-[70vh] overflow-y-auto custom-scrollbar bg-gray-50/50 dark:bg-gray-950/50">
                <!-- Data will be injected here -->
                <div class="flex flex-col items-center justify-center py-20 animate-pulse">
                    <i class="fas fa-circle-notch fa-spin text-4xl mb-4 text-indigo-500"></i>
                    <p class="text-gray-500 font-bold uppercase tracking-widest text-xs">Evaluating network
                        performance...</p>
                </div>
            </div>

            <div
                class="p-6 bg-white dark:bg-gray-900 border-t border-gray-100 dark:border-gray-800 flex justify-end items-center">
                <div class="flex gap-2">
                    <button onclick="generateNetworkHealthAIReport()"
                        class="px-6 py-3 bg-emerald-500 text-white font-bold rounded-xl hover:bg-emerald-600 transition-all active:scale-95 flex items-center gap-2 shadow-lg shadow-emerald-500/20">
                        <i class="fas fa-robot"></i> AI Report
                    </button>

                    <button onclick="closeNetworkHealthModal()"
                        class="px-8 py-3 bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 font-bold rounded-xl hover:bg-gray-200 dark:hover:bg-gray-700 transition-all active:scale-95">
                        Close Analysis
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Network Topology Map (Wide) -->
    <div id="topologyModal"
        class="fixed inset-0 z-[70] flex items-center justify-center bg-black/60 backdrop-blur-sm hidden">
        <div
            class="bg-white dark:bg-gray-900 w-[95%] max-w-[1400px] h-[85vh] rounded-3xl shadow-2xl transition-all border border-gray-200 dark:border-gray-800 overflow-hidden flex flex-col">

            <div class="p-6 bg-purple-600 text-white flex justify-between items-center shadow-lg">
                <div class="flex items-center gap-4">
                    <div class="p-3 bg-white/20 rounded-xl backdrop-blur-md">
                        <i class="fas fa-sitemap text-2xl"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-extrabold tracking-tight">Interactive Network Topology</h3>
                        <p class="text-indigo-100/70 text-[10px] uppercase font-bold tracking-widest mt-0.5">
                            Automated
                            Infrastructure Logic</p>
                    </div>
                </div>
                <div class="flex items-center gap-4">

                    <!-- Zoom Controls -->
                    <div class="flex bg-black/20 p-1 rounded-xl mr-4">
                        <button onclick="zoomTopology(1.1)"
                            class="w-8 h-8 flex items-center justify-center hover:bg-white/10 rounded-lg transition-colors"
                            title="Zoom In">
                            <i class="fas fa-search-plus"></i>
                        </button>
                        <button onclick="zoomTopology(0.9)"
                            class="w-8 h-8 flex items-center justify-center hover:bg-white/10 rounded-lg transition-colors"
                            title="Zoom Out">
                            <i class="fas fa-search-minus"></i>
                        </button>
                        <button onclick="resetTopologyZoom()"
                            class="w-8 h-8 flex items-center justify-center hover:bg-white/10 rounded-lg transition-colors"
                            title="Reset Zoom">
                            <i class="fas fa-expand"></i>
                        </button>
                    </div>

                    <button onclick="closeTopologyModal()"
                        class="bg-black/20 hover:bg-black/40 w-10 h-10 rounded-full flex items-center justify-center transition-all">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <div id="full_topology_container"
                class="flex-1 overflow-auto p-0 bg-gray-50/50 dark:bg-gray-950/50 relative custom-scrollbar select-none">
                <!-- Map will be injected here -->
                <div class="flex items-center justify-center h-full text-gray-400 italic">
                    Building network graph...
                </div>
            </div>

            <div
                class="p-4 bg-white dark:bg-gray-900 border-t border-gray-100 dark:border-gray-800 flex justify-between items-center text-[10px]">
                <div class="flex gap-4">
                    <div class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-green-500"></span>
                        Online
                    </div>
                    <div class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-red-500"></span>
                        Offline
                    </div>
                </div>
                <div class="text-gray-400 font-mono italic">
                    * Click on any device to view detailed diagnostic statistics
                </div>
            </div>
        </div>
    </div>

    <style>
        .active-diag-tab {
            background-color: white !important;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }

        .dark .active-diag-tab {
            background-color: #1f2937 !important;
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 8px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: transparent;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #333;
            border-radius: 10px;
        }
    </style>

</body>

</html>