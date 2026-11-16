<?php

namespace Tests\Feature;

use App\Models\ArchiveSetting;
use App\Models\AuditLog;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\DB;
use Tests\OfficeTestCase;

/**
 * Panel revision #6: configurable archiving eligibility rules and retention periods, a
 * preview before archiving, restore, archive/restore/delete logging, and permanent deletion
 * as its own, later-eligible, admin-only action.
 */
class ArchiveSettingsTest extends OfficeTestCase
{
    private function settings(array $overrides = []): ArchiveSetting
    {
        $settings = ArchiveSetting::current();
        $settings->update($overrides);

        return $settings->refresh();
    }

    public function test_only_admins_can_reach_the_archive_page_or_act_on_it(): void
    {
        $staff = $this->office('iato_staff');

        $this->actingAs($staff)->get('/admin/archive')->assertForbidden();
        $this->actingAs($staff)->put('/admin/archive', ['enrolled_after_years' => 1, 'not_enrolled_after_years' => 1, 'delete_after_years' => 1])->assertForbidden();
        $this->actingAs($staff)->post('/admin/archive/run')->assertForbidden();
    }

    public function test_eligibility_follows_the_configured_policy_per_enrollment_status(): void
    {
        $this->settings(['enrolled_after_years' => 3, 'not_enrolled_after_years' => 2]);

        // Enrolled, cycle ended 4 years ago - past the 3-year window.
        $oldEnrolled = $this->applicant(['school_year' => '2020-2021', 'application_status' => 'completed', 'reference_number' => 'ARC-ENR-OLD']);
        // Enrolled, cycle ended this year - not eligible yet.
        $recentEnrolled = $this->applicant(['school_year' => '2025-2026', 'application_status' => 'completed', 'reference_number' => 'ARC-ENR-NEW']);
        // Never enrolled, cycle ended 3 years ago - past the 2-year window for non-enrolled.
        $oldPending = $this->applicant(['school_year' => '2021-2022', 'application_status' => 'pooling', 'reference_number' => 'ARC-PEND-OLD']);
        // Never enrolled, cycle ended this year - not eligible yet.
        $recentPending = $this->applicant(['school_year' => '2025-2026', 'application_status' => 'pooling', 'reference_number' => 'ARC-PEND-NEW']);

        $admin = $this->office('admin');
        $response = $this->actingAs($admin)->get('/admin/archive')->assertOk();
        $refs = collect($response->viewData('eligible'))->pluck('ref')->all();

        $this->assertContains('ARC-ENR-OLD', $refs);
        $this->assertContains('ARC-PEND-OLD', $refs);
        $this->assertNotContains('ARC-ENR-NEW', $refs);
        $this->assertNotContains('ARC-PEND-NEW', $refs);
    }

    public function test_archiving_eligible_records_sets_archived_at_and_logs_each_one(): void
    {
        $this->settings(['enrolled_after_years' => 1, 'not_enrolled_after_years' => 1]);
        $applicant = $this->applicant(['school_year' => '2020-2021', 'application_status' => 'completed', 'reference_number' => 'ARC-RUN-1']);
        $untouched = $this->applicant(['school_year' => '2025-2026', 'application_status' => 'completed', 'reference_number' => 'ARC-RUN-2']);

        $admin = $this->office('admin');
        $this->actingAs($admin)->post('/admin/archive/run')->assertRedirect();

        $this->assertNotNull($applicant->refresh()->archived_at);
        $this->assertNull($untouched->refresh()->archived_at);
        $this->assertTrue(AuditLog::where('applicant_id', $applicant->id)->where('title', 'Applicant Archived (Policy)')->exists());
    }

    public function test_permanent_deletion_is_blocked_until_the_retention_window_has_passed(): void
    {
        $this->settings(['delete_after_years' => 2]);
        $applicant = $this->applicant(['archived_at' => now()->subMonths(6), 'reference_number' => 'ARC-DEL-EARLY']);

        $admin = $this->office('admin');
        $this->actingAs($admin)->delete('/admin/archive/ARC-DEL-EARLY', ['confirm' => 'DELETE'])->assertForbidden();
        $this->assertDatabaseHas('applicants', ['reference_number' => 'ARC-DEL-EARLY']);
    }

    public function test_permanent_deletion_requires_typing_delete_and_removes_the_record_and_its_payments(): void
    {
        $this->settings(['delete_after_years' => 1]);
        $applicant = $this->applicant(['archived_at' => now()->subYears(2), 'reference_number' => 'ARC-DEL-READY']);
        DB::table('payments')->insert(['applicant_id' => $applicant->id, 'fee_type' => 'application_fee', 'status' => 'paid', 'created_at' => now()]);

        $admin = $this->office('admin');
        // Wrong confirmation text is rejected.
        $this->actingAs($admin)->delete('/admin/archive/ARC-DEL-READY', ['confirm' => 'delete'])->assertSessionHasErrors('confirm');
        $this->assertDatabaseHas('applicants', ['reference_number' => 'ARC-DEL-READY']);

        $this->actingAs($admin)->delete('/admin/archive/ARC-DEL-READY', ['confirm' => 'DELETE'])->assertRedirect();

        $this->assertDatabaseMissing('applicants', ['reference_number' => 'ARC-DEL-READY']);
        $this->assertSame(0, DB::table('payments')->where('applicant_id', $applicant->id)->count());
        $this->assertTrue(AuditLog::where('title', 'Applicant Permanently Deleted')->where('details', 'like', '%ARC-DEL-READY%')->exists());
    }

    public function test_restoring_a_batch_restores_every_archived_member_at_once(): void
    {
        $batch = ImportBatch::create(['uploaded_by' => $this->office('admin')->id, 'original_filename' => 'bulk-import.xlsx', 'status' => 'completed']);
        $a = $this->applicant(['imported_batch_id' => $batch->id, 'archived_at' => now(), 'reference_number' => 'ARC-BATCH-1']);
        $b = $this->applicant(['imported_batch_id' => $batch->id, 'archived_at' => now(), 'reference_number' => 'ARC-BATCH-2']);
        $untouched = $this->applicant(['imported_batch_id' => $batch->id, 'archived_at' => null, 'reference_number' => 'ARC-BATCH-3']);

        $admin = $this->office('admin');
        $this->actingAs($admin)->post("/admin/archive/batch/{$batch->id}/restore")->assertRedirect();

        $this->assertNull($a->refresh()->archived_at);
        $this->assertNull($b->refresh()->archived_at);
        $this->assertNull($untouched->refresh()->archived_at);
        $this->assertTrue(AuditLog::where('title', 'Applicants Restored (Batch)')->where('details', 'like', '%2 record%')->exists());
    }

    public function test_batch_permanent_deletion_is_blocked_until_its_newest_member_passes_retention(): void
    {
        $this->settings(['delete_after_years' => 2]);
        $batch = ImportBatch::create(['uploaded_by' => $this->office('admin')->id, 'original_filename' => 'bulk-import.xlsx', 'status' => 'completed']);
        $this->applicant(['imported_batch_id' => $batch->id, 'archived_at' => now()->subYears(3), 'reference_number' => 'ARC-BATCH-OLD']);
        // One member archived too recently - the whole batch stays blocked, not just that row.
        $this->applicant(['imported_batch_id' => $batch->id, 'archived_at' => now()->subMonths(2), 'reference_number' => 'ARC-BATCH-NEW']);

        $admin = $this->office('admin');
        $this->actingAs($admin)->delete("/admin/archive/batch/{$batch->id}", ['confirm' => 'DELETE'])->assertForbidden();
        $this->assertDatabaseHas('applicants', ['reference_number' => 'ARC-BATCH-OLD']);
        $this->assertDatabaseHas('applicants', ['reference_number' => 'ARC-BATCH-NEW']);
    }

    public function test_batch_permanent_deletion_removes_every_eligible_member_and_their_payments(): void
    {
        $this->settings(['delete_after_years' => 1]);
        $batch = ImportBatch::create(['uploaded_by' => $this->office('admin')->id, 'original_filename' => 'bulk-import.xlsx', 'status' => 'completed']);
        $a = $this->applicant(['imported_batch_id' => $batch->id, 'archived_at' => now()->subYears(2), 'reference_number' => 'ARC-BATCH-DEL-1']);
        $b = $this->applicant(['imported_batch_id' => $batch->id, 'archived_at' => now()->subYears(2), 'reference_number' => 'ARC-BATCH-DEL-2']);
        DB::table('payments')->insert(['applicant_id' => $a->id, 'fee_type' => 'application_fee', 'status' => 'paid', 'created_at' => now()]);

        $admin = $this->office('admin');
        $this->actingAs($admin)->delete("/admin/archive/batch/{$batch->id}", ['confirm' => 'DELETE'])->assertRedirect();

        $this->assertDatabaseMissing('applicants', ['reference_number' => 'ARC-BATCH-DEL-1']);
        $this->assertDatabaseMissing('applicants', ['reference_number' => 'ARC-BATCH-DEL-2']);
        $this->assertSame(0, DB::table('payments')->where('applicant_id', $a->id)->count());
        $this->assertTrue(AuditLog::where('title', 'Applicants Permanently Deleted (Batch)')->where('details', 'like', '%2 archived record%')->exists());
    }

    public function test_updating_the_policy_validates_and_logs_a_diff(): void
    {
        $this->settings(['enrolled_after_years' => 3, 'not_enrolled_after_years' => 2, 'delete_after_years' => 2]);
        $admin = $this->office('admin');

        $this->actingAs($admin)->put('/admin/archive', ['enrolled_after_years' => 0, 'not_enrolled_after_years' => 2, 'delete_after_years' => 2])->assertSessionHasErrors('enrolled_after_years');

        $this->actingAs($admin)->put('/admin/archive', ['enrolled_after_years' => 5, 'not_enrolled_after_years' => 2, 'delete_after_years' => 2])->assertRedirect();

        $this->assertSame(5, ArchiveSetting::current()->enrolled_after_years);
        $this->assertTrue(AuditLog::where('title', 'Archive Policy Updated')->exists());
    }
}
