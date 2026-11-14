/* MRMS – Patient Dashboard behaviour. No dependencies. */
(function () {
    'use strict';

    var root = document.querySelector('.mrms-pd');
    var shell = document.getElementById('pd-shell');
    if (!root || !shell) { return; }

    var sidebar = document.getElementById('pd-sidebar');
    var toggleBtn = document.getElementById('pd-sidebar-toggle');
    var overlay = shell.querySelector('.pd-overlay');
    var searchInput = document.getElementById('pd-search');
    var searchEmpty = document.querySelector('[data-search-empty]');
    var sections = Array.prototype.slice.call(document.querySelectorAll('[data-section]'));
    var navLinks = Array.prototype.slice.call(document.querySelectorAll('.pd-nav__link[data-section-link]'));
    var titleEls = Array.prototype.slice.call(document.querySelectorAll('[data-current-title]'));
    var mobileQuery = window.matchMedia('(max-width: 900px)');
    var STORE_KEY = 'mrms-pd-collapsed';
    var currentSection = 'overview';
    var activeFilter = 'all';

    function qa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
    function sectionNames() { return sections.map(function (s) { return s.getAttribute('data-section'); }); }

    /* ---------- Sidebar ---------- */
    function setNavOpen(open) {
        shell.classList.toggle('is-nav-open', open);
        overlay.hidden = !open;
        toggleBtn.setAttribute('aria-expanded', String(open));
        if (open) {
            var first = sidebar.querySelector('.pd-nav__link');
            if (first) { first.focus(); }
        }
    }

    function setCollapsed(collapsed) {
        shell.classList.toggle('is-collapsed', collapsed);
        toggleBtn.setAttribute('aria-expanded', String(!collapsed));
        try { localStorage.setItem(STORE_KEY, collapsed ? '1' : '0'); } catch (e) { /* storage unavailable */ }
    }

    toggleBtn.addEventListener('click', function () {
        if (mobileQuery.matches) {
            setNavOpen(!shell.classList.contains('is-nav-open'));
        } else {
            setCollapsed(!shell.classList.contains('is-collapsed'));
        }
    });

    qa('[data-nav-close]').forEach(function (el) {
        el.addEventListener('click', function () { setNavOpen(false); toggleBtn.focus(); });
    });

    function syncForViewport() {
        if (mobileQuery.matches) {
            overlay.hidden = !shell.classList.contains('is-nav-open');
            toggleBtn.setAttribute('aria-expanded', String(shell.classList.contains('is-nav-open')));
        } else {
            shell.classList.remove('is-nav-open');
            overlay.hidden = true;
            toggleBtn.setAttribute('aria-expanded', String(!shell.classList.contains('is-collapsed')));
        }
    }
    if (mobileQuery.addEventListener) { mobileQuery.addEventListener('change', syncForViewport); }
    else if (mobileQuery.addListener) { mobileQuery.addListener(syncForViewport); }

    try {
        if (localStorage.getItem(STORE_KEY) === '1') { shell.classList.add('is-collapsed'); }
    } catch (e) { /* ignore */ }
    syncForViewport();

    /* ---------- Section navigation ---------- */
    function showSection(name, focusMain) {
        if (sectionNames().indexOf(name) === -1) { name = 'overview'; }
        currentSection = name;
        var title = name;

        sections.forEach(function (s) {
            var on = s.getAttribute('data-section') === name;
            s.hidden = !on;
        });

        navLinks.forEach(function (link) {
            var on = link.getAttribute('data-section-link') === name;
            link.classList.toggle('is-active', on);
            if (on) {
                link.setAttribute('aria-current', 'page');
                title = link.getAttribute('title') || name;
            } else {
                link.removeAttribute('aria-current');
            }
        });

        titleEls.forEach(function (el) { el.textContent = title; });
        document.title = title + ' | MRMS Patient Dashboard';

        if (searchInput) { searchInput.value = ''; }
        applyAllFilters();

        if (focusMain) {
            var main = document.getElementById('pd-main');
            if (main) { main.focus({ preventScroll: true }); }
            window.scrollTo(0, 0);
        }
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('[data-section-link]');
        if (!link) { return; }
        var name = link.getAttribute('data-section-link');
        event.preventDefault();
        if (history.replaceState) { history.replaceState(null, '', '#' + name); }
        showSection(name, true);
        closeAllMenus();
        if (mobileQuery.matches) { setNavOpen(false); }
    });

    window.addEventListener('hashchange', function () {
        showSection(window.location.hash.replace('#', ''), true);
    });

    /* ---------- Dropdown menus ---------- */
    function closeAllMenus(except) {
        qa('[data-dropdown-toggle]').forEach(function (btn) {
            var menu = document.getElementById(btn.getAttribute('data-dropdown-toggle'));
            if (!menu || menu === except) { return; }
            menu.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        });
    }

    qa('[data-dropdown-toggle]').forEach(function (btn) {
        var menu = document.getElementById(btn.getAttribute('data-dropdown-toggle'));
        if (!menu) { return; }

        btn.addEventListener('click', function (event) {
            event.stopPropagation();
            var open = menu.hidden;
            closeAllMenus(open ? menu : null);
            menu.hidden = !open;
            btn.setAttribute('aria-expanded', String(open));
        });

        btn.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                menu.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
                var first = menu.querySelector('a');
                if (first) { first.focus(); }
            }
        });

        menu.addEventListener('keydown', function (event) {
            var items = qa('a', menu);
            var idx = items.indexOf(document.activeElement);
            if (event.key === 'ArrowDown') { event.preventDefault(); items[(idx + 1) % items.length].focus(); }
            if (event.key === 'ArrowUp') { event.preventDefault(); items[(idx - 1 + items.length) % items.length].focus(); }
            if (event.key === 'Tab') { menu.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
        });
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.pd-dropdown')) { closeAllMenus(); }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') { return; }
        var openBtn = qa('[data-dropdown-toggle][aria-expanded="true"]')[0];
        if (openBtn) { closeAllMenus(); openBtn.focus(); return; }
        if (shell.classList.contains('is-nav-open')) { setNavOpen(false); toggleBtn.focus(); }
    });

    /* ---------- Filtering (appointment tabs + search) ---------- */
    function activeSection() {
        return document.querySelector('[data-section="' + currentSection + '"]');
    }

    function applyAllFilters() {
        var section = activeSection();
        if (!section) { return; }
        var term = searchInput ? searchInput.value.trim().toLowerCase() : '';
        var items = qa('[data-searchable]', section);
        var shown = 0;

        items.forEach(function (item) {
            var matchesSearch = term === '' || (item.getAttribute('data-searchable') || '').indexOf(term) !== -1;
            var group = item.getAttribute('data-group');
            var matchesFilter = !group || activeFilter === 'all' || group === activeFilter;
            item.hidden = !(matchesSearch && matchesFilter);
            if (!item.hidden) { shown++; }
        });

        var filterEmpty = section.querySelector('[data-filter-empty]');
        if (filterEmpty) { filterEmpty.hidden = !(items.length > 0 && shown === 0); }

        if (searchEmpty) {
            searchEmpty.hidden = !(term !== '' && items.length > 0 && shown === 0 && !filterEmpty);
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyAllFilters);
        searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && searchInput.value) {
                event.stopPropagation();
                searchInput.value = '';
                applyAllFilters();
            }
        });
    }

    var tabs = qa('.pd-tabs [data-filter]');
    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function () {
            activeFilter = tab.getAttribute('data-filter');
            tabs.forEach(function (t) {
                var on = t === tab;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-selected', String(on));
            });
            applyAllFilters();
        });
        tab.addEventListener('keydown', function (event) {
            var next = null;
            if (event.key === 'ArrowRight') { next = tabs[(index + 1) % tabs.length]; }
            if (event.key === 'ArrowLeft') { next = tabs[(index - 1 + tabs.length) % tabs.length]; }
            if (next) { event.preventDefault(); next.focus(); next.click(); }
        });
    });

    /* ---------- Booking form (UI only: nothing is sent) ---------- */
    
/* ---------- Booking form (Backend integration) ---------- */

    /* ---------- Booking form (Connected to PHP API) ---------- */
    var form = document.getElementById('pd-booking-form');

    if (form) {
        var dateInput = document.getElementById('pd-book-date');
        var doctorInput = document.getElementById('pd-book-doctor');
        var timeInput = document.getElementById('pd-book-time');
        var reasonInput = document.getElementById('pd-book-reason');
        var notice = document.getElementById('pd-booking-notice');
        var submitButton = form.querySelector('button[type="submit"]');

        function localToday() {
            var d = new Date();
            var m = String(d.getMonth() + 1).padStart(2, '0');
            var day = String(d.getDate()).padStart(2, '0');
            return d.getFullYear() + '-' + m + '-' + day;
        }

        dateInput.min = localToday();

        function setError(id, message) {
            var field = document.getElementById(id);
            var err = form.querySelector('[data-error-for="' + id + '"]');

            field.classList.toggle('is-invalid', Boolean(message));
            field.setAttribute('aria-invalid', message ? 'true' : 'false');

            if (err) {
                err.textContent = message || '';
                err.hidden = !message;
            }

            return !message;
        }

        function showNotice(message, success) {
            notice.textContent = message;
            notice.hidden = false;
            notice.classList.toggle('is-success', success);
            notice.classList.toggle('is-error', !success);
        }

        function validate() {
            var ok = true;
            var today = localToday();
            var date = dateInput.value;
            var time = timeInput.value;
            var reason = reasonInput.value.trim();

            ok = setError(
                'pd-book-doctor',
                !doctorInput.value ? 'Please select a doctor.' : ''
            ) && ok;

            ok = setError(
                'pd-book-date',
                !date ? 'Choose a date.' :
                (date < today ? 'Choose today or a later date.' : '')
            ) && ok;

            ok = setError(
                'pd-book-time',
                !time ? 'Choose a time.' : ''
            ) && ok;

            ok = setError(
                'pd-book-reason',
                reason.length < 5 ? 'Reason must contain at least 5 characters.' : ''
            ) && ok;

            return ok;
        }

        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            notice.hidden = true;

            if (!validate()) {
                var bad = form.querySelector('.is-invalid');
                if (bad) bad.focus();
                return;
            }

            var endpoint = form.getAttribute('data-endpoint');

            if (!endpoint) {
                showNotice('Booking API endpoint is missing.', false);
                return;
            }

            submitButton.disabled = true;
            submitButton.textContent = 'Submitting...';

            try {
                var response = await fetch(endpoint, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                var result = await response.json();

                if (!response.ok || !result.success) {
                    throw new Error(
                        result.message || 'Appointment booking failed.'
                    );
                }

                form.reset();
dateInput.min = localToday();

showNotice(
    result.message || 'Appointment request submitted successfully.',
    true
);

                if (typeof window.loadPatientDashboard === 'function') {
                    window.loadPatientDashboard();
                }

            } catch (error) {
                showNotice(
                    error.message || 'Something went wrong. Please try again.',
                    false
                );
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = 'Request appointment';
            }
        });

        form.addEventListener('reset', function () {
            ['pd-book-doctor', 'pd-book-date', 'pd-book-time', 'pd-book-reason']
                .forEach(function (id) {
                    setError(id, '');
                });

            notice.hidden = true;
        });
    }


    /* ---------- Logout confirmation ---------- */
    var dialog = document.getElementById('pd-logout-dialog');
    qa('[data-logout]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            closeAllMenus();
            if (dialog && typeof dialog.showModal === 'function') {
                dialog.showModal();
                var stay = dialog.querySelector('[data-dialog-cancel]');
                if (stay) { stay.focus(); }
            } else if (window.confirm('Log out of MRMS?')) {
                window.location.href = link.getAttribute('href');
            }
        });
    });
    if (dialog) {
        var cancel = dialog.querySelector('[data-dialog-cancel]');
        if (cancel) { cancel.addEventListener('click', function () { dialog.close(); }); }
        dialog.addEventListener('click', function (event) { if (event.target === dialog) { dialog.close(); } });
    }

    /* ---------- Init ---------- */
    showSection(window.location.hash.replace('#', '') || 'overview', false);
})();