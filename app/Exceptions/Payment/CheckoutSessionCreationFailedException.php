<?php

declare(strict_types=1);

namespace App\Exceptions\Payment;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Stripe の決済画面(Checkout Session)を作れなかった際の例外(HTTP 409)。
 * `StripeService::createCheckoutSession()` が Stripe 側の失敗を包み替えて throw する。
 *
 * 想定する原因は 2 つ——①STRIPE_SECRET が未設定 / 誤り ②Stripe 側の障害・通信タイムアウト。
 * どちらも受講生には同じ「いま買えない」でしかないので、文言は 1 つに固定する。
 *
 * ⚠️ 決済はまだ 1 円も動いていない時点の失敗なので、残面談回数への影響は無い
 *    (加算は Webhook が payments を succeeded にしたときだけ)。
 *    原典 要件「決済が失敗 / 中断した場合は、残数が変わらない」はこの経路でも満たされる。
 *
 * ⚠️ 500 ではなく 409 にしている。app/Exceptions/Handler.php が 409 を拾って直前の画面へ戻し、
 *    error フラッシュで理由を見せるため(_共通ルール.md §2)。素の 500 ページだと
 *    受講生は「何が起きたのか / もう一度試してよいのか」が分からない。
 *
 * 手本: app/Exceptions/MeetingQuota/InsufficientMeetingQuotaException.php(文言 1 つ・公開コンストラクタ)
 */
final class CheckoutSessionCreationFailedException extends ConflictHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('決済サービスに接続できませんでした。時間をおいてお試しください。', $previous);
    }
}
