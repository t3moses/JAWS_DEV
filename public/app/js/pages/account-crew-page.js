/**
 * Crew Registration Page Module
 * Handles crew member registration form
 */

import { isSignedIn, register } from '../authService.js';
import { validatePassword } from '../passwordValidator.js';

// Check if already signed in
if (await isSignedIn()) {
    window.location.href = 'dashboard.html';
}

// Toggle mobile field visibility when WhatsApp checkbox changes
const whatsappCheckbox = document.getElementById('whatsapp_group');
const mobileGroup = document.getElementById('mobile-group');
const mobileInput = document.getElementById('mobile');

whatsappCheckbox.addEventListener('change', function() {
    if (this.checked) {
        mobileGroup.style.display = '';
        mobileInput.required = true;
    } else {
        mobileGroup.style.display = 'none';
        mobileInput.required = false;
        mobileInput.value = '';
    }
});

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

    // Validate mobile is provided when WhatsApp is checked
    const wantsWhatsapp = whatsappCheckbox.checked;
    const mobile = mobileInput.value.trim();
    if (wantsWhatsapp && !mobile) {
        submitButton.disabled = false;
        submitButton.textContent = originalLabel;
        alert('Please enter your mobile number to join the WhatsApp group.');
        mobileInput.focus();
        return;
    }

    // Create user data object
    const userData = {
        accountType: 'crew',
        email: document.getElementById('email').value,
        password: password,
        profile: {
            firstName: document.getElementById('first_name').value,
            lastName: document.getElementById('last_name').value,
            membershipNumber: document.getElementById('membership_number').value,
            skill: parseInt(document.getElementById('skill').value, 10),
            socialPreference: wantsWhatsapp,
            ...(wantsWhatsapp && mobile ? { mobile } : {})
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
