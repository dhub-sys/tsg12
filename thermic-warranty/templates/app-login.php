<?php
/** @var array $view  @var array $s */
if (!defined('ABSPATH')) exit;
$step = $view['step'] ?? 'login';
$email = $view['email'] ?? '';
$action = TW_App::admin_url();
$fields = function () {
    wp_nonce_field('tw_staff_auth');
    echo '<div class="tw-hp" aria-hidden="true"><label>Website<input type="text" name="tw_website" tabindex="-1" autocomplete="off"></label></div>';
};
?><!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Σύνδεση – <?php echo esc_html(TW_App::APP_NAME . ' · ' . $s['company_name']); ?></title>
    <?php echo TW_App::favicon_tag(); // phpcs:ignore ?>
    <link rel="stylesheet" href="<?php echo esc_url(TW_App::asset('assets/css/fonts.css')); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(TW_App::asset('assets/css/app-admin.css')); ?>">
    <?php echo TW_App::brand_style(); // phpcs:ignore ?>
</head>
<body class="tw-auth">
<main class="tw-auth-card">
    <div class="tw-auth-brand"><?php echo TW_App::logo_html('tw-brand-logo'); // phpcs:ignore ?><span class="tw-auth-app"><?php echo TW_App::mark_svg('currentColor', 'class="tw-mark" aria-hidden="true"'); // phpcs:ignore ?><?php echo esc_html(TW_App::APP_NAME); ?></span></div>

    <?php if (!empty($view['error'])) : ?><div class="notice notice-error" role="alert"><p><?php echo esc_html($view['error']); ?></p></div><?php endif; ?>
    <?php if (!empty($view['info'])) : ?><div class="notice notice-info" role="status"><p><?php echo esc_html($view['info']); ?></p></div><?php endif; ?>

    <?php if ($step === 'otp') : ?>
        <h1>Επαλήθευση</h1>
        <form method="post" action="<?php echo esc_url($action); ?>">
            <?php $fields(); ?><input type="hidden" name="action" value="tw_staff_otp"><input type="hidden" name="email" value="<?php echo esc_attr($email); ?>">
            <label>Κωδικός μίας χρήσης<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" required autofocus class="tw-otp"></label>
            <button class="button button-primary button-hero">Σύνδεση</button>
        </form>
        <p class="tw-auth-links"><a href="<?php echo esc_url($action); ?>">← Επιστροφή</a></p>

    <?php elseif ($step === 'forgot') : ?>
        <h1>Πρώτη σύνδεση / νέος κωδικός</h1>
        <p class="description">Θα σας στείλουμε κωδικό στο email για να ορίσετε κωδικό πρόσβασης.</p>
        <form method="post" action="<?php echo esc_url($action); ?>">
            <?php $fields(); ?><input type="hidden" name="action" value="tw_staff_forgot">
            <label>Email<input type="email" name="email" value="<?php echo esc_attr($email); ?>" autocomplete="username" required autofocus></label>
            <button class="button button-primary button-hero">Αποστολή κωδικού</button>
        </form>
        <p class="tw-auth-links"><a href="<?php echo esc_url($action); ?>">← Σύνδεση</a></p>

    <?php elseif ($step === 'reset') : ?>
        <h1>Ορισμός κωδικού πρόσβασης</h1>
        <form method="post" action="<?php echo esc_url($action); ?>">
            <?php $fields(); ?><input type="hidden" name="action" value="tw_staff_reset"><input type="hidden" name="email" value="<?php echo esc_attr($email); ?>">
            <label>Κωδικός από το email<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" required autofocus class="tw-otp"></label>
            <label>Νέος κωδικός πρόσβασης (τουλάχιστον <?php echo (int) TW_Staff_Auth::MIN_PASSWORD; ?> χαρακτήρες)<input type="password" name="password" autocomplete="new-password" minlength="<?php echo (int) TW_Staff_Auth::MIN_PASSWORD; ?>" required></label>
            <label>Επανάληψη<input type="password" name="password2" autocomplete="new-password" required></label>
            <button class="button button-primary button-hero">Αποθήκευση &amp; σύνδεση</button>
        </form>
        <p class="tw-auth-links"><a href="<?php echo esc_url(add_query_arg('forgot', 1, $action)); ?>">Αποστολή νέου κωδικού</a></p>

    <?php else : ?>
        <h1>Σύνδεση</h1>
        <form method="post" action="<?php echo esc_url($action); ?>">
            <?php $fields(); ?><input type="hidden" name="action" value="tw_staff_login">
            <label>Email<input type="email" name="email" value="<?php echo esc_attr($email); ?>" autocomplete="username" required autofocus></label>
            <label>Κωδικός πρόσβασης<input type="password" name="password" autocomplete="current-password" required></label>
            <button class="button button-primary button-hero">Συνέχεια</button>
        </form>
        <p class="tw-auth-links"><a href="<?php echo esc_url(add_query_arg('forgot', 1, $action)); ?>">Πρώτη σύνδεση / ξέχασα τον κωδικό</a></p>
    <?php endif; ?>
</main>
<p class="tw-auth-credit">Έκδοση <?php echo esc_html(TW_VERSION); ?> · Developed by <a href="https://www.dazzlink.gr" target="_blank" rel="noopener">Dazzlink</a></p>
</body>
</html>
