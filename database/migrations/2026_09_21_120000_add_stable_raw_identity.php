<?php

use App\Domain\Devconf\CsvReader;
use App\Domain\Devconf\FormatRegistry;
use App\Models\Term;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // These guards also permit resuming a large interrupted backfill on MariaDB,
        // where ALTER TABLE commits independently of migration bookkeeping.
        if (! Schema::hasColumn('terms', 'raw_identity_hash')) {
            Schema::table('terms', fn (Blueprint $table) => $table->char('raw_identity_hash', 64)->nullable());
        }
        if (! Schema::hasColumn('terms', 'scope_overridden')) {
            Schema::table('terms', fn (Blueprint $table) => $table->boolean('scope_overridden')->default(false));
        }
        if (! Schema::hasColumn('import_rows', 'raw_identity_hash')) {
            Schema::table('import_rows', fn (Blueprint $table) => $table->char('raw_identity_hash', 64)->nullable());
        }
        DB::table('terms')->select(['id', 'type', 'key'])->whereNull('raw_identity_hash')->orderBy('id')->chunkById(300, function ($terms) {
            $hashes = [];
            foreach ($terms as $term) {
                $key = json_decode($term->key, true, flags: JSON_THROW_ON_ERROR);
                $hashes[$term->id] = hash('sha256', json_encode([$term->type, $key], JSON_THROW_ON_ERROR));
            }
            $this->setHashes('terms', $hashes);
        });
        $sources = [];
        DB::table('import_rows')->whereNull('raw_identity_hash')->orderBy('id')->chunkById(300, function ($rows) use (&$sources) {
            $terms = DB::table('terms')->whereIn('id', $rows->pluck('term_id')->filter()->all())->pluck('raw_identity_hash', 'id');
            $hashes = [];
            foreach ($rows as $row) {
                if ($row->term_id && isset($terms[$row->term_id])) {
                    $hashes[$row->id] = $terms[$row->term_id];

                    continue;
                }
                $source = $sources[$row->import_id] ??= DB::table('import_batches')->where('id', $row->import_id)->first();
                if (! $source) {
                    throw new RuntimeException('Import provenance missing during identity migration.');
                }
                $format = FormatRegistry::get($source->type);
                $record = (new CsvReader)->readAt(storage_path('app/private/'.$source->path), $row->offset, $row->record_number, $row->length);
                $hashes[$row->id] = $format->identityHash($record->associative($format->headers));
            }
            $this->setHashes('import_rows', $hashes);
        });
        DB::table('terms')->whereIn('id', DB::table('audit_events')->where('action', 'term.scope')
            ->where('entity_type', Term::class)->select('entity_id'))->update(['scope_overridden' => true]);
        if (! Schema::hasIndex('terms', 'terms_raw_identity_hash_index')) {
            Schema::table('terms', fn (Blueprint $table) => $table->index('raw_identity_hash'));
        }
        if (! Schema::hasIndex('import_rows', 'import_rows_raw_identity_hash_index')) {
            Schema::table('import_rows', fn (Blueprint $table) => $table->index('raw_identity_hash'));
        }
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            $table->dropIndex(['raw_identity_hash']);
            $table->dropColumn('raw_identity_hash');
        });
        Schema::table('terms', function (Blueprint $table) {
            $table->dropIndex(['raw_identity_hash']);
            $table->dropColumn(['raw_identity_hash', 'scope_overridden']);
        });
    }

    private function setHashes(string $table, array $hashes): void
    {
        if ($hashes === []) {
            return;
        }
        $bindings = [];
        $cases = [];
        foreach ($hashes as $id => $hash) {
            $cases[] = 'WHEN ? THEN ?';
            $bindings[] = $id;
            $bindings[] = $hash;
        }
        $quoted = DB::connection()->getQueryGrammar()->wrapTable($table);
        DB::update('UPDATE '.$quoted.' SET raw_identity_hash = CASE id '.implode(' ', $cases).' END WHERE id IN ('
            .implode(',', array_fill(0, count($hashes), '?')).')', [...$bindings, ...array_keys($hashes)]);
    }
};
