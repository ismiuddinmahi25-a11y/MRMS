(function () {
    'use strict';

    var body = document.body;
    var menuBtn = document.getElementById('menuBtn');
    var closeBtn = document.getElementById('sidebarClose');
    var overlay = document.getElementById('overlay');
    var desktopQuery = window.matchMedia('(min-width: 1025px)');

    /* ---------- Sidebar ---------- */
    function setSidebar(open) {
        body.classList.toggle('sidebar-open', open);
        if (menuBtn) {
            menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (!open && document.activeElement === closeBtn) menuBtn.focus();
        }
        if (open && closeBtn) closeBtn.focus();
    }

    if (menuBtn) menuBtn.addEventListener('click', function () { setSidebar(!body.classList.contains('sidebar-open')); });
    if (closeBtn) closeBtn.addEventListener('click', function () { setSidebar(false); });
    if (overlay) overlay.addEventListener('click', function () { setSidebar(false); });

    var onBreakpoint = function (e) { if (e.matches) setSidebar(false); };
    if (desktopQuery.addEventListener) desktopQuery.addEventListener('change', onBreakpoint);
    else if (desktopQuery.addListener) desktopQuery.addListener(onBreakpoint);

    /* ---------- Dropdowns ---------- */
    var dropdowns = document.querySelectorAll('[data-dropdown]');

    function closeAll(except) {
        dropdowns.forEach(function (d) {
            if (d === except) return;
            d.classList.remove('open');
            var t = d.querySelector('[data-dropdown-toggle]');
            if (t) t.setAttribute('aria-expanded', 'false');
        });
    }

    dropdowns.forEach(function (d) {
        var toggle = d.querySelector('[data-dropdown-toggle]');
        if (!toggle) return;
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = !d.classList.contains('open');
            closeAll(d);
            d.classList.toggle('open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('[data-dropdown]')) closeAll();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var wasOpen = document.querySelector('[data-dropdown].open [data-dropdown-toggle]');
        closeAll();
        if (body.classList.contains('sidebar-open')) setSidebar(false);
        else if (wasOpen) wasOpen.focus();
    });

    /* ---------- Test request table filter ---------- */
    var rows = document.querySelectorAll('[data-request-row]');
    if (rows.length) {
        var tabs = document.querySelectorAll('[data-filter]');
        var search = document.getElementById('requestSearch');
        var emptyMsg = document.querySelector('[data-filter-empty]');
        var tableWrap = document.querySelector('.table-wrap');
        var state = { status: 'all', query: '' };

        function applyFilter() {
            var visible = 0;
            rows.forEach(function (row) {
                var okStatus = state.status === 'all' || row.getAttribute('data-status') === state.status;
                var okQuery = state.query === '' || (row.getAttribute('data-search') || '').indexOf(state.query) !== -1;
                var show = okStatus && okQuery;
                row.hidden = !show;
                if (show) visible++;
            });
            if (emptyMsg) emptyMsg.hidden = visible !== 0;
            if (tableWrap) tableWrap.hidden = visible === 0;
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                state.status = tab.getAttribute('data-filter');
                tabs.forEach(function (t) {
                    var active = t === tab;
                    t.classList.toggle('is-active', active);
                    t.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
                applyFilter();
            });
        });

        if (search) {
            search.addEventListener('input', function () {
                state.query = search.value.trim().toLowerCase();
                applyFilter();
            });
        }
    }
})();