<?php
/** @var array $s settings  @var string $privacy */
if (!defined('ABSPATH')) exit;
$terms = TW_Settings::current_terms();
?>
<div class="tw-app tw-register" data-tw="register">
    <noscript><p class="tw-alert tw-alert--error">Για την ενεργοποίηση της εγγύησης απαιτείται JavaScript.</p></noscript>

    <ol class="tw-steps" aria-label="Βήματα ενεργοποίησης">
        <li class="is-current" data-step-label="1">Σειριακός αριθμός</li>
        <li data-step-label="2">Email</li>
        <li data-step-label="3">Επαλήθευση</li>
        <li data-step-label="4">Στοιχεία &amp; όροι</li>
        <li data-step-label="5">Ολοκλήρωση</li>
    </ol>

    <div class="tw-alert<?php echo !empty($card_expired) ? ' tw-alert--error' : ''; ?>" role="alert"<?php echo !empty($card_expired) ? '' : ' hidden'; ?>><?php if (!empty($card_expired)) : ?>Ο σύνδεσμος εκτύπωσης έληξε. Την καρτέλα της εγγύησής σας τη βρίσκετε πάντα στο «Οι εγγυήσεις μου» → «Αντίγραφο των στοιχείων μου».<?php endif; ?></div>

    <!-- Step 1: serial only -->
    <form class="tw-step" data-step="1" novalidate>
        <?php echo TW_Guard::fields(); // phpcs:ignore ?>
        <h3>Ενεργοποίηση εγγύησης</h3>
        <p>Πληκτρολογήστε τον σειριακό αριθμό, όπως αναγράφεται στην ετικέτα του προϊόντος.</p>
        <div class="tw-field">
            <label for="tw-serial">Σειριακός αριθμός (S/N) <span class="tw-req">*</span></label>
            <input id="tw-serial" name="serial" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" required maxlength="64">
        </div>
        <?php echo TW_Guard::widget('public', 'register_start'); // phpcs:ignore ?>
        <button type="submit" class="tw-btn">Συνέχεια</button>
    </form>

    <!-- Step 2: email (only for a serial that is on the list and not yet activated) -->
    <form class="tw-step" data-step="2" novalidate hidden>
        <?php echo TW_Guard::fields(); // phpcs:ignore ?>
        <h3>Email κατόχου</h3>
        <p class="tw-product tw-product--found"></p>
        <p>Συμπληρώστε το email σας. Θα σας στείλουμε έναν κωδικό επαλήθευσης και, μετά την ενεργοποίηση, τον κωδικό της εγγύησης.</p>
        <div class="tw-field">
            <label for="tw-email">Email <span class="tw-req">*</span></label>
            <input id="tw-email" name="email" type="email" autocomplete="email" required maxlength="190">
        </div>
        <button type="submit" class="tw-btn">Αποστολή κωδικού</button>
        <button type="button" class="tw-link" data-action="restart">Αλλαγή σειριακού</button>
    </form>

    <!-- Step 3: email code -->
    <form class="tw-step" data-step="3" novalidate hidden>
        <?php echo TW_Guard::fields(); // phpcs:ignore ?>
        <h3>Επαλήθευση email</h3>
        <p class="tw-step-info"></p>
        <div class="tw-field">
            <label for="tw-code">Κωδικός επαλήθευσης <span class="tw-req">*</span></label>
            <input id="tw-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" required class="tw-code-input">
        </div>
        <button type="submit" class="tw-btn">Επαλήθευση</button>
        <button type="button" class="tw-link" data-action="resend">Αποστολή νέου κωδικού</button>
        <button type="button" class="tw-link" data-action="change-email">Αλλαγή email</button>
    </form>

    <!-- Step 4: details, terms, consents -->
    <form class="tw-step" data-step="4" novalidate hidden enctype="multipart/form-data">
        <?php echo TW_Guard::fields(); // phpcs:ignore ?>
        <h3>Στοιχεία κατόχου</h3>
        <p class="tw-product"></p>

        <fieldset>
            <legend>Στοιχεία επικοινωνίας</legend>
            <div class="tw-field">
                <label for="tw-name">Ονοματεπώνυμο <span class="tw-req">*</span></label>
                <input id="tw-name" name="customer_name" type="text" autocomplete="name" required maxlength="200">
            </div>
            <div class="tw-field">
                <label>Email</label>
                <input type="email" data-bind="email" readonly class="tw-readonly">
            </div>
            <div class="tw-grid">
                <div class="tw-field">
                    <label for="tw-mobile">Κινητό τηλέφωνο <span class="tw-req">*</span></label>
                    <input id="tw-mobile" name="phone_mobile" type="tel" autocomplete="tel" required maxlength="20" placeholder="69XXXXXXXX">
                </div>
                <div class="tw-field">
                    <label for="tw-landline">Σταθερό τηλέφωνο</label>
                    <input id="tw-landline" name="phone_landline" type="tel" maxlength="20">
                </div>
            </div>
            <div class="tw-field">
                <label for="tw-address">Διεύθυνση (οδός, αριθμός) <span class="tw-req">*</span></label>
                <input id="tw-address" name="address" type="text" autocomplete="street-address" required maxlength="255">
            </div>
            <div class="tw-grid">
                <div class="tw-field">
                    <label for="tw-postcode">Ταχυδρομικός κώδικας <span class="tw-req">*</span></label>
                    <input id="tw-postcode" name="postcode" type="text" inputmode="numeric" autocomplete="postal-code" required maxlength="6">
                </div>
                <div class="tw-field">
                    <label for="tw-city">Πόλη / Περιοχή</label>
                    <input id="tw-city" name="city" type="text" autocomplete="address-level2" maxlength="120">
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Στοιχεία αγοράς</legend>
            <div class="tw-grid">
                <div class="tw-field">
                    <label for="tw-vat">ΑΦΜ συνεργάτη <span class="tw-req">*</span></label>
                    <input id="tw-vat" name="partner_vat" type="text" inputmode="numeric" required maxlength="9">
                </div>
                <div class="tw-field">
                    <label for="tw-partner-email">Email συνεργάτη <span class="tw-req">*</span></label>
                    <input id="tw-partner-email" name="partner_email" type="email" required maxlength="190">
                </div>
            </div>
            <div class="tw-grid">
                <div class="tw-field">
                    <label for="tw-invoice-number">Αριθμός παραστατικού αγοράς <span class="tw-req">*</span></label>
                    <input id="tw-invoice-number" name="invoice_number" type="text" required maxlength="100">
                </div>
                <div class="tw-field">
                    <label for="tw-purchase">Ημερομηνία αγοράς <span class="tw-req">*</span></label>
                    <input id="tw-purchase" name="purchase_date" type="date" required max="<?php echo esc_attr(TW_Schedule::today()); ?>">
                </div>
            </div>
            <?php $invoice_mode = TW_Settings::invoice_mode(); if ($invoice_mode !== 'off') : ?>
            <div class="tw-field">
                <label for="tw-invoice">Αρχείο παραστατικού αγοράς (PDF, JPG ή PNG, έως <?php echo (int) $s['invoice_max_mb']; ?> MB)<?php echo $invoice_mode === 'required' ? ' <span class="tw-req">*</span>' : ' <em>(προαιρετικό)</em>'; ?></label>
                <input id="tw-invoice" name="invoice_file" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" <?php echo $invoice_mode === 'required' ? 'required' : ''; ?>>
            </div>
            <?php endif; ?>
            <p class="tw-hint">Η εγγύηση διαρκεί <?php echo (int) $s['warranty_years']; ?> χρόνια από την ημερομηνία αγοράς<?php if ((int) $s['service_interval_years'] > 0) : ?>, με προβλεπόμενο Service ανά <?php echo (int) $s['service_interval_years']; ?> χρόνια<?php endif; ?>.</p>
        </fieldset>

        <fieldset>
            <legend>Όροι εγγύησης</legend>
            <div class="tw-terms" tabindex="0" aria-label="Κείμενο όρων εγγύησης"><?php echo wpautop(esc_html($terms['text'])); ?></div>
            <p class="tw-hint">Έκδοση όρων: <?php echo (int) $terms['version']; ?> (<?php echo esc_html(TW_Util::datetime_label($terms['published_at'])); ?>)</p>
            <input type="hidden" name="terms_version" value="<?php echo (int) $terms['version']; ?>">

            <label class="tw-check"><input type="checkbox" name="consent_terms" value="1" required> <span><?php echo esc_html($s['terms_label']); ?> <span class="tw-req">*</span></span></label>
            <label class="tw-check"><input type="checkbox" name="consent_accuracy" value="1" required> <span><?php echo esc_html($s['accuracy_label']); ?> <span class="tw-req">*</span></span></label>
            <label class="tw-check"><input type="checkbox" name="consent_marketing" value="1"> <span><?php echo esc_html($s['marketing_label']); ?> <em>(προαιρετικό)</em></span></label>

            <p class="tw-hint">Οι ειδοποιήσεις για τη συντήρηση (Service) και την εγγύηση του προϊόντος σας αποστέλλονται ανεξάρτητα από την παραπάνω προαιρετική επιλογή, γιατί είναι απαραίτητες για την εγγύηση.
            <?php if ($privacy) : ?> Δείτε πώς επεξεργαζόμαστε τα στοιχεία σας στην <a href="<?php echo esc_url($privacy); ?>" target="_blank" rel="noopener">Πολιτική Απορρήτου</a>.<?php endif; ?></p>
        </fieldset>

        <?php echo TW_Guard::widget('public', 'register_submit'); // phpcs:ignore ?>
        <button type="submit" class="tw-btn">Ενεργοποίηση εγγύησης</button>
    </form>

    <!-- Step 4 -->
    <div class="tw-step tw-done" data-step="5" hidden>
        <h3>Η εγγύηση ενεργοποιήθηκε</h3>
        <p>Ο κωδικός εγγύησής σας:</p>
        <p class="tw-warranty-code" data-bind="warranty_code"></p>
        <dl class="tw-dl">
            <dt>Σειριακός αριθμός</dt><dd data-bind="serial"></dd>
            <dt>Προϊόν</dt><dd data-bind="product"></dd>
            <dt>Λήξη εγγύησης</dt><dd data-bind="warranty_end"></dd>
            <dt>Επόμενο Service</dt><dd data-bind="next_service"></dd>
        </dl>
        <p>Στείλαμε επιβεβαίωση στο <strong data-bind="email"></strong>.</p>
        <p class="tw-done-actions"><a class="tw-btn" data-bind-href="card_url" target="_blank" rel="noopener" hidden>🖨 Εκτύπωση καρτέλας εγγύησης</a>
           <a class="tw-btn tw-btn--ghost" data-bind-href="portal_url" hidden>Μετάβαση στις εγγυήσεις μου</a>
           <button type="button" class="tw-link" data-action="restart">Ενεργοποίηση άλλου προϊόντος</button></p>
    </div>
</div>
