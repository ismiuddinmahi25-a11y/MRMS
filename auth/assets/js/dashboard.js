/* =========================================================
   MRMS - Admin dashboard behaviour
   Handles the mobile sidebar only.
   ========================================================= */

(function () {
    'use strict';

    var body     = document.body;
    var menuBtn  = document.getElementById('menuBtn');
    var closeBtn = document.getElementById('sidebarClose');
    var overlay  = document.getElementById('overlay');

    var desktop = window.matchMedia('(min-width: 901px)');

    // Stop safely if required elements are missing
    if (!menuBtn || !closeBtn || !overlay) {
        console.warn('Dashboard sidebar elements not found.');
        return;
    }

    // Open sidebar
    function openSidebar() {
        if (desktop.matches) return;

        body.classList.add('sidebar-open');
        menuBtn.setAttribute('aria-expanded', 'true');
    }

    // Close sidebar
    function closeSidebar() {
        body.classList.remove('sidebar-open');
        menuBtn.setAttribute('aria-expanded', 'false');
    }

    // Event listeners
    menuBtn.addEventListener('click', openSidebar);
    closeBtn.addEventListener('click', closeSidebar);
    overlay.addEventListener('click', closeSidebar);

    // Close sidebar using Escape key
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeSidebar();
        }
    });

    // Reset sidebar when switching to desktop
    desktop.addEventListener('change', function (event) {
        if (event.matches) {
            closeSidebar();
        }
    });

})();