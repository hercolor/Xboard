<?php

declare(strict_types=1);

namespace Tests\Feature\AppApi;

use App\Http\Middleware\InitializePlugins;
use App\Http\Middleware\AppApiResponseBoundary;
use Illuminate\Routing\Route;
use Tests\TestCase;

final class AppApiBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
                        'session' => false,
                        'dashboard' => false,
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
