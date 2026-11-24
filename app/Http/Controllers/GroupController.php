<?php

namespace App\Http\Controllers;

use App\Domain\Devconf\FormatRegistry;
use App\Models\Proposal;
use App\Models\Term;
use App\Models\TermGroup;
use App\Services\GroupService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GroupController extends Controller
{
    public const COLORS = ['#2266a4', '#32634d', '#825f20', '#53677b', '#102348', '#a12f2f'];

    public function index(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:200', 'archived' => 'nullable|boolean']);
        $query = TermGroup::visibleTo($request->user());
        $request->boolean('archived') ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        if (! empty($data['q'])) {
            $query->where('title', 'like', '%'.addcslashes($data['q'], '%_\\').'%');
        }

        $groups = $query->orderBy('title')->paginate(30)->withQueryString();
        if ($groups->isNotEmpty()) {
            // Explicit group IDs let MariaDB use the group index for the counts.
            $termCounts = Term::query()->whereIn('term_group_id', $groups->getCollection()->modelKeys())
                ->select('term_group_id')->selectRaw('COUNT(*) AS terms_count')
                ->groupBy('term_group_id')->pluck('terms_count', 'term_group_id');

            foreach ($groups as $group) {
                $group->terms_count = (int) ($termCounts[$group->id] ?? 0);
            }
        }

        return view('groups.index', compact('groups'));
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->canManage(), 403);

        return view('groups.form', ['group' => new TermGroup(['color' => self::COLORS[0]]), 'colors' => self::COLORS]);
    }

    public function store(Request $request, GroupService $service)
    {
        $group = $service->create($request->user(), $this->data($request));

        return redirect()->route('groups.show', $group)->with('status', __('ui.saved'));
    }

    public function show(Request $request, TermGroup $group)
    {
        $this->visible($request, $group);
        $language = $request->query('language');
        $language = in_array($language, ['de', 'fr', 'it', 'en'], true) ? $language : 'fr';
        $terms = $group->terms()->visibleTo($request->user())->with('lastPublication:id,status')->orderBy('label')->paginate(50)->withQueryString();
        $openAttributes = Proposal::openAttributesForTerms($terms->getCollection()->modelKeys(), $language);
        foreach ($terms as $term) {
            $attributes = FormatRegistry::get($term->type)->attributeNames;
            $attribute = $attributes[0];
            $states = $openAttributes->get($term->id, collect());
            $status = $states->get($attribute);
            $term->current_state = $term->state($attribute, $language, $status === 'pending', $status === Proposal::ARBITRATION);
            $term->secondary_arbitration = $term->obsolete ? [] : array_filter(array_slice($attributes, 1, preserve_keys: true),
                fn ($name) => $states->get($name) === Proposal::ARBITRATION);
        }
        $group->load(['campaigns' => fn ($query) => $query->visibleTo($request->user())->orderByDesc('id')]);

        return view('groups.show', compact('group', 'terms', 'language'));
    }

    public function edit(Request $request, TermGroup $group)
    {
        abort_unless($request->user()->canManage(), 403);
        $this->visible($request, $group);

        return view('groups.form', ['group' => $group, 'colors' => self::COLORS]);
    }

    public function update(Request $request, TermGroup $group, GroupService $service)
    {
        $service->update($request->user(), $group, $this->data($request));

        return redirect()->route('groups.show', $group)->with('status', __('ui.saved'));
    }

    public function assign(Request $request, GroupService $service)
    {
        $data = $request->validate(['group_id' => 'nullable|integer|exists:term_groups,id', 'term_ids' => 'required|array|min:1|max:500', 'term_ids.*' => 'required|integer|distinct|exists:terms,id']);
        $service->assignTerms($request->user(), isset($data['group_id']) ? TermGroup::findOrFail($data['group_id']) : null, $data['term_ids']);

        return back()->with('status', __('ui.saved'));
    }

    public function archive(Request $request, TermGroup $group, GroupService $service)
    {
        $service->archive($request->user(), $group);

        return back()->with('status', __('ui.saved'));
    }

    public function unarchive(Request $request, TermGroup $group, GroupService $service)
    {
        $service->unarchive($request->user(), $group);

        return back()->with('status', __('ui.saved'));
    }

    public function destroy(Request $request, TermGroup $group, GroupService $service)
    {
        $request->validate(['confirmed' => 'accepted']);
        $service->delete($request->user(), $group);

        return redirect()->route('groups.index')->with('status', __('ui.saved'));
    }

    private function data(Request $request): array
    {
        abort_unless($request->user()->canManage(), 403);

        $data = $request->validate(['title' => 'required|string|max:200', 'description' => 'nullable|string|max:10000', 'color' => ['nullable', Rule::in(self::COLORS)]]);

        return [...$data, 'color' => $data['color'] ?? self::COLORS[0]];
    }

    private function visible(Request $request, TermGroup $group): void
    {
        abort_unless(TermGroup::visibleTo($request->user())->whereKey($group->id)->exists(), 404);
    }
}
