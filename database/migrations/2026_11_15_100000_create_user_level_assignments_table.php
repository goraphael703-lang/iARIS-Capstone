<?php

use App\Support\LevelAssignments;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// One account can hold several school levels (the Integrated School Principal: Grade School, Junior High and
// Senior High) instead of the single users.assigned_level / assigned_sub_level pair. The old columns stay and are
// kept in step; existing users are filled in here.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_level_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 40);
            $table->string('level', 20);           // is, college, graduate_school, law, ipace, eteeap
            $table->string('sub_level', 20)->nullable();   // Integrated School only: grade_school, jhs, shs (NULL = every sub-level)
            $table->timestamps();

            $table->unique(['user_id', 'level', 'sub_level']);
            $table->index(['user_id', 'level']);
        });

        foreach (DB::table('users')->whereNotNull('assigned_level')->get(['id', 'role', 'assigned_level', 'assigned_sub_level']) as $user) {
            foreach (LevelAssignments::rowsFor($user->role, $user->assigned_level, $user->assigned_sub_level) as [$level, $sub]) {
                DB::table('user_level_assignments')->insert([
                    'user_id' => $user->id, 'role' => $user->role, 'level' => $level, 'sub_level' => $sub,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_level_assignments');
    }
};
