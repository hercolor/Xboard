<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Http\Requests\Passport\CommSendPhoneVerify;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\User;
use App\Services\CaptchaService;
use App\Services\Sms\SmsBaoService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CommController extends Controller
{

    public function sendEmailVerify(CommSendEmailVerify $request)
    {
                // 验证人机验证码
        $captchaService = app(CaptchaService::class);
        [$captchaValid, $captchaError] = $captchaService->verify($request);
        if (!$captchaValid) {
            return $this->fail($captchaError);
        }

        $account = trim((string) ($request->input('account') ?: $request->input('email')));
        if ($account === '') {
            return $this->fail([400, __('Account can not be empty')]);
        }

        if (str_contains($account, '@')) {
            $email = strtolower($account);
        } else {
            $phone = User::normalizePhone($account);
            if (!$phone) {
                return $this->fail([400, __('Phone format is incorrect')]);
            }
            $user = User::byPhone($phone)->first();
            if (!$user) {
                return $this->fail([400, __('This phone is not registered in the system')]);
            }
            $email = $user->email;
        }

        // 检查白名单后缀限制
        if ((int) admin_setting('email_whitelist_enable', 0)) {
            $isRegisteredEmail = User::byEmail($email)->exists();
            if (!$isRegisteredEmail) {
                $allowedSuffixes = Helper::getEmailSuffix();
                $emailSuffix = substr(strrchr($email, '@'), 1);

                if (!in_array($emailSuffix, $allowedSuffixes)) {
                    return $this->fail([400, __('Email suffix is not in whitelist')]);
                }
            }
        }

        if (Cache::get(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email))) {
            return $this->fail([400, __('Email verification code has been sent, please request again later')]);
        }
        $code = rand(100000, 999999);
        $subject = admin_setting('app_name', 'XBoard') . __('Email verification code');

        SendEmailJob::dispatch([
            'email' => $email,
            'subject' => $subject,
            'template_name' => 'verify',
            'template_value' => [
                'name' => admin_setting('app_name', 'XBoard'),
                'code' => $code,
                'url' => admin_setting('app_url')
            ]
        ]);

        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $email), $code, 300);
        Cache::put(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email), time(), 60);
        return $this->success(true);
    }

    public function sendPhoneVerify(CommSendPhoneVerify $request, SmsBaoService $smsBaoService)
    {
        // 验证人机验证码
        $captchaService = app(CaptchaService::class);
        [$captchaValid, $captchaError] = $captchaService->verify($request);
        if (!$captchaValid) {
            return $this->fail($captchaError);
        }

        $account = trim((string) ($request->input('account') ?: $request->input('phone')));
        $phone = User::normalizePhone($account);
        if (!$phone || !preg_match('/^\+?[0-9]{6,20}$/', $phone)) {
            return $this->fail([400, __('Phone format is incorrect')]);
        }

        if (!User::byPhone($phone)->exists()) {
            return $this->fail([400, __('This phone is not registered in the system')]);
        }

        $cacheSubject = 'forget:' . sha1($phone);
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

    public function pv(Request $request)
    {
        $inviteCode = InviteCode::where('code', $request->input('invite_code'))->first();
        if ($inviteCode) {
            $inviteCode->pv = $inviteCode->pv + 1;
            $inviteCode->save();
        }

        return $this->success(true);
    }

}
