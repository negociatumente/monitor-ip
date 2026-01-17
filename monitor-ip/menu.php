<!-- Dashboard Menu -->
<script>
    window.servicesConfig = <?php
    $services_config = [];
    foreach ($services as $name => $color) {
        $services_config[$name] = [
            'color' => $color,
            'method' => $services_methods[$name] ?? ($services_methods['DEFAULT'] ?? 'icmp')
        ];
    }
    echo json_encode($services_config);
    ?>;
</script>
<div class="mb-4">
    <!-- Monitoring Controls Panel - Single Row Layout (Responsive) -->
    <div
        class="bg-gradient-to-r from-blue-50 via-indigo-50 to-purple-50 dark:from-blue-900/20 dark:via-indigo-900/15 dark:to-purple-900/20 rounded-2xl shadow-lg border border-blue-200/40 dark:border-blue-800/30 p-3 sm:p-4 mb-2 overflow-x-auto">

        <?php
        // Pre-calculate stats
        $total_ips = count($ips_to_monitor);
        $online_count = 0;
        foreach ($ips_to_monitor as $ip => $service) {
            $result = analyze_ip($ip);
            if ($result['status'] === 'UP')
                $online_count++;
        }
        $uptime_percentage = $total_ips > 0 ? round(($online_count / $total_ips) * 100, 1) : 0;
        $stats = calculateSystemStats($ips_to_monitor);
        $health_color = $uptime_percentage >= 90 ? 'emerald' : ($uptime_percentage >= 50 ? 'amber' : 'red');
        // Calculate circle with radius 32 (matching SVG viewBox)
        $circle_radius = 32;
        $circle_circumference = 2 * pi() * $circle_radius;
        // When 100%, offset should be 0 (complete circle)
        // Ensure 100% shows as complete circle
        if ($uptime_percentage >= 100) {
            $circle_offset = 0;
        } else {
            $circle_offset = $circle_circumference - (($uptime_percentage / 100) * $circle_circumference);
        }

        // Ping badge color
        $ping_badge = 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
        if ($stats['average_ping'] !== 'N/A') {
            if ($stats['average_ping'] > 100) {
                $ping_badge = 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
            } elseif ($stats['average_ping'] > 50) {
                $ping_badge = 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
            }
        }
        ?>

        <!-- Single Row Layout - Responsive -->
        <div class="flex items-center gap-2 sm:gap-4 flex-nowrap min-w-max">
            <!-- Health Circle - Enhanced -->
            <div
                class="flex items-center gap-2 sm:gap-4 bg-gradient-to-br from-white to-gray-50 dark:from-gray-800 dark:to-gray-900 rounded-2xl p-2 sm:p-3 shadow-2xl border-2 border-gray-300/50 dark:border-gray-600/50 hover:shadow-3xl transition-all flex-shrink-0">
                <div class="relative w-16 sm:w-20 h-16 sm:h-20 flex items-center justify-center flex-shrink-0">
                    <svg class="w-full h-full transform -rotate-90" viewBox="0 0 80 80">
                        <defs>
                            <linearGradient id="healthGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%"
                                    style="stop-color:rgb(<?php echo $health_color === 'emerald' ? '16,185,129' : ($health_color === 'amber' ? '245,158,11' : '239,68,68'); ?>);stop-opacity:1" />
                                <stop offset="100%"
                                    style="stop-color:rgb(<?php echo $health_color === 'emerald' ? '5,150,105' : ($health_color === 'amber' ? '217,119,6' : '220,38,38'); ?>);stop-opacity:1" />
                            </linearGradient>
                        </defs>
                        <circle cx="40" cy="40" r="32" stroke="currentColor" stroke-width="5" fill="transparent"
                            class="text-gray-200 dark:text-gray-700" />
                        <circle cx="40" cy="40" r="32" stroke="url(#healthGradient)" stroke-width="5" fill="transparent"
                            class="transition-all duration-1000 ease-out drop-shadow-lg"
                            stroke-dasharray="<?php echo $circle_circumference; ?>"
                            stroke-dashoffset="<?php echo $circle_offset; ?>" stroke-linecap="round" />
                    </svg>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <i
                            class="fas fa-heartbeat text-<?php echo $health_color; ?>-500 text-lg sm:text-2xl drop-shadow-md"></i>
                    </div>
                </div>
                <div class="flex-shrink-0">
                    <p
                        class="text-[10px] sm:text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">
                        Uptime</p>
                    <div class="flex items-baseline gap-2">
                        <span
                            class="text-2xl sm:text-3xl font-black text-gray-800 dark:text-gray-100"><?php echo $uptime_percentage; ?>%</span>
                    </div>
                </div>


                <div class="flex items-center gap-2 sm:gap-5 ml-1 sm:ml-4 mr-1 sm:mr-4">
                    <div class="text-center">
                        <p class="text-2xl sm:text-3xl font-black text-gray-800 dark:text-gray-100">
                            <?php echo $stats['total_ips']; ?>
                        </p>
                        <p class="text-[10px] sm:text-xs text-gray-500 dark:text-gray-400 uppercase font-bold mt-1">
                            Total</p>
                    </div>
                    <div class="w-px h-8 sm:h-12 bg-gray-300 dark:bg-gray-600"></div>
                    <div class="text-center">
                        <p class="text-2xl sm:text-3xl font-black text-green-600 dark:text-green-400">
                            <?php echo $stats['ips_up']; ?>
                        </p>
                        <p class="text-[10px] sm:text-xs text-gray-500 dark:text-gray-400 uppercase font-bold mt-1">
                            Online</p>
                    </div>
                    <div class="w-px h-8 sm:h-12 bg-gray-300 dark:bg-gray-600"></div>
                    <div class="text-center">
                        <p class="text-2xl sm:text-3xl font-black text-red-600 dark:text-red-400">
                            <?php echo $stats['ips_down']; ?>
                        </p>
                        <p class="text-[10px] sm:text-xs text-gray-500 dark:text-gray-400 uppercase font-bold mt-1">
                            Offline</p>
                    </div>
                </div>

                <div class="ml-1 sm:ml-4 mr-1 sm:mr-2">
                    <p
                        class="text-[10px] sm:text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">
                        Latency</p>
                    <div class="flex items-baseline gap-2">
                        <span class="text-2xl sm:text-3xl font-black text-blue-600 dark:text-blue-400">
                            <?php echo ($stats['average_ping'] !== 'N/A' ? $stats['average_ping'] : '-'); ?>
                        </span>
                        <span
                            class="text-xs sm:text-base text-gray-500 dark:text-gray-400 font-semibold"><?php echo ($stats['average_ping'] !== 'N/A' ? 'ms' : 'N/A'); ?></span>
                    </div>
                </div>

            </div>

            <!-- Compact Timer Interval -->
            <div class="flex items-center gap-2 bg-blue-50/80 dark:bg-blue-900/20 rounded-lg p-2 sm:p-2.5 shadow-sm border border-blue-200/50 dark:border-blue-700/40 hover:bg-blue-100/80 dark:hover:bg-blue-900/30 transition-all group cursor-pointer flex-shrink-0"
                onclick="showChangeTimerForm();">
                <i class="fas fa-clock text-blue-500 text-xs sm:text-sm"></i>
                <div class="hidden sm:block">
                    <p class="text-[10px] font-semibold text-gray-600 dark:text-gray-300 uppercase">Interval</p>
                    <span
                        class="text-xs sm:text-sm font-bold text-gray-700 dark:text-gray-200"><?php echo $ping_interval; ?>s</span>
                </div>
                <button onclick="showChangeTimerForm(); event.stopPropagation();"
                    class="ml-1 p-1 rounded hover:bg-blue-200/50 dark:hover:bg-blue-800/40 transition-all opacity-0 group-hover:opacity-100"
                    title="Change Timer">
                    <i class="fas fa-edit text-blue-600 dark:text-blue-400 text-[10px] sm:text-xs"></i>
                </button>
            </div>

            <!-- Compact Ping History -->
            <div class="flex items-center gap-2 bg-purple-50/80 dark:bg-purple-900/20 rounded-lg p-2 sm:p-2.5 shadow-sm border border-purple-200/50 dark:border-purple-700/40 hover:bg-purple-100/80 dark:hover:bg-purple-900/30 transition-all group cursor-pointer flex-shrink-0"
                onclick="showChangePingAttemptsForm();">
                <i class="fas fa-history text-purple-500 text-xs sm:text-sm"></i>
                <div class="hidden sm:block">
                    <p class="text-[10px] font-semibold text-gray-600 dark:text-gray-300 uppercase">History</p>
                    <span
                        class="text-xs sm:text-sm font-bold text-gray-700 dark:text-gray-200"><?php echo $ping_attempts; ?>
                        pings</span>
                </div>
                <button onclick="showChangePingAttemptsForm(); event.stopPropagation();"
                    class="ml-1 p-1 rounded hover:bg-purple-200/50 dark:hover:bg-purple-800/40 transition-all opacity-0 group-hover:opacity-100"
                    title="Change History">
                    <i class="fas fa-edit text-purple-600 dark:text-purple-400 text-[10px] sm:text-xs"></i>
                </button>
            </div>



            <!-- El temporizador a la derecha -->
            <div class="flex-1 flex justify-end min-w-0">
                <div
                    class="flex items-center gap-2 sm:gap-4 bg-white dark:bg-gray-800 rounded-2xl p-2 sm:p-4 shadow-xl border-2 border-gray-200 dark:border-gray-700 hover:shadow-2xl transition-all relative overflow-hidden group min-w-0">
                    <!-- Subtle animated background -->
                    <div
                        class="absolute inset-0 bg-gradient-to-r from-gray-50/0 via-gray-100/30 to-gray-50/0 dark:from-gray-700/0 dark:via-gray-600/30 dark:to-gray-700/0 group-hover:via-gray-100/50 dark:group-hover:via-gray-600/50 transition-all duration-1000">
                    </div>

                    <div class="relative z-10 flex-1 min-w-0">
                        <p
                            class="text-[10px] sm:text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">
                            Next Ping</p>
                        <div id="nextPingBlock" class="flex items-baseline gap-2">
                            <span
                                class="text-2xl sm:text-3xl font-black text-gray-800 dark:text-gray-100 font-mono tracking-tight"
                                id="countdown"><?php echo $ping_interval; ?></span>
                            <span class="text-xs sm:text-sm font-semibold text-gray-600 dark:text-gray-300">sec</span>
                        </div>
                        <div id="stoppedMsg" style="display:none;"
                            class="relative z-10 flex items-center gap-2 rounded-xl  ">
                            <i class="fas fa-pause-circle text-orange-600 dark:text-orange-400"></i>
                            <span class="text-sm font-bold text-orange-700 dark:text-orange-300">Paused</span>
                        </div>
                    </div>

                    <div
                        class="relative z-10 flex items-center gap-1 sm:gap-2 ml-2 sm:ml-3 pl-2 sm:pl-3 border-l border-gray-200 dark:border-gray-700 flex-shrink-0">
                        <button id="checkNowBtn" onclick="reloadPage();"
                            class="btn bg-blue-500 hover:bg-blue-600 text-white px-2 sm:px-3 py-1.5 sm:py-2 rounded-lg text-[11px] sm:text-xs transition-all shadow-md hover:shadow-lg font-semibold">
                            <i class="fas fa-redo-alt mr-1 hidden sm:inline"></i>Check
                        </button>
                        <button id="stopPingBtn" onclick="stopPing();"
                            class="btn bg-gray-500 hover:bg-gray-600 text-white px-1.5 sm:px-2.5 py-1.5 sm:py-2 rounded-lg text-xs transition-all shadow-md hover:shadow-lg">
                            <i class="fas fa-pause"></i>
                        </button>
                        <button id="resumePingBtn" onclick="startPing();" style="display:none;"
                            class="btn bg-green-500 hover:bg-green-600 text-white px-1.5 sm:px-2.5 py-1.5 sm:py-2 rounded-lg text-xs transition-all shadow-md hover:shadow-lg">
                            <i class="fas fa-play"></i>
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </div>


</div>

<!-- Modal: Import/Export Config -->
<div id="configModal" class="modal">
    <div class="modal-content p-0 max-w-3xl shadow-2xl border-0">
        <div class="bg-gray-500 p-6 rounded-t-xl flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="bg-white/20 rounded-full p-3 flex items-center justify-center shadow-inner">
                    <i class="fas fa-cogs text-3xl text-white drop-shadow"></i>
                </div>
                <div>
                    <h2 class="text-2xl font-extrabold text-white tracking-tight mb-1">Config Import / Export</h2>
                    <p class="text-indigo-100 text-xs font-medium">*If you are using Local Network monitoring, make sure
                        to select the correct network type when importing/exporting configurations.</p>
                </div>
            </div>
            <button type="button" onclick="hideConfigModal();"
                class="text-white/70 hover:text-white transition-colors text-2xl ml-4">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="p-6 bg-gray-50 dark:bg-gray-900 rounded-b-2xl">
            <?php if (!empty($import_export_message)): ?>
                <div
                    class="mb-4 p-3 rounded-xl bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 border border-blue-200 dark:border-blue-700 flex items-center gap-2">
                    <i class="fas fa-info-circle"></i>
                    <span><?php echo $import_export_message === true ? 'Configuración importada correctamente.' : htmlspecialchars($import_export_message); ?></span>
                </div>
            <?php endif; ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Importar -->
                <form method="POST" enctype="multipart/form-data"
                    class="bg-white dark:bg-gray-800 rounded-2xl shadow p-5 flex flex-col gap-4 border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center gap-3 mb-2">
                        <i class="fas fa-file-import text-indigo-500 text-2xl"></i>
                        <span class="font-bold text-gray-700 dark:text-gray-200">Import Configuration</span>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">*Imports will overwrite existing settings</p>

                    <input type="hidden" name="network"
                        value="<?php echo isset($network_type) ? $network_type : 'external'; ?>">
                    <input type="file" name="import_config" accept=".ini,text/plain" required
                        class="block w-full text-sm text-gray-700 dark:text-gray-200 bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg p-2">
                    <button type="submit"
                        class="w-full bg-indigo-500 hover:bg-indigo-600 text-white font-bold py-2 rounded-lg flex items-center justify-center gap-2 transition-all">
                        <i class="fas fa-upload"></i> Import
                    </button>
                </form>
                <!-- Exportar -->
                <div
                    class="bg-white dark:bg-gray-800 rounded-2xl shadow p-5 flex flex-col gap-4 border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center gap-3 mb-2">
                        <i class="fas fa-file-export text-green-500 text-2xl"></i>
                        <span class="font-bold text-gray-700 dark:text-gray-200">Export Configuration</span>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Export your current configuration settings</p>
                    <a href="?export_config=1<?php echo isset($network_type) ? '&network=' . $network_type : ''; ?>"
                        class="w-full bg-green-500 hover:bg-green-600 text-white font-bold py-2 rounded-lg flex items-center justify-center gap-2 transition-all">
                        <i class="fas fa-download"></i> Export
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Add IP Form -->
<div id="addIpForm" class="modal">
    <div class="modal-content p-0 max-w-xl shadow-2xl border-0">
        <div class="bg-indigo-500 p-6 rounded-t-xl flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="bg-white/20 rounded-full p-3 flex items-center justify-center shadow-inner">
                    <i class="fas fa-plus-circle text-3xl text-white drop-shadow"></i>
                </div>
                <div>
                    <h2 class="text-2xl font-extrabold text-white tracking-tight mb-1">Add New IP / Domain</h2>
                    <p class="text-indigo-100 text-xs font-medium">Add a new IP or domain to monitor</p>
                </div>
            </div>
            <button type="button" onclick="hideAddIpForm();"
                class="text-white/70 hover:text-white transition-colors text-2xl ml-4">
                <i class="fas fa-times"></i>
            </button>
        </div>


        <form method="POST" action="" onsubmit="return validateAddIpForm()">
            <div class="p-4">
                <label for="new_service"
                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Service</label>
                <div class="relative">
                    <select id="new_service" name="new_service" onchange="toggleNewServiceForm()"
                        class="w-full p-2.5 bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 appearance-none dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                        required>
                        <option value="" disabled selected>Select a service</option>
                        <?php foreach ($services as $service_name => $color): ?>
                            <?php if ($service_name !== "DEFAULT"): ?>
                                <option value="<?php echo htmlspecialchars($service_name, ENT_QUOTES, 'UTF-8'); ?>"
                                    style="background-color: <?php echo htmlspecialchars($color, ENT_QUOTES, 'UTF-8'); ?>; color: #fff;">
                                    <?php echo htmlspecialchars($service_name, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <option value="create_new" class="bg-blue-500 text-white">
                            <i class="fas fa-plus mr-2"></i> ➕ New Service
                        </option>
                    </select>
                    <div
                        class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700 dark:text-gray-300">
                        <i class="fas fa-chevron-down"></i>
                    </div>
                </div>
            </div>

            <!-- New Service Creation Form (Hidden by default) -->
            <div id="newServiceForm" style="display: none;"
                class="m-4 p-4 bg-blue-50 dark:bg-blue-900/30 rounded-lg border border-blue-200 dark:border-blue-700">
                <h4 class="text-sm font-semibold text-blue-800 dark:text-blue-300 mb-3 flex items-center">
                    <i class="fas fa-plus-circle mr-2"></i> Create New Service
                </h4>

                <div class="mb-3">
                    <label for="new_service_name_inline"
                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name</label>
                    <input type="text" id="new_service_name_inline" name="new_service_name_inline"
                        placeholder="e.g. Web Server"
                        class="w-full p-2.5 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                </div>

                <div class="mb-3">
                    <label for="new_service_color_inline"
                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Color</label>
                    <div class="flex items-center space-x-3">
                        <input type="color" id="new_service_color_inline" name="new_service_color_inline"
                            value="#3b82f6" onchange="updateInlineColorPreview()"
                            class="h-8 w-16 rounded border border-gray-300 cursor-pointer">
                        <div class="flex-1">
                            <div class="w-full h-8 rounded" id="color_preview_inline" style="background-color: #3b82f6">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="new_method"
                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Monitoring
                        Method</label>
                    <div class="relative">
                        <select id="new_method" name="new_method"
                            class="w-full p-2.5 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 appearance-none dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                            <option value="icmp" selected>Ping (ICMP)</option>
                            <option value="curl">HTTP/HTTPS (Curl)</option>
                            <option value="dns">DNS Lookup</option>
                        </select>
                        <div
                            class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700 dark:text-gray-300">
                            <i class="fas fa-chevron-down"></i>
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Select the monitoring protocol for this
                        service</p>
                </div>

            </div>

            <div class="p-4">
                <label for="new_ip" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">IP Address /
                    Domain</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                        <i class="fas fa-network-wired text-gray-400"></i>
                    </div>
                    <input type="text" id="new_ip" name="new_ip" placeholder="192.168.1.1 or example.com"
                        title="Ingresa una dirección IP válida o un dominio (ej: 192.168.1.1 o google.com)"
                        class="w-full pl-10 p-2.5 bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                        required>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Enter a valid IPv4 address or Domain name</p>
            </div>



            <div class="flex justify-end gap-3 p-4">
                <button type="button" onclick="hideAddIpForm();"
                    class="btn px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                    <i class="fas fa-times mr-2"></i> Cancel
                </button>
                <button type="submit" name="add_ip"
                    class="btn px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                    <i class="fas fa-plus mr-2"></i> Add IP
                </button>
            </div>
        </form>
    </div>
</div>
<!-- Modal: Delete IP Confirmation -->
<div id="deleteIpForm" class="modal">
    <div class="modal-content">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">
                <i class="fas fa-exclamation-triangle text-red-500 mr-2"></i> Delete IP / Domain
            </h2>
            <button type="button" onclick="hideDeleteIpForm();"
                class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <form method="POST" action="">
            <input type="hidden" id="delete_ip" name="delete_ip">

            <div class="bg-red-50 dark:bg-red-900/30 p-4 rounded-lg mb-5">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-trash-alt text-red-500 text-2xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-red-800 dark:text-red-300">Confirm deletion</h3>
                        <p class="text-red-700 dark:text-red-200 mt-1">Are you sure you want to delete this IP/Domain?
                            This action cannot be undone.</p>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-3 mt-6">
                <button type="button" onclick="hideDeleteIpForm();"
                    class="btn px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                    <i class="fas fa-times mr-2"></i> Cancel
                </button>
                <button type="submit" class="btn px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600">
                    <i class="fas fa-trash-alt mr-2"></i> Delete
                </button>
            </div>
        </form>
    </div>
</div>
<!-- Modal: Change Timer Interval -->
<div id="changeTimerForm" class="modal">
    <div class="modal-content max-w-3xl">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">
                <i class="fas fa-clock text-blue-500 mr-2"></i> Change Timer Interval
            </h2>
            <button type="button" onclick="hideChangeTimerForm();"
                class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <form method="POST" action="">
            <div class="mb-6">
                <div class="mb-6">
                    <p class="text-sm text-gray-600 dark:text-gray-400">Choose how often the system should check IP
                        status</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <!-- Critical Services - 30 seconds -->
                    <div class="group">
                        <button type="button" id="timer-btn-30"
                            class="timer-btn w-full p-4 rounded-xl border-2 transition-all duration-300 transform hover:scale-105 <?php echo ($ping_interval == 30 ? 'bg-red-500 text-white border-red-500 shadow-lg' : 'bg-white text-gray-700 border-gray-200 hover:bg-red-50 hover:border-red-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-red-900/20'); ?>"
                            onclick="setTimerValue(30)">
                            <div class="flex flex-col items-center">
                                <div
                                    class="mb-3 p-3 rounded-full <?php echo ($ping_interval == 30 ? 'bg-white/20' : 'bg-red-100 dark:bg-red-900/30'); ?>">
                                    <i
                                        class="fas fa-exclamation-triangle text-2xl <?php echo ($ping_interval == 30 ? 'text-white' : 'text-red-500'); ?>"></i>
                                </div>
                                <div class="text-2xl font-bold mb-1">30s</div>
                                <div class="text-sm font-medium">Critical Services</div>
                                <div class="text-xs opacity-75 mt-1">High priority monitoring</div>
                            </div>
                        </button>
                    </div>

                    <!-- Standard Services - 90 seconds -->
                    <div class="group">
                        <button type="button" id="timer-btn-90"
                            class="timer-btn w-full p-4 rounded-xl border-2 transition-all duration-300 transform hover:scale-105 <?php echo ($ping_interval == 90 ? 'bg-blue-500 text-white border-blue-500 shadow-lg' : 'bg-white text-gray-700 border-gray-200 hover:bg-blue-50 hover:border-blue-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-blue-900/20'); ?>"
                            onclick="setTimerValue(90)">
                            <div class="flex flex-col items-center">
                                <div
                                    class="mb-3 p-3 rounded-full <?php echo ($ping_interval == 90 ? 'bg-white/20' : 'bg-blue-100 dark:bg-blue-900/30'); ?>">
                                    <i
                                        class="fas fa-server text-2xl <?php echo ($ping_interval == 90 ? 'text-white' : 'text-blue-500'); ?>"></i>
                                </div>
                                <div class="text-2xl font-bold mb-1">90s</div>
                                <div class="text-sm font-medium">Standard Services</div>
                                <div class="text-xs opacity-75 mt-1">Balanced monitoring</div>
                            </div>
                        </button>
                    </div>

                    <!-- Non-Critical Services - 300 seconds -->
                    <div class="group">
                        <button type="button" id="timer-btn-300"
                            class="timer-btn w-full p-4 rounded-xl border-2 transition-all duration-300 transform hover:scale-105 <?php echo ($ping_interval == 300 ? 'bg-green-500 text-white border-green-500 shadow-lg' : 'bg-white text-gray-700 border-gray-200 hover:bg-green-50 hover:border-green-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-green-900/20'); ?>"
                            onclick="setTimerValue(300)">
                            <div class="flex flex-col items-center">
                                <div
                                    class="mb-3 p-3 rounded-full <?php echo ($ping_interval == 300 ? 'bg-white/20' : 'bg-green-100 dark:bg-green-900/30'); ?>">
                                    <i
                                        class="fas fa-leaf text-2xl <?php echo ($ping_interval == 300 ? 'text-green' : 'text-green-500'); ?>"></i>
                                </div>
                                <div class="text-2xl font-bold mb-1">5m</div>
                                <div class="text-sm font-medium">Non-Critical Services</div>
                                <div class="text-xs opacity-75 mt-1">Light monitoring</div>
                            </div>
                        </button>
                    </div>
                </div>

                <div
                    class="bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 p-4 rounded-lg border border-blue-200 dark:border-blue-700">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <i class="fas fa-lightbulb text-blue-500 text-xl"></i>
                        </div>
                        <div class="ml-3">
                            <h4 class="text-sm font-medium text-blue-800 dark:text-blue-300 mb-2">Monitoring Guidelines
                            </h4>
                            <div class="text-sm text-blue-700 dark:text-blue-200 space-y-1">
                                <div class="flex items-center">
                                    <i class="fas fa-circle text-red-500 text-xs mr-2"></i>
                                    <span><strong>30 seconds:</strong> Critical infrastructure, databases, core
                                        services</span>
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-circle text-blue-500 text-xs mr-2"></i>
                                    <span><strong>90 seconds:</strong> Web servers, APIs, standard applications</span>
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-circle text-green-500 text-xs mr-2"></i>
                                    <span><strong>5 minutes:</strong> Background services, development
                                        environments</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <input type="hidden" id="new_timer_value" name="new_timer_value" value="<?php echo $ping_interval; ?>">
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" onclick="hideChangeTimerForm();"
                    class="btn px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                    <i class="fas fa-times mr-2"></i> Cancel
                </button>
                <button type="submit" name="change_timer"
                    class="btn px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                    <i class="fas fa-save mr-2"></i> Update
                </button>
            </div>
        </form>
    </div>
</div>
<!-- Modal: Change Ping History -->
<div id="changePingAttemptsForm" class="modal">
    <div class="modal-content max-w-4xl">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">
                <i class="fas fa-history text-blue-500 mr-2"></i> Change Ping History
            </h2>
            <button type="button" onclick="hideChangePingAttemptsForm();"
                class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <form method="POST" action="">
            <div class="mb-6">
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-2">
                        Select History Length
                    </h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Choose how many ping results to keep for trend
                        analysis</p>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
                    <!-- Quick Analysis - 5 pings -->
                    <div class="group">
                        <button type="button" id="btn-5"
                            class="ping-btn w-full p-4 rounded-xl border-2 transition-all duration-300 transform hover:scale-105 <?php echo ($ping_attempts == 5 ? 'bg-purple-500 text-white border-purple-500 shadow-lg' : 'bg-white text-gray-700 border-gray-200 hover:bg-purple-50 hover:border-purple-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-purple-900/20'); ?>"
                            onclick="setPingAttempts(5)">
                            <div class="flex flex-col items-center">
                                <div
                                    class="mb-3 p-3 rounded-full <?php echo ($ping_attempts == 5 ? 'bg-white/20' : 'bg-purple-100 dark:bg-purple-900/30'); ?>">
                                    <i
                                        class="fas fa-bolt text-2xl <?php echo ($ping_attempts == 5 ? 'text-purple' : 'text-purple-500'); ?>"></i>
                                </div>
                                <div class="text-2xl font-bold mb-1">5</div>
                                <div class="text-sm font-medium">Quick</div>
                                <div class="text-xs opacity-75 mt-1">Minimal tracking</div>
                            </div>
                        </button>
                    </div>

                    <!-- Standard Analysis - 15 pings -->
                    <div class="group">
                        <button type="button" id="btn-15"
                            class="ping-btn w-full p-4 rounded-xl border-2 transition-all duration-300 transform hover:scale-105 <?php echo ($ping_attempts == 15 ? 'bg-blue-500 text-white border-blue-500 shadow-lg' : 'bg-white text-gray-700 border-gray-200 hover:bg-blue-50 hover:border-blue-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-blue-900/20'); ?>"
                            onclick="setPingAttempts(15)">
                            <div class="flex flex-col items-center">
                                <div
                                    class="mb-3 p-3 rounded-full <?php echo ($ping_attempts == 15 ? 'bg-white/20' : 'bg-blue-100 dark:bg-blue-900/30'); ?>">
                                    <i
                                        class="fas fa-chart-bar text-2xl <?php echo ($ping_attempts == 15 ? 'text-blue' : 'text-blue-500'); ?>"></i>
                                </div>
                                <div class="text-2xl font-bold mb-1">15</div>
                                <div class="text-sm font-medium">Standard</div>
                                <div class="text-xs opacity-75 mt-1">Balanced view</div>
                            </div>
                        </button>
                    </div>

                    <!-- Detailed Analysis - 25 pings -->
                    <div class="group">
                        <button type="button" id="btn-25"
                            class="ping-btn w-full p-4 rounded-xl border-2 transition-all duration-300 transform hover:scale-105 <?php echo ($ping_attempts == 25 ? 'bg-orange-500 text-white border-orange-500 shadow-lg' : 'bg-white text-gray-700 border-gray-200 hover:bg-orange-50 hover:border-orange-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-orange-900/20'); ?>"
                            onclick="setPingAttempts(25)">
                            <div class="flex flex-col items-center">
                                <div
                                    class="mb-3 p-3 rounded-full <?php echo ($ping_attempts == 25 ? 'bg-white/20' : 'bg-orange-100 dark:bg-orange-900/30'); ?>">
                                    <i
                                        class="fas fa-chart-line text-2xl <?php echo ($ping_attempts == 25 ? 'text-white' : 'text-orange-500'); ?>"></i>
                                </div>
                                <div class="text-2xl font-bold mb-1">25</div>
                                <div class="text-sm font-medium">Detailed</div>
                                <div class="text-xs opacity-75 mt-1">More insights</div>
                            </div>
                        </button>
                    </div>
                </div>

                <div
                    class="bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 p-4 rounded-lg border border-blue-200 dark:border-blue-700">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <i class="fas fa-info-circle text-blue-500 text-xl"></i>
                        </div>
                        <div class="ml-3">
                            <h4 class="text-sm font-medium text-blue-800 dark:text-blue-300 mb-2">History Guidelines
                            </h4>
                            <div class="text-sm text-blue-700 dark:text-blue-200 space-y-1">
                                <div class="flex items-center">
                                    <i class="fas fa-circle text-purple-500 text-xs mr-2"></i>
                                    <span><strong>5 pings:</strong> Basic monitoring, minimal table width</span>
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-circle text-blue-500 text-xs mr-2"></i>
                                    <span><strong>15 pings:</strong> Good balance between detail and performance</span>
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-circle text-orange-500 text-xs mr-2"></i>
                                    <span><strong>25 pings:</strong> Detailed trend analysis for important
                                        services</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <input type="hidden" id="new_ping_attempts" name="new_ping_attempts"
                    value="<?php echo $ping_attempts; ?>">
            </div>

            <div class="flex justify-end gap-3 mt-6">
                <button type="button" onclick="hideChangePingAttemptsForm();"
                    class="btn px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                    <i class="fas fa-times mr-2"></i> Cancel
                </button>
                <button type="submit" name="change_ping_attempts"
                    class="btn px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                    <i class="fas fa-save mr-2"></i> Update
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Clear Data Confirmation -->
<div id="clearDataConfirmation" class="modal">
    <div class="modal-content p-0 max-w-3xl shadow-2xl border-0">
        <div class="bg-amber-500 p-6 rounded-t-xl flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="bg-white/20 rounded-full p-3 flex items-center justify-center shadow-inner">
                    <i class="fas fa-exclamation-triangle text-3xl text-white drop-shadow"></i>
                </div>
                <div>
                    <h2 class="text-2xl font-extrabold text-white tracking-tight mb-1">Clear Monitoring Data</h2>
                    <p class="text-indigo-100 text-xs font-medium">Measure your internet connection speed</p>
                </div>
            </div>
            <button type="button" onclick="hideClearDataConfirmation();"
                class="text-white/70 hover:text-white transition-colors text-2xl ml-4">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="network"
                value="<?php echo isset($network_type) ? $network_type : 'external'; ?>">
            <div class="bg-amber-50 dark:bg-amber-900/30 p-4 m-4 rounded-lg mb-5">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-eraser text-amber-500 text-2xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-amber-800 dark:text-amber-300">Confirm data reset
                        </h3>
                        <p class="text-amber-700 dark:text-amber-200 mt-1">This will clear all ping history
                            data. Your IP addresses and service configurations will be preserved.</p>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-4 m-4">
                <h4 class="font-medium mb-2 text-gray-800 dark:text-gray-200">What will be deleted:</h4>
                <ul class="space-y-1 text-gray-600 dark:text-gray-400">
                    <li class="flex items-center">
                        <i class="fas fa-check-circle text-green-500 mr-2"></i>
                        All ping response history
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-check-circle text-green-500 mr-2"></i>
                        Uptime statistics
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-check-circle text-green-500 mr-2"></i>
                        Response time data
                    </li>
                </ul>

                <h4 class="font-medium m-4 text-gray-800 dark:text-gray-200">What will be preserved:</h4>
                <ul class="space-y-1 text-gray-600 dark:text-gray-400">
                    <li class="flex items-center">
                        <i class="fas fa-shield-alt text-blue-500 mr-2"></i>
                        IP addresses configuration
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-shield-alt text-blue-500 mr-2"></i>
                        Service definitions and colors
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-shield-alt text-blue-500 mr-2"></i>
                        System settings
                    </li>
                </ul>
            </div>

            <div class="p-4 m-4 px-1">
                <label class="flex items-start space-x-3 cursor-pointer group">
                    <div class="flex items-center h-5">
                        <input type="checkbox" name="delete_ips"
                            class="w-4 h-4 text-amber-600 bg-gray-100 border-gray-300 rounded focus:ring-amber-500 dark:focus:ring-amber-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600">
                    </div>
                    <div class="flex flex-col">
                        <span
                            class="text-sm font-medium text-gray-700 dark:text-gray-300 group-hover:text-gray-900 dark:group-hover:text-gray-100">Also
                            delete all IPs and Domains</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Warning: This will remove all monitored
                            hosts from the configuration.</span>
                    </div>
                </label>
            </div>

            <div class="flex justify-end gap-3 m-4">
                <button type="button" onclick="hideClearDataConfirmation();"
                    class="btn px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                    <i class="fas fa-times mr-2"></i> Cancel
                </button>
                <button type="submit" name="clear_data"
                    class="btn px-4 py-2 bg-amber-500 text-white rounded-lg hover:bg-amber-600">
                    <i class="fas fa-eraser mr-2"></i> Clear Data
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Manage Services -->
<div id="manageServiceForm" class="modal">
    <div class="modal-content p-0 max-w-lg shadow-2xl border-0">
        <div class="bg-purple-500 p-6 rounded-t-xl flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="bg-white/20 rounded-full p-3 flex items-center justify-center shadow-inner">
                    <i class="fas fa-tasks text-3xl text-white drop-shadow"></i>
                </div>
                <div>
                    <h2 class="text-2xl font-extrabold text-white tracking-tight mb-1">Manage Services</h2>
                    <p class="text-indigo-100 text-xs font-medium">Personaliza y gestiona los servicios monitorizados
                    </p>
                </div>
            </div>
            <button type="button" onclick="hideManageServiceForm();"
                class="text-white/70 hover:text-white transition-colors text-2xl ml-4">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="p-6 bg-gray-50 dark:bg-gray-900 rounded-b-2xl">
            <!-- Services List -->
            <div id="servicesList">
                <div class="grid grid-cols-1 gap-4 max-h-96 overflow-y-auto pr-2">
                    <?php foreach ($services as $service_name => $color): ?>
                        <?php if ($service_name !== "DEFAULT"):
                            $method = $services_methods[$service_name] ?? ($services_methods['DEFAULT'] ?? 'icmp');
                            $method_label = strtoupper($method);
                            $method_class = $method === 'icmp' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300' :
                                ($method === 'curl' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' :
                                    'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300');
                            ?>
                            <div
                                class="flex items-center justify-between p-4 bg-white dark:bg-gray-800 rounded-2xl shadow border border-gray-200 dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                <div class="flex items-center gap-4">
                                    <div class="w-7 h-7 rounded-full shadow-sm flex items-center justify-center"
                                        style="background-color: <?php echo htmlspecialchars($color); ?>;">
                                        <i class="fas fa-server text-white text-xs"></i>
                                    </div>
                                    <div>
                                        <div class="font-bold text-gray-800 dark:text-gray-200 text-base">
                                            <?php echo htmlspecialchars($service_name); ?>
                                        </div>
                                        <div class="text-xs mt-0.5">
                                            <span
                                                class="px-2 py-0.5 rounded text-[10px] font-bold <?php echo $method_class; ?>">
                                                <?php echo $method_label; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button"
                                        onclick="editService('<?php echo htmlspecialchars($service_name, ENT_QUOTES); ?>')"
                                        class="p-2 text-blue-600 hover:bg-blue-100 dark:text-blue-400 dark:hover:bg-blue-900/30 rounded-lg transition-colors"
                                        title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button"
                                        onclick="deleteService('<?php echo htmlspecialchars($service_name, ENT_QUOTES); ?>')"
                                        class="p-2 text-red-600 hover:bg-red-100 dark:text-red-400 dark:hover:bg-red-900/30 rounded-lg transition-colors"
                                        title="Delete">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if (count($services) <= 1): // Only DEFAULT exists ?>
                        <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                            <i class="fas fa-info-circle text-2xl mb-2"></i>
                            <p>No custom services created yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Edit Form (Hidden by default) -->
            <div id="serviceDetailsForm" style="display:none;" class="pt-2">

                <form method="POST" action=""
                    class="bg-white dark:bg-gray-800 rounded-2xl shadow p-5 border border-gray-200 dark:border-gray-700 flex flex-col gap-4">
                    <input type="hidden" id="old_service_name" name="old_service_name">
                    <div class="">
                        <label for="edit_service_name"
                            class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">Service Name</label>
                        <input type="text" id="edit_service_name" name="service_name" required
                            class="w-full p-2.5 bg-gray-50 dark:bg-gray-900 border border-gray-300 dark:border-gray-700 text-gray-900 dark:text-white rounded-lg focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div class="grid grid-cols-1 gap-4 mb-4">
                        <div>
                            <label for="edit_service_color"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Color</label>
                            <div class="flex items-center space-x-2">
                                <input type="color" id="edit_service_color" name="service_color" required
                                    class="h-10 w-full p-1 bg-white border border-gray-300 rounded-lg cursor-pointer dark:bg-gray-700 dark:border-gray-600">
                            </div>
                        </div>
                        <div>
                            <label for="edit_service_method"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Method</label>
                            <select id="edit_service_method" name="service_method"
                                class="w-full p-2.5 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                <option value="icmp">Ping (ICMP)</option>
                                <option value="curl">HTTP/HTTPS (Curl)</option>
                                <option value="dns">DNS Lookup</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="showServicesList();"
                            class="btn px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                            Cancel
                        </button>
                        <button type="submit" name="update_service"
                            class="btn px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                            <i class="fas fa-save mr-2"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Custom Alert Modal -->
<div id="customAlert" class="modal">
    <div class="modal-content max-w-md">
        <div class="flex items-center mb-4">
            <div class="flex-shrink-0">
                <i id="alertIcon" class="fas fa-info-circle text-blue-500 text-2xl"></i>
            </div>
            <div class="ml-4">
                <h3 id="alertHeader" class="text-lg font-medium text-blue-800 dark:text-blue-300">Información
                </h3>
            </div>
        </div>

        <div class="mb-6">
            <p id="alertMessage" class="text-gray-700 dark:text-gray-200"></p>
        </div>

        <div class="flex justify-end">
            <button type="button" onclick="modalFunctions.hideModal('customAlert');"
                class="btn px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                <i class="fas fa-check mr-2"></i> Aceptar
            </button>
        </div>
    </div>
</div>

<!-- Custom Confirm Modal -->
<div id="customConfirm" class="modal" style="display:none;z-index:9999;">
    <div class="modal-content max-w-md">
        <div class="flex items-center mb-4">
            <div class="flex-shrink-0">
                <i class="fas fa-exclamation-triangle text-red-500 text-2xl"></i>
            </div>
            <div class="ml-4">
                <h3 class="custom-confirm-header text-lg font-bold text-red-800 dark:text-red-300">Confirmar acción</h3>
            </div>
        </div>
        <div class="custom-confirm-body mb-6 text-gray-700 dark:text-gray-200"></div>
        <div class="flex justify-end gap-2">
            <button type="button"
                class="custom-confirm-cancel btn px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600">Cancelar</button>
            <button type="button"
                class="custom-confirm-ok btn px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600">Eliminar</button>
        </div>
    </div>
</div>

<!-- Modal: Scan Private Network -->
<div id="scanNetworkModal" class="modal">

    <div class="modal-content p-0 max-w-4xl shadow-2xl border-0">

        <div class="bg-indigo-500 p-6 rounded-t-xl flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="bg-white/20 rounded-full p-3 flex items-center justify-center shadow-inner">
                    <i class="fas fa-network-wired text-3xl text-white drop-shadow"></i>
                </div>
                <div>
                    <h2 class="text-2xl font-extrabold text-white tracking-tight mb-1">Scan Private Network</h2>
                    <p class="text-indigo-100 text-xs font-medium">Scan your local network for connected devices</p>
                </div>
            </div>
            <button type="button" onclick="hideScanNetworkModal();"
                class="text-white/70 hover:text-white transition-colors text-2xl ml-4">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div id="scanStatus" class="p-4">
            <div class="bg-blue-50 dark:bg-blue-900/30 p-4 rounded-lg border border-blue-200 dark:border-blue-700">
                <p class="text-sm text-blue-800 dark:text-blue-200">
                    <i class="fas fa-info-circle mr-2"></i>
                    Click "Start Scan" to discover devices on your private network. This may take a few seconds.
                </p>
            </div>
        </div>

        <div id="scanResults" class="p-4" style="display:none;">
            <div class="mb-4">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-3">
                    <i class="fas fa-list mr-2"></i> Discovered Devices
                </h3>
                <div id="devicesTable" class="overflow-x-auto">
                    <!-- Results will be inserted here -->
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3 p-4">
            <button type="button" onclick="hideScanNetworkModal();"
                class="btn px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                <i class="fas fa-times mr-2"></i> Close
            </button>
            <button type="button" id="startScanBtn" onclick="startNetworkScan();"
                class="btn px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                <i class="fas fa-radar mr-2"></i> Start Scan
            </button>
            <button type="button" id="saveDevicesBtn" onclick="saveDiscoveredDevices();" style="display:none;"
                class="btn px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                <i class="fas fa-save mr-2"></i> Save Selected Devices
            </button>
        </div>
    </div>
</div>

<!-- Modal: Speed Test -->
<div id="speedTestModal" class="modal">
    <div class="modal-content p-0 max-w-4xl shadow-2xl border-0">

        <div class="bg-purple-500 p-6 rounded-t-xl flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="bg-white/20 rounded-full p-3 flex items-center justify-center shadow-inner">
                    <i class="fas fa-tachometer-alt text-3xl text-white drop-shadow"></i>
                </div>
                <div>
                    <h2 class="text-2xl font-extrabold text-white tracking-tight mb-1">Network Speed Test</h2>
                    <p class="text-indigo-100 text-xs font-medium">Measure your internet connection speed</p>
                </div>
            </div>
            <button type="button" onclick="hideSpeedTestModal();"
                class="text-white/70 hover:text-white transition-colors text-2xl ml-4">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div id="speedTestStatus" class="p-4">
            <div class="bg-blue-50 dark:bg-blue-900/30 p-4 rounded-lg border border-blue-200 dark:border-blue-700">
                <p class="text-sm text-blue-800 dark:text-blue-200">
                    <i class="fas fa-info-circle mr-2"></i>
                    Click "Start Test" to measure your internet speed using Speedtest.
                </p>
            </div>
        </div>

        <div id="speedTestResults" class="p-4" style="display:none;">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <!-- Download Speed -->
                <div
                    class="bg-gradient-to-br from-green-50 to-green-100 dark:from-green-900/30 dark:to-green-800/30 p-4 rounded-lg border border-green-200 dark:border-green-700">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-green-700 dark:text-green-300">Download</span>
                        <i class="fas fa-download text-green-500"></i>
                    </div>
                    <div class="text-3xl font-bold text-green-600 dark:text-green-400" id="downloadSpeed">--</div>
                    <div class="text-xs text-green-600 dark:text-green-400">Mbps</div>
                </div>

                <!-- Upload Speed -->
                <div
                    class="bg-gradient-to-br from-blue-50 to-blue-100 dark:from-blue-900/30 dark:to-blue-800/30 p-4 rounded-lg border border-blue-200 dark:border-blue-700">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-blue-700 dark:text-blue-300">Upload</span>
                        <i class="fas fa-upload text-blue-500"></i>
                    </div>
                    <div class="text-3xl font-bold text-blue-600 dark:text-blue-400" id="uploadSpeed">--</div>
                    <div class="text-xs text-blue-600 dark:text-blue-400">Mbps</div>
                </div>
            </div>
            <!-- Ping/Latency -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div
                    class="bg-gradient-to-br from-purple-50 to-purple-100 dark:from-purple-900/30 dark:to-purple-800/30 p-4 rounded-lg border border-purple-200 dark:border-purple-700">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-purple-700 dark:text-purple-300">Latency</span>
                        <i class="fas fa-clock text-purple-500"></i>
                    </div>
                    <div class="text-3xl font-bold text-purple-600 dark:text-purple-400" id="pingLatency">--</div>
                    <div class="text-xs text-purple-600 dark:text-purple-400">ms</div>
                </div>

                <!-- Jitter -->
                <div
                    class="bg-gradient-to-br from-amber-50 to-amber-100 dark:from-amber-900/30 dark:to-amber-800/30 p-4 rounded-lg border border-amber-200 dark:border-amber-700">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-amber-700 dark:text-amber-300">Jitter</span>
                        <i class="fas fa-wave-square text-amber-500"></i>
                    </div>
                    <div class="text-3xl font-bold text-amber-600 dark:text-amber-400" id="pingJitter">--</div>
                    <div class="text-xs text-amber-600 dark:text-amber-400">ms</div>
                </div>

                <!-- Packet Loss -->
                <div
                    class="bg-gradient-to-br from-red-50 to-red-100 dark:from-red-900/30 dark:to-red-800/30 p-4 rounded-lg border border-red-200 dark:border-red-700">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-sm font-medium text-red-700 dark:text-red-300">Packet Loss</span>
                        <i class="fas fa-percentage text-red-500"></i>
                    </div>
                    <div class="text-3xl font-bold text-red-600 dark:text-red-400" id="packetLoss">--</div>
                    <div class="text-xs text-red-600 dark:text-red-400">%</div>
                </div>
            </div>

            <!-- Progress Bar -->
            <div id="speedTestProgress" class="mb-4" style="display:none;">
                <div class="bg-gray-200 dark:bg-gray-700 rounded-full h-2 overflow-hidden">
                    <div id="speedTestProgressBar"
                        class="bg-gradient-to-r from-purple-500 to-blue-500 h-full transition-all duration-300"
                        style="width: 0%"></div>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2 text-center" id="speedTestProgressText">
                    Initializing...</p>
            </div>

            <!-- Historical Results -->
            <div class="mt-8 pt-6 border-t border-gray-100 dark:border-gray-700">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-md font-semibold text-gray-700 dark:text-gray-300 flex items-center">
                        <i class="fas fa-history mr-2 opacity-70"></i> Recent History (Last 5)
                    </h3>
                    <button type="button" onclick="clearSpeedTestHistory();"
                        class="text-xs font-bold text-red-500 hover:text-red-700 transition-colors flex items-center gap-1">
                        <i class="fas fa-trash-alt"></i> Clear History
                    </button>
                </div>
                <div id="speedTestHistory" class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="text-gray-500 dark:text-gray-400 border-b border-gray-100 dark:border-gray-700">
                                <th class="pb-2 font-medium">Date & Time</th>
                                <th class="pb-2 font-medium">Lat.</th>
                                <th class="pb-2 font-medium">Loss</th>
                                <th class="pb-2 font-medium">Jitter</th>
                                <th class="pb-2 font-medium">Down.</th>
                                <th class="pb-2 font-medium">Up.</th>
                            </tr>
                        </thead>
                        <tbody id="speedTestHistoryBody" class="divide-y divide-gray-50 dark:divide-gray-800">
                            <!-- History will be populated by JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3 mt-6 p-4">
            <button type="button" onclick="hideSpeedTestModal();"
                class="btn px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                <i class="fas fa-times mr-2"></i> Close
            </button>
            <button type="button" id="startSpeedTestBtn" onclick="startSpeedTest();"
                class="btn px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600">
                <i class="fas fa-play mr-2"></i> Start Test
            </button>
        </div>
    </div>
</div>

<!-- Modal: Clear Speed Test History Confirmation -->
<div id="clearSpeedTestHistoryConfirmation" class="modal">
    <div class="modal-content p-0 max-w-xl shadow-2xl border-0">
        <div class="bg-red-500 p-6 rounded-t-xl flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="bg-white/20 rounded-full p-3 flex items-center justify-center shadow-inner">
                    <i class="fas fa-trash-alt text-3xl text-white drop-shadow"></i>
                </div>
                <div>
                    <h2 class="text-2xl font-extrabold text-white tracking-tight mb-1">Clear Speed Test History</h2>
                    <p class="text-indigo-100 text-xs font-medium">This action will permanently delete all recorded
                        tests.</p>
                </div>
            </div>
            <button type="button" onclick="hideClearSpeedTestHistoryModal();"
                class="text-white/70 hover:text-white transition-colors text-2xl ml-4">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="p-6 bg-white dark:bg-gray-800 rounded-b-2xl">
            <div class="bg-red-50 dark:bg-red-900/30 p-4 rounded-lg mb-6 border border-red-200 dark:border-red-900">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-red-500 text-2xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-bold text-red-800 dark:text-red-300">Are you sure?</h3>
                        <p class="text-red-700 dark:text-red-200 mt-1 text-sm font-medium">
                            You are about to delete the entire speed test history.
                            This data is used to track your provider's performance over time.
                            <strong>This action cannot be undone.</strong>
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <button type="button" onclick="hideClearSpeedTestHistoryModal();"
                    class="btn px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 transition-all font-semibold">
                    <i class="fas fa-times mr-2"></i> Cancel
                </button>
                <button type="button" onclick="confirmClearSpeedTestHistory();"
                    class="btn px-6 py-2 bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white rounded-lg shadow-lg shadow-red-500/30 transition-all font-bold">
                    <i class="fas fa-trash-alt mr-2"></i> Delete
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Configure Network Speed -->
<div id="setNetworkSpeedModal" class="modal" style="display: none; z-index: 1000;">
    <div class="modal-content max-w-md">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">
                <i class="fas fa-tachometer-alt text-indigo-500 mr-2"></i> Configure Network Speed
            </h2>
            <button type="button" onclick="hideSetNetworkSpeedModal();"
                class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <p class="text-sm text-gray-600 dark:text-gray-400 mb-6 font-medium">
            Please enter your contracted network speed (in Mbps). This value is required for the Network Health Analysis
            to calculate performance efficiency.
        </p>

        <div class="mb-6">
            <label for="network_speed_input"
                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Contracted Speed (Mbps)</label>
            <div class="flex items-center gap-3">
                <div class="relative flex-1">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-bolt text-indigo-400"></i>
                    </div>
                    <input type="number" id="network_speed_input" placeholder="300, 600, 1000"
                        class="w-full pl-10 p-4 bg-gray-50 border border-gray-300 text-gray-900 rounded-2xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none dark:bg-gray-700 dark:border-gray-600 dark:text-white font-black text-2xl transition-all">
                </div>
                <span class="text-gray-400 font-black text-sm uppercase tracking-widest">Mbps</span>
            </div>
        </div>

        <div class="flex justify-end pt-2">
            <button onclick="saveNetworkSpeed()"
                class="w-full py-4 bg-gradient-to-r from-indigo-500 to-purple-600 hover:from-indigo-600 hover:to-purple-700 text-white font-black rounded-2xl shadow-xl shadow-indigo-500/20 transition-all active:scale-95 flex items-center justify-center gap-2">
                <span>SAVE CONFIGURATION</span>
                <i class="fas fa-arrow-right text-xs"></i>
            </button>
        </div>
    </div>
</div>

<!-- Public IP Modal -->
<div id="publicIPModal" class="modal">
    <div class="modal-content bg-white dark:bg-gray-800 rounded-3xl shadow-2xl max-w-md w-full mx-4">
        <div class="bg-gradient-to-r from-blue-500 to-cyan-600 p-6 rounded-t-3xl">
            <div class="flex items-center justify-between">
                <h2 class="text-2xl font-black text-white flex items-center gap-3">
                    <i class="fas fa-globe"></i>
                    Public IP Information
                </h2>
                <button type="button" onclick="closePublicIPModal();"
                    class="text-white hover:bg-white/20 rounded-full p-2 transition-all">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>

        <div class="p-6">
            <div id="publicIPContent" class="flex flex-col items-center justify-center py-8">
                <i class="fas fa-spinner fa-spin text-4xl mb-4 text-blue-500"></i>
                <p class="text-sm text-gray-500 font-medium">Loading IP information...</p>
            </div>
        </div>

        <div
            class="bg-gray-50 dark:bg-gray-700/50 px-6 py-4 rounded-b-3xl border-t border-gray-200 dark:border-gray-700">
            <button onclick="closePublicIPModal()"
                class="w-full py-2 bg-gray-200 dark:bg-gray-600 text-gray-800 dark:text-white font-semibold rounded-lg hover:bg-gray-300 dark:hover:bg-gray-500 transition-all">
                Close
            </button>
        </div>
    </div>
</div>
</div>