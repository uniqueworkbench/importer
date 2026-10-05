<?php

namespace UniqueWorkbench\Importer;

use Illuminate\Support\Facades\Route;
use UniqueWorkbench\Importer\Http\ImportController;

/**
 * Registers the wizard's routes inside the app's own group, which sets the
 * URL prefix, route name prefix (ending in a dot) and middleware:
 *
 *   Route::prefix('organization/import')->name('organization.import.')
 *       ->middleware('can:…')->group(fn () => Importer::routes());
 *
 * The same routes can be registered more than once (e.g. an admin copy under
 * /admin/organizations/{organization}/import); the wizard links within
 * whichever copy it's on, keeping its other route parameters.
 */
class Importer
{
    public static function routes(): void
    {
        Route::get('/', [ImportController::class, 'index'])->name('index');
        Route::post('/', [ImportController::class, 'store'])->name('store');
        Route::get('/{run}', [ImportController::class, 'show'])->name('show')->whereNumber('run');
        Route::get('/{run}/columns', [ImportController::class, 'columns'])->name('columns')->whereNumber('run');
        Route::post('/{run}/columns', [ImportController::class, 'saveColumns'])->name('save-columns')->whereNumber('run');
        Route::get('/{run}/values', [ImportController::class, 'values'])->name('values')->whereNumber('run');
        Route::post('/{run}/values', [ImportController::class, 'saveValues'])->name('save-values')->whereNumber('run');
        Route::get('/{run}/preview', [ImportController::class, 'preview'])->name('preview')->whereNumber('run');
        Route::post('/{run}/preview', [ImportController::class, 'recheck'])->name('recheck')->whereNumber('run');
        Route::post('/{run}/run', [ImportController::class, 'run'])->name('run')->whereNumber('run');
        Route::get('/{run}/download', [ImportController::class, 'download'])->name('download')->whereNumber('run');
        Route::delete('/{run}', [ImportController::class, 'destroy'])->name('destroy')->whereNumber('run');
    }
}
