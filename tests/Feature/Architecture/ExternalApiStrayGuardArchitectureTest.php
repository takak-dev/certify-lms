<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Exceptions\Payment\CheckoutSessionCreationFailedException;
use App\Models\AiChatConversation;
use App\Models\GoogleCredential;
use App\Models\MeetingPack;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Tests\Support\StrayGuardedStripeHttpClient;
use Tests\TestCase;

/**
 * 「モックしていない外部通信が起きたらテストが失敗する」見張り(tests/TestCase.php)そのものが
 * 効いていることを固定する(T-A-04 / decisions #258)。
 *
 * なぜ要るのか:
 *   見張りは、付け忘れたテストを**落とす**ための仕組み。見張りが黙って外れても、ほかのテストは全部緑のまま
 *   で誰も気づけない。たとえば次のどれかが起きると、見張りだけが静かに効かなくなる。
 *    - Google: 誰かが GoogleCalendarService::client() を private に戻す(子クラスの上書きが通らなくなる)
 *    - Stripe: tests/TestCase.php の ApiRequestor::setHttpClient() の行が消える
 *    - Gemini: Laravel の更新で、Http::preventStrayRequests() の例外の文言が変わる(文言で見分けているため)
 *    - 共通: TestCase::assertPostConditions() の「記録があれば落とす」処理が消える
 *   見張りの仕事は 3 つあるので、それぞれを確かめる。
 *    ① 記録する … わざとモックを付け忘れ、記録が残ることを確かめる
 *    ② 止める   … 本物へ通さず、見張り自身が止めたことを確かめる(包み直された例外の中身を見る)
 *    ③ 落とす   … 記録が残ったまま事後チェックを呼ぶと、テストが失敗することを確かめる
 *
 * ⚠️ 確かめ終わったら pullStrayExternalCalls() で記録を消している。消さないと、事後チェック
 *    (TestCase::assertPostConditions())がこのテスト自体を「付け忘れ」として落とす。
 *
 * ⚠️ Stripe と Gemini のテストは、鍵の確認を通り抜けて送信の直前まで進ませるために**ダミーの鍵を入れる**。
 *    見張りが壊れていてもダミーの鍵が本物へ出ないよう、それぞれ手前で塞いでいる(decisions #258)。
 *     - Stripe: 鍵を入れる前に、SDK の通信係が見張り役に差し替わっているかを確かめる(外れていれば送る前に落ちる)
 *     - Gemini: 送信先を、名前解決されないと規格で決められた .invalid のドメインに変える(RFC 6761)。
 *       Laravel 10 には preventStrayRequests() が効いているかを読む手段が無いため、Stripe と同じ事前確認はできない
 *    Google は鍵を入れずに確かめられるので入れない(見張りが外れても親の「設定がされていません」で止まる)。
 *
 * ⚠️ #[Group('external')] は付けない(decisions #258)。外部連携のテストを除外して流したときにも、
 *    見張りが生きていることは確かめ続けたいため。代わりに --group=external だけで流したときは走らない。
 */
class ExternalApiStrayGuardArchitectureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 見張り役が投げる例外の文言(StrayGuardedGoogleCalendarService / StrayGuardedStripeHttpClient)に共通して入る語。
     * 「見張り自身が止めた」ことを、本物の通信失敗や設定エラーと見分けるために使う。
     */
    private const GUARD_MARK = 'T-A-04 の見張り役が止めました';

    /**
     * Gemini の自己テストで使う送信先。.invalid は「どこにも名前解決されない」と規格で決められたドメイン(RFC 6761)。
     */
    private const UNREACHABLE_HOST = 'guard-test.invalid';

    /**
     * Google で付け忘れを起こす: モックを付けずに、コンテナから受け取った Service で予定の作成を呼ぶ。
     *
     * ⚠️ 鍵は入れない。見張りが効いていれば見張りが止め、見張りが外れていれば親の client() が
     *    「設定がされていません」で止める。どちらでも本物へは出ない。
     *
     * @return RuntimeException createEvent() が包み直して投げた例外
     */
    private function causeStrayGoogleCall(): RuntimeException
    {
        $credential = GoogleCredential::factory()->create();

        try {
            app(GoogleCalendarService::class)->createEvent($credential, [
                'summary' => '件名',
                'description' => '説明',
                'location' => 'https://meet.example.com/room',
                'starts_at' => Carbon::parse('2026-10-05 10:00'),
                'ends_at' => Carbon::parse('2026-10-05 11:00'),
            ]);
        } catch (RuntimeException $e) {
            return $e;
        }

        $this->fail('例外が投げられていない');
    }

    /**
     * Stripe で付け忘れを起こす: 鍵を入れ、モックを付けずに決済画面の作成を呼ぶ。
     *
     * @return CheckoutSessionCreationFailedException StripeService が包み直して投げた例外
     */
    private function causeStrayStripeRequest(): CheckoutSessionCreationFailedException
    {
        // 見張りが外れていたら、ダミーの鍵を入れる前にここで落とす(外れたまま送ると本物の Stripe へ届く)
        $this->assertInstanceOf(StrayGuardedStripeHttpClient::class, ApiRequestor::httpClient());

        config(['services.stripe.secret' => 'sk_test_dummy_for_guard_test']);
        $pack = MeetingPack::factory()->published()->create();

        try {
            app(StripeService::class)->createCheckoutSession(
                $pack,
                'dummy-client-reference-id',
                'http://localhost/dummy-success',
                'http://localhost/dummy-cancel',
            );
        } catch (CheckoutSessionCreationFailedException $e) {
            return $e;
        }

        $this->fail('例外が投げられていない');
    }

    /**
     * Gemini で付け忘れを起こす: 鍵を入れ、Http::fake() を付けずに画面(HTTP)経由で送信する。
     */
    private function causeStrayHttpRequest(): void
    {
        // 送信先を名前解決されないドメインにする。見張りが外れていても、ダミーの鍵は本物の Gemini へ届かない
        config([
            'services.gemini.api_key' => 'dummy-key-for-guard-test',
            'ai-chat.gemini.endpoint' => 'https://'.self::UNREACHABLE_HOST.'/v1beta',
        ]);
        $student = User::factory()->student()->inProgress()->create();
        $conversation = AiChatConversation::factory()->create(['user_id' => $student->id]);

        $response = $this->actingAs($student)->postJson(
            route('ai-chat.conversations.messages.store', $conversation),
            ['content' => '質問です'],
        );

        // 画面の応答は 500(見張り番の例外は Laravel の例外係に 500 へ変えられ、テストまで届かない)
        $response->assertStatus(500);
    }

    /**
     * Google: 付け忘れを記録し(①)、本物へ通さずに見張り自身が止める(②)。
     */
    public function test_google_guard_records_and_stops_an_unmocked_call(): void
    {
        // Act
        $e = $this->causeStrayGoogleCall();

        // Assert ②: createEvent() が包み直した例外の中身が、見張り自身の例外である
        //   (記録だけして親の client() に通すように壊すと、ここが親の「設定がされていません」になって落ちる)
        $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        $this->assertStringContainsString(self::GUARD_MARK, $e->getPrevious()->getMessage());

        // Assert ①: どの操作から本物の入口に届いたかが記録され、ほかの見張りには何も残っていない
        $this->assertSame(
            ['google' => ['createEvent'], 'stripe' => [], 'http' => []],
            $this->pullStrayExternalCalls(),
        );
    }

    /**
     * Google: 通信しない createAuthUrl() は記録しない(decisions #250 の除外が効いている)。
     *
     * ⚠️ これが崩れると、本物の URL を確かめている GoogleCalendarTest の redirect のテストが誤って落ちる。
     */
    public function test_google_guard_ignores_create_auth_url(): void
    {
        // Arrange: URL を組み立てるには鍵が要る(このメソッドは通信しないので、鍵を入れても外へは出ない)
        config([
            'services.google.client_id' => 'dummy-client-id',
            'services.google.client_secret' => 'dummy-client-secret',
            'services.google.redirect_uri' => 'http://localhost/dummy-callback',
        ]);

        // Act
        $url = app(GoogleCalendarService::class)->createAuthUrl('dummy-state');

        // Assert: 本物の URL が返り、どの見張りにも記録は残らない
        $this->assertStringContainsString('accounts.google.com', $url);
        $this->assertSame(['google' => [], 'stripe' => [], 'http' => []], $this->pullStrayExternalCalls());
    }

    /**
     * Stripe: SDK の出口で送信先を記録し(①)、本物へ通さずに見張り自身が止める(②)。
     */
    public function test_stripe_guard_records_and_stops_an_unmocked_request(): void
    {
        // Act
        $e = $this->causeStrayStripeRequest();

        // Assert ②: StripeService が包み直した例外の中身が、見張り自身の例外である
        //   (記録だけして本物の通信係に渡すように壊すと、ここが本物の接続エラーになって落ちる)
        $this->assertInstanceOf(ApiConnectionException::class, $e->getPrevious());
        $this->assertStringContainsString(self::GUARD_MARK, $e->getPrevious()->getMessage());

        // Assert ①: 送ろうとした先が記録され、ほかの見張りには何も残っていない
        $this->assertSame(
            ['google' => [], 'stripe' => ['POST https://api.stripe.com/v1/checkout/sessions'], 'http' => []],
            $this->pullStrayExternalCalls(),
        );
    }

    /**
     * Gemini: 画面(HTTP)経由で fake を付けずに送信すると、応答は 500 に変わるが、見張りの記録は残る(①)。
     *
     * ⭐ この経路が decisions #257 の理由。例外が 500 の応答に変えられてテストまで届かないため、
     *    例外係への報告の段階で記録している。止める(②)のは Laravel 標準の Http::preventStrayRequests()。
     */
    public function test_http_guard_records_a_stray_request_even_when_it_becomes_a_500(): void
    {
        // Act
        $this->causeStrayHttpRequest();

        // Assert ①: 見張り番が止めた送信が 1 件記録され、ほかの見張りには何も残っていない
        $calls = $this->pullStrayExternalCalls();
        $this->assertSame([], $calls['google']);
        $this->assertSame([], $calls['stripe']);
        $this->assertCount(1, $calls['http']);
        $this->assertStringContainsString(self::UNREACHABLE_HOST, $calls['http'][0]);
    }

    /**
     * 記録が残ったまま事後チェックを呼ぶと、テストが失敗する(③)。3 つの見張りそれぞれについて確かめる。
     *
     * ⚠️ 事後チェックは本来 PHPUnit がテストの後に呼ぶもの。ここではわざと自分で呼び、
     *    「落とす」処理が消えていないこと・失敗メッセージに手がかり(操作名 / 送信先)が出ることを固定する。
     */
    public function test_post_conditions_fail_the_test_while_any_record_remains(): void
    {
        // ---- Google ----
        // Arrange: 付け忘れを起こして記録を残す
        $this->causeStrayGoogleCall();
        // Act & Assert: 事後チェックが失敗し、どの操作から届いたかが出る
        $this->assertPostConditionsFailWith('到達した操作: createEvent');
        // 後片付け: 記録を消す(次の見張りを 1 種類ずつ確かめるため)
        $this->pullStrayExternalCalls();

        // ---- Stripe ----
        // Arrange
        $this->causeStrayStripeRequest();
        // Act & Assert: 送ろうとした先が出る
        $this->assertPostConditionsFailWith('送ろうとした先: POST https://api.stripe.com/v1/checkout/sessions');
        // 後片付け
        $this->pullStrayExternalCalls();

        // ---- Gemini(Http ファサード)----
        // Arrange
        $this->causeStrayHttpRequest();
        // Act & Assert: 止めた送信の送信先が出る
        $this->assertPostConditionsFailWith('止めた送信: Attempted request to [https://'.self::UNREACHABLE_HOST.'/');
        // 後片付け(消さないと、PHPUnit が本来呼ぶ事後チェックでこのテスト自体が落ちる)
        $this->pullStrayExternalCalls();
    }

    /**
     * 事後チェックを呼び、失敗すること・失敗メッセージに $expected が入っていることを確かめる。
     */
    private function assertPostConditionsFailWith(string $expected): void
    {
        try {
            $this->assertPostConditions();
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString($expected, $e->getMessage());

            return;
        }

        $this->fail('記録が残っているのに、事後チェックが失敗しなかった');
    }
}
