<?php

namespace UniqueWorkbench\Importer\Reading;

use UniqueWorkbench\Importer\ImportException;

/**
 * Reads an uploaded CSV/TSV/TXT or XLSX file into a Sheet: the first non-blank
 * row is the headings (blank ones become "Column N", repeated ones get " (2)"),
 * fully blank rows are dropped and every row is padded to the headings' width.
 */
class SheetReader
{
    public const EXTENSIONS = ['csv', 'tsv', 'txt', 'xlsx'];

    public function read(string $path, string $extension, ?int $maxRows = null): Sheet
    {
        $lines = match (strtolower($extension)) {
            'csv', 'tsv', 'txt' => (new CsvReader)->read($path),
            'xlsx' => (new XlsxReader)->read($path),
            default => throw new ImportException('Upload a CSV or Excel (.xlsx) file.'),
        };

        $headers = null;
        $rows = [];
        $numbers = [];

        foreach ($lines as $line => $cells) {
            $cells = array_map(fn ($cell) => trim((string) $cell), $cells);
            if (implode('', $cells) === '') {
                continue;
            }

            if ($headers === null) {
                $headers = $this->headers($cells);

                continue;
            }

            if ($maxRows && count($rows) >= $maxRows) {
                throw new ImportException("The file has more than {$maxRows} rows. Split it into smaller files.");
            }

            $rows[] = array_slice(array_pad($cells, count($headers), ''), 0, count($headers));
            $numbers[] = $line + 1;
        }

        if ($headers === null) {
            throw new ImportException('The file is empty.');
        }
        if (! $rows) {
            throw new ImportException('The file has headings but no rows under them.');
        }

        return new Sheet($headers, $rows, $numbers);
    }

    /** Headings, trimmed of trailing blank columns, every one named and unique */
    private function headers(array $cells): array
    {
        while ($cells && end($cells) === '') {
            array_pop($cells);
        }

        $headers = [];
        foreach (array_values($cells) as $i => $cell) {
            $name = $cell !== '' ? $cell : 'Column ' . ($i + 1);
            $candidate = $name;
            for ($n = 2; in_array($candidate, $headers, true); $n++) {
                $candidate = "{$name} ({$n})";
            }
            $headers[] = $candidate;
        }

        return $headers;
    }
}
