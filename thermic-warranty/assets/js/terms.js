/* Warranty Manager – warranty terms finder ([thermic_warranty_terms]). */
(function () {
    function init(root) {
        var cfg;
        try { cfg = JSON.parse(root.getAttribute('data-tw-terms')); } catch (e) { return; }
        var $ = function (k) { return root.querySelector('[data-f="' + k + '"]'); };
        var date = $('date'), err = $('error');

        function message(text, kind) {
            err.hidden = !text;
            err.textContent = text || '';
            err.className = 'tw-alert ' + (kind === 'info' ? 'tw-alert--info' : 'tw-alert--error');
        }
        function render(e, title) {
            $('title').textContent = title;
            $('period').textContent = e.period;
            var box = $('text');
            box.innerHTML = e.html; // escaped on the server
            if (e.lang !== cfg.lang) box.setAttribute('lang', e.lang); else box.removeAttribute('lang');
            $('label').textContent = e.label;
            $('pdf').href = e.pdf;
        }
        // The latest set whose «from» is on or before the purchase date ('' = the base set, from the start).
        function byDate(d) {
            var pick = null;
            cfg.dated.forEach(function (x) { if (x.from === '' || x.from <= d) pick = x.e; });
            return pick || cfg.latest;
        }
        function onDate() {
            message('');
            var d = date.value;
            if (!d) { render(cfg.latest, cfg.t.latest); return; }
            if (date.max && d > date.max) { message(cfg.t.future); return; }
            render(byDate(d), cfg.t.yours);
        }
        date.addEventListener('change', onDate);
        date.addEventListener('input', onDate);
        root.querySelectorAll('[data-date]').forEach(function (b) {
            b.addEventListener('click', function () { date.value = b.getAttribute('data-date'); onDate(); });
        });

        var form = $('serial-form');
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var serial = $('serial').value.trim();
            if (!serial) return;
            var btn = form.querySelector('button');
            btn.disabled = true;
            message('');
            var fd = new FormData();
            fd.append('action', 'tw_terms_lookup');
            fd.append('serial', serial);
            fd.append('tw_lang', cfg.lang);
            fetch(cfg.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (r) {
                    btn.disabled = false;
                    if (!r.success) { message(r.data && r.data.message ? r.data.message : cfg.t.error); return; }
                    if (r.data.own) { render(r.data, cfg.t.yours); return; }
                    message(cfg.t.bydate, 'info');
                    date.focus();
                })
                .catch(function () { btn.disabled = false; message(cfg.t.error); });
        });
    }
    function boot() { document.querySelectorAll('[data-tw-terms]').forEach(init); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
