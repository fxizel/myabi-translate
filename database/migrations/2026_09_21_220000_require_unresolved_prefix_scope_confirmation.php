<?php

use App\Domain\Devconf\FormatDefinition;
use App\Domain\Devconf\FormatRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $prefixes = config('referentiel.unconfirmed_scope_prefixes', FormatDefinition::UNCONFIRMED_SCOPE_PREFIXES);
        if ($prefixes === []) {
            return;
        }
        $changed = 0;
        DB::table('terms')->whereIn('type', ['form', 'workflow'])->where('scope_overridden', false)->where('scope_unconfirmed', false)
            ->select('id', 'type', 'key')->chunkById(300, function ($terms) use ($prefixes, &$changed) {
                $ids = [];
                foreach ($terms as $term) {
                    $format = FormatRegistry::get($term->type);
                    $column = $term->type === 'form' ? 'Form Template Name' : 'Workflow';
                    $key = json_decode($term->key, true, flags: JSON_THROW_ON_ERROR);
                    $index = array_search($column, $format->identityColumns, true);
                    $scope = $format->suggestedScope([$column => $key[$index] ?? ''], [], $prefixes);
                    if (in_array($scope, $prefixes, true)) {
                        $ids[] = $term->id;
                    }
                }
                if ($ids !== []) {
                    // Keep identity, ownership, revisions and frozen files intact.
                    // Never invalidate a manager's explicit scope decision.
                    $changed += DB::table('terms')->whereIn('id', $ids)->where('scope_overridden', false)->where('scope_unconfirmed', false)
                        ->update(['scope_unconfirmed' => true, 'lock_version' => DB::raw('lock_version + 1')]);
                }
            });
        if ($changed > 0) {
            DB::table('catalogue_state')->where('id', 1)->increment('generation');
        }
    }

    public function down(): void
    {
        // Reopening an unresolved scope would silently authorize publication.
        // Keep this corrective flag; only an explicit manager decision clears it.
    }
};
