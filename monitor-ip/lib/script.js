/**
 * IP Monitor Dashboard - Main JavaScript
 */

/**
 * Modal management functions
 * Includes functions to show/hide modals, custom alerts, and confirmations.
 */
const modalFunctions = {
    // Show a modal by ID
    showModal: function (modalId) {
        document.getElementById(modalId).style.display = 'flex';
        document.body.style.overflow = 'hidden'; // Prevent scrolling when modal is open
    },
    // Hide a modal by ID
    hideModal: function (modalId) {
        document.getElementById(modalId).style.display = 'none';
        document.body.style.overflow = 'auto'; // Re-enable scrolling
    },
    // Show a custom alert modal
    showAlert: function (message, type = 'info') {
        const alertModal = document.getElementById('customAlert');
        const alertIcon = document.getElementById('alertIcon');
        const alertMessage = document.getElementById('alertMessage');
        const alertHeader = document.getElementById('alertHeader');

        // Set icon and colors based on type
        if (type === 'error') {
            alertIcon.className = 'fas fa-exclamation-circle text-red-500 text-2xl';
            alertHeader.textContent = 'Error';
            alertHeader.className = 'text-lg font-medium text-red-800 dark:text-red-300';
        } else if (type === 'success') {
            alertIcon.className = 'fas fa-check-circle text-green-500 text-2xl';
            alertHeader.textContent = 'Éxito';
            alertHeader.className = 'text-lg font-medium text-green-800 dark:text-green-300';
        } else {
            alertIcon.className = 'fas fa-info-circle text-blue-500 text-2xl';
            alertHeader.textContent = 'Información';
            alertHeader.className = 'text-lg font-medium text-blue-800 dark:text-blue-300';
        }

        alertMessage.textContent = message;
        this.showModal('customAlert');
    },

    // Show a custom confirm modal
    showConfirm: function (message, onConfirm, title = 'Confirmar acción') {
        const confirmModal = document.getElementById('customConfirm');
        const confirmMessage = document.getElementById('confirmMessage');
        const confirmTitle = document.getElementById('confirmTitle');
        const confirmBtn = document.getElementById('confirmBtn');

        confirmTitle.textContent = title;
        confirmMessage.textContent = message;

        // Remove existing event listeners and add new one
        confirmBtn.onclick = function () {
            modalFunctions.hideModal('customConfirm');
            onConfirm();
        };

        this.showModal('customConfirm');
    },

    // Specific modal functions for forms and config
    showAddIpForm: function () { this.showModal('addIpForm'); },
    hideAddIpForm: function () { this.hideModal('addIpForm'); },

    showChangeTimerForm: function () { this.showModal('changeTimerForm'); },
    hideChangeTimerForm: function () { this.hideModal('changeTimerForm'); },

    showChangePingAttemptsForm: function () { this.showModal('changePingAttemptsForm'); },
    hideChangePingAttemptsForm: function () { this.hideModal('changePingAttemptsForm'); },

    showClearDataConfirmation: function () { this.showModal('clearDataConfirmation'); },
    hideClearDataConfirmation: function () { this.hideModal('clearDataConfirmation'); },

    confirmDelete: function (ip) {
        this.showModal('deleteIpForm');
        document.getElementById('delete_ip').value = ip;
    },
    hideDeleteIpForm: function () { this.hideModal('deleteIpForm'); },

    // Config modal functions
    showConfigModal: function () {
        document.getElementById('configModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    },
    hideConfigModal: function () {
        document.getElementById('configModal').style.display = 'none';
        document.body.style.overflow = 'auto';
    },
};

/**
 * Updates the countdown timer for the next ping and reloads the page when it reaches zero.
 */
function updateCountdown() {
    if (pingStopped) return;
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

/**
 * Reloads the current page.
 */
function reloadPage() {
    // Remove parameters that might interfere with a clean refresh
    const url = new URL(window.location.href);
    const paramsToClear = [
        'page', 'action', 'msg', 'delete_ip', 'add_ip',
        'update_ip_service', 'change_timer', 'change_ping_attempts',
        'clear_data', 'delete_service', 'export_config', 'import_config', 'no_ping'
    ];

    paramsToClear.forEach(param => url.searchParams.delete(param));

    // Navigate to the clean URL (keeps network=local/external)
    window.location.href = url.toString();
}

/**
 * Stops the ping countdown and shows the monitoring stopped message.
 */
function stopPing() {
    pingStopped = true;
    document.getElementById('nextPingBlock').style.display = 'none';
    document.getElementById('stopPingBtn').style.display = 'none';
    document.getElementById('resumePingBtn').style.display = '';
    document.getElementById('stoppedMsg').style.display = '';
}

/**
 * Resumes the ping countdown and hides the stopped message.
 */
function startPing() {
    pingStopped = false;
    countdown = pingInterval;
    document.getElementById('nextPingBlock').style.display = '';
    document.getElementById('stopPingBtn').style.display = '';
    document.getElementById('resumePingBtn').style.display = 'none';
    document.getElementById('stoppedMsg').style.display = 'none';
}

/**
 * Updates the timer value from the form.
 */
function updateTimer(event) {
    event.preventDefault();
    const newTimerValue = parseInt(document.getElementById('new_timer_value').value, 10);
    if (newTimerValue > 0) {
        pingInterval = newTimerValue;
        countdown = pingInterval;
        modalFunctions.hideChangeTimerForm();
    } else {
        modalFunctions.showAlert("Por favor, ingresa un número válido mayor a 0.", 'error');
    }
}

/**
 * Shows or hides the form to create a new service.
 */
function toggleNewServiceForm() {
    const selectService = document.getElementById('new_service');
    const newServiceForm = document.getElementById('newServiceForm');

    if (selectService.value === 'create_new') {
        newServiceForm.style.display = 'block';
        document.getElementById('new_service_name_inline').required = true;
        document.getElementById('new_service_color_inline').required = true;
    } else {
        newServiceForm.style.display = 'none';
        document.getElementById('new_service_name_inline').required = false;
        document.getElementById('new_service_color_inline').required = false;
    }
}

/**
 * Updates the color preview in the inline service creation form.
 */
function updateInlineColorPreview() {
    const colorInput = document.getElementById('new_service_color_inline');
    const preview = document.getElementById('color_preview_inline');
    preview.style.backgroundColor = colorInput.value;
}

/**
 * Initializes the app: listeners and timer.
 */
function initApp() {
    // Set up event listeners for escape key to close modals
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            const openModals = document.querySelectorAll('.modal[style*="display: flex"]');
            openModals.forEach(modal => {
                modal.style.display = 'none';
            });
            document.body.style.overflow = 'auto';
        }
    });

    // Start countdown timer
    countdownInterval = setInterval(updateCountdown, 1000);
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', initApp);

/**
 * Validates the Add IP form.
 */
function validateAddIpForm() {
    const ipInput = document.getElementById('new_ip');
    const serviceSelect = document.getElementById('new_service');
    const serviceNameInput = document.getElementById('new_service_name_inline');

    // Validate IP address
    // Validate IP address or Domain/Hostname
    // Allows IPv4, FQDNs, and single-word hostnames (e.g. localhost, server1)
    const ipPattern = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
    // Regex for hostname: alphanumeric, hyphens, dots. 
    const hostnamePattern = /^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/;

    const inputVal = ipInput.value.trim();
    if (!ipPattern.test(inputVal) && !hostnamePattern.test(inputVal)) {
        modalFunctions.showAlert('Por favor, ingresa una dirección IP válida (ej: 192.168.1.1) o un dominio/hostname (ej: google.com, localhost)', 'error');
        ipInput.focus();
        return false;
    }

    // Validate service selection
    if (!serviceSelect.value) {
        modalFunctions.showAlert('Por favor, selecciona un servicio', 'error');
        serviceSelect.focus();
        return false;
    }

    // If creating new service, validate service name
    if (serviceSelect.value === 'create_new') {
        if (!serviceNameInput.value.trim()) {
            modalFunctions.showAlert('Por favor, ingresa un nombre para el nuevo servicio', 'error');
            serviceNameInput.focus();
            return false;
        }
    }

    return true;
}

// Expose functions to global scope for HTML onclick attributes
window.showAddIpForm = function () { modalFunctions.showAddIpForm(); };
window.hideAddIpForm = function () { modalFunctions.hideAddIpForm(); };
window.showChangeTimerForm = function () { modalFunctions.showChangeTimerForm(); };
window.hideChangeTimerForm = function () { modalFunctions.hideChangeTimerForm(); };
window.showChangePingAttemptsForm = function () { modalFunctions.showChangePingAttemptsForm(); };
window.hideChangePingAttemptsForm = function () { modalFunctions.hideChangePingAttemptsForm(); };
window.showClearDataConfirmation = function () { modalFunctions.showClearDataConfirmation(); };
window.hideClearDataConfirmation = function () { modalFunctions.hideClearDataConfirmation(); };
window.showConfigModal = function () { modalFunctions.showConfigModal(); };
window.hideConfigModal = function () { modalFunctions.hideConfigModal(); };
window.confirmDelete = function (ip) { modalFunctions.confirmDelete(ip); };
window.hideDeleteIpForm = function () { modalFunctions.hideDeleteIpForm(); };
window.showClearServiceForm = function () { modalFunctions.showModal('clearServiceForm'); };
window.hideClearServiceForm = function () { modalFunctions.hideModal('clearServiceForm'); };
window.confirmClearService = function () {
    const serviceSelect = document.getElementById('service_name');
    const serviceName = serviceSelect.value;

    if (!serviceName) {
        modalFunctions.showAlert('Por favor, selecciona un servicio para eliminar', 'error');
        return;
    }

    modalFunctions.showConfirm(
        `¿Estás seguro de que quieres eliminar el servicio "${serviceName}" y todas sus IPs asociadas? Esta acción no se puede deshacer.`,
        function () {
            // Create and submit the form
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';

            const serviceInput = document.createElement('input');
            serviceInput.type = 'hidden';
            serviceInput.name = 'service_name';
            serviceInput.value = serviceName;

            const clearServiceInput = document.createElement('input');
            clearServiceInput.type = 'hidden';
            clearServiceInput.name = 'clear_service';
            clearServiceInput.value = '1';

            form.appendChild(serviceInput);
            form.appendChild(clearServiceInput);
            document.body.appendChild(form);
            form.submit();
        },
        'Eliminar Servicio'
    );
};

/**
 * Auto-hides the notification after 5 seconds.
 */
document.addEventListener('DOMContentLoaded', function () {
    const notification = document.getElementById('notification');
    if (notification) {
        setTimeout(function () {
            notification.style.transition = 'opacity 0.5s ease-out';
            notification.style.opacity = '0';
            setTimeout(function () {
                notification.style.display = 'none';
            }, 500);
        }, 5000);
    }
});

/**
 * Changes the number of ping attempts and updates the button visuals.
 */
function setPingAttempts(val) {
    var input = document.getElementById('new_ping_attempts');
    if (input) {
        input.value = val;
    }

    // Reset all ping buttons
    document.querySelectorAll('.ping-btn').forEach(btn => {
        btn.className = btn.className.replace(/bg-(purple|blue|orange|green)-500|text-white|border-(purple|blue|orange|green)-500|shadow-lg/g, '');
        if (btn.id === 'btn-5') {
            btn.className = btn.className + ' bg-white text-gray-700 border-gray-200 hover:bg-purple-50 hover:border-purple-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-purple-900/20';
        } else if (btn.id === 'btn-15') {
            btn.className = btn.className + ' bg-white text-gray-700 border-gray-200 hover:bg-blue-50 hover:border-blue-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-blue-900/20';
        } else if (btn.id === 'btn-25') {
            btn.className = btn.className + ' bg-white text-gray-700 border-gray-200 hover:bg-orange-50 hover:border-orange-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-orange-900/20';
        }
    });

    // Set active button
    var activeBtn = document.getElementById('btn-' + val);
    if (activeBtn) {
        activeBtn.className = activeBtn.className.replace(/bg-white|text-gray-700|border-gray-200|hover:bg-\w+-50|hover:border-\w+-300|dark:bg-gray-700|dark:text-gray-200|dark:border-gray-600|dark:hover:bg-\w+-900\/20/g, '');
        if (val == 5) {
            activeBtn.className = activeBtn.className + ' bg-purple-500 text-white border-purple-500 shadow-lg';
        } else if (val == 15) {
            activeBtn.className = activeBtn.className + ' bg-blue-500 text-white border-blue-500 shadow-lg';
        } else if (val == 25) {
            activeBtn.className = activeBtn.className + ' bg-orange-500 text-white border-orange-500 shadow-lg';
        }
    }
}

/**
 * Changes the timer value and updates the button visuals.
 */
function setTimerValue(val) {
    var input = document.getElementById('new_timer_value');
    input.value = val;

    // Reset all timer buttons
    document.querySelectorAll('.timer-btn').forEach(btn => {
        btn.className = btn.className.replace(/bg-(red|blue|green)-500|text-white|border-(red|blue|green)-500|shadow-lg/g, '');
        if (btn.id === 'timer-btn-30') {
            btn.className = btn.className + ' bg-white text-gray-700 border-gray-200 hover:bg-red-50 hover:border-red-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-red-900/20';
        } else if (btn.id === 'timer-btn-90') {
            btn.className = btn.className + ' bg-white text-gray-700 border-gray-200 hover:bg-blue-50 hover:border-blue-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-blue-900/20';
        } else if (btn.id === 'timer-btn-300') {
            btn.className = btn.className + ' bg-white text-gray-700 border-gray-200 hover:bg-green-50 hover:border-green-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-green-900/20';
        }
    });

    // Set active button
    var activeBtn = document.getElementById('timer-btn-' + val);
    if (activeBtn) {
        activeBtn.className = activeBtn.className.replace(/bg-white|text-gray-700|border-gray-200|hover:bg-\w+-50|hover:border-\w+-300|dark:bg-gray-700|dark:text-gray-200|dark:border-gray-600|dark:hover:bg-\w+-900\/20/g, '');
        if (val == 30) {
            activeBtn.className = activeBtn.className + ' bg-red-500 text-white border-red-500 shadow-lg';
        } else if (val == 90) {
            activeBtn.className = activeBtn.className + ' bg-blue-500 text-white border-blue-500 shadow-lg';
        } else if (val == 300) {
            activeBtn.className = activeBtn.className + ' bg-green-500 text-white border-green-500 shadow-lg';
        }
    }
}

/**
 * Closes the IP detail modal.
 */
function closeIpModal() {
    document.getElementById('ipDetailModal').classList.add('hidden');
    currentPage = 1;
    window.currentIpData = null;
}

/**
 * Shows the confirmation modal to delete an IP from the detail view.
 */
function showDeleteConfirmFromDetail() {
    const ip = document.getElementById('modalIpTitle').innerText.replace('IP info: ', '');
    const confirmModal = document.getElementById('deleteIpForm');
    document.getElementById('delete_ip').value = ip;
    confirmModal.style.zIndex = '200'; // Más alto que el modal de detalle
    confirmModal.style.display = 'flex';
}

/**
 * Creates the ping latency chart using Chart.js.
 */
function createPingChart(ipData) {
    const ctx = document.getElementById('pingChart').getContext('2d');
    // Invertir el orden de los pings para mostrar los más recientes a la derecha
    const reversedResults = [...ipData.ping_results].reverse();
    const labels = reversedResults.map(p => p.timestamp.split(' ')[1] || p.timestamp);
    const data = reversedResults.map(p => {
        let val = p.response_time;
        if (typeof val === 'string') {
            val = parseFloat(val.replace('ms', '').replace(' ', ''));
        }
        return isNaN(val) ? null : val;
    });

    const hasValidData = data.some(val => val !== null && val !== undefined);

    if (window.pingChartInstance) {
        window.pingChartInstance.destroy();
    }

    if (!hasValidData) {
        window.pingChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Sin datos'],
                datasets: [{
                    label: 'Sin datos de latencia',
                    data: [0],
                    borderColor: '#9CA3AF',
                    backgroundColor: 'rgba(156,163,175,0.1)',
                    pointBackgroundColor: '#9CA3AF',
                    pointRadius: 0,
                    fill: false
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 1,
                        display: false
                    },
                    x: {
                        display: false
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        labels: {
                            generateLabels: function () {
                                return [{
                                    text: 'No hay datos de ping disponibles',
                                    fillStyle: '#9CA3AF',
                                    strokeStyle: '#9CA3AF'
                                }];
                            }
                        }
                    }
                },
                elements: {
                    point: {
                        radius: 0
                    }
                }
            }
        });
    } else {
        window.pingChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Latencia (ms)',
                    data: data,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37,99,235,0.1)',
                    pointBackgroundColor: '#2563eb',
                    pointRadius: 4,
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'ms'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Ping'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
    }
}

/**
 * Shows the IP detail modal and loads its information.
 */
/**
 * Shows the IP detail modal and loads its information.
 */
function showIpDetailModal(ip, startTab = 'general') {
    const ipData = window.ipDetails[ip];
    if (!ipData) return;

    currentPage = 1; // Reset to first page
    document.getElementById('modalIpTitle').innerText = `${ip}`;

    // Reset tabs
    switchIpDetailTab(startTab);

    // Clear previous diagnostic results
    const visual = document.getElementById('detail_traceroute_visual');
    const raw = document.getElementById('detail_traceroute_raw');
    const geo = document.getElementById('detail_geoip_container');
    const ai = document.getElementById('detail_aireport_content');

    if (visual) visual.innerHTML = '<div class="flex flex-col items-center justify-center py-10 text-gray-400 italic">Click "Run Traceroute" to analyze path...</div>';
    if (raw) raw.textContent = '-- Raw output --';
    if (geo) geo.innerHTML = '<div class="col-span-2 py-10 flex flex-col items-center"><i class="fas fa-spinner fa-spin text-2xl mb-4 text-purple-500"></i><p class="text-sm text-gray-400">Initiating geolocation analysis...</p></div>';
    if (ai) ai.innerText = 'Waiting for analysis trigger...';

    // Check if it's an external network to show/hide diagnostics and AI tabs
    const isLocal = new URLSearchParams(window.location.search).get('network') === 'local';
    const diagTab = document.getElementById('ipTabDiagnostics');
    const aiTab = document.getElementById('ipTabAIReport');
    const tabsNav = document.getElementById('ipDetailTabsNav');

    if (isLocal) {
        diagTab.classList.add('hidden');
        aiTab.classList.add('hidden');
        tabsNav.classList.add('hidden');
    } else {
        diagTab.classList.remove('hidden');
        aiTab.classList.remove('hidden');
        tabsNav.classList.remove('hidden');

        // Initial data fetch: Pre-load location for external IPs
        runGeoIPDetail(ip);
    }

    // Uptime color logic
    let uptimeColors = '';
    let uptimeTextColor = '';
    if (ipData.percentage >= 90) {
        uptimeColors = 'bg-gradient-to-br from-green-50 to-green-100 dark:from-green-900/40 dark:to-green-800/20 text-green-600 dark:text-green-300 border border-green-200 dark:border-green-800';
        uptimeTextColor = 'text-green-700 dark:text-green-400';
    } else if (ipData.percentage >= 75) {
        uptimeColors = 'bg-gradient-to-br from-yellow-50 to-yellow-100 dark:from-yellow-900/40 dark:to-yellow-800/20 text-yellow-600 dark:text-yellow-300 border border-yellow-200 dark:border-yellow-800';
        uptimeTextColor = 'text-yellow-700 dark:text-yellow-400';
    } else {
        uptimeColors = 'bg-gradient-to-br from-red-50 to-red-100 dark:from-red-900/40 dark:to-red-800/20 text-red-600 dark:text-red-300 border border-red-200 dark:border-red-800';
        uptimeTextColor = 'text-red-700 dark:text-red-400';
    }

    // Ping color logic
    let pingColors = '';
    let pingTextColor = '';
    if (ipData.average_response_time === 'N/A') {
        pingColors = 'bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900/40 dark:to-gray-800/20 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-800';
        pingTextColor = 'text-gray-700 dark:text-gray-400';
    } else if (ipData.average_response_time > 100) {
        pingColors = 'bg-gradient-to-br from-red-50 to-red-100 dark:from-red-900/40 dark:to-red-800/20 text-red-600 dark:text-red-300 border border-red-200 dark:border-red-800';
        pingTextColor = 'text-red-700 dark:text-red-400';
    } else if (ipData.average_response_time > 50) {
        pingColors = 'bg-gradient-to-br from-yellow-50 to-yellow-100 dark:from-yellow-900/40 dark:to-yellow-800/20 text-yellow-600 dark:text-yellow-300 border border-yellow-200 dark:border-yellow-800';
        pingTextColor = 'text-yellow-700 dark:text-yellow-400';
    } else {
        pingColors = 'bg-gradient-to-br from-green-50 to-green-100 dark:from-green-900/40 dark:to-green-800/20 text-green-600 dark:text-green-300 border border-green-200 dark:border-green-800';
        pingTextColor = 'text-green-700 dark:text-green-400';
    }

    document.getElementById('modalIpContent').innerHTML = `
        <div class='grid grid-cols-2 md:grid-cols-4 gap-4'>
            <div class='${uptimeColors} rounded-2xl p-4 text-center shadow-sm'>
                <div class='text-2xl font-black'>${Math.round(ipData.percentage)}%</div>
                <div class='text-[10px] uppercase font-bold tracking-wider opacity-60'>Uptime</div>
            </div>
            <div class='${pingColors} rounded-2xl p-4 text-center shadow-sm'>
                <div class='text-2xl font-black'>${typeof ipData.average_response_time === 'number' ? ipData.average_response_time.toFixed(1) : ipData.average_response_time} <span class='text-xs font-normal'>ms</span></div>
                <div class='text-[10px] uppercase font-bold tracking-wider opacity-60'>Avg. Latency</div>
            </div>
            <div class='rounded-2xl p-4 text-center shadow-sm flex flex-col justify-center' style='background-color: ${ipData.service_color}; border: 1px solid rgba(0,0,0,0.1);'>
                <div class='text-lg font-bold truncate leading-tight' style='color: ${ipData.service_text_color};'>${ipData.service}</div>
                <div class='text-[10px] uppercase font-bold tracking-wider opacity-60 mt-1' style='color: ${ipData.service_text_color};'>Assigned Service</div>
            </div>
            <div class='bg-gradient-to-br from-indigo-50 to-purple-50 dark:from-indigo-900/40 dark:to-purple-800/20 text-indigo-600 dark:text-indigo-300 rounded-2xl p-4 text-center border border-indigo-100 dark:border-indigo-800 shadow-sm'>
                <div class='text-2xl font-black'>${ipData.method || 'ICMP'}</div>
                <div class='text-[10px] uppercase font-bold tracking-wider opacity-60'>Checking Method</div>
            </div>
        </div>

        <div class='mt-8'>
            <div class='flex justify-between items-center mb-4'>
                <h4 class='text-md font-semibold text-gray-700 dark:text-gray-300 flex items-center'>
                    <i class='fas fa-history mr-2 text-blue-500'></i>
                    Recent Activity Logs
                </h4>
                <div class='text-xs font-bold text-gray-400 uppercase tracking-widest'>
                    Total: ${ipData.ping_results.length} samples
                </div>
            </div>
            <div id='pingsContainer' class='grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2 mb-4'>
                <!-- Los pings se cargan aquí -->
            </div>
            <div id='paginationContainer' class='flex justify-center mt-2'>
                <!-- La paginación se carga aquí -->
            </div>
        </div>
    `;

    // Store current IP data globally for pagination
    window.currentIpData = ipData;

    // Load first page
    loadPingsPage(ip, 1);

    document.getElementById('ipDetailModal').classList.remove('hidden');

    // Graficar los pings y la latencia
    setTimeout(() => {
        createPingChart(ipData);
    }, 200);
}

/**
 * Switching Tabs in IP Detail Modal
 */
function switchIpDetailTab(tabId) {
    const tabs = {
        'general': { tab: 'ipTabGeneral', content: 'ipDetailContentGeneral' },
        'diagnostics': { tab: 'ipTabDiagnostics', content: 'ipDetailContentDiagnostics' },
        'aireport': { tab: 'ipTabAIReport', content: 'ipDetailContentAIReport' }
    };

    // Reset all tabs
    Object.values(tabs).forEach(t => {
        const tabEl = document.getElementById(t.tab);
        const contentEl = document.getElementById(t.content);
        if (tabEl) {
            tabEl.classList.remove('border-blue-600', 'text-blue-600', 'bg-white', 'dark:bg-gray-800', 'dark:text-blue-400');
            tabEl.classList.add('border-transparent', 'text-gray-500');
        }
        if (contentEl) contentEl.classList.add('hidden');
    });

    // Activate selected tab
    const selected = tabs[tabId];
    if (selected) {
        const tabEl = document.getElementById(selected.tab);
        const contentEl = document.getElementById(selected.content);
        if (tabEl) {
            tabEl.classList.add('border-blue-600', 'text-blue-600', 'bg-white', 'dark:bg-gray-800', 'dark:text-blue-400');
            tabEl.classList.remove('border-transparent', 'text-gray-500');
        }
        if (contentEl) contentEl.classList.remove('hidden');

        // Load diagnostics data if needed
        if (tabId === 'diagnostics' && window.currentIpData) {
            runGeoIPDetail(window.currentIpData.ip);
        } else if (tabId === 'aireport' && window.currentIpData) {
            generateAIReportDetail(window.currentIpData.ip);
        }
    }
}

window.showIpDetailModal = showIpDetailModal;
window.switchIpDetailTab = switchIpDetailTab;

/**
 * Loads the ping page in the IP detail modal.
 */
function loadPingsPage(ip, page) {
    const ipData = window.currentIpData;
    if (!ipData) return;

    currentPage = page;
    const totalPings = ipData.ping_results.length;
    const totalPages = Math.ceil(totalPings / pingsPerPage);
    const startIndex = (page - 1) * pingsPerPage;
    const endIndex = Math.min(startIndex + pingsPerPage, totalPings);
    const pingsToShow = ipData.ping_results.slice(startIndex, endIndex);

    // Render pings
    const pingsHtml = pingsToShow.map((p, index) => {
        const isUp = p.status === 'UP';
        const isDown = p.status === 'DOWN';
        let bgColor, iconColor, icon;
        if (isUp) {
            bgColor = 'bg-green-50 dark:bg-green-900 border-green-200 dark:border-green-700';
            iconColor = 'text-green-500';
            icon = 'check-circle';
        } else if (isDown) {
            bgColor = 'bg-red-50 dark:bg-red-900 border-red-200 dark:border-red-700';
            iconColor = 'text-red-500';
            icon = 'times-circle';
        } else {
            bgColor = 'bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-600';
            iconColor = 'text-gray-500';
            icon = 'question-circle';
        }
        let responseTime = p.response_time;
        if (typeof responseTime === 'string' && responseTime.includes('ms')) {
            const num = parseFloat(responseTime.replace('ms', '').trim());
            responseTime = isNaN(num) ? responseTime : num.toFixed(2) + ' ms';
        }
        const globalIndex = startIndex + index + 1;
        // Mostrar hora si es de hoy, fecha completa si no
        let fechaMostrar = p.timestamp;
        if (p.timestamp && typeof p.timestamp === 'string') {
            const fecha = new Date(p.timestamp.replace(/-/g, '/'));
            const hoy = new Date();
            if (fecha.getFullYear() === hoy.getFullYear() && fecha.getMonth() === hoy.getMonth() && fecha.getDate() === hoy.getDate()) {
                fechaMostrar = p.timestamp.split(' ')[1];
            }
        }
        return `
                <div class='${bgColor} border rounded-md p-3 flex items-center justify-between text-sm transition-all hover:shadow-sm'>
                    <div class='flex items-center gap-3'>
                        <span class='text-xs text-gray-500 dark:text-gray-400 font-mono w-6'>#${globalIndex}</span>
                        <i class='fas fa-${icon} ${iconColor}'></i>
                        <span class='font-medium text-gray-800 dark:text-gray-200'>${fechaMostrar}</span>
                    </div>
                    <div class='text-right'>
                        <span class='font-bold ${isUp ? 'text-green-600 dark:text-green-400' : isDown ? 'text-red-600 dark:text-red-400' : 'text-gray-600 dark:text-gray-400'}'>${p.status}</span>
                        <span class='text-xs text-gray-500 dark:text-gray-400 ml-2'>${responseTime}</span>
                    </div>
                </div>
            `;
    }).join('');

    document.getElementById('pingsContainer').innerHTML = pingsHtml;

    // Render pagination
    if (totalPages > 1) {
        const paginationHtml = createPaginationHtml(ip, page, totalPages);
        document.getElementById('paginationContainer').innerHTML = paginationHtml;
    } else {
        document.getElementById('paginationContainer').innerHTML = '';
    }
}

/**
 * Creates pagination for the ping history in the IP detail modal.
 */
function createPaginationHtml(ip, currentPage, totalPages) {
    let html = '<div class="flex items-center gap-2">';

    // Previous button
    if (currentPage > 1) {
        html += `<button onclick="loadPingsPage('${ip}', ${currentPage - 1})" class="px-3 py-1 text-sm bg-blue-500 text-white rounded hover:bg-blue-600 transition-colors">
            <i class="fas fa-chevron-left mr-1"></i>Anterior
        </button>`;
    }

    // Page numbers
    const maxVisible = 5;
    let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
    let endPage = Math.min(totalPages, startPage + maxVisible - 1);

    if (endPage - startPage + 1 < maxVisible) {
        startPage = Math.max(1, endPage - maxVisible + 1);
    }

    if (startPage > 1) {
        html += `<button onclick="loadPingsPage('${ip}', 1)" class="px-3 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">1</button>`;
        if (startPage > 2) {
            html += '<span class="px-2 text-gray-500">...</span>';
        }
    }

    for (let i = startPage; i <= endPage; i++) {
        const isActive = i === currentPage;
        html += `<button onclick="loadPingsPage('${ip}', ${i})" class="px-3 py-1 text-sm rounded transition-colors ${isActive
            ? 'bg-blue-500 text-white'
            : 'border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700'
            }">${i}</button>`;
    }

    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            html += '<span class="px-2 text-gray-500">...</span>';
        }
        html += `<button onclick="loadPingsPage('${ip}', ${totalPages})" class="px-3 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">${totalPages}</button>`;
    }

    // Next button
    if (currentPage < totalPages) {
        html += `<button onclick="loadPingsPage('${ip}', ${currentPage + 1})" class="px-3 py-1 text-sm bg-blue-500 text-white rounded hover:bg-blue-600 transition-colors">
            Siguiente<i class="fas fa-chevron-right ml-1"></i>
        </button>`;
    }

    html += '</div>';

    // Add page info
    const startItem = (currentPage - 1) * pingsPerPage + 1;
    const endItem = Math.min(currentPage * pingsPerPage, window.currentIpData.ping_results.length);
    html += `<div class="text-xs text-gray-500 dark:text-gray-400 mt-2 ml-2 text-center">
        Mostrando ${startItem}-${endItem} de ${window.currentIpData.ping_results.length} pings
    </div>`;

    return html;
}

/**
 * Filters the monitoring table based on search input, service, and status.
 */
function filterTable() {
    const searchInput = document.getElementById('searchFilter').value.toLowerCase();
    const serviceFilter = document.getElementById('serviceFilter').value.toLowerCase();
    const statusFilter = document.getElementById('statusFilter').value.toLowerCase();
    const tableBody = document.getElementById('monitoringTableBody');
    const rows = tableBody.getElementsByTagName('tr');

    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        // Skip if it's the "No IPs" row (usually has colspan)
        if (row.cells.length < 4) continue;

        const serviceCell = row.cells[0];
        const ipCell = row.cells[1];
        const statusCell = row.cells[3]; // Status column is at index 3 in both views

        if (!serviceCell || !ipCell || !statusCell) continue;

        const serviceText = serviceCell.textContent.trim().toLowerCase();
        const ipText = ipCell.textContent.trim().toLowerCase();
        const statusText = statusCell.textContent.trim().toLowerCase();

        const matchesSearch = ipText.includes(searchInput) || serviceText.includes(searchInput);
        const matchesService = serviceFilter === '' || serviceText.includes(serviceFilter);
        const matchesStatus = statusFilter === '' || statusText.includes(statusFilter);

        if (matchesSearch && matchesService && matchesStatus) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    }
}

/**
 * Resets all filters to default values.
 */
function resetFilters() {
    document.getElementById('searchFilter').value = '';
    document.getElementById('serviceFilter').value = '';
    document.getElementById('statusFilter').value = '';
    filterTable();
}

/**
 * Changes the number of items per page and reloads with page 1
 */
function changePerPage(perPage) {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('per_page', perPage);
    urlParams.set('page', '1'); // Reset to first page when changing items per page
    window.location.href = '?' + urlParams.toString() + '';
}

// Expose functions to global scope
window.filterTable = filterTable;
window.resetFilters = resetFilters;
window.changePerPage = changePerPage;

// Funciones para Manage Services
function showManageServiceForm() {
    showServicesList();
    modalFunctions.showModal('manageServiceForm');
}

function hideManageServiceForm() {
    modalFunctions.hideModal('manageServiceForm');
}

function showServicesList() {
    document.getElementById('servicesList').style.display = 'block';
    document.getElementById('serviceDetailsForm').style.display = 'none';
}

function editService(serviceName) {
    const config = window.servicesConfig[serviceName];
    if (config) {
        document.getElementById('old_service_name').value = serviceName;
        document.getElementById('edit_service_name').value = serviceName;
        document.getElementById('edit_service_color').value = config.color;
        document.getElementById('edit_service_method').value = config.method;

        document.getElementById('servicesList').style.display = 'none';
        document.getElementById('serviceDetailsForm').style.display = 'block';
    }
}

function deleteService(serviceName) {
    // Mostrar modal de confirmación personalizado
    window.showCustomConfirm(
        '¿Eliminar servicio?',
        '¿Estás seguro de que deseas eliminar el servicio <b>"' + serviceName + '"</b>? Esto eliminará <b>TODAS</b> las IPs asociadas. Esta acción no se puede deshacer.',
        function () {
            // Acción de eliminación
            const formData = new FormData();
            formData.append('clear_service', '1');
            formData.append('service_name', serviceName);
            fetch('?action=clear_service', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        modalFunctions.showAlert('Servicio eliminado correctamente', 'success');
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                        modalFunctions.showAlert('Error: ' + (data.message || 'Error desconocido'), 'error');
                    }
                })
                .catch(error => {
                    modalFunctions.showAlert('Error: ' + error.message, 'error');
                });
        }
    );
}

// Modal de confirmación reutilizable
window.showCustomConfirm = function (title, message, onConfirm) {
    var modal = document.getElementById('customConfirm');
    var header = modal.querySelector('.custom-confirm-header');
    var body = modal.querySelector('.custom-confirm-body');
    var btnOk = modal.querySelector('.custom-confirm-ok');
    var btnCancel = modal.querySelector('.custom-confirm-cancel');
    header.innerHTML = title;
    body.innerHTML = message;
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    btnOk.onclick = function () {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
        if (typeof onConfirm === 'function') onConfirm();
    };
    btnCancel.onclick = function () {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    };
};

/**
 * Toggles the service edit dropdown in the modal
 */
function toggleServiceEdit(ip) {
    const container = document.getElementById('serviceEditContainer');
    const display = document.getElementById('modalServiceDisplay');
    if (container.classList.contains('hidden')) {
        container.classList.remove('hidden');
        display.classList.add('hidden');
    } else {
        container.classList.add('hidden');
        display.classList.remove('hidden');
    }
}

/**
 * Saves the updated service for an IP
 */
async function saveIpService(ip) {
    const newService = document.getElementById('newServiceSelect').value;
    const saveBtn = document.querySelector('#serviceEditContainer button');

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    try {
        const response = await fetch('?action=update_ip_service', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                ip: ip,
                service: newService
            })
        });

        const data = await response.json();

        if (data.success) {
            modalFunctions.showAlert('Service updated successfully', 'success');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            modalFunctions.showAlert('Error: ' + (data.message || 'Unknown error'), 'error');
        }
    } catch (error) {
        modalFunctions.showAlert('Error: ' + error.message, 'error');
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = 'Save';
    }
}

window.toggleServiceEdit = toggleServiceEdit;
window.saveIpService = saveIpService;

/**
 * Handles the logic when a special device checkbox (Gateway/Repeater) is toggled.
 */
window.handleSpecialDeviceCheck = function (type) {
    const nameInput = document.getElementById('new_device_name');
    const gatewayCheck = document.getElementById('check_is_gateway');
    const repeaterCheck = document.getElementById('check_is_repeater');
    const networkSelect = document.getElementById('new_network_type');

    if (!nameInput || !gatewayCheck || !repeaterCheck) return;

    if (type === 'gateway') {
        if (gatewayCheck.checked) {
            repeaterCheck.checked = false;
            nameInput.value = 'Gateway';
            nameInput.readOnly = true;
            nameInput.classList.add('bg-gray-100', 'dark:bg-gray-800', 'cursor-not-allowed', 'opacity-60');
        } else {
            nameInput.readOnly = false;
            nameInput.classList.remove('bg-gray-100', 'dark:bg-gray-800', 'cursor-not-allowed', 'opacity-60');
        }
    } else if (type === 'repeater') {
        if (repeaterCheck.checked) {
            gatewayCheck.checked = false;
            nameInput.value = 'AP/Mesh';
            nameInput.readOnly = true;
            nameInput.classList.add('bg-gray-100', 'dark:bg-gray-800', 'cursor-not-allowed', 'opacity-60');
            // Auto-select Repeater network type
            if (networkSelect) networkSelect.value = 'AP/Mesh';
        } else {
            nameInput.readOnly = false;
            nameInput.classList.remove('bg-gray-100', 'dark:bg-gray-800', 'cursor-not-allowed', 'opacity-60');
        }
    }
};

window.toggleServiceEdit = toggleServiceEdit;
window.saveIpService = saveIpService;
/**
 * Shows the modal to change the service assigned to an IP.
 */
window.showChangeIpServiceModal = function (ip, currentService, isLocal = false, currentNetwork = '') {
    document.getElementById('change_service_ip').value = ip;
    document.getElementById('change_service_ip_display').value = ip;

    if (isLocal) {
        const nameInput = document.getElementById('new_device_name');
        const gatewayCheck = document.getElementById('check_is_gateway');
        const repeaterCheck = document.getElementById('check_is_repeater');
        const networkSelect = document.getElementById('new_network_type');

        nameInput.value = currentService;

        // Reset check states
        if (gatewayCheck) gatewayCheck.checked = (currentService === 'Gateway');
        if (repeaterCheck) repeaterCheck.checked = (currentService === 'AP/Mesh' || currentService === 'Repeater');

        // Dynamically populate Network Connection dropdown
        if (networkSelect) {
            let optionsHtml = `
                <option value="WiFi-2.4GHz">WiFi-2.4GHz</option>
                <option value="WiFi-5GHz">WiFi-5GHz</option>
                <option value="WiFi-6GHz">WiFi-6GHz</option>
                <option value="Ethernet">Ethernet</option>
            `;

            // Find all repeaters in the current data
            Object.entries(window.ipDetails || {}).forEach(([rip, rdata]) => {
                const sLower = (rdata.service || "").toLowerCase();
                const isR = !rip.endsWith('.1') && rdata.service !== 'Gateway' && (
                    sLower.includes('repeater') ||
                    sLower.includes('repetidor') ||
                    (sLower.includes('ap') && !sLower.includes('apple'))
                );

                if (isR && rip !== ip) {
                    const suffix = rip.split('.').pop();
                    const val = `Repeater (.${suffix})`;
                    optionsHtml += `<option value="${val}">${rdata.service} (.${suffix})</option>`;
                }
            });
            networkSelect.innerHTML = optionsHtml;
            networkSelect.value = currentNetwork || 'Ethernet';
        }

        // Apply readOnly if needed
        if (gatewayCheck && gatewayCheck.checked) {
            nameInput.readOnly = true;
            nameInput.classList.add('bg-gray-100', 'dark:bg-gray-800', 'cursor-not-allowed', 'opacity-60');
        } else if (repeaterCheck && repeaterCheck.checked) {
            nameInput.readOnly = true;
            nameInput.classList.add('bg-gray-100', 'dark:bg-gray-800', 'cursor-not-allowed', 'opacity-60');
        } else {
            nameInput.readOnly = false;
            nameInput.classList.remove('bg-gray-100', 'dark:bg-gray-800', 'cursor-not-allowed', 'opacity-60');
        }
    } else {
        document.getElementById('new_service_for_ip').value = currentService;
        // Reset inline form
        document.getElementById('newServiceForIpForm').style.display = 'none';
        document.getElementById('new_service_inline_name').value = '';
    }

    const modal = document.getElementById('changeIpServiceModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
};

/**
 * Closes the Change IP Service modal.
 */
window.closeChangeIpServiceModal = function () {
    const modal = document.getElementById('changeIpServiceModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
};

/**
 * Toggles the inline service creation form in the Change IP Service modal.
 */
window.toggleNewServiceFormInChangeModal = function () {
    const select = document.getElementById('new_service_for_ip');
    const form = document.getElementById('newServiceForIpForm');
    const nameInput = document.getElementById('new_service_inline_name');

    if (select.value === 'create_new') {
        form.style.display = 'block';
        nameInput.required = true;
    } else {
        form.style.display = 'none';
        nameInput.required = false;
    }
};

// Initialize inline color preview for the new modal
document.addEventListener('DOMContentLoaded', function () {
    const colorInput = document.getElementById('new_service_inline_color');
    if (colorInput) {
        colorInput.addEventListener('input', function () {
            document.getElementById('new_service_inline_color_preview').style.backgroundColor = this.value;
        });
    }
});

/**
 * Mobile Navigation Functions
 * Handles responsive sidebar and header menu toggles
 */

// Toggle mobile sidebar visibility
window.toggleSidebar = function () {
    const sidebarPanel = document.getElementById('sidebarPanel');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    if (sidebarPanel && sidebarOverlay) {
        const isVisible = sidebarPanel.classList.contains('translate-x-0');

        if (isVisible) {
            // Hide sidebar
            sidebarPanel.classList.remove('translate-x-0');
            sidebarPanel.classList.add('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
        } else {
            // Show sidebar
            sidebarPanel.classList.add('translate-x-0');
            sidebarPanel.classList.remove('-translate-x-full');
            sidebarOverlay.classList.remove('hidden');
        }
    }
};

// Toggle desktop sidebar collapse/expand
window.toggleDesktopSidebar = function () {
    const sidebarPanel = document.getElementById('sidebarPanel');
    const toggleBtn = document.getElementById('desktopSidebarToggle');

    if (sidebarPanel && toggleBtn) {
        const isCollapsed = sidebarPanel.classList.contains('sidebar-collapsed');

        if (isCollapsed) {
            // Expand sidebar
            sidebarPanel.classList.remove('sidebar-collapsed');
            sidebarPanel.classList.add('sidebar-expanded');
            sidebarPanel.style.width = '16rem'; // w-64
            if (toggleBtn.querySelector('i')) {
                toggleBtn.querySelector('i').style.transform = 'rotate(0deg)';
            }
            // Save preference to localStorage
            localStorage.setItem('sidebarCollapsed', 'false');
        } else {
            // Collapse sidebar
            sidebarPanel.classList.add('sidebar-collapsed');
            sidebarPanel.classList.remove('sidebar-expanded');
            sidebarPanel.style.width = '4.5rem';
            if (toggleBtn.querySelector('i')) {
                toggleBtn.querySelector('i').style.transform = 'rotate(180deg)';
            }
            // Save preference to localStorage
            localStorage.setItem('sidebarCollapsed', 'true');
        }
    }
};

// Restore sidebar state on page load
document.addEventListener('DOMContentLoaded', function () {
    const sidebarPanel = document.getElementById('sidebarPanel');
    const toggleBtn = document.getElementById('desktopSidebarToggle');

    if (sidebarPanel) {
        // Check localStorage for saved preference
        const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';

        if (isCollapsed) {
            sidebarPanel.classList.add('sidebar-collapsed');
            sidebarPanel.classList.remove('sidebar-expanded');
            sidebarPanel.style.width = '4.5rem';
            if (toggleBtn && toggleBtn.querySelector('i')) {
                toggleBtn.querySelector('i').style.transform = 'rotate(180deg)';
            }
        }
    }
});

// Toggle mobile header menu
window.toggleMobileMenu = function () {
    const mobileNavMenu = document.getElementById('mobileNavMenu');
    if (mobileNavMenu) {
        if (mobileNavMenu.style.display === 'none') {
            mobileNavMenu.style.display = 'block';
        } else {
            mobileNavMenu.style.display = 'none';
        }
    }
};

// Close mobile menu when a link is clicked
document.addEventListener('DOMContentLoaded', function () {
    // Close mobile nav when clicking on link
    const mobileNavLinks = document.querySelectorAll('#mobileNavMenu a');
    mobileNavLinks.forEach(link => {
        link.addEventListener('click', function () {
            const mobileNavMenu = document.getElementById('mobileNavMenu');
            if (mobileNavMenu) {
                mobileNavMenu.style.display = 'none';
            }
        });
    });

    // Close mobile menu when clicking outside
    document.addEventListener('click', function (event) {
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const mobileNavMenu = document.getElementById('mobileNavMenu');
        const header = document.querySelector('header');

        if (mobileNavMenu && mobileMenuBtn && header && header.contains(event.target)) {
            // Click inside header, check if it's not the menu button
            if (!mobileMenuBtn.contains(event.target) && !mobileNavMenu.contains(event.target)) {
                mobileNavMenu.style.display = 'none';
            }
        } else if (mobileNavMenu && event.target !== mobileMenuBtn && !mobileNavMenu.contains(event.target)) {
            mobileNavMenu.style.display = 'none';
        }
    });
});

// Handle responsive sidebar behavior
window.addEventListener('resize', function () {
    const sidebarPanel = document.getElementById('sidebarPanel');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    if (window.innerWidth >= 1024) {
        // Hide mobile sidebar on desktop
        if (sidebarPanel) sidebarPanel.classList.remove('-translate-x-full');
        if (sidebarOverlay) sidebarOverlay.classList.add('hidden');
    }
});
