<?php
if (!defined('ABSPATH')) exit;

/**
 * Serial number import from .xlsx / .csv / pasted text.
 *
 * Columns (header row optional, any order when present):
 *   serial (S/N, Σειριακός) – required
 *   product_code (Κωδικός)  – optional
 *   product_name (Περιγραφή, Προϊόν, Μοντέλο) – optional
 *   notes (Σημειώσεις) – optional
 * Without a header row the columns are taken as: serial, product_code, product_name, notes.
 */
final class TW_Importer {
    const HEADER_ALIASES = [
        'serial' => ['serial', 'serial number', 'serial_number', 's/n', 'sn', 'σειριακός', 'σειριακος', 'σειριακός αριθμός', 'σειριακος αριθμος', 'σ/ν'],
        'product_code' => ['product_code', 'code', 'sku', 'κωδικός', 'κωδικος', 'κωδικός προϊόντος', 'κωδικος προιοντος'],
        'product_name' => ['product_name', 'product', 'name', 'model', 'description', 'περιγραφή', 'περιγραφη', 'προϊόν', 'προιον', 'μοντέλο', 'μοντελο', 'όνομα', 'ονομα'],
        'notes' => ['notes', 'note', 'σημειώσεις', 'σημειωσεις', 'παρατηρήσεις', 'παρατηρησεις'],
    ];

    /** @return array[]|WP_Error rows of cells */
    public static function read_file($path, $original_name) {
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        if ($ext === 'xlsx') return self::read_xlsx($path);
        if (in_array($ext, ['csv', 'txt'], true)) return self::read_csv($path);
        return new WP_Error('format', 'Υποστηρίζονται αρχεία .xlsx και .csv. Αρχεία .xls (παλιό Excel) αποθηκεύστε πρώτα ως .xlsx ή .csv.');
    }

    public static function read_csv($path) {
        $raw = file_get_contents($path);
        if ($raw === false) return new WP_Error('read', 'Αδυναμία ανάγνωσης αρχείου.');
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            // Greek Excel saves CSV as Windows-1253.
            $conv = function_exists('iconv') ? @iconv('CP1253', 'UTF-8//IGNORE', $raw) : false;
            $raw = $conv !== false ? $conv : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-7');
        }
        $first = strtok($raw, "\n");
        $delim = ',';
        foreach ([';', "\t", ','] as $d) {
            if (substr_count((string) $first, $d) > 0) { $delim = $d; break; }
        }
        $rows = [];
        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        while (($r = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
            $rows[] = $r;
        }
        fclose($fh);
        return $rows;
    }

    public static function read_xlsx($path) {
        if (!class_exists('ZipArchive')) return new WP_Error('zip', 'Η επέκταση PHP ZipArchive δεν είναι διαθέσιμη. Χρησιμοποιήστε αρχείο CSV.');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return new WP_Error('zip', 'Το αρχείο Excel δεν μπορεί να ανοιχτεί.');

        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $x = @simplexml_load_string($ss);
            if ($x) {
                foreach ($x->si as $si) {
                    if (isset($si->t)) {
                        $shared[] = (string) $si->t;
                    } else {
                        $txt = '';
                        foreach ($si->r as $r) $txt .= (string) $r->t;
                        $shared[] = $txt;
                    }
                }
            }
        }

        // First worksheet, resolved through the workbook relationships.
        $sheet_path = 'xl/worksheets/sheet1.xml';
        $wb = @simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $rels = @simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        if ($wb && $rels && isset($wb->sheets->sheet[0])) {
            $rid = (string) $wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($rels->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $target = ltrim((string) $rel['Target'], '/');
                    $sheet_path = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
                }
            }
        }
        $xml = $zip->getFromName($sheet_path);
        $zip->close();
        if ($xml === false) return new WP_Error('sheet', 'Δεν βρέθηκε φύλλο εργασίας στο αρχείο.');

        $reader = new XMLReader();
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $rows = [];
        $row = null; $col = 0; $type = ''; $val = null; $in_v = false; $in_is = false;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                switch ($reader->localName) {
                    case 'row':
                        $row = [];
                        if ($reader->isEmptyElement) { $rows[] = $row; $row = null; }
                        break;
                    case 'c':
                        $ref = (string) $reader->getAttribute('r');
                        $col = $ref !== '' ? self::col_index($ref) : ($row ? max(array_keys($row)) + 1 : 0);
                        $type = (string) $reader->getAttribute('t');
                        $val = null;
                        break;
                    case 'v': $in_v = true; $val = ''; break;
                    case 'is': $in_is = true; $val = ''; break;
                }
            } elseif ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::CDATA) {
                if ($in_v || $in_is) $val .= $reader->value;
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                switch ($reader->localName) {
                    case 'v': $in_v = false; break;
                    case 'is': $in_is = false; break;
                    case 'c':
                        if ($row !== null && $val !== null) {
                            if ($type === 's') $val = $shared[(int) $val] ?? '';
                            elseif ($type === '' || $type === 'n') $val = self::number_text($val);
                            $row[$col] = $val;
                        }
                        break;
                    case 'row':
                        if ($row !== null) {
                            $max = $row ? max(array_keys($row)) : -1;
                            $dense = [];
                            for ($i = 0; $i <= $max; $i++) $dense[] = $row[$i] ?? '';
                            $rows[] = $dense;
                        }
                        $row = null;
                        break;
                }
            }
        }
        $reader->close();
        return $rows;
    }

    private static function col_index($ref) {
        $letters = preg_replace('/\d/', '', strtoupper($ref));
        $n = 0;
        for ($i = 0; $i < strlen($letters); $i++) $n = $n * 26 + (ord($letters[$i]) - 64);
        return $n - 1;
    }

    /** Excel stores long numeric serials as numbers (sometimes in E-notation). */
    private static function number_text($v) {
        if (preg_match('/^-?\d+(\.\d+)?E\+?\d+$/i', $v)) return sprintf('%.0f', (float) $v);
        if (preg_match('/^(\d+)\.0+$/', $v, $m)) return $m[1];
        return $v;
    }

    /** Maps raw rows to records. */
    public static function to_records(array $rows) {
        $rows = array_values(array_filter($rows, function ($r) {
            return is_array($r) && trim(implode('', array_map('strval', $r))) !== '';
        }));
        if (!$rows) return [];
        $map = ['serial' => 0, 'product_code' => 1, 'product_name' => 2, 'notes' => 3];
        $header = array_map(function ($h) { return mb_strtolower(trim((string) $h)); }, $rows[0]);
        $found = [];
        foreach ($header as $i => $h) {
            foreach (self::HEADER_ALIASES as $field => $aliases) {
                if (in_array($h, $aliases, true) && !isset($found[$field])) $found[$field] = $i;
            }
        }
        $first_line = 1;
        if (isset($found['serial'])) {
            $map = array_merge(array_fill_keys(array_keys($map), null), $found);
            array_shift($rows);
            $first_line = 2;
        }
        $out = [];
        foreach ($rows as $n => $r) {
            $get = function ($f) use ($r, $map) { return $map[$f] === null ? '' : trim((string) ($r[$map[$f]] ?? '')); };
            $out[] = [
                'serial' => TW_Util::normalize_serial($get('serial')),
                'product_code' => mb_substr($get('product_code'), 0, 100),
                'product_name' => mb_substr($get('product_name'), 0, 255),
                'notes' => $get('notes'),
                'line' => $n + $first_line,
            ];
        }
        return $out;
    }

    /**
     * Inserts records; existing serials are never overwritten.
     * @return array inserted, duplicates (list), invalid (list), batch
     */
    public static function import(array $records, $defaults = []) {
        global $wpdb;
        $t = TW_DB::table('serials');
        $batch = 'IMP-' . current_time('Ymd-His');
        $result = ['inserted' => 0, 'duplicates' => [], 'invalid' => [], 'batch' => $batch];
        $seen = [];
        $valid = [];
        foreach ($records as $i => $r) {
            $serial = $r['serial'];
            if ($serial === '' || !preg_match('/^[A-Z0-9][A-Z0-9\-\/\._]{2,63}$/', $serial)) {
                $result['invalid'][] = ($serial === '' ? '(κενό)' : $serial) . ' – γραμμή ' . ($r['line'] ?? $i + 1);
                continue;
            }
            if (isset($seen[$serial])) { $result['duplicates'][] = $serial . ' (διπλό μέσα στο αρχείο)'; continue; }
            $seen[$serial] = true;
            if ($r['product_code'] === '' && !empty($defaults['product_code'])) $r['product_code'] = $defaults['product_code'];
            if ($r['product_name'] === '' && !empty($defaults['product_name'])) $r['product_name'] = $defaults['product_name'];
            $valid[] = $r;
        }
        $now = TW_DB::now();
        foreach (array_chunk($valid, 500) as $chunk) {
            $serials = wp_list_pluck($chunk, 'serial');
            $in = implode(',', array_fill(0, count($serials), '%s'));
            $existing = $wpdb->get_col($wpdb->prepare("SELECT serial_no FROM $t WHERE serial_no IN ($in)", $serials));
            $existing = array_flip($existing);
            $values = [];
            $params = [];
            foreach ($chunk as $r) {
                if (isset($existing[$r['serial']])) { $result['duplicates'][] = $r['serial']; continue; }
                $values[] = '(%s,%s,%s,%s,%s,%s,%s)';
                array_push($params, $r['serial'], $r['product_code'], $r['product_name'], 'available', $batch, $r['notes'], $now);
            }
            if ($values) {
                $sql = "INSERT INTO $t (serial_no, product_code, product_name, status, import_batch, notes, created_at) VALUES " . implode(',', $values);
                $n = $wpdb->query($wpdb->prepare($sql, $params));
                $result['inserted'] += (int) $n;
            }
        }
        TW_DB::log_event('serials_imported', ['batch' => $batch, 'inserted' => $result['inserted'], 'duplicates' => count($result['duplicates']), 'invalid' => count($result['invalid'])]);
        return $result;
    }
}
