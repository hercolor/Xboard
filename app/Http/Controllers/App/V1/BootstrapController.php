<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\V1;

use App\Http\Controllers\Controller;
use App\Support\AppApiResponseFactory;
use Illuminate\Http\JsonResponse;

final class BootstrapController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return AppApiResponseFactory::success([
            'api' => [
                'name' => 'app-bff',
                'version' => 'v1',
            ],
            'capabilities' => [
                'bootstrap' => true,
                'session' => true,
                'dashboard' => true,
            ],
        ]);
    }
}
