<?php

namespace UniqueWorkbench\Importer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use UniqueWorkbench\Importer\Mapping\Mapping;
use UniqueWorkbench\Importer\Reading\Sheet;

/**
 * One file going through the wizard: uploaded → mapped → previewed →
 * completed (or failed). The upload is parsed once into "<path>.json" next
 * to it; the preview's and the import's results go in "<path>.preview.json"
 * and "<path>.results.json".
 */
class ImportRun extends Model
{
    public const UPLOADED = 'uploaded';
    public const MAPPED = 'mapped';
    public const PREVIEWED = 'previewed';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    protected $table = 'importer_runs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'column_map' => 'array',
            'value_map' => 'array',
            'options' => 'array',
            'counts' => 'array',
            'previewed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(fn (ImportRun $run) => static::disk()->delete([
            $run->path, "{$run->path}.json", "{$run->path}.preview.json", "{$run->path}.results.json",
        ]));
    }

    public static function disk()
    {
        return Storage::disk(config('importer.disk'));
    }

    public function sheet(): Sheet
    {
        return Sheet::fromArray(json_decode(static::disk()->get("{$this->path}.json"), true));
    }

    public function mapping(): Mapping
    {
        return new Mapping($this->column_map ?? [], $this->value_map ?? [], $this->options ?? []);
    }

    /** The preview's (or, once run, the import's) results: ['rows' => […], 'counts' => […], 'notes' => […]] */
    public function results(?string $kind = null): ?array
    {
        $kind ??= $this->status === self::COMPLETED ? 'results' : 'preview';
        $file = "{$this->path}.{$kind}.json";

        return static::disk()->exists($file) ? json_decode(static::disk()->get($file), true) : null;
    }

    public function saveResults(string $kind, array $results): void
    {
        static::disk()->put("{$this->path}.{$kind}.json", json_encode($results));
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::FAILED], true);
    }
}
