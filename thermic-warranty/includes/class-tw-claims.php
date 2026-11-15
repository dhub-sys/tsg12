<?php
if (!defined('ABSPATH')) exit;

/**
 * Warranty claims: the customer asks, from «Οι εγγυήσεις μου», for the repair or the replacement of a product
 * that is under warranty; the team answers from the console (status + message to the customer).
 *
 * Statuses: new → in_progress → approved → completed, or rejected. One open claim per warranty at a time.
 */
final class TW_Claims {
    const MAX_FILES = 5;

    public static function table() {
        return TW_DB::table('claims');
    }

    /** Customer-facing labels (translated for English customers). */
    public static function types() {
        return ['repair' => tw__('Επισκευή'), 'replace' => tw__('Αντικατάσταση')];
    }

    public static function statuses() {
        return [
            'new' => tw__('Νέο – αναμένει έλεγχο'),
            'in_progress' => tw__('Σε εξέλιξη'),
            'approved' => tw__('Εγκρίθηκε'),
            'rejected' => tw__('Απορρίφθηκε'),
            'completed' => tw__('Ολοκληρώθηκε'),
        ];
    }

    public static function open_statuses() {
        return ['new', 'in_progress', 'approved'];
    }

    /** @return true|string why a claim cannot be made now */
    public static function can_claim($w) {
        if ($w->anonymized_at) return tw__('Η εγγύηση δεν βρέθηκε.');
        if ($w->status !== 'active' || $w->warranty_end < TW_Schedule::today()) {
            return tw__('Η εγγύηση αυτού του προϊόντος δεν είναι σε ισχύ, γι’ αυτό δεν μπορεί να υποβληθεί αίτημα εγγύησης. Για επισκευή εκτός εγγύησης επικοινωνήστε μαζί μας.');
        }
        if (self::open_for($w->id)) return tw__('Υπάρχει ήδη ανοιχτό αίτημα για αυτό το προϊόν. Θα ενημερωθείτε με email για την εξέλιξή του.');
        return true;
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id));
    }

    public static function for_warranty($wid) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE warranty_id = %d ORDER BY id DESC', (int) $wid));
    }

    public static function open_for($wid) {
        global $wpdb;
        $in = "'" . implode("','", self::open_statuses()) . "'";
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . " WHERE warranty_id = %d AND status IN ($in) ORDER BY id DESC LIMIT 1", (int) $wid));
    }

    public static function count_new() {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'new'");
    }

    /** Claims nobody in the team has opened yet (menu badge). */
    public static function count_unseen() {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table() . ' WHERE seen_at IS NULL');
    }

    /** Opening the warranty card marks its claims as read. */
    public static function mark_seen($wid) {
        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET seen_at = %s WHERE warranty_id = %d AND seen_at IS NULL', TW_DB::now(), (int) $wid));
    }

    public static function files($c) {
        $f = json_decode((string) $c->files, true);
        return is_array($f) ? $f : [];
    }

    /** $_FILES['x'] with several files → list of single-file arrays. */
    public static function uploaded_list($key) {
        if (empty($_FILES[$key]['name'])) return [];
        $f = $_FILES[$key];
        if (!is_array($f['name'])) return $f['name'] !== '' ? [$f] : [];
        $out = [];
        foreach ($f['name'] as $i => $name) {
            if ($name === '') continue;
            $out[] = ['name' => $name, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        }
        return $out;
    }

    // ------------------------------------------------------------------ customer side

    /** Creates the claim and sends the e-mails. @return object|WP_Error */
    public static function create($w, $type, $description, $phone, array $files) {
        global $wpdb;
        $now = TW_DB::now();
        $ok = $wpdb->insert(self::table(), [
            'warranty_id' => (int) $w->id,
            'type' => $type,
            'description' => $description,
            'contact_phone' => $phone,
            'files' => wp_json_encode($files),
            'status' => 'new',
            'lang' => TW_I18n::norm($w->lang ?? '') ?: 'el',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$ok) return new WP_Error('db', tw__('Σφάλμα αποθήκευσης. Δοκιμάστε ξανά.'));
        $c = self::get($wpdb->insert_id);
        TW_DB::log_event('claim_submitted', ['claim' => '#' . $c->id, 'type' => $type === 'replace' ? 'Αντικατάσταση' : 'Επισκευή', 'files' => count($files)], $w->id, $w->serial_id, 'customer:' . $w->email);

        // To the customer, in their language.
        TW_I18n::with($c->lang, function () use ($w, $c) {
            $s = TW_Settings::all();
            $types = self::types();
            $subject = sprintf(tw__('Λάβαμε το αίτημά σας #%1$d – S/N %2$s'), $c->id, $w->serial);
            $body = sprintf(tw__("Αγαπητέ/ή %1\$s,\n\nλάβαμε το αίτημά σας για %2\$s του προϊόντος σας (S/N %3\$s, κωδικός εγγύησης %4\$s).\n\nΑριθμός αιτήματος: #%5\$d\n\nΘα το εξετάσουμε και θα σας ενημερώσουμε με email. Μπορείτε να δείτε την εξέλιξή του στη σελίδα «Οι εγγυήσεις μου»: %6\$s\n\n%7\$s"),
                $w->customer_name, mb_strtolower($types[$c->type] ?? $c->type), $w->serial, $w->warranty_code, $c->id, TW_I18n::url(TW_Settings::get('portal_url') ?: TW_App::public_url('my')), $s['company_name']);
            TW_Messenger::send_email($w->email, $subject, $body, 'service');
        });
        // To the team.
        $link = TW_App::admin_url('warranties', ['view' => $w->id]) . '#tw-claims';
        foreach (TW_Messenger::admin_recipients() as $to) {
            TW_Messenger::send_email($to, sprintf('[Εγγυήσεις] Νέο αίτημα %s #%d – S/N %s', $type === 'replace' ? 'αντικατάστασης' : 'επισκευής', $c->id, $w->serial), sprintf(
                "Ο πελάτης %s ζήτησε %s για το προϊόν του.\n\nS/N: %s\nΚωδικός εγγύησης: %s\nΤηλέφωνο επικοινωνίας: %s\nΣυνημμένα: %d\n\nΠεριγραφή:\n%s\n\nΚαρτέλα εγγύησης: %s",
                $w->customer_name, $type === 'replace' ? 'αντικατάσταση' : 'επισκευή', $w->serial, $w->warranty_code, $phone ?: $w->phone_mobile, count($files), $description, $link));
        }
        do_action('tw_claim_submitted', $w, $c);
        return $c;
    }

    /** Claims block on a warranty card in «Οι εγγυήσεις μου». */
    public static function portal_block($w) {
        $claims = self::for_warranty($w->id);
        $can = self::can_claim($w);
        $labels = self::statuses();
        $types = self::types();
        $max_mb = (int) TW_Settings::get('invoice_max_mb');
        ob_start();
        ?>
        <section class="tw-claims">
            <h5><?php tw_e('Αίτημα επισκευής ή αντικατάστασης'); ?></h5>
            <?php foreach ($claims as $c) : ?>
                <div class="tw-claim tw-claim--<?php echo esc_attr($c->status); ?>">
                    <p><strong>#<?php echo (int) $c->id; ?> · <?php echo esc_html($types[$c->type] ?? $c->type); ?></strong> · <?php echo esc_html(TW_Util::date_label(substr($c->created_at, 0, 10))); ?>
                        <span class="tw-claim-state"><?php echo esc_html($labels[$c->status] ?? $c->status); ?></span></p>
                    <?php if ($c->reply) : ?><p class="tw-claim-reply"><strong><?php tw_e('Απάντηση:'); ?></strong> <?php echo nl2br(esc_html($c->reply)); ?></p><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($can === true) : ?>
                <details class="tw-details tw-claim-new"<?php echo $claims ? '' : ' open'; ?>>
                    <summary><?php tw_e('Νέο αίτημα εντός εγγύησης'); ?></summary>
                    <form class="tw-claim-form" data-warranty="<?php echo (int) $w->id; ?>" novalidate enctype="multipart/form-data">
                        <?php echo TW_Guard::fields(); // phpcs:ignore ?>
                        <fieldset class="tw-choice">
                            <legend><?php tw_e('Τι ζητάτε;'); ?> <span class="tw-req">*</span></legend>
                            <label><input type="radio" name="claim_type" value="repair" checked> <?php tw_e('Επισκευή'); ?></label>
                            <label><input type="radio" name="claim_type" value="replace"> <?php tw_e('Αντικατάσταση'); ?></label>
                        </fieldset>
                        <div class="tw-field">
                            <label><?php tw_e('Περιγραφή του προβλήματος'); ?> <span class="tw-req">*</span></label>
                            <textarea name="description" rows="4" maxlength="3000" required placeholder="<?php echo esc_attr(tw__('Τι συμβαίνει, από πότε, πού είναι εγκατεστημένο το προϊόν…')); ?>"></textarea>
                        </div>
                        <div class="tw-field">
                            <label><?php tw_e('Τηλέφωνο επικοινωνίας'); ?></label>
                            <input type="tel" name="contact_phone" maxlength="40" value="<?php echo esc_attr($w->phone_mobile); ?>">
                        </div>
                        <div class="tw-field">
                            <label><?php echo esc_html(sprintf(tw__('Φωτογραφίες ή έγγραφα (έως %1$d αρχεία, PDF, JPG ή PNG, έως %2$d MB το καθένα)'), self::MAX_FILES, $max_mb)); ?></label>
                            <input type="file" name="claim_files[]" multiple accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
                        </div>
                        <p class="tw-hint"><?php tw_e('Το αίτημα εξετάζεται σύμφωνα με τους όρους εγγύησης που αποδεχθήκατε. Θα σας ενημερώσουμε με email.'); ?></p>
                        <button type="submit" class="tw-btn"><?php tw_e('Υποβολή αιτήματος'); ?></button>
                    </form>
                </details>
            <?php elseif (!$claims || !self::open_for($w->id)) : ?>
                <p class="tw-hint"><?php echo esc_html($can); ?></p>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    // ------------------------------------------------------------------ console

    public static function admin_labels() {
        return ['new' => 'Νέο', 'in_progress' => 'Σε εξέλιξη', 'approved' => 'Εγκρίθηκε', 'rejected' => 'Απορρίφθηκε', 'completed' => 'Ολοκληρώθηκε'];
    }

    private static function badge($status) {
        $map = ['new' => 'registered', 'in_progress' => 'registered', 'approved' => 'active', 'completed' => 'active', 'rejected' => 'void'];
        return '<span class="tw-badge tw-badge--' . esc_attr($map[$status] ?? 'void') . '">' . esc_html(self::admin_labels()[$status] ?? $status) . '</span>';
    }

    private static function file_url($c, $i) {
        return wp_nonce_url(add_query_arg(['action' => 'tw_claim_file', 'id' => (int) $c->id, 'i' => (int) $i], TW_Admin::url()), 'tw_claim_file');
    }

    /** Screen «Αιτήματα εγγύησης». */
    public static function page() {
        global $wpdb;
        $status = TW_Admin::q('status');
        $where = $status !== '' ? $wpdb->prepare('WHERE c.status = %s', $status) : (TW_Admin::q('all') ? '' : "WHERE c.status IN ('new','in_progress','approved')");
        $paged = max(1, (int) TW_Admin::q('paged'));
        $w = TW_DB::table('warranties');
        $s = TW_DB::table('serials');
        $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table() . " c $where");
        $rows = $wpdb->get_results('SELECT c.*, w.warranty_code, w.customer_name, w.email, s.serial_no AS serial, s.product_name FROM ' . self::table() . " c JOIN $w w ON w.id = c.warranty_id LEFT JOIN $s s ON s.id = w.serial_id $where ORDER BY c.status = 'new' DESC, c.id DESC LIMIT " . TW_Admin::PER_PAGE . ' OFFSET ' . (($paged - 1) * TW_Admin::PER_PAGE));
        $counts = $wpdb->get_results('SELECT status, COUNT(*) n FROM ' . self::table() . ' GROUP BY status', OBJECT_K);
        ?>
        <div class="wrap tw-wrap">
            <h1 class="wp-heading-inline">Αιτήματα εγγύησης</h1>
            <?php TW_Admin::notice(); ?>
            <p class="description">Αιτήματα επισκευής ή αντικατάστασης που υπέβαλαν πελάτες από τη σελίδα «Οι εγγυήσεις μου», για προϊόντα με εγγύηση σε ισχύ. Ανοίξτε την καρτέλα της εγγύησης για να δείτε τα συνημμένα και να απαντήσετε.</p>
            <ul class="subsubsub">
                <li><a href="<?php echo esc_url(TW_Admin::url('claims')); ?>"<?php echo $status === '' && !TW_Admin::q('all') ? ' class="current"' : ''; ?>>Ανοιχτά</a> |</li>
                <?php foreach (self::admin_labels() as $k => $l) : ?>
                    <li><a href="<?php echo esc_url(TW_Admin::url('claims', ['status' => $k])); ?>"<?php echo $status === $k ? ' class="current"' : ''; ?>><?php echo esc_html($l); ?> <span class="count">(<?php echo (int) ($counts[$k]->n ?? 0); ?>)</span></a> |</li>
                <?php endforeach; ?>
                <li><a href="<?php echo esc_url(TW_Admin::url('claims', ['all' => 1])); ?>"<?php echo TW_Admin::q('all') ? ' class="current"' : ''; ?>>Όλα</a></li>
            </ul>
            <?php TW_Admin::pagination($total, $paged); ?>
            <table class="widefat striped"><thead><tr><th>#</th><th>Ημ/νία</th><th>Αίτημα</th><th>Κατάσταση</th><th>Πελάτης</th><th>Προϊόν / S/N</th><th>Περιγραφή</th><th></th></tr></thead><tbody>
            <?php if (!$rows) echo '<tr><td colspan="8">Δεν υπάρχουν αιτήματα.</td></tr>'; ?>
            <?php foreach ($rows as $c) : ?>
                <tr<?php echo $c->seen_at ? '' : ' class="tw-unread"'; ?>>
                    <td>#<?php echo (int) $c->id; ?><?php echo $c->seen_at ? '' : ' <span class="tw-new-dot" title="Δεν το έχει ανοίξει κανείς ακόμη">νέο</span>'; ?></td>
                    <td><?php echo esc_html(TW_Util::datetime_label($c->created_at)); ?></td>
                    <td><?php echo $c->type === 'replace' ? 'Αντικατάσταση' : 'Επισκευή'; ?><?php echo self::files($c) ? ' <span class="description">· ' . count(self::files($c)) . ' συνημμένα</span>' : ''; ?></td>
                    <td><?php echo self::badge($c->status); // phpcs:ignore ?></td>
                    <td><?php echo esc_html($c->customer_name); ?><br><small><?php echo esc_html($c->email); ?></small></td>
                    <td><?php echo esc_html($c->product_name); ?><br><code><?php echo esc_html($c->serial); ?></code></td>
                    <td><?php echo esc_html(wp_trim_words($c->description, 18)); ?></td>
                    <td><a class="button" href="<?php echo esc_url(TW_Admin::url('warranties', ['view' => $c->warranty_id]) . '#tw-claims'); ?>">Άνοιγμα</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php TW_Admin::pagination($total, $paged); ?>
        </div>
        <?php
    }

    /** Section on the warranty card in the console. */
    public static function admin_section($w) {
        $claims = self::for_warranty($w->id);
        self::mark_seen($w->id);
        $post = TW_Admin::url();
        ?>
        <h2 id="tw-claims">Αιτήματα επισκευής / αντικατάστασης</h2>
        <?php if (!$claims) : ?>
            <p class="description">Ο πελάτης δεν έχει υποβάλει αίτημα. <?php echo self::can_claim($w) === true ? 'Μπορεί να υποβάλει από τη σελίδα «Οι εγγυήσεις μου».' : ''; ?></p>
        <?php endif; ?>
        <?php foreach ($claims as $c) : $files = self::files($c); ?>
            <div class="tw-panel tw-claim-admin">
                <h3>#<?php echo (int) $c->id; ?> – <?php echo $c->type === 'replace' ? 'Αντικατάσταση' : 'Επισκευή'; ?> <?php echo self::badge($c->status); // phpcs:ignore ?></h3>
                <p class="description">Υποβλήθηκε <?php echo esc_html(TW_Util::datetime_label($c->created_at)); ?><?php echo $c->updated_at !== $c->created_at ? ' · τελευταία αλλαγή ' . esc_html(TW_Util::datetime_label($c->updated_at)) : ''; ?> · τηλέφωνο επικοινωνίας: <?php echo esc_html($c->contact_phone ?: $w->phone_mobile); ?> · γλώσσα πελάτη: <?php echo $c->lang === 'en' ? 'English' : 'Ελληνικά'; ?></p>
                <blockquote class="tw-claim-desc"><?php echo nl2br(esc_html($c->description)); ?></blockquote>
                <?php if ($files) : ?><p><strong>Συνημμένα:</strong> <?php foreach ($files as $i => $f) printf('<a href="%s" target="_blank">%s</a> &nbsp; ', esc_url(self::file_url($c, $i)), esc_html($f['name'])); ?></p><?php endif; ?>
                <?php if ($c->reply) : ?><p><strong>Τελευταία απάντηση στον πελάτη:</strong> <?php echo nl2br(esc_html($c->reply)); ?></p><?php endif; ?>
                <?php if ($c->admin_note) : ?><p><strong>Εσωτερική σημείωση:</strong> <?php echo nl2br(esc_html($c->admin_note)); ?></p><?php endif; ?>
                <form method="post" action="<?php echo esc_url($post); ?>">
                    <?php wp_nonce_field('tw_claim_update'); ?><input type="hidden" name="action" value="tw_claim_update"><input type="hidden" name="id" value="<?php echo (int) $c->id; ?>">
                    <p><label>Κατάσταση <select name="status"><?php foreach (self::admin_labels() as $k => $l) printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($c->status, $k, false), esc_html($l)); ?></select></label></p>
                    <p><label>Μήνυμα προς τον πελάτη <span class="description">(στέλνεται με email και φαίνεται στη σελίδα «Οι εγγυήσεις μου»)</span><textarea name="reply" rows="3" class="large-text" placeholder="π.χ. Θα σας επισκεφθεί τεχνικός την Τρίτη 10:00–12:00."></textarea></label></p>
                    <p><label>Εσωτερική σημείωση <span class="description">(μόνο για την ομάδα)</span><textarea name="admin_note" rows="2" class="large-text"><?php echo esc_textarea($c->admin_note); ?></textarea></label></p>
                    <p><label><input type="checkbox" name="notify" value="1" checked> Ενημέρωση πελάτη με email για την αλλαγή</label></p>
                    <p><button class="button button-primary">Αποθήκευση</button></p>
                </form>
            </div>
        <?php endforeach;
    }

    public static function handle_update() {
        global $wpdb;
        TW_Admin::guard('tw_claim_update');
        $c = self::get((int) TW_Admin::post('id'));
        if (!$c) TW_Admin::back('tw-claims');
        $w = TW_Warranty::get($c->warranty_id);
        $status = TW_Admin::post('status');
        if (!isset(self::admin_labels()[$status])) $status = $c->status;
        $reply = sanitize_textarea_field(TW_Admin::post('reply'));
        $note = sanitize_textarea_field(TW_Admin::post('admin_note'));
        $changed = $status !== $c->status;
        $data = ['status' => $status, 'admin_note' => $note, 'updated_at' => TW_DB::now()];
        if ($reply !== '') $data['reply'] = $reply;
        if ($changed && in_array($status, ['completed', 'rejected'], true)) $data['closed_at'] = TW_DB::now();
        $wpdb->update(self::table(), $data, ['id' => $c->id]);
        TW_DB::log_event('claim_updated', ['claim' => '#' . $c->id, 'status' => self::admin_labels()[$status], 'message' => $reply !== '' ? 'ναι' : 'όχι'], $c->warranty_id, $w ? $w->serial_id : 0);
        $msg = 'Το αίτημα #' . (int) $c->id . ' αποθηκεύτηκε.';
        if ($w && !$w->anonymized_at && TW_Admin::post('notify') === '1' && ($changed || $reply !== '')) {
            $c = self::get($c->id);
            $res = TW_I18n::with($c->lang, function () use ($w, $c, $reply, $changed) {
                $s = TW_Settings::all();
                $subject = sprintf(tw__('Ενημέρωση για το αίτημά σας #%1$d – S/N %2$s'), $c->id, $w->serial);
                $body = sprintf(tw__("Αγαπητέ/ή %1\$s,\n\nτο αίτημά σας #%2\$d για το προϊόν με S/N %3\$s: %4\$s."), $w->customer_name, $c->id, $w->serial, self::statuses()[$c->status]);
                if ($reply !== '') $body .= "\n\n" . $reply;
                $body .= "\n\n" . sprintf(tw__('Οι εγγυήσεις μου: %s'), TW_I18n::url(TW_Settings::get('portal_url') ?: TW_App::public_url('my'))) . "\n\n" . $s['company_name'];
                return TW_Messenger::send_email($w->email, $subject, $body, 'service');
            });
            $msg .= $res === true ? ' Ο πελάτης ενημερώθηκε με email.' : ' Το email προς τον πελάτη δεν στάλθηκε: ' . esc_html($res);
        }
        TW_Admin::flash($msg, isset($res) && $res !== true ? 'warning' : 'success');
        wp_safe_redirect(TW_Admin::url('warranties', ['view' => $c->warranty_id]) . '#tw-claims');
        exit;
    }

    public static function handle_file() {
        if (!current_user_can(TW_Admin::cap())) wp_die('', 403);
        check_admin_referer('tw_claim_file');
        $c = self::get((int) ($_GET['id'] ?? 0));
        $files = $c ? self::files($c) : [];
        $f = $files[(int) ($_GET['i'] ?? -1)] ?? null;
        $path = $f ? TW_Util::invoice_path($f['file']) : '';
        if (!$path) wp_die('Το αρχείο δεν βρέθηκε.');
        TW_DB::log_event('claim_file_downloaded', ['claim' => '#' . $c->id, 'file' => $f['name']], $c->warranty_id);
        $type = wp_check_filetype($path, TW_Util::invoice_mimes());
        nocache_headers();
        header('Content-Type: ' . ($type['type'] ?: 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . rawurlencode($f['name'] ?: basename($path)) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    // ------------------------------------------------------------------ GDPR

    public static function export_rows($wid) {
        return array_map(function ($c) {
            return ['claim' => (int) $c->id, 'type' => $c->type, 'description' => $c->description, 'contact_phone' => $c->contact_phone,
                'files' => count(self::files($c)), 'status' => $c->status, 'reply' => $c->reply, 'submitted_at' => $c->created_at];
        }, self::for_warranty($wid));
    }

    /** Anonymisation: attachments removed, description and phone erased; type/status/dates stay. */
    public static function anonymize($wid) {
        global $wpdb;
        foreach (self::for_warranty($wid) as $c) {
            foreach (self::files($c) as $f) {
                if ($p = TW_Util::invoice_path($f['file'])) @unlink($p);
            }
        }
        $wpdb->update(self::table(), ['description' => '[ανωνυμοποιήθηκε]', 'contact_phone' => '', 'files' => '[]'], ['warranty_id' => (int) $wid]);
    }
}
