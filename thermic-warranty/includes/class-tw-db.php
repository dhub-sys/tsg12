<?php
if (!defined('ABSPATH')) exit;

/**
 * Database schema and table names.
 *
 * serials        – the serial numbers we import (one row per physical product)
 * warranties     – one activated warranty per serial (customer data lives here)
 * consents       – every consent given/withdrawn, with terms version, IP and time (GDPR proof)
 * otps           – email verification codes (registration and portal login), stored hashed
 * services       – Service per 3-year milestone: submitted by the customer (date, technician ΑΦΜ,
 *                  receipt) and reviewed by the company, or recorded by the company directly
 * notifications  – every reminder sent; the unique key makes the daily run idempotent
 * events         – audit trail per warranty / serial
 */
final class TW_DB {
    const DB_VERSION = '1.8.0';
    const DB_VERSION_OPTION = 'tw_db_version';

    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'tw_' . $name;
    }

    public static function activate() {
        self::install();
        TW_Settings::ensure_defaults();
        TW_Util::protect_upload_dir();
        TW_Cron::schedule();
        TW_App::install();
    }

    public static function maybe_upgrade() {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            self::install();
            TW_Settings::ensure_defaults();
            // Roles + rewrite rules once WordPress is fully loaded.
            add_action('init', ['TW_App', 'install'], 100);
        }
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $t = function ($n) { return self::table($n); };

        dbDelta("CREATE TABLE {$t('serials')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  serial_no varchar(64) NOT NULL,
  product_code varchar(100) NOT NULL DEFAULT '',
  product_name varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'available',
  import_batch varchar(64) NOT NULL DEFAULT '',
  notes text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY serial_no (serial_no),
  KEY status (status)
) $c;");

        dbDelta("CREATE TABLE {$t('warranties')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  warranty_code varchar(32) NOT NULL,
  serial_id bigint(20) unsigned NOT NULL,
  customer_name varchar(200) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  phone_landline varchar(40) NOT NULL DEFAULT '',
  phone_mobile varchar(40) NOT NULL DEFAULT '',
  address varchar(255) NOT NULL DEFAULT '',
  postcode varchar(20) NOT NULL DEFAULT '',
  city varchar(120) NOT NULL DEFAULT '',
  partner_vat varchar(20) NOT NULL DEFAULT '',
  partner_country varchar(2) NOT NULL DEFAULT 'GR',
  partner_email varchar(190) NOT NULL DEFAULT '',
  invoice_number varchar(100) NOT NULL DEFAULT '',
  invoice_file varchar(255) NOT NULL DEFAULT '',
  invoice_original_name varchar(255) NOT NULL DEFAULT '',
  purchase_date date NOT NULL,
  warranty_end date NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  lock_reason varchar(255) NOT NULL DEFAULT '',
  locked_at datetime NULL,
  marketing_opt_in tinyint(1) NOT NULL DEFAULT 0,
  anonymized_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY warranty_code (warranty_code),
  UNIQUE KEY serial_id (serial_id),
  KEY email (email),
  KEY status (status),
  KEY warranty_end (warranty_end),
  KEY partner_vat (partner_vat)
) $c;");

        dbDelta("CREATE TABLE {$t('consents')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  warranty_id bigint(20) unsigned NOT NULL,
  consent_type varchar(30) NOT NULL,
  granted tinyint(1) NOT NULL DEFAULT 0,
  terms_version int(11) NOT NULL DEFAULT 0,
  terms_hash char(64) NOT NULL DEFAULT '',
  label_text text NULL,
  source varchar(30) NOT NULL DEFAULT 'registration',
  ip varchar(64) NOT NULL DEFAULT '',
  user_agent varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY warranty_id (warranty_id)
) $c;");

        dbDelta("CREATE TABLE {$t('otps')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  purpose varchar(20) NOT NULL,
  email varchar(190) NOT NULL,
  serial_id bigint(20) unsigned NOT NULL DEFAULT 0,
  code_hash char(64) NOT NULL,
  token_hash char(64) NOT NULL DEFAULT '',
  attempts int(11) NOT NULL DEFAULT 0,
  expires_at datetime NOT NULL,
  verified_at datetime NULL,
  used_at datetime NULL,
  ip varchar(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY email (email),
  KEY token_hash (token_hash)
) $c;");

        // 1.3.0: every Service submission is kept (history), so (warranty, milestone) is no longer unique.
        if (version_compare((string) get_option(self::DB_VERSION_OPTION, '0'), '1.3.0', '<')
            && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t('services'))) === $t('services')) {
            $suppress = $wpdb->suppress_errors(true);
            $wpdb->query("ALTER TABLE {$t('services')} DROP INDEX warranty_milestone");
            $wpdb->suppress_errors($suppress);
        }
        dbDelta("CREATE TABLE {$t('services')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  warranty_id bigint(20) unsigned NOT NULL,
  milestone_no int(11) NOT NULL,
  performed_date date NOT NULL,
  performed_by varchar(20) NOT NULL DEFAULT 'partner',
  technician varchar(200) NOT NULL DEFAULT '',
  technician_vat varchar(20) NOT NULL DEFAULT '',
  technician_country varchar(2) NOT NULL DEFAULT 'GR',
  genuine_parts tinyint(1) NOT NULL DEFAULT 1,
  receipt_file varchar(255) NOT NULL DEFAULT '',
  receipt_original_name varchar(255) NOT NULL DEFAULT '',
  receipt_number varchar(100) NOT NULL DEFAULT '',
  receipt_date date NULL,
  source varchar(20) NOT NULL DEFAULT 'admin',
  review_status varchar(20) NOT NULL DEFAULT 'approved',
  review_note varchar(255) NOT NULL DEFAULT '',
  reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
  reviewed_at datetime NULL,
  resubmit_until date NULL,
  notes text NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY warranty_milestone (warranty_id,milestone_no)
) $c;");

        dbDelta("CREATE TABLE {$t('notifications')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  warranty_id bigint(20) unsigned NOT NULL,
  milestone_key varchar(30) NOT NULL,
  offset_days int(11) NOT NULL,
  channel varchar(10) NOT NULL,
  recipient varchar(190) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'sent',
  error text NULL,
  provider_id varchar(190) NOT NULL DEFAULT '',
  delivery_status varchar(20) NOT NULL DEFAULT '',
  delivery_detail varchar(255) NOT NULL DEFAULT '',
  delivery_at datetime NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY once_per_step (warranty_id,milestone_key,offset_days,channel),
  KEY provider_id (provider_id),
  KEY created_at (created_at)
) $c;");

        dbDelta("CREATE TABLE {$t('events')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  warranty_id bigint(20) unsigned NOT NULL DEFAULT 0,
  serial_id bigint(20) unsigned NOT NULL DEFAULT 0,
  actor varchar(100) NOT NULL DEFAULT '',
  action varchar(50) NOT NULL,
  details text NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  sig varchar(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY warranty_id (warranty_id),
  KEY serial_id (serial_id),
  KEY created_at (created_at),
  KEY action (action),
  KEY actor (actor)
) $c;");

        // Archived activity log entries (same columns and signatures; ids kept).
                dbDelta("CREATE TABLE {$t('events_archive')} (
  id bigint(20) unsigned NOT NULL,
  warranty_id bigint(20) unsigned NOT NULL DEFAULT 0,
  serial_id bigint(20) unsigned NOT NULL DEFAULT 0,
  actor varchar(100) NOT NULL DEFAULT '',
  action varchar(50) NOT NULL,
  details text NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  sig varchar(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY warranty_id (warranty_id),
  KEY serial_id (serial_id),
  KEY created_at (created_at),
  KEY action (action),
  KEY actor (actor)
) $c;");

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    /** Current time in the site's timezone, MySQL format. */
    public static function now() {
        return current_time('mysql');
    }

    public static function log_event($action, $details = '', $warranty_id = 0, $serial_id = 0, $actor = null) {
        global $wpdb;
        if ($actor === null) {
            $u = wp_get_current_user();
            $actor = ($u && $u->ID) ? 'admin:' . $u->user_login : 'system';
        }
        $wpdb->insert(self::table('events'), [
            'warranty_id' => (int) $warranty_id,
            'serial_id' => (int) $serial_id,
            'actor' => $actor,
            'action' => $action,
            'details' => is_string($details) ? $details : wp_json_encode($details, JSON_UNESCAPED_UNICODE),
            // Who did it from where (not for automatic tasks).
            'ip' => strpos($actor, 'system') === 0 ? '' : TW_Util::client_ip(),
            'created_at' => self::now(),
        ]);
        if ($wpdb->insert_id) self::sign_event((int) $wpdb->insert_id);
    }

    /**
     * Tamper evidence for the activity log: each entry is signed with a key derived from the site's secret keys
     * in wp-config.php (not stored in the database), so an entry edited directly in the database no longer matches.
     */
    public static function event_signature($row) {
        $row = (object) $row;
        $data = implode('|', [(int) $row->id, (int) $row->warranty_id, (int) $row->serial_id, (string) $row->actor, (string) $row->action, (string) $row->details, (string) $row->ip, (string) $row->created_at]);
        return hash_hmac('sha256', $data, wp_salt('auth') . 'tw-activity-log');
    }

    public static function sign_event($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table('events') . " WHERE id = %d", $id));
        if ($row) $wpdb->update(self::table('events'), ['sig' => self::event_signature($row)], ['id' => $id]);
    }
}
