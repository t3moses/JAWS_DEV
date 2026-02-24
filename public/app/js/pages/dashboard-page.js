/**
 * Dashboard Page Module
 * Handles dashboard page display and event availability management
 */

import { requireAuth, getCurrentUser, signOut } from '../authService.js';
import { updateAuthenticatedNavigation } from '../navigationService.js';
import { getAllEvents, isDeadlinePassed } from '../eventService.js';
import { updateEventAvailability, updateBoatBerths } from '../userService.js';
import { get } from '../apiService.js';
import { API_CONFIG } from '../config.js';
import { showSuccess, showError, showInfo } from '../toastService.js';
import { addAdminLink } from '../navigationService.js';

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
            <span class="profile-label">Experience:</span>
            <span class="profile-value">${formatExperience(user.profile.experience)}</span>
        </div>
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

// Helper function for formatting
function formatExperience(value) {
    const labels = {
        'none': 'None',
        'competent_crew': 'Competent Crew',
        'competent_first_mate': 'Competent First Mate'
    };
    return labels[value] || value;
}

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

        // Filter for guaranteed assignments only (status = 2)
        const guaranteedAssignments = assignments.filter(a => a.availabilityStatus === 2 && a.boatName);

        if (guaranteedAssignments.length === 0) {
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

        // Render each assignment
        guaranteedAssignments.forEach(assignment => {
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

            // Build crewmates HTML
            let crewmatesHTML = '';
            if (assignment.crewmates && assignment.crewmates.length > 0) {
                crewmatesHTML = `
                    <div class="assignment-crew">
                        ${assignment.crewmates
                            .map(c => `<span class="crew-tag">${c.display_name}</span>`)
                            .join('')}
                    </div>
                `;
            }

            card.innerHTML = `
                <div class="assignment-date">${displayDate} • ${timeRange}</div>
                <div class="assignment-boat">⛵ ${assignment.boatName}</div>
                ${crewmatesHTML}
            `;

            container.appendChild(card);
        });
    } catch (error) {
        console.error('Failed to load assignments:', error);
        container.innerHTML = '<div class="alert alert-error">Failed to load assignments. Please refresh the page.</div>';
    }
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
    let hasError = false;
    let hasChanges = false;
    const failedEvents = [];

    const saveButton = this;
    const originalLabel = saveButton.textContent;
    saveButton.disabled = true;
    saveButton.textContent = 'Saving...';

    if (isBoatOwner) {
        // Boat owner path: iterate berths dropdowns
        const selects = document.querySelectorAll('.availability-item select.berths-select');

        for (const select of selects) {
            if (select.disabled) {
                continue;
            }

            const eventDate = select.getAttribute('data-event-date');
            const newBerths = parseInt(select.value, 10);
            // '' means no row in DB yet; treat as always-dirty so saving at max still persists
            const originalRaw = select.dataset.original;
            const originalBerths = originalRaw === '' ? null : parseInt(originalRaw, 10);

            if (originalBerths !== null && newBerths === originalBerths) {
                continue;
            }

            hasChanges = true;

            const result = await updateBoatBerths(user.userId, eventDate, newBerths);

            if (!result.success) {
                showError(result.error || 'Failed to update availability');
                hasError = true;
                failedEvents.push(eventDate);
                if (originalBerths !== null) {
                    select.value = String(originalBerths); // revert on error only if there was a prior value
                }
            } else {
                select.dataset.original = String(newBerths);
                user.eventBerths[eventDate] = newBerths;
                user.eventAvailability[eventDate] = newBerths > 0;
            }
        }
    } else {
        // Crew path: iterate checkboxes (unchanged)
        const checkboxes = document.querySelectorAll('.availability-item input[type="checkbox"]');

        for (const checkbox of checkboxes) {
            if (checkbox.disabled) {
                continue;
            }

            const eventDate = checkbox.getAttribute('data-event-date');
            const isAvailable = checkbox.checked;
            const originalValue = checkbox.dataset.original === 'true';

            if (originalValue === isAvailable) {
                continue;
            }

            hasChanges = true;

            const result = await updateEventAvailability(user.userId, eventDate, isAvailable);

            if (!result.success) {
                showError(result.error || 'Failed to update availability');
                hasError = true;
                failedEvents.push(eventDate);
                checkbox.checked = originalValue; // Revert checkbox on error
            } else {
                checkbox.dataset.original = String(isAvailable);
                user.eventAvailability[eventDate] = isAvailable;
            }
        }
    }

    saveButton.disabled = false;
    saveButton.textContent = originalLabel;

    if (!hasChanges) {
        showInfo('No changes to save.', 2000);
        return;
    }

    if (hasError) {
        if (failedEvents.length > 1) {
            showError('Some availability updates failed. Please try again.');
        }
        return;
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
