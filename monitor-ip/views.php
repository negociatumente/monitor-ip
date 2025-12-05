<?php

// Centralizar rutas (solo si no están definidas previamente por index.php)
if (!isset($functions_path))
    $functions_path = __DIR__ . '/lib/functions.php';
if (!isset($config_path))
    $config_path = __DIR__ . '/conf/config.ini';
if (!isset($ping_file))
    $ping_file = __DIR__ . '/conf/ping_results.json';

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

    <script>
        // Global variables
        let pingInterval = <?php echo $ping_interval; ?>;
        let countdown = pingInterval;
        let countdownInterval = null;
        let pingStopped = false;
        let currentPage = 1;
        const pingsPerPage = 5;

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
                ]);
            }
            echo implode(",\n", $ip_entries);
            ?>
        };
    </script>

    <script src="lib/script.js"></script>
    <script src="lib/network_scan.js"></script>

</head>

<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200">
    <!-- Header/Navigation Bar -->
    <header
        class="bg-gradient-to-r from-blue-600 via-blue-700 to-indigo-800 text-white shadow-2xl relative overflow-hidden">
        <!-- Background pattern -->
        <div class="absolute inset-0 opacity-10">
            <div
                class="absolute top-0 left-0 w-full h-full bg-gradient-to-br from-transparent via-white to-transparent transform rotate-12">
            </div>
        </div>

        <div class="container mx-auto py-4 px-6 relative z-10">
            <!-- Top row with logo and actions -->
            <div class="flex flex-col lg:flex-row justify-between items-center mb- lg:mb-0">
                <!-- Logo and title -->
                <div class="flex items-center mb-4 lg:mb-0 group">
                    <div
                        class="bg-white bg-opacity-20 p-3 rounded-full mr-4 group-hover:bg-opacity-30 transition-all duration-300">
                        <i class="fas fa-network-wired text-2xl text-white"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl lg:text-3xl font-bold tracking-tight">
                            IP Monitor
                            <?php if (isset($config['settings']['version'])): ?>
                                <span class="text-sm font-normal bg-white bg-opacity-20 px-2 py-1 rounded-full ml-2">
                                    v<?php echo htmlspecialchars($config['settings']['version'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            <?php endif; ?>
                        </h1>
                        <p class="text-blue-100 text-sm mt-1">Real-time network monitoring & analytics</p>
                    </div>
                </div>

                <!-- Navigation links -->
                <div
                    class="flex flex-wrap justify-center lg:justify-end gap-2 pt-4 border-t border-white border-opacity-20">
                    <a href="https://nordvpn.com/es/pricing/" target="_blank"
                        class="bg-green-400 hover:bg-green-500 text-blue-900 font-bold px-4 py-2 rounded-lg transition-all duration-300 flex items-center gap-2 text-sm shadow-lg transform hover:scale-105">
                        <i class="fas fa-shield-alt"></i>
                        <span class="hidden sm:inline">Get Secure VPN</span>
                    </a>
                    <a href="https://negociatumente.com" target="_blank"
                        class="bg-white bg-opacity-10 hover:bg-opacity-20 px-4 py-2 rounded-lg transition-all duration-300 flex items-center gap-2 text-sm backdrop-blur-sm">
                        <i class="fas fa-user"></i>
                        <span class="hidden sm:inline">Antonio Cañavate</span>
                    </a>
                    <a href="https://github.com/negociatumente/monitor-ip" target="_blank"
                        class="bg-white bg-opacity-10 hover:bg-opacity-20 px-4 py-2 rounded-lg transition-all duration-300 flex items-center gap-2 text-sm backdrop-blur-sm">
                        <i class="fab fa-github"></i>
                        <span class="hidden sm:inline">GitHub</span>
                    </a>
                    <a href="https://negociatumente.com/guia-redes/" target="_blank"
                        class="bg-white bg-opacity-10 hover:bg-opacity-20 px-4 py-2 rounded-lg transition-all duration-300 flex items-center gap-2 text-sm backdrop-blur-sm">
                        <i class="fas fa-book"></i>
                        <span class="hidden sm:inline">Learn More</span>
                    </a>
                </div>
            </div>
        </div>

    </header>

    <div class="container mx-auto px-4 py-4">
        <!-- Notifications -->
        <?php if (isset($_GET['action'])): ?>
            <?php echo renderNotification($_GET['action'], $_GET['msg'] ?? null); ?>
        <?php endif; ?>

        <!-- Dash Menu -->
        <?php require_once __DIR__ . '/menu.php'; ?>


        <!-- Network Type Tabs -->
        <div class="mb-2">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-1 inline-flex">
                <button onclick="switchNetworkType('external')" id="externalTab"
                    class="network-tab px-6 py-2 rounded-md text-sm font-medium transition-all active">
                    <i class="fas fa-globe mr-2"></i> External Network
                </button>
                <button onclick="switchNetworkType('local')" id="localTab"
                    class="network-tab px-6 py-2 rounded-md text-sm font-medium transition-all">
                    <i class="fas fa-home mr-2"></i> Local Network
                </button>
            </div>
        </div>

        <!-- IP Monitoring Table -->
        <div class='bg-white dark:bg-gray-800 rounded-lg shadow-sm overflow-hidden'>
            <div class='p-2 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center'>
                <h2 class='text-lg font-semibold text-gray-800 dark:text-gray-200'>
                    <i class='fas fa-table mr-2'></i> IP Monitoring Table
                </h2>
                <div class='text-sm text-gray-500 dark:text-gray-400'>
                    <?php echo count($ips_to_monitor); ?> Hosts monitored • Last updated: <?php echo date('H:i:s'); ?>
                </div>
            </div>

            <!-- Filters -->
            <div
                class="p-4 bg-gray-50 dark:bg-gray-700/30 border-b border-gray-200 dark:border-gray-700 flex flex-wrap gap-4 items-center">
                <div class="flex-1 min-w-[200px]">
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                        <input type="text" id="searchFilter" onkeyup="filterTable()"
                            placeholder="Search IP or Domain..."
                            class="w-full pl-10 p-2 text-sm bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                    </div>
                </div>
                <div class="w-full sm:w-auto">
                    <select id="serviceFilter" onchange="filterTable()"
                        class="w-full p-2 text-sm bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                        <option value="">All Services</option>
                        <?php foreach ($services as $service_name => $color): ?>
                            <?php if ($service_name !== "DEFAULT"): ?>
                                <option value="<?php echo htmlspecialchars($service_name); ?>">
                                    <?php echo htmlspecialchars($service_name); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="w-full sm:w-auto">
                    <select id="statusFilter" onchange="filterTable()"
                        class="w-full p-2 text-sm bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                        <option value="">All Statuses</option>
                        <option value="UP">UP</option>
                        <option value="DOWN">DOWN</option>
                    </select>
                </div>
                <button onclick="resetFilters()"
                    class="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                    Reset Filters
                </button>
            </div>
            <div class='overflow-x-auto'>
                <table class='min-w-max w-full'>
                    <thead>
                        <tr class='bg-gray-50 dark:bg-gray-700 text-left'>
                            <th class='p-3 whitespace-nowrap'>Service</th>
                            <th class='p-3 whitespace-nowrap'>IP / Domain</th>
                            <th class='p-3 whitespace-nowrap'>Method</th>
                            <th class='p-3 whitespace-nowrap'>Status</th>
                            <th class='p-3 whitespace-nowrap'>Reliability</th>
                            <th class='p-3 whitespace-nowrap'>Uptime</th>
                            <th class='p-3 whitespace-nowrap'>LATENCY</th>
                            <?php
                            // Encabezados de pings (de derecha a izquierda)
                            $max_pings_to_show = 5;
                            $sample_ip = array_key_last($ping_data);
                            $latest_pings = $ping_data[$sample_ip] ?? [];
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
                                        <p class='text-sm'>Click "Add IP / Domain" button above to start monitoring</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php
                            // Pagination logic
                            $items_per_page = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 10;
                            $current_page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
                            $total_ips = count($ips_to_monitor);
                            $total_pages = max(1, ceil($total_ips / $items_per_page));
                            $current_page = min($current_page, $total_pages);
                            $offset = ($current_page - 1) * $items_per_page;

                            // Get IPs for current page
                            $ips_on_page = array_slice($ips_to_monitor, $offset, $items_per_page, true);
                            ?>
                            <?php foreach ($ips_on_page as $ip => $service): ?>
                                <?php
                                $result = analyze_ip($ip);
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

                                // Get monitoring method
                                $config = parse_ini_file($config_path, true);
                                $services_methods = $config['services-methods'] ?? [];
                                $method = $services_methods[$service] ?? ($services_methods['DEFAULT'] ?? 'icmp');
                                $method_display = strtoupper($method);
                                $method_icon = $method === 'icmp' ? 'network-wired' : ($method === 'curl' ? 'globe' : 'server');
                                ?>
                                <tr class='hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-all duration-150 cursor-pointer'
                                    onclick="showIpDetailModal('<?php echo htmlspecialchars($ip, ENT_QUOTES, 'UTF-8'); ?>')">
                                    <td class='p-3'>
                                        <span class='inline-block px-3 py-1 rounded-full text-sm font-medium'
                                            style='background-color: <?php echo $service_styling['color']; ?>; color: <?php echo $service_styling['text_color']; ?>'>
                                            <?php echo $service; ?>
                                        </span>
                                    </td>
                                    <td class='p-3 font-mono text-sm'><?php echo $ip; ?></td>
                                    <td class='p-3'>
                                        <span
                                            class='inline-block px-2 py-1 rounded text-xs font-medium bg-gray-100 dark:bg-gray-600 text-gray-700 dark:text-gray-300'>
                                            <i class='fas fa-<?php echo $method_icon; ?> mr-1'></i>
                                            <?php echo $method_display; ?>
                                        </span>
                                    </td>
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
                                        <button type='button'
                                            onclick="event.stopPropagation();confirmDelete('<?php echo htmlspecialchars($ip, ENT_QUOTES, 'UTF-8'); ?>')"
                                            class='btn bg-red-100 text-red-700 hover:bg-red-200 dark:bg-red-900 dark:text-red-300 dark:hover:bg-red-800 p-2 rounded-md'>
                                            <i class='fas fa-trash-alt'></i>
                                        </button>
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
                            <label for="perPageSelect" class="text-sm text-gray-600 dark:text-gray-400">Show:</label>
                            <select id="perPageSelect" onchange="changePerPage(this.value)"
                                class="p-2 text-sm bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                <option value="10" <?php echo $items_per_page == 10 ? 'selected' : ''; ?>>10</option>
                                <option value="25" <?php echo $items_per_page == 25 ? 'selected' : ''; ?>>25</option>
                                <option value="50" <?php echo $items_per_page == 50 ? 'selected' : ''; ?>>50</option>
                                <option value="100" <?php echo $items_per_page == 100 ? 'selected' : ''; ?>>100</option>
                                <option value="<?php echo $total_ips; ?>" <?php echo $items_per_page >= $total_ips ? 'selected' : ''; ?>>All</option>
                            </select>
                            <span class="text-sm text-gray-600 dark:text-gray-400">of <?php echo $total_ips; ?> hosts</span>
                        </div>

                        <!-- Page info -->
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $items_per_page, $total_ips); ?>
                            of <?php echo $total_ips; ?> hosts
                        </div>

                        <!-- Page navigation -->
                        <div class="flex items-center gap-2">
                            <?php if ($current_page > 1): ?>
                                <a href="?page=<?php echo $current_page - 1; ?>&per_page=<?php echo $items_per_page; ?>"
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
                                    <a href="?page=1&per_page=<?php echo $items_per_page; ?>"
                                        class="px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                        1
                                    </a>
                                    <?php if ($start_page > 2): ?>
                                        <span class="px-2 text-gray-500">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <?php if ($i == $current_page): ?>
                                        <span class="px-3 py-2 text-sm bg-blue-500 text-white rounded-lg"><?php echo $i; ?></span>
                                    <?php else: ?>
                                        <a href="?page=<?php echo $i; ?>&per_page=<?php echo $items_per_page; ?>"
                                            class="px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <?php if ($end_page < $total_pages): ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <span class="px-2 text-gray-500">...</span>
                                    <?php endif; ?>
                                    <a href="?page=<?php echo $total_pages; ?>&per_page=<?php echo $items_per_page; ?>"
                                        class="px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                        <?php echo $total_pages; ?>
                                    </a>
                                <?php endif; ?>
                            </div>

                            <?php if ($current_page < $total_pages): ?>
                                <a href="?page=<?php echo $current_page + 1; ?>&per_page=<?php echo $items_per_page; ?>"
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
            class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 hidden">
            <div
                class="bg-white dark:bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-2xl relative max-h-[90vh] overflow-y-auto">
                <button onclick="closeIpModal()"
                    class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 z-10">
                    <i class="fas fa-times text-xl"></i>
                </button>
                <div class="flex items-center mb-6">
                    <i class="fas fa-network-wired text-blue-500 text-2xl mr-3"></i>
                    <h3 class="text-xl font-bold text-gray-800 dark:text-gray-200" id="modalIpTitle">Host Detail</h3>
                </div>
                <div id="modalIpContent"></div>

                <div class="mt-6">
                    <h4 class="text-md font-semibold text-gray-700 dark:text-gray-300 mb-3 flex items-center">
                        <i class="fas fa-chart-line mr-2 text-blue-500"></i>
                        Latency History
                    </h4>
                    <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                        <canvas id="pingChart" width="400" height="200"></canvas>
                    </div>
                </div>
                <div class="flex mt-2 gap-2 justify-between">
                    <button type="button" onclick="closeIpModal()"
                        class="btn bg-gray-300 text-gray-800 px-4 py-2 rounded-lg hover:bg-gray-400 flex items-center gap-2">
                        <i class="fas fa-times"></i> Close
                    </button>
                    <button type="button" onclick="showDeleteConfirmFromDetail()"
                        class="btn bg-red-500 text-white px-4 py-2 rounded-lg hover:bg-red-600 flex items-center gap-2">
                        <i class="fas fa-trash-alt"></i> Delete IP
                    </button>
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
</body>

</html>