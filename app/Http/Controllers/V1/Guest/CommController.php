<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\Plugin\HookManager;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Support\Facades\Http;

class CommController extends Controller
{
    public function config()
    {
        $supportContactUrl = admin_setting('support_contact_url');
        $supportGroupUrl = admin_setting('support_group_url', admin_setting('telegram_discuss_link'));

        $data = [
            'tos_url' => admin_setting('tos_url'),
            'is_email_verify' => (int) admin_setting('email_verify', 0) ? 1 : 0,
            'is_invite_force' => (int) admin_setting('invite_force', 0) ? 1 : 0,
            'email_whitelist_suffix' => (int) admin_setting('email_whitelist_enable', 0)
                ? Helper::getEmailSuffix()
                : 0,
            'is_captcha' => (int) admin_setting('captcha_enable', 0) ? 1 : 0,
            'captcha_type' => admin_setting('captcha_type', 'recaptcha'),
            'recaptcha_site_key' => admin_setting('recaptcha_site_key'),
            'recaptcha_v3_site_key' => admin_setting('recaptcha_v3_site_key'),
            'recaptcha_v3_score_threshold' => admin_setting('recaptcha_v3_score_threshold', 0.5),
            'turnstile_site_key' => admin_setting('turnstile_site_key'),
            'app_description' => admin_setting('app_description'),
            'app_url' => admin_setting('app_url'),
            'logo' => admin_setting('logo'),
            'support_contact_label' => admin_setting('support_contact_label'),
            'support_contact_url' => $supportContactUrl,
            'support_group_label' => admin_setting('support_group_label'),
            'support_group_url' => $supportGroupUrl,
            'windows_download_url' => admin_setting('windows_download_url'),
            'macos_download_url' => admin_setting('macos_download_url'),
            'android_download_url' => admin_setting('android_download_url'),
            // APP 兼容字段：hiddify-app 当前从 customer_service* 读取客服入口。
            // 保留 support_* 字段给 DK_Theme/旧前端，同时补充别名避免前端分叉。
            'customer_service' => $supportContactUrl ?: $supportGroupUrl,
            'customer_service_url' => $supportContactUrl ?: $supportGroupUrl,
            'customerServiceUrl' => $supportContactUrl ?: $supportGroupUrl,
            // 保持向后兼容
            'is_recaptcha' => (int) admin_setting('captcha_enable', 0) ? 1 : 0,
        ];

        $data = HookManager::filter('guest_comm_config', $data);

        return $this->success($data);
    }
}
