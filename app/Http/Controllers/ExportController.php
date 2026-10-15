<?php

namespace App\Http\Controllers;

use App\Domain\Devconf\FormatRegistry;
use App\Models\ImportBatch;
use App\Models\MyabiVersion;
use App\Services\SimpleExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExportController extends Controller
{
    public function index()
    {
        $appliedVersions = ImportBatch::where('status', 'applied')->select('version_id');
        $latest = MyabiVersion::whereIn('id', clone $appliedVersions)->max('released_at');
        $versions = MyabiVersion::whereIn('id', $appliedVersions)->where('status', '!=', 'archived')
            ->when($latest, fn ($query) => $query->whereDate('released_at', '>=', Carbon::parse($latest)->toDateString()))
            ->latest('released_at')->latest('id')->get();

        return view('exports.index', [
            'types' => TermController::typeLabels(), 'versions' => $versions,
        ]);
    }

    public function download(Request $request, SimpleExportService $service)
    {
        $parameters = $request->validate([
            'version_id' => ['required', 'integer', 'exists:myabi_versions,id'],
            'type' => ['required', 'string', Rule::in(array_keys(FormatRegistry::all()))],
        ]);
        $file = null;
        try {
            $file = $service->prepare($request->user(), (int) $parameters['version_id'], $parameters['type']);
            clearstatcache(true, $file['path']);

            return response()->download($file['path'], $file['filename'], [
                'Content-Type' => 'text/csv; charset=Windows-1252',
                'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            ])->deleteFileAfterSend(true);
        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            if ($file !== null && is_file($file['path'])) {
                unlink($file['path']);
            }
            // Parser errors may contain source values; only a neutral message is exposed.
            throw ValidationException::withMessages(['export' => __('ui.export_failed')]);
        }
    }
}
