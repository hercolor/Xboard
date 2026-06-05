<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;

class SmsBaoService
{
    private const STATUS_MESSAGES = [
        '0' => '短信发送成功',
        '-1' => '参数不全',
        '-2' => '服务器空间不支持',
        '30' => '密码错误',
        '40' => '账号不存在',
        '41' => '余额不足',
        '42' => '账户已过期',
        '43' => 'IP地址限制',
        '50' => '内容含有敏感词',
    ];

    public function sendVerificationCode(string $phone, string $code): array
    {
        if (!$this->isEnabled()) {
            return [false, __('SMS service is disabled')];
        }

        $username = $this->setting('smsbao_username', 'username');
        $password = $this->passwordHash();
        if ($username === '' || $password === '') {
            return [false, __('SMS service is not configured')];
        }

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post($this->apiUrl(), [
                    'u' => $username,
                    'p' => $password,
                    'm' => $phone,
                    'c' => $this->content($code),
                ]);
        } catch (\Throwable $e) {
            return [false, __('SMS send failed') . ': ' . $e->getMessage()];
        }

        $status = trim((string) $response->body());
        if (!$response->ok() || $status !== '0') {
            $message = self::STATUS_MESSAGES[$status] ?? ($status !== '' ? $status : $response->status());
            return [false, __('SMS send failed') . ': ' . $message];
        }

        return [true, null];
    }

    private function isEnabled(): bool
    {
        $value = admin_setting('smsbao_enabled', config('services.smsbao.enabled', true));
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    private function apiUrl(): string
    {
        $url = $this->setting('smsbao_api_url', 'api_url', 'https://api.smsbao.com/sms');
        $url = rtrim($url, '/');
        return str_ends_with($url, '/sms') ? $url : $url . '/sms';
    }

    private function content(string $code): string
    {
        $template = $this->setting('smsbao_template', 'template', '您的验证码是 {code}，5分钟内有效。如非本人操作，请忽略。');
        $content = str_replace('{code}', $code, $template);
        $sign = trim($this->setting('smsbao_sign', 'sign', admin_setting('app_name', 'XBoard')));

        if ($sign === '' || str_starts_with($content, '【')) {
            return $content;
        }

        return "【{$sign}】{$content}";
    }

    private function passwordHash(): string
    {
        $passwordMd5 = $this->setting('smsbao_password_md5', 'password_md5');
        if ($passwordMd5 !== '') {
            return strtolower($passwordMd5);
        }

        $password = $this->setting('smsbao_password', 'password');
        if ($password === '') {
            return '';
        }

        if (preg_match('/^[a-f0-9]{32}$/i', $password)) {
            return strtolower($password);
        }

        return md5($password);
    }

    private function setting(string $adminKey, string $configKey, mixed $default = ''): string
    {
        return trim((string) admin_setting($adminKey, config("services.smsbao.{$configKey}", $default)));
    }
}
