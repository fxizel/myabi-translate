<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Models\Term;
use App\Models\TermGroup;
use App\Models\TranslationCampaign;
use App\Models\User;
use App\Services\CampaignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CampaignController extends Controller
{
    public function prepare(Request $request)
    {
        abort_unless($request->user()->canManage(), 403);
        $data = $request->validate(['term_ids' => 'required|array|min:1|max:500', 'term_ids.*' => 'integer|distinct|exists:terms,id', 'language' => 'nullable|in:de,fr,it,en']);

        return redirect()->route('campaigns.create', $data);
    }

    public function compose(Request $request, CampaignService $service)
    {
        $data = $request->validate(['campaign_id' => 'required|integer|exists:translation_campaigns,id', 'term_ids' => 'required|array|min:1|max:500', 'term_ids.*' => 'integer|distinct|exists:terms,id']);
        $campaign = TranslationCampaign::findOrFail($data['campaign_id']);
        $report = $service->addDirectTerms($request->user(), $campaign, $data['term_ids']);

        return redirect()->route('campaigns.show', $campaign)->with('status', __('ui.saved'))->with('admission', $report);
    }

    public function index(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:200', 'language' => 'nullable|in:de,fr,it,en', 'objective' => 'nullable|in:translation,revision', 'group_id' => 'nullable|integer', 'assignee_id' => 'nullable|integer', 'mine' => 'nullable|boolean', 'overdue' => 'nullable|boolean', 'archived' => 'nullable|boolean']);
        $query = TranslationCampaign::visibleTo($request->user())->with('groups', 'assignees');
        $request->boolean('archived') ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        if (! empty($data['q'])) {
            $query->where('title', 'like', '%'.addcslashes($data['q'], '%_\\').'%');
        }
        foreach (['language', 'objective'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (! empty($data['group_id'])) {
            $query->whereHas('groups', fn ($q) => $q->where('term_groups.id', $data['group_id']));
        }
        if (! empty($data['assignee_id'])) {
            $query->whereHas('assignees', fn ($q) => $q->where('users.id', $data['assignee_id']));
        }
        if ($request->boolean('mine')) {
            $query->whereHas('assignees', fn ($q) => $q->where('users.id', $request->user()->id));
        }
        if ($request->boolean('overdue')) {
            $query->where('status', '!=', 'completed')->whereDate('deadline', '<', now()->timezone('Europe/Zurich')->toDateString());
        }
        $campaigns = $query->orderBy('deadline')->orderBy('title')->paginate(60)->withQueryString();
        $groups = TermGroup::visibleTo($request->user())->orderBy('title')->get();
        $assignees = User::whereIn('id', DB::table('campaign_assignees')->whereIn('translation_campaign_id', TranslationCampaign::visibleTo($request->user())->select('id'))->select('user_id'))->orderBy('name')->get(['id', 'name']);

        return view('campaigns.index', compact('campaigns', 'groups', 'assignees'));
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->canManage(), 403);
        $data = $request->validate(['term_ids' => 'nullable|array|max:500', 'term_ids.*' => 'integer|distinct|exists:terms,id', 'group_ids' => 'nullable|array|max:100', 'group_ids.*' => 'integer|distinct|exists:term_groups,id', 'language' => 'nullable|in:de,fr,it,en']);
        $campaign = new TranslationCampaign(['language' => $data['language'] ?? 'fr', 'objective' => 'translation']);

        return view('campaigns.form', [...$this->formData($request, $campaign), 'selectedTerms' => Term::visibleTo($request->user())->whereIn('id', $data['term_ids'] ?? [])->get(), 'selectedGroups' => $data['group_ids'] ?? []]);
    }

    public function store(Request $request, CampaignService $service)
    {
        $campaign = $service->create($request->user(), $this->data($request));

        return redirect()->route('campaigns.show', $campaign)->with('status', __('ui.saved'));
    }

    public function edit(Request $request, TranslationCampaign $campaign)
    {
        abort_unless($request->user()->canManage(), 403);
        $this->visible($request, $campaign);
        $campaign->load('groups', 'assignees');

        return view('campaigns.form', [...$this->formData($request, $campaign), 'selectedTerms' => collect(), 'selectedGroups' => $campaign->groups->pluck('id')->all()]);
    }

    public function update(Request $request, TranslationCampaign $campaign, CampaignService $service)
    {
        $service->update($request->user(), $campaign, $this->data($request, $campaign));

        return redirect()->route('campaigns.show', $campaign)->with('status', __('ui.saved'));
    }

    public function show(Request $request, TranslationCampaign $campaign, CampaignService $service)
    {
        $this->visible($request, $campaign);
        $request->validate(['participation' => 'nullable|in:active,removed', 'treatment' => 'nullable|in:to_process,to_arbitrate,proposal_sent,confirmed']);
        $campaign->load('groups', 'assignees');
        $query = $campaign->participations()->where('active', $request->query('participation') !== 'removed')->whereHas('term', fn ($q) => $q->visibleTo($request->user()))->with('term.group', 'trackedProposal');
        if ($request->filled('treatment')) {
            $query->where('status', $request->query('treatment'));
        }
        $participations = $query->orderBy('id')->paginate(30)->withQueryString();
        $pending = $participations->mapWithKeys(fn ($participation) => [$participation->term_id => $service->pertinentPending($participation->term, $campaign->language)]);
        $events = $campaign->events()->with('actor', 'participation:id,term_id', 'participation.term:id,label')->latest('id')->paginate(40, ['*'], 'history_page')->withQueryString();

        $historyProposalIds = $events->getCollection()->flatMap(fn ($event) => array_filter([$event->payload['proposal_id'] ?? null, $event->payload['previous_proposal_id'] ?? null]))->unique()->take(500);
        $historyProposals = Proposal::whereIn('id', $historyProposalIds)->whereIn('term_id', $campaign->participations()->select('term_id'))->get(['id', 'term_id', 'status'])->keyBy('id');

        return view('campaigns.show', ['historyProposals' => $historyProposals, 'campaign' => $campaign, 'participations' => $participations, 'pending' => $pending, 'events' => $events, 'canWorkCampaign' => $service->canWork($request->user(), $campaign), 'canReviewCampaign' => $service->canReview($request->user(), $campaign), 'campaignService' => $service]);
    }

    public function groups(Request $request, TranslationCampaign $campaign, CampaignService $service)
    {
        $data = $request->validate(['group_ids' => 'nullable|array|max:100', 'group_ids.*' => 'integer|distinct|exists:term_groups,id']);
        $report = $service->setGroups($request->user(), $campaign, $data['group_ids'] ?? []);

        return back()->with('status', __('ui.saved'))->with('admission', $report);
    }

    public function terms(Request $request, TranslationCampaign $campaign, CampaignService $service)
    {
        $data = $request->validate(['term_ids' => 'required|array|min:1|max:500', 'term_ids.*' => 'integer|distinct|exists:terms,id']);
        $report = $service->addDirectTerms($request->user(), $campaign, $data['term_ids']);

        return back()->with('status', __('ui.saved'))->with('admission', $report);
    }

    public function remove(Request $request, TranslationCampaign $campaign, Term $term, CampaignService $service)
    {
        $service->removeDirectTerm($request->user(), $campaign, $term);

        return back()->with('status', __('ui.saved'));
    }

    public function start(Request $request, TranslationCampaign $campaign, CampaignService $service)
    {
        $service->start($request->user(), $campaign);

        return back()->with('status', __('ui.saved'));
    }

    public function confirm(Request $request, TranslationCampaign $campaign, Term $term, CampaignService $service)
    {
        $data = $request->validate(['lock_version' => 'required|integer']);
        $service->confirm($request->user(), $campaign, $term, (int) $data['lock_version']);

        return back()->with('status', __('ui.saved'));
    }

    public function arbitration(Request $request, TranslationCampaign $campaign, Term $term, CampaignService $service)
    {
        $data = $request->validate(['reason' => 'required|string|max:4000']);
        $service->requestArbitration($request->user(), $campaign, $term, $data['reason']);

        return back()->with('status', __('ui.saved'));
    }

    public function resolve(Request $request, TranslationCampaign $campaign, Term $term, CampaignService $service)
    {
        $data = $request->validate(['outcome' => 'required|in:to_process,proposal_sent,confirmed', 'reason' => 'required|string|max:4000', 'proposal_id' => 'nullable|integer|exists:proposals,id']);
        $service->resolveArbitration($request->user(), $campaign, $term, $data['outcome'], $data['reason'], isset($data['proposal_id']) ? (int) $data['proposal_id'] : null);

        return back()->with('status', __('ui.saved'));
    }

    public function attach(Request $request, TranslationCampaign $campaign, Term $term, CampaignService $service)
    {
        $data = $request->validate(['proposal_id' => 'required|integer|exists:proposals,id']);
        $service->attachProposal($request->user(), $campaign, $term, Proposal::findOrFail($data['proposal_id']));

        return back()->with('status', __('ui.saved'));
    }

    public function propose(Request $request, TranslationCampaign $campaign, Term $term, CampaignService $service)
    {
        $data = $request->validate(['value' => 'required|string|max:1000000', 'lock_version' => 'required|integer']);
        $service->propose($request->user(), $campaign, $term, $data['value'], (int) $data['lock_version']);

        return back()->with('status', __('ui.proposal_saved'));
    }

    public function reexamine(Request $request, TranslationCampaign $campaign, Term $term, CampaignService $service)
    {
        $data = $request->validate(['reason' => 'required|string|max:4000']);
        $service->reexamine($request->user(), $campaign, $term, $data['reason']);

        return back()->with('status', __('ui.saved'));
    }

    public function archive(Request $request, TranslationCampaign $campaign, CampaignService $service)
    {
        $service->archive($request->user(), $campaign);

        return back()->with('status', __('ui.saved'));
    }

    public function unarchive(Request $request, TranslationCampaign $campaign, CampaignService $service)
    {
        $service->unarchive($request->user(), $campaign);

        return back()->with('status', __('ui.saved'));
    }

    public function destroy(Request $request, TranslationCampaign $campaign, CampaignService $service)
    {
        $request->validate(['confirmed' => 'accepted']);
        $service->delete($request->user(), $campaign);

        return redirect()->route('campaigns.index')->with('status', __('ui.saved'));
    }

    private function data(Request $request, ?TranslationCampaign $campaign = null): array
    {
        abort_unless($request->user()->canManage(), 403);

        $data = $request->validate(['title' => 'required|string|max:200', 'description' => 'nullable|string|max:10000', 'objective' => 'required|in:translation,revision', 'language' => 'required|in:de,fr,it,en', 'deadline' => 'nullable|date_format:Y-m-d', 'assignee_ids' => 'nullable|array|max:100', 'assignee_ids.*' => 'integer|distinct|exists:users,id', 'group_ids' => 'nullable|array|max:100', 'group_ids.*' => 'integer|distinct|exists:term_groups,id', 'term_ids' => 'nullable|array|max:500', 'term_ids.*' => 'integer|distinct|exists:terms,id']);

        $data['assignee_ids'] ??= [];
        if ($campaign === null || ($campaign->status !== 'completed' && $campaign->archived_at === null)) {
            $data['group_ids'] ??= [];
        }

        return $data;
    }

    private function formData(Request $request, TranslationCampaign $campaign): array
    {
        $groups = TermGroup::visibleTo($request->user())->where(fn ($q) => $q->whereNull('archived_at')->when($campaign->exists, fn ($q) => $q->orWhereIn('id', $campaign->groups()->select('term_groups.id'))))->orderBy('title')->get();
        $assignees = User::where('active', true)->where('is_technical', false)->orderBy('name')->get()->filter(fn ($user) => $user->canValidateAny() || collect(User::LANGUAGES)->contains(fn ($language) => $user->canTranslate($language)));

        return compact('campaign', 'groups', 'assignees');
    }

    private function visible(Request $request, TranslationCampaign $campaign): void
    {
        abort_unless(TranslationCampaign::visibleTo($request->user())->whereKey($campaign->id)->exists(), 404);
    }
}
