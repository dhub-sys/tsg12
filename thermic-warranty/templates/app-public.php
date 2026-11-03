<?php
/** @var string $screen  @var array $s */
if (!defined('ABSPATH')) exit;
$privacy = $s['privacy_url'] ?: get_privacy_policy_url();
$config = [
    'ajax' => admin_url('admin-ajax.php'),
    'maxMb' => (int) $s['invoice_max_mb'],
    'invoiceMode' => TW_Settings::invoice_mode(),
];
?><!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <?php echo TW_App::favicon_tag(); // phpcs:ignore ?>
    <title><?php echo esc_html(($screen === 'my' ? 'Οι εγγυήσεις μου' : 'Ενεργοποίηση εγγύησης') . ' – ' . $s['company_name']); ?></title>
    <link rel="stylesheet" href="<?php echo esc_url(TW_App::asset('assets/css/fonts.css')); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(TW_App::asset('assets/css/frontend.css')); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(TW_App::asset('assets/css/app-public.css')); ?>">
    <?php echo TW_App::brand_style(); // phpcs:ignore ?>
</head>
<body class="tw-public tw-public--<?php echo esc_attr($screen); ?>">
<header class="tw-pub-head">
    <div class="tw-pub-inner">
        <a class="tw-pub-brand" href="<?php echo esc_url(home_url('/')); ?>"><?php echo TW_App::logo_html('tw-pub-logo'); // phpcs:ignore ?></a>
        <nav class="tw-pub-nav" aria-label="Εγγυήσεις">
            <a href="<?php echo esc_url(TW_App::public_url()); ?>" class="<?php echo $screen === 'register' ? 'is-active' : ''; ?>">Ενεργοποίηση εγγύησης</a>
            <a href="<?php echo esc_url(TW_App::public_url('my')); ?>" class="<?php echo $screen === 'my' ? 'is-active' : ''; ?>">Οι εγγυήσεις μου</a>
        </nav>
    </div>
</header>
<main class="tw-pub-main">
    <?php include TW_DIR . 'templates/' . ($screen === 'my' ? 'portal.php' : 'register.php'); ?>
</main>
<footer class="tw-pub-foot">
    <div class="tw-pub-inner">
        <span>© <?php echo esc_html(current_time('Y') . ' ' . $s['company_name']); ?></span>
        <span><?php echo esc_html($s['company_contact']); ?></span>
        <?php if ($privacy) : ?><a href="<?php echo esc_url($privacy); ?>" target="_blank" rel="noopener">Πολιτική απορρήτου</a><?php endif; ?>
    </div>
</footer>
<script>var TW = <?php echo wp_json_encode($config); ?>;</script>
<script src="<?php echo esc_url(TW_App::asset('assets/js/frontend.js')); ?>"></script>
</body>
</html>
