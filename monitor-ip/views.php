<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IP Monitor</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 50;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.75);
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            width: 25%;
            max-width: 400px;
            margin: auto;
        }
    </style>
    <script>
        let pingInterval = <?php echo $ping_interval; ?>;
        let countdown = pingInterval;

        function showAddIpForm() {
            document.getElementById('addIpForm').style.display = 'flex';
        }

        function hideAddIpForm() {
            document.getElementById('addIpForm').style.display = 'none';
        }
        
        function confirmDelete(ip) {
            document.getElementById('deleteIpForm').style.display = 'flex';
            document.getElementById('delete_ip').value = ip;
        }

        function hideDeleteIpForm() {
            document.getElementById('deleteIpForm').style.display = 'none';
        }

        function reloadPage() {
            window.location.href = window.location.pathname;
        }

        function updateCountdown() {
            document.getElementById('countdown').innerText = countdown;
            countdown--;
            if (countdown < 0) {
                countdown = pingInterval;
                reloadPage();
            }
        }

        // Temporizador para recargar la página a intervalos regulares
        setInterval(updateCountdown, 1000);
    </script>
</head>
<body class="bg-gray-100 dark:bg-gray-900 text-gray-800 dark:text-gray-200">
    <div class="container mx-auto p-4">
        <div class="text-center my-4">
        <p class="text-white text-lg bg-gray-800 bg-opacity-50 p-2 rounded-lg inline-block shadow-lg">Next ping in <span id="countdown" class="font-bold"><?php echo $ping_interval; ?></span> seconds!</p>
            <button onclick="reloadPage();" class="font-bold bg-yellow-500 text-white px-4 py-2 rounded hover:bg-yellow-700 transition duration-300">
                Check Status Now!
            </button>
            <button onclick="showAddIpForm();" class="font-bold bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-700 transition duration-300">
                Add IP
            </button>
        </div> 
        <div id="addIpForm" class="modal">
            <div class="modal-content">
                <h2 class="text-2xl font-bold mb-4">Add New IP</h2>
                <form method="POST" action="">
                    <div class="mb-4">
                        <label for="new_service" class="block text-gray-700">Service</label>
                        <input type="text" id="new_service" name="new_service" class="w-full p-2 border border-gray-300 rounded mt-1" required>
                    </div>
                    <div class="mb-4">
                        <label for="new_ip" class="block text-gray-700">IP Address</label>
                        <input type="text" id="new_ip" name="new_ip" class="w-full p-2 border border-gray-300 rounded mt-1" required>
                    </div>
                    <div class="flex justify-end">
                        <button type="button" onclick="hideAddIpForm();" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-700 transition duration-300 mr-2">Cancel</button>
                        <button type="submit" name="add_ip" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-700 transition duration-300">Add</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="deleteIpForm" class="modal">
            <div class="modal-content">
                <h2 class="text-2xl font-bold mb-4">Delete IP</h2>
                <form method="POST" action="">
                    <input type="hidden" id="delete_ip" name="delete_ip">
                    <p>Are you sure you want to delete this IP?</p>
                    <div class="flex justify-end mt-4">
                        <button type="button" onclick="hideDeleteIpForm();" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-700 transition duration-300 mr-2">Cancel</button>
                        <button type="submit" class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-700 transition duration-300">Delete</button>
                    </div>
                </form>
            </div>
        </div>
        <h1 class="text-4xl font-bold text-center my-6">IP Status Monitor</h1>
        <div class="overflow-x-auto">
            <table class="w-full max-w-5xl mx-auto border-collapse shadow-lg rounded-lg overflow-hidden">
                <thead>
                    <tr class="bg-gray-200 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                        <th class="p-3">Service</th>
                        <th class="p-3">IP Address</th>
                        <th class="p-3">Status</th>
                        <th class="p-3">Label</th>
                        <th class="p-3">Percentage</th>
                        <th class="p-3">Average Ping</th>
                        <?php 
                        // Obtener una IP de ejemplo para mostrar fechas (si existen datos previos)
                        $sample_ip = array_key_first($ping_data); 
                        $latest_pings = $ping_data[$sample_ip] ?? array_fill(0, 5, ["timestamp" => "-"]);
                        //Haz un for que vaya hasta el ping_attempts y muestre las fechas
                        for($i = 0; $i < 5; $i++) {
                            if(!isset($latest_pings[$i])) {
                                $latest_pings[$i] = ["timestamp" => "-"];
                            }
                            echo "<th class='p-2 text-center'>" . ($latest_pings[$i]['timestamp']) . "</th>";
                        }
                        ?>
                        <th class="p-3">Actions</th>
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
                            $average_response_time = $result['average_response_time'];

                            $status_color = $status === "UP" ? "bg-green-500" : "bg-red-500";
                            $label_color = $label === "Good" ? "bg-green-400" : ($label === "Stable" ? "bg-yellow-400" : "bg-red-400");
                            $service_color = $services[$service] ?? $services["DEFAULT"];

                             // Determinar el color del percentage
                             if ($percentage !== 'N/A') {
                                $percentage = round($percentage, 1);
                                if ($percentage > 80) {
                                    $response_time_percentage = "text-green-500";
                                } elseif ($percentage > 60) {
                                    $response_time_percentage = "text-yellow-500";
                                } else {
                                    $response_time_percentage = "text-red-500";
                                }
                            } else {
                                $response_time_percentage = "text-gray-500";
                            }
                            
                            // Determinar el color del tiempo de respuesta
                            if ($average_response_time !== 'N/A') {
                                $average_response_time = round($average_response_time, 1);
                                if ($average_response_time < 50) {
                                    $response_time_color = "text-green-500";
                                } elseif ($average_response_time < 100) {
                                    $response_time_color = "text-yellow-500";
                                } else {
                                    $response_time_color = "text-red-500";
                                }
                            } else {
                                $response_time_color = "text-gray-500";
                            }
                            
                        ?>
                        <tr class="border-b border-gray-300 dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-800 transition duration-300">
                        <td class="p-3 text-center font-semibold" style="background-color:<?php echo $service_color; ?>"><?php echo $service; ?></td>
                        <td class="p-3 text-center"><?php echo $ip; ?></td>
                            <td class="p-3 text-center <?php echo $status_color; ?> text-white font-bold rounded-lg"><?php echo $status; ?></td>
                            <td class="p-3 text-center <?php echo $label_color; ?> text-white font-bold rounded-lg"><?php echo $label; ?></td>
                            <td class="p-3 text-center font-bold <?php echo $response_time_percentage; ?>"><?php echo $percentage; ?>%</td>
                            <td class="p-3 text-center font-bold <?php echo $response_time_color; ?>"><?php echo $average_response_time." ms"; ?> </td>
                            <?php foreach ($ping_results as $ping): ?>
                                <td class="p-3 text-center">
                                    <span class="<?php echo ($ping['status'] ?? "-") === "UP" ? "text-green-500" : "text-red-500"; ?>">
                                        <?php echo $ping['status'] ?? "-"; ?>
                                    </span>
                                </td>
                            <?php endforeach; ?>
                            <?php for ($i = count($ping_results); $i < 5; $i++): ?>
                                <td class="p-3 text-center">-</td>
                            <?php endfor; ?>
                            <td class="p-3 text-center">
                                <button type="button" class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-700 transition duration-300" onclick="confirmDelete('<?php echo $ip; ?>')">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>