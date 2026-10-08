<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $t) {
            $t->unsignedBigInteger('supersedes_id')->nullable();
            $t->unsignedBigInteger('restored_revision_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('proposals', fn (Blueprint $t) => $t->dropColumn(['supersedes_id', 'restored_revision_id']));
    }
};
