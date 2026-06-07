<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Console\Command;

class SyncExpiredUsers extends Command
{
    protected $signature = 'sync:expired-users';

    protected $description = '通知在线节点移除自然到期的用户';

    public function handle(): int
    {
        $now = time();
        $expiredUsers = User::toBase()
            ->whereNotNull('group_id')
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', 0)
            ->where('expired_at', '<=', $now)
            ->select(['id', 'group_id'])
            ->get();

        if ($expiredUsers->isEmpty()) {
            return self::SUCCESS;
        }

        $notifiedNodes = 0;
        foreach ($expiredUsers->groupBy('group_id') as $groupId => $users) {
            if (!$groupId) {
                continue;
            }

            $servers = Server::whereJsonContains('group_ids', (string) $groupId)->get();
            $payloadUsers = $users
                ->pluck('id')
                ->map(fn ($id) => ['id' => (int) $id])
                ->values()
                ->all();

            foreach ($servers as $server) {
                if (!NodeSyncService::isNodeOnline($server->id)) {
                    continue;
                }

                NodeSyncService::push($server->id, 'sync.user.delta', [
                    'action' => 'remove',
                    'users' => $payloadUsers,
                ]);
                $notifiedNodes++;
            }
        }

        $this->info('Expired users checked: ' . $expiredUsers->count() . ", notified nodes: {$notifiedNodes}");
        return self::SUCCESS;
    }
}
