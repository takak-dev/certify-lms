<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stripe から届いた決済結果の通知を処理するユースケース(S-A-03)。
 *
 * 受け取るのは StripeService::verifyWebhook() が**署名検証を終えた**イベントの配列だけ。
 * このクラスは stripe-php を一切知らないので、テストは素の配列を渡すだけで書ける。
 *
 * ⚠️ **同じ通知が何度届いても、残面談回数は 1 回分しか増えない**(原典 要件・このチケットの核心)。
 *    Stripe は再送を行うため、重複受信は異常ではなく通常の運用で起きる
 *    (docs.stripe.com/webhooks「Handle duplicate events」)。
 *
 * ⚠️ 対応していない種類の通知は**黙って無視する**(原典 要件「想定外の通知が届いても
 *    処理が破綻しない」)。例外を投げて 500 を返すと、Stripe は失敗と見なして再送を繰り返す。
 *
 * 手本: app/UseCases/SectionQuestionAnswer/StoreAction.php
 *      (lockForUpdate() で行を排他取得してから増分する形。ダブルクリックによる二重送信と
 *       Webhook の重複受信は、まったく同じ問題)
 */
final class HandleStripeWebhookAction
{
    /** 取り扱う通貨。原典 非機能要件「通貨は円のみ」。StripeService が price_data に渡す値と対になる */
    private const CURRENCY = 'jpy';

    public function __construct(
        private readonly MeetingQuotaService $quota,
    ) {}

    /**
     * @param array{id: string, type: string, object: array<string, mixed>} $event 署名検証済みのイベント
     */
    public function __invoke(array $event): void
    {
        match ($event['type']) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($event['object']),
            'charge.refunded' => $this->handleChargeRefunded($event['object']),
            // ⚠️ match は default が無いと UnhandledMatchError を投げる。ここでは
            //    「知らない通知は無視する」が要件なので、default を必ず置く。
            default => null,
        };
    }

    /**
     * 決済完了。ここでだけ残面談回数が増える。
     *
     * @param array<string, mixed> $session Checkout Session オブジェクト
     */
    private function handleCheckoutCompleted(array $session): void
    {
        // Session 作成時に渡した payments.id。Stripe が発行した cs_... ではなくこちらを主に使う理由は、
        // cs_... の書き戻し(CheckoutCreateAction ④)が失敗していると null のことがあるため。
        // client_reference_id は Session に最初から載っているので取りこぼしが無い。
        $paymentId = $session['client_reference_id'] ?? null;

        if (! is_string($paymentId) || $paymentId === '') {
            return; // こちらが作った Session ではない。無視して 200 を返す
        }

        // ⚠️ 支払いが済んでいない完了通知がありうる(コンビニ決済等の後日確定型)。
        //    Stripe の Checkout\Session の定義にも「payment_status の値で注文を履行するか
        //    決められる」と明記がある。paid 以外では回数を増やさない。
        if (($session['payment_status'] ?? null) !== 'paid') {
            return;
        }

        DB::transaction(function () use ($paymentId, $session): void {
            // ① 行を排他ロックして取得。重複した通知が同時に届いても、片方はここで待たされる。
            $payment = Payment::query()
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                // 消えている / 別環境の通知。処理は続けられないが、決済は成立している可能性があるため
                // 「どの ID を引けなかったか」だけ残す(payments.id は個人情報ではない)。
                Log::warning('Stripe webhook の client_reference_id に対応する購入記録がありません。', [
                    'payment_id' => $paymentId,
                ]);

                return;
            }

            // ② **pending 以外はすべて処理済みとして引き返す** —— これが冪等性の本体。
            //    ①のロックを待っていた 2 本目の通知は、ここで必ず引き返す。
            //    ⚠️ 「succeeded なら戻る」にしてはいけない。返金で refunded になった行が
            //       ガードを素通りし、status を succeeded に巻き戻したうえで purchased を
            //       もう 1 行積む ＝ **返金したのに残数が復活する**(再送はダッシュボードの
            //       「イベントを再送」でも自動リトライでも起きる)。
            //       回帰テスト: StripeWebhookTest::test_completed_webhook_after_refund_does_not_re_add_quota
            if ($payment->status !== PaymentStatus::Pending) {
                return;
            }

            // ③ この通知が「本当にこの購入のものか」を、金額・通貨・Session ID の 3 つで確かめる。
            //
            //    ⚠️ **確認できないときは通さない(fail-closed)。** 「金額が読めなければ素通り」に
            //       すると、金額を含まない通知を作れる経路がそのまま迂回路になる。
            //    ⚠️ 通貨も見る。payments.amount は円建ての整数だが、Stripe の amount_total は
            //       その決済の通貨の最小単位。JPY と KRW はどちらもゼロ小数通貨なので、
            //       3000(≒330 円の KRW)と ¥3,000 が**数値として一致してしまう**。
            //    ⚠️ Session ID も突き合わせる(多層防御)。届く通知は署名検証を通った自アカウントの
            //       ものに限られるので現状ただちに悪用できる経路は無いが、client_reference_id は
            //       Payment Link に URL クエリで任意の値を付けられる項目で、運用で公開 Payment Link を
            //       1 本作った瞬間に「別の決済の完了通知を、この購入の完了として処理する」が成立しうる。
            //       cs_ が null の行だけは、書き戻し(CheckoutCreateAction ④)の失敗として許す。
            $amountTotal = $session['amount_total'] ?? null;
            $currency = $session['currency'] ?? null;
            $sessionId = $session['id'] ?? null;

            $mismatch = match (true) {
                ! is_int($amountTotal) || $amountTotal !== $payment->amount => 'amount',
                ! is_string($currency) || strtolower($currency) !== self::CURRENCY => 'currency',
                $payment->stripe_checkout_session_id !== null
                    && $payment->stripe_checkout_session_id !== $sessionId => 'session_id',
                default => null,
            };

            if ($mismatch !== null) {
                // ⚠️ 加算しないだけで pending のまま残す。人が Stripe 側と突き合わせられる状態にする。
                //    ただし黙って戻ると誰も気付けないので、ここだけは記録を残す
                //    (decisions #225 の「人が突き合わせられるように」を成り立たせるための信号)。
                Log::warning('Stripe webhook の内容が購入記録と一致しないため加算しませんでした。', [
                    'payment_id' => $payment->id,
                    'mismatch' => $mismatch,
                    'expected_amount' => $payment->amount,
                    'received_amount' => $amountTotal,
                    'received_currency' => $currency,
                ]);

                return;
            }

            // ④ 購入記録を完了にする。あわせて Stripe 側の識別子を埋める。
            //    stripe_checkout_session_id は CheckoutCreateAction ④で書き戻し済みのはずだが、
            //    そこが失敗していた場合にここで自己修復する。
            //    stripe_payment_intent_id は返金通知(charge.refunded)から購入行を
            //    逆引きするために必要(返金の Refund オブジェクトに cs_... は入っていない)。
            $payment->update([
                'status' => PaymentStatus::Succeeded,
                'paid_at' => now(),
                'stripe_checkout_session_id' => $payment->stripe_checkout_session_id
                    ?? (is_string($session['id'] ?? null) ? $session['id'] : null),
                // ⚠️ 既存の値を null で潰さない。返金通知(charge.refunded)は payment_intent でしか
                //    購入行を引けないため、一度入った値を失うと返金の減算ができなくなる。
                'stripe_payment_intent_id' => is_string($session['payment_intent'] ?? null)
                    ? $session['payment_intent']
                    : $payment->stripe_payment_intent_id,
            ]);

            // ⑤ 面談回数の台帳に 1 行積む。残数はこの台帳の合計で決まるので、
            //    ここまで来て初めて受講生の残数が増える(MeetingQuotaService::remaining)。
            //    ⚠️ 増やす回数は payments.quantity —— 購入時点の控え。マスタを見ない。
            MeetingQuotaTransaction::create([
                'user_id' => $payment->user_id,
                'type' => MeetingQuotaTransactionType::Purchased,
                'amount' => $payment->quantity,
                'related_payment_id' => $payment->id,
                'occurred_at' => now(),
            ]);
        });
    }

    /**
     * 返金。管理者が Stripe ダッシュボードで返金すると届く(decisions #210)。
     *
     * ⚠️ **全額返金のときだけ減らす**(decisions #220・本人判断)。部分返金(amount_refunded が
     *    amount より小さい)では何もしない。原典が返金運用そのものをスコープ外としており、
     *    「いくら返したら何回減らすか」の按分規則がどこにも無いため、こちらで発明しない。
     *
     * ⚠️ 減らし方は **payment_refunded 種別のマイナス 1 行**(decisions #219・本人判断)。
     *    既存の Refunded(ラベル「返却」)は面談キャンセル時の +1 で意味が逆なので流用しない。
     *    種別を 1 つ増やしたことで直した箇所は 4 つ ——
     *    ①Enum のケースと label() ②MeetingQuotaService::remaining() の whereIn
     *    ③支給 Blade history.blade.php の default 無し match(バッジ色) ④同 Blade の絞り込みは
     *    cases() を回しているため「返金」の選択肢が自動で増える(コードの変更は不要だが画面は変わる)。
     *    ②を忘れると「返金したのに残数が減らない」静かな不具合になるため、テストで固定している。
     *
     * @param array<string, mixed> $charge Charge オブジェクト
     */
    private function handleChargeRefunded(array $charge): void
    {
        // Charge.refunded は「全額返金されたか」。部分返金では false のまま
        // (vendor/stripe/stripe-php/lib/Charge.php:48)。
        if (($charge['refunded'] ?? false) !== true) {
            return;
        }

        // 返金の通知に Checkout Session の ID は入っていないため、payment_intent で逆引きする
        // (docs.stripe.com/api/refunds —— Refund が持つのは payment_intent / charge)。
        $paymentIntentId = $charge['payment_intent'] ?? null;

        if (! is_string($paymentIntentId) || $paymentIntentId === '') {
            return;
        }

        DB::transaction(function () use ($paymentIntentId): void {
            $payment = Payment::query()
                ->where('stripe_payment_intent_id', $paymentIntentId)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                // こちらの購入ではない(別環境の決済など)。返金が反映されない事態に
                // 気付けるよう、引けなかった識別子だけ残す。
                Log::warning('Stripe の返金通知に対応する購入記録がありません。', [
                    'payment_intent_id' => $paymentIntentId,
                ]);

                return;
            }

            // 冪等性: 同じ返金通知が再送されても 2 回引かない。
            if ($payment->status === PaymentStatus::Refunded) {
                return;
            }

            // 完了していない購入は回数を配っていないので、引くものが無い。
            // ⚠️ 「実際に支払われたが突合に失敗して pending のまま残った行」もここに来る。
            //    その場合は返金しても残数が動かないので、人が気付けるよう記録する。
            if ($payment->status !== PaymentStatus::Succeeded) {
                Log::warning('完了していない購入に返金通知が届いたため残数を減らしませんでした。', [
                    'payment_id' => $payment->id,
                    'status' => $payment->status->value,
                ]);

                return;
            }

            // 同一受講生の消費と直列化する。残数の集計(SELECT)と INSERT の間に面談予約が
            // 入り込むと、0 で止めたつもりが負になりうる。
            // 手本: app/UseCases/MeetingQuota/ConsumeQuotaAction.php:38-39(同じ User 行ロック)。
            User::query()->whereKey($payment->user_id)->lockForUpdate()->first();

            $payment->update(['status' => PaymentStatus::Refunded]);

            // 減らす量は「購入した回数」と「いま残っている回数」の小さいほう。
            // 既に面談で使い切っていればマイナスの残数を作らず 0 で止める(decisions #211)。
            $decrement = min($payment->quantity, max($this->quota->remaining($payment->user), 0));

            if ($decrement < 1) {
                return; // 引く余地が無い。payments は返金済みに変えたのでここで終わり
            }

            MeetingQuotaTransaction::create([
                'user_id' => $payment->user_id,
                'type' => MeetingQuotaTransactionType::PaymentRefunded,
                'amount' => -$decrement,
                'related_payment_id' => $payment->id,
                'note' => '返金による取り消し',
                'occurred_at' => now(),
            ]);
        });
    }
}
