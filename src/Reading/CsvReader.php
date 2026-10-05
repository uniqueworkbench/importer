<?php

namespace UniqueWorkbench\Importer\Reading;

use UniqueWorkbench\Importer\ImportException;

/**
 * Delimited text: UTF-8 (with or without a BOM) or, failing that, Windows-1252
 * (what Excel's "CSV" saves on Windows); comma, semicolon, tab or pipe
 * separated, whichever the first line uses most. Quoted fields may span lines.
 */
class CsvReader
{
    /** @return array<int, string[]> line index => cells */
    public function read(string $path): array
    {
        $text = @file_get_contents($path);
        if ($text === false) {
            throw new ImportException("The file couldn't be read.");
        }

        $text = static::toUtf8($text);
        $delimiter = static::delimiter(strtok($text, "\n") ?: '');

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $text);
        rewind($stream);

        $lines = [];
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $lines[] = $cells === [null] ? [] : $cells;
        }
        fclose($stream);

        return $lines;
    }

    public static function toUtf8(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        } elseif (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
            $text = mb_convert_encoding(substr($text, 2), 'UTF-8', str_starts_with($text, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }

        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    public static function delimiter(string $firstLine): string
    {
        $counts = [];
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts[$candidate] = substr_count($firstLine, $candidate);
        }
        arsort($counts);

        return reset($counts) > 0 ? array_key_first($counts) : ',';
    }
}
