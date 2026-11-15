<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Panel revision #1: "allow one account to have access to multiple school levels instead of
// storing only one level per user." assigned_sub_level stays as the single-value column (an
// Assistant Principal's "primary" level, used wherever a page can only show one at a time -
// see CollegeController::college()), but an Assistant Principal can now be given more than
// one via this JSON column; User::syncLevelAssignments() creates a user_level_assignments
// row for each one.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('assigned_sub_levels')->nullable()->after('assigned_sub_level');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('assigned_sub_levels');
        });
    }
};
