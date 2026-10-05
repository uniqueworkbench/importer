{{--
    The rows of a preview or an import: counts by action (each a filter), the run's notes, and each row's
    result, record and notes. Shows up to 500 rows; the download has them all.
    @include('importer::partials.rows', ['results' => …, 'show' => all|<action>|notes, 'route' => preview|show])
--}}
@php
    use UniqueWorkbench\Importer\RowOutcome;
    $limit = 500;
    $colors = [
        RowOutcome::CREATED => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
        RowOutcome::UPDATED => 'bg-sky-50 text-sky-700 ring-sky-100',
        RowOutcome::LINKED => 'bg-violet-50 text-violet-700 ring-violet-100',
        RowOutcome::UNCHANGED => 'bg-zinc-100 text-zinc-600 ring-zinc-200',
        RowOutcome::SKIPPED => 'bg-amber-50 text-amber-700 ring-amber-100',
        RowOutcome::ERROR => 'bg-bt_primary-50 text-bt_primary-700 ring-bt_primary-100',
    ];
    $rows = collect($results['rows'] ?? []);
    $withNotes = $rows->filter(fn ($r) => $r['notes'] && ! in_array($r['action'], [RowOutcome::ERROR, RowOutcome::SKIPPED], true))->count();
    $shown = match ($show) {
        'all' => $rows,
        'notes' => $rows->filter(fn ($r) => $r['notes']),
        default => $rows->where('action', $show),
    };
    $filters = ['all' => ['All rows', $rows->count()]];
    foreach (RowOutcome::ACTIONS as $action => $label) {
        if ($results['counts'][$action] ?? 0) {
            $filters[$action] = [$label, $results['counts'][$action]];
        }
    }
    if ($withNotes) {
        $filters['notes'] = ['With notes', $rows->filter(fn ($r) => $r['notes'])->count()];
    }
@endphp

<section class="bg-white border border-zinc-200 rounded-md">
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-px bg-zinc-200 border-b border-zinc-200 rounded-t-md overflow-hidden">
        @foreach (RowOutcome::ACTIONS as $action => $label)
            <div class="bg-white px-4 py-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $label }}</p>
                <p @class(['mt-1 text-2xl font-bold tabular-nums',
                    'text-bt_primary-600' => $action === RowOutcome::ERROR && ($results['counts'][$action] ?? 0),
                    'text-zinc-900' => ! ($action === RowOutcome::ERROR && ($results['counts'][$action] ?? 0))])>
                    {{ number_format($results['counts'][$action] ?? 0) }}
                </p>
            </div>
        @endforeach
    </div>

    @if (! empty($results['notes']))
        <ul class="px-6 py-3 border-b border-zinc-200 bg-zinc-50 text-sm text-zinc-700 space-y-1">
            @foreach ($results['notes'] as $note)
                <li><i class="fa-solid fa-circle-info me-1.5 text-zinc-400"></i>{{ $note }}</li>
            @endforeach
        </ul>
    @endif

    <nav class="flex flex-wrap gap-1 px-6 py-3 border-b border-zinc-200" aria-label="Show">
        @foreach ($filters as $key => [$label, $n])
            <a href="{{ $to($route, $run, $key === 'all' ? [] : ['show' => $key]) }}"
               class="px-3 py-1 rounded-full text-sm {{ $show === $key ? 'bg-bt_primary-600 text-white' : 'bg-white border border-zinc-300 text-zinc-700 hover:bg-zinc-100' }}">
                {{ $label }} <span class="opacity-75">{{ number_format($n) }}</span>
            </a>
        @endforeach
    </nav>

    @if ($shown->isEmpty())
        <p class="p-6 text-sm text-zinc-500">No rows.</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-6 py-2 w-16">Row</th>
                        <th class="px-3 py-2 w-28">Result</th>
                        <th class="px-3 py-2">Record</th>
                        <th class="px-6 py-2">Notes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200">
                    @foreach ($shown->take($limit) as $row)
                        <tr class="align-top">
                            <td class="px-6 py-2.5 tabular-nums text-zinc-500">{{ $row['row'] }}</td>
                            <td class="px-3 py-2.5">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold ring-1 ring-inset {{ $colors[$row['action']] ?? '' }}">
                                    {{ RowOutcome::ACTIONS[$row['action']] ?? $row['action'] }}
                                </span>
                            </td>
                            <td class="px-3 py-2.5 font-medium text-zinc-900">
                                @if ($row['url'])
                                    <a href="{{ $row['url'] }}" class="text-bt_primary-600 hover:text-bt_primary-800">{{ $row['record'] }}</a>
                                @else
                                    {{ $row['record'] }}
                                @endif
                            </td>
                            <td class="px-6 py-2.5 text-zinc-600">
                                @if ($row['notes'])
                                    <ul class="space-y-0.5">
                                        @foreach ($row['notes'] as $note)
                                            <li>{{ $note }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($shown->count() > $limit)
            <p class="px-6 py-3 border-t border-zinc-200 text-sm text-zinc-500">
                Showing the first {{ number_format($limit) }} of {{ number_format($shown->count()) }} rows — download the file for all of them.
            </p>
        @endif
    @endif
</section>
