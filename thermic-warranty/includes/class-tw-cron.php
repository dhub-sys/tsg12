<?php
if (!defined('ABSPATH')) exit;

/**
 * Daily job: Service/expiry reminders, final lock, admin digest, retention.
 *
 * Idempotent: every step of every milestone is claimed in the notifications table
 * (unique warranty+milestone+offset+channel) before anything is sent, so running
 * the job twice on the same day, or concurrently, never duplicates a message.
 */
final class TW_Cron {
    const HOOK = 'tw_daily_run';

    public static function init() {
        add_action(self::HOOK, [__CLASS__, 'run']);
        if (!wp_next_scheduled(self::HOOK)) self::schedule();
    }

    public static function schedule() {
        if (wp_next_scheduled(self::HOOK)) return;
        // 09:00 site time tomorrow (or today if still before 09:00).
        $tz = wp_timezone();
        $next = new DateTimeImmutable('today 09:00', $tz);
        if ($next->getTimestamp() <= time()) $next = $next->modify('+1 day');
        wp_schedule_event($next->getTimestamp(), 'daily', self::HOOK);
    }

    public static function unschedule() {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /** Claims a step; false if it was already processed. */
    public static function claim($wid, $key, $offset, $channel, $recipient) {
        global $wpdb;
        $t = TW_DB::table('notifications');
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $t WHERE warranty_id = %d AND milestone_key = %s AND offset_days = %d AND channel = %s",
            $wid, $key, $offset, $channel
        ));
        if ($exists) return false;
        $ok = $wpdb->insert($t, [
            'warranty_id' => $wid, 'milestone_key' => $key, 'offset_days' => $offset,
            'channel' => $channel, 'recipient' => $recipient, 'status' => 'pending', 'created_at' => TW_DB::now(),
        ]);
        return $ok ? (int) $wpdb->insert_id : false;
    }

    public static function finish($id, $result) {
        global $wpdb;
        $data = [
            'status' => $result === true ? 'sent' : ($result === null ? 'skipped' : 'failed'),
            'error' => is_string($result) ? $result : null,
        ];
        // Sent through Brevo: remember its id so the delivery report can update this row.
        if ($result === true && TW_Messenger::$last_id) {
            $data['provider_id'] = TW_Messenger::$last_id;
            $data['delivery_status'] = 'sent';
        }
        $wpdb->update(TW_DB::table('notifications'), $data, ['id' => $id]);
        // Activity log (activation messages are logged separately by the activation itself).
        $row = $wpdb->get_row($wpdb->prepare("SELECT n.*, w.serial_id FROM " . TW_DB::table('notifications') . " n LEFT JOIN " . TW_DB::table('warranties') . " w ON w.id = n.warranty_id WHERE n.id = %d", $id));
        if ($row && $row->milestone_key !== 'activation' && $data['status'] !== 'skipped') {
            TW_DB::log_event($data['status'] === 'sent' ? 'message_sent' : 'message_failed', array_filter([
                'message' => self::row_label($row), 'channel' => ['email' => 'email', 'sms' => 'SMS', 'partner' => 'email συνεργάτη'][$row->channel] ?? $row->channel,
                'to' => $row->recipient, 'reason' => $data['error'],
            ]), (int) $row->warranty_id, (int) $row->serial_id, 'system');
        }
    }

    /** Records a one-off message (e.g. the activation confirmation) in the notifications log. */
    public static function log_message($warranty_id, $key, $channel, $recipient, $result) {
        $id = self::claim($warranty_id, $key, 0, $channel, $recipient);
        if ($id) self::finish($id, $result);
    }

    /**
     * A reminder could not be delivered: send the same step through the other channel, once.
     * Called by the Brevo delivery report handler.
     */
    public static function fallback($w, $row) {
        if (!TW_Settings::get('fallback_channel') || $w->status === 'void' || $w->anonymized_at) return;
        $m = null;
        foreach (TW_Schedule::milestones($w->purchase_date, $w->warranty_end) as $x) {
            if ($x['key'] === $row->milestone_key) $m = $x;
        }
        if (!$m) return; // not a reminder (e.g. activation)
        $offset = (int) $row->offset_days;
        $key = $m['type'] . '_' . TW_Schedule::stage($offset);
        $vars = self::vars($w, $m, $offset, TW_Schedule::add_days($m['due'], $offset));
        if ($row->channel === 'email') {
            if (!TW_Messenger::sms_active() || !$w->phone_mobile || trim(TW_Settings::template($key)['sms']) === '') return;
            $claim = self::claim($w->id, $m['key'], $offset, 'sms', $w->phone_mobile);
            if ($claim) self::finish($claim, TW_Messenger::send_template_sms($w->phone_mobile, $key, $vars));
        } elseif ($row->channel === 'sms') {
            $claim = self::claim($w->id, $m['key'], $offset, 'email', $w->email);
            if ($claim) self::finish($claim, TW_Messenger::send_template_email($w->email, $key, $vars));
        } else {
            return;
        }
        if (!empty($claim)) TW_DB::log_event('fallback_sent', ['step' => self::step_label($m, $offset), 'after_failed' => $row->channel], $w->id, $w->serial_id, 'system');
    }

    /**
     * @param string|null $today override for testing (Y-m-d)
     * @return array summary lines
     */
    public static function run($today = null) {
        global $wpdb;
        $today = $today ?: TW_Schedule::today();
        $s = TW_Settings::all();
        $catchup = max(0, (int) $s['catchup_days']);
        $digest = [];

        $rows = $wpdb->get_results(TW_Warranty::select_sql() . " WHERE w.status IN ('active','expired') AND w.anonymized_at IS NULL");
        foreach ($rows as $w) {
            $registered = substr($w->created_at, 0, 10);
            $services = TW_Warranty::services($w->id);

            if ($w->status === 'active' && $today >= $w->warranty_end) {
                TW_Warranty::set_status($w, 'expired', 'Λήξη εγγύησης', 'system');
                $w->status = 'expired';
                $digest[] = sprintf('Λήξη: %s (%s) – %s', $w->serial, $w->warranty_code, $w->customer_name);
            }

            foreach (TW_Schedule::milestones($w->purchase_date, $w->warranty_end) as $m) {
                if ($m['type'] === 'service') {
                    $sv = $services[$m['no']] ?? null;
                    // Submitted by the customer (or checked by the company): the warranty continues, no reminders.
                    if (TW_Warranty::service_counts($sv)) continue;
                    // Rejected but the customer may still resubmit: hold reminders and the lock until the deadline.
                    if (TW_Warranty::resubmit_open($sv, $today)) continue;
                }
                if ($m['type'] === 'service' && $w->status !== 'active') continue;
                // A Service that fell due before the customer registered is not chased or used to lock.
                if ($m['type'] === 'service' && $m['due'] < $registered) continue;

                foreach (TW_Schedule::steps() as $offset) {
                    $step_date = TW_Schedule::add_days($m['due'], $offset);
                    if ($step_date > $today) break;
                    if ($step_date < $registered) continue; // happened before the customer registered
                    $stage = TW_Schedule::stage($offset);
                    $late = TW_Schedule::diff_days($step_date, $today);

                    if ($stage === 'locked') {
                        $should_lock = $m['type'] === 'service' ? (bool) $s['lock_on_missed_service'] : (bool) $s['lock_on_expiry'];
                        if (!$should_lock) continue;
                        $claim = self::claim($w->id, $m['key'], $offset, 'email', $w->email);
                        if (!$claim) continue;
                        $reason = $m['type'] === 'service'
                            ? sprintf('Δεν καταχωρήθηκε (ή δεν εγκρίθηκε) το %dο Service (ημερομηνία %s)', $m['no'], TW_Util::date_label($m['due']))
                            : sprintf('Οριστικό κλείδωμα μετά τη λήξη (%s)', TW_Util::date_label($m['due']));
                        TW_Warranty::lock($w, $reason);
                        $vars = self::vars($w, $m, $offset, $step_date);
                        self::finish($claim, TW_Messenger::send_template_email($w->email, $m['type'] . '_locked', $vars));
                        self::send_sms_step($w, $m, $offset, $m['type'] . '_locked', $vars);
                        $digest[] = sprintf('ΚΛΕΙΔΩΜΑ: %s (%s) – %s – %s', $w->serial, $w->warranty_code, $w->customer_name, $reason);
                        continue 3; // next warranty
                    }

                    if ($late > $catchup) continue; // too old to be useful as a reminder
                    $claim = self::claim($w->id, $m['key'], $offset, 'email', $w->email);
                    if (!$claim) continue;
                    $key = $m['type'] . '_' . $stage;
                    $vars = self::vars($w, $m, $offset, $step_date);
                    $res = TW_Messenger::send_template_email($w->email, $key, $vars);
                    self::finish($claim, $res);
                    self::send_sms_step($w, $m, $offset, $key, $vars);

                    if ($m['type'] === 'service' && $s['notify_partner'] && is_email($w->partner_email)) {
                        $pc = self::claim($w->id, $m['key'], $offset, 'partner', $w->partner_email);
                        if ($pc) self::finish($pc, TW_Messenger::send_email($w->partner_email,
                            '[Κοινοποίηση] ' . TW_Messenger::render(TW_Settings::template($key)['subject'], $vars),
                            TW_Messenger::render(TW_Settings::template($key)['body'], $vars), 'service'));
                    }
                    $digest[] = sprintf('%s: %s (%s) – %s – %s%s', self::step_label($m, $offset), $w->serial, $w->warranty_code,
                        $w->customer_name, $w->email, $res === true ? '' : ' [ΑΠΟΤΥΧΙΑ: ' . $res . ']');
                }
            }
        }

        self::retention($today);
        TW_OTP::purge();

        if ($s['admin_digest']) self::send_daily_report();
        update_option('tw_last_run', ['at' => TW_DB::now(), 'today' => $today, 'count' => count($digest)], false);
        return $digest;
    }

    /**
     * Daily report to the company: everything that happened since the previous report
     * (normally the last 24 hours). Sent only when there is something to report.
     * @return int number of items reported
     */
    public static function send_daily_report() {
        $now = TW_DB::now();
        $since = (string) get_option('tw_last_report', '');
        if ($since === '' || $since > $now) $since = date('Y-m-d H:i:s', current_time('timestamp') - DAY_IN_SECONDS);
        $r = self::daily_report($since, $now);
        update_option('tw_last_report', $now, false);
        if (!$r['count']) return 0;
        $body = sprintf("Αναφορά Warranty Manager για το διάστημα %s – %s.\n", TW_Util::datetime_label($since), TW_Util::datetime_label($now));
        foreach ($r['sections'] as $title => $lines) {
            if (!$lines) continue;
            $body .= "\n■ " . $title . ' (' . count($lines) . ")\n- " . implode("\n- ", $lines) . "\n";
        }
        $body .= "\nΔιαχειριστικό: " . TW_App::admin_url();
        foreach (TW_Messenger::admin_recipients() as $to) {
            TW_Messenger::send_email($to, sprintf('[%s] Αναφορά 24ώρου – %s – %d %s', TW_App::APP_NAME, TW_Util::date_label(substr($now, 0, 10)), $r['count'], $r['count'] === 1 ? 'ενημέρωση' : 'ενημερώσεις'), $body);
        }
        return $r['count'];
    }

    /** @return array ['sections' => [title => lines], 'count' => int] */
    public static function daily_report($since, $until) {
        global $wpdb;
        $n = TW_DB::table('notifications'); $w = TW_DB::table('warranties'); $sr = TW_DB::table('serials'); $e = TW_DB::table('events'); $sv = TW_DB::table('services');
        $who = function ($row) { return trim($row->serial_no . ' (' . $row->warranty_code . ') – ' . $row->customer_name); };
        $sections = [
            'Υπενθυμίσεις που στάλθηκαν' => [],
            'Αποτυχίες αποστολής' => [],
            'Μηνύματα που δεν παραδόθηκαν' => [],
            'Εγγυήσεις που έληξαν ή κλείδωσαν' => [],
            'Νέες ενεργοποιήσεις' => [],
            'Υποβολές Service από πελάτες' => [],
            'Ασφάλεια' => [],
            'Άλλες ενέργειες' => [],
        ];
        // Reminders (and one-off messages) sent in the period.
        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT n.*, s.serial_no, w.warranty_code, w.customer_name FROM $n n JOIN $w w ON w.id = n.warranty_id JOIN $sr s ON s.id = w.serial_id
             WHERE n.created_at >= %s AND n.created_at < %s ORDER BY n.id", $since, $until)) as $row) {
            $ch = ['email' => 'email', 'sms' => 'SMS', 'partner' => 'email συνεργάτη'][$row->channel] ?? $row->channel;
            $line = sprintf('%s (%s): %s – %s', self::row_label($row), $ch, $who($row), $row->recipient);
            if ($row->status === 'failed') $sections['Αποτυχίες αποστολής'][] = $line . ($row->error ? ' – ' . $row->error : '');
            elseif ($row->status === 'sent' && $row->milestone_key !== 'activation') $sections['Υπενθυμίσεις που στάλθηκαν'][] = $line;
        }
        // Delivery reports (Brevo) received in the period.
        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT n.*, s.serial_no, w.warranty_code, w.customer_name FROM $n n JOIN $w w ON w.id = n.warranty_id JOIN $sr s ON s.id = w.serial_id
             WHERE n.delivery_status IN ('failed','complaint') AND n.delivery_at >= %s AND n.delivery_at < %s ORDER BY n.id", $since, $until)) as $row) {
            $sections['Μηνύματα που δεν παραδόθηκαν'][] = sprintf('%s (%s): %s – %s%s', self::row_label($row), $row->channel === 'sms' ? 'SMS' : 'email', $who($row), $row->recipient, $row->delivery_detail ? ' – ' . $row->delivery_detail : '');
        }
        // Events in the period.
        $map = ['locked' => 'Εγγυήσεις που έληξαν ή κλείδωσαν', 'status_expired' => 'Εγγυήσεις που έληξαν ή κλείδωσαν', 'activated' => 'Νέες ενεργοποιήσεις',
            'fallback_sent' => 'Μηνύματα που δεν παραδόθηκαν', 'erasure_requested' => 'Άλλες ενέργειες', 'customer_corrected' => 'Άλλες ενέργειες', 'status_active' => 'Άλλες ενέργειες', 'status_void' => 'Άλλες ενέργειες',
            'staff_login_failed' => 'Ασφάλεια', 'staff_otp_failed' => 'Ασφάλεια', 'staff_login_blocked' => 'Ασφάλεια', 'access_blocked' => 'Ασφάλεια',
            'ip_lock_on' => 'Ασφάλεια', 'ip_lock_off' => 'Ασφάλεια', 'ip_lock_changed' => 'Ασφάλεια', 'team_added' => 'Ασφάλεια', 'team_removed' => 'Ασφάλεια',
            'staff_password_set' => 'Ασφάλεια', 'backup_restored' => 'Ασφάλεια'];
        $in = implode(',', array_fill(0, count($map), '%s'));
        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT e.*, s.serial_no, w.warranty_code, w.customer_name FROM $e e LEFT JOIN $w w ON w.id = e.warranty_id LEFT JOIN $sr s ON s.id = w.serial_id
             WHERE e.action IN ($in) AND e.created_at >= %s AND e.created_at < %s ORDER BY e.id", array_merge(array_keys($map), [$since, $until]))) as $row) {
            $label = TW_Activity::label($row->action);
            $target = $row->serial_no ? $who($row) : TW_Activity::who($row->actor) . ($row->ip ? ' – IP ' . $row->ip : '');
            $details = TW_Admin::event_details($row->details);
            if ($row->action === 'activated') $details = '';
            $sections[$map[$row->action]][] = $label . ': ' . $target . ($details !== '' ? ' – ' . $details : '');
        }
        // Service submitted by customers.
        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT v.*, s.serial_no, w.warranty_code, w.customer_name FROM $sv v JOIN $w w ON w.id = v.warranty_id JOIN $sr s ON s.id = w.serial_id
             WHERE v.source = 'customer' AND v.created_at >= %s AND v.created_at < %s ORDER BY v.id", $since, $until)) as $row) {
            $sections['Υποβολές Service από πελάτες'][] = sprintf('%dο Service: %s – ημ/νία %s – τεχνικός: %s – απόδειξη: %s', $row->milestone_no, $who($row), TW_Util::date_label($row->performed_date), TW_Util::tax_id_text($row->technician_country ?? 'GR', $row->technician_vat), TW_Warranty::receipt_text($row));
        }
        $count = 0;
        foreach ($sections as $l) $count += count($l);
        return ['sections' => $sections, 'count' => $count];
    }

    private static function send_sms_step($w, $m, $offset, $key, $vars) {
        if (!TW_Messenger::sms_active() || !$w->phone_mobile) return;
        if (trim(TW_Settings::template($key)['sms']) === '') return;
        $claim = self::claim($w->id, $m['key'], $offset, 'sms', $w->phone_mobile);
        if ($claim) self::finish($claim, TW_Messenger::send_template_sms($w->phone_mobile, $key, $vars));
    }

    /** Label of a notifications row (reminder step or one-off message). */
    public static function row_label($row) {
        $one_off = ['activation' => 'Επιβεβαίωση ενεργοποίησης', 'service_rejected' => 'Απόρριψη υποβολής Service'];
        if (isset($one_off[$row->milestone_key])) return $one_off[$row->milestone_key];
        $m = $row->milestone_key === 'expiry' ? ['type' => 'expiry', 'no' => 0] : ['type' => 'service', 'no' => (int) str_replace('service_', '', $row->milestone_key)];
        return self::step_label($m, (int) $row->offset_days);
    }

    public static function step_label($m, $offset) {
        $what = $m['type'] === 'service' ? sprintf('%dο Service', $m['no']) : 'Λήξη';
        if ($offset === 0) return "$what – ημέρα";
        return sprintf('%s %s%d ημ.', $what, $offset > 0 ? '+' : '', $offset);
    }

    public static function vars($w, $m, $offset, $step_date) {
        $lock = (int) TW_Settings::get('lock_offset');
        $offsets = TW_Settings::reminder_offsets();
        $grace = $offsets ? max(0, max($offsets)) : 0;
        return TW_Messenger::warranty_vars($w, [
            'due_date' => TW_Util::date_label($m['due']),
            'days' => abs($offset),
            'years' => $m['years'],
            'milestone_no' => $m['no'],
            'grace_end_date' => TW_Util::date_label(TW_Schedule::add_days($m['due'], $grace)),
            'lock_date' => TW_Util::date_label(TW_Schedule::add_days($m['due'], $lock)),
        ]);
    }

    /** Optional automatic anonymisation N months after a warranty ended or was locked. */
    private static function retention($today) {
        global $wpdb;
        $months = (int) TW_Settings::get('retention_months_after_end');
        if ($months <= 0) return;
        $cutoff = (new DateTimeImmutable($today))->modify("-$months months")->format('Y-m-d');
        $rows = $wpdb->get_results($wpdb->prepare(
            TW_Warranty::select_sql() . " WHERE w.anonymized_at IS NULL AND w.status IN ('expired','locked','void') AND (w.warranty_end < %s OR (w.locked_at IS NOT NULL AND w.locked_at < %s))",
            $cutoff, $cutoff . ' 00:00:00'
        ));
        foreach ($rows as $w) {
            TW_Warranty::anonymize($w, 'system:retention');
        }
    }
}
