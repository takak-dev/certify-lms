<?php

declare(strict_types=1);

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
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Google Calendar 連携(S-A-01)。コーチが自分の Google アカウントを任意連携するための
    // OAuth 2.0 クライアント情報。実際の値は .env にのみ置き、リポジトリにはコミットしない。
    // 未設定でも面談機能は従来どおり動く(Google を参照しないだけ)。連携カードの表示は
    // resources/views/settings/_partials/tab-meeting.blade.php:81 の Route::has() が
    // ルート登録の有無を見ており、この設定値は見ていない。
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
    ],

    // Stripe 連携(S-A-03)。追加面談パックの決済を Stripe Checkout に委譲するための資格情報。
    // 実際の値は .env にのみ置き、リポジトリにはコミットしない。
    //
    // ⚠️ 公開可能キー(STRIPE_KEY)は持たない。決済フォームは Stripe がホストする外部画面に
    //    委譲する(原典「決済画面は信頼できる決済プラットフォームに任されている」)ため、
    //    ブラウザ側で Stripe.js を動かす必要がなく、公開可能キーの出番が無い。
    //
    // ⚠️ webhook_secret は署名検証専用で、API 呼び出しには使わない。secret と役割が違う。
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

];
