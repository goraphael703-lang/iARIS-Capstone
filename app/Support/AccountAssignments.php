<?php

namespace App\Support;

use App\Http\Controllers\CollegeController;
use App\Models\User;
use Illuminate\Validation\Rule;

class AccountAssignments
{
    public const DEPARTMENTS = [
        'iato_staff' => ['IATO'],
        'dean' => ['CITE', 'CBEAM', 'CEAS', 'CIHTM', 'CON', 'CCJE', 'College of Law'],
        'program_chair' => ['CITE', 'CBEAM', 'CEAS', 'CIHTM', 'CON', 'CCJE', 'Graduate Programs'],
        'principal' => ['Integrated School'],
        'assistant_principal' => ['Integrated School'],
        'registrar' => ['College Registrar', 'IS Registrar'],
        'lamp' => ['Lasallian Mission Office'],
        'president' => ['Office of the President'],
        'chancellor' => ['Office of the Chancellor'],
        'vice_president' => ['Finance', 'Administration', 'Advancement'],
        'vice_chancellor' => ['Academics', 'Research, Innovation & Internationalization', 'Mission'],
        'toic' => ['TOIC'],
        'director' => ['iPACE'],
    ];

    public const SUB_LEVELS = ['grade_school' => 'Grade School', 'jhs' => 'Junior High', 'shs' => 'Senior High'];

    public static function rules(string $role, string $department): array
    {
        $chair = $role === 'program_chair' && $department !== 'Graduate Programs';

        return [
            'department' => ['required', Rule::in(self::DEPARTMENTS[$role] ?? [])],
            'assigned_college' => ['nullable', Rule::in(in_array($role, ['dean', 'program_chair'], true) && $department !== 'Graduate Programs' ? [CollegeCatalog::code($department)] : [])],
            'assigned_program' => $chair
                ? ['required', 'string', 'max:150', Rule::in(CollegeController::accountPrograms()[$department] ?? [])]
                : ['nullable', 'prohibited'],
            // An Assistant Principal can cover more than one school level - sent as an array
            // even for just one, so the form always has a single shape to submit.
            'assigned_sub_level' => $role === 'assistant_principal'
                ? ['required', 'array', 'min:1']
                : ['nullable', 'prohibited'],
            'assigned_sub_level.*' => $role === 'assistant_principal'
                ? [Rule::in(array_keys(self::SUB_LEVELS))]
                : ['prohibited'],
        ];
    }

    public static function attributes(array $data, ?User $existing = null): array
    {
        $role = $data['role'];
        $department = $data['department'];
        $collegeRole = in_array($role, ['dean', 'program_chair'], true) && $department !== 'Graduate Programs';

        return [
            'department' => $department,
            'assigned_college' => $collegeRole ? CollegeCatalog::code($department) : null,
            'assigned_program' => $role === 'program_chair' && $collegeRole ? $data['assigned_program'] : null,
            'assigned_level' => match ($role) {
                'dean' => 'college',
                'program_chair' => $department === 'Graduate Programs' ? 'graduate_school' : 'college',
                'principal', 'assistant_principal' => 'is',
                'registrar' => $department === 'IS Registrar' ? 'is' : 'college',
                'director' => 'ipace',
                default => null,
            },
            // assigned_sub_level stays the single "primary" level (the first one picked) for
            // pages that can only show one at a time; assigned_sub_levels is the full set.
            // Retain an existing SHS principal's scope when editing the same role.
            'assigned_sub_level' => $role === 'assistant_principal' ? $data['assigned_sub_level'][0]
                : ($role === 'principal' && $existing?->role === 'principal' ? $existing->assigned_sub_level : null),
            'assigned_sub_levels' => $role === 'assistant_principal' ? array_values($data['assigned_sub_level']) : null,
            'access_type' => $role === 'iato_staff' ? 'editor' : 'viewer',
        ];
    }
}
