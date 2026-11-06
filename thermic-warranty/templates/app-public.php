<?php
/** @var string $screen  @var array $s */
if (!defined('ABSPATH')) exit;
$lang = TW_I18n::lang();
$s['company_contact'] = TW_Settings::company_contact($lang);
$privacy = $s['privacy_url'] ?: get_privacy_policy_url();
$config = [
    'ajax' => admin_url('admin-ajax.php'),
    'maxMb' => (int) $s['invoice_max_mb'],
    'invoiceMode' => TW_Settings::invoice_mode(),
    'turnstile' => TW_Guard::on('public') ? TW_Guard::site_key() : '',
    'lang' => $lang,
    'i18n' => TW_I18n::js(),
];
$here = TW_App::public_url($screen === 'my' ? 'my' : '');
?><!doctype html>
<html lang="<?php echo esc_attr($lang); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <?php echo TW_App::favicon_tag(); // phpcs:ignore ?>
    <title><?php echo esc_html(tw__($screen === 'my' ? 'Οι εγγυήσεις μου' : 'Ενεργοποίηση εγγύησης') . ' – ' . $s['company_name']); ?></title>
    <link rel="stylesheet" href="<?php echo esc_url(TW_App::asset('assets/css/fonts.css')); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(TW_App::asset('assets/css/frontend.css')); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(TW_App::asset('assets/css/app-public.css')); ?>">
    <?php echo TW_App::brand_style(); // phpcs:ignore ?>
</head>
<body class="tw-public tw-public--<?php echo esc_attr($screen); ?>">
<header class="tw-pub-head">
    <div class="tw-pub-inner">
        <a class="tw-pub-brand" href="<?php echo esc_url(TW_I18n::url(home_url('/'))); ?>"><?php echo TW_App::logo_html('tw-pub-logo'); // phpcs:ignore ?></a>
        <nav class="tw-pub-nav" aria-label="<?php echo esc_attr(tw__('Εγγυήσεις')); ?>">
            <a href="<?php echo esc_url(TW_I18n::url(TW_App::public_url())); ?>" class="<?php echo $screen === 'register' ? 'is-active' : ''; ?>"><?php tw_e('Ενεργοποίηση εγγύησης'); ?></a>
            <a href="<?php echo esc_url(TW_I18n::url(TW_App::public_url('my'))); ?>" class="<?php echo $screen === 'my' ? 'is-active' : ''; ?>"><?php tw_e('Οι εγγυήσεις μου'); ?></a>
            <span class="tw-lang-switch" role="group" aria-label="Language">
                <?php foreach (['el' => 'EL', 'en' => 'EN'] as $code => $short) : ?>
                    <a href="<?php echo esc_url(add_query_arg('lang', $code, $here)); ?>" hreflang="<?php echo esc_attr($code); ?>" lang="<?php echo esc_attr($code); ?>" title="<?php echo esc_attr(TW_I18n::languages()[$code]); ?>" class="<?php echo $lang === $code ? 'is-active' : ''; ?>"<?php echo $lang === $code ? ' aria-current="true"' : ''; ?>><?php echo esc_html($short); ?></a>
                <?php endforeach; ?>
            </span>
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
        <?php if ($privacy) : ?><a href="<?php echo esc_url(TW_I18n::url($privacy)); ?>" target="_blank" rel="noopener"><?php tw_e('Πολιτική απορρήτου'); ?></a><?php endif; ?>
    </div>
</footer>
<script>var TW = <?php echo wp_json_encode($config, JSON_UNESCAPED_UNICODE); ?>;</script>
<script src="<?php echo esc_url(TW_App::asset('assets/js/otp-input.js')); ?>"></script>
<script src="<?php echo esc_url(TW_App::asset('assets/js/frontend.js')); ?>"></script>
<?php if (TW_Guard::on('public')) : ?><script src="<?php echo esc_url(TW_Guard::explicit_script_url()); ?>" async defer></script><?php endif; ?>
</body>
</html>
