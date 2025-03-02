<?php
// Cargar configuración desde config.php
$config = require 'config.php';
$ips_to_monitor = $config['ips'];
$ping_attempts = $config['ping_attempts'];

$ping_file = 'ping_results.json';

// Cargar resultados previos si existen
if (file_exists($ping_file)) {
    $ping_data = json_decode(file_get_contents($ping_file), true);
} else {
    $ping_data = [];
}

// Función para realizar un ping y actualizar los datos
function update_ping_results($ip) {
    global $ping_attempts, $ping_data;

    $ping = shell_exec("ping -c 1 -W 1 $ip");
    $ping_status = (strpos($ping, '1 received') !== false) ? "UP" : "DOWN";

    if (!isset($ping_data[$ip])) {
        $ping_data[$ip] = [];
    }

    array_unshift($ping_data[$ip], $ping_status);
    if (count($ping_data[$ip]) > $ping_attempts) {
        array_pop($ping_data[$ip]);
    }
}

// Ejecutar un solo ping por ciclo
foreach ($ips_to_monitor as $ip => $service) {
    update_ping_results($ip);
}

// Guardar resultados actualizados en JSON
file_put_contents($ping_file, json_encode($ping_data));

// Función para calcular el estado de la IP
function analyze_ip($ip) {
    global $ping_data;

    $ping_results = $ping_data[$ip] ?? array_fill(0, 5, "N/A");
    $success_count = array_count_values($ping_results)["UP"] ?? 0;
    $percentage = ($success_count / 5) * 100;
    $status = ($percentage >= 60) ? "UP" : "DOWN";

    if ($percentage >= 80) {
        $label = "Good";
    } elseif ($percentage >= 60) {
        $label = "Stable";
    } else {
        $label = "Critical";
    }

    return [
        'status' => $status,
        'percentage' => $percentage,
        'ping_results' => $ping_results,
        'label' => $label
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IP Monitor</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 dark:bg-gray-900 text-gray-800 dark:text-gray-200">
    <div class="container mx-auto p-4">
        <h1 class="text-3xl font-bold text-center my-6">IP Status Monitor</h1>
        <div class="overflow-x-auto">
            <table class="w-full max-w-5xl mx-auto border-collapse shadow-lg rounded-lg overflow-hidden">
                <thead>
                    <tr class="bg-gray-200 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                        <th class="p-3">Service</th>
                        <th class="p-3">IP Address</th>
                        <th class="p-3">Status</th>
                        <th class="p-3">Label</th>
                        <th class="p-3">Percentage</th>
                        <th class="p-3" colspan="5">Recent Pings</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ips_to_monitor as $ip => $service): ?>
                        <?php
                            $result = analyze_ip($ip);
                            $status = $result['status'];
                            $percentage = $result['percentage'];
                            $label = $result['label'];
                            $ping_results = $result['ping_results'];

                            $status_color = $status === "UP" ? "bg-green-500" : "bg-red-500";
                            $label_color = $label === "Good" ? "bg-green-400" : ($label === "Stable" ? "bg-yellow-400" : "bg-red-400");
                            $service_color = $service === "Cloudflare" ? "bg-blue-300" : ($service === "GitHub" ? "bg-gray-400" : "bg-gray-200");
                        ?>
                        <tr class="border-b border-gray-300 dark:border-gray-700">
                            <td class="p-3 <?php echo $service_color; ?> text-center font-semibold"><?php echo $service; ?></td>
                            <td class="p-3 text-center"><?php echo $ip; ?></td>
                            <td class="p-3 text-center <?php echo $status_color; ?> text-white font-bold rounded-lg"><?php echo $status; ?></td>
                            <td class="p-3 text-center <?php echo $label_color; ?> text-white font-bold rounded-lg"><?php echo $label; ?></td>
                            <td class="p-3 text-center"><?php echo number_format($percentage, 2); ?>%</td>
                            <?php foreach ($ping_results as $ping): ?>
                                <td class="p-3 text-center">
                                    <span class="<?php echo $ping === "UP" ? "text-green-500" : "text-red-500"; ?>">
                                        <?php echo $ping; ?>
                                    </span>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
