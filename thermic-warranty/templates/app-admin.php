<?php
/** @var string $screen  @var array $screens  @var array $s */
if (!defined('ABSPATH')) exit;
$user = wp_get_current_user();
?><!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo esc_html($screens[$screen][0] . ' – ' . TW_App::APP_NAME . ' · ' . $s['company_name']); ?></title>
    <?php echo TW_App::favicon_tag(); // phpcs:ignore ?>
    <link rel="stylesheet" href="<?php echo esc_url(TW_App::asset('assets/css/fonts.css')); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(TW_App::asset('assets/css/app-admin.css')); ?>">
    <?php echo TW_App::brand_style(); // phpcs:ignore ?>
</head>
<body class="tw-console tw-screen-<?php echo esc_attr($screen); ?>">
<header class="tw-appbar">
    <button type="button" class="tw-menu-toggle" aria-controls="tw-sidebar" aria-expanded="false" aria-label="Μενού"><?php echo TW_Admin::icon('menu'); // phpcs:ignore ?></button>
    <span class="tw-appbar-title"><?php echo esc_html($screens[$screen][0]); ?></span>
</header>
<aside class="tw-sidebar" id="tw-sidebar">
    <div class="tw-brand">
        <?php echo TW_App::logo_html('tw-brand-logo'); // phpcs:ignore ?>
        <span class="tw-brand-sub"><?php echo esc_html(TW_App::APP_NAME); ?></span>
    </div>
    <nav class="tw-nav" aria-label="Κύριο μενού">
        <?php foreach ($screens as $key => $def) : ?>
            <a href="<?php echo esc_url(TW_App::admin_url($key)); ?>" class="<?php echo $key === $screen ? 'is-active' : ''; ?>"<?php echo $key === $screen ? ' aria-current="page"' : ''; ?>>
                <?php echo TW_Admin::icon($def[1]); // phpcs:ignore ?><span><?php echo esc_html($def[0]); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="tw-sidebar-foot">
        <?php $tw_exp = TW_Staff_Auth::session_expires(); if ($tw_exp) : ?>
        <div class="tw-session" data-expires="<?php echo (int) $tw_exp; ?>" data-now="<?php echo (int) time(); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('tw_session_refresh')); ?>" data-url="<?php echo esc_url(TW_App::admin_url()); ?>">
            <?php echo TW_Admin::icon('clock'); // phpcs:ignore ?>
            <span class="tw-session-text">Η σύνδεση λήγει σε <strong class="tw-session-left">–</strong></span>
            <button type="button" class="tw-session-refresh" title="Ανανέωση σύνδεσης" aria-label="Ανανέωση σύνδεσης"><?php echo TW_Admin::icon('refresh'); // phpcs:ignore ?></button>
        </div>
        <?php endif; ?>
        <div class="tw-user">
            <span class="tw-avatar" aria-hidden="true"><?php $parts = preg_split('/\s+/u', trim($user->display_name ?: $user->user_email)); echo esc_html(mb_strtoupper(mb_substr($parts[0], 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''))); ?></span>
            <span class="tw-user-text"><strong><?php echo esc_html($user->display_name); ?></strong><span><?php echo esc_html($user->user_email); ?></span></span>
        </div>
        <a href="<?php echo esc_url(TW_Staff_Auth::logout_url()); ?>" class="tw-logout"><?php echo TW_Admin::icon('logout'); // phpcs:ignore ?><span>Αποσύνδεση</span></a>
        <div class="tw-credit">
            <span>Έκδοση <?php echo esc_html(TW_VERSION); ?></span>
            <span>Developed by <a href="https://www.dazzlink.gr" target="_blank" rel="noopener">Dazzlink</a></span>
        </div>
    </div>
</aside>
<div class="tw-backdrop" hidden></div>
<main class="tw-main">
    <?php
    // Each screen's title gets the same icon as its menu item.
    ob_start();
    call_user_func(['TW_Admin', $screens[$screen][2]]);
    $page = ob_get_clean();
    echo preg_replace('/<h1([^>]*)>/', '<h1$1><span class="tw-title-ico">' . TW_Admin::icon($screens[$screen][1]) . '</span>', $page, 1); // phpcs:ignore
    ?>
</main>
<script>
(function () {
    var b = document.querySelector('.tw-menu-toggle'), bd = document.querySelector('.tw-backdrop');
    function set(open) { document.body.classList.toggle('tw-nav-open', open); b.setAttribute('aria-expanded', open ? 'true' : 'false'); bd.hidden = !open; }
    b.addEventListener('click', function () { set(!document.body.classList.contains('tw-nav-open')); });
    bd.addEventListener('click', function () { set(false); });
    // Session countdown with refresh (the server ends the session at the same moment).
    (function () {
        var box = document.querySelector('.tw-session'); if (!box) return;
        var left = box.querySelector('.tw-session-left'), btn = box.querySelector('.tw-session-refresh');
        var skew = Date.now() / 1000 - parseInt(box.getAttribute('data-now'), 10); // server vs. browser clock
        var exp = parseInt(box.getAttribute('data-expires'), 10);
        function fmt(s) {
            if (s >= 86400) { var d = Math.floor(s / 86400); return d + (d === 1 ? ' ημέρα' : ' ημέρες'); }
            var h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60;
            return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(x).padStart(2, '0');
        }
        function tick() {
            var s = Math.max(0, Math.round(exp - (Date.now() / 1000 - skew)));
            left.textContent = fmt(s);
            box.classList.toggle('is-low', s <= 300);
            if (s <= 0) { clearInterval(timer); location.href = box.getAttribute('data-url'); }
        }
        var timer = setInterval(tick, 1000); tick();
        btn.addEventListener('click', function () {
            btn.disabled = true; btn.classList.add('is-spinning');
            var fd = new FormData(); fd.append('action', 'tw_session_refresh'); fd.append('_wpnonce', box.getAttribute('data-nonce'));
            fetch(box.getAttribute('data-url'), { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (r) {
                    if (!r.success) { location.href = box.getAttribute('data-url'); return; }
                    exp = r.data.expires; skew = 0; box.setAttribute('data-nonce', r.data.nonce); tick();
                })
                .catch(function () {})
                .finally(function () { setTimeout(function () { btn.disabled = false; btn.classList.remove('is-spinning'); }, 400); });
        });
    })();

    // File inputs: Greek, styled picker with drag & drop (replaces the browser's "Choose file").
    var up = <?php echo wp_json_encode(TW_Admin::icon('upload')); ?>;
    document.querySelectorAll('input[type=file]').forEach(function (inp) {
        var box = document.createElement('label'); box.className = 'tw-file';
        var multi = inp.multiple, empty = multi ? 'Δεν επιλέχθηκαν αρχεία' : 'Δεν επιλέχθηκε αρχείο';
        inp.parentNode.insertBefore(box, inp);
        box.innerHTML = '<span class="tw-file-btn">' + up + (multi ? 'Επιλογή αρχείων' : 'Επιλογή αρχείου') + '</span><span class="tw-file-name">' + empty + ' – ή σύρετε ένα αρχείο εδώ</span>';
        box.appendChild(inp);
        var name = box.querySelector('.tw-file-name');
        function show() {
            var f = inp.files;
            box.classList.toggle('has-file', f.length > 0);
            name.textContent = f.length ? Array.prototype.map.call(f, function (x) { return x.name; }).join(', ') : empty + ' – ή σύρετε ένα αρχείο εδώ';
        }
        inp.addEventListener('change', show);
        ['dragenter', 'dragover'].forEach(function (ev) { box.addEventListener(ev, function (e) { e.preventDefault(); box.classList.add('is-drag'); }); });
        ['dragleave', 'drop'].forEach(function (ev) { box.addEventListener(ev, function () { box.classList.remove('is-drag'); }); });
        box.addEventListener('drop', function (e) { e.preventDefault(); if (e.dataTransfer.files.length) { inp.files = e.dataTransfer.files; show(); } });
        inp.form && inp.form.addEventListener('reset', function () { setTimeout(show, 0); });
    });
    document.querySelectorAll('.notice.is-dismissible').forEach(function (n) {
        var x = document.createElement('button'); x.type = 'button'; x.className = 'tw-dismiss'; x.setAttribute('aria-label', 'Κλείσιμο'); x.textContent = '×';
        x.onclick = function () { n.remove(); }; n.appendChild(x);
    });
})();
</script>
</body>
</html>
