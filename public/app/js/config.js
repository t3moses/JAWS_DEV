/**
 * API Configuration
 * Application uses API backend only (localStorage removed)
 */

export const API_CONFIG = {
    BASE_URL: '/api',
    TIMEOUT: 10000, // 10 seconds
    ENDPOINTS: {
        // Auth endpoints
        AUTH_LOGIN: '/auth/login',
        AUTH_REGISTER: '/auth/register',
        AUTH_LOGOUT: '/auth/logout',
        AUTH_SESSION: '/auth/session',

        // User endpoints
        USERS: '/users',
        USER_ME: '/users/me',
        USER_BY_ID: '/users/:id',
        USER_BY_EMAIL: '/users/email/:email',
        USER_AVAILABILITY: '/users/me/availability',
        ASSIGNMENTS: '/assignments',

        // Event endpoints
        EVENTS: '/events',
        EVENT_BY_ID: '/events/:id',
        FLOTILLAS: '/flotillas',

        // Admin endpoints
        ADMIN_MATCHING: '/admin/matching/:eventId',
        ADMIN_NOTIFICATIONS: '/admin/notifications/:eventId',
        ADMIN_PARTICIPANTS: '/admin/participants/:eventId',
        ADMIN_CUSTOM_NOTIFICATION: '/admin/notifications/:eventId/custom',
        ADMIN_CONFIG: '/admin/config',
        ADMIN_USERS: '/admin/users',
        ADMIN_USER_ADMIN: '/admin/users/:id/admin',
        ADMIN_USER_DETAIL: '/admin/users/:userId',
        ADMIN_CREWS: '/admin/crews',
        ADMIN_BOATS: '/admin/boats',
        ADMIN_CREW_PROFILE: '/admin/crews/:crewKey',
        ADMIN_CREW_WHITELIST_ENTRY: '/admin/crews/:crewKey/whitelist/:boatKey',
        ADMIN_CREW_COMMITMENT_RANK: '/admin/crews/:crewKey/commitment-rank'
    }
};

/**
 * Get current environment name
 * @returns {string}
 */
export function getCurrentEnvironment() {
    return 'development';
}

/**
 * Build full API URL
 * @param {string} endpoint - Endpoint path
 * @param {Object} params - URL parameters to replace (e.g., {id: '123'})
 * @returns {string}
 */
export function buildApiUrl(endpoint, params = {}) {
    let url = API_CONFIG.BASE_URL + endpoint;

    // Replace URL parameters
    Object.keys(params).forEach(key => {
        url = url.replace(`:${key}`, encodeURIComponent(params[key]));
    });

    return url;
}
