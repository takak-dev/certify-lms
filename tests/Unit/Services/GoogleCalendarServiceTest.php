<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\GoogleCredential;
use App\Services\GoogleCalendarService;
use Google\Client as GoogleClient;
use Google\Service\Calendar;
use Google\Service\Calendar\Resource\Events;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
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
}
