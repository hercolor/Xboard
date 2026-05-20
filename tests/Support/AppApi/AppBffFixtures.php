<?php

declare(strict_types=1);

namespace Tests\Support\AppApi;

use App\Models\User;

final class AppBffFixtures
{
    /**
     * Build an in-memory user fixture for App BFF contract tests.
     *
     * The defaults intentionally contain sensitive legacy fields so tests can
     * prove the App BFF read models expose only their allowlisted payload.
     *
     * @param array<string, mixed> $overrides
     */
    public static function user(array $overrides = []): User
    {
        $user = new User();

        foreach (array_replace(self::userDefaults(), $overrides) as $key => $value) {
            $user->{$key} = $value;
        }

        return $user;
    }

    /**
     * @return array<int, string>
     */
    public static function sensitiveNeedles(): array
    {
        return [
            'secret-subscribe-token',
            'subscribe_url',
            'subscribeUrl',
            'subscription_url',
            'clash_url',
            'mihomo_url',
            '00000000-0000-0000-0000-000000000001',
            'auth_data',
        ];
    }

    /**
     * Future dashboard candidate fixture rows. These are intentionally plain
     * arrays so dashboard test planning can validate field budgets before any
     * route or controller exists.
     *
     * @return array{
     *     orders_summary: array{unpaid_count: int, pending_count: int, latest: array<int, array<string, mixed>>},
     *     tickets_summary: array{open_count: int, latest: array<int, array<string, mixed>>},
     *     notices: array<int, array<string, mixed>>
     * }
     */
    public static function futureDashboardCandidateRows(): array
    {
        return [
            'orders_summary' => [
                'unpaid_count' => 1,
                'pending_count' => 1,
                'latest' => [
                    [
                        'trade_no' => 'SAFE-ORDER-1',
                        'status' => 0,
                        'period' => 'monthly',
                        'total_amount' => 100,
                        'created_at' => 1770000200,
                    ],
                ],
            ],
            'tickets_summary' => [
                'open_count' => 1,
                'latest' => [
                    [
                        'id' => 9,
                        'level' => 'medium',
                        'reply_status' => 0,
                        'status' => 0,
                        'subject' => 'Safe support subject',
                        'created_at' => 1770000300,
                        'updated_at' => 1770000400,
                    ],
                ],
            ],
            'notices' => [
                [
                    'id' => 3,
                    'title' => 'Safe public notice',
                    'created_at' => 1770000500,
                    'updated_at' => 1770000600,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function userDefaults(): array
    {
        return [
            'id' => 42,
            'email' => 'app-session@example.invalid',
            'token' => 'secret-subscribe-token',
            'uuid' => '00000000-0000-0000-0000-000000000001',
            'plan_id' => 7,
            'transfer_enable' => 1000,
            'u' => 120,
            'd' => 80,
            'expired_at' => time() + 3600,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => false,
            'remind_expire' => 1,
            'remind_traffic' => 0,
            'device_limit' => 3,
            'speed_limit' => 50,
            'next_reset_at' => time() + 86400,
            'created_at' => 1770000000,
            'last_login_at' => 1770000100,
        ];
    }
}
