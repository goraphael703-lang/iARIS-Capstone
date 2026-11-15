<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_settings', function (Blueprint $table) {
            $table->id();
            // How many years past the end of an applicant's school year before the record is
            // eligible for archiving - a different window for enrolled vs. non-enrolled, since
            // an enrolled applicant's record stays active (handed to the Registrar) longer.
            $table->unsignedTinyInteger('enrolled_after_years')->default(3);
            $table->unsignedTinyInteger('not_enrolled_after_years')->default(2);
            // How many years after a record is archived before it is eligible for permanent
            // deletion - a second, separate window (panel revision #6: permanent deletion is a
            // separate action from archiving, not immediate).
            $table->unsignedTinyInteger('delete_after_years')->default(2);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('archive_settings')->insert([
            'enrolled_after_years' => 3, 'not_enrolled_after_years' => 2, 'delete_after_years' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_settings');
    }
};
