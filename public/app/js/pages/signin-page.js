/**
 * Sign In Page Module
 * Handles user authentication on the sign-in page
 */

import { isSignedIn, signIn, getCurrentUser } from '../authService.js';
import { showError } from '../toastService.js';

// Check if already signed in
if (await isSignedIn()) {
    // Redirect to appropriate dashboard based on admin status
    const user = await getCurrentUser();
    window.location.href = user?.isAdmin ? 'admin.html' : 'dashboard.html';
}

const form = document.getElementById('signin-form');

form.addEventListener('submit', async function(e) {
    e.preventDefault();

    const submitButton = this.querySelector('[type="submit"]');
    const originalLabel = submitButton.textContent;
    submitButton.disabled = true;
    submitButton.textContent = 'Signing in...';

    // Get form values
    const email = document.getElementById('email').value;
    const password = document.getElementById('password').value;

    // Attempt sign in
    const result = await signIn(email, password);

    if (result.success) {
        // Get user data to check admin status
        const user = await getCurrentUser();

        // Redirect to admin dashboard if admin, otherwise regular dashboard
        window.location.href = user?.isAdmin ? 'admin.html' : 'dashboard.html';
    } else {
        submitButton.disabled = false;
        submitButton.textContent = originalLabel;

        // Show error message as toast (stays visible for 4 seconds)
        showError(result.error, 4000);

        // Clear password field
        document.getElementById('password').value = '';
    }
});
