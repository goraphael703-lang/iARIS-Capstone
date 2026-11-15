<?php

namespace App\Services;

use App\Imports\ApplicantsImport;
use App\Models\Applicant;
use App\Models\AuditLog;
use App\Models\CachedInsight;
use App\Models\ImportBatch;
use App\Models\ReviewQueueItem;
use App\Models\User;
use App\Support\ApprovalPolicy;
use App\Support\ImportSchoolYear;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class AdmissionsImportService
{
    /** Shared by the upload controller and the local folder command. */
    public function import(UploadedFile $file, string $schoolYear, User $user, ?string $sourceHash = null): array
    {
        if (! preg_match('/^\d{4}-\d{4}$/', $schoolYear) || (int) substr($schoolYear, 5) !== (int) substr($schoolYear, 0, 4) + 1) {
            throw ValidationException::withMessages(['school_year' => 'Enter consecutive years, for example 2025-2026.']);
        }
        try {
            $declaredYear = ImportSchoolYear::declaredYear($file);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => 'Unable to read the workbook. Check that it is a valid Excel file.']);
        }
        if ($declaredYear && $declaredYear !== $schoolYear) {
            throw ValidationException::withMessages(['school_year' => "This file declares SY {$declaredYear}, but the form selects {$schoolYear}. Set School Year to {$declaredYear} before importing."]);
        }

        if ($declaredYear) {
            foreach (ImportSchoolYear::warnings() as $warning) {
                if ($warning['file'] === $file->getClientOriginalName() && $warning['declared'] === $declaredYear) {
                    throw ValidationException::withMessages(['file' => "Batch #{$warning['batch']} of this file already has active records assigned to {$warning['assigned']}. Review that batch before importing this file again."]);
                }
            }
        }

        $batch = ImportBatch::create([
            'uploaded_by' => $user->id,
            'original_filename' => $file->getClientOriginalName(),
            'status' => 'processing',
            'source_hash' => $sourceHash,
        ]);

        try {
            return DB::transaction(function () use ($file, $schoolYear, $user, $batch) {
                $batch->refresh();
                $isStaff = $user->role === 'iato_staff' && ! $user->isAdmin();

                // A staff import always previews first now (nothing committed yet) so
                // ApprovalPolicy can see the real error count before deciding - an admin's
                // own import still commits directly as it always has.
                $import = new ApplicantsImport($batch, $schoolYear);
                $import->preview = $isStaff;
                Excel::import($import, $file);

                if ($isStaff) {
                    $decision = ApprovalPolicy::forImportBatch(count($import->errors));
                    if (! $decision['auto']) {
                        return $this->stageImportForReview($user, $batch, $import, $schoolYear, $decision['rule']);
                    }

                    $rowCount = $this->commitPreviewRows($import->previewRows, $schoolYear, $batch);
                    $batch->update(['status' => 'completed', 'row_count' => $rowCount, 'error_count' => 0, 'errors' => null]);
                    CachedInsight::where('key', 'analytics')->delete();
                    AuditLog::record(
                        'import',
                        'Import Data',
                        'Data Import',
                        "Imported **{$batch->original_filename}** — **{$rowCount} records** added ({$decision['rule']})",
                    );

                    return ['batch' => $batch, 'review' => null];
                }

                $rowCount = Applicant::where('imported_batch_id', $batch->id)->count();
                $errorCount = count($import->errors);

                $batch->update([
                    'status' => 'completed',
                    'row_count' => $rowCount,
                    'error_count' => $errorCount,
                    'errors' => $import->errors ?: null,
                ]);

                CachedInsight::where('key', 'analytics')->delete();

                AuditLog::record(
                    'import',
                    'Import Data',
                    'Data Import',
                    "Imported **{$batch->original_filename}** — **{$rowCount} records** added"
                        .($errorCount ? ", **{$errorCount} row".($errorCount === 1 ? '' : 's').' skipped**' : ''),
                );

                return ['batch' => $batch, 'review' => null];
            }, 3);
        } catch (\Throwable $exception) {
            report($exception);
            $batch->update(['status' => 'failed', 'source_hash' => null]);
            throw ValidationException::withMessages(['file' => 'The import failed. No applicant or payment changes were saved. Check the file format and column lengths.']);
        }
    }

    /**
     * IATO Staff (not an admin): nothing is saved yet. The validated, classified rows and the
     * batch's id travel inside the Review Queue item itself (pending_action), replayed through
     * ApplicantsImport::commitRow() by ReviewQueueController::approve() once a Super Admin signs off.
     */
    protected function stageImportForReview(User $user, ImportBatch $batch, ApplicantsImport $import, string $schoolYear, ?string $approvalRule = null): array
    {
        $new = count(array_filter($import->previewRows, fn ($r) => $r['is_new']));
        $total = count($import->previewRows);

        $item = ReviewQueueItem::create([
            'rq_number' => ReviewQueueItem::nextRqNumber(),
            'type' => 'import',
            'title' => 'Batch Import Requested',
            'subject' => $batch->original_filename,
            'ref' => $total ? 'Mixed levels' : '—',
            'unit' => 'Import Data',
            'submitted_by' => $user->id,
            'submitted_by_name' => $user->name,
            'submitted_by_role' => $user->roleInfo()[0],
            'submitted_at' => now(),
            'status' => 'waiting',
            'import_summary' => ['total' => $total + count($import->errors), 'new' => $new, 'updated' => $total - $new, 'errors' => count($import->errors)],
            'import_sample' => collect($import->previewRows)->take(4)->map(fn ($r) => [$r['name'], $r['program'], $r['is_new'] ? 'New' : 'Updated'])->all(),
            'import_errors' => collect($import->errors)->map(fn ($e) => [$e['row'], $e['name'], '—', '—', $e['reason']])->all(),
            'approval_rule' => $approvalRule,
            'pending_action' => ['action' => 'commit_import', 'payload' => ['batch_id' => $batch->id, 'school_year' => $schoolYear, 'rows' => $import->previewRows]],
        ]);

        AuditLog::record(
            'import',
            'Import Data',
            'Batch Import Requested',
            "{$user->name} submitted **{$batch->original_filename}** ({$total} record".($total === 1 ? '' : 's').") — sent to Review Queue as **{$item->rq_number}**",
        );

        return ['batch' => $batch, 'review' => ['rq_number' => $item->rq_number, 'count' => $total]];
    }

    /**
     * Replays already-validated preview rows (collected with ApplicantsImport::$preview =
     * true, never written to the database yet) through ApplicantsImport::commitRow(). Shared
     * by an auto-approved staff import here and an admin-approved staged one
     * (ReviewQueueController::commitImport()), so both commit identically.
     */
    public function commitPreviewRows(array $rows, string $schoolYear, ImportBatch $batch): int
    {
        foreach ($rows as $row) {
            ApplicantsImport::commitRow(
                $row['fields'],
                $row['applied_at'] ? Carbon::parse($row['applied_at']) : null,
                $row['fees'],
                $batch->id,
                $schoolYear,
                $row['fields']['level'],
            );
        }

        return count($rows);
    }
}
