<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use App\Enums\UserStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 個人学習目標の削除（DELETE /enrollment-goals/{goal}）の検証。
 *
 * 原典が「物理削除、履歴は残さない」と明記し、論理削除 + 復元 UI をスコープ外に置いている。
 * 行が本当に消えることと、消せるのは本人だけであることを固定する。
 */
class DestroyTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_owner_student_can_delete_goal(): void
    {
        // Arrange
        $goal = $this->ownedGoal();
        $enrollmentId = $goal->enrollment_id;

        // Act
        $response = $this->actingAs($goal->enrollment->user)->delete(route('enrollment-goals.destroy', $goal));

        // Assert: 消えた本人の画面には戻れないので、親の受講登録詳細へ
        $response->assertRedirect(route('enrollments.show', $enrollmentId));
        $response->assertSessionHas('success');
        // 論理削除ではなく物理削除。行そのものが消えていることを確認する
        $this->assertDatabaseMissing('enrollment_goals', ['id' => $goal->id]);
        $this->assertDatabaseCount('enrollment_goals', 0);
    }

    /** 達成済みの目標も削除できる（支給 Blade が削除ボタンを達成状態の外に置いている） */
    public function test_achieved_goal_can_be_deleted(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create();

        // Act
        $this->actingAs($student)->delete(route('enrollment-goals.destroy', $goal));

        // Assert
        $this->assertDatabaseMissing('enrollment_goals', ['id' => $goal->id]);
    }

    /** 他人の目標は削除できない */
    public function test_other_student_cannot_delete_goal(): void
    {
        // Arrange
        $goal = $this->ownedGoal();
        $otherStudent = User::factory()->student()->create();

        // Act
        $response = $this->actingAs($otherStudent)->delete(route('enrollment-goals.destroy', $goal));

        // Assert: 消えていないことまで確認する（403 が返るだけでは不十分）
        $response->assertForbidden();
        $this->assertDatabaseHas('enrollment_goals', ['id' => $goal->id]);
    }

    /** 担当コーチと管理者は削除できない（原典「介入はしない」） */
    public function test_coach_and_admin_cannot_delete_goal(): void
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
                ->delete(route('enrollment-goals.destroy', $goal))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('enrollment_goals', ['id' => $goal->id]);
    }

    /**
     * 親の受講登録が論理削除されている目標は削除できない。
     *
     * ⚠️ 受講解除では配下の目標を物理削除する（decisions #135 / 面談2 Q44）ので、
     *    実運用でこの状態（親だけ解除され目標が残っている）にはならない。
     *    ここで固めているのは二重の守り——モデルを直接 delete した場合や、
     *    将来 Action を経由しない解除経路が増えた場合に、書き込みが漏れないこと。
     */
    public function test_cannot_delete_goal_of_soft_deleted_enrollment(): void
    {
        // Arrange
        $goal = $this->ownedGoal();
        $student = $goal->enrollment->user;
        $goal->enrollment->delete();

        // Act
        $response = $this->actingAs($student)->delete(route('enrollment-goals.destroy', $goal));

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseHas('enrollment_goals', ['id' => $goal->id]);
    }

    /** 修了した受講生は削除できない（active-learning ミドルウェア） */
    public function test_graduated_student_cannot_delete_goal(): void
    {
        // Arrange
        $goal = $this->ownedGoal();
        $student = $goal->enrollment->user;
        $student->forceFill(['status' => UserStatus::Graduated])->save();

        // Act
        $response = $this->actingAs($student->fresh())->delete(route('enrollment-goals.destroy', $goal));

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseHas('enrollment_goals', ['id' => $goal->id]);
    }
}
