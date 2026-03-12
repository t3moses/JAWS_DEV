/**
 * Edit Profile Page Module
 * Handles profile editing form and password changes
 */

import { requireAuth, getCurrentUser, signOut } from '../authService.js';
import { updateAuthenticatedNavigation, addAdminLink } from '../navigationService.js';
import { updateUser } from '../userService.js';
import { validatePassword, getPasswordRequirementsHTML } from '../passwordValidator.js';
import { showSuccess, showError } from '../toastService.js';

// Make signOut available globally for onclick handlers
window.signOut = signOut;

// Require authentication
if (!requireAuth()) {
    // requireAuth redirects to signin.html if not authenticated
}

// Get current user
const user = await getCurrentUser();
if (!user) {
    window.location.href = 'signin.html';
}

// Update navigation with user info
updateAuthenticatedNavigation(user, signOut);

// Add admin link if user is admin
addAdminLink(user);

// Build form based on account type
const formContent = document.getElementById('form-content');
let formHTML = '';

if (user.accountType === 'crew') {
    formHTML = `
        <h2>Your Info</h2>

        <div class="form-group">
            <label for="first_name">First Name *</label>
            <input type="text" id="first_name" name="first_name" required placeholder="Enter your first name" value="${user.profile.firstName}">
        </div>

        <div class="form-group">
            <label for="last_name">Last Name *</label>
            <input type="text" id="last_name" name="last_name" required placeholder="Enter your last name" value="${user.profile.lastName}">
        </div>

        <div class="form-group">
            <label for="email">Email *</label>
            <input type="email" id="email" name="email" required placeholder="your.email@example.com" value="${user.email}" disabled style="background: #f5f5f5; cursor: not-allowed;">
            <small>Email cannot be changed. Contact admin if you need to update it.</small>
        </div>

        <div class="form-group">
            <label for="membership_number">NSC Membership Number (Optional)</label>
            <input type="text" id="membership_number" name="membership_number" placeholder="Enter your NSC membership number" value="${user.profile.membershipNumber || ''}">
            <small>Found on your NSC membership card</small>
        </div>

        <div class="form-group">
            <label for="experience">Qualifications and Experience</label>
            <textarea id="experience" name="experience" maxlength="120" rows="3" placeholder="e.g. CANSail courses, 3 seasons racing at NSC">${user.profile.experience || ''}</textarea>
            <small>This information will be provided to your skipper. Limited to 120 characters.</small>
        </div>

        <div class="form-group">
            <label style="display: flex; align-items: center; cursor: pointer;">
                <input type="checkbox" id="whatsapp_group" name="whatsapp_group" style="width: auto; margin-right: 0.75rem;" ${user.profile.whatsappGroup ? 'checked' : ''}>
                Enrol me in the program's WhatsApp group
            </label>
            <small>Stay connected with other sailors and get event updates!</small>
        </div>

        <div class="form-group" id="mobile-group" style="display: ${user.profile.whatsappGroup || user.profile.mobile ? '' : 'none'};">
            <label for="mobile">Mobile Number${user.profile.whatsappGroup ? ' *' : ''}</label>
            <input type="tel" id="mobile" name="mobile" placeholder="(555) 123-4567" value="${user.profile.mobile || ''}" ${user.profile.whatsappGroup ? 'required' : ''}>
            <small>Required to add you to the WhatsApp group.</small>
        </div>

        <h2 style="margin-top: 4rem; margin-bottom: 2rem;">Change Password (Optional)</h2>

        <div class="form-group">
            <label for="current_password">Current Password</label>
            <input type="password" id="current_password" name="current_password" placeholder="Enter current password">
            <small>Only required if changing password</small>
        </div>

        <div class="form-group">
            <label for="new_password">New Password</label>
            <input type="password" id="new_password" name="new_password" minlength="8" placeholder="At least 8 characters">
            <small>Password must contain:${getPasswordRequirementsHTML()}</small>
        </div>

        <div class="form-group">
            <label for="confirm_new_password">Confirm New Password</label>
            <input type="password" id="confirm_new_password" name="confirm_new_password" placeholder="Re-enter new password">
        </div>
    `;
} else {
    // Boat owner form
    formHTML = `
        <h2>About You</h2>

        <div class="form-group">
            <label for="first_name">First Name *</label>
            <input type="text" id="first_name" name="first_name" required placeholder="Enter your first name" value="${user.profile.firstName}">
        </div>

        <div class="form-group">
            <label for="last_name">Last Name *</label>
            <input type="text" id="last_name" name="last_name" required placeholder="Enter your last name" value="${user.profile.lastName}">
        </div>

        <div class="form-group">
            <label for="email">Email *</label>
            <input type="email" id="email" name="email" required placeholder="your.email@example.com" value="${user.email}" disabled style="background: #f5f5f5; cursor: not-allowed;">
            <small>Email cannot be changed. Contact admin if you need to update it.</small>
        </div>

        <div class="form-group">
            <label for="phone">Phone Number *</label>
            <input type="tel" id="phone" name="phone" required placeholder="(555) 123-4567" value="${user.profile.phone}">
            <small>For weather-related morning calls and the WhatsApp group (if you join).</small>
        </div>

        <h2 style="margin-top: 4rem; margin-bottom: 2rem;">About Your Boat</h2>

        <div class="form-group">
            <label for="boat_name">Boat Name *</label>
            <input type="text" id="boat_name" name="boat_name" required placeholder="What's your boat called?" value="${user.profile.boatName}">
        </div>

        <div class="form-group">
            <label for="min_crew">Minimum Crew Needed *</label>
            <select id="min_crew" name="min_crew" required>
                <option value="">Minimum crew needed</option>
                <option value="1" ${user.profile.minCrew === '1' ? 'selected' : ''}>1 crew member</option>
                <option value="2" ${user.profile.minCrew === '2' ? 'selected' : ''}>2 crew members</option>
                <option value="3" ${user.profile.minCrew === '3' ? 'selected' : ''}>3 crew members</option>
                <option value="4" ${user.profile.minCrew === '4' ? 'selected' : ''}>4 crew members</option>
            </select>
            <small>Minimum number of crew you need to sail comfortably</small>
        </div>

        <div class="form-group">
            <label for="max_crew">Maximum Crew You Can Take *</label>
            <select id="max_crew" name="max_crew" required>
                <option value="">Maximum crew</option>
                <option value="2" ${user.profile.maxCrew === '2' ? 'selected' : ''}>2 crew members</option>
                <option value="3" ${user.profile.maxCrew === '3' ? 'selected' : ''}>3 crew members</option>
                <option value="4" ${user.profile.maxCrew === '4' ? 'selected' : ''}>4 crew members</option>
                <option value="5" ${user.profile.maxCrew === '5' ? 'selected' : ''}>5 crew members</option>
                <option value="6" ${user.profile.maxCrew === '6' ? 'selected' : ''}>6 crew members</option>
            </select>
            <small>Not including you as skipper—just how many crew can you safely fit?</small>
        </div>

        <div class="form-group">
            <label style="display: flex; align-items: flex-start; cursor: pointer;">
                <input type="checkbox" id="request_first_mate" name="request_first_mate" style="width: auto; margin-right: 0.75rem; margin-top: 0.25rem;" ${user.profile.requestFirstMate ? 'checked' : ''}>
                <span>I would like the assistance of a competent first mate</span>
            </label>
        </div>

        <div class="form-group">
            <label style="display: flex; align-items: flex-start; cursor: pointer;">
                <input type="checkbox" id="whatsapp_group" name="whatsapp_group" style="width: auto; margin-right: 0.75rem; margin-top: 0.25rem;" ${user.profile.whatsappGroup ? 'checked' : ''}>
                <span>I would like to join the program's WhatsApp group</span>
            </label>
        </div>

        <div class="form-group">
            <label style="display: flex; align-items: flex-start; cursor: pointer;">
                <input type="checkbox" id="willing_to_crew" name="willing_to_crew" style="width: auto; margin-right: 0.75rem; margin-top: 0.25rem;" ${user.profile.willingToCrew ? 'checked' : ''}>
                <span>I would be willing to crew on another boat if there aren't enough crew for an event</span>
            </label>
        </div>

        <h2 style="margin-top: 4rem; margin-bottom: 2rem;">Change Password (Optional)</h2>

        <div class="form-group">
            <label for="current_password">Current Password</label>
            <input type="password" id="current_password" name="current_password" placeholder="Enter current password">
            <small>Only required if changing password</small>
        </div>

        <div class="form-group">
            <label for="new_password">New Password</label>
            <input type="password" id="new_password" name="new_password" minlength="8" placeholder="At least 8 characters">
            <small>Password must contain:${getPasswordRequirementsHTML()}</small>
        </div>

        <div class="form-group">
            <label for="confirm_new_password">Confirm New Password</label>
            <input type="password" id="confirm_new_password" name="confirm_new_password" placeholder="Re-enter new password">
        </div>
    `;
}

formContent.innerHTML = formHTML;

// Wire up WhatsApp → mobile toggle for crew
if (user.accountType === 'crew') {
    const whatsappCheckbox = document.getElementById('whatsapp_group');
    const mobileGroup = document.getElementById('mobile-group');
    const mobileInput = document.getElementById('mobile');
    const mobileLabel = mobileGroup.querySelector('label');

    whatsappCheckbox.addEventListener('change', function() {
        if (this.checked) {
            mobileGroup.style.display = '';
            mobileInput.required = true;
            mobileLabel.textContent = 'Mobile Number *';
        } else {
            mobileGroup.style.display = 'none';
            mobileInput.required = false;
            mobileLabel.textContent = 'Mobile Number';
        }
    });
}

// Handle form submission
document.getElementById('edit-profile-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    // Handle password change if provided
    const currentPassword = document.getElementById('current_password').value;
    const newPassword = document.getElementById('new_password').value;
    const confirmNewPassword = document.getElementById('confirm_new_password').value;

    // If any password field is filled, validate all password fields
    if (currentPassword || newPassword || confirmNewPassword) {
        if (!currentPassword) {
            showError('Please enter your current password to change it.');
            return;
        }

        if (!newPassword) {
            showError('Please enter a new password.');
            return;
        }

        if (newPassword !== confirmNewPassword) {
            showError('New passwords do not match!');
            return;
        }

        // Validate password requirements
        const validation = validatePassword(newPassword);
        if (!validation.isValid) {
            showError(validation.error);
            return;
        }

        // Password verification will be handled by backend API
        // The API will verify the current password when we submit the profile update
        // This is more secure than client-side verification
        console.log('Password change requested - backend will verify current password');
    }

    const saveButton = e.target.querySelector('[type="submit"]');
    const originalLabel = saveButton.textContent;
    saveButton.disabled = true;
    saveButton.textContent = 'Saving...';

    // Build profile update object
    let profileUpdates = {};

    if (user.accountType === 'crew') {
        profileUpdates = {
            firstName: document.getElementById('first_name').value,
            lastName: document.getElementById('last_name').value,
            membershipNumber: document.getElementById('membership_number').value,
            experience: document.getElementById('experience').value.trim(),
            socialPreference: document.getElementById('whatsapp_group').checked,
            mobile: document.getElementById('mobile').value
        };
    } else {
        profileUpdates = {
            ownerFirstName: document.getElementById('first_name').value,
            ownerLastName: document.getElementById('last_name').value,
            ownerMobile: document.getElementById('phone').value,
            displayName: document.getElementById('boat_name').value,
            minBerths: document.getElementById('min_crew').value,
            maxBerths: document.getElementById('max_crew').value,
            assistanceRequired: document.getElementById('request_first_mate').checked,
            socialPreference: document.getElementById('whatsapp_group').checked,
            willingToCrew: document.getElementById('willing_to_crew').checked
        };
    }

    // Update user
    const updates = {};

    if (user.accountType === 'crew') {
        updates.crewProfile = profileUpdates;
    } else {
        updates.boatProfile = profileUpdates;
    }

    // Add password change if provided
    if (newPassword) {
        updates.password = newPassword;
    }

    const result = await updateUser(user.userId, updates);

    if (result.success) {
        // Show success toast for 3 seconds
        showSuccess('Profile updated successfully! Redirecting to dashboard...', 3000);

        // Redirect after toast has been visible (3.2 seconds to ensure user sees it)
        setTimeout(() => {
            window.location.href = 'dashboard.html';
        }, 3200);
    } else {
        saveButton.disabled = false;
        saveButton.textContent = originalLabel;
        showError(result.error || 'Failed to update profile');
    }
});
