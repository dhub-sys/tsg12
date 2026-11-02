<?php
if (!defined('ABSPATH')) exit;

/**
 * Email (wp_mail, so any SMTP plugin applies) and SMS (configurable HTTP gateway or the tw_send_sms filter).
 */
final class TW_Messenger {

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
            'portal_url' => $s['portal_url'] ?: home_url('/'),
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
        ];
        return array_merge($vars, $extra);
    }

    public static function from_headers() {
        $name = (string) TW_Settings::get('email_from_name');
        $addr = (string) TW_Settings::get('email_from_address');
        $h = ['Content-Type: text/html; charset=UTF-8'];
        if ($addr && is_email($addr)) {
            $h[] = 'From: ' . ($name ? '"' . str_replace('"', '', $name) . '" ' : '') . '<' . $addr . '>';
        }
        return $h;
    }

    public static function html_wrap($subject, $body_text) {
        $company = esc_html((string) TW_Settings::get('company_name'));
        $body = nl2br(make_clickable(esc_html($body_text)));
        // Codes on their own line are shown large.
        $body = preg_replace('/(^|<br \/>\s*)([A-Z0-9]{6}|TW-[A-Z0-9\-]+)(\s*<br \/>|$)/', '$1<strong style="font-size:22px;letter-spacing:3px">$2</strong>$3', $body);
        return '<!doctype html><html><body style="margin:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2933">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:8px">'
            . '<tr><td style="padding:20px 28px;border-bottom:3px solid #c8102e;font-size:20px;font-weight:bold">' . $company . '</td></tr>'
            . '<tr><td style="padding:24px 28px;font-size:15px;line-height:1.55">' . $body . '</td></tr>'
            . '<tr><td style="padding:16px 28px;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb">Το μήνυμα αφορά την εγγύηση και τη συντήρηση του προϊόντος σας και δεν αποτελεί εμπορική επικοινωνία.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** @return true|string true or error message */
    public static function send_email($to, $subject, $body_text) {
        $err = '';
        $catch = function ($e) use (&$err) { $err = $e->get_error_message(); };
        add_action('wp_mail_failed', $catch);
        $ok = wp_mail($to, $subject, self::html_wrap($subject, $body_text), self::from_headers());
        remove_action('wp_mail_failed', $catch);
        return $ok ? true : ($err ?: 'wp_mail failed');
    }

    public static function send_template_email($to, $key, array $vars) {
        $t = TW_Settings::template($key);
        return self::send_email($to, self::render($t['subject'], $vars), self::render($t['body'], $vars));
    }

    /** @return true|string|null null when SMS is disabled or the template is empty */
    public static function send_template_sms($phone, $key, array $vars) {
        $t = TW_Settings::template($key);
        if (!TW_Settings::get('sms_enabled') || trim($t['sms']) === '') return null;
        return self::send_sms($phone, self::render($t['sms'], $vars));
    }

    /** @return true|string */
    public static function send_sms($phone, $text) {
        $to = TW_Util::e164($phone);
        if ($to === '') return 'Μη έγκυρος αριθμός κινητού';

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
