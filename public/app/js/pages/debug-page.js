/**
 * System Status Page Module
 * Displays API connection status and authentication state
 */

import { hasToken } from '../tokenService.js';
import { getCurrentEnvironment, API_CONFIG } from '../config.js';
import * as ApiService from '../apiService.js';
import { get } from '../apiService.js';

// Make functions available globally
window.testApiConnection = testApiConnection;
window.clearToken = clearSessionToken;
window.checkBlackoutStatus = checkBlackoutStatus;

// Display environment info
function displayEnvironmentInfo() {
    const envName = getCurrentEnvironment();
    document.getElementById('environment-name').textContent = envName.toUpperCase();
    document.getElementById('api-base-url').textContent = API_CONFIG.BASE_URL;
}

// Display authentication status
function displayAuthStatus() {
    const tokenPresent = hasToken();
    const tokenStatusElement = document.getElementById('token-status');

    if (tokenPresent) {
        tokenStatusElement.textContent = '✅ Yes';
        tokenStatusElement.className = 'status-value success';
    } else {
        tokenStatusElement.textContent = '❌ No';
        tokenStatusElement.className = 'status-value error';
    }
}

// Test API connection
async function testApiConnection() {
    const statusElement = document.getElementById('connection-status');
    const button = document.getElementById('test-connection-btn');

    // Update UI to show testing state
    statusElement.textContent = '⏳ Testing...';
    statusElement.className = 'status-value';
    button.disabled = true;

    try {
        // Try to fetch events (public endpoint)
        const events = await ApiService.getAllEvents();

        if (events && Array.isArray(events)) {
            statusElement.textContent = `✅ Connected (${events.length} events found)`;
            statusElement.className = 'status-value success';
        } else {
            statusElement.textContent = '⚠️ Connected but unexpected response';
            statusElement.className = 'status-value warning';
        }
    } catch (error) {
        statusElement.textContent = `❌ Failed: ${error.message}`;
        statusElement.className = 'status-value error';
    } finally {
        button.disabled = false;
    }
}

// Check blackout status via GET /api/status
async function checkBlackoutStatus() {
    const statusEl = document.getElementById('blackout-status');
    const windowEl = document.getElementById('blackout-window');
    const button = document.getElementById('refresh-blackout-btn');

    statusEl.textContent = '⏳ Checking...';
    statusEl.className = 'status-value';
    button.disabled = true;

    try {
        const response = await get(API_CONFIG.ENDPOINTS.STATUS);
        const isBlackout = response?.data?.isBlackout === true;

        if (isBlackout) {
            statusEl.textContent = '🔒 Yes — registration locked';
            statusEl.className = 'status-value error';
        } else {
            statusEl.textContent = '✅ No — registration open';
            statusEl.className = 'status-value success';
        }

        // Current server date and time source
        const date = response?.data?.currentDate ?? null;
        const time = response?.data?.currentTime ?? null;
        const source = response?.data?.timeSource ?? null;

        document.getElementById('server-date').textContent =
            date && time ? `${date} ${time}` : '—';

        const timeSourceEl = document.getElementById('time-source');
        if (source === 'simulated') {
            timeSourceEl.textContent = 'simulated';
            timeSourceEl.className = 'status-value warning';
        } else if (source) {
            timeSourceEl.textContent = source;
            timeSourceEl.className = 'status-value success';
        } else {
            timeSourceEl.textContent = '—';
            timeSourceEl.className = 'status-value';
        }

        // Show configured window if returned
        const from = response?.data?.blackout_from ?? null;
        const to = response?.data?.blackout_to ?? null;
        windowEl.textContent = from && to ? `${from} – ${to}` : '10:00 AM – 6:00 PM (default)';
    } catch (error) {
        statusEl.textContent = `❌ Failed: ${error.message}`;
        statusEl.className = 'status-value error';
        document.getElementById('server-date').textContent = '—';
        document.getElementById('time-source').textContent = '—';
        windowEl.textContent = '—';
    } finally {
        button.disabled = false;
    }
}

// Clear session token (for testing)
function clearSessionToken() {
    if (confirm('Are you sure you want to clear your session token? You will need to sign in again.')) {
        sessionStorage.removeItem('nsc_auth_token');
        alert('Session token cleared!');
        displayAuthStatus();
    }
}

// Initial load
displayEnvironmentInfo();
displayAuthStatus();

// Auto-test connection on load
testApiConnection();

// Auto-check blackout status on load
checkBlackoutStatus();
