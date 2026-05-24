<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\User\LegacyNoticeReadModel;
use Tests\TestCase;

final class LegacyNoticeFetchContractTest extends TestCase
{
    public function test_notice_fetch_uses_read_model_and_keeps_raw_legacy_shape(): void
    {
        $controllerSource = file_get_contents(app_path('Http/Controllers/V1/User/NoticeController.php'));
        $readModelSource = file_get_contents(app_path('Services/User/LegacyNoticeReadModel.php'));

        $this->assertIsString($controllerSource);
        $this->assertIsString($readModelSource);
        $this->assertStringContainsString('LegacyNoticeReadModel $readModel', $controllerSource);
        $this->assertStringContainsString('return response($readModel->fetch($request->input(\'current\')));', $controllerSource);
        $this->assertSame([
            'id',
            'sort',
            'title',
            'content',
            'show',
            'img_url',
            'tags',
            'created_at',
            'updated_at',
        ], LegacyNoticeReadModel::COLUMNS);
        $this->assertStringContainsString('private const PAGE_SIZE = 5;', $readModelSource);
        $this->assertStringContainsString('(clone $baseQuery)->count()', $readModelSource);
        $this->assertStringContainsString('select(self::COLUMNS)', $readModelSource);
        $this->assertStringContainsString('\'data\' => $notices', $readModelSource);
        $this->assertStringContainsString('\'total\' => (int) $total', $readModelSource);
    }
}
