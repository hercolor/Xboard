<?php

declare(strict_types=1);

namespace Tests\Feature\AppApi;

use App\Http\Middleware\AppApiResponseBoundary;
use App\Http\Middleware\InitializePlugins;
use App\Services\App\AppSessionReadModel;
use App\Services\User\LegacyUserInfoReadModel;
use Illuminate\Routing\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AppApi\AppBffFixtures;
use Tests\TestCase;

final class AppApiBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['api_security.rate_limits.enabled' => false]);

        // These endpoint tests assert the App API route/envelope contract.
        // Plugin boot is covered by the runtime E2E smoke path and may require
        // the local php-xboard binary's sqlite extension in this workspace.
        $this->withoutMiddleware(InitializePlugins::class);
    }

    public function test_bootstrap_uses_dedicated_app_api_envelope(): void
    {
        $response = $this->withHeader('X-Request-Id', 'app-api-test-trace')
            ->getJson('/api/app/v1/bootstrap');

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'code' => 'OK',
                'message' => 'ok',
                'data' => [
                    'api' => [
                        'name' => 'app-bff',
                        'version' => 'v1',
                    ],
                    'capabilities' => [
                        'bootstrap' => true,
                        'session' => true,
                        'dashboard' => true,
                    ],
                ],
                'meta' => [
                    'trace_id' => 'app-api-test-trace',
                ],
            ])
            ->assertJsonStructure([
                'ok',
                'code',
                'message',
                'data' => [
                    'api' => ['name', 'version'],
                    'capabilities' => ['bootstrap', 'session', 'dashboard'],
                ],
                'meta' => ['trace_id', 'server_time'],
            ]);

        $this->assertIsInt($response->json('meta.server_time'));
    }

    public function test_app_api_routes_are_not_mounted_under_legacy_v1_prefix(): void
    {
        $this->assertNotNull($this->findRoute('GET', 'api/app/v1/bootstrap'));
        $this->assertNull($this->findRoute('GET', 'api/v1/app/v1/bootstrap'));

        $this->getJson('/api/v1/app/v1/bootstrap')->assertNotFound();
    }

    public function test_dashboard_is_mounted_after_phase_2_approval(): void
    {
        $route = $this->findRoute('GET', 'api/app/v1/dashboard');

        $this->assertNotNull($route);
        $this->assertContains(AppApiResponseBoundary::class, $route->gatherMiddleware());
        $this->assertContains('user', $route->gatherMiddleware());
        $this->assertContains('throttle:app-read', $route->gatherMiddleware());
    }

    public function test_app_api_not_found_errors_use_scoped_envelope(): void
    {
        $this->getJson('/api/app/v1/not-found')
            ->assertNotFound()
            ->assertJson([
                'ok' => false,
                'code' => 'NOT_FOUND',
            ])
            ->assertJsonStructure([
                'ok',
                'code',
                'message',
                'data',
                'meta' => ['trace_id', 'server_time'],
            ]);
    }

    public function test_app_api_response_boundary_is_scoped_to_new_prefix(): void
    {
        $appRoute = $this->findRoute('GET', 'api/app/v1/bootstrap');
        $this->assertNotNull($appRoute);
        $this->assertContains(AppApiResponseBoundary::class, $appRoute->gatherMiddleware());

        $sessionRoute = $this->findRoute('GET', 'api/app/v1/session');
        $this->assertNotNull($sessionRoute);
        $this->assertContains(AppApiResponseBoundary::class, $sessionRoute->gatherMiddleware());
        $this->assertContains('user', $sessionRoute->gatherMiddleware());

        foreach ([
            ['GET', 'api/v1/client/subscribe'],
            ['GET', 'api/v2/server/config'],
            ['POST', 'api/v2/server/report'],
            ['POST', 'api/v1/guest/telegram/webhook'],
            ['GET', 'api/v1/guest/payment/notify/{method}/{uuid}'],
            ['POST', 'api/v1/guest/payment/notify/{method}/{uuid}'],
            ['GET', admin_setting('subscribe_path', 's') . '/{token}'],
        ] as [$method, $uri]) {
            $route = $this->findRoute($method, $uri);

            $this->assertNotNull($route, sprintf('Expected no-touch route [%s %s] to remain mounted.', $method, $uri));
            $this->assertNotContains(
                AppApiResponseBoundary::class,
                $route->gatherMiddleware(),
                sprintf('Expected no-touch route [%s %s] to stay outside the App API envelope.', $method, $uri)
            );
        }
    }

    public function test_session_requires_user_auth_and_uses_app_api_error_envelope(): void
    {
        $this->getJson('/api/app/v1/session')
            ->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'code' => 'APP_API_ERROR',
            ])
            ->assertJsonStructure([
                'ok',
                'code',
                'message',
                'data',
                'meta' => ['trace_id', 'server_time'],
            ]);
    }

    public function test_session_returns_read_only_user_subscription_and_traffic_overview_without_subscription_token(): void
    {
        $user = AppBffFixtures::user();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/app/v1/session');

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'code' => 'OK',
                'data' => [
                    'user' => [
                        'id' => 42,
                        'email' => 'app-session@example.invalid',
                        'banned' => false,
                    ],
                    'subscription' => [
                        'status' => 'active',
                        'active' => true,
                        'plan_id' => 7,
                        'delivery_available' => true,
                    ],
                    'traffic' => [
                        'upload' => 120,
                        'download' => 80,
                        'used' => 200,
                        'total' => 1000,
                        'remaining' => 800,
                        'usage_percent' => 20.0,
                    ],
                    'preferences' => [
                        'remind_expire' => true,
                        'remind_traffic' => false,
                    ],
                ],
            ])
            ->assertJsonStructure([
                'ok',
                'code',
                'message',
                'data' => [
                    'user' => ['id', 'email', 'avatar_url', 'is_admin', 'is_staff', 'banned', 'created_at', 'last_login_at', 'telegram_bound'],
                    'subscription' => ['status', 'active', 'plan_id', 'expired_at', 'next_reset_at', 'device_limit', 'speed_limit', 'delivery_available'],
                    'traffic' => ['upload', 'download', 'used', 'total', 'remaining', 'usage_percent'],
                    'preferences' => ['remind_expire', 'remind_traffic'],
                ],
                'meta' => ['trace_id', 'server_time'],
            ]);

        $payload = json_encode($response->json('data'), JSON_THROW_ON_ERROR);
        foreach (AppBffFixtures::sensitiveNeedles() as $needle) {
            $this->assertStringNotContainsString($needle, $payload);
        }
    }

    public function test_session_read_model_is_allowlist_only_and_safe_for_future_dashboard_reuse(): void
    {
        $payload = (new AppSessionReadModel())->forUser(AppBffFixtures::user());

        $this->assertSame(['user', 'subscription', 'traffic', 'preferences'], array_keys($payload));
        $this->assertSame(
            ['id', 'email', 'avatar_url', 'is_admin', 'is_staff', 'banned', 'created_at', 'last_login_at', 'telegram_bound'],
            array_keys($payload['user'])
        );
        $this->assertSame(
            ['status', 'active', 'plan_id', 'expired_at', 'next_reset_at', 'device_limit', 'speed_limit', 'delivery_available'],
            array_keys($payload['subscription'])
        );

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (AppBffFixtures::sensitiveNeedles() as $needle) {
            $this->assertStringNotContainsString($needle, $encoded);
        }
    }

    public function test_legacy_user_info_and_get_subscribe_routes_remain_outside_app_envelope(): void
    {
        foreach ([
            ['GET', 'api/v1/user/info', 'info', 'throttle:user-read'],
            ['GET', 'api/v1/user/getSubscribe', 'getSubscribe', null],
        ] as [$method, $uri, $controllerMethod, $extraMiddleware]) {
            $route = $this->findRoute($method, $uri);

            $this->assertNotNull($route);
            $this->assertSame(
                'App\Http\Controllers\V1\User\UserController@' . $controllerMethod,
                $route->getActionName()
            );
            $this->assertContains('user', $route->gatherMiddleware());
            $this->assertNotContains(AppApiResponseBoundary::class, $route->gatherMiddleware());

            if ($extraMiddleware !== null) {
                $this->assertContains($extraMiddleware, $route->gatherMiddleware());
            }
        }
    }

    public function test_legacy_user_info_read_model_keeps_frontend_fallback_fields_documented_for_session_migration(): void
    {
        $controllerSource = file_get_contents(app_path('Http/Controllers/V1/User/UserController.php'));
        $readModelSource = file_get_contents(app_path('Services/User/LegacyUserInfoReadModel.php'));

        $this->assertIsString($controllerSource);
        $this->assertIsString($readModelSource);
        $this->assertStringContainsString('LegacyUserInfoReadModel $readModel', $controllerSource);

        $this->assertSame([
            'email',
            'transfer_enable',
            'last_login_at',
            'created_at',
            'banned',
            'remind_expire',
            'remind_traffic',
            'expired_at',
            'balance',
            'commission_balance',
            'plan_id',
            'discount',
            'commission_rate',
            'telegram_id',
            'uuid',
        ], LegacyUserInfoReadModel::COLUMNS);

        $this->assertStringContainsString('avatar_url', $readModelSource);

        foreach ([
            "'token'",
            "'u'",
            "'d'",
            "'device_limit'",
            "'speed_limit'",
            "'next_reset_at'",
            '$user[\'subscribe_url\'] = Helper::getSubscribeUrl($user[\'token\']);',
            'HookManager::filter(\'user.subscribe.response\', $user)',
        ] as $expectedSubscribeFragment) {
            $this->assertStringContainsString($expectedSubscribeFragment, $controllerSource);
        }
    }

    public function test_future_dashboard_fixture_catalog_is_capped_and_secret_free(): void
    {
        $fixture = AppBffFixtures::futureDashboardCandidateRows();

        $this->assertLessThanOrEqual(5, count($fixture['orders_summary']['latest']));
        $this->assertLessThanOrEqual(5, count($fixture['tickets_summary']['latest']));
        $this->assertLessThanOrEqual(5, count($fixture['notices']));

        $encoded = json_encode($fixture, JSON_THROW_ON_ERROR);
        foreach (AppBffFixtures::sensitiveNeedles() as $needle) {
            $this->assertStringNotContainsString($needle, $encoded);
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
