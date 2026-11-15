<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

// Panel revision #2, third find in the same family as review_queue.subject and
// audit_logs.applicant_id: LampRequest::create() has been storing the applicant's name in
// plain varchar(255) (see LampConversationController::store()). Same fix - encrypt it, widen
// the column first since ciphertext can run longer than 255 bytes.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE lamp_requests MODIFY COLUMN `name` TEXT NOT NULL');

        DB::table('lamp_requests')->select(['id', 'name'])->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                if ($row->name === '' || $this->looksEncrypted($row->name)) {
                    continue;
                }
                DB::table('lamp_requests')->where('id', $row->id)->update(['name' => Crypt::encryptString($row->name)]);
            }
        });
    }

    public function down(): void
    {
        DB::table('lamp_requests')->select(['id', 'name'])->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                if (! $this->looksEncrypted($row->name)) {
                    continue;
                }
                try {
                    DB::table('lamp_requests')->where('id', $row->id)->update(['name' => Crypt::decryptString($row->name)]);
                } catch (\Throwable) {
                }
            }
        });
        DB::statement('ALTER TABLE lamp_requests MODIFY COLUMN `name` VARCHAR(255) NOT NULL');
    }

    private function looksEncrypted(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9+\/=]+$/', trim($value));
    }
};
