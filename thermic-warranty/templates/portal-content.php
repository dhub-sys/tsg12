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
        <p><button type="button" class="tw-btn tw-btn--ghost tw-btn--sm" data-action="show-card" aria-expanded="false">🪪 Κάρτα εγγύησης</button></p>
        <?php $next = TW_Warranty::next_service($w); ?>
        <div class="tw-done tw-card-view" hidden data-print-title="<?php echo esc_attr($w->warranty_code . ' - ' . $w->customer_name); ?>">
            <div class="tw-card-logo" hidden><?php echo TW_App::logo_html('tw-card-logo-img'); // phpcs:ignore -- shown only on the printed card ?></div>
            <h3>Κάρτα εγγύησης</h3>
            <p>Ο κωδικός εγγύησής σας:</p>
            <p class="tw-warranty-code"><?php echo esc_html($w->warranty_code); ?></p>
            <dl class="tw-dl">
                <dt>Σειριακός αριθμός</dt><dd><?php echo esc_html($w->serial); ?></dd>
                <dt>Προϊόν</dt><dd><?php echo esc_html($w->product_name ?: ($w->product_code ?: '—')); ?></dd>
                <dt>Λήξη εγγύησης</dt><dd><?php echo esc_html(TW_Util::date_label($w->warranty_end)); ?></dd>
                <dt>Επόμενο Service</dt><dd><?php echo esc_html($next ? TW_Util::date_label($next['due']) : '—'); ?></dd>
                <?php if ($w->status !== 'active') : ?><dt>Κατάσταση</dt><dd><?php echo esc_html($labels[$w->status] ?? $w->status); ?></dd><?php endif; ?>
            </dl>
            <p class="tw-done-actions"><button type="button" class="tw-btn" data-action="print-card">🖨 Εκτύπωση κάρτας εγγύησης</button>
                <button type="button" class="tw-link" data-action="hide-card">Κλείσιμο</button></p>
        </div>

        <h5>Πρόγραμμα Service</h5>
        <table class="tw-table">
            <thead><tr><th>Ορόσημο</th><th>Ημερομηνία</th><th>Κατάσταση</th></tr></thead>
            <tbody>
            <?php foreach ($timeline as $m) :
                if ($m['type'] === 'service') {
                    $label = sprintf('%dο Service (%d έτη)', $m['no'], $m['years']);
                    $sv = $m['service'];
                    $state = [
                        'done' => 'Καταχωρήθηκε – Service ' . ($sv ? TW_Util::date_label($sv->performed_date) : ''),
                        'submitted' => 'Καταχωρήθηκε – Service ' . ($sv ? TW_Util::date_label($sv->performed_date) : ''),
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
                <?php echo TW_Guard::fields(); // phpcs:ignore ?>
                <div class="tw-grid">
                    <div class="tw-field">
                        <label>Ημερομηνία Service <span class="tw-req">*</span></label>
                        <input type="date" name="performed_date" required max="<?php echo esc_attr(TW_Schedule::today()); ?>" min="<?php echo esc_attr(TW_Schedule::add_days($m['due'], -max(0, (int) $s['service_submit_window_days']))); ?>">
                    </div>
                    <div class="tw-field">
                        <label>ΑΦΜ τεχνικού <span class="tw-req">*</span></label>
                        <?php echo TW_Util::tax_id_field('technician_vat', 'technician_country'); // phpcs:ignore ?>
                    </div>
                </div>
                <div class="tw-field">
                    <label>Ονοματεπώνυμο / επωνυμία τεχνικού</label>
                    <input type="text" name="technician" maxlength="200">
                </div>
                <?php if (TW_Settings::service_needs_fields()) : ?>
                <div class="tw-grid">
                    <div class="tw-field">
                        <label>Αριθμός απόδειξης / παραστατικού <span class="tw-req">*</span></label>
                        <input type="text" name="receipt_number" maxlength="100" required>
                    </div>
                    <div class="tw-field">
                        <label>Ημερομηνία παραστατικού <span class="tw-req">*</span></label>
                        <input type="date" name="receipt_date" required max="<?php echo esc_attr(TW_Schedule::today()); ?>">
                    </div>
                </div>
                <?php endif; ?>
                <?php if (TW_Settings::service_needs_file()) : ?>
                <div class="tw-field">
                    <label>Απόδειξη Service (PDF, JPG ή PNG, έως <?php echo (int) $s['invoice_max_mb']; ?> MB) <span class="tw-req">*</span></label>
                    <input type="file" name="receipt_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
                </div>
                <?php endif; ?>
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
                <dt>Συνεργάτης</dt><dd><?php echo esc_html(TW_Util::tax_id_text($w->partner_country, $w->partner_vat)); ?></dd>
                <dt>Παραστατικό</dt><dd><?php echo esc_html($w->invoice_number); ?></dd>
                <dt>Ενεργοποίηση</dt><dd><?php echo esc_html(TW_Util::datetime_label($w->created_at)); ?></dd>
            </dl>
            <p class="tw-hint">Για διόρθωση στοιχείων επικοινωνήστε μαζί μας: <?php echo esc_html($s['company_contact']); ?>.</p>
        </details>
    </article>
<?php endforeach; ?>

<?php
$mk = TW_Warranty::marketing_status($email);
$terms_rows = [];
foreach ($warranties as $w) {
    $c = $GLOBALS['wpdb']->get_row($GLOBALS['wpdb']->prepare("SELECT * FROM " . TW_DB::table('consents') . " WHERE warranty_id = %d AND consent_type = 'terms' ORDER BY id DESC LIMIT 1", $w->id));
    if ($c) $terms_rows[] = $c;
}
?>
<section class="tw-card tw-privacy-box" id="tw-my-data">
    <h4>Τα δεδομένα μου</h4>

    <div class="tw-consents">
        <h5>Οι άδειές μου</h5>
        <div class="tw-consent-row">
            <div><strong>Όροι εγγύησης</strong><br><span class="tw-hint">Απαραίτητοι για την εγγύηση. Τους αποδεχθήκατε κατά την ενεργοποίηση<?php echo $terms_rows ? ' (τελευταία φορά ' . esc_html(TW_Util::date_label(substr(max(array_map(function ($c) { return $c->created_at; }, $terms_rows)), 0, 10))) . ')' : ''; ?>.</span></div>
            <span class="tw-consent-state tw-consent-state--fixed">Απαραίτητο</span>
        </div>
        <div class="tw-consent-row">
            <div><strong>Μηνύματα για Service και εγγύηση</strong><br><span class="tw-hint">Υπενθυμίσεις Service, λήξης και επιβεβαιώσεις. Στέλνονται πάντα, γιατί αφορούν την εγγύηση που έχετε.</span></div>
            <span class="tw-consent-state tw-consent-state--fixed">Απαραίτητο</span>
        </div>
        <form class="tw-consent-row tw-consent-marketing" data-action="marketing-form" novalidate>
            <div>
                <strong>Ενημερώσεις και προσφορές</strong> <span class="tw-hint">(προαιρετικό)</span><br>
                <span class="tw-hint"><?php echo esc_html($s['marketing_label']); ?> Ισχύει για όλα τα προϊόντα σας<?php echo $mk['changed_at'] ? ' · τελευταία αλλαγή ' . esc_html(TW_Util::datetime_label($mk['changed_at'])) : ''; ?>.</span>
                <?php if ($mk['state'] === 'mixed') : ?><p class="tw-alert tw-alert--warn">Μέχρι σήμερα η επιλογή σας διέφερε ανά προϊόν. Επιλέξτε και αποθηκεύστε, για να ισχύει μία επιλογή για όλα.</p><?php endif; ?>
                <div class="tw-choice" role="radiogroup" aria-label="Ενημερώσεις και προσφορές">
                    <label><input type="radio" name="marketing" value="1" <?php checked($mk['state'], 'yes'); ?>> Ναι, θέλω να λαμβάνω</label>
                    <label><input type="radio" name="marketing" value="0" <?php checked($mk['state'], 'no'); ?>> Όχι</label>
                </div>
            </div>
            <div class="tw-consent-side">
                <span class="tw-consent-state tw-consent-state--<?php echo esc_attr($mk['state']); ?>"><?php echo esc_html(['yes' => 'Ενεργή', 'no' => 'Ανενεργή', 'mixed' => 'Μερική'][$mk['state']]); ?></span>
                <button type="submit" class="tw-btn" disabled>Αποθήκευση επιλογής</button>
            </div>
        </form>
    </div>

    <p>Δείτε, εκτυπώστε ή αποθηκεύστε ως PDF όλα τα στοιχεία που έχουμε για εσάς και τις εγγυήσεις σας. Χρησιμεύει και ως βεβαίωση της εγγύησης.</p>
    <a class="tw-btn" href="<?php echo esc_url(add_query_arg('copy', '1', TW_App::public_url('my'))); ?>" target="_blank" rel="noopener">📄 Αντίγραφο των στοιχείων μου</a>
    <button type="button" class="tw-btn tw-btn--ghost" data-action="erase">Αίτημα διαγραφής</button>
    <p class="tw-hint"><button type="button" class="tw-link" data-action="export">Αντίγραφο σε μορφή για μεταφορά σε άλλο σύστημα (JSON)</button></p>
</section>
