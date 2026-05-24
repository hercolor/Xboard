<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class LegacyUserStatReadModel
{
    /**
     * Preserve the legacy `/api/v1/user/getStat` positional array contract:
     * [pending order count, open ticket count, invited user count].
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public function forUserId(int $userId): array
    {
        $row = DB::query()
            ->selectSub($this->pendingOrdersQuery($userId), 'pending_order_count')
            ->selectSub($this->openTicketsQuery($userId), 'open_ticket_count')
            ->selectSub($this->invitedUsersQuery($userId), 'invited_user_count')
            ->first();

        return [
            (int) ($row->pending_order_count ?? 0),
            (int) ($row->open_ticket_count ?? 0),
            (int) ($row->invited_user_count ?? 0),
        ];
    }

    private function pendingOrdersQuery(int $userId): Builder
    {
        return Order::query()
            ->selectRaw('COUNT(*)')
            ->where('status', Order::STATUS_PENDING)
            ->where('user_id', $userId);
    }

    private function openTicketsQuery(int $userId): Builder
    {
        return Ticket::query()
            ->selectRaw('COUNT(*)')
            ->where('status', Ticket::STATUS_OPENING)
            ->where('user_id', $userId);
    }

    private function invitedUsersQuery(int $userId): Builder
    {
        return User::query()
            ->selectRaw('COUNT(*)')
            ->where('invite_user_id', $userId);
    }
}
