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
            'email_from_name' => 'Thermic',
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
            'staff_2fa' => 1,
            'portal_url' => '',
            'register_url' => '',
            'privacy_url' => '',
            'retention_months_after_end' => 0,
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
            case 'fields': return 'αριθμό και ημερομηνία της απόδειξης';
            case 'both': return 'αριθμό και ημερομηνία της απόδειξης, μαζί με φωτογραφία ή PDF της';
            default: return 'φωτογραφία ή PDF της απόδειξης';
        }
    }

    public static function update(array $values) {
        update_option(self::OPTION, array_merge(self::all(), $values), false);
    }

    public static function template($key) {
        $defaults = self::template_defaults();
        $saved = self::get('templates');
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

    public static function terms_versions() {
        $v = get_option(self::TERMS_OPTION, []);
        return is_array($v) ? $v : [];
    }

    public static function current_terms() {
        $v = self::terms_versions();
        return $v ? end($v) : ['version' => 0, 'text' => '', 'hash' => '', 'published_at' => ''];
    }

    public static function terms_version($version) {
        foreach (self::terms_versions() as $t) {
            if ((int) $t['version'] === (int) $version) return $t;
        }
        return null;
    }

    /** Saves the text as a new version if it changed. Returns the current version number. */
    public static function save_terms($text) {
        $text = trim(str_replace("\r\n", "\n", (string) $text));
        $hash = hash('sha256', $text);
        $versions = self::terms_versions();
        $current = $versions ? end($versions) : null;
        if ($current && $current['hash'] === $hash) {
            return (int) $current['version'];
        }
        $n = $current ? (int) $current['version'] + 1 : 1;
        $versions[] = ['version' => $n, 'text' => $text, 'hash' => $hash, 'published_at' => TW_DB::now()];
        update_option(self::TERMS_OPTION, $versions, false);
        return $n;
    }
}
