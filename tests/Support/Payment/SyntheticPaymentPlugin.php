<?php

declare(strict_types=1);

namespace Tests\Support\Payment;

use App\Contracts\PaymentInterface;
use App\Services\Plugin\AbstractPlugin;

final class SyntheticPaymentPlugin extends AbstractPlugin implements PaymentInterface
{
    public const PLUGIN_CODE = 'fixture-payment-plugin';
    public const METHOD = 'FixturePay';
    public const VALID_SIGNATURE = 'valid-fixture-signature';

    public function __construct()
    {
        parent::__construct(self::PLUGIN_CODE);
    }

    public function form(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return array{type: int, data: string}
     */
    public function pay($order): array
    {
        return [
            'type' => 1,
            'data' => 'https://fixture-pay.invalid/checkout?' . http_build_query([
                'trade_no' => $order['trade_no'],
                'amount' => $order['total_amount'],
                'notify_url' => $order['notify_url'],
                'return_url' => $order['return_url'],
                'stripe_token' => $order['stripe_token'] ?? null,
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{trade_no: string, callback_no: string}|false
     */
    public function notify($params): array|bool
    {
        if (($params['fixture_signature'] ?? null) !== self::VALID_SIGNATURE) {
            return false;
        }

        return [
            'trade_no' => (string) $params['trade_no'],
            'callback_no' => (string) ($params['callback_no'] ?? 'fixture-callback'),
        ];
    }
}
