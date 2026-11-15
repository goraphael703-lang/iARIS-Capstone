<?php

namespace App\Support;

/**
 * Which school levels a user may see. Stored in user_level_assignments (one row per level / sub-level),
 * kept in step with the user's role and assigned_level / assigned_sub_level by User::syncLevelAssignments().
 *
 *   level      is, college, graduate_school, law, ipace, eteeap
 *   sub_level  Integrated School only: grade_school (Preschool to Grade 6), jhs, shs. NULL = every sub-level of that level.
 *
 * No rows = not tied to a level (admins, IATO Staff, the Executive Office, LAMP, TOIC).
 * The Integrated School Principal gets all three sub-levels; an Assistant Principal gets one.
 */
class LevelAssignments
{
    public const IS_SUB_LEVELS = ['grade_school', 'jhs', 'shs'];

    // The Integrated School sub-level -> the labels the records pages use
    public const IS_LABELS = [
        'grade_school' => ['Preschool', 'Grade School'],
        'jhs' => ['Junior High'],
        'shs' => ['Senior High'],
    ];

    /**
     * The assignments a role with this level and sub-level should have: [[level, sub_level|null], ...].
     * ('kindergarten' is the old name for Preschool and is held under grade_school.)
     */
    public static function rowsFor(string $role, ?string $level, ?string $subLevel): array
    {
        if ($level === null || $level === '') {
            return [];
        }
        if ($level !== 'is') {
            return [[$level, null]];
        }
        $sub = $subLevel === 'kindergarten' ? 'grade_school' : $subLevel;
        if (in_array($sub, self::IS_SUB_LEVELS, true)) {
            return [['is', $sub]];
        }
        // The Principal has all three; an IS Registrar or anyone else on the level without a sub-level has the whole level
        return $role === 'principal'
            ? array_map(fn ($s) => ['is', $s], self::IS_SUB_LEVELS)
            : [['is', null]];
    }
}
