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
    window.location.href = window.location.pathname;
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
    confirmModal.style.zIndex = '100'; // Más alto que el modal de detalle
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
function showIpDetailModal(ip) {
    const ipData = window.ipDetails[ip];
    if (!ipData) return;

    currentPage = 1; // Reset to first page
    document.getElementById('modalIpTitle').innerText = `IP info: ${ip}`;

    // Uptime color logic
    let uptimeColors = '';
    let uptimeTextColor = '';
    if (ipData.percentage >= 90) {
        uptimeColors = 'bg-gradient-to-r from-green-50 to-green-100 dark:from-green-900 dark:to-green-800 text-green-600 dark:text-green-300';
        uptimeTextColor = 'text-green-700 dark:text-green-400';
    } else if (ipData.percentage >= 75) {
        uptimeColors = 'bg-gradient-to-r from-yellow-50 to-yellow-100 dark:from-yellow-900 dark:to-yellow-800 text-yellow-600 dark:text-yellow-300';
        uptimeTextColor = 'text-yellow-700 dark:text-yellow-400';
    } else {
        uptimeColors = 'bg-gradient-to-r from-red-50 to-red-100 dark:from-red-900 dark:to-red-800 text-red-600 dark:text-red-300';
        uptimeTextColor = 'text-red-700 dark:text-red-400';
    }

    // Ping color logic
    let pingColors = '';
    let pingTextColor = '';
    if (ipData.average_response_time === 'N/A') {
        pingColors = 'bg-gradient-to-r from-gray-50 to-gray-100 dark:from-gray-900 dark:to-gray-800 text-gray-600 dark:text-gray-300';
        pingTextColor = 'text-gray-700 dark:text-gray-400';
    } else if (ipData.average_response_time > 100) {
        pingColors = 'bg-gradient-to-r from-red-50 to-red-100 dark:from-red-900 dark:to-red-800 text-red-600 dark:text-red-300';
        pingTextColor = 'text-red-700 dark:text-red-400';
    } else if (ipData.average_response_time > 50) {
        pingColors = 'bg-gradient-to-r from-yellow-50 to-yellow-100 dark:from-yellow-900 dark:to-yellow-800 text-yellow-600 dark:text-yellow-300';
        pingTextColor = 'text-yellow-700 dark:text-yellow-400';
    } else {
        pingColors = 'bg-gradient-to-r from-green-50 to-green-100 dark:from-green-900 dark:to-green-800 text-green-600 dark:text-green-300';
        pingTextColor = 'text-green-700 dark:text-green-400';
    }

    document.getElementById('modalIpContent').innerHTML = `
        <div class='mb-6'>
            <div class='grid grid-cols-1 md:grid-cols-4 gap-4 mb-4'>
                <div class='${uptimeColors} rounded-lg p-4 text-center'>
                    <div class='text-2xl font-bold'>${Math.round(ipData.percentage)}%</div>
                    <div class='text-sm ${uptimeTextColor}'>Uptime</div>
                </div>
                <div class='${pingColors} rounded-lg p-4 text-center'>
                    <div class='text-2xl font-bold'>${typeof ipData.average_response_time === 'number' ? ipData.average_response_time.toFixed(2) : ipData.average_response_time} ms</div>
                    <div class='text-sm ${pingTextColor}'>Avg. Latency</div>
                </div>
                <div class='rounded-lg p-4 text-center' style='background-color: ${ipData.service_color}; color: ${ipData.service_text_color};'>
                    <div class='text-xl font-bold'>${ipData.service}</div>
                    <div class='text-sm opacity-80 mt-1'>Service</div>
                </div>
                <div class='bg-gradient-to-r from-indigo-50 to-purple-50 dark:from-indigo-900 dark:to-purple-800 text-indigo-600 dark:text-indigo-300 rounded-lg p-4 text-center'>
                    <div class='text-xl font-bold'>${ipData.method || 'ICMP'}</div>
                    <div class='text-sm text-indigo-700 dark:text-indigo-400 mt-1'>Method</div>
                </div>
            </div>
        </div>
        
        <div class='mb-4'>
            <div class='flex justify-between items-center mb-3'>
                <h4 class='text-md font-semibold text-gray-700 dark:text-gray-300 flex items-center'>
                    <i class='fas fa-history mr-2 text-blue-500'></i>
                    Pings History
                </h4>
                <div class='text-sm text-gray-500 dark:text-gray-400'>
                    Total: ${ipData.ping_results.length} pings
                </div>
            </div>
            <div id='pingsContainer' class='grid gap-1 mb-4'>
                <!-- Los pings se cargan aquí -->
            </div>
            <div id='paginationContainer' class='flex justify-center'>
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
        if (row.cells.length < 2) continue;

        const serviceCell = row.cells[0];
        const ipCell = row.cells[1];
        const methodCell = row.cells[2]; // New Method column
        const statusCell = row.cells[3]; // Status moved to column 3

        if (!serviceCell || !ipCell || !statusCell) continue;

        const serviceText = serviceCell.textContent.trim().toLowerCase();
        const ipText = ipCell.textContent.trim().toLowerCase();
        const statusText = statusCell.textContent.trim().toLowerCase();

        const matchesSearch = ipText.includes(searchInput);
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
    window.location.search = urlParams.toString();
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
    if (confirm('Are you sure you want to delete the service "' + serviceName + '"? This will delete ALL IPs associated with this service. This action cannot be undone.')) {
        document.getElementById('delete_service_name_input').value = serviceName;
        document.getElementById('deleteServiceForm').submit();
    }
}

window.showManageServiceForm = showManageServiceForm;
window.hideManageServiceForm = hideManageServiceForm;
window.showServicesList = showServicesList;
window.editService = editService;
window.deleteService = deleteService;