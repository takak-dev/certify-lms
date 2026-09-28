<?php

declare(strict_types=1);

namespace Tests\Support;

use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;

/**
 * Stripe SDK の通信係の代役。本当に Stripe へ送ろうとした瞬間を記録する見張り役(T-A-04 / decisions #251)。
 *
 * なぜここに立てるのか:
 *   Stripe SDK は Laravel の Http ファサードを通らず curl で直接通信する(HttpClient\CurlClient)ので、
 *   Http::preventStrayRequests() が効かない。一方で SDK 自身が「通信係を差し替える口」
 *   (Stripe\ApiRequestor::setHttpClient())を用意しているので、通信の出口そのものに見張りを置ける。
 *
 * なぜ StripeService の入口(createCheckoutSession())ではないのか:
 *   CheckoutTest の test_checkout_returns_conflict_when_stripe_is_not_configured が、わざとモックを
 *   付けずに「鍵が空なら通信せずに引き返す」道を確かめている。入口で記録するとこれを誤って落とす。
 *   出口なら「実際に送ろうとしたとき」だけ反応するので、通信しないテストは巻き込まない。
 *
 * ⚠️ 普段のテストは phpunit.xml で STRIPE_SECRET が空なので、ここまで来ない。
 *    反応するのは「テストの中で鍵を埋め、モックを付け忘れた」ときだけ。
 *
 * ⚠️ 見張りの対象外: ストリーミング用の通信係(ApiRequestor::setStreamingHttpClient()。ファイルのダウンロードなどで使う)。
 *    いまの StripeService は Checkout Session の作成と Webhook の署名検証しか使わないので通らない。
 *    ストリーミングの API を使い始めたら、そちらにも見張りを差し込むこと。
 */
class StrayGuardedStripeHttpClient implements ClientInterface
{
    /**
     * 送ろうとした先を積む。空なら「一度も送ろうとしていない」。
     *
     * @var list<string>
     */
    private array $strayRequests = [];

    /**
     * SDK が Stripe へ送る直前に呼ぶメソッド。本物(CurlClient)の代わりに、記録してから失敗させる。
     *
     * 失敗させる例外は ApiConnectionException(Stripe の「接続できなかった」)。本物の通信失敗と同じ形にして、
     * アプリ側の扱い(StripeService が CheckoutSessionCreationFailedException に包み替える)を変えないため。
     * 例外が握りつぶされても記録は残るので、tests/TestCase.php の assertPostConditions() で落とせる。
     *
     * ⚠️ 引数に型を書かないのは、SDK のインターフェース(ClientInterface::request())が型なしで宣言しているため。
     *    実装側で string などに絞ると、PHP が「宣言と合わない」として読み込みを拒否する。
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->strayRequests[] = strtoupper((string) $method).' '.$absUrl;

        throw new ApiConnectionException('テスト中に Stripe への実通信が試みられました(T-A-04 の見張り役が止めました)。');
    }

    /**
     * 記録を返す。
     *
     * @return list<string>
     */
    public function strayRequests(): array
    {
        return $this->strayRequests;
    }

    /**
     * 記録を返して消す(StrayGuardedGoogleCalendarService::pullStrayCalls() と同じ理由)。
     *
     * @return list<string>
     */
    public function pullStrayRequests(): array
    {
        $requests = $this->strayRequests;
        $this->strayRequests = [];

        return $requests;
    }
}
