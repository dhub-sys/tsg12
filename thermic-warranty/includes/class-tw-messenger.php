<?php
if (!defined('ABSPATH')) exit;

/**
 * Email (wp_mail, so any SMTP plugin applies) and SMS (configurable HTTP gateway or the tw_send_sms filter).
 */
final class TW_Messenger {
    /** Provider message id of the last email/SMS sent (Brevo), used to match delivery reports. */
    public static $last_id = null;

    /** SMS goes out through Brevo or through the generic gateway. */
    public static function sms_active() {
        return TW_Brevo::sms_enabled() || (bool) TW_Settings::get('sms_enabled');
    }


    public static function render($text, array $vars) {
        $repl = [];
        foreach ($vars as $k => $v) {
            $repl['{' . $k . '}'] = (string) $v;
        }
        return strtr((string) $text, $repl);
    }

    /** Placeholders available for a warranty. */
    public static function warranty_vars($w, array $extra = []) {
        $s = TW_Settings::all();
        $vars = [
            'company_name' => $s['company_name'],
            'company_contact' => $s['company_contact'],
            'portal_url' => $s['portal_url'] ?: TW_App::public_url('my'),
            'customer_name' => $w->customer_name,
            'email' => $w->email,
            'serial' => $w->serial,
            'product_name' => $w->product_name ?: $w->product_code,
            'product_code' => $w->product_code,
            'warranty_code' => $w->warranty_code,
            'purchase_date' => TW_Util::date_label($w->purchase_date),
            'warranty_end' => TW_Util::date_label($w->warranty_end),
            'partner_email' => $w->partner_email,
            'service_schedule' => TW_Schedule::service_schedule_text($w->purchase_date),
            'service_proof' => TW_Settings::service_proof_text(),
        ];
        return array_merge($vars, $extra);
    }

    /** Sender shown on every email (Settings → Αποστολέας); the name falls back to the company name. */
    public static function sender() {
        $s = TW_Settings::all();
        $name = trim((string) $s['email_from_name']) ?: trim((string) $s['company_name']);
        $addr = is_email($s['email_from_address']) ? $s['email_from_address'] : '';
        $reply = is_email($s['email_reply_to']) ? $s['email_reply_to'] : '';
        return ['name' => str_replace(['"', "\r", "\n"], '', $name), 'email' => $addr, 'reply_to' => $reply];
    }

    public static function from_headers() {
        $snd = self::sender();
        $h = ['Content-Type: text/html; charset=UTF-8'];
        if ($snd['email']) $h[] = 'From: ' . ($snd['name'] !== '' ? '"' . $snd['name'] . '" ' : '') . '<' . $snd['email'] . '>';
        if ($snd['reply_to']) $h[] = 'Reply-To: ' . $snd['reply_to'];
        return $h;
    }

    public static function html_wrap($subject, $body_text) {
        $company = esc_html((string) TW_Settings::get('company_name'));
        $body = nl2br(make_clickable(esc_html($body_text)));
        // Codes on their own line are shown large.
        $body = preg_replace('/(^|<br \/>\s*)([A-Z0-9]{6}|TW-[A-Z0-9\-]+)(\s*<br \/>|$)/', '$1<strong style="font-size:22px;letter-spacing:3px">$2</strong>$3', $body);
        return '<!doctype html><html><body style="margin:0;background:#f4f5f7;font-family:Geom,Segoe UI,Arial,Helvetica,sans-serif;color:#1f2933">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:8px">'
            . '<tr><td style="padding:20px 28px;border-bottom:3px solid ' . esc_attr(TW_App::colors()[0]) . ';font-size:20px;font-weight:bold">' . $company . '</td></tr>'
            . '<tr><td style="padding:24px 28px;font-size:15px;line-height:1.55">' . $body . '</td></tr>'
            . '<tr><td style="padding:16px 28px;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb">Το μήνυμα αφορά την εγγύηση και τη συντήρηση του προϊόντος σας και δεν αποτελεί εμπορική επικοινωνία.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** @return true|string true or error message */
    public static function send_email($to, $subject, $body_text) {
        self::$last_id = null;
        if (TW_Brevo::email_enabled()) {
            return TW_Brevo::send_email($to, $subject, self::html_wrap($subject, $body_text), $body_text);
        }
        $err = '';
        $catch = function ($e) use (&$err) { $err = $e->get_error_message(); };
        // Without a sender address WordPress uses wordpress@domain with the name "WordPress": at least show our name.
        $snd = self::sender();
        $name = function ($n) use ($snd) { return $snd['name'] !== '' && ($n === 'WordPress' || $n === '') ? $snd['name'] : $n; };
        add_action('wp_mail_failed', $catch);
        add_filter('wp_mail_from_name', $name, 20);
        try {
            $ok = wp_mail($to, $subject, self::html_wrap($subject, $body_text), self::from_headers());
        } finally {
            remove_filter('wp_mail_from_name', $name, 20);
            remove_action('wp_mail_failed', $catch);
        }
        return $ok ? true : ($err ?: 'wp_mail failed');
    }

    public static function send_template_email($to, $key, array $vars) {
        $t = TW_Settings::template($key);
        return self::send_email($to, self::render($t['subject'], $vars), self::render($t['body'], $vars));
    }

    /** @return true|string|null null when SMS is disabled or the template is empty */
    public static function send_template_sms($phone, $key, array $vars) {
        $t = TW_Settings::template($key);
        if (!self::sms_active() || trim($t['sms']) === '') return null;
        return self::send_sms($phone, self::render($t['sms'], $vars));
    }

    /** @return true|string */
    public static function send_sms($phone, $text) {
        self::$last_id = null;
        $to = TW_Util::e164($phone);
        if ($to === '') return 'Μη έγκυρος αριθμός κινητού';
        if (TW_Brevo::sms_enabled()) return TW_Brevo::send_sms($to, $text);

        // Custom integrations (e.g. a specific Greek SMS provider SDK) can take over here.
        $pre = apply_filters('tw_send_sms', null, $to, $text);
        if ($pre !== null) return $pre === true ? true : (is_wp_error($pre) ? $pre->get_error_message() : (string) $pre);

        $s = TW_Settings::all();
        if (!$s['sms_url']) return 'Δεν έχει οριστεί SMS gateway';
        $headers = [];
        foreach (preg_split('/\r?\n/', (string) $s['sms_headers']) as $line) {
            if (strpos($line, ':') === false) continue;
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            if ($k !== '') $headers[$k] = $v;
        }
        $is_json = stripos($headers['Content-Type'] ?? '', 'json') !== false;
        $enc = function ($v) use ($is_json) {
            return $is_json ? substr(wp_json_encode((string) $v, JSON_UNESCAPED_UNICODE), 1, -1) : rawurlencode((string) $v);
        };
        $vars = ['to' => $enc($to), 'to_no_plus' => $enc(ltrim($to, '+')), 'text' => $enc($text), 'sender' => $enc($s['sms_sender'])];
        $url = self::render($s['sms_url'], [
            'to' => rawurlencode($to), 'to_no_plus' => rawurlencode(ltrim($to, '+')),
            'text' => rawurlencode($text), 'sender' => rawurlencode($s['sms_sender']),
        ]);
        $args = ['timeout' => 15, 'headers' => $headers, 'method' => strtoupper($s['sms_method']) === 'GET' ? 'GET' : 'POST'];
        if ($args['method'] === 'POST') {
            $args['body'] = self::render($s['sms_body'], $vars);
        }
        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) return $res->get_error_message();
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            return 'HTTP ' . $code . ': ' . mb_substr(wp_remote_retrieve_body($res), 0, 300);
        }
        return true;
    }

    public static function admin_recipients() {
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string) TW_Settings::get('admin_emails')) as $e) {
            if (is_email($e)) $out[] = $e;
        }
        return $out;
    }
}
