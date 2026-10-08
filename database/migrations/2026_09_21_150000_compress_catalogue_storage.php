<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Value hashes are compared within a term/cell; the global index was unused.
        Schema::table('proposals', fn (Blueprint $t) => $t->dropIndex('proposals_value_hash_index'));
        if (in_array(DB::getDriverName(), ['mariadb', 'mysql'])) {
            foreach (['terms', 'proposals', 'revisions', 'import_rows', 'proposal_imports'] as $table) {
                DB::statement('ALTER TABLE `'.$table.'` ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=4');
                $row = DB::selectOne('SELECT row_format FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$table]);
                if (strtolower($row->row_format) !== 'compressed') {
                    throw new RuntimeException('Lossless InnoDB compression is required for the pilot storage profile.');
                }
            }
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mariadb', 'mysql'])) {
            foreach (['terms', 'proposals', 'revisions', 'import_rows', 'proposal_imports'] as $table) {
                DB::statement('ALTER TABLE `'.$table.'` ROW_FORMAT=DYNAMIC KEY_BLOCK_SIZE=0');
            }
        }
        Schema::table('proposals', fn (Blueprint $t) => $t->index('value_hash'));
    }
};
