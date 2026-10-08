<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            return;
        }
        // SHA-256 hex is ASCII. Preserve every hash character while avoiding
        // multibyte CHAR padding and oversized index records.
        $columns = [
            'terms' => ['identity_hash', 'raw_identity_hash', 'semantic_hash', 'source_hash'],
            'import_rows' => ['identity_hash', 'raw_identity_hash'],
            'proposals' => ['value_hash'],
            'import_batches' => ['sha256'],
        ];
        foreach ($columns as $table => $names) {
            $changes = array_map(fn ($name) => 'MODIFY `'.$name.'` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin '.($name === 'raw_identity_hash' ? 'NULL' : 'NOT NULL'), $names);
            // Random hashes compress poorly in 4 KiB pages; 8 KiB avoids overflow.
            if ($table === 'import_rows') {
                $changes[] = 'ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8';
            }
            DB::statement('ALTER TABLE `'.$table.'` '.implode(', ', $changes));
        }
    }

    public function down(): void
    {
        // ASCII is a lossless subset of the previous representation. Keep the
        // storage optimization on rollback; no application contract changes.
    }
};
