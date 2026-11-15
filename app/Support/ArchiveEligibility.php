<?php

namespace App\Support;

use App\Models\Applicant;
use App\Models\ArchiveSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Panel revision #6: configurable eligibility rules and retention periods for archiving
 * applicant records, and a separate, later-eligible window before permanent deletion.
 *
 * application_status is never encrypted (see Applicant::casts()'s note - it's a plain column
 * so it can still be filtered in SQL), so every query here is a normal WHERE, not a PHP scan.
 */
class ArchiveEligibility
{
    // "2025-2026" -> Aug 1, 2026: the app already treats August 1 as the start of the next
    // academic year (see AuditLogController's "This Academic Year" filter), so that's also
    // when a school year is considered closed for archiving purposes.
    public static function cycleEnd(string $schoolYear): Carbon
    {
        $years = array_map('intval', explode('-', $schoolYear));
        $endYear = $years[1] ?? ($years[0] + 1);

        return Carbon::create($endYear, 8, 1)->startOfDay();
    }

    // Every (non-archived) school year whose cycle ended at least $years ago.
    public static function schoolYearsOlderThan(int $years): array
    {
        $cutoff = now()->subYears($years);

        return Applicant::whereNull('archived_at')->distinct()->pluck('school_year')
            ->filter(fn ($year) => self::cycleEnd($year)->lte($cutoff))
            ->values()->all();
    }

    public static function eligibleToArchive(ArchiveSetting $settings): Builder
    {
        $enrolledYears = self::schoolYearsOlderThan($settings->enrolled_after_years);
        $notEnrolledYears = self::schoolYearsOlderThan($settings->not_enrolled_after_years);

        return Applicant::whereNull('archived_at')->where(function (Builder $q) use ($enrolledYears, $notEnrolledYears) {
            $q->where(fn ($q2) => $q2->whereIn('school_year', $enrolledYears)->where('application_status', 'completed'))
                ->orWhere(fn ($q2) => $q2->whereIn('school_year', $notEnrolledYears)->where('application_status', '!=', 'completed'));
        });
    }

    public static function eligibleToDelete(ArchiveSetting $settings): Builder
    {
        return Applicant::whereNotNull('archived_at')->where('archived_at', '<=', now()->subYears($settings->delete_after_years));
    }

    public static function canDeletePermanently(Applicant $applicant, ArchiveSetting $settings): bool
    {
        return $applicant->archived_at !== null && $applicant->archived_at->lte(now()->subYears($settings->delete_after_years));
    }

    public static function reasonFor(Applicant $applicant, ArchiveSetting $settings): string
    {
        $enrolled = $applicant->application_status === 'completed';
        $years = $enrolled ? $settings->enrolled_after_years : $settings->not_enrolled_after_years;

        return ($enrolled ? 'Enrolled' : 'Did not enroll')." — {$applicant->school_year} cycle ended, past the {$years}-year policy";
    }
}
