<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use App\Models\ArchiveSetting;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use App\Support\ApplicantEditFlow;
use App\Support\ArchiveEligibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Panel revision #6 - Archiving settings: eligibility rules, retention periods, a preview
 * before archiving, authorized restore, archive/restore/delete logging, and permanent
 * deletion as its own, later-eligible action. Super Admin only (see routes/web.php).
 */
class ArchiveSettingsController extends Controller
{
    public function index()
    {
        $settings = ArchiveSetting::current();

        $eligible = ArchiveEligibility::eligibleToArchive($settings)->orderBy('school_year')->limit(200)->get();
        $eligibleTotal = ArchiveEligibility::eligibleToArchive($settings)->count();

        // Archived records that came from a bulk import are grouped by batch, so a Super Admin
        // acts on all of them at once instead of one at a time (a single import can be
        // hundreds or thousands of rows). Records archived individually (imported_batch_id
        // null - added one by one, not via a file) still list separately below.
        $archivedBatches = Applicant::whereNotNull('archived_at')->whereNotNull('imported_batch_id')
            ->selectRaw('imported_batch_id, count(*) as archived_count, max(archived_at) as last_archived_at')
            ->groupBy('imported_batch_id')
            ->orderByDesc('last_archived_at')
            ->get()
            ->map(function ($row) use ($settings) {
                $batch = ImportBatch::find($row->imported_batch_id);
                $lastArchivedAt = \Illuminate\Support\Carbon::parse($row->last_archived_at);

                return [
                    'batchId' => $row->imported_batch_id,
                    'filename' => $batch->original_filename ?? '(batch record deleted)',
                    'archivedCount' => $row->archived_count,
                    'lastArchivedAt' => $lastArchivedAt->format('M j, Y'),
                    // Conservative: only offer to delete the whole batch once its NEWEST
                    // archived member has also passed retention - never force a mixed batch's
                    // not-yet-eligible rows out early just because most of it qualifies.
                    'deletable' => $lastArchivedAt->lte(now()->subYears($settings->delete_after_years)),
                    'deletableOn' => $lastArchivedAt->copy()->addYears($settings->delete_after_years)->format('M j, Y'),
                ];
            });

        $archivedIndividual = Applicant::whereNotNull('archived_at')->whereNull('imported_batch_id')
            ->orderByDesc('archived_at')->paginate(15)->withQueryString();

        return view('admin.archive', [
            'settings' => $settings,
            'eligible' => $eligible->map(fn ($a) => [
                'ref' => $a->reference_number,
                'name' => "{$a->last_name}, {$a->first_name}",
                'schoolYear' => $a->school_year,
                'module' => ApplicantEditFlow::moduleFor($a->level),
                'reason' => ArchiveEligibility::reasonFor($a, $settings),
            ]),
            'eligibleTotal' => $eligibleTotal,
            'archivedBatches' => $archivedBatches,
            'archivedIndividual' => $archivedIndividual->through(fn ($a) => [
                'ref' => $a->reference_number,
                'name' => "{$a->last_name}, {$a->first_name}",
                'schoolYear' => $a->school_year,
                'module' => ApplicantEditFlow::moduleFor($a->level),
                'archivedAt' => $a->archived_at->format('M j, Y'),
                'deletable' => ArchiveEligibility::canDeletePermanently($a, $settings),
                'deletableOn' => $a->archived_at->copy()->addYears($settings->delete_after_years)->format('M j, Y'),
            ]),
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'enrolled_after_years' => 'required|integer|min:1|max:10',
            'not_enrolled_after_years' => 'required|integer|min:1|max:10',
            'delete_after_years' => 'required|integer|min:1|max:10',
        ]);

        $settings = ArchiveSetting::current();
        $diff = [];
        foreach ($validated as $field => $value) {
            if ((int) $settings->$field !== (int) $value) {
                $diff[] = [ucwords(str_replace('_', ' ', $field)), $settings->$field, $value];
            }
        }
        $settings->update($validated + ['updated_by' => $request->user()->id]);

        if ($diff) {
            AuditLog::record('edit', 'System', 'Archive Policy Updated', 'Updated the archiving eligibility and retention policy.', null,
                array_map(fn ($c) => ['field' => $c[0], 'before' => (string) $c[1], 'after' => (string) $c[2]], $diff));
        }

        return back()->with('success', 'Archive policy updated.');
    }

    public function archiveEligible()
    {
        $settings = ArchiveSetting::current();
        $applicants = ArchiveEligibility::eligibleToArchive($settings)->get();

        foreach ($applicants as $applicant) {
            ApplicantEditFlow::applyDestroy($applicant);
            $window = $applicant->application_status === 'completed' ? $settings->enrolled_after_years : $settings->not_enrolled_after_years;
            AuditLog::record(
                'delete',
                ApplicantEditFlow::moduleFor($applicant->level),
                'Applicant Archived (Policy)',
                "Archived **{$applicant->reference_number}** — past the {$window}-year retention policy.",
                null, null, $applicant->id,
            );
        }

        $count = $applicants->count();

        return back()->with('success', $count ? "Archived {$count} record(s)." : 'Nothing is currently eligible to archive.');
    }

    public function restoreBatch(ImportBatch $batch)
    {
        $applicants = $batch->applicants()->whereNotNull('archived_at')->get();
        $restored = 0;
        $skipped = 0;
        foreach ($applicants as $applicant) {
            try {
                ApplicantEditFlow::applyRestore($applicant);
                $restored++;
            } catch (ValidationException) {
                // A duplicate active record already occupies that applicant's identity -
                // leave it archived rather than failing the whole batch over one row.
                $skipped++;
            }
        }

        if ($restored) {
            AuditLog::record('edit', 'Import Data', 'Applicants Restored (Batch)',
                "Restored {$restored} record(s) from **{$batch->original_filename}**".($skipped ? " ({$skipped} skipped - a duplicate active record already exists)." : '.'));
        }

        return back()->with('success', $restored ? "Restored {$restored} record(s).".($skipped ? " {$skipped} skipped (duplicate active record)." : '') : 'Nothing to restore.');
    }

    public function destroyBatchPermanently(Request $request, ImportBatch $batch)
    {
        $request->validate(['confirm' => 'required|in:DELETE']);

        $settings = ArchiveSetting::current();
        $applicants = $batch->applicants()->whereNotNull('archived_at')->get();

        abort_if($applicants->isEmpty(), 404);

        // All-or-nothing, gated by the NEWEST archived member: never delete most of a batch
        // while quietly leaving its too-recently-archived rows half-deleted around it. This
        // must match the same check index() used to decide whether the button was enabled.
        $newest = $applicants->max('archived_at');
        abort_unless($newest->lte(now()->subYears($settings->delete_after_years)), 403,
            'Not every record in this batch has passed the retention period yet.');

        $ids = $applicants->pluck('id');
        DB::transaction(function () use ($ids) {
            // Same reason as the single-record delete: payments.applicant_id has no
            // nullOnDelete(), so its rows have to go first.
            DB::table('payments')->whereIn('applicant_id', $ids)->delete();
            Applicant::whereIn('id', $ids)->delete();
        });

        $count = $applicants->count();
        AuditLog::record('delete', 'System', 'Applicants Permanently Deleted (Batch)',
            "Permanently deleted {$count} archived record(s) from **{$batch->original_filename}** after the retention period — this cannot be undone.");

        return back()->with('success', "Permanently deleted {$count} record(s).");
    }

    public function destroyPermanently(Request $request, Applicant $applicant)
    {
        abort_unless($applicant->archived_at !== null, 404);

        $settings = ArchiveSetting::current();
        abort_unless(ArchiveEligibility::canDeletePermanently($applicant, $settings), 403,
            'This record has not yet passed the retention period required before permanent deletion.');

        $request->validate(['confirm' => 'required|in:DELETE']);

        $ref = $applicant->reference_number;
        $module = ApplicantEditFlow::moduleFor($applicant->level);

        DB::transaction(function () use ($applicant) {
            // payments.applicant_id has no nullOnDelete() (an applicant always has a real
            // payment trail), so its rows have to go first or the delete below violates the
            // foreign key. review_queue and lamp_* already null themselves out on delete.
            DB::table('payments')->where('applicant_id', $applicant->id)->delete();
            $applicant->delete();
        });

        AuditLog::record('delete', 'System', 'Applicant Permanently Deleted',
            "Permanently deleted archived applicant **{$ref}** ({$module}) after the retention period — this cannot be undone.");

        return back()->with('success', "Permanently deleted {$ref}.");
    }
}
