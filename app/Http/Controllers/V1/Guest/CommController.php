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
        $settings = admin_settings_batch([
            'tos_url',
            'email_verify',
            'invite_force',
            'email_whitelist_enable',
            'captcha_enable',
            'captcha_type',
            'recaptcha_site_key',
            'recaptcha_v3_site_key',
            'recaptcha_v3_score_threshold',
            'turnstile_site_key',
            'app_description',
            'app_url',
            'logo',
            'support_contact_label',
            'support_contact_url',
            'support_group_label',
            'support_group_url',
            'telegram_discuss_link',
            'windows_download_url',
            'macos_download_url',
            'android_download_url',
        ]);

        $supportContactUrl = $settings['support_contact_url'];
        $supportGroupUrl = $settings['support_group_url'] ?: $settings['telegram_discuss_link'];

        $data = [
            'tos_url' => $settings['tos_url'],
            'is_email_verify' => (int) ($settings['email_verify'] ?? 0) ? 1 : 0,
            'is_invite_force' => (int) ($settings['invite_force'] ?? 0) ? 1 : 0,
            'email_whitelist_suffix' => (int) ($settings['email_whitelist_enable'] ?? 0)
                ? Helper::getEmailSuffix()
                : 0,
            'is_captcha' => (int) ($settings['captcha_enable'] ?? 0) ? 1 : 0,
            'captcha_type' => $settings['captcha_type'] ?: 'recaptcha',
            'recaptcha_site_key' => $settings['recaptcha_site_key'],
            'recaptcha_v3_site_key' => $settings['recaptcha_v3_site_key'],
            'recaptcha_v3_score_threshold' => $settings['recaptcha_v3_score_threshold'] ?: 0.5,
            'turnstile_site_key' => $settings['turnstile_site_key'],
            'app_description' => $settings['app_description'],
            'app_url' => $settings['app_url'],
            'logo' => $settings['logo'],
            'support_contact_label' => $settings['support_contact_label'],
            'support_contact_url' => $supportContactUrl,
            'support_group_label' => $settings['support_group_label'],
            'support_group_url' => $supportGroupUrl,
            'windows_download_url' => $settings['windows_download_url'],
            'macos_download_url' => $settings['macos_download_url'],
            'android_download_url' => $settings['android_download_url'],
            // APP 兼容字段：hiddify-app 当前从 customer_service* 读取客服入口。
            // 保留 support_* 字段给 DK_Theme/旧前端，同时补充别名避免前端分叉。
            'customer_service' => $supportContactUrl ?: $supportGroupUrl,
            'customer_service_url' => $supportContactUrl ?: $supportGroupUrl,
            'customerServiceUrl' => $supportContactUrl ?: $supportGroupUrl,
            // 保持向后兼容
            'is_recaptcha' => (int) ($settings['captcha_enable'] ?? 0) ? 1 : 0,
        ];

        $data = HookManager::filter('guest_comm_config', $data);

        return $this->success($data);
    }
}
