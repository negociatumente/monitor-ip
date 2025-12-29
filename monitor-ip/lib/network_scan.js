
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
            // Filter out already registered devices
            const registeredIps = Object.keys(window.ipDetails || {});
            const newDevices = data.devices.filter(d => !registeredIps.includes(d.ip));
            displayScanResults(newDevices);
            statusDiv.innerHTML = `
                <div class="bg-green-50 dark:bg-green-900/30 p-4 rounded-lg border border-green-200 dark:border-green-700">
                    <p class="text-sm text-green-800 dark:text-green-200">
                        <i class="fas fa-check-circle mr-2"></i>
                        Scan complete! Found ${newDevices.length} device(s).
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

function displayScanResults(newDevices) {
    const resultsDiv = document.getElementById('scanResults');
    const tableDiv = document.getElementById('devicesTable');

    if (newDevices.length === 0) {
        tableDiv.innerHTML = `
            <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                <i class="fas fa-check-double text-4xl mb-3 text-green-500 opacity-50"></i>
                <p class="font-bold">No new devices found.</p>
                <p class="text-sm">All discovered devices are already being monitored.</p>
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
                    <th class="p-3 border-b border-gray-200 dark:border-gray-700">Name</th>
                    <th class="p-3 border-b border-gray-200 dark:border-gray-700">Is Repeater/Mesh</th>
                    <th class="p-3 border-b border-gray-200 dark:border-gray-700">Network Connection</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
    `;

    // Find all existing repeaters in window.ipDetails
    const existingRepeaters = [];
    Object.entries(window.ipDetails || {}).forEach(([rip, rdata]) => {
        const sLower = (rdata.service || "").toLowerCase();
        if (!rip.endsWith('.1') && (sLower.includes('repeater') || sLower.includes('repetidor') || (sLower.includes('ap') && !sLower.includes('apple')))) {
            existingRepeaters.push({ ip: rip, service: rdata.service });
        }
    });

    newDevices.forEach((device, index) => {
        const isSelf = device.mac === 'SELF' || device.mac === 'SELF/GATEWAY';
        const isGateway = device.hostname === 'Gateway' || device.mac === 'GATEWAY' || device.mac === 'SELF/GATEWAY';

        let rowClass = 'hover:bg-gray-50 dark:hover:bg-gray-700/30';
        if (isSelf) rowClass = 'bg-blue-50 dark:bg-blue-900/20';
        else if (isGateway) rowClass = 'bg-purple-50 dark:bg-purple-900/20';

        // Prepare connection options
        let optionsHtml = `
            <option value="Ethernet">Ethernet</option>
            <option value="WiFi-2.4GHz">WiFi-2.4GHz</option>
            <option value="WiFi-5GHz">WiFi-5GHz</option>
            <option value="WiFi-6GHz">WiFi-6GHz</option>
        `;
        existingRepeaters.forEach(r => {
            const suffix = r.ip.split('.').pop();
            optionsHtml += `<option value="Repeater (.${suffix})">${r.service} (.${suffix})</option>`;
        });

        html += `
            <tr class="${rowClass} transition-colors">
                <td class="p-3">
                    <input type="checkbox" class="device-checkbox rounded" value="${device.ip}" data-index="${index}" ${isSelf || isGateway ? 'checked' : ''}>
                </td>
                <td class="p-3 font-mono text-sm">
                    ${device.ip}
                    ${isSelf ? '<span class="ml-2 text-xs bg-blue-100 text-blue-800 px-1.5 py-0.5 rounded">YOU</span>' : ''}
                    ${isGateway && !isSelf ? '<span class="ml-2 text-xs bg-purple-100 text-purple-800 px-1.5 py-0.5 rounded">GATEWAY</span>' : ''}
                </td>
                <td class="p-3">
                    <input type="text" 
                        id="scan-name-${index}"
                        class="device-name-input w-full bg-transparent border-b border-gray-300 dark:border-gray-600 focus:border-blue-500 focus:outline-none text-sm py-1" 
                        value="${device.hostname}" 
                        placeholder="Enter name"
                        data-ip="${device.ip}">
                </td>
                <td class="p-3 text-center">
                    <input type="checkbox" 
                        class="scan-repeater-check w-4 h-4" 
                        onchange="handleScanRepeaterCheck(${index})"
                        data-ip="${device.ip}">
                </td>
                <td class="p-3">
                    <select id="scan-net-${index}" class="device-network-input w-full bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded text-xs p-1 focus:ring-blue-500 focus:border-blue-500 dark:text-gray-300" data-ip="${device.ip}">
                        ${optionsHtml}
                    </select>
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
    window.discoveredDevices = newDevices;
}

function handleScanRepeaterCheck(index) {
    const check = document.querySelector(`.scan-repeater-check[onchange="handleScanRepeaterCheck(${index})"]`);
    const nameInput = document.getElementById(`scan-name-${index}`);

    if (check && nameInput) {
        if (check.checked) {
            nameInput.value = 'Repeater/Mesh';
            nameInput.readOnly = true;
            nameInput.classList.add('bg-gray-100', 'dark:bg-gray-800', 'opacity-60', 'cursor-not-allowed');
        } else {
            nameInput.readOnly = false;
            nameInput.classList.remove('bg-gray-100', 'dark:bg-gray-800', 'opacity-60', 'cursor-not-allowed');
        }
    }

    // Update all selects in the table to include/remove this new repeater
    updateAllScanNetworkSelects();
}

/**
 * Updates all Network Connection selects in the scan results table
 * to include both existing repeaters and newly checked ones.
 */
function updateAllScanNetworkSelects() {
    const selects = document.querySelectorAll('.device-network-input[id^="scan-net-"]');
    const repeaterChecks = document.querySelectorAll('.scan-repeater-check');

    // 1. Find all active repeaters (registered + newly checked in scan)
    const activeRepeaters = [];

    // Registered from window.ipDetails
    Object.entries(window.ipDetails || {}).forEach(([rip, rdata]) => {
        const sLower = (rdata.service || "").toLowerCase();
        if (!rip.endsWith('.1') && (sLower.includes('repeater') || sLower.includes('repetidor') || (sLower.includes('ap') && !sLower.includes('apple')))) {
            activeRepeaters.push({ ip: rip, service: rdata.service });
        }
    });

    // Newly checked in scan
    repeaterChecks.forEach(check => {
        if (check.checked) {
            const ip = check.getAttribute('data-ip');
            // Avoid duplicates if already registered
            if (!activeRepeaters.some(r => r.ip === ip)) {
                activeRepeaters.push({ ip: ip, service: 'Repeater/Mesh' });
            }
        }
    });

    // 2. Refresh each select
    selects.forEach(select => {
        const currentVal = select.value;
        const currentIp = select.getAttribute('data-ip');

        let optionsHtml = `
            <option value="Ethernet">Ethernet</option>
            <option value="WiFi-2.4GHz">WiFi-2.4GHz</option>
            <option value="WiFi-5GHz">WiFi-5GHz</option>
            <option value="WiFi-6GHz">WiFi-6GHz</option>
        `;

        activeRepeaters.forEach(r => {
            if (r.ip !== currentIp) { // Don't allow connecting to self
                const suffix = r.ip.split('.').pop();
                optionsHtml += `<option value="Repeater (.${suffix})">${r.service} (.${suffix})</option>`;
            }
        });

        select.innerHTML = optionsHtml;

        // Restore value if it still exists in new options
        const exists = Array.from(select.options).some(opt => opt.value === currentVal);
        if (exists) select.value = currentVal;
    });
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
        const networkInput = document.querySelector(`.device-network-input[data-ip="${ip}"]`);
        const network = networkInput ? networkInput.value : '';

        // Find original device data
        const originalDevice = window.discoveredDevices.find(d => d.ip === ip);

        return {
            ip: ip,
            name: name || (originalDevice ? originalDevice.hostname : 'Unknown Device'),
            mac: originalDevice ? originalDevice.mac : '',
            network: network
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

// Diagnostics Functions
let currentDiagIp = '';

function showDiagnosticsModal(ip) {
    currentDiagIp = ip;
    document.getElementById('diag_ip_title').textContent = ip;
    document.getElementById('diagnosticsModal').classList.remove('hidden');

    // Reset tabs and panels
    switchDiagTab('general');

    // Initial data fetch
    runGeoIP(ip);
}

function closeDiagnosticsModal() {
    document.getElementById('diagnosticsModal').classList.add('hidden');
    currentDiagIp = '';
}

function switchDiagTab(tabId) {
    // Update tabs UI
    document.querySelectorAll('.diag-tab').forEach(tab => {
        tab.classList.remove('bg-purple-100', 'text-purple-700', 'dark:bg-purple-900/40', 'dark:text-purple-300', 'active-diag-tab');
        tab.classList.add('text-gray-600', 'dark:text-gray-400');
    });

    const activeTab = document.getElementById('tab-diag-' + tabId);
    activeTab.classList.add('active-diag-tab', 'bg-purple-100', 'text-purple-700', 'dark:bg-purple-900/40', 'dark:text-purple-300');
    activeTab.classList.remove('text-gray-600', 'dark:text-gray-400');

    // Update panels
    document.querySelectorAll('.diag-panel').forEach(panel => panel.classList.add('hidden'));
    document.getElementById('diag-content-' + tabId).classList.remove('hidden');

    // Trigger specific actions if needed
    if (tabId === 'traceroute' && document.getElementById('traceroute_visual').innerHTML.includes('-- Traceroute ready --')) {
        runTraceroute(currentDiagIp);
    }

    if (tabId === 'aireport') {
        generateAIReport();
    }
}

async function runGeoIP(ip) {
    const container = document.getElementById('geoip_container');
    container.innerHTML = `<div class="col-span-2 py-10 flex flex-col items-center"><i class="fas fa-spinner fa-spin text-2xl mb-4"></i>Analysing location...</div>`;

    try {
        const formData = new FormData();
        formData.append('ip', ip);
        formData.append('type', 'geoip');

        const response = await fetch('?action=diagnose', { method: 'POST', body: formData });
        const data = await response.json();

        if (data.success && data.result.status === 'success') {
            const r = data.result;
            container.innerHTML = `
                <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-xl border border-gray-100 dark:border-gray-700">
                    <p class="text-[10px] uppercase font-bold text-gray-400 mb-1">Status</p>
                    <p class="text-sm font-semibold flex items-center gap-2"><i class="fas fa-check-circle text-green-500"></i> Localized</p>
                </div>
                <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-xl border border-gray-100 dark:border-gray-700">
                    <p class="text-[10px] uppercase font-bold text-gray-400 mb-1">Country</p>
                    <p class="text-sm font-semibold flex items-center gap-2">
                        <img src="https://flagcdn.com/24x18/${r.countryCode.toLowerCase()}.png" class="rounded-sm"> ${r.country} (${r.countryCode})
                    </p>
                </div>
                <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-xl border border-gray-100 dark:border-gray-700">
                    <p class="text-[10px] uppercase font-bold text-gray-400 mb-1">City / Region</p>
                    <p class="text-sm font-semibold">${r.city}, ${r.regionName}</p>
                </div>
                <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-xl border border-gray-100 dark:border-gray-700">
                    <p class="text-[10px] uppercase font-bold text-gray-400 mb-1">ISP / Org</p>
                    <p class="text-sm font-semibold truncate" title="${r.isp}">${r.isp}</p>
                </div>
                <div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-xl border border-gray-100 dark:border-gray-700 col-span-2">
                    <p class="text-[10px] uppercase font-bold text-gray-400 mb-1">ASN</p>
                    <p class="text-sm font-semibold">${r.as}</p>
                </div>
            `;
        } else {
            const isLocal = data.result?.message?.includes('Local') || ip === '127.0.0.1' || ip.startsWith('192.168.') || ip.startsWith('10.') || ip.startsWith('172.');

            if (isLocal) {
                container.innerHTML = `
                    <div class="col-span-2 bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800/50 rounded-2xl p-8 flex flex-col items-center text-center">
                        <div class="w-16 h-16 bg-blue-100 dark:bg-blue-800 rounded-full flex items-center justify-center text-blue-600 dark:text-blue-400 mb-4 shadow-inner">
                            <i class="fas fa-home text-2xl"></i>
                        </div>
                        <h4 class="text-xl font-bold text-blue-900 dark:text-blue-100 mb-2">Local Network IP</h4>
                        <p class="text-sm text-blue-700/70 dark:text-blue-300/60 max-w-md">
                            This address belongs to a private network range. Geolocation is not available for internal assets.
                        </p>
                        <div class="mt-6 flex gap-4">
                            <span class="px-3 py-1 bg-white dark:bg-gray-800 rounded-full text-[10px] font-bold text-blue-500 border border-blue-100 dark:border-blue-800 shadow-sm uppercase tracking-wider">Private Range</span>
                            <span class="px-3 py-1 bg-white dark:bg-gray-800 rounded-full text-[10px] font-bold text-blue-500 border border-blue-100 dark:border-blue-800 shadow-sm uppercase tracking-wider">Intranet</span>
                        </div>
                    </div>
                `;
            } else {
                container.innerHTML = `<div class="col-span-2 py-10 text-center text-gray-400 italic">No geolocation data available for this host.</div>`;
            }
        }
    } catch (e) {
        container.innerHTML = `<div class="col-span-2 py-10 text-red-500">Error fetching GeoIP data</div>`;
    }
}

function setTracerouteView(mode) {
    const visual = document.getElementById('traceroute_visual');
    const raw = document.getElementById('traceroute_raw');
    const btnVisual = document.getElementById('btn-trace-visual');
    const btnRaw = document.getElementById('btn-trace-raw');

    if (mode === 'visual') {
        visual.classList.remove('hidden');
        raw.classList.add('hidden');
        btnVisual.classList.add('bg-white', 'dark:bg-gray-700', 'shadow-sm', 'text-indigo-600', 'dark:text-indigo-400');
        btnVisual.classList.remove('text-gray-500');
        btnRaw.classList.remove('bg-white', 'dark:bg-gray-700', 'shadow-sm', 'text-indigo-600', 'dark:text-indigo-400');
        btnRaw.classList.add('text-gray-500');
    } else {
        visual.classList.add('hidden');
        raw.classList.remove('hidden');
        btnRaw.classList.add('bg-white', 'dark:bg-gray-700', 'shadow-sm', 'text-indigo-600', 'dark:text-indigo-400');
        btnRaw.classList.remove('text-gray-500');
        btnVisual.classList.remove('bg-white', 'dark:bg-gray-700', 'shadow-sm', 'text-indigo-600', 'dark:text-indigo-400');
        btnVisual.classList.add('text-gray-500');
    }
}

async function runTraceroute(ip) {
    const visual = document.getElementById('traceroute_visual');
    const raw = document.getElementById('traceroute_raw');

    visual.innerHTML = `
        <div class="flex flex-col items-center justify-center py-20 animate-pulse">
            <i class="fas fa-route text-3xl mb-4 text-indigo-400"></i>
            <p class="text-gray-500 font-medium">Trace-routing path to ${ip}...</p>
            <p class="text-[10px] text-gray-400 mt-2 uppercase tracking-widest">Searching via 15 maximum hops</p>
        </div>
    `;
    raw.textContent = "Scanning network path...";

    try {
        const formData = new FormData();
        formData.append('ip', ip);
        formData.append('type', 'traceroute');

        const response = await fetch('?action=diagnose', { method: 'POST', body: formData });
        const data = await response.json();

        if (data.success) {
            const rawOutput = data.result;
            raw.textContent = rawOutput;

            const lines = rawOutput.split('\n');
            let html = '<div class="space-y-4 relative">';

            // CGNAT Detection: Check for 100.64.0.0/10 range (RFC 6598)
            let cgnatDetected = false;
            const cgnatIPs = [];

            lines.forEach(line => {
                const ipMatch = line.match(/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/g);
                if (ipMatch) {
                    ipMatch.forEach(ip => {
                        const parts = ip.split('.').map(Number);
                        // Check if IP is in 100.64.0.0/10 range
                        if (parts[0] === 100 && parts[1] >= 64 && parts[1] <= 127) {
                            cgnatDetected = true;
                            if (!cgnatIPs.includes(ip)) cgnatIPs.push(ip);
                        }
                    });
                }
            });

            // Display CGNAT warning banner if detected
            if (cgnatDetected) {
                html += `
                    <div class="mb-6 p-4 bg-amber-50 dark:bg-amber-900/20 border-l-4 border-amber-500 rounded-lg">
                        <div class="flex items-start gap-3">
                            <div class="flex-shrink-0 w-8 h-8 bg-amber-500 rounded-full flex items-center justify-center">
                                <i class="fas fa-exclamation-triangle text-white text-sm"></i>
                            </div>
                            <div class="flex-1">
                                <h4 class="text-sm font-black text-amber-800 dark:text-amber-400 uppercase tracking-wider mb-1">
                                    <i class="fas fa-network-wired mr-2"></i>CGNAT Detected
                                </h4>
                                <p class="text-xs text-amber-700 dark:text-amber-300 mb-2">
                                    Your ISP is using Carrier-Grade NAT (CGNAT). Detected IP(s): <span class="font-mono font-bold">${cgnatIPs.join(', ')}</span>
                                </p>
                                <p class="text-[10px] text-amber-600 dark:text-amber-400">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    This may affect port forwarding, P2P connections, and some online services. Contact your ISP for a public IP if needed.
                                </p>
                            </div>
                        </div>
                    </div>
                `;
            }

            // Add a vertical line for the path
            html += '<div class="absolute left-[20px] top-6 bottom-6 w-0.5 bg-gray-200 dark:bg-gray-800 z-0"></div>';

            let hopsFound = 0;
            lines.forEach(line => {
                // Better regex for hops: starts with space(s) and then a number
                const match = line.trim().match(/^(\d+)\s+(.+)$/);
                if (match) {
                    hopsFound++;
                    const hopNumber = match[1];
                    const content = match[2];

                    // Detect timeout (* * *)
                    const isTimeout = content.includes('*') && !content.includes('.');

                    // Parse times (usually something like "5.232 ms" or "<1 ms")
                    const times = content.match(/(\d+\.?\d*\s*ms|<1\s*ms)/g) || [];

                    // Get IP/Host: strip out times and asterisks
                    let hostName = content
                        .replace(/(\d+\.?\d*\s*ms|<1\s*ms)/g, '')
                        .replace(/\*/g, '')
                        .trim();

                    // Format IP if found
                    const ipMatch = hostName.match(/\((\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\)/) || hostName.match(/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/);
                    const ipOnly = ipMatch ? ipMatch[1] : '';
                    if (ipMatch) hostName = hostName.replace(`(${ipOnly})`, '').trim();

                    // Check if this hop is a CGNAT IP
                    const isCGNAT = cgnatIPs.includes(ipOnly);

                    html += `
                        <div class="relative z-10 flex items-start gap-4 p-2 transition-all">
                            <div class="flex-shrink-0 w-10 h-10 rounded-full ${isTimeout ? 'bg-gray-100 dark:bg-gray-800' : (isCGNAT ? 'bg-amber-500' : 'bg-indigo-600')} border-4 border-white dark:border-gray-900 shadow-sm flex items-center justify-center text-xs font-bold ${isTimeout ? 'text-gray-400' : 'text-white'}">
                                ${hopNumber}
                            </div>
                            <div class="flex-1 min-w-0 bg-white dark:bg-gray-800/50 p-4 rounded-2xl border ${isCGNAT ? 'border-amber-300 dark:border-amber-700' : 'border-gray-100 dark:border-gray-700/50'} shadow-sm group hover:border-indigo-200 dark:hover:border-indigo-900 transition-colors">
                                <div class="flex flex-col md:flex-row md:items-center justify-between gap-2">
                                    <div class="flex-1 truncate">
                                        <h5 class="text-sm font-bold ${isTimeout ? 'text-gray-400' : 'text-gray-800 dark:text-gray-200'} truncate flex items-center gap-2">
                                            ${isTimeout ? '<i class="fas fa-exclamation-triangle mr-2"></i> Request Timed Out' : (hostName || ipOnly || 'Unknown Host')}
                                            ${isCGNAT ? '<span class="px-2 py-0.5 bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 text-[8px] font-black uppercase tracking-wider rounded-full">CGNAT</span>' : ''}
                                        </h5>
                                        ${ipOnly && hostName !== ipOnly ? `<p class="text-[10px] font-mono text-gray-500 mt-0.5">${ipOnly}</p>` : ''}
                                    </div>
                                    <div class="flex gap-1">
                                        ${times.length > 0 ? times.map(t => `<span class="px-2 py-0.5 bg-gray-50 dark:bg-gray-900 rounded-md text-[9px] font-mono font-bold text-gray-500 border border-gray-100 dark:border-gray-800">${t.replace(' ms', '').trim()}ms</span>`).join('') : (isTimeout ? '' : '<span class="px-2 py-0.5 bg-red-50 dark:bg-red-900/20 rounded-md text-[9px] font-mono font-bold text-red-500 border border-red-100 dark:border-red-900/30">DROP</span>')}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                }
            });

            if (hopsFound === 0) {
                // Fallback to raw if no hops were parsed
                html = `<div class="flex flex-col items-center justify-center py-20 text-gray-400">
                    <i class="fas fa-terminal text-3xl mb-4 opacity-20"></i>
                    <p class="text-sm">Structured view unavailable for this output format.</p>
                    <button onclick="setTracerouteView('raw')" class="mt-4 px-4 py-2 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 rounded-lg text-xs font-bold">Switch to Raw Output</button>
                </div>`;
            } else {
                html += '</div>';
            }

            visual.innerHTML = html;
        } else {
            visual.innerHTML = `<div class="p-8 text-red-500 flex items-center gap-3"><i class="fas fa-times-circle text-xl"></i> ${data.message}</div>`;
        }
    } catch (e) {
        visual.innerHTML = `<div class="p-8 text-red-500">Fatal error running diagnostic tool.</div>`;
    }
}


window.showDiagnosticsModal = showDiagnosticsModal;
window.closeDiagnosticsModal = closeDiagnosticsModal;
window.switchDiagTab = switchDiagTab;

async function showNetworkHealth() {
    const modal = document.getElementById('networkHealthModal');
    const content = document.getElementById('network_health_content');
    modal.classList.remove('hidden');

    content.innerHTML = `
        <div class="flex flex-col items-center justify-center py-20 animate-pulse">
            <i class="fas fa-circle-notch fa-spin text-4xl mb-4 text-indigo-500"></i>
            <p class="text-gray-500 font-bold uppercase tracking-widest text-xs">Evaluating network performance...</p>
        </div>
    `;

    try {
        const urlParams = new URLSearchParams(window.location.search);
        const network = urlParams.get('network') || 'external';


        const formData = new FormData();
        formData.append('type', 'network_health');

        const response = await fetch(`?action=diagnose&network=${network}`, { method: 'POST', body: formData });
        const data = await response.json();

        if (data.success) {
            window.lastNetworkHealthData = data.result;
            const h = data.result;
            let speedHtml = '';
            const theoreticalMb = h.theoretical_speed || 0;
            const theoreticalDisplay = theoreticalMb > 0 ? `${theoreticalMb} Mb` : 'Not Set';

            if (h.speed) {
                const perfRatio = h.speed.performance_ratio || 'N/A';

                speedHtml = `
                    <div class="grid grid-cols-3 gap-4 mb-3">
                        <div class="bg-white dark:bg-gray-800 p-4 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-sm relative overflow-hidden group">
                            <div class="absolute top-0 right-0 p-1 opacity-5 group-hover:opacity-10 transition-opacity">
                                <i class="fas fa-download text-4xl transform translate-x-4"></i>
                            </div>
                            <p class="text-[10px] font-bold text-gray-400 uppercase mb-1">Download</p>
                            <p class="text-xl font-black text-green-500">${h.speed.download}<span class="text-[10px] ml-1">Mb</span></p>
                        </div>
                        <div class="bg-white dark:bg-gray-800 p-4 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-sm relative overflow-hidden group">
                            <div class="absolute top-0 right-0 p-1 opacity-5 group-hover:opacity-10 transition-opacity">
                                <i class="fas fa-upload text-4xl transform translate-x-4"></i>
                            </div>
                            <p class="text-[10px] font-bold text-gray-400 uppercase mb-1">Upload</p>
                            <p class="text-xl font-black text-blue-500">${h.speed.upload}<span class="text-[10px] ml-1">Mb</span></p>
                        </div>
                        <div class="bg-white dark:bg-gray-800 p-4 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-sm relative overflow-hidden group">
                            <div class="absolute top-0 right-0 p-1 opacity-5 group-hover:opacity-10 transition-opacity">
                                <i class="fas fa-clock text-4xl transform translate-x-4"></i>
                            </div>
                            <p class="text-[10px] font-bold text-gray-400 uppercase mb-1">Latency</p>
                            <p class="text-xl font-black text-purple-500">${h.speed.latency}<span class="text-[10px] ml-1">ms</span></p>
                        </div>
                    </div>
                    <div class="bg-indigo-50 dark:bg-indigo-900/20 p-4 rounded-2xl border border-indigo-100 dark:border-indigo-800/50 mb-6 flex justify-between items-center group transition-all hover:border-indigo-300">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-indigo-500 text-white flex items-center justify-center text-lg shadow-lg shadow-indigo-500/20">
                                <i class="fas fa-bolt"></i>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold text-indigo-400 uppercase leading-tight text-left">Connection efficiency</p>
                                <p class="text-xs text-indigo-700 dark:text-indigo-300 font-medium tracking-tight text-left">Plan: ${theoreticalDisplay} | Actual: ${h.speed.download} Mb</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-2xl font-black text-indigo-600 dark:text-indigo-400">${perfRatio}</p>
                        </div>
                    </div>
                `;
            } else {
                speedHtml = `
                    <div class="bg-white dark:bg-gray-800 p-5 rounded-3xl border border-gray-100 dark:border-gray-700 shadow-sm mb-6">
                        <div class="flex items-center justify-between mb-4">
                             <div class="flex items-center gap-3">
                                <i class="fas fa-wifi text-indigo-400 text-xl"></i>
                                <span class="text-sm font-bold text-gray-700 dark:text-gray-200 uppercase tracking-tighter">Internet Plan</span>
                             </div>
                             <span class="px-3 py-1 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 rounded-full text-xs font-bold font-mono border border-indigo-100 dark:border-indigo-800">${theoreticalDisplay}</span>
                        </div>
                        <div class="bg-yellow-50 dark:bg-yellow-900/20 p-4 rounded-2xl border border-yellow-100 dark:border-yellow-900/30 flex items-center justify-between">
                            <div class="flex items-center gap-3 text-left">
                                <i class="fas fa-exclamation-circle text-yellow-500"></i>
                                <p class="text-xs text-yellow-700 dark:text-yellow-300 font-medium">No speed test data found for comparison.</p>
                            </div>
                            <button onclick="closeNetworkHealthModal(); showSpeedTestModal();" class="text-[10px] font-bold bg-white dark:bg-gray-800 px-4 py-2 rounded-xl shadow-sm border border-yellow-200 dark:border-yellow-700 text-yellow-600 transition-all hover:scale-105 active:scale-95">RUN TEST</button>
                        </div>
                    </div>
                `;
            }

            const headerMap = {
                'Excellent': { color: 'green', icon: 'fa-check-circle' },
                'Good': { color: 'blue', icon: 'fa-info-circle' },
                'Fair': { color: 'yellow', icon: 'fa-exclamation-triangle' },
                'Poor': { color: 'red', icon: 'fa-times-circle' }
            };
            const status = headerMap[h.summary] || { color: 'gray', icon: 'fa-question-circle' };
            const statusColorCode = status.color === 'green' ? '#10B981' : (status.color === 'blue' ? '#3B82F6' : (status.color === 'yellow' ? '#F59E0B' : '#EF4444'));

            content.innerHTML = `
                <div class="flex items-center justify-between mb-8 bg-white dark:bg-gray-800 p-6 rounded-3xl border-b-4 shadow-xl relative overflow-hidden" style="border-color: ${statusColorCode}">
                    <div class="absolute top-0 right-0 p-8 opacity-5">
                       <i class="fas ${status.icon} text-6xl"></i>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 text-left">System status</p>
                        <h4 class="text-4xl font-black text-gray-800 dark:text-white text-left">${h.summary}</h4>
                    </div>
                    <div class="text-right">
                         <p class="text-[10px] font-bold text-gray-400 uppercase mb-1">Health score</p>
                         <p class="text-4xl font-black" style="color: ${statusColorCode}">${h.health_score}<span class="text-sm ml-1 opacity-50">/100</span></p>
                    </div>
                </div>

                ${speedHtml}

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-white dark:bg-gray-800 p-5 rounded-3xl border border-gray-100 dark:border-gray-700 shadow-sm flex items-center justify-between group transition-all hover:border-indigo-200">
                        <div class="flex items-center gap-4 text-left">
                            <div class="w-12 h-12 rounded-2xl bg-indigo-50 dark:bg-indigo-900/30 flex items-center justify-center text-indigo-500 group-hover:scale-110 transition-transform">
                                <i class="fas fa-network-wired text-xl"></i>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold text-gray-400 uppercase leading-none mb-1">Gateway</p>
                                <p class="text-sm font-black text-gray-700 dark:text-gray-200">${h.gateway.ip}</p>
                                <p class="text-[10px] font-bold ${h.gateway.status === 'ONLINE' ? 'text-green-500' : 'text-red-500'} flex items-center gap-1 mt-0.5">
                                    <i class="fas fa-circle text-[6px]"></i> ${h.gateway.status}
                                </p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] font-bold text-gray-400 uppercase leading-none mb-1">Latency</p>
                            <p class="text-sm font-mono font-black text-indigo-500">${h.gateway.latency}</p>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 p-5 rounded-3xl border border-gray-100 dark:border-gray-700 shadow-sm flex items-center justify-between group transition-all hover:border-purple-200">
                        <div class="flex items-center gap-4 text-left">
                            <div class="w-12 h-12 rounded-2xl bg-purple-50 dark:bg-purple-900/30 flex items-center justify-center text-purple-500 group-hover:scale-110 transition-transform">
                                <i class="fas fa-desktop text-xl"></i>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold text-gray-400 uppercase leading-none mb-1">Active hosts</p>
                                <p class="text-sm font-black text-gray-700 dark:text-gray-200">${h.devices.up} <span class="text-gray-400 font-normal">of</span> ${h.devices.total}</p>
                                <p class="text-[10px] font-bold text-purple-400 mt-0.5">Local Area Network</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] font-bold text-gray-400 uppercase leading-none mb-1">Avg Latency</p>
                            <p class="text-sm font-mono font-black text-purple-500">${h.devices.avg_latency}</p>
                        </div>
                    </div>
                </div>
                
                <!-- Initiation Guide Promo -->
                <div class="mt-8 p-6 bg-gradient-to-r from-amber-50 to-orange-50 dark:from-amber-900/10 dark:to-orange-900/10 rounded-3xl border-2 border-dashed border-amber-200 dark:border-amber-800/50 relative group">
                    <div class="flex flex-col md:flex-row items-center gap-6">
                        <div class="w-16 h-16 bg-amber-500 text-white rounded-2xl flex items-center justify-center text-3xl shadow-lg shadow-amber-500/30 group-hover:rotate-12 transition-transform shrink-0">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <div class="flex-1 text-center md:text-left">
                            <h5 class="text-lg font-black text-amber-800 dark:text-amber-400 mb-1">¿No entiendes el monitor?</h5>
                            <p class="text-xs text-amber-700/80 dark:text-amber-500/70 font-medium leading-relaxed">
                                Aprende los conceptos básicos y cómo funcionan las redes con la guía <b>Aprende a Monitorizar Servicios en Internet</b>.
                            </p>
                        </div>
                        <a href="https://negociatumente.com/guia-redes" target="_blank" 
                           class="px-6 py-3 bg-amber-600 text-white font-bold rounded-xl hover:bg-amber-700 transition-all active:scale-95 shadow-lg shadow-amber-600/20 whitespace-nowrap flex items-center gap-2">
                            Obtener Guía <i class="fas fa-arrow-right text-[10px]"></i>
                        </a>
                    </div>
                </div>
            `;


        } else {
            content.innerHTML = `<div class="p-8 text-red-500 text-center font-bold">Failed to analyze network: ${data.message}</div>`;
        }
    } catch (e) {
        content.innerHTML = `<div class="p-8 text-red-500 text-center font-bold">Analysis Error: ${e.message}</div>`;
    }
}

function closeNetworkHealthModal() {
    document.getElementById('networkHealthModal').classList.add('hidden');
}

window.showNetworkHealth = showNetworkHealth;
window.closeNetworkHealthModal = closeNetworkHealthModal;

function showTopologyMapModal() {
    const modal = document.getElementById('topologyModal');
    modal.classList.remove('hidden');
    renderTopologyMap();
}

function closeTopologyModal() {
    document.getElementById('topologyModal').classList.add('hidden');
}

window.showTopologyMapModal = showTopologyMapModal;
window.closeTopologyModal = closeTopologyModal;

/**
 * Generates a comprehensive text report for AI analysis
 */
function generateAIReport() {
    const content = document.getElementById('aireport_content');
    const ip = currentDiagIp;
    const ipData = window.ipDetails[ip];
    const timestamp = new Date().toLocaleString();

    let report = `NETWORK DIAGNOSTIC REPORT - ${timestamp}\n`;
    report += `==========================================\n\n`;

    // 1. Target host info
    report += `TARGET HOST ANALYSIS:\n`;
    report += `---------------------\n`;
    report += `IP/Domain: ${ip}\n`;
    if (ipData) {
        report += `Service: ${ipData.service}\n`;
        report += `Monitoring Method: ${ipData.method || 'ICMP'}\n`;
        if (ipData.network_type) {
            report += `Connection Type: ${ipData.network_type}\n`;
        }
        report += `Current Status: ${ipData.status}\n`;
        report += `Overall Uptime: ${Math.round(ipData.percentage)}%\n`;
        report += `Average Latency: ${typeof ipData.average_response_time === 'number' ? ipData.average_response_time.toFixed(2) : ipData.average_response_time} ms\n`;

        report += `\nRecent Ping History (Last 10):\n`;
        const history = ipData.ping_results.slice(0, 10);
        history.forEach((p, i) => {
            report += `  [${i + 1}] ${p.timestamp} | Status: ${p.status} | Time: ${p.response_time}\n`;
        });
    } else {
        report += `No detailed data available for this specific host.\n`;
    }

    // 2. Traceroute info (if available)
    const tracerouteRaw = document.getElementById('traceroute_raw').textContent;
    if (tracerouteRaw && !tracerouteRaw.includes('-- Raw output --') && !tracerouteRaw.includes('Scanning')) {
        report += `\nTRACEROUTE DATA:\n`;
        report += `----------------\n`;
        report += tracerouteRaw + `\n`;
    }

    // 3. Overall Network Context
    report += `\nOVERALL NETWORK CONTEXT:\n`;
    report += `------------------------\n`;
    report += `Total Hosts Monitored: ${Object.keys(window.ipDetails).length}\n`;

    const hosts = Object.entries(window.ipDetails);
    const upCount = hosts.filter(([_, data]) => data.status === 'UP').length;
    const downCount = hosts.length - upCount;

    report += `Live Hosts: ${upCount}\n`;
    report += `Down Hosts: ${downCount}\n\n`;

    report += `Monitored Hosts Summary:\n`;
    hosts.forEach(([hostIp, data]) => {
        report += `- ${hostIp}: ${data.status} (${data.average_response_time} ms)\n`;
    });

    report += `\n==========================================\n`;
    report += `PROMPT FOR AI: "I am a network administrator. The data above shows my current network monitoring status. Please analyze the latency and connectivity issues (if any) and suggest technical solutions or potential causes for the observed behavior."\n`;

    // Render in modal
    content.innerHTML = `<pre class="whitespace-pre-wrap">${report}</pre>`;

    // Store in global for copy function
    window.currentFullAIReport = report;
}

/**
 * Copies the generated report to clipboard
 */
function copyAIReport() {
    const reportText = window.currentFullAIReport;
    if (!reportText) return;

    navigator.clipboard.writeText(reportText).then(() => {
        const copyBtn = document.querySelector('[onclick="copyAIReport()"]');
        const originalHtml = copyBtn.innerHTML;
        copyBtn.innerHTML = '<i class="fas fa-check"></i> COPIED!';
        copyBtn.classList.replace('bg-emerald-500', 'bg-green-600');

        setTimeout(() => {
            copyBtn.innerHTML = originalHtml;
            copyBtn.classList.replace('bg-green-600', 'bg-emerald-500');
        }, 2000);
    }).catch(err => {
        console.error('Failed to copy: ', err);
    });
}

window.generateAIReport = generateAIReport;
window.copyAIReport = copyAIReport;

/**
 * Generates a text report from the network health data for AI analysis
 */
function generateNetworkHealthAIReport() {
    const h = window.lastNetworkHealthData;
    if (!h) {
        alert("Please run the analysis first.");
        return;
    }

    const timestamp = new Date().toLocaleString();
    const theoreticalDisplay = h.theoretical_speed > 0 ? `${h.theoretical_speed} Mb` : 'Not Set';

    let report = `NETWORK HEALTH ANALYSIS REPORT - ${timestamp}\n`;
    report += `==============================================\n\n`;

    report += `SUMMARY STATUS: ${h.summary}\n`;
    report += `HEALTH SCORE: ${h.health_score}/100\n\n`;

    report += `INTERNET CONNECTION:\n`;
    report += `--------------------\n`;
    report += `Plan Speed: ${theoreticalDisplay}\n`;
    if (h.speed) {
        report += `Actual Download: ${h.speed.download} Mb\n`;
        report += `Actual Upload: ${h.speed.upload} Mb\n`;
        report += `Ping Latency: ${h.speed.latency} ms\n`;
        report += `Performance Efficiency: ${h.speed.performance_ratio || 'N/A'}\n`;
    } else {
        report += `Speed Test: No recent data available.\n`;
    }

    report += `\nINFRASTRUCTURE & TOPOLOGY:\n`;
    report += `--------------------------\n`;
    report += `Gateway IP: ${h.gateway.ip}\n`;
    report += `Gateway Status: ${h.gateway.status}\n`;
    report += `Gateway Latency: ${h.gateway.latency}\n`;

    report += `\nLOCAL DEVICES:\n`;
    report += `--------------\n`;
    report += `Total Hosts Monitored (Local): ${h.devices.total}\n`;
    report += `Online Hosts: ${h.devices.up}\n`;
    report += `Average Network Latency: ${h.devices.avg_latency}\n`;

    report += `\nDETAILED DEVICE LIST:\n`;
    const hosts = Object.entries(window.ipDetails);
    hosts.forEach(([ip, data]) => {
        // Simple check if it's likely a local IP to focus the report
        if (ip.startsWith('192.168.') || ip.startsWith('10.') || ip.startsWith('172.') || ip === '127.0.0.1') {
            const netType = data.network_type ? ` [${data.network_type}]` : '';
            report += `- ${ip}${netType}: ${data.status} | Latency: ${data.average_response_time} ms | Device: ${data.service}\n`;
        }
    });

    report += `\n==============================================\n`;
    report += `PROMPT FOR AI: "Eres un experto en redes de telecomunicaciones. Te envio un reporte del estado de mi red local. Por favor, analiza el rendimiento, identifica posibles problemas de rendimiento o configuración, y sugiere mejoras técnicas específicas basadas en estos datos. Por favor, explica claramente cada sugerencia y proporciona un plan de acción detallado."\n`;

    // Show in modal
    const content = document.getElementById('network_health_content');
    content.innerHTML = `
        <div class="flex justify-between items-center mb-4">
            <h4 class="text-sm font-bold uppercase tracking-wider text-emerald-500">
                <i class="fas fa-robot mr-2"></i>Text Report for AI
            </h4>
            <button onclick="copyNetworkHealthToClipboard()" id="btnCopyHealth" class="px-4 py-2 bg-emerald-500 text-white text-xs font-bold rounded-lg hover:bg-emerald-600 transition-all flex items-center gap-2">
                <i class="fas fa-copy"></i> COPY TO CLIPBOARD
            </button>
        </div>
        <div class="bg-gray-900 rounded-2xl p-6 border border-gray-800 shadow-inner">
            <pre class="whitespace-pre-wrap font-mono text-xs text-emerald-400/90 leading-relaxed" id="healthTextReport">${report}</pre>
        </div>
        <div class="flex justify-between items-center mt-6">
            <button onclick="showNetworkHealth()" class="text-xs text-blue-500 hover:text-blue-600 font-bold flex items-center gap-2">
                <i class="fas fa-arrow-left"></i> Back to Visual Analysis
            </button>
            <a href="https://negociatumente.com/guia-redes" target="_blank" class="text-xs text-amber-600 dark:text-amber-400 font-black flex items-center gap-2 hover:underline">
                ¿Necesitas ayuda? Consigue la Guía <i class="fas fa-external-link-alt text-[10px]"></i>
            </a>
        </div>

    `;

    window.currentNetworkHealthAIReport = report;
}

/**
 * Copies the network health report to clipboard
 */
function copyNetworkHealthToClipboard() {
    const text = window.currentNetworkHealthAIReport;
    if (!text) return;

    navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById('btnCopyHealth');
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> COPIED!';
        btn.classList.replace('bg-emerald-500', 'bg-green-600');
        setTimeout(() => {
            btn.innerHTML = original;
            btn.classList.replace('bg-green-600', 'bg-emerald-500');
        }, 2000);
    });
}

window.generateNetworkHealthAIReport = generateNetworkHealthAIReport;
window.copyNetworkHealthToClipboard = copyNetworkHealthToClipboard;

/**
 * Renders a graphical topology map of the local network in HORIZONTAL layout
 */
let currentTopologyScale = 1.0;
let topologyOrientation = 'horizontal'; // 'horizontal' or 'vertical'
window.topologyOrientation = topologyOrientation; // Expose to window

function toggleTopologyOrientation() {
    topologyOrientation = (topologyOrientation === 'horizontal') ? 'vertical' : 'horizontal';
    window.topologyOrientation = topologyOrientation; // Expose to window

    // Update UI elements
    const icon = document.querySelector('.topology-orientation-icon');
    const text = document.getElementById('orientation-text');

    if (icon) {
        icon.className = topologyOrientation === 'horizontal' ? 'fas topology-orientation-icon fa-arrows-alt-v' : 'fas topology-orientation-icon fa-arrows-alt-h';
    }
    if (text) {
        text.innerText = topologyOrientation === 'horizontal' ? 'Vertical' : 'Horizontal';
    }

    renderTopologyMap();
}

function zoomTopology(factor) {
    currentTopologyScale *= factor;
    // Limit zoom
    if (currentTopologyScale < 0.2) currentTopologyScale = 0.2;
    if (currentTopologyScale > 3) currentTopologyScale = 3;

    applyTopologyZoom();
}

function resetTopologyZoom() {
    currentTopologyScale = 1.0;
    applyTopologyZoom();
}

function applyTopologyZoom() {
    const wrapper = document.querySelector('.topology-wrapper-h');
    if (wrapper) {
        wrapper.style.transition = 'transform 0.2s ease-out';
        wrapper.style.transform = `scale(${currentTopologyScale})`;
        wrapper.style.transformOrigin = '0 0';

        // Adjust the container's min-dimensions to allow scrolling when zoomed in
        const container = wrapper.parentElement;
        if (container) {
            // This is a bit of a hack to ensure scrollbars appear when scaling up
            // transform scale doesn't affect layout size, so we force it
        }
    }
}

function renderTopologyMap() {
    const container = document.getElementById('full_topology_container');
    if (!container) return;

    const hosts = Object.entries(window.ipDetails);

    if (hosts.length === 0) {
        container.innerHTML = '<div class="flex items-center justify-center h-full text-gray-500">No data available to render topology.</div>';
        return;
    }

    let gateway = null;
    const repeaters = [];
    const others = [];

    // Identify roles
    hosts.forEach(([ip, data]) => {
        const item = { ip, ...data };
        const serviceLower = (data.service || "").toLowerCase();
        const networkLower = (data.network_type || "").toLowerCase();
        const isGateway = data.service === 'Gateway' || ip.endsWith('.1');

        // Identify Hubs: Named after Repeater/Repetidor/AP
        const isRepeaterHub = !isGateway && (
            serviceLower.includes('repeater') ||
            serviceLower.includes('repetidor') ||
            (serviceLower.includes('ap') && !serviceLower.includes('apple'))
        );

        if (isGateway) {
            gateway = item;
        } else if (isRepeaterHub) {
            repeaters.push(item);
        } else {
            others.push(item);
        }
    });

    const isH = topologyOrientation === 'horizontal';
    const wrapperClass = isH ? 'flex items-center min-w-max min-h-full' : 'flex flex-col items-center min-w-full min-h-max';
    const busLineClass = isH ? 'w-12 h-0.5 bg-indigo-600' : 'w-0.5 h-12 bg-indigo-600';
    const segmentListClass = isH ? 'flex flex-col gap-12 relative' : 'flex flex-col gap-12 relative mt-4';

    // Start building HTML
    let html = `
        <div class="topology-wrapper-h p-12 ${wrapperClass}">
            
            <!-- Level 1: Internet -->
            <div class="flex flex-col items-center">
                <div class="w-16 h-16 md:w-20 md:h-20 bg-blue-500 text-white rounded-2xl flex items-center justify-center shadow-xl shadow-blue-500/20 z-10 border-4 border-white dark:border-gray-800 rotate-45 group hover:rotate-0 transition-transform duration-500">
                    <i class="fas fa-cloud text-2xl md:text-3xl -rotate-45 group-hover:rotate-0 transition-transform"></i>
                </div>
                <div class="text-[8px] md:text-[10px] font-black mt-4 uppercase text-blue-500 tracking-widest">WAN / INTERNET</div>
            </div>

            <!-- Connection: WAN -> Gateway -->
            ${isH ? `
                <div class="w-16 h-0.5 bg-gradient-to-r from-blue-500 to-indigo-600 relative">
                    <div class="absolute -right-1 -top-1 w-2.5 h-2.5 bg-indigo-600 rounded-full"></div>
                </div>
            ` : `
                <div class="h-16 w-0.5 bg-gradient-to-b from-blue-500 to-indigo-600 relative">
                    <div class="absolute -bottom-1 -left-1 w-2.5 h-2.5 bg-indigo-600 rounded-full"></div>
                </div>
            `}

            <!-- Level 2: Gateway (Router) -->
            <div class="flex flex-col items-center z-20">
                <div class="p-6 bg-indigo-600 text-white rounded-3xl shadow-2xl border-4 border-white dark:border-gray-800 flex flex-col items-center min-w-[160px] relative group hover:scale-105 transition-all">
                    <div class="absolute -top-3 -right-3 w-8 h-8 bg-green-500 rounded-full border-4 border-white dark:border-gray-900 flex items-center justify-center shadow-lg">
                        <i class="fas fa-check text-[10px]"></i>
                    </div>
                    <i class="fas fa-server text-3xl mb-3"></i>
                    <div class="text-xs font-black uppercase tracking-tight">${gateway ? gateway.service : 'Router'}</div>
                    <div class="text-[10px] font-mono opacity-60">${gateway ? gateway.ip : '192.168.18.1'}</div>
                </div>
            </div>

            <!-- Main Distribution Bus -->
            <div class="${busLineClass}"></div>

            <!-- Level 3: Network Segments -->
            <div class="${segmentListClass}">
                <!-- Vertical Bus Line (Visible in H mode as vertical, in V mode as vertical too) -->
                <div class="absolute left-0 top-0 bottom-0 w-0.5 bg-gradient-to-b from-indigo-600 via-emerald-500 to-amber-500 rounded-full"></div>

                ${renderNetworkSegment('Ethernet', others.filter(d => (d.network_type || '').toLowerCase().includes('ethernet')), 'blue', 'network-wired')}
                ${renderNetworkSegment('WiFi 2.4GHz', others.filter(d => (d.network_type || '').includes('2,4') || (d.network_type || '').includes('2.4')), 'amber', 'wifi')}
                ${renderNetworkSegment('WiFi 5GHz', others.filter(d => (d.network_type || '').includes('5GHz')), 'emerald', 'wifi')}
                ${renderNetworkSegment('WiFi 6GHz', others.filter(d => (d.network_type || '').includes('6GHz')), 'purple', 'bolt')}
                
                <!-- Repeaters and their dependencies -->
                ${repeaters.length > 0 ? repeaters.map(rep => renderRepeaterNode(rep, others)).join('') : (
            others.some(d => (d.network_type || '').toLowerCase().includes('repet')) ?
                renderRepeaterNode({ ip: '---', service: 'Repeater Bridge', status: 'UP', average_response_time: '-' }, others) : ''
        )}
            </div>
        </div>

        <style>
            .topology-wrapper-h {
                background-image: radial-gradient(circle at 2px 2px, rgba(0,0,0,0.05) 1px, transparent 0);
                background-size: 32px 32px;
                font-family: 'Inter', sans-serif;
            }
            .dark .topology-wrapper-h {
                background-image: radial-gradient(circle at 2px 2px, rgba(255,255,255,0.03) 1px, transparent 0);
            }
            .device-card-h {
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            }
            .device-card-h:hover {
                transform: translateX(10px);
                box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            }
        </style>
    `;

    container.innerHTML = html;

    // Re-apply zoom if it was set
    setTimeout(applyTopologyZoom, 50);
}

function renderNetworkSegment(title, devices, color, icon) {
    if (devices.length === 0) return '';

    return `
        <div class="flex items-center group/segment">
            <!-- Connection to main bus -->
            <div class="w-12 h-0.5 bg-gray-300 dark:bg-gray-700 group-hover/segment:bg-indigo-400 transition-colors"></div>
            
            <div class="flex flex-col gap-3 ml-2">
                <div class="flex items-center gap-2 mb-1">
                    <div class="w-6 h-6 bg-${color}-100 dark:bg-${color}-900/30 text-${color}-600 rounded flex items-center justify-center text-[10px]">
                        <i class="fas fa-${icon}"></i>
                    </div>
                    <span class="text-[10px] font-black uppercase tracking-widest text-gray-400 opacity-60 group-hover/segment:opacity-100 transition-opacity">${title}</span>
                </div>
                <div class="flex flex-wrap gap-4 max-w-4xl">
                    ${devices.map(dev => renderDeviceSkin(dev)).join('')}
                </div>
            </div>
        </div>
    `;
}

function renderRepeaterNode(repeater, allOthers) {
    const repeaterSuffix = (repeater.ip || "").split('.').pop();
    const isBridge = repeater.ip === '---';

    // Devices connected through this specific repeater
    const repeaterDevices = allOthers.filter(d => {
        const nt = (d.network_type || '').toLowerCase();
        const ipSuffix = (d.ip || "").split('.').pop();

        // Exact match with suffix: "Repeater (.50)"
        if (nt === `repeater (.${repeaterSuffix})`) return true;

        // Backward compatibility / Generic fallback:
        // If it's a bridge or the only repeater, or manually set to generic
        if (nt === 'Repeater/Mesh' || nt === 'repetidor/ap') {
            // For now, if generic, we show it on the first repeater found or bridge
            // To be more precise, we only show 'Generic' on the 'Repeater Bridge' if it exists
            return isBridge;
        }

        return false;
    });

    return `
        <div class="flex items-center group/segment">
            <!-- Connection to main bus -->
            <div class="w-12 h-0.5 bg-gray-300 dark:bg-gray-700 group-hover/segment:bg-red-400 transition-colors"></div>
            
            <div class="flex flex-col gap-3 ml-2">
                <div class="flex items-center gap-2 mb-1">
                    <div class="w-6 h-6 bg-red-100 dark:bg-red-900/30 text-red-600 rounded flex items-center justify-center text-[10px]">
                        <i class="fas fa-broadcast-tower"></i>
                    </div>
                    <span class="text-[10px] font-black uppercase tracking-widest text-gray-400 opacity-60 group-hover/segment:opacity-100 transition-opacity">Repeater Bridge</span>
                </div>
                <div class="flex items-center gap-2">
                    <!-- Square Repeater Hub -->
                    <div class="w-24 h-24 bg-red-600 text-white rounded-xl shadow-xl border-4 border-white dark:border-gray-800 flex flex-col items-center justify-center z-10 hover:scale-110 transition-all cursor-pointer relative" 
                         onclick="showIpDetailModal('${repeater.ip}')">
                        <i class="fas fa-broadcast-tower text-2xl mb-1"></i>
                        <div class="text-[10px] font-black uppercase tracking-tighter text-center leading-tight px-2">${repeater.service}</div>
                        <div class="text-[8px] font-mono opacity-60 mt-0.5">${repeater.ip}</div>
                    </div>
                    
                    ${repeaterDevices.length > 0 ? `
                        <!-- Connection line from repeater -->
                        <div class="w-8 h-0.5 bg-red-400"></div>
                        
                        <!-- Bridged devices in the same row -->
                        <div class="flex items-center gap-3">
                            ${repeaterDevices.map((dev, idx) => `
                                ${idx > 0 ? '<div class="w-4 h-0.5 bg-red-200 dark:bg-red-900/30"></div>' : ''}
                                ${renderDeviceSkin(dev)}
                            `).join('')}
                        </div>
                    ` : '<div class="text-[10px] text-gray-400 italic px-4 py-2 ml-4">No bridged clients</div>'}
                </div>
            </div>
        </div>
    `;
}

function renderDeviceSkin(dev) {
    const isUp = dev.status === 'UP';
    const statusColor = isUp ? 'bg-green-500' : 'bg-red-500';
    const latencyVal = parseFloat(dev.average_response_time);
    const latencyColor = latencyVal < 30 ? 'text-emerald-500' : (latencyVal < 100 ? 'text-amber-500' : 'text-red-500');
    const responseTimeDisplay = !isNaN(latencyVal) ? latencyVal.toFixed(2) : dev.average_response_time;

    return `
        <div class="device-card-h p-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl shadow-sm flex items-center gap-4 min-w-[200px] cursor-pointer relative overflow-hidden group/card" 
             onclick="showIpDetailModal('${dev.ip}')">
            
            <!-- Side Status Indicator -->
            <div class="absolute left-0 top-0 bottom-0 w-1 ${statusColor} opacity-50 group-hover/card:opacity-100 transition-opacity"></div>
            
            <div class="w-11 h-11 bg-gray-50 dark:bg-gray-700 rounded-xl flex items-center justify-center text-gray-400 group-hover/card:text-indigo-500 transition-all group-hover/card:scale-110 shadow-inner">
                <i class="fas fa-desktop text-md"></i>
            </div>
            
            <div class="flex flex-col overflow-hidden">
                <div class="flex items-center gap-2">
                    <span class="text-[12px] font-black tracking-tight truncate text-gray-800 dark:text-gray-100">${dev.service}</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="w-2 h-2 ${statusColor} rounded-full animate-pulse"></span>
                    <span class="text-[10px] font-mono text-gray-500">${dev.ip}</span>
                </div>
            </div>
            
            <div class="ml-auto flex flex-col items-end border-l border-gray-100 dark:border-gray-700 pl-4">
                 <span class="text-[12px] font-black ${latencyColor}">${responseTimeDisplay}</span>
                 <span class="text-[8px] text-gray-400 uppercase font-black tracking-tighter">ms</span>
            </div>
        </div>
    `;
}

window.renderTopologyMap = renderTopologyMap;
window.zoomTopology = zoomTopology;
window.resetTopologyZoom = resetTopologyZoom;
window.toggleTopologyOrientation = toggleTopologyOrientation;
window.topologyOrientation = () => topologyOrientation;
