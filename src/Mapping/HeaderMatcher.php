<?php

namespace UniqueWorkbench\Importer\Mapping;

use UniqueWorkbench\Importer\Field;
use UniqueWorkbench\Importer\Normalizer;
use UniqueWorkbench\Importer\Reading\Sheet;

/**
 * Guesses which field each column is, best evidence first:
 * 1. heading — the heading is one of the field's names (ignoring case, spaces, punctuation);
 * 2. similar — the heading is a near-spelling of one, or contains one ("Customer Email Address");
 * 3. data — the column's values look like emails, phones, dates or ZIP codes and
 *    exactly one unused field of that type fits.
 * A single-valued field goes to one column only; columns nothing fits are left
 * for the person to map. Returns column index => ['fields' => [keys], 'how' => heading|similar|data].
 */
class HeaderMatcher
{
    /** @param  Field[]  $fields */
    public function match(Sheet $sheet, array $fields): array
    {
        $matches = [];
        $used = [];
        $take = function (int $column, Field $field, string $how) use (&$matches, &$used) {
            $matches[$column] = ['fields' => [$field->key], 'how' => $how];
            $used[$field->key] = true;
        };
        $free = fn (Field $field) => $field->multiple || ! isset($used[$field->key]);

        $headings = array_map([Normalizer::class, 'compact'], $sheet->headers);

        // 1. Exact names
        foreach ($headings as $column => $heading) {
            foreach ($fields as $field) {
                if ($free($field) && in_array($heading, array_map([Normalizer::class, 'compact'], $field->names()), true)) {
                    $take($column, $field, 'heading');
                    break;
                }
            }
        }

        // 2. Near spellings ("Adress", "Zip-Code") and headings containing a name ("Primary Email Address")
        foreach ($headings as $column => $heading) {
            if (isset($matches[$column]) || $heading === '') {
                continue;
            }
            $best = null;
            $bestScore = 0;
            foreach ($fields as $field) {
                if (! $free($field)) {
                    continue;
                }
                foreach (array_map([Normalizer::class, 'compact'], $field->names()) as $name) {
                    $score = $this->similarity($heading, $name);
                    if ($score > $bestScore) {
                        [$best, $bestScore] = [$field, $score];
                    }
                }
            }
            if ($best && $bestScore >= 0.8) {
                $take($column, $best, 'similar');
            }
        }

        // 3. What the data looks like
        foreach ($sheet->headers as $column => $heading) {
            if (isset($matches[$column]) || ! ($type = $this->sniff($sheet, $column))) {
                continue;
            }
            $candidates = array_values(array_filter($fields, fn (Field $f) => $f->type === $type && $free($f)));
            if (count($candidates) === 1) {
                $take($column, $candidates[0], 'data');
            }
        }

        ksort($matches);

        return $matches;
    }

    /** 1 for a match; 0.8–0.99 for a near spelling or a heading containing the name */
    private function similarity(string $heading, string $name): float
    {
        if ($name === '' || $heading === $name) {
            return $heading === $name ? 1.0 : 0.0;
        }

        // Contains a longer name: "contactphonenumber" ∋ "phonenumber"
        if (strlen($name) >= 5 && str_contains($heading, $name)) {
            return 0.8 + 0.19 * strlen($name) / strlen($heading);
        }

        if (strlen($heading) < 5 || strlen($name) < 5) {
            return 0.0;
        }

        $distance = levenshtein($heading, $name);

        return 1 - $distance / max(strlen($heading), strlen($name)) - 0.05;
    }

    /** The field type most of a column's values look like, or null */
    private function sniff(Sheet $sheet, int $column): ?string
    {
        $values = array_values(array_filter(array_map('trim', $sheet->column($column)), fn ($v) => $v !== ''));
        if (count($values) < 2) {
            return null;
        }
        $values = array_slice($values, 0, 200);

        $tests = [
            Field::EMAIL => fn ($v) => (bool) filter_var($v, FILTER_VALIDATE_EMAIL),
            Field::PHONE => fn ($v) => preg_match('/^[\d\s().+\-]+$/', $v) && in_array(strlen(preg_replace('/\D/', '', $v)), [10, 11], true),
            Field::ZIP => fn ($v) => (bool) preg_match('/^\d{5}(-\d{4})?$/', $v),
            Field::DATE => fn ($v) => (bool) preg_match('#^(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}/\d{1,2}/\d{2,4})$#', $v),
        ];

        foreach ($tests as $type => $test) {
            if (count(array_filter($values, $test)) / count($values) >= 0.8) {
                return $type;
            }
        }

        return null;
    }
}
