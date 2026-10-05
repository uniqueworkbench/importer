<?php

namespace UniqueWorkbench\Importer;

use Illuminate\Support\Facades\DB;
use Throwable;
use UniqueWorkbench\Importer\Mapping\HeaderMatcher;
use UniqueWorkbench\Importer\Mapping\Mapping;
use UniqueWorkbench\Importer\Reading\Sheet;

/**
 * The wizard's working parts, apart from HTTP: picking the likely target,
 * the starting mapping, the Values step's questions, and running rows.
 */
class Wizard
{
    public function __construct(private HeaderMatcher $matcher)
    {
    }

    /** The target whose fields the headings match best (the first when none do) */
    public function guessTarget(Sheet $sheet, array $targets): ?string
    {
        $best = null;
        $bestScore = -1;
        foreach ($targets as $key => $target) {
            $score = 0;
            foreach ($this->matcher->match($sheet, $target->fields()) as $match) {
                $score += $match['how'] === 'heading' ? 2 : 1;
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$key, $score];
            }
        }

        return $best;
    }

    /**
     * Where the Columns step starts: the run's own mapping when it's for this
     * target, else the one saved for these headings, else the matcher's
     * guesses. `how` says, per column, where each came from; `answered`
     * whether columns left out mean "don't import" (a mapping someone made)
     * rather than "still to choose" (guesses).
     *
     * @return array{0: Mapping, 1: array<int, string>, 2: bool}
     */
    public function startingMapping(ImportRun $run, string $scope, string $targetKey, ImportTarget $target): array
    {
        $sheet = $run->sheet();

        if ($run->target === $targetKey && $run->column_map !== null) {
            return [$run->mapping(), array_fill_keys(array_keys($run->column_map), 'yours'), true];
        }

        if ($saved = SavedMapping::for($scope, $targetKey, $sheet->headers)) {
            $mapping = $saved->toMapping($sheet->headers);

            return [$mapping, array_fill_keys(array_keys($mapping->columns), 'saved'), true];
        }

        $columns = [];
        $how = [];
        foreach ($this->matcher->match($sheet, $target->fields()) as $column => $match) {
            $columns[$column] = $match['fields'];
            $how[$column] = $match['how'];
        }

        return [new Mapping($columns), $how, false];
    }

    /**
     * The Values step: for each mapped choice field, every value in the file
     * with its rows and the option it maps to (as chosen before, else the
     * option whose label matches it, else the target's suggestion, else
     * Create new / Ignore); for other fields, only the values the target
     * flags for review.
     *
     * @return array<int, array{field: Field, options: array, parents: array, review: bool, values: array}>
     */
    public function valueSections(Sheet $sheet, ImportTarget $target, Mapping $mapping): array
    {
        $sections = [];
        foreach ($mapping->valueCounts($sheet, $target) as $key => $counts) {
            $field = $target->field($key);
            if (! $field) {
                continue;
            }

            if ($field->isChoice()) {
                $options = $target->choiceOptions($field);
                $values = [];
                foreach ($counts as $valueKey => $count) {
                    $chosen = $mapping->values[$key][$valueKey] ?? null;
                    // "Create new" from last time, now that it exists (made by that import): the existing one
                    if (($chosen['choice'] ?? null) === Mapping::NEW && ($existing = $this->labelMatch($options, $count['value']))) {
                        $chosen = ['choice' => $existing, 'parent' => null];
                    }
                    $values[$valueKey] = $count + [
                        'choice' => $chosen['choice'] ?? $this->suggest($field, $target, $options, $count['value']),
                        'parent' => $chosen['parent'] ?? null,
                        'note' => null,
                    ];
                }
                $sections[] = ['field' => $field, 'options' => $options, 'parents' => $field->canCreate ? $target->parentOptions($field) : [],
                    'review' => false, 'values' => $values];

                continue;
            }

            $flagged = $target->review($field, $counts);
            if ($flagged) {
                $values = [];
                foreach ($flagged as $valueKey => $flag) {
                    $values[$valueKey] = $counts[$valueKey] + [
                        'choice' => $mapping->values[$key][$valueKey]['choice'] ?? $flag['default'],
                        'parent' => null,
                        'note' => $flag['note'] ?? null,
                    ];
                }
                $sections[] = ['field' => $field, 'options' => $target->reviewOptions($field), 'parents' => [],
                    'review' => true, 'values' => $values];
            }
        }

        return $sections;
    }

    /** The option whose label is the value (ignoring case, spaces, punctuation), else the target's pick, else new/ignore */
    private function suggest(Field $field, ImportTarget $target, array $options, string $value): string
    {
        return $this->labelMatch($options, $value)
            ?? $target->suggestChoice($field, $value)
            ?? ($field->canCreate && Normalizer::compact($value) !== '' ? Mapping::NEW : Mapping::IGNORE);
    }

    /** The option labelled as the value — or, for a path ("Com_Mgt › West"), whose last part is */
    private function labelMatch(array $options, string $value): ?string
    {
        $wanted = Normalizer::compact($value);
        if ($wanted === '') {
            return null;
        }

        foreach (static::flatOptions($options) as $option => $label) {
            if (Normalizer::compact($label) === $wanted || Normalizer::compact(preg_replace('/^.*›\s*/u', '', $label)) === $wanted) {
                return (string) $option;
            }
        }

        return null;
    }

    /** Options with their groups flattened: key => label */
    public static function flatOptions(array $options): array
    {
        $flat = [];
        foreach ($options as $key => $label) {
            if (is_array($label)) {
                $flat += $label;
            } else {
                $flat[$key] = $label;
            }
        }

        return $flat;
    }

    /**
     * Import every row. Each row runs in its own savepoint, so a row that
     * fails is reported and undone alone; the whole run is one transaction,
     * committed for a real import and rolled back for the preview.
     *
     * @return array{rows: array, counts: array, notes: array}
     */
    public function run(ImportRun $run, ImportTarget $target, bool $commit): array
    {
        $sheet = $run->sheet();
        $mapping = $run->mapping();
        $fieldColumns = $mapping->fieldColumns();
        $rows = [];
        $counts = array_fill_keys(array_keys(RowOutcome::ACTIONS), 0);

        @set_time_limit(600);
        DB::beginTransaction();

        try {
            $target->begin();

            foreach ($sheet->rows as $index => $cells) {
                $row = new Row($sheet->number($index), $cells, $fieldColumns, $mapping, $target);

                try {
                    $outcome = DB::transaction(fn () => $target->import($row));
                } catch (ImportException $e) {
                    $outcome = RowOutcome::error('', $e->getMessage());
                } catch (Throwable $e) {
                    report($e);
                    $outcome = RowOutcome::error('', 'Something went wrong importing this row: ' . $e->getMessage());
                }

                $counts[$outcome->action]++;
                $rows[] = [
                    'row' => $row->number,
                    'index' => $index,
                    'action' => $outcome->action,
                    'record' => $outcome->record,
                    'notes' => array_values(array_unique([...$outcome->notes, ...$row->notes])),
                    'url' => $commit ? $outcome->url : null,
                ];
            }

            $notes = $target->finish();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $commit ? DB::commit() : DB::rollBack();

        return ['rows' => $rows, 'counts' => array_filter($counts), 'notes' => $notes];
    }
}
