<?php
if (!defined('ABSPATH')) exit;

/**
 * Company back office, rendered inside the standalone admin app (see TW_App):
 * dashboard, warranties, serials (single + bulk import), notifications log, team, settings.
 * Forms post back to the app URL with an `action` field; TW_App::route_admin() dispatches to handle_*().
 */
final class TW_Admin {
    const PER_PAGE = 50;

    const CAP = 'tw_manage';

    public static function cap() {
        return apply_filters('tw_capability', self::CAP);
    }

    /** Actions accepted by the app router (POST, or GET for downloads). */
    public static function actions() {
        return ['import', 'add_serial', 'serial_status', 'record_service', 'delete_service', 'set_status', 'invoice', 'export',
            'save_settings', 'run_now', 'resend_activation', 'anonymize', 'test_message', 'terms_view', 'team_add', 'team_remove', 'process_erasure', 'template', 'review_service', 'receipt', 'brevo_tool', 'backup_create', 'backup_download', 'backup_delete', 'backup_restore', 'backup_settings',
            'backup_send', 'remote_settings', 'remote_connect', 'remote_disconnect', 'remote_test'];
    }

    /** Screens of the admin app: key => [label, dashicon-like glyph, callback]. */
    public static function screens() {
        $screens = [
            'dashboard' => ['Επισκόπηση', '◧', 'page_dashboard'],
            'warranties' => ['Εγγυήσεις & πελάτες', '🛡', 'page_warranties'],
            'serials' => ['Σειριακοί αριθμοί', '#', 'page_serials'],
            'notifications' => ['Ειδοποιήσεις', '✉', 'page_notifications'],
        ];
        if (self::can_admin()) {
            $screens['team'] = ['Ομάδα', '👥', 'page_team'];
            $screens['backups'] = ['Αντίγραφα ασφαλείας', '⛁', 'page_backups'];
        }
        $screens['settings'] = ['Ρυθμίσεις', '⚙', 'page_settings'];
        return $screens;
    }

    /** Platform administrators: WordPress admins and team members with team management rights. */
    public static function can_admin() {
        return current_user_can('manage_options') || current_user_can('tw_manage_team');
    }

    /** wp-admin only gets a launcher that opens the standalone app. */
    public static function init() {
        add_action('admin_menu', function () {
            $hook = add_menu_page('Εγγυήσεις', 'Εγγυήσεις ↗', self::cap(), 'tw-app', '__return_null', 'dashicons-shield', 56);
            add_action('load-' . $hook, function () {
                wp_safe_redirect(TW_App::admin_url());
                exit;
            });
        });
    }

    public static function url($screen = 'dashboard', array $args = []) {
        return TW_App::admin_url($screen, $args);
    }

    // ------------------------------------------------------------------ helpers

    private static function guard($action) {
        if (!current_user_can(self::cap())) wp_die('Δεν έχετε δικαίωμα πρόσβασης.', 403);
        check_admin_referer($action);
    }

    private static function back($page, array $args = []) {
        wp_safe_redirect(self::url(preg_replace('/^tw-/', '', $page), array_filter($args, function ($v) { return $v !== null; })));
        exit;
    }

    /** Hidden inputs so GET filter forms stay inside the app. */
    private static function get_form_fields($screen) {
        foreach (TW_App::admin_base_query() as $k => $v) {
            echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }
        echo '<input type="hidden" name="screen" value="' . esc_attr($screen) . '">';
    }

    private static function post($k) {
        return isset($_POST[$k]) ? trim(wp_unslash((string) $_POST[$k])) : '';
    }

    private static function q($k) {
        return isset($_GET[$k]) ? sanitize_text_field(wp_unslash((string) $_GET[$k])) : '';
    }

    private static function notice() {
        $msg = get_transient('tw_notice_' . get_current_user_id());
        if (!$msg) return;
        delete_transient('tw_notice_' . get_current_user_id());
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($msg['type']), wp_kses_post($msg['text']));
    }

    private static function flash($text, $type = 'success') {
        set_transient('tw_notice_' . get_current_user_id(), ['text' => $text, 'type' => $type], 120);
    }

    private static function pagination($total, $paged) {
        $pages = (int) ceil($total / self::PER_PAGE);
        if ($pages <= 1) return;
        echo '<div class="tablenav"><div class="tablenav-pages"><span class="displaying-num">' . (int) $total . ' εγγραφές</span> ';
        echo paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages]);
        echo '</div></div>';
    }

    private static function status_badge($status, $labels) {
        return '<span class="tw-badge tw-badge--' . esc_attr($status) . '">' . esc_html($labels[$status] ?? $status) . '</span>';
    }

    /** Latest delivery result per channel says the customer's email / mobile does not receive messages. */
    public static function contact_problems($w) {
        global $wpdb;
        $out = [];
        foreach (['email' => [$w->email, 'Το email του πελάτη (%s) απορρίπτει τα μηνύματα: %s. Ο πελάτης δεν λαμβάνει ειδοποιήσεις με email – επικοινωνήστε μαζί του για διόρθωση.'],
                  'sms' => [$w->phone_mobile, 'Τα SMS προς το κινητό του πελάτη (%s) δεν παραδίδονται: %s.']] as $ch => $def) {
            $last = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . TW_DB::table('notifications') . " WHERE warranty_id = %d AND channel = %s AND recipient = %s AND delivery_status NOT IN ('', 'sent') ORDER BY id DESC LIMIT 1",
                $w->id, $ch, $def[0]));
            if ($last && in_array($last->delivery_status, ['failed', 'complaint'], true)) {
                $out[] = sprintf($def[1], $def[0], $last->delivery_detail ?: TW_Brevo::status_labels()[$last->delivery_status]);
            }
        }
        return $out;
    }

    private static function select_row($key, $label, $current, array $options, $desc = '') {
        echo '<tr><th><label for="tw-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><select id="tw-' . esc_attr($key) . '" name="s[' . esc_attr($key) . ']">';
        foreach ($options as $v => $l) printf('<option value="%s"%s>%s</option>', esc_attr($v), selected($current, $v, false), esc_html($l));
        echo '</select>';
        if ($desc) echo '<p class="description">' . esc_html($desc) . '</p>';
        echo '</td></tr>';
    }

    private static function receipt_url($w, $sv) {
        return wp_nonce_url(add_query_arg(['action' => 'tw_receipt', 'id' => (int) $w->id, 'service_id' => (int) $sv->id], self::url()), 'tw_receipt');
    }

    private static function warranty_link($id, $text) {
        return '<a href="' . esc_url(self::url('warranties', ['view' => (int) $id])) . '">' . esc_html($text) . '</a>';
    }

    // ------------------------------------------------------------------ dashboard

    public static function page_dashboard() {
        global $wpdb;
        $s = TW_DB::table('serials');
        $w = TW_DB::table('warranties');
        $today = TW_Schedule::today();
        $serial_counts = $wpdb->get_results("SELECT status, COUNT(*) n FROM $s GROUP BY status", OBJECT_K);
        $w_counts = $wpdb->get_results("SELECT status, COUNT(*) n FROM $w GROUP BY status", OBJECT_K);
        $n = function ($arr, $k) { return isset($arr[$k]) ? (int) $arr[$k]->n : 0; };
        $exp60 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $w WHERE status = 'active' AND warranty_end BETWEEN %s AND %s", $today, TW_Schedule::add_days($today, 60)));
        $last30 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $w WHERE created_at >= %s", TW_Schedule::add_days($today, -30) . ' 00:00:00'));

        // Upcoming / overdue Service across active warranties (computed, the schedule is not stored).
        $upcoming = [];
        $horizon = TW_Schedule::add_days($today, 60);
        foreach ($wpdb->get_results(TW_Warranty::select_sql() . " WHERE w.status = 'active'") as $row) {
            foreach (TW_Warranty::timeline($row) as $m) {
                if ($m['type'] === 'service' && in_array($m['state'], ['upcoming', 'due', 'rejected'], true) && $m['due'] <= $horizon) {
                    $upcoming[] = ['w' => $row, 'm' => $m];
                }
            }
        }
        usort($upcoming, function ($a, $b) { return strcmp($a['m']['due'], $b['m']['due']); });
        $recent = $wpdb->get_results(TW_Warranty::select_sql() . " ORDER BY w.created_at DESC LIMIT 10");
        $sv_t = TW_DB::table('services');
        $recent_sv = $wpdb->get_results($wpdb->prepare("SELECT * FROM $sv_t WHERE source = 'customer' AND created_at >= %s ORDER BY id DESC LIMIT 50", TW_Schedule::add_days($today, -30) . ' 00:00:00'));
        $last = get_option('tw_last_run');
        $labels = TW_Util::status_labels();
        ?>
        <div class="wrap tw-wrap">
            <h1>Εγγυήσεις – Επισκόπηση</h1>
            <?php self::notice(); ?>
            <form method="get" class="tw-filters tw-lookup">
                <?php self::get_form_fields('warranties'); ?>
                <label for="tw-lookup"><strong>Αναζήτηση εγγύησης</strong></label>
                <input type="search" id="tw-lookup" name="s" placeholder="Σειριακός αριθμός, κωδικός εγγύησης, email ή τηλέφωνο" size="44" autofocus>
                <button class="button button-primary">Άνοιγμα καρτέλας</button>
            </form>
            <div class="tw-cards">
                <div class="tw-stat"><span><?php echo $n($serial_counts, 'available') + $n($serial_counts, 'registered') + $n($serial_counts, 'void'); ?></span>Σειριακοί στο σύστημα</div>
                <div class="tw-stat"><span><?php echo $n($serial_counts, 'available'); ?></span>Διαθέσιμοι (μη ενεργοποιημένοι)</div>
                <div class="tw-stat tw-stat--ok"><span><?php echo $n($w_counts, 'active'); ?></span>Ενεργές εγγυήσεις</div>
                <div class="tw-stat"><span><?php echo $n($w_counts, 'expired'); ?></span>Έληξαν</div>
                <div class="tw-stat tw-stat--bad"><span><?php echo $n($w_counts, 'locked'); ?></span>Οριστικά κλειδωμένες</div>
                <div class="tw-stat tw-stat--warn"><span><?php echo $exp60; ?></span>Λήγουν σε 60 ημέρες</div>
                <div class="tw-stat"><span><?php echo $last30; ?></span>Ενεργοποιήσεις 30 ημερών</div>
                <div class="tw-stat"><span><?php echo count($recent_sv); ?></span>Υποβολές Service 30 ημερών</div>
            </div>
            <p class="description">Τελευταία εκτέλεση ειδοποιήσεων: <?php echo $last ? esc_html(TW_Util::datetime_label($last['at']) . ' – ' . (int) $last['count'] . ' ενέργειες') : 'καμία ακόμη'; ?>.
                Επόμενη: <?php $nx = wp_next_scheduled(TW_Cron::HOOK); echo $nx ? esc_html(wp_date('d/m/Y H:i', $nx)) : '—'; ?>.</p>

            <h2>Πρόσφατες υποβολές Service από πελάτες (30 ημέρες)</h2>
            <table class="widefat striped"><thead><tr><th>Καταχώρηση</th><th>S/N</th><th>Πελάτης</th><th>Service</th><th>Ημ/νία Service</th><th>ΑΦΜ τεχνικού</th><th>Απόδειξη</th></tr></thead><tbody>
            <?php if (!$recent_sv) echo '<tr><td colspan="7">Καμία υποβολή τις τελευταίες 30 ημέρες.</td></tr>'; ?>
            <?php foreach ($recent_sv as $sv) : $pw = TW_Warranty::get($sv->warranty_id); if (!$pw) continue; ?>
                <tr><td><?php echo esc_html(TW_Util::datetime_label($sv->created_at)); ?></td><td><?php echo self::warranty_link($pw->id, $pw->serial); ?></td>
                    <td><?php echo esc_html($pw->customer_name); ?></td><td><?php echo (int) $sv->milestone_no; ?>ο</td>
                    <td><?php echo esc_html(TW_Util::date_label($sv->performed_date)); ?></td><td><code><?php echo esc_html($sv->technician_vat); ?></code></td>
                    <td><?php echo esc_html($sv->receipt_number ? 'αρ. ' . $sv->receipt_number . ($sv->receipt_date ? ' / ' . TW_Util::date_label($sv->receipt_date) : '') : ''); ?>
                        <?php echo $sv->receipt_file ? '<a href="' . esc_url(self::receipt_url($pw, $sv)) . '" target="_blank">Αρχείο</a>' : ''; ?><?php echo !$sv->receipt_number && !$sv->receipt_file ? '—' : ''; ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>

            <h2>Service σε εκκρεμότητα ή τις επόμενες 60 ημέρες (<?php echo count($upcoming); ?>)</h2>
            <table class="widefat striped"><thead><tr><th>Ημ/νία Service</th><th>Ορόσημο</th><th>S/N</th><th>Πελάτης</th><th>Κινητό</th><th>Συνεργάτης</th><th>Κατάσταση</th></tr></thead><tbody>
            <?php if (!$upcoming) echo '<tr><td colspan="7">Καμία εκκρεμότητα.</td></tr>'; ?>
            <?php foreach (array_slice($upcoming, 0, 100) as $u) : $row = $u['w']; $m = $u['m']; ?>
                <tr><td><?php echo esc_html(TW_Util::date_label($m['due'])); ?></td><td><?php echo (int) $m['no']; ?>ο (<?php echo (int) $m['years']; ?> έτη)</td>
                    <td><?php echo self::warranty_link($row->id, $row->serial); ?></td><td><?php echo esc_html($row->customer_name); ?></td>
                    <td><?php echo esc_html($row->phone_mobile); ?></td><td><?php echo esc_html($row->partner_vat . ' ' . $row->partner_email); ?></td>
                    <td><?php echo $m['state'] === 'due' ? '<strong class="tw-warn">Εκκρεμεί – κλείδωμα ' . esc_html(TW_Util::date_label($m['lock_date'])) . '</strong>' : 'Προσεχώς'; ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>

            <h2>Πρόσφατες ενεργοποιήσεις</h2>
            <table class="widefat striped"><thead><tr><th>Ημερομηνία</th><th>Κωδικός</th><th>S/N</th><th>Προϊόν</th><th>Πελάτης</th><th>Κατάσταση</th></tr></thead><tbody>
            <?php if (!$recent) echo '<tr><td colspan="6">Καμία ακόμη.</td></tr>'; ?>
            <?php foreach ($recent as $row) : ?>
                <tr><td><?php echo esc_html(TW_Util::datetime_label($row->created_at)); ?></td><td><?php echo self::warranty_link($row->id, $row->warranty_code); ?></td>
                    <td><?php echo esc_html($row->serial); ?></td><td><?php echo esc_html($row->product_name); ?></td><td><?php echo esc_html($row->customer_name); ?></td>
                    <td><?php echo self::status_badge($row->status, $labels); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }

    // ------------------------------------------------------------------ warranties

    /** WHERE clause shared by the list and the CSV export. */
    private static function warranty_filters(array $args) {
        global $wpdb;
        $where = ['1=1'];
        $params = [];
        if (!empty($args['s'])) {
            $like = '%' . $wpdb->esc_like($args['s']) . '%';
            $where[] = '(s.serial_no LIKE %s OR w.warranty_code LIKE %s OR w.customer_name LIKE %s OR w.email LIKE %s OR w.phone_mobile LIKE %s OR w.phone_landline LIKE %s OR w.partner_vat LIKE %s OR w.partner_email LIKE %s OR w.invoice_number LIKE %s OR s.product_name LIKE %s OR s.product_code LIKE %s OR w.postcode LIKE %s)';
            $params = array_merge($params, array_fill(0, 12, $like));
        }
        if (!empty($args['status']) && isset(TW_Util::status_labels()[$args['status']])) {
            $where[] = 'w.status = %s';
            $params[] = $args['status'];
        }
        if (!empty($args['expiring'])) {
            $where[] = "w.status = 'active' AND w.warranty_end BETWEEN %s AND %s";
            $params[] = TW_Schedule::today();
            $params[] = TW_Schedule::add_days(TW_Schedule::today(), (int) $args['expiring']);
        }
        if (!empty($args['from'])) { $where[] = 'w.purchase_date >= %s'; $params[] = $args['from']; }
        if (!empty($args['to'])) { $where[] = 'w.purchase_date <= %s'; $params[] = $args['to']; }
        $sql = implode(' AND ', $where);
        return $params ? $wpdb->prepare($sql, $params) : $sql;
    }

    /** Exact serial / warranty code from a search opens the card directly. Called by the router before output. */
    public static function maybe_open_exact() {
        global $wpdb;
        if (($_GET['screen'] ?? '') !== 'warranties' || !empty($_GET['view']) || empty($_GET['s'])) return;
        $q = trim(sanitize_text_field(wp_unslash($_GET['s'])));
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT w.id FROM " . TW_DB::table('warranties') . " w JOIN " . TW_DB::table('serials') . " s ON s.id = w.serial_id WHERE s.serial_no = %s OR w.warranty_code = %s LIMIT 1",
            TW_Util::normalize_serial($q), strtoupper($q)
        ));
        if ($id) {
            wp_safe_redirect(self::url('warranties', ['view' => (int) $id]));
            exit;
        }
        // A serial that exists but was never activated: say so on the serials screen.
        $serial = TW_Warranty::find_serial($q);
        if ($serial) {
            self::flash(sprintf('Ο σειριακός %s υπάρχει αλλά δεν έχει ενεργοποιηθεί εγγύηση (%s).', esc_html($serial->serial), esc_html(TW_Util::serial_status_labels()[$serial->status] ?? $serial->status)), 'warning');
            wp_safe_redirect(self::url('serials', ['s' => $serial->serial]));
            exit;
        }
    }

    public static function page_warranties() {
        if (!empty($_GET['view'])) {
            self::page_warranty((int) $_GET['view']);
            return;
        }
        global $wpdb;
        $args = ['s' => self::q('s'), 'status' => self::q('status'), 'expiring' => (int) self::q('expiring'), 'from' => self::q('from'), 'to' => self::q('to')];
        $where = self::warranty_filters($args);
        $paged = max(1, (int) self::q('paged'));
        $base = TW_Warranty::select_sql() . " WHERE $where";
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM (" . $base . ") x");
        $rows = $wpdb->get_results($base . ' ORDER BY w.created_at DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($paged - 1) * self::PER_PAGE));
        $labels = TW_Util::status_labels();
        $export = wp_nonce_url(add_query_arg(array_merge(['action' => 'tw_export', 'type' => 'warranties'], array_filter($args)), self::url('warranties')), 'tw_export');
        ?>
        <div class="wrap tw-wrap">
            <h1 class="wp-heading-inline">Εγγυήσεις &amp; πελάτες</h1>
            <a class="page-title-action" href="<?php echo esc_url($export); ?>">Εξαγωγή CSV (με τα τρέχοντα φίλτρα)</a>
            <?php self::notice(); ?>
            <form method="get" class="tw-filters">
                <?php self::get_form_fields('warranties'); ?>
                <input type="search" name="s" value="<?php echo esc_attr($args['s']); ?>" placeholder="S/N, κωδικός, όνομα, email, τηλέφωνο, ΑΦΜ…" size="40">
                <select name="status"><option value="">Όλες οι καταστάσεις</option>
                    <?php foreach ($labels as $k => $l) printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($args['status'], $k, false), esc_html($l)); ?>
                </select>
                <select name="expiring"><option value="">Λήξη: οποτεδήποτε</option>
                    <?php foreach ([30, 60, 90, 180, 365] as $d) printf('<option value="%d"%s>Λήγουν σε %d ημέρες</option>', $d, selected($args['expiring'], $d, false), $d); ?>
                </select>
                <label>Αγορά από <input type="date" name="from" value="<?php echo esc_attr($args['from']); ?>"></label>
                <label>έως <input type="date" name="to" value="<?php echo esc_attr($args['to']); ?>"></label>
                <button class="button">Φίλτρο</button>
            </form>
            <?php self::pagination($total, $paged); ?>
            <table class="widefat striped"><thead><tr><th>Κωδικός εγγύησης</th><th>S/N</th><th>Προϊόν</th><th>Πελάτης</th><th>Email / Κινητό</th><th>Συνεργάτης ΑΦΜ</th><th>Αγορά</th><th>Λήξη</th><th>Κατάσταση</th></tr></thead><tbody>
            <?php if (!$rows) echo '<tr><td colspan="9">Δεν βρέθηκαν εγγυήσεις.</td></tr>'; ?>
            <?php foreach ($rows as $r) : ?>
                <tr><td><?php echo self::warranty_link($r->id, $r->warranty_code); ?></td><td><code><?php echo esc_html($r->serial); ?></code></td>
                    <td><?php echo esc_html($r->product_name); ?></td><td><?php echo esc_html($r->customer_name); ?></td>
                    <td><?php echo esc_html($r->email); ?><br><?php echo esc_html($r->phone_mobile); ?></td><td><?php echo esc_html($r->partner_vat); ?></td>
                    <td><?php echo esc_html(TW_Util::date_label($r->purchase_date)); ?></td><td><?php echo esc_html(TW_Util::date_label($r->warranty_end)); ?></td>
                    <td><?php echo self::status_badge($r->status, $labels); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php self::pagination($total, $paged); ?>
        </div>
        <?php
    }

    private static function page_warranty($id) {
        global $wpdb;
        $w = TW_Warranty::get($id);
        if (!$w) { echo '<div class="wrap"><p>Η εγγύηση δεν βρέθηκε.</p></div>'; return; }
        $labels = TW_Util::status_labels();
        $timeline = TW_Warranty::timeline($w);
        $consents = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . TW_DB::table('consents') . " WHERE warranty_id = %d ORDER BY id", $id));
        $notes = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . TW_DB::table('notifications') . " WHERE warranty_id = %d ORDER BY id DESC", $id));
        $events = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . TW_DB::table('events') . " WHERE warranty_id = %d ORDER BY id DESC", $id));
        $post = self::url();
        $ms_labels = ['done' => 'Καταχωρήθηκε – ελέγχθηκε', 'due' => 'Εκκρεμεί καταχώρηση', 'missed' => 'Δεν καταχωρήθηκε', 'upcoming' => 'Προσεχώς', 'before_registration' => 'Πριν από την ενεργοποίηση', 'expired' => 'Έληξε', 'submitted' => 'Καταχωρήθηκε από τον πελάτη', 'rejected' => 'Απορρίφθηκε – αναμένεται νέα υποβολή'];
        $history = TW_Warranty::services_history($w->id);
        $validity = TW_Warranty::validity($w);
        $consent_labels = ['terms' => 'Όροι εγγύησης', 'data_accuracy' => 'Ορθότητα στοιχείων', 'marketing' => 'Εμπορικές ενημερώσεις'];
        ?>
        <div class="wrap tw-wrap">
            <p><a href="<?php echo esc_url(self::url('warranties')); ?>">&larr; Όλες οι εγγυήσεις</a></p>
            <h1>Εγγύηση <?php echo esc_html($w->warranty_code); ?> <?php echo self::status_badge($w->status, $labels); ?>
                <button type="button" class="page-title-action tw-no-print" onclick="window.print()">🖨 Εκτύπωση καρτέλας</button></h1>
            <?php self::notice(); ?>
            <?php foreach (self::contact_problems($w) as $problem) : ?>
                <div class="notice notice-warning inline"><p>⚠ <?php echo esc_html($problem); ?></p></div>
            <?php endforeach; ?>
            <div class="tw-validity tw-validity--<?php echo $validity['ok'] ? 'ok' : 'bad'; ?>">
                <strong><?php echo $validity['ok'] ? '✔' : '✘'; ?> <?php echo esc_html($validity['title']); ?></strong>
                <ul><?php foreach ($validity['lines'] as $l) echo '<li>' . esc_html($l) . '</li>'; ?></ul>
                <small>S/N <?php echo esc_html($w->serial); ?> · <?php echo esc_html($w->product_name); ?> · αγορά <?php echo esc_html(TW_Util::date_label($w->purchase_date)); ?> · κατάσταση στις <?php echo esc_html(TW_Util::datetime_label(TW_DB::now())); ?></small>
            </div>
            <?php if ($w->status === 'locked') : ?><div class="notice notice-error inline"><p><strong>Οριστικά κλειδωμένη</strong> στις <?php echo esc_html(TW_Util::datetime_label($w->locked_at)); ?>: <?php echo esc_html($w->lock_reason); ?></p></div><?php endif; ?>
            <?php if ($w->anonymized_at) : ?><div class="notice notice-warning inline"><p>Τα προσωπικά στοιχεία ανωνυμοποιήθηκαν στις <?php echo esc_html(TW_Util::datetime_label($w->anonymized_at)); ?>.</p></div><?php endif; ?>

            <div class="tw-columns">
                <div class="tw-panel">
                    <h2>Προϊόν &amp; εγγύηση</h2>
                    <table class="form-table tw-kv">
                        <tr><th>Σειριακός</th><td><code><?php echo esc_html($w->serial); ?></code></td></tr>
                        <tr><th>Προϊόν</th><td><?php echo esc_html(trim($w->product_name . ' ' . ($w->product_code ? '(' . $w->product_code . ')' : ''))); ?></td></tr>
                        <tr><th>Ημ. αγοράς</th><td><?php echo esc_html(TW_Util::date_label($w->purchase_date)); ?></td></tr>
                        <tr><th>Λήξη εγγύησης</th><td><?php echo esc_html(TW_Util::date_label($w->warranty_end)); ?></td></tr>
                        <tr><th>Ενεργοποίηση</th><td><?php echo esc_html(TW_Util::datetime_label($w->created_at)); ?></td></tr>
                        <tr><th>Παραστατικό</th><td><?php echo esc_html($w->invoice_number); ?>
                            <?php if ($w->invoice_file) : ?> – <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'tw_invoice', 'id' => $w->id], $post), 'tw_invoice')); ?>">Λήψη αρχείου (<?php echo esc_html($w->invoice_original_name); ?>)</a><?php endif; ?></td></tr>
                    </table>
                </div>
                <div class="tw-panel">
                    <h2>Πελάτης</h2>
                    <table class="form-table tw-kv">
                        <tr><th>Ονοματεπώνυμο</th><td><?php echo esc_html($w->customer_name); ?></td></tr>
                        <tr><th>Email</th><td><?php echo esc_html($w->email); ?></td></tr>
                        <tr><th>Κινητό</th><td><?php echo esc_html($w->phone_mobile); ?></td></tr>
                        <tr><th>Σταθερό</th><td><?php echo esc_html($w->phone_landline); ?></td></tr>
                        <tr><th>Διεύθυνση</th><td><?php echo esc_html(trim($w->address . ', ' . $w->postcode . ' ' . $w->city, ', ')); ?></td></tr>
                        <tr><th>Συνεργάτης</th><td>ΑΦΜ <?php echo esc_html($w->partner_vat); ?> – <?php echo esc_html($w->partner_email); ?></td></tr>
                        <tr><th>Εμπορικές ενημερώσεις</th><td><?php echo $w->marketing_opt_in ? 'Ναι' : 'Όχι'; ?></td></tr>
                    </table>
                </div>
            </div>

            <h2>Πρόγραμμα Service &amp; λήξης</h2>
            <table class="widefat striped"><thead><tr><th>Ορόσημο</th><th>Ημερομηνία</th><th>Κλείδωμα αν δεν γίνει</th><th>Κατάσταση</th><th>Service</th><th></th></tr></thead><tbody>
            <?php foreach ($timeline as $m) : ?>
                <tr><td><?php echo $m['type'] === 'service' ? (int) $m['no'] . 'ο Service (' . (int) $m['years'] . ' έτη)' : 'Λήξη εγγύησης'; ?></td>
                    <td><?php echo esc_html(TW_Util::date_label($m['due'])); ?></td>
                    <td><?php echo esc_html(TW_Util::date_label($m['lock_date'])); ?></td>
                    <td><?php echo esc_html($ms_labels[$m['state']] ?? $m['state']); ?></td>
                    <td><?php if ($m['service']) : $sv = $m['service']; ?>
                        <?php echo esc_html(TW_Util::date_label($sv->performed_date)); ?> · ΑΦΜ <?php echo esc_html($sv->technician_vat ?: '—'); ?>
                        <?php echo $sv->receipt_number ? '· αρ. ' . esc_html($sv->receipt_number) : ''; ?>
                        <?php echo $sv->receipt_file ? '· <a href="' . esc_url(self::receipt_url($w, $sv)) . '" target="_blank">απόδειξη</a>' : ''; ?>
                    <?php endif; ?></td>
                    <td></td></tr>
            <?php endforeach; ?>
            </tbody></table>

            <h2>Υποβολές Service (<?php echo count($history); ?>)</h2>
            <p class="description tw-no-print">Όλες οι καταχωρήσεις Service για αυτόν τον σειριακό, με τις αποδείξεις. Καμία δεν διαγράφεται όταν ο πελάτης υποβάλει ξανά. Ο έλεγχος είναι προαιρετικός: σημειώστε μια υποβολή ως ελεγμένη ή απορρίψτε την αν δεν είναι σωστή (ο πελάτης ενημερώνεται και μπορεί να υποβάλει ξανά).</p>
            <table class="widefat striped"><thead><tr><th>Καταχώρηση</th><th>Service</th><th>Ημ/νία Service</th><th>Τεχνικός</th><th>Απόδειξη</th><th>Γνήσια ανταλλ.</th><th>Από</th><th>Έλεγχος</th><th class="tw-no-print"></th></tr></thead><tbody>
            <?php if (!$history) echo '<tr><td colspan="9">Καμία υποβολή ακόμη.</td></tr>'; ?>
            <?php $latest = TW_Warranty::services($w->id); foreach ($history as $sv) : $is_latest = isset($latest[$sv->milestone_no]) && (int) $latest[$sv->milestone_no]->id === (int) $sv->id; ?>
                <tr<?php echo $is_latest ? '' : ' class="tw-superseded"'; ?>>
                    <td><?php echo esc_html(TW_Util::datetime_label($sv->created_at)); ?><?php echo $is_latest ? '' : '<br><small>αντικαταστάθηκε από νεότερη</small>'; ?></td>
                    <td><?php echo (int) $sv->milestone_no; ?>ο</td>
                    <td><?php echo esc_html(TW_Util::date_label($sv->performed_date)); ?></td>
                    <td><?php echo $sv->technician_vat ? 'ΑΦΜ <code>' . esc_html($sv->technician_vat) . '</code>' : '—'; ?><?php echo $sv->technician ? '<br>' . esc_html($sv->technician) : ''; ?><?php echo $sv->notes ? '<br><small>' . esc_html($sv->notes) . '</small>' : ''; ?></td>
                    <td><?php
                        $bits = [];
                        if ($sv->receipt_number) $bits[] = 'Αρ. <strong>' . esc_html($sv->receipt_number) . '</strong>' . ($sv->receipt_date ? '<br>' . esc_html(TW_Util::date_label($sv->receipt_date)) : '');
                        if ($sv->receipt_file) $bits[] = '<a href="' . esc_url(self::receipt_url($w, $sv)) . '" target="_blank">' . esc_html($sv->receipt_original_name ?: 'Αρχείο') . '</a>';
                        echo $bits ? implode('<br>', $bits) : '—';
                    ?></td>
                    <td><?php echo $sv->genuine_parts ? 'Ναι' : '<strong>Όχι</strong>'; ?></td>
                    <td><?php echo esc_html(['customer' => 'Πελάτης (πύλη)', 'admin' => 'Εταιρεία'][$sv->source] ?? $sv->source); ?></td>
                    <td><?php
                        if ($sv->review_status === 'approved') echo '✔ Ελέγχθηκε' . ($sv->reviewed_at ? '<br><small>' . esc_html(TW_Util::datetime_label($sv->reviewed_at)) . '</small>' : '');
                        elseif ($sv->review_status === 'rejected') echo '<span class="tw-danger">✘ Απορρίφθηκε</span><br><small>' . esc_html($sv->review_note) . '</small>';
                        else echo 'Δεν έχει ελεγχθεί';
                    ?></td>
                    <td class="tw-no-print">
                        <?php if ($is_latest && $sv->review_status !== 'approved') : ?>
                        <form method="post" action="<?php echo esc_url($post); ?>" class="tw-review-form">
                            <?php wp_nonce_field('tw_review_service'); ?><input type="hidden" name="action" value="tw_review_service"><input type="hidden" name="id" value="<?php echo (int) $w->id; ?>"><input type="hidden" name="milestone_no" value="<?php echo (int) $sv->milestone_no; ?>">
                            <button class="button" name="decision" value="approve">✔ Ελέγχθηκε</button>
                            <?php if ($sv->review_status !== 'rejected') : ?>
                            <input type="text" name="note" placeholder="Λόγος απόρριψης">
                            <button class="button tw-danger" name="decision" value="reject" onclick="return this.form.note.value.trim() ? confirm('Απόρριψη; Ο πελάτης θα ενημερωθεί για νέα υποβολή.') : (alert('Συμπληρώστε λόγο απόρριψης.'), false)">✘ Απόρριψη</button>
                            <?php endif; ?>
                        </form>
                        <?php endif; ?>
                        <form method="post" action="<?php echo esc_url($post); ?>" onsubmit="return confirm('Οριστική διαγραφή αυτής της καταχώρησης και της απόδειξης;')">
                            <?php wp_nonce_field('tw_delete_service'); ?><input type="hidden" name="action" value="tw_delete_service"><input type="hidden" name="id" value="<?php echo (int) $w->id; ?>"><input type="hidden" name="service_id" value="<?php echo (int) $sv->id; ?>">
                            <button class="button-link tw-danger">Διαγραφή</button></form>
                    </td></tr>
            <?php endforeach; ?>
            </tbody></table>

            <div class="tw-columns tw-no-print">
                <div class="tw-panel">
                    <h2>Καταχώρηση Service από την εταιρεία</h2>
                    <p class="description">Συνήθως το Service το καταχωρεί ο πελάτης από την πύλη. Από εδώ το καταχωρείτε εσείς (π.χ. όταν το έκανε η Thermic).</p>
                    <form method="post" action="<?php echo esc_url($post); ?>" enctype="multipart/form-data">
                        <?php wp_nonce_field('tw_record_service'); ?>
                        <input type="hidden" name="action" value="tw_record_service"><input type="hidden" name="id" value="<?php echo (int) $w->id; ?>">
                        <p><label>Ορόσημο<br><select name="milestone_no">
                            <?php $preselected = false;
                            foreach ($timeline as $m) {
                                if ($m['type'] !== 'service') continue;
                                $pick = !$preselected && in_array($m['state'], ['due', 'upcoming', 'missed'], true);
                                if ($pick) $preselected = true;
                                printf('<option value="%d"%s>%dο Service – %s%s</option>', $m['no'], $pick ? ' selected' : '', $m['no'], esc_html(TW_Util::date_label($m['due'])), $m['service'] ? ' (καταχωρημένο)' : '');
                            } ?>
                        </select></label></p>
                        <p><label>Ημερομηνία Service<br><input type="date" name="performed_date" required max="<?php echo esc_attr(TW_Schedule::today()); ?>"></label></p>
                        <p><label>Από<br><select name="performed_by"><option value="partner">Συνεργάτη</option><option value="thermic">Thermic</option></select></label></p>
                        <p><label>Τεχνικός / συνεργείο<br><input type="text" name="technician" class="regular-text"></label></p>
                        <p><label>ΑΦΜ τεχνικού<br><input type="text" name="technician_vat" inputmode="numeric" maxlength="9" class="small-text" style="width:10em"></label></p>
                        <p><label>Αριθμός απόδειξης<br><input type="text" name="receipt_number" class="regular-text" maxlength="100"></label></p>
                        <p><label>Ημερομηνία παραστατικού<br><input type="date" name="receipt_date" max="<?php echo esc_attr(TW_Schedule::today()); ?>"></label></p>
                        <p><label>Αρχείο απόδειξης (προαιρετικό)<br><input type="file" name="receipt_file" accept=".pdf,.jpg,.jpeg,.png"></label></p>
                        <p><label><input type="checkbox" name="genuine_parts" value="1" checked> Χρησιμοποιήθηκαν αυθεντικά ανταλλακτικά</label></p>
                        <p><label>Σημειώσεις<br><textarea name="notes" rows="2" class="large-text"></textarea></label></p>
                        <p><button class="button button-primary">Καταχώρηση Service</button></p>
                    </form>
                    <?php if ($w->status === 'locked') : ?><p class="description">Η εγγύηση είναι κλειδωμένη· η καταχώρηση Service δεν την ξεκλειδώνει αυτόματα.</p><?php endif; ?>
                </div>
                <div class="tw-panel">
                    <h2>Ενέργειες</h2>
                    <form method="post" action="<?php echo esc_url($post); ?>" class="tw-inline-form">
                        <?php wp_nonce_field('tw_set_status'); ?><input type="hidden" name="action" value="tw_set_status"><input type="hidden" name="id" value="<?php echo (int) $w->id; ?>">
                        <p><label>Αλλαγή κατάστασης<br><select name="status">
                            <?php foreach ($labels as $k => $l) printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($w->status, $k, false), esc_html($l)); ?>
                        </select></label></p>
                        <p><label>Αιτιολογία (υποχρεωτική)<br><input type="text" name="note" class="regular-text" required></label></p>
                        <p><button class="button" onclick="return confirm('Αλλαγή κατάστασης της εγγύησης;')">Αποθήκευση κατάστασης</button></p>
                        <p class="description">Ο πελάτης δεν μπορεί ποτέ να επανενεργοποιήσει κλειδωμένη εγγύηση· μόνο η εταιρεία από εδώ, με καταγραφή.</p>
                    </form>
                    <hr>
                    <form method="post" action="<?php echo esc_url($post); ?>">
                        <?php wp_nonce_field('tw_resend_activation'); ?><input type="hidden" name="action" value="tw_resend_activation"><input type="hidden" name="id" value="<?php echo (int) $w->id; ?>">
                        <button class="button">Επαναποστολή email επιβεβαίωσης</button>
                    </form>
                    <?php if (!$w->anonymized_at) : ?>
                    <hr>
                    <form method="post" action="<?php echo esc_url($post); ?>" onsubmit="return confirm('Ανωνυμοποίηση: θα διαγραφούν οριστικά τα προσωπικά στοιχεία και το παραστατικό. Συνέχεια;')">
                        <?php wp_nonce_field('tw_anonymize'); ?><input type="hidden" name="action" value="tw_anonymize"><input type="hidden" name="id" value="<?php echo (int) $w->id; ?>">
                        <button class="button tw-danger">Ανωνυμοποίηση προσωπικών στοιχείων (GDPR)</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <h2>Συγκαταθέσεις (αποδεικτικό GDPR)</h2>
            <table class="widefat striped"><thead><tr><th>Ημ/νία</th><th>Τύπος</th><th>Απόφαση</th><th>Κείμενο που εμφανίστηκε</th><th>Έκδοση όρων</th><th>Πηγή</th><th>IP</th><th>Browser</th></tr></thead><tbody>
            <?php foreach ($consents as $c) : ?>
                <tr><td><?php echo esc_html(TW_Util::datetime_label($c->created_at)); ?></td><td><?php echo esc_html($consent_labels[$c->consent_type] ?? $c->consent_type); ?></td>
                    <td><?php echo $c->granted ? '✔ Αποδοχή' : '✘ Όχι'; ?></td><td><small><?php echo esc_html($c->label_text); ?></small></td>
                    <td><?php if ($c->terms_version) : ?><a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'tw_terms_view', 'v' => $c->terms_version], $post), 'tw_terms_view')); ?>" target="_blank">v<?php echo (int) $c->terms_version; ?></a><br><small title="SHA-256"><?php echo esc_html(substr($c->terms_hash, 0, 12)); ?>…</small><?php endif; ?></td>
                    <td><?php echo esc_html($c->source); ?></td><td><?php echo esc_html($c->ip); ?></td><td><small><?php echo esc_html($c->user_agent); ?></small></td></tr>
            <?php endforeach; ?>
            </tbody></table>

            <h2>Ειδοποιήσεις που στάλθηκαν</h2>
            <?php self::notifications_table($notes, false); ?>

            <h2>Ιστορικό</h2>
            <table class="widefat striped"><thead><tr><th>Ημ/νία</th><th>Ενέργεια</th><th>Από</th><th>Λεπτομέρειες</th></tr></thead><tbody>
            <?php foreach ($events as $e) : ?>
                <tr><td><?php echo esc_html(TW_Util::datetime_label($e->created_at)); ?></td><td><?php echo esc_html($e->action); ?></td><td><?php echo esc_html($e->actor); ?></td><td><small><?php echo esc_html($e->details); ?></small></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <?php
    }

    private static function notifications_table($rows, $with_warranty) {
        $ch = ['email' => 'Email', 'sms' => 'SMS', 'partner' => 'Email συνεργάτη'];
        $st = ['sent' => 'Στάλθηκε', 'failed' => 'Απέτυχε η αποστολή', 'pending' => 'Σε εξέλιξη', 'skipped' => 'Παραλείφθηκε'];
        $dl = TW_Brevo::status_labels();
        $icon = ['sent' => '⋯', 'delivered' => '✔', 'deferred' => '⏳', 'failed' => '✘', 'complaint' => '⚠'];
        echo '<table class="widefat striped"><thead><tr><th>Ημ/νία</th>' . ($with_warranty ? '<th>Εγγύηση</th>' : '') . '<th>Βήμα</th><th>Κανάλι</th><th>Παραλήπτης</th><th>Αποτέλεσμα</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="6">Καμία.</td></tr>';
        foreach ($rows as $n) {
            echo '<tr><td>' . esc_html(TW_Util::datetime_label($n->created_at)) . '</td>';
            if ($with_warranty) echo '<td>' . self::warranty_link($n->warranty_id, $n->serial ?? ('#' . $n->warranty_id)) . '</td>';
            echo '<td>' . esc_html(TW_Cron::row_label($n)) . '</td><td>' . esc_html($ch[$n->channel] ?? $n->channel) . '</td><td>' . esc_html($n->recipient) . '</td><td>';
            if ($n->status === 'sent' && $n->delivery_status !== '') {
                $cls = in_array($n->delivery_status, ['failed', 'complaint'], true) ? 'tw-danger' : ($n->delivery_status === 'delivered' ? 'tw-ok' : '');
                echo '<span class="' . $cls . '">' . esc_html(($icon[$n->delivery_status] ?? '') . ' ' . ($dl[$n->delivery_status] ?? $n->delivery_status)) . '</span>';
                if ($n->delivery_at && $n->delivery_status !== 'sent') echo ' <small>' . esc_html(TW_Util::datetime_label($n->delivery_at)) . '</small>';
                if ($n->delivery_detail && $n->delivery_status !== 'delivered') echo '<br><small>' . esc_html($n->delivery_detail) . '</small>';
            } else {
                echo esc_html($st[$n->status] ?? $n->status) . ($n->error ? '<br><small class="tw-danger">' . esc_html($n->error) . '</small>' : '');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    // ------------------------------------------------------------------ serials

    public static function page_serials() {
        global $wpdb;
        $t = TW_DB::table('serials');
        $s = self::q('s');
        $status = self::q('status');
        $batch = self::q('batch');
        $where = ['1=1']; $params = [];
        if ($s !== '') { $where[] = '(s.serial_no LIKE %s OR s.product_name LIKE %s OR s.product_code LIKE %s)'; $l = '%' . $wpdb->esc_like($s) . '%'; array_push($params, $l, $l, $l); }
        if ($status !== '') { $where[] = 's.status = %s'; $params[] = $status; }
        if ($batch !== '') { $where[] = 's.import_batch = %s'; $params[] = $batch; }
        $wsql = implode(' AND ', $where);
        if ($params) $wsql = $wpdb->prepare($wsql, $params);
        $paged = max(1, (int) self::q('paged'));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t s WHERE $wsql");
        $wt = TW_DB::table('warranties');
        $rows = $wpdb->get_results("SELECT s.*, s.serial_no AS serial, w.id wid, w.warranty_code FROM $t s LEFT JOIN $wt w ON w.serial_id = s.id WHERE $wsql"
            . ' ORDER BY s.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($paged - 1) * self::PER_PAGE));
        $labels = TW_Util::serial_status_labels();
        $post = self::url();
        $result = get_transient('tw_import_result_' . get_current_user_id());
        if ($result) delete_transient('tw_import_result_' . get_current_user_id());
        $export = wp_nonce_url(add_query_arg(array_filter(['action' => 'tw_export', 'type' => 'serials', 's' => $s, 'status' => $status, 'batch' => $batch]), $post), 'tw_export');
        ?>
        <div class="wrap tw-wrap">
            <h1 class="wp-heading-inline">Σειριακοί αριθμοί</h1>
            <a class="page-title-action" href="<?php echo esc_url($export); ?>">Εξαγωγή CSV</a>
            <?php self::notice(); ?>
            <?php if ($result) : ?>
                <div class="notice notice-info"><p><strong>Αποτέλεσμα εισαγωγής <?php echo esc_html($result['batch']); ?>:</strong>
                    νέοι <?php echo (int) $result['inserted']; ?>, υπήρχαν ήδη / διπλοί <?php echo count($result['duplicates']); ?>, μη έγκυροι <?php echo count($result['invalid']); ?>.</p>
                    <?php if ($result['duplicates']) : ?><details><summary>Διπλοί</summary><p><code><?php echo esc_html(implode(', ', array_slice($result['duplicates'], 0, 500))); ?></code></p></details><?php endif; ?>
                    <?php if ($result['invalid']) : ?><details><summary>Μη έγκυροι</summary><p><code><?php echo esc_html(implode(', ', array_slice($result['invalid'], 0, 500))); ?></code></p></details><?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="tw-columns">
                <div class="tw-panel">
                    <h2>Εισαγωγή από αρχείο (Excel / CSV)</h2>
                    <p><a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'tw_template', self::url('serials')), 'tw_template')); ?>">⬇ Λήψη προτύπου Excel</a>
                        <span class="description">Συμπληρώστε το πρότυπο και ανεβάστε το εδώ.</span></p>
                    <form method="post" action="<?php echo esc_url($post); ?>" enctype="multipart/form-data">
                        <?php wp_nonce_field('tw_import'); ?><input type="hidden" name="action" value="tw_import">
                        <p><input type="file" name="file" accept=".xlsx,.csv,.txt" required></p>
                        <p class="description">Πρώτο φύλλο του .xlsx ή αρχείο .csv. Στήλες: <code>serial</code> (υποχρεωτική), <code>product_code</code>, <code>product_name</code>, <code>notes</code> – δεκτές και ελληνικές επικεφαλίδες (Σειριακός, Κωδικός, Περιγραφή, Σημειώσεις). Χωρίς γραμμή επικεφαλίδων, οι στήλες διαβάζονται με αυτή τη σειρά. Υπάρχοντες σειριακοί δεν αλλάζουν.</p>
                        <p><label>Προεπιλεγμένος κωδικός προϊόντος (για κενά κελιά) <input type="text" name="default_code"></label></p>
                        <p><label>Προεπιλεγμένη περιγραφή προϊόντος <input type="text" name="default_name" class="regular-text"></label></p>
                        <p><button class="button button-primary">Εισαγωγή</button></p>
                    </form>
                </div>
                <div class="tw-panel">
                    <h2>Προσθήκη ενός ή λίγων σειριακών</h2>
                    <form method="post" action="<?php echo esc_url($post); ?>">
                        <?php wp_nonce_field('tw_add_serial'); ?><input type="hidden" name="action" value="tw_add_serial">
                        <p><label>Σειριακοί (ένας ανά γραμμή)<br><textarea name="serials" rows="5" class="large-text code" required></textarea></label></p>
                        <p><label>Κωδικός προϊόντος <input type="text" name="product_code"></label></p>
                        <p><label>Περιγραφή προϊόντος <input type="text" name="product_name" class="regular-text"></label></p>
                        <p><button class="button button-primary">Προσθήκη</button></p>
                    </form>
                </div>
            </div>

            <form method="get" class="tw-filters">
                <?php self::get_form_fields('serials'); ?>
                <input type="search" name="s" value="<?php echo esc_attr($s); ?>" placeholder="Σειριακός ή προϊόν">
                <select name="status"><option value="">Όλοι</option><?php foreach ($labels as $k => $l) printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($status, $k, false), esc_html($l)); ?></select>
                <input type="text" name="batch" value="<?php echo esc_attr($batch); ?>" placeholder="Παρτίδα εισαγωγής">
                <button class="button">Φίλτρο</button>
            </form>
            <?php self::pagination($total, $paged); ?>
            <table class="widefat striped"><thead><tr><th>Σειριακός</th><th>Κωδικός</th><th>Προϊόν</th><th>Κατάσταση</th><th>Εγγύηση</th><th>Παρτίδα</th><th>Καταχώρηση</th><th></th></tr></thead><tbody>
            <?php if (!$rows) echo '<tr><td colspan="8">Δεν βρέθηκαν σειριακοί.</td></tr>'; ?>
            <?php foreach ($rows as $r) : ?>
                <tr><td><code><?php echo esc_html($r->serial); ?></code></td><td><?php echo esc_html($r->product_code); ?></td><td><?php echo esc_html($r->product_name); ?></td>
                    <td><?php echo self::status_badge($r->status, $labels); ?></td>
                    <td><?php echo $r->wid ? self::warranty_link($r->wid, $r->warranty_code) : '—'; ?></td>
                    <td><a href="<?php echo esc_url(self::url('serials', ['batch' => $r->import_batch])); ?>"><?php echo esc_html($r->import_batch); ?></a></td>
                    <td><?php echo esc_html(TW_Util::datetime_label($r->created_at)); ?></td>
                    <td><?php if ($r->status !== 'registered') : $to = $r->status === 'void' ? 'available' : 'void'; ?>
                        <form method="post" action="<?php echo esc_url($post); ?>"><?php wp_nonce_field('tw_serial_status'); ?>
                            <input type="hidden" name="action" value="tw_serial_status"><input type="hidden" name="id" value="<?php echo (int) $r->id; ?>"><input type="hidden" name="to" value="<?php echo esc_attr($to); ?>">
                            <button class="button-link"><?php echo $to === 'void' ? 'Ακύρωση' : 'Επαναφορά'; ?></button></form>
                    <?php endif; ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php self::pagination($total, $paged); ?>
        </div>
        <?php
    }

    // ------------------------------------------------------------------ notifications log

    public static function page_notifications() {
        global $wpdb;
        $paged = max(1, (int) self::q('paged'));
        $n = TW_DB::table('notifications');
        $status = self::q('status');
        if ($status === 'undelivered') $where = "WHERE n.delivery_status IN ('failed','complaint')";
        elseif ($status === 'delivered') $where = "WHERE n.delivery_status = 'delivered'";
        else $where = $status ? $wpdb->prepare('WHERE n.status = %s', $status) : '';
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $n n $where");
        $rows = $wpdb->get_results("SELECT n.*, s.serial_no AS serial FROM $n n LEFT JOIN " . TW_DB::table('warranties') . " w ON w.id = n.warranty_id LEFT JOIN " . TW_DB::table('serials') . " s ON s.id = w.serial_id $where ORDER BY n.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($paged - 1) * self::PER_PAGE));
        ?>
        <div class="wrap tw-wrap">
            <h1>Ειδοποιήσεις</h1>
            <?php self::notice(); ?>
            <form method="post" action="<?php echo esc_url(self::url('notifications')); ?>" class="tw-inline-form">
                <?php wp_nonce_field('tw_run_now'); ?><input type="hidden" name="action" value="tw_run_now">
                <button class="button button-primary">Εκτέλεση ελέγχου ειδοποιήσεων τώρα</button>
                <span class="description">Η εκτέλεση γίνεται αυτόματα κάθε μέρα. Δεν στέλνει ποτέ το ίδιο μήνυμα δύο φορές.</span>
            </form>
            <p><a href="<?php echo esc_url(self::url('notifications')); ?>">Όλες</a> | <a href="<?php echo esc_url(self::url('notifications', ['status' => 'failed'])); ?>">Απέτυχε η αποστολή</a> | <a href="<?php echo esc_url(self::url('notifications', ['status' => 'undelivered'])); ?>">Δεν παραδόθηκαν</a> | <a href="<?php echo esc_url(self::url('notifications', ['status' => 'delivered'])); ?>">Παραδόθηκαν</a></p>
            <?php if (!TW_Brevo::email_enabled() && !TW_Brevo::sms_enabled()) : ?><p class="description">Η επιβεβαίωση παράδοσης («Παραδόθηκε / Δεν παραδόθηκε») λειτουργεί όταν η αποστολή γίνεται μέσω Brevo (Ρυθμίσεις → Brevo).</p><?php endif; ?>
            <?php self::pagination($total, $paged); self::notifications_table($rows, true); self::pagination($total, $paged); ?>
        </div>
        <?php
    }

    // ------------------------------------------------------------------ settings

    public static function page_settings() {
        $s = TW_Settings::all();
        $terms = TW_Settings::current_terms();
        $tpl = TW_Settings::template_defaults();
        $post = self::url();
        $tab = self::q('tab') ?: 'general';
        $tabs = ['general' => 'Γενικά', 'app' => 'Εφαρμογή & εμφάνιση', 'schedule' => 'Εγγύηση & ειδοποιήσεις', 'terms' => 'Όροι & συγκαταθέσεις', 'messages' => 'Κείμενα μηνυμάτων', 'brevo' => 'Brevo (email & SMS)', 'sms' => 'Άλλος πάροχος SMS', 'privacy' => 'GDPR'];
        $field = function ($key, $label, $type = 'text', $desc = '', $attrs = '') use ($s) {
            echo '<tr><th><label for="tw-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
            if ($type === 'checkbox') {
                echo '<label><input type="checkbox" id="tw-' . esc_attr($key) . '" name="s[' . esc_attr($key) . ']" value="1" ' . checked((int) $s[$key], 1, false) . '> ' . esc_html($desc) . '</label>';
            } elseif ($type === 'textarea') {
                echo '<textarea id="tw-' . esc_attr($key) . '" name="s[' . esc_attr($key) . ']" rows="3" class="large-text" ' . $attrs . '>' . esc_textarea($s[$key]) . '</textarea>';
                if ($desc) echo '<p class="description">' . wp_kses_post($desc) . '</p>';
            } else {
                echo '<input type="' . esc_attr($type) . '" id="tw-' . esc_attr($key) . '" name="s[' . esc_attr($key) . ']" value="' . esc_attr($s[$key]) . '" class="' . ($type === 'number' ? 'small-text' : 'regular-text') . '" ' . $attrs . '>';
                if ($desc) echo '<p class="description">' . wp_kses_post($desc) . '</p>';
            }
            echo '</td></tr>';
        };
        ?>
        <div class="wrap tw-wrap">
            <h1>Ρυθμίσεις εγγυήσεων</h1>
            <?php self::notice(); ?>
            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $k => $l) printf('<a class="nav-tab%s" href="%s">%s</a>', $tab === $k ? ' nav-tab-active' : '', esc_url(self::url('settings', ['tab' => $k])), esc_html($l)); ?>
            </nav>
            <form method="post" action="<?php echo esc_url($post); ?>">
                <?php wp_nonce_field('tw_save_settings'); ?>
                <input type="hidden" name="action" value="tw_save_settings"><input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
                <table class="form-table">
                <?php if ($tab === 'general') :
                    $field('company_name', 'Επωνυμία');
                    $field('company_contact', 'Στοιχεία επικοινωνίας για Service', 'text', 'Εμφανίζεται στα μηνύματα: «…ή απευθείας στη Thermic: <em>αυτό το κείμενο</em>».');
                    $field('register_url', 'Σελίδα ενεργοποίησης (προαιρετικό)', 'url', 'Αφήστε κενό για την αυτόνομη σελίδα <code>' . esc_html(TW_App::public_url()) . '</code>. Συμπληρώστε μόνο αν βάλατε το shortcode <code>[thermic_warranty_register]</code> σε δική σας σελίδα του site.');
                    $field('portal_url', 'Σελίδα «Οι εγγυήσεις μου» (προαιρετικό)', 'url', 'Αφήστε κενό για την αυτόνομη σελίδα <code>' . esc_html(TW_App::public_url('my')) . '</code>. Συμπληρώστε μόνο αν χρησιμοποιείτε το shortcode <code>[thermic_warranty_portal]</code>. Μπαίνει στα email.');
                    $field('email_from_name', 'Όνομα αποστολέα email');
                    $field('email_from_address', 'Email αποστολέα', 'email', 'Προτείνεται SMTP plugin (π.χ. WP Mail SMTP) με SPF/DKIM για τον τομέα, ώστε τα μηνύματα να μην πηγαίνουν στα ανεπιθύμητα.');
                    $field('admin_emails', 'Email εταιρείας για ειδοποιήσεις', 'text', 'Ένα ή περισσότερα, χωρισμένα με κόμμα.');
                    $field('admin_digest', 'Ημερήσια σύνοψη', 'checkbox', 'Αποστολή σύνοψης στην εταιρεία κάθε μέρα που υπάρχουν ειδοποιήσεις, λήξεις ή κλειδώματα.');
                    self::select_row('invoice_mode', 'Αρχείο παραστατικού αγοράς (ενεργοποίηση)', TW_Settings::invoice_mode(), [
                        'required' => 'Υποχρεωτικό ανέβασμα αρχείου',
                        'optional' => 'Προαιρετικό ανέβασμα αρχείου',
                        'off' => 'Χωρίς αρχείο (μόνο αριθμός παραστατικού και ημερομηνία αγοράς)',
                    ], 'Ο αριθμός παραστατικού και η ημερομηνία αγοράς ζητούνται πάντα.');
                    self::select_row('service_proof_mode', 'Απόδειξη Service (υποβολή πελάτη)', TW_Settings::service_proof_mode(), [
                        'upload' => 'Ανέβασμα αρχείου (φωτογραφία ή PDF)',
                        'fields' => 'Αριθμός και ημερομηνία παραστατικού (χωρίς αρχείο)',
                        'both' => 'Και τα δύο: αριθμός, ημερομηνία και αρχείο',
                    ]);
                    $field('invoice_max_mb', 'Μέγιστο μέγεθος αρχείου (MB)', 'number', '', 'min="1" max="50"');
                elseif ($tab === 'app') :
                    $field('admin_slug', 'Διεύθυνση διαχειριστικού', 'text', 'Το διαχειριστικό ανοίγει στο <code>' . esc_html(home_url('/')) . '<strong>' . esc_html($s['admin_slug']) . '</strong>/</code>.', 'pattern="[a-z0-9\\-]+"');
                    $field('public_slug', 'Διεύθυνση σελίδων πελατών', 'text', 'Ενεργοποίηση: <code>' . esc_html(TW_App::public_url()) . '</code> – Οι εγγυήσεις μου: <code>' . esc_html(TW_App::public_url('my')) . '</code>', 'pattern="[a-z0-9\\-]+"');
                    $field('logo_url', 'Λογότυπο (URL εικόνας)', 'url', 'Εμφανίζεται στις σελίδες πελατών, στο διαχειριστικό και στη σύνδεση.');
                    $field('brand_color', 'Χρώμα εταιρείας', 'text', 'Hex, π.χ. #c8102e', 'pattern="#[0-9a-fA-F]{6}"');
                    $field('staff_2fa', 'Διπλή επαλήθευση ομάδας', 'checkbox', 'Μετά τον κωδικό πρόσβασης, η ομάδα λαμβάνει κωδικό μίας χρήσης στο email (συνιστάται).');
                elseif ($tab === 'schedule') :
                    $field('warranty_years', 'Διάρκεια εγγύησης (έτη)', 'number', 'Από την ημερομηνία αγοράς.', 'min="1" max="50"');
                    $field('service_interval_years', 'Service ανά (έτη)', 'number', '0 = χωρίς περιοδικό Service. Με 12 έτη και 3: Service στα 3, 6 και 9 έτη, λήξη στα 12.', 'min="0" max="50"');
                    $field('reminder_offsets', 'Ημέρες ειδοποιήσεων', 'text', 'Σε σχέση με κάθε ημερομηνία (Service ή λήξη), χωρισμένες με κόμμα. Αρνητικές = πριν. Προεπιλογή: <code>-60,-30,0,30</code>.');
                    $field('lock_offset', 'Οριστικό κλείδωμα (ημέρες μετά)', 'number', 'Προεπιλογή 45, δηλαδή 15 ημέρες μετά το τέλος της περιόδου των 30 ημερών.', 'min="0" max="365"');
                    $field('lock_on_missed_service', 'Κλείδωμα για Service', 'checkbox', 'Κλείδωμα της εγγύησης αν δεν καταγραφεί Service έως την ημερομηνία κλειδώματος.');
                    $field('lock_on_expiry', 'Κλείδωμα μετά τη λήξη', 'checkbox', 'Οριστικό κλείδωμα μετά τη λήξη των 12 ετών.');
                    $field('notify_partner', 'Κοινοποίηση στον συνεργάτη', 'checkbox', 'Αποστολή αντιγράφου των υπενθυμίσεων Service και στο email του συνεργάτη.');
                    $field('catchup_days', 'Καθυστερημένη αποστολή (ημέρες)', 'number', 'Αν η αυτόματη εκτέλεση χάσει μέρες (π.χ. το site ήταν εκτός λειτουργίας), μια υπενθύμιση στέλνεται έως τόσες ημέρες αργότερα. Παλαιότερες παραλείπονται.', 'min="0" max="60"');
                    $field('service_submit_window_days', 'Υποβολή Service από τον πελάτη (ημέρες πριν)', 'number', 'Πόσες ημέρες πριν από την ημερομηνία Service ανοίγει η φόρμα υποβολής στην πύλη. Η υποβολή επιτρέπεται έως την ημερομηνία κλειδώματος.', 'min="0" max="365"');
                    $field('service_resubmit_days', 'Προθεσμία νέας υποβολής μετά από απόρριψη (ημέρες)', 'number', 'Αν απορρίψετε μια υποβολή, ο πελάτης έχει τόσες ημέρες (και τουλάχιστον έως την κανονική ημερομηνία κλειδώματος) για να υποβάλει ξανά.', 'min="1" max="120"');
                    $field('service_notify_company', 'Email για κάθε υποβολή Service', 'checkbox', 'Αποστολή email στην εταιρεία κάθε φορά που ένας πελάτης καταχωρεί Service. Χωρίς αυτό, οι υποβολές φαίνονται στην καρτέλα κάθε εγγύησης και στην Επισκόπηση.');
                    $field('max_registration_delay_days', 'Προθεσμία ενεργοποίησης (ημέρες)', 'number', 'Μέγιστες ημέρες από την αγορά έως την ενεργοποίηση. 0 = χωρίς όριο.', 'min="0"');
                elseif ($tab === 'terms') : ?>
                    <tr><th>Όροι εγγύησης</th><td>
                        <textarea name="terms_text" rows="18" class="large-text"><?php echo esc_textarea($terms['text']); ?></textarea>
                        <p class="description">Τρέχουσα έκδοση: <strong>v<?php echo (int) $terms['version']; ?></strong> (<?php echo esc_html(TW_Util::datetime_label($terms['published_at'])); ?>). Κάθε αλλαγή δημιουργεί νέα έκδοση· οι παλαιότερες φυλάσσονται, και για κάθε πελάτη καταγράφεται ποια έκδοση αποδέχθηκε.</p>
                        <p><?php foreach (array_reverse(TW_Settings::terms_versions()) as $tv) printf('<a href="%s" target="_blank">v%d</a> (%s) &nbsp; ', esc_url(wp_nonce_url(add_query_arg(['action' => 'tw_terms_view', 'v' => $tv['version']], $post), 'tw_terms_view')), (int) $tv['version'], esc_html(TW_Util::date_label(substr($tv['published_at'], 0, 10)))); ?></p>
                    </td></tr>
                    <?php
                    $field('terms_label', 'Checkbox όρων (υποχρεωτικό)', 'textarea');
                    $field('accuracy_label', 'Checkbox ορθότητας στοιχείων (υποχρεωτικό)', 'textarea');
                    $field('marketing_label', 'Checkbox εμπορικών ενημερώσεων (προαιρετικό, αρχικά αποεπιλεγμένο)', 'textarea');
                elseif ($tab === 'messages') : ?>
                    <tr><td colspan="2"><p class="description">Διαθέσιμα πεδία: <code>{customer_name} {serial} {warranty_code} {product_name} {product_code} {purchase_date} {warranty_end} {due_date} {days} {years} {milestone_no} {grace_end_date} {lock_date} {partner_email} {company_name} {company_contact} {portal_url} {service_schedule}</code>, και για τους κωδικούς επαλήθευσης <code>{code} {minutes}</code>. Αφήστε ένα πεδίο κενό για το προεπιλεγμένο κείμενο. Κενό SMS = δεν στέλνεται SMS σε αυτό το βήμα.</p></td></tr>
                    <?php foreach ($tpl as $key => $def) : $cur = TW_Settings::template($key); $saved = $s['templates'][$key] ?? []; ?>
                        <tr><th><?php echo esc_html($def['label']); ?><br><code><?php echo esc_html($key); ?></code></th><td>
                            <p><input type="text" class="large-text" name="tpl[<?php echo esc_attr($key); ?>][subject]" value="<?php echo esc_attr($saved['subject'] ?? ''); ?>" placeholder="<?php echo esc_attr($def['subject']); ?>"></p>
                            <p><textarea class="large-text" rows="6" name="tpl[<?php echo esc_attr($key); ?>][body]" placeholder="<?php echo esc_attr($def['body']); ?>"><?php echo esc_textarea($saved['body'] ?? ''); ?></textarea></p>
                            <?php if (!in_array($key, ['registration_otp', 'login_otp'], true)) : ?>
                            <p><label>SMS<br><textarea class="large-text" rows="2" name="tpl[<?php echo esc_attr($key); ?>][sms]" placeholder="<?php echo esc_attr($def['sms']); ?>"><?php echo esc_textarea($saved['sms'] ?? ''); ?></textarea></label></p>
                            <?php endif; ?>
                        </td></tr>
                    <?php endforeach;
                elseif ($tab === 'brevo') : ?>
                    <tr><td colspan="2"><p class="description">Με το Brevo τα email και τα SMS φεύγουν από τους servers του Brevo, και για κάθε μήνυμα καταγράφεται αν <strong>παραδόθηκε</strong> στον πελάτη. Το αποτέλεσμα φαίνεται στην καρτέλα κάθε εγγύησης και στις Ειδοποιήσεις.</p></td></tr>
                    <tr><th><label for="tw-brevo_api_key">API key</label></th><td>
                        <input type="password" id="tw-brevo_api_key" name="brevo_api_key_new" class="regular-text" autocomplete="off" placeholder="<?php echo $s['brevo_api_key'] ? esc_attr('Αποθηκευμένο: …' . substr($s['brevo_api_key'], -6)) : 'xkeysib-…'; ?>">
                        <p class="description">Brevo → Settings → SMTP &amp; API → API Keys. Αφήστε κενό για να κρατήσετε το αποθηκευμένο.</p></td></tr>
                    <?php
                    $field('brevo_email', 'Email μέσω Brevo', 'checkbox', 'Όλα τα email της πλατφόρμας (υπενθυμίσεις, επιβεβαιώσεις, κωδικοί) στέλνονται μέσω Brevo.');
                    $field('brevo_sender_email', 'Email αποστολέα', 'email', 'Διεύθυνση του domain σας, επιβεβαιωμένη στο Brevo (Senders & IP), π.χ. warranty@thermic.gr.');
                    $field('brevo_sender_name', 'Όνομα αποστολέα', 'text');
                    $field('brevo_reply_to', 'Απαντήσεις πελατών στο', 'email', 'Προαιρετικό, π.χ. service@thermic.gr.');
                    $field('brevo_sms', 'SMS μέσω Brevo', 'checkbox', 'Τα SMS στέλνονται μέσω Brevo (χρειάζονται μονάδες SMS στον λογαριασμό).');
                    $field('brevo_sms_sender', 'Αποστολέας SMS', 'text', 'Έως 11 λατινικοί χαρακτήρες, π.χ. THERMIC.', 'maxlength="11"');
                    $field('fallback_channel', 'Εναλλακτικό κανάλι', 'checkbox', 'Αν μια υπενθύμιση δεν παραδοθεί με email, στέλνεται με SMS (και το αντίστροφο).');
                    ?>
                    <tr><th>Αναφορές παράδοσης (webhook)</th><td>
                        <code style="word-break:break-all"><?php echo esc_html(TW_Brevo::webhook_url()); ?></code>
                        <p class="description">Για τα SMS ρυθμίζεται αυτόματα με κάθε μήνυμα. Για τα email πατήστε «Ρύθμιση αναφορών email στο Brevo» παρακάτω, ή προσθέστε τη διεύθυνση χειροκίνητα στο Brevo → Transactional → Settings → Webhook (συμβάντα: Delivered, Hard bounce, Soft bounce, Blocked, Spam, Invalid, Deferred, Error).</p></td></tr>
                    <?php
                elseif ($tab === 'sms') : ?>
                    <tr><td colspan="2"><p class="description">Μόνο αν δεν χρησιμοποιείτε το Brevo για τα SMS. Με άλλον πάροχο δεν υπάρχει επιβεβαίωση παράδοσης.</p></td></tr>
                    <?php
                    $field('sms_enabled', 'SMS', 'checkbox', 'Αποστολή SMS μαζί με τα email (ενεργοποίηση, Service, λήξη).');
                    $field('sms_sender', 'Αποστολέας (Sender ID)', 'text', 'Έως 11 λατινικοί χαρακτήρες, εγκεκριμένο από τον πάροχο.');
                    $field('sms_url', 'URL του παρόχου', 'url', 'Το endpoint αποστολής του παρόχου σας (π.χ. Yuboto, Apifon, Routee, Twilio). Δεκτά πεδία: <code>{to} {to_no_plus} {text} {sender}</code>.');
                    $field('sms_method', 'Μέθοδος HTTP', 'text', 'POST ή GET.');
                    $field('sms_headers', 'Headers', 'textarea', 'Ένα ανά γραμμή, π.χ. <code>Authorization: Bearer …</code>.');
                    $field('sms_body', 'Σώμα αιτήματος', 'textarea', 'Για POST. Πεδία: <code>{to} {to_no_plus} {text} {sender}</code>. Για JSON οι τιμές μπαίνουν με σωστό escaping.');
                elseif ($tab === 'privacy') :
                    $field('privacy_url', 'Σελίδα Πολιτικής Απορρήτου', 'url', 'Σύνδεσμος στη φόρμα ενεργοποίησης. Κενό = η Πολιτική Απορρήτου του WordPress. Προτεινόμενο κείμενο υπάρχει στις Ρυθμίσεις → Απόρρητο.');
                    $field('retention_months_after_end', 'Αυτόματη ανωνυμοποίηση (μήνες)', 'number', 'Μήνες μετά τη λήξη ή το κλείδωμα για αυτόματη ανωνυμοποίηση των προσωπικών στοιχείων. Το ιστορικό του προϊόντος μένει. 0 = απενεργοποιημένο. Ορίστε το σε συνεννόηση με τον νομικό σύμβουλο / DPO.', 'min="0"');
                    ?>
                    <tr><th>Αιτήματα διαγραφής</th><td><p class="description">Τα αιτήματα από την πύλη πελατών εμφανίζονται παρακάτω αφού ο πελάτης τα επιβεβαιώσει από το email του. Ενεργές εγγυήσεις διατηρούνται (ανάκληση εμπορικής συγκατάθεσης)· όσες έχουν λήξει ή κλειδωθεί ανωνυμοποιούνται.</p></td></tr>
                <?php endif; ?>
                </table>
                <p class="submit"><button type="submit" class="button button-primary" id="submit">Αποθήκευση</button></p>
            </form>
            <?php if ($tab === 'privacy') self::erasure_requests(); ?>
            <?php if ($tab === 'brevo') self::brevo_tools(); ?>
            <?php if (in_array($tab, ['messages', 'sms', 'general', 'brevo'], true)) : ?>
                <h2>Δοκιμαστική αποστολή</h2>
                <form method="post" action="<?php echo esc_url($post); ?>" class="tw-inline-form">
                    <?php wp_nonce_field('tw_test_message'); ?><input type="hidden" name="action" value="tw_test_message"><input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
                    <select name="template"><?php foreach ($tpl as $k => $d) printf('<option value="%s">%s</option>', esc_attr($k), esc_html($d['label'])); ?></select>
                    <input type="email" name="email" placeholder="email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>">
                    <input type="tel" name="phone" placeholder="κινητό (για SMS)">
                    <button class="button">Αποστολή δοκιμής</button>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    // ------------------------------------------------------------------ backups

    private static function days_label($days) {
        if ($days >= 2) return (int) floor($days) . ' ημέρες';
        return $days >= 1 ? '1 ημέρα' : 'λιγότερο από 1 ημέρα';
    }

    private static function size_label($bytes) {
        if ($bytes >= 1048576) return number_format_i18n($bytes / 1048576, 1) . ' MB';
        return number_format_i18n(max(1, $bytes / 1024), 0) . ' KB';
    }

    public static function page_backups() {
        if (!self::can_admin()) { echo '<p>Δεν έχετε πρόσβαση.</p>'; return; }
        $s = TW_Settings::all();
        $list = TW_Backup::all();
        $post = self::url('backups');
        $next = wp_next_scheduled(TW_Backup::HOOK);
        $types = ['auto' => 'Αυτόματο', 'manual' => 'Χειροκίνητο', 'prerestore' => 'Πριν από επαναφορά'];
        ?>
        <div class="wrap tw-wrap">
            <h1>Αντίγραφα ασφαλείας</h1>
            <?php self::notice(); ?>
            <p class="description">Κάθε αντίγραφο περιέχει όλα τα δεδομένα της πλατφόρμας: σειριακούς, εγγυήσεις και πελάτες, υποβολές Service, συγκαταθέσεις, ιστορικό ειδοποιήσεων, ρυθμίσεις και όρους, καθώς και τα αρχεία (παραστατικά, αποδείξεις). Δεν περιλαμβάνει τα κλειδιά πρόσβασης (Brevo) ούτε το υπόλοιπο WordPress (σελίδες, χρήστες, theme), που καλύπτονται από το backup της φιλοξενίας.</p>

            <form method="post" action="<?php echo esc_url($post); ?>" class="tw-inline-form">
                <?php wp_nonce_field('tw_backup_create'); ?><input type="hidden" name="action" value="tw_backup_create">
                <button class="button button-primary">⛁ Δημιουργία αντιγράφου τώρα</button>
                <span class="description">Αυτόματα αντίγραφα: <?php echo $s['backup_daily']
                    ? esc_html(sprintf('%d την ημέρα (%s) – επόμενο %s – στον server κρατούνται τα τελευταία %d (≈ %s)', TW_Backup::per_day(), implode(', ', TW_Backup::times()), $next ? wp_date('d/m/Y H:i', $next) : '—', (int) $s['backup_keep'], self::days_label((int) $s['backup_keep'] / TW_Backup::per_day())))
                    : '<strong>ανενεργά</strong>'; ?>.</span>
            </form>

            <table class="widefat striped"><thead><tr><th>Ημερομηνία</th><th>Τύπος</th><th>Μέγεθος</th><th>Περιεχόμενο</th><th>Κρυπτογράφηση</th><th>Εκτός server</th><th></th></tr></thead><tbody>
            <?php if (!$list) echo '<tr><td colspan="7">Δεν υπάρχει αντίγραφο ακόμη.</td></tr>'; ?>
            <?php foreach ($list as $b) : $m = TW_Backup::manifest($b['path']); ?>
                <tr><td><?php echo esc_html(TW_Util::datetime_label($b['time'])); ?></td><td><?php echo esc_html($types[$b['type']] ?? $b['type']); ?></td>
                    <td><?php echo esc_html(self::size_label($b['size'])); ?></td>
                    <td><?php echo $m ? esc_html(sprintf('%d εγγυήσεις, %d σειριακοί, %d αρχεία', $m['rows']['warranties'] ?? 0, $m['rows']['serials'] ?? 0, $m['files'] ?? 0)) : '—'; ?></td>
                    <td><?php echo $m && !empty($m['encrypted']) ? '🔒 Ναι' : 'Όχι'; ?></td>
                    <td><?php $si = TW_Backup_Remote::sent_info($b['file']); echo $si ? '✔ ' . esc_html(TW_Backup_Remote::label($si['service'])) . '<br><small>' . esc_html(TW_Util::datetime_label($si['at'])) . '</small>' : '—'; ?></td>
                    <td class="tw-row-actions">
                        <?php if (TW_Backup_Remote::enabled() && $m && !empty($m['encrypted'])) : ?>
                        <form method="post" action="<?php echo esc_url($post); ?>"><?php wp_nonce_field('tw_backup_send'); ?><input type="hidden" name="action" value="tw_backup_send"><input type="hidden" name="file" value="<?php echo esc_attr($b['file']); ?>">
                            <button class="button" title="Αποστολή στο <?php echo esc_attr(TW_Backup_Remote::label()); ?>">⇪ Αποστολή</button></form>
                        <?php endif; ?>
                        <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'tw_backup_download', 'file' => $b['file']], $post), 'tw_backup_download')); ?>">Λήψη</a>
                        <form method="post" action="<?php echo esc_url($post); ?>" onsubmit="return confirm('ΕΠΑΝΑΦΟΡΑ: όλα τα τωρινά δεδομένα της πλατφόρμας θα αντικατασταθούν με αυτά του <?php echo esc_attr(TW_Util::datetime_label($b['time'])); ?>. Πριν από την επαναφορά θα κρατηθεί αντίγραφο της τωρινής κατάστασης. Συνέχεια;')">
                            <?php wp_nonce_field('tw_backup_restore'); ?><input type="hidden" name="action" value="tw_backup_restore"><input type="hidden" name="file" value="<?php echo esc_attr($b['file']); ?>">
                            <?php if ($m && !empty($m['encrypted'])) : ?><input type="password" name="password" placeholder="κωδικός" size="10"><?php endif; ?>
                            <button class="button">Επαναφορά</button></form>
                        <form method="post" action="<?php echo esc_url($post); ?>" onsubmit="return confirm('Οριστική διαγραφή του αντιγράφου;')">
                            <?php wp_nonce_field('tw_backup_delete'); ?><input type="hidden" name="action" value="tw_backup_delete"><input type="hidden" name="file" value="<?php echo esc_attr($b['file']); ?>">
                            <button class="button-link tw-danger">Διαγραφή</button></form>
                    </td></tr>
            <?php endforeach; ?>
            </tbody></table>

            <div class="tw-columns">
                <div class="tw-panel">
                    <h2>Επαναφορά από αρχείο</h2>
                    <p class="description">Για αντίγραφο που έχετε κατεβάσει (π.χ. από άλλον server). Όλα τα τωρινά δεδομένα θα αντικατασταθούν· πριν από αυτό κρατείται αυτόματα αντίγραφο της τωρινής κατάστασης.</p>
                    <form method="post" action="<?php echo esc_url($post); ?>" enctype="multipart/form-data" onsubmit="return confirm('Όλα τα τωρινά δεδομένα θα αντικατασταθούν με αυτά του αρχείου. Συνέχεια;')">
                        <?php wp_nonce_field('tw_backup_restore'); ?><input type="hidden" name="action" value="tw_backup_restore">
                        <p><input type="file" name="backup_file" accept=".zip" required></p>
                        <p><input type="password" name="password" placeholder="Κωδικός (αν είναι κρυπτογραφημένο)" class="regular-text"></p>
                        <p><button class="button">Επαναφορά από αρχείο</button></p>
                    </form>
                </div>
                <div class="tw-panel">
                    <h2>Ρυθμίσεις</h2>
                    <form method="post" action="<?php echo esc_url($post); ?>">
                        <?php wp_nonce_field('tw_backup_settings'); ?><input type="hidden" name="action" value="tw_backup_settings">
                        <p><label><input type="checkbox" name="backup_daily" value="1" <?php checked((int) $s['backup_daily'], 1); ?>> Αυτόματα αντίγραφα</label></p>
                        <p><label>Πόσα την ημέρα <select name="backup_per_day" id="tw-per-day">
                            <?php foreach (array_keys(TW_Backup::PER_DAY) as $n) printf('<option value="%d"%s>%d</option>', $n, selected(TW_Backup::per_day(), $n, false), $n); ?>
                        </select></label>
                        <label>πρώτο στις <input type="time" name="backup_time" value="<?php echo esc_attr(preg_match('/^\d{2}:\d{2}$/', $s['backup_time']) ? $s['backup_time'] : '03:30'); ?>" required></label></p>
                        <p class="description">Τα υπόλοιπα γίνονται σε ίσα διαστήματα μέσα στο 24ωρο, π.χ. 2 την ημέρα με πρώτο στις 03:30 → 03:30 και 15:30. Προτιμήστε ώρες με λίγη κίνηση στο site.</p>
                        <p><label>Διατήρηση <strong>στον server</strong> των τελευταίων <input type="number" name="backup_keep" id="tw-keep" value="<?php echo (int) $s['backup_keep']; ?>" min="1" max="365" class="small-text"> αυτόματων αντιγράφων</label>
                            <span class="description" id="tw-keep-days"></span></p>
                        <p class="description">Τα παλαιότερα αυτόματα αντίγραφα σβήνονται από τον server. Τα χειροκίνητα και όσα γίνονται «πριν από επαναφορά» δεν σβήνονται αυτόματα. Για την υπηρεσία εκτός server (OneDrive κ.λπ.) ισχύει η ξεχωριστή ρύθμιση «Διατήρηση εκεί».</p>
                        <script>
                        (function () {
                            var pd = document.getElementById('tw-per-day'), k = document.getElementById('tw-keep'), out = document.getElementById('tw-keep-days');
                            function upd() { var d = (parseInt(k.value, 10) || 0) / (parseInt(pd.value, 10) || 1); out.textContent = '≈ ' + (d >= 2 ? Math.floor(d) + ' ημέρες' : (d >= 1 ? '1 ημέρα' : 'λιγότερο από 1 ημέρα')); }
                            pd.addEventListener('change', upd); k.addEventListener('input', upd); upd();
                        })();
                        </script>
                        <p><label>Κωδικός κρυπτογράφησης (AES-256)<br><input type="password" name="backup_password" class="regular-text" autocomplete="new-password" placeholder="<?php echo $s['backup_password'] ? 'Ορισμένος – αφήστε κενό για να μείνει' : 'Χωρίς κρυπτογράφηση'; ?>"></label></p>
                        <?php if ($s['backup_password']) : ?><p><label><input type="checkbox" name="backup_password_clear" value="1"> Κατάργηση κρυπτογράφησης</label></p><?php endif; ?>
                        <p class="description">Συνιστάται: το αρχείο περιέχει προσωπικά δεδομένα πελατών. Φυλάξτε τον κωδικό σε ασφαλές μέρος – χωρίς αυτόν το αντίγραφο δεν ανοίγει. Ισχύει για τα νέα αντίγραφα.</p>
                        <p><button class="button button-primary">Αποθήκευση</button></p>
                    </form>
                </div>
            </div>
            <?php self::remote_panel(); ?>
        </div>
        <?php
    }

    private static function remote_panel() {
        $s = TW_Settings::all();
        $post = self::url('backups');
        $svc = TW_Backup_Remote::service();
        $tokens = TW_Backup_Remote::tokens();
        $status = get_option(TW_Backup_Remote::STATUS);
        $secret_ph = function ($k) use ($s) { return $s[$k] ? 'Αποθηκευμένο – αφήστε κενό για να μείνει' : ''; };
        $oauth = [
            'gdrive' => ['Google Drive', 'Google Cloud Console → APIs & Services: ενεργοποιήστε το «Google Drive API», στο «OAuth consent screen» ορίστε την εφαρμογή, και στο «Credentials» δημιουργήστε «OAuth client ID» τύπου «Web application» με Authorized redirect URI την παρακάτω διεύθυνση.', 'backup_gdrive_client_id', 'backup_gdrive_client_secret', 'Client ID', 'Client secret'],
            'onedrive' => ['OneDrive', 'Microsoft Entra admin center (portal.azure.com) → App registrations → New registration, με Redirect URI τύπου «Web» την παρακάτω διεύθυνση. Μετά: Certificates & secrets → New client secret, και API permissions → Microsoft Graph → Files.ReadWrite, offline_access, User.Read.', 'backup_onedrive_client_id', 'backup_onedrive_client_secret', 'Application (client) ID', 'Client secret (Value)'],
            'dropbox' => ['Dropbox', 'dropbox.com/developers/apps → Create app → «Scoped access», «App folder». Στο Permissions ενεργοποιήστε files.content.write, files.content.read, files.metadata.read και account_info.read, και στο Settings προσθέστε στα Redirect URIs την παρακάτω διεύθυνση.', 'backup_dropbox_app_key', 'backup_dropbox_app_secret', 'App key', 'App secret'],
        ];
        ?>
        <div class="tw-panel tw-remote">
            <h2>Αποστολή εκτός server</h2>
            <p class="description">Κάθε αυτόματο αντίγραφο ανεβαίνει και σε μια υπηρεσία της εταιρείας, ώστε να υπάρχει ακόμη κι αν χαθεί ο server. Στέλνονται μόνο <strong>κρυπτογραφημένα</strong> αντίγραφα (περιέχουν προσωπικά δεδομένα πελατών)<?php echo $s['backup_password'] ? '.' : ' – <strong class="tw-danger">ορίστε πρώτα κωδικό κρυπτογράφησης στις Ρυθμίσεις αντιγράφων.</strong>'; ?></p>
            <?php if ($status) : ?>
                <p>Τελευταία αποστολή: <?php echo esc_html(TW_Util::datetime_label($status['at'])); ?> – <?php echo esc_html(TW_Backup_Remote::label($status['service'])); ?> –
                    <?php echo $status['ok'] ? '<span class="tw-ok">✔ επιτυχής</span>' : '<span class="tw-danger">✘ ' . esc_html($status['message']) . '</span>'; ?></p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url($post); ?>" id="tw-remote-form">
                <?php wp_nonce_field('tw_remote_settings'); ?><input type="hidden" name="action" value="tw_remote_settings">
                <table class="form-table">
                    <tr><th><label for="tw-backup_remote">Υπηρεσία</label></th><td><select id="tw-backup_remote" name="backup_remote">
                        <?php foreach (TW_Backup_Remote::services() as $k => $l) printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($svc, $k, false), esc_html($l)); ?>
                    </select></td></tr>
                    <tr data-remote="gdrive onedrive dropbox"><th>Φάκελος</th><td><input type="text" name="backup_remote_folder" value="<?php echo esc_attr($s['backup_remote_folder']); ?>" class="regular-text"></td></tr>
                    <tr data-remote="gdrive onedrive dropbox ftp"><th>Διατήρηση εκεί</th><td>τα τελευταία <input type="number" name="backup_remote_keep" value="<?php echo (int) $s['backup_remote_keep']; ?>" min="1" max="3650" class="small-text"> αυτόματα αντίγραφα</td></tr>
                    <tr data-remote="gdrive onedrive dropbox ftp"><th>Χειροκίνητα αντίγραφα</th><td><label><input type="checkbox" name="backup_remote_manual" value="1" <?php checked((int) $s['backup_remote_manual'], 1); ?>> Αποστολή και των χειροκίνητων αντιγράφων</label></td></tr>
                    <?php foreach ($oauth as $k => $o) : ?>
                        <tr data-remote="<?php echo esc_attr($k); ?>"><th>Ρύθμιση <?php echo esc_html($o[0]); ?></th><td>
                            <p class="description"><?php echo esc_html($o[1]); ?></p>
                            <p>Redirect URI: <code style="word-break:break-all"><?php echo esc_html(TW_Backup_Remote::redirect_uri()); ?></code></p>
                            <?php if (!get_option('permalink_structure')) : ?><p class="tw-danger">Χρειάζονται «φιλικά» permalinks (Ρυθμίσεις WordPress → Μόνιμοι σύνδεσμοι) για τη σύνδεση.</p><?php endif; ?>
                        </td></tr>
                        <tr data-remote="<?php echo esc_attr($k); ?>"><th><?php echo esc_html($o[4]); ?></th><td><input type="text" name="<?php echo esc_attr($o[2]); ?>" value="<?php echo esc_attr($s[$o[2]]); ?>" class="regular-text" autocomplete="off"></td></tr>
                        <tr data-remote="<?php echo esc_attr($k); ?>"><th><?php echo esc_html($o[5]); ?></th><td><input type="password" name="<?php echo esc_attr($o[3]); ?>" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr($secret_ph($o[3])); ?>"></td></tr>
                        <?php if ($k === 'onedrive') : ?>
                        <tr data-remote="onedrive"><th>Tenant</th><td><input type="text" name="backup_onedrive_tenant" value="<?php echo esc_attr($s['backup_onedrive_tenant']); ?>" class="regular-text"><p class="description">Για εταιρικό Microsoft 365 / OneDrive for Business: το <strong>Directory (tenant) ID</strong> (Entra → App registrations → η εφαρμογή → Overview). Το <code>common</code> λειτουργεί μόνο αν η εφαρμογή έχει δηλωθεί «multi-tenant».</p></td></tr>
                        <?php endif; ?>
                        <tr data-remote="<?php echo esc_attr($k); ?>"><th>Λογαριασμός</th><td>
                            <?php if (TW_Backup_Remote::connected($k)) : ?>
                                <span class="tw-ok">✔ Συνδεδεμένο</span> <?php echo !empty($tokens[$k]['account']) ? esc_html($tokens[$k]['account']) : ''; ?> <small>(<?php echo esc_html(TW_Util::datetime_label($tokens[$k]['connected_at'] ?? '')); ?>)</small>
                                <button class="button-link tw-danger" form="tw-remote-disconnect-<?php echo esc_attr($k); ?>">Αποσύνδεση</button>
                            <?php else : ?>
                                <button class="button button-primary" form="tw-remote-connect-<?php echo esc_attr($k); ?>">Σύνδεση με <?php echo esc_html($o[0]); ?></button>
                                <p class="description">Αποθηκεύστε πρώτα τα στοιχεία παραπάνω. Θα μεταφερθείτε στη σελίδα της υπηρεσίας για να εγκρίνετε την πρόσβαση με τον λογαριασμό της εταιρείας.</p>
                            <?php endif; ?>
                        </td></tr>
                    <?php endforeach; ?>
                    <tr data-remote="ftp"><th>Server</th><td><input type="text" name="backup_ftp_host" value="<?php echo esc_attr($s['backup_ftp_host']); ?>" class="regular-text" placeholder="ftp.example.gr"> θύρα <input type="number" name="backup_ftp_port" value="<?php echo (int) $s['backup_ftp_port']; ?>" class="small-text"></td></tr>
                    <tr data-remote="ftp"><th>Σύνδεση</th><td><select name="backup_ftp_mode"><option value="ftps" <?php selected($s['backup_ftp_mode'], 'ftps'); ?>>FTPS (κρυπτογραφημένη, συνιστάται)</option><option value="ftp" <?php selected($s['backup_ftp_mode'], 'ftp'); ?>>FTP (χωρίς κρυπτογράφηση)</option></select>
                        <label><input type="checkbox" name="backup_ftp_passive" value="1" <?php checked((int) $s['backup_ftp_passive'], 1); ?>> Passive mode</label></td></tr>
                    <tr data-remote="ftp"><th>Χρήστης</th><td><input type="text" name="backup_ftp_user" value="<?php echo esc_attr($s['backup_ftp_user']); ?>" class="regular-text" autocomplete="off"></td></tr>
                    <tr data-remote="ftp"><th>Κωδικός</th><td><input type="password" name="backup_ftp_password" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr($secret_ph('backup_ftp_password')); ?>"></td></tr>
                    <tr data-remote="ftp"><th>Φάκελος</th><td><input type="text" name="backup_ftp_path" value="<?php echo esc_attr($s['backup_ftp_path']); ?>" class="regular-text"></td></tr>
                </table>
                <p><button class="button button-primary">Αποθήκευση</button>
                    <?php if ($svc !== 'none') : ?><button class="button" form="tw-remote-test">Δοκιμή σύνδεσης</button><?php endif; ?></p>
            </form>
            <?php foreach (array_keys($oauth) as $k) : ?>
                <form method="post" action="<?php echo esc_url($post); ?>" id="tw-remote-connect-<?php echo esc_attr($k); ?>"><?php wp_nonce_field('tw_remote_connect'); ?><input type="hidden" name="action" value="tw_remote_connect"><input type="hidden" name="service" value="<?php echo esc_attr($k); ?>"></form>
                <form method="post" action="<?php echo esc_url($post); ?>" id="tw-remote-disconnect-<?php echo esc_attr($k); ?>" onsubmit="return confirm('Αποσύνδεση;')"><?php wp_nonce_field('tw_remote_disconnect'); ?><input type="hidden" name="action" value="tw_remote_disconnect"><input type="hidden" name="service" value="<?php echo esc_attr($k); ?>"></form>
            <?php endforeach; ?>
            <form method="post" action="<?php echo esc_url($post); ?>" id="tw-remote-test"><?php wp_nonce_field('tw_remote_test'); ?><input type="hidden" name="action" value="tw_remote_test"></form>
            <script>
            (function () {
                var sel = document.getElementById('tw-backup_remote');
                function show() { document.querySelectorAll('#tw-remote-form [data-remote]').forEach(function (r) { r.hidden = r.getAttribute('data-remote').split(' ').indexOf(sel.value) < 0; }); }
                sel.addEventListener('change', show); show();
            })();
            </script>
        </div>
        <?php
    }

    private static function backup_guard($action) {
        self::guard($action);
        if (!self::can_admin()) wp_die('Δεν έχετε πρόσβαση.', 403);
    }

    public static function handle_backup_create() {
        self::backup_guard('tw_backup_create');
        $r = TW_Backup::create('manual');
        if (is_wp_error($r)) {
            self::flash('Αποτυχία: ' . esc_html($r->get_error_message()), 'error');
        } else {
            $msg = sprintf('Το αντίγραφο δημιουργήθηκε (%s). <a href="%s">Λήψη</a>', esc_html(self::size_label($r['size'])),
                esc_url(wp_nonce_url(add_query_arg(['action' => 'tw_backup_download', 'file' => $r['file']], self::url('backups')), 'tw_backup_download')));
            $type = 'success';
            if (TW_Backup_Remote::enabled() && TW_Settings::get('backup_remote_manual')) {
                $sent = TW_Backup::send_remote($r);
                $msg .= $sent === true ? ' Στάλθηκε στο ' . esc_html(TW_Backup_Remote::label()) . '.' : ' Η αποστολή απέτυχε: ' . esc_html($sent->get_error_message());
                if ($sent !== true) $type = 'warning';
            }
            self::flash($msg, $type);
        }
        self::back('tw-backups');
    }

    public static function handle_backup_download() {
        if (!current_user_can(self::cap()) || !self::can_admin()) wp_die('', 403);
        check_admin_referer('tw_backup_download');
        $b = TW_Backup::find(sanitize_file_name(wp_unslash($_GET['file'] ?? '')));
        if (!$b) wp_die('Το αντίγραφο δεν βρέθηκε.');
        TW_DB::log_event('backup_downloaded', ['file' => $b['file']]);
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $b['file'] . '"');
        header('Content-Length: ' . $b['size']);
        readfile($b['path']);
        exit;
    }

    public static function handle_backup_delete() {
        self::backup_guard('tw_backup_delete');
        $b = TW_Backup::find(sanitize_file_name(self::post('file')));
        if ($b) { @unlink($b['path']); TW_DB::log_event('backup_deleted', ['file' => $b['file']]); self::flash('Το αντίγραφο διαγράφηκε.'); }
        self::back('tw-backups');
    }

    public static function handle_backup_restore() {
        self::backup_guard('tw_backup_restore');
        $password = (string) wp_unslash($_POST['password'] ?? '');
        if (!empty($_FILES['backup_file']['tmp_name']) && is_uploaded_file($_FILES['backup_file']['tmp_name'])) {
            $path = $_FILES['backup_file']['tmp_name'];
        } else {
            $b = TW_Backup::find(sanitize_file_name(self::post('file')));
            if (!$b) { self::flash('Το αντίγραφο δεν βρέθηκε.', 'error'); self::back('tw-backups'); }
            $path = $b['path'];
        }
        $r = TW_Backup::restore($path, $password);
        if (is_wp_error($r)) {
            self::flash('Η επαναφορά δεν έγινε: ' . esc_html($r->get_error_message()), 'error');
        } else {
            self::flash(sprintf('Η επαναφορά ολοκληρώθηκε από το αντίγραφο της %s: %d εγγυήσεις, %d σειριακοί, %d αρχεία. Η προηγούμενη κατάσταση φυλάχθηκε ως αντίγραφο «Πριν από επαναφορά».',
                esc_html(TW_Util::datetime_label($r['manifest']['created_at'] ?? '')), $r['rows']['warranties'] ?? 0, $r['rows']['serials'] ?? 0, $r['files']));
        }
        self::back('tw-backups');
    }

    public static function handle_remote_settings() {
        self::backup_guard('tw_remote_settings');
        $svc = sanitize_key(self::post('backup_remote'));
        $v = [
            'backup_remote' => isset(TW_Backup_Remote::services()[$svc]) ? $svc : 'none',
            'backup_remote_folder' => trim(sanitize_text_field(self::post('backup_remote_folder')), '/ ') ?: 'Thermic Warranty Backups',
            'backup_remote_keep' => max(1, min(3650, (int) self::post('backup_remote_keep'))),
            'backup_remote_manual' => empty($_POST['backup_remote_manual']) ? 0 : 1,
            'backup_ftp_passive' => empty($_POST['backup_ftp_passive']) ? 0 : 1,
            'backup_ftp_mode' => self::post('backup_ftp_mode') === 'ftp' ? 'ftp' : 'ftps',
            'backup_ftp_port' => max(1, min(65535, (int) self::post('backup_ftp_port') ?: 21)),
        ];
        foreach (['backup_gdrive_client_id', 'backup_onedrive_client_id', 'backup_dropbox_app_key', 'backup_onedrive_tenant', 'backup_ftp_host', 'backup_ftp_user', 'backup_ftp_path'] as $k) {
            if (isset($_POST[$k])) $v[$k] = sanitize_text_field(self::post($k));
        }
        foreach (['backup_gdrive_client_secret', 'backup_onedrive_client_secret', 'backup_dropbox_app_secret', 'backup_ftp_password'] as $k) {
            $val = (string) wp_unslash($_POST[$k] ?? '');
            if ($val !== '') $v[$k] = trim($val);
        }
        if ($v['backup_remote_folder'] !== TW_Settings::get('backup_remote_folder')) $v['backup_gdrive_folder_id'] = '';
        TW_Settings::update($v);
        TW_DB::log_event('remote_settings', ['service' => $v['backup_remote']]);
        self::flash('Οι ρυθμίσεις αποστολής αποθηκεύτηκαν.' . ($v['backup_remote'] !== 'none' && !TW_Settings::get('backup_password') ? ' Ορίστε κωδικό κρυπτογράφησης – χωρίς αυτόν δεν στέλνονται αντίγραφα.' : ''));
        self::back('tw-backups');
    }

    public static function handle_remote_connect() {
        self::backup_guard('tw_remote_connect');
        $url = TW_Backup_Remote::auth_url(sanitize_key(self::post('service')));
        if (is_wp_error($url)) { self::flash(esc_html($url->get_error_message()), 'error'); self::back('tw-backups'); }
        wp_redirect($url); // external: the provider's consent page
        exit;
    }

    public static function handle_remote_disconnect() {
        self::backup_guard('tw_remote_disconnect');
        $svc = sanitize_key(self::post('service'));
        TW_Backup_Remote::disconnect($svc);
        TW_DB::log_event('remote_disconnected', ['service' => $svc]);
        self::flash('Αποσυνδέθηκε.');
        self::back('tw-backups');
    }

    public static function handle_remote_test() {
        self::backup_guard('tw_remote_test');
        $r = TW_Backup_Remote::test();
        self::flash($r === true ? 'Η σύνδεση με ' . esc_html(TW_Backup_Remote::label()) . ' λειτουργεί: ανέβηκε και σβήστηκε ένα δοκιμαστικό αρχείο.' : 'Αποτυχία: ' . esc_html($r->get_error_message()), $r === true ? 'success' : 'error');
        self::back('tw-backups');
    }

    public static function handle_backup_send() {
        self::backup_guard('tw_backup_send');
        $b = TW_Backup::find(sanitize_file_name(self::post('file')));
        if (!$b) { self::flash('Το αντίγραφο δεν βρέθηκε.', 'error'); self::back('tw-backups'); }
        $r = TW_Backup_Remote::send($b['path']);
        self::flash($r === true ? 'Το αντίγραφο στάλθηκε στο ' . esc_html(TW_Backup_Remote::label()) . '.' : 'Αποτυχία αποστολής: ' . esc_html($r->get_error_message()), $r === true ? 'success' : 'error');
        self::back('tw-backups');
    }

    public static function handle_backup_settings() {
        self::backup_guard('tw_backup_settings');
        $time = self::post('backup_time');
        $values = [
            'backup_daily' => empty($_POST['backup_daily']) ? 0 : 1,
            'backup_keep' => max(1, min(365, (int) self::post('backup_keep'))),
            'backup_per_day' => isset(TW_Backup::PER_DAY[(int) self::post('backup_per_day')]) ? (int) self::post('backup_per_day') : 1,
            'backup_time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : '03:30',
        ];
        $pw = (string) wp_unslash($_POST['backup_password'] ?? '');
        if ($pw !== '') $values['backup_password'] = $pw;
        if (!empty($_POST['backup_password_clear'])) $values['backup_password'] = '';
        TW_Settings::update($values);
        TW_Backup::reschedule();
        TW_DB::log_event('backup_settings', ['auto' => $values['backup_daily'], 'per_day' => $values['backup_per_day'], 'time' => $values['backup_time'], 'keep' => $values['backup_keep'], 'encrypted' => (string) TW_Settings::get('backup_password') !== '']);
        self::flash('Οι ρυθμίσεις αντιγράφων αποθηκεύτηκαν.');
        self::back('tw-backups');
    }

    // ------------------------------------------------------------------ Brevo tools

    private static function brevo_tools() {
        $post = self::url('settings', ['tab' => 'brevo']);
        $log = get_option(TW_Brevo::LOG_OPTION, []);
        ?>
        <h2>Έλεγχος &amp; ρύθμιση</h2>
        <form method="post" action="<?php echo esc_url($post); ?>" class="tw-inline-form">
            <?php wp_nonce_field('tw_brevo_tool'); ?><input type="hidden" name="action" value="tw_brevo_tool">
            <button class="button" name="tool" value="account">Έλεγχος σύνδεσης &amp; μονάδων SMS</button>
            <button class="button" name="tool" value="webhook">Ρύθμιση αναφορών email στο Brevo</button>
        </form>
        <h2>Τελευταίες αναφορές από το Brevo</h2>
        <p class="description">Χρήσιμο για έλεγχο μετά την ενεργοποίηση: εδώ φαίνεται ό,τι στέλνει το Brevo για τα μηνύματά σας.</p>
        <table class="widefat striped"><thead><tr><th>Λήψη</th><th>Συμβάν</th><th>Παραλήπτης</th><th>Μήνυμα</th></tr></thead><tbody>
        <?php if (!$log) echo '<tr><td colspan="4">Καμία αναφορά ακόμη.</td></tr>'; ?>
        <?php foreach (array_slice((array) $log, 0, 30) as $l) : $d = (array) $l['data']; ?>
            <tr><td><?php echo esc_html(TW_Util::datetime_label($l['at'])); ?></td>
                <td><?php echo esc_html((string) ($d['event'] ?? ($d['msg_status'] ?? ($d['status'] ?? '?')))); ?><?php echo !empty($d['reason']) ? '<br><small>' . esc_html((string) $d['reason']) . '</small>' : ''; ?></td>
                <td><?php echo esc_html((string) ($d['email'] ?? ($d['to'] ?? ($d['recipient'] ?? '')))); ?></td>
                <td><small><?php echo esc_html((string) ($d['message-id'] ?? ($d['messageId'] ?? ''))); ?></small></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php
    }

    public static function handle_brevo_tool() {
        self::guard('tw_brevo_tool');
        if (self::post('tool') === 'webhook') {
            $r = TW_Brevo::ensure_email_webhook();
            self::flash($r === true ? 'Οι αναφορές παράδοσης email ρυθμίστηκαν στο Brevo.' : 'Αποτυχία: ' . esc_html($r), $r === true ? 'success' : 'error');
        } else {
            $a = TW_Brevo::account();
            if (is_wp_error($a)) {
                self::flash('Αποτυχία σύνδεσης: ' . esc_html($a->get_error_message()), 'error');
            } else {
                self::flash(sprintf('Σύνδεση OK – λογαριασμός %s%s. Μονάδες SMS: %s.%s', esc_html($a['email']), $a['company'] ? ' (' . esc_html($a['company']) . ')' : '',
                    $a['sms_credits'] === null ? '—' : esc_html((string) $a['sms_credits']), $a['email_plan'] ? ' Πακέτο: ' . esc_html($a['email_plan']) . '.' : ''));
            }
        }
        self::back('tw-settings', ['tab' => 'brevo']);
    }

    // ------------------------------------------------------------------ team

    public static function page_team() {
        if (!current_user_can('manage_options') && !current_user_can('tw_manage_team')) { echo '<p>Δεν έχετε πρόσβαση.</p>'; return; }
        $users = get_users(['role__in' => ['administrator', TW_App::ROLE], 'orderby' => 'display_name']);
        $post = self::url('team');
        ?>
        <div class="wrap tw-wrap">
            <h1>Ομάδα</h1>
            <?php self::notice(); ?>
            <p class="description">Τα μέλη της ομάδας συνδέονται στο <code><?php echo esc_html(TW_App::admin_url()); ?></code> με email και κωδικό<?php echo TW_Settings::get('staff_2fa') ? ', και επιβεβαιώνουν με κωδικό μίας χρήσης στο email' : ''; ?>. Δεν έχουν πρόσβαση στο διαχειριστικό του WordPress.</p>
            <table class="widefat striped"><thead><tr><th>Όνομα</th><th>Email</th><th>Ρόλος</th><th>Τελευταία σύνδεση</th><th></th></tr></thead><tbody>
            <?php foreach ($users as $u) : $is_admin = user_can($u, 'manage_options'); $last = get_user_meta($u->ID, 'tw_last_login', true); ?>
                <tr><td><?php echo esc_html($u->display_name); ?></td><td><?php echo esc_html($u->user_email); ?></td>
                    <td><?php echo $is_admin ? 'Διαχειριστής WordPress' : (user_can($u, 'tw_manage_team') ? 'Υπεύθυνος εγγυήσεων (διαχείριση ομάδας)' : 'Διαχειριστής εγγυήσεων'); ?></td>
                    <td><?php echo $last ? esc_html(TW_Util::datetime_label($last)) : '—'; ?></td>
                    <td><?php if (!$is_admin && $u->ID !== get_current_user_id()) : ?>
                        <form method="post" action="<?php echo esc_url($post); ?>" onsubmit="return confirm('Αφαίρεση πρόσβασης;')"><?php wp_nonce_field('tw_team_remove'); ?>
                            <input type="hidden" name="action" value="tw_team_remove"><input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>">
                            <button class="button-link tw-danger">Αφαίρεση πρόσβασης</button></form>
                    <?php endif; ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <div class="tw-panel" style="max-width:640px;margin-top:20px">
                <h2>Νέο μέλος</h2>
                <form method="post" action="<?php echo esc_url($post); ?>">
                    <?php wp_nonce_field('tw_team_add'); ?><input type="hidden" name="action" value="tw_team_add">
                    <p><label>Ονοματεπώνυμο<br><input type="text" name="name" class="regular-text" required></label></p>
                    <p><label>Email<br><input type="email" name="email" class="regular-text" required></label></p>
                    <p><label><input type="checkbox" name="manage_team" value="1"> Μπορεί να διαχειρίζεται και την ομάδα</label></p>
                    <p><button class="button button-primary">Προσθήκη &amp; αποστολή πρόσκλησης</button></p>
                    <p class="description">Το μέλος λαμβάνει email με τον σύνδεσμο του διαχειριστικού και ορίζει μόνο του κωδικό από την επιλογή «Πρώτη σύνδεση / ξέχασα τον κωδικό».</p>
                </form>
            </div>
        </div>
        <?php
    }

    public static function handle_team_add() {
        self::guard('tw_team_add');
        if (!current_user_can('manage_options') && !current_user_can('tw_manage_team')) wp_die('', 403);
        $email = strtolower(sanitize_email(self::post('email')));
        $name = sanitize_text_field(self::post('name'));
        if (!is_email($email)) { self::flash('Μη έγκυρο email.', 'error'); self::back('tw-team'); }
        $user = get_user_by('email', $email);
        if (!$user) {
            $login = sanitize_user(current(explode('@', $email)), true) ?: 'tw-user';
            $base = $login; $i = 1;
            while (username_exists($login)) $login = $base . (++$i);
            $id = wp_insert_user(['user_login' => $login, 'user_email' => $email, 'display_name' => $name, 'first_name' => $name,
                'user_pass' => wp_generate_password(32, true, true), 'role' => TW_App::ROLE]);
            if (is_wp_error($id)) { self::flash($id->get_error_message(), 'error'); self::back('tw-team'); }
            $user = get_user_by('id', $id);
        } elseif (!user_can($user, 'manage_options')) {
            $user->add_role(TW_App::ROLE);
        }
        if (!empty($_POST['manage_team'])) $user->add_cap('tw_manage_team'); else $user->remove_cap('tw_manage_team');
        $s = TW_Settings::all();
        TW_Messenger::send_email($email, sprintf('Πρόσβαση στο διαχειριστικό εγγυήσεων – %s', $s['company_name']),
            sprintf("Γεια σας %s,\n\nσας δόθηκε πρόσβαση στο διαχειριστικό εγγυήσεων της %s:\n%s\n\nΓια την πρώτη σύνδεση επιλέξτε «Πρώτη σύνδεση / ξέχασα τον κωδικό», βάλτε το email σας (%s) και ορίστε κωδικό.",
                $name ?: $user->display_name, $s['company_name'], TW_App::admin_url(), $email));
        TW_DB::log_event('team_added', ['user' => $email]);
        self::flash(sprintf('Το μέλος %s προστέθηκε και ενημερώθηκε με email.', esc_html($email)));
        self::back('tw-team');
    }

    public static function handle_team_remove() {
        self::guard('tw_team_remove');
        if (!current_user_can('manage_options') && !current_user_can('tw_manage_team')) wp_die('', 403);
        $user = get_user_by('id', (int) self::post('user_id'));
        if ($user && !user_can($user, 'manage_options') && $user->ID !== get_current_user_id()) {
            $user->remove_role(TW_App::ROLE);
            $user->remove_cap('tw_manage_team');
            $user->remove_cap(self::CAP);
            WP_Session_Tokens::get_instance($user->ID)->destroy_all();
            TW_DB::log_event('team_removed', ['user' => $user->user_email]);
            self::flash('Η πρόσβαση αφαιρέθηκε.');
        }
        self::back('tw-team');
    }

    // ------------------------------------------------------------------ GDPR erasure requests (WordPress user_request posts)

    private static function erasure_requests() {
        $reqs = get_posts(['post_type' => 'user_request', 'post_name__in' => ['remove_personal_data'], 'post_status' => ['request-pending', 'request-confirmed', 'request-completed'], 'numberposts' => 50, 'orderby' => 'date', 'order' => 'DESC']);
        $st = ['request-pending' => 'Αναμένει επιβεβαίωση από τον πελάτη', 'request-confirmed' => 'Επιβεβαιωμένο – προς εκτέλεση', 'request-completed' => 'Ολοκληρώθηκε'];
        echo '<h2>Αιτήματα διαγραφής</h2><table class="widefat striped"><thead><tr><th>Ημ/νία</th><th>Email</th><th>Κατάσταση</th><th></th></tr></thead><tbody>';
        if (!$reqs) echo '<tr><td colspan="4">Κανένα αίτημα.</td></tr>';
        foreach ($reqs as $r) {
            echo '<tr><td>' . esc_html(TW_Util::datetime_label($r->post_date)) . '</td><td>' . esc_html($r->post_title) . '</td><td>' . esc_html($st[$r->post_status] ?? $r->post_status) . '</td><td>';
            if ($r->post_status === 'request-confirmed') {
                echo '<form method="post" action="' . esc_url(self::url('settings', ['tab' => 'privacy'])) . '" onsubmit="return confirm(\'Εκτέλεση διαγραφής για ' . esc_attr($r->post_title) . ';\')">';
                wp_nonce_field('tw_process_erasure');
                echo '<input type="hidden" name="action" value="tw_process_erasure"><input type="hidden" name="request_id" value="' . (int) $r->ID . '"><button class="button">Εκτέλεση</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public static function handle_process_erasure() {
        self::guard('tw_process_erasure');
        $r = get_post((int) self::post('request_id'));
        if (!$r || $r->post_type !== 'user_request' || $r->post_status !== 'request-confirmed') {
            self::flash('Το αίτημα δεν βρέθηκε ή δεν έχει επιβεβαιωθεί.', 'error');
            self::back('tw-settings', ['tab' => 'privacy']);
        }
        $res = TW_Privacy::eraser($r->post_title);
        wp_update_post(['ID' => $r->ID, 'post_status' => 'request-completed']);
        update_post_meta($r->ID, '_wp_user_request_completed_timestamp', time());
        TW_DB::log_event('erasure_processed', ['email' => TW_Util::mask_email($r->post_title), 'removed' => $res['items_removed'], 'retained' => $res['items_retained']]);
        self::flash('Ολοκληρώθηκε. ' . esc_html(implode(' ', $res['messages']) ?: 'Τα στοιχεία ανωνυμοποιήθηκαν.'));
        self::back('tw-settings', ['tab' => 'privacy']);
    }

    // ------------------------------------------------------------------ handlers

    public static function handle_save_settings() {
        self::guard('tw_save_settings');
        $tab = sanitize_key(self::post('tab'));
        $in = isset($_POST['s']) && is_array($_POST['s']) ? wp_unslash($_POST['s']) : [];
        $defaults = TW_Settings::defaults();
        $checkboxes = [
            'general' => ['admin_digest'],
            'app' => ['staff_2fa'],
            'brevo' => ['brevo_email', 'brevo_sms', 'fallback_channel'],
            'schedule' => ['lock_on_missed_service', 'lock_on_expiry', 'notify_partner', 'service_notify_company'],
            'sms' => ['sms_enabled'],
        ];
        $numbers = ['service_submit_window_days', 'service_resubmit_days', 'warranty_years', 'service_interval_years', 'lock_offset', 'catchup_days', 'max_registration_delay_days', 'invoice_max_mb', 'retention_months_after_end'];
        $multiline = ['sms_headers', 'sms_body', 'terms_label', 'accuracy_label', 'marketing_label'];
        $urls = ['portal_url', 'register_url', 'privacy_url', 'sms_url', 'logo_url'];
        $values = [];
        foreach ($in as $k => $v) {
            if (!array_key_exists($k, $defaults) || $k === 'templates') continue;
            if (in_array($k, $numbers, true)) $values[$k] = max(0, (int) $v);
            elseif (in_array($k, $urls, true)) $values[$k] = esc_url_raw(trim($v));
            elseif (in_array($k, $multiline, true)) $values[$k] = sanitize_textarea_field($v);
            elseif ($k === 'admin_slug' || $k === 'public_slug') $values[$k] = sanitize_title($v) ?: $defaults[$k];
            elseif ($k === 'invoice_mode') $values[$k] = in_array($v, ['required', 'optional', 'off'], true) ? $v : 'required';
            elseif ($k === 'service_proof_mode') $values[$k] = in_array($v, ['upload', 'fields', 'both'], true) ? $v : 'upload';
            elseif ($k === 'brand_color') $values[$k] = sanitize_hex_color($v) ?: $defaults[$k];
            elseif ($k === 'reminder_offsets') $values[$k] = implode(',', array_filter(array_map('trim', explode(',', $v)), function ($p) { return preg_match('/^[+-]?\d+$/', $p); }));
            else $values[$k] = sanitize_text_field($v);
        }
        foreach ($checkboxes[$tab] ?? [] as $cb) $values[$cb] = empty($in[$cb]) ? 0 : 1;
        if (isset($values['warranty_years'])) $values['warranty_years'] = max(1, $values['warranty_years']);
        if ($tab === 'sms' && isset($values['sms_method'])) $values['sms_method'] = strtoupper($values['sms_method']) === 'GET' ? 'GET' : 'POST';

        if ($tab === 'messages' && isset($_POST['tpl']) && is_array($_POST['tpl'])) {
            $tpl = [];
            foreach (wp_unslash($_POST['tpl']) as $key => $f) {
                if (!isset(TW_Settings::template_defaults()[$key]) || !is_array($f)) continue;
                $tpl[$key] = [
                    'subject' => sanitize_text_field($f['subject'] ?? ''),
                    'body' => sanitize_textarea_field($f['body'] ?? ''),
                    'sms' => sanitize_textarea_field($f['sms'] ?? ''),
                ];
            }
            $values['templates'] = $tpl;
        }
        $msg = 'Οι ρυθμίσεις αποθηκεύτηκαν.';
        if ($tab === 'brevo') {
            $newkey = trim(self::post('brevo_api_key_new'));
            if ($newkey !== '') $values['brevo_api_key'] = sanitize_text_field($newkey);
            unset($values['brevo_webhook_token']);
            if (!empty($values['brevo_email']) && !is_email($values['brevo_sender_email'] ?? '')) {
                $msg .= ' Προσοχή: συμπληρώστε email αποστολέα επιβεβαιωμένο στο Brevo.';
            }
        }
        if ($tab === 'terms' && isset($_POST['terms_text'])) {
            $before = (int) TW_Settings::current_terms()['version'];
            $v = TW_Settings::save_terms(sanitize_textarea_field(wp_unslash($_POST['terms_text'])));
            if ($v !== $before) {
                $msg .= sprintf(' Δημιουργήθηκε νέα έκδοση όρων v%d.', $v);
                TW_DB::log_event('terms_published', ['version' => $v]);
            }
        }
        if ($tab === 'schedule') {
            $old = TW_Settings::all();
            foreach (['warranty_years', 'service_interval_years'] as $k) {
                if (isset($values[$k]) && (int) $old[$k] !== (int) $values[$k]) {
                    $msg .= ' Προσοχή: η αλλαγή διάρκειας ισχύει για νέες ενεργοποιήσεις· η ημερομηνία λήξης των υπαρχουσών εγγυήσεων δεν αλλάζει.';
                    break;
                }
            }
        }
        $old = TW_Settings::all();
        if ((isset($values['admin_slug']) && $values['admin_slug'] !== $old['admin_slug']) || (isset($values['public_slug']) && $values['public_slug'] !== $old['public_slug'])) {
            if (($values['admin_slug'] ?? $old['admin_slug']) === ($values['public_slug'] ?? $old['public_slug'])) {
                self::flash('Οι δύο διευθύνσεις πρέπει να είναι διαφορετικές.', 'error');
                self::back('tw-settings', ['tab' => $tab]);
            }
            update_option('tw_flush_rewrite', 1, false);
            TW_Settings::update($values);
            // The admin URL itself may have changed: go to the new one.
            set_transient('tw_notice_' . get_current_user_id(), ['text' => $msg . ' Η διεύθυνση άλλαξε.', 'type' => 'success'], 120);
            TW_App::register_rewrites();
            flush_rewrite_rules(false);
            delete_option('tw_flush_rewrite');
            wp_safe_redirect(TW_App::admin_url('settings', ['tab' => $tab]));
            exit;
        }
        TW_Settings::update($values);
        self::flash($msg);
        self::back('tw-settings', ['tab' => $tab]);
    }

    public static function handle_terms_view() {
        if (!current_user_can(self::cap())) wp_die('', 403);
        check_admin_referer('tw_terms_view');
        $t = TW_Settings::terms_version((int) ($_GET['v'] ?? 0));
        if (!$t) wp_die('Δεν βρέθηκε.');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Όροι v' . (int) $t['version'] . '</title><body style="font-family:sans-serif;max-width:800px;margin:2em auto;line-height:1.5">';
        echo '<p><strong>Έκδοση ' . (int) $t['version'] . '</strong> – δημοσίευση ' . esc_html(TW_Util::datetime_label($t['published_at'])) . '<br><small>SHA-256: ' . esc_html($t['hash']) . '</small></p><hr>';
        echo wpautop(esc_html($t['text'])) . '</body>';
        exit;
    }

    public static function handle_import() {
        self::guard('tw_import');
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            self::flash('Επιλέξτε αρχείο.', 'error');
            self::back('tw-serials');
        }
        $rows = TW_Importer::read_file($_FILES['file']['tmp_name'], $_FILES['file']['name']);
        if (is_wp_error($rows)) {
            self::flash($rows->get_error_message(), 'error');
            self::back('tw-serials');
        }
        $res = TW_Importer::import(TW_Importer::to_records($rows), [
            'product_code' => sanitize_text_field(self::post('default_code')),
            'product_name' => sanitize_text_field(self::post('default_name')),
        ]);
        set_transient('tw_import_result_' . get_current_user_id(), $res, 600);
        self::back('tw-serials', ['batch' => $res['inserted'] ? $res['batch'] : null]);
    }

    public static function handle_template() {
        if (!current_user_can(self::cap())) wp_die('', 403);
        check_admin_referer('tw_template');
        $file = TW_Importer::template_xlsx();
        nocache_headers();
        if ($file) {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="protypo-seiriakon.xlsx"');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            @unlink($file);
        } else { // no ZipArchive on the server: CSV that Excel opens the same way
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="protypo-seiriakon.csv"');
            echo "\xEF\xBB\xBF" . implode(';', TW_Importer::TEMPLATE_HEADERS) . "\r\n";
        }
        exit;
    }

    public static function handle_add_serial() {
        self::guard('tw_add_serial');
        $records = [];
        foreach (preg_split('/\r?\n/', self::post('serials')) as $line) {
            if (trim($line) === '') continue;
            $records[] = ['serial' => TW_Util::normalize_serial($line), 'product_code' => sanitize_text_field(self::post('product_code')), 'product_name' => sanitize_text_field(self::post('product_name')), 'notes' => ''];
        }
        $res = TW_Importer::import($records);
        set_transient('tw_import_result_' . get_current_user_id(), $res, 600);
        self::back('tw-serials');
    }

    public static function handle_serial_status() {
        global $wpdb;
        self::guard('tw_serial_status');
        $id = (int) self::post('id');
        $to = self::post('to') === 'void' ? 'void' : 'available';
        $from = $to === 'void' ? 'available' : 'void';
        $wpdb->query($wpdb->prepare("UPDATE " . TW_DB::table('serials') . " SET status = %s WHERE id = %d AND status = %s", $to, $id, $from));
        TW_DB::log_event('serial_' . $to, '', 0, $id);
        self::flash($to === 'void' ? 'Ο σειριακός ακυρώθηκε και δεν μπορεί να ενεργοποιηθεί.' : 'Ο σειριακός είναι ξανά διαθέσιμος.');
        self::back('tw-serials');
    }

    private static function warranty_from_post() {
        $w = TW_Warranty::get((int) self::post('id'));
        if (!$w) wp_die('Η εγγύηση δεν βρέθηκε.');
        return $w;
    }

    public static function handle_record_service() {
        self::guard('tw_record_service');
        $w = self::warranty_from_post();
        $no = (int) self::post('milestone_no');
        $date = self::post('performed_date');
        $valid = false;
        foreach (TW_Schedule::milestones($w->purchase_date, $w->warranty_end) as $m) if ($m['type'] === 'service' && $m['no'] === $no) $valid = true;
        $vat = preg_replace('/\D/', '', self::post('technician_vat'));
        if (!$valid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            self::flash('Μη έγκυρα στοιχεία Service.', 'error');
        } elseif ($vat !== '' && !TW_Util::valid_afm($vat)) {
            self::flash('Ο ΑΦΜ τεχνικού δεν είναι έγκυρος.', 'error');
        } else {
            $rd = self::post('receipt_date');
            $receipt = ['file' => '', 'name' => '', 'number' => sanitize_text_field(self::post('receipt_number')),
                'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $rd) ? $rd : null];
            if (!empty($_FILES['receipt_file']['name'])) {
                $file = TW_Util::store_invoice($_FILES['receipt_file'], 'της απόδειξης');
                if (is_wp_error($file)) { self::flash($file->get_error_message(), 'error'); self::back('tw-warranties', ['view' => $w->id]); }
                $receipt['file'] = $file['file'];
                $receipt['name'] = $file['name'];
            }
            TW_Warranty::record_service($w, $no, $date, self::post('performed_by') === 'thermic' ? 'thermic' : 'partner',
                sanitize_text_field(self::post('technician')), !empty($_POST['genuine_parts']), sanitize_textarea_field(self::post('notes')), $vat, $receipt);
            self::flash(sprintf('Καταχωρήθηκε το %dο Service.', $no));
        }
        self::back('tw-warranties', ['view' => $w->id]);
    }

    public static function handle_review_service() {
        self::guard('tw_review_service');
        $w = self::warranty_from_post();
        $no = (int) self::post('milestone_no');
        $approve = self::post('decision') === 'approve';
        $note = sanitize_text_field(self::post('note'));
        if (!$approve && $note === '') {
            self::flash('Για απόρριψη χρειάζεται αιτιολογία (θα τη δει ο πελάτης).', 'error');
            self::back('tw-warranties', ['view' => $w->id]);
        }
        $row = TW_Warranty::review_service($w, $no, $approve, $note);
        if (is_wp_error($row)) {
            self::flash($row->get_error_message(), 'error');
        } else {
            if ($approve) {
                self::flash('Η υποβολή σημειώθηκε ως ελεγμένη.');
            } else {
                $vars = TW_Warranty::service_vars($w, $row);
                $r = TW_Messenger::send_template_email($w->email, 'service_rejected', $vars);
                TW_Messenger::send_template_sms($w->phone_mobile, 'service_rejected', $vars);
                self::flash(sprintf('Η υποβολή απορρίφθηκε. Ο πελάτης μπορεί να υποβάλει ξανά έως %s.', TW_Util::date_label($row->resubmit_until))
                    . ($r === true ? ' Ο πελάτης ενημερώθηκε με email.' : ' Το email προς τον πελάτη απέτυχε: ' . esc_html($r)), $r === true ? 'success' : 'warning');
            }
        }
        $back = self::post('back');
        if ($back === 'dashboard') self::back('tw-dashboard');
        self::back('tw-warranties', ['view' => $w->id]);
    }

    public static function handle_receipt() {
        if (!current_user_can(self::cap())) wp_die('', 403);
        check_admin_referer('tw_receipt');
        $w = TW_Warranty::get((int) ($_GET['id'] ?? 0));
        $sv = $w ? TW_Warranty::service_row((int) ($_GET['service_id'] ?? 0)) : null;
        if ($sv && (int) $sv->warranty_id !== (int) $w->id) $sv = null;
        $path = $sv ? TW_Util::invoice_path($sv->receipt_file) : '';
        if (!$path) wp_die('Το αρχείο δεν βρέθηκε.');
        TW_DB::log_event('receipt_downloaded', ['milestone' => (int) $sv->milestone_no], $w->id, $w->serial_id);
        $type = wp_check_filetype($path, TW_Util::invoice_mimes());
        nocache_headers();
        header('Content-Type: ' . ($type['type'] ?: 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . rawurlencode($sv->receipt_original_name ?: basename($path)) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    public static function handle_delete_service() {
        self::guard('tw_delete_service');
        $w = self::warranty_from_post();
        $sv = TW_Warranty::service_row((int) self::post('service_id'));
        if ($sv && (int) $sv->warranty_id === (int) $w->id) {
            TW_Warranty::delete_service($sv);
            TW_DB::log_event('service_deleted', ['milestone' => (int) $sv->milestone_no, 'date' => $sv->performed_date, 'source' => $sv->source], $w->id, $w->serial_id);
            self::flash('Η καταχώρηση Service διαγράφηκε.');
        }
        self::back('tw-warranties', ['view' => $w->id]);
    }

    public static function handle_set_status() {
        self::guard('tw_set_status');
        $w = self::warranty_from_post();
        $status = self::post('status');
        $note = sanitize_text_field(self::post('note'));
        if (!isset(TW_Util::status_labels()[$status]) || $note === '') {
            self::flash('Επιλέξτε κατάσταση και συμπληρώστε αιτιολογία.', 'error');
        } elseif ($status === 'locked') {
            TW_Warranty::lock($w, $note, null);
            self::flash('Η εγγύηση κλειδώθηκε.');
        } else {
            TW_Warranty::set_status($w, $status, $note);
            self::flash('Η κατάσταση άλλαξε.');
        }
        self::back('tw-warranties', ['view' => $w->id]);
    }

    public static function handle_resend_activation() {
        self::guard('tw_resend_activation');
        $w = self::warranty_from_post();
        $r = TW_Messenger::send_template_email($w->email, 'activation', TW_Messenger::warranty_vars($w));
        TW_DB::log_event($r === true ? 'activation_email_resent' : 'activation_email_failed', $r === true ? '' : $r, $w->id, $w->serial_id);
        self::flash($r === true ? 'Το email στάλθηκε.' : 'Αποτυχία: ' . esc_html($r), $r === true ? 'success' : 'error');
        self::back('tw-warranties', ['view' => $w->id]);
    }

    public static function handle_anonymize() {
        self::guard('tw_anonymize');
        $w = self::warranty_from_post();
        TW_Warranty::anonymize($w);
        self::flash('Τα προσωπικά στοιχεία ανωνυμοποιήθηκαν.');
        self::back('tw-warranties', ['view' => $w->id]);
    }

    public static function handle_invoice() {
        if (!current_user_can(self::cap())) wp_die('', 403);
        check_admin_referer('tw_invoice');
        $w = TW_Warranty::get((int) ($_GET['id'] ?? 0));
        $path = $w ? TW_Util::invoice_path($w->invoice_file) : '';
        if (!$path) wp_die('Το αρχείο δεν βρέθηκε.');
        TW_DB::log_event('invoice_downloaded', '', $w->id, $w->serial_id);
        $type = wp_check_filetype($path, TW_Util::invoice_mimes());
        nocache_headers();
        header('Content-Type: ' . ($type['type'] ?: 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . rawurlencode($w->invoice_original_name ?: basename($path)) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    public static function handle_run_now() {
        self::guard('tw_run_now');
        $lines = TW_Cron::run();
        self::flash($lines ? 'Ολοκληρώθηκε: ' . count($lines) . ' ενέργειες.<br>' . implode('<br>', array_map('esc_html', array_slice($lines, 0, 50))) : 'Ολοκληρώθηκε: τίποτα για αποστολή σήμερα.');
        self::back('tw-notifications');
    }

    public static function handle_test_message() {
        self::guard('tw_test_message');
        $key = sanitize_key(self::post('template'));
        $tab = sanitize_key(self::post('tab'));
        $fake = (object) [
            'customer_name' => 'Δοκιμαστικός Πελάτης', 'email' => sanitize_email(self::post('email')), 'serial' => 'TEST-000123',
            'product_name' => 'Δοκιμαστικό προϊόν', 'product_code' => 'TST-01', 'warranty_code' => 'TW-TEST-TEST-TEST',
            'purchase_date' => TW_Schedule::today(), 'warranty_end' => TW_Schedule::warranty_end(TW_Schedule::today()), 'partner_email' => 'partner@example.com',
        ];
        $vars = TW_Messenger::warranty_vars($fake, ['due_date' => TW_Util::date_label(TW_Schedule::add_days(TW_Schedule::today(), 30)), 'days' => 30, 'years' => 3,
            'milestone_no' => 1, 'grace_end_date' => '—', 'lock_date' => '—', 'code' => '123456', 'minutes' => TW_OTP::TTL_MINUTES]);
        $out = [];
        if (is_email($fake->email)) {
            $r = TW_Messenger::send_template_email($fake->email, $key, $vars);
            $out[] = 'Email: ' . ($r === true ? 'στάλθηκε' : 'αποτυχία – ' . $r);
        }
        $phone = self::post('phone');
        if ($phone !== '') {
            $t = TW_Settings::template($key);
            $r = TW_Messenger::send_sms($phone, TW_Messenger::render($t['sms'] ?: 'Δοκιμαστικό SMS', $vars));
            $out[] = 'SMS: ' . ($r === true ? 'στάλθηκε' : 'αποτυχία – ' . $r);
        }
        self::flash($out ? esc_html(implode(' | ', $out)) : 'Συμπληρώστε email ή κινητό.', $out ? 'info' : 'error');
        self::back('tw-settings', ['tab' => $tab]);
    }

    public static function handle_export() {
        global $wpdb;
        if (!current_user_can(self::cap())) wp_die('', 403);
        check_admin_referer('tw_export');
        $type = self::q('type');
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . ($type === 'serials' ? 'serials' : 'warranties') . '-' . current_time('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // Excel opens UTF-8 Greek correctly with a BOM
        if ($type === 'serials') {
            $t = TW_DB::table('serials');
            $where = ['1=1']; $p = [];
            if (self::q('s') !== '') { $l = '%' . $wpdb->esc_like(self::q('s')) . '%'; $where[] = '(serial_no LIKE %s OR product_name LIKE %s OR product_code LIKE %s)'; array_push($p, $l, $l, $l); }
            if (self::q('status') !== '') { $where[] = 'status = %s'; $p[] = self::q('status'); }
            if (self::q('batch') !== '') { $where[] = 'import_batch = %s'; $p[] = self::q('batch'); }
            $sql = "SELECT serial_no, product_code, product_name, status, import_batch, created_at FROM $t WHERE " . implode(' AND ', $where) . ' ORDER BY id';
            fputcsv($out, ['serial', 'product_code', 'product_name', 'status', 'import_batch', 'created_at'], ';');
            foreach ($wpdb->get_results($p ? $wpdb->prepare($sql, $p) : $sql, ARRAY_N) as $r) fputcsv($out, $r, ';');
        } else {
            $where = self::warranty_filters(['s' => self::q('s'), 'status' => self::q('status'), 'expiring' => (int) self::q('expiring'), 'from' => self::q('from'), 'to' => self::q('to')]);
            $cols = ['warranty_code', 'serial', 'product_code', 'product_name', 'status', 'customer_name', 'email', 'phone_mobile', 'phone_landline', 'address', 'postcode', 'city',
                'partner_vat', 'partner_email', 'invoice_number', 'purchase_date', 'warranty_end', 'marketing_opt_in', 'lock_reason', 'created_at'];
            fputcsv($out, $cols, ';');
            foreach ($wpdb->get_results(TW_Warranty::select_sql() . " WHERE $where ORDER BY w.id", ARRAY_A) as $r) {
                fputcsv($out, array_map(function ($c) use ($r) { return $r[$c] ?? ''; }, $cols), ';');
            }
            TW_DB::log_event('warranties_exported', ['filters' => array_filter($_GET)]);
        }
        fclose($out);
        exit;
    }
}
