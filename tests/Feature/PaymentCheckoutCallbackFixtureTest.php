<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\InitializePlugins;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PDO;
use Tests\Support\Payment\SyntheticPaymentPlugin;
use Tests\TestCase;

final class PaymentCheckoutCallbackFixtureTest extends TestCase
{
    private SyntheticPaymentPlugin $paymentPlugin;
    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipIfConfiguredDatabaseDriverIsUnavailable();
        DB::beginTransaction();
        $this->transactionStarted = true;

        $this->withoutMiddleware(InitializePlugins::class);
        HookManager::reset();

        $this->paymentPlugin = new SyntheticPaymentPlugin();

        HookManager::registerFilter('available_payment_methods', function (array $methods): array {
            $methods[SyntheticPaymentPlugin::METHOD] = [
                'name' => 'Fixture Pay',
                'icon' => '🧪',
                'plugin_code' => SyntheticPaymentPlugin::PLUGIN_CODE,
                'type' => 'plugin',
            ];

            return $methods;
        });

        app()->instance(PluginManager::class, new class ($this->paymentPlugin) extends PluginManager {
            public function __construct(private readonly SyntheticPaymentPlugin $paymentPlugin)
            {
                parent::__construct();
            }

            public function getEnabledPaymentPlugins(): array
            {
                return [$this->paymentPlugin];
            }
        });
    }

    protected function tearDown(): void
    {
        HookManager::reset();

        if ($this->transactionStarted) {
            DB::rollBack();
            $this->transactionStarted = false;
        }

        parent::tearDown();
    }

    public function test_checkout_uses_synthetic_provider_without_changing_raw_response_shape(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $payment = $this->makePayment([
            'handling_fee_fixed' => 25,
            'handling_fee_percent' => 10,
        ]);
        $order = $this->makePendingOrder($user, $plan, [
            'trade_no' => 'fixture-checkout-order',
            'total_amount' => 1000,
        ]);

        Sanctum::actingAs($user);

        $response = $this
            ->withHeader('Referer', 'https://client.example.invalid/app')
            ->postJson('/api/v1/user/order/checkout', [
                'trade_no' => $order->trade_no,
                'method' => $payment->id,
                'token' => 'fixture-stripe-token',
            ])
            ->assertOk()
            ->assertJsonStructure(['type', 'data'])
            ->assertJsonPath('type', 1);

        $checkoutUrl = (string) $response->json('data');
        $this->assertStringStartsWith('https://fixture-pay.invalid/checkout?', $checkoutUrl);
        $this->assertStringContainsString('trade_no=fixture-checkout-order', $checkoutUrl);
        $this->assertStringContainsString('amount=1125', $checkoutUrl);
        $this->assertStringContainsString(rawurlencode('/api/v1/guest/payment/notify/' . SyntheticPaymentPlugin::METHOD . '/' . $payment->uuid), $checkoutUrl);
        $this->assertStringContainsString('return_url=https%3A%2F%2Fclient.example.invalid%2F%23%2Forder%2Ffixture-checkout-order', $checkoutUrl);
        $this->assertStringContainsString('stripe_token=fixture-stripe-token', $checkoutUrl);

        $order->refresh();
        $this->assertSame($payment->id, $order->payment_id);
        $this->assertSame(125, $order->handling_amount);
        $this->assertSame(Order::STATUS_PENDING, $order->status);
        $releasedLock = Cache::lock($this->checkoutLockKey($user, $order), 15);
        $this->assertTrue($releasedLock->get());
        $releasedLock->release();
    }

    public function test_checkout_in_flight_lock_rejects_duplicate_without_calling_provider(): void
    {
        $user = $this->makeUser(['email' => 'fixture-locked-checkout@example.invalid']);
        $plan = $this->makePlan(['name' => 'Fixture locked checkout plan']);
        $payment = $this->makePayment();
        $order = $this->makePendingOrder($user, $plan, [
            'trade_no' => 'fixture-locked-checkout-order',
            'total_amount' => 1000,
        ]);
        $lock = Cache::lock($this->checkoutLockKey($user, $order), 15);
        $this->assertTrue($lock->get());

        try {
            Sanctum::actingAs($user);

            $this->postJson('/api/v1/user/order/checkout', [
                'trade_no' => $order->trade_no,
                'method' => $payment->id,
            ])
                ->assertStatus(429)
                ->assertJson([
                    'status' => 'fail',
                    'message' => 'Checkout is already processing, please try again later',
                ]);

            $order->refresh();
            $this->assertNull($order->payment_id);
            $this->assertNull($order->handling_amount);
            $this->assertSame(Order::STATUS_PENDING, $order->status);
        } finally {
            $lock->release();
        }
    }

    public function test_callback_success_marks_pending_order_as_processing_and_preserves_success_body(): void
    {
        Bus::fake();

        $user = $this->makeUser(['email' => 'fixture-callback@example.invalid']);
        $plan = $this->makePlan(['name' => 'Fixture callback plan']);
        $payment = $this->makePayment();
        $order = $this->makePendingOrder($user, $plan, [
            'trade_no' => 'fixture-callback-order',
            'total_amount' => 1000,
        ]);

        $this->postJson("/api/v1/guest/payment/notify/{$payment->payment}/{$payment->uuid}", [
            'fixture_signature' => SyntheticPaymentPlugin::VALID_SIGNATURE,
            'trade_no' => $order->trade_no,
            'callback_no' => 'fixture-callback-no',
        ])
            ->assertOk()
            ->assertContent('success');

        $order->refresh();
        $this->assertSame(Order::STATUS_PROCESSING, $order->status);
        $this->assertSame('fixture-callback-no', $order->callback_no);
        $this->assertNotNull($order->paid_at);
    }

    public function test_callback_invalid_payload_keeps_existing_failure_envelope_and_order_pending(): void
    {
        Bus::fake();
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use (&$payment): bool {
                return $message === 'payment_notify_verification_failed'
                    && $context['method'] === SyntheticPaymentPlugin::METHOD
                    && $context['uuid_hash'] === hash('sha256', $payment->uuid)
                    && $context['reason'] === 'verify_false'
                    && in_array('fixture_signature', $context['payload_keys'], true)
                    && in_array('trade_no', $context['payload_keys'], true)
                    && !array_key_exists('payload', $context);
            });

        $user = $this->makeUser(['email' => 'fixture-invalid-callback@example.invalid']);
        $plan = $this->makePlan(['name' => 'Fixture invalid callback plan']);
        $payment = $this->makePayment();
        $order = $this->makePendingOrder($user, $plan, [
            'trade_no' => 'fixture-invalid-callback-order',
            'total_amount' => 1000,
        ]);

        $this->postJson("/api/v1/guest/payment/notify/{$payment->payment}/{$payment->uuid}", [
            'fixture_signature' => 'invalid-signature',
            'trade_no' => $order->trade_no,
            'callback_no' => 'fixture-callback-no',
        ])
            ->assertStatus(422)
            ->assertJson([
                'status' => 'fail',
                'message' => 'verify error',
            ]);

        $order->refresh();
        $this->assertSame(Order::STATUS_PENDING, $order->status);
        $this->assertNull($order->callback_no);
        $this->assertNull($order->paid_at);
    }

    public function test_callback_duplicate_for_non_pending_order_is_idempotent(): void
    {
        Bus::fake();

        $user = $this->makeUser(['email' => 'fixture-duplicate-callback@example.invalid']);
        $plan = $this->makePlan(['name' => 'Fixture duplicate callback plan']);
        $payment = $this->makePayment();
        $order = $this->makePendingOrder($user, $plan, [
            'trade_no' => 'fixture-duplicate-callback-order',
            'total_amount' => 1000,
            'status' => Order::STATUS_PROCESSING,
            'callback_no' => 'original-callback-no',
            'paid_at' => time() - 60,
        ]);

        $this->postJson("/api/v1/guest/payment/notify/{$payment->payment}/{$payment->uuid}", [
            'fixture_signature' => SyntheticPaymentPlugin::VALID_SIGNATURE,
            'trade_no' => $order->trade_no,
            'callback_no' => 'duplicate-callback-no',
        ])
            ->assertOk()
            ->assertContent('success');

        $order->refresh();
        $this->assertSame(Order::STATUS_PROCESSING, $order->status);
        $this->assertSame('original-callback-no', $order->callback_no);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeUser(array $overrides = []): User
    {
        return User::create(array_replace([
            'email' => 'fixture-user@example.invalid',
            'password' => str_repeat('x', 60),
            'uuid' => '00000000-0000-0000-0000-000000000101',
            'token' => str_repeat('a', 32),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => false,
            'is_admin' => false,
            'is_staff' => false,
            'remind_expire' => 1,
            'remind_traffic' => 1,
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makePlan(array $overrides = []): Plan
    {
        return Plan::create(array_replace([
            'group_id' => 1,
            'transfer_enable' => 100,
            'name' => 'Fixture plan',
            'speed_limit' => null,
            'show' => true,
            'sort' => 1,
            'renew' => true,
            'content' => null,
            'prices' => [
                Plan::PERIOD_MONTHLY => 10,
            ],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER,
            'capacity_limit' => null,
            'sell' => true,
            'device_limit' => null,
            'tags' => [],
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makePayment(array $overrides = []): Payment
    {
        return Payment::create(array_replace([
            'uuid' => 'fixturepaymentuuid00000000000000',
            'payment' => SyntheticPaymentPlugin::METHOD,
            'name' => 'Fixture Pay',
            'icon' => '🧪',
            'config' => [
                'fixture_key' => 'fixture-value',
            ],
            'notify_domain' => null,
            'handling_fee_fixed' => null,
            'handling_fee_percent' => null,
            'enable' => true,
            'sort' => 1,
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makePendingOrder(User $user, Plan $plan, array $overrides = []): Order
    {
        return Order::create(array_replace([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_id' => null,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'fixture-order',
            'total_amount' => 1000,
            'handling_amount' => null,
            'discount_amount' => null,
            'surplus_amount' => null,
            'refund_amount' => null,
            'balance_amount' => null,
            'surplus_order_ids' => null,
            'status' => Order::STATUS_PENDING,
            'commission_status' => false,
            'commission_balance' => 0,
            'actual_commission_balance' => null,
            'paid_at' => null,
        ], $overrides));
    }

    private function checkoutLockKey(User $user, Order $order): string
    {
        return "payment:checkout:{$user->id}:" . sha1($order->trade_no);
    }

    private function skipIfConfiguredDatabaseDriverIsUnavailable(): void
    {
        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");

        if ($driver !== '' && !in_array($driver, PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped("Configured PDO driver [{$driver}] is unavailable in this PHP runtime.");
        }

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $exception) {
            $this->markTestSkipped('Configured test database is unavailable: ' . $exception->getMessage());
        }

        foreach (['v2_user', 'v2_plan', 'v2_payment', 'v2_order'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->markTestSkipped("Required fixture table [{$table}] is unavailable.");
            }
        }
    }
}
