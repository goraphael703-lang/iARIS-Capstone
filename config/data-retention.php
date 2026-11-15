<?php

// Panel revision #2: "Import previews and samples" and "Exports and backups" both need a
// defined retention period, not to be kept forever. These are separate from any state the
// app still needs for its own logic (e.g. a *waiting* Review Queue item never gets pruned,
// only a resolved one past its window) - see App\Console\Commands\PruneRetainedData.
return [
    // Files in the folder importer's processed/ and failed/ subfolders (see
    // config/admissions-import.php) - already imported or already reported, kept only long
    // enough to investigate an issue.
    'import_files_days' => (int) env('RETENTION_IMPORT_FILES_DAYS', 30),

    // review_queue.import_sample / import_errors: once an import item is no longer waiting
    // (approved or sent back), the preview data that let a reviewer see it has served its
    // purpose.
    'import_preview_days' => (int) env('RETENTION_IMPORT_PREVIEW_DAYS', 14),

    // generated_reports: a saved export/report snapshot. Kept longer than an import preview
    // since these are "institutional record" documents.
    'generated_reports_days' => (int) env('RETENTION_GENERATED_REPORTS_DAYS', 365),
];
