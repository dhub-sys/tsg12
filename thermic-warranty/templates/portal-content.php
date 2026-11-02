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
                    $state = [
                        'done' => 'Πραγματοποιήθηκε ' . ($m['service'] ? TW_Util::date_label($m['service']->performed_date) : ''),
                        'due' => 'Εκκρεμεί – έως ' . TW_Util::date_label($m['lock_date']),
                        'missed' => 'Δεν πραγματοποιήθηκε',
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
