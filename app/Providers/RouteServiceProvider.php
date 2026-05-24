<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * This namespace is applied to your controller routes.
     *
     * In addition, it is set as the URL generator's root namespace.
     *
     * @var string
     */
    protected $namespace = 'App\Http\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        // HTTPS scheme is forced per-request via middleware (Octane-safe).
        $this->configureApiRateLimiting();

        parent::boot();
    }

    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function map()
    {
        $this->mapApiRoutes();
        $this->mapAppApiRoutes();
        $this->mapWebRoutes();

        //
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     *
     * @return void
     */
    protected function mapWebRoutes()
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/web.php'));
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    protected function mapApiRoutes()
    {
        Route::group([
            'prefix' => '/api/v1',
            'middleware' => 'api',
            'namespace' => $this->namespace
        ], function ($router) {
            foreach (glob(app_path('Http//Routes//V1') . '/*.php') as $file) {
                $this->app->make('App\\Http\\Routes\\V1\\' . basename($file, '.php'))->map($router);
            }
        });


        Route::group([
            'prefix' => '/api/v2',
            'middleware' => 'api',
            'namespace' => $this->namespace
        ], function ($router) {
            foreach (glob(app_path('Http//Routes//V2') . '/*.php') as $file) {
                $this->app->make('App\\Http\\Routes\\V2\\' . basename($file, '.php'))->map($router);
            }
        });
    }

    /**
     * Define the additive frontend/app BFF routes.
     *
     * These routes intentionally live outside the V1/V2 route-file globs so
     * they mount under /api/app/v1 only and cannot shadow legacy clients.
     *
     * @return void
     */
    protected function mapAppApiRoutes()
    {
        Route::group([
            'prefix' => '/api/app/v1',
            'middleware' => [
                'api',
                \App\Http\Middleware\AppApiResponseBoundary::class,
            ],
        ], function () {
            require base_path('routes/app_api.php');
        });
    }

    /**
     * Configure scoped API rate limiters for the security pilot.
     *
     * These named limiters are intentionally applied route-by-route instead
     * of enabling a global API throttle, because subscription, node, payment,
     * Telegram, and plugin-sensitive channels have separate protocol budgets.
     */
    private function configureApiRateLimiting(): void
    {
        RateLimiter::for('passport-login', function (Request $request) {
            if (!$this->apiRateLimitsEnabled()) {
                return Limit::none();
            }

            $email = strtolower((string) $request->input('email', 'anonymous'));

            return Limit::perMinute((int) config('api_security.rate_limits.passport_login_per_minute', 20))
                ->by($request->ip() . '|login|' . $email);
        });

        RateLimiter::for('passport-email', function (Request $request) {
            if (!$this->apiRateLimitsEnabled()) {
                return Limit::none();
            }

            $email = strtolower((string) $request->input('email', 'anonymous'));

            return Limit::perMinute((int) config('api_security.rate_limits.passport_email_per_minute', 3))
                ->by($request->ip() . '|email|' . $email);
        });

        RateLimiter::for('user-read', function (Request $request) {
            if (!$this->apiRateLimitsEnabled()) {
                return Limit::none();
            }

            $userId = $request->user()?->id ?: 'guest';

            return Limit::perMinute((int) config('api_security.rate_limits.user_read_per_minute', 120))
                ->by($request->ip() . '|user|' . $userId);
        });

        RateLimiter::for('app-read', function (Request $request) {
            if (!$this->apiRateLimitsEnabled()) {
                return Limit::none();
            }

            $userId = $request->user()?->id ?: 'guest';

            return Limit::perMinute((int) config('api_security.rate_limits.app_read_per_minute', 120))
                ->by($request->ip() . '|app|' . $userId);
        });


        RateLimiter::for('admin-login', function (Request $request) {
            if (!$this->apiRateLimitsEnabled()) {
                return Limit::none();
            }

            $email = strtolower((string) $request->input('email', 'anonymous'));

            return Limit::perMinute((int) config('api_security.rate_limits.admin_login_per_minute', 10))
                ->by($request->ip() . '|admin-login|' . $email);
        });

        RateLimiter::for('admin-api', function (Request $request) {
            if (!$this->apiRateLimitsEnabled()) {
                return Limit::none();
            }

            $adminId = $request->user()?->id ?: 'guest';

            return Limit::perMinute((int) config('api_security.rate_limits.admin_api_per_minute', 240))
                ->by($request->ip() . '|admin|' . $adminId);
        });

        RateLimiter::for('subscription', function (Request $request) {
            if (!$this->apiRateLimitsEnabled()) {
                return Limit::none();
            }

            $token = $request->input('token', $request->route('token', 'anonymous'));

            return Limit::perMinute((int) config('api_security.rate_limits.subscription_per_minute', 60))
                ->by($request->ip() . '|subscription|' . $this->rateLimitKeyFragment($token));
        });

        RateLimiter::for('server-node', function (Request $request) {
            if (!$this->apiRateLimitsEnabled()) {
                return Limit::none();
            }

            $nodeKey = $request->input('machine_id')
                ? 'machine:' . $request->input('machine_id')
                : 'node:' . $request->input('node_id', 'unknown');
            $token = $request->input('token', 'anonymous');

            return Limit::perMinute((int) config('api_security.rate_limits.server_node_per_minute', 300))
                ->by($request->ip() . '|server|' . $nodeKey . '|' . $this->rateLimitKeyFragment($token));
        });

        RateLimiter::for('server-machine', function (Request $request) {
            if (!$this->apiRateLimitsEnabled()) {
                return Limit::none();
            }

            $machineId = $request->input('machine_id', 'unknown');
            $token = $request->input('token', 'anonymous');

            return Limit::perMinute((int) config('api_security.rate_limits.server_machine_per_minute', 120))
                ->by($request->ip() . '|machine|' . $machineId . '|' . $this->rateLimitKeyFragment($token));
        });

        RateLimiter::for('callback', function (Request $request) {
            if (!$this->apiRateLimitsEnabled()) {
                return Limit::none();
            }

            $method = (string) $request->route('method', 'generic');

            return Limit::perMinute((int) config('api_security.rate_limits.callback_per_minute', 120))
                ->by($request->ip() . '|callback|' . $method);
        });
    }

    private function apiRateLimitsEnabled(): bool
    {
        return (bool) config('api_security.rate_limits.enabled', true);
    }

    private function rateLimitKeyFragment(mixed $value): string
    {
        $value = (string) ($value ?? 'anonymous');

        if ($value === '') {
            return 'anonymous';
        }

        return sha1($value);
    }
}
