<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Panel revision #4: "Include an import history showing the source, processing time,
// imported and rejected counts, and errors." Imported/rejected counts and errors already
// exist (row_count, error_count, errors) - this adds the other two.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->enum('source', ['upload', 'folder'])->default('upload')->after('original_filename');
            // Time to read, validate and (if not staged for review) commit the file - the
            // same figure panel revision #7's "4,000 records within 30 seconds" target
            // measures. Null until the batch finishes being processed.
            $table->unsignedInteger('processing_ms')->nullable()->after('error_count');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn(['source', 'processing_ms']);
        });
    }
};
