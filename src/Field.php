<?php

namespace UniqueWorkbench\Importer;

/**
 * Something a target can take from a column: its key, how it's shown, the
 * headings it's known by (aliases — compared ignoring case, spaces and
 * punctuation) and its type, which decides how values are cleaned (Row) and
 * how a column of data is recognised (HeaderMatcher).
 *
 * - choice: each different value in the column is mapped to one of the
 *   target's options (an existing persona, "create new", "ignore"…) on the
 *   Values step. `canCreate` offers "Create new"; `multiple` lets several
 *   columns feed it (e.g. Division and Territory both → Units).
 * - list: free text split on commas/semicolons (tags).
 * - person_name: one column holding a whole name, "Last, First" or "First Last".
 * - external_id: another system's id for the record, kept so the next import
 *   updates it instead of adding it again; the source system is asked for.
 */
final class Field
{
    public const TEXT = 'text';
    public const EMAIL = 'email';
    public const PHONE = 'phone';
    public const DATE = 'date';
    public const ZIP = 'zip';
    public const STATE = 'state';
    public const PERSON_NAME = 'person_name';
    public const CHOICE = 'choice';
    public const LIST = 'list';
    public const EXTERNAL_ID = 'external_id';

    /**
     * @param  string[]  $aliases
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = self::TEXT,
        public readonly array $aliases = [],
        public readonly bool $required = false,
        public readonly bool $multiple = false,
        public readonly string $group = 'Details',
        public readonly ?string $help = null,
        public readonly bool $canCreate = false,
    ) {
    }

    public function isChoice(): bool
    {
        return $this->type === self::CHOICE;
    }

    /** Every name it goes by: label, key and aliases */
    public function names(): array
    {
        return array_values(array_unique([$this->label, str_replace('_', ' ', $this->key), ...$this->aliases]));
    }
}
