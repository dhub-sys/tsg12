<?php
/**
 * Human-readable copy of everything held about the signed-in customer (GDPR right of access),
 * also usable as a warranty certificate. Printable / save as PDF from the browser.
 *
 * @var array $s  @var string $email  @var object[] $warranties
 */
if (!defined('ABSPATH')) exit;
global $wpdb;
$labels = TW_Util::status_labels();
$privacy = $s['privacy_url'] ?: get_privacy_policy_url();
$consent_labels = ['terms' => 'Αποδοχή όρων εγγύησης', 'data_accuracy' => 'Επιβεβαίωση ορθότητας στοιχείων', 'marketing' => 'Ενημερώσεις και προσφορές (email/SMS)'];
$source_labels = ['registration' => 'κατά την ενεργοποίηση', 'portal' => 'από «Οι εγγυήσεις μου»', 'erasure_request' => 'με αίτημα διαγραφής'];
$channel_labels = ['email' => 'Email', 'sms' => 'SMS', 'partner' => 'Email συνεργάτη'];
$review_labels = ['pending' => 'Καταχωρήθηκε', 'approved' => 'Καταχωρήθηκε – ελέγχθηκε από την εταιρεία', 'rejected' => 'Απορρίφθηκε'];
?><!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <?php echo TW_App::favicon_tag(); // phpcs:ignore ?>
    <title>Αντίγραφο στοιχείων – <?php echo esc_html($s['company_name']); ?></title>
    <link rel="stylesheet" href="<?php echo esc_url(TW_App::asset('assets/css/fonts.css')); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(TW_App::asset('assets/css/app-copy.css')); ?>">
    <?php echo TW_App::brand_style(); // phpcs:ignore ?>
</head>
<body class="tw-copy">
<div class="tw-copy-bar">
    <button type="button" onclick="window.print()">🖨 Εκτύπωση / Αποθήκευση ως PDF</button>
    <a href="<?php echo esc_url(TW_App::public_url('my')); ?>">← Οι εγγυήσεις μου</a>
</div>
<main class="tw-copy-page">
    <header class="tw-copy-head">
        <div><?php echo TW_App::logo_html('tw-copy-logo'); // phpcs:ignore ?></div>
        <div class="tw-copy-meta">
            <h1>Αντίγραφο στοιχείων εγγύησης</h1>
            <p>Για: <strong><?php echo esc_html($email); ?></strong><br>Ημερομηνία: <?php echo esc_html(TW_Util::datetime_label(TW_DB::now())); ?></p>
        </div>
    </header>

    <p class="tw-copy-intro">Το έγγραφο περιέχει όλα τα στοιχεία που διατηρεί η <?php echo esc_html($s['company_name']); ?> για τις εγγυήσεις των προϊόντων σας, όπως καταχωρήθηκαν στην πλατφόρμα εγγυήσεων. Μπορείτε να το φυλάξετε ως βεβαίωση της εγγύησης.</p>

    <?php if (!$warranties) : ?><p>Δεν βρέθηκαν εγγυήσεις.</p><?php endif; ?>

    <?php foreach ($warranties as $i => $w) :
        $timeline = TW_Warranty::timeline($w);
        $history = TW_Warranty::services_history($w->id);
        $consents = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . TW_DB::table('consents') . " WHERE warranty_id = %d ORDER BY id", $w->id));
        $sent = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . TW_DB::table('notifications') . " WHERE warranty_id = %d AND status = 'sent' AND channel <> 'partner' ORDER BY id", $w->id));
        ?>
        <section class="tw-copy-warranty">
            <h2><?php echo (int) ($i + 1); ?>. <?php echo esc_html($w->product_name ?: $w->product_code ?: 'Προϊόν'); ?>
                <span class="tw-copy-status tw-copy-status--<?php echo esc_attr($w->status); ?>"><?php echo esc_html($labels[$w->status] ?? $w->status); ?></span></h2>

            <h3>Εγγύηση</h3>
            <table class="tw-copy-kv">
                <tr><th>Κωδικός εγγύησης</th><td><strong><?php echo esc_html($w->warranty_code); ?></strong></td></tr>
                <tr><th>Σειριακός αριθμός</th><td><?php echo esc_html($w->serial); ?></td></tr>
                <tr><th>Προϊόν</th><td><?php echo esc_html(trim($w->product_name . ($w->product_code ? ' (' . $w->product_code . ')' : ''))); ?></td></tr>
                <tr><th>Ημερομηνία αγοράς</th><td><?php echo esc_html(TW_Util::date_label($w->purchase_date)); ?></td></tr>
                <tr><th>Αριθμός παραστατικού αγοράς</th><td><?php echo esc_html($w->invoice_number); ?><?php echo $w->invoice_file ? ' (έχει ανέβει αρχείο)' : ''; ?></td></tr>
                <tr><th>Λήξη εγγύησης</th><td><?php echo esc_html(TW_Util::date_label($w->warranty_end)); ?></td></tr>
                <tr><th>Ενεργοποίηση</th><td><?php echo esc_html(TW_Util::datetime_label($w->created_at)); ?></td></tr>
                <?php if ($w->status === 'locked') : ?><tr><th>Κλείδωμα</th><td><?php echo esc_html(TW_Util::datetime_label($w->locked_at) . ' – ' . $w->lock_reason); ?></td></tr><?php endif; ?>
            </table>

            <h3>Στοιχεία κατόχου</h3>
            <table class="tw-copy-kv">
                <tr><th>Ονοματεπώνυμο</th><td><?php echo esc_html($w->customer_name); ?></td></tr>
                <tr><th>Email</th><td><?php echo esc_html($w->email); ?></td></tr>
                <tr><th>Κινητό τηλέφωνο</th><td><?php echo esc_html($w->phone_mobile); ?></td></tr>
                <tr><th>Σταθερό τηλέφωνο</th><td><?php echo esc_html($w->phone_landline ?: '—'); ?></td></tr>
                <tr><th>Διεύθυνση</th><td><?php echo esc_html(trim($w->address . ', ' . $w->postcode . ' ' . $w->city, ', ')); ?></td></tr>
                <tr><th>Συνεργάτης (πωλητής)</th><td>ΑΦΜ <?php echo esc_html($w->partner_vat); ?> – <?php echo esc_html($w->partner_email); ?></td></tr>
            </table>

            <h3>Πρόγραμμα Service</h3>
            <table class="tw-copy-table">
                <thead><tr><th>Ορόσημο</th><th>Ημερομηνία</th><th>Κατάσταση</th></tr></thead>
                <tbody>
                <?php foreach ($timeline as $m) :
                    if ($m['type'] === 'service') {
                        $label = sprintf('%dο Service (%d έτη)', $m['no'], $m['years']);
                        $state = ['done' => 'Καταχωρήθηκε', 'submitted' => 'Καταχωρήθηκε', 'rejected' => 'Χρειάζεται νέα καταχώρηση', 'due' => 'Εκκρεμεί – καταχώρηση έως ' . TW_Util::date_label($m['lock_date']),
                            'missed' => 'Δεν καταχωρήθηκε', 'upcoming' => 'Προγραμματισμένο', 'before_registration' => '—'][$m['state']];
                    } else {
                        $label = 'Λήξη εγγύησης';
                        $state = $m['state'] === 'expired' ? 'Έληξε' : 'Σε ισχύ';
                    } ?>
                    <tr><td><?php echo esc_html($label); ?></td><td><?php echo esc_html(TW_Util::date_label($m['due'])); ?></td><td><?php echo esc_html($state); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h3>Καταχωρήσεις Service</h3>
            <?php if (!$history) : ?><p class="tw-copy-muted">Καμία καταχώρηση.</p><?php else : ?>
            <table class="tw-copy-table">
                <thead><tr><th>Service</th><th>Ημ/νία Service</th><th>Τεχνικός</th><th>Απόδειξη</th><th>Καταχώρηση</th><th>Κατάσταση</th></tr></thead>
                <tbody>
                <?php foreach (array_reverse($history) as $sv) : ?>
                    <tr><td><?php echo (int) $sv->milestone_no; ?>ο</td><td><?php echo esc_html(TW_Util::date_label($sv->performed_date)); ?></td>
                        <td><?php echo esc_html(trim(($sv->technician_vat ? 'ΑΦΜ ' . $sv->technician_vat : '') . ($sv->technician ? ' – ' . $sv->technician : ''), ' –') ?: '—'); ?></td>
                        <td><?php echo esc_html(TW_Warranty::receipt_text($sv)); ?></td>
                        <td><?php echo esc_html(TW_Util::datetime_label($sv->created_at)); ?> (<?php echo $sv->source === 'customer' ? 'από εσάς' : 'από την εταιρεία'; ?>)</td>
                        <td><?php echo esc_html($review_labels[$sv->review_status] ?? $sv->review_status); ?><?php echo $sv->review_status === 'rejected' && $sv->review_note ? ': ' . esc_html($sv->review_note) : ''; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <h3>Συγκαταθέσεις</h3>
            <table class="tw-copy-table">
                <thead><tr><th>Ημ/νία</th><th>Θέμα</th><th>Απόφαση</th><th>Πώς</th></tr></thead>
                <tbody>
                <?php foreach ($consents as $c) : ?>
                    <tr><td><?php echo esc_html(TW_Util::datetime_label($c->created_at)); ?></td>
                        <td><?php echo esc_html($consent_labels[$c->consent_type] ?? $c->consent_type); ?><?php echo $c->terms_version ? ' (όροι έκδοσης ' . (int) $c->terms_version . ')' : ''; ?></td>
                        <td><?php echo $c->granted ? 'Ναι' : 'Όχι'; ?></td>
                        <td><?php echo esc_html($source_labels[$c->source] ?? $c->source); ?><?php echo $c->ip ? ', IP ' . esc_html($c->ip) : ''; ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="tw-copy-muted">Σήμερα: ενημερώσεις και προσφορές <strong><?php echo $w->marketing_opt_in ? 'ΝΑΙ' : 'ΟΧΙ'; ?></strong>. Τα μηνύματα για το Service και την εγγύηση αποστέλλονται ανεξάρτητα από αυτή την επιλογή.</p>

            <h3>Μηνύματα που σας στάλθηκαν</h3>
            <?php if (!$sent) : ?><p class="tw-copy-muted">Κανένα μήνυμα ακόμη.</p><?php else : ?>
            <table class="tw-copy-table">
                <thead><tr><th>Ημ/νία</th><th>Θέμα</th><th>Μέσο</th></tr></thead>
                <tbody>
                <?php foreach ($sent as $n) : ?>
                    <tr><td><?php echo esc_html(TW_Util::datetime_label($n->created_at)); ?></td><td><?php echo esc_html(TW_Cron::row_label($n)); ?></td><td><?php echo esc_html($channel_labels[$n->channel] ?? $n->channel); ?><?php echo $n->delivery_status === 'delivered' ? ' – παραδόθηκε' : (in_array($n->delivery_status, ['failed', 'complaint'], true) ? ' – δεν παραδόθηκε' : ''); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <section class="tw-copy-info">
        <h2>Πώς χρησιμοποιούμε τα στοιχεία σας</h2>
        <ul>
            <li><strong>Σκοπός:</strong> η ενεργοποίηση και η εξυπηρέτηση της εγγύησης και οι υπενθυμίσεις για το Service και τη λήξη της. Ενημερώσεις και προσφορές στέλνουμε μόνο αν έχετε συμφωνήσει.</li>
            <li><strong>Διάρκεια:</strong> για όσο ισχύει η εγγύηση και για το διάστημα που απαιτεί η νομοθεσία· στη συνέχεια τα στοιχεία ανωνυμοποιούνται.</li>
            <li><strong>Αποδέκτες:</strong> η <?php echo esc_html($s['company_name']); ?> και οι πάροχοι που στέλνουν τα email/SMS για λογαριασμό της.</li>
            <li><strong>Διόρθωση στοιχείων:</strong> επικοινωνήστε μαζί μας: <?php echo esc_html($s['company_contact']); ?>.</li>
            <li><strong>Διαγραφή:</strong> από «Οι εγγυήσεις μου» → «Αίτημα διαγραφής». Τα στοιχεία ενεργών εγγυήσεων διατηρούνται όσο διαρκεί η εγγύηση, ώστε να μπορεί να εξυπηρετηθεί.</li>
            <?php if ($privacy) : ?><li><strong>Πολιτική απορρήτου:</strong> <?php echo esc_html($privacy); ?></li><?php endif; ?>
        </ul>
    </section>
    <footer class="tw-copy-foot"><?php echo esc_html($s['company_name'] . ' – ' . $s['company_contact']); ?></footer>
</main>
</body>
</html>
