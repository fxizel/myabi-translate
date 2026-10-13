<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('term_groups', function (Blueprint $t) {
            $t->id();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->string('color', 7)->default('#2266a4');
            $t->unsignedBigInteger('organization_id')->nullable()->index();
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('updated_by');
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
        });
        $this->alterTermsPreservingTriggers(fn (Blueprint $t) => $t->foreignId('term_group_id')->nullable()->constrained('term_groups')->restrictOnDelete());
        Schema::table('proposals', fn (Blueprint $t) => $t->char('context_fingerprint', 64)->nullable());
        Schema::create('translation_campaigns', function (Blueprint $t) {
            $t->id();
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->string('objective', 16);
            $t->string('language', 2);
            $t->string('status', 16)->default('todo')->index();
            $t->date('deadline')->nullable();
            $t->unsignedBigInteger('organization_id')->nullable()->index();
            $t->timestamp('first_admitted_at')->nullable();
            $t->json('admission_report')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('updated_by');
            $t->timestamps();
        });
        Schema::create('campaign_groups', function (Blueprint $t) {
            $t->foreignId('translation_campaign_id')->constrained()->cascadeOnDelete();
            $t->foreignId('term_group_id')->constrained()->restrictOnDelete();
            $t->primary(['translation_campaign_id', 'term_group_id']);
        });
        Schema::create('campaign_assignees', function (Blueprint $t) {
            $t->foreignId('translation_campaign_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->primary(['translation_campaign_id', 'user_id']);
        });
        Schema::create('campaign_participations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('translation_campaign_id')->constrained()->restrictOnDelete();
            $t->foreignId('term_id')->constrained()->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->boolean('direct')->default(false);
            $t->string('attribute', 60);
            $t->string('status', 20)->default('to_process');
            $t->foreignId('tracked_proposal_id')->nullable()->constrained('proposals')->restrictOnDelete();
            $t->text('reason')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->unsignedBigInteger('confirmed_by')->nullable();
            $t->char('confirmation_fingerprint', 64)->nullable();
            $t->unsignedInteger('confirmed_revision')->nullable();
            $t->timestamp('removed_at')->nullable();
            $t->timestamps();
            $t->unique(['translation_campaign_id', 'term_id']);
            $t->index(['translation_campaign_id', 'active', 'status'], 'campaign_participations_campaign_active_status');
        });
        Schema::create('campaign_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('translation_campaign_id')->constrained()->restrictOnDelete();
            $t->foreignId('campaign_participation_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('action', 60);
            $t->text('reason')->nullable();
            $t->json('payload')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_events');
        Schema::dropIfExists('campaign_participations');
        Schema::dropIfExists('campaign_assignees');
        Schema::dropIfExists('campaign_groups');
        Schema::dropIfExists('translation_campaigns');
        Schema::table('proposals', fn (Blueprint $t) => $t->dropColumn('context_fingerprint'));
        $this->alterTermsPreservingTriggers(fn (Blueprint $t) => $t->dropConstrainedForeignId('term_group_id'));
        Schema::dropIfExists('term_groups');
    }

    /** SQLite rebuilds a table when changing its foreign keys, dropping its triggers. */
    private function alterTermsPreservingTriggers(Closure $alter): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $triggers = $sqlite ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'terms')->whereNotNull('sql')->get(['name', 'sql']) : [];
        Schema::table('terms', $alter);
        foreach ($triggers as $trigger) {
            if (! DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger->name)->exists()) {
                DB::unprepared($trigger->sql);
            }
        }
    }
};
