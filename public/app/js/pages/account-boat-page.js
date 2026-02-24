/**
 * Boat Owner Registration Page Module
 * Handles boat owner registration form
 */

import { isSignedIn, register } from '../authService.js';
import { validatePassword } from '../passwordValidator.js';

// Check if already signed in
if (await isSignedIn()) {
    window.location.href = 'dashboard.html';
}

document.querySelector('form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const submitButton = this.querySelector('[type="submit"]');
    const originalLabel = submitButton.textContent;
    submitButton.disabled = true;
    submitButton.textContent = 'Creating account...';

    // Get password values
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm_password').value;

    // Validate passwords match
    if (password !== confirmPassword) {
        submitButton.disabled = false;
        submitButton.textContent = originalLabel;
        alert('Passwords do not match! Please try again.');
        return;
    }

    // Validate password requirements
    const validation = validatePassword(password);
    if (!validation.isValid) {
        submitButton.disabled = false;
        submitButton.textContent = originalLabel;
        alert(validation.error);
        return;
    }

    // Create user data object
    const userData = {
        accountType: 'boat_owner',
        email: document.getElementById('email').value,
        password: password,
        profile: {
            ownerFirstName: document.getElementById('first_name').value,
            ownerLastName: document.getElementById('last_name').value,
            ownerMobile: document.getElementById('phone').value,
            displayName: document.getElementById('boat_name').value,
            minBerths: document.getElementById('min_crew').value,
            maxBerths: document.getElementById('max_crew').value,
            assistanceRequired: document.getElementById('request_first_mate').checked,
            socialPreference: document.getElementById('whatsapp_group').checked,
            willingToCrew: document.getElementById('willing_to_crew').checked
        }
    };

    // Register user (creates account and signs in automatically)
    const result = await register(userData);

    if (result.success) {
        window.location.href = 'dashboard.html';
    } else {
        submitButton.disabled = false;
        submitButton.textContent = originalLabel;
        alert('Error: ' + result.error);
    }
});
