<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\GoogleCalendarService;
use Google\Client as GoogleClient;
use ReflectionMethod;
use RuntimeException;

/**
 * 「モックを付け忘れて、本物の Google 通信の入口まで届いた」ことを記録し、そこで止める見張り役
 * (T-A-04 / decisions #249・#254)。
 *
 * なぜ要るのか:
 *   Google は Laravel の Http ファサードを通らない(Guzzle を直接使う)ので、Http::preventStrayRequests() が効かない。
 *   さらに phpunit.xml でキーを空にしているため本物の client() は例外を投げるが、呼び出し側
 *   (SyncMeetingAction / MeetingAvailabilityService など)が「Google が失敗しても面談は止めない」ために
 *   例外を握りつぶす。結果、付け忘れたテストが緑のまま通ってしまう。
 *
 * どう防ぐか:
 *   例外ではなく「記録」で知らせる。記録は catch に飲み込まれないので、
 *   テスト本体の直後に tests/TestCase.php の assertPostConditions() が読んで、あればテストを失敗させる。
 *
 * ⚠️ 記録したら親の client() は呼ばずに RuntimeException で止める(decisions #254 で #249 から変更)。
 *    親を呼ぶと、テストの中でダミーの鍵を入れた場合(GoogleCalendarTest の configureGoogle() など)に
 *    本物の Google クライアントが組み上がり、**通信が実際に出てから**テストが落ちる。
 *    例外の型は鍵が空のときに親が投げるものと同じなので、アプリ側の扱い(catch してフォールバック)は変わらない。
 *
 * 見張りの対象外(ここを通らないもの):
 *  - テストの中で new GoogleCalendarService と直接作ったもの(コンテナを経由しない)。
 *    そうするテストは、鍵を空のままにするか、client() / calendarFor() / fetchRefreshedToken() を自分で差し替えること。
 *  - アプリ側は GoogleCalendarService をすべてコンストラクタで受け取っているので、アプリの経路は全部ここを通る。
 */
class StrayGuardedGoogleCalendarService extends GoogleCalendarService
{
    /**
     * client() を使うが通信はしない操作。ここから届いた分は記録しない(decisions #250)。
     *
     * createAuthUrl(): 同意画面へ飛ぶ URL を組み立てるだけ。SDK の中で
     *   buildFullAuthorizationUri() が文字列を作って返し、Google へは何も送らない
     *   (vendor/google/apiclient/src/Client.php の createAuthUrl())。
     *   tests/Feature/Http/Settings/GoogleCalendarTest.php の redirect のテストは、利用者に求める権限の
     *   範囲を固定するために、わざと本物の URL を確かめている(モックを付けるとその確認ができない)。
     * ⚠️ SDK の更新などで createAuthUrl() が通信するようになったら、ここから外すこと。
     */
    private const NO_NETWORK_OPERATIONS = ['createAuthUrl'];

    /**
     * 本物の入口に届いた回数ぶん、「どの操作から届いたか」を積む。
     *
     * @var list<string>
     */
    private array $strayCalls = [];

    /**
     * 親の client() を上書きする。Google への本物の通信は必ずここを通る
     * (予定の作成・削除・空き取得 / トークン更新 / 認可コード交換)。
     */
    protected function client(): GoogleClient
    {
        $calledFrom = $this->calledFrom();

        // 通信しない操作だけは本物を組み立てて返す(本物の URL を確かめるテストがあるため)
        if (in_array($calledFrom, self::NO_NETWORK_OPERATIONS, true)) {
            return parent::client();
        }

        $this->strayCalls[] = $calledFrom;

        throw new RuntimeException('テスト中に Google への実通信が試みられました(T-A-04 の見張り役が止めました)。');
    }

    /**
     * 記録を返す。空なら「本物の入口には一度も届いていない」。
     *
     * @return list<string>
     */
    public function strayCalls(): array
    {
        return $this->strayCalls;
    }

    /**
     * 記録を返して消す。見張り役そのものを確かめるテスト(ExternalApiStrayGuardArchitectureTest)が、
     * 「わざと付け忘れて記録が残った」ことを確かめたあと、事後チェックで自分が落ちないようにするため。
     *
     * @return list<string>
     */
    public function pullStrayCalls(): array
    {
        $calls = $this->strayCalls;
        $this->strayCalls = [];

        return $calls;
    }

    /**
     * どの public 操作(createEvent など)から client() に届いたかを調べる。
     * 失敗メッセージに出して、どこでモックが漏れたかを探しやすくするため。
     *
     * debug_backtrace() は「いまの関数が、どこから順に呼ばれてきたか」の一覧を返す PHP の関数。
     * 呼び出しの連なりを外側へ辿り、GoogleCalendarService の public メソッドを見つけたらその名前を返す。
     */
    private function calledFrom(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            // 'class' は「そのメソッドが書かれているクラス」。親のメソッドなら GoogleCalendarService になる
            if (($frame['class'] ?? null) !== GoogleCalendarService::class) {
                continue;
            }

            // 無名関数の中から届いた場合、名前は '{closure}' になり、そのままでは ReflectionMethod が例外を投げる。
            // その例外はアプリ側の catch (Throwable) に飲まれ、見張りが黙って空振りするので、先に弾いておく。
            if (! method_exists(GoogleCalendarService::class, $frame['function'])) {
                continue;
            }

            // ReflectionMethod はメソッドの情報(public / private など)を調べるための PHP 標準クラス
            if ((new ReflectionMethod(GoogleCalendarService::class, $frame['function']))->isPublic()) {
                return $frame['function'];
            }
        }

        return 'client';
    }
}
