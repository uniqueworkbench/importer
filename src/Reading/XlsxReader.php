<?php

namespace UniqueWorkbench\Importer\Reading;

use SimpleXMLElement;
use UniqueWorkbench\Importer\ImportException;
use ZipArchive;

/**
 * The first worksheet of an .xlsx file, as text: shared and inline strings,
 * numbers as written (no float noise), booleans as TRUE/FALSE, and cells
 * formatted as dates as Y-m-d (Y-m-d H:i with a time). Needs PHP's zip
 * extension; formulas give their last calculated value.
 */
class XlsxReader
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Built-in number formats that are dates/times */
    private const DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 30, 36, 45, 46, 47, 50, 57];

    /** @return array<int, string[]> row index (0-based) => cells */
    public function read(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new ImportException('Excel files can\'t be read on this server (PHP\'s zip extension is off). Save the sheet as CSV and upload that.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new ImportException('That doesn\'t look like an Excel (.xlsx) file. Save it as .xlsx or CSV and try again.');
        }

        try {
            $strings = $this->sharedStrings($zip);
            $dateStyles = $this->dateStyles($zip);
            $sheet = $this->xml($zip, $this->firstSheetPath($zip));

            return $this->rows($sheet, $strings, $dateStyles);
        } finally {
            $zip->close();
        }
    }

    private function xml(ZipArchive $zip, string $name): ?SimpleXMLElement
    {
        $content = $zip->getFromName($name);
        if ($content === false) {
            return null;
        }

        $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if ($xml === false) {
            throw new ImportException('The Excel file is damaged. Save it again (or as CSV) and retry.');
        }
        $xml->registerXPathNamespace('m', self::NS);

        return $xml;
    }

    private function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $this->xml($zip, 'xl/workbook.xml');
        $rels = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
        $sheet = $workbook?->xpath('//m:sheets/m:sheet')[0] ?? null;

        if ($sheet && $rels) {
            $id = (string) $sheet->attributes(self::REL_NS)['id'];
            foreach ($rels->children() as $rel) {
                if (static::attr($rel, 'Id') === $id) {
                    $target = ltrim(static::attr($rel, 'Target'), '/');

                    return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $this->xml($zip, 'xl/sharedStrings.xml');
        if (! $xml) {
            return [];
        }

        $strings = [];
        foreach ($xml->children(self::NS)->si as $si) {
            $strings[] = $this->text($si);
        }

        return $strings;
    }

    /** The text of a string item: plain <t>, or the runs of rich text */
    private function text(SimpleXMLElement $item): string
    {
        $item = $item->children(self::NS);
        if (isset($item->t)) {
            return (string) $item->t;
        }

        $text = '';
        foreach ($item->r as $run) {
            $text .= (string) $run->children(self::NS)->t;
        }

        return $text;
    }

    /** @return array<int, true> style indexes (cellXfs) whose number format is a date */
    private function dateStyles(ZipArchive $zip): array
    {
        $xml = $this->xml($zip, 'xl/styles.xml');
        if (! $xml) {
            return [];
        }

        $custom = [];
        foreach ($xml->xpath('//m:numFmts/m:numFmt') ?: [] as $format) {
            $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', static::attr($format, 'formatCode'));
            $custom[(int) static::attr($format, 'numFmtId')] = (bool) preg_match('/[dmyhs]/i', $code);
        }

        $styles = [];
        foreach ($xml->xpath('//m:cellXfs/m:xf') ?: [] as $index => $xf) {
            $id = (int) static::attr($xf, 'numFmtId');
            if (in_array($id, self::DATE_FORMATS, true) || ($custom[$id] ?? false)) {
                $styles[$index] = true;
            }
        }

        return $styles;
    }

    private function rows(?SimpleXMLElement $sheet, array $strings, array $dateStyles): array
    {
        if (! $sheet) {
            throw new ImportException('The Excel file has no worksheet.');
        }

        $rows = [];
        foreach ($sheet->children(self::NS)->sheetData->row as $row) {
            $index = static::attr($row, 'r') !== '' ? (int) static::attr($row, 'r') - 1 : ($rows ? max(array_keys($rows)) + 1 : 0);
            $cells = [];
            foreach ($row->children(self::NS)->c as $cell) {
                $column = $this->columnIndex(static::attr($cell, 'r')) ?? count($cells);
                $cells[$column] = $this->value($cell, $strings, $dateStyles);
            }
            if ($cells) {
                $width = max(array_keys($cells)) + 1;
                $rows[$index] = array_replace(array_fill(0, $width, ''), $cells);
            }
        }

        ksort($rows);

        return $rows;
    }

    private function value(SimpleXMLElement $cell, array $strings, array $dateStyles): string
    {
        $children = $cell->children(self::NS);
        $raw = isset($children->v) ? (string) $children->v : '';

        return match (static::attr($cell, 't')) {
            's' => $strings[(int) $raw] ?? '',
            'inlineStr' => isset($children->is) ? $this->text($children->is) : '',
            'b' => $raw === '1' ? 'TRUE' : 'FALSE',
            'str', 'e' => $raw,
            default => $raw !== '' && is_numeric($raw)
                ? (isset($dateStyles[(int) static::attr($cell, 's')]) ? static::excelDate((float) $raw) : static::number($raw))
                : $raw,
        };
    }

    /** An unprefixed attribute (via attributes(): plain $el['x'] misses it on elements reached through children($ns)) */
    private static function attr(SimpleXMLElement $element, string $name): string
    {
        return (string) ($element->attributes()[$name] ?? '');
    }

    /** "B12" → 1 */
    private function columnIndex(string $reference): ?int
    {
        if (! preg_match('/^([A-Z]+)/', $reference, $match)) {
            return null;
        }

        $index = 0;
        foreach (str_split($match[1]) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /** Excel's serial day number (1900 system) → Y-m-d, with H:i when there's a time */
    public static function excelDate(float $serial): string
    {
        $seconds = (int) round(($serial - 25569) * 86400);
        $format = fmod($serial, 1.0) > 0 ? 'Y-m-d H:i' : 'Y-m-d';

        return gmdate($format, $seconds);
    }

    /** 3142803451 rather than 3.142803451E+9; 0.1 rather than 0.10000000000000001 */
    private static function number(string $raw): string
    {
        if (preg_match('/^-?\d+$/', $raw)) {
            return $raw;
        }

        $value = (float) $raw;

        return floor($value) === $value && abs($value) < 1e15
            ? number_format($value, 0, '.', '')
            : rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
    }
}
