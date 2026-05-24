<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;

final class LegacyOrderReadModel
{
    public const PLAN_COLUMNS = [
        'id',
        'group_id',
        'name',
        'tags',
        'content',
        'prices',
        'capacity_limit',
        'transfer_enable',
        'speed_limit',
        'device_limit',
        'show',
        'sell',
        'renew',
        'reset_traffic_method',
        'sort',
        'created_at',
        'updated_at',
    ];

    public const PAYMENT_COLUMNS = [
        'id',
        'name',
        'payment',
        'icon',
    ];

    /**
     * @return Collection<int, Order>
     */
    public function fetchForUser(int $userId, ?int $status): Collection
    {
        return Order::query()
            ->with(['plan' => fn ($query) => $query->select(self::PLAN_COLUMNS)])
            ->where('user_id', $userId)
            ->when($status !== null, function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->orderBy('created_at', 'DESC')
            ->get();
    }

    public function detailForUser(int $userId, string $tradeNo): ?Order
    {
        return Order::query()
            ->with([
                'payment' => fn ($query) => $query->select(self::PAYMENT_COLUMNS),
                'plan' => fn ($query) => $query->select(self::PLAN_COLUMNS),
            ])
            ->where('user_id', $userId)
            ->where('trade_no', $tradeNo)
            ->first();
    }
}
