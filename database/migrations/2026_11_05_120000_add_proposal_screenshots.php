<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->string('screenshot_path')->nullable()->unique();
            $table->string('screenshot_mime', 10)->nullable();
            $table->unsignedInteger('screenshot_bytes')->nullable();
            $table->unsignedInteger('screenshot_width')->nullable();
            $table->unsignedInteger('screenshot_height')->nullable();
            $table->char('screenshot_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('proposals')->whereNotNull('screenshot_path')->exists()) {
            throw new RuntimeException('Restore a coherent backup before removing screenshot references.');
        }
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropUnique(['screenshot_path']);
            $table->dropColumn(['screenshot_path', 'screenshot_mime', 'screenshot_bytes', 'screenshot_width', 'screenshot_height', 'screenshot_sha256']);
        });
    }
};
