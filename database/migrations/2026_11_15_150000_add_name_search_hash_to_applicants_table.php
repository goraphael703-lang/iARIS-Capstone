<?php

use App\Models\Applicant;
use App\Support\NameSearchIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Panel revision #3: a keyed index for exact name matching without decrypting every row.
// See App\Support\NameSearchIndex for what this does and does not support.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->char('name_search_hash', 64)->nullable()->after('last_name')->index();
        });

        // Backfill: has to go through the model (decrypts first_name/last_name via their
        // casts) - can't be done as a single SQL statement.
        Applicant::whereNull('name_search_hash')->orderBy('id')->chunkById(500, function ($applicants) {
            foreach ($applicants as $applicant) {
                $applicant->name_search_hash = NameSearchIndex::hash($applicant->first_name, $applicant->last_name);
                $applicant->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropColumn('name_search_hash');
        });
    }
};
