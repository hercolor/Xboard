<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ApiRequestSizeGuard
{
    /**
     * @param Closure(Request): mixed $next
     */
    public function handle(Request $request, Closure $next, string $channel = 'default'): mixed
    {
        if (!(bool) config('api_security.request_size.enabled', true)) {
            return $next($request);
        }

        $maxBytes = $this->maxBytes($channel);
        if ($maxBytes <= 0 || $this->requestSize($request) <= $maxBytes) {
            return $next($request);
        }

        throw new HttpException(413, 'Payload Too Large');
    }

    private function maxBytes(string $channel): int
    {
        $key = match ($channel) {
            'passport' => 'passport_max_bytes',
            'admin' => 'admin_max_bytes',
            'server' => 'server_max_bytes',
            'callback' => 'callback_max_bytes',
            'app' => 'app_max_bytes',
            default => 'default_max_bytes',
        };

        return (int) config("api_security.request_size.{$key}", 262144);
    }

    private function requestSize(Request $request): int
    {
        $contentLength = $request->server('CONTENT_LENGTH');
        if (is_numeric($contentLength)) {
            return (int) $contentLength;
        }

        if (!in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return 0;
        }

        return strlen($request->getContent());
    }
}
