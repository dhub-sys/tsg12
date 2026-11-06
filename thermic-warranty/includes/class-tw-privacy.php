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
