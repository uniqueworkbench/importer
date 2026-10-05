<?php

namespace UniqueWorkbench\Importer;

/**
 * Whose import this is, given by the app for each request (bound as
 * ResolvesImportContext): e.g. "the current organization, as this user".
 */
interface ImportContext
{
    /** The imports' owner, stored on each run ("organization:12"): runs and saved mappings are only seen under it */
    public function scope(): string;

    /** Shown at the top of the wizard ("Import into Acme Pools") */
    public function title(): string;

    /** What the person may import here, fresh instances: key => target. None = 403. */
    public function targets(): array;

    public function userId(): ?int;

    /** Breadcrumbs before "Import" ([['label' => …, 'url' => …]]) */
    public function breadcrumbs(): array;

    /** After a real (not preview) run, e.g. to tell other systems what changed */
    public function completed(ImportTarget $target, array $results): void;
}
