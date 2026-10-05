<?php

namespace UniqueWorkbench\Importer\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UniqueWorkbench\Importer\ImportContext;
use UniqueWorkbench\Importer\ImportException;
use UniqueWorkbench\Importer\ImportRun;
use UniqueWorkbench\Importer\ImportTarget;
use UniqueWorkbench\Importer\Mapping\Mapping;
use UniqueWorkbench\Importer\Reading\SheetReader;
use UniqueWorkbench\Importer\ResolvesImportContext;
use UniqueWorkbench\Importer\SavedMapping;
use UniqueWorkbench\Importer\Wizard;

/**
 * The import wizard: Upload → Columns (what each column is; unmatched ones
 * must be answered) → Values (what each value of a choice column means, and
 * flagged values) → Preview (every row, nothing saved) → Import → Results.
 * Routes come from Importer::routes(); who may import what, and into where,
 * from the app's ResolvesImportContext.
 */
class ImportController extends Controller
{
    /** Columns step: "don't import this column" */
    public const SKIP = '__skip__';

    public function __construct(private Wizard $wizard)
    {
    }

    public function index(Request $request)
    {
        $context = $this->context($request);

        return $this->page($request, $context, 'index', [
            'targets' => $this->targets($context),
            'runs' => ImportRun::where('scope', $context->scope())->latest()->limit(15)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $context = $this->context($request);
        $targets = $this->targets($context);

        $request->validate([
            'file' => ['required', 'file', 'max:' . config('importer.max_kilobytes')],
            'target' => ['nullable', 'in:' . implode(',', array_keys($targets))],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, SheetReader::EXTENSIONS, true)) {
            throw ValidationException::withMessages(['file' => 'Upload a CSV or Excel (.xlsx) file.']);
        }

        try {
            $sheet = (new SheetReader)->read($file->getRealPath(), $extension, (int) config('importer.max_rows'));
        } catch (ImportException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $path = $file->storeAs(config('importer.directory'), Str::uuid() . '.' . $extension, config('importer.disk'));
        ImportRun::disk()->put("{$path}.json", json_encode($sheet->toArray()));

        $run = ImportRun::create([
            'scope' => $context->scope(),
            'user_id' => $context->userId(),
            'target' => $request->input('target') ?: $this->wizard->guessTarget($sheet, $targets),
            'filename' => $file->getClientOriginalName(),
            'path' => $path,
            'headers' => $sheet->headers,
            'row_count' => count($sheet->rows),
        ]);

        return redirect()->to($this->url($request, 'columns', $run));
    }

    /** A finished import's results; an unfinished one goes back to where it got to */
    public function show(Request $request)
    {
        $context = $this->context($request);
        $run = $this->findRun($request, $context);

        if (! $run->isFinished()) {
            return redirect()->to($this->url($request, $run->column_map === null ? 'columns' : 'preview', $run));
        }

        return $this->page($request, $context, 'results', [
            'run' => $run,
            'target' => $this->targets($context)[$run->target] ?? null,
            'results' => $run->results('results'),
            'show' => $request->query('show', 'all'),
        ]);
    }

    public function columns(Request $request)
    {
        $context = $this->context($request);
        $run = $this->editableRun($request, $context);
        $targets = $this->targets($context);
        $targetKey = array_key_exists($request->query('target'), $targets) ? $request->query('target')
            : (array_key_exists($run->target, $targets) ? $run->target : array_key_first($targets));
        $target = $targets[$targetKey];

        [$mapping, $how, $answered] = $this->wizard->startingMapping($run, $context->scope(), $targetKey, $target);
        $sheet = $run->sheet();

        return $this->page($request, $context, 'columns', [
            'run' => $run,
            'targets' => $targets,
            'targetKey' => $targetKey,
            'target' => $target,
            'headers' => $sheet->headers,
            'samples' => array_map(fn ($column) => $sheet->samples($column), array_keys($sheet->headers)),
            'mapping' => $mapping,
            'how' => $how,
            'answered' => $answered,
            'skip' => self::SKIP,
        ]);
    }

    public function saveColumns(Request $request): RedirectResponse
    {
        $context = $this->context($request);
        $run = $this->editableRun($request, $context);
        $targets = $this->targets($context);

        $validated = $request->validate([
            'target' => ['required', 'in:' . implode(',', array_keys($targets))],
            'columns' => ['array'],
            'columns.*' => ['array'],
            'columns.*.*' => ['nullable', 'string'],
            'source' => ['nullable', 'string', 'max:50'],
        ]);
        $target = $targets[$validated['target']];
        $sheet = $run->sheet();

        $columns = [];
        $unanswered = [];
        foreach ($sheet->headers as $column => $heading) {
            $chosen = array_values(array_unique(array_filter((array) ($validated['columns'][$column] ?? []), 'strlen')));
            if (! $chosen) {
                $unanswered[] = "“{$heading}”";
            }
            $columns[$column] = array_values(array_diff($chosen, [self::SKIP]));
        }

        // Value choices: this run's own when it already had some for this target, else the ones saved for these headings
        $mapping = new Mapping(
            array_filter($columns),
            $run->target === $validated['target'] && $run->value_map !== null
                ? $run->value_map
                : (SavedMapping::for($context->scope(), $validated['target'], $sheet->headers)?->value_map ?? []),
            array_filter(['source' => trim((string) ($validated['source'] ?? ''))]),
        );

        $errors = $mapping->errors($target, $sheet);
        if ($unanswered) {
            array_unshift($errors, 'Choose what ' . implode(', ', $unanswered) . ' ' . (count($unanswered) === 1 ? 'is' : 'are') . ', or Don\'t import.');
        }
        if ($errors) {
            return back()->withInput()->withErrors(['columns' => $errors]);
        }

        $run->update([
            'target' => $validated['target'],
            'column_map' => $mapping->columns,
            'value_map' => $mapping->values,
            'options' => $mapping->options,
            'status' => ImportRun::MAPPED,
        ]);

        $sections = $this->wizard->valueSections($sheet, $target, $mapping);

        return redirect()->to($this->url($request, $sections ? 'values' : 'preview', $run));
    }

    public function values(Request $request)
    {
        $context = $this->context($request);
        $run = $this->editableRun($request, $context);
        if ($run->column_map === null) {
            return redirect()->to($this->url($request, 'columns', $run));
        }
        $target = $this->target($context, $run);
        $sections = $this->wizard->valueSections($run->sheet(), $target, $run->mapping());

        if (! $sections) {
            return redirect()->to($this->url($request, 'preview', $run));
        }

        return $this->page($request, $context, 'values', [
            'run' => $run,
            'target' => $target,
            'sections' => $sections,
        ]);
    }

    public function saveValues(Request $request): RedirectResponse
    {
        $context = $this->context($request);
        $run = $this->editableRun($request, $context);
        $target = $this->target($context, $run);
        $sections = $this->wizard->valueSections($run->sheet(), $target, $run->mapping());

        $values = $run->value_map ?? [];
        foreach ($sections as $section) {
            $field = $section['field'];
            $allowed = array_map('strval', array_keys(Wizard::flatOptions($section['options'])));
            if (! $section['review']) {
                $allowed[] = Mapping::IGNORE;
                if ($field->canCreate) {
                    $allowed[] = Mapping::NEW;
                }
            }
            $parents = array_map('strval', array_keys($section['parents']));

            $answers = (array) $request->input("values.{$field->key}", []);
            foreach (array_keys($section['values']) as $i => $valueKey) {
                $choice = (string) ($answers[$i]['choice'] ?? '');
                if (! in_array($choice, $allowed, true)) {
                    throw ValidationException::withMessages(['values' => "Choose what each {$field->label} value is."]);
                }
                $parent = $choice === Mapping::NEW && in_array((string) ($answers[$i]['parent'] ?? ''), $parents, true)
                    ? (string) $answers[$i]['parent'] : null;
                $values[$field->key][$valueKey] = ['choice' => $choice, 'parent' => $parent];
            }
        }

        $run->update(['value_map' => $values, 'status' => ImportRun::MAPPED]);

        return redirect()->to($this->url($request, 'preview', $run));
    }

    /** Every row, as the import would do it — worked out (and saved) whenever the mapping changed */
    public function preview(Request $request)
    {
        $context = $this->context($request);
        $run = $this->findRun($request, $context);
        if ($run->isFinished()) {
            return redirect()->to($this->url($request, 'show', $run));
        }
        if ($run->column_map === null) {
            return redirect()->to($this->url($request, 'columns', $run));
        }
        $target = $this->target($context, $run);

        $results = $run->status === ImportRun::PREVIEWED ? $run->results('preview') : null;
        if (! $results) {
            $results = $this->wizard->run($run, $target, commit: false);
            $run->saveResults('preview', $results);
            $run->update(['status' => ImportRun::PREVIEWED, 'previewed_at' => now(), 'counts' => $results['counts']]);
        }

        return $this->page($request, $context, 'preview', [
            'run' => $run,
            'target' => $target,
            'results' => $results,
            'show' => $request->query('show', 'all'),
            'hasValues' => (bool) $this->wizard->valueSections($run->sheet(), $target, $run->mapping()),
        ]);
    }

    /** Work the preview out again (the data it checks against may have changed) */
    public function recheck(Request $request): RedirectResponse
    {
        $run = $this->editableRun($request, $this->context($request));
        $run->update(['status' => ImportRun::MAPPED]);

        return redirect()->to($this->url($request, 'preview', $run));
    }

    public function run(Request $request): RedirectResponse
    {
        $context = $this->context($request);
        $run = $this->editableRun($request, $context);
        if ($run->status !== ImportRun::PREVIEWED) {
            return redirect()->to($this->url($request, 'preview', $run));
        }
        $target = $this->target($context, $run);

        try {
            $results = $this->wizard->run($run, $target, commit: true);
        } catch (Throwable $e) {
            report($e);
            $run->update(['status' => ImportRun::FAILED, 'completed_at' => now()]);

            return redirect()->to($this->url($request, 'show', $run))->with('error', 'The import failed and nothing was saved: ' . $e->getMessage());
        }

        $run->saveResults('results', $results);
        $run->update(['status' => ImportRun::COMPLETED, 'completed_at' => now(), 'counts' => $results['counts']]);
        SavedMapping::remember($context->scope(), $run->target, $run->headers, $run->mapping());
        $context->completed($target, $results);

        return redirect()->to($this->url($request, 'show', $run))->with('success', 'Import finished.');
    }

    /** The file with a result, record and notes column added (the import's, or the preview's before it runs) */
    public function download(Request $request): StreamedResponse
    {
        $run = $this->findRun($request, $this->context($request));
        $results = $run->results() ?? abort(404);
        $sheet = $run->sheet();
        $byIndex = collect($results['rows'])->keyBy('index');
        $name = pathinfo($run->filename, PATHINFO_FILENAME) . ($run->status === ImportRun::COMPLETED ? '-results' : '-preview') . '.csv';

        return response()->streamDownload(function () use ($sheet, $byIndex) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Import result', 'Record', 'Notes', ...$sheet->headers], ',', '"', '');
            foreach ($sheet->rows as $index => $cells) {
                $result = $byIndex->get($index);
                fputcsv($out, [
                    \UniqueWorkbench\Importer\RowOutcome::ACTIONS[$result['action'] ?? ''] ?? '',
                    $result['record'] ?? '',
                    implode(' | ', $result['notes'] ?? []),
                    ...$cells,
                ], ',', '"', '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->findRun($request, $this->context($request))->delete();

        return redirect()->to($this->url($request, 'index'))->with('success', 'Import deleted.');
    }

    private function context(Request $request): ImportContext
    {
        return app(ResolvesImportContext::class)->resolve($request);
    }

    /** @return array<string, ImportTarget> */
    private function targets(ImportContext $context): array
    {
        $targets = $context->targets();
        abort_if(! $targets, 403);

        return $targets;
    }

    private function target(ImportContext $context, ImportRun $run): ImportTarget
    {
        return $this->targets($context)[$run->target] ?? abort(403, 'You can\'t import these here.');
    }

    private function findRun(Request $request, ImportContext $context): ImportRun
    {
        return ImportRun::where('scope', $context->scope())->findOrFail($request->route('run'));
    }

    /** A run that can still change (not imported yet) */
    private function editableRun(Request $request, ImportContext $context): ImportRun
    {
        $run = $this->findRun($request, $context);
        abort_if($run->isFinished(), 409, 'This file has already been imported. Upload it again to import it again.');

        return $run;
    }

    /** A URL within whichever copy of the routes this request is on */
    private function url(Request $request, string $action, ?ImportRun $run = null, array $query = []): string
    {
        $prefix = Str::beforeLast($request->route()->getName(), '.');
        $parameters = Arr::except($request->route()->parameters(), ['run']);
        if ($run) {
            $parameters['run'] = $run->id;
        }

        return route("{$prefix}.{$action}", $parameters + $query);
    }

    private function page(Request $request, ImportContext $context, string $view, array $data)
    {
        return view("importer::{$view}", $data + [
            'context' => $context,
            'to' => fn (string $action, ?ImportRun $run = null, array $query = []) => $this->url($request, $action, $run, $query),
        ]);
    }
}
