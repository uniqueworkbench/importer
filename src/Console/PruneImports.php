<?php

namespace UniqueWorkbench\Importer\Console;

use Illuminate\Console\Command;
use UniqueWorkbench\Importer\ImportRun;

/** Deletes imports (and their files) older than importer.keep_days — schedule it daily */
class PruneImports extends Command
{
    protected $signature = 'importer:prune';

    protected $description = 'Delete old imports and their uploaded files';

    public function handle(): int
    {
        $count = 0;
        ImportRun::where('created_at', '<', now()->subDays((int) config('importer.keep_days', 90)))
            ->each(function (ImportRun $run) use (&$count) {
                $run->delete();
                $count++;
            });

        $this->info("Deleted {$count} old import(s).");

        return self::SUCCESS;
    }
}
