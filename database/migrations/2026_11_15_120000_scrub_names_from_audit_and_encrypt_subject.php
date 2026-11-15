<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Panel revision #2: audit log entries should record the record ID, not bake in an
// applicant's name (the free-text details/description did). Adds audit_logs.applicant_id
// so the Audit Logs page can resolve the (encrypted) name live from the applicants table
// for an authorized viewer, instead of storing a permanent plain-text copy of it.
//
// review_queue.subject held an applicant's name in plain varchar(255) - same protection as
// changes/pending_action/etc. (see 2026_11_15_110000): encrypted, and widened to TEXT first
// since ciphertext for a name can run longer than 255 bytes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('applicant_id')->nullable()->after('user_id')->index();
        });

        DB::statement('ALTER TABLE review_queue MODIFY COLUMN `subject` TEXT NULL');

        DB::table('review_queue')->select(['id', 'subject'])->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                if ($row->subject === null || $row->subject === '' || $this->looksEncrypted($row->subject)) {
                    continue;
                }
                DB::table('review_queue')->where('id', $row->id)->update(['subject' => Crypt::encryptString($row->subject)]);
            }
        });
    }

    public function down(): void
    {
        DB::table('review_queue')->select(['id', 'subject'])->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                if ($row->subject === null || ! $this->looksEncrypted($row->subject)) {
                    continue;
                }
                try {
                    DB::table('review_queue')->where('id', $row->id)->update(['subject' => Crypt::decryptString($row->subject)]);
                } catch (\Throwable) {
                }
            }
        });
        DB::statement('ALTER TABLE review_queue MODIFY COLUMN `subject` VARCHAR(255) NULL');

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('applicant_id');
        });
    }

    // Plain text (old, unencrypted subject) is never base64-only; Crypt::encryptString()'s
    // output always is.
    private function looksEncrypted(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9+\/=]+$/', trim($value));
    }
};
