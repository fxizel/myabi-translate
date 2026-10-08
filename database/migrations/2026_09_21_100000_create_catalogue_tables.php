<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('myabi_versions', function (Blueprint $t) {
            $t->id();
            $t->string('number', 60)->unique();
            $t->date('released_at');
            $t->string('status', 20)->default('preparation')->index();
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('updated_by');
            $t->timestamps();
        });
        Schema::create('import_batches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('version_id')->index();
            $t->string('type', 20);
            $t->string('format_version', 40);
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->string('source_kind', 20);
            $t->boolean('is_complete');
            $t->string('filename');
            $t->string('path');
            $t->char('sha256', 64)->index();
            $t->unsignedBigInteger('size');
            $t->string('status', 20)->index();
            $t->unsignedInteger('row_count')->default(0);
            $t->unsignedBigInteger('analysis_offset')->default(0);
            $t->unsignedBigInteger('catalogue_generation')->default(0);
            $t->json('report')->nullable();
            $t->text('error')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('updated_by');
            $t->timestamps();
            $t->index(['version_id', 'type', 'status']);
        });
        Schema::create('terms', function (Blueprint $t) {
            $t->id();
            $t->string('type', 20);
            $t->unsignedBigInteger('organization_id')->nullable()->index();
            $t->char('identity_hash', 64)->index();
            $t->json('key');
            $t->text('label');
            $t->string('context', 255)->default('')->index();
            $t->text('source_text');
            $t->longText('search_text');
            $t->char('semantic_hash', 64);
            $t->char('source_hash', 64);
            $t->unsignedBigInteger('source_import_id');
            $t->unsignedBigInteger('source_offset');
            $t->unsignedInteger('source_length');
            $t->json('validated');
            $t->json('review_needed');
            $t->unsignedInteger('revision_no')->default(1);
            $t->unsignedInteger('lock_version')->default(1);
            $t->boolean('obsolete')->default(false);
            $t->boolean('scope_unconfirmed')->default(false);
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('updated_by');
            $t->timestamps();
            $t->index(['type', 'obsolete', 'id']);
        });
        Schema::create('import_rows', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('import_id');
            $t->unsignedInteger('record_number');
            $t->unsignedBigInteger('offset');
            $t->unsignedInteger('length');
            $t->char('identity_hash', 64);
            $t->unsignedBigInteger('term_id')->nullable()->index();
            $t->boolean('collision')->default(false);
            $t->string('category', 30)->default('new');
            $t->unique(['import_id', 'record_number']);
            $t->index(['import_id', 'identity_hash']);
        });
        Schema::create('revisions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('term_id');
            $t->unsignedInteger('number');
            $t->unsignedBigInteger('version_id');
            $t->unsignedBigInteger('source_import_id');
            $t->unsignedBigInteger('source_offset');
            $t->unsignedInteger('source_length');
            $t->json('validated');
            $t->json('metadata');
            $t->string('origin', 30);
            $t->unsignedBigInteger('origin_id');
            $t->unsignedBigInteger('created_by');
            $t->timestamp('created_at');
            $t->unique(['term_id', 'number']);
        });
        Schema::create('proposals', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('term_id');
            $t->string('attribute', 60);
            $t->string('language', 2);
            $t->text('value');
            $t->char('value_hash', 64)->index();
            $t->string('status', 16)->default('pending');
            $t->unsignedInteger('lock_version')->default(1);
            $t->unsignedBigInteger('author_id');
            $t->unsignedBigInteger('import_id')->nullable()->index();
            $t->unsignedBigInteger('organization_id')->nullable();
            $t->boolean('claimed')->default(false);
            $t->boolean('divergence')->default(false);
            $t->json('anomalies');
            $t->json('edit_history');
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->timestamps();
            $t->index(['status', 'language', 'id']);
            $t->index(['term_id', 'attribute', 'language', 'status']);
        });
        Schema::create('proposal_imports', function (Blueprint $t) {
            $t->unsignedBigInteger('proposal_id');
            $t->unsignedBigInteger('import_id');
            $t->primary(['proposal_id', 'import_id']);
        });
        Schema::create('publications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('version_id')->index();
            $t->string('number')->unique();
            $t->string('status', 20)->index();
            $t->json('types');
            $t->json('recipients');
            $t->json('manifest')->nullable();
            $t->text('note')->nullable();
            $t->text('error')->nullable();
            $t->text('withdrawal_reason')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('updated_by');
            $t->timestamps();
        });
        Schema::create('catalogue_state', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedBigInteger('generation')->default(0);
        });
        DB::table('catalogue_state')->insert(['id' => 1, 'generation' => 0]);
    }

    public function down(): void
    {
        foreach (['catalogue_state', 'publications', 'proposal_imports', 'proposals', 'revisions', 'import_rows', 'terms', 'import_batches', 'myabi_versions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
