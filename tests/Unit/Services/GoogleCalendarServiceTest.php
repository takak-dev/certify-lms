<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\GoogleCredential;
use App\Services\GoogleCalendarService;
use Google\Client as GoogleClient;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\FreeBusyCalendar;
use Google\Service\Calendar\FreeBusyRequest;
use Google\Service\Calendar\FreeBusyResponse;
use Google\Service\Calendar\Resource\Events;
use Google\Service\Calendar\Resource\Freebusy;
use Google\Service\Calendar\TimePeriod;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * GoogleCalendarService の 2 つの判断を検証する(S-A-01)。
 *
 *  ① deleteEvent() が Google のエラー(404 / 410)をどう扱うか
 *  ② ensureFreshAccessToken() が期限切れのトークンをどう更新するか
 *
 * ⚠️ 実通信はしない。calendarFor() を差し替えて、Google が返す HTTP ステータスだけを再現する。
 *
 * ここで守りたいのは「**目的が達成されているなら成功として扱う**」という判断。
 * 原典「その面談がキャンセルされると、登録済の予定も連動して削除される」に対し、
 * 予定が既に無い状態はその要求を満たしている。ここを失敗にすると、
 * コーチが Google 側で予定を手動削除しただけで LMS の面談キャンセルが落ちるようになり、
 * 原典 共通の振る舞い「面談のキャンセルは止まらない」に反する。
 *
 * Google のエラー仕様(公式ドキュメント「Errors」):
 *  - 410 Gone      … "if a request attempts to delete an event that has already been deleted"
 *  - 404 Not Found … "when the requested resource (with the provided ID) has never existed"
 */
#[Group('external')]
#[Group('google-calendar')]
class GoogleCalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * トークン更新の結果を固定した Service を組み立てる。
     *
     * ⚠️ `fetchRefreshedToken()` を差し替えるので `client()` を通らず、**実通信は起きない**。
     *    これが無いと「設定されていません」で先に落ち、更新処理に到達しないまま緑になる。
     *
     * @param array<string, mixed>|\Throwable $result 返す値、または投げる例外
     * @param int|null $calls 呼び出し回数を書き戻す先(参照渡し)
     *
     * ⚠️ 既定値 null は必要。外すと呼び出し側が **未宣言の変数** を渡せなくなり、
     *    null が渡って型 int に合わず TypeError になる(実測で確認)。
     */
    private function serviceWithRefreshResult(array|\Throwable $result, ?int &$calls = null): GoogleCalendarService
    {
        $calls = 0;

        return new class($result, $calls) extends GoogleCalendarService
        {
            public function __construct(
                private readonly array|\Throwable $result,
                private int &$calls,
            ) {}

            protected function fetchRefreshedToken(string $refreshToken): array
            {
                $this->calls++;

                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        };
    }

    /**
     * events->delete が指定のステータスで失敗する Service を組み立てる。
     *
     * @param int|null $failWithStatus null なら削除が成功する
     */
    private function serviceThatFailsWith(?int $failWithStatus): GoogleCalendarService
    {
        $events = Mockery::mock(Events::class);

        if ($failWithStatus === null) {
            $events->shouldReceive('delete')->once();
        } else {
            // ライブラリは HTTP ステータスをそのまま例外コードに詰める
            // (vendor/google/apiclient/src/Http/REST.php の decodeHttpResponse)。
            $events->shouldReceive('delete')->once()
                ->andThrow(new GoogleServiceException('error', $failWithStatus));
        }

        return new class($events) extends GoogleCalendarService
        {
            public function __construct(private readonly Events $fakeEvents) {}

            protected function calendarFor(GoogleCredential $credential): Calendar
            {
                // clientFor() を通さない = トークン更新も実通信も起きない。
                $calendar = new Calendar(new GoogleClient);
                $calendar->events = $this->fakeEvents;

                return $calendar;
            }
        };
    }

    /**
     * 既に削除済みの予定(410)は成功として扱う。
     *
     * ⭐ コーチが Google カレンダー上で予定を手で消したあと、受講生が LMS で面談を
     *    キャンセルしたときに通る経路。ここで例外が出ると受講生の操作が失敗する。
     */
    public function test_delete_event_treats_already_deleted_as_success(): void
    {
        // Arrange
        $credential = GoogleCredential::factory()->create();
        $service = $this->serviceThatFailsWith(410);

        // Act & Assert: 例外が出ないこと
        $service->deleteEvent($credential, 'ALREADY-DELETED-EVENT-ID');
        $this->addToAssertionCount(1);
    }

    /**
     * そもそも存在しない予定(404)も成功として扱う。
     */
    public function test_delete_event_treats_missing_event_as_success(): void
    {
        // Arrange
        $credential = GoogleCredential::factory()->create();
        $service = $this->serviceThatFailsWith(404);

        // Act & Assert
        $service->deleteEvent($credential, 'NEVER-EXISTED-EVENT-ID');
        $this->addToAssertionCount(1);
    }

    /**
     * それ以外の失敗(サーバエラー等)は握り潰さず例外にする。
     *
     * ⚠️ この 1 本が無いと「全部成功扱い」に書き換えてもテストが通ってしまい、
     *    本当に消せなかったケースが無言で消える。
     *    握るかどうかを決めるのは呼び出し側(RemoveMeetingEventAction)の責務。
     */
    public function test_delete_event_rethrows_other_failures(): void
    {
        // Arrange
        $credential = GoogleCredential::factory()->create();
        $service = $this->serviceThatFailsWith(500);

        // Assert
        $this->expectException(RuntimeException::class);

        // Act
        $service->deleteEvent($credential, 'SOME-EVENT-ID');
    }

    /**
     * 正常に削除できた場合は当然ながら例外が出ない(対照群)。
     */
    public function test_delete_event_succeeds_normally(): void
    {
        // Arrange
        $credential = GoogleCredential::factory()->create();
        $service = $this->serviceThatFailsWith(null);

        // Act & Assert
        $service->deleteEvent($credential, 'SOME-EVENT-ID');
        $this->addToAssertionCount(1);
    }

    // ================================================================
    // トークンの自動更新（原典「連携は一度設定すれば継続して使える状態を保つ」）
    // ================================================================

    /**
     * 期限内のトークンはそのまま返し、Google へは問い合わせない。
     */
    public function test_ensure_fresh_access_token_returns_the_current_token_while_valid(): void
    {
        // Arrange: 期限まで 1 時間ある
        $credential = GoogleCredential::factory()->create([
            'access_token' => 'STILL-VALID',
            'expires_at' => Carbon::now()->addHour(),
        ]);

        // Act & Assert
        $this->assertSame('STILL-VALID', (new GoogleCalendarService)->ensureFreshAccessToken($credential));
    }

    /**
     * ⭐ 期限が「マージンの内側」に入ったら更新しに行き、新しいトークンを DB へ書き戻す。
     *
     * 原典 共通の振る舞い「連携は一度設定すれば継続して使える状態を保つ」の実体。
     * 期限ちょうどを境にすると、判定を通った直後に切れて 401 になる取りこぼしが出るので、
     * 60 秒のマージンを持たせている。
     */
    public function test_ensure_fresh_access_token_refreshes_inside_the_margin(): void
    {
        // Arrange: 期限まで残り 30 秒（マージンは 60 秒）。更新は成功する。
        $credential = GoogleCredential::factory()->create([
            'access_token' => 'OLD-TOKEN',
            'refresh_token' => 'RT-1',
            'expires_at' => Carbon::now()->addSeconds(30),
        ]);
        $service = $this->serviceWithRefreshResult(
            ['access_token' => 'NEW-TOKEN', 'expires_in' => 3600],
            $calls,
        );

        // Act
        $token = $service->ensureFreshAccessToken($credential);

        // Assert: 更新を 1 回だけ試み、新しいトークンを返し、DB にも書き戻している
        $this->assertSame(1, $calls);
        $this->assertSame('NEW-TOKEN', $token);
        $this->assertSame('NEW-TOKEN', $credential->fresh()->access_token);
        $this->assertTrue($credential->fresh()->expires_at->isFuture());
        // ⚠️ 更新のレスポンスに refresh_token は通常含まれない。null で潰していないこと。
        $this->assertSame('RT-1', $credential->fresh()->refresh_token);
    }

    /**
     * リフレッシュトークンが無ければ、原因が分かるメッセージで失敗する。
     *
     * ⚠️ Google は 2 回目以降の認可で refresh_token を返さないことがあるため、実際に起こりうる状態。
     *    ここが静かに失敗すると「連携した 1 時間後に理由もなく切れる」になる。
     */
    public function test_ensure_fresh_access_token_fails_clearly_without_a_refresh_token(): void
    {
        // Arrange
        $credential = GoogleCredential::factory()->expired()->withoutRefreshToken()->create();

        // Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('リフレッシュトークンが無いため');

        // Act
        (new GoogleCalendarService)->ensureFreshAccessToken($credential);
    }

    /**
     * 更新に失敗したとき、DB のトークンを中途半端に書き換えない。
     *
     * ⚠️ 壊れた値で上書きすると、Google が復旧しても元のトークンに戻れない。
     */
    public function test_failed_refresh_leaves_the_stored_token_untouched(): void
    {
        // Arrange: 期限切れ。更新は失敗する。
        $credential = GoogleCredential::factory()->expired()->create([
            'access_token' => 'ORIGINAL-TOKEN',
            'refresh_token' => 'RT-1',
        ]);
        $service = $this->serviceWithRefreshResult(new RuntimeException('Google is down'), $calls);

        // Assert
        $this->expectException(RuntimeException::class);

        try {
            // Act
            $service->ensureFreshAccessToken($credential);
        } finally {
            $this->assertSame(1, $calls);
            $this->assertSame('ORIGINAL-TOKEN', $credential->fresh()->access_token);
        }
    }

    /**
     * Google が access_token を返さなかった場合も、原因を残して失敗する。
     *
     * ⚠️ 「例外は飛ばないが中身が無い」経路。exchangeCode() と同じ形で、
     *    error の値だけをメッセージに載せる（秘密は載せない）。
     */
    public function test_refresh_without_access_token_fails_with_the_reason(): void
    {
        // Arrange
        $credential = GoogleCredential::factory()->expired()->create(['refresh_token' => 'RT-1']);
        $service = $this->serviceWithRefreshResult(['error' => 'invalid_grant'], $calls);

        // Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_grant');

        // Act
        $service->ensureFreshAccessToken($credential);
    }

    // ================================================================
    // 空き時刻の取得(T-A-04 原典「カレンダー操作(空き時刻の取得 / 予定の作成 / 予定の削除)それぞれのモックテスト」)
    // ================================================================

    /**
     * calendarFor() が、渡した偽のカレンダー API を返す Service を組み立てる。
     *
     * serviceThatFailsWith() と同じ差し替え方(clientFor() を通さない = トークン更新も実通信も起きない)。
     * あちらは「削除」専用なので、どの操作でも使えるよう偽の API を外から渡せる形にした。
     */
    private function serviceWithCalendar(Calendar $calendar): GoogleCalendarService
    {
        return new class($calendar) extends GoogleCalendarService
        {
            public function __construct(private readonly Calendar $fakeCalendar) {}

            protected function calendarFor(GoogleCredential $credential): Calendar
            {
                return $this->fakeCalendar;
            }
        };
    }

    /**
     * FreeBusy(空き状況の問い合わせ)の窓口だけを偽物に差し替えたカレンダー API を作る。
     *
     * @param \Closure(FreeBusyRequest): FreeBusyResponse $respond 問い合わせを受けて返す応答(例外を投げてもよい)
     */
    private function calendarWithFreebusy(\Closure $respond): Calendar
    {
        $freebusy = Mockery::mock(Freebusy::class);
        $freebusy->shouldReceive('query')->once()->andReturnUsing($respond);

        // Calendar は SDK の入れ物。中の freebusy プロパティを偽物に入れ替える(Events の差し替えと同じ手)
        $calendar = new Calendar(new GoogleClient);
        $calendar->freebusy = $freebusy;

        return $calendar;
    }

    /**
     * Google が UTC(末尾 Z)で返した予定の時刻を、アプリのタイムゾーン(Asia/Tokyo)に直して返す。
     *
     * ⚠️ ここを落とすと 9 時間ずれた時刻で空き枠と突き合わせることになり、
     *    「予定があるのに空き枠に出る / 無いのに消える」が起きる(GoogleCalendarService.php の busyPeriods())。
     *    使う側のテスト(MeetingAvailabilityServiceTest)は busyPeriods() を丸ごと差し替えているので、
     *    この変換を見張れるのはここだけ。
     */
    public function test_busy_periods_converts_google_utc_times_to_the_app_timezone(): void
    {
        // Arrange: コーチの 1 件の予定。Google は UTC 01:00〜02:00(= 日本時間 10:00〜11:00)で返す
        $credential = GoogleCredential::factory()->create(['calendar_id' => 'primary']);
        $sent = null;
        $service = $this->serviceWithCalendar($this->calendarWithFreebusy(
            function (FreeBusyRequest $request) use (&$sent): FreeBusyResponse {
                $sent = $request;

                $period = new TimePeriod;
                $period->setStart('2026-10-05T01:00:00Z');
                $period->setEnd('2026-10-05T02:00:00Z');
                $calendar = new FreeBusyCalendar;
                $calendar->setBusy([$period]);
                $response = new FreeBusyResponse;
                $response->setCalendars(['primary' => $calendar]);

                return $response;
            },
        ));
        $from = Carbon::parse('2026-10-05 00:00', 'Asia/Tokyo');
        $to = Carbon::parse('2026-10-06 00:00', 'Asia/Tokyo');

        // Act
        $periods = $service->busyPeriods($credential, $from, $to);

        // Assert: 日本時間の 10:00〜11:00 に直っている
        $this->assertCount(1, $periods);
        $this->assertSame('2026-10-05 10:00:00', $periods[0]['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-05 11:00:00', $periods[0]['end']->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Tokyo', $periods[0]['start']->timezoneName);

        // Assert: 送信内容 —— 期間はオフセット付き(+09:00)で、問い合わせ先はコーチのカレンダー
        $this->assertSame('2026-10-05T00:00:00+09:00', $sent->getTimeMin());
        $this->assertSame('2026-10-06T00:00:00+09:00', $sent->getTimeMax());
        $this->assertSame('primary', $sent->getItems()[0]->getId());
    }

    /**
     * 応答に問い合わせたカレンダーが含まれていなければ「予定なし」として扱う。
     */
    public function test_busy_periods_returns_no_periods_when_the_calendar_is_missing_from_the_response(): void
    {
        // Arrange: 別のカレンダーの結果だけが返ってくる
        $credential = GoogleCredential::factory()->create(['calendar_id' => 'primary']);
        $service = $this->serviceWithCalendar($this->calendarWithFreebusy(function (): FreeBusyResponse {
            $response = new FreeBusyResponse;
            $response->setCalendars(['someone-else@example.com' => new FreeBusyCalendar]);

            return $response;
        }));

        // Act
        $periods = $service->busyPeriods($credential, Carbon::now(), Carbon::now()->addDay());

        // Assert: 例外にせず、空の一覧
        $this->assertSame([], $periods);
    }

    /**
     * 通信の失敗は、1 種類の例外(RuntimeException)に揃えて投げる。
     *
     * 使う側(MeetingAvailabilityService)はこの例外を受け止めて「Google の予定を除かずに空き枠を出す」
     * フォールバックに入る(原典「連携に失敗しても面談機能は止まらない」)。
     */
    public function test_busy_periods_wraps_failures_in_a_single_exception_type(): void
    {
        // Arrange: Google が 500 を返す(SDK は HTTP ステータスを例外コードに詰めて投げる)
        $credential = GoogleCredential::factory()->create();
        $service = $this->serviceWithCalendar($this->calendarWithFreebusy(
            fn () => throw new GoogleServiceException('backendError', 500),
        ));

        // Act
        try {
            $service->busyPeriods($credential, Carbon::now(), Carbon::now()->addDay());
            $this->fail('例外が投げられていない');
        } catch (RuntimeException $e) {
            // Assert: 元の SDK の例外は previous に留め、外には自前のメッセージだけを出す
            $this->assertSame('Google カレンダーの空き状況を取得できませんでした。', $e->getMessage());
            $this->assertInstanceOf(GoogleServiceException::class, $e->getPrevious());
        }
    }

    // ================================================================
    // 予定の作成
    // ================================================================

    /**
     * 予定の登録(events->insert)の窓口だけを偽物に差し替えたカレンダー API を作る。
     *
     * @param \Closure(string, Event): Event $respond 登録先のカレンダー ID と予定を受けて返す応答(例外を投げてもよい)
     */
    private function calendarWithEventInsert(\Closure $respond): Calendar
    {
        $events = Mockery::mock(Events::class);
        $events->shouldReceive('insert')->once()->andReturnUsing($respond);

        $calendar = new Calendar(new GoogleClient);
        $calendar->events = $events;

        return $calendar;
    }

    /**
     * 登録する予定(呼び出し側 SyncMeetingAction が渡す形)。
     *
     * @return array{summary: string, description: string, location: string, starts_at: Carbon, ends_at: Carbon}
     */
    private function meetingEvent(): array
    {
        return [
            'summary' => '山田 花子 / 基本情報技術者',
            'description' => '相談したい',
            'location' => 'https://meet.example.com/coach-room',
            'starts_at' => Carbon::parse('2026-10-05 10:00', 'Asia/Tokyo'),
            'ends_at' => Carbon::parse('2026-10-05 11:00', 'Asia/Tokyo'),
        ];
    }

    /**
     * 渡された内容をそのまま Google の形に詰め替えて送り、Google が採番したイベント ID を返す。
     *
     * 返した ID は meetings.google_event_id に控えられ、キャンセル時の削除に使われる。
     * 使う側のテスト(GoogleCalendarSyncTest)は createEvent() を丸ごと差し替えているので、
     * 「Google にどう詰めて送るか」を見張れるのはここだけ。
     */
    public function test_create_event_sends_the_event_and_returns_the_google_event_id(): void
    {
        // Arrange: Google は登録に成功し、ID を採番して返す
        $credential = GoogleCredential::factory()->create(['calendar_id' => 'primary']);
        $sentCalendarId = null;
        $sent = null;
        $service = $this->serviceWithCalendar($this->calendarWithEventInsert(
            function (string $calendarId, Event $event) use (&$sentCalendarId, &$sent): Event {
                $sentCalendarId = $calendarId;
                $sent = $event;

                $created = new Event;
                $created->setId('GOOGLE-EVENT-1');

                return $created;
            },
        ));

        // Act
        $eventId = $service->createEvent($credential, $this->meetingEvent());

        // Assert: Google が採番した ID がそのまま返る
        $this->assertSame('GOOGLE-EVENT-1', $eventId);

        // Assert: 送信内容 —— 登録先はコーチのカレンダーで、文字列は加工せずに詰めている
        $this->assertSame('primary', $sentCalendarId);
        $this->assertSame('山田 花子 / 基本情報技術者', $sent->getSummary());
        $this->assertSame('相談したい', $sent->getDescription());
        $this->assertSame('https://meet.example.com/coach-room', $sent->getLocation());

        // Assert: 時刻はオフセット付き + タイムゾーン名の組で送っている
        $this->assertSame('2026-10-05T10:00:00+09:00', $sent->getStart()->getDateTime());
        $this->assertSame('Asia/Tokyo', $sent->getStart()->getTimeZone());
        $this->assertSame('2026-10-05T11:00:00+09:00', $sent->getEnd()->getDateTime());
        $this->assertSame('Asia/Tokyo', $sent->getEnd()->getTimeZone());
    }

    /**
     * 登録できたように見えても ID が空なら失敗として扱う。
     *
     * ⚠️ 空の ID を成功として返すと、控えが空になり「未同期」と見分けがつかず、
     *    キャンセルしても Google 側の予定が消せないまま残る。
     */
    public function test_create_event_fails_when_google_returns_no_event_id(): void
    {
        // Arrange: ID の無い応答
        $credential = GoogleCredential::factory()->create();
        $service = $this->serviceWithCalendar($this->calendarWithEventInsert(fn (): Event => new Event));

        // Act & Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Google カレンダーからイベント ID を取得できませんでした。');

        $service->createEvent($credential, $this->meetingEvent());
    }

    /**
     * 通信の失敗は、1 種類の例外(RuntimeException)に揃えて投げる。
     *
     * 使う側(SyncMeetingAction)はこの例外を受け止めて、ID を空のまま予約を成立させる
     * (原典「連携に失敗しても面談機能は止まらない」)。
     */
    public function test_create_event_wraps_failures_in_a_single_exception_type(): void
    {
        // Arrange: Google が 403(権限不足。コーチが Google 側で連携を取り消した場合など)を返す
        $credential = GoogleCredential::factory()->create();
        $service = $this->serviceWithCalendar($this->calendarWithEventInsert(
            fn () => throw new GoogleServiceException('forbidden', 403),
        ));

        // Act
        try {
            $service->createEvent($credential, $this->meetingEvent());
            $this->fail('例外が投げられていない');
        } catch (RuntimeException $e) {
            // Assert: 元の SDK の例外は previous に留め、外には自前のメッセージだけを出す
            $this->assertSame('Google カレンダーへの予定登録に失敗しました。', $e->getMessage());
            $this->assertInstanceOf(GoogleServiceException::class, $e->getPrevious());
        }
    }

    // ================================================================
    // 認可コードの交換(T-A-04 原典「認可フロー(連携の開始 / 連携情報の交換 / 連携の解除)」の「交換」)
    // ================================================================

    /**
     * client() が、渡した偽の Google クライアントを返す Service を組み立てる。
     *
     * ⭐ client() は T-A-04 で private → protected にした(decisions #249)ので上書きできる。
     *    exchangeCode() は calendarFor() を通らず client() を直接使うため、この継ぎ目でしか差し替えられない。
     *
     * @param \Closure(string): mixed $respond 認可コードを受けて SDK が返すもの(例外を投げてもよい)
     */
    private function serviceWithTokenExchange(\Closure $respond): GoogleCalendarService
    {
        $client = Mockery::mock(GoogleClient::class);
        $client->shouldReceive('fetchAccessTokenWithAuthCode')->once()->andReturnUsing($respond);

        return new class($client) extends GoogleCalendarService
        {
            public function __construct(private readonly GoogleClient $fakeClient) {}

            protected function client(): GoogleClient
            {
                return $this->fakeClient;
            }
        };
    }

    /**
     * 交換に成功したら、保存に使う 3 つの値(アクセストークン / リフレッシュトークン / 期限の絶対時刻)に直して返す。
     *
     * 使う側のテスト(GoogleCalendarTest の callback)は exchangeCode() を丸ごと差し替えているので、
     * 「Google の応答をどう読み替えるか」を見張れるのはここだけ。
     */
    public function test_exchange_code_returns_tokens_with_an_absolute_expiry(): void
    {
        // Arrange: 時刻を固定する(期限は「今 + expires_in 秒」で計算されるため)
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'Asia/Tokyo'));
        $service = $this->serviceWithTokenExchange(fn (string $code): array => [
            'access_token' => 'AT-1',
            'refresh_token' => 'RT-1',
            'expires_in' => 3599,
        ]);

        // Act
        $token = $service->exchangeCode('AUTH-CODE');

        // Assert: expires_in(残り秒数)が絶対時刻に直っている
        $this->assertSame('AT-1', $token['access_token']);
        $this->assertSame('RT-1', $token['refresh_token']);
        $this->assertSame('2026-10-05 10:59:59', $token['expires_at']->format('Y-m-d H:i:s'));
    }

    /**
     * リフレッシュトークンが返ってこなければ null にする(空文字や例外にしない)。
     *
     * ⚠️ Google は初回の同意時にしか refresh_token を発行しないことがある。null なら保存側が
     *    「返ってきたときだけ差し替える」で既存の値を残せる(GoogleCalendarService.php の exchangeCode())。
     */
    public function test_exchange_code_allows_a_missing_refresh_token(): void
    {
        // Arrange: refresh_token の無い応答
        $service = $this->serviceWithTokenExchange(fn (): array => [
            'access_token' => 'AT-1',
            'expires_in' => 3600,
        ]);

        // Act
        $token = $service->exchangeCode('AUTH-CODE');

        // Assert
        $this->assertNull($token['refresh_token']);
    }

    /**
     * 認可コードが無効なとき(SDK は例外を投げず、エラーの配列を返す)は、理由の符号だけを載せて失敗する。
     *
     * ⚠️ error_description は Google が返す自由文なので載せない(将来入力値が混ざる可能性があるため)。
     */
    public function test_exchange_code_fails_with_the_error_code_when_the_code_is_rejected(): void
    {
        // Arrange: 期限切れ・使用済みの認可コード(SDK はこの形を「返す」)
        $service = $this->serviceWithTokenExchange(fn (): array => [
            'error' => 'invalid_grant',
            'error_description' => 'Bad Request: code=AUTH-CODE',
        ]);

        // Act
        try {
            $service->exchangeCode('AUTH-CODE');
            $this->fail('例外が投げられていない');
        } catch (RuntimeException $e) {
            // Assert: 切り分けに使える符号は載る
            $this->assertStringContainsString('invalid_grant', $e->getMessage());
            // Assert: 自由文(認可コードが混ざっている)は載らない
            $this->assertStringNotContainsString('AUTH-CODE', $e->getMessage());
        }
    }

    /**
     * 通信エラーのとき(SDK は Guzzle の例外を投げる)は、自前のメッセージだけの例外に包み替える。
     *
     * ⚠️ Guzzle の例外はリクエスト本体(client_secret と認可コードが載った POST ボディ)を抱えている。
     *    外に出るメッセージに秘密が混ざらないことと、元の例外は previous に留めることを固定する。
     */
    public function test_exchange_code_wraps_connection_failures_without_leaking_secrets(): void
    {
        // Arrange: トークン交換の POST が通信エラーになる(ボディに秘密を載せた本物の形のリクエスト)
        $request = new GuzzleRequest('POST', 'https://oauth2.googleapis.com/token', [], 'client_secret=SECRET-VALUE&code=AUTH-CODE');
        $service = $this->serviceWithTokenExchange(
            fn () => throw new ConnectException('cURL error 28: Operation timed out', $request),
        );

        // Act
        try {
            $service->exchangeCode('AUTH-CODE');
            $this->fail('例外が投げられていない');
        } catch (RuntimeException $e) {
            // Assert: 外に出るのは自前の固定文だけ
            $this->assertSame('Google の認可コードをトークンに交換できませんでした。', $e->getMessage());
            $this->assertStringNotContainsString('SECRET-VALUE', $e->getMessage());
            // Assert: 原因の切り分け用に元の例外は残す(ログに何を出すかは呼び出し側が決める)
            $this->assertInstanceOf(ConnectException::class, $e->getPrevious());
        }
    }

    // ================================================================
    // 期限切れ → 自動リフレッシュ → 再試行(T-A-04 原典「連携の期限切れ → 自動リフレッシュ → 再試行」)
    // ================================================================

    /**
     * 期限切れのトークンで操作すると、先に更新してから、**更新後のトークンで** Google へ送る。
     *
     * 既存の test_ensure_fresh_access_token_refreshes_inside_the_margin は「更新して DB に書き戻す」までしか
     * 見ていない。ここでは、その新しいトークンが**実際に送られるリクエストに載る**ところまでをつなげて確かめる。
     * 他のテストのように calendarFor() を差し替えると、トークンを載せる処理(clientFor())ごと飛ばしてしまうため、
     * ここだけは 1 段深く、SDK の下にいる HTTP の通信係(Guzzle)を偽物にする。
     *
     * 手段: Guzzle の MockHandler(公式ドキュメント「Testing」)。送られたリクエストに、あらかじめ積んだ応答を
     * 順に返す偽の通信係で、外へは出ない。積んだものが関数なら、その関数にリクエストを渡して呼ぶ
     * (vendor/guzzlehttp/guzzle/src/Handler/MockHandler.php:106-107)ので、そこで中身を記録する。
     *
     * ⚠️ 公式ドキュメントにある Middleware::history(送信の記録係)は使わない。SDK はトークンを載せる部品を
     *    あとから通信係に積む(vendor/google/apiclient/src/AuthHandler/Guzzle6AuthHandler.php:77 の attachToken()。
     *    Guzzle7AuthHandler は中身の無い子クラスでこれを受け継いでいる)が、
     *    Guzzle は先に積んだ部品ほど外側で動く(vendor/guzzlehttp/guzzle/src/HandlerStack.php:209-211)。
     *    記録係が先に積まれると**トークンが載る前**のリクエストを記録してしまい、Authorization が空に見える(実測)。
     *    いちばん内側の MockHandler の中で記録すれば、実際に外へ出ていくはずの最終形を見られる。
     */
    public function test_expired_token_is_refreshed_and_the_new_token_is_sent_to_google(): void
    {
        // Arrange: 期限切れのトークンを持つコーチ
        $credential = GoogleCredential::factory()->create([
            'calendar_id' => 'primary',
            'access_token' => 'OLD-TOKEN',
            'refresh_token' => 'RT-1',
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        // Arrange: 送られたリクエストを記録し、Google の空き状況 API が返す応答(公式 REST の形の JSON)を返す
        $sentRequests = [];
        $mock = new MockHandler([
            function (RequestInterface $request) use (&$sentRequests): GuzzleResponse {
                $sentRequests[] = $request;

                return new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode([
                    'kind' => 'calendar#freeBusy',
                    'calendars' => ['primary' => ['busy' => [
                        ['start' => '2026-10-05T01:00:00Z', 'end' => '2026-10-05T02:00:00Z'],
                    ]]],
                ], JSON_THROW_ON_ERROR));
            },
        ]);
        $stack = HandlerStack::create($mock);

        $service = new class(new GuzzleClient(['handler' => $stack])) extends GoogleCalendarService
        {
            public int $refreshCalls = 0;

            public function __construct(private readonly GuzzleClient $fakeHttp) {}

            // 本物の client() は phpunit.xml で鍵が空なので例外になる。代わりに、通信係だけを偽物にした
            // SDK のクライアントを返す。トークンを載せる処理(clientFor())と SDK の組み立ては本物のまま通る。
            protected function client(): GoogleClient
            {
                $client = new GoogleClient;
                $client->setHttpClient($this->fakeHttp);

                return $client;
            }

            // トークン更新の通信だけは既存テストと同じく結果を固定する(更新そのものは別のテストが見ている)。
            protected function fetchRefreshedToken(string $refreshToken): array
            {
                $this->refreshCalls++;

                return ['access_token' => 'NEW-TOKEN', 'expires_in' => 3600];
            }
        };

        // Act
        $periods = $service->busyPeriods($credential, Carbon::now(), Carbon::now()->addDay());

        // Assert: 更新は 1 回だけ試みられ、DB にも新しいトークンが書き戻された
        $this->assertSame(1, $service->refreshCalls);
        $this->assertSame('NEW-TOKEN', $credential->fresh()->access_token);

        // Assert: Google へは 1 回だけ送られ、そのリクエストには**更新後の**トークンが載っている
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];
        $this->assertStringContainsString('/calendar/v3/freeBusy', (string) $request->getUri());
        $this->assertSame('Bearer NEW-TOKEN', $request->getHeaderLine('Authorization'));

        // Assert: 応答は通常どおり読み取られ、操作は最後まで成功している
        $this->assertCount(1, $periods);
        $this->assertSame('10:00', $periods[0]['start']->format('H:i'));
    }
}
