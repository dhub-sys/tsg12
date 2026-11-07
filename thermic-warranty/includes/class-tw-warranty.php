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

        // Consents are recorded with the exact texts and terms version the customer saw, in their language.
        $lang = TW_I18n::norm($data['lang'] ?? '') ?: 'el';
        $terms = TW_Settings::current_terms($lang);
        $cl = TW_Settings::consent_labels($lang);
        $labels = ['terms' => $cl['terms'], 'data_accuracy' => $cl['accuracy'], 'marketing' => $cl['marketing']];
        foreach ($labels as $type => $label) {
            self::record_consent($wid, $type, !empty($consents[$type]), $label, 'registration', $type === 'terms' ? $terms : null, $type === 'terms' ? $terms['lang'] : $lang);
        }
        TW_DB::log_event('activated', ['warranty_code' => $code, 'terms_version' => $terms['version'] . ($terms['lang'] === 'en' ? ' (EN)' : ''), 'lang' => $lang === 'en' ? 'English' : 'Ελληνικά'], $wid, $serial_id, 'customer:' . $data['email']);
        return self::get($wid);
    }

    public static function record_consent($warranty_id, $type, $granted, $label, $source, $terms = null, $lang = 'el') {
        global $wpdb;
        $wpdb->insert(TW_DB::table('consents'), [
            'warranty_id' => (int) $warranty_id,
            'consent_type' => $type,
            'granted' => $granted ? 1 : 0,
            'terms_version' => $terms ? (int) $terms['version'] : 0,
            'terms_hash' => $terms ? $terms['hash'] : '',
            'label_text' => $label,
            'lang' => TW_I18n::norm($lang) ?: 'el',
            'source' => $source,
            'ip' => TW_Util::client_ip(),
            'user_agent' => TW_Util::user_agent(),
            'created_at' => TW_DB::now(),
        ]);
    }

    public static function set_marketing($warranty, $granted, $source) {
        global $wpdb;
        $wpdb->update(TW_DB::table('warranties'), ['marketing_opt_in' => $granted ? 1 : 0, 'updated_at' => TW_DB::now()], ['id' => $warranty->id]);
        self::record_consent($warranty->id, 'marketing', $granted, TW_Settings::consent_labels($warranty->lang ?? 'el')['marketing'], $source, null, $warranty->lang ?? 'el');
        TW_DB::log_event($granted ? 'marketing_granted' : 'marketing_withdrawn', ['source' => $source], $warranty->id, $warranty->serial_id, in_array($source, ['portal', 'registration', 'inherited'], true) ? 'customer:' . $warranty->email : null);
    }

    /**
     * Marketing consent belongs to the person, not to a product: applies the choice to every warranty of this
     * email (each change is recorded as its own consent entry, with date and source). @return int warranties changed
     */
    public static function set_marketing_for_email($email, $granted, $source) {
        $n = 0;
        foreach (self::by_email($email) as $w) {
            if ((bool) $w->marketing_opt_in === (bool) $granted) continue;
            self::set_marketing($w, $granted, $source);
            $n++;
        }
        return $n;
    }

    /** The customer's language for messages (from their newest warranty). */
    public static function customer_lang($email) {
        global $wpdb;
        $l = $wpdb->get_var($wpdb->prepare("SELECT lang FROM " . TW_DB::table('warranties') . " WHERE email = %s AND anonymized_at IS NULL ORDER BY id DESC LIMIT 1", $email));
        return TW_I18n::norm($l) ?: 'el';
    }

    /** Sets the language for messages on all of the customer's warranties. @return int changed */
    public static function set_lang_for_email($email, $lang, $actor = null) {
        global $wpdb;
        $lang = TW_I18n::norm($lang) ?: 'el';
        $n = 0;
        foreach (self::by_email($email) as $w) {
            if (($w->lang ?? 'el') === $lang) continue;
            $wpdb->update(TW_DB::table('warranties'), ['lang' => $lang, 'updated_at' => TW_DB::now()], ['id' => $w->id]);
            TW_DB::log_event('language_changed', ['lang' => $lang === 'en' ? 'English' : 'Ελληνικά'], $w->id, $w->serial_id, $actor ?: 'customer:' . $email);
            $n++;
        }
        return $n;
    }

    /** @return array ['state' => 'yes'|'no'|'mixed', 'changed_at' => 'Y-m-d H:i:s'|''] for this person */
    public static function marketing_status($email) {
        global $wpdb;
        $ws = self::by_email($email);
        $yes = count(array_filter($ws, function ($w) { return (int) $w->marketing_opt_in === 1; }));
        $state = !$ws || $yes === 0 ? 'no' : ($yes === count($ws) ? 'yes' : 'mixed');
        $ids = array_map(function ($w) { return (int) $w->id; }, $ws);
        $at = $ids ? (string) $wpdb->get_var("SELECT MAX(created_at) FROM " . TW_DB::table('consents') . " WHERE consent_type = 'marketing' AND warranty_id IN (" . implode(',', $ids) . ")") : '';
        return ['state' => $state, 'changed_at' => $at];
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

    /** Services loaded in bulk for a batch of warranties (see preload_services()). */
    private static $svc_cache = [];

    /** Loads the Service records of many warranties with one query (lists, exports, the daily task). */
    public static function preload_services(array $ids) {
        global $wpdb;
        self::$svc_cache = [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return;
        foreach ($ids as $id) self::$svc_cache[$id] = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = $wpdb->get_results("SELECT * FROM " . TW_DB::table('services') . " WHERE warranty_id IN (" . implode(',', $chunk) . ") ORDER BY warranty_id, milestone_no, id");
            foreach ($rows as $r) self::$svc_cache[(int) $r->warranty_id][(int) $r->milestone_no] = $r; // latest per milestone
        }
    }

    public static function forget_services($warranty_id = null) {
        if ($warranty_id === null) self::$svc_cache = []; else unset(self::$svc_cache[(int) $warranty_id]);
    }

    public static function services($warranty_id) {
        if (array_key_exists((int) $warranty_id, self::$svc_cache)) return self::$svc_cache[(int) $warranty_id];
        global $wpdb;
        // Latest submission per milestone (all submissions are kept, see services_history()).
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . TW_DB::table('services') . " WHERE warranty_id = %d ORDER BY milestone_no, id", $warranty_id));
        $out = [];
        foreach ($rows as $r) $out[(int) $r->milestone_no] = $r;
        return $out;
    }

    /** Every Service submission/record of a warranty, newest first. */
    public static function services_history($warranty_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . TW_DB::table('services') . " WHERE warranty_id = %d ORDER BY id DESC", $warranty_id));
    }

    public static function service_row($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . TW_DB::table('services') . " WHERE id = %d", $id));
    }

    /**
     * What the administrator needs when a warranty claim comes in: is the warranty valid today,
     * and what has the customer submitted for each Service that has fallen due.
     * @return array ['ok' => bool, 'title' => string, 'lines' => string[]]
     */
    public static function validity($w) {
        $labels = TW_Util::status_labels();
        $today = TW_Schedule::today();
        $lines = [];
        $open = null;
        foreach (self::timeline($w) as $m) {
            if ($m['type'] !== 'service' || $m['state'] === 'before_registration') continue;
            if ($m['due'] > $today && !$m['service']) continue; // not due yet
            $sv = $m['service'];
            if ($sv && self::service_counts($sv)) {
                $lines[] = sprintf('%dο Service: καταχωρήθηκε %s – τεχνικός: %s – απόδειξη: %s – γνήσια ανταλλακτικά: %s%s',
                    $m['no'], TW_Util::date_label($sv->performed_date), TW_Util::tax_id_text($sv->technician_country, $sv->technician_vat), self::receipt_text($sv),
                    $sv->genuine_parts ? 'ναι' : 'όχι', $sv->review_status === 'approved' ? ' – ελέγχθηκε' : '');
            } elseif (in_array($m['state'], ['due', 'rejected'], true)) {
                $open = $m;
                $lines[] = sprintf('%dο Service (%s): εκκρεμεί – η καταχώρηση επιτρέπεται έως %s', $m['no'], TW_Util::date_label($m['due']),
                    TW_Util::date_label($m['state'] === 'rejected' && $sv ? $sv->resubmit_until : $m['lock_date']));
            } else {
                $lines[] = sprintf('%dο Service (%s): δεν καταχωρήθηκε', $m['no'], TW_Util::date_label($m['due']));
            }
        }
        if (!$lines) $lines[] = 'Δεν έχει έρθει ακόμη η ημερομηνία του πρώτου Service.';

        if ($w->status === 'active') {
            $title = $open
                ? sprintf('Σε ισχύ – εκκρεμεί η καταχώρηση του %dου Service', $open['no'])
                : 'Σε ισχύ – όλα τα Service που έχουν λήξει έχουν καταχωρηθεί';
            return ['ok' => true, 'title' => $title . ' (λήξη εγγύησης ' . TW_Util::date_label($w->warranty_end) . ')', 'lines' => $lines];
        }
        $why = $w->status === 'locked' ? ' – ' . $w->lock_reason : '';
        return ['ok' => false, 'title' => 'Δεν ισχύει – ' . ($labels[$w->status] ?? $w->status) . $why, 'lines' => $lines];
    }

    /** A submitted ('pending' = not checked yet) or checked Service keeps the warranty going; a rejected one does not. */
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
    public static function record_service($w, $milestone_no, $date, $by, $technician, $genuine, $notes, $technician_vat = '', $receipt = null, $technician_country = 'GR') {
        global $wpdb;
        $wpdb->insert(TW_DB::table('services'), [
            'warranty_id' => (int) $w->id,
            'milestone_no' => (int) $milestone_no,
            'performed_date' => $date,
            'performed_by' => $by,
            'technician' => $technician,
            'technician_vat' => $technician_vat,
            'technician_country' => $technician_country,
            'genuine_parts' => $genuine ? 1 : 0,
            'receipt_file' => $receipt['file'] ?? '',
            'receipt_original_name' => $receipt['name'] ?? '',
            'receipt_number' => $receipt['number'] ?? '',
            'receipt_date' => $receipt['date'] ?? null,
            'source' => 'admin',
            'review_status' => 'approved',
            'reviewed_by' => get_current_user_id(),
            'reviewed_at' => TW_DB::now(),
            'notes' => $notes,
            'created_by' => get_current_user_id(),
            'created_at' => TW_DB::now(),
        ]);
        self::forget_services($w->id);
        TW_DB::log_event('service_recorded', ['milestone' => $milestone_no, 'date' => $date, 'by' => $by, 'technician_vat' => $technician_vat, 'genuine_parts' => $genuine ? 1 : 0], $w->id, $w->serial_id);
    }

    /**
     * Service submitted by the customer from the portal. Accepted at once (the warranty continues);
     * the company may check it later, typically when a warranty claim comes in. Earlier submissions are kept.
     */
    public static function submit_service($w, $milestone_no, $date, $technician_vat, $technician, array $receipt, $notes, $technician_country = 'GR') {
        global $wpdb;
        $wpdb->insert(TW_DB::table('services'), [
            'warranty_id' => (int) $w->id,
            'milestone_no' => (int) $milestone_no,
            'performed_date' => $date,
            'performed_by' => 'customer',
            'technician' => $technician,
            'technician_vat' => $technician_vat,
            'technician_country' => $technician_country,
            'genuine_parts' => 1,
            'receipt_file' => $receipt['file'] ?? '',
            'receipt_original_name' => $receipt['name'] ?? '',
            'receipt_number' => $receipt['number'] ?? '',
            'receipt_date' => $receipt['date'] ?? null,
            'source' => 'customer',
            'review_status' => 'pending',
            'notes' => $notes,
            'created_at' => TW_DB::now(),
        ]);
        self::forget_services($w->id);
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

    /** Removes one submission (admin correction). */
    public static function delete_service($row) {
        global $wpdb;
        if ($row->receipt_file && ($p = TW_Util::invoice_path($row->receipt_file))) @unlink($p);
        $wpdb->delete(TW_DB::table('services'), ['id' => (int) $row->id]);
    }

    /** e.g. "αρ. ΑΠΥ-123 / 03/11/2026, αρχείο: scan.pdf" */
    public static function receipt_text($row) {
        $parts = [];
        if ($row->receipt_number) $parts[] = tw__('αρ.') . ' ' . $row->receipt_number . ($row->receipt_date ? ' / ' . TW_Util::date_label($row->receipt_date) : '');
        if ($row->receipt_file) $parts[] = tw__('αρχείο') . ($row->receipt_original_name ? ': ' . $row->receipt_original_name : '');
        return $parts ? implode(', ', $parts) : '—';
    }

    /** Placeholders for the Service submission/review messages. */
    public static function service_vars($w, $row) {
        return TW_I18n::with($w->lang ?? 'el', function () use ($w, $row) { return self::service_vars_in_lang($w, $row); });
    }

    private static function service_vars_in_lang($w, $row) {
        $next = null;
        foreach (TW_Schedule::milestones($w->purchase_date, $w->warranty_end) as $m) {
            if ($m['type'] === 'service' && $m['no'] > (int) $row->milestone_no) { $next = $m; break; }
        }
        $next_step = $next
            ? sprintf(tw__('Επόμενο Service: %s.'), TW_Util::date_label($next['due']))
            : sprintf(tw__('Η εγγύηση ισχύει έως %s.'), TW_Util::date_label($w->warranty_end));
        return TW_Messenger::warranty_vars($w, [
            'milestone_no' => (int) $row->milestone_no,
            'performed_date' => TW_Util::date_label($row->performed_date),
            'technician_vat' => $row->technician_vat . (($row->technician_country ?? 'GR') !== 'GR' ? ' (' . tw__(TW_Util::countries()[$row->technician_country] ?? $row->technician_country) . ')' : ''),
            'receipt_info' => self::receipt_text($row),
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
        foreach (self::services_history($w->id) as $sv) {
            if ($sv->receipt_file && ($p = TW_Util::invoice_path($sv->receipt_file))) @unlink($p);
        }
        $wpdb->update(TW_DB::table('services'), ['receipt_file' => '', 'receipt_original_name' => ''], ['warranty_id' => $w->id]);
        // Activity log: replace the customer's email, phones and name, drop their IP, and sign the entries again.
        $anon = 'anon-' . $w->id . '@invalid';
        $repl = array_filter([(string) $w->email => $anon, (string) $w->phone_mobile => '', (string) $w->phone_landline => '', (string) $w->customer_name => '[ανωνυμοποιήθηκε]'], function ($v, $k) { return $k !== ''; }, ARRAY_FILTER_USE_BOTH);
        // This warranty's entries; the customer's general entries only if no other warranty of theirs remains.
        $others = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . TW_DB::table('warranties') . " WHERE email = %s AND id <> %d AND anonymized_at IS NULL", $w->email, $w->id));
        $sql = $others ? "SELECT * FROM " . TW_DB::table('events') . " WHERE warranty_id = %d" : "SELECT * FROM " . TW_DB::table('events') . " WHERE warranty_id = %d OR (warranty_id = 0 AND actor = %s)";
        foreach ($wpdb->get_results($others ? $wpdb->prepare($sql, $w->id) : $wpdb->prepare($sql, $w->id, 'customer:' . $w->email)) as $ev) {
            $new = ['actor' => strtr((string) $ev->actor, $repl), 'details' => strtr((string) $ev->details, $repl)];
            if (strpos($ev->actor, 'customer:') === 0) $new['ip'] = '';
            $wpdb->update(TW_DB::table('events'), $new, ['id' => $ev->id]);
            TW_DB::sign_event((int) $ev->id);
        }
        TW_DB::log_event('anonymized', '', $w->id, $w->serial_id, $actor);
    }
}
