<?php

namespace UniqueWorkbench\Importer;

use RuntimeException;

/** Something the person importing can fix (a file we can't read, a row we can't import), shown as is */
class ImportException extends RuntimeException
{
}
