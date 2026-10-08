<?php

namespace App\Http\Controllers;

use App\Domain\Devconf\FormatRegistry;
use App\Models\MyabiVersion;
use App\Models\Organisation;
use App\Models\Publication;
use App\Services\AuditService;
use App\Services\OperationLock;
use App\Services\PublicationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PublicationController extends Controller
{
    public function __construct(private PublicationService $service, private AuditService $audit) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Publication::with(['version', 'author'])->latest('id');
        if (! $user->canManage()) {
            $query->whereJsonContains('recipients', (int) $user->organization_id);
        }
        if ($request->filled('version_id')) {
            $query->where('version_id', $request->integer('version_id'));
        }
        if (in_array($request->input('status'), ['queued', 'building', 'published', 'failed', 'withdrawn'], true)) {
            $query->where('status', $request->input('status'));
        }
        $formats = FormatRegistry::all();

        return view('publications.index', [
            'publications' => $query->paginate(30)->withQueryString(),
            'versions' => MyabiVersion::latest('released_at')->get(),
            'organizations' => Organisation::where('active', true)->orderBy('code')->get(),
            'formats' => $formats, 'types' => TermController::typeLabels(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->canManage(), 403);
        $parameters = $request->validate([
            'version_id' => ['required', 'integer', 'exists:myabi_versions,id'],
            'types' => ['required', 'array', 'min:1', 'max:7'],
            'types.*' => ['required', 'string', 'distinct', Rule::in(array_keys(FormatRegistry::all()))],
            'recipients' => ['sometimes', 'array', 'min:1', 'max:100'],
            'recipients.*' => ['integer', 'distinct', Rule::exists('organizations', 'id')->where('active', true)],
            'note' => ['nullable', 'string', 'max:5000'], 'confirm' => ['accepted'],
        ]);
        $publication = $this->service->enqueue($request->user(), $parameters);

        return redirect()->route('publications.show', $publication)->with('status', __('ui.publication_queued'));
    }

    public function show(Request $request, Publication $publication)
    {
        $this->authorizeRecipient($request, $publication);
        $publication->load(['version', 'author']);
        $manifest = $publication->manifest ?? [];
        if (! $request->user()->canManage() && isset($manifest['recipients'])) {
            $manifest['recipients'] = array_intersect_key($manifest['recipients'], [(int) $request->user()->organization_id => true]);
        }
        // Views receive only the permitted recipient's manifest, including when they use the model.
        $publication->setAttribute('manifest', $manifest);
        $organizationIds = $request->user()->canManage() ? $publication->recipients : [(int) $request->user()->organization_id];

        return view('publications.show', ['publication' => $publication, 'manifest' => $manifest,
            'organizations' => Organisation::whereIn('id', $organizationIds)->orderBy('code')->get(),
            'downloads' => $manifest['recipients'] ?? [], 'formats' => FormatRegistry::all()]);
    }

    public function download(Request $request, Publication $publication)
    {
        $this->authorizeRecipient($request, $publication);
        $request->validate(['organization_id' => ['nullable', 'integer'], 'withdrawn' => ['nullable', 'boolean']]);
        if (! in_array($publication->status, ['published', 'withdrawn'], true)) {
            throw ValidationException::withMessages(['publication' => __('ui.publication_not_ready')]);
        }
        if ($publication->status === 'withdrawn' && ! $request->boolean('withdrawn')) {
            throw ValidationException::withMessages(['withdrawn' => __('ui.publication_withdrawn_confirmation')]);
        }
        $user = $request->user();
        $organizationId = $request->integer('organization_id', (int) $user->organization_id);
        abort_unless($user->canManage() || $organizationId === (int) $user->organization_id, 403);
        abort_unless(in_array($organizationId, array_map('intval', $publication->recipients), true), 403);
        $recipient = $publication->manifest['recipients'][$organizationId] ?? null;
        abort_unless($recipient && isset($recipient['archive']), 404);
        $archive = $recipient['archive'];
        $path = $this->service->artifactPath($publication, $archive['path']);
        abort_unless(is_file($path) && filesize($path) === $archive['bytes'] && hash_file('sha256', $path) === $archive['sha256'], 503);
        $this->audit->record('publication.downloaded', $publication, [], ['organization_id' => $organizationId,
            'types' => $publication->types, 'sha256' => $archive['sha256'], 'withdrawn_explicit' => $publication->status === 'withdrawn',
            'files' => array_map(fn ($file) => ['type' => $file['type'], 'source_import_id' => $file['source_import_id'], 'format_version' => $file['format_version'], 'records' => $file['records'], 'sha256' => $file['sha256']], $recipient['files'])], $user);

        return response()->download($path, $archive['name'], ['Content-Type' => 'application/zip', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function withdraw(Request $request, Publication $publication)
    {
        abort_unless($request->user()->canManage(), 403);
        $parameters = $request->validate(['reason' => ['required', 'string', 'max:3000'], 'confirm' => ['accepted']]);
        $this->service->withdraw($request->user(), $publication, $parameters['reason']);

        return redirect()->route('publications.show', $publication)->with('status', __('ui.publication_withdrawn'));
    }

    private function authorizeRecipient(Request $request, Publication $publication): void
    {
        $user = $request->user();
        abort_unless($user->active && ! $user->is_technical
            && ($user->canManage() || in_array((int) $user->organization_id, array_map('intval', $publication->recipients), true)), 403);
    }

    public function retry(Request $request, Publication $publication, OperationLock $lock)
    {
        abort_unless($request->user()->canManage(), 403);
        $request->validate(['confirm' => 'accepted']);
        $lock->run(function () use ($publication) {
            abort_unless($publication->fresh()->status === 'failed', 409);
            $publication->update(['status' => 'queued', 'error' => null, 'updated_by' => auth()->id()]);
            $this->audit->record('publication.retry_requested', $publication);
        });

        return back()->with('status', __('ui.publication_queued'));
    }
}
