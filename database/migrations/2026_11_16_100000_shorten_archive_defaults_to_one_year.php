<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DLSL's actual policy, stated at the panel defense: applicant records are not retained past
 * one school year. The Archiving feature's defaults (3 years enrolled, 2 years not enrolled)
 * didn't match that claim - this corrects them to 1 year for both, so a fresh install's
 * out-of-the-box behavior is what was actually presented. Still configurable either way from
 * the Archiving page; this only changes the starting values. delete_after_years is untouched -
 * permanent deletion stays a separate, later, deliberate action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('archive_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('enrolled_after_years')->default(1)->change();
            $table->unsignedTinyInteger('not_enrolled_after_years')->default(1)->change();
        });

        DB::table('archive_settings')->update([
            'enrolled_after_years' => 1,
            'not_enrolled_after_years' => 1,
        ]);
    }

    public function down(): void
    {
        Schema::table('archive_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('enrolled_after_years')->default(3)->change();
            $table->unsignedTinyInteger('not_enrolled_after_years')->default(2)->change();
        });

        DB::table('archive_settings')->update([
            'enrolled_after_years' => 3,
            'not_enrolled_after_years' => 2,
        ]);
    }
};
