<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiCacheHeaders
{
    /**
     * @param Closure(Request): mixed $next
     */
    public function handle(Request $request, Closure $next, string $profile = 'default'): mixed
    {
        $response = $next($request);

        if (!(bool) config('api_performance.cache_headers.enabled', true)) {
            return $response;
        }

        $maxAge = $this->maxAge($profile);
        if ($maxAge <= 0) {
            return $response;
        }

        $response->headers->set('Cache-Control', sprintf(
            'public, max-age=%d, stale-while-revalidate=%d',
            $maxAge,
            $maxAge
        ));
        $response->headers->set('Vary', 'Accept');

        return $response;
    }

    private function maxAge(string $profile): int
    {
        return match ($profile) {
            'bootstrap' => (int) config('api_performance.cache_headers.bootstrap_max_age', 300),
            'guest-config' => (int) config('api_performance.cache_headers.guest_config_max_age', 60),
            default => 0,
        };
    }
}
