<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('browser_extension_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->string('attribute');
            $table->string('language', 2);
            $table->unsignedInteger('revision_no');
            $table->string('verdict', 20);
            $table->char('proof_digest', 64)->unique();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('browser_extension_feedback');
    }
};
