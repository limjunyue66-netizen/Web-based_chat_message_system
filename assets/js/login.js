(function () {
    function byId(id) {
        return document.getElementById(id);
    }

    function bindShowPassword() {
        var password = byId('password');
        var toggle = byId('toggle-password');
        if (!password || !toggle) {
            return;
        }
        toggle.addEventListener('click', function () {
            var show = password.getAttribute('type') === 'password';
            password.setAttribute('type', show ? 'text' : 'password');
            toggle.textContent = show ? 'Hide password' : 'Show password';
            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
        });
    }

    function bindFillButtons() {
        var identifier = byId('identifier');
        var password = byId('password');
        if (!identifier || !password) {
            return;
        }
        var buttons = document.querySelectorAll('[data-fill-login]');
        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener('click', function () {
                var user = button.getAttribute('data-username') || '';
                var pass = button.getAttribute('data-password');
                identifier.value = user;
                if (pass !== null && pass !== '') {
                    password.value = pass;
                } else {
                    password.value = '';
                    password.focus();
                }
                if (pass) {
                    password.focus();
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            bindShowPassword();
            bindFillButtons();
        });
    } else {
        bindShowPassword();
        bindFillButtons();
    }
})();
