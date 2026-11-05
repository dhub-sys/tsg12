<?php
if (!defined('ABSPATH')) exit;

/**
 * Public side:
 *  [thermic_warranty_register] – serial → email → email code → details, terms, consents → warranty code + printable card
 *  [thermic_warranty_portal]   – email code sign-in → the customer's warranties, Service dates, consents, own data
 *
 * Everything runs through admin-ajax so full-page caches never serve stale personal data;
 * the portal content itself is fetched after page load.
 */
final class TW_Frontend {
    const COOKIE = 'tw_portal_session';

    public static function init() {
        add_shortcode('thermic_warranty_register', [__CLASS__, 'shortcode_register']);
        add_shortcode('thermic_warranty_portal', [__CLASS__, 'shortcode_portal']);
        add_action('template_redirect', [__CLASS__, 'no_cache_on_our_pages']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);

        $public = ['reg_check', 'reg_start', 'reg_verify', 'reg_submit', 'login_start', 'login_verify', 'portal', 'portal_marketing', 'portal_service', 'portal_export', 'portal_erase', 'logout'];
        foreach ($public as $a) {
            add_action('wp_ajax_tw_' . $a, [__CLASS__, 'ajax_' . $a]);
            add_action('wp_ajax_nopriv_tw_' . $a, [__CLASS__, 'ajax_' . $a]);
        }
    }

    public static function no_cache_on_our_pages() {
        if (!is_singular()) return;
        $post = get_post();
        if ($post && (has_shortcode($post->post_content, 'thermic_warranty_register') || has_shortcode($post->post_content, 'thermic_warranty_portal'))) {
            if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
            nocache_headers();
        }
    }

    public static function register_assets() {
        wp_register_style('tw-fonts', TW_URL . 'assets/css/fonts.css', [], TW_VERSION);
        wp_register_style('tw-front', TW_URL . 'assets/css/frontend.css', ['tw-fonts'], TW_VERSION);
        wp_add_inline_style('tw-front', TW_App::brand_vars());
        wp_register_script('tw-otp', TW_URL . 'assets/js/otp-input.js', [], TW_VERSION, true);
        wp_register_script('tw-front', TW_URL . 'assets/js/frontend.js', ['tw-otp'], TW_VERSION, true);
        wp_localize_script('tw-front', 'TW', [
            'ajax' => admin_url('admin-ajax.php'),
            'maxMb' => (int) TW_Settings::get('invoice_max_mb'),
            'invoiceMode' => TW_Settings::invoice_mode(),
            'turnstile' => TW_Guard::on('public') ? TW_Guard::site_key() : '',
        ]);
        if (TW_Guard::on('public')) wp_register_script('tw-turnstile', TW_Guard::explicit_script_url(), ['tw-front'], null, true);
    }

    private static function enqueue() {
        if (!wp_script_is('tw-front', 'registered')) self::register_assets();
        wp_enqueue_style('tw-front');
        wp_enqueue_script('tw-front');
        if (wp_script_is('tw-turnstile', 'registered')) wp_enqueue_script('tw-turnstile');
    }

    // ------------------------------------------------------------------ shortcodes

    public static function shortcode_register() {
        self::enqueue();
        $s = TW_Settings::all();
        $privacy = $s['privacy_url'] ?: get_privacy_policy_url();
        ob_start();
        include TW_DIR . 'templates/register.php';
        return ob_get_clean();
    }

    public static function shortcode_portal() {
        self::enqueue();
        ob_start();
        include TW_DIR . 'templates/portal.php';
        return ob_get_clean();
    }

    // ------------------------------------------------------------------ helpers

    private static function input($key) {
        return isset($_POST[$key]) ? trim(wp_unslash((string) $_POST[$key])) : '';
    }

    private static function fail($message, $field = '', $status = 400) {
        wp_send_json_error(['message' => $message, 'field' => $field], $status);
    }

    private static function email_input() {
        $email = strtolower(sanitize_email(self::input('email')));
        if (!$email || !is_email($email)) self::fail('Συμπληρώστε ένα έγκυρο email.', 'email');
        return $email;
    }

    /** Serial lookup with the messages the customer should see. */
    private static function usable_serial($raw) {
        $serial = TW_Util::normalize_serial($raw);
        if ($serial === '') self::fail('Συμπληρώστε τον σειριακό αριθμό.', 'serial');
        $row = TW_Warranty::find_serial($serial);
        if (!$row || $row->status === 'void') {
            self::fail('Ο σειριακός αριθμός δεν βρέθηκε. Ελέγξτε ότι τον πληκτρολογήσατε σωστά, όπως αναγράφεται στην ετικέτα του προϊόντος.', 'serial');
        }
        if ($row->status !== 'available') {
            self::fail('Για αυτόν τον σειριακό αριθμό έχει ήδη ενεργοποιηθεί εγγύηση. Αν είναι δική σας, συνδεθείτε στην πύλη εγγυήσεων.', 'serial');
        }
        return $row;
    }

    // ------------------------------------------------------------------ registration

    /** Honeypot (always), time trap and Turnstile (when $challenge). */
    private static function guard($challenge, $skip_turnstile = false) {
        $g = TW_Guard::check('public', $challenge, $skip_turnstile);
        if ($g !== true) self::fail($g, 'guard', 403);
    }

    /**
     * Step 1: the serial alone. Bot checks (honeypot, time trap, Turnstile) and a per-IP limit come first,
     * so the form can't be used to sweep serial numbers. A valid, unregistered serial gets a signed pass
     * that lets the next steps (email, "send a new code") go ahead without a second challenge.
     */
    public static function ajax_reg_check() {
        self::guard(true);
        if (!TW_Guard::throttle('serial_check', 30, HOUR_IN_SECONDS)) {
            self::fail('Πάρα πολλές αναζητήσεις σειριακών αριθμών. Δοκιμάστε ξανά σε λίγο.', '', 429);
        }
        $serial = self::usable_serial(self::input('serial'));
        wp_send_json_success([
            'serial' => $serial->serial,
            'product' => trim($serial->product_name . ($serial->product_code ? ' (' . $serial->product_code . ')' : '')),
            'pass' => TW_Guard::pass('serial|' . TW_Util::normalize_serial($serial->serial)),
        ]);
    }

    public static function ajax_reg_start() {
        $email = self::email_input();
        // Only after step 1: the pass proves the serial was checked (with the bot checks) in the last 30 minutes.
        if (!TW_Guard::pass_ok(self::input('pass'), 'serial|' . TW_Util::normalize_serial(self::input('serial')))) {
            self::fail('Η σελίδα έμεινε ανοιχτή πολύ ώρα. Ξεκινήστε ξανά από τον σειριακό αριθμό.', 'restart', 403);
        }
        self::guard(true, true); // honeypot and form stamp
        $serial = self::usable_serial(self::input('serial'));
        $rl = TW_OTP::rate_limit_ok($email);
        if (is_wp_error($rl)) self::fail($rl->get_error_message(), '', 429);

        [, $code] = TW_OTP::create('register', $email, $serial->id);
        $vars = [
            'company_name' => TW_Settings::get('company_name'),
            'company_contact' => TW_Settings::get('company_contact'),
            'serial' => $serial->serial,
            'code' => $code,
            'minutes' => TW_OTP::ttl_minutes(),
        ];
        $sent = TW_Messenger::send_template_email($email, 'registration_otp', $vars);
        if ($sent !== true) {
            TW_DB::log_event('otp_email_failed', $sent, 0, $serial->id, 'system');
            self::fail('Δεν ήταν δυνατή η αποστολή του email. Δοκιμάστε ξανά αργότερα.', '', 500);
        }
        TW_DB::log_event('otp_sent', ['email' => TW_Util::mask_email($email)], 0, $serial->id, 'customer:' . $email);
        wp_send_json_success([
            'message' => sprintf('Στείλαμε έναν 6ψήφιο κωδικό επαλήθευσης στο %s. Ισχύει για %d λεπτά.', TW_Util::mask_email($email), TW_OTP::ttl_minutes()),
            'serial' => $serial->serial,
            'ttl' => TW_OTP::ttl_minutes() * MINUTE_IN_SECONDS,
        ]);
    }

    public static function ajax_reg_verify() {
        self::guard(false);
        $email = self::email_input();
        $serial = self::usable_serial(self::input('serial'));
        $res = TW_OTP::verify('register', $email, self::input('code'), $serial->id);
        if (is_wp_error($res)) self::fail($res->get_error_message(), 'code');
        $terms = TW_Settings::current_terms();
        wp_send_json_success([
            'token' => $res['token'],
            'product' => trim($serial->product_name . ($serial->product_code ? ' (' . $serial->product_code . ')' : '')),
            'serial' => $serial->serial,
            'email' => $email,
            'terms_version' => (int) $terms['version'],
        ]);
    }

    public static function ajax_reg_submit() {
        self::guard(true);
        $otp = TW_OTP::token_row('register', self::input('token'));
        if (!$otp) self::fail('Η επαλήθευση έληξε. Ξεκινήστε ξανά από τον σειριακό αριθμό.', 'token', 401);
        $serial = TW_Warranty::serial_by_id($otp->serial_id);
        if (!$serial || $serial->status !== 'available') self::fail('Ο σειριακός αριθμός έχει ήδη καταχωρηθεί.', 'serial', 409);

        // ---- validation (server side is authoritative; the browser checks are only for convenience)
        $name = sanitize_text_field(self::input('customer_name'));
        if (mb_strlen($name) < 3 || strpos($name, ' ') === false) self::fail('Συμπληρώστε ονοματεπώνυμο.', 'customer_name');

        $mobile = TW_Util::clean_phone(self::input('phone_mobile'));
        if (!TW_Util::valid_mobile($mobile)) self::fail('Συμπληρώστε έγκυρο κινητό τηλέφωνο (π.χ. 69XXXXXXXX).', 'phone_mobile');

        $landline_raw = self::input('phone_landline');
        $landline = TW_Util::clean_phone($landline_raw);
        if ($landline_raw !== '' && $landline === '') self::fail('Το σταθερό τηλέφωνο δεν είναι έγκυρο.', 'phone_landline');

        $address = sanitize_text_field(self::input('address'));
        if (mb_strlen($address) < 3) self::fail('Συμπληρώστε τη διεύθυνση.', 'address');
        $city = sanitize_text_field(self::input('city'));
        $postcode = preg_replace('/\s+/', '', self::input('postcode'));
        if (!preg_match('/^\d{5}$/', $postcode)) self::fail('Ο ταχυδρομικός κώδικας πρέπει να έχει 5 ψηφία.', 'postcode');

        $vat = preg_replace('/\D/', '', self::input('partner_vat'));
        if (!TW_Util::valid_afm($vat)) self::fail('Ο ΑΦΜ συνεργάτη δεν είναι έγκυρος.', 'partner_vat');
        $partner_email = strtolower(sanitize_email(self::input('partner_email')));
        if (!is_email($partner_email)) self::fail('Συμπληρώστε έγκυρο email συνεργάτη.', 'partner_email');

        $invoice_number = sanitize_text_field(self::input('invoice_number'));
        if ($invoice_number === '') self::fail('Συμπληρώστε τον αριθμό του παραστατικού αγοράς.', 'invoice_number');

        $purchase = self::input('purchase_date');
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $purchase);
        if (!$d || $d->format('Y-m-d') !== $purchase) self::fail('Συμπληρώστε την ημερομηνία αγοράς.', 'purchase_date');
        if ($purchase > TW_Schedule::today()) self::fail('Η ημερομηνία αγοράς δεν μπορεί να είναι μελλοντική.', 'purchase_date');
        if ($purchase < '2000-01-01') self::fail('Η ημερομηνία αγοράς δεν είναι έγκυρη.', 'purchase_date');
        $max_delay = (int) TW_Settings::get('max_registration_delay_days');
        if ($max_delay > 0 && TW_Schedule::diff_days($purchase, TW_Schedule::today()) > $max_delay) {
            self::fail(sprintf('Η ενεργοποίηση πρέπει να γίνει μέσα σε %d ημέρες από την αγορά. Επικοινωνήστε μαζί μας.', $max_delay), 'purchase_date');
        }

        $consents = [
            'terms' => self::input('consent_terms') === '1',
            'data_accuracy' => self::input('consent_accuracy') === '1',
            'marketing' => self::input('consent_marketing') === '1',
        ];
        if (!$consents['terms']) self::fail('Για να ολοκληρωθεί η ενεργοποίηση πρέπει να αποδεχθείτε τους όρους εγγύησης.', 'consent_terms');
        if (!$consents['data_accuracy']) self::fail('Επιβεβαιώστε ότι τα στοιχεία είναι σωστά.', 'consent_accuracy');
        $terms = TW_Settings::current_terms();
        if ((int) self::input('terms_version') !== (int) $terms['version']) {
            self::fail('Οι όροι εγγύησης ενημερώθηκαν στο μεταξύ. Ανανεώστε τη σελίδα, διαβάστε τους και αποδεχθείτε τους ξανά.', 'consent_terms', 409);
        }

        $invoice = ['file' => '', 'name' => ''];
        $invoice_mode = TW_Settings::invoice_mode();
        if ($invoice_mode !== 'off' && !empty($_FILES['invoice_file']['name'])) {
            $invoice = TW_Util::store_invoice($_FILES['invoice_file']);
            if (is_wp_error($invoice)) self::fail($invoice->get_error_message(), 'invoice_file');
        } elseif ($invoice_mode === 'required') {
            self::fail('Επισυνάψτε το παραστατικό αγοράς (PDF, JPG ή PNG).', 'invoice_file');
        }

        $w = TW_Warranty::activate($serial->id, [
            'customer_name' => $name,
            'email' => $otp->email,
            'phone_landline' => $landline,
            'phone_mobile' => $mobile,
            'address' => $address,
            'postcode' => $postcode,
            'city' => $city,
            'partner_vat' => $vat,
            'partner_email' => $partner_email,
            'invoice_number' => $invoice_number,
            'invoice_file' => $invoice['file'],
            'invoice_original_name' => $invoice['name'],
            'purchase_date' => $purchase,
        ], $consents);
        if (is_wp_error($w)) {
            if ($invoice['file']) @unlink(TW_Util::invoice_path($invoice['file']));
            self::fail($w->get_error_message(), 'serial', 409);
        }
        TW_OTP::mark_used($otp->id);

        $vars = TW_Messenger::warranty_vars($w);
        $sent = TW_Messenger::send_template_email($w->email, 'activation', $vars);
        TW_Cron::log_message($w->id, 'activation', 'email', $w->email, $sent);
        TW_DB::log_event($sent === true ? 'activation_email_sent' : 'activation_email_failed', $sent === true ? '' : $sent, $w->id, $w->serial_id, 'system');
        $sms = TW_Messenger::send_template_sms($w->phone_mobile, 'activation', $vars);
        if ($sms !== null) TW_Cron::log_message($w->id, 'activation', 'sms', $w->phone_mobile, $sms);
        if ($sms !== null) TW_DB::log_event($sms === true ? 'activation_sms_sent' : 'activation_sms_failed', $sms === true ? '' : $sms, $w->id, $w->serial_id, 'system');
        do_action('tw_warranty_activated', $w);

        $next = TW_Warranty::next_service($w);
        wp_send_json_success([
            'warranty_code' => $w->warranty_code,
            'serial' => $w->serial,
            'product' => $w->product_name,
            'warranty_end' => TW_Util::date_label($w->warranty_end),
            'next_service' => $next ? TW_Util::date_label($next['due']) : '',
            'email' => TW_Util::mask_email($w->email),
            'portal_url' => (string) TW_Settings::get('portal_url') ?: TW_App::public_url('my'),
            'card_url' => add_query_arg(['card' => self::card_token($w->id), 'print' => 1], TW_App::public_url()),
        ]);
    }

    // ------------------------------------------------------------------ printable card after activation

    const CARD_TTL = 2 * HOUR_IN_SECONDS;

    /** Signed, short-lived link to the printable card of a warranty just activated in this browser. */
    public static function card_token($warranty_id) {
        $exp = time() + self::CARD_TTL;
        return (int) $warranty_id . '.' . $exp . '.' . substr(hash_hmac('sha256', 'tw-card|' . (int) $warranty_id . '|' . $exp, wp_salt('auth')), 0, 32);
    }

    /** @return int warranty id, or 0 if the link is invalid or expired */
    public static function card_warranty_id($token) {
        $p = explode('.', (string) $token);
        if (count($p) !== 3 || !ctype_digit($p[0]) || !ctype_digit($p[1]) || (int) $p[1] < time()) return 0;
        $expect = substr(hash_hmac('sha256', 'tw-card|' . (int) $p[0] . '|' . (int) $p[1], wp_salt('auth')), 0, 32);
        return hash_equals($expect, $p[2]) ? (int) $p[0] : 0;
    }

    // ------------------------------------------------------------------ portal sign-in

    public static function ajax_login_start() {
        $email = self::email_input();
        self::guard(true);
        $generic = sprintf('Αν υπάρχουν εγγυήσεις με το email %s, θα λάβετε σε λίγο έναν κωδικό σύνδεσης.', TW_Util::mask_email($email));
        $rl = TW_OTP::rate_limit_ok($email);
        if (is_wp_error($rl)) self::fail($rl->get_error_message(), '', 429);
        if (!TW_Warranty::by_email($email)) {
            // Same answer whether or not the email exists, so the form can't be used to discover customers.
            // A throwaway row keeps the rate limit counting for unknown addresses too.
            TW_OTP::create('login_unknown', $email, 0);
            wp_send_json_success(['message' => $generic, 'ttl' => TW_OTP::ttl_minutes() * MINUTE_IN_SECONDS]);
        }
        [, $code] = TW_OTP::create('login', $email, 0);
        TW_Messenger::send_template_email($email, 'login_otp', [
            'company_name' => TW_Settings::get('company_name'),
            'company_contact' => TW_Settings::get('company_contact'),
            'code' => $code,
            'minutes' => TW_OTP::ttl_minutes(),
        ]);
        wp_send_json_success(['message' => $generic, 'ttl' => TW_OTP::ttl_minutes() * MINUTE_IN_SECONDS]);
    }

    public static function ajax_login_verify() {
        self::guard(false);
        $email = self::email_input();
        $res = TW_OTP::verify('login', $email, self::input('code'), 0);
        if (is_wp_error($res)) self::fail($res->get_error_message(), 'code');
        self::set_cookie($res['token'], time() + TW_OTP::TOKEN_TTL_MINUTES * MINUTE_IN_SECONDS);
        foreach (TW_Warranty::by_email($email) as $w) {
            TW_DB::log_event('portal_login', '', $w->id, $w->serial_id, 'customer:' . $email);
        }
        wp_send_json_success(['message' => 'ok']);
    }

    private static function set_cookie($value, $expires) {
        $opts = [
            'expires' => $expires,
            'path' => COOKIEPATH ?: '/',
            'domain' => COOKIE_DOMAIN ?: '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Strict',
        ];
        setcookie(self::COOKIE, $value, $opts);
    }

    /** @return object|null the login row of the signed-in customer */
    public static function session() {
        $token = isset($_COOKIE[self::COOKIE]) ? (string) $_COOKIE[self::COOKIE] : '';
        return $token ? TW_OTP::token_row('login', $token) : null;
    }

    /** CSRF token bound to the session. */
    private static function csrf($session) {
        return substr(hash_hmac('sha256', 'csrf' . $session->id . $session->token_hash, wp_salt('nonce')), 0, 32);
    }

    private static function require_session() {
        $s = self::session();
        if (!$s) self::fail('Η σύνδεση έληξε. Συνδεθείτε ξανά.', 'session', 401);
        if (!hash_equals(self::csrf($s), self::input('csrf'))) self::fail('Μη έγκυρο αίτημα. Ανανεώστε τη σελίδα.', 'csrf', 403);
        return $s;
    }

    public static function ajax_logout() {
        $s = self::session();
        if ($s) TW_OTP::mark_used($s->id);
        self::set_cookie('', time() - 3600);
        wp_send_json_success();
    }

    public static function ajax_portal() {
        $s = self::session();
        if (!$s) wp_send_json_success(['signed_in' => false]);
        $warranties = TW_Warranty::by_email($s->email);
        $csrf = self::csrf($s);
        $email = $s->email;
        ob_start();
        include TW_DIR . 'templates/portal-content.php';
        wp_send_json_success(['signed_in' => true, 'html' => ob_get_clean(), 'csrf' => $csrf]);
    }

    private static function owned_warranty($session) {
        $w = TW_Warranty::get((int) self::input('warranty_id'));
        if (!$w || $w->email !== $session->email || $w->anonymized_at) self::fail('Η εγγύηση δεν βρέθηκε.', '', 404);
        return $w;
    }

    public static function ajax_portal_marketing() {
        $s = self::require_session();
        $w = self::owned_warranty($s);
        $granted = self::input('granted') === '1';
        if ((bool) $w->marketing_opt_in !== $granted) {
            TW_Warranty::set_marketing($w, $granted, 'portal');
        }
        wp_send_json_success(['message' => $granted ? 'Η συγκατάθεσή σας καταχωρήθηκε.' : 'Η συγκατάθεσή σας ανακλήθηκε. Δεν θα λαμβάνετε εμπορικές ενημερώσεις.']);
    }

    /** Customer submits the Service of a milestone: date, technician ΑΦΜ, receipt. */
    public static function ajax_portal_service() {
        $s = self::require_session();
        self::guard(false);
        $w = self::owned_warranty($s);
        $no = (int) self::input('milestone_no');
        $m = null;
        foreach (TW_Warranty::timeline($w) as $row) {
            if ($row['type'] === 'service' && $row['no'] === $no) $m = $row;
        }
        if (!$m || !$m['can_submit']) self::fail('Η υποβολή για αυτό το Service δεν είναι διαθέσιμη. Ανανεώστε τη σελίδα.', '', 409);

        $date = self::input('performed_date');
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) self::fail('Συμπληρώστε την ημερομηνία του Service.', 'performed_date');
        if ($date > TW_Schedule::today()) self::fail('Η ημερομηνία του Service δεν μπορεί να είναι μελλοντική.', 'performed_date');
        $earliest = TW_Schedule::add_days($m['due'], -max(0, (int) TW_Settings::get('service_submit_window_days')));
        if ($date < $earliest) {
            self::fail(sprintf('Για το %dο Service δεκτές είναι ημερομηνίες από %s και μετά.', $no, TW_Util::date_label($earliest)), 'performed_date');
        }
        $vat = preg_replace('/\D/', '', self::input('technician_vat'));
        if (!TW_Util::valid_afm($vat)) self::fail('Ο ΑΦΜ τεχνικού δεν είναι έγκυρος.', 'technician_vat');
        $receipt = ['file' => '', 'name' => '', 'number' => '', 'date' => null];
        if (TW_Settings::service_needs_fields()) {
            $receipt['number'] = sanitize_text_field(self::input('receipt_number'));
            if ($receipt['number'] === '') self::fail('Συμπληρώστε τον αριθμό της απόδειξης.', 'receipt_number');
            $rd = self::input('receipt_date');
            $rdo = DateTimeImmutable::createFromFormat('!Y-m-d', $rd);
            if (!$rdo || $rdo->format('Y-m-d') !== $rd) self::fail('Συμπληρώστε την ημερομηνία του παραστατικού.', 'receipt_date');
            if ($rd > TW_Schedule::today()) self::fail('Η ημερομηνία του παραστατικού δεν μπορεί να είναι μελλοντική.', 'receipt_date');
            if ($rd < $earliest) self::fail(sprintf('Η ημερομηνία του παραστατικού πρέπει να είναι από %s και μετά.', TW_Util::date_label($earliest)), 'receipt_date');
            $receipt['date'] = $rd;
        }
        if (self::input('genuine_parts') !== '1') self::fail('Επιβεβαιώστε ότι χρησιμοποιήθηκαν αυθεντικά ανταλλακτικά.', 'genuine_parts');
        if (TW_Settings::service_needs_file()) {
            if (empty($_FILES['receipt_file']['name'])) self::fail('Επισυνάψτε την απόδειξη του Service (PDF, JPG ή PNG).', 'receipt_file');
            $file = TW_Util::store_invoice($_FILES['receipt_file'], 'της απόδειξης');
            if (is_wp_error($file)) self::fail($file->get_error_message(), 'receipt_file');
            $receipt['file'] = $file['file'];
            $receipt['name'] = $file['name'];
        }

        $row = TW_Warranty::submit_service($w, $no, $date, $vat, sanitize_text_field(self::input('technician')), $receipt, sanitize_textarea_field(self::input('notes')));
        $vars = TW_Warranty::service_vars($w, $row);
        TW_Messenger::send_template_email($w->email, 'service_received', $vars);
        $link = TW_App::admin_url('warranties', ['view' => $w->id]);
        foreach (TW_Settings::get('service_notify_company') ? TW_Messenger::admin_recipients() : [] as $to) {
            TW_Messenger::send_email($to, sprintf('[Εγγυήσεις] Νέα υποβολή Service – S/N %s', $w->serial), sprintf(
                "Ο πελάτης %s καταχώρησε το %dο Service.\n\nS/N: %s\nΚωδικός εγγύησης: %s\nΗμερομηνία Service: %s\nΑΦΜ τεχνικού: %s\n\nΚαρτέλα εγγύησης: %s",
                $w->customer_name, $no, $w->serial, $w->warranty_code, TW_Util::date_label($date), $vat, $link));
        }
        do_action('tw_service_submitted', $w, $row);
        wp_send_json_success(['message' => sprintf('Το %dο Service καταχωρήθηκε και η εγγύηση συνεχίζει να ισχύει. Σας στείλαμε επιβεβαίωση με email· κρατήστε και την πρωτότυπη απόδειξη.', $no)]);
    }

    public static function ajax_portal_export() {
        $s = self::require_session();
        $data = TW_Privacy::customer_data($s->email);
        TW_DB::log_event('portal_data_export', '', 0, 0, 'customer:' . $s->email);
        wp_send_json_success(['filename' => 'thermic-warranty-data.json', 'data' => $data]);
    }

    public static function ajax_portal_erase() {
        $s = self::require_session();
        $req = wp_create_user_request($s->email, 'remove_personal_data', [], 'pending');
        if (is_wp_error($req)) {
            if ($req->get_error_code() === 'duplicate_request') {
                wp_send_json_success(['message' => 'Υπάρχει ήδη αίτημα διαγραφής σε εξέλιξη για το email σας.']);
            }
            self::fail('Δεν ήταν δυνατή η καταχώρηση του αιτήματος. Επικοινωνήστε μαζί μας.');
        }
        wp_send_user_request($req);
        TW_DB::log_event('erasure_requested', '', 0, 0, 'customer:' . $s->email);
        wp_send_json_success(['message' => 'Στείλαμε email επιβεβαίωσης για το αίτημα διαγραφής. Μετά την επιβεβαίωση θα εξεταστεί από την εταιρεία. Στοιχεία ενεργών εγγυήσεων διατηρούνται όσο διαρκεί η εγγύηση, ώστε να μπορεί να εξυπηρετηθεί.']);
    }
}
