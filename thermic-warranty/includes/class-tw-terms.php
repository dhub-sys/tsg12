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
        if (!isset($sets[self::BASE])) {
            $sets[self::BASE] = ['id' => self::BASE, 'name' => 'Βασικοί όροι', 'from' => '', 'created_at' => TW_DB::now()];
            update_option(self::SETS_OPTION, $sets, false);
        }
        uasort($sets, function ($a, $b) {
            if ((int) $a['id'] === self::BASE) return -1;
            if ((int) $b['id'] === self::BASE) return 1;
            return [$a['from'] === '' ? '9999' : $a['from'], $a['id']] <=> [$b['from'] === '' ? '9999' : $b['from'], $b['id']];
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

    /** Creates (id 0) or updates a set; returns its id. The base set always applies from the start. */
    public static function save_set($id, $name, $from) {
        $sets = self::sets();
        $id = (int) $id;
        if (!$id) $id = max(array_keys($sets)) + 1;
        $old = $sets[$id] ?? ['id' => $id, 'created_at' => TW_DB::now()];
        $sets[$id] = array_merge($old, [
            'name' => mb_substr(trim($name), 0, 120) ?: ('Όροι #' . $id),
            'from' => $id === self::BASE ? '' : $from,
        ]);
        update_option(self::SETS_OPTION, $sets, false);
        return $id;
    }

    /** Removes a set nobody has accepted (its serials go back to «by purchase date»). */
    public static function delete_set($id) {
        global $wpdb;
        $id = (int) $id;
        if ($id === self::BASE) return new WP_Error('base', 'Οι βασικοί όροι δεν διαγράφονται.');
        if (self::accepted_count($id)) return new WP_Error('used', 'Δεν διαγράφεται: υπάρχουν πελάτες που αποδέχθηκαν αυτούς τους όρους. Μπορείτε να αφαιρέσετε την ημερομηνία και τους σειριακούς, ώστε να μην εφαρμόζεται σε νέες ενεργοποιήσεις.');
        $wpdb->update(TW_DB::table('serials'), ['terms_set' => 0], ['terms_set' => $id]);
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

    /** Saves the text as a new version if it changed. Returns the current version number. */
    public static function save_text($set, $text, $lang = 'el') {
        $text = trim(str_replace("\r\n", "\n", (string) $text));
        $hash = hash('sha256', $text);
        $versions = self::versions($set, $lang);
        $current = $versions ? end($versions) : null;
        if ($current && $current['hash'] === $hash) return (int) $current['version'];
        if (!$current && $text === '') return 0;
        $n = $current ? (int) $current['version'] + 1 : 1;
        $versions[] = ['version' => $n, 'text' => $text, 'hash' => $hash, 'published_at' => TW_DB::now()];
        update_option(self::option($set, $lang), $versions, false);
        return $n;
    }

    /** All options holding terms (for backups). */
    public static function all_options() {
        $out = [self::SETS_OPTION => self::sets()];
        foreach (array_keys(self::sets()) as $id) {
            foreach (['el', 'en'] as $l) $out[self::option($id, $l)] = self::versions($id, $l);
        }
        return $out;
    }

    // ------------------------------------------------------------------ which set applies

    /** Sets that apply by purchase date: from-date => id, ascending ('' = base). */
    private static function dated() {
        $out = ['' => self::BASE];
        foreach (self::sets() as $id => $s) {
            if ((int) $id !== self::BASE && $s['from'] !== '' && self::versions($id, 'el')) $out[$s['from']] = (int) $id;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** The set for a product: the serial's own set if it has one, otherwise by purchase date. */
    public static function resolve($serial_set, $purchase_date) {
        $serial_set = (int) $serial_set;
        if ($serial_set && self::get($serial_set) && self::versions($serial_set, 'el')) return $serial_set;
        $pick = self::BASE;
        foreach (self::dated() as $from => $id) {
            if ($from === '' || ($purchase_date !== '' && $from <= $purchase_date)) $pick = $id;
        }
        return $pick;
    }

    /** The terms that apply to purchases made today («the most recent terms»). */
    public static function latest() {
        return self::resolve(0, TW_Schedule::today());
    }

    /**
     * What the activation form needs to show the right terms as soon as the customer enters the purchase date:
     * one entry if the serial has its own set, otherwise one per date (the form picks the latest «from» on or
     * before the purchase date).
     */
    public static function candidates($serial, $lang) {
        $own = (int) ($serial->terms_set ?? 0);
        $pairs = ($own && self::get($own) && self::versions($own, 'el')) ? ['' => $own] : self::dated();
        $out = [];
        foreach ($pairs as $from => $id) {
            $t = self::current($id, $lang);
            $out[] = [
                'from' => $from,
                'set' => (int) $id,
                'version' => (int) $t['version'],
                'lang' => $t['lang'],
                'html' => wpautop(esc_html($t['text'])),
                'label' => sprintf(tw__('Έκδοση όρων: %1$d (%2$s)'), (int) $t['version'], TW_Util::datetime_label($t['published_at'])) . ' · ' . self::period_label($id),
            ];
        }
        return $out;
    }

    /**
     * Purchase period a set covers by date: ['from' => Y-m-d|'', 'to' => Y-m-d|''], or null for a set that
     * applies only to assigned serials.
     */
    public static function period($set) {
        $set = (int) $set;
        $s = self::get($set);
        if (!$s || ($set !== self::BASE && $s['from'] === '')) return null;
        $froms = array_keys(self::dated());
        $from = $set === self::BASE ? '' : $s['from'];
        $to = '';
        foreach ($froms as $f) {
            if ($f !== '' && $f > $from) { $to = TW_Schedule::add_days($f, -1); break; }
        }
        return ['from' => $from, 'to' => $to];
    }

    /** «Για αγορές από 20/10/2026 έως 14/11/2026.» in the current customer language. */
    public static function period_label($set) {
        $p = self::period($set);
        if (!$p) return tw__('Για τα προϊόντα με συγκεκριμένους σειριακούς αριθμούς.');
        $d = function ($ymd) { return TW_Util::date_label($ymd); };
        if ($p['from'] !== '' && $p['to'] !== '') return sprintf(tw__('Για αγορές από %1$s έως %2$s.'), $d($p['from']), $d($p['to']));
        if ($p['from'] !== '') return sprintf(tw__('Για αγορές από %s και μετά.'), $d($p['from']));
        if ($p['to'] !== '') return sprintf(tw__('Για αγορές έως %s.'), $d($p['to']));
        return tw__('Για όλες τις αγορές.');
    }

    // ------------------------------------------------------------------ console screen «Όροι εγγύησης»

    private static function view_link($set, $version, $lang = 'el') {
        return wp_nonce_url(add_query_arg(['action' => 'tw_terms_view', 's' => (int) $set, 'v' => (int) $version, 'tl' => $lang], TW_Admin::url()), 'tw_terms_view');
    }

    private static function history($set, $lang) {
        $out = [];
        foreach (array_reverse(self::versions($set, $lang)) as $tv) {
            $out[] = sprintf('<a href="%s" target="_blank">v%d</a> <small>(%s)</small>', esc_url(self::view_link($set, $tv['version'], $lang)), (int) $tv['version'], esc_html(TW_Util::date_label(substr($tv['published_at'], 0, 10))));
        }
        return $out ? implode(' &nbsp; ', $out) : '—';
    }

    public static function page() {
        $sets = self::sets();
        $post = TW_Admin::url();
        $edit_q = TW_Admin::q('edit');
        $edit = $edit_q === 'new' ? ['id' => 0, 'name' => '', 'from' => '', 'created_at' => ''] : ($edit_q !== '' ? self::get((int) $edit_q) : null);
        $latest = self::latest();
        $batches = TW_Importer::batches();
        ?>
        <div class="wrap tw-wrap">
            <h1 class="wp-heading-inline">Όροι εγγύησης</h1>
            <a class="page-title-action" href="<?php echo esc_url(TW_Admin::url('terms', ['edit' => 'new']) . '#tw-terms-form'); ?>">+ Νέο σετ όρων</a>
            <?php TW_Admin::notice(); ?>

            <div class="tw-panel">
                <p>Μπορείτε να έχετε πολλά σετ όρων, π.χ. «Όροι 2026» και «Όροι από 15/11/2026». Κάθε πελάτης βλέπει και αποδέχεται, κατά την ενεργοποίηση, το σετ που αντιστοιχεί στο προϊόν του:</p>
                <ol>
                    <li>το σετ που έχει <strong>ανατεθεί στον σειριακό</strong> του (ανά σειριακό ή ανά παρτίδα), αλλιώς</li>
                    <li>το σετ με την πιο πρόσφατη ημερομηνία <strong>«Ισχύει για αγορές από»</strong> που είναι ίδια ή προγενέστερη της ημερομηνίας αγοράς, αλλιώς</li>
                    <li>τους <strong>Βασικούς όρους</strong>.</li>
                </ol>
                <p>Κάθε αλλαγή κειμένου δημιουργεί νέα έκδοση του σετ· οι παλαιότερες φυλάσσονται και για κάθε πελάτη καταγράφονται σετ, έκδοση και γλώσσα που αποδέχθηκε. Οι αλλαγές ισχύουν για νέες ενεργοποιήσεις· όσοι έχουν ήδη ενεργοποιήσει κρατούν τους όρους που αποδέχθηκαν.</p>
                <p><strong>Σήμερα, για αγορές σημερινής ημερομηνίας, ισχύει:</strong> <?php echo esc_html(self::name($latest)); ?>. Στη σελίδα του site: <code>[<?php echo esc_html(TW_Terms_Public::SHORTCODE); ?>]</code> (οι πιο πρόσφατοι όροι, με αναζήτηση όρων ανά σειριακό ή ημερομηνία αγοράς).</p>
            </div>

            <table class="widefat striped"><thead><tr><th>Σετ όρων</th><th>Ισχύει για αγορές από</th><th>Σειριακοί με ανάθεση</th><th>Πελάτες που τους αποδέχθηκαν</th><th>Εκδόσεις (EL)</th><th>Εκδόσεις (EN)</th><th></th></tr></thead><tbody>
            <?php foreach ($sets as $id => $st) : $assigned = self::assigned_count($id); ?>
                <tr<?php echo (int) $id === $latest ? ' style="font-weight:600"' : ''; ?>>
                    <td><?php echo esc_html($st['name']); ?><?php echo (int) $id === $latest ? ' <span class="tw-badge tw-badge--active">ισχύει σήμερα</span>' : ''; ?><?php echo !self::versions($id, 'el') ? ' <span class="tw-badge tw-badge--void">χωρίς κείμενο</span>' : ''; ?></td>
                    <td><?php echo (int) $id === self::BASE ? 'από την αρχή' : ($st['from'] !== '' ? esc_html(TW_Util::date_label($st['from'])) : '<span class="description">μόνο σε σειριακούς με ανάθεση</span>'); ?></td>
                    <td><?php echo $assigned ? '<a href="' . esc_url(TW_Admin::url('serials', ['terms_set' => $id])) . '">' . (int) $assigned . '</a>' : '—'; ?></td>
                    <td><?php echo (int) self::accepted_count($id); ?></td>
                    <td><?php echo self::history($id, 'el'); // phpcs:ignore ?></td>
                    <td><?php echo self::history($id, 'en'); // phpcs:ignore ?></td>
                    <td><a class="button" href="<?php echo esc_url(TW_Admin::url('terms', ['edit' => $id]) . '#tw-terms-form'); ?>">Επεξεργασία</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>

            <?php if ($edit) : $id = (int) $edit['id']; $el = $id ? self::current($id, 'el') : ['text' => '']; $enx = $id && self::versions($id, 'en') ? end(self::versions($id, 'en'))['text'] : ''; ?>
            <div class="tw-columns" id="tw-terms-form">
                <div class="tw-panel">
                    <h2><?php echo $id ? 'Επεξεργασία: ' . esc_html($edit['name']) : 'Νέο σετ όρων'; ?></h2>
                    <form method="post" action="<?php echo esc_url($post); ?>">
                        <?php wp_nonce_field('tw_terms_save'); ?><input type="hidden" name="action" value="tw_terms_save"><input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                        <p><label>Όνομα (για την ομάδα, π.χ. «Όροι από 15/11/2026») <input type="text" name="name" value="<?php echo esc_attr($edit['name']); ?>" maxlength="120" required></label></p>
                        <?php if ($id !== self::BASE) : ?>
                            <p><label>Ισχύει για αγορές από <span class="description">(προαιρετικό)</span> <input type="date" name="from" value="<?php echo esc_attr($edit['from']); ?>"></label>
                            <span class="description">Για αγορές από αυτή την ημερομηνία και μετά, έως την ημερομηνία ενός νεότερου σετ. Κενό: εφαρμόζεται μόνο σε σειριακούς που του αναθέσετε.</span></p>
                        <?php else : ?>
                            <p class="description">Οι βασικοί όροι ισχύουν όταν δεν ταιριάζει κανένα άλλο σετ (για αγορές πριν από το πρώτο σετ με ημερομηνία).</p>
                        <?php endif; ?>
                        <p><label>Κείμενο όρων (Ελληνικά) <textarea name="text_el" rows="16" class="large-text" required><?php echo esc_textarea($el['text']); ?></textarea></label></p>
                        <p><label>Terms (English) <span class="description">(προαιρετικό – χωρίς αγγλικό κείμενο, οι πελάτες στα αγγλικά βλέπουν τους ελληνικούς όρους του σετ)</span><textarea name="text_en" rows="10" class="large-text" lang="en"><?php echo esc_textarea($enx); ?></textarea></label></p>
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
        $from = TW_Admin::post('from');
        if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
        if ($from !== '') {
            foreach (self::sets() as $sid => $st) {
                if ((int) $sid !== $id && $st['from'] === $from) {
                    TW_Admin::flash('Υπάρχει ήδη σετ όρων («' . esc_html($st['name']) . '») που ισχύει από ' . esc_html(TW_Util::date_label($from)) . '. Επιλέξτε άλλη ημερομηνία.', 'error');
                    TW_Admin::back('tw-terms', ['edit' => $id ?: 'new']);
                }
            }
        }
        $text_el = sanitize_textarea_field(wp_unslash($_POST['text_el'] ?? ''));
        if (trim($text_el) === '') {
            TW_Admin::flash('Συμπληρώστε το κείμενο των όρων στα ελληνικά.', 'error');
            TW_Admin::back('tw-terms', ['edit' => $id ?: 'new']);
        }
        $is_new = !$id;
        $id = self::save_set($id, sanitize_text_field(TW_Admin::post('name')), $from);
        $before = self::versions($id, 'el') ? (int) self::current($id, 'el')['version'] : 0;
        $v = self::save_text($id, $text_el, 'el');
        $msg = $is_new ? 'Δημιουργήθηκε το σετ όρων «' . esc_html(self::name($id)) . '».' : 'Αποθηκεύτηκε.';
        if ($v !== $before) {
            $msg .= sprintf(' Νέα έκδοση v%d.', $v);
            TW_DB::log_event('terms_published', ['set' => self::name($id), 'version' => $v]);
        }
        $en = trim(sanitize_textarea_field(wp_unslash($_POST['text_en'] ?? '')));
        if ($en !== '' || self::versions($id, 'en')) {
            $before_en = self::versions($id, 'en') ? (int) end(self::versions($id, 'en'))['version'] : 0;
            $ve = self::save_text($id, $en, 'en');
            if ($ve !== $before_en) {
                $msg .= sprintf(' Νέα αγγλική έκδοση v%d.', $ve);
                TW_DB::log_event('terms_published', ['set' => self::name($id), 'version' => $ve . ' (EN)']);
            }
        }
        TW_Admin::flash($msg);
        TW_Admin::back('tw-terms', ['edit' => $id]);
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
        foreach (['terms_lookup', 'terms_print'] as $a) {
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
            'label' => sprintf(tw__('Έκδοση όρων: %1$d (%2$s)'), (int) $t['version'], TW_Util::datetime_label($t['published_at'])),
            'html' => wpautop(esc_html($t['text'])),
            'pdf' => self::print_url($set, (int) $t['version'], $t['lang']),
        ];
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
        foreach (array_keys(TW_Terms::sets()) as $id) {
            $p = TW_Terms::period($id);
            if (!$p || !TW_Terms::versions($id, 'el')) continue;
            $dated[] = ['from' => $p['from'], 'e' => self::entry($id)];
            // Example date inside each period (the first day, or the day before the next period for the base set).
            $ex = $p['from'] !== '' ? $p['from'] : ($p['to'] !== '' ? $p['to'] : '');
            if ($ex !== '' && $ex <= TW_Schedule::today()) $examples[] = $ex;
        }
        usort($dated, function ($a, $b) { return strcmp($a['from'], $b['from']); });
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
        $s = TW_Settings::all();
        $lang = TW_I18n::lang();
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        $title = tw__('Όροι εγγύησης') . ' – ' . $s['company_name'];
        ?><!doctype html>
<html lang="<?php echo esc_attr($lang); ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($title); ?></title>
<link rel="stylesheet" href="<?php echo esc_url(TW_URL . 'assets/css/fonts.css?ver=' . TW_VERSION); ?>">
<style>
@page { size: A4; margin: 18mm 16mm; }
body { font-family: 'Geom', Arial, sans-serif; color: #1d2330; line-height: 1.55; font-size: 11pt; max-width: 760px; margin: 24px auto; padding: 0 16px; }
header { display: flex; justify-content: space-between; align-items: center; gap: 16px; border-bottom: 2px solid #1d2330; padding-bottom: 10px; margin-bottom: 14px; }
header img { max-height: 48px; max-width: 200px; }
h1 { font-size: 20pt; margin: 0 0 4px; }
.period { font-weight: 700; font-size: 12pt; margin: 0 0 4px; }
.meta { color: #5f6b7a; font-size: 9pt; margin: 0 0 16px; }
.text p { margin: 0 0 .7em; }
footer { margin-top: 24px; border-top: 1px solid #d6d9de; padding-top: 8px; color: #5f6b7a; font-size: 8.5pt; }
.bar { text-align: center; margin: 0 0 16px; } .bar button { font: inherit; padding: 8px 18px; border-radius: 6px; border: 1px solid #1d2330; background: #1d2330; color: #fff; cursor: pointer; }
@media print { .bar { display: none; } body { margin: 0; max-width: none; padding: 0; } }
</style></head><body>
<div class="bar"><button type="button" onclick="window.print()"><?php echo esc_html(tw__('Αποθήκευση ως PDF / Εκτύπωση')); ?></button></div>
<header><div><h1><?php tw_e('Όροι εγγύησης'); ?></h1><div><?php echo esc_html($s['company_name']); ?></div></div><?php echo $s['logo_url'] ? TW_App::logo_html('logo') : ''; // phpcs:ignore ?></header>
<p class="period"><?php echo esc_html(TW_Terms::period_label($set)); ?></p>
<p class="meta"><?php echo esc_html(sprintf(tw__('Έκδοση όρων: %1$d (%2$s)'), (int) $t['version'], TW_Util::datetime_label($t['published_at']))); ?></p>
<div class="text"<?php echo $t['lang'] !== $lang ? ' lang="' . esc_attr($t['lang']) . '"' : ''; ?>><?php echo wpautop(esc_html($t['text'])); ?></div>
<footer><?php echo esc_html(sprintf(tw__('Εκτυπώθηκε %s'), TW_Util::datetime_label(TW_DB::now()))); ?> · SHA-256 <?php echo esc_html(substr($t['hash'], 0, 16)); ?>…</footer>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
</body></html><?php
        exit;
    }
}
