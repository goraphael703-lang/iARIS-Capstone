<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Panel revision #5: "record the rule used to approve each change." Distinct from the
// existing `note` (a message the submitter typed) and `review_reason` (the admin's reason
// for sending something back) - this is the system's own explanation of why
// App\Support\ApprovalPolicy routed this item to manual review in the first place.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_queue', function (Blueprint $table) {
            $table->string('approval_rule', 255)->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('review_queue', function (Blueprint $table) {
            $table->dropColumn('approval_rule');
        });
    }
};
