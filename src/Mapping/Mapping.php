<?php

namespace UniqueWorkbench\Importer\Mapping;

use UniqueWorkbench\Importer\Field;
use UniqueWorkbench\Importer\ImportTarget;
use UniqueWorkbench\Importer\Normalizer;
use UniqueWorkbench\Importer\Reading\Sheet;

/**
 * How a file's columns and values map onto a target:
 * - columns: column index => field keys ([] = don't import). A column can feed several fields
 *   (Division → customer type and units) and a `multiple` field several columns.
 * - values: field key => value key (Normalizer::valueKey) => ['choice' => option, 'parent' => option|null]
 *   — choice fields' values, and values a target asked to review (a shared office email…).
 * - options: anything else asked for, e.g. `source` (the system external ids come from).
 */
final class Mapping
{
    public const NEW = '__new__';
    public const IGNORE = '__ignore__';

    public function __construct(
        public array $columns = [],
        public array $values = [],
        public array $options = [],
    ) {
    }

    /** field key => column indexes feeding it */
    public function fieldColumns(): array
    {
        $fields = [];
        foreach ($this->columns as $column => $keys) {
            foreach ((array) $keys as $key) {
                $fields[$key][] = (int) $column;
            }
        }

        return $fields;
    }

    public function mapped(string $field): bool
    {
        return isset($this->fieldColumns()[$field]);
    }

    /**
     * Problems that stop the import: a column feeding a field twice over, a
     * single-valued field fed by two columns, and what the target needs.
     */
    public function errors(ImportTarget $target, Sheet $sheet): array
    {
        $errors = [];
        foreach ($this->fieldColumns() as $key => $columns) {
            $field = $target->field($key);
            if (! $field) {
                $errors[] = "Unknown field “{$key}”.";
            } elseif (! $field->multiple && count($columns) > 1) {
                $names = implode(' and ', array_map(fn ($c) => "“{$sheet->headers[$c]}”", $columns));
                $errors[] = "{$names} both go to {$field->label}, which takes one column.";
            }
        }

        if ($this->hasField($target, Field::EXTERNAL_ID) && blank($this->options['source'] ?? null)) {
            $errors[] = 'Say which system the IDs come from.';
        }

        return [...$errors, ...$target->mappingErrors(array_keys($this->fieldColumns()))];
    }

    private function hasField(ImportTarget $target, string $type): bool
    {
        foreach (array_keys($this->fieldColumns()) as $key) {
            if ($target->field($key)?->type === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * The different values of each mapped field, with how many rows have
     * each: field key => value key => ['value' => as first seen, 'rows' => n].
     * Multiple fields count every column feeding them.
     */
    public function valueCounts(Sheet $sheet, ImportTarget $target): array
    {
        $counts = [];
        foreach ($this->fieldColumns() as $key => $columns) {
            $field = $target->field($key);
            foreach ($sheet->rows as $row) {
                $seen = [];
                foreach ($columns as $column) {
                    $raw = $row[$column] ?? '';
                    foreach ($field?->type === Field::LIST ? (Normalizer::list($raw) ?: ['']) : [$raw] as $value) {
                        $valueKey = Normalizer::valueKey($value);
                        if ($field?->multiple && $valueKey === '' ) {
                            continue;
                        }
                        if (isset($seen[$valueKey])) {
                            continue;
                        }
                        $seen[$valueKey] = true;
                        $counts[$key][$valueKey] ??= ['value' => Normalizer::text($value)[0] ?? '', 'rows' => 0];
                        $counts[$key][$valueKey]['rows']++;
                    }
                }
            }
            if (isset($counts[$key])) {
                uasort($counts[$key], fn ($a, $b) => [$b['rows'], $a['value']] <=> [$a['rows'], $b['value']]);
            }
        }

        return $counts;
    }

    public function choiceFor(string $field, ?string $value): ?array
    {
        return $this->values[$field][Normalizer::valueKey($value)] ?? null;
    }

    public function toArray(): array
    {
        return ['columns' => $this->columns, 'values' => $this->values, 'options' => $this->options];
    }
}
