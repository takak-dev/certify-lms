<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReservedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 面談の予約・キャンセルで通知が飛ぶことの検証。
 *
 * 宛先は支給 Blade が画面上で約束している内容に従う（decisions #77）。
 * - 予約:     「予約完了後、コーチに通知メールが届きます」（meeting/create.blade.php:158）
 * - キャンセル:「相手方に通知メールが届きます」（meeting/_modals/cancel-confirm.blade.php:21）
 *
 * どちらも「操作した本人には送らない」ことをここで固定する。
 */
class MeetingDispatchTest extends TestCase
{
    use RefreshDatabase;

    /** 担当コーチの割当（中間テーブルは ULID 主キーと割当メタ情報を持つ） */
    private function attachCoach(Certification $certification, User $coach, User $admin): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
    }

    public function test_reserving_notifies_the_coach_only(): void
    {
        // Arrange: 予約が成立する最小構成（担当コーチ + 空き枠 + 面談回数 + 受講登録）
        Notification::fake();
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->inProgress()->create([
            'meeting_url' => 'https://meet.example.com/coach-room',
        ]);
        $certification = Certification::factory()->published()->create();
        $this->attachCoach($certification, $coach, $admin);
        CoachAvailability::factory()->forCoach($coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        // 空き枠に合わせて次の月曜 10:00 を選ぶ
        $scheduledAt = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);

        // Act
        $response = $this->actingAs($student)->post(route('meetings.store', $enrollment), [
            'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
            'topic' => 'アルゴリズムの進め方を相談したい',
        ]);

        // Assert: 予約した本人には送らない
        $response->assertRedirect();
        Notification::assertSentTo($coach, MeetingReservedNotification::class);
        Notification::assertNotSentTo($student, MeetingReservedNotification::class);
    }

    public function test_cancel_by_student_notifies_the_coach(): void
    {
        // Arrange
        Notification::fake();
        [$meeting, $student, $coach] = $this->makeReservedMeeting();

        // Act: 受講生がキャンセルする
        $response = $this->actingAs($student)->post(route('meetings.cancel', $meeting));

        // Assert: 相手方（コーチ）にだけ届く
        $response->assertRedirect(route('meetings.show', $meeting));
        Notification::assertSentTo($coach, MeetingCanceledNotification::class);
        Notification::assertNotSentTo($student, MeetingCanceledNotification::class);
    }

    public function test_cancel_by_coach_notifies_the_student(): void
    {
        // Arrange: 向きを入れ替えても「相手方へ」が成り立つことを見る
        Notification::fake();
        [$meeting, $student, $coach] = $this->makeReservedMeeting();

        // Act: コーチがキャンセルする
        $response = $this->actingAs($coach)->post(route('meetings.cancel', $meeting));

        // Assert
        $response->assertRedirect(route('meetings.show', $meeting));
        Notification::assertSentTo($student, MeetingCanceledNotification::class);
        Notification::assertNotSentTo($coach, MeetingCanceledNotification::class);
    }

    /**
     * キャンセル可能な予約済み面談を 1 件作る。
     *
     * @return array{0: Meeting, 1: User, 2: User} 面談 / 受講生 / コーチ
     */
    private function makeReservedMeeting(): array
    {
        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();
        $meeting = Meeting::factory()->reserved()->forCoach($coach)->forStudent($student)->create([
            // 開始前でないとキャンセルできない
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);

        return [$meeting, $student, $coach];
    }
}
