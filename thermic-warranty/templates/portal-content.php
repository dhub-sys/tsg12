<?php
/** @var object[] $warranties  @var string $email */
if (!defined('ABSPATH')) exit;
$labels = TW_Util::status_labels();
$s = TW_Settings::all();
?>
<div class="tw-portal-head">
    <h3>Οι εγγυήσεις μου</h3>
    <p>Συνδεδεμένος/η ως <strong><?php echo esc_html($email); ?></strong>
        <button type="button" class="tw-link" data-action="logout">Αποσύνδεση</button></p>
</div>

<?php if (!$warranties) : ?>
    <p>Δεν βρέθηκαν εγγυήσεις.</p>
<?php endif; ?>

<?php foreach ($warranties as $w) :
    $timeline = TW_Warranty::timeline($w);
    $days_left = TW_Schedule::diff_days(TW_Schedule::today(), $w->warranty_end);
    ?>
    <article class="tw-card tw-card--<?php echo esc_attr($w->status); ?>">
        <header>
            <h4><?php echo esc_html($w->product_name ?: $w->product_code ?: 'Προϊόν'); ?></h4>
            <span class="tw-badge tw-badge--<?php echo esc_attr($w->status); ?>"><?php echo esc_html($labels[$w->status] ?? $w->status); ?></span>
        </header>
        <dl class="tw-dl">
            <dt>Σειριακός αριθμός</dt><dd><code><?php echo esc_html($w->serial); ?></code></dd>
            <dt>Κωδικός εγγύησης</dt><dd><code><?php echo esc_html($w->warranty_code); ?></code></dd>
            <dt>Ημερομηνία αγοράς</dt><dd><?php echo esc_html(TW_Util::date_label($w->purchase_date)); ?></dd>
            <dt>Λήξη εγγύησης</dt><dd><?php echo esc_html(TW_Util::date_label($w->warranty_end)); ?>
                <?php if ($w->status === 'active' && $days_left >= 0) : ?><small>(σε <?php echo (int) $days_left; ?> ημέρες)</small><?php endif; ?></dd>
            <?php if ($w->status === 'locked') : ?>
                <dt>Κλείδωμα</dt><dd><?php echo esc_html(TW_Util::datetime_label($w->locked_at)); ?> – <?php echo esc_html($w->lock_reason); ?></dd>
            <?php endif; ?>
        </dl>

        <h5>Πρόγραμμα Service</h5>
        <table class="tw-table">
            <thead><tr><th>Ορόσημο</th><th>Ημερομηνία</th><th>Κατάσταση</th></tr></thead>
            <tbody>
            <?php foreach ($timeline as $m) :
                if ($m['type'] === 'service') {
                    $label = sprintf('%dο Service (%d έτη)', $m['no'], $m['years']);
                    $sv = $m['service'];
                    $state = [
                        'done' => 'Εγκρίθηκε – Service ' . ($sv ? TW_Util::date_label($sv->performed_date) : ''),
                        'submitted' => 'Υποβλήθηκε ' . ($sv ? TW_Util::date_label($sv->performed_date) : '') . ' – σε έλεγχο',
                        'rejected' => 'Χρειάζεται νέα υποβολή έως ' . ($sv ? TW_Util::date_label($sv->resubmit_until) : ''),
                        'due' => 'Εκκρεμεί – καταχώρηση έως ' . TW_Util::date_label($m['lock_date']),
                        'missed' => 'Δεν καταχωρήθηκε',
                        'upcoming' => 'Προγραμματισμένο',
                        'before_registration' => '—',
                    ][$m['state']];
                } else {
                    $label = 'Λήξη εγγύησης';
                    $state = $m['state'] === 'expired' ? 'Έληξε' : 'Σε ισχύ';
                }
                ?>
                <tr class="tw-ms tw-ms--<?php echo esc_attr($m['state']); ?>"><td><?php echo esc_html($label); ?></td><td><?php echo esc_html(TW_Util::date_label($m['due'])); ?></td><td><?php echo esc_html($state); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php foreach ($timeline as $m) : if (!$m['can_submit']) continue; $sv = $m['service']; ?>
            <form class="tw-service-form" data-warranty="<?php echo (int) $w->id; ?>" novalidate enctype="multipart/form-data">
                <h5>Υποβολή <?php echo (int) $m['no']; ?>ου Service</h5>
                <?php if ($m['state'] === 'rejected' && $sv) : ?>
                    <p class="tw-alert tw-alert--error">Η προηγούμενη υποβολή δεν εγκρίθηκε: <?php echo esc_html($sv->review_note); ?>. Υποβάλετε ξανά έως <?php echo esc_html(TW_Util::date_label($sv->resubmit_until)); ?>.</p>
                <?php else : ?>
                    <p class="tw-hint">Μόλις γίνει το Service, καταχωρήστε το εδώ έως <strong><?php echo esc_html(TW_Util::date_label($m['lock_date'])); ?></strong>, ώστε η εγγύηση να συνεχίσει να ισχύει μέχρι το επόμενο Service.</p>
                <?php endif; ?>
                <input type="hidden" name="milestone_no" value="<?php echo (int) $m['no']; ?>">
                <div class="tw-grid">
                    <div class="tw-field">
                        <label>Ημερομηνία Service <span class="tw-req">*</span></label>
                        <input type="date" name="performed_date" required max="<?php echo esc_attr(TW_Schedule::today()); ?>" min="<?php echo esc_attr(TW_Schedule::add_days($m['due'], -max(0, (int) $s['service_submit_window_days']))); ?>">
                    </div>
                    <div class="tw-field">
                        <label>ΑΦΜ τεχνικού <span class="tw-req">*</span></label>
                        <input type="text" name="technician_vat" inputmode="numeric" maxlength="9" required>
                    </div>
                </div>
                <div class="tw-field">
                    <label>Ονοματεπώνυμο / επωνυμία τεχνικού</label>
                    <input type="text" name="technician" maxlength="200">
                </div>
                <div class="tw-field">
                    <label>Απόδειξη Service (PDF, JPG ή PNG, έως <?php echo (int) $s['invoice_max_mb']; ?> MB) <span class="tw-req">*</span></label>
                    <input type="file" name="receipt_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
                </div>
                <div class="tw-field">
                    <label>Σημειώσεις</label>
                    <input type="text" name="notes" maxlength="500">
                </div>
                <label class="tw-check"><input type="checkbox" name="genuine_parts" value="1" required> <span>Βεβαιώνω ότι το Service έγινε με αυθεντικά ανταλλακτικά <?php echo esc_html($s['company_name']); ?>. <span class="tw-req">*</span></span></label>
                <button type="submit" class="tw-btn">Υποβολή Service</button>
            </form>
        <?php endforeach; ?>

        <p class="tw-hint">Για το Service απευθυνθείτε στον συνεργάτη από τον οποίο αγοράσατε το προϊόν<?php echo $w->partner_email ? ' (' . esc_html($w->partner_email) . ')' : ''; ?> ή απευθείας στη <?php echo esc_html($s['company_name']); ?>: <?php echo esc_html($s['company_contact']); ?>. Το Service πρέπει να γίνεται με αυθεντικά ανταλλακτικά.</p>

        <details class="tw-details">
            <summary>Στοιχεία καταχώρησης</summary>
            <dl class="tw-dl">
                <dt>Ονοματεπώνυμο</dt><dd><?php echo esc_html($w->customer_name); ?></dd>
                <dt>Κινητό</dt><dd><?php echo esc_html($w->phone_mobile); ?></dd>
                <?php if ($w->phone_landline) : ?><dt>Σταθερό</dt><dd><?php echo esc_html($w->phone_landline); ?></dd><?php endif; ?>
                <dt>Διεύθυνση</dt><dd><?php echo esc_html(trim($w->address . ', ' . $w->postcode . ' ' . $w->city, ', ')); ?></dd>
                <dt>Συνεργάτης (ΑΦΜ)</dt><dd><?php echo esc_html($w->partner_vat); ?></dd>
                <dt>Παραστατικό</dt><dd><?php echo esc_html($w->invoice_number); ?></dd>
                <dt>Ενεργοποίηση</dt><dd><?php echo esc_html(TW_Util::datetime_label($w->created_at)); ?></dd>
            </dl>
            <p class="tw-hint">Για διόρθωση στοιχείων επικοινωνήστε μαζί μας: <?php echo esc_html($s['company_contact']); ?>.</p>
        </details>

        <label class="tw-check">
            <input type="checkbox" data-action="marketing" data-warranty="<?php echo (int) $w->id; ?>" <?php checked((int) $w->marketing_opt_in, 1); ?>>
            <span><?php echo esc_html($s['marketing_label']); ?></span>
        </label>
    </article>
<?php endforeach; ?>

<section class="tw-card tw-privacy-box">
    <h4>Τα δεδομένα μου</h4>
    <p>Μπορείτε να κατεβάσετε αντίγραφο των στοιχείων σας ή να υποβάλετε αίτημα διαγραφής.</p>
    <button type="button" class="tw-btn tw-btn--ghost" data-action="export">Λήψη των δεδομένων μου (JSON)</button>
    <button type="button" class="tw-btn tw-btn--ghost" data-action="erase">Αίτημα διαγραφής</button>
</section>
