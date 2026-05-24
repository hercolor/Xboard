<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\StatUser;
use Illuminate\Database\Eloquent\Collection;

final class LegacyTrafficReadModel
{
    public const TRAFFIC_LOG_COLUMNS = [
        'user_id',
        'u',
        'd',
        'record_at',
        'server_rate',
    ];

    /**
     * @return Collection<int, StatUser>
     */
    public function monthlyTrafficLogsForUser(int $userId, int $startAt): Collection
    {
        return StatUser::query()
            ->select(self::TRAFFIC_LOG_COLUMNS)
            ->where('user_id', $userId)
            ->where('record_at', '>=', $startAt)
            ->orderBy('record_at', 'DESC')
            ->get();
    }
}
