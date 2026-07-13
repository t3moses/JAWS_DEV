/**
 * Authentication Service Module
 * Handles user authentication and session management using API
 * Uses sessionStorage for JWT token storage only (via tokenService)
 */

import * as ApiService from './apiService.js';
import { setToken, clearToken, hasToken } from './tokenService.js';

/**
 * Sign in user via API
 * @param {string} email - User email
 * @param {string} password - User password (plain text)
 * @returns {Promise<Object>} Result object with success status
 */
export async function signIn(email, password) {
    try {
        console.log('Attempting sign in for:', email);

        // Call API login endpoint
        const response = await ApiService.login(email, password);

        if (!response.success) {
            console.error('Sign in failed:', response.error);
            return { success: false, error: response.error || 'Invalid email or password' };
        }

        // Store only the JWT token (no session object, no user caching)
        setToken(response.data.token);

        console.log('Sign in successful - token stored');

        return { success: true };
    } catch (error) {
        console.error('Sign in failed:', error);
        return { success: false, error: 'Sign in failed. Please check your connection.' };
    }
}

/**
 * Sign out current user and redirect to home page
 * @returns {Promise<void>}
 */
export async function signOut() {
    try {
        // Call API to invalidate session on server
        await ApiService.logout();
    } catch (error) {
        console.error('Logout API call failed:', error);
        // Continue with local cleanup even if API fails
    } finally {
        // Always clear token
        clearToken();
        window.location.href = 'index.html';
    }
}

/**
 * Check if user is signed in
 * @returns {boolean} True if JWT token exists
 */
export function isSignedIn() {
    return hasToken();
}

/**
 * Transform availabilities object to eventAvailability object
 * @param {Object} availabilities - Object with event IDs as keys and values
 *                                   For crew: status codes (0=not selected, 1=selected) — an
 *                                   entry's mere presence means the crew is registered/available;
 *                                   row absence (no entry) means withdrawn.
 *                                   For boats: berth counts (0, 1, 2, 3, 4)
 * @param {string} accountType - 'crew' or 'boat_owner'
 * @returns {Object} eventAvailability object { "Fri Jun 12": true, "Fri Jun 19": false, ... }
 */
function transformAvailabilities(availabilities, accountType) {
    if (!availabilities || typeof availabilities !== 'object') {
        return {};
    }

    const eventAvailability = {};
    Object.entries(availabilities).forEach(([eventId, value]) => {
        if (accountType === 'crew') {
            // Presence of an entry means the crew has a crew_availability row (registered/available).
            // The status value (0=not selected, 1=selected) doesn't affect availability itself.
            eventAvailability[eventId] = true;
        } else {
            // For boat owners: Any berth count > 0 means available (true)
            eventAvailability[eventId] = value > 0;
        }
    });

    return eventAvailability;
}

/**
 * Transform profile data from API format to legacy format
 * @param {Object} profileData - crewProfile or boatProfile from API
 * @param {string} accountType - 'crew' or 'boat_owner'
 * @returns {Object} Transformed profile
 */
function transformProfile(profileData, accountType) {
    if (!profileData) {
        console.warn('No profile data available, returning empty profile');
        return {};
    }

    if (accountType === 'crew') {
        return {
            firstName: profileData.firstName || '',
            lastName: profileData.lastName || '',
            membershipNumber: profileData.membershipNumber || '',
            skill: profileData.skill,
            experience: profileData.experience || '',
            whatsappGroup: profileData.socialPreference || false,
            mobile: profileData.mobile || ''
        };
    } else {
        // Boat owner profile - map from backend BoatResponse fields
        return {
            firstName: profileData.ownerFirstName || '',
            lastName: profileData.ownerLastName || '',
            phone: profileData.ownerMobile || '',
            boatName: profileData.displayName || '',
            minCrew: String(profileData.minBerths || 1),
            maxCrew: String(profileData.maxBerths || 4),
            requestFirstMate: profileData.assistanceRequired || false,
            whatsappGroup: profileData.socialPreference || false,
            willingToCrew: profileData.willingToCrew || false
        };
    }
}

/**
 * Transform /users/me API response to legacy user object format
 * @param {Object} apiResponse - Response from /users/me endpoint
 * @returns {Object} Transformed user object
 */
function transformUserMeResponse(apiResponse) {
    const { user, crewProfile, boatProfile } = apiResponse;

    // Determine which profile to use based on account type
    const profileData = user.accountType === 'crew' ? crewProfile : boatProfile;

    if (!profileData) {
        console.warn('No profile data found for user:', user.email);
    }

    // Build transformed user object
    const transformedUser = {
        // Map id to userId (convert integer to string for consistency)
        userId: String(user.id),
        email: user.email,
        accountType: user.accountType,

        // Password not returned by API (security best practice)
        // This field will be null - backend handles password verification
        password: null,

        // Transform profile structure based on account type
        profile: transformProfile(profileData, user.accountType),

        // Transform availabilities array to eventAvailability object
        eventAvailability: transformAvailabilities(profileData?.availabilities, user.accountType),

        // Raw integer berths per event for boat owners (e.g. { "Fri Jun 12": 3 })
        eventBerths: user.accountType !== 'crew' ? (profileData?.availabilities || {}) : {},

        // Map timestamp field names
        createdAt: user.createdAt,
        lastSignIn: user.lastLogin,

        // Additional fields from API
        isAdmin: user.isAdmin
    };

    return transformedUser;
}

/**
 * Get current user data from API
 * Always fetches fresh data from server (no caching)
 * @returns {Promise<Object|null>} User object or null
 */
export async function getCurrentUser() {
    if (!hasToken()) return null;

    try {
        console.log('Fetching user from API /users/me...');
        const apiResponse = await ApiService.getUserMe();

        if (!apiResponse) {
            console.error('No user data returned from API');
            return null;
        }

        // Transform API response to legacy format
        const user = transformUserMeResponse(apiResponse);

        console.log('User fetched successfully:', user.email);
        return user;
    } catch (error) {
        console.error('Failed to get current user:', error);
        return null;
    }
}

/**
 * Require authentication - redirect to sign in page if not authenticated
 * @returns {boolean} True if user is authenticated
 */
export function requireAuth() {
    const signedIn = isSignedIn();
    if (!signedIn) {
        window.location.href = 'signin.html';
        return false;
    }
    return true;
}

/**
 * Register new user via API
 * @param {Object} userData - User registration data
 * @returns {Promise<Object>} Result object with success status
 */
export async function register(userData) {
    try {
        console.log('Registering user:', userData.email);

        // Call API register endpoint
        const response = await ApiService.register(userData);

        if (!response.success) {
            console.error('Registration failed:', response.error);
            return { success: false, error: response.error || 'Registration failed' };
        }

        // Auto-login: Store only the JWT token
        setToken(response.data.token);

        console.log('User registered and signed in successfully');

        return { success: true, userId: response.data.user.id };
    } catch (error) {
        console.error('Registration failed:', error);
        return { success: false, error: 'Registration failed. Please check your connection.' };
    }
}
