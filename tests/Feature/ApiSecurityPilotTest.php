<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\AppApiResponseBoundary;
use App\Http\Middleware\InitializePlugins;
use App\Http\Middleware\RequestLog;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class ApiSecurityPilotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Route/limiter contract tests do not need plugin boot and should stay
        // independent from runtime plugin database state.
        $this->withoutMiddleware(InitializePlugins::class);
    }

    public function test_security_pilot_rate_limiters_are_registered_and_have_a_kill_switch(): void
    {
        config(['api_security.rate_limits.enabled' => true]);

        foreach (['passport-login', 'passport-email', 'user-read', 'app-read'] as $name) {
            $limiter = RateLimiter::limiter($name);
            $this->assertNotNull($limiter, sprintf('Expected [%s] limiter to be registered.', $name));

            $limit = $limiter(Request::create('/api/security-pilot', 'POST', ['email' => 'Pilot@Example.INVALID']));
            $this->assertInstanceOf(Limit::class, $limit);
        }

        config(['api_security.rate_limits.enabled' => false]);

        $disabledLimit = RateLimiter::limiter('passport-login')(
            Request::create('/api/security-pilot', 'POST', ['email' => 'pilot@example.invalid'])
        );

        $this->assertInstanceOf(Unlimited::class, $disabledLimit);
    }

    public function test_rate_limit_pilot_is_route_scoped_not_global(): void
    {
        foreach (['v1', 'v2'] as $version) {
            $this->assertRouteContainsMiddleware('POST', "api/{$version}/passport/auth/login", 'throttle:passport-login');
            $this->assertRouteContainsMiddleware('POST', "api/{$version}/passport/comm/sendEmailVerify", 'throttle:passport-email');
            $this->assertRouteContainsMiddleware('GET', "api/{$version}/user/info", 'throttle:user-read');
        }

        $this->assertRouteContainsMiddleware('GET', 'api/app/v1/bootstrap', AppApiResponseBoundary::class);
        $this->assertRouteContainsMiddleware('GET', 'api/app/v1/bootstrap', 'throttle:app-read');
        $this->assertRouteContainsMiddleware('GET', 'api/app/v1/session', 'user');
        $this->assertRouteContainsMiddleware('GET', 'api/app/v1/session', 'throttle:app-read');
        $this->assertRouteContainsMiddleware('GET', 'api/app/v1/dashboard', 'user');
        $this->assertRouteContainsMiddleware('GET', 'api/app/v1/dashboard', 'throttle:app-read');

        foreach ([
            ['GET', 'api/v1/client/subscribe'],
            ['GET', admin_setting('subscribe_path', 's') . '/{token}'],
            ['GET', 'api/v1/guest/payment/notify/{method}/{uuid}'],
            ['POST', 'api/v1/guest/payment/notify/{method}/{uuid}'],
            ['POST', 'api/v1/guest/telegram/webhook'],
            ['GET', 'api/v2/server/config'],
            ['POST', 'api/v2/server/report'],
        ] as [$method, $uri]) {
            $route = $this->findRoute($method, $uri);
            $this->assertNotNull($route, sprintf('Expected no-touch route [%s %s] to remain mounted.', $method, $uri));

            foreach ($route->gatherMiddleware() as $middleware) {
                $this->assertFalse(str_starts_with($middleware, 'throttle:'), sprintf(
                    'No-touch route [%s %s] must stay outside this rate-limit pilot.',
                    $method,
                    $uri
                ));
            }
        }
    }

    public function test_admin_audit_redaction_is_recursive_and_preserves_safe_fields(): void
    {
        $redacted = RequestLog::redactSensitiveData([
            'name' => 'safe plan',
            'password' => 'plain-password',
            'auth_data' => 'Bearer secret-auth-token',
            'payment' => [
                'api_key' => 'provider-api-key',
                'public_name' => 'Stripe',
                'nested' => [
                    'webhook-secret' => 'whsec_secret',
                    'subscribeUrl' => 'https://example.invalid/s/token',
                    'description' => 'safe description',
                ],
            ],
            'servers' => [
                ['node_token' => 'node-secret', 'host' => '198.51.100.1'],
            ],
        ]);

        $this->assertSame('safe plan', $redacted['name']);
        $this->assertSame('[REDACTED]', $redacted['password']);
        $this->assertSame('[REDACTED]', $redacted['auth_data']);
        $this->assertSame('[REDACTED]', $redacted['payment']['api_key']);
        $this->assertSame('Stripe', $redacted['payment']['public_name']);
        $this->assertSame('[REDACTED]', $redacted['payment']['nested']['webhook-secret']);
        $this->assertSame('[REDACTED]', $redacted['payment']['nested']['subscribeUrl']);
        $this->assertSame('safe description', $redacted['payment']['nested']['description']);
        $this->assertSame('[REDACTED]', $redacted['servers'][0]['node_token']);
        $this->assertSame('198.51.100.1', $redacted['servers'][0]['host']);
    }

    private function assertRouteContainsMiddleware(string $method, string $uri, string $expectedMiddleware): void
    {
        $route = $this->findRoute($method, $uri);

        $this->assertNotNull($route, sprintf('Expected route [%s %s] to be mounted.', $method, $uri));
        $this->assertContains($expectedMiddleware, $route->gatherMiddleware(), sprintf(
            'Expected route [%s %s] to include middleware [%s].',
            $method,
            $uri,
            $expectedMiddleware
        ));
    }

    private function findRoute(string $method, string $uri): ?Route
    {
        $method = strtoupper($method);
        $uri = trim($uri, '/');

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (trim($route->uri(), '/') === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }
}
