<?php
/**
 * Print / PDF version of a warranty card, laid out for A4.
 * Header and footer repeat on every page (table thead/tfoot), the watermark is a fixed element
 * (repeated by the browser on every printed page), page numbers come from @page margin boxes.
 * The document title becomes the PDF file name: "<warranty code> - <customer name>".
 *
 * @var object $w  @var array $s
 */
if (!defined('ABSPATH')) exit;
global $wpdb;
$labels = TW_Util::status_labels();
$timeline = TW_Warranty::timeline($w);
$history = TW_Warranty::services_history($w->id);
$validity = TW_Warranty::validity($w);
$consents = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . TW_DB::table('consents') . " WHERE warranty_id = %d ORDER BY id", $w->id));
$notes = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . TW_DB::table('notifications') . " WHERE warranty_id = %d ORDER BY id", $w->id));
$events = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . TW_DB::table('events') . " WHERE warranty_id = %d ORDER BY id DESC LIMIT 60", $w->id));
$user = wp_get_current_user();
[$c1, $c2] = TW_App::colors();
$title = trim($w->warranty_code . ' - ' . $w->customer_name);
$ms_labels = ['done' => 'Καταχωρήθηκε – ελέγχθηκε', 'submitted' => 'Καταχωρήθηκε από τον πελάτη', 'rejected' => 'Απορρίφθηκε – αναμένεται νέα υποβολή', 'due' => 'Εκκρεμεί καταχώρηση',
    'missed' => 'Δεν καταχωρήθηκε', 'upcoming' => 'Προσεχώς', 'before_registration' => 'Πριν από την ενεργοποίηση', 'expired' => 'Έληξε'];
$consent_labels = ['terms' => 'Αποδοχή όρων εγγύησης', 'data_accuracy' => 'Ορθότητα στοιχείων', 'marketing' => 'Ενημερώσεις & προσφορές'];
$source_labels = ['registration' => 'Ενεργοποίηση', 'portal' => 'Πύλη πελάτη', 'erasure_request' => 'Αίτημα διαγραφής'];
$channels = ['email' => 'Email', 'sms' => 'SMS', 'partner' => 'Email συνεργάτη'];
$delivery = TW_Brevo::status_labels();
$wm_type = $s['pdf_watermark'];
$wm_opacity = max(0, min(100, (int) $s['pdf_watermark_opacity'])) / 100;
$wm_text = trim((string) $s['pdf_watermark_text']) !== '' ? $s['pdf_watermark_text'] : $s['company_name'];
$printed = sprintf('Εκτύπωση: %s – %s', TW_Util::datetime_label(TW_DB::now()), $user->display_name);
?><!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo esc_html($title); ?></title>
    <link rel="stylesheet" href="<?php echo esc_url(TW_App::asset('assets/css/fonts.css')); ?>">
    <link rel="stylesheet" href="<?php echo esc_url(TW_App::asset('assets/css/print.css')); ?>">
    <style>
        :root { --brand: <?php echo esc_html($c1); ?>; --side: <?php echo esc_html($c2); ?>; --wm-opacity: <?php echo esc_html((string) $wm_opacity); ?>; }
        @page { size: A4; margin: 14mm 12mm 16mm;
            @bottom-right { content: "Σελίδα " counter(page) " από " counter(pages); font: 8.5pt 'Geom', Arial, sans-serif; color: #4b5563; }
            @bottom-left { content: "<?php echo esc_html(str_replace('"', '', $title)); ?>"; font: 8.5pt 'Geom', Arial, sans-serif; color: #4b5563; } }
    </style>
</head>
<body>
<div class="toolbar">
    <button type="button" onclick="window.print()">Εκτύπωση / Αποθήκευση ως PDF</button>
    <span>Όνομα αρχείου: <strong><?php echo esc_html($title); ?>.pdf</strong></span>
    <a href="<?php echo esc_url(TW_App::admin_url('warranties', ['view' => $w->id])); ?>">← Πίσω στην καρτέλα</a>
</div>

<?php if ($wm_type !== 'none' && $wm_opacity > 0) : ?>
    <div class="watermark" aria-hidden="true">
        <?php if ($wm_type === 'logo' && $s['logo_url']) : ?><img src="<?php echo esc_url($s['logo_url']); ?>" alt="">
        <?php else : ?><span><?php echo esc_html($wm_text); ?></span><?php endif; ?>
    </div>
<?php endif; ?>

<table class="page">
    <thead><tr><td>
        <header class="doc-head">
            <div class="brand"><?php if ($s['logo_url']) : ?><img src="<?php echo esc_url($s['logo_url']); ?>" alt="<?php echo esc_attr($s['company_name']); ?>"><?php else : ?><span class="brand-text"><?php echo esc_html($s['company_name']); ?></span><?php endif; ?></div>
            <div class="head-meta">
                <div class="doc-title">Καρτέλα εγγύησης</div>
                <div class="doc-code"><?php echo esc_html($w->warranty_code); ?> · S/N <?php echo esc_html($w->serial); ?></div>
            </div>
        </header>
    </td></tr></thead>
    <tfoot><tr><td>
        <footer class="doc-foot">
            <span><?php echo esc_html($s['company_name']); ?> – <?php echo esc_html($s['company_contact']); ?></span>
            <span><?php echo esc_html($printed); ?></span>
        </footer>
    </td></tr></tfoot>
    <tbody><tr><td>

        <div class="validity validity--<?php echo $validity['ok'] ? 'ok' : 'bad'; ?>">
            <div class="validity-title"><?php echo $validity['ok'] ? '✔' : '✘'; ?> <?php echo esc_html($validity['title']); ?></div>
            <ul><?php foreach ($validity['lines'] as $l) echo '<li>' . esc_html($l) . '</li>'; ?></ul>
            <div class="validity-meta">Κατάσταση: <strong><?php echo esc_html($labels[$w->status] ?? $w->status); ?></strong><?php if ($w->status === 'locked') : ?> – <?php echo esc_html(TW_Util::datetime_label($w->locked_at) . ' – ' . $w->lock_reason); ?><?php endif; ?> · στοιχεία της <?php echo esc_html(TW_Util::datetime_label(TW_DB::now())); ?></div>
        </div>

        <div class="cols">
            <section class="box">
                <h2>Προϊόν &amp; εγγύηση</h2>
                <table class="kv">
                    <tr><th>Κωδικός εγγύησης</th><td><strong><?php echo esc_html($w->warranty_code); ?></strong></td></tr>
                    <tr><th>Σειριακός αριθμός</th><td><?php echo esc_html($w->serial); ?></td></tr>
                    <tr><th>Προϊόν</th><td><?php echo esc_html(trim($w->product_name . ($w->product_code ? ' (' . $w->product_code . ')' : '')) ?: '—'); ?></td></tr>
                    <tr><th>Ημερομηνία αγοράς</th><td><?php echo esc_html(TW_Util::date_label($w->purchase_date)); ?></td></tr>
                    <tr><th>Λήξη εγγύησης</th><td><?php echo esc_html(TW_Util::date_label($w->warranty_end)); ?></td></tr>
                    <tr><th>Ενεργοποίηση</th><td><?php echo esc_html(TW_Util::datetime_label($w->created_at)); ?></td></tr>
                    <tr><th>Παραστατικό αγοράς</th><td><?php echo esc_html($w->invoice_number ?: '—'); ?><?php echo $w->invoice_file ? ' (αρχείο στο σύστημα)' : ''; ?></td></tr>
                </table>
            </section>
            <section class="box">
                <h2>Πελάτης</h2>
                <table class="kv">
                    <tr><th>Ονοματεπώνυμο</th><td><strong><?php echo esc_html($w->customer_name); ?></strong></td></tr>
                    <tr><th>Email</th><td><?php echo esc_html($w->email); ?></td></tr>
                    <tr><th>Κινητό</th><td><?php echo esc_html($w->phone_mobile ?: '—'); ?></td></tr>
                    <tr><th>Σταθερό</th><td><?php echo esc_html($w->phone_landline ?: '—'); ?></td></tr>
                    <tr><th>Διεύθυνση</th><td><?php echo esc_html(trim($w->address . ', ' . $w->postcode . ' ' . $w->city, ', ') ?: '—'); ?></td></tr>
                    <tr><th>Συνεργάτης</th><td>ΑΦΜ <?php echo esc_html($w->partner_vat); ?><br><?php echo esc_html($w->partner_email); ?></td></tr>
                    <tr><th>Ενημερώσεις</th><td><?php echo $w->marketing_opt_in ? 'Ναι' : 'Όχι'; ?></td></tr>
                </table>
            </section>
        </div>

        <h2 class="section">Πρόγραμμα Service &amp; λήξης</h2>
        <table class="grid">
            <thead><tr><th>Ορόσημο</th><th>Ημερομηνία</th><th>Κλείδωμα αν δεν γίνει</th><th>Κατάσταση</th><th>Καταχώρηση</th></tr></thead>
            <tbody>
            <?php foreach ($timeline as $m) : $sv = $m['service']; ?>
                <tr class="st-<?php echo esc_attr($m['state']); ?>">
                    <td><?php echo $m['type'] === 'service' ? (int) $m['no'] . 'ο Service (' . (int) $m['years'] . ' έτη)' : 'Λήξη εγγύησης'; ?></td>
                    <td><?php echo esc_html(TW_Util::date_label($m['due'])); ?></td>
                    <td><?php echo esc_html(TW_Util::date_label($m['lock_date'])); ?></td>
                    <td><span class="pill pill-<?php echo esc_attr($m['state']); ?>"><?php echo esc_html($ms_labels[$m['state']] ?? $m['state']); ?></span></td>
                    <td><?php echo $sv ? esc_html(TW_Util::date_label($sv->performed_date) . ' · ΑΦΜ ' . ($sv->technician_vat ?: '—')) : '—'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2 class="section">Υποβολές Service (<?php echo count($history); ?>)</h2>
        <?php if (!$history) : ?><p class="muted">Καμία υποβολή.</p><?php else : ?>
        <table class="grid">
            <thead><tr><th>Καταχώρηση</th><th>Service</th><th>Ημ/νία Service</th><th>Τεχνικός</th><th>Απόδειξη</th><th>Γνήσια</th><th>Από</th><th>Έλεγχος</th></tr></thead>
            <tbody>
            <?php $latest = TW_Warranty::services($w->id); foreach ($history as $sv) : $is_latest = isset($latest[$sv->milestone_no]) && (int) $latest[$sv->milestone_no]->id === (int) $sv->id; ?>
                <tr<?php echo $is_latest ? '' : ' class="superseded"'; ?>>
                    <td><?php echo esc_html(TW_Util::datetime_label($sv->created_at)); ?><?php echo $is_latest ? '' : '<br><small>αντικαταστάθηκε</small>'; ?></td>
                    <td><?php echo (int) $sv->milestone_no; ?>ο</td>
                    <td><?php echo esc_html(TW_Util::date_label($sv->performed_date)); ?></td>
                    <td><?php echo esc_html(($sv->technician_vat ? 'ΑΦΜ ' . $sv->technician_vat : '—') . ($sv->technician ? ' – ' . $sv->technician : '')); ?></td>
                    <td><?php echo esc_html(TW_Warranty::receipt_text($sv)); ?></td>
                    <td><?php echo $sv->genuine_parts ? 'Ναι' : 'Όχι'; ?></td>
                    <td><?php echo $sv->source === 'customer' ? 'Πελάτης' : 'Εταιρεία'; ?></td>
                    <td><?php echo esc_html(['approved' => 'Ελέγχθηκε', 'rejected' => 'Απορρίφθηκε' . ($sv->review_note ? ': ' . $sv->review_note : ''), 'pending' => 'Δεν έχει ελεγχθεί'][$sv->review_status] ?? $sv->review_status); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <h2 class="section">Συγκαταθέσεις (αποδεικτικό GDPR)</h2>
        <table class="grid">
            <thead><tr><th>Ημ/νία</th><th>Θέμα</th><th>Απόφαση</th><th>Έκδοση όρων</th><th>Πηγή</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($consents as $c) : ?>
                <tr><td><?php echo esc_html(TW_Util::datetime_label($c->created_at)); ?></td>
                    <td><?php echo esc_html($consent_labels[$c->consent_type] ?? $c->consent_type); ?></td>
                    <td><span class="pill <?php echo $c->granted ? 'pill-yes' : 'pill-no'; ?>"><?php echo $c->granted ? '✔ Αποδοχή' : '✘ Όχι'; ?></span></td>
                    <td><?php echo $c->terms_version ? 'v' . (int) $c->terms_version . ' <small class="mono">' . esc_html(substr($c->terms_hash, 0, 10)) . '</small>' : '—'; ?></td>
                    <td><?php echo esc_html($source_labels[$c->source] ?? $c->source); ?></td>
                    <td class="mono"><?php echo esc_html($c->ip ?: '—'); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="note">Το ακριβές κείμενο κάθε επιλογής, η έκδοση των όρων (με αποτύπωμα SHA-256) και τα στοιχεία του προγράμματος περιήγησης φυλάσσονται στο σύστημα.</p>

        <h2 class="section">Ειδοποιήσεις που στάλθηκαν (<?php echo count($notes); ?>)</h2>
        <?php if (!$notes) : ?><p class="muted">Καμία.</p><?php else : ?>
        <table class="grid">
            <thead><tr><th>Ημ/νία</th><th>Μήνυμα</th><th>Μέσο</th><th>Παραλήπτης</th><th>Αποτέλεσμα</th></tr></thead>
            <tbody>
            <?php foreach ($notes as $n) : ?>
                <tr><td><?php echo esc_html(TW_Util::datetime_label($n->created_at)); ?></td><td><?php echo esc_html(TW_Cron::row_label($n)); ?></td>
                    <td><?php echo esc_html($channels[$n->channel] ?? $n->channel); ?></td><td><?php echo esc_html($n->recipient); ?></td>
                    <td><?php echo esc_html($n->status !== 'sent' ? ['failed' => 'Απέτυχε η αποστολή', 'skipped' => 'Παραλείφθηκε', 'pending' => 'Σε εξέλιξη'][$n->status] ?? $n->status : ($n->delivery_status !== '' ? ($delivery[$n->delivery_status] ?? $n->delivery_status) : 'Στάλθηκε')); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <h2 class="section">Ιστορικό</h2>
        <table class="grid">
            <thead><tr><th style="width:22%">Ημ/νία</th><th style="width:28%">Ενέργεια</th><th style="width:20%">Από</th><th>Λεπτομέρειες</th></tr></thead>
            <tbody>
            <?php foreach ($events as $e) : ?>
                <tr><td><?php echo esc_html(TW_Util::datetime_label($e->created_at)); ?></td><td><?php echo esc_html(TW_Admin::event_label($e->action)); ?></td>
                    <td><?php echo esc_html(TW_Admin::actor_label($e->actor)); ?></td><td><?php echo esc_html(TW_Admin::event_details($e->details)); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </td></tr></tbody>
</table>
<script>
// Open the print dialog once fonts and the logo are ready.
window.addEventListener('load', function () {
    var go = function () { setTimeout(function () { window.print(); }, 150); };
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(go); else go();
});
</script>
</body>
</html>
