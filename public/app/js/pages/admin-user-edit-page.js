/**
 * Admin User Edit Page
 * Manage a single user's crew profile: skill, partner, and whitelist
 */

import { requireAuth, getCurrentUser, signOut } from '../authService.js';
import { updateAuthenticatedNavigation, addAdminLink } from '../navigationService.js';
import { initHamburgerMenu } from '../hamburger.js';
import * as adminService from '../adminService.js';
import { showToast } from '../toast.js';

let currentUser = null;
let targetUserId = null;
let targetUserData = null;   // { user, crew }
let allCrews = [];
let allBoats = [];

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

    // Extract userId from query string
    const params = new URLSearchParams(window.location.search);
    targetUserId = parseInt(params.get('userId'), 10);
    if (!targetUserId || isNaN(targetUserId)) {
        showPageError('No user ID provided. Please go back to the user list.');
        return;
    }

    await loadPage();
});

/**
 * Load all page data in parallel and render sections
 */
async function loadPage() {
    try {
        const [userDetail, crews, boats] = await Promise.all([
            adminService.getUserDetail(targetUserId),
            adminService.getAllCrews(),
            adminService.getAllBoats(),
        ]);

        targetUserData = userDetail;
        allCrews = crews;
        allBoats = boats;

        hideLoading();
        renderPage();
    } catch (error) {
        console.error('Failed to load page data:', error);
        showPageError(error.message || 'Failed to load user details.');
    }
}

function hideLoading() {
    document.getElementById('page-loading').style.display = 'none';
}

function showPageError(message) {
    document.getElementById('page-loading').style.display = 'none';
    const errorDiv = document.getElementById('page-error');
    document.getElementById('page-error-message').textContent = message;
    errorDiv.style.display = '';
}

/**
 * Render all page sections based on the loaded data
 */
function renderPage() {
    const { user, crew } = targetUserData;

    // Hero subtitle
    document.getElementById('hero-subtitle').textContent =
        `Editing: ${escapeHtml(user.email)}`;

    renderUserInfo(user);
    renderAdminRights(user);
    renderAccountStatus(user);

    if (crew) {
        renderSkill(crew);
        renderCommitment(crew);
        renderPartner(crew);
        renderWhitelist(crew);
    }
}

// ==================== User Info ====================

function renderUserInfo(user) {
    document.getElementById('info-email').textContent = user.email;
    const isBoatOwner = user.account_type === 'boat_owner';
    document.getElementById('info-account-type').innerHTML = isBoatOwner
        ? '<span class="user-badge boat-owner">⛵ Boat Owner</span>'
        : '<span class="user-badge">🌊 Crew Member</span>';
    document.getElementById('info-created-at').textContent =
        new Date(user.created_at).toLocaleDateString();

    document.getElementById('section-user-info').style.display = '';
}

// ==================== Admin Rights ====================

function renderAdminRights(user) {
    const section = document.getElementById('section-admin-rights');
    const toggle = document.getElementById('admin-toggle');
    const saveBtn = document.getElementById('admin-save-btn');

    toggle.checked = user.is_admin;

    // Disable if editing self
    if (user.id === currentUser.id) {
        toggle.disabled = true;
        saveBtn.disabled = true;
        saveBtn.title = 'You cannot change your own admin status';
    } else {
        saveBtn.addEventListener('click', () => handleAdminSave(toggle));
    }

    section.style.display = '';
}

async function handleAdminSave(toggle) {
    const btn = document.getElementById('admin-save-btn');
    btn.disabled = true;

    try {
        await adminService.setUserAdmin(targetUserId, toggle.checked);
        // Refresh user data
        const detail = await adminService.getUserDetail(targetUserId);
        targetUserData = detail;
        toggle.checked = detail.user.is_admin;
        showToast('Admin status updated. Changes take effect on next login.', 'success');
    } catch (error) {
        console.error('Failed to update admin status:', error);
        showToast(error.message || 'Failed to update admin status', 'error');
        // Revert toggle
        toggle.checked = targetUserData.user.is_admin;
    } finally {
        btn.disabled = false;
    }
}

// ==================== Account Status ====================

function renderAccountStatus(user) {
    const section = document.getElementById('section-account-status');
    const btn = document.getElementById('status-toggle-btn');

    updateAccountStatusView(user);

    // Cannot deactivate your own account
    if (user.id === currentUser.id) {
        btn.disabled = true;
        btn.title = 'You cannot deactivate your own account';
    } else {
        btn.addEventListener('click', handleStatusToggle);
    }

    section.style.display = '';
}

/**
 * Reflect the current disabled state in the status label and toggle button
 */
function updateAccountStatusView(user) {
    const label = document.getElementById('status-current');
    const btn = document.getElementById('status-toggle-btn');

    if (user.disabled) {
        const when = user.disabled_at
            ? ` (since ${new Date(user.disabled_at).toLocaleDateString()})`
            : '';
        label.textContent = `Deactivated${when}`;
        btn.textContent = 'Reactivate Account';
        btn.classList.remove('btn-danger');
        btn.classList.add('btn-primary');
    } else {
        label.textContent = 'Active';
        btn.textContent = 'Deactivate Account';
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-danger');
    }
}

async function handleStatusToggle() {
    const btn = document.getElementById('status-toggle-btn');
    const currentlyDisabled = targetUserData.user.disabled === true;
    const willDisable = !currentlyDisabled;

    // Confirm the heavier (deactivation) action; reactivation is low-risk
    if (willDisable && !window.confirm(
        'Deactivate this account? The user will be signed out and blocked from ' +
        'signing in, and their boat/crew will be removed from upcoming flotillas. ' +
        'You can reactivate the account later.'
    )) {
        return;
    }

    btn.disabled = true;

    try {
        await adminService.setUserStatus(targetUserId, willDisable);
        // Refresh user data so the label and button reflect the new state
        const detail = await adminService.getUserDetail(targetUserId);
        targetUserData = detail;
        updateAccountStatusView(detail.user);
        showToast(
            willDisable ? 'Account deactivated.' : 'Account reactivated.',
            'success'
        );
    } catch (error) {
        console.error('Failed to update account status:', error);
        showToast(error.message || 'Failed to update account status', 'error');
    } finally {
        btn.disabled = false;
    }
}

// ==================== Skill ====================

function renderSkill(crew) {
    const section = document.getElementById('section-skill');
    const select = document.getElementById('skill-select');
    const saveBtn = document.getElementById('skill-save-btn');

    select.value = String(crew.skill);

    saveBtn.addEventListener('click', () => handleSkillSave(select));

    section.style.display = '';
}

async function handleSkillSave(select) {
    const btn = document.getElementById('skill-save-btn');
    btn.disabled = true;

    try {
        const updated = await adminService.updateCrewProfile(targetUserData.crew.key, {
            skill: parseInt(select.value, 10),
        });
        targetUserData.crew = updated;
        select.value = String(updated.skill);
        showToast('Skill level updated successfully.', 'success');
    } catch (error) {
        console.error('Failed to update skill:', error);
        showToast(error.message || 'Failed to update skill', 'error');
        select.value = String(targetUserData.crew.skill);
    } finally {
        btn.disabled = false;
    }
}

// ==================== Commitment Rank ====================

function renderCommitment(crew) {
    const section = document.getElementById('section-commitment');
    const select = document.getElementById('commitment-select');
    const saveBtn = document.getElementById('commitment-save-btn');

    // Only allow setting penalty (1) or restoring normal (2)
    const rank = crew.rank_commitment ?? 2;
    select.value = String(rank === 1 ? 1 : 2);

    saveBtn.addEventListener('click', () => handleCommitmentSave(select));

    section.style.display = '';
}

async function handleCommitmentSave(select) {
    const btn = document.getElementById('commitment-save-btn');
    btn.disabled = true;

    try {
        const result = await adminService.setCrewCommitmentRank(targetUserData.crew.key, parseInt(select.value, 10));
        targetUserData.crew.rank_commitment = result.rank_commitment;
        select.value = String(result.rank_commitment === 1 ? 1 : 2);
        showToast('Commitment rank updated successfully.', 'success');
    } catch (error) {
        console.error('Failed to update commitment rank:', error);
        showToast(error.message || 'Failed to update commitment rank', 'error');
        const rank = targetUserData.crew.rank_commitment ?? 2;
        select.value = String(rank === 1 ? 1 : 2);
    } finally {
        btn.disabled = false;
    }
}

// ==================== Partner ====================

function renderPartner(crew) {
    const section = document.getElementById('section-partner');
    const select = document.getElementById('partner-select');
    const saveBtn = document.getElementById('partner-save-btn');

    // Populate options with all crew except this crew member
    const otherCrews = allCrews.filter(c => c.key !== crew.key);
    otherCrews.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.key;
        opt.textContent = `${escapeHtml(c.first_name)} ${escapeHtml(c.last_name)}`;
        select.appendChild(opt);
    });

    select.value = crew.partner_key || '';

    saveBtn.addEventListener('click', () => handlePartnerSave(select));

    section.style.display = '';
}

async function handlePartnerSave(select) {
    const btn = document.getElementById('partner-save-btn');
    btn.disabled = true;

    try {
        const partnerKey = select.value || null;
        const updated = await adminService.updateCrewProfile(targetUserData.crew.key, {
            partner_key: partnerKey,
        });
        targetUserData.crew = updated;
        select.value = updated.partner_key || '';
        showToast('Partner updated successfully.', 'success');
    } catch (error) {
        console.error('Failed to update partner:', error);
        showToast(error.message || 'Failed to update partner', 'error');
        select.value = targetUserData.crew.partner_key || '';
    } finally {
        btn.disabled = false;
    }
}

// ==================== Whitelist ====================

function renderWhitelist(crew) {
    document.getElementById('section-whitelist').style.display = '';
    refreshWhitelistTable(crew);
    refreshWhitelistAddDropdown(crew);

    document.getElementById('whitelist-add-btn').addEventListener('click', handleWhitelistAdd);
}

function refreshWhitelistTable(crew) {
    const container = document.getElementById('whitelist-table-container');
    const whitelist = crew.whitelist || [];

    if (whitelist.length === 0) {
        container.innerHTML = '<p class="text-muted">No boats whitelisted.</p>';
        return;
    }

    const rows = whitelist.map(boatKey => {
        const boat = allBoats.find(b => b.key === boatKey);
        const boatName = boat ? escapeHtml(boat.display_name) : escapeHtml(boatKey);
        return `
            <tr>
                <td>${boatName}</td>
                <td>
                    <button class="btn btn-sm btn-danger whitelist-remove-btn" data-boat-key="${escapeHtml(boatKey)}">
                        Remove
                    </button>
                </td>
            </tr>
        `;
    }).join('');

    container.innerHTML = `
        <table class="data-table">
            <thead>
                <tr><th>Boat</th><th>Action</th></tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>
    `;

    container.querySelectorAll('.whitelist-remove-btn').forEach(btn => {
        btn.addEventListener('click', () => handleWhitelistRemove(btn.dataset.boatKey));
    });
}

function refreshWhitelistAddDropdown(crew) {
    const select = document.getElementById('whitelist-add-select');
    const whitelist = crew.whitelist || [];

    // Remove all options except the placeholder
    while (select.options.length > 1) {
        select.remove(1);
    }

    const available = allBoats.filter(b => !whitelist.includes(b.key));
    available.forEach(boat => {
        const opt = document.createElement('option');
        opt.value = boat.key;
        opt.textContent = boat.display_name;
        select.appendChild(opt);
    });

    // Hide add group if no boats left to add
    document.getElementById('whitelist-add-group').style.display =
        available.length === 0 ? 'none' : '';
}

async function handleWhitelistAdd() {
    const select = document.getElementById('whitelist-add-select');
    const btn = document.getElementById('whitelist-add-btn');
    const boatKey = select.value;

    if (!boatKey) {
        showToast('Please select a boat to add.', 'error');
        return;
    }

    btn.disabled = true;

    try {
        const updated = await adminService.addToCrewWhitelist(targetUserData.crew.key, boatKey);
        targetUserData.crew = updated;
        refreshWhitelistTable(updated);
        refreshWhitelistAddDropdown(updated);
        showToast('Boat added to whitelist.', 'success');
    } catch (error) {
        console.error('Failed to add to whitelist:', error);
        showToast(error.message || 'Failed to add boat to whitelist', 'error');
    } finally {
        btn.disabled = false;
    }
}

async function handleWhitelistRemove(boatKey) {
    try {
        const updated = await adminService.removeFromCrewWhitelist(targetUserData.crew.key, boatKey);
        targetUserData.crew = updated;
        refreshWhitelistTable(updated);
        refreshWhitelistAddDropdown(updated);
        showToast('Boat removed from whitelist.', 'success');
    } catch (error) {
        console.error('Failed to remove from whitelist:', error);
        showToast(error.message || 'Failed to remove boat from whitelist', 'error');
    }
}

// ==================== Utilities ====================

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
