<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plan;
use App\Services\PlanService;
use Tests\TestCase;

final class PlanServiceCapacityTest extends TestCase
{
    public function test_has_capacity_uses_preloaded_active_user_count_without_database_fallback(): void
    {
        $service = new PlanService(new Plan());

        $plan = new Plan();
        $plan->capacity_limit = 2;
        $plan->setAttribute('active_users_count', 1);

        $this->assertTrue($service->hasCapacity($plan));
    }

    public function test_has_capacity_respects_preloaded_active_user_count_when_full(): void
    {
        $service = new PlanService(new Plan());

        $plan = new Plan();
        $plan->capacity_limit = 2;
        $plan->setAttribute('active_users_count', 2);

        $this->assertFalse($service->hasCapacity($plan));
    }
}
