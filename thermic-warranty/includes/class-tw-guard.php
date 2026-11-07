<?php
if (!defined('ABSPATH')) exit;

/**
 * Bot protection for every sign-in and customer form:
 *  - honeypot: a hidden field people never see or fill; anything in it means a bot;
 *  - time trap: a signed timestamp of when the form was shown; forms sent back faster than a person could are refused;
 *  - Cloudflare Turnstile (optional, per area): a privacy-friendly challenge, checked server-side with siteverify.
 *
 * Areas: 'staff' (console sign-in) and 'public' (activation and customer portal).
 * Emergency switch: define('TW_DISABLE_TURNSTILE', true) in wp-config.php turns Turnstile off (honeypot stays).
 */
final class TW_Guard {
    const SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
    const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    const HP = 'tw_website';
    const TS = 'tw_t';
    const MIN_SECONDS = 2;
    const PASS_TTL = 1800;

    public static function site_key() {
        return trim((string) TW_Settings::get('turnstile_site_key'));
    }

    public static function secret() {
        return trim((string) TW_Settings::get('turnstile_secret'));
    }

    public static function disabled_by_constant() {
        return defined('TW_DISABLE_TURNSTILE') && TW_DISABLE_TURNSTILE;
    }

    /** Whether Turnstile is required in this area ('staff' | 'public'). */
    public static function on($area) {
        if (self::disabled_by_constant() || self::site_key() === '' || self::secret() === '') return false;
        return (bool) TW_Settings::get($area === 'staff' ? 'turnstile_staff' : 'turnstile_public');
    }

    // ------------------------------------------------------------------ output

    /** Hidden honeypot field and signed timestamp; goes in every form. */
    public static function fields() {
        return '<div class="tw-hp" aria-hidden="true"><label>Website<input type="text" name="' . self::HP . '" value="" tabindex="-1" autocomplete="off"></label></div>'
            . '<input type="hidden" name="' . self::TS . '" value="' . esc_attr(self::stamp()) . '">';
    }

    /** The Turnstile box (placed just above the submit button), when the area needs it. */
    public static function widget($area, $action) {
        if (!self::on($area)) return '';
        return $area === 'staff'
            ? '<div class="cf-turnstile tw-ts" data-sitekey="' . esc_attr(self::site_key()) . '" data-language="el" data-size="flexible" data-action="' . esc_attr($action) . '"></div>'
            : '<div class="tw-ts" data-action="' . esc_attr($action) . '"></div>';
    }

    /** Script tag for pages without wp_head (implicit rendering of .cf-turnstile boxes). */
    public static function script_tag($area) {
        if (!self::on($area)) return '';
        return '<script src="' . esc_url(self::SCRIPT) . '" async defer></script>';
    }

    /** Script URL for the customer forms, which render their boxes from frontend.js. */
    public static function explicit_script_url() {
        return self::SCRIPT . '?render=explicit&onload=twTurnstileReady';
    }

    // ------------------------------------------------------------------ checks

    private static function stamp() {
        $t = (string) time();
        return $t . '.' . substr(hash_hmac('sha256', 'tw-form|' . $t, wp_salt('nonce')), 0, 16);
    }

    private static function stamp_age($value) {
        $parts = explode('.', (string) $value);
        if (count($parts) !== 2 || !ctype_digit($parts[0])) return -1;
        $expect = substr(hash_hmac('sha256', 'tw-form|' . $parts[0], wp_salt('nonce')), 0, 16);
        if (!hash_equals($expect, $parts[1])) return -1;
        return time() - (int) $parts[0];
    }

    /**
     * @param string $area 'staff' | 'public'
     * @param bool $challenge whether this form carries the time trap and Turnstile (the forms that start a sign-in or activation)
     * @param bool $skip_turnstile e.g. a "send a new code" request already covered by a pass from the first step
     * @return true|string error message for the person
     */
    public static function check($area, $challenge = true, $skip_turnstile = false) {
        $hp = isset($_POST[self::HP]) ? (string) wp_unslash($_POST[self::HP]) : '';
        if ($hp !== '') {
            self::log('honeypot', $area);
            return 'Μη έγκυρο αίτημα.';
        }
        if (!$challenge) return true;

        $age = self::stamp_age(isset($_POST[self::TS]) ? sanitize_text_field(wp_unslash($_POST[self::TS])) : '');
        if ($age < 0) return 'Η φόρμα έληξε. Ανανεώστε τη σελίδα και δοκιμάστε ξανά.';
        if ($age < self::MIN_SECONDS && !$skip_turnstile) {
            self::log('too_fast', $area);
            return 'Η φόρμα στάλθηκε πολύ γρήγορα. Περιμένετε λίγο και δοκιμάστε ξανά.';
        }

        if ($skip_turnstile || !self::on($area)) return true;
        $token = isset($_POST['cf-turnstile-response']) ? sanitize_text_field(wp_unslash($_POST['cf-turnstile-response'])) : '';
        if ($token === '') return 'Ολοκληρώστε τον έλεγχο ασφαλείας και δοκιμάστε ξανά.';
        $r = self::siteverify(self::secret(), $token);
        if ($r === true) return true;
        self::log('turnstile_' . $r, $area);
        return $r === 'network'
            ? 'Ο έλεγχος ασφαλείας δεν είναι διαθέσιμος αυτή τη στιγμή. Δοκιμάστε ξανά σε λίγο.'
            : 'Ο έλεγχος ασφαλείας απέτυχε ή έληξε. Δοκιμάστε ξανά.';
    }

    /** @return true|string 'network' | 'invalid-input-secret' | other Cloudflare error code */
    public static function siteverify($secret, $token) {
        $res = wp_remote_post(self::VERIFY_URL, [
            'timeout' => 10,
            'body' => ['secret' => $secret, 'response' => $token, 'remoteip' => TW_Util::client_ip()],
        ]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) return 'network';
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($data)) return 'network';
        if (!empty($data['success'])) return true;
        $codes = (array) ($data['error-codes'] ?? []);
        return $codes ? (string) $codes[0] : 'failed';
    }

    // ------------------------------------------------------------------ resend pass

    /** Lets "send a new code" skip a second challenge for the same email and serial, for 30 minutes. */
    public static function pass($subject) {
        $exp = time() + self::PASS_TTL;
        return $exp . '.' . substr(hash_hmac('sha256', 'tw-pass|' . $subject . '|' . $exp, wp_salt('nonce')), 0, 32);
    }

    public static function pass_ok($pass, $subject) {
        $parts = explode('.', (string) $pass);
        if (count($parts) !== 2 || !ctype_digit($parts[0]) || (int) $parts[0] < time()) return false;
        return hash_equals(substr(hash_hmac('sha256', 'tw-pass|' . $subject . '|' . $parts[0], wp_salt('nonce')), 0, 32), $parts[1]);
    }

    /** Simple per-IP counter: false once $max requests of this kind were made within $window seconds. */
    public static function throttle($bucket, $max, $window) {
        return self::hit($bucket, $max, $window);
    }

    /**
     * Counts one request in a per-IP bucket; false once $max were counted within $window seconds.
     * The increment is a single conditional UPDATE, so simultaneous requests cannot slip past the limit.
     */
    public static function hit($bucket, $max, $window, $ip = null) {
        global $wpdb;
        $t = TW_DB::table('throttle');
        $k = sha1($bucket . '|' . ($ip ?? TW_Util::client_ip()));
        $now = TW_DB::now();
        $exp = date('Y-m-d H:i:s', current_time('timestamp') + (int) $window);
        $suppress = $wpdb->suppress_errors(true);
        $wpdb->query($wpdb->prepare("INSERT INTO $t (k, n, expires_at) VALUES (%s, 0, %s)", $k, $exp)); // ignored if it exists
        $wpdb->suppress_errors($suppress);
        // A window that has ended starts again at 1.
        if ($wpdb->query($wpdb->prepare("UPDATE $t SET n = 1, expires_at = %s WHERE k = %s AND expires_at <= %s", $exp, $k, $now))) return true;
        return (bool) $wpdb->query($wpdb->prepare("UPDATE $t SET n = n + 1 WHERE k = %s AND n < %d", $k, (int) $max));
    }

    /** Current count in a bucket (0 when the window has ended). */
    public static function hits($bucket, $ip = null) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT n FROM " . TW_DB::table('throttle') . " WHERE k = %s AND expires_at > %s", sha1($bucket . '|' . ($ip ?? TW_Util::client_ip())), TW_DB::now()));
    }

    public static function reset($bucket, $ip = null) {
        global $wpdb;
        $wpdb->delete(TW_DB::table('throttle'), ['k' => sha1($bucket . '|' . ($ip ?? TW_Util::client_ip()))]);
    }

    /** Housekeeping (daily). */
    public static function purge() {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM " . TW_DB::table('throttle') . " WHERE expires_at < %s", TW_DB::now()));
    }

    // ------------------------------------------------------------------ stats

    /** Counts blocked attempts per day (kept for 30 days) for the settings page. */
    private static function log($reason, $area) {
        $stats = get_option('tw_guard_stats', []);
        if (!is_array($stats)) $stats = [];
        $day = wp_date('Y-m-d');
        $key = $area . ':' . (strpos($reason, 'turnstile_') === 0 ? 'turnstile' : $reason);
        $stats[$day][$key] = ($stats[$day][$key] ?? 0) + 1;
        krsort($stats);
        update_option('tw_guard_stats', array_slice($stats, 0, 30, true), false);
    }

    /** @return array ['honeypot' => n, 'too_fast' => n, 'turnstile' => n] over the last $days days */
    public static function stats($days = 30) {
        $out = ['honeypot' => 0, 'too_fast' => 0, 'turnstile' => 0];
        $from = wp_date('Y-m-d', time() - ($days - 1) * DAY_IN_SECONDS);
        foreach ((array) get_option('tw_guard_stats', []) as $day => $rows) {
            if ($day < $from) continue;
            foreach ((array) $rows as $k => $n) {
                $r = substr($k, strpos($k, ':') + 1);
                if (isset($out[$r])) $out[$r] += (int) $n;
            }
        }
        return $out;
    }
}
