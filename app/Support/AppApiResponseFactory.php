<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class AppApiResponseFactory
{
    /**
     * @param array<string, mixed> $meta
     */
    public static function success(mixed $data = null, string $message = 'ok', array $meta = []): JsonResponse
    {
        return response()->json(self::payload(true, 'OK', $message, $data, $meta));
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function error(
        string $code,
        string $message,
        int $status = 500,
        mixed $data = null,
        array $meta = []
    ): JsonResponse {
        return response()->json(self::payload(false, $code, $message, $data, $meta), $status);
    }

    public static function exception(Throwable $exception): JsonResponse
    {
        if ($exception instanceof ApiException) {
            $status = (int) substr((string) $exception->getCode(), 0, 3);

            return self::error(
                'APP_API_ERROR',
                $exception->getMessage(),
                $status >= 400 ? $status : 400,
                null,
                ['legacy_code' => $exception->getCode(), 'errors' => $exception->errors()]
            );
        }

        if ($exception instanceof HttpExceptionInterface) {
            return self::error(
                self::codeForStatus($exception->getStatusCode()),
                $exception->getMessage() ?: 'Request failed',
                $exception->getStatusCode()
            );
        }

        return self::error(
            'INTERNAL_ERROR',
            config('app.debug') ? $exception->getMessage() : 'Internal server error',
            500
        );
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function payload(bool $ok, string $code, string $message, mixed $data, array $meta): array
    {
        return [
            'ok' => $ok,
            'code' => $code,
            'message' => $message,
            'data' => $data ?? new \stdClass(),
            'meta' => self::meta($meta),
        ];
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function meta(array $meta): array
    {
        return array_replace([
            'trace_id' => self::traceId(),
            'server_time' => time(),
        ], $meta);
    }

    private static function traceId(): string
    {
        $request = request();
        $headerTraceId = $request->headers->get('X-Request-Id');

        if (is_string($headerTraceId) && trim($headerTraceId) !== '') {
            return trim($headerTraceId);
        }

        return (string) Str::uuid();
    }

    private static function codeForStatus(int $status): string
    {
        return match ($status) {
            401 => 'UNAUTHENTICATED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            422 => 'VALIDATION_ERROR',
            default => 'HTTP_ERROR',
        };
    }
}
