<!-- Dashboard Menu -->
<div class="mb-8">
    <!-- Monitoring Controls Panel -->
    <div
        class="bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 rounded-xl shadow-sm border border-blue-100 dark:border-blue-800 p-4 mb-6">
        <div class="flex flex-col lg:flex-row justify-between items-center gap-4">
            <!-- Monitoring Status -->
            <div class="flex items-center space-x-6">
                 <!-- System Uptime -->
                <div class="flex items-center">
                    <div class="bg-blue-500 bg-opacity-20 p-2 rounded-lg mr-3">
                        <i class="fas fa-signal text-blue-600 dark:text-blue-400"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">System Uptime</p>
                        <p class="font-semibold text-gray-800 dark:text-gray-200">
                            <?php 
                            $total_ips = count($ips_to_monitor);
                            $online_count = 0;
                            foreach ($ips_to_monitor as $ip => $service) {
                                $result = analyze_ip($ip);
                                if ($result['status'] === 'UP') $online_count++;
                            }
                            echo $total_ips > 0 ? round(($online_count/$total_ips)*100, 1) . '%' : 'N/A';
                            ?>
                        </p>
                    </div>
                </div>

                <div class="flex items-center">
                    <div class="bg-blue-500 bg-opacity-20 p-2 rounded-lg mr-3">
                        <i class="fas fa-clock text-blue-600 dark:text-blue-400"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Timer Interval</p>
                        <p class="font-semibold text-gray-800 dark:text-gray-200"><?php echo $ping_interval; ?>s</p>
                    </div>
                </div>

                <div class="flex items-center">
                    <div class="bg-blue-500 bg-opacity-20 p-2 rounded-lg mr-3">
                        <i class="fas fa-history text-blue-600 dark:text-blue-400"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Ping History</p>
                        <p class="font-semibold text-gray-800 dark:text-gray-200"><?php echo $ping_attempts; ?> pings
                        </p>
                    </div>
                </div>
            </div>

            <!-- Control Buttons -->
            <div
                class="flex items-center bg-white dark:bg-gray-800 rounded-lg shadow-sm px-4 py-2 border border-gray-200 dark:border-gray-700">
                <div id="nextPingBlock" class="text-gray-600 dark:text-gray-300 mr-4 flex items-center">
                    <div class="bg-blue-100 dark:bg-blue-900/50 p-1.5 rounded-full mr-2">
                        <i class="fas fa-sync-alt text-blue-600 dark:text-blue-400 text-sm"></i>
                    </div>
                    <span class="text-sm">Next ping in</span>
                    <span id="countdown"
                        class="font-mono font-bold text-blue-600 dark:text-blue-400 ml-2 bg-blue-50 dark:bg-blue-900/30 px-2 py-1 rounded">
                        <?php echo $ping_interval; ?>
                    </span>
                    <span class="text-sm text-gray-500 ml-1">s</span>
                </div>

                <div id="stoppedMsg" style="display:none;" class="flex items-center mr-4">
                    <div class="bg-orange-100 dark:bg-orange-900/50 p-1.5 rounded-full mr-2">
                        <i class="fas fa-pause-circle text-orange-500"></i>
                    </div>
                    <span class="text-orange-600 dark:text-orange-400 font-semibold text-sm">Monitoring Paused</span>
                </div>

                <div class="flex items-center gap-2">
                    <button id="checkNowBtn" onclick="reloadPage();"
                        class="btn bg-blue-500 hover:bg-blue-600 text-white px-3 py-2 rounded-lg text-sm transition-all duration-200 flex items-center gap-1">
                        <i class="fas fa-redo-alt text-xs"></i>
                        <span class="hidden sm:inline">Check Now</span>
                    </button>
                    <button id="stopPingBtn" onclick="stopPing();"
                        class="btn bg-gray-500 hover:bg-gray-600 text-white px-3 py-2 rounded-lg text-sm transition-all duration-200 flex items-center gap-1">
                        <i class="fas fa-stop text-xs"></i>
                        <span class="hidden sm:inline">Pause</span>
                    </button>
                    <button id="resumePingBtn" onclick="startPing();" style="display:none;"
                        class="btn bg-green-500 hover:bg-green-600 text-white px-3 py-2 rounded-lg text-sm transition-all duration-200 flex items-center gap-1">
                        <i class="fas fa-play text-xs"></i>
                        <span class="hidden sm:inline">Resume</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4">
        <div class="flex flex-wrap gap-3 justify-center md:justify-start">
            <button onclick="showAddIpForm();"
                class="btn bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-md">
                <i class="fas fa-network-wired"></i> Add IP
            </button>
            <button onclick="showChangeTimerForm();"
                class="btn bg-teal-500 hover:bg-teal-600 text-white px-4 py-2 rounded-md">
                <i class="fas fa-clock"></i> Change Timer Interval
            </button>
            <button onclick="showChangePingAttemptsForm();"
                class="btn bg-teal-500 hover:bg-teal-600 text-white px-4 py-2 rounded-md">
                <i class="fas fa-history"></i> Change Ping History
            </button>

            <button onclick="showConfigModal();"
                class="btn bg-indigo-500 hover:bg-indigo-600 text-white px-4 py-2 rounded-md">
                <i class="fas fa-file-import"></i> Import/Export
            </button>
            <button type="button" onclick="showClearServiceForm();"
                class="btn bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-md">
                <i class="fas fa-server"></i> Delete Service
            </button>
            <button type="button" onclick="showClearDataConfirmation();"
                class="btn bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-md">
                <i class="fas fa-trash-alt"></i> Clear Data
            </button>
        </div>
    </div>
</div>

<!-- Modal: Import/Export Config -->
<div id="configModal" class="modal">
    <div class="modal-content">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">
                <i class="fas fa-cogs text-indigo-500 mr-2"></i> Importar / Exportar Configuración
            </h2>
            <button type="button" onclick="hideConfigModal();"
                class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <?php if (!empty($import_export_message)): ?>
            <div class="mb-4 p-3 rounded bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                <?php echo $import_export_message === true ? 'Configuración importada correctamente.' : htmlspecialchars($import_export_message); ?>
            </div>
        <?php endif; ?>
        <div class="mb-6">
            <form method="POST" enctype="multipart/form-data">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Importar archivo
                    config.ini</label>
                <input type="file" name="import_config" accept=".ini,text/plain" required
                    class="mb-4 block w-full text-sm text-gray-700 dark:text-gray-200">
                <div class="flex gap-3">
                    <button type="submit" class="btn bg-indigo-500 text-white px-4 py-2 rounded-lg hover:bg-indigo-600">
                        <i class="fas fa-upload mr-2"></i> Importar
                    </button>
                </div>
            </form>
        </div>
        <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Exportar
                configuración actual</label>
            <a href="?export_config=1" class="btn bg-green-500 text-white px-4 py-2 rounded-lg hover:bg-green-600">
                <i class="fas fa-download mr-2"></i> Exportar
            </a>
        </div>
    </div>
</div>

<!-- Modal: Add IP Form -->
<div id="addIpForm" class="modal">
    <div class="modal-content">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">
                <i class="fas fa-plus-circle text-blue-500 mr-2"></i> Add New IP
            </h2>
            <button type="button" onclick="hideAddIpForm();"
                class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <form method="POST" action="" onsubmit="return validateAddIpForm()">
            <div class="mb-5">
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
                class="mb-5 p-4 bg-blue-50 dark:bg-blue-900/30 rounded-lg border border-blue-200 dark:border-blue-700">
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

            </div>

            <div class="mb-5">
                <label for="new_ip" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">IP
                    Address</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                        <i class="fas fa-network-wired text-gray-400"></i>
                    </div>
                    <input type="text" id="new_ip" name="new_ip" placeholder="192.168.1.1"
                        pattern="^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$"
                        title="Ingresa una dirección IP válida (ej: 192.168.1.1)"
                        class="w-full pl-10 p-2.5 bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                        required>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Enter a valid IPv4 address</p>
            </div>

            <div class="flex justify-end gap-3 mt-6">
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
                <i class="fas fa-exclamation-triangle text-red-500 mr-2"></i> Delete IP
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
                        <p class="text-red-700 dark:text-red-200 mt-1">Are you sure you want to delete this IP?
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
    <div class="modal-content">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">
                <i class="fas fa-exclamation-triangle text-amber-500 mr-2"></i> Clear Monitoring Data
            </h2>
            <button type="button" onclick="hideClearDataConfirmation();"
                class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <form method="POST" action="">
            <div class="bg-amber-50 dark:bg-amber-900/30 p-4 rounded-lg mb-5">
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

            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-4 mb-5">
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

                <h4 class="font-medium mt-4 mb-2 text-gray-800 dark:text-gray-200">What will be preserved:</h4>
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

            <div class="flex justify-end gap-3 mt-6">
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

<!-- Modal: Clear Service Form -->
<div id="clearServiceForm" class="modal">
    <div class="modal-content">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">
                <i class="fas fa-trash-alt text-orange-500 mr-2"></i> Delete Service
            </h2>
            <button type="button" onclick="hideClearServiceForm();"
                class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <div class="bg-orange-50 dark:bg-orange-900/30 p-4 rounded-lg mb-5">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <i class="fas fa-server text-orange-500 text-2xl"></i>
                </div>
                <div class="ml-4">
                    <h3 class="text-lg font-medium text-orange-800 dark:text-orange-300">Confirm service
                        deletion</h3>
                    <p class="text-orange-700 dark:text-orange-200 mt-1">This will permanently delete the
                        selected service and all its associated IP addresses.</p>
                </div>
            </div>
        </div>

        <div class="mb-5">
            <label for="service_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Select
                Service</label>
            <div class="relative">
                <select id="service_name" name="service_name"
                    class="w-full p-2.5 bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                    required>
                    <option value="">Select service to delete</option>
                    <?php foreach ($services as $service_name => $color): ?>
                        <?php if ($service_name !== "DEFAULT"): ?>
                            <option value="<?php echo htmlspecialchars($service_name); ?>"
                                style="background-color: <?php echo htmlspecialchars($color); ?>; color: #fff;">
                                <?php echo htmlspecialchars($service_name); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <div
                    class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700 dark:text-gray-300">
                    <i class="fas fa-chevron-down"></i>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3 mt-6">
            <button type="button" onclick="hideClearServiceForm();"
                class="btn px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                <i class="fas fa-times mr-2"></i> Cancel
            </button>
            <button type="button" onclick="confirmClearService();"
                class="btn px-4 py-2 bg-orange-500 text-white rounded-lg hover:bg-orange-600">
                <i class="fas fa-trash-alt mr-2"></i> Delete Service
            </button>
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
<div id="customConfirm" class="modal">
    <div class="modal-content max-w-md">
        <div class="flex items-center mb-4">
            <div class="flex-shrink-0">
                <i class="fas fa-question-circle text-amber-500 text-2xl"></i>
            </div>
            <div class="ml-4">
                <h3 id="confirmTitle" class="text-lg font-medium text-amber-800 dark:text-amber-300">Confirmar
                    acción</h3>
            </div>
        </div>

        <div class="mb-6">
            <p id="confirmMessage" class="text-gray-700 dark:text-gray-200"></p>
        </div>

        <div class="flex justify-end gap-3">
            <button type="button" onclick="modalFunctions.hideModal('customConfirm');"
                class="btn px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                <i class="fas fa-times mr-2"></i> Cancelar
            </button>
            <button type="button" id="confirmBtn"
                class="btn px-4 py-2 bg-amber-500 text-white rounded-lg hover:bg-amber-600">
                <i class="fas fa-check mr-2"></i> Confirmar
            </button>
        </div>
    </div>
</div>

<!-- System Status Cards -->
<?php
$stats = calculateSystemStats($ips_to_monitor);
?>
<div class='grid grid-cols-1 md:grid-cols-4 gap-4 mb-8'>
    <!-- System Status Card -->
    <div class='card bg-white dark:bg-gray-800 p-4'>
        <div class='flex items-center justify-between mb-2'>
            <h3 class='text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase'>System Status</h3>
            <span class='<?php echo $stats['system_status_color']; ?> text-white text-xs px-2 py-1 rounded-full'>
                <i class='fas fa-<?php echo $stats['system_status_icon']; ?> mr-1'></i>
                <?php echo $stats['system_status']; ?>
            </span>
        </div>
        <div class='flex items-center'>
            <div class='<?php echo $stats['system_status_color']; ?> bg-opacity-20 p-3 rounded-full mr-4'>
                <i
                    class='fas fa-server text-2xl <?php echo str_replace('bg-', 'text-', $stats['system_status_color']); ?>'></i>
            </div>
            <div>
                <p class='text-3xl font-bold'><?php echo $stats['total_ips']; ?></p>
                <p class='text-sm text-gray-500 dark:text-gray-400'>Total IPs Monitored</p>
            </div>
        </div>
    </div>
    <!-- UP IPs Card -->
    <div class='card bg-white dark:bg-gray-800 p-4'>
        <div class='flex items-center justify-between mb-2'>
            <h3 class='text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase'>Online</h3>
            <span
                class='bg-green-100 text-green-800 text-xs px-2 py-1 rounded-full dark:bg-green-900 dark:text-green-200'>
                <?php echo round(($stats['ips_up'] / max(1, $stats['total_ips'])) * 100); ?>%
            </span>
        </div>
        <div class='flex items-center'>
            <div class='bg-green-500 bg-opacity-20 p-3 rounded-full mr-4'>
                <i class='fas fa-check-circle text-2xl text-green-500'></i>
            </div>
            <div>
                <p class='text-3xl font-bold text-green-500'><?php echo $stats['ips_up']; ?></p>
                <p class='text-sm text-gray-500 dark:text-gray-400'>IPs Online</p>
            </div>
        </div>
    </div>
    <!-- DOWN IPs Card -->
    <div class='card bg-white dark:bg-gray-800 p-4'>
        <div class='flex items-center justify-between mb-2'>
            <h3 class='text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase'>Offline</h3>
            <span class='bg-red-100 text-red-800 text-xs px-2 py-1 rounded-full dark:bg-red-900 dark:text-red-200'>
                <?php echo round(($stats['ips_down'] / max(1, $stats['total_ips'])) * 100); ?>%
            </span>
        </div>
        <div class='flex items-center'>
            <div class='bg-red-500 bg-opacity-20 p-3 rounded-full mr-4'>
                <i class='fas fa-times-circle text-2xl text-red-500'></i>
            </div>
            <div>
                <p class='text-3xl font-bold text-red-500'><?php echo $stats['ips_down']; ?></p>
                <p class='text-sm text-gray-500 dark:text-gray-400'>IPs Offline</p>
            </div>
        </div>
    </div>
    <!-- Average Ping Card -->
    <div class='card bg-white dark:bg-gray-800 p-4'>
        <div class='flex items-center justify-between mb-2'>
            <h3 class='text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase'>Response Time</h3>
            <?php
            if ($stats['average_ping'] === 'N/A') {
                echo '';
            } else {
                $ping_color = 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
                if ($stats['average_ping'] > 100) {
                    $ping_color = 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
                } elseif ($stats['average_ping'] > 50) {
                    $ping_color = 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
                }
                echo "<span class='{$ping_color} text-xs px-2 py-1 rounded-full'>{$stats['average_ping']} ms</span>";
            }
            ?>
        </div>
        <div class='flex itemscenter'>
            <div class='bg-blue-500 bg-opacity-20 p-3 rounded-full mr-4'>
                <i class='fas fa-tachometer-alt text-2xl text-blue-500'></i>
            </div>
            <div>
                <p class='text-3xl font-bold text-blue-500'>
                    <?php echo ($stats['average_ping'] !== 'N/A' ? $stats['average_ping'] : '-'); ?>
                    <span
                        class='text-sm font-normal'><?php echo ($stats['average_ping'] !== 'N/A' ? 'ms' : 'N/A'); ?></span>
                </p>
                <p class='text-sm text-gray-500 dark:text-gray-400'>Average Response</p>
            </div>
        </div>
    </div>
</div>