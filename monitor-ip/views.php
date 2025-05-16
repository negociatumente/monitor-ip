<?php
// Helper function to determine text color based on background
function getContrastColor($hexcolor) {
    // Remove # if present
    $hexcolor = ltrim($hexcolor, '#');
    
    // Convert to RGB
    $r = hexdec(substr($hexcolor, 0, 2));
    $g = hexdec(substr($hexcolor, 2, 2));
    $b = hexdec(substr($hexcolor, 4, 2));
    
    // Calculate brightness (YIQ formula)
    $yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
    
    // Return black or white based on brightness
    return ($yiq >= 128) ? '#000000' : '#ffffff';
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
    
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
        
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
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: #fff;
            padding: 1.5rem;
            border-radius: 0.75rem;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            width: 90%;
            max-width: 450px;
            margin: auto;
            transition: all 0.3s ease;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-weight: 600;
            font-size: 0.875rem;
            text-align: center;
            display: inline-block;
        }
        
        .btn {
            transition: all 0.2s ease;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .btn:hover {
            transform: translateY(-1px);
        }
        
        .btn:active {
            transform: translateY(1px);
        }
        
        .card {
            border-radius: 0.75rem;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }
        
        .card:hover {
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
        }
        
        table {
            border-collapse: separate;
            border-spacing: 0;
        }
        
        table th {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }
        
        .animate-pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        
        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.7;
            }
        }
    </style>
    <script>
        /**
         * IP Monitor Dashboard - Main JavaScript
         */
        
        // Global variables
        let pingInterval = <?php echo $ping_interval; ?>;
        let countdown = pingInterval;
        
        // Modal management functions
        const modalFunctions = {
            // Show modal functions
            showModal: function(modalId) {
                document.getElementById(modalId).style.display = 'flex';
                document.body.style.overflow = 'hidden'; // Prevent scrolling when modal is open
            },
            
            // Hide modal functions
            hideModal: function(modalId) {
                document.getElementById(modalId).style.display = 'none';
                document.body.style.overflow = 'auto'; // Re-enable scrolling
            },
            
            // Specific modal functions
            showAddIpForm: function() { this.showModal('addIpForm'); },
            hideAddIpForm: function() { this.hideModal('addIpForm'); },
            
            showAddServiceForm: function() { this.showModal('addServiceForm'); },
            hideAddServiceForm: function() { this.hideModal('addServiceForm'); },
            
            showChangeTimerForm: function() { this.showModal('changeTimerForm'); },
            hideChangeTimerForm: function() { this.hideModal('changeTimerForm'); },
            
            showChangePingAttemptsForm: function() { this.showModal('changePingAttemptsForm'); },
            hideChangePingAttemptsForm: function() { this.hideModal('changePingAttemptsForm'); },
            
            showClearDataConfirmation: function() { this.showModal('clearDataConfirmation'); },
            hideClearDataConfirmation: function() { this.hideModal('clearDataConfirmation'); },
            
            confirmDelete: function(ip) {
                this.showModal('deleteIpForm');
                document.getElementById('delete_ip').value = ip;
            }
        };
        
        // Countdown and page refresh functions
        function updateCountdown() {
            const countdownElement = document.getElementById('countdown');
            if (countdownElement) {
                countdownElement.innerText = countdown;
                countdown--;
                if (countdown < 0) {
                    countdown = pingInterval;
                    reloadPage();
                }
            }
        }
        
        function reloadPage() {
            window.location.href = window.location.pathname;
        }
        
        // Timer update function
        function updateTimer(event) {
            event.preventDefault();
            const newTimerValue = parseInt(document.getElementById('new_timer_value').value, 10);
            if (newTimerValue > 0) {
                pingInterval = newTimerValue;
                countdown = pingInterval;
                modalFunctions.hideChangeTimerForm();
            } else {
                alert("Please enter a valid number greater than 0.");
            }
        }
        
        // Initialize the application
        function initApp() {
            // Set up event listeners for escape key to close modals
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    const openModals = document.querySelectorAll('.modal[style*="display: flex"]');
                    openModals.forEach(modal => {
                        modal.style.display = 'none';
                    });
                    document.body.style.overflow = 'auto';
                }
            });
            
            // Start countdown timer
            setInterval(updateCountdown, 1000);
        }
        
        // Initialize when DOM is loaded
        document.addEventListener('DOMContentLoaded', initApp);
        
        // Expose functions to global scope for HTML onclick attributes
        window.showAddIpForm = function() { modalFunctions.showAddIpForm(); };
        window.hideAddIpForm = function() { modalFunctions.hideAddIpForm(); };
        window.showAddServiceForm = function() { modalFunctions.showAddServiceForm(); };
        window.hideAddServiceForm = function() { modalFunctions.hideAddServiceForm(); };
        window.showChangeTimerForm = function() { modalFunctions.showChangeTimerForm(); };
        window.hideChangeTimerForm = function() { modalFunctions.hideChangeTimerForm(); };
        window.showChangePingAttemptsForm = function() { modalFunctions.showChangePingAttemptsForm(); };
        window.hideChangePingAttemptsForm = function() { modalFunctions.hideChangePingAttemptsForm(); };
        window.showClearDataConfirmation = function() { modalFunctions.showClearDataConfirmation(); };
        window.hideClearDataConfirmation = function() { modalFunctions.hideClearDataConfirmation(); };
        window.confirmDelete = function(ip) { modalFunctions.confirmDelete(ip); };
        window.reloadPage = reloadPage;
        window.updateTimer = updateTimer;
    </script>
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
                <a href="https://negociatumente.com" target="_blank" class="text-white hover:text-blue-200 transition flex items-center gap-2">
                    <i class="fas fa-user"></i> Antonio Cañavate
                </a>
                <a href="https://github.com/negociatumente/monitor-ip" target="_blank" class="text-white hover:text-blue-200 transition flex items-center gap-2">
                    <i class="fab fa-github"></i> GitHub
                </a>
                <a href="https://negociatumente.com/guia-redes/" target="_blank" class="text-white hover:text-blue-200 transition flex items-center gap-2">
                    <i class="fas fa-book"></i> Aprender más
                </a>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-4 py-6">
        <!-- Dashboard Controls -->
        <div class="mb-8">
            <div class="flex flex-col md:flex-row justify-between items-center mb-6">
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100 mb-4 md:mb-0">
                    <i class="fas fa-chart-line mr-2"></i> IP Status Monitor
                </h1>
                
                <div class="flex items-center bg-white dark:bg-gray-800 rounded-lg shadow-sm px-4 py-2">
                    <div class="text-gray-600 dark:text-gray-300 mr-3">
                        <i class="fas fa-sync-alt mr-2"></i> Next ping in 
                        <span id="countdown" class="font-mono font-bold text-blue-600 dark:text-blue-400">
                            <?php echo $ping_interval; ?>
                        </span> s
                    </div>
                    <button onclick="reloadPage();" class="btn bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-md">
                        <i class="fas fa-redo-alt mr-1"></i> Check Now
                    </button>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-4">
                <div class="flex flex-wrap gap-3 justify-center md:justify-start">
                    <button onclick="showAddServiceForm();" class="btn bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-md">
                        <i class="fas fa-plus-circle"></i> Add Service
                    </button>
                    <button onclick="showAddIpForm();" class="btn bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-md">
                        <i class="fas fa-network-wired"></i> Add IP
                    </button>
                    <button onclick="showChangeTimerForm();" class="btn bg-teal-500 hover:bg-teal-600 text-white px-4 py-2 rounded-md">
                        <i class="fas fa-clock"></i> Change Timer
                    </button>
                    <button onclick="showChangePingAttemptsForm();" class="btn bg-teal-500 hover:bg-teal-600 text-white px-4 py-2 rounded-md">
                        <i class="fas fa-history"></i> Change Ping History
                    </button>
                    <button type="button" onclick="showClearDataConfirmation();" class="btn bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-md">
                        <i class="fas fa-trash-alt"></i> Clear Data
                    </button>
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
                        <button type="button" onclick="hideAddIpForm();" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                    
                    <form method="POST" action="">
                        <div class="mb-5">
                            <label for="new_service" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Service</label>
                            <div class="relative">
                                <select id="new_service" name="new_service" class="w-full p-2.5 bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 appearance-none dark:bg-gray-700 dark:border-gray-600 dark:text-white" required>
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
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700 dark:text-gray-300">
                                    <i class="fas fa-chevron-down"></i>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-5">
                            <label for="new_ip" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">IP Address</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                    <i class="fas fa-network-wired text-gray-400"></i>
                                </div>
                                <input type="text" id="new_ip" name="new_ip" placeholder="192.168.1.1" 
                                    class="w-full pl-10 p-2.5 bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white" required>
                            </div>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Enter a valid IPv4 address</p>
                        </div>
                        
                        <div class="flex justify-end gap-3 mt-6">
                            <button type="button" onclick="hideAddIpForm();" class="btn px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                                <i class="fas fa-times mr-2"></i> Cancel
                            </button>
                            <button type="submit" name="add_ip" class="btn px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                                <i class="fas fa-plus mr-2"></i> Add IP
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <!-- Modal: Add Service Form -->
            <div id="addServiceForm" class="modal">
                <div class="modal-content">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">
                            <i class="fas fa-tags text-green-500 mr-2"></i> Add New Service
                        </h2>
                        <button type="button" onclick="hideAddServiceForm();" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                    
                    <form method="POST" action="">
                        <div class="mb-5">
                            <label for="new_service_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Service Name</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                    <i class="fas fa-server text-gray-400"></i>
                                </div>
                                <input type="text" id="new_service_name" name="new_service_name" placeholder="e.g. Web Server" 
                                    class="w-full pl-10 p-2.5 bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white" required>
                            </div>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Enter a descriptive name for the service</p>
                        </div>
                        
                        <div class="mb-5">
                            <label for="new_service_color" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Service Color</label>
                            <div class="flex items-center space-x-3">
                                <input type="color" id="new_service_color" name="new_service_color" value="#3b82f6"
                                    class="h-10 w-10 rounded border border-gray-300 cursor-pointer" required>
                                <div class="flex-1">
                                    <div class="w-full h-10 rounded-lg" id="color_preview" style="background-color: #3b82f6"></div>
                                </div>
                            </div>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Choose a color to identify this service</p>
                        </div>
                        
                        <div class="flex justify-end gap-3 mt-6">
                            <button type="button" onclick="hideAddServiceForm();" class="btn px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                                <i class="fas fa-times mr-2"></i> Cancel
                            </button>
                            <button type="submit" name="add_service" class="btn px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600">
                                <i class="fas fa-plus mr-2"></i> Add Service
                            </button>
                        </div>
                    </form>
                    
                    <script>
                        // Update color preview when color input changes
                        document.getElementById('new_service_color').addEventListener('input', function(e) {
                            document.getElementById('color_preview').style.backgroundColor = e.target.value;
                        });
                    </script>
                </div>
            </div>
            <!-- Modal: Delete IP Confirmation -->
            <div id="deleteIpForm" class="modal">
                <div class="modal-content">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">
                            <i class="fas fa-exclamation-triangle text-red-500 mr-2"></i> Delete IP
                        </h2>
                        <button type="button" onclick="hideDeleteIpForm();" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
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
                                    <p class="text-red-700 dark:text-red-200 mt-1">Are you sure you want to delete this IP? This action cannot be undone.</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-end gap-3 mt-6">
                            <button type="button" onclick="hideDeleteIpForm();" class="btn px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
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
                <div class="modal-content">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">
                            <i class="fas fa-clock text-blue-500 mr-2"></i> Change Timer Interval
                        </h2>
                        <button type="button" onclick="hideChangeTimerForm();" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                    
                    <form method="POST" action="">
                        <div class="mb-5">
                            <label for="new_timer_value" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Timer Interval (seconds)</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                    <i class="fas fa-stopwatch text-gray-400"></i>
                                </div>
                                <input type="number" id="new_timer_value" name="new_timer_value"
                                    class="w-full pl-10 p-2.5 bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                    value="<?php echo $ping_interval; ?>" min="1" required>
                            </div>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Set how often the system should check IP status (in seconds)</p>
                        </div>
                        
                        <div class="bg-blue-50 dark:bg-blue-900/30 p-4 rounded-lg mb-5">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-info-circle text-blue-500 text-xl"></i>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-blue-800 dark:text-blue-300">Recommended settings</h3>
                                    <div class="mt-2 text-sm text-blue-700 dark:text-blue-200">
                                        <ul class="list-disc pl-5 space-y-1">
                                            <li>30-60 seconds: For critical services</li>
                                            <li>60-300 seconds: For standard monitoring</li>
                                            <li>300+ seconds: For non-critical services</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-end gap-3 mt-6">
                            <button type="button" onclick="hideChangeTimerForm();" class="btn px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                                <i class="fas fa-times mr-2"></i> Cancel
                            </button>
                            <button type="submit" name="change_timer" class="btn px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                                <i class="fas fa-save mr-2"></i> Update
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <!-- Modal: Change Ping History -->
            <div id="changePingAttemptsForm" class="modal">
                <div class="modal-content">
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-200">
                            <i class="fas fa-history text-purple-500 mr-2"></i> Change Ping History
                        </h2>
                        <button type="button" onclick="hideChangePingAttemptsForm();" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                    
                    <form method="POST" action="">
                        <div class="mb-5">
                            <label for="new_ping_attempts" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Ping History Length</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                    <i class="fas fa-list-ol text-gray-400"></i>
                                </div>
                                <input type="number" id="new_ping_attempts" name="new_ping_attempts"
                                    class="w-full pl-10 p-2.5 bg-gray-50 border border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                    value="<?php echo $ping_attempts; ?>" min="1" max="20" required>
                            </div>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Number of ping results to keep in history (1-20)</p>
                        </div>
                        
                        <div class="bg-purple-50 dark:bg-purple-900/30 p-4 rounded-lg mb-5">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-info-circle text-purple-500 text-xl"></i>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-purple-800 dark:text-purple-300">About Ping History</h3>
                                    <div class="mt-2 text-sm text-purple-700 dark:text-purple-200">
                                        <p>The ping history determines how many previous ping results are stored and displayed in the table. A longer history provides better trend analysis but may make the table wider.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-end gap-3 mt-6">
                            <button type="button" onclick="hideChangePingAttemptsForm();" class="btn px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                                <i class="fas fa-times mr-2"></i> Cancel
                            </button>
                            <button type="submit" name="change_ping_attempts" class="btn px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600">
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
                        <button type="button" onclick="hideClearDataConfirmation();" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
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
                                    <h3 class="text-lg font-medium text-amber-800 dark:text-amber-300">Confirm data reset</h3>
                                    <p class="text-amber-700 dark:text-amber-200 mt-1">This will clear all ping history data. Your IP addresses and service configurations will be preserved.</p>
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
                            <button type="button" onclick="hideClearDataConfirmation();" class="btn px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                                <i class="fas fa-times mr-2"></i> Cancel
                            </button>
                            <button type="submit" name="clear_data" class="btn px-4 py-2 bg-amber-500 text-white rounded-lg hover:bg-amber-600">
                                <i class="fas fa-eraser mr-2"></i> Clear Data
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <!-- System Status Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
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
                $system_status_color = $system_status === "Healthy" ? "bg-green-500" : ($system_status === "Degraded" ? "bg-yellow-500" : "bg-red-500");
                $system_status_icon = $system_status === "Healthy" ? "check-circle" : ($system_status === "Degraded" ? "exclamation-triangle" : "exclamation-circle");
                ?>
                
                <!-- System Status Card -->
                <div class="card bg-white dark:bg-gray-800 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase">System Status</h3>
                        <span class="<?php echo $system_status_color; ?> text-white text-xs px-2 py-1 rounded-full">
                            <i class="fas fa-<?php echo $system_status_icon; ?> mr-1"></i>
                            <?php echo $system_status; ?>
                        </span>
                    </div>
                    <div class="flex items-center">
                        <div class="<?php echo $system_status_color; ?> bg-opacity-20 p-3 rounded-full mr-4">
                            <i class="fas fa-server text-2xl <?php echo str_replace('bg-', 'text-', $system_status_color); ?>"></i>
                        </div>
                        <div>
                            <p class="text-3xl font-bold"><?php echo $total_ips; ?></p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Total IPs Monitored</p>
                        </div>
                    </div>
                </div>
                
                <!-- UP IPs Card -->
                <div class="card bg-white dark:bg-gray-800 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase">Online</h3>
                        <span class="bg-green-100 text-green-800 text-xs px-2 py-1 rounded-full dark:bg-green-900 dark:text-green-200">
                            <?php echo round(($ips_up / max(1, $total_ips)) * 100); ?>%
                        </span>
                    </div>
                    <div class="flex items-center">
                        <div class="bg-green-500 bg-opacity-20 p-3 rounded-full mr-4">
                            <i class="fas fa-check-circle text-2xl text-green-500"></i>
                        </div>
                        <div>
                            <p class="text-3xl font-bold text-green-500"><?php echo $ips_up; ?></p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">IPs Online</p>
                        </div>
                    </div>
                </div>
                
                <!-- DOWN IPs Card -->
                <div class="card bg-white dark:bg-gray-800 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase">Offline</h3>
                        <span class="bg-red-100 text-red-800 text-xs px-2 py-1 rounded-full dark:bg-red-900 dark:text-red-200">
                            <?php echo round(($ips_down / max(1, $total_ips)) * 100); ?>%
                        </span>
                    </div>
                    <div class="flex items-center">
                        <div class="bg-red-500 bg-opacity-20 p-3 rounded-full mr-4">
                            <i class="fas fa-times-circle text-2xl text-red-500"></i>
                        </div>
                        <div>
                            <p class="text-3xl font-bold text-red-500"><?php echo $ips_down; ?></p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">IPs Offline</p>
                        </div>
                    </div>
                </div>
                
                <!-- Average Ping Card -->
                <div class="card bg-white dark:bg-gray-800 p-4">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase">Response Time</h3>
                        <?php if ($average_ping !== 'N/A'): ?>
                            <?php 
                            $ping_color = 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
                            if ($average_ping > 100) {
                                $ping_color = 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
                            } else if ($average_ping > 50) {
                                $ping_color = 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
                            }
                            ?>
                            <span class="<?php echo $ping_color; ?> text-xs px-2 py-1 rounded-full">
                                <?php echo $average_ping; ?> ms
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center">
                        <div class="bg-blue-500 bg-opacity-20 p-3 rounded-full mr-4">
                            <i class="fas fa-tachometer-alt text-2xl text-blue-500"></i>
                        </div>
                        <div>
                            <p class="text-3xl font-bold text-blue-500">
                                <?php echo $average_ping !== 'N/A' ? $average_ping : '-'; ?>
                                <span class="text-sm font-normal"><?php echo $average_ping !== 'N/A' ? 'ms' : 'N/A'; ?></span>
                            </p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Average Response</p>
                        </div>
                    </div>
                </div>
            </div>
            <!-- IP Monitoring Table -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm overflow-hidden mb-8">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-200">
                        <i class="fas fa-table mr-2"></i> IP Monitoring Status
                    </h2>
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        <?php echo count($ips_to_monitor); ?> IPs monitored • Last updated: <?php echo date('H:i:s'); ?>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-gray-700 text-left">
                                <th class="p-3 whitespace-nowrap">Service</th>
                                <th class="p-3 whitespace-nowrap">IP Address</th>
                                <th class="p-3 whitespace-nowrap">Status</th>
                                <th class="p-3 whitespace-nowrap">Reliability</th>
                                <th class="p-3 whitespace-nowrap">Uptime %</th>
                                <th class="p-3 whitespace-nowrap">Response</th>
                                <?php
                                // Obtener una IP de ejemplo para mostrar fechas (si existen datos previos)
                                $sample_ip = array_key_last($ping_data);
                                $latest_pings = $ping_data[$sample_ip] ?? array_fill(0, 5, ["timestamp" => "-"]);
                                
                                for ($i = 0; $i < $ping_attempts; $i++) {
                                    if (!isset($latest_pings[$i])) {
                                        $latest_pings[$i] = ["timestamp" => "-"];
                                    }
                                    $timestamp = $latest_pings[$i]['timestamp'];
                                    if ($timestamp !== '-') {
                                        // Format timestamp to show only time if it's today
                                        $date = new DateTime($timestamp);
                                        $now = new DateTime();
                                        if ($date->format('Y-m-d') === $now->format('Y-m-d')) {
                                            $timestamp = $date->format('H:i:s');
                                        }
                                    }
                                    echo "<th class='p-2 text-center text-xs whitespace-nowrap'>" . $timestamp . "</th>";
                                }
                                ?>
                                <th class="p-3 text-center whitespace-nowrap">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <?php if (empty($ips_to_monitor)): ?>
                                <tr>
                                    <td colspan="<?php echo 7 + $ping_attempts; ?>" class="p-4 text-center text-gray-500 dark:text-gray-400">
                                        <div class="py-8">
                                            <i class="fas fa-info-circle text-blue-500 text-4xl mb-3"></i>
                                            <p class="text-lg">No IPs being monitored yet</p>
                                            <p class="text-sm">Click "Add IP" button above to start monitoring</p>
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

                                    // Status badge styling
                                    $status_badge = $status === "UP" 
                                        ? "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200" 
                                        : "bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200";
                                    $status_icon = $status === "UP" ? "check-circle" : "times-circle";
                                    
                                    // Label badge styling
                                    if ($label === "Good") {
                                        $label_badge = "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200";
                                        $label_icon = "thumbs-up";
                                    } elseif ($label === "Stable") {
                                        $label_badge = "bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200";
                                        $label_icon = "exclamation-triangle";
                                    } else {
                                        $label_badge = "bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200";
                                        $label_icon = "exclamation-circle";
                                    }
                                    
                                    // Service color
                                    $service_color = $services[$service] ?? $services["DEFAULT"];
                                    $service_text_color = getContrastColor($service_color);
                                    
                                    // Percentage styling
                                    if ($percentage !== 'N/A') {
                                        $percentage = round($percentage, 1);
                                        if ($percentage > 80) {
                                            $percentage_color = "text-green-500 dark:text-green-400";
                                        } elseif ($percentage > 60) {
                                            $percentage_color = "text-yellow-500 dark:text-yellow-400";
                                        } else {
                                            $percentage_color = "text-red-500 dark:text-red-400";
                                        }
                                    } else {
                                        $percentage_color = "text-gray-500 dark:text-gray-400";
                                        $percentage = 0;
                                    }
                                    
                                    // Response time styling
                                    if ($average_response_time !== 'N/A') {
                                        $average_response_time = round($average_response_time, 1);
                                        if ($average_response_time < 50) {
                                            $response_time_color = "text-green-500 dark:text-green-400";
                                        } elseif ($average_response_time < 100) {
                                            $response_time_color = "text-yellow-500 dark:text-yellow-400";
                                        } else {
                                            $response_time_color = "text-red-500 dark:text-red-400";
                                        }
                                    } else {
                                        $response_time_color = "text-gray-500 dark:text-gray-400";
                                    }
                                    
                                    // Use the getContrastColor() function defined at the top of the file
                                    ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-all duration-150">
                                        <!-- Service -->
                                        <td class="p-3">
                                            <span class="inline-block px-3 py-1 rounded-full text-sm font-medium" 
                                                  style="background-color: <?php echo $service_color; ?>; color: <?php echo $service_text_color; ?>">
                                                <?php echo $service; ?>
                                            </span>
                                        </td>
                                        
                                        <!-- IP Address -->
                                        <td class="p-3 font-mono text-sm"><?php echo $ip; ?></td>
                                        
                                        <!-- Status -->
                                        <td class="p-3">
                                            <span class="status-badge <?php echo $status_badge; ?>">
                                                <i class="fas fa-<?php echo $status_icon; ?> mr-1"></i>
                                                <?php echo $status; ?>
                                            </span>
                                        </td>
                                        
                                        <!-- Label/Reliability -->
                                        <td class="p-3">
                                            <span class="status-badge <?php echo $label_badge; ?>">
                                                <i class="fas fa-<?php echo $label_icon; ?> mr-1"></i>
                                                <?php echo $label; ?>
                                            </span>
                                        </td>
                                        
                                        <!-- Percentage -->
                                        <td class="p-3">
                                            <div class="flex items-center">
                                                <div class="w-16 bg-gray-200 dark:bg-gray-700 rounded-full h-2 mr-2">
                                                    <div class="h-full rounded-full <?php echo str_replace('text', 'bg', $percentage_color); ?>" 
                                                         style="width: <?php echo $percentage; ?>%"></div>
                                                </div>
                                                <span class="font-medium <?php echo $percentage_color; ?>">
                                                    <?php echo $percentage; ?>%
                                                </span>
                                            </div>
                                        </td>
                                        
                                        <!-- Average Response Time -->
                                        <td class="p-3 font-medium <?php echo $response_time_color; ?>">
                                            <?php echo $average_response_time !== 'N/A' ? $average_response_time . ' ms' : 'N/A'; ?>
                                        </td>
                                        
                                        <!-- Ping History -->
                                        <?php foreach ($ping_results as $ping): ?>
                                            <td class="p-2 text-center">
                                                <?php if (($ping['status'] ?? "-") === "UP"): ?>
                                                    <span class="inline-block w-4 h-4 rounded-full bg-green-500 dark:bg-green-400" 
                                                          title="UP - <?php echo $ping['response_time'] ?? 'N/A'; ?>"></span>
                                                <?php else: ?>
                                                    <span class="inline-block w-4 h-4 rounded-full bg-red-500 dark:bg-red-400" 
                                                          title="DOWN"></span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                        
                                        <!-- Empty slots for missing ping history -->
                                        <?php for ($i = count($ping_results); $i < $ping_attempts; $i++): ?>
                                            <td class="p-2 text-center">
                                                <span class="inline-block w-4 h-4 rounded-full bg-gray-200 dark:bg-gray-600"></span>
                                            </td>
                                        <?php endfor; ?>
                                        
                                        <!-- Actions -->
                                        <td class="p-3 text-center">
                                            <button type="button" onclick="confirmDelete('<?php echo $ip; ?>')" 
                                                    class="btn bg-red-100 text-red-700 hover:bg-red-200 dark:bg-red-900 dark:text-red-300 dark:hover:bg-red-800 p-2 rounded-md">
                                                <i class="fas fa-trash-alt"></i>
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