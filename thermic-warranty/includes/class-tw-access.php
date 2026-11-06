<?php
if (!defined('ABSPATH')) exit;

/**
 * IP restriction for the Manager console (sign-in page included).
 *
 * Only WordPress administrators (manage_options) can change it, from wp-admin → Warranty Manager →
 * «Πρόσβαση με IP». wp-admin itself is never restricted, so an administrator can always switch it off.
 * Emergency switch: define('TW_DISABLE_IP_LOCK', true) in wp-config.php.
 *
 * The visitor's IP comes from REMOTE_ADDR, or – when the site sits behind Cloudflare or another reverse
 * proxy – from the header that proxy adds (Cloudflare's header is trusted only from Cloudflare's own ranges).
 */
final class TW_Access {
    const PAGE = 'tw-ip-access';

    /** Cloudflare edge ranges (https://www.cloudflare.com/ips/). */
    const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
        '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
    }

    // ------------------------------------------------------------------ IP helpers

    /** Visitor IP for a given proxy mode: '' (direct) | 'cloudflare' | 'proxy'. */
    public static function ip_for_mode($mode) {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if (!filter_var($remote, FILTER_VALIDATE_IP)) return '';
        if ($mode === 'cloudflare') {
            $cf = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']) : '';
            if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) && self::in_list($remote, self::CLOUDFLARE)) return $cf;
        } elseif ($mode === 'proxy') {
            // The right-most entry is the one added by the proxy in front of the site; earlier ones can be forged.
            $xff = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
            $parts = array_values(array_filter(array_map('trim', explode(',', $xff))));
            $last = $parts ? end($parts) : '';
            if ($last !== '' && filter_var($last, FILTER_VALIDATE_IP)) return $last;
        }
        return $remote;
    }

    public static function visitor_ip() {
        return self::ip_for_mode((string) TW_Settings::get('ip_proxy'));
    }

    /** True if $ip matches one of the entries (single IPs or CIDR ranges, IPv4 or IPv6). */
    public static function in_list($ip, array $list) {
        $bin = @inet_pton($ip);
        if ($bin === false) return false;
        foreach ($list as $entry) {
            $entry = trim($entry);
            if ($entry === '') continue;
            [$net, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $nb = @inet_pton($net);
            if ($nb === false || strlen($nb) !== strlen($bin)) continue;
            $max = strlen($bin) * 8;
            $bits = $bits === null ? $max : (int) $bits;
            if ($bits < 0 || $bits > $max) continue;
            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($bin, 0, $bytes) !== substr($nb, 0, $bytes)) continue;
            if ($rest) {
                $mask = (0xFF << (8 - $rest)) & 0xFF;
                if ((ord($bin[$bytes]) & $mask) !== (ord($nb[$bytes]) & $mask)) continue;
            }
            return true;
        }
        return false;
    }

    /** Parses the textarea: one entry per line, "# comment" allowed. @return array [entries, invalid lines] */
    public static function parse($text) {
        $ok = []; $bad = [];
        foreach (preg_split('/\r?\n/', (string) $text) as $line) {
            $v = trim(preg_replace('/#.*$/', '', $line));
            if ($v === '') continue;
            [$net, $bits] = array_pad(explode('/', $v, 2), 2, null);
            $valid = filter_var($net, FILTER_VALIDATE_IP) && ($bits === null || (ctype_digit($bits) && (int) $bits <= (strpos($net, ':') !== false ? 128 : 32)));
            if ($valid) $ok[] = $v; else $bad[] = $v;
        }
        return [$ok, $bad];
    }

    public static function allowed_list() {
        return self::parse(TW_Settings::get('ip_allow'))[0];
    }

    public static function disabled_by_constant() {
        return defined('TW_DISABLE_IP_LOCK') && TW_DISABLE_IP_LOCK;
    }

    public static function active() {
        return !self::disabled_by_constant() && TW_Settings::get('ip_lock') && self::allowed_list();
    }

    /** Whether the current visitor may open the Manager. */
    public static function allowed() {
        if (!self::active()) return true;
        $ip = self::visitor_ip();
        return $ip !== '' && self::in_list($ip, self::allowed_list());
    }

    /** Stops the request with a plain page when the visitor's network is not allowed. */
    public static function enforce() {
        if (self::allowed()) return;
        $ip = self::visitor_ip();
        $key = 'tw_ip_block_log';
        $log = get_option($key, []);
        if (!is_array($log)) $log = [];
        array_unshift($log, ['at' => TW_DB::now(), 'ip' => $ip, 'path' => mb_substr(strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?'), 0, 120)]);
        update_option($key, array_slice($log, 0, 50), false);
        TW_DB::log_event('access_blocked', ['reason' => 'IP εκτός λίστας'], 0, 0, 'visitor');
        status_header(403);
        nocache_headers();
        $s = TW_Settings::all();
        include TW_DIR . 'templates/app-blocked.php';
        exit;
    }

    public static function admin_page_url() {
        return admin_url('admin.php?page=' . self::PAGE);
    }

    // ------------------------------------------------------------------ wp-admin page

    public static function menu() {
        $hook = add_submenu_page('tw-app', 'Πρόσβαση με IP – ' . TW_App::APP_NAME, 'Πρόσβαση με IP', 'manage_options', self::PAGE, [__CLASS__, 'page']);
        add_action('load-' . $hook, [__CLASS__, 'save']);
    }

    public static function save() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !current_user_can('manage_options')) return;
        check_admin_referer('tw_ip_access');
        $text = isset($_POST['ip_allow']) ? sanitize_textarea_field(wp_unslash($_POST['ip_allow'])) : '';
        [$ok, $bad] = self::parse($text);
        $mode = isset($_POST['ip_proxy']) ? sanitize_key(wp_unslash($_POST['ip_proxy'])) : '';
        if (!in_array($mode, ['', 'cloudflare', 'proxy'], true)) $mode = '';
        $lock = !empty($_POST['ip_lock']) ? 1 : 0;
        $msg = []; $type = 'success';
        if ($bad) {
            $type = 'error';
            $msg[] = 'Μη έγκυρες γραμμές (δεν αποθηκεύτηκαν): ' . implode(', ', $bad) . '.';
            $text = implode("\n", array_filter(preg_split('/\r?\n/', $text), function ($l) use ($bad) {
                return !in_array(trim(preg_replace('/#.*$/', '', $l)), $bad, true);
            }));
        }
        if ($lock && !$ok) {
            $lock = 0; $type = 'error';
            $msg[] = 'Για να ενεργοποιηθεί ο περιορισμός, συμπληρώστε τουλάχιστον μία IP.';
        }
        $before = (int) TW_Settings::get('ip_lock');
        TW_Settings::update(['ip_lock' => $lock, 'ip_allow' => $text, 'ip_proxy' => $mode]);
        $me = self::ip_for_mode($mode);
        if ($lock && !self::in_list($me, $ok)) {
            $type = $type === 'error' ? 'error' : 'warning';
            $msg[] = sprintf('Προσοχή: η τρέχουσα IP σας (%s) δεν είναι στη λίστα, οπότε από εδώ δεν θα ανοίγει το %s. Η διαχείριση του WordPress δεν επηρεάζεται.', $me, TW_App::APP_NAME);
        }
        if ($before !== $lock) {
            TW_DB::log_event($lock ? 'ip_lock_on' : 'ip_lock_off', ['ips' => implode(', ', $ok)], 0, 0, 'admin:' . wp_get_current_user()->user_login);
        } else {
            TW_DB::log_event('ip_lock_changed', ['ips' => implode(', ', $ok)], 0, 0, 'admin:' . wp_get_current_user()->user_login);
        }
        array_unshift($msg, $lock ? 'Αποθηκεύτηκε. Ο περιορισμός IP είναι ενεργός.' : 'Αποθηκεύτηκε. Ο περιορισμός IP είναι ανενεργός.');
        set_transient('tw_ip_notice_' . get_current_user_id(), ['type' => $type, 'text' => implode(' ', $msg)], 60);
        wp_safe_redirect(self::admin_page_url());
        exit;
    }

    public static function page() {
        if (!current_user_can('manage_options')) return;
        $s = TW_Settings::all();
        $notice = get_transient('tw_ip_notice_' . get_current_user_id());
        delete_transient('tw_ip_notice_' . get_current_user_id());
        $modes = [
            '' => 'Απευθείας σύνδεση (προεπιλογή)',
            'cloudflare' => 'Η ιστοσελίδα είναι πίσω από Cloudflare (proxy, πορτοκαλί σύννεφο)',
            'proxy' => 'Άλλος reverse proxy / load balancer (X-Forwarded-For)',
        ];
        $log = array_slice((array) get_option('tw_ip_block_log', []), 0, 10);
        ?>
        <div class="wrap">
            <h1>Πρόσβαση στο <?php echo esc_html(TW_App::APP_NAME); ?> με IP</h1>
            <?php if ($notice) : ?><div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['text']); ?></p></div><?php endif; ?>
            <?php if (self::disabled_by_constant()) : ?><div class="notice notice-warning"><p>Ο περιορισμός είναι απενεργοποιημένος από το <code>wp-config.php</code> (<code>TW_DISABLE_IP_LOCK</code>).</p></div><?php endif; ?>
            <p style="max-width:780px">Όταν είναι ενεργός, το <?php echo esc_html(TW_App::APP_NAME); ?> (μαζί με τη σελίδα σύνδεσης) ανοίγει μόνο από τις διευθύνσεις IP της λίστας, π.χ. τη σταθερή (static) IP του γραφείου. Από οποιαδήποτε άλλη σύνδεση εμφανίζεται μήνυμα ότι η πρόσβαση δεν επιτρέπεται. Οι σελίδες των πελατών (ενεργοποίηση, «Οι εγγυήσεις μου») δεν επηρεάζονται. Τη ρύθμιση την αλλάζουν μόνο διαχειριστές του WordPress, από αυτή τη σελίδα, η οποία δεν περιορίζεται ποτέ.</p>
            <form method="post">
                <?php wp_nonce_field('tw_ip_access'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">Περιορισμός</th><td><label><input type="checkbox" name="ip_lock" value="1" <?php checked((int) $s['ip_lock'], 1); ?>> Πρόσβαση στο <?php echo esc_html(TW_App::APP_NAME); ?> μόνο από τις παρακάτω IP</label></td></tr>
                    <tr><th scope="row"><label for="tw-ip-allow">Επιτρεπόμενες IP</label></th><td>
                        <textarea id="tw-ip-allow" name="ip_allow" rows="6" class="large-text code" placeholder="85.72.10.20   # Γραφείο Αθήνας&#10;2a02:587:1234::/48"><?php echo esc_textarea($s['ip_allow']); ?></textarea>
                        <p class="description">Μία ανά γραμμή. Δεκτές: μεμονωμένες IPv4/IPv6 ή εύρη (CIDR, π.χ. <code>85.72.10.0/24</code>). Κείμενο μετά το <code>#</code> είναι σχόλιο.</p>
                        <p><button type="button" class="button" id="tw-ip-add" data-ip="<?php echo esc_attr(self::visitor_ip()); ?>">Προσθήκη της τρέχουσας IP μου (<?php echo esc_html(self::visitor_ip()); ?>)</button></p>
                    </td></tr>
                    <tr><th scope="row"><label for="tw-ip-proxy">Σύνδεση της ιστοσελίδας</label></th><td>
                        <select id="tw-ip-proxy" name="ip_proxy">
                            <?php foreach ($modes as $k => $l) printf('<option value="%s"%s>%s – βλέπει την IP σας ως %s</option>', esc_attr($k), selected($s['ip_proxy'], $k, false), esc_html($l), esc_html(self::ip_for_mode($k) ?: '—')); ?>
                        </select>
                        <p class="description">Αφήστε την προεπιλογή, εκτός αν η ιστοσελίδα περνά από Cloudflare ή άλλον proxy: τότε η «απευθείας» IP είναι του proxy, όχι του επισκέπτη. Επιλέξτε τη ρύθμιση που δείχνει τη δική σας πραγματική IP (μπορείτε να τη δείτε π.χ. στο <a href="https://www.whatismyip.com/" target="_blank" rel="noopener">whatismyip.com</a>).</p>
                    </td></tr>
                </table>
                <?php submit_button('Αποθήκευση'); ?>
            </form>
            <h2>Σε περίπτωση ανάγκης</h2>
            <p>Αν αλλάξει η IP του γραφείου, ενημερώστε τη λίστα από εδώ. Αν δεν έχετε πρόσβαση ούτε στη διαχείριση του WordPress, προσθέστε στο <code>wp-config.php</code> τη γραμμή <code>define('TW_DISABLE_IP_LOCK', true);</code>.</p>
            <?php if ($log) : ?>
                <h2>Τελευταίες απορρίψεις</h2>
                <table class="widefat striped" style="max-width:780px"><thead><tr><th>Ημερομηνία</th><th>IP</th></tr></thead><tbody>
                <?php foreach ($log as $r) printf('<tr><td>%s</td><td><code>%s</code></td></tr>', esc_html(TW_Util::date_label(substr($r['at'], 0, 10)) . ' ' . substr($r['at'], 11, 5)), esc_html($r['ip'])); ?>
                </tbody></table>
            <?php endif; ?>
            <script>
            document.getElementById('tw-ip-add').addEventListener('click', function () {
                var t = document.getElementById('tw-ip-allow'), ip = this.getAttribute('data-ip');
                if (!ip || t.value.split(/\n/).some(function (l) { return l.replace(/#.*/, '').trim() === ip; })) return;
                t.value = (t.value.replace(/\s+$/, '') + (t.value.trim() ? '\n' : '') + ip + '   # ' + new Date().toLocaleDateString('el-GR')).trim();
            });
            </script>
        </div>
        <?php
    }
}
