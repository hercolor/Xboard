<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiTraceId
{
    public const ATTRIBUTE = 'api_trace_id';
    public const HEADER = 'X-Request-Id';

    /**
     * @param Closure(Request): mixed $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $traceId = $this->traceId($request);

        $request->attributes->set(self::ATTRIBUTE, $traceId);
        $request->headers->set(self::HEADER, $traceId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $traceId);

        return $response;
    }

    private function traceId(Request $request): string
    {
        $headerTraceId = trim((string) $request->headers->get(self::HEADER, ''));

        if ($headerTraceId !== '' && strlen($headerTraceId) <= 128 && preg_match('/^[A-Za-z0-9._:-]+$/', $headerTraceId)) {
            return $headerTraceId;
        }

        return (string) Str::uuid();
    }
}
