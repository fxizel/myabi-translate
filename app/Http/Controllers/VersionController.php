<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Services\AuditService;
use App\Services\OperationLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VersionController extends Controller
{
    public function __construct(private OperationLock $lock, private AuditService $audit) {}

    public function store(Request $request)
    {
        abort_unless($request->user()->canManage(), 403);
        $values = $this->values($request);
        $this->lock->run(fn () => DB::transaction(function () use ($request, $values) {
            DB::table('catalogue_state')->where('id', 1)->lockForUpdate()->first();
            if ($values['status'] === 'current') {
                MyabiVersion::where('status', 'current')->update(['status' => 'archived', 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            }
            $version = MyabiVersion::create([...$values, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $this->audit->record('version.created', $version, [], $values, $request->user());
        }));

        return redirect()->route('publications.index')->with('status', __('ui.saved'));
    }

    public function update(Request $request, MyabiVersion $version)
    {
        abort_unless($request->user()->canManage(), 403);
        $values = $this->values($request, $version);
        $this->lock->run(fn () => DB::transaction(function () use ($request, $version, $values) {
            DB::table('catalogue_state')->where('id', 1)->lockForUpdate()->first();
            $version = MyabiVersion::whereKey($version->id)->lockForUpdate()->firstOrFail();
            if (ImportBatch::where('version_id', $version->id)->exists()
                && ($values['number'] !== $version->number || $values['released_at'] !== $version->released_at->format('Y-m-d'))) {
                throw ValidationException::withMessages(['version' => __('ui.version_identity_locked')]);
            }
            $before = $version->only(['number', 'released_at', 'status']);
            if ($values['status'] === 'current') {
                MyabiVersion::where('status', 'current')->where('id', '<>', $version->id)->update(['status' => 'archived', 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            }
            $version->update([...$values, 'updated_by' => $request->user()->id]);
            $this->audit->record('version.updated', $version, $before, $values, $request->user());
        }));

        return redirect()->route('publications.index')->with('status', __('ui.saved'));
    }

    private function values(Request $request, ?MyabiVersion $version = null): array
    {
        return $request->validate([
            'number' => ['required', 'string', 'max:60', 'regex:/^[\pL\pN][\pL\pN._-]*$/u', Rule::unique('myabi_versions', 'number')->ignore($version?->id)],
            'released_at' => ['required', 'date_format:Y-m-d'], 'status' => ['required', Rule::in(['preparation', 'current', 'archived'])],
        ]);
    }
}
