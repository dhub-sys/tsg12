<?php
if (!defined('ABSPATH')) exit;

/**
 * Company back office: dashboard, warranties, serials (single + bulk import), notifications log, settings.
 */
final class TW_Admin {
    const PER_PAGE = 50;

    public static function cap() {
        return apply_filters('tw_capability', 'manage_options');
    }

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', function ($hook) {
            if (strpos((string) $hook, 'tw-') !== false) {
                wp_enqueue_style('tw-admin', TW_URL . 'assets/css/admin.css', [], TW_VERSION);
            }
        });
        $actions = ['import', 'add_serial', 'serial_status', 'record_service', 'delete_service', 'set_status', 'invoice', 'export',
            'save_settings', 'run_now', 'resend_activation', 'anonymize', 'test_message', 'terms_view'];
        foreach ($actions as $a) {
            add_action('admin_post_tw_' . $a, [__CLASS__, 'handle_' . $a]);
        }
    }

    public static function menu() {
        $cap = self::cap();
        add_menu_page('Εγγυήσεις', 'Εγγυήσεις', $cap, 'tw-dashboard', [__CLASS__, 'page_dashboard'], 'dashicons-shield', 56);
        add_submenu_page('tw-dashboard', 'Επισκόπηση', 'Επισκόπηση', $cap, 'tw-dashboard', [__CLASS__, 'page_dashboard']);
        add_submenu_page('tw-dashboard', 'Εγγυήσεις & πελάτες', 'Εγγυήσεις & πελάτες', $cap, 'tw-warranties', [__CLASS__, 'page_warranties']);
        add_submenu_page('tw-dashboard', 'Σειριακοί αριθμοί', 'Σειριακοί αριθμοί', $cap, 'tw-serials', [__CLASS__, 'page_serials']);
        add_submenu_page('tw-dashboard', 'Ειδοποιήσεις', 'Ειδοποιήσεις', $cap, 'tw-notifications', [__CLASS__, 'page_notifications']);
        add_submenu_page('tw-dashboard', 'Ρυθμίσεις', 'Ρυθμίσεις', $cap, 'tw-settings', [__CLASS__, 'page_settings']);
    }

    // ------------------------------------------------------------------ helpers

    private static function guard($action) {
        if (!current_user_can(self::cap())) wp_die('Δεν έχετε δικαίωμα πρόσβασης.', 403);
        check_admin_referer($action);
    }

    private static function back($page, array $args = []) {
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=' . $page)));
        exit;
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

    private static function warranty_link($id, $text) {
        return '<a href="' . esc_url(admin_url('admin.php?page=tw-warranties&view=' . (int) $id)) . '">' . esc_html($text) . '</a>';
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
                if ($m['type'] === 'service' && in_array($m['state'], ['upcoming', 'due'], true) && $m['due'] <= $horizon) {
                    $upcoming[] = ['w' => $row, 'm' => $m];
                }
            }
        }
        usort($upcoming, function ($a, $b) { return strcmp($a['m']['due'], $b['m']['due']); });
        $recent = $wpdb->get_results(TW_Warranty::select_sql() . " ORDER BY w.created_at DESC LIMIT 10");
        $last = get_option('tw_last_run');
        $labels = TW_Util::status_labels();
        ?>
        <div class="wrap tw-wrap">
            <h1>Εγγυήσεις – Επισκόπηση</h1>
            <?php self::notice(); ?>
            <div class="tw-cards">
                <div class="tw-stat"><span><?php echo $n($serial_counts, 'available') + $n($serial_counts, 'registered') + $n($serial_counts, 'void'); ?></span>Σειριακοί στο σύστημα</div>
                <div class="tw-stat"><span><?php echo $n($serial_counts, 'available'); ?></span>Διαθέσιμοι (μη ενεργοποιημένοι)</div>
                <div class="tw-stat tw-stat--ok"><span><?php echo $n($w_counts, 'active'); ?></span>Ενεργές εγγυήσεις</div>
                <div class="tw-stat"><span><?php echo $n($w_counts, 'expired'); ?></span>Έληξαν</div>
                <div class="tw-stat tw-stat--bad"><span><?php echo $n($w_counts, 'locked'); ?></span>Οριστικά κλειδωμένες</div>
                <div class="tw-stat tw-stat--warn"><span><?php echo $exp60; ?></span>Λήγουν σε 60 ημέρες</div>
                <div class="tw-stat"><span><?php echo $last30; ?></span>Ενεργοποιήσεις 30 ημερών</div>
            </div>
            <p class="description">Τελευταία εκτέλεση ειδοποιήσεων: <?php echo $last ? esc_html(TW_Util::datetime_label($last['at']) . ' – ' . (int) $last['count'] . ' ενέργειες') : 'καμία ακόμη'; ?>.
                Επόμενη: <?php $nx = wp_next_scheduled(TW_Cron::HOOK); echo $nx ? esc_html(wp_date('d/m/Y H:i', $nx)) : '—'; ?>.</p>

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
        $export = wp_nonce_url(add_query_arg(array_merge(['action' => 'tw_export', 'type' => 'warranties'], array_filter($args)), admin_url('admin-post.php')), 'tw_export');
        ?>
        <div class="wrap tw-wrap">
            <h1 class="wp-heading-inline">Εγγυήσεις &amp; πελάτες</h1>
            <a class="page-title-action" href="<?php echo esc_url($export); ?>">Εξαγωγή CSV (με τα τρέχοντα φίλτρα)</a>
            <?php self::notice(); ?>
            <form method="get" class="tw-filters">
                <input type="hidden" name="page" value="tw-warranties">
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
        $post = admin_url('admin-post.php');
        $ms_labels = ['done' => 'Πραγματοποιήθηκε', 'due' => 'Εκκρεμεί', 'missed' => 'Δεν πραγματοποιήθηκε', 'upcoming' => 'Προσεχώς', 'before_registration' => 'Πριν από την ενεργοποίηση', 'expired' => 'Έληξε'];
        $consent_labels = ['terms' => 'Όροι εγγύησης', 'data_accuracy' => 'Ορθότητα στοιχείων', 'marketing' => 'Εμπορικές ενημερώσεις'];
        ?>
        <div class="wrap tw-wrap">
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=tw-warranties')); ?>">&larr; Όλες οι εγγυήσεις</a></p>
            <h1>Εγγύηση <?php echo esc_html($w->warranty_code); ?> <?php echo self::status_badge($w->status, $labels); ?></h1>
            <?php self::notice(); ?>
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
                        <?php echo esc_html(TW_Util::date_label($sv->performed_date)); ?> – <?php echo $sv->performed_by === 'thermic' ? 'Thermic' : 'Συνεργάτης'; ?>
                        <?php echo $sv->technician ? '(' . esc_html($sv->technician) . ')' : ''; ?>
                        – γνήσια ανταλλακτικά: <?php echo $sv->genuine_parts ? 'ναι' : '<strong>όχι</strong>'; ?>
                        <?php echo $sv->notes ? '<br><small>' . esc_html($sv->notes) . '</small>' : ''; ?>
                    <?php endif; ?></td>
                    <td><?php if ($m['service']) : ?>
                        <form method="post" action="<?php echo esc_url($post); ?>" onsubmit="return confirm('Διαγραφή της καταχώρησης Service;')">
                            <?php wp_nonce_field('tw_delete_service'); ?><input type="hidden" name="action" value="tw_delete_service"><input type="hidden" name="id" value="<?php echo (int) $w->id; ?>"><input type="hidden" name="milestone_no" value="<?php echo (int) $m['no']; ?>">
                            <button class="button-link tw-danger">Διαγραφή</button></form>
                    <?php endif; ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>

            <div class="tw-columns">
                <div class="tw-panel">
                    <h2>Καταχώρηση Service</h2>
                    <form method="post" action="<?php echo esc_url($post); ?>">
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
        $st = ['sent' => 'Στάλθηκε', 'failed' => 'Απέτυχε', 'pending' => 'Σε εξέλιξη', 'skipped' => 'Παραλείφθηκε'];
        echo '<table class="widefat striped"><thead><tr><th>Ημ/νία</th>' . ($with_warranty ? '<th>Εγγύηση</th>' : '') . '<th>Βήμα</th><th>Κανάλι</th><th>Παραλήπτης</th><th>Αποτέλεσμα</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="6">Καμία.</td></tr>';
        foreach ($rows as $n) {
            $m = $n->milestone_key === 'expiry' ? ['type' => 'expiry', 'no' => 0] : ['type' => 'service', 'no' => (int) str_replace('service_', '', $n->milestone_key)];
            echo '<tr><td>' . esc_html(TW_Util::datetime_label($n->created_at)) . '</td>';
            if ($with_warranty) echo '<td>' . self::warranty_link($n->warranty_id, $n->serial ?? ('#' . $n->warranty_id)) . '</td>';
            echo '<td>' . esc_html(TW_Cron::step_label($m, (int) $n->offset_days)) . '</td><td>' . esc_html($ch[$n->channel] ?? $n->channel) . '</td><td>' . esc_html($n->recipient) . '</td>';
            echo '<td>' . esc_html($st[$n->status] ?? $n->status) . ($n->error ? '<br><small class="tw-danger">' . esc_html($n->error) . '</small>' : '') . '</td></tr>';
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
        $post = admin_url('admin-post.php');
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
                <input type="hidden" name="page" value="tw-serials">
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
                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=tw-serials&batch=' . rawurlencode($r->import_batch))); ?>"><?php echo esc_html($r->import_batch); ?></a></td>
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
        $where = $status ? $wpdb->prepare('WHERE n.status = %s', $status) : '';
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $n n $where");
        $rows = $wpdb->get_results("SELECT n.*, s.serial_no AS serial FROM $n n LEFT JOIN " . TW_DB::table('warranties') . " w ON w.id = n.warranty_id LEFT JOIN " . TW_DB::table('serials') . " s ON s.id = w.serial_id $where ORDER BY n.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($paged - 1) * self::PER_PAGE));
        ?>
        <div class="wrap tw-wrap">
            <h1>Ειδοποιήσεις</h1>
            <?php self::notice(); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tw-inline-form">
                <?php wp_nonce_field('tw_run_now'); ?><input type="hidden" name="action" value="tw_run_now">
                <button class="button button-primary">Εκτέλεση ελέγχου ειδοποιήσεων τώρα</button>
                <span class="description">Η εκτέλεση γίνεται αυτόματα κάθε μέρα. Δεν στέλνει ποτέ το ίδιο μήνυμα δύο φορές.</span>
            </form>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=tw-notifications')); ?>">Όλες</a> | <a href="<?php echo esc_url(admin_url('admin.php?page=tw-notifications&status=failed')); ?>">Αποτυχημένες</a></p>
            <?php self::pagination($total, $paged); self::notifications_table($rows, true); self::pagination($total, $paged); ?>
        </div>
        <?php
    }

    // ------------------------------------------------------------------ settings

    public static function page_settings() {
        $s = TW_Settings::all();
        $terms = TW_Settings::current_terms();
        $tpl = TW_Settings::template_defaults();
        $post = admin_url('admin-post.php');
        $tab = self::q('tab') ?: 'general';
        $tabs = ['general' => 'Γενικά', 'schedule' => 'Εγγύηση & ειδοποιήσεις', 'terms' => 'Όροι & συγκαταθέσεις', 'messages' => 'Κείμενα μηνυμάτων', 'sms' => 'SMS', 'privacy' => 'GDPR'];
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
                <?php foreach ($tabs as $k => $l) printf('<a class="nav-tab%s" href="%s">%s</a>', $tab === $k ? ' nav-tab-active' : '', esc_url(admin_url('admin.php?page=tw-settings&tab=' . $k)), esc_html($l)); ?>
            </nav>
            <form method="post" action="<?php echo esc_url($post); ?>">
                <?php wp_nonce_field('tw_save_settings'); ?>
                <input type="hidden" name="action" value="tw_save_settings"><input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
                <table class="form-table">
                <?php if ($tab === 'general') :
                    $field('company_name', 'Επωνυμία');
                    $field('company_contact', 'Στοιχεία επικοινωνίας για Service', 'text', 'Εμφανίζεται στα μηνύματα: «…ή απευθείας στη Thermic: <em>αυτό το κείμενο</em>».');
                    $field('register_url', 'Σελίδα ενεργοποίησης', 'url', 'Η σελίδα με το shortcode <code>[thermic_warranty_register]</code>.');
                    $field('portal_url', 'Σελίδα «Οι εγγυήσεις μου»', 'url', 'Η σελίδα με το shortcode <code>[thermic_warranty_portal]</code>. Μπαίνει στα email.');
                    $field('email_from_name', 'Όνομα αποστολέα email');
                    $field('email_from_address', 'Email αποστολέα', 'email', 'Προτείνεται SMTP plugin (π.χ. WP Mail SMTP) με SPF/DKIM για τον τομέα, ώστε τα μηνύματα να μην πηγαίνουν στα ανεπιθύμητα.');
                    $field('admin_emails', 'Email εταιρείας για ειδοποιήσεις', 'text', 'Ένα ή περισσότερα, χωρισμένα με κόμμα.');
                    $field('admin_digest', 'Ημερήσια σύνοψη', 'checkbox', 'Αποστολή σύνοψης στην εταιρεία κάθε μέρα που υπάρχουν ειδοποιήσεις, λήξεις ή κλειδώματα.');
                    $field('invoice_required', 'Αρχείο παραστατικού', 'checkbox', 'Υποχρεωτική επισύναψη αρχείου (PDF/JPG/PNG).');
                    $field('invoice_max_mb', 'Μέγιστο μέγεθος αρχείου (MB)', 'number', '', 'min="1" max="50"');
                elseif ($tab === 'schedule') :
                    $field('warranty_years', 'Διάρκεια εγγύησης (έτη)', 'number', 'Από την ημερομηνία αγοράς.', 'min="1" max="50"');
                    $field('service_interval_years', 'Service ανά (έτη)', 'number', '0 = χωρίς περιοδικό Service. Με 12 έτη και 3: Service στα 3, 6 και 9 έτη, λήξη στα 12.', 'min="0" max="50"');
                    $field('reminder_offsets', 'Ημέρες ειδοποιήσεων', 'text', 'Σε σχέση με κάθε ημερομηνία (Service ή λήξη), χωρισμένες με κόμμα. Αρνητικές = πριν. Προεπιλογή: <code>-60,-30,0,30</code>.');
                    $field('lock_offset', 'Οριστικό κλείδωμα (ημέρες μετά)', 'number', 'Προεπιλογή 45, δηλαδή 15 ημέρες μετά το τέλος της περιόδου των 30 ημερών.', 'min="0" max="365"');
                    $field('lock_on_missed_service', 'Κλείδωμα για Service', 'checkbox', 'Κλείδωμα της εγγύησης αν δεν καταγραφεί Service έως την ημερομηνία κλειδώματος.');
                    $field('lock_on_expiry', 'Κλείδωμα μετά τη λήξη', 'checkbox', 'Οριστικό κλείδωμα μετά τη λήξη των 12 ετών.');
                    $field('notify_partner', 'Κοινοποίηση στον συνεργάτη', 'checkbox', 'Αποστολή αντιγράφου των υπενθυμίσεων Service και στο email του συνεργάτη.');
                    $field('catchup_days', 'Καθυστερημένη αποστολή (ημέρες)', 'number', 'Αν η αυτόματη εκτέλεση χάσει μέρες (π.χ. το site ήταν εκτός λειτουργίας), μια υπενθύμιση στέλνεται έως τόσες ημέρες αργότερα. Παλαιότερες παραλείπονται.', 'min="0" max="60"');
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
                elseif ($tab === 'sms') :
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
                    <tr><th>Αιτήματα πελατών</th><td><p class="description">Τα αιτήματα εξαγωγής / διαγραφής από την πύλη εμφανίζονται στα Εργαλεία → <a href="<?php echo esc_url(admin_url('erase-personal-data.php')); ?>">Διαγραφή προσωπικών δεδομένων</a> και <a href="<?php echo esc_url(admin_url('export-personal-data.php')); ?>">Εξαγωγή</a>. Ενεργές εγγυήσεις διατηρούνται (ανάκληση εμπορικής συγκατάθεσης)· όσες έχουν λήξει ή κλειδωθεί ανωνυμοποιούνται.</p></td></tr>
                <?php endif; ?>
                </table>
                <?php submit_button('Αποθήκευση'); ?>
            </form>
            <?php if (in_array($tab, ['messages', 'sms', 'general'], true)) : ?>
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

    // ------------------------------------------------------------------ handlers

    public static function handle_save_settings() {
        self::guard('tw_save_settings');
        $tab = sanitize_key(self::post('tab'));
        $in = isset($_POST['s']) && is_array($_POST['s']) ? wp_unslash($_POST['s']) : [];
        $defaults = TW_Settings::defaults();
        $checkboxes = [
            'general' => ['admin_digest', 'invoice_required'],
            'schedule' => ['lock_on_missed_service', 'lock_on_expiry', 'notify_partner'],
            'sms' => ['sms_enabled'],
        ];
        $numbers = ['warranty_years', 'service_interval_years', 'lock_offset', 'catchup_days', 'max_registration_delay_days', 'invoice_max_mb', 'retention_months_after_end'];
        $multiline = ['sms_headers', 'sms_body', 'terms_label', 'accuracy_label', 'marketing_label'];
        $urls = ['portal_url', 'register_url', 'privacy_url', 'sms_url'];
        $values = [];
        foreach ($in as $k => $v) {
            if (!array_key_exists($k, $defaults) || $k === 'templates') continue;
            if (in_array($k, $numbers, true)) $values[$k] = max(0, (int) $v);
            elseif (in_array($k, $urls, true)) $values[$k] = esc_url_raw(trim($v));
            elseif (in_array($k, $multiline, true)) $values[$k] = sanitize_textarea_field($v);
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
        if (!$valid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            self::flash('Μη έγκυρα στοιχεία Service.', 'error');
        } else {
            TW_Warranty::record_service($w, $no, $date, self::post('performed_by') === 'thermic' ? 'thermic' : 'partner',
                sanitize_text_field(self::post('technician')), !empty($_POST['genuine_parts']), sanitize_textarea_field(self::post('notes')));
            self::flash(sprintf('Καταχωρήθηκε το %dο Service.', $no));
        }
        self::back('tw-warranties', ['view' => $w->id]);
    }

    public static function handle_delete_service() {
        global $wpdb;
        self::guard('tw_delete_service');
        $w = self::warranty_from_post();
        $no = (int) self::post('milestone_no');
        $wpdb->delete(TW_DB::table('services'), ['warranty_id' => $w->id, 'milestone_no' => $no]);
        TW_DB::log_event('service_deleted', ['milestone' => $no], $w->id, $w->serial_id);
        self::flash('Η καταχώρηση Service διαγράφηκε.');
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
