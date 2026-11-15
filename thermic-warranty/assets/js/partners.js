/* Warranty Manager – partner / authorized Service finder ([thermic_warranty_partners]). */
(function () {
    function init(root) {
        var cfg;
        try { cfg = JSON.parse(root.getAttribute('data-tw-partners')); } catch (e) { return; }
        var $ = function (k) { return root.querySelector('[data-f="' + k + '"]'); };
        var sc = $('country'), sr = $('region'), sa = $('area'), list = $('list'), status = $('status');
        // Alphabetical, ignoring accents and capitals (Άλφα = ΑΛΦΑ = αλφα); numbers in natural order.
        var cmp = function (a, b) { return a.localeCompare(b, cfg.lang === 'en' ? 'en' : 'el', { sensitivity: 'base', numeric: true }); };
        var uniq = function (arr) { return arr.filter(function (v, i) { return v && arr.indexOf(v) === i; }).sort(cmp); };
        var label = function (country, r) { return country === 'GR' && cfg.regionsEn[r] ? cfg.regionsEn[r] : r; };
        var fill = function (sel, items, first, labelFn) {
            sel.innerHTML = '';
            if (first) { var o = document.createElement('option'); o.value = ''; o.textContent = first; sel.appendChild(o); }
            items.forEach(function (v) { var o = document.createElement('option'); o.value = v; o.textContent = labelFn ? labelFn(v) : v; sel.appendChild(o); });
        };
        var el = function (tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text) e.textContent = text; return e; };
        var link = function (href, text, ext) { var a = el('a', '', text); a.href = href; if (ext) { a.target = '_blank'; a.rel = 'noopener'; } return a; };

        Object.keys(cfg.countries).forEach(function (c) { var o = document.createElement('option'); o.value = c; o.textContent = cfg.countries[c]; sc.appendChild(o); });
        sc.value = cfg['default'];

        function inCountry() { return cfg.partners.filter(function (p) { return p.c === sc.value; }); }
        function onCountry() {
            var gr = sc.value === 'GR';
            root.querySelector('[data-l="region"]').textContent = gr ? cfg.t.region_gr : cfg.t.region;
            var regions = uniq(inCountry().map(function (p) { return p.r; }));
            fill(sr, regions, regions.length > 1 ? cfg.t.choose : '', function (r) { return label(sc.value, r); });
            onRegion();
        }
        function onRegion() {
            var ps = inCountry().filter(function (p) { return p.r === sr.value; });
            var areas = uniq(ps.map(function (p) { return p.a; }));
            fill(sa, areas, cfg.t.all_areas);
            root.querySelector('[data-wrap="area"]').hidden = !sr.value || areas.length < 2;
            render();
        }
        function render() {
            list.innerHTML = '';
            if (!sr.value) { status.textContent = sc.value === 'GR' ? cfg.t.pick : cfg.t.pick_other; return; }
            var ps = inCountry().filter(function (p) { return p.r === sr.value && (!sa.value || p.a === sa.value); });
            ps.sort(function (a, b) { return cmp(a.n, b.n); });
            status.textContent = ps.length === 0 ? cfg.t.none : (ps.length === 1 ? cfg.t.found1 : cfg.t.foundN.replace('%d', ps.length));
            ps.forEach(function (p) {
                var card = el('article', 'tw-card tw-partner');
                card.appendChild(el('h4', '', p.n));
                // One line: address | phone | phone | email | website | map pin. Each item stays whole and moves
                // to the next line when it does not fit; the separator at the start of a line is clipped away.
                var ul = el('ul', 'tw-partner-line');
                var item = function (cls, child) { var li = el('li', cls); li.appendChild(child); ul.appendChild(li); };
                var where = [p.ad, [p.pc, p.a].filter(Boolean).join(' ')].filter(Boolean).join(', ');
                if (where) item('tw-partner-addr', document.createTextNode(where));
                (p.ph || '').split(/\s*[,;\/]\s*/).filter(Boolean).forEach(function (t) {
                    item('', link('tel:' + t.replace(/[^\d+]/g, ''), t));
                });
                if (p.em) item('', link('mailto:' + p.em, p.em));
                if (p.w) item('', link(p.w, p.w.replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, ''), true));
                if (where) {
                    // Pin (UIkit «location» icon) opening the place in Google Maps.
                    var pin = link('https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(p.q || p.n), '', true);
                    pin.className = 'tw-partner-pin';
                    pin.title = cfg.t.map;
                    pin.setAttribute('aria-label', cfg.t.map + ': ' + p.n);
                    pin.innerHTML = '<svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="1.01" d="M10,0.5 C6.41,0.5 3.5,3.39 3.5,6.98 C3.5,11.83 10,19 10,19 C10,19 16.5,11.83 16.5,6.98 C16.5,3.39 13.59,0.5 10,0.5 L10,0.5 Z"></path><circle fill="none" stroke="currentColor" cx="10" cy="6.8" r="2.3"></circle></svg>';
                    item('tw-partner-map', pin);
                }
                var wrap = el('div', 'tw-partner-linewrap');
                wrap.appendChild(ul);
                card.appendChild(wrap);
                if (p.no) card.appendChild(el('p', 'tw-hint', p.no));
                list.appendChild(card);
            });
        }
        sc.addEventListener('change', onCountry);
        sr.addEventListener('change', onRegion);
        sa.addEventListener('change', render);
        onCountry();
    }
    function boot() { document.querySelectorAll('[data-tw-partners]').forEach(init); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
