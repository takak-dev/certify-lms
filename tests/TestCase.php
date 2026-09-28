<?php

declare(strict_types=1);

namespace Tests;

use App\Exceptions\Handler;
use App\Services\GoogleCalendarService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Stripe\ApiRequestor;
use Tests\Feature\Architecture\ExternalApiStrayGuardArchitectureTest;
use Tests\Support\StrayGuardedGoogleCalendarService;
use Tests\Support\StrayGuardedStripeHttpClient;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Google の見張り役。本物の通信の入口に届いたら記録する(T-A-04 / decisions #249)。
     */
    private StrayGuardedGoogleCalendarService $googleStrayGuard;

    /**
     * Stripe の見張り役。SDK が本当に送ろうとしたら記録する(T-A-04 / decisions #251)。
     */
    private StrayGuardedStripeHttpClient $stripeStrayGuard;

    /**
     * Gemini(Http ファサード)の見張り番が止めた送信先。止められたら記録する(T-A-04 / decisions #257)。
     *
     * @var list<string>
     */
    private array $strayHttpRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        // アプリが GoogleCalendarService を求めたら、この見張り役を渡すように登録する。
        // instance() は「このクラスを求められたら、この 1 つを渡す」という Laravel コンテナへの登録。
        // テストが $this->mock(GoogleCalendarService::class, ...) を書くと、その登録で上書きされる
        // (= モックを付けたテストでは見張りは使われず、記録も残らない)。
        $this->googleStrayGuard = new StrayGuardedGoogleCalendarService;
        $this->app->instance(GoogleCalendarService::class, $this->googleStrayGuard);

        // Stripe は Google と違い、SDK の通信係そのものを差し替える(出口で見張る)。
        // ⚠️ setHttpClient() は static(PHP プロセス全体で 1 つ共有)なので、前のテストの記録を
        //    持ち越さないよう、テストごとに新しい見張り役で置き直す。
        $this->stripeStrayGuard = new StrayGuardedStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripeStrayGuard);

        // Gemini は Laravel の Http ファサードで通信するので、Laravel 標準の歯止めを使う(decisions #252)。
        // Http::fake() で偽の応答を用意していない送信は、通信せずに RuntimeException で止まる。
        Http::preventStrayRequests();

        // ⚠️ 例外だけでは足りない(decisions #257)。Service や Action を直接呼ぶテストなら例外がそのまま
        //    テストを落とすが、画面(HTTP)経由のテストでは Laravel の例外係が 500 の応答に変えてしまい、
        //    テストまで届かない(実測: 鍵を入れて fake 無しで送信 → status 500 で緑)。
        //    そこで Google / Stripe と同じく「記録 → 事後チェックで落とす」にそろえる。
        //    reportable() は「この種類の例外が報告されたら、この処理も呼ぶ」という例外係への登録
        //    (app/Exceptions/Handler.php の register() と同じ仕組み)。500 に変える前に必ず報告されるので、ここで捕まる。
        // ⚠️ 見張り番の例外は種類が RuntimeException で見分けがつかないため、**文言で**見分ける
        //    (vendor/laravel/framework/src/Illuminate/Http/Client/PendingRequest.php:1253 の
        //    'Attempted request to [...] without a matching fake.')。Laravel の更新で文言が変わると効かなくなる。
        // ⚠️ reportable() は約束(ExceptionHandler インターフェース)には無く、実際に登録されている
        //    App\Exceptions\Handler(Laravel の Handler を継承)が持つメソッド。型を明示しておく。
        //    App\Exceptions\Handler::class で取り出さないのは、コンテナに登録されている名前が
        //    ExceptionHandler のほうで(bootstrap/app.php)、別名で取り出すとアプリが使うのとは
        //    別の例外係が新しく作られ、そこに登録しても効かないおそれがあるため。
        /** @var Handler $handler */
        $handler = $this->app->make(ExceptionHandler::class);
        $handler->reportable(function (RuntimeException $e): void {
            if (str_starts_with($e->getMessage(), 'Attempted request to [')
                && str_ends_with($e->getMessage(), '] without a matching fake.')) {
                $this->strayHttpRequests[] = $e->getMessage();
            }
        });
    }

    /**
     * 3 つの見張りの記録を取り出して消す(T-A-04 / decisions #258)。
     *
     * 見張りそのものが効いていることを確かめるテスト(tests/Feature/Architecture/ExternalApiStrayGuardArchitectureTest.php)
     * だけが使う。わざと付け忘れて記録を残し、ここで取り出して確かめる。消さないと事後チェックでそのテスト自体が落ちる。
     *
     * @return array{google: list<string>, stripe: list<string>, http: list<string>}
     */
    protected function pullStrayExternalCalls(): array
    {
        // ⚠️ 自己テスト以外から呼ばせない。ふつうのテストで呼ぶと、付け忘れの記録を消して検知を黙らせられてしまう。
        if (static::class !== ExternalApiStrayGuardArchitectureTest::class) {
            $this->fail('pullStrayExternalCalls() は見張りの自己テスト(ExternalApiStrayGuardArchitectureTest)専用です。');
        }

        $http = $this->strayHttpRequests;
        $this->strayHttpRequests = [];

        return [
            'google' => $this->googleStrayGuard->pullStrayCalls(),
            'stripe' => $this->stripeStrayGuard->pullStrayRequests(),
            'http' => $http,
        ];
    }

    /**
     * 全テスト共通の事後チェック。PHPUnit がテスト本体の直後(tearDown の前)に呼ぶ。
     *
     * 見張り役に記録が残っていれば「モックを付け忘れて本物の入口まで届いた」ので落とす。
     * ⚠️ 例外で知らせない理由は StrayGuardedGoogleCalendarService の docblock を参照
     *    (アプリ側の catch に飲み込まれて緑のまま通るため)。
     *
     * ⚠️ assertSame などで確かめない。ここでの確認もテストの「確認の数」に足されるため、
     *    確認を 1 つも書いていないテストに PHPUnit が出す警告(risky)が、全テストで出なくなる(実測)。
     *    fail() は失敗するときしか数に入らないので、記録があるときだけ呼ぶ(decisions #254)。
     */
    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();

        if ($this->googleStrayGuard->strayCalls() !== []) {
            $this->fail(
                'GoogleCalendarService のモックが付いていないため、本物の Google 通信の入口(client())まで到達しました。'
                .' $this->mock(GoogleCalendarService::class, ...) で差し替えてください。'
                .' 到達した操作: '.implode(', ', $this->googleStrayGuard->strayCalls()),
            );
        }

        if ($this->strayHttpRequests !== []) {
            $this->fail(
                'テスト中に Http ファサード経由の実通信(Gemini など)が試みられ、画面の応答では 500 に変わっていました。'
                .' Http::fake() か $this->mock(GeminiService::class, ...) で差し替えてください。'
                .' 止めた送信: '.implode(', ', $this->strayHttpRequests),
            );
        }

        if ($this->stripeStrayGuard->strayRequests() !== []) {
            $this->fail(
                'テスト中に Stripe への実通信が試みられました。'
                .' $this->mock(StripeService::class, ...) で差し替えてください。'
                .' 送ろうとした先: '.implode(', ', $this->stripeStrayGuard->strayRequests()),
            );
        }
    }
}
