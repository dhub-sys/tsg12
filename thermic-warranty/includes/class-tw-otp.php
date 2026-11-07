<?php
if (!defined('ABSPATH')) exit;

/**
 * One-time email codes.
 *  - 'register': one new code per serial + email (each warranty gets its own code)
 *  - 'login':    customer portal sign-in
 * Codes and continuation tokens are stored as HMAC hashes only.
 */
final class TW_OTP {
    const MINUTE_CHOICES = [2, 3, 5, 10, 15];
    const MAX_ATTEMPTS = 5;
    const TOKEN_TTL_MINUTES = 60;
    const MAX_SENDS_PER_HOUR_EMAIL = 8; // short-lived codes mean a few more "send a new code"

    /** How long an emailed code is valid (Settings → Ασφάλεια, default 2 minutes). */
    public static function ttl_minutes() {
        $m = (int) TW_Settings::get('otp_minutes');
        return in_array($m, self::MINUTE_CHOICES, true) ? $m : 2;
    }

    /** Seconds left on the newest open code (for the countdown on the code screen). */
    public static function seconds_left($purpose, $email, $serial_id = 0) {
        global $wpdb;
        $t = TW_DB::table('otps');
        $exp = $wpdb->get_var($wpdb->prepare(
            "SELECT expires_at FROM $t WHERE purpose = %s AND email = %s AND serial_id = %d AND verified_at IS NULL ORDER BY id DESC LIMIT 1",
            $purpose, $email, (int) $serial_id
        ));
        return $exp ? max(0, strtotime($exp) - current_time('timestamp')) : 0;
    }
    const MAX_SENDS_PER_HOUR_IP = 20;

    public static function hash($value) {
        return hash_hmac('sha256', (string) $value, wp_salt('auth') . 'tw_otp');
    }

    /** @return true|WP_Error */
    /**
     * Counts one code sent and says whether it is allowed: at most MAX_SENDS_PER_HOUR_EMAIL per email and
     * MAX_SENDS_PER_HOUR_IP per IP within an hour. Atomic counters, so a burst of parallel requests cannot exceed them.
     * @return true|WP_Error
     */
    public static function rate_limit_ok($email) {
        if (!TW_Guard::hit('otp_email|' . strtolower((string) $email), self::MAX_SENDS_PER_HOUR_EMAIL, HOUR_IN_SECONDS, '')) {
            return new WP_Error('rate', tw__('Έχουν σταλεί πολλοί κωδικοί σε αυτό το email. Δοκιμάστε ξανά σε λίγο.'));
        }
        $ip = TW_Util::client_ip();
        if ($ip !== '' && !TW_Guard::hit('otp_ip', self::MAX_SENDS_PER_HOUR_IP, HOUR_IN_SECONDS)) {
            return new WP_Error('rate', tw__('Πάρα πολλά αιτήματα. Δοκιμάστε ξανά σε λίγο.'));
        }
        return true;
    }

    /**
     * Creates a code (invalidating earlier unused ones for the same purpose/email/serial).
     * @return array [id, code]
     */
    public static function create($purpose, $email, $serial_id = 0) {
        global $wpdb;
        $t = TW_DB::table('otps');
        $now = TW_DB::now();
        $wpdb->query($wpdb->prepare(
            "UPDATE $t SET expires_at = %s WHERE purpose = %s AND email = %s AND serial_id = %d AND verified_at IS NULL AND expires_at > %s",
            $now, $purpose, $email, (int) $serial_id, $now
        ));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $wpdb->insert($t, [
            'purpose' => $purpose,
            'email' => $email,
            'serial_id' => (int) $serial_id,
            'code_hash' => self::hash($code),
            'expires_at' => date('Y-m-d H:i:s', current_time('timestamp') + self::ttl_minutes() * MINUTE_IN_SECONDS),
            'ip' => TW_Util::client_ip(),
            'created_at' => $now,
        ]);
        return [(int) $wpdb->insert_id, $code];
    }

    /**
     * Checks a code; on success returns a fresh continuation token (plain, given to the browser once).
     * @return array|WP_Error ['token' => ..., 'row' => object]
     */
    public static function verify($purpose, $email, $code, $serial_id = 0) {
        global $wpdb;
        $t = TW_DB::table('otps');
        $now = TW_DB::now();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t WHERE purpose = %s AND email = %s AND serial_id = %d AND verified_at IS NULL AND expires_at > %s ORDER BY id DESC LIMIT 1",
            $purpose, $email, (int) $serial_id, $now
        ));
        if (!$row) {
            return new WP_Error('otp', tw__('Ο κωδικός έληξε ή δεν βρέθηκε. Ζητήστε νέο κωδικό.'));
        }
        if ((int) $row->attempts >= self::MAX_ATTEMPTS) {
            return new WP_Error('otp', tw__('Υπερβήκατε τον αριθμό προσπαθειών. Ζητήστε νέο κωδικό.'));
        }
        $wpdb->query($wpdb->prepare("UPDATE $t SET attempts = attempts + 1 WHERE id = %d", $row->id));
        $code = preg_replace('/\D/', '', (string) $code);
        if (!hash_equals($row->code_hash, self::hash($code))) {
            $left = self::MAX_ATTEMPTS - (int) $row->attempts - 1;
            return new WP_Error('otp', $left > 0 ? sprintf(tw__('Λάθος κωδικός. Απομένουν %d προσπάθειες.'), $left) : tw__('Λάθος κωδικός. Ζητήστε νέο κωδικό.'));
        }
        $token = bin2hex(random_bytes(32));
        $wpdb->update($t, [
            'verified_at' => $now,
            'token_hash' => self::hash($token),
            'expires_at' => date('Y-m-d H:i:s', current_time('timestamp') + self::TOKEN_TTL_MINUTES * MINUTE_IN_SECONDS),
        ], ['id' => $row->id]);
        $row->verified_at = $now;
        return ['token' => $token, 'row' => $row];
    }

    /** Looks up a verified, unused, unexpired token. */
    public static function token_row($purpose, $token) {
        global $wpdb;
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $t = TW_DB::table('otps');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t WHERE purpose = %s AND token_hash = %s AND verified_at IS NOT NULL AND used_at IS NULL AND expires_at > %s LIMIT 1",
            $purpose, self::hash($token), TW_DB::now()
        ));
    }

    public static function mark_used($id) {
        global $wpdb;
        $wpdb->update(TW_DB::table('otps'), ['used_at' => TW_DB::now()], ['id' => (int) $id]);
    }

    /** Housekeeping: drop rows older than 30 days. */
    public static function purge() {
        global $wpdb;
        $t = TW_DB::table('otps');
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE created_at < %s", date('Y-m-d H:i:s', current_time('timestamp') - 30 * DAY_IN_SECONDS)));
    }
}
