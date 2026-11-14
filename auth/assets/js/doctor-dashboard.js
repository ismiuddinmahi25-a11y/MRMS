
/* MRMS - Doctor Dashboard
   Dropdowns + Database Dashboard Data
   Appointment actions are handled by secure PHP POST forms.
*/

(function () {
    'use strict';

    /* ===============================
       1. Notification & Profile Dropdowns
    =============================== */

    var dropdowns = document.querySelectorAll('[data-dropdown]');

    function closeAll(except) {
        dropdowns.forEach(function (dropdown) {
            if (dropdown !== except) {
                dropdown.classList.remove('open');

                var toggle = dropdown.querySelector('[data-dropdown-toggle]');

                if (toggle) {
                    toggle.setAttribute('aria-expanded', 'false');
                }
            }
        });
    }

    dropdowns.forEach(function (dropdown) {
        var toggle = dropdown.querySelector('[data-dropdown-toggle]');

        if (!toggle) return;

        toggle.addEventListener('click', function (event) {
            event.stopPropagation();

            var shouldOpen = !dropdown.classList.contains('open');

            closeAll(dropdown);

            dropdown.classList.toggle('open', shouldOpen);

            toggle.setAttribute(
                'aria-expanded',
                shouldOpen ? 'true' : 'false'
            );
        });
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('[data-dropdown]')) {
            closeAll();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAll();
        }
    });


    /* ===============================
       2. Load Dashboard Data
    =============================== */

    function updateNumber(id, value) {
        var element = document.getElementById(id);

        if (element) {
            element.textContent = Number(value || 0).toLocaleString();
        }
    }

    async function loadDashboardData() {
        try {
            var response = await fetch('dashboard_data.php', {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                },
                cache: 'no-store'
            });

            if (!response.ok) {
                throw new Error(
                    'Dashboard request failed: HTTP ' + response.status
                );
            }

            var result = await response.json();

            if (!result.success) {
                throw new Error(
                    result.message || 'Unable to load dashboard data.'
                );
            }

            // dashboard_data.php returns fields directly,
            // not inside result.data.

            updateNumber(
                'totalPatients',
                result.total_patients
            );

            updateNumber(
                'todayAppointments',
                result.today_appointments
            );

            updateNumber(
                'pendingAppointmentsCount',
                result.pending_appointments_count
            );

            console.log('MRMS Doctor Dashboard data loaded successfully.');

        } catch (error) {
            console.error('MRMS Dashboard Error:', error);
        }
    }

    loadDashboardData();

})();
