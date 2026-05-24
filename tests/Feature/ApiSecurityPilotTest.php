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
use Illuminate\Support\Facades\Route as RouteFacade;
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

        foreach ([
            'passport-login',
            'passport-email',
            'user-read',
            'app-read',
            'admin-login',
            'admin-api',
            'subscription',
            'server-node',
            'server-machine',
            'callback',
        ] as $name) {
            $limiter = RateLimiter::limiter($name);
            $this->assertNotNull($limiter, sprintf('Expected [%s] limiter to be registered.', $name));

            $limit = $limiter(Request::create('/api/security-pilot', 'POST', ['email' => 'Pilot@Example.INVALID']));
            $this->assertInstanceOf(Limit::class, $limit);
        }

        config(['api_security.rate_limits.enabled' => false]);

        foreach ([
            'passport-login',
            'passport-email',
            'user-read',
            'app-read',
            'admin-login',
            'admin-api',
            'subscription',
            'server-node',
            'server-machine',
            'callback',
        ] as $name) {
            $disabledLimit = RateLimiter::limiter($name)(
                Request::create('/api/security-pilot', 'POST', [
                    'email' => 'pilot@example.invalid',
                    'token' => 'secret-token-for-keying',
                    'node_id' => 1,
                    'machine_id' => 1,
                ])
            );

            $this->assertInstanceOf(Unlimited::class, $disabledLimit, sprintf(
                'Expected [%s] limiter to honor API_RATE_LIMITS_ENABLED kill switch.',
                $name
            ));
        }
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
        $this->assertRouteContainsMiddleware('GET', 'api/app/v1/bootstrap', 'api.request_size:app');
        $this->assertRouteContainsMiddleware('GET', 'api/app/v1/session', 'user');
        $this->assertRouteContainsMiddleware('GET', 'api/app/v1/session', 'throttle:app-read');
        $this->assertRouteContainsMiddleware('GET', 'api/app/v1/dashboard', 'user');
        $this->assertRouteContainsMiddleware('GET', 'api/app/v1/dashboard', 'throttle:app-read');

        $securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));

        foreach ([
            ['POST', "api/v2/{$securePath}/auth/login", 'throttle:admin-login'],
            ['POST', "api/v2/{$securePath}/auth/login", 'api.request_size:passport'],
            ['GET', "api/v2/{$securePath}/auth/me", 'throttle:admin-api'],
            ['GET', "api/v2/{$securePath}/auth/me", 'api.request_size:admin'],
            ['GET', "api/v2/{$securePath}/config/fetch", 'throttle:admin-api'],
            ['GET', "api/v2/{$securePath}/config/fetch", 'api.request_size:admin'],
            ['GET', 'api/v1/client/subscribe', 'throttle:subscription'],
            ['GET', admin_setting('subscribe_path', 's') . '/{token}', 'throttle:subscription'],
            ['GET', 'api/v1/guest/payment/notify/{method}/{uuid}', 'throttle:callback'],
            ['POST', 'api/v1/guest/payment/notify/{method}/{uuid}', 'throttle:callback'],
            ['POST', 'api/v1/guest/telegram/webhook', 'throttle:callback'],
            ['GET', 'api/v1/server/UniProxy/config', 'throttle:server-node'],
            ['GET', 'api/v1/server/UniProxy/config', 'api.request_size:server'],
            ['POST', 'api/v1/server/TrojanTidalab/submit', 'throttle:server-node'],
            ['POST', 'api/v1/server/TrojanTidalab/submit', 'api.request_size:server'],
            ['GET', 'api/v2/server/config', 'throttle:server-node'],
            ['GET', 'api/v2/server/config', 'api.request_size:server'],
            ['POST', 'api/v2/server/report', 'throttle:server-node'],
            ['POST', 'api/v2/server/report', 'api.request_size:server'],
            ['POST', 'api/v2/server/machine/nodes', 'throttle:server-machine'],
            ['POST', 'api/v2/server/machine/nodes', 'api.request_size:server'],
            ['POST', 'api/v2/server/machine/status', 'throttle:server-machine'],
            ['POST', 'api/v2/server/machine/status', 'api.request_size:server'],
        ] as [$method, $uri, $expectedMiddleware]) {
            $this->assertRouteContainsMiddleware($method, $uri, $expectedMiddleware);
        }
    }

    public function test_api_trace_id_header_is_scoped_to_api_responses_and_preserves_safe_client_id(): void
    {
        $this->withHeader('X-Request-Id', 'security-phase3-trace')
            ->getJson('/api/app/v1/bootstrap')
            ->assertOk()
            ->assertHeader('X-Request-Id', 'security-phase3-trace')
            ->assertJsonPath('meta.trace_id', 'security-phase3-trace');
    }

    public function test_api_request_size_guard_rejects_oversized_bodies_without_changing_normal_responses(): void
    {
        config([
            'api_security.request_size.enabled' => true,
            'api_security.request_size.app_max_bytes' => 16,
        ]);

        RouteFacade::post('/api/security-size-test', fn() => response()->json(['ok' => true]))
            ->middleware(['api', 'api.request_size:app']);

        $this->postJson('/api/security-size-test', ['x' => 'small'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->postJson('/api/security-size-test', ['x' => str_repeat('a', 64)])
            ->assertStatus(413);
    }

    public function test_admin_audit_redaction_is_recursive_and_preserves_safe_fields(): void
    {
        $redacted = RequestLog::redactSensitiveData([
            'name' => 'safe plan',
            'password' => 'plain-password',
            'auth_data' => 'Bearer secret-auth-token',
            'access_token' => 'access-secret',
            'clientSecret' => 'client-secret',
            'payment' => [
                'api_key' => 'provider-api-key',
                'public_name' => 'Stripe',
                'nested' => [
                    'webhook-secret' => 'whsec_secret',
                    'subscribeUrl' => 'https://example.invalid/s/token',
                    'private.key' => 'private-key',
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
        $this->assertSame('[REDACTED]', $redacted['access_token']);
        $this->assertSame('[REDACTED]', $redacted['clientSecret']);
        $this->assertSame('[REDACTED]', $redacted['payment']['api_key']);
        $this->assertSame('Stripe', $redacted['payment']['public_name']);
        $this->assertSame('[REDACTED]', $redacted['payment']['nested']['webhook-secret']);
        $this->assertSame('[REDACTED]', $redacted['payment']['nested']['subscribeUrl']);
        $this->assertSame('[REDACTED]', $redacted['payment']['nested']['private.key']);
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
