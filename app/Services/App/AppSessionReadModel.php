<?php

declare(strict_types=1);

namespace App\Services\App;

use App\Models\User;

final class AppSessionReadModel
{
    /**
     * Build the safe App BFF session payload from the authenticated user model.
     *
     * This read model is intentionally allowlist-only. It must not expose legacy
     * subscription delivery fields such as token, subscribe_url, uuid, or auth_data.
     *
     * @return array{
     *     user: array<string, mixed>,
     *     subscription: array<string, mixed>,
     *     traffic: array<string, int|float>,
     *     preferences: array<string, bool>
     * }
     */
    public function forUser(User $user): array
    {
        return [
            'user' => $this->userPayload($user),
            'subscription' => $this->subscriptionPayload($user),
            'traffic' => $this->trafficPayload($user),
            'preferences' => [
                'remind_expire' => (bool) $user->remind_expire,
                'remind_traffic' => (bool) $user->remind_traffic,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'avatar_url' => 'https://cdn.v2ex.com/gravatar/' . md5($user->email) . '?s=64&d=identicon',
            'is_admin' => (bool) $user->is_admin,
            'is_staff' => (bool) $user->is_staff,
            'banned' => (bool) $user->banned,
            'created_at' => $user->created_at,
            'last_login_at' => $user->last_login_at,
            'telegram_bound' => !empty($user->telegram_id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionPayload(User $user): array
    {
        return [
            'status' => $this->subscriptionStatus($user),
            'active' => $user->isActive(),
            'plan_id' => $user->plan_id,
            'expired_at' => $user->expired_at,
            'next_reset_at' => $user->next_reset_at,
            'device_limit' => $user->device_limit,
            'speed_limit' => $user->speed_limit,
            'delivery_available' => !empty($user->token),
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function trafficPayload(User $user): array
    {
        return [
            'upload' => (int) ($user->u ?? 0),
            'download' => (int) ($user->d ?? 0),
            'used' => $user->getTotalUsedTraffic(),
            'total' => (int) ($user->transfer_enable ?? 0),
            'remaining' => $user->getRemainingTraffic(),
            'usage_percent' => round($user->getTrafficUsagePercentage(), 2),
        ];
    }

    private function subscriptionStatus(User $user): string
    {
        if ($user->banned) {
            return 'banned';
        }

        if (!$user->plan_id) {
            return 'no_plan';
        }

        if ($user->expired_at !== null && $user->expired_at <= time()) {
            return 'expired';
        }

        if ($user->getRemainingTraffic() <= 0) {
            return 'traffic_exhausted';
        }

        return 'active';
    }
}
