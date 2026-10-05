<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The import wizard (uniqueworkbench/importer):
 * - importer_runs: one uploaded file going through the wizard — whose it is (`scope`, e.g.
 *   "organization:12"), what its rows are (`target`), the column and value mappings, and the
 *   preview / final results (kept as JSON files on the importer disk, `data_path`).
 * - importer_mappings: the mappings last used for a set of headings, per scope and target,
 *   so the next export from the same system maps itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importer_runs', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 100)->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('target', 50)->nullable();
            $table->string('filename');
            $table->string('path');
            $table->json('headers');
            $table->unsignedInteger('row_count')->default(0);
            $table->json('column_map')->nullable();
            $table->json('value_map')->nullable();
            $table->json('options')->nullable();
            $table->string('status', 20)->default('uploaded');
            $table->json('counts')->nullable();
            $table->timestamp('previewed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('importer_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 100);
            $table->string('target', 50);
            $table->string('signature', 40);
            $table->json('column_map');
            $table->json('value_map')->nullable();
            $table->json('options')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'target', 'signature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importer_mappings');
        Schema::dropIfExists('importer_runs');
    }
};
