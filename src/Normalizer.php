<?php

namespace UniqueWorkbench\Importer;

use DateTimeImmutable;

/**
 * Cleans one cell for a field type. Each returns [value, problem]: the value
 * (null when blank or unusable) and, when something had to be dropped or
 * changed, what — shown on the preview and results as a note for the row.
 */
class Normalizer
{
    public const US_STATES = [
        'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR', 'california' => 'CA',
        'colorado' => 'CO', 'connecticut' => 'CT', 'delaware' => 'DE', 'district of columbia' => 'DC',
        'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI', 'idaho' => 'ID', 'illinois' => 'IL',
        'indiana' => 'IN', 'iowa' => 'IA', 'kansas' => 'KS', 'kentucky' => 'KY', 'louisiana' => 'LA',
        'maine' => 'ME', 'maryland' => 'MD', 'massachusetts' => 'MA', 'michigan' => 'MI', 'minnesota' => 'MN',
        'mississippi' => 'MS', 'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE', 'nevada' => 'NV',
        'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY',
        'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK', 'oregon' => 'OR',
        'pennsylvania' => 'PA', 'rhode island' => 'RI', 'south carolina' => 'SC', 'south dakota' => 'SD',
        'tennessee' => 'TN', 'texas' => 'TX', 'utah' => 'UT', 'vermont' => 'VT', 'virginia' => 'VA',
        'washington' => 'WA', 'west virginia' => 'WV', 'wisconsin' => 'WI', 'wyoming' => 'WY',
        'puerto rico' => 'PR',
    ];

    /** UTF-8 punctuation read as Windows-1252 and saved again, and what it was */
    public const MOJIBAKE = [
        'â€™' => '’', 'â€˜' => '‘', 'â€œ' => '“', 'â€' . "\u{9D}" => '”', 'â€"' => '–', 'â€”' => '—', 'â€¦' => '…', 'Â ' => ' ',
    ];

    /**
     * Trimmed, single-spaced, without stray trailing commas/semicolons or the
     * "�" an earlier bad save left behind; '' is null.
     */
    public static function text(?string $value): array
    {
        if ($value === null) {
            return [null, null];
        }

        // Text saved in the wrong encoding somewhere along the way: "’" read as "â€™", and characters
        // already lost ("�", or that saved again as "ï¿½")
        $problem = null;
        $value = str_replace(array_keys(static::MOJIBAKE), array_values(static::MOJIBAKE), $value);
        if (str_contains($value, "\u{FFFD}") || str_contains($value, 'ï¿½')) {
            $value = str_replace(["\u{FFFD}", 'ï¿½'], '', $value);
            $problem = 'had an unreadable character, which was removed';
        }

        $value = preg_replace('/[\s\x{00A0}]+/u', ' ', $value);
        $value = trim(rtrim(trim($value), ',;'));

        return [$value === '' ? null : $value, $problem];
    }

    public static function email(?string $value, array $placeholders = []): array
    {
        [$value] = static::text($value);
        if ($value === null) {
            return [null, null];
        }

        $value = mb_strtolower(preg_replace('/^mailto:/i', '', $value));
        foreach ($placeholders as $pattern) {
            if (@preg_match($pattern, $value)) {
                return [null, "“{$value}” looks like a placeholder, so it was left blank"];
            }
        }
        if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return [null, "“{$value}” isn't an email address, so it was left blank"];
        }

        return [$value, null];
    }

    /** A North American number as its 10 digits (a leading 1 or +1 dropped); extensions ignored */
    public static function phone(?string $value): array
    {
        [$value] = static::text($value);
        if ($value === null) {
            return [null, null];
        }

        $digits = preg_replace('/\D/', '', preg_replace('/\s*(x|ext\.?|extension)\s*\d+$/i', '', $value));
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 10
            ? [$digits, null]
            : [null, "“{$value}” isn't a 10-digit phone number, so it was left blank"];
    }

    /** Y-m-d from ISO, US (m/d/Y, m/d/y) or an Excel day number */
    public static function date(?string $value): array
    {
        [$value] = static::text($value);
        if ($value === null) {
            return [null, null];
        }

        if (preg_match('/^\d{5}(\.\d+)?$/', $value) && (float) $value > 20000 && (float) $value < 80000) {
            return [substr(Reading\XlsxReader::excelDate((float) $value), 0, 10), null];
        }

        // Strict: a format matches only when it reads the whole value with no overflow (no 13th month)
        foreach (['Y-m-d', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'n/j/Y', 'n/j/y', 'n-j-Y', 'Y/n/j', 'M j, Y', 'F j, Y', 'j M Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date && DateTimeImmutable::getLastErrors() === false) {
                return [$date->format('Y-m-d'), null];
            }
        }

        return [null, "“{$value}” isn't a date, so it was left blank"];
    }

    /** 5-digit or ZIP+4; 4 digits get their leading zero back (Excel drops it) */
    public static function zip(?string $value): array
    {
        [$value] = static::text($value);
        if ($value === null) {
            return [null, null];
        }

        if (preg_match('/^(\d{5})(?:-?(\d{4}))?$/', $value, $m)) {
            return [isset($m[2]) ? "{$m[1]}-{$m[2]}" : $m[1], null];
        }
        if (preg_match('/^\d{4}$/', $value)) {
            return ['0' . $value, null];
        }

        return [null, "ZIP “{$value}” isn't a ZIP code, so it was left blank"];
    }

    /** Two-letter code, from "mo", "MO" or "Missouri" */
    public static function state(?string $value): array
    {
        [$value] = static::text($value);
        if ($value === null) {
            return [null, null];
        }

        $value = rtrim($value, '.');
        if (preg_match('/^[a-z]{2}$/i', $value)) {
            return [strtoupper($value), null];
        }

        return isset(static::US_STATES[mb_strtolower($value)])
            ? [static::US_STATES[mb_strtolower($value)], null]
            : [null, "State “{$value}” isn't a state, so it was left blank"];
    }

    /**
     * A whole name in one cell → [first, last]: "Last, First" (how many
     * systems export people) or "First Last" (split at the first space, as
     * the rest of the platform does). One word is a first name.
     */
    public static function personName(?string $value): array
    {
        [$value] = static::text($value);
        if ($value === null) {
            return [null, null];
        }

        if (str_contains($value, ',')) {
            [$last, $first] = array_map('trim', explode(',', $value, 2));
            if ($first !== '' && $last !== '') {
                return [$first, $last];
            }
            $value = $first !== '' ? $first : $last;
        }

        $parts = preg_split('/\s+/', $value, 2);

        return [$parts[0], $parts[1] ?? null];
    }

    /** "a, b; c" → ["a", "b", "c"] */
    public static function list(?string $value): array
    {
        [$value] = static::text($value);

        return $value === null ? [] : array_values(array_unique(array_filter(array_map(
            fn ($part) => static::text($part)[0], preg_split('/[,;|\n]/', $value)))));
    }

    /** The key a value is mapped by on the Values step: trimmed, single-spaced, lower case ('' = blank) */
    public static function valueKey(?string $value): string
    {
        return mb_strtolower(static::text($value)[0] ?? '');
    }

    /** "Zip-Code #" → "zipcodenumber": headings compared without case, spaces or punctuation */
    public static function compact(string $heading): string
    {
        $heading = mb_strtolower(str_replace(['&', '#'], [' and ', ' number '], $heading));

        return preg_replace('/[^a-z0-9]/', '', $heading);
    }
}
