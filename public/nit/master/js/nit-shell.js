/* nit/master: delete confirmations, live filters, password tools, single submit. Source of elements/shell-script.blade.php. */
    (function () {
        // Light / dark toggle; the choice is remembered in this browser.
        document.querySelectorAll('[data-nx-theme-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-bs-theme', next);
                try { localStorage.setItem('nx-theme', next); } catch (e) {}
            });
        });

        // Confirm before submitting a form with data-nx-confirm.
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form.matches('[data-nx-confirm]') || form.dataset.nxConfirmed) return;
            event.preventDefault();
            var message = form.getAttribute('data-nx-confirm');
            var proceed = function () { form.dataset.nxConfirmed = '1'; form.submit(); };
            if (window.Swal) {
                Swal.fire({
                    title: message,
                    text: form.getAttribute('data-nx-confirm-text') || 'This cannot be undone.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: form.getAttribute('data-nx-confirm-button') || 'Delete',
                    cancelButtonText: 'Cancel',
                    reverseButtons: true,
                    focusCancel: true,
                    customClass: { confirmButton: 'btn btn-danger', cancelButton: 'btn btn-light' },
                    buttonsStyling: false
                }).then(function (result) { if (result.isConfirmed) proceed(); });
            } else if (window.confirm(message)) {
                proceed();
            }
        });

        // Live filter: <input data-nx-filter="#target"> hides [data-nx-row] whose text does not match.
        document.querySelectorAll('[data-nx-filter]').forEach(function (input) {
            var target = document.querySelector(input.getAttribute('data-nx-filter'));
            var empty = document.querySelector(input.getAttribute('data-nx-filter-empty'));
            input.addEventListener('input', function () {
                var q = input.value.trim().toLowerCase();
                var shown = 0;
                target.querySelectorAll('[data-nx-row]').forEach(function (row) {
                    var match = row.textContent.toLowerCase().indexOf(q) !== -1;
                    row.hidden = !match;
                    if (match) shown++;
                });
                if (empty) empty.hidden = shown > 0;
            });
        });

        // Password: show / hide and generate.
        document.querySelectorAll('[data-nx-pw]').forEach(function (wrap) {
            var input = wrap.querySelector('input');
            var toggle = wrap.querySelector('[data-nx-pw-toggle]');
            var generate = wrap.querySelector('[data-nx-pw-generate]');
            toggle && toggle.addEventListener('click', function () {
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                toggle.textContent = show ? (toggle.dataset.hide || 'Hide') : (toggle.dataset.show || 'Show');
                toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
            });
            generate && generate.addEventListener('click', function () {
                // As long as the input allows (maxlength, 16 otherwise), with an upper and lower case
                // letter, a digit and a symbol, so it meets every password rule.
                var sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%*'];
                var all = sets.join('');
                var length = input.maxLength > 0 ? input.maxLength : 16;
                var bytes = new Uint32Array(length * 2);
                window.crypto.getRandomValues(bytes);
                var out = Array.prototype.slice.call(bytes, 0, length).map(function (b, i) { var set = sets[i] || all; return set[b % set.length]; });
                for (var i = out.length - 1; i > 0; i--) { var j = bytes[length + i] % (i + 1); var t = out[i]; out[i] = out[j]; out[j] = t; }
                input.value = out.join('');
                input.type = 'text';
                if (toggle) { toggle.textContent = toggle.dataset.hide || 'Hide'; toggle.setAttribute('aria-pressed', 'true'); }
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.focus();
                input.select();
            });
        });

        // Prevent double submits.
        document.querySelectorAll('form[data-nx-once]').forEach(function (form) {
            form.addEventListener('submit', function () {
                document.querySelectorAll('button[type="submit"][form="' + form.id + '"], #' + form.id + ' button[type="submit"]').forEach(function (b) { b.disabled = true; });
            });
        });
        window.addEventListener('pageshow', function () {
            document.querySelectorAll('form[data-nx-once]').forEach(function (form) {
                document.querySelectorAll('button[type="submit"][form="' + form.id + '"], #' + form.id + ' button[type="submit"]').forEach(function (b) { b.disabled = false; });
            });
        });
    })();
