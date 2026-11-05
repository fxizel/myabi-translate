<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Models\Term;
use App\Services\ProposalScreenshots;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProposalScreenshotController extends Controller
{
    public function show(Request $request, Proposal $proposal, ProposalScreenshots $screenshots)
    {
        return $this->image($request, $proposal, $screenshots, false);
    }

    public function thumbnail(Request $request, Proposal $proposal, ProposalScreenshots $screenshots)
    {
        return $this->image($request, $proposal, $screenshots, true);
    }

    private function image(Request $request, Proposal $proposal, ProposalScreenshots $screenshots, bool $thumbnail)
    {
        abort_unless($request->user()->canSeeCatalogue(), 403);
        abort_unless(Term::visibleTo($request->user())->whereKey($proposal->term_id)->exists(), 404);
        abort_unless($proposal->screenshot_path, 404);
        $version = $request->query('version');
        abort_unless(is_string($version) && ctype_digit($version) && $request->integer('version') === $proposal->lock_version, 409);
        try {
            $path = $screenshots->absolute($proposal->screenshot_path, $thumbnail);
        } catch (ValidationException) {
            abort(404);
        }
        $handle = @fopen($path, 'rb');
        abort_unless($handle, 404);
        $length = fstat($handle)['size'];
        $mime = $thumbnail ? 'image/png' : $proposal->screenshot_mime;
        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            fclose($handle);
            abort(404);
        }

        return response()->stream(function () use ($handle) {
            try {
                fpassthru($handle);
            } finally {
                fclose($handle);
            }
        }, 200, ['Content-Type' => $mime, 'Content-Length' => (string) $length,
            'Content-Disposition' => 'inline; filename="screenshot.'.($mime === 'image/png' ? 'png' : 'jpg').'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
