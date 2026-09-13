<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Enrollment;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 受講解除（DELETE /enrollments/{enrollment}）で配下の個人目標が消えることの検証。
 *
 * 面談2 Q44 の PM 回答「目標の削除(物理削除、履歴は残さない)で良いです」がこの挙動
 * （decisions #135。面談前の暫定判断 #130「消さない」を覆した）。
 *
 * ⚠️ 受講登録そのものは論理削除（SoftDeletes）なので、外部キーの cascade は発火しない。
 *    Enrollment\DestroyAction が明示的に消している。外部キーに任せていると思い込むと、
 *    「消えるはず」のものが残る。
 */
class DestroyGoalCascadeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unenrolling_physically_deletes_its_goals(): void
    {
        // Arrange: 未達成と達成済みを混ぜて置く（状態に関係なく消えることを見る）
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        EnrollmentGoal::factory()->forEnrollment($enrollment)->count(2)->create();
        EnrollmentGoal::factory()->forEnrollment($enrollment)->achieved()->create();
        $this->assertDatabaseCount('enrollment_goals', 3);

        // Act
        $response = $this->actingAs($student)->delete(route('enrollments.destroy', $enrollment));

        // Assert: 受講登録は論理削除で残り、目標は行ごと消える
        $response->assertSessionHas('success');
        $this->assertSoftDeleted('enrollments', ['id' => $enrollment->id]);
        $this->assertDatabaseCount('enrollment_goals', 0);
    }

    /** 他の受講登録の目標は巻き込まない */
    public function test_goals_of_other_enrollments_are_untouched(): void
    {
        // Arrange: 同じ受講生が2つの資格に登録し、両方に目標を置く
        $student = User::factory()->student()->create();
        $target = Enrollment::factory()->for($student)->learning()->create();
        $survivor = Enrollment::factory()->for($student)->learning()->create();
        EnrollmentGoal::factory()->forEnrollment($target)->create(['title' => '消える目標']);
        EnrollmentGoal::factory()->forEnrollment($survivor)->create(['title' => '残る目標']);

        // Act: 片方だけ解除する
        $this->actingAs($student)->delete(route('enrollments.destroy', $target));

        // Assert
        $this->assertDatabaseMissing('enrollment_goals', ['title' => '消える目標']);
        $this->assertDatabaseHas('enrollment_goals', ['title' => '残る目標']);
    }

    /** 他の受講生の目標も巻き込まない */
    public function test_goals_of_other_students_are_untouched(): void
    {
        // Arrange
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->learning()->create();
        EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        $otherStudent = User::factory()->student()->create();
        $otherEnrollment = Enrollment::factory()->for($otherStudent)->learning()->create();
        EnrollmentGoal::factory()->forEnrollment($otherEnrollment)->create(['title' => '他人の目標']);

        // Act
        $this->actingAs($student)->delete(route('enrollments.destroy', $enrollment));

        // Assert
        $this->assertDatabaseHas('enrollment_goals', ['title' => '他人の目標']);
    }

    /**
     * 受講解除が拒否されたときは目標も消えない。
     *
     * 受講解除は learning 状態のときしか行えない（passed / failed は履歴として残す）。
     * 拒否されたのに目標だけ消えると、取り返しのつかない差分が生まれる。
     * DestroyAction は状態チェックをトランザクションの外で行い、先に例外を投げている。
     */
    public function test_goals_survive_when_unenrolling_is_rejected(): void
    {
        // Arrange: 合格済み（解除できない状態）の受講登録
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->passed()->create();
        EnrollmentGoal::factory()->forEnrollment($enrollment)->create(['title' => '残るべき目標']);

        // Act
        $this->actingAs($student)->delete(route('enrollments.destroy', $enrollment));

        // Assert: 受講登録も目標もそのまま
        $this->assertNotSoftDeleted('enrollments', ['id' => $enrollment->id]);
        $this->assertDatabaseHas('enrollment_goals', ['title' => '残るべき目標']);
    }
}
