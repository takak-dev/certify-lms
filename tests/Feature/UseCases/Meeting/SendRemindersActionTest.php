<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingReminderWindow;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use App\UseCases\Meeting\SendRemindersAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * 配信ユースケース単体の検証。
 *
 * Command 側のテスト(tests/Feature/Commands/SendMeetingRemindersCommandTest.php)は
 * 「どの面談を抽出するか」を見る。こちらは**コマンド経由では到達できない 2 経路**だけを持つ。
 * 手本の 2 本立ては AutoCompleteMeetingsCommandTest（抽出）と
 * AutoCompleteMeetingActionTest（冪等性）の関係と同じ。
 *
 * ① 1 宛先の配信が失敗しても残りを巻き込まないこと（decisions #106）
 * ② 送信済みの判定が「面談単位」ではなく「受信者単位」であること（decisions #104）
 */
class SendRemindersActionTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-09-11 18:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::NOW));
    }

    /** 当事者がどちらも受講中の面談を 1 件作る */
    private function makeMeeting(string $scheduledAt): Meeting
    {
        return Meeting::factory()->reserved()
            ->forCoach(User::factory()->coach()->inProgress()->create())
            ->forStudent(User::factory()->student()->inProgress()->create())
            ->create(['scheduled_at' => Carbon::parse($scheduledAt)]);
    }

    /** 渡した Collection を Action に流す */
    private function dispatch(MeetingReminderWindow $window = MeetingReminderWindow::Eve): int
    {
        return app(SendRemindersAction::class)(
            Meeting::query()->with(['student', 'coach'])->orderBy('scheduled_at')->get(),
            $window,
        );
    }

    public function test_one_failing_delivery_does_not_stop_the_rest(): void
    {
        // Arrange: 翌日の面談 2 件。1 件目の受講生あてだけメール送信を失敗させる。
        //          Command 経由では例外を起こせないので、ここで mail チャネルだけ落とす。
        //          ⚠️ 既存テストの失敗注入は Mockery で Service を差し替える形だが
        //          (FetchAdminDashboardActionTest:91 ほか)、Action は $user->notify() を直接呼ぶため
        //          差し替える依存が無い。送信イベントで落とすのが唯一の手になる
        $first = $this->makeMeeting('2026-09-12 10:00:00');
        $second = $this->makeMeeting('2026-09-12 11:00:00');
        $failingAddress = $first->student->email;

        Log::spy();
        Event::listen(function (MessageSending $event) use ($failingAddress): void {
            if (($event->message->getTo()[0]->getAddress() ?? '') === $failingAddress) {
                throw new RuntimeException('SMTP down');
            }
        });

        // Act
        $sent = $this->dispatch();

        // Assert: 4 宛先のうち 1 件が失敗し、残り 3 件は配信される。
        //         失敗した 1 件は件数に数えない（コマンドの出力を実態に合わせるため）
        $this->assertSame(3, $sent);
        $this->assertSame(1, $first->coach->notifications()->count());
        $this->assertSame(1, $second->student->notifications()->count());
        $this->assertSame(1, $second->coach->notifications()->count());

        // Assert: 失敗は握りつぶさずログに残す
        Log::shouldHaveReceived('warning')->once()
            ->withArgs(fn (string $message): bool => $message === 'Meeting reminder delivery failed');

        // Assert: ⚠️ アプリ内通知の行は via の順序で先に作られているため、失敗した宛先にも残る。
        //         つまり次回の実行はこの宛先を送信済みと判定し、メールは再送されない（decisions #106）
        $this->assertSame(1, $first->student->notifications()->count());
    }

    public function test_the_sent_check_is_per_recipient_not_per_meeting(): void
    {
        // Arrange: コーチにだけ先にリマインダーが届いている状態を作る。
        //          修了などで片方だけ配信対象から外れた後に状態が戻ると、実際にこの形になる
        $meeting = $this->makeMeeting('2026-09-12 10:00:00');
        $meeting->coach->notify(new MeetingReminderNotification($meeting, MeetingReminderWindow::Eve));

        // Act
        $sent = $this->dispatch();

        // Assert: 受講生にだけ送る。
        //         面談単位で「1 件でもあるか」を見る実装だと、ここが 0 になり受講生に永久に届かない
        $this->assertSame(1, $sent);
        $this->assertSame(1, $meeting->student->notifications()->count());
        $this->assertSame(1, $meeting->coach->notifications()->count());
        $this->assertSame(2, DB::table('notifications')->count());
    }
}
