<?php

namespace App\Http\Controllers;

use App\Services\BrowserExtension\FeedbackService;
use App\Services\BrowserExtension\RequestValidator;
use App\Services\BrowserExtension\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BrowserExtensionController extends Controller
{
    public function search(Request $request, RequestValidator $validator, SearchService $search, FeedbackService $feedback): JsonResponse
    {
        $data = $validator->search($request->json()->all());
        $response = DB::transaction(fn () => $feedback->decorate($request->user(), $search->search($request->user(), $data)), 3);

        return response()->json($response);
    }

    public function feedback(Request $request, RequestValidator $validator, FeedbackService $feedback): JsonResponse
    {
        $data = $validator->feedback($request->json()->all());
        $feedback->submit($request->user(), $data);

        return response()->json(['ok' => true]);
    }
}
