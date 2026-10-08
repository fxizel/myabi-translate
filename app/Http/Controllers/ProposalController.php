<?php

namespace App\Http\Controllers;

use App\Models\BulkOperation;
use App\Models\Proposal;
use App\Models\Term;
use App\Services\FilteredValidationService;
use App\Services\OperationLock;
use App\Services\ProposalFilter;
use App\Services\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProposalController extends Controller
{
    public function index(Request $request, ProposalFilter $filter, FilteredValidationService $filteredValidation)
    {
        abort_unless($request->user()->canValidateAny(), 403);
        $filters = $filter->fromQuery($request->user(), $request->query());
        $language = $filters['language'];
        $generation = (int) DB::table('catalogue_state')->where('id', 1)->value('generation');
        $q = $filter->query($request->user(), $filters)
            ->select(['id', 'term_id', 'attribute', 'language', 'value', 'lock_version', 'author_id', 'anomalies', 'created_at'])
            ->with(['term:id,type,label,source_text,validated,source_import_id,source_offset,source_length', 'term.sourceImport:id,path', 'author:id,name']);
        $showAll = $request->query('per_page') === 'all';
        $proposalTotal = $showAll ? (clone $q)->count() : null;
        $q->orderBy('created_at')->orderBy('id');
        $proposals = ($showAll
            ? $q->cursorPaginate(100, cursorName: 'cursor')
            : $q->paginate(min(100, max(10, (int) $request->query('per_page', 25)))))
            ->withQueryString();
        $proposalTotal ??= $proposals->total();
        $viewData = [...TermController::filters(), ...compact('proposals', 'language', 'showAll', 'proposalTotal')];

        if ($showAll && $request->expectsJson()) {
            return response()->json([
                'html' => view('validation.rows', $viewData)->render(),
                'next_url' => $proposals->nextPageUrl(),
            ]);
        }

        $filteredSnapshot = $filteredValidation->snapshot($request->user(), $filters, $proposalTotal, $generation);
        $filteredOperations = BulkOperation::where('type', 'filtered_validation')->where('user_id', $request->user()->id)
            ->latest('id')->limit(5)->get();

        return view('validation.index', [...$viewData, ...compact('filteredSnapshot', 'filteredOperations')]);
    }

    public function validateFiltered(Request $request, FilteredValidationService $validation)
    {
        abort_unless($request->user()->canValidateAny(), 403);
        $data = $request->validate(['snapshot_token' => 'required|string|max:10000', 'confirmed' => 'accepted', 'override' => 'nullable|boolean']);
        $operation = $validation->enqueue($request->user(), $data['snapshot_token'], $request->boolean('override'));

        return redirect()->route('validation.index', $this->filterParameters($operation))->with('status', __('ui.filtered_queued'));
    }

    public function retryFiltered(Request $request, BulkOperation $operation, FilteredValidationService $validation)
    {
        $request->validate(['confirmed' => 'accepted']);
        $validation->retry($request->user(), $operation);

        return redirect()->route('validation.index', $this->filterParameters($operation))->with('status', __('ui.filtered_retry_queued'));
    }

    private function filterParameters(BulkOperation $operation): array
    {
        $filters = $operation->preview['filters'];
        if (isset($filters['overdue_before'])) {
            $filters['overdue'] = 1;
            unset($filters['overdue_before']);
        }

        return $filters;
    }

    public function store(Request $request, Term $term, WorkflowService $workflow)
    {
        $d = $request->validate(['attribute' => 'required|string|max:60', 'language' => 'required|in:de,fr,it,en', 'value' => 'required|string|max:1000000', 'lock_version' => 'required|integer', 'supersedes_id' => 'nullable|integer', 'restored_revision_id' => 'nullable|integer']);
        $workflow->propose($request->user(), $term, $d['attribute'], $d['language'], $d['value'], (int) $d['lock_version'], isset($d['supersedes_id']) ? (int) $d['supersedes_id'] : null, isset($d['restored_revision_id']) ? (int) $d['restored_revision_id'] : null);

        return back()->with('status', __('ui.proposal_saved'));
    }

    public function update(Request $request, Proposal $proposal, WorkflowService $workflow)
    {
        $d = $request->validate(['value' => 'required|string|max:1000000', 'lock_version' => 'required|integer']);
        $workflow->edit($request->user(), $proposal, $d['value'], (int) $d['lock_version']);

        return back()->with('status', __('ui.proposal_saved'));
    }

    public function decide(Request $request, Proposal $proposal, WorkflowService $workflow)
    {
        $d = $request->validate(['decision' => 'required|in:validate,reject', 'lock_version' => 'required|integer', 'reason' => 'required_if:decision,reject|nullable|string|max:4000', 'reason_category' => 'nullable|in:other,glossary,meaning,spelling,length,placeholder,superseded', 'override' => 'nullable|boolean']);
        $reason = trim(($request->input('reason_category') ? $request->input('reason_category').': ' : '').($d['reason'] ?? ''));
        $workflow->decide($request->user(), $proposal, $d['decision'], (int) $d['lock_version'], $reason, $request->boolean('override'));

        return back()->with('status', __('ui.decision_saved'));
    }

    public function bulk(Request $request, WorkflowService $workflow)
    {
        $d = $request->validate(['decision' => 'required|in:validate,reject', 'reason' => 'nullable|string|max:4000', 'selection' => 'nullable|array|max:500', 'selected' => 'nullable|array|max:500', 'versions' => 'nullable|array|max:500', 'confirmed' => 'accepted']);
        $selection = [];
        foreach ($request->input('ids', []) as $id) {
            $selection[(int) $id] = (int) $request->input('lock_versions.'.$id, 0);
        }
        foreach ($request->input('selected', []) as $id) {
            $selection[(int) $id] = (int) $request->input('versions.'.$id, 0);
        }
        foreach ($request->input('selection', []) as $id => $version) {
            $selection[(int) $id] = (int) $version;
        }
        $workflow->bulk($request->user(), $selection, $d['decision'], $d['reason'] ?? null, $request->boolean('override'));

        return back()->with('status', __('ui.decision_saved'));
    }

    public function bulkStore(Request $request, WorkflowService $workflow, OperationLock $lock)
    {
        $request->validate(['rows' => 'required|array|max:100', 'confirmed' => 'accepted']);
        $count = 0;
        $lock->run(fn () => DB::transaction(function () use ($request, $workflow, &$count) {
            foreach ($request->input('rows') as $id => $row) {
                if (empty($row['selected'])) {
                    continue;
                }
                validator($row, ['attribute' => 'required|string', 'language' => 'required|in:de,fr,it,en', 'value' => 'required|string|max:1000000', 'lock_version' => 'required|integer'])->validate();
                $workflow->propose($request->user(), Term::findOrFail($id), $row['attribute'], $row['language'], $row['value'], (int) $row['lock_version']);
                $count++;
            }
            abort_if($count === 0, 422);
        }));

        return back()->with('status', __('ui.proposal_saved'));
    }

    public function confirm(Request $request, Term $term, WorkflowService $workflow)
    {
        $d = $request->validate(['attribute' => 'required|string', 'language' => 'required|in:de,fr,it,en', 'lock_version' => 'required|integer']);
        $workflow->confirm($request->user(), $term, $d['attribute'], $d['language'], (int) $d['lock_version']);

        return back()->with('status', __('ui.decision_saved'));
    }
}
