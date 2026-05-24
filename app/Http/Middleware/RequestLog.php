<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;

class RequestLog
{
    private const REDACTED = '[REDACTED]';

    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'secret',
        'key',
        'api_key',
        'access_token',
        'refresh_token',
        'auth_data',
        'authdata',
        'subscribe_url',
        'subscribeurl',
        'subscribe_token',
        'subscribetoken',
        'subscription_url',
        'subscriptionurl',
        'subscription_token',
        'subscriptiontoken',
        'authorization',
        'node_token',
        'server_token',
        'machine_token',
        'webhook_secret',
        'webhooksecret',
        'client_secret',
        'clientsecret',
        'private_key',
        'privatekey',
    ];

    public function handle($request, Closure $next)
    {
        if ($request->method() !== 'POST') {
            return $next($request);
        }

        $response = $next($request);

        try {
            $admin = $request->user();
            if (!$admin || !$admin->is_admin) {
                return $response;
            }

            $action = $this->resolveAction($request->path());
            $data = self::redactSensitiveData($request->all());

            AdminAuditLog::insert([
                'admin_id' => $admin->id,
                'action' => $action,
                'method' => $request->method(),
                'uri' => $request->getRequestUri(),
                'request_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'ip' => $request->getClientIp(),
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Audit log write failed: ' . $e->getMessage());
        }

        return $response;
    }

    /**
     * Redact sensitive request data before it is persisted to admin audit logs.
     *
     * This is intentionally recursive because secrets are often nested under
     * provider config objects (payment/webhook/node/plugin settings).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function redactSensitiveData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (self::isSensitiveKey((string) $key)) {
                $data[$key] = self::REDACTED;
                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::redactSensitiveData($value);
            }
        }

        return $data;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '.'], '_', $key));

        if (in_array($normalized, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        return str_contains($normalized, 'password')
            || str_contains($normalized, 'authorization')
            || str_ends_with($normalized, '_token')
            || str_ends_with($normalized, '_secret')
            || str_ends_with($normalized, '_key')
            || str_ends_with($normalized, '_auth')
            || str_ends_with($normalized, '_credential');
    }

    private function resolveAction(string $path): string
    {
        // api/v2/{secure_path}/user/update → user.update
        $path = preg_replace('#^api/v[12]/[^/]+/#', '', $path);
        // gift-card/create-template → gift_card.create_template
        $path = str_replace('-', '_', $path);
        // user/update → user.update, server/manage/sort → server_manage.sort
        $segments = explode('/', $path);
        $method = array_pop($segments);
        $resource = implode('_', $segments);

        return $resource . '.' . $method;
    }
}

