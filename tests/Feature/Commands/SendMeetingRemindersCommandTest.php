<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use App\Services\UserWithdrawalService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 面談リマインダー配信コマンド (S-B-09) の検証。
 *
 * ⭐ このテストの核心は「2 回実行しても増えない」こと。
 * 要件が求めているのは配信そのものではなく「重複して起動・再実行されても二重配信しない」ことで、
 * 送信済みの記録を専用テーブルではなく notifications の data に持たせた(decisions #36 / #104)。
 * そのため Notification::fake() は使えない。fake は通知行を作らないので、
 * 「送信済みかどうか」を引く仕組みごと素通りしてしまい、重複検査を検証できない。
 *
 * 時刻は travelTo で固定する。前日分は「翌日 1 日分」、1 時間前分は「55〜65 分後」を見るため、
 * 実時刻のまま書くと実行した瞬間によって結果が変わる。
 */
class SendMeetingRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    /** テスト中の「いま」。夜 18:00 に固定する(前日分が実際に動く時刻) */
    private const NOW = '2026-09-11 18:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::NOW));
    }

    /**
     * 面談 1 件を作る。当事者はどちらも受講中(通知を受け取れる状態)。
     *
     * ファクトリの状態は文字列ではなく Enum で受けて match で分ける。
     * `Meeting::factory()->{$state}()` のような動的呼び出しだと、名前を間違えても実行時まで気づけない。
     */
    private function makeMeeting(Carbon $scheduledAt, MeetingStatus $status = MeetingStatus::Reserved): Meeting
    {
        // 当事者を面談ごとに作るのは、宛先ごとの通知件数を独立に数えられるようにするため
        $coach = User::factory()->coach()->inProgress()->create();
        $student = User::factory()->student()->inProgress()->create();

        $factory = match ($status) {
            MeetingStatus::Reserved => Meeting::factory()->reserved(),
            MeetingStatus::Canceled => Meeting::factory()->canceled(),
            MeetingStatus::Completed => Meeting::factory()->completed(),
        };

        return $factory
            ->forCoach($coach)
            ->forStudent($student)
            ->create(['scheduled_at' => $scheduledAt]);
    }

    /** notifications テーブルに入っている面談リマインダーの件数 */
    private function reminderCount(): int
    {
        return DB::table('notifications')
            ->where('type', MeetingReminderNotification::class)
            ->count();
    }

    public function test_eve_window_notifies_both_parties_of_a_meeting_scheduled_tomorrow(): void
    {
        // Arrange: 翌日 10:00 の予約。前日 18:00 に走るコマンドの対象になる
        $meeting = $this->makeMeeting(Carbon::parse('2026-09-12 10:00:00'));

        // Act
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->expectsOutput('面談リマインダー（前日）を 2 件送信しました。')
            ->assertExitCode(0);

        // Assert: 受講生とコーチの 2 名に届く(管理者は当事者ではないので登場しない)
        $this->assertSame(2, $this->reminderCount());
        $this->assertSame(1, $meeting->student->notifications()->count());
        $this->assertSame(1, $meeting->coach->notifications()->count());
    }

    public function test_notification_data_carries_the_keys_the_duplicate_check_needs(): void
    {
        // Arrange
        $meeting = $this->makeMeeting(Carbon::parse('2026-09-12 10:00:00'));

        // Act
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve']);

        // Assert: 重複検査はこの 2 つのキーで引く。どちらかが欠けると冪等性が成立しない
        $data = json_decode((string) DB::table('notifications')->value('data'), true);
        $this->assertSame('meeting_reminder', $data['notification_type']);
        $this->assertSame($meeting->id, $data['meeting_id']);
        $this->assertSame('eve', $data['reminder_window']);
    }

    public function test_running_the_same_window_twice_does_not_send_duplicates(): void
    {
        // Arrange: 要件の核心。手動での再実行や、窓が重なる巡回で二重配信が起きないこと
        $this->makeMeeting(Carbon::parse('2026-09-12 10:00:00'));

        // Act: 1 回目
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->expectsOutput('面談リマインダー（前日）を 2 件送信しました。');
        $afterFirstRun = $this->reminderCount();

        // Act: 2 回目。条件は何も変えていないので、同じ面談がふたたび抽出される
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->expectsOutput('面談リマインダー（前日）を 0 件送信しました。');

        // Assert: 通知行は増えない
        $this->assertSame(2, $afterFirstRun);
        $this->assertSame(2, $this->reminderCount());
    }

    public function test_the_two_windows_are_sent_independently_for_the_same_meeting(): void
    {
        // Arrange: 翌日 10:00 の面談。前日 18:00 に前日分、当日 09:00 に 1 時間前分が走る想定
        $meeting = $this->makeMeeting(Carbon::parse('2026-09-12 10:00:00'));

        // Act: 前日分
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->expectsOutput('面談リマインダー（前日）を 2 件送信しました。');

        // Act: 面談当日の 09:00 へ進めて 1 時間前分(開始ちょうど 60 分前)
        $this->travelTo(Carbon::parse('2026-09-12 09:00:00'));
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])
            ->expectsOutput('面談リマインダー（開始 1 時間前）を 2 件送信しました。');

        // Assert: 同じ面談・同じ宛先でも window が違えば別の通知として届く。
        //         reminder_window を持たせていないと前日分が送信済みと判定され、
        //         直前リマインダーが永久に飛ばなくなる(decisions #104)
        $this->assertSame(4, $this->reminderCount());
        $this->assertSame(2, DB::table('notifications')->where('data->reminder_window', 'eve')->count());
        $this->assertSame(2, DB::table('notifications')->where('data->reminder_window', 'one_hour_before')->count());
        $this->assertSame(2, $meeting->student->notifications()->count());
    }

    public function test_one_hour_before_window_covers_only_the_five_five_to_sixty_five_minute_range(): void
    {
        // Arrange: 窓の内側 1 件と、前後の外側 2 件
        $inside = $this->makeMeeting(Carbon::parse('2026-09-11 19:00:00'));   // 60 分後 → 対象
        $tooSoon = $this->makeMeeting(Carbon::parse('2026-09-11 18:30:00'));  // 30 分後 → 対象外
        $tooLate = $this->makeMeeting(Carbon::parse('2026-09-11 20:00:00'));  // 120 分後 → 対象外

        // Act
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])
            ->expectsOutput('面談リマインダー（開始 1 時間前）を 2 件送信しました。');

        // Assert: 窓の内側の面談の当事者だけが受け取る
        $this->assertSame(1, $inside->student->notifications()->count());
        $this->assertSame(0, $tooSoon->student->notifications()->count());
        $this->assertSame(0, $tooLate->student->notifications()->count());
    }

    public function test_eve_window_does_not_target_a_meeting_on_the_same_day(): void
    {
        // Arrange: 当日 20:00 の面談。18:00 に前日分が走っても対象にしない(当日分は 1 時間前分が拾う)
        $today = $this->makeMeeting(Carbon::parse('2026-09-11 20:00:00'));

        // Act
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->expectsOutput('面談リマインダー（前日）を 0 件送信しました。');

        // Assert
        $this->assertSame(0, $this->reminderCount());
        $this->assertSame(0, $today->student->notifications()->count());
    }

    public function test_canceled_and_completed_meetings_are_not_targeted(): void
    {
        // Arrange: 翌日の予定だが、予約済ではない 2 件
        $canceled = $this->makeMeeting(Carbon::parse('2026-09-12 10:00:00'), MeetingStatus::Canceled);
        $completed = $this->makeMeeting(Carbon::parse('2026-09-12 11:00:00'), MeetingStatus::Completed);

        // Act
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->expectsOutput('面談リマインダー（前日）を 0 件送信しました。');

        // Assert
        $this->assertSame(0, $canceled->student->notifications()->count());
        $this->assertSame(0, $completed->student->notifications()->count());
    }

    public function test_a_party_who_cannot_receive_notifications_is_skipped(): void
    {
        // Arrange: 受講生だけ修了済。配信対象は受講中のユーザーのみ(decisions #34)で、
        //          コーチは受講中なので受け取る(面談リマインダーはコーチにも配信する)
        $coach = User::factory()->coach()->inProgress()->create();
        $student = User::factory()->student()->graduated()->create();
        $meeting = Meeting::factory()->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->create(['scheduled_at' => Carbon::parse('2026-09-12 10:00:00')]);

        // Act: 出力の件数も、実際に作られた通知行と一致していなければならない
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->expectsOutput('面談リマインダー（前日）を 1 件送信しました。');

        // Assert
        $this->assertSame(1, $this->reminderCount());
        $this->assertSame(0, $meeting->student->notifications()->count());
        $this->assertSame(1, $meeting->coach->notifications()->count());
    }

    public function test_a_withdrawn_party_keeps_its_name_in_the_reminder(): void
    {
        // Arrange: コーチが退会済。受講生は受講中なのでリマインダーを受け取る。
        //          退会者の氏名はそのまま出す方針(decisions #46 / #67 / #105)
        $coach = User::factory()->coach()->inProgress()->create(['name' => '退会コーチ']);
        $student = User::factory()->student()->inProgress()->create();
        Meeting::factory()->reserved()
            ->forCoach($coach)->forStudent($student)
            ->create(['scheduled_at' => Carbon::parse('2026-09-12 10:00:00')]);
        app(UserWithdrawalService::class)->withdraw($coach);

        // Act: 退会したコーチには届かず(status が Withdrawn)、受講生の 1 件だけが残る
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->expectsOutput('面談リマインダー（前日）を 1 件送信しました。');

        // Assert: 本文に氏名が残っている。「相手方」のような伏せ字にはしない
        $data = json_decode((string) DB::table('notifications')->value('data'), true);
        $this->assertStringContainsString('退会コーチ', $data['message']);
    }

    public function test_the_overlapping_sweep_does_not_send_twice(): void
    {
        // Arrange: ⭐ 要件の核心。1 時間前分は「55〜65 分前」の 10 分幅を 5 分間隔で巡回するため、
        //          同じ面談が連続する 2 回の起動で必ず条件に一致する(decisions #51)。
        //          手動の再実行ではなく、正常な定期実行だけで重複が起きる経路をここで固定する
        $this->makeMeeting(Carbon::parse('2026-09-11 19:00:00')); // 18:00 時点で 60 分後

        // Act: 1 回目(60 分前)
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])
            ->expectsOutput('面談リマインダー（開始 1 時間前）を 2 件送信しました。');

        // Act: 5 分後の起動。同じ面談が今度は 55 分前として再び窓に入る
        $this->travelTo(Carbon::parse('2026-09-11 18:05:00'));
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])
            ->expectsOutput('面談リマインダー（開始 1 時間前）を 0 件送信しました。');

        // Assert: 通知は増えない
        $this->assertSame(2, $this->reminderCount());
    }

    public function test_one_hour_before_window_includes_its_boundaries_and_excludes_one_minute_outside(): void
    {
        // Arrange: 18:00 実行なので窓は 18:55〜19:05。両端の内と外を 1 分差で並べる(decisions #51)
        $lowerOutside = $this->makeMeeting(Carbon::parse('2026-09-11 18:54:00')); // 54 分後 → 外
        $lowerEdge = $this->makeMeeting(Carbon::parse('2026-09-11 18:55:00'));    // 55 分後 → 内
        $upperEdge = $this->makeMeeting(Carbon::parse('2026-09-11 19:05:00'));    // 65 分後 → 内
        $upperOutside = $this->makeMeeting(Carbon::parse('2026-09-11 19:06:00')); // 66 分後 → 外

        // Act: 内側 2 件 × 当事者 2 名
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])
            ->expectsOutput('面談リマインダー（開始 1 時間前）を 4 件送信しました。');

        // Assert: 1 分外れただけで対象から落ちる
        $this->assertSame(0, $lowerOutside->student->notifications()->count());
        $this->assertSame(1, $lowerEdge->student->notifications()->count());
        $this->assertSame(1, $upperEdge->student->notifications()->count());
        $this->assertSame(0, $upperOutside->student->notifications()->count());
    }

    public function test_eve_window_does_not_target_the_day_after_tomorrow(): void
    {
        // Arrange: 前日分の対象は「翌日 1 日分」。翌々日はまだ送らない(明日また 18:00 に走る)
        $dayAfterTomorrow = $this->makeMeeting(Carbon::parse('2026-09-13 10:00:00'));

        // Act
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->expectsOutput('面談リマインダー（前日）を 0 件送信しました。');

        // Assert
        $this->assertSame(0, $dayAfterTomorrow->student->notifications()->count());
    }

    public function test_both_windows_are_registered_on_the_schedule(): void
    {
        // Arrange: 「定期実行で自動的に走る」は原典の要件そのもの。
        //          Kernel の 2 行は誰も実行しないので、打ち間違えても 18:00 に静かに失敗するだけになる
        $events = collect(app(Schedule::class)->events())
            ->map(fn ($event): string => $event->getExpression().' '.$event->command)
            ->all();

        // Act & Assert: cron 式とコマンド文字列の組で確かめる
        $this->assertTrue(
            collect($events)->contains(fn (string $e): bool => str_contains($e, '0 18 * * *')
                && str_contains($e, 'notifications:send-meeting-reminders --window=eve')),
            '前日分（毎日 18:00）のスケジュール登録が見つかりません: '.implode(' / ', $events),
        );
        $this->assertTrue(
            collect($events)->contains(fn (string $e): bool => str_contains($e, '*/5 * * * *')
                && str_contains($e, 'notifications:send-meeting-reminders --window=one_hour_before')),
            '1 時間前分（5 分間隔）のスケジュール登録が見つかりません: '.implode(' / ', $events),
        );
    }

    public function test_a_held_lock_skips_the_run_without_sending(): void
    {
        // Arrange: 前の実行が終わっていない（または異常終了してロックが残っている）状態を作る。
        //          Schedule の withoutOverlapping は手動実行に効かないため、
        //          同時実行を止めるのはコマンド自身のロック（decisions #107）
        $this->makeMeeting(Carbon::parse('2026-09-12 10:00:00'));
        Cache::lock('meeting-reminders:eve', 600)->get();
        Log::spy();

        // Act
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->expectsOutput('面談リマインダー（前日）は実行中のためスキップしました。')
            ->assertExitCode(0);

        // Assert: 1 通も送らない
        $this->assertSame(0, $this->reminderCount());

        // Assert: ⚠️ 前日分はこの日の配信が二度と行われない（次の起動は 24 時間後で対象の日が変わる）。
        //         標準出力は cron に流れて読まれないので、記録が残ることまで固定する（decisions #107）
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Meeting reminder run skipped: lock was held'
                && $context['unrecoverable'] === true
                && $context['target'] === '2026-09-12',
        );
    }

    public function test_the_two_windows_do_not_share_a_lock(): void
    {
        // Arrange: ⚠️ 2 本は毎日 18:00 ちょうどに同時起動する（eve は 0 18 * * *、
        //          one_hour_before は */5 * * * *）。ロックのキーから window が落ちると、
        //          前日分が毎日ロック衝突でスキップされ、その日の配信が二度と行われなくなる
        $this->makeMeeting(Carbon::parse('2026-09-11 19:00:00')); // 60 分後
        Cache::lock('meeting-reminders:eve', 600)->get();

        // Act: 前日分のロックを握ったまま 1 時間前分を走らせる
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])
            ->expectsOutput('面談リマインダー（開始 1 時間前）を 2 件送信しました。')
            ->assertExitCode(0);

        // Assert: 互いのロックに邪魔されない
        $this->assertSame(2, $this->reminderCount());
    }

    public function test_each_party_sees_the_other_side_with_the_label_from_the_screen(): void
    {
        // Arrange: 文面のロール別出し分け（decisions #109）。ラベルの言い回しは画面から取っている
        //          （meeting/index.blade.php:65「担当コーチ: 」/ meeting/coach/index.blade.php:56「受講生: 」）
        $coach = User::factory()->coach()->inProgress()->create(['name' => 'コーチ太郎']);
        $student = User::factory()->student()->inProgress()->create(['name' => '受講生花子']);
        Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)
            ->create(['scheduled_at' => Carbon::parse('2026-09-12 10:00:00')]);

        // Act
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])
            ->assertExitCode(0);

        // Assert: 受講生にはコーチ名、コーチには受講生名が出る。
        //         DatabaseNotification の data は配列にキャスト済みなので json_decode は要らない
        $toStudent = $student->notifications()->first()->data;
        $toCoach = $coach->notifications()->first()->data;
        $this->assertStringContainsString('担当コーチ: コーチ太郎', $toStudent['message']);
        $this->assertStringContainsString('受講生: 受講生花子', $toCoach['message']);
    }

    public function test_an_unknown_window_fails_without_sending_anything(): void
    {
        // Arrange: 対象になる面談を置いたうえで、誤った値を渡す
        $this->makeMeeting(Carbon::parse('2026-09-12 10:00:00'));

        // Act & Assert: 静かに 0 件で終わらせず、失敗として返す
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'tomorrow'])
            ->assertExitCode(1);
        $this->assertSame(0, $this->reminderCount());

        // Act & Assert: 未指定も同じ（decisions #110 は「不正・未指定」の両方を宣言している）。
        //               署名に既定値を付けてしまうと静かに片方の window だけ走り続ける
        $this->artisan('notifications:send-meeting-reminders')
            ->assertExitCode(1);
        $this->assertSame(0, $this->reminderCount());
    }
}
