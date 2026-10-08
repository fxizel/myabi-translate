<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Provenance and collision lookups use the stable raw hash. Preserve the
        // original scoped hash for traceability; its old index is no longer read.
        if (Schema::hasIndex('import_rows', 'import_rows_import_id_identity_hash_index')) {
            Schema::table('import_rows', fn (Blueprint $table) => $table->dropIndex('import_rows_import_id_identity_hash_index'));
        }
    }

    public function down(): void
    {
        Schema::table('import_rows', fn (Blueprint $table) => $table->index(['import_id', 'identity_hash']));
    }
};
