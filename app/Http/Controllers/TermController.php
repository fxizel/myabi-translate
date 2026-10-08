<?php

namespace App\Http\Controllers;

use App\Domain\Devconf\FormatRegistry;
use App\Models\AuditEvent;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Term;
use App\Models\TermState;
use App\Services\AuditService;
use App\Services\OperationLock;
use App\Services\SourceRecord;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TermController extends Controller
{
    public static function typeLabels(): array
    {
        return array_map(function ($format) {
            $key = 'catalogue.types.'.$format->code;

            return app('translator')->has($key) ? __($key) : $format->label;
        }, FormatRegistry::all());
    }

    public static function filters(): array
    {
        return ['types' => self::typeLabels(), 'versions' => MyabiVersion::orderByDesc('released_at')->get(), 'organizations' => Organisation::orderBy('code')->get()];
    }

    public function dashboard(Request $request)
    {
        $query = Term::visibleTo($request->user());
        $pending = Proposal::where('status', 'pending');
        $review = TermState::where('review_languages', '>', 0);
        if (! ($request->user()->canManage() || $request->user()->canValidateAny())) {
            $visible = (clone $query)->select('id');
            $pending->whereIn('term_id', $visible);
            $review->whereIn('id', $visible);
        }
        if (! $request->user()->canManage()) {
            $pending->whereIn('language', $request->user()->canValidateAny() ? $request->user()->roleLanguages('validator') : $request->user()->roleLanguages('translator'));
        }

        return view('dashboard', [...self::filters(), 'stats' => ['terms' => $query->count(), 'pending' => $pending->count(), 'overdue' => (clone $pending)->where('created_at', '<=', now()->subDays(30))->count(),
            'review' => $review->count()],
            'imports' => $request->user()->canManage() ? ImportBatch::with('version', 'author')->latest()->limit(5)->get() : collect(),
            'currentVersion' => MyabiVersion::where('status', 'current')->first()]);
    }

    public function index(Request $request)
    {
        $data = $request->validate(['type' => 'nullable|in:'.implode(',', array_keys(FormatRegistry::all())), 'language' => 'nullable|in:de,fr,it,en', 'q' => 'nullable|string|max:250',
            'version_id' => 'nullable|integer|exists:myabi_versions,id', 'organization_id' => 'nullable|integer', 'context' => 'nullable|string|max:255', 'per_page' => 'nullable|integer|min:10|max:100',
            'state' => 'nullable|in:missing,pending,validated,published,review,obsolete', 'scope' => 'nullable|in:common,own', 'sort' => 'nullable|in:key,updated,created', 'search_mode' => 'nullable|in:text,prefix,regex']);
        $language = $data['language'] ?? 'fr';
        $query = Term::visibleTo($request->user())->with('organization', 'sourceImport', 'lastPublication');
        if ($request->filled('type')) {
            $query->where('type', $data['type']);
        }
        if ($request->filled('version_id')) {
            $query->whereIn('id', DB::table('import_rows')->join('import_batches', 'import_batches.id', '=', 'import_rows.import_id')->where('import_batches.version_id', $data['version_id'])->where('status', 'applied')->select('term_id'));
        }
        if ($request->filled('organization_id')) {
            $query->where('organization_id', $data['organization_id']);
        }
        if (($data['scope'] ?? '') === 'common') {
            $query->common();
        }
        if (($data['scope'] ?? '') === 'own') {
            $query->where('organization_id', $request->user()->organization_id);
        }
        if ($request->filled('context')) {
            $query->where('context', $data['context']);
        }
        if ($request->filled('q')) {
            $text = $data['q'];
            $mode = $data['search_mode'] ?? 'text';
            if ($mode === 'regex') {
                abort_unless($request->user()->canManage(), 403);
                if (! in_array(DB::getDriverName(), ['mariadb', 'mysql'])) {
                    throw ValidationException::withMessages(['q' => __('ui.regex_mariadb')]);
                }
                if (@preg_match('~'.str_replace('~', '\~', $text).'~u', '') === false) {
                    throw ValidationException::withMessages(['q' => __('ui.regex_invalid')]);
                }
                $query->whereIn('id', DB::table('term_search')->select('term_id')->whereRaw('search_text REGEXP ?', [$text]));
            } elseif (str_starts_with($text, '"') && str_ends_with($text, '"')) {
                $exact = substr($text, 1, -1);
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $exact).'%';
                $query->whereIn('id', DB::table('term_search')->select('term_id')->whereRaw("search_text LIKE ? ESCAPE '!'", [$pattern]));
            } else {
                $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text);
                $pattern = ($mode === 'prefix' ? '' : '%').$escaped.'%';
                if ($mode === 'prefix') {
                    $query->whereRaw("label LIKE ? ESCAPE '!'", [$pattern]);
                } else {
                    $query->whereIn('id', DB::table('term_search')->select('term_id')->whereRaw("search_text LIKE ? ESCAPE '!'", [$pattern]));
                }
            }
        }
        $state = $data['state'] ?? null;
        if ($state === 'pending') {
            $query->whereHas('proposals', fn ($q) => $q->where('status', 'pending')->where('language', $language));
        }
        if (in_array($state, ['missing', 'validated', 'review', 'published', 'obsolete'])) {
            $query->whereIn('id', TermState::matchingState($state, $language, $request->filled('version_id') ? (int) $data['version_id'] : null)->select('id'));
        }
        $sort = match ($data['sort'] ?? 'updated') {
            'key' => 'label','created' => 'created_at',default => 'updated_at'
        };
        // A single aggregate supplies both the per-type badges and paginator total.
        // GROUP BY type otherwise encourages MariaDB to walk the type index and
        // fetch every large catalogue row in index order for text predicates.
        $countQuery = (clone $query)->withoutEagerLoads();
        if ($state === 'pending' && in_array(DB::getDriverName(), ['mariadb', 'mysql'])
            && ! $request->filled('context') && ($data['search_mode'] ?? 'text') !== 'prefix') {
            // Keep the count on a covering term index. A proposal-first
            // LooseScan otherwise fetches every large matching term by PK.
            $scoped = ! ($request->user()->canManage() || $request->user()->canValidateAny())
                || $request->filled('organization_id') || $request->filled('scope');
            $countQuery->forceIndex($scoped ? 'terms_organization_id_index' : 'terms_type_obsolete_id_index');
        }
        foreach (array_keys(FormatRegistry::all()) as $type) {
            $countQuery->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS '.$type, [$type]);
        }
        $counts = $countQuery->toBase()->first();
        $typeCounts = collect((array) $counts)->map(fn ($count) => (int) $count)->filter(fn ($count) => $count > 0);
        $perPage = (int) ($data['per_page'] ?? 25);
        $page = LengthAwarePaginator::resolveCurrentPage();
        // Select only this page's IDs first so the date/id index covers the
        // ordered scan; hydrate at most 100 large rows after SQL filtering.
        $pageQuery = (clone $query)->withoutEagerLoads()->select('id');
        if (in_array(DB::getDriverName(), ['mariadb', 'mysql']) && $sort !== 'label' && ! $request->filled('context') && ($data['search_mode'] ?? 'text') !== 'prefix') {
            // MariaDB may otherwise choose organization_id IS NULL OR ... and
            // fetch/sort the entire common catalogue despite a tiny LIMIT.
            $pageQuery->forceIndex($sort === 'created_at' ? 'terms_created_page_index' : 'terms_updated_page_index');
        }
        $ids = $typeCounts->sum() > ($page - 1) * $perPage
            ? $pageQuery->orderBy($sort, $sort === 'label' ? 'asc' : 'desc')->orderBy('id')->forPage($page, $perPage)->pluck('id')
            : collect();
        $rows = Term::visibleTo($request->user())->with('organization', 'sourceImport', 'lastPublication')->whereIn('id', $ids)->get()->keyBy('id');
        $items = $ids->map(fn ($id) => $rows->get($id))->filter()->values();
        $terms = (new LengthAwarePaginator($items, $typeCounts->sum(), $perPage, $page, ['path' => $request->url()]))->withQueryString();
        $proposals = Proposal::whereIn('term_id', $terms->pluck('id'))->where('language', $language)->where('status', 'pending')
            ->orderByDesc('created_at')->orderByDesc('id')->get()->groupBy(['term_id', 'attribute', 'language']);
        foreach ($terms as $term) {
            $attribute = FormatRegistry::get($term->type)->attributeNames[0];
            $pending = $proposals->get($term->id, collect())->get($attribute, collect())->get($language, collect());
            $term->display_attribute = $attribute;
            $term->current_value = data_get($term->validated, "$attribute.$language");
            $term->current_state = $term->state($attribute, $language);
            $term->pending_value = $pending->first()?->value;
            $term->pending_count = $pending->count();
            $term->other_pending_attributes = $proposals->get($term->id, collect())->except($attribute)
                ->map(fn ($languages) => $languages->get($language, collect())->count());
            if ($term->current_state === 'published' && $request->filled('version_id') && $term->lastPublication?->version_id != (int) $request->query('version_id')) {
                $term->current_state = 'validated';
            }
        }

        return view('terms.index', [...self::filters(), ...compact('terms', 'language', 'typeCounts')]);
    }

    public function show(Request $request, Term $term, SourceRecord $source)
    {
        abort_unless($request->user()->canSeeOrganisation($term->organization_id), 403);
        $language = in_array($request->query('language'), ['de', 'fr', 'it', 'en']) ? $request->query('language') : 'fr';
        $attributes = $term->attributesForDisplay();
        $proposals = $term->proposals()->with('author', 'organization')->latest()->orderByDesc('id')->paginate(50)->withQueryString();
        $revisions = $term->revisions()->with('author')->orderByDesc('number')->paginate(30, ['*'], 'revisions_page');
        $presences = DB::table('import_rows')->join('import_batches', 'import_batches.id', '=', 'import_rows.import_id')->join('myabi_versions', 'myabi_versions.id', '=', 'import_batches.version_id')
            ->where('term_id', $term->id)->where('import_batches.status', 'applied')->select('import_rows.*', 'myabi_versions.number as version_number', 'import_batches.filename', 'import_batches.created_at')->orderByDesc('import_id')->paginate(25, ['*'], 'presence_page');
        $comparison = null;
        $restoreRevision = null;
        if ($request->filled('restore_revision')) {
            $restoreRevision = $term->revisions()->where('number', (int) $request->query('restore_revision'))->firstOrFail();
        }
        if ($request->filled('from') && $request->filled('to')) {
            foreach (['from', 'to'] as $side) {
                $revision = $term->revisions()->where('number', (int) $request->query($side))->firstOrFail();
                $comparison[$side] = ['revision' => $revision, 'attributes' => $source->attributes($revision), 'validated' => $revision->validated];
            }
        }
        $events = AuditEvent::with('user')->where(function ($q) use ($term) {
            $q->where(fn ($q) => $q->where('entity_type', Term::class)->where('entity_id', (string) $term->id))
                ->orWhere(fn ($q) => $q->where('entity_type', Proposal::class)->whereIn('entity_id', $term->proposals()->select('id')));
        })->orderByDesc('id')->paginate(30, ['*'], 'history_page');

        return view('terms.show', [...self::filters(), ...compact('term', 'attributes', 'proposals', 'revisions', 'presences', 'comparison', 'language', 'events', 'restoreRevision'), 'sourceMetadata' => $source->row($term)]);
    }

    public function scope(Request $request, Term $term, OperationLock $lock, AuditService $audit, SourceRecord $source)
    {
        abort_unless($request->user()->canManage(), 403);
        $d = $request->validate(['organization_id' => 'nullable|integer|exists:organizations,id', 'lock_version' => 'required|integer']);
        $lock->run(fn () => DB::transaction(function () use ($d, $term, $request, $audit, $source) {
            $t = Term::whereKey($term->id)->lockForUpdate()->firstOrFail();
            if ($t->lock_version != (int) $d['lock_version']) {
                throw ValidationException::withMessages(['scope' => __('ui.stale_change')]);
            }
            $owner = isset($d['organization_id']) ? (int) $d['organization_id'] : null;
            $hash = hash('sha256', json_encode([$t->type, $owner, $t->key], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $rawHash = hash('sha256', json_encode([$t->type, $t->key], JSON_THROW_ON_ERROR));
            $candidates = Term::where('type', $t->type)->where('organization_id', $owner)->where('id', '!=', $t->id)
                ->where(fn ($query) => $query->where('raw_identity_hash', $rawHash)->orWhereNull('raw_identity_hash'))
                ->select('id', 'key')->lockForUpdate();
            // Legacy rows without a raw hash remain part of the exact check.
            // Their slower, bounded fallback must never silently allow a conflict.
            foreach ($candidates->lazyById(300) as $candidate) {
                if ($candidate->key === $t->key) {
                    throw ValidationException::withMessages(['scope' => __('ui.scope_collision')]);
                }
            }
            $before = $t->organization_id;
            $t->update(['organization_id' => $owner, 'identity_hash' => $hash, 'scope_unconfirmed' => false, 'scope_overridden' => true, 'lock_version' => $t->lock_version + 1, 'revision_no' => $t->revision_no + 1, 'updated_by' => $request->user()->id]);
            $source->snapshot($t, 'scope', $t->id, $request->user()->id);
            $audit->record('term.scope', $t, ['organization_id' => $before], ['organization_id' => $owner]);
            DB::table('catalogue_state')->where('id', 1)->increment('generation');
        }, 3));

        return back()->with('status', __('ui.saved'));
    }
}
