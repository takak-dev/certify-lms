<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingQuota;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Stripe からの決済結果通知(POST /webhooks/stripe)の受け口を検証する(S-A-03)。
 *
 * ⚠️ このエンドポイントは**認証なし**で、正当性の担保は署名検証だけ(原典 インターフェース)。
 *    そのため「署名が合わなければ何も起きない」ことを、加算・減算の両方で確かめる。
 *
 * 署名は本番と同じ方法で自前で組む —— タイムスタンプとリクエストボディを結合して
 * HMAC-SHA256 を取り、`t=...,v1=...` の形にする。ボディは 1 バイトも加工してはならない
 * (JSON を配列に直して組み立て直すと署名が一致しなくなる)ので、送信もエンコード済みの
 * 文字列をそのまま渡している。
 *
 * 署名鍵はテスト専用のダミー(phpunit.xml の STRIPE_WEBHOOK_SECRET)。
 * STRIPE_SECRET は空にしてあるので、このテストから本物の Stripe を叩くことはない。
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    /** Webhook の URL。ルート名は routes/web.php の webhooks.stripe */
    private const ENDPOINT = '/webhooks/stripe';

    /**
     * 決済が完了した購入を 1 件だけ用意する。
     *
     * @return array{0: User, 1: Payment}
     */
    private function makePendingPayment(int $quantity = 3, int $maxMeetings = 0): array
    {
        // Arrange: 残数の計算式は max_meetings + 台帳の合計なので、
        //          max_meetings を 0 にして「台帳の増減だけ」を観測できるようにする
        $user = User::factory()->student()->create(['max_meetings' => $maxMeetings]);
        $pack = MeetingPack::factory()->published()->withCount($quantity)->withPrice(3000)->create();

        $payment = Payment::factory()
            ->pending()
            ->forUser($user)
            ->forPack($pack)
            ->create();

        return [$user, $payment];
    }

    /**
     * 本番と同じ形式の署名ヘッダを付けて Webhook を叩く。
     *
     * @param array<string, mixed> $object 通知の主役(Checkout Session / Charge)
     */
    private function postWebhook(string $type, array $object, bool $validSignature = true): TestResponse
    {
        // Arrange: Stripe が送ってくる形の JSON を組み立てる
        $payload = json_encode([
            'id' => 'evt_test_'.uniqid(),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = $validSignature
            // 正しい署名: タイムスタンプ + "." + ボディ を秘密鍵で HMAC-SHA256
            ? hash_hmac('sha256', $timestamp.'.'.$payload, (string) config('services.stripe.webhook_secret'))
            // 改ざん / 偽装の再現: 形式は正しいが値が合わない
            : str_repeat('0', 64);

        // Act: 生のボディをそのまま送る(Laravel に加工させない)
        return $this->call(
            method: 'POST',
            uri: self::ENDPOINT,
            server: [
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $payload,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function checkoutSession(Payment $payment, array $overrides = []): array
    {
        return array_merge([
            'id' => $payment->stripe_checkout_session_id,
            'object' => 'checkout.session',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_from_webhook',
            'client_reference_id' => $payment->id,
            // 金額・通貨は「この通知が本当にこの購入のものか」の突合に使われる。
            // 実装は fail-closed(確認できなければ加算しない)なので、正常系の雛形にも必ず載せる
            'amount_total' => $payment->amount,
            'currency' => 'jpy',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // 決済完了
    // ------------------------------------------------------------------

    public function test_completed_webhook_marks_payment_succeeded_and_adds_quota(): void
    {
        // Arrange: 3 回パックの購入が pending で存在する
        [$user, $payment] = $this->makePendingPayment(quantity: 3);

        // Act
        $response = $this->postWebhook('checkout.session.completed', $this->checkoutSession($payment));

        // Assert: Stripe には 2xx を返す(それ以外だと再送され続ける)
        $response->assertSuccessful();

        // Assert: 購入記録が完了になり、返金通知から逆引きするための payment_intent も入る
        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('pi_test_from_webhook', $payment->stripe_payment_intent_id);

        // Assert: 台帳に購入 1 行。残数はこの台帳の合計で決まる
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $user->id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
            'amount' => 3,
            'related_payment_id' => $payment->id,
        ]);
        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($user->refresh()));
    }

    /**
     * ⭐ 原典 要件「決済サービスからの通知が重複して届いても、残数の会計が崩れない」。
     * Stripe は再送を行うため、これは異常系ではなく通常運用で起きる。
     */
    public function test_duplicate_completed_webhook_does_not_add_quota_twice(): void
    {
        // Arrange
        [$user, $payment] = $this->makePendingPayment(quantity: 3);

        // Act: まったく同じ内容の通知を 2 回送る
        $this->postWebhook('checkout.session.completed', $this->checkoutSession($payment))->assertSuccessful();
        $this->postWebhook('checkout.session.completed', $this->checkoutSession($payment))->assertSuccessful();

        // Assert: 台帳は 1 行だけ。残数も 1 回分しか増えていない
        $this->assertSame(1, MeetingQuotaTransaction::query()
            ->where('related_payment_id', $payment->id)
            ->where('type', MeetingQuotaTransactionType::Purchased->value)
            ->count());
        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($user->refresh()));
    }

    public function test_invalid_signature_is_rejected_and_changes_nothing(): void
    {
        // Arrange
        [$user, $payment] = $this->makePendingPayment(quantity: 3);

        // Act: 署名だけが合わない通知(偽装・設定ミスの再現)
        $response = $this->postWebhook('checkout.session.completed', $this->checkoutSession($payment), validSignature: false);

        // Assert: 400 で拒否。ここが通ると誰でも残数を増やせてしまう
        $response->assertStatus(400);

        // Assert: 購入記録も残数も一切動かない
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(0, app(MeetingQuotaService::class)->remaining($user->refresh()));
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }

    /**
     * 原典 要件「想定外の通知が届いても処理が破綻しない」。
     * 2xx を返さないと Stripe は失敗と見なして再送を繰り返す。
     */
    public function test_unhandled_event_type_is_ignored_with_success_response(): void
    {
        // Arrange
        [$user, $payment] = $this->makePendingPayment(quantity: 3);

        // Act: こちらが扱わない種類の通知
        $response = $this->postWebhook('customer.created', ['id' => 'cus_test_1', 'object' => 'customer']);

        // Assert
        $response->assertSuccessful();
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(0, app(MeetingQuotaService::class)->remaining($user->refresh()));
    }

    /**
     * checkout.session.completed は「支払い済み」を意味しない場合がある(後日確定型の決済手段)。
     * Stripe の Checkout\Session も payment_status で履行を判断するよう定めている。
     */
    public function test_completed_webhook_without_paid_status_does_not_add_quota(): void
    {
        // Arrange
        [$user, $payment] = $this->makePendingPayment(quantity: 3);

        // Act: 完了通知だが payment_status が unpaid
        $this->postWebhook(
            'checkout.session.completed',
            $this->checkoutSession($payment, ['payment_status' => 'unpaid']),
        )->assertSuccessful();

        // Assert: 何も起きない
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(0, app(MeetingQuotaService::class)->remaining($user->refresh()));
    }

    // ------------------------------------------------------------------
    // 返金(decisions #210 / #211 / #219 / #220)
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function refundedCharge(Payment $payment, array $overrides = []): array
    {
        return array_merge([
            'id' => 'ch_test_1',
            'object' => 'charge',
            'payment_intent' => $payment->stripe_payment_intent_id,
            'refunded' => true,
            'amount' => $payment->amount,
            'amount_refunded' => $payment->amount,
        ], $overrides);
    }

    public function test_full_refund_reduces_quota_once(): void
    {
        // Arrange: 3 回分を購入済み(完了通知まで通した状態を作る)
        [$user, $payment] = $this->makePendingPayment(quantity: 3);
        $this->postWebhook('checkout.session.completed', $this->checkoutSession($payment))->assertSuccessful();
        $payment->refresh();

        // Act: 全額返金の通知を 2 回送る(再送されても 1 回しか引かないことを同時に見る)
        $this->postWebhook('charge.refunded', $this->refundedCharge($payment))->assertSuccessful();
        $this->postWebhook('charge.refunded', $this->refundedCharge($payment))->assertSuccessful();

        // Assert: 購入記録が返金済みになる
        $this->assertSame(PaymentStatus::Refunded, $payment->refresh()->status);

        // Assert: 取り消しは payment_refunded のマイナス 1 行だけ(decisions #219)。
        //         既存の refunded(返却)は面談キャンセル用で意味が違うため使わない
        $this->assertSame(1, MeetingQuotaTransaction::query()
            ->where('related_payment_id', $payment->id)
            ->where('type', MeetingQuotaTransactionType::PaymentRefunded->value)
            ->count());
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'related_payment_id' => $payment->id,
            'type' => MeetingQuotaTransactionType::PaymentRefunded->value,
            'amount' => -3,
        ]);

        // Assert: 購入前の残数(0)に戻っている
        $this->assertSame(0, app(MeetingQuotaService::class)->remaining($user->refresh()));
    }

    /**
     * decisions #220。部分返金では按分規則が決まっていないため残数を動かさない。
     * Charge.refunded は「全額返金されたか」で、部分返金では false のまま。
     */
    public function test_partial_refund_does_not_change_quota(): void
    {
        // Arrange
        [$user, $payment] = $this->makePendingPayment(quantity: 3);
        $this->postWebhook('checkout.session.completed', $this->checkoutSession($payment))->assertSuccessful();
        $payment->refresh();

        // Act: 3,000 円のうち 1,000 円だけ返金された通知
        $this->postWebhook('charge.refunded', $this->refundedCharge($payment, [
            'refunded' => false,
            'amount_refunded' => 1000,
        ]))->assertSuccessful();

        // Assert: 購入記録も残数もそのまま
        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($user->refresh()));
    }

    /**
     * decisions #211。返金時点で回数を使い切っていても、マイナスの残数は作らない。
     */
    public function test_refund_stops_at_zero_when_quota_already_consumed(): void
    {
        // Arrange: 3 回購入したあと、面談予約で 2 回消費した状態
        [$user, $payment] = $this->makePendingPayment(quantity: 3);
        $this->postWebhook('checkout.session.completed', $this->checkoutSession($payment))->assertSuccessful();
        $payment->refresh();

        MeetingQuotaTransaction::create([
            'user_id' => $user->id,
            'type' => MeetingQuotaTransactionType::Consumed->value,
            'amount' => -2,
            'occurred_at' => now(),
        ]);
        // 残数は 3 - 2 = 1 回
        $this->assertSame(1, app(MeetingQuotaService::class)->remaining($user->refresh()));

        // Act: 3 回分の購入が全額返金される
        $this->postWebhook('charge.refunded', $this->refundedCharge($payment))->assertSuccessful();

        // Assert: 引かれるのは残っていた 1 回だけ。-2 回にはしない
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'related_payment_id' => $payment->id,
            'type' => MeetingQuotaTransactionType::PaymentRefunded->value,
            'amount' => -1,
        ]);
        $this->assertSame(0, app(MeetingQuotaService::class)->remaining($user->refresh()));
    }

    /**
     * ⚠️ 返金のあとに同じ完了通知が再送されても、回数を配り直さない。
     *
     * Stripe はダッシュボードの「イベントを再送」でも自動リトライでも同じ通知を送り直せる。
     * 冪等の判定を「succeeded なら戻る」にしていると、返金で refunded に変わった行が
     * ガードを素通りし、status を succeeded に巻き戻したうえで purchased をもう 1 行積む
     * ＝ 返金したのに残数が復活する。判定は「pending 以外は処理済み」でなければならない。
     */
    public function test_completed_webhook_after_refund_does_not_re_add_quota(): void
    {
        // Arrange: 購入 → 全額返金まで進めた状態(残数は 0 に戻っている)
        [$user, $payment] = $this->makePendingPayment(quantity: 3);
        $this->postWebhook('checkout.session.completed', $this->checkoutSession($payment))->assertSuccessful();
        $payment->refresh();
        $this->postWebhook('charge.refunded', $this->refundedCharge($payment))->assertSuccessful();
        $this->assertSame(0, app(MeetingQuotaService::class)->remaining($user->refresh()));

        // Act: 決済完了の通知がもう一度届く
        $this->postWebhook('checkout.session.completed', $this->checkoutSession($payment))->assertSuccessful();

        // Assert: 返金済みのまま。回数も戻らない
        $this->assertSame(PaymentStatus::Refunded, $payment->refresh()->status);
        $this->assertSame(0, app(MeetingQuotaService::class)->remaining($user->refresh()));
        $this->assertSame(1, MeetingQuotaTransaction::query()
            ->where('related_payment_id', $payment->id)
            ->where('type', MeetingQuotaTransactionType::Purchased->value)
            ->count());
    }

    /**
     * ⚠️ 署名シークレットが未設定の環境では、検証を通さず必ず拒否する。
     *
     * HMAC は鍵が空文字でも計算できてしまう(vendor/stripe/stripe-php/lib/WebhookSignature.php:139)。
     * 鍵が空のまま検証すると「誰でも正しい署名を作れる」状態になり、認証なしの公開窓口が
     * そのまま残数を増やせる入口に変わる。設定漏れを安全側に倒す。
     */
    public function test_webhook_is_rejected_when_secret_is_not_configured(): void
    {
        // Arrange: 設定漏れの環境を再現する
        [$user, $payment] = $this->makePendingPayment(quantity: 3);
        config(['services.stripe.webhook_secret' => '']);

        // Act: 空の鍵で計算した「形式上は正しい」署名を付けて送る
        $response = $this->postWebhook('checkout.session.completed', $this->checkoutSession($payment));

        // Assert: 400 で拒否し、残数も購入記録も動かさない
        $response->assertStatus(400);
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(0, app(MeetingQuotaService::class)->remaining($user->refresh()));
    }

    /**
     * 返金の逆引きに使う payment_intent を、通知に入っていないからといって null で潰さない。
     *
     * ⚠️ 2 回送るだけのテストでは**何も固定できない**。冪等ガード(`status !== Pending` で戻る)が
     *    先に効いて更新処理まで到達しないため、フォールバックを消しても緑のままになる
     *    (3 巡目のレビューで判明)。到達する形 —— **pending の行に既に payment_intent がある状態**
     *    —— を作って初めて、この分岐を検証できる。
     */
    public function test_payment_intent_is_not_overwritten_with_null(): void
    {
        // Arrange: まだ完了していないが、何らかの経路で payment_intent が入っている行
        [, $payment] = $this->makePendingPayment(quantity: 1);
        $payment->update(['stripe_payment_intent_id' => 'pi_test_already_known']);

        // Act: payment_intent を持たない完了通知が届く
        $this->postWebhook(
            'checkout.session.completed',
            $this->checkoutSession($payment, ['payment_intent' => null]),
        )->assertSuccessful();

        // Assert: 完了にはなるが、既存の payment_intent は残る
        //         (消えると以後の返金通知から購入行を引けなくなる)
        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame('pi_test_already_known', $payment->stripe_payment_intent_id);
    }

    /**
     * 通知された決済額が購入時点の控えと違う場合は加算しない。
     *
     * いまは price_data をサーバ側で組み立て、クーポンも数量変更も許可していないので
     * 金額がずれる経路は無いが、将来 Stripe ダッシュボードでクーポンを有効化した瞬間に
     * 「割引価格で買って満額の回数が入る」が成立する。お金に関わる突合はここで止める。
     */
    public function test_completed_webhook_with_mismatched_amount_does_not_add_quota(): void
    {
        // Arrange
        [$user, $payment] = $this->makePendingPayment(quantity: 3);

        // Act: 支払われた額が控えより少ない通知
        $this->postWebhook('checkout.session.completed', $this->checkoutSession($payment, [
            'amount_total' => $payment->amount - 1,
            'currency' => 'jpy',
        ]))->assertSuccessful();

        // Assert: 加算しない。購入記録も pending のまま(人が調べられる状態で残す)
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(0, app(MeetingQuotaService::class)->remaining($user->refresh()));
    }

    /**
     * 金額が読み取れない通知では加算しない(fail-closed)。
     *
     * 「確認できないときは通す」倒れ方にすると、金額を含まない通知を作れる経路が
     * そのまま迂回路になる。確認できないものは通さない。
     */
    public function test_completed_webhook_without_amount_does_not_add_quota(): void
    {
        // Arrange
        [$user, $payment] = $this->makePendingPayment(quantity: 3);

        // Act: amount_total が入っていない通知
        $this->postWebhook('checkout.session.completed', $this->checkoutSession($payment, [
            'amount_total' => null,
        ]))->assertSuccessful();

        // Assert
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(0, app(MeetingQuotaService::class)->remaining($user->refresh()));
    }

    /**
     * ⚠️ 通貨が違えば、金額の数値が一致していても加算しない。
     *
     * payments.amount は円建ての整数だが、Stripe の amount_total はその決済の通貨の最小単位。
     * JPY と KRW はどちらもゼロ小数通貨なので、3,000 KRW(≒330 円)と ¥3,000 が
     * **数値としては一致してしまう**。通貨まで見ないと安い通貨で回数を買えることになる。
     */
    public function test_completed_webhook_with_other_currency_does_not_add_quota(): void
    {
        // Arrange
        [$user, $payment] = $this->makePendingPayment(quantity: 3);

        // Act: 金額の数値は同じだが通貨が違う
        $this->postWebhook('checkout.session.completed', $this->checkoutSession($payment, [
            'currency' => 'krw',
        ]))->assertSuccessful();

        // Assert
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(0, app(MeetingQuotaService::class)->remaining($user->refresh()));
    }

    /**
     * ⚠️ 別の Checkout Session の完了通知を、この購入の完了として処理しない。
     *
     * client_reference_id は Stripe の Payment Link に URL クエリで任意の値を付けられるため、
     * これだけを信頼すると「自分の payments.id を付けた別の決済」で回数を得られてしまう。
     * 控えてある cs_... と一致しない通知は無視する。
     */
    public function test_completed_webhook_from_another_session_does_not_add_quota(): void
    {
        // Arrange: cs_... を控え済みの購入
        [$user, $payment] = $this->makePendingPayment(quantity: 3);

        // Act: client_reference_id はこの購入を指すが、Session ID は別物
        $this->postWebhook('checkout.session.completed', $this->checkoutSession($payment, [
            'id' => 'cs_test_someone_elses_session',
        ]))->assertSuccessful();

        // Assert
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(0, app(MeetingQuotaService::class)->remaining($user->refresh()));
    }

    /**
     * 逆に、cs_ の書き戻しに失敗して null のままの行は、この通知で自己修復できる。
     * (CheckoutCreateAction ④ の UPDATE が落ちた場合。拒否しすぎていないことの確認)
     */
    public function test_completed_webhook_backfills_session_id_when_missing(): void
    {
        // Arrange: cs_ が未設定の購入
        [$user, $payment] = $this->makePendingPayment(quantity: 3);
        $payment->update(['stripe_checkout_session_id' => null]);

        // Act
        $this->postWebhook('checkout.session.completed', $this->checkoutSession($payment, [
            'id' => 'cs_test_backfilled',
        ]))->assertSuccessful();

        // Assert: 加算され、cs_ も埋まる
        $payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame('cs_test_backfilled', $payment->stripe_checkout_session_id);
        $this->assertSame(3, app(MeetingQuotaService::class)->remaining($user->refresh()));
    }

    /**
     * 返金による取り消しの行が残数集計に含まれていることを、Service の側からも固定する。
     * MeetingQuotaService::remaining() の whereIn に新しい種別を足し忘れると
     * 「返金したのに残数が減らない」静かな不具合になるため、ここで機械的に止める。
     */
    public function test_payment_refunded_type_is_counted_in_remaining(): void
    {
        // Arrange: 台帳に直接 -1 の取り消し行だけを置く
        $user = User::factory()->student()->create(['max_meetings' => 5]);
        MeetingQuotaTransaction::create([
            'user_id' => $user->id,
            'type' => MeetingQuotaTransactionType::PaymentRefunded->value,
            'amount' => -1,
            'occurred_at' => now(),
        ]);

        // Assert: 5 - 1 = 4。集計から漏れていれば 5 のままになる
        $this->assertSame(4, app(MeetingQuotaService::class)->remaining($user));
    }
}
