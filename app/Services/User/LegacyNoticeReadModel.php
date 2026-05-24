<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\Notice;

final class LegacyNoticeReadModel
{
    public const COLUMNS = [
        'id',
        'sort',
        'title',
        'content',
        'show',
        'img_url',
        'tags',
        'created_at',
        'updated_at',
    ];

    private const PAGE_SIZE = 5;

    /**
     * Preserve legacy `/api/v1/user/notice/fetch` raw response data.
     *
     * @return array{data: mixed, total: int}
     */
    public function fetch(int|string|null $current): array
    {
        $current = $current ?: 1;

        $baseQuery = Notice::query()
            ->where('show', true);

        $total = (clone $baseQuery)->count();
        $notices = $baseQuery
            ->select(self::COLUMNS)
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'DESC')
            ->forPage($current, self::PAGE_SIZE)
            ->get();

        return [
            'data' => $notices,
            'total' => (int) $total,
        ];
    }
}
