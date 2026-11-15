<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\LevelAssignments;
use Tests\OfficeTestCase;

class LevelAssignmentsTest extends OfficeTestCase
{
    private function assigned(string $role, ?string $level, ?string $subLevel = null): User
    {
        $user = $this->office($role);
        $user->forceFill(['assigned_level' => $level, 'assigned_sub_level' => $subLevel, 'access_type' => 'viewer'])->save();

        return $user->refresh();
    }

    private function rows(User $user): array
    {
        return $user->levelAssignments()->orderBy('level')->orderBy('sub_level')->get(['level', 'sub_level'])
            ->map(fn ($a) => [$a->level, $a->sub_level])->all();
    }

    public function test_rows_for_each_kind_of_account(): void
    {
        $this->assertSame([['is', 'grade_school'], ['is', 'jhs'], ['is', 'shs']], LevelAssignments::rowsFor('principal', 'is', null));
        $this->assertSame([['is', 'grade_school']], LevelAssignments::rowsFor('assistant_principal', 'is', 'kindergarten'));
        $this->assertSame([['is', null]], LevelAssignments::rowsFor('registrar', 'is', null));
        $this->assertSame([['college', null]], LevelAssignments::rowsFor('dean', 'college', null));
        $this->assertSame([], LevelAssignments::rowsFor('admin', null, null));
    }

    public function test_accounts_get_their_assignments_and_changes_resync(): void
    {
        $principal = $this->assigned('principal', 'is');
        $this->assertSame([['is', 'grade_school'], ['is', 'jhs'], ['is', 'shs']], $this->rows($principal));

        $assistant = $this->assigned('assistant_principal', 'is', 'grade_school');
        $this->assertSame([['is', 'grade_school']], $this->rows($assistant));
        $assistant->forceFill(['assigned_sub_level' => 'jhs'])->save();
        $this->assertSame([['is', 'jhs']], $this->rows($assistant->refresh()));

        $this->assertSame([], $this->rows($this->office('admin')));
    }

    public function test_single_level_account_cannot_reach_other_levels(): void
    {
        $assistant = $this->assigned('assistant_principal', 'is', 'jhs');
        $this->assertTrue($assistant->canAccessLevel('is'));
        $this->assertFalse($assistant->canAccessLevel('college'));
        $this->assertSame(['jhs'], $assistant->subLevelScope('is'));

        $this->actingAs($assistant);
        foreach (['/records/college', '/records/graduate', '/records/law', '/records/is'] as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    public function test_assistant_principal_only_sees_their_sub_levels_and_principal_sees_all(): void
    {
        $this->applicant(['reference_number' => 'LVL-GS-1', 'level' => 'is', 'sub_level' => 'grade_school', 'year_level' => 'Grade 2', 'program_or_track' => 'Grade School']);
        $this->applicant(['reference_number' => 'LVL-PS-1', 'level' => 'is', 'sub_level' => 'kindergarten', 'year_level' => 'Kinder', 'program_or_track' => 'Kindergarten']);
        $this->applicant(['reference_number' => 'LVL-JHS-1', 'level' => 'is', 'sub_level' => 'jhs', 'year_level' => 'Grade 8', 'program_or_track' => 'Junior High School']);
        $this->applicant(['reference_number' => 'LVL-SHS-1', 'level' => 'is', 'sub_level' => 'shs', 'year_level' => 'Grade 11', 'program_or_track' => 'STEM']);

        $this->actingAs($this->assigned('assistant_principal', 'is', 'jhs'))->get('/principal/applicants')
            ->assertOk()->assertSee('LVL-JHS-1')->assertDontSee('LVL-GS-1')->assertDontSee('LVL-PS-1')->assertDontSee('LVL-SHS-1');
        $this->actingAs($this->assigned('assistant_principal', 'is', 'grade_school'))->get('/principal/applicants')
            ->assertOk()->assertSee('LVL-GS-1')->assertSee('LVL-PS-1')->assertDontSee('LVL-JHS-1')->assertDontSee('LVL-SHS-1');
        $this->actingAs($this->assigned('principal', 'is'))->get('/principal/applicants')
            ->assertOk()->assertSee('LVL-GS-1')->assertSee('LVL-PS-1')->assertSee('LVL-JHS-1')->assertSee('LVL-SHS-1');
    }

    public function test_master_applicants_list_is_only_for_iato(): void
    {
        $this->actingAs($this->office('admin'))->get('/applicants')->assertOk();
        $this->actingAs($this->office('iato_staff'))->get('/applicants')->assertOk();

        $this->actingAs($this->assigned('principal', 'is'))->get('/applicants')->assertRedirect(route('principal.applicants'));
        $this->actingAs($this->assigned('assistant_principal', 'is', 'shs'))->get('/applicants')->assertRedirect(route('principal.applicants'));
        $this->actingAs($this->assigned('dean', 'college'))->get('/applicants')->assertRedirect(route('college.applicants'));
        $this->actingAs($this->assigned('registrar', 'college'))->get('/applicants')->assertRedirect(route('registrar.dashboard'));
    }
}
