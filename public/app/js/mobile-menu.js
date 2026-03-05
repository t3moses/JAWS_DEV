/**
 * Mobile menu toggle functionality
 * Handles the hamburger menu for mobile navigation
 */

// Wait for DOM to be ready
document.addEventListener('DOMContentLoaded', function() {
    const hamburger = document.getElementById('hamburger');
    const nav = document.getElementById('nav');
    const menuOverlay = document.getElementById('menu-overlay');

    // Return early if elements don't exist on this page
    if (!hamburger || !nav || !menuOverlay) {
        return;
    }

    function toggleMenu() {
        hamburger.classList.toggle('active');
        nav.classList.toggle('active');
        menuOverlay.classList.toggle('active');
        document.body.style.overflow = nav.classList.contains('active') ? 'hidden' : '';
    }

    // Toggle menu when hamburger is clicked
    hamburger.addEventListener('click', toggleMenu);

    // Close menu when overlay is clicked
    menuOverlay.addEventListener('click', toggleMenu);

    // Close menu when clicking nav links
    document.querySelectorAll('nav a').forEach(link => {
        link.addEventListener('click', (_e) => {
            // Only close menu if it's open
            if (nav.classList.contains('active')) {
                toggleMenu();
            }
        });
    });
});
