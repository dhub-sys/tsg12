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
        var savedRange = null;
        function remember() {
            var sel = window.getSelection();
            if (sel.rangeCount && area.contains(sel.getRangeAt(0).commonAncestorContainer)) savedRange = sel.getRangeAt(0).cloneRange();
        }
        // Puts the caret back where it was when a toolbar control (e.g. the style list) took the focus.
        function restore() {
            var sel = window.getSelection();
            var inside = sel.rangeCount && area.contains(sel.getRangeAt(0).commonAncestorContainer);
            area.focus();
            if (!inside && savedRange) { sel.removeAllRanges(); sel.addRange(savedRange); }
        }
        function cmd(c, v) {
            restore();
            try { document.execCommand('styleWithCSS', false, false); } catch (e) {}
            document.execCommand(c, false, v || null);
            sync();
            state();
        }
        function cell() {
            var sel = window.getSelection(), node = sel.rangeCount ? sel.getRangeAt(0).startContainer : null;
            if ((!node || !area.contains(node)) && savedRange) node = savedRange.startContainer;
            return node ? closest(node, 'td,th', area) : null;
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
            restore();
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
                area.innerHTML = src.value.replace(/>\s*\n\s*</g, '><'); // the line breaks added for reading
                clean(area);
                wrap.removeChild(src);
                src = false;
                area.style.display = '';
                sync();
            }
            wrap.classList.toggle('is-source', !!src);
            bar.querySelector('[data-k="src"]').classList.toggle('is-on', !!src);
            block.disabled = !!src;
            if (src) bar.querySelectorAll('[data-state]').forEach(function (b) { b.classList.remove('is-on'); }); else state();
        }

        var I = {
            b: '<b>B</b>', i: '<i>I</i>', u: '<u>U</u>',
            ul: '<svg viewBox="0 0 20 20"><circle cx="4" cy="5" r="1.4"/><circle cx="4" cy="10" r="1.4"/><circle cx="4" cy="15" r="1.4"/><path d="M8 5h9M8 10h9M8 15h9"/></svg>',
            ol: '<svg viewBox="0 0 20 20"><path d="M8 5h9M8 10h9M8 15h9"/><text x="1.5" y="7" font-size="6">1</text><text x="1.5" y="12" font-size="6">2</text><text x="1.5" y="17" font-size="6">3</text></svg>',
            table: '<svg viewBox="0 0 20 20"><rect x="2.5" y="3.5" width="15" height="13" rx="1.5"/><path d="M2.5 8h15M2.5 12.3h15M8 3.5v13M12.5 3.5v13"/></svg>',
            row: '<svg viewBox="0 0 20 20"><rect x="2.5" y="2.5" width="15" height="7" rx="1.5"/><path d="M10 12v6M7 15h6"/></svg>',
            col: '<svg viewBox="0 0 20 20"><rect x="2.5" y="2.5" width="7" height="15" rx="1.5"/><path d="M12 10h6M15 7v6"/></svg>',
            delrow: '<svg viewBox="0 0 20 20"><rect x="2.5" y="2.5" width="15" height="7" rx="1.5"/><path d="M7 15h6"/></svg>',
            delcol: '<svg viewBox="0 0 20 20"><rect x="2.5" y="2.5" width="7" height="15" rx="1.5"/><path d="M12 10h6"/></svg>',
            link: '<svg viewBox="0 0 20 20"><path d="M8.5 11.5a3.5 3.5 0 0 0 5 0l2.5-2.5a3.5 3.5 0 0 0-5-5L10 5"/><path d="M11.5 8.5a3.5 3.5 0 0 0-5 0L4 11a3.5 3.5 0 0 0 5 5l1-1"/></svg>',
            unlink: '<svg viewBox="0 0 20 20"><path d="M8.5 11.5a3.5 3.5 0 0 0 5 0l2.5-2.5a3.5 3.5 0 0 0-5-5L10 5"/><path d="M11.5 8.5a3.5 3.5 0 0 0-5 0L4 11a3.5 3.5 0 0 0 5 5l1-1"/><path d="M3 3l14 14"/></svg>',
            hr: '<svg viewBox="0 0 20 20"><path d="M2.5 10h15"/><path d="M5 5.5h10M5 14.5h10" opacity=".35"/></svg>',
            clear: '<svg viewBox="0 0 20 20"><path d="M5 4h10M10 4l-3 12M4 17h6M13 12l4 4M17 12l-4 4"/></svg>',
            undo: '<svg viewBox="0 0 20 20"><path d="M7 5L3 9l4 4"/><path d="M3 9h9a5 5 0 0 1 0 10h-2"/></svg>',
            redo: '<svg viewBox="0 0 20 20"><path d="M13 5l4 4-4 4"/><path d="M17 9H8a5 5 0 0 0 0 10h2"/></svg>',
            src: '<svg viewBox="0 0 20 20"><path d="M7 6l-4 4 4 4M13 6l4 4-4 4"/></svg>'
        };
        var block = document.createElement('select');
        block.className = 'tw-rte-block';
        block.title = 'Στυλ κειμένου';
        block.setAttribute('aria-label', 'Στυλ κειμένου');
        [['p', 'Κείμενο'], ['h2', 'Επικεφαλίδα'], ['h3', 'Υποεπικεφαλίδα'], ['h4', 'Μικρή επικεφαλίδα'], ['blockquote', 'Πλαίσιο σημείωσης']].forEach(function (o) {
            var op = document.createElement('option'); op.value = o[0]; op.textContent = o[1]; block.appendChild(op);
        });
        block.addEventListener('change', function () { cmd('formatBlock', '<' + block.value + '>'); });
        bar.appendChild(block);

        var tools = [
            '|',
            ['b', I.b, 'Έντονα (Ctrl+B)', function () { cmd('bold'); }, 'bold'],
            ['i', I.i, 'Πλάγια (Ctrl+I)', function () { cmd('italic'); }, 'italic'],
            ['u', I.u, 'Υπογράμμιση (Ctrl+U)', function () { cmd('underline'); }, 'underline'],
            '|',
            ['ul', I.ul, 'Λίστα με κουκκίδες', function () { cmd('insertUnorderedList'); }, 'insertUnorderedList'],
            ['ol', I.ol, 'Αριθμημένη λίστα', function () { cmd('insertOrderedList'); }, 'insertOrderedList'],
            '|',
            ['table', I.table, 'Εισαγωγή πίνακα', table],
            ['row', I.row, 'Νέα γραμμή κάτω από την τρέχουσα (κέρσορας μέσα σε πίνακα)', addRow, '', true],
            ['col', I.col, 'Νέα στήλη δεξιά της τρέχουσας (κέρσορας μέσα σε πίνακα)', addCol, '', true],
            ['delrow', I.delrow, 'Διαγραφή τρέχουσας γραμμής', delRow, '', true],
            ['delcol', I.delcol, 'Διαγραφή τρέχουσας στήλης', delCol, '', true],
            '|',
            ['link', I.link, 'Σύνδεσμος', link],
            ['unlink', I.unlink, 'Αφαίρεση συνδέσμου', function () { cmd('unlink'); }],
            ['hr', I.hr, 'Διαχωριστική γραμμή', function () { cmd('insertHorizontalRule'); }],
            '|',
            ['clear', I.clear, 'Καθαρισμός μορφοποίησης', function () { cmd('removeFormat'); cmd('formatBlock', '<p>'); }],
            ['undo', I.undo, 'Αναίρεση (Ctrl+Z)', function () { cmd('undo'); }],
            ['redo', I.redo, 'Επανάληψη (Ctrl+Y)', function () { cmd('redo'); }],
            '>',
            ['src', I.src, 'Προβολή/επεξεργασία κώδικα HTML', source]
        ];
        tools.forEach(function (t) {
            if (t === '|' || t === '>') { var s = document.createElement('span'); s.className = t === '|' ? 'tw-rte-sep' : 'tw-rte-spacer'; bar.appendChild(s); return; }
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'tw-rte-btn';
            b.dataset.k = t[0];
            if (t[4]) b.dataset.state = t[4];
            if (t[5]) b.dataset.cell = '1';
            b.innerHTML = t[1];
            b.title = t[2];
            b.setAttribute('aria-label', t[2]);
            b.addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep the text selection
            b.addEventListener('click', function (e) { if (!b.classList.contains('is-off')) t[3](e); });
            bar.appendChild(b);
        });

        // Active buttons (bold, list…), the current style in the list, and the table tools only inside a table.
        function state() {
            if (src) return;
            var focused = document.activeElement === area;
            bar.querySelectorAll('[data-state]').forEach(function (b) {
                var on = false;
                try { on = focused && document.queryCommandState(b.dataset.state); } catch (e) {}
                b.classList.toggle('is-on', !!on);
            });
            var inCell = !!cell();
            bar.querySelectorAll('[data-cell]').forEach(function (b) { b.classList.toggle('is-off', !inCell); });
            var v = '';
            try { v = String(document.queryCommandValue('formatBlock') || '').toLowerCase().replace(/[<>]/g, ''); } catch (e) {}
            if (focused) block.value = /^(h2|h3|h4|blockquote)$/.test(v) ? v : 'p';
        }
        ['keyup', 'mouseup', 'focus'].forEach(function (ev) { area.addEventListener(ev, function () { remember(); state(); }); });
        document.addEventListener('selectionchange', function () { if (document.activeElement === area) { remember(); state(); } });
        state();

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
