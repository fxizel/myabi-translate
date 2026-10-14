<?php

namespace App\Http\Controllers;

use App\Domain\Devconf\FormatRegistry;
use App\Models\AuditEvent;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use App\Models\Proposal;
use App\Models\Term;
use App\Models\TermGroup;
use App\Models\TermState;
use App\Models\TranslationCampaign;
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
        $review = TermState::where('review_languages', '>', 0)->whereIn('id', Term::select('id'));
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
            'version_id' => 'nullable|integer|exists:myabi_versions,id', 'context' => 'nullable|string|max:255', 'per_page' => 'nullable|integer|min:10|max:100',
            'state' => 'nullable|in:missing,pending,validated,published,review,obsolete', 'sort' => 'nullable|in:key,updated,created', 'search_mode' => 'nullable|in:text,prefix,regex', 'group_id' => ['nullable', 'regex:/^(none|[1-9][0-9]*)$/'],
            'campaign_id' => 'nullable|integer|exists:translation_campaigns,id']);
        $language = $data['language'] ?? 'fr';
        $query = Term::visibleTo($request->user())->with('sourceImport', 'lastPublication');
        if (($data['group_id'] ?? '') === 'none') {
            $query->whereNull('term_group_id');
        } elseif ($request->filled('group_id')) {
            $query->where('term_group_id', (int) $data['group_id']);
        }
        if ($request->filled('campaign_id')) {
            $campaign = TranslationCampaign::visibleTo($request->user())->findOrFail($data['campaign_id']);
            $query->whereIn('id', $campaign->participations()->where('active', true)->select('term_id'));
        }
        if ($request->filled('type')) {
            $query->where('type', $data['type']);
        }
        if ($request->filled('version_id')) {
            $query->whereIn('id', DB::table('import_rows')->join('import_batches', 'import_batches.id', '=', 'import_rows.import_id')->where('import_batches.version_id', $data['version_id'])->where('status', 'applied')->select('term_id'));
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
        // A single aggregate supplies the totals by type and paginator total.
        // GROUP BY type otherwise encourages MariaDB to walk the type index and
        // fetch every large catalogue row in index order for text predicates.
        $countQuery = (clone $query)->withoutEagerLoads();
        if ($state === 'pending' && in_array(DB::getDriverName(), ['mariadb', 'mysql'])
            && ! $request->filled('context') && ! $request->filled('group_id') && ! $request->filled('campaign_id') && ($data['search_mode'] ?? 'text') !== 'prefix') {
            // Keep the count on a covering term index. A proposal-first
            // LooseScan otherwise fetches every large matching term by PK.
            $countQuery->forceIndex('terms_type_obsolete_id_index');
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
        if (in_array(DB::getDriverName(), ['mariadb', 'mysql']) && $sort !== 'label' && ! $request->filled('context') && ! $request->filled('group_id') && ! $request->filled('campaign_id') && ($data['search_mode'] ?? 'text') !== 'prefix') {
            // Keep the ordered catalogue page on its covering date/id index.
            $pageQuery->forceIndex($sort === 'created_at' ? 'terms_created_page_index' : 'terms_updated_page_index');
        }
        $ids = $typeCounts->sum() > ($page - 1) * $perPage
            ? $pageQuery->orderBy($sort, $sort === 'label' ? 'asc' : 'desc')->orderBy('id')->forPage($page, $perPage)->pluck('id')
            : collect();
        $rows = Term::visibleTo($request->user())->with('sourceImport', 'lastPublication', 'group')->whereIn('id', $ids)->get()->keyBy('id');
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

        $groups = TermGroup::visibleTo($request->user())->orderBy('title')->get();
        $filterCampaigns = TranslationCampaign::visibleTo($request->user())->orderBy('title')->get();
        $compositionCampaigns = $request->user()->canManage() ? $filterCampaigns->whereNull('archived_at')->whereIn('status', ['todo', 'in_progress']) : collect();

        return view('terms.index', [...self::filters(), ...compact('terms', 'language', 'typeCounts', 'groups', 'filterCampaigns', 'compositionCampaigns')]);
    }

    public function show(Request $request, string $term, SourceRecord $source)
    {
        abort_unless($request->user()->canSeeCatalogue(), 403);
        $term = Term::withoutGlobalScope('current')->findOrFail($term);
        if ($term->consolidated_into_id !== null) {
            return redirect()->route('terms.show', [...$request->query(), 'term' => $term->consolidated_into_id, 'lineage' => $term->id]);
        }
        $lineageIds = DB::table('term_consolidations')->where('common_term_id', $term->id)->pluck('legacy_term_id');
        $lineages = Term::withoutGlobalScope('current')->whereIn('id', $lineageIds)->orderBy('created_at')->orderBy('id')->get();
        $lineageId = $request->integer('lineage', $term->id);
        abort_unless($lineageId === $term->id || $lineageIds->contains($lineageId), 404);
        $revisionTerm = $lineageId === $term->id ? $term : $lineages->firstWhere('id', $lineageId);
        $historicalLineage = $lineageId !== $term->id;
        $term->load('group');
        $language = in_array($request->query('language'), ['de', 'fr', 'it', 'en']) ? $request->query('language') : 'fr';
        $attributes = $term->attributesForDisplay();
        $proposals = $term->proposals()->with('author', 'organization', 'import.organization', 'imports.organization')->latest()->orderByDesc('id')->paginate(50)->withQueryString();
        $revisions = $revisionTerm->revisions()->with('author')->orderByDesc('number')->paginate(30, ['*'], 'revisions_page');
        $presences = DB::table('import_rows')->join('import_batches', 'import_batches.id', '=', 'import_rows.import_id')->join('myabi_versions', 'myabi_versions.id', '=', 'import_batches.version_id')
            ->where('term_id', $term->id)->where('import_batches.status', 'applied')->select('import_rows.*', 'myabi_versions.number as version_number', 'import_batches.filename', 'import_batches.created_at')->orderByDesc('import_id')->paginate(25, ['*'], 'presence_page');
        $comparison = null;
        $restoreRevision = null;
        if ($request->filled('restore_revision')) {
            $restoreRevision = $revisionTerm->revisions()->where('number', (int) $request->query('restore_revision'))->firstOrFail();
        }
        if ($request->filled('from') && $request->filled('to')) {
            foreach (['from', 'to'] as $side) {
                $revision = $revisionTerm->revisions()->where('number', (int) $request->query($side))->firstOrFail();
                $comparison[$side] = ['revision' => $revision, 'attributes' => $source->attributes($revision), 'validated' => $revision->validated];
            }
        }
        $events = AuditEvent::with('user')->where(function ($q) use ($term, $lineageIds) {
            $q->where(fn ($q) => $q->where('entity_type', Term::class)->whereIn('entity_id', $lineageIds->prepend($term->id)->map(fn ($id) => (string) $id)))
                ->orWhere(fn ($q) => $q->where('entity_type', Proposal::class)->whereIn('entity_id', $term->proposals()->select('id')));
        })->orderByDesc('id')->paginate(30, ['*'], 'history_page');

        $groups = TermGroup::visibleTo($request->user())->orderBy('title')->get();

        return view('terms.show', [...self::filters(), ...compact('term', 'attributes', 'proposals', 'revisions', 'presences', 'comparison', 'language', 'events', 'restoreRevision', 'groups', 'lineages', 'lineageId', 'historicalLineage'), 'sourceMetadata' => $source->row($term)]);
    }
}
