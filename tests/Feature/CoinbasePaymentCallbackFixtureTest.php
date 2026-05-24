<?php

declare(strict_types=1);

namespace Plugin\Coinbase {
    function getallheaders(): array
    {
        return \Tests\Feature\CoinbasePaymentCallbackFixtureTest::fixtureHeaders();
    }
}

namespace Tests\Feature {
    use App\Exceptions\ApiException;
    use Illuminate\Http\Request;
    use Plugin\Coinbase\Plugin as CoinbasePlugin;
    use Tests\TestCase;

    final class CoinbasePaymentCallbackFixtureTest extends TestCase
    {
        private const API_KEY = 'fixture-coinbase-api-key';
        private const WEBHOOK_KEY = 'fixture-coinbase-webhook-key';

        /**
         * @var array<string, string>
         */
        private static array $headers = [];

        protected function setUp(): void
        {
            parent::setUp();

            self::$headers = [];

            if (!class_exists(CoinbasePlugin::class)) {
                require_once base_path('plugins-core/Coinbase/Plugin.php');
            }
        }

        /**
         * @return array<string, string>
         */
        public static function fixtureHeaders(): array
        {
            return self::$headers;
        }

        public function test_coinbase_raw_body_hmac_success_extracts_order_and_callback_ids(): void
        {
            $plugin = $this->makePlugin();
            $payload = $this->bindSignedRawRequest([
                'event' => [
                    'id' => 'fixture-coinbase-event',
                    'data' => [
                        'metadata' => [
                            'outTradeNo' => 'fixture-coinbase-order',
                        ],
                    ],
                ],
            ]);

            $this->assertSame([
                'trade_no' => 'fixture-coinbase-order',
                'callback_no' => 'fixture-coinbase-event',
            ], $plugin->notify(json_decode($payload, true)));
        }

        public function test_coinbase_raw_body_hmac_failure_throws_existing_api_exception(): void
        {
            $plugin = $this->makePlugin();
            $payload = $this->bindSignedRawRequest([
                'event' => [
                    'id' => 'fixture-coinbase-event',
                    'data' => [
                        'metadata' => [
                            'outTradeNo' => 'fixture-coinbase-order',
                        ],
                    ],
                ],
            ]);
            self::$headers = ['X-Cc-Webhook-Signature' => 'invalid-signature'];

            $this->expectException(ApiException::class);
            $this->expectExceptionMessage('HMAC signature does not match');

            $plugin->notify(json_decode($payload, true));
        }

        public function test_coinbase_signature_uses_exact_trimmed_raw_body(): void
        {
            $plugin = $this->makePlugin();
            $payload = json_encode([
                'event' => [
                    'id' => 'fixture-coinbase-trimmed-event',
                    'data' => [
                        'metadata' => [
                            'outTradeNo' => 'fixture-coinbase-trimmed-order',
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_SLASHES);

            $this->assertIsString($payload);
            $rawBodyWithWhitespace = "\n" . $payload . "\n";
            $this->bindRawRequest($rawBodyWithWhitespace);
            self::$headers = [
                'X-Cc-Webhook-Signature' => hash_hmac('sha256', $payload, self::WEBHOOK_KEY),
            ];

            $this->assertSame([
                'trade_no' => 'fixture-coinbase-trimmed-order',
                'callback_no' => 'fixture-coinbase-trimmed-event',
            ], $plugin->notify(json_decode($payload, true)));
        }

        private function makePlugin(): CoinbasePlugin
        {
            $plugin = new CoinbasePlugin('Coinbase');
            $plugin->setConfig([
                'coinbase_url' => 'https://coinbase.example.invalid',
                'coinbase_api_key' => self::API_KEY,
                'coinbase_webhook_key' => self::WEBHOOK_KEY,
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
                'X-Cc-Webhook-Signature' => hash_hmac('sha256', $json, self::WEBHOOK_KEY),
            ];

            return $json;
        }

        private function bindRawRequest(string $rawBody): void
        {
            app()->instance('request', Request::create(
                '/api/v1/guest/payment/notify/Coinbase/fixtureuuid',
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
