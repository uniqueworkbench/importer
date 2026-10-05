<?php

return [
    // Where uploads and their parsed rows/results are kept
    'disk' => env('IMPORTER_DISK', 'local'),
    'directory' => 'imports',

    // Largest upload (kilobytes) and most data rows in one import
    'max_kilobytes' => 10240,
    'max_rows' => 5000,

    // The page layout component the wizard renders in (<x-app-layout> in Unique Workbench apps)
    'layout' => 'app-layout',

    // Emails that stand for "no email" in exported data: left blank, with a note (regexes)
    'placeholder_emails' => [
        '/^(no|none|noemail|na|n\/a|unknown)@/i',
        '/@(example|test)\.(com|org|net)$/i',
    ],

    // An email or phone on at least this many rows is flagged for a decision (shared mailbox, office line…)
    'shared_value_rows' => 3,

    // Finished imports (and their files) are kept this many days, then pruned (importer:prune)
    'keep_days' => 90,
];
