<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEXES = [
        'terms_identity_hash_index' => 'identity_hash',
        'terms_last_publication_id_index' => 'last_publication_id',
    ];

    public function up(): void
    {
        if (! Schema::hasIndex('terms', 'terms_raw_identity_hash_index')) {
            throw new RuntimeException('The stable raw identity index must exist before redundant indexes are removed.');
        }
        // Preserve every historical value. The controller uses the raw index,
        // exact keys and a bounded fallback for legacy rows whose hash is null.
        // Separate guards allow resuming MariaDB's independently committed DDL.
        foreach (self::INDEXES as $name => $column) {
            if (Schema::hasIndex('terms', $name)) {
                Schema::table('terms', fn (Blueprint $table) => $table->dropIndex($name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $name => $column) {
            if (! Schema::hasIndex('terms', $name)) {
                Schema::table('terms', fn (Blueprint $table) => $table->index($column, $name));
            }
        }
    }
};
