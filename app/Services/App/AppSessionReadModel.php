<?php

declare(strict_types=1);

namespace App\Services\App;

use App\Models\User;
use App\Services\User\MembershipStatusService;

final class AppSessionReadModel
{
    public function __construct(
        private readonly MembershipStatusService $membershipStatusService
    ) {
    }

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
        $traffic = $this->trafficSnapshot($user);

        return [
            'user' => $this->userPayload($user),
            'subscription' => $this->subscriptionPayload($user, $traffic),
            'traffic' => $this->trafficPayload($traffic),
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
            'phone' => $user->phone,
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
     * @param array{upload: int, download: int, used: int, total: int, remaining: int, usage_percent: float} $traffic
     * @return array<string, mixed>
     */
    private function subscriptionPayload(User $user, array $traffic): array
    {
        $membership = $this->membershipStatusService->build($user);

        return [
            'status' => $membership['subscription_status'],
            'membership_status' => $membership['membership_status'],
            'membership_label' => $membership['membership_label'],
            'is_member' => $membership['is_member'],
            'can_connect' => $membership['can_connect'],
            'active' => $user->isActive(),
            'plan_id' => $user->plan_id,
            'plan_name' => $membership['plan_name'],
            'expired_at' => $user->expired_at,
            'next_reset_at' => $user->next_reset_at,
            'device_limit' => $user->device_limit,
            'speed_limit' => $user->speed_limit,
            'delivery_available' => (bool) $membership['can_connect'],
        ];
    }

    /**
     * @param array{upload: int, download: int, used: int, total: int, remaining: int, usage_percent: float} $traffic
     * @return array<string, int|float>
     */
    private function trafficPayload(array $traffic): array
    {
        return [
            'upload' => $traffic['upload'],
            'download' => $traffic['download'],
            'used' => $traffic['used'],
            'total' => $traffic['total'],
            'remaining' => $traffic['remaining'],
            'usage_percent' => $traffic['usage_percent'],
        ];
    }

    /**
     * @return array{upload: int, download: int, used: int, total: int, remaining: int, usage_percent: float}
     */
    private function trafficSnapshot(User $user): array
    {
        $upload = (int) ($user->u ?? 0);
        $download = (int) ($user->d ?? 0);
        $used = $upload + $download;
        $total = (int) ($user->transfer_enable ?? 0);
        $remaining = max(0, $total - $used);
        $usagePercent = $total > 0 ? round(min(100, ($used / $total) * 100), 2) : 0.0;

        return [
            'upload' => $upload,
            'download' => $download,
            'used' => $used,
            'total' => $total,
            'remaining' => $remaining,
            'usage_percent' => $usagePercent,
        ];
    }

}
