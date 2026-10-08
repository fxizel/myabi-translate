<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_imports', fn (Blueprint $table) => $table->index(['import_id', 'proposal_id'], 'proposal_import_lookup'));
    }

    public function down(): void
    {
        Schema::table('proposal_imports', fn (Blueprint $table) => $table->dropIndex('proposal_import_lookup'));
    }
};
