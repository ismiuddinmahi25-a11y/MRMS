/* =========================================================
   MRMS - Login page behaviour (assets/js/login.js)
   Front-end only: show/hide password, validation, messages.
   No authentication happens here.
   ========================================================= */
(function () {
    'use strict';

    var form        = document.getElementById('loginForm');
    var emailInput  = document.getElementById('email');
    var passInput   = document.getElementById('password');
    var toggleBtn   = document.getElementById('togglePassword');
    var loginBtn    = document.getElementById('loginBtn');
    var forgotLink  = document.getElementById('forgotLink');
    var messageBox  = document.getElementById('formMessage');
    var messageText = document.getElementById('formMessageText');

    /* ---------- Message area helpers ---------- */
    function showMessage(text, type) {
        messageText.textContent = text;
        messageBox.classList.toggle('info', type === 'info');
        messageBox.classList.add('show');
    }

    function hideMessage() {
        messageBox.classList.remove('show', 'info');
        messageText.textContent = '';
    }

    /* ---------- Field error helpers ---------- */
    function setFieldError(input, errorId, text) {
        var field = input.closest('.field');
        document.getElementById(errorId).textContent = text;
        field.classList.toggle('invalid', text !== '');
        input.setAttribute('aria-invalid', text !== '' ? 'true' : 'false');
    }

    function validateEmail() {
        var value = emailInput.value.trim();
        var pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        var error = '';

        if (value === '') {
            error = 'Email address is required.';
        } else if (!pattern.test(value)) {
            error = 'Enter a valid email address.';
        }
        setFieldError(emailInput, 'emailError', error);
        return error === '';
    }

    function validatePassword() {
        var error = passInput.value === '' ? 'Password is required.' : '';
        setFieldError(passInput, 'passwordError', error);
        return error === '';
    }

    /* ---------- Show / hide password ---------- */
    toggleBtn.addEventListener('click', function () {
        var show = passInput.type === 'password';
        passInput.type = show ? 'text' : 'password';
        toggleBtn.classList.toggle('visible', show);
        toggleBtn.setAttribute('aria-pressed', show ? 'true' : 'false');
        toggleBtn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        passInput.focus();
    });

    /* ---------- Clear errors while typing ---------- */
    emailInput.addEventListener('input', function () {
        if (emailInput.closest('.field').classList.contains('invalid')) { validateEmail(); }
        hideMessage();
    });
    passInput.addEventListener('input', function () {
        if (passInput.closest('.field').classList.contains('invalid')) { validatePassword(); }
        hideMessage();
    });
    emailInput.addEventListener('blur', function () {
        if (emailInput.value !== '') { validateEmail(); }
    });

    /* ---------- Forgot password (UI only for now) ---------- */
    forgotLink.addEventListener('click', function (event) {
        event.preventDefault();
        showMessage('Password recovery is not available yet. Please contact your administrator.', 'info');
    });

    /* ---------- Submit ---------- */
    form.addEventListener('submit', function (event) {
        var emailOk = validateEmail();
        var passOk  = validatePassword();

        if (!emailOk || !passOk) {
            event.preventDefault();
            showMessage('Please fix the highlighted fields and try again.', 'error');
            (emailOk ? passInput : emailInput).focus();
            return;
        }

        /* Valid: let the form POST to login_process.php */
        hideMessage();
        loginBtn.disabled = true;
        loginBtn.textContent = 'Logging in...';
    });

    /* Restore the button if the page is shown again via the Back button */
    window.addEventListener('pageshow', function () {
        loginBtn.disabled = false;
        loginBtn.textContent = 'Login';
    });
})();


