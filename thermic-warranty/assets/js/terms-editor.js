/* Warranty Manager – small rich-text editor for the warranty terms (console only).
 * Turns every <textarea data-tw-rich> into a toolbar + editable area; the textarea keeps the HTML and is what
 * the form submits. The server cleans the HTML again (TW_Terms::clean_html), so this only has to be convenient. */
(function () {
    var ALLOWED = { H2: 1, H3: 1, H4: 1, P: 1, BR: 1, HR: 1, BLOCKQUOTE: 1, STRONG: 1, B: 1, EM: 1, I: 1, U: 1, SUP: 1, SUB: 1,
        UL: 1, OL: 1, LI: 1, TABLE: 1, THEAD: 1, TBODY: 1, TR: 1, TH: 1, TD: 1, A: 1 };
    var KEEP_ATTR = { A: ['href'], TD: ['colspan', 'rowspan'], TH: ['colspan', 'rowspan'] };

    function esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

    // Older terms are plain text: paragraphs on blank lines, line breaks inside them.
    function toHtml(v) {
        if (/<(p|h[2-4]|ul|ol|table|strong|em|br|blockquote)\b/i.test(v)) return v;
        return v.trim() === '' ? '<p><br></p>' : v.trim().split(/\n\s*\n/).map(function (p) { return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>'; }).join('');
    }

    // Keeps only the allowed tags/attributes (e.g. when pasting from Word or a web page).
    function clean(root) {
        Array.prototype.slice.call(root.querySelectorAll('*')).reverse().forEach(function (el) {
            var tag = el.tagName;
            if (tag === 'DIV') { // browsers use <div> for new lines: make it a paragraph
                var p = document.createElement('p');
                while (el.firstChild) p.appendChild(el.firstChild);
                el.parentNode.replaceChild(p, el);
                return;
            }
            if (/^(SCRIPT|STYLE|META|LINK|TITLE|XML|O:P)$/.test(tag)) { el.parentNode.removeChild(el); return; }
            if (!ALLOWED[tag]) { // unwrap: keep the text, drop the element
                while (el.firstChild) el.parentNode.insertBefore(el.firstChild, el);
                el.parentNode.removeChild(el);
                return;
            }
            Array.prototype.slice.call(el.attributes).forEach(function (a) {
                if ((KEEP_ATTR[tag] || []).indexOf(a.name) === -1) el.removeAttribute(a.name);
            });
            if (tag === 'A') { el.setAttribute('target', '_blank'); el.setAttribute('rel', 'noopener'); }
        });
    }

    function closest(node, sel, stop) {
        while (node && node !== stop) {
            if (node.nodeType === 1 && node.matches(sel)) return node;
            node = node.parentNode;
        }
        return null;
    }

    function init(ta) {
        var wrap = document.createElement('div');
        wrap.className = 'tw-rte';
        var bar = document.createElement('div');
        bar.className = 'tw-rte-bar';
        bar.setAttribute('role', 'toolbar');
        var area = document.createElement('div');
        area.className = 'tw-rte-area';
        area.contentEditable = 'true';
        if (ta.lang) area.lang = ta.lang;
        area.innerHTML = toHtml(ta.value);
        var src = false; // HTML source view
        wrap.appendChild(bar);
        wrap.appendChild(area);
        // Outside the <label>: a click inside a label would otherwise move the focus to the hidden textarea.
        var label = ta.closest('label');
        (label || ta).insertAdjacentElement('afterend', wrap);
        ta.style.display = 'none';
        ta.removeAttribute('required');

        function sync() {
            if (src) return;
            var tmp = document.createElement('div');
            tmp.innerHTML = area.innerHTML;
            clean(tmp);
            ta.value = tmp.innerHTML;
        }
        function cmd(c, v) { area.focus(); document.execCommand(c, false, v || null); sync(); }
        function cell() {
            var sel = window.getSelection();
            return sel.rangeCount ? closest(sel.getRangeAt(0).startContainer, 'td,th', area) : null;
        }
        function addRow() {
            var c = cell(); if (!c) return alert('Βάλτε τον κέρσορα μέσα σε κελί του πίνακα.');
            var tr = c.parentNode, n = tr.children.length, row = document.createElement('tr');
            for (var i = 0; i < n; i++) { var td = document.createElement('td'); td.innerHTML = '<br>'; row.appendChild(td); }
            var body = tr.parentNode.tagName === 'THEAD' ? tr.closest('table').querySelector('tbody') : null;
            if (body) body.insertBefore(row, body.firstChild); else tr.parentNode.insertBefore(row, tr.nextSibling);
            sync();
        }
        function addCol() {
            var c = cell(); if (!c) return alert('Βάλτε τον κέρσορα μέσα σε κελί του πίνακα.');
            var idx = Array.prototype.indexOf.call(c.parentNode.children, c);
            c.closest('table').querySelectorAll('tr').forEach(function (tr) {
                var ref = tr.children[idx];
                var nc = document.createElement(ref && ref.tagName === 'TH' ? 'th' : 'td');
                nc.innerHTML = '<br>';
                tr.insertBefore(nc, ref ? ref.nextSibling : null);
            });
            sync();
        }
        function delRow() {
            var c = cell(); if (!c) return alert('Βάλτε τον κέρσορα μέσα σε κελί του πίνακα.');
            var tr = c.parentNode, table = tr.closest('table');
            tr.parentNode.removeChild(tr);
            if (!table.querySelector('tr')) table.parentNode.removeChild(table);
            sync();
        }
        function delCol() {
            var c = cell(); if (!c) return alert('Βάλτε τον κέρσορα μέσα σε κελί του πίνακα.');
            var idx = Array.prototype.indexOf.call(c.parentNode.children, c), table = c.closest('table');
            table.querySelectorAll('tr').forEach(function (tr) { if (tr.children[idx]) tr.removeChild(tr.children[idx]); });
            if (!table.querySelector('td,th')) table.parentNode.removeChild(table);
            sync();
        }
        function table() {
            var cols = parseInt(prompt('Πόσες στήλες;', '3'), 10), rows = parseInt(prompt('Πόσες γραμμές (χωρίς την επικεφαλίδα);', '3'), 10);
            if (!(cols > 0 && rows > 0 && cols <= 12 && rows <= 60)) return;
            // Built with the DOM and placed after the current block: insertHTML mangles tables in some browsers.
            var t = document.createElement('table'), body = document.createElement('tbody');
            for (var r = 0; r <= rows; r++) {
                var tr = document.createElement('tr');
                for (var c = 0; c < cols; c++) {
                    var cl = document.createElement(r === 0 ? 'th' : 'td');
                    if (r === 0) cl.textContent = 'Στήλη ' + (c + 1); else cl.innerHTML = '<br>';
                    tr.appendChild(cl);
                }
                body.appendChild(tr);
            }
            t.appendChild(body);
            var sel = window.getSelection(), block = null;
            if (sel.rangeCount) {
                block = sel.getRangeAt(0).startContainer;
                while (block && block.parentNode !== area) block = block.parentNode;
            }
            var after = document.createElement('p');
            after.innerHTML = '<br>';
            if (block && block.parentNode === area) {
                var empty = block.nodeType === 1 && !block.textContent.trim() && !block.querySelector('table');
                if (empty) area.replaceChild(t, block); else area.insertBefore(t, block.nextSibling);
            } else {
                area.appendChild(t);
            }
            area.insertBefore(after, t.nextSibling);
            var first = t.querySelector('th');
            var range = document.createRange();
            range.selectNodeContents(first);
            sel.removeAllRanges();
            sel.addRange(range);
            area.focus();
            sync();
        }
        function link() {
            var url = prompt('Διεύθυνση συνδέσμου (https://…):', 'https://');
            if (url && /^(https?:|mailto:|tel:)/i.test(url)) cmd('createLink', url);
        }
        function source() {
            if (!src) {
                sync();
                var t = document.createElement('textarea');
                t.className = 'tw-rte-src large-text code';
                t.value = ta.value.replace(/></g, '>\n<');
                area.style.display = 'none';
                wrap.appendChild(t);
                src = t;
            } else {
                area.innerHTML = src.value;
                clean(area);
                wrap.removeChild(src);
                src = false;
                area.style.display = '';
                sync();
            }
            bar.querySelectorAll('button').forEach(function (b) { if (b.dataset.k !== 'src') b.disabled = !!src; });
        }

        var tools = [
            ['p', 'Κείμενο', 'Κανονική παράγραφος', function () { cmd('formatBlock', '<p>'); }],
            ['h2', 'H2', 'Επικεφαλίδα', function () { cmd('formatBlock', '<h2>'); }],
            ['h3', 'H3', 'Υποεπικεφαλίδα', function () { cmd('formatBlock', '<h3>'); }],
            '|',
            ['b', '<b>B</b>', 'Έντονα (Ctrl+B)', function () { cmd('bold'); }],
            ['i', '<i>I</i>', 'Πλάγια (Ctrl+I)', function () { cmd('italic'); }],
            ['u', '<u>U</u>', 'Υπογράμμιση (Ctrl+U)', function () { cmd('underline'); }],
            '|',
            ['ul', '• Λίστα', 'Λίστα με κουκκίδες', function () { cmd('insertUnorderedList'); }],
            ['ol', '1. Λίστα', 'Αριθμημένη λίστα', function () { cmd('insertOrderedList'); }],
            '|',
            ['table', '▦ Πίνακας', 'Εισαγωγή πίνακα', table],
            ['row', '+ Γραμμή', 'Νέα γραμμή κάτω από την τρέχουσα', addRow],
            ['col', '+ Στήλη', 'Νέα στήλη δεξιά της τρέχουσας', addCol],
            ['delrow', '− Γραμμή', 'Διαγραφή τρέχουσας γραμμής', delRow],
            ['delcol', '− Στήλη', 'Διαγραφή τρέχουσας στήλης', delCol],
            '|',
            ['link', '🔗', 'Σύνδεσμος', link],
            ['hr', '―', 'Διαχωριστική γραμμή', function () { cmd('insertHorizontalRule'); }],
            ['clear', 'Τx', 'Καθαρισμός μορφοποίησης', function () { cmd('removeFormat'); cmd('formatBlock', '<p>'); }],
            '|',
            ['undo', '↶', 'Αναίρεση (Ctrl+Z)', function () { cmd('undo'); }],
            ['redo', '↷', 'Επανάληψη (Ctrl+Y)', function () { cmd('redo'); }],
            ['src', '&lt;/&gt;', 'Προβολή/επεξεργασία HTML', source]
        ];
        tools.forEach(function (t) {
            if (t === '|') { var s = document.createElement('span'); s.className = 'tw-rte-sep'; bar.appendChild(s); return; }
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'button';
            b.dataset.k = t[0];
            b.innerHTML = t[1];
            b.title = t[2];
            b.setAttribute('aria-label', t[2]);
            b.addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep the text selection
            b.addEventListener('click', t[3]);
            bar.appendChild(b);
        });

        area.addEventListener('input', sync);
        area.addEventListener('blur', sync);
        area.addEventListener('paste', function (e) {
            var cd = e.clipboardData;
            if (!cd) return;
            e.preventDefault();
            var html = cd.getData('text/html');
            if (html) {
                var tmp = document.createElement('div');
                tmp.innerHTML = html;
                clean(tmp);
                document.execCommand('insertHTML', false, tmp.innerHTML);
            } else {
                document.execCommand('insertHTML', false, toHtml(cd.getData('text/plain')));
            }
            sync();
        });
        try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {}
        var form = ta.form;
        if (form) form.addEventListener('submit', function (e) {
            if (src) source();
            sync();
            if (ta.name === 'text_el' && !area.textContent.trim()) {
                e.preventDefault();
                alert('Συμπληρώστε το κείμενο των όρων στα ελληνικά.');
                area.focus();
            }
        });
        sync();
    }

    document.querySelectorAll('textarea[data-tw-rich]').forEach(init);
})();
