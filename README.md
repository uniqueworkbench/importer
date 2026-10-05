# uniqueworkbench/importer

Import wizard for Unique Workbench apps: upload a CSV or Excel sheet, match its columns to fields (asking
about the ones it can't), map values (existing options, create new, ignore), preview every row with nothing
saved, then import — and import the next file from the same system with the same choices. Apps supply the
record types (`ImportTarget`) and whose import it is (`ImportContext`). See `CLAUDE.md` for how it works.

## Install

During development, as a path repository next to the app:

```json
"repositories": [{ "type": "path", "url": "../packages/importer", "options": { "symlink": true } }],
"require": { "uniqueworkbench/importer": "@dev" }
```

For deployed apps, publish the package to its own GitHub repository (like `uniqueworkbench/shared-ui`) and
use a `vcs` repository instead — a path repository doesn't exist on the deploy runner.

XLSX files need PHP's `zip` extension; CSV works without it.
