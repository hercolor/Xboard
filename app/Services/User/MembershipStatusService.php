<?php

namespace App\Services\User;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;

class MembershipStatusService
{
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_NORMAL = 'normal';
    public const STATUS_MONTH = 'month';
    public const STATUS_QUARTER = 'quarter';
    public const STATUS_YEAR = 'year';

    /**
     * Build the stable membership summary consumed by web/App clients.
     *
     * membership_status and membership_label intentionally stay in the small
     * product vocabulary requested by the client UI:
     * 会员到期 / 普通用户 / 蝴蝶月卡 / 蝴蝶季卡 / 蝴蝶年卡.
     *
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $remainingTraffic = $this->remainingTraffic($user);
        $subscriptionStatus = $this->subscriptionStatus($user, $remainingTraffic);
        $membershipStatus = $this->membershipStatus($user, $subscriptionStatus);
        $plan = $user->plan_id ? $user->plan : null;

        return [
            'membership_status' => $membershipStatus,
            'membership_label' => $this->membershipLabel($membershipStatus),
            'subscription_status' => $subscriptionStatus,
            'is_member' => in_array($membershipStatus, [
                self::STATUS_MONTH,
                self::STATUS_QUARTER,
                self::STATUS_YEAR,
            ], true),
            'can_connect' => $subscriptionStatus === 'active',
            'plan_id' => $user->plan_id,
            'plan_name' => $plan?->name,
            'device_limit' => $user->device_limit,
            'expired_at' => $user->expired_at,
            'remaining_traffic' => $remainingTraffic,
        ];
    }

    private function subscriptionStatus(User $user, int $remainingTraffic): string
    {
        if ($user->banned) {
            return 'banned';
        }

        if (!$user->plan_id) {
            return 'no_plan';
        }

        if ($user->expired_at !== null && (int) $user->expired_at <= time()) {
            return 'expired';
        }

        if ($remainingTraffic <= 0) {
            return 'traffic_exhausted';
        }

        return 'active';
    }

    private function membershipStatus(User $user, string $subscriptionStatus): string
    {
        if (!$user->plan_id) {
            return self::STATUS_NORMAL;
        }

        if ($subscriptionStatus === 'expired') {
            return self::STATUS_EXPIRED;
        }

        return $this->detectActivePeriod($user);
    }

    private function membershipLabel(string $membershipStatus): string
    {
        return match ($membershipStatus) {
            self::STATUS_EXPIRED => '会员到期',
            self::STATUS_MONTH => '蝴蝶月卡',
            self::STATUS_QUARTER => '蝴蝶季卡',
            self::STATUS_YEAR => '蝴蝶年卡',
            default => '普通用户',
        };
    }

    private function detectActivePeriod(User $user): string
    {
        $period = $this->latestCompletedPeriod($user);
        $status = $this->statusFromPeriod($period);
        if ($status !== null) {
            return $status;
        }

        $plan = $user->plan_id ? $user->plan : null;
        $status = $this->statusFromPlanName($plan?->name);
        if ($status !== null) {
            return $status;
        }

        $status = $this->statusFromPlanPrices($plan);
        if ($status !== null) {
            return $status;
        }

        return self::STATUS_MONTH;
    }

    private function latestCompletedPeriod(User $user): ?string
    {
        if (!$user->id || !$user->plan_id) {
            return null;
        }

        $period = Order::query()
            ->where('user_id', $user->id)
            ->where('plan_id', $user->plan_id)
            ->where('status', Order::STATUS_COMPLETED)
            ->whereNotIn('period', [Plan::PERIOD_RESET_TRAFFIC])
            ->orderByDesc('paid_at')
            ->orderByDesc('created_at')
            ->value('period');

        return is_string($period) && trim($period) !== '' ? trim($period) : null;
    }

    private function statusFromPeriod(?string $period): ?string
    {
        return match ($period) {
            Plan::PERIOD_MONTHLY, 'month_price' => self::STATUS_MONTH,
            Plan::PERIOD_QUARTERLY, 'quarter_price' => self::STATUS_QUARTER,
            Plan::PERIOD_YEARLY,
            Plan::PERIOD_HALF_YEARLY,
            Plan::PERIOD_TWO_YEARLY,
            Plan::PERIOD_THREE_YEARLY,
            'year_price',
            'half_year_price',
            'two_year_price',
            'three_year_price' => self::STATUS_YEAR,
            default => null,
        };
    }

    private function statusFromPlanName(?string $name): ?string
    {
        $value = trim((string) $name);
        if ($value === '') {
            return null;
        }

        if (str_contains($value, '季') || stripos($value, 'quarter') !== false) {
            return self::STATUS_QUARTER;
        }

        if (str_contains($value, '年') || stripos($value, 'year') !== false) {
            return self::STATUS_YEAR;
        }

        if (str_contains($value, '月') || stripos($value, 'month') !== false) {
            return self::STATUS_MONTH;
        }

        return null;
    }

    private function statusFromPlanPrices(?Plan $plan): ?string
    {
        $prices = $plan?->prices ?? [];
        if (!is_array($prices) || $prices === []) {
            return null;
        }

        $activePeriods = array_keys(array_filter(
            $prices,
            fn ($price) => is_numeric($price) && (int) $price > 0
        ));

        if (count($activePeriods) !== 1) {
            return null;
        }

        return $this->statusFromPeriod((string) $activePeriods[0]);
    }

    private function remainingTraffic(User $user): int
    {
        $upload = (int) ($user->u ?? 0);
        $download = (int) ($user->d ?? 0);
        $total = (int) ($user->transfer_enable ?? 0);

        return max(0, $total - $upload - $download);
    }
}
