<?php
if (!defined('ABSPATH')) exit;

/**
 * Minimal streaming .xlsx writer for exports: one sheet, bold header row frozen at the top,
 * auto filter, column widths from the content. Every cell is written as text, so serial numbers,
 * phone numbers and ΑΦΜ keep their leading zeros in Excel.
 */
final class TW_Xlsx {
    private $sheet_file;
    private $fh;
    private $row = 0;
    private $widths = [];
    private $cols = 0;

    public function __construct(array $headers) {
        $this->sheet_file = tempnam(get_temp_dir(), 'tw-xlsx');
        $this->fh = fopen($this->sheet_file, 'w');
        $this->cols = count($headers);
        $this->add($headers, 1);
    }

    private static function col($i) {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + ($n - 1) % 26) . $s;
        return $s;
    }

    private static function x($v) {
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $v); // invalid in XML
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public function add(array $values, $style = 0) {
        $this->row++;
        $xml = '<row r="' . $this->row . '">';
        foreach (array_values($values) as $i => $v) {
            $v = (string) $v;
            $len = min(60, max(mb_strlen($v), 4));
            $this->widths[$i] = max($this->widths[$i] ?? 0, $len);
            if ($v === '' && !$style) continue;
            $xml .= '<c r="' . self::col($i) . $this->row . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">' . self::x($v) . '</t></is></c>';
        }
        fwrite($this->fh, $xml . '</row>');
    }

    /** Builds the file and returns its path (caller sends and deletes it). */
    public function finish($sheet_name = 'Export') {
        fclose($this->fh);
        $cols = '';
        foreach ($this->widths as $i => $w) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . ($w + 3) . '" customWidth="1"/>';
        $last = self::col(max(0, $this->cols - 1)) . max(1, $this->row);
        $path = tempnam(get_temp_dir(), 'tw-export');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::x(mb_substr($sheet_name, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . self::x(mb_substr($sheet_name, 0, 31)) . '\'!$A$1:$' . preg_replace('/\d+$/', '', $last) . '$' . max(1, $this->row) . '</definedName></definedNames></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF1D2330"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE8EBEF"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left/><right/><top/><bottom style="thin"><color rgb="FF9AA1AA"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="49" fontId="1" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $cols . '</cols><sheetData>';
        $tail = '</sheetData><autoFilter ref="A1:' . $last . '"/></worksheet>';
        // Stitch header + streamed rows + tail into one file without loading the rows into memory.
        $sheet = tempnam(get_temp_dir(), 'tw-sheet');
        $out = fopen($sheet, 'w');
        fwrite($out, $head);
        $in = fopen($this->sheet_file, 'r');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fwrite($out, $tail);
        fclose($out);
        $zip->addFile($sheet, 'xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($sheet);
        @unlink($this->sheet_file);
        return $path;
    }

    /** Sends the workbook as a download and exits. */
    public function download($filename, $sheet_name = 'Export') {
        $path = $this->finish($sheet_name);
        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        @unlink($path);
        exit;
    }
}
