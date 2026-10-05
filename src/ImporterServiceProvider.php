<?php

namespace UniqueWorkbench\Importer;

use Illuminate\Support\ServiceProvider;
use UniqueWorkbench\Importer\Console\PruneImports;

class ImporterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/importer.php', 'importer');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'importer');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([__DIR__ . '/../config/importer.php' => config_path('importer.php')], 'importer-config');
        $this->publishes([__DIR__ . '/../resources/views' => resource_path('views/vendor/importer')], 'importer-views');

        if ($this->app->runningInConsole()) {
            $this->commands([PruneImports::class]);
        }
    }
}
