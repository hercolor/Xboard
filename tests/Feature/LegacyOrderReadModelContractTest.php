<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\User\LegacyOrderReadModel;
use Tests\TestCase;

final class LegacyOrderReadModelContractTest extends TestCase
{
    public function test_order_reads_use_legacy_read_model_with_resource_column_allowlists(): void
    {
        $controllerSource = file_get_contents(app_path('Http/Controllers/V1/User/OrderController.php'));
        $readModelSource = file_get_contents(app_path('Services/User/LegacyOrderReadModel.php'));
        $resourceSource = file_get_contents(app_path('Http/Resources/OrderResource.php'));

        $this->assertIsString($controllerSource);
        $this->assertIsString($readModelSource);
        $this->assertIsString($resourceSource);
        $this->assertStringContainsString('LegacyOrderReadModel $readModel', $controllerSource);
        $this->assertStringContainsString('fetchForUser(', $controllerSource);
        $this->assertStringContainsString('detailForUser(', $controllerSource);
        $this->assertContains('prices', LegacyOrderReadModel::PLAN_COLUMNS);
        $this->assertContains('reset_traffic_method', LegacyOrderReadModel::PLAN_COLUMNS);
        $this->assertSame(['id', 'name', 'payment', 'icon'], LegacyOrderReadModel::PAYMENT_COLUMNS);
        $this->assertStringContainsString('PlanResource::make($this->plan)', $resourceSource);
        $this->assertStringContainsString("'payment' => \$this->whenLoaded('payment'", $resourceSource);
    }
}
