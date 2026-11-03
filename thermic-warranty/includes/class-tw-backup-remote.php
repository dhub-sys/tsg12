<?php
if (!defined('ABSPATH')) exit;

/**
 * Off-site copies of the backups: Google Drive, OneDrive, Dropbox or FTP/FTPS.
 *
 * Cloud services use OAuth 2.0 with the company's own app credentials (client id/secret) and a
 * refresh token kept in a separate, non-autoloaded option (never written to a backup). Files are
 * uploaded in 10 MiB chunks (resumable/session uploads), so large backups do not need much memory.
 * Only encrypted backups are sent: they contain customers' personal data.
 */
final class TW_Backup_Remote {
    const TOKENS = 'tw_remote_tokens';
    const STATUS = 'tw_remote_status';
    const SENT = 'tw_remote_sent';
    const CHUNK = 10485760; // 10 MiB: a multiple of 256 KiB (Drive) and 320 KiB (OneDrive)

    public static function services() {
        return [
            'none' => 'Καμία (μόνο στον server)',
            'gdrive' => 'Google Drive',
            'onedrive' => 'OneDrive',
            'dropbox' => 'Dropbox',
            'ftp' => 'FTP / FTPS',
        ];
    }

    public static function init() {
        add_action('rest_api_init', function () {
            register_rest_route(TW_Brevo::ROUTE_NS, '/oauth', [
                'methods' => ['GET'],
                'callback' => [__CLASS__, 'oauth_callback'],
                'permission_callback' => '__return_true', // protected by the single-use state value
            ]);
        });
    }

    public static function service() {
        $s = (string) TW_Settings::get('backup_remote');
        return isset(self::services()[$s]) ? $s : 'none';
    }

    public static function enabled() {
        return self::service() !== 'none';
    }

    public static function label($service = null) {
        return self::services()[$service ?: self::service()] ?? '';
    }

    public static function redirect_uri() {
        return rest_url(TW_Brevo::ROUTE_NS . '/oauth');
    }

    public static function folder() {
        $f = trim((string) TW_Settings::get('backup_remote_folder'), " /");
        return $f !== '' ? $f : 'Warranty Manager Backups';
    }

    // ------------------------------------------------------------------ OAuth

    private static function oauth_config($service) {
        $s = TW_Settings::all();
        $tenant = preg_replace('/[^a-zA-Z0-9\.\-]/', '', (string) $s['backup_onedrive_tenant']) ?: 'common';
        $cfg = [
            'gdrive' => [
                'auth' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token' => 'https://oauth2.googleapis.com/token',
                'scope' => 'https://www.googleapis.com/auth/drive.file',
                'extra' => ['access_type' => 'offline', 'prompt' => 'consent'],
                'id' => $s['backup_gdrive_client_id'], 'secret' => $s['backup_gdrive_client_secret'],
            ],
            'onedrive' => [
                'auth' => "https://login.microsoftonline.com/$tenant/oauth2/v2.0/authorize",
                'token' => "https://login.microsoftonline.com/$tenant/oauth2/v2.0/token",
                'scope' => 'Files.ReadWrite offline_access User.Read',
                'extra' => [],
                'id' => $s['backup_onedrive_client_id'], 'secret' => $s['backup_onedrive_client_secret'],
            ],
            'dropbox' => [
                'auth' => 'https://www.dropbox.com/oauth2/authorize',
                'token' => 'https://api.dropboxapi.com/oauth2/token',
                'scope' => '',
                'extra' => ['token_access_type' => 'offline'],
                'id' => $s['backup_dropbox_app_key'], 'secret' => $s['backup_dropbox_app_secret'],
            ],
        ];
        return $cfg[$service] ?? null;
    }

    /** @return string|WP_Error URL to send the administrator to */
    public static function auth_url($service) {
        $c = self::oauth_config($service);
        if (!$c) return new WP_Error('svc', 'Η υπηρεσία δεν χρειάζεται σύνδεση.');
        if (trim($c['id']) === '' || trim($c['secret']) === '') return new WP_Error('cfg', 'Συμπληρώστε και αποθηκεύστε πρώτα το Client ID και το Client secret.');
        $state = bin2hex(random_bytes(24));
        set_transient('tw_oauth_' . $state, ['service' => $service, 'user' => get_current_user_id()], 15 * MINUTE_IN_SECONDS);
        $q = array_merge([
            'client_id' => trim($c['id']),
            'redirect_uri' => self::redirect_uri(),
            'response_type' => 'code',
            'state' => $state,
        ], $c['scope'] !== '' ? ['scope' => $c['scope']] : [], $c['extra']);
        return $c['auth'] . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }

    public static function oauth_callback(WP_REST_Request $req) {
        $state = (string) $req->get_param('state');
        $data = preg_match('/^[a-f0-9]{48}$/', $state) ? get_transient('tw_oauth_' . $state) : false;
        if ($state !== '') delete_transient('tw_oauth_' . $state);
        $back = TW_App::admin_url('backups');
        if (!$data) {
            wp_redirect($back);
            exit;
        }
        $notice = function ($text, $type) use ($data) {
            set_transient('tw_notice_' . (int) $data['user'], ['text' => $text, 'type' => $type], 300);
        };
        if ($req->get_param('error') || !$req->get_param('code')) {
            $desc = (string) ($req->get_param('error_description') ?: $req->get_param('error'));
            $hint = self::error_hint($data['service'], $desc);
            $notice($hint !== '' ? esc_html($hint) : 'Η σύνδεση με ' . esc_html(self::label($data['service'])) . ' ακυρώθηκε: ' . esc_html($desc), 'error');
            wp_redirect($back);
            exit;
        }
        $r = self::token_request($data['service'], ['grant_type' => 'authorization_code', 'code' => (string) $req->get_param('code'), 'redirect_uri' => self::redirect_uri()]);
        if (is_wp_error($r)) {
            $notice('Η σύνδεση απέτυχε: ' . esc_html($r->get_error_message()), 'error');
        } elseif (empty($r['refresh_token'])) {
            $notice('Η υπηρεσία δεν έδωσε μόνιμη πρόσβαση (refresh token). Αφαιρέστε την πρόσβαση της εφαρμογής από τον λογαριασμό και συνδεθείτε ξανά.', 'error');
        } else {
            self::save_token($data['service'], $r);
            $account = self::account($data['service']);
            $tokens = self::tokens();
            $tokens[$data['service']]['account'] = is_wp_error($account) ? '' : $account;
            update_option(self::TOKENS, $tokens, false);
            TW_DB::log_event('remote_connected', ['service' => $data['service'], 'account' => is_wp_error($account) ? '' : $account], 0, 0, 'admin:' . (get_userdata($data['user'])->user_login ?? $data['user']));
            $notice('Συνδέθηκε: ' . esc_html(self::label($data['service'])) . (is_wp_error($account) || !$account ? '' : ' (' . esc_html($account) . ')') . '.', 'success');
        }
        wp_redirect($back);
        exit;
    }

    /** Plain-language explanation for the provider errors administrators actually run into. */
    private static function error_hint($service, $desc) {
        if ($service !== 'onedrive') return '';
        if (stripos($desc, 'multi-tenant') !== false || stripos($desc, 'AADSTS50194') !== false) {
            return 'Η εφαρμογή στο Microsoft Entra είναι «Single tenant»: στο πεδίο «Tenant» βάλτε το Directory (tenant) ID της εταιρείας (Entra → App registrations → η εφαρμογή → Overview) αντί για «common», αποθηκεύστε και πατήστε ξανά «Σύνδεση με OneDrive».';
        }
        if (stripos($desc, 'AADSTS50011') !== false || stripos($desc, 'redirect') !== false) {
            return 'Η διεύθυνση επιστροφής (Redirect URI) δεν ταιριάζει: στο Entra → η εφαρμογή → Authentication προσθέστε ακριβώς τη διεύθυνση που δείχνει η πλατφόρμα (τύπου «Web»).';
        }
        if (stripos($desc, 'AADSTS65001') !== false || stripos($desc, 'consent') !== false) {
            return 'Χρειάζεται έγκριση διαχειριστή: στο Entra → η εφαρμογή → API permissions πατήστε «Grant admin consent» και δοκιμάστε ξανά.';
        }
        if (stripos($desc, 'AADSTS7000215') !== false || stripos($desc, 'AADSTS7000222') !== false) {
            return 'Το Client secret είναι λάθος ή έχει λήξει: δημιουργήστε νέο (Certificates & secrets) και βάλτε τη στήλη «Value».';
        }
        return '';
    }

    private static function token_request($service, array $params) {
        $c = self::oauth_config($service);
        $body = array_merge($params, ['client_id' => trim($c['id']), 'client_secret' => trim($c['secret'])]);
        if ($service === 'onedrive') $body['scope'] = $c['scope'];
        $res = wp_remote_post($c['token'], ['timeout' => 20, 'body' => $body]);
        if (is_wp_error($res)) return $res;
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200 || !is_array($data) || empty($data['access_token'])) {
            $msg = is_array($data) ? (string) ($data['error_description'] ?? ($data['error'] ?? '')) : '';
            $hint = self::error_hint($service, $msg);
            return new WP_Error('oauth', $hint !== '' ? $hint : sprintf('HTTP %d %s', $code, $msg));
        }
        return $data;
    }

    public static function tokens() {
        $t = get_option(self::TOKENS, []);
        return is_array($t) ? $t : [];
    }

    private static function save_token($service, array $r) {
        $tokens = self::tokens();
        $old = $tokens[$service] ?? [];
        $tokens[$service] = [
            'access' => $r['access_token'],
            'refresh' => $r['refresh_token'] ?? ($old['refresh'] ?? ''),
            'expires' => time() + (int) ($r['expires_in'] ?? 3600),
            'account' => $old['account'] ?? '',
            'connected_at' => $old['connected_at'] ?? TW_DB::now(),
        ];
        if (empty($old)) $tokens[$service]['connected_at'] = TW_DB::now();
        update_option(self::TOKENS, $tokens, false);
    }

    public static function disconnect($service) {
        $tokens = self::tokens();
        unset($tokens[$service]);
        update_option(self::TOKENS, $tokens, false);
        if ($service === 'gdrive') TW_Settings::update(['backup_gdrive_folder_id' => '']);
    }

    public static function connected($service) {
        return !empty(self::tokens()[$service]['refresh']);
    }

    /** @return string|WP_Error */
    private static function access_token($service) {
        $t = self::tokens()[$service] ?? null;
        if (!$t || empty($t['refresh'])) return new WP_Error('auth', 'Δεν έχει γίνει σύνδεση με ' . self::label($service) . '.');
        if (!empty($t['access']) && (int) $t['expires'] > time() + 60) return $t['access'];
        $r = self::token_request($service, ['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh']]);
        if (is_wp_error($r)) return new WP_Error('auth', 'Η σύνδεση με ' . self::label($service) . ' έληξε ή ανακλήθηκε (' . $r->get_error_message() . '). Συνδεθείτε ξανά.');
        self::save_token($service, $r);
        return $r['access_token'];
    }

    /** JSON request to a cloud API. @return array|WP_Error ['code', 'body' (decoded), 'headers'] */
    private static function api($service, $method, $url, $body = null, array $headers = [], $raw = false) {
        $token = self::access_token($service);
        if (is_wp_error($token)) return $token;
        $h = array_merge(['Authorization' => 'Bearer ' . $token], $headers);
        $args = ['method' => $method, 'timeout' => 60, 'headers' => $h];
        if ($body !== null) {
            if ($raw) {
                $args['body'] = $body;
            } else {
                $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE);
                if (!isset($h['Content-Type'])) $args['headers']['Content-Type'] = 'application/json';
            }
        }
        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) return $res;
        return [
            'code' => (int) wp_remote_retrieve_response_code($res),
            'body' => json_decode((string) wp_remote_retrieve_body($res), true),
            'text' => (string) wp_remote_retrieve_body($res),
            'headers' => wp_remote_retrieve_headers($res),
        ];
    }

    private static function api_error($r, $what) {
        if (is_wp_error($r)) return $r;
        $b = $r['body'];
        $msg = is_array($b) ? (string) ($b['error']['message'] ?? ($b['error_summary'] ?? ($b['error_description'] ?? (is_string($b['error'] ?? null) ? $b['error'] : '')))) : '';
        return new WP_Error('remote', sprintf('%s: HTTP %d %s', $what, $r['code'], $msg));
    }

    /** @return string|WP_Error account e-mail / name */
    public static function account($service) {
        switch ($service) {
            case 'gdrive':
                $r = self::api('gdrive', 'GET', 'https://www.googleapis.com/drive/v3/about?fields=user(emailAddress,displayName)');
                return !is_wp_error($r) && $r['code'] === 200 ? (string) ($r['body']['user']['emailAddress'] ?? '') : self::api_error($r, 'Google Drive');
            case 'onedrive':
                $r = self::api('onedrive', 'GET', 'https://graph.microsoft.com/v1.0/me?$select=userPrincipalName,mail,displayName');
                return !is_wp_error($r) && $r['code'] === 200 ? (string) ($r['body']['mail'] ?? ($r['body']['userPrincipalName'] ?? '')) : self::api_error($r, 'OneDrive');
            case 'dropbox':
                $r = self::api('dropbox', 'POST', 'https://api.dropboxapi.com/2/users/get_current_account', 'null', ['Content-Type' => 'application/json'], true);
                return !is_wp_error($r) && $r['code'] === 200 ? (string) ($r['body']['email'] ?? '') : self::api_error($r, 'Dropbox');
        }
        return '';
    }

    // ------------------------------------------------------------------ public operations

    /**
     * Uploads one backup file to the selected service and applies remote retention.
     * @return true|WP_Error
     */
    public static function send($path, $force_service = null) {
        $service = $force_service ?: self::service();
        if ($service === 'none') return new WP_Error('none', 'Δεν έχει επιλεγεί υπηρεσία.');
        if (!is_file($path)) return new WP_Error('file', 'Το αρχείο δεν βρέθηκε.');
        $m = TW_Backup::manifest($path);
        if (strpos(basename($path), 'tw-test-') !== 0 && empty($m['encrypted'])) {
            return new WP_Error('encrypt', 'Για αποστολή εκτός server το αντίγραφο πρέπει να είναι κρυπτογραφημένο (περιέχει προσωπικά δεδομένα). Ορίστε κωδικό κρυπτογράφησης και δημιουργήστε νέο αντίγραφο.');
        }
        @set_time_limit(0);
        $name = basename($path);
        $r = call_user_func([__CLASS__, 'upload_' . $service], $path, $name);
        $ok = $r === true;
        update_option(self::STATUS, ['at' => TW_DB::now(), 'file' => $name, 'service' => $service, 'ok' => $ok, 'message' => $ok ? '' : $r->get_error_message()], false);
        if ($ok) {
            $sent = get_option(self::SENT, []);
            if (!is_array($sent)) $sent = [];
            $sent[$name] = ['service' => $service, 'at' => TW_DB::now()];
            $existing = array_flip(array_column(TW_Backup::all(), 'file'));
            update_option(self::SENT, array_intersect_key($sent, $existing + [$name => 1]), false);
            TW_DB::log_event('backup_sent', ['file' => $name, 'service' => $service], 0, 0, 'system');
            if (strpos($name, 'tw-test-') !== 0) self::prune($service);
        } else {
            TW_DB::log_event('backup_send_failed', ['file' => $name, 'service' => $service, 'error' => $r->get_error_message()], 0, 0, 'system');
        }
        return $r;
    }

    /** Uploads and removes a small test file. @return true|WP_Error */
    public static function test() {
        $service = self::service();
        $tmp = trailingslashit(get_temp_dir()) . 'tw-test-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($tmp, "Warranty Manager – δοκιμή σύνδεσης " . TW_DB::now() . "\n");
        $r = self::send($tmp, $service);
        if ($r === true) self::delete_remote($service, basename($tmp));
        @unlink($tmp);
        return $r;
    }

    public static function sent_info($file) {
        $sent = get_option(self::SENT, []);
        return is_array($sent) ? ($sent[$file] ?? null) : null;
    }

    /** Keeps the newest N automatic backups on the remote side. */
    private static function prune($service) {
        $keep = max(1, (int) TW_Settings::get('backup_remote_keep'));
        $files = self::list_remote($service);
        if (is_wp_error($files)) return;
        $auto = array_values(array_filter($files, function ($n) { return (bool) preg_match('/^tw-backup-\d{8}-\d{6}-auto-[a-f0-9]+\.zip$/', $n); }));
        rsort($auto);
        foreach (array_slice($auto, $keep) as $n) self::delete_remote($service, $n);
    }

    private static function chunks($path, callable $fn) {
        $size = filesize($path);
        $fh = fopen($path, 'rb');
        if (!$fh) return new WP_Error('file', 'Το αρχείο δεν ανοίγει.');
        $offset = 0;
        do {
            $data = $size > 0 ? fread($fh, self::CHUNK) : '';
            $last = $offset + strlen($data) >= $size;
            $r = $fn($data, $offset, $size, $last);
            if (is_wp_error($r)) { fclose($fh); return $r; }
            $offset += strlen($data);
        } while (!$last);
        fclose($fh);
        return $r;
    }

    // ------------------------------------------------------------------ Google Drive

    private static function gdrive_folder() {
        $id = (string) TW_Settings::get('backup_gdrive_folder_id');
        if ($id !== '') return $id;
        $name = self::folder();
        $q = "mimeType='application/vnd.google-apps.folder' and name='" . str_replace("'", "\\'", $name) . "' and trashed=false";
        $r = self::api('gdrive', 'GET', 'https://www.googleapis.com/drive/v3/files?fields=files(id,name)&q=' . rawurlencode($q));
        if (!is_wp_error($r) && $r['code'] === 200 && !empty($r['body']['files'][0]['id'])) {
            $id = $r['body']['files'][0]['id'];
        } else {
            $r = self::api('gdrive', 'POST', 'https://www.googleapis.com/drive/v3/files?fields=id', ['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder']);
            if (is_wp_error($r) || $r['code'] !== 200 || empty($r['body']['id'])) return self::api_error($r, 'Google Drive (φάκελος)');
            $id = $r['body']['id'];
        }
        TW_Settings::update(['backup_gdrive_folder_id' => $id]);
        return $id;
    }

    private static function upload_gdrive($path, $name) {
        $folder = self::gdrive_folder();
        if (is_wp_error($folder)) return $folder;
        $size = filesize($path);
        $init = self::api('gdrive', 'POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id',
            ['name' => $name, 'parents' => [$folder]],
            ['Content-Type' => 'application/json; charset=UTF-8', 'X-Upload-Content-Type' => 'application/octet-stream', 'X-Upload-Content-Length' => (string) $size]);
        $loc = !is_wp_error($init) ? ($init['headers']['location'] ?? '') : '';
        if (is_wp_error($init) || $init['code'] !== 200 || !$loc) return self::api_error($init, 'Google Drive (έναρξη)');
        $token = self::access_token('gdrive');
        if (is_wp_error($token)) return $token;
        $r = self::chunks($path, function ($data, $offset, $total, $last) use ($loc, $token) {
            $range = $total > 0 ? sprintf('bytes %d-%d/%d', $offset, $offset + strlen($data) - 1, $total) : 'bytes */0';
            $res = wp_remote_request($loc, ['method' => 'PUT', 'timeout' => 120, 'body' => $data,
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Length' => (string) strlen($data), 'Content-Range' => $range]]);
            if (is_wp_error($res)) return $res;
            $code = (int) wp_remote_retrieve_response_code($res);
            if ($last ? !in_array($code, [200, 201], true) : $code !== 308) return new WP_Error('remote', 'Google Drive (μεταφορά): HTTP ' . $code);
            return true;
        });
        return $r === true ? true : $r;
    }

    // ------------------------------------------------------------------ OneDrive

    private static function od_path($name = '') {
        $parts = array_filter(array_merge(explode('/', self::folder()), $name !== '' ? [$name] : []), 'strlen');
        return implode('/', array_map('rawurlencode', $parts));
    }

    private static function upload_onedrive($path, $name) {
        $s = self::api('onedrive', 'POST', 'https://graph.microsoft.com/v1.0/me/drive/root:/' . self::od_path($name) . ':/createUploadSession',
            ['item' => ['@microsoft.graph.conflictBehavior' => 'replace']]);
        if (is_wp_error($s) || $s['code'] !== 200 || empty($s['body']['uploadUrl'])) return self::api_error($s, 'OneDrive (έναρξη)');
        $url = $s['body']['uploadUrl'];
        return self::chunks($path, function ($data, $offset, $total, $last) use ($url) {
            // The upload URL is pre-authorised: no Authorization header.
            $res = wp_remote_request($url, ['method' => 'PUT', 'timeout' => 120, 'body' => $data,
                'headers' => ['Content-Length' => (string) strlen($data), 'Content-Range' => sprintf('bytes %d-%d/%d', $offset, $offset + max(0, strlen($data) - 1), max(1, $total))]]);
            if (is_wp_error($res)) return $res;
            $code = (int) wp_remote_retrieve_response_code($res);
            if ($last ? !in_array($code, [200, 201], true) : $code !== 202) return new WP_Error('remote', 'OneDrive (μεταφορά): HTTP ' . $code);
            return true;
        });
    }

    // ------------------------------------------------------------------ Dropbox

    private static function dbx_path($name = '') {
        return '/' . trim(self::folder(), '/') . ($name !== '' ? '/' . $name : '');
    }

    private static function dbx_content($endpoint, array $arg, $data) {
        $r = self::api('dropbox', 'POST', 'https://content.dropboxapi.com/2/files/' . $endpoint, $data,
            ['Content-Type' => 'application/octet-stream', 'Dropbox-API-Arg' => wp_json_encode($arg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)], true);
        if (is_wp_error($r) || $r['code'] !== 200) return self::api_error($r, 'Dropbox (' . $endpoint . ')');
        return $r['body'];
    }

    private static function upload_dropbox($path, $name) {
        // start (empty) → append every chunk → finish (empty) at the final offset
        $r = self::dbx_content('upload_session/start', ['close' => false], '');
        if (is_wp_error($r)) return $r;
        $session = (string) ($r['session_id'] ?? '');
        if ($session === '') return new WP_Error('remote', 'Dropbox: δεν δόθηκε session.');
        $sent = self::chunks($path, function ($data, $offset) use ($session) {
            if ($data === '') return true;
            $r = self::dbx_content('upload_session/append_v2', ['cursor' => ['session_id' => $session, 'offset' => $offset], 'close' => false], $data);
            return is_wp_error($r) ? $r : true;
        });
        if (is_wp_error($sent)) return $sent;
        $r = self::dbx_content('upload_session/finish', [
            'cursor' => ['session_id' => $session, 'offset' => filesize($path)],
            'commit' => ['path' => self::dbx_path($name), 'mode' => 'overwrite', 'autorename' => false, 'mute' => true],
        ], '');
        return is_wp_error($r) ? $r : true;
    }

    // ------------------------------------------------------------------ FTP / FTPS

    /** @return resource|object|WP_Error */
    private static function ftp_open() {
        if (!function_exists('ftp_connect')) return new WP_Error('ftp', 'Η επέκταση PHP FTP δεν είναι διαθέσιμη στον server.');
        $s = TW_Settings::all();
        $host = trim((string) $s['backup_ftp_host']);
        if ($host === '') return new WP_Error('ftp', 'Συμπληρώστε τον FTP server.');
        $port = (int) $s['backup_ftp_port'] ?: 21;
        $secure = $s['backup_ftp_mode'] === 'ftps';
        if ($secure && !function_exists('ftp_ssl_connect')) return new WP_Error('ftp', 'Το FTPS δεν υποστηρίζεται από την PHP του server.');
        $c = $secure ? @ftp_ssl_connect($host, $port, 20) : @ftp_connect($host, $port, 20);
        if (!$c) return new WP_Error('ftp', "Δεν ήταν δυνατή η σύνδεση στο $host:$port.");
        if (!@ftp_login($c, (string) $s['backup_ftp_user'], (string) $s['backup_ftp_password'])) { @ftp_close($c); return new WP_Error('ftp', 'Λάθος όνομα χρήστη ή κωδικός FTP.'); }
        @ftp_pasv($c, (bool) $s['backup_ftp_passive']);
        $dir = trim((string) $s['backup_ftp_path'], '/');
        if ($dir !== '') {
            foreach (explode('/', $dir) as $part) {
                if ($part === '' || $part === '.' || $part === '..') continue;
                if (!@ftp_chdir($c, $part)) {
                    if (!@ftp_mkdir($c, $part) || !@ftp_chdir($c, $part)) { @ftp_close($c); return new WP_Error('ftp', "Δεν ήταν δυνατή η δημιουργία του φακέλου «$dir» στον FTP."); }
                }
            }
        }
        return $c;
    }

    private static function upload_ftp($path, $name) {
        $c = self::ftp_open();
        if (is_wp_error($c)) return $c;
        $ok = @ftp_put($c, $name, $path, FTP_BINARY);
        @ftp_close($c);
        return $ok ? true : new WP_Error('ftp', 'Η μεταφορά στον FTP απέτυχε (χώρος ή δικαιώματα;).');
    }

    // ------------------------------------------------------------------ list / delete (retention, test)

    /** @return string[]|WP_Error file names in the remote folder */
    private static function list_remote($service) {
        switch ($service) {
            case 'gdrive':
                $folder = self::gdrive_folder();
                if (is_wp_error($folder)) return $folder;
                $q = "'" . $folder . "' in parents and trashed=false";
                $r = self::api('gdrive', 'GET', 'https://www.googleapis.com/drive/v3/files?pageSize=1000&fields=files(id,name)&q=' . rawurlencode($q));
                if (is_wp_error($r) || $r['code'] !== 200) return self::api_error($r, 'Google Drive');
                return array_column((array) ($r['body']['files'] ?? []), 'name');
            case 'onedrive':
                $r = self::api('onedrive', 'GET', 'https://graph.microsoft.com/v1.0/me/drive/root:/' . self::od_path() . ':/children?$select=name&$top=999');
                if (is_wp_error($r) || $r['code'] !== 200) return self::api_error($r, 'OneDrive');
                return array_column((array) ($r['body']['value'] ?? []), 'name');
            case 'dropbox':
                $r = self::api('dropbox', 'POST', 'https://api.dropboxapi.com/2/files/list_folder', ['path' => self::dbx_path(), 'limit' => 2000]);
                if (is_wp_error($r) || $r['code'] !== 200) return self::api_error($r, 'Dropbox');
                return array_column((array) ($r['body']['entries'] ?? []), 'name');
            case 'ftp':
                $c = self::ftp_open();
                if (is_wp_error($c)) return $c;
                $list = @ftp_nlist($c, '.');
                @ftp_close($c);
                return is_array($list) ? array_map('basename', $list) : [];
        }
        return [];
    }

    private static function delete_remote($service, $name) {
        switch ($service) {
            case 'gdrive':
                $folder = self::gdrive_folder();
                if (is_wp_error($folder)) return;
                $q = "'" . $folder . "' in parents and name='" . str_replace("'", "\\'", $name) . "' and trashed=false";
                $r = self::api('gdrive', 'GET', 'https://www.googleapis.com/drive/v3/files?fields=files(id)&q=' . rawurlencode($q));
                foreach (!is_wp_error($r) ? (array) ($r['body']['files'] ?? []) : [] as $f) {
                    self::api('gdrive', 'DELETE', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($f['id']));
                }
                return;
            case 'onedrive':
                self::api('onedrive', 'DELETE', 'https://graph.microsoft.com/v1.0/me/drive/root:/' . self::od_path($name));
                return;
            case 'dropbox':
                self::api('dropbox', 'POST', 'https://api.dropboxapi.com/2/files/delete_v2', ['path' => self::dbx_path($name)]);
                return;
            case 'ftp':
                $c = self::ftp_open();
                if (is_wp_error($c)) return;
                @ftp_delete($c, $name);
                @ftp_close($c);
                return;
        }
    }
}
