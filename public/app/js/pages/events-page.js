/**
 * Events Page Module
 * Handles events page personalization and dynamic event rendering
 */

import { isSignedIn, getCurrentUser, signOut } from '../authService.js';
import { updateAuthenticatedNavigation, addAdminLink } from '../navigationService.js';
import * as CalendarService from '../calendarService.js';

// Update navigation based on auth state
if (await isSignedIn()) {
    const user = await getCurrentUser();
    updateAuthenticatedNavigation(user, signOut);
    addAdminLink(user);
}

/**
 * Render a single event with its flotilla information
 * @param {Object} event - Event object with flotilla data
 * @returns {DocumentFragment} DOM fragment containing the event HTML
 */
function renderEvent(event) {
    const fragment = document.createDocumentFragment();

    // Create schedule date heading
    const scheduleDateDiv = document.createElement('div');
    scheduleDateDiv.className = 'schedule-date';
    scheduleDateDiv.textContent = event.eventId; // CalendarService.formatDisplayDate(event.date);
    fragment.appendChild(scheduleDateDiv);

    // Render crewed boats
    if (CalendarService.hasCrewedBoats(event)) {
        event.flotilla.crewedBoats.forEach(assignment => {
            const boatDiv = document.createElement('div');
            boatDiv.className = 'boat-assignment';

            // Boat name
            const boatNameDiv = document.createElement('div');
            const boatNameStrong = document.createElement('strong');
            const ownerName = assignment.boat.ownerFirstName ? ` [${assignment.boat.ownerFirstName}]` : '';
            boatNameStrong.textContent = assignment.boat.displayName + ownerName;
            boatNameDiv.appendChild(boatNameStrong);
            boatDiv.appendChild(boatNameDiv);

            // Crew list
            const crewListDiv = document.createElement('div');
            crewListDiv.className = 'crew-list';

            assignment.crews.forEach(crew => {
                const crewTag = document.createElement('span');
                crewTag.className = 'crew-tag';
                crewTag.textContent = crew.displayName;
                crewListDiv.appendChild(crewTag);
            });

            boatDiv.appendChild(crewListDiv);
            fragment.appendChild(boatDiv);
        });
    }

    // Render waitlist section if applicable
    if (CalendarService.hasWaitlist(event)) {
        const waitlistDiv = document.createElement('div');
        waitlistDiv.className = 'waitlist';

        const waitlistHeading = document.createElement('h4');
        waitlistHeading.textContent = 'Waitlist';
        waitlistDiv.appendChild(waitlistHeading);

        const waitlistContent = document.createElement('div');
        waitlistContent.className = 'crew-list';

        // Add waitlisted boats
        const waitlistedBoats = CalendarService.getWaitlistedBoats(event);
        waitlistedBoats.forEach(boat => {
            const boatTag = document.createElement('span');
            boatTag.className = 'crew-tag';
            const ownerName = boat.ownerFirstName ? ` [${boat.ownerFirstName}]` : '';
            boatTag.textContent = boat.displayName + ownerName;
            waitlistContent.appendChild(boatTag);
        });

        // Add waitlisted crews
        const waitlistedCrews = CalendarService.getWaitlistedCrews(event);
        waitlistedCrews.forEach(crew => {
            const crewTag = document.createElement('span');
            crewTag.className = 'crew-tag';
            crewTag.textContent = crew.displayName;
            waitlistContent.appendChild(crewTag);
        });

        waitlistDiv.appendChild(waitlistContent);
        fragment.appendChild(waitlistDiv);
    }

    return fragment;
}

/**
 * Render all events to the events container
 */
async function renderAllEvents() {
    const container = document.getElementById('events-container');

    if (!container) {
        console.error('Events container not found');
        return;
    }

    // Show loading state
    container.innerHTML = '<div class="loading-state">Loading events...</div>';

    try {
        // Fetch events with flotilla data
        const events = await CalendarService.getEventsWithFlotilla();

        // Clear loading state
        container.innerHTML = '';

        // Check if we have events
        if (!events || events.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon">📅</div>
                    <p><strong>No events scheduled</strong></p>
                    <p>Check back later for upcoming sailing events!</p>
                </div>
            `;
            return;
        }

        // Render each event
        events.forEach(event => {
            const eventFragment = renderEvent(event);
            container.appendChild(eventFragment);
        });

    } catch (error) {
        console.error('Failed to load events:', error);
        container.innerHTML = `
            <div class="alert alert-error">
                <strong>Unable to load events</strong>
                <p>There was a problem loading the event schedule. Please try refreshing the page.</p>
                <p style="margin-top: 0.5rem; font-size: 0.9em; opacity: 0.8;">Error: ${error.message}</p>
            </div>
        `;
    }
}

// Initialize events rendering when page loads
renderAllEvents();
