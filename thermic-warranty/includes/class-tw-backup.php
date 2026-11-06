<?php
if (!defined('ABSPATH')) exit;

/**
 * Backups of everything the platform owns: its tables, its settings/terms, and the private files
 * (invoices, receipts). Manual (download) and daily automatic, with retention, optional AES-256
 * zip encryption, and restore (a safety backup is taken first).
 *
 * Archive layout:
 *   manifest.json            plugin/db version, site, date, row counts
 *   options.json             settings (without secrets), terms versions
 *   tables/<name>.ndjson     one JSON object per row (portable across MySQL/MariaDB)
 *   files/…                  the private upload folder (without the backups themselves)
 */
final class TW_Backup {
    const HOOK = 'tw_daily_backup';
    const DIR = 'backups';
    const TABLES = ['serials', 'warranties', 'services', 'consents', 'notifications', 'events', 'events_archive', 'otps'];
    /** Never written to a backup and never overwritten by a restore (secrets, and the IP lock that only WP admins may change). */
    const SECRET_SETTINGS = ['brevo_api_key', 'turnstile_secret', 'ip_lock', 'ip_allow', 'ip_proxy', 'brevo_webhook_token', 'backup_password', 'backup_gdrive_client_secret', 'backup_onedrive_client_secret', 'backup_dropbox_app_secret', 'backup_ftp_password'];

    /** Automatic backups per day → hours between them. */
    const PER_DAY = [1 => 24, 2 => 12, 3 => 8, 4 => 6, 6 => 4];

    public static function init() {
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action(self::HOOK, [__CLASS__, 'run_scheduled']);
        // (Re)schedule when missing or when the frequency setting changed.
        if (!wp_next_scheduled(self::HOOK) || wp_get_schedule(self::HOOK) !== self::recurrence()) self::reschedule();
    }

    public static function cron_schedules($s) {
        foreach (self::PER_DAY as $n => $h) {
            if ($n > 1) $s['tw_backup_' . $h . 'h'] = ['interval' => $h * HOUR_IN_SECONDS, 'display' => sprintf('Κάθε %d ώρες (Warranty Manager)', $h)];
        }
        return $s;
    }

    public static function per_day() {
        $n = (int) TW_Settings::get('backup_per_day');
        return isset(self::PER_DAY[$n]) ? $n : 1;
    }

    private static function recurrence() {
        $h = self::PER_DAY[self::per_day()];
        return $h === 24 ? 'daily' : 'tw_backup_' . $h . 'h';
    }

    /** Times of the day the automatic backup runs, e.g. ['03:30', '15:30']. */
    public static function times() {
        $t = (string) TW_Settings::get('backup_time');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) $t = '03:30';
        [$hh, $mm] = array_map('intval', explode(':', $t));
        $step = self::PER_DAY[self::per_day()];
        $out = [];
        for ($i = 0; $i < self::per_day(); $i++) $out[] = sprintf('%02d:%02d', ($hh + $i * $step) % 24, $mm);
        sort($out);
        return $out;
    }

    public static function schedule() {
        if (wp_next_scheduled(self::HOOK)) return;
        $tz = wp_timezone();
        $next = null;
        foreach (self::times() as $t) { // the next of today's (or tomorrow's) slots
            foreach (['today', 'tomorrow'] as $day) {
                $c = new DateTimeImmutable("$day $t", $tz);
                if ($c->getTimestamp() > time() && (!$next || $c < $next)) $next = $c;
            }
        }
        wp_schedule_event($next->getTimestamp(), self::recurrence(), self::HOOK);
    }

    public static function reschedule() {
        wp_clear_scheduled_hook(self::HOOK);
        self::schedule();
    }

    public static function unschedule() {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function dir() {
        $d = TW_Util::protect_upload_dir() . '/' . self::DIR;
        if (!is_dir($d)) wp_mkdir_p($d);
        if (!file_exists($d . '/index.php')) @file_put_contents($d . '/index.php', "<?php // Silence is golden.\n");
        if (!file_exists($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        return $d;
    }

    public static function run_scheduled() {
        if (!TW_Settings::get('backup_daily')) return;
        $r = self::create('auto');
        if (is_wp_error($r)) {
            TW_DB::log_event('backup_failed', $r->get_error_message(), 0, 0, 'system');
            foreach (TW_Messenger::admin_recipients() as $to) {
                TW_Messenger::send_email($to, '[Εγγυήσεις] Αποτυχία αυτόματου backup', "Το αυτόματο backup της πλατφόρμας εγγυήσεων απέτυχε:\n\n" . $r->get_error_message() . "\n\n" . TW_App::admin_url('backups'));
            }
            return;
        }
        self::prune();
        if (TW_Backup_Remote::enabled()) self::send_remote($r);
    }

    /** Off-site copy; on failure the company is emailed. @return true|WP_Error */
    public static function send_remote(array $backup) {
        $r = TW_Backup_Remote::send($backup['path']);
        if (is_wp_error($r)) {
            foreach (TW_Messenger::admin_recipients() as $to) {
                TW_Messenger::send_email($to, '[Εγγυήσεις] Αποτυχία αποστολής backup – ' . TW_Backup_Remote::label(),
                    "Το αντίγραφο " . $backup['file'] . " δημιουργήθηκε στον server, αλλά η αποστολή στο " . TW_Backup_Remote::label() . " απέτυχε:\n\n" . $r->get_error_message() . "\n\n" . TW_App::admin_url('backups'));
            }
        }
        return $r;
    }

    /** Keeps the newest N automatic backups; manual and pre-restore backups are kept until deleted. */
    public static function prune() {
        $keep = max(1, (int) TW_Settings::get('backup_keep'));
        $auto = array_values(array_filter(self::all(), function ($b) { return $b['type'] === 'auto'; }));
        foreach (array_slice($auto, $keep) as $b) @unlink($b['path']);
    }

    /** @return array[] newest first: file, path, type, size, time */
    public static function all() {
        $out = [];
        foreach ((array) glob(self::dir() . '/tw-backup-*.zip') as $path) {
            if (!preg_match('/tw-backup-(\d{8}-\d{6})-(auto|manual|prerestore)-[a-f0-9]+\.zip$/', $path, $m)) continue;
            $out[] = ['file' => basename($path), 'path' => $path, 'type' => $m[2], 'size' => filesize($path),
                'time' => DateTimeImmutable::createFromFormat('Ymd-His', $m[1], wp_timezone())->format('Y-m-d H:i:s')];
        }
        usort($out, function ($a, $b) { return strcmp($b['file'], $a['file']); });
        return $out;
    }

    public static function find($file) {
        foreach (self::all() as $b) if ($b['file'] === $file) return $b;
        return null;
    }

    // ------------------------------------------------------------------ create

    /** @return array|WP_Error the backup entry */
    public static function create($type = 'manual') {
        global $wpdb;
        if (!class_exists('ZipArchive')) return new WP_Error('zip', 'Η επέκταση PHP ZipArchive δεν είναι διαθέσιμη στον server.');
        @set_time_limit(0);
        $name = sprintf('tw-backup-%s-%s-%s.zip', current_time('Ymd-His'), $type, bin2hex(random_bytes(6)));
        $path = self::dir() . '/' . $name;
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return new WP_Error('zip', 'Δεν ήταν δυνατή η δημιουργία του αρχείου backup.');
        $password = (string) TW_Settings::get('backup_password');
        $encrypt = function ($entry) use ($zip, $password) {
            if ($password !== '' && method_exists($zip, 'setEncryptionName')) $zip->setEncryptionName($entry, ZipArchive::EM_AES_256, $password);
        };
        $tmp = [];
        $counts = [];
        foreach (self::TABLES as $t) {
            $table = TW_DB::table($t);
            $file = tw_tempnam('tw-' . $t);
            $fh = fopen($file, 'w');
            $n = 0; $last = 0;
            do { // in chunks, so large tables do not exhaust memory
                $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE id > %d ORDER BY id LIMIT 2000", $last), ARRAY_A);
                foreach ($rows as $r) {
                    fwrite($fh, wp_json_encode($r, JSON_UNESCAPED_UNICODE) . "\n");
                    $last = (int) $r['id'];
                    $n++;
                }
            } while (count($rows) === 2000);
            fclose($fh);
            $zip->addFile($file, "tables/$t.ndjson");
            $encrypt("tables/$t.ndjson");
            $tmp[] = $file;
            $counts[$t] = $n;
        }
        $settings = TW_Settings::all();
        foreach (self::SECRET_SETTINGS as $k) unset($settings[$k]);
        $zip->addFromString('options.json', wp_json_encode(['tw_settings' => $settings, 'tw_terms_versions' => TW_Settings::terms_versions()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $encrypt('options.json');

        $files = 0;
        $base = TW_Util::upload_dir();
        if (is_dir($base)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($base))), '/');
                if ($f->isDir() || strpos($rel, self::DIR . '/') === 0 || in_array($rel, ['.htaccess', 'index.php'], true)) continue;
                $zip->addFile($f->getPathname(), 'files/' . $rel);
                $encrypt('files/' . $rel);
                $files++;
            }
        }
        $manifest = [
            'format' => 1,
            'plugin_version' => TW_VERSION,
            'db_version' => TW_DB::DB_VERSION,
            'site' => home_url('/'),
            'created_at' => TW_DB::now(),
            'type' => $type,
            'encrypted' => $password !== '',
            'rows' => $counts,
            'files' => $files,
        ];
        // The manifest stays readable so a backup can be identified without the password.
        $zip->addFromString('manifest.json', wp_json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $zip->setArchiveComment('Warranty Manager backup ' . $manifest['created_at']);
        $ok = $zip->close();
        foreach ($tmp as $f) @unlink($f);
        if (!$ok || !file_exists($path)) return new WP_Error('zip', 'Η ολοκλήρωση του αρχείου backup απέτυχε (χώρος στον δίσκο;).');
        @chmod($path, 0640);
        TW_DB::log_event('backup_created', ['file' => $name, 'type' => $type, 'rows' => $counts, 'files' => $files, 'encrypted' => $manifest['encrypted']], 0, 0, $type === 'auto' ? 'system' : null);
        update_option('tw_last_backup', ['at' => TW_DB::now(), 'file' => $name, 'type' => $type], false);
        return self::find($name);
    }

    /** @return array|null manifest of a backup file */
    public static function manifest($path) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return null;
        $m = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();
        return is_array($m) ? $m : null;
    }

    // ------------------------------------------------------------------ restore

    /**
     * Replaces all platform data with the backup's content. A "prerestore" backup is taken first.
     * @return array|WP_Error ['rows' => [...], 'files' => n, 'safety' => file]
     */
    public static function restore($path, $password = '') {
        global $wpdb;
        @set_time_limit(0);
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return new WP_Error('zip', 'Το αρχείο δεν είναι έγκυρο backup.');
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        if (!is_array($manifest) || ($manifest['format'] ?? 0) !== 1 || !isset($manifest['rows'])) {
            $zip->close();
            return new WP_Error('format', 'Το αρχείο δεν είναι backup της πλατφόρμας εγγυήσεων.');
        }
        if ($password !== '') $zip->setPassword($password);
        $options = $zip->getFromName('options.json');
        if ($options === false) {
            $zip->close();
            return new WP_Error('password', !empty($manifest['encrypted']) ? 'Λάθος ή κενός κωδικός κρυπτογράφησης.' : 'Το backup είναι ελλιπές.');
        }
        $options = json_decode($options, true);

        $safety = self::create('prerestore');
        if (is_wp_error($safety)) {
            $zip->close();
            return new WP_Error('safety', 'Δεν έγινε επαναφορά: δεν ήταν δυνατό το αντίγραφο ασφαλείας της τρέχουσας κατάστασης (' . $safety->get_error_message() . ').');
        }

        $restored = [];
        foreach (self::TABLES as $t) {
            $data = $zip->getFromName("tables/$t.ndjson");
            if ($data === false) continue;
            $table = TW_DB::table($t);
            $columns = array_flip($wpdb->get_col("SHOW COLUMNS FROM $table"));
            $wpdb->query("DELETE FROM $table");
            $n = 0;
            foreach (preg_split('/\r?\n/', $data) as $line) {
                if ($line === '') continue;
                $row = json_decode($line, true);
                if (!is_array($row)) continue;
                $row = array_intersect_key($row, $columns); // backups from older/newer versions
                if ($wpdb->insert($table, $row)) $n++;
            }
            $restored[$t] = $n;
        }

        if (is_array($options)) {
            if (isset($options['tw_settings']) && is_array($options['tw_settings'])) {
                $current = TW_Settings::all();
                $settings = $options['tw_settings'];
                foreach (self::SECRET_SETTINGS as $k) $settings[$k] = $current[$k] ?? '';
                // Keep this site's addresses: a backup may come from staging.
                foreach (['admin_slug', 'public_slug', 'portal_url', 'register_url'] as $k) $settings[$k] = $current[$k] ?? '';
                // …and this site's backup configuration (schedule, encryption, off-site destination).
                foreach ($current as $k => $v) if (strpos($k, 'backup_') === 0) $settings[$k] = $v;
                update_option(TW_Settings::OPTION, $settings, false);
            }
            if (isset($options['tw_terms_versions']) && is_array($options['tw_terms_versions'])) {
                update_option(TW_Settings::TERMS_OPTION, $options['tw_terms_versions'], false);
            }
        }

        // Files: replace the private folder's content (backups excluded).
        $base = TW_Util::upload_dir();
        if (is_dir($base)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($base))), '/');
                if ($rel === self::DIR || strpos($rel, self::DIR . '/') === 0 || in_array($rel, ['.htaccess', 'index.php'], true)) continue;
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
        }
        $files = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (strpos($entry, 'files/') !== 0 || substr($entry, -1) === '/') continue;
            $rel = substr($entry, 6);
            if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/' || strpos($rel, self::DIR . '/') === 0) continue;
            $content = $zip->getFromIndex($i);
            if ($content === false) continue;
            $dest = $base . '/' . $rel;
            wp_mkdir_p(dirname($dest));
            if (@file_put_contents($dest, $content) !== false) { @chmod($dest, 0640); $files++; }
        }
        $zip->close();
        TW_Util::protect_upload_dir();

        // Bring the restored data up to this version's schema.
        delete_option(TW_DB::DB_VERSION_OPTION);
        TW_DB::install();
        TW_DB::log_event('backup_restored', ['from' => $manifest['created_at'] ?? '', 'site' => $manifest['site'] ?? '', 'rows' => $restored, 'files' => $files, 'safety' => $safety['file']]);
        return ['rows' => $restored, 'files' => $files, 'safety' => $safety['file'], 'manifest' => $manifest];
    }
}

/** Temp file in the WordPress temp dir (wp_tempnam lives in wp-admin and is not loaded on the front end). */
function tw_tempnam($prefix) {
    return tempnam(get_temp_dir(), $prefix);
}
