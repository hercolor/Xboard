<?php

declare(strict_types=1);

namespace App\Services\App;

use App\Models\Notice;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class AppDashboardReadModel
{
    private const LATEST_LIMIT = 5;

    public function __construct(
        private readonly AppSessionReadModel $sessionReadModel
    ) {
    }

    /**
     * Build the safe read-only App BFF dashboard payload.
     *
     * This endpoint is an allowlist-only aggregate. It must never expose
     * subscription delivery tokens/URLs, raw auth data, node credentials,
     * payment provider payloads, full ticket messages, or knowledge bodies.
     *
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $session = $this->sessionReadModel->forUser($user);

        return [
            'session_summary' => [
                'user' => $session['user'],
            ],
            'subscription_summary' => $session['subscription'],
            'traffic_summary' => $session['traffic'],
            'orders_summary' => $this->ordersSummary($user),
            'tickets_summary' => $this->ticketsSummary($user),
            'notices' => $this->notices(),
            'support' => new \stdClass(),
        ];
    }

    /**
     * @return array{unpaid_count: int, pending_count: int, latest: array<int, array<string, mixed>>}
     */
    private function ordersSummary(User $user): array
    {
        /** @var Collection<int|string, int|string> $statusCounts */
        $statusCounts = Order::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $latest = Order::query()
            ->where('user_id', $user->id)
            ->select(['trade_no', 'status', 'period', 'total_amount', 'created_at'])
            ->orderByDesc('created_at')
            ->limit(self::LATEST_LIMIT)
            ->get()
            ->map(fn (Order $order): array => [
                'trade_no' => $order->trade_no,
                'status' => (int) $order->status,
                'period' => $order->period,
                'total_amount' => (int) $order->total_amount,
                'created_at' => $this->timestamp($order->created_at),
            ])
            ->values()
            ->all();

        return [
            'unpaid_count' => (int) ($statusCounts[(string) Order::STATUS_PENDING] ?? $statusCounts[Order::STATUS_PENDING] ?? 0),
            'pending_count' => (int) ($statusCounts[(string) Order::STATUS_PROCESSING] ?? $statusCounts[Order::STATUS_PROCESSING] ?? 0),
            'latest' => $latest,
        ];
    }

    /**
     * @return array{open_count: int, latest: array<int, array<string, mixed>>}
     */
    private function ticketsSummary(User $user): array
    {
        $openCount = Ticket::query()
            ->where('user_id', $user->id)
            ->where('status', Ticket::STATUS_OPENING)
            ->count();

        $latest = Ticket::query()
            ->where('user_id', $user->id)
            ->select(['id', 'level', 'reply_status', 'status', 'subject', 'created_at', 'updated_at'])
            ->orderByDesc('created_at')
            ->limit(self::LATEST_LIMIT)
            ->get()
            ->map(fn (Ticket $ticket): array => [
                'id' => (int) $ticket->id,
                'level' => $ticket->level,
                'reply_status' => (int) $ticket->reply_status,
                'status' => (int) $ticket->status,
                'subject' => $ticket->subject,
                'created_at' => $this->timestamp($ticket->created_at),
                'updated_at' => $this->timestamp($ticket->updated_at),
            ])
            ->values()
            ->all();

        return [
            'open_count' => (int) $openCount,
            'latest' => $latest,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function notices(): array
    {
        $ttl = (int) config('api_performance.app_dashboard.notices_cache_ttl', 60);

        if ($ttl <= 0) {
            return $this->noticesFromDatabase();
        }

        try {
            return Cache::remember('app_api:v1:dashboard:notices', $ttl, fn (): array => $this->noticesFromDatabase());
        } catch (Throwable) {
            return $this->noticesFromDatabase();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function noticesFromDatabase(): array
    {
        return Notice::query()
            ->where('show', true)
            ->select(['id', 'title', 'created_at', 'updated_at'])
            ->orderBy('sort')
            ->orderByDesc('id')
            ->limit(self::LATEST_LIMIT)
            ->get()
            ->map(fn (Notice $notice): array => [
                'id' => (int) $notice->id,
                'title' => $notice->title,
                'created_at' => $this->timestamp($notice->created_at),
                'updated_at' => $this->timestamp($notice->updated_at),
            ])
            ->values()
            ->all();
    }

    private function timestamp(mixed $value): int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }
}
