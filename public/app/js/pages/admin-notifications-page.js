/**
 * Admin Notifications Page
 * Compose and send custom BCC notifications to event participants
 */

import { requireAuth, getCurrentUser, signOut } from '../authService.js';
import { updateAuthenticatedNavigation, addAdminLink } from '../navigationService.js';
import { initHamburgerMenu } from '../hamburger.js';
import * as eventService from '../eventService.js';
import * as adminService from '../adminService.js';
import { showToast } from '../toast.js';

let allEvents = [];
let participantData = null;

// Initialize page
document.addEventListener('DOMContentLoaded', async () => {
    initHamburgerMenu();
    requireAuth();

    const user = await getCurrentUser();
    if (!user) {
        window.location.href = 'signin.html';
        return;
    }

    if (!user.isAdmin) {
        console.warn('Access denied: User is not an admin');
        window.location.href = 'dashboard.html';
        return;
    }

    updateAuthenticatedNavigation(user, signOut);
    addAdminLink(user);

    await loadEvents();
    setupEventListeners();
});

/**
 * Load all events and populate the dropdown
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

function populateEventSelect() {
    const select = document.getElementById('event-select');

    select.innerHTML = '<option value="">-- Select an event --</option>';

    allEvents.forEach(event => {
        const option = document.createElement('option');
        option.value = event.eventId;
        const localDate = new Date(event.date + 'T12:00:00');
        option.textContent = `${event.eventId} (${localDate.toLocaleDateString()})`;
        select.appendChild(option);
    });

    select.addEventListener('change', () => {
        document.getElementById('load-btn').disabled = !select.value;

        // Hide compose section until participants are loaded for the new selection
        participantData = null;
        document.getElementById('compose-section').style.display = 'none';
        document.getElementById('empty-state').style.display = '';
    });
}

function setupEventListeners() {
    document.getElementById('load-btn').addEventListener('click', async () => {
        const eventId = document.getElementById('event-select').value;
        if (!eventId) return;
        await loadParticipants(eventId);
    });

    document.getElementById('copy-boat-btn').addEventListener('click', () => copyEmails('boat'));
    document.getElementById('copy-crew-btn').addEventListener('click', () => copyEmails('crew'));

    // Update the summary bar and card appearance when toggles change
    document.getElementById('send-to-boat-owners').addEventListener('change', () => {
        syncCardState('boats');
        updateRecipientSummary();
    });
    document.getElementById('send-to-crew').addEventListener('change', () => {
        syncCardState('crew');
        updateRecipientSummary();
    });

    document.getElementById('send-btn').addEventListener('click', () => {
        const err = validateForm();
        if (err) {
            showToast(err, 'error');
            return;
        }
        showConfirmationModal();
    });

    document.getElementById('cancel-btn').addEventListener('click', () => hideConfirmationModal());
    document.getElementById('confirm-btn').addEventListener('click', async () => {
        hideConfirmationModal();
        await sendNotification();
    });

    const modal = document.getElementById('confirm-modal');
    modal.addEventListener('click', (e) => {
        if (e.target === modal) hideConfirmationModal();
    });
}

/**
 * Load participant emails for the selected event
 */
async function loadParticipants(eventId) {
    const loadBtn    = document.getElementById('load-btn');
    const emptyState = document.getElementById('empty-state');

    try {
        loadBtn.classList.add('loading');
        loadBtn.disabled = true;

        participantData = await adminService.getParticipantEmails(eventId);

        // Populate BCC textareas
        document.getElementById('boat-owner-count').textContent = participantData.boat_owners.count;
        document.getElementById('boat-owner-emails').value = participantData.boat_owners.emails.join(', ');

        document.getElementById('crew-member-count').textContent = participantData.crew_members.count;
        document.getElementById('crew-member-emails').value = participantData.crew_members.emails.join(', ');

        // Reset toggles and card states
        document.getElementById('send-to-boat-owners').checked = true;
        document.getElementById('send-to-crew').checked = true;
        syncCardState('boats');
        syncCardState('crew');

        // Pre-fill default subject and update summary
        document.getElementById('subject').value = `NSC Social Day Cruising \u2013 ${eventId}`;
        updateRecipientSummary();

        // Reveal compose section
        emptyState.style.display = 'none';
        document.getElementById('compose-section').style.display = '';

        showToast('Participants loaded', 'success');
    } catch (error) {
        console.error('Failed to load participants:', error);
        showToast(error.message || 'Failed to load participants', 'error');
    } finally {
        loadBtn.classList.remove('loading');
        loadBtn.disabled = false;
    }
}

/**
 * Dim/undim a recipient card based on its checkbox state
 * @param {'boats'|'crew'} group
 */
function syncCardState(group) {
    const checkboxId = group === 'boats' ? 'send-to-boat-owners' : 'send-to-crew';
    const cardId     = group === 'boats' ? 'card-boats' : 'card-crew';
    const included   = document.getElementById(checkboxId).checked;
    document.getElementById(cardId).classList.toggle('rc--excluded', !included);
}

/**
 * Update the "TO (BCC):" summary line in the compose section
 */
function updateRecipientSummary() {
    const sendToBoats = document.getElementById('send-to-boat-owners').checked;
    const sendToCrew  = document.getElementById('send-to-crew').checked;

    const boatCount = participantData?.boat_owners?.count ?? 0;
    const crewCount = participantData?.crew_members?.count ?? 0;

    const groups = [];
    if (sendToBoats) groups.push(`${boatCount} boat owner${boatCount !== 1 ? 's' : ''}`);
    if (sendToCrew)  groups.push(`${crewCount} crew member${crewCount !== 1 ? 's' : ''}`);

    const el = document.getElementById('compose-to-value');
    if (groups.length === 0) {
        el.textContent = 'No recipients selected';
    } else {
        el.textContent = groups.join(' + ');
    }
}

/**
 * Copy BCC email list to clipboard
 * @param {'boat'|'crew'} group
 */
async function copyEmails(group) {
    const textareaId = group === 'boat' ? 'boat-owner-emails' : 'crew-member-emails';
    const btnId      = group === 'boat' ? 'copy-boat-btn'    : 'copy-crew-btn';
    const text       = document.getElementById(textareaId).value;

    if (!text) {
        showToast('No emails to copy', 'error');
        return;
    }

    try {
        await navigator.clipboard.writeText(text);
        const btn = document.getElementById(btnId);
        const original = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = original; }, 1500);
    } catch {
        showToast('Failed to copy to clipboard', 'error');
    }
}

/**
 * Validate the compose form
 * @returns {string|null} Error message, or null if valid
 */
function validateForm() {
    const subject          = document.getElementById('subject').value.trim();
    const message          = document.getElementById('message').value.trim();
    const sendToBoatOwners = document.getElementById('send-to-boat-owners').checked;
    const sendToCrew       = document.getElementById('send-to-crew').checked;

    if (!subject)                         return 'Subject is required';
    if (!message)                         return 'Message is required';
    if (!sendToBoatOwners && !sendToCrew) return 'At least one recipient group must be selected';
    return null;
}

function showConfirmationModal() {
    const sendToBoatOwners = document.getElementById('send-to-boat-owners').checked;
    const sendToCrew       = document.getElementById('send-to-crew').checked;

    const ownerCount = sendToBoatOwners ? (participantData?.boat_owners?.count ?? 0) : 0;
    const crewCount  = sendToCrew       ? (participantData?.crew_members?.count ?? 0) : 0;
    const total      = ownerCount + crewCount;

    const groups = [];
    if (sendToBoatOwners) groups.push(`${ownerCount} boat owner${ownerCount !== 1 ? 's' : ''}`);
    if (sendToCrew)       groups.push(`${crewCount} crew member${crewCount !== 1 ? 's' : ''}`);

    document.getElementById('confirm-message').textContent =
        `Send to ${groups.join(' + ')} (~${total} participant${total !== 1 ? 's' : ''})?`;

    document.getElementById('confirm-modal').classList.remove('hidden');
}

function hideConfirmationModal() {
    document.getElementById('confirm-modal').classList.add('hidden');
}

/**
 * Send the custom notification
 */
async function sendNotification() {
    const sendBtn          = document.getElementById('send-btn');
    const eventId          = document.getElementById('event-select').value;
    const subject          = document.getElementById('subject').value.trim();
    const message          = document.getElementById('message').value.trim();
    const sendToBoatOwners = document.getElementById('send-to-boat-owners').checked;
    const sendToCrew       = document.getElementById('send-to-crew').checked;

    if (!eventId) return;

    try {
        sendBtn.classList.add('loading');
        sendBtn.disabled = true;

        const result = await adminService.sendCustomNotification(eventId, {
            subject,
            message,
            sendToBoatOwners,
            sendToCrew,
        });

        showToast(result.message || `Sent ${result.emails_sent} notification emails`, 'success');
    } catch (error) {
        console.error('Failed to send notification:', error);
        showToast(error.message || 'Failed to send notification', 'error');
    } finally {
        sendBtn.classList.remove('loading');
        sendBtn.disabled = false;
    }
}
