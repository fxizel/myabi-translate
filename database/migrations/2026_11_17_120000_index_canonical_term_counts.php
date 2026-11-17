<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'terms_canonical_type_index';

    public function up(): void
    {
        if (Schema::hasIndex('terms', self::INDEX)) {
            return;
        }

        if (in_array(DB::getDriverName(), ['mariadb', 'mysql'], true)) {
            // Cover both the catalogue visibility predicate and the totals by type.
            DB::statement('ALTER TABLE terms ADD INDEX '.self::INDEX.' (consolidated_into_id, type), ALGORITHM=INPLACE, LOCK=NONE');

            return;
        }

        Schema::table('terms', fn (Blueprint $table) => $table->index(['consolidated_into_id', 'type'], self::INDEX));
    }

    public function down(): void
    {
        if (! Schema::hasIndex('terms', self::INDEX)) {
            return;
        }

        if (in_array(DB::getDriverName(), ['mariadb', 'mysql'], true)) {
            DB::statement('ALTER TABLE terms DROP INDEX '.self::INDEX.', ALGORITHM=INPLACE, LOCK=NONE');

            return;
        }

        Schema::table('terms', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
    }
};
