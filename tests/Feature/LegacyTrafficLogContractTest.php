<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\User\LegacyTrafficReadModel;
use Tests\TestCase;

final class LegacyTrafficLogContractTest extends TestCase
{
    public function test_traffic_log_uses_read_model_and_keeps_resource_columns(): void
    {
        $controllerSource = file_get_contents(app_path('Http/Controllers/V1/User/StatController.php'));
        $readModelSource = file_get_contents(app_path('Services/User/LegacyTrafficReadModel.php'));
        $resourceSource = file_get_contents(app_path('Http/Resources/TrafficLogResource.php'));

        $this->assertIsString($controllerSource);
        $this->assertIsString($readModelSource);
        $this->assertIsString($resourceSource);
        $this->assertStringContainsString('LegacyTrafficReadModel $readModel', $controllerSource);
        $this->assertStringContainsString('monthlyTrafficLogsForUser(', $controllerSource);
        $this->assertSame([
            'user_id',
            'u',
            'd',
            'record_at',
            'server_rate',
        ], LegacyTrafficReadModel::TRAFFIC_LOG_COLUMNS);
        $this->assertStringContainsString('select(self::TRAFFIC_LOG_COLUMNS)', $readModelSource);
        $this->assertStringContainsString("'record_at', '>=', \$startAt", $readModelSource);
        foreach (['"d"', '"u"', '"record_at"', '"server_rate"'] as $field) {
            $this->assertStringContainsString($field, $resourceSource);
        }
    }
}
