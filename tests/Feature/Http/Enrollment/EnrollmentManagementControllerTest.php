<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Enrollment;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\UserWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 管理者向け EnrollmentManagementController の HTTP 統合テスト。
 * updateExamDate / fail の admin 専用業務操作を検証する(一覧 / 詳細は EnrollmentControllerTest に統合済)。
 */
class EnrollmentManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_update_exam_date_succeeds_for_learning_enrollment(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->learning()->create(['exam_date' => null]);

        // Act
        $response = $this->actingAs($admin)->patch(route('admin.enrollments.updateExamDate', $enrollment), [
            'exam_date' => now()->addMonths(2)->toDateString(),
        ]);

        // Assert
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertNotNull($enrollment->fresh()->exam_date);
    }

    public function test_admin_update_exam_date_forbidden_for_passed_enrollment(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->passed()->create();

        // Act
        $response = $this->actingAs($admin)->patchJson(route('admin.enrollments.updateExamDate', $enrollment), [
            'exam_date' => now()->addMonth()->toDateString(),
        ]);

        // Assert
        // Policy::updateExamDate が status=Passed を弾く → 403
        $response->assertForbidden();
    }

    public function test_admin_can_fail_learning_enrollment(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->learning()->create();

        // Act
        $response = $this->actingAs($admin)->post(route('admin.enrollments.fail', $enrollment), [
            'reason' => '本人辞退',
        ]);

        // Assert
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertSame(EnrollmentStatus::Failed, $enrollment->fresh()->status);
        $this->assertDatabaseHas('enrollment_status_logs', [
            'enrollment_id' => $enrollment->id,
            'from_status' => EnrollmentStatus::Learning->value,
            'to_status' => EnrollmentStatus::Failed->value,
            'changed_by_user_id' => $admin->id,
            'changed_reason' => '本人辞退',
        ]);
    }

    /**
     * 退会した受講生の受講登録でも、管理者が学習中止にできること(decisions #284)。
     *
     * 退会はユーザーを論理削除するだけで受講登録は受講中のまま残る。学習中止の処理は最後に
     * DefaultEnrollmentService::resolveAfterStatusChange(User $user, ...) へ受講生を渡すので、
     * 受講登録から受講生が引けないと null が渡って TypeError(500)になっていた。
     */
    public function test_admin_can_fail_learning_enrollment_of_withdrawn_student(): void
    {
        // Arrange: 受講中の受講登録を持つ受講生を、本物の退会処理で退会させる。
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->learning()->create();
        app(UserWithdrawalService::class)->withdraw($enrollment->user);

        // Act
        $response = $this->actingAs($admin)->post(route('admin.enrollments.fail', $enrollment), [
            'reason' => '退会済みのため',
        ]);

        // Assert: 500 にならず、受講登録が学習中止になる(正常時と同じく詳細画面へ戻る)。
        $response->assertRedirect(route('enrollments.show', $enrollment));
        $this->assertSame(EnrollmentStatus::Failed, $enrollment->fresh()->status);
    }

    public function test_admin_fail_forbidden_for_passed_enrollment(): void
    {
        // Arrange
        $admin = User::factory()->admin()->create();
        $enrollment = Enrollment::factory()->passed()->create();

        // Act
        $response = $this->actingAs($admin)->postJson(route('admin.enrollments.fail', $enrollment));

        // Assert
        // Policy::fail が status=Learning に絞っているため 403
        $response->assertForbidden();
    }
}
