<?php

namespace UniqueWorkbench\Importer;

use Illuminate\Database\Eloquent\Model;
use UniqueWorkbench\Importer\Mapping\Mapping;

/**
 * The mapping last used, per scope and target, for files with these
 * headings (signature): the next file from the same system starts from it.
 */
class SavedMapping extends Model
{
    protected $table = 'importer_mappings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['column_map' => 'array', 'value_map' => 'array', 'options' => 'array'];
    }

    /** The same headings, in any order and however they're spelled or cased */
    public static function signature(array $headers): string
    {
        $headings = array_map([Normalizer::class, 'compact'], $headers);
        sort($headings);

        return sha1(implode('|', $headings));
    }

    public static function for(string $scope, string $target, array $headers): ?self
    {
        return static::where(['scope' => $scope, 'target' => $target, 'signature' => static::signature($headers)])->first();
    }

    /**
     * Remember a run's mapping, keyed by heading text rather than column
     * position, so the same headings in another order still map.
     */
    public static function remember(string $scope, string $target, array $headers, Mapping $mapping): void
    {
        $byHeading = [];
        foreach ($mapping->columns as $column => $fields) {
            $byHeading[Normalizer::compact($headers[$column] ?? '')] = $fields;
        }

        static::updateOrCreate(
            ['scope' => $scope, 'target' => $target, 'signature' => static::signature($headers)],
            ['column_map' => $byHeading, 'value_map' => $mapping->values, 'options' => $mapping->options],
        );
    }

    /** The saved mapping for this file's column positions */
    public function toMapping(array $headers): Mapping
    {
        $columns = [];
        foreach ($headers as $column => $heading) {
            if (array_key_exists($key = Normalizer::compact($heading), $this->column_map)) {
                $columns[$column] = $this->column_map[$key];
            }
        }

        return new Mapping($columns, $this->value_map ?? [], $this->options ?? []);
    }
}
