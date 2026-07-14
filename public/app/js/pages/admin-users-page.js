/**
 * Admin Users Page
 * List all registered users with a link to the per-user edit page
 */

import { requireAuth, getCurrentUser, signOut } from '../authService.js';
import { updateAuthenticatedNavigation, addAdminLink } from '../navigationService.js';
import { initHamburgerMenu } from '../hamburger.js';
import * as adminService from '../adminService.js';

let currentUser = null;

// Initialize page
document.addEventListener('DOMContentLoaded', async () => {
    initHamburgerMenu();

    requireAuth();

    currentUser = await getCurrentUser();
    if (!currentUser) {
        window.location.href = 'signin.html';
        return;
    }

    if (!currentUser.isAdmin) {
        console.warn('Access denied: User is not an admin');
        window.location.href = 'dashboard.html';
        return;
    }

    updateAuthenticatedNavigation(currentUser, signOut);
    addAdminLink(currentUser);

    await loadUsers();
});

/**
 * Load all users and render the table
 */
async function loadUsers() {
    const container = document.getElementById('users-container');

    try {
        const users = await adminService.getAllUsers();
        renderUsersTable(container, users);
    } catch (error) {
        console.error('Failed to load users:', error);
        container.innerHTML = '<p class="error-message">Failed to load users. Please try again.</p>';
    }
}

/**
 * Render the users table
 * @param {HTMLElement} container
 * @param {Object[]} users
 */
function renderUsersTable(container, users) {
    if (users.length === 0) {
        container.innerHTML = '<p>No registered users found.</p>';
        return;
    }

    const rows = users.map(user => renderUserRow(user)).join('');

    container.innerHTML = `
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Account Type</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                ${rows}
            </tbody>
        </table>
    `;
}

/**
 * Render a single user table row
 * @param {Object} user
 * @returns {string} HTML string
 */
function renderUserRow(user) {
    const isBoatOwner = user.account_type === 'boat_owner';
    const accountBadge = isBoatOwner
        ? '<span class="user-badge boat-owner">⛵ Boat Owner</span>'
        : '<span class="user-badge">🌊 Crew Member</span>';
    const adminBadge = user.is_admin ? ' <span class="admin-badge">Admin</span>' : '';
    const displayName = user.display_name || user.email;

    return `
        <tr>
            <td>${escapeHtml(displayName)}${adminBadge}</td>
            <td>${accountBadge}</td>
            <td><a href="admin-user-edit.html?userId=${user.id}" class="btn btn-sm btn-secondary">Edit</a></td>
        </tr>
    `;
}

/**
 * Escape HTML special characters
 * @param {string} str
 * @returns {string}
 */
function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
