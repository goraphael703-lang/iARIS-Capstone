<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use App\Models\ArchiveSetting;
use App\Models\AuditLog;
use App\Support\ApplicantEditFlow;
use App\Support\ArchiveEligibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $archived = Applicant::whereNotNull('archived_at')->orderByDesc('archived_at')->paginate(15)->withQueryString();

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
            'archived' => $archived->through(fn ($a) => [
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
