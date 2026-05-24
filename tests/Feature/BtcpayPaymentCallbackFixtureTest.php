<?php

declare(strict_types=1);

namespace Plugin\Btcpay {
    function getallheaders(): array
    {
        return \Tests\Feature\BtcpayPaymentCallbackFixtureTest::fixtureHeaders();
    }

    function file_get_contents(string $filename, bool $use_include_path = false, $context = null): string
    {
        return \Tests\Feature\BtcpayPaymentCallbackFixtureTest::fixtureInvoiceResponse($filename, $context);
    }
}

namespace Tests\Feature {
    use App\Exceptions\ApiException;
    use Illuminate\Http\Request;
    use Plugin\Btcpay\Plugin as BtcpayPlugin;
    use Tests\TestCase;

    final class BtcpayPaymentCallbackFixtureTest extends TestCase
    {
        private const API_URL = 'https://btcpay.example.invalid/';
        private const STORE_ID = 'fixture-store';
        private const API_KEY = 'fixture-btcpay-api-key';
        private const WEBHOOK_KEY = 'fixture-btcpay-webhook-key';

        /**
         * @var array<string, string>
         */
        private static array $headers = [];
        private static string $expectedInvoiceUrl = '';
        private static string $lastInvoiceUrl = '';

        protected function setUp(): void
        {
            parent::setUp();

            self::$headers = [];
            self::$expectedInvoiceUrl = '';
            self::$lastInvoiceUrl = '';

            if (!class_exists(BtcpayPlugin::class)) {
                require_once base_path('plugins-core/Btcpay/Plugin.php');
            }
        }

        /**
         * @return array<string, string>
         */
        public static function fixtureHeaders(): array
        {
            return self::$headers;
        }

        public static function fixtureInvoiceResponse(string $filename, mixed $context = null): string
        {
            self::$lastInvoiceUrl = $filename;

            return json_encode([
                'metadata' => [
                    'orderId' => 'fixture-btcpay-order',
                ],
            ], JSON_THROW_ON_ERROR);
        }

        public function test_btcpay_raw_body_hmac_success_fetches_invoice_and_extracts_ids(): void
        {
            $plugin = $this->makePlugin();
            $payload = $this->bindSignedRawRequest([
                'invoiceId' => 'fixture-btcpay-invoice',
            ]);
            self::$expectedInvoiceUrl = self::API_URL . 'api/v1/stores/' . self::STORE_ID . '/invoices/fixture-btcpay-invoice';

            $this->assertSame([
                'trade_no' => 'fixture-btcpay-order',
                'callback_no' => 'fixture-btcpay-invoice',
            ], $plugin->notify(json_decode($payload, true)));
            $this->assertSame(self::$expectedInvoiceUrl, self::$lastInvoiceUrl);
        }

        public function test_btcpay_raw_body_hmac_failure_throws_existing_api_exception_before_invoice_fetch(): void
        {
            $plugin = $this->makePlugin();
            $payload = $this->bindSignedRawRequest([
                'invoiceId' => 'fixture-btcpay-invoice',
            ]);
            self::$headers = ['Btcpay-Sig' => 'sha256=invalid-signature'];

            $this->expectException(ApiException::class);
            $this->expectExceptionMessage('HMAC signature does not match');

            try {
                $plugin->notify(json_decode($payload, true));
            } finally {
                $this->assertSame('', self::$lastInvoiceUrl);
            }
        }

        public function test_btcpay_signature_uses_exact_trimmed_raw_body(): void
        {
            $plugin = $this->makePlugin();
            $payload = json_encode(['invoiceId' => 'fixture-btcpay-trimmed-invoice'], JSON_UNESCAPED_SLASHES);
            $this->assertIsString($payload);
            $this->bindRawRequest("\n" . $payload . "\n");
            self::$headers = [
                'Btcpay-Sig' => 'sha256=' . hash_hmac('sha256', $payload, self::WEBHOOK_KEY),
            ];

            $this->assertSame([
                'trade_no' => 'fixture-btcpay-order',
                'callback_no' => 'fixture-btcpay-trimmed-invoice',
            ], $plugin->notify(json_decode($payload, true)));
        }

        private function makePlugin(): BtcpayPlugin
        {
            $plugin = new BtcpayPlugin('Btcpay');
            $plugin->setConfig([
                'btcpay_url' => self::API_URL,
                'btcpay_storeId' => self::STORE_ID,
                'btcpay_api_key' => self::API_KEY,
                'btcpay_webhook_key' => self::WEBHOOK_KEY,
            ]);

            return $plugin;
        }

        /**
         * @param array<string, mixed> $payload
         */
        private function bindSignedRawRequest(array $payload): string
        {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $this->assertIsString($json);

            $this->bindRawRequest($json);
            self::$headers = [
                'Btcpay-Sig' => 'sha256=' . hash_hmac('sha256', $json, self::WEBHOOK_KEY),
            ];

            return $json;
        }

        private function bindRawRequest(string $rawBody): void
        {
            app()->instance('request', Request::create(
                '/api/v1/guest/payment/notify/BTCPay/fixtureuuid',
                'POST',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                $rawBody
            ));
        }
    }
}
