<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Dashboard;

use App\Enums\EnrollmentStatus;
use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\LearningSession;
use App\Models\Meeting;
use App\Models\User;
use App\Services\ChatUnreadCountService;
use App\UseCases\Dashboard\FetchCoachDashboardAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class FetchCoachDashboardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_enrollments_come_from_certification_coaches_pivot(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $myCert = Certification::factory()->published()->create();
        $otherCert = Certification::factory()->published()->create();
        $this->attachCoach($myCert, $coach);

        $mine = Enrollment::factory()->for($myCert)->learning()->create();
        Enrollment::factory()->for($otherCert)->learning()->create();

        $vm = app(FetchCoachDashboardAction::class)($coach);

        $this->assertCount(1, $vm->assignedEnrollments);
        $this->assertSame($mine->id, $vm->assignedEnrollments->first()->id);
    }

    public function test_assigned_enrollments_carry_last_activity_at_via_with_max(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        $this->attachCoach($cert, $coach);
        $enrollment = Enrollment::factory()->for($cert)->learning()->create();

        LearningSession::factory()
            ->forEnrollment($enrollment)
            ->forUser($enrollment->user)
            ->closed()
            ->startedOn(now()->subDay())
            ->create();

        $vm = app(FetchCoachDashboardAction::class)($coach);

        $first = $vm->assignedEnrollments->first();
        $this->assertNotNull($first->last_activity_at);
    }

    /**
     * T-B-01 の回帰防止テスト。
     *
     * 直前の `test_assigned_enrollments_carry_last_activity_at_via_with_max` は
     * 学習セッションを 1 件しか作らないため、「最大値を取っているか」を検証できない。
     * 集計関数を withMin / withAvg に書き間違えても、値が 1 つしか無いので同じ結果になり緑のまま通る。
     * セッションを 3 件・別々の日時で作り、**最も新しい 1 件**が採られることを固定する。
     */
    public function test_last_activity_at_is_the_newest_of_multiple_sessions(): void
    {
        // Arrange: コーチ + 担当資格 + 受講生 1 名
        $coach = User::factory()->coach()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        $this->attachCoach($cert, $coach);
        $enrollment = Enrollment::factory()->for($cert)->learning()->create();

        // Arrange: 学習セッション 3 件。最大が先頭にも末尾にも来ないよう、あえて中央に置く
        // (先頭・末尾だと「最初の 1 件」「最後の 1 件」を返す実装でも偶然通ってしまう)
        $newest = now()->subDay()->startOfSecond();
        foreach ([now()->subDays(3), $newest, now()->subDays(5)] as $startedAt) {
            LearningSession::factory()
                ->forEnrollment($enrollment)
                ->forUser($enrollment->user)
                ->closed()
                ->startedOn($startedAt)
                ->create();
        }

        // Act
        $vm = app(FetchCoachDashboardAction::class)($coach);

        // Assert: 3 件のうち最も新しい「昨日」が入る。
        // last_activity_at は集計値なので $casts が効かず文字列で返る。
        // 表示側の $lastActivityAt も文字列を Carbon::parse する前提なので、ここでも Carbon に通してから比較する。
        $actual = Carbon::parse((string) $vm->assignedEnrollments->first()->last_activity_at);
        $this->assertSame($newest->toDateTimeString(), $actual->toDateTimeString());
    }

    public function test_only_passed_and_learning_enrollments_are_displayed(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        $this->attachCoach($cert, $coach);
        Enrollment::factory()->for($cert)->learning()->create();
        Enrollment::factory()->for($cert)->passed()->create(['passed_at' => now()]);
        Enrollment::factory()->for($cert)->failed()->create();

        $vm = app(FetchCoachDashboardAction::class)($coach);

        $statuses = $vm->assignedEnrollments->map(fn (Enrollment $e) => $e->status)->all();
        $this->assertNotContains(EnrollmentStatus::Failed, $statuses);
    }

    public function test_today_and_tomorrow_meetings_are_scoped_to_coach(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $cert = Certification::factory()->published()->create();
        $this->attachCoach($cert, $coach);
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()->for($cert)->for($student)->learning()->create();

        Meeting::factory()->state([
            'coach_id' => $coach->id,
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'status' => MeetingStatus::Reserved,
            'scheduled_at' => now()->addHours(2),
        ])->create();
        Meeting::factory()->state([
            'coach_id' => $coach->id,
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'status' => MeetingStatus::Reserved,
            'scheduled_at' => now()->addDays(2),
        ])->create();

        $vm = app(FetchCoachDashboardAction::class)($coach);

        $this->assertCount(1, $vm->todayAndTomorrowMeetings);
    }

    public function test_unread_chat_count_uses_service(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();

        $mock = Mockery::mock(ChatUnreadCountService::class);
        $mock->shouldReceive('roomCountForUser')->with(Mockery::on(fn (User $u) => $u->id === $coach->id))->andReturn(7);
        $this->app->instance(ChatUnreadCountService::class, $mock);

        $vm = app(FetchCoachDashboardAction::class)($coach);

        $this->assertSame(7, $vm->unreadChatCount);
    }

    public function test_view_model_does_not_carry_v3_dropped_properties(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();

        $vm = app(FetchCoachDashboardAction::class)($coach);

        $this->assertFalse(property_exists($vm, 'aggregatedWeakCategories'));
        $this->assertFalse(property_exists($vm, 'recentEnrollmentNotes'));
        $this->assertFalse(property_exists($vm, 'stagnationList'));
    }

    public function test_safe_returns_null_when_unread_chat_service_throws(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();

        $mock = Mockery::mock(ChatUnreadCountService::class);
        $mock->shouldReceive('roomCountForUser')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(ChatUnreadCountService::class, $mock);

        $vm = app(FetchCoachDashboardAction::class)($coach);

        $this->assertNull($vm->unreadChatCount);
    }

    private function attachCoach(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
    }
}
