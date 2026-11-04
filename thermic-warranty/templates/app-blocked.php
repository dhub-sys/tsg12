<?php
/** @var array $s  @var string $ip */
if (!defined('ABSPATH')) exit;
?><!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Η πρόσβαση δεν επιτρέπεται – <?php echo esc_html(TW_App::APP_NAME); ?></title>
    <?php echo TW_App::favicon_tag(); // phpcs:ignore ?>
    <link rel="stylesheet" href="<?php echo esc_url(TW_App::asset('assets/css/fonts.css')); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(TW_App::asset('assets/css/app-admin.css')); ?>">
    <?php echo TW_App::brand_style(); // phpcs:ignore ?>
</head>
<body class="tw-auth">
<main class="tw-auth-card">
    <div class="tw-auth-brand"><?php echo TW_App::logo_html('tw-brand-logo'); // phpcs:ignore ?><span class="tw-auth-app"><?php echo esc_html(TW_App::APP_NAME); ?></span></div>
    <h1>Η πρόσβαση δεν επιτρέπεται</h1>
    <p>Το <?php echo esc_html(TW_App::APP_NAME); ?> ανοίγει μόνο από το δίκτυο της εταιρείας.</p>
    <p class="description">Η διεύθυνσή σας: <code><?php echo esc_html($ip ?: '—'); ?></code>. Αν χρειάζεστε πρόσβαση από εδώ, επικοινωνήστε με τον διαχειριστή.</p>
</main>
<p class="tw-auth-credit">Developed by <a href="https://www.dazzlink.gr" target="_blank" rel="noopener">Dazzlink</a></p>
</body>
</html>
