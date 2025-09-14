<?php

// Centralizar rutas
$functions_path = __DIR__ . '/lib/functions.php';
$config_path = __DIR__ . '/conf/config.ini';
$ping_file = __DIR__ . '/conf/ping_results.json';

// Require functions
require_once $functions_path;

// Cargar configuración desde config.ini
$config = parse_ini_file($config_path, true);
$ips_to_monitor = $config['ips'] ?? [];
$services = $config['services'] ?? [];
$ping_attempts = $config['settings']['ping_attempts'] ?? 5;
$ping_interval = $config['settings']['ping_interval'] ?? 30;

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
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?imported=1');
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
                $ip_entries[] = "'" . addslashes($ip) . "': " . json_encode([
                    'service' => $service,
                    'service_color' => $service_styling['color'],
                    'service_text_color' => $service_styling['text_color'],
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

</head>

<body class="bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200">
    <!-- Header/Navigation Bar -->
    <header class="bg-gradient-to-r from-blue-600 to-blue-800 text-white shadow-lg">
        <div class="container mx-auto py-4 px-6 flex flex-col md:flex-row justify-between items-center">
            <div class="flex items-center mb-4 md:mb-0">
                <i class="fas fa-network-wired text-2xl mr-3"></i>
                <h1 class="text-xl font-bold">IP Monitor Dashboard</h1>
            </div>
            <div class="flex flex-wrap justify-center gap-4">
                <a href="https://negociatumente.com" target="_blank"
                    class="text-white hover:text-blue-200 transition flex items-center gap-2">
                    <i class="fas fa-user"></i> Antonio Cañavate
                </a>
                <a href="https://github.com/negociatumente/monitor-ip" target="_blank"
                    class="text-white hover:text-blue-200 transition flex items-center gap-2">
                    <i class="fab fa-github"></i> GitHub
                </a>
                <a href="https://negociatumente.com/guia-redes/" target="_blank"
                    class="text-white hover:text-blue-200 transition flex items-center gap-2">
                    <i class="fas fa-book"></i> Aprender más
                </a>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-4 py-6">
        <!-- Notifications -->
        <?php if (isset($_GET['action'])): ?>
            <?php echo renderNotification($_GET['action'], $_GET['msg'] ?? null); ?>
        <?php endif; ?>

        <!-- Dash Menu -->
        <?php require_once __DIR__ . '/menu.php'; ?>


        <!-- IP Monitoring Table -->
        <div class='bg-white dark:bg-gray-800 rounded-lg shadow-sm overflow-hidden mb-8'>
            <div class='p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center'>
                <h2 class='text-lg font-semibold text-gray-800 dark:text-gray-200'>
                    <i class='fas fa-table mr-2'></i> IP Monitoring Table
                </h2>
                <div class='text-sm text-gray-500 dark:text-gray-400'>
                    <?php echo count($ips_to_monitor); ?> IPs monitored • Last updated: <?php echo date('H:i:s'); ?>
                </div>
            </div>
            <div class='overflow-x-auto'>
                <table class='min-w-max w-full'>
                    <thead>
                        <tr class='bg-gray-50 dark:bg-gray-700 text-left'>
                            <th class='p-3 whitespace-nowrap'>Service</th>
                            <th class='p-3 whitespace-nowrap'>IP Address</th>
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
                                if ($num_pings >= $max_pings_to_show && $i === 0) {
                                    $header_labels[] = 'now';
                                } else {
                                    $ping_index = $num_pings >= $max_pings_to_show ? $num_pings - $max_pings_to_show + $i : $i;
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
                    <tbody class='divide-y divide-gray-200 dark:divide-gray-700'>
                        <?php if (empty($ips_to_monitor)): ?>
                            <tr>
                                <td colspan='<?php echo 7 + $max_pings_to_show; ?>'
                                    class='p-4 text-center text-gray-500 dark:text-gray-400'>
                                    <div class='py-8'>
                                        <i class='fas fa-info-circle text-blue-500 text-4xl mb-3'></i>
                                        <p class='text-lg'>No IPs being monitored yet</p>
                                        <p class='text-sm'>Click "Add IP" button above to start monitoring</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($ips_to_monitor as $ip => $service): ?>
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
                                                    style='width: <?php echo $percentage_styling['percentage']; ?>%'></div>
                                            </div>
                                            <span class='font-medium <?php echo $percentage_styling['text_class']; ?>'>
                                                <?php echo $percentage_styling['percentage']; ?>%
                                            </span>
                                        </div>
                                    </td>
                                    <td class='p-3 font-medium <?php echo $response_styling['class']; ?>'>
                                        <?php
                                        if (is_numeric($average_response_time)) {
                                            echo number_format($average_response_time, 2).' ms';
                                        } else {
                                            echo $response_styling['display'];
                                        }
                                        ?>
                                    </td>
                                    <?php
                                    // Mostrar los pings de derecha a izquierda, primero celdas vacías (izquierda), luego pings reales (derecha)
                                    $max_pings_to_show = 5;
                                    $ping_results = $result['ping_results'];
                                    $num_pings = count($ping_results);
                                    $pings_to_show = array_slice($ping_results, -$max_pings_to_show);
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
                    <h3 class="text-xl font-bold text-gray-800 dark:text-gray-200" id="modalIpTitle">IP Detail</h3>
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
</body>

</html>