<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\Payment\CheckoutSessionCreationFailedException;
use App\Exceptions\Payment\InvalidWebhookSignatureException;
use App\Models\MeetingPack;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * 追加面談パックの決済(S-A-03)で Stripe とやり取りする部分をまとめて受け持つ Service。
 *
 * このクラスだけが stripe/stripe-php に触る。Controller / Action は、ここが返す素の配列だけを
 * 見るようにしてライブラリの都合を外へ漏らさない
 * (手本: app/Services/GoogleCalendarService.php / app/Services/CertificatePdfService.php)。
 *
 * ⚠️ 意図的に final にしていない。テストで Stripe への通信を差し替える必要があるため
 *    (Mockery は final クラスをモックできない)。外部 API のモックテストの本格化は T-A-04。
 */
class StripeService
{
    /**
     * Stripe の設定が入っているか。未設定の環境では購入導線を動かせない。
     *
     * 手本の GoogleCalendarService::isConfigured() と同じ考え方だが、あちらと違い
     * 「未設定でも既存機能は動く」類のものではない(決済は設定が無いと成立しない)。
     */
    public function isConfigured(): bool
    {
        return filled(config('services.stripe.secret'))
            && filled(config('services.stripe.webhook_secret'));
    }

    /**
     * Stripe の決済画面(Checkout Session)を作り、その ID と遷移先 URL を返す。
     *
     * ⚠️ 商品(Price)を Stripe に事前登録せず、price_data でその場で組み立てる(decisions #149)。
     *    支給コードもこの前提で書かれている(MeetingPack.php:19-20、
     *    meeting-pack/management/show.blade.php:188 の「— (動的生成)」表示)。
     *
     * ⚠️ payment_method_types を指定しない(decisions #213)。Stripe ダッシュボードで
     *    有効化した決済手段(カード / Apple Pay / Google Pay 等)がそのまま使われる。
     *
     * @param string $clientReferenceId こちらの payments.id(ULID)。Webhook から購入行を引くための目印
     *
     * @return array{id: string, url: string}
     *
     * @throws CheckoutSessionCreationFailedException 設定不備 / Stripe 側の障害で Session を作れなかった場合
     */
    public function createCheckoutSession(
        MeetingPack $pack,
        string $clientReferenceId,
        string $successUrl,
        string $cancelUrl,
    ): array {
        // 設定が無い状態で呼ぶと、空のキーで Stripe に接続しにいって分かりにくい失敗になる。
        // 「いま買えない」という同じ結末なので、手前で同じ例外に倒す。
        if (! $this->isConfigured()) {
            throw new CheckoutSessionCreationFailedException;
        }

        try {
            $session = $this->client()->checkout->sessions->create([
                'mode' => 'payment',            // 都度購入。サブスクリプションは原典 スコープ外
                'client_reference_id' => $clientReferenceId,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'line_items' => [
                    [
                        'price_data' => [
                            // 円のみ(原典 非機能要件)。JPY は最小単位が 1 円なので、
                            // unit_amount に価格をそのまま渡す(USD のような 100 倍は不要)。
                            'currency' => 'jpy',
                            'unit_amount' => $pack->price,
                            'product_data' => [
                                'name' => $pack->name,
                            ],
                        ],
                        // ⚠️ ここの quantity は「パックを何個買うか」で常に 1。
                        //    payments.quantity(面談の回数)とは別物。まとめ買いは原典 スコープ外。
                        'quantity' => 1,
                    ],
                ],
                // 購入時点の控えを Stripe 側にも残す。返金などで Stripe の管理画面から
                // 追跡するときの手がかりになる(アプリ側の正本は payments テーブル)。
                'metadata' => [
                    'meeting_pack_id' => $pack->id,
                    'meeting_count' => (string) $pack->meeting_count,
                ],
            ]);
        } catch (ApiErrorException $e) {
            // stripe-php の例外をドメインの例外に包み替え、外へライブラリを漏らさない。
            // ApiErrorException は Stripe の API 由来の失敗(認証エラー / レート制限 / 障害)の親クラス。
            throw new CheckoutSessionCreationFailedException($e);
        }

        return [
            'id' => (string) $session->id,
            'url' => (string) $session->url,
        ];
    }

    /**
     * Webhook の署名を検証し、検証済みのイベントを素の配列で返す。
     *
     * ⚠️ $payload は「Laravel が一切加工していない生のリクエストボディ」でなければならない。
     *    署名は届いたバイト列そのものに対する HMAC-SHA256 なので、JSON を配列に直して
     *    組み立て直すと空白・キー順・エスケープの違いで別物になり、正規の通知まで弾かれる。
     *
     * @return array{id: string, type: string, object: array<string, mixed>}
     *
     * @throws InvalidWebhookSignatureException 署名が合わない / ボディが JSON として壊れている場合
     */
    public function verifyWebhook(string $payload, ?string $signature): array
    {
        // ⚠️ 鍵が空でも HMAC は計算できてしまう(vendor/stripe/stripe-php/lib/WebhookSignature.php:139)。
        //    空のまま検証に進むと「誰でも正しい署名を作れる」状態になり、認証なしの公開窓口が
        //    そのまま残数を増やす入口に変わる。設定漏れは安全側(拒否)に倒す。
        if (blank(config('services.stripe.webhook_secret'))) {
            throw new InvalidWebhookSignatureException;
        }

        try {
            $event = Webhook::constructEvent(
                $payload,
                (string) $signature,
                (string) config('services.stripe.webhook_secret'),
            );
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            // ライブラリの例外をドメインの例外に包み替えて、外に stripe-php を漏らさない。
            throw new InvalidWebhookSignatureException($e);
        }

        return [
            'id' => (string) $event->id,        // evt_... 。イベント自体の識別子
            'type' => (string) $event->type,    // checkout.session.completed など
            // 通知の主役(Checkout Session / Charge など)。配列にして返すことで、
            // 呼び出し側のテストが Stripe のオブジェクトを組み立てずに済む。
            'object' => $event->data->object->toArray(),
        ];
    }

    /**
     * Stripe API のクライアント。設定値は呼ばれた時点で読む(手本: GoogleCalendarService::client())。
     */
    protected function client(): StripeClient
    {
        return new StripeClient((string) config('services.stripe.secret'));
    }
}
