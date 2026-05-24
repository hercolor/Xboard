<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugin\Mgate\Plugin as MgatePlugin;
use Tests\TestCase;

final class MgatePaymentCallbackFixtureTest extends TestCase
{
    private const APP_ID = 'fixture-mgate-app';
    private const APP_SECRET = 'fixture-mgate-secret';

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(MgatePlugin::class)) {
            require_once base_path('plugins-core/Mgate/Plugin.php');
        }
    }

    public function test_mgate_callback_signature_success_extracts_order_and_callback_ids(): void
    {
        $plugin = $this->makePlugin();
        $payload = $this->signedCallbackPayload([
            'out_trade_no' => 'fixture-mgate-order',
            'trade_no' => 'fixture-mgate-callback',
        ]);

        $this->assertSame([
            'trade_no' => 'fixture-mgate-order',
            'callback_no' => 'fixture-mgate-callback',
        ], $plugin->notify($payload));
    }

    public function test_mgate_callback_signature_failure_returns_false_without_throwing(): void
    {
        $plugin = $this->makePlugin();
        $payload = $this->signedCallbackPayload([
            'out_trade_no' => 'fixture-mgate-order',
            'trade_no' => 'fixture-mgate-callback',
        ]);
        $payload['sign'] = 'invalid-signature';

        $this->assertFalse($plugin->notify($payload));
    }

    public function test_mgate_callback_signature_is_independent_of_payload_order(): void
    {
        $plugin = $this->makePlugin();
        $payload = $this->signedCallbackPayload([
            'out_trade_no' => 'fixture-mgate-ordered',
            'trade_no' => 'fixture-mgate-ordered-callback',
            'source_currency' => 'CNY',
            'total_amount' => '1125',
        ]);

        $reorderedPayload = [
            'trade_no' => $payload['trade_no'],
            'sign' => $payload['sign'],
            'total_amount' => $payload['total_amount'],
            'out_trade_no' => $payload['out_trade_no'],
            'source_currency' => $payload['source_currency'],
            'app_id' => $payload['app_id'],
        ];

        $this->assertSame([
            'trade_no' => 'fixture-mgate-ordered',
            'callback_no' => 'fixture-mgate-ordered-callback',
        ], $plugin->notify($reorderedPayload));
    }

    private function makePlugin(): MgatePlugin
    {
        $plugin = new MgatePlugin('Mgate');
        $plugin->setConfig([
            'mgate_url' => 'https://mgate.example.invalid',
            'mgate_app_id' => self::APP_ID,
            'mgate_app_secret' => self::APP_SECRET,
            'mgate_source_currency' => 'CNY',
        ]);

        return $plugin;
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function signedCallbackPayload(array $overrides = []): array
    {
        $payload = array_replace([
            'app_id' => self::APP_ID,
            'out_trade_no' => 'fixture-mgate-order',
            'trade_no' => 'fixture-mgate-callback',
            'total_amount' => '1125',
            'source_currency' => 'CNY',
        ], $overrides);

        $payload['sign'] = $this->signPayloadWithoutSignature($payload);

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signPayloadWithoutSignature(array $payload): string
    {
        unset($payload['sign']);
        ksort($payload);

        return md5(http_build_query($payload) . self::APP_SECRET);
    }
}
