<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->unsignedBigInteger('last_publication_id')->nullable()->index();
            $table->unsignedInteger('last_published_revision')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->dropIndex(['last_publication_id']);
            $table->dropColumn(['last_publication_id', 'last_published_revision']);
        });
    }
};
