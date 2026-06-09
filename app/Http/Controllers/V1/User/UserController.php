<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserBindPhone;
use App\Http\Requests\User\UserChangePassword;
use App\Http\Requests\User\UserSendPhoneVerify;
use App\Http\Requests\User\UserTransfer;
use App\Http\Requests\User\UserUpdate;
use App\Models\Plan;
use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\AuthService;
use App\Services\Plugin\HookManager;
use App\Services\Sms\SmsBaoService;
use App\Services\UserService;
use App\Services\User\LegacyUserInfoReadModel;
use App\Services\User\LegacyUserStatReadModel;
use App\Services\User\MembershipStatusService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    protected $loginService;

    public function __construct(
        LoginService $loginService
    ) {
        $this->loginService = $loginService;
    }

    public function getActiveSession(Request $request)
    {
        $user = $request->user();
        $authService = new AuthService($user);
        return $this->success($authService->getSessions());
    }

    public function removeActiveSession(Request $request)
    {
        $user = $request->user();
        $authService = new AuthService($user);
        return $this->success($authService->removeSession($request->input('session_id')));
    }

    public function checkLogin(Request $request)
    {
        $data = [
            'is_login' => $request->user()?->id ? true : false
        ];
        if ($request->user()?->is_admin) {
            $data['is_admin'] = true;
        }
        return $this->success($data);
    }

    public function changePassword(UserChangePassword $request)
    {
        $user = $request->user();
        if (
            !Helper::multiPasswordVerify(
                $user->password_algo,
                $user->password_salt,
                $request->input('old_password'),
                $user->password
            )
        ) {
            return $this->fail([400, __('The old password is wrong')]);
        }
        $user->password = password_hash($request->input('new_password'), PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;
        if (!$user->save()) {
            return $this->fail([400, __('Save failed')]);
        }
        
        $currentToken = $user->currentAccessToken();
        $user->tokens()
            ->when($currentToken, fn($query) => $query->where('id', '!=', $currentToken->id))
            ->delete();
        
        return $this->success(true);
    }

    public function info(Request $request, LegacyUserInfoReadModel $readModel)
    {
        $user = $readModel->forUserId((int) $request->user()->id);
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        return $this->success($user);
    }

    public function getStat(Request $request, LegacyUserStatReadModel $readModel)
    {
        return $this->success($readModel->forUserId((int) $request->user()->id));
    }

    public function getSubscribe(Request $request, MembershipStatusService $membershipStatusService)
    {
        $user = User::where('id', $request->user()->id)
            ->select([
                'id',
                'plan_id',
                'token',
                'expired_at',
                'u',
                'd',
                'transfer_enable',
                'email',
                'uuid',
                'device_limit',
                'speed_limit',
                'next_reset_at',
                'banned'
            ])
            ->first();
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        $userService = new UserService();
        if ($user->plan_id) {
            $plan = Plan::find($user->plan_id);
            if ($plan) {
                $user->setRelation('plan', $plan);
            }
            $user['plan'] = $plan;
        }
        $membership = $membershipStatusService->build($user);
        $user['subscribe_url'] = $membership['can_connect']
            ? Helper::getSubscribeUrl($user['token'])
            : '';
        $user['reset_day'] = $userService->getResetDay($user);
        foreach ($membership as $key => $value) {
            $user[$key] = $value;
        }
        $user['delivery_available'] = (bool) $membership['can_connect'];
        $user = HookManager::filter('user.subscribe.response', $user);
        return $this->success($user);
    }

    public function resetSecurity(Request $request)
    {
        $user = $request->user();
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if (!$user->save()) {
            return $this->fail([400, __('Reset failed')]);
        }
        return $this->success(Helper::getSubscribeUrl($user->token));
    }

    public function update(UserUpdate $request)
    {
        $updateData = $request->only([
            'remind_expire',
            'remind_traffic'
        ]);

        $user = $request->user();
        try {
            $user->update($updateData);
        } catch (\Exception $e) {
            return $this->fail([400, __('Save failed')]);
        }

        return $this->success(true);
    }

    public function sendPhoneVerify(UserSendPhoneVerify $request, SmsBaoService $smsBaoService)
    {
        $user = $request->user();
        $phone = User::normalizePhone($request->input('phone'));
        if (!$phone || !$this->isValidNormalizedPhone($phone)) {
            return $this->fail([400, __('Phone format is incorrect')]);
        }

        if (User::byPhone($phone)->where('id', '!=', $user->id)->exists()) {
            return $this->fail([400202, __('Phone already exists')]);
        }

        $cacheSubject = $this->phoneVerifySubject('bind', (int) $user->id, $phone);
        if (Cache::get(CacheKey::get('LAST_SEND_PHONE_VERIFY_TIMESTAMP', $cacheSubject))) {
            return $this->fail([400, __('Phone verification code has been sent, please request again later')]);
        }

        $code = (string) random_int(100000, 999999);
        [$success, $message] = $smsBaoService->sendVerificationCode($phone, $code);
        if (!$success) {
            return $this->fail([400, $message ?: __('SMS send failed')]);
        }

        Cache::put(CacheKey::get('PHONE_VERIFY_CODE', $cacheSubject), $code, 300);
        Cache::put(CacheKey::get('LAST_SEND_PHONE_VERIFY_TIMESTAMP', $cacheSubject), time(), 60);
        return $this->success(true);
    }

    public function bindPhone(UserBindPhone $request)
    {
        $user = $request->user();
        $phone = User::normalizePhone($request->input('phone'));
        if (!$phone || !$this->isValidNormalizedPhone($phone)) {
            return $this->fail([400, __('Phone format is incorrect')]);
        }

        if (User::byPhone($phone)->where('id', '!=', $user->id)->exists()) {
            return $this->fail([400202, __('Phone already exists')]);
        }

        $code = (string) ($request->input('phone_code') ?: $request->input('code'));
        $cacheSubject = $this->phoneVerifySubject('bind', (int) $user->id, $phone);
        if ((string) Cache::get(CacheKey::get('PHONE_VERIFY_CODE', $cacheSubject)) !== $code) {
            return $this->fail([400, __('Incorrect phone verification code')]);
        }

        $user->phone = $phone;
        if (!$user->save()) {
            return $this->fail([400, __('Save failed')]);
        }

        Cache::forget(CacheKey::get('PHONE_VERIFY_CODE', $cacheSubject));
        return $this->success(['phone' => $user->phone]);
    }

    public function transfer(UserTransfer $request)
    {
        $amount = $request->input('transfer_amount');
        try {
            DB::transaction(function () use ($request, $amount) {
                $user = User::lockForUpdate()->find($request->user()->id);
                if (!$user) {
                    throw new \Exception(__('The user does not exist'));
                }
                if ($amount > $user->commission_balance) {
                    throw new \Exception(__('Insufficient commission balance'));
                }
                $user->commission_balance -= $amount;
                $user->balance += $amount;
                if (!$user->save()) {
                    throw new \Exception(__('Transfer failed'));
                }
            });
        } catch (\Exception $e) {
            return $this->fail([400, $e->getMessage()]);
        }
        return $this->success(true);
    }

    private function phoneVerifySubject(string $scene, int $userId, string $phone): string
    {
        return $scene . ':' . sha1($userId . '|' . $phone);
    }

    private function isValidNormalizedPhone(string $phone): bool
    {
        return (bool) preg_match('/^\+?[0-9]{6,20}$/', $phone);
    }

    public function getQuickLoginUrl(Request $request)
    {
        $user = $request->user();

        $url = $this->loginService->generateQuickLoginUrl($user, $request->input('redirect'));
        return $this->success($url);
    }
}
