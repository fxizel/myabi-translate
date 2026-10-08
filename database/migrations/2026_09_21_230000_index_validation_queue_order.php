<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->index(['status', 'language', 'created_at', 'id'], 'proposals_validation_queue_index');
            $table->dropIndex(['status', 'language', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->index(['status', 'language', 'id']);
            $table->dropIndex('proposals_validation_queue_index');
        });
    }
};
