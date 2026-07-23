/**
 * Event Report Page
 * Generate printable crew assignment reports for events
 */

import { requireAuth, getCurrentUser, signOut } from '../authService.js';
import { updateAuthenticatedNavigation, addAdminLink } from '../navigationService.js';
import { initHamburgerMenu } from '../hamburger.js';
import * as eventService from '../eventService.js';
import * as apiService from '../apiService.js';
import { API_CONFIG } from '../config.js';
import { showToast } from '../toast.js';

let allEvents = [];

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

    // Load events
    await loadEvents();

    // Setup event listeners
    setupEventListeners();
});

/**
 * Load all events
 */
async function loadEvents() {
    try {
        allEvents = await eventService.getAllEvents();
        populateEventSelect();
    } catch (error) {
        console.error('Failed to load events:', error);
        showToast('Failed to load events', 'error');
    }
}

/**
 * Populate event dropdown
 */
function populateEventSelect() {
    const select = document.getElementById('event-select');
    const loadBtn = document.getElementById('load-btn');

    // Clear existing options (keep the first placeholder)
    select.innerHTML = '<option value="">-- Select an event --</option>';

    // Add event options
    allEvents.forEach(event => {
        const option = document.createElement('option');
        option.value = event.eventId;
        // Parse date as local date by appending time component
        const localDate = new Date(event.date + 'T12:00:00');
        option.textContent = `${event.eventId} (${localDate.toLocaleDateString()})`;
        select.appendChild(option);
    });

    // Enable/disable load button based on selection
    select.addEventListener('change', () => {
        loadBtn.disabled = !select.value;
    });
}

/**
 * Setup event listeners
 */
function setupEventListeners() {
    const loadBtn = document.getElementById('load-btn');
    const printBtn = document.getElementById('print-btn');
    const eventSelect = document.getElementById('event-select');

    loadBtn.addEventListener('click', async () => {
        const eventId = eventSelect.value;
        if (!eventId) return;
        await generateReport(eventId);
    });

    printBtn.addEventListener('click', () => {
        window.print();
    });
}

/**
 * Generate report for selected event
 */
async function generateReport(eventId) {
    const loadBtn = document.getElementById('load-btn');
    const printBtn = document.getElementById('print-btn');
    const emptyState = document.getElementById('empty-state');
    const reportContainer = document.getElementById('report-container');

    try {
        // Show loading state
        loadBtn.classList.add('loading');
        loadBtn.disabled = true;

        // Fetch event with flotilla data via /api/events/{eventId}
        const response = await apiService.get(API_CONFIG.ENDPOINTS.EVENT_BY_ID, { id: eventId });

        if (!response.success || !response.data) {
            throw new Error('Failed to load event data');
        }

        const eventData = response.data;

        // Hide empty state, show report
        emptyState.style.display = 'none';
        reportContainer.style.display = 'block';

        // Render report sections
        renderEventHeader(eventData.event);
        renderAssignmentsTable(eventData.flotilla?.crewedBoats || [], eventId);
        renderWaitlist(eventData.flotilla?.waitlistBoats || [], eventData.flotilla?.waitlistCrews || []);
        renderSummary(eventData.flotilla || {});

        // Enable print button
        printBtn.disabled = false;

        showToast('Report generated successfully', 'success');
    } catch (error) {
        console.error('Failed to generate report:', error);
        showToast(error.message || 'Failed to generate report', 'error');

        // Keep report hidden on error
        reportContainer.style.display = 'none';
        emptyState.style.display = 'block';
    } finally {
        // Remove loading state
        loadBtn.classList.remove('loading');
        loadBtn.disabled = false;
    }
}

/**
 * Render event header
 */
function renderEventHeader(event) {
    const titleEl = document.getElementById('event-title');
    const detailsEl = document.getElementById('event-details');

    const localDate = new Date(event.date + 'T12:00:00');

    titleEl.textContent = event.eventId;
    detailsEl.textContent = `${localDate.toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    })} • ${event.startTime} - ${event.finishTime}`;
}

/**
 * Compute the assignment-rule compliance string for a crewed boat.
 * Returns a subset of the letters "AWSPR", one per rule the assignment complies with:
 *   A - assistance not required, or a competent (skill 2) crew is aboard
 *   W - every assigned crew has this boat in their whitelist
 *   S - crew skill levels span no more than one level
 *   P - no assigned crew is the partner of another assigned crew
 *   R - no assigned crew has been assigned to this boat at an earlier event
 */
function computeCompliance(assignment, eventId) {
    const boat = assignment.boat;
    const crews = assignment.crews || [];
    let compliance = '';

    if (!boat.assistanceRequired || crews.some(c => c.skill === 2)) {
        compliance += 'A';
    }

    if (crews.every(c => (c.whitelist || []).includes(boat.key))) {
        compliance += 'W';
    }

    const skills = crews.map(c => c.skill);
    const skillSpread = skills.length > 0 ? Math.max(...skills) - Math.min(...skills) : 0;
    if (skillSpread <= 1) {
        compliance += 'S';
    }

    const crewKeys = new Set(crews.map(c => c.key));
    if (!crews.some(c => c.partnerKey && c.partnerKey !== c.key && crewKeys.has(c.partnerKey))) {
        compliance += 'P';
    }

    const repeatOk = crews.every(c => {
        const history = c.history || {};
        return Object.entries(history).every(([evId, boatKey]) => evId === eventId || boatKey !== boat.key);
    });
    if (repeatOk) {
        compliance += 'R';
    }

    return compliance;
}

/**
 * Render crew assignments table
 */
function renderAssignmentsTable(crewedBoats, eventId) {
    const tbody = document.getElementById('assignments-tbody');
    tbody.innerHTML = '';

    if (!crewedBoats || crewedBoats.length === 0) {
        const row = tbody.insertRow();
        const cell = row.insertCell(0);
        cell.colSpan = 3;
        cell.textContent = 'No crew assignments yet';
        cell.style.textAlign = 'center';
        cell.style.fontStyle = 'italic';
        cell.style.color = 'var(--text-gray)';
        return;
    }

    crewedBoats.forEach(assignment => {
        const row = tbody.insertRow();

        // Boat name
        const boatCell = row.insertCell(0);
        boatCell.innerHTML = `<strong>${assignment.boat.displayName}</strong>`;

        // Crew members
        const crewCell = row.insertCell(1);
        const crewNames = assignment.crews.map(c => c.displayName).join(', ');
        crewCell.textContent = crewNames || 'No crew assigned';

        // Compliance
        const complianceCell = row.insertCell(2);
        complianceCell.textContent = computeCompliance(assignment, eventId);
        complianceCell.style.textAlign = 'center';
    });
}

/**
 * Render waitlist section
 */
function renderWaitlist(waitlistedBoats, waitlistedCrews) {
    const section = document.getElementById('waitlist-section');
    const boatsContainer = document.getElementById('waitlist-boats-container');
    const crewsContainer = document.getElementById('waitlist-crews-container');

    boatsContainer.innerHTML = '';
    crewsContainer.innerHTML = '';

    const hasWaitlist = (waitlistedBoats && waitlistedBoats.length > 0) ||
                        (waitlistedCrews && waitlistedCrews.length > 0);

    if (!hasWaitlist) {
        section.style.display = 'none';
        return;
    }

    section.style.display = 'block';

    // Render waitlisted boats
    if (waitlistedBoats && waitlistedBoats.length > 0) {
        const boatsDiv = document.createElement('div');
        boatsDiv.className = 'waitlist-group';
        boatsDiv.innerHTML = `
            <h4>Boats Without Crew</h4>
            <ul>${waitlistedBoats.map(b => `<li>${b.displayName}</li>`).join('')}</ul>
        `;
        boatsContainer.appendChild(boatsDiv);
    }

    // Render waitlisted crews
    if (waitlistedCrews && waitlistedCrews.length > 0) {
        const crewsDiv = document.createElement('div');
        crewsDiv.className = 'waitlist-group';
        crewsDiv.innerHTML = `
            <h4>Crew Without Boats</h4>
            <ul>${waitlistedCrews.map(c => `<li>${c.displayName}</li>`).join('')}</ul>
        `;
        crewsContainer.appendChild(crewsDiv);
    }
}

/**
 * Render summary statistics
 */
function renderSummary(flotilla) {
    const totalBoats = flotilla.crewedBoats?.length || 0;
    const totalCrews = flotilla.crewedBoats?.reduce((sum, boat) =>
        sum + (boat.crews?.length || 0), 0) || 0;
    const waitlistCount = (flotilla.waitlistBoats?.length || 0) +
                         (flotilla.waitlistCrews?.length || 0);

    document.getElementById('total-boats').textContent = totalBoats;
    document.getElementById('total-crews').textContent = totalCrews;
    document.getElementById('waitlist-count').textContent = waitlistCount;
}
