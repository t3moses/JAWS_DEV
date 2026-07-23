/**
 * Dashboard Page Module
 * Handles dashboard page display and event availability management
 */

import { requireAuth, getCurrentUser, signOut } from '../authService.js';
import { updateAuthenticatedNavigation, addAdminLink } from '../navigationService.js';
import { getAllEvents, isDeadlinePassed } from '../eventService.js';
import { updateBatchAvailability, flagAssignedCrew } from '../userService.js';
import { get } from '../apiService.js';
import { API_CONFIG } from '../config.js';
import { showSuccess, showError, showInfo } from '../toastService.js';

// Make signOut available globally for onclick handlers
window.signOut = signOut;

// Require authentication
if (!requireAuth()) {
    // requireAuth redirects to signin.html if not authenticated
}

// Get current user
const user = await getCurrentUser();
if (!user) {
    console.error('No user found, redirecting to sign in');
    alert('Session error. Please sign in again.');
    window.location.href = 'signin.html';
    throw new Error('No user found'); // Stop execution
}

console.log('User loaded successfully:', user.email);

// Update navigation with user's name and attach sign-out handler
updateAuthenticatedNavigation(user, signOut);

// Populate username in hero
document.getElementById('hero-username').textContent = user.profile.firstName;

// Add admin link if user is admin
addAdminLink(user);

// Populate account badge
const badge = document.getElementById('account-badge');
if (user.accountType === 'crew') {
    badge.textContent = '🌊 Crew Member';
    badge.classList.add('crew-member');
} else {
    badge.textContent = '⛵ Boat Owner';
    badge.classList.add('boat-owner');
}

// Populate profile details
const profileDetails = document.getElementById('profile-details');
let profileHTML = '';
console.log(user);
if (user.accountType === 'crew') {
    profileHTML = `
        <div class="profile-item">
            <span class="profile-label">Name:</span>
            <span class="profile-value">${user.profile.firstName} ${user.profile.lastName}</span>
        </div>
        <div class="profile-item">
            <span class="profile-label">Email:</span>
            <span class="profile-value">${user.email}</span>
        </div>
        ${user.membershipNumber ? `
        <div class="profile-item">
            <span class="profile-label">Membership Number:</span>
            <span class="profile-value">${user.profile.membershipNumber}</span>
        </div>
        ` : ''}
        <div class="profile-item">
            <span class="profile-label">WhatsApp Group:</span>
            <span class="profile-value">${user.profile.whatsappGroup ? 'Yes, enrolled' : 'Not enrolled'}</span>
        </div>
        ${user.profile.mobile ? `
        <div class="profile-item">
            <span class="profile-label">Mobile:</span>
            <span class="profile-value">${user.profile.mobile}</span>
        </div>
        ` : ''}
    `;
} else {
    profileHTML = `
        <div class="profile-item">
            <span class="profile-label">Name:</span>
            <span class="profile-value">${user.profile.firstName} ${user.profile.lastName}</span>
        </div>
        <div class="profile-item">
            <span class="profile-label">Email:</span>
            <span class="profile-value">${user.email}</span>
        </div>
        <div class="profile-item">
            <span class="profile-label">Phone:</span>
            <span class="profile-value">${user.profile.phone}</span>
        </div>
        <div class="profile-item">
            <span class="profile-label">Boat Name:</span>
            <span class="profile-value">${user.profile.boatName}</span>
        </div>
        <div class="profile-item">
            <span class="profile-label">Crew Capacity:</span>
            <span class="profile-value">${user.profile.minCrew || 1} - ${user.profile.maxCrew} crew members</span>
        </div>
        <div class="profile-item">
            <span class="profile-label">First Mate Requested:</span>
            <span class="profile-value">${user.profile.requestFirstMate ? 'Yes' : 'No'}</span>
        </div>
        <div class="profile-item">
            <span class="profile-label">WhatsApp Group:</span>
            <span class="profile-value">${user.profile.whatsappGroup ? 'Yes, enrolled' : 'Not enrolled'}</span>
        </div>
    `;
}

profileDetails.innerHTML = profileHTML;

/**
 * Populate user's boat assignments
 */
async function populateAssignments() {
    const container = document.getElementById('assignments-container');

    // Show loading state
    container.innerHTML = '<div class="loading-state" style="text-align: center; padding: 2rem; color: var(--text-gray);">Loading assignments...</div>';

    try {
        // Fetch assignments from API
        const response = await get(API_CONFIG.ENDPOINTS.ASSIGNMENTS);
        const assignments = response.data?.assignments || [];

        // Filter for assignments where a boat has actually been matched
        const boatAssignments = assignments.filter(a => a.boatName);

        if (boatAssignments.length === 0) {
            // Show empty state
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon">⛵</div>
                    <p><strong>No assignments yet</strong></p>
                    <p>Mark your availability above to get matched with a boat and crew!</p>
                </div>
            `;
            return;
        }

        // Clear container
        container.innerHTML = '';

        const isBoatOwner = user.accountType !== 'crew';
        let hasFlaggableAssignment = false;

        // Render each assignment
        boatAssignments.forEach(assignment => {
            const card = document.createElement('div');
            card.className = 'assignment-card';

            // Format date for display (parse as local date to avoid timezone issues)
            const [year, month, day] = assignment.eventDate.split('-').map(Number);
            const date = new Date(year, month - 1, day); // month is 0-indexed
            const displayDate = date.toLocaleDateString('en-US', {
                weekday: 'short',
                month: 'short',
                day: 'numeric'
            });

            // Format time range
            const timeRange = `${formatTime(assignment.startTime)} - ${formatTime(assignment.finishTime)}`;

            // Build crewmates HTML. For boat owners, crew names for past events are
            // togglable flag buttons (see handleSaveCrewFlags) so commitment rank can
            // only be decremented once an assignment is final; the next/future event
            // isn't flaggable yet, and crew members viewing their own crewmates always
            // see a plain tag.
            const eventHasPassed = hasEventOccurred(assignment.eventDate, assignment.finishTime);
            const canFlag = isBoatOwner && eventHasPassed;
            if (canFlag) {
                hasFlaggableAssignment = true;
            }

            let crewmatesHTML = '';
            if (assignment.crewmates && assignment.crewmates.length > 0) {
                const tags = assignment.crewmates.map(c => (
                    canFlag
                        ? `<button type="button" class="crew-tag crew-tag-btn"
                                   data-event-id="${assignment.eventId}"
                                   data-crew-key="${c.key}">${c.display_name}</button>`
                        : `<span class="crew-tag">${c.display_name}</span>`
                )).join('');
                crewmatesHTML = `<div class="assignment-crew">${tags}</div>`;
            }

            card.innerHTML = `
                <div class="assignment-date">${displayDate} • ${timeRange}</div>
                <div class="assignment-boat">⛵ ${assignment.boatName}</div>
                ${crewmatesHTML}
            `;

            container.appendChild(card);
        });

        // Toggle a crew flag button between turquoise (unflagged) and orange (flagged)
        container.querySelectorAll('.crew-tag-btn').forEach(btn => {
            btn.addEventListener('click', () => btn.classList.toggle('flagged'));
        });

        // Boat owners get a Save Changes button to submit flagged crew, but only
        // when at least one past-event assignment is actually flaggable
        if (hasFlaggableAssignment) {
            const saveWrapper = document.createElement('div');
            saveWrapper.style.textAlign = 'center';
            saveWrapper.style.marginTop = '2rem';
            saveWrapper.innerHTML = '<button id="save-crew-flags" class="btn btn-primary">Save Changes</button>';
            container.appendChild(saveWrapper);

            document.getElementById('save-crew-flags').addEventListener('click', handleSaveCrewFlags);
        }
    } catch (error) {
        console.error('Failed to load assignments:', error);
        container.innerHTML = '<div class="alert alert-error">Failed to load assignments. Please refresh the page.</div>';
    }
}

/**
 * Handle the "My Boat Assignments" Save Changes click: collect every crew name
 * button currently flagged orange, list flagged crews with how many times each
 * was flagged, and submit those flags so their commitment rank is decremented.
 */
async function handleSaveCrewFlags() {
    const saveButton = document.getElementById('save-crew-flags');
    const originalLabel = saveButton.textContent;

    const flaggedButtons = Array.from(
        document.querySelectorAll('#assignments-container .crew-tag-btn.flagged')
    );

    if (flaggedButtons.length === 0) {
        showInfo('No changes to save.', 2000);
        return;
    }

    const flags = flaggedButtons.map(btn => ({
        eventId: btn.dataset.eventId,
        crewKey: btn.dataset.crewKey
    }));

    saveButton.disabled = true;
    saveButton.textContent = 'Saving...';

    const result = await flagAssignedCrew(flags);

    saveButton.disabled = false;
    saveButton.textContent = originalLabel;

    if (!result.success) {
        showError(result.error || 'Failed to save flagged crew');
        return;
    }

    const flagged = result.data?.flagged || [];
    if (flagged.length === 0) {
        showInfo('No changes to save.', 2000);
        return;
    }

    const summary = flagged
        .map(f => `${f.display_name || f.crew_key} (×${f.flag_count})`)
        .join(', ');
    showSuccess(`Flagged: ${summary}. Commitment rank updated.`);

    // Clear flags now that they've been applied, so re-clicking Save without
    // re-flagging anything doesn't decrement the same crew again.
    flaggedButtons.forEach(btn => btn.classList.remove('flagged'));
}

/**
 * Check whether an event has already finished (mirrors the server's
 * EventRepository::findPastEvents definition: event_date/finish_time < now).
 */
function hasEventOccurred(eventDate, finishTime) {
    return new Date() > new Date(`${eventDate}T${finishTime}`);
}

/**
 * Format time from HH:MM:SS to H:MM AM/PM
 */
function formatTime(timeString) {
    const [hours, minutes] = timeString.split(':').map(Number);
    const period = hours >= 12 ? 'PM' : 'AM';
    const displayHours = hours % 12 || 12;
    return `${displayHours}:${minutes.toString().padStart(2, '0')} ${period}`;
}

// Populate event availability controls (dropdown for boat owners, checkbox for crew)
async function populateEventAvailability() {
    const availabilityList = document.getElementById('availability-list');

    try {
        // Check blackout window using server time before rendering controls
        const statusResponse = await get(API_CONFIG.ENDPOINTS.STATUS);
        const isBlackout = statusResponse?.data?.isBlackout === true;

        if (isBlackout) {
            availabilityList.innerHTML = `
                <div class="alert alert-info">
                    <strong>Registration is currently closed.</strong><br>
                    Availability cannot be changed during the event (10:00 AM – 6:00 PM).
                    Please come back after the event ends.
                </div>
            `;
            document.getElementById('save-availability').style.display = 'none';
            return;
        }

        const events = await getAllEvents();
        const isBoatOwner = user.accountType !== 'crew';

        events.forEach(event => {
            const deadlinePassed = isDeadlinePassed(event.date);
            const itemDiv = document.createElement('div');
            itemDiv.className = 'availability-item' + (deadlinePassed ? ' disabled' : '');

            if (isBoatOwner) {
                const maxBerths = parseInt(user.profile.maxCrew, 10) || 0;
                // persistedBerths is undefined when no DB row exists yet
                const persistedBerths = user.eventBerths[event.eventId];
                const displayBerths = persistedBerths ?? maxBerths;

                // Build options 0..maxBerths
                let options = `<option value="0"${displayBerths === 0 ? ' selected' : ''}>Not available</option>`;
                for (let i = 1; i <= maxBerths; i++) {
                    options += `<option value="${i}"${displayBerths === i ? ' selected' : ''}>${i} berth${i !== 1 ? 's' : ''}</option>`;
                }

                // data-original is '' when no row exists so any save triggers a write
                itemDiv.innerHTML = `
                    <select class="berths-select"
                            data-event-date="${event.eventId}"
                            data-original="${persistedBerths ?? ''}"
                            ${deadlinePassed ? 'disabled' : ''}>
                        ${options}
                    </select>
                    <label class="availability-date">${event.displayDate || event.eventId}</label>
                    ${deadlinePassed ? '<span class="deadline-warning">Deadline Passed</span>' : ''}
                `;
            } else {
                const isAvailable = user.eventAvailability[event.eventId] || false;

                itemDiv.innerHTML = `
                    <input type="checkbox"
                           id="event-${event.eventId}"
                           data-event-date="${event.eventId}"
                           data-original="${isAvailable}"
                           ${isAvailable ? 'checked' : ''}
                           ${deadlinePassed ? 'disabled' : ''}>
                    <label for="event-${event.eventId}" class="availability-date">
                        ${event.displayDate || event.eventId}
                    </label>
                    ${deadlinePassed ? '<span class="deadline-warning">Deadline Passed</span>' : ''}
                `;
            }

            availabilityList.appendChild(itemDiv);
        });
    } catch (error) {
        console.error('Failed to load events:', error);
        availabilityList.innerHTML = '<div class="alert alert-error">Failed to load events. Please refresh the page.</div>';
    }
}

// Call the async function
populateEventAvailability();

// Load user's boat assignments
populateAssignments();

// Handle save availability button
document.getElementById('save-availability').addEventListener('click', async function() {
    const isBoatOwner = user.accountType !== 'crew';
    const saveButton = this;
    const originalLabel = saveButton.textContent;
    saveButton.disabled = true;
    saveButton.textContent = 'Saving...';

    // --- Collect changes ---
    const pendingChanges = [];

    if (isBoatOwner) {
        for (const select of document.querySelectorAll('.availability-item select.berths-select')) {
            if (select.disabled) continue;
            const eventDate = select.getAttribute('data-event-date');
            const newBerths = parseInt(select.value, 10);
            // '' means no row in DB yet; treat as always-dirty so saving at max still persists
            const originalRaw = select.dataset.original;
            const originalBerths = originalRaw === '' ? null : parseInt(originalRaw, 10);
            if (originalBerths !== null && newBerths === originalBerths) continue;
            pendingChanges.push({
                element: select,
                type: 'boat',
                eventDate,
                newValue: newBerths,
                originalValue: originalBerths,
                payload: { eventId: eventDate, isAvailable: newBerths > 0, berths: newBerths }
            });
        }
    } else {
        for (const checkbox of document.querySelectorAll('.availability-item input[type="checkbox"]')) {
            if (checkbox.disabled) continue;
            const eventDate = checkbox.getAttribute('data-event-date');
            const isAvailable = checkbox.checked;
            const originalValue = checkbox.dataset.original === 'true';
            if (originalValue === isAvailable) continue;
            pendingChanges.push({
                element: checkbox,
                type: 'crew',
                eventDate,
                newValue: isAvailable,
                originalValue,
                payload: { eventId: eventDate, isAvailable }
            });
        }
    }

    saveButton.disabled = false;
    saveButton.textContent = originalLabel;

    if (pendingChanges.length === 0) {
        showInfo('No changes to save.', 2000);
        return;
    }

    // --- Send one batched request ---
    const result = await updateBatchAvailability(pendingChanges.map(c => c.payload));

    if (!result.success) {
        // Revert all DOM elements
        for (const change of pendingChanges) {
            if (change.type === 'boat') {
                if (change.originalValue !== null) change.element.value = String(change.originalValue);
            } else {
                change.element.checked = change.originalValue;
            }
        }
        showError(result.error || 'Failed to update availability');
        return;
    }

    // Commit all changes to local state
    for (const change of pendingChanges) {
        if (change.type === 'boat') {
            change.element.dataset.original = String(change.newValue);
            user.eventBerths[change.eventDate] = change.newValue;
            user.eventAvailability[change.eventDate] = change.newValue > 0;
        } else {
            change.element.dataset.original = String(change.newValue);
            user.eventAvailability[change.eventDate] = change.newValue;
        }
    }

    showSuccess('Availability updated successfully! Your assignments have been refreshed.');

    // Reload assignments in case they changed
    await populateAssignments();

    // Smoothly scroll to assignments section so user can see updates
    setTimeout(() => {
        const assignmentsSection = document.getElementById('assignments-container');
        if (assignmentsSection) {
            assignmentsSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }, 300); // Small delay to let assignments load
});
