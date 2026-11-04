/* Thermic Warranty – registration wizard and customer portal. No dependencies. */
(function () {
    'use strict';
    if (typeof window.TW === 'undefined') return;

    function post(action, data) {
        var fd = data instanceof FormData ? data : new FormData();
        if (!(data instanceof FormData) && data) {
            Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        }
        fd.append('action', 'tw_' + action);
        return fetch(TW.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) {
                return r.json().catch(function () { return { success: false, data: { message: 'Σφάλμα επικοινωνίας με τον server.' } }; });
            })
            .catch(function () { return { success: false, data: { message: 'Δεν υπάρχει σύνδεση. Δοκιμάστε ξανά.' } }; });
    }

    // ------------------------------------------------------------ bot protection
    // Every form carries a honeypot and a signed timestamp (TW_Guard::fields); the forms that start an
    // activation or sign-in also carry a Cloudflare Turnstile box (.tw-ts), rendered here once its script loads.
    var tsQueue = [];
    function tsRender(el) {
        if (!TW.turnstile || !el || el.getAttribute('data-ts-id')) return;
        if (!window.turnstile) { tsQueue.push(el); return; }
        var id = window.turnstile.render(el, { sitekey: TW.turnstile, action: el.getAttribute('data-action') || 'public', language: 'el', size: 'flexible' });
        el.setAttribute('data-ts-id', id);
    }
    window.twTurnstileReady = function () { tsQueue.splice(0).forEach(tsRender); };
    function tsBox(form) { return form.querySelector('.tw-ts'); }
    function tsToken(form) {
        var el = tsBox(form);
        if (!TW.turnstile || !el) return null; // not needed here
        var id = el.getAttribute('data-ts-id');
        return id && window.turnstile ? (window.turnstile.getResponse(id) || '') : '';
    }
    function tsReset(form) {
        var el = tsBox(form), id = el && el.getAttribute('data-ts-id');
        if (id && window.turnstile) window.turnstile.reset(id);
    }
    /** Guard fields of a form, plus its Turnstile token; false (with a message shown) if the challenge isn't done yet. */
    function guard(form, root) {
        var g = {};
        ['tw_website', 'tw_t'].forEach(function (k) { if (form[k]) g[k] = form[k].value; });
        var t = tsToken(form);
        if (t === '') { alertBox(root, 'Ολοκληρώστε τον έλεγχο ασφαλείας πάνω από το κουμπί και δοκιμάστε ξανά.'); return false; }
        if (t) g['cf-turnstile-response'] = t;
        return g;
    }
    function extend(a, b) { Object.keys(b).forEach(function (k) { a[k] = b[k]; }); return a; }

    function alertBox(root, msg, type) {
        var el = root.querySelector('.tw-alert');
        if (!msg) { el.hidden = true; return; }
        el.textContent = msg;
        el.className = 'tw-alert tw-alert--' + (type || 'error');
        el.hidden = false;
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function markField(form, name) {
        form.querySelectorAll('.tw-invalid').forEach(function (e) { e.classList.remove('tw-invalid'); });
        if (!name) return;
        var f = form.querySelector('[name="' + name + '"]');
        if (f) {
            var wrap = f.closest('.tw-field, .tw-check') || f;
            wrap.classList.add('tw-invalid');
            f.focus();
        }
    }

    function busy(form, on) {
        form.querySelectorAll('button').forEach(function (b) { b.disabled = on; });
        form.classList.toggle('is-busy', on);
    }

    // File inputs: Greek, styled picker with drag & drop (replaces the browser's "Choose file").
    var UP = '<svg class="tw-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/></svg>';
    function enhanceFiles(scope) {
        scope.querySelectorAll('input[type=file]:not([data-tw-file])').forEach(function (inp) {
            inp.setAttribute('data-tw-file', '1');
            var box = document.createElement('label');
            box.className = 'tw-file';
            var empty = 'Δεν επιλέχθηκε αρχείο';
            inp.parentNode.insertBefore(box, inp);
            box.innerHTML = '<span class="tw-file-btn">' + UP + 'Επιλογή αρχείου</span><span class="tw-file-name">' + empty + '</span>';
            box.appendChild(inp);
            var name = box.querySelector('.tw-file-name');
            function show() {
                var f = inp.files && inp.files[0];
                box.classList.toggle('has-file', !!f);
                name.textContent = f ? f.name : empty;
            }
            inp.addEventListener('change', show);
            ['dragenter', 'dragover'].forEach(function (ev) { box.addEventListener(ev, function (e) { e.preventDefault(); box.classList.add('is-drag'); }); });
            ['dragleave', 'drop'].forEach(function (ev) { box.addEventListener(ev, function () { box.classList.remove('is-drag'); }); });
            box.addEventListener('drop', function (e) { e.preventDefault(); if (e.dataTransfer.files.length) { inp.files = e.dataTransfer.files; show(); } });
            if (inp.form) inp.form.addEventListener('reset', function () { setTimeout(show, 0); });
        });
    }

    function bind(root, data) {
        Object.keys(data).forEach(function (k) {
            root.querySelectorAll('[data-bind="' + k + '"]').forEach(function (el) {
                if ('value' in el && el.tagName === 'INPUT') el.value = data[k]; else el.textContent = data[k] || '—';
            });
            root.querySelectorAll('[data-bind-href="' + k + '"]').forEach(function (el) {
                if (data[k]) { el.href = data[k]; el.hidden = false; }
            });
        });
    }

    // ------------------------------------------------------------ registration
    function Register(root) {
        enhanceFiles(root);
        root.querySelectorAll('.tw-ts').forEach(tsRender);
        var state = { serial: '', email: '', token: '', pass: '' };
        var steps = root.querySelectorAll('.tw-step');
        var labels = root.querySelectorAll('.tw-steps li');

        function show(n) {
            steps.forEach(function (s) { s.hidden = s.getAttribute('data-step') !== String(n); });
            labels.forEach(function (l, i) {
                l.classList.toggle('is-current', i + 1 === n);
                l.classList.toggle('is-done', i + 1 < n);
            });
            var first = root.querySelector('.tw-step[data-step="' + n + '"] input:not([readonly]):not([type=hidden]):not([name=tw_website])');
            if (first) first.focus();
        }

        function restart() {
            state = { serial: '', email: '', token: '', pass: '' };
            try { sessionStorage.removeItem('tw_reg'); } catch (e) {}
            root.querySelectorAll('form').forEach(function (f) { f.reset(); });
            alertBox(root, '');
            show(1);
        }

        var f1 = root.querySelector('[data-step="1"]');
        var f2 = root.querySelector('[data-step="2"]');
        var f3 = root.querySelector('[data-step="3"]');

        function sendCode(form) {
            alertBox(root, '');
            var data = { serial: state.serial, email: state.email };
            if (form === f1) {
                var g = guard(f1, root);
                if (!g) return Promise.resolve(false);
                extend(data, g);
            } else {
                // A new code for the same serial and email: the pass from the first send stands in for the challenge.
                extend(data, { tw_website: f1.tw_website.value, tw_t: f1.tw_t.value, pass: state.pass });
            }
            busy(form, true);
            return post('reg_start', data).then(function (r) {
                busy(form, false);
                if (form === f1) tsReset(f1);
                if (!r.success) {
                    alertBox(root, r.data.message);
                    if (form === f1) markField(f1, r.data.field);
                    return false;
                }
                state.serial = r.data.serial;
                state.pass = r.data.pass || '';
                f2.querySelector('.tw-step-info').textContent = r.data.message;
                if (window.TWOtp) { TWOtp.clear(f2.code); TWOtp.timer(f2.code, r.data.ttl); }
                return true;
            });
        }

        f1.addEventListener('submit', function (e) {
            e.preventDefault();
            state.serial = f1.serial.value.trim();
            state.email = f1.email.value.trim();
            if (!state.serial) { alertBox(root, 'Συμπληρώστε τον σειριακό αριθμό.'); markField(f1, 'serial'); return; }
            if (!f1.email.checkValidity() || !state.email) { alertBox(root, 'Συμπληρώστε ένα έγκυρο email.'); markField(f1, 'email'); return; }
            sendCode(f1).then(function (ok) { if (ok) show(2); });
        });

        f2.addEventListener('submit', function (e) {
            e.preventDefault();
            var code = f2.code.value.replace(/\D/g, '');
            if (code.length !== 6) { alertBox(root, 'Ο κωδικός έχει 6 ψηφία.'); markField(f2, 'code'); return; }
            busy(f2, true);
            post('reg_verify', extend({ serial: state.serial, email: state.email, code: code }, guard(f2, root) || {})).then(function (r) {
                busy(f2, false);
                if (!r.success) { alertBox(root, r.data.message); if (window.TWOtp) TWOtp.clear(f2.code); markField(f2, 'code'); return; }
                alertBox(root, '');
                state.token = r.data.token;
                state.product = r.data.product;
                state.verifiedEmail = r.data.email;
                state.serial = r.data.serial;
                try { sessionStorage.setItem('tw_reg', JSON.stringify(state)); } catch (e2) {}
                fillStep3();
                show(3);
            });
        });

        function fillStep3() {
            var p = f3.querySelector('.tw-product');
            p.innerHTML = '';
            p.appendChild(document.createTextNode('Προϊόν: '));
            var b = document.createElement('strong'); b.textContent = (state.product || '—'); p.appendChild(b);
            p.appendChild(document.createTextNode(' · S/N: '));
            var c = document.createElement('code'); c.textContent = state.serial; p.appendChild(c);
            bind(f3, { email: state.verifiedEmail });
        }

        root.querySelectorAll('[data-action="resend"]').forEach(function (b) {
            b.addEventListener('click', function () {
                sendCode(f2).then(function (ok) { if (ok) alertBox(root, 'Στάλθηκε νέος κωδικός.', 'success'); });
            });
        });
        root.querySelectorAll('[data-action="restart"]').forEach(function (b) { b.addEventListener('click', restart); });

        f3.addEventListener('submit', function (e) {
            e.preventDefault();
            alertBox(root, '');
            // Browser-side checks for the obvious; the server validates everything again.
            var req = f3.querySelectorAll('[required]');
            for (var i = 0; i < req.length; i++) {
                var el = req[i];
                var empty = el.type === 'checkbox' ? !el.checked : (el.type === 'file' ? !el.files.length : !el.value.trim());
                if (empty || (el.checkValidity && !el.checkValidity())) {
                    var lbl = el.type === 'checkbox' ? 'Πρέπει να επιλέξετε το υποχρεωτικό πεδίο.' : 'Συμπληρώστε σωστά όλα τα υποχρεωτικά πεδία.';
                    alertBox(root, lbl);
                    markField(f3, el.name);
                    return;
                }
            }
            var file = f3.invoice_file && f3.invoice_file.files[0];
            if (file && file.size > TW.maxMb * 1024 * 1024) {
                alertBox(root, 'Το αρχείο ξεπερνά το όριο των ' + TW.maxMb + ' MB.'); markField(f3, 'invoice_file'); return;
            }
            if (tsToken(f3) === '') { alertBox(root, 'Ολοκληρώστε τον έλεγχο ασφαλείας πάνω από το κουμπί και δοκιμάστε ξανά.'); return; }
            var fd = new FormData(f3); // includes the honeypot, timestamp and Turnstile token
            ['consent_terms', 'consent_accuracy', 'consent_marketing'].forEach(function (k) {
                fd.set(k, f3[k].checked ? '1' : '0');
            });
            fd.append('token', state.token);
            busy(f3, true);
            post('reg_submit', fd).then(function (r) {
                busy(f3, false);
                tsReset(f3);
                if (!r.success) {
                    alertBox(root, r.data.message);
                    markField(f3, r.data.field);
                    if (r.data.field === 'token' || r.data.field === 'serial') {
                        setTimeout(restart, 4000);
                    }
                    return;
                }
                try { sessionStorage.removeItem('tw_reg'); } catch (e3) {}
                bind(root.querySelector('[data-step="4"]'), r.data);
                show(4);
                root.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        // Resume step 3 after an accidental reload (the server keeps the verification valid for 60 minutes).
        try {
            var saved = JSON.parse(sessionStorage.getItem('tw_reg') || 'null');
            if (saved && saved.token) {
                state = saved;
                fillStep3();
                show(3);
            }
        } catch (e4) {}
    }

    // ------------------------------------------------------------ portal
    function Portal(root) {
        var loading = root.querySelector('[data-portal="loading"]');
        var login = root.querySelector('[data-portal="login"]');
        var content = root.querySelector('[data-portal="content"]');
        var l1 = root.querySelector('[data-step="login-1"]');
        var l2 = root.querySelector('[data-step="login-2"]');
        var email = '';
        var csrf = '';
        root.querySelectorAll('.tw-ts').forEach(tsRender);

        function load() {
            post('portal', {}).then(function (r) {
                loading.hidden = true;
                if (r.success && r.data.signed_in) {
                    csrf = r.data.csrf;
                    content.innerHTML = r.data.html;
                    content.hidden = false;
                    login.hidden = true;
                    wire();
                } else {
                    content.hidden = true;
                    login.hidden = false;
                    l1.hidden = false; l2.hidden = true;
                }
            });
        }

        l1.addEventListener('submit', function (e) {
            e.preventDefault();
            email = l1.email.value.trim();
            if (!email || !l1.email.checkValidity()) { alertBox(root, 'Συμπληρώστε ένα έγκυρο email.'); markField(l1, 'email'); return; }
            var g = guard(l1, root);
            if (!g) return;
            busy(l1, true);
            post('login_start', extend({ email: email }, g)).then(function (r) {
                busy(l1, false);
                tsReset(l1);
                if (!r.success) { alertBox(root, r.data.message); return; }
                alertBox(root, '');
                l2.querySelector('.tw-step-info').textContent = r.data.message;
                if (window.TWOtp) { TWOtp.clear(l2.code); TWOtp.timer(l2.code, r.data.ttl); }
                l1.hidden = true; l2.hidden = false;
                l2.code.focus();
            });
        });

        l2.addEventListener('submit', function (e) {
            e.preventDefault();
            busy(l2, true);
            post('login_verify', extend({ email: email, code: l2.code.value }, guard(l2, root) || {})).then(function (r) {
                busy(l2, false);
                if (!r.success) { alertBox(root, r.data.message); if (window.TWOtp) TWOtp.clear(l2.code); markField(l2, 'code'); return; }
                alertBox(root, '');
                l2.reset();
                load();
            });
        });

        root.querySelector('[data-action="login-restart"]').addEventListener('click', function () {
            l2.hidden = true; l1.hidden = false; alertBox(root, '');
        });

        function wire() {
            enhanceFiles(content);
            var out = content.querySelector('[data-action="logout"]');
            if (out) out.addEventListener('click', function () { post('logout', {}).then(load); });

            content.querySelectorAll('[data-action="marketing"]').forEach(function (cb) {
                cb.addEventListener('change', function () {
                    cb.disabled = true;
                    post('portal_marketing', { csrf: csrf, warranty_id: cb.getAttribute('data-warranty'), granted: cb.checked ? '1' : '0' }).then(function (r) {
                        cb.disabled = false;
                        if (!r.success) { cb.checked = !cb.checked; alertBox(root, r.data.message); return; }
                        alertBox(root, r.data.message, 'success');
                    });
                });
            });

            content.querySelectorAll('.tw-service-form').forEach(function (f) {
                f.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var req = f.querySelectorAll('[required]');
                    for (var i = 0; i < req.length; i++) {
                        var el = req[i];
                        var empty = el.type === 'checkbox' ? !el.checked : (el.type === 'file' ? !el.files.length : !el.value.trim());
                        if (empty) { alertBox(root, 'Συμπληρώστε όλα τα υποχρεωτικά πεδία του Service.'); markField(f, el.name); return; }
                    }
                    var file = f.receipt_file && f.receipt_file.files[0];
                    if (file && file.size > TW.maxMb * 1024 * 1024) { alertBox(root, 'Το αρχείο ξεπερνά το όριο των ' + TW.maxMb + ' MB.'); markField(f, 'receipt_file'); return; }
                    var fd = new FormData(f);
                    fd.set('genuine_parts', f.genuine_parts.checked ? '1' : '0');
                    fd.append('csrf', csrf);
                    fd.append('warranty_id', f.getAttribute('data-warranty'));
                    busy(f, true);
                    post('portal_service', fd).then(function (r) {
                        busy(f, false);
                        if (!r.success) { alertBox(root, r.data.message); markField(f, r.data.field); return; }
                        load();
                        alertBox(root, r.data.message, 'success');
                    });
                });
            });

            var exp = content.querySelector('[data-action="export"]');
            if (exp) exp.addEventListener('click', function () {
                post('portal_export', { csrf: csrf }).then(function (r) {
                    if (!r.success) { alertBox(root, r.data.message); return; }
                    var blob = new Blob([JSON.stringify(r.data.data, null, 2)], { type: 'application/json' });
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = r.data.filename;
                    document.body.appendChild(a); a.click(); a.remove();
                });
            });

            var er = content.querySelector('[data-action="erase"]');
            if (er) er.addEventListener('click', function () {
                if (!window.confirm('Θέλετε να υποβάλετε αίτημα διαγραφής των προσωπικών σας δεδομένων;')) return;
                post('portal_erase', { csrf: csrf }).then(function (r) {
                    alertBox(root, r.data.message, r.success ? 'success' : 'error');
                });
            });
        }

        load();
    }

    function boot() {
        document.querySelectorAll('[data-tw="register"]').forEach(Register);
        document.querySelectorAll('[data-tw="portal"]').forEach(Portal);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
