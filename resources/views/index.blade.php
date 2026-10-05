{{-- Upload a file, and the recent imports --}}
@php
    $statuses = [
        'uploaded' => ['Columns to map', 'amber'], 'mapped' => ['Ready to preview', 'amber'], 'previewed' => ['Previewed, not imported', 'blue'],
        'completed' => ['Imported', 'green'], 'failed' => ['Failed', 'red'],
    ];
@endphp
<x-importer::page :context="$context" :to="$to" step="upload">
    <section class="bg-white border border-zinc-200 rounded-md">
        <div class="px-6 pt-6">
            <h2 class="text-xl font-bold tracking-tight text-zinc-900">Upload a spreadsheet</h2>
            <p class="mt-1.5 text-sm leading-relaxed text-zinc-600">
                A CSV or Excel (.xlsx) file whose first row is the headings. Columns are matched to fields by their headings (and by what's in them);
                you'll be asked about anything that can't be matched, and you'll see what every row will do before anything is saved.
            </p>
        </div>
        <form method="POST" action="{{ $to('store') }}" enctype="multipart/form-data" class="p-6 space-y-5"
              x-data="{ name: '' }">
            @csrf
            <label class="flex flex-col items-center justify-center gap-2 px-6 py-10 border-2 border-dashed border-zinc-300 rounded-md cursor-pointer hover:border-bt_primary-400 hover:bg-bt_primary-50/40 transition">
                <i class="fa-solid fa-file-arrow-up text-3xl text-zinc-400"></i>
                <span class="text-sm font-semibold text-zinc-800" x-text="name || 'Choose a file'">Choose a file</span>
                <span class="text-xs text-zinc-500">.csv or .xlsx · up to {{ number_format(config('importer.max_rows')) }} rows</span>
                <input type="file" name="file" accept=".csv,.tsv,.txt,.xlsx" required class="sr-only"
                       @change="name = $event.target.files[0]?.name ?? ''">
            </label>

            <fieldset>
                <legend class="text-sm font-medium text-zinc-700">The rows are</legend>
                <div class="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <label class="flex items-start gap-3 p-3 border border-zinc-200 rounded-md cursor-pointer has-[:checked]:border-bt_primary-500 has-[:checked]:bg-bt_primary-50">
                        <input type="radio" name="target" value="" checked class="mt-0.5 text-bt_primary-600 focus:ring-bt_primary-500">
                        <span>
                            <span class="block text-sm font-semibold text-zinc-900">Work it out</span>
                            <span class="block text-xs text-zinc-500">From the headings — you can change it next</span>
                        </span>
                    </label>
                    @foreach ($targets as $key => $target)
                        <label class="flex items-start gap-3 p-3 border border-zinc-200 rounded-md cursor-pointer has-[:checked]:border-bt_primary-500 has-[:checked]:bg-bt_primary-50">
                            <input type="radio" name="target" value="{{ $key }}" @checked(old('target') === $key) class="mt-0.5 text-bt_primary-600 focus:ring-bt_primary-500">
                            <span>
                                <span class="block text-sm font-semibold text-zinc-900">{{ $target->label() }}</span>
                                <span class="block text-xs text-zinc-500">{{ $target->description() }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="flex justify-end">
                <button type="submit" class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-sm bg-bt_primary-600 text-white hover:bg-bt_primary-700 focus:outline-none focus:ring-2 focus:ring-bt_primary-500 focus:ring-offset-2">
                    Continue <i class="fa-solid fa-arrow-right ms-2"></i>
                </button>
            </div>
        </form>
    </section>

    @if ($runs->isNotEmpty())
        <section class="bg-white border border-zinc-200 rounded-md">
            <h2 class="px-6 pt-6 pb-4 text-xl font-bold tracking-tight text-zinc-900">Recent imports</h2>
            <ul class="divide-y divide-zinc-200 border-t border-zinc-200">
                @foreach ($runs as $run)
                    @php [$status, $color] = $statuses[$run->status] ?? [$run->status, 'gray']; @endphp
                    <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-3">
                        <div class="min-w-0">
                            <a href="{{ $to('show', $run) }}" class="text-sm font-semibold text-bt_primary-600 hover:text-bt_primary-800">{{ $run->filename }}</a>
                            <p class="text-xs text-zinc-500">
                                {{ ($targets[$run->target] ?? null)?->label() ?? ucfirst((string) $run->target) }} · {{ number_format($run->row_count) }} {{ Str::plural('row', $run->row_count) }}
                                · {{ $run->created_at->diffForHumans() }}
                                @if ($run->counts)
                                    · @foreach ($run->counts as $action => $n){{ $n }} {{ Str::lower(\UniqueWorkbench\Importer\RowOutcome::ACTIONS[$action] ?? $action) }}@if (! $loop->last), @endif @endforeach
                                @endif
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <x-pill :color="$color">{{ $status }}</x-pill>
                            <form method="POST" action="{{ $to('destroy', $run) }}" onsubmit="return confirm('Delete this import\'s file and results? What it imported stays.')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-zinc-400 hover:text-bt_primary-600" title="Delete" aria-label="Delete {{ $run->filename }}"><i class="fa-solid fa-trash-can"></i></button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-importer::page>
