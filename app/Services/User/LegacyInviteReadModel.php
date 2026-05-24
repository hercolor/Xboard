<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\CommissionLog;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class LegacyInviteReadModel
{
    public const CODE_COLUMNS = [
        'user_id',
        'code',
        'pv',
        'status',
        'created_at',
        'updated_at',
    ];

    /**
     * Preserve legacy `/api/v1/user/invite/fetch` data contract:
     * `codes` resource collection and `stat` positional array.
     *
     * @return array{codes: mixed, stat: array{0: int, 1: int, 2: int|float, 3: int, 4: int}}
     */
    public function fetchForUser(User $user): array
    {
        $codes = InviteCode::query()
            ->where('user_id', $user->id)
            ->where('status', InviteCode::STATUS_UNUSED)
            ->select(self::CODE_COLUMNS)
            ->get();

        $aggregates = $this->aggregatesForUserId((int) $user->id);
        $commissionRate = $user->commission_rate ?: admin_setting('invite_commission', 10);
        $uncheckCommissionBalance = (int) $aggregates['uncheck_commission_balance'];

        if (admin_setting('commission_distribution_enable', 0)) {
            $uncheckCommissionBalance = $uncheckCommissionBalance * (admin_setting('commission_distribution_l1') / 100);
        }

        return [
            'codes' => $codes,
            'stat' => [
                (int) $aggregates['invited_user_count'],
                (int) $aggregates['checked_commission_balance'],
                $uncheckCommissionBalance,
                (int) $commissionRate,
                (int) $user->commission_balance,
            ],
        ];
    }

    /**
     * @return array{invited_user_count: int, checked_commission_balance: int, uncheck_commission_balance: int}
     */
    private function aggregatesForUserId(int $userId): array
    {
        $row = DB::query()
            ->selectSub($this->invitedUsersQuery($userId), 'invited_user_count')
            ->selectSub($this->checkedCommissionQuery($userId), 'checked_commission_balance')
            ->selectSub($this->uncheckCommissionQuery($userId), 'uncheck_commission_balance')
            ->first();

        return [
            'invited_user_count' => (int) ($row->invited_user_count ?? 0),
            'checked_commission_balance' => (int) ($row->checked_commission_balance ?? 0),
            'uncheck_commission_balance' => (int) ($row->uncheck_commission_balance ?? 0),
        ];
    }

    private function invitedUsersQuery(int $userId): Builder
    {
        return User::query()
            ->selectRaw('COUNT(*)')
            ->where('invite_user_id', $userId);
    }

    private function checkedCommissionQuery(int $userId): Builder
    {
        return CommissionLog::query()
            ->selectRaw('COALESCE(SUM(get_amount), 0)')
            ->where('invite_user_id', $userId);
    }

    private function uncheckCommissionQuery(int $userId): Builder
    {
        return Order::query()
            ->selectRaw('COALESCE(SUM(commission_balance), 0)')
            ->where('status', Order::STATUS_COMPLETED)
            ->where('commission_status', 0)
            ->where('invite_user_id', $userId);
    }
}
