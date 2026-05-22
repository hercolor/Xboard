<?php

declare(strict_types=1);

namespace Tests\Feature;

use Closure;
use App\Http\Controllers\V1\Guest\CommController;
use App\Support\Setting as SettingsRepository;
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

    public function test_guest_comm_config_keeps_support_fields_and_adds_app_customer_service_aliases(): void
    {
        app()->instance(SettingsRepository::class, new class extends SettingsRepository {
            private array $settings = [
                'support_contact_label' => 'Support',
                'support_contact_url' => 'https://support.example.invalid/contact',
                'support_group_label' => 'Group',
                'support_group_url' => 'https://support.example.invalid/group',
            ];

            public function __construct()
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->settings[strtolower($key)] ?? $default;
            }
        });

        $payload = (new CommController())->config()->getData(true);

        $this->assertSame('success', $payload['status']);
        $this->assertSame('https://support.example.invalid/contact', $payload['data']['support_contact_url']);
        $this->assertSame('https://support.example.invalid/group', $payload['data']['support_group_url']);
        $this->assertSame('https://support.example.invalid/contact', $payload['data']['customer_service']);
        $this->assertSame('https://support.example.invalid/contact', $payload['data']['customer_service_url']);
        $this->assertSame('https://support.example.invalid/contact', $payload['data']['customerServiceUrl']);
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
