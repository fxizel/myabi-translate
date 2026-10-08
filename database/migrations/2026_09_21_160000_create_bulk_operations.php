<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_operations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40)->default('initial_validation');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('import_id')->constrained('import_batches');
            $table->string('language', 5);
            $table->boolean('override')->default(false);
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedBigInteger('cursor')->default(0);
            $table->unsignedBigInteger('max_proposal_id')->default(0);
            $table->unsignedBigInteger('expected_generation');
            $table->json('preview');
            $table->json('counts');
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();
            $table->index(['import_id', 'language', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_operations');
    }
};
