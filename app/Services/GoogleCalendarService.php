<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GoogleCredential;
use Carbon\Carbon;
use Google\Client as GoogleClient;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\FreeBusyRequest;
use Google\Service\Calendar\FreeBusyRequestItem;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Client as GuzzleClient;
use RuntimeException;

/**
 * Google カレンダー連携(S-A-01)の外部通信をまとめて受け持つ Service。
 *
 * このクラスだけが google/apiclient に触れる。Controller / Action / 他の Service は
 * ここが返す素の配列や Carbon だけを見るようにして、ライブラリの都合を外へ漏らさない
 * (手本: app/Services/CertificatePdfService.php が mpdf を 1 クラスに閉じ込めているのと同じ考え方)。
 *
 * ⚠️ このクラスのメソッドは失敗時に例外を投げる。呼び出し側が try-catch でフォールバックする
 *    責任を持つ(原典 共通の振る舞い「Google との通信に失敗しても、空き枠の表示・面談の予約・
 *    面談のキャンセルといった面談機能の根幹は止まらない」)。
 *    ここで握り潰すと「連携したのに何も起きない」理由が分からなくなるので、握るのは呼び出し側。
 *
 * ⚠️ 意図的に final にしていない。テストで Google への通信を差し替える必要があるため
 *    (Mockery は final クラスをモックできない)。「予定が入っている」「Google が落ちている」を
 *    再現できないと、原典が要求するフォールバックの検証ができない。
 *    手本にした CertificatePdfService も同じ理由で final ではない
 *    (tests/Feature/UseCases/Certificate/IssueActionTest.php:127 が $this->mock() で差し替えている)。
 *    外部 API のモックテストを本格化するのは T-A-04。
 *
 * ⚠️ 時刻の型は Carbon\Carbon で受ける(Illuminate\Support\Carbon ではない)。
 *    Illuminate 版は Carbon\Carbon を継承しているので両方受け取れるが、逆は通らない。
 *    呼び出し元の MeetingAvailabilityService が Carbon\Carbon を使っており、
 *    ここを Illuminate 版にしていたため TypeError が出ていた(テストで検出)。
 */
class GoogleCalendarService
{
    /**
     * 要求するスコープ。**必要最小限の 2 本**に絞っている。
     *
     * - CALENDAR_EVENTS   : 面談予定の作成・削除に必要(events.insert / events.delete の要求スコープ)
     * - CALENDAR_FREEBUSY : 予定の「有無」だけを問い合わせるのに必要(freebusy.query の要求スコープ)
     *
     * ⚠️ 広い Calendar::CALENDAR を使えば 1 本で足りるが、あちらは
     *    「See, edit, share, and permanently delete all the calendars you can access」——
     *    **カレンダーそのものの共有設定と完全削除まで**含む(ライブラリの定数コメント
     *    vendor/google/apiclient-services/src/Calendar.php:37)。こちらが必要なのは
     *    予定の作成・削除(:61 「View and edit events on all your calendars」)と
     *    空き状況の取得(:80)だけなので、用途の狭い 2 本に絞る。
     *
     * ⚠️ **calendar.events の時点で予定の中身を読む権限は含まれる**(上記のとおり「View and edit」)。
     *    「スコープを絞ったから中身が読めない」わけではない —— **読まないのは実装の側**で、
     *    このクラスは予定の取得 API を一度も呼ばない(呼ぶのは freebusy / insert / delete だけ)。
     *    さらに絞るなら calendar.events.owned(:67 自分が所有するカレンダーに限定)という選択肢もある。
     *
     * @var array<int, string>
     */
    public const SCOPES = [
        Calendar::CALENDAR_EVENTS,
        Calendar::CALENDAR_FREEBUSY,
    ];

    /**
     * 連携先カレンダーの ID。Google API では 'primary' が「そのアカウントの既定カレンダー」を指す予約語。
     *
     * 原典 スコープ外「連携カレンダーの選択 UI — プライマリカレンダー固定」により、
     * このプロジェクトでは常にこの値を使う。将来カレンダーを選ばせるなら
     * google_credentials.calendar_id に別の値が入るので、列は残したまま定数の利用箇所だけを減らせばよい。
     */
    public const PRIMARY_CALENDAR_ID = 'primary';

    /**
     * アクセストークンを「期限の何秒前」から更新しにいくか。
     *
     * 期限ちょうどを境にすると、判定を通った直後に切れて 401 になる取りこぼしが出る。
     * 少し早めに更新しても失うものは無い(Google 側で古いトークンが無効になるだけ)。
     */
    private const REFRESH_MARGIN_SECONDS = 60;

    /**
     * Google への 1 リクエストを何秒まで待つか。
     *
     * ⚠️ ライブラリの既定は **無制限**(vendor/google/apiclient/src/Client.php:1261-1265 は
     *    base_uri と http_errors しか渡しておらず、Guzzle の timeout 既定は 0)。
     *    Google 側がハングすると、こちらのリクエストもそのまま止まる。
     *    原典の「通信に失敗しても面談機能の根幹は止まらない」は **エラー** には効くが
     *    **ハング** には効かないので、待ち時間の上限を明示して必ず例外に落とす。
     */
    private const HTTP_TIMEOUT_SECONDS = 5;

    /** 接続確立までの上限(秒)。応答待ちより短くする。 */
    private const HTTP_CONNECT_TIMEOUT_SECONDS = 2;

    /**
     * .env に OAuth クライアント情報が入っているか。
     *
     * 未設定の環境でもルートは登録されている(= 支給 Blade の連携カードは出る)ため、
     * 押したときに 500 にせず「設定されていません」と案内できるように判定手段を用意する。
     * 要件シート「利用には各自でのキー取得・.env 設定が必要」を踏まえた作り。
     */
    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect_uri'));
    }

    /**
     * Google の同意画面へ送るための認可 URL を組み立てる。
     *
     * @param string $state なりすまし検証用のランダム文字列。Google はこの値をそのまま
     *                      callback のクエリに返すので、呼び出し側がセッションの値と突き合わせる
     *                      (原典 共通の振る舞い「連携処理が正規の本人によるものか検証し、
     *                      なりすましや不正な連携を拒否する」)。
     */
    public function createAuthUrl(string $state): string
    {
        $client = $this->client();
        $client->setState($state);

        return $client->createAuthUrl();
    }

    /**
     * 同意画面から戻ってきた認可コードをトークンへ交換する。
     *
     * @return array{access_token: string, refresh_token: string|null, expires_at: Carbon}
     *
     * @throws RuntimeException 交換に失敗した場合(通信エラー / コードが無効 / 期限切れ など)
     */
    public function exchangeCode(string $code): array
    {
        // ライブラリの失敗の出方は 2 通りある(どちらも実測で確認済み)。
        // 呼び出し側に 1 種類の例外だけを見せたいので、ここで RuntimeException に揃える。
        //
        // (1) 認可コードが無効 → 例外は飛ばず ['error' => 'invalid_grant', ...] が返る。
        // (2) 通信エラー       → GuzzleHttp\Exception\RequestException が飛ぶ。
        try {
            $token = $this->client()->fetchAccessTokenWithAuthCode($code);
        } catch (\Throwable $e) {
            // ⚠️ (2) の例外オブジェクトは RequestException::$request にリクエスト本体を抱えており
            //    (vendor/guzzlehttp/guzzle/src/Exception/RequestException.php:19)、
            //    トークン交換の POST ボディには client_secret と認可コードが載っている。
            //    メッセージ自体に秘密は出ないが、APP_DEBUG=true のエラー画面や dd() が
            //    例外オブジェクトを展開すると見えてしまう。
            //    そこで外向きには自前のメッセージだけを出し、元の例外は previous に留める
            //    (ログに出すかどうか・何を出すかは呼び出し側が決める)。
            throw new RuntimeException('Google の認可コードをトークンに交換できませんでした。', previous: $e);
        }

        if (! is_array($token) || ! isset($token['access_token'])) {
            // (1) の経路。error / error_description に秘密は含まれない(実測)。
            // 原因が分からないと「連携できない」の切り分けができないので、error の値だけを載せる。
            // error_description は Google が返す自由文なので、将来入力値が混ざる可能性を考えて載せない。
            $reason = is_array($token) && isset($token['error']) ? (string) $token['error'] : 'unknown_error';

            throw new RuntimeException("Google からアクセストークンを取得できませんでした。({$reason})");
        }

        return [
            'access_token' => (string) $token['access_token'],

            // ⚠️ refresh_token は返ってこないことがある(Google は初回の同意時にしか発行しない)。
            //    createAuthUrl() 側で prompt=consent を指定して毎回発行させているが、
            //    Google の仕様変更や再同意のスキップに備えて null を許容し、
            //    保存側が「返ってきたときだけ差し替える」で扱えるようにしておく。
            'refresh_token' => isset($token['refresh_token']) ? (string) $token['refresh_token'] : null,

            // expires_in は「今から何秒で切れるか」(Google の既定は 3600 = 1 時間)。
            // 絶対時刻に直して保存する。比較のたびに now() から計算し直さなくて済むため。
            'expires_at' => Carbon::now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
        ];
    }

    /**
     * アクセストークンが切れていれば refresh_token で更新し、DB にも書き戻す。
     *
     * 原典 共通の振る舞い「連携は一度設定すれば継続して使える状態を保つ」の実体。
     * Google のアクセストークンは既定で 1 時間しか持たないので、これが無いと
     * 連携した当日のうちに動かなくなる。
     *
     * @return string そのまま API 呼び出しに使えるアクセストークン
     *
     * @throws RuntimeException 更新できない場合(refresh_token が無い / 通信失敗 / Google が拒否)
     */
    public function ensureFreshAccessToken(GoogleCredential $credential): string
    {
        // ⚠️ 期限ちょうどではなく少し手前で更新する。「残り 1 秒」で通してしまうと、
        //    この後の API 呼び出しが届くころには切れていて 401 になる。
        if ($credential->expires_at->copy()->subSeconds(self::REFRESH_MARGIN_SECONDS)->isFuture()) {
            return $credential->access_token;
        }

        if ($credential->refresh_token === null) {
            // 連携し直してもらうしかない状態。ConnectAction / prompt=consent で起きないようにしているが、
            // 起きたときに「なぜ動かないか」が分かるメッセージを残す。
            throw new RuntimeException('リフレッシュトークンが無いためアクセストークンを更新できません。連携し直してください。');
        }

        try {
            $token = $this->fetchRefreshedToken($credential->refresh_token);
        } catch (\Throwable $e) {
            // exchangeCode() と同じ理由で元の例外は previous に留める(リクエスト本体に client_secret が載る)。
            throw new RuntimeException('アクセストークンを更新できませんでした。', previous: $e);
        }

        // ⚠️ exchangeCode() と違って is_array() は見ない。fetchRefreshedToken() の戻り型が
        //    array なので、そのチェックは常に false になる到達不能なコードになる。
        if (! isset($token['access_token'])) {
            $reason = isset($token['error']) ? (string) $token['error'] : 'unknown_error';

            throw new RuntimeException("アクセストークンを更新できませんでした。({$reason})");
        }

        // 更新できたら必ず保存する。保存し忘れると次のリクエストでも切れたままで、毎回更新しに行くことになる。
        $credential->access_token = (string) $token['access_token'];
        $credential->expires_at = Carbon::now()->addSeconds((int) ($token['expires_in'] ?? 3600));

        // ⚠️ ここでも「返ってきたときだけ」差し替える。更新のレスポンスに refresh_token は通常含まれず、
        //    素直に代入すると手元の有効なトークンを消してしまう(ConnectAction と同じ理由)。
        if (isset($token['refresh_token'])) {
            $credential->refresh_token = (string) $token['refresh_token'];
        }

        $credential->save();

        return $credential->access_token;
    }

    /**
     * 指定コーチの Google カレンダーで「予定が入っている」時間帯を返す。
     *
     * freebusy.query は予定の**有無だけ**を返し、件名や参加者は返さない。
     * 空き枠から外すのに必要なのはそれだけなので、広い calendar スコープを要求せずに済む
     * (原典「連携済コーチが Google カレンダーで予定を持つ時刻は、受講生の予約画面の空き枠から外れる」)。
     *
     * ⚠️ $from / $to は 1 回の呼び出しで 1 コーチ分。コーチごとにアクセストークンが違うため、
     *    複数コーチを 1 リクエストにまとめることはできない(items に複数のカレンダーを並べても、
     *    そのトークンで見える範囲しか返らない)。呼び出し側は連携済コーチの数だけ呼ぶことになる。
     *
     * @return array<int, array{start: Carbon, end: Carbon}> アプリのタイムゾーンに変換済み
     *
     * @throws RuntimeException 通信に失敗した場合(呼び出し側が try-catch でフォールバックする)
     */
    public function busyPeriods(GoogleCredential $credential, Carbon $from, Carbon $to): array
    {
        $request = new FreeBusyRequest;
        // RFC3339(例 2026-09-16T00:00:00+09:00)で渡す。オフセット付きなので時差の解釈違いが起きない。
        $request->setTimeMin($from->toRfc3339String());
        $request->setTimeMax($to->toRfc3339String());

        $item = new FreeBusyRequestItem;
        $item->setId($credential->calendar_id);
        $request->setItems([$item]);

        try {
            $response = $this->calendarFor($credential)->freebusy->query($request);
        } catch (\Throwable $e) {
            throw new RuntimeException('Google カレンダーの空き状況を取得できませんでした。', previous: $e);
        }

        // レスポンスは「カレンダー ID => 結果」の連想配列。要求した ID が無ければ予定なし扱いにする。
        $calendar = $response->getCalendars()[$credential->calendar_id] ?? null;

        if ($calendar === null) {
            return [];
        }

        $periods = [];

        foreach ($calendar->getBusy() as $busy) {
            $periods[] = [
                // ⚠️ Google は UTC(末尾 Z)で返すことがある。アプリは Asia/Tokyo で時刻を扱うので、
                //    ここで必ず変換する。変換を忘れると 9 時間ずれた時刻と突き合わせることになり、
                //    「予定があるのに空き枠に出る / 無いのに消える」が起きる。
                'start' => Carbon::parse($busy->getStart())->setTimezone(config('app.timezone')),
                'end' => Carbon::parse($busy->getEnd())->setTimezone(config('app.timezone')),
            ];
        }

        return $periods;
    }

    /**
     * コーチのカレンダーへ予定を 1 件登録し、Google が採番したイベント ID を返す。
     *
     * ⚠️ 「何を書くか」はここでは決めない。決めるのは呼び出し側(UseCase)で、
     *    このクラスは渡された文字列と時刻をそのまま Google の形に詰め替えるだけにする。
     *    仕様(decisions #145 の件名 / 説明 / 場所)が変わったときに、外部通信のコードを
     *    触らずに済ませるため。
     *
     * @param array{summary: string, description: string, location: string, starts_at: Carbon, ends_at: Carbon} $event
     *
     * @return string Google が採番したイベント ID(キャンセル時の削除に使う)
     *
     * @throws RuntimeException 通信に失敗した場合(呼び出し側が try-catch でフォールバックする)
     */
    public function createEvent(GoogleCredential $credential, array $event): string
    {
        $payload = new Event;
        $payload->setSummary($event['summary']);
        $payload->setDescription($event['description']);
        $payload->setLocation($event['location']);

        // 開始 / 終了は「時刻 + タイムゾーン」の組で渡す。
        // RFC3339 にオフセットが入っているうえに timeZone も明示するのは、
        // Google 側で繰り返し予定やサマータイムを扱うときの基準を確定させるため(公式サンプルもこの形)。
        $payload->setStart($this->eventDateTime($event['starts_at']));
        $payload->setEnd($this->eventDateTime($event['ends_at']));

        try {
            $created = $this->calendarFor($credential)
                ->events
                ->insert($credential->calendar_id, $payload);
        } catch (\Throwable $e) {
            throw new RuntimeException('Google カレンダーへの予定登録に失敗しました。', previous: $e);
        }

        $eventId = $created->getId();

        if (! is_string($eventId) || $eventId === '') {
            throw new RuntimeException('Google カレンダーからイベント ID を取得できませんでした。');
        }

        return $eventId;
    }

    /**
     * コーチのカレンダーから予定を 1 件削除する。
     *
     * ⚠️ 「既に無い」は成功として扱う。Google は削除済みのイベントに対して 404 / 410 を返すが、
     *    こちらの目的は「その予定が残っていない状態にすること」なので、結果が同じなら成功でよい。
     *    ここで例外にすると、コーチが Google 側で手動削除しただけで
     *    LMS の面談キャンセルが失敗するようになってしまう(原典「キャンセルは止まらない」に反する)。
     *
     * @throws RuntimeException 上記以外の理由で通信に失敗した場合
     */
    public function deleteEvent(GoogleCredential $credential, string $eventId): void
    {
        try {
            $this->calendarFor($credential)
                ->events
                ->delete($credential->calendar_id, $eventId);
        } catch (GoogleServiceException $e) {
            // 404 Not Found / 410 Gone = 既に存在しない。目的は達成されているので成功扱い。
            if (in_array($e->getCode(), [404, 410], true)) {
                return;
            }

            throw new RuntimeException('Google カレンダーの予定削除に失敗しました。', previous: $e);
        } catch (\Throwable $e) {
            throw new RuntimeException('Google カレンダーの予定削除に失敗しました。', previous: $e);
        }
    }

    /**
     * Carbon を Google の「日時 + タイムゾーン」表現に詰め替える。
     */
    private function eventDateTime(Carbon $at): EventDateTime
    {
        $dateTime = new EventDateTime;
        $dateTime->setDateTime($at->toRfc3339String());
        $dateTime->setTimeZone(config('app.timezone'));

        return $dateTime;
    }

    /**
     * リフレッシュトークンで新しいアクセストークンを取り寄せる。
     *
     * ⚠️ protected にしてあるのはテストのため。ここを差し替えると、**実通信なしで**
     *    「期限切れ → 更新 → DB へ書き戻し」という経路そのものを検証できる
     *    (tests/Unit/Services/GoogleCalendarServiceTest.php)。
     *    切り出す前は、`client()` が「設定されていません」で先に落ちるため
     *    **更新処理に一度も到達しないままテストが緑になっていた**(変異テストで実測)。
     *    calendarFor() と同じ考え方の継ぎ目。
     *
     * @return array<string, mixed> Google が返したトークン情報
     */
    protected function fetchRefreshedToken(string $refreshToken): array
    {
        return (array) $this->client()->fetchAccessTokenWithRefreshToken($refreshToken);
    }

    /**
     * 特定コーチのカレンダー API を返す。
     *
     * ⚠️ protected にしてあるのはテストのため。ここを差し替えると、Google が返す 404 / 410 を
     *    deleteEvent() がどう扱うかを実通信なしで検証できる
     *    (tests/Unit/Services/GoogleCalendarServiceTest.php)。
     *    private にすると、この分岐を通すには本物の Google に消せない ID を投げるしかなくなる。
     */
    protected function calendarFor(GoogleCredential $credential): Calendar
    {
        return new Calendar($this->clientFor($credential));
    }

    /**
     * 特定コーチのトークンを載せた Google クライアントを返す。
     *
     * 期限切れならここで更新するので、呼び出し側は期限を気にしなくてよい。
     */
    private function clientFor(GoogleCredential $credential): GoogleClient
    {
        $client = $this->client();
        $client->setAccessToken($this->ensureFreshAccessToken($credential));

        return $client;
    }

    /**
     * 設定済みの Google クライアントを 1 つ組み立てて返す。
     *
     * リクエストごとに使い捨てる。シングルトンにしてアクセストークンを持ち回すと、
     * 別のコーチのトークンが載ったままになる事故が起きうるため。
     *
     * @throws RuntimeException .env が未設定の場合
     */
    private function client(): GoogleClient
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Google カレンダー連携の設定(GOOGLE_CLIENT_ID 等)がされていません。');
        }

        $client = new GoogleClient;
        $client->setClientId((string) config('services.google.client_id'));
        $client->setClientSecret((string) config('services.google.client_secret'));
        $client->setRedirectUri((string) config('services.google.redirect_uri'));
        $client->setScopes(self::SCOPES);

        // refresh_token を受け取るために必須。これが無いとアクセストークンだけが返り、
        // 1 時間後に更新できなくなる(原典「連携は一度設定すれば継続して使える状態を保つ」)。
        $client->setAccessType('offline');

        // ⚠️ 毎回同意画面を出す。付けないと「解除 → 再連携」で refresh_token が発行されない。
        //    解除しても Google 側の同意は残る(原典 スコープ外により取り消しに行かない)ため、
        //    2 回目の連携を Google が「同意済み」と見なして refresh_token を返さず、
        //    再連携した瞬間から 1 時間で連携が切れる行ができてしまう。
        //    連携ボタンを押した直後に同意画面が出るのは利用者から見ても自然なので、常に付ける。
        $client->setPrompt('consent');

        // ⚠️ タイムアウトを明示する(既定は無制限)。理由は HTTP_TIMEOUT_SECONDS の docblock。
        //    http_errors => false はライブラリの既定と揃える —— true にすると
        //    Google\Service\Exception ではなく Guzzle の例外が飛び、
        //    deleteEvent() の 404 / 410 判定が効かなくなる。
        $client->setHttpClient(new GuzzleClient([
            'timeout' => self::HTTP_TIMEOUT_SECONDS,
            'connect_timeout' => self::HTTP_CONNECT_TIMEOUT_SECONDS,
            'http_errors' => false,
        ]));

        return $client;
    }
}
