<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AppApiResponseFactory;
use Closure;
use Illuminate\Http\Request;
use Throwable;

final class AppApiResponseBoundary
{
    /**
     * @param Closure(Request): mixed $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        try {
            return $next($request);
        } catch (Throwable $exception) {
            if (!$request->is('api/app/v1/*')) {
                throw $exception;
            }

            return AppApiResponseFactory::exception($exception);
        }
    }
}
