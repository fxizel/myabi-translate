<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('browser_extension_search_state', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('mutation_no')->default(0);
            $table->unsignedBigInteger('active_generation')->default(0);
            $table->unsignedBigInteger('build_generation')->default(0);
            $table->unsignedBigInteger('build_mutation')->default(0);
            $table->unsignedBigInteger('cursor')->default(0);
            $table->boolean('ready')->default(false);
            $table->unsignedInteger('normalization_version')->default(0);
        });
        DB::table('browser_extension_search_state')->insert(['id' => 1]);
        Schema::create('browser_extension_search_dirty', function (Blueprint $table) {
            $table->unsignedBigInteger('term_id')->primary();
        });
        Schema::create('browser_extension_search_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('generation');
            $table->unsignedBigInteger('term_id');
            $table->string('attribute', 80);
            $table->string('language', 2);
            $table->string('origin', 12);
            $table->longText('value');
            $table->longText('literal');
            $table->longText('normalized');
            $table->char('literal_hash', 64);
            $table->char('normalized_hash', 64);
            $table->string('prefix', 191);
            $table->unsignedInteger('normalized_length');
            $table->unsignedBigInteger('revision_no');
            $table->string('source_hash', 64);
            $table->unsignedInteger('normalization_version');
            $table->index(['generation', 'language', 'literal_hash'], 'be_search_literal');
            $table->index(['generation', 'language', 'normalized_hash'], 'be_search_normalized');
            $table->index(['generation', 'language', 'prefix'], 'be_search_prefix');
            $table->index(['generation', 'term_id', 'attribute'], 'be_search_cell');
        });
        Schema::create('browser_extension_search_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('value_id');
            $table->unsignedBigInteger('generation');
            $table->string('language', 2);
            $table->char('token_hash', 64);
            $table->primary(['value_id', 'token_hash'], 'be_search_token_pk');
            $table->index(['generation', 'language', 'token_hash', 'value_id'], 'be_search_token_lookup');
        });
        DB::statement('INSERT INTO browser_extension_search_dirty (term_id) SELECT id FROM terms');
        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb']);
        $columns = ['type', 'key', 'validated', 'source_import_id', 'source_offset', 'source_length', 'source_hash', 'revision_no', 'obsolete', 'consolidated_into_id'];
        if ($mysql) {
            $changed = implode(' OR ', array_map(fn ($column) => 'NOT (BINARY NEW.`'.$column.'` <=> BINARY OLD.`'.$column.'`)', $columns));
            $dirtyNew = 'INSERT IGNORE INTO browser_extension_search_dirty (term_id) VALUES (NEW.id); UPDATE browser_extension_search_state SET mutation_no=mutation_no+1 WHERE id=1;';
            DB::unprepared('CREATE TRIGGER be_search_insert AFTER INSERT ON terms FOR EACH ROW BEGIN '.$dirtyNew.' END');
            DB::unprepared('CREATE TRIGGER be_search_update AFTER UPDATE ON terms FOR EACH ROW BEGIN IF '.$changed.' THEN '.$dirtyNew.' END IF; END');
            DB::unprepared('CREATE TRIGGER be_search_delete AFTER DELETE ON terms FOR EACH ROW BEGIN INSERT IGNORE INTO browser_extension_search_dirty (term_id) VALUES (OLD.id); UPDATE browser_extension_search_state SET mutation_no=mutation_no+1 WHERE id=1; END');
        } else {
            $changed = implode(' OR ', array_map(fn ($column) => 'NEW."'.$column.'" IS NOT OLD."'.$column.'"', $columns));
            $dirtyNew = 'INSERT OR IGNORE INTO browser_extension_search_dirty (term_id) VALUES (NEW.id); UPDATE browser_extension_search_state SET mutation_no=mutation_no+1 WHERE id=1;';
            DB::unprepared('CREATE TRIGGER be_search_insert AFTER INSERT ON terms BEGIN '.$dirtyNew.' END');
            DB::unprepared('CREATE TRIGGER be_search_update AFTER UPDATE ON terms WHEN '.$changed.' BEGIN '.$dirtyNew.' END');
            DB::unprepared('CREATE TRIGGER be_search_delete AFTER DELETE ON terms BEGIN INSERT OR IGNORE INTO browser_extension_search_dirty (term_id) VALUES (OLD.id); UPDATE browser_extension_search_state SET mutation_no=mutation_no+1 WHERE id=1; END');
        }
    }

    public function down(): void
    {
        foreach (['be_search_insert', 'be_search_update', 'be_search_delete'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        foreach (['tokens', 'values', 'dirty', 'state'] as $suffix) {
            Schema::dropIfExists('browser_extension_search_'.$suffix);
        }
    }
};
