/**
 * User Service Module
 * Handles user profile operations via API
 */

import { patch } from './apiService.js';
import { API_CONFIG } from './config.js';

/**
 * Update user profile
 * @param {string} userId - User ID (ignored - backend uses JWT token from request)
 * @param {Object} updates - Object with fields to update
 * @returns {Promise<Object>} Result object with success status
 */
export async function updateUser(userId, updates) {
    try {
        console.log('Updating user profile via API:', updates);

        // Call PATCH /api/users/me endpoint
        // Backend uses JWT token to identify user, so userId is ignored
        const response = await patch(API_CONFIG.ENDPOINTS.USER_ME, updates);

        if (response?.success === false) {
            console.error('Profile update failed:', response.error);
            return { success: false, error: response.error || 'Failed to update profile' };
        }

        console.log('Profile updated successfully');
        return { success: true, data: response?.data };
    } catch (error) {
        console.error('Error updating profile:', error);
        return { success: false, error: error.message || 'Failed to update profile' };
    }
}

/**
 * Update availability for multiple events in a single request.
 * @param {Array<{eventId: string, isAvailable: boolean, berths?: number}>} availabilities
 * @returns {Promise<{success: boolean, data?: any, error?: string}>}
 */
export async function updateBatchAvailability(availabilities) {
    try {
        const response = await patch(API_CONFIG.ENDPOINTS.USER_AVAILABILITY, { availabilities });

        if (response?.success === false) {
            console.error('Batch availability update failed:', response.error);
            return { success: false, error: response.error || 'Failed to update availability' };
        }

        console.log('Batch availability updated successfully');
        return { success: true, data: response?.data };
    } catch (error) {
        console.error('Error updating batch availability:', error);
        return { success: false, error: error.message || 'Failed to update availability' };
    }
}
