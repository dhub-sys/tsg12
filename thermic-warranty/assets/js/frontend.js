/* Thermic Warranty – registration wizard and customer portal. No dependencies. */
(function () {
    'use strict';
    if (typeof window.TW === 'undefined') return;

    // Customer-side language: strings are written in Greek and looked up in TW.i18n (English) when needed.
    function T(s) { return (TW.i18n && TW.i18n[s]) || s; }

    function post(action, data) {
        var fd = data instanceof FormData ? data : new FormData();
        if (!(data instanceof FormData) && data) {
            Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        }
        fd.append('action', 'tw_' + action);
        if (TW.lang) fd.append('tw_lang', TW.lang);
        return fetch(TW.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) {
                return r.json().catch(function () { return { success: false, data: { message: T('Σφάλμα επικοινωνίας με τον server.') } }; });
            })
            .catch(function () { return { success: false, data: { message: T('Δεν υπάρχει σύνδεση. Δοκιμάστε ξανά.') } }; });
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
        if (t === '') { alertBox(root, T('Ολοκληρώστε τον έλεγχο ασφαλείας πάνω από το κουμπί και δοκιμάστε ξανά.')); return false; }
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
            var empty = T('Δεν επιλέχθηκε αρχείο');
            inp.parentNode.insertBefore(box, inp);
            box.innerHTML = '<span class="tw-file-btn">' + UP + T('Επιλογή αρχείου') + '</span><span class="tw-file-name">' + empty + '</span>';
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

    // ------------------------------------------------------------ printed warranty card
    // Prints a card as shown, with the logo on top, on a single page: a copy is put straight under <body>
    // and the print styles show only that copy. The title becomes the PDF file name ("code - customer").
    function printCard(src, title) {
        var old = document.getElementById('tw-print-card');
        if (old) old.remove();
        var holder = document.createElement('div');
        holder.id = 'tw-print-card';
        holder.className = 'tw-app tw-register';
        var card = src.cloneNode(true);
        card.hidden = false;
        card.removeAttribute('data-step');
        card.querySelectorAll('.tw-done-actions').forEach(function (e) { e.remove(); });
        card.querySelectorAll('.tw-card-logo').forEach(function (e) { e.hidden = false; });
        holder.appendChild(card);
        document.body.appendChild(holder);
        var oldTitle = document.title;
        if (title) document.title = title;
        document.documentElement.classList.add('tw-printing-card');
        var done = false;
        var undo = function () {
            if (done) return; done = true;
            document.title = oldTitle;
            document.documentElement.classList.remove('tw-printing-card');
            holder.remove();
            window.removeEventListener('afterprint', undo);
        };
        window.addEventListener('afterprint', undo);
        var img = holder.querySelector('img');
        var go = function () { window.print(); setTimeout(undo, 1500); };
        if (img && !img.complete) { img.addEventListener('load', go, { once: true }); img.addEventListener('error', go, { once: true }); } else go();
    }

    // ------------------------------------------------------------ registration
    function Register(root) {
        enhanceFiles(root);
        root.querySelectorAll('.tw-ts').forEach(tsRender);
        // Steps: 1 serial · 2 email · 3 code · 4 details · 5 terms & consents (same form as 4) · 6 done
        var state = { serial: '', email: '', token: '', pass: '', product: '' };
        var steps = root.querySelectorAll('.tw-step');
        var labels = root.querySelectorAll('.tw-steps li');
        var f1 = root.querySelector('[data-step="1"]');
        var f2 = root.querySelector('[data-step="2"]');
        var f3 = root.querySelector('[data-step="3"]');
        var f4 = root.querySelector('[data-step="4"]');
        var ASK = T('Ολοκληρώστε τον έλεγχο ασφαλείας πάνω από το κουμπί και δοκιμάστε ξανά.');

        var partDetails = f4.querySelector('[data-part="details"]'), partTerms = f4.querySelector('[data-part="terms"]');
        function show(n) {
            var dom = n === 5 ? 4 : n; // step 5 is the second part of the step-4 form
            steps.forEach(function (s) { s.hidden = s.getAttribute('data-step') !== String(dom); });
            if (dom === 4) { partDetails.hidden = n !== 4; partTerms.hidden = n !== 5; }
            labels.forEach(function (l, i) {
                l.classList.toggle('is-current', i + 1 === n);
                l.classList.toggle('is-done', i + 1 < n);
            });
            var scope = n === 5 ? partTerms : (n === 4 ? partDetails : root.querySelector('.tw-step[data-step="' + dom + '"]'));
            var first = scope && n !== 5 ? scope.querySelector('input:not([readonly]):not([type=hidden]):not([name=tw_website])') : null;
            if (first) first.focus();
            if (n === 5) { var bx = partTerms.querySelector('[data-terms-box]'); if (bx) bx.focus({ preventScroll: true }); root.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        }
        // A field reported by the server may be in the other part of the form: show that part first.
        function markF4(name) {
            var f = name ? f4.querySelector('[name="' + name + '"]') : null;
            if (f) show(partTerms.contains(f) ? 5 : 4);
            markField(f4, name);
        }
        // Required fields of one part; returns false (and marks the field) if one is missing.
        function checkPart(part) {
            var req = part.querySelectorAll('[required]');
            for (var i = 0; i < req.length; i++) {
                var el = req[i];
                var empty = el.type === 'checkbox' ? !el.checked : (el.type === 'file' ? !el.files.length : !el.value.trim());
                if (empty || (el.checkValidity && !el.checkValidity())) {
                    alertBox(root, el.type === 'checkbox' ? T('Πρέπει να επιλέξετε το υποχρεωτικό πεδίο.') : T('Συμπληρώστε σωστά όλα τα υποχρεωτικά πεδία.'));
                    markField(f4, el.name);
                    return false;
                }
            }
            return true;
        }

        function restart() {
            state = { serial: '', email: '', token: '', pass: '', product: '' };
            try { sessionStorage.removeItem('tw_reg'); } catch (e) {}
            root.querySelectorAll('form').forEach(function (f) { f.reset(); });
            tsReset(f1);
            alertBox(root, '');
            show(1);
        }

        function productLine(el, prefix) {
            el.innerHTML = '';
            el.appendChild(document.createTextNode(prefix));
            var b = document.createElement('strong'); b.textContent = (state.product || '—'); el.appendChild(b);
            el.appendChild(document.createTextNode(' · S/N: '));
            var c = document.createElement('code'); c.textContent = state.serial; el.appendChild(c);
        }

        // Step 1: is the serial on the list and still free?
        f1.addEventListener('submit', function (e) {
            e.preventDefault();
            alertBox(root, '');
            var serial = f1.serial.value.trim();
            if (!serial) { alertBox(root, T('Συμπληρώστε τον σειριακό αριθμό.')); markField(f1, 'serial'); return; }
            var g = guard(f1, root);
            if (!g) return;
            busy(f1, true);
            post('reg_check', extend({ serial: serial }, g)).then(function (r) {
                busy(f1, false);
                tsReset(f1);
                if (!r.success) { alertBox(root, r.data.message); markField(f1, r.data.field); return; }
                state.serial = r.data.serial;
                state.product = r.data.product;
                state.pass = r.data.pass;
                productLine(f2.querySelector('.tw-product'), '✔ ' + T('Βρέθηκε:') + ' ');
                show(2);
            });
        });

        function sendCode(form) {
            alertBox(root, '');
            busy(form, true);
            var data = { serial: state.serial, email: state.email, pass: state.pass, tw_website: form.tw_website.value, tw_t: form.tw_t.value };
            return post('reg_start', data).then(function (r) {
                busy(form, false);
                if (!r.success) {
                    alertBox(root, r.data.message);
                    if (r.data.field === 'restart') { setTimeout(restart, 3000); return false; }
                    if (form === f2) markField(f2, r.data.field);
                    return false;
                }
                f3.querySelector('.tw-step-info').textContent = r.data.message;
                if (window.TWOtp) { TWOtp.clear(f3.code); TWOtp.timer(f3.code, r.data.ttl); }
                return true;
            });
        }

        // Step 2: email → code
        f2.addEventListener('submit', function (e) {
            e.preventDefault();
            state.email = f2.email.value.trim();
            if (!f2.email.checkValidity() || !state.email) { alertBox(root, T('Συμπληρώστε ένα έγκυρο email.')); markField(f2, 'email'); return; }
            sendCode(f2).then(function (ok) { if (ok) show(3); });
        });

        // Step 3: verify the code
        f3.addEventListener('submit', function (e) {
            e.preventDefault();
            var code = f3.code.value.replace(/\D/g, '');
            if (code.length !== 6) { alertBox(root, T('Ο κωδικός έχει 6 ψηφία.')); markField(f3, 'code'); return; }
            busy(f3, true);
            post('reg_verify', extend({ serial: state.serial, email: state.email, code: code }, guard(f3, root) || {})).then(function (r) {
                busy(f3, false);
                if (!r.success) { alertBox(root, r.data.message); if (window.TWOtp) TWOtp.clear(f3.code); markField(f3, 'code'); return; }
                alertBox(root, '');
                state.token = r.data.token;
                state.product = r.data.product;
                state.verifiedEmail = r.data.email;
                state.serial = r.data.serial;
                state.terms = r.data.terms || [];
                try { sessionStorage.setItem('tw_reg', JSON.stringify(state)); } catch (e2) {}
                fillDetails();
                show(4);
            });
        });

        function fillDetails() {
            productLine(f4.querySelector('.tw-product'), T('Προϊόν') + ': ');
            bind(f4, { email: state.verifiedEmail });
            showTerms();
        }

        // The terms that come with this product: the serial's own set, or the set whose period contains the
        // purchase date. Until the date is entered (and more than one set could apply) nothing can be accepted.
        function showTerms() {
            var list = state.terms || [];
            var date = f4.purchase_date ? f4.purchase_date.value : '';
            var pick = null;
            if (list.length === 1) pick = list[0];
            else if (date) {
                // The period that contains the purchase date (the latest start wins); otherwise the base terms.
                list.forEach(function (t) {
                    if ((t.from === '' || t.from <= date) && (!t.to || date <= t.to) && (!pick || t.from >= pick.from)) pick = t;
                });
                if (!pick) list.forEach(function (t) { if (t.base) pick = t; });
            }
            var box = f4.querySelector('[data-terms-box]');
            var label = f4.querySelector('[data-terms-label]');
            var accept = f4.consent_terms;
            if (!pick) {
                box.innerHTML = '<p class="tw-hint"></p>';
                box.firstChild.textContent = T('Συμπληρώστε την ημερομηνία αγοράς για να δείτε τους όρους εγγύησης που ισχύουν για το προϊόν σας.');
                label.textContent = '';
                f4.terms_set.value = f4.terms_version.value = f4.terms_lang.value = '';
                accept.checked = false;
                accept.disabled = true;
                return;
            }
            if (f4.terms_set.value !== String(pick.set) || f4.terms_version.value !== String(pick.version)) accept.checked = false;
            box.innerHTML = pick.html; // server-escaped text
            if (pick.lang !== (TW.lang || 'el')) box.setAttribute('lang', pick.lang); else box.removeAttribute('lang');
            label.textContent = pick.label;
            f4.terms_set.value = pick.set;
            f4.terms_version.value = pick.version;
            f4.terms_lang.value = pick.lang;
            accept.disabled = false;
        }
        if (f4.purchase_date) {
            f4.purchase_date.addEventListener('change', showTerms);
            f4.purchase_date.addEventListener('input', showTerms);
        }

        root.querySelectorAll('[data-action="resend"]').forEach(function (b) {
            b.addEventListener('click', function () {
                sendCode(f3).then(function (ok) { if (ok) { alertBox(root, T('Στάλθηκε νέος κωδικός.'), 'success'); f3.code.focus(); } });
            });
        });
        root.querySelectorAll('[data-action="change-email"]').forEach(function (b) {
            b.addEventListener('click', function () { alertBox(root, ''); show(2); });
        });
        root.querySelectorAll('[data-action="restart"]').forEach(function (b) { b.addEventListener('click', restart); });

        root.querySelectorAll('[data-action="print-card"]').forEach(function (b) {
            b.addEventListener('click', function () {
                printCard(root.querySelector('[data-step="6"]'), state.done ? state.done.warranty_code + (state.done.customer_name ? ' - ' + state.done.customer_name : '') : '');
            });
        });

        // Step 4: details, terms, consents → warranty code
        f4.querySelectorAll('[data-action="back-details"]').forEach(function (b) {
            b.addEventListener('click', function () { alertBox(root, ''); show(4); });
        });
        f4.addEventListener('submit', function (e) {
            e.preventDefault();
            alertBox(root, '');
            // Browser-side checks for the obvious; the server validates everything again.
            if (!partDetails.hidden) {
                // First part: details → on to the terms.
                if (!checkPart(partDetails)) return;
                var file = f4.invoice_file && f4.invoice_file.files[0];
                if (file && file.size > TW.maxMb * 1024 * 1024) {
                    alertBox(root, T('Το αρχείο ξεπερνά το όριο των %d MB.').replace('%d', TW.maxMb)); markField(f4, 'invoice_file'); return;
                }
                showTerms();
                show(5);
                return;
            }
            if (!checkPart(partTerms)) return;
            if (tsToken(f4) === '') { alertBox(root, ASK); return; }
            var fd = new FormData(f4); // includes the honeypot, timestamp and Turnstile token
            ['consent_terms', 'consent_accuracy', 'consent_marketing'].forEach(function (k) {
                fd.set(k, f4[k].checked ? '1' : '0');
            });
            fd.append('token', state.token);
            busy(f4, true);
            post('reg_submit', fd).then(function (r) {
                busy(f4, false);
                tsReset(f4);
                if (!r.success) {
                    alertBox(root, r.data.message);
                    markF4(r.data.field);
                    if (r.data.field === 'token' || r.data.field === 'serial') {
                        setTimeout(restart, 4000);
                    }
                    return;
                }
                try { sessionStorage.removeItem('tw_reg'); } catch (e3) {}
                state.done = r.data;
                bind(root.querySelector('[data-step="6"]'), r.data);
                show(6);
                root.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        // Resume the details step after an accidental reload (the server keeps the verification valid for 60 minutes).
        try {
            var saved = JSON.parse(sessionStorage.getItem('tw_reg') || 'null');
            if (saved && saved.token) {
                state = saved;
                fillDetails();
                show(4);
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

        function load(after) {
            post('portal', {}).then(function (r) {
                loading.hidden = true;
                if (r.success && r.data.signed_in) {
                    csrf = r.data.csrf;
                    content.innerHTML = r.data.html;
                    content.hidden = false;
                    login.hidden = true;
                    wire();
                    if (typeof after === 'function') after();
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
            if (!email || !l1.email.checkValidity()) { alertBox(root, T('Συμπληρώστε ένα έγκυρο email.')); markField(l1, 'email'); return; }
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
            taxIds(content);
            var out = content.querySelector('[data-action="logout"]');
            if (out) out.addEventListener('click', function () { post('logout', {}).then(load); });

            // Marketing consent: one choice for all products, saved explicitly.
            var mf = content.querySelector('[data-action="marketing-form"]');
            if (mf) {
                var btn = mf.querySelector('button[type=submit]');
                var initial = (mf.querySelector('input[name=marketing]:checked') || {}).value || '';
                mf.addEventListener('change', function () {
                    var v = (mf.querySelector('input[name=marketing]:checked') || {}).value || '';
                    btn.disabled = !v || v === initial;
                });
                mf.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var v = (mf.querySelector('input[name=marketing]:checked') || {}).value;
                    if (!v) return;
                    if (v === '0' && !window.confirm(T('Να ανακληθεί η συγκατάθεσή σας για ενημερώσεις και προσφορές, για όλα τα προϊόντα σας;'))) return;
                    btn.disabled = true;
                    post('portal_marketing', { csrf: csrf, granted: v }).then(function (r) {
                        if (!r.success) { btn.disabled = false; alertBox(root, r.data.message); return; }
                        load(function () { alertBox(root, r.data.message, 'success'); var box = content.querySelector('#tw-my-data'); if (box) box.scrollIntoView({ block: 'center' }); });
                    });
                });
            }

            // Language for emails and SMS: one choice for all products.
            var lf = content.querySelector('[data-action="lang-form"]');
            if (lf) {
                var lbtn = lf.querySelector('button[type=submit]');
                var linit = (lf.querySelector('input[name=comm_lang]:checked') || {}).value || '';
                lf.addEventListener('change', function () { lbtn.disabled = ((lf.querySelector('input[name=comm_lang]:checked') || {}).value || '') === linit; });
                lf.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var v = (lf.querySelector('input[name=comm_lang]:checked') || {}).value;
                    if (!v) return;
                    lbtn.disabled = true;
                    post('portal_lang', { csrf: csrf, comm_lang: v }).then(function (r) {
                        if (!r.success) { lbtn.disabled = false; alertBox(root, r.data.message); return; }
                        load(function () { alertBox(root, r.data.message, 'success'); var box = content.querySelector('#tw-my-data'); if (box) box.scrollIntoView({ block: 'center' }); });
                    });
                });
            }

                        // Warranty card: show / hide / print, per product.
            content.querySelectorAll('[data-action="show-card"]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var view = b.closest('article').querySelector('.tw-card-view');
                    view.hidden = !view.hidden;
                    b.setAttribute('aria-expanded', String(!view.hidden));
                    if (!view.hidden) view.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                });
            });
            content.querySelectorAll('.tw-card-view').forEach(function (view) {
                view.querySelector('[data-action="hide-card"]').addEventListener('click', function () {
                    view.hidden = true;
                    view.closest('article').querySelector('[data-action="show-card"]').setAttribute('aria-expanded', 'false');
                });
                view.querySelector('[data-action="print-card"]').addEventListener('click', function () {
                    printCard(view, view.getAttribute('data-print-title'));
                });
            });

            content.querySelectorAll('.tw-service-form').forEach(function (f) {
                f.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var req = f.querySelectorAll('[required]');
                    for (var i = 0; i < req.length; i++) {
                        var el = req[i];
                        var empty = el.type === 'checkbox' ? !el.checked : (el.type === 'file' ? !el.files.length : !el.value.trim());
                        if (empty) { alertBox(root, T('Συμπληρώστε όλα τα υποχρεωτικά πεδία του Service.')); markField(f, el.name); return; }
                    }
                    var file = f.receipt_file && f.receipt_file.files[0];
                    if (file && file.size > TW.maxMb * 1024 * 1024) { alertBox(root, T('Το αρχείο ξεπερνά το όριο των %d MB.').replace('%d', TW.maxMb)); markField(f, 'receipt_file'); return; }
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

            content.querySelectorAll('.tw-claim-form').forEach(function (f) {
                f.addEventListener('submit', function (e) {
                    e.preventDefault();
                    if (f.description.value.trim().length < 10) { alertBox(root, T('Περιγράψτε το πρόβλημα (τουλάχιστον 10 χαρακτήρες).')); markField(f, 'description'); return; }
                    var input = f.querySelector('input[type=file]');
                    var files = input ? input.files : [];
                    if (files.length > 5) { alertBox(root, T('Επισυνάψτε έως %d αρχεία.').replace('%d', 5)); markField(f, 'claim_files[]'); return; }
                    for (var i = 0; i < files.length; i++) {
                        if (files[i].size > TW.maxMb * 1024 * 1024) { alertBox(root, T('Το αρχείο ξεπερνά το όριο των %d MB.').replace('%d', TW.maxMb)); markField(f, 'claim_files[]'); return; }
                    }
                    var fd = new FormData(f);
                    fd.append('csrf', csrf);
                    fd.append('warranty_id', f.getAttribute('data-warranty'));
                    busy(f, true);
                    post('portal_claim', fd).then(function (r) {
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

            // «Διαγραφή των στοιχείων μου»: a page of its own (#erase), with explanation, tick box and emailed code.
            var main = content.querySelector('[data-portal-main]'), ev = content.querySelector('[data-portal-erase]');
            if (main && ev) {
                var e1 = ev.querySelector('[data-step="erase-1"]'), e2 = ev.querySelector('[data-step="erase-2"]');
                function eraseView(on) {
                    alertBox(root, '');
                    main.hidden = on; ev.hidden = !on;
                    if (on && location.hash !== '#erase') history.pushState(null, '', '#erase');
                    if (!on && location.hash === '#erase') history.pushState(null, '', location.pathname + location.search);
                    root.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
                content.querySelectorAll('[data-action="erase-view"]').forEach(function (b) { b.addEventListener('click', function () { eraseView(true); }); });
                ev.querySelectorAll('[data-action="erase-back"]').forEach(function (b) { b.addEventListener('click', function () { eraseView(false); }); });
                if (!window.twErasePop) { window.twErasePop = true; window.addEventListener('popstate', function () { var v = document.querySelector('[data-portal-erase]'); if (v) v.dispatchEvent(new Event('tw-hash')); }); }
                ev.addEventListener('tw-hash', function () { eraseView(location.hash === '#erase'); });
                if (location.hash === '#erase') eraseView(true);
                e1.confirm.addEventListener('change', function () { e1.querySelector('button').disabled = !e1.confirm.checked; });
                function sendCode() {
                    busy(e1, true);
                    return post('portal_erase_start', { csrf: csrf, confirm: e1.confirm.checked ? '1' : '0' }).then(function (r) {
                        busy(e1, false); e1.querySelector('button').disabled = !e1.confirm.checked;
                        if (!r.success) { alertBox(root, r.data.message); return false; }
                        e2.querySelector('.tw-step-info').textContent = r.data.message;
                        e1.hidden = true; e2.hidden = false;
                        if (window.TWOtp) { TWOtp.clear(e2.code); TWOtp.timer(e2.code, r.data.ttl); }
                        return true;
                    });
                }
                e1.addEventListener('submit', function (e) { e.preventDefault(); alertBox(root, ''); if (e1.confirm.checked) sendCode(); });
                ev.querySelector('[data-action="erase-resend"]').addEventListener('click', function () { alertBox(root, ''); sendCode(); });
                e2.addEventListener('submit', function (e) {
                    e.preventDefault();
                    alertBox(root, '');
                    if (!/^\d{6}$/.test(e2.code.value.trim())) { alertBox(root, T('Πληκτρολογήστε τον 6ψήφιο κωδικό.')); markField(e2, 'code'); return; }
                    busy(e2, true);
                    post('portal_erase_confirm', { csrf: csrf, code: e2.code.value.trim() }).then(function (r) {
                        busy(e2, false);
                        if (!r.success) { alertBox(root, r.data.message); if (window.TWOtp) TWOtp.clear(e2.code); markField(e2, 'code'); return; }
                        e2.hidden = true;
                        ev.querySelectorAll('.tw-erase-text, .tw-hint, [data-action="erase-back"]').forEach(function (x) { x.hidden = true; });
                        var done = ev.querySelector('.tw-erase-done'); done.hidden = false; done.querySelector('p').textContent = r.data.message;
                        history.replaceState(null, '', location.pathname + location.search);
                    });
                });
            }
        }

        load();
    }

    // Tax number with country prefix: numeric keypad and hint follow the chosen country.
    function taxIds(scope) {
        scope.querySelectorAll('[data-tw-taxid]:not([data-ready])').forEach(function (box) {
            box.setAttribute('data-ready', '1');
            var sel = box.querySelector('select'), inp = box.querySelector('input');
            function label(full) { [].forEach.call(sel.options, function (o) { o.textContent = o.getAttribute(full ? 'data-full' : 'data-short'); }); }
            function upd() {
                var o = sel.options[sel.selectedIndex];
                inp.placeholder = o.getAttribute('data-ph') || '';
                if (sel.value === 'GR') inp.setAttribute('inputmode', 'numeric'); else inp.removeAttribute('inputmode');
                sel.title = o.getAttribute('title') || '';
            }
            ['focus', 'mousedown', 'touchstart', 'keydown'].forEach(function (ev) { sel.addEventListener(ev, function () { label(true); }, { passive: true }); });
            sel.addEventListener('blur', function () { label(false); });
            sel.addEventListener('change', function () { upd(); label(false); inp.focus(); });
            label(false); upd();
        });
    }
    window.TWTaxIds = taxIds;

    function boot() {
        taxIds(document);
        document.querySelectorAll('[data-tw="register"]').forEach(Register);
        document.querySelectorAll('[data-tw="portal"]').forEach(Portal);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
