/**
 * Reset Password Page Module
 * Handles the password-reset form using a token from the URL
 */

import { isSignedIn, getCurrentUser } from '../authService.js';
import { resetPassword } from '../apiService.js';
import { validatePassword, getPasswordRequirementsHTML } from '../passwordValidator.js';
import { showError, showSuccess } from '../toastService.js';

// Redirect to dashboard if already signed in
if (await isSignedIn()) {
    const user = await getCurrentUser();
    window.location.href = user?.isAdmin ? 'admin.html' : 'dashboard.html';
}

// Inject password requirements
document.getElementById('password-requirements').innerHTML = getPasswordRequirementsHTML();

// Extract token from URL
const params = new URLSearchParams(window.location.search);
const token = params.get('token');

if (!token) {
    document.getElementById('token-error').style.display = 'block';
    const form = document.getElementById('reset-password-form');
    form.style.display = 'none';
} else {
    const form = document.getElementById('reset-password-form');

    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        const submitButton = this.querySelector('[type="submit"]');
        const originalLabel = submitButton.textContent;

        const password = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirm-password').value;

        // Client-side validation
        if (password !== confirmPassword) {
            showError('Passwords do not match.', 4000);
            return;
        }

        const validation = validatePassword(password);
        if (!validation.isValid) {
            showError(validation.error, 4000);
            return;
        }

        submitButton.disabled = true;
        submitButton.textContent = 'Resetting...';

        try {
            await resetPassword(token, password);
            showSuccess('Password reset successfully. Redirecting to sign in...', 3000);
            setTimeout(() => { window.location.href = 'signin.html'; }, 2000);
        } catch (error) {
            showError(error.message || 'Reset link is invalid or has expired. Please request a new one.', 5000);
            submitButton.disabled = false;
            submitButton.textContent = originalLabel;
        }
    });
}
