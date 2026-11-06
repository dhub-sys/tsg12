<?php
if (!defined('ABSPATH')) exit;

/**
 * «Ημερολόγιο»: every recorded action (customers, team, system), with filters and Excel export.
 * Only for those who manage the team (WordPress administrators or «Μπορεί να διαχειρίζεται και την ομάδα»).
 */
final class TW_Activity {
    const PER_PAGE = 50;

    /** Category => actions (exact names, or prefixes ending in "*"). */
    public static function categories() {
        return [
            'warranty' => ['Εγγυήσεις', ['activated', 'status_*', 'locked', 'customer_corrected', 'anonymized', 'activation_email_resent', 'card_printed', 'invoice_downloaded', 'warranty_viewed']],
            'service' => ['Service', ['service_*', 'receipt_downloaded']],
            'customer' => ['Πελάτες & «Οι εγγυήσεις μου»', ['otp_sent', 'otp_email_failed', 'portal_*', 'marketing_*', 'erasure_*']],
            'messages' => ['Μηνύματα', ['message_*', 'activation_email_*', 'activation_sms_*', 'email_undeliverable', 'sms_undeliverable', 'fallback_sent']],
            'serials' => ['Σειριακοί', ['serial_*', 'serials_imported']],
            'access' => ['Συνδέσεις & ασφάλεια', ['staff_*', 'access_blocked', 'ip_lock_*', 'log_verified', 'log_archived']],
            'team' => ['Ομάδα', ['team_*']],
            'settings' => ['Ρυθμίσεις', ['settings_saved', 'terms_published', 'logo_uploaded', 'remote_*', 'backup_settings']],
            'backups' => ['Αντίγραφα ασφαλείας', ['backup_created', 'backup_downloaded', 'backup_deleted', 'backup_restored', 'backup_failed', 'backup_sent', 'backup_send_failed']],
            'exports' => ['Εξαγωγές', ['*_exported']],
        ];
    }

    /** Labels for actions not named on the warranty card (TW_Admin::event_label has the rest). */
    public static function label($action) {
        $map = [
            'message_sent' => 'Αποστολή μηνύματος', 'message_failed' => 'Αποτυχία αποστολής μηνύματος',
            'staff_logout' => 'Αποσύνδεση από το διαχειριστικό', 'staff_login_failed' => 'Αποτυχημένη σύνδεση στο διαχειριστικό',
            'staff_otp_failed' => 'Λάθος κωδικός μίας χρήσης (διαχειριστικό)', 'staff_login_blocked' => 'Μπλοκάρισμα σύνδεσης (πολλές αποτυχίες)',
            'staff_password_set' => 'Ορισμός κωδικού πρόσβασης', 'access_blocked' => 'Άρνηση πρόσβασης (IP εκτός λίστας)',
            'ip_lock_on' => 'Ενεργοποίηση περιορισμού IP', 'ip_lock_off' => 'Απενεργοποίηση περιορισμού IP', 'ip_lock_changed' => 'Αλλαγή λίστας IP',
            'team_added' => 'Προσθήκη μέλους ομάδας', 'team_removed' => 'Αφαίρεση μέλους ομάδας',
            'settings_saved' => 'Αλλαγή ρυθμίσεων', 'terms_published' => 'Νέα έκδοση όρων', 'logo_uploaded' => 'Νέο λογότυπο',
            'remote_settings' => 'Ρυθμίσεις αποστολής αντιγράφων', 'remote_connected' => 'Σύνδεση υπηρεσίας αντιγράφων', 'remote_disconnected' => 'Αποσύνδεση υπηρεσίας αντιγράφων',
            'backup_settings' => 'Ρυθμίσεις αντιγράφων ασφαλείας', 'backup_created' => 'Δημιουργία αντιγράφου ασφαλείας', 'backup_downloaded' => 'Λήψη αντιγράφου ασφαλείας',
            'backup_deleted' => 'Διαγραφή αντιγράφου ασφαλείας', 'backup_restored' => 'Επαναφορά από αντίγραφο ασφαλείας', 'backup_failed' => 'Αποτυχία αντιγράφου ασφαλείας',
            'backup_sent' => 'Αποστολή αντιγράφου εκτός server', 'backup_send_failed' => 'Αποτυχία αποστολής αντιγράφου',
            'serials_imported' => 'Εισαγωγή σειριακών', 'serial_void' => 'Ακύρωση σειριακού', 'serial_available' => 'Επαναφορά σειριακού',
            'log_verified' => 'Έλεγχος ακεραιότητας ημερολογίου', 'log_archived' => 'Αρχειοθέτηση καταγραφών', 'warranty_viewed' => 'Προβολή καρτέλας από την ομάδα',
            'serials_exported' => 'Εξαγωγή σειριακών', 'warranties_exported' => 'Εξαγωγή εγγυήσεων', 'log_exported' => 'Εξαγωγή ημερολογίου',
        ];
        return $map[$action] ?? TW_Admin::event_label($action);
    }

    public static function category_of($action) {
        foreach (self::categories() as $key => $c) {
            foreach ($c[1] as $p) if (self::matches($action, $p)) return $c[0];
        }
        return 'Άλλο';
    }

    private static function matches($action, $pattern) {
        if (substr($pattern, -1) === '*') return strpos($action, substr($pattern, 0, -1)) === 0;
        if ($pattern[0] === '*') return substr($action, -strlen(substr($pattern, 1))) === substr($pattern, 1);
        return $action === $pattern;
    }

    public static function who_type($actor) {
        if (strpos($actor, 'customer:') === 0) return 'Πελάτης';
        if (strpos($actor, 'admin:') === 0) return 'Ομάδα';
        if ($actor === 'visitor') return 'Επισκέπτης';
        return 'Σύστημα';
    }

    public static function who($actor) {
        return $actor === 'visitor' ? 'Επισκέπτης' : TW_Admin::actor_label($actor);
    }

    // ------------------------------------------------------------------ query

    /** Current filters from the request, with defaults (last 7 days). */
    public static function filters() {
        $g = function ($k) { return isset($_GET[$k]) ? sanitize_text_field(wp_unslash($_GET[$k])) : ''; };
        $date = function ($v) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : ''; };
        $f = [
            'from' => $date($g('from')) ?: TW_Schedule::add_days(TW_Schedule::today(), -6),
            'to' => $date($g('to')) ?: TW_Schedule::today(),
            'who' => in_array($g('who'), ['customer', 'team', 'system', 'visitor'], true) ? $g('who') : '',
            'user' => sanitize_user($g('user')),
            'cat' => isset(self::categories()[$g('cat')]) ? $g('cat') : '',
            'q' => $g('q'),
            'src' => $g('src') === 'archive' ? 'archive' : '',
            'wid' => (int) $g('wid'),
        ];
        if ($f['from'] > $f['to']) [$f['from'], $f['to']] = [$f['to'], $f['from']];
        // A word search reads the details of every entry in the period: keep it to one year.
        if ($f['q'] !== '' && TW_Schedule::diff_days($f['from'], $f['to']) > self::SEARCH_MAX_DAYS) {
            $f['from'] = TW_Schedule::add_days($f['to'], -self::SEARCH_MAX_DAYS);
            self::$clamped = true;
        }
        return $f;
    }

    const SEARCH_MAX_DAYS = 366;
    private static $clamped = false;

    /** The current log or the archive. */
    public static function table($src = '') {
        return TW_DB::table($src === 'archive' ? 'events_archive' : 'events');
    }

    /** @return array [sql FROM/WHERE part, params] */
    private static function where(array $f, $joins = true) {
        global $wpdb;
        $e = self::table($f['src'] ?? '');
        // Counting without a word search needs no joins (the log table alone, through its indexes).
        $joins = $joins || $f['q'] !== '';
        $sql = " FROM $e e" . ($joins ? " LEFT JOIN " . TW_DB::table('warranties') . " w ON w.id = e.warranty_id LEFT JOIN " . TW_DB::table('serials') . " s ON s.id = IF(e.serial_id > 0, e.serial_id, w.serial_id)" : '')
            . " WHERE e.created_at >= %s AND e.created_at <= %s";
        $p = [$f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59'];
        if ($f['who'] === 'customer') $sql .= " AND e.actor LIKE 'customer:%'";
        elseif ($f['who'] === 'team') $sql .= " AND e.actor LIKE 'admin:%'";
        elseif ($f['who'] === 'visitor') $sql .= " AND e.actor = 'visitor'";
        elseif ($f['who'] === 'system') $sql .= " AND (e.actor = 'system' OR e.actor LIKE 'system:%' OR e.actor = '')";
        if ($f['user'] !== '') { $sql .= ' AND e.actor = %s'; $p[] = 'admin:' . $f['user']; }
        if (!empty($f['wid'])) { $sql .= ' AND e.warranty_id = %d'; $p[] = $f['wid']; }
        if ($f['cat'] !== '') {
            $parts = [];
            foreach (self::categories()[$f['cat']][1] as $pat) {
                if (substr($pat, -1) === '*') { $parts[] = 'e.action LIKE %s'; $p[] = $wpdb->esc_like(substr($pat, 0, -1)) . '%'; }
                elseif ($pat[0] === '*') { $parts[] = 'e.action LIKE %s'; $p[] = '%' . $wpdb->esc_like(substr($pat, 1)); }
                else { $parts[] = 'e.action = %s'; $p[] = $pat; }
            }
            $sql .= ' AND (' . implode(' OR ', $parts) . ')';
        }
        if ($f['q'] !== '') {
            $l = '%' . $wpdb->esc_like($f['q']) . '%';
            $sql .= ' AND (e.actor LIKE %s OR e.details LIKE %s OR e.ip LIKE %s OR s.serial_no LIKE %s OR w.warranty_code LIKE %s OR w.customer_name LIKE %s OR w.email LIKE %s)';
            array_push($p, $l, $l, $l, $l, $l, $l, $l);
        }
        return [$sql, $p];
    }

    private static function select() {
        return 'SELECT e.*, s.serial_no AS serial, w.warranty_code, w.customer_name';
    }

    // ------------------------------------------------------------------ page

    public static function page() {
        global $wpdb;
        if (!TW_Admin::can_admin()) { echo '<div class="wrap tw-wrap"><h1>Ημερολόγιο</h1><p>Δεν έχετε πρόσβαση σε αυτή τη σελίδα.</p></div>'; return; }
        $f = self::filters();
        [$where, $p] = self::where($f);
        [$cwhere, $cp] = self::where($f, false);
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*)' . $cwhere, $cp));
        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $rows = $wpdb->get_results($wpdb->prepare(self::select() . $where . ' ORDER BY e.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($paged - 1) * self::PER_PAGE), $p));
        $team = get_users(['capability' => TW_Admin::cap(), 'orderby' => 'display_name', 'fields' => ['user_login', 'display_name']]);
        $export = wp_nonce_url(add_query_arg(array_merge(['action' => 'tw_log_export'], array_filter($f)), TW_App::admin_url('log')), 'tw_log_export');
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $presets = ['Σήμερα' => [0, 0], 'Χθες' => [-1, -1], '7 ημέρες' => [-6, 0], '30 ημέρες' => [-29, 0]];
        ?>
        <div class="wrap tw-wrap">
            <h1 class="wp-heading-inline">Ημερολόγιο</h1>
            <a class="page-title-action" href="<?php echo esc_url($export); ?>"><?php echo TW_Admin::icon('download'); // phpcs:ignore ?> Εξαγωγή Excel (με τα τρέχοντα φίλτρα)</a>
            <form method="post" action="<?php echo esc_url(TW_App::admin_url('log', array_filter($f))); ?>" style="display:inline">
                <?php wp_nonce_field('tw_log_verify'); ?><input type="hidden" name="action" value="tw_log_verify">
                <button class="page-title-action" type="submit"><?php echo TW_Admin::icon('shield'); // phpcs:ignore ?> Έλεγχος ακεραιότητας</button>
            </form>
            <?php
            $vr = get_transient('tw_log_verify_' . get_current_user_id());
            if ($vr) {
                delete_transient('tw_log_verify_' . get_current_user_id());
                $bad = count($vr['changed']) + count($vr['gaps']);
                echo '<div class="notice ' . ($bad ? 'notice-error' : 'notice-success') . ' is-dismissible"><p><strong>Έλεγχος ακεραιότητας ' . esc_html(TW_Util::date_label($vr['from']) . ' – ' . TW_Util::date_label($vr['to'])) . ':</strong> ';
                echo esc_html(sprintf('ελέγχθηκαν %d καταγραφές.', $vr['checked'])) . ' ';
                if (!$bad) echo 'Καμία αλλοίωση ή διαγραφή.';
                if ($vr['changed']) echo esc_html(sprintf(' ⚠ %d καταγραφές έχουν αλλοιωθεί (αριθμοί: %s).', count($vr['changed']), implode(', ', array_slice($vr['changed'], 0, 20)))) . ' ';
                foreach (array_slice($vr['gaps'], 0, 10) as $g) echo esc_html(sprintf(' ⚠ Λείπουν οι καταγραφές με αριθμούς %d–%d (πιθανή διαγραφή).', $g[0], $g[1])) . ' ';
                if ($vr['unsigned']) echo esc_html(sprintf(' %d παλαιότερες καταγραφές δεν έχουν υπογραφή (πριν την έκδοση 1.21).', $vr['unsigned']));
                echo '</p></div>';
            }
            ?>
            <?php
            $note = get_transient('tw_log_notice_' . get_current_user_id());
            if ($note) { delete_transient('tw_log_notice_' . get_current_user_id()); echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($note) . '</p></div>'; }
            if (self::$clamped) echo '<div class="notice notice-info"><p>Η αναζήτηση με λέξη γίνεται για έως 1 έτος· περιορίστηκε στο διάστημα ' . esc_html(TW_Util::date_label($f['from']) . ' – ' . TW_Util::date_label($f['to'])) . '. Για παλαιότερο διάστημα αλλάξτε τις ημερομηνίες.</p></div>';
            $n_arch = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('archive'));
            $base = array_filter(array_diff_key($f, ['src' => 1]));
            ?>
            <nav class="nav-tab-wrapper tw-log-src">
                <a class="nav-tab<?php echo $f['src'] === '' ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(TW_App::admin_url('log', $base)); ?>">Τρέχον ημερολόγιο</a>
                <a class="nav-tab<?php echo $f['src'] === 'archive' ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(TW_App::admin_url('log', array_merge($base, ['src' => 'archive']))); ?>">Αρχείο (<?php echo (int) $n_arch; ?>)</a>
            </nav>
            <p class="description" style="max-width:820px">Όλες οι ενέργειες που καταγράφονται στην πλατφόρμα, από πελάτες, την ομάδα και το σύστημα (αυτόματες εργασίες, μηνύματα), με ημερομηνία, ώρα και διεύθυνση IP. Τη σελίδα βλέπουν μόνο όσοι διαχειρίζονται την ομάδα.</p>
            <form method="get" class="tw-filters tw-log-filters">
                <?php foreach (TW_App::admin_base_query() as $k => $v) echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">'; ?>
                <input type="hidden" name="screen" value="log">
                <?php if ($f['src'] === 'archive') : ?><input type="hidden" name="src" value="archive"><?php endif; ?>
                <?php if ($f['wid']) : $fw = TW_Warranty::get($f['wid']); ?><input type="hidden" name="wid" value="<?php echo (int) $f['wid']; ?>"><span class="tw-chip">Εγγύηση <?php echo esc_html($fw ? $fw->warranty_code : '#' . $f['wid']); ?> <a href="<?php echo esc_url(TW_App::admin_url('log', array_filter(array_diff_key($f, ['wid' => 1])))); ?>" aria-label="Αφαίρεση φίλτρου">×</a></span><?php endif; ?>
                <label>Από <input type="date" name="from" value="<?php echo esc_attr($f['from']); ?>"></label>
                <label>Έως <input type="date" name="to" value="<?php echo esc_attr($f['to']); ?>"></label>
                <select name="who" aria-label="Ποιος">
                    <?php foreach (['' => 'Όλοι', 'customer' => 'Πελάτες', 'team' => 'Ομάδα', 'system' => 'Σύστημα', 'visitor' => 'Επισκέπτες (αποτυχίες)'] as $k => $l) printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($f['who'], $k, false), esc_html($l)); ?>
                </select>
                <select name="user" aria-label="Μέλος ομάδας">
                    <option value="">Κάθε μέλος ομάδας</option>
                    <?php foreach ($team as $u) printf('<option value="%s"%s>%s</option>', esc_attr($u->user_login), selected($f['user'], $u->user_login, false), esc_html($u->display_name)); ?>
                </select>
                <select name="cat" aria-label="Κατηγορία">
                    <option value="">Όλες οι κατηγορίες</option>
                    <?php foreach (self::categories() as $k => $c) printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($f['cat'], $k, false), esc_html($c[0])); ?>
                </select>
                <input type="search" name="q" value="<?php echo esc_attr($f['q']); ?>" placeholder="Σειριακός, κωδικός, email, IP…">
                <button class="button">Φιλτράρισμα</button>
                <a class="button-link" href="<?php echo esc_url(TW_App::admin_url('log', $f['src'] ? ['src' => 'archive'] : [])); ?>">Καθαρισμός</a>
            </form>
            <p class="tw-log-presets">
                <?php foreach ($presets as $l => $r) printf('<a class="button button-small" href="%s">%s</a> ', esc_url(TW_App::admin_url('log', array_filter(array_merge($f, ['from' => TW_Schedule::add_days(TW_Schedule::today(), $r[0]), 'to' => TW_Schedule::add_days(TW_Schedule::today(), $r[1])])))), esc_html($l)); ?>
                <span class="description"><?php echo esc_html(sprintf('%s – %s · %d καταγραφές', TW_Util::date_label($f['from']), TW_Util::date_label($f['to']), $total)); ?></span>
            </p>
            <table class="widefat striped tw-log-table">
                <thead><tr><th>Ημ/νία &amp; ώρα</th><th>Ποιος</th><th>Ενέργεια</th><th>Εγγύηση / σειριακός</th><th>Λεπτομέρειες</th><th>IP</th></tr></thead>
                <tbody>
                <?php if (!$rows) : ?><tr><td colspan="6">Δεν βρέθηκαν καταγραφές με αυτά τα φίλτρα.</td></tr><?php endif; ?>
                <?php foreach ($rows as $r) : ?>
                    <tr>
                        <td class="tw-nowrap"><?php echo esc_html(TW_Util::datetime_label($r->created_at)); ?><br><small class="description">#<?php echo (int) $r->id; ?></small>
                            <?php if (self::row_state($r) === 'changed') : ?><br><span class="tw-log-bad" title="Η καταγραφή δεν ταιριάζει με την υπογραφή της: άλλαξε απευθείας στη βάση δεδομένων.">⚠ αλλοιωμένη</span><?php endif; ?></td>
                        <td><span class="tw-who tw-who--<?php echo esc_attr(['Πελάτης' => 'customer', 'Ομάδα' => 'team', 'Επισκέπτης' => 'visitor'][self::who_type($r->actor)] ?? 'system'); ?>"><?php echo esc_html(self::who_type($r->actor)); ?></span><br><?php echo esc_html(self::who($r->actor)); ?></td>
                        <td><?php echo esc_html(self::label($r->action)); ?><br><small class="description"><?php echo esc_html(self::category_of($r->action)); ?></small></td>
                        <td><?php if ($r->warranty_id && $r->warranty_code) : ?><a href="<?php echo esc_url(TW_App::admin_url('warranties', ['view' => $r->warranty_id])); ?>"><?php echo esc_html($r->warranty_code); ?></a><br><?php endif; ?><?php if ($r->serial) : ?><code><?php echo esc_html($r->serial); ?></code><?php endif; ?></td>
                        <td class="tw-log-details"><?php echo esc_html(self::details($r)); ?></td>
                        <td class="tw-nowrap"><?php echo esc_html($r->ip ?: '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($pages > 1) : ?>
                <p class="tw-pagination">
                    <?php if ($paged > 1) printf('<a class="button" href="%s">← Νεότερες</a> ', esc_url(TW_App::admin_url('log', array_filter(array_merge($f, ['paged' => $paged - 1]))))); ?>
                    <span>Σελίδα <?php echo (int) $paged; ?> από <?php echo (int) $pages; ?></span>
                    <?php if ($paged < $pages) printf(' <a class="button" href="%s">Παλαιότερες →</a>', esc_url(TW_App::admin_url('log', array_filter(array_merge($f, ['paged' => $paged + 1]))))); ?>
                </p>
            <?php endif; ?>
            <?php
            $live_n = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table());
            $oldest = $wpdb->get_var('SELECT MIN(created_at) FROM ' . self::table());
            $auto = (int) TW_Settings::get('log_archive_years');
            ?>
            <section class="tw-panel tw-log-archive">
                <h2>Αρχειοθέτηση</h2>
                <p class="description">Οι παλιές καταγραφές μπορούν να μεταφερθούν στο «Αρχείο», ώστε το τρέχον ημερολόγιο να μένει ελαφρύ. Στο αρχείο δεν χάνεται τίποτα: αναζητείται, εξάγεται και ελέγχεται για ακεραιότητα όπως το τρέχον, και περιλαμβάνεται στα αντίγραφα ασφαλείας. Τρέχον ημερολόγιο: <strong><?php echo (int) $live_n; ?></strong> καταγραφές<?php echo $oldest ? ', η παλαιότερη από ' . esc_html(TW_Util::date_label(substr($oldest, 0, 10))) : ''; ?> · Αρχείο: <strong><?php echo (int) $n_arch; ?></strong>.</p>
                <form method="post" action="<?php echo esc_url(TW_App::admin_url('log')); ?>" class="tw-inline-form" onsubmit="return confirm('Μεταφορά στο αρχείο των καταγραφών που είναι παλαιότερες από την επιλογή;');">
                    <?php wp_nonce_field('tw_log_archive'); ?><input type="hidden" name="action" value="tw_log_archive">
                    <label>Μεταφορά στο αρχείο των καταγραφών παλαιότερων από
                        <select name="years"><?php foreach (self::ARCHIVE_YEARS as $y) printf('<option value="%d"%s>%d %s</option>', $y, selected($y, 3, false), $y, $y === 1 ? 'έτος' : 'έτη'); ?></select></label>
                    <button class="button">Αρχειοθέτηση τώρα</button>
                </form>
                <form method="post" action="<?php echo esc_url(TW_App::admin_url('log')); ?>" class="tw-inline-form">
                    <?php wp_nonce_field('tw_log_archive_settings'); ?><input type="hidden" name="action" value="tw_log_archive_settings">
                    <label>Αυτόματη αρχειοθέτηση (κάθε ημέρα)
                        <select name="auto_years"><option value="0">Ποτέ</option><?php foreach (self::ARCHIVE_YEARS as $y) printf('<option value="%d"%s>Καταγραφές παλαιότερες από %d %s</option>', $y, selected($auto, $y, false), $y, $y === 1 ? 'έτος' : 'έτη'); ?></select></label>
                    <button class="button">Αποθήκευση</button>
                </form>
            </section>
        </div>
        <?php
    }

    private static function details($r) {
        $raw = json_decode((string) $r->details, true);
        if (is_array($raw) && isset($raw['ip'])) { unset($raw['ip']); $r = clone $r; $r->details = wp_json_encode($raw, JSON_UNESCAPED_UNICODE); } // the IP has its own column
        $d = TW_Admin::event_details($r->details);
        $map = ['section' => 'Ενότητα', 'message' => 'Μήνυμα', 'channel' => 'Μέσο', 'filters' => 'Φίλτρα', 'columns' => 'Στήλες', 'ips' => 'IP', 'from' => 'Από', 'checked' => 'Ελέγχθηκαν', 'gaps' => 'Κενά', 'count' => 'Πλήθος', 'before' => 'Πριν από'];
        $sections = ['general' => 'Γενικά', 'sender' => 'Αποστολέας', 'app' => 'Εφαρμογή & εμφάνιση', 'schedule' => 'Εγγύηση & ειδοποιήσεις', 'terms' => 'Όροι & συγκαταθέσεις',
            'messages' => 'Κείμενα μηνυμάτων', 'brevo' => 'Brevo', 'sms' => 'Άλλος πάροχος SMS', 'security' => 'Ασφάλεια', 'privacy' => 'GDPR', 'log' => 'Ημερολόγιο'];
        $d = preg_replace_callback('/\b(section|message|channel|filters|columns|ips|from|checked|gaps|count|before): /', function ($m) use ($map) { return $map[$m[1]] . ': '; }, $d);
        return preg_replace_callback('/Ενότητα: ([a-z]+)/', function ($m) use ($sections) { return 'Ενότητα: ' . ($sections[$m[1]] ?? $m[1]); }, $d);
    }

    // ------------------------------------------------------------------ integrity

    /** 'ok' | 'changed' | 'unsigned' (entries from before signing existed) */
    public static function row_state($r) {
        if ((string) $r->sig === '') return 'unsigned';
        return hash_equals((string) $r->sig, TW_DB::event_signature($r)) ? 'ok' : 'changed';
    }

    /**
     * Checks every entry in the period: altered entries (signature mismatch) and missing entry numbers
     * between signed entries (possible deletion). @return array
     */
    public static function verify($from, $to, $src = '') {
        global $wpdb;
        $e = self::table($src);
        $live = TW_DB::table('events'); $arch = TW_DB::table('events_archive');
        $out = ['checked' => 0, 'ok' => 0, 'unsigned' => 0, 'changed' => [], 'gaps' => []];
        $prev = null; $last = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $e WHERE created_at >= %s AND created_at <= %s AND id > %d ORDER BY id LIMIT 2000", $from . ' 00:00:00', $to . ' 23:59:59', $last));
            foreach ($rows as $r) {
                $out['checked']++;
                $st = self::row_state($r);
                if ($st === 'ok') $out['ok']++;
                elseif ($st === 'unsigned') $out['unsigned']++;
                else $out['changed'][] = (int) $r->id;
                if ($st !== 'unsigned' && $prev !== null && (int) $r->id > $prev + 1) {
                    // Numbers in between that do not exist at all (not merely outside the period).
                    // Entries moved to the archive (or still in the log) are not missing.
                    $missing = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $live WHERE id > %d AND id < %d", $prev, (int) $r->id))
                        + (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $arch WHERE id > %d AND id < %d", $prev, (int) $r->id));
                    if ($missing < (int) $r->id - $prev - 1) $out['gaps'][] = [$prev + 1, (int) $r->id - 1];
                }
                if ($st !== 'unsigned') $prev = (int) $r->id;
                $last = (int) $r->id;
            }
        } while (count($rows) === 2000);
        return $out;
    }

    public static function verify_action() {
        if (!TW_Admin::can_admin()) wp_die('', 403);
        check_admin_referer('tw_log_verify');
        $f = self::filters();
        $v = self::verify($f['from'], $f['to'], $f['src']);
        TW_DB::log_event('log_verified', ['source' => $f['src'] === 'archive' ? 'αρχείο' : 'τρέχον', 'from' => $f['from'], 'to' => $f['to'], 'checked' => $v['checked'], 'changed' => count($v['changed']), 'gaps' => count($v['gaps'])]);
        set_transient('tw_log_verify_' . get_current_user_id(), $v + ['from' => $f['from'], 'to' => $f['to']], 300);
        wp_safe_redirect(TW_App::admin_url('log', array_filter($f)));
        exit;
    }

    // ------------------------------------------------------------------ archive

    const ARCHIVE_YEARS = [1, 2, 3, 5, 7, 10];

    /**
     * Moves entries older than $before (Y-m-d) from the log to the archive table, in batches; ids and
     * signatures are kept, so the archive can be searched, exported and checked like the log. @return int moved
     */
    public static function archive($before, $actor = null) {
        global $wpdb;
        $live = TW_DB::table('events'); $arch = TW_DB::table('events_archive');
        $cols = 'id, warranty_id, serial_id, actor, action, details, ip, sig, created_at';
        $moved = 0;
        @set_time_limit(0);
        do {
            $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM $live WHERE created_at < %s ORDER BY id LIMIT 2000", $before . ' 00:00:00')));
            if (!$ids) break;
            $in = implode(',', $ids);
            $wpdb->query("DELETE FROM $arch WHERE id IN ($in)"); // in case a previous run stopped half-way
            if ($wpdb->query("INSERT INTO $arch ($cols) SELECT $cols FROM $live WHERE id IN ($in)") === false) break;
            $wpdb->query("DELETE FROM $live WHERE id IN ($in)");
            $moved += count($ids);
        } while (count($ids) === 2000);
        if ($moved) TW_DB::log_event('log_archived', ['count' => $moved, 'before' => $before], 0, 0, $actor);
        return $moved;
    }

    /** Daily: archive automatically, if set. */
    public static function auto_archive() {
        $y = (int) TW_Settings::get('log_archive_years');
        if ($y > 0) self::archive(gmdate('Y-m-d', strtotime(TW_Schedule::today() . " -$y years")), 'system');
    }

    public static function archive_action() {
        if (!TW_Admin::can_admin()) wp_die('', 403);
        check_admin_referer('tw_log_archive');
        $y = (int) ($_POST['years'] ?? 0);
        if (!in_array($y, self::ARCHIVE_YEARS, true)) wp_die('', 400);
        $before = gmdate('Y-m-d', strtotime(TW_Schedule::today() . " -$y years"));
        $n = self::archive($before);
        set_transient('tw_log_notice_' . get_current_user_id(), $n
            ? sprintf('Μεταφέρθηκαν στο αρχείο %d καταγραφές πριν από %s. Βρίσκονται στην καρτέλα «Αρχείο».', $n, TW_Util::date_label($before))
            : sprintf('Δεν υπάρχουν καταγραφές πριν από %s.', TW_Util::date_label($before)), 120);
        wp_safe_redirect(TW_App::admin_url('log'));
        exit;
    }

    public static function archive_settings_action() {
        if (!TW_Admin::can_admin()) wp_die('', 403);
        check_admin_referer('tw_log_archive_settings');
        $y = (int) ($_POST['auto_years'] ?? 0);
        if ($y !== 0 && !in_array($y, self::ARCHIVE_YEARS, true)) $y = 0;
        $old = (int) TW_Settings::get('log_archive_years');
        TW_Settings::update(['log_archive_years' => $y]);
        if ($old !== $y) TW_DB::log_event('settings_saved', ['section' => 'log', 'changed' => 'log_archive_years: ' . $old . ' → ' . $y]);
        set_transient('tw_log_notice_' . get_current_user_id(), $y ? sprintf('Οι καταγραφές παλαιότερες από %d %s θα μεταφέρονται αυτόματα στο αρχείο κάθε ημέρα.', $y, $y === 1 ? 'έτος' : 'έτη') : 'Η αυτόματη αρχειοθέτηση απενεργοποιήθηκε.', 120);
        wp_safe_redirect(TW_App::admin_url('log'));
        exit;
    }

    // ------------------------------------------------------------------ export

    public static function export() {
        global $wpdb;
        if (!TW_Admin::can_admin()) wp_die('', 403);
        check_admin_referer('tw_log_export');
        @set_time_limit(0);
        $f = self::filters();
        [$where, $p] = self::where($f);
        $x = new TW_Xlsx(['Α/Α', 'Ημερομηνία', 'Ώρα', 'Τύπος', 'Ποιος', 'Κατηγορία', 'Ενέργεια', 'Κωδικός εγγύησης', 'Σειριακός αριθμός', 'Πελάτης', 'Λεπτομέρειες', 'IP', 'Ακεραιότητα']);
        $states = ['ok' => 'ΟΚ', 'changed' => 'ΑΛΛΟΙΩΜΕΝΗ', 'unsigned' => 'χωρίς υπογραφή'];
        $last = PHP_INT_MAX;
        do {
            $rows = $wpdb->get_results($wpdb->prepare(self::select() . $where . ' AND e.id < %d ORDER BY e.id DESC LIMIT 2000', array_merge($p, [$last])));
            foreach ($rows as $r) {
                $x->add([(string) $r->id, TW_Util::date_label(substr($r->created_at, 0, 10)), substr($r->created_at, 11, 8), self::who_type($r->actor), self::who($r->actor), self::category_of($r->action),
                    self::label($r->action), (string) $r->warranty_code, (string) $r->serial, (string) $r->customer_name, self::details($r), (string) $r->ip, $states[self::row_state($r)]]);
                $last = (int) $r->id;
            }
        } while (count($rows) === 2000);
        TW_DB::log_event('log_exported', ['filters' => array_filter($f)]);
        $x->download(($f['src'] === 'archive' ? 'imerologio-archeio-' : 'imerologio-') . $f['from'] . '_' . $f['to'] . '.xlsx', 'Ημερολόγιο');
    }
}
