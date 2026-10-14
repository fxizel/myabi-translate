<?php

use App\Services\CommonTermTransition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('terms', 'consolidated_into_id')) {
            Schema::table('terms', fn (Blueprint $t) => $t->unsignedBigInteger('consolidated_into_id')->nullable()->index());
        }
        if (! Schema::hasColumn('proposals', 'legacy_term_id')) {
            Schema::table('proposals', fn (Blueprint $t) => $t->unsignedBigInteger('legacy_term_id')->nullable()->index());
        }
        if (! Schema::hasTable('term_consolidations')) {
            Schema::create('term_consolidations', function (Blueprint $t) {
                $t->unsignedBigInteger('legacy_term_id')->primary();
                $t->unsignedBigInteger('common_term_id')->index();
                $t->json('metadata');
                $t->timestamp('created_at');
            });
        }
        if (! Schema::hasTable('term_legacy_scopes')) {
            Schema::create('term_legacy_scopes', function (Blueprint $t) {
                $t->unsignedBigInteger('term_id')->primary();
                $t->json('metadata');
            });
        }
        if (! Schema::hasTable('common_term_recovered_values')) {
            Schema::create('common_term_recovered_values', function (Blueprint $t) {
                $t->unsignedBigInteger('legacy_term_id');
                $t->string('attribute', 60);
                $t->string('language', 2);
                $t->unsignedBigInteger('proposal_id');
                $t->unsignedBigInteger('legacy_proposal_id')->nullable();
                $t->primary(['legacy_term_id', 'attribute', 'language']);
            });
        }
        if (! Schema::hasTable('common_term_transition')) {
            Schema::create('common_term_transition', function (Blueprint $t) {
                $t->unsignedInteger('id')->primary();
                $t->timestamp('completed_at')->nullable();
                $t->json('counts')->nullable();
            });
        }
        // MariaDB may commit the column/table DDL before a separate index statement fails.
        foreach (['terms' => ['consolidated_into_id', 'terms_consolidated_into_id_index'],
            'proposals' => ['legacy_term_id', 'proposals_legacy_term_id_index'],
            'term_consolidations' => ['common_term_id', 'term_consolidations_common_term_id_index']] as $table => [$column, $index]) {
            if (! Schema::hasIndex($table, $index)) {
                Schema::table($table, fn (Blueprint $t) => $t->index($column, $index));
            }
        }
        app(CommonTermTransition::class)->consolidate();
        foreach (Schema::getIndexes('terms') as $index) {
            if (array_intersect($index['columns'], ['organization_id', 'scope_unconfirmed', 'scope_overridden'])) {
                Schema::table('terms', fn (Blueprint $t) => $t->dropIndex($index['name']));
            }
        }
        foreach (['organization_id', 'scope_unconfirmed', 'scope_overridden'] as $column) {
            if (Schema::hasColumn('terms', $column)) {
                // SQLite rebuilds tables for some ALTERs: preserve all projection triggers.
                $triggers = DB::getDriverName() === 'sqlite'
                    ? DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'terms')->get(['name', 'sql']) : [];
                if ($column === 'organization_id' && Schema::hasIndex('terms', 'terms_organization_id_index')) {
                    Schema::table('terms', fn (Blueprint $t) => $t->dropIndex('terms_organization_id_index'));
                }
                Schema::table('terms', fn (Blueprint $t) => $t->dropColumn($column));
                foreach ($triggers as $trigger) {
                    if (! DB::table('sqlite_master')->where('type', 'trigger')->where('name', $trigger->name)->exists()) {
                        DB::unprepared($trigger->sql);
                    }
                }
            }
        }
        foreach (['created' => 'created_at', 'updated' => 'updated_at'] as $name => $column) {
            if (! Schema::hasIndex('terms', 'terms_'.$name.'_page_index')) {
                if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                    DB::statement('ALTER TABLE terms ADD INDEX terms_'.$name.'_page_index (`'.$column.'` DESC, id ASC, type, consolidated_into_id)');
                } else {
                    Schema::table('terms', fn (Blueprint $t) => $t->index([$column, 'id', 'type', 'consolidated_into_id'], 'terms_'.$name.'_page_index'));
                }
            }
        }
        if (! Schema::hasIndex('import_rows', 'import_rows_import_id_offset_index')) {
            Schema::table('import_rows', fn (Blueprint $t) => $t->index(['import_id', 'offset'], 'import_rows_import_id_offset_index'));
        }
    }

    public function down(): void
    {
        if (DB::table('terms')->exists() || DB::table('proposals')->exists() || DB::table('revisions')->exists() || DB::table('term_consolidations')->exists()) {
            throw new RuntimeException('Restore a coherent private database/files backup to undo the common catalogue transition.');
        }
        foreach (['terms_created_page_index', 'terms_updated_page_index', 'terms_consolidated_into_id_index'] as $index) {
            if (Schema::hasIndex('terms', $index)) {
                Schema::table('terms', fn (Blueprint $t) => $t->dropIndex($index));
            }
        }
        Schema::table('terms', function (Blueprint $t) {
            $t->unsignedBigInteger('organization_id')->nullable()->index();
            $t->boolean('scope_unconfirmed')->default(false);
            $t->boolean('scope_overridden')->default(false);
            $t->dropColumn('consolidated_into_id');
        });
        foreach (['created' => 'created_at', 'updated' => 'updated_at'] as $name => $column) {
            Schema::table('terms', fn (Blueprint $t) => $t->index([$column, 'id', 'organization_id', 'type'], 'terms_'.$name.'_page_index'));
        }
        Schema::table('proposals', function (Blueprint $t) {
            $t->dropIndex('proposals_legacy_term_id_index');
            $t->dropColumn('legacy_term_id');
        });
        Schema::table('import_rows', fn (Blueprint $t) => $t->dropIndex('import_rows_import_id_offset_index'));
        foreach (['common_term_recovered_values', 'term_legacy_scopes', 'term_consolidations', 'common_term_transition'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
