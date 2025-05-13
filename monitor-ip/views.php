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

        function showAddServiceForm() {
            document.getElementById('addServiceForm').style.display = 'flex';
        }

        function hideAddServiceForm() {
            document.getElementById('addServiceForm').style.display = 'none';
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

        function showChangeTimerForm() {
            document.getElementById('changeTimerForm').style.display = 'flex';
        }

        function hideChangeTimerForm() {
            document.getElementById('changeTimerForm').style.display = 'none';
        }

        function showChangePingAttemptsForm() {
            document.getElementById('changePingAttemptsForm').style.display = 'flex';
        }

        function hideChangePingAttemptsForm() {
            document.getElementById('changePingAttemptsForm').style.display = 'none';
        }

        function showClearDataConfirmation() {
            document.getElementById('clearDataConfirmation').style.display = 'flex';
        }

        function hideClearDataConfirmation() {
            document.getElementById('clearDataConfirmation').style.display = 'none';
        }

        function updateTimer(event) {
            event.preventDefault(); // Evitar el envío del formulario
            const newTimerValue = parseInt(document.getElementById('new_timer_value').value, 10);
            if (newTimerValue > 0) {
                pingInterval = newTimerValue; // Actualizar el valor global del temporizador
                countdown = pingInterval; // Reiniciar el contador con el nuevo valor
                hideChangeTimerForm(); // Cerrar la ventana modal
            } else {
                alert("Please enter a valid number greater than 0.");
            }
        }

    </script>
</head>

<body class="bg-gray-100 dark:bg-gray-900 text-gray-800 dark:text-gray-200">
    <!-- Banner superior -->
    <div class="bg-gray-400 text-center py-2 shadow-lg">
    <p>
        Proyecto hecho por 
        <a href="https://negociatumente.com" target="_blank" class="font-bold hover:underline">
            Antonio Cañavate | Telemático
        </a>
        <a href="https://github.com/negociatumente/monitor-ip" target="_blank" class="hover:underline mx-4">
            Ver en GitHub
        </a> <a href="https://negociatumente.com/guia-redes/" target="_blank" class="hover:underline mx-4">
            Quiero aprender más
        </a>
    </p>
     
    
    </div>
</div>

    <div class="container mx-auto p-2">
        <div class="text-center my-2">
            <!-- Barra de navegación -->
            <nav>
                <div class="container mx-auto px-4 py-2 flex justify-center items-center space-x-4">
                    <button onclick="showAddServiceForm();"
                        class="font-bold bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600 transition duration-300">
                        Add Service
                    </button>
                    <button onclick="showAddIpForm();"
                        class="font-bold bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600 transition duration-300">
                        Add IP
                    </button>
                    <button onclick="showChangeTimerForm();"
                        class="font-bold bg-teal-500 text-white px-4 py-2 rounded hover:bg-teal-600 transition duration-300">
                        Change Timer
                    </button>
                    <button onclick="showChangePingAttemptsForm();"
                        class="font-bold bg-teal-500 text-white px-4 py-2 rounded hover:bg-teal-600 transition duration-300">
                        Change Ping History
                    </button>
                    <form method="POST" action="" class="inline">
                        <button type="button" onclick="showClearDataConfirmation();"
                            class="font-bold bg-red-500 text-white px-4 py-2 rounded hover:bg-red-700 transition duration-300">
                            Clear Data
                        </button>
                    </form>
                </div>
            </nav>


            <div id="addIpForm" class="modal">
                <div class="modal-content">
                    <h2 class="text-2xl font-bold mb-4">Add New IP</h2>
                    <form method="POST" action="">
                        <div class="mb-4">
                            <label for="new_service" class="block text-gray-700">Service</label>
                            <select id="new_service" name="new_service"
                                class="w-full p-2 border border-gray-300 rounded mt-1" required>
                                <option value="" disabled selected>Select a service</option>
                                <?php foreach ($services as $service_name => $color): ?>
                                    <?php if ($service_name !== "DEFAULT"): ?>
                                        <option value="<?php echo htmlspecialchars($service_name, ENT_QUOTES, 'UTF-8'); ?>"
                                            style="background-color: <?php echo htmlspecialchars($color, ENT_QUOTES, 'UTF-8'); ?>; color: #fff;">
                                            <?php echo htmlspecialchars($service_name, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label for="new_ip" class="block text-gray-700">IP Address</label>
                            <input type="text" id="new_ip" name="new_ip"
                                class="w-full p-2 border border-gray-300 rounded mt-1" required>
                        </div>
                        <div class="flex justify-end">
                            <button type="button" onclick="hideAddIpForm();"
                                class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-700 transition duration-300 mr-2">Cancel</button>
                            <button type="submit" name="add_ip"
                                class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-700 transition duration-300">Add</button>
                        </div>
                    </form>
                </div>
            </div>
            <div id="addServiceForm" class="modal">
                <div class="modal-content">
                    <h2 class="text-2xl font-bold mb-4">Add New Service</h2>
                    <form method="POST" action="">
                        <div class="mb-4">
                            <label for="new_service_name" class="block text-gray-700">Service Name</label>
                            <input type="text" id="new_service_name" name="new_service_name"
                                class="w-full p-2 border border-gray-300 rounded mt-1" required>
                        </div>
                        <div class="mb-4">
                            <label for="new_service_color" class="block text-gray-700">Service Color</label>
                            <input type="color" id="new_service_color" name="new_service_color"
                                class="w-full p-2 border border-gray-300 rounded mt-1" required>
                        </div>
                        <div class="flex justify-end">
                            <button type="button" onclick="hideAddServiceForm();"
                                class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-700 transition duration-300 mr-2">Cancel</button>
                            <button type="submit" name="add_service"
                                class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-700 transition duration-300">Add</button>
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
                            <button type="button" onclick="hideDeleteIpForm();"
                                class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-700 transition duration-300 mr-2">Cancel</button>
                            <button type="submit"
                                class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-700 transition duration-300">Delete</button>
                        </div>
                    </form>
                </div>
            </div>
            <div id="changeTimerForm" class="modal">
                <div class="modal-content">
                    <h2 class="text-2xl font-bold mb-4">Change Timer Interval</h2>
                    <form method="POST" action="">
                        <div class="mb-4">
                            <label for="new_timer_value" class="block text-gray-700">New Timer Value (seconds):</label>
                            <input type="number" id="new_timer_value" name="new_timer_value"
                                class="w-full p-2 border border-gray-300 rounded mt-1"
                                value="<?php echo $ping_interval; ?>" min="1" required>
                        </div>
                        <div class="flex justify-end">
                            <button type="button" onclick="hideChangeTimerForm();"
                                class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-700 transition duration-300 mr-2">Cancel</button>
                            <button type="submit" name="change_timer"
                                class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-700 transition duration-300">Update</button>
                        </div>
                    </form>
                </div>
            </div>
            <div id="changePingAttemptsForm" class="modal">
                <div class="modal-content">
                    <h2 class="text-2xl font-bold mb-4">Change Ping History</h2>
                    <form method="POST" action="">
                        <div class="mb-4">
                            <label for="new_ping_attempts" class="block text-gray-700">New Ping History:</label>
                            <input type="number" id="new_ping_attempts" name="new_ping_attempts"
                                class="w-full p-2 border border-gray-300 rounded mt-1"
                                value="<?php echo $ping_attempts; ?>" min="1" required>
                        </div>
                        <div class="flex justify-end">
                            <button type="button" onclick="hideChangePingAttemptsForm();"
                                class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-700 transition duration-300 mr-2">Cancel</button>
                            <button type="submit" name="change_ping_attempts"
                                class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-700 transition duration-300">Update</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="clearDataConfirmation" class="modal">
                <div class="modal-content">
                    <h2 class="text-2xl font-bold mb-4">Confirm Clear Data</h2>
                    <p class="mb-4">Are you sure you want to delete the ping history? IPs and services will be
                        maintained.</p>
                    <form method="POST" action="">
                        <div class="flex justify-end">
                            <button type="button" onclick="hideClearDataConfirmation();"
                                class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-700 transition duration-300 mr-2">Cancel</button>
                            <button type="submit" name="clear_data"
                                class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-700 transition duration-300">Confirm</button>
                        </div>
                    </form>
                </div>
            </div>
            <h1 class="text-3xl font-bold text-center my-6">IP Status Monitor</h1>
            <!-- Tabla de resumen -->
            <div class="my-6">
                <div class="overflow-x-auto">
                    <table
                        class="w-full max-w-2xl mx-auto border-collapse shadow-lg rounded-lg overflow-hidden bg-white">
                        <thead>
                            <tr class="bg-gray-200 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                                <th class="p-3 text-center">Total IPs</th>
                                <th class="p-3 text-center">UP IPs</th>
                                <th class="p-3 text-center">DOWN IPs</th>
                                <th class="p-3 text-center">Average Ping</th>
                                <th class="p-3 text-center">System Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Variables para el resumen
                            $total_ips = count($ips_to_monitor);
                            $ips_up = 0;
                            $ips_down = 0;
                            $total_ping = 0;
                            $ping_count = 0;

                            foreach ($ips_to_monitor as $ip => $service) {
                                $result = analyze_ip($ip);
                                if ($result['status'] === "UP") {
                                    $ips_up++;
                                } else {
                                    $ips_down++;
                                }

                                if ($result['average_response_time'] !== 'N/A') {
                                    $total_ping += $result['average_response_time'];
                                    $ping_count++;
                                }
                            }

                            $average_ping = $ping_count > 0 ? round($total_ping / $ping_count, 2) : 'N/A';
                            $system_status = $ips_down === 0 ? "Healthy" : ($ips_up > $ips_down ? "Degraded" : "Critical");
                            $system_status_color = $system_status === "Healthy" ? "text-green-500" : ($system_status === "Degraded" ? "text-yellow-500" : "text-red-500");
                            ?>
                            <tr class="border-b border-gray-300 dark:border-gray-700">
                                <td class="p-3 text-center font-bold"><?php echo $total_ips; ?></td>
                                <td class="p-3 text-center font-bold text-green-500"><?php echo $ips_up; ?></td>
                                <td class="p-3 text-center font-bold text-red-500"><?php echo $ips_down; ?></td>
                                <td class="p-3 text-center font-bold">
                                    <?php echo $average_ping !== 'N/A' ? $average_ping . " ms" : "N/A"; ?></td>
                                <td class="p-3 text-center font-bold <?php echo $system_status_color; ?>">
                                    <?php echo $system_status; ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>


            <div class="container mx-auto  m-2">
                <p class="text-white text-lg m-2 bg-gray-800 bg-opacity-50 p-2 rounded-lg inline-block shadow-lg">Next
                    ping in <span id="countdown" class="font-bold"><?php echo $ping_interval; ?></span> seconds!</p>
                <button onclick="reloadPage();"
                    class="font-bold bg-orange-500 text-white px-4 py-2 rounded hover:bg-orange-600 transition duration-300">
                    Check Status Now
                </button>
            </div>
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
                            $sample_ip = array_key_last($ping_data);
                            $latest_pings = $ping_data[$sample_ip] ?? array_fill(0, 5, ["timestamp" => "-"]);
                            //Haz un for que vaya hasta el ping_attempts y muestre las fechas
                            for ($i = 0; $i < $ping_attempts; $i++) {
                                if (!isset($latest_pings[$i])) {
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
                            <tr
                                class="border-b border-gray-300 dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-800 transition duration-300">
                                <td class="p-3 text-center font-semibold"
                                    style="background-color:<?php echo $service_color; ?>"><?php echo $service; ?></td>
                                <td class="p-3 text-center"><?php echo $ip; ?></td>
                                <td class="p-3 text-center <?php echo $status_color; ?> text-white font-bold rounded-lg">
                                    <?php echo $status; ?></td>
                                <td class="p-3 text-center <?php echo $label_color; ?> text-white font-bold rounded-lg">
                                    <?php echo $label; ?></td>
                                <td class="p-3 text-center font-bold <?php echo $response_time_percentage; ?>">
                                    <?php echo $percentage; ?>%</td>
                                <td class="p-3 text-center font-bold <?php echo $response_time_color; ?>">
                                    <?php echo $average_response_time . " ms"; ?> </td>
                                <?php foreach ($ping_results as $ping): ?>
                                    <td class="p-3 text-center">
                                        <span
                                            class="<?php echo ($ping['status'] ?? "-") === "UP" ? "text-green-500" : "text-red-500"; ?>">
                                            <?php echo $ping['status'] ?? "-"; ?>
                                        </span>
                                    </td>
                                <?php endforeach; ?>
                                <?php for ($i = count($ping_results); $i < $ping_attempts; $i++): ?>
                                    <td class="p-3 text-center">-</td>
                                <?php endfor; ?>
                                <td class="p-3 text-center">
                                    <button type="button"
                                        class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-700 transition duration-300"
                                        onclick="confirmDelete('<?php echo $ip; ?>')">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
</body>

</html>