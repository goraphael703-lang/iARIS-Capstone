<?php
namespace Tests\Feature;
use App\Models\RolePermission;
use Tests\OfficeTestCase;

class ApSwitcherTest extends OfficeTestCase
{
    public function test_assistant_principal_with_multiple_levels_can_switch_but_not_escape_scope(): void
    {
        $u = $this->office('assistant_principal');
        $u->update(['assigned_level' => 'is', 'assigned_sub_level' => 'grade_school', 'assigned_sub_levels' => ['grade_school', 'jhs']]);
        RolePermission::updateOrCreate(['role' => 'assistant_principal', 'module' => 'dashboard'], ['enabled' => true]);

        $this->actingAs($u)->get('/principal')->assertOk()
            ->assertSee('Grade School')->assertSee('Junior High');
        $this->actingAs($u)->get('/principal?level=jhs')->assertOk()->assertViewHas('college', fn ($c) => $c['code'] === 'IS_JHS');
        $this->actingAs($u)->get('/principal')->assertOk()->assertViewHas('college', fn ($c) => $c['code'] === 'IS_GS');
        // Can't escape to a level they're not assigned to via the query param
        $this->actingAs($u)->get('/principal?level=shs')->assertOk()->assertViewHas('college', fn ($c) => $c['code'] === 'IS_GS');
    }
}
