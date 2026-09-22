<?php

declare(strict_types=1);

namespace App\Exceptions\Payment;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Stripe からの Webhook の署名検証に失敗した際に throw される(HTTP 400)。
 *
 * 想定される原因は 3 つ——①偽装された POST ②STRIPE_WEBHOOK_SECRET の設定ミス
 * ③リクエストボディが途中で加工された。いずれも「受け取ってはいけない通知」なので、
 * 内容を一切解釈せずに捨てる。
 *
 * ⚠️ 400 を返すのは意図的。Stripe は 2xx 以外を失敗と見なして再送するが、署名が合わない通知は
 *    何度再送されても合わないため、再送されても同じ判定で捨て続けるだけで害はない。
 *    逆にここで 200 を返すと、設定ミスに気付く手段が無くなる。
 *
 * ⚠️ 409 系と違い、Handler.php の「直前ページへ戻して error フラッシュ」は**効かない**
 *    (Handler.php:47-50 の REDIRECT_BACK_STATUSES は [409, 422] のみ)。Stripe はボディを読まず
 *    ステータスだけを見るので、この文言は人がログで読むためのもの。
 *
 * 手本: app/Exceptions/MeetingQuota/InsufficientMeetingQuotaException.php
 *      (Symfony の HttpException を継承して、ステータスを Laravel に解釈させる形)
 */
final class InvalidWebhookSignatureException extends BadRequestHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Webhook の署名を検証できませんでした。', $previous);
    }
}
