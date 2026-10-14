{{--
    The wizard's page: the app's layout (config importer.layout), breadcrumbs, the steps and flash messages.
    <x-importer::page :context="$context" :to="$to" :run="$run" step="columns">…</x-importer::page>
--}}
@props(['context', 'to', 'run' => null, 'step' => 'upload'])
@php
    $steps = ['upload' => 'Upload', 'columns' => 'Columns', 'values' => 'Values', 'preview' => 'Preview', 'done' => 'Done'];
    $current = array_search($step, array_keys($steps), true);
    $crumbs = [...$context->breadcrumbs(), ['label' => 'Import', 'url' => $to('index')]];
    if ($run) {
        $crumbs[] = ['label' => $run->filename];
    } else {
        unset($crumbs[array_key_last($crumbs)]['url']);
    }
@endphp
<x-dynamic-component :component="config('importer.layout')">
    <x-slot name="breadcrumb">
        <x-breadcrumbs :breadcrumbs="$crumbs" />
    </x-slot>

    <div class="py-4">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="text-2xl font-bold tracking-tight text-zinc-900">{{ $context->title() }}</h1>
                    @if ($run)
                        <p class="mt-1 text-sm text-zinc-600">
                            <i class="fa-solid fa-file-csv me-1 text-zinc-400"></i>{{ $run->filename }}
                            · {{ number_format($run->row_count) }} {{ Str::plural('row', $run->row_count) }}
                            · {{ count($run->headers) }} {{ Str::plural('column', count($run->headers)) }}
                        </p>
                    @endif
                </div>
                <ol class="flex flex-wrap items-center gap-1 text-xs font-semibold" aria-label="Steps">
                    @foreach ($steps as $key => $label)
                        @php $i = array_search($key, array_keys($steps), true); @endphp
                        <li class="flex items-center gap-1">
                            <span @class([
                                'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full',
                                'bg-bt_primary-600 text-white' => $i === $current,
                                'bg-emerald-50 text-emerald-700' => $i < $current,
                                'bg-zinc-100 text-zinc-500' => $i > $current,
                            ]) @if ($i === $current) aria-current="step" @endif>
                                @if ($i < $current)<i class="fa-solid fa-check"></i>@else{{ $i + 1 }}@endif
                                {{ $label }}
                            </span>
                            @if (! $loop->last)<i class="fa-solid fa-chevron-right text-[0.6rem] text-zinc-300"></i>@endif
                        </li>
                    @endforeach
                </ol>
            </div>

            @foreach (['success' => 'bg-emerald-50 border-emerald-200 text-emerald-800', 'error' => 'bg-bt_primary-50 border-bt_primary-200 text-bt_primary-800'] as $key => $classes)
                @if (session($key))
                    <div class="p-4 border rounded-md text-sm {{ $classes }}" role="status">{{ session($key) }}</div>
                @endif
            @endforeach

            @if ($errors->any())
                <div class="p-4 border border-bt_primary-200 bg-bt_primary-50 rounded-md text-sm text-bt_primary-800" role="alert">
                    <ul class="space-y-1">
                        @foreach ($errors->all() as $error)
                            <li><i class="fa-solid fa-circle-exclamation me-1.5"></i>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{ $slot }}
        </div>
    </div>
</x-dynamic-component>
