<?php

declare(strict_types=1);

namespace Tests\Feature;

use Closure;
use Illuminate\Routing\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class AdminOnlyShellContractTest extends TestCase
{
    public function test_root_returns_not_found_while_admin_shell_and_subscribe_routes_remain_mounted(): void
    {
        $securePath = ltrim((string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        ), '/');

        $rootRoute = $this->assertRouteIsMounted('GET', '/');
        $rootAction = $rootRoute->getAction('uses');

        $this->assertInstanceOf(Closure::class, $rootAction);

        try {
            $rootAction();
            $this->fail('Expected public root [/] to return 404 instead of exposing the admin shell.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertRouteIsMounted('GET', $securePath);
        $this->assertRouteIsMounted('GET', admin_setting('subscribe_path', 's') . '/{token}');
    }

    public function test_dk_theme_shared_passport_user_and_guest_api_contracts_remain_mounted(): void
    {
        foreach (['v1', 'v2'] as $version) {
            $this->assertRouteIsMounted('POST', "api/{$version}/passport/auth/login");
            $this->assertRouteIsMounted('POST', "api/{$version}/passport/auth/register");
            $this->assertRouteIsMounted('POST', "api/{$version}/passport/auth/forget");
            $this->assertRouteIsMounted('POST', "api/{$version}/passport/comm/sendEmailVerify");
            $this->assertRouteIsMounted('GET', "api/{$version}/passport/auth/token2Login");
            $this->assertRouteIsMounted('POST', "api/{$version}/passport/auth/loginWithMailLink");
            $this->assertRouteIsMounted('POST', "api/{$version}/passport/auth/getQuickLoginUrl");

            $this->assertRouteIsMounted('GET', "api/{$version}/user/info", ['user']);
        }

        $this->assertRouteIsMounted('GET', 'api/v1/guest/comm/config');
        $this->assertRouteIsMounted('GET', 'api/v1/guest/plan/fetch');
        $this->assertRouteIsMounted('POST', 'api/v1/guest/telegram/webhook');
        $this->assertRouteIsMounted('GET', 'api/v1/guest/payment/notify/{method}/{uuid}');
        $this->assertRouteIsMounted('POST', 'api/v1/guest/payment/notify/{method}/{uuid}');
    }

    /**
     * @param array<int, string> $requiredMiddleware
     */
    private function assertRouteIsMounted(string $method, string $uri, array $requiredMiddleware = []): Route
    {
        $route = $this->findRoute($method, $uri);

        $this->assertNotNull($route, sprintf('Expected [%s] route [%s] to be mounted.', $method, $uri));

        foreach ($requiredMiddleware as $middleware) {
            $this->assertContains(
                $middleware,
                $route->gatherMiddleware(),
                sprintf('Expected route [%s] to keep [%s] middleware.', $uri, $middleware)
            );
        }

        return $route;
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
