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
    private static function claim($wid, $key, $offset, $channel, $recipient) {
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

    private static function finish($id, $result) {
        global $wpdb;
        $wpdb->update(TW_DB::table('notifications'), [
            'status' => $result === true ? 'sent' : ($result === null ? 'skipped' : 'failed'),
            'error' => is_string($result) ? $result : null,
        ], ['id' => $id]);
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
                    // Submitted (pending review) or approved: the warranty continues, no reminders.
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
                            TW_Messenger::render(TW_Settings::template($key)['body'], $vars)));
                    }
                    $digest[] = sprintf('%s: %s (%s) – %s – %s%s', self::step_label($m, $offset), $w->serial, $w->warranty_code,
                        $w->customer_name, $w->email, $res === true ? '' : ' [ΑΠΟΤΥΧΙΑ: ' . $res . ']');
                }
            }
        }

        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . TW_DB::table('services') . " WHERE review_status = 'pending'");
        if ($pending && $digest) {
            $digest[] = sprintf('Υποβολές Service που περιμένουν έλεγχο: %d', $pending);
        }

        self::retention($today);
        TW_OTP::purge();

        if ($digest && $s['admin_digest']) {
            $body = "Ειδοποιήσεις εγγυήσεων " . TW_Util::date_label($today) . ":\n\n- " . implode("\n- ", $digest)
                . "\n\nΔιαχείριση: " . TW_App::admin_url('warranties');
            foreach (TW_Messenger::admin_recipients() as $to) {
                TW_Messenger::send_email($to, sprintf('[Εγγυήσεις] %d ειδοποιήσεις – %s', count($digest), TW_Util::date_label($today)), $body);
            }
        }
        update_option('tw_last_run', ['at' => TW_DB::now(), 'today' => $today, 'count' => count($digest)], false);
        return $digest;
    }

    private static function send_sms_step($w, $m, $offset, $key, $vars) {
        if (!TW_Settings::get('sms_enabled') || !$w->phone_mobile) return;
        if (trim(TW_Settings::template($key)['sms']) === '') return;
        $claim = self::claim($w->id, $m['key'], $offset, 'sms', $w->phone_mobile);
        if ($claim) self::finish($claim, TW_Messenger::send_template_sms($w->phone_mobile, $key, $vars));
    }

    public static function step_label($m, $offset) {
        $what = $m['type'] === 'service' ? sprintf('%dο Service', $m['no']) : 'Λήξη';
        if ($offset === 0) return "$what – ημέρα";
        return sprintf('%s %s%d ημ.', $what, $offset > 0 ? '+' : '', $offset);
    }

    private static function vars($w, $m, $offset, $step_date) {
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
