<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

trait SavesProposals
{
    private function saveProposal(Request $request, callable $save, bool $created = false)
    {
        try {
            $proposal = $save();
        } catch (ValidationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (\Throwable $error) {
            // SQL exception messages can contain the proposed text; never report the exception.
            Log::warning('Proposal submission failed.', ['error_type' => $error::class]);
            throw ValidationException::withMessages(['screenshot' => __('screenshots.save_failed')]);
        }
        if ($request->expectsJson()) {
            return response()->json(['id' => $proposal->id, 'version' => $proposal->lock_version, 'message' => __('ui.proposal_saved')], $created ? 201 : 200);
        }

        return back()->with('status', __('ui.proposal_saved'));
    }

    private function screenshotRules(): array
    {
        return ['screenshot_action' => 'sometimes|required|in:keep,replace,remove', 'screenshot' => 'nullable|file'];
    }
}
