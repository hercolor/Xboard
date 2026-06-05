<?php

namespace App\Services\User;

use App\Models\User;

class LegacyUserInfoReadModel
{
    public const COLUMNS = [
        'email',
        'phone',
        'transfer_enable',
        'last_login_at',
        'created_at',
        'banned',
        'remind_expire',
        'remind_traffic',
        'expired_at',
        'balance',
        'commission_balance',
        'plan_id',
        'discount',
        'commission_rate',
        'telegram_id',
        'uuid',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public function forUserId(int $userId): ?array
    {
        $user = User::query()
            ->whereKey($userId)
            ->first(self::COLUMNS);

        if (!$user instanceof User) {
            return null;
        }

        $payload = $user->toArray();
        $payload['avatar_url'] = $this->avatarUrl((string) $user->email);

        return $payload;
    }

    private function avatarUrl(string $email): string
    {
        return 'https://cdn.v2ex.com/gravatar/' . md5($email) . '?s=64&d=identicon';
    }
}
