<?php

declare(strict_types=1);

namespace Tests\Feature\AppApi;

use App\Http\Middleware\InitializePlugins;
use App\Services\App\AppDashboardReadModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AppApi\AppBffFixtures;
use Tests\TestCase;

final class AppApiDashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'api_security.rate_limits.enabled' => false,
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->withoutMiddleware(InitializePlugins::class);
        $this->createDashboardTables();
    }

    public function test_dashboard_requires_user_auth_and_uses_app_api_error_envelope(): void
    {
        $this->getJson('/api/app/v1/dashboard')
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

    public function test_dashboard_returns_allowlisted_capped_read_only_aggregate(): void
    {
        $user = AppBffFixtures::user();
        $this->seedDashboardRows($user->id);

        Sanctum::actingAs($user);

        $response = $this->withHeader('X-Request-Id', 'app-dashboard-test-trace')
            ->getJson('/api/app/v1/dashboard');

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'code' => 'OK',
                'data' => [
                    'session_summary' => [
                        'user' => [
                            'id' => 42,
                            'email' => 'app-session@example.invalid',
                        ],
                    ],
                    'subscription_summary' => [
                        'status' => 'active',
                        'active' => true,
                        'plan_id' => 7,
                    ],
                    'traffic_summary' => [
                        'used' => 200,
                        'total' => 1000,
                        'remaining' => 800,
                    ],
                    'orders_summary' => [
                        'unpaid_count' => 1,
                        'pending_count' => 1,
                    ],
                    'tickets_summary' => [
                        'open_count' => 1,
                    ],
                ],
                'meta' => [
                    'trace_id' => 'app-dashboard-test-trace',
                ],
            ])
            ->assertJsonStructure([
                'ok',
                'code',
                'message',
                'data' => [
                    'session_summary' => [
                        'user' => ['id', 'email', 'avatar_url', 'is_admin', 'is_staff', 'banned', 'created_at', 'last_login_at', 'telegram_bound'],
                    ],
                    'subscription_summary' => ['status', 'active', 'plan_id', 'expired_at', 'next_reset_at', 'device_limit', 'speed_limit', 'delivery_available'],
                    'traffic_summary' => ['upload', 'download', 'used', 'total', 'remaining', 'usage_percent'],
                    'orders_summary' => [
                        'unpaid_count',
                        'pending_count',
                        'latest' => [
                            '*' => ['trade_no', 'status', 'period', 'total_amount', 'created_at'],
                        ],
                    ],
                    'tickets_summary' => [
                        'open_count',
                        'latest' => [
                            '*' => ['id', 'level', 'reply_status', 'status', 'subject', 'created_at', 'updated_at'],
                        ],
                    ],
                    'notices' => [
                        '*' => ['id', 'title', 'created_at', 'updated_at'],
                    ],
                    'support',
                ],
                'meta' => ['trace_id', 'server_time'],
            ]);

        $this->assertLessThanOrEqual(5, count($response->json('data.orders_summary.latest')));
        $this->assertLessThanOrEqual(5, count($response->json('data.tickets_summary.latest')));
        $this->assertLessThanOrEqual(5, count($response->json('data.notices')));
    }

    public function test_dashboard_omits_sensitive_legacy_and_detail_fields(): void
    {
        $user = AppBffFixtures::user();
        $this->seedDashboardRows($user->id);

        Sanctum::actingAs($user);

        $payload = $this->getJson('/api/app/v1/dashboard')
            ->assertOk()
            ->json('data');

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        foreach ([
            ...AppBffFixtures::sensitiveNeedles(),
            'secret notice body with auth_data and subscribe_url',
            'ticket message body',
            'callback_no',
            'payment_id',
            'coupon_id',
            'surplus_order_ids',
        ] as $needle) {
            $this->assertStringNotContainsString($needle, $encoded);
        }
    }

    public function test_dashboard_read_model_query_budget_stays_bounded(): void
    {
        $user = AppBffFixtures::user();
        $this->seedDashboardRows($user->id, 8);

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(AppDashboardReadModel::class)->forUser($user);

        $this->assertLessThanOrEqual(8, count(DB::getQueryLog()));
    }

    private function createDashboardTables(): void
    {
        Schema::dropIfExists('v2_order');
        Schema::dropIfExists('v2_ticket');
        Schema::dropIfExists('v2_notice');

        Schema::create('v2_order', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('plan_id')->nullable();
            $table->integer('payment_id')->nullable();
            $table->integer('coupon_id')->nullable();
            $table->string('period')->nullable();
            $table->string('trade_no')->unique();
            $table->integer('total_amount')->default(0);
            $table->integer('status')->default(0);
            $table->string('callback_no')->nullable();
            $table->text('surplus_order_ids')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        Schema::create('v2_ticket', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('subject');
            $table->string('level')->nullable();
            $table->integer('reply_status')->default(1);
            $table->integer('status')->default(0);
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        Schema::create('v2_notice', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->text('content')->nullable();
            $table->boolean('show')->default(false);
            $table->integer('sort')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    private function seedDashboardRows(int $userId, int $rowCount = 2): void
    {
        for ($i = 0; $i < $rowCount; $i++) {
            DB::table('v2_order')->insert([
                'user_id' => $userId,
                'plan_id' => 7,
                'payment_id' => 9,
                'coupon_id' => 11,
                'period' => 'monthly',
                'trade_no' => 'SAFE-ORDER-' . $i,
                'total_amount' => 100 + $i,
                'status' => $i === 0 ? 0 : ($i === 1 ? 1 : 3),
                'callback_no' => 'callback_no_should_not_leak',
                'surplus_order_ids' => '[1,2,3]',
                'created_at' => 1770000200 + $i,
                'updated_at' => 1770000200 + $i,
            ]);

            DB::table('v2_ticket')->insert([
                'user_id' => $userId,
                'subject' => 'Safe support subject ' . $i,
                'level' => 'medium',
                'reply_status' => 0,
                'status' => $i === 0 ? 0 : 1,
                'created_at' => 1770000300 + $i,
                'updated_at' => 1770000400 + $i,
            ]);

            DB::table('v2_notice')->insert([
                'title' => 'Safe public notice ' . $i,
                'content' => 'secret notice body with auth_data and subscribe_url',
                'show' => $i < 6,
                'sort' => $i,
                'created_at' => 1770000500 + $i,
                'updated_at' => 1770000600 + $i,
            ]);
        }

        DB::table('v2_order')->insert([
            'user_id' => $userId + 1,
            'plan_id' => 7,
            'period' => 'monthly',
            'trade_no' => 'OTHER-USER-ORDER',
            'total_amount' => 999,
            'status' => 0,
            'created_at' => 1770000999,
            'updated_at' => 1770000999,
        ]);
    }
}
