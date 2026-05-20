<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AppApiResponseFactory;
use App\Services\App\AppSessionReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SessionController extends Controller
{
    public function __invoke(Request $request, AppSessionReadModel $sessionReadModel): JsonResponse
    {
        $user = $request->user();

        if (!$user instanceof User) {
            throw new ApiException('未登录或登陆已过期', 403);
        }

        return AppApiResponseFactory::success($sessionReadModel->forUser($user));
    }
}
