{{-- A finished import: what each row did, with links to the records --}}
<x-importer::page :context="$context" :to="$to" :run="$run" step="done">
    <div class="flex flex-col sm:flex-row sm:items-center gap-4 p-5 border rounded-md {{ $run->status === 'completed' ? 'bg-emerald-50 border-emerald-200' : 'bg-bt_primary-50 border-bt_primary-200' }}">
        <i class="fa-solid {{ $run->status === 'completed' ? 'fa-circle-check text-emerald-600' : 'fa-circle-xmark text-bt_primary-600' }} text-2xl"></i>
        <div class="flex-1 text-sm text-zinc-700">
            @if ($run->status === 'completed')
                <p class="font-semibold text-zinc-900">Imported {{ $run->completed_at?->diffForHumans() }}.</p>
                <p>{{ $target?->label() ?? ucfirst($run->target) }} from {{ $run->filename }}. The column and value choices are saved for the next file with these headings.</p>
            @else
                <p class="font-semibold text-zinc-900">This import failed, and nothing from it was saved.</p>
                <p>Upload the file again to retry.</p>
            @endif
        </div>
        @if ($results)
            <a href="{{ $to('download', $run) }}" class="shrink-0 text-sm font-semibold text-bt_primary-600 hover:text-bt_primary-800">
                <i class="fa-solid fa-download me-1.5"></i>Download results
            </a>
        @endif
    </div>

    @if ($results)
        @include('importer::partials.rows', ['route' => 'show'])
    @endif

    <a href="{{ $to('index') }}" class="inline-flex items-center text-sm text-zinc-600 hover:text-zinc-900"><i class="fa-solid fa-arrow-left me-1.5"></i>Imports</a>
</x-importer::page>
