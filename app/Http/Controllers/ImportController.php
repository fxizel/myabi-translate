<?php

namespace App\Http\Controllers;

use App\Domain\Devconf\CsvReader;
use App\Domain\Devconf\FormatRegistry;
use App\Domain\Devconf\TranslationValidator;
use App\Models\BulkOperation;
use App\Models\ImportBatch;
use App\Models\Proposal;
use App\Services\AuditService;
use App\Services\ImportService;
use App\Services\InitialValidationService;
use App\Services\LocalizedMessage;
use App\Services\OperationLock;
use App\Services\StorageBudget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportController extends Controller
{
    public function index(Request $request, StorageBudget $budget)
    {
        $q = ImportBatch::with('version', 'organization', 'author');
        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }

        return view('imports.index', [...TermController::filters(), 'imports' => $q->latest()->paginate(25)->withQueryString(), 'storage' => $budget->usage()]);
    }

    public function store(Request $request, ImportService $service, OperationLock $lock)
    {
        $d = $request->validate(['file' => 'required|file|max:102400', 'type' => 'required|in:'.implode(',', array_keys(FormatRegistry::all())),
            'version_id' => 'required|integer|exists:myabi_versions,id', 'organization_id' => 'nullable|integer|exists:organizations,id', 'source_kind' => 'required|in:publisher,organization']);
        if ($d['source_kind'] === 'organization' && empty($d['organization_id'])) {
            throw ValidationException::withMessages(['organization_id' => __('ui.organization_required')]);
        }
        $file = $d['file'];
        unset($d['file']);
        if ($d['source_kind'] === 'publisher') {
            $d['organization_id'] = null;
        }
        try {
            $import = $lock->run(fn () => $service->receive($file->getRealPath(), $file->getClientOriginalName(), $d, $request->user()));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return redirect()->route('imports.show', $import)->with('status', __('ui.import_queued'));
    }

    public function show(Request $request, ImportBatch $import)
    {
        $rows = DB::table('import_rows')->where('import_id', $import->id)->when($request->boolean('collisions'), fn ($q) => $q->where('collision', true))->orderBy('record_number')->paginate(50);
        $initial = Proposal::where('import_id', $import->id)->where('status', 'pending')->where('divergence', false)->count();
        $initialOperations = BulkOperation::with('author')->where('import_id', $import->id)->latest('id')->paginate(10, ['*'], 'operations_page');

        return view('imports.show', [...TermController::filters(), ...compact('import', 'rows', 'initial', 'initialOperations'), 'progress' => $this->progressData($import)]);
    }

    private function progressData(ImportBatch $import): array
    {
        if ($import->status === 'applied') {
            return ['stage' => 'applied', 'rows' => $import->row_count, 'total' => $import->row_count,
                'provisional' => false, 'status' => 'applied', 'row_count' => $import->row_count, 'error' => $import->error === null ? null : LocalizedMessage::display($import->error)];
        }
        $path = storage_path('app/private/progress/'.$import->id.'.json');
        $p = is_file($path) ? json_decode(file_get_contents($path), true) : [];

        return [...$p, 'status' => $import->status, 'row_count' => $import->row_count, 'error' => $import->error === null ? null : LocalizedMessage::display($import->error)];
    }

    public function progress(ImportBatch $import)
    {
        return response()->json($this->progressData($import));
    }

    public function apply(Request $request, ImportBatch $import, OperationLock $lock, AuditService $audit)
    {
        $request->validate(['confirmed' => 'accepted']);
        $lock->run(fn () => DB::transaction(function () use ($import, $audit) {
            $i = ImportBatch::whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($i->status !== 'analyzed') {
                abort(409);
            }
            $i->update(['status' => 'apply_queued', 'updated_by' => auth()->id()]);
            $audit->record('import.accepted', $i);
        }));

        return back()->with('status', __('ui.import_queued'));
    }

    public function cancel(ImportBatch $import, OperationLock $lock, AuditService $audit)
    {
        $lock->run(fn () => DB::transaction(function () use ($import, $audit) {
            $current = ImportBatch::whereKey($import->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($current->status, ['queued', 'analyzed', 'failed'], true), 409);
            $current->update(['status' => 'cancelled', 'updated_by' => auth()->id()]);
            $audit->record('import.cancelled', $current);
        }));

        return back();
    }

    public function retry(ImportBatch $import, OperationLock $lock, AuditService $audit)
    {
        $lock->run(fn () => DB::transaction(function () use ($import, $audit) {
            $current = ImportBatch::whereKey($import->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->status === 'failed', 409);
            $current->update(['status' => 'queued', 'error' => null, 'updated_by' => auth()->id()]);
            $audit->record('import.retried', $current);
        }));

        return back();
    }

    public function original(ImportBatch $import, AuditService $audit)
    {
        $audit->record('import.original_download', $import);

        return response()->download($import->originalPath(), $import->filename, ['Content-Type' => 'text/csv']);
    }

    public function report(ImportBatch $import)
    {
        return response()->streamDownload(function () use ($import) {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['record', 'key', 'category', 'collision', 'message'], ';', '"', '');
            $format = FormatRegistry::get($import->type);
            $reader = new CsvReader;
            foreach (DB::table('import_rows')->where('import_id', $import->id)->orderBy('id')->lazyById(300) as $p) {
                $row = $reader->readAt($import->originalPath(), $p->offset, $p->record_number, $p->length)->associative($format->headers);
                $messages = [];
                foreach ($format->attributes($row) as $attr => $a) {
                    foreach ($a['translations'] as $language => $value) {
                        if ($value !== '' && $language !== 'de') {
                            $check = TranslationValidator::validate($a['reference'], $value);
                            foreach ($check['errors'] as $error) {
                                $messages[] = "$attr/$language: $error";
                            }
                        }
                    }
                }
                $key = implode(' / ', $format->identity($row));
                if (preg_match('/^[=+@-]/', $key)) {
                    $key = "'".$key;
                }
                fputcsv($out, [$p->record_number, $key, $p->category, $p->collision ? 'yes' : 'no', implode(' | ', $messages)], ';', '"', '');
            } fclose($out);
        }, 'import-'.$import->id.'-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function validationPreview(Request $request, ImportBatch $import, InitialValidationService $service)
    {
        $request->validate(['language' => 'required|in:de,fr,it,en', 'override' => 'nullable|boolean', 'operation_id' => 'nullable|integer']);
        if (! $request->filled('operation_id')) {
            abort_unless($request->isMethod('post'), 405);
            $service->enqueuePreview($request->user(), $import, $request->input('language'), $request->boolean('override'));

            return redirect()->route('imports.show', $import)->with('status', __('ui.initial_preview_queued'));
        }
        $operation = BulkOperation::where('import_id', $import->id)->findOrFail($request->integer('operation_id'));
        $preview = $service->reviewedPreview($request->user(), $operation);
        $token = bin2hex(random_bytes(32));
        $previews = $request->session()->get('initial_validation_previews', []);
        $previews[$token] = ['user_id' => $request->user()->id, 'preview' => $preview];
        $request->session()->put('initial_validation_previews', array_slice($previews, -5, null, true));

        return view('imports.validation-preview', compact('import', 'preview', 'token'));
    }

    public function validateInitial(Request $request, ImportBatch $import, InitialValidationService $service)
    {
        $request->validate(['preview_token' => 'required|string|size:64', 'confirmed' => 'accepted']);
        $token = $request->input('preview_token');
        $stored = $request->session()->get('initial_validation_previews.'.$token);
        if (! $stored || $stored['user_id'] !== $request->user()->id) {
            throw ValidationException::withMessages(['validation' => __('ui.initial_preview_stale')]);
        }
        $service->enqueue($request->user(), $import, $stored['preview']);
        $request->session()->forget('initial_validation_previews.'.$token);

        return redirect()->route('imports.show', $import)->with('status', __('ui.initial_queued'));
    }

    public function retryInitial(Request $request, ImportBatch $import, BulkOperation $operation, InitialValidationService $service)
    {
        $request->validate(['confirmed' => 'accepted']);
        abort_unless($operation->import_id === $import->id, 404);
        $service->retry($request->user(), $operation);

        return redirect()->route('imports.show', $import)->with('status', __('ui.initial_queued'));
    }
}
