<?php
if (!defined('ABSPATH')) exit;

/**
 * Greek / English for the customer side only (activation, «Οι εγγυήσεις μου», printable copy and card,
 * customer emails and SMS). The console stays in Greek.
 *
 * Language, in order: explicit choice in the request (?lang= or the AJAX field tw_lang) → Polylang's current
 * language → the visitor's earlier choice (cookie) → the browser's language → Greek.
 * Strings are written in Greek in the code and looked up in includes/i18n/en.php (Greek => English).
 */
final class TW_I18n {
    const COOKIE = 'tw_lang';
    private static $lang = null;
    private static $dict = null;

    public static function languages() {
        return ['el' => 'Ελληνικά', 'en' => 'English'];
    }

    public static function norm($code) {
        $code = strtolower(substr((string) $code, 0, 2));
        if ($code === 'en') return 'en';
        if ($code === 'el' || $code === 'gr') return 'el';
        return '';
    }

    public static function lang() {
        if (self::$lang === null) self::$lang = self::detect();
        return self::$lang;
    }

    /** For a request or a message in a given language (e.g. an email to an English-speaking customer). */
    public static function set($lang) {
        $prev = self::lang();
        self::$lang = self::norm($lang) ?: 'el';
        return $prev;
    }

    public static function detect() {
        // The console and automatic tasks are in Greek; customer messages set their language explicitly.
        if ((is_admin() && !wp_doing_ajax()) || (defined('DOING_CRON') && DOING_CRON)) return 'el';
        foreach (['tw_lang', 'lang'] as $k) {
            if (isset($_REQUEST[$k]) && ($l = self::norm(sanitize_key(wp_unslash($_REQUEST[$k]))))) return $l;
        }
        if (function_exists('pll_current_language') && ($l = self::norm((string) pll_current_language('slug')))) return $l;
        if (isset($_COOKIE[self::COOKIE]) && ($l = self::norm(sanitize_key(wp_unslash($_COOKIE[self::COOKIE]))))) return $l;
        if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            foreach (explode(',', (string) $_SERVER['HTTP_ACCEPT_LANGUAGE']) as $part) {
                if ($l = self::norm(trim($part))) return $l;
            }
        }
        return 'el';
    }

    /** Runs $fn with the given language active, then restores the previous one. */
    public static function with($lang, callable $fn) {
        $prev = self::set($lang);
        try { return $fn(); } finally { self::$lang = $prev; }
    }

    /** Remembers an explicit choice (standalone pages' EL | EN switch) for the next visits. */
    public static function remember() {
        foreach (['tw_lang', 'lang'] as $k) {
            if (isset($_GET[$k]) && ($l = self::norm(sanitize_key(wp_unslash($_GET[$k])))) && !headers_sent()) {
                setcookie(self::COOKIE, $l, ['expires' => time() + YEAR_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax']);
            }
        }
    }

    public static function dict() {
        if (self::$dict === null) {
            $d = include TW_DIR . 'includes/i18n/en.php';
            self::$dict = is_array($d) ? $d : [];
        }
        return self::$dict;
    }

    /** Translates a Greek source string into the current customer language. */
    public static function t($s) {
        if (self::lang() !== 'en') return $s;
        $d = self::dict();
        return isset($d[$s]) ? $d[$s] : $s;
    }

    /** Strings the browser scripts need (only when not Greek). */
    public static function js() {
        return self::lang() === 'en' ? self::dict() : (object) [];
    }

    /** A customer-facing link in the given language (Polylang translation, or ?lang=en on the standalone pages). */
    public static function url($url, $lang = null) {
        $lang = $lang ?: self::lang();
        if (!$url) return $url;
        if (function_exists('pll_home_url') && untrailingslashit($url) === untrailingslashit(home_url('/'))) return pll_home_url($lang);
        if (function_exists('pll_get_post') && ($pid = url_to_postid($url)) && ($tr = pll_get_post($pid, $lang))) {
            return get_permalink($tr);
        }
        return $lang === 'en' ? add_query_arg('lang', 'en', $url) : remove_query_arg('lang', $url);
    }

    /** "1ο" / "1st". */
    public static function ordinal($n) {
        $n = (int) $n;
        if (self::lang() !== 'en') return $n . 'ο';
        if (in_array($n % 100, [11, 12, 13], true)) return $n . 'th';
        return $n . ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th');
    }
}

/** Customer-side translation helper (Greek source string). */
function tw__($s) {
    return TW_I18n::t($s);
}

/** Echoes a translated, escaped customer-side string. */
function tw_e($s) {
    echo esc_html(TW_I18n::t($s));
}
