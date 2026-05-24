<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\User\LegacyInviteReadModel;
use Tests\TestCase;

final class LegacyInviteFetchContractTest extends TestCase
{
    public function test_invite_fetch_uses_read_model_and_keeps_legacy_shape_fragments(): void
    {
        $controllerSource = file_get_contents(app_path('Http/Controllers/V1/User/InviteController.php'));
        $readModelSource = file_get_contents(app_path('Services/User/LegacyInviteReadModel.php'));

        $this->assertIsString($controllerSource);
        $this->assertIsString($readModelSource);
        $this->assertStringContainsString('LegacyInviteReadModel $readModel', $controllerSource);
        $this->assertStringContainsString('InviteCodeResource::collection($data[\'codes\'])', $controllerSource);
        $this->assertStringContainsString("'stat' => \$data['stat']", $controllerSource);
        $this->assertSame([
            'user_id',
            'code',
            'pv',
            'status',
            'created_at',
            'updated_at',
        ], LegacyInviteReadModel::CODE_COLUMNS);
        $this->assertSame([
            'id',
            'order_amount',
            'trade_no',
            'get_amount',
            'created_at',
        ], LegacyInviteReadModel::DETAIL_COLUMNS);
        $this->assertStringContainsString('detailsForUser(', $controllerSource);
        $this->assertStringContainsString('ComissionLogResource::collection($details[\'data\'])', $controllerSource);
        $this->assertStringContainsString('select(self::DETAIL_COLUMNS)', $readModelSource);
        $this->assertStringContainsString('selectSub($this->invitedUsersQuery($userId), \'invited_user_count\')', $readModelSource);
        $this->assertStringContainsString('selectSub($this->checkedCommissionQuery($userId), \'checked_commission_balance\')', $readModelSource);
        $this->assertStringContainsString('selectSub($this->uncheckCommissionQuery($userId), \'uncheck_commission_balance\')', $readModelSource);
        $this->assertStringContainsString('array{codes: mixed, stat: array{0: int, 1: int, 2: int|float, 3: int, 4: int}}', $readModelSource);
    }
}
