<?php
if (!defined('ABSPATH')) exit;

/**
 * Warranty and serial data access + domain actions.
 */
final class TW_Warranty {

    public static function select_sql() {
        $w = TW_DB::table('warranties');
        $s = TW_DB::table('serials');
        return "SELECT w.*, s.serial_no AS serial, s.product_code, s.product_name FROM $w w JOIN $s s ON s.id = w.serial_id";
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(self::select_sql() . " WHERE w.id = %d", $id));
    }

    public static function by_email($email) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(self::select_sql() . " WHERE w.email = %s AND w.anonymized_at IS NULL ORDER BY w.created_at DESC", $email));
    }

    public static function find_serial($serial) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT *, serial_no AS serial FROM " . TW_DB::table('serials') . " WHERE serial_no = %s", TW_Util::normalize_serial($serial)));
    }

    public static function serial_by_id($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT *, serial_no AS serial FROM " . TW_DB::table('serials') . " WHERE id = %d", $id));
    }

    public static function new_code() {
        global $wpdb;
        $t = TW_DB::table('warranties');
        do {
            $code = 'TW-' . TW_Util::random_code(4) . '-' . TW_Util::random_code(4) . '-' . TW_Util::random_code(4);
        } while ($wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE warranty_code = %s", $code)));
        return $code;
    }

    /**
     * Activates a warranty. The serial is claimed with a conditional UPDATE so two
     * simultaneous submissions can never both succeed.
     * @return object|WP_Error the warranty row
     */
    public static function activate($serial_id, array $data, array $consents) {
        global $wpdb;
        $serials = TW_DB::table('serials');
        $claimed = $wpdb->query($wpdb->prepare("UPDATE $serials SET status = 'registered' WHERE id = %d AND status = 'available'", $serial_id));
        if (!$claimed) {
            return new WP_Error('taken', 'Ο σειριακός αριθμός έχει ήδη καταχωρηθεί.');
        }
        $now = TW_DB::now();
        $code = self::new_code();
        $row = array_merge($data, [
            'warranty_code' => $code,
            'serial_id' => (int) $serial_id,
            'warranty_end' => TW_Schedule::warranty_end($data['purchase_date']),
            'status' => 'active',
            'marketing_opt_in' => !empty($consents['marketing']) ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if (!$wpdb->insert(TW_DB::table('warranties'), $row)) {
            $wpdb->query($wpdb->prepare("UPDATE $serials SET status = 'available' WHERE id = %d", $serial_id));
            return new WP_Error('db', 'Σφάλμα αποθήκευσης. Δοκιμάστε ξανά.');
        }
        $wid = (int) $wpdb->insert_id;

        $terms = TW_Settings::current_terms();
        $s = TW_Settings::all();
        $labels = ['terms' => $s['terms_label'], 'data_accuracy' => $s['accuracy_label'], 'marketing' => $s['marketing_label']];
        foreach ($labels as $type => $label) {
            self::record_consent($wid, $type, !empty($consents[$type]), $label, 'registration', $type === 'terms' ? $terms : null);
        }
        TW_DB::log_event('activated', ['warranty_code' => $code, 'terms_version' => $terms['version']], $wid, $serial_id, 'customer:' . $data['email']);
        return self::get($wid);
    }

    public static function record_consent($warranty_id, $type, $granted, $label, $source, $terms = null) {
        global $wpdb;
        $wpdb->insert(TW_DB::table('consents'), [
            'warranty_id' => (int) $warranty_id,
            'consent_type' => $type,
            'granted' => $granted ? 1 : 0,
            'terms_version' => $terms ? (int) $terms['version'] : 0,
            'terms_hash' => $terms ? $terms['hash'] : '',
            'label_text' => $label,
            'source' => $source,
            'ip' => TW_Util::client_ip(),
            'user_agent' => TW_Util::user_agent(),
            'created_at' => TW_DB::now(),
        ]);
    }

    public static function set_marketing($warranty, $granted, $source) {
        global $wpdb;
        $wpdb->update(TW_DB::table('warranties'), ['marketing_opt_in' => $granted ? 1 : 0, 'updated_at' => TW_DB::now()], ['id' => $warranty->id]);
        self::record_consent($warranty->id, 'marketing', $granted, (string) TW_Settings::get('marketing_label'), $source);
        TW_DB::log_event($granted ? 'marketing_granted' : 'marketing_withdrawn', ['source' => $source], $warranty->id, $warranty->serial_id, $source === 'portal' ? 'customer:' . $warranty->email : null);
    }

    public static function lock($warranty, $reason, $actor = 'system') {
        global $wpdb;
        $wpdb->update(TW_DB::table('warranties'), [
            'status' => 'locked',
            'lock_reason' => mb_substr($reason, 0, 255),
            'locked_at' => TW_DB::now(),
            'updated_at' => TW_DB::now(),
        ], ['id' => $warranty->id]);
        TW_DB::log_event('locked', $reason, $warranty->id, $warranty->serial_id, $actor);
    }

    public static function set_status($warranty, $status, $note, $actor = null) {
        global $wpdb;
        $data = ['status' => $status, 'updated_at' => TW_DB::now()];
        if ($status !== 'locked') {
            $data['lock_reason'] = '';
            $data['locked_at'] = null;
        }
        $wpdb->update(TW_DB::table('warranties'), $data, ['id' => $warranty->id]);
        TW_DB::log_event('status_' . $status, $note, $warranty->id, $warranty->serial_id, $actor);
    }

    public static function services($warranty_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . TW_DB::table('services') . " WHERE warranty_id = %d ORDER BY milestone_no", $warranty_id));
        $out = [];
        foreach ($rows as $r) $out[(int) $r->milestone_no] = $r;
        return $out;
    }

    /** Milestones of a warranty with Service state, for display. */
    public static function timeline($w) {
        $services = self::services($w->id);
        $lock = (int) TW_Settings::get('lock_offset');
        $today = TW_Schedule::today();
        $registered = substr($w->created_at, 0, 10);
        $out = [];
        foreach (TW_Schedule::milestones($w->purchase_date, $w->warranty_end) as $m) {
            $m['lock_date'] = TW_Schedule::add_days($m['due'], $lock);
            $m['service'] = $m['type'] === 'service' ? ($services[$m['no']] ?? null) : null;
            if ($m['type'] === 'service') {
                if ($m['service']) $m['state'] = 'done';
                elseif ($m['due'] < $registered) $m['state'] = 'before_registration';
                elseif ($today > $m['lock_date']) $m['state'] = 'missed';
                elseif ($today >= $m['due']) $m['state'] = 'due';
                else $m['state'] = 'upcoming';
            } else {
                $m['state'] = $today >= $m['due'] ? 'expired' : 'upcoming';
            }
            $out[] = $m;
        }
        return $out;
    }

    /** Next Service milestone that is not done yet (or null). */
    public static function next_service($w) {
        foreach (self::timeline($w) as $m) {
            if ($m['type'] === 'service' && in_array($m['state'], ['upcoming', 'due'], true)) return $m;
        }
        return null;
    }

    public static function record_service($w, $milestone_no, $date, $by, $technician, $genuine, $notes) {
        global $wpdb;
        $t = TW_DB::table('services');
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $t WHERE warranty_id = %d AND milestone_no = %d", $w->id, $milestone_no
        ));
        $wpdb->insert($t, [
            'warranty_id' => (int) $w->id,
            'milestone_no' => (int) $milestone_no,
            'performed_date' => $date,
            'performed_by' => $by,
            'technician' => $technician,
            'genuine_parts' => $genuine ? 1 : 0,
            'notes' => $notes,
            'created_by' => get_current_user_id(),
            'created_at' => TW_DB::now(),
        ]);
        TW_DB::log_event('service_recorded', ['milestone' => $milestone_no, 'date' => $date, 'by' => $by, 'genuine_parts' => $genuine ? 1 : 0], $w->id, $w->serial_id);
    }

    /**
     * GDPR anonymisation: personal data is removed, the product/warranty history stays.
     */
    public static function anonymize($w, $actor = null) {
        global $wpdb;
        if ($w->invoice_file) {
            $p = TW_Util::invoice_path($w->invoice_file);
            if ($p) @unlink($p);
        }
        $wpdb->update(TW_DB::table('warranties'), [
            'customer_name' => '[ανωνυμοποιήθηκε]',
            'email' => 'anon-' . $w->id . '@invalid',
            'phone_landline' => '',
            'phone_mobile' => '',
            'address' => '',
            'postcode' => '',
            'city' => '',
            'invoice_file' => '',
            'invoice_original_name' => '',
            'marketing_opt_in' => 0,
            'anonymized_at' => TW_DB::now(),
            'updated_at' => TW_DB::now(),
        ], ['id' => $w->id]);
        $wpdb->update(TW_DB::table('consents'), ['ip' => '', 'user_agent' => ''], ['warranty_id' => $w->id]);
        TW_DB::log_event('anonymized', '', $w->id, $w->serial_id, $actor);
    }
}
