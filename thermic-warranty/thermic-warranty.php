<?php
/**
 * Plugin Name: Thermic Warranty
 * Description: Αυτόνομη πλατφόρμα (standalone app) ηλεκτρονικής ενεργοποίησης και διαχείριση εγγυήσεων με σειριακούς αριθμούς: εγγραφή με επαλήθευση email, πύλη πελάτη, ειδοποιήσεις Service/λήξης μέσω email και SMS, διαχειριστικό με μαζική εισαγωγή από Excel/CSV και καταγραφή συγκαταθέσεων (GDPR).
 * Version: 1.4.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Custom
 * Text Domain: thermic-warranty
 */
if (!defined('ABSPATH')) exit;

define('TW_VERSION', '1.4.0');
define('TW_FILE', __FILE__);
define('TW_DIR', plugin_dir_path(__FILE__));
define('TW_URL', plugin_dir_url(__FILE__));

require_once TW_DIR . 'includes/class-tw-db.php';
require_once TW_DIR . 'includes/class-tw-settings.php';
require_once TW_DIR . 'includes/class-tw-schedule.php';
require_once TW_DIR . 'includes/class-tw-util.php';
require_once TW_DIR . 'includes/class-tw-messenger.php';
require_once TW_DIR . 'includes/class-tw-otp.php';
require_once TW_DIR . 'includes/class-tw-warranty.php';
require_once TW_DIR . 'includes/class-tw-frontend.php';
require_once TW_DIR . 'includes/class-tw-cron.php';
require_once TW_DIR . 'includes/class-tw-importer.php';
require_once TW_DIR . 'includes/class-tw-privacy.php';
require_once TW_DIR . 'includes/class-tw-admin.php';
require_once TW_DIR . 'includes/class-tw-staff-auth.php';
require_once TW_DIR . 'includes/class-tw-app.php';

register_activation_hook(__FILE__, ['TW_DB', 'activate']);
register_deactivation_hook(__FILE__, ['TW_Cron', 'unschedule']);

add_action('plugins_loaded', function () {
    TW_DB::maybe_upgrade();
    TW_Frontend::init();
    TW_Cron::init();
    TW_Privacy::init();
    TW_App::init();
    if (is_admin()) {
        TW_Admin::init();
    }
});
