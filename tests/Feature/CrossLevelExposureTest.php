<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\RolePermission;
use App\Models\User;
use Tests\OfficeTestCase;

/**
 * Panel revision #7: "Zero unauthorized cross-level records exposed - test screens, direct
 * requests, reports and exports." This is a sweep, not exhaustive duplication of the level-
 * isolation coverage LevelAssignmentsTest and OfficeScopeAndReportsTest already have (records
 * pages, legacy route redirects, saved-report ownership) - it specifically targets masking
 * (full contact detail is an editor-only exemption - see ApplicantRecords::shouldMask()) on
 * every screen a viewer role can reach, since that's a distinct kind of exposure from level
 * isolation and wasn't covered anywhere else.
 *
 * office() always creates access_type 'editor' (many other tests need that); a real Dean/
 * Program Chair/LAMP account is 'viewer' by default (see AccountAssignments::attributes()),
 * so every masking test here sets that explicitly to match what the role actually is in
 * production.
 */
class CrossLevelExposureTest extends OfficeTestCase
{
    private function assigned(string $role, ?string $level, ?string $subLevel = null): User
    {
        $user = $this->office($role);
        $user->forceFill(['assigned_level' => $level, 'assigned_sub_level' => $subLevel, 'access_type' => 'viewer'])->save();

        return $user->refresh();
    }

    private function applicantWithContact(array $overrides = []): Applicant
    {
        return $this->applicant(array_replace([
            'email' => 'juan.delacruz@example.test', 'contact_number' => '09171234567',
            'address' => 'Purok 3, Lipa City, Batangas',
        ], $overrides));
    }

    public function test_dean_applicants_page_masks_contact_details(): void
    {
        $this->applicantWithContact(['level' => 'college', 'college' => 'CITE', 'program_or_track' => 'BS Computer Science']);
        $dean = $this->office('dean');
        $dean->update(['assigned_college' => 'CITE', 'access_type' => 'viewer']);
        RolePermission::updateOrCreate(['role' => 'dean', 'module' => 'applicants'], ['enabled' => true]);

        $this->actingAs($dean)->get('/college/applicants')->assertOk()
            ->assertDontSee('juan.delacruz@example.test')
            ->assertDontSee('09171234567')
            ->assertDontSee('Purok 3');
    }

    public function test_program_chair_applicants_page_masks_contact_details(): void
    {
        $this->applicantWithContact(['level' => 'college', 'college' => 'CITE', 'program_or_track' => 'BS Computer Science']);
        $chair = $this->office('program_chair');
        $chair->update(['assigned_college' => 'CITE', 'assigned_program' => 'BS Computer Science', 'access_type' => 'viewer']);
        RolePermission::updateOrCreate(['role' => 'program_chair', 'module' => 'applicants'], ['enabled' => true]);

        $this->actingAs($chair)->get('/college/applicants')->assertOk()
            ->assertDontSee('juan.delacruz@example.test')
            ->assertDontSee('09171234567')
            ->assertDontSee('Purok 3');
    }

    public function test_lamp_scholars_pages_never_show_contact_details(): void
    {
        $this->applicantWithContact(['level' => 'college', 'applying_for_scholarship' => true]);
        $lamp = $this->office('lamp');
        $lamp->update(['access_type' => 'viewer']);
        RolePermission::updateOrCreate(['role' => 'lamp', 'module' => 'scholars'], ['enabled' => true]);

        // LampOffice::applicants() (their own overview) never includes contact fields at all.
        $this->actingAs($lamp)->get('/lamp/scholars')->assertOk()->assertDontSee('juan.delacruz@example.test');
        // ScholarController::index() (the shared records page LAMP and IATO Staff both use)
        // does carry them, masked for LAMP as a non-editor viewer.
        $this->get('/records/scholars')->assertOk()
            ->assertDontSee('juan.delacruz@example.test')
            ->assertDontSee('09171234567')
            ->assertDontSee('Purok 3');
    }

    public function test_iato_staff_scholars_page_is_not_masked(): void
    {
        $this->applicantWithContact(['level' => 'college', 'applying_for_scholarship' => true]);
        $staff = $this->office('iato_staff');
        RolePermission::updateOrCreate(['role' => 'iato_staff', 'module' => 'scholars'], ['enabled' => true]);

        $this->actingAs($staff)->get('/records/scholars')->assertOk()->assertSee('juan.delacruz@example.test');
    }

    public function test_assistant_principal_cannot_see_another_sub_levels_applicants_or_contact_details(): void
    {
        $this->applicantWithContact(['level' => 'is', 'sub_level' => 'shs', 'year_level' => 'Grade 11', 'reference_number' => 'XLVL-SHS-1']);
        $jhsAp = $this->assigned('assistant_principal', 'is', 'jhs');

        $this->actingAs($jhsAp)->get('/principal/applicants')->assertOk()
            ->assertDontSee('XLVL-SHS-1')
            ->assertDontSee('juan.delacruz@example.test');
    }

    public function test_registrar_scope_never_shows_other_scopes_records_and_never_shows_contact_details(): void
    {
        $this->applicantWithContact(['level' => 'college', 'college' => 'CITE', 'program_or_track' => 'BS Computer Science', 'reference_number' => 'XLVL-COL-1']);
        $this->applicantWithContact(['level' => 'is', 'sub_level' => 'shs', 'year_level' => 'Grade 11', 'reference_number' => 'XLVL-IS-1']);

        $collegeRegistrar = $this->office('registrar');
        $collegeRegistrar->update(['assigned_level' => 'college', 'access_type' => 'viewer']);
        $this->actingAs($collegeRegistrar)->get('/records/college')->assertOk()
            ->assertSee('XLVL-COL-1')
            // RegistrarData omits contact fields entirely (stronger than masking) - never shown either way.
            ->assertDontSee('juan.delacruz@example.test')->assertDontSee('09171234567');
        $this->get('/records/is')->assertForbidden();

        $isRegistrar = $this->office('registrar');
        $isRegistrar->update(['assigned_level' => 'is', 'access_type' => 'viewer']);
        $this->actingAs($isRegistrar)->get('/records/is')->assertOk()->assertSee('XLVL-IS-1');
        $this->get('/records/college')->assertForbidden();
    }

    /** A Dean can't widen their own scope by asking for a different college's reference number directly. */
    public function test_dean_cannot_open_another_colleges_applicant_by_reference_number(): void
    {
        $this->applicantWithContact(['level' => 'college', 'college' => 'CEAS', 'program_or_track' => 'BS Psychology', 'reference_number' => 'XLVL-CEAS-1']);
        $dean = $this->office('dean');
        $dean->update(['assigned_college' => 'CITE', 'access_type' => 'viewer']);
        RolePermission::updateOrCreate(['role' => 'dean', 'module' => 'applicants'], ['enabled' => true]);

        $this->actingAs($dean)->get('/college/applicants')->assertOk()->assertDontSee('XLVL-CEAS-1');
    }
}
