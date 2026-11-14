
/* =========================================================
   MRMS - User Management
   Database API Integration
   File: assets/js/users.js
   ========================================================= */

(function () {
    'use strict';

    /* ---------- Configuration ---------- */

    var API_URL = 'users_api.php';

    var STATUS_LABELS = {
        active: 'Active',
        pending: 'Pending',
        suspended: 'Suspended',
        rejected: 'Rejected'
    };

    var ROLE_LABELS = {
        admin: 'Admin',
        doctor: 'Doctor',
        lab_operator: 'Lab Operator',
        patient: 'Patient'
    };

    /* ---------- State ---------- */

    var users = [];

    var state = {
        search: '',
        role: 'all',
        status: 'all',
        page: 1,
        perPage: 5,
        editingId: null
    };

    /* ---------- Elements ---------- */

    var $ = function (id) {
        return document.getElementById(id);
    };

    var tbody = $('userTableBody');
    var tableWrap = $('tableWrap');
    var emptyState = $('emptyState');
    var paginationBar = $('paginationBar');
    var pagination = $('pagination');
    var resultInfo = $('resultInfo');

    var searchInput = $('userSearch');
    var roleFilter = $('roleFilter');
    var statusFilter = $('statusFilter');

    var modal = $('userModal');
    var modalTitle = $('modalTitle');
    var form = $('userForm');
    var formError = $('formError');

    var fullNameInput = $('fullName');
    var emailInput = $('userEmail');
    var passwordInput = $('userPassword');
    var passwordField = $('passwordField');
    var roleInput = $('userRole');
    var statusInput = $('userStatus');

    var saveBtn = $('saveUserBtn');
    var toast = $('toast');

    var toastTimer = null;

    /* ---------- Helpers ---------- */

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (ch) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[ch];
        });
    }

    function initials(name) {
        var parts = String(name || '').trim().split(/\s+/).slice(0, 2);

        return parts.map(function (p) {
            return p.charAt(0).toUpperCase();
        }).join('') || '?';
    }

    function formatDate(value) {
        if (!value) return '—';

        var date = new Date(String(value).replace(' ', 'T'));

        if (isNaN(date.getTime())) return escapeHtml(value);

        return date.toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        });
    }

    function findUser(id) {
        return users.find(function (user) {
            return Number(user.id) === Number(id);
        }) || null;
    }

    function showToast(message) {
        toast.textContent = message;
        toast.classList.add('show');

        clearTimeout(toastTimer);

        toastTimer = setTimeout(function () {
            toast.classList.remove('show');
        }, 2800);
    }

    function showError(message) {
        formError.textContent = message;
        formError.hidden = false;
    }

    function clearErrors() {
        formError.hidden = true;
        formError.textContent = '';

        form.querySelectorAll('.form-field').forEach(function (field) {
            field.classList.remove('invalid');
        });
    }

    function markInvalid(input, message) {
        input.closest('.form-field').classList.add('invalid');

        if (!formError.textContent) {
            showError(message);
        }
    }

    /* ---------- API Requests ---------- */

    async function apiRequest(action, method, data) {
        var options = {
            method: method,
            credentials: 'same-origin',
            headers: {}
        };

        if (data) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(data);
        }

        var response = await fetch(
            API_URL + '?action=' + encodeURIComponent(action),
            options
        );

        var result;

        try {
            result = await response.json();
        } catch (error) {
            throw new Error('Invalid server response. Please check PHP errors.');
        }

        if (!response.ok) {
            throw new Error(result.message || 'Server request failed.');
        }

        if (!result.success) {
            throw new Error(result.message || 'Operation failed.');
        }

        return result;
    }

    /* ---------- Load Users From Database ---------- */

    async function loadUsers() {
        tbody.innerHTML =
            '<tr><td colspan="7">Loading users...</td></tr>';

        try {
            var result = await apiRequest('list', 'GET');

           users = Array.isArray(result.data)
    ? result.data.map(function (user) {
        return {
            id: Number(user.id),
            name: user.full_name,
            email: user.email,
            role: user.role,
            status: user.account_status,
            verificationStatus: user.verification_status,
            created: user.created_at
        };
    })
    : [];

            state.page = 1;

            render();

        } catch (error) {
            users = [];

            render();

            tbody.innerHTML =
                '<tr><td colspan="7">' +
                escapeHtml(error.message) +
                '</td></tr>';

            showToast(error.message);
        }
    }

    /* ---------- Filtering ---------- */

    function getFiltered() {
        var query = state.search.trim().toLowerCase();

        return users.filter(function (user) {

            var matchSearch =
                query === '' ||
                user.name.toLowerCase().includes(query) ||
                user.email.toLowerCase().includes(query) ||
                String(user.id) === query;

            var matchRole =
                state.role === 'all' ||
                user.role === state.role;

            var matchStatus =
                state.status === 'all' ||
                user.status === state.status;

            return matchSearch && matchRole && matchStatus;
        });
    }

    /* ---------- Summary Cards ---------- */

    function renderSummary() {
        var counts = {
            active: 0,
            pending: 0,
            suspended: 0
        };

        users.forEach(function (user) {
            if (Object.prototype.hasOwnProperty.call(counts, user.status)) {
                counts[user.status]++;
            }
        });

        $('statTotal').textContent = users.length;
        $('statActive').textContent = counts.active;
        $('statPending').textContent = counts.pending;
        $('statSuspended').textContent = counts.suspended;
    }

    /* ---------- Table Rendering ---------- */

    
function rowHtml(user) {

    var isActive = user.status === 'active';

    var toggleLabel = isActive ? 'Deactivate' : 'Activate';
    var toggleClass = isActive ? 'deactivate' : 'activate';

    var roleLabel = ROLE_LABELS[user.role] || user.role;
    var statusLabel = STATUS_LABELS[user.status] || user.status;

    var actions = '';

    // Patient Approval
    if (user.role === 'patient' && user.status === 'pending') {

        actions += `
            <button type="button"
                    class="btn-sm activate"
                    data-action="approve">
                Approve
            </button>

            <button type="button"
                    class="btn-sm deactivate"
                    data-action="reject">
                Reject
            </button>
        `;
    }

    // Doctor Verification
    if (user.role === 'doctor') {

        if (user.verificationStatus === 'pending') {

            actions += `
                <button type="button"
                        class="btn-sm activate"
                        data-action="doctor-approve">
                    Verify
                </button>

                <button type="button"
                        class="btn-sm deactivate"
                        data-action="doctor-reject">
                    Reject
                </button>
            `;

        } else if (user.verificationStatus === 'approved') {

            actions += `
                <span class="badge badge-active">
                    Verified
                </span>
            `;

        } else if (user.verificationStatus === 'rejected') {

            actions += `
                <span class="badge badge-rejected">
                    Verification Rejected
                </span>
            `;
        }
    }

    // Account activation/deactivation
    actions += `
        <button type="button"
                class="btn-sm ${toggleClass}"
                data-action="toggle">
            ${toggleLabel}
        </button>
    `;

    return `
        <tr data-id="${Number(user.id)}">

            <td data-label="ID" class="cell-id">
                #${Number(user.id)}
            </td>

            <td data-label="Full Name">
                <div class="user-cell">
                    <span class="user-avatar">
                        ${escapeHtml(initials(user.name))}
                    </span>

                    <span class="user-name">
                        ${escapeHtml(user.name)}
                    </span>
                </div>
            </td>

            <td data-label="Email" class="cell-email">
                ${escapeHtml(user.email)}
            </td>

            <td data-label="Role">
                <span class="role-tag">
                    ${escapeHtml(roleLabel)}
                </span>
            </td>

            <td data-label="Status">
                <span class="badge badge-${escapeHtml(user.status)}">
                    ${escapeHtml(statusLabel)}
                </span>
            </td>

            <td data-label="Created" class="cell-date">
                ${formatDate(user.created)}
            </td>

            <td class="cell-actions">
                <div class="row-actions">

                    <button type="button"
                            class="btn-sm"
                            data-action="edit">
                        Edit
                    </button>

                    ${actions}

                </div>
            </td>

        </tr>
    `;
}

    function render() {
        var list = getFiltered();

        var totalPages = Math.max(
            1,
            Math.ceil(list.length / state.perPage)
        );

        if (state.page > totalPages) {
            state.page = totalPages;
        }

        var start = (state.page - 1) * state.perPage;

        var pageItems = list.slice(
            start,
            start + state.perPage
        );

        renderSummary();

        var isEmpty = list.length === 0;

        tableWrap.hidden = isEmpty;
        emptyState.hidden = !isEmpty;
        paginationBar.hidden = isEmpty;

        tbody.innerHTML = pageItems.map(rowHtml).join('');

        if (!isEmpty) {
            resultInfo.textContent =
                'Showing ' + (start + 1) +
                ' to ' + (start + pageItems.length) +
                ' of ' + list.length +
                (list.length === 1 ? ' user' : ' users');

            renderPagination(totalPages);
        }
    }

    /* ---------- Pagination ---------- */

    function pageButton(label, page, options) {
        options = options || {};

        var cls = 'page-btn' +
            (options.active ? ' active' : '');

        var disabled = options.disabled ? ' disabled' : '';

        var current = options.active
            ? ' aria-current="page"'
            : '';

        return '<button type="button" class="' + cls +
            '" data-page="' + page + '"' +
            disabled + current + '>' +
            label +
            '</button>';
    }

    function renderPagination(totalPages) {
        var html = pageButton('Prev', state.page - 1, {
            disabled: state.page === 1
        });

        var last = 0;

        for (var p = 1; p <= totalPages; p++) {
            var near =
                p === 1 ||
                p === totalPages ||
                Math.abs(p - state.page) <= 1;

            if (!near) continue;

            if (last && p - last > 1) {
                html += '<span class="page-dots">...</span>';
            }

            html += pageButton(p, p, {
                active: p === state.page
            });

            last = p;
        }

        html += pageButton('Next', state.page + 1, {
            disabled: state.page === totalPages
        });

        pagination.innerHTML = html;
    }

    pagination.addEventListener('click', function (event) {
        var button = event.target.closest('[data-page]');

        if (!button || button.disabled) return;

        state.page = parseInt(
            button.getAttribute('data-page'),
            10
        );

        render();

        tableWrap.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest'
        });
    });

    /* ---------- Search and Filters ---------- */

    searchInput.addEventListener('input', function () {
        state.search = searchInput.value;
        state.page = 1;
        render();
    });

    roleFilter.addEventListener('change', function () {
        state.role = roleFilter.value;
        state.page = 1;
        render();
    });

    statusFilter.addEventListener('change', function () {
        state.status = statusFilter.value;
        state.page = 1;
        render();
    });

    function resetFilters() {
        state.search = '';
        state.role = 'all';
        state.status = 'all';
        state.page = 1;

        searchInput.value = '';
        roleFilter.value = 'all';
        statusFilter.value = 'all';

        render();
    }

    $('resetFilters').addEventListener('click', resetFilters);
    $('emptyReset').addEventListener('click', resetFilters);

    /* ---------- Modal ---------- */

    function openModal(user) {
        clearErrors();

        form.reset();

        if (user) {
            state.editingId = user.id;

            modalTitle.textContent = 'Edit User';
            saveBtn.textContent = 'Save Changes';

            fullNameInput.value = user.name;
            emailInput.value = user.email;
            roleInput.value = user.role;
            statusInput.value = user.status;

            passwordField.hidden = true;
            passwordInput.required = false;

        } else {
            state.editingId = null;

            modalTitle.textContent = 'Add New User';
            saveBtn.textContent = 'Save User';

            statusInput.value = 'active';

            passwordField.hidden = false;
            passwordInput.required = true;
        }

        modal.hidden = false;

        document.body.style.overflow = 'hidden';

        fullNameInput.focus();
    }

    function closeModal() {
        modal.hidden = true;

        document.body.style.overflow = '';

        state.editingId = null;

        passwordField.hidden = false;
        passwordInput.required = false;

        form.reset();

        clearErrors();
    }

    $('addUserBtn').addEventListener('click', function () {
        openModal(null);
    });

    modal.addEventListener('click', function (event) {
        if (event.target.closest('[data-close]')) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });

    /* ---------- Add / Update User ---------- */

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        clearErrors();

        var name = fullNameInput.value.trim();
        var email = emailInput.value.trim();
        var password = passwordInput.value;
        var role = roleInput.value;
        var status = statusInput.value;

        var valid = true;

        if (name === '') {
            markInvalid(fullNameInput, 'Full name is required.');
            valid = false;
        }

        if (email === '') {
            markInvalid(emailInput, 'Email address is required.');
            valid = false;
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            markInvalid(emailInput, 'Enter a valid email address.');
            valid = false;
        }

        if (state.editingId === null && password.length < 8) {
            markInvalid(passwordInput, 'Password must contain at least 8 characters.');
            valid = false;
        }

        if (!role) {
            markInvalid(roleInput, 'Please select a role.');
            valid = false;
        }

        if (!valid) return;

        var isEditing = state.editingId !== null;

        var data = {
            full_name: name,
            email: email,
            role: role,
            account_status: status
        };

        if (isEditing) {
            data.id = state.editingId;
        } else {
            data.password = password;
        }

        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';

        try {
            var result = await apiRequest(
                isEditing ? 'update' : 'add',
                'POST',
                data
            );

            closeModal();

            showToast(result.message);

            await loadUsers();

        } catch (error) {
            showError(error.message);
        } finally {
            saveBtn.disabled = false;
            saveBtn.textContent = state.editingId !== null
                ? 'Save Changes'
                : 'Save User';
        }
    });

    /* ---------- Edit / Activate / Deactivate ---------- */

    
/* ---------- Edit / Approve / Reject / Activate / Deactivate ---------- */

/* ---------- Edit / Approve / Reject / Activate / Deactivate ---------- */

tbody.addEventListener('click', async function (event) {

    var button = event.target.closest('[data-action]');

    if (!button) return;

    var row = button.closest('tr');

    if (!row) return;

    var user = findUser(
        Number(row.getAttribute('data-id'))
    );

    if (!user) return;

    var action = button.getAttribute('data-action');

    // Edit User
    if (action === 'edit') {
        openModal(user);
        return;
    }

    var apiAction = '';
    var decision = '';

    // Patient approval
    if (action === 'approve' || action === 'reject') {

        apiAction = 'approval';
        decision = action;
    }

    // Doctor verification
    if (action === 'doctor-approve') {

        apiAction = 'doctor_approval';
        decision = 'approve';
    }

    if (action === 'doctor-reject') {

        apiAction = 'doctor_approval';
        decision = 'reject';
    }

    // Handle approvals and rejections
    if (apiAction !== '') {

        var confirmed = confirm(
            'Are you sure you want to ' +
            (decision === 'approve' ? 'approve ' : 'reject ') +
            user.name + '?'
        );

        if (!confirmed) return;

        button.disabled = true;

        try {

            var result = await apiRequest(
                apiAction,
                'POST',
                {
                    id: user.id,
                    decision: decision
                }
            );

            showToast(result.message);

            await loadUsers();

        } catch (error) {

            showToast(error.message);
            button.disabled = false;
        }

        return;
    }

    // Activate / Deactivate account
    if (action === 'toggle') {

        var newStatus = user.status === 'active'
            ? 'suspended'
            : 'active';

        var confirmed = confirm(
            'Are you sure you want to ' +
            (newStatus === 'active' ? 'activate ' : 'deactivate ') +
            user.name + '?'
        );

        if (!confirmed) return;

        button.disabled = true;

        try {

            var result = await apiRequest(
                'status',
                'POST',
                {
                    id: user.id,
                    account_status: newStatus
                }
            );

            showToast(result.message);

            await loadUsers();

        } catch (error) {

            showToast(error.message);
            button.disabled = false;
        }
    }

});

    /* ---------- Initial Load ---------- */

    loadUsers();

})();
