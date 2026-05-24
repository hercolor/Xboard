<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugin\Epay\Plugin as EpayPlugin;
use Tests\TestCase;

final class EpayPaymentCallbackFixtureTest extends TestCase
{
    private const MERCHANT_ID = 'fixture-merchant';
    private const SIGNING_KEY = 'fixture-signing-key';

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(EpayPlugin::class)) {
            require_once base_path('plugins-core/Epay/Plugin.php');
        }
    }

    public function test_epay_callback_signature_success_extracts_order_and_callback_ids(): void
    {
        $plugin = $this->makePlugin();
        $payload = $this->signedCallbackPayload([
            'out_trade_no' => 'fixture-epay-order',
            'trade_no' => 'fixture-epay-callback',
        ]);

        $this->assertSame([
            'trade_no' => 'fixture-epay-order',
            'callback_no' => 'fixture-epay-callback',
        ], $plugin->notify($payload));
    }

    public function test_epay_callback_signature_failure_returns_false_without_throwing(): void
    {
        $plugin = $this->makePlugin();
        $payload = $this->signedCallbackPayload([
            'out_trade_no' => 'fixture-epay-order',
            'trade_no' => 'fixture-epay-callback',
        ]);
        $payload['sign'] = 'invalid-signature';

        $this->assertFalse($plugin->notify($payload));
    }

    public function test_epay_checkout_url_is_deterministic_and_signed_without_network(): void
    {
        $plugin = $this->makePlugin();

        $result = $plugin->pay([
            'trade_no' => 'fixture-epay-order',
            'total_amount' => 1125,
            'notify_url' => 'https://xboard.example.invalid/api/v1/guest/payment/notify/EPay/fixtureuuid',
            'return_url' => 'https://client.example.invalid/#/order/fixture-epay-order',
        ]);

        $this->assertSame(1, $result['type']);

        $query = parse_url($result['data'], PHP_URL_QUERY);
        $this->assertIsString($query);
        parse_str($query, $params);

        $this->assertStringStartsWith('https://epay.example.invalid/submit.php?', $result['data']);
        $this->assertSame(self::MERCHANT_ID, $params['pid']);
        $this->assertSame('alipay', $params['type']);
        $this->assertSame('fixture-epay-order', $params['out_trade_no']);
        $this->assertSame('11.25', (string) $params['money']);
        $this->assertSame('MD5', $params['sign_type']);
        $this->assertSame($this->signPayloadWithoutSignature($params), $params['sign']);
    }

    private function makePlugin(): EpayPlugin
    {
        $plugin = new EpayPlugin('Epay');
        $plugin->setConfig([
            'url' => 'https://epay.example.invalid',
            'pid' => self::MERCHANT_ID,
            'key' => self::SIGNING_KEY,
            'type' => 'alipay',
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
            'pid' => self::MERCHANT_ID,
            'type' => 'alipay',
            'out_trade_no' => 'fixture-epay-order',
            'trade_no' => 'fixture-epay-callback',
            'trade_status' => 'TRADE_SUCCESS',
            'money' => '11.25',
            'name' => 'Fixture EPay order',
            'sign_type' => 'MD5',
        ], $overrides);

        $payload['sign'] = $this->signPayloadWithoutSignature($payload);

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signPayloadWithoutSignature(array $payload): string
    {
        unset($payload['sign'], $payload['sign_type']);
        ksort($payload);

        return md5(stripslashes(urldecode(http_build_query($payload))) . self::SIGNING_KEY);
    }
}
