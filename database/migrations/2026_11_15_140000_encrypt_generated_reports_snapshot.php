<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

// Panel revision #2 "Exports and backups": a saved report's snapshot can hold an applicant's
// name and other details straight from the records it was generated from. Same treatment as
// review_queue/audit_logs/lamp_requests: encrypt it, and convert the column from json() (which
// MariaDB enforces as longtext + CHECK(json_valid(...)) and MySQL 8 enforces natively - both
// reject ciphertext) to plain LONGTEXT first.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE generated_reports MODIFY COLUMN `snapshot` LONGTEXT NOT NULL');

        DB::table('generated_reports')->select(['id', 'snapshot'])->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                if ($row->snapshot === '' || $this->looksEncrypted($row->snapshot)) {
                    continue;
                }
                DB::table('generated_reports')->where('id', $row->id)->update(['snapshot' => Crypt::encryptString($row->snapshot)]);
            }
        });
    }

    public function down(): void
    {
        DB::table('generated_reports')->select(['id', 'snapshot'])->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                if (! $this->looksEncrypted($row->snapshot)) {
                    continue;
                }
                try {
                    DB::table('generated_reports')->where('id', $row->id)->update(['snapshot' => Crypt::decryptString($row->snapshot)]);
                } catch (\Throwable) {
                }
            }
        });
        DB::statement('ALTER TABLE generated_reports MODIFY COLUMN `snapshot` JSON NOT NULL');
    }

    private function looksEncrypted(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9+\/=]+$/', trim($value));
    }
};
