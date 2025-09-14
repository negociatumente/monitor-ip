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
    $import_export_message = import_config_ini($_FILES['import_config']);
}
if (isset($_GET['export_config'])) {
    export_config_ini();
    exit;
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

    <script>
        // Global variables
        let pingInterval = <?php echo $ping_interval; ?>;
        let countdown = pingInterval;
        let countdownInterval = null;
        let pingStopped = false;
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
                <table class='w-full'>
                    <thead>
                        <tr class='bg-gray-50 dark:bg-gray-700 text-left'>
                            <th class='p-3 whitespace-nowrap'>Service</th>
                            <th class='p-3 whitespace-nowrap'>IP Address</th>
                            <th class='p-3 whitespace-nowrap'>Status</th>
                            <th class='p-3 whitespace-nowrap'>Reliability</th>
                            <th class='p-3 whitespace-nowrap'>Uptime %</th>
                            <th class='p-3 whitespace-nowrap'>Response</th>
                            <?php
                            $sample_ip = array_key_last($ping_data);
                            $latest_pings = $ping_data[$sample_ip] ?? array_fill(0, $ping_attempts, ["timestamp" => "-"]);
                            for ($i = 0; $i < $ping_attempts; $i++) {
                                $timestamp = $latest_pings[$i]['timestamp'] ?? "-";
                                if ($timestamp !== '-') {
                                    $date = new DateTime($timestamp);
                                    $now = new DateTime();
                                    if ($date->format('Y-m-d') === $now->format('Y-m-d')) {
                                        $timestamp = $date->format('H:i:s');
                                    }
                                }
                                echo "<th class='p-2 text-center text-xs whitespace-nowrap'>{$timestamp}</th>";
                            }
                            ?>
                            <th class='p-3 text-center whitespace-nowrap'>Actions</th>
                        </tr>
                    </thead>
                    <tbody class='divide-y divide-gray-200 dark:divide-gray-700'>
                        <?php if (empty($ips_to_monitor)): ?>
                            <tr>
                                <td colspan='<?php echo 7 + $ping_attempts; ?>'
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
                                <tr class='hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-all duration-150'>
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
                                        <?php echo $response_styling['display']; ?>
                                    </td>
                                    <?php
                                    foreach ($ping_results as $ping) {
                                        if (($ping['status'] ?? "-") === "UP") {
                                            echo "<td class='p-2 text-center'><span class='inline-block w-4 h-4 rounded-full bg-green-500 dark:bg-green-400' title='UP - " . ($ping['response_time'] ?? 'N/A') . "'></span></td>";
                                        } else {
                                            echo "<td class='p-2 text-center'><span class='inline-block w-4 h-4 rounded-full bg-red-500 dark:bg-red-400' title='DOWN'></span></td>";
                                        }
                                    }
                                    for ($i = count($ping_results); $i < $ping_attempts; $i++) {
                                        echo "<td class='p-2 text-center'><span class='inline-block w-4 h-4 rounded-full bg-gray-200 dark:bg-gray-600'></span></td>";
                                    }
                                    ?>
                                    <td class='p-3 text-center'>
                                        <button type='button' onclick="confirmDelete('<?php echo $ip; ?>')"
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
    </div>
</body>

</html>