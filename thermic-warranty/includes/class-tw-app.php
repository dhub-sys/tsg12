<?php
if (!defined('ABSPATH')) exit;

/**
 * Standalone application layer.
 *
 * WordPress is only the engine: the company team works in its own full-screen app
 * (default /warranty-admin/) with its own sign-in, and customers use full-screen
 * pages (default /warranty/ and /warranty/my/) that do not depend on the theme.
 * Team members (role tw_manager) never see /wp-admin.
 */
final class TW_App {
    const ROLE = 'tw_manager';

    public static function init() {
        add_action('init', [__CLASS__, 'register_rewrites']);
        add_action('init', [__CLASS__, 'maybe_flush'], 99);
        add_filter('query_vars', function ($v) { $v[] = 'tw_app'; $v[] = 'tw_screen'; return $v; });
        add_action('template_redirect', [__CLASS__, 'route'], -100);
        add_action('admin_init', [__CLASS__, 'keep_team_out_of_wp_admin'], 1);
        add_filter('show_admin_bar', function ($show) { return self::is_team_only() ? false : $show; });
        add_filter('login_redirect', function ($to, $req, $user) {
            return ($user instanceof WP_User && self::is_team_only($user)) ? self::admin_url() : $to;
        }, 10, 3);
    }

    /** Roles/caps + rewrite rules. Called on activation and upgrade. */
    public static function install() {
        if (!get_role(self::ROLE)) {
            add_role(self::ROLE, 'Διαχειριστής εγγυήσεων', ['read' => true, TW_Admin::CAP => true]);
        }
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap(TW_Admin::CAP);
            $admin->add_cap('tw_manage_team');
        }
        self::register_rewrites();
        flush_rewrite_rules(false);
    }

    public static function register_rewrites() {
        $a = preg_quote((string) TW_Settings::get('admin_slug'), '#');
        $p = preg_quote((string) TW_Settings::get('public_slug'), '#');
        add_rewrite_rule('^' . $a . '/?$', 'index.php?tw_app=admin', 'top');
        add_rewrite_rule('^' . $p . '/my/?$', 'index.php?tw_app=public&tw_screen=my', 'top');
        add_rewrite_rule('^' . $p . '/?$', 'index.php?tw_app=public', 'top');
    }

    public static function maybe_flush() {
        if (get_option('tw_flush_rewrite')) {
            flush_rewrite_rules(false);
            delete_option('tw_flush_rewrite');
        }
    }

    private static function pretty() {
        return (bool) get_option('permalink_structure');
    }

    // ------------------------------------------------------------------ URLs

    public static function admin_base_query() {
        return self::pretty() ? [] : ['tw_app' => 'admin'];
    }

    public static function admin_url($screen = 'dashboard', array $args = []) {
        $base = self::pretty() ? home_url('/' . TW_Settings::get('admin_slug') . '/') : add_query_arg('tw_app', 'admin', home_url('/'));
        $q = $args;
        if ($screen && $screen !== 'dashboard') $q = array_merge(['screen' => $screen], $args);
        $q = array_filter($q, function ($v) { return $v !== null && $v !== ''; });
        return $q ? add_query_arg(array_map('rawurlencode', array_map('strval', $q)), $base) : $base;
    }

    public static function public_url($screen = '') {
        if (self::pretty()) {
            return home_url('/' . TW_Settings::get('public_slug') . '/' . ($screen === 'my' ? 'my/' : ''));
        }
        $q = ['tw_app' => 'public'];
        if ($screen === 'my') $q['tw_screen'] = 'my';
        return add_query_arg($q, home_url('/'));
    }

    // ------------------------------------------------------------------ access

    /** Team member without any other back-office role. */
    public static function is_team_only($user = null) {
        $user = $user ?: wp_get_current_user();
        return $user && $user->ID && user_can($user, TW_Admin::CAP) && !user_can($user, 'edit_posts');
    }

    public static function keep_team_out_of_wp_admin() {
        if (wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON)) return;
        if (isset($_SERVER['SCRIPT_NAME']) && basename((string) $_SERVER['SCRIPT_NAME']) === 'admin-post.php') return;
        if (self::is_team_only()) {
            wp_safe_redirect(self::admin_url());
            exit;
        }
    }

    // ------------------------------------------------------------------ routing

    public static function route() {
        $app = get_query_var('tw_app');
        if ($app !== 'admin' && $app !== 'public') return;
        status_header(200);
        nocache_headers();
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Referrer-Policy: same-origin');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: ' . ($app === 'admin' ? 'DENY' : 'SAMEORIGIN'));
        header('Content-Type: text/html; charset=utf-8');
        if ($app === 'admin') self::route_admin(); else self::route_public();
        exit;
    }

    private static function route_admin() {
        // Sign-in, 2FA, password set/reset and sign-out.
        $login_view = TW_Staff_Auth::handle();
        if (!is_user_logged_in() || !current_user_can(TW_Admin::cap())) {
            self::render('app-login', ['view' => $login_view]);
            return;
        }

        // Actions: forms post here with `action=tw_xxx`; downloads use GET + nonce.
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if ($action && strpos($action, 'tw_') === 0) {
            $name = substr($action, 3);
            $get_ok = in_array($name, ['invoice', 'export', 'terms_view', 'template', 'receipt', 'backup_download'], true);
            if (in_array($name, TW_Admin::actions(), true) && ($get_ok || $_SERVER['REQUEST_METHOD'] === 'POST')) {
                call_user_func(['TW_Admin', 'handle_' . $name]); // each handler checks its nonce and exits
            }
        }

        TW_Admin::maybe_open_exact();
        if (($_GET['screen'] ?? '') === 'warranties' && !empty($_GET['view']) && isset($_GET['print'])) {
            TW_Admin::render_print((int) $_GET['view']);
            return;
        }
        $screens = TW_Admin::screens();
        $screen = isset($_GET['screen']) ? sanitize_key(wp_unslash($_GET['screen'])) : 'dashboard';
        if (!isset($screens[$screen])) $screen = 'dashboard';
        self::render('app-admin', ['screen' => $screen, 'screens' => $screens]);
    }

    private static function route_public() {
        $screen = get_query_var('tw_screen') === 'my' ? 'my' : 'register';
        if ($screen === 'my' && isset($_GET['copy'])) {
            // Printable copy of the customer's data: only for a signed-in customer (session cookie, SameSite=Strict).
            $session = TW_Frontend::session();
            if (!$session) {
                wp_safe_redirect(self::public_url('my'));
                exit;
            }
            TW_DB::log_event('portal_data_copy', '', 0, 0, 'customer:' . $session->email);
            self::render('app-copy', ['email' => $session->email, 'warranties' => TW_Warranty::by_email($session->email)]);
            return;
        }
        self::render('app-public', ['screen' => $screen]);
    }

    private static function render($template, array $vars) {
        extract($vars, EXTR_SKIP);
        $s = TW_Settings::all();
        include TW_DIR . 'templates/' . $template . '.php';
    }

    /** Asset URL with cache-busting by file time. */
    public static function asset($rel) {
        $path = TW_DIR . $rel;
        return TW_URL . $rel . '?ver=' . TW_VERSION . '.' . (file_exists($path) ? filemtime($path) : '0');
    }

    /** <style> block that applies the brand colour. */
    /** True when dark text reads better than white on this colour (WCAG relative luminance). */
    public static function is_light($hex) {
        $hex = ltrim((string) $hex, '#');
        if (strlen($hex) !== 6) return false;
        $l = 0;
        foreach ([0.2126, 0.7152, 0.0722] as $i => $w) {
            $c = hexdec(substr($hex, $i * 2, 2)) / 255;
            $c = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
            $l += $w * $c;
        }
        return $l > 0.45;
    }

    public static function colors() {
        $primary = sanitize_hex_color((string) TW_Settings::get('brand_color')) ?: '#c8102e';
        $secondary = sanitize_hex_color((string) TW_Settings::get('brand_color_2')) ?: '#0f1320';
        return [$primary, $secondary];
    }

    /** CSS variables for the two brand colours, with readable text colours on top of them. */
    public static function brand_vars() {
        [$p, $s] = self::colors();
        $p_ink = self::is_light($p) ? '#111827' : '#ffffff';
        $light_side = self::is_light($s);
        return ':root{--tw-brand:' . $p . ';--tw-brand-ink:' . $p_ink . ';--tw-side:' . $s . ';'
            . '--tw-side-2:color-mix(in srgb,' . $s . ' 88%,' . ($light_side ? '#000' : '#fff') . ');'
            . '--tw-side-ink:' . ($light_side ? 'rgba(17,24,39,.72)' : 'rgba(255,255,255,.72)') . ';'
            . '--tw-side-strong:' . ($light_side ? '#111827' : '#ffffff') . ';'
            . '--tw-side-hover:' . ($light_side ? 'rgba(0,0,0,.06)' : 'rgba(255,255,255,.07)') . ';'
            . '--tw-side-line:' . ($light_side ? 'rgba(0,0,0,.1)' : 'rgba(255,255,255,.08)') . ';}'
            . '.tw-app{--tw-accent:' . $p . ';--tw-accent-ink:' . $p_ink . ';}';
    }

    public static function brand_style() {
        return '<style id="tw-brand-vars">' . esc_html(self::brand_vars()) . '</style>';
    }

    public static function logo_html($class = 'tw-logo') {
        $s = TW_Settings::all();
        if ($s['logo_url']) {
            return '<img class="' . esc_attr($class) . '" src="' . esc_url($s['logo_url']) . '" alt="' . esc_attr($s['company_name']) . '">';
        }
        return '<span class="' . esc_attr($class) . ' ' . esc_attr($class) . '--text">' . esc_html($s['company_name']) . '</span>';
    }
}
