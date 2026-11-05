<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terms', fn (Blueprint $t) => $t->json('catalogue_reference_de')->nullable());
        Schema::create('catalogue_search_state', function (Blueprint $t) {
            $t->engine('InnoDB');
            $t->unsignedInteger('id')->primary();
            foreach (['active_generation', 'build_generation', 'mutation_no', 'build_mutation', 'cursor', 'build_values', 'build_occurrences'] as $column) {
                $t->unsignedBigInteger($column)->default(0);
            }
            $t->unsignedInteger('normalization_version')->default(0);
            $t->boolean('ready')->default(false);
            $t->boolean('references_ready')->default(false);
        });
        DB::table('catalogue_search_state')->insert(['id' => 1]);
        Schema::create('catalogue_search_dirty', function (Blueprint $t) {
            $t->engine('InnoDB');
            $t->unsignedBigInteger('term_id')->primary();
            $t->unsignedBigInteger('mutation_no');
        });
        Schema::create('catalogue_search_values', function (Blueprint $t) {
            $t->engine('InnoDB');
            $t->id();
            $t->unsignedBigInteger('generation');
            $t->unsignedBigInteger('term_id');
            $t->string('attribute', 80);
            $t->string('language', 2);
            $t->string('origin', 12);
            // Zero for reference/current; otherwise the proposal ID.
            $t->unsignedBigInteger('origin_id')->default(0);
            $fingerprint = $t->char('fingerprint', 64);
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
                $fingerprint->charset('ascii')->collation('ascii_bin');
            }
            $t->unsignedInteger('word_count');
            $t->unsignedInteger('text_length');
            $t->unique(['generation', 'term_id', 'attribute', 'language', 'origin', 'origin_id'], 'cs_value_identity');
            $t->index(['generation', 'language', 'id'], 'cs_value_language');
        });
        Schema::create('catalogue_search_words', function (Blueprint $t) {
            $t->engine('InnoDB');
            $t->id();
            $t->unsignedBigInteger('generation');
            $t->string('language', 2);
            $t->longText('word');
            $hash = $t->char('word_hash', 64);
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
                $hash->charset('ascii')->collation('ascii_bin');
            }
            $t->unsignedInteger('length');
            $t->index(['generation', 'language', 'word_hash'], 'cs_word_exact');
            $t->index(['generation', 'language', 'length', 'id'], 'cs_word_length');
        });
        Schema::create('catalogue_search_occurrences', function (Blueprint $t) {
            $t->engine('InnoDB');
            $t->unsignedBigInteger('value_id');
            $t->unsignedBigInteger('word_id');
            $t->unsignedInteger('position');
            $t->unsignedInteger('start');
            $t->unsignedInteger('length');
            $t->primary(['value_id', 'position'], 'cs_occurrence_pk');
            $t->index(['word_id', 'value_id'], 'cs_occurrence_word');
        });
        Schema::create('catalogue_search_fragments', function (Blueprint $t) {
            $t->engine('InnoDB');
            $t->unsignedBigInteger('generation');
            $t->string('language', 2);
            $t->unsignedBigInteger('word_id');
            $fragment = $t->string('fragment', 8);
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
                $fragment->collation('utf8mb4_bin');
            }
            $t->unsignedInteger('frequency');
            $t->primary(['word_id', 'fragment'], 'cs_fragment_pk');
            $t->index(['generation', 'language', 'fragment', 'word_id'], 'cs_fragment_lookup');
        });
        DB::statement('INSERT INTO catalogue_search_dirty (term_id,mutation_no) SELECT id,0 FROM terms');
        $mysql = in_array(DB::getDriverName(), ['mariadb', 'mysql']);
        $termColumns = ['type', 'key', 'validated', 'source_import_id', 'source_offset', 'source_length', 'source_hash', 'obsolete', 'consolidated_into_id'];
        $proposalColumns = ['term_id', 'attribute', 'language', 'value', 'status'];
        foreach (['terms' => $termColumns, 'proposals' => $proposalColumns] as $table => $columns) {
            $prefix = $table === 'terms' ? 'cs_term_' : 'cs_proposal_';
            $new = $table === 'terms' ? 'NEW.id' : 'NEW.term_id';
            $old = $table === 'terms' ? 'OLD.id' : 'OLD.term_id';
            foreach (['insert', 'update', 'delete'] as $event) {
                $ids = $event === 'update' ? [$old, $new] : [$event === 'delete' ? $old : $new];
                $body = 'UPDATE catalogue_search_state SET mutation_no=mutation_no+1 WHERE id=1; ';
                foreach (array_unique($ids) as $id) {
                    $body .= $mysql
                        ? 'INSERT INTO catalogue_search_dirty (term_id,mutation_no) SELECT '.$id.',mutation_no FROM catalogue_search_state WHERE id=1 ON DUPLICATE KEY UPDATE mutation_no=VALUES(mutation_no); '
                        : 'INSERT OR REPLACE INTO catalogue_search_dirty (term_id,mutation_no) SELECT '.$id.',mutation_no FROM catalogue_search_state WHERE id=1; ';
                }
                $changed = implode(' OR ', array_map(fn ($column) => $mysql
                    ? 'NOT (BINARY NEW.`'.$column.'` <=> BINARY OLD.`'.$column.'`)'
                    : 'NEW."'.$column.'" IS NOT OLD."'.$column.'"', $columns));
                if ($mysql) {
                    DB::unprepared('CREATE TRIGGER '.$prefix.$event.' AFTER '.strtoupper($event).' ON '.$table.' FOR EACH ROW BEGIN '.($event === 'update' ? 'IF '.$changed.' THEN '.$body.' END IF; ' : $body).'END');
                } else {
                    DB::unprepared('CREATE TRIGGER '.$prefix.$event.' AFTER '.strtoupper($event).' ON '.$table.($event === 'update' ? ' WHEN '.$changed : '').' BEGIN '.$body.'END');
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['cs_term_', 'cs_proposal_'] as $prefix) {
            foreach (['insert', 'update', 'delete'] as $event) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$prefix.$event);
            }
        }
        foreach (['fragments', 'occurrences', 'words', 'values', 'dirty', 'state'] as $suffix) {
            Schema::dropIfExists('catalogue_search_'.$suffix);
        }
        Schema::table('terms', fn (Blueprint $t) => $t->dropColumn('catalogue_reference_de'));
    }
};
