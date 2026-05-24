<?php

declare(strict_types=1);

namespace Plugin\CoinPayments {
    function getallheaders(): array
    {
        return \Tests\Feature\CoinPaymentsPaymentCallbackFixtureTest::fixtureHeaders();
    }
}

namespace Tests\Feature {
    use App\Exceptions\ApiException;
    use Plugin\CoinPayments\Plugin as CoinPaymentsPlugin;
    use Tests\TestCase;

    final class CoinPaymentsPaymentCallbackFixtureTest extends TestCase
    {
        private const MERCHANT_ID = 'fixture-coinpayments-merchant';
        private const IPN_SECRET = 'fixture-coinpayments-secret';
        private const CURRENCY = 'CNY';

        /**
         * @var array<string, string>
         */
        private static array $headers = [];

        protected function setUp(): void
        {
            parent::setUp();

            self::$headers = [];

            if (!class_exists(CoinPaymentsPlugin::class)) {
                require_once base_path('plugins-core/CoinPayments/Plugin.php');
            }
        }

        /**
         * @return array<string, string>
         */
        public static function fixtureHeaders(): array
        {
            return self::$headers;
        }

        public function test_coinpayments_completed_callback_returns_order_callback_and_custom_result(): void
        {
            $plugin = $this->makePlugin();
            $payload = $this->signedCallbackPayload([
                'item_number' => 'fixture-coinpayments-order',
                'txn_id' => 'fixture-coinpayments-callback',
                'status' => '100',
            ]);

            $this->assertSame([
                'trade_no' => 'fixture-coinpayments-order',
                'callback_no' => 'fixture-coinpayments-callback',
                'custom_result' => 'IPN OK',
            ], $plugin->notify($payload));
        }

        public function test_coinpayments_pending_callback_preserves_provider_pending_body(): void
        {
            $plugin = $this->makePlugin();
            $payload = $this->signedCallbackPayload([
                'item_number' => 'fixture-coinpayments-pending-order',
                'txn_id' => 'fixture-coinpayments-pending-callback',
                'status' => '1',
            ]);

            $this->assertSame('IPN OK: pending', $plugin->notify($payload));
        }

        public function test_coinpayments_hmac_failure_throws_existing_api_exception(): void
        {
            $plugin = $this->makePlugin();
            $payload = $this->signedCallbackPayload([
                'item_number' => 'fixture-coinpayments-order',
                'txn_id' => 'fixture-coinpayments-callback',
                'status' => '100',
            ]);
            self::$headers = ['Hmac' => 'invalid-hmac'];

            $this->expectException(ApiException::class);
            $this->expectExceptionMessage('HMAC signature does not match');

            $plugin->notify($payload);
        }

        public function test_coinpayments_checkout_url_is_deterministic_without_network(): void
        {
            $plugin = $this->makePlugin();

            $result = $plugin->pay([
                'trade_no' => 'fixture-coinpayments-order',
                'total_amount' => 1125,
                'notify_url' => 'https://xboard.example.invalid/api/v1/guest/payment/notify/CoinPayments/fixtureuuid',
                'return_url' => 'https://client.example.invalid/#/order/fixture-coinpayments-order',
            ]);

            $this->assertSame(1, $result['type']);

            $query = parse_url($result['data'], PHP_URL_QUERY);
            $this->assertIsString($query);
            parse_str($query, $params);

            $this->assertStringStartsWith('https://www.coinpayments.net/index.php?', $result['data']);
            $this->assertSame('_pay_simple', $params['cmd']);
            $this->assertSame('1', (string) $params['reset']);
            $this->assertSame(self::MERCHANT_ID, $params['merchant']);
            $this->assertSame('fixture-coinpayments-order', $params['item_number']);
            $this->assertSame(self::CURRENCY, $params['currency']);
            $this->assertSame('11.25', (string) $params['amountf']);
            $this->assertSame('https://client.example.invalid', $params['success_url']);
            $this->assertSame('https://client.example.invalid/#/order/fixture-coinpayments-order', $params['cancel_url']);
        }

        private function makePlugin(): CoinPaymentsPlugin
        {
            $plugin = new CoinPaymentsPlugin('CoinPayments');
            $plugin->setConfig([
                'coinpayments_merchant_id' => self::MERCHANT_ID,
                'coinpayments_ipn_secret' => self::IPN_SECRET,
                'coinpayments_currency' => self::CURRENCY,
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
                'merchant' => self::MERCHANT_ID,
                'item_number' => 'fixture-coinpayments-order',
                'item_name' => 'Fixture CoinPayments order',
                'txn_id' => 'fixture-coinpayments-callback',
                'status' => '100',
                'amount1' => '11.25',
                'currency1' => self::CURRENCY,
            ], $overrides);

            self::$headers = ['Hmac' => $this->signPayload($payload)];

            return $payload;
        }

        /**
         * @param array<string, mixed> $payload
         */
        private function signPayload(array $payload): string
        {
            ksort($payload);

            return hash_hmac('sha512', stripslashes(http_build_query($payload)), self::IPN_SECRET);
        }
    }
}
