<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_V2BOARD_REGION', 'us-east-1'),
    ],

    'smsbao' => [
        'enabled' => env('SMSBAO_ENABLED', true),
        'api_url' => env('SMSBAO_API_URL', 'https://api.smsbao.com/sms'),
        'username' => env('SMSBAO_USERNAME'),
        'password' => env('SMSBAO_PASSWORD'),
        'password_md5' => env('SMSBAO_PASSWORD_MD5'),
        'sign' => env('SMSBAO_SIGN'),
        'template' => env('SMSBAO_TEMPLATE', '您的验证码是 {code}，5分钟内有效。如非本人操作，请忽略。'),
    ],

];
