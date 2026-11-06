<?php
/** @var array $s settings  @var string $privacy */
if (!defined('ABSPATH')) exit;
$lang = TW_I18n::lang();
$terms = TW_Settings::current_terms($lang);
$labels = TW_Settings::consent_labels($lang);
?>
<div class="tw-app tw-register" data-tw="register" lang="<?php echo esc_attr($lang); ?>">
    <noscript><p class="tw-alert tw-alert--error"><?php tw_e('Για την ενεργοποίηση της εγγύησης απαιτείται JavaScript.'); ?></p></noscript>

    <ol class="tw-steps" aria-label="<?php echo esc_attr(tw__('Βήματα ενεργοποίησης')); ?>">
        <li class="is-current" data-step-label="1"><?php tw_e('Σειριακός αριθμός'); ?></li>
        <li data-step-label="2"><?php tw_e('Email'); ?></li>
        <li data-step-label="3"><?php tw_e('Επαλήθευση'); ?></li>
        <li data-step-label="4"><?php tw_e('Στοιχεία & όροι'); ?></li>
        <li data-step-label="5"><?php tw_e('Ολοκλήρωση'); ?></li>
    </ol>

    <div class="tw-alert" role="alert" hidden></div>

    <!-- Step 1: serial only -->
    <form class="tw-step" data-step="1" novalidate>
        <?php echo TW_Guard::fields(); // phpcs:ignore ?>
        <h3><?php tw_e('Ενεργοποίηση εγγύησης'); ?></h3>
        <p><?php tw_e('Πληκτρολογήστε τον σειριακό αριθμό, όπως αναγράφεται στην ετικέτα του προϊόντος.'); ?></p>
        <div class="tw-field">
            <label for="tw-serial"><?php tw_e('Σειριακός αριθμός (S/N)'); ?> <span class="tw-req">*</span></label>
            <input id="tw-serial" name="serial" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" required maxlength="64">
        </div>
        <?php echo TW_Guard::widget('public', 'register_start'); // phpcs:ignore ?>
        <button type="submit" class="tw-btn"><?php tw_e('Συνέχεια'); ?></button>
    </form>

    <!-- Step 2: email (only for a serial that is on the list and not yet activated) -->
    <form class="tw-step" data-step="2" novalidate hidden>
        <?php echo TW_Guard::fields(); // phpcs:ignore ?>
        <h3><?php tw_e('Email κατόχου'); ?></h3>
        <p class="tw-product tw-product--found"></p>
        <p><?php tw_e('Συμπληρώστε το email σας. Θα σας στείλουμε έναν κωδικό επαλήθευσης και, μετά την ενεργοποίηση, τον κωδικό της εγγύησης.'); ?></p>
        <div class="tw-field">
            <label for="tw-email"><?php tw_e('Email'); ?> <span class="tw-req">*</span></label>
            <input id="tw-email" name="email" type="email" autocomplete="email" required maxlength="190">
        </div>
        <button type="submit" class="tw-btn"><?php tw_e('Αποστολή κωδικού'); ?></button>
        <button type="button" class="tw-link" data-action="restart"><?php tw_e('Αλλαγή σειριακού'); ?></button>
    </form>

    <!-- Step 3: email code -->
    <form class="tw-step" data-step="3" novalidate hidden>
        <?php echo TW_Guard::fields(); // phpcs:ignore ?>
        <h3><?php tw_e('Επαλήθευση email'); ?></h3>
        <p class="tw-step-info"></p>
        <div class="tw-field">
            <label for="tw-code"><?php tw_e('Κωδικός επαλήθευσης'); ?> <span class="tw-req">*</span></label>
            <input id="tw-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" required class="tw-code-input">
        </div>
        <button type="submit" class="tw-btn"><?php tw_e('Επαλήθευση'); ?></button>
        <button type="button" class="tw-link" data-action="resend"><?php tw_e('Αποστολή νέου κωδικού'); ?></button>
        <button type="button" class="tw-link" data-action="change-email"><?php tw_e('Αλλαγή email'); ?></button>
    </form>

    <!-- Step 4: details, terms, consents -->
    <form class="tw-step" data-step="4" novalidate hidden enctype="multipart/form-data">
        <?php echo TW_Guard::fields(); // phpcs:ignore ?>
        <h3><?php tw_e('Στοιχεία κατόχου'); ?></h3>
        <p class="tw-product"></p>

        <fieldset>
            <legend><?php tw_e('Στοιχεία επικοινωνίας'); ?></legend>
            <div class="tw-field">
                <label for="tw-name"><?php tw_e('Ονοματεπώνυμο'); ?> <span class="tw-req">*</span></label>
                <input id="tw-name" name="customer_name" type="text" autocomplete="name" required maxlength="200">
            </div>
            <div class="tw-field">
                <label><?php tw_e('Email'); ?></label>
                <input type="email" data-bind="email" readonly class="tw-readonly">
            </div>
            <div class="tw-grid">
                <div class="tw-field">
                    <label for="tw-mobile"><?php tw_e('Κινητό τηλέφωνο'); ?> <span class="tw-req">*</span></label>
                    <input id="tw-mobile" name="phone_mobile" type="tel" autocomplete="tel" required maxlength="20" placeholder="<?php echo esc_attr($lang === 'en' ? '+357 99XXXXXX' : '69XXXXXXXX'); ?>">
                </div>
                <div class="tw-field">
                    <label for="tw-landline"><?php tw_e('Σταθερό τηλέφωνο'); ?></label>
                    <input id="tw-landline" name="phone_landline" type="tel" maxlength="20">
                </div>
            </div>
            <div class="tw-field">
                <label for="tw-address"><?php tw_e('Διεύθυνση (οδός, αριθμός)'); ?> <span class="tw-req">*</span></label>
                <input id="tw-address" name="address" type="text" autocomplete="street-address" required maxlength="255">
            </div>
            <div class="tw-grid">
                <div class="tw-field">
                    <label for="tw-postcode"><?php tw_e('Ταχυδρομικός κώδικας'); ?><?php echo $lang === 'en' ? '' : ' <span class="tw-req">*</span>'; ?></label>
                    <input id="tw-postcode" name="postcode" type="text" autocomplete="postal-code"<?php echo $lang === 'en' ? '' : ' required inputmode="numeric"'; ?> maxlength="10">
                </div>
                <div class="tw-field">
                    <label for="tw-city"><?php tw_e('Πόλη / Περιοχή'); ?></label>
                    <input id="tw-city" name="city" type="text" autocomplete="address-level2" maxlength="120">
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend><?php tw_e('Στοιχεία αγοράς'); ?></legend>
            <div class="tw-grid">
                <div class="tw-field">
                    <label for="tw-vat"><?php tw_e('ΑΦΜ συνεργάτη'); ?> <span class="tw-req">*</span></label>
                    <?php echo TW_Util::tax_id_field('partner_vat', 'partner_country', '', 'GR', 'tw-vat'); // phpcs:ignore ?>
                </div>
                <div class="tw-field">
                    <label for="tw-partner-email"><?php tw_e('Email συνεργάτη'); ?> <span class="tw-req">*</span></label>
                    <input id="tw-partner-email" name="partner_email" type="email" required maxlength="190">
                </div>
            </div>
            <div class="tw-grid">
                <div class="tw-field">
                    <label for="tw-invoice-number"><?php tw_e('Αριθμός παραστατικού αγοράς'); ?> <span class="tw-req">*</span></label>
                    <input id="tw-invoice-number" name="invoice_number" type="text" required maxlength="100">
                </div>
                <div class="tw-field">
                    <label for="tw-purchase"><?php tw_e('Ημερομηνία αγοράς'); ?> <span class="tw-req">*</span></label>
                    <input id="tw-purchase" name="purchase_date" type="date" required max="<?php echo esc_attr(TW_Schedule::today()); ?>">
                </div>
            </div>
            <?php $invoice_mode = TW_Settings::invoice_mode(); if ($invoice_mode !== 'off') : ?>
            <div class="tw-field">
                <label for="tw-invoice"><?php echo esc_html(sprintf(tw__('Αρχείο παραστατικού αγοράς (PDF, JPG ή PNG, έως %d MB)'), (int) $s['invoice_max_mb'])); ?><?php echo $invoice_mode === 'required' ? ' <span class="tw-req">*</span>' : ' <em>(' . esc_html(tw__('προαιρετικό')) . ')</em>'; ?></label>
                <input id="tw-invoice" name="invoice_file" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" <?php echo $invoice_mode === 'required' ? 'required' : ''; ?>>
            </div>
            <?php endif; ?>
            <p class="tw-hint"><?php echo esc_html((int) $s['service_interval_years'] > 0
                ? sprintf(tw__('Η εγγύηση διαρκεί %1$d χρόνια από την ημερομηνία αγοράς, με προβλεπόμενο Service ανά %2$d χρόνια.'), (int) $s['warranty_years'], (int) $s['service_interval_years'])
                : sprintf(tw__('Η εγγύηση διαρκεί %d χρόνια από την ημερομηνία αγοράς.'), (int) $s['warranty_years'])); ?></p>
        </fieldset>

        <fieldset>
            <legend><?php tw_e('Όροι εγγύησης'); ?></legend>
            <div class="tw-terms" tabindex="0" aria-label="<?php echo esc_attr(tw__('Κείμενο όρων εγγύησης')); ?>"<?php echo $terms['lang'] !== $lang ? ' lang="' . esc_attr($terms['lang']) . '"' : ''; ?>><?php echo wpautop(esc_html($terms['text'])); ?></div>
            <p class="tw-hint"><?php echo esc_html(sprintf(tw__('Έκδοση όρων: %1$d (%2$s)'), (int) $terms['version'], TW_Util::datetime_label($terms['published_at']))); ?></p>
            <input type="hidden" name="terms_version" value="<?php echo (int) $terms['version']; ?>">
            <input type="hidden" name="terms_lang" value="<?php echo esc_attr($terms['lang']); ?>">

            <label class="tw-check"><input type="checkbox" name="consent_terms" value="1" required> <span><?php echo esc_html($labels['terms']); ?> <span class="tw-req">*</span></span></label>
            <label class="tw-check"><input type="checkbox" name="consent_accuracy" value="1" required> <span><?php echo esc_html($labels['accuracy']); ?> <span class="tw-req">*</span></span></label>
            <label class="tw-check"><input type="checkbox" name="consent_marketing" value="1"> <span><?php echo esc_html($labels['marketing']); ?> <em>(<?php tw_e('προαιρετικό'); ?>)</em></span></label>

            <p class="tw-hint"><?php tw_e('Οι ειδοποιήσεις για τη συντήρηση (Service) και την εγγύηση του προϊόντος σας αποστέλλονται ανεξάρτητα από την παραπάνω προαιρετική επιλογή, γιατί είναι απαραίτητες για την εγγύηση.'); ?>
            <?php if ($privacy) : ?> <?php tw_e('Δείτε πώς επεξεργαζόμαστε τα στοιχεία σας στην'); ?> <a href="<?php echo esc_url($privacy); ?>" target="_blank" rel="noopener"><?php tw_e('Πολιτική Απορρήτου'); ?></a>.<?php endif; ?></p>
        </fieldset>

        <?php echo TW_Guard::widget('public', 'register_submit'); // phpcs:ignore ?>
        <button type="submit" class="tw-btn"><?php tw_e('Ενεργοποίηση εγγύησης'); ?></button>
    </form>

    <!-- Step 5 -->
    <div class="tw-step tw-done" data-step="5" hidden>
        <div class="tw-card-logo" hidden><?php echo TW_App::logo_html('tw-card-logo-img'); // phpcs:ignore -- shown only on the printed card ?></div>
        <h3><?php tw_e('Η εγγύηση ενεργοποιήθηκε'); ?></h3>
        <p><?php tw_e('Ο κωδικός εγγύησής σας:'); ?></p>
        <p class="tw-warranty-code" data-bind="warranty_code"></p>
        <dl class="tw-dl">
            <dt><?php tw_e('Σειριακός αριθμός'); ?></dt><dd data-bind="serial"></dd>
            <dt><?php tw_e('Προϊόν'); ?></dt><dd data-bind="product"></dd>
            <dt><?php tw_e('Λήξη εγγύησης'); ?></dt><dd data-bind="warranty_end"></dd>
            <dt><?php tw_e('Επόμενο Service'); ?></dt><dd data-bind="next_service"></dd>
        </dl>
        <p><?php tw_e('Στείλαμε επιβεβαίωση στο'); ?> <strong data-bind="email"></strong>.</p>
        <p class="tw-done-actions"><button type="button" class="tw-btn" data-action="print-card">🖨 <?php tw_e('Εκτύπωση κάρτας εγγύησης'); ?></button>
           <a class="tw-btn tw-btn--ghost" data-bind-href="portal_url" hidden><?php tw_e('Μετάβαση στις εγγυήσεις μου'); ?></a>
           <button type="button" class="tw-link" data-action="restart"><?php tw_e('Ενεργοποίηση άλλου προϊόντος'); ?></button></p>
    </div>
</div>
