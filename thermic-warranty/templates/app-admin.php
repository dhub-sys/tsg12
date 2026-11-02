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
    <title><?php echo esc_html($screens[$screen][0] . ' – Εγγυήσεις ' . $s['company_name']); ?></title>
    <link rel="stylesheet" href="<?php echo esc_url(TW_App::asset('assets/css/app-admin.css')); ?>">
    <?php echo TW_App::brand_style(); // phpcs:ignore ?>
</head>
<body class="tw-console tw-screen-<?php echo esc_attr($screen); ?>">
<header class="tw-appbar">
    <button type="button" class="tw-menu-toggle" aria-controls="tw-sidebar" aria-expanded="false" aria-label="Μενού">☰</button>
    <span class="tw-appbar-title"><?php echo esc_html($screens[$screen][0]); ?></span>
</header>
<aside class="tw-sidebar" id="tw-sidebar">
    <div class="tw-brand">
        <?php echo TW_App::logo_html('tw-brand-logo'); // phpcs:ignore ?>
        <span class="tw-brand-sub">Διαχείριση εγγυήσεων</span>
    </div>
    <nav class="tw-nav" aria-label="Κύριο μενού">
        <?php foreach ($screens as $key => $def) : ?>
            <a href="<?php echo esc_url(TW_App::admin_url($key)); ?>" class="<?php echo $key === $screen ? 'is-active' : ''; ?>"<?php echo $key === $screen ? ' aria-current="page"' : ''; ?>>
                <span class="tw-nav-ico" aria-hidden="true"><?php echo esc_html($def[1]); ?></span><?php echo esc_html($def[0]); ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="tw-sidebar-foot">
        <div class="tw-user"><strong><?php echo esc_html($user->display_name); ?></strong><span><?php echo esc_html($user->user_email); ?></span></div>
        <a href="<?php echo esc_url(TW_App::public_url()); ?>" target="_blank" rel="noopener">Σελίδα πελατών ↗</a>
        <?php if (current_user_can('manage_options')) : ?><a href="<?php echo esc_url(admin_url()); ?>">WordPress ↗</a><?php endif; ?>
        <a href="<?php echo esc_url(TW_Staff_Auth::logout_url()); ?>" class="tw-logout">Αποσύνδεση</a>
    </div>
</aside>
<div class="tw-backdrop" hidden></div>
<main class="tw-main">
    <?php call_user_func(['TW_Admin', $screens[$screen][2]]); ?>
</main>
<script>
(function () {
    var b = document.querySelector('.tw-menu-toggle'), bd = document.querySelector('.tw-backdrop');
    function set(open) { document.body.classList.toggle('tw-nav-open', open); b.setAttribute('aria-expanded', open ? 'true' : 'false'); bd.hidden = !open; }
    b.addEventListener('click', function () { set(!document.body.classList.contains('tw-nav-open')); });
    bd.addEventListener('click', function () { set(false); });
    document.querySelectorAll('.notice.is-dismissible').forEach(function (n) {
        var x = document.createElement('button'); x.type = 'button'; x.className = 'tw-dismiss'; x.setAttribute('aria-label', 'Κλείσιμο'); x.textContent = '×';
        x.onclick = function () { n.remove(); }; n.appendChild(x);
    });
})();
</script>
</body>
</html>
