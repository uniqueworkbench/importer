{{--
    Values: for each choice column, what every value in it means (an existing option, Create new — optionally
    inside another — or Ignore); for other columns, the values the target flagged (a shared office email…).
--}}
@php
    use UniqueWorkbench\Importer\Mapping\Mapping;
    use UniqueWorkbench\Importer\Wizard;
@endphp
<x-importer::page :context="$context" :to="$to" :run="$run" step="values">
    <form method="POST" action="{{ $to('save-values', $run) }}" class="space-y-6">
        @csrf
        @foreach ($sections as $section)
            @php
                $field = $section['field'];
                $flat = Wizard::flatOptions($section['options']);
                $new = count(array_filter($section['values'], fn ($v) => $v['choice'] === Mapping::NEW));
            @endphp
            <section class="bg-white border border-zinc-200 rounded-md">
                <div class="px-6 pt-6 pb-4">
                    <h2 class="text-xl font-bold tracking-tight text-zinc-900">
                        @if ($section['review'])
                            <i class="fa-solid fa-flag me-1.5 text-amber-500"></i>Check these {{ Str::plural(Str::lower($field->label)) }}
                        @else
                            {{ $field->label }}
                        @endif
                    </h2>
                    <p class="mt-1.5 text-sm text-zinc-600">
                        @if ($section['review'])
                            These look like they aren't really this record's own. Say what to do with each.
                        @else
                            What each value in the file means.
                            @if ($field->canCreate && $new)
                                <strong>{{ $new }}</strong> will be created as {{ $new === 1 ? 'a new one' : 'new ones' }}, unless you pick an existing one.
                            @endif
                        @endif
                    </p>
                </div>
                <table class="w-full text-sm">
                    <thead class="border-y border-zinc-200 bg-zinc-50 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                        <tr>
                            <th class="px-6 py-2">In your file</th>
                            <th class="px-3 py-2 w-20 text-right">Rows</th>
                            <th class="px-6 py-2">Means</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200">
                        @foreach (array_values($section['values']) as $i => $value)
                            @php $key = "values.{$field->key}.{$i}"; @endphp
                            <tr x-data="{ choice: @js(old("{$key}.choice", $value['choice'])) }" class="align-top">
                                <td class="px-6 py-3">
                                    @if ($value['value'] === '')
                                        <span class="italic text-zinc-400">(blank)</span>
                                    @else
                                        <span class="font-medium text-zinc-900">{{ $value['value'] }}</span>
                                    @endif
                                    @if ($value['note'])
                                        <p class="mt-0.5 text-xs text-amber-700">{{ $value['note'] }}</p>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right tabular-nums text-zinc-600">{{ number_format($value['rows']) }}</td>
                                <td class="px-6 py-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <select name="values[{{ $field->key }}][{{ $i }}][choice]" x-model="choice" aria-label="{{ $value['value'] ?: '(blank)' }} means"
                                                class="min-w-[14rem] text-sm border-zinc-300 rounded-md focus:border-bt_primary-500 focus:ring-bt_primary-500">
                                            @if (! $section['review'] && $field->canCreate && $value['value'] !== '')
                                                <option value="{{ Mapping::NEW }}" @selected($value['choice'] === Mapping::NEW)>Create new: “{{ $value['value'] }}”</option>
                                            @endif
                                            @foreach ($section['options'] as $option => $label)
                                                @if (is_array($label))
                                                    <optgroup label="{{ $option }}">
                                                        @foreach ($label as $groupOption => $groupLabel)
                                                            <option value="{{ $groupOption }}" @selected((string) $value['choice'] === (string) $groupOption)>{{ $groupLabel }}</option>
                                                        @endforeach
                                                    </optgroup>
                                                @else
                                                    <option value="{{ $option }}" @selected((string) $value['choice'] === (string) $option)>{{ $label }}</option>
                                                @endif
                                            @endforeach
                                            @unless ($section['review'])
                                                <option value="{{ Mapping::IGNORE }}" @selected($value['choice'] === Mapping::IGNORE)>{{ $field->multiple ? 'Ignore' : 'Leave blank' }}</option>
                                            @endunless
                                        </select>
                                        @if ($section['parents'])
                                            <label x-show="choice === '{{ Mapping::NEW }}'" class="flex items-center gap-2 text-xs text-zinc-600">
                                                inside
                                                <select name="values[{{ $field->key }}][{{ $i }}][parent]"
                                                        class="text-sm border-zinc-300 rounded-md focus:border-bt_primary-500 focus:ring-bt_primary-500">
                                                    <option value="">— top level —</option>
                                                    @foreach ($section['parents'] as $parent => $parentLabel)
                                                        <option value="{{ $parent }}" @selected((string) old("{$key}.parent", $value['parent']) === (string) $parent)>{{ $parentLabel }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endforeach

        <div class="flex flex-wrap items-center justify-between gap-3">
            <a href="{{ $to('columns', $run) }}" class="text-sm text-zinc-600 hover:text-zinc-900"><i class="fa-solid fa-arrow-left me-1.5"></i>Back to columns</a>
            <button type="submit" class="inline-flex items-center px-4 py-2 rounded-md font-semibold text-sm bg-bt_primary-600 text-white hover:bg-bt_primary-700 focus:outline-none focus:ring-2 focus:ring-bt_primary-500 focus:ring-offset-2">
                Preview the import <i class="fa-solid fa-arrow-right ms-2"></i>
            </button>
        </div>
    </form>
</x-importer::page>
