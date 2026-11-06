<?php
if (!defined('ABSPATH')) exit;

/**
 * Plugin settings, message templates and versioned warranty terms.
 */
final class TW_Settings {
    const OPTION = 'tw_settings';
    const TERMS_OPTION = 'tw_terms_versions';

    public static function defaults() {
        return [
            'company_name' => 'Thermic',
            'company_contact' => 'Thermic – τηλ. 210 0000000 – service@example.com',
            'warranty_years' => 12,
            'service_interval_years' => 3,
            // Days relative to each milestone (negative = before). The lock step is separate.
            'reminder_offsets' => '-60,-30,0,30',
            'lock_offset' => 45,
            'lock_on_missed_service' => 1,
            'lock_on_expiry' => 1,
            // If the daily job was missed (site down), still send a step up to this many days late.
            'catchup_days' => 7,
            'max_registration_delay_days' => 0,
            // Customer Service submission: how many days before the due date the form opens,
            // and how long a customer has to resubmit after a rejection.
            'service_submit_window_days' => 90,
            'service_resubmit_days' => 15,
            'service_notify_company' => 0,
            'admin_emails' => get_option('admin_email'),
            'admin_digest' => 1,
            'notify_partner' => 0,
            'email_from_name' => '',
            'email_reply_to' => '',
            // Note at the bottom of customer emails ({company_name} allowed; empty = no note). Team emails have none.
            'email_footer_login' => 'Το μήνυμα που λαμβάνετε αφορά την ασφαλή σας σύνδεση στο περιβάλλον καταχώρησης, επεξεργασίας και προβολής των εγγυήσεών σας για προϊόντα της {company_name}, και δεν αποτελεί εμπορική επικοινωνία.',
            'email_footer_service' => 'Το μήνυμα αφορά την εγγύηση και τη συντήρηση του προϊόντος σας και δεν αποτελεί εμπορική επικοινωνία.',
            'email_from_address' => '',
            'invoice_required' => 1, // legacy (≤1.3): replaced by invoice_mode
            // Purchase invoice file at activation: required | optional | off
            'invoice_mode' => 'required',
            // Proof of Service at submission: upload (file) | fields (number + date, no file) | both
            'service_proof_mode' => 'upload',
            'invoice_max_mb' => 5,
            'admin_slug' => 'warranty-admin',
            'public_slug' => 'warranty',
            'logo_url' => '',
            'brand_color' => '#c8102e',
            'brand_color_2' => '#0f1320',
            // Printed warranty card: watermark none | text | logo, its text and opacity (0–100 %).
            'pdf_watermark' => 'text',
            'pdf_watermark_text' => '',
            'pdf_watermark_opacity' => 6,
            'staff_2fa' => 1,
            // How long a sign-in to the console lasts (minutes); the sidebar shows a countdown with a refresh button.
            'staff_session_minutes' => 120,
            // Cloudflare Turnstile on the console sign-in and/or the customer forms (honeypot is always on).
            'turnstile_site_key' => '',
            'turnstile_secret' => '',
            'turnstile_staff' => 0,
            'turnstile_public' => 0,
            // Manager access by IP – changed only by WordPress administrators (wp-admin → Πρόσβαση με IP).
            'ip_lock' => 0,
            'ip_allow' => '',
            'ip_proxy' => '',
            // Lifetime of the emailed one-time codes, in minutes.
            'otp_minutes' => 2,
            // Countries offered next to the partner / technician tax number (Greece is always offered).
            'tax_countries' => ['GR', 'CY', 'AE', 'QA', 'XX'],
            // Activity log entries older than this many years move to the archive automatically (0 = never).
            'log_archive_years' => 0,
            'portal_url' => '',
            'register_url' => '',
            'privacy_url' => '',
            'retention_months_after_end' => 0,
            'brevo_api_key' => '',
            'brevo_email' => 0,
            'brevo_sms' => 0,
            'brevo_sender_email' => '',
            'brevo_sender_name' => '',
            'brevo_reply_to' => '',
            'brevo_sms_sender' => '',
            'brevo_webhook_token' => '',
            // If a reminder is reported as not delivered, resend it through the other channel.
            'fallback_channel' => 1,
            'backup_daily' => 1,
            'backup_keep' => 14,
            'backup_per_day' => 1,
            'backup_time' => '03:30',
            'backup_password' => '',
            // Off-site copies: none | gdrive | onedrive | dropbox | ftp
            'backup_remote' => 'none',
            'backup_remote_folder' => 'Warranty Manager Backups',
            'backup_remote_keep' => 30,
            'backup_remote_manual' => 0,
            'backup_gdrive_client_id' => '',
            'backup_gdrive_client_secret' => '',
            'backup_gdrive_folder_id' => '',
            'backup_onedrive_client_id' => '',
            'backup_onedrive_client_secret' => '',
            'backup_onedrive_tenant' => 'common',
            'backup_dropbox_app_key' => '',
            'backup_dropbox_app_secret' => '',
            'backup_ftp_host' => '',
            'backup_ftp_port' => 21,
            'backup_ftp_user' => '',
            'backup_ftp_password' => '',
            'backup_ftp_path' => '/thermic-warranty-backups',
            'backup_ftp_mode' => 'ftps',
            'backup_ftp_passive' => 1,
            'sms_enabled' => 0,
            'sms_url' => '',
            'sms_method' => 'POST',
            'sms_headers' => "Content-Type: application/json\nAuthorization: Bearer YOUR_API_KEY",
            'sms_body' => '{"to":"{to}","from":"{sender}","text":"{text}"}',
            'sms_sender' => 'Thermic',
            'marketing_label' => 'Επιθυμώ να λαμβάνω νέα, ενημερώσεις και προσφορές της Thermic μέσω email ή SMS. Μπορώ να ανακαλέσω τη συγκατάθεσή μου οποτεδήποτε.',
            'terms_label' => 'Διάβασα και αποδέχομαι τους όρους εγγύησης.',
            'accuracy_label' => 'Επιβεβαιώνω ότι τα στοιχεία που συμπλήρωσα είναι σωστά.',
            'templates' => [],
            // English for customers who use the platform in English (empty = the defaults below).
            'templates_en' => [],
            'company_contact_en' => '',
            'terms_label_en' => 'I have read and accept the warranty terms.',
            'accuracy_label_en' => 'I confirm that the details I have entered are correct.',
            'marketing_label_en' => 'I would like to receive news, updates and offers from Thermic by email or SMS. I can withdraw my consent at any time.',
            'email_footer_login_en' => 'This message concerns your secure sign-in to the environment where you register, edit and view your warranties for {company_name} products, and is not a marketing communication.',
            'email_footer_service_en' => 'This message concerns the warranty and maintenance of your product and is not a marketing communication.',
        ];
    }

    /** Contact details shown to customers in their language (English falls back to the Greek text). */
    public static function company_contact($lang = 'el') {
        return $lang === 'en' && trim((string) self::get('company_contact_en')) !== '' ? (string) self::get('company_contact_en') : (string) self::get('company_contact');
    }

    /** The three consent texts in a language: ['terms' =>, 'accuracy' =>, 'marketing' =>]. */
    public static function consent_labels($lang = 'el') {
        $s = self::all();
        $sfx = $lang === 'en' ? '_en' : '';
        $d = self::defaults();
        return [
            'terms' => trim((string) $s['terms_label' . $sfx]) ?: $d['terms_label' . $sfx],
            'accuracy' => trim((string) $s['accuracy_label' . $sfx]) ?: $d['accuracy_label' . $sfx],
            'marketing' => trim((string) $s['marketing_label' . $sfx]) ?: $d['marketing_label' . $sfx],
        ];
    }

    /** Message templates. Placeholders are listed in the settings page. */
    public static function template_defaults() {
        $genuine = "Για τη διατήρηση της εγγύησης, το Service πρέπει να γίνεται αποκλειστικά με αυθεντικά (γνήσια) ανταλλακτικά Thermic.";
        $submit = "Μόλις γίνει το Service, καταχωρήστε το στο {portal_url} («Οι εγγυήσεις μου» → «Υποβολή Service»): ημερομηνία, ΑΦΜ τεχνικού και {service_proof}. Έτσι η εγγύηση συνεχίζει να ισχύει μέχρι το επόμενο Service.";
        $where = "Για τον προγραμματισμό του Service μπορείτε να απευθυνθείτε στον συνεργάτη από τον οποίο αγοράσατε το προϊόν ({partner_email}) ή απευθείας στη {company_name}: {company_contact}.";
        $ident = "Προϊόν: {product_name}\nΣειριακός αριθμός: {serial}\nΚωδικός εγγύησης: {warranty_code}";
        return [
            'registration_otp' => [
                'label' => 'Κωδικός επαλήθευσης εγγραφής',
                'subject' => 'Κωδικός επαλήθευσης για τον σειριακό {serial}',
                'body' => "Γεια σας,\n\nο κωδικός επαλήθευσης για την ενεργοποίηση της εγγύησης του προϊόντος με σειριακό αριθμό {serial} είναι:\n\n{code}\n\nΟ κωδικός ισχύει για {minutes} λεπτά. Αν δεν ζητήσατε εσείς την ενεργοποίηση, αγνοήστε αυτό το μήνυμα.",
                'sms' => '',
            ],
            'login_otp' => [
                'label' => 'Κωδικός σύνδεσης στην πύλη',
                'subject' => 'Κωδικός σύνδεσης – Εγγυήσεις {company_name}',
                'body' => "Γεια σας,\n\nο κωδικός σύνδεσης στην πλατφόρμα εγγυήσεων είναι:\n\n{code}\n\nΟ κωδικός ισχύει για {minutes} λεπτά. Αν δεν ζητήσατε εσείς τη σύνδεση, αγνοήστε αυτό το μήνυμα.",
                'sms' => '',
            ],
            'activation' => [
                'label' => 'Επιβεβαίωση ενεργοποίησης εγγύησης',
                'subject' => 'Η εγγύησή σας ενεργοποιήθηκε – {warranty_code}',
                'body' => "Αγαπητέ/ή {customer_name},\n\nη εγγύηση του προϊόντος σας ενεργοποιήθηκε επιτυχώς.\n\n$ident\nΗμερομηνία αγοράς: {purchase_date}\nΛήξη εγγύησης: {warranty_end}\n\nΠρογραμματισμένο Service:\n{service_schedule}\n\nΗ εγγύηση διατηρείται εφόσον κάθε Service γίνεται εγκαίρως και καταχωρείται στην πλατφόρμα (ημερομηνία, ΑΦΜ τεχνικού, απόδειξη).\n$genuine\n$where\n\nΜπορείτε να δείτε την εγγύησή σας οποτεδήποτε: {portal_url}",
                'sms' => '{company_name}: Η εγγύηση του προϊόντος S/N {serial} ενεργοποιήθηκε. Κωδ. εγγύησης {warranty_code}, λήξη {warranty_end}.',
            ],
            'service_before' => [
                'label' => 'Service – υπενθύμιση πριν από την ημερομηνία',
                'subject' => 'Πλησιάζει το Service του προϊόντος σας – S/N {serial}',
                'body' => "Αγαπητέ/ή {customer_name},\n\nσε {days} ημέρες ({due_date}) συμπληρώνονται {years} χρόνια από την αγορά και προβλέπεται το {milestone_no}ο περιοδικό Service του προϊόντος σας.\n\n$ident\n\n$where\n$genuine\n\n$submit",
                'sms' => '{company_name}: Στις {due_date} προβλέπεται Service για το S/N {serial}. Μετά το Service καταχωρήστε το στο {portal_url}',
            ],
            'service_today' => [
                'label' => 'Service – ημέρα Service',
                'subject' => 'Σήμερα είναι η ημερομηνία Service – S/N {serial}',
                'body' => "Αγαπητέ/ή {customer_name},\n\nσήμερα ({due_date}) είναι η προβλεπόμενη ημερομηνία του {milestone_no}ου περιοδικού Service.\n\n$ident\n\nΑν δεν έχει πραγματοποιηθεί, παρακαλούμε προγραμματίστε το έως {grace_end_date}.\n$where\n$genuine\n\n$submit",
                'sms' => '{company_name}: Σήμερα είναι η ημερομηνία Service για το S/N {serial}. Προγραμματίστε το έως {grace_end_date}.',
            ],
            'service_after' => [
                'label' => 'Service – ολοκλήρωση περιόδου χάριτος',
                'subject' => 'Δεν έχει καταχωρηθεί Service – S/N {serial}',
                'body' => "Αγαπητέ/ή {customer_name},\n\nέχουν περάσει {days} ημέρες από την προβλεπόμενη ημερομηνία Service ({due_date}) και δεν έχει καταχωρηθεί Service για το προϊόν σας.\n\n$ident\n\nΑν το Service δεν γίνει και δεν καταχωρηθεί έως {lock_date}, η εγγύηση θα κλειδωθεί οριστικά.\n\n$submit\n$where\n$genuine",
                'sms' => '{company_name}: Δεν έχει καταχωρηθεί Service για το S/N {serial}. Καταχωρήστε το έως {lock_date} στο {portal_url}, αλλιώς η εγγύηση κλειδώνει.',
            ],
            'service_received' => [
                'label' => 'Service – επιβεβαίωση υποβολής από τον πελάτη',
                'subject' => 'Λάβαμε την υποβολή Service – S/N {serial}',
                'body' => "Αγαπητέ/ή {customer_name},\n\nλάβαμε την καταχώρηση του {milestone_no}ου Service του προϊόντος σας.\n\n$ident\nΗμερομηνία Service: {performed_date}\nΑΦΜ τεχνικού: {technician_vat}\nΑπόδειξη: {receipt_info}\n\nΗ καταχώρηση και η απόδειξη φυλάσσονται στην καρτέλα της εγγύησής σας και η εγγύηση συνεχίζει να ισχύει. {next_step}\n\nΚρατήστε και εσείς την πρωτότυπη απόδειξη του Service.",
                'sms' => '',
            ],
            'service_rejected' => [
                'label' => 'Service – απόρριψη υποβολής',
                'subject' => 'Χρειάζεται νέα υποβολή Service – S/N {serial}',
                'body' => "Αγαπητέ/ή {customer_name},\n\nη υποβολή του {milestone_no}ου Service του προϊόντος σας δεν μπόρεσε να εγκριθεί.\n\nΛόγος: {review_note}\n\n$ident\n\nΠαρακαλούμε υποβάλετε ξανά τα σωστά στοιχεία έως {resubmit_until} στο {portal_url}. Αν δεν γίνει νέα υποβολή έως τότε, η εγγύηση θα κλειδωθεί οριστικά.\n\nΓια οποιαδήποτε απορία: {company_contact}.",
                'sms' => '{company_name}: Η υποβολή Service για το S/N {serial} χρειάζεται διόρθωση. Δείτε το email σας και υποβάλετε ξανά έως {resubmit_until}.',
            ],
            'service_locked' => [
                'label' => 'Service – οριστικό κλείδωμα',
                'subject' => 'Οριστικό κλείδωμα εγγύησης – S/N {serial}',
                'body' => "Αγαπητέ/ή {customer_name},\n\nεπειδή δεν πραγματοποιήθηκε το προβλεπόμενο {milestone_no}ο Service (ημερομηνία {due_date}), η εγγύηση του προϊόντος σας κλειδώθηκε οριστικά και δεν μπορεί να επανενεργοποιηθεί.\n\n$ident\n\nΓια οποιαδήποτε απορία: {company_contact}.",
                'sms' => '{company_name}: Η εγγύηση του S/N {serial} κλειδώθηκε οριστικά λόγω μη πραγματοποίησης Service.',
            ],
            'expiry_before' => [
                'label' => 'Λήξη – υπενθύμιση πριν από τη λήξη',
                'subject' => 'Η εγγύησή σας λήγει σε {days} ημέρες – S/N {serial}',
                'body' => "Αγαπητέ/ή {customer_name},\n\nη εγγύηση του προϊόντος σας λήγει σε {days} ημέρες, στις {due_date}.\n\n$ident\n\n$where\n$genuine",
                'sms' => '{company_name}: Η εγγύηση του S/N {serial} λήγει σε {days} ημέρες ({due_date}).',
            ],
            'expiry_today' => [
                'label' => 'Λήξη – ημέρα λήξης',
                'subject' => 'Η εγγύησή σας λήγει σήμερα – S/N {serial}',
                'body' => "Αγαπητέ/ή {customer_name},\n\nη εγγύηση του προϊόντος σας λήγει σήμερα ({due_date}).\n\n$ident\n\nΟποιοδήποτε αίτημα εγγύησης πρέπει να υποβληθεί έως {grace_end_date}.\n$where",
                'sms' => '{company_name}: Η εγγύηση του S/N {serial} λήγει σήμερα.',
            ],
            'expiry_after' => [
                'label' => 'Λήξη – ολοκλήρωση περιόδου 30 ημερών',
                'subject' => 'Ολοκληρώθηκε η περίοδος μετά τη λήξη – S/N {serial}',
                'body' => "Αγαπητέ/ή {customer_name},\n\nολοκληρώθηκε η περίοδος {days} ημερών μετά τη λήξη της εγγύησης ({due_date}).\n\n$ident\n\nΣτις {lock_date} η εγγύηση θα κλειδωθεί οριστικά.",
                'sms' => '{company_name}: Ολοκληρώθηκε η περίοδος μετά τη λήξη της εγγύησης S/N {serial}. Οριστικό κλείδωμα στις {lock_date}.',
            ],
            'expiry_locked' => [
                'label' => 'Λήξη – οριστικό κλείδωμα',
                'subject' => 'Οριστικό κλείδωμα εγγύησης – S/N {serial}',
                'body' => "Αγαπητέ/ή {customer_name},\n\nη εγγύηση του προϊόντος σας έληξε στις {due_date} και πλέον κλειδώθηκε οριστικά.\n\n$ident\n\nΣας ευχαριστούμε που επιλέξατε τη {company_name}.",
                'sms' => '{company_name}: Η εγγύηση του S/N {serial} κλειδώθηκε οριστικά.',
            ],
        ];
    }

    /** English message templates (same keys and placeholders as the Greek ones). */
    public static function template_defaults_en() {
        $genuine = "To keep the warranty valid, Service must be carried out exclusively with genuine Thermic spare parts.";
        $submit = "Once the Service has been carried out, record it at {portal_url} (“My warranties” → “Submit Service”): date, technician's tax ID and {service_proof}. This keeps your warranty valid until the next Service.";
        $where = "To arrange the Service, you can contact the dealer you bought the product from ({partner_email}) or {company_name} directly: {company_contact}.";
        $ident = "Product: {product_name}\nSerial number: {serial}\nWarranty code: {warranty_code}";
        return [
            'registration_otp' => ['label' => 'Registration verification code', 'subject' => 'Verification code for serial number {serial}',
                'body' => "Hello,\n\nyour verification code to activate the warranty of the product with serial number {serial} is:\n\n{code}\n\nThe code is valid for {minutes} minutes. If you did not request this activation, please ignore this message.", 'sms' => ''],
            'login_otp' => ['label' => 'Sign-in code', 'subject' => 'Sign-in code – {company_name} warranties',
                'body' => "Hello,\n\nyour sign-in code for the warranty platform is:\n\n{code}\n\nThe code is valid for {minutes} minutes. If you did not request to sign in, please ignore this message.", 'sms' => ''],
            'activation' => ['label' => 'Warranty activation confirmation', 'subject' => 'Your warranty is active – {warranty_code}',
                'body' => "Dear {customer_name},\n\nthe warranty of your product has been activated successfully.\n\n$ident\nPurchase date: {purchase_date}\nWarranty expiry: {warranty_end}\n\nScheduled Service:\n{service_schedule}\n\nThe warranty remains valid as long as each Service is carried out on time and recorded on the platform (date, technician's tax ID, receipt).\n$genuine\n$where\n\nYou can view your warranty at any time: {portal_url}",
                'sms' => '{company_name}: The warranty of product S/N {serial} is active. Warranty code {warranty_code}, expires {warranty_end}.'],
            'service_before' => ['label' => 'Service – reminder before the date', 'subject' => 'Your product\'s Service is coming up – S/N {serial}',
                'body' => "Dear {customer_name},\n\nin {days} days ({due_date}) it will be {years} years since your purchase, and the {milestone_no} scheduled Service of your product is due.\n\n$ident\n\n$where\n$genuine\n\n$submit",
                'sms' => '{company_name}: Service is due on {due_date} for S/N {serial}. After the Service, record it at {portal_url}'],
            'service_today' => ['label' => 'Service – due date', 'subject' => 'Today is the Service date – S/N {serial}',
                'body' => "Dear {customer_name},\n\ntoday ({due_date}) is the due date of the {milestone_no} scheduled Service.\n\n$ident\n\nIf it has not been carried out yet, please arrange it by {grace_end_date}.\n$where\n$genuine\n\n$submit",
                'sms' => '{company_name}: Today is the Service date for S/N {serial}. Please arrange it by {grace_end_date}.'],
            'service_after' => ['label' => 'Service – end of grace period', 'subject' => 'No Service recorded – S/N {serial}',
                'body' => "Dear {customer_name},\n\n{days} days have passed since the Service due date ({due_date}) and no Service has been recorded for your product.\n\n$ident\n\nIf the Service is not carried out and recorded by {lock_date}, the warranty will be permanently locked.\n\n$submit\n$where\n$genuine",
                'sms' => '{company_name}: No Service recorded for S/N {serial}. Record it by {lock_date} at {portal_url}, otherwise the warranty will be locked.'],
            'service_received' => ['label' => 'Service – submission received', 'subject' => 'We received your Service submission – S/N {serial}',
                'body' => "Dear {customer_name},\n\nwe have received the record of the {milestone_no} Service of your product.\n\n$ident\nService date: {performed_date}\nTechnician's tax ID: {technician_vat}\nReceipt: {receipt_info}\n\nThe record and the receipt are kept with your warranty, and the warranty remains valid. {next_step}\n\nPlease keep the original Service receipt as well.", 'sms' => ''],
            'service_rejected' => ['label' => 'Service – submission not approved', 'subject' => 'Please resubmit your Service – S/N {serial}',
                'body' => "Dear {customer_name},\n\nthe submission of the {milestone_no} Service of your product could not be approved.\n\nReason: {review_note}\n\n$ident\n\nPlease submit the correct details again by {resubmit_until} at {portal_url}. If there is no new submission by then, the warranty will be permanently locked.\n\nFor any questions: {company_contact}.",
                'sms' => '{company_name}: Your Service submission for S/N {serial} needs correcting. Please check your email and resubmit by {resubmit_until}.'],
            'service_locked' => ['label' => 'Service – permanent lock', 'subject' => 'Warranty permanently locked – S/N {serial}',
                'body' => "Dear {customer_name},\n\nbecause the scheduled {milestone_no} Service (due {due_date}) was not carried out, the warranty of your product has been permanently locked and cannot be reactivated.\n\n$ident\n\nFor any questions: {company_contact}.",
                'sms' => '{company_name}: The warranty of S/N {serial} has been permanently locked because the Service was not carried out.'],
            'expiry_before' => ['label' => 'Expiry – reminder before expiry', 'subject' => 'Your warranty expires in {days} days – S/N {serial}',
                'body' => "Dear {customer_name},\n\nthe warranty of your product expires in {days} days, on {due_date}.\n\n$ident\n\n$where\n$genuine",
                'sms' => '{company_name}: The warranty of S/N {serial} expires in {days} days ({due_date}).'],
            'expiry_today' => ['label' => 'Expiry – expiry date', 'subject' => 'Your warranty expires today – S/N {serial}',
                'body' => "Dear {customer_name},\n\nthe warranty of your product expires today ({due_date}).\n\n$ident\n\nAny warranty claim must be submitted by {grace_end_date}.\n$where",
                'sms' => '{company_name}: The warranty of S/N {serial} expires today.'],
            'expiry_after' => ['label' => 'Expiry – end of the 30-day period', 'subject' => 'The period after expiry has ended – S/N {serial}',
                'body' => "Dear {customer_name},\n\nthe {days}-day period after the warranty expiry ({due_date}) has ended.\n\n$ident\n\nOn {lock_date} the warranty will be permanently locked.",
                'sms' => '{company_name}: The period after the expiry of the warranty of S/N {serial} has ended. Permanent lock on {lock_date}.'],
            'expiry_locked' => ['label' => 'Expiry – permanent lock', 'subject' => 'Warranty permanently locked – S/N {serial}',
                'body' => "Dear {customer_name},\n\nthe warranty of your product expired on {due_date} and has now been permanently locked.\n\n$ident\n\nThank you for choosing {company_name}.",
                'sms' => '{company_name}: The warranty of S/N {serial} has been permanently locked.'],
        ];
    }

    public static function ensure_defaults() {
        if (get_option(self::OPTION) === false) {
            add_option(self::OPTION, self::defaults(), '', false);
        }
        if (get_option(self::TERMS_OPTION) === false) {
            add_option(self::TERMS_OPTION, [], '', false);
            self::save_terms("ΟΡΟΙ ΕΓΓΥΗΣΗΣ\n\n(Το κείμενο των όρων εγγύησης θα το καταχωρίσετε από Εγγυήσεις → Ρυθμίσεις → Όροι εγγύησης.)");
        }
    }

    public static function all() {
        $saved = get_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($saved) ? $saved : []);
    }

    public static function get($key) {
        $all = self::all();
        return $all[$key] ?? null;
    }

    /** Purchase invoice file at activation: required | optional | off (keeps the pre-1.4 checkbox working). */
    public static function invoice_mode() {
        $saved = get_option(self::OPTION, []);
        if (is_array($saved) && !isset($saved['invoice_mode']) && isset($saved['invoice_required'])) {
            return $saved['invoice_required'] ? 'required' : 'optional';
        }
        $m = (string) self::get('invoice_mode');
        return in_array($m, ['required', 'optional', 'off'], true) ? $m : 'required';
    }

    /** Proof of Service: upload | fields | both */
    public static function service_proof_mode() {
        $m = (string) self::get('service_proof_mode');
        return in_array($m, ['upload', 'fields', 'both'], true) ? $m : 'upload';
    }

    public static function service_needs_file() {
        return self::service_proof_mode() !== 'fields';
    }

    public static function service_needs_fields() {
        return self::service_proof_mode() !== 'upload';
    }

    /** Words used in messages for what the customer must provide as proof of Service. */
    public static function service_proof_text() {
        switch (self::service_proof_mode()) {
            case 'fields': return tw__('αριθμό και ημερομηνία της απόδειξης');
            case 'both': return tw__('αριθμό και ημερομηνία της απόδειξης, μαζί με φωτογραφία ή PDF της');
            default: return tw__('φωτογραφία ή PDF της απόδειξης');
        }
    }

    public static function update(array $values) {
        update_option(self::OPTION, array_merge(self::all(), $values), false);
    }

    public static function template($key, $lang = 'el') {
        $en = $lang === 'en';
        $defaults = $en ? self::template_defaults_en() : self::template_defaults();
        $saved = self::get($en ? 'templates_en' : 'templates');
        $t = $defaults[$key] ?? ['subject' => '', 'body' => '', 'sms' => ''];
        if (is_array($saved) && isset($saved[$key]) && is_array($saved[$key])) {
            foreach (['subject', 'body', 'sms'] as $f) {
                if (isset($saved[$key][$f]) && $saved[$key][$f] !== '') {
                    $t[$f] = $saved[$key][$f];
                }
            }
        }
        return $t;
    }

    /** @return int[] sorted reminder offsets, without the lock step */
    public static function reminder_offsets() {
        $lock = (int) self::get('lock_offset');
        $out = [];
        foreach (explode(',', (string) self::get('reminder_offsets')) as $p) {
            $p = trim($p);
            if ($p === '' || !preg_match('/^[+-]?\d+$/', $p)) continue;
            $v = (int) $p;
            if ($v < $lock) $out[$v] = $v;
        }
        ksort($out);
        return array_values($out);
    }

    // ---- Terms (versioned: every change creates a new version; consents point to it) ----

    /** Greek terms in TERMS_OPTION; English terms (own versions) in TERMS_OPTION . '_en'. */
    public static function terms_versions($lang = 'el') {
        $v = get_option(self::TERMS_OPTION . ($lang === 'en' ? '_en' : ''), []);
        return is_array($v) ? $v : [];
    }

    /**
     * Current terms for a language, with 'lang' telling which text it really is: English customers see the
     * Greek terms until an English text is published.
     */
    public static function current_terms($lang = 'el') {
        if ($lang === 'en') {
            $v = self::terms_versions('en');
            if ($v && trim((string) end($v)['text']) !== '') return end($v) + ['lang' => 'en'];
        }
        $v = self::terms_versions('el');
        return ($v ? end($v) : ['version' => 0, 'text' => '', 'hash' => '', 'published_at' => '']) + ['lang' => 'el'];
    }

    public static function terms_version($version, $lang = 'el') {
        foreach (self::terms_versions($lang) as $t) {
            if ((int) $t['version'] === (int) $version) return $t + ['lang' => $lang];
        }
        return null;
    }

    /** Saves the text as a new version if it changed. Returns the current version number. */
    public static function save_terms($text, $lang = 'el') {
        $text = trim(str_replace("\r\n", "\n", (string) $text));
        $hash = hash('sha256', $text);
        $versions = self::terms_versions($lang);
        $current = $versions ? end($versions) : null;
        if ($current && $current['hash'] === $hash) {
            return (int) $current['version'];
        }
        $n = $current ? (int) $current['version'] + 1 : 1;
        $versions[] = ['version' => $n, 'text' => $text, 'hash' => $hash, 'published_at' => TW_DB::now()];
        update_option(self::TERMS_OPTION . ($lang === 'en' ? '_en' : ''), $versions, false);
        return $n;
    }
}
