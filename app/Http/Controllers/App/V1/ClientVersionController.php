<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\V1;

use App\Http\Controllers\Controller;
use App\Services\App\ClientVersionService;
use App\Support\AppApiResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ClientVersionController extends Controller
{
    public function __invoke(Request $request, ClientVersionService $clientVersionService): JsonResponse
    {
        return AppApiResponseFactory::success($clientVersionService->catalogForRequest($request));
    }

    public function releases(Request $request, ClientVersionService $clientVersionService): JsonResponse
    {
        return response()->json($clientVersionService->githubCompatibleReleasesForRequest($request));
    }

    public function latest(Request $request, ClientVersionService $clientVersionService): JsonResponse
    {
        return response()->json($clientVersionService->githubCompatibleLatestForRequest($request));
    }

    public function appcast(ClientVersionService $clientVersionService): Response
    {
        return response($clientVersionService->appcastXml(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
