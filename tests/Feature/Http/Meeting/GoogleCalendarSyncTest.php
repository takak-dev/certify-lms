<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\GoogleCredential;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * 面談の予約 / キャンセルと Google カレンダーの連動を検証する(S-A-01)。
 *
 * 原典の 3 条を担保する。
 *  ① 連携済コーチが担当する面談が成立すると、そのコーチの Google カレンダーへ予定が自動登録される
 *  ② 連携していないコーチには登録しない
 *  ③ その面談がキャンセルされると、登録済の予定も連動して削除される
 * さらに共通の振る舞い「Google との通信に失敗しても、面談の予約・キャンセルは止まらない」。
 *
 * ⚠️ Google への通信そのものは差し替える。本物を呼ぶとテストがネットワークとトークンの状態に
 *    依存してしまう。ここで見たいのは「LMS 側がどう振る舞うか」だけ(本格的なモックは T-A-04)。
 */
class GoogleCalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    private function attachCoach(Certification $certification, User $coach, User $admin): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
    }

    /**
     * 予約できる状態を一式そろえる。
     *
     * @return array{student: User, coach: User, enrollment: Enrollment, scheduled_at: Carbon}
     */
    private function bookableSetup(): array
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $certification = Certification::factory()->published()->create(['name' => '基本情報技術者']);
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();

        return [
            'student' => $student,
            'coach' => $coach,
            'enrollment' => Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create(),
            // 次の月曜 10:00(必ず未来)
            'scheduled_at' => now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0),
        ];
    }

    // ================================================================
    // 予約
    // ================================================================

    /**
     * ① 連携済コーチの面談が成立すると Google カレンダーへ登録され、イベント ID が控えられる。
     *
     * あわせて、予定に書く内容が decisions #145(面談2 Q18-a)どおりかを検証する——
     * 件名 = 受講生名 + 資格名 / 説明 = 話題 + 面談 URL の案内 / 場所 = コーチの固定面談 URL。
     */
    public function test_store_registers_event_on_google_calendar_for_linked_coach(): void
    {
        // Arrange
        ['student' => $student, 'coach' => $coach, 'enrollment' => $enrollment, 'scheduled_at' => $scheduledAt]
            = $this->bookableSetup();
        $student->update(['name' => '山田太郎']);
        GoogleCredential::factory()->forUser($coach)->create();

        // createEvent に渡された中身を捕まえるための入れ物
        $captured = null;

        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) use (&$captured) {
            // 空き枠判定(coachIdsOfferingSlot 経由)でも呼ばれる。予定なしを返す。
            $mock->shouldReceive('busyPeriods')->andReturn([]);
            $mock->shouldReceive('createEvent')->once()->andReturnUsing(
                function ($credential, array $event) use (&$captured): string {
                    $captured = $event;

                    return 'GOOGLE-EVENT-ID-123';
                },
            );
        });

        // Act
        $response = $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '学習計画の見直しを相談したいです。',
        ]);

        // Assert: 予約が成立し、イベント ID が控えられている
        $response->assertRedirect();
        $this->assertDatabaseHas('meetings', [
            'student_id' => $student->id,
            'coach_id' => $coach->id,
            'google_event_id' => 'GOOGLE-EVENT-ID-123',
        ]);

        // Assert: 予定の中身(decisions #145)
        $this->assertSame('面談: 山田太郎 / 基本情報技術者', $captured['summary']);
        $this->assertStringContainsString('学習計画の見直しを相談したいです。', $captured['description']);
        $this->assertStringContainsString('https://meet.example.com/coach-room', $captured['description']);
        $this->assertSame('https://meet.example.com/coach-room', $captured['location']);
        // 面談は 60 分固定
        $this->assertSame(60, $captured['starts_at']->diffInMinutes($captured['ends_at']));
    }

    /**
     * ② 未連携コーチの面談では Google を一切呼ばない。
     *
     * ⚠️ 「既存機能を壊していない」ことの担保。google_credentials に行が無いコーチでは
     *    createEvent が 1 度も呼ばれてはいけない。
     */
    public function test_store_does_not_touch_google_for_unlinked_coach(): void
    {
        // Arrange: GoogleCredential を作らない
        ['student' => $student, 'coach' => $coach, 'enrollment' => $enrollment, 'scheduled_at' => $scheduledAt]
            = $this->bookableSetup();

        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldReceive('busyPeriods')->andReturn([]);
            $mock->shouldNotReceive('createEvent');
        });

        // ⚠️ shouldNotReceive('createEvent') だけでは不十分。未連携チェックを外しても
        //    createEvent(null, ...) が TypeError になり、SyncMeetingAction の catch(Throwable) が
        //    それを握るため、DB の見た目は同じままテストが通ってしまう(変異テストで実測)。
        //    「呼ぼうとして失敗した」なら必ず warning ログが出るので、そこを見て
        //    「そもそも呼ぼうとしていない」ことを証明する。
        Log::spy();

        // Act
        $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ])->assertRedirect();

        // Assert: 予約は成立し、イベント ID は空のまま
        $this->assertDatabaseHas('meetings', [
            'coach_id' => $coach->id,
            'status' => MeetingStatus::Reserved->value,
            'google_event_id' => null,
        ]);

        // Assert: Google を呼ぼうとした形跡が無い
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Google への登録に失敗しても予約そのものは成立する。
     *
     * 原典 共通の振る舞い「Google との通信に失敗しても…面談の予約…は止まらない」。
     * 予約は既に DB に確定しているので、ここで 500 にすると
     * 「予約は入ったのに画面はエラー」という最悪の食い違いになる。
     */
    public function test_store_succeeds_even_when_google_registration_fails(): void
    {
        // Arrange: 連携済だが createEvent が必ず失敗する
        ['student' => $student, 'coach' => $coach, 'enrollment' => $enrollment, 'scheduled_at' => $scheduledAt]
            = $this->bookableSetup();
        GoogleCredential::factory()->forUser($coach)->create();

        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldReceive('busyPeriods')->andReturn([]);
            $mock->shouldReceive('createEvent')->andThrow(new RuntimeException('Google is down'));
        });

        // Act
        $response = $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        // Assert: 予約は成立。イベント ID は空(= 未同期と分かる)
        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('meetings', [
            'coach_id' => $coach->id,
            'status' => MeetingStatus::Reserved->value,
            'google_event_id' => null,
        ]);
    }

    // ================================================================
    // キャンセル
    // ================================================================

    /**
     * ③ 登録済の面談をキャンセルすると Google 側の予定も消え、控えも空になる。
     */
    public function test_cancel_removes_event_from_google_calendar(): void
    {
        // Arrange: 連携済コーチの、Google 登録済の面談
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $coach = User::factory()->coach()->create();
        GoogleCredential::factory()->forUser($coach)->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
            'google_event_id' => 'GOOGLE-EVENT-ID-123',
        ]);

        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            // 控えていたイベント ID がそのまま渡ること
            $mock->shouldReceive('deleteEvent')->once()->withArgs(
                fn ($credential, string $eventId): bool => $eventId === 'GOOGLE-EVENT-ID-123',
            );
        });

        // Act
        $this->actingAs($student)->post(route('meetings.cancel', $meeting))->assertRedirect();

        // Assert
        $fresh = $meeting->fresh();
        $this->assertSame(MeetingStatus::Canceled, $fresh->status);
        $this->assertNull($fresh->google_event_id);
    }

    /**
     * 未登録の面談をキャンセルしても Google を呼ばない。
     *
     * ⚠️ meetings.google_event_id を持つ最大の理由がこれ。列が無ければ
     *    「登録したかどうか」が分からず、存在しない予定に毎回削除要求を投げることになる。
     */
    public function test_cancel_does_not_touch_google_when_event_was_never_registered(): void
    {
        // Arrange: 連携済コーチだが、この面談は Google に登録されていない
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $coach = User::factory()->coach()->create();
        GoogleCredential::factory()->forUser($coach)->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
            'google_event_id' => null,
        ]);

        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('deleteEvent');
        });

        // Act & Assert
        $this->actingAs($student)->post(route('meetings.cancel', $meeting))->assertRedirect();
        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
    }

    /**
     * Google 側の削除に失敗してもキャンセルは成立する。
     *
     * 原典 共通の振る舞い「…面談のキャンセルといった面談機能の根幹は止まらない」。
     */
    public function test_cancel_succeeds_even_when_google_deletion_fails(): void
    {
        // Arrange
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $coach = User::factory()->coach()->create();
        GoogleCredential::factory()->forUser($coach)->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
            'google_event_id' => 'GOOGLE-EVENT-ID-123',
        ]);

        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldReceive('deleteEvent')->andThrow(new RuntimeException('Google is down'));
        });

        // Act
        $response = $this->actingAs($student)->post(route('meetings.cancel', $meeting));

        // Assert: キャンセルは成立。控えは残る(消せていないので「登録済」のまま持つのが正しい)
        $response->assertRedirect();
        $response->assertSessionHas('success');
        $fresh = $meeting->fresh();
        $this->assertSame(MeetingStatus::Canceled, $fresh->status);
        $this->assertSame('GOOGLE-EVENT-ID-123', $fresh->google_event_id);
    }

    /**
     * 予定を登録した後にコーチが連携を解除していたら、キャンセル時に何もしない。
     *
     * ⚠️ 想定内の状態。支給 Blade が解除時に「Google 側のイベントは削除されません」と
     *    利用者に説明しているとおり、消しに行かないのが正しい
     *    （settings/_partials/tab-meeting.blade.php:113-114）。
     *    控えた ID は残す —— 再連携したときに消しに行ける手がかりになる。
     */
    public function test_cancel_does_nothing_when_the_coach_disconnected_after_booking(): void
    {
        // Arrange: 予定は登録済み。しかし連携は解除されている（google_credentials に行が無い）
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $coach = User::factory()->coach()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
            'google_event_id' => 'GOOGLE-EVENT-ID-123',
        ]);

        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('deleteEvent');
        });
        Log::spy();

        // Act
        $this->actingAs($student)->post(route('meetings.cancel', $meeting))->assertRedirect();

        // Assert: キャンセルは成立し、控えは残る（呼ぼうとした形跡も無い）
        $fresh = $meeting->fresh();
        $this->assertSame(MeetingStatus::Canceled, $fresh->status);
        $this->assertSame('GOOGLE-EVENT-ID-123', $fresh->google_event_id);
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * ⭐ 残り面談回数が 0 の受講生の予約 POST では、Google を一切呼ばない。
     *
     * ⚠️ 候補コーチの確定をトランザクションの外へ出した副作用への歯止め。
     *    外へ出すと、そのままでは残回数チェックより前に Google 通信が走るようになり、
     *    **無資格の受講生が時刻を変えながら連打するだけで外部 API のクォータを削れる**。
     *    クォータが尽きると空き枠計算のフォールバックで全コーチが「予定なし」に倒れ、
     *    このチケットのダブルブッキング防止そのものが無効化される。
     *
     *    この 1 本が無いと、事前チェックを外しても全テストが緑のまま（変異テストで実測）。
     */
    public function test_store_does_not_call_google_when_the_student_has_no_quota(): void
    {
        // Arrange: 面談回数 0 の受講生。コーチは連携済み。
        ['student' => $student, 'coach' => $coach, 'enrollment' => $enrollment, 'scheduled_at' => $scheduledAt]
            = $this->bookableSetup();
        $student->update(['max_meetings' => 0]);
        GoogleCredential::factory()->forUser($coach)->create();

        $this->mock(GoogleCalendarService::class, function (MockInterface $mock) {
            // 空き枠判定も予定登録も、1 度も呼ばれてはいけない
            $mock->shouldNotReceive('busyPeriods');
            $mock->shouldNotReceive('createEvent');
        });

        // Act
        $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => '相談したい',
        ]);

        // Assert: 予約は成立していない
        $this->assertDatabaseCount('meetings', 0);
    }
}
