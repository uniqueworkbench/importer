<?php

namespace UniqueWorkbench\Importer;

/**
 * What importing a row did (or, in the preview, would do): its action, the
 * record it's about (a name), notes on the details, and a link to the record.
 */
final class RowOutcome
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const LINKED = 'linked';
    public const UNCHANGED = 'unchanged';
    public const SKIPPED = 'skipped';
    public const ERROR = 'error';

    /** How each action is shown */
    public const ACTIONS = [
        self::CREATED => 'New',
        self::UPDATED => 'Updated',
        self::LINKED => 'Linked',
        self::UNCHANGED => 'No change',
        self::SKIPPED => 'Skipped',
        self::ERROR => 'Error',
    ];

    /** "10 new", "1 error", "3 errors": a count of rows with an action, for summaries (only Error is a noun) */
    public static function countLabel(string $action, int $count): string
    {
        $label = mb_strtolower(self::ACTIONS[$action] ?? $action);

        return number_format($count) . ' ' . ($action === self::ERROR ? \Illuminate\Support\Str::plural($label, $count) : $label);
    }

    public function __construct(
        public readonly string $action,
        public readonly string $record = '',
        public array $notes = [],
        public readonly ?string $url = null,
    ) {
    }

    public static function created(string $record, array $notes = [], ?string $url = null): self
    {
        return new self(self::CREATED, $record, $notes, $url);
    }

    /** Updated when anything changed, else unchanged */
    public static function updated(string $record, array $notes = [], ?string $url = null, bool $changed = true): self
    {
        return new self($changed ? self::UPDATED : self::UNCHANGED, $record, $notes, $url);
    }

    public static function linked(string $record, array $notes = [], ?string $url = null): self
    {
        return new self(self::LINKED, $record, $notes, $url);
    }

    public static function skipped(string $record, string $why): self
    {
        return new self(self::SKIPPED, $record, [$why]);
    }

    public static function error(string $record, string $why): self
    {
        return new self(self::ERROR, $record, [$why]);
    }
}
