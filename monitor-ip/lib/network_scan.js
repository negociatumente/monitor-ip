
// Network Scan Functions
function showScanNetworkModal() {
    modalFunctions.showModal('scanNetworkModal');
}

function hideScanNetworkModal() {
    modalFunctions.hideModal('scanNetworkModal');
    document.getElementById('scanResults').style.display = 'none';
    document.getElementById('scanStatus').innerHTML = `
        <div class="bg-blue-50 dark:bg-blue-900/30 p-4 rounded-lg border border-blue-200 dark:border-blue-700">
            <p class="text-sm text-blue-800 dark:text-blue-200">
                <i class="fas fa-info-circle mr-2"></i>
                Click "Start Scan" to discover devices on your local network. This may take a few seconds.
            </p>
        </div>
    `;
    document.getElementById('saveDevicesBtn').style.display = 'none';
    document.getElementById('startScanBtn').style.display = 'inline-block';
}

async function startNetworkScan() {
    const startBtn = document.getElementById('startScanBtn');
    const statusDiv = document.getElementById('scanStatus');

    // Show loading state
    startBtn.disabled = true;
    startBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Scanning...';

    statusDiv.innerHTML = `
        <div class="bg-yellow-50 dark:bg-yellow-900/30 p-4 rounded-lg border border-yellow-200 dark:border-yellow-700">
            <p class="text-sm text-yellow-800 dark:text-yellow-200">
                <i class="fas fa-spinner fa-spin mr-2"></i>
                Scanning network... This may take up to 30 seconds.
            </p>
        </div>
    `;

    try {
        const response = await fetch('?action=scan_network', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'scan_network=1'
        });

        const data = await response.json();

        if (data.success) {
            displayScanResults(data.devices);
            statusDiv.innerHTML = `
                <div class="bg-green-50 dark:bg-green-900/30 p-4 rounded-lg border border-green-200 dark:border-green-700">
                    <p class="text-sm text-green-800 dark:text-green-200">
                        <i class="fas fa-check-circle mr-2"></i>
                        Scan complete! Found ${data.devices.length} device(s).
                    </p>
                </div>
            `;
        } else {
            statusDiv.innerHTML = `
                <div class="bg-red-50 dark:bg-red-900/30 p-4 rounded-lg border border-red-200 dark:border-red-700">
                    <p class="text-sm text-red-800 dark:text-red-200">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        ${data.message || 'Scan failed. Please try again.'}
                    </p>
                </div>
            `;
        }
    } catch (error) {
        statusDiv.innerHTML = `
            <div class="bg-red-50 dark:bg-red-900/30 p-4 rounded-lg border border-red-200 dark:border-red-700">
                <p class="text-sm text-red-800 dark:text-red-200">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    Error: ${error.message}
                </p>
            </div>
        `;
    } finally {
        startBtn.disabled = false;
        startBtn.innerHTML = '<i class="fas fa-radar mr-2"></i> Scan Again';
    }
}

function displayScanResults(devices) {
    const resultsDiv = document.getElementById('scanResults');
    const tableDiv = document.getElementById('devicesTable');

    if (devices.length === 0) {
        tableDiv.innerHTML = `
            <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                <i class="fas fa-inbox text-4xl mb-3 opacity-30"></i>
                <p>No devices found on the network.</p>
            </div>
        `;
        resultsDiv.style.display = 'block';
        return;
    }

    let html = `
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-gray-50 dark:bg-gray-700/50 text-gray-600 dark:text-gray-400 text-xs uppercase tracking-wider">
                    <th class="p-3 border-b border-gray-200 dark:border-gray-700 w-10">
                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" class="rounded">
                    </th>
                    <th class="p-3 border-b border-gray-200 dark:border-gray-700">IP Address</th>
                    <th class="p-3 border-b border-gray-200 dark:border-gray-700">Name / Hostname</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
    `;

    devices.forEach((device, index) => {
        const isSelf = device.mac === 'SELF' || device.mac === 'SELF/GATEWAY';
        const isGateway = device.hostname === 'Gateway' || device.mac === 'GATEWAY' || device.mac === 'SELF/GATEWAY';

        let rowClass = 'hover:bg-gray-50 dark:hover:bg-gray-700/30';
        if (isSelf) rowClass = 'bg-blue-50 dark:bg-blue-900/20';
        else if (isGateway) rowClass = 'bg-purple-50 dark:bg-purple-900/20';

        html += `
            <tr class="${rowClass} transition-colors">
                <td class="p-3">
                    <input type="checkbox" class="device-checkbox rounded" value="${device.ip}" data-index="${index}" ${isSelf || isGateway ? 'checked' : ''}>
                </td>
                <td class="p-3 font-mono text-sm">
                    ${device.ip}
                    ${isSelf ? '<span class="ml-2 text-xs bg-blue-100 text-blue-800 px-1.5 py-0.5 rounded">YOU</span>' : ''}
                    ${isGateway && !isSelf ? '<span class="ml-2 text-xs bg-purple-100 text-purple-800 px-1.5 py-0.5 rounded">GATEWAY</span>' : ''}
                    ${isGateway && isSelf ? '<span class="ml-2 text-xs bg-purple-100 text-purple-800 px-1.5 py-0.5 rounded">GW</span>' : ''}
                </td>
                <td class="p-3">
                    <input type="text" 
                        class="device-name-input w-full bg-transparent border-b border-gray-300 dark:border-gray-600 focus:border-blue-500 focus:outline-none text-sm py-1" 
                        value="${device.hostname}" 
                        placeholder="Enter name"
                        data-ip="${device.ip}">
                </td>
            </tr>
        `;
    });

    html += `
            </tbody>
        </table>
    `;

    tableDiv.innerHTML = html;
    resultsDiv.style.display = 'block';
    document.getElementById('saveDevicesBtn').style.display = 'inline-block';

    // Store devices data globally
    window.discoveredDevices = devices;
}

function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.device-checkbox');
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
}

async function saveDiscoveredDevices() {
    // Get all checkboxes and filter by checked property to ensure we capture programmatically checked ones
    const allCheckboxes = document.querySelectorAll('.device-checkbox');
    const checkboxes = Array.from(allCheckboxes).filter(cb => cb.checked);

    console.log(`Saving ${checkboxes.length} devices out of ${allCheckboxes.length} total`);

    const selectedDevices = checkboxes.map(cb => {
        const ip = cb.value;
        // Escape the IP for the selector to handle special characters if any (though IPs are usually safe)
        const nameInput = document.querySelector(`.device-name-input[data-ip="${ip}"]`);
        const name = nameInput ? nameInput.value.trim() : '';

        // Find original device data
        const originalDevice = window.discoveredDevices.find(d => d.ip === ip);

        return {
            ip: ip,
            name: name || (originalDevice ? originalDevice.hostname : 'Unknown Device'),
            mac: originalDevice ? originalDevice.mac : ''
        };
    });

    if (selectedDevices.length === 0) {
        alert('Please select at least one device to save.');
        return;
    }

    const saveBtn = document.getElementById('saveDevicesBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Saving...';

    try {
        const response = await fetch('?action=save_scanned_devices', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                devices: selectedDevices
            })
        });

        const data = await response.json();

        if (data.success) {
            hideScanNetworkModal();
            window.location.reload();
        } else {
            alert('Error saving devices: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        alert('Error: ' + error.message);
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fas fa-save mr-2"></i> Save Selected Devices';
    }
}

// Expose functions
window.showScanNetworkModal = showScanNetworkModal;
window.hideScanNetworkModal = hideScanNetworkModal;
window.startNetworkScan = startNetworkScan;
window.toggleSelectAll = toggleSelectAll;
window.saveDiscoveredDevices = saveDiscoveredDevices;

// Network Type Switching
function switchNetworkType(type) {
    // Update tabs
    const externalTab = document.getElementById('externalTab');
    const localTab = document.getElementById('localTab');

    if (type === 'external') {
        externalTab.classList.add('active');
        localTab.classList.remove('active');
        // Redirect to main config
        window.location.href = '?network=external';
    } else {
        localTab.classList.add('active');
        externalTab.classList.remove('active');
        // Redirect to local config
        window.location.href = '?network=local';
    }
}

window.switchNetworkType = switchNetworkType;

// Set active tab on load
document.addEventListener('DOMContentLoaded', function () {
    const urlParams = new URLSearchParams(window.location.search);
    const networkType = urlParams.get('network') || 'external';

    const externalTab = document.getElementById('externalTab');
    const localTab = document.getElementById('localTab');

    if (networkType === 'local') {
        localTab?.classList.add('active');
        externalTab?.classList.remove('active');
    } else {
        externalTab?.classList.add('active');
        localTab?.classList.remove('active');
    }
});

// Speed Test Functions
function showSpeedTestModal() {
    modalFunctions.showModal('speedTestModal');
    fetchSpeedTestHistory();
}

async function fetchSpeedTestHistory() {
    try {
        const response = await fetch('?action=speed_test_history');
        const history = await response.json();
        const body = document.getElementById('speedTestHistoryBody');

        if (!history || history.length === 0) {
            body.innerHTML = '<tr><td colspan="4" class="py-4 text-center text-gray-500 opacity-50 italic">No history available</td></tr>';
            return;
        }

        body.innerHTML = history.map(row => `
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                <td class="py-2 text-gray-600 dark:text-gray-400 font-mono text-xs">${row.timestamp}</td>
                <td class="py-2 font-medium text-purple-600 dark:text-purple-400">${row.latency}<span class="text-[10px] ml-0.5 opacity-70">ms</span></td>
                <td class="py-2 font-medium text-green-600 dark:text-green-400">${row.download}<span class="text-[10px] ml-0.5 opacity-70">Mb</span></td>
                <td class="py-2 font-medium text-blue-600 dark:text-blue-400">${row.upload}<span class="text-[10px] ml-0.5 opacity-70">Mb</span></td>
            </tr>
        `).join('');
    } catch (error) {
        console.error('Failed to fetch speedtest history:', error);
    }
}


function hideSpeedTestModal() {
    modalFunctions.hideModal('speedTestModal');
    document.getElementById('speedTestResults').style.display = 'none';
    document.getElementById('speedTestProgress').style.display = 'none';
    document.getElementById('speedTestStatus').innerHTML = `
        <div class="bg-blue-50 dark:bg-blue-900/30 p-4 rounded-lg border border-blue-200 dark:border-blue-700">
            <p class="text-sm text-blue-800 dark:text-blue-200">
                <i class="fas fa-info-circle mr-2"></i>
                Click "Start Test" to measure your network speed. This will test download and upload speeds.
            </p>
        </div>
    `;
    document.getElementById('downloadSpeed').textContent = '--';
    document.getElementById('uploadSpeed').textContent = '--';
    document.getElementById('pingLatency').textContent = '--';
    document.getElementById('startSpeedTestBtn').style.display = 'inline-block';
}

async function startSpeedTest() {
    const startBtn = document.getElementById('startSpeedTestBtn');
    const statusDiv = document.getElementById('speedTestStatus');
    const resultsDiv = document.getElementById('speedTestResults');
    const progressDiv = document.getElementById('speedTestProgress');
    const progressBar = document.getElementById('speedTestProgressBar');
    const progressText = document.getElementById('speedTestProgressText');

    // Show loading state
    startBtn.disabled = true;
    startBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Testing...';

    statusDiv.innerHTML = `
        <div class="bg-yellow-50 dark:bg-yellow-900/30 p-4 rounded-lg border border-yellow-200 dark:border-yellow-700">
            <p class="text-sm text-yellow-800 dark:text-yellow-200">
                <i class="fas fa-spinner fa-spin mr-2"></i>
                Running speed test... Please wait.
            </p>
        </div>
    `;

    resultsDiv.style.display = 'block';
    progressDiv.style.display = 'block';

    resultsDiv.style.display = 'block';
    progressDiv.style.display = 'block';

    try {
        progressBar.style.width = '10%';
        progressText.textContent = 'Running complete speed test...';

        const response = await fetch('?action=speed_test_full', { method: 'POST' });
        const data = await response.json();

        if (data.success) {
            document.getElementById('pingLatency').textContent = data.latency;
            document.getElementById('downloadSpeed').textContent = data.download;
            document.getElementById('uploadSpeed').textContent = data.upload;

            progressBar.style.width = '100%';
            progressText.textContent = 'Test complete!';

            // Refresh history
            fetchSpeedTestHistory();
        } else {
            throw new Error(data.message || 'Speed test failed');
        }

        setTimeout(() => {
            progressDiv.style.display = 'none';
        }, 2000);

        statusDiv.innerHTML = `
            <div class="bg-green-50 dark:bg-green-900/30 p-4 rounded-lg border border-green-200 dark:border-green-700">
                <p class="text-sm text-green-800 dark:text-green-200">
                    <i class="fas fa-check-circle mr-2"></i>
                    Speed test completed successfully!
                </p>
            </div>
        `;

    } catch (error) {
        statusDiv.innerHTML = `
            <div class="bg-red-50 dark:bg-red-900/30 p-4 rounded-lg border border-red-200 dark:border-red-700">
                <p class="text-sm text-red-800 dark:text-red-200 mb-2">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong>Error:</strong> ${error.message}
                </p>
                <div class="mt-3 p-3 bg-gray-50 dark:bg-gray-800 rounded text-xs">
                    <p class="font-semibold text-gray-700 dark:text-gray-300 mb-2">Installation Instructions:</p>
                    <p class="text-gray-600 dark:text-gray-400 mb-1">
                        <strong>Option 1 (Python):</strong> <code class="bg-gray-200 dark:bg-gray-700 px-1 rounded">pip install speedtest-cli</code>
                    </p>
                    <p class="text-gray-600 dark:text-gray-400">
                        <strong>Option 2 (Ookla):</strong> Download from <a href="https://www.speedtest.net/apps/cli" target="_blank" class="text-blue-500 hover:underline">speedtest.net/apps/cli</a>
                    </p>
                </div>
            </div>
        `;
        progressDiv.style.display = 'none';
    } finally {
        startBtn.disabled = false;
        startBtn.innerHTML = '<i class="fas fa-redo mr-2"></i> Test Again';
    }
}

window.showSpeedTestModal = showSpeedTestModal;
window.hideSpeedTestModal = hideSpeedTestModal;
window.startSpeedTest = startSpeedTest;
