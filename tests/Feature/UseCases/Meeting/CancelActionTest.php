<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use App\UseCases\Meeting\CancelAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * 当事者による面談キャンセル CancelAction の検証(T-A-02)。
 *
 * 認可(当事者か)は MeetingPolicy::cancel() の担当。この Action が保証するのは ——
 *  ① reserved かつ開始前という状態ガード
 *  ② canceled 化と面談回数 1 回分の返却が**必ずセットで起きる**(decisions #55: 返却先は操作者ではなく
 *     面談の受講生。二重返却と「status だけ canceled で未返却」の両方を既存ガードで防ぐ)
 *  ③ 通知はキャンセルした本人ではなく**相手方**に届く(decisions #77)
 */
class CancelActionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * キャンセル可能な面談(未来 / reserved)を、当事者ごと作る。
     *
     * @return array{coach: User, student: User, meeting: Meeting}
     */
    private function cancelableMeeting(): array
    {
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create(['max_meetings' => 3]);
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addWeek()->startOfHour(),
        ]);

        return ['coach' => $coach, 'student' => $student, 'meeting' => $meeting];
    }

    public function test_marks_the_meeting_canceled_with_actor_and_timestamp(): void
    {
        // --- Arrange ---
        ['student' => $student, 'meeting' => $meeting] = $this->cancelableMeeting();

        // --- Act: 受講生本人がキャンセルする ---
        app(CancelAction::class)($meeting, $student);

        // --- Assert: 誰がいつキャンセルしたかまで記録されること ---
        $meeting->refresh();
        $this->assertSame(MeetingStatus::Canceled, $meeting->status);
        $this->assertSame($student->id, $meeting->canceled_by_user_id);
        $this->assertNotNull($meeting->canceled_at);
    }

    public function test_refunds_one_meeting_quota_to_the_student(): void
    {
        // --- Arrange ---
        // 返却先は「キャンセル操作者」ではなく「面談の受講生」。それを見分けるため
        // **コーチがキャンセルする**ケースで検証する
        ['coach' => $coach, 'student' => $student, 'meeting' => $meeting] = $this->cancelableMeeting();

        // --- Act ---
        app(CancelAction::class)($meeting, $coach);

        // --- Assert: refunded(+1) が受講生に 1 行だけ積まれる ---
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $student->id,
            'type' => MeetingQuotaTransactionType::Refunded->value,
            'amount' => 1,
            'related_meeting_id' => $meeting->id,
        ]);
        // コーチ側には 1 行も積まれない(返却先を取り違えていないか)
        $this->assertDatabaseMissing('meeting_quota_transactions', ['user_id' => $coach->id]);
    }

    public function test_notifies_the_counterpart_not_the_actor(): void
    {
        // --- Arrange ---
        // Notification::fake() は通知の送信を差し替えて「誰に何が送られたか」だけを記録する。
        // 本物のメール送信をせずに宛先を検証できる
        Notification::fake();
        ['coach' => $coach, 'student' => $student, 'meeting' => $meeting] = $this->cancelableMeeting();

        // --- Act: 受講生がキャンセル ---
        app(CancelAction::class)($meeting, $student);

        // --- Assert: 相手方(コーチ)にだけ届く。操作した本人には送らない(decisions #77) ---
        Notification::assertSentTo($coach, MeetingCanceledNotification::class);
        Notification::assertNotSentTo($student, MeetingCanceledNotification::class);
    }

    public function test_notifies_the_student_when_the_coach_cancels(): void
    {
        // --- Arrange: 逆向きも成り立つこと(宛先が固定で書かれていないか) ---
        Notification::fake();
        ['coach' => $coach, 'student' => $student, 'meeting' => $meeting] = $this->cancelableMeeting();

        // --- Act: コーチがキャンセル ---
        app(CancelAction::class)($meeting, $coach);

        // --- Assert ---
        Notification::assertSentTo($student, MeetingCanceledNotification::class);
        Notification::assertNotSentTo($coach, MeetingCanceledNotification::class);
    }

    public function test_rejects_a_meeting_that_is_not_reserved(): void
    {
        // --- Arrange: 既にキャンセル済み。二重キャンセル = 二重返却を防ぐガード ---
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $meeting = Meeting::factory()->canceled()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addWeek()->startOfHour(),
        ]);

        // --- Assert ---
        $this->expectException(MeetingStatusTransitionException::class);

        // --- Act ---
        app(CancelAction::class)($meeting, $student);
    }

    public function test_rejects_a_meeting_that_has_already_started(): void
    {
        // --- Arrange ---
        // 開始時刻を 1 分過ぎた reserved の面談。status は正しいが時刻で弾かれる経路を通す
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->subMinute(),
        ]);

        // --- Assert ---
        $this->expectException(MeetingAlreadyStartedException::class);

        // --- Act ---
        app(CancelAction::class)($meeting, $student);
    }

    public function test_does_not_refund_when_the_guard_rejects(): void
    {
        // --- Arrange ---
        // ガードで弾かれたときに返却だけ走ってしまうと、キャンセルせずに回数を増やせてしまう。
        // 「status と返却がセット」であることの裏返しを検証する
        $coach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $meeting = Meeting::factory()->canceled()->forCoach($coach)->forStudent($student)->create([
            'scheduled_at' => now()->addWeek()->startOfHour(),
        ]);

        // --- Act ---
        try {
            app(CancelAction::class)($meeting, $student);
        } catch (MeetingStatusTransitionException) {
            // 例外が出ること自体は別のテストで検証済み
        }

        // --- Assert ---
        $this->assertDatabaseMissing('meeting_quota_transactions', ['related_meeting_id' => $meeting->id]);
    }
}
