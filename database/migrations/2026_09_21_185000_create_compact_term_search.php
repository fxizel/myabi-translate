<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('term_search')) {
            Schema::create('term_search', function (Blueprint $table) {
                $table->unsignedBigInteger('term_id')->primary();
                $table->longText('search_text');
            });
        }
        $this->dropTriggers();
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            // A narrow transactional projection avoids decompressing the large
            // term JSON/source fields during a literal search, including short
            // words and stopwords which a standard FULLTEXT index would omit.
            DB::statement('ALTER TABLE term_search ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8');
            DB::unprepared('CREATE TRIGGER term_search_insert AFTER INSERT ON terms FOR EACH ROW INSERT INTO term_search (term_id,search_text) VALUES (NEW.id,NEW.search_text)');
            DB::unprepared('CREATE TRIGGER term_search_update AFTER UPDATE ON terms FOR EACH ROW BEGIN IF NOT (BINARY NEW.search_text <=> BINARY OLD.search_text) THEN INSERT INTO term_search (term_id,search_text) VALUES (NEW.id,NEW.search_text) ON DUPLICATE KEY UPDATE search_text=VALUES(search_text); END IF; END');
            DB::unprepared('CREATE TRIGGER term_search_delete AFTER DELETE ON terms FOR EACH ROW DELETE FROM term_search WHERE term_id=OLD.id');
            DB::statement('INSERT INTO term_search (term_id,search_text) SELECT id,search_text FROM terms ON DUPLICATE KEY UPDATE search_text=VALUES(search_text)');
        } else {
            DB::unprepared('CREATE TRIGGER term_search_insert AFTER INSERT ON terms BEGIN INSERT INTO term_search (term_id,search_text) VALUES (NEW.id,NEW.search_text); END');
            DB::unprepared('CREATE TRIGGER term_search_update AFTER UPDATE OF search_text ON terms BEGIN INSERT OR REPLACE INTO term_search (term_id,search_text) VALUES (NEW.id,NEW.search_text); END');
            DB::unprepared('CREATE TRIGGER term_search_delete AFTER DELETE ON terms BEGIN DELETE FROM term_search WHERE term_id=OLD.id; END');
            DB::statement('INSERT OR REPLACE INTO term_search (term_id,search_text) SELECT id,search_text FROM terms');
        }
    }

    public function down(): void
    {
        $this->dropTriggers();
        Schema::dropIfExists('term_search');
    }

    private function dropTriggers(): void
    {
        foreach (['term_search_insert', 'term_search_update', 'term_search_delete'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
    }
};
