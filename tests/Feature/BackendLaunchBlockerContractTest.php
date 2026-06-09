<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\InitializePlugins;
use App\Models\Plan;
use App\Models\User;
use App\Services\App\AppSessionReadModel;
use App\Services\User\MembershipStatusService;
use App\Utils\CacheKey;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AppApi\AppBffFixtures;
use Tests\TestCase;

final class BackendLaunchBlockerContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'api_security.rate_limits.enabled' => false,
            'cache.default' => 'array',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->withoutMiddleware(InitializePlugins::class);
        $this->createAuthAndSubscriptionTables();
    }

    public function test_app_session_delivery_permission_matches_connection_permission_for_expired_and_no_plan_users(): void
    {
        $readModel = new AppSessionReadModel(new MembershipStatusService());

        $expiredUser = AppBffFixtures::user([
            'plan_id' => 7,
            'expired_at' => time() - 60,
            'token' => 'expired-user-subscribe-token',
        ]);
        $expiredUser->setRelation('plan', new Plan([
            'id' => 7,
            'name' => '蝴蝶月卡',
        ]));

        $expiredSubscription = $readModel->forUser($expiredUser)['subscription'];

        $this->assertSame('expired', $expiredSubscription['status']);
        $this->assertFalse($expiredSubscription['active']);
        $this->assertFalse($expiredSubscription['is_member']);
        $this->assertFalse($expiredSubscription['can_connect']);
        $this->assertFalse($expiredSubscription['delivery_available']);

        $noPlanUser = AppBffFixtures::user([
            'plan_id' => null,
            'expired_at' => null,
            'token' => 'normal-user-subscribe-token',
        ]);

        $noPlanSubscription = $readModel->forUser($noPlanUser)['subscription'];

        $this->assertSame('no_plan', $noPlanSubscription['status']);
        $this->assertFalse($noPlanSubscription['active']);
        $this->assertFalse($noPlanSubscription['is_member']);
        $this->assertFalse($noPlanSubscription['can_connect']);
        $this->assertFalse($noPlanSubscription['delivery_available']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('expiredOrNoPlanUserProvider')]
    public function test_legacy_get_subscribe_hides_subscribe_url_for_non_connectable_users(
        array $overrides,
        string $expectedStatus,
        string $expectedMembershipStatus
    ): void {
        $this->seedPlan();
        $this->seedUser($overrides);

        $user = User::findOrFail(42);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/user/getSubscribe');

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'subscription_status' => $expectedStatus,
                    'membership_status' => $expectedMembershipStatus,
                    'can_connect' => false,
                    'delivery_available' => false,
                ],
            ]);

        $this->assertSame('', $response->json('data.subscribe_url'));
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertStringNotContainsString('/s/' . $response->json('data.token'), (string) $response->json('data.subscribe_url'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function expiredOrNoPlanUserProvider(): array
    {
        return [
            'expired member' => [
                [
                    'plan_id' => 7,
                    'expired_at' => time() - 60,
                ],
                'expired',
                MembershipStatusService::STATUS_EXPIRED,
            ],
            'normal user without plan' => [
                [
                    'plan_id' => null,
                    'expired_at' => null,
                ],
                'no_plan',
                MembershipStatusService::STATUS_NORMAL,
            ],
        ];
    }

    #[DataProvider('passportPrefixProvider')]
    public function test_passport_login_accepts_phone_alias(string $prefix): void
    {
        $this->seedUser(['phone' => '+15551234567']);

        $response = $this->postJson("/{$prefix}/passport/auth/login", [
            'phone' => '+1 (555) 123-4567',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'email' => 'launch-blocker@example.invalid',
                    'phone' => '+15551234567',
                ],
            ]);

        $this->assertIsString($response->json('data.auth_data'));
    }

    #[DataProvider('passportPrefixProvider')]
    public function test_passport_forget_accepts_phone_and_code_aliases(string $prefix): void
    {
        $phone = '+15551234567';
        $this->seedUser(['phone' => $phone]);

        Cache::put(CacheKey::get('PHONE_VERIFY_CODE', 'forget:' . sha1($phone)), '123456', 300);

        $response = $this->postJson("/{$prefix}/passport/auth/forget", [
            'phone' => '+1 (555) 123-4567',
            'password' => 'newpass123',
            'code' => '123456',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'data' => true,
            ]);

        $this->postJson("/{$prefix}/passport/auth/login", [
            'phone' => '+15551234567',
            'password' => 'newpass123',
        ])->assertOk();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function passportPrefixProvider(): array
    {
        return [
            'v1' => ['api/v1'],
            'v2' => ['api/v2'],
        ];
    }

    private function createAuthAndSubscriptionTables(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('v2_order');
        Schema::dropIfExists('v2_plan');
        Schema::dropIfExists('v2_user');

        Schema::create('v2_user', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email', 64)->unique();
            $table->string('phone', 32)->nullable()->unique();
            $table->string('password', 255);
            $table->string('password_algo', 10)->nullable();
            $table->string('password_salt', 10)->nullable();
            $table->integer('balance')->default(0);
            $table->integer('commission_balance')->default(0);
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->bigInteger('transfer_enable')->default(0);
            $table->boolean('banned')->default(false);
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_staff')->default(false);
            $table->integer('last_login_at')->nullable();
            $table->string('uuid', 36);
            $table->integer('plan_id')->nullable();
            $table->integer('discount')->nullable();
            $table->integer('commission_rate')->nullable();
            $table->integer('device_limit')->nullable();
            $table->integer('speed_limit')->nullable();
            $table->boolean('remind_expire')->default(true);
            $table->boolean('remind_traffic')->default(true);
            $table->string('token', 32);
            $table->bigInteger('expired_at')->nullable();
            $table->bigInteger('next_reset_at')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        Schema::create('v2_plan', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('group_id')->nullable();
            $table->bigInteger('transfer_enable')->default(0);
            $table->string('name');
            $table->json('prices')->nullable();
            $table->integer('speed_limit')->nullable();
            $table->boolean('show')->default(true);
            $table->boolean('sell')->default(true);
            $table->integer('sort')->nullable();
            $table->boolean('renew')->default(true);
            $table->integer('reset_traffic_method')->nullable();
            $table->integer('capacity_limit')->nullable();
            $table->integer('device_limit')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        Schema::create('v2_order', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('plan_id')->nullable();
            $table->string('period')->nullable();
            $table->integer('status')->default(0);
            $table->integer('paid_at')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function seedUser(array $overrides = []): void
    {
        DB::table('v2_user')->insert(array_replace([
            'id' => 42,
            'email' => 'launch-blocker@example.invalid',
            'phone' => '+15551234567',
            'password' => password_hash('password123', PASSWORD_DEFAULT),
            'password_algo' => null,
            'password_salt' => null,
            'balance' => 0,
            'commission_balance' => 0,
            'u' => 120,
            'd' => 80,
            'transfer_enable' => 1000,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'last_login_at' => null,
            'uuid' => '00000000-0000-0000-0000-000000000042',
            'plan_id' => 7,
            'discount' => null,
            'commission_rate' => null,
            'device_limit' => 3,
            'speed_limit' => 50,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'token' => 'legacy-subscribe-token-00000042',
            'expired_at' => time() + 3600,
            'next_reset_at' => time() + 86400,
            'created_at' => time() - 86400,
            'updated_at' => time() - 3600,
        ], $overrides));
    }

    private function seedPlan(): void
    {
        DB::table('v2_plan')->insert([
            'id' => 7,
            'group_id' => 1,
            'transfer_enable' => 1000,
            'name' => '蝴蝶月卡',
            'prices' => json_encode(['monthly' => 10], JSON_THROW_ON_ERROR),
            'speed_limit' => 50,
            'show' => 1,
            'sell' => 1,
            'sort' => 1,
            'renew' => 1,
            'reset_traffic_method' => null,
            'capacity_limit' => 0,
            'device_limit' => 3,
            'created_at' => time() - 86400,
            'updated_at' => time() - 3600,
        ]);
    }
}
