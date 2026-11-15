/* Warranty Manager – partner / authorized Service finder ([thermic_warranty_partners]). */
(function () {
    function init(root) {
        var cfg;
        try { cfg = JSON.parse(root.getAttribute('data-tw-partners')); } catch (e) { return; }
        var $ = function (k) { return root.querySelector('[data-f="' + k + '"]'); };
        var sc = $('country'), sr = $('region'), sa = $('area'), list = $('list'), status = $('status');
        var cmp = function (a, b) { return a.localeCompare(b, cfg.lang === 'en' ? 'en' : 'el'); };
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
            ps.sort(function (a, b) { return cmp(a.a || '', b.a || '') || cmp(a.n, b.n); });
            status.textContent = ps.length === 0 ? cfg.t.none : (ps.length === 1 ? cfg.t.found1 : cfg.t.foundN.replace('%d', ps.length));
            ps.forEach(function (p) {
                var card = el('article', 'tw-card tw-partner');
                card.appendChild(el('h4', '', p.n));
                var where = [p.ad, [p.pc, p.a].filter(Boolean).join(' ')].filter(Boolean).join(', ');
                if (where) card.appendChild(el('p', 'tw-partner-addr', where));
                var ul = el('ul', 'tw-partner-contact');
                (p.ph || '').split(/\s*[,;\/]\s*/).filter(Boolean).forEach(function (t) {
                    var li = el('li'); li.appendChild(link('tel:' + t.replace(/[^\d+]/g, ''), t)); ul.appendChild(li);
                });
                if (p.em) { var li = el('li'); li.appendChild(link('mailto:' + p.em, p.em)); ul.appendChild(li); }
                if (p.w) { var lw = el('li'); lw.appendChild(link(p.w, p.w.replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, ''), true)); ul.appendChild(lw); }
                if (where) {
                    var q = p.q || p.n;
                    var lm = el('li'); lm.appendChild(link('https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(q), cfg.t.map, true)); ul.appendChild(lm);
                }
                card.appendChild(ul);
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
