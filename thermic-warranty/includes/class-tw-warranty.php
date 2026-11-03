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

    /** A Service row keeps the warranty going while it is pending review or approved. */
    public static function service_counts($row) {
        return $row && in_array($row->review_status, ['pending', 'approved'], true);
    }

    /** A rejected submission that the customer may still correct. */
    public static function resubmit_open($row, $today = null) {
        $today = $today ?: TW_Schedule::today();
        return $row && $row->review_status === 'rejected' && $row->resubmit_until && $row->resubmit_until >= $today;
    }

    /**
     * Milestones of a warranty with Service state, for display and for the customer form.
     * Service states: done | submitted | rejected | due | upcoming | missed | before_registration
     */
    public static function timeline($w) {
        $services = self::services($w->id);
        $lock = (int) TW_Settings::get('lock_offset');
        $window = max(0, (int) TW_Settings::get('service_submit_window_days'));
        $today = TW_Schedule::today();
        $registered = substr($w->created_at, 0, 10);
        $out = [];
        foreach (TW_Schedule::milestones($w->purchase_date, $w->warranty_end) as $m) {
            $m['lock_date'] = TW_Schedule::add_days($m['due'], $lock);
            $m['service'] = $m['type'] === 'service' ? ($services[$m['no']] ?? null) : null;
            $m['can_submit'] = false;
            if ($m['type'] === 'service') {
                $sv = $m['service'];
                if ($sv && $sv->review_status === 'approved') $m['state'] = 'done';
                elseif ($sv && $sv->review_status === 'pending') $m['state'] = 'submitted';
                elseif (self::resubmit_open($sv, $today)) $m['state'] = 'rejected';
                elseif ($m['due'] < $registered) $m['state'] = 'before_registration';
                elseif ($today > $m['lock_date']) $m['state'] = 'missed';
                elseif ($today >= $m['due']) $m['state'] = 'due';
                else $m['state'] = 'upcoming';
                $m['can_submit'] = $w->status === 'active' && !$w->anonymized_at && (
                    $m['state'] === 'rejected'
                    || ($m['state'] === 'due')
                    || ($m['state'] === 'upcoming' && $today >= TW_Schedule::add_days($m['due'], -$window))
                );
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
            if ($m['type'] === 'service' && in_array($m['state'], ['upcoming', 'due', 'rejected'], true)) return $m;
        }
        return null;
    }

    /** Service recorded directly by the company: approved at once. */
    public static function record_service($w, $milestone_no, $date, $by, $technician, $genuine, $notes, $technician_vat = '', $receipt = null) {
        global $wpdb;
        $t = TW_DB::table('services');
        self::delete_service_row($w->id, $milestone_no);
        $wpdb->insert($t, [
            'warranty_id' => (int) $w->id,
            'milestone_no' => (int) $milestone_no,
            'performed_date' => $date,
            'performed_by' => $by,
            'technician' => $technician,
            'technician_vat' => $technician_vat,
            'genuine_parts' => $genuine ? 1 : 0,
            'receipt_file' => $receipt['file'] ?? '',
            'receipt_original_name' => $receipt['name'] ?? '',
            'source' => 'admin',
            'review_status' => 'approved',
            'reviewed_by' => get_current_user_id(),
            'reviewed_at' => TW_DB::now(),
            'notes' => $notes,
            'created_by' => get_current_user_id(),
            'created_at' => TW_DB::now(),
        ]);
        TW_DB::log_event('service_recorded', ['milestone' => $milestone_no, 'date' => $date, 'by' => $by, 'technician_vat' => $technician_vat, 'genuine_parts' => $genuine ? 1 : 0], $w->id, $w->serial_id);
    }

    /** Service submitted by the customer from the portal: pending review, the warranty continues meanwhile. */
    public static function submit_service($w, $milestone_no, $date, $technician_vat, $technician, array $receipt, $notes) {
        global $wpdb;
        self::delete_service_row($w->id, $milestone_no); // replaces an earlier rejected submission
        $wpdb->insert(TW_DB::table('services'), [
            'warranty_id' => (int) $w->id,
            'milestone_no' => (int) $milestone_no,
            'performed_date' => $date,
            'performed_by' => 'customer',
            'technician' => $technician,
            'technician_vat' => $technician_vat,
            'genuine_parts' => 1,
            'receipt_file' => $receipt['file'],
            'receipt_original_name' => $receipt['name'],
            'source' => 'customer',
            'review_status' => 'pending',
            'notes' => $notes,
            'created_at' => TW_DB::now(),
        ]);
        TW_DB::log_event('service_submitted', ['milestone' => $milestone_no, 'date' => $date, 'technician_vat' => $technician_vat], $w->id, $w->serial_id, 'customer:' . $w->email);
        return self::services($w->id)[(int) $milestone_no] ?? null;
    }

    /**
     * Company review of a customer submission.
     * @return object|WP_Error the updated row
     */
    public static function review_service($w, $milestone_no, $approve, $note) {
        global $wpdb;
        $row = self::services($w->id)[(int) $milestone_no] ?? null;
        if (!$row) return new WP_Error('missing', 'Η καταχώρηση Service δεν βρέθηκε.');
        // Resubmission deadline: N days from now, but never earlier than the milestone's normal lock date.
        $deadline = TW_Schedule::add_days(TW_Schedule::today(), max(1, (int) TW_Settings::get('service_resubmit_days')));
        foreach (TW_Schedule::milestones($w->purchase_date, $w->warranty_end) as $m) {
            if ($m['type'] === 'service' && $m['no'] === (int) $milestone_no) {
                $deadline = max($deadline, TW_Schedule::add_days($m['due'], (int) TW_Settings::get('lock_offset')));
            }
        }
        $data = [
            'review_status' => $approve ? 'approved' : 'rejected',
            'review_note' => mb_substr($note, 0, 255),
            'reviewed_by' => get_current_user_id(),
            'reviewed_at' => TW_DB::now(),
            'resubmit_until' => $approve ? null : $deadline,
        ];
        $wpdb->update(TW_DB::table('services'), $data, ['id' => $row->id]);
        TW_DB::log_event($approve ? 'service_approved' : 'service_rejected', ['milestone' => $milestone_no, 'note' => $note], $w->id, $w->serial_id);
        return self::services($w->id)[(int) $milestone_no];
    }

    public static function delete_service_row($warranty_id, $milestone_no) {
        global $wpdb;
        $t = TW_DB::table('services');
        $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE warranty_id = %d AND milestone_no = %d", $warranty_id, $milestone_no));
        if ($old && $old->receipt_file) {
            $p = TW_Util::invoice_path($old->receipt_file);
            if ($p) @unlink($p);
        }
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE warranty_id = %d AND milestone_no = %d", $warranty_id, $milestone_no));
    }

    /** Placeholders for the Service submission/review messages. */
    public static function service_vars($w, $row) {
        $next = null;
        foreach (TW_Schedule::milestones($w->purchase_date, $w->warranty_end) as $m) {
            if ($m['type'] === 'service' && $m['no'] > (int) $row->milestone_no) { $next = $m; break; }
        }
        $next_step = $next
            ? sprintf('Επόμενο Service: %s.', TW_Util::date_label($next['due']))
            : sprintf('Η εγγύηση ισχύει έως %s.', TW_Util::date_label($w->warranty_end));
        return TW_Messenger::warranty_vars($w, [
            'milestone_no' => (int) $row->milestone_no,
            'performed_date' => TW_Util::date_label($row->performed_date),
            'technician_vat' => $row->technician_vat,
            'review_note' => $row->review_note,
            'resubmit_until' => TW_Util::date_label((string) $row->resubmit_until),
            'next_step' => $next_step,
        ]);
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
        foreach (self::services($w->id) as $sv) {
            if ($sv->receipt_file && ($p = TW_Util::invoice_path($sv->receipt_file))) @unlink($p);
        }
        $wpdb->update(TW_DB::table('services'), ['receipt_file' => '', 'receipt_original_name' => ''], ['warranty_id' => $w->id]);
        TW_DB::log_event('anonymized', '', $w->id, $w->serial_id, $actor);
    }
}
