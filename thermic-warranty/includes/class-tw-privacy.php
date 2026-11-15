<?php
if (!defined('ABSPATH')) exit;

/**
 * GDPR: hooks into WordPress' own privacy tools (Tools → Export/Erase Personal Data),
 * and gives the portal a self-service export.
 *
 * Erasure policy: warranties that are still active are retained (needed to honour the warranty
 * contract – Art. 6(1)(b) / 17(3)(e)); the request withdraws marketing consent. Expired, locked
 * or void warranties are anonymised: personal fields and the invoice file are removed, the
 * product/warranty history stays for the company.
 */
final class TW_Privacy {

    public static function init() {
        add_filter('wp_privacy_personal_data_exporters', function ($e) {
            $e['thermic-warranty'] = ['exporter_friendly_name' => 'Εγγυήσεις προϊόντων', 'callback' => [__CLASS__, 'exporter']];
            return $e;
        });
        add_filter('wp_privacy_personal_data_erasers', function ($e) {
            $e['thermic-warranty'] = ['eraser_friendly_name' => 'Εγγυήσεις προϊόντων', 'callback' => [__CLASS__, 'eraser']];
            return $e;
        });
        add_action('admin_init', [__CLASS__, 'policy_text']);
    }

    public static function customer_data($email) {
        global $wpdb;
        $out = [];
        foreach (TW_Warranty::by_email($email) as $w) {
            $consents = $wpdb->get_results($wpdb->prepare(
                "SELECT consent_type, granted, terms_version, label_text, source, ip, created_at FROM " . TW_DB::table('consents') . " WHERE warranty_id = %d ORDER BY id", $w->id
            ), ARRAY_A);
            $out[] = [
                'warranty_code' => $w->warranty_code,
                'serial' => $w->serial,
                'product' => $w->product_name,
                'status' => $w->status,
                'customer_name' => $w->customer_name,
                'email' => $w->email,
                'phone_mobile' => $w->phone_mobile,
                'phone_landline' => $w->phone_landline,
                'address' => $w->address,
                'postcode' => $w->postcode,
                'city' => $w->city,
                'partner_vat' => $w->partner_vat,
                'partner_country' => $w->partner_country ?: 'GR',
                'partner_email' => $w->partner_email,
                'invoice_number' => $w->invoice_number,
                'invoice_file_uploaded' => $w->invoice_file ? 'yes' : 'no',
                'purchase_date' => $w->purchase_date,
                'warranty_end' => $w->warranty_end,
                'marketing_opt_in' => (int) $w->marketing_opt_in,
                'registered_at' => $w->created_at,
                'consents' => $consents,
                'claims' => TW_Claims::export_rows($w->id),
                'services' => array_values(array_map(function ($s) {
                    return ['milestone' => (int) $s->milestone_no, 'date' => $s->performed_date, 'by' => $s->performed_by,
                        'technician_vat' => $s->technician_vat, 'receipt_number' => $s->receipt_number, 'receipt_date' => $s->receipt_date, 'receipt_uploaded' => $s->receipt_file ? 'yes' : 'no', 'review_status' => $s->review_status];
                }, TW_Warranty::services($w->id))),
            ];
        }
        return $out;
    }

    public static function exporter($email, $page = 1) {
        $items = [];
        foreach (self::customer_data(strtolower($email)) as $w) {
            $data = [];
            foreach ($w as $k => $v) {
                if (is_array($v)) $v = wp_json_encode($v, JSON_UNESCAPED_UNICODE);
                $data[] = ['name' => $k, 'value' => (string) $v];
            }
            $items[] = ['group_id' => 'thermic-warranty', 'group_label' => 'Εγγυήσεις προϊόντων', 'item_id' => 'warranty-' . $w['warranty_code'], 'data' => $data];
        }
        return ['data' => $items, 'done' => true];
    }

    public static function eraser($email, $page = 1) {
        $removed = false; $retained = false; $messages = [];
        foreach (TW_Warranty::by_email(strtolower($email)) as $w) {
            if ($w->marketing_opt_in) TW_Warranty::set_marketing($w, false, 'erasure_request');
            if ($w->status === 'active') {
                $retained = true;
                $messages[] = sprintf('Η εγγύηση %s (S/N %s) είναι ενεργή μέχρι %s: τα στοιχεία διατηρούνται για την εξυπηρέτηση της εγγύησης. Η συγκατάθεση για εμπορικές ενημερώσεις ανακλήθηκε.',
                    $w->warranty_code, $w->serial, TW_Util::date_label($w->warranty_end));
            } else {
                TW_Warranty::anonymize($w, 'privacy_eraser');
                $removed = true;
            }
        }
        return ['items_removed' => $removed, 'items_retained' => $retained, 'messages' => $messages, 'done' => true];
    }

    public static function policy_text() {
        if (!function_exists('wp_add_privacy_policy_content')) return;
        $c = esc_html((string) TW_Settings::get('company_name'));
        wp_add_privacy_policy_content('Warranty Manager', wpautop(
            "Κατά την ενεργοποίηση εγγύησης συλλέγουμε: ονοματεπώνυμο, email, κινητό και (προαιρετικά) σταθερό τηλέφωνο, διεύθυνση και ταχυδρομικό κώδικα, ΑΦΜ και email του συνεργάτη από τον οποίο έγινε η αγορά, αριθμό και αντίγραφο παραστατικού αγοράς, ημερομηνία αγοράς και τον σειριακό αριθμό του προϊόντος.\n\n"
            . "Σκοπός: η ενεργοποίηση και εξυπηρέτηση της εγγύησης και οι ειδοποιήσεις για τη συντήρηση (Service) και τη λήξη της (εκτέλεση σύμβασης). Με ξεχωριστή, προαιρετική συγκατάθεση, την οποία μπορείτε να ανακαλέσετε οποτεδήποτε από την πύλη εγγυήσεων, σας στέλνουμε νέα και προσφορές της $c.\n\n"
            . "Καταγράφουμε την αποδοχή των όρων εγγύησης (έκδοση κειμένου, ημερομηνία/ώρα, διεύθυνση IP, πρόγραμμα περιήγησης) ως αποδεικτικό. Τα στοιχεία διατηρούνται για όσο ισχύει η εγγύηση και για το διάστημα που απαιτεί η νομοθεσία· στη συνέχεια ανωνυμοποιούνται.\n\n"
            . "Τα email και SMS αποστέλλονται μέσω παρόχων (SMTP / SMS gateway) που ενεργούν ως εκτελούντες την επεξεργασία."
        ));
    }
}

/**
 * Privacy policy texts (Greek and English), edited in Ρυθμίσεις → GDPR with the same toolbar as the terms,
 * and shown on the site's pages with [thermic_warranty_privacy] in the visitor's language.
 */
final class TW_Privacy_Text {
    const OPTION = 'tw_privacy_text';
    const SHORTCODE = 'thermic_warranty_privacy';

    public static function init() {
        add_shortcode(self::SHORTCODE, [__CLASS__, 'shortcode']);
    }

    /** @return array lang => ['html' => string, 'updated_at' => Y-m-d H:i:s] */
    public static function all() {
        $v = get_option(self::OPTION, []);
        return is_array($v) ? $v : [];
    }

    public static function get($lang) {
        $all = self::all();
        return !empty($all[$lang]['html']) ? $all[$lang] : null;
    }

    /** Saves a language's text; returns true if it changed. */
    public static function save($lang, $html) {
        $html = trim(str_replace("\r\n", "\n", TW_Terms::clean_html($html)));
        if (trim(wp_strip_all_tags($html)) === '') $html = '';
        $all = self::all();
        if (($all[$lang]['html'] ?? '') === $html) return false;
        $all[$lang] = ['html' => $html, 'updated_at' => TW_DB::now()];
        update_option(self::OPTION, $all, false);
        return true;
    }

    /** Suggested text, based on what the platform actually collects and sends (to be completed by the company). */
    public static function template($lang) {
        $f = TW_DIR . 'templates/privacy-template-' . ($lang === 'en' ? 'en' : 'el') . '.html';
        return is_readable($f) ? (string) file_get_contents($f) : '';
    }

    public static function shortcode($atts = []) {
        $atts = shortcode_atts(['lang' => ''], $atts, self::SHORTCODE);
        $lang = TW_I18n::norm(sanitize_key($atts['lang'])) ?: TW_I18n::lang();
        $t = self::get($lang) ?: self::get('el');
        if (!$t) return '';
        if (!wp_style_is('tw-front', 'registered')) TW_Frontend::register_assets();
        wp_enqueue_style('tw-front');
        $tl = self::get($lang) ? $lang : 'el';
        return TW_I18n::with($lang, function () use ($t, $tl, $lang) {
            return '<div class="tw-privacy-text"' . ($tl !== $lang ? ' lang="' . esc_attr($tl) . '"' : '') . '>'
                . '<p class="tw-privacy-updated">' . esc_html(sprintf(tw__('Τελευταία ενημέρωση: %s'), TW_Util::date_label(substr($t['updated_at'], 0, 10)))) . '</p>'
                . wp_kses($t['html'], TW_Terms::allowed_html()) . '</div>';
        });
    }

    /** Rows of the GDPR settings tab (inside the settings form). */
    public static function admin_rows() {
        foreach (['el' => 'Ελληνικά', 'en' => 'English'] as $l => $ln) {
            $t = self::get($l);
            echo '<tr><th>Πολιτική απορρήτου (' . esc_html($ln) . ')</th><td>';
            echo '<p class="description" style="margin-top:0">' . ($t ? 'Τελευταία ενημέρωση: ' . esc_html(TW_Util::datetime_label($t['updated_at'])) . '. ' : 'Δεν έχει συμπληρωθεί ακόμη. ') . ($l === 'en' ? 'Χωρίς αγγλικό κείμενο, η αγγλική σελίδα δείχνει το ελληνικό.' : '') . '</p>';
            echo '<p><textarea aria-label="' . esc_attr('Πολιτική απορρήτου – ' . $ln) . '" id="tw-privacy-' . esc_attr($l) . '" name="privacy_text_' . esc_attr($l) . '" rows="14" class="large-text" lang="' . esc_attr($l) . '" data-tw-rich>' . esc_textarea($t['html'] ?? '') . '</textarea></p>';
            echo '<p><button type="button" class="button" data-tw-privacy-tpl="' . esc_attr($l) . '">Φόρτωση προτεινόμενου κειμένου</button> <span class="description">Βασίζεται σε ό,τι συλλέγει και στέλνει πραγματικά η πλατφόρμα. Συμπληρώστε τα σημεία σε [αγκύλες] και ελέγξτε το με τον νομικό σας σύμβουλο.</span></p>';
            echo '<template id="tw-privacy-tpl-' . esc_attr($l) . '">' . self::template($l) . '</template>'; // static file shipped with the plugin
            echo '</td></tr>';
        }
        ?>
        <tr><td colspan="2">
            <script src="<?php echo esc_url(TW_URL . 'assets/js/terms-editor.js?ver=' . TW_VERSION); ?>"></script>
            <script>
            document.querySelectorAll('[data-tw-privacy-tpl]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var l = b.getAttribute('data-tw-privacy-tpl'), ta = document.getElementById('tw-privacy-' + l);
                    var area = ta.closest('td').querySelector('.tw-rte-area');
                    if (area && area.textContent.trim() && !confirm('Να αντικατασταθεί το τρέχον κείμενο με το προτεινόμενο;')) return;
                    var html = document.getElementById('tw-privacy-tpl-' + l).innerHTML;
                    if (area) { area.innerHTML = html; area.dispatchEvent(new Event('input')); area.focus(); } else ta.value = html;
                });
            });
            </script>
        </td></tr>
        <?php
    }
}
