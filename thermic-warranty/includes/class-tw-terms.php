<?php
if (!defined('ABSPATH')) exit;

/**
 * Warranty terms sets ("Όροι εγγύησης").
 *
 * The company can keep several sets of terms side by side, e.g. «Όροι 2026» and «Όροι από 15/11/2026».
 * Each set has its own text (Greek and English) and its own version history: every change of the text
 * creates a new version and the older ones are kept, because every customer's acceptance points to the
 * exact set, version and text (SHA-256) they saw.
 *
 * Which set applies to a product:
 *  1. a set assigned to the serial number (from the Όροι εγγύησης screen: list of serials or a whole batch);
 *  2. otherwise the set with the latest «ισχύει για αγορές από» date on or before the purchase date;
 *  3. otherwise the base set (id 1, «Βασικοί όροι»).
 *
 * The base set keeps the original storage (TW_Settings::TERMS_OPTION and its _en twin), so everything
 * accepted before sets existed stays valid as «Βασικοί όροι».
 */
final class TW_Terms {
    const SETS_OPTION = 'tw_terms_sets';
    const BASE = 1;

    // ------------------------------------------------------------------ sets

    /** @return array id => ['id', 'name', 'from' (Y-m-d or ''), 'created_at'] ordered: base first, then by date */
    public static function sets() {
        $sets = get_option(self::SETS_OPTION, []);
        if (!is_array($sets)) $sets = [];
        foreach ($sets as $k => $v) $sets[$k] += ['from' => '', 'to' => ''];
        if (!isset($sets[self::BASE])) {
            $sets[self::BASE] = ['id' => self::BASE, 'name' => 'Βασικοί όροι', 'from' => '', 'to' => '', 'created_at' => TW_DB::now()];
            update_option(self::SETS_OPTION, $sets, false);
        }
        // Base first, then by the start date (sets without dates, i.e. only for assigned serials, last).
        uasort($sets, function ($a, $b) {
            if ((int) $a['id'] === self::BASE) return -1;
            if ((int) $b['id'] === self::BASE) return 1;
            $ka = $a['from'] !== '' ? $a['from'] : ($a['to'] !== '' ? '0000' : '9999');
            $kb = $b['from'] !== '' ? $b['from'] : ($b['to'] !== '' ? '0000' : '9999');
            return [$ka, $a['id']] <=> [$kb, $b['id']];
        });
        return $sets;
    }

    public static function get($id) {
        $sets = self::sets();
        return $sets[(int) $id] ?? null;
    }

    public static function name($id) {
        $s = self::get($id);
        return $s ? $s['name'] : '#' . (int) $id;
    }

    /**
     * Creates (id 0) or updates a set; returns its id. $from / $to (Y-m-d or '') are the purchase dates the set
     * covers; an empty «to» means «until the next set by date starts».
     */
    public static function save_set($id, $name, $from, $to = '') {
        $sets = self::sets();
        $id = (int) $id;
        if (!$id) $id = max(array_keys($sets)) + 1;
        $old = $sets[$id] ?? ['id' => $id, 'created_at' => TW_DB::now()];
        $sets[$id] = array_merge($old, [
            'name' => mb_substr(trim($name), 0, 120) ?: ('Όροι #' . $id),
            'from' => $from,
            'to' => $to,
        ]);
        update_option(self::SETS_OPTION, $sets, false);
        return $id;
    }

    /** Removes a set nobody has accepted (its serials go back to «by purchase date»). */
    public static function delete_set($id) {
        global $wpdb;
        $id = (int) $id;
        if ($id === self::BASE) return new WP_Error('base', 'Οι βασικοί όροι δεν διαγράφονται.');
        if (self::accepted_count($id)) return new WP_Error('used', 'Δεν διαγράφεται: υπάρχουν πελάτες που αποδέχθηκαν αυτούς τους όρους. Μπορείτε να αφαιρέσετε τις ημερομηνίες και τους σειριακούς, ώστε να μην εφαρμόζεται σε νέες ενεργοποιήσεις.');
        $wpdb->update(TW_DB::table('serials'), ['terms_set' => 0], ['terms_set' => $id]);
        self::remove_pdf($id, 'el');
        self::remove_pdf($id, 'en');
        $sets = self::sets();
        unset($sets[$id]);
        update_option(self::SETS_OPTION, $sets, false);
        delete_option(self::option($id, 'el'));
        delete_option(self::option($id, 'en'));
        return true;
    }

    public static function accepted_count($id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . TW_DB::table('warranties') . ' WHERE terms_set = %d', (int) $id));
    }

    public static function assigned_count($id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . TW_DB::table('serials') . ' WHERE terms_set = %d', (int) $id));
    }

    // ------------------------------------------------------------------ texts and versions

    public static function option($set, $lang = 'el') {
        return TW_Settings::TERMS_OPTION . ((int) $set === self::BASE ? '' : '_s' . (int) $set) . ($lang === 'en' ? '_en' : '');
    }

    public static function versions($set, $lang = 'el') {
        $v = get_option(self::option($set, $lang), []);
        return is_array($v) ? $v : [];
    }

    /**
     * Current text of a set in a language, with 'lang' telling which text it really is (English customers
     * see the Greek text of the set until an English one is published), plus 'set' and 'set_name'.
     */
    public static function current($set, $lang = 'el') {
        $set = (int) $set;
        $extra = ['set' => $set, 'set_name' => self::name($set)];
        if ($lang === 'en') {
            $v = self::versions($set, 'en');
            if ($v && trim((string) end($v)['text']) !== '') return end($v) + ['lang' => 'en'] + $extra;
        }
        $v = self::versions($set, 'el');
        return ($v ? end($v) : ['version' => 0, 'text' => '', 'hash' => '', 'published_at' => '']) + ['lang' => 'el'] + $extra;
    }

    public static function version($set, $version, $lang = 'el') {
        foreach (self::versions($set, $lang) as $t) {
            if ((int) $t['version'] === (int) $version) return $t + ['lang' => $lang, 'set' => (int) $set, 'set_name' => self::name($set)];
        }
        return null;
    }

    /**
     * Saves the text of a set. For the team, editing never makes a new version: a new version is a new set.
     * Internally the text is overwritten in place as long as nobody has accepted it; once a customer has,
     * the accepted wording is kept as a hidden archived revision (the consent points to it with its SHA-256),
     * so the record of what each customer accepted never changes. Returns the current revision number.
     */
    public static function save_text($set, $text, $lang = 'el') {
        $text = trim(str_replace("\r\n", "\n", (string) $text));
        $hash = hash('sha256', $text);
        $versions = self::versions($set, $lang);
        $current = $versions ? end($versions) : null;
        if ($current && $current['hash'] === $hash) return (int) $current['version'];
        if (!$current && $text === '') return 0;
        $row = ['text' => $text, 'hash' => $hash, 'published_at' => TW_DB::now()];
        if ($current && !self::revision_accepted($set, (int) $current['version'], $lang)) {
            $versions[count($versions) - 1] = ['version' => (int) $current['version']] + $row;
            $n = (int) $current['version'];
        } else {
            $n = $current ? (int) $current['version'] + 1 : 1;
            $versions[] = ['version' => $n] + $row;
        }
        update_option(self::option($set, $lang), $versions, false);
        return $n;
    }

    /** Whether any customer accepted this revision of the text. */
    public static function revision_accepted($set, $revision, $lang) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM " . TW_DB::table('consents') . " WHERE consent_type = 'terms' AND terms_set = %d AND terms_version = %d AND lang = %s LIMIT 1", (int) $set, (int) $revision, $lang === 'en' ? 'en' : 'el'));
    }

    /** Tags and attributes the terms editor may produce (everything else is removed on save). */
    public static function allowed_html() {
        $cell = ['colspan' => true, 'rowspan' => true];
        return [
            'h2' => [], 'h3' => [], 'h4' => [], 'p' => [], 'br' => [], 'hr' => [], 'blockquote' => [],
            'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 'sup' => [], 'sub' => [],
            'ul' => [], 'ol' => [], 'li' => [],
            'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => $cell, 'td' => $cell,
            'a' => ['href' => true, 'target' => true, 'rel' => true],
        ];
    }

    /** Cleans editor HTML before saving. */
    public static function clean_html($html) {
        $html = preg_replace('#<(script|style|iframe|object|embed|noscript|template)\b[^>]*>.*?</\1\s*>#is', '', (string) $html);
        $html = wp_kses($html, self::allowed_html(), ['http', 'https', 'mailto', 'tel']);
        $html = preg_replace('#<(p|h[2-4]|li)>\s*(?:<br\s*/?>)?\s*</\1>#i', '', $html); // empty blocks (table cells stay)
        return trim($html);
    }

    /** HTML for display: formatted text from the editor, or older plain text turned into paragraphs. */
    public static function html($text) {
        $text = (string) $text;
        if (preg_match('#<(p|h[2-4]|ul|ol|table|strong|em|br|blockquote)\b#i', $text)) return wp_kses($text, self::allowed_html(), ['http', 'https', 'mailto', 'tel']);
        return wpautop(esc_html($text));
    }

    /** «Ενημέρωση: 15/11/2026» – what customers see instead of a version number. */
    public static function updated_label($t) {
        return sprintf(tw__('Τελευταία ενημέρωση όρων: %s'), TW_Util::date_label(substr((string) $t['published_at'], 0, 10)));
    }

    /** All options holding terms (for backups). */
    public static function all_options() {
        $out = [self::SETS_OPTION => self::sets()];
        foreach (array_keys(self::sets()) as $id) {
            foreach (['el', 'en'] as $l) $out[self::option($id, $l)] = self::versions($id, $l);
        }
        return $out;
    }

    // ------------------------------------------------------------------ ready-made PDF per set and language

    const PDF_DIR = 'terms';

    /** @return array|null ['file', 'name', 'version', 'uploaded_at', 'path'] of the uploaded PDF, if any */
    public static function pdf($set, $lang = 'el') {
        $s = self::get($set);
        $m = $s['pdf_' . ($lang === 'en' ? 'en' : 'el')] ?? null;
        if (!is_array($m) || empty($m['file'])) return null;
        $path = TW_Util::invoice_path($m['file']);
        return $path ? $m + ['path' => $path] : null;
    }

    /** The uploaded PDF only if it belongs to the current version of the text (otherwise it shows outdated terms). */
    public static function pdf_current($set, $lang = 'el') {
        return self::pdf($set, $lang);
    }

    /** The text was changed after the PDF was uploaded (the team should check the PDF is up to date). */
    public static function pdf_older_than_text($set, $lang) {
        $m = self::pdf($set, $lang);
        $v = self::versions($set, $lang);
        return $m && $v && end($v)['published_at'] > $m['uploaded_at'];
    }

    private static function set_pdf_meta($set, $lang, $meta) {
        $sets = get_option(self::SETS_OPTION, []);
        if (!isset($sets[(int) $set])) return;
        if ($meta) $sets[(int) $set]['pdf_' . $lang] = $meta; else unset($sets[(int) $set]['pdf_' . $lang]);
        update_option(self::SETS_OPTION, $sets, false);
    }

    /** Stores an uploaded PDF for a set and language (replacing the previous one). @return true|WP_Error */
    public static function store_pdf($set, $lang, array $file) {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload', 'Το PDF δεν ανέβηκε σωστά.');
        }
        if ((int) $file['size'] > 20 * 1024 * 1024) return new WP_Error('upload', 'Το PDF ξεπερνά τα 20 MB.');
        $fh = fopen($file['tmp_name'], 'rb');
        $head = $fh ? fread($fh, 5) : '';
        if ($fh) fclose($fh);
        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], ['pdf' => 'application/pdf']);
        if ($head !== '%PDF-' || empty($check['ext'])) return new WP_Error('upload', 'Επιτρέπονται μόνο αρχεία PDF.');
        $dir = TW_Util::protect_upload_dir() . '/' . self::PDF_DIR;
        if (!is_dir($dir)) wp_mkdir_p($dir);
        $rel = self::PDF_DIR . '/' . bin2hex(random_bytes(16)) . '.pdf';
        if (!@move_uploaded_file($file['tmp_name'], TW_Util::upload_dir() . '/' . $rel)) return new WP_Error('upload', 'Το PDF δεν αποθηκεύτηκε. Ελέγξτε τα δικαιώματα του φακέλου uploads.');
        self::remove_pdf($set, $lang);
        $name = sanitize_file_name(wp_basename($file['name'])) ?: 'terms.pdf';
        if (strtolower(substr($name, -4)) !== '.pdf') $name .= '.pdf';
        $cur = self::versions($set, $lang) ? end(self::versions($set, $lang)) : null;
        self::set_pdf_meta($set, $lang, ['file' => $rel, 'name' => $name, 'version' => $cur ? (int) $cur['version'] : 0, 'uploaded_at' => TW_DB::now()]);
        return true;
    }

    public static function remove_pdf($set, $lang) {
        $m = self::pdf($set, $lang);
        if ($m) @unlink($m['path']);
        self::set_pdf_meta($set, $lang, null);
    }

    // ------------------------------------------------------------------ which set applies

    /**
     * Sets that apply by purchase date, ordered by start: [['id', 'from', 'to', 'base'], ...] with the effective
     * end date: the set's own «to», otherwise the day before the next set by date starts ('' = open).
     * The base set is always in the list (it is also the fallback when no period matches).
     */
    public static function ranges() {
        $list = [];
        foreach (self::sets() as $id => $s) {
            $id = (int) $id;
            $base = $id === self::BASE;
            if (!$base && ($s['from'] === '' && $s['to'] === '')) continue; // only for assigned serials
            if (!$base && !self::versions($id, 'el')) continue;
            $list[] = ['id' => $id, 'from' => $s['from'], 'to' => $s['to'], 'own_to' => $s['to'] !== '', 'base' => $base];
        }
        usort($list, function ($a, $b) { return [$a['from'], $a['base'] ? 0 : 1, $a['id']] <=> [$b['from'], $b['base'] ? 0 : 1, $b['id']]; });
        foreach ($list as $i => $r) {
            if ($r['own_to']) continue;
            foreach ($list as $n) {
                if ($n['from'] !== '' && $n['from'] > $r['from']) { $list[$i]['to'] = TW_Schedule::add_days($n['from'], -1); break; }
            }
        }
        return array_map(function ($r) { unset($r['own_to']); return $r; }, $list);
    }

    /** The set whose period contains the date (the latest start wins on overlaps), else the base set. */
    public static function by_date($date) {
        $pick = null;
        if ($date !== '') {
            foreach (self::ranges() as $r) {
                if (($r['from'] === '' || $r['from'] <= $date) && ($r['to'] === '' || $date <= $r['to'])) {
                    if (!$pick || $r['from'] >= $pick['from']) $pick = $r;
                }
            }
        }
        return $pick ? (int) $pick['id'] : self::BASE;
    }

    /** The set for a product: the serial's own set if it has one, otherwise by purchase date. */
    public static function resolve($serial_set, $purchase_date) {
        $serial_set = (int) $serial_set;
        if ($serial_set && self::get($serial_set) && self::versions($serial_set, 'el')) return $serial_set;
        return self::by_date((string) $purchase_date);
    }

    /** The terms that apply to purchases made today («the most recent terms»). */
    public static function latest() {
        return self::resolve(0, TW_Schedule::today());
    }

    /**
     * What the activation form needs to show the right terms as soon as the customer enters the purchase date:
     * one entry if the serial has its own set, otherwise one per period (the form picks the period that contains
     * the purchase date, like by_date()).
     */
    public static function candidates($serial, $lang) {
        $own = (int) ($serial->terms_set ?? 0);
        $pairs = ($own && self::get($own) && self::versions($own, 'el')) ? [['id' => $own, 'from' => '', 'to' => '', 'base' => true]] : self::ranges();
        $out = [];
        foreach ($pairs as $r) {
            $id = $r['id'];
            $t = self::current($id, $lang);
            $out[] = [
                'from' => $r['from'],
                'to' => $r['to'],
                'base' => !empty($r['base']),
                'set' => (int) $id,
                'version' => (int) $t['version'],
                'lang' => $t['lang'],
                'html' => self::html($t['text']),
                'label' => self::period_label($id) . ' · ' . self::updated_label($t),
            ];
        }
        return $out;
    }

    /**
     * Purchase period a set covers by date: ['from' => Y-m-d|'', 'to' => Y-m-d|''], or null for a set that
     * applies only to assigned serials.
     */
    public static function period($set) {
        foreach (self::ranges() as $r) {
            if ($r['id'] === (int) $set) return ['from' => $r['from'], 'to' => $r['to']];
        }
        return null;
    }

    /** «Για αγορές από 20/10/2026 έως 14/11/2026.» in the current customer language. */
    public static function period_label($set) {
        $p = self::period($set);
        if (!$p) return tw__('Για τα προϊόντα με συγκεκριμένους σειριακούς αριθμούς.');
        return self::label_from_to($p['from'], $p['to']);
    }

    /** The same label for dates not saved yet (from the edit form): the end comes from the next set if empty. */
    public static function period_label_for($id, $from, $to) {
        $id = (int) $id;
        if ($id !== self::BASE && $from === '' && $to === '') return tw__('Για τα προϊόντα με συγκεκριμένους σειριακούς αριθμούς.');
        if ($to === '') {
            foreach (self::ranges() as $r) {
                if ($r['id'] !== $id && $r['from'] !== '' && $r['from'] > $from) { $to = TW_Schedule::add_days($r['from'], -1); break; }
            }
        }
        return self::label_from_to($from, $to);
    }

    private static function label_from_to($from, $to) {
        $d = function ($ymd) { return TW_Util::date_label($ymd); };
        if ($from !== '' && $to !== '') return sprintf(tw__('Για αγορές από %1$s έως %2$s.'), $d($from), $d($to));
        if ($from !== '') return sprintf(tw__('Για αγορές από %s και μετά.'), $d($from));
        if ($to !== '') return sprintf(tw__('Για αγορές έως %s.'), $d($to));
        return tw__('Για όλες τις αγορές.');
    }

    // ------------------------------------------------------------------ console screen «Όροι εγγύησης»

    private static function view_link($set, $version, $lang = 'el') {
        return wp_nonce_url(add_query_arg(['action' => 'tw_terms_view', 's' => (int) $set, 'v' => (int) $version, 'tl' => $lang], TW_Admin::url()), 'tw_terms_view');
    }

    /** Last update of the text, linked to it. */
    private static function history($set, $lang) {
        $v = self::versions($set, $lang);
        if (!$v) return '—';
        $cur = end($v);
        return sprintf('<a href="%s" target="_blank" title="Προβολή του κειμένου">%s</a>', esc_url(self::view_link($set, $cur['version'], $lang)), esc_html(TW_Util::date_label(substr($cur['published_at'], 0, 10))));
    }

    /** Older wordings that customers accepted (kept for the record), shown in the edit form. */
    private static function archive($set) {
        $rows = [];
        foreach (['el' => 'EL', 'en' => 'EN'] as $lang => $ln) {
            $v = self::versions($set, $lang);
            foreach (array_slice(array_reverse($v), 1) as $tv) {
                $rows[] = sprintf('<li>%s · <a href="%s" target="_blank">%s</a></li>', $ln, esc_url(self::view_link($set, $tv['version'], $lang)), esc_html(TW_Util::datetime_label($tv['published_at'])));
            }
        }
        if (!$rows) return '';
        return '<details class="tw-terms-archive"><summary>Αρχείο προηγούμενων διατυπώσεων (' . count($rows) . ')</summary><p class="description">Διατυπώσεις που είχαν αποδεχθεί πελάτες πριν από διόρθωση του κειμένου· φυλάσσονται για το αρχείο.</p><ul>' . implode('', $rows) . '</ul></details>';
    }

    /** «από 01/01/2026 έως 14/11/2026» for the console list. */
    private static function range_label($id, $st) {
        $p = self::period($id);
        $d = function ($x) { return esc_html(TW_Util::date_label($x)); };
        if (!$p) return '<span class="description">μόνο σε σειριακούς με ανάθεση</span>';
        $from = $p['from'] !== '' ? 'από ' . $d($p['from']) : 'από την αρχή';
        $to = $p['to'] !== '' ? ' έως ' . $d($p['to']) : ' και μετά';
        $note = ($p['to'] !== '' && $st['to'] === '') ? '<br><small class="description">η λήξη προκύπτει από το επόμενο σετ</small>' : '';
        return $from . $to . $note;
    }

    public static function page() {
        $sets = self::sets();
        $post = TW_Admin::url();
        $edit_q = TW_Admin::q('edit');
        $edit = $edit_q === 'new' ? ['id' => 0, 'name' => '', 'from' => '', 'to' => '', 'created_at' => ''] : ($edit_q !== '' ? self::get((int) $edit_q) : null);
        $latest = self::latest();
        $batches = TW_Importer::batches();
        ?>
        <div class="wrap tw-wrap">
            <h1 class="wp-heading-inline">Όροι εγγύησης</h1>
            <a class="page-title-action" href="<?php echo esc_url(TW_Admin::url('terms', ['edit' => 'new']) . '#tw-terms-form'); ?>">+ Νέο σετ όρων</a>
            <?php TW_Admin::notice(); ?>

            <details class="tw-panel tw-terms-intro">
                <summary>Πώς επιλέγονται οι όροι για κάθε προϊόν</summary>
                <p>Μπορείτε να έχετε πολλά σετ όρων, π.χ. «Όροι 2026» και «Όροι από 15/11/2026». Κάθε πελάτης βλέπει και αποδέχεται, κατά την ενεργοποίηση, το σετ που αντιστοιχεί στο προϊόν του:</p>
                <ol>
                    <li>το σετ που έχει <strong>ανατεθεί στον σειριακό</strong> του (ανά σειριακό ή ανά παρτίδα), αλλιώς</li>
                    <li>το σετ του οποίου το <strong>διάστημα ισχύος</strong> («για αγορές από … έως …») περιέχει την ημερομηνία αγοράς (αν ταιριάζουν δύο, αυτό με την πιο πρόσφατη έναρξη), αλλιώς</li>
                    <li>τους <strong>Βασικούς όρους</strong>.</li>
                </ol>
                <p>Κάθε σετ είναι μία <strong>έκδοση όρων</strong>: για νέα έκδοση προσθέτετε νέο σετ. Η διόρθωση του κειμένου ενός σετ δεν αλλάζει έκδοση. Για κάθε πελάτη φυλάσσεται ακριβώς το κείμενο που αποδέχθηκε (με αποτύπωμα SHA-256), ακόμη κι αν διορθωθεί αργότερα· όσοι έχουν ήδη ενεργοποιήσει κρατούν τους όρους που αποδέχθηκαν.</p>
                <p>Στη σελίδα του site: <code>[<?php echo esc_html(TW_Terms_Public::SHORTCODE); ?>]</code> (οι πιο πρόσφατοι όροι, με αναζήτηση όρων ανά σειριακό ή ημερομηνία αγοράς).</p>
            </details>
            <p class="tw-terms-today"><strong>Για αγορές με σημερινή ημερομηνία ισχύει:</strong> <?php echo esc_html(self::name($latest)); ?> <span class="description">· shortcode για το site: <code>[<?php echo esc_html(TW_Terms_Public::SHORTCODE); ?>]</code></span></p>

            <table class="widefat striped tw-terms-table"><thead><tr><th>Σετ όρων</th><th>Ισχύει για αγορές</th><th>Σειριακοί με ανάθεση</th><th>Αποδοχές πελατών</th><th>Κείμενο EL</th><th>Κείμενο EN</th><th>PDF</th><th></th></tr></thead><tbody>
            <?php foreach ($sets as $id => $st) : $assigned = self::assigned_count($id); ?>
                <tr<?php echo (int) $id === $latest ? ' style="font-weight:600"' : ''; ?>>
                    <td><?php echo esc_html($st['name']); ?><?php echo (int) $id === $latest ? ' <span class="tw-badge tw-badge--active">ισχύει σήμερα</span>' : ''; ?><?php echo !self::versions($id, 'el') ? ' <span class="tw-badge tw-badge--void">χωρίς κείμενο</span>' : ''; ?></td>
                    <td><?php echo self::range_label($id, $st); // phpcs:ignore ?></td>
                    <td><?php echo $assigned ? '<a href="' . esc_url(TW_Admin::url('serials', ['terms_set' => $id])) . '">' . (int) $assigned . '</a>' : '—'; ?></td>
                    <td><?php echo (int) self::accepted_count($id); ?></td>
                    <td><?php echo self::history($id, 'el'); // phpcs:ignore ?></td>
                    <td><?php echo self::history($id, 'en'); // phpcs:ignore ?></td>
                    <td><?php $pl = []; foreach (['el', 'en'] as $x) { if (self::pdf($id, $x)) $pl[] = strtoupper($x) . (self::pdf_older_than_text($id, $x) ? ' (έλεγχος)' : ''); } echo $pl ? esc_html(implode(' · ', $pl)) : '<span class="description">αυτόματο</span>'; ?></td>
                    <td><a class="button" href="<?php echo esc_url(TW_Admin::url('terms', ['edit' => $id]) . '#tw-terms-form'); ?>">Επεξεργασία</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>

            <?php if ($edit) : $id = (int) $edit['id']; $el = $id ? self::current($id, 'el') : ['text' => '']; $enx = $id && self::versions($id, 'en') ? end(self::versions($id, 'en'))['text'] : ''; ?>
            <div class="tw-columns" id="tw-terms-form">
                <div class="tw-panel">
                    <h2><?php echo $id ? 'Επεξεργασία: ' . esc_html($edit['name']) : 'Νέο σετ όρων'; ?></h2>
                    <form method="post" action="<?php echo esc_url($post); ?>" enctype="multipart/form-data">
                        <?php wp_nonce_field('tw_terms_save'); ?><input type="hidden" name="action" value="tw_terms_save"><input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                        <p><label>Όνομα (για την ομάδα, π.χ. «Όροι από 15/11/2026») <input type="text" name="name" value="<?php echo esc_attr($edit['name']); ?>" maxlength="120" required></label></p>
                        <fieldset class="tw-terms-dates">
                            <legend>Διάστημα ισχύος <span class="description">(ημερομηνία αγοράς του προϊόντος)</span></legend>
                            <p><label>Για αγορές από <input type="date" name="from" value="<?php echo esc_attr($edit['from']); ?>"></label>
                               <label>έως <input type="date" name="to" value="<?php echo esc_attr($edit['to'] ?? ''); ?>"></label></p>
                            <?php if ($id === self::BASE) : ?>
                                <p class="description">Κενό «από»: από την αρχή. Κενό «έως»: μέχρι την έναρξη του επόμενου σετ. Οι βασικοί όροι εφαρμόζονται επίσης όταν η ημερομηνία αγοράς δεν ανήκει σε κανένα διάστημα.</p>
                            <?php else : ?>
                                <p class="description">Κενό «έως»: μέχρι την έναρξη του επόμενου σετ (ή χωρίς λήξη). Χωρίς καμία ημερομηνία, το σετ εφαρμόζεται μόνο σε σειριακούς που του αναθέσετε.</p>
                            <?php endif; ?>
                        </fieldset>
                        <p><label>Κείμενο όρων (Ελληνικά) <textarea name="text_el" rows="16" class="large-text" data-tw-rich><?php echo esc_textarea($el['text']); ?></textarea></label></p>
                        <p><label>Terms (English) <span class="description">(προαιρετικό – χωρίς αγγλικό κείμενο, οι πελάτες στα αγγλικά βλέπουν τους ελληνικούς όρους του σετ)</span><textarea name="text_en" rows="10" class="large-text" lang="en" data-tw-rich><?php echo esc_textarea($enx); ?></textarea></label></p>
                        <script src="<?php echo esc_url(TW_URL . 'assets/js/terms-editor.js?ver=' . TW_VERSION); ?>"></script>
                        <script>
                        (function () {
                            // Sends the text and dates as they are in the form (saved or not) to the print page, in a new tab.
                            document.addEventListener('click', function (e) {
                                var b = e.target.closest('[data-tw-mkpdf] button[data-lang]');
                                if (!b) return;
                                var box = b.closest('[data-tw-mkpdf]'), cfg = JSON.parse(box.getAttribute('data-tw-mkpdf')), form = box.closest('form');
                                var lang = b.getAttribute('data-lang');
                                form.querySelectorAll('.tw-rte-area').forEach(function (a) { a.dispatchEvent(new Event('blur')); });
                                var ta = form.querySelector('[name="text_' + lang + '"]'), src = ta.parentNode.parentNode.querySelector('.tw-rte-src');
                                var text = src ? src.value : ta.value;
                                if (!text.replace(/<[^>]*>|&nbsp;|\s/g, '')) { alert(lang === 'en' ? 'Δεν υπάρχει αγγλικό κείμενο.' : 'Δεν υπάρχει κείμενο.'); return; }
                                var f = document.createElement('form');
                                f.method = 'post'; f.action = cfg.url; f.target = '_blank'; f.style.display = 'none';
                                var fields = { action: 'tw_terms_pdf_draft', _wpnonce: cfg.nonce, id: cfg.id, lang: lang, text: text,
                                    from: (form.querySelector('[name=from]') || {}).value || '', to: (form.querySelector('[name=to]') || {}).value || '' };
                                Object.keys(fields).forEach(function (k) { var i = document.createElement('textarea'); i.name = k; i.value = fields[k]; f.appendChild(i); });
                                document.body.appendChild(f); f.submit(); f.remove();
                            });
                        })();
                        </script>
                        <h3>PDF για λήψη από το site <span class="description">(προαιρετικό)</span></h3>
                        <p class="description">Το «Λήψη PDF» της σελίδας όρων δίνει αυτό το αρχείο. Χωρίς PDF, ο επισκέπτης παίρνει αυτόματη σελίδα εκτύπωσης από το κείμενο, με το διάστημα ισχύος. Αν διορθώσετε το κείμενο, ανεβάστε και το ενημερωμένο PDF.</p>
                        <div class="tw-terms-mkpdf" data-tw-mkpdf="<?php echo esc_attr(wp_json_encode(['url' => $post, 'nonce' => wp_create_nonce('tw_terms_pdf_draft'), 'id' => $id])); ?>">
                            <p><strong>Δημιουργία PDF από το κείμενο:</strong>
                                <button type="button" class="button" data-lang="el"><?php echo TW_Admin::icon('printer'); // phpcs:ignore ?> Ελληνικά</button>
                                <button type="button" class="button" data-lang="en"><?php echo TW_Admin::icon('printer'); // phpcs:ignore ?> English</button></p>
                            <p class="description">Ανοίγει σε νέα καρτέλα η σελίδα των όρων όπως τους γράψατε, και πριν την αποθήκευση, με λογότυπο, διάστημα ισχύος και ημερομηνία. Επιλέξτε «Αποθήκευση ως PDF» στο παράθυρο εκτύπωσης και ανεβάστε το αρχείο στο αντίστοιχο πεδίο παρακάτω.</p>
                        </div>
                        <?php foreach (['el' => 'PDF (Ελληνικά)', 'en' => 'PDF (English)'] as $pl => $plabel) : $pm = $id ? self::pdf($id, $pl) : null; $pv = $id && self::versions($id, $pl) ? (int) end(self::versions($id, $pl))['version'] : 0; ?>
                            <p><label><?php echo esc_html($plabel); ?> <input type="file" name="pdf_<?php echo esc_attr($pl); ?>" accept="application/pdf,.pdf"></label>
                            <?php if ($pm) : ?>
                                <span class="description">Τώρα: <a href="<?php echo esc_url(TW_Terms_Public::pdf_url($id, $pl)); ?>" target="_blank"><?php echo esc_html($pm['name']); ?></a> (<?php echo esc_html(TW_Util::datetime_label($pm['uploaded_at'])); ?>)</span>
                                <?php if (self::pdf_older_than_text($id, $pl)) : ?><br><span class="tw-badge tw-badge--void">Το κείμενο άλλαξε μετά το ανέβασμα του PDF. Ελέγξτε ότι το PDF είναι ενημερωμένο.</span><?php endif; ?>
                                <label><input type="checkbox" name="pdf_<?php echo esc_attr($pl); ?>_remove" value="1"> Αφαίρεση PDF</label>
                            <?php endif; ?></p>
                        <?php endforeach; ?>
                        <?php echo $id ? self::archive($id) : ''; // phpcs:ignore ?>
                        <p><button class="button button-primary">Αποθήκευση</button> <a class="button" href="<?php echo esc_url(TW_Admin::url('terms')); ?>">Κλείσιμο</a></p>
                    </form>
                    <?php if ($id && $id !== self::BASE && !self::accepted_count($id)) : ?>
                        <form method="post" action="<?php echo esc_url($post); ?>" onsubmit="return confirm('Διαγραφή του σετ «<?php echo esc_js($edit['name']); ?>»;');">
                            <?php wp_nonce_field('tw_terms_delete'); ?><input type="hidden" name="action" value="tw_terms_delete"><input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                            <button class="button-link" style="color:#b42318">Διαγραφή σετ</button> <span class="description">(μόνο όσο δεν το έχει αποδεχθεί κανείς)</span>
                        </form>
                    <?php endif; ?>
                </div>
                <?php if ($id && $id !== self::BASE) : ?>
                <div class="tw-panel">
                    <h2>Ανάθεση σε σειριακούς</h2>
                    <p>Οι σειριακοί με ανάθεση παίρνουν αυτό το σετ, όποια κι αν είναι η ημερομηνία αγοράς. Σειριακοί με ανάθεση τώρα: <strong><?php echo (int) self::assigned_count($id); ?></strong>.</p>
                    <form method="post" action="<?php echo esc_url($post); ?>">
                        <?php wp_nonce_field('tw_terms_assign'); ?><input type="hidden" name="action" value="tw_terms_assign"><input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                        <p><label>Σειριακοί (ένας ανά γραμμή) <textarea name="serials" rows="5" class="large-text code"></textarea></label></p>
                        <p><label>ή ολόκληρη παρτίδα <select name="batch"><option value="">—</option><?php foreach ($batches as $b) echo '<option>' . esc_html($b) . '</option>'; ?></select></label></p>
                        <p><label><input type="radio" name="mode" value="assign" checked> Ανάθεση σε αυτό το σετ</label>
                           <label><input type="radio" name="mode" value="remove"> Αφαίρεση ανάθεσης (επιστροφή σε «κατά ημερομηνία αγοράς»)</label></p>
                        <p><button class="button button-primary">Εφαρμογή</button></p>
                        <p class="description">Σειριακοί με ήδη ενεργή εγγύηση κρατούν τους όρους που αποδέχθηκαν· η ανάθεση αφορά νέες ενεργοποιήσεις.</p>
                    </form>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_save() {
        TW_Admin::guard('tw_terms_save');
        $id = (int) TW_Admin::post('id');
        if ($id && !self::get($id)) $id = 0;
        $date = function ($k) { $v = TW_Admin::post($k); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : ''; };
        $from = $date('from');
        $to = $date('to');
        if ($from !== '' && $to !== '' && $to < $from) {
            TW_Admin::flash('Η ημερομηνία «έως» είναι πριν από την ημερομηνία «από».', 'error');
            TW_Admin::back('tw-terms', ['edit' => $id ?: 'new']);
        }
        if ($from !== '') {
            foreach (self::sets() as $sid => $st) {
                if ((int) $sid !== $id && $st['from'] === $from) {
                    TW_Admin::flash('Υπάρχει ήδη σετ όρων («' . esc_html($st['name']) . '») που ισχύει από ' . esc_html(TW_Util::date_label($from)) . '. Επιλέξτε άλλη ημερομηνία.', 'error');
                    TW_Admin::back('tw-terms', ['edit' => $id ?: 'new']);
                }
            }
        }
        $text_el = self::clean_html(wp_unslash($_POST['text_el'] ?? ''));
        if (trim(wp_strip_all_tags($text_el)) === '') {
            TW_Admin::flash('Συμπληρώστε το κείμενο των όρων στα ελληνικά.', 'error');
            TW_Admin::back('tw-terms', ['edit' => $id ?: 'new']);
        }
        $is_new = !$id;
        $old_set = $id ? self::get($id) : null;
        $id = self::save_set($id, sanitize_text_field(TW_Admin::post('name')), $from, $to);
        if ($old_set && ($old_set['from'] !== $from || $old_set['to'] !== $to)) {
            $fmt = function ($f, $t) { return ($f !== '' ? TW_Util::date_label($f) : '…') . ' – ' . ($t !== '' ? TW_Util::date_label($t) : '…'); };
            TW_DB::log_event('terms_dates_changed', ['set' => self::name($id), 'range_before' => $fmt($old_set['from'], $old_set['to']), 'range_after' => $fmt($from, $to)]);
        }
        $before = self::versions($id, 'el') ? self::current($id, 'el')['hash'] : '';
        self::save_text($id, $text_el, 'el');
        $msg = $is_new ? 'Δημιουργήθηκε το σετ όρων «' . esc_html(self::name($id)) . '».' : 'Αποθηκεύτηκε.';
        if (self::current($id, 'el')['hash'] !== $before) {
            TW_DB::log_event('terms_published', ['set' => self::name($id), 'lang' => 'EL']);
        }
        $en = self::clean_html(wp_unslash($_POST['text_en'] ?? ''));
        if (trim(wp_strip_all_tags($en)) === '') $en = '';
        if ($en !== '' || self::versions($id, 'en')) {
            $before_en = self::versions($id, 'en') ? end(self::versions($id, 'en'))['hash'] : '';
            self::save_text($id, $en, 'en');
            if (end(self::versions($id, 'en'))['hash'] !== $before_en) {
                TW_DB::log_event('terms_published', ['set' => self::name($id), 'lang' => 'EN']);
            }
        }
        $type = 'success';
        foreach (['el' => 'ελληνικό', 'en' => 'αγγλικό'] as $pl => $pname) {
            if (!empty($_POST['pdf_' . $pl . '_remove'])) {
                self::remove_pdf($id, $pl);
                $msg .= ' Αφαιρέθηκε το ' . $pname . ' PDF.';
                TW_DB::log_event('terms_pdf_removed', ['set' => self::name($id), 'lang' => strtoupper($pl)]);
            }
            if (!empty($_FILES['pdf_' . $pl]['name'])) {
                $r = self::store_pdf($id, $pl, $_FILES['pdf_' . $pl]);
                if (is_wp_error($r)) {
                    $msg .= ' ' . ucfirst($pname) . ' PDF: ' . $r->get_error_message();
                    $type = 'warning';
                } else {
                    $msg .= ' Ανέβηκε το ' . $pname . ' PDF.';
                    TW_DB::log_event('terms_pdf_uploaded', ['set' => self::name($id), 'lang' => strtoupper($pl), 'file' => self::pdf($id, $pl)['name']]);
                }
            }
        }
        TW_Admin::flash($msg, $type);
        TW_Admin::back('tw-terms', ['edit' => $id]);
    }

    /** Print page (save as PDF) of the text as it is in the edit form, before or after saving. */
    public static function handle_pdf_draft() {
        TW_Admin::guard('tw_terms_pdf_draft');
        $id = (int) TW_Admin::post('id');
        if ($id && !self::get($id)) $id = 0;
        $lang = TW_Admin::post('lang') === 'en' ? 'en' : 'el';
        $text = trim(str_replace("\r\n", "\n", self::clean_html(wp_unslash($_POST['text'] ?? ''))));
        if (trim(wp_strip_all_tags($text)) === '') wp_die('Το κείμενο των όρων είναι κενό.');
        $date = function ($k) { $v = TW_Admin::post($k); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : ''; };
        $from = $date('from');
        $to = $date('to');
        $versions = $id ? self::versions($id, $lang) : [];
        $saved = $versions ? end($versions) : null;
        $hash = hash('sha256', $text);
        $same = $saved && $saved['hash'] === $hash;
        $set = $id ? self::get($id) : null;
        $dates_saved = $set && $set['from'] === $from && $set['to'] === $to;
        $t = $same ? $saved + ['lang' => $lang] : ['text' => $text, 'hash' => $hash, 'published_at' => TW_DB::now(), 'lang' => $lang];
        $note = ($same && $dates_saved) ? '' : 'Προεπισκόπηση με αλλαγές που δεν έχουν αποθηκευτεί ακόμη. Πατήστε «Αποθήκευση» στη φόρμα, ώστε το PDF να συμφωνεί με τους όρους που βλέπουν οι πελάτες.';
        TW_I18n::with($lang, function () use ($id, $from, $to, $t, $note) {
            TW_Terms_Public::render_print($t, self::period_label_for($id, $from, $to), $note);
        });
        exit;
    }

    public static function handle_assign() {
        global $wpdb;
        TW_Admin::guard('tw_terms_assign');
        $id = (int) TW_Admin::post('id');
        if (!$id || $id === self::BASE || !self::get($id)) TW_Admin::back('tw-terms');
        $remove = TW_Admin::post('mode') === 'remove';
        $t = TW_DB::table('serials');
        $n = 0;
        $missing = [];
        $list = array_filter(array_map(['TW_Util', 'normalize_serial'], preg_split('/[\r\n,;]+/', TW_Admin::post('serials'))));
        foreach (array_chunk(array_values(array_unique($list)), 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%s'));
            $found = $wpdb->get_col($wpdb->prepare("SELECT serial_no FROM $t WHERE serial_no IN ($in)", $chunk));
            $missing = array_merge($missing, array_diff($chunk, $found));
            $sql = $remove ? "UPDATE $t SET terms_set = 0 WHERE terms_set = %d AND serial_no IN ($in)" : "UPDATE $t SET terms_set = %d WHERE serial_no IN ($in)";
            $n += (int) $wpdb->query($wpdb->prepare($sql, array_merge([$id], $chunk)));
        }
        $batch = mb_substr(sanitize_text_field(TW_Admin::post('batch')), 0, 64);
        if ($batch !== '') {
            $n += (int) $wpdb->query($remove
                ? $wpdb->prepare("UPDATE $t SET terms_set = 0 WHERE terms_set = %d AND import_batch = %s", $id, $batch)
                : $wpdb->prepare("UPDATE $t SET terms_set = %d WHERE import_batch = %s", $id, $batch));
        }
        TW_DB::log_event($remove ? 'terms_unassigned' : 'terms_assigned', ['set' => self::name($id), 'serials' => $n, 'batch' => $batch]);
        $msg = ($remove ? 'Αφαιρέθηκε η ανάθεση από ' : 'Ανατέθηκε σε ') . $n . ' σειριακούς.';
        if ($missing) $msg .= ' Δεν βρέθηκαν: ' . esc_html(implode(', ', array_slice($missing, 0, 30))) . (count($missing) > 30 ? '…' : '') . '.';
        TW_Admin::flash($msg, $missing ? 'warning' : 'success');
        TW_Admin::back('tw-terms', ['edit' => $id]);
    }

    public static function handle_delete() {
        TW_Admin::guard('tw_terms_delete');
        $id = (int) TW_Admin::post('id');
        $name = self::name($id);
        $r = self::delete_set($id);
        if (is_wp_error($r)) {
            TW_Admin::flash($r->get_error_message(), 'error');
            TW_Admin::back('tw-terms', ['edit' => $id]);
        }
        TW_DB::log_event('terms_deleted', ['set' => $name]);
        TW_Admin::flash('Το σετ όρων «' . esc_html($name) . '» διαγράφηκε.');
        TW_Admin::back('tw-terms');
    }
}


/**
 * Public side of the terms:
 *  [thermic_warranty_terms] – the most recent terms, with «Εύρεση όρων» by purchase date (and by serial number),
 *  and a printable / PDF copy that states the purchase period the terms cover.
 */
final class TW_Terms_Public {
    const SHORTCODE = 'thermic_warranty_terms';

    public static function init() {
        add_shortcode(self::SHORTCODE, [__CLASS__, 'shortcode']);
        foreach (['terms_lookup', 'terms_print', 'terms_pdf'] as $a) {
            add_action('wp_ajax_tw_' . $a, [__CLASS__, 'ajax_' . $a]);
            add_action('wp_ajax_nopriv_tw_' . $a, [__CLASS__, 'ajax_' . $a]);
        }
    }

    /** Everything the page needs about one set, in the current language. */
    public static function entry($set) {
        $t = TW_Terms::current($set, TW_I18n::lang());
        return [
            'set' => (int) $set,
            'version' => (int) $t['version'],
            'lang' => $t['lang'],
            'period' => TW_Terms::period_label($set),
            'label' => TW_Terms::updated_label($t),
            'html' => TW_Terms::html($t['text']),
            // The uploaded PDF in the language of the text shown, otherwise the automatic printable page.
            'pdf' => TW_Terms::pdf_current($set, $t['lang']) ? self::pdf_url($set, $t['lang']) : self::print_url($set, (int) $t['version'], $t['lang']),
        ];
    }

    public static function pdf_url($set, $lang) {
        $m = TW_Terms::pdf($set, $lang);
        return add_query_arg(['action' => 'tw_terms_pdf', 's' => (int) $set, 'l' => $lang, 'f' => $m ? substr(md5($m['file']), 0, 8) : ''], admin_url('admin-ajax.php'));
    }

    /** Serves the uploaded PDF of a set (public: the terms are public). */
    public static function ajax_terms_pdf() {
        $set = (int) ($_GET['s'] ?? 0);
        $lang = TW_I18n::norm(sanitize_key(wp_unslash($_GET['l'] ?? ''))) ?: 'el';
        $m = TW_Terms::get($set) ? TW_Terms::pdf($set, $lang) : null;
        if (!$m) wp_die(esc_html(tw__('Δεν βρέθηκε.')), '', 404);
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $m['name']) . '"');
        header('Content-Length: ' . filesize($m['path']));
        header('X-Content-Type-Options: nosniff');
        readfile($m['path']);
        exit;
    }

    public static function print_url($set, $version, $lang) {
        return add_query_arg(['action' => 'tw_terms_print', 's' => (int) $set, 'v' => (int) $version, 'tl' => $lang, 'lang' => TW_I18n::lang()], admin_url('admin-ajax.php'));
    }

    public static function shortcode($atts = []) {
        if (!wp_style_is('tw-front', 'registered')) TW_Frontend::register_assets();
        wp_enqueue_style('tw-front');
        wp_enqueue_script('tw-terms', TW_URL . 'assets/js/terms.js', [], TW_VERSION, true);
        $lang = TW_I18n::lang();
        // Dated sets (and the base set): the page finds the right one by purchase date without a round trip.
        $dated = [];
        $examples = [];
        foreach (TW_Terms::ranges() as $p) {
            $id = $p['id'];
            if (!TW_Terms::versions($id, 'el')) continue;
            $dated[] = ['from' => $p['from'], 'to' => $p['to'], 'base' => $p['base'], 'e' => self::entry($id)];
            // Example date inside each period (the first day, or the day before the next period for the base set).
            $ex = $p['from'] !== '' ? $p['from'] : ($p['to'] !== '' ? $p['to'] : '');
            if ($ex !== '' && $ex <= TW_Schedule::today()) $examples[] = $ex;
        }
        sort($examples);
        $latest = self::entry(TW_Terms::latest());
        $id = 'tw-terms-' . wp_unique_id();
        $cfg = [
            'ajax' => admin_url('admin-ajax.php'), 'lang' => $lang, 'dated' => $dated, 'latest' => $latest,
            't' => [
                'latest' => tw__('Ισχύοντες όροι εγγύησης'), 'yours' => tw__('Όροι για την αγορά σας'),
                'future' => tw__('Η ημερομηνία αγοράς δεν μπορεί να είναι μελλοντική.'), 'busy' => tw__('Αναζήτηση…'),
                'bydate' => tw__('Για αυτό το προϊόν οι όροι ορίζονται από την ημερομηνία αγοράς. Συμπληρώστε την ημερομηνία αγοράς.'), 'error' => tw__('Κάτι πήγε στραβά. Δοκιμάστε ξανά.'),
            ],
        ];
        ob_start();
        ?>
        <div class="tw-app tw-terms-finder" id="<?php echo esc_attr($id); ?>" lang="<?php echo esc_attr($lang); ?>" data-tw-terms="<?php echo esc_attr(wp_json_encode($cfg, JSON_UNESCAPED_UNICODE)); ?>">
            <aside class="tw-card tw-terms-search">
                <h3><?php tw_e('Εύρεση όρων'); ?></h3>
                <label class="tw-terms-field"><span><?php tw_e('Ημερομηνία αγοράς'); ?></span>
                    <input type="date" data-f="date" max="<?php echo esc_attr(TW_Schedule::today()); ?>"></label>
                <p class="tw-hint"><?php tw_e('Χρησιμοποιήστε την ημερομηνία στο παραστατικό αγοράς.'); ?></p>
                <?php if ($examples) : ?>
                    <p class="tw-terms-examples-title"><?php tw_e('Δοκιμάστε ένα παράδειγμα'); ?></p>
                    <div class="tw-terms-examples"><?php foreach ($examples as $ex) printf('<button type="button" class="tw-chip" data-date="%s">%s</button>', esc_attr($ex), esc_html(TW_Util::date_label($ex))); ?></div>
                <?php endif; ?>
                <form class="tw-terms-serial" data-f="serial-form" novalidate>
                    <label class="tw-terms-field"><span><?php tw_e('ή σειριακός αριθμός'); ?> <em class="tw-hint">(<?php tw_e('προαιρετικό'); ?>)</em></span>
                        <span class="tw-terms-serial-row"><input type="text" data-f="serial" autocomplete="off" spellcheck="false"><button class="tw-btn tw-btn--ghost" type="submit"><?php tw_e('Εύρεση'); ?></button></span></label>
                    <p class="tw-hint"><?php tw_e('Για προϊόντα με ειδικούς όρους, όπως αναγράφεται στην ετικέτα του προϊόντος.'); ?></p>
                </form>
                <p class="tw-alert tw-alert--error" data-f="error" hidden></p>
            </aside>
            <section class="tw-card tw-terms-result" aria-live="polite">
                <h3 data-f="title"><?php tw_e('Ισχύοντες όροι εγγύησης'); ?></h3>
                <p class="tw-terms-period" data-f="period"><?php echo esc_html($latest['period']); ?></p>
                <div class="tw-terms-text" data-f="text"<?php echo $latest['lang'] !== $lang ? ' lang="' . esc_attr($latest['lang']) . '"' : ''; ?>><?php echo $latest['html']; // escaped in entry() ?></div>
                <p class="tw-terms-foot"><span class="tw-hint" data-f="label"><?php echo esc_html($latest['label']); ?></span>
                    <a class="tw-btn tw-btn--ghost" data-f="pdf" href="<?php echo esc_url($latest['pdf']); ?>" target="_blank" rel="noopener"><?php tw_e('Λήψη PDF'); ?></a></p>
            </section>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Terms by serial number (sets assigned to serials cannot be found by date). */
    public static function ajax_terms_lookup() {
        if (!TW_Guard::hit('terms_lookup', 30, 600)) wp_send_json_error(['message' => tw__('Πολλές αναζητήσεις. Δοκιμάστε ξανά σε λίγα λεπτά.')], 429);
        $serial = TW_Util::normalize_serial(isset($_POST['serial']) ? wp_unslash($_POST['serial']) : '');
        $row = $serial !== '' ? TW_Warranty::find_serial($serial) : null;
        if (!$row || $row->status === 'void') wp_send_json_error(['message' => tw__('Ο σειριακός αριθμός δεν βρέθηκε. Ελέγξτε ότι τον πληκτρολογήσατε σωστά, όπως αναγράφεται στην ετικέτα του προϊόντος.')], 404);
        if ((int) $row->terms_set && TW_Terms::get($row->terms_set) && TW_Terms::versions($row->terms_set, 'el')) {
            wp_send_json_success(['own' => true] + self::entry((int) $row->terms_set));
        }
        wp_send_json_success(['own' => false]); // the terms depend on the purchase date
    }

    /** Printable copy (the browser saves it as PDF), with the purchase period the terms cover. */
    public static function ajax_terms_print() {
        $set = (int) ($_GET['s'] ?? 0);
        $tl = TW_I18n::norm(sanitize_key(wp_unslash($_GET['tl'] ?? ''))) ?: 'el';
        $t = TW_Terms::get($set) ? TW_Terms::version($set, (int) ($_GET['v'] ?? 0), $tl) : null;
        if (!$t) wp_die(esc_html(tw__('Δεν βρέθηκε.')), '', 404);
        self::render_print($t, TW_Terms::period_label($set));
        exit;
    }

    /**
     * The printable page of a terms text (the browser saves it as PDF), laid out like the warranty card:
     * header (logo, title, period) and footer (company, contact, version) on every page, page numbers from
     * @page margin boxes, and the watermark of Ρυθμίσεις → Εφαρμογή & εμφάνιση. $note: shown on screen only.
     */
    public static function render_print($t, $period, $note = '') {
        $s = TW_Settings::all();
        $lang = TW_I18n::lang();
        [$c1, $c2] = TW_App::colors();
        $updated = TW_Util::date_label(substr((string) $t['published_at'], 0, 10));
        // Document title = suggested PDF file name.
        $title = tw__('Όροι εγγύησης') . ' - ' . $s['company_name'] . ' - ' . str_replace('/', '-', $updated);
        $wm_type = $s['pdf_watermark'];
        $wm_opacity = max(0, min(100, (int) $s['pdf_watermark_opacity'])) / 100;
        $wm_text = trim((string) $s['pdf_watermark_text']) !== '' ? $s['pdf_watermark_text'] : $s['company_name'];
        $contact = TW_Settings::company_contact($lang);
        $css = function ($v) { return str_replace(['"', '\\', "\n"], ['', '', ' '], $v); };
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        ?><!doctype html>
<html lang="<?php echo esc_attr($lang); ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html($title); ?></title>
<link rel="stylesheet" href="<?php echo esc_url(TW_App::asset('assets/css/fonts.css')); ?>">
<link rel="stylesheet" href="<?php echo esc_url(TW_App::asset('assets/css/print.css')); ?>">
<style>
:root { --brand: <?php echo esc_html($c1); ?>; --side: <?php echo esc_html($c2); ?>; --wm-opacity: <?php echo esc_html((string) $wm_opacity); ?>; }
@page { size: A4; margin: 14mm 12mm 16mm;
    @bottom-right { content: "<?php echo esc_html($css(tw__('Σελίδα'))); ?> " counter(page) " <?php echo esc_html($css(tw__('από'))); ?> " counter(pages); font: 8.5pt 'Geom', Arial, sans-serif; color: #4b5563; }
    @bottom-left { content: "<?php echo esc_html($css(tw__('Όροι εγγύησης') . ' · ' . $s['company_name'])); ?>"; font: 8.5pt 'Geom', Arial, sans-serif; color: #4b5563; } }
body { font-size: 10.5pt; line-height: 1.55; }
.toolbar .note { background: #fff7e6; color: #5c3b00; border-radius: 6px; padding: 6px 10px; max-width: 640px; }
.terms-period { margin: 0 0 1mm; font-size: 11.5pt; font-weight: 700; color: #111827; }
.terms-meta { margin: 0 0 5mm; font-size: 8.5pt; color: #4b5563; }
.terms-text p { margin: 0 0 .7em; }
.terms-text h2 { font-size: 13pt; margin: 1.1em 0 .45em; padding-left: 2.5mm; border-left: 3pt solid var(--brand); break-after: avoid; }
.terms-text h3 { font-size: 11.5pt; margin: 1em 0 .4em; break-after: avoid; }
.terms-text h4 { font-size: 10.5pt; margin: .9em 0 .35em; break-after: avoid; }
.terms-text ul, .terms-text ol { margin: 0 0 .8em 1.4em; padding: 0; }
.terms-text li { margin: .15em 0; }
.terms-text blockquote { margin: .6em 0 1em; padding: 2.5mm 4mm; border-left: 3pt solid var(--brand); background: #f6f7f9; break-inside: avoid; }
.terms-text hr { border: 0; border-top: .8pt solid #9ca3af; margin: 1em 0; }
.terms-text a { color: inherit; }
.terms-text table { width: 100%; border-collapse: collapse; margin: .6em 0 1em; border: 1pt solid #9ca3af; }
.terms-text th, .terms-text td { border: .6pt solid #c4c9d1; padding: 1.7mm 2.4mm; text-align: left; vertical-align: top; }
.terms-text th { background: color-mix(in srgb, var(--brand) 12%, #fff); font-weight: 700; }
.terms-text tr { break-inside: avoid; }
.doc-foot .mono { font-size: 7.5pt; }
/* Printed: the footer is fixed at the bottom of every page (also the last, half-empty one); the table footer
   only keeps its space so the text never runs under it. */
.fixed-foot { display: none; }
@media print { .fixed-foot { display: flex; position: fixed; left: 1.5pt; right: 1.5pt; bottom: 0; margin: 0; padding-bottom: 0; background: #fff; z-index: 3; } table.page > tfoot .doc-foot { visibility: hidden; } }
</style></head><body>
<div class="toolbar">
    <button type="button" onclick="window.print()"><?php echo esc_html(tw__('Αποθήκευση ως PDF / Εκτύπωση')); ?></button>
    <?php if ($note !== '') : ?><span class="note"><?php echo esc_html($note); ?></span><?php endif; ?>
</div>
<?php if ($wm_type !== 'none' && $wm_opacity > 0) : ?>
    <div class="watermark" aria-hidden="true">
        <?php if ($wm_type === 'logo' && $s['logo_url']) : ?><img src="<?php echo esc_url($s['logo_url']); ?>" alt="">
        <?php else : ?><span><?php echo esc_html($wm_text); ?></span><?php endif; ?>
    </div>
<?php endif; ?>
<table class="page">
    <thead><tr><td>
        <header class="doc-head">
            <div class="brand"><?php if ($s['logo_url']) : ?><img src="<?php echo esc_url($s['logo_url']); ?>" alt="<?php echo esc_attr($s['company_name']); ?>"><?php else : ?><span class="brand-text"><?php echo esc_html($s['company_name']); ?></span><?php endif; ?></div>
            <div class="head-meta">
                <div class="doc-title"><?php tw_e('Όροι εγγύησης'); ?></div>
                <div class="doc-code"><?php echo esc_html(rtrim($period, '.')); ?></div>
            </div>
        </header>
    </td></tr></thead>
    <tfoot><tr><td>
        <footer class="doc-foot">
            <span><?php echo esc_html($contact === '' ? $s['company_name'] : (mb_stripos($contact, $s['company_name']) === 0 ? $contact : $s['company_name'] . ' – ' . $contact)); ?></span>
            <span><?php echo esc_html(TW_Terms::updated_label($t)); ?> · <span class="mono">SHA-256 <?php echo esc_html(substr($t['hash'], 0, 12)); ?>…</span></span>
        </footer>
    </td></tr></tfoot>
    <tbody><tr><td>
        <p class="terms-period"><?php echo esc_html($period); ?></p>
        <p class="terms-meta"><?php echo esc_html(TW_Terms::updated_label($t)); ?> · <?php echo esc_html(sprintf(tw__('Εκτυπώθηκε %s'), TW_Util::datetime_label(TW_DB::now()))); ?></p>
        <div class="terms-text"<?php echo $t['lang'] !== $lang ? ' lang="' . esc_attr($t['lang']) . '"' : ''; ?>><?php echo TW_Terms::html($t['text']); // sanitized ?></div>
    </td></tr></tbody>
</table>
<footer class="doc-foot fixed-foot">
            <span><?php echo esc_html($contact === '' ? $s['company_name'] : (mb_stripos($contact, $s['company_name']) === 0 ? $contact : $s['company_name'] . ' – ' . $contact)); ?></span>
            <span><?php echo esc_html(TW_Terms::updated_label($t)); ?> · <span class="mono">SHA-256 <?php echo esc_html(substr($t['hash'], 0, 12)); ?>…</span></span>
        </footer>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
</body></html><?php
    }
}
