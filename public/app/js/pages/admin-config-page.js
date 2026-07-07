/**
 * Admin Configuration Page
 * Manage season-wide configuration settings
 */

import { requireAuth, getCurrentUser, signOut } from '../authService.js';
import { updateAuthenticatedNavigation, addAdminLink } from '../navigationService.js';
import { initHamburgerMenu } from '../hamburger.js';
import * as adminService from '../adminService.js';
import { showToast } from '../toast.js';

let currentConfig = null;

// Initialize page
document.addEventListener('DOMContentLoaded', async () => {
    // Initialize hamburger menu
    initHamburgerMenu();

    // Require authentication
    requireAuth();

    // Get current user
    const user = await getCurrentUser();
    if (!user) {
        window.location.href = 'signin.html';
        return;
    }

    // Check admin privileges
    if (!user.isAdmin) {
        console.warn('Access denied: User is not an admin');
        window.location.href = 'dashboard.html';
        return;
    }

    // Update navigation
    updateAuthenticatedNavigation(user, signOut);
    addAdminLink(user);

    // Load configuration
    await loadConfig();

    // Setup event listeners
    setupEventListeners();
});

/**
 * Load current configuration
 */
async function loadConfig() {
    try {
        currentConfig = await adminService.getSeasonConfig();
        populateForm(currentConfig);
    } catch (error) {
        console.error('Failed to load configuration:', error);
        showToast('Failed to load configuration', 'error');
    }
}

/**
 * Populate form with configuration data
 */
function populateForm(config) {
    // Time source
    const sourceProduction = document.getElementById('source-production');
    const sourceSimulated = document.getElementById('source-simulated');
    if (config.source === 'simulated') {
        sourceSimulated.checked = true;
        showSimulatedDateSection();
    } else {
        sourceProduction.checked = true;
    }

    // Simulated date and time (stored as "YYYY-MM-DD HH:MM:SS")
    if (config.simulated_date) {
        const [datePart, timePart] = config.simulated_date.split(' ');
        document.getElementById('simulated-date').value = datePart ?? '';
        // <input type="time"> expects HH:MM
        document.getElementById('simulated-time').value = timePart ? timePart.substring(0, 5) : '00:00';
    }

    // Year
    document.getElementById('year').value = config.year || new Date().getFullYear();

    // Event times
    document.getElementById('start-time').value = config.start_time || '10:00:00';
    document.getElementById('finish-time').value = config.finish_time || '18:00:00';

    // Blackout window
    document.getElementById('blackout-from').value = config.blackout_from || '10:00:00';
    document.getElementById('blackout-to').value = config.blackout_to || '18:00:00';

    // Last updated
    if (config.updated_at) {
        const updatedDate = new Date(config.updated_at);
        document.getElementById('last-updated').textContent = updatedDate.toLocaleString();
    }
}

/**
 * Setup event listeners
 */
function setupEventListeners() {
    const form = document.getElementById('config-form');
    const sourceProduction = document.getElementById('source-production');
    const sourceSimulated = document.getElementById('source-simulated');

    // Toggle simulated date section
    sourceProduction.addEventListener('change', () => {
        if (sourceProduction.checked) {
            hideSimulatedDateSection();
        }
    });

    sourceSimulated.addEventListener('change', () => {
        if (sourceSimulated.checked) {
            showSimulatedDateSection();
        }
    });

    // Form submission
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        await saveConfig();
    });

    // Recalculate pipeline
    document.getElementById('recalculate-btn').addEventListener('click', recalculatePipeline);
}

/**
 * Show simulated date section
 */
function showSimulatedDateSection() {
    const section = document.getElementById('simulated-date-section');
    section.style.display = 'block';
}

/**
 * Hide simulated date section
 */
function hideSimulatedDateSection() {
    const section = document.getElementById('simulated-date-section');
    section.style.display = 'none';
}

/**
 * Validate form
 */
function validateForm() {
    const errors = [];

    // Year validation
    const year = parseInt(document.getElementById('year').value);
    if (year < 2020 || year > 2100) {
        errors.push('Year must be between 2020 and 2100');
    }

    // Time format validation (HH:MM:SS)
    const timePattern = /^[0-2][0-9]:[0-5][0-9]:[0-5][0-9]$/;
    const startTime = document.getElementById('start-time').value;
    const finishTime = document.getElementById('finish-time').value;
    const blackoutFrom = document.getElementById('blackout-from').value;
    const blackoutTo = document.getElementById('blackout-to').value;

    if (!timePattern.test(startTime)) {
        errors.push('Start time must be in format HH:MM:SS');
    }
    if (!timePattern.test(finishTime)) {
        errors.push('Finish time must be in format HH:MM:SS');
    }
    if (!timePattern.test(blackoutFrom)) {
        errors.push('Blackout from must be in format HH:MM:SS');
    }
    if (!timePattern.test(blackoutTo)) {
        errors.push('Blackout to must be in format HH:MM:SS');
    }

    // Logical validation
    if (finishTime <= startTime) {
        errors.push('Finish time must be after start time');
    }
    if (blackoutTo <= blackoutFrom) {
        errors.push('Blackout end time must be after blackout start time');
    }

    // Simulated date/time validation
    const source = document.querySelector('input[name="source"]:checked').value;
    const simulatedDate = document.getElementById('simulated-date').value;
    const simulatedTime = document.getElementById('simulated-time').value;
    if (source === 'simulated' && !simulatedDate) {
        errors.push('Simulated date is required when using simulated time source');
    }
    if (source === 'simulated' && simulatedDate && !simulatedTime) {
        errors.push('Simulated time is required when using simulated time source');
    }

    return errors;
}

/**
 * Save configuration
 */
async function saveConfig() {
    const submitBtn = document.querySelector('button[type="submit"]');

    // Validate form
    const errors = validateForm();
    if (errors.length > 0) {
        showToast(errors.join('. '), 'error');
        return;
    }

    try {
        // Show loading state
        submitBtn.classList.add('loading');
        submitBtn.disabled = true;

        // Gather form data
        const source = document.querySelector('input[name="source"]:checked').value;
        const simulatedDate = document.getElementById('simulated-date').value;
        const simulatedTime = document.getElementById('simulated-time').value || '00:00';
        const year = parseInt(document.getElementById('year').value);
        const startTime = document.getElementById('start-time').value;
        const finishTime = document.getElementById('finish-time').value;
        const blackoutFrom = document.getElementById('blackout-from').value;
        const blackoutTo = document.getElementById('blackout-to').value;

        const configData = {
            source,
            year,
            start_time: startTime,
            finish_time: finishTime,
            blackout_from: blackoutFrom,
            blackout_to: blackoutTo
        };

        // Only include simulated_date if source is simulated
        // Combine date + time into "YYYY-MM-DD HH:MM:SS" as expected by the API
        if (source === 'simulated' && simulatedDate) {
            configData.simulated_date = `${simulatedDate} ${simulatedTime}:00`;
        }

        // Save configuration
        await adminService.updateSeasonConfig(configData);

        showToast('Configuration saved successfully', 'success');

        // Reload configuration to show updated values
        await loadConfig();
    } catch (error) {
        console.error('Failed to save configuration:', error);
        showToast(error.message || 'Failed to save configuration', 'error');
    } finally {
        // Remove loading state
        submitBtn.classList.remove('loading');
        submitBtn.disabled = false;
    }
}

/**
 * Manually re-run the season update pipeline
 */
async function recalculatePipeline() {
    const recalculateBtn = document.getElementById('recalculate-btn');

    try {
        recalculateBtn.classList.add('loading');
        recalculateBtn.disabled = true;

        const result = await adminService.recalculatePipeline();

        showToast(`Recalculated: ${result.events_processed} event(s) processed, ${result.flotillas_generated} flotilla(s) generated`, 'success');
    } catch (error) {
        console.error('Failed to recalculate season pipeline:', error);
        showToast(error.message || 'Failed to recalculate season pipeline', 'error');
    } finally {
        recalculateBtn.classList.remove('loading');
        recalculateBtn.disabled = false;
    }
}
