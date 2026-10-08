<?php

use App\Domain\Devconf\FormatRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('term_states')) {
            Schema::create('term_states', function (Blueprint $table) {
                $table->unsignedBigInteger('id')->primary();
                foreach (['missing', 'validated', 'review', 'published'] as $state) {
                    $table->unsignedTinyInteger($state.'_languages');
                }
                $table->boolean('obsolete');
                $table->unsignedBigInteger('last_publication_id')->nullable();
            });
        }
        $this->dropTriggers();
        $mysql = in_array(DB::getDriverName(), ['mysql', 'mariadb']);
        $columns = ['id', 'missing_languages', 'validated_languages', 'review_languages', 'published_languages', 'obsolete', 'last_publication_id'];
        $insert = 'INSERT INTO term_states ('.implode(',', $columns).') VALUES ('.implode(',', $this->expressions('NEW', $mysql)).')';
        $watched = ['type', 'validated', 'review_needed', 'obsolete', 'revision_no', 'last_published_revision', 'last_publication_id'];
        if ($mysql) {
            DB::statement('ALTER TABLE term_states ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=4');
            DB::unprepared('CREATE TRIGGER term_states_insert AFTER INSERT ON terms FOR EACH ROW '.$insert);
            $changed = implode(' OR ', array_map(fn ($column) => "NOT (BINARY NEW.$column <=> BINARY OLD.$column)", $watched));
            $upsert = implode(',', array_map(fn ($column) => "$column=VALUES($column)", array_slice($columns, 1)));
            DB::unprepared('CREATE TRIGGER term_states_update AFTER UPDATE ON terms FOR EACH ROW BEGIN IF '.$changed.' THEN '.$insert.' ON DUPLICATE KEY UPDATE '.$upsert.'; END IF; END');
            DB::unprepared('CREATE TRIGGER term_states_delete AFTER DELETE ON terms FOR EACH ROW DELETE FROM term_states WHERE id=OLD.id');
            DB::statement('INSERT INTO term_states ('.implode(',', $columns).') SELECT '.implode(',', $this->expressions('t', true)).' FROM terms t ON DUPLICATE KEY UPDATE '.$upsert);
        } else {
            DB::unprepared('CREATE TRIGGER term_states_insert AFTER INSERT ON terms BEGIN '.$insert.'; END');
            DB::unprepared('CREATE TRIGGER term_states_update AFTER UPDATE OF '.implode(',', $watched).' ON terms BEGIN '.str_replace('INSERT INTO', 'INSERT OR REPLACE INTO', $insert).'; END');
            DB::unprepared('CREATE TRIGGER term_states_delete AFTER DELETE ON terms BEGIN DELETE FROM term_states WHERE id=OLD.id; END');
            DB::statement('INSERT OR REPLACE INTO term_states ('.implode(',', $columns).') SELECT '.implode(',', $this->expressions('t', false)).' FROM terms t');
        }
    }

    /** Masks represent any matching attribute, not just the first displayed cell. */
    private function expressions(string $prefix, bool $mysql): array
    {
        $missing = $this->languageMask($prefix, 'missing', $mysql);
        $validated = $this->languageMask($prefix, 'validated', $mysql);
        $review = $this->languageMask($prefix, 'review', $mysql);
        $published = "CASE WHEN $prefix.last_publication_id IS NOT NULL AND $prefix.last_published_revision=$prefix.revision_no THEN ($validated) ELSE 0 END";

        return ["$prefix.id", $missing, $validated, $review, $published, "$prefix.obsolete", "$prefix.last_publication_id"];
    }

    private function languageMask(string $prefix, string $state, bool $mysql): string
    {
        $column = $state === 'review' ? 'review_needed' : 'validated';
        $emptyMask = $state === 'missing' ? 15 : 0;
        $types = [];
        foreach (FormatRegistry::all() as $format) {
            $languages = [];
            foreach (['de' => 1, 'fr' => 2, 'it' => 4, 'en' => 8] as $language => $bit) {
                $attributes = [];
                foreach ($format->attributeNames as $attribute) {
                    $path = '$.'.json_encode($attribute, JSON_THROW_ON_ERROR).'.'.json_encode($language, JSON_THROW_ON_ERROR);
                    $path = str_replace("'", "''", $path);
                    $value = "JSON_EXTRACT($prefix.$column, '$path')";
                    $kind = $mysql ? "JSON_TYPE($value)" : "JSON_TYPE($prefix.$column, '$path')";
                    if ($state === 'review') {
                        $attributes[] = $mysql ? "(JSON_UNQUOTE($value)='true' AND $kind='BOOLEAN')" : "($kind='true')";
                    } else {
                        // JSON null is missing; the literal string "null", an
                        // empty string and "0" are real stored translations.
                        $present = "COALESCE(UPPER($kind)<>'NULL',0)";
                        $attributes[] = $state === 'missing' ? "NOT ($present)" : $present;
                    }
                }
                $languages[] = 'CASE WHEN ('.implode(' OR ', $attributes).") THEN $bit ELSE 0 END";
            }
            $types[] = "WHEN '$format->code' THEN (".implode('+', $languages).')';
        }

        return "CASE WHEN $prefix.obsolete=1 THEN 0 WHEN $prefix.$column IN ('[]','{}') THEN $emptyMask ELSE CASE $prefix.type ".implode(' ', $types).' ELSE 0 END END';
    }

    public function down(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('term_states');
    }

    private function dropTriggers(): void
    {
        foreach (['term_states_insert', 'term_states_update', 'term_states_delete'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
    }
};
