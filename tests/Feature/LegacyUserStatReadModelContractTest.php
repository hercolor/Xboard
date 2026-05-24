<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Ticket;
use Tests\TestCase;

final class LegacyUserStatReadModelContractTest extends TestCase
{
    public function test_legacy_user_stat_controller_keeps_positional_read_model_contract(): void
    {
        $controllerSource = file_get_contents(app_path('Http/Controllers/V1/User/UserController.php'));
        $readModelSource = file_get_contents(app_path('Services/User/LegacyUserStatReadModel.php'));

        $this->assertIsString($controllerSource);
        $this->assertIsString($readModelSource);
        $this->assertStringContainsString('LegacyUserStatReadModel $readModel', $controllerSource);
        $this->assertStringContainsString('selectSub($this->pendingOrdersQuery($userId), \'pending_order_count\')', $readModelSource);
        $this->assertStringContainsString('selectSub($this->openTicketsQuery($userId), \'open_ticket_count\')', $readModelSource);
        $this->assertStringContainsString('selectSub($this->invitedUsersQuery($userId), \'invited_user_count\')', $readModelSource);
        $this->assertStringContainsString('array{0: int, 1: int, 2: int}', $readModelSource);
        $this->assertSame(0, Order::STATUS_PENDING);
        $this->assertSame(0, Ticket::STATUS_OPENING);
    }
}
