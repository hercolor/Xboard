<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\Plugin\HookManager;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        HookManager::call('payment.notify.before', [$method, $uuid, $request]);
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) {
                $this->logNotifyFailure($method, $uuid, $request, 'verify_false');
                HookManager::call('payment.notify.failed', [$method, $uuid, $request]);
                return $this->fail([422, 'verify error']);
            }
            HookManager::call('payment.notify.verified', $verify);
            if (!$this->handle($verify['trade_no'], $verify['callback_no'])) {
                return $this->fail([400, 'handle error']);
            }
            return (isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            $this->logNotifyFailure($method, $uuid, $request, 'exception', $e);
            Log::error($e);
            return $this->fail([500, 'fail']);
        }
    }

    private function logNotifyFailure(string $method, string $uuid, Request $request, string $reason, ?\Throwable $exception = null): void
    {
        $context = [
            'method' => $method,
            'uuid_hash' => hash('sha256', $uuid),
            'reason' => $reason,
            'ip' => $request->ip(),
            'request_id' => $request->header('X-Request-Id'),
            'payload_keys' => array_keys($request->input()),
        ];

        if ($exception) {
            $context['exception_class'] = get_class($exception);
            $context['exception_code'] = $exception->getCode();
        }

        Log::warning('payment_notify_verification_failed', $context);
    }

    private function handle($tradeNo, $callbackNo)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            return $this->fail([400202, 'order is not found']);
        }
        if ($order->status !== Order::STATUS_PENDING)
            return true;
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }

        HookManager::call('payment.notify.success', $order);
        return true;
    }
}
