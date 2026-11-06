<?php
if (!defined('ABSPATH')) exit;

final class TW_Util {
    const UPLOAD_SUBDIR = 'thermic-warranty-private';

    public static function status_labels() {
        return [
            'active' => 'Ενεργή',
            'expired' => 'Έληξε',
            'locked' => 'Οριστικά κλειδωμένη',
            'void' => 'Ακυρωμένη',
        ];
    }

    public static function serial_status_labels() {
        return [
            'available' => 'Διαθέσιμος',
            'registered' => 'Καταχωρημένος',
            'void' => 'Ακυρωμένος',
        ];
    }

    public static function date_label($ymd) {
        if (!$ymd || $ymd === '0000-00-00') return '';
        $t = strtotime($ymd);
        return $t ? date('d/m/Y', $t) : $ymd;
    }

    public static function datetime_label($mysql) {
        if (!$mysql) return '';
        $t = strtotime($mysql);
        return $t ? date('d/m/Y H:i', $t) : $mysql;
    }

    public static function normalize_serial($s) {
        $s = strtoupper(trim((string) $s));
        return preg_replace('/\s+/', '', $s);
    }

    public static function client_ip() {
        // REMOTE_ADDR only: forwarded headers are spoofable unless the site sits behind a known proxy.
        // Behind Cloudflare / a reverse proxy (wp-admin → Πρόσβαση με IP) the visitor's IP comes from the proxy's header.
        $ip = class_exists('TW_Access') ? TW_Access::visitor_ip() : (isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '');
        $ip = apply_filters('tw_client_ip', $ip);
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    public static function user_agent() {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        return mb_substr($ua, 0, 255);
    }

    /** EU VAT number formats (after the country prefix). Greece uses the ΑΦΜ check digit instead. */
    const EU_VAT = [
        'AT' => 'U\d{8}', 'BE' => '[01]\d{9}', 'BG' => '\d{9,10}', 'CY' => '\d{8}[A-Z]', 'CZ' => '\d{8,10}', 'DE' => '\d{9}',
        'DK' => '\d{8}', 'EE' => '\d{9}', 'ES' => '[A-Z0-9]\d{7}[A-Z0-9]', 'FI' => '\d{8}', 'FR' => '[A-HJ-NP-Z0-9]{2}\d{9}',
        'HR' => '\d{11}', 'HU' => '\d{8}', 'IE' => '\d{7}[A-W][A-I]?|\d[A-Z+*]\d{5}[A-W]', 'IT' => '\d{11}', 'LT' => '\d{9}|\d{12}',
        'LU' => '\d{8}', 'LV' => '\d{11}', 'MT' => '\d{8}', 'NL' => '\d{9}B\d{2}', 'PL' => '\d{10}', 'PT' => '\d{9}', 'RO' => '\d{2,10}',
        'SE' => '\d{12}', 'SI' => '\d{8}', 'SK' => '\d{10}',
    ];

    /** Countries for partner / technician tax numbers: the likely ones first, then the EU, then others. */
    public static function countries() {
        return [
            'GR' => 'Ελλάδα', 'CY' => 'Κύπρος', 'AE' => 'Ηνωμένα Αραβικά Εμιράτα (Ντουμπάι κ.ά.)', 'QA' => 'Κατάρ',
            'SA' => 'Σαουδική Αραβία', 'KW' => 'Κουβέιτ', 'BH' => 'Μπαχρέιν', 'OM' => 'Ομάν',
            'BG' => 'Βουλγαρία', 'RO' => 'Ρουμανία', 'IT' => 'Ιταλία', 'DE' => 'Γερμανία', 'FR' => 'Γαλλία', 'ES' => 'Ισπανία',
            'PT' => 'Πορτογαλία', 'NL' => 'Ολλανδία', 'BE' => 'Βέλγιο', 'AT' => 'Αυστρία', 'IE' => 'Ιρλανδία', 'LU' => 'Λουξεμβούργο',
            'MT' => 'Μάλτα', 'HR' => 'Κροατία', 'SI' => 'Σλοβενία', 'SK' => 'Σλοβακία', 'CZ' => 'Τσεχία', 'PL' => 'Πολωνία',
            'HU' => 'Ουγγαρία', 'DK' => 'Δανία', 'SE' => 'Σουηδία', 'FI' => 'Φινλανδία', 'EE' => 'Εσθονία', 'LV' => 'Λετονία', 'LT' => 'Λιθουανία',
            'GB' => 'Ηνωμένο Βασίλειο', 'CH' => 'Ελβετία', 'AL' => 'Αλβανία', 'MK' => 'Βόρεια Μακεδονία', 'RS' => 'Σερβία', 'TR' => 'Τουρκία',
            'EG' => 'Αίγυπτος', 'LB' => 'Λίβανος', 'JO' => 'Ιορδανία', 'IL' => 'Ισραήλ', 'US' => 'ΗΠΑ', 'CA' => 'Καναδάς', 'AU' => 'Αυστραλία',
            'XX' => 'Άλλη χώρα',
        ];
    }

    /** What the tax number is called for a country (for labels and messages). */
    public static function tax_id_label($country) {
        if ($country === 'GR' || $country === '') return 'ΑΦΜ';
        if (isset(self::EU_VAT[$country])) return 'ΑΦΜ (VAT)';
        if ($country === 'AE') return 'TRN';
        return 'Αριθμός φορολογικού μητρώου';
    }

    /**
     * Checks a partner / technician tax number for its country.
     *  GR: 9 digits with the ΑΦΜ check digit ("EL"/"GR" in front is accepted and dropped)
     *  EU: the country's VAT format; stored with the country prefix (e.g. CY12345678X)
     *  AE: 15-digit TRN · other countries: 4–20 letters/digits
     * @return array [normalized value, error message or null]
     */
    public static function tax_id($country, $raw) {
        $country = strtoupper((string) $country);
        if (!isset(self::countries()[$country])) $country = 'GR';
        $v = strtoupper(preg_replace('/[\s.\-\/]/', '', (string) $raw));
        if ($country === 'GR') {
            $v = preg_replace('/^(EL|GR)/', '', $v);
            return self::valid_afm($v) ? [$v, null] : [$v, 'Ο ΑΦΜ δεν είναι έγκυρος (9 ψηφία).'];
        }
        if (isset(self::EU_VAT[$country])) {
            $body = strpos($v, $country) === 0 ? substr($v, 2) : $v;
            return preg_match('/^(?:' . self::EU_VAT[$country] . ')$/', $body)
                ? [$country . $body, null]
                : [$v, sprintf('Ο ΑΦΜ (VAT) δεν έχει τη μορφή που ισχύει στη χώρα (%s).', self::countries()[$country])];
        }
        if ($country === 'AE') {
            $v = preg_replace('/^AE/', '', $v);
            return preg_match('/^\d{15}$/', $v) ? [$v, null] : [$v, 'Το TRN των Ηνωμένων Αραβικών Εμιράτων έχει 15 ψηφία.'];
        }
        return preg_match('/^[A-Z0-9]{4,20}$/', $v) ? [$v, null] : [$v, 'Ο αριθμός φορολογικού μητρώου πρέπει να έχει 4–20 γράμματα ή ψηφία.'];
    }

    /** Prefix shown in the country dropdown: EL for Greece (as in EU VAT numbers), otherwise the ISO code. */
    public static function tax_prefix($country) {
        return $country === 'GR' ? 'EL' : ($country === 'XX' ? '—' : $country);
    }

    /**
     * Tax number input with the country prefix as a dropdown on its left.
     * $value is shown without its country prefix (it is added back when saved).
     */
    public static function tax_id_field($vat_name, $country_name, $value = '', $country = 'GR', $id = '', $required = true) {
        $country = isset(self::countries()[$country]) ? $country : 'GR';
        if ($country !== 'GR' && $value !== '' && strpos($value, $country) === 0 && isset(self::EU_VAT[$country])) $value = substr($value, 2);
        $h = '<div class="tw-taxid" data-tw-taxid>';
        $h .= '<select name="' . esc_attr($country_name) . '" aria-label="Χώρα" class="tw-taxid-country">';
        foreach (self::countries() as $code => $name) {
            $ph = $code === 'GR' ? '9 ψηφία' : ($code === 'CY' ? 'π.χ. 12345678X' : ($code === 'AE' ? '15 ψηφία (TRN)' : (isset(self::EU_VAT[$code]) ? 'ΑΦΜ (VAT)' : 'Αριθμός φορολογικού μητρώου')));
            // Closed, the dropdown shows only the prefix; opened, the full country names (see the page scripts).
            $full = self::tax_prefix($code) . ' · ' . $name;
            $h .= '<option value="' . esc_attr($code) . '" data-ph="' . esc_attr($ph) . '" data-short="' . esc_attr(self::tax_prefix($code)) . '" data-full="' . esc_attr($full) . '" title="' . esc_attr($name) . '"' . selected($country, $code, false) . '>' . esc_html($full) . '</option>';
        }
        $h .= '</select>';
        $h .= '<input type="text" name="' . esc_attr($vat_name) . '"' . ($id ? ' id="' . esc_attr($id) . '"' : '') . ' value="' . esc_attr($value) . '" maxlength="20" autocomplete="off" spellcheck="false"'
            . ($country === 'GR' ? ' inputmode="numeric" placeholder="9 ψηφία"' : '') . ($required ? ' required' : '') . '>';
        return $h . '</div>';
    }

    /** For display: "ΑΦΜ 094014201" or "ΑΦΜ (VAT) CY12345678X – Κύπρος". */
    public static function tax_id_text($country, $value) {
        if ($value === '' || $value === null) return '—';
        $country = $country ?: 'GR';
        return self::tax_id_label($country) . ' ' . $value . ($country !== 'GR' ? ' – ' . (self::countries()[$country] ?? $country) : '');
    }

    /** Greek ΑΦΜ check digit validation. */
    public static function valid_afm($afm) {
        if (!preg_match('/^\d{9}$/', $afm) || $afm === '000000000') return false;
        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += (int) $afm[$i] * (2 ** (8 - $i));
        }
        return (($sum % 11) % 10) === (int) $afm[8];
    }

    /** Strips spaces/dashes; returns '' when it does not look like a phone number. */
    public static function clean_phone($p) {
        $p = preg_replace('/[\s\-\.\(\)]/', '', (string) $p);
        if (strpos($p, '00') === 0) $p = '+' . substr($p, 2);
        return preg_match('/^\+?\d{8,15}$/', $p) ? $p : '';
    }

    public static function valid_mobile($p) {
        $p = self::clean_phone($p);
        if ($p === '') return false;
        // Greek mobile: 69XXXXXXXX (optionally +30). Other international numbers are accepted as-is.
        if (preg_match('/^(\+30)?69\d{8}$/', $p)) return true;
        return strpos($p, '+') === 0 && strpos($p, '+30') !== 0;
    }

    /** E.164 for SMS gateways. */
    public static function e164($p) {
        $p = self::clean_phone($p);
        if ($p === '') return '';
        if (strpos($p, '+') === 0) return $p;
        if (preg_match('/^\d{10}$/', $p)) return '+30' . $p;
        return '+' . $p;
    }

    /** Random code from an alphabet without look-alike characters (no 0/O, 1/I/L). */
    public static function random_code($length) {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    public static function upload_dir() {
        $u = wp_upload_dir(null, false);
        return trailingslashit($u['basedir']) . self::UPLOAD_SUBDIR;
    }

    /** Keeps invoices out of public reach (Apache/LiteSpeed; for nginx see README). */
    public static function protect_upload_dir() {
        $dir = self::upload_dir();
        if (!is_dir($dir)) wp_mkdir_p($dir);
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }
        return $dir;
    }

    public static function invoice_mimes() {
        return [
            'pdf' => 'application/pdf',
            'jpg|jpeg' => 'image/jpeg',
            'png' => 'image/png',
        ];
    }

    /**
     * Validates and stores the invoice upload in the private directory.
     * @return array|WP_Error ['file' => relative path, 'name' => original name]
     */
    public static function store_invoice(array $file, $label = 'του παραστατικού') {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload', sprintf('Το αρχείο %s δεν ανέβηκε σωστά.', $label));
        }
        $max = max(1, (int) TW_Settings::get('invoice_max_mb')) * 1024 * 1024;
        if ((int) $file['size'] > $max) {
            return new WP_Error('upload', sprintf('Το αρχείο ξεπερνά το όριο των %d MB.', (int) TW_Settings::get('invoice_max_mb')));
        }
        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], self::invoice_mimes());
        if (empty($check['ext']) || empty($check['type'])) {
            return new WP_Error('upload', 'Επιτρέπονται μόνο αρχεία PDF, JPG ή PNG.');
        }
        $dir = self::protect_upload_dir() . '/' . current_time('Y');
        if (!is_dir($dir)) wp_mkdir_p($dir);
        $rel = current_time('Y') . '/' . bin2hex(random_bytes(16)) . '.' . $check['ext'];
        $dest = self::upload_dir() . '/' . $rel;
        if (!@move_uploaded_file($file['tmp_name'], $dest)) {
            return new WP_Error('upload', 'Αδυναμία αποθήκευσης του αρχείου.');
        }
        @chmod($dest, 0640);
        return ['file' => $rel, 'name' => sanitize_file_name($file['name'])];
    }

    public static function invoice_path($rel) {
        if (!$rel || strpos($rel, '..') !== false) return '';
        $path = self::upload_dir() . '/' . $rel;
        return is_file($path) ? $path : '';
    }

    public static function mask_email($email) {
        $parts = explode('@', $email);
        if (count($parts) !== 2) return $email;
        $n = $parts[0];
        $vis = mb_substr($n, 0, min(2, mb_strlen($n)));
        return $vis . str_repeat('*', max(1, mb_strlen($n) - 2)) . '@' . $parts[1];
    }
}
