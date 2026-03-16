/**
 * Forgot Password Page Module
 * Handles the forgot-password form submission
 */

import { isSignedIn, getCurrentUser } from '../authService.js';
import { forgotPassword } from '../apiService.js';
import { showInfo } from '../toastService.js';

// Redirect to dashboard if already signed in
if (await isSignedIn()) {
    const user = await getCurrentUser();
    window.location.href = user?.isAdmin ? 'admin.html' : 'dashboard.html';
}

const form = document.getElementById('forgot-password-form');

form.addEventListener('submit', async function(e) {
    e.preventDefault();

    const submitButton = this.querySelector('[type="submit"]');
    const originalLabel = submitButton.textContent;
    submitButton.disabled = true;
    submitButton.textContent = 'Sending...';

    const email = document.getElementById('email').value;

    try {
        await forgotPassword(email);
    } catch {
        // Intentionally ignored: always show neutral message (enumeration protection)
    }

    // Always show the same neutral message regardless of outcome
    showInfo('If that email address is registered, you\'ll receive a reset link shortly. Please check your inbox.', 8000);

    submitButton.disabled = false;
    submitButton.textContent = originalLabel;
});
