<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('proposals', 'proposals_status_language_term_index')) {
            Schema::table('proposals', function (Blueprint $table) {
                // Covers both the pending term set and the per-term existence
                // lookup without loading proposal values/history JSON.
                $table->index(['status', 'language', 'term_id'], 'proposals_status_language_term_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('proposals', fn (Blueprint $table) => $table->dropIndex('proposals_status_language_term_index'));
    }
};
