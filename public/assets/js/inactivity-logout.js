/**
 * Aegis Inactivity Auto-Logout
 *
 * Tracks browser activity events and automatically redirects the user
 * to the logout endpoint after 15 minutes of inactivity.
 * Sleeping-tab and background-tab safe.
 */
(function () {
    // 15 minutes of inactivity (15 * 60 * 1000 ms)
    const INACTIVITY_TIMEOUT = 15 * 60 * 1000;

    // Store timestamp of the last activity
    let lastActivityTime = Date.now();
    let timeoutId;

    function resetTimer() {
        lastActivityTime = Date.now();
        clearTimeout(timeoutId);
        timeoutId = setTimeout(logoutUser, INACTIVITY_TIMEOUT);
    }

    function checkInactivity() {
        if (Date.now() - lastActivityTime >= INACTIVITY_TIMEOUT) {
            logoutUser();
        }
    }

    function logoutUser() {
        if (typeof window.appLogout === 'function') {
            window.appLogout(true);
        } else {
            window.location.href = '/login';
        }
    }

    // Common user interaction events to monitor
    const activityEvents = [
        'mousedown',
        'mousemove',
        'keypress',
        'scroll',
        'touchstart',
        'click'
    ];

    // Throttle helper to avoid performance hit on high-frequency events like mousemove/scroll
    let throttleTimer;
    function handleActivity() {
        if (throttleTimer) return;
        throttleTimer = setTimeout(() => {
            throttleTimer = null;
            resetTimer();
        }, 1000); // Only reset timer at most once per second
    }

    // Attach listeners
    activityEvents.forEach(event => {
        document.addEventListener(event, handleActivity, { passive: true });
    });

    // Check inactivity on window focus or tab visibility changes (handles woke-up tabs)
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            checkInactivity();
        }
    }, { passive: true });

    window.addEventListener('focus', checkInactivity, { passive: true });

    // Start timer on load
    resetTimer();
})();
