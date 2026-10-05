{{-- Preview: what every row will do — worked out by running the import and undoing it, so nothing is saved yet --}}
@php
    use UniqueWorkbench\Importer\RowOutcome;
    $errors_ = $results['counts'][RowOutcome::ERROR] ?? 0;
    $doing = array_sum(array_intersect_key($results['counts'], array_flip([RowOutcome::CREATED, RowOutcome::UPDATED, RowOutcome::LINKED])));
@endphp
<x-importer::page :context="$context" :to="$to" :run="$run" step="preview">
    <div class="flex flex-col sm:flex-row sm:items-center gap-4 p-5 bg-bt_primary-50 border border-bt_primary-200 rounded-md">
        <i class="fa-solid fa-eye text-2xl text-bt_primary-600"></i>
        <div class="flex-1 text-sm text-zinc-700">
            <p class="font-semibold text-zinc-900">Nothing has been saved yet.</p>
            <p>This is what importing {{ number_format($run->row_count) }} {{ Str::lower($target->label()) }} rows will do.
                @if ($errors_)
                    <strong class="text-bt_primary-700">{{ $errors_ }} {{ Str::plural('row', $errors_) }} can't be imported</strong> and will be left out — fix them in the file and import it again, or go ahead without them.
                @endif
            </p>
        </div>
        <a href="{{ $to('download', $run) }}" class="shrink-0 text-sm font-semibold text-bt_primary-600 hover:text-bt_primary-800">
            <i class="fa-solid fa-download me-1.5"></i>Download preview
        </a>
    </div>

    @include('importer::partials.rows', ['route' => 'preview'])

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-4 text-sm">
            <a href="{{ $to('columns', $run) }}" class="text-zinc-600 hover:text-zinc-900"><i class="fa-solid fa-arrow-left me-1.5"></i>Columns</a>
            @if ($hasValues)
                <a href="{{ $to('values', $run) }}" class="text-zinc-600 hover:text-zinc-900"><i class="fa-solid fa-arrow-left me-1.5"></i>Values</a>
            @endif
            <form method="POST" action="{{ $to('recheck', $run) }}">
                @csrf
                <button type="submit" class="text-zinc-600 hover:text-zinc-900" title="Work the preview out again"><i class="fa-solid fa-rotate me-1.5"></i>Check again</button>
            </form>
        </div>
        <form method="POST" action="{{ $to('run', $run) }}" x-data="{ busy: false }"
              @submit="if (busy || ! confirm(@js('Import ' . $doing . ' ' . Str::plural('row', $doing) . ' now?'))) { $event.preventDefault(); return; } busy = true">
            @csrf
            <button type="submit" :disabled="busy" @disabled($doing === 0)
                    class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-sm bg-bt_primary-600 text-white hover:bg-bt_primary-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-bt_primary-500 focus:ring-offset-2">
                <i class="fa-solid fa-file-import me-2" x-show="! busy"></i><i class="fa-solid fa-spinner fa-spin me-2" x-show="busy" x-cloak></i>
                Import {{ number_format($doing) }} {{ Str::plural('row', $doing) }}
            </button>
        </form>
    </div>
</x-importer::page>
