<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ImportBatch;
use App\Support\AdmissionLevelClassifier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\OfficeTestCase;

class ImportAndIpaceTest extends OfficeTestCase
{
    private function row(int $n, array $overrides = []): array
    {
        $row = array_fill(0, 30, '');
        foreach ([0 => $n, 1 => 'STRESS-'.$n, 2 => '2025-06-01', 3 => 'Applicant'.$n.', Test', 10 => 'Female', 11 => 'Test Program', 13 => '1st Year', 15 => 'Yes', 16 => 'Yes', 17 => 'Yes', 19 => 'Passed', 21 => 'Completed', 27 => 'Online'] as $key => $value) {
            $row[$key] = $value;
        }

        return array_replace($row, $overrides);
    }

    private function uploadRows(array $rows, string $year = '2025-2026'): void
    {
        $path = tempnam(sys_get_temp_dir(), 'iaris-import-');
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray($rows, null, 'A1');
        (new Xlsx($sheet))->save($path);
        $sheet->disconnectWorksheets();
        unset($sheet);
        gc_collect_cycles();
        try {
            $this->post('/import', ['file' => new UploadedFile($path, 'applicants.xlsx', null, null, true), 'school_year' => $year])->assertRedirect()->assertSessionHasNoErrors();
        } finally {
            @unlink($path);
        }
    }

    public function test_four_thousand_xlsx_records_import_and_reimport_without_duplicates(): void
    {
        $this->actingAs($this->office('admin'));
        $headers = ['IPACE', 'ETEEAP', 'COLLEGE OF LAW', 'GRADUATE SCHOOL', 'COLLEGE OF INFORMATION TECHNOLOGY', 'JUNIOR HIGH'];
        $rows = [['#', 'APP. NO.', 'APP. DATE', 'FULL NAME']];
        for ($n = 1; $n <= 4000; $n++) {
            $rows[] = [$headers[($n - 1) % 6]];
            $rows[] = $this->row($n);
        }
        $this->uploadRows($rows);
        $this->assertSame(4000, Applicant::count());
        $this->assertSame(8000, DB::table('payments')->count());
        $batch = ImportBatch::latest('id')->first();
        $this->assertSame(0, $batch->error_count);
        // Panel revision #7's performance target: 4,000 applicant records within 30 seconds.
        $this->assertLessThan(30000, $batch->processing_ms, "Import of 4,000 records took {$batch->processing_ms}ms, over the 30s target.");
        $this->assertSame(['college', 'eteeap', 'graduate_school', 'ipace', 'is', 'law'], Applicant::distinct()->orderBy('level')->pluck('level')->sort()->values()->all());
        $this->uploadRows($rows);
        $this->assertSame(4000, Applicant::count());
        $this->assertSame(8000, DB::table('payments')->count());
    }

    public function test_bad_file_rolls_back_all_rows_and_marks_batch_failed(): void
    {
        $this->actingAs($this->office('admin'));
        $path = tempnam(sys_get_temp_dir(), 'iaris-fail-');
        $stream = fopen($path, 'w');
        fputcsv($stream, ['IPACE'], escape: '');
        for ($n = 1; $n <= 510; $n++) {
            fputcsv($stream, $this->row($n), escape: '');
        }
        fputcsv($stream, $this->row(511, [27 => str_repeat('x', 300)]), escape: '');
        fclose($stream);
        try {
            $this->post('/import', ['file' => new UploadedFile($path, 'invalid.csv', null, null, true), 'school_year' => '2025-2026'])->assertSessionHasErrors('file');
            $this->assertSame(0, Applicant::count());
            $this->assertSame(0, DB::table('payments')->count());
            $this->assertSame('failed', ImportBatch::latest('id')->first()->status);
        } finally {
            @unlink($path);
        }
    }

    public function test_ipace_year_specific_charts_and_profiles_use_actual_non_archived_records(): void
    {
        $director = $this->office('director');
        $this->applicant(['school_year' => '2023-2024', 'application_status' => 'completed']);
        $this->applicant(['school_year' => '2025-2026', 'application_status' => 'completed']);
        $this->applicant(['school_year' => '2025-2026', 'archived_at' => now()]);
        $this->actingAs($director)->get('/director/analytics')->assertOk()->assertViewHas('programs', function ($programs) {
            $p = collect($programs)->firstWhere('name', 'Test Program');

            return $p['applicants'] === [0, 1, 0, 1] && $p['enrolled'] === [0, 1, 0, 1];
        })->assertViewHas('years', ['AY 2022–23', 'AY 2023–24', 'AY 2024–25', 'AY 2025–26']);
        $this->get('/director')->assertOk()->assertViewHas('profiles', fn ($profiles) => $profiles['iPACE'][1][1][1][1][1] === 1);
    }

    public function test_reimport_preserves_other_school_years_and_row_errors_are_reported(): void
    {
        $this->actingAs($this->office('admin'));
        $this->uploadRows([['IPACE'], $this->row(1)], '2024-2025');
        $this->uploadRows([['IPACE'], $this->row(1), $this->row(2, [10 => 'Unknown'])]);
        $this->assertSame(2, Applicant::count());
        $this->assertSame(1, ImportBatch::latest('id')->first()->error_count);
        $this->assertSame('ipace', AdmissionLevelClassifier::classify('iPACE', '')['level']);
        $this->assertSame('eteeap', AdmissionLevelClassifier::classify('iPACE / ETEEAP', '')['level']);
    }

    // Panel revision #7: "all predefined validation errors detected before approval - test a
    // documented set of valid and invalid records." TC-05 (ApplicantsImport::model()) defines
    // exactly three: a missing APP. NO., a blank/unrecognized SEX, and a College-level row
    // with no section header to classify it under. Each is exercised here on its own, with
    // the rest of that row otherwise valid, and checked against its exact reported reason -
    // not just that *an* error was counted, which the row-errors test above already covers.
    public function test_all_three_predefined_validation_errors_are_detected_and_reported(): void
    {
        $this->actingAs($this->office('admin'));

        // 1. Missing APP. NO. (column 1)
        $this->uploadRows([['IPACE'], $this->row(1, [1 => ''])]);
        $batch = ImportBatch::latest('id')->first();
        $this->assertSame(1, $batch->error_count);
        $this->assertStringContainsString('Missing APP. NO.', $batch->errors[0]['reason']);
        $this->assertSame(0, Applicant::count());

        // 2. Blank/unrecognized SEX (column 10)
        $this->uploadRows([['IPACE'], $this->row(2, [10 => ''])]);
        $batch = ImportBatch::latest('id')->first();
        $this->assertSame(1, $batch->error_count);
        $this->assertStringContainsString('SEX is blank or not recognized', $batch->errors[0]['reason']);
        $this->assertSame(0, Applicant::count());

        // 3. A College-level row with no section header above it at all (the default
        // classification is 'college', and there's been no header to say which one).
        $this->uploadRows([$this->row(3)]);
        $batch = ImportBatch::latest('id')->first();
        $this->assertSame(1, $batch->error_count);
        $this->assertStringContainsString('No section header', $batch->errors[0]['reason']);
        $this->assertSame(0, Applicant::count());

        // A row with none of these problems is unaffected - confirms the checks above are
        // actually targeted at the broken field, not rejecting the row for some other reason.
        $this->uploadRows([['IPACE'], $this->row(4)]);
        $this->assertSame(0, ImportBatch::latest('id')->first()->error_count);
        $this->assertSame(1, Applicant::count());
    }
}
