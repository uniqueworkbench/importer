<?php

namespace UniqueWorkbench\Importer;

use Illuminate\Http\Request;

/** Bound by the app: the ImportContext for a request to the wizard (abort when it isn't allowed) */
interface ResolvesImportContext
{
    public function resolve(Request $request): ImportContext;
}
