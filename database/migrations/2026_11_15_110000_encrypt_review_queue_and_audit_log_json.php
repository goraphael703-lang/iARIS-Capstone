<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

// review_queue (changes, pending_action, import_summary, import_sample, import_errors) and
// audit_logs.diff moved from a plain 'array' cast to 'encrypted:array' (see ReviewQueueItem
// and AuditLog models) - those columns can hold an applicant's name, contact number or
// address before/after a change. Existing rows were written as plain JSON, so they need to
// be re-encrypted once here; from this point on the model casts do it automatically on save.
// Uses the query builder throughout (never the Eloquent models) so this runs the same
// whether or not the code already has the new casts applied.
return new class extends Migration
{
    private const REVIEW_QUEUE_COLUMNS = ['changes', 'pending_action', 'import_summary', 'import_sample', 'import_errors'];

    public function up(): void
    {
        // These were declared json() originally. On MariaDB that's really a longtext column
        // with an auto-added CHECK(json_valid(...)) constraint - which an encrypted value
        // (base64 ciphertext, not JSON) fails. On real MySQL 8 it's a native json column,
        // which enforces the same thing at the storage layer. Either way, the column has to
        // become a plain long-text column before it can hold encrypted values.
        foreach (self::REVIEW_QUEUE_COLUMNS as $column) {
            DB::statement("ALTER TABLE review_queue MODIFY COLUMN `{$column}` LONGTEXT NULL");
        }
        DB::statement('ALTER TABLE audit_logs MODIFY COLUMN `diff` LONGTEXT NULL');

        $this->encryptColumns('review_queue', self::REVIEW_QUEUE_COLUMNS);
        $this->encryptColumns('audit_logs', ['diff']);
    }

    public function down(): void
    {
        $this->decryptColumns('review_queue', self::REVIEW_QUEUE_COLUMNS);
        $this->decryptColumns('audit_logs', ['diff']);
    }

    private function encryptColumns(string $table, array $columns): void
    {
        DB::table($table)->select(array_merge(['id'], $columns))->orderBy('id')->chunkById(200, function ($rows) use ($table, $columns) {
            foreach ($rows as $row) {
                $update = [];
                foreach ($columns as $column) {
                    $raw = $row->{$column};
                    if ($raw === null || $raw === '' || ! $this->looksLikePlainJson($raw)) {
                        continue;
                    }
                    $update[$column] = Crypt::encryptString($raw);
                }
                if ($update !== []) {
                    DB::table($table)->where('id', $row->id)->update($update);
                }
            }
        });
    }

    private function decryptColumns(string $table, array $columns): void
    {
        DB::table($table)->select(array_merge(['id'], $columns))->orderBy('id')->chunkById(200, function ($rows) use ($table, $columns) {
            foreach ($rows as $row) {
                $update = [];
                foreach ($columns as $column) {
                    $raw = $row->{$column};
                    if ($raw === null || $raw === '' || $this->looksLikePlainJson($raw)) {
                        continue;
                    }
                    try {
                        $update[$column] = Crypt::decryptString($raw);
                    } catch (\Throwable) {
                        // Not one of ours to decrypt (or already plain) - leave it as is.
                    }
                }
                if ($update !== []) {
                    DB::table($table)->where('id', $row->id)->update($update);
                }
            }
        });
    }

    // Laravel's Crypt::encryptString() output is a base64 string, which never starts with
    // '{', '[', '"' or the literal 'null' the way json_encode() output does.
    private function looksLikePlainJson(string $value): bool
    {
        $value = ltrim($value);

        return $value !== '' && (str_starts_with($value, '{') || str_starts_with($value, '[') || str_starts_with($value, '"') || $value === 'null');
    }
};
