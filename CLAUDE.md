# Importer Package (`uniqueworkbench/importer`)

A spreadsheet/CSV import wizard for Unique Workbench apps. The package does the generic part — reading the
file, matching headings to fields, asking about what it can't match, mapping values, previewing every row and
running the import. Each app supplies **what rows can become** (`ImportTarget` subclasses) and **whose import
it is** (`ImportContext`). The account app is the first user (`account/app/Imports/`).

## The wizard

Upload → **Columns** → **Values** → **Preview** → Import → Results (`Http\ImportController`, views `importer::*`).

- **Upload** (CSV/TSV/TXT or XLSX; `Reading\SheetReader`): the first non-blank row is the headings (blank ones
  become "Column N", repeats get " (2)"); blank rows are dropped. CSV is read as UTF-8 (BOM or not, UTF-16
  with a BOM) or, failing that, Windows-1252; the delimiter is whichever of `, ; tab |` the first line uses
  most. XLSX (`XlsxReader`, no dependency — needs PHP's **zip** extension): the first sheet, shared/inline
  strings, numbers as written, date-formatted cells as `Y-m-d`. The parsed sheet is stored once as
  `<upload>.json` on the importer disk; the target is guessed from the headings (`Wizard::guessTarget`).
- **Columns**: each column starts on a field when `Mapping\HeaderMatcher` can tell — 1. the heading is one of
  the field's names (label, key, aliases; compared without case, spaces or punctuation), 2. a near spelling or a
  heading containing one ("Primary Email Address"), 3. the column's data looks like emails/phones/dates/ZIPs and
  exactly one unused field of that type fits. **Unmatched columns must be answered** (a field, or Don't
  import) before continuing. A column can feed several fields (+ Also use as…), and a `multiple` field several
  columns. Fields of type `external_id` ask which system the IDs come from (`options.source`).
- **Values**: every value of a `choice` column maps to one of the target's options (`choiceOptions`), **Create
  new** (`Mapping::NEW`, when the field `canCreate`, optionally *inside* a parent — `parentOptions`) or
  **Ignore/Leave blank** (`Mapping::IGNORE`). Each starts on the option labelled the same (ignoring case and
  punctuation; for "A › B" paths, the last part), else `suggestChoice()`, else Create new / Ignore. Values of
  other fields that the target flags in `review()` (e.g. an email on many rows) are asked about too, with
  `reviewOptions()`. Skipped when there's nothing to ask.
- **Preview**: the import is **run for real inside a transaction that's rolled back** (`Wizard::run(commit:
  false)`), so it shows exactly what will happen — rows that create something a later row uses, duplicates
  within the file, conflicts with existing data. Each row runs in its own savepoint, so a failing row is
  reported (`RowOutcome::ERROR`) and the others go on. Results are stored in `<upload>.preview.json`.
- **Import**: the same run, committed; results in `<upload>.results.json`, downloadable as the original file
  plus Result / Record / Notes columns. The mapping (columns by heading, value choices, options) is saved per
  scope + target + heading set (`SavedMapping`), so the next file from the same system maps itself ("As last
  time"); a saved "Create new" becomes the existing option once it exists.

Runs (`ImportRun`, `importer_runs`) belong to a **scope** string from the context (e.g. `organization:12`):
other scopes get 404. A finished run can't be changed (409); upload the file again to re-import.
`php artisan importer:prune` deletes runs (and files) older than `keep_days`.

## Using it in an app

1. Require it (path repository in development; see the README) — its migration and views load automatically.
2. Bind `ResolvesImportContext` to a class returning your `ImportContext` per request: `scope()`, `title()`,
   `targets()` (fresh instances, keyed; none → 403), `userId()`, `breadcrumbs()`, and `completed()` (after a
   real run — notify other systems, audit log).
3. Register the routes inside your own group (prefix, name ending in `.`, middleware):
   `Route::prefix('organization/import')->name('organization.import.')->middleware(...)->group(fn () => Importer::routes());`
   The same routes can be registered more than once (e.g. an admin copy with an `{organization}` parameter —
   not route-model-bound, since the package's controller doesn't type-hint it; resolve it in your context).
   Links stay within the copy in use. Route names use dashes (`save-columns`), never dots, after the prefix.
4. Add `./vendor/uniqueworkbench/importer/resources/views/**/*.blade.php` to Tailwind's `content`.
5. Optionally publish the config (`importer-config`): disk, row/size limits, the layout component
   (`app-layout`), placeholder-email patterns, how many rows make a value "shared" (`shared_value_rows`).

## Writing a target (`ImportTarget`)

- `fields()`: `new Field(key, label, type, aliases, required, multiple, group, help, canCreate)`. Types decide
  cleaning (`Normalizer`, each returning `[value, problem]`): `text` (trimmed, single-spaced, trailing commas
  and "�"/mojibake fixed), `email` (lower-cased; invalid or placeholder → blank + note), `phone` (10 digits, a
  leading 1 dropped), `date` (ISO, US, Excel day numbers → `Y-m-d`), `zip` (5 or ZIP+4, a lost leading zero
  restored; anything else → blank + note), `state` (2 letters, from names too), `person_name` ("Last, First"
  or "First Last" → `[first, last]`), `list` (split on `,;|`), `choice`, `external_id`.
- `import(Row $row): RowOutcome` — read values with `$row->get()` (cleaned; problems become row notes),
  `text()`, `choice()`/`choices()` (`['choice', 'parent', 'value']`), `review()`, `option()`; add purely
  informational notes with `$row->note()` (they don't make a row "updated"). Return `RowOutcome::created /
  updated(…, changed: bool) / linked / skipped / error`. **Only touch the database** (no mail, HTTP or files —
  the preview is rolled back), and keep per-run caches on the instance: it's made fresh for each pass.
- Optional: `mappingErrors()`, `choiceOptions()`, `parentOptions()`, `suggestChoice()`, `review()` +
  `reviewOptions()`, `begin()`, `finish()` (run-level notes like "Created 3 personas").

## Depends on

Laravel 12 and the app's layout component; the views use shared-ui's `<x-breadcrumbs>` and `<x-pill>` and the
Unique Workbench Tailwind palette (`bt_primary`, `zinc`), and Alpine.js (column "Also use as", value parents).
