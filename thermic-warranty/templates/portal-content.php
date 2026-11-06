<?php
/** @var object[] $warranties  @var string $email */
if (!defined('ABSPATH')) exit;
$labels = TW_Util::status_labels();
$s = TW_Settings::all();
$lang = TW_I18n::lang();
$s['company_contact'] = TW_Settings::company_contact($lang);
$mlabels = TW_Settings::consent_labels($lang);
?>
<div class="tw-portal-head">
    <h3><?php tw_e('Οι εγγυήσεις μου'); ?></h3>
    <p><?php tw_e('Συνδεδεμένος/η ως'); ?> <strong><?php echo esc_html($email); ?></strong>
        <button type="button" class="tw-link" data-action="logout"><?php tw_e('Αποσύνδεση'); ?></button></p>
</div>

<?php if (!$warranties) : ?>
    <p><?php tw_e('Δεν βρέθηκαν εγγυήσεις.'); ?></p>
<?php endif; ?>

<?php foreach ($warranties as $w) :
    $timeline = TW_Warranty::timeline($w);
    $days_left = TW_Schedule::diff_days(TW_Schedule::today(), $w->warranty_end);
    $status = tw__($labels[$w->status] ?? $w->status);
    ?>
    <article class="tw-card tw-card--<?php echo esc_attr($w->status); ?>">
        <header>
            <h4><?php echo esc_html($w->product_name ?: $w->product_code ?: tw__('Προϊόν')); ?></h4>
            <span class="tw-badge tw-badge--<?php echo esc_attr($w->status); ?>"><?php echo esc_html($status); ?></span>
        </header>
        <dl class="tw-dl">
            <dt><?php tw_e('Σειριακός αριθμός'); ?></dt><dd><code><?php echo esc_html($w->serial); ?></code></dd>
            <dt><?php tw_e('Κωδικός εγγύησης'); ?></dt><dd><code><?php echo esc_html($w->warranty_code); ?></code></dd>
            <dt><?php tw_e('Ημερομηνία αγοράς'); ?></dt><dd><?php echo esc_html(TW_Util::date_label($w->purchase_date)); ?></dd>
            <dt><?php tw_e('Λήξη εγγύησης'); ?></dt><dd><?php echo esc_html(TW_Util::date_label($w->warranty_end)); ?>
                <?php if ($w->status === 'active' && $days_left >= 0) : ?><small>(<?php echo esc_html(sprintf(tw__('σε %d ημέρες'), (int) $days_left)); ?>)</small><?php endif; ?></dd>
            <?php if ($w->status === 'locked') : ?>
                <dt><?php tw_e('Κλείδωμα'); ?></dt><dd><?php echo esc_html(TW_Util::datetime_label($w->locked_at)); ?> – <?php echo esc_html($w->lock_reason); ?></dd>
            <?php endif; ?>
        </dl>
        <p><button type="button" class="tw-btn tw-btn--ghost tw-btn--sm" data-action="show-card" aria-expanded="false">🪪 <?php tw_e('Κάρτα εγγύησης'); ?></button></p>
        <?php $next = TW_Warranty::next_service($w); ?>
        <div class="tw-done tw-card-view" hidden data-print-title="<?php echo esc_attr($w->warranty_code . ' - ' . $w->customer_name); ?>">
            <div class="tw-card-logo" hidden><?php echo TW_App::logo_html('tw-card-logo-img'); // phpcs:ignore -- shown only on the printed card ?></div>
            <h3><?php tw_e('Κάρτα εγγύησης'); ?></h3>
            <p><?php tw_e('Ο κωδικός εγγύησής σας:'); ?></p>
            <p class="tw-warranty-code"><?php echo esc_html($w->warranty_code); ?></p>
            <dl class="tw-dl">
                <dt><?php tw_e('Σειριακός αριθμός'); ?></dt><dd><?php echo esc_html($w->serial); ?></dd>
                <dt><?php tw_e('Προϊόν'); ?></dt><dd><?php echo esc_html($w->product_name ?: ($w->product_code ?: '—')); ?></dd>
                <dt><?php tw_e('Λήξη εγγύησης'); ?></dt><dd><?php echo esc_html(TW_Util::date_label($w->warranty_end)); ?></dd>
                <dt><?php tw_e('Επόμενο Service'); ?></dt><dd><?php echo esc_html($next ? TW_Util::date_label($next['due']) : '—'); ?></dd>
                <?php if ($w->status !== 'active') : ?><dt><?php tw_e('Κατάσταση'); ?></dt><dd><?php echo esc_html($status); ?></dd><?php endif; ?>
            </dl>
            <p class="tw-done-actions"><button type="button" class="tw-btn" data-action="print-card">🖨 <?php tw_e('Εκτύπωση κάρτας εγγύησης'); ?></button>
                <button type="button" class="tw-link" data-action="hide-card"><?php tw_e('Κλείσιμο'); ?></button></p>
        </div>

        <h5><?php tw_e('Πρόγραμμα Service'); ?></h5>
        <table class="tw-table">
            <thead><tr><th><?php tw_e('Ορόσημο'); ?></th><th><?php tw_e('Ημερομηνία'); ?></th><th><?php tw_e('Κατάσταση'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($timeline as $m) :
                if ($m['type'] === 'service') {
                    $label = sprintf(tw__('%1$s Service (%2$d έτη)'), TW_I18n::ordinal($m['no']), $m['years']);
                    $sv = $m['service'];
                    $state = [
                        'done' => sprintf(tw__('Καταχωρήθηκε – Service %s'), $sv ? TW_Util::date_label($sv->performed_date) : ''),
                        'submitted' => sprintf(tw__('Καταχωρήθηκε – Service %s'), $sv ? TW_Util::date_label($sv->performed_date) : ''),
                        'rejected' => sprintf(tw__('Χρειάζεται νέα υποβολή έως %s'), $sv ? TW_Util::date_label($sv->resubmit_until) : ''),
                        'due' => sprintf(tw__('Εκκρεμεί – καταχώρηση έως %s'), TW_Util::date_label($m['lock_date'])),
                        'missed' => tw__('Δεν καταχωρήθηκε'),
                        'upcoming' => tw__('Προγραμματισμένο'),
                        'before_registration' => '—',
                    ][$m['state']];
                } else {
                    $label = tw__('Λήξη εγγύησης');
                    $state = tw__($m['state'] === 'expired' ? 'Έληξε' : 'Σε ισχύ');
                }
                ?>
                <tr class="tw-ms tw-ms--<?php echo esc_attr($m['state']); ?>"><td><?php echo esc_html($label); ?></td><td><?php echo esc_html(TW_Util::date_label($m['due'])); ?></td><td><?php echo esc_html($state); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php foreach ($timeline as $m) : if (!$m['can_submit']) continue; $sv = $m['service']; ?>
            <form class="tw-service-form" data-warranty="<?php echo (int) $w->id; ?>" novalidate enctype="multipart/form-data">
                <h5><?php echo esc_html(sprintf(tw__('Υποβολή %s Service'), TW_I18n::lang() === 'en' ? TW_I18n::ordinal($m['no']) : $m['no'] . 'ου')); ?></h5>
                <?php if ($m['state'] === 'rejected' && $sv) : ?>
                    <p class="tw-alert tw-alert--error"><?php echo esc_html(sprintf(tw__('Η προηγούμενη υποβολή δεν εγκρίθηκε: %1$s. Υποβάλετε ξανά έως %2$s.'), $sv->review_note, TW_Util::date_label($sv->resubmit_until))); ?></p>
                <?php else : ?>
                    <p class="tw-hint"><?php echo wp_kses(sprintf(esc_html(tw__('Μόλις γίνει το Service, καταχωρήστε το εδώ έως %s, ώστε η εγγύηση να συνεχίσει να ισχύει μέχρι το επόμενο Service.')), '<strong>' . esc_html(TW_Util::date_label($m['lock_date'])) . '</strong>'), ['strong' => []]); ?></p>
                <?php endif; ?>
                <input type="hidden" name="milestone_no" value="<?php echo (int) $m['no']; ?>">
                <?php echo TW_Guard::fields(); // phpcs:ignore ?>
                <div class="tw-grid">
                    <div class="tw-field">
                        <label><?php tw_e('Ημερομηνία Service'); ?> <span class="tw-req">*</span></label>
                        <input type="date" name="performed_date" required max="<?php echo esc_attr(TW_Schedule::today()); ?>" min="<?php echo esc_attr(TW_Schedule::add_days($m['due'], -max(0, (int) $s['service_submit_window_days']))); ?>">
                    </div>
                    <div class="tw-field">
                        <label><?php tw_e('ΑΦΜ τεχνικού'); ?> <span class="tw-req">*</span></label>
                        <?php echo TW_Util::tax_id_field('technician_vat', 'technician_country', '', $w->partner_country ?: 'GR'); // phpcs:ignore ?>
                    </div>
                </div>
                <div class="tw-field">
                    <label><?php tw_e('Ονοματεπώνυμο / επωνυμία τεχνικού'); ?></label>
                    <input type="text" name="technician" maxlength="200">
                </div>
                <?php if (TW_Settings::service_needs_fields()) : ?>
                <div class="tw-grid">
                    <div class="tw-field">
                        <label><?php tw_e('Αριθμός απόδειξης / παραστατικού'); ?> <span class="tw-req">*</span></label>
                        <input type="text" name="receipt_number" maxlength="100" required>
                    </div>
                    <div class="tw-field">
                        <label><?php tw_e('Ημερομηνία παραστατικού'); ?> <span class="tw-req">*</span></label>
                        <input type="date" name="receipt_date" required max="<?php echo esc_attr(TW_Schedule::today()); ?>">
                    </div>
                </div>
                <?php endif; ?>
                <?php if (TW_Settings::service_needs_file()) : ?>
                <div class="tw-field">
                    <label><?php echo esc_html(sprintf(tw__('Απόδειξη Service (PDF, JPG ή PNG, έως %d MB)'), (int) $s['invoice_max_mb'])); ?> <span class="tw-req">*</span></label>
                    <input type="file" name="receipt_file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
                </div>
                <?php endif; ?>
                <div class="tw-field">
                    <label><?php tw_e('Σημειώσεις'); ?></label>
                    <input type="text" name="notes" maxlength="500">
                </div>
                <label class="tw-check"><input type="checkbox" name="genuine_parts" value="1" required> <span><?php echo esc_html(sprintf(tw__('Βεβαιώνω ότι το Service έγινε με αυθεντικά ανταλλακτικά %s.'), $s['company_name'])); ?> <span class="tw-req">*</span></span></label>
                <button type="submit" class="tw-btn"><?php tw_e('Υποβολή Service'); ?></button>
            </form>
        <?php endforeach; ?>

        <p class="tw-hint"><?php echo esc_html($w->partner_email
            ? sprintf(tw__('Για το Service απευθυνθείτε στον συνεργάτη από τον οποίο αγοράσατε το προϊόν (%1$s) ή απευθείας στη %2$s: %3$s. Το Service πρέπει να γίνεται με αυθεντικά ανταλλακτικά.'), $w->partner_email, $s['company_name'], $s['company_contact'])
            : sprintf(tw__('Για το Service απευθυνθείτε στον συνεργάτη από τον οποίο αγοράσατε το προϊόν ή απευθείας στη %1$s: %2$s. Το Service πρέπει να γίνεται με αυθεντικά ανταλλακτικά.'), $s['company_name'], $s['company_contact'])); ?></p>

        <details class="tw-details">
            <summary><?php tw_e('Στοιχεία καταχώρησης'); ?></summary>
            <dl class="tw-dl">
                <dt><?php tw_e('Ονοματεπώνυμο'); ?></dt><dd><?php echo esc_html($w->customer_name); ?></dd>
                <dt><?php tw_e('Κινητό'); ?></dt><dd><?php echo esc_html($w->phone_mobile); ?></dd>
                <?php if ($w->phone_landline) : ?><dt><?php tw_e('Σταθερό'); ?></dt><dd><?php echo esc_html($w->phone_landline); ?></dd><?php endif; ?>
                <dt><?php tw_e('Διεύθυνση'); ?></dt><dd><?php echo esc_html(trim($w->address . ', ' . $w->postcode . ' ' . $w->city, ', ')); ?></dd>
                <dt><?php tw_e('Συνεργάτης'); ?></dt><dd><?php echo esc_html(TW_Util::tax_id_text($w->partner_country, $w->partner_vat)); ?></dd>
                <dt><?php tw_e('Παραστατικό'); ?></dt><dd><?php echo esc_html($w->invoice_number); ?></dd>
                <dt><?php tw_e('Ενεργοποίηση'); ?></dt><dd><?php echo esc_html(TW_Util::datetime_label($w->created_at)); ?></dd>
            </dl>
            <p class="tw-hint"><?php echo esc_html(sprintf(tw__('Για διόρθωση στοιχείων επικοινωνήστε μαζί μας: %s.'), $s['company_contact'])); ?></p>
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
$cust_lang = TW_Warranty::customer_lang($email);
?>
<section class="tw-card tw-privacy-box" id="tw-my-data">
    <h4><?php tw_e('Τα δεδομένα μου'); ?></h4>

    <div class="tw-consents">
        <h5><?php tw_e('Οι άδειές μου'); ?></h5>
        <div class="tw-consent-row">
            <div><strong><?php tw_e('Όροι εγγύησης'); ?></strong><br><span class="tw-hint"><?php echo esc_html($terms_rows
                ? sprintf(tw__('Απαραίτητοι για την εγγύηση. Τους αποδεχθήκατε κατά την ενεργοποίηση (τελευταία φορά %s).'), TW_Util::date_label(substr(max(array_map(function ($c) { return $c->created_at; }, $terms_rows)), 0, 10)))
                : tw__('Απαραίτητοι για την εγγύηση. Τους αποδεχθήκατε κατά την ενεργοποίηση.')); ?></span></div>
            <span class="tw-consent-state tw-consent-state--fixed"><?php tw_e('Απαραίτητο'); ?></span>
        </div>
        <div class="tw-consent-row">
            <div><strong><?php tw_e('Μηνύματα για Service και εγγύηση'); ?></strong><br><span class="tw-hint"><?php tw_e('Υπενθυμίσεις Service, λήξης και επιβεβαιώσεις. Στέλνονται πάντα, γιατί αφορούν την εγγύηση που έχετε.'); ?></span></div>
            <span class="tw-consent-state tw-consent-state--fixed"><?php tw_e('Απαραίτητο'); ?></span>
        </div>
        <form class="tw-consent-row tw-consent-marketing" data-action="marketing-form" novalidate>
            <div>
                <strong><?php tw_e('Ενημερώσεις και προσφορές'); ?></strong> <span class="tw-hint">(<?php tw_e('προαιρετικό'); ?>)</span><br>
                <span class="tw-hint"><?php echo esc_html($mlabels['marketing']); ?> <?php tw_e('Ισχύει για όλα τα προϊόντα σας'); ?><?php echo $mk['changed_at'] ? ' · ' . esc_html(sprintf(tw__('τελευταία αλλαγή %s'), TW_Util::datetime_label($mk['changed_at']))) : ''; ?>.</span>
                <?php if ($mk['state'] === 'mixed') : ?><p class="tw-alert tw-alert--warn"><?php tw_e('Μέχρι σήμερα η επιλογή σας διέφερε ανά προϊόν. Επιλέξτε και αποθηκεύστε, για να ισχύει μία επιλογή για όλα.'); ?></p><?php endif; ?>
                <div class="tw-choice" role="radiogroup" aria-label="<?php echo esc_attr(tw__('Ενημερώσεις και προσφορές')); ?>">
                    <label><input type="radio" name="marketing" value="1" <?php checked($mk['state'], 'yes'); ?>> <?php tw_e('Ναι, θέλω να λαμβάνω'); ?></label>
                    <label><input type="radio" name="marketing" value="0" <?php checked($mk['state'], 'no'); ?>> <?php tw_e('Όχι'); ?></label>
                </div>
            </div>
            <div class="tw-consent-side">
                <span class="tw-consent-state tw-consent-state--<?php echo esc_attr($mk['state']); ?>"><?php echo esc_html(tw__(['yes' => 'Ενεργή', 'no' => 'Ανενεργή', 'mixed' => 'Μερική'][$mk['state']])); ?></span>
                <button type="submit" class="tw-btn" disabled><?php tw_e('Αποθήκευση επιλογής'); ?></button>
            </div>
        </form>
        <form class="tw-consent-row tw-consent-lang" data-action="lang-form" novalidate>
            <div>
                <strong><?php tw_e('Γλώσσα επικοινωνίας'); ?></strong><br>
                <span class="tw-hint"><?php tw_e('Η γλώσσα των email και των SMS που σας στέλνουμε, για όλα τα προϊόντα σας.'); ?></span>
                <div class="tw-choice" role="radiogroup" aria-label="<?php echo esc_attr(tw__('Γλώσσα επικοινωνίας')); ?>">
                    <?php foreach (TW_I18n::languages() as $code => $name) : ?>
                        <label lang="<?php echo esc_attr($code); ?>"><input type="radio" name="comm_lang" value="<?php echo esc_attr($code); ?>" <?php checked($cust_lang, $code); ?>> <?php echo esc_html($name); ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="tw-consent-side">
                <button type="submit" class="tw-btn" disabled><?php tw_e('Αποθήκευση επιλογής'); ?></button>
            </div>
        </form>
    </div>

    <p><?php tw_e('Δείτε, εκτυπώστε ή αποθηκεύστε ως PDF όλα τα στοιχεία που έχουμε για εσάς και τις εγγυήσεις σας. Χρησιμεύει και ως βεβαίωση της εγγύησης.'); ?></p>
    <a class="tw-btn" href="<?php echo esc_url(add_query_arg(['copy' => '1', 'lang' => $lang], TW_App::public_url('my'))); ?>" target="_blank" rel="noopener">📄 <?php tw_e('Αντίγραφο των στοιχείων μου'); ?></a>
    <button type="button" class="tw-btn tw-btn--ghost" data-action="erase"><?php tw_e('Αίτημα διαγραφής'); ?></button>
    <p class="tw-hint"><button type="button" class="tw-link" data-action="export"><?php tw_e('Αντίγραφο σε μορφή για μεταφορά σε άλλο σύστημα (JSON)'); ?></button></p>
</section>
