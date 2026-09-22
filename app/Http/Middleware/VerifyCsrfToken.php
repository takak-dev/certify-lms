<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Stripe からの Webhook(S-A-03)。叩くのは Stripe のサーバーでブラウザではないため
        // CSRF トークンを持たず、除外しないと 419 で弾かれて決済完了を受け取れない。
        // CSRF は「ログイン中のユーザーのブラウザが意図しない送信をさせられる」のを防ぐ仕組みで、
        // セッションを持たないサーバー間通信では守る対象がそもそも無い。
        // 正当性は署名検証(StripeService::verifyWebhook())で担保する(原典「認証なし(署名検証のみ)」)。
        'webhooks/stripe',
    ];
}
