<?php

namespace Tests\Feature;

use App\Models\ReviewQueueItem;
use App\Models\RolePermission;
use Tests\OfficeTestCase;

class ApprovalPolicyTest extends OfficeTestCase
{
    private function staff(): \App\Models\User
    {
        RolePermission::updateOrCreate(['role' => 'iato_staff', 'module' => 'college'], ['enabled' => true, 'can_edit' => true]);

        return $this->office('iato_staff');
    }

    private function entry(array $overrides = []): array
    {
        return array_replace([
            'scope' => 'college', 'last' => 'Dela Cruz', 'first' => 'Juan', 'mi' => 'A', 'gender' => 'Male',
            'group' => 'CITE', 'program' => 'BS Computer Science', 'status' => 'Pending',
            'applied' => '2026-01-01', 'exam_result' => 'not_taken',
        ], $overrides);
    }

    public function test_a_minor_spelling_fix_auto_applies_without_review(): void
    {
        $applicant = $this->applicant(['level' => 'college', 'college' => 'CITE', 'program_or_track' => 'BS Computer Science', 'first_name' => 'Juan', 'last_name' => 'Dela Curz', 'middle_initial' => 'A', 'admission_test_status' => 'not_taken']);
        $this->actingAs($this->staff())
            ->putJson('/applicants/'.$applicant->reference_number, $this->entry())
            ->assertOk()->assertJsonMissingPath('review');
        $this->assertSame('Dela Cruz', $applicant->fresh()->last_name);
        $this->assertSame(0, ReviewQueueItem::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'edit', 'title' => 'Record Edited']);
    }

    public function test_a_contact_number_update_auto_applies_without_review(): void
    {
        $applicant = $this->applicant(['level' => 'college', 'college' => 'CITE', 'program_or_track' => 'BS Computer Science', 'last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_initial' => 'A', 'contact_number' => '09170000000', 'admission_test_status' => 'not_taken']);
        $this->actingAs($this->staff())
            ->putJson('/applicants/'.$applicant->reference_number, $this->entry(['contact' => '09171234567']))
            ->assertOk()->assertJsonMissingPath('review');
        $this->assertSame('+639171234567', $applicant->fresh()->contact_number);
        $this->assertSame(0, ReviewQueueItem::count());
    }

    public function test_a_full_name_rewrite_still_needs_manual_review(): void
    {
        $applicant = $this->applicant(['level' => 'college', 'program_or_track' => 'BS Computer Science', 'last_name' => 'Santos', 'first_name' => 'Maria', 'admission_test_status' => 'not_taken']);
        $this->actingAs($this->staff())
            ->putJson('/applicants/'.$applicant->reference_number, $this->entry())
            ->assertOk()->assertJsonPath('review', true);
        $this->assertSame('Santos', $applicant->fresh()->last_name);
        $item = ReviewQueueItem::sole();
        $this->assertStringContainsString('Last Name', $item->approval_rule);
    }

    public function test_mixing_a_minor_fix_with_an_unrelated_field_still_needs_review(): void
    {
        $applicant = $this->applicant(['level' => 'college', 'program_or_track' => 'BS Information Technology', 'last_name' => 'Dela Curz', 'admission_test_status' => 'not_taken']);
        $this->actingAs($this->staff())
            ->putJson('/applicants/'.$applicant->reference_number, $this->entry(['program' => 'BS Computer Science']))
            ->assertOk()->assertJsonPath('review', true);
    }
}
