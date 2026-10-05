<?php

namespace UniqueWorkbench\Importer\Reading;

/**
 * Tabular data: the headings (the first row) and the rows under them, each a
 * list of strings as long as the headings. Blank rows are already dropped;
 * `numbers` holds each row's line in the original file (headings = 1).
 */
final class Sheet
{
    /**
     * @param  string[]  $headers
     * @param  array<int, string[]>  $rows
     * @param  int[]  $numbers
     */
    public function __construct(
        public readonly array $headers,
        public readonly array $rows,
        public readonly array $numbers = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self($data['headers'], $data['rows'], $data['numbers'] ?? []);
    }

    public function toArray(): array
    {
        return ['headers' => $this->headers, 'rows' => $this->rows, 'numbers' => $this->numbers];
    }

    /** The row's line in the file */
    public function number(int $index): int
    {
        return $this->numbers[$index] ?? $index + 2;
    }

    /** Up to $count different non-blank values from a column, for showing what it holds */
    public function samples(int $column, int $count = 3): array
    {
        $samples = [];
        foreach ($this->rows as $row) {
            $value = trim($row[$column] ?? '');
            if ($value !== '' && ! in_array($value, $samples, true)) {
                $samples[] = $value;
                if (count($samples) === $count) {
                    break;
                }
            }
        }

        return $samples;
    }

    /** Every value in a column (blanks included) */
    public function column(int $column): array
    {
        return array_map(fn (array $row) => $row[$column] ?? '', $this->rows);
    }
}
