<?php
if (!defined('ABSPATH')) exit;

/**
 * Brevo integration: transactional email and SMS through the Brevo API, and delivery reports
 * (webhooks) that update each message's delivery status.
 *
 * Email:  POST /v3/smtp/email            → messageId "<…@smtp-relay…>"; reports via a transactional webhook
 * SMS:    POST /v3/transactionalSMS/send  → messageId (number); reports via the per-message webUrl
 * Both kinds of report arrive at the same REST endpoint, protected by a secret token in the URL.
 * Brevo's payloads differ between documents (event / msg_status, message-id / messageId, snake_case /
 * camelCase), so the parser accepts all of them and the last payloads are kept for diagnostics.
 */
final class TW_Brevo {
    const API = 'https://api.brevo.com/v3/';
    const LOG_OPTION = 'tw_brevo_webhook_log';
    const ROUTE_NS = 'thermic-warranty/v1';

    public static function init() {
        add_action('rest_api_init', function () {
            register_rest_route(self::ROUTE_NS, '/brevo', [
                'methods' => ['POST'],
                'callback' => [__CLASS__, 'rest_webhook'],
                'permission_callback' => [__CLASS__, 'rest_permission'],
            ]);
        });
    }

    // ------------------------------------------------------------------ settings helpers

    public static function api_key() {
        return trim((string) TW_Settings::get('brevo_api_key'));
    }

    public static function email_enabled() {
        return TW_Settings::get('brevo_email') && self::api_key() !== '';
    }

    public static function sms_enabled() {
        return TW_Settings::get('brevo_sms') && self::api_key() !== '';
    }

    public static function token() {
        $t = (string) TW_Settings::get('brevo_webhook_token');
        if ($t === '') {
            $t = bin2hex(random_bytes(20));
            TW_Settings::update(['brevo_webhook_token' => $t]);
        }
        return $t;
    }

    public static function webhook_url() {
        return add_query_arg('token', self::token(), rest_url(self::ROUTE_NS . '/brevo'));
    }

    // ------------------------------------------------------------------ API

    /** @return array|WP_Error decoded body */
    private static function request($method, $path, array $body = null) {
        $key = self::api_key();
        if ($key === '') return new WP_Error('brevo', 'Δεν έχει οριστεί API key του Brevo.');
        $args = [
            'method' => $method,
            'timeout' => 20,
            'headers' => ['api-key' => $key, 'accept' => 'application/json', 'content-type' => 'application/json'],
        ];
        if ($body !== null) $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE);
        $res = wp_remote_request(self::API . ltrim($path, '/'), $args);
        if (is_wp_error($res)) return new WP_Error('brevo', 'Brevo: ' . $res->get_error_message());
        $code = (int) wp_remote_retrieve_response_code($res);
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($data) ? trim(($data['code'] ?? '') . ' ' . ($data['message'] ?? '')) : '';
            return new WP_Error('brevo', sprintf('Brevo HTTP %d%s', $code, $msg !== '' ? ': ' . $msg : ''));
        }
        return is_array($data) ? $data : [];
    }

    /** @return true|string, with the Brevo message id in TW_Messenger::$last_id */
    public static function send_email($to, $subject, $html, $text) {
        $s = TW_Settings::all();
        $from = is_email($s['brevo_sender_email']) ? $s['brevo_sender_email'] : (is_email($s['email_from_address']) ? $s['email_from_address'] : get_option('admin_email'));
        $body = [
            'sender' => ['email' => $from, 'name' => $s['brevo_sender_name'] ?: ($s['email_from_name'] ?: $s['company_name'])],
            'to' => [['email' => $to]],
            'subject' => $subject,
            'htmlContent' => $html,
            'textContent' => $text,
            'tags' => ['thermic-warranty'],
        ];
        if (is_email($s['brevo_reply_to'])) $body['replyTo'] = ['email' => $s['brevo_reply_to']];
        $r = self::request('POST', 'smtp/email', $body);
        if (is_wp_error($r)) return $r->get_error_message();
        TW_Messenger::$last_id = self::norm_id($r['messageId'] ?? '');
        return true;
    }

    /** @return true|string, with the Brevo message id in TW_Messenger::$last_id */
    public static function send_sms($to_e164, $text) {
        $s = TW_Settings::all();
        $r = self::request('POST', 'transactionalSMS/send', [
            'sender' => mb_substr($s['brevo_sms_sender'] ?: $s['sms_sender'], 0, 11),
            'recipient' => ltrim($to_e164, '+'),
            'content' => $text,
            'type' => 'transactional',
            'tag' => 'thermic-warranty',
            'unicodeEnabled' => (bool) preg_match('/[^\x00-\x7F]/', $text),
            'webUrl' => self::webhook_url(),
        ]);
        if (is_wp_error($r)) return $r->get_error_message();
        TW_Messenger::$last_id = self::norm_id($r['messageId'] ?? ($r['reference'] ?? ''));
        return true;
    }

    /** @return array|WP_Error ['email' =>, 'company' =>, 'sms_credits' =>, 'email_plan' =>] */
    public static function account() {
        $r = self::request('GET', 'account');
        if (is_wp_error($r)) return $r;
        $sms = null; $plans = [];
        foreach ((array) ($r['plan'] ?? []) as $p) {
            if (($p['type'] ?? '') === 'sms') $sms = $p['credits'] ?? null;
            else $plans[] = ($p['type'] ?? '') . (isset($p['credits']) ? ' (' . $p['credits'] . ')' : '');
        }
        return ['email' => $r['email'] ?? '', 'company' => $r['companyName'] ?? '', 'sms_credits' => $sms, 'email_plan' => implode(', ', array_filter($plans))];
    }

    /** Creates (or reuses) the transactional email webhook in the Brevo account. @return true|string */
    public static function ensure_email_webhook() {
        $url = self::webhook_url();
        $list = self::request('GET', 'webhooks?type=transactional');
        if (!is_wp_error($list)) {
            foreach ((array) ($list['webhooks'] ?? []) as $h) {
                if (($h['url'] ?? '') === $url) return true;
            }
        }
        $r = self::request('POST', 'webhooks', [
            'url' => $url,
            'description' => 'Thermic Warranty – αναφορές παράδοσης',
            'events' => ['delivered', 'hardBounce', 'softBounce', 'blocked', 'spam', 'invalid', 'deferred', 'error'],
            'type' => 'transactional',
        ]);
        return is_wp_error($r) ? $r->get_error_message() : true;
    }

    private static function norm_id($id) {
        return trim((string) $id, " <>\t\n");
    }

    // ------------------------------------------------------------------ webhooks

    public static function rest_permission(WP_REST_Request $req) {
        $t = (string) $req->get_param('token');
        return $t !== '' && hash_equals(self::token(), $t);
    }

    public static function rest_webhook(WP_REST_Request $req) {
        $payload = $req->get_json_params();
        if (!is_array($payload) || !$payload) $payload = $req->get_body_params();
        // Brevo may batch events in an array.
        $events = isset($payload[0]) && is_array($payload[0]) ? $payload : [$payload];
        $out = [];
        foreach ($events as $e) {
            if (is_array($e)) $out[] = self::handle_event($e);
        }
        return new WP_REST_Response(['ok' => true, 'matched' => count(array_filter($out))], 200);
    }

    /** Maps a Brevo status to ours: delivered | deferred | failed | complaint | sent | '' (ignored). */
    public static function map_status($raw) {
        $k = strtolower(str_replace(['_', '-', ' '], '', (string) $raw));
        $map = [
            'delivered' => 'delivered',
            'hardbounce' => 'failed', 'hardbounced' => 'failed', 'invalid' => 'failed', 'invalidemail' => 'failed',
            'blocked' => 'failed', 'error' => 'failed', 'rejected' => 'failed', 'blacklisted' => 'failed', 'skip' => 'failed',
            'softbounce' => 'deferred', 'softbounced' => 'deferred', 'deferred' => 'deferred',
            'spam' => 'complaint', 'complaint' => 'complaint',
            'sent' => 'sent', 'request' => 'sent', 'accepted' => 'sent',
        ];
        return $map[$k] ?? '';
    }

    public static function status_labels() {
        return [
            'sent' => 'Στάλθηκε – αναμένεται επιβεβαίωση',
            'delivered' => 'Παραδόθηκε',
            'deferred' => 'Καθυστέρηση παράδοσης',
            'failed' => 'Δεν παραδόθηκε',
            'complaint' => 'Σημειώθηκε ως spam',
        ];
    }

    /** @return bool whether the event matched a message of ours */
    public static function handle_event(array $e) {
        global $wpdb;
        self::log_payload($e);
        $raw = $e['event'] ?? ($e['msg_status'] ?? ($e['status'] ?? ''));
        $status = self::map_status($raw);
        $id = self::norm_id($e['message-id'] ?? ($e['messageId'] ?? ($e['message_id'] ?? ($e['reference'] ?? ''))));
        if ($status === '' || $id === '') return false;
        $t = TW_DB::table('notifications');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE provider_id = %s ORDER BY id DESC LIMIT 1", $id));
        if (!$row) return false;

        // Never let a later "sent"/"deferred" overwrite a final result.
        $rank = ['' => 0, 'sent' => 1, 'deferred' => 2, 'delivered' => 3, 'complaint' => 4, 'failed' => 4];
        if (($rank[$status] ?? 0) < ($rank[$row->delivery_status] ?? 0)) return true;

        $detail = (string) ($e['reason'] ?? ($e['description'] ?? ($e['error'] ?? '')));
        if ($detail === '' && $status !== 'delivered' && $status !== 'sent') $detail = (string) $raw;
        if (isset($e['ts_event'])) $when = (int) $e['ts_event'];
        elseif (isset($e['ts_epoch'])) $when = (int) floor($e['ts_epoch'] / 1000);
        elseif (!empty($e['date']) && ($t2 = strtotime((string) $e['date']))) $when = $t2;
        else $when = time();
        $wpdb->update($t, [
            'delivery_status' => $status,
            'delivery_detail' => mb_substr($detail, 0, 255),
            'delivery_at' => wp_date('Y-m-d H:i:s', $when ?: time()),
        ], ['id' => $row->id]);

        if (in_array($status, ['failed', 'complaint'], true) && !in_array($row->delivery_status, ['failed', 'complaint'], true)) {
            $w = TW_Warranty::get($row->warranty_id);
            if ($w) {
                TW_DB::log_event($row->channel === 'sms' ? 'sms_undeliverable' : 'email_undeliverable', ['to' => $row->recipient, 'reason' => $detail ?: $raw], $w->id, $w->serial_id, 'system:brevo');
                if ($status === 'failed') TW_Cron::fallback($w, $row);
            }
        }
        return true;
    }

    private static function log_payload(array $e) {
        $log = get_option(self::LOG_OPTION, []);
        if (!is_array($log)) $log = [];
        array_unshift($log, ['at' => TW_DB::now(), 'data' => $e]);
        update_option(self::LOG_OPTION, array_slice($log, 0, 30), false);
    }
}
