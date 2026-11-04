<?php
if (!defined('ABSPATH')) exit;
$register_url = (string) TW_Settings::get('register_url') ?: TW_App::public_url();
?>
<div class="tw-app tw-portal" data-tw="portal">
    <noscript><p class="tw-alert tw-alert--error">Για την πύλη εγγυήσεων απαιτείται JavaScript.</p></noscript>
    <div class="tw-alert" role="alert" hidden></div>
    <p class="tw-loading" data-portal="loading">Φόρτωση…</p>

    <div data-portal="login" hidden>
        <form class="tw-step" data-step="login-1" novalidate>
            <?php echo TW_Guard::fields(); // phpcs:ignore ?>
            <h3>Οι εγγυήσεις μου</h3>
            <p>Συνδεθείτε με το email που δηλώσατε κατά την ενεργοποίηση. Θα σας στείλουμε έναν κωδικό μίας χρήσης, χωρίς κωδικό πρόσβασης.</p>
            <div class="tw-field">
                <label for="tw-login-email">Email</label>
                <input id="tw-login-email" name="email" type="email" autocomplete="email" required maxlength="190">
            </div>
            <?php echo TW_Guard::widget('public', 'portal_login'); // phpcs:ignore ?>
            <button type="submit" class="tw-btn">Αποστολή κωδικού</button>
            <?php if ($register_url) : ?><p class="tw-hint">Δεν έχετε ενεργοποιήσει ακόμη την εγγύηση; <a href="<?php echo esc_url($register_url); ?>">Ενεργοποίηση εγγύησης</a></p><?php endif; ?>
        </form>
        <form class="tw-step" data-step="login-2" novalidate hidden>
            <?php echo TW_Guard::fields(); // phpcs:ignore ?>
            <h3>Κωδικός σύνδεσης</h3>
            <p class="tw-step-info"></p>
            <div class="tw-field">
                <label for="tw-login-code">Κωδικός</label>
                <input id="tw-login-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" required class="tw-code-input">
            </div>
            <button type="submit" class="tw-btn">Σύνδεση</button>
            <button type="button" class="tw-link" data-action="login-restart">Αλλαγή email</button>
        </form>
    </div>

    <div data-portal="content" hidden></div>
</div>
