<?php

namespace UniqueWorkbench\Importer;

/**
 * A kind of record rows can become (users, customers, contacts…), defined by
 * the app: the fields it takes, the options for its choice fields, which
 * values need a decision, and how one row is imported.
 *
 * import() does the real work, preview included: the preview runs every row
 * inside a database transaction that's rolled back, so it reports exactly
 * what the import will do (rows that create a persona the next row then
 * uses, duplicates within the file…). So import() must only touch the
 * database — no mail, no HTTP — and keep any per-run state (caches of what
 * it created) on the instance, which is made fresh for each pass.
 */
abstract class ImportTarget
{
    abstract public function key(): string;

    /** "Users", "Customers"… */
    abstract public function label(): string;

    /** One line on what rows become */
    public function description(): string
    {
        return '';
    }

    /** @return Field[] */
    abstract public function fields(): array;

    /** Import one row (see the class comment) */
    abstract public function import(Row $row): RowOutcome;

    public function field(string $key): ?Field
    {
        foreach ($this->fields() as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * What the mapping lacks for this target (shown on the Columns step):
     * by default, required fields that no column feeds.
     *
     * @param  string[]  $mapped  field keys with a column
     */
    public function mappingErrors(array $mapped): array
    {
        $errors = [];
        foreach ($this->fields() as $field) {
            if ($field->required && ! in_array($field->key, $mapped, true)) {
                $errors[] = "Pick the column with the {$field->label}.";
            }
        }

        return $errors;
    }

    /**
     * The options a choice field's values can map to: option key => label,
     * or group label => [option key => label]. "Create new" and "Ignore" are
     * added by the wizard (Mapping::NEW when the field canCreate, Mapping::IGNORE).
     */
    public function choiceOptions(Field $field): array
    {
        return [];
    }

    /** Where a new option can go ("inside" another, e.g. a sub-unit): option key => label; [] = nowhere to choose */
    public function parentOptions(Field $field): array
    {
        return [];
    }

    /**
     * The option a value starts on, when no option's label matches it (the
     * wizard tries that first): null = Create new when the field canCreate,
     * else Ignore.
     */
    public function suggestChoice(Field $field, string $value): ?string
    {
        return null;
    }

    /**
     * Values of a (non-choice) field that need a decision before importing —
     * e.g. an email on many rows that's really the office's. $counts is value
     * key => ['value', 'rows']. Returns value key => ['note' => why, 'default' => option].
     */
    public function review(Field $field, array $counts): array
    {
        return [];
    }

    /** The options for reviewed values of $field: option key => label */
    public function reviewOptions(Field $field): array
    {
        return [];
    }

    /** Called once before the first row */
    public function begin(): void
    {
    }

    /** Called after the last row: notes for the whole run ("Created 3 personas") */
    public function finish(): array
    {
        return [];
    }
}
