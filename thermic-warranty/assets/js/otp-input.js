/* One box per digit for the 6-digit email codes, with a countdown to the code's expiry.
   The original <input> stays in the form (visually hidden) and always holds the full code, so forms and
   scripts keep reading it as before. Paste, SMS/email autofill, Backspace and arrow keys are handled.
   Usage: automatic for input.tw-otp / input.tw-code-input; TWOtp.timer(input, seconds) restarts the countdown. */
(function () {
    'use strict';
    var N = 6;
    function T(x) { return (window.TW && TW.i18n && TW.i18n[x]) || x; }

    function digits(v) { return String(v || '').replace(/\D/g, ''); }
    function fmt(s) { var m = Math.floor(s / 60), r = s % 60; return m + ':' + (r < 10 ? '0' : '') + r; }

    function enhance(inp) {
        if (inp.getAttribute('data-otp-boxes')) return;
        inp.setAttribute('data-otp-boxes', '1');
        inp.classList.add('tw-otp-source');
        inp.setAttribute('tabindex', '-1');
        inp.setAttribute('aria-hidden', 'true');
        var autofocus = inp.hasAttribute('autofocus');
        inp.removeAttribute('autofocus');

        var wrap = document.createElement('div');
        wrap.className = 'tw-otp-boxes';
        wrap.setAttribute('role', 'group');
        wrap.setAttribute('aria-label', T('Κωδικός 6 ψηφίων'));
        var boxes = [];
        for (var i = 0; i < N; i++) {
            var b = document.createElement('input');
            b.type = 'text';
            b.inputMode = 'numeric';
            b.className = 'tw-otp-box';
            b.setAttribute('maxlength', i === 0 ? String(N) : '1'); // the first box accepts a whole autofilled code
            b.setAttribute('autocomplete', i === 0 ? 'one-time-code' : 'off');
            b.setAttribute('aria-label', T('Ψηφίο %d').replace('%d', i + 1));
            b.setAttribute('pattern', '[0-9]*');
            wrap.appendChild(b);
            boxes.push(b);
        }
        inp.parentNode.insertBefore(wrap, inp.nextSibling);

        var timer = document.createElement('p');
        timer.className = 'tw-otp-timer';
        timer.hidden = true;
        timer.setAttribute('aria-live', 'polite');
        wrap.parentNode.insertBefore(timer, wrap.nextSibling);

        function sync(submitIfFull) {
            var code = boxes.map(function (b) { return b.value; }).join('');
            inp.value = code;
            wrap.classList.toggle('is-full', code.length === N);
            if (submitIfFull && code.length === N && !inp.hasAttribute('data-no-autosubmit') && inp.form && !inp.form.classList.contains('is-busy')) {
                if (inp.form.requestSubmit) inp.form.requestSubmit(); else inp.form.submit();
            }
        }
        function fill(from, text) {
            var d = digits(text).split('');
            for (var k = from; k < N && d.length; k++) boxes[k].value = d.shift();
            var next = boxes.findIndex ? boxes.findIndex(function (b) { return !b.value; }) : -1;
            (boxes[next < 0 ? N - 1 : next]).focus();
            sync(true);
        }

        boxes.forEach(function (b, idx) {
            b.addEventListener('input', function () {
                var d = digits(b.value);
                if (d.length > 1) { b.value = ''; fill(idx, d); return; }
                b.value = d;
                if (d && idx < N - 1) boxes[idx + 1].focus();
                sync(true);
            });
            b.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && !b.value && idx > 0) { boxes[idx - 1].value = ''; boxes[idx - 1].focus(); sync(false); e.preventDefault(); }
                else if (e.key === 'ArrowLeft' && idx > 0) { boxes[idx - 1].focus(); e.preventDefault(); }
                else if (e.key === 'ArrowRight' && idx < N - 1) { boxes[idx + 1].focus(); e.preventDefault(); }
            });
            b.addEventListener('paste', function (e) {
                var t = (e.clipboardData || window.clipboardData).getData('text');
                if (digits(t)) { e.preventDefault(); fill(idx, t); }
            });
            b.addEventListener('focus', function () { b.select(); });
        });

        // Focus requests on the hidden input (e.g. after a wrong code) go to the first empty box.
        inp.addEventListener('focus', function () {
            var first = boxes.filter(function (b) { return !b.value; })[0] || boxes[N - 1];
            first.focus();
        });
        if (inp.form) inp.form.addEventListener('reset', function () { setTimeout(function () { set(''); }, 0); });

        function set(v) {
            var d = digits(v);
            boxes.forEach(function (b, k) { b.value = d[k] || ''; });
            sync(false);
        }
        // Lets scripts clear or set the code: input.twOtpSet('')
        inp.twOtpSet = set;
        if (inp.value) set(inp.value);

        var tick = null;
        inp.twOtpTimer = function (seconds) {
            clearInterval(tick);
            seconds = parseInt(seconds, 10) || 0;
            if (seconds <= 0) { timer.hidden = true; return; }
            var end = Date.now() + seconds * 1000;
            timer.hidden = false;
            function step() {
                var left = Math.max(0, Math.round((end - Date.now()) / 1000));
                timer.classList.toggle('is-low', left <= 30);
                timer.classList.toggle('is-expired', left === 0);
                timer.textContent = left > 0
                    ? T('Ο κωδικός λήγει σε %s').replace('%s', fmt(left))
                    : (inp.getAttribute('data-expired-text') || T('Ο κωδικός έληξε. Ζητήστε νέο κωδικό.'));
                if (!left) clearInterval(tick);
            }
            step();
            tick = setInterval(step, 1000);
        };
        if (inp.getAttribute('data-ttl')) inp.twOtpTimer(inp.getAttribute('data-ttl'));

        // Focus the first box once it is on screen (hidden steps are focused by their own scripts).
        if (autofocus && wrap.offsetParent !== null) boxes[0].focus();
    }

    function scan(root) {
        (root || document).querySelectorAll('input.tw-otp, input.tw-code-input').forEach(enhance);
    }

    window.TWOtp = {
        scan: scan,
        timer: function (inp, seconds) { enhance(inp); inp.twOtpTimer(seconds); },
        clear: function (inp) { if (inp.twOtpSet) inp.twOtpSet(''); }
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { scan(); }); else scan();
})();
