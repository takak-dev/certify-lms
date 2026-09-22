<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Enums\MeetingPackStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\MeetingQuota\MeetingPackNotPurchasableException;
use App\Exceptions\Payment\CheckoutSessionCreationFailedException;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\Services\StripeService;

/**
 * 追加面談パックの購入を開始するユースケース(S-A-03)。
 *
 * 購入記録を pending で作ってから Stripe の決済画面を用意し、その URL を返す。
 * 残面談回数はここでは 1 回も増えない —— 増えるのは Webhook が決済完了を伝えたときだけ
 * (原典 要件「決済結果の通知に応じて、完了した購入の分だけ残面談回数を加算する」)。
 *
 * ⚠️ **DB::transaction() で囲まない。** ②の INSERT と④の UPDATE はそれぞれ単体で原子的で、
 *    まとめて守るべき不変条件が無い。逆にトランザクションの中で Stripe を呼ぶと、応答を待つ間
 *    DB 接続を掴み続けるうえ、Stripe 側で Session が作られた後にロールバックしても
 *    **外部サービスは取り消せない**(手本の考え方: GoogleCalendar/SyncMeetingAction:23-26)。
 *
 * ⚠️ 処理順が「payments を作る → Stripe」なのは意図的。逆順だと、Stripe に Session ができた直後に
 *    こちらの INSERT が失敗した場合、受講生は支払えるのに対応する行が無い = 入金だけ受け取って
 *    回数を渡せない状態になる。この順なら、失敗して残るのは cs_ が null の pending 行だけで、
 *    残数にも unique 制約にも影響しない(nullable な unique 列に NULL は何行あってもよい)。
 */
final class CheckoutCreateAction
{
    public function __construct(
        private readonly StripeService $stripe,
    ) {}

    /**
     * @return string 受講生を送り出す Stripe の決済画面 URL
     *
     * @throws MeetingPackNotPurchasableException 公開中でないパックが指定された場合(409)
     * @throws CheckoutSessionCreationFailedException Stripe 側で Session を作れなかった場合(409)
     */
    public function __invoke(User $user, MeetingPack $pack): string
    {
        // ① 公開中のパックしか買えない(原典 要件)。画面に並べない(SelectAction)だけでは、
        //    POST に meeting_pack_id を手で乗せられた場合を防げない(CLAUDE.md §3-7)。
        if ($pack->status !== MeetingPackStatus::Published) {
            throw new MeetingPackNotPurchasableException;
        }

        // ② 購入記録を pending で作る。
        //    ⚠️ amount / quantity はマスタを参照せず、**この瞬間の値を控える**
        //       (原典「決済額 / 購入回数は購入時点の値を控えとして保存し、後からマスタを
        //        変更しても過去の購入を監査できる」)。
        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'meeting_pack_id' => $pack->id,
            'amount' => $pack->price,
            'quantity' => $pack->meeting_count,
            'status' => PaymentStatus::Pending,
        ]);

        // ③ Stripe に決済画面を用意させる。client_reference_id にこちらの payments.id を渡すことで、
        //    Webhook が届いたときにどの購入の話かを一意に引ける。
        $session = $this->stripe->createCheckoutSession(
            pack: $pack,
            clientReferenceId: $payment->id,
            successUrl: $this->successUrl(),
            cancelUrl: route('meeting-quota.checkout.select'),
        );

        // ④ 発行された Checkout Session の ID を控える。この列の unique 制約が、
        //    同じ決済が 2 行に分かれることを DB のレベルで防ぐ(冪等性の土台)。
        $payment->update(['stripe_checkout_session_id' => $session['id']]);

        return $session['url'];
    }

    /**
     * 決済完了後に Stripe が受講生を送り返す URL。
     *
     * ⚠️ `{CHECKOUT_SESSION_ID}` は Stripe が実際の cs_... に置き換えるプレースホルダ。
     *    route() のクエリ引数として渡すと波括弧が URL エンコードされて置換されなくなるため、
     *    文字列として連結する。完了画面はこの session_id から購入記録を引く。
     */
    private function successUrl(): string
    {
        return route('meeting-quota.checkout.success').'?session_id={CHECKOUT_SESSION_ID}';
    }
}
