/**
 * Admin Service
 * Handles all admin-related API calls
 */

import * as apiService from './apiService.js';
import { API_CONFIG } from './config.js';

/**
 * Get matching data for an event (capacity analysis)
 * @param {string} eventId - Event identifier
 * @returns {Promise<Object>} Matching data with available boats, crews, and capacity summary
 */
export async function getMatchingData(eventId) {
    try {
        const response = await apiService.get(API_CONFIG.ENDPOINTS.ADMIN_MATCHING, { eventId });

        if (!response.success) {
            throw new Error(response.message || 'Failed to load matching data');
        }

        return response.data;
    } catch (error) {
        console.error('AdminService: Failed to get matching data:', error);
        throw error;
    }
}

/**
 * Send email notifications for an event
 * @param {string} eventId - Event identifier
 * @param {boolean} includeCalendar - Whether to include calendar invites
 * @returns {Promise<Object>} Result with count of emails sent
 */
export async function sendNotifications(eventId, includeCalendar = true) {
    try {
        const response = await apiService.post(API_CONFIG.ENDPOINTS.ADMIN_NOTIFICATIONS, {
            include_calendar: includeCalendar
        }, { eventId });

        if (!response.success) {
            throw new Error(response.message || 'Failed to send notifications');
        }

        return response.data;
    } catch (error) {
        console.error('AdminService: Failed to send notifications:', error);
        throw error;
    }
}

/**
 * Get current season configuration
 * @returns {Promise<Object>} Season configuration data
 */
export async function getSeasonConfig() {
    try {
        const response = await apiService.get(API_CONFIG.ENDPOINTS.ADMIN_CONFIG);

        if (!response.success) {
            throw new Error(response.message || 'Failed to load season configuration');
        }

        return response.data;
    } catch (error) {
        console.error('AdminService: Failed to get season config:', error);
        throw error;
    }
}

/**
 * Update season configuration
 * @param {Object} configData - Configuration data to update
 * @returns {Promise<Object>} Updated configuration
 */
export async function updateSeasonConfig(configData) {
    try {
        const response = await apiService.patch(API_CONFIG.ENDPOINTS.ADMIN_CONFIG, configData);

        if (!response.success) {
            throw new Error(response.message || 'Failed to update season configuration');
        }

        return response.data;
    } catch (error) {
        console.error('AdminService: Failed to update season config:', error);
        throw error;
    }
}

/**
 * Manually re-run the season update pipeline (ranking, selection, flotillas)
 * @returns {Promise<Object>} Result with events_processed and flotillas_generated counts
 */
export async function recalculatePipeline() {
    try {
        const response = await apiService.post(API_CONFIG.ENDPOINTS.ADMIN_RECALCULATE, {});

        if (!response.success) {
            throw new Error(response.message || 'Failed to recalculate season pipeline');
        }

        return response.data;
    } catch (error) {
        console.error('AdminService: Failed to recalculate season pipeline:', error);
        throw error;
    }
}

/**
 * Get all registered users
 * @returns {Promise<Object[]>} Array of user summaries
 */
export async function getAllUsers() {
    try {
        const response = await apiService.get(API_CONFIG.ENDPOINTS.ADMIN_USERS);

        if (!response.success) {
            throw new Error(response.message || 'Failed to load users');
        }

        return response.data;
    } catch (error) {
        console.error('AdminService: Failed to get users:', error);
        throw error;
    }
}

/**
 * Grant or revoke admin privileges for a user
 * @param {number} userId - Target user ID
 * @param {boolean} isAdmin - Whether to grant (true) or revoke (false) admin
 * @returns {Promise<Object>} Updated user summary
 */
export async function setUserAdmin(userId, isAdmin) {
    try {
        const response = await apiService.patch(API_CONFIG.ENDPOINTS.ADMIN_USER_ADMIN, { is_admin: isAdmin }, { id: userId });

        if (!response.success) {
            throw new Error(response.message || 'Failed to update admin status');
        }

        return response.data;
    } catch (error) {
        console.error('AdminService: Failed to set user admin:', error);
        throw error;
    }
}

/**
 * Get a single user's detail including linked crew profile
 * @param {number} userId - Target user ID
 * @returns {Promise<{user: Object, crew: Object|null}>}
 */
export async function getUserDetail(userId) {
    try {
        const response = await apiService.get(API_CONFIG.ENDPOINTS.ADMIN_USER_DETAIL, { userId });

        if (!response.success) {
            throw new Error(response.message || 'Failed to load user detail');
        }

        return response.data;
    } catch (error) {
        console.error('AdminService: Failed to get user detail:', error);
        throw error;
    }
}

/**
 * Permanently delete a user account and its linked crew or boat profile
 * @param {number} userId - Target user ID
 * @returns {Promise<Object>} { deleted: true, user_id: number }
 */
export async function deleteUser(userId) {
    try {
        const response = await apiService.deleteRequest(API_CONFIG.ENDPOINTS.ADMIN_USER_DETAIL, { userId });

        if (!response.success) {
            throw new Error(response.message || 'Failed to delete user');
        }

        return response.data;
    } catch (error) {
        console.error('AdminService: Failed to delete user:', error);
        throw error;
    }
}

/**
 * Get all crew members (for partner picker)
 * @returns {Promise<Object[]>} Array of crew summaries
 */
export async function getAllCrews() {
    try {
        const response = await apiService.get(API_CONFIG.ENDPOINTS.ADMIN_CREWS);

        if (!response.success) {
            throw new Error(response.message || 'Failed to load crews');
        }

        return response.data;
    } catch (error) {
        console.error('AdminService: Failed to get crews:', error);
        throw error;
    }
}

/**
 * Get all boats (for whitelist picker)
 * @returns {Promise<Object[]>} Array of boat summaries
 */
export async function getAllBoats() {
    try {
        const response = await apiService.get(API_CONFIG.ENDPOINTS.ADMIN_BOATS);

        if (!response.success) {
            throw new Error(response.message || 'Failed to load boats');
        }

        return response.data;
    } catch (error) {
        console.error('AdminService: Failed to get boats:', error);
        throw error;
    }
}

/**
 * Update crew profile (skill and/or partner)
 * @param {string} crewKey - Crew key
 * @param {Object} data - { skill?: number, partner_key?: string|null }
 * @returns {Promise<Object>} Updated crew summary
 */
export async function updateCrewProfile(crewKey, data) {
    try {
        const response = await apiService.patch(API_CONFIG.ENDPOINTS.ADMIN_CREW_PROFILE, data, { crewKey });

        if (!response.success) {
            throw new Error(response.message || 'Failed to update crew profile');
        }

        return response.data;
    } catch (error) {
        console.error('AdminService: Failed to update crew profile:', error);
        throw error;
    }
}

/**
 * Add a boat to a crew member's whitelist
 * @param {string} crewKey - Crew key
 * @param {string} boatKey - Boat key
 * @returns {Promise<Object>} Updated crew summary
 */
export async function addToCrewWhitelist(crewKey, boatKey) {
    try {
        const response = await apiService.post(API_CONFIG.ENDPOINTS.ADMIN_CREW_WHITELIST_ENTRY, {}, { crewKey, boatKey });

        if (!response.success) {
            throw new Error(response.message || 'Failed to add boat to whitelist');
        }

        return response.data;
    } catch (error) {
        console.error('AdminService: Failed to add to whitelist:', error);
        throw error;
    }
}

/**
 * Set the commitment rank for a crew member (admin override)
 * @param {string} crewKey - Crew key
 * @param {number} commitmentRank - 0=unavailable, 1=penalty, 2=normal, 3=assigned
 * @returns {Promise<Object>} Updated crew summary
 */
export async function setCrewCommitmentRank(crewKey, commitmentRank) {
    try {
        const response = await apiService.patch(API_CONFIG.ENDPOINTS.ADMIN_CREW_COMMITMENT_RANK, { commitment_rank: commitmentRank }, { crewKey });

        if (!response.success) {
            throw new Error(response.message || 'Failed to update commitment rank');
        }

        return response.data;
    } catch (error) {
        console.error('AdminService: Failed to set commitment rank:', error);
        throw error;
    }
}

/**
 * Get participant emails for an event, grouped by role
 * @param {string} eventId - Event identifier
 * @returns {Promise<{event_id: string, boat_owners: {count: number, emails: string[]}, crew_members: {count: number, emails: string[]}}>}
 */
export async function getParticipantEmails(eventId) {
    try {
        const response = await apiService.get(API_CONFIG.ENDPOINTS.ADMIN_PARTICIPANTS, { eventId });

        if (!response.success) {
            throw new Error(response.message || 'Failed to load participant emails');
        }

        return response.data;
    } catch (error) {
        console.error('AdminService: Failed to get participant emails:', error);
        throw error;
    }
}

/**
 * Send a custom admin-composed notification via BCC
 * @param {string} eventId - Event identifier
 * @param {{subject: string, message: string, sendToBoatOwners: boolean, sendToCrew: boolean}} options
 * @returns {Promise<{emails_sent: number, message: string}>}
 */
export async function sendCustomNotification(eventId, { subject, message, sendToBoatOwners, sendToCrew }) {
    try {
        const response = await apiService.post(API_CONFIG.ENDPOINTS.ADMIN_CUSTOM_NOTIFICATION, {
            subject,
            message,
            send_to_boat_owners: sendToBoatOwners,
            send_to_crew: sendToCrew,
        }, { eventId });

        if (!response.success) {
            throw new Error(response.message || 'Failed to send custom notification');
        }

        return response.data;
    } catch (error) {
        console.error('AdminService: Failed to send custom notification:', error);
        throw error;
    }
}

/**
 * Remove a boat from a crew member's whitelist
 * @param {string} crewKey - Crew key
 * @param {string} boatKey - Boat key
 * @returns {Promise<Object>} Updated crew summary
 */
export async function removeFromCrewWhitelist(crewKey, boatKey) {
    try {
        const response = await apiService.deleteRequest(API_CONFIG.ENDPOINTS.ADMIN_CREW_WHITELIST_ENTRY, { crewKey, boatKey });

        if (!response.success) {
            throw new Error(response.message || 'Failed to remove boat from whitelist');
        }

        return response.data;
    } catch (error) {
        console.error('AdminService: Failed to remove from whitelist:', error);
        throw error;
    }
}
