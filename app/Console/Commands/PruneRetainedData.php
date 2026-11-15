<?php

namespace App\Console\Commands;

use App\Models\GeneratedReport;
use App\Models\ReviewQueueItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

// Panel revision #2: temporary import copies and sample/preview data don't get kept forever,
// and saved exports/backups have a retention rule too. See config/data-retention.php for the
// windows. Safe to run on any schedule (daily is enough) - every step only ever deletes/nulls
// data that has already served its purpose and is past its window.
class PruneRetainedData extends Command
{
    protected $signature = 'retention:prune';

    protected $description = 'Delete or clear data past its configured retention window (import files, Review Queue previews, saved reports)';

    public function handle(): int
    {
        $this->pruneImportFiles();
        $this->pruneReviewQueuePreviews();
        $this->pruneGeneratedReports();

        return self::SUCCESS;
    }

    // processed/ and failed/ only - never incoming/ or processing/, which hold files not yet
    // (or still being) imported.
    private function pruneImportFiles(): void
    {
        $days = config('data-retention.import_files_days');
        $cutoff = now()->subDays($days)->getTimestamp();
        $root = config('admissions-import.path');
        $deleted = 0;
        foreach (['processed', 'failed'] as $folder) {
            $path = $root.'/'.$folder;
            if (! File::isDirectory($path)) {
                continue;
            }
            foreach (File::files($path) as $file) {
                if ($file->getMTime() < $cutoff) {
                    File::delete($file->getPathname());
                    $deleted++;
                }
            }
        }
        $this->info("Import files pruned: {$deleted} (older than {$days} days).");
    }

    private function pruneReviewQueuePreviews(): void
    {
        $days = config('data-retention.import_preview_days');
        $query = ReviewQueueItem::where('type', 'import')
            ->where('status', '!=', 'waiting')
            ->where('reviewed_at', '<=', now()->subDays($days))
            ->where(fn ($q) => $q->whereNotNull('import_sample')->orWhereNotNull('import_errors'));
        $count = $query->count();
        $query->update(['import_sample' => null, 'import_errors' => null]);
        $this->info("Review Queue import previews cleared: {$count} (resolved more than {$days} days ago).");
    }

    private function pruneGeneratedReports(): void
    {
        $days = config('data-retention.generated_reports_days');
        $query = GeneratedReport::where('created_at', '<=', now()->subDays($days));
        $count = $query->count();
        $query->delete();
        $this->info("Generated reports deleted: {$count} (older than {$days} days).");
    }
}
