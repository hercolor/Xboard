<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\Payment\SyntheticPaymentPlugin;
use Tests\TestCase;

final class SyntheticPaymentPluginContractTest extends TestCase
{
    public function test_synthetic_payment_plugin_returns_stable_checkout_payload(): void
    {
        $plugin = new SyntheticPaymentPlugin();

        $payload = $plugin->pay([
            'trade_no' => 'fixture-order',
            'total_amount' => 1125,
            'notify_url' => 'https://xboard.example.invalid/api/v1/guest/payment/notify/FixturePay/fixtureuuid',
            'return_url' => 'https://client.example.invalid/#/order/fixture-order',
            'stripe_token' => 'fixture-token',
        ]);

        $this->assertSame(1, $payload['type']);
        $this->assertStringStartsWith('https://fixture-pay.invalid/checkout?', $payload['data']);
        $this->assertStringContainsString('trade_no=fixture-order', $payload['data']);
        $this->assertStringContainsString('amount=1125', $payload['data']);
        $this->assertStringContainsString('stripe_token=fixture-token', $payload['data']);
    }

    public function test_synthetic_payment_plugin_verifies_callback_payload(): void
    {
        $plugin = new SyntheticPaymentPlugin();

        $this->assertFalse($plugin->notify([
            'fixture_signature' => 'wrong-signature',
            'trade_no' => 'fixture-order',
        ]));

        $this->assertSame([
            'trade_no' => 'fixture-order',
            'callback_no' => 'fixture-callback',
        ], $plugin->notify([
            'fixture_signature' => SyntheticPaymentPlugin::VALID_SIGNATURE,
            'trade_no' => 'fixture-order',
            'callback_no' => 'fixture-callback',
        ]));
    }
}
