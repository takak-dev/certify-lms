<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 達成マーク（POST /enrollment-goals/{goal}/achieve）と
 * 達成解除（DELETE 同 URL）の検証。
 *
 * ⚠️ この2つは URL がまったく同じで、HTTP メソッドだけが違う。
 *    「達成という状態を作る / 消す」と読む設計で、原典の HTTP 表どおり。
 *    支給 Blade は両方 method="POST" で送り、解除側だけ @method('DELETE') を付けている。
 *
 * 達成日時の照合のため Carbon::setTestNow() で時計を止める
 * （手本: tests/Feature/UseCases/Certificate/IssueActionTest.php:33）。
 */
class AchieveTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // 止めた時計を戻す。忘れると後続のテストまで同じ時刻を見てしまう
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ownedGoal(?User $student = null, ?Certification $certification = null): EnrollmentGoal
    {
        $student ??= User::factory()->student()->create();
        $certification ??= Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();

        return EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
    }

    private function assignCoach(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
        ]);
    }

    public function test_owner_student_can_mark_goal_as_achieved(): void
    {
        // Arrange: 時計を止めてから未達成の目標を用意する
        Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00'));
        $goal = $this->ownedGoal();

        // Act
        $response = $this->actingAs($goal->enrollment->user)
            ->post(route('enrollment-goals.markAchieved', $goal));

        // Assert: 止めた時計の時刻がそのまま入る
        $response->assertRedirect(route('enrollments.show', $goal->enrollment_id));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'achieved_at' => '2026-09-14 10:00:00',
        ]);
    }

    /**
     * 既に達成済みの目標へ達成マークを送っても、達成日時は動かない（冪等）。
     *
     * 二重送信や戻るボタンでの再送で、3 日前に達成した記録が今日に書き換わるのを防ぐ。
     * 手本の QaThread\ResolveAction が同じ理由で同じ形をしている。
     */
    public function test_marking_already_achieved_goal_does_not_move_the_timestamp(): void
    {
        // Arrange: 3 日前に達成した目標
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();
        $goal->forceFill(['achieved_at' => Carbon::parse('2026-09-11 09:00:00')])->save();

        // 時計を今日に進める
        Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00'));

        // Act: もう一度達成マークを送る
        $this->actingAs($student)->post(route('enrollment-goals.markAchieved', $goal));

        // Assert: 3 日前のまま
        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'achieved_at' => '2026-09-11 09:00:00',
        ]);
    }

    public function test_owner_student_can_unmark_achieved_goal(): void
    {
        // Arrange: 達成済みの目標
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create();

        // Act: 同じ URL に DELETE を送る
        $response = $this->actingAs($student)->delete(route('enrollment-goals.unmarkAchieved', $goal));

        // Assert: 未達成に戻り、達成日時は消える
        $response->assertRedirect(route('enrollments.show', $enrollment->id));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('enrollment_goals', [
            'id' => $goal->id,
            'achieved_at' => null,
        ]);
    }

    /** 既に未達成の目標へ解除を送っても何も起きない（冪等） */
    public function test_unmarking_already_unachieved_goal_is_a_no_op(): void
    {
        // Arrange: 未達成の目標
        $goal = $this->ownedGoal();

        // Act
        $response = $this->actingAs($goal->enrollment->user)
            ->delete(route('enrollment-goals.unmarkAchieved', $goal));

        // Assert: エラーにもならず、未達成のまま
        $response->assertSessionHas('success');
        $this->assertNull($goal->refresh()->achieved_at);
    }

    /**
     * 同じ URL が HTTP メソッドで別の動作になることを、1 本のテストで通しで確認する。
     *
     * ここが崩れると「達成ボタンを押したら解除された」という取り違えが起きる。
     */
    public function test_same_url_behaves_differently_by_http_method(): void
    {
        // Arrange
        Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00'));
        $goal = $this->ownedGoal();
        $student = $goal->enrollment->user;
        $url = route('enrollment-goals.markAchieved', $goal);

        // markAchieved と unmarkAchieved は同じ URL を指している
        $this->assertSame($url, route('enrollment-goals.unmarkAchieved', $goal));

        // Act & Assert: POST で達成になる
        $this->actingAs($student)->post($url);
        $this->assertNotNull($goal->refresh()->achieved_at);

        // Act & Assert: DELETE で未達成に戻る
        $this->actingAs($student)->delete($url);
        $this->assertNull($goal->refresh()->achieved_at);
    }

    /** 他人の目標の達成状態は変えられない */
    public function test_other_student_cannot_change_achievement(): void
    {
        // Arrange
        $goal = $this->ownedGoal();
        $otherStudent = User::factory()->student()->create();

        // Act & Assert
        $this->actingAs($otherStudent)
            ->post(route('enrollment-goals.markAchieved', $goal))
            ->assertForbidden();
        $this->assertNull($goal->refresh()->achieved_at);
    }

    /** 担当コーチと管理者は達成マークを操作できない（原典「介入はしない」） */
    public function test_coach_and_admin_cannot_change_achievement(): void
    {
        // Arrange
        $certification = Certification::factory()->published()->create();
        $goal = $this->ownedGoal(null, $certification);
        $coach = User::factory()->coach()->create();
        $this->assignCoach($certification, $coach);
        $admin = User::factory()->admin()->create();

        // Act & Assert
        foreach ([$coach, $admin] as $staff) {
            $this->actingAs($staff)
                ->post(route('enrollment-goals.markAchieved', $goal))
                ->assertForbidden();
            $this->actingAs($staff)
                ->delete(route('enrollment-goals.unmarkAchieved', $goal))
                ->assertForbidden();
        }

        $this->assertNull($goal->refresh()->achieved_at);
    }

    /**
     * 親の受講登録が論理削除されている目標は達成状態を変えられない。
     *
     * ⚠️ 受講解除では配下の目標を物理削除する（decisions #135 / 面談2 Q44）ので、
     *    実運用でこの状態（親だけ解除され目標が残っている）にはならない。
     *    ここで固めているのは二重の守り——モデルを直接 delete した場合や、
     *    将来 Action を経由しない解除経路が増えた場合に、書き込みが漏れないこと。
     */
    public function test_cannot_change_achievement_of_soft_deleted_enrollment(): void
    {
        // Arrange
        $goal = $this->ownedGoal();
        $student = $goal->enrollment->user;
        $goal->enrollment->delete();

        // Act & Assert
        $this->actingAs($student)
            ->post(route('enrollment-goals.markAchieved', $goal))
            ->assertForbidden();
        $this->assertNull($goal->refresh()->achieved_at);
    }

    /** 修了した受講生は達成状態を変えられない（active-learning ミドルウェア） */
    public function test_graduated_student_cannot_change_achievement(): void
    {
        // Arrange
        $goal = $this->ownedGoal();
        $student = $goal->enrollment->user;
        $student->forceFill(['status' => UserStatus::Graduated])->save();

        // Act & Assert
        $this->actingAs($student->fresh())
            ->post(route('enrollment-goals.markAchieved', $goal))
            ->assertForbidden();
        $this->assertNull($goal->refresh()->achieved_at);
    }
}
