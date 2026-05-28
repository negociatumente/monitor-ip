/**
 * IP Monitor - Main JavaScript
 */
/**
 * Theme Toggle Functionality
 */
function toggleTheme() {
    if (document.documentElement.classList.contains('dark')) {
        document.documentElement.classList.remove('dark');
        localStorage.theme = 'light';
    } else {
        document.documentElement.classList.add('dark');
        localStorage.theme = 'dark';
    }
}

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

        alertModal.style.zIndex = '300';

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

    showChangePasswordModal: function () { this.showModal('changePasswordModal'); },
    hideChangePasswordModal: function () { this.hideModal('changePasswordModal'); },

    showTelegramConfigModal: function () {
        applyTelegramConfigToForm(window.telegramConfig);
        this.showModal('telegramConfigModal');
    },
    hideTelegramConfigModal: function () { this.hideModal('telegramConfigModal'); },
    showAIConfigModal: function () {
        loadAIConfigIntoForm();
        this.showModal('aiConfigModal');
    },
    hideAIConfigModal: function () { this.hideModal('aiConfigModal'); },
};

/**
 * Updates the countdown timer for the next ping and reloads the page when it reaches zero.
 */
function formatSecondsToMinSec(totalSeconds) {
    const seconds = Math.max(0, parseInt(totalSeconds, 10) || 0);
    const minutesPart = Math.floor(seconds / 60);
    const secondsPart = seconds % 60;
    return `${minutesPart}m ${secondsPart}s`;
}

function updateCountdown() {
    if (pingStopped) return;
    const countdownElement = document.getElementById('countdown');
    if (countdownElement) {
        countdownElement.innerText = formatSecondsToMinSec(countdown);
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
        'update_ip_service', 'change_timer', 'change_password',
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
window.showClearDataConfirmation = function () { modalFunctions.showClearDataConfirmation(); };
window.hideClearDataConfirmation = function () { modalFunctions.hideClearDataConfirmation(); };
window.showConfigModal = function () { modalFunctions.showConfigModal(); };
window.hideConfigModal = function () { modalFunctions.hideConfigModal(); };
window.showChangePasswordModal = function () { modalFunctions.showChangePasswordModal(); };
window.hideChangePasswordModal = function () { modalFunctions.hideChangePasswordModal(); };
window.showTelegramConfigModal = function () { modalFunctions.showTelegramConfigModal(); };
window.hideTelegramConfigModal = function () { modalFunctions.hideTelegramConfigModal(); };
window.showAIConfigModal = function () { modalFunctions.showAIConfigModal(); };
window.hideAIConfigModal = function () { modalFunctions.hideAIConfigModal(); };

function getAIConfigPath() {
    return (window.aiConfig?.gpt_path || '').trim();
}

function getAIProvider() {
    return (window.aiConfig?.provider || 'chatgpt').trim();
}

function getAIBaseUrl() {
    return (window.aiConfig?.base_url || 'https://chatgpt.com').trim();
}

function normalizeAIConfigPath(rawValue) {
    let value = (rawValue || '').trim();
    value = value.replace(/^https?:\/\/(www\.)?chatgpt\.com\//i, '');
    value = value.replace(/^\/+/, '');
    if (/^g-[a-zA-Z0-9]+/.test(value)) {
        value = `g/${value}`;
    }
    return value;
}

function normalizeAIBaseUrl(rawValue) {
    let value = (rawValue || '').trim();
    if (!value) return 'https://chatgpt.com';
    if (!/^https?:\/\//i.test(value)) {
        value = `https://${value}`;
    }
    return value.replace(/\/+$/, '');
}

async function loadAIConfigIntoForm() {
    try {
        const response = await fetch('?action=get_ai_config');
        const data = await response.json();
        if (data?.success && data.config) {
            window.aiConfig = data.config;
        }
    } catch (error) {
        console.error('Failed to load AI config from DB:', error);
    }

    const providerSelect = document.getElementById('aiProviderSelect');
    const urlInput = document.getElementById('aiBaseUrlInput');
    const input = document.getElementById('aiGptPathInput');
    if (providerSelect) providerSelect.value = getAIProvider();
    if (urlInput) urlInput.value = getAIBaseUrl();
    if (!input) return;
    input.value = getAIConfigPath();
}

async function saveAIConfig() {
    const providerSelect = document.getElementById('aiProviderSelect');
    const urlInput = document.getElementById('aiBaseUrlInput');
    const input = document.getElementById('aiGptPathInput');
    if (!input || !providerSelect) return;
    const provider = providerSelect.value || 'chatgpt';
    const baseUrl = normalizeAIBaseUrl(urlInput ? urlInput.value : getAIBaseUrl());
    const normalized = normalizeAIConfigPath(input.value);

    try {
        const response = await fetch('?action=save_ai_config', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                provider,
                base_url: baseUrl,
                gpt_path: normalized
            })
        });
        const data = await response.json();
        if (!data?.success) {
            throw new Error(data?.message || 'Failed to save AI configuration');
        }
        window.aiConfig = data.config || { provider, base_url: baseUrl, gpt_path: normalized };
        modalFunctions.hideAIConfigModal();
        modalFunctions.showAlert('Configuración IA guardada en base de datos.', 'success');
    } catch (error) {
        console.error('Failed to save AI config:', error);
        modalFunctions.showAlert('No se pudo guardar la configuración IA.', 'error');
    }
}

function buildChatGPTUrlForPrompt(promptText) {
    const prompt = encodeURIComponent(promptText || '');
    const path = getAIConfigPath();
    const base = getAIBaseUrl() || 'https://chatgpt.com';

    if (!path) {
        return `${base}/?q=${prompt}`;
    }

    const target = `${base}/${path}`;
    const separator = target.includes('?') ? '&' : '?';
    return `${target}${separator}q=${prompt}&prompt=${prompt}`;
}

window.saveAIConfig = saveAIConfig;
window.buildChatGPTUrlForPrompt = buildChatGPTUrlForPrompt;

/**
 * User account dropdown in the header.
 */
function toggleUserMenu(event) {
    if (event) event.stopPropagation();
    const dropdown = document.getElementById('userMenuDropdown');
    const btn = document.getElementById('userMenuBtn');
    const chevron = document.getElementById('userMenuChevron');
    if (!dropdown) return;

    const willOpen = dropdown.classList.contains('hidden');
    closeUserMenu();

    if (willOpen) {
        dropdown.classList.remove('hidden');
        chevron?.classList.add('rotate-180');
        btn?.setAttribute('aria-expanded', 'true');
    }
}

function closeUserMenu() {
    const dropdown = document.getElementById('userMenuDropdown');
    const btn = document.getElementById('userMenuBtn');
    const chevron = document.getElementById('userMenuChevron');
    dropdown?.classList.add('hidden');
    chevron?.classList.remove('rotate-180');
    btn?.setAttribute('aria-expanded', 'false');
}

function toggleMobileUserMenu(event) {
    if (event) event.stopPropagation();
    const dropdown = document.getElementById('mobileUserMenuDropdown');
    const btn = document.getElementById('mobileUserMenuBtn');
    const chevron = document.getElementById('mobileUserMenuChevron');
    if (!dropdown) return;

    const willOpen = dropdown.classList.contains('hidden');
    closeMobileUserMenu();

    if (willOpen) {
        dropdown.classList.remove('hidden');
        chevron?.classList.add('rotate-180');
        btn?.setAttribute('aria-expanded', 'true');
    }
}

function closeMobileUserMenu() {
    const dropdown = document.getElementById('mobileUserMenuDropdown');
    const btn = document.getElementById('mobileUserMenuBtn');
    const chevron = document.getElementById('mobileUserMenuChevron');
    dropdown?.classList.add('hidden');
    chevron?.classList.remove('rotate-180');
    btn?.setAttribute('aria-expanded', 'false');
}

document.addEventListener('click', function (event) {
    const container = document.getElementById('userMenuContainer');
    if (container && !container.contains(event.target)) {
        closeUserMenu();
    }
    const mobileContainer = document.getElementById('mobileUserMenuContainer');
    if (mobileContainer && !mobileContainer.contains(event.target)) {
        closeMobileUserMenu();
    }
});

window.toggleUserMenu = toggleUserMenu;
window.closeUserMenu = closeUserMenu;
window.toggleMobileUserMenu = toggleMobileUserMenu;
window.closeMobileUserMenu = closeMobileUserMenu;
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
 * Toggles password visibility for input fields
 */
function togglePasswordVisibility(inputId) {
    const input = document.getElementById(inputId);
    const button = input.nextElementSibling;
    const icon = button.querySelector('i');

    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

window.togglePasswordVisibility = togglePasswordVisibility;

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
 * Changes the timer value and updates the button visuals.
 */
function setTimerValue(val) {
    var input = document.getElementById('new_timer_value');
    input.value = val;

    // Reset all timer buttons
    document.querySelectorAll('.timer-btn').forEach(btn => {
        btn.className = btn.className.replace(/bg-(red|blue|green)-500|text-white|border-(red|blue|green)-500|shadow-lg/g, '');
        if (btn.id === 'timer-btn-60') {
            btn.className = btn.className + ' bg-white text-gray-700 border-gray-200 hover:bg-red-50 hover:border-red-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-red-900/20';
        } else if (btn.id === 'timer-btn-300') {
            btn.className = btn.className + ' bg-white text-gray-700 border-gray-200 hover:bg-blue-50 hover:border-blue-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-blue-900/20';
        } else if (btn.id === 'timer-btn-900') {
            btn.className = btn.className + ' bg-white text-gray-700 border-gray-200 hover:bg-green-50 hover:border-green-300 dark:bg-gray-700 dark:text-gray-200 dark:border-gray-600 dark:hover:bg-green-900/20';
        }
    });

    // Set active button
    var activeBtn = document.getElementById('timer-btn-' + val);
    if (activeBtn) {
        activeBtn.className = activeBtn.className.replace(/bg-white|text-gray-700|border-gray-200|hover:bg-\w+-50|hover:border-\w+-300|dark:bg-gray-700|dark:text-gray-200|dark:border-gray-600|dark:hover:bg-\w+-900\/20/g, '');
        if (val == 60) {
            activeBtn.className = activeBtn.className + ' bg-red-500 text-white border-red-500 shadow-lg';
        } else if (val == 300) {
            activeBtn.className = activeBtn.className + ' bg-blue-500 text-white border-blue-500 shadow-lg';
        } else if (val == 900) {
            activeBtn.className = activeBtn.className + ' bg-green-500 text-white border-green-500 shadow-lg';
        }
    }
}

/**
 * Closes the IP detail modal.
 */
function closeIpModal() {
    document.getElementById('ipDetailModal').classList.add('hidden');
    window.currentIpData = null;
}

/**
 * Shows the confirmation modal to delete an IP from the detail view.
 */
function showDeleteConfirmFromDetail() {
    const ip = window.currentIpData?.ip;
    if (!ip) return;
    const confirmModal = document.getElementById('deleteIpForm');
    document.getElementById('delete_ip').value = ip;
    confirmModal.style.zIndex = '200'; // Más alto que el modal de detalle
    confirmModal.style.display = 'flex';
}

/**
 * Creates the ping latency chart using Chart.js.
 */
function createPingChart(ipData) {
    const canvas = document.getElementById('pingChart');
    const ctx = canvas.getContext('2d');
    const timeline = document.getElementById('pingStatusTimeline');
    const chartResults = ipData.ping_results_24h || ipData.ping_results;
    const reversedResults = [...chartResults].reverse();
    const formatPingDate = function (timestamp) {
        if (!timestamp) return '';
        const normalized = timestamp.replace(' ', 'T');
        const date = new Date(normalized);
        if (Number.isNaN(date.getTime())) return timestamp;
        return date.toLocaleString('es-ES', {
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    };
    const formatFullPingDate = function (timestamp) {
        if (!timestamp) return '';
        const normalized = timestamp.replace(' ', 'T');
        const date = new Date(normalized);
        if (Number.isNaN(date.getTime())) return timestamp;
        return date.toLocaleString('es-ES', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    };
    const labels = reversedResults.map(p => {
        return formatPingDate(p.timestamp || '');
    });
    const data = reversedResults.map(p => {
        let val = p.response_time;
        if (typeof val === 'string') {
            val = parseFloat(val.replace('ms', '').replace(' ', ''));
        }
        return isNaN(val) ? null : val;
    });
    const statuses = reversedResults.map(p => p.status || 'DOWN');
    const validValues = data.filter(val => val !== null && val !== undefined);
    const maxLatency = validValues.length ? Math.max(...validValues) : 1;
    const outageMarkerValue = Math.max(maxLatency * 1.08, 1);
    const outageData = reversedResults.map((p, index) => p.status === 'DOWN' ? outageMarkerValue : null);

    const hasValidData = data.some(val => val !== null && val !== undefined);

    if (window.pingChartInstance) {
        window.pingChartInstance.destroy();
    }

    if (timeline) {
        if (reversedResults.length === 0) {
            timeline.innerHTML = `<div class="text-xs text-gray-400 italic">No ping samples in the last 24h</div>`;
        } else {
            timeline.innerHTML = reversedResults.map((p, index) => {
                const status = p.status || 'DOWN';
                const responseTime = p.response_time || 'N/A';
                const timestamp = formatFullPingDate(p.timestamp || '-');
                const color = status === 'UP'
                    ? 'bg-green-500 hover:bg-green-400'
                    : 'bg-red-500 hover:bg-red-400';
                const label = status === 'UP' ? 'UP' : 'DOWN';
                return `<span class="block h-4 min-w-2 flex-1 rounded-sm ${color} transition-colors" title="${label} - ${responseTime} - ${timestamp}" aria-label="${label} ${timestamp}"></span>`;
            }).join('');
        }
    }

    if (!hasValidData && reversedResults.length === 0) {
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
        const gradient = ctx.createLinearGradient(0, 0, 0, canvas.parentElement?.clientHeight || 260);
        gradient.addColorStop(0, 'rgba(37,99,235,0.35)');
        gradient.addColorStop(0.55, 'rgba(37,99,235,0.12)');
        gradient.addColorStop(1, 'rgba(37,99,235,0)');
        const pointColors = statuses.map(status => status === 'UP' ? '#22c55e' : '#ef4444');

        window.pingChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Latency (ms)',
                        data: data,
                        borderColor: '#2563eb',
                        backgroundColor: gradient,
                        pointBackgroundColor: pointColors,
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: statuses.map(status => status === 'DOWN' ? 5 : 3),
                        pointHoverRadius: 7,
                        borderWidth: 3,
                        fill: true,
                        tension: 0.35,
                        spanGaps: true
                    },
                    {
                        label: 'Down',
                        data: outageData,
                        borderColor: 'transparent',
                        backgroundColor: '#ef4444',
                        pointBackgroundColor: '#ef4444',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: outageData.map(value => value === null ? 0 : 7),
                        pointHoverRadius: outageData.map(value => value === null ? 0 : 9),
                        pointStyle: 'triangle',
                        showLine: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        suggestedMax: outageMarkerValue * 1.12,
                        grid: {
                            color: 'rgba(148,163,184,0.18)'
                        },
                        title: {
                            display: true,
                            text: 'ms'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 8
                        },
                        title: {
                            display: true,
                            text: 'Fecha'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            title: function (items) {
                                const index = items[0]?.dataIndex ?? 0;
                                return formatFullPingDate(reversedResults[index]?.timestamp) || labels[index] || '';
                            },
                            label: function (item) {
                                const index = item.dataIndex;
                                const ping = reversedResults[index];
                                if (item.dataset.label === 'Down') {
                                    return ping?.status === 'DOWN' ? 'Status: DOWN' : '';
                                }
                                const latency = item.parsed.y;
                                const status = ping?.status || 'UNKNOWN';
                                return latency === null ? `Status: ${status}` : `Latency: ${latency.toFixed(2)} ms (${status})`;
                            }
                        }
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

    const escapeHtml = function (value) {
        return String(value ?? '').replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    };
    const isLocal = new URLSearchParams(window.location.search).get('network') === 'local';
    const displayName = ipData.service || ip;
    const displayType = ipData.type || 'N/A';
    const contextLabel = isLocal ? 'Network' : 'Service';
    const contextValue = isLocal ? (ipData.network_type || 'Unknown') : (ipData.service || 'Default');
    document.getElementById('modalIpTitle').innerHTML = `
        <span class="block truncate leading-tight">${escapeHtml(displayName)}</span>
        <span class="block text-xs sm:text-sm font-medium text-white/75 truncate leading-tight">
            ${escapeHtml(ip)} · ${escapeHtml(displayType)} · ${escapeHtml(contextValue)}
        </span>
    `;

    // Reset tabs
    switchIpDetailTab(startTab);

    // Clear previous diagnostic results
    const visual = document.getElementById('detail_traceroute_visual');
    const raw = document.getElementById('detail_traceroute_raw');
    const geo = document.getElementById('detail_geoip_container');
    const ai = document.getElementById('detail_aireport_content');
    const tracerouteSection = document.getElementById('detail_traceroute_section');

    if (visual) visual.innerHTML = '<div class="flex flex-col items-center justify-center py-10 text-gray-400 italic">Click "Run Traceroute" to analyze path...</div>';
    if (raw) raw.textContent = '-- Raw output --';
    if (geo) {
        if (isLocal) {
            geo.innerHTML = `
                <div class="col-span-2 rounded-3xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900/70 p-6 sm:p-8 shadow-sm">
                    <div class="flex flex-col items-center text-center">
                        <div class="w-14 h-14 rounded-2xl bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-300 flex items-center justify-center mb-4">
                            <i class="fas fa-stethoscope text-xl"></i>
                        </div>
                        <h4 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-slate-100">Local diagnostics</h4>
                        <p class="mt-2 text-sm sm:text-base text-slate-500 dark:text-slate-400 max-w-md">Run an on-demand LAN test for connectivity, device identity and common services.</p>
                        <button type="button" onclick="runLocalDiagnosticsDetail('${escapeHtml(ip)}')"
                            class="mt-5 inline-flex items-center px-5 py-3 rounded-2xl bg-blue-600 text-white font-black hover:bg-blue-700 transition-colors">
                            <i class="fas fa-play mr-2"></i>Run diagnosis test
                        </button>
                    </div>
                </div>`;
        } else {
            geo.innerHTML = '<div class="col-span-2 py-10 flex flex-col items-center"><i class="fas fa-spinner fa-spin text-2xl mb-4 text-purple-500"></i><p class="text-sm text-gray-400">Initiating geolocation analysis...</p></div>';
        }
    }
    if (ai) ai.innerText = 'Waiting for analysis trigger...';

    // Tabs are available for both private and public networks.
    const diagTab = document.getElementById('ipTabDiagnostics');
    const aiTab = document.getElementById('ipTabAIReport');
    const tabsNav = document.getElementById('ipDetailTabsNav');

    diagTab.classList.remove('hidden');
    aiTab.classList.remove('hidden');
    tabsNav.classList.remove('hidden');

    if (tracerouteSection) {
        tracerouteSection.classList.toggle('hidden', isLocal);
    }

    if (!isLocal) {
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

    let monthlyUptimeColors = '';
    const monthlyPercentage = Number(ipData.monthly_percentage || 0);
    const monthlySamples = Number(ipData.sample_count_30d || 0);
    const monthlyDisplay = monthlySamples > 0 ? `${monthlyPercentage.toFixed(2)}%` : 'N/A';
    if (monthlySamples === 0) {
        monthlyUptimeColors = 'bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-900/40 dark:to-gray-800/20 text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-800';
    } else if (monthlyPercentage >= 90) {
        monthlyUptimeColors = 'bg-gradient-to-br from-emerald-50 to-green-100 dark:from-emerald-900/40 dark:to-green-800/20 text-emerald-600 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800';
    } else if (monthlyPercentage >= 75) {
        monthlyUptimeColors = 'bg-gradient-to-br from-amber-50 to-yellow-100 dark:from-amber-900/40 dark:to-yellow-800/20 text-amber-600 dark:text-amber-300 border border-amber-200 dark:border-amber-800';
    } else {
        monthlyUptimeColors = 'bg-gradient-to-br from-rose-50 to-red-100 dark:from-rose-900/40 dark:to-red-800/20 text-rose-600 dark:text-rose-300 border border-rose-200 dark:border-rose-800';
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

    const latencyDisplay = typeof ipData.average_response_time === 'number' ? ipData.average_response_time.toFixed(2) : ipData.average_response_time;
    document.getElementById('modalIpContent').innerHTML = `
        <div class='grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4'>
            <div class='${uptimeColors} rounded-3xl p-5 sm:p-6 shadow-sm'>
                <div class='text-[11px] uppercase font-black tracking-[0.16em] opacity-60'>Uptime 24h</div>
                <div class='mt-3 text-4xl sm:text-5xl font-black leading-none'>${ipData.percentage.toFixed(2)}%</div>
            </div>
            <div class='${pingColors} rounded-3xl p-5 sm:p-6 shadow-sm'>
                <div class='text-[11px] uppercase font-black tracking-[0.16em] opacity-60'>Latency 24h</div>
                <div class='mt-3 text-4xl sm:text-5xl font-black leading-none'>${latencyDisplay}</div>
                <div class='mt-1 text-sm font-bold opacity-60'>ms avg</div>
            </div>
            <div class='${monthlyUptimeColors} rounded-3xl p-5 sm:p-6 shadow-sm'>
                <div class='text-[11px] uppercase font-black tracking-[0.16em] opacity-60'>Uptime Month</div>
                <div class='mt-3 text-4xl sm:text-5xl font-black leading-none'>${monthlyDisplay}</div>
            </div>
        </div>`;

    window.currentIpData = ipData;

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
        if (tabId === 'diagnostics' && window.currentIpData && new URLSearchParams(window.location.search).get('network') !== 'local') {
            runGeoIPDetail(window.currentIpData.ip);
        } else if (tabId === 'aireport' && window.currentIpData) {
            generateAIReportDetail(window.currentIpData.ip);
        }
    }
}

window.showIpDetailModal = showIpDetailModal;
window.switchIpDetailTab = switchIpDetailTab;

/**
 * Filters the monitoring table based on search input, service, and status.
 */
function filterTable() {
    const searchInput = document.getElementById('searchFilter') ? document.getElementById('searchFilter').value.toLowerCase() : '';
    const statusFilter = document.getElementById('statusFilter') ? document.getElementById('statusFilter').value.toLowerCase() : '';
    const typeFilter = document.getElementById('typeFilter') ? document.getElementById('typeFilter').value.toLowerCase() : '';
    const tableBody = document.getElementById('monitoringTableBody');
    const rows = tableBody.getElementsByTagName('tr');

    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        // Skip if it's the "No IPs" row (usually has colspan)
        if (row.cells.length < 4) continue;

        const hostCell = row.cells[0];
        const typeCell = row.cells[1];
        const ipCell = row.cells[2];

        // Status column is at index 4 for local network, 3 for external
        const isLocal = document.body.contains(document.getElementById('scanNetworkModal')); // Simple check
        const statusCell = isLocal ? row.cells[4] : row.cells[3];

        if (!hostCell || !typeCell || !ipCell || !statusCell) continue;

        const hostText = hostCell.textContent.trim().toLowerCase();
        const typeText = typeCell.textContent.trim().toLowerCase();
        const ipText = ipCell.textContent.trim().toLowerCase();
        const statusText = statusCell.textContent.trim().toLowerCase();

        const matchesSearch = ipText.includes(searchInput) || hostText.includes(searchInput) || typeText.includes(searchInput);
        const matchesType = typeFilter === '' || typeText.includes(typeFilter);
        const matchesStatus = statusFilter === '' || statusText === statusFilter;

        if (matchesSearch && matchesType && matchesStatus) {
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
    if (document.getElementById('searchFilter')) document.getElementById('searchFilter').value = '';
    if (document.getElementById('statusFilter')) document.getElementById('statusFilter').value = '';
    if (document.getElementById('typeFilter')) document.getElementById('typeFilter').value = '';
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
// handleSpecialDeviceCheck removed as it's replaced by a select menu
window.handleSpecialDeviceCheck = function (type) { };

window.toggleServiceEdit = toggleServiceEdit;
window.saveIpService = saveIpService;
/**
 * Shows the modal to change the service assigned to an IP.
 */
window.showChangeIpServiceModal = function (ip, currentService, isLocal = false, currentNetwork = '') {
    document.getElementById('change_service_ip').value = ip;
    document.getElementById('change_service_ip_display').value = ip;

    // Set current type
    const editTypeInput = document.getElementById('edit_type');
    if (editTypeInput && window.ipDetails && window.ipDetails[ip]) {
        editTypeInput.value = window.ipDetails[ip].type || '';
    }
    const typeInput = document.getElementById('new_device_type');
    if (typeInput && window.ipDetails && window.ipDetails[ip]) {
        let validTypes = []
        if (isLocal) {
            validTypes = ['gateway', 'router', 'ap-mesh', 'camera', 'mobile', 'computer', 'printer', 'iot', 'other'];
        } else {
            validTypes = ['web', 'server', 'cdn', 'iot', 'other'];
        }
        const typeMapping = {
            'camara': 'camera',
            'movil': 'mobile',
            'ordenador': 'computer',
            'impresora': 'printer',
            'otro': 'other',
            'servidor': 'server',
            'iot': 'iot'
        };
        let currentType = (window.ipDetails[ip].type || '').toLowerCase();
        currentType = typeMapping[currentType] || currentType;
        typeInput.value = validTypes.includes(currentType) ? currentType : 'other';
    }
    if (isLocal) {
        const nameInput = document.getElementById('new_device_name');
        const networkSelect = document.getElementById('new_network_type');
        if (nameInput) {
            nameInput.value = currentService; // currentService is ips-host (Name)
        }

        // Reset check states (checks removed from UI)

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

        // Apply readOnly if needed (logic removed as checkboxes are gone)
        if (nameInput) {
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

function setTelegramFieldValue(id, value) {
    const field = document.getElementById(id);
    if (field) {
        field.value = value ?? '';
    }
}

function setTelegramCheckboxValue(id, value) {
    const field = document.getElementById(id);
    if (field) {
        field.checked = Boolean(value);
    }
}

function applyTelegramConfigToForm(telegram) {
    if (!telegram) return;

    setTelegramCheckboxValue('enabled', telegram.enabled);
    setTelegramFieldValue('bot_token', telegram.bot_token);
    setTelegramFieldValue('chat_id', telegram.chat_id);
    setTelegramCheckboxValue('notify_on_up', telegram.notify_on_up);
    setTelegramCheckboxValue('notify_on_down', telegram.notify_on_down);
    setTelegramCheckboxValue('notify_on_latency', telegram.notify_on_latency);
    setTelegramCheckboxValue('notify_on_intruder', telegram.notify_on_intruder);
    setTelegramFieldValue('latency_threshold', telegram.latency_threshold);
    setTelegramFieldValue('message_template', telegram.message_template);
}

function setTelegramOptionsExpanded(expanded) {
    const wrapper = document.getElementById('telegramOptionsWrapper');
    if (!wrapper) return;

    if (expanded) {
        wrapper.classList.remove('max-h-0', 'opacity-0', 'pointer-events-none');
        wrapper.classList.add('max-h-[1200px]', 'opacity-100');
    } else {
        wrapper.classList.remove('max-h-[1200px]', 'opacity-100');
        wrapper.classList.add('max-h-0', 'opacity-0', 'pointer-events-none');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    applyTelegramConfigToForm(window.telegramConfig);

    const enabledField = document.getElementById('enabled');
    if (enabledField) {
        setTelegramOptionsExpanded(enabledField.checked);
        enabledField.addEventListener('change', function () {
            setTelegramOptionsExpanded(enabledField.checked);
        });
    }
});

function testTelegramConnection() {
    const form = document.getElementById('telegramConfigForm');
    const formData = new FormData(form);
    const url = new URL(window.location.href);
    url.searchParams.set('action', 'test_telegram');

    const testButton = document.activeElement && document.activeElement.tagName === 'BUTTON'
        ? document.activeElement
        : null;
    const originalHtml = testButton?.innerHTML;
    if (testButton) {
        testButton.disabled = true;
        testButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Probando';
    }

    fetch(url.toString(), {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            modalFunctions.showAlert(data.message, data.success ? 'success' : 'error');
        })
        .catch(error => {
            console.error('Error:', error);
            modalFunctions.showAlert('Error al probar la conexión con Telegram.', 'error');
        })
        .finally(() => {
            if (testButton) {
                testButton.disabled = false;
                testButton.innerHTML = originalHtml;
            }
        });
}

window.testTelegramConnection = testTelegramConnection;
