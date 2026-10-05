{{--
    Columns: what each column is. Matched columns start on their field (with how they were matched);
    the rest must be answered — a field, or Don't import. A column can feed more than one field (+ Also).
--}}
@php
    $groups = collect($target->fields())->groupBy('group');
    $hows = [
        'heading' => ['Matched by heading', 'green'], 'similar' => ['Similar heading', 'green'], 'data' => ['Guessed from the data', 'amber'],
        'saved' => ['As last time', 'blue'], 'yours' => ['Your choice', 'blue'],
    ];
    $old = old('columns');
    $initial = [];
    foreach ($headers as $i => $heading) {
        $fields = $old !== null ? array_values((array) ($old[$i] ?? [''])) : ($mapping->columns[$i] ?? ($answered ? [$skip] : ['']));
        $initial[$i] = $fields ?: [$skip];
    }
    $hasExternalId = collect($target->fields())->contains('type', \UniqueWorkbench\Importer\Field::EXTERNAL_ID);
    $unanswered = collect($initial)->filter(fn ($f) => $f === [''])->count();
@endphp
<x-importer::page :context="$context" :to="$to" :run="$run" step="columns">
    <section class="bg-white border border-zinc-200 rounded-md">
        <form method="GET" action="{{ $to('columns', $run) }}" class="flex flex-wrap items-center gap-3 px-6 py-4 border-b border-zinc-200">
            <label for="target-picker" class="text-sm font-medium text-zinc-700">Import these rows as</label>
            <select id="target-picker" name="target" onchange="this.form.submit()"
                    class="text-sm border-zinc-300 rounded-md focus:border-bt_primary-500 focus:ring-bt_primary-500">
                @foreach ($targets as $key => $option)
                    <option value="{{ $key }}" @selected($key === $targetKey)>{{ $option->label() }}</option>
                @endforeach
            </select>
            <span class="text-sm text-zinc-500">{{ $target->description() }}</span>
            <noscript><button type="submit" class="text-sm underline">Change</button></noscript>
        </form>

        <form method="POST" action="{{ $to('save-columns', $run) }}">
            @csrf
            <input type="hidden" name="target" value="{{ $targetKey }}">

            <div class="px-6 py-4 border-b border-zinc-200 bg-zinc-50 text-sm text-zinc-700">
                @if ($unanswered)
                    <i class="fa-solid fa-circle-question me-1.5 text-amber-500"></i>
                    <strong>{{ $unanswered }} {{ Str::plural('column', $unanswered) }}</strong> couldn't be matched — choose what {{ $unanswered === 1 ? 'it is' : 'they are' }}, or <em>Don't import</em>.
                @else
                    <i class="fa-solid fa-circle-check me-1.5 text-emerald-600"></i>
                    Every column is matched. Check them, then continue.
                @endif
            </div>

            <div class="hidden md:grid grid-cols-12 gap-4 px-6 py-2 text-xs font-semibold uppercase tracking-wide text-zinc-500 border-b border-zinc-200">
                <div class="col-span-3">Column in your file</div>
                <div class="col-span-4">Sample values</div>
                <div class="col-span-5">Import as</div>
            </div>

            <ul class="divide-y divide-zinc-200">
                @foreach ($headers as $i => $heading)
                    @php [$howLabel, $howColor] = $hows[$how[$i] ?? ''] ?? [null, null]; @endphp
                    <li class="grid md:grid-cols-12 gap-x-4 gap-y-2 px-6 py-3"
                        x-data="{ fields: @js($initial[$i]) }"
                        :class="fields.length === 1 && fields[0] === '' ? 'bg-amber-50/60' : ''">
                        <div class="md:col-span-3 min-w-0">
                            <p class="text-sm font-semibold text-zinc-900 break-words">{{ $heading }}</p>
                            @if ($howLabel)
                                <x-pill :color="$howColor" class="mt-1">{{ $howLabel }}</x-pill>
                            @endif
                        </div>
                        <div class="md:col-span-4 min-w-0 text-sm text-zinc-600">
                            @forelse ($samples[$i] as $sample)
                                <span class="inline-block max-w-full truncate align-bottom me-1 mb-1 px-1.5 py-0.5 bg-zinc-100 rounded text-xs font-mono" title="{{ $sample }}">{{ $sample }}</span>
                            @empty
                                <span class="text-xs italic text-zinc-400">Empty column</span>
                            @endforelse
                        </div>
                        <div class="md:col-span-5 space-y-2">
                            <template x-for="(field, n) in fields" :key="n">
                                <div class="flex items-center gap-2">
                                    <select :name="'columns[{{ $i }}][]'" x-model="fields[n]" aria-label="Import {{ $heading }} as"
                                            :class="fields[n] === '' ? 'border-amber-400' : 'border-zinc-300'"
                                            class="flex-1 min-w-0 text-sm rounded-md focus:border-bt_primary-500 focus:ring-bt_primary-500">
                                        <option value="">— Choose —</option>
                                        <option value="{{ $skip }}">Don't import</option>
                                        @foreach ($groups as $group => $fields)
                                            <optgroup label="{{ $group }}">
                                                @foreach ($fields as $field)
                                                    <option value="{{ $field->key }}">{{ $field->label }}{{ $field->required ? ' *' : '' }}</option>
                                                @endforeach
                                            </optgroup>
                                        @endforeach
                                    </select>
                                    <button type="button" x-show="fields.length > 1" @click="fields.splice(n, 1)"
                                            class="text-zinc-400 hover:text-bt_primary-600" title="Remove"><i class="fa-solid fa-xmark"></i></button>
                                </div>
                            </template>
                            <button type="button" x-show="fields[fields.length - 1] !== '' && fields[0] !== '{{ $skip }}'" @click="fields.push('')"
                                    class="text-xs font-semibold text-bt_primary-600 hover:text-bt_primary-800">
                                <i class="fa-solid fa-plus me-1"></i>Also use as…
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>

            @if ($hasExternalId)
                <div class="px-6 py-4 border-t border-zinc-200 bg-zinc-50">
                    <label for="source" class="block text-sm font-medium text-zinc-700">Which system are the IDs from?</label>
                    <input id="source" name="source" type="text" maxlength="50" value="{{ old('source', $mapping->options['source'] ?? '') }}"
                           placeholder="e.g. TimeTracker" class="mt-1 w-full max-w-sm text-sm border-zinc-300 rounded-md focus:border-bt_primary-500 focus:ring-bt_primary-500">
                    <p class="mt-1 text-xs text-zinc-500">Only needed when a column is imported as an ID. Records keep the ID, so importing from that system again updates them instead of adding them twice.</p>
                </div>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 border-t border-zinc-200">
                <a href="{{ $to('index') }}" class="text-sm text-zinc-600 hover:text-zinc-900"><i class="fa-solid fa-arrow-left me-1.5"></i>Start over</a>
                <button type="submit" class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-sm bg-bt_primary-600 text-white hover:bg-bt_primary-700 focus:outline-none focus:ring-2 focus:ring-bt_primary-500 focus:ring-offset-2">
                    Continue <i class="fa-solid fa-arrow-right ms-2"></i>
                </button>
            </div>
        </form>
    </section>

    @php $help = collect($target->fields())->filter(fn ($f) => $f->help); @endphp
    @if ($help->isNotEmpty())
        <details class="bg-white border border-zinc-200 rounded-md">
            <summary class="px-6 py-4 cursor-pointer text-sm font-semibold text-zinc-800">About the fields</summary>
            <dl class="px-6 pb-5 grid sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                @foreach ($help as $field)
                    <div>
                        <dt class="font-semibold text-zinc-900">{{ $field->label }}</dt>
                        <dd class="text-zinc-600">{{ $field->help }}</dd>
                    </div>
                @endforeach
            </dl>
        </details>
    @endif
</x-importer::page>
