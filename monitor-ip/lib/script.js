/**
 * IP Monitor Dashboard - Main JavaScript
 */

// Modal management functions
const modalFunctions = {
    // Show modal functions
    showModal: function (modalId) {
        document.getElementById(modalId).style.display = 'flex';
        document.body.style.overflow = 'hidden'; // Prevent scrolling when modal is open
    },

    // Hide modal functions
    hideModal: function (modalId) {
        document.getElementById(modalId).style.display = 'none';
        document.body.style.overflow = 'auto'; // Re-enable scrolling
    },

    // Custom alert and confirm functions
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

    // Specific modal functions
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

// Countdown and page refresh functions
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

function reloadPage() {
    window.location.href = window.location.pathname;
}

function stopPing() {
    pingStopped = true;
    document.getElementById('nextPingBlock').style.display = 'none';
    document.getElementById('stopPingBtn').style.display = 'none';
    document.getElementById('resumePingBtn').style.display = '';
    document.getElementById('stoppedMsg').style.display = '';
}

function startPing() {
    pingStopped = false;
    countdown = pingInterval;
    document.getElementById('nextPingBlock').style.display = '';
    document.getElementById('stopPingBtn').style.display = '';
    document.getElementById('resumePingBtn').style.display = 'none';
    document.getElementById('stoppedMsg').style.display = 'none';
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
        modalFunctions.showAlert("Por favor, ingresa un número válido mayor a 0.", 'error');
    }
}

// Toggle new service creation form
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

// Update color preview for inline service creation
function updateInlineColorPreview() {
    const colorInput = document.getElementById('new_service_color_inline');
    const preview = document.getElementById('color_preview_inline');
    preview.style.backgroundColor = colorInput.value;
}

// Initialize the application
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

// Validate Add IP Form
function validateAddIpForm() {
    const ipInput = document.getElementById('new_ip');
    const serviceSelect = document.getElementById('new_service');
    const serviceNameInput = document.getElementById('new_service_name_inline');

    // Validate IP address
    const ipPattern = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
    if (!ipPattern.test(ipInput.value.trim())) {
        modalFunctions.showAlert('Por favor, ingresa una dirección IP válida (ej: 192.168.1.1)', 'error');
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

// Auto-hide notifications after 5 seconds
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
