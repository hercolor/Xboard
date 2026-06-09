<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\App\AppSessionReadModel;
use App\Services\User\LegacyOrderReadModel;
use App\Services\User\LegacyTrafficReadModel;
use App\Services\User\MembershipStatusService;
use Tests\Support\AppApi\AppBffFixtures;
use Tests\TestCase;

final class ApiSensitiveFieldLeakageContractTest extends TestCase
{
    public function test_app_session_payload_excludes_legacy_delivery_secrets(): void
    {
        $payload = (new AppSessionReadModel(new MembershipStatusService()))->forUser(AppBffFixtures::user());
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        foreach (AppBffFixtures::sensitiveNeedles() as $needle) {
            $this->assertStringNotContainsString($needle, $encoded);
        }

        $this->assertArrayNotHasKey('token', $payload['user']);
        $this->assertArrayNotHasKey('uuid', $payload['user']);
        $this->assertArrayNotHasKey('subscribe_url', $payload['subscription']);
        $this->assertArrayNotHasKey('auth_data', $payload['subscription']);
        $this->assertSame(['user', 'subscription', 'traffic', 'preferences'], array_keys($payload));
    }

    public function test_payment_method_user_allowlist_excludes_provider_secrets(): void
    {
        $this->assertSame([
            'id',
            'name',
            'payment',
            'icon',
            'handling_fee_fixed',
            'handling_fee_percent',
        ], LegacyOrderReadModel::PAYMENT_METHOD_COLUMNS);

        foreach (['config', 'uuid', 'notify_domain', 'enable', 'created_at', 'updated_at'] as $forbidden) {
            $this->assertNotContains($forbidden, LegacyOrderReadModel::PAYMENT_METHOD_COLUMNS);
            $this->assertNotContains($forbidden, LegacyOrderReadModel::PAYMENT_COLUMNS);
        }
    }

    public function test_dashboard_read_model_uses_summary_allowlists_not_detail_secrets(): void
    {
        $source = file_get_contents(app_path('Services/App/AppDashboardReadModel.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("select(['trade_no', 'status', 'period', 'total_amount', 'created_at'])", $source);
        $this->assertStringContainsString("select(['id', 'level', 'reply_status', 'status', 'subject', 'created_at', 'updated_at'])", $source);
        $this->assertStringContainsString("select(['id', 'title', 'created_at', 'updated_at'])", $source);

        foreach (['payment_id', 'coupon_id', 'callback_no', 'surplus_order_ids', 'message', 'content', 'body', 'subscribe_url', 'auth_data'] as $forbidden) {
            $this->assertStringNotContainsString("'{$forbidden}' =>", $source);
            $this->assertStringNotContainsString('"' . $forbidden . '" =>', $source);
        }
    }

    public function test_traffic_log_allowlist_excludes_storage_only_columns(): void
    {
        $this->assertSame([
            'user_id',
            'u',
            'd',
            'record_at',
            'server_rate',
        ], LegacyTrafficReadModel::TRAFFIC_LOG_COLUMNS);

        foreach (['id', 'record_type', 'created_at', 'updated_at'] as $forbidden) {
            $this->assertNotContains($forbidden, LegacyTrafficReadModel::TRAFFIC_LOG_COLUMNS);
        }
    }
}
