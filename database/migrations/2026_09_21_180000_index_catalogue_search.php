<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mariadb', 'mysql'])) {
            $indexes = collect(Schema::getIndexes('terms'))->keyBy('name');
            foreach (['updated_at' => 'terms_updated_page_index', 'created_at' => 'terms_created_page_index'] as $column => $index) {
                if (($indexes[$index]['columns'] ?? []) !== [$column, 'id', 'organization_id', 'type']) {
                    $drop = isset($indexes[$index]) ? "DROP INDEX `$index`, " : '';
                    DB::statement("ALTER TABLE terms {$drop}ADD INDEX `$index` (`$column` DESC, id ASC, organization_id, type)");
                }
            }
            if (($indexes['terms_organization_id_index']['columns'] ?? []) !== ['organization_id', 'type', 'id']) {
                DB::statement('ALTER TABLE terms DROP INDEX terms_organization_id_index, ADD INDEX terms_organization_id_index (organization_id,type,id)');
            }
            if (! Schema::hasIndex('terms', 'terms_label_prefix_index')) {
                DB::statement('CREATE INDEX terms_label_prefix_index ON terms (label(191))');
            }
        } else {
            Schema::table('terms', function (Blueprint $table) {
                $table->index(['updated_at', 'id', 'organization_id', 'type'], 'terms_updated_page_index');
                $table->index(['created_at', 'id', 'organization_id', 'type'], 'terms_created_page_index');
                $table->index('label', 'terms_label_prefix_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->dropIndex('terms_updated_page_index');
            $table->dropIndex('terms_created_page_index');
            $table->dropIndex('terms_label_prefix_index');
        });
        if (in_array(DB::getDriverName(), ['mariadb', 'mysql'])) {
            DB::statement('ALTER TABLE terms DROP INDEX terms_organization_id_index, ADD INDEX terms_organization_id_index (organization_id)');
        }
    }
};
