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
            'passport-register',
            'passport-forget',
            'passport-quick-login',
            'user-read',
            'user-mutation',
            'payment-config',
            'payment-checkout',
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
            'passport-register',
            'passport-forget',
            'passport-quick-login',
            'user-read',
            'user-mutation',
            'payment-config',
            'payment-checkout',
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
            $this->assertRouteContainsMiddleware('POST', "api/{$version}/passport/auth/register", 'throttle:passport-register');
            $this->assertRouteContainsMiddleware('POST', "api/{$version}/passport/auth/login", 'throttle:passport-login');
            $this->assertRouteContainsMiddleware('POST', "api/{$version}/passport/auth/forget", 'throttle:passport-forget');
            $this->assertRouteContainsMiddleware('POST', "api/{$version}/passport/auth/getQuickLoginUrl", 'throttle:passport-quick-login');
            $this->assertRouteContainsMiddleware('POST', "api/{$version}/passport/auth/loginWithMailLink", 'throttle:passport-email');
            $this->assertRouteContainsMiddleware('POST', "api/{$version}/passport/comm/sendEmailVerify", 'throttle:passport-email');
            $this->assertRouteContainsMiddleware('GET', "api/{$version}/user/info", 'throttle:user-read');
        }

        $this->assertRouteContainsMiddleware('GET', 'api/app/v1/bootstrap', AppApiResponseBoundary::class);
        $this->assertRouteContainsMiddleware('GET', 'api/app/v1/bootstrap', 'throttle:app-read');
        $this->assertRouteContainsMiddleware('GET', 'api/app/v1/bootstrap', 'api.request_size:app');
        $this->assertRouteContainsMiddleware('GET', 'api/app/v1/bootstrap', 'api.cache_headers:bootstrap');
        $this->assertRouteContainsMiddleware('GET', 'api/app/v1/session', 'user');
        $this->assertRouteContainsMiddleware('GET', 'api/app/v1/session', 'throttle:app-read');
        $this->assertRouteContainsMiddleware('GET', 'api/app/v1/dashboard', 'user');
        $this->assertRouteContainsMiddleware('GET', 'api/app/v1/dashboard', 'throttle:app-read');

        $securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));

        foreach ([
            ['GET', 'api/v1/user/getStat', 'throttle:user-read'],
            ['GET', 'api/v1/user/stat/getTrafficLog', 'throttle:user-read'],
            ['GET', 'api/v1/user/order/fetch', 'throttle:user-read'],
            ['GET', 'api/v1/user/order/detail', 'throttle:user-read'],
            ['GET', 'api/v1/user/order/getPaymentMethod', 'throttle:user-read'],
            ['GET', 'api/v1/user/plan/fetch', 'throttle:user-read'],
            ['GET', 'api/v1/user/invite/fetch', 'throttle:user-read'],
            ['GET', 'api/v1/user/invite/details', 'throttle:user-read'],
            ['GET', 'api/v1/user/ticket/fetch', 'throttle:user-read'],
            ['GET', 'api/v1/user/notice/fetch', 'throttle:user-read'],
            ['GET', 'api/v1/user/knowledge/fetch', 'throttle:user-read'],
            ['GET', 'api/v1/user/knowledge/getCategory', 'throttle:user-read'],
            ['POST', 'api/v1/user/resetSecurity', 'throttle:user-mutation'],
            ['POST', 'api/v2/user/resetSecurity', 'throttle:user-mutation'],
            ['POST', 'api/v1/user/invite/save', 'throttle:user-mutation'],
            ['POST', 'api/v1/user/changePassword', 'throttle:user-mutation'],
            ['POST', 'api/v1/user/update', 'throttle:user-mutation'],
            ['POST', 'api/v1/user/transfer', 'throttle:user-mutation'],
            ['POST', 'api/v1/user/getQuickLoginUrl', 'throttle:user-mutation'],
            ['POST', 'api/v1/user/removeActiveSession', 'throttle:user-mutation'],
            ['POST', 'api/v1/user/order/save', 'throttle:user-mutation'],
            ['POST', 'api/v1/user/order/checkout', 'throttle:payment-checkout'],
            ['POST', 'api/v1/user/order/cancel', 'throttle:user-mutation'],
            ['POST', 'api/v1/user/ticket/save', 'throttle:user-mutation'],
            ['POST', 'api/v1/user/ticket/reply', 'throttle:user-mutation'],
            ['POST', 'api/v1/user/ticket/close', 'throttle:user-mutation'],
            ['POST', 'api/v1/user/ticket/withdraw', 'throttle:user-mutation'],
            ['POST', 'api/v1/user/coupon/check', 'throttle:user-mutation'],
            ['POST', 'api/v1/user/gift-card/check', 'throttle:user-mutation'],
            ['POST', 'api/v1/user/gift-card/redeem', 'throttle:user-mutation'],
            ['POST', 'api/v1/user/comm/getStripePublicKey', 'throttle:payment-config'],
            ['POST', "api/v2/{$securePath}/auth/login", 'throttle:admin-login'],
            ['POST', "api/v2/{$securePath}/auth/login", 'api.request_size:passport'],
            ['GET', "api/v2/{$securePath}/auth/me", 'throttle:admin-api'],
            ['GET', "api/v2/{$securePath}/auth/me", 'api.request_size:admin'],
            ['GET', "api/v2/{$securePath}/config/fetch", 'throttle:admin-api'],
            ['GET', "api/v2/{$securePath}/config/fetch", 'api.request_size:admin'],
            ['GET', 'api/v1/guest/comm/config', 'api.cache_headers:guest-config'],
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

    public function test_phase7_cache_header_route_matrix_is_frozen_before_cache_pilot(): void
    {
        foreach ([
            ['GET', 'api/app/v1/bootstrap', 'api.cache_headers:bootstrap'],
            ['GET', 'api/v1/guest/comm/config', 'api.cache_headers:guest-config'],
        ] as [$method, $uri, $expectedMiddleware]) {
            $this->assertRouteContainsMiddleware($method, $uri, $expectedMiddleware);
        }

        foreach ([
            // Future cache candidates need exact-body/client-compatibility tests before middleware changes.
            ['GET', 'api/v1/guest/plan/fetch'],
            ['GET', 'api/v1/user/comm/config'],
            ['GET', 'api/app/v1/session'],
            ['GET', 'api/app/v1/dashboard'],
            // No-touch protocol/callback/payment/auth surfaces must not receive public cache headers.
            ['GET', 'api/v1/client/subscribe'],
            ['GET', admin_setting('subscribe_path', 's') . '/{token}'],
            ['GET', 'api/v1/guest/payment/notify/{method}/{uuid}'],
            ['POST', 'api/v1/guest/payment/notify/{method}/{uuid}'],
            ['POST', 'api/v1/guest/telegram/webhook'],
            ['GET', 'api/v1/server/UniProxy/config'],
            ['GET', 'api/v2/server/config'],
            ['POST', 'api/v2/server/report'],
            ['POST', 'api/v2/server/machine/nodes'],
            ['POST', 'api/v2/server/machine/status'],
            ['POST', 'api/v1/passport/auth/login'],
            ['POST', 'api/v2/passport/auth/login'],
            ['GET', 'api/v1/user/order/check'],
        ] as [$method, $uri]) {
            $this->assertRouteDoesNotContainMiddlewarePrefix($method, $uri, 'api.cache_headers:');
        }
    }

    public function test_phase7_unthrottled_legacy_read_candidates_match_documented_matrix_before_changes(): void
    {
        $auditDocument = file_get_contents(base_path('docs/api-cache-field-minimization-phase7-audit.md'));
        $this->assertIsString($auditDocument);

        foreach ([
            ['GET', 'api/v1/user/getSubscribe'],
            ['GET', 'api/v1/user/checkLogin'],
            ['GET', 'api/v1/user/getActiveSession'],
            ['GET', 'api/v1/user/server/fetch'],
            ['GET', 'api/v1/user/gift-card/history'],
            ['GET', 'api/v1/user/gift-card/detail'],
            ['GET', 'api/v1/user/gift-card/types'],
            ['GET', 'api/v1/user/telegram/getBotInfo'],
            ['GET', 'api/v1/user/comm/config'],
            ['GET', 'api/v1/user/order/check'],
        ] as [$method, $uri]) {
            $this->assertRouteContainsMiddleware($method, $uri, 'user');
            $this->assertRouteDoesNotContainMiddleware($method, $uri, 'throttle:user-read');
            $this->assertRouteDoesNotContainMiddleware($method, $uri, AppApiResponseBoundary::class);
            $this->assertStringContainsString("`{$method} /{$uri}`", $auditDocument);
        }
    }

    public function test_phase7_side_effect_get_aliases_are_not_classified_as_read_optimizations(): void
    {
        $auditDocument = file_get_contents(base_path('docs/api-cache-field-minimization-phase7-audit.md'));
        $this->assertIsString($auditDocument);

        foreach ([
            ['GET', 'api/v1/user/resetSecurity'],
            ['GET', 'api/v2/user/resetSecurity'],
            ['GET', 'api/v1/user/invite/save'],
        ] as [$method, $uri]) {
            $this->assertRouteContainsMiddleware($method, $uri, 'user');
            $this->assertRouteDoesNotContainMiddleware($method, $uri, 'throttle:user-read');
            $this->assertRouteDoesNotContainMiddleware($method, $uri, 'throttle:user-mutation');
            $this->assertRouteDoesNotContainMiddlewarePrefix($method, $uri, 'api.cache_headers:');
        }

        $this->assertStringContainsString('`GET /api/v1/user/resetSecurity`', $auditDocument);
        $this->assertStringContainsString('`GET /api/v1/user/invite/save`', $auditDocument);
    }

    public function test_api_trace_id_header_is_scoped_to_api_responses_and_preserves_safe_client_id(): void
    {
        $this->withHeader('X-Request-Id', 'security-phase3-trace')
            ->getJson('/api/app/v1/bootstrap')
            ->assertOk()
            ->assertHeader('X-Request-Id', 'security-phase3-trace')
            ->assertJsonPath('meta.trace_id', 'security-phase3-trace');
    }

    public function test_public_read_cache_header_middleware_is_short_ttl_and_disableable(): void
    {
        config([
            'api_performance.cache_headers.enabled' => true,
            'api_performance.cache_headers.bootstrap_max_age' => 30,
        ]);

        RouteFacade::get('/api/cache-header-test', fn() => response()->json(['ok' => true]))
            ->middleware(['api', 'api.cache_headers:bootstrap']);

        $cacheResponse = $this->getJson('/api/cache-header-test')
            ->assertOk()
            ->assertHeader('Vary', 'Accept');

        $this->assertStringContainsString('public', $cacheResponse->headers->get('Cache-Control', ''));
        $this->assertStringContainsString('max-age=30', $cacheResponse->headers->get('Cache-Control', ''));
        $this->assertStringContainsString('stale-while-revalidate=30', $cacheResponse->headers->get('Cache-Control', ''));

        config(['api_performance.cache_headers.enabled' => false]);

        RouteFacade::get('/api/cache-header-disabled-test', fn() => response()->json(['ok' => true]))
            ->middleware(['api', 'api.cache_headers:bootstrap']);

        $disabledResponse = $this->getJson('/api/cache-header-disabled-test')
            ->assertOk();

        $this->assertStringNotContainsString(
            'public, max-age=',
            $disabledResponse->headers->get('Cache-Control', '')
        );
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

    private function assertRouteDoesNotContainMiddleware(string $method, string $uri, string $middleware): void
    {
        $route = $this->findRoute($method, $uri);

        $this->assertNotNull($route, sprintf('Expected route [%s %s] to be mounted.', $method, $uri));
        $this->assertNotContains($middleware, $route->gatherMiddleware(), sprintf(
            'Expected route [%s %s] to avoid middleware [%s].',
            $method,
            $uri,
            $middleware
        ));
    }

    private function assertRouteDoesNotContainMiddlewarePrefix(string $method, string $uri, string $middlewarePrefix): void
    {
        $route = $this->findRoute($method, $uri);

        $this->assertNotNull($route, sprintf('Expected route [%s %s] to be mounted.', $method, $uri));

        foreach ($route->gatherMiddleware() as $middleware) {
            $this->assertStringStartsNotWith($middlewarePrefix, $middleware, sprintf(
                'Expected route [%s %s] to avoid middleware prefix [%s].',
                $method,
                $uri,
                $middlewarePrefix
            ));
        }
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
