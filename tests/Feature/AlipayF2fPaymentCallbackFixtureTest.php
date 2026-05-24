<?php

declare(strict_types=1);

namespace Tests\Feature;

use Plugin\AlipayF2f\Plugin as AlipayF2fPlugin;
use Plugin\AlipayF2f\library\AlipayF2F;
use Tests\TestCase;

final class AlipayF2fPaymentCallbackFixtureTest extends TestCase
{
    private string $privateKeyPem;
    private string $privateKeyBody;
    private string $publicKeyBody;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(AlipayF2F::class)) {
            require_once base_path('plugins-core/AlipayF2f/library/AlipayF2F.php');
        }

        if (!class_exists(AlipayF2fPlugin::class)) {
            require_once base_path('plugins-core/AlipayF2f/Plugin.php');
        }

        $this->makeKeyPair();
    }

    public function test_alipay_f2f_callback_signature_success_extracts_order_and_callback_ids(): void
    {
        $plugin = $this->makePlugin();
        $payload = $this->signedCallbackPayload([
            'out_trade_no' => 'fixture-alipay-order',
            'trade_no' => 'fixture-alipay-callback',
        ]);

        $this->assertSame([
            'trade_no' => 'fixture-alipay-order',
            'callback_no' => 'fixture-alipay-callback',
        ], $plugin->notify($payload));
    }

    public function test_alipay_f2f_callback_signature_failure_returns_false_without_throwing(): void
    {
        $plugin = $this->makePlugin();
        $payload = $this->signedCallbackPayload([
            'out_trade_no' => 'fixture-alipay-order',
            'trade_no' => 'fixture-alipay-callback',
        ]);
        $payload['sign'] = base64_encode('invalid-signature');

        $this->assertFalse($plugin->notify($payload));
    }

    public function test_alipay_f2f_non_success_trade_status_returns_false_before_signature_check(): void
    {
        $plugin = $this->makePlugin();
        $payload = $this->signedCallbackPayload([
            'trade_status' => 'WAIT_BUYER_PAY',
            'out_trade_no' => 'fixture-alipay-order',
            'trade_no' => 'fixture-alipay-callback',
        ]);

        $this->assertFalse($plugin->notify($payload));
    }

    private function makePlugin(): AlipayF2fPlugin
    {
        $plugin = new AlipayF2fPlugin('AlipayF2f');
        $plugin->setConfig([
            'app_id' => 'fixture-alipay-app',
            'private_key' => $this->privateKeyBody,
            'public_key' => $this->publicKeyBody,
            'product_name' => 'Fixture Alipay order',
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
            'app_id' => 'fixture-alipay-app',
            'trade_status' => 'TRADE_SUCCESS',
            'out_trade_no' => 'fixture-alipay-order',
            'trade_no' => 'fixture-alipay-callback',
            'total_amount' => '11.25',
            'buyer_id' => 'fixture-buyer',
            'seller_id' => 'fixture-seller',
            'charset' => 'UTF-8',
            'sign_type' => 'RSA2',
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

        $signData = implode('&', array_map(
            static fn(string $key, mixed $value): string => $key . '=' . $value,
            array_keys($payload),
            $payload
        ));

        $signature = '';
        $this->assertTrue(openssl_sign($signData, $signature, $this->privateKeyPem, OPENSSL_ALGO_SHA256));

        return base64_encode($signature);
    }

    private function makeKeyPair(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKeyPem));

        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $this->assertIsString($details['key']);

        $this->privateKeyPem = $privateKeyPem;
        $this->privateKeyBody = $this->stripPemEnvelope($privateKeyPem);
        $this->publicKeyBody = $this->stripPemEnvelope($details['key']);
    }

    private function stripPemEnvelope(string $pem): string
    {
        return str_replace([
            "-----BEGIN PRIVATE KEY-----",
            "-----END PRIVATE KEY-----",
            "-----BEGIN RSA PRIVATE KEY-----",
            "-----END RSA PRIVATE KEY-----",
            "-----BEGIN PUBLIC KEY-----",
            "-----END PUBLIC KEY-----",
            "\r",
            "\n",
            ' ',
        ], '', $pem);
    }
}
