<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\StripeService;
use App\UseCases\MeetingQuota\HandleStripeWebhookAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Stripe からの決済結果通知(Webhook)を受け取る公開エンドポイント(S-A-03)。
 *
 * ⚠️ 認証なし。叩くのは Stripe のサーバーでブラウザではないため、ログインセッションも
 *    CSRF トークンも存在しない(原典 インターフェース「認証なし(署名検証のみ)」)。
 *    **正当性の担保は署名検証だけ**で、ここを飛ばすと誰でも POST して残面談回数を増やせる。
 *
 * 返す HTTP ステータスの意味(Stripe 側の再送挙動に直結する):
 *   - 400: 署名が検証できない。偽装または設定ミスなので受け取らない
 *   - 200: 受理した。**対応していない種類の通知も 200 で返して無視する**
 *          (原典 要件「想定外の通知が届いても処理が破綻しない」)。
 *          200 以外を返すと Stripe は失敗と見なして再送を繰り返す。
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeService $stripe, HandleStripeWebhookAction $action): Response
    {
        // 署名検証。生のリクエストボディをそのまま渡す必要がある
        // (JSON に変換してから署名を検証することはできない。1 バイトでも変わると不一致になる)。
        //
        // ⚠️ try-catch は書かない(_共通ルール.md §2)。InvalidWebhookSignatureException は
        //    Symfony の HttpException を継承しているので、投げるだけで 400 が返る。
        //    Handler.php:47-50 の REDIRECT_BACK_STATUSES は [409, 422] のみで 400 は対象外 ——
        //    直前ページへ戻すこともフラッシュも起きず、Stripe が見るステータスだけが伝わる。
        $event = $stripe->verifyWebhook(
            payload: $request->getContent(),
            signature: $request->header('Stripe-Signature'),
        );

        $action($event);

        return response()->noContent();
    }
}
