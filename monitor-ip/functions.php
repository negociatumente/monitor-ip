<?php
// Función para realizar un ping y actualizar los datos
function update_ping_results($ip) {
    global $ping_attempts, $ping_data;
    
    // Detectar si el sistema es Windows
    $isWindows = (PHP_OS_FAMILY === 'Windows');

    // Comando de ping según el sistema operativo
    $pingCommand = $isWindows ? "ping -n 1 -w 1000 $ip" : "ping -c 1 -W 1 $ip";
    
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
        preg_match('/time[=<]\s*(\d+\.\d+ ms)/', $ping, $matches);
    }
    $response_time = $matches[1] ?? 'N/A';

    if (!isset($ping_data[$ip])) {
        $ping_data[$ip] = [];
    }

    array_unshift($ping_data[$ip], [
        "status" => $ping_status,
        "timestamp" => $timestamp,
        "response_time" => $response_time,
    ]);
    if (count($ping_data[$ip]) > $ping_attempts) {
        // Mantiene solo los últimos registros
        array_pop($ping_data[$ip]);  
    }
}

// Función para calcular el estado de la IP
function analyze_ip($ip) {
    global $ping_data;

    $ping_results = $ping_data[$ip] ?? array_fill(0, 5, ["status" => "-", "timestamp" => "-", "response_time" => "-", "packet_loss" => "-"]);
    $success_count = 0;
    $total_response_time = 0;
    $response_time_count = 0;

    foreach ($ping_results as $ping) {
        if ($ping['status'] === "UP") {
            $success_count++;
        }
        if ($ping['response_time'] !== 'N/A' && $ping['response_time'] !== '-') {
            $response_time = floatval(str_replace(['ms', ' '], '', $ping['response_time']));
            $total_response_time += $response_time;
            $response_time_count++;
        }
    }
    $percentage = ($success_count / count($ping_results)) * 100;
    $status = ($percentage > 50) ? "UP" : "DOWN";

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
        'label' => $label,
        'average_response_time' => $average_response_time
    ];
}

// Función para eliminar una IP del archivo config.ini y ping_results.json
function delete_ip_from_config($ip) {
    global $ping_file, $ping_data;

    // Eliminar del archivo config.ini
    $config = parse_ini_file('config.ini', true);
    if (isset($config['ips'][$ip])) {
        unset($config['ips'][$ip]);
        $new_content = '';
        foreach ($config as $section => $values) {
            $new_content .= "[$section]\n";
            foreach ($values as $key => $value) {
                $new_content .= "$key = \"$value\"\n";
            }
        }
        file_put_contents('config.ini', $new_content);
    }

    // Eliminar del archivo ping_results.json
    if (isset($ping_data[$ip])) {
        unset($ping_data[$ip]);
        file_put_contents($ping_file, json_encode($ping_data));
    }
}

// Función para agregar una IP al archivo config.ini
function add_ip_to_config($ip, $service) {
    $config = parse_ini_file('config.ini', true);
    $config['ips'][filter_var($ip, FILTER_VALIDATE_IP)] = htmlspecialchars($service, ENT_QUOTES, 'UTF-8');
    $new_content = '';
    foreach ($config as $section => $values) {
        $new_content .= "[$section]\n";
        foreach ($values as $key => $value) {
            $new_content .= "$key = \"$value\"\n";
        }
    }
    file_put_contents('config.ini', $new_content);
}
?>