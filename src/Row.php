<?php

namespace UniqueWorkbench\Importer;

use UniqueWorkbench\Importer\Mapping\Mapping;

/**
 * One row of the file, as a target sees it: its values by field, cleaned for
 * the field's type, with any choice made for them on the Values step. Every
 * problem met while cleaning (a ZIP that wasn't one, a placeholder email…)
 * becomes a note on the row's result.
 */
final class Row
{
    /** @var string[] */
    public array $notes = [];

    /**
     * @param  string[]  $cells  the row's cells, by column
     * @param  array<string, int[]>  $fieldColumns  field key => columns
     */
    public function __construct(
        public readonly int $number,
        public readonly array $cells,
        private readonly array $fieldColumns,
        private readonly Mapping $mapping,
        private readonly ImportTarget $target,
    ) {
    }

    /** Whether a column was mapped to the field (a blank cell in a mapped column still counts) */
    public function has(string $field): bool
    {
        return isset($this->fieldColumns[$field]);
    }

    /** The raw cell for a single-valued field */
    public function raw(string $field): ?string
    {
        $column = $this->fieldColumns[$field][0] ?? null;

        return $column === null ? null : ($this->cells[$column] ?? null);
    }

    /** The raw cells for a field, one per column feeding it (multiple fields) */
    public function raws(string $field): array
    {
        return array_map(fn (int $column) => $this->cells[$column] ?? '', $this->fieldColumns[$field] ?? []);
    }

    /**
     * The cleaned value, by the field's type (null when blank or unusable —
     * with a note saying why). Person names come back as [first, last].
     */
    public function get(string $field): mixed
    {
        $definition = $this->target->field($field);
        $raw = $this->raw($field);

        [$value, $problem] = match ($definition?->type) {
            Field::EMAIL => Normalizer::email($raw, config('importer.placeholder_emails', [])),
            Field::PHONE => Normalizer::phone($raw),
            Field::DATE => Normalizer::date($raw),
            Field::ZIP => Normalizer::zip($raw),
            Field::STATE => Normalizer::state($raw),
            Field::PERSON_NAME => [Normalizer::personName($raw), null],
            Field::LIST => [array_values(array_unique(array_merge(...array_map([Normalizer::class, 'list'], $this->raws($field)) ?: [[]]))), null],
            default => Normalizer::text($raw),
        };

        if ($problem) {
            $this->note(($definition ? "{$definition->label}: " : '') . $problem);
        }

        return $value;
    }

    /** The cleaned text as typed, whatever the field's type (e.g. a phone that wasn't 10 digits) */
    public function text(string $field): ?string
    {
        return Normalizer::text($this->raw($field))[0];
    }

    /**
     * What was chosen for a single choice field's value on the Values step:
     * ['choice' => option, 'parent' => option|null, 'value' => the text], or
     * null when the column isn't mapped. Blank cells have a choice too.
     */
    public function choice(string $field): ?array
    {
        if (! $this->has($field)) {
            return null;
        }

        return $this->choiceFor($field, $this->raw($field));
    }

    /** The choices for every non-blank value feeding a multiple choice field (one per column) */
    public function choices(string $field): array
    {
        $choices = [];
        foreach ($this->raws($field) as $raw) {
            if (Normalizer::valueKey($raw) !== '') {
                $choices[] = $this->choiceFor($field, $raw);
            }
        }

        return $choices;
    }

    /** What was chosen on the Values step for a reviewed value of a non-choice field (a shared email…), or null */
    public function review(string $field, ?string $value): ?string
    {
        return $this->mapping->choiceFor($field, $value)['choice'] ?? null;
    }

    private function choiceFor(string $field, ?string $raw): array
    {
        $chosen = $this->mapping->choiceFor($field, $raw) ?? ['choice' => Mapping::IGNORE];

        return $chosen + ['parent' => null, 'value' => Normalizer::text($raw)[0] ?? ''];
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->mapping->options[$key] ?? $default;
    }

    public function note(string $note): void
    {
        if (! in_array($note, $this->notes, true)) {
            $this->notes[] = $note;
        }
    }
}
