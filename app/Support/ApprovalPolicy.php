<?php

namespace App\Support;

/**
 * Panel revision #5: a configurable approval policy instead of every IATO Staff change
 * waiting on a Super Admin, regardless of how small it is. Only ever consulted for IATO
 * Staff (not an admin) - an admin's own edits still always apply immediately, unchanged.
 *
 * What auto-applies, straight from the panel's own table - nothing broader than this list:
 *   - A name field (Last/First Name) changed by a small edit distance: a spelling fix, not
 *     a different identity.
 *   - A Contact No. update (its format is already validated before this runs - that's the
 *     "verified" the panel's table means here; there's no SMS/OTP verification in this app).
 *   - A batch import with zero validation errors (file-level duplicate detection and the
 *     uploader's import permission are already checked before this is reached).
 * Everything else - a new applicant, a deletion or restoration, an exam result change, any
 * other field (email, address, program, status), or an import with any error rows - needs
 * manual Super Admin approval, exactly as today. This is deliberately a short, named list:
 * extending it is a product decision, not something to infer.
 */
class ApprovalPolicy
{
    private const IDENTITY_LABELS = ['Last Name', 'First Name'];

    // A spelling fix is a small edit relative to the name's own length, not a rewrite.
    private const MAX_EDIT_DISTANCE = 2;

    /** @return array{auto: bool, rule: string} */
    public static function forApplicantAction(string $action, array $diff): array
    {
        if ($action === 'create') {
            return ['auto' => false, 'rule' => 'New applicant records always need manual approval.'];
        }
        if (in_array($action, ['destroy', 'restore'], true)) {
            return ['auto' => false, 'rule' => 'Deletion and restoration always need manual approval.'];
        }
        if ($action === 'exam_status') {
            return ['auto' => false, 'rule' => 'Exam result changes always need manual approval.'];
        }
        if ($diff === []) {
            return ['auto' => true, 'rule' => 'No field actually changed.'];
        }

        $reasons = [];
        foreach ($diff as [$label, $before, $after]) {
            if (in_array($label, self::IDENTITY_LABELS, true) && self::isSpellingFix((string) $before, (string) $after)) {
                $reasons[] = "{$label}: minor spelling correction";

                continue;
            }
            if ($label === 'Contact No.') {
                $reasons[] = 'Contact No.: format verified';

                continue;
            }

            return ['auto' => false, 'rule' => "{$label} change needs manual approval."];
        }

        return ['auto' => true, 'rule' => 'Auto-approved: '.implode('; ', $reasons).'.'];
    }

    /** @return array{auto: bool, rule: string} */
    public static function forImportBatch(int $errorCount): array
    {
        return $errorCount === 0
            ? ['auto' => true, 'rule' => 'Auto-approved: every row passed validation, the duplicate-file check, and the import permission check.']
            : ['auto' => false, 'rule' => "{$errorCount} row(s) failed validation - the whole batch needs manual review."];
    }

    private static function isSpellingFix(string $before, string $after): bool
    {
        $before = trim($before);
        $after = trim($after);
        if ($before === '' || $after === '' || $before === $after) {
            return false;
        }
        // levenshtein() is byte-based, not multibyte-safe - fine for this app's Latin-script
        // names, but an accented or non-Latin name could overcount; when in doubt this errs
        // toward "not a spelling fix" (manual review), never the other way.
        $distance = levenshtein(mb_strtolower($before), mb_strtolower($after));
        $longer = max(mb_strlen($before), mb_strlen($after));

        return $distance > 0 && $distance <= self::MAX_EDIT_DISTANCE && $distance <= max(1, (int) ceil($longer * 0.3));
    }
}
