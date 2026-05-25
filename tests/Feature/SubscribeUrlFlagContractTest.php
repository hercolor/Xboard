<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Utils\Helper;
use Tests\TestCase;

final class SubscribeUrlFlagContractTest extends TestCase
{
    public function test_generated_subscribe_url_defaults_to_hiddify_flag_for_app_imports(): void
    {
        $url = Helper::getSubscribeUrl('sub-token', 'https://subscribe.example.invalid');

        $this->assertSame('https', parse_url($url, PHP_URL_SCHEME));
        $this->assertSame('subscribe.example.invalid', parse_url($url, PHP_URL_HOST));
        $this->assertSame('/' . admin_setting('subscribe_path', 's') . '/sub-token', parse_url($url, PHP_URL_PATH));

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('hiddify', $query['flag'] ?? null);
    }
}
