<?php
if (!defined('ABSPATH')) exit;

/**
 * Partners / authorized Service network.
 *  - Console screen «Συνεργάτες»: add, edit, hide, remove, import/export Excel.
 *  - Shortcode [thermic_warranty_partners]: public finder, Country (default Greece) → Νομός / region → area → partners.
 *    Attributes: service="1" (default) only partners marked as authorized Service, service="0" all active partners;
 *    country="GR" the preselected country.
 */
final class TW_Partners {
    /** Greek prefectures (Νομοί), with their English names for the English page. */
    const GR_REGIONS = [
        'Αιτωλοακαρνανία' => 'Aetolia-Acarnania', 'Αργολίδα' => 'Argolis', 'Αρκαδία' => 'Arcadia', 'Άρτα' => 'Arta',
        'Αττική' => 'Attica', 'Αχαΐα' => 'Achaea', 'Βοιωτία' => 'Boeotia', 'Γρεβενά' => 'Grevena', 'Δράμα' => 'Drama',
        'Δωδεκάνησα' => 'Dodecanese', 'Έβρος' => 'Evros', 'Εύβοια' => 'Euboea', 'Ευρυτανία' => 'Evrytania', 'Ζάκυνθος' => 'Zakynthos',
        'Ηλεία' => 'Ilia', 'Ημαθία' => 'Imathia', 'Ηράκλειο' => 'Heraklion', 'Θεσπρωτία' => 'Thesprotia', 'Θεσσαλονίκη' => 'Thessaloniki',
        'Ιωάννινα' => 'Ioannina', 'Καβάλα' => 'Kavala', 'Καρδίτσα' => 'Karditsa', 'Καστοριά' => 'Kastoria', 'Κέρκυρα' => 'Corfu',
        'Κεφαλονιά' => 'Kefalonia', 'Κιλκίς' => 'Kilkis', 'Κοζάνη' => 'Kozani', 'Κορινθία' => 'Corinthia', 'Κυκλάδες' => 'Cyclades',
        'Λακωνία' => 'Laconia', 'Λάρισα' => 'Larissa', 'Λασίθι' => 'Lasithi', 'Λέσβος' => 'Lesbos', 'Λευκάδα' => 'Lefkada',
        'Μαγνησία' => 'Magnesia', 'Μεσσηνία' => 'Messinia', 'Ξάνθη' => 'Xanthi', 'Πέλλα' => 'Pella', 'Πιερία' => 'Pieria',
        'Πρέβεζα' => 'Preveza', 'Ρέθυμνο' => 'Rethymno', 'Ροδόπη' => 'Rhodope', 'Σάμος' => 'Samos', 'Σέρρες' => 'Serres',
        'Τρίκαλα' => 'Trikala', 'Φθιώτιδα' => 'Phthiotis', 'Φλώρινα' => 'Florina', 'Φωκίδα' => 'Phocis', 'Χαλκιδική' => 'Chalkidiki',
        'Χανιά' => 'Chania', 'Χίος' => 'Chios',
    ];

    /** Excel columns: field => [heading, accepted headings (lower case)]. */
    const COLUMNS = [
        'name' => ['Επωνυμία', ['name', 'επωνυμία', 'επωνυμια', 'όνομα', 'ονομα', 'συνεργάτης', 'συνεργατης', 'company']],
        'country' => ['Χώρα', ['country', 'χώρα', 'χωρα']],
        'region' => ['Νομός / Περιφέρεια', ['region', 'νομός / περιφέρεια', 'νομός', 'νομος', 'περιφέρεια', 'περιφερεια', 'state', 'county', 'prefecture']],
        'area' => ['Περιοχή / Πόλη', ['area', 'περιοχή / πόλη', 'περιοχή', 'περιοχη', 'πόλη', 'πολη', 'city', 'town']],
        'address' => ['Διεύθυνση', ['address', 'διεύθυνση', 'διευθυνση', 'οδός', 'οδος']],
        'postcode' => ['Τ.Κ.', ['postcode', 'zip', 'τ.κ.', 'τκ', 'ταχυδρομικός κώδικας']],
        'phone' => ['Τηλέφωνο', ['phone', 'τηλέφωνο', 'τηλεφωνο', 'τηλέφωνα', 'τηλ.', 'τηλ']],
        'email' => ['Email', ['email', 'e-mail']],
        'website' => ['Ιστότοπος', ['website', 'web', 'site', 'ιστότοπος', 'ιστοτοπος', 'url']],
        'notes' => ['Σημείωση', ['notes', 'note', 'σημείωση', 'σημειωση', 'σημειώσεις']],
        'map_url' => ['Χάρτης Google', ['map_url', 'χάρτης google', 'χαρτης google', 'χάρτης', 'χαρτης', 'google maps', 'maps', 'map', 'google business profile', 'business profile']],
        'name_en' => ['Επωνυμία (EN)', ['name_en', 'επωνυμία (en)', 'name (en)', 'company (en)']],
        'area_en' => ['Περιοχή (EN)', ['area_en', 'περιοχή (en)', 'area (en)', 'city (en)']],
        'address_en' => ['Διεύθυνση (EN)', ['address_en', 'διεύθυνση (en)', 'address (en)']],
        'notes_en' => ['Σημείωση (EN)', ['notes_en', 'σημείωση (en)', 'notes (en)']],
        'is_service' => ['Εξουσιοδοτημένο Service', ['is_service', 'service', 'εξουσιοδοτημένο service', 'εξουσιοδοτημενο service']],
        'active' => ['Ενεργός', ['active', 'ενεργός', 'ενεργος', 'εμφάνιση']],
    ];

    const SHORTCODE = 'thermic_warranty_partners';

    public static function init() {
        add_shortcode(self::SHORTCODE, [__CLASS__, 'shortcode']);
    }

    public static function table() {
        return TW_DB::table('partners');
    }

    /** Countries partners can be in: the full list of the platform. */
    public static function countries() {
        return TW_Util::countries();
    }

    public static function region_label($country) {
        return $country === 'GR' ? 'Νομός' : 'Περιφέρεια / Πόλη';
    }

    // ------------------------------------------------------------------ data

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id));
    }

    /** Cleans a submitted / imported record; returns [array $row, string $error]. */
    public static function clean(array $in) {
        $txt = function ($k, $max) use ($in) { return mb_substr(trim(sanitize_text_field((string) ($in[$k] ?? ''))), 0, $max); };
        $country = self::match_country($txt('country', 60));
        $region = $txt('region', 100);
        if ($country === 'GR' && $region !== '') {
            $region = self::match_gr_region($region);
        }
        // Google Maps / Business Profile link (the map pin opens it; empty = search by name and address).
        $map_url = trim((string) ($in['map_url'] ?? ''));
        if (preg_match('#^http://#i', $map_url)) $map_url = 'https://' . substr($map_url, 7);
        elseif ($map_url !== '' && preg_match('#^[a-z][a-z0-9+.-]*:#i', $map_url) && !preg_match('#^https://#i', $map_url)) $map_url = ''; // other schemes
        elseif ($map_url !== '' && !preg_match('#^https://#i', $map_url)) $map_url = 'https://' . $map_url;
        $map_url = preg_match('#^https://#i', $map_url) ? mb_substr(esc_url_raw($map_url, ['https']), 0, 500) : '';
        $web = trim((string) ($in['website'] ?? ''));
        if ($web !== '' && !preg_match('#^https?://#i', $web)) $web = 'https://' . $web;
        $flag = function ($k) use ($in) {
            if (!array_key_exists($k, $in)) return 1;
            $v = mb_strtolower(trim((string) $in[$k]));
            return in_array($v, ['0', 'όχι', 'οχι', 'no', 'false', 'n', ''], true) ? 0 : 1;
        };
        $row = [
            'name' => $txt('name', 190),
            'country' => $country,
            'region' => $region === null ? '' : $region,
            'area' => $txt('area', 100),
            'address' => $txt('address', 255),
            'postcode' => $txt('postcode', 20),
            'phone' => $txt('phone', 100),
            'email' => mb_substr(sanitize_email((string) ($in['email'] ?? '')), 0, 190),
            'website' => mb_substr(esc_url_raw($web), 0, 255),
            'notes' => $txt('notes', 255),
            'map_url' => $map_url,
            'name_en' => $txt('name_en', 190),
            'area_en' => $txt('area_en', 100),
            'address_en' => $txt('address_en', 255),
            'notes_en' => $txt('notes_en', 255),
            'is_service' => $flag('is_service'),
            'active' => $flag('active'),
        ];
        if ($row['name'] === '') return [$row, 'Η επωνυμία είναι υποχρεωτική.'];
        if ($country === '') return [$row, 'Άγνωστη χώρα «' . $txt('country', 60) . '».'];
        if ($region === null) return [$row, 'Άγνωστος νομός «' . $txt('region', 100) . '».'];
        if ($row['region'] === '') return [$row, 'Συμπληρώστε ' . ($country === 'GR' ? 'τον νομό' : 'την περιφέρεια / πόλη') . '.'];
        return [$row, ''];
    }

    /**
     * Greek → Latin (ELOT 743, simplified) for the English page when no English text was entered:
     * «ΠΑΠΑΔΟΠΟΥΛΟΣ» → «PAPADOPOULOS», «Περιστέρι» → «Peristeri». Latin text passes through unchanged.
     */
    public static function translit($s) {
        $s = (string) $s;
        if ($s === '' || !preg_match('/\p{Greek}/u', $s)) return $s;
        // Accents go; the diaeresis stays (ϊ, ϋ), so «οϋ» is not read as «ου».
        $plain = strtr($s, ['ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ΐ' => 'ϊ', 'ό' => 'ο', 'ύ' => 'υ', 'ΰ' => 'ϋ', 'ώ' => 'ω',
            'Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω']);
        $pairs = ['ου' => 'ou', 'αυ' => 'av', 'ευ' => 'ev', 'ηυ' => 'iv', 'γγ' => 'ng', 'γξ' => 'nx', 'γχ' => 'nch', 'θ' => 'th', 'χ' => 'ch', 'ψ' => 'ps'];
        $single = ['α' => 'a', 'β' => 'v', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e', 'ζ' => 'z', 'η' => 'i', 'ι' => 'i', 'κ' => 'k', 'λ' => 'l', 'μ' => 'm', 'ν' => 'n',
            'ξ' => 'x', 'ο' => 'o', 'π' => 'p', 'ρ' => 'r', 'σ' => 's', 'ς' => 's', 'τ' => 't', 'υ' => 'y', 'φ' => 'f', 'ω' => 'o', 'ϊ' => 'i', 'ϋ' => 'y'];
        $chars = preg_split('//u', $plain, -1, PREG_SPLIT_NO_EMPTY);
        $out = '';
        $n = count($chars);
        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i];
            $lc = mb_strtolower($c);
            $up = $c !== $lc;
            $next = $chars[$i + 1] ?? '';
            $prev = $i > 0 ? $chars[$i - 1] : ' ';
            $word_start = !preg_match('/\p{L}/u', $prev);
            $pair = $lc . mb_strtolower($next);
            if ($pair === 'μπ' && $word_start) { $lat = 'b'; $len = 2; }
            elseif ($pair === 'ντ' && $word_start) { $lat = 'd'; $len = 2; }
            elseif ($pair === 'γκ') { $lat = $word_start ? 'g' : 'ng'; $len = 2; }
            elseif (isset($pairs[$pair])) { $lat = $pairs[$pair]; $len = 2; }
            elseif (isset($single[$lc])) { $lat = $single[$lc]; $len = 1; }
            elseif (isset($pairs[$lc])) { $lat = $pairs[$lc]; $len = 1; }
            else { $out .= $c; continue; }
            if ($up) {
                // ΘΩΜΑΣ → THOMAS, Θωμάς → Thomas: look at the next Greek letter to tell all-caps from a capital.
                $look = $len === 2 ? $next : ($chars[$i + 1] ?? '');
                $lat = ($look !== '' && preg_match('/\p{Lu}/u', $look)) ? strtoupper($lat) : ucfirst($lat);
            }
            $out .= $lat;
            $i += $len - 1;
        }
        return $out;
    }

    /** The English text of a field: what was entered, or the automatic transliteration. */
    public static function en($row, $field) {
        $v = trim((string) ($row->{$field . '_en'} ?? ''));
        return $v !== '' ? $v : self::translit((string) ($row->$field ?? ''));
    }

    /** «GR», «Ελλάδα», «Greece», «ελλαδα» → GR; empty → GR; unknown → ''. */
    public static function match_country($v) {
        $v = trim((string) $v);
        if ($v === '') return 'GR';
        if (isset(self::countries()[strtoupper($v)])) return strtoupper($v);
        $norm = function ($s) { return strtr(mb_strtolower(trim($s)), ['ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω']); };
        $k = $norm($v);
        foreach (self::countries() as $code => $name) {
            if ($norm($name) === $k || $norm(self::country_en($code)) === $k) return $code;
        }
        $extra = ['ελλας' => 'GR', 'hellas' => 'GR', 'uae' => 'AE', 'dubai' => 'AE', 'ντουμπαι' => 'AE', 'εμιρατα' => 'AE', 'uk' => 'GB', 'κυπροσ' => 'CY', 'κυπρος' => 'CY'];
        return $extra[$k] ?? '';
    }

    /** «Νομός Αττικής», «ΑΤΤΙΚΗ», «attica» → «Αττική»; null if it is not a Greek prefecture. */
    public static function match_gr_region($v) {
        $key = function ($s) {
            $s = mb_strtolower(trim($s));
            $s = strtr($s, ['ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ϊ' => 'ι', 'ΐ' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ϋ' => 'υ', 'ΰ' => 'υ', 'ώ' => 'ω', 'ς' => 'σ']);
            return preg_replace('/^(νομοσ|ν\.)\s*/u', '', $s);
        };
        $k = $key($v);
        $aliases = ['αττικησ' => 'Αττική', 'θεσσαλονικησ' => 'Θεσσαλονίκη', 'ηρακλειου' => 'Ηράκλειο', 'κεφαλληνια' => 'Κεφαλονιά', 'κεφαλληνιασ' => 'Κεφαλονιά', 'αχαιασ' => 'Αχαΐα', 'ευβοιασ' => 'Εύβοια', 'δωδεκανησου' => 'Δωδεκάνησα', 'δωδεκανησοσ' => 'Δωδεκάνησα', 'κυκλαδων' => 'Κυκλάδες', 'χανιων' => 'Χανιά', 'λαρισησ' => 'Λάρισα', 'λαρισσα' => 'Λάρισα', 'ρεθυμνησ' => 'Ρέθυμνο', 'ρεθυμνου' => 'Ρέθυμνο', 'λασιθιου' => 'Λασίθι', 'ιωαννινων' => 'Ιωάννινα', 'σερρων' => 'Σέρρες', 'τρικαλων' => 'Τρίκαλα', 'κερκυρασ' => 'Κέρκυρα', 'φθιωτιδασ' => 'Φθιώτιδα', 'μαγνησιασ' => 'Μαγνησία', 'ηλειασ' => 'Ηλεία', 'μεσσηνιασ' => 'Μεσσηνία', 'κορινθιασ' => 'Κορινθία', 'αργολιδασ' => 'Αργολίδα', 'αρκαδιασ' => 'Αρκαδία', 'λακωνιασ' => 'Λακωνία', 'χαλκιδικησ' => 'Χαλκιδική', 'καβαλασ' => 'Καβάλα', 'κοζανησ' => 'Κοζάνη', 'πελλασ' => 'Πέλλα', 'πιεριασ' => 'Πιερία', 'ημαθιασ' => 'Ημαθία', 'δραμασ' => 'Δράμα', 'ξανθησ' => 'Ξάνθη', 'ροδοπησ' => 'Ροδόπη', 'εβρου' => 'Έβρος', 'λεσβου' => 'Λέσβος', 'χιου' => 'Χίος', 'σαμου' => 'Σάμος', 'ζακυνθου' => 'Ζάκυνθος', 'λευκαδασ' => 'Λευκάδα', 'πρεβεζασ' => 'Πρέβεζα', 'αρτασ' => 'Άρτα', 'θεσπρωτιασ' => 'Θεσπρωτία', 'καρδιτσασ' => 'Καρδίτσα', 'καστοριασ' => 'Καστοριά', 'φλωρινασ' => 'Φλώρινα', 'γρεβενων' => 'Γρεβενά', 'βοιωτιασ' => 'Βοιωτία', 'φωκιδασ' => 'Φωκίδα', 'ευρυτανιασ' => 'Ευρυτανία', 'αιτωλοακαρνανιασ' => 'Αιτωλοακαρνανία', 'πειραια' => 'Αττική', 'πειραιωσ' => 'Αττική', 'αθηνα' => 'Αττική', 'αθηνων' => 'Αττική', 'ροδοσ' => 'Δωδεκάνησα', 'κωσ' => 'Δωδεκάνησα'];
        foreach (self::GR_REGIONS as $el => $en) {
            if ($key($el) === $k || mb_strtolower($en) === $k) return $el;
        }
        return $aliases[$k] ?? null;
    }

    public static function save(array $row, $id = 0) {
        global $wpdb;
        $row['updated_at'] = TW_DB::now();
        if ($id) {
            $wpdb->update(self::table(), $row, ['id' => (int) $id]);
            return (int) $id;
        }
        $row['created_at'] = $row['updated_at'];
        $wpdb->insert(self::table(), $row);
        return (int) $wpdb->insert_id;
    }

    /** What the map search looks for when there is no Google Maps link. */
    public static function map_query($r) {
        $names = self::countries();
        return implode(', ', array_filter([$r->name, $r->address, $r->postcode, $r->area, $r->region, $names[$r->country] ?? '']));
    }

    /** Same partner = same name and place (so a re-import updates instead of duplicating). */
    private static function find_same(array $row) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::table() . ' WHERE name = %s AND country = %s AND region = %s AND area = %s LIMIT 1', $row['name'], $row['country'], $row['region'], $row['area']));
    }

    /** Areas already used (datalist suggestions in the form). */
    private static function distinct($col) {
        global $wpdb;
        return $wpdb->get_col("SELECT DISTINCT $col FROM " . self::table() . " WHERE $col <> '' ORDER BY $col LIMIT 500");
    }

    // ------------------------------------------------------------------ console screen

    public static function page() {
        global $wpdb;
        $t = self::table();
        $s = TW_Admin::q('s');
        $fc = TW_Admin::q('country');
        $fr = TW_Admin::q('region');
        $fa = TW_Admin::q('active');
        $where = ['1=1']; $p = [];
        if ($s !== '') { $l = '%' . $wpdb->esc_like($s) . '%'; $where[] = '(name LIKE %s OR area LIKE %s OR address LIKE %s OR phone LIKE %s OR email LIKE %s)'; array_push($p, $l, $l, $l, $l, $l); }
        if ($fc !== '') { $where[] = 'country = %s'; $p[] = $fc; }
        if ($fr !== '') { $where[] = 'region = %s'; $p[] = $fr; }
        if ($fa === '1' || $fa === '0') { $where[] = 'active = %d'; $p[] = (int) $fa; }
        $w = implode(' AND ', $where);
        if ($p) $w = $wpdb->prepare($w, $p);
        $paged = max(1, (int) TW_Admin::q('paged'));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE $w");
        $rows = $wpdb->get_results("SELECT * FROM $t WHERE $w ORDER BY country = 'GR' DESC, country, region, area, name LIMIT " . TW_Admin::PER_PAGE . ' OFFSET ' . (($paged - 1) * TW_Admin::PER_PAGE));
        $all = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
        $edit = TW_Admin::q('edit') !== '' ? self::get((int) TW_Admin::q('edit')) : null;
        $e = $edit ?: (object) ['id' => 0, 'name' => '', 'country' => 'GR', 'region' => '', 'area' => '', 'address' => '', 'postcode' => '', 'phone' => '', 'email' => '', 'website' => '', 'map_url' => '', 'notes' => '', 'name_en' => '', 'area_en' => '', 'address_en' => '', 'notes_en' => '', 'is_service' => 1, 'active' => 1];
        $draft = get_transient('tw_partner_draft_' . get_current_user_id());
        if ($draft) { delete_transient('tw_partner_draft_' . get_current_user_id()); $e = (object) array_merge((array) $e, $draft); }
        $countries = self::countries();
        $post = TW_Admin::url();
        $result = get_transient('tw_partner_import_' . get_current_user_id());
        if ($result) delete_transient('tw_partner_import_' . get_current_user_id());
        $export = wp_nonce_url(add_query_arg(array_filter(['action' => 'tw_partner_export', 's' => $s, 'country' => $fc, 'region' => $fr, 'active' => $fa], 'strlen'), $post), 'tw_partner_export');
        $template = wp_nonce_url(add_query_arg(['action' => 'tw_partner_export', 'template' => 1], $post), 'tw_partner_export');
        $used_regions = self::distinct('region');
        ?>
        <div class="wrap tw-wrap">
            <h1 class="wp-heading-inline">Συνεργάτες</h1>
            <a class="page-title-action" href="<?php echo esc_url($export); ?>"><?php echo TW_Admin::icon('download'); // phpcs:ignore ?> Εξαγωγή Excel</a>
            <?php TW_Admin::notice(); ?>
            <?php if ($result) : ?>
                <div class="notice notice-info"><p><strong>Αποτέλεσμα εισαγωγής:</strong> νέοι <?php echo (int) $result['inserted']; ?>, ενημερώθηκαν <?php echo (int) $result['updated']; ?>, με λάθη <?php echo count($result['errors']); ?>.</p>
                    <?php if ($result['errors']) : ?><details open><summary>Γραμμές που δεν περάστηκαν</summary><ul><?php foreach (array_slice($result['errors'], 0, 200) as $err) echo '<li>' . esc_html($err) . '</li>'; ?></ul></details><?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="tw-columns">
                <div class="tw-panel" id="tw-partner-form">
                    <h2><?php echo $e->id ? 'Επεξεργασία συνεργάτη' : 'Προσθήκη συνεργάτη'; ?></h2>
                    <form method="post" action="<?php echo esc_url($post); ?>">
                        <?php wp_nonce_field('tw_partner_save'); ?><input type="hidden" name="action" value="tw_partner_save"><input type="hidden" name="id" value="<?php echo (int) $e->id; ?>">
                        <p><label>Επωνυμία <input type="text" name="name" value="<?php echo esc_attr($e->name); ?>" maxlength="190" required></label></p>
                        <p><label>Χώρα <select name="country" data-tw-partner-country><?php foreach ($countries as $k => $l) if ($k !== 'XX') printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($e->country, $k, false), esc_html($l)); ?></select></label></p>
                        <p data-tw-region-gr<?php echo $e->country === 'GR' ? '' : ' hidden'; ?>><label>Νομός <select name="region_gr"><option value="">— Επιλέξτε —</option><?php foreach (array_keys(self::GR_REGIONS) as $r) printf('<option%s>%s</option>', selected($e->country === 'GR' ? $e->region : '', $r, false), esc_html($r)); ?></select></label></p>
                        <p data-tw-region-other<?php echo $e->country === 'GR' ? ' hidden' : ''; ?>><label>Περιφέρεια / Πόλη <input type="text" name="region_other" value="<?php echo esc_attr($e->country === 'GR' ? '' : $e->region); ?>" maxlength="100" list="tw-partner-regions" placeholder="π.χ. Λευκωσία, Dubai, Doha"></label></p>
                        <datalist id="tw-partner-regions"><?php foreach ($used_regions as $r) if (!isset(self::GR_REGIONS[$r])) echo '<option value="' . esc_attr($r) . '">'; ?></datalist>
                        <p><label>Περιοχή <span class="description">(πόλη, δήμος ή συνοικία – τρίτο πεδίο της αναζήτησης)</span> <input type="text" name="area" value="<?php echo esc_attr($e->area); ?>" maxlength="100" list="tw-partner-areas" placeholder="π.χ. Περιστέρι"></label></p>
                        <datalist id="tw-partner-areas"><?php foreach (self::distinct('area') as $r) echo '<option value="' . esc_attr($r) . '">'; ?></datalist>
                        <p><label>Διεύθυνση <input type="text" name="address" value="<?php echo esc_attr($e->address); ?>" maxlength="255"></label></p>
                        <p><label>Τ.Κ. <input type="text" name="postcode" value="<?php echo esc_attr($e->postcode); ?>" maxlength="20" class="small-text"></label></p>
                        <p><label>Τηλέφωνο <span class="description">(πολλά: χωρισμένα με κόμμα)</span> <input type="text" name="phone" value="<?php echo esc_attr($e->phone); ?>" maxlength="100"></label></p>
                        <p><label>Email <input type="email" name="email" value="<?php echo esc_attr($e->email); ?>" maxlength="190"></label></p>
                        <p><label>Ιστότοπος <input type="text" name="website" value="<?php echo esc_attr($e->website); ?>" maxlength="255" placeholder="www.example.gr"></label></p>
                        <p><label>Χάρτης Google <span class="description">(σύνδεσμος του Google Maps ή του Google Business Profile – προαιρετικό)</span> <input type="url" name="map_url" value="<?php echo esc_attr($e->map_url); ?>" maxlength="500" placeholder="https://maps.app.goo.gl/…"></label>
                        <span class="description">Στο Google Maps, ανοίξτε την επιχείρηση → «Κοινοποίηση» → «Αντιγραφή συνδέσμου». Η πινέζα στη σελίδα του site ανοίγει αυτόν τον σύνδεσμο· αν μείνει κενό, ανοίγει αναζήτηση στο Google Maps με την επωνυμία και τη διεύθυνση<?php if ($e->id && !$e->map_url) : ?> (<a href="<?php echo esc_url('https://www.google.com/maps/search/?api=1&query=' . rawurlencode(self::map_query($e))); ?>" target="_blank" rel="noopener">δοκιμή αναζήτησης</a>)<?php endif; ?>.</span></p>
                        <p><label>Σημείωση για τους πελάτες <span class="description">(προαιρετικό, π.χ. ωράριο)</span> <input type="text" name="notes" value="<?php echo esc_attr($e->notes); ?>" maxlength="255"></label></p>
                        <details class="tw-partner-en"<?php echo ($e->name_en . $e->area_en . $e->address_en . $e->notes_en) !== '' ? ' open' : ''; ?>>
                            <summary>English (αγγλική σελίδα) – προαιρετικό</summary>
                            <p class="description">Ό,τι μείνει κενό εμφανίζεται στην αγγλική σελίδα με αυτόματη μεταγραφή σε λατινικούς χαρακτήρες (π.χ. «Περιστέρι» → «Peristeri»). Συμπληρώστε μόνο όπου θέλετε διαφορετική απόδοση. Ο νομός μεταφράζεται αυτόματα.</p>
                            <?php foreach (['name_en' => ['Επωνυμία', 190, 'name'], 'area_en' => ['Περιοχή', 100, 'area'], 'address_en' => ['Διεύθυνση', 255, 'address'], 'notes_en' => ['Σημείωση', 255, 'notes']] as $k => $d) : ?>
                                <p><label><?php echo esc_html($d[0]); ?> (EN) <input type="text" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($e->$k); ?>" maxlength="<?php echo (int) $d[1]; ?>" placeholder="<?php echo esc_attr(self::translit($e->{$d[2]})); ?>"></label></p>
                            <?php endforeach; ?>
                        </details>
                        <p><label><input type="checkbox" name="is_service" value="1" <?php checked((int) $e->is_service, 1); ?>> Εξουσιοδοτημένο Service</label>
                           <label><input type="checkbox" name="active" value="1" <?php checked((int) $e->active, 1); ?>> Εμφανίζεται στην αναζήτηση της σελίδας</label></p>
                        <p><button class="button button-primary"><?php echo $e->id ? 'Αποθήκευση' : 'Προσθήκη'; ?></button>
                            <?php if ($e->id) : ?><a class="button" href="<?php echo esc_url(TW_Admin::url('partners')); ?>">Άκυρο</a><?php endif; ?></p>
                    </form>
                    <script>
                    (function () {
                        var f = document.getElementById('tw-partner-form'), c = f.querySelector('[data-tw-partner-country]');
                        c.addEventListener('change', function () {
                            f.querySelector('[data-tw-region-gr]').hidden = c.value !== 'GR';
                            f.querySelector('[data-tw-region-other]').hidden = c.value === 'GR';
                        });
                    })();
                    </script>
                </div>
                <div class="tw-panel">
                    <h2>Εισαγωγή από Excel</h2>
                    <p><a class="button" href="<?php echo esc_url($template); ?>"><?php echo TW_Admin::icon('download'); // phpcs:ignore ?>Λήψη προτύπου Excel</a></p>
                    <form method="post" action="<?php echo esc_url($post); ?>" enctype="multipart/form-data">
                        <?php wp_nonce_field('tw_partner_import'); ?><input type="hidden" name="action" value="tw_partner_import">
                        <p><input type="file" name="file" accept=".xlsx,.csv,.txt" required></p>
                        <p class="description">Στήλες: <code>Επωνυμία</code> (υποχρεωτική), <code>Χώρα</code> (κενό = Ελλάδα), <code>Νομός / Περιφέρεια</code> (υποχρεωτική), <code>Περιοχή / Πόλη</code>, <code>Διεύθυνση</code>, <code>Τ.Κ.</code>, <code>Τηλέφωνο</code>, <code>Email</code>, <code>Ιστότοπος</code>, <code>Σημείωση</code>, <code>Χάρτης Google</code> (σύνδεσμος Google Maps / Business Profile· αν λείπει η στήλη, κρατιέται ο σύνδεσμος που υπάρχει), <code>Εξουσιοδοτημένο Service</code> και <code>Ενεργός</code> (Ναι/Όχι, κενό = Ναι). Ο νομός γράφεται όπως θέλετε (π.χ. «Νομός Αττικής», «ΑΤΤΙΚΗ»). Συνεργάτης με ίδια επωνυμία, νομό και περιοχή ενημερώνεται, δεν διπλασιάζεται.</p>
                        <p><button class="button button-primary">Εισαγωγή</button></p>
                    </form>
                    <h2>Στη σελίδα του site</h2>
                    <p>Βάλτε σε οποιαδήποτε σελίδα το shortcode <code>[<?php echo esc_html(self::SHORTCODE); ?>]</code>. Ο επισκέπτης επιλέγει Χώρα (προεπιλογή Ελλάδα) → Νομό → Περιοχή και βλέπει τους συνεργάτες.</p>
                    <p class="description">Προεπιλογή: μόνο όσοι είναι «Εξουσιοδοτημένο Service». Για όλους τους ενεργούς: <code>[<?php echo esc_html(self::SHORTCODE); ?> service="0"]</code>. Άλλη αρχική χώρα: <code>country="CY"</code>.</p>
                    <p class="description">Σύνολο: <strong><?php echo (int) $all; ?></strong> συνεργάτες.</p>
                </div>
            </div>

            <form method="get" class="tw-filters">
                <?php TW_Admin::get_form_fields('partners'); ?>
                <input type="search" name="s" value="<?php echo esc_attr($s); ?>" placeholder="Επωνυμία, περιοχή, τηλέφωνο…">
                <select name="country"><option value="">Όλες οι χώρες</option><?php foreach ($wpdb->get_col("SELECT DISTINCT country FROM $t") as $k) printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($fc, $k, false), esc_html($countries[$k] ?? $k)); ?></select>
                <select name="region"><option value="">Όλοι οι νομοί / περιφέρειες</option><?php foreach ($used_regions as $r) printf('<option%s>%s</option>', selected($fr, $r, false), esc_html($r)); ?></select>
                <select name="active"><option value="">Ενεργοί & κρυφοί</option><option value="1"<?php selected($fa, '1'); ?>>Μόνο ενεργοί</option><option value="0"<?php selected($fa, '0'); ?>>Μόνο κρυφοί</option></select>
                <button class="button">Φίλτρο</button>
            </form>
            <?php TW_Admin::pagination($total, $paged); ?>
            <table class="widefat striped"><thead><tr><th>Επωνυμία</th><th>Χώρα</th><th>Νομός / Περιφέρεια</th><th>Περιοχή</th><th>Διεύθυνση</th><th>Επικοινωνία</th><th>Service</th><th>Εμφάνιση</th><th></th></tr></thead><tbody>
            <?php if (!$rows) echo '<tr><td colspan="9">' . ($all ? 'Δεν βρέθηκαν συνεργάτες με αυτά τα φίλτρα.' : 'Δεν υπάρχουν ακόμη συνεργάτες. Προσθέστε τον πρώτο ή κάντε εισαγωγή από Excel.') . '</td></tr>'; ?>
            <?php foreach ($rows as $r) : ?>
                <tr<?php echo $r->active ? '' : ' style="opacity:.6"'; ?>>
                    <td><strong><?php echo esc_html($r->name); ?></strong><?php if ($r->notes) echo '<br><span class="description">' . esc_html($r->notes) . '</span>'; ?></td>
                    <td><?php echo esc_html($countries[$r->country] ?? $r->country); ?></td>
                    <td><?php echo esc_html($r->region); ?></td>
                    <td><?php echo esc_html($r->area); ?></td>
                    <td><?php echo esc_html(trim($r->address . ($r->postcode ? ', ' . $r->postcode : ''), ', ')); ?></td>
                    <td><?php echo esc_html($r->phone); ?><?php if ($r->email) echo '<br>' . esc_html($r->email); ?><?php if ($r->website) echo '<br><a href="' . esc_url($r->website) . '" target="_blank" rel="noopener">' . esc_html(preg_replace('#^https?://(www\.)?#', '', untrailingslashit($r->website))) . '</a>'; ?><?php echo '<br><a href="' . esc_url($r->map_url ?: 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(self::map_query($r))) . '" target="_blank" rel="noopener" class="description">' . ($r->map_url ? '📍 Χάρτης Google' : '📍 αναζήτηση στον χάρτη') . '</a>'; ?></td>
                    <td><?php echo $r->is_service ? 'Ναι' : '—'; ?></td>
                    <td><?php echo $r->active ? 'Ενεργός' : 'Κρυφός'; ?></td>
                    <td style="white-space:nowrap"><a href="<?php echo esc_url(TW_Admin::url('partners', ['edit' => $r->id]) . '#tw-partner-form'); ?>">Επεξεργασία</a> ·
                        <form method="post" action="<?php echo esc_url($post); ?>" style="display:inline" onsubmit="return confirm('Αφαίρεση του συνεργάτη «<?php echo esc_js($r->name); ?>»; (Για να μη φαίνεται προσωρινά, βγάλτε το «Εμφανίζεται» αντί να τον αφαιρέσετε.)');">
                            <?php wp_nonce_field('tw_partner_delete'); ?><input type="hidden" name="action" value="tw_partner_delete"><input type="hidden" name="id" value="<?php echo (int) $r->id; ?>">
                            <button class="button-link" style="color:#b42318">Αφαίρεση</button></form></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php TW_Admin::pagination($total, $paged); ?>
        </div>
        <?php
    }

    // ------------------------------------------------------------------ console actions

    public static function handle_save() {
        TW_Admin::guard('tw_partner_save');
        $id = (int) TW_Admin::post('id');
        $in = wp_unslash($_POST);
        $in['region'] = ($in['country'] ?? 'GR') === 'GR' ? ($in['region_gr'] ?? '') : ($in['region_other'] ?? '');
        $in['is_service'] = empty($_POST['is_service']) ? '0' : '1';
        $in['active'] = empty($_POST['active']) ? '0' : '1';
        [$row, $err] = self::clean($in);
        if ($err) {
            set_transient('tw_partner_draft_' . get_current_user_id(), $row, 300);
            TW_Admin::flash($err, 'error');
            TW_Admin::back('tw-partners', ['edit' => $id ?: null]);
        }
        if ($id && !self::get($id)) $id = 0;
        $new = self::save($row, $id);
        TW_DB::log_event($id ? 'partner_updated' : 'partner_added', $row['name'] . ' – ' . $row['region'] . ($row['area'] ? ' / ' . $row['area'] : ''));
        TW_Admin::flash(($id ? 'Οι αλλαγές για «' : 'Προστέθηκε ο συνεργάτης «') . esc_html($row['name']) . ($id ? '» αποθηκεύτηκαν.' : '».'));
        TW_Admin::back('tw-partners', $id ? [] : []);
    }

    public static function handle_delete() {
        global $wpdb;
        TW_Admin::guard('tw_partner_delete');
        $p = self::get((int) TW_Admin::post('id'));
        if ($p) {
            $wpdb->delete(self::table(), ['id' => (int) $p->id]);
            TW_DB::log_event('partner_deleted', $p->name . ' – ' . $p->region . ($p->area ? ' / ' . $p->area : ''));
            TW_Admin::flash('Ο συνεργάτης «' . esc_html($p->name) . '» αφαιρέθηκε.');
        }
        TW_Admin::back('tw-partners');
    }

    public static function handle_import() {
        TW_Admin::guard('tw_partner_import');
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            TW_Admin::flash('Επιλέξτε αρχείο.', 'error');
            TW_Admin::back('tw-partners');
        }
        $rows = TW_Importer::read_file($_FILES['file']['tmp_name'], $_FILES['file']['name']);
        if (is_wp_error($rows)) {
            TW_Admin::flash($rows->get_error_message(), 'error');
            TW_Admin::back('tw-partners');
        }
        $res = self::import_rows($rows);
        set_transient('tw_partner_import_' . get_current_user_id(), $res, 600);
        TW_Admin::back('tw-partners');
    }

    /** @return array ['inserted' => n, 'updated' => n, 'errors' => string[]] */
    public static function import_rows(array $rows) {
        $rows = array_values(array_filter($rows, function ($r) { return is_array($r) && trim(implode('', array_map('strval', $r))) !== ''; }));
        $res = ['inserted' => 0, 'updated' => 0, 'errors' => []];
        if (!$rows) return $res;
        $map = [];
        foreach ($rows[0] as $i => $h) {
            $h = mb_strtolower(trim((string) $h));
            foreach (self::COLUMNS as $f => $def) if (!isset($map[$f]) && in_array($h, $def[1], true)) $map[$f] = $i;
        }
        if (!isset($map['name'])) {
            $res['errors'][] = 'Δεν βρέθηκε η στήλη «Επωνυμία» στην πρώτη γραμμή. Χρησιμοποιήστε το πρότυπο Excel.';
            return $res;
        }
        array_shift($rows);
        foreach ($rows as $n => $r) {
            $in = [];
            foreach ($map as $f => $i) $in[$f] = trim((string) ($r[$i] ?? ''));
            foreach (['is_service', 'active'] as $f) if (isset($in[$f]) && $in[$f] === '') unset($in[$f]);
            [$row, $err] = self::clean($in);
            if ($err) { $res['errors'][] = 'Γραμμή ' . ($n + 2) . ($row['name'] !== '' ? ' (' . $row['name'] . ')' : '') . ': ' . $err; continue; }
            $id = self::find_same($row);
            if (!isset($map['map_url']) && $id) unset($row['map_url']); // older Excel without the column: keep the saved link
            self::save($row, $id);
            $res[$id ? 'updated' : 'inserted']++;
        }
        TW_DB::log_event('partners_imported', 'νέοι ' . $res['inserted'] . ', ενημερώσεις ' . $res['updated'] . ', λάθη ' . count($res['errors']));
        return $res;
    }

    public static function handle_export() {
        global $wpdb;
        if (!current_user_can(TW_Admin::cap())) wp_die('', 403);
        check_admin_referer('tw_partner_export');
        $x = new TW_Xlsx(array_map(function ($d) { return $d[0]; }, self::COLUMNS));
        if (empty($_GET['template'])) {
            $where = ['1=1']; $p = [];
            foreach (['country', 'region'] as $k) if (TW_Admin::q($k) !== '') { $where[] = "$k = %s"; $p[] = TW_Admin::q($k); }
            if (in_array(TW_Admin::q('active'), ['0', '1'], true)) { $where[] = 'active = %d'; $p[] = (int) TW_Admin::q('active'); }
            if (TW_Admin::q('s') !== '') { $l = '%' . $wpdb->esc_like(TW_Admin::q('s')) . '%'; $where[] = '(name LIKE %s OR area LIKE %s OR address LIKE %s OR phone LIKE %s OR email LIKE %s)'; array_push($p, $l, $l, $l, $l, $l); }
            $w = implode(' AND ', $where);
            if ($p) $w = $wpdb->prepare($w, $p);
            $countries = self::countries();
            foreach ($wpdb->get_results('SELECT * FROM ' . self::table() . " WHERE $w ORDER BY country, region, area, name") as $r) {
                $x->add([$r->name, $countries[$r->country] ?? $r->country, $r->region, $r->area, $r->address, $r->postcode, $r->phone, $r->email, $r->website, $r->notes, $r->map_url, $r->name_en, $r->area_en, $r->address_en, $r->notes_en, $r->is_service ? 'Ναι' : 'Όχι', $r->active ? 'Ναι' : 'Όχι']);
            }
            TW_DB::log_event('partners_exported', '');
        } else {
            $x->add(['Παράδειγμα Τεχνική Ο.Ε.', 'Ελλάδα', 'Αττική', 'Περιστέρι', 'Θηβών 100', '12134', '210 0000000', 'info@example.gr', 'www.example.gr', 'Δευ–Παρ 9:00–17:00', 'https://maps.app.goo.gl/xxxxxxxx', '', '', '', 'Mon–Fri 9:00–17:00', 'Ναι', 'Ναι']);
        }
        $x->download(empty($_GET['template']) ? 'synergates-' . wp_date('Y-m-d') . '.xlsx' : 'protypo-synergaton.xlsx', 'Συνεργάτες');
        exit;
    }

    // ------------------------------------------------------------------ public finder

    public static function shortcode($atts = []) {
        global $wpdb;
        $atts = shortcode_atts(['service' => '1', 'country' => 'GR'], $atts, self::SHORTCODE);
        $where = 'active = 1' . ($atts['service'] === '0' ? '' : ' AND is_service = 1');
        $rows = $wpdb->get_results('SELECT name, country, region, area, address, postcode, phone, email, website, notes, map_url, name_en, area_en, address_en, notes_en FROM ' . self::table() . " WHERE $where ORDER BY name");
        $lang = TW_I18n::lang();
        $names = self::countries();
        $en = $lang === 'en';
        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                'n' => $en ? self::en($r, 'name') : $r->name, 'c' => $r->country, 'r' => $r->region,
                'a' => $en ? self::en($r, 'area') : $r->area, 'ad' => $en ? self::en($r, 'address') : $r->address, 'pc' => $r->postcode,
                'ph' => $r->phone, 'em' => $r->email, 'w' => $r->website, 'no' => $en ? self::en($r, 'notes') : $r->notes,
                // The map search always uses the local (Greek) address: it finds the place more reliably.
                'q' => self::map_query($r),
                'm' => $r->map_url,
            ];
        }
        $countries = [];
        foreach (array_unique(array_column($data, 'c')) as $c) $countries[$c] = $lang === 'en' ? self::country_en($c) : ($names[$c] ?? $c);
        $default = strtoupper($atts['country']);
        if (!isset($countries[$default])) $default = isset($countries['GR']) ? 'GR' : (string) key($countries);
        // Greece first, then alphabetically.
        uksort($countries, function ($a, $b) use ($countries) { return ($b === 'GR') <=> ($a === 'GR') ?: strcmp($countries[$a], $countries[$b]); });
        $regions_en = $lang === 'en' ? self::GR_REGIONS : (object) [];

        if (!wp_style_is('tw-front', 'registered')) TW_Frontend::register_assets();
        wp_enqueue_style('tw-front');
        wp_enqueue_script('tw-partners', TW_URL . 'assets/js/partners.js', [], TW_VERSION, true);
        $id = 'tw-partners-' . wp_unique_id();
        $cfg = [
            'partners' => $data, 'countries' => $countries, 'default' => $default, 'regionsEn' => $regions_en, 'lang' => $lang,
            't' => [
                'region_gr' => tw__('Νομός'), 'region' => tw__('Περιφέρεια / Πόλη'), 'area' => tw__('Περιοχή'),
                'choose' => tw__('— Επιλέξτε —'), 'all_areas' => tw__('Όλες οι περιοχές'), 'map' => tw__('Χάρτης'),
                'found1' => tw__('1 συνεργάτης'), 'foundN' => tw__('%d συνεργάτες'), 'none' => tw__('Δεν υπάρχει ακόμη συνεργάτης σε αυτή την περιοχή. Δείτε τους κοντινούς νομούς ή επικοινωνήστε μαζί μας.'),
                'pick' => tw__('Επιλέξτε νομό για να δείτε τους συνεργάτες.'), 'pick_other' => tw__('Επιλέξτε περιφέρεια / πόλη για να δείτε τους συνεργάτες.'),
            ],
        ];
        ob_start();
        ?>
        <div class="tw-app tw-partners" id="<?php echo esc_attr($id); ?>" lang="<?php echo esc_attr($lang); ?>"<?php if ($data) echo ' data-tw-partners="' . esc_attr(wp_json_encode($cfg, JSON_UNESCAPED_UNICODE)) . '"'; ?>>
            <h3><?php tw_e('Εύρεση εξουσιοδοτημένου Service'); ?></h3>
            <?php if (!$data) : ?>
                <p class="tw-hint"><?php tw_e('Η λίστα συνεργατών θα είναι σύντομα διαθέσιμη.'); ?></p>
            <?php else : ?>
            <div class="tw-partners-filters">
                <label><span><?php tw_e('Χώρα'); ?></span><select data-f="country"></select></label>
                <label data-wrap="region"><span data-l="region"></span><select data-f="region"></select></label>
                <label data-wrap="area" hidden><span><?php tw_e('Περιοχή'); ?></span><select data-f="area"></select></label>
            </div>
            <p class="tw-hint" data-f="status" aria-live="polite"></p>
            <div class="tw-partners-list" data-f="list"></div>
            <noscript><p class="tw-alert tw-alert--error"><?php tw_e('Για την αναζήτηση συνεργατών απαιτείται JavaScript.'); ?></p></noscript>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /** English country names for the English page. */
    public static function country_en($c) {
        $en = ['GR' => 'Greece', 'CY' => 'Cyprus', 'AE' => 'United Arab Emirates', 'QA' => 'Qatar', 'SA' => 'Saudi Arabia', 'KW' => 'Kuwait', 'BH' => 'Bahrain', 'OM' => 'Oman',
            'BG' => 'Bulgaria', 'RO' => 'Romania', 'IT' => 'Italy', 'DE' => 'Germany', 'FR' => 'France', 'ES' => 'Spain', 'PT' => 'Portugal', 'NL' => 'Netherlands', 'BE' => 'Belgium',
            'AT' => 'Austria', 'IE' => 'Ireland', 'LU' => 'Luxembourg', 'MT' => 'Malta', 'HR' => 'Croatia', 'SI' => 'Slovenia', 'SK' => 'Slovakia', 'CZ' => 'Czechia', 'PL' => 'Poland',
            'HU' => 'Hungary', 'DK' => 'Denmark', 'SE' => 'Sweden', 'FI' => 'Finland', 'EE' => 'Estonia', 'LV' => 'Latvia', 'LT' => 'Lithuania', 'GB' => 'United Kingdom', 'CH' => 'Switzerland',
            'AL' => 'Albania', 'MK' => 'North Macedonia', 'RS' => 'Serbia', 'TR' => 'Türkiye', 'EG' => 'Egypt', 'LB' => 'Lebanon', 'JO' => 'Jordan', 'IL' => 'Israel', 'US' => 'USA',
            'CA' => 'Canada', 'AU' => 'Australia'];
        return $en[$c] ?? (self::countries()[$c] ?? $c);
    }
}
