<?php
if (!defined('ABSPATH')) exit;

/**
 * Sign-in for the standalone admin app: email + password, then (by default) a one-time
 * code by email. Also first-time password set / reset by emailed code, and sign-out.
 * Uses the normal WordPress auth cookie, so sessions behave like any WP login.
 */
final class TW_Staff_Auth {
    const MAX_FAILS = 10;
    const FAIL_WINDOW = 900; // 15 minutes
    const MIN_PASSWORD = 10;

    /** @return array view state for the login template */
    public static function handle() {
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';

        if ($action === 'tw_logout') {
            if (is_user_logged_in() && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'tw_logout')) {
                wp_logout();
            }
            wp_safe_redirect(TW_App::admin_url());
            exit;
        }

        if (is_user_logged_in() && current_user_can(TW_Admin::cap())) {
            return ['step' => 'login'];
        }
        if (is_user_logged_in() && !current_user_can(TW_Admin::cap()) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return ['step' => 'login', 'error' => 'Ο λογαριασμός σας δεν έχει πρόσβαση στο διαχειριστικό εγγυήσεων.'];
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || strpos($action, 'tw_staff_') !== 0) {
            return ['step' => isset($_GET['forgot']) ? 'forgot' : 'login'];
        }
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'tw_staff_auth')) {
            return ['step' => 'login', 'error' => 'Η φόρμα έληξε. Δοκιμάστε ξανά.'];
        }
        // Honeypot everywhere; time trap and Turnstile on the forms that start a sign-in or a password reset.
        $guard = TW_Guard::check('staff', in_array($action, ['tw_staff_login', 'tw_staff_forgot'], true));
        if ($guard !== true) {
            $back = ['tw_staff_otp' => 'otp', 'tw_staff_forgot' => 'forgot', 'tw_staff_reset' => 'reset'][$action] ?? 'login';
            $email = isset($_POST['email']) ? strtolower(sanitize_email(wp_unslash($_POST['email']))) : '';
            return ['step' => $back, 'email' => $email, 'error' => $guard];
        }
        if (self::blocked()) {
            return ['step' => 'login', 'error' => 'Πάρα πολλές αποτυχημένες προσπάθειες. Δοκιμάστε ξανά σε 15 λεπτά.'];
        }

        $email = isset($_POST['email']) ? strtolower(sanitize_email(wp_unslash($_POST['email']))) : '';
        switch ($action) {
            case 'tw_staff_login':
                return self::step_password($email, isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '');
            case 'tw_staff_otp':
                return self::step_otp($email, isset($_POST['code']) ? (string) wp_unslash($_POST['code']) : '');
            case 'tw_staff_forgot':
                return self::step_forgot($email);
            case 'tw_staff_reset':
                return self::step_reset($email, isset($_POST['code']) ? (string) wp_unslash($_POST['code']) : '',
                    isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '', isset($_POST['password2']) ? (string) wp_unslash($_POST['password2']) : '');
        }
        return ['step' => 'login'];
    }

    private static function fail_key() {
        return 'tw_staff_fail_' . md5(TW_Util::client_ip());
    }

    private static function blocked() {
        return (int) get_transient(self::fail_key()) >= self::MAX_FAILS;
    }

    private static function fail() {
        set_transient(self::fail_key(), (int) get_transient(self::fail_key()) + 1, self::FAIL_WINDOW);
    }

    private static function team_user($email) {
        $u = $email ? get_user_by('email', $email) : null;
        return ($u && user_can($u, TW_Admin::cap())) ? $u : null;
    }

    private static function send_code($user, $purpose, $subject) {
        [, $code] = TW_OTP::create($purpose, strtolower($user->user_email), $user->ID);
        return TW_Messenger::send_email($user->user_email, $subject,
            sprintf("Γεια σας %s,\n\nο κωδικός σας για το διαχειριστικό εγγυήσεων είναι:\n\n%s\n\nΙσχύει για %d λεπτά. Αν δεν τον ζητήσατε εσείς, αγνοήστε το μήνυμα και ενημερώστε τον υπεύθυνο.",
                $user->display_name, $code, TW_OTP::ttl_minutes()));
    }

    const SESSION_CHOICES = [15, 30, 60, 120, 240, 480, 720, 1440];

    public static function session_minutes() {
        $m = (int) TW_Settings::get('staff_session_minutes');
        return in_array($m, self::SESSION_CHOICES, true) ? $m : 120;
    }

    /**
     * Issues the WordPress auth cookie with this platform's session length. WordPress stores the
     * expiry with the session token, so the server ends the session even if the page stays open.
     * @return int expiry timestamp
     */
    public static function issue_cookie($user_id) {
        $len = self::session_minutes() * MINUTE_IN_SECONDS;
        $f = function () use ($len) { return $len; };
        // Keep $_COOKIE in step so nonces made later in this request use the new session token.
        $c = function ($cookie) { $_COOKIE[LOGGED_IN_COOKIE] = $cookie; };
        add_filter('auth_cookie_expiration', $f, 999);
        add_action('set_logged_in_cookie', $c, 10, 1);
        wp_set_auth_cookie($user_id, true, is_ssl());
        remove_filter('auth_cookie_expiration', $f, 999);
        remove_action('set_logged_in_cookie', $c, 10);
        return time() + $len;
    }

    /** Expiry of the current sign-in (from the logged-in cookie). */
    public static function session_expires() {
        $p = wp_parse_auth_cookie('', 'logged_in');
        return $p && !empty($p['expiration']) ? (int) $p['expiration'] : 0;
    }

    /** Starts a fresh session of the configured length and ends the current one. @return array */
    public static function refresh() {
        $user = wp_get_current_user();
        $old = wp_get_session_token();
        $exp = self::issue_cookie($user->ID);
        if ($old) WP_Session_Tokens::get_instance($user->ID)->destroy($old);
        return ['expires' => $exp, 'nonce' => wp_create_nonce('tw_session_refresh')];
    }

    private static function sign_in($user) {
        wp_set_current_user($user->ID);
        self::issue_cookie($user->ID);
        update_user_meta($user->ID, 'tw_last_login', TW_DB::now());
        delete_transient(self::fail_key());
        TW_DB::log_event('staff_login', ['ip' => TW_Util::client_ip()], 0, 0, 'admin:' . $user->user_login);
        wp_safe_redirect(TW_App::admin_url());
        exit;
    }

    private static function step_password($email, $password) {
        $user = self::team_user($email);
        $auth = $user ? wp_authenticate($user->user_login, $password) : null;
        if (!$user || is_wp_error($auth)) {
            self::fail();
            return ['step' => 'login', 'email' => $email, 'error' => 'Λάθος email ή κωδικός πρόσβασης.'];
        }
        if (!TW_Settings::get('staff_2fa')) self::sign_in($auth);
        $rl = TW_OTP::rate_limit_ok(strtolower($user->user_email));
        if (is_wp_error($rl)) return ['step' => 'login', 'email' => $email, 'error' => $rl->get_error_message()];
        $sent = self::send_code($user, 'staff', 'Κωδικός σύνδεσης – Διαχειριστικό εγγυήσεων');
        if ($sent !== true) return ['step' => 'login', 'email' => $email, 'error' => 'Δεν ήταν δυνατή η αποστολή του κωδικού: ' . $sent];
        return ['step' => 'otp', 'email' => $email, 'info' => sprintf('Στείλαμε κωδικό μίας χρήσης στο %s.', TW_Util::mask_email($user->user_email))];
    }

    private static function step_otp($email, $code) {
        $user = self::team_user($email);
        if (!$user) return ['step' => 'login', 'error' => 'Ξεκινήστε ξανά τη σύνδεση.'];
        $res = TW_OTP::verify('staff', strtolower($user->user_email), $code, $user->ID);
        if (is_wp_error($res)) {
            self::fail();
            return ['step' => 'otp', 'email' => $email, 'error' => $res->get_error_message()];
        }
        TW_OTP::mark_used($res['row']->id);
        self::sign_in($user);
    }

    private static function step_forgot($email) {
        $generic = ['step' => 'reset', 'email' => $email, 'info' => 'Αν το email ανήκει σε μέλος της ομάδας, θα λάβετε σε λίγο κωδικό. Συμπληρώστε τον μαζί με τον νέο σας κωδικό πρόσβασης.'];
        if (!is_email($email)) return ['step' => 'forgot', 'error' => 'Συμπληρώστε έγκυρο email.'];
        $rl = TW_OTP::rate_limit_ok($email);
        if (is_wp_error($rl)) return ['step' => 'forgot', 'email' => $email, 'error' => $rl->get_error_message()];
        $user = self::team_user($email);
        if ($user) {
            self::send_code($user, 'staff_reset', 'Ορισμός κωδικού πρόσβασης – Διαχειριστικό εγγυήσεων');
        } else {
            TW_OTP::create('staff_unknown', $email, 0); // counts toward the rate limit
        }
        return $generic;
    }

    private static function step_reset($email, $code, $p1, $p2) {
        $base = ['step' => 'reset', 'email' => $email];
        if (mb_strlen($p1) < self::MIN_PASSWORD) return $base + ['error' => sprintf('Ο κωδικός πρόσβασης πρέπει να έχει τουλάχιστον %d χαρακτήρες.', self::MIN_PASSWORD)];
        if ($p1 !== $p2) return $base + ['error' => 'Οι δύο κωδικοί δεν ταιριάζουν.'];
        $user = self::team_user($email);
        if (!$user) { self::fail(); return $base + ['error' => 'Ο κωδικός έληξε ή δεν βρέθηκε. Ζητήστε νέο κωδικό.']; }
        $res = TW_OTP::verify('staff_reset', strtolower($user->user_email), $code, $user->ID);
        if (is_wp_error($res)) { self::fail(); return $base + ['error' => $res->get_error_message()]; }
        TW_OTP::mark_used($res['row']->id);
        wp_set_password($p1, $user->ID);
        TW_DB::log_event('staff_password_set', '', 0, 0, 'admin:' . $user->user_login);
        self::sign_in(get_user_by('id', $user->ID));
    }

    public static function logout_url() {
        return wp_nonce_url(add_query_arg('action', 'tw_logout', TW_App::admin_url()), 'tw_logout');
    }
}
